<?php
require_once 'includes/config.php';
require_once 'includes/imap.php';
$res = imapTestSocket('imap.gmail.com', 993, 'jhonmax248226@gmail.com', 'dummy_pass', true, 5);
print_r($res);
