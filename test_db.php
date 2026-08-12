<?php
$db = new PDO('sqlite::memory:', '', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE emails (id INTEGER PRIMARY KEY, list_id INTEGER, email TEXT UNIQUE, name TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
try {
    $db->prepare('INSERT INTO emails (list_id,email,name) VALUES (?,?,?) ON DUPLICATE KEY UPDATE list_id=VALUES(list_id), name=VALUES(name), created_at=CURRENT_TIMESTAMP')->execute([1, 'test@example.com', 'Test']);
    echo "Success!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
