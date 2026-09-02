<?php
/**
 * Diagnostic: Check current thread status for step 4,5,6 issue
 */
require_once __DIR__ . '/../includes/config.php';

echo "=== CURRENT THREAD STATUS ===\n\n";
echo "Server time: " . date('Y-m-d H:i:s') . "\n\n";

// All non-completed threads
$threads = db()->query("SELECT id, rule_id, from_email, current_step, status, scheduled_send_time, last_sent_at, messages_received, reply_count FROM autoreply_threads WHERE status != 'completed' ORDER BY id DESC LIMIT 20")->fetchAll();
echo "── Active Threads ──\n";
foreach ($threads as $t) {
    echo "  #{$t['id']} step={$t['current_step']} status={$t['status']} email={$t['from_email']}\n";
    echo "    scheduled={$t['scheduled_send_time']} last_sent={$t['last_sent_at']} msgs={$t['messages_received']} replies={$t['reply_count']}\n";
}

echo "\n── Recent AR Logs (last 20) ──\n";
$logs = db()->query("SELECT id, thread_id, step_number, to_email, status, error, sent_at FROM autoreply_logs ORDER BY id DESC LIMIT 20")->fetchAll();
foreach ($logs as $l) {
    echo "  step={$l['step_number']} status={$l['status']} email={$l['to_email']} sent={$l['sent_at']}";
    if ($l['error']) echo " ERR={$l['error']}";
    echo "\n";
}

echo "\n── Rule sequential_mode check ──\n";
$rules = db()->query("SELECT id, name, sequential_mode, enable_always_send_followup FROM autoreply_rules WHERE status='active'")->fetchAll();
foreach ($rules as $r) {
    echo "  Rule #{$r['id']} '{$r['name']}': sequential_mode=" . ($r['sequential_mode'] ?? 'NULL') . " enable_always_send_followup=" . ($r['enable_always_send_followup'] ?? 'NULL') . "\n";
}

echo "\n── KEY FINDING ──\n";
foreach ($threads as $t) {
    if ($t['status'] === 'pending') {
        echo "  ⚠️  Thread #{$t['id']} (step {$t['current_step']}) is PENDING — waiting for recipient to REPLY before next step sends.\n";
        echo "     The system requires recipient's reply before sending step {$t['current_step']}.\n";
    } elseif ($t['status'] === 'scheduled') {
        $due = strtotime($t['scheduled_send_time']) <= time() ? 'DUE NOW' : 'FUTURE';
        echo "  ✅ Thread #{$t['id']} (step {$t['current_step']}) is SCHEDULED — {$due} at {$t['scheduled_send_time']}\n";
    }
}

echo "\n=== DONE ===\n";
