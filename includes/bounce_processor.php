<?php
/**
 * Mailpro Enterprise - Automated Bounce Processing & VERP Engine
 * Compliant with RFC 3464 (Delivery Status Notifications) & RFC 3463 (Enhanced Mail System Status Codes)
 *
 * Implements:
 *  - VERP (Variable Envelope Return Path) generation and decoding
 *  - DSN / NDR MIME parsing and diagnostic extraction
 *  - Hard Bounce (5.x.x) vs Soft Bounce (4.x.x) Classification
 *  - Automated Suppression & Blacklisting
 *  - Soft Bounce Threshold Management (Auto-escalation to Hard Suppression)
 *  - IMAP Bounce Mailbox Polling
 */

class BounceProcessor {

    protected static string $defaultSecret = 'mailpro_verp_salt_2026';

    /**
     * Get security salt for VERP hashing
     */
    protected static function getSecret(): string {
        if (function_exists('getConfig')) {
            $cfg = getConfig();
            if (!empty($cfg['cron_key'])) return $cfg['cron_key'];
        }
        return self::$defaultSecret;
    }

    /**
     * Generate a Variable Envelope Return Path (VERP) address.
     * Format: bounce+{encoded_payload}_{hmac}@{bounce_domain}
     *
     * @param string $bounceDomain The return-path domain (e.g. bounce.yourdomain.com)
     * @param int $userId User ID
     * @param int|null $campaignId Campaign ID
     * @param int|null $leadId Lead/Email ID
     * @param string $recipientEmail Recipient email address
     * @return string VERP email address
     */
    public static function generateVerpAddress(
        string $bounceDomain,
        int $userId,
        ?int $campaignId,
        ?int $leadId,
        string $recipientEmail
    ): string {
        $cid = (int)($campaignId ?? 0);
        $lid = (int)($leadId ?? 0);
        $cleanEmail = strtolower(trim($recipientEmail));

        // Compact base64url encoded representation of recipient email
        $encEmail = rtrim(strtr(base64_encode($cleanEmail), '+/', '-_'), '=');
        $payload = "u{$userId}.c{$cid}.l{$lid}.{$encEmail}";

        // 8-character HMAC signature for tamper verification
        $hash = substr(hash_hmac('sha256', $payload, self::getSecret()), 0, 8);

        $domain = ltrim(trim($bounceDomain), '@');
        return "bounce+{$payload}.{$hash}@{$domain}";
    }

    /**
     * Decode and verify a VERP address.
     * Returns: ['valid' => bool, 'user_id' => int, 'campaign_id' => int, 'lead_id' => int, 'recipient_email' => string]
     */
    public static function decodeVerpAddress(string $address): ?array {
        $clean = trim($address, '<> ');
        if (!preg_match('/^bounce\+u(\d+)\.c(\d+)\.l(\d+)\.([a-zA-Z0-9\-_]+)\.([a-f0-9]{8})@/i', $clean, $m)) {
            return null;
        }

        $userId = (int)$m[1];
        $campaignId = (int)$m[2];
        $leadId = (int)$m[3];
        $encEmail = $m[4];
        $receivedHash = strtolower($m[5]);

        $payload = "u{$userId}.c{$campaignId}.l{$leadId}.{$encEmail}";
        $expectedHash = substr(hash_hmac('sha256', $payload, self::getSecret()), 0, 8);

        if (!hash_equals($expectedHash, $receivedHash)) {
            return null; // Tampered or invalid hash
        }

        // Restore original recipient email
        $b64 = strtr($encEmail, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad > 0) $b64 .= str_repeat('=', 4 - $pad);
        $recipientEmail = base64_decode($b64);

        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return [
            'valid'           => true,
            'user_id'         => $userId,
            'campaign_id'     => $campaignId ?: null,
            'lead_id'         => $leadId ?: null,
            'recipient_email' => strtolower($recipientEmail)
        ];
    }

