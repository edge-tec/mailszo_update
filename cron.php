<?php
/**
 * MailsZo — Cron Worker
 *
 * IMAP workflow (UID-based, via imap.php):
 *  1. Connect to INBOX, fetch messages with UID > last_uid (all new mail,
 *     regardless of \Seen flag — works even if emails were read in webmail).
 *  2. Persist the highest UID seen so next run never re-processes old messages.
 *  3. Enroll each new sender into BOTH queues simultaneously:
 *       Auto-Reply queue  (autoreply_threads)
 *       Follow-Up queue   (followup_contacts)
 *  4. Each queue sends its own step sequence on schedule.
 *
 * One IMAP connection per account, results shared between AR and FU.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/imap.php';

if (php_sapi_name() !== 'cli') {
    $cfg = getConfig();
    if (($_GET['key'] ?? '') !== ($cfg['cron_key'] ?? '')) {
        http_response_code(403); die('Forbidden');
    }
} else {
    // When invoked as a CLI subprocess (proc_open fallback from api.php),
    // the key is passed via environment variable instead of $_GET.
    $envKey = getenv('CRON_KEY');
    if ($envKey !== false) {
        $_GET['key']  = $envKey;
        $_GET['json'] = (getenv('CRON_JSON') !== false) ? '1' : ($_GET['json'] ?? '');
    }
}
if (!isInstalled()) die("Not installed\n");

$lock = sys_get_temp_dir() . '/mailszo_v4.lock';
if (file_exists($lock) && (time() - filemtime($lock)) < 110) {
    if (!empty($_GET['json'])) {
        if (php_sapi_name() !== 'cli') header('Content-Type: application/json');
        echo json_encode(['ok'=>false,'error'=>'Already running','results'=>[]]);
        exit;
    }
    die("Already running\n");
}
file_put_contents($lock, getmypid());
register_shutdown_function(function() use ($lock){ @unlink($lock); });

// Ensure script can continue running even if the client (browser) disconnects early.
ignore_user_abort(true);
set_time_limit(0);

$CRON_START_TIME = time();

$results = [];

// ── Helpers ──────────────────────────────────────────────────────

function parseImageIds($raw): array {
    if (empty($raw)) return [];
    if (is_array($raw)) return array_values(array_filter(array_map('intval',$raw),fn($v)=>$v>0));
    $d=json_decode($raw,true);
    return is_array($d)?array_values(array_filter(array_map('intval',$d),fn($v)=>$v>0)):[];
}

function embedImage(string $html,array $ids,array &$inline,
                    string $w='600',string $align='center',string $pos='top'): string {
    if (!$ids) return $html;
    
    $tags = [];
    foreach ($ids as $id) {
        $s=db()->prepare('SELECT filename,mime,url FROM images WHERE id=?');$s->execute([$id]);
        $img=$s->fetch(PDO::FETCH_ASSOC); if(!$img) continue;
        $filename=$img['filename'];

        // ── Filesystem path resolution ────────────────────────────────────────────
        $dirReal=realpath(__DIR__)?:__DIR__;
        $candidates=[];
        $candidates[]=$dirReal.'/uploads/images/'.$filename;
        $cfg=getConfig();
        if(!empty($cfg['app_path'])){
            $ap=realpath($cfg['app_path'])?:$cfg['app_path'];
            $p=rtrim($ap,'/').'/uploads/images/'.$filename;
            if($p!==$candidates[0])$candidates[]=$p;
        }
        if(!empty($_SERVER['DOCUMENT_ROOT'])){
            $p=rtrim(realpath($_SERVER['DOCUMENT_ROOT'])?:$_SERVER['DOCUMENT_ROOT'],'/').'/uploads/images/'.$filename;
            if(!in_array($p,$candidates))$candidates[]=$p;
        }
        if(!empty($_SERVER['SCRIPT_FILENAME'])){
            $p=(realpath(dirname($_SERVER['SCRIPT_FILENAME']))?:dirname($_SERVER['SCRIPT_FILENAME'])).'/uploads/images/'.$filename;
            if(!in_array($p,$candidates))$candidates[]=$p;
        }

        $path=null;
        foreach($candidates as $c){if(file_exists($c)&&is_readable($c)){$path=$c;break;}}

        // ── HTTP fallback: fetch via stored URL → temp file ───────────────────────
        if(!$path && !empty($img['url']) && filter_var($img['url'],FILTER_VALIDATE_URL)){
            $raw=@file_get_contents($img['url']);
            if($raw!==false && strlen($raw)>0){
                $tmp=tempnam(sys_get_temp_dir(),'mz_img_').'.'
                    .strtolower(pathinfo($filename,PATHINFO_EXTENSION));
                if(@file_put_contents($tmp,$raw)!==false){
                    $path=$tmp;
                    register_shutdown_function(fn()=>@unlink($tmp));
                }
            }
        }

        if(!$path){
            global $results;
            $results[]=['status'=>'img_warn','message'=>"Image #{$id} ({$filename}) not found. Checked: ".implode(', ',$candidates).(empty($img['url'])?'':" | URL fetch also failed: {$img['url']}")];
            continue;
        }

        $ext=strtolower(pathinfo($filename,PATHINFO_EXTENSION));
        $mime=$img['mime']?:(['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png',
            'gif'=>'image/gif','webp'=>'image/webp','svg'=>'image/svg+xml'][$ext]??'image/jpeg');
        $cid='img'.md5($filename.$id).'@mailszo.com';
        $inline[]=['cid'=>$cid,'path'=>$path,'mime'=>$mime];
        
        $ws=is_numeric($w)?"width:{$w}px;max-width:100%;":"width:{$w};max-width:100%;";
        if($align==='left')     {$mL='0';   $mR='auto';}
        elseif($align==='right'){$mL='auto';$mR='0';}
        else                    {$mL='auto';$mR='auto';}
        $tags[] = "<img src=\"cid:$cid\" style=\"{$ws}height:auto;display:block;margin-left:{$mL};margin-right:{$mR};margin-bottom:16px;\" alt=\"\" />";
    }

    if (empty($tags)) return preg_replace('/\{\{image\}\}/i', '', $html);
    $allTags = implode("\n", $tags);
    if (preg_match('/\{\{image\}\}/i', $html)) {
        return preg_replace('/\{\{image\}\}/i', $allTags, $html);
    }
    return $pos === 'bottom' ? $html . '<div style="margin-top:16px">' . $allTags . '</div>'
                            : '<div style="margin-bottom:16px">' . $allTags . '</div>' . $html;
}

function buildMessage(array $step,string $name,string $email,string $defSubj='',string $senderName='',string $todayDate=''): array {
    $subj=spin(!empty($step['subject'])?$step['subject']:$defSubj);
    $html=spin($step['html_body']??'');
    $text=spin($step['text_body']??'')?:strip_tags($html);
    $todayDate = $todayDate ?: date('F j, Y g:i A');
    $subj=personalize($subj,$name,$email,$senderName,$todayDate);
    $html=personalize($html,$name,$email,$senderName,$todayDate);
    $text=personalize($text,$name,$email,$senderName,$todayDate);
    $inline=[];
    $ids=parseImageIds($step['image_ids']??null);
    if($ids)$html=embedImage($html,$ids,$inline,$step['img_width']??'600',$step['img_align']??'center',$step['img_position']??'top');
    return ['subject'=>$subj,'html'=>$html,'text'=>$text,'inlineImages'=>$inline];
}

function getDisplayName(int $uid): string {
    static $c=[];
    if(!isset($c[$uid])){
        try{
            $s=db()->prepare('SELECT meta_value FROM user_meta WHERE user_id=? AND meta_key=?');
            $s->execute([$uid,'display_name']);
            $r=$s->fetch(PDO::FETCH_ASSOC);
            $c[$uid]=($r&&!empty($r['meta_value']))?$r['meta_value']:'';
        }catch(Exception $e){$c[$uid]='';}
    }
    return $c[$uid];
}
function applyDisplayName(array $cfg,int $uid): array {
    $dn=getDisplayName($uid);if($dn!=='')$cfg['from_name']=$dn;return $cfg;
}

// saveToBackup — persists a completed lead into backup_emails.
//
// DUPLICATE LOGIC — USER-LEVEL ONLY (not global / system-wide):
//   The unique key on backup_emails is (user_id, rule_id, email).
//   • Same email + same rule + same user    → ON DUPLICATE KEY: updates
//     the completion timestamp only (lead already backed up for this rule).
//   • Same email + DIFFERENT rule (same user) → new row (rule_id differs).
//   • Same email + DIFFERENT user            → new row (user_id differs).
//
//   ✓ Duplicate prevention is strictly per-user, per-rule.
//   ✗ There is NO system-wide or cross-user duplicate blocking here.
//     UserA and UserB each maintain completely independent backup records
//     for the same lead email address.
function saveToBackup(int $uid, string $email, string $name, string $src, int $rid): void {
    if ($rid <= 0) return; // rule_id is required for the per-rule unique key
    try {
        db()->prepare(
            "INSERT INTO backup_emails(user_id,email,name,source,rule_id,completed_at,first_seen)
             VALUES(?,?,?,?,?,NOW(),NOW())
             ON DUPLICATE KEY UPDATE
               name=COALESCE(NULLIF(VALUES(name),''),name),
               source=VALUES(source),
               completed_at=NOW()"
        )->execute([$uid, $email, $name, $src, $rid]);
    } catch (Exception $e) { /* non-fatal */ }
}

// ─────────────────────────────────────────────────────────────────
// STEP 1 — POLL ALL ACTIVE IMAP ACCOUNTS (WITH OWNER ISOLATION)
//
// Uses UID-based tracking (imap.php) so emails are never missed,
// even if already read/opened in webmail before the cron runs.
// last_uid is persisted per account so each poll only fetches new msgs.
//
// ISOLATION RULES (prevents cross-user lead duplication):
//  • Each IMAP account belongs to exactly one user (imap_accounts.user_id).
//  • Only the owner's rules process messages from that account.
//  • Admin may grant shared access via imap_shared_permissions table.
//  • A process lock prevents two cron workers from reading the same account
//    simultaneously (race condition / duplicate protection).
//  • All read operations are logged to imap_read_log for audit.
// ───────────────────────────────────────────────────────────────────
$imapMessages = []; // [imap_account_id => ['from_email','from_name','subject',...]]

// ── Admin-configurable per-cron-run read cap ───────────────────────────────────────────
// Stored in config.json under imap_read_per_minute. The IMAP fetch
// helpers slice the UID list to this many messages per call, so when the
// cron is scheduled every minute the value is also the per-minute throttle.
// Default 100 matches the previous hardcoded behaviour.
$imapReadCap = (int)(getConfig()['imap_read_per_minute'] ?? 100);
if ($imapReadCap < 1) $imapReadCap = 100;

// ── Server process identifier for locking ───────────────────────────────────────────
// Combines hostname + PID so multi-server deployments can distinguish
// which server holds a lock. Stored in imap_accounts.process_lock_pid.
$cronServerId = gethostname() . ':' . getmypid();

// ── Lock timeout: stale locks older than this are auto-expired ────────────────────
// 5 minutes covers the worst-case IMAP poll time. Any lock older than
// this is considered abandoned (cron crash, OOM kill, etc.).
$lockTimeoutSeconds = 300;

