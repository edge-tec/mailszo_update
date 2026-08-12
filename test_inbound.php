<?php
require 'includes/config.php';
header('Content-Type: application/json');

$q = $pdo->query("SELECT 
    (SELECT COUNT(*) FROM inbound_emails) as total_inbound,
    (SELECT COUNT(DISTINCT from_email) FROM inbound_emails) as distinct_inbound,
    (SELECT COUNT(*) FROM inbound_emails WHERE DATE(received_at) = CURDATE()) as today_inbound,
    (SELECT COUNT(DISTINCT from_email) FROM inbound_emails WHERE DATE(received_at) = CURDATE()) as today_distinct_inbound
");
echo json_encode($q->fetch(PDO::FETCH_ASSOC));
