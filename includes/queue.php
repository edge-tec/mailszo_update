<?php
/**
 * Mailpro Enterprise - High-Throughput Asynchronous Queue Engine
 * 
 * Features:
 *  - Priority Queues (urgent, autoreply, followup, campaign, bounce, default)
 *  - Atomic Job Reservation (Zero Race Conditions across concurrent workers)
 *  - Crash Recovery for Stale Locks
 *  - Exponential Backoff with Jitter for Retries
 *  - Dead-Letter Queue (DLQ / failed_jobs)
 *  - Isolated Job Handlers
 */

class QueueManager {

    public const QUEUE_URGENT    = 'urgent';
    public const QUEUE_AUTOREPLY = 'autoreply';
    public const QUEUE_FOLLOWUP  = 'followup';
    public const QUEUE_CAMPAIGN  = 'campaign';
    public const QUEUE_BOUNCE    = 'bounce';
    public const QUEUE_DEFAULT   = 'default';

    /**
     * Default priority weights (higher integer = processed first)
     */
    public const PRIORITIES = [
        self::QUEUE_URGENT    => 100,
        self::QUEUE_AUTOREPLY => 80,
        self::QUEUE_FOLLOWUP  => 60,
        self::QUEUE_BOUNCE    => 40,
        self::QUEUE_CAMPAIGN  => 20,
        self::QUEUE_DEFAULT   => 50
    ];

