<?php
/**
 * One-Time IMAP Cleanup — Purge all old already-processed messages from IMAP accounts.
 *
 * These are messages with UID <= last_uid (already read & stored in inbound_emails)
 * but never deleted because auto-cut didn't exist before.
 *
 * Usage:
 *   php cleanup_old_imap.php              # Dry-run (shows what would be deleted)
 *   php cleanup_old_imap.php --execute    # Actually delete
 */

if (php_sapi_name() !== 'cli') die("CLI only\n");

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/imap.php';

$execute = in_array('--execute', $argv ?? []);

echo "\n══════════════════════════════════════════════════════════\n";
echo "  IMAP ONE-TIME CLEANUP — Purge Old Processed Messages\n";
echo "  Mode: " . ($execute ? "🔴 EXECUTE (will delete!)" : "🟢 DRY-RUN (safe preview)") . "\n";
echo "══════════════════════════════════════════════════════════\n\n";

$accounts = db()->query("SELECT * FROM imap_accounts WHERE status='active'")->fetchAll();

if (empty($accounts)) {
    echo "No active IMAP accounts found.\n";
    exit(0);
}

$totalPurged = 0;

foreach ($accounts as $acc) {
    $iaId     = (int)$acc['id'];
    $iaUser   = $acc['username'];
    $iaHost   = $acc['host'];
    $iaPort   = (int)($acc['port'] ?? 993);
    $iaPass   = $acc['password'];
    $iaSsl    = !empty($acc['ssl']) || !empty($acc['secure']) || $iaPort === 993;
    $lastUid  = (int)($acc['last_uid'] ?? 0);

    echo "── Account: {$iaUser} (#{$iaId}) ──\n";
    echo "   Host: {$iaHost}:{$iaPort} | Last UID: {$lastUid}\n";

    if ($lastUid <= 0) {
        echo "   ⏭ Skipped — last_uid=0, no messages have been processed yet.\n\n";
        continue;
    }

    $cfg = [
        'host'     => $iaHost,
        'port'     => $iaPort,
        'username' => $iaUser,
        'password' => $iaPass,
        'ssl'      => $iaSsl,
    ];

    // Connect and find all UIDs <= last_uid (already processed)
    $sock = imapSocketOpen($iaHost, $iaPort, $iaSsl);
    if (!$sock) {
        echo "   ❌ Cannot connect to {$iaHost}:{$iaPort}\n\n";
        continue;
    }
    $gr = imapReadLine($sock);
    if (strpos($gr, '* OK') === false && strpos($gr, '* PREAUTH') === false) {
        fclose($sock);
        echo "   ❌ Bad server greeting\n\n";
        continue;
    }
    $escUser = str_replace(['\\', '"'], ['\\\\', '\\"'], $iaUser);
    $escPass = str_replace(['\\', '"'], ['\\\\', '\\"'], $iaPass);
    fwrite($sock, 'A01 LOGIN "' . $escUser . '" "' . $escPass . '"' . "\r\n");
    if (strpos(imapReadResponse($sock, 'A01'), 'A01 OK') === false) {
        fclose($sock);
        echo "   ❌ Login failed\n\n";
        continue;
    }
    fwrite($sock, "A02 SELECT INBOX\r\n");
    $selResp = imapReadResponse($sock, 'A02');
    if (strpos($selResp, 'A02 OK') === false) {
        fwrite($sock, "A03 LOGOUT\r\n"); fclose($sock);
        echo "   ❌ Cannot SELECT INBOX\n\n";
        continue;
    }

    $existsCount = 0;
    if (preg_match('/\* (\d+) EXISTS/i', $selResp, $em)) {
        $existsCount = (int)$em[1];
    }

    // Search for UIDs 1:lastUid (already processed, safe to delete)
    fwrite($sock, "A03 UID SEARCH UID 1:{$lastUid}\r\n");
    $searchResp = imapReadResponse($sock, 'A03');
    fwrite($sock, "A04 LOGOUT\r\n");
    fclose($sock);

    $oldUids = [];
    if (preg_match('/\* SEARCH (.+)/i', $searchResp, $sm)) {
        $oldUids = array_values(array_filter(array_map('intval', preg_split('/\s+/', trim($sm[1]))), fn($u) => $u > 0));
    }

    echo "   📊 INBOX exists: {$existsCount} | Old processed UIDs found: " . count($oldUids) . "\n";

    if (empty($oldUids)) {
        echo "   ✅ Clean — no old messages to purge.\n\n";
        continue;
    }

    if (!$execute) {
        echo "   🟡 DRY-RUN: Would delete " . count($oldUids) . " old message(s). Run with --execute to purge.\n\n";
        $totalPurged += count($oldUids);
        continue;
    }

    // Execute deletion
    $del = imapDeleteUids($cfg, $oldUids, 'INBOX');
    if (!empty($del['ok'])) {
        echo "   ✂️ PURGED: " . ($del['deleted'] ?? count($oldUids)) . " old message(s) deleted from IMAP!\n\n";
        $totalPurged += (int)($del['deleted'] ?? count($oldUids));
    } else {
        echo "   ⚠️ Delete failed: " . ($del['message'] ?? 'unknown') . "\n\n";
    }
}

echo "══════════════════════════════════════════════════════════\n";
echo "  Total: {$totalPurged} old message(s) " . ($execute ? "PURGED" : "would be purged (dry-run)") . "\n";
echo "══════════════════════════════════════════════════════════\n\n";

if (!$execute) {
    echo "👆 This was a DRY-RUN. To actually delete, run:\n";
    echo "   php cleanup_old_imap.php --execute\n\n";
}