    /**
     * Parse raw bounce email headers and body.
     * Returns structured bounce data or null if not a bounce.
     */
    public static function parseBounceMessage(string $rawHeaders, string $rawBody): ?array {
        $headersLower = strtolower($rawHeaders);

        // 1. Identify if this is a bounce/NDR message
        $isBounce = false;
        if (stripos($headersLower, 'report-type=delivery-status') !== false ||
            stripos($headersLower, 'multipart/report') !== false ||
            stripos($headersLower, 'delivery status notification') !== false ||
            stripos($headersLower, 'undelivered mail returned') !== false ||
            stripos($headersLower, 'mail delivery failed') !== false ||
            stripos($headersLower, 'failure notice') !== false ||
            stripos($headersLower, 'returned mail:') !== false ||
            stripos($headersLower, 'mail delivery subsystem') !== false) {
            $isBounce = true;
        }

        // Also check subject header
        $subject = '';
        if (preg_match('/^Subject:\s*(.*?)$/mi', $rawHeaders, $sm)) {
            $subject = trim($sm[1]);
            if (preg_match('/(undelivered|delivery status|failure notice|returned mail|bounce|failed delivery)/i', $subject)) {
                $isBounce = true;
            }
        }

        if (!$isBounce && stripos($rawBody, 'Final-Recipient:') === false) {
            return null;
        }

        // 2. Try to extract recipient from VERP address in Delivered-To, To, or Envelope-To
        $verpData = null;
        if (preg_match('/(Delivered-To|Envelope-To|To):\s*([^\r\n]+)/i', $rawHeaders, $tm)) {
            $candidateTo = trim($tm[2]);
            $verpData = self::decodeVerpAddress($candidateTo);
        }

        // Also search body for VERP address if not in headers
        if (!$verpData && preg_match('/bounce\+u\d+\.c\d+\.l\d+\.[a-zA-Z0-9\-_]+\.[a-f0-9]{8}@[a-zA-Z0-9\.\-]+/i', $rawBody, $bm)) {
            $verpData = self::decodeVerpAddress($bm[0]);
        }

        $recipientEmail = $verpData['recipient_email'] ?? '';
        $userId         = $verpData['user_id'] ?? 1;
        $campaignId     = $verpData['campaign_id'] ?? null;
        $leadId         = $verpData['lead_id'] ?? null;

        // 3. If no VERP address, extract recipient from RFC 3464 Final-Recipient / Original-Recipient
        if (!$recipientEmail) {
            if (preg_match('/(?:Final-Recipient|Original-Recipient):\s*(?:rfc822|RFC822)?;\s*([^\s\r\n<>]+)/i', $rawBody, $frm)) {
                $recipientEmail = trim($frm[1], '<> ');
            } elseif (preg_match('/To:\s*<([^>]+)>/i', $rawBody, $tbm)) {
                $recipientEmail = trim($tbm[1]);
            } elseif (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $rawBody, $em)) {
                // Fallback email regex in body
                $candidate = trim($em[0]);
                if (stripos($candidate, 'mailer-daemon') === false && stripos($candidate, 'postmaster') === false) {
                    $recipientEmail = $candidate;
                }
            }
        }

