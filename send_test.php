<?php
require_once __DIR__ . '/includes/mailer.php';
$cfg = [
    'host' => '127.0.0.1',
    'port' => 1025,
    'secure' => false,
    'username' => '',
    'password' => '',
    'from_email' => 'test@example.com',
    'from_name' => 'Sender Name'
];
$mailer = new Mailer($cfg);
// create a dummy image
file_put_contents('dummy.jpg', 'fake image content');
$inlineImages = [
    ['cid' => 'img123', 'path' => __DIR__ . '/dummy.jpg', 'mime' => 'image/jpeg']
];
$mailer->send('recipient@example.com', 'Recipient Name', 'Test Subject', '<h1>Hello</h1><img src="cid:img123">', 'Hello text', $inlineImages);
