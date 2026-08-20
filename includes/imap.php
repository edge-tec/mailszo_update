<?php
/**
 * MailsZo — IMAP Helpers (raw socket, no php-imap extension required)
 *
 * Core design: UID-based tracking
 * ─────────────────────────────────
 * Every IMAP message has a persistent UID (integer). We store the highest
 * UID we have successfully processed in imap_accounts.last_uid.
 *
 * On each poll we run:   UID SEARCH UID <lastUid+1>:*
 * This returns every message newer than the last one we saw —
 * regardless of whether it is Read/Unread (\Seen or not).
 *
 * This fixes the "found:0" problem that occurred when:
 *  - A webmail client or mail app read/opened the email first
 *  - A previous cron run marked messages \Seen but crashed before enrolling
 *  - Gmail / hosted mail auto-marks promotional mail as read
 *
 * We do NOT modify \Seen flags at all — we only track by UID.
 */

// ── Open TCP/SSL socket ───────────────────────────────────────────
function imapSocketOpen(string $host, int $port, bool $ssl, int $timeout = 20) {
    // FIX: Manually resolve to IPv4 to prevent IPv6 blackhole timeouts
    $ip = gethostbyname($host);
    if (!$ip || $ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) return false;
    
    // Connect via TCP first to enforce timeout
    $addr = 'tcp://' . $ip . ':' . $port;
    $sock = @stream_socket_client($addr, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
    if (!$sock) return false;
    
    // Enforce read/write timeout BEFORE the SSL handshake
    stream_set_timeout($sock, $timeout);
    
    if ($ssl) {
        stream_context_set_option($sock, 'ssl', 'verify_peer', false);
        stream_context_set_option($sock, 'ssl', 'verify_peer_name', false);
        stream_context_set_option($sock, 'ssl', 'peer_name', $host);
        // Do the SSL handshake explicitly, respecting the timeout
        if (!@stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_SSLv23_CLIENT)) {
            fclose($sock);
            return false;
        }
    }
    
    // Restore default per-read timeout for subsequent IMAP commands
    stream_set_timeout($sock, 20); 
    return $sock;
}

// ── Read one line (up to \n) ─────────────────────────────────────
function imapReadLine($sock): string {
    $line = '';
    while (!feof($sock)) {
        $ch = fgetc($sock);
        if ($ch === false) {
            $meta = stream_get_meta_data($sock);
            if ($meta['timed_out']) break;
            if ($line === '') break;
        } else {
            $line .= $ch;
            if ($ch === "\n") break;
        }
    }
    return $line;
}

// ── Read lines until tagged OK/NO/BAD response ───────────────────
function imapReadResponse($sock, string $tag, int $timeout = 20): string {
    $out      = '';
    $deadline = time() + $timeout;
    $prefix   = $tag . ' ';
    $plen     = strlen($prefix);
    while (!feof($sock) && time() < $deadline) {
        $line = imapReadLine($sock);
        if ($line === '') { usleep(5000); continue; }
        $out .= $line;
        if (strncmp($line, $prefix, $plen) === 0) break;
    }
    return $out;
}

// ── Read exactly N bytes (for IMAP literal blocks) ───────────────
function imapReadLiteral($sock, int $n): string {
    $buf = ''; $rem = $n; $deadline = time() + 30;
    while ($rem > 0 && !feof($sock) && time() < $deadline) {
        $chunk = fread($sock, min($rem, 8192));
        if ($chunk === false || $chunk === '') { usleep(5000); continue; }
        $buf .= $chunk; $rem -= strlen($chunk);
    }
    return $buf;
}

// ── RFC 2047 encoded-word decoder ────────────────────────────────
function imapDecodeWords(string $s): string {
    if (strpos($s, '=?') === false) return $s;
    $s = preg_replace('/\?=\s+=\?/', '?==?', $s);
    $s = preg_replace_callback('/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/', function($m) {
        $decoded = strtoupper($m[2]) === 'B'
            ? base64_decode($m[3], true)
            : quoted_printable_decode(str_replace('_', ' ', $m[3]));
        if ($decoded === false) return $m[0];
        $cs = strtolower(trim($m[1]));
        if ($cs !== 'utf-8' && $cs !== 'utf8') {
            // PHP 8 changed mb_convert_encoding to throw a ValueError when the
            // source encoding name isn't compiled into the host's mbstring
            // build (e.g. windows-1255 Hebrew on minimal PHP installs). The
            // `@` suppression operator does NOT swallow exceptions, so we
            // need a real try/catch — otherwise the cron's IMAP poll dies
            // mid-fetch on the first non-UTF-8 subject it encounters.
            //
            // Try mb_convert_encoding first, then iconv with //IGNORE which
            // is more permissive on glibc-iconv builds, and finally fall
            // back to the raw decoded bytes (the subject won't render
            // perfectly, but it won't crash the cron either).
            $converted = null;
            if (function_exists('mb_convert_encoding')) {
                try {
                    $c = @mb_convert_encoding($decoded, 'UTF-8', $m[1]);
                    if ($c !== false && $c !== '') $converted = $c;
                } catch (\Throwable $_mbe) { /* unsupported encoding — try iconv next */ }
            }
            if ($converted === null && function_exists('iconv')) {
                try {
                    $c = @iconv($m[1], 'UTF-8//IGNORE', $decoded);
                    if ($c !== false && $c !== '') $converted = $c;
                } catch (\Throwable $_ice) { /* iconv also doesn't know it — leave raw */ }
            }
            if ($converted !== null) $decoded = $converted;
        }
        return $decoded;
    }, $s);
    return trim($s);
}

