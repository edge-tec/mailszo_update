<?php
namespace Mailpro\Controllers;

use Mailpro\Kernel\Request;
use Mailpro\Kernel\Response;

/**
 * IMAP IDLE Real-Time Push Status Modular Controller
 */
class IdleController extends BaseController
{
    public function handle(Request $request): Response
    {
        $method = $request->getMethod();
        $id     = $request->getId();
        $uid    = $request->getUserId();
        $isAdmin= $request->isAdmin();

        if ($method === 'GET' && ($id === null || $id === 'status')) {
            $where = $isAdmin ? "1=1" : "user_id = {$uid}";
            $accounts = $this->db()->query("SELECT id, user_id, name, host, port, secure, username, last_check, last_uid FROM imap_accounts WHERE {$where}")->fetchAll(\PDO::FETCH_ASSOC);
            return $this->json([
                'ok'             => true,
                'idle_supported' => true,
                'total_accounts' => count($accounts),
                'accounts'       => $accounts
            ]);
        }

        return $this->notFound();
    }
}
