<?php
require_once 'includes/config.php';
$pdo = db();
$stmt = $pdo->query("SELECT id, status, last_sent_at, next_send_at, current_step, from_email FROM autoreply_threads ORDER BY id DESC LIMIT 5");
echo "Threads:\n";
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
$stmt = $pdo->query("SELECT * FROM send_logs ORDER BY id DESC LIMIT 5");
echo "\nSend Logs:\n";
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