// ── Parse FROM header → ['email', 'name'] ────────────────────────
function imapParseFromHeader(string $line): array {
    $line = trim(preg_replace('/^From\s*:\s*/i', '', $line));
    $line = imapDecodeWords($line);
    $email = ''; $name = '';
    if (preg_match('/^(.*?)\s*<([^>@\s]+@[^>]+)>\s*$/s', $line, $m)) {
        $name = trim($m[1], ' "\''); $email = strtolower(trim($m[2]));
    } elseif (preg_match('/^<([^>@\s]+@[^>]+)>\s*$/s', $line, $m)) {
        $email = strtolower(trim($m[1]));
    } elseif (preg_match('/^([^\s@]+@[^\s@(]+)\s*\((.+)\)\s*$/s', $line, $m)) {
        $email = strtolower(trim($m[1])); $name = trim($m[2]);
    } elseif (preg_match('/^([^\s@]+@[^\s@]+)\s*$/s', $line, $m)) {
        $email = strtolower(trim($m[1]));
    }
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
    return ['email' => $email, 'name' => $name];
}

// ── Parse Subject header ─────────────────────────────────────────
function imapParseSubject(string $line): string {
    return imapDecodeWords(trim(preg_replace('/^Subject\s*:\s*/i', '', $line)));
}

// ── Extract header text from a raw FETCH response ────────────────
function imapExtractHeaders(string $resp, string $tag): string {
    // Literal form: {NNN}\r\n<data>
    if (preg_match('/\{(\d+)\}\r?\n/s', $resp, $lm, PREG_OFFSET_CAPTURE)) {
        $start = $lm[0][1] + strlen($lm[0][0]);
        return substr($resp, $start, (int)$lm[1][0]);
    }
    // Inline form between HEADER.FIELDS marker and closing )
    if (preg_match('/HEADER\.FIELDS[^\r\n]*\r?\n([\s\S]+?)\r?\n\)\r?\n/i', $resp, $hm)) {
        return $hm[1];
    }
    // Last resort: lines between FETCH line and tag OK
    $lines = explode("\n", $resp); $cap = false; $parts = [];
    foreach ($lines as $ln) {
        $ln = rtrim($ln, "\r");
        if (!$cap && preg_match('/\* \d+ FETCH/i', $ln)) { $cap = true; continue; }
        if ($cap && strncmp($ln, $tag . ' ', strlen($tag) + 1) === 0) break;
        if ($cap) $parts[] = $ln;
    }
    return implode("\n", $parts);
}

// ── Test IMAP login ──────────────────────────────────────────────
function imapTestSocket(string $host, int $port, string $user, string $pass, bool $ssl, int $timeout = 20): array {
    if (!$host || !$user || !$pass)
        return ['ok' => false, 'message' => '❌ Host, username and password are all required.'];
    $sock = imapSocketOpen($host, $port, $ssl, $timeout);
    if (!$sock)
        return ['ok' => false, 'message' => "❌ Could not connect to {$host}:{$port}."];
    $gr = imapReadLine($sock);
    if (strpos($gr, '* OK') === false && strpos($gr, '* PREAUTH') === false) {
        fclose($sock);
        return ['ok' => false, 'message' => '❌ Bad server greeting: ' . trim($gr)];
    }
    fwrite($sock, 'T01 LOGIN "' . addslashes($user) . '" "' . addslashes($pass) . '"' . "\r\n");
    $resp = imapReadResponse($sock, 'T01');
    if (strpos($resp, 'T01 OK') !== false) {
        fwrite($sock, "T02 LOGOUT\r\n"); fclose($sock);
        return ['ok' => true, 'message' => '✅ IMAP connection successful!'];
    }
    fclose($sock);
    if (preg_match('/T01 NO (.+)/i', $resp, $m)) return ['ok' => false, 'message' => '❌ Login failed: ' . trim($m[1])];
    if (preg_match('/T01 BAD (.+)/i', $resp, $m)) return ['ok' => false, 'message' => '❌ Protocol error: ' . trim($m[1])];
    return ['ok' => false, 'message' => '❌ Authentication failed.'];
}

// ── Fetch new messages by UID (raw socket) ────────────────────────
/**
 * Fetches all messages with UID > $lastUid using IMAP UID SEARCH.
 * This works regardless of \Seen flag — even emails opened in webmail
 * or read by other clients are included as long as their UID is new.
 *
 * Pass $prevUidValidity from the prior run so we can detect a mailbox
 * UID-space reset (UIDVALIDITY change). When that happens we ignore the
 * old $lastUid and rescan from scratch — otherwise emails would be
 * silently skipped forever.
 *
 * Returns ['messages' => [...], 'highestUid' => int, 'connected' => bool,
 *          'uidValidity' => int, 'uidNext' => int, 'existsCount' => int,
 *          'reset' => string|null]
 *   reset = 'uidvalidity_changed' | 'stale_last_uid' | null
 */