        if (!$recipientEmail || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $recipientEmail = strtolower($recipientEmail);

        // 4. Extract Enhanced Status Code (RFC 3463, e.g. 5.1.1, 4.2.2)
        $statusCode = '';
        if (preg_match('/Status:\s*([45]\.\d+\.\d+)/i', $rawBody, $stm)) {
            $statusCode = trim($stm[1]);
        } elseif (preg_match('/\b([45]\.\d+\.\d+)\b/', $rawBody, $stm2)) {
            $statusCode = trim($stm2[1]);
        } elseif (preg_match('/\b(550|554|552|551|553|450|451|452)\b/', $rawBody, $code3)) {
            // Map legacy 3-digit SMTP codes
            $c = (int)$code3[1];
            $statusCode = ($c >= 500) ? "5.0.0 ({$c})" : "4.0.0 ({$c})";
        } else {
            $statusCode = '5.0.0'; // Default to hard bounce if unspecified delivery failure
        }

        // 5. Extract Diagnostic-Code
        $diagnostic = '';
        if (preg_match('/Diagnostic-Code:\s*(?:smtp|SMTP)?;\s*([^\r\n]+(?:\r\n[ \t]+[^\r\n]+)*)/i', $rawBody, $dm)) {
            $diagnostic = trim(preg_replace('/\s+/', ' ', $dm[1]));
        } else {
            // Grab snippet of failure description
            if (preg_match('/(?:reason|failed|error|rejected):\s*([^\r\n]+)/i', $rawBody, $fm)) {
                $diagnostic = trim($fm[1]);
            } else {
                $diagnostic = 'Remote MTA indicated message delivery failure';
            }
        }
        $diagnostic = substr($diagnostic, 0, 500);

        // 6. Classify Bounce Type (Hard vs Soft)
        $bounceType = 'hard';
        if (str_starts_with($statusCode, '4.') ||
            stripos($diagnostic, 'mailbox full') !== false ||
            stripos($diagnostic, 'quota exceeded') !== false ||
            stripos($diagnostic, 'try again later') !== false ||
            stripos($diagnostic, 'greylisted') !== false ||
            stripos($diagnostic, 'temporarily deferred') !== false) {
            $bounceType = 'soft';
        }

        return [
            'is_bounce'        => true,
            'recipient_email'  => $recipientEmail,
            'user_id'          => $userId,
            'campaign_id'      => $campaignId,
            'lead_id'          => $leadId,
            'status_code'      => $statusCode,
            'bounce_type'      => $bounceType,
            'diagnostic'       => $diagnostic,
            'subject'          => $subject
        ];
    }

