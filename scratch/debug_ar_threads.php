<?php
/**
 * Debug script: Check current state of autoreply_threads 
 * Run on server: php /www/wwwroot/m.mailszo.com/scratch/debug_ar_threads.php
 */
require_once __DIR__ . '/../includes/config.php';

echo "=== AUTOREPLY RULES ===\n";
$rules = db()->query("SELECT id, name, imap_id, imap2_id, sequential_mode, status, user_id FROM autoreply_rules")->fetchAll();
foreach ($rules as $r) {
    echo "  Rule #{$r['id']}: {$r['name']} | sequential={$r['sequential_mode']} | status={$r['status']} | user={$r['user_id']} | imap1={$r['imap_id']} | imap2={$r['imap2_id']}\n";
}

echo "\n=== AUTOREPLY STEPS ===\n";
$steps = db()->query("SELECT rule_id, step_number, delay_minutes, delay_value, delay_unit FROM autoreply_steps ORDER BY rule_id, step_number")->fetchAll();
foreach ($steps as $s) {
    echo "  Rule#{$s['rule_id']} Step#{$s['step_number']}: delay_value={$s['delay_value']} delay_unit={$s['delay_unit']} delay_minutes={$s['delay_minutes']}\n";
}

echo "\n=== AUTOREPLY THREADS (last 20) ===\n";
$threads = db()->query("SELECT id, rule_id, from_email, current_step, status, scheduled_send_time, last_sent_at, messages_received, reply_count, first_reply_sent, last_received_message_id, last_trigger_uid, last_trigger_imap_id FROM autoreply_threads ORDER BY id DESC LIMIT 20")->fetchAll();
foreach ($threads as $t) {
    echo "  Thread#{$t['id']}: rule={$t['rule_id']} email={$t['from_email']} step={$t['current_step']} status={$t['status']} ";
    echo "scheduled={$t['scheduled_send_time']} last_sent={$t['last_sent_at']} msgs_recv={$t['messages_received']} ";
    echo "reply_count={$t['reply_count']} first_sent={$t['first_reply_sent']} ";
    echo "last_msg_id=" . substr($t['last_received_message_id'] ?? '', 0, 30) . " ";
    echo "last_uid={$t['last_trigger_uid']} last_imap={$t['last_trigger_imap_id']}\n";
}

echo "\n=== AUTOREPLY LOGS (last 10) ===\n";
$logs = db()->query("SELECT rule_id, thread_id, step_number, to_email, status, error, smtp_used, sent_at FROM autoreply_logs ORDER BY id DESC LIMIT 10")->fetchAll();
foreach ($logs as $l) {
    echo "  Log: rule={$l['rule_id']} thread={$l['thread_id']} step={$l['step_number']} to={$l['to_email']} status={$l['status']} smtp={$l['smtp_used']} at={$l['sent_at']}";
    if ($l['error']) echo " ERR={$l['error']}";
    echo "\n";
}

echo "\n=== IMAP ACCOUNTS ===\n";
$imaps = db()->query("SELECT id, name, username, user_id, last_uid, last_uid_validity FROM imap_accounts")->fetchAll();
foreach ($imaps as $i) {
    echo "  IMAP#{$i['id']}: {$i['username']} | user={$i['user_id']} | last_uid={$i['last_uid']} | uidvalidity={$i['last_uid_validity']}\n";
}

echo "\nDone.\n";
