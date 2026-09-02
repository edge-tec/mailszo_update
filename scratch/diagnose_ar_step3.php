<?php
/**
 * Diagnostic: Why is Auto Reply Step 3 not sending?
 * Run on server: php scratch/diagnose_ar_step3.php
 */
require_once __DIR__ . '/../includes/config.php';

echo "=== AUTO REPLY STEP 3 DIAGNOSTIC ===\n\n";

// 1. Check all threads at step 3
echo "── 1. Threads at current_step=3 ──\n";
$threads = db()->query("SELECT id, rule_id, from_email, current_step, status, scheduled_send_time, last_sent_at, first_reply_sent, smtp_used, messages_received, reply_count FROM autoreply_threads WHERE current_step >= 3 ORDER BY id DESC LIMIT 20")->fetchAll();
if (empty($threads)) {
    echo "NO threads found at step 3+\n\n";
    echo "── All pending/scheduled threads ──\n";
    $allThreads = db()->query("SELECT id, rule_id, from_email, current_step, status, scheduled_send_time, last_sent_at FROM autoreply_threads WHERE status IN ('pending','scheduled','sending') ORDER BY id DESC LIMIT 20")->fetchAll();
    foreach ($allThreads as $t) {
        echo "  Thread #{$t['id']} | step={$t['current_step']} | status={$t['status']} | email={$t['from_email']} | scheduled={$t['scheduled_send_time']} | last_sent={$t['last_sent_at']}\n";
    }
} else {
    foreach ($threads as $t) {
        echo "  Thread #{$t['id']} | step={$t['current_step']} | status={$t['status']} | email={$t['from_email']}\n";
        echo "    scheduled={$t['scheduled_send_time']} | last_sent={$t['last_sent_at']} | reply_count={$t['reply_count']}\n";
        echo "    first_reply_sent={$t['first_reply_sent']} | smtp_used={$t['smtp_used']} | msgs_received={$t['messages_received']}\n";
    }
}

echo "\n── 2. Check step 3 templates exist ──\n";
$rules = db()->query("SELECT id, name, status FROM autoreply_rules WHERE status='active'")->fetchAll();
foreach ($rules as $r) {
    $steps = db()->prepare("SELECT step_number, subject, delay_value, delay_unit FROM autoreply_steps WHERE rule_id=? ORDER BY step_number");
    $steps->execute([$r['id']]);
    $allSteps = $steps->fetchAll();
    echo "  Rule #{$r['id']} '{$r['name']}': " . count($allSteps) . " steps\n";
    foreach ($allSteps as $s) {
        $mark = ($s['step_number'] == 3) ? ' <<< STEP 3' : '';
        echo "    Step {$s['step_number']}: delay={$s['delay_value']} {$s['delay_unit']}{$mark}\n";
    }
    if (count($allSteps) < 3) {
        echo "    WARNING: ONLY " . count($allSteps) . " STEPS — Step 3 MISSING!\n";
    }
}

echo "\n── 3. SMTP config ──\n";
foreach ($rules as $r) {
    $rd = db()->prepare("SELECT primary_smtp_id, secondary_smtp_id, smtp_ids FROM autoreply_rules WHERE id=?");
    $rd->execute([$r['id']]);
    $rd = $rd->fetch();
    echo "  Rule #{$r['id']}: primary={$rd['primary_smtp_id']} secondary={$rd['secondary_smtp_id']}\n";
    if ($rd['secondary_smtp_id']) {
        $smtp = db()->prepare("SELECT id, name, host, from_email FROM smtp_providers WHERE id=?");
        $smtp->execute([$rd['secondary_smtp_id']]);
        $sr = $smtp->fetch();
        echo "    Secondary: " . ($sr ? "#{$sr['id']} {$sr['name']} ({$sr['from_email']})" : "NOT FOUND!") . "\n";
    }
}

echo "\n── 4. Recent AR logs for step 3+ ──\n";
$logs = db()->query("SELECT id, thread_id, step_number, to_email, status, error, sent_at FROM autoreply_logs WHERE step_number >= 3 ORDER BY id DESC LIMIT 10")->fetchAll();
if (empty($logs)) echo "  NO logs for step 3+ (never attempted)\n";
foreach ($logs as $l) {
    echo "  Log #{$l['id']} step={$l['step_number']} status={$l['status']} email={$l['to_email']}";
    if ($l['error']) echo " ERROR={$l['error']}";
    echo "\n";
}

echo "\n── 5. Recently completed threads ──\n";
$completed = db()->query("SELECT id, from_email, current_step, status, last_sent_at FROM autoreply_threads WHERE status='completed' ORDER BY id DESC LIMIT 10")->fetchAll();
foreach ($completed as $c) {
    echo "  #{$c['id']} step={$c['current_step']} email={$c['from_email']} last_sent={$c['last_sent_at']}\n";
}

echo "\n=== DONE ===\n";