function imapFetchSinceUid(string $host, int $port, string $user, string $pass, bool $ssl, int $lastUid, int $prevUidValidity = 0, int $perRunCap = 100): array {
    $result = [
        'messages'    => [],
        'highestUid'  => $lastUid,
        'connected'   => false,
        'uidValidity' => 0,
        'uidNext'     => 0,
        'existsCount' => 0,
        'reset'       => null,
    ];

    $sock = imapSocketOpen($host, $port, $ssl);
    if (!$sock) return $result;

    // Read greeting
    $gr = imapReadLine($sock);
    if (strpos($gr, '* OK') === false && strpos($gr, '* PREAUTH') === false) {
        fclose($sock); return $result;
    }

    // LOGIN
    fwrite($sock, 'A01 LOGIN "' . addslashes($user) . '" "' . addslashes($pass) . '"' . "\r\n");
    if (strpos(imapReadResponse($sock, 'A01'), 'A01 OK') === false) {
        fclose($sock); return $result;
    }

    // Mark as successfully connected & authenticated
    $result['connected'] = true;

    // SELECT INBOX — also gives us UIDVALIDITY, UIDNEXT and EXISTS count
    fwrite($sock, "A02 SELECT INBOX\r\n");
    $selResp = imapReadResponse($sock, 'A02');

    // Parse EXISTS count — if mailbox is truly empty, bail early
    $existsCount = 0;
    if (preg_match('/\* (\d+) EXISTS/i', $selResp, $exm)) {
        $existsCount = (int)$exm[1];
    }
    $uidValidity = 0;
    if (preg_match('/UIDVALIDITY\s+(\d+)/i', $selResp, $uvm)) {
        $uidValidity = (int)$uvm[1];
    }
    $uidNext = 0;
    if (preg_match('/UIDNEXT\s+(\d+)/i', $selResp, $unm)) {
        $uidNext = (int)$unm[1];
    }
    $result['existsCount'] = $existsCount;
    $result['uidValidity'] = $uidValidity;
    $result['uidNext']     = $uidNext;

    // ── Detect stale last_uid and recover ──────────────────────────────
    // 1. UIDVALIDITY mismatch: the mailbox's UID space has been recreated.
    //    The stored last_uid no longer maps to anything meaningful.
    //    → Treat as a fresh scan (lastUid = 0).
    if ($prevUidValidity > 0 && $uidValidity > 0 && $uidValidity !== $prevUidValidity) {
        $lastUid          = 0;
        $result['reset']  = 'uidvalidity_changed';
        $result['highestUid'] = 0;
    }
    // 2. last_uid >= UIDNEXT: every existing UID in the mailbox is
    //    strictly less than UIDNEXT (UIDNEXT is the value the next NEW
    //    message will receive). If our last_uid is >= UIDNEXT, our state
    //    is corrupt (manual reset gone wrong, account swap on same row id,
    //    etc.). Without this guard, UID SEARCH lastUid+1:* returns nothing
    //    forever. → Reset and rescan.
    if ($uidNext > 0 && $lastUid >= $uidNext) {
        $lastUid          = 0;
        if ($result['reset'] === null) $result['reset'] = 'stale_last_uid';
        $result['highestUid'] = 0;
    }

    if ($existsCount === 0) {
        fwrite($sock, "A03 LOGOUT\r\n"); fclose($sock); return $result;
    }

    // UID SEARCH for all messages with UID greater than lastUid
    // If lastUid = 0, search ALL messages (UID 1:*)
    // UNKEYWORD <kw>: skip messages MailsZo previously APPENDed via a move
    //                 (those are already processed; without this filter they
    //                  would re-trigger the thread state machine).
    $searchRange = ($lastUid > 0) ? ($lastUid + 1) . ':*' : '1:*';
    $kw = IMAP_DEDUPE_KEYWORD;
    fwrite($sock, "A03 UID SEARCH UID {$searchRange} UNKEYWORD {$kw}\r\n");
    $searchResp = imapReadResponse($sock, 'A03', 30);

    // Parse returned UIDs from "* SEARCH uid1 uid2 uid3 ..."
    $uids = [];
    if (preg_match('/\* SEARCH([\d\s]*)/i', $searchResp, $sm)) {
        $uids = array_values(array_filter(array_map('intval', explode(' ', trim($sm[1])))));
        // Filter out any UID <= lastUid (server may return lastUid itself
        // because IMAP ranges normalise N:* when N > current max UID)
        $uids = array_filter($uids, fn($u) => $u > $lastUid);
        $uids = array_values($uids);
    }

    if (empty($uids)) {
        fwrite($sock, "A04 LOGOUT\r\n"); fclose($sock); return $result;
    }

    // Process oldest-first so a backlog catches up across multiple cron runs.
    sort($uids, SORT_NUMERIC);

    // Per-run cap so a single poll doesn't run for too long. We deliberately
    // do NOT advance highestUid past UIDs we don't actually attempt to FETCH —
    // otherwise on a backlog larger than the cap, anything past it would be
    // skipped forever (which is the bug the user reported as "not all emails
    // are being added to the database").
    // The cap is admin-configurable via config.json:imap_read_per_minute and
    // arrives here as $perRunCap. Defaults to 100 to preserve legacy behaviour.
    $cap = $perRunCap > 0 ? $perRunCap : 100;
    $processBatch = array_slice($uids, 0, $cap);

    $messages       = [];
    $attemptedUids  = []; // UIDs whose FETCH we actually issued + drained
    $tagNum         = 4;

    foreach ($processBatch as $uid) {
        $tFetch = sprintf('A%03d', $tagNum++);

        // UID FETCH — use BODY.PEEK so we don't alter \Seen flag, fetching threading headers
        fwrite($sock, "{$tFetch} UID FETCH {$uid} (BODY.PEEK[HEADER.FIELDS (FROM SUBJECT MESSAGE-ID IN-REPLY-TO REFERENCES DATE)])\r\n");

        // Read response, handling IMAP literal blocks inline
        $fetchLines  = '';
        $literalData = null;
        $deadline    = time() + 15;
        $pfx         = $tFetch . ' ';

        while (!feof($sock) && time() < $deadline) {
            $line = imapReadLine($sock);
            $fetchLines .= $line;

            // Literal detected: {NNN}\r\n
            if ($literalData === null && preg_match('/\{(\d+)\}\r?\n$/', $line, $lm)) {
                $lsize       = (int)$lm[1];
                $literalData = imapReadLiteral($sock, $lsize);
                $fetchLines .= $literalData;
                $fetchLines .= imapReadLine($sock); // closing )
                $fetchLines .= imapReadLine($sock); // tagged OK
                break;
            }

            if (strncmp($line, $pfx, strlen($pfx)) === 0) break;
        }

        // Mark this UID as attempted: the FETCH response was drained from the
        // socket. We will advance last_uid past this UID even if header parsing
        // below fails — otherwise an unparseable message would loop forever.
        $attemptedUids[] = $uid;

        // Pull header block out of response
        $headerBlock = '';
        if ($literalData !== null) {
            $headerBlock = $literalData;
        } else {
            $headerBlock = imapExtractHeaders($fetchLines, $tFetch);
        }

        // Unfold RFC 5322 multi-line headers
        $headerBlock = preg_replace("/\r?\n([ \t])/", ' $1', $headerBlock);

        $fromLine = ''; $subjectLine = ''; $msgIdLine = ''; $inReplyToLine = ''; $referencesLine = ''; $dateLine = '';
        foreach (explode("\n", $headerBlock) as $hLine) {
            $hLine = rtrim($hLine, "\r");
            if ($fromLine       === '' && preg_match('/^From\s*:/i',        $hLine)) $fromLine       = $hLine;
            if ($subjectLine    === '' && preg_match('/^Subject\s*:/i',     $hLine)) $subjectLine    = $hLine;
            if ($msgIdLine      === '' && preg_match('/^Message-ID\s*:/i',  $hLine)) $msgIdLine      = $hLine;
            if ($inReplyToLine  === '' && preg_match('/^In-Reply-To\s*:/i', $hLine)) $inReplyToLine  = $hLine;
            if ($referencesLine === '' && preg_match('/^References\s*:/i',  $hLine)) $referencesLine = $hLine;
            if ($dateLine       === '' && preg_match('/^Date\s*:/i',        $hLine)) $dateLine       = $hLine;
        }

        if ($fromLine === '') continue; // No FROM header found

        $parsed     = imapParseFromHeader($fromLine);
        $subject    = imapParseSubject($subjectLine);
        $msgId      = trim(preg_replace('/^Message-ID\s*:\s*/i', '', $msgIdLine));
        $inReplyTo  = trim(preg_replace('/^In-Reply-To\s*:\s*/i', '', $inReplyToLine));
        $references = trim(preg_replace('/^References\s*:\s*/i', '', $referencesLine));
        $dateHeader = trim(preg_replace('/^Date\s*:\s*/i', '', $dateLine));

        if (!$parsed['email'] || !filter_var($parsed['email'], FILTER_VALIDATE_EMAIL)) continue;

        $messages[] = [
            'from_email'  => $parsed['email'],
            'from_name'   => $parsed['name'],
            'subject'     => $subject,
            'message_id'  => $msgId,
            'in_reply_to' => $inReplyTo,
            'references'  => $references,
            'date_header' => $dateHeader,
            'uid'         => $uid,
        ];
    }

    fwrite($sock, sprintf('A%03d LOGOUT', $tagNum) . "\r\n");
    fclose($sock);

    // Advance only past UIDs we actually attempted to FETCH. Any UID > 100 in
    // this batch (or any UID whose FETCH we never sent) stays unprocessed and
    // will be picked up by the next cron run. This guarantees no email is
    // permanently skipped on busy mailboxes.
    $highestUid = $attemptedUids ? max($attemptedUids) : $lastUid;

    $result['messages']    = $messages;
    $result['highestUid']  = $highestUid;
    return $result;
}

