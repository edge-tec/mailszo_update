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

if (!extension_loaded('pdo_mysql')) {
    echo "\n\033[31m[ERROR] The 'pdo_mysql' extension is missing in this PHP binary (" . PHP_BINARY . ").\033[0m\n";
    echo "If using aaPanel, please run with aaPanel's PHP binary, for example:\n";
    echo "  👉 /www/server/php/82/bin/php imap_daemon.php\n";
    echo "  👉 /www/server/php/81/bin/php imap_daemon.php\n\n";
    exit(1);
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/imap_idle.php';
require_once __DIR__ . '/includes/queue.php';
require_once __DIR__ . '/includes/dispatcher.php';

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
            $tStmt = $pdo->prepare("SELECT id, status, current_step, messages_received FROM autoreply_threads WHERE rule_id = ? AND LOWER(from_email) = ?");
            $tStmt->execute([$ruleId, $fromEmail]);
            $thread = $tStmt->fetch();

            if (!$thread) {
                // Fetch Step 1 delay
                $s1Stmt = $pdo->prepare("SELECT delay_value, delay_unit, delay_minutes FROM autoreply_steps WHERE rule_id = ? AND step_number = 1");
                $s1Stmt->execute([$ruleId]);
                $s1Row = $s1Stmt->fetch();
                $s1Val = max(0, (int)($s1Row['delay_value'] ?? $s1Row['delay_minutes'] ?? 0));
                $s1Unit = strtolower($s1Row['delay_unit'] ?? 'minutes');
                $s1Secs = delayToSeconds($s1Val, $s1Unit);
                $step1at = $s1Secs > 0 ? date('Y-m-d H:i:s', time() + $s1Secs) : date('Y-m-d H:i:s');

                // Enroll new thread
                $insThread = $pdo->prepare(
                    "INSERT INTO autoreply_threads 
                     (rule_id, from_email, from_name, subject_in, current_step, status, scheduled_send_time, created_at)
                     VALUES (?, ?, ?, ?, 1, 'scheduled', ?, NOW())"
                );
                $insThread->execute([$ruleId, $fromEmail, $fromName, $subject, $step1at]);
                $threadId = (int)$pdo->lastInsertId();

                logSystemEvent('queued', $fromEmail, "Auto Reply #1 scheduled for {$step1at} (+{$s1Val} {$s1Unit})", $userId, null, $ruleId);
                echo sprintf("  ⚡ [Instant Enroll] Lead <%s> enrolled in Rule '%s' (Step #1 scheduled for %s)\n",
                    $fromEmail,
                    $rule['name'],
                    $step1at
                );
            } else {
                // Lead replied to existing conversation!
                $curStep = (int)$thread['current_step'];
                $sStmt = $pdo->prepare("SELECT delay_value, delay_unit, delay_minutes FROM autoreply_steps WHERE rule_id = ? AND step_number = ?");
                $sStmt->execute([$ruleId, $curStep]);
                $sRow = $sStmt->fetch();
                $sVal = max(0, (int)($sRow['delay_value'] ?? $sRow['delay_minutes'] ?? 0));
                $sUnit = strtolower($sRow['delay_unit'] ?? 'minutes');
                $sSecs = delayToSeconds($sVal, $sUnit);
                $schedAt = $sSecs > 0 ? date('Y-m-d H:i:s', time() + $sSecs) : date('Y-m-d H:i:s');

                $pdo->prepare(
                    "UPDATE autoreply_threads 
                     SET messages_received = messages_received + 1,
                         status = 'scheduled',
                         scheduled_send_time = ?,
                         subject_in = ?,
                         reply_count = reply_count + 1
                     WHERE id = ?"
                )->execute([$schedAt, $subject, $thread['id']]);

                logSystemEvent('queued', $fromEmail, "Auto Reply #{$curStep} scheduled for {$schedAt} (lead replied)", $userId, null, $ruleId);
                echo sprintf("  🔄 [Lead Reply] Lead <%s> unlocked Step #%d scheduled for %s\n",
                    $fromEmail,
                    $curStep,
                    $schedAt
                );
            }
        }

        // ── SIMULTANEOUS ACTION: AUTOMATICALLY ENROLL IN FOLLOW-UP ──────
        $preferredFuId = 0;
        $primaryUid = 1;
        foreach ($rules as $r) {
            $primaryUid = (int)($r['user_id'] ?? $primaryUid);
            if (!empty($r['followup_rule_id'])) {
                $preferredFuId = (int)$r['followup_rule_id'];
                break;
            }
        }
        $fuResults = autoEnrollInFollowup($fromEmail, $fromName, $primaryUid, $accountId, $preferredFuId);
        if (!empty($fuResults)) {
            foreach ($fuResults as $fu) {
                echo sprintf("  📬 [Follow-Up Enrolled] Lead <%s> enrolled in Follow-Up '%s' (Step #1 scheduled for %s)\n",
                    $fromEmail,
                    $fu['rule_name'],
                    $fu['scheduled_at']
                );
            }
        }

        // ── INSTANT REAL-TIME DISPATCH (< 500ms) ─────────────────────────
        // Dispatches any due auto-replies or follow-ups immediately
        $arSent = processAutoReplyQueue(25);
        $fuSent = processFollowUpQueue(25);
        if ($arSent > 0 || $fuSent > 0) {
            echo sprintf("  🚀 [Instant Sent] Dispatched %d auto-reply and %d follow-up immediately!\n", $arSent, $fuSent);
        }
    }
};

// Start the event loop
$duration = $runOnce ? 1 : $maxRuntime;
$multiplexer->run($onNewMessages, $duration);
exit(0);
