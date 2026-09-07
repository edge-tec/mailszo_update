<?php
class Mailer {
    private $cfg;
    public function __construct($cfg) { $this->cfg = $cfg; }

    private function connect() {
        $host = $this->cfg['host'];
        $port = (int)$this->cfg['port'];

        // FIX: Manually resolve to IPv4. Many servers have broken IPv6 routes. 
        // stream_socket_client will try IPv6 and timeout. gethostbyname forces IPv4.
        $ip = gethostbyname($host);

        // FIX: Use stream_socket_client with a proper SSL context so STARTTLS
        // upgrades work reliably and SSL port 465 connects without errors.
        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
                'peer_name'         => $host, // Send SNI using the original hostname
            ]
        ]);

        $pre  = $this->cfg['secure'] ? 'ssl://' : 'tcp://';
        $sock = @stream_socket_client($pre.$ip.':'.$port, $errno, $errstr, 20,
                    STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) {
            $detail = $errstr ? trim($errstr) : 'Connection refused or timed out';
            throw new Exception("Cannot connect to {$host}:{$port} — {$detail}");
        }

        // Set 60-second read timeout to prevent premature drops on busy mail servers
        stream_set_timeout($sock, 60);

        // FIX: Use the domain part of from_email as the EHLO hostname.
        // Many receiving servers (especially Exim on cPanel/Namecheap shared hosting)
        // perform a "sender callout verification" — they connect back to the EHLO
        // domain to verify the MAIL FROM address exists.  If EHLO sends a bare
        // server hostname that doesn't match the sender domain, Exim rejects with:
        //   "Sender verify failed — No Such User Here"
        // Using the from_email domain makes the EHLO consistent with MAIL FROM,
        // which satisfies Exim's callout check and SPF alignment simultaneously.
        $fromEmail  = $this->cfg['from_email'] ?? '';
        $fromDomain = '';
        if ($fromEmail && strpos($fromEmail, '@') !== false) {
            $fromDomain = strtolower(trim(explode('@', $fromEmail)[1]));
        }
        // Prefer from_email domain → server hostname → SMTP host as last resort
        $ehloHost = $fromDomain ?: (gethostname() ?: $host);
        if (!$ehloHost || $ehloHost === 'localhost') $ehloHost = $fromDomain ?: $host;

        $this->read($sock);                           // server greeting
        $this->cmd($sock, "EHLO {$ehloHost}");
        $resp = $this->read($sock);

        // STARTTLS upgrade (plain TCP connections only)
        if (!$this->cfg['secure'] && stripos($resp, 'STARTTLS') !== false) {
            $this->cmd($sock, 'STARTTLS');
            $this->read($sock);
            // Broad TLS version negotiation (TLS 1.3, 1.2, 1.1, 1.0) with fallback
            $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) $crypto |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT')) $crypto |= STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT')) $crypto |= STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT;

            if (!@stream_socket_enable_crypto($sock, true, $crypto)) {
                $anyCrypto = defined('STREAM_CRYPTO_METHOD_ANY_CLIENT') ? STREAM_CRYPTO_METHOD_ANY_CLIENT : STREAM_CRYPTO_METHOD_TLS_CLIENT;
                if (!@stream_socket_enable_crypto($sock, true, $anyCrypto)) {
                    throw new Exception("STARTTLS handshake failed — try SSL port 465 instead");
                }
            }
            $this->cmd($sock, "EHLO {$ehloHost}");
            $this->read($sock);
        }

        // AUTH LOGIN (only when credentials are configured)
        if (!empty($this->cfg['username'])) {
            $this->cmd($sock, 'AUTH LOGIN');
            $r1 = $this->read($sock);
            // FIX: Validate server sent 334 (challenge) before sending credentials.
            // Previously credentials were sent even if the server returned an error.
            if (strpos($r1, '334') === false) {
                $clean = trim(preg_replace('/^\d+[\s-]*/m', '', $r1));
                throw new Exception("AUTH LOGIN rejected by server: {$clean}");
            }
            $this->cmd($sock, base64_encode($this->cfg['username']));
            $r2 = $this->read($sock);
            // FIX: Validate server accepted the username before sending password.
            if (strpos($r2, '334') === false) {
                throw new Exception("AUTH: server did not accept username (got: " . trim($r2) . ")");
            }
            $this->cmd($sock, base64_encode($this->cfg['password']));
            $r3 = $this->read($sock);
            if (strpos($r3, '235') === false) {
                $clean = trim(preg_replace('/^\d+[\s-]*/m', '', $r3));
                throw new Exception("Authentication failed — {$clean}");
            }
        }
        return $sock;
    }

    private function cmd($sock, $c) {
        if (@fwrite($sock, $c . "\r\n") === false) {
            throw new Exception("Failed to write to SMTP socket — connection dropped");
        }
    }

    /**
     * Read one complete SMTP response (handles multi-line responses).
     *
     * FIXES applied:
     * - Buffer raised from 512 → 4096 bytes. Exchange and some other servers
     *   send banners >512 bytes. The old buffer split lines mid-stream, causing
     *   the break condition to never match → indefinite hang → timeout.
     * - Added timeout detection: fgets() returning false due to a socket timeout
     *   now throws a clear exception instead of silently returning '' which made
     *   all subsequent strpos() checks fail with misleading error messages.
     * - Break condition changed from isset($l[3])&&$l[3]===' ' to a length-safe
     *   check: SMTP final-line marker is code + ' ' (space, not dash).
     */
    private function read($sock): string {
        $response = '';
        while (true) {
            $line = @fgets($sock, 4096);
            if ($line === false) {
                $info = @stream_get_meta_data($sock);
                if (!empty($info['timed_out'])) {
                    throw new Exception("SMTP read timeout — server did not respond within 30s");
                }
                break; // EOF / connection closed
            }
            $response .= $line;
            // Final line of an SMTP response: "NNN " (space after code, not dash)
            if (strlen($line) >= 4 && $line[3] === ' ') break;
            // Handle bare 3-char responses (no trailing space/CRLF) — extremely rare
            $bare = rtrim($line, "\r\n");
            if (strlen($bare) === 3 && ctype_digit($bare)) break;
        }
        return $response;
    }

    public function verify() {
        $s = $this->connect();
        $this->cmd($s, 'QUIT');
        @fclose($s);
        return true;
    }

    /**
     * Send an email. Supports embedded inline images via CID references.
     * $inlineImages = [['cid'=>'img1','path'=>'/full/path.jpg','mime'=>'image/jpeg'], ...]
     */
    public function send($to, $toName, $subject, $html, $text = '', $inlineImages = [], array $options = []) {
        $userId = (int)($options['user_id'] ?? ($this->cfg['user_id'] ?? 1));

        // ── DELIVERABILITY GATEKEEPER (Pre-send suppression check) ───────
        if (!isset($options['check_blacklist']) || !empty($options['check_blacklist'])) {
            if (function_exists('isBlacklisted') && isBlacklisted($to, $userId)) {
                throw new Exception("Recipient <{$to}> is blacklisted or suppressed (Hard Bounce or Opt-out). Sending aborted to protect domain reputation.");
            }
        }

        $from = $this->cfg['from_email'];

        // Normalise from address — strip any display-name wrapping if present
        if (preg_match('/<([^>]+)>/', $from, $m)) $from = trim($m[1]);
        $from = trim($from);

        // ── VERP ENVELOPE SENDER RESOLUTION ───────────────────────────────
        $bounceDomain = $options['bounce_domain'] ?? ($this->cfg['bounce_domain'] ?? '');
        $envelopeSender = $from;
        if (!empty($bounceDomain)) {
            if (!class_exists('BounceProcessor')) {
                require_once __DIR__ . '/bounce_processor.php';
            }
            $envelopeSender = BounceProcessor::generateVerpAddress(
                $bounceDomain,
                $userId,
                $options['campaign_id'] ?? null,
                $options['lead_id'] ?? null,
                $to
            );
        } elseif (!empty($options['return_path'])) {
            $envelopeSender = trim($options['return_path']);
        }

        $sock = $this->connect();

        // MAIL FROM — envelope sender (uses VERP when enabled, otherwise sender mailbox)
        $this->cmd($sock, "MAIL FROM:<{$envelopeSender}>");
        $mfr = $this->read($sock);
        if (strpos($mfr, '250') === false) {
            $clean = trim(preg_replace('/^\d+[\s-]*/m', '', $mfr));
            @fclose($sock);
            throw new Exception("Sender rejected: {$clean} — Ensure '{$envelopeSender}' is authorised to send via this SMTP.");
        }

        // RCPT TO
        $this->cmd($sock, "RCPT TO:<{$to}>");
        $rcpt = $this->read($sock);
        // FIX: Original check was strpos($r,'25') !== 0.
        // strpos returns false (not 0) when '25' is absent, and false!==0 is TRUE,
        // so an empty response (e.g. on timeout) threw a false "Recipient rejected".
        // Use regex for a reliable 2xx match at the start of any response line.
        if (!preg_match('/^2[0-9]{2}[\s\-]/m', $rcpt)) {
            $clean = trim(preg_replace('/^\d+[\s-]*/m', '', $rcpt));
            @fclose($sock);
            throw new Exception("Recipient <{$to}> rejected: {$clean}");
        }

        // DATA
        $this->cmd($sock, 'DATA');
        $dr = $this->read($sock);
        // FIX: Original code ignored the DATA response and wrote the message body
        // even if the server returned an error. Now we validate 354 before writing.
        if (strpos($dr, '354') === false) {
            $clean = trim(preg_replace('/^\d+[\s-]*/m', '', $dr));
            @fclose($sock);
            throw new Exception("DATA rejected: {$clean}");
        }

        $fn   = $this->cfg['from_name'] ?? '';
        $fd   = $fn ? "=?UTF-8?B?" . base64_encode($fn) . "?= <{$from}>" : "<{$from}>";
        $td   = $toName ? "=?UTF-8?B?" . base64_encode($toName) . "?= <{$to}>" : "<{$to}>";
        $sb   = "=?UTF-8?B?" . base64_encode($subject) . "?=";
        $fromDomain = explode('@', $from)[1] ?? 'mailpro';
        $mid  = '<' . md5(uniqid('', true)) . '@' . $fromDomain . '>';
        $date = date('r');

        // Tracking token & Open Pixel processing (FEATURE 1)
        $trackingToken = trim($options['tracking_token'] ?? '');
        $baseUrl = getAppBaseUrl();

        if ($html) {
            if (!function_exists('generateTrackingUuid')) {
                require_once __DIR__ . '/tracking_engine.php';
            }
            if (!$trackingToken) {
                $trackingToken = generateTrackingUuid();
            }

            // Ensure master record in email_tracking table
            try {
                if (function_exists('db')) {
                    $etIns = db()->prepare(
                        "INSERT INTO email_tracking (
                            tracking_token, user_id, email_log_id, campaign_id, rule_id, lead_id, smtp_account_id, sequence_step, recipient_email, sent_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE
                            user_id = COALESCE(user_id, VALUES(user_id)),
                            campaign_id = COALESCE(campaign_id, VALUES(campaign_id)),
                            rule_id = COALESCE(rule_id, VALUES(rule_id)),
                            lead_id = COALESCE(lead_id, VALUES(lead_id)),
                            smtp_account_id = COALESCE(smtp_account_id, VALUES(smtp_account_id)),
                            sequence_step = COALESCE(sequence_step, VALUES(sequence_step))"
                    );
                    $etIns->execute([
                        $trackingToken,
                        $options['user_id'] ?? null,
                        $options['email_log_id'] ?? null,
                        $options['campaign_id'] ?? null,
                        $options['rule_id'] ?? null,
                        $options['lead_id'] ?? null,
                        $this->cfg['id'] ?? ($options['smtp_account_id'] ?? null),
                        $options['sequence_step'] ?? null,
                        $to
                    ]);
                }
            } catch (\Throwable $e) {
                error_log("[Mailer Tracking] Warning creating master email_tracking: " . $e->getMessage());
            }

            // Clean baseUrl if any corrupted path remained
            if (strpos($baseUrl, '/www/wwwroot') !== false) {
                if (preg_match('#/(?:www/wwwroot|var/www/vhosts|var/www|vhosts)/([a-zA-Z0-9][-a-zA-Z0-9.]*\.[a-zA-Z]{2,})#i', $baseUrl, $bm)) {
                    $baseUrl = 'https://' . $bm[1];
                }
            }

            // Production Open Tracking Pixel: {{APP_URL}}/track/open/{{tracking_token}}.png
            $pixelUrl = rtrim($baseUrl, '/') . '/track/open/' . urlencode($trackingToken) . '.png';
            $pixelTag = '<img src="' . htmlspecialchars($pixelUrl, ENT_QUOTES, 'UTF-8') . '" width="1" height="1" style="display:none !important;width:1px;height:1px;border:0;outline:none" alt="" />';
            if (stripos($html, '</body>') !== false) {
                $html = preg_replace('/<\/body>/i', $pixelTag . "\n</body>", $html, 1);
            } else {
                $html .= "\n" . $pixelTag;
            }

            // Click Tracking rewrite if enabled
            if (!empty($options['track_clicks'])) {
                // Prevent rewriting links with invalid localhost / filesystem paths
                $isBrokenLocalhost = (strpos($baseUrl, 'localhost') !== false || strpos($baseUrl, '/www/wwwroot') !== false);
                if (!$isBrokenLocalhost) {
                    $html = preg_replace_callback('/<a\s+([^>]*?)href=["\'](https?:\/\/[^"\']+)["\']([^>]*)>/i', function($m) use ($baseUrl, $trackingToken) {
                        $originalUrl = $m[2];
                        if (strpos($originalUrl, 'track/open') !== false || strpos($originalUrl, 'r=track') !== false) return $m[0];
                        $trackUrl = $baseUrl . '/api.php?r=track/click&t=' . urlencode($trackingToken) . '&url=' . urlencode($originalUrl);
                        return '<a ' . $m[1] . 'href="' . htmlspecialchars($trackUrl) . '"' . $m[3] . '>';
                    }, $html);
                }
            }
        }

        // Unsubscribe placeholders removed (traffic cannot unsubscribe)
        $html = str_ireplace(['{{UNSUBSCRIBE_URL}}', '{{unsubscribe_url}}'], '#', $html);
        $text = str_ireplace(['{{UNSUBSCRIBE_URL}}', '{{unsubscribe_url}}'], '', $text);

        // Optional headers & Auto-Reply RFC 3834 compliance
        $isAutoReply = !empty($options['is_auto_reply']);
        $inReplyTo   = trim($options['in_reply_to'] ?? '');
        $references  = trim($options['references'] ?? '');
        $replyToVal  = trim($options['reply_to'] ?? '');

        $replyToHdr = '';
        if ($replyToVal) {
            if (preg_match('/^(.*?)\s*<([^>]+)>$/', $replyToVal, $m)) {
                $rName  = trim($m[1]);
                $rEmail = trim($m[2]);
                $replyToHdr = $rName ? "Reply-To: =?UTF-8?B?" . base64_encode($rName) . "?= <{$rEmail}>\r\n" : "Reply-To: <{$rEmail}>\r\n";
            } else {
                $cleanEmail = trim($replyToVal, '<>');
                $replyToHdr = "Reply-To: <{$cleanEmail}>\r\n";
            }
        } else {
            $replyToHdr = "Reply-To: {$fd}\r\n";
        }

        // Sender header: RFC 5322 §3.6.2 specifies Sender SHOULD NOT be present when From == Sender
        $senderHdr = '';
        if (!empty($options['sender']) && strtolower(trim($options['sender'])) !== strtolower($from)) {
            $senderHdr = "Sender: <" . trim($options['sender']) . ">\r\n";
        }

        // Auto-reply headers (RFC 3834 compliance)
        $arHdrs = '';
        if ($isAutoReply) {
            $arHdrs .= "Auto-Submitted: auto-replied\r\n";
            $arHdrs .= "X-Auto-Response-Suppress: All\r\n";
            $arHdrs .= "Precedence: auto_reply\r\n";
        }

        // Threading headers
        $threadHdrs = '';
        if ($inReplyTo) {
            $formattedInReplyTo = (strpos($inReplyTo, '<') === false) ? "<{$inReplyTo}>" : $inReplyTo;
            $threadHdrs .= "In-Reply-To: {$formattedInReplyTo}\r\n";
        }
        if ($references) {
            $formattedRef = (strpos($references, '<') === false) ? "<{$references}>" : $references;
            $threadHdrs .= "References: {$formattedRef}\r\n";
        } elseif ($inReplyTo) {
            $formattedInReplyTo = (strpos($inReplyTo, '<') === false) ? "<{$inReplyTo}>" : $inReplyTo;
            $threadHdrs .= "References: {$formattedInReplyTo}\r\n";
        }

        // List-Unsubscribe header disabled per user request (traffic cannot unsubscribe)
        $unsubHdrs = '';

        // Deliverability compliance headers
        $deliverabilityHdrs  = "Return-Path: <{$envelopeSender}>\r\n";
        $deliverabilityHdrs .= "X-Mailer: Mailpro/4.0\r\n";
        $deliverabilityHdrs .= "Feedback-ID: mailpro:campaign:user{$this->cfg['user_id']}:general\r\n";

        // Filter out missing/unreadable image files before building MIME
        $inlineImages = array_values(array_filter($inlineImages,
            fn($i) => !empty($i['path']) && file_exists($i['path']) && is_readable($i['path'])
        ));

        if (!empty($inlineImages)) {
            /*
             * Correct MIME structure for inline images (renders in Gmail + Outlook):
             *
             * multipart/mixed
             *   └── multipart/related; type="text/html"
             *         ├── multipart/alternative
             *         │     ├── text/plain
             *         │     └── text/html  ← references cid: images
             *         └── image parts (Content-ID: <cid>)
             */
            $bMixed   = '----=_MX' . bin2hex(random_bytes(8));
            $bRelated = '----=_RL' . bin2hex(random_bytes(8));
            $bAlt     = '----=_AL' . bin2hex(random_bytes(8));

            $msg  = "From: {$fd}\r\n";
            $msg .= "To: {$td}\r\n";
            $msg .= $replyToHdr;
            if ($senderHdr) $msg .= $senderHdr;
            if ($arHdrs) $msg .= $arHdrs;
            if ($threadHdrs) $msg .= $threadHdrs;
            if ($unsubHdrs) $msg .= $unsubHdrs;
            $msg .= $deliverabilityHdrs;
            $msg .= "Subject: {$sb}\r\n";
            $msg .= "Date: {$date}\r\n";
            $msg .= "Message-ID: {$mid}\r\n";
            $msg .= "MIME-Version: 1.0\r\n";
            $msg .= "Content-Type: multipart/mixed; boundary=\"{$bMixed}\"\r\n";
            $msg .= "\r\n";

            $msg .= "--{$bMixed}\r\n";
            $msg .= "Content-Type: multipart/related; boundary=\"{$bRelated}\"; type=\"multipart/alternative\"\r\n";
            $msg .= "\r\n";

            $msg .= "--{$bRelated}\r\n";
            $msg .= "Content-Type: multipart/alternative; boundary=\"{$bAlt}\"\r\n";
            $msg .= "\r\n";

            $msg .= "--{$bAlt}\r\n";
            $msg .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $msg .= "Content-Transfer-Encoding: quoted-printable\r\n";
            $msg .= "\r\n";
            $msg .= quoted_printable_encode($text ?: strip_tags($html));
            $msg .= "\r\n";

            $msg .= "--{$bAlt}\r\n";
            $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
            $msg .= "Content-Transfer-Encoding: quoted-printable\r\n";
            $msg .= "\r\n";
            $msg .= quoted_printable_encode($html);
            $msg .= "\r\n";

            $msg .= "--{$bAlt}--\r\n";

            foreach ($inlineImages as $img) {
                $data = chunk_split(base64_encode(file_get_contents($img['path'])));
                $mime = $img['mime'] ?? 'image/jpeg';
                $name = basename($img['path']);
                $cid  = $img['cid'];

                $msg .= "--{$bRelated}\r\n";
                $msg .= "Content-Type: {$mime}; name=\"{$name}\"\r\n";
                $msg .= "Content-Transfer-Encoding: base64\r\n";
                $msg .= "Content-Disposition: inline; filename=\"{$name}\"\r\n";
                $msg .= "Content-ID: <{$cid}>\r\n";
                $msg .= "X-Attachment-Id: {$cid}\r\n";
                $msg .= "\r\n";
                $msg .= $data;
                $msg .= "\r\n";
            }

            $msg .= "--{$bRelated}--\r\n";
            $msg .= "\r\n";
            $msg .= "--{$bMixed}--\r\n";

        } else {
            // No images — simple multipart/alternative
            $bAlt = '----=_AL' . bin2hex(random_bytes(8));

            $msg  = "From: {$fd}\r\n";
            $msg .= "To: {$td}\r\n";
            $msg .= $replyToHdr;
            if ($senderHdr) $msg .= $senderHdr;
            if ($arHdrs) $msg .= $arHdrs;
            if ($threadHdrs) $msg .= $threadHdrs;
            if ($unsubHdrs) $msg .= $unsubHdrs;
            $msg .= $deliverabilityHdrs;
            $msg .= "Subject: {$sb}\r\n";
            $msg .= "Date: {$date}\r\n";
            $msg .= "Message-ID: {$mid}\r\n";
            $msg .= "MIME-Version: 1.0\r\n";
            $msg .= "Content-Type: multipart/alternative; boundary=\"{$bAlt}\"\r\n";
            $msg .= "\r\n";

            $msg .= "--{$bAlt}\r\n";
            $msg .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $msg .= "Content-Transfer-Encoding: quoted-printable\r\n";
            $msg .= "\r\n";
            $msg .= quoted_printable_encode($text ?: strip_tags($html));
            $msg .= "\r\n";

            $msg .= "--{$bAlt}\r\n";
            $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
            $msg .= "Content-Transfer-Encoding: quoted-printable\r\n";
            $msg .= "\r\n";
            $msg .= quoted_printable_encode($html);
            $msg .= "\r\n";

            $msg .= "--{$bAlt}--\r\n";
        }

        // ── IN-APP DKIM CRYPTOGRAPHIC SIGNING (RFC 6376) ──────────────────
        try {
            if (!class_exists('DkimSigner')) {
                require_once __DIR__ . '/dkim.php';
            }

            $dkimKey = null;
            if (!empty($options['dkim']['private_key'])) {
                $dkimKey = $options['dkim'];
            } elseif (!empty($this->cfg['dkim']['private_key'])) {
                $dkimKey = $this->cfg['dkim'];
            } else {
                $dkimKey = DkimSigner::getActiveKeyForDomain($fromDomain, $userId);
            }

            if ($dkimKey && !empty($dkimKey['private_key'])) {
                $parts = explode("\r\n\r\n", $msg, 2);
                if (count($parts) === 2) {
                    $rawHdrs = $parts[0];
                    $rawBody = $parts[1];
                    $dkimDomain = $dkimKey['domain'] ?? $fromDomain;
                    $dkimSelector = $dkimKey['selector'] ?? 'mailpro';

                    $dkimHeader = DkimSigner::signMessage(
                        $rawHdrs,
                        $rawBody,
                        $dkimDomain,
                        $dkimSelector,
                        $dkimKey['private_key']
                    );
                    $msg = $dkimHeader . $msg;
                }
            }
        } catch (\Throwable $e) {
            error_log("[Mailer DKIM Warning] Signing failed: " . $e->getMessage());
        }

        // SMTP dot-stuffing: lines starting with "." must be doubled.
        // Split on \r\n (not just \n) so base64 image lines are handled correctly.
        $lines = explode("\r\n", $msg);
        foreach ($lines as &$line) {
            if (isset($line[0]) && $line[0] === '.') {
                $line = '.' . $line;
            }
        }
        unset($line);
        $msg = implode("\r\n", $lines);

        @fwrite($sock, $msg . "\r\n.\r\n");
        $r = $this->read($sock);
        $this->cmd($sock, 'QUIT');
        @fclose($sock);

        if (strpos($r, '250') === false) {
            $clean = trim(preg_replace('/^\d+[\s-]*/m', '', $r));
            throw new Exception("Send failed: {$clean}");
        }
        return $mid;
    }

    /**
     * Diagnostic report mode: Inspects SMTP settings, DNS alignment,
     * envelope sender, SPF, DKIM, and DMARC alignment status without revealing passwords.
     */
    public function getDeliverabilityReport($to = 'recipient@example.com'): array {
        $from      = trim($this->cfg['from_email'] ?? '');
        if (preg_match('/<([^>]+)>/', $from, $m)) $from = trim($m[1]);
        $fromDomain = '';
        if ($from && strpos($from, '@') !== false) {
            $fromDomain = strtolower(trim(explode('@', $from)[1]));
        }

        $host     = $this->cfg['host'] ?? '';
        $port     = (int)($this->cfg['port'] ?? 25);
        $username = $this->cfg['username'] ?? '';
        
        // Redact username partially if present
        $safeUser = $username;
        if ($safeUser && strpos($safeUser, '@') !== false) {
            [$uPart, $uDom] = explode('@', $safeUser, 2);
            $safeUser = substr($uPart, 0, 3) . '***@' . $uDom;
        }

        $ehloHost = $fromDomain ?: (gethostname() ?: $host);
        if (!$ehloHost || $ehloHost === 'localhost') $ehloHost = $fromDomain ?: $host;

        // DNS Lookups
        $spfRecord   = 'Not Found';
        $dmarcRecord = 'Not Found';
        $dkimRecord  = 'Not Found';
        $dkimSelector = 'google';

        if ($fromDomain) {
            $txts = @dns_get_record($fromDomain, DNS_TXT);
            if (is_array($txts)) {
                foreach ($txts as $t) {
                    $txtVal = $t['txt'] ?? ($t['entries'][0] ?? '');
                    if (strpos($txtVal, 'v=spf1') === 0) {
                        $spfRecord = $txtVal;
                        break;
                    }
                }
            }

            $dmarcs = @dns_get_record("_dmarc.{$fromDomain}", DNS_TXT);
            if (is_array($dmarcs) && !empty($dmarcs)) {
                $dmarcRecord = $dmarcs[0]['txt'] ?? ($dmarcs[0]['entries'][0] ?? 'Not Found');
            }

            $dkims = @dns_get_record("{$dkimSelector}._domainkey.{$fromDomain}", DNS_TXT);
            if (is_array($dkims) && !empty($dkims)) {
                $dkimRecord = "Found at {$dkimSelector}._domainkey.{$fromDomain}";
            } else {
                // Check alternative selector 'default'
                $dkimsDef = @dns_get_record("default._domainkey.{$fromDomain}", DNS_TXT);
                if (is_array($dkimsDef) && !empty($dkimsDef)) {
                    $dkimSelector = 'default';
                    $dkimRecord   = "Found at default._domainkey.{$fromDomain}";
                }
            }
        }

        // Evaluate DMARC Alignment
        $envelopeDomain = $fromDomain; // Mailer forces envelope MAIL FROM domain == fromDomain
        $spfAligned  = ($envelopeDomain === $fromDomain) ? 'PASS (Relaxed/Strict Aligned)' : 'FAIL (Domain Mismatch)';
        $dkimAligned = (strpos($dkimRecord, 'Found') !== false) ? 'PASS (DKIM Domain matches From domain)' : 'WARNING (DKIM Selector Not Found in DNS)';
        $dmarcAligned = ($spfAligned === 'PASS (Relaxed/Strict Aligned)') ? 'PASS (SPF Envelope Aligned with From)' : 'FAIL (Neither SPF nor DKIM Aligned)';

        $msgId = '<' . md5(uniqid('', true)) . '@' . ($fromDomain ?: 'mailszo') . '>';

        return [
            'SMTP Host'         => $host,
            'SMTP Port'         => $port,
            'SMTP Username'     => $safeUser ?: '(none / unauthenticated)',
            'From'              => ($this->cfg['from_name'] ?? '') . " <{$from}>",
            'Envelope From'     => $from,
            'Return Path'       => $from,
            'DKIM Domain'       => $fromDomain ?: '(unknown)',
            'DKIM Selector'     => $dkimSelector,
            'DKIM Status'       => $dkimRecord,
            'SPF Domain'        => $fromDomain ?: '(unknown)',
            'SPF Record'        => $spfRecord,
            'DMARC Domain'      => "_dmarc.{$fromDomain}",
            'DMARC Record'      => $dmarcRecord,
            'DMARC Alignment'   => $dmarcAligned,
            'Message-ID'        => $msgId,
            'HELO/EHLO'         => $ehloHost,
            'TLS'               => $this->cfg['secure'] ? 'SSL/TLS (implicit)' : 'STARTTLS (explicit)',
        ];
    }
}
