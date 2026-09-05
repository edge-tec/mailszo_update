<?php
namespace Mailpro\Controllers;

use Mailpro\Kernel\Request;
use Mailpro\Kernel\Response;
use DatabaseArchiver;
use PartitionManager;

require_once __DIR__ . '/../../includes/archiver.php';
require_once __DIR__ . '/../../includes/partition_manager.php';

/**
 * Database Storage, Partitioning & Archiving Modular Controller
 */
class DatabaseController extends BaseController
{
    public function handle(Request $request): Response
    {
        $method = $request->getMethod();
        $id     = $request->getId();
        $isAdmin= $request->isAdmin();

        // 1. STORAGE STATS & TABLE SIZES
        if ($method === 'GET' && ($id === null || $id === 'stats')) {
            $stats = DatabaseArchiver::getStorageStats();
            $partSupported = PartitionManager::isSupported();
            return $this->json([
                'ok'                  => true,
                'partition_supported' => $partSupported,
                'storage'             => $stats
            ]);
        }

        // 2. OPTIMIZE / DEFRAGMENT TABLES
        if ($method === 'POST' && $id === 'optimize') {
            if (!$isAdmin) return $this->forbidden();
            $res = DatabaseArchiver::optimizeTables();
            return $this->json($res);
        }

        // 3. RUN CHUNKED ZERO-LOCK ARCHIVAL
        if ($method === 'POST' && $id === 'archive') {
            if (!$isAdmin) return $this->forbidden();
            $days = max(7, (int)$request->input('days', 30));
            $batch = min(5000, max(100, (int)$request->input('batch', 1000)));

            $res1 = DatabaseArchiver::archiveTable('system_logs', 'system_logs_archive', 'created_at', $days, $batch);
            $res2 = DatabaseArchiver::archiveTable('send_logs', 'send_logs_archive', 'sent_at', $days, $batch);
            $qCleaned = DatabaseArchiver::cleanQueueJobs(24);

            return $this->json([
                'ok'            => true,
                'days'          => $days,
                'system_logs'   => $res1,
                'send_logs'     => $res2,
                'queue_cleaned' => $qCleaned
            ]);
        }

        // 4. PARTITION STATS
        if ($method === 'GET' && $id === 'partitions') {
            $table = trim((string)$request->getQuery('table', 'system_logs')) ?: 'system_logs';
            $parts = PartitionManager::getPartitionStats($table);
            return $this->json([
                'ok'         => true,
                'table'      => $table,
                'supported'  => PartitionManager::isSupported(),
                'partitions' => $parts
            ]);
        }

        // 5. DROP EXPIRED PARTITION
        if ($method === 'POST' && $id === 'drop-partition') {
            if (!$isAdmin) return $this->forbidden();
            $table = trim((string)$request->input('table', 'system_logs'));
            $partition = trim((string)$request->input('partition', ''));
            if (!$partition) return $this->error('Partition name required');

            $res = PartitionManager::dropPartition($table, $partition);
            return $this->json($res);
        }

        return $this->notFound();
    }
}
