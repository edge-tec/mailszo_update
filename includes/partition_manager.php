<?php
/**
 * Time-Series Partition Manager
 * Declarative RANGE Partitioning for High-Volume Event Streaming (100K - 10M+ emails/day)
 * 
 * Provides sub-millisecond data lifecycle operations via ALTER TABLE ... DROP PARTITION
 * instead of expensive, lock-inducing DELETE queries.
 */

if (!defined('MAILPRO_PARTITION_MANAGER')) {
    define('MAILPRO_PARTITION_MANAGER', true);
}

class PartitionManager
{
    /**
     * Checks if current database engine supports native table partitioning.
     */
    public static function isSupported(?PDO $pdo = null): bool
    {
        $pdo = $pdo ?? (function_exists('db') ? db() : null);
        if (!$pdo) return false;

        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver !== 'mysql') {
            return false;
        }

        try {
            $row = $pdo->query("SHOW PLUGINS WHERE Name = 'partition'")->fetch();
            return !empty($row) && strtolower($row['Status'] ?? '') === 'active';
        } catch (\Throwable $e) {
            // MySQL 8+ built-in partitioning doesn't always show as a separate plugin
            $version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            return version_compare($version, '5.6.0', '>=');
        }
    }

    /**
     * Retrieves partition breakdown for a table.
     */
    public static function getPartitionStats(string $table, ?PDO $pdo = null): array
    {
        $pdo = $pdo ?? (function_exists('db') ? db() : null);
        if (!$pdo) return [];

        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver !== 'mysql') {
            return [];
        }

        try {
            $stmt = $pdo->prepare("
                SELECT 
                    partition_name AS name,
                    partition_ordinal_position AS position,
                    table_rows AS `rows`,
                    ROUND(data_length / 1024 / 1024, 2) AS data_mb,
                    ROUND(index_length / 1024 / 1024, 2) AS index_mb,
                    partition_description AS range_description
                FROM information_schema.partitions
                WHERE table_schema = DATABASE()
                  AND table_name = ?
                  AND partition_name IS NOT NULL
                ORDER BY partition_ordinal_position ASC
            ");
            $stmt->execute([$table]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Generates declarative RANGE partitioning SQL statement for a table.
     */
    public static function generatePartitionSql(
        string $table,
        string $dateCol = 'created_at',
        int $monthsBack = 2,
        int $monthsForward = 3
    ): string {
        $partitions = [];
        $now = new DateTime('first day of this month');

        for ($i = -$monthsBack; $i <= $monthsForward; $i++) {
            $dt = (clone $now)->modify("{$i} month");
            $nextMonth = (clone $dt)->modify("+1 month");
            $pName = 'p_' . $dt->format('Y_m');
            $lessThanDate = $nextMonth->format('Y-m-01');
            $partitions[] = "    PARTITION `{$pName}` VALUES LESS THAN (TO_DAYS('{$lessThanDate}'))";
        }
        $partitions[] = "    PARTITION `p_future` VALUES LESS THAN MAXVALUE";

        $partBlock = implode(",\n", $partitions);

        return "ALTER TABLE `{$table}` PARTITION BY RANGE (TO_DAYS(`{$dateCol}`)) (\n{$partBlock}\n);";
    }

    /**
     * Checks if future month partitions exist and automatically appends them.
     */
    public static function ensureFuturePartitions(
        string $table,
        string $dateCol = 'created_at',
        ?PDO $pdo = null
    ): array {
        $pdo = $pdo ?? (function_exists('db') ? db() : null);
        if (!$pdo || !self::isSupported($pdo)) {
            return ['ok' => false, 'error' => 'Partitioning unsupported or connection unavailable'];
        }

        $existing = self::getPartitionStats($table, $pdo);
        if (empty($existing)) {
            return ['ok' => false, 'message' => "Table {$table} is not partitioned"];
        }

        $existingNames = array_column($existing, 'name');
        $nextMonth = new DateTime('first day of next month');
        $afterNext = (clone $nextMonth)->modify('+1 month');

        $pNextName = 'p_' . $nextMonth->format('Y_m');
        $lessThanDate = $afterNext->format('Y-m-01');

        if (in_array($pNextName, $existingNames)) {
            return ['ok' => true, 'message' => "Partition {$pNextName} already exists"];
        }

        // Reorganize p_future to carve out the upcoming month
        if (in_array('p_future', $existingNames)) {
            $sql = "ALTER TABLE `{$table}` REORGANIZE PARTITION `p_future` INTO (
                PARTITION `{$pNextName}` VALUES LESS THAN (TO_DAYS('{$lessThanDate}')),
                PARTITION `p_future` VALUES LESS THAN MAXVALUE
            )";
            try {
                $pdo->exec($sql);
                return ['ok' => true, 'created' => $pNextName, 'table' => $table];
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        return ['ok' => false, 'message' => 'p_future partition not found for reorganization'];
    }

    /**
     * Drops an expired partition instantly with sub-millisecond execution.
     */
    public static function dropPartition(string $table, string $partitionName, ?PDO $pdo = null): array
    {
        $pdo = $pdo ?? (function_exists('db') ? db() : null);
        if (!$pdo || !self::isSupported($pdo)) {
            return ['ok' => false, 'error' => 'Unsupported'];
        }

        // Protect p_future from accidental deletion
        if ($partitionName === 'p_future') {
            return ['ok' => false, 'error' => 'Cannot drop p_future partition'];
        }

        try {
            $pdo->exec("ALTER TABLE `{$table}` DROP PARTITION `{$partitionName}`");
            return ['ok' => true, 'dropped' => $partitionName, 'table' => $table];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