// ── php-imap extension: fetch messages since lastUid ─────────────
/**
 * Same UID-based strategy using the php-imap extension.
 *
 * Pass $prevUidValidity from the prior run so we can detect a mailbox
 * UID-space reset (UIDVALIDITY change). When that happens we ignore the
 * old $lastUid and rescan from scratch.
 *
 * Returns ['messages' => [...], 'highestUid' => int, 'uidValidity' => int,
 *          'uidNext' => int, 'existsCount' => int, 'reset' => string|null]
 */
function imapExtFetchSinceUid($mbox, int $lastUid, int $prevUidValidity = 0, string $mailboxRef = '', int $perRunCap = 200): array {
    $result = [
        'messages'    => [],
        'highestUid'  => $lastUid,
        'uidValidity' => 0,
        'uidNext'     => 0,
        'existsCount' => 0,
        'reset'       => null,
    ];

    // Capture UIDVALIDITY / UIDNEXT / EXISTS via STATUS so we can detect
    // a mailbox UID-space reset (UIDVALIDITY change) and a stale last_uid
    // (last_uid >= UIDNEXT). Without these checks the search below may
    // silently return nothing forever after such an event.
    if ($mailboxRef !== '' && function_exists('imap_status')) {
        $st = @imap_status($mbox, $mailboxRef, SA_UIDVALIDITY | SA_UIDNEXT | SA_MESSAGES);
        if ($st) {
            $result['uidValidity'] = (int)($st->uidvalidity ?? 0);
            $result['uidNext']     = (int)($st->uidnext     ?? 0);
            $result['existsCount'] = (int)($st->messages    ?? 0);
        }
    }
    if ($result['existsCount'] === 0 && function_exists('imap_num_msg')) {
        $result['existsCount'] = (int)@imap_num_msg($mbox);
    }

    if ($prevUidValidity > 0 && $result['uidValidity'] > 0
        && $result['uidValidity'] !== $prevUidValidity) {
        $lastUid              = 0;
        $result['reset']      = 'uidvalidity_changed';
        $result['highestUid'] = 0;
    }
    if ($result['uidNext'] > 0 && $lastUid >= $result['uidNext']) {
        $lastUid              = 0;
        if ($result['reset'] === null) $result['reset'] = 'stale_last_uid';
        $result['highestUid'] = 0;
    }

    if ($result['existsCount'] === 0) return $result;

    // Fetch all message UIDs from the mailbox, then filter client-side.
    // imap_search('UNKEYWORD ...', SE_UID) returns UIDs of messages that do NOT
    // carry the MailsZo dedupe keyword — i.e., regular mail and not messages
    // we previously APPENDed as part of a move (those are already processed).
    $kw    = IMAP_DEDUPE_KEYWORD;
    $found = @imap_search($mbox, "UNKEYWORD {$kw}", SE_UID);

    // Fallback: some servers/transient states return false for imap_search
    // even when the mailbox has messages. Walk sequence numbers and resolve
    // each to a UID via imap_uid() so we still get the full UID list.
    // (We accept that this fallback can't filter by keyword — the move-dedupe
    //  step above already advances last_uid, so duplicates here would be rare.)
    if ((!$found || !is_array($found) || count($found) === 0) && $result['existsCount'] > 0) {
        $found = [];
        $n = $result['existsCount'];
        for ($seq = 1; $seq <= $n; $seq++) {
            $u = @imap_uid($mbox, $seq);
            if ($u) $found[] = (int)$u;
        }
    }

    if (!$found || !is_array($found) || count($found) === 0) return $result;

    // $found contains UIDs (SE_UID flag). Keep only UIDs newer than lastUid.
    $uids = array_values(array_filter($found, fn($u) => (int)$u > $lastUid));
    if (empty($uids)) return $result;

    // Process oldest-first so a backlog larger than the per-run cap catches up
    // over multiple cron runs. Previously this path sliced to the LAST 200,
    // which on a 1000-message backlog would advance last_uid to the newest UID
    // and silently drop the oldest 800 — directly causing the user's "not all
    // emails are added to the database" complaint.
    // The cap is admin-configurable via config.json:imap_read_per_minute and
    // arrives here as $perRunCap. Defaults to 200 (legacy behaviour).
    $cap = $perRunCap > 0 ? $perRunCap : 200;
    sort($uids, SORT_NUMERIC);
    if (count($uids) > $cap) $uids = array_slice($uids, 0, $cap);

    $messages = [];

    // Fetch overview by UID list
    $uidList  = implode(',', $uids);
    $overview = @imap_fetch_overview($mbox, $uidList, FT_UID);

    // If imap_fetch_overview totally failed (network blip, server hiccup),
    // do NOT advance last_uid — let the next cron run retry these UIDs.
    if ($overview === false) return $result;

    // We attempted to fetch every UID in the (sliced) batch; advance last_uid
    // only past those, never past the un-attempted tail.
    $highestUid = max($uids);

    if ($overview) {
        foreach ($overview as $ov) {
            $rawFrom = $ov->from ?? '';
            $fe = ''; $fn = '';

            if (preg_match('/<([^>@\s]+@[^>]+)>/', $rawFrom, $em)) {
                $fe = strtolower(trim($em[1]));
                $fn = trim(preg_replace('/<[^>]+>/', '', $rawFrom), ' "\'');
            } elseif (filter_var(trim($rawFrom), FILTER_VALIDATE_EMAIL)) {
                $fe = strtolower(trim($rawFrom));
            } else {
                // Fallback: headerinfo (uses sequence number, not UID)
                $seqNo = @imap_msgno($mbox, $ov->uid ?? 0);
                if ($seqNo > 0) {
                    $hdr = @imap_headerinfo($mbox, $seqNo);
                    if ($hdr && !empty($hdr->from[0])) {
                        $mb = $hdr->from[0]->mailbox ?? '';
                        $hh = $hdr->from[0]->host ?? '';
                        if ($mb && $hh) $fe = strtolower("{$mb}@{$hh}");
                        if (!empty($hdr->from[0]->personal)) {
                            $fn = function_exists('imap_utf8')
                                ? @imap_utf8($hdr->from[0]->personal)
                                : ($hdr->from[0]->personal ?? '');
                        }
                    }
                }
            }

            if (!$fe || !filter_var($fe, FILTER_VALIDATE_EMAIL)) continue;

            // Decode subject
            $fs = '';
            if (!empty($ov->subject)) {
                if (function_exists('imap_mime_header_decode')) {
                    $parts = @imap_mime_header_decode($ov->subject);
                    if ($parts) {
                        $fs = implode('', array_map(function($p) {
                            if ($p->charset === 'default' || strtolower($p->charset) === 'utf-8') return $p->text;
                            // PHP 8 throws ValueError on unknown encodings —
                            // wrap so a Hebrew/CP-1255 subject (or any other
                            // exotic encoding the mbstring build doesn't
                            // know) can never crash the cron's IMAP fetch.
                            if (function_exists('mb_convert_encoding')) {
                                try {
                                    $c = @mb_convert_encoding($p->text, 'UTF-8', $p->charset);
                                    if ($c !== false && $c !== '') return $c;
                                } catch (\Throwable $_mbe) { /* fall through to iconv */ }
                            }
                            if (function_exists('iconv')) {
                                try {
                                    $c = @iconv($p->charset, 'UTF-8//IGNORE', $p->text);
                                    if ($c !== false && $c !== '') return $c;
                                } catch (\Throwable $_ice) { /* leave raw */ }
                            }
                            return $p->text;
                        }, $parts));
                    }
                }
                if ($fs === '' && function_exists('imap_utf8')) $fs = @imap_utf8($ov->subject);
                if ($fs === '') $fs = $ov->subject;
            }

            $messages[] = [
                'from_email' => $fe,
                'from_name'  => $fn,
                'subject'    => $fs,
                'uid'        => (int)($ov->uid ?? 0),
            ];
        }
    }

    $result['messages']   = $messages;
    $result['highestUid'] = max($highestUid, $result['highestUid']);
    return $result;
}

