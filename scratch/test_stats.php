<?php
require_once __DIR__ . '/../includes/config.php';

try {
    $db = db();
    echo "DB Connection OK\n";

    // Test resolveDateRange
    $rng = resolveDateRange('yesterday', '', '');
    echo "RNG Active: " . ($rng['active'] ? 'YES' : 'NO') . "\n";
    echo "RNG From: " . $rng['from'] . "\n";
    echo "RNG To: " . $rng['to'] . "\n";

    $dfSL  = $rng['active'] ? sprintf(" AND sent_at     BETWEEN '%s' AND '%s'", $rng['from'], $rng['to']) : '';
    $dfCmp = $rng['active'] ? sprintf(" AND created_at  BETWEEN '%s' AND '%s'", $rng['from'], $rng['to']) : '';
    $dfInb = $rng['active'] ? sprintf(" AND received_at BETWEEN '%s' AND '%s'", $rng['from'], $rng['to']) : '';

    $sql = "SELECT
        (SELECT COUNT(*) FROM send_logs WHERE status='sent'   AND (log_source IS NULL OR log_source='campaign'){$dfSL}) total_sent,
        (SELECT COUNT(*) FROM send_logs WHERE status='failed' AND (log_source IS NULL OR log_source='campaign'){$dfSL}) total_failed,
        (SELECT COUNT(*) FROM campaigns WHERE status='running') active,
        (SELECT COUNT(*) FROM campaigns WHERE 1=1{$dfCmp}) total_campaigns,
        ((SELECT COUNT(*) FROM emails) + (SELECT COUNT(*) FROM inbound_emails) + (SELECT COUNT(*) FROM autoreply_threads) + (SELECT COUNT(*) FROM followup_contacts)) total_emails,
        (SELECT COUNT(DISTINCT email) FROM (SELECT e.email FROM emails e LEFT JOIN email_lists l ON l.id = e.list_id WHERE (DATE(e.created_at) = CURDATE() OR (e.created_at IS NULL AND l.id IS NOT NULL AND DATE(l.created_at) = CURDATE())) UNION SELECT t.from_email AS email FROM autoreply_threads t WHERE DATE(t.created_at) = CURDATE() UNION SELECT c.email FROM followup_contacts c WHERE DATE(c.created_at) = CURDATE() UNION SELECT i.from_email AS email FROM inbound_emails i WHERE DATE(i.received_at) = CURDATE()) _tl) today_leads,
        (SELECT COUNT(DISTINCT email) FROM (SELECT e.email FROM emails e LEFT JOIN email_lists l ON l.id = e.list_id WHERE ((YEAR(e.created_at) = YEAR(CURDATE()) AND MONTH(e.created_at) = MONTH(CURDATE())) OR (e.created_at IS NULL AND l.id IS NOT NULL AND YEAR(l.created_at) = YEAR(CURDATE()) AND MONTH(l.created_at) = MONTH(CURDATE()))) UNION SELECT t.from_email AS email FROM autoreply_threads t WHERE YEAR(t.created_at) = YEAR(CURDATE()) AND MONTH(t.created_at) = MONTH(CURDATE()) UNION SELECT c.email FROM followup_contacts c WHERE YEAR(c.created_at) = YEAR(CURDATE()) AND MONTH(c.created_at) = MONTH(CURDATE()) UNION SELECT i.from_email AS email FROM inbound_emails i WHERE YEAR(i.received_at) = YEAR(CURDATE()) AND MONTH(i.received_at) = MONTH(CURDATE())) _ml) month_leads,
        (SELECT COUNT(*) FROM smtp_providers) total_smtps,
        (SELECT COUNT(*) FROM users WHERE is_admin=0) total_users,
        (SELECT COUNT(*) FROM campaigns WHERE status IN ('scheduled','running')) total_pending,
        ".(
            $rng['active']
                ? "(SELECT COUNT(*) FROM inbound_emails WHERE 1=1{$dfInb}) total_imap_read"
                : "(SELECT IFNULL(SUM(emails_read),0) FROM imap_accounts) total_imap_read"
        ).",
        (SELECT COUNT(*) FROM followup_contacts WHERE status='active' AND next_send_at IS NOT NULL) total_followup_pending,
        (SELECT COUNT(*) FROM autoreply_threads WHERE status='active' AND next_send_at IS NOT NULL AND COALESCE(awaiting_reply,0)!=1) total_reply_pending,
        (SELECT COUNT(*) FROM send_logs WHERE log_source='autoreply' AND status='sent'  {$dfSL}) total_ar_sent,
        (SELECT COUNT(*) FROM send_logs WHERE log_source='autoreply' AND status='failed'{$dfSL}) total_ar_failed,
        (SELECT COUNT(*) FROM send_logs WHERE log_source='followup'  AND status='sent'  {$dfSL}) total_fu_sent,
        (SELECT COUNT(*) FROM send_logs WHERE log_source='followup'  AND status='failed'{$dfSL}) total_fu_failed,
        (SELECT COUNT(*) FROM autoreply_threads  WHERE status='completed'".($rng['active']?" AND created_at BETWEEN '{$rng['from']}' AND '{$rng['to']}'":"").") total_ar_completed,
        (SELECT COUNT(*) FROM followup_contacts  WHERE status='completed') total_fu_completed,
        ".(
            $rng['active']
                ? "(SELECT COUNT(*) FROM inbound_emails i WHERE EXISTS (SELECT 1 FROM autoreply_threads t WHERE LOWER(t.from_email)=LOWER(i.from_email)){$dfInb}) total_ar_read"
                : "(SELECT IFNULL(SUM(messages_received),0) FROM autoreply_threads) total_ar_read"
        ).",
        ".(
            $rng['active']
                ? "(SELECT COUNT(DISTINCT email) FROM followup_contacts WHERE created_at BETWEEN '{$rng['from']}' AND '{$rng['to']}') total_fu_read"
                : "(SELECT COUNT(DISTINCT email) FROM followup_contacts) total_fu_read"
        );

    echo "Executing SQL...\n";
    $start = microtime(true);
    $res = $db->query($sql)->fetch();
    $elapsed = round((microtime(true) - $start) * 1000, 2);
    echo "Query finished in {$elapsed} ms\n";
    print_r($res);
} catch (Exception $e) {
    echo "SQL EXCEPTION CAUGHT: " . $e->getMessage() . "\n";
}