    /**
     * Process a parsed bounce: Auto-Blacklist, update contacts, log system event,
     * and handle soft-bounce thresholds.
     *
     * @param array $bounce Parsed bounce array from parseBounceMessage
     * @return array Results of action taken
     */
    public static function processBounce(array $bounce): array {
        if (!function_exists('db')) {
            throw new Exception("Database connection unavailable");
        }

        $pdo = db();
        $email      = strtolower(trim($bounce['recipient_email']));
        $userId     = (int)($bounce['user_id'] ?? 1);
        $campaignId = $bounce['campaign_id'] ?? null;
        $code       = $bounce['status_code'] ?? '5.0.0';
        $diagnostic = $bounce['diagnostic'] ?? 'Delivery failure';
        $type       = $bounce['bounce_type'] ?? 'hard';

        $actionTaken = 'none';

        if ($type === 'hard') {
            // ── HARD BOUNCE ACTION ───────────────────────────────────────
            // 1. Add to blacklist table
            try {
                $blReason = "Hard bounce [{$code}]: {$diagnostic}";
                $stmt = $pdo->prepare(
                    "INSERT INTO blacklist (user_id, type, email, reason, created_at)
                     VALUES (?, 'email', ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE reason = VALUES(reason)"
                );
                $stmt->execute([$userId, $email, substr($blReason, 0, 255)]);
            } catch (\Throwable $e) {
                error_log("[BounceProcessor] Error blacklisting: " . $e->getMessage());
            }

            // 2. Mark recipient in emails table
            try {
                $pdo->prepare("UPDATE emails SET status = 'bounced' WHERE LOWER(email) = ?")->execute([$email]);
            } catch (\Throwable $e) {}

            // 3. Mark recipient in followup_contacts table
            try {
                $pdo->prepare("UPDATE followup_contacts SET status = 'cancelled' WHERE LOWER(email) = ?")->execute([$email]);
            } catch (\Throwable $e) {}

            // 4. Update send_logs recent record to failed
            try {
                $pdo->prepare(
                    "UPDATE send_logs SET status = 'failed', error = ? 
                     WHERE LOWER(email) = ? AND sent_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) 
                     ORDER BY id DESC LIMIT 1"
                )->execute(["Hard bounce [{$code}]: {$diagnostic}", $email]);
            } catch (\Throwable $e) {}

            // 5. Log to system_logs
            if (function_exists('logSystemEvent')) {
                logSystemEvent('bounced', $email, "Hard bounce [{$code}]: {$diagnostic}", $userId, $campaignId);
            }

            $actionTaken = 'blacklisted_hard_bounce';

        } else {
            // ── SOFT BOUNCE ACTION ───────────────────────────────────────
            // Check soft bounce threshold in soft_bounces table (e.g. >= 3 is escalated)
            $count = 1;
            try {
                $stmt = $pdo->prepare(
                    "INSERT INTO soft_bounces (user_id, email, bounce_count, last_bounce_code, last_diagnostic, first_bounced_at, last_bounced_at)
                     VALUES (?, ?, 1, ?, ?, NOW(), NOW())
                     ON DUPLICATE KEY UPDATE 
                        bounce_count = bounce_count + 1,
                        last_bounce_code = VALUES(last_bounce_code),
                        last_diagnostic = VALUES(last_diagnostic),
                        last_bounced_at = NOW()"
                );
                $stmt->execute([$userId, $email, $code, $diagnostic]);

                // Retrieve updated count
                $cntStmt = $pdo->prepare("SELECT bounce_count FROM soft_bounces WHERE user_id = ? AND email = ?");
                $cntStmt->execute([$userId, $email]);
                $count = (int)$cntStmt->fetchColumn();
            } catch (\Throwable $e) {
                error_log("[BounceProcessor] Soft bounce counter error: " . $e->getMessage());
            }

            if ($count >= 3) {
                // Escalate to permanent blacklist
                try {
                    $blReason = "Soft bounce limit exceeded ({$count}x) [{$code}]: {$diagnostic}";
                    $stmt = $pdo->prepare(
                        "INSERT INTO blacklist (user_id, type, email, reason, created_at)
                         VALUES (?, 'email', ?, ?, NOW())
                         ON DUPLICATE KEY UPDATE reason = VALUES(reason)"
                    );
                    $stmt->execute([$userId, $email, substr($blReason, 0, 255)]);
                    $pdo->prepare("UPDATE emails SET status = 'bounced' WHERE LOWER(email) = ?")->execute([$email]);
                } catch (\Throwable $e) {}

                if (function_exists('logSystemEvent')) {
                    logSystemEvent('bounced', $email, "Soft bounce exceeded threshold ({$count}x) [{$code}]", $userId, $campaignId);
                }
                $actionTaken = 'escalated_to_blacklist';
            } else {
                if (function_exists('logSystemEvent')) {
                    logSystemEvent('retry', $email, "Soft bounce [{$code}]: {$diagnostic} (Strike {$count}/3)", $userId, $campaignId);
                }
                $actionTaken = "soft_bounce_strike_{$count}";
            }
        }

        return [
            'success'      => true,
            'email'        => $email,
            'bounce_type'  => $type,
            'status_code'  => $code,
            'action_taken' => $actionTaken
        ];
    }

