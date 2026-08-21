<?php
ob_start();

set_exception_handler(function($e){
    ob_end_clean();
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['ok'=>false,'message'=>'Server error: '.$e->getMessage()]);
    exit;
});
set_error_handler(function($errno,$errstr){
    // Suppress warnings from output; let exceptions handle real errors
    return true;
});

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/imap.php';
require_once __DIR__ . '/includes/mailer.php';
startSecureSession();

header('Content-Type: application/json');

// If not installed yet, only allow the install route through
if (!isInstalled()) {
    $route = trim($_GET['r']??'','/');
    // Allow install POST to proceed — everything else: tell client to redirect
    if (!str_starts_with($route, 'install')) {
        http_response_code(200);
        echo json_encode(['installed'=>false,'error'=>'Not installed']);
        exit;
    }
}

$route  = trim($_GET['r']??'','/');
$parts  = explode('/',$route);
$res    = $parts[0]??'';
$id     = $parts[1]??null;
$action = $parts[2]??null;
$method = $_SERVER['REQUEST_METHOD'];

// Release the session lock early to prevent blocking concurrent requests
// (except for login/logout which need to write to the session).
if (!($res === 'auth' && in_array($id, ['login', 'logout']))) {
    try { session_write_close(); } catch (Throwable $e) {}
}

// ── AUTH ────────────────────────────────────────────────────────
if ($res==='auth') {
    $b=body();
    if ($method==='POST'&&$id==='login') {
        $s=db()->prepare('SELECT * FROM users WHERE LOWER(TRIM(username))=LOWER(TRIM(?))');
        $s->execute([$b['username']??'']);
        $u=$s->fetch();
        if (!$u||!password_verify($b['password']??'',$u['password'])) jsonOut(['error'=>'Invalid credentials'],401);
        if ($u['status']==='suspended') jsonOut(['error'=>'Account suspended'],403);
        if (!$u['is_admin']&&!empty($u['expires_at'])&&strtotime($u['expires_at'])<time()) jsonOut(['error'=>'Account expired'],403);
        try { session_regenerate_id(true); } catch (Throwable $e) {}
        $_SESSION['uid']=$u['id'];
        $_SESSION['uname']=$u['username'];
        $_SESSION['is_admin']=(bool)$u['is_admin'];
        $_SESSION['image_upload']=(bool)($u['image_upload'] ?? 1);
        setRememberCookie($u['id']);
        try { session_write_close(); } catch (Throwable $e) {}
        jsonOut(['success'=>true,'username'=>$u['username'],'is_admin'=>(bool)$u['is_admin'],'image_upload'=>(bool)($u['image_upload'] ?? 1)]);
    }
    if ($method==='POST'&&$id==='logout'){
        try { startSecureSession(); } catch (Throwable $e) {}
        if (!empty($_COOKIE['mailpro_remember'])) {
            $parts = explode(':', $_COOKIE['mailpro_remember'], 2);
            if (count($parts) === 2) {
                $remUid  = (int)$parts[0];
                $remHash = hash('sha256', $parts[1]);
                try {
                    db()->prepare('UPDATE users SET remember_token=NULL WHERE id=? OR remember_token=?')->execute([$remUid, $remHash]);
                } catch(Throwable $e){}
            }
        }
        if (!empty($_SESSION['uid'])) {
            try {
                db()->prepare('UPDATE users SET remember_token=NULL WHERE id=?')->execute([(int)$_SESSION['uid']]);
            } catch(Throwable $e){}
        }
        try { clearRememberCookie(); } catch (Throwable $e) {}
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            try { session_unset(); } catch (Throwable $e) {}
            if (ini_get('session.use_cookies')) {
                try {
                    $p = session_get_cookie_params();
                    $cPath = (!empty($p['path'])) ? $p['path'] : '/';
                    if (PHP_VERSION_ID >= 70300) {
                        $cOpts = [
                            'expires'  => 1,
                            'path'     => $cPath,
                            'httponly' => $p['httponly'] ?? true,
                            'samesite' => $p['samesite'] ?? 'Lax'
                        ];
                        if (!empty($p['domain'])) $cOpts['domain'] = $p['domain'];
                        if (!empty($p['secure'])) $cOpts['secure'] = true;
                        @setcookie(session_name(), '', $cOpts);
                    }
                    @setcookie(session_name(), '', time() - 42000, $cPath);
                    @setcookie(session_name(), '', time() - 42000, '/');
                } catch (Throwable $e) {}
            }
            try { @session_destroy(); } catch (Throwable $e) {}
        }
        jsonOut(['success'=>true, 'ok'=>true]);
    }
    if ($method==='GET'&&$id==='me') {
        startSecureSession();
        if (empty($_SESSION['uid'])) {
            checkRememberToken();
        }
        if (!empty($_SESSION['uid'])) {
            $uid = (int)$_SESSION['uid'];
            $uname = $_SESSION['uname'] ?? '';
            $isAdmin = (bool)($_SESSION['is_admin'] ?? false);
            $imageUpload = (bool)($_SESSION['image_upload'] ?? true);
            try { session_write_close(); } catch (Throwable $e) {}
            jsonOut([
                'loggedIn'     => true,
                'uid'          => $uid,
                'username'     => $uname,
                'is_admin'     => $isAdmin,
                'image_upload' => $imageUpload,
            ]);
        }
        try { session_write_close(); } catch (Throwable $e) {}
        jsonOut(['loggedIn'=>false]);
    }
    if ($method==='POST'&&$id==='change-password') {
        requireAuth();$b=body();
        $s=db()->prepare('SELECT * FROM users WHERE id=?');$s->execute([$_SESSION['uid']]);$u=$s->fetch();
        if (!password_verify($b['current']??'',$u['password'])) jsonOut(['ok'=>false,'message'=>'Current password wrong']);
        db()->prepare('UPDATE users SET password=? WHERE id=?')->execute([password_hash($b['newpass'],PASSWORD_BCRYPT),$_SESSION['uid']]);
        jsonOut(['ok'=>true,'message'=>'Password updated!']);
    }
    // Emergency reset — only works from CLI or when not yet installed properly
    // URL: api.php?r=auth/emergency-reset&user=admin&newpass=yourpassword&token=EMERGENCY_RESET
    if ($method==='GET'&&$id==='emergency-reset') {
        $token = $_GET['token'] ?? '';
        if ($token !== 'EMERGENCY_RESET_MAILSZO_2025') jsonOut(['ok'=>false,'message'=>'Invalid token'],403);
        $user  = $_GET['user']    ?? 'admin';
        $newpw = $_GET['newpass'] ?? '';
        if (strlen($newpw) < 6) jsonOut(['ok'=>false,'message'=>'Password min 6 chars']);
        $hash = password_hash($newpw, PASSWORD_BCRYPT);
        $s = db()->prepare('SELECT id FROM users WHERE username=?'); $s->execute([$user]); $row=$s->fetch();
        if (!$row) jsonOut(['ok'=>false,'message'=>"User '{$user}' not found — check username"]);
        db()->prepare('UPDATE users SET password=? WHERE username=?')->execute([$hash,$user]);
        jsonOut(['ok'=>true,'message'=>"Password for '{$user}' updated. Remove this URL from your browser history."]);
    }
    jsonOut(['error'=>'Not found'],404);
}

// ── PUBLIC TRACKING (Open pixel, click redirect, unsubscribe) ───
if ($res === 'track') {
    $subAction = $id; // 'open', 'click', 'unsub'
    $token = trim($_GET['t'] ?? $action ?? '');

    // 1. OPEN TRACKING PIXEL
    if ($subAction === 'open') {
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: image/gif');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, post-check=0, pre-check=0');
        header('Pragma: no-cache');
        header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

        if ($token) {
            try {
                // Check email_followup_queue
                $qStmt = db()->prepare("SELECT * FROM email_followup_queue WHERE tracking_token = ?");
                $qStmt->execute([$token]);
                $qRow = $qStmt->fetch();

                if ($qRow) {
                    $isFirstOpen = empty($qRow['opened_at']);
                    if ($isFirstOpen) {
                        db()->prepare(
                            "UPDATE email_followup_queue 
                             SET opened_at = NOW(), followup_started_at = IFNULL(followup_started_at, NOW())
                             WHERE id = ?"
                        )->execute([$qRow['id']]);

                        logSystemEvent('opened', $qRow['recipient_email'], "Recipient opened email (Follow-up #{$qRow['followup_order']})", $qRow['user_id'], $qRow['campaign_id'], $qRow['rule_id'], $qRow['id'], $token);
                    } else {
                        logSystemEvent('opened', $qRow['recipient_email'], "Repeat open", $qRow['user_id'], $qRow['campaign_id'], $qRow['rule_id'], $qRow['id'], $token);
                    }
                }

                // Also check followup_contacts
                $fcStmt = db()->prepare("SELECT * FROM followup_contacts WHERE tracking_token = ?");
                $fcStmt->execute([$token]);
                $fcRow = $fcStmt->fetch();
                if ($fcRow) {
                    $isFirstOpen = empty($fcRow['opened_at']);
                    if ($isFirstOpen) {
                        $ruleId = (int)$fcRow['rule_id'];
                        db()->prepare(
                            "UPDATE followup_contacts
                             SET opened_at = NOW(), followup_started_at = IFNULL(followup_started_at, NOW()), open_count = open_count + 1
                             WHERE id = ?"
                        )->execute([$fcRow['id']]);

                        $uStmt = db()->prepare("SELECT user_id FROM followup_rules WHERE id = ?");
                        $uStmt->execute([$ruleId]);
                        $ruleOwner = (int)$uStmt->fetchColumn();

                        logSystemEvent('opened', $fcRow['email'], "Contact opened email", $ruleOwner, null, $ruleId, null, $token);
                    } else {
                        db()->prepare("UPDATE followup_contacts SET open_count = open_count + 1 WHERE id = ?")->execute([$fcRow['id']]);
                    }
                }
            } catch (Throwable $_tEx) {}
        }
        exit;
    }

    // 2. CLICK TRACKING
    if ($subAction === 'click') {
        $targetUrl = trim($_GET['url'] ?? $_GET['u'] ?? '');
        if (!$targetUrl) {
            header('Location: ' . getAppBaseUrl(), true, 302);
            exit;
        }
        if ($token) {
            try {
                // Look up queue or contact
                $qStmt = db()->prepare("SELECT * FROM email_followup_queue WHERE tracking_token = ?");
                $qStmt->execute([$token]);
                $qRow = $qStmt->fetch();
                if ($qRow) {
                    logSystemEvent('clicked', $qRow['recipient_email'], "Clicked link: " . substr($targetUrl, 0, 300), $qRow['user_id'], $qRow['campaign_id'], $qRow['rule_id'], $qRow['id'], $token);
                }
                $fcStmt = db()->prepare("SELECT * FROM followup_contacts WHERE tracking_token = ?");
                $fcStmt->execute([$token]);
                $fcRow = $fcStmt->fetch();
                if ($fcRow) {
                    db()->prepare("UPDATE followup_contacts SET click_count = click_count + 1 WHERE id = ?")->execute([$fcRow['id']]);
                }
            } catch (Throwable $_cEx) {}
        }
        header('Location: ' . $targetUrl, true, 302);
        exit;
    }

    // 3. UNSUBSCRIBE
    if ($subAction === 'unsub') {
        $email = '';
        $userId = 1;
        if ($token) {
            try {
                $qStmt = db()->prepare("SELECT * FROM email_followup_queue WHERE tracking_token = ?");
                $qStmt->execute([$token]);
                $qRow = $qStmt->fetch();
                if ($qRow) {
                    $email = $qRow['recipient_email'];
                    $userId = (int)$qRow['user_id'];
                    db()->prepare("UPDATE email_followup_queue SET status = 'cancelled' WHERE recipient_email = ? AND status IN ('pending','scheduled')")->execute([$email]);
                }
                $fcStmt = db()->prepare("SELECT c.*, r.user_id FROM followup_contacts c JOIN followup_rules r ON r.id = c.rule_id WHERE c.tracking_token = ?");
                $fcStmt->execute([$token]);
                $fcRow = $fcStmt->fetch();
                if ($fcRow) {
                    $email = $fcRow['email'];
                    $userId = (int)$fcRow['user_id'];
                    db()->prepare("UPDATE followup_contacts SET status = 'stopped' WHERE email = ?")->execute([$email]);
                }
                if ($email) {
                    db()->prepare("UPDATE emails SET status = 'unsubscribed' WHERE email = ?")->execute([$email]);
                    db()->prepare("INSERT INTO blacklist (user_id, type, email) VALUES (?, 'email', ?) ON DUPLICATE KEY UPDATE type='email'")->execute([$userId, $email]);
                    logSystemEvent('unsubscribed', $email, 'Recipient unsubscribed via tracking link', $userId, null, null, null, $token);
                }
            } catch (Throwable $_uEx) {}
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            jsonOut(['ok' => true, 'message' => 'Successfully unsubscribed.']);
        }

        // Output clean HTML unsubscribe confirmation page
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Unsubscribed</title><style>body{background:#090c12;color:#e2eaf6;font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px;text-align:center}.card{background:#0e1420;border:1px solid #1a2540;padding:36px 24px;border-radius:14px;max-width:420px;box-shadow:0 10px 30px rgba(0,0,0,0.5)}.ic{font-size:44px;margin-bottom:16px}h2{color:#4ade80;font-size:20px;margin-bottom:10px}p{color:#7a92b8;font-size:14px;line-height:1.6}</style></head><body><div class="card"><div class="ic">📬</div><h2>You have been unsubscribed</h2><p>' . htmlspecialchars($email ?: 'Your email address') . ' has been removed from our mailing list. You will not receive any further automated emails from this sequence.</p></div></body></html>';
        exit;
    }

    jsonOut(['error' => 'Unknown tracking action'], 404);
}

requireAuth();
$CUR = currentUser();
if (!$CUR) jsonOut(['error'=>'Session invalid'],401);
// Check expiry on every request
if (isExpired($CUR)&&!$CUR['is_admin']) jsonOut(['error'=>'Account expired. Contact administrator.'],403);
if ($CUR['status']==='suspended') jsonOut(['error'=>'Account suspended'],403);

$UID = (int)$CUR['id'];
$IS_ADMIN = (bool)$CUR['is_admin'];

/**
 * Resolve a dashboard date-range filter into ISO datetime bounds.
 *
 * Accepts a preset name (matching the buttons in the dashboard picker:
 * today / yesterday / 7d / 15d / this_month / last_month / custom) plus
 * optional explicit YYYY-MM-DD strings used when preset = 'custom'.
 *
 * Returns ['from'=>'Y-m-d H:i:s', 'to'=>'Y-m-d H:i:s', 'preset'=>$p,
 *          'active'=>bool] — `active=false` means no range was supplied
 * and callers should leave their queries unfiltered (cumulative behaviour).
 *
 * Output strings are formatted by DateTime so they're always safe to
 * interpolate directly into SQL — they cannot contain quotes or
 * SQL-meaningful characters.
 */
function resolveDateRange(string $preset = '', string $customFrom = '', string $customTo = ''): array {
    $tz  = new DateTimeZone(date_default_timezone_get());
    $now = new DateTime('now', $tz);
    $from = null; $to = null; $active = true;
    try {
        switch ($preset) {
            case 'today':
                $from = new DateTime('today',     $tz);
                $to   = (clone $from)->modify('+1 day -1 second');
                break;
            case 'yesterday':
                $from = new DateTime('yesterday', $tz);
                $to   = (clone $from)->modify('+1 day -1 second');
                break;
            case '7d': case 'last_7':
                $to   = clone $now;
                $from = (clone $now)->modify('-7 days');
                break;
            case '15d': case 'last_15':
                $to   = clone $now;
                $from = (clone $now)->modify('-15 days');
                break;
            case 'this_month':
                $from = new DateTime($now->format('Y-m-01 00:00:00'), $tz);
                $to   = clone $now;
                break;
            case 'last_month':
                $from = new DateTime($now->format('Y-m-01 00:00:00'), $tz);
                $from->modify('-1 month');
                $to = (clone $from)->modify('+1 month -1 second');
                break;
            case 'custom':
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $customFrom)) { $active = false; break; }
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $customTo))   { $active = false; break; }
                $from = new DateTime($customFrom . ' 00:00:00', $tz);
                $to   = new DateTime($customTo   . ' 23:59:59', $tz);
                if ($to < $from) { $tmp = $from; $from = $to; $to = $tmp; }
                break;
            default:
                $active = false;
        }
    } catch (Exception $_dre) {
        $active = false;
    }
    return [
        'preset' => $preset ?: '',
        'from'   => ($active && $from) ? $from->format('Y-m-d H:i:s') : '',
        'to'     => ($active && $to)   ? $to  ->format('Y-m-d H:i:s') : '',
        'active' => (bool)$active && $from && $to,
    ];
}

// ── USER MANAGEMENT (Admin only) ────────────────────────────────
if ($res==='users') {
    requireAdmin();
    if ($method==='GET'&&!$id) {
        $rows=db()->query('SELECT id,username,is_admin,smtp_limit,campaign_limit,daily_send_limit,autoreply_limit,followup_limit,imap_read_limit,image_upload,expires_at,status,created_at FROM users ORDER BY id DESC')->fetchAll();
        jsonOut($rows);
    }
    if ($method==='GET'&&$id) {
        $s=db()->prepare('SELECT id,username,is_admin,smtp_limit,campaign_limit,daily_send_limit,autoreply_limit,followup_limit,imap_read_limit,image_upload,expires_at,status,created_at FROM users WHERE id=?');
        $s->execute([$id]); jsonOut($s->fetch());
    }
    if ($method==='POST') {
        $b=body();
        if (empty($b['username'])||empty($b['password'])) jsonOut(['ok'=>false,'message'=>'username and password required']);
        $hash=password_hash($b['password'],PASSWORD_BCRYPT);
        $imapLimitVal = (int)($b['daily_send_limit'] ?? 1000);
        $imapReadVal  = (int)($b['imap_read_limit']  ?? 0);
        try {
            db()->prepare('INSERT INTO users (username,password,is_admin,smtp_limit,campaign_limit,daily_send_limit,autoreply_limit,followup_limit,imap_read_limit,image_upload,expires_at,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([
                    $b['username'],$hash,
                    (int)($b['is_admin']??0),
                    (int)($b['smtp_limit']??5),
                    (int)($b['campaign_limit']??10),
                    $imapLimitVal,
                    (int)($b['autoreply_limit']??5),
                    (int)($b['followup_limit']??5),
                    $imapReadVal,
                    (int)($b['image_upload']??1),
                    ($b['expires_at']??null)?:null,
                    $b['status']??'active'
                ]);
            jsonOut(['ok'=>true,'id'=>db()->lastInsertId()]);
        } catch(Exception $e) {
            jsonOut(['ok'=>false,'message'=>'Username already exists']);
        }
    }
    if ($method==='PUT'&&$id) {
        $b=body();
        $imapLimitVal = (int)($b['daily_send_limit'] ?? 1000);
        $imapReadVal  = (int)($b['imap_read_limit']  ?? 0);
        $fields=[
            'smtp_limit'       => (int)($b['smtp_limit']?? 5),
            'campaign_limit'   => (int)($b['campaign_limit']??10),
            'daily_send_limit' => $imapLimitVal,
            'autoreply_limit'  => (int)($b['autoreply_limit']??5),
            'followup_limit'   => (int)($b['followup_limit']??5),
            'imap_read_limit'  => $imapReadVal,
            'image_upload'     => (int)($b['image_upload']??1),
            'expires_at'       => ($b['expires_at']??null)?:null,
            'status'           => $b['status']??'active',
            'is_admin'         => (int)($b['is_admin']??0),
        ];
        if (!empty($b['password'])) {
            $fields['password']=password_hash($b['password'],PASSWORD_BCRYPT);
            db()->prepare('UPDATE users SET smtp_limit=?,campaign_limit=?,daily_send_limit=?,autoreply_limit=?,followup_limit=?,imap_read_limit=?,image_upload=?,expires_at=?,status=?,is_admin=?,password=? WHERE id=?')
                ->execute(array_merge(array_values($fields),[$id]));
        } else {
            db()->prepare('UPDATE users SET smtp_limit=?,campaign_limit=?,daily_send_limit=?,autoreply_limit=?,followup_limit=?,imap_read_limit=?,image_upload=?,expires_at=?,status=?,is_admin=? WHERE id=?')
                ->execute([...array_values($fields),$id]);
        }
        jsonOut(['ok'=>true]);
    }
    if ($method==='DELETE'&&$id&&!$action) {
        if ((int)$id===$UID) jsonOut(['ok'=>false,'message'=>'Cannot delete yourself']);
        db()->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
        jsonOut(['ok'=>true]);
    }
    // Admin: clear all data for a specific user
    if ($method==='DELETE'&&$id&&$action==='clear-data') {
        requireAdmin();
        $tid=(int)$id;
        if (!$tid) jsonOut(['ok'=>false,'message'=>'Invalid user ID']);
        // Clear send logs
        db()->prepare('DELETE FROM send_logs WHERE user_id=?')->execute([$tid]);
        // Clear campaigns
        db()->prepare('DELETE FROM send_logs WHERE campaign_id IN (SELECT id FROM campaigns WHERE user_id=?)')->execute([$tid]);
        db()->prepare('DELETE FROM campaigns WHERE user_id=?')->execute([$tid]);
        // Clear SMTP servers
        db()->prepare('DELETE FROM smtp_providers WHERE user_id=?')->execute([$tid]);
        // Clear email lists and their emails
        db()->prepare('DELETE FROM emails WHERE list_id IN (SELECT id FROM email_lists WHERE user_id=?)')->execute([$tid]);
        db()->prepare('DELETE FROM email_lists WHERE user_id=?')->execute([$tid]);
        // Clear IMAP accounts
        db()->prepare('DELETE FROM imap_accounts WHERE user_id=?')->execute([$tid]);
        // Clear autoreply rules and threads
        db()->prepare('DELETE FROM autoreply_threads WHERE rule_id IN (SELECT id FROM autoreply_rules WHERE user_id=?)')->execute([$tid]);
        db()->prepare('DELETE FROM autoreply_rules WHERE user_id=?')->execute([$tid]);
        // Clear followup rules and contacts
        db()->prepare('DELETE FROM followup_contacts WHERE rule_id IN (SELECT id FROM followup_rules WHERE user_id=?)')->execute([$tid]);
        db()->prepare('DELETE FROM followup_rules WHERE user_id=?')->execute([$tid]);
        jsonOut(['ok'=>true,'message'=>'All data cleared for user #'.$tid]);
    }

    // Admin: Reset dashboard stats + daily send counter for a specific user
    if ($method==='POST'&&$id&&$action==='reset-stats') {
        requireAdmin();
        $tid=(int)$id;
        if (!$tid) jsonOut(['ok'=>false,'message'=>'Invalid user ID']);
        $today = date('Y-m-d');
        $pdo   = db();

        // 1. Delete today's send_logs for this user (resets daily counter)
        $pdo->prepare("DELETE FROM send_logs WHERE user_id=? AND DATE(sent_at)=?")
            ->execute([$tid, $today]);

        // 2. Reset autoreply_logs for today (by joining to owned rules)
        $pdo->prepare("DELETE FROM autoreply_logs WHERE rule_id IN (SELECT id FROM autoreply_rules WHERE user_id=?) AND DATE(sent_at)=?")
            ->execute([$tid, $today]);

        // 3. Reset followup_logs for today
        $pdo->prepare("DELETE FROM followup_logs WHERE rule_id IN (SELECT id FROM followup_rules WHERE user_id=?) AND DATE(sent_at)=?")
            ->execute([$tid, $today]);

        // 4. Fetch user info to return default daily limit
        $uRow = $pdo->prepare('SELECT username, daily_send_limit FROM users WHERE id=?');
        $uRow->execute([$tid]);
        $user = $uRow->fetch();
        if (!$user) jsonOut(['ok'=>false,'message'=>'User not found']);

        jsonOut([
            'ok'               => true,
            'message'          => 'Stats reset for user "' . $user['username'] . '". Daily limit restored to ' . (int)$user['daily_send_limit'] . '.',
            'username'         => $user['username'],
            'daily_send_limit' => (int)$user['daily_send_limit'],
        ]);
    }
}

// ── ADMIN: CLEAR ENTIRE DASHBOARD (all-user or user-specific today stats) ─────────
// POST api.php?r=dashboard/clear
// Admin-only. Wipes today's send_logs, autoreply_logs, followup_logs,
// inbound_emails, imap_read_log and fully resets thread/contact state.
if ($res==='dashboard' && $id==='clear' && $method==='POST') {
    requireAdmin();
    $b = body();
    $targetUid = (int)($b['user_id'] ?? $_GET['user_id'] ?? 0);
    $today = date('Y-m-d');
    $pdo   = db();

    $pdo->beginTransaction();
    try {
        if ($targetUid > 0) {
            // User-wise clear
            $pdo->prepare("DELETE FROM emails WHERE list_id IN (SELECT id FROM email_lists WHERE user_id=?)")
                ->execute([$targetUid]);

            $pdo->prepare("UPDATE email_lists SET total_count=0 WHERE user_id=?")
                ->execute([$targetUid]);

            $pdo->prepare("DELETE FROM send_logs WHERE (user_id=? OR campaign_id IN (SELECT id FROM campaigns WHERE user_id=?))")
                ->execute([$targetUid, $targetUid]);

            $pdo->prepare("DELETE FROM autoreply_logs WHERE rule_id IN (SELECT id FROM autoreply_rules WHERE user_id=?)")
                ->execute([$targetUid]);

            $pdo->prepare("DELETE FROM followup_logs WHERE rule_id IN (SELECT id FROM followup_rules WHERE user_id=?)")
                ->execute([$targetUid]);

            $pdo->prepare("DELETE FROM inbound_emails WHERE imap_account_id IN (SELECT id FROM imap_accounts WHERE user_id=?)")
                ->execute([$targetUid]);

            $pdo->prepare("DELETE FROM autoreply_threads WHERE rule_id IN (SELECT id FROM autoreply_rules WHERE user_id=?)")
                ->execute([$targetUid]);

            $pdo->prepare("DELETE FROM followup_contacts WHERE rule_id IN (SELECT id FROM followup_rules WHERE user_id=?)")
                ->execute([$targetUid]);

            $pdo->prepare("DELETE FROM email_followup_queue WHERE user_id=?")
                ->execute([$targetUid]);

            try {
                $pdo->prepare("DELETE FROM mail_routing_logs WHERE user_id=?")->execute([$targetUid]);
            } catch (Exception $_mrlE) {}

            $pdo->prepare("UPDATE imap_accounts SET emails_read=0, last_uid=0, last_uid_validity=0 WHERE user_id=?")
                ->execute([$targetUid]);

            try {
                $pdo->prepare("DELETE FROM imap_read_log WHERE (owner_user_id=? OR processing_user_id=?)")
                    ->execute([$targetUid, $targetUid]);
            } catch (Exception $_e) {}

            $pdo->prepare("UPDATE campaigns SET sent_count=0, failed_count=0 WHERE user_id=?")
                ->execute([$targetUid]);
        } else {
            // System-wide clear (all users)
            $pdo->exec("DELETE FROM emails");
            $pdo->exec("UPDATE email_lists SET total_count=0");
            $pdo->exec("DELETE FROM send_logs");
            $pdo->exec("DELETE FROM autoreply_logs");
            $pdo->exec("DELETE FROM followup_logs");
            $pdo->exec("DELETE FROM inbound_emails");
            $pdo->exec("DELETE FROM autoreply_threads");
            $pdo->exec("DELETE FROM followup_contacts");
            $pdo->exec("DELETE FROM email_followup_queue");

            try {
                $pdo->exec("DELETE FROM mail_routing_logs");
            } catch (Exception $_mrlE) {}

            $pdo->exec("UPDATE imap_accounts SET emails_read=0, last_uid=0, last_uid_validity=0");
            try {
                $pdo->exec("DELETE FROM imap_read_log");
            } catch (Exception $_e) {}

            $pdo->exec("UPDATE campaigns SET sent_count=0, failed_count=0");
        }

        $pdo->commit();
    } catch (Exception $clearEx) {
        $pdo->rollBack();
        jsonOut(['ok'=>false,'message'=>'Clear failed: '.$clearEx->getMessage()]);
    }

    jsonOut([
        'ok'         => true,
        'message'    => $targetUid > 0 ? "Dashboard cleared for user #{$targetUid}." : 'Dashboard cleared. All statistics have been reset to zero.',
        'cleared_at' => date('Y-m-d H:i:s'),
    ]);
}

