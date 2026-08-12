<?php
require_once 'includes/config.php';
$stmt = db()->query("SELECT id, status, last_sent_at, next_send_at, current_step, from_email FROM autoreply_threads ORDER BY id DESC LIMIT 10");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
