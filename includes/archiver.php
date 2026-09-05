<?php
/**
 * Database Archiver & Maintenance Engine
 * Enterprise High-Volume Zero-Lock Archival, Log Compaction, and Optimization
 * 
 * Supports high-throughput environments (100K - 10M+ emails/day) by avoiding
 * table locks and preventing transaction log bloat.
 */

if (!defined('MAILPRO_ARCHIVER')) {
    define('MAILPRO_ARCHIVER', true);
}

class DatabaseArchiver
{
    /**
     * Chunked zero-lock archival of historical records.
     * Moves records from $sourceTable to $archiveTable in non-blocking batches.
     *
     * @param string $sourceTable Source table name (e.g. 'system_logs', 'send_logs')
     * @param string $archiveTable Target archive table name (e.g. 'system_logs_archive')
     * @param string $dateCol Date column name (e.g. 'created_at', 'sent_at')
     * @param int $daysRetention Keep records younger than this many days
     * @param int $batchSize Number of rows per chunk (default: 1000)
     * @param PDO|null $pdo Optional PDO instance for testing
     * @return array Results summary
     */
    public static function archiveTable(
        string $sourceTable,
        string $archiveTable,
        string $dateCol,
        int $daysRetention,
        int $batchSize = 1000,
        ?PDO $pdo = null
    ): array {
        $pdo = $pdo ?? (function_exists('db') ? db() : null);
        if (!$pdo) {
            return ['ok' => false, 'error' => 'Database connection unavailable'];
        }

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$daysRetention} days"));
        $totalArchived = 0;
        $batches = 0;

        // Ensure archive table exists before attempting migration
        self::ensureArchiveTableExists($sourceTable, $archiveTable, $pdo);

        while (true) {
            // 1. Fetch primary keys of the next chunk
            $stmt = $pdo->prepare("SELECT id FROM `{$sourceTable}` WHERE `{$dateCol}` < ? ORDER BY id ASC LIMIT {$batchSize}");
            $stmt->execute([$cutoff]);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($ids)) {
                break; // All matching historical records processed
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            // 2. Perform copy & delete in a quick transaction for this batch
            $pdo->beginTransaction();
            try {
                // Copy batch into archive table
                $copySql = "INSERT INTO `{$archiveTable}` SELECT * FROM `{$sourceTable}` WHERE id IN ({$placeholders})";
                $copyStmt = $pdo->prepare($copySql);
                $copyStmt->execute($ids);

                // Delete batch from source table
                $delSql = "DELETE FROM `{$sourceTable}` WHERE id IN ({$placeholders})";
                $delStmt = $pdo->prepare($delSql);
                $delStmt->execute($ids);

                $pdo->commit();
                $count = count($ids);
                $totalArchived += $count;
                $batches++;

                // If less than batch size, we are at the end
                if ($count < $batchSize) {
                    break;
                }

                // Brief 5ms pause between chunks to release locks for active high-throughput workers
                usleep(5000);
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return [
                    'ok'            => false,
                    'error'         => $e->getMessage(),
                    'total_archived'=> $totalArchived,
                    'batches'       => $batches
                ];
            }
        }