    /**
     * Poll an IMAP bounce mailbox, parse unseen bounce messages, and execute processing.
     *
     * @param array $mailbox [host, port, secure, username, password, delete_after_processing, id, user_id]
     * @return array Statistics
     */
    public static function processImapBounceMailbox(array $mailbox): array {
        $stats = [
            'total_checked'  => 0,
            'processed'      => 0,
            'hard_bounces'   => 0,
            'soft_bounces'   => 0,
            'skipped'        => 0,
            'errors'         => []
        ];

        $host = $mailbox['host'];
        $port = (int)($mailbox['port'] ?? 993);
        $user = $mailbox['username'];
        $pass = $mailbox['password'];
        $secure = !empty($mailbox['secure']);
        $deleteAfter = !empty($mailbox['delete_after_processing']);

        $pre = $secure ? 'ssl://' : 'tcp://';
        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
                'peer_name'         => $host,
            ]
        ]);

        $sock = @stream_socket_client($pre . $host . ':' . $port, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) {
            $stats['errors'][] = "Connection failed to {$host}:{$port} - {$errstr}";
            return $stats;
        }

        stream_set_timeout($sock, 30);

        $tagIndex = 1;
        $cmd = function($c) use ($sock, &$tagIndex) {
            $tag = 'A' . sprintf('%04d', $tagIndex++);
            fwrite($sock, "{$tag} {$c}\r\n");
            $response = '';
            while (!feof($sock)) {
                $line = fgets($sock, 4096);
                if ($line === false) break;
                $response .= $line;
                if (str_starts_with($line, "{$tag} ")) break;
            }
            return [$tag, $response];
        };

        // Read server greeting
        fgets($sock, 4096);

        // Login
        list($t, $resp) = $cmd("LOGIN " . escapeshellarg($user) . " " . escapeshellarg($pass));
        if (stripos($resp, "{$t} OK") === false) {
            $stats['errors'][] = "Authentication failed for {$user}";
            fclose($sock);
            return $stats;
        }

        // Select INBOX
        list($t, $resp) = $cmd("SELECT INBOX");
        if (stripos($resp, "{$t} OK") === false) {
            $stats['errors'][] = "Failed to select INBOX";
            $cmd("LOGOUT");
            fclose($sock);
            return $stats;
        }

        // Search UNSEEN messages
        list($t, $searchResp) = $cmd("SEARCH UNSEEN");
        $uids = [];
        if (preg_match('/\* SEARCH\s+([0-9 ]+)/i', $searchResp, $sm)) {
            $uids = array_filter(explode(' ', trim($sm[1])));
        }

        $stats['total_checked'] = count($uids);

        // Process up to 50 bounces per run to avoid memory/time limits
        $toProcess = array_slice($uids, 0, 50);

        foreach ($toProcess as $msgSeq) {
            // Fetch RFC822 message content
            list($ft, $fResp) = $cmd("FETCH {$msgSeq} RFC822");
            
            // Extract raw body from FETCH response
            $rawMsg = '';
            if (preg_match('/\{(\d+)\}\r\n(.*)/s', $fResp, $mm)) {
                $expectedBytes = (int)$mm[1];
                $rawMsg = substr($mm[2], 0, $expectedBytes);
            } else {
                $rawMsg = $fResp;
            }

            // Split headers and body
            $parts = preg_split("/\r?\n\r?\n/", $rawMsg, 2);
            $rawHeaders = $parts[0] ?? '';
            $rawBody = $parts[1] ?? '';

            $parsed = self::parseBounceMessage($rawHeaders, $rawBody);
            if ($parsed && !empty($parsed['recipient_email'])) {
                // Attach mailbox user_id if not extracted from VERP
                if (empty($parsed['user_id']) && !empty($mailbox['user_id'])) {
                    $parsed['user_id'] = $mailbox['user_id'];
                }

                $res = self::processBounce($parsed);
                $stats['processed']++;
                if ($parsed['bounce_type'] === 'hard') {
                    $stats['hard_bounces']++;
                } else {
                    $stats['soft_bounces']++;
                }

                // Delete or mark seen
                if ($deleteAfter) {
                    $cmd("STORE {$msgSeq} +FLAGS (\\Deleted)");
                } else {
                    $cmd("STORE {$msgSeq} +FLAGS (\\Seen)");
                }
            } else {
                $stats['skipped']++;
                $cmd("STORE {$msgSeq} +FLAGS (\\Seen)");
            }
        }

        if ($deleteAfter) {
            $cmd("EXPUNGE");
        }

        $cmd("LOGOUT");
        fclose($sock);

        // Update last_polled_at in database if mailbox has ID
        if (!empty($mailbox['id']) && function_exists('db')) {
            try {
                db()->prepare("UPDATE bounce_mailboxes SET last_polled_at = NOW() WHERE id = ?")->execute([$mailbox['id']]);
            } catch (\Throwable $e) {}
        }

        return $stats;
    }
}