// ─────────────────────────────────────────────────────────────────
// Two-IMAP workflow helpers
// ─────────────────────────────────────────────────────────────────
// These power the "delete the trigger email after reply 1 is sent" and
// "move user's reply from IMAP 1 to IMAP 2" requirements. Both raw-socket
// and php-imap paths are provided. Each helper opens its own connection
// so callers don't have to manage long-lived sockets.

/**
 * Internal: open + login on a raw socket and return [$sock, $err].
 * On success, the mailbox is selected and ready for UID commands.
 */
function imapOpenSelect(array $cfg, string $folder = 'INBOX') {
    $host = $cfg['host'] ?? '';
    $port = (int)($cfg['port'] ?? 993);
    $user = $cfg['username'] ?? '';
    $pass = $cfg['password'] ?? '';
    $ssl  = (bool)($cfg['ssl'] ?? true);

    $sock = imapSocketOpen($host, $port, $ssl);
    if (!$sock) return [null, "connect failed {$host}:{$port}"];
    $gr = imapReadLine($sock);
    if (strpos($gr, '* OK') === false && strpos($gr, '* PREAUTH') === false) {
        fclose($sock); return [null, 'bad greeting'];
    }
    fwrite($sock, 'X01 LOGIN "' . addslashes($user) . '" "' . addslashes($pass) . '"' . "\r\n");
    if (strpos(imapReadResponse($sock, 'X01'), 'X01 OK') === false) {
        fclose($sock); return [null, 'login failed'];
    }
    fwrite($sock, 'X02 SELECT "' . addslashes($folder) . '"' . "\r\n");
    if (strpos(imapReadResponse($sock, 'X02'), 'X02 OK') === false) {
        fwrite($sock, "X03 LOGOUT\r\n"); fclose($sock);
        return [null, 'select '.$folder.' failed'];
    }
    return [$sock, null];
}