        return [
            'ok'            => true,
            'source'        => $sourceTable,
            'archive'       => $archiveTable,
            'cutoff'        => $cutoff,
            'total_archived'=> $totalArchived,
            'batches'       => $batches
        ];
    }

    /**
     * Purges completed and cancelled queue jobs older than $hours.
     */
    public static function cleanQueueJobs(int $hours = 24, ?PDO $pdo = null): int
    {
        $pdo = $pdo ?? (function_exists('db') ? db() : null);
        if (!$pdo) return 0;

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));
        $deleted = 0;

        try {
            $stmt = $pdo->prepare("DELETE FROM queue_jobs WHERE status IN ('completed', 'cancelled') AND available_at < ?");
            $stmt->execute([$cutoff]);
            $deleted = $stmt->rowCount();
        } catch (\Throwable $e) {}

        return $deleted;
    }

    /**
     * Optimizes tables (OPTIMIZE TABLE on MySQL, VACUUM on SQLite).
     */
    public static function optimizeTables(array $tables = [], ?PDO $pdo = null): array
    {
        $pdo = $pdo ?? (function_exists('db') ? db() : null);
        if (!$pdo) return ['ok' => false, 'error' => 'No DB'];

        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $results = [];

        if ($driver === 'sqlite') {
            try {
                $pdo->exec('VACUUM');
                return ['ok' => true, 'driver' => 'sqlite', 'message' => 'SQLite VACUUM completed'];
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        if (empty($tables)) {
            $tables = ['system_logs', 'send_logs', 'queue_jobs', 'failed_jobs', 'email_open_events', 'email_tracking'];
        }

        foreach ($tables as $t) {
            try {
                $pdo->exec("OPTIMIZE TABLE `{$t}`");
                $results[$t] = 'optimized';
            } catch (\Throwable $e) {
                $results[$t] = 'error: ' . $e->getMessage();
            }
        }

        return ['ok' => true, 'driver' => 'mysql', 'results' => $results];
    }

    /**
     * Retrieves table storage statistics (Data size, Index size, Row counts, Fragmentation).
     */
    public static function getStorageStats(?PDO $pdo = null): array
    {
        $pdo = $pdo ?? (function_exists('db') ? db() : null);
        if (!$pdo) return ['ok' => false, 'error' => 'No DB'];

        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $pageSize = (int)$pdo->query('PRAGMA page_size')->fetchColumn();
            $pageCount = (int)$pdo->query('PRAGMA page_count')->fetchColumn();
            $freeCount = (int)$pdo->query('PRAGMA freelist_count')->fetchColumn();
            $totalBytes = $pageSize * $pageCount;
            $freeBytes = $pageSize * $freeCount;

            $tables = ['system_logs', 'send_logs', 'queue_jobs', 'failed_jobs', 'email_open_events', 'blacklist', 'autoreply_threads'];
            $tableStats = [];
            foreach ($tables as $t) {
                try {
                    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
                    $tableStats[] = [
                        'name'       => $t,
                        'rows'       => $cnt,
                        'data_mb'    => round(($cnt * 300) / (1024 * 1024), 2),
                        'index_mb'   => 0.1,
                        'total_mb'   => round(($cnt * 300) / (1024 * 1024), 2),
                        'overhead_mb'=> 0
                    ];
                } catch (\Throwable $e) {}
            }

            return [
                'ok'            => true,
                'driver'        => 'sqlite',
                'total_size_mb' => round($totalBytes / (1024 * 1024), 2),
                'free_space_mb' => round($freeBytes / (1024 * 1024), 2),
                'tables'        => $tableStats
            ];
        }

        // MySQL statistics
        try {
            $stmt = $pdo->query("
                SELECT 
                    table_name AS name,
                    table_rows AS `rows`,
                    ROUND(data_length / 1024 / 1024, 2) AS data_mb,
                    ROUND(index_length / 1024 / 1024, 2) AS index_mb,
                    ROUND((data_length + index_length) / 1024 / 1024, 2) AS total_mb,
                    ROUND(data_free / 1024 / 1024, 2) AS overhead_mb
                FROM information_schema.tables
                WHERE table_schema = DATABASE()
                ORDER BY (data_length + index_length) DESC
            ");
            $tables = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $totalDataMb = 0;
            $totalOverheadMb = 0;
            foreach ($tables as $t) {
                $totalDataMb += (float)$t['total_mb'];
                $totalOverheadMb += (float)$t['overhead_mb'];
            }

            return [
                'ok'            => true,
                'driver'        => 'mysql',
                'total_size_mb' => round($totalDataMb, 2),
                'free_space_mb' => round($totalOverheadMb, 2),
                'tables'        => $tables
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Exports records older than $daysRetention to a compressed .csv.gz file.
     */
    public static function exportCompressedArchive(
        string $table,
        string $dateCol,
        int $daysRetention,
        ?string $outputDir = null,
        ?PDO $pdo = null
    ): array {
        $pdo = $pdo ?? (function_exists('db') ? db() : null);
        if (!$pdo) return ['ok' => false, 'error' => 'No DB'];

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$daysRetention} days"));
        $outputDir = $outputDir ?? (__DIR__ . '/../uploads/archives');
        if (!is_dir($outputDir)) {
            @mkdir($outputDir, 0755, true);
        }

        $filename = "archive_{$table}_" . date('Ymd_His') . ".csv.gz";
        $filepath = rtrim($outputDir, '/') . '/' . $filename;

        $gz = function_exists('gzopen') ? @gzopen($filepath, 'w9') : false;
        if (!$gz) {
            return ['ok' => false, 'error' => 'Failed to initialize gzip writer'];
        }

        $stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE `{$dateCol}` < ? ORDER BY id ASC");
        $stmt->execute([$cutoff]);

        $rowsExported = 0;
        $headerWritten = false;

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!$headerWritten) {
                gzwrite($gz, implode(',', array_keys($row)) . "\n");
                $headerWritten = true;
            }
            $escaped = array_map(function ($val) {
                return '"' . str_replace('"', '""', (string)$val) . '"';
            }, array_values($row));
            gzwrite($gz, implode(',', $escaped) . "\n");
            $rowsExported++;
        }

        gzclose($gz);

        return [
            'ok'       => true,
            'filename' => $filename,
            'filepath' => $filepath,
            'filesize' => file_exists($filepath) ? filesize($filepath) : 0,
            'rows'     => $rowsExported,
            'cutoff'   => $cutoff
        ];
    }

    /**
     * Self-healing helper: clones table structure for archive if not yet present.
     */
    private static function ensureArchiveTableExists(string $source, string $archive, PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'sqlite') {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `{$archive}` AS SELECT * FROM `{$source}` WHERE 1=0");
            } else {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `{$archive}` LIKE `{$source}`");
            }
        } catch (\Throwable $e) {}
    }
}
