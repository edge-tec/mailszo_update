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
require_once __DIR__ . '/includes/imap.php';
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

    // Fetch matching auto-reply rules for this account.
    // Matches if this IMAP account is configured as:
    // primary imap (imap_id / primary_imap_id), secondary imap (imap2_id / secondary_imap_id),
    // backup imap (backup_imap_id), OR belongs to the same user as the IMAP account.
    $rulesStmt = $pdo->prepare(
        "SELECT r.*, u.status as u_status 
         FROM autoreply_rules r 
         JOIN users u ON u.id = r.user_id 
         WHERE (
            r.imap_id = ? 
            OR r.primary_imap_id = ? 
            OR r.imap2_id = ? 
            OR r.secondary_imap_id = ? 
            OR r.backup_imap_id = ? 
            OR r.user_id = (SELECT user_id FROM imap_accounts WHERE id = ?)
         ) AND r.status = 'active' AND u.status = 'active'"
    );
    $rulesStmt->execute([$accountId, $accountId, $accountId, $accountId, $accountId, $accountId]);
    $rules = $rulesStmt->fetchAll();

    foreach ($messages as $msg) {
        $fromEmail = strtolower(trim($msg['from_email'] ?? ''));
        if (!$fromEmail || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) continue;

        $fromName = trim($msg['from_name'] ?? '');
        $subject  = trim($msg['subject'] ?? '');
        $uid      = (int)($msg['uid'] ?? 0);
        $inMsgId  = trim($msg['message_id'] ?? '');
        $inIrt    = trim($msg['in_reply_to'] ?? '');
        $inRef    = trim($msg['references'] ?? '');

        // Resolve conversation thread ID
        $thId = function_exists('resolveConversationThreadId')
            ? resolveConversationThreadId($inMsgId, $inIrt, $inRef, $fromEmail, $subject)
            : substr(md5($fromEmail . '|' . $subject), 0, 32);

        // 1. Mandatory persistence into inbound_emails (updates dashboard stats & read counter)
        try {
            $inbStmt = $pdo->prepare(
                "INSERT INTO inbound_emails
                 (imap_account_id, uid, uid_validity, from_email, from_name, subject, message_id, in_reply_to, references_header, thread_id, received_at)
                 VALUES (?, ?, 0, ?, ?, ?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                   from_email = VALUES(from_email),
                   from_name  = COALESCE(NULLIF(VALUES(from_name), ''), from_name),
                   subject    = COALESCE(NULLIF(VALUES(subject),   ''), subject),
                   message_id = COALESCE(NULLIF(VALUES(message_id), ''), message_id),
                   in_reply_to = COALESCE(NULLIF(VALUES(in_reply_to), ''), in_reply_to),
                   references_header = COALESCE(NULLIF(VALUES(references_header), ''), references_header),
                   thread_id  = COALESCE(NULLIF(VALUES(thread_id), ''), thread_id)"
            );
            $inbStmt->execute([
                $accountId,
                $uid,
                substr($fromEmail, 0, 255),
                substr($fromName, 0, 255),
                substr($subject, 0, 500),
                $inMsgId ?: null,
                $inIrt ?: null,
                $inRef ?: null,
                $thId ?: null
            ]);
        } catch (\Throwable $inbEx) {
            echo sprintf("  [Inbound Notice] inbound_emails write: %s\n", $inbEx->getMessage());
        }

        // Log system read event for dashboard activity feed
        $primaryOwnerUid = 1;
        if (!empty($rules)) {
            $primaryOwnerUid = (int)($rules[0]['user_id'] ?? 1);
        }
        logSystemEvent('read', $fromEmail, "Incoming email read from {$fromEmail}: " . substr($subject, 0, 100), $primaryOwnerUid, null, null);

        // Pre-check blacklist
        if (function_exists('isBlacklisted') && isBlacklisted($fromEmail, $primaryOwnerUid)) {
            echo sprintf("  [Skip] Sender <%s> is blacklisted. Skipped.\n", $fromEmail);
            continue;
        }

        foreach ($rules as $rule) {
            $ruleId = (int)$rule['id'];
            $userId = (int)$rule['user_id'];

            // Check if thread already exists
            $tStmt = $pdo->prepare("SELECT * FROM autoreply_threads WHERE rule_id = ? AND LOWER(TRIM(from_email)) = ?");
            $tStmt->execute([$ruleId, $fromEmail]);
            $thread = $tStmt->fetch();

            if (!$thread) {
                // Fetch Step 1 delay
                $s1Stmt = $pdo->prepare("SELECT delay_value, delay_unit, delay_minutes FROM autoreply_steps WHERE rule_id = ? AND step_number = 1");
                $s1Stmt->execute([$ruleId]);
                $s1Row = $s1Stmt->fetch();
                $s1Val = max(0, (int)($s1Row['delay_value'] ?? $s1Row['delay_minutes'] ?? 0));
                $s1Unit = strtolower($s1Row['delay_unit'] ?? 'seconds');
                $s1Secs = delayToSeconds($s1Val, $s1Unit);
                $step1at = $s1Secs > 0 ? date('Y-m-d H:i:s', time() + $s1Secs) : date('Y-m-d H:i:s');

                // Enroll new thread
                $insThread = $pdo->prepare(
                    "INSERT INTO autoreply_threads 
                     (rule_id, from_email, from_name, subject_in, current_step, status, scheduled_send_time,
                      last_received_message_id, references_header, last_trigger_uid, last_trigger_imap_id, thread_id,
                      reply_count, messages_received, created_at)
                     VALUES (?, ?, ?, ?, 1, 'scheduled', ?, ?, ?, ?, ?, ?, 1, 1, NOW())"
                );
                $insThread->execute([
                    $ruleId, $fromEmail, $fromName, substr($subject, 0, 200), $step1at,
                    $inMsgId ?: null, $inRef ?: null, $uid ?: null, $accountId, $thId ?: null
                ]);

                logSystemEvent('queued', $fromEmail, "Auto Reply #1 scheduled for {$step1at} (+{$s1Val} {$s1Unit})", $userId, null, $ruleId);
                echo sprintf("  ⚡ [Instant Enroll] Lead <%s> enrolled in Rule '%s' (Step #1 scheduled for %s)\n",
                    $fromEmail,
                    $rule['name'],
                    $step1at
                );
            } else {
                // Lead replied to existing conversation!
                $curStep = (int)$thread['current_step'];

                // Check if step exists for this rule (handles Step 1 up to 15)
                $sStmt = $pdo->prepare("SELECT * FROM autoreply_steps WHERE rule_id = ? AND step_number = ?");
                $sStmt->execute([$ruleId, $curStep]);
                $stepRow = $sStmt->fetch();

                if (!$stepRow) {
                    // All configured steps completed (e.g. sent up to step 15)
                    $pdo->prepare("UPDATE autoreply_threads SET status = 'completed' WHERE id = ?")->execute([$thread['id']]);
                    continue;
                }

                // Duplicate reply protection:
                // Only skip if the exact same message_id was already processed
                $cleanInMsgId = trim(str_replace(['<','>'], '', $inMsgId));
                $cleanThMsgId = trim(str_replace(['<','>'], '', (string)($thread['last_received_message_id'] ?? '')));
                $isSameMsgId  = ($cleanInMsgId !== '' && $cleanThMsgId !== '' && strtolower($cleanInMsgId) === strtolower($cleanThMsgId));

                if ($isSameMsgId) {
                    echo sprintf("  [Skip] Duplicate reply from <%s> (same MsgID %s). Skipped.\n", $fromEmail, $inMsgId);
                    continue;
                }

                // In Sequential Mode: If delay is 0 or not set, trigger INSTANTLY upon reply!
                $isSeq = !empty($rule['sequential_mode']);
                if ($isSeq && (empty($stepRow['delay_value']) || (int)$stepRow['delay_value'] === 0)) {
                    $schedAt = date('Y-m-d H:i:s');
                } else {
                    $sVal = max(0, (int)($stepRow['delay_value'] ?? $stepRow['delay_minutes'] ?? 0));
                    $sUnit = strtolower($stepRow['delay_unit'] ?? 'seconds');
                    $sSecs = delayToSeconds($sVal, $sUnit);
                    $schedAt = $sSecs > 0 ? date('Y-m-d H:i:s', time() + $sSecs) : date('Y-m-d H:i:s');
                }
                $newRefs = trim(($thread['references_header'] ?? '') . ' ' . $inMsgId);

                $pdo->prepare(
                    "UPDATE autoreply_threads 
                     SET messages_received = messages_received + 1,
                         status = 'scheduled',
                         scheduled_send_time = ?,
                         subject_in = ?,
                         reply_count = reply_count + 1,
                         last_received_message_id = ?,
                         references_header = ?,
                         last_trigger_uid = ?,
                         last_trigger_imap_id = ?
                     WHERE id = ?"
                )->execute([
                    $schedAt,
                    substr($subject, 0, 200),
                    $inMsgId ?: null,
                    $newRefs ?: null,
                    $uid ?: null,
                    $accountId,
                    $thread['id']
                ]);

                logSystemEvent('queued', $fromEmail, "Auto Reply #{$curStep} scheduled for {$schedAt} (lead replied)", $userId, null, $ruleId);
                echo sprintf("  🔄 [Lead Reply] Lead <%s> replied! Unlocked Step #%d scheduled for %s\n",
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

    // ── AUTO-CUT / DELETE READ LEADS FROM IMAP MAILBOX ─────────────────
    // Ensures read leads are removed from the IMAP server mailbox
    if (!empty($messages) && function_exists('imapDeleteMessages')) {
        try {
            $accStmt = $pdo->prepare("SELECT host, port, secure, username, password FROM imap_accounts WHERE id = ?");
            $accStmt->execute([$accountId]);
            $accRow = $accStmt->fetch();
            if ($accRow) {
                $delCfg = [
                    'host'     => $accRow['host'],
                    'port'     => (int)($accRow['port'] ?? 993),
                    'username' => $accRow['username'],
                    'password' => $accRow['password'],
                    'ssl'      => !empty($accRow['ssl']) || !empty($accRow['secure']) || (int)($accRow['port'] ?? 993) === 993,
                ];
                $delRes = imapDeleteMessages($delCfg, $messages);
                if (!empty($delRes['ok'])) {
                    echo sprintf("  ✂️ [Cut/Deleted] Successfully cut %d read lead(s) from IMAP mailbox #%d (server mailbox clean).\n",
                        $delRes['deleted'] ?? count($messages),
                        $accountId
                    );
                } else {
                    echo sprintf("  ⚠️ [Cut Warning] Failed to cut read lead(s) from IMAP mailbox #%d: %s\n",
                        $accountId,
                        $delRes['message'] ?? 'unknown'
                    );
                }
            }
        } catch (\Throwable $delEx) {
            echo sprintf("  ⚠️ [Cut Exception] Error cutting read lead(s) from IMAP #%d: %s\n",
                $accountId,
                $delEx->getMessage()
            );
        }
    }
};

// Start the event loop
$duration = $runOnce ? 1 : $maxRuntime;
$multiplexer->run($onNewMessages, $duration);
exit(0);