/**
 * Delete a list of UIDs from a folder (default INBOX) on the given account.
 * Marks them \Deleted then EXPUNGE'd. Returns ['ok'=>bool,'deleted'=>N,'message'=>?].
 *
 * Used to enforce "as soon as the first email is read, remove it from IMAP 1
 * so we never reprocess that lead".
 */
function imapDeleteUids(array $cfg, array $uids, string $folder = 'INBOX'): array {
    $uids = array_values(array_filter(array_map('intval', $uids), fn($u) => $u > 0));
    if (!$uids) return ['ok' => true, 'deleted' => 0, 'message' => 'no uids'];

    [$sock, $err] = imapOpenSelect($cfg, $folder);
    if (!$sock) return ['ok' => false, 'deleted' => 0, 'message' => $err];

    $uidList = implode(',', $uids);
    // Set \Seen + \Deleted together so the spec's "mark as \Seen, then
    // EXPUNGE" semantics are visible to any third-party client that reads
    // the mailbox between this STORE and the EXPUNGE that follows.
    fwrite($sock, "Y01 UID STORE {$uidList} +FLAGS (\\Seen \\Deleted)\r\n");
    $stResp = imapReadResponse($sock, 'Y01', 30);
    $stOk   = strpos($stResp, 'Y01 OK') !== false;

    fwrite($sock, "Y02 EXPUNGE\r\n");
    $exResp = imapReadResponse($sock, 'Y02', 30);
    $exOk   = strpos($exResp, 'Y02 OK') !== false;

    fwrite($sock, "Y03 LOGOUT\r\n"); fclose($sock);

    return [
        'ok'      => ($stOk && $exOk),
        'deleted' => ($stOk && $exOk) ? count($uids) : 0,
        'message' => ($stOk && $exOk) ? 'ok' : ('store='.($stOk?'ok':'fail').' expunge='.($exOk?'ok':'fail')),
    ];
}

