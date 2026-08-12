<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/mailer.php';

$cfg = [
    'host' => '127.0.0.1',
    'port' => 2525, // dummy
    'secure' => false,
    'from_email' => 'test@example.com',
    'from_name' => 'Test'
];

$inlineImages = [['cid' => 'img123', 'path' => __DIR__ . '/api.php', 'mime' => 'text/plain']];
$inlineImages = array_values(array_filter($inlineImages,
    fn($i) => !empty($i['path']) && file_exists($i['path']) && is_readable($i['path'])
));
echo count($inlineImages) . " images found.\n";