// ── USER SELF CLEAR-DATA ──────────────────────────────────────────
// Allows a regular user to clear their own data (campaigns, logs, SMTP, lists, etc.)
if ($res==='user'&&$id==='clear-data'&&$method==='DELETE') {
    // Clear send logs
    db()->prepare('DELETE FROM send_logs WHERE user_id=?')->execute([$UID]);
    db()->prepare('DELETE FROM send_logs WHERE campaign_id IN (SELECT id FROM campaigns WHERE user_id=?)')->execute([$UID]);
    // Clear campaigns
    db()->prepare('DELETE FROM campaigns WHERE user_id=?')->execute([$UID]);
    // Clear SMTP servers
    db()->prepare('DELETE FROM smtp_providers WHERE user_id=?')->execute([$UID]);
    // Clear email lists and their emails
    db()->prepare('DELETE FROM emails WHERE list_id IN (SELECT id FROM email_lists WHERE user_id=?)')->execute([$UID]);
    db()->prepare('DELETE FROM email_lists WHERE user_id=?')->execute([$UID]);
    // Clear IMAP accounts
    db()->prepare('DELETE FROM imap_accounts WHERE user_id=?')->execute([$UID]);
    // Clear autoreply rules and threads
    db()->prepare('DELETE FROM autoreply_threads WHERE rule_id IN (SELECT id FROM autoreply_rules WHERE user_id=?)')->execute([$UID]);
    db()->prepare('DELETE FROM autoreply_rules WHERE user_id=?')->execute([$UID]);
    // Clear followup rules and contacts
    db()->prepare('DELETE FROM followup_contacts WHERE rule_id IN (SELECT id FROM followup_rules WHERE user_id=?)')->execute([$UID]);
    db()->prepare('DELETE FROM followup_rules WHERE user_id=?')->execute([$UID]);
    jsonOut(['ok'=>true,'message'=>'All your data has been cleared.']);
}

// ── SMTP ─────────────────────────────────────────────────────────
if ($res==='smtp') {
    if ($method==='GET'&&$action==='test') {
        session_write_close();
        ini_set('default_socket_timeout', 5);
        $s=db()->prepare('SELECT * FROM smtp_providers WHERE id=?'.($IS_ADMIN?'':' AND user_id=?'));
        $p=[$id]; if(!$IS_ADMIN)$p[]=$UID;
        $s->execute($p); $smtp=$s->fetch();
        if (!$smtp) jsonOut(['ok'=>false,'message'=>'SMTP server not found']);
        set_error_handler(function($errno,$errstr){throw new Exception($errstr);});
        try {
            (new Mailer($smtp))->verify();
            jsonOut(['ok'=>true,'message'=>'✅ Connected successfully to '.$smtp['host'].':'.$smtp['port']]);
        } catch(Exception $e) {
            $msg=$e->getMessage();
            // Clean up common socket error noise
            $msg=preg_replace('/fsockopen\(\):?\s*/i','',$msg);
            jsonOut(['ok'=>false,'message'=>'❌ SMTP Error: '.$msg]);
        }
        restore_error_handler();
    }
    if ($method==='GET') {
        if ($IS_ADMIN) {
            // Admin always sees all SMTP servers
            $rows=db()->query('SELECT s.*,u.username owner FROM smtp_providers s LEFT JOIN users u ON u.id=s.user_id ORDER BY s.id DESC')->fetchAll();
        } else {
            // Non-admin: return their OWN smtp servers + admin-assigned ones (marked)
            $cu = db()->prepare('SELECT assigned_smtp_ids FROM users WHERE id=?');
            $cu->execute([$UID]); $cuRow = $cu->fetch();
            $assignedIds = [];
            if ($cuRow && !empty($cuRow['assigned_smtp_ids'])) {
                $d = json_decode($cuRow['assigned_smtp_ids'], true);
                if (is_array($d)) $assignedIds = array_values(array_unique(array_filter(array_map('intval', $d))));
            }
            // Own SMTPs
            $ownStmt = db()->prepare("SELECT id,user_id,name,host,port,secure,username,from_email,from_name,created_at FROM smtp_providers WHERE user_id=? ORDER BY id DESC");
            $ownStmt->execute([$UID]); $ownRows = $ownStmt->fetchAll();
            $ownIds = array_column($ownRows, 'id');
            // Mark own as owned
            foreach ($ownRows as &$r) { $r['is_own'] = true; $r['is_assigned'] = false; }
            // Assigned SMTPs not already in own
            $assignedRows = [];
            $extraIds = array_diff($assignedIds, $ownIds);
            if (!empty($extraIds)) {
                $ph = implode(',', array_fill(0, count($extraIds), '?'));
                $stmt2 = db()->prepare("SELECT id,user_id,name,host,port,secure,username,from_email,from_name,created_at FROM smtp_providers WHERE id IN ($ph) ORDER BY id DESC");
                $stmt2->execute(array_values($extraIds));
                foreach ($stmt2->fetchAll() as $r) { $r['is_own'] = false; $r['is_assigned'] = true; $assignedRows[] = $r; }
            }
            $rows = array_merge($ownRows, $assignedRows);
            $dedup = [];
            foreach ($rows as $rItem) {
                if (!isset($dedup[$rItem['id']])) $dedup[$rItem['id']] = $rItem;
            }
            $rows = array_values($dedup);
        }
        jsonOut($rows??[]);
    }
    if ($method==='POST') {
        if (!$IS_ADMIN) jsonOut(['ok'=>false,'message'=>'Only admin can add SMTP servers.'], 403);
        $b=body();
        if (empty($b['name'])||empty($b['host'])||empty($b['from_email'])) jsonOut(['ok'=>false,'message'=>'Name, host and from-email are required']);
        try {
            db()->prepare('INSERT INTO smtp_providers (user_id,name,host,port,secure,username,password,from_email,from_name) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$UID,$b['name'],$b['host'],(int)($b['port']??587),$b['secure']?1:0,$b['username']??'',$b['password']??'',$b['from_email'],$b['from_name']??'']);
            jsonOut(['ok'=>true,'id'=>(int)db()->lastInsertId()]);
        } catch(Exception $e) {
            jsonOut(['ok'=>false,'message'=>'DB error: '.$e->getMessage()]);
        }
    }
    if ($method==='PUT') {
        $b=body();
        $s=db()->prepare('SELECT user_id FROM smtp_providers WHERE id=?');$s->execute([$id]);$row=$s->fetch();
        if (!$row) jsonOut(['ok'=>false,'message'=>'Not found'],404);
        if (!$IS_ADMIN) jsonOut(['ok'=>false,'message'=>'Only admin can edit SMTP servers.'], 403);
        try {
            if (!empty($b['password']))
                db()->prepare('UPDATE smtp_providers SET name=?,host=?,port=?,secure=?,username=?,password=?,from_email=?,from_name=? WHERE id=?')
                    ->execute([$b['name'],$b['host'],(int)($b['port']??587),$b['secure']?1:0,$b['username']??'',$b['password'],$b['from_email'],$b['from_name']??'',$id]);
            else
                db()->prepare('UPDATE smtp_providers SET name=?,host=?,port=?,secure=?,username=?,from_email=?,from_name=? WHERE id=?')
                    ->execute([$b['name'],$b['host'],(int)($b['port']??587),$b['secure']?1:0,$b['username']??'',$b['from_email'],$b['from_name']??'',$id]);
            jsonOut(['ok'=>true,'success'=>true]);
        } catch(Exception $e) {
            jsonOut(['ok'=>false,'message'=>'DB error: '.$e->getMessage()]);
        }
    }
    if ($method==='DELETE') {
        $s=db()->prepare('SELECT user_id FROM smtp_providers WHERE id=?');$s->execute([$id]);$row=$s->fetch();
        if (!$row) jsonOut(['ok'=>false,'message'=>'Not found'],404);
        if (!$IS_ADMIN) jsonOut(['ok'=>false,'message'=>'Only admin can delete SMTP servers.'], 403);
        db()->prepare('DELETE FROM smtp_providers WHERE id=?')->execute([$id]);
        jsonOut(['success'=>true]);
    }
}

// ── IMAGES ───────────────────────────────────────────────────────
if ($res==='images') {
    if ($method==='GET') {
        if ($IS_ADMIN) {
            $stmt=db()->prepare('SELECT i.*, u.username FROM images i LEFT JOIN users u ON i.user_id = u.id ORDER BY i.id DESC');
            $stmt->execute();
        } else {
            $stmt=db()->prepare('SELECT * FROM images WHERE user_id=? ORDER BY id DESC');
            $stmt->execute([$UID]);
        }
        jsonOut($stmt->fetchAll());
    }
    if ($method==='POST') {
        if (!$IS_ADMIN && !(int)($CUR['image_upload'] ?? 1)) jsonOut(['ok'=>false,'error'=>'Image upload is disabled for your account'],403);
        if (empty($_FILES['image'])) jsonOut(['ok'=>false,'error'=>'No image uploaded'],400);
        $file=$_FILES['image'];
        $allowed=['image/jpeg','image/png','image/gif','image/webp','image/svg+xml'];
        if (!in_array($file['type'],$allowed)) jsonOut(['ok'=>false,'error'=>'Only JPG/PNG/GIF/WEBP/SVG'],400);
        if ($file['size']>8*1024*1024) jsonOut(['ok'=>false,'error'=>'Max 8MB'],400);
        $ext=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION)?:'jpg');
        $fname='img_'.$UID.'_'.uniqid().'.'.$ext;
        $dir=__DIR__.'/uploads/images/';
        if (!is_dir($dir)) mkdir($dir,0755,true);
        if (!move_uploaded_file($file['tmp_name'],$dir.$fname)) jsonOut(['ok'=>false,'error'=>'Save failed'],500);
        $proto=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';
        $base=$proto.'://'.$_SERVER['HTTP_HOST'].rtrim(dirname($_SERVER['SCRIPT_NAME']),'/\\');
        $url=$base.'/uploads/images/'.$fname;
        db()->prepare('INSERT INTO images (user_id,filename,original_name,mime,url) VALUES (?,?,?,?,?)')
            ->execute([$UID,$fname,$file['name'],$file['type'],$url]);
        $imgId=db()->lastInsertId();
        jsonOut(['ok'=>true,'id'=>$imgId,'url'=>$url,'filename'=>$fname,'original_name'=>$file['name'],'mime'=>$file['type']]);
    }
    if ($method==='DELETE'&&$id) {
        $s=db()->prepare('SELECT * FROM images WHERE id=?');$s->execute([$id]);$img=$s->fetch();
        if (!$img||(!$IS_ADMIN&&(int)$img['user_id']!==$UID)) jsonOut(['ok'=>false,'message'=>'Not found'],404);
        $path=__DIR__.'/uploads/images/'.$img['filename'];
        if (file_exists($path)) @unlink($path);
        db()->prepare('DELETE FROM images WHERE id=?')->execute([$id]);
        jsonOut(['ok'=>true]);
    }
    jsonOut(['error'=>'Not found'],404);
}

// ── EMAIL LISTS ───────────────────────────────────────────────────
if ($res==='lists') {
    if ($method==='GET'&&$action==='emails') {
        $s=db()->prepare('SELECT l.user_id FROM email_lists l WHERE l.id=?');$s->execute([$id]);$l=$s->fetch();
        if (!$l||(!$IS_ADMIN&&(int)$l['user_id']!==$UID)) jsonOut([]);
        $s=db()->prepare('SELECT * FROM emails WHERE list_id=? LIMIT 500');$s->execute([$id]);jsonOut($s->fetchAll());
    }
    if ($method==='GET') {
        $stmt=$IS_ADMIN
            ? db()->query('SELECT l.*,u.username owner FROM email_lists l LEFT JOIN users u ON u.id=l.user_id ORDER BY l.id DESC')
            : db()->prepare('SELECT * FROM email_lists WHERE user_id=? ORDER BY id DESC');
        if (!$IS_ADMIN) $stmt->execute([$UID]);
        jsonOut($stmt->fetchAll());
    }
    if ($method==='POST') {
        if (empty($_FILES['file'])) jsonOut(['error'=>'No file'],400);
        $name=$_POST['list_name']??'Untitled';$db=db();
        $db->prepare('INSERT INTO email_lists (user_id,name) VALUES (?,?)')->execute([$UID,$name]);
        $lid=$db->lastInsertId();
        $h=fopen($_FILES['file']['tmp_name'],'r');$hdr=null;$ei=-1;$ni=-1;
        $st=$db->prepare('INSERT INTO emails (list_id,email,name,created_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE list_id=?, name=?, created_at=CURRENT_TIMESTAMP');
        
        $db->beginTransaction();
        $batch_count = 0;
        
        while(($row=fgetcsv($h))!==false){
            if(!$hdr){$hdr=array_map('strtolower',array_map('trim',$row));foreach($hdr as $i=>$h2){if(strpos($h2,'email')!==false||strpos($h2,'mail')!==false)$ei=$i;if(strpos($h2,'name')!==false||strpos($h2,'first')!==false)$ni=$i;}if($ei>=0&&strpos($row[$ei]??'','@')!==false){$e=strtolower(trim($row[$ei]));if(filter_var($e,FILTER_VALIDATE_EMAIL)){$nm=trim($row[$ni]??'');$st->execute([$lid,$e,$nm,$lid,$nm]); $batch_count++;}}if($ei===-1)$ei=0;continue;}
            $e=strtolower(trim($row[$ei]??$row[0]??''));if(filter_var($e,FILTER_VALIDATE_EMAIL)){$nm=trim($row[$ni]??'');$st->execute([$lid,$e,$nm,$lid,$nm]); $batch_count++;}
            
            if ($batch_count >= 5000) {
                $db->commit();
                $db->beginTransaction();
                $batch_count = 0;
            }
        }
        $db->commit();
        fclose($h);
        $c=db()->prepare('SELECT COUNT(*) FROM emails WHERE list_id=?');$c->execute([$lid]);$cnt=(int)$c->fetchColumn();
        db()->prepare('UPDATE email_lists SET total_count=? WHERE id=?')->execute([$cnt,$lid]);
        jsonOut(['success'=>true,'imported'=>$cnt]);
    }
    if ($method==='DELETE') {
        $s=db()->prepare('SELECT user_id FROM email_lists WHERE id=?');$s->execute([$id]);$row=$s->fetch();
        if (!$row||(!$IS_ADMIN&&(int)$row['user_id']!==$UID)) jsonOut(['ok'=>false,'message'=>'Not found'],404);
        db()->prepare('DELETE FROM email_lists WHERE id=?')->execute([$id]);
        jsonOut(['success'=>true]);
    }
}