/**
 * Fetch the raw RFC 822 message bodies for a list of UIDs.
 * Returns ['ok'=>bool, 'messages'=>[uid => raw_string, ...], 'message'=>?].
 *
 * Used as the read leg of move(): we pull the raw bytes from IMAP 1, then
 * APPEND them to IMAP 2 in a separate connection.
 */
function imapFetchRawMessages(array $cfg, array $uids, string $folder = 'INBOX'): array {
    $uids = array_values(array_filter(array_map('intval', $uids), fn($u) => $u > 0));
    if (!$uids) return ['ok' => true, 'messages' => [], 'message' => 'no uids'];

    [$sock, $err] = imapOpenSelect($cfg, $folder);
    if (!$sock) return ['ok' => false, 'messages' => [], 'message' => $err];

    $out  = [];
    $tagN = 1;

    foreach ($uids as $uid) {
        $tag = sprintf('Z%03d', $tagN++);
        // BODY.PEEK[] = full RFC 822 message with no \Seen side-effect
        fwrite($sock, "{$tag} UID FETCH {$uid} (BODY.PEEK[])\r\n");

        $literalData = null;
        $deadline    = time() + 30;
        $pfx         = $tag . ' ';
        while (!feof($sock) && time() < $deadline) {
            $line = imapReadLine($sock);
            if ($literalData === null && preg_match('/\{(\d+)\}\r?\n$/', $line, $lm)) {
                $lsize       = (int)$lm[1];
                $literalData = imapReadLiteral($sock, $lsize);
                imapReadLine($sock); // closing )
                imapReadLine($sock); // tagged OK
                break;
            }
            if (strncmp($line, $pfx, strlen($pfx)) === 0) break;
        }

        if ($literalData !== null) $out[$uid] = $literalData;
    }

    fwrite($sock, "Z999 LOGOUT\r\n"); fclose($sock);
    return ['ok' => true, 'messages' => $out, 'message' => 'ok'];
}

/**
 * Custom IMAP keyword set on every message that MailsZo APPENDs as part of
 * a move. The poll functions exclude UIDs carrying this keyword so the
 * appended message does not re-trigger the thread state machine.
 *
 * RFC 3501 keyword syntax — atom (letters/digits, no spaces, no leading `\`).
 * Set on the destination message at APPEND time; never set on regular mail.
 */
const IMAP_DEDUPE_KEYWORD = 'Mailszomoved';

/**
 * APPEND a list of raw RFC 822 messages to a folder (default INBOX) on the
 * given account. Each appended message is tagged with the dedupe keyword
 * (in addition to \Seen) so subsequent polls skip it.
 *
 * Returns ['ok'=>bool, 'appended'=>N, 'message'=>?].
 */