try {
    $activeImaps = db()->query("SELECT * FROM imap_accounts WHERE status='active'")->fetchAll();

    // ── Smart Priority Sorting: Prioritize Gmail and Primary Inboxes ──
    usort($activeImaps, function($a, $b) {
        $aIsGmail = (stripos($a['host'] ?? '', 'gmail') !== false || stripos($a['username'] ?? '', '@gmail.com') !== false);
        $bIsGmail = (stripos($b['host'] ?? '', 'gmail') !== false || stripos($b['username'] ?? '', '@gmail.com') !== false);
        if ($aIsGmail && !$bIsGmail) return -1;
        if (!$aIsGmail && $bIsGmail) return 1;
        return (int)$a['id'] <=> (int)$b['id'];
    });

    foreach ($activeImaps as $ia) {
        if (time() - $CRON_START_TIME > 45) {
            $results[] = ['status'=>'time_limit', 'message'=>'Time limit reached, stopping IMAP processing.'];
            break;
        }
        $iaId      = (int)$ia['id'];
        $iaHost    = $ia['host'];
        $iaPort    = (int)$ia['port'];
        $iaUser    = $ia['username'];
        $iaPass    = $ia['password'];
        $iaSsl     = (bool)$ia['ssl'];
        $iaOwnerId = (int)($ia['user_id'] ?? 0);
        $lastUid   = (int)($ia['last_uid'] ?? 0);
        $prevUidV  = (int)($ia['last_uid_validity'] ?? 0);
        $msgs      = [];
        $newHighUid = $lastUid;
        $fetched    = null;

        // ── Strict Server-Side DAILY IMAP LIMIT & PER-MINUTE READ LIMIT Enforcement ────────
        // daily_send_limit = maximum leads IMAP is allowed to read per day for this user.
        // imap_read_limit  = maximum leads IMAP can read per cron run / per minute.
        $dailyImapLimit   = 1000;
        $userImapReadRate = $imapReadCap; // Default global per-minute setting
        if ($iaOwnerId > 0) {
            $uCapRow = db()->prepare('SELECT imap_read_limit, daily_send_limit FROM users WHERE id=? LIMIT 1');
            $uCapRow->execute([$iaOwnerId]);
            $uCapData = $uCapRow->fetch();
            if ($uCapData) {
                $userDailyLimit = (int)($uCapData['daily_send_limit'] ?? 0);
                if ($userDailyLimit > 0) {
                    $dailyImapLimit = $userDailyLimit;
                }
                $userRateLimit = (int)($uCapData['imap_read_limit'] ?? 0);
                if ($userRateLimit > 0) {
                    $userImapReadRate = $userRateLimit;
                }
            }
        }

        // Count how many IMAP leads have already been read/persisted today for this user
        $todayImapRead = 0;
        if ($iaOwnerId > 0) {
            $todayReadStmt = db()->prepare("SELECT COUNT(*) FROM inbound_emails i JOIN imap_accounts ia ON ia.id=i.imap_account_id WHERE (ia.user_id=? OR ia.id IN (SELECT imap_id FROM autoreply_rules WHERE user_id=? AND imap_id IS NOT NULL UNION SELECT imap2_id FROM autoreply_rules WHERE user_id=? AND imap2_id IS NOT NULL UNION SELECT imap_id FROM followup_rules WHERE user_id=? AND imap_id IS NOT NULL)) AND DATE(i.received_at)=CURDATE()");
            $todayReadStmt->execute([$iaOwnerId, $iaOwnerId, $iaOwnerId, $iaOwnerId]);
            $todayImapRead = (int)$todayReadStmt->fetchColumn();
        } else {
            $todayReadStmt = db()->prepare("SELECT COUNT(*) FROM inbound_emails WHERE imap_account_id=? AND DATE(received_at)=CURDATE()");
            $todayReadStmt->execute([$iaId]);
            $todayImapRead = (int)$todayReadStmt->fetchColumn();
        }

        $remainingImapToday = max(0, $dailyImapLimit - $todayImapRead);
        if ($remainingImapToday <= 0) {
            $results[] = ['status'=>'imap_limit_reached', 'account'=>$iaUser, 'message'=>"DAILY IMAP LIMIT reached for today ({$todayImapRead}/{$dailyImapLimit}). Skipping IMAP poll until midnight reset."];
            continue; // Stop reading IMAP for this account today!
        }

        $effectiveCap = min($userImapReadRate, $remainingImapToday);

        // ── IMAP PROCESS LOCK — prevent concurrent duplicate reads ──────────────────────────────────
        // Attempt to acquire an exclusive lock on this IMAP account.
        // Uses an atomic UPDATE that only succeeds when:
        //   (a) no lock is held (process_lock_at IS NULL), OR
        //   (b) the existing lock has expired (stale crash recovery).
        // If neither condition holds, another cron worker is already reading
        // this account — skip it to prevent duplicate lead insertion.
        $lockAcquired = false;
        try {
            $lockStmt = db()->prepare(
                "UPDATE imap_accounts
                    SET process_lock_at  = NOW(),
                        process_lock_pid = ?
                  WHERE id = ?
                    AND (process_lock_at IS NULL
                         OR process_lock_at < DATE_SUB(NOW(), INTERVAL ? SECOND))"
            );
            $lockStmt->execute([$cronServerId, $iaId, $lockTimeoutSeconds]);
            $lockRows = (int)$lockStmt->rowCount();
            if ($lockRows === 0) {
                // Lock is held by another process — skip to prevent duplicate reads
                $heldBy = $ia['process_lock_pid'] ?? 'unknown';
                $results[] = ['status'=>'imap_skip','account'=>$iaUser,
                    'message'=>"Skipped — IMAP account is locked by another process ({$heldBy}). Duplicate read prevented."];
                // Log to imap_read_log
                try {
                    db()->prepare(
                        "INSERT INTO imap_read_log
                         (imap_account_id, owner_user_id, processing_user_id, is_shared_access,
                          server_pid, messages_found, duplicates_skipped, status, notes, completed_at)
                         VALUES (?, ?, ?, 0, ?, 0, 0, 'locked', 'Process lock held by another worker', NOW())"
                    )->execute([$iaId, $iaOwnerId, $iaOwnerId, $cronServerId]);
                } catch (Exception $_logE) { /* non-fatal */ }
                continue;
            }
            $lockAcquired = true;
        } catch (Exception $lockEx) {
            // Lock columns may not exist yet on first run before migration completes.
            // Fall through — we still process but log a warning.
            $results[] = ['status'=>'imap_warn','account'=>$iaUser,
                'message'=>'Process lock unavailable (migration pending?): '.$lockEx->getMessage()];
        }

        // ── Log start of IMAP read operation ─────────────────────────────────────────────────
        $readLogId = null;
        try {
            db()->prepare(
                "INSERT INTO imap_read_log
                 (imap_account_id, owner_user_id, processing_user_id, is_shared_access,
                  server_pid, messages_found, duplicates_skipped, status, notes)
                 VALUES (?, ?, ?, 0, ?, 0, 0, 'started', 'Owner-only processing')"
            )->execute([$iaId, $iaOwnerId, $iaOwnerId, $cronServerId]);
            $readLogId = db()->lastInsertId();
        } catch (Exception $_logE) { /* non-fatal — imap_read_log may not exist yet */ }

        try {
            if (function_exists('imap_open')) {
                // ── php-imap extension path (uses imap.php UID helpers) ──
                $flags     = $iaSsl ? '/imap/ssl/novalidate-cert' : '/imap/notls/norsh';
                $mboxRef   = '{' . $iaHost . ':' . $iaPort . $flags . '}INBOX';
                $mbox      = @imap_open($mboxRef, $iaUser, $iaPass, 0, 1);

                if (!$mbox) {
                    $results[] = ['status'=>'imap_err','account'=>$iaUser,
                        'message'=>'Cannot open mailbox — check host/port/SSL/credentials'];
                } else {
                    $fetched    = imapExtFetchSinceUid($mbox, $lastUid, $prevUidV, $mboxRef, $effectiveCap);
                    $msgs       = $fetched['messages'];
                    $newHighUid = max($lastUid, $fetched['highestUid']);
                    @imap_close($mbox);
                }

            } else {
                // ── Raw socket path (uses imap.php UID helpers) ──────────────
                $fetched    = imapFetchSinceUid($iaHost, $iaPort, $iaUser, $iaPass, $iaSsl, $lastUid, $prevUidV, $effectiveCap);
                $msgs       = $fetched['messages'];
                $newHighUid = max($lastUid, $fetched['highestUid']);

                if (!$fetched['connected']) {
                    $results[] = ['status'=>'imap_err','account'=>$iaUser,
                        'message'=>'Cannot connect or login — check host/port/SSL/credentials'];
                }
            }

            if ($fetched && !empty($fetched['reset'])) {
                $results[] = ['status'=>'imap_warn','account'=>$iaUser,
                    'message'=>'UID state reset (' . $fetched['reset'] . ') — rescanning mailbox from scratch'];
            }

            $curUidV = $fetched ? (int)($fetched['uidValidity'] ?? 0) : 0;
            if ($newHighUid > $lastUid) {
                db()->prepare("UPDATE imap_accounts SET last_uid=?, last_uid_validity=?, last_check=NOW(), emails_read=emails_read+? WHERE id=?")
                    ->execute([$newHighUid, $curUidV ?: $prevUidV, count($msgs), $iaId]);
            } elseif ($fetched && !empty($fetched['reset'])) {
                db()->prepare("UPDATE imap_accounts SET last_uid=?, last_uid_validity=?, last_check=NOW() WHERE id=?")
                    ->execute([$newHighUid, $curUidV ?: $prevUidV, $iaId]);
            } elseif ($curUidV > 0 && $curUidV !== $prevUidV) {
                db()->prepare("UPDATE imap_accounts SET last_uid_validity=?, last_check=NOW() WHERE id=?")
                    ->execute([$curUidV, $iaId]);
            } else {
                db()->prepare("UPDATE imap_accounts SET last_check=NOW() WHERE id=?")
                    ->execute([$iaId]);
            }

        } catch (Exception $innerE) {
            $results[] = ['status'=>'imap_err','account'=>$iaUser,
                'message'=>'IMAP fetch error: ' . $innerE->getMessage()];
        }

        // ── Domain Blacklist Filter — applied BEFORE inbound persistence ─────────────────────────
        // Emails from blacklisted domains/extensions are completely ignored:
        // they are NOT stored in inbound_emails, NOT processed by AR/FU rules,
        // and NO reply is ever sent to them. This fulfils requirements:
        //   • Emails from blacklisted domains must NOT be processed, stored, or forwarded.
        //   • The system must NOT send any messages for blacklisted domains.
        //   • Blacklisted domain emails are completely ignored by IMAP/email processing flow.
        //
        // isBlacklisted() already handles TLD/extension entries stored as ".com", "com", etc.
        // via suffix-variant matching, so this one call covers both domain and extension blacklists.
        if (!empty($msgs) && $iaOwnerId > 0) {
            $domainFilteredCount = 0;
            $msgs = array_filter($msgs, function($m) use ($iaOwnerId, &$domainFilteredCount) {
                $fe = strtolower(trim((string)($m['from_email'] ?? '')));
                if ($fe === '' || !filter_var($fe, FILTER_VALIDATE_EMAIL)) return true; // let later code handle invalid addresses
                if (isBlacklisted($fe, $iaOwnerId)) {
                    $domainFilteredCount++;
                    return false; // drop — completely ignored
                }
                return true;
            });
            $msgs = array_values($msgs);
            if ($domainFilteredCount > 0) {
                $results[] = ['status'=>'imap_info','account'=>$iaUser,
                    'message'=>"Domain blacklist: {$domainFilteredCount} message(s) from blacklisted domains/extensions silently dropped before storage."];
            }
        }

        // ── Mandatory persistence: every read message is written to inbound_emails ──
        // ON DUPLICATE KEY (imap_account_id, uid_validity, uid) keeps re-runs safe.
        // Per-message duplicate check added for extra safety: if a record already
        // exists (e.g. from a concurrent run that slipped past the lock), skip it.
        $curUidV = $fetched ? (int)($fetched['uidValidity'] ?? 0) : 0;
        $persisted = 0; $persistErr = 0; $duplicatesSkipped = 0;
        foreach ($msgs as $m) {
            $mUid = (int)($m['uid'] ?? 0);
            $mFE  = strtolower(trim((string)($m['from_email'] ?? '')));
            if ($mFE === '') continue;
            try {
                // ── Per-message duplicate check before insert ───────────────────────────
                // Checks if this exact message already exists before inserting.
                // The ON DUPLICATE KEY below also handles this, but an explicit
                // check lets us count true duplicates for the audit log.
                $dupChk = db()->prepare(
                    "SELECT COUNT(*) FROM inbound_emails WHERE imap_account_id=? AND uid=? AND uid_validity=?"
                );
                $dupChk->execute([$iaId, $mUid, $curUidV]);
                if ((int)$dupChk->fetchColumn() > 0) {
                    $duplicatesSkipped++;
                    continue; // Already persisted — duplicate protection active
                }

                $mMsgId = substr(trim((string)($m['message_id'] ?? '')), 0, 255);
                $mIrt   = substr(trim((string)($m['in_reply_to'] ?? '')), 0, 255);
                $mRef   = trim((string)($m['references'] ?? ''));
                $mSubj  = substr((string)($m['subject'] ?? ''), 0, 500);
                $mThId  = resolveConversationThreadId($mMsgId, $mIrt, $mRef, $mFE, $mSubj);

                db()->prepare(
                    "INSERT INTO inbound_emails
                     (imap_account_id, uid, uid_validity, from_email, from_name, subject, message_id, in_reply_to, references_header, thread_id, received_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE
                       from_email = VALUES(from_email),
                       from_name  = COALESCE(NULLIF(VALUES(from_name), ''), from_name),
                       subject    = COALESCE(NULLIF(VALUES(subject),   ''), subject),
                       message_id = COALESCE(NULLIF(VALUES(message_id), ''), message_id),
                       in_reply_to = COALESCE(NULLIF(VALUES(in_reply_to), ''), in_reply_to),
                       references_header = COALESCE(NULLIF(VALUES(references_header), ''), references_header),
                       thread_id  = COALESCE(NULLIF(VALUES(thread_id), ''), thread_id)"
                )->execute([
                    $iaId,
                    $mUid,
                    $curUidV,
                    substr($mFE, 0, 255),
                    substr((string)($m['from_name'] ?? ''), 0, 255),
                    $mSubj,
                    $mMsgId ?: null,
                    $mIrt ?: null,
                    $mRef ?: null,
                    $mThId ?: null,
                ]);
                $persisted++;
            } catch (Exception $insE) {
                $persistErr++;
                $results[] = ['status'=>'imap_warn','account'=>$iaUser,
                    'message'=>'inbound persist failed uid=' . $mUid . ': ' . $insE->getMessage()];
            }
        }

        // ── Auto-delete from IMAP after successful read + persistence ─────────────────────────────
        $autoDeleted = 0; $autoDelErr = '';
        if (!empty($msgs) && function_exists('imapDeleteUids')) {
            $uidsToDelete = array_values(array_filter(
                array_map(fn($m) => (int)($m['uid'] ?? 0), $msgs),
                fn($u) => $u > 0
            ));
            if ($uidsToDelete) {
                $cfg = [
                    'host'     => $iaHost,
                    'port'     => $iaPort,
                    'username' => $iaUser,
                    'password' => $iaPass,
                    'ssl'      => $iaSsl,
                ];
                try {
                    $del = imapDeleteUids($cfg, $uidsToDelete);
                    $autoDeleted = (int)($del['deleted'] ?? 0);
                    if (!$del['ok']) {
                        $autoDelErr = (string)($del['message'] ?? 'unknown');
                        $results[] = ['status'=>'imap_warn','account'=>$iaUser,
                            'message'=>'Auto-delete failed: ' . $autoDelErr];
                    }
                } catch (Exception $delEx) {
                    $autoDelErr = $delEx->getMessage();
                    $results[] = ['status'=>'imap_warn','account'=>$iaUser,
                        'message'=>'Auto-delete exception: ' . $autoDelErr];
                }
            }
        }

        // ── Release the process lock ───────────────────────────────────────────────────
        if ($lockAcquired) {
            try {
                db()->prepare(
                    "UPDATE imap_accounts
                        SET process_lock_at  = NULL,
                            process_lock_pid = NULL
                      WHERE id = ? AND process_lock_pid = ?"
                )->execute([$iaId, $cronServerId]);
            } catch (Exception $_unlockE) { /* non-fatal */ }
        }

        // ── Complete the imap_read_log entry ──────────────────────────────────────────────────────
        if ($readLogId) {
            try {
                db()->prepare(
                    "UPDATE imap_read_log
                        SET messages_found     = ?,
                            duplicates_skipped = ?,
                            status             = 'completed',
                            notes              = ?,
                            completed_at       = NOW()
                      WHERE id = ?"
                )->execute([
                    count($msgs),
                    $duplicatesSkipped,
                    "owner={$iaOwnerId} | server={$cronServerId} | persisted={$persisted} | auto_deleted={$autoDeleted}" . ($autoDelErr ? " | del_err={$autoDelErr}" : ''),
                    $readLogId,
                ]);
            } catch (Exception $_logE) { /* non-fatal */ }
        }

        $imapMessages[$iaId] = $msgs;
        $results[] = ['status'=>'imap_ok','account'=>$iaUser,'found'=>count($msgs),
            'auto_deleted'      => $autoDeleted,
            'persisted'         => $persisted,
            'persist_err'       => $persistErr,
            'duplicates_skipped'=> $duplicatesSkipped,
            'owner_user_id'     => $iaOwnerId,
            'shared_access'     => false,
            'server_pid'        => $cronServerId,
            'uid_was'           => $lastUid,
            'uid_now'           => $newHighUid,
            'uid_validity'      => $fetched['uidValidity'] ?? 0,
            'uid_next'          => $fetched['uidNext']     ?? 0,
            'exists'            => $fetched['existsCount'] ?? 0,
            'reset'             => $fetched['reset']       ?? null,
            'emails'            => array_column($msgs, 'from_email')];
    }
} catch(Exception $e){
    $results[]=['status'=>'error','message'=>'IMAP poll error: ' . $e->getMessage()];
}
// ── Diagnostic: IMAP read messages but a user has no AR/FU rules ─────
// When operators see "10 emails read, 0 leads, 0 AR, 0 FU" the cause is
// almost always missing or paused rules for the user that owns the IMAP.
// Surface this once per user so it shows up in the cron log instead of
// being invisible.
try {
    $msgsByOwner = [];
    foreach ($imapMessages as $iaId => $_ms) {
        if (empty($_ms)) continue;
        $owner = 0;
        try {
            $oq = db()->prepare("SELECT user_id FROM imap_accounts WHERE id=?");
            $oq->execute([$iaId]);
            $owner = (int)$oq->fetchColumn();
        } catch (Exception $_e) {}
        if ($owner > 0) $msgsByOwner[$owner] = ($msgsByOwner[$owner] ?? 0) + count($_ms);
    }
    foreach ($msgsByOwner as $ownerUid => $cnt) {
        $arCount = (int)db()->query("SELECT COUNT(*) FROM autoreply_rules WHERE user_id={$ownerUid} AND status='active'")->fetchColumn();
        $fuCount = (int)db()->query("SELECT COUNT(*) FROM followup_rules  WHERE user_id={$ownerUid} AND status='active'")->fetchColumn();
        if ($arCount === 0 || $fuCount === 0) {
            $missing = [];
            if ($arCount === 0) $missing[] = 'auto-reply';
            if ($fuCount === 0) $missing[] = 'follow-up';
            $results[] = ['status'=>'imap_warn','account'=>"user_id={$ownerUid}",
                'message'=>"{$cnt} email(s) read but no active ".implode(' or ', $missing)." rule for this user — emails will not be enrolled until a rule is created and activated."];
        }
    }
} catch (Exception $_diagE) { /* diagnostics only — never block the cron */ }


// ─────────────────────────────────────────────────────────────────
// STEP 2 — CAMPAIGNS
//
// Flow per campaign:
//   1. Fetch all scheduled/running campaigns whose scheduled_at is due.
//   2. Guard: must have a list_id, at least one variant, at least one SMTP.
//   3. daily_limit=0 means unlimited.  Count only today's 'sent' rows.
//   4. Fetch unsent recipients via LEFT JOIN (no send_log row for this campaign).
//   5. Round-robin SMTP, optional from-email pool, applyDisplayName.
//   6. Send via Mailer → log to send_logs (sent or failed).
//   7. After batch: 'scheduled' if more remain, 'completed' if all done.
// ─────────────────────────────────────────────────────────────────
try {
    $campNow = date('Y-m-d H:i:s');

    // Include both 'scheduled' and 'running' so stuck campaigns are never abandoned.
    $campStmt = db()->prepare(
        "SELECT c.*, u.status AS u_status, u.expires_at AS u_expires
           FROM campaigns c
           JOIN users u ON u.id = c.user_id
          WHERE c.status IN ('scheduled','running')
            AND (c.scheduled_at IS NULL OR c.scheduled_at <= ?)
            AND u.status = 'active'
          ORDER BY c.id ASC"
    );
    $campStmt->execute([$campNow]);
    $allCampaigns = $campStmt->fetchAll();

    if (empty($allCampaigns)) {
        $results[] = ['status' => 'info', 'message' => 'No scheduled campaigns'];
    }

    foreach ($allCampaigns as $camp) {

        if (time() - $CRON_START_TIME > 45) {
            $results[] = ['status'=>'time_limit', 'message'=>'Time limit reached, stopping campaign processing.'];
            break;
        }
        $cid   = (int)$camp['id'];
        $cName = (string)$camp['name'];
        $cUid  = (int)$camp['user_id'];

        // ── Guard: expired user account ──────────────────────────────
        if (!empty($camp['u_expires']) && strtotime($camp['u_expires']) < time()) {
            db()->prepare("UPDATE campaigns SET status='scheduled' WHERE id=?")->execute([$cid]);
            $results[] = ['status' => 'skip', 'campaign' => $cName, 'message' => 'User account expired'];
            continue;
        }

        // ── Guard: must have a list attached ─────────────────────────
        if (empty($camp['list_id'])) {
            db()->prepare("UPDATE campaigns SET status='failed' WHERE id=?")->execute([$cid]);
            $results[] = ['status' => 'error', 'campaign' => $cName, 'message' => 'No email list attached to campaign'];
            continue;
        }

        // ── Guard: must have at least one variant ────────────────────
        $variants = [];
        if (!empty($camp['variants'])) {
            $vDec = json_decode($camp['variants'], true);
            if (is_array($vDec) && count($vDec) > 0) {
                $variants = $vDec;
            }
        }
        if (empty($variants)) {
            db()->prepare("UPDATE campaigns SET status='failed' WHERE id=?")->execute([$cid]);
            $results[] = ['status' => 'error', 'campaign' => $cName, 'message' => 'No email variants configured'];
            continue;
        }

        // ── Build SMTP pool ──────────────────────────────────────────
        $smtpIds = [];
        if (!empty($camp['smtp_ids'])) {
            $sDec = json_decode($camp['smtp_ids'], true);
            if (is_array($sDec)) {
                $smtpIds = array_values(array_filter(array_map('intval', $sDec)));
            }
        }
        if (empty($smtpIds) && !empty($camp['smtp_id'])) {
            $smtpIds = [(int)$camp['smtp_id']];
        }
        if (empty($smtpIds)) {
            db()->prepare("UPDATE campaigns SET status='failed' WHERE id=?")->execute([$cid]);
            $results[] = ['status' => 'error', 'campaign' => $cName, 'message' => 'No SMTP server configured'];
            continue;
        }
        $smtpPh   = implode(',', array_fill(0, count($smtpIds), '?'));
        $smtpSt   = db()->prepare("SELECT * FROM smtp_providers WHERE id IN ($smtpPh)");
        $smtpSt->execute($smtpIds);
        $smtpPool = $smtpSt->fetchAll();
        if (empty($smtpPool)) {
            db()->prepare("UPDATE campaigns SET status='failed' WHERE id=?")->execute([$cid]);
            $results[] = ['status' => 'error', 'campaign' => $cName, 'message' => 'SMTP server(s) not found — may have been deleted'];
            continue;
        }

        // ── Optional from-email override pool ────────────────────────
        $fromPool = [];
        if (!empty($camp['from_emails'])) {
            $fDec = json_decode($camp['from_emails'], true);
            if (is_array($fDec)) $fromPool = $fDec;
        }

        // Mark running so the dashboard shows live status
        db()->prepare("UPDATE campaigns SET status='running' WHERE id=?")->execute([$cid]);

        // ── Daily limit (0 = unlimited) ──────────────────────────────
        $dailyLimit = (int)$camp['daily_limit'];
        if ($dailyLimit <= 0) $dailyLimit = PHP_INT_MAX;

        $dSt = db()->prepare(
            "SELECT COUNT(*) FROM send_logs
              WHERE campaign_id = ? AND status = 'sent' AND DATE(sent_at) = CURDATE()"
        );
        $dSt->execute([$cid]);
        $sentToday = (int)$dSt->fetchColumn();

        if ($sentToday >= $dailyLimit) {
            db()->prepare("UPDATE campaigns SET status='scheduled' WHERE id=?")->execute([$cid]);
            $results[] = ['status' => 'info', 'campaign' => $cName, 'message' => 'Daily send limit reached — will resume tomorrow'];
            continue;
        }

        // ── Fetch unsent recipients ───────────────────────────────────
        $batchSize = (int)$camp['per_minute_limit'];
        if ($batchSize <= 0)  $batchSize = 10;
        if ($batchSize > 300) $batchSize = 300;
        // Cap batch to what remains under the daily limit
        $canSend   = min($batchSize, $dailyLimit - $sentToday);

        $recSt = db()->prepare(
            "SELECT e.email, e.name
               FROM emails e
               LEFT JOIN send_logs sl
                      ON sl.email = e.email
                     AND sl.campaign_id = ?
              WHERE e.list_id = ?
                AND e.status  = 'active'
                AND sl.id IS NULL
              LIMIT $canSend"
        );
        $recSt->execute([$cid, (int)$camp['list_id']]);
        $recipients = $recSt->fetchAll();

        if (empty($recipients)) {
            db()->prepare("UPDATE campaigns SET status='completed' WHERE id=?")->execute([$cid]);
            $results[] = ['status' => 'completed', 'campaign' => $cName, 'message' => 'All recipients have been sent'];
            continue;
        }

        // ── Send loop ─────────────────────────────────────────────────
        $sent    = 0;
        $failed  = 0;
        $poolSz  = count($smtpPool);

        foreach ($recipients as $recipient) {

            // Re-check daily cap mid-batch (in case another process ran concurrently)
            if ($sentToday + $sent >= $dailyLimit) break;

            // Blacklist enforcement at campaign send. The recipient list is
            // typically opt-in but the operator may have added an address /
            // domain / TLD to the blacklist after the list was uploaded —
            // never bypass that. We log a 'failed' send_log row with a
            // BLACKLISTED error_code so (a) the LEFT JOIN at the top of this
            // step never re-fetches the same recipient, and (b) the operator
            // sees the skip in All Send Logs.
            if (isBlacklisted($recipient['email'], $cUid)) {
                try {
                    db()->prepare(
                        "INSERT INTO send_logs
                         (campaign_id, user_id, email, status, log_source,
                          error_code, error)
                         VALUES (?, ?, ?, 'failed', 'campaign', 'BLACKLISTED',
                                 'Recipient is on the blacklist — skipped')"
                    )->execute([$cid, $cUid, $recipient['email']]);
                } catch (Exception $_blLogEx) {}
                $failed++;
                continue;
            }

            // Round-robin across the SMTP pool
            $mailerCfg     = $smtpPool[($sentToday + $sent) % $poolSz];
            $fromEmailUsed = $mailerCfg['from_email'] ?? '';

            // Apply from-email override pool
            if (!empty($fromPool)) {
                $pick = $fromPool[array_rand($fromPool)];
                if (is_array($pick)) {
                    $mailerCfg['from_email'] = $pick['email'] ?? $mailerCfg['from_email'];
                    $mailerCfg['from_name']  = $pick['name']  ?? ($mailerCfg['from_name'] ?? '');
                } else {
                    $mailerCfg['from_email'] = (string)$pick;
                }
                $fromEmailUsed = $mailerCfg['from_email'];
            }

            // Apply user display-name override last so it always wins
            $mailerCfg   = applyDisplayName($mailerCfg, $cUid);

            // Campaign-level sender_name has highest priority — if set, override everything
            $campSenderName = trim($camp['sender_name'] ?? '');
            if ($campSenderName !== '') {
                $mailerCfg['from_name'] = $campSenderName;
            }

            $smtpNameLog = $mailerCfg['name'] ?? '';

            // Pick a random variant and build the full message
            $vi      = array_rand($variants);
            $message = buildMessage(
                $variants[$vi],
                $recipient['name'] ?? '',
                $recipient['email'],
                '',
                $mailerCfg['from_name'] ?? '',
                date('F j, Y g:i A')
            );

            $campTrackingToken = generateTrackingToken();

            try {
                (new Mailer($mailerCfg))->send(
                    $recipient['email'],
                    $recipient['name'] ?? '',
                    $message['subject'],
                    $message['html'],
                    $message['text'],
                    $message['inlineImages'],
                    [
                        'tracking_token' => $campTrackingToken,
                        'track_clicks'   => true,
                    ]
                );

                db()->prepare(
                    "INSERT INTO send_logs
                     (campaign_id, user_id, email, status, log_source,
                      smtp_name_used, from_email_used, variant_index)
                     VALUES (?, ?, ?, 'sent', 'campaign', ?, ?, ?)"
                )->execute([$cid, $cUid, $recipient['email'], $smtpNameLog, $fromEmailUsed, $vi]);

                logSystemEvent('sent', $recipient['email'], "Campaign '{$cName}' email sent", $cUid, $cid, null, null, $campTrackingToken, $smtpNameLog);

                // If campaign is linked to a Follow-Up rule, enqueue Step 1 as 'scheduled' (sends after delay whether read or not)
                if (!empty($camp['followup_rule_id'])) {
                    $fuRuleId = (int)$camp['followup_rule_id'];
                    $s1Query = db()->prepare("SELECT delay_value, delay_unit, delay_minutes FROM followup_steps WHERE rule_id = ? ORDER BY step_number ASC LIMIT 1");
                    $s1Query->execute([$fuRuleId]);
                    $s1Data = $s1Query->fetch();
                    if ($s1Data) {
                        $s1DelayVal = max(0, (int)($s1Data['delay_value'] ?? $s1Data['delay_minutes'] ?? 30));
                        $s1DelayUnit = in_array(strtolower($s1Data['delay_unit'] ?? ''), ['minutes','hours','days'], true) ? strtolower($s1Data['delay_unit']) : 'minutes';
                        $s1DelayMins = delayToMinutes($s1DelayVal, $s1DelayUnit);
                        $s1SchedAt = date('Y-m-d H:i:s', strtotime("+{$s1DelayMins} minutes"));

                        $insQ = db()->prepare(
                            "INSERT INTO email_followup_queue
                             (user_id, campaign_id, rule_id, recipient_email, recipient_name, followup_order, delay_value, delay_unit, delay_in_minutes, scheduled_at, status, tracking_token)
                             VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, 'scheduled', ?)"
                        );
                        $insQ->execute([
                            $cUid, $cid, $fuRuleId, $recipient['email'], $recipient['name'] ?? '',
                            $s1DelayVal, $s1DelayUnit, $s1DelayMins, $s1SchedAt, $campTrackingToken
                        ]);
                        logSystemEvent('queued', $recipient['email'], "Follow-up #1 scheduled for {$s1SchedAt} (+{$s1DelayVal} {$s1DelayUnit})", $cUid, $cid, $fuRuleId, db()->lastInsertId(), $campTrackingToken);
                    }
                }

                $sent++;

            } catch (Exception $sendEx) {

                $errMsg  = $sendEx->getMessage();
                $errCode = 'SMTP_ERR';
                if      (stripos($errMsg, 'auth')        !== false || stripos($errMsg, 'credentials') !== false || stripos($errMsg, '535') !== false) $errCode = 'AUTH_FAIL';
                elseif  (stripos($errMsg, 'recipient')   !== false || stripos($errMsg, 'RCPT')        !== false || stripos($errMsg, '550') !== false
                      || stripos($errMsg, '551')         !== false || stripos($errMsg, '553')         !== false)                                      $errCode = 'RCPT_REJECT';
                elseif  (stripos($errMsg, 'connect')     !== false || stripos($errMsg, 'refused')     !== false || stripos($errMsg, 'timed out') !== false) $errCode = 'CONN_FAIL';
                elseif  (stripos($errMsg, 'sender')      !== false || stripos($errMsg, 'MAIL FROM')   !== false)                                      $errCode = 'SENDER_REJECT';
                elseif  (stripos($errMsg, 'DATA')        !== false || stripos($errMsg, 'send failed') !== false)                                      $errCode = 'DATA_ERR';
                elseif  (stripos($errMsg, 'rate')        !== false || stripos($errMsg, 'quota')       !== false || stripos($errMsg, '452') !== false)  $errCode = 'RATE_LIMIT';

                db()->prepare(
                    "INSERT INTO send_logs
                     (campaign_id, user_id, email, status, log_source,
                      smtp_name_used, from_email_used, error_code, error, variant_index)
                     VALUES (?, ?, ?, 'failed', 'campaign', ?, ?, ?, ?, ?)"
                )->execute([$cid, $cUid, $recipient['email'], $smtpNameLog, $fromEmailUsed, $errCode, substr($errMsg, 0, 500), $vi]);

                $failed++;
            }
        }

        // ── Persist counters ──────────────────────────────────────────
        db()->prepare(
            "UPDATE campaigns SET sent_count = sent_count + ?, failed_count = failed_count + ? WHERE id = ?"
        )->execute([$sent, $failed, $cid]);

        // ── Determine final status ────────────────────────────────────
        $remSt = db()->prepare(
            "SELECT COUNT(*) FROM emails e
               LEFT JOIN send_logs sl
                      ON sl.email = e.email
                     AND sl.campaign_id = ?
              WHERE e.list_id = ?
                AND e.status  = 'active'
                AND sl.id IS NULL"
        );
        $remSt->execute([$cid, (int)$camp['list_id']]);
        $remaining = (int)$remSt->fetchColumn();

        $finalStatus = ($remaining > 0) ? 'scheduled' : 'completed';
        db()->prepare("UPDATE campaigns SET status = ? WHERE id = ?")->execute([$finalStatus, $cid]);

        $results[] = [
            'status'    => 'ok',
            'campaign'  => $cName,
            'sent'      => $sent,
            'failed'    => $failed,
            'remaining' => $remaining,
        ];
    }

} catch (Exception $e) {
    $results[] = ['status' => 'error', 'message' => 'Campaign error: ' . $e->getMessage()];
}