    /**
     * Enqueue a new asynchronous job.
     *
     * @param string $queue Target queue name
     * @param string $handler Class or action identifier (e.g. 'campaign_send', 'imap_poll')
     * @param array $args Job payload parameters
     * @param int|null $priority Priority (null uses default for queue)
     * @param int $delaySeconds Delay before job becomes available
     * @param int $maxAttempts Maximum retry attempts before DLQ
     * @return int Inserted job ID
     */
    public static function push(
        string $queue,
        string $handler,
        array $args = [],
        ?int $priority = null,
        int $delaySeconds = 0,
        int $maxAttempts = 3
    ): int {
        if (!function_exists('db')) {
            throw new Exception("Database connection unavailable");
        }

        $pdo = db();
        $queueClean = strtolower(trim($queue)) ?: self::QUEUE_DEFAULT;
        $pri = $priority !== null ? $priority : (self::PRIORITIES[$queueClean] ?? 50);
        $availAt = date('Y-m-d H:i:s', time() + max(0, $delaySeconds));

        $payload = json_encode([
            'handler'    => $handler,
            'args'       => $args,
            'created_at' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            "INSERT INTO queue_jobs 
             (queue, priority, status, payload, attempts, max_attempts, available_at, created_at)
             VALUES (?, ?, 'pending', ?, 0, ?, ?, ?)"
        );
        $stmt->execute([$queueClean, $pri, $payload, $maxAttempts, $availAt, $now]);
        return (int)$pdo->lastInsertId();
    }

    /**
     * Atomically reserve the next available job for execution.
     * Guaranteed safe across unlimited concurrent worker processes.
     *
     * @param array $queues List of queue names to pull from (in order of preference)
     * @param string $workerId Identifier of worker (PID or hostname:pid)
     * @param int $staleTimeoutSeconds Seconds after which an unfinished reserved job is considered crashed
     * @return array|null The reserved job row with decoded payload, or null if queue empty
     */
    public static function reserveNextJob(
        array $queues = [],
        string $workerId = '',
        int $staleTimeoutSeconds = 300
    ): ?array {
        if (!function_exists('db')) return null;
        $pdo = db();

        if (empty($queues)) {
            $queues = [self::QUEUE_URGENT, self::QUEUE_AUTOREPLY, self::QUEUE_FOLLOWUP, self::QUEUE_BOUNCE, self::QUEUE_CAMPAIGN, self::QUEUE_DEFAULT];
        }
        $workerToken = $workerId ?: ('worker_' . getmypid() . '_' . bin2hex(random_bytes(3)));

        // 1. Recover stale reserved jobs (crashed workers)
        try {
            $staleTime = date('Y-m-d H:i:s', time() - $staleTimeoutSeconds);
            $pdo->prepare(
                "UPDATE queue_jobs 
                 SET status = 'pending', reserved_at = NULL, reserved_by = NULL 
                 WHERE status = 'reserved' AND reserved_at < ?"
            )->execute([$staleTime]);
        } catch (\Throwable $e) {}

        // 2. Atomic reservation transaction
        $qPlaceholders = implode(',', array_fill(0, count($queues), '?'));
        $now = date('Y-m-d H:i:s');

        try {
            $pdo->beginTransaction();

            $isMysql = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql');
            $forUpdate = $isMysql ? 'FOR UPDATE' : '';

            // Select next candidate job
            $sql = "SELECT id FROM queue_jobs 
                    WHERE queue IN ({$qPlaceholders}) 
                      AND status = 'pending' 
                      AND available_at <= ? 
                    ORDER BY priority DESC, available_at ASC, id ASC 
                    LIMIT 1 
                    {$forUpdate}";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge($queues, [$now]));
            $jobId = (int)$stmt->fetchColumn();

            if (!$jobId) {
                $pdo->commit();
                return null;
            }

            // Reserve the candidate
            $upd = $pdo->prepare(
                "UPDATE queue_jobs 
                 SET status = 'reserved', 
                     reserved_at = ?, 
                     reserved_by = ?, 
                     attempts = attempts + 1 
                 WHERE id = ? AND status = 'pending'"
            );
            $upd->execute([$now, $workerToken, $jobId]);

            if ($upd->rowCount() === 0) {
                // Another concurrent worker acquired this job in a race condition
                $pdo->rollBack();
                return null;
            }

            // Fetch full job record
            $fetchStmt = $pdo->prepare("SELECT * FROM queue_jobs WHERE id = ?");
            $fetchStmt->execute([$jobId]);
            $job = $fetchStmt->fetch();

            $pdo->commit();

            if ($job) {
                $job['payload_data'] = json_decode($job['payload'] ?? '{}', true) ?: [];
            }
            return $job;

        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("[QueueManager] Reservation error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Mark a job as successfully completed.
     */
    public static function markCompleted(int $jobId, bool $purge = false): bool {
        if (!function_exists('db')) return false;
        try {
            $pdo = db();
            if ($purge) {
                return $pdo->prepare("DELETE FROM queue_jobs WHERE id = ?")->execute([$jobId]);
            }
            $now = date('Y-m-d H:i:s');
            return $pdo->prepare("UPDATE queue_jobs SET status = 'completed', updated_at = ? WHERE id = ?")->execute([$now, $jobId]);
        } catch (\Throwable $e) {
            error_log("[QueueManager] markCompleted error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Mark a job as failed. Handles Exponential Backoff retry and Dead-Letter Queue promotion.
     */
    public static function markFailed(array $job, Throwable $exception): bool {
        if (!function_exists('db')) return false;
        $pdo = db();
        $jobId = (int)$job['id'];
        $attempts = (int)$job['attempts'];
        $maxAttempts = (int)($job['max_attempts'] ?: 3);
        $errMessage = substr($exception->getMessage() . "\n" . $exception->getTraceAsString(), 0, 2000);
        $now = date('Y-m-d H:i:s');

        try {
            if ($attempts >= $maxAttempts) {
                // ── DEAD-LETTER QUEUE (DLQ) ───────────────────────────────
                $pdo->prepare(
                    "INSERT INTO failed_jobs (job_id, queue, payload, exception, failed_at)
                     VALUES (?, ?, ?, ?, ?)"
                )->execute([$jobId, $job['queue'], $job['payload'], $errMessage, $now]);

                $pdo->prepare("UPDATE queue_jobs SET status = 'failed', last_error = ?, updated_at = ? WHERE id = ?")
                    ->execute([$errMessage, $now, $jobId]);
                return true;
            } else {
                // ── EXPONENTIAL BACKOFF WITH JITTER ───────────────────────
                // Backoff: 1st retry ~15-20s, 2nd retry ~60-65s, 3rd retry ~240s
                $backoffSecs = (int)(pow(2, $attempts) * 15) + rand(1, 5);
                $nextAvail = date('Y-m-d H:i:s', time() + $backoffSecs);

                $pdo->prepare(
                    "UPDATE queue_jobs 
                     SET status = 'pending', 
                         reserved_at = NULL, 
                         reserved_by = NULL, 
                         available_at = ?, 
                         last_error = ?, 
                         updated_at = ? 
                     WHERE id = ?"
                )->execute([$nextAvail, $errMessage, $now, $jobId]);
                return false;
            }
        } catch (\Throwable $e) {
            error_log("[QueueManager] markFailed error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Retry a permanently failed job from the DLQ.
     */
    public static function retryFailedJob(int $failedJobId): bool {
        if (!function_exists('db')) return false;
        $pdo = db();

        try {
            $stmt = $pdo->prepare("SELECT * FROM failed_jobs WHERE id = ?");
            $stmt->execute([$failedJobId]);
            $failed = $stmt->fetch();
            if (!$failed) return false;

            $now = date('Y-m-d H:i:s');
            // Reset in queue_jobs or re-insert
            $chk = $pdo->prepare("SELECT id FROM queue_jobs WHERE id = ?");
            $chk->execute([$failed['job_id']]);
            if ($chk->fetchColumn()) {
                $pdo->prepare(
                    "UPDATE queue_jobs 
                     SET status = 'pending', attempts = 0, reserved_at = NULL, reserved_by = NULL, available_at = ?, last_error = NULL 
                     WHERE id = ?"
                )->execute([$now, $failed['job_id']]);
            } else {
                $pdo->prepare(
                    "INSERT INTO queue_jobs (queue, priority, status, payload, attempts, max_attempts, available_at, created_at)
                     VALUES (?, 50, 'pending', ?, 0, 3, ?, ?)"
                )->execute([$failed['queue'], $failed['payload'], $now, $now]);
            }

            // Remove from failed_jobs
            $pdo->prepare("DELETE FROM failed_jobs WHERE id = ?")->execute([$failedJobId]);
            return true;
        } catch (\Throwable $e) {
            error_log("[QueueManager] retryFailedJob error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Retry all failed jobs from DLQ.
     */
    public static function retryAllFailedJobs(): int {
        if (!function_exists('db')) return 0;
        $pdo = db();
        try {
            $failedJobs = $pdo->query("SELECT id FROM failed_jobs ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
            $retried = 0;
            foreach ($failedJobs as $fid) {
                if (self::retryFailedJob((int)$fid)) {
                    $retried++;
                }
            }
            return $retried;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Delete a failed job permanently from DLQ.
     */
    public static function deleteFailedJob(int $failedJobId): bool {
        if (!function_exists('db')) return false;
        try {
            return db()->prepare("DELETE FROM failed_jobs WHERE id = ?")->execute([$failedJobId]);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Purge completed jobs older than N hours.
     */
    public static function flushCompletedJobs(int $olderThanHours = 24): int {
        if (!function_exists('db')) return 0;
        try {
            $stmt = db()->prepare("DELETE FROM queue_jobs WHERE status = 'completed' AND updated_at < DATE_SUB(NOW(), INTERVAL ? HOUR)");
            $stmt->execute([max(1, $olderThanHours)]);
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Real-time Queue Telemetry.
     */
    public static function getStats(): array {
        if (!function_exists('db')) {
            return ['ok' => false, 'error' => 'Database unavailable'];
        }

        $pdo = db();
        $stats = [
            'total_pending'   => 0,
            'total_reserved'  => 0,
            'total_completed' => 0,
            'total_failed'    => 0,
            'queues'          => []
        ];

        try {
            $statusCounts = $pdo->query("SELECT status, COUNT(*) as cnt FROM queue_jobs GROUP BY status")->fetchAll();
            foreach ($statusCounts as $row) {
                $st = $row['status'];
                if ($st === 'pending')   $stats['total_pending']   = (int)$row['cnt'];
                if ($st === 'reserved')  $stats['total_reserved']  = (int)$row['cnt'];
                if ($st === 'completed') $stats['total_completed'] = (int)$row['cnt'];
                if ($st === 'failed')    $stats['total_failed']    = (int)$row['cnt'];
            }

            $dlqCount = (int)$pdo->query("SELECT COUNT(*) FROM failed_jobs")->fetchColumn();
            $stats['dlq_count'] = $dlqCount;

            $queueCounts = $pdo->query(
                "SELECT queue, 
                        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_cnt,
                        SUM(CASE WHEN status = 'reserved' THEN 1 ELSE 0 END) as reserved_cnt
                 FROM queue_jobs 
                 GROUP BY queue"
            )->fetchAll();

            foreach ($queueCounts as $q) {
                $stats['queues'][$q['queue']] = [
                    'pending'  => (int)$q['pending_cnt'],
                    'reserved' => (int)$q['reserved_cnt']
                ];
            }
        } catch (\Throwable $e) {}

        return $stats;
    }

    /**
     * Dispatch and execute a single job payload.
     *
     * @param array $job The job row from queue_jobs
     * @return mixed Execution result
     */
    public static function executeJob(array $job) {
        $payload = $job['payload_data'] ?? (json_decode($job['payload'] ?? '{}', true) ?: []);
        $handler = $payload['handler'] ?? '';
        $args    = $payload['args'] ?? [];

        switch ($handler) {
            case 'campaign_send':
                return self::handleCampaignSend($args);

            case 'autoreply_process':
                return self::handleAutoReplyProcess($args);

            case 'followup_process':
                return self::handleFollowupProcess($args);

            case 'imap_poll':
                return self::handleImapPoll($args);

            case 'bounce_poll':
                return self::handleBouncePoll($args);

            default:
                // Support class-based handlers: HandlerClass::handle(args)
                if (class_exists($handler) && method_exists($handler, 'handle')) {
                    return $handler::handle($args);
                }
                throw new Exception("Unknown job handler: {$handler}");
        }
    }

    // ── BUILT-IN JOB HANDLERS ───────────────────────────────────────

    /**
     * Process Campaign Email Dispatch Job
     */
    protected static function handleCampaignSend(array $args): array {
        if (!class_exists('Mailer')) {
            require_once __DIR__ . '/mailer.php';
        }

        $mailerCfg   = $args['mailer_cfg'];
        $recipient   = $args['recipient'];
        $message     = $args['message'];
        $campaignId  = (int)($args['campaign_id'] ?? 0);
        $userId      = (int)($args['user_id'] ?? 1);
        $options     = $args['options'] ?? [];

        $mailer = new Mailer($mailerCfg);
        $mailer->send(
            $recipient['email'],
            $recipient['name'] ?? '',
            $message['subject'],
            $message['html'],
            $message['text'] ?? '',
            $message['inlineImages'] ?? [],
            $options
        );

        return ['success' => true, 'email' => $recipient['email'], 'campaign_id' => $campaignId];
    }

    /**
     * Process Inbound IMAP Account Polling Job
     */
    protected static function handleImapPoll(array $args): array {
        if (!class_exists('ImapClient')) {
            require_once __DIR__ . '/imap.php';
        }

        $accountId = (int)($args['account_id'] ?? 0);
        if (!$accountId || !function_exists('db')) {
            throw new Exception("Invalid IMAP account ID");
        }

        $stmt = db()->prepare("SELECT * FROM imap_accounts WHERE id = ?");
        $stmt->execute([$accountId]);
        $account = $stmt->fetch();
        if (!$account) {
            throw new Exception("IMAP account #{$accountId} not found");
        }

        $client = new ImapClient($account);
        $client->connect();
        $messages = $client->fetchUnread(50);
        $client->close();

        return ['account_id' => $accountId, 'messages_count' => count($messages)];
    }

    /**
     * Process Auto-Reply Sequence Step
     */
    protected static function handleAutoReplyProcess(array $args): array {
        if (!class_exists('Mailer')) {
            require_once __DIR__ . '/mailer.php';
        }

        $threadId = (int)($args['thread_id'] ?? 0);
        $mailerCfg = $args['mailer_cfg'] ?? [];
        $to = $args['to'] ?? '';
        $subject = $args['subject'] ?? '';
        $html = $args['html'] ?? '';
        $options = $args['options'] ?? [];

        $mailer = new Mailer($mailerCfg);
        $mailer->send($to, $args['to_name'] ?? '', $subject, $html, '', [], $options);

        return ['thread_id' => $threadId, 'sent_to' => $to];
    }

    /**
     * Process Follow-Up Sequence Step
     */
    protected static function handleFollowupProcess(array $args): array {
        if (!class_exists('Mailer')) {
            require_once __DIR__ . '/mailer.php';
        }

        $contactId = (int)($args['contact_id'] ?? 0);
        $mailerCfg = $args['mailer_cfg'] ?? [];
        $to = $args['to'] ?? '';
        $subject = $args['subject'] ?? '';
        $html = $args['html'] ?? '';
        $options = $args['options'] ?? [];

        $mailer = new Mailer($mailerCfg);
        $mailer->send($to, $args['to_name'] ?? '', $subject, $html, '', [], $options);

        return ['contact_id' => $contactId, 'sent_to' => $to];
    }

    /**
     * Process Bounce Inbox Polling
     */
    protected static function handleBouncePoll(array $args): array {
        if (!class_exists('BounceProcessor')) {
            require_once __DIR__ . '/bounce_processor.php';
        }

        $mailbox = $args['mailbox'] ?? [];
        if (empty($mailbox)) {
            throw new Exception("Bounce mailbox configuration missing");
        }

        return BounceProcessor::processImapBounceMailbox($mailbox);
    }
}