// ── CAMPAIGNS ────────────────────────────────────────────────────
if ($res==='campaigns') {
    if ($method==='GET'&&$id==='stats') {
        // Auto-Reply / Follow-Up totals are derived from send_logs.log_source
        // (set in cron.php Step 3/4) so the dashboard reflects exactly what was
        // dispatched, with no double-counting against campaign stats.
        //
        // Optional date-range filter — when the dashboard picker is set to a
        // preset (today / yesterday / 7d / 15d / this_month / last_month) or
        // a custom YYYY-MM-DD pair, the resolved bounds are appended to every
        // event-based metric (sent/failed counts, inbound reads, campaigns
        // created). Snapshot metrics (running, pending, active follow-ups)
        // are NOT date-filtered — they reflect current state regardless.
        $rng = resolveDateRange(
            $_GET['range']     ?? '',
            $_GET['date_from'] ?? '',
            $_GET['date_to']   ?? ''
        );
        // Date strings come from DateTime->format() so they cannot contain
        // SQL-significant characters; safe to interpolate directly.
        $dfSL  = $rng['active'] ? sprintf(" AND sent_at     BETWEEN '%s' AND '%s'", $rng['from'], $rng['to']) : '';
        $dfCmp = $rng['active'] ? sprintf(" AND created_at  BETWEEN '%s' AND '%s'", $rng['from'], $rng['to']) : '';
        $dfInb = $rng['active'] ? sprintf(" AND received_at BETWEEN '%s' AND '%s'", $rng['from'], $rng['to']) : '';

        $row = [];
        try {
            if ($IS_ADMIN) {
                // total_emails is ALWAYS cumulative (lifetime) — never date-filtered
                $row = db()->query("SELECT
                    (SELECT COUNT(*) FROM send_logs WHERE status='sent'   AND (log_source IS NULL OR log_source='campaign'){$dfSL}) total_sent,
                    (SELECT COUNT(*) FROM send_logs WHERE status='failed' AND (log_source IS NULL OR log_source='campaign'){$dfSL}) total_failed,
                    (SELECT COUNT(*) FROM campaigns WHERE status='running') active,
                    (SELECT COUNT(*) FROM campaigns WHERE 1=1{$dfCmp}) total_campaigns,
                    ((SELECT COUNT(*) FROM emails) + (SELECT COUNT(*) FROM inbound_emails) + (SELECT COUNT(*) FROM autoreply_threads) + (SELECT COUNT(*) FROM followup_contacts)) total_emails,
                    (SELECT COUNT(DISTINCT email) FROM (SELECT e.email FROM emails e WHERE DATE(e.created_at) = CURDATE() UNION SELECT t.from_email AS email FROM autoreply_threads t WHERE DATE(t.created_at) = CURDATE() UNION SELECT c.email FROM followup_contacts c WHERE DATE(c.enrolled_at) = CURDATE() UNION SELECT i.from_email AS email FROM inbound_emails i WHERE DATE(i.received_at) = CURDATE()) _tl) today_leads,
                    (SELECT COUNT(DISTINCT email) FROM (SELECT e.email FROM emails e WHERE YEAR(e.created_at) = YEAR(CURDATE()) AND MONTH(e.created_at) = MONTH(CURDATE()) UNION SELECT t.from_email AS email FROM autoreply_threads t WHERE YEAR(t.created_at) = YEAR(CURDATE()) AND MONTH(t.created_at) = MONTH(CURDATE()) UNION SELECT c.email FROM followup_contacts c WHERE YEAR(c.enrolled_at) = YEAR(CURDATE()) AND MONTH(c.enrolled_at) = MONTH(CURDATE()) UNION SELECT i.from_email AS email FROM inbound_emails i WHERE YEAR(i.received_at) = YEAR(CURDATE()) AND MONTH(i.received_at) = MONTH(CURDATE())) _ml) month_leads,
                    (SELECT COUNT(*) FROM smtp_providers) total_smtps,
                    (SELECT COUNT(*) FROM users WHERE is_admin=0) total_users,
                    (SELECT COUNT(*) FROM campaigns WHERE status IN ('scheduled','running')) total_pending,
                    ".(
                        $rng['active']
                            ? "(SELECT COUNT(*) FROM inbound_emails WHERE 1=1{$dfInb}) total_imap_read"
                            : "(SELECT IFNULL(SUM(emails_read),0) FROM imap_accounts) total_imap_read"
                    ).",
                    (SELECT COUNT(*) FROM followup_contacts WHERE status='active' AND next_send_at IS NOT NULL) total_followup_pending,
                    (SELECT COUNT(*) FROM autoreply_threads WHERE status='active' AND next_send_at IS NOT NULL AND COALESCE(awaiting_reply,0)!=1) total_reply_pending,
                    (SELECT COUNT(*) FROM send_logs WHERE log_source='autoreply' AND status='sent'  {$dfSL}) total_ar_sent,
                    (SELECT COUNT(*) FROM send_logs WHERE log_source='autoreply' AND status='failed'{$dfSL}) total_ar_failed,
                    (SELECT COUNT(*) FROM send_logs WHERE log_source='followup'  AND status='sent'  {$dfSL}) total_fu_sent,
                    (SELECT COUNT(*) FROM send_logs WHERE log_source='followup'  AND status='failed'{$dfSL}) total_fu_failed,
                    (SELECT COUNT(*) FROM autoreply_threads  WHERE status='completed'".($rng['active']?" AND created_at BETWEEN '{$rng['from']}' AND '{$rng['to']}'":"").") total_ar_completed,
                    (SELECT COUNT(*) FROM followup_contacts  WHERE status='completed') total_fu_completed,
                    ".(
                        $rng['active']
                            ? "(SELECT COUNT(*) FROM inbound_emails i WHERE EXISTS (SELECT 1 FROM autoreply_threads t WHERE LOWER(t.from_email)=LOWER(i.from_email)){$dfInb}) total_ar_read"
                            : "(SELECT IFNULL(SUM(messages_received),0) FROM autoreply_threads) total_ar_read"
                    ).",
                    ".(
                        $rng['active']
                            ? "(SELECT COUNT(DISTINCT email) FROM followup_contacts WHERE enrolled_at BETWEEN '{$rng['from']}' AND '{$rng['to']}') total_fu_read"
                            : "(SELECT COUNT(DISTINCT email) FROM followup_contacts) total_fu_read"
                    ))->fetch();
            } else {
                $uImapIds = [0];
                try {
                    $uImSt = db()->prepare("SELECT id FROM imap_accounts WHERE user_id=?");
                    $uImSt->execute([$UID]);
                    foreach ($uImSt->fetchAll(PDO::FETCH_COLUMN) as $imId) { $uImapIds[] = (int)$imId; }

                    $uRlSt = db()->prepare("SELECT imap_id FROM autoreply_rules WHERE user_id=? AND imap_id IS NOT NULL UNION SELECT imap2_id FROM autoreply_rules WHERE user_id=? AND imap2_id IS NOT NULL UNION SELECT imap_id FROM followup_rules WHERE user_id=? AND imap_id IS NOT NULL");
                    $uRlSt->execute([$UID, $UID, $UID]);
                    foreach ($uRlSt->fetchAll(PDO::FETCH_COLUMN) as $imId) { $uImapIds[] = (int)$imId; }

                    $cuSt = db()->prepare('SELECT assigned_imap_ids FROM users WHERE id=?');
                    $cuSt->execute([$UID]); $cuRow = $cuSt->fetch();
                    if ($cuRow && !empty($cuRow['assigned_imap_ids'])) {
                        $dArr = json_decode($cuRow['assigned_imap_ids'], true);
                        if (is_array($dArr)) { foreach ($dArr as $imId) { $uImapIds[] = (int)$imId; } }
                    }
                } catch (Exception $_eIM) {}
                $uImapIds = array_values(array_unique(array_filter($uImapIds)));
                if (empty($uImapIds)) $uImapIds = [0];
                $imapIdList = implode(',', $uImapIds);
                // total_emails is ALWAYS cumulative (lifetime) — never date-filtered
                $s=db()->prepare("SELECT
                    (SELECT COUNT(*) FROM send_logs sl LEFT JOIN campaigns c ON c.id=sl.campaign_id WHERE sl.status='sent'   AND (sl.log_source IS NULL OR sl.log_source='campaign') AND COALESCE(sl.user_id, c.user_id)=?".($rng['active']?" AND sl.sent_at BETWEEN '{$rng['from']}' AND '{$rng['to']}'":"").") total_sent,
                    (SELECT COUNT(*) FROM send_logs sl LEFT JOIN campaigns c ON c.id=sl.campaign_id WHERE sl.status='failed' AND (sl.log_source IS NULL OR sl.log_source='campaign') AND COALESCE(sl.user_id, c.user_id)=?".($rng['active']?" AND sl.sent_at BETWEEN '{$rng['from']}' AND '{$rng['to']}'":"").") total_failed,
                    (SELECT COUNT(*) FROM campaigns WHERE status='running' AND user_id=?) active,
                    (SELECT COUNT(*) FROM campaigns WHERE user_id=?{$dfCmp}) total_campaigns,
                    ((SELECT COUNT(*) FROM emails e JOIN email_lists l ON l.id=e.list_id WHERE l.user_id={$UID}) + (SELECT COUNT(*) FROM inbound_emails WHERE imap_account_id IN ({$imapIdList})) + (SELECT COUNT(*) FROM autoreply_threads t JOIN autoreply_rules r ON r.id=t.rule_id WHERE r.user_id={$UID}) + (SELECT COUNT(*) FROM followup_contacts c JOIN followup_rules r ON r.id=c.rule_id WHERE r.user_id={$UID})) total_emails,
                    (SELECT COUNT(DISTINCT email) FROM (SELECT e.email FROM emails e JOIN email_lists l ON l.id = e.list_id WHERE l.user_id={$UID} AND DATE(e.created_at) = CURDATE() UNION SELECT t.from_email AS email FROM autoreply_threads t JOIN autoreply_rules r ON r.id=t.rule_id WHERE r.user_id={$UID} AND DATE(t.created_at) = CURDATE() UNION SELECT c.email FROM followup_contacts c JOIN followup_rules r ON r.id=c.rule_id WHERE r.user_id={$UID} AND DATE(c.enrolled_at) = CURDATE() UNION SELECT i.from_email AS email FROM inbound_emails i WHERE i.imap_account_id IN ({$imapIdList}) AND DATE(i.received_at) = CURDATE()) _tl) today_leads,
                    (SELECT COUNT(DISTINCT email) FROM (SELECT e.email FROM emails e JOIN email_lists l ON l.id = e.list_id WHERE l.user_id={$UID} AND YEAR(e.created_at) = YEAR(CURDATE()) AND MONTH(e.created_at) = MONTH(CURDATE()) UNION SELECT t.from_email AS email FROM autoreply_threads t JOIN autoreply_rules r ON r.id=t.rule_id WHERE r.user_id={$UID} AND YEAR(t.created_at) = YEAR(CURDATE()) AND MONTH(t.created_at) = MONTH(CURDATE()) UNION SELECT c.email FROM followup_contacts c JOIN followup_rules r ON r.id=c.rule_id WHERE r.user_id={$UID} AND YEAR(c.enrolled_at) = YEAR(CURDATE()) AND MONTH(c.enrolled_at) = MONTH(CURDATE()) UNION SELECT i.from_email AS email FROM inbound_emails i WHERE i.imap_account_id IN ({$imapIdList}) AND YEAR(i.received_at) = YEAR(CURDATE()) AND MONTH(i.received_at) = MONTH(CURDATE())) _ml) month_leads,
                    (SELECT COUNT(*) FROM smtp_providers WHERE user_id=?) total_smtps,
                    (SELECT COUNT(*) FROM campaigns WHERE status IN ('scheduled','running') AND user_id=?) total_pending,
                    ".(
                        $rng['active']
                            ? "(SELECT COUNT(*) FROM inbound_emails WHERE imap_account_id IN ({$imapIdList}){$dfInb}) total_imap_read"
                            : "(SELECT IFNULL(SUM(ia.emails_read),0) FROM imap_accounts ia WHERE ia.user_id={$UID}) total_imap_read"
                    ).",
                    (SELECT COUNT(*) FROM followup_contacts fc JOIN followup_rules fr ON fr.id=fc.rule_id WHERE fc.status='active' AND fc.next_send_at IS NOT NULL AND fr.user_id=?) total_followup_pending,
                    (SELECT COUNT(*) FROM autoreply_threads t JOIN autoreply_rules r ON r.id=t.rule_id WHERE t.status='active' AND t.next_send_at IS NOT NULL AND COALESCE(t.awaiting_reply,0)!=1 AND r.user_id=?) total_reply_pending,
                    (SELECT COUNT(*) FROM send_logs WHERE log_source='autoreply' AND status='sent'   AND user_id=?{$dfSL}) total_ar_sent,
                    (SELECT COUNT(*) FROM send_logs WHERE log_source='autoreply' AND status='failed' AND user_id=?{$dfSL}) total_ar_failed,
                    (SELECT COUNT(*) FROM send_logs WHERE log_source='followup'  AND status='sent'   AND user_id=?{$dfSL}) total_fu_sent,
                    (SELECT COUNT(*) FROM send_logs WHERE log_source='followup'  AND status='failed' AND user_id=?{$dfSL}) total_fu_failed,
                    (SELECT COUNT(*) FROM autoreply_threads t JOIN autoreply_rules r ON r.id=t.rule_id WHERE t.status='completed' AND r.user_id=?".($rng['active']?" AND t.created_at BETWEEN '{$rng['from']}' AND '{$rng['to']}'":"").") total_ar_completed,
                    (SELECT COUNT(*) FROM followup_contacts c JOIN followup_rules r ON r.id=c.rule_id WHERE c.status='completed' AND r.user_id=?) total_fu_completed,
                    ".(
                        $rng['active']
                            ? "(SELECT COUNT(*) FROM inbound_emails WHERE imap_account_id IN ({$imapIdList}){$dfInb}) total_ar_read"
                            : "(SELECT IFNULL(SUM(t.messages_received),0) FROM autoreply_threads t JOIN autoreply_rules r ON r.id=t.rule_id WHERE r.user_id={$UID}) total_ar_read"
                    ).",
                    ".(
                        $rng['active']
                            ? "(SELECT COUNT(DISTINCT c.email) FROM followup_contacts c JOIN followup_rules r ON r.id=c.rule_id WHERE r.user_id={$UID} AND c.enrolled_at BETWEEN '{$rng['from']}' AND '{$rng['to']}') total_fu_read"
                            : "(SELECT COUNT(DISTINCT c.email) FROM followup_contacts c JOIN followup_rules r ON r.id=c.rule_id WHERE r.user_id={$UID}) total_fu_read"
                    ));
                $s->execute(array_fill(0, 14, $UID));
                $row = $s->fetch() ?: [];
            }
        } catch (Exception $e) {
            error_log('MailPro stats query failed: ' . $e->getMessage());
            $row = [];
        }

        if (!$IS_ADMIN && $UID) {
            try {
                $uInfo=db()->prepare('SELECT daily_send_limit,imap_read_limit,autoreply_limit,followup_limit,expires_at FROM users WHERE id=?');
                $uInfo->execute([$UID]); $uRow=$uInfo->fetch();
                if ($uRow) {
                    // daily_send_limit = per-DAY cap (e.g. 1000/day)
                    // imap_read_limit  = per-MINUTE rate (e.g. 200/min per cron run)
                    $dailyImapLimit = (int)($uRow['daily_send_limit'] ?? 0);
                    if ($dailyImapLimit <= 0) $dailyImapLimit = 1000; // fallback
                    $row['daily_send_limit'] = $dailyImapLimit;
                    $row['imap_read_limit']  = (int)($uRow['imap_read_limit'] ?? 0);
                    $row['expires_at'] = $uRow['expires_at'] ?? null;
                    $todayLeadsCount = (int)($row['today_leads'] ?? 0);
                    $row['today_remaining'] = max(0, $dailyImapLimit - $todayLeadsCount);

                    $arLimit = (int)($uRow['autoreply_limit'] ?? 0);
                    $fuLimit = (int)($uRow['followup_limit']  ?? 0);
                    $arUsed = 0; $fuUsed = 0;
                    try {
                        $arUsedQ = db()->prepare('SELECT COUNT(*) FROM autoreply_steps s JOIN autoreply_rules r ON r.id = s.rule_id WHERE r.user_id = ?');
                        $arUsedQ->execute([$UID]); $arUsed = (int)$arUsedQ->fetchColumn();
                    } catch(Exception $e){}
                    try {
                        $fuUsedQ = db()->prepare('SELECT COUNT(*) FROM followup_steps s JOIN followup_rules r ON r.id = s.rule_id WHERE r.user_id = ?');
                        $fuUsedQ->execute([$UID]); $fuUsed = (int)$fuUsedQ->fetchColumn();
                    } catch(Exception $e){}
                    $row['autoreply_limit']     = $arLimit;
                    $row['autoreply_used']      = $arUsed;
                    $row['autoreply_remaining'] = $arLimit > 0 ? max(0, $arLimit - $arUsed) : 0;
                    $row['followup_limit']      = $fuLimit;
                    $row['followup_used']       = $fuUsed;
                    $row['followup_remaining']  = $fuLimit > 0 ? max(0, $fuLimit - $fuUsed) : 0;
                }
            } catch(Exception $e){}
        }

        // Ensure all expected keys have integer/valid values
        $statKeys = ['total_sent','total_failed','active','total_campaigns','total_emails','today_leads','month_leads','total_smtps','total_users','total_pending','total_imap_read','total_followup_pending','total_reply_pending','total_ar_sent','total_ar_failed','total_fu_sent','total_fu_failed','total_ar_completed','total_fu_completed','total_ar_read','total_fu_read'];
        foreach ($statKeys as $k) {
            $row[$k] = isset($row[$k]) ? (int)$row[$k] : 0;
        }

        // ── Unified Live Reporting Dashboard extras ───────────────────
        // total_leads      → subscribers across email lists (cumulative).
        // total_pending_leads → leads still queued for any outbound action
        //                       (AR pending threads + FU pending contacts).
        // reply_rate       → replies (AR+FU read) / sent (AR+FU sent), %
        // conversion_rate  → completed sequences / sent, %
        // active_campaigns → snapshot count of currently-running campaigns
        // total_sent_emails → AR+FU+campaign sent during the active range
        $arSent = (int)($row['total_ar_sent'] ?? 0);
        $fuSent = (int)($row['total_fu_sent'] ?? 0);
        $camSent= (int)($row['total_sent']    ?? 0);
        $arRead = (int)($row['total_ar_read'] ?? 0);
        $fuRead = (int)($row['total_fu_read'] ?? 0);
        $arDone = (int)($row['total_ar_completed'] ?? 0);
        $fuDone = (int)($row['total_fu_completed'] ?? 0);

        $row['total_leads']         = (int)($row['total_emails'] ?? 0);
        $row['today_leads']         = (int)($row['today_leads'] ?? 0);
        $row['month_leads']         = (int)($row['month_leads'] ?? 0);
        $row['total_pending_leads'] = (int)($row['total_reply_pending'] ?? 0)
                                    + (int)($row['total_followup_pending'] ?? 0);
        $row['active_campaigns']    = (int)($row['active'] ?? 0);
        $row['total_sent_emails']   = $arSent + $fuSent + $camSent;

        $autoTotalSent = $arSent + $fuSent;
        $autoTotalRead = $arRead + $fuRead;
        $row['reply_rate']      = $autoTotalSent > 0
            ? round($autoTotalRead / $autoTotalSent * 100, 1) : 0;
        $row['conversion_rate'] = $autoTotalSent > 0
            ? round(($arDone + $fuDone) / $autoTotalSent * 100, 1) : 0;

        // ── Hourly & daily performance series ─────────────────────────
        // Hourly: 24-bucket array of (AR+FU+campaign) sends for *today*.
        // Daily : 14-day array of same metric, oldest → newest.
        // Snapshot — independent of the date-range picker so the charts
        // always render a meaningful timeline.
        $hourly = array_fill(0, 24, 0);
        $daily  = [];
        $dailyLabels = [];
        for ($i=13; $i>=0; $i--) {
            $dailyLabels[] = date('Y-m-d', strtotime("-{$i} days"));
            $daily[] = 0;
        }
        try {
            $today = date('Y-m-d');
            $since = date('Y-m-d 00:00:00', strtotime('-13 days'));
            if ($IS_ADMIN) {
                $h = db()->prepare("SELECT HOUR(sent_at) h, COUNT(*) c FROM send_logs WHERE status='sent' AND DATE(sent_at)=? GROUP BY HOUR(sent_at)");
                $h->execute([$today]);
                $d = db()->prepare("SELECT DATE(sent_at) d, COUNT(*) c FROM send_logs WHERE status='sent' AND sent_at>=? GROUP BY DATE(sent_at)");
                $d->execute([$since]);
            } else {
                $h = db()->prepare("SELECT HOUR(sl.sent_at) h, COUNT(*) c FROM send_logs sl LEFT JOIN campaigns c ON c.id=sl.campaign_id WHERE sl.status='sent' AND DATE(sl.sent_at)=? AND COALESCE(sl.user_id,c.user_id)=? GROUP BY HOUR(sl.sent_at)");
                $h->execute([$today, $UID]);
                $d = db()->prepare("SELECT DATE(sl.sent_at) d, COUNT(*) c FROM send_logs sl LEFT JOIN campaigns c ON c.id=sl.campaign_id WHERE sl.status='sent' AND sl.sent_at>=? AND COALESCE(sl.user_id,c.user_id)=? GROUP BY DATE(sl.sent_at)");
                $d->execute([$since, $UID]);
            }
            foreach ($h->fetchAll() as $rw) {
                $hr = (int)$rw['h'];
                if ($hr>=0 && $hr<24) $hourly[$hr] = (int)$rw['c'];
            }
            $dailyMap = [];
            foreach ($d->fetchAll() as $rw) $dailyMap[$rw['d']] = (int)$rw['c'];
            foreach ($dailyLabels as $idx => $lbl) {
                if (isset($dailyMap[$lbl])) $daily[$idx] = $dailyMap[$lbl];
            }
        } catch (Throwable $e) {
            // Soft-fail — keep zero-filled arrays so frontend still renders.
        }
        $row['hourly_sent']  = $hourly;
        $row['daily_sent']   = $daily;
        $row['daily_labels'] = $dailyLabels;
        $row['generated_at'] = date('c');

        jsonOut($row);
    }
    if ($method==='GET'&&$action==='logs') {
        $s=db()->prepare('SELECT sl.* FROM send_logs sl LEFT JOIN campaigns c ON c.id=sl.campaign_id WHERE sl.campaign_id=?'.($IS_ADMIN?'':' AND (sl.user_id=? OR c.user_id=?)').' ORDER BY sl.id DESC LIMIT 500');
        $p=[$id]; if(!$IS_ADMIN){$p[]=$UID;$p[]=$UID;}
        $s->execute($p); jsonOut($s->fetchAll());
    }
    if ($method==='GET'&&!$id) {
        if ($IS_ADMIN) {
            $rows=db()->query("SELECT c.*,l.name list_name,l.total_count,u.username owner,
                (SELECT COUNT(*) FROM send_logs sl WHERE sl.campaign_id=c.id AND sl.status='sent') sent_count,
                (SELECT COUNT(*) FROM send_logs sl WHERE sl.campaign_id=c.id AND sl.status='failed') failed_count
                FROM campaigns c LEFT JOIN email_lists l ON c.list_id=l.id LEFT JOIN users u ON u.id=c.user_id ORDER BY c.id DESC")->fetchAll();
        } else {
            $stmt=db()->prepare("SELECT c.*,l.name list_name,l.total_count,
                (SELECT COUNT(*) FROM send_logs sl WHERE sl.campaign_id=c.id AND sl.status='sent') sent_count,
                (SELECT COUNT(*) FROM send_logs sl WHERE sl.campaign_id=c.id AND sl.status='failed') failed_count
                FROM campaigns c LEFT JOIN email_lists l ON c.list_id=l.id WHERE c.user_id=? ORDER BY c.id DESC");
            $stmt->execute([$UID]); $rows=$stmt->fetchAll();
        }
        jsonOut($rows??[]);
    }
    if ($method==='GET'&&$id&&!$action) {
        $s=db()->prepare('SELECT * FROM campaigns WHERE id=?'.($IS_ADMIN?'':' AND user_id=?'));
        $p=[$id]; if(!$IS_ADMIN)$p[]=$UID;
        $s->execute($p); jsonOut($s->fetch()?:[]);
    }
    if ($method==='POST'&&$action==='pause') {
        db()->prepare("UPDATE campaigns SET status='paused' WHERE id=?".($IS_ADMIN?'':' AND user_id=?'))->execute($IS_ADMIN?[$id]:[$id,$UID]);
        jsonOut(['success'=>true]);
    }
    if ($method==='POST'&&$action==='resume') {
        db()->prepare("UPDATE campaigns SET status='scheduled' WHERE id=?".($IS_ADMIN?'':' AND user_id=?'))->execute($IS_ADMIN?[$id]:[$id,$UID]);
        jsonOut(['success'=>true]);
    }
    if ($method==='POST'&&$action==='send-now') {
        db()->prepare("UPDATE campaigns SET status='scheduled',scheduled_at=NOW() WHERE id=?".($IS_ADMIN?'':' AND user_id=?'))->execute($IS_ADMIN?[$id]:[$id,$UID]);
        jsonOut(['success'=>true]);
    }

    // ── TEST SEND ──────────────────────────────────────────────────
    if ($method==='POST'&&$action==='test-send') {
        session_write_close();
        ini_set('default_socket_timeout', 10);
        $b=body();
        $testEmail=filter_var($b['test_email']??'',FILTER_VALIDATE_EMAIL);
        if (!$testEmail) jsonOut(['ok'=>false,'message'=>'Invalid test email']);
        $s=db()->prepare('SELECT * FROM campaigns WHERE id=?'.($IS_ADMIN?'':' AND user_id=?'));
        $p=[$id]; if(!$IS_ADMIN)$p[]=$UID;
        $s->execute($p); $c=$s->fetch();
        if (!$c) jsonOut(['ok'=>false,'message'=>'Campaign not found']);

        $smtpIds=[];
        if (!empty($c['smtp_ids'])){$d=json_decode($c['smtp_ids'],true);if(is_array($d))$smtpIds=$d;}
        if (empty($smtpIds)&&!empty($c['smtp_id']))$smtpIds=[$c['smtp_id']];
        if (empty($smtpIds)) jsonOut(['ok'=>false,'message'=>'No SMTP configured']);

        $ss=db()->prepare('SELECT * FROM smtp_providers WHERE id=?');$ss->execute([$smtpIds[0]]);$smtp=$ss->fetch();
        if (!$smtp) jsonOut(['ok'=>false,'message'=>'SMTP not found']);

        // Pick random variant
        $variants=[];
        if (!empty($c['variants'])) {$v=json_decode($c['variants'],true);if(is_array($v)&&count($v))$variants=$v;}
        if (empty($variants)) jsonOut(['ok'=>false,'message'=>'No variants configured']);
        $variant=$variants[array_rand($variants)];

        // Resolve inline images BEFORE spin/personalize so {{image}} placeholder survives
        $inlineImages=[];
        $imgWidth  = $variant['img_width']    ?? '600';
        $imgAlign  = $variant['img_align']    ?? 'center';
        $imgPos    = $variant['img_position'] ?? 'top';
        $imageIds  = array_values(array_filter(array_map('intval', $variant['image_ids']??[]), fn($v)=>$v>0));

        // spin → personalize → embed image (same order as cron buildMessage)
        // Apply campaign sender_name → display name → SMTP from_name (priority order)
        $campaignSenderName = trim($c['sender_name'] ?? '');
        if ($campaignSenderName !== '') {
            $smtp['from_name'] = $campaignSenderName;
        } else {
            // Fall back to user display name (same as cron applyDisplayName)
            $dnStmt = db()->prepare('SELECT meta_value FROM user_meta WHERE user_id=? AND meta_key=?');
            $dnStmt->execute([$c['user_id'], 'display_name']);
            $dnRow = $dnStmt->fetch(PDO::FETCH_ASSOC);
            if ($dnRow && !empty($dnRow['meta_value'])) {
                $smtp['from_name'] = $dnRow['meta_value'];
            }
        }
        $senderName = $smtp['from_name'] ?? '';
        $todayDate  = date('F j, Y g:i A');
        $subject = personalize(spin($variant['subject']??'(no subject)'), 'Test User', $testEmail, $senderName, $todayDate);
        $html    = personalize(spin($variant['html_body']??''),           'Test User', $testEmail, $senderName, $todayDate);
        $text    = personalize(spin($variant['text_body']??strip_tags($variant['html_body']??'')), 'Test User', $testEmail, $senderName, $todayDate);
        if (!empty($imageIds)) $html = resolveImages($html, $imageIds, $inlineImages, $imgWidth, $imgAlign, $imgPos);

        try {
            (new Mailer($smtp))->send($testEmail,'Test User',$subject,$html,$text,$inlineImages);
            jsonOut(['ok'=>true,'message'=>"✅ Test sent to {$testEmail} — SMTP: {$smtp['name']} — From: {$senderName} — Variant: ".($variant['label']??'#1')]);
        } catch(Exception $e) {
            jsonOut(['ok'=>false,'message'=>'❌ '.$e->getMessage()]);
        }
    }

    // ── CREATE CAMPAIGN ───────────────────────────────────────────
    if ($method==='POST'&&!$action) {
        $chk=checkUserLimit($CUR,'campaign_count');
        if (!$chk['ok']) jsonOut(['ok'=>false,'message'=>$chk['msg']]);
        $b=body();
        $smtpIds=array_values(array_filter(array_map('intval',$b['smtp_ids']??($b['smtp_id']?[$b['smtp_id']]:[]))));;
        $primarySid=count($smtpIds)?$smtpIds[0]:null;
        $fromEmails=$b['from_emails']??[];
        $variants=$b['variants']??[];
        $senderName=trim($b['sender_name']??'');
        db()->prepare('INSERT INTO campaigns (user_id,name,smtp_id,smtp_ids,from_emails,list_id,scheduled_at,per_minute_limit,daily_limit,variants,sender_name) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$UID,$b['name']??'Untitled',$primarySid,count($smtpIds)?json_encode($smtpIds):null,count($fromEmails)?json_encode($fromEmails):null,$b['list_id']??null,$b['scheduled_at']??null,(int)($b['per_minute_limit']??10),(int)($b['daily_limit']??500),count($variants)?json_encode($variants):null,$senderName!==''?$senderName:null]);
        jsonOut(['success'=>true,'id'=>db()->lastInsertId()]);
    }
    // ── UPDATE CAMPAIGN ───────────────────────────────────────────
    if ($method==='PUT'&&$id) {
        $s=db()->prepare('SELECT user_id FROM campaigns WHERE id=?');$s->execute([$id]);$row=$s->fetch();
        if (!$row||(!$IS_ADMIN&&(int)$row['user_id']!==$UID)) jsonOut(['ok'=>false,'message'=>'Not found'],404);
        $b=body();
        $smtpIds=array_values(array_filter(array_map('intval',$b['smtp_ids']??($b['smtp_id']?[$b['smtp_id']]:[]))));
        $primarySid=count($smtpIds)?$smtpIds[0]:null;
        $fromEmails=$b['from_emails']??[];
        $variants=$b['variants']??[];
        $senderName=trim($b['sender_name']??'');
        db()->prepare('UPDATE campaigns SET name=?,smtp_id=?,smtp_ids=?,from_emails=?,list_id=?,scheduled_at=?,per_minute_limit=?,daily_limit=?,variants=?,sender_name=? WHERE id=?')
            ->execute([$b['name']??'Untitled',$primarySid,count($smtpIds)?json_encode($smtpIds):null,count($fromEmails)?json_encode($fromEmails):null,$b['list_id']??null,$b['scheduled_at']??null,(int)($b['per_minute_limit']??10),(int)($b['daily_limit']??500),count($variants)?json_encode($variants):null,$senderName!==''?$senderName:null,$id]);
        jsonOut(['success'=>true]);
    }
    if ($method==='DELETE'&&$id) {
        $s=db()->prepare('SELECT user_id FROM campaigns WHERE id=?');$s->execute([$id]);$row=$s->fetch();
        if (!$row||(!$IS_ADMIN&&(int)$row['user_id']!==$UID)) jsonOut(['ok'=>false,'message'=>'Not found'],404);
        db()->prepare('DELETE FROM campaigns WHERE id=?')->execute([$id]);
        jsonOut(['success'=>true]);
    }
}

// ── CRON INFO ─────────────────────────────────────────────────────
if ($res==='cron') {
    if ($method==='GET'&&$id==='info') {
        requireAdmin();
        $cfg=getConfig();
        // Auto-generate key if missing
        if (empty($cfg['cron_key'])) {
            $cfg['cron_key']=bin2hex(random_bytes(16));
            file_put_contents(CONFIG_FILE, json_encode($cfg, JSON_PRETTY_PRINT));
        }
        $proto=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';
        $base=$proto.'://'.$_SERVER['HTTP_HOST'].rtrim(dirname($_SERVER['SCRIPT_NAME']),'/\\');
        jsonOut(['cron_key'=>$cfg['cron_key'],'cron_url'=>$base.'/cron.php?key='.$cfg['cron_key']]);
    }
    if ($method==='POST'&&$id==='regen-key') {
        requireAdmin();
        $cfg=getConfig();
        $cfg['cron_key']=bin2hex(random_bytes(16));
        if (file_put_contents(CONFIG_FILE, json_encode($cfg, JSON_PRETTY_PRINT))===false)
            jsonOut(['ok'=>false,'message'=>'Cannot write config.json — check file permissions']);
        $proto=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';
        $base=$proto.'://'.$_SERVER['HTTP_HOST'].rtrim(dirname($_SERVER['SCRIPT_NAME']),'/\\');
        jsonOut(['ok'=>true,'cron_key'=>$cfg['cron_key'],'cron_url'=>$base.'/cron.php?key='.$cfg['cron_key']]);
    }
    if ($method==='POST'&&$id==='run') {
        requireAdmin();
        $cfg     = getConfig();
        $cronKey = $cfg['cron_key'] ?? '';
        $proto   = (!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off') ? 'https' : 'http';
        $base    = $proto.'://'.$_SERVER['HTTP_HOST'].rtrim(dirname($_SERVER['SCRIPT_NAME']),'/\\');
        $cronUrl = $base.'/cron.php?key='.urlencode($cronKey).'&json=1';

        // ── Attempt 1: HTTP self-request (cleanest — own process, own memory) ──
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'timeout'       => 120,
                'ignore_errors' => true,
                'header'        => "Connection: close\r\n",
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $output = @file_get_contents($cronUrl, false, $ctx);

        // ── Attempt 2: proc_open (PHP CLI subprocess) ──────────────────────────
        // Used when the HTTP loopback is blocked (common on shared/VPS hosting).
        // This ALWAYS runs cron.php in a completely separate PHP process so there
        // are zero function-redeclaration issues regardless of how many times the
        // button is clicked.
        if ($output === false && function_exists('proc_open')) {
            $phpBin  = PHP_BINARY ?: 'php';
            $cronPhp = __DIR__ . '/cron.php';
            $desc    = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
            $env     = ['CRON_KEY' => $cronKey, 'CRON_JSON' => '1'];
            $proc    = @proc_open(
                escapeshellarg($phpBin) . ' ' . escapeshellarg($cronPhp),
                $desc, $pipes, __DIR__, $env
            );
            if (is_resource($proc)) {
                fclose($pipes[0]);
                $output = stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
            }
        }

        // ── Attempt 3: direct include (last resort, same process) ─────────────
        // cron.php checks php_sapi_name() !== 'cli' and requires the key via $_GET.
        // Only safe on the very first call in this PHP process; the buildMessage
        // guard prevents a fatal redeclaration on repeat clicks.
        if ($output === false) {
            $_GET['key']  = $cronKey;
            $_GET['json'] = '1';
            $prevErr = error_reporting(0);
            ob_start();
            if (!function_exists('buildMessage')) {
                try { include __DIR__ . '/cron.php'; }
                catch (Exception $incEx) {
                    echo json_encode(['ok'=>false,'error'=>$incEx->getMessage(),'results'=>[]]);
                }
            } else {
                // Already included — cannot safely include again.
                // Surface a helpful message so the admin knows to use the cron URL.
                echo json_encode([
                    'ok'      => false,
                    'error'   => 'Please use the Cron URL directly (copy it from the Cron tab) or set up a real server cron job. The in-process fallback cannot run twice.',
                    'results' => [],
                ]);
            }
            $output = ob_get_clean();
            error_reporting($prevErr);
        }

        if (!$output) jsonOut(['ok'=>false,'error'=>'Cron did not respond — check PHP error logs','results'=>[]]);
        $jsonStart = strpos($output, '{');
        if ($jsonStart !== false) $output = substr($output, $jsonStart);
        $decoded = json_decode($output, true);
        if ($decoded) jsonOut($decoded);
        jsonOut(['ok'=>false,'error'=>'Cron returned invalid output: '.htmlspecialchars(substr(trim($output),0,300)),'results'=>[]]);
    }
    if ($method==='GET'&&$id==='logs') {
        requireAdmin();
        $all = isset($_GET['all']) ? true : false;
        $limit = $all ? 500 : 200;
        jsonOut(db()->query("SELECT sl.*, COALESCE(c.name,'(deleted)') campaign_name, COALESCE(u.username,'—') owner FROM send_logs sl LEFT JOIN campaigns c ON c.id=sl.campaign_id LEFT JOIN users u ON u.id=COALESCE(sl.user_id,c.user_id) ORDER BY sl.id DESC LIMIT $limit")->fetchAll());
    }
    jsonOut(['error'=>'Not found'],404);
}

// ── SEND LOG ──────────────────────────────────────────────────────
if ($res==='sendlog') {
    // DELETE — clear logs
    if ($method==='DELETE') {
        $status = $_GET['status']??'';
        if ($IS_ADMIN) {
            if ($status==='failed') db()->exec("DELETE FROM send_logs WHERE status='failed'");
            else db()->exec("DELETE FROM send_logs");
        } else {
            if ($status==='failed') db()->prepare("DELETE FROM send_logs WHERE user_id=? AND status='failed'")->execute([$UID]);
            else db()->prepare("DELETE FROM send_logs WHERE user_id=?")->execute([$UID]);
        }
        jsonOut(['ok'=>true]);
    }
    $page   = max(1,(int)($_GET['page']??1));
    $limit  = 100;
    $offset = ($page-1)*$limit;

    // Build WHERE: admin sees all; users see only their own logs (via sl.user_id)
    $where  = $IS_ADMIN ? '1=1' : '(sl.user_id='.$UID.' OR c.user_id='.$UID.')';
    $status = $_GET['status']??'';
    $camp   = $_GET['campaign']??'';
    $search = $_GET['q']??'';
    $source = $_GET['source']??''; // optional: campaign | autoreply | followup

    if ($status==='sent')   $where.=" AND sl.status='sent'";
    if ($status==='failed') $where.=" AND sl.status='failed'";
    if ($camp)  $where.=" AND sl.campaign_id=".intval($camp);
    if ($source) $where.=" AND sl.log_source=".db()->quote($source);
    if ($search) $where.=" AND (sl.email LIKE ".db()->quote('%'.$search.'%')
        ." OR COALESCE(c.name,'') LIKE ".db()->quote('%'.$search.'%')
        ." OR COALESCE(sl.smtp_name_used,'') LIKE ".db()->quote('%'.$search.'%')
        ." OR COALESCE(sl.from_email_used,'') LIKE ".db()->quote('%'.$search.'%')
        ." OR COALESCE(sl.log_source,'') LIKE ".db()->quote('%'.$search.'%').")";

    $total = (int)db()->query("SELECT COUNT(*) FROM send_logs sl LEFT JOIN campaigns c ON c.id=sl.campaign_id LEFT JOIN users u ON u.id=COALESCE(sl.user_id,c.user_id) WHERE $where")->fetchColumn();
    $rows  = db()->query("SELECT sl.id, sl.email, sl.status, sl.error, sl.error_code,
        sl.smtp_name_used, sl.from_email_used, sl.variant_index, sl.sent_at, sl.campaign_id,
        COALESCE(sl.log_source,'campaign') log_source,
        CASE
            WHEN COALESCE(sl.log_source,'campaign')='autoreply' THEN '⚡ Auto-Reply'
            WHEN COALESCE(sl.log_source,'campaign')='followup'  THEN '📬 Follow-Up'
            WHEN c.name IS NOT NULL                              THEN c.name
            ELSE '(deleted)'
        END campaign_name,
        COALESCE(u.username,'—') owner
        FROM send_logs sl
        LEFT JOIN campaigns c ON c.id=sl.campaign_id
        LEFT JOIN users u ON u.id=COALESCE(sl.user_id,c.user_id)
        WHERE $where ORDER BY sl.id DESC LIMIT $limit OFFSET $offset")->fetchAll();

    // Stats — cast to int so frontend never gets null/"0" ambiguity
    $statsRow = db()->query("SELECT COUNT(*) total, COALESCE(SUM(sl.status='sent'),0) sent, COALESCE(SUM(sl.status='failed'),0) failed FROM send_logs sl LEFT JOIN campaigns c ON c.id=sl.campaign_id LEFT JOIN users u ON u.id=COALESCE(sl.user_id,c.user_id) WHERE $where")->fetch();
    $stats = ['total'=>(int)($statsRow['total']??0),'sent'=>(int)($statsRow['sent']??0),'failed'=>(int)($statsRow['failed']??0)];

    jsonOut(['rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>(int)ceil($total/$limit),'stats'=>$stats]);
}

// ── ERROR LOG ─────────────────────────────────────────────────────
if ($res==='errorlog') {
    $page   = max(1,(int)($_GET['page']??1));
    $limit  = 100;
    $offset = ($page-1)*$limit;

    $where  = $IS_ADMIN ? "sl.status='failed'" : "(sl.user_id=$UID OR c.user_id=$UID) AND sl.status='failed'";
    $camp   = $_GET['campaign']??'';
    $search = $_GET['q']??'';

    if ($camp)   $where.=" AND sl.campaign_id=".intval($camp);
    if ($search) $where.=" AND (sl.email LIKE ".db()->quote('%'.$search.'%')." OR sl.error LIKE ".db()->quote('%'.$search.'%')." OR c.name LIKE ".db()->quote('%'.$search.'%').")";

    $total = db()->query("SELECT COUNT(*) FROM send_logs sl LEFT JOIN campaigns c ON c.id=sl.campaign_id LEFT JOIN users u ON u.id=COALESCE(sl.user_id,c.user_id) WHERE $where")->fetchColumn();
    $rows  = db()->query("SELECT sl.id,sl.email,sl.error,sl.error_code,sl.smtp_name_used,sl.from_email_used,sl.variant_index,sl.sent_at, COALESCE(c.name,'(deleted)') campaign_name,sl.campaign_id, COALESCE(u.username,'—') owner FROM send_logs sl LEFT JOIN campaigns c ON c.id=sl.campaign_id LEFT JOIN users u ON u.id=COALESCE(sl.user_id,c.user_id) WHERE $where ORDER BY sl.id DESC LIMIT $limit OFFSET $offset")->fetchAll();

    // Top errors grouped
    $topErrors = db()->query("SELECT sl.error, COUNT(*) cnt FROM send_logs sl LEFT JOIN campaigns c ON c.id=sl.campaign_id LEFT JOIN users u ON u.id=COALESCE(sl.user_id,c.user_id) WHERE $where AND sl.error IS NOT NULL AND sl.error!='' GROUP BY sl.error ORDER BY cnt DESC LIMIT 10")->fetchAll();

    jsonOut(['rows'=>$rows,'total'=>(int)$total,'page'=>$page,'pages'=>(int)ceil($total/$limit),'top_errors'=>$topErrors]);
}


// ── SEQUENCES ─────────────────────────────────────────────────────
// ── IMAP ACCOUNTS ─────────────────────────────────────────────────
if ($res==='imap') {
    // ── Admin-only: per-minute IMAP read limit ────────────────────────
    // Stored in config.json so it survives schema changes and is readable
    // by cron.php without a DB roundtrip. Default 100 — matches the
    // hardcoded cap that was previously baked into imapFetchSinceUid().
    // Each cron run reads at most this many messages PER IMAP account.
    // When the cron is scheduled every minute (the default), this becomes
    // an effective "emails read per minute per account" cap.
    if ($method==='GET' && $id==='read-limit') {
        requireAdmin();
        $cfg = getConfig();
        $val = isset($cfg['imap_read_per_minute']) ? (int)$cfg['imap_read_per_minute'] : 100;
        if ($val < 1) $val = 100;
        jsonOut(['ok'=>true,'imap_read_per_minute'=>$val]);
    }
    if ($method==='POST' && $id==='read-limit') {
        requireAdmin();
        $b   = body();
        $val = (int)($b['imap_read_per_minute'] ?? 0);
        // Floor at 1 (zero would freeze the IMAP poll). Cap at a reasonable
        // upper bound so a typo doesn't tell the cron to fetch 1M emails on
        // one connection — but high enough not to constrain real workloads.
        if ($val < 1)    $val = 1;
        if ($val > 5000) $val = 5000;
        $cfg = getConfig();
        $cfg['imap_read_per_minute'] = $val;
        if (file_put_contents(CONFIG_FILE, json_encode($cfg, JSON_PRETTY_PRINT)) === false) {
            jsonOut(['ok'=>false,'message'=>'Cannot write config.json — check file permissions']);
        }
        jsonOut(['ok'=>true,'imap_read_per_minute'=>$val,'message'=>"Limit set to {$val} emails / cron run / IMAP account"]);
    }

    if ($method==='GET' && !$id) {
        if ($IS_ADMIN) {
            $stmt = db()->query("SELECT ia.*,u.username owner FROM imap_accounts ia LEFT JOIN users u ON u.id=ia.user_id ORDER BY ia.id DESC");
            $rows = $stmt->fetchAll();
        } else {
            // Non-admin: own IMAP accounts + admin-assigned ones (marked)
            $cu = db()->prepare('SELECT assigned_imap_ids FROM users WHERE id=?');
            $cu->execute([$UID]); $cuRow = $cu->fetch();
            $assignedIds = [];
            if ($cuRow && !empty($cuRow['assigned_imap_ids'])) {
                $d = json_decode($cuRow['assigned_imap_ids'], true);
                if (is_array($d)) $assignedIds = array_values(array_unique(array_filter(array_map('intval', $d))));
            }
            // Own IMAPs
            $ownStmt = db()->prepare("SELECT * FROM imap_accounts WHERE user_id=? ORDER BY id DESC");
            $ownStmt->execute([$UID]); $ownRows = $ownStmt->fetchAll();
            $ownIds = array_column($ownRows, 'id');
            foreach ($ownRows as &$r) { $r['is_own'] = true; $r['is_assigned'] = false; }
            // Assigned IMAPs not already own
            $assignedRows = [];
            $extraIds = array_diff($assignedIds, $ownIds);
            if (!empty($extraIds)) {
                $ph = implode(',', array_fill(0, count($extraIds), '?'));
                $s2 = db()->prepare("SELECT * FROM imap_accounts WHERE id IN ($ph) ORDER BY id DESC");
                $s2->execute(array_values($extraIds));
                foreach ($s2->fetchAll() as $r) { $r['is_own'] = false; $r['is_assigned'] = true; $assignedRows[] = $r; }
            }
            $rows = array_merge($ownRows, $assignedRows);
            $dedup = [];
            foreach ($rows as $rItem) {
                if (!isset($dedup[$rItem['id']])) $dedup[$rItem['id']] = $rItem;
            }
            $rows = array_values($dedup);
        }
        // Mask password
        foreach ($rows as &$r2) { $r2['password'] = ''; }
        jsonOut($rows ?? []);
    }
    if ($method==='GET' && $id && !$action) {
        $s = db()->prepare('SELECT * FROM imap_accounts WHERE id=?'.($IS_ADMIN?'':' AND user_id=?'));
        $p = [$id]; if (!$IS_ADMIN) $p[] = $UID;
        $s->execute($p); $row = $s->fetch();
        if (!$row) jsonOut(['error'=>'Not found'],404);
        $row['password'] = '';
        jsonOut($row);
    }
    // POST test connection
    if ($method==='POST' && $action==='test') {
        session_write_close();
        if (function_exists('imap_timeout')) { imap_timeout(IMAP_OPENTIMEOUT, 5); imap_timeout(IMAP_READTIMEOUT, 5); }
        ini_set('default_socket_timeout', 5);
        $b = body();
        // If testing saved account, load its password if none provided
        if ($id) {
            $s = db()->prepare('SELECT * FROM imap_accounts WHERE id=?'); $s->execute([$id]); $acc=$s->fetch();
            if (!$acc) jsonOut(['ok'=>false,'message'=>'Not found']);
            $host = $b['host'] ?? $acc['host'];
            $port = (int)($b['port'] ?? $acc['port']);
            $user = $b['username'] ?? $acc['username'];
            $pass = !empty($b['password']) ? $b['password'] : $acc['password'];
            $ssl  = (int)($b['ssl'] ?? $acc['ssl']);
        } else {
            $host = $b['host'] ?? ''; $port = (int)($b['port'] ?? 993);
            $user = $b['username'] ?? ''; $pass = $b['password'] ?? '';
            $ssl  = (int)($b['ssl'] ?? 1);
        }
        // Try php-imap extension first, fall back to raw socket IMAP
        if (function_exists('imap_open')) {
            $flags = $ssl ? '/imap/ssl/novalidate-cert' : '/imap/notls';
            $mbox = @imap_open('{'.$host.':'.$port.$flags.'}INBOX', $user, $pass, 0, 1);
            if ($mbox) { @imap_close($mbox); jsonOut(['ok'=>true,'message'=>'✅ IMAP connection successful! (php-imap)']); }
            else { $err = imap_last_error(); jsonOut(['ok'=>false,'message'=>'❌ '.$err]); }
        }
        // Fallback: raw socket IMAP login (works without php-imap extension)
        $result = imapTestSocket($host, $port, $user, $pass, (bool)$ssl, 5);
        jsonOut(['ok'=>$result['ok'], 'message'=>$result['message']]);
    }
    if ($method==='POST' && !$action) {
        // Non-admin can create their own IMAP accounts (no hard cap by default, but respects smtp_limit philosophy)
        if (!$IS_ADMIN) {
            // allowed — users can add their own IMAP
        }
        $b = body();
        if (empty($b['host'])||empty($b['username'])||empty($b['password'])||empty($b['name']))
            jsonOut(['ok'=>false,'message'=>'Name, host, username and password are all required']);
        try {
            db()->prepare("INSERT INTO imap_accounts (user_id,name,host,port,username,password,`ssl`) VALUES (?,?,?,?,?,?,?)")
                ->execute([$UID, $b['name'], $b['host'], (int)($b['port']??993), $b['username'], $b['password'], (int)($b['ssl']??1)]);
            jsonOut(['ok'=>true,'id'=>(int)db()->lastInsertId()]);
        } catch (Exception $e) {
            jsonOut(['ok'=>false,'message'=>'DB error: '.$e->getMessage()]);
        }
    }
    if ($method==='PUT' && $id) {
        $b = body();
        $s = db()->prepare('SELECT * FROM imap_accounts WHERE id=?'); $s->execute([$id]); $acc=$s->fetch();
        if (!$acc) jsonOut(['ok'=>false,'message'=>'Not found'],404);
        // Non-admin can only edit their own IMAP accounts
        if (!$IS_ADMIN && (int)$acc['user_id'] !== $UID) jsonOut(['ok'=>false,'message'=>'You can only edit your own IMAP accounts.'], 403);
        $pass = !empty($b['password']) ? $b['password'] : $acc['password'];
        try {
            db()->prepare("UPDATE imap_accounts SET name=?,host=?,port=?,username=?,password=?,`ssl`=?,status=? WHERE id=?")
                ->execute([$b['name']??$acc['name'], $b['host']??$acc['host'], (int)($b['port']??$acc['port']),
                           $b['username']??$acc['username'], $pass, (int)($b['ssl']??$acc['ssl']),
                           $b['status']??$acc['status'], $id]);
            jsonOut(['ok'=>true]);
        } catch (Exception $e) {
            jsonOut(['ok'=>false,'message'=>'DB error: '.$e->getMessage()]);
        }
    }
    if ($method==='POST' && $id && $action==='toggle-status') {
        // Admin-only: pause or resume an IMAP account
        if (!$IS_ADMIN) jsonOut(['ok'=>false,'message'=>'Only the Admin can pause/resume IMAP accounts.'], 403);
        $s = db()->prepare('SELECT id, status FROM imap_accounts WHERE id=?'); $s->execute([$id]); $acc=$s->fetch();
        if (!$acc) jsonOut(['ok'=>false,'message'=>'Not found'],404);
        $newStatus = ($acc['status'] === 'active') ? 'disabled' : 'active';
        db()->prepare("UPDATE imap_accounts SET status=? WHERE id=?")->execute([$newStatus, $id]);
        jsonOut(['ok'=>true,'status'=>$newStatus,'message'=>$newStatus==='active' ? '✅ IMAP account resumed.' : '⏸️ IMAP account paused.']);
    }
    if ($method==='POST' && $id && $action==='reset-uid') {
        if (!$IS_ADMIN) jsonOut(['ok'=>false,'message'=>'Only the Admin can reset IMAP UID trackers.'], 403);
        $s = db()->prepare('SELECT user_id FROM imap_accounts WHERE id=?'); $s->execute([$id]); $acc=$s->fetch();
        if (!$acc) jsonOut(['ok'=>false,'message'=>'Not found'],404);
        db()->prepare("UPDATE imap_accounts SET last_uid=0, last_uid_validity=0, emails_read=0 WHERE id=?")->execute([$id]);
        jsonOut(['ok'=>true,'message'=>'UID reset — next cron run will re-scan all messages']);
    }
    if ($method==='DELETE' && $id) {
        $s = db()->prepare('SELECT user_id FROM imap_accounts WHERE id=?'); $s->execute([$id]); $acc=$s->fetch();
        if (!$acc) jsonOut(['ok'=>false,'message'=>'Not found'],404);
        // Non-admin can only delete their own IMAP accounts
        if (!$IS_ADMIN && (int)$acc['user_id'] !== $UID) jsonOut(['ok'=>false,'message'=>'You can only delete your own IMAP accounts.'], 403);
        db()->prepare("DELETE FROM imap_accounts WHERE id=?")->execute([$id]);
        jsonOut(['ok'=>true]);
    }
    jsonOut(['error'=>'Not found'],404);
}


// ── IMAP SHARED PERMISSIONS (Admin only) ──────────────────────────────────
// Admin grants a user permission to process leads from another user's IMAP.
// By default each IMAP account is strictly private to its owner.
// Without an imap_shared_permissions entry, no cross-user IMAP access occurs.
// Endpoints (admin only):
//   GET    /imap-shared              — list all shared permissions
//   GET    /imap-shared?imap_id=N   — list grantees for one IMAP account
//   GET    /imap-shared/log          — IMAP read audit log (imap_read_log)
//   POST   /imap-shared              — grant {imap_account_id, grantee_user_id}
//   DELETE /imap-shared/{id}         — revoke by permission id
if ($res === 'imap-shared') {
    if (!$IS_ADMIN) jsonOut(['ok'=>false,'message'=>'Admin only'], 403);

    // GET /imap-shared/log — imap_read_log audit entries
    if ($method === 'GET' && $id === 'log') {
        $limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));
        try {
            $rows = db()->query(
                "SELECT l.*, ia.username imap_username, ia.host imap_host,
                        ou.username owner_username, pu.username processing_username
                   FROM imap_read_log l
                   JOIN imap_accounts ia ON ia.id = l.imap_account_id
                   LEFT JOIN users ou ON ou.id = l.owner_user_id
                   LEFT JOIN users pu ON pu.id = l.processing_user_id
                  ORDER BY l.started_at DESC LIMIT {$limit}"
            )->fetchAll();
            jsonOut(['ok'=>true,'log'=>$rows]);
        } catch (Exception $e) {
            jsonOut(['ok'=>false,'message'=>'imap_read_log not available: '.$e->getMessage()]);
        }
    }

    // GET /imap-shared or ?imap_id=N
    if ($method === 'GET') {
        $imapFilter = isset($_GET['imap_id']) ? (int)$_GET['imap_id'] : 0;
        try {
            if ($imapFilter > 0) {
                $stmt = db()->prepare(
                    "SELECT p.*, ia.username imap_username, ia.host imap_host,
                            ou.username owner_username, gu.username grantee_username, au.username granted_by_username
                       FROM imap_shared_permissions p
                       JOIN imap_accounts ia ON ia.id = p.imap_account_id
                       LEFT JOIN users ou ON ou.id = p.owner_user_id
                       LEFT JOIN users gu ON gu.id = p.grantee_user_id
                       LEFT JOIN users au ON au.id = p.granted_by
                      WHERE p.imap_account_id = ? ORDER BY p.id DESC"
                );
                $stmt->execute([$imapFilter]);
            } else {
                $stmt = db()->query(
                    "SELECT p.*, ia.username imap_username, ia.host imap_host,
                            ou.username owner_username, gu.username grantee_username, au.username granted_by_username
                       FROM imap_shared_permissions p
                       JOIN imap_accounts ia ON ia.id = p.imap_account_id
                       LEFT JOIN users ou ON ou.id = p.owner_user_id
                       LEFT JOIN users gu ON gu.id = p.grantee_user_id
                       LEFT JOIN users au ON au.id = p.granted_by
                      ORDER BY p.id DESC"
                );
            }
            jsonOut(['ok'=>true,'permissions'=>$stmt->fetchAll()]);
        } catch (Exception $e) {
            jsonOut(['ok'=>false,'message'=>'imap_shared_permissions not available: '.$e->getMessage()]);
        }
    }

    // POST /imap-shared — grant permission
    if ($method === 'POST') {
        $imapAccountId = (int)($b['imap_account_id'] ?? 0);
        $granteeUserId = (int)($b['grantee_user_id']  ?? 0);
        if (!$imapAccountId || !$granteeUserId)
            jsonOut(['ok'=>false,'message'=>'imap_account_id and grantee_user_id are required']);
        $iaQ = db()->prepare('SELECT id,user_id FROM imap_accounts WHERE id=?');
        $iaQ->execute([$imapAccountId]); $iaRow = $iaQ->fetch();
        if (!$iaRow) jsonOut(['ok'=>false,'message'=>'IMAP account not found'], 404);
        $ownerUserId = (int)$iaRow['user_id'];
        if ($ownerUserId === $granteeUserId)
            jsonOut(['ok'=>false,'message'=>'Cannot grant to owner — they already have full access']);
        $guQ = db()->prepare('SELECT id FROM users WHERE id=?');
        $guQ->execute([$granteeUserId]);
        if (!$guQ->fetch()) jsonOut(['ok'=>false,'message'=>'Grantee user not found'], 404);
        try {
            db()->prepare(
                "INSERT INTO imap_shared_permissions (imap_account_id, owner_user_id, grantee_user_id, granted_by)
                 VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE granted_by=VALUES(granted_by), granted_at=NOW()"
            )->execute([$imapAccountId, $ownerUserId, $granteeUserId, $UID]);
            jsonOut(['ok'=>true,'message'=>"Permission granted: user_id={$granteeUserId} can process leads from IMAP id={$imapAccountId} (owner={$ownerUserId})"]);
        } catch (Exception $e) {
            jsonOut(['ok'=>false,'message'=>'Failed to grant: '.$e->getMessage()]);
        }
    }

    // DELETE /imap-shared/{id} — revoke permission
    if ($method === 'DELETE' && $id) {
        $permId = (int)$id;
        $pQ = db()->prepare('SELECT * FROM imap_shared_permissions WHERE id=?');
        $pQ->execute([$permId]); $perm = $pQ->fetch();
        if (!$perm) jsonOut(['ok'=>false,'message'=>'Permission not found'], 404);
        db()->prepare('DELETE FROM imap_shared_permissions WHERE id=?')->execute([$permId]);
        jsonOut(['ok'=>true,'message'=>"Permission revoked: user_id={$perm['grantee_user_id']} no longer has access to IMAP id={$perm['imap_account_id']}"]);
    }
    jsonOut(['error'=>'Not found'], 404);
}

// ── ADMIN: SMTP/IMAP USER ASSIGNMENT ─────────────────────────────
// Admin-only endpoints to assign SMTP servers and IMAP accounts to users.
// GET  /user-smtp-assignment?user_id=N  — get current assignment for a user
// POST /user-smtp-assignment            — {user_id, smtp_ids:[]} set assignment
// GET  /user-imap-assignment?user_id=N  — get current assignment for a user
// POST /user-imap-assignment            — {user_id, imap_ids:[]} set assignment
if ($res==='user-smtp-assignment') {
    if (!$IS_ADMIN) jsonOut(['ok'=>false,'message'=>'Admin only'], 403);
    if ($method==='GET') {
        $userId = (int)($_GET['user_id'] ?? 0);
        if (!$userId) jsonOut(['ok'=>false,'message'=>'user_id required']);
        $s = db()->prepare('SELECT assigned_smtp_ids FROM users WHERE id=?');
        $s->execute([$userId]); $row = $s->fetch();
        if (!$row) jsonOut(['ok'=>false,'message'=>'User not found'], 404);
        $ids = [];
        if (!empty($row['assigned_smtp_ids'])) { $d=json_decode($row['assigned_smtp_ids'],true); if(is_array($d)) $ids=$d; }
        // Also return the actual SMTP server details for display
        $smtps = [];
        if (!empty($ids)) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $q = db()->prepare("SELECT id,name,host,from_email FROM smtp_providers WHERE id IN ($ph)");
            $q->execute($ids); $smtps = $q->fetchAll();
        }
        jsonOut(['ok'=>true,'assigned_smtp_ids'=>$ids,'smtps'=>$smtps]);
    }
    if ($method==='POST') {
        $b = body();
        $userId = (int)($b['user_id'] ?? 0);
        if (!$userId) jsonOut(['ok'=>false,'message'=>'user_id required']);
        $smtpIds = array_values(array_filter(array_map('intval', $b['smtp_ids'] ?? [])));
        db()->prepare('UPDATE users SET assigned_smtp_ids=? WHERE id=?')
            ->execute([count($smtpIds) ? json_encode($smtpIds) : null, $userId]);
        jsonOut(['ok'=>true,'message'=>'SMTP assignment saved for user #'.$userId,'assigned_smtp_ids'=>$smtpIds]);
    }
    jsonOut(['error'=>'Not found'], 404);
}
if ($res==='user-imap-assignment') {
    if (!$IS_ADMIN) jsonOut(['ok'=>false,'message'=>'Admin only'], 403);
    if ($method==='GET') {
        $userId = (int)($_GET['user_id'] ?? 0);
        if (!$userId) jsonOut(['ok'=>false,'message'=>'user_id required']);
        $s = db()->prepare('SELECT assigned_imap_ids FROM users WHERE id=?');
        $s->execute([$userId]); $row = $s->fetch();
        if (!$row) jsonOut(['ok'=>false,'message'=>'User not found'], 404);
        $ids = [];
        if (!empty($row['assigned_imap_ids'])) { $d=json_decode($row['assigned_imap_ids'],true); if(is_array($d)) $ids=$d; }
        $imaps = [];
        if (!empty($ids)) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $q = db()->prepare("SELECT id,name,host,username FROM imap_accounts WHERE id IN ($ph)");
            $q->execute($ids); $imaps = $q->fetchAll();
        }
        jsonOut(['ok'=>true,'assigned_imap_ids'=>$ids,'imaps'=>$imaps]);
    }
    if ($method==='POST') {
        $b = body();
        $userId = (int)($b['user_id'] ?? 0);
        if (!$userId) jsonOut(['ok'=>false,'message'=>'user_id required']);
        $imapIds = array_values(array_filter(array_map('intval', $b['imap_ids'] ?? [])));
        db()->prepare('UPDATE users SET assigned_imap_ids=? WHERE id=?')
            ->execute([count($imapIds) ? json_encode($imapIds) : null, $userId]);
        jsonOut(['ok'=>true,'message'=>'IMAP assignment saved for user #'.$userId,'assigned_imap_ids'=>$imapIds]);
    }
    jsonOut(['error'=>'Not found'], 404);
}

// ── AUTO-REPLY RULES ───────────────────────────────────────────────
if ($res==='autoreply') {
    // GET /autoreply/quota — lightweight endpoint the AR rule editor calls
    // when it opens, so the client can show "8 / 20 messages used" and
    // disable the "+ Add Reply" button at the cap. Admin always has unlimited.
    if ($method==='GET' && $id==='quota') {
        if ($IS_ADMIN) jsonOut(['ok'=>true,'limit'=>0,'used'=>0,'remaining'=>9999,'unlimited'=>true]);
        $u = db()->prepare('SELECT autoreply_limit FROM users WHERE id=?');
        $u->execute([$UID]);
        $limit = (int)($u->fetchColumn() ?: 0);
        $c = db()->prepare(
            'SELECT COUNT(*) FROM autoreply_steps s
               JOIN autoreply_rules r ON r.id = s.rule_id
              WHERE r.user_id = ?'
        );
        $c->execute([$UID]);
        $used = (int)$c->fetchColumn();
        jsonOut([
            'ok'        => true,
            'limit'     => $limit,
            'used'      => $used,
            'remaining' => $limit > 0 ? max(0, $limit - $used) : 0,
            'unlimited' => false,
        ]);
    }

    // Helper to attach steps+stats to a rule row
    function arEnrich(array &$row): void {
        $st = db()->prepare("SELECT * FROM autoreply_steps WHERE rule_id=? ORDER BY step_number ASC");
        $st->execute([$row['id']]); $row['steps'] = $st->fetchAll();
        $tc = db()->prepare("SELECT COUNT(*) FROM autoreply_threads WHERE rule_id=?");
        $tc->execute([$row['id']]); $row['total_threads'] = (int)$tc->fetchColumn();
        $ac = db()->prepare("SELECT COUNT(*) FROM autoreply_threads WHERE rule_id=? AND status='active'");
        $ac->execute([$row['id']]); $row['active_threads'] = (int)$ac->fetchColumn();
        $sl = db()->prepare("SELECT COUNT(*) FROM autoreply_logs WHERE rule_id=? AND status='sent'");
        $sl->execute([$row['id']]); $row['total_sent'] = (int)$sl->fetchColumn();

        // Enrich smart routing account names
        if (!empty($row['primary_imap_id'])) {
            $row['primary_imap_name'] = db()->query("SELECT name FROM imap_accounts WHERE id=".(int)$row['primary_imap_id'])->fetchColumn() ?: null;
        }
        if (!empty($row['secondary_imap_id'])) {
            $row['secondary_imap_name'] = db()->query("SELECT name FROM imap_accounts WHERE id=".(int)$row['secondary_imap_id'])->fetchColumn() ?: null;
        }
        if (!empty($row['primary_smtp_id'])) {
            $row['primary_smtp_name'] = db()->query("SELECT name FROM smtp_providers WHERE id=".(int)$row['primary_smtp_id'])->fetchColumn() ?: null;
        }
        if (!empty($row['secondary_smtp_id'])) {
            $row['secondary_smtp_name'] = db()->query("SELECT name FROM smtp_providers WHERE id=".(int)$row['secondary_smtp_id'])->fetchColumn() ?: null;
        }
        if (!empty($row['followup_rule_id'])) {
            $row['followup_rule_name'] = db()->query("SELECT name FROM followup_rules WHERE id=".(int)$row['followup_rule_id'])->fetchColumn() ?: null;
        }
    }
    if ($method==='GET' && !$id) {
        $stmt = $IS_ADMIN
            ? db()->query("SELECT r.*,u.username owner,ia.name imap_name FROM autoreply_rules r LEFT JOIN users u ON u.id=r.user_id LEFT JOIN imap_accounts ia ON ia.id=r.imap_id ORDER BY r.id DESC")
            : db()->prepare("SELECT r.*,ia.name imap_name FROM autoreply_rules r LEFT JOIN imap_accounts ia ON ia.id=r.imap_id WHERE r.user_id=? ORDER BY r.id DESC");
        if (!$IS_ADMIN) $stmt->execute([$UID]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) arEnrich($row);
        jsonOut($rows ?? []);
    }
    if ($method==='GET' && $id && !$action) {
        $s = db()->prepare('SELECT r.*,ia.name imap_name FROM autoreply_rules r LEFT JOIN imap_accounts ia ON ia.id=r.imap_id WHERE r.id=?'.($IS_ADMIN?'':' AND r.user_id=?'));
        $p = [$id]; if (!$IS_ADMIN) $p[] = $UID;
        $s->execute($p); $row = $s->fetch();
        if (!$row) jsonOut(['error'=>'Not found'],404);
        arEnrich($row); jsonOut($row);
    }
    if ($method==='GET' && $id && $action==='threads') {
        $pg = max(1,(int)($_GET['page']??1)); $lim=100; $off=($pg-1)*$lim;
        $where = $IS_ADMIN ? "t.rule_id=$id" : "t.rule_id=$id AND r.user_id=$UID";
        $total = db()->query("SELECT COUNT(*) FROM autoreply_threads t JOIN autoreply_rules r ON r.id=t.rule_id WHERE $where")->fetchColumn();
        $rows  = db()->query("SELECT t.* FROM autoreply_threads t JOIN autoreply_rules r ON r.id=t.rule_id WHERE $where ORDER BY t.id DESC LIMIT $lim OFFSET $off")->fetchAll();
        jsonOut(['rows'=>$rows,'total'=>(int)$total,'pages'=>(int)ceil($total/$lim)]);
    }
    if ($method==='GET' && $id && $action==='logs') {
        $rows = db()->query("SELECT l.* FROM autoreply_logs l JOIN autoreply_rules r ON r.id=l.rule_id WHERE l.rule_id=$id".($IS_ADMIN?'':' AND r.user_id='.$UID)." ORDER BY l.id DESC LIMIT 300")->fetchAll();
        jsonOut($rows);
    }
    if ($method==='DELETE' && $id && $action==='logs') {
        $s = db()->prepare('SELECT user_id FROM autoreply_rules WHERE id=?'); $s->execute([$id]); $row=$s->fetch();
        if (!$row||(!$IS_ADMIN&&(int)$row['user_id']!==$UID)) jsonOut(['ok'=>false,'message'=>'Not found'],404);
        db()->prepare("DELETE FROM autoreply_logs WHERE rule_id=?")->execute([$id]);
        jsonOut(['ok'=>true,'message'=>'Auto-reply logs cleared.']);
    }
    if ($method==='DELETE' && $id==='logs') {
        if ($IS_ADMIN) {
            db()->exec("DELETE FROM autoreply_logs");
        } else {
            db()->prepare("DELETE FROM autoreply_logs WHERE rule_id IN (SELECT id FROM autoreply_rules WHERE user_id=?)")->execute([$UID]);
        }
        jsonOut(['ok'=>true,'message'=>'Auto-reply logs cleared.']);
    }
    if ($method==='POST' && !$action) {
        $b = body();
        if (empty($b['name'])) jsonOut(['ok'=>false,'message'=>'Name required']);
        $targetUid = $UID;
        if ($IS_ADMIN && !empty($b['user_id'])) {
            $tu = (int)$b['user_id'];
            $chk = db()->prepare('SELECT id FROM users WHERE id=?');
            $chk->execute([$tu]);
            if ($chk->fetchColumn()) $targetUid = $tu;
        }
        $quotaTarget = $CUR;
        if ($targetUid !== $UID) {
            $tuRow = db()->prepare('SELECT * FROM users WHERE id=?');
            $tuRow->execute([$targetUid]);
            $tuRowFetched = $tuRow->fetch();
            if ($tuRowFetched) $quotaTarget = $tuRowFetched;
        }
        $proposedSteps = is_array($b['steps'] ?? null) ? count(array_slice($b['steps'], 0, 15)) : 0;
        $limCheck = checkUserLimit($quotaTarget, 'autoreply_count', ['adding' => $proposedSteps]);
        if (!$limCheck['ok']) jsonOut(['ok'=>false,'message'=>$limCheck['msg']]);

        $pImap = !empty($b['primary_imap_id']) ? (int)$b['primary_imap_id'] : (!empty($b['imap_id']) ? (int)$b['imap_id'] : null);
        $sImap = !empty($b['secondary_imap_id']) ? (int)$b['secondary_imap_id'] : (!empty($b['imap2_id']) ? (int)$b['imap2_id'] : null);
        $bImap = !empty($b['backup_imap_id']) ? (int)$b['backup_imap_id'] : null;
        $pSmtp = !empty($b['primary_smtp_id']) ? (int)$b['primary_smtp_id'] : null;
        $sSmtp = !empty($b['secondary_smtp_id']) ? (int)$b['secondary_smtp_id'] : null;
        $fuRuleId = !empty($b['followup_rule_id']) ? (int)$b['followup_rule_id'] : null;

        db()->prepare("INSERT INTO autoreply_rules 
            (user_id,name,imap_id,imap2_id,smtp_ids,from_emails,status,sequential_mode,step1_smtp_ids,
             enable_smart_routing,primary_imap_id,secondary_imap_id,backup_imap_id,primary_smtp_id,secondary_smtp_id,
             enable_reply_to_switch,enable_always_send_followup,enable_gmail_priority,followup_rule_id) 
            VALUES (?,?,?,?,?,?,?,?,?, ?,?,?,?,?,?, ?,?,?,?)")
            ->execute([
                $targetUid, $b['name'], $pImap, $sImap,
                !empty($b['smtp_ids'])?json_encode($b['smtp_ids']):null,
                !empty($b['from_emails'])?json_encode($b['from_emails']):null,
                $b['status']??'active',
                !empty($b['sequential_mode'])?1:0,
                !empty($b['step1_smtp_ids'])?json_encode($b['step1_smtp_ids']):null,
                !empty($b['enable_smart_routing'])?1:0,
                $pImap, $sImap, $bImap, $pSmtp, $sSmtp,
                isset($b['enable_reply_to_switch'])?(int)$b['enable_reply_to_switch']:1,
                isset($b['enable_always_send_followup'])?(int)$b['enable_always_send_followup']:1,
                isset($b['enable_gmail_priority'])?(int)$b['enable_gmail_priority']:1,
                $fuRuleId
            ]);
        $rid = db()->lastInsertId();
        if (!empty($b['steps'])) {
            $ins = db()->prepare("INSERT INTO autoreply_steps (rule_id,step_number,delay_minutes,delay_value,delay_unit,subject,html_body,text_body,image_ids,img_width,img_align,img_position) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            foreach (array_slice($b['steps'],0,15) as $i=>$st) {
                $dVal = max(0, (int)($st['delay_value'] ?? $st['delay_minutes'] ?? 1));
                $dUnit = in_array(strtolower($st['delay_unit'] ?? ''), ['minutes','hours','days'], true) ? strtolower($st['delay_unit']) : 'minutes';
                $dMins = delayToMinutes($dVal, $dUnit);
                $ins->execute([$rid,$i+1,$dMins,$dVal,$dUnit,$st['subject']??'',$st['html_body']??'',$st['text_body']??'',
                    safeImageIds($st['image_ids']??[]),$st['img_width']??'600',$st['img_align']??'center',$st['img_position']??'top']);
            }
        }
        jsonOut(['ok'=>true,'id'=>$rid]);
    }
    if ($method==='PUT' && $id && !$action) {
        $s = db()->prepare('SELECT * FROM autoreply_rules WHERE id=?'); $s->execute([$id]); $row=$s->fetch();
        if (!$row||(!$IS_ADMIN&&(int)$row['user_id']!==$UID)) jsonOut(['ok'=>false,'message'=>'Not found'],404);
        $b = body();

        $newOwner = (int)$row['user_id'];
        if ($IS_ADMIN && isset($b['user_id'])) {
            $cand = (int)$b['user_id'];
            if ($cand > 0 && $cand !== $newOwner) {
                $chk = db()->prepare('SELECT id FROM users WHERE id=?');
                $chk->execute([$cand]);
                if ($chk->fetchColumn()) $newOwner = $cand;
            }
        }

        $quotaTarget = $CUR;
        if ($newOwner !== $UID) {
            $tuRow = db()->prepare('SELECT * FROM users WHERE id=?');
            $tuRow->execute([$newOwner]);
            $tuRowFetched = $tuRow->fetch();
            if ($tuRowFetched) $quotaTarget = $tuRowFetched;
        }
        $proposedSteps = is_array($b['steps'] ?? null) ? count(array_slice($b['steps'], 0, 15)) : 0;
        $limCheck = checkUserLimit($quotaTarget, 'autoreply_count', [
            'adding'       => $proposedSteps,
            'exclude_rule' => (int)$id,
        ]);
        if (!$limCheck['ok']) jsonOut(['ok'=>false,'message'=>$limCheck['msg']]);

        $pImap = !empty($b['primary_imap_id']) ? (int)$b['primary_imap_id'] : (!empty($b['imap_id']) ? (int)$b['imap_id'] : $row['imap_id']);
        $sImap = !empty($b['secondary_imap_id']) ? (int)$b['secondary_imap_id'] : (!empty($b['imap2_id']) ? (int)$b['imap2_id'] : $row['imap2_id']);
        $bImap = isset($b['backup_imap_id']) ? (!empty($b['backup_imap_id'])?(int)$b['backup_imap_id']:null) : $row['backup_imap_id'];
        $pSmtp = isset($b['primary_smtp_id']) ? (!empty($b['primary_smtp_id'])?(int)$b['primary_smtp_id']:null) : $row['primary_smtp_id'];
        $sSmtp = isset($b['secondary_smtp_id']) ? (!empty($b['secondary_smtp_id'])?(int)$b['secondary_smtp_id']:null) : $row['secondary_smtp_id'];
        $fuRuleId = isset($b['followup_rule_id']) ? (!empty($b['followup_rule_id'])?(int)$b['followup_rule_id']:null) : $row['followup_rule_id'];

        db()->prepare("UPDATE autoreply_rules SET 
            user_id=?,name=?,imap_id=?,imap2_id=?,smtp_ids=?,from_emails=?,status=?,sequential_mode=?,step1_smtp_ids=?,
            enable_smart_routing=?,primary_imap_id=?,secondary_imap_id=?,backup_imap_id=?,primary_smtp_id=?,secondary_smtp_id=?,
            enable_reply_to_switch=?,enable_always_send_followup=?,enable_gmail_priority=?,followup_rule_id=? 
            WHERE id=?")
            ->execute([
                $newOwner,
                $b['name']??$row['name'],
                $pImap, $sImap,
                !empty($b['smtp_ids'])?json_encode($b['smtp_ids']):$row['smtp_ids'],
                !empty($b['from_emails'])?json_encode($b['from_emails']):$row['from_emails'],
                $b['status']??$row['status'],
                isset($b['sequential_mode'])?(int)$b['sequential_mode']:(int)($row['sequential_mode']??0),
                isset($b['step1_smtp_ids'])&&is_array($b['step1_smtp_ids'])&&count($b['step1_smtp_ids'])>0?json_encode($b['step1_smtp_ids']):$row['step1_smtp_ids'],
                isset($b['enable_smart_routing'])?(int)$b['enable_smart_routing']:(int)($row['enable_smart_routing']??0),
                $pImap, $sImap, $bImap, $pSmtp, $sSmtp,
                isset($b['enable_reply_to_switch'])?(int)$b['enable_reply_to_switch']:(int)($row['enable_reply_to_switch']??1),
                isset($b['enable_always_send_followup'])?(int)$b['enable_always_send_followup']:(int)($row['enable_always_send_followup']??1),
                isset($b['enable_gmail_priority'])?(int)$b['enable_gmail_priority']:(int)($row['enable_gmail_priority']??1),
                $fuRuleId,
                $id
            ]);
        db()->prepare("DELETE FROM autoreply_steps WHERE rule_id=?")->execute([$id]);
        if (!empty($b['steps'])) {
            $ins = db()->prepare("INSERT INTO autoreply_steps (rule_id,step_number,delay_minutes,delay_value,delay_unit,subject,html_body,text_body,image_ids,img_width,img_align,img_position) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            foreach (array_slice($b['steps'],0,15) as $i=>$st) {
                $dVal = max(0, (int)($st['delay_value'] ?? $st['delay_minutes'] ?? 1));
                $dUnit = in_array(strtolower($st['delay_unit'] ?? ''), ['minutes','hours','days'], true) ? strtolower($st['delay_unit']) : 'minutes';
                $dMins = delayToMinutes($dVal, $dUnit);
                $ins->execute([$id,$i+1,$dMins,$dVal,$dUnit,$st['subject']??'',$st['html_body']??'',$st['text_body']??'',
                    safeImageIds($st['image_ids']??[]),$st['img_width']??'600',$st['img_align']??'center',$st['img_position']??'top']);
            }
        }
        jsonOut(['ok'=>true]);
    }
    if ($method==='POST' && $id && $action==='test-send') {
        session_write_close();
        ini_set('default_socket_timeout', 10);
        $b = body();
        $toEmail = filter_var($b['to'] ?? '', FILTER_VALIDATE_EMAIL);
        if (!$toEmail) jsonOut(['ok'=>false,'message'=>'Valid "to" email required']);
        $s = db()->prepare('SELECT * FROM autoreply_rules WHERE id=?'); $s->execute([$id]); $rule=$s->fetch();
        if (!$rule) jsonOut(['ok'=>false,'message'=>'Rule not found'],404);
        $stepNum = max(1,(int)($b['step']??1));
        $st = db()->prepare('SELECT * FROM autoreply_steps WHERE rule_id=? AND step_number=?');
        $st->execute([$id,$stepNum]); $stepRow=$st->fetch();
        if (!$stepRow) jsonOut(['ok'=>false,'message'=>'Step '.$stepNum.' not found']);
        $smtpIds=[];
        if(!empty($rule['smtp_ids'])){$d=json_decode($rule['smtp_ids'],true);if(is_array($d))$smtpIds=$d;}
        if(empty($smtpIds)) jsonOut(['ok'=>false,'message'=>'No SMTP on this rule']);
        $ph=implode(',',array_fill(0,count($smtpIds),'?'));
        $ss=db()->prepare("SELECT * FROM smtp_providers WHERE id IN ($ph)");$ss->execute($smtpIds);
        $smtpCfg=$ss->fetch();
        if(!$smtpCfg) jsonOut(['ok'=>false,'message'=>'SMTP not found']);
        $imageIds=[];
        $imgRaw=$stepRow['image_ids']??'';
        if(!empty($imgRaw)){$decoded=json_decode($imgRaw,true);if(is_array($decoded))$imageIds=array_values(array_filter(array_map('intval',$decoded),fn($v)=>$v>0));}
        $html=personalize(spin($stepRow['html_body']??''),'Test User',$toEmail,$smtpCfg['from_name']??'',date('F j, Y g:i A'));
        $inlineImages=[];
        if(!empty($imageIds)) $html=resolveImages($html,$imageIds,$inlineImages,$stepRow['img_width']??'600',$stepRow['img_align']??'center',$stepRow['img_position']??'top');
        $subject=personalize(spin($stepRow['subject']??'Test Auto-Reply'),'Test User',$toEmail,$smtpCfg['from_name']??'',date('F j, Y g:i A'));
        $text=personalize(spin($stepRow['text_body']??strip_tags($stepRow['html_body']??'')),'Test User',$toEmail,$smtpCfg['from_name']??'',date('F j, Y g:i A'));
        $debug=['image_ids_raw'=>$imgRaw,'image_ids_parsed'=>$imageIds,'inline_images'=>count($inlineImages),'img_path'=>__DIR__.'/uploads/images/'];
        foreach($inlineImages as $im) $debug['files'][]=basename($im['path']).' readable='.((int)is_readable($im['path']));
        try {
            (new Mailer($smtpCfg))->send($toEmail,'Test User',$subject,$html,$text,$inlineImages,['is_auto_reply'=>true]);
            jsonOut(['ok'=>true,'message'=>'✅ Test sent to '.$toEmail,'debug'=>$debug]);
        } catch(Exception $e) {
            jsonOut(['ok'=>false,'message'=>'Send failed: '.$e->getMessage(),'debug'=>$debug]);
        }
    }
    if ($method==='POST' && $id && $action==='duplicate') {
        $s = db()->prepare('SELECT * FROM autoreply_rules WHERE id=?'); $s->execute([$id]); $row=$s->fetch();
        if (!$row||(!$IS_ADMIN&&(int)$row['user_id']!==$UID)) jsonOut(['ok'=>false,'message'=>'Not found'],404);
        $b = body();
        $targetUid = $UID;
        if ($IS_ADMIN && !empty($b['user_id'])) {
            $tu = (int)$b['user_id'];
            $chk = db()->prepare('SELECT id FROM users WHERE id=?'); $chk->execute([$tu]);
            if ($chk->fetchColumn()) $targetUid = $tu;
        }
        $quotaTarget = $CUR;
        if ($targetUid !== $UID) {
            $tuRow = db()->prepare('SELECT * FROM users WHERE id=?'); $tuRow->execute([$targetUid]);
            if ($tuRowFetched = $tuRow->fetch()) $quotaTarget = $tuRowFetched;
        }
        $stQ = db()->prepare("SELECT * FROM autoreply_steps WHERE rule_id=? ORDER BY step_number ASC");
        $stQ->execute([$id]); $steps = $stQ->fetchAll();
        $proposedSteps = count($steps);
        $limCheck = checkUserLimit($quotaTarget, 'autoreply_count', ['adding' => $proposedSteps]);
        if (!$limCheck['ok']) jsonOut(['ok'=>false,'message'=>$limCheck['msg']]);
        $newName = !empty($b['name']) ? $b['name'] : $row['name'] . ' (Copy)';
        db()->prepare("INSERT INTO autoreply_rules (user_id,name,imap_id,imap2_id,smtp_ids,from_emails,status,sequential_mode,step1_smtp_ids) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$targetUid,$newName,$row['imap_id'],$row['imap2_id'],$row['smtp_ids'],$row['from_emails'],'paused',$row['sequential_mode'],$row['step1_smtp_ids']]);
        $rid = db()->lastInsertId();
        if ($proposedSteps > 0) {
            $ins = db()->prepare("INSERT INTO autoreply_steps (rule_id,step_number,delay_minutes,delay_value,delay_unit,subject,html_body,text_body,image_ids,img_width,img_align,img_position) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            foreach ($steps as $st) $ins->execute([$rid,$st['step_number'],$st['delay_minutes'],$st['delay_value']??$st['delay_minutes'],$st['delay_unit']??'minutes',$st['subject'],$st['html_body'],$st['text_body'],$st['image_ids'],$st['img_width'],$st['img_align'],$st['img_position']]);
        }
        jsonOut(['ok'=>true,'id'=>$rid]);
    }
    if ($method==='POST' && ($action==='pause'||$action==='resume')) {
        $s = db()->prepare('SELECT user_id FROM autoreply_rules WHERE id=?'); $s->execute([$id]); $row=$s->fetch();
        if (!$row||(!$IS_ADMIN&&(int)$row['user_id']!==$UID)) jsonOut(['ok'=>false,'message'=>'Not found'],404);
        $st2 = $action==='pause' ? 'paused' : 'active';
        db()->prepare("UPDATE autoreply_rules SET status=? WHERE id=?")->execute([$st2, $id]);
        jsonOut(['ok'=>true]);
    }
    if ($method==='DELETE' && $id) {
        $s = db()->prepare('SELECT user_id FROM autoreply_rules WHERE id=?'); $s->execute([$id]); $row=$s->fetch();
        if (!$row||(!$IS_ADMIN&&(int)$row['user_id']!==$UID)) jsonOut(['ok'=>false,'message'=>'Not found'],404);
        db()->prepare('DELETE FROM autoreply_threads WHERE rule_id=?')->execute([$id]);
        db()->prepare('DELETE FROM autoreply_logs WHERE rule_id=?')->execute([$id]);
        db()->prepare('DELETE FROM autoreply_steps WHERE rule_id=?')->execute([$id]);
        db()->prepare('DELETE FROM autoreply_rules WHERE id=?')->execute([$id]);
        jsonOut(['ok'=>true]);
    }
    jsonOut(['error'=>'Not found'],404);
}

// ── FOLLOW-UP RULES ───────────────────────────────────────────────
if ($res==='followup') {
    // GET /followup/quota — same lightweight quota endpoint as autoreply
    // for the FU rule editor's client-side guard.
    if ($method==='GET' && $id==='quota') {
        if ($IS_ADMIN) jsonOut(['ok'=>true,'limit'=>0,'used'=>0,'remaining'=>9999,'unlimited'=>true]);
        $u = db()->prepare('SELECT followup_limit FROM users WHERE id=?');
        $u->execute([$UID]);
        $limit = (int)($u->fetchColumn() ?: 0);
        $c = db()->prepare(
            'SELECT COUNT(*) FROM followup_steps s
               JOIN followup_rules r ON r.id = s.rule_id
              WHERE r.user_id = ?'
        );
        $c->execute([$UID]);
        $used = (int)$c->fetchColumn();
        jsonOut([
            'ok'        => true,
            'limit'     => $limit,
            'used'      => $used,
            'remaining' => $limit > 0 ? max(0, $limit - $used) : 0,
            'unlimited' => false,
        ]);
    }

    function fuEnrich(array &$row): void {
        $st = db()->prepare("SELECT * FROM followup_steps WHERE rule_id=? ORDER BY step_number ASC");
        $st->execute([$row['id']]); $row['steps'] = $st->fetchAll();
        $ac = db()->prepare("SELECT COUNT(*) FROM followup_contacts WHERE rule_id=? AND status='active'");
        $ac->execute([$row['id']]); $row['active_contacts'] = (int)$ac->fetchColumn();
        $tc = db()->prepare("SELECT COUNT(*) FROM followup_contacts WHERE rule_id=?");
        $tc->execute([$row['id']]); $row['total_contacts'] = (int)$tc->fetchColumn();
        $sl = db()->prepare("SELECT COUNT(*) FROM followup_logs WHERE rule_id=? AND status='sent'");
        $sl->execute([$row['id']]); $row['total_sent'] = (int)$sl->fetchColumn();
    }
    if ($method==='GET' && !$id) {
        $stmt = $IS_ADMIN
            ? db()->query("SELECT r.*,u.username owner,u.id owner_id FROM followup_rules r LEFT JOIN users u ON u.id=r.user_id ORDER BY r.id DESC")
            : db()->prepare("SELECT * FROM followup_rules WHERE user_id=? ORDER BY id DESC");
        if (!$IS_ADMIN) $stmt->execute([$UID]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) fuEnrich($row);
        jsonOut($rows ?? []);
    }
    if ($method==='GET' && $id && !$action) {
        $s = db()->prepare('SELECT * FROM followup_rules WHERE id=?'.($IS_ADMIN?'':' AND user_id=?'));
        $p = [$id]; if (!$IS_ADMIN) $p[] = $UID;
        $s->execute($p); $row = $s->fetch();
        if (!$row) jsonOut(['error'=>'Not found'],404);
        fuEnrich($row); jsonOut($row);
    }
    if ($method==='GET' && $id && $action==='contacts') {
        $pg = max(1,(int)($_GET['page']??1)); $lim=100; $off=($pg-1)*$lim;
        $where = $IS_ADMIN ? "c.rule_id=$id" : "c.rule_id=$id AND r.user_id=$UID";
        $total = db()->query("SELECT COUNT(*) FROM followup_contacts c JOIN followup_rules r ON r.id=c.rule_id WHERE $where")->fetchColumn();
        $rows  = db()->query("SELECT c.* FROM followup_contacts c JOIN followup_rules r ON r.id=c.rule_id WHERE $where ORDER BY c.id DESC LIMIT $lim OFFSET $off")->fetchAll();
        jsonOut(['rows'=>$rows,'total'=>(int)$total,'pages'=>(int)ceil($total/$lim)]);
    }
    if ($method==='GET' && $id && $action==='logs') {
        $rows = db()->query("SELECT l.* FROM followup_logs l JOIN followup_rules r ON r.id=l.rule_id WHERE l.rule_id=$id".($IS_ADMIN?'':' AND r.user_id='.$UID)." ORDER BY l.id DESC LIMIT 300")->fetchAll();
        jsonOut($rows);
    }
    if ($method==='DELETE' && $id && $action==='logs') {
        $s = db()->prepare('SELECT user_id FROM followup_rules WHERE id=?'); $s->execute([$id]); $row=$s->fetch();
        if (!$row||(!$IS_ADMIN&&(int)$row['user_id']!==$UID)) jsonOut(['ok'=>false,'message'=>'Not found'],404);
        db()->prepare("DELETE FROM followup_logs WHERE rule_id=?")->execute([$id]);
        jsonOut(['ok'=>true,'message'=>'Follow-up logs cleared.']);
    }
    if ($method==='DELETE' && $id==='logs') {
        if ($IS_ADMIN) {
            db()->exec("DELETE FROM followup_logs");
        } else {
            db()->prepare("DELETE FROM followup_logs WHERE rule_id IN (SELECT id FROM followup_rules WHERE user_id=?)")->execute([$UID]);
        }
        jsonOut(['ok'=>true,'message'=>'Follow-up logs cleared.']);
    }
    if ($method==='POST' && !$action) {
        $b = body();
        if (empty($b['name'])) jsonOut(['ok'=>false,'message'=>'Name required']);
        // Owner resolution — same pattern as the AR POST. Admin may set
        // `user_id` in the body to assign the new rule to another account.
        $targetUid = $UID;
        if ($IS_ADMIN && !empty($b['user_id'])) {
            $tu = (int)$b['user_id'];
            $chk = db()->prepare('SELECT id FROM users WHERE id=?');
            $chk->execute([$tu]);
            if ($chk->fetchColumn()) $targetUid = $tu;
        }
        $quotaTarget = $CUR;
        if ($targetUid !== $UID) {
            $tuRow = db()->prepare('SELECT * FROM users WHERE id=?');
            $tuRow->execute([$targetUid]);
            $tuRowFetched = $tuRow->fetch();
            if ($tuRowFetched) $quotaTarget = $tuRowFetched;
        }
        // Per-user FOLLOW-UP MESSAGE limit. Counts step rows against the
        // EFFECTIVE owner so admin-assigned rules respect the target user's cap.
        $proposedSteps = is_array($b['steps'] ?? null) ? count(array_slice($b['steps'], 0, 15)) : 0;
        $limCheck = checkUserLimit($quotaTarget, 'followup_count', ['adding' => $proposedSteps]);
        if (!$limCheck['ok']) jsonOut(['ok'=>false,'message'=>$limCheck['msg']]);

        $valErr = validateRuleSmtpImap(
            $targetUid,
            !empty($b['imap_id']) ? (int)$b['imap_id'] : null,
            null,
            $b['smtp_ids'] ?? null,
            null
        );
        if ($valErr) jsonOut(['ok'=>false,'message'=>$valErr]);

        db()->prepare("INSERT INTO followup_rules (user_id,name,imap_id,smtp_ids,from_emails,status,trigger_on_open) VALUES (?,?,?,?,?,?,?)")
            ->execute([$targetUid,$b['name'],!empty($b['imap_id'])?(int)$b['imap_id']:null,!empty($b['smtp_ids'])?json_encode($b['smtp_ids']):null,
                       !empty($b['from_emails'])?json_encode($b['from_emails']):null,$b['status']??'active',isset($b['trigger_on_open'])?(int)$b['trigger_on_open']:1]);
        $rid = db()->lastInsertId();
        if (!empty($b['steps'])) {
            $ins = db()->prepare("INSERT INTO followup_steps (rule_id,step_number,delay_minutes,delay_value,delay_unit,subject,html_body,text_body,image_ids,img_width,img_align,img_position) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            foreach (array_slice($b['steps'],0,15) as $i=>$st) {
                $dVal = max(0, (int)($st['delay_value'] ?? $st['delay_minutes'] ?? 30));
                $dUnit = in_array(strtolower($st['delay_unit'] ?? ''), ['minutes','hours','days'], true) ? strtolower($st['delay_unit']) : 'minutes';
                $dMins = delayToMinutes($dVal, $dUnit);
                $ins->execute([$rid, $i+1, $dMins, $dVal, $dUnit, $st['subject']??'', $st['html_body']??'', $st['text_body']??'',
                    safeImageIds($st['image_ids']??[]), $st['img_width']??'600', $st['img_align']??'center', $st['img_position']??'top']);
            }
        }
        jsonOut(['ok'=>true,'id'=>$rid]);
    }
    if ($method==='PUT' && $id && !$action) {
        $s = db()->prepare('SELECT * FROM followup_rules WHERE id=?'); $s->execute([$id]); $row=$s->fetch();
        if (!$row||(!$IS_ADMIN&&(int)$row['user_id']!==$UID)) jsonOut(['ok'=>false,'message'=>'Not found'],404);
        $b = body();

        // Owner transfer (admin only). Same semantics as the AR PUT — when
        // user_id changes, the rule disappears from the previous owner's
        // list (every list query gates by r.user_id = $UID for non-admin)
        // and appears under the new owner.
        $newOwner = (int)$row['user_id'];
        if ($IS_ADMIN && isset($b['user_id'])) {
            $cand = (int)$b['user_id'];
            if ($cand > 0 && $cand !== $newOwner) {
                $chk = db()->prepare('SELECT id FROM users WHERE id=?');
                $chk->execute([$cand]);
                if ($chk->fetchColumn()) $newOwner = $cand;
            }
        }

        // Re-check the message cap against the EFFECTIVE owner. Exclude this
        // rule's own existing steps so they don't double-count.
        $quotaTarget = $CUR;
        if ($newOwner !== $UID) {
            $tuRow = db()->prepare('SELECT * FROM users WHERE id=?');
            $tuRow->execute([$newOwner]);
            $tuRowFetched = $tuRow->fetch();
            if ($tuRowFetched) $quotaTarget = $tuRowFetched;
        }
        $proposedSteps = is_array($b['steps'] ?? null) ? count(array_slice($b['steps'], 0, 15)) : 0;
        $limCheck = checkUserLimit($quotaTarget, 'followup_count', [
            'adding'       => $proposedSteps,
            'exclude_rule' => (int)$id,
        ]);
        if (!$limCheck['ok']) jsonOut(['ok'=>false,'message'=>$limCheck['msg']]);

        $valErr = validateRuleSmtpImap(
            $newOwner,
            $IS_ADMIN ? (!empty($b['imap_id']) ? (int)$b['imap_id'] : null) : $row['imap_id'],
            null,
            $IS_ADMIN ? ($b['smtp_ids'] ?? null) : $row['smtp_ids'],
            null
        );
        if ($valErr) jsonOut(['ok'=>false,'message'=>$valErr]);

        db()->prepare("UPDATE followup_rules SET user_id=?,name=?,imap_id=?,smtp_ids=?,from_emails=?,status=?,trigger_on_open=? WHERE id=?")
            ->execute([$newOwner,
                       $b['name']??$row['name'],
                       $IS_ADMIN ? (!empty($b['imap_id'])?(int)$b['imap_id']:null) : $row['imap_id'],
                       $IS_ADMIN ? (!empty($b['smtp_ids'])?json_encode($b['smtp_ids']):null) : $row['smtp_ids'],
                       !empty($b['from_emails'])?json_encode($b['from_emails']):null,$b['status']??$row['status'],
                       isset($b['trigger_on_open'])?(int)$b['trigger_on_open']:($row['trigger_on_open']??1),$id]);
        db()->prepare("DELETE FROM followup_steps WHERE rule_id=?")->execute([$id]);
        if (!empty($b['steps'])) {
            $ins = db()->prepare("INSERT INTO followup_steps (rule_id,step_number,delay_minutes,delay_value,delay_unit,subject,html_body,text_body,image_ids,img_width,img_align,img_position) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            foreach (array_slice($b['steps'],0,15) as $i=>$st) {
                $dVal = max(0, (int)($st['delay_value'] ?? $st['delay_minutes'] ?? 30));
                $dUnit = in_array(strtolower($st['delay_unit'] ?? ''), ['minutes','hours','days'], true) ? strtolower($st['delay_unit']) : 'minutes';
                $dMins = delayToMinutes($dVal, $dUnit);
                $ins->execute([$id, $i+1, $dMins, $dVal, $dUnit, $st['subject']??'', $st['html_body']??'', $st['text_body']??'',
                    safeImageIds($st['image_ids']??[]), $st['img_width']??'600', $st['img_align']??'center', $st['img_position']??'top']);
            }
        }
        jsonOut(['ok'=>true]);
    }
    if ($method==='POST' && $id && $action==='duplicate') {
        $s = db()->prepare('SELECT * FROM followup_rules WHERE id=?'); $s->execute([$id]); $row=$s->fetch();
        if (!$row||(!$IS_ADMIN&&(int)$row['user_id']!==$UID)) jsonOut(['ok'=>false,'message'=>'Not found'],404);
        $b = body();
        $targetUid = $UID;
        if ($IS_ADMIN && !empty($b['user_id'])) {
            $tu = (int)$b['user_id'];
            $chk = db()->prepare('SELECT id FROM users WHERE id=?'); $chk->execute([$tu]);
            if ($chk->fetchColumn()) $targetUid = $tu;
        }
        $quotaTarget = $CUR;
        if ($targetUid !== $UID) {
            $tuRow = db()->prepare('SELECT * FROM users WHERE id=?'); $tuRow->execute([$targetUid]);
            if ($tuRowFetched = $tuRow->fetch()) $quotaTarget = $tuRowFetched;
        }
        $stQ = db()->prepare("SELECT * FROM followup_steps WHERE rule_id=? ORDER BY step_number ASC");
        $stQ->execute([$id]); $steps = $stQ->fetchAll();
        $proposedSteps = count($steps);
        $limCheck = checkUserLimit($quotaTarget, 'followup_count', ['adding' => $proposedSteps]);
        if (!$limCheck['ok']) jsonOut(['ok'=>false,'message'=>$limCheck['msg']]);
        $newName = !empty($b['name']) ? $b['name'] : $row['name'] . ' (Copy)';
        db()->prepare("INSERT INTO followup_rules (user_id,name,imap_id,smtp_ids,from_emails,status,trigger_on_open) VALUES (?,?,?,?,?,?,?)")
            ->execute([$targetUid,$newName,$row['imap_id'],$row['smtp_ids'],$row['from_emails'],'paused',$row['trigger_on_open']??1]);
        $rid = db()->lastInsertId();
        if ($proposedSteps > 0) {
            $ins = db()->prepare("INSERT INTO followup_steps (rule_id,step_number,delay_minutes,delay_value,delay_unit,subject,html_body,text_body,image_ids,img_width,img_align,img_position) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            foreach ($steps as $st) $ins->execute([$rid,$st['step_number'],$st['delay_minutes'],$st['delay_value']??$st['delay_minutes'],$st['delay_unit']??'minutes',$st['subject'],$st['html_body'],$st['text_body'],$st['image_ids'],$st['img_width'],$st['img_align'],$st['img_position']]);
        }
        jsonOut(['ok'=>true,'id'=>$rid]);
    }
    if ($method==='POST' && ($action==='pause'||$action==='resume')) {
        $st2 = $action==='pause'?'paused':'active';
        db()->prepare("UPDATE followup_rules SET status='$st2' WHERE id=?".($IS_ADMIN?'':' AND user_id=?'))->execute($IS_ADMIN?[$id]:[$id,$UID]);
        jsonOut(['ok'=>true]);
    }
    // POST enroll contacts
    if ($method==='POST' && $action==='enroll') {
        $s = db()->prepare('SELECT * FROM followup_rules WHERE id=?'); $s->execute([$id]); $rule=$s->fetch();
        if (!$rule||(!$IS_ADMIN&&(int)$rule['user_id']!==$UID)) jsonOut(['ok'=>false,'message'=>'Not found'],404);
        $fs = db()->prepare("SELECT delay_value, delay_unit, delay_minutes FROM followup_steps WHERE rule_id=? ORDER BY step_number ASC LIMIT 1");
        $fs->execute([$id]); $firstStep = $fs->fetch();
        $delayMinutes = $firstStep ? delayToMinutes((int)($firstStep['delay_value'] ?? $firstStep['delay_minutes'] ?? 30), $firstStep['delay_unit'] ?? 'minutes') : 30;
        
        // Auto-schedule step 1 immediately upon enrollment (sends after delay whether read or not)
        $nextSend = date('Y-m-d H:i:s', strtotime("+{$delayMinutes} minutes"));
        
        if (!empty($_FILES['file'])) {
            $h=fopen($_FILES['file']['tmp_name'],'r');$hdr=null;$ei=0;$ni=-1;$cnt=0;
            $ins=db()->prepare("INSERT INTO followup_contacts (rule_id,email,name,current_step,next_send_at,tracking_token,status) VALUES (?,?,?,1,?,?,'active') ON DUPLICATE KEY UPDATE status='active'");
            while(($rw=fgetcsv($h))!==false){
                if(!$hdr){$hdr=array_map('strtolower',array_map('trim',$rw));foreach($hdr as $ki=>$hv){if(strpos($hv,'email')!==false)$ei=$ki;if(strpos($hv,'name')!==false)$ni=$ki;}continue;}
                $em=strtolower(trim($rw[$ei]??''));if(!filter_var($em,FILTER_VALIDATE_EMAIL))continue;
                $nm=($ni>=0)?trim($rw[$ni]??''):'';
                $tTok = generateTrackingToken();
                $ins->execute([$id,$em,$nm,$nextSend,$tTok]);$cnt++;
            }
            fclose($h); jsonOut(['ok'=>true,'enrolled'=>$cnt]);
        }
        $b = body();
        if (!empty($b['list_id'])) {
            $em2=db()->prepare("SELECT email,name FROM emails WHERE list_id=? AND status='active'");
            $em2->execute([$b['list_id']]);$list=$em2->fetchAll();
            $ins=db()->prepare("INSERT INTO followup_contacts (rule_id,email,name,current_step,next_send_at,tracking_token,status) VALUES (?,?,?,1,?,?,'active') ON DUPLICATE KEY UPDATE status='active'");
            $cnt=0; 
            foreach($list as $e){
                $tTok = generateTrackingToken();
                $ins->execute([$id,$e['email'],$e['name']??'',$nextSend,$tTok]);$cnt++;
            }
            jsonOut(['ok'=>true,'enrolled'=>$cnt]);
        }
        jsonOut(['ok'=>false,'message'=>'No contacts provided']);
    }
    if ($method==='DELETE' && $id && $action==='contact') {
        $cid=$parts[3]??null;
        if ($cid) db()->prepare("DELETE FROM followup_contacts WHERE id=? AND rule_id=?")->execute([$cid,$id]);
        jsonOut(['ok'=>true]);
    }
    if ($method==='DELETE' && $id && !$action) {
        $s = db()->prepare('SELECT user_id FROM followup_rules WHERE id=?'); $s->execute([$id]); $row=$s->fetch();
        if (!$row||(!$IS_ADMIN&&(int)$row['user_id']!==$UID)) jsonOut(['ok'=>false,'message'=>'Not found'],404);
        db()->prepare("DELETE FROM followup_rules WHERE id=?")->execute([$id]);
        jsonOut(['ok'=>true]);
    }
    jsonOut(['error'=>'Not found'],404);
}


// ── REPORTS ───────────────────────────────────────────────────────
if ($res==='reports') {

    // GET reports/reply-pending — auto-reply threads still active (not completed)
    if ($method==='GET' && $id==='reply-pending') {
        $page  = max(1,(int)($_GET['page']??1));
        $limit = 100; $offset = ($page-1)*$limit;
        $ruleFilter = isset($_GET['rule']) ? (int)$_GET['rule'] : 0;
        $search     = $_GET['q'] ?? '';

        $where = $IS_ADMIN ? "t.status='active'" : "t.status='active' AND r.user_id=$UID";
        if ($ruleFilter) $where .= " AND t.rule_id=$ruleFilter";
        if ($search)     $where .= " AND t.from_email LIKE ".db()->quote('%'.$search.'%');

        $total = db()->query("SELECT COUNT(*) FROM autoreply_threads t JOIN autoreply_rules r ON r.id=t.rule_id WHERE $where")->fetchColumn();
        $rows  = db()->query("SELECT t.*,r.name rule_name,r.id rule_id_val FROM autoreply_threads t JOIN autoreply_rules r ON r.id=t.rule_id WHERE $where ORDER BY t.next_send_at ASC LIMIT $limit OFFSET $offset")->fetchAll();
        // Attach next step subject + body preview
        foreach ($rows as &$row) {
            $ns = db()->prepare("SELECT subject, text_body, html_body FROM autoreply_steps WHERE rule_id=? AND step_number=?");
            $ns->execute([$row['rule_id_val'], $row['current_step']]);
            $stepData = $ns->fetch() ?: [];
            $row['next_subject'] = $stepData['subject'] ?: '—';
            $row['next_text']    = $stepData['text_body'] ?: '';
            $row['next_html']    = $stepData['html_body'] ?: '';
        }
        // Rule list for filter dropdown
        $rulesQ = db()->query("SELECT id,name FROM autoreply_rules".($IS_ADMIN ? '' : ' WHERE user_id='.$UID)." ORDER BY name")->fetchAll();
        jsonOut(['rows'=>$rows,'total'=>(int)$total,'page'=>$page,'pages'=>(int)ceil($total/$limit),'rules'=>$rulesQ]);
    }

    // GET reports/followup-pending — follow-up contacts still active
    if ($method==='GET' && $id==='followup-pending') {
        $page  = max(1,(int)($_GET['page']??1));
        $limit = 100; $offset = ($page-1)*$limit;
        $ruleFilter = isset($_GET['rule']) ? (int)$_GET['rule'] : 0;
        $search     = $_GET['q'] ?? '';

        $where = $IS_ADMIN ? "c.status='active'" : "c.status='active' AND r.user_id=$UID";
        if ($ruleFilter) $where .= " AND c.rule_id=$ruleFilter";
        if ($search)     $where .= " AND c.email LIKE ".db()->quote('%'.$search.'%');

        $total = db()->query("SELECT COUNT(*) FROM followup_contacts c JOIN followup_rules r ON r.id=c.rule_id WHERE $where")->fetchColumn();
        $rows  = db()->query("SELECT c.*,r.name rule_name FROM followup_contacts c JOIN followup_rules r ON r.id=c.rule_id WHERE $where ORDER BY c.next_send_at ASC LIMIT $limit OFFSET $offset")->fetchAll();
        foreach ($rows as &$row) {
            $ns = db()->prepare("SELECT subject, text_body, html_body FROM followup_steps WHERE rule_id=? AND step_number=?");
            $ns->execute([$row['rule_id'], $row['current_step']]);
            $stepData = $ns->fetch() ?: [];
            $row['next_subject'] = $stepData['subject'] ?: '—';
            $row['next_text']    = $stepData['text_body'] ?: '';
            $row['next_html']    = $stepData['html_body'] ?: '';
        }
        $rulesQ = db()->query("SELECT id,name FROM followup_rules".($IS_ADMIN?'':' WHERE user_id='.$UID)." ORDER BY name")->fetchAll();
        jsonOut(['rows'=>$rows,'total'=>(int)$total,'page'=>$page,'pages'=>(int)ceil($total/$limit),'rules'=>$rulesQ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // GET reports/step-summary — Unified per-step counts for the Live
    // Step Report Dashboard (Auto-Reply + Follow-Up, steps 1..MAX).
    //
    // For each step N we return:
    //   ar_sent      — autoreply_logs rows at step N with status='sent'
    //   ar_pending   — active autoreply_threads currently waiting at step N
    //   ar_completed — autoreply_threads that have advanced past step N
    //                  (current_step > N) OR fully completed sequences whose
    //                  total step count reached/passed N
    //   fu_*         — same shape, against followup_* tables
    //   total_*      — AR + FU sums (what the unified bars display)
    //
    // Non-admin users see only rows tied to their own rules.
    // ─────────────────────────────────────────────────────────────────
    if ($method==='GET' && $id==='step-summary') {
        $maxSteps = max(1, min(50, (int)($_GET['max']??15)));

        // Per-step sent counts (event-based; one row per dispatch).
        $arSentByStep = array_fill(1, $maxSteps, 0);
        $fuSentByStep = array_fill(1, $maxSteps, 0);
        // Per-step pending = active threads/contacts currently at step N.
        $arPendingByStep = array_fill(1, $maxSteps, 0);
        $fuPendingByStep = array_fill(1, $maxSteps, 0);
        // Per-step completed = entities whose current_step > N (already
        // passed) plus fully-completed sequences whose final step ≥ N.
        $arCompletedByStep = array_fill(1, $maxSteps, 0);
        $fuCompletedByStep = array_fill(1, $maxSteps, 0);

        try {
            // ── AR: sent counts grouped by step_number ──────────────
            if ($IS_ADMIN) {
                $q = db()->prepare("SELECT step_number s, COUNT(*) c FROM autoreply_logs WHERE status='sent' AND step_number BETWEEN 1 AND ? GROUP BY step_number");
                $q->execute([$maxSteps]);
            } else {
                $q = db()->prepare("SELECT l.step_number s, COUNT(*) c FROM autoreply_logs l INNER JOIN autoreply_rules r ON r.id=l.rule_id WHERE l.status='sent' AND r.user_id=? AND l.step_number BETWEEN 1 AND ? GROUP BY l.step_number");
                $q->execute([$UID, $maxSteps]);
            }
            foreach ($q->fetchAll() as $rw) {
                $n = (int)$rw['s'];
                if ($n>=1 && $n<=$maxSteps) $arSentByStep[$n] = (int)$rw['c'];
            }

            // ── FU: sent counts grouped by step_number ──────────────
            if ($IS_ADMIN) {
                $q = db()->prepare("SELECT step_number s, COUNT(*) c FROM followup_logs WHERE status='sent' AND step_number BETWEEN 1 AND ? GROUP BY step_number");
                $q->execute([$maxSteps]);
            } else {
                $q = db()->prepare("SELECT l.step_number s, COUNT(*) c FROM followup_logs l INNER JOIN followup_rules r ON r.id=l.rule_id WHERE l.status='sent' AND r.user_id=? AND l.step_number BETWEEN 1 AND ? GROUP BY l.step_number");
                $q->execute([$UID, $maxSteps]);
            }
            foreach ($q->fetchAll() as $rw) {
                $n = (int)$rw['s'];
                if ($n>=1 && $n<=$maxSteps) $fuSentByStep[$n] = (int)$rw['c'];
            }

            // ── AR pending: active threads at current_step = N ──────
            if ($IS_ADMIN) {
                $q = db()->prepare("SELECT current_step s, COUNT(*) c FROM autoreply_threads WHERE status='active' AND current_step BETWEEN 1 AND ? GROUP BY current_step");
                $q->execute([$maxSteps]);
            } else {
                $q = db()->prepare("SELECT t.current_step s, COUNT(*) c FROM autoreply_threads t INNER JOIN autoreply_rules r ON r.id=t.rule_id WHERE t.status='active' AND r.user_id=? AND t.current_step BETWEEN 1 AND ? GROUP BY t.current_step");
                $q->execute([$UID, $maxSteps]);
            }
            foreach ($q->fetchAll() as $rw) {
                $n = (int)$rw['s'];
                if ($n>=1 && $n<=$maxSteps) $arPendingByStep[$n] = (int)$rw['c'];
            }

            // ── FU pending: active contacts at current_step = N ─────
            if ($IS_ADMIN) {
                $q = db()->prepare("SELECT current_step s, COUNT(*) c FROM followup_contacts WHERE status='active' AND current_step BETWEEN 1 AND ? GROUP BY current_step");
                $q->execute([$maxSteps]);
            } else {
                $q = db()->prepare("SELECT c.current_step s, COUNT(*) c FROM followup_contacts c INNER JOIN followup_rules r ON r.id=c.rule_id WHERE c.status='active' AND r.user_id=? AND c.current_step BETWEEN 1 AND ? GROUP BY c.current_step");
                $q->execute([$UID, $maxSteps]);
            }
            foreach ($q->fetchAll() as $rw) {
                $n = (int)$rw['s'];
                if ($n>=1 && $n<=$maxSteps) $fuPendingByStep[$n] = (int)$rw['c'];
            }

            // ── AR/FU "completed at step N" derivation ──────────────
            // A thread/contact has "completed step N" if it has moved
            // past it. For active rows that means current_step > N.
            // For status='completed' rows, the engine retains the final
            // step they reached in current_step — treat those as having
            // completed every step 1..current_step.
            // We compute the cumulative distribution by walking from the
            // highest step downward.
            $arCompletedHist = array_fill(1, $maxSteps, 0);
            $fuCompletedHist = array_fill(1, $maxSteps, 0);

            // AR: include completed sequences inclusive of their final step;
            // active sequences contribute to step N only if current_step > N.
            if ($IS_ADMIN) {
                $q = db()->query("SELECT current_step s, status st, COUNT(*) c FROM autoreply_threads WHERE current_step BETWEEN 1 AND $maxSteps GROUP BY current_step, status");
            } else {
                $q = db()->prepare("SELECT t.current_step s, t.status st, COUNT(*) c FROM autoreply_threads t INNER JOIN autoreply_rules r ON r.id=t.rule_id WHERE r.user_id=? AND t.current_step BETWEEN 1 AND ? GROUP BY t.current_step, t.status");
                $q->execute([$UID, $maxSteps]);
            }
            foreach ($q->fetchAll() as $rw) {
                $cs = (int)$rw['s']; $st = $rw['st']; $cnt = (int)$rw['c'];
                if ($cs < 1) continue;
                // active: completed step 1..cs-1
                // completed: completed step 1..cs (inclusive)
                $upper = ($st === 'completed') ? min($cs, $maxSteps) : min($cs-1, $maxSteps);
                for ($n=1; $n<=$upper; $n++) $arCompletedByStep[$n] += $cnt;
            }

            // FU: same logic against followup_contacts (status active|completed|stopped).
            if ($IS_ADMIN) {
                $q = db()->query("SELECT current_step s, status st, COUNT(*) c FROM followup_contacts WHERE current_step BETWEEN 1 AND $maxSteps GROUP BY current_step, status");
            } else {
                $q = db()->prepare("SELECT c.current_step s, c.status st, COUNT(*) cnt FROM followup_contacts c INNER JOIN followup_rules r ON r.id=c.rule_id WHERE r.user_id=? AND c.current_step BETWEEN 1 AND ? GROUP BY c.current_step, c.status");
                $q->execute([$UID, $maxSteps]);
            }
            foreach ($q->fetchAll() as $rw) {
                $cs = (int)$rw['s']; $st = $rw['st']; $cnt = (int)($rw['c'] ?? $rw['cnt'] ?? 0);
                if ($cs < 1) continue;
                $upper = ($st === 'completed') ? min($cs, $maxSteps) : min($cs-1, $maxSteps);
                for ($n=1; $n<=$upper; $n++) $fuCompletedByStep[$n] += $cnt;
            }
        } catch (Throwable $e) {
            // Soft-fail — return zero-filled arrays so UI still renders.
        }

        // ── Assemble per-step rows + summary ─────────────────────────
        $steps = [];
        $sumSent = 0; $sumPending = 0; $sumCompleted = 0;
        for ($n = 1; $n <= $maxSteps; $n++) {
            $arS = (int)$arSentByStep[$n];
            $fuS = (int)$fuSentByStep[$n];
            $arP = (int)$arPendingByStep[$n];
            $fuP = (int)$fuPendingByStep[$n];
            $arC = (int)$arCompletedByStep[$n];
            $fuC = (int)$fuCompletedByStep[$n];
            $tS = $arS + $fuS;
            $tP = $arP + $fuP;
            $tC = $arC + $fuC;
            $denom = $tS + $tP;
            $pct = $denom > 0 ? round($tC / max($denom, 1) * 100, 1) : 0;
            $sumSent += $tS; $sumPending += $tP; $sumCompleted += $tC;
            $steps[] = [
                'step'           => $n,
                'ar_sent'        => $arS,
                'ar_pending'     => $arP,
                'ar_completed'   => $arC,
                'fu_sent'        => $fuS,
                'fu_pending'     => $fuP,
                'fu_completed'   => $fuC,
                'total_sent'     => $tS,
                'total_pending'  => $tP,
                'total_completed'=> $tC,
                'completion_pct' => $pct,
            ];
        }

        jsonOut([
            'ok'          => true,
            'max_steps'   => $maxSteps,
            'summary'     => [
                'total_sent'      => $sumSent,
                'total_pending'   => $sumPending,
                'total_completed' => $sumCompleted,
            ],
            'steps'       => $steps,
            'generated_at'=> date('c'),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // GET reports/ar-step  — Auto-Reply step-by-step report
    // GET reports/fu-step  — Follow-Up step-by-step report
    //
    // Common query string (both endpoints):
    //   page          int    (1-based)
    //   q             str    free-text search on lead email/name/rule
    //   rule_id       int    filter by Campaign/Rule
    //   status        str    completed | active | pending | failed | sent
    //   smtp          str    filter on smtp_used (last log row)
    //   step          int    filter on current_step
    //   date_from     YYYY-MM-DD   filter on last_sent_at (>=)
    //   date_to       YYYY-MM-DD   filter on last_sent_at (<=)
    //   sort          col,asc|desc
    //   export        csv | xls    (when present, streams the result instead of JSON)
    // ─────────────────────────────────────────────────────────────────
    $isArReport = ($method==='GET' && $id==='ar-step');
    $isFuReport = ($method==='GET' && $id==='fu-step');
    if ($isArReport || $isFuReport) {
        $pdo    = db();
        $page   = max(1,(int)($_GET['page'] ?? 1));
        $limit  = max(1,min(500,(int)($_GET['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;
        $q       = trim((string)($_GET['q']        ?? ''));
        $ruleFil = (int)($_GET['rule_id']         ?? 0);
        $stFil   = trim((string)($_GET['status']  ?? ''));
        $smtpFil = trim((string)($_GET['smtp']    ?? ''));
        $stepFil = isset($_GET['step']) && $_GET['step']!=='' ? (int)$_GET['step'] : null;
        $dFrom   = trim((string)($_GET['date_from'] ?? ''));
        $dTo     = trim((string)($_GET['date_to']   ?? ''));
        // Accept the same `range` preset the dashboard picker emits, so the
        // step reports filter by the same window as the stat cards. Custom
        // YYYY-MM-DD inputs still work via date_from/date_to.
        $rangePreset = trim((string)($_GET['range'] ?? ''));
        if ($rangePreset !== '' && $dFrom === '' && $dTo === '') {
            $rng = resolveDateRange($rangePreset);
            if ($rng['active']) {
                // step report's existing WHERE expects YYYY-MM-DD; strip time.
                $dFrom = substr($rng['from'], 0, 10);
                $dTo   = substr($rng['to'],   0, 10);
            }
        }
        $sort    = trim((string)($_GET['sort']    ?? 'id,desc'));
        $export  = strtolower(trim((string)($_GET['export'] ?? '')));

        // Sort whitelist — never interpolate user input directly into ORDER BY.
        $sortable = ['id','rule_name','lead_email','current_step','last_sent_at','next_send_at','status'];
        [$sortCol, $sortDir] = array_pad(explode(',', $sort, 2), 2, 'desc');
        if (!in_array($sortCol, $sortable, true)) $sortCol = 'id';
        $sortDir = strtolower($sortDir) === 'asc' ? 'ASC' : 'DESC';
        $sortMap = [
            'id'           => 't.id',
            'rule_name'    => 'r.name',
            'lead_email'   => $isArReport ? 't.from_email' : 't.email',
            'current_step' => 't.current_step',
            'last_sent_at' => 't.last_sent_at',
            'next_send_at' => 't.next_send_at',
            'status'       => 't.status',
        ];
        $orderSql = $sortMap[$sortCol] . ' ' . $sortDir;

        if ($isArReport) {
            $tableMain='autoreply_threads'; $emailCol='from_email'; $nameCol='from_name';
            $stepsTbl='autoreply_steps';   $logsTbl='autoreply_logs'; $logsLink='thread_id';
            $rulesTbl='autoreply_rules';
        } else {
            $tableMain='followup_contacts'; $emailCol='email'; $nameCol='name';
            $stepsTbl='followup_steps';    $logsTbl='followup_logs'; $logsLink='contact_id';
            $rulesTbl='followup_rules';
        }

        $where = []; $params = [];
        if (!$IS_ADMIN) { $where[] = 'r.user_id = ?'; $params[] = $UID; }
        if ($ruleFil > 0)              { $where[] = 't.rule_id = ?';      $params[] = $ruleFil; }
        if ($stepFil !== null && $stepFil > 0) { $where[] = 't.current_step = ?'; $params[] = $stepFil; }
        if ($q !== '') {
            $where[] = "(t.{$emailCol} LIKE ? OR t.{$nameCol} LIKE ? OR r.name LIKE ?)";
            $params[] = '%'.$q.'%'; $params[] = '%'.$q.'%'; $params[] = '%'.$q.'%';
        }
        if ($dFrom !== '') { $where[] = 't.last_sent_at >= ?'; $params[] = $dFrom.' 00:00:00'; }
        if ($dTo   !== '') { $where[] = 't.last_sent_at <= ?'; $params[] = $dTo  .' 23:59:59'; }
        if ($stFil === 'completed') { $where[] = "t.status='completed'"; }
        elseif ($stFil === 'active') { $where[] = "t.status='active'"; }
        elseif ($stFil === 'pending') {
            $where[] = "t.status='active' AND t.next_send_at IS NOT NULL AND t.next_send_at > NOW()";
        } elseif ($stFil === 'failed') {
            $where[] = "EXISTS (SELECT 1 FROM {$logsTbl} lf WHERE lf.{$logsLink}=t.id AND lf.status='failed')";
        } elseif ($stFil === 'sent') {
            $where[] = "EXISTS (SELECT 1 FROM {$logsTbl} lf WHERE lf.{$logsLink}=t.id AND lf.status='sent')";
        }
        if ($smtpFil !== '') {
            $where[] = "EXISTS (SELECT 1 FROM {$logsTbl} lf WHERE lf.{$logsLink}=t.id AND lf.smtp_used = ?)";
            $params[] = $smtpFil;
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        // Per-row subqueries — small inner tables so the planner inlines them.
        $totalStepsSub = "(SELECT COUNT(*) FROM {$stepsTbl} WHERE rule_id=t.rule_id)";
        $subjectSub    = "(SELECT s.subject FROM {$stepsTbl} s WHERE s.rule_id=t.rule_id AND s.step_number=t.current_step LIMIT 1)";
        $lastLogIdSub  = "(SELECT MAX(id) FROM {$logsTbl} WHERE {$logsLink}=t.id)";
        $lastSmtpSub   = "(SELECT smtp_used FROM {$logsTbl} WHERE id={$lastLogIdSub})";
        $lastStatusSub = "(SELECT status FROM {$logsTbl} WHERE id={$lastLogIdSub})";
        $sentCountSub  = "(SELECT COUNT(*) FROM {$logsTbl} WHERE {$logsLink}=t.id AND status='sent')";
        $failCountSub  = "(SELECT COUNT(*) FROM {$logsTbl} WHERE {$logsLink}=t.id AND status='failed')";

        $emailSelect = $isArReport ? 't.from_email AS lead_email, t.from_name AS lead_name'
                                   : 't.email      AS lead_email, t.name      AS lead_name';

        $cntSql = "SELECT COUNT(*) FROM {$tableMain} t JOIN {$rulesTbl} r ON r.id=t.rule_id {$whereSql}";
        $cntStmt = $pdo->prepare($cntSql);
        $cntStmt->execute($params);
        $total = (int)$cntStmt->fetchColumn();

        $isExport = in_array($export, ['csv','xls','xlsx','excel'], true);
        $rowLimit  = $isExport ? 50000 : $limit;
        $rowOffset = $isExport ? 0     : $offset;

        $rowSql = "
            SELECT
                t.id,
                t.rule_id,
                r.name AS rule_name,
                {$emailSelect},
                t.current_step,
                {$totalStepsSub} AS total_steps,
                {$subjectSub}    AS subject,
                t.last_sent_at,
                t.next_send_at,
                {$lastSmtpSub}   AS smtp_used,
                {$lastStatusSub} AS last_log_status,
                {$sentCountSub}  AS sent_count,
                {$failCountSub}  AS failed_count,
                t.status
            FROM {$tableMain} t
            JOIN {$rulesTbl} r ON r.id = t.rule_id
            {$whereSql}
            ORDER BY {$orderSql}
            LIMIT {$rowLimit} OFFSET {$rowOffset}";
        $rowStmt = $pdo->prepare($rowSql);
        $rowStmt->execute($params);
        $rows = $rowStmt->fetchAll();

        // Server-derived UI flags so client and CSV agree on every status.
        $now = time();
        foreach ($rows as &$rr) {
            $isCompleted = ($rr['status'] === 'completed');
            $hasNext     = !empty($rr['next_send_at']);
            $nextTs      = $hasNext ? strtotime($rr['next_send_at']) : 0;
            $rr['is_completed'] = $isCompleted ? 1 : 0;
            $rr['is_failed']    = ((int)$rr['failed_count']) > 0 ? 1 : 0;
            $rr['is_sent']      = ((int)$rr['sent_count'])   > 0 ? 1 : 0;
            $rr['is_pending']   = (!$isCompleted && $hasNext && $nextTs > $now) ? 1 : 0;
            $rr['is_running']   = (!$isCompleted && $hasNext && $nextTs <= $now) ? 1 : 0;
            if ($isArReport) {
                // For AR threads we already track inbound count on the row.
                $rr['messages_received'] = isset($rr['messages_received']) ? (int)$rr['messages_received'] : 0;
            } else {
                $rr['messages_received'] = 0; // backfilled below
            }
        }
        unset($rr);

        // FU enrichment: count inbound_emails per lead in one batched query.
        if (!$isArReport && $rows) {
            $emails = array_values(array_unique(array_map(fn($r) => strtolower($r['lead_email']), $rows)));
            if ($emails) {
                $ph = implode(',', array_fill(0, count($emails), '?'));
                $cstmt = $pdo->prepare(
                    "SELECT LOWER(from_email) AS k, COUNT(*) AS c
                       FROM inbound_emails
                      WHERE LOWER(from_email) IN ({$ph})
                      GROUP BY LOWER(from_email)"
                );
                $cstmt->execute($emails);
                $cmap = [];
                foreach ($cstmt->fetchAll() as $cr) $cmap[$cr['k']] = (int)$cr['c'];
                foreach ($rows as &$rr2) {
                    $k = strtolower($rr2['lead_email']);
                    $rr2['messages_received'] = $cmap[$k] ?? 0;
                }
                unset($rr2);
            }
        }
        // For AR, refetch messages_received which the row carries natively
        if ($isArReport && $rows) {
            $ids = array_map(fn($r)=>(int)$r['id'], $rows);
            if ($ids) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $mstmt = $pdo->prepare("SELECT id, messages_received FROM autoreply_threads WHERE id IN ({$ph})");
                $mstmt->execute($ids);
                $mmap = [];
                foreach ($mstmt->fetchAll() as $mr) $mmap[(int)$mr['id']] = (int)$mr['messages_received'];
                foreach ($rows as &$rr3) $rr3['messages_received'] = $mmap[(int)$rr3['id']] ?? 0;
                unset($rr3);
            }
        }

        if (!$isExport) {
            $rulesQ = $pdo->prepare("SELECT id,name FROM {$rulesTbl}".($IS_ADMIN?'':' WHERE user_id=?').' ORDER BY name');
            if ($IS_ADMIN) $rulesQ->execute(); else $rulesQ->execute([$UID]);
            $rulesList = $rulesQ->fetchAll();

            $smtpQ = $pdo->prepare("SELECT DISTINCT name FROM smtp_providers".($IS_ADMIN?'':' WHERE user_id=?').' ORDER BY name');
            if ($IS_ADMIN) $smtpQ->execute(); else $smtpQ->execute([$UID]);
            $smtpList = array_values(array_filter(array_map(fn($r)=>$r['name'],$smtpQ->fetchAll())));

            jsonOut([
                'rows'  => $rows,
                'total' => $total,
                'page'  => $page,
                'pages' => max(1,(int)ceil($total/$limit)),
                'rules' => $rulesList,
                'smtps' => $smtpList,
            ]);
        }

        // ── Export branch ──────────────────────────────────────────────
        $kind = $isArReport ? 'auto-reply' : 'follow-up';
        $cols = [
            'Campaign'             => 'rule_name',
            'Lead Email'           => 'lead_email',
            'Lead Name'            => 'lead_name',
            'Current Step'         => 'current_step',
            'Total Steps'          => 'total_steps',
            'Subject'              => 'subject',
            'Sent Count'           => 'sent_count',
            'Failed Count'         => 'failed_count',
            'Read Count (Inbound)' => 'messages_received',
            'Last Sent'            => 'last_sent_at',
            'Next Scheduled'       => 'next_send_at',
            'SMTP Used'            => 'smtp_used',
            'Status'               => 'status',
        ];
        while (ob_get_level() > 0) ob_end_clean();
        $fname = $kind . '-step-report-' . date('Y-m-d_His');

        if ($export === 'csv') {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="'.$fname.'.csv"');
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, array_keys($cols));
            foreach ($rows as $rr) {
                $line = [];
                foreach ($cols as $h => $k) $line[] = (string)($rr[$k] ?? '');
                fputcsv($out, $line);
            }
            fclose($out); exit;
        }
        // Excel: HTML table with .xls extension. Excel + LibreOffice both
        // open this natively without needing PhpSpreadsheet.
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$fname.'.xls"');
        echo "\xEF\xBB\xBF";
        echo "<html><head><meta charset='UTF-8'></head><body><table border='1'>";
        echo '<tr>';
        foreach (array_keys($cols) as $h) echo '<th>'.htmlspecialchars($h).'</th>';
        echo '</tr>';
        foreach ($rows as $rr) {
            echo '<tr>';
            foreach ($cols as $h => $k) {
                echo '<td>'.htmlspecialchars((string)($rr[$k] ?? '')).'</td>';
            }
            echo '</tr>';
        }
        echo '</table></body></html>';
        exit;
    }

    jsonOut(['error'=>'Not found'],404);
}

// ── LEADS (clear + export) ────────────────────────────────────────
if ($res==='leads') {

    // GET leads — list all leads with pagination and filter
    if ($method==='GET' && !$id) {
        $page   = max(1,(int)($_GET['page']??1));
        $limit  = 100;
        $offset = ($page-1)*$limit;
        $source = $_GET['source'] ?? 'all';   // all | lists | autoreply | followup
        $search = trim($_GET['q'] ?? '');
        $listId = isset($_GET['list_id']) ? (int)$_GET['list_id'] : 0;

        $pdo = db();
        $likeVal = $search ? '%'.$search.'%' : null;

        // Detect whether emails.created_at column exists (belt-and-suspenders for MySQL 5.7
        // installs where ALTER TABLE … IF NOT EXISTS is unsupported and migration may have failed)
        $hasEmailDate = true;
        try {
            $pdo->query("SELECT created_at FROM emails LIMIT 0");
        } catch (Exception $_ce) {
            $hasEmailDate = false;
        }
        $eDate = $hasEmailDate ? "IFNULL(e.created_at,'')" : "''";

        try {
            $rows  = [];
            $total = 0;

            if ($source === 'all') {
                // Build UNION ALL so pagination spans all three sources uniformly
                $lw = $IS_ADMIN ? "l.user_id IS NOT NULL" : "l.user_id=$UID";
                if ($listId) $lw .= " AND e.list_id=$listId";
                if ($likeVal) $lw .= " AND (e.email LIKE ".$pdo->quote($likeVal)." OR e.name LIKE ".$pdo->quote($likeVal).")";

                $aw = $IS_ADMIN ? "r.user_id IS NOT NULL" : "r.user_id=$UID";
                if ($likeVal) $aw .= " AND (t.from_email LIKE ".$pdo->quote($likeVal)." OR t.from_name LIKE ".$pdo->quote($likeVal).")";

                $fw = $IS_ADMIN ? "r.user_id IS NOT NULL" : "r.user_id=$UID";
                if ($likeVal) $fw .= " AND (c.email LIKE ".$pdo->quote($likeVal)." OR c.name LIKE ".$pdo->quote($likeVal).")";

                $total  = (int)$pdo->query("SELECT COUNT(*) FROM emails e JOIN email_lists l ON l.id=e.list_id WHERE $lw")->fetchColumn();
                $total += (int)$pdo->query("SELECT COUNT(*) FROM autoreply_threads t JOIN autoreply_rules r ON r.id=t.rule_id WHERE $aw")->fetchColumn();
                $total += (int)$pdo->query("SELECT COUNT(*) FROM followup_contacts c JOIN followup_rules r ON r.id=c.rule_id WHERE $fw")->fetchColumn();

                // Each row exposes its source-table primary key as `_id` so the
                // per-row Delete button knows which record to remove.
                $parts = [
                    "SELECT e.id _id,e.email,IFNULL(e.name,'') name,l.name list_name,'email_list' source,$eDate created_at FROM emails e JOIN email_lists l ON l.id=e.list_id WHERE $lw",
                    "SELECT t.id _id,t.from_email email,IFNULL(t.from_name,'') name,r.name list_name,'auto_reply' source,IFNULL(t.created_at,'') created_at FROM autoreply_threads t JOIN autoreply_rules r ON r.id=t.rule_id WHERE $aw",
                    "SELECT c.id _id,c.email,IFNULL(c.name,'') name,r.name list_name,'follow_up' source,IFNULL(c.enrolled_at,'') created_at FROM followup_contacts c JOIN followup_rules r ON r.id=c.rule_id WHERE $fw",
                ];
                $union = implode(' UNION ALL ', $parts);
                $rows  = $pdo->query("SELECT * FROM ($union) _u ORDER BY created_at DESC LIMIT $limit OFFSET $offset")->fetchAll();

            } elseif ($source === 'lists') {
                $w = $IS_ADMIN ? "l.user_id IS NOT NULL" : "l.user_id=$UID";
                if ($listId) $w .= " AND e.list_id=$listId";
                if ($likeVal) $w .= " AND (e.email LIKE ".$pdo->quote($likeVal)." OR e.name LIKE ".$pdo->quote($likeVal).")";
                $total = (int)$pdo->query("SELECT COUNT(*) FROM emails e JOIN email_lists l ON l.id=e.list_id WHERE $w")->fetchColumn();
                $rows  = $pdo->query("SELECT e.id _id,e.email,IFNULL(e.name,'') name,l.name list_name,'email_list' source,$eDate created_at FROM emails e JOIN email_lists l ON l.id=e.list_id WHERE $w ORDER BY e.id DESC LIMIT $limit OFFSET $offset")->fetchAll();

            } elseif ($source === 'autoreply') {
                $w = $IS_ADMIN ? "r.user_id IS NOT NULL" : "r.user_id=$UID";
                if ($likeVal) $w .= " AND (t.from_email LIKE ".$pdo->quote($likeVal)." OR t.from_name LIKE ".$pdo->quote($likeVal).")";
                $total = (int)$pdo->query("SELECT COUNT(*) FROM autoreply_threads t JOIN autoreply_rules r ON r.id=t.rule_id WHERE $w")->fetchColumn();
                $rows  = $pdo->query("SELECT t.id _id,t.from_email email,IFNULL(t.from_name,'') name,r.name list_name,'auto_reply' source,IFNULL(t.created_at,'') created_at FROM autoreply_threads t JOIN autoreply_rules r ON r.id=t.rule_id WHERE $w ORDER BY t.id DESC LIMIT $limit OFFSET $offset")->fetchAll();

            } elseif ($source === 'followup') {
                $w = $IS_ADMIN ? "r.user_id IS NOT NULL" : "r.user_id=$UID";
                if ($likeVal) $w .= " AND (c.email LIKE ".$pdo->quote($likeVal)." OR c.name LIKE ".$pdo->quote($likeVal).")";
                $total = (int)$pdo->query("SELECT COUNT(*) FROM followup_contacts c JOIN followup_rules r ON r.id=c.rule_id WHERE $w")->fetchColumn();
                $rows  = $pdo->query("SELECT c.id _id,c.email,IFNULL(c.name,'') name,r.name list_name,'follow_up' source,IFNULL(c.enrolled_at,'') created_at FROM followup_contacts c JOIN followup_rules r ON r.id=c.rule_id WHERE $w ORDER BY c.id DESC LIMIT $limit OFFSET $offset")->fetchAll();
            }

            jsonOut(['rows'=>$rows,'total'=>(int)$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/$limit))]);

        } catch (Exception $qe) {
            // Return empty result with error detail so the UI shows a helpful message
            jsonOut(['rows'=>[],'total'=>0,'page'=>1,'pages'=>1,'error'=>$qe->getMessage()]);
        }
    }

    // GET leads/export — export all leads as CSV
    if ($method==='GET' && $id==='export') {
        $listId  = isset($_GET['list_id']) ? (int)$_GET['list_id'] : 0;
        $source  = $_GET['source'] ?? 'all'; // all | lists | autoreply | followup
        $search  = trim($_GET['q'] ?? '');
        $pdo     = db();
        $likeVal = $search ? '%'.$search.'%' : null;

        // Detect whether emails.created_at exists (MySQL 5.7 safety)
        $hasEmailDate = true;
        try { $pdo->query("SELECT created_at FROM emails LIMIT 0"); } catch (Exception $_ce) { $hasEmailDate = false; }
        $eDate = $hasEmailDate ? "IFNULL(e.created_at,'')" : "''";

        $rows = [];
        try {
            if ($source === 'all' || $source === 'lists') {
                $w = $IS_ADMIN ? "l.user_id IS NOT NULL" : "l.user_id=$UID";
                if ($listId)  $w .= " AND e.list_id=$listId";
                if ($likeVal) $w .= " AND (e.email LIKE ".$pdo->quote($likeVal)." OR e.name LIKE ".$pdo->quote($likeVal).")";
                foreach ($pdo->query("SELECT e.email,IFNULL(e.name,'') name,l.name list_name,'email_list' source,$eDate created_at FROM emails e JOIN email_lists l ON l.id=e.list_id WHERE $w ORDER BY e.id DESC")->fetchAll() as $r) $rows[] = $r;
            }
            if ($source === 'all' || $source === 'autoreply') {
                $w = $IS_ADMIN ? "r.user_id IS NOT NULL" : "r.user_id=$UID";
                if ($likeVal) $w .= " AND (t.from_email LIKE ".$pdo->quote($likeVal)." OR t.from_name LIKE ".$pdo->quote($likeVal).")";
                foreach ($pdo->query("SELECT t.from_email email,IFNULL(t.from_name,'') name,r.name list_name,'auto_reply' source,IFNULL(t.created_at,'') created_at FROM autoreply_threads t JOIN autoreply_rules r ON r.id=t.rule_id WHERE $w ORDER BY t.id DESC")->fetchAll() as $r) $rows[] = $r;
            }
            if ($source === 'all' || $source === 'followup') {
                $w = $IS_ADMIN ? "r.user_id IS NOT NULL" : "r.user_id=$UID";
                if ($likeVal) $w .= " AND (c.email LIKE ".$pdo->quote($likeVal)." OR c.name LIKE ".$pdo->quote($likeVal).")";
                foreach ($pdo->query("SELECT c.email,IFNULL(c.name,'') name,r.name list_name,'follow_up' source,IFNULL(c.enrolled_at,'') created_at FROM followup_contacts c JOIN followup_rules r ON r.id=c.rule_id WHERE $w ORDER BY c.id DESC")->fetchAll() as $r) $rows[] = $r;
            }
        } catch (Exception $qe) { /* on error, export whatever we have so far */ }

        // Stream as UTF-8 CSV with BOM for Excel compatibility
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="leads_export_'.date('Y-m-d_His').'.csv"');
        header('Cache-Control: no-cache, no-store');
        $out = fopen('php://output','w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
        fputcsv($out, ['Email','Name','Source List / Rule','Type','Added']);
        foreach ($rows as $row) {
            fputcsv($out, [$row['email']??'',$row['name']??'',$row['list_name']??'',$row['source']??'',$row['created_at']??'']);
        }
        fclose($out);
        exit;
    }

    // DELETE leads/clear — clear leads from a list, autoreply threads, or followup contacts
    if ($method==='DELETE' && $id==='clear') {
        $target   = $_GET['target']    ?? '';  // list | autoreply | followup | all
        $targetId = isset($_GET['target_id']) ? (int)$_GET['target_id'] : 0;

        if (!in_array($target, ['list','autoreply','followup','all'], true)) {
            jsonOut(['ok'=>false,'message'=>'Invalid target']);
        }

        $cleared = 0;
        $pdo = db();

        try {
            // ── Clear email list contacts ──────────────────────────────
            if ($target === 'list' || $target === 'all') {
                if ($targetId) {
                    $s = $pdo->prepare('SELECT user_id FROM email_lists WHERE id=?');
                    $s->execute([$targetId]); $own = $s->fetch();
                    if ($own && ($IS_ADMIN || (int)$own['user_id'] === $UID)) {
                        $c = $pdo->prepare('SELECT COUNT(*) FROM emails WHERE list_id=?');
                        $c->execute([$targetId]);
                        $cleared += (int)$c->fetchColumn();
                        $pdo->prepare('DELETE FROM emails WHERE list_id=?')->execute([$targetId]);
                        $pdo->prepare('UPDATE email_lists SET total_count=0 WHERE id=?')->execute([$targetId]);
                    }
                } elseif ($target === 'all') {
                    if ($IS_ADMIN) {
                        $listIds = $pdo->query('SELECT id FROM email_lists')->fetchAll(PDO::FETCH_COLUMN);
                    } else {
                        $st = $pdo->prepare('SELECT id FROM email_lists WHERE user_id=?');
                        $st->execute([$UID]);
                        $listIds = $st->fetchAll(PDO::FETCH_COLUMN);
                    }
                    foreach ($listIds as $lid) {
                        $c = $pdo->prepare('SELECT COUNT(*) FROM emails WHERE list_id=?');
                        $c->execute([$lid]);
                        $cleared += (int)$c->fetchColumn();
                        $pdo->prepare('DELETE FROM emails WHERE list_id=?')->execute([$lid]);
                        $pdo->prepare('UPDATE email_lists SET total_count=0 WHERE id=?')->execute([$lid]);
                    }
                }
            }

            // ── Clear auto-reply threads ───────────────────────────────
            if ($target === 'autoreply' || $target === 'all') {
                if ($targetId) {
                    $s = $pdo->prepare('SELECT user_id FROM autoreply_rules WHERE id=?');
                    $s->execute([$targetId]); $own = $s->fetch();
                    if ($own && ($IS_ADMIN || (int)$own['user_id'] === $UID)) {
                        $c = $pdo->prepare('SELECT COUNT(*) FROM autoreply_threads WHERE rule_id=?');
                        $c->execute([$targetId]);
                        $cleared += (int)$c->fetchColumn();
                        $pdo->prepare('DELETE FROM autoreply_threads WHERE rule_id=?')->execute([$targetId]);
                        try { $pdo->prepare('DELETE FROM autoreply_logs WHERE rule_id=?')->execute([$targetId]); } catch(Exception $e2) {}
                    }
                } elseif ($target === 'all') {
                    if ($IS_ADMIN) {
                        $arIds = $pdo->query('SELECT id FROM autoreply_rules')->fetchAll(PDO::FETCH_COLUMN);
                    } else {
                        $st = $pdo->prepare('SELECT id FROM autoreply_rules WHERE user_id=?');
                        $st->execute([$UID]);
                        $arIds = $st->fetchAll(PDO::FETCH_COLUMN);
                    }
                    foreach ($arIds as $rid) {
                        $c = $pdo->prepare('SELECT COUNT(*) FROM autoreply_threads WHERE rule_id=?');
                        $c->execute([$rid]);
                        $cleared += (int)$c->fetchColumn();
                        $pdo->prepare('DELETE FROM autoreply_threads WHERE rule_id=?')->execute([$rid]);
                        try { $pdo->prepare('DELETE FROM autoreply_logs WHERE rule_id=?')->execute([$rid]); } catch(Exception $e2) {}
                    }
                }
            }

            // ── Clear follow-up contacts ───────────────────────────────
            if ($target === 'followup' || $target === 'all') {
                if ($targetId) {
                    $s = $pdo->prepare('SELECT user_id FROM followup_rules WHERE id=?');
                    $s->execute([$targetId]); $own = $s->fetch();
                    if ($own && ($IS_ADMIN || (int)$own['user_id'] === $UID)) {
                        $c = $pdo->prepare('SELECT COUNT(*) FROM followup_contacts WHERE rule_id=?');
                        $c->execute([$targetId]);
                        $cleared += (int)$c->fetchColumn();
                        $pdo->prepare('DELETE FROM followup_contacts WHERE rule_id=?')->execute([$targetId]);
                        try { $pdo->prepare('DELETE FROM followup_logs WHERE rule_id=?')->execute([$targetId]); } catch(Exception $e2) {}
                    }
                } elseif ($target === 'all') {
                    if ($IS_ADMIN) {
                        $fuIds = $pdo->query('SELECT id FROM followup_rules')->fetchAll(PDO::FETCH_COLUMN);
                    } else {
                        $st = $pdo->prepare('SELECT id FROM followup_rules WHERE user_id=?');
                        $st->execute([$UID]);
                        $fuIds = $st->fetchAll(PDO::FETCH_COLUMN);
                    }
                    foreach ($fuIds as $rid) {
                        $c = $pdo->prepare('SELECT COUNT(*) FROM followup_contacts WHERE rule_id=?');
                        $c->execute([$rid]);
                        $cleared += (int)$c->fetchColumn();
                        $pdo->prepare('DELETE FROM followup_contacts WHERE rule_id=?')->execute([$rid]);
                        try { $pdo->prepare('DELETE FROM followup_logs WHERE rule_id=?')->execute([$rid]); } catch(Exception $e2) {}
                    }
                }
            }

            jsonOut(['ok'=>true,'cleared'=>$cleared]);
        } catch(Exception $e) {
            jsonOut(['ok'=>false,'message'=>'Clear failed: '.$e->getMessage()]);
        }
    }

    // ── Delete a single lead row ───────────────────────────────────────
    // DELETE api.php?r=leads/item&source=<email_list|auto_reply|follow_up>&row_id=<pk>
    // Ownership is verified via the parent (list / rule) — non-admin users
    // can only delete leads attached to their own lists/rules.
    if ($method==='DELETE' && $id==='item') {
        $src   = $_GET['source'] ?? '';
        $rowId = (int)($_GET['row_id'] ?? 0);
        if (!$rowId || !in_array($src, ['email_list','auto_reply','follow_up'], true)) {
            jsonOut(['ok'=>false,'message'=>'Invalid source or row_id']);
        }
        $pdo = db();
        try {
            if ($src === 'email_list') {
                // Ownership: emails.list_id → email_lists.user_id
                $s = $pdo->prepare(
                    "SELECT l.user_id, e.list_id
                       FROM emails e
                       JOIN email_lists l ON l.id = e.list_id
                      WHERE e.id = ?"
                );
                $s->execute([$rowId]); $own = $s->fetch();
                if (!$own) jsonOut(['ok'=>false,'message'=>'Lead not found']);
                if (!$IS_ADMIN && (int)$own['user_id'] !== $UID) jsonOut(['ok'=>false,'message'=>'Forbidden'], 403);
                $pdo->prepare('DELETE FROM emails WHERE id=?')->execute([$rowId]);
                // Keep email_lists.total_count consistent so the dashboard
                // counters don't drift after individual deletes.
                try {
                    $pdo->prepare(
                        "UPDATE email_lists
                            SET total_count = (SELECT COUNT(*) FROM emails WHERE list_id = ?)
                          WHERE id = ?"
                    )->execute([(int)$own['list_id'], (int)$own['list_id']]);
                } catch (Exception $_e) {}
            } elseif ($src === 'auto_reply') {
                // Ownership: autoreply_threads.rule_id → autoreply_rules.user_id
                $s = $pdo->prepare(
                    "SELECT r.user_id
                       FROM autoreply_threads t
                       JOIN autoreply_rules r ON r.id = t.rule_id
                      WHERE t.id = ?"
                );
                $s->execute([$rowId]); $own = $s->fetch();
                if (!$own) jsonOut(['ok'=>false,'message'=>'Lead not found']);
                if (!$IS_ADMIN && (int)$own['user_id'] !== $UID) jsonOut(['ok'=>false,'message'=>'Forbidden'], 403);
                $pdo->prepare('DELETE FROM autoreply_threads WHERE id=?')->execute([$rowId]);
                // Best-effort cleanup of related autoreply_logs rows.
                try { $pdo->prepare('DELETE FROM autoreply_logs WHERE thread_id=?')->execute([$rowId]); } catch (Exception $_e) {}
            } else { // follow_up
                $s = $pdo->prepare(
                    "SELECT r.user_id
                       FROM followup_contacts c
                       JOIN followup_rules r ON r.id = c.rule_id
                      WHERE c.id = ?"
                );
                $s->execute([$rowId]); $own = $s->fetch();
                if (!$own) jsonOut(['ok'=>false,'message'=>'Lead not found']);
                if (!$IS_ADMIN && (int)$own['user_id'] !== $UID) jsonOut(['ok'=>false,'message'=>'Forbidden'], 403);
                $pdo->prepare('DELETE FROM followup_contacts WHERE id=?')->execute([$rowId]);
                try { $pdo->prepare('DELETE FROM followup_logs WHERE contact_id=?')->execute([$rowId]); } catch (Exception $_e) {}
            }
            jsonOut(['ok'=>true,'deleted'=>1]);
        } catch (Exception $e) {
            jsonOut(['ok'=>false,'message'=>'Delete failed: '.$e->getMessage()]);
        }
    }

    jsonOut(['error'=>'Not found'],404);
}

// ── DISPLAY NAME SETTINGS ────────────────────────────────────────
// GET  api.php?r=settings/display-name  → returns current global display name for user
// POST api.php?r=settings/display-name  → saves global display name for user
if ($res==='settings') {
    if ($id==='display-name') {
        if ($method==='GET') {
            $s=db()->prepare('SELECT meta_value FROM user_meta WHERE user_id=? AND meta_key=?');
            $s->execute([$UID,'display_name']);
            $row=$s->fetch();
            jsonOut(['display_name'=>$row?$row['meta_value']:'']);
        }
        if ($method==='POST') {
            $b=body();
            $dn=trim($b['display_name']??'');
            // Upsert into user_meta
            db()->prepare('INSERT INTO user_meta (user_id,meta_key,meta_value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE meta_value=?')
                ->execute([$UID,'display_name',$dn,$dn]);
            jsonOut(['ok'=>true,'display_name'=>$dn]);
        }
    }
    jsonOut(['error'=>'Not found'],404);
}

// ── BLACKLIST ─────────────────────────────────────────────────────
// GET  api.php?r=blacklist?type=email|domain|extension|subject|keyword&page=N&q=... → list
// GET  api.php?r=blacklist/stats                          → counts
// POST api.php?r=blacklist  {type,value}                 → add entry
// DELETE api.php?r=blacklist/{id}                        → remove one
// DELETE api.php?r=blacklist?type=email|domain|extension → clear all of type
//
// 'extension' is a virtual type: domain entries whose value starts with '.'
// (e.g. '.com', '.net', '.org', '.us'). They are stored as type='domain' in
// the blacklist table and matched by the existing isBlacklisted() suffix logic.
// The extension layer simply filters/adds/clears these specific entries so the
// UI can manage them in a dedicated section without touching the domain list.
if ($res==='blacklist') {

    // Stats
    if ($method==='GET' && $id==='stats') {
        $p = $IS_ADMIN ? [] : [$UID];
        $we = $IS_ADMIN ? "1=1" : "user_id=?";
        
        $emailsStmt = db()->prepare("SELECT COUNT(*) FROM blacklist WHERE type='email' AND $we");
        $emailsStmt->execute($p);
        $emails = (int)$emailsStmt->fetchColumn();

        $domains = 0; $extensions = 0;
        try {
            $domStmt = db()->prepare("SELECT COUNT(*) FROM blacklist WHERE type='domain' AND domain IS NOT NULL AND domain NOT LIKE '.%' AND $we");
            $domStmt->execute($p);
            $domains = (int)$domStmt->fetchColumn();

            $extStmt = db()->prepare("SELECT COUNT(*) FROM blacklist WHERE type='domain' AND domain IS NOT NULL AND domain LIKE '.%' AND $we");
            $extStmt->execute($p);
            $extensions = (int)$extStmt->fetchColumn();
        } catch (Exception $_e) {}

        $subjects = 0; $keywords = 0;
        try {
            $subStmt = db()->prepare("SELECT COUNT(*) FROM blacklist WHERE type='subject' AND $we");
            $subStmt->execute($p);
            $subjects = (int)$subStmt->fetchColumn();

            $keyStmt = db()->prepare("SELECT COUNT(*) FROM blacklist WHERE type='keyword' AND $we");
            $keyStmt->execute($p);
            $keywords = (int)$keyStmt->fetchColumn();
        } catch (Exception $_e) {}

        jsonOut([
            'emails'     => $emails,
            'domains'    => $domains,
            'extensions' => $extensions,
            'subjects'   => $subjects,
            'keywords'   => $keywords,
            'total'      => $emails + $domains + $extensions + $subjects + $keywords,
        ]);
    }

    // List
    if ($method==='GET' && !$id) {
        $type   = $_GET['type'] ?? 'email';
        // 'extension' is a virtual type backed by type='domain' WHERE domain LIKE '.%'
        if (!in_array($type, ['email','domain','extension','subject','keyword'], true)) $type = 'email';
        $page   = max(1,(int)($_GET['page']??1));
        $limit  = 50;
        $offset = ($page-1)*$limit;
        $q      = $_GET['q'] ?? '';

        if ($type === 'extension') {
            // Virtual: domain entries starting with '.'
            $userW = $IS_ADMIN ? "type='domain' AND domain LIKE '.%'" : "user_id=$UID AND type='domain' AND domain LIKE '.%'";
            if ($q) $userW .= " AND domain LIKE ".db()->quote('%'.$q.'%');
            try {
                $total = (int)db()->query("SELECT COUNT(*) FROM blacklist WHERE $userW")->fetchColumn();
                $rows  = db()->query("SELECT id,domain,created_at FROM blacklist WHERE $userW ORDER BY id DESC LIMIT $limit OFFSET $offset")->fetchAll();
            } catch (Exception $_e) {
                jsonOut(['rows'=>[],'total'=>0,'page'=>$page,'pages'=>1]);
            }
            foreach ($rows as &$row) { $row['value'] = $row['domain']; }
            jsonOut(['rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>(int)ceil($total/$limit)]);
        }

        $col    = $type==='domain'  ? 'domain'
                : ($type==='subject' || $type==='keyword' ? 'phrase' : 'email');

        // For 'domain' list: exclude extension entries (those starting with '.')
        // so each section shows only its own entries.
        if ($type === 'domain') {
            $where = $IS_ADMIN ? "type='domain' AND domain IS NOT NULL AND domain NOT LIKE '.%'"
                               : "user_id=$UID AND type='domain' AND domain IS NOT NULL AND domain NOT LIKE '.%'";
        } else {
            $where = $IS_ADMIN ? "type='$type'" : "user_id=$UID AND type='$type'";
        }
        if ($q) $where .= " AND $col LIKE ".db()->quote('%'.$q.'%');
        try {
            $total = (int)db()->query("SELECT COUNT(*) FROM blacklist WHERE $where")->fetchColumn();
            $rows  = db()->query("SELECT id,$col,created_at FROM blacklist WHERE $where ORDER BY id DESC LIMIT $limit OFFSET $offset")->fetchAll();
        } catch (Exception $_e) {
            // Migration hasn't applied yet — return empty page rather than 500.
            jsonOut(['rows'=>[],'total'=>0,'page'=>$page,'pages'=>1,'note'=>'Run a cron pass to trigger schema migration: '.$_e->getMessage()]);
        }
        // Normalize: add 'value' alias for JS
        foreach ($rows as &$row) { $row['value'] = $row[$col]; }
        jsonOut(['rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>(int)ceil($total/$limit)]);
    }

    // Add
    if ($method==='POST' && !$id) {
        $b     = body();
        // 'extension' is a virtual type: stored as type='domain' with a leading dot.
        $type  = in_array($b['type']??'', ['email','domain','extension','subject','keyword']) ? $b['type'] : 'email';
        $value = trim($b['value']??'');
        // email/domain/extension are stored lowercase; subject/keyword preserve case.
        if (in_array($type, ['email','domain','extension'])) $value = strtolower($value);
        if ($value === '') jsonOut(['ok'=>false,'message'=>'Value required'],400);

        // Server-side format validation
        if ($type === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            jsonOut(['ok'=>false,'message'=>'Invalid email address format'],400);
        }
        if ($type === 'domain') {
            // Must not contain @ and must look like a valid domain
            if (strpos($value, '@') !== false) {
                jsonOut(['ok'=>false,'message'=>'Enter just the domain without @'],400);
            }
            if (!preg_match('/^[a-z0-9]([a-z0-9\-]*\.)+[a-z]{2,}$/i', $value)) {
                jsonOut(['ok'=>false,'message'=>'Invalid domain format — use e.g. example.com'],400);
            }
        }

        if ($type === 'extension') {
            // Normalise: ensure it starts with a dot (e.g. "com" → ".com")
            if ($value[0] !== '.') $value = '.' . $value;
            // Validate: must look like a TLD or extension (.com, .co.uk, etc.)
            if (!preg_match('/^\.[a-z0-9]([a-z0-9\-.]{0,253}[a-z0-9])?$/i', $value)) {
                jsonOut(['ok'=>false,'message'=>'Invalid extension format. Use e.g. .com, .net, .co.uk'],400);
            }
            // Store as domain type with leading dot
            try {
                // Check for duplicates: user's own entry OR any existing admin entry
                $check = db()->prepare("SELECT id FROM blacklist WHERE (user_id=? OR user_id IN (SELECT id FROM users WHERE is_admin=1)) AND type='domain' AND domain=?");
                $check->execute([$UID,$value]);
                if ($check->fetch()) jsonOut(['ok'=>false,'message'=>'Extension already blacklisted']);
                db()->prepare("INSERT INTO blacklist (user_id,type,domain) VALUES (?,?,?)")->execute([$UID,'domain',$value]);
            } catch (Exception $e) {
                jsonOut(['ok'=>false,'message'=>'Insert failed: '.$e->getMessage()]);
            }
            jsonOut(['ok'=>true,'message'=>'Extension blocked — all emails from *'.$value.' addresses will be ignored']);
        }

        $col = $type==='domain'  ? 'domain'
             : ($type==='subject' || $type==='keyword' ? 'phrase' : 'email');
        // Check duplicate — case-insensitive for subject/keyword phrases.
        // Also checks admin entries so users don't add redundant copies of admin rules.
        try {
            if ($type === 'subject' || $type === 'keyword') {
                $check = db()->prepare("SELECT id FROM blacklist WHERE (user_id=? OR user_id IN (SELECT id FROM users WHERE is_admin=1)) AND type=? AND LOWER($col)=LOWER(?)");
            } else {
                $check = db()->prepare("SELECT id FROM blacklist WHERE (user_id=? OR user_id IN (SELECT id FROM users WHERE is_admin=1)) AND type=? AND $col=?");
            }
            $check->execute([$UID,$type,$value]);
            if ($check->fetch()) jsonOut(['ok'=>false,'message'=>'Already blacklisted']);
            db()->prepare("INSERT INTO blacklist (user_id,type,$col) VALUES (?,?,?)")->execute([$UID,$type,$value]);
        } catch (Exception $e) {
            jsonOut(['ok'=>false,'message'=>'Insert failed (migration may not have applied yet): '.$e->getMessage()]);
        }
        jsonOut(['ok'=>true,'message'=>'Blocked']);
    }

    // Delete one
    if ($method==='DELETE' && $id && is_numeric($id)) {
        $s = db()->prepare('SELECT user_id FROM blacklist WHERE id=?'); $s->execute([$id]); $row=$s->fetch();
        if (!$row) jsonOut(['ok'=>false,'message'=>'Not found'],404);
        if (!$IS_ADMIN && (int)$row['user_id']!==$UID) jsonOut(['ok'=>false,'message'=>'Forbidden'],403);
        db()->prepare('DELETE FROM blacklist WHERE id=?')->execute([$id]);
        jsonOut(['ok'=>true]);
    }

    // Clear all of type
    if ($method==='DELETE' && !$id) {
        $type = $_GET['type'] ?? '';
        if (!in_array($type, ['email','domain','extension','subject','keyword'], true)) {
            jsonOut(['ok'=>false,'message'=>'type parameter required'],400);
        }
        if ($type === 'extension') {
            // Delete only domain entries starting with '.' (the extension entries)
            if ($IS_ADMIN) {
                db()->query("DELETE FROM blacklist WHERE type='domain' AND domain LIKE '.%'");
            } else {
                db()->prepare("DELETE FROM blacklist WHERE user_id=? AND type='domain' AND domain LIKE '.%'")->execute([$UID]);
            }
            jsonOut(['ok'=>true]);
        }
        $where = $IS_ADMIN ? "type=?" : "user_id=? AND type=?";
        $params = $IS_ADMIN ? [$type] : [$UID,$type];
        db()->prepare("DELETE FROM blacklist WHERE $where")->execute($params);
        jsonOut(['ok'=>true]);
    }

    jsonOut(['error'=>'Not found'],404);
}

// ═══════════════════════════════════════════════════════════════════
// ═══════════════════════════════════════════════════════════════════
// BACKUP EMAILS — stores emails whose follow-up sequence completed
// GET    /api/backup         — list backup emails (paginated)
// GET    /api/backup/export  — download all as CSV
// DELETE /api/backup/:id     — remove one entry
// DELETE /api/backup         — clear all entries for this user
// ═══════════════════════════════════════════════════════════════════
if ($res==='backup') {

    // ── LIST ──────────────────────────────────────────────────────
    if ($method==='GET' && !$id) {
        $page   = max(1,(int)($_GET['page']??1));
        $limit  = 50; $offset = ($page-1)*$limit;
        $search = trim($_GET['q'] ?? '');
        $source = $_GET['source'] ?? '';

        $where = $IS_ADMIN ? '1=1' : "user_id=$UID";
        if ($search) $where .= ' AND email LIKE '.db()->quote('%'.$search.'%');
        if (in_array($source,['autoreply','followup'])) $where .= ' AND source='.db()->quote($source);

        $total = (int)db()->query("SELECT COUNT(*) FROM backup_emails WHERE $where")->fetchColumn();
        $rows  = db()->query(
            "SELECT b.*, r_fu.name AS rule_name
             FROM backup_emails b
             LEFT JOIN followup_rules r_fu ON r_fu.id = b.rule_id AND b.source='followup'
             WHERE $where
             ORDER BY b.completed_at DESC
             LIMIT $limit OFFSET $offset"
        )->fetchAll();
        jsonOut(['rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>(int)ceil($total/$limit)]);
    }

    // ── EXPORT CSV ────────────────────────────────────────────────
    if ($method==='GET' && $id==='export') {
        $search = trim($_GET['q'] ?? '');
        $source = $_GET['source'] ?? '';

        $where = $IS_ADMIN ? '1=1' : "user_id=$UID";
        if ($search) $where .= ' AND email LIKE '.db()->quote('%'.$search.'%');
        if (in_array($source, ['autoreply','followup'])) $where .= ' AND source='.db()->quote($source);

        $rows = db()->query(
            "SELECT b.email, b.name, b.source, b.first_seen, b.completed_at,
                    r_fu.name AS rule_name
             FROM backup_emails b
             LEFT JOIN followup_rules r_fu ON r_fu.id = b.rule_id AND b.source='followup'
             WHERE $where
             ORDER BY b.completed_at DESC"
        )->fetchAll();

        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="completed_leads_'.date('Y-m-d_His').'.csv"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($out, ['Email', 'Name', 'Follow-Up Rule', 'First Enrolled', 'Completed At']);
        foreach ($rows as $row) {
            fputcsv($out, [
                $row['email'],
                $row['name'] ?? '',
                $row['rule_name'] ?? '',
                $row['first_seen']    ? date('Y-m-d H:i', strtotime($row['first_seen']))    : '',
                $row['completed_at']  ? date('Y-m-d H:i', strtotime($row['completed_at']))  : '',
            ]);
        }
        fclose($out);
        exit;
    }

    // ── DELETE ONE ───────────────────────────────────────────────
    if ($method==='DELETE' && $id && is_numeric($id)) {
        $s = db()->prepare('SELECT user_id FROM backup_emails WHERE id=?');
        $s->execute([$id]); $row = $s->fetch();
        if (!$row) jsonOut(['ok'=>false,'message'=>'Not found'],404);
        if (!$IS_ADMIN && (int)$row['user_id'] !== $UID) jsonOut(['ok'=>false,'message'=>'Forbidden'],403);
        db()->prepare('DELETE FROM backup_emails WHERE id=?')->execute([$id]);
        jsonOut(['ok'=>true]);
    }

    // ── CLEAR ALL ────────────────────────────────────────────────
    if ($method==='DELETE' && !$id) {
        $where = $IS_ADMIN ? '1=1' : "user_id=$UID";
        db()->query("DELETE FROM backup_emails WHERE $where");
        jsonOut(['ok'=>true]);
    }

    jsonOut(['error'=>'Not found'],404);
}

// ── EMAIL TEMPLATES ───────────────────────────────────────────────
if ($res === 'templates') {
    if ($method === 'GET' && !$id) {
        $stmt = $IS_ADMIN
            ? db()->query("SELECT t.*, u.username owner FROM email_templates t LEFT JOIN users u ON u.id = t.user_id ORDER BY t.id DESC")
            : db()->prepare("SELECT * FROM email_templates WHERE user_id = ? ORDER BY id DESC");
        if (!$IS_ADMIN) $stmt->execute([$UID]);
        jsonOut($stmt->fetchAll() ?: []);
    }
    if ($method === 'GET' && $id) {
        $stmt = db()->prepare("SELECT * FROM email_templates WHERE id = ?" . ($IS_ADMIN ? '' : ' AND user_id = ?'));
        $params = [$id];
        if (!$IS_ADMIN) $params[] = $UID;
        $stmt->execute($params);
        $tmpl = $stmt->fetch();
        if (!$tmpl) jsonOut(['error' => 'Template not found'], 404);
        jsonOut($tmpl);
    }
    if ($method === 'POST' && !$action) {
        $b = body();
        if (empty($b['name'])) jsonOut(['ok' => false, 'message' => 'Template name is required']);
        $stmt = db()->prepare("INSERT INTO email_templates (user_id, name, subject, html_body, text_body) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $UID,
            trim($b['name']),
            $b['subject'] ?? '',
            $b['html_body'] ?? '',
            $b['text_body'] ?? ''
        ]);
        jsonOut(['ok' => true, 'id' => db()->lastInsertId()]);
    }
    if ($method === 'POST' && $id && $action === 'duplicate') {
        $stmt = db()->prepare("SELECT * FROM email_templates WHERE id = ?" . ($IS_ADMIN ? '' : ' AND user_id = ?'));
        $params = [$id];
        if (!$IS_ADMIN) $params[] = $UID;
        $stmt->execute($params);
        $tmpl = $stmt->fetch();
        if (!$tmpl) jsonOut(['ok' => false, 'message' => 'Template not found'], 404);
        $newName = $tmpl['name'] . ' (Copy)';
        $ins = db()->prepare("INSERT INTO email_templates (user_id, name, subject, html_body, text_body) VALUES (?, ?, ?, ?, ?)");
        $ins->execute([$UID, $newName, $tmpl['subject'], $tmpl['html_body'], $tmpl['text_body']]);
        jsonOut(['ok' => true, 'id' => db()->lastInsertId()]);
    }
    if ($method === 'PUT' && $id) {
        $stmt = db()->prepare("SELECT id FROM email_templates WHERE id = ?" . ($IS_ADMIN ? '' : ' AND user_id = ?'));
        $params = [$id];
        if (!$IS_ADMIN) $params[] = $UID;
        $stmt->execute($params);
        if (!$stmt->fetch()) jsonOut(['ok' => false, 'message' => 'Template not found'], 404);
        $b = body();
        $up = db()->prepare("UPDATE email_templates SET name = ?, subject = ?, html_body = ?, text_body = ? WHERE id = ?");
        $up->execute([
            $b['name'] ?? 'Untitled Template',
            $b['subject'] ?? '',
            $b['html_body'] ?? '',
            $b['text_body'] ?? '',
            $id
        ]);
        jsonOut(['ok' => true]);
    }
    if ($method === 'DELETE' && $id) {
        $stmt = db()->prepare("DELETE FROM email_templates WHERE id = ?" . ($IS_ADMIN ? '' : ' AND user_id = ?'));
        $params = [$id];
        if (!$IS_ADMIN) $params[] = $UID;
        $stmt->execute($params);
        jsonOut(['ok' => true]);
    }
    jsonOut(['error' => 'Not found'], 404);
}

// ── SYSTEM & EVENT LOGS ───────────────────────────────────────────
if ($res === 'system-logs' || $res === 'logs') {
    if ($method === 'GET' && $id === 'stats') {
        $where = $IS_ADMIN ? '1=1' : "user_id = {$UID}";
        $today = date('Y-m-d');
        
        $stats = [
            'total_queued'   => (int)db()->query("SELECT COUNT(*) FROM system_logs WHERE {$where} AND event_type='queued'")->fetchColumn(),
            'total_sent'     => (int)db()->query("SELECT COUNT(*) FROM system_logs WHERE {$where} AND event_type='sent'")->fetchColumn(),
            'sent_today'     => (int)db()->query("SELECT COUNT(*) FROM system_logs WHERE {$where} AND event_type='sent' AND DATE(created_at)='{$today}'")->fetchColumn(),
            'failed_today'   => (int)db()->query("SELECT COUNT(*) FROM system_logs WHERE {$where} AND event_type='failed' AND DATE(created_at)='{$today}'")->fetchColumn(),
            'total_opened'   => (int)db()->query("SELECT COUNT(*) FROM system_logs WHERE {$where} AND event_type='opened'")->fetchColumn(),
            'total_clicked'  => (int)db()->query("SELECT COUNT(*) FROM system_logs WHERE {$where} AND event_type='clicked'")->fetchColumn(),
            'total_bounced'  => (int)db()->query("SELECT COUNT(*) FROM system_logs WHERE {$where} AND event_type='bounced'")->fetchColumn(),
            'total_unsub'    => (int)db()->query("SELECT COUNT(*) FROM system_logs WHERE {$where} AND event_type='unsubscribed'")->fetchColumn(),
            'pending_followups' => (int)db()->query("SELECT COUNT(*) FROM email_followup_queue WHERE {$where} AND status='pending'")->fetchColumn(),
            'scheduled_followups' => (int)db()->query("SELECT COUNT(*) FROM email_followup_queue WHERE {$where} AND status='scheduled'")->fetchColumn(),
            'retry_queue'    => (int)db()->query("SELECT COUNT(*) FROM email_followup_queue WHERE {$where} AND status='scheduled' AND retry_count > 0")->fetchColumn(),
        ];
        $sentTotal = max(1, $stats['total_sent']);
        $stats['open_rate'] = round(($stats['total_opened'] / $sentTotal) * 100, 1);
        $stats['click_rate'] = round(($stats['total_clicked'] / $sentTotal) * 100, 1);
        $stats['bounce_rate'] = round(($stats['total_bounced'] / $sentTotal) * 100, 1);
        jsonOut($stats);
    }
    if ($method === 'GET') {
        $pg = max(1, (int)($_GET['page'] ?? 1));
        $lim = 100;
        $off = ($pg - 1) * $lim;
        $where = $IS_ADMIN ? '1=1' : "user_id = {$UID}";
        $params = [];

        $ev = trim($_GET['event'] ?? '');
        if ($ev) {
            $where .= " AND event_type = ?";
            $params[] = $ev;
        }
        $em = trim($_GET['email'] ?? '');
        if ($em) {
            $where .= " AND recipient_email LIKE ?";
            $params[] = '%' . $em . '%';
        }

        $total = db()->prepare("SELECT COUNT(*) FROM system_logs WHERE {$where}");
        $total->execute($params);
        $totalCount = (int)$total->fetchColumn();

        $stmt = db()->prepare("SELECT * FROM system_logs WHERE {$where} ORDER BY id DESC LIMIT {$lim} OFFSET {$off}");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        jsonOut(['rows' => $rows, 'total' => $totalCount, 'pages' => (int)ceil($totalCount / $lim)]);
    }
    if ($method === 'DELETE') {
        if ($IS_ADMIN) {
            db()->exec("DELETE FROM system_logs");
        } else {
            db()->prepare("DELETE FROM system_logs WHERE user_id = ?")->execute([$UID]);
        }
        jsonOut(['ok' => true, 'message' => 'System logs cleared.']);
    }
    jsonOut(['error' => 'Not found'], 404);
}

// ── SMART MAIL ROUTING (Multi IMAP/SMTP Engine) ────────────────────
if ($res === 'mail-routing') {
    if ($method === 'GET' && $id === 'stats') {
        $where = $IS_ADMIN ? "1=1" : "r.user_id = $UID";
        $thWhere = $IS_ADMIN ? "1=1" : "t.rule_id IN (SELECT id FROM autoreply_rules WHERE user_id = $UID)";

        $totalLeads = (int)db()->query("SELECT COUNT(*) FROM autoreply_threads t WHERE $thWhere")->fetchColumn();
        $firstReplies = (int)db()->query("SELECT COUNT(*) FROM autoreply_threads t WHERE $thWhere AND t.first_reply_sent = 1")->fetchColumn();
        $migratedSec = (int)db()->query("SELECT COUNT(*) FROM autoreply_threads t WHERE $thWhere AND (t.active_mailbox = 'secondary' OR t.conversation_stage = 'MOVED_TO_SECONDARY')")->fetchColumn();
        $fuRunning = (int)db()->query("SELECT COUNT(*) FROM autoreply_threads t WHERE $thWhere AND t.followup_status = 'running'")->fetchColumn();
        $completed = (int)db()->query("SELECT COUNT(*) FROM autoreply_threads t WHERE $thWhere AND (t.status = 'completed' OR t.conversation_stage = 'FOLLOWUP_COMPLETED')")->fetchColumn();
        $activeTh = (int)db()->query("SELECT COUNT(*) FROM autoreply_threads t WHERE $thWhere AND t.status = 'active'")->fetchColumn();

        jsonOut([
            'ok' => true,
            'total_leads' => $totalLeads,
            'first_replies_sent' => $firstReplies,
            'migrated_secondary' => $migratedSec,
            'followups_active' => $fuRunning,
            'completed_conversations' => $completed,
            'active_conversations' => $activeTh,
        ]);
    }

    if ($method === 'GET' && $id === 'threads') {
        $pg = max(1, (int)($_GET['page'] ?? 1));
        $lim = min(100, max(10, (int)($_GET['limit'] ?? 25)));
        $off = ($pg - 1) * $lim;

        $conds = [];
        $params = [];

        if (!$IS_ADMIN) {
            $conds[] = "r.user_id = ?";
            $params[] = $UID;
        }

        if (!empty($_GET['stage'])) {
            $conds[] = "t.conversation_stage = ?";
            $params[] = $_GET['stage'];
        }
        if (!empty($_GET['mailbox'])) {
            $conds[] = "t.active_mailbox = ?";
            $params[] = $_GET['mailbox'];
        }
        if (!empty($_GET['q'])) {
            $conds[] = "(t.from_email LIKE ? OR t.from_name LIKE ? OR t.subject_in LIKE ? OR t.thread_id LIKE ?)";
            $qLike = '%' . trim($_GET['q']) . '%';
            $params[] = $qLike;
            $params[] = $qLike;
            $params[] = $qLike;
            $params[] = $qLike;
        }

        $whereClause = count($conds) > 0 ? "WHERE " . implode(" AND ", $conds) : "";

        $countSql = "SELECT COUNT(*) FROM autoreply_threads t JOIN autoreply_rules r ON r.id = t.rule_id $whereClause";
        $cStmt = db()->prepare($countSql);
        $cStmt->execute($params);
        $total = (int)$cStmt->fetchColumn();

        $sql = "SELECT t.*, r.name as rule_name, r.primary_imap_id, r.secondary_imap_id, r.primary_smtp_id, r.secondary_smtp_id,
                       p_ia.name as primary_imap_name, s_ia.name as secondary_imap_name,
                       p_sp.name as primary_smtp_name, s_sp.name as secondary_smtp_name
                FROM autoreply_threads t
                JOIN autoreply_rules r ON r.id = t.rule_id
                LEFT JOIN imap_accounts p_ia ON p_ia.id = r.primary_imap_id
                LEFT JOIN imap_accounts s_ia ON s_ia.id = r.secondary_imap_id
                LEFT JOIN smtp_providers p_sp ON p_sp.id = r.primary_smtp_id
                LEFT JOIN smtp_providers s_sp ON s_sp.id = r.secondary_smtp_id
                $whereClause
                ORDER BY t.id DESC LIMIT $lim OFFSET $off";
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        jsonOut([
            'ok' => true,
            'rows' => $rows,
            'total' => $total,
            'page' => $pg,
            'pages' => (int)ceil($total / $lim),
        ]);
    }

    if ($method === 'GET' && $id === 'logs') {
        $pg = max(1, (int)($_GET['page'] ?? 1));
        $lim = min(200, max(10, (int)($_GET['limit'] ?? 50)));
        $off = ($pg - 1) * $lim;

        $conds = [];
        $params = [];

        if (!$IS_ADMIN) {
            $conds[] = "l.user_id = ?";
            $params[] = $UID;
        }
        if (!empty($_GET['event_type'])) {
            $conds[] = "l.event_type = ?";
            $params[] = $_GET['event_type'];
        }
        if (!empty($_GET['q'])) {
            $conds[] = "(l.recipient_email LIKE ? OR l.thread_id LIKE ? OR l.details LIKE ?)";
            $qLike = '%' . trim($_GET['q']) . '%';
            $params[] = $qLike;
            $params[] = $qLike;
            $params[] = $qLike;
        }

        $whereClause = count($conds) > 0 ? "WHERE " . implode(" AND ", $conds) : "";

        $countSql = "SELECT COUNT(*) FROM mail_routing_logs l $whereClause";
        $cStmt = db()->prepare($countSql);
        $cStmt->execute($params);
        $total = (int)$cStmt->fetchColumn();

        $sql = "SELECT l.*, r.name as rule_name, u.username as user_name
                FROM mail_routing_logs l
                LEFT JOIN autoreply_rules r ON r.id = l.rule_id
                LEFT JOIN users u ON u.id = l.user_id
                $whereClause
                ORDER BY l.id DESC LIMIT $lim OFFSET $off";
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        jsonOut([
            'ok' => true,
            'rows' => $rows,
            'total' => $total,
            'page' => $pg,
            'pages' => (int)ceil($total / $lim),
        ]);
    }

    if ($method === 'DELETE' && $id === 'logs') {
        if ($IS_ADMIN) {
            db()->exec("DELETE FROM mail_routing_logs");
        } else {
            db()->prepare("DELETE FROM mail_routing_logs WHERE user_id = ?")->execute([$UID]);
        }
        jsonOut(['ok' => true, 'message' => 'Mail routing logs cleared.']);
    }

    if ($method === 'POST' && $id === 'migrate-thread') {
        $b = body();
        $thId = (int)($b['thread_id'] ?? 0);
        $targetMb = ($b['target_mailbox'] ?? 'secondary') === 'primary' ? 'primary' : 'secondary';
        $targetStg = $targetMb === 'secondary' ? 'MOVED_TO_SECONDARY' : 'FIRST_REPLY_SENT';

        $s = db()->prepare("SELECT t.*, r.user_id as r_uid FROM autoreply_threads t JOIN autoreply_rules r ON r.id = t.rule_id WHERE t.id = ?");
        $s->execute([$thId]);
        $th = $s->fetch();
        if (!$th || (!$IS_ADMIN && (int)$th['r_uid'] !== $UID)) {
            jsonOut(['ok' => false, 'message' => 'Thread not found'], 404);
        }

        db()->prepare("UPDATE autoreply_threads SET active_mailbox = ?, conversation_stage = ? WHERE id = ?")
            ->execute([$targetMb, $targetStg, $thId]);

        logMailRoutingEvent((int)$th['r_uid'], (int)$th['rule_id'], $th['thread_id'], $th['from_email'], 'mailbox_migrated', null, null, null, $th['conversation_stage'], $targetStg, 'success', "Manual mailbox migration to " . ucfirst($targetMb) . " by operator");

        jsonOut(['ok' => true, 'message' => "Thread #{$thId} migrated to " . ucfirst($targetMb)]);
    }

    jsonOut(['error' => 'Not found'], 404);
}

jsonOut(['error'=>'Not found'],404);

// Safely encode image_ids — accepts array or JSON string, always stores valid JSON
function safeImageIds($val): string {
    if (empty($val)) return '[]';
    if (is_array($val)) return json_encode(array_values(array_map('intval', $val)));
    if (is_string($val)) {
        $d = json_decode($val, true);
        if (is_array($d)) return json_encode(array_values(array_map('intval', $d)));
    }
    return '[]';
}

function resolveImages(string $html, array $imageIds, array &$inlineImages, string $width='600', string $align='center', string $position='top'): string {
    if (empty($imageIds)) {
        return preg_replace('/\{\{image\}\}/i', '', $html);
    }
    $imageIds = array_values(array_filter(array_map('intval', $imageIds), fn($v) => $v > 0));
    if (empty($imageIds)) {
        return preg_replace('/\{\{image\}\}/i', '', $html);
    }

    $tags = [];
    foreach ($imageIds as $id) {
        $s = db()->prepare('SELECT id, filename, mime FROM images WHERE id=?');
        $s->execute([$id]);
        $img = $s->fetch();
        if (!$img || empty($img['filename'])) continue;

        $filename = $img['filename'];

        // Build candidate paths — use config app_path first (most reliable)
        $cfg = getConfig();
        $candidates = [];
        if (!empty($cfg['app_path'])) $candidates[] = rtrim($cfg['app_path'],'/') . '/uploads/images/' . $filename;
        $candidates[] = __DIR__ . '/uploads/images/' . $filename;
        if (!empty($_SERVER['DOCUMENT_ROOT'])) $candidates[] = rtrim($_SERVER['DOCUMENT_ROOT'],'/') . '/uploads/images/' . $filename;
        if (!empty($_SERVER['SCRIPT_FILENAME'])) $candidates[] = dirname($_SERVER['SCRIPT_FILENAME']) . '/uploads/images/' . $filename;

        $filePath = null;
        foreach ($candidates as $c) {
            if (file_exists($c) && is_readable($c)) { $filePath = $c; break; }
        }
        if (!$filePath) continue;

        $mime = !empty($img['mime']) ? $img['mime'] : '';
        if (empty($mime)) {
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $mimeMap = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png',
                        'gif'=>'image/gif','webp'=>'image/webp','svg'=>'image/svg+xml'];
            $mime = $mimeMap[$ext] ?? (function_exists('mime_content_type') ? mime_content_type($filePath) : 'image/jpeg');
        }

        $cid = 'img' . md5($filename . $id) . '@mailszo.com';
        $inlineImages[] = ['cid' => $cid, 'path' => $filePath, 'mime' => $mime];

        $wStyle = is_numeric($width) ? "width:{$width}px;max-width:100%;" : "width:{$width};max-width:100%;";
        if ($align === 'left')       { $mL = '0';    $mR = 'auto'; }
        elseif ($align === 'right')  { $mL = 'auto'; $mR = '0'; }
        else                         { $mL = 'auto'; $mR = 'auto'; }

        $tags[] = '<img src="cid:' . $cid . '" style="' . $wStyle . 'height:auto;display:block;margin-left:' . $mL . ';margin-right:' . $mR . ';margin-bottom:16px;" alt="" />';
    }

    if (empty($tags)) {
        return preg_replace('/\{\{image\}\}/i', '', $html);
    }
    $allTags = implode("\n", $tags);

    if (preg_match('/\{\{image\}\}/i', $html)) {
        return preg_replace('/\{\{image\}\}/i', $allTags, $html);
    }
    if ($position === 'bottom') return $html . '<div style="margin-top:16px">' . $allTags . '</div>';
    return '<div style="margin-bottom:16px">' . $allTags . '</div>' . $html;
}

function getAllowedSmtpIds(int $userId): array {
    $allowed = [];
    $s = db()->prepare("SELECT id FROM smtp_providers WHERE user_id = ?");
    $s->execute([$userId]);
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $allowed[] = (int)$id;
    }
    $s = db()->prepare("SELECT assigned_smtp_ids FROM users WHERE id = ?");
    $s->execute([$userId]);
    $assigned = $s->fetchColumn();
    if (!empty($assigned)) {
        $d = json_decode($assigned, true);
        if (is_array($d)) {
            foreach ($d as $id) {
                $allowed[] = (int)$id;
            }
        }
    }
    return array_unique($allowed);
}

function getAllowedImapIds(int $userId): array {
    $allowed = [];
    $s = db()->prepare("SELECT id FROM imap_accounts WHERE user_id = ?");
    $s->execute([$userId]);
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $allowed[] = (int)$id;
    }
    $s = db()->prepare("SELECT assigned_imap_ids FROM users WHERE id = ?");
    $s->execute([$userId]);
    $assigned = $s->fetchColumn();
    if (!empty($assigned)) {
        $d = json_decode($assigned, true);
        if (is_array($d)) {
            foreach ($d as $id) {
                $allowed[] = (int)$id;
            }
        }
    }
    return array_unique($allowed);
}

function validateRuleSmtpImap(int $userId, $imapId, $imap2Id, $smtpIds, $step1SmtpIds = null): ?string {
    $chk = db()->prepare("SELECT is_admin FROM users WHERE id = ?");
    $chk->execute([$userId]);
    $isAdminUser = (bool)$chk->fetchColumn();
    if ($isAdminUser) {
        return null;
    }

    $allowedImaps = getAllowedImapIds($userId);
    $allowedSmtps = getAllowedSmtpIds($userId);

    if ($imapId !== null && $imapId !== '' && !in_array((int)$imapId, $allowedImaps, true)) {
        return "Selected IMAP 1 account is not owned by or assigned to the rule owner.";
    }
    if ($imap2Id !== null && $imap2Id !== '' && !in_array((int)$imap2Id, $allowedImaps, true)) {
        return "Selected IMAP 2 account is not owned by or assigned to the rule owner.";
    }

    if ($smtpIds !== null) {
        $ids = is_string($smtpIds) ? json_decode($smtpIds, true) : $smtpIds;
        if (is_array($ids)) {
            foreach ($ids as $sid) {
                if (!in_array((int)$sid, $allowedSmtps, true)) {
                    return "One of the selected SMTP servers is not owned by or assigned to the rule owner.";
                }
            }
        }
    }

    if ($step1SmtpIds !== null) {
        $ids = is_string($step1SmtpIds) ? json_decode($step1SmtpIds, true) : $step1SmtpIds;
        if (is_array($ids)) {
            foreach ($ids as $sid) {
                if (!in_array((int)$sid, $allowedSmtps, true)) {
                    return "One of the selected Step 1 SMTP servers is not owned by or assigned to the rule owner.";
                }
            }
        }
    }

    return null;
}

