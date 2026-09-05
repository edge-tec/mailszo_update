#!/usr/bin/env php
<?php
/**
 * Mailpro Enterprise - Real-Time IMAP IDLE Daemon
 *
 * Runs an event-driven persistent background listener across multiple IMAP inboxes.
 * Instantly detects incoming emails within 1-3 seconds and dispatches urgent auto-replies.
 *
 * Usage:
 *   php imap_daemon.php                        # Monitor all active IMAP accounts
 *   php imap_daemon.php --accounts=1,5,10      # Monitor specific accounts
 *   php imap_daemon.php --max-runtime=3600     # Run for 1 hour then restart (recommended for Systemd/Supervisor)
 *   php imap_daemon.php --once                 # Test/single check mode
 */

if (php_sapi_name() !== 'cli') {
    die("Error: imap_daemon.php must be run from the command line (CLI).\n");
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/imap_idle.php';
require_once __DIR__ . '/includes/queue.php';

$options = getopt('', [
    'accounts::',
    'max-runtime::',
    'once',
    'help'
]);

if (isset($options['help'])) {
    echo "Mailpro Enterprise IMAP IDLE Daemon\n";
    echo "Options:\n";
    echo "  --accounts=ID1,ID2    Comma-separated list of IMAP account IDs (default: all active)\n";
    echo "  --max-runtime=SECS    Max seconds to run before cycling (default: unlimited)\n";
    echo "  --once                Single-check mode (connect, check, exit)\n";
    echo "  --help                Display this help screen\n\n";
    exit(0);
}

if (!isInstalled()) {
    die("Error: Mailpro is not installed yet.\n");
}

$accountsFilter = !empty($options['accounts']) ? array_map('intval', explode(',', $options['accounts'])) : [];
$maxRuntime     = max(0, (int)($options['max-runtime'] ?? 0));
$runOnce        = isset($options['once']);

// Query target IMAP accounts
$pdo = db();
$sql = "SELECT a.* FROM imap_accounts a 
        JOIN users u ON u.id = a.user_id 
        WHERE u.status = 'active'";
if ($accountsFilter) {
    $ph = implode(',', array_fill(0, count($accountsFilter), '?'));
    $sql .= " AND a.id IN ({$ph})";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($accountsFilter);
} else {
    $stmt = $pdo->query($sql);
}

$accounts = $stmt->fetchAll();

if (empty($accounts)) {
    echo "[" . date('Y-m-d H:i:s') . "] No active IMAP accounts found to monitor. Exiting.\n";
    exit(0);
}

echo "\n=========================================================\n";
echo "  MAILPRO ENTERPRISE - REAL-TIME IMAP IDLE DAEMON (RFC 2177)\n";
echo "  Monitored Accounts: " . count($accounts) . "\n";
echo "  Urgent Dispatch SLA: < 3 seconds\n";
echo "=========================================================\n\n";

$multiplexer = new ImapIdleMultiplexer();
foreach ($accounts as $acc) {
    $multiplexer->addAccount($acc);
}

// Signal handling for graceful shutdown
if (function_exists('pcntl_signal')) {
    declare(ticks = 1);
    $stopHandler = function() use ($multiplexer) {
        echo "\n[" . date('Y-m-d H:i:s') . "] Termination signal received. Stopping IDLE sessions cleanly...\n";
        $multiplexer->stop();
    };
    pcntl_signal(SIGTERM, $stopHandler);
    pcntl_signal(SIGINT, $stopHandler);
}

// Handler when instant push delivers new messages
$onNewMessages = function(int $accountId, array $messages) use ($pdo) {
    $dispatchStart = microtime(true);
    echo sprintf("[%s] [IMAP IDLE Push] Processing %d new message(s) from Account #%d...\n",
        date('Y-m-d H:i:s'),
        count($messages),
        $accountId
    );

    // Fetch matching auto-reply rules for this account
    $rulesStmt = $pdo->prepare(
        "SELECT r.*, u.status as u_status 
         FROM autoreply_rules r 
         JOIN users u ON u.id = r.user_id 
         WHERE (r.imap_id = ? OR r.primary_imap_id = ?) AND u.status = 'active'"
    );
    $rulesStmt->execute([$accountId, $accountId]);
    $rules = $rulesStmt->fetchAll();

    foreach ($messages as $msg) {
        $fromEmail = strtolower(trim($msg['from_email'] ?? ''));
        if (!$fromEmail) continue;

        $fromName = trim($msg['from_name'] ?? '');
        $subject  = trim($msg['subject'] ?? '');
        $uid      = (int)($msg['uid'] ?? 0);

        // Pre-check blacklist
        if (function_exists('isBlacklisted') && isBlacklisted($fromEmail, 1)) {
            echo sprintf("  [Skip] Sender <%s> is blacklisted. Skipped.\n", $fromEmail);
            continue;
        }

        foreach ($rules as $rule) {
            $ruleId = (int)$rule['id'];
            $userId = (int)$rule['user_id'];

            // Check if thread already exists
            $tStmt = $pdo->prepare("SELECT id, status FROM autoreply_threads WHERE rule_id = ? AND LOWER(from_email) = ?");
            $tStmt->execute([$ruleId, $fromEmail]);
            $thread = $tStmt->fetch();

            if (!$thread) {
                // Enroll new thread
                $insThread = $pdo->prepare(
                    "INSERT INTO autoreply_threads 
                     (rule_id, from_email, from_name, subject_in, current_step, status, scheduled_send_time, created_at)
                     VALUES (?, ?, ?, ?, 1, 'scheduled', NOW(), NOW())"
                );
                $insThread->execute([$ruleId, $fromEmail, $fromName, $subject]);
                $threadId = (int)$pdo->lastInsertId();

                // ── INSTANT REAL-TIME DISPATCH VIA QUEUE ENGINE ───────────
                // Push an immediate URGENT job with Priority 100
                $jobId = QueueManager::push(
                    QueueManager::QUEUE_URGENT,
                    'autoreply_process',
                    [
                        'thread_id' => $threadId,
                        'rule_id'   => $ruleId,
                        'user_id'   => $userId,
                        'to'        => $fromEmail,
                        'to_name'   => $fromName,
                        'subject'   => 'Re: ' . $subject,
                        'html'      => '<p>Thank you for reaching out! We received your message and will get back to you shortly.</p>'
                    ],
                    100 // Highest priority
                );

                $latencyMs = round((microtime(true) - $dispatchStart) * 1000, 2);
                echo sprintf("  ⚡ [Instant Enroll] Lead <%s> enrolled in Rule '%s'! Urgent Job #%d enqueued in %sms.\n",
                    $fromEmail,
                    $rule['name'],
                    $jobId,
                    $latencyMs
                );
            }
        }
    }
};

// Start the event loop
$duration = $runOnce ? 1 : $maxRuntime;
$multiplexer->run($onNewMessages, $duration);
exit(0);
