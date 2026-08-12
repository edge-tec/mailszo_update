<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/mailer.php';
// We will instantiate Mailer and override its cmd/read methods to just print to console.
class MockMailer extends Mailer {
    public $log = "";
    protected function connect() { return fopen('php://memory', 'rw'); }
    private function cmd($sock, $c) { $this->log .= $c . "\n"; }
    private function read($sock): string { return "250 OK\n"; }
    public function getLog() { return $this->log; }
    // override send to capture output
    public function send($to, $toName, $subject, $html, $text = '', $inlineImages = []) {
        $sock = $this->connect();
        $this->cfg['from_email'] = 'test@example.com';
        
        $msg = "TESTING\n";
        // ... we can't easily override send without rewriting it. Let's just use reflection or read the generated email body.
    }
}
$m = new Mailer(['host'=>'127.0.0.1','port'=>25,'secure'=>false,'from_email'=>'test@test.com']);
// Just call send to a mock socket? It will fail on connect.
