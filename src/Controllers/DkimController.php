<?php
namespace Mailpro\Controllers;

use Mailpro\Kernel\Request;
use Mailpro\Kernel\Response;
use DkimSigner;

require_once __DIR__ . '/../../includes/dkim.php';

/**
 * DKIM Key Management Modular Controller
 */
class DkimController extends BaseController
{
    public function handle(Request $request): Response
    {
        $method = $request->getMethod();
        $id     = $request->getId();
        $uid    = $request->getUserId();
        $isAdmin= $request->isAdmin();

        // 1. LIST DKIM KEYS
        if ($method === 'GET' && ($id === null || $id === 'list')) {
            $where = $isAdmin ? '1=1' : "user_id = {$uid}";
            $stmt = $this->db()->query("SELECT id, user_id, domain, selector, public_key, dns_record, dns_verified, last_verified_at, is_active, created_at, updated_at FROM dkim_keys WHERE {$where} ORDER BY id DESC");
            $keys = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($keys as &$k) {
                $dnsInfo = DkimSigner::getDnsRecordInfo($k['domain'], $k['selector'], $k['public_key']);
                $k['dns_host'] = $dnsInfo['host'];
                $k['dns_value'] = $dnsInfo['value'];
            }
            return $this->json(['ok' => true, 'keys' => $keys]);
        }

        // 2. GENERATE NEW 2048-BIT KEYPAIR
        if ($method === 'POST' && $id === 'generate') {
            $domain = strtolower(trim((string)$request->input('domain', '')));
            $selector = trim((string)$request->input('selector', 'mailpro')) ?: 'mailpro';
            if (!$domain) return $this->error('Domain is required');

            try {
                $kp = DkimSigner::generateKeyPair(2048);
                $dnsInfo = DkimSigner::getDnsRecordInfo($domain, $selector, $kp['public_key']);
                return $this->json([
                    'ok'          => true,
                    'domain'      => $domain,
                    'selector'    => $selector,
                    'private_key' => $kp['private_key'],
                    'public_key'  => $kp['public_key'],
                    'dns_host'    => $dnsInfo['host'],
                    'dns_value'   => $dnsInfo['value']
                ]);
            } catch (\Throwable $e) {
                return $this->error($e->getMessage(), 500);
            }
        }

        // 3. SAVE / UPSERT DKIM KEY
        if ($method === 'POST' && ($id === 'save' || $id === null)) {
            $domain = strtolower(trim((string)$request->input('domain', '')));
            $selector = trim((string)$request->input('selector', 'mailpro')) ?: 'mailpro';
            $privateKey = trim((string)$request->input('private_key', ''));
            $publicKey = trim((string)$request->input('public_key', ''));
            $isActive = $request->input('is_active', 1) ? 1 : 0;

            if (!$domain || !$privateKey) {
                return $this->error('Domain and Private Key are required');
            }

            if (!$publicKey) {
                $pkRes = openssl_pkey_get_private($privateKey);
                if ($pkRes) {
                    $details = openssl_pkey_get_details($pkRes);
                    $publicKey = $details['key'] ?? '';
                }
            }
            if (!$publicKey) {
                return $this->error('Invalid private key: could not derive public key');
            }

            $dnsInfo = DkimSigner::getDnsRecordInfo($domain, $selector, $publicKey);

            try {
                $stmt = $this->db()->prepare(
                    "INSERT INTO dkim_keys (user_id, domain, selector, private_key, public_key, dns_record, is_active, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE
                        private_key = VALUES(private_key),
                        public_key  = VALUES(public_key),
                        dns_record  = VALUES(dns_record),
                        is_active   = VALUES(is_active),
                        updated_at  = NOW()"
                );
                $stmt->execute([$uid, $domain, $selector, $privateKey, $publicKey, $dnsInfo['value'], $isActive]);
                return $this->json(['ok' => true, 'message' => 'DKIM Key saved successfully', 'dns_host' => $dnsInfo['host'], 'dns_value' => $dnsInfo['value']]);
            } catch (\Throwable $e) {
                return $this->error($e->getMessage(), 500);
            }
        }

        // 4. LIVE DNS VERIFY
        if (($method === 'GET' || $method === 'POST') && $id === 'verify') {
            $keyId = (int)($request->input('id') ?? $request->getQuery('id', 0));
            $domain = strtolower(trim((string)($request->input('domain') ?? $request->getQuery('domain', ''))));
            $selector = trim((string)($request->input('selector') ?? $request->getQuery('selector', 'mailpro'))) ?: 'mailpro';
            $pubKey = null;

            if ($keyId > 0) {
                $s = $this->db()->prepare("SELECT * FROM dkim_keys WHERE id = ?" . ($isAdmin ? "" : " AND user_id = {$uid}"));
                $s->execute([$keyId]);
                $row = $s->fetch(\PDO::FETCH_ASSOC);
                if ($row) {
                    $domain = $row['domain'];
                    $selector = $row['selector'];
                    $pubKey = $row['public_key'];
                }
            }

            if (!$domain) return $this->error('Domain is required');

            $res = DkimSigner::verifyDnsRecord($domain, $selector, $pubKey);

            if ($keyId > 0 && $res['verified']) {
                try {
                    $this->db()->prepare("UPDATE dkim_keys SET dns_verified = 1, last_verified_at = NOW() WHERE id = ?")->execute([$keyId]);
                } catch (\Throwable $e) {}
            }

            return $this->json([
                'ok'       => true,
                'verified' => $res['verified'],
                'message'  => $res['message'],
                'host'     => $selector . '._domainkey.' . $domain,
                'record'   => $res['found']
            ]);
        }

        // 5. DELETE DKIM KEY
        if ($method === 'DELETE' || ($method === 'POST' && $id === 'delete')) {
            $keyId = (int)($request->input('id') ?? ($id !== 'delete' ? $id : 0));
            if ($keyId <= 0) return $this->error('Invalid ID');

            $sql = "DELETE FROM dkim_keys WHERE id = ?" . ($isAdmin ? "" : " AND user_id = {$uid}");
            $this->db()->prepare($sql)->execute([$keyId]);
            return $this->success('DKIM Key deleted');
        }

        // 6. TOGGLE ACTIVE
        if ($method === 'POST' && $id === 'toggle') {
            $keyId = (int)$request->input('id', 0);
            $sql = "UPDATE dkim_keys SET is_active = NOT is_active WHERE id = ?" . ($isAdmin ? "" : " AND user_id = {$uid}");
            $this->db()->prepare($sql)->execute([$keyId]);
            return $this->success('Status updated');
        }

        return $this->notFound();
    }
}
