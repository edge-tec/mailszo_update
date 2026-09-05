<?php
/**
 * CLI Database Maintenance & Archival Daemon
 * 
 * Usage:
 *   php maintenance.php --full
 *   php maintenance.php --archive [--days=30] [--batch=1000]
 *   php maintenance.php --optimize
 *   php maintenance.php --clean-queue [--hours=24]
 *   php maintenance.php --prune-partitions
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/archiver.php';
require_once __DIR__ . '/includes/partition_manager.php';

$shortopts = "";
$longopts  = [
    "full",
    "archive",
    "days::",
    "batch::",
    "optimize",
    "clean-queue",
    "hours::",
    "prune-partitions",
    "help"
];
$options = getopt($shortopts, $longopts);

if (isset($options['help']) || empty($options)) {
    echo "=========================================================\n";
    echo "  MAILPRO ENTERPRISE DATABASE MAINTENANCE RUNNER        \n";
    echo "=========================================================\n";
    echo "Usage:\n";
    echo "  php maintenance.php --full                 Run full daily maintenance suite\n";
    echo "  php maintenance.php --archive [--days=30]  Archive logs older than N days\n";
    echo "  php maintenance.php --optimize             Optimize & defragment tables\n";
    echo "  php maintenance.php --clean-queue          Flush completed queue jobs (>24h)\n";
    echo "  php maintenance.php --prune-partitions     Check time-series partition health\n";
    exit(0);
}

echo "[" . date('Y-m-d H:i:s') . "] Starting Database Maintenance...\n";

$days  = isset($options['days']) ? (int)$options['days'] : 30;
$batch = isset($options['batch']) ? (int)$options['batch'] : 1000;
$hours = isset($options['hours']) ? (int)$options['hours'] : 24;

$runAll = isset($options['full']);

// 1. Clean Queue Jobs
if ($runAll || isset($options['clean-queue'])) {
    echo "  -> Flushing completed/cancelled queue jobs older than {$hours}h... ";
    $deleted = DatabaseArchiver::cleanQueueJobs($hours);
    echo "Done. ({$deleted} jobs purged)\n";
}

// 2. Chunked Zero-Lock Archival
if ($runAll || isset($options['archive'])) {
    echo "  -> Archiving 'system_logs' older than {$days} days (batch: {$batch})... ";
    $res1 = DatabaseArchiver::archiveTable('system_logs', 'system_logs_archive', 'created_at', $days, $batch);
    echo "Done. (" . ($res1['total_archived'] ?? 0) . " rows archived in " . ($res1['batches'] ?? 0) . " batches)\n";

    echo "  -> Archiving 'send_logs' older than {$days} days (batch: {$batch})... ";
    $res2 = DatabaseArchiver::archiveTable('send_logs', 'send_logs_archive', 'sent_at', $days, $batch);
    echo "Done. (" . ($res2['total_archived'] ?? 0) . " rows archived in " . ($res2['batches'] ?? 0) . " batches)\n";
}

// 3. Partition Upkeep
if ($runAll || isset($options['prune-partitions'])) {
    if (PartitionManager::isSupported()) {
        echo "  -> Checking MySQL future month partitions... ";
        $pRes = PartitionManager::ensureFuturePartitions('system_logs');
        echo ($pRes['ok'] ? "OK ({$pRes['message']})" : "Skipped ({$pRes['message']})") . "\n";
    }
}

// 4. Table Optimization / Defragmentation
if ($runAll || isset($options['optimize'])) {
    echo "  -> Defragmenting and optimizing tables... ";
    $optRes = DatabaseArchiver::optimizeTables();
    echo "Done. (" . ($optRes['driver'] ?? 'unknown') . ")\n";
}

echo "[" . date('Y-m-d H:i:s') . "] Database Maintenance Completed Successfully.\n";
exit(0);