// ─────────────────────────────────────────────────────────────────
// STEP 3 — AUTO-REPLY: ENROLL BOTH QUEUES + SEND
//
// For each AR rule: get its IMAP account's new messages from $imapMessages,
// enroll each sender into autoreply_threads (due NOW = send immediately),
// then send all due steps.
// ─────────────────────────────────────────────────────────────────
try {
    $arNow=date('Y-m-d H:i:s');
    $arNowTs=time();

    // Resolve all IMAP account configs once so the AR loop can run
    // post-actions (delete on IMAP 1, move IMAP 1 → IMAP 2) without
    // re-querying the DB per thread. Also build an owner map so AR/FU
    // enrollment can match messages to rules by user_id instead of by
    // the often-misconfigured rule.imap_id column.
    // MAILSZO_IMAP_ENROLL_V3 — grep marker. If `grep MAILSZO_IMAP_ENROLL_V3
    // /path/to/cron.php` on the server prints a hit, this patched version
    // is the one running. If it prints nothing, the deployed cron.php is
    // stale and no fix in this file can take effect until you re-upload.
    //
    // SELECT * avoids any MariaDB reserved-word collision (`ssl`, `password`).
    $imapCfgById   = [];
    $imapOwnerById = [];
    foreach (db()->query("SELECT * FROM imap_accounts")->fetchAll() as $ic) {
        $imapCfgById[(int)$ic['id']]   = $ic;
        $imapOwnerById[(int)$ic['id']] = (int)($ic['user_id'] ?? 0);
    }

    $arRules=db()->query(
        "SELECT r.*,u.status u_status,u.expires_at u_expires, u.assigned_imap_ids u_assigned_imap_ids, u.assigned_smtp_ids u_assigned_smtp_ids
         FROM autoreply_rules r
         JOIN users u ON u.id=r.user_id
         WHERE r.status='active' AND u.status='active'")->fetchAll();

    foreach($arRules as $rule){
        // ── Per-rule fault isolation ────────────────────────────────────────
        // Without this, an exception from ANY message or post-action inside this
        // loop body propagates all the way up to the Step 3 try/catch and aborts
        // every remaining rule for the rest of this poll. That was the structural
        // cause of "the email is added to FU but not AR": the AR INSERT (which
        // references columns that may be missing on legacy MySQL) threw, the
        // outer catch swallowed it, and no further AR enrollment happened —
        // while the independent FU step kept working.
        try {
        if(!empty($rule['u_expires'])&&strtotime($rule['u_expires'])<time())continue;
        $ruleId=(int)$rule['id'];$userId=(int)$rule['user_id'];
        $imap1Id=(int)($rule['imap_id']??0);
        $imap2Id=(int)($rule['imap2_id']??0);
        $isSeq=!empty($rule['sequential_mode']);
        $twoImap = ($imap1Id > 0 && $imap2Id > 0 && $imap1Id !== $imap2Id);
        $enrolledAR=0;

        // ── Gather inbound messages: owner-match first, fallback to all ──
        // 1) Preferred: messages from IMAPs whose user_id matches this rule's
        //    user_id (correct multi-tenant behaviour).
        // ── ISOLATED IMAP message gathering (owner + admin-shared only) ──────
        // ISOLATION: Only process messages from IMAP accounts that belong to
        // this rule's user_id, OR accounts where the admin has explicitly
        // granted this user shared access via imap_shared_permissions.
        //
        // The legacy "fallback to all IMAPs" behaviour has been REMOVED because
        // it was the root cause of cross-user lead duplication: when UserA had
        // no IMAP messages but UserB did, UserA's rules would enroll UserB's
        // leads — creating duplicate leads across multiple users.
        //
        // If owner-match yields 0 results, we now check admin-granted shared
        // permissions instead of blindly enrolling from all accounts.
        // The original imap_id / imap2_id columns are still used for the
        // post-send delete + move workflow (via $trigImapId).

        // Build the set of IMAP account IDs this user is allowed to process:
        // 1. Accounts owned by this user
        // 2. Accounts assigned by admin (assigned_imap_ids)
        // 3. Accounts where admin granted shared access via imap_shared_permissions
        $allowedImapIds = [];
        foreach ($imapOwnerById as $_iaId => $_ownerId) {
            if ($_ownerId === $userId) $allowedImapIds[] = (int)$_iaId;
        }
        
        if (!empty($rule['u_assigned_imap_ids'])) {
            $sharedIds = json_decode($rule['u_assigned_imap_ids'], true);
            if (is_array($sharedIds)) {
                foreach ($sharedIds as $sharedIaId) {
                    $sharedIaId = (int)$sharedIaId;
                    if (!in_array($sharedIaId, $allowedImapIds, true)) {
                        $allowedImapIds[] = $sharedIaId;
                    }
                }
            }
        }
        
        try {
            $sharedStmt = db()->prepare(
                "SELECT imap_account_id, owner_user_id FROM imap_shared_permissions WHERE grantee_user_id = ?"
            );
            $sharedStmt->execute([$userId]);
            foreach ($sharedStmt->fetchAll() as $sharedRow) {
                $sharedIaId = (int)$sharedRow['imap_account_id'];
                if (!in_array($sharedIaId, $allowedImapIds, true)) {
                    $allowedImapIds[] = $sharedIaId;
                }
            }
        } catch (Exception $_shareEx) { /* imap_shared_permissions may not exist yet — silent */ }

        $newMsgs = [];
        foreach ($imapMessages as $iaId => $msgs) {
            if (!in_array((int)$iaId, $allowedImapIds, true)) continue;
            foreach ($msgs as $m) {
                $m['source_imap_id'] = $iaId;
                $newMsgs[] = $m;
            }
        }
         $isSmart = !empty($rule['enable_smart_routing']);
        $primaryImapId = (int)($rule['primary_imap_id'] ?? $rule['imap_id'] ?? 0);
        $secondaryImapId = (int)($rule['secondary_imap_id'] ?? $rule['imap2_id'] ?? 0);
        $backupImapId = (int)($rule['backup_imap_id'] ?? 0);
        $primarySmtpId = (int)($rule['primary_smtp_id'] ?? 0);
        $secondarySmtpId = (int)($rule['secondary_smtp_id'] ?? 0);
        $replyToSwitch = !isset($rule['enable_reply_to_switch']) || (int)$rule['enable_reply_to_switch'] === 1;
        $fuRuleId = (int)($rule['followup_rule_id'] ?? 0);

        foreach($newMsgs as $msg){
            $fe=strtolower(trim($msg['from_email']??''));
            $fn=trim($msg['from_name']??'');
            $fs=trim($msg['subject']??'');
            $uid=(int)($msg['uid']??0);
            $srcId=(int)($msg['source_imap_id']??0);
            $inMsgId=trim($msg['message_id']??'');
            $inIrt=trim($msg['in_reply_to']??'');
            $inRef=trim($msg['references']??'');
            if(!$fe||!filter_var($fe,FILTER_VALIDATE_EMAIL))continue;
            if(isBlacklisted($fe,$userId))continue;
            if (isMessageBlocked($userId, $fs, $fe, $fn)) continue;

            try {
            $thId = resolveConversationThreadId($inMsgId, $inIrt, $inRef, $fe, $fs);
            $th=db()->prepare("SELECT * FROM autoreply_threads WHERE rule_id=? AND from_email=?");
            $th->execute([$ruleId,$fe]);$thread=$th->fetch();

            if(!$thread){
                // ── NEW LEAD (Stage 1) ──────────────────────────────────────
                $step1delay = 1;
                try {
                    $step1q=db()->prepare("SELECT delay_minutes FROM autoreply_steps WHERE rule_id=? AND step_number=1");
                    $step1q->execute([$ruleId]);
                    $s1r = $step1q->fetch();
                    if ($s1r && isset($s1r['delay_minutes']) && $s1r['delay_minutes'] !== null) {
                        $step1delay = max(0, (int)$s1r['delay_minutes']);
                    }
                } catch (Exception $_s1de) {}
                $step1at = $step1delay > 0
                    ? date('Y-m-d H:i:s', strtotime("+{$step1delay} minutes", $arNowTs))
                    : $arNow;

                $arInserted = false;
                try {
                    db()->prepare(
                        "INSERT INTO autoreply_threads
                         (rule_id,from_email,from_name,subject_in,current_step,next_send_at,
                          reply_count,messages_received,awaiting_reply,status,
                          current_imap_id,last_trigger_uid,last_trigger_imap_id,last_msg_id,
                          active_mailbox,first_reply_sent,imap_source,thread_id,original_message_id,references_header,conversation_stage)
                         VALUES(?,?,?,?,1,?,1,1,0,'active',?,?,?,?, 'primary', 0, 'primary', ?, ?, ?, 'NEW_LEAD')
                         ON DUPLICATE KEY UPDATE
                           from_name=IF(from_name=''OR from_name IS NULL,VALUES(from_name),from_name),
                           next_send_at=IF(status='active'AND next_send_at IS NULL,VALUES(next_send_at),next_send_at),
                           reply_count=reply_count+1,
                           last_trigger_uid=VALUES(last_trigger_uid),
                           last_trigger_imap_id=VALUES(last_trigger_imap_id),
                           last_msg_id=COALESCE(VALUES(last_msg_id),last_msg_id)"
                    )->execute([$ruleId,$fe,$fn,substr($fs,0,200),$step1at,
                                $srcId>0?$srcId:null,$uid>0?$uid:null,$srcId>0?$srcId:null,$inMsgId?:null,
                                $thId,$inMsgId?:null,$inRef?:null]);
                    $arInserted = true;
                } catch (Exception $arInsEx) {
                    try {
                        db()->prepare(
                            "INSERT INTO autoreply_threads
                             (rule_id,from_email,from_name,subject_in,current_step,next_send_at,reply_count,status)
                             VALUES (?, ?, ?, ?, 1, ?, 1, 'active')
                             ON DUPLICATE KEY UPDATE
                               from_name    = IF(from_name='' OR from_name IS NULL, VALUES(from_name), from_name),
                               next_send_at = IF(status='active' AND next_send_at IS NULL, VALUES(next_send_at), next_send_at),
                               reply_count  = reply_count + 1"
                        )->execute([$ruleId,$fe,$fn,substr($fs,0,200),$step1at]);
                        $arInserted = true;
                    } catch (Exception $arBaseEx) {}
                }
                if ($arInserted) {
                    $enrolledAR++;
                    logMailRoutingEvent($userId, $ruleId, $thId, $fe, 'lead_received', $imapCfgById[$srcId]['username'] ?? 'Primary Gmail', null, null, null, 'NEW_LEAD', 'success', "New lead received in Gmail: {$fs}");

                    // ── SIMULTANEOUS ACTION: SCHEDULE FOLLOW-UP SEQUENCE ─────
                    if ($fuRuleId > 0) {
                        try {
                            $fs1Query = db()->prepare("SELECT delay_value, delay_unit, delay_minutes FROM followup_steps WHERE rule_id = ? ORDER BY step_number ASC LIMIT 1");
                            $fs1Query->execute([$fuRuleId]);
                            $fs1Data = $fs1Query->fetch();
                            if ($fs1Data) {
                                $fuVal = max(0, (int)($fs1Data['delay_value'] ?? $fs1Data['delay_minutes'] ?? 30));
                                $fuUnit = in_array(strtolower($fs1Data['delay_unit'] ?? ''), ['minutes','hours','days'], true) ? strtolower($fs1Data['delay_unit']) : 'minutes';
                                $fuMins = delayToMinutes($fuVal, $fuUnit);
                                $fuSchedAt = date('Y-m-d H:i:s', strtotime("+{$fuMins} minutes"));
                                $fuTok = generateTrackingToken();

                                $insFu = db()->prepare(
                                    "INSERT INTO email_followup_queue
                                     (user_id, campaign_id, rule_id, recipient_email, recipient_name, followup_order, delay_value, delay_unit, delay_in_minutes, scheduled_at, status, tracking_token)
                                     VALUES (?, NULL, ?, ?, ?, 1, ?, ?, ?, ?, 'scheduled', ?)"
                                );
                                $insFu->execute([$userId, $fuRuleId, $fe, $fn, $fuVal, $fuUnit, $fuMins, $fuSchedAt, $fuTok]);
                                $fuQId = db()->lastInsertId();

                                db()->prepare("UPDATE autoreply_threads SET followup_status = 'running', followup_next_run = ? WHERE rule_id = ? AND from_email = ?")
                                    ->execute([$fuSchedAt, $ruleId, $fe]);

                                logMailRoutingEvent($userId, $ruleId, $thId, $fe, 'followup_scheduled', null, null, null, 'NEW_LEAD', 'FOLLOWUP_RUNNING', 'success', "Follow-up sequence started simultaneously with lead creation (+{$fuVal} {$fuUnit})");
                                logSystemEvent('queued', $fe, "Follow-up #1 scheduled simultaneously with lead creation for {$fuSchedAt}", $userId, null, $fuRuleId, $fuQId, $fuTok);
                            }
                        } catch (Throwable $_fuSimEx) {}
                    }
                }
            } else if($thread['status']==='completed') {
                // Returning sender — re-enroll
                $step1delay = 1;
                try {
                    $step1q=db()->prepare("SELECT delay_minutes FROM autoreply_steps WHERE rule_id=? AND step_number=1");
                    $step1q->execute([$ruleId]);
                    $s1r = $step1q->fetch();
                    if ($s1r && isset($s1r['delay_minutes']) && $s1r['delay_minutes'] !== null) {
                        $step1delay = max(0, (int)$s1r['delay_minutes']);
                    }
                } catch (Exception $_s1de) {}
                $step1at = $step1delay > 0 ? date('Y-m-d H:i:s', strtotime("+{$step1delay} minutes", $arNowTs)) : $arNow;
                db()->prepare(
                    "UPDATE autoreply_threads
                     SET current_step=1,next_send_at=?,awaiting_reply=0,status='active',
                         reply_count=reply_count+1,messages_received=messages_received+1,
                         from_name=IF(? != '' AND ? IS NOT NULL,?,from_name),
                         current_imap_id=?,last_trigger_uid=?,last_trigger_imap_id=?,
                         conversation_stage='NEW_LEAD', active_mailbox='primary', first_reply_sent=0
                     WHERE id=?"
                )->execute([$step1at,$fn,$fn,$fn,
                            $srcId>0?$srcId:null,$uid>0?$uid:null,$srcId>0?$srcId:null,
                            $thread['id']]);
                $enrolledAR++;
            } else {
                // ── USER REPLIED (Check for Mailbox Migration to Secondary) ──
                $isReplyToSecondary = ($secondaryImapId > 0 && $srcId === $secondaryImapId) || (!empty($thread['first_reply_sent']));
                $targetMailbox = $isReplyToSecondary ? 'secondary' : ($thread['active_mailbox'] ?? 'primary');
                $targetStage = $isReplyToSecondary ? 'MOVED_TO_SECONDARY' : ($thread['conversation_stage'] ?? 'FIRST_REPLY_SENT');

                $nc=(int)($thread['messages_received']??1)+1;
                $awaiting = (int)($thread['awaiting_reply'] ?? 0) === 1 || ((int)$thread['current_step'] > 1 && empty($thread['next_send_at']));

                if($thread['status']==='active' && ($awaiting || $isSeq || $isReplyToSecondary)){
                    $unlockStep=db()->prepare("SELECT delay_minutes FROM autoreply_steps WHERE rule_id=? AND step_number=?");
                    $unlockStep->execute([$ruleId,(int)$thread['current_step']]);
                    $unlockRow = $unlockStep->fetch();
                    $unlockDelay = ($unlockRow && isset($unlockRow['delay_minutes']) && $unlockRow['delay_minutes'] !== null)
                        ? max(0, (int)$unlockRow['delay_minutes']) : 1;
                    $unlockAt = $unlockDelay > 0 ? date('Y-m-d H:i:s', strtotime("+{$unlockDelay} minutes", $arNowTs)) : $arNow;

                    $newRefs = trim(($thread['references_header'] ?? '') . ' ' . $inMsgId);

                    try {
                        db()->prepare("UPDATE autoreply_threads
                            SET messages_received=?,awaiting_reply=0,next_send_at=?,reply_count=reply_count+1,
                                last_trigger_uid=?,last_trigger_imap_id=?,last_msg_id=?,
                                active_mailbox=?,conversation_stage=?,references_header=?
                            WHERE id=?")
                            ->execute([$nc,$unlockAt,$uid>0?$uid:null,$srcId>0?$srcId:null,$inMsgId?:null,
                                       $targetMailbox,$targetStage,$newRefs,$thread['id']]);
                    } catch (Exception $unlockEx) {
                        db()->prepare("UPDATE autoreply_threads SET next_send_at=?, reply_count=reply_count+1 WHERE id=?")->execute([$unlockAt, $thread['id']]);
                    }

                    if ($isReplyToSecondary && ($thread['active_mailbox'] !== 'secondary' || $thread['conversation_stage'] !== 'MOVED_TO_SECONDARY')) {
                        logMailRoutingEvent($userId, $ruleId, $thread['thread_id'] ?? $thId, $fe, 'mailbox_migrated', $imapCfgById[$srcId]['username'] ?? 'Secondary IMAP', null, null, $thread['conversation_stage'] ?? 'FIRST_REPLY_SENT', 'MOVED_TO_SECONDARY', 'success', "Lead replied to IMAP #2 — Thread permanently migrated to Secondary Mailbox (SMTP #2 will handle all future replies & follow-ups)");
                    }
                }
            }
            } catch (Exception $mEx) {
                $results[] = ['status'=>'ar_warn','rule'=>$rule['name'],'message'=>'AR message loop error for '.$fe.': '.$mEx->getMessage()];
            }
        }

        // ── Build SMTP Pools ──────────────────────────────────────────
        $smtpIds=[];
        if(!empty($rule['smtp_ids'])){$d=json_decode($rule['smtp_ids'],true);if(is_array($d))$smtpIds=array_values(array_map('intval',$d));}
        if(empty($smtpIds) && !empty($rule['u_assigned_smtp_ids'])){$d=json_decode($rule['u_assigned_smtp_ids'],true);if(is_array($d))$smtpIds=array_values(array_map('intval',$d));}

        // Pre-fetch dedicated Step 1 / Primary SMTP pool
        $step1SmtpPool=null;
        $primarySmtpCfg=null;
        $secondarySmtpCfg=null;

        if ($primarySmtpId > 0) {
            $psStmt = db()->prepare("SELECT * FROM smtp_providers WHERE id = ?");
            $psStmt->execute([$primarySmtpId]);
            $primarySmtpCfg = $psStmt->fetch();
            if ($primarySmtpCfg) $step1SmtpPool = [$primarySmtpCfg];
        }
        if (!$step1SmtpPool && !empty($rule['step1_smtp_ids'])) {
            $s1Ids=json_decode($rule['step1_smtp_ids'],true);
            if(is_array($s1Ids)&&count($s1Ids)>0){
                $s1Ids=array_values(array_map('intval',$s1Ids));
                try{
                    $s1ph=implode(',',array_fill(0,count($s1Ids),'?'));
                    $s1ss=db()->prepare("SELECT * FROM smtp_providers WHERE id IN ($s1ph)");
                    $s1ss->execute($s1Ids);
                    $step1SmtpPool=$s1ss->fetchAll();
                }catch(Exception $e){}
            }
        }

        if ($secondarySmtpId > 0) {
            $ssStmt = db()->prepare("SELECT * FROM smtp_providers WHERE id = ?");
            $ssStmt->execute([$secondarySmtpId]);
            $secondarySmtpCfg = $ssStmt->fetch();
        }

        $smtpPool=[];
        if($smtpIds){
            $ph=implode(',',array_fill(0,count($smtpIds),'?'));
            $ss=db()->prepare("SELECT * FROM smtp_providers WHERE id IN ($ph)");$ss->execute($smtpIds);
            $smtpPool=$ss->fetchAll();
        }
        if ($secondarySmtpCfg && empty($smtpPool)) {
            $smtpPool = [$secondarySmtpCfg];
        }

        if(!$smtpPool && !$step1SmtpPool){
            $results[]=['status'=>'ar_warn','rule'=>$rule['name'],'message'=>"No SMTP configured — enrolled {$enrolledAR}"];continue;
        }

        $fromPool=[];
        if(!empty($rule['from_emails'])){$d=json_decode($rule['from_emails'],true);if(is_array($d))$fromPool=$d;}

        $due=db()->prepare("SELECT * FROM autoreply_threads WHERE rule_id=? AND status='active' AND next_send_at IS NOT NULL AND next_send_at<=? LIMIT 100");
        $due->execute([$ruleId,$arNow]);$dueThreads=$due->fetchAll();
        $sent=0;$failed=0;

        $arDeleteQueue = [];
        $arMoveQueue   = [];

        foreach($dueThreads as $thread){
            if (isBlacklisted($thread['from_email'], $userId)) {
                try { db()->prepare("UPDATE autoreply_threads SET status='completed', next_send_at=NULL WHERE id=?")->execute([$thread['id']]); } catch (Exception $_blEx) {}
                continue;
            }
            $stepNumNow = (int)$thread['current_step'];
            $sr=db()->prepare("SELECT * FROM autoreply_steps WHERE rule_id=? AND step_number=?");
            $sr->execute([$ruleId,$stepNumNow]);$step=$sr->fetch();
            if(!$step){db()->prepare("UPDATE autoreply_threads SET status='completed',next_send_at=NULL WHERE id=?")->execute([$thread['id']]);continue;}

            // ── SMART ROUTING: Pick SMTP #1 for First Reply, SMTP #2 for Chat & Migration ──
            $isFirstReply = ($stepNumNow === 1 || empty($thread['first_reply_sent']));
            if ($isFirstReply) {
                $activeSmtpPool = ($step1SmtpPool !== null && count($step1SmtpPool) > 0) ? $step1SmtpPool : ($smtpPool ?: [$primarySmtpCfg]);
            } else {
                // Secondary Sender (SMTP #2)
                $activeSmtpPool = ($secondarySmtpCfg) ? [$secondarySmtpCfg] : ($smtpPool ?: $step1SmtpPool);
            }

            if (empty($activeSmtpPool)) {
                $results[] = ['status'=>'ar_warn','rule'=>$rule['name'],'message'=>"No SMTP available for step {$stepNumNow}"];
                continue;
            }

            $mc = $activeSmtpPool[array_rand($activeSmtpPool)];
            if($fromPool){$pk=$fromPool[array_rand($fromPool)];if(is_array($pk)){$mc['from_email']=$pk['email']??$mc['from_email'];$mc['from_name']=$pk['name']??$mc['from_name'];}else $mc['from_email']=$pk;}
            $arDefSubj = !empty($thread['subject_in']) 
                ? ((stripos(trim($thread['subject_in']), 're:') === 0) ? $thread['subject_in'] : 'Re: ' . $thread['subject_in'])
                : 'Re: Regarding your inquiry';
            $msg=buildMessage((array)$step,$thread['from_name']??'',$thread['from_email'],$arDefSubj,$mc['from_name']??'',date('F j, Y g:i A'));
            $mc=applyDisplayName($mc,$userId);
            $smtpNameUsed=$mc['name']??'';
            $fromEmailUsed=$mc['from_email']??'';

            // Resolve Secondary Email for Reply-To Switching
            $secReplyTo = '';
            if ($secondarySmtpCfg && !empty($secondarySmtpCfg['from_email'])) {
                $secReplyTo = $secondarySmtpCfg['from_email'];
            } elseif ($secondarySmtpCfg && !empty($secondarySmtpCfg['username']) && filter_var($secondarySmtpCfg['username'], FILTER_VALIDATE_EMAIL)) {
                $secReplyTo = $secondarySmtpCfg['username'];
            } elseif ($secondaryImapId > 0 && !empty($imapCfgById[$secondaryImapId]['username']) && filter_var($imapCfgById[$secondaryImapId]['username'], FILTER_VALIDATE_EMAIL)) {
                $secReplyTo = $imapCfgById[$secondaryImapId]['username'];
            }

            try{
                $inReplyToHdr = $thread['last_message_id'] ?: ($thread['original_message_id'] ?: ($thread['last_msg_id'] ?? ''));
                $referencesHdr = $thread['references_header'] ?: ($thread['original_message_id'] ?: ($thread['last_msg_id'] ?? ''));

                $arOpts = [
                    'is_auto_reply' => true,
                    'in_reply_to'   => $inReplyToHdr,
                    'references'    => $referencesHdr,
                ];

                // If Reply-To switching is enabled and this is the first reply from SMTP #1:
                if ($isFirstReply && $replyToSwitch && $secReplyTo && strtolower($secReplyTo) !== strtolower($fromEmailUsed)) {
                    $arOpts['reply_to']    = $secReplyTo;
                    $arOpts['return_path'] = $secReplyTo;
                    $arOpts['sender']      = $fromEmailUsed;
                }

                $sentMsgId = (new Mailer($mc))->send($thread['from_email'],$thread['from_name']??'',$msg['subject'],$msg['html'],$msg['text'],$msg['inlineImages'], $arOpts);

                // Update Thread Stage & Status with sent Message-ID for In-Reply-To matching
                $newStage = $isFirstReply ? 'FIRST_REPLY_SENT' : ($thread['conversation_stage'] ?? 'MOVED_TO_SECONDARY');
                db()->prepare("UPDATE autoreply_threads SET first_reply_sent = 1, smtp_used = ?, conversation_stage = ?, last_message_id = COALESCE(?, last_message_id), reply_to_mailbox = ? WHERE id = ?")
                    ->execute([$mc['id'] ?? null, $newStage, $sentMsgId, $secReplyTo ?: $fromEmailUsed, $thread['id']]);

                // Log to autoreply_logs and send_logs
                db()->prepare("INSERT INTO autoreply_logs(rule_id,thread_id,step_number,to_email,status,smtp_used)VALUES(?,?,?,?,'sent',?)")
                    ->execute([$ruleId,$thread['id'],$thread['current_step'],$thread['from_email'],$smtpNameUsed]);
                db()->prepare("INSERT INTO send_logs(campaign_id,user_id,email,status,log_source,smtp_name_used,from_email_used)VALUES(NULL,?,?,'sent','autoreply',?,?)")
                    ->execute([$userId,$thread['from_email'],$smtpNameUsed,$fromEmailUsed]);

                if ($isFirstReply) {
                    logMailRoutingEvent($userId, $ruleId, $thread['thread_id'] ?? null, $thread['from_email'], 'first_reply_sent', null, $smtpNameUsed, $secReplyTo ?: $fromEmailUsed, 'NEW_LEAD', 'FIRST_REPLY_SENT', 'success', "First reply sent from SMTP #1 with Reply-To set to " . ($secReplyTo ?: $fromEmailUsed));
                } else {
                    logMailRoutingEvent($userId, $ruleId, $thread['thread_id'] ?? null, $thread['from_email'], 'chat_reply_sent', null, $smtpNameUsed, null, $thread['conversation_stage'], $thread['conversation_stage'], 'success', "Chat reply #{$stepNumNow} sent via SMTP #2");
                }

                // Two-IMAP post-action cleanup
                $trigUid    = (int)($thread['last_trigger_uid'] ?? 0);
                $trigImapId = (int)($thread['last_trigger_imap_id'] ?? 0);
                if ($twoImap && $trigUid > 0 && $trigImapId > 0) {
                    if ($stepNumNow === 1) {
                        $arDeleteQueue[$trigImapId][] = $trigUid;
                    } elseif ($trigImapId === $imap1Id) {
                        if (!isset($arMoveQueue[$trigImapId])) {
                            $arMoveQueue[$trigImapId] = ['to' => $imap2Id, 'uids' => []];
                        }
                        $arMoveQueue[$trigImapId]['uids'][] = $trigUid;
                    }
                }

                $nextNum=$thread['current_step']+1;
                $nr=db()->prepare("SELECT * FROM autoreply_steps WHERE rule_id=? AND step_number=?");
                $nr->execute([$ruleId,$nextNum]);$nextRow=$nr->fetch();
                $newCurImap = $twoImap ? $imap2Id : ($imap1Id ?: null);
                if($nextRow){
                    if($isSeq || $isSmart){
                        try {
                            db()->prepare("UPDATE autoreply_threads
                                SET current_step=?,last_sent_at=NOW(),next_send_at=NULL,awaiting_reply=1,status='active',
                                    current_imap_id=?,last_trigger_uid=NULL,last_trigger_imap_id=NULL
                                WHERE id=?")
                                ->execute([$nextNum,$newCurImap,$thread['id']]);
                        } catch (Exception $seqUpdEx) {
                            db()->prepare("UPDATE autoreply_threads SET current_step=?, last_sent_at=NOW(), next_send_at=NULL, status='active' WHERE id=?")
                                ->execute([$nextNum,$thread['id']]);
                        }
                    }else{
                        // Non-sequential: schedule next send strictly by configured delay.
                        // Defensive default avoids collapsing to NOW when the column is missing.
                        $nxtDelayMin = (isset($nextRow['delay_minutes']) && $nextRow['delay_minutes'] !== null)
                            ? max(0, (int)$nextRow['delay_minutes']) : 1;
                        $nAt=date('Y-m-d H:i:s',strtotime("+{$nxtDelayMin} minutes"));
                        db()->prepare("UPDATE autoreply_threads
                            SET current_step=?,last_sent_at=NOW(),next_send_at=?,status='active',
                                current_imap_id=?,last_trigger_uid=NULL,last_trigger_imap_id=NULL
                            WHERE id=?")
                            ->execute([$nextNum,$nAt,$newCurImap,$thread['id']]);
                    }
                }else{
                    db()->prepare("UPDATE autoreply_threads
                        SET status='completed',last_sent_at=NOW(),next_send_at=NULL,awaiting_reply=0,
                            current_imap_id=?,last_trigger_uid=NULL,last_trigger_imap_id=NULL
                        WHERE id=?")
                        ->execute([$newCurImap,$thread['id']]);
                    // Sequence fully completed — persist completed lead to backup_emails.
                    // Per-rule, per-user only: same lead email in a different user's AR rule
                    // is stored independently (user_id scopes the record).
                    saveToBackup($userId, $thread['from_email'], $thread['from_name'] ?? '', 'autoreply', $ruleId);
                }
                $sent++;
            }catch(Exception $e){
                $errMsg=substr($e->getMessage(),0,500);
                // Log to autoreply_logs (rule-level detail)
                db()->prepare("INSERT INTO autoreply_logs(rule_id,thread_id,step_number,to_email,status,error,smtp_used)VALUES(?,?,?,?,'failed',?,?)")
                    ->execute([$ruleId,$thread['id'],$thread['current_step'],$thread['from_email'],$errMsg,$smtpNameUsed]);
                // Also log failure to send_logs so All Send Logs shows it
                db()->prepare("INSERT INTO send_logs(campaign_id,user_id,email,status,log_source,smtp_name_used,from_email_used,error)VALUES(NULL,?,?,'failed','autoreply',?,?,?)")
                    ->execute([$userId,$thread['from_email'],$smtpNameUsed,$fromEmailUsed,$errMsg]);
                $failed++;
                // Send failed — do NOT queue any IMAP post-action. The trigger UID
                // stays on IMAP 1 so the next cron run can retry.
            }
        }

        // ── Apply queued IMAP post-actions, one connection per source IMAP ──
        $deletedTotal = 0; $movedTotal = 0;
        foreach ($arDeleteQueue as $iid => $uids) {
            if (!isset($imapCfgById[$iid])) continue;
            $uids = array_values(array_unique(array_map('intval', $uids)));
            $del = imapDeleteUids($imapCfgById[$iid], $uids);
            $deletedTotal += (int)($del['deleted'] ?? 0);
            if (!$del['ok']) {
                $results[] = ['status'=>'ar_warn','rule'=>$rule['name'],
                    'message'=>"IMAP delete failed on imap_id={$iid}: ".$del['message']];
            }
        }
        foreach ($arMoveQueue as $srcId => $info) {
            $dstId = (int)$info['to'];
            if (!isset($imapCfgById[$srcId]) || !isset($imapCfgById[$dstId])) continue;
            $uids = array_values(array_unique(array_map('intval', $info['uids'])));
            $mv = imapMoveUids($imapCfgById[$srcId], $imapCfgById[$dstId], $uids);
            $movedTotal += (int)($mv['moved'] ?? 0);
            if (!$mv['ok']) {
                $results[] = ['status'=>'ar_warn','rule'=>$rule['name'],
                    'message'=>"IMAP move failed {$srcId}→{$dstId}: ".$mv['message']];
            }
        }

        $results[]=['status'=>'autoreply','rule'=>$rule['name'],
            'imap_msgs'=>count($newMsgs),'new_enrolled'=>$enrolledAR,
            'sent'=>$sent,'failed'=>$failed,
            'imap_deleted'=>$deletedTotal,'imap_moved'=>$movedTotal];
        } catch (Exception $ruleEx) {
            // Per-rule isolation: don't let one rule's failure block the rest.
            $results[] = ['status'=>'ar_warn','rule'=>($rule['name'] ?? '?'),
                'message'=>'AR rule error: '.$ruleEx->getMessage()];
        }
    }
}catch(Exception $e){$results[]=['status'=>'error','message'=>'AutoReply error: '.$e->getMessage()];}


// ─────────────────────────────────────────────────────────────────
// STEP 4 — FOLLOW-UP: ENROLL + SEQUENTIAL READ-BASED SEND
//
// 1. Follow-up Timer starts when recipient reads initial email.
// 2. Sequential Delay: Step 1 starts on read; Step 2 starts after
//    Step 1 was sent; Step 3 starts after Step 2 was sent, etc.
// 3. Queue worker processes email_followup_queue with atomic locks
//    and exponential backoff (5m, 15m, 60m).
// ─────────────────────────────────────────────────────────────────
try {
    $fuNow = date('Y-m-d H:i:s');
    $fuPid = getmypid() ?: bin2hex(random_bytes(4));

    // ── 4A. RECOVER STUCK QUEUE JOBS (>15m in sending state) ────────
    try {
        db()->exec("UPDATE email_followup_queue 
                    SET status = 'scheduled', locked_at = NULL, lock_token = NULL 
                    WHERE status = 'sending' AND locked_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    } catch (Throwable $_recEx) {}

    // ── 4B. PROCESS DEDICATED FOLLOW-UP QUEUE (email_followup_queue) ──
    try {
        $qDue = db()->query(
            "SELECT q.*, r.smtp_ids, r.from_emails, r.name rule_name, u.status u_status, u.expires_at u_expires
             FROM email_followup_queue q
             LEFT JOIN followup_rules r ON r.id = q.rule_id
             JOIN users u ON u.id = q.user_id
             WHERE q.status = 'scheduled' AND q.scheduled_at IS NOT NULL AND q.scheduled_at <= NOW() AND u.status = 'active'
             ORDER BY q.scheduled_at ASC
             LIMIT 50"
        )->fetchAll();

        foreach ($qDue as $qItem) {
            $qId = (int)$qItem['id'];
            $qUserId = (int)$qItem['user_id'];
            $qEmail = strtolower(trim($qItem['recipient_email']));

            // Expiry check
            if (!empty($qItem['u_expires']) && strtotime($qItem['u_expires']) < time()) continue;

            // Blacklist check
            if (isBlacklisted($qEmail, $qUserId)) {
                db()->prepare("UPDATE email_followup_queue SET status = 'cancelled', last_error = 'Blacklisted recipient' WHERE id = ?")->execute([$qId]);
                logSystemEvent('failed', $qEmail, 'Follow-up cancelled: Blacklisted recipient', $qUserId, $qItem['campaign_id'], $qItem['rule_id'], $qId);
                continue;
            }

            // Atomic Lock
            $lockStmt = db()->prepare("UPDATE email_followup_queue SET status = 'sending', locked_at = NOW(), lock_token = ? WHERE id = ? AND status = 'scheduled'");
            $lockStmt->execute([$fuPid, $qId]);
            if ($lockStmt->rowCount() === 0) continue; // Concurrently claimed by another worker

            // Fetch step content
            $ruleId = (int)$qItem['rule_id'];
            $stepOrder = (int)$qItem['followup_order'];
            $stepStmt = db()->prepare("SELECT * FROM followup_steps WHERE rule_id = ? AND step_number = ?");
            $stepStmt->execute([$ruleId, $stepOrder]);
            $stepRow = $stepStmt->fetch();

            if (!$stepRow) {
                db()->prepare("UPDATE email_followup_queue SET status = 'skipped', last_error = 'Step template not found', locked_at = NULL WHERE id = ?")->execute([$qId]);
                continue;
            }

            // ── Resolve SMTP pool (Smart Follow-Up routing: SMTP #1 before switch, SMTP #2 after switch) ──
            $smtpIds = [];
            $activeMailbox = 'primary';
            $activeTh = null;
            try {
                $thCheck = db()->prepare("SELECT active_mailbox, rule_id, thread_id, conversation_stage FROM autoreply_threads WHERE from_email = ? ORDER BY id DESC LIMIT 1");
                $thCheck->execute([$qEmail]);
                $activeTh = $thCheck->fetch();
                if ($activeTh) {
                    $activeMailbox = $activeTh['active_mailbox'] ?? 'primary';
                    if (!empty($activeTh['rule_id'])) {
                        $arRuleStmt = db()->prepare("SELECT primary_smtp_id, secondary_smtp_id, enable_smart_routing FROM autoreply_rules WHERE id = ?");
                        $arRuleStmt->execute([(int)$activeTh['rule_id']]);
                        $arRuleData = $arRuleStmt->fetch();
                        if ($arRuleData && !empty($arRuleData['enable_smart_routing'])) {
                            if ($activeMailbox === 'secondary' && !empty($arRuleData['secondary_smtp_id'])) {
                                $smtpIds = [(int)$arRuleData['secondary_smtp_id']];
                            } elseif (!empty($arRuleData['primary_smtp_id'])) {
                                $smtpIds = [(int)$arRuleData['primary_smtp_id']];
                            }
                        }
                    }
                }
            } catch (Exception $_thEx) {}

            if (!$smtpIds && !empty($qItem['smtp_ids'])) { $d = json_decode($qItem['smtp_ids'], true); if (is_array($d)) $smtpIds = $d; }
            if (!$smtpIds) {
                $userSmtps = db()->prepare("SELECT id FROM smtp_providers WHERE user_id = ?");
                $userSmtps->execute([$qUserId]);
                $smtpIds = $userSmtps->fetchAll(PDO::FETCH_COLUMN);
            }
            if (!$smtpIds) {
                db()->prepare("UPDATE email_followup_queue SET status = 'failed', last_error = 'No SMTP configured for user', locked_at = NULL WHERE id = ?")->execute([$qId]);
                continue;
            }

            $ph = implode(',', array_fill(0, count($smtpIds), '?'));
            $ss = db()->prepare("SELECT * FROM smtp_providers WHERE id IN ($ph)");
            $ss->execute($smtpIds);
            $smtpPool = $ss->fetchAll();
            if (!$smtpPool) {
                db()->prepare("UPDATE email_followup_queue SET status = 'failed', last_error = 'SMTP provider not found', locked_at = NULL WHERE id = ?")->execute([$qId]);
                continue;
            }

            $mc = $smtpPool[array_rand($smtpPool)];
            $fromPool = [];
            if (!empty($qItem['from_emails'])) { $d = json_decode($qItem['from_emails'], true); if (is_array($d)) $fromPool = $d; }
            if ($fromPool) {
                $pk = $fromPool[array_rand($fromPool)];
                if (is_array($pk)) { $mc['from_email'] = $pk['email'] ?? $mc['from_email']; $mc['from_name'] = $pk['name'] ?? $mc['from_name']; }
                else { $mc['from_email'] = $pk; }
            }

            $defSubj = '';
            // 1. Try to find the original thread / incoming email subject
            try {
                $thSubjStmt = db()->prepare("SELECT subject_in FROM autoreply_threads WHERE from_email = ? AND (rule_id IN (SELECT id FROM autoreply_rules WHERE followup_rule_id = ?) OR user_id = ?) AND subject_in IS NOT NULL AND subject_in != '' ORDER BY id DESC LIMIT 1");
                $thSubjStmt->execute([$qEmail, $ruleId, $qUserId]);
                $thSubj = $thSubjStmt->fetchColumn();
                if ($thSubj) {
                    $defSubj = (stripos(trim($thSubj), 're:') === 0) ? $thSubj : 'Re: ' . $thSubj;
                }
            } catch (Throwable $_subEx) {}

            // 2. If not found, check campaign subject if triggered from campaign
            if (!$defSubj && !empty($qItem['campaign_id'])) {
                try {
                    $campSubjStmt = db()->prepare("SELECT subject FROM campaigns WHERE id = ?");
                    $campSubjStmt->execute([(int)$qItem['campaign_id']]);
                    $cSubj = $campSubjStmt->fetchColumn();
                    if ($cSubj) {
                        $defSubj = (stripos(trim($cSubj), 're:') === 0) ? $cSubj : 'Re: ' . $cSubj;
                    }
                } catch (Throwable $_cEx) {}
            }

            // 3. If step > 1 and step 1 has custom subject
            if (!$defSubj && $stepOrder > 1) {
                $s1Stmt = db()->prepare("SELECT subject FROM followup_steps WHERE rule_id = ? AND step_number = 1 AND subject IS NOT NULL AND subject != ''");
                $s1Stmt->execute([$ruleId]);
                $s1Subj = $s1Stmt->fetchColumn();
                if ($s1Subj) {
                    $defSubj = (stripos(trim($s1Subj), 're:') === 0) ? $s1Subj : 'Re: ' . $s1Subj;
                }
            }

            // 4. If still empty, check inbound emails
            if (!$defSubj) {
                try {
                    $inSubjStmt = db()->prepare("SELECT subject FROM inbound_emails WHERE from_email = ? AND subject IS NOT NULL AND subject != '' ORDER BY id DESC LIMIT 1");
                    $inSubjStmt->execute([$qEmail]);
                    $inSubj = $inSubjStmt->fetchColumn();
                    if ($inSubj) {
                        $defSubj = (stripos(trim($inSubj), 're:') === 0) ? $inSubj : 'Re: ' . $inSubj;
                    }
                } catch (Throwable $_inEx) {}
            }

            // 5. Final fallback (clean natural conversation subject, NEVER the rule name "follow" / "Follow-up")
            if (!$defSubj) {
                $defSubj = 'Re: Regarding your inquiry';
            }

            $msg = buildMessage((array)$stepRow, $qItem['recipient_name'] ?? '', $qEmail, $defSubj, $mc['from_name'] ?? '', date('F j, Y g:i A'));
            $mc = applyDisplayName($mc, $qUserId);
            $fuSmtpName = $mc['name'] ?? '';
            $fuFromEmail = $mc['from_email'] ?? '';

            try {
                $fuInReplyTo = '';
                $fuReferences = '';
                $fuReplyTo = '';

                try {
                    $thStmt = db()->prepare("SELECT original_message_id, last_message_id, references_header, reply_to_mailbox FROM autoreply_threads WHERE from_email = ? AND (rule_id IN (SELECT id FROM autoreply_rules WHERE followup_rule_id = ?) OR user_id = ?) ORDER BY id DESC LIMIT 1");
                    $thStmt->execute([$qEmail, $ruleId, $qUserId]);
                    $thRow = $thStmt->fetch();
                    if ($thRow) {
                        $fuInReplyTo = $thRow['last_message_id'] ?: ($thRow['original_message_id'] ?: '');
                        $fuReferences = $thRow['references_header'] ?: ($thRow['original_message_id'] ?: '');
                        if (!empty($thRow['reply_to_mailbox'])) {
                            $fuReplyTo = $thRow['reply_to_mailbox'];
                        }
                    }
                } catch (Throwable $_thEx) {}

                if (!$fuReplyTo && !empty($qItem['imap_id'])) {
                    $fuImapRow = db()->query("SELECT username FROM imap_accounts WHERE id = " . (int)$qItem['imap_id'])->fetch();
                    if ($fuImapRow && filter_var($fuImapRow['username'], FILTER_VALIDATE_EMAIL)) {
                        $fuReplyTo = $fuImapRow['username'];
                    }
                }

                $mailer = new Mailer($mc);
                $sentFuMsgId = $mailer->send(
                    $qEmail,
                    $qItem['recipient_name'] ?? '',
                    $msg['subject'],
                    $msg['html'],
                    $msg['text'],
                    $msg['inlineImages'],
                    [
                        'tracking_token' => $qItem['tracking_token'],
                        'track_clicks'   => true,
                        'in_reply_to'    => $fuInReplyTo,
                        'references'     => $fuReferences,
                        'reply_to'       => $fuReplyTo ?: $fuFromEmail,
                    ]
                );

                // Mark current queue item SENT
                db()->prepare("UPDATE email_followup_queue SET status = 'sent', sent_at = NOW(), locked_at = NULL, lock_token = NULL WHERE id = ?")->execute([$qId]);
                logSystemEvent('sent', $qEmail, "Follow-up #{$stepOrder} sent successfully", $qUserId, $qItem['campaign_id'], $ruleId, $qId, $qItem['tracking_token'], $fuSmtpName);

                // Log to send_logs
                db()->prepare("INSERT INTO send_logs (campaign_id, user_id, email, status, log_source, smtp_name_used, from_email_used) VALUES (?, ?, ?, 'sent', 'followup', ?, ?)")
                    ->execute([$qItem['campaign_id'], $qUserId, $qEmail, $fuSmtpName, $fuFromEmail]);

                // Check for NEXT STEP (Sequential chaining: Step N+1 delay starts from Step N sent_at)
                $nextStepStmt = db()->prepare("SELECT * FROM followup_steps WHERE rule_id = ? AND step_number = ?");
                $nextStepStmt->execute([$ruleId, $stepOrder + 1]);
                $nextStepRow = $nextStepStmt->fetch();

                if ($nextStepRow) {
                    $nextDelayVal = max(0, (int)($nextStepRow['delay_value'] ?? $nextStepRow['delay_minutes'] ?? 30));
                    $nextDelayUnit = in_array(strtolower($nextStepRow['delay_unit'] ?? ''), ['minutes','hours','days'], true) ? strtolower($nextStepRow['delay_unit']) : 'minutes';
                    $nextDelayMins = delayToMinutes($nextDelayVal, $nextDelayUnit);
                    $nextSchedAt = date('Y-m-d H:i:s', strtotime("+{$nextDelayMins} minutes"));
                    $nextTrackingToken = generateTrackingToken();

                    $insNext = db()->prepare(
                        "INSERT INTO email_followup_queue 
                         (user_id, campaign_id, rule_id, contact_id, recipient_email, recipient_name, followup_order, delay_value, delay_unit, delay_in_minutes, scheduled_at, status, tracking_token)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'scheduled', ?)"
                    );
                    $insNext->execute([
                        $qUserId, $qItem['campaign_id'], $ruleId, $qItem['contact_id'],
                        $qEmail, $qItem['recipient_name'], $stepOrder + 1,
                        $nextDelayVal, $nextDelayUnit, $nextDelayMins, $nextSchedAt, $nextTrackingToken
                    ]);
                    $nextQid = db()->lastInsertId();
                    logSystemEvent('queued', $qEmail, "Follow-up #" . ($stepOrder + 1) . " scheduled for {$nextSchedAt} (+{$nextDelayVal} {$nextDelayUnit})", $qUserId, $qItem['campaign_id'], $ruleId, $nextQid, $nextTrackingToken);
                } else {
                    // Sequence fully completed
                    saveToBackup($qUserId, $qEmail, $qItem['recipient_name'] ?? '', 'followup', $ruleId);
                }

            } catch (Throwable $sendEx) {
                $err = substr($sendEx->getMessage(), 0, 500);
                $retryCount = (int)$qItem['retry_count'] + 1;

                if ($retryCount < 3) {
                    // Exponential backoff: attempt 1 -> 5 min, attempt 2 -> 15 min, attempt 3 -> 60 min
                    $backoffMins = ($retryCount === 1) ? 5 : (($retryCount === 2) ? 15 : 60);
                    $retryAt = date('Y-m-d H:i:s', strtotime("+{$backoffMins} minutes"));

                    db()->prepare(
                        "UPDATE email_followup_queue 
                         SET status = 'scheduled', retry_count = ?, scheduled_at = ?, last_error = ?, locked_at = NULL, lock_token = NULL 
                         WHERE id = ?"
                    )->execute([$retryCount, $retryAt, $err, $qId]);

                    logSystemEvent('retry', $qEmail, "Retry #{$retryCount} scheduled in {$backoffMins}m due to error: {$err}", $qUserId, $qItem['campaign_id'], $ruleId, $qId, $qItem['tracking_token']);
                } else {
                    // Max retries exceeded
                    db()->prepare(
                        "UPDATE email_followup_queue 
                         SET status = 'failed', retry_count = ?, last_error = ?, locked_at = NULL, lock_token = NULL 
                         WHERE id = ?"
                    )->execute([$retryCount, $err, $qId]);

                    logSystemEvent('failed', $qEmail, "Follow-up #{$stepOrder} failed after 3 retries: {$err}", $qUserId, $qItem['campaign_id'], $ruleId, $qId, $qItem['tracking_token']);
                    db()->prepare("INSERT INTO send_logs (campaign_id, user_id, email, status, log_source, smtp_name_used, from_email_used, error) VALUES (?, ?, ?, 'failed', 'followup', ?, ?, ?)")
                        ->execute([$qItem['campaign_id'], $qUserId, $qEmail, $fuSmtpName, $fuFromEmail, $err]);
                }
            }
        }
    } catch (Throwable $_qErr) {
        $results[] = ['status'=>'fu_queue_error', 'message'=>$_qErr->getMessage()];
    }

    // ── 4C. PROCESS FOLLOW-UP CONTACTS (IMAP enrollments & list contacts) ──
    $fuRules = db()->query(
        "SELECT r.*, u.status u_status, u.expires_at u_expires, ia.id imap_account_id, u.assigned_imap_ids u_assigned_imap_ids, u.assigned_smtp_ids u_assigned_smtp_ids
         FROM followup_rules r
         JOIN users u ON u.id = r.user_id
         LEFT JOIN imap_accounts ia ON ia.id = r.imap_id
         WHERE r.status = 'active' AND u.status = 'active'"
    )->fetchAll();

    if (!isset($imapOwnerById) || !is_array($imapOwnerById)) {
        $imapOwnerById = [];
        try {
            foreach (db()->query("SELECT id, user_id FROM imap_accounts")->fetchAll() as $ic) {
                $imapOwnerById[(int)$ic['id']] = (int)$ic['user_id'];
            }
        } catch (Exception $_e) {}
    }

    foreach ($fuRules as $rule) {
        if (!empty($rule['u_expires']) && strtotime($rule['u_expires']) < time()) continue;
        $ruleId = (int)$rule['id'];
        $userId = (int)$rule['user_id'];
        $isTriggerOnOpen = !isset($rule['trigger_on_open']) || (int)$rule['trigger_on_open'] === 1;

        // Isolation
        $fuAllowedImapIds = [];
        foreach ($imapOwnerById as $_iaId => $_ownerId) {
            if ($_ownerId === $userId) $fuAllowedImapIds[] = (int)$_iaId;
        }
        if (!empty($rule['u_assigned_imap_ids'])) {
            $sharedIds = json_decode($rule['u_assigned_imap_ids'], true);
            if (is_array($sharedIds)) {
                foreach ($sharedIds as $sharedIaId) {
                    $sharedIaId = (int)$sharedIaId;
                    if (!in_array($sharedIaId, $fuAllowedImapIds, true)) $fuAllowedImapIds[] = $sharedIaId;
                }
            }
        }

        $fuMsgs = [];
        foreach ($imapMessages as $iaId => $_msgs) {
            if (!in_array((int)$iaId, $fuAllowedImapIds, true)) continue;
            foreach ($_msgs as $_m) $fuMsgs[] = $_m;
        }

        $enrolledFU = 0;
        // Step 1 delay
        $delay1Val = 30; $delay1Unit = 'minutes';
        try {
            $fs1 = db()->prepare("SELECT delay_value, delay_unit, delay_minutes FROM followup_steps WHERE rule_id=? ORDER BY step_number ASC LIMIT 1");
            $fs1->execute([$ruleId]);
            $fs1r = $fs1->fetch();
            if ($fs1r) {
                $delay1Val = max(0, (int)($fs1r['delay_value'] ?? $fs1r['delay_minutes'] ?? 30));
                $delay1Unit = in_array(strtolower($fs1r['delay_unit'] ?? ''), ['minutes','hours','days'], true) ? strtolower($fs1r['delay_unit']) : 'minutes';
            }
        } catch (Exception $_dle) {}
        $delay1Mins = delayToMinutes($delay1Val, $delay1Unit);

        // Follow-up timer begins upon enrollment: Step 1 sends after delay whether read or not
        $nextSend = date('Y-m-d H:i:s', strtotime("+{$delay1Mins} minutes"));

        foreach ($fuMsgs as $msg) {
            $fuE = strtolower(trim($msg['from_email'] ?? ''));
            $fuN = trim($msg['from_name'] ?? '');
            $fuS = trim($msg['subject'] ?? '');
            if (!$fuE || !filter_var($fuE, FILTER_VALIDATE_EMAIL)) continue;
            if (isBlacklisted($fuE, $userId)) continue;
            if (isMessageBlocked($userId, $fuS, $fuE, $fuN)) continue;

            $ex = db()->prepare("SELECT id, status FROM followup_contacts WHERE rule_id=? AND email=?");
            $ex->execute([$ruleId, $fuE]);
            $exRow = $ex->fetch();
            $tTok = generateTrackingToken();

            if (!$exRow) {
                db()->prepare(
                    "INSERT INTO followup_contacts (rule_id, email, name, current_step, next_send_at, tracking_token, status)
                     VALUES (?, ?, ?, 1, ?, ?, 'active')"
                )->execute([$ruleId, $fuE, $fuN, $nextSend, $tTok]);
                $enrolledFU++;
            } elseif ($exRow['status'] === 'completed') {
                db()->prepare("UPDATE followup_contacts SET current_step=1, next_send_at=?, tracking_token=?, status='active', last_sent_at=NULL, opened_at=NULL WHERE id=?")
                    ->execute([$nextSend, $tTok, $exRow['id']]);
                $enrolledFU++;
            }
        }

        $smtpIds = [];
        if (!empty($rule['smtp_ids'])) { $d = json_decode($rule['smtp_ids'], true); if (is_array($d)) $smtpIds = $d; }
        if (empty($smtpIds) && !empty($rule['u_assigned_smtp_ids'])) { $d = json_decode($rule['u_assigned_smtp_ids'], true); if (is_array($d)) $smtpIds = array_values(array_map('intval', $d)); }

        if (!$smtpIds) {
            $results[] = ['status'=>'fu_warn', 'rule'=>$rule['name'], 'message'=>"No SMTP configured — enrolled {$enrolledFU}"];
            continue;
        }

        $ph = implode(',', array_fill(0, count($smtpIds), '?'));
        $ss = db()->prepare("SELECT * FROM smtp_providers WHERE id IN ($ph)");
        $ss->execute($smtpIds);
        $smtpPool = $ss->fetchAll();
        if (!$smtpPool) continue;

        $fromPool = [];
        if (!empty($rule['from_emails'])) { $d = json_decode($rule['from_emails'], true); if (is_array($d)) $fromPool = $d; }

        // Due contacts: next_send_at IS NOT NULL AND next_send_at <= NOW()
        // (Contacts whose read-timer has not fired yet have next_send_at = NULL and are safely waiting)
        $due = db()->prepare("SELECT * FROM followup_contacts WHERE rule_id=? AND status='active' AND next_send_at IS NOT NULL AND next_send_at<=? LIMIT 50");
        $due->execute([$ruleId, $fuNow]);
        $contacts = $due->fetchAll();
        $sent = 0; $failed = 0;

        foreach ($contacts as $contact) {
            if (isBlacklisted($contact['email'], $userId)) {
                try {
                    db()->prepare("UPDATE followup_contacts SET status='stopped', next_send_at=NULL WHERE id=?")->execute([$contact['id']]);
                } catch (Exception $_blEx) {}
                continue;
            }

            $sr = db()->prepare("SELECT * FROM followup_steps WHERE rule_id=? AND step_number=?");
            $sr->execute([$ruleId, $contact['current_step']]);
            $step = $sr->fetch();
            if (!$step) {
                db()->prepare("UPDATE followup_contacts SET status='completed', next_send_at=NULL WHERE id=?")->execute([$contact['id']]);
                continue;
            }

            $mc = $smtpPool[array_rand($smtpPool)];
            if ($fromPool) {
                $pk = $fromPool[array_rand($fromPool)];
                if (is_array($pk)) { $mc['from_email'] = $pk['email'] ?? $mc['from_email']; $mc['from_name'] = $pk['name'] ?? $mc['from_name']; }
                else { $mc['from_email'] = $pk; }
            }

            $defSubj = '';
            // 1. Try to find original thread / incoming email subject
            try {
                $thSubjStmt = db()->prepare("SELECT subject_in FROM autoreply_threads WHERE from_email = ? AND (rule_id IN (SELECT id FROM autoreply_rules WHERE followup_rule_id = ?) OR user_id = ?) AND subject_in IS NOT NULL AND subject_in != '' ORDER BY id DESC LIMIT 1");
                $thSubjStmt->execute([$contact['email'], $ruleId, $userId]);
                $thSubj = $thSubjStmt->fetchColumn();
                if ($thSubj) {
                    $defSubj = (stripos(trim($thSubj), 're:') === 0) ? $thSubj : 'Re: ' . $thSubj;
                }
            } catch (Throwable $_subEx) {}

            // 2. If step > 1 and step 1 has custom subject
            if (!$defSubj && $contact['current_step'] > 1) {
                $sr1 = db()->prepare("SELECT subject FROM followup_steps WHERE rule_id=? AND step_number=1 AND subject IS NOT NULL AND subject != ''");
                $sr1->execute([$ruleId]);
                $s1 = $sr1->fetchColumn();
                if ($s1) {
                    $defSubj = (stripos(trim($s1), 're:') === 0) ? $s1 : 'Re: ' . $s1;
                }
            }

            // 3. If still empty, check inbound emails
            if (!$defSubj) {
                try {
                    $inSubjStmt = db()->prepare("SELECT subject FROM inbound_emails WHERE from_email = ? AND subject IS NOT NULL AND subject != '' ORDER BY id DESC LIMIT 1");
                    $inSubjStmt->execute([$contact['email']]);
                    $inSubj = $inSubjStmt->fetchColumn();
                    if ($inSubj) {
                        $defSubj = (stripos(trim($inSubj), 're:') === 0) ? $inSubj : 'Re: ' . $inSubj;
                    }
                } catch (Throwable $_inEx) {}
            }

            // 4. Final fallback (clean natural subject, NEVER internal rule name)
            if (!$defSubj) {
                $defSubj = 'Re: Regarding your inquiry';
            }

            $msg = buildMessage((array)$step, $contact['name'] ?? '', $contact['email'], $defSubj, $mc['from_name'] ?? '', date('F j, Y g:i A'));
            $mc = applyDisplayName($mc, $userId);
            $fuSmtpName = $mc['name'] ?? '';
            $fuFromEmail = $mc['from_email'] ?? '';

            try {
                $mailer = new Mailer($mc);
                $mailer->send(
                    $contact['email'],
                    $contact['name'] ?? '',
                    $msg['subject'],
                    $msg['html'],
                    $msg['text'],
                    $msg['inlineImages'],
                    [
                        'tracking_token' => $contact['tracking_token'] ?? '',
                        'track_clicks'   => true,
                    ]
                );

                // Log to followup_logs
                db()->prepare("INSERT INTO followup_logs (rule_id, contact_id, step_number, email, status, smtp_used) VALUES (?, ?, ?, ?, 'sent', ?)")
                    ->execute([$ruleId, $contact['id'], $contact['current_step'], $contact['email'], $fuSmtpName]);
                db()->prepare("INSERT INTO send_logs (campaign_id, user_id, email, status, log_source, smtp_name_used, from_email_used) VALUES (NULL, ?, ?, 'sent', 'followup', ?, ?)")
                    ->execute([$userId, $contact['email'], $fuSmtpName, $fuFromEmail]);

                logSystemEvent('sent', $contact['email'], "Follow-up step #{$contact['current_step']} sent", $userId, null, $ruleId, null, $contact['tracking_token'] ?? null, $fuSmtpName);

                // Next Step in Sequence
                $nr = db()->prepare("SELECT * FROM followup_steps WHERE rule_id=? AND step_number=?");
                $nr->execute([$ruleId, $contact['current_step'] + 1]);
                $nxtRow = $nr->fetch();

                if ($nxtRow) {
                    $nxtDelayVal = max(0, (int)($nxtRow['delay_value'] ?? $nxtRow['delay_minutes'] ?? 60));
                    $nxtDelayUnit = in_array(strtolower($nxtRow['delay_unit'] ?? ''), ['minutes','hours','days'], true) ? strtolower($nxtRow['delay_unit']) : 'minutes';
                    $nxtDelayMins = delayToMinutes($nxtDelayVal, $nxtDelayUnit);

                    // Sequential Delay: Step N+1 delay starts from Step N sent_at (NOW)
                    $nAt = date('Y-m-d H:i:s', strtotime("+{$nxtDelayMins} minutes"));
                    db()->prepare("UPDATE followup_contacts SET current_step=?, next_send_at=?, last_sent_at=NOW(), status='active' WHERE id=?")
                        ->execute([$contact['current_step'] + 1, $nAt, $contact['id']]);
                } else {
                    db()->prepare("UPDATE followup_contacts SET status='completed', last_sent_at=NOW(), next_send_at=NULL WHERE id=?")->execute([$contact['id']]);
                    saveToBackup($userId, $contact['email'], $contact['name'] ?? '', 'followup', $ruleId);
                }
                $sent++;
            } catch (Throwable $e) {
                $fuErrMsg = substr($e->getMessage(), 0, 500);
                db()->prepare("INSERT INTO followup_logs (rule_id, contact_id, step_number, email, status, error, smtp_used) VALUES (?, ?, ?, ?, 'failed', ?, ?)")
                    ->execute([$ruleId, $contact['id'], $contact['current_step'], $contact['email'], $fuErrMsg, $fuSmtpName]);
                db()->prepare("INSERT INTO send_logs (campaign_id, user_id, email, status, log_source, smtp_name_used, from_email_used, error) VALUES (NULL, ?, ?, 'failed', 'followup', ?, ?, ?)")
                    ->execute([$userId, $contact['email'], $fuSmtpName, $fuFromEmail, $fuErrMsg]);
                logSystemEvent('failed', $contact['email'], "Follow-up step #{$contact['current_step']} failed: {$fuErrMsg}", $userId, null, $ruleId, null, $contact['tracking_token'] ?? null);
                $failed++;
            }
        }
        $results[] = ['status'=>'followup', 'rule'=>$rule['name'], 'imap_msgs'=>count($fuMsgs), 'new_enrolled'=>$enrolledFU, 'sent'=>$sent, 'failed'=>$failed];
    }
} catch (Throwable $e) {
    $results[] = ['status'=>'error', 'message'=>'FollowUp error: ' . $e->getMessage()];
}


// ─────────────────────────────────────────────────────────────────
// OUTPUT
// ─────────────────────────────────────────────────────────────────
@unlink($lock);

if(!empty($_GET['debug'])){
    try{$iaRows=db()->query("SELECT id,username,emails_read,last_check FROM imap_accounts")->fetchAll();}
    catch(Exception $e){$iaRows=[];}
    $results[]=['status'=>'debug',
        'php_imap'=>function_exists('imap_open')?'yes':'no (raw socket)',
        '__DIR__'=>__DIR__,'imap_accounts'=>$iaRows];
}

if(!empty($_GET['json'])){
    if(php_sapi_name()!=='cli') header('Content-Type: application/json');
    echo json_encode(['ok'=>true,'results'=>$results,'time'=>date('Y-m-d H:i:s')],JSON_PRETTY_PRINT);
    exit;
}

echo "OK\n";
foreach($results as $r){
    $tag=strtoupper($r['status']??'info');
    switch($r['status']){
        case 'imap_ok':
            // Some imap_ok rows are warnings/notices that don't include the
            // full poll metrics (e.g. "Auto-deleted N processed UID(s)").
            // Fall through to a simpler [IMAP] line in that case.
            if (isset($r['message']) && !isset($r['found'])) {
                echo "[IMAP] {$r['account']}: {$r['message']}\n"; break;
            }
            $em=$r['emails']?' | '.implode(', ',$r['emails']):'';
            $uidInfo=$r['uid_now']>$r['uid_was']?' | uid:'.$r['uid_was'].'->'.$r['uid_now']:'';
            $diag=' | exists:'.($r['exists']??0).' uidnext:'.($r['uid_next']??0).' uidvalidity:'.($r['uid_validity']??0);
            $reset=!empty($r['reset'])?' | reset:'.$r['reset']:'';
            $delCount=isset($r['auto_deleted'])?' | auto_deleted:'.(int)$r['auto_deleted']:'';
            echo "[IMAP] {$r['account']} | found:{$r['found']}{$diag}{$uidInfo}{$delCount}{$reset}{$em}\n";break;
        case 'imap_warn':
            echo "[IMAP WARN] {$r['account']}: {$r['message']}\n";break;
        case 'imap_err':
            echo "[IMAP ERROR] {$r['account']}: {$r['message']}\n";break;
        case 'ok':
            echo "[CAMPAIGN] {$r['campaign']} | sent:{$r['sent']} failed:{$r['failed']} remaining:{$r['remaining']}\n";break;
        case 'autoreply':
            $imapOps = '';
            if (!empty($r['imap_deleted'])||!empty($r['imap_moved'])) {
                $imapOps = ' imap1_deleted:'.(int)($r['imap_deleted']??0).' moved_to_imap2:'.(int)($r['imap_moved']??0);
            }
            echo "[AR] {$r['rule']} | imap_msgs:{$r['imap_msgs']} enrolled:{$r['new_enrolled']} sent:{$r['sent']} failed:{$r['failed']}{$imapOps}\n";break;
        case 'followup':
            echo "[FU] {$r['rule']} | imap_msgs:{$r['imap_msgs']} enrolled:{$r['new_enrolled']} sent:{$r['sent']} failed:{$r['failed']}\n";break;
        case 'ar_warn':case 'fu_warn':
            echo "[{$tag}] {$r['rule']}: {$r['message']}\n";break;
        case 'error':
            echo "[ERROR] ".($r['message']??'')."\n";break;
        default:
            echo "[{$tag}] ".($r['campaign']??$r['rule']??'').': '.($r['message']??json_encode($r))."\n";
    }
}
