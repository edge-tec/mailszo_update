<?php
namespace Mailpro\Controllers;

use Mailpro\Kernel\Request;
use Mailpro\Kernel\Response;
use QueueManager;

require_once __DIR__ . '/../../includes/queue.php';

/**
 * Asynchronous Multi-Process Queue & Dead-Letter Queue Modular Controller
 */
class QueueController extends BaseController
{
    public function handle(Request $request): Response
    {
        $method = $request->getMethod();
        $id     = $request->getId();
        $action = $request->getAction();

        // 1. QUEUE TELEMETRY & STATS
        if ($method === 'GET' && ($id === null || $id === 'stats')) {
            return $this->json(['ok' => true, 'stats' => QueueManager::getStats()]);
        }

        // 2. FAILED JOBS (DEAD-LETTER QUEUE)
        if ($method === 'GET' && $id === 'failed') {
            $limit = min(100, max(1, (int)$request->getQuery('limit', 50)));
            $stmt = $this->db()->prepare("SELECT id, job_id, queue, payload, exception, failed_at FROM failed_jobs ORDER BY id DESC LIMIT ?");
            $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
            $stmt->execute();
            $jobs = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($jobs as &$j) {
                $j['payload_data'] = json_decode($j['payload'], true) ?: [];
            }
            return $this->json(['ok' => true, 'failed_jobs' => $jobs]);
        }

        // 3. RETRY SINGLE FAILED JOB
        if ($method === 'POST' && $id === 'retry') {
            $failedJobId = (int)($request->input('id') ?? $request->getQuery('id', 0));
            if ($failedJobId <= 0) return $this->error('Invalid Failed Job ID');

            $success = QueueManager::retryFailedJob($failedJobId);
            if ($success) {
                return $this->success("Job #{$failedJobId} re-enqueued for processing");
            }
            return $this->error('Failed to retry job or job not found', 404);
        }

        // 4. RETRY ALL FAILED JOBS
        if ($method === 'POST' && $id === 'retry-all') {
            $count = QueueManager::retryAllFailedJobs();
            return $this->success("Re-enqueued {$count} failed jobs");
        }

        // 5. DELETE FAILED JOB
        if ($method === 'DELETE' && $id === 'failed' && $action !== null) {
            $failedJobId = (int)$action;
            $success = QueueManager::deleteFailedJob($failedJobId);
            return $this->json(['ok' => $success, 'message' => $success ? 'Failed job removed from DLQ' : 'Not found']);
        }

        // 6. FLUSH COMPLETED JOBS
        if ($method === 'POST' && $id === 'flush') {
            $hours = (int)$request->input('older_than_hours', 24);
            $deleted = QueueManager::flushCompletedJobs($hours);
            return $this->json(['ok' => true, 'deleted' => $deleted, 'message' => "Flushed {$deleted} completed jobs older than {$hours}h"]);
        }

        return $this->notFound();
    }
}