function imapAppendMessages(array $cfg, array $rawMessages, string $folder = 'INBOX', string $extraFlags = ''): array {
    if (!$rawMessages) return ['ok' => true, 'appended' => 0, 'message' => 'no messages'];

    $host = $cfg['host'] ?? '';
    $port = (int)($cfg['port'] ?? 993);
    $user = $cfg['username'] ?? '';
    $pass = $cfg['password'] ?? '';
    $ssl  = (bool)($cfg['ssl'] ?? true);

    $sock = imapSocketOpen($host, $port, $ssl);
    if (!$sock) return ['ok' => false, 'appended' => 0, 'message' => "connect failed {$host}:{$port}"];
    $gr = imapReadLine($sock);
    if (strpos($gr, '* OK') === false && strpos($gr, '* PREAUTH') === false) {
        fclose($sock); return ['ok' => false, 'appended' => 0, 'message' => 'bad greeting'];
    }
    fwrite($sock, 'W01 LOGIN "' . addslashes($user) . '" "' . addslashes($pass) . '"' . "\r\n");
    if (strpos(imapReadResponse($sock, 'W01'), 'W01 OK') === false) {
        fclose($sock); return ['ok' => false, 'appended' => 0, 'message' => 'login failed'];
    }

    $appended = 0;
    $tagN     = 2;
    $folderQ  = '"' . addslashes($folder) . '"';
    // Always tag appended messages with the dedupe keyword so subsequent
    // polls of the destination mailbox skip them. $extraFlags lets the
    // caller add more flags if needed (rare).
    $flagList = trim('\\Seen ' . IMAP_DEDUPE_KEYWORD . ' ' . $extraFlags);

    foreach ($rawMessages as $raw) {
        if (!is_string($raw) || $raw === '') continue;
        // RFC 3501: APPEND mailbox [flags] [date-time] literal
        // Normalize line endings to CRLF — required for IMAP literals.
        $raw  = preg_replace("/(?<!\r)\n/", "\r\n", $raw);
        $size = strlen($raw);
        $tag  = sprintf('W%03d', $tagN++);

        fwrite($sock, "{$tag} APPEND {$folderQ} ({$flagList}) {{$size}}\r\n");
        // Wait for + continuation
        $cont = '';
        $deadline = time() + 15;
        while (!feof($sock) && time() < $deadline) {
            $line = imapReadLine($sock);
            $cont .= $line;
            if ($line === '' || $line === false) { usleep(5000); continue; }
            if ($line[0] === '+') break;
            if (strncmp($line, "{$tag} ", strlen($tag)+1) === 0) {
                // Server rejected before continuation — skip this message
                $cont = '__rejected__';
                break;
            }
        }
        if ($cont === '__rejected__') continue;

        // Send the literal payload + closing CRLF
        fwrite($sock, $raw . "\r\n");
        $resp = imapReadResponse($sock, $tag, 30);
        if (strpos($resp, "{$tag} OK") !== false) $appended++;
    }

    fwrite($sock, "W999 LOGOUT\r\n"); fclose($sock);
    return ['ok' => true, 'appended' => $appended, 'message' => 'ok'];
}

/**
 * Move messages by UID from one IMAP account to another.
 *   1. Fetch raw bodies from src
 *   2. APPEND them to dst INBOX
 *   3. Mark src UIDs \Deleted + EXPUNGE
 *
 * If the same account is passed for both src and dst, this degenerates to a
 * no-op delete (we don't want to duplicate the message in the same mailbox).
 *
 * Returns ['ok'=>bool,'moved'=>N,'fetched'=>N,'appended'=>N,'message'=>?]
 */
function imapMoveUids(array $srcCfg, array $dstCfg, array $uids): array {
    $uids = array_values(array_filter(array_map('intval', $uids), fn($u) => $u > 0));
    if (!$uids) return ['ok'=>true,'moved'=>0,'fetched'=>0,'appended'=>0,'message'=>'no uids'];

    $sameAccount = (
        ($srcCfg['host']     ?? '') === ($dstCfg['host']     ?? '') &&
        ((int)($srcCfg['port']??0)) === ((int)($dstCfg['port']??0)) &&
        ($srcCfg['username'] ?? '') === ($dstCfg['username'] ?? '')
    );
    if ($sameAccount) {
        // No move needed — message already lives in the destination mailbox.
        return ['ok'=>true,'moved'=>0,'fetched'=>0,'appended'=>0,'message'=>'same account, skipped'];
    }

    $fetch = imapFetchRawMessages($srcCfg, $uids);
    if (!$fetch['ok']) return ['ok'=>false,'moved'=>0,'fetched'=>0,'appended'=>0,'message'=>'fetch: '.$fetch['message']];

    $raws = array_values($fetch['messages']);
    if (!$raws) return ['ok'=>false,'moved'=>0,'fetched'=>0,'appended'=>0,'message'=>'no raw bodies fetched'];

    $append = imapAppendMessages($dstCfg, $raws);
    if (!$append['ok'] || $append['appended'] === 0) {
        // Don't delete from source if append failed — better to have duplicates than lose messages
        return ['ok'=>false,'moved'=>0,'fetched'=>count($raws),'appended'=>$append['appended'],'message'=>'append: '.$append['message']];
    }

    // Only delete UIDs whose raw bodies we actually fetched + appended
    $movedUids = array_keys($fetch['messages']);
    if ($append['appended'] < count($raws)) {
        // Partial append — only delete the first N successfully appended UIDs
        $movedUids = array_slice($movedUids, 0, $append['appended']);
    }
    $del = imapDeleteUids($srcCfg, $movedUids);

    return [
        'ok'       => $del['ok'],
        'moved'    => $del['ok'] ? $del['deleted'] : 0,
        'fetched'  => count($raws),
        'appended' => $append['appended'],
        'message'  => $del['ok'] ? 'ok' : ('delete: '.$del['message']),
    ];
}
