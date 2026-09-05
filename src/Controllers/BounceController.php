<?php
namespace Mailpro\Controllers;

use Mailpro\Kernel\Request;
use Mailpro\Kernel\Response;
use BounceProcessor;

require_once __DIR__ . '/../../includes/bounce_processor.php';

/**
 * Bounce & Suppression Intelligence Modular Controller
 */
class BounceController extends BaseController
{
    public function handle(Request $request): Response
    {
        $method = $request->getMethod();
        $id     = $request->getId();
        $action = $request->getAction();
        $uid    = $request->getUserId();
        $isAdmin= $request->isAdmin();

        // 1. BOUNCE STATS & REPORT
        if ($method === 'GET' && ($id === null || $id === 'stats')) {
            $whereUser = $isAdmin ? "1=1" : "user_id = {$uid}";
            $hardCount = 0;
            $softCount = 0;
            try {
                $hardCount = (int)$this->db()->query("SELECT COUNT(*) FROM blacklist WHERE {$whereUser} AND reason LIKE '%bounce%'")->fetchColumn();
                $softCount = (int)$this->db()->query("SELECT COUNT(*) FROM soft_bounces WHERE {$whereUser}")->fetchColumn();
            } catch (\Throwable $e) {}

            $recent = [];
            try {
                $s = $this->db()->prepare(
                    "SELECT recipient_email, details, created_at 
                     FROM system_logs 
                     WHERE {$whereUser} AND event_type = 'bounced' 
                     ORDER BY id DESC LIMIT 50"
                );
                $s->execute();
                $recent = $s->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {}

            return $this->json([
                'ok'           => true,
                'hard_bounces' => $hardCount,
                'soft_bounces' => $softCount,
                'recent'       => $recent
            ]);
        }

        // 2. BOUNCE MAILBOXES (CRUD)
        if ($id === 'mailboxes') {
            if ($method === 'GET') {
                $where = $isAdmin ? "1=1" : "user_id = {$uid}";
                $stmt = $this->db()->query("SELECT id, user_id, name, host, port, secure, username, delete_after_processing, is_active, last_polled_at, created_at FROM bounce_mailboxes WHERE {$where} ORDER BY id DESC");
                return $this->json(['ok' => true, 'mailboxes' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
            }

            if ($method === 'POST') {
                $name = trim((string)$request->input('name', ''));
                $host = trim((string)$request->input('host', ''));
                $port = (int)$request->input('port', 993);
                $secure = $request->input('secure') ? 1 : 0;
                $username = trim((string)$request->input('username', ''));
                $password = trim((string)$request->input('password', ''));
                $deleteAfter = $request->input('delete_after_processing') ? 1 : 0;
                $boxId = (int)$request->input('id', 0);

                if (!$name || !$host || !$username) {
                    return $this->error('Name, Host, and Username are required');
                }

                if ($boxId > 0) {
                    $sql = "UPDATE bounce_mailboxes SET name=?, host=?, port=?, secure=?, username=?, delete_after_processing=? " . ($password ? ", password=?" : "") . " WHERE id=? " . ($isAdmin ? "" : "AND user_id={$uid}");
                    $params = [$name, $host, $port, $secure, $username, $deleteAfter];
                    if ($password) $params[] = $password;
                    $params[] = $boxId;
                    $this->db()->prepare($sql)->execute($params);
                } else {
                    if (!$password) return $this->error('Password is required for new mailbox');
                    $stmt = $this->db()->prepare("INSERT INTO bounce_mailboxes (user_id, name, host, port, secure, username, password, delete_after_processing) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$uid, $name, $host, $port, $secure, $username, $password, $deleteAfter]);
                }
                return $this->success('Bounce mailbox saved');
            }

            if ($method === 'DELETE' && $action !== null) {
                $delId = (int)$action;
                $sql = "DELETE FROM bounce_mailboxes WHERE id = ?" . ($isAdmin ? "" : " AND user_id = {$uid}");
                $this->db()->prepare($sql)->execute([$delId]);
                return $this->success('Bounce mailbox removed');
            }
        }

        // 3. TRIGGER BOUNCE PROCESSOR RUN
        if ($method === 'POST' && $id === 'process') {
            $where = $isAdmin ? "is_active = 1" : "is_active = 1 AND user_id = {$uid}";
            $mailboxes = $this->db()->query("SELECT * FROM bounce_mailboxes WHERE {$where}")->fetchAll(\PDO::FETCH_ASSOC);

            $totalResults = ['processed' => 0, 'hard_bounces' => 0, 'soft_bounces' => 0, 'skipped' => 0, 'errors' => []];
            foreach ($mailboxes as $mb) {
                $res = BounceProcessor::processImapBounceMailbox($mb);
                $totalResults['processed']    += $res['processed'];
                $totalResults['hard_bounces'] += $res['hard_bounces'];
                $totalResults['soft_bounces'] += $res['soft_bounces'];
                $totalResults['skipped']      += $res['skipped'];
                if (!empty($res['errors'])) {
                    $totalResults['errors'] = array_merge($totalResults['errors'], $res['errors']);
                }
            }

            return $this->json(['ok' => true, 'results' => $totalResults]);
        }

        // 4. TEST PARSE RAW BOUNCE
        if ($method === 'POST' && $id === 'test-parse') {
            $raw = trim((string)$request->input('raw_email', ''));
            if (!$raw) return $this->error('raw_email string required');

            $parts = preg_split("/\r?\n\r?\n/", $raw, 2);
            $headers = $parts[0] ?? '';
            $bodyPart = $parts[1] ?? '';

            $parsed = BounceProcessor::parseBounceMessage($headers, $bodyPart);
            if (!$parsed) {
                return $this->json(['ok' => false, 'message' => 'Message could not be parsed as a bounce or no recipient found']);
            }

            return $this->json(['ok' => true, 'parsed' => $parsed]);
        }

        return $this->notFound();
    }
}
