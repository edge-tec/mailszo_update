<?php
require_once __DIR__ . '/includes/config.php';
if (!isInstalled()) { header('Location: install.php'); exit; }
startSecureSession();
if (empty($_SESSION['uid'])) {
    checkRememberToken();
}
if (empty($_SESSION['uid'])) {
    // If this is an API request, return JSON error so the JS in the iframe handles it gracefully
    if (isset($_GET['api'])) {
        session_write_close();
        header('Content-Type: application/json');
        echo json_encode(['ok'=>false,'error'=>'session_expired']);
        exit;
    }
    session_write_close();
    // For the page request (iframe load): show a minimal inline message instead of
    // redirecting to index.php — a redirect would render the full login screen inside the iframe.
    // Instead, we show a lightweight prompt that reloads the parent window.
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Session Expired</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#090c12;color:#e2eaf6;font-family:'DM Sans',sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;text-align:center;}
.wrap{padding:32px 24px;max-width:340px;}
.ic{font-size:44px;margin-bottom:16px;}
h2{font-size:16px;font-weight:700;margin-bottom:8px;color:#f59e0b;}
p{font-size:13px;color:#7a92b8;line-height:1.6;margin-bottom:20px;}
button{background:#4ade80;color:#000;border:none;padding:10px 24px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;}
button:hover{background:#6fee8e;}
</style>
</head>
<body>
<div class="wrap">
  <div class="ic">🔐</div>
  <h2>Session Expired</h2>
  <p>Your session has expired or you are not logged in. Please reload the page to sign in again.</p>
  <button onclick="window.top.location.reload()">Reload Page</button>
</div>
<script>
// If we're inside an iframe, automatically reload the top-level window so the
// user is returned to the main login screen without any manual intervention.
try { if(window.self !== window.top){ window.top.location.reload(); } } catch(e){}
</script>
</body>
</html><?php
    exit;
}

// ══════════════════════════════════════════════════════════════════
//  Dashboard API endpoint
// ══════════════════════════════════════════════════════════════════
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $uid     = (int)$_SESSION['uid'];
    $isAdmin = !empty($_SESSION['is_admin']);
    session_write_close();
    $today   = date('Y-m-d');
    $pdo     = db();

    // ── Total auto-replies sent today ──────────────────────────────
    // Uses autoreply_logs as primary source (one row per sent step).
    // Non-admin: INNER JOIN ensures only logs belonging to the user's own rules are counted;
    // eliminates orphan-log over-counting when rules are deleted without FK cascade.
    if ($isAdmin) {
        $s = $pdo->prepare("SELECT COUNT(*) FROM autoreply_logs WHERE status='sent' AND DATE(sent_at)=?");
        $s->execute([$today]);
    } else {
        $s = $pdo->prepare("SELECT COUNT(*) FROM autoreply_logs l INNER JOIN autoreply_rules r ON r.id=l.rule_id WHERE l.status='sent' AND r.user_id=? AND DATE(l.sent_at)=?");
        $s->execute([$uid, $today]);
    }
    $totalAutoReplySent = (int)$s->fetchColumn();

    // Fallback: also count from send_logs (log_source='autoreply') in case autoreply_logs
    // and send_logs are out of sync. Use whichever is higher as the authoritative count.
    if ($isAdmin) {
        $s2 = $pdo->prepare("SELECT COUNT(*) FROM send_logs WHERE log_source='autoreply' AND status='sent' AND DATE(sent_at)=?");
        $s2->execute([$today]);
    } else {
        $s2 = $pdo->prepare("SELECT COUNT(*) FROM send_logs WHERE log_source='autoreply' AND status='sent' AND user_id=? AND DATE(sent_at)=?");
        $s2->execute([$uid, $today]);
    }
    $totalAutoReplySentAlt = (int)$s2->fetchColumn();
    // Use the higher of the two counts — protects against either table being behind
    if ($totalAutoReplySentAlt > $totalAutoReplySent) {
        $totalAutoReplySent = $totalAutoReplySentAlt;
    }

    // ── Completed threads today ────────────────────────────────────
    if ($isAdmin) {
        $s = $pdo->prepare("SELECT t.id, t.from_email, t.from_name, t.reply_count, t.last_sent_at, r.name rule_name FROM autoreply_threads t JOIN autoreply_rules r ON r.id=t.rule_id WHERE t.status='completed' AND DATE(t.last_sent_at)=? ORDER BY t.last_sent_at DESC LIMIT 200");
        $s->execute([$today]);
    } else {
        $s = $pdo->prepare("SELECT t.id, t.from_email, t.from_name, t.reply_count, t.last_sent_at, r.name rule_name FROM autoreply_threads t JOIN autoreply_rules r ON r.id=t.rule_id WHERE t.status='completed' AND r.user_id=? AND DATE(t.last_sent_at)=? ORDER BY t.last_sent_at DESC LIMIT 200");
        $s->execute([$uid, $today]);
    }
    $completedReplies = $s->fetchAll();

    // ── Pending auto-reply threads WITH message content ────────────
    // PENDING LOGIC: Only threads that are truly scheduled to send:
    //   • status = 'active'
    //   • awaiting_reply = 0  (NOT waiting for a human reply — those are not pending sends)
    //   • next_send_at IS NOT NULL  (has a scheduled send time)
    // Threads with awaiting_reply=1 are parked waiting for a user reply and
    // must NOT appear in the Pending chart. Once cron sends a thread it sets
    // status='completed' and next_send_at=NULL, so it drops off automatically.
    if ($isAdmin) {
        $s = $pdo->prepare("
            SELECT t.id, t.from_email, t.from_name, t.current_step,
                   t.next_send_at, t.awaiting_reply, t.created_at,
                   r.name AS rule_name,
                   st.subject   AS msg_subject,
                   st.text_body AS msg_text,
                   st.html_body AS msg_html
            FROM autoreply_threads t
            JOIN autoreply_rules r ON r.id = t.rule_id
            LEFT JOIN autoreply_steps st
                   ON st.rule_id = t.rule_id AND st.step_number = t.current_step
            WHERE t.status = 'active'
              AND (t.awaiting_reply = 0 OR t.awaiting_reply IS NULL)
              AND t.next_send_at IS NOT NULL
            ORDER BY t.next_send_at ASC
            LIMIT 300
        ");
        $s->execute();
    } else {
        $s = $pdo->prepare("
            SELECT t.id, t.from_email, t.from_name, t.current_step,
                   t.next_send_at, t.awaiting_reply, t.created_at,
                   r.name AS rule_name,
                   st.subject   AS msg_subject,
                   st.text_body AS msg_text,
                   st.html_body AS msg_html
            FROM autoreply_threads t
            JOIN autoreply_rules r ON r.id = t.rule_id AND r.user_id = ?
            LEFT JOIN autoreply_steps st
                   ON st.rule_id = t.rule_id AND st.step_number = t.current_step
            WHERE t.status = 'active'
              AND (t.awaiting_reply = 0 OR t.awaiting_reply IS NULL)
              AND t.next_send_at IS NOT NULL
            ORDER BY t.next_send_at ASC
            LIMIT 300
        ");
        $s->execute([$uid]);
    }
    $pendingReplies = $s->fetchAll();

    // ── Follow-up: sent today ──────────────────────────────────────
    if ($isAdmin) {
        $s = $pdo->prepare("SELECT COUNT(*) FROM followup_logs WHERE status='sent' AND DATE(sent_at)=?");
        $s->execute([$today]);
    } else {
        $s = $pdo->prepare("SELECT COUNT(*) FROM followup_logs l JOIN followup_rules r ON r.id=l.rule_id WHERE l.status='sent' AND r.user_id=? AND DATE(l.sent_at)=?");
        $s->execute([$uid, $today]);
    }
    $followupSent = (int)$s->fetchColumn();

    // ── Follow-up: completed today ─────────────────────────────────
    if ($isAdmin) {
        $s = $pdo->prepare("SELECT COUNT(*) FROM followup_contacts WHERE status='completed' AND DATE(last_sent_at)=?");
        $s->execute([$today]);
    } else {
        $s = $pdo->prepare("SELECT COUNT(*) FROM followup_contacts c JOIN followup_rules r ON r.id=c.rule_id WHERE c.status='completed' AND r.user_id=? AND DATE(c.last_sent_at)=?");
        $s->execute([$uid, $today]);
    }
    $followupCompleted = (int)$s->fetchColumn();

    // ── Follow-up: pending contacts WITH scheduled message content ──
    // PENDING LOGIC: Only contacts that are truly scheduled to send next:
    //   • status = 'active'
    //   • next_send_at IS NOT NULL  (has a scheduled send time)
    // Once cron sends a contact's step it sets next_send_at to the NEXT
    // step's time (or NULL + status='completed' on the final step), so
    // sent messages automatically disappear from this list in real time.
    if ($isAdmin) {
        $s = $pdo->prepare("
            SELECT c.id, c.email, c.name AS contact_name, c.current_step,
                   c.next_send_at, c.enrolled_at,
                   r.name AS rule_name,
                   st.subject   AS msg_subject,
                   st.text_body AS msg_text,
                   st.html_body AS msg_html
            FROM followup_contacts c
            JOIN followup_rules r ON r.id = c.rule_id
            LEFT JOIN followup_steps st
                   ON st.rule_id = c.rule_id AND st.step_number = c.current_step
            WHERE c.status = 'active'
              AND c.next_send_at IS NOT NULL
            ORDER BY c.next_send_at ASC
            LIMIT 300
        ");
        $s->execute();
    } else {
        $s = $pdo->prepare("
            SELECT c.id, c.email, c.name AS contact_name, c.current_step,
                   c.next_send_at, c.enrolled_at,
                   r.name AS rule_name,
                   st.subject   AS msg_subject,
                   st.text_body AS msg_text,
                   st.html_body AS msg_html
            FROM followup_contacts c
            JOIN followup_rules r ON r.id = c.rule_id AND r.user_id = ?
            LEFT JOIN followup_steps st
                   ON st.rule_id = c.rule_id AND st.step_number = c.current_step
            WHERE c.status = 'active'
              AND c.next_send_at IS NOT NULL
            ORDER BY c.next_send_at ASC
            LIMIT 300
        ");
        $s->execute([$uid]);
    }
    $followupPending = $s->fetchAll();

    // ── Hourly charts ──────────────────────────────────────────────
    // Auto-reply: per-hour send count from autoreply_logs (primary) with send_logs fallback.
    // Build from autoreply_logs first:
    if ($isAdmin) {
        $s = $pdo->prepare("SELECT HOUR(sent_at) hr, COUNT(*) cnt FROM autoreply_logs WHERE status='sent' AND DATE(sent_at)=? GROUP BY hr ORDER BY hr ASC");
        $s->execute([$today]);
    } else {
        $s = $pdo->prepare("SELECT HOUR(l.sent_at) hr, COUNT(*) cnt FROM autoreply_logs l INNER JOIN autoreply_rules r ON r.id=l.rule_id WHERE l.status='sent' AND r.user_id=? AND DATE(l.sent_at)=? GROUP BY hr ORDER BY hr ASC");
        $s->execute([$uid, $today]);
    }
    $hourlyAR = array_fill(0, 24, 0);
    foreach ($s->fetchAll() as $row) $hourlyAR[(int)$row['hr']] = (int)$row['cnt'];

    // Fallback hourly from send_logs for autoreply — merge in case of gaps:
    if ($isAdmin) {
        $s = $pdo->prepare("SELECT HOUR(sent_at) hr, COUNT(*) cnt FROM send_logs WHERE log_source='autoreply' AND status='sent' AND DATE(sent_at)=? GROUP BY hr ORDER BY hr ASC");
        $s->execute([$today]);
    } else {
        $s = $pdo->prepare("SELECT HOUR(sent_at) hr, COUNT(*) cnt FROM send_logs WHERE log_source='autoreply' AND status='sent' AND user_id=? AND DATE(sent_at)=? GROUP BY hr ORDER BY hr ASC");
        $s->execute([$uid, $today]);
    }
    foreach ($s->fetchAll() as $row) {
        $h = (int)$row['hr'];
        // Use whichever source shows more activity for this hour
        if ((int)$row['cnt'] > $hourlyAR[$h]) $hourlyAR[$h] = (int)$row['cnt'];
    }

    // Follow-up hourly from followup_logs:
    if ($isAdmin) {
        $s = $pdo->prepare("SELECT HOUR(sent_at) hr, COUNT(*) cnt FROM followup_logs WHERE status='sent' AND DATE(sent_at)=? GROUP BY hr ORDER BY hr ASC");
        $s->execute([$today]);
    } else {
        $s = $pdo->prepare("SELECT HOUR(l.sent_at) hr, COUNT(*) cnt FROM followup_logs l JOIN followup_rules r ON r.id=l.rule_id WHERE l.status='sent' AND r.user_id=? AND DATE(l.sent_at)=? GROUP BY hr ORDER BY hr ASC");
        $s->execute([$uid, $today]);
    }
    $hourlyFU = array_fill(0, 24, 0);
    foreach ($s->fetchAll() as $row) $hourlyFU[(int)$row['hr']] = (int)$row['cnt'];

    // Fallback hourly from send_logs for followup:
    if ($isAdmin) {
        $s = $pdo->prepare("SELECT HOUR(sent_at) hr, COUNT(*) cnt FROM send_logs WHERE log_source='followup' AND status='sent' AND DATE(sent_at)=? GROUP BY hr ORDER BY hr ASC");
        $s->execute([$today]);
    } else {
        $s = $pdo->prepare("SELECT HOUR(sent_at) hr, COUNT(*) cnt FROM send_logs WHERE log_source='followup' AND status='sent' AND user_id=? AND DATE(sent_at)=? GROUP BY hr ORDER BY hr ASC");
        $s->execute([$uid, $today]);
    }
    foreach ($s->fetchAll() as $row) {
        $h = (int)$row['hr'];
        if ((int)$row['cnt'] > $hourlyFU[$h]) $hourlyFU[$h] = (int)$row['cnt'];
    }

    // ── Follow-up: hourly completed today (real data) ──────────────
    if ($isAdmin) {
        $s = $pdo->prepare("SELECT HOUR(c.last_sent_at) hr, COUNT(*) cnt FROM followup_contacts c WHERE c.status='completed' AND DATE(c.last_sent_at)=? GROUP BY hr ORDER BY hr ASC");
        $s->execute([$today]);
    } else {
        $s = $pdo->prepare("SELECT HOUR(c.last_sent_at) hr, COUNT(*) cnt FROM followup_contacts c JOIN followup_rules r ON r.id=c.rule_id WHERE c.status='completed' AND r.user_id=? AND DATE(c.last_sent_at)=? GROUP BY hr ORDER BY hr ASC");
        $s->execute([$uid, $today]);
    }
    $hourlyFUCompleted = array_fill(0, 24, 0);
    foreach ($s->fetchAll() as $row) $hourlyFUCompleted[(int)$row['hr']] = (int)$row['cnt'];

    // ── Follow-up: completed today — full list for new report table ──
    if ($isAdmin) {
        $s = $pdo->prepare("SELECT c.email, c.name contact_name, c.last_sent_at completed_at, r.name rule_name FROM followup_contacts c JOIN followup_rules r ON r.id=c.rule_id WHERE c.status='completed' AND DATE(c.last_sent_at)=? ORDER BY c.last_sent_at DESC LIMIT 300");
        $s->execute([$today]);
    } else {
        $s = $pdo->prepare("SELECT c.email, c.name contact_name, c.last_sent_at completed_at, r.name rule_name FROM followup_contacts c JOIN followup_rules r ON r.id=c.rule_id WHERE c.status='completed' AND r.user_id=? AND DATE(c.last_sent_at)=? ORDER BY c.last_sent_at DESC LIMIT 300");
        $s->execute([$uid, $today]);
    }
    $followupCompletedList = $s->fetchAll();

    echo json_encode([
        'ok'                       => true,
        'date'                     => $today,
        'generated_at'             => date('Y-m-d H:i:s'),
        'total_autoreply_sent'     => $totalAutoReplySent,
        'completed_replies'        => $completedReplies,
        'pending_replies'          => $pendingReplies,
        'followup_sent'            => $followupSent,
        'followup_completed'       => $followupCompleted,
        'followup_completed_list'  => $followupCompletedList,
        'followup_pending'         => $followupPending,
        'hourly_autoreply'         => array_values($hourlyAR),
        'hourly_followup'          => array_values($hourlyFU),
        'hourly_followup_completed'=> array_values($hourlyFUCompleted),
    ]);
    exit;
}

$cfg      = getConfig();
$siteName = $cfg['site_name'] ?? 'MailsZo';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Live Dashboard — <?= htmlspecialchars($siteName) ?></title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600;700&family=IBM+Plex+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#04060c; --bg2:#080d18; --bg3:#0c1220; --bg4:#101928; --bg5:#0a1020;
  --border:#131f35; --border2:#1a2840;
  --accent:#00ffc6; --accent-dim:rgba(0,255,198,.1);
  --amber:#f59e0b;  --amber-dim:rgba(245,158,11,.1);
  --blue:#38bdf8;   --blue-dim:rgba(56,189,248,.1);
  --red:#f87171;    --red-dim:rgba(248,113,113,.1);
  --purple:#c084fc; --purple-dim:rgba(192,132,252,.1);
  --orange:#fb923c; --orange-dim:rgba(251,146,60,.1);
  --text:#c8d8f0; --text2:#5a7a9a; --text3:#263852;
  --mono:'IBM Plex Mono',monospace; --sans:'IBM Plex Sans',sans-serif; --r:8px;
}
body{
  font-family:var(--sans);background:var(--bg);color:var(--text);min-height:100vh;overflow-x:hidden;
  background-image:
    radial-gradient(ellipse 900px 600px at 85% 5%,rgba(0,255,198,.025) 0%,transparent 70%),
    radial-gradient(ellipse 600px 500px at 5% 90%,rgba(56,189,248,.025) 0%,transparent 70%);
}

/* ─── Topbar ──────────────────────────────────────────────── */
.topbar{
  position:sticky;top:0;z-index:200;background:rgba(4,6,12,.94);
  border-bottom:1px solid var(--border2);backdrop-filter:blur(14px);
  padding:13px 28px;display:flex;align-items:center;gap:16px;
}
.topbar-logo{font-family:var(--mono);font-size:14px;font-weight:700;display:flex;align-items:center;gap:9px;}
.live-dot{width:9px;height:9px;border-radius:50%;background:var(--accent);box-shadow:0 0 10px var(--accent);animation:pulse 2s ease-in-out infinite;flex-shrink:0;}
@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.4;transform:scale(.75)}}
.topbar-sep{flex:1;}
.topbar-date{font-family:var(--mono);font-size:10px;color:var(--text3);}
.topbar-clock{font-family:var(--mono);font-size:13px;font-weight:700;color:var(--accent);}
.back-btn{display:inline-flex;align-items:center;gap:5px;padding:6px 12px;border-radius:6px;background:var(--bg3);border:1px solid var(--border2);color:var(--text2);font-size:11px;font-family:var(--mono);text-decoration:none;cursor:pointer;transition:all .15s;}
.back-btn:hover{border-color:var(--accent);color:var(--accent);}
.refresh-ring{display:flex;align-items:center;gap:7px;font-size:10px;color:var(--text3);font-family:var(--mono);}
.ring-wrap{position:relative;width:28px;height:28px;}
.ring-svg{transform:rotate(-90deg);width:28px;height:28px;}
.ring-track{stroke:var(--border2);}
.ring-fill{stroke:var(--accent);stroke-dasharray:75.4;stroke-dashoffset:75.4;transition:stroke-dashoffset .5s linear;}
.ring-label{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-family:var(--mono);font-size:8px;font-weight:700;color:var(--accent);}

/* ─── Layout ──────────────────────────────────────────────── */
.page{padding:24px 28px;max-width:1700px;margin:0 auto;}
.sec-label{font-family:var(--mono);font-size:10px;font-weight:700;letter-spacing:.15em;color:var(--text3);text-transform:uppercase;margin-bottom:14px;display:flex;align-items:center;gap:10px;}
.sec-label::after{content:'';flex:1;height:1px;background:var(--border);}
.sec-label .si{font-size:13px;letter-spacing:0;}

/* ─── Stat cards ──────────────────────────────────────────── */
.stats-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(195px,1fr));gap:13px;margin-bottom:26px;}
.stat-card{background:var(--bg2);border:1px solid var(--border2);border-radius:var(--r);padding:20px 22px;position:relative;overflow:hidden;transition:border-color .2s,transform .2s;cursor:default;min-width:0;}
.stat-card:hover{transform:translateY(-2px);}
.stat-card::before{content:'';position:absolute;inset:0;pointer-events:none;background:linear-gradient(135deg,var(--sc-bg,rgba(0,255,198,.04)) 0%,transparent 55%);}
.stat-card::after{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:var(--sc-line,var(--accent));}
.stat-card.amber{--sc-bg:rgba(245,158,11,.04);--sc-line:var(--amber);}
.stat-card.blue{--sc-bg:rgba(56,189,248,.04);--sc-line:var(--blue);}
.stat-card.orange{--sc-bg:rgba(251,146,60,.04);--sc-line:var(--orange);}
.stat-ic{width:38px;height:38px;border-radius:8px;margin-bottom:12px;display:flex;align-items:center;justify-content:center;font-size:18px;background:var(--accent-dim);}
.stat-card.amber .stat-ic{background:var(--amber-dim);}
.stat-card.blue .stat-ic{background:var(--blue-dim);}
.stat-card.orange .stat-ic{background:var(--orange-dim);}
.stat-lbl{font-family:var(--mono);font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--text2);margin-bottom:6px;}
.stat-val{font-family:var(--mono);font-size:36px;font-weight:700;line-height:1;color:var(--accent);transition:all .3s;}
.stat-card.amber .stat-val{color:var(--amber);}
.stat-card.blue .stat-val{color:var(--blue);}
.stat-card.orange .stat-val{color:var(--orange);}
.stat-sub{font-size:10px;color:var(--text3);margin-top:6px;font-family:var(--mono);}

/* ─── Charts ──────────────────────────────────────────────── */
.charts-row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:13px;margin-bottom:26px;}
@media(max-width:1100px){.charts-row{grid-template-columns:1fr 1fr;}}
@media(max-width:700px){.charts-row{grid-template-columns:1fr;}}
.chart-card{background:var(--bg2);border:1px solid var(--border2);border-radius:var(--r);padding:17px;min-width:0;}
.chart-card-hd{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;}
.chart-card-hd h3{font-family:var(--mono);font-size:10px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.1em;}
.chart-badge{font-family:var(--mono);font-size:10px;font-weight:700;padding:2px 9px;border-radius:20px;}
.cb-green{background:rgba(0,255,198,.1);color:var(--accent);border:1px solid rgba(0,255,198,.25);}
.cb-amber{background:rgba(245,158,11,.1);color:var(--amber);border:1px solid rgba(245,158,11,.25);}
.cb-blue{background:rgba(56,189,248,.1);color:var(--blue);border:1px solid rgba(56,189,248,.25);}
.chart-canvas-wrap{position:relative;height:130px;}

.big-chart-card{background:var(--bg2);border:1px solid var(--border2);border-radius:var(--r);padding:18px;margin-bottom:26px;min-width:0;}
.big-chart-hd{display:flex;align-items:center;gap:16px;margin-bottom:14px;}
.big-chart-hd h3{font-family:var(--mono);font-size:11px;font-weight:700;color:var(--text);flex:1;}
.legend-item{display:flex;align-items:center;gap:5px;font-family:var(--mono);font-size:10px;color:var(--text3);}
.legend-dot{width:8px;height:8px;border-radius:50%;}

/* ─── Mini overview tables ────────────────────────────────── */
.table-grid{display:grid;grid-template-columns:1fr 1fr;gap:13px;margin-bottom:26px;}
@media(max-width:900px){.table-grid{grid-template-columns:1fr;}}
.table-card{background:var(--bg2);border:1px solid var(--border2);border-radius:var(--r);overflow:hidden;min-width:0;}
.table-card-hd{padding:13px 17px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:9px;}
.table-card-hd h3{font-family:var(--mono);font-size:12px;font-weight:700;color:var(--text);flex:1;}
.count-pill{font-family:var(--mono);font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;}
.pill-green{background:rgba(0,255,198,.1);color:var(--accent);border:1px solid rgba(0,255,198,.2);}
.pill-blue{background:rgba(56,189,248,.1);color:var(--blue);border:1px solid rgba(56,189,248,.2);}
.mini-tw{overflow-x:auto;max-height:300px;overflow-y:auto;}
.mini-t{width:100%;border-collapse:collapse;font-size:12px;}
.mini-t th{position:sticky;top:0;background:var(--bg3);padding:9px 13px;text-align:left;font-family:var(--mono);font-size:9px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.1em;white-space:nowrap;border-bottom:1px solid var(--border);z-index:5;}
.mini-t td{padding:9px 13px;border-top:1px solid var(--border);vertical-align:middle;color:var(--text2);}
.mini-t tr:hover td{background:rgba(255,255,255,.012);}
.mini-t .er td{text-align:center;color:var(--text3);padding:28px;font-family:var(--mono);font-size:11px;}
.ec{color:var(--text);font-family:var(--mono);font-size:11px;}
.rc{color:var(--text3);font-size:11px;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.sb{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;font-family:var(--mono);font-size:10px;font-weight:700;background:var(--blue-dim);color:var(--blue);border:1px solid rgba(56,189,248,.2);}
.tc{font-family:var(--mono);font-size:10px;color:var(--text3);}
.sdot{display:inline-flex;align-items:center;gap:5px;font-size:10px;font-weight:600;font-family:var(--mono);}
.sdot-g::before,.sdot-a::before{content:'';width:6px;height:6px;border-radius:50%;display:inline-block;}
.sdot-g::before{background:var(--accent);box-shadow:0 0 5px var(--accent);}
.sdot-a::before{background:var(--amber);box-shadow:0 0 5px var(--amber);}

/* ═══════════════════════════════════════════════════════════
   PENDING REPORT CARDS — the two new sections
═══════════════════════════════════════════════════════════ */
.report-card{background:var(--bg2);border:1px solid var(--border2);border-radius:var(--r);overflow:hidden;margin-bottom:22px;min-width:0;}

/* Header strip */
.report-hd{
  padding:15px 20px;border-bottom:2px solid var(--border2);
  display:flex;align-items:center;gap:12px;flex-wrap:wrap;
  background:linear-gradient(90deg,rgba(0,255,198,.03) 0%,transparent 60%);
}
.report-card.fu-card .report-hd{background:linear-gradient(90deg,rgba(251,146,60,.03) 0%,transparent 60%);}
.report-hd-left{display:flex;align-items:center;gap:11px;flex:1;min-width:0;}
.report-hd-icon{font-size:20px;flex-shrink:0;}
.report-hd-title{font-family:var(--mono);font-size:13px;font-weight:700;color:var(--text);}
.report-hd-sub{font-size:11px;color:var(--text3);margin-top:2px;}
.report-count-pill{font-family:var(--mono);font-size:11px;font-weight:700;padding:4px 12px;border-radius:20px;flex-shrink:0;}
.pill-ar{background:rgba(0,255,198,.1);color:var(--accent);border:1px solid rgba(0,255,198,.3);}
.pill-fu{background:rgba(251,146,60,.1);color:var(--orange);border:1px solid rgba(251,146,60,.3);}
.live-badge{display:inline-flex;align-items:center;gap:5px;font-family:var(--mono);font-size:9px;font-weight:700;padding:3px 8px;border-radius:20px;background:rgba(0,255,198,.06);border:1px solid rgba(0,255,198,.18);color:var(--accent);text-transform:uppercase;letter-spacing:.08em;flex-shrink:0;}
.live-badge .ld{width:5px;height:5px;border-radius:50%;background:var(--accent);animation:pulse 1.4s infinite;flex-shrink:0;}

/* Table */
.report-tw{overflow-x:auto;max-height:500px;overflow-y:auto;}
.rt{width:100%;border-collapse:collapse;font-size:12px;}
.rt th{position:sticky;top:0;z-index:10;background:var(--bg3);padding:11px 16px;text-align:left;white-space:nowrap;font-family:var(--mono);font-size:9px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.1em;border-bottom:1px solid var(--border2);}
.rt td{padding:0;border-top:1px solid var(--border);vertical-align:top;}
.rt tr:hover td{background:rgba(255,255,255,.011);}
.rt .er td{text-align:center;color:var(--text3);padding:50px 20px;font-family:var(--mono);font-size:12px;}
.cp{padding:13px 16px;}  /* cell padding wrapper */

/* Email cell */
.email-main{font-family:var(--mono);font-size:12px;color:var(--text);font-weight:600;word-break:break-all;}
.email-name{font-size:10px;color:var(--text3);margin-top:2px;}
.rule-tag{display:inline-block;margin-top:5px;font-size:9px;font-family:var(--mono);padding:2px 7px;border-radius:4px;background:var(--bg4);border:1px solid var(--border2);color:var(--text3);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}

/* Message preview cell */
.msg-subject{font-size:12px;font-weight:600;color:var(--text);margin-bottom:5px;line-height:1.4;}
.msg-body{font-size:11px;color:var(--text2);line-height:1.6;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;max-width:460px;}
.msg-none{font-size:11px;color:var(--text3);font-style:italic;font-family:var(--mono);}

/* Status badges */
.pend-ar{display:inline-flex;align-items:center;gap:5px;font-family:var(--mono);font-size:10px;font-weight:700;padding:4px 11px;border-radius:20px;background:rgba(0,255,198,.08);border:1px solid rgba(0,255,198,.25);color:var(--accent);white-space:nowrap;}
.pend-fu{display:inline-flex;align-items:center;gap:5px;font-family:var(--mono);font-size:10px;font-weight:700;padding:4px 11px;border-radius:20px;background:rgba(251,146,60,.08);border:1px solid rgba(251,146,60,.25);color:var(--orange);white-space:nowrap;}
.pend-aw{display:inline-flex;align-items:center;gap:5px;font-family:var(--mono);font-size:10px;font-weight:700;padding:4px 11px;border-radius:20px;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25);color:var(--amber);white-space:nowrap;}
.sds{width:5px;height:5px;border-radius:50%;background:currentColor;display:inline-block;flex-shrink:0;}

/* Step / ordinal badges */
.step-ar{display:inline-flex;align-items:center;justify-content:center;min-width:30px;height:30px;border-radius:7px;padding:0 7px;font-family:var(--mono);font-size:12px;font-weight:700;background:var(--accent-dim);border:1px solid rgba(0,255,198,.25);color:var(--accent);}
.step-fu{display:inline-flex;align-items:center;justify-content:center;min-width:30px;height:30px;border-radius:7px;padding:0 7px;font-family:var(--mono);font-size:12px;font-weight:700;background:var(--orange-dim);border:1px solid rgba(251,146,60,.25);color:var(--orange);}

/* Time cell */
.time-main{font-family:var(--mono);font-size:11px;color:var(--text2);white-space:nowrap;}
.time-sub{font-family:var(--mono);font-size:9px;color:var(--text3);margin-top:3px;white-space:nowrap;}

.report-foot{border-top:1px solid var(--border);padding:8px 20px;font-family:var(--mono);font-size:9px;color:var(--text3);text-align:right;}

/* ─── Meta bar ────────────────────────────────────────────── */
.reset-bar{background:rgba(0,255,198,.025);border:1px solid rgba(0,255,198,.1);border-radius:var(--r);padding:10px 16px;font-family:var(--mono);font-size:10px;color:var(--text3);display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:20px;}
.reset-bar strong{color:var(--accent);}
.update-bar{text-align:center;font-family:var(--mono);font-size:10px;color:var(--text3);padding:16px;margin-top:4px;}
.update-bar span{color:var(--accent);}

/* ═══════════════════════════════════════════════════════════
   EMAIL LEADS ANALYTICS (ELA) STYLES
═══════════════════════════════════════════════════════════ */
.ela-toolbar{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:18px;padding:12px 16px;background:var(--bg2);border:1px solid var(--border2);border-radius:var(--r);}
.ela-filter-group{display:flex;align-items:center;gap:7px;flex-wrap:wrap;flex:1;}
.ela-filter-label{font-family:var(--mono);font-size:10px;color:var(--text3);white-space:nowrap;}
.ela-btn{font-family:var(--mono);font-size:10px;font-weight:700;padding:5px 12px;border-radius:5px;border:1px solid var(--border2);background:var(--bg3);color:var(--text3);cursor:pointer;transition:all .15s;}
.ela-btn:hover{border-color:var(--accent);color:var(--accent);}
.ela-btn.ela-btn-active{background:var(--accent-dim);border-color:rgba(0,255,198,.4);color:var(--accent);}
.ela-live-badge{display:inline-flex;align-items:center;gap:5px;font-family:var(--mono);font-size:9px;font-weight:700;padding:4px 10px;border-radius:20px;background:rgba(0,255,198,.06);border:1px solid rgba(0,255,198,.2);color:var(--accent);letter-spacing:.1em;flex-shrink:0;}
.eld{width:6px;height:6px;border-radius:50%;background:var(--accent);animation:pulse 1.4s infinite;flex-shrink:0;}

/* Stat cards */
.ela-stats-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin-bottom:20px;}
.ela-stat-card{background:var(--bg2);border:1px solid var(--border2);border-radius:var(--r);padding:18px 20px;position:relative;overflow:hidden;transition:transform .2s,border-color .2s;cursor:default;min-width:0;}
.ela-stat-card:hover{transform:translateY(-2px);}
.ela-stat-card::after{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:var(--ela-line,var(--accent));}
.ela-stat-card::before{content:'';position:absolute;inset:0;pointer-events:none;background:linear-gradient(135deg,var(--ela-bg,rgba(0,255,198,.03)) 0%,transparent 60%);}
.ela-c-green{--ela-line:var(--accent);--ela-bg:rgba(0,255,198,.04);}
.ela-c-blue{--ela-line:var(--blue);--ela-bg:rgba(56,189,248,.04);}
.ela-c-amber{--ela-line:var(--amber);--ela-bg:rgba(245,158,11,.04);}
.ela-c-purple{--ela-line:var(--purple);--ela-bg:rgba(192,132,252,.04);}
.ela-c-orange{--ela-line:var(--orange);--ela-bg:rgba(251,146,60,.04);}
.ela-c-teal{--ela-line:#2dd4bf;--ela-bg:rgba(45,212,191,.04);}
.ela-c-red{--ela-line:var(--red);--ela-bg:rgba(248,113,113,.04);}
.ela-c-indigo{--ela-line:#818cf8;--ela-bg:rgba(129,140,248,.04);}
.ela-stat-icon{font-size:20px;margin-bottom:10px;}

/* Progress bar inside stat cards */
.ela-stat-progress{margin-top:10px;}
.ela-stat-prog-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:3px;}
.ela-stat-prog-lbl{font-family:var(--mono);font-size:8px;color:var(--text3);text-transform:uppercase;letter-spacing:.07em;}
.ela-stat-prog-pct{font-family:var(--mono);font-size:8px;color:var(--ela-line,var(--accent));font-weight:700;}
.ela-prog-bar-wrap{background:var(--bg3);border-radius:3px;height:4px;overflow:hidden;}
.ela-prog-bar{height:100%;border-radius:3px;background:var(--ela-line,var(--accent));transition:width .6s cubic-bezier(.4,0,.2,1);}

/* Divider row between original and new stats */
.ela-stats-divider{display:flex;align-items:center;gap:10px;margin:4px 0 14px;opacity:.5;}
.ela-stats-divider::before,.ela-stats-divider::after{content:'';flex:1;height:1px;background:var(--border2);}
.ela-stats-divider span{font-family:var(--mono);font-size:9px;color:var(--text3);white-space:nowrap;letter-spacing:.08em;text-transform:uppercase;}
.ela-stat-lbl{font-family:var(--mono);font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--text2);margin-bottom:6px;}
.ela-stat-val{font-family:var(--mono);font-size:32px;font-weight:700;line-height:1;color:var(--ela-line,var(--accent));transition:all .3s;}
.ela-stat-sub{font-size:9px;color:var(--text3);margin-top:6px;font-family:var(--mono);}

/* Chart row */
.ela-chart-row{display:flex;gap:13px;margin-bottom:20px;}
@media(max-width:800px){.ela-chart-row{flex-direction:column;}}
.ela-chart-card{background:var(--bg2);border:1px solid var(--border2);border-radius:var(--r);padding:16px;min-width:0;}
.ela-chart-hd{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;}
.ela-chart-hd h3{font-family:var(--mono);font-size:10px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.08em;}
.ela-badge{font-family:var(--mono);font-size:9px;font-weight:700;padding:2px 9px;border-radius:20px;}
.ela-badge-green{background:rgba(0,255,198,.1);color:var(--accent);border:1px solid rgba(0,255,198,.25);}
.ela-badge-blue{background:rgba(56,189,248,.1);color:var(--blue);border:1px solid rgba(56,189,248,.25);}

/* Tables */
.ela-table-card{background:var(--bg2);border:1px solid var(--border2);border-radius:var(--r);overflow:hidden;min-width:0;}
.ela-table-hd{padding:13px 17px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:9px;background:linear-gradient(90deg,rgba(0,255,198,.025) 0%,transparent 70%);}
.ela-table-icon{font-size:16px;}
.ela-table-hd h3{font-family:var(--mono);font-size:11px;font-weight:700;color:var(--text);flex:1;}
.ela-tw{overflow-x:auto;max-height:320px;overflow-y:auto;}
.ela-t{width:100%;border-collapse:collapse;font-size:12px;}
.ela-t th{position:sticky;top:0;background:var(--bg3);padding:9px 13px;text-align:left;font-family:var(--mono);font-size:9px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.1em;border-bottom:1px solid var(--border2);white-space:nowrap;z-index:5;}
.ela-t td{padding:9px 13px;border-top:1px solid var(--border);color:var(--text2);vertical-align:middle;}
.ela-t tr:hover td{background:rgba(255,255,255,.012);}
.ela-t .er td{text-align:center;color:var(--text3);padding:28px;font-family:var(--mono);font-size:11px;}
.ela-lead-bar-wrap{background:var(--bg3);border-radius:4px;height:5px;width:100px;overflow:hidden;display:inline-block;vertical-align:middle;margin-left:8px;}
.ela-lead-bar{height:100%;background:var(--accent);border-radius:4px;transition:width .4s;}
.ela-status-pill{font-family:var(--mono);font-size:9px;padding:2px 8px;border-radius:10px;font-weight:700;}
.ela-status-active{background:rgba(0,255,198,.1);color:var(--accent);border:1px solid rgba(0,255,198,.2);}
.ela-status-disabled{background:rgba(248,113,113,.1);color:var(--red);border:1px solid rgba(248,113,113,.2);}

.ela-footer{text-align:center;font-family:var(--mono);font-size:9px;color:var(--text3);padding:12px;margin-top:4px;}
.ela-footer span{color:var(--accent);}

@media(max-width:600px){.ela-stats-row{grid-template-columns:1fr 1fr;}}
@media(max-width:400px){.ela-stats-row{grid-template-columns:1fr;}}
</style>
</head>
<body>

<!-- ── Topbar ──────────────────────────────────────────────────── -->
<div class="topbar">
  <div class="topbar-logo">
    <div class="live-dot"></div>
    <span>MailsZo</span>
  </div>
  <span style="font-size:11px;color:var(--text3);font-family:var(--mono);">Live Reporting Dashboard</span>
  <div class="topbar-sep"></div>
  <div class="topbar-date" id="topdate">—</div>
  <div class="topbar-clock" id="topclock">—</div>
  <div class="refresh-ring">
    <div class="ring-wrap">
      <svg class="ring-svg" viewBox="0 0 28 28">
        <circle class="ring-track" cx="14" cy="14" r="12" fill="none" stroke-width="2.5"/>
        <circle class="ring-fill" id="ring" cx="14" cy="14" r="12" fill="none" stroke-width="2.5" stroke-linecap="round"/>
      </svg>
      <div class="ring-label" id="ring-sec">15</div>
    </div>
    <span>auto-refresh</span>
  </div>
  <a href="index.php" class="back-btn">← Back</a>
  <?php if (!empty($_SESSION['is_admin'])): ?>
  <button class="back-btn" id="btn-clear-dash-live" onclick="openClearDashConfirm()" style="border-color:rgba(248,113,113,.5);color:#f87171;background:rgba(248,113,113,.08)" title="Clear all today's dashboard statistics">🗑 Clear Dashboard</button>
  <?php endif; ?>
</div>

<!-- ── Clear Dashboard Confirm Modal (admin only) ──────────────── -->
<?php if (!empty($_SESSION['is_admin'])): ?>
<div id="cdm-bg" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.82);z-index:9999;align-items:center;justify-content:center;backdrop-filter:blur(6px);padding:16px">
  <div style="background:#0e1420;border:1px solid rgba(248,113,113,.35);border-radius:14px;width:100%;max-width:460px;animation:mza .2s">
    <div style="padding:16px 20px;border-bottom:2px solid rgba(248,113,113,.3);display:flex;align-items:center;gap:10px">
      <span style="font-size:18px">🗑</span>
      <h3 style="flex:1;font-family:var(--mono);font-size:14px;font-weight:700;color:#f87171">Clear All Dashboard Data</h3>
      <span onclick="closeClearDashConfirm()" style="cursor:pointer;color:#3a4f70;font-size:20px;line-height:1">✕</span>
    </div>
    <div style="padding:20px">
      <div id="cdm-al" style="display:none;padding:10px 14px;border-radius:8px;font-size:12px;margin-bottom:12px;line-height:1.5"></div>
      <div style="background:rgba(248,113,113,.07);border:1px solid rgba(248,113,113,.25);border-radius:9px;padding:14px;margin-bottom:14px;font-size:12px;color:#a0b4cc;line-height:1.9">
        ⚠️ This will <strong style="color:#f1f5fb">permanently clear ALL dashboard data</strong> for ALL users:<br>
        • Inbound email leads received today<br>
        • Send logs, auto-reply logs, follow-up logs (today)<br>
        • All threads/contacts reset to active at step 1<br>
        • IMAP read counters &amp; UIDs reset to 0<br>
        • Campaign sent/failed counters reset to 0<br>
        <span style="color:#4ade80">✅ Campaigns, rules, users and SMTP servers are NOT deleted.</span>
      </div>
      <div style="margin-bottom:16px">
        <label style="display:block;font-size:11px;font-weight:700;color:#7a92b8;text-transform:uppercase;letter-spacing:.07em;margin-bottom:5px">Type <span style="color:#f87171;font-family:var(--mono)">CLEAR</span> to confirm</label>
        <input id="cdm-inp" type="text" placeholder="Type CLEAR here…" autocomplete="off" spellcheck="false"
          oninput="onCdmInput()"
          style="width:100%;background:#131c2e;border:1px solid #1a2540;border-radius:7px;padding:9px 12px;color:#e2eaf6;font-family:var(--mono);font-size:13px;outline:none;transition:border-color .2s"
          onfocus="this.style.borderColor='#f87171'" onblur="this.style.borderColor='#1a2540'">
      </div>
    </div>
    <div style="padding:14px 20px;border-top:1px solid #1a2540;display:flex;justify-content:flex-end;gap:8px">
      <button onclick="closeClearDashConfirm()" style="display:inline-flex;align-items:center;padding:7px 14px;border-radius:7px;border:1px solid #1f2e4a;background:#131c2e;color:#7a92b8;font-size:12px;font-weight:600;cursor:pointer">Cancel</button>
      <button id="cdm-btn" onclick="doClearDashboard()" disabled style="display:inline-flex;align-items:center;gap:5px;padding:7px 16px;border-radius:7px;border:1px solid rgba(248,113,113,.3);background:rgba(248,113,113,.12);color:#f87171;font-size:12px;font-weight:700;cursor:not-allowed;opacity:.4;transition:all .15s">🗑 Confirm Clear</button>
    </div>
  </div>
</div>
<style>
@keyframes mza{from{transform:scale(.93);opacity:0}to{transform:scale(1);opacity:1}}
</style>
<script>
function openClearDashConfirm(){
  const bg=document.getElementById('cdm-bg');
  bg.style.display='flex';
  const inp=document.getElementById('cdm-inp');
  inp.value='';
  const btn=document.getElementById('cdm-btn');
  btn.disabled=true; btn.style.opacity='.4'; btn.style.cursor='not-allowed';
  btn.innerHTML='🗑 Confirm Clear';
  const al=document.getElementById('cdm-al'); al.style.display='none';
  setTimeout(()=>inp.focus(),100);
}
function closeClearDashConfirm(){
  document.getElementById('cdm-bg').style.display='none';
}
function onCdmInput(){
  const val=(document.getElementById('cdm-inp').value||'').trim().toUpperCase();
  const btn=document.getElementById('cdm-btn');
  const ok=val==='CLEAR';
  btn.disabled=!ok; btn.style.opacity=ok?'1':'.4'; btn.style.cursor=ok?'pointer':'not-allowed';
}
async function doClearDashboard(){
  const val=(document.getElementById('cdm-inp').value||'').trim().toUpperCase();
  if(val!=='CLEAR') return;
  const btn=document.getElementById('cdm-btn');
  btn.disabled=true; btn.innerHTML='<span style="display:inline-block;width:11px;height:11px;border:2px solid rgba(248,113,113,.3);border-top-color:#f87171;border-radius:50%;animation:spin .6s linear infinite"></span> Clearing…';
  const al=document.getElementById('cdm-al');
  try{
    const res=await fetch('api.php?r=dashboard/clear',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({})});
    const d=await res.json();
    if(d?.ok){
      closeClearDashConfirm();
      // Show a persistent banner, then hard-reload the page so ALL panels
      // (Email Leads Analytics, charts, pipeline cards) reflect the cleared DB.
      const bann=document.createElement('div');
      bann.style.cssText='position:fixed;top:16px;left:50%;transform:translateX(-50%);z-index:99999;background:rgba(4,6,12,.97);border:1px solid rgba(0,255,198,.4);border-radius:9px;padding:12px 22px;font-family:var(--mono);font-size:12px;color:var(--accent);box-shadow:0 4px 24px rgba(0,0,0,.6);white-space:nowrap;';
      bann.textContent='✅ Dashboard cleared — reloading…';
      document.body.appendChild(bann);
      // Hard reload after 1.2 s so the banner is briefly visible
      setTimeout(()=>{ window.location.reload(); }, 1200);
    } else {
      al.style.display='block'; al.style.background='rgba(248,113,113,.07)'; al.style.border='1px solid rgba(248,113,113,.25)'; al.style.color='#f87171';
      al.textContent='❌ '+(d?.message||d?.error||'Unknown error');
      btn.disabled=false; btn.style.opacity='1'; btn.style.cursor='pointer'; btn.innerHTML='🗑 Confirm Clear';
    }
  }catch(e){
    al.style.display='block'; al.style.background='rgba(248,113,113,.07)'; al.style.border='1px solid rgba(248,113,113,.25)'; al.style.color='#f87171';
    al.textContent='❌ Network error: '+e.message;
    btn.disabled=false; btn.style.opacity='1'; btn.style.cursor='pointer'; btn.innerHTML='🗑 Confirm Clear';
  }
}
</script>
<?php endif; ?>

<!-- ── Page ───────────────────────────────────────────────────── -->
<div class="page">

  <!-- ═══════════════════════════════════════════════════════════════
       SECTION 1 — EMAIL LEADS ANALYTICS
  ════════════════════════════════════════════════════════════════ -->
  <div class="sec-label" style="margin-top:8px;"><span class="si">📥</span> Email Leads Analytics</div>

  <!-- Filter Toolbar -->
  <div class="ela-toolbar">
    <div class="ela-filter-group">
      <span class="ela-filter-label">📅 Period:</span>
      <button class="ela-btn ela-btn-active" data-filter="today"    onclick="elaSetFilter('today')">Today</button>
      <button class="ela-btn" data-filter="yesterday" onclick="elaSetFilter('yesterday')">Yesterday</button>
      <button class="ela-btn" data-filter="last7"     onclick="elaSetFilter('last7')">Last 7 Days</button>
      <button class="ela-btn" data-filter="last30"    onclick="elaSetFilter('last30')">Last 30 Days</button>
      <button class="ela-btn" data-filter="alltime"   onclick="elaSetFilter('alltime')">All Time</button>
    </div>
    <div class="ela-live-badge">
      <span class="eld"></span> LIVE
    </div>
  </div>

  <!-- Summary Stat Cards -->
  <div class="ela-stats-row">
    <div class="ela-stat-card ela-c-green">
      <div class="ela-stat-icon">📥</div>
      <div class="ela-stat-lbl">Total Leads (All Time)</div>
      <div class="ela-stat-val" id="ela-total-alltime">—</div>
      <div class="ela-stat-sub" id="ela-trend-sub">Loading…</div>
    </div>
    <div class="ela-stat-card ela-c-blue">
      <div class="ela-stat-icon">📆</div>
      <div class="ela-stat-lbl">Today's Leads</div>
      <div class="ela-stat-val" id="ela-total-today">—</div>
      <div class="ela-stat-sub" id="ela-growth-sub">vs yesterday</div>
    </div>
    <div class="ela-stat-card ela-c-amber">
      <div class="ela-stat-icon">📅</div>
      <div class="ela-stat-lbl">This Week's Leads</div>
      <div class="ela-stat-val" id="ela-total-week">—</div>
      <div class="ela-stat-sub">Mon – Today</div>
    </div>
    <div class="ela-stat-card ela-c-purple">
      <div class="ela-stat-icon">🗓️</div>
      <div class="ela-stat-lbl">This Month's Leads</div>
      <div class="ela-stat-val" id="ela-total-month">—</div>
      <div class="ela-stat-sub">1st – Today</div>
    </div>
    <div class="ela-stat-card ela-c-orange">
      <div class="ela-stat-icon">🖥️</div>
      <div class="ela-stat-lbl">Active Servers</div>
      <div class="ela-stat-val" id="ela-active-servers">—</div>
      <div class="ela-stat-sub">Receiving leads</div>
    </div>
    <div class="ela-stat-card ela-c-teal">
      <div class="ela-stat-icon">🔍</div>
      <div class="ela-stat-lbl" id="ela-filtered-lbl">Filtered Period</div>
      <div class="ela-stat-val" id="ela-total-filtered">—</div>
      <div class="ela-stat-sub" id="ela-filtered-sub">Selected range</div>
    </div>
  </div>

  <!-- Pipeline Status Stats Row -->
  <div class="ela-stats-divider"><span>⚡ Lead Pipeline Status</span></div>
  <div class="ela-stats-row" id="ela-pipeline-row">
    <!-- Total Pending Leads -->
    <div class="ela-stat-card ela-c-amber">
      <div class="ela-stat-icon">⏳</div>
      <div class="ela-stat-lbl">Total Pending Leads</div>
      <div class="ela-stat-val" id="ela-pending-total">—</div>
      <div class="ela-stat-sub">Awaiting any action</div>
      <div class="ela-stat-progress">
        <div class="ela-stat-prog-row">
          <span class="ela-stat-prog-lbl">% of all leads</span>
          <span class="ela-stat-prog-pct" id="ela-pending-pct">—</span>
        </div>
        <div class="ela-prog-bar-wrap">
          <div class="ela-prog-bar" id="ela-pending-bar" style="width:0%"></div>
        </div>
      </div>
    </div>
    <!-- Auto Reply Pending -->
    <div class="ela-stat-card ela-c-blue">
      <div class="ela-stat-icon">📨</div>
      <div class="ela-stat-lbl">Auto Reply Pending</div>
      <div class="ela-stat-val" id="ela-reply-pending">—</div>
      <div class="ela-stat-sub">Queued for auto-reply</div>
      <div class="ela-stat-progress">
        <div class="ela-stat-prog-row">
          <span class="ela-stat-prog-lbl">% of pending</span>
          <span class="ela-stat-prog-pct" id="ela-reply-pct">—</span>
        </div>
        <div class="ela-prog-bar-wrap">
          <div class="ela-prog-bar" id="ela-reply-bar" style="width:0%"></div>
        </div>
      </div>
    </div>
    <!-- Follow-up Pending -->
    <div class="ela-stat-card ela-c-purple">
      <div class="ela-stat-icon">🔄</div>
      <div class="ela-stat-lbl">Follow-up Pending</div>
      <div class="ela-stat-val" id="ela-followup-pending">—</div>
      <div class="ela-stat-sub">Queued for follow-up</div>
      <div class="ela-stat-progress">
        <div class="ela-stat-prog-row">
          <span class="ela-stat-prog-lbl">% of pending</span>
          <span class="ela-stat-prog-pct" id="ela-followup-pct">—</span>
        </div>
        <div class="ela-prog-bar-wrap">
          <div class="ela-prog-bar" id="ela-followup-bar" style="width:0%"></div>
        </div>
      </div>
    </div>
    <!-- Total Processed Leads -->
    <div class="ela-stat-card ela-c-green">
      <div class="ela-stat-icon">✅</div>
      <div class="ela-stat-lbl">Total Processed Leads</div>
      <div class="ela-stat-val" id="ela-processed-total">—</div>
      <div class="ela-stat-sub">Replied or followed up</div>
      <div class="ela-stat-progress">
        <div class="ela-stat-prog-row">
          <span class="ela-stat-prog-lbl">completion rate</span>
          <span class="ela-stat-prog-pct" id="ela-processed-pct">—</span>
        </div>
        <div class="ela-prog-bar-wrap">
          <div class="ela-prog-bar" id="ela-processed-bar" style="width:0%"></div>
        </div>
      </div>
    </div>
    <!-- Today's Leads Count (realtime live counter) -->
    <div class="ela-stat-card ela-c-indigo">
      <div class="ela-stat-icon">📅</div>
      <div class="ela-stat-lbl">Today's Leads Count</div>
      <div class="ela-stat-val" id="ela-today-live">—</div>
      <div class="ela-stat-sub" id="ela-today-live-sub">Live · updates every 15s</div>
      <div class="ela-stat-progress">
        <div class="ela-stat-prog-row">
          <span class="ela-stat-prog-lbl">vs yesterday</span>
          <span class="ela-stat-prog-pct" id="ela-today-growth-pct">—</span>
        </div>
        <div class="ela-prog-bar-wrap">
          <div class="ela-prog-bar" id="ela-today-bar" style="width:0%"></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Chart Row -->
  <div class="ela-chart-row">
    <div class="ela-chart-card" style="flex:2">
      <div class="ela-chart-hd">
        <h3>📊 Lead Volume</h3>
        <span class="ela-badge ela-badge-green" id="ela-chart-label">Today (Hourly)</span>
      </div>
      <div style="position:relative;height:200px;">
        <canvas id="elaChartMain"></canvas>
      </div>
    </div>
    <div class="ela-chart-card" style="flex:1">
      <div class="ela-chart-hd">
        <h3>📈 30-Day Trend</h3>
        <span class="ela-badge ela-badge-blue">Sparkline</span>
      </div>
      <div style="position:relative;height:200px;">
        <canvas id="elaChartTrend"></canvas>
      </div>
    </div>
  </div>

  <!-- Server-wise Table -->
  <div class="ela-table-card" style="margin-bottom:16px;">
    <div class="ela-table-hd">
      <span class="ela-table-icon">🖥️</span>
      <h3>Server-wise Lead Statistics</h3>
      <span class="count-pill pill-green" id="ela-server-count">—</span>
    </div>
    <div class="ela-tw">
      <table class="ela-t" id="ela-server-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Server Name</th>
            <th>Host / Username</th>
            <th id="ela-th-owner" style="display:none">Owner</th>
            <th>Status</th>
            <th>Leads (Period)</th>
          </tr>
        </thead>
        <tbody id="ela-server-tbody">
          <tr class="er"><td colspan="6">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- User-wise Table (Admin only) -->
  <div class="ela-table-card ela-admin-only" id="ela-user-table-card" style="display:none;margin-bottom:16px;">
    <div class="ela-table-hd">
      <span class="ela-table-icon">👥</span>
      <h3>User-wise Lead Statistics</h3>
      <span class="count-pill pill-blue" id="ela-user-count">—</span>
    </div>
    <div class="ela-tw">
      <table class="ela-t" id="ela-user-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Username</th>
            <th>Servers</th>
            <th>Leads (Period)</th>
          </tr>
        </thead>
        <tbody id="ela-user-tbody">
          <tr class="er"><td colspan="4">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="ela-footer">
    Email Leads Analytics · Auto-refreshes every 30 s · Last updated: <span id="ela-last-updated">—</span>
  </div>

</div><!-- /.page -->

  <!-- ═══════════════════════════════════════════════════════════════
       SECTION 2 — AUTO-REPLY STATISTICS
  ════════════════════════════════════════════════════════════════ -->
  <div class="reset-bar">
    📅 Report date: <strong id="report-date">—</strong>
    &nbsp;·&nbsp; Resets at midnight &nbsp;·&nbsp; Next reset: <strong id="next-reset">—</strong>
    &nbsp;·&nbsp; Refreshed: <span id="last-updated" style="color:var(--text2)">—</span>
  </div>


  <div class="sec-label" style="margin-top:20px;"><span class="si">🔁</span> Auto-Reply Statistics</div>
  <div class="stats-row">
    <div class="stat-card">
      <div class="stat-ic">✉️</div>
      <div class="stat-lbl">Total Sent Today</div>
      <div class="stat-val" id="stat-sent">—</div>
      <div class="stat-sub">Auto-replies dispatched</div>
    </div>
    <div class="stat-card blue">
      <div class="stat-ic">⏳</div>
      <div class="stat-lbl">Pending Threads</div>
      <div class="stat-val" id="stat-pending">—</div>
      <div class="stat-sub">Unsent scheduled sends</div>
    </div>
    <div class="stat-card amber">
      <div class="stat-ic">✅</div>
      <div class="stat-lbl">Completed Today</div>
      <div class="stat-val" id="stat-completed">—</div>
      <div class="stat-sub">Full sequences done</div>
    </div>
  </div>


  <div class="table-grid">
    <div class="table-card">
      <div class="table-card-hd">
        <h3>✅ Completed Replies Today</h3>
        <span class="count-pill pill-green" id="cnt-completed">0</span>
      </div>
      <div class="mini-tw">
        <table class="mini-t" id="tbl-completed">
          <thead><tr><th>Email</th><th>Rule</th><th>Steps</th><th>Done At</th></tr></thead>
          <tbody><tr class="er"><td colspan="4">Loading…</td></tr></tbody>
        </table>
      </div>
    </div>
    <div class="table-card">
      <div class="table-card-hd">
        <h3>⏳ Pending Scheduled Sends</h3>
        <span class="count-pill pill-blue" id="cnt-pending">0</span>
      </div>
      <div class="mini-tw">
        <table class="mini-t" id="tbl-pending-mini">
          <thead><tr><th>Email</th><th>Rule</th><th>Step</th><th>Status</th><th>Next Send</th></tr></thead>
          <tbody><tr class="er"><td colspan="5">Loading…</td></tr></tbody>
        </table>
      </div>
    </div>
  </div>


  <div class="sec-label"><span class="si">📨</span> Auto-Reply Pending Report</div>
  <div class="report-card">
    <div class="report-hd">
      <div class="report-hd-left">
        <div class="report-hd-icon">🔁</div>
        <div>
          <div class="report-hd-title">Auto-Reply Pending Queue</div>
          <div class="report-hd-sub">Unsent scheduled sends only — excludes "Waiting for Reply" threads — updates every 15 seconds</div>
        </div>
      </div>
      <span class="report-count-pill pill-ar" id="ar-pending-count">0 pending</span>
      <span class="live-badge"><span class="ld"></span>Live</span>
    </div>
    <div class="report-tw">
      <table class="rt" id="tbl-ar-pending">
        <thead>
          <tr>
            <th style="min-width:210px">Email Address</th>
            <th style="min-width:380px">Pending Message Content</th>
            <th style="min-width:90px">Step #</th>
            <th style="min-width:130px">Status</th>
            <th style="min-width:160px">Queue Time / Next Send</th>
          </tr>
        </thead>
        <tbody><tr class="er"><td colspan="5">Loading data…</td></tr></tbody>
      </table>
    </div>
    <div class="report-foot">Max 300 records shown &nbsp;·&nbsp; Auto-refreshes every 15 s</div>
  </div>


  <!-- ═══════════════════════════════════════════════════════════════
       SECTION 3 — FOLLOW-UP STATISTICS
  ════════════════════════════════════════════════════════════════ -->
  <div class="sec-label" style="margin-top:28px;"><span class="si">📬</span> Follow-Up Statistics</div>
  <div class="charts-row">
    <div class="chart-card">
      <div class="chart-card-hd"><h3>Follow-ups Sent</h3><span class="chart-badge cb-green" id="fu-sent-badge">0</span></div>
      <div class="chart-canvas-wrap"><canvas id="chart-fu-sent"></canvas></div>
    </div>
    <div class="chart-card">
      <div class="chart-card-hd"><h3>Sequences Completed</h3><span class="chart-badge cb-amber" id="fu-completed-badge">0</span></div>
      <div class="chart-canvas-wrap"><canvas id="chart-fu-completed"></canvas></div>
    </div>
    <div class="chart-card">
      <div class="chart-card-hd"><h3>Contacts Pending</h3><span class="chart-badge cb-blue" id="fu-pending-badge">0</span></div>
      <div class="chart-canvas-wrap"><canvas id="chart-fu-pending"></canvas></div>
    </div>
  </div>

  <div class="big-chart-card">
    <div class="big-chart-hd">
      <h3>Hourly Activity — Today</h3>
      <div class="legend-item"><div class="legend-dot" style="background:var(--accent)"></div>Auto-Reply</div>
      <div class="legend-item"><div class="legend-dot" style="background:var(--blue)"></div>Follow-up</div>
    </div>
    <div style="position:relative;height:170px;"><canvas id="chart-hourly"></canvas></div>
  </div>


  <div class="sec-label"><span class="si">✅</span> Completed Follow-Up Today</div>
  <div class="report-card fu-card">
    <div class="report-hd">
      <div class="report-hd-left">
        <div class="report-hd-icon">🏁</div>
        <div>
          <div class="report-hd-title">Completed Follow-Up Sequences — Today</div>
          <div class="report-hd-sub">All contacts whose full follow-up sequence finished today — updates every 15 seconds</div>
        </div>
      </div>
      <span class="report-count-pill pill-fu" id="fu-completed-count">0 completed</span>
      <span class="live-badge"><span class="ld"></span>Live</span>
    </div>
    <div class="report-tw">
      <table class="rt" id="tbl-fu-completed">
        <thead>
          <tr>
            <th style="min-width:240px">Email</th>
            <th style="min-width:260px">Rule</th>
            <th style="min-width:180px">Followup Done At</th>
          </tr>
        </thead>
        <tbody><tr class="er"><td colspan="3">Loading data…</td></tr></tbody>
      </table>
    </div>
    <div class="report-foot">Max 300 records shown &nbsp;·&nbsp; Auto-refreshes every 15 s</div>
  </div>

  <div class="update-bar">

  <div class="sec-label"><span class="si">📅</span> Follow-Up Pending Report</div>
  <div class="report-card fu-card">
    <div class="report-hd">
      <div class="report-hd-left">
        <div class="report-hd-icon">📬</div>
        <div>
          <div class="report-hd-title">Follow-Up Pending Queue</div>
          <div class="report-hd-sub">Unsent scheduled follow-ups only — removed automatically once sent — updates every 15 seconds</div>
        </div>
      </div>
      <span class="report-count-pill pill-fu" id="fu-pending-count">0 pending</span>
      <span class="live-badge"><span class="ld"></span>Live</span>
    </div>
    <div class="report-tw">
      <table class="rt" id="tbl-fu-pending">
        <thead>
          <tr>
            <th style="min-width:210px">Email Address</th>
            <th style="min-width:380px">Follow-Up Message Content</th>
            <th style="min-width:110px">Follow-Up #</th>
            <th style="min-width:120px">Status</th>
            <th style="min-width:160px">Scheduled Time</th>
          </tr>
        </thead>
        <tbody><tr class="er"><td colspan="5">Loading data…</td></tr></tbody>
      </table>
    </div>
    <div class="report-foot">Max 300 records shown &nbsp;·&nbsp; Auto-refreshes every 15 s</div>
  </div>


  <!-- ═══════════════════════════════════════════════════════════════
       SECTION 4 — LIVE REPORTING DASHBOARD
  ════════════════════════════════════════════════════════════════ -->
  <div class="sec-label" style="margin-top:28px;"><span class="si">⚡</span> Live Reporting Dashboard</div>

  <!-- Live summary bar -->
  <div class="lrd-meta-bar">
    <span class="live-badge"><span class="ld"></span>Live</span>
    <span>Last updated: <strong id="last-updated2">—</strong></span>
    <span class="lrd-dot">·</span>
    <span>Stats reset daily at midnight</span>
    <span class="lrd-dot">·</span>
    <span>Next reset: <strong id="next-reset2">—</strong></span>
    <span class="lrd-spacer"></span>
    <span class="lrd-refresh-note">⟳ Auto-refreshes every 15 s</span>
  </div>

  <!-- ── KPI Summary Row ────────────────────────────────────────── -->
  <div class="lrd-kpi-row">

    <div class="lrd-kpi lrd-kpi-green">
      <div class="lrd-kpi-icon">✉️</div>
      <div class="lrd-kpi-body">
        <div class="lrd-kpi-lbl">Auto-Replies Sent</div>
        <div class="lrd-kpi-val" id="lrd-ar-sent">—</div>
        <div class="lrd-kpi-sub">Total dispatched today</div>
      </div>
      <div class="lrd-kpi-spark"><canvas id="lrd-spark-ar-sent"></canvas></div>
    </div>

    <div class="lrd-kpi lrd-kpi-amber">
      <div class="lrd-kpi-icon">✅</div>
      <div class="lrd-kpi-body">
        <div class="lrd-kpi-lbl">AR Completed</div>
        <div class="lrd-kpi-val lrd-val-amber" id="lrd-ar-completed">—</div>
        <div class="lrd-kpi-sub">Full sequences done</div>
      </div>
      <div class="lrd-kpi-spark"><canvas id="lrd-spark-ar-completed"></canvas></div>
    </div>

    <div class="lrd-kpi lrd-kpi-blue">
      <div class="lrd-kpi-icon">⏳</div>
      <div class="lrd-kpi-body">
        <div class="lrd-kpi-lbl">AR Pending</div>
        <div class="lrd-kpi-val lrd-val-blue" id="lrd-ar-pending">—</div>
        <div class="lrd-kpi-sub">Unsent scheduled sends</div>
      </div>
      <div class="lrd-kpi-spark"><canvas id="lrd-spark-ar-pending"></canvas></div>
    </div>

    <div class="lrd-kpi lrd-kpi-orange">
      <div class="lrd-kpi-icon">📬</div>
      <div class="lrd-kpi-body">
        <div class="lrd-kpi-lbl">Follow-ups Sent</div>
        <div class="lrd-kpi-val lrd-val-orange" id="lrd-fu-sent">—</div>
        <div class="lrd-kpi-sub">Total sent today</div>
      </div>
      <div class="lrd-kpi-spark"><canvas id="lrd-spark-fu-sent"></canvas></div>
    </div>

    <div class="lrd-kpi lrd-kpi-purple">
      <div class="lrd-kpi-icon">🏁</div>
      <div class="lrd-kpi-body">
        <div class="lrd-kpi-lbl">FU Completed</div>
        <div class="lrd-kpi-val lrd-val-purple" id="lrd-fu-completed">—</div>
        <div class="lrd-kpi-sub">Sequences finished</div>
      </div>
      <div class="lrd-kpi-spark"><canvas id="lrd-spark-fu-completed"></canvas></div>
    </div>

    <div class="lrd-kpi lrd-kpi-teal">
      <div class="lrd-kpi-icon">🔄</div>
      <div class="lrd-kpi-body">
        <div class="lrd-kpi-lbl">FU Pending</div>
        <div class="lrd-kpi-val lrd-val-teal" id="lrd-fu-pending">—</div>
        <div class="lrd-kpi-sub">Contacts in queue</div>
      </div>
      <div class="lrd-kpi-spark"><canvas id="lrd-spark-fu-pending"></canvas></div>
    </div>

  </div><!-- /.lrd-kpi-row -->

  <!-- ── Activity + Ratio Row ──────────────────────────────────── -->
  <div class="lrd-mid-row">

    <!-- Combined hourly activity chart (larger) -->
    <div class="lrd-chart-card lrd-chart-wide">
      <div class="lrd-chart-hd">
        <h3>⏱ Hourly Activity Today</h3>
        <div class="lrd-legend">
          <span class="lrd-leg-dot" style="background:var(--accent)"></span><span>Auto-Reply</span>
          <span class="lrd-leg-dot" style="background:var(--blue)"></span><span>Follow-up</span>
        </div>
        <span class="live-badge" style="margin-left:auto"><span class="ld"></span>Live</span>
      </div>
      <div style="position:relative;height:180px;"><canvas id="lrd-chart-hourly"></canvas></div>
    </div>

    <!-- Completion ratio gauges -->
    <div class="lrd-ratio-card">
      <div class="lrd-chart-hd"><h3>📊 Today's Ratios</h3></div>

      <div class="lrd-ratio-item">
        <div class="lrd-ratio-row">
          <span class="lrd-ratio-lbl">AR Completion Rate</span>
          <span class="lrd-ratio-pct lrd-val-amber" id="lrd-ratio-ar">—</span>
        </div>
        <div class="lrd-bar-wrap"><div class="lrd-bar lrd-bar-amber" id="lrd-bar-ar" style="width:0%"></div></div>
        <div class="lrd-ratio-sub"><span id="lrd-ratio-ar-sub">— completed of — sent</span></div>
      </div>

      <div class="lrd-ratio-item" style="margin-top:18px;">
        <div class="lrd-ratio-row">
          <span class="lrd-ratio-lbl">FU Completion Rate</span>
          <span class="lrd-ratio-pct lrd-val-purple" id="lrd-ratio-fu">—</span>
        </div>
        <div class="lrd-bar-wrap"><div class="lrd-bar lrd-bar-purple" id="lrd-bar-fu" style="width:0%"></div></div>
        <div class="lrd-ratio-sub"><span id="lrd-ratio-fu-sub">— completed of — sent</span></div>
      </div>

      <div class="lrd-ratio-item" style="margin-top:18px;">
        <div class="lrd-ratio-row">
          <span class="lrd-ratio-lbl">Total Emails Today</span>
          <span class="lrd-ratio-pct lrd-val-green" id="lrd-total-emails">—</span>
        </div>
        <div class="lrd-bar-wrap"><div class="lrd-bar lrd-bar-green" id="lrd-bar-total" style="width:100%"></div></div>
        <div class="lrd-ratio-sub"><span id="lrd-ratio-total-sub">Auto-replies + Follow-ups</span></div>
      </div>
    </div>

  </div><!-- /.lrd-mid-row -->

  <!-- ── Activity Feed Table ────────────────────────────────────── -->
  <div class="lrd-feed-card">
    <div class="report-hd" style="background:linear-gradient(90deg,rgba(0,255,198,.03) 0%,transparent 60%);">
      <div class="report-hd-left">
        <div class="report-hd-icon">⚡</div>
        <div>
          <div class="report-hd-title">Live Activity Feed — Recent Completions</div>
          <div class="report-hd-sub">Latest completed auto-reply &amp; follow-up threads — updates every 15 seconds</div>
        </div>
      </div>
      <span class="report-count-pill pill-ar" id="lrd-feed-count">0 entries</span>
      <span class="live-badge"><span class="ld"></span>Live</span>
    </div>
    <div class="report-tw">
      <table class="rt" id="lrd-tbl-feed">
        <thead>
          <tr>
            <th style="min-width:50px">Type</th>
            <th style="min-width:210px">Email Address</th>
            <th style="min-width:220px">Rule / Sequence</th>
            <th style="min-width:90px">Steps</th>
            <th style="min-width:160px">Completed At</th>
          </tr>
        </thead>
        <tbody><tr class="er"><td colspan="5">Loading data…</td></tr></tbody>
      </table>
    </div>
    <div class="report-foot">Shows up to 50 most recent completions (AR + Follow-up combined) &nbsp;·&nbsp; Auto-refreshes every 15 s</div>
  </div>

  <!-- ── Update bar ─────────────────────────────────────────────── -->
  <div class="update-bar" style="margin-top:8px;">
    Live Reporting Dashboard &nbsp;·&nbsp; Refreshed: <span id="lrd-ts">—</span>
  </div>

</div><!-- /.page -->

<style>
/* ═══════════════════════════════════════════════════════════════
   LIVE REPORTING DASHBOARD — Section 4 Styles
═══════════════════════════════════════════════════════════════ */
.lrd-meta-bar{
  display:flex;align-items:center;gap:10px;flex-wrap:wrap;
  background:rgba(0,255,198,.025);border:1px solid rgba(0,255,198,.1);
  border-radius:var(--r);padding:10px 16px;
  font-family:var(--mono);font-size:10px;color:var(--text3);
  margin-bottom:22px;
}
.lrd-meta-bar strong{color:var(--accent);}
.lrd-dot{color:var(--border2);}
.lrd-spacer{flex:1;}
.lrd-refresh-note{color:var(--text3);font-size:9px;}

/* KPI row */
.lrd-kpi-row{
  display:grid;
  grid-template-columns:repeat(6,1fr);
  gap:12px;margin-bottom:18px;
}
@media(max-width:1400px){.lrd-kpi-row{grid-template-columns:repeat(3,1fr);}}
@media(max-width:800px) {.lrd-kpi-row{grid-template-columns:repeat(2,1fr);}}
@media(max-width:500px) {.lrd-kpi-row{grid-template-columns:1fr;}}

.lrd-kpi{
  background:var(--bg2);border:1px solid var(--border2);border-radius:var(--r);
  padding:16px 18px 14px;position:relative;overflow:hidden;
  transition:border-color .2s,transform .2s;cursor:default;
  display:flex;flex-direction:column;gap:0;
}
.lrd-kpi:hover{transform:translateY(-2px);}
.lrd-kpi::after{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:var(--lrd-line,var(--accent));}
.lrd-kpi::before{content:'';position:absolute;inset:0;pointer-events:none;background:linear-gradient(135deg,var(--lrd-bg,rgba(0,255,198,.04)) 0%,transparent 55%);}

.lrd-kpi-green {--lrd-line:var(--accent); --lrd-bg:rgba(0,255,198,.04);}
.lrd-kpi-amber {--lrd-line:var(--amber);  --lrd-bg:rgba(245,158,11,.04);}
.lrd-kpi-blue  {--lrd-line:var(--blue);   --lrd-bg:rgba(56,189,248,.04);}
.lrd-kpi-orange{--lrd-line:var(--orange); --lrd-bg:rgba(251,146,60,.04);}
.lrd-kpi-purple{--lrd-line:var(--purple); --lrd-bg:rgba(192,132,252,.04);}
.lrd-kpi-teal  {--lrd-line:#2dd4bf;       --lrd-bg:rgba(45,212,191,.04);}

.lrd-kpi-icon{font-size:20px;margin-bottom:9px;width:36px;height:36px;display:flex;align-items:center;justify-content:center;border-radius:8px;background:rgba(255,255,255,.04);}
.lrd-kpi-body{flex:1;}
.lrd-kpi-lbl{font-family:var(--mono);font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--text2);margin-bottom:5px;}
.lrd-kpi-val{font-family:var(--mono);font-size:32px;font-weight:700;line-height:1;color:var(--accent);transition:all .3s;}
.lrd-val-amber {color:var(--amber);}
.lrd-val-blue  {color:var(--blue);}
.lrd-val-orange{color:var(--orange);}
.lrd-val-purple{color:var(--purple);}
.lrd-val-teal  {color:#2dd4bf;}
.lrd-val-green {color:var(--accent);}
.lrd-kpi-sub{font-family:var(--mono);font-size:9px;color:var(--text3);margin-top:5px;}
.lrd-kpi-spark{position:relative;height:40px;margin-top:10px;}

/* Mid row */
.lrd-mid-row{display:flex;gap:13px;margin-bottom:18px;}
@media(max-width:1100px){.lrd-mid-row{flex-direction:column;}}

.lrd-chart-card{
  background:var(--bg2);border:1px solid var(--border2);border-radius:var(--r);
  padding:17px;
}
.lrd-chart-wide{flex:2;min-width:0;}

.lrd-ratio-card{
  flex:1;min-width:260px;
  background:var(--bg2);border:1px solid var(--border2);border-radius:var(--r);
  padding:17px;
}

.lrd-chart-hd{
  display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap;
}
.lrd-chart-hd h3{
  font-family:var(--mono);font-size:10px;font-weight:700;
  color:var(--text2);text-transform:uppercase;letter-spacing:.1em;
  flex:1;
}
.lrd-legend{display:flex;align-items:center;gap:10px;font-family:var(--mono);font-size:10px;color:var(--text3);}
.lrd-leg-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;}

.lrd-ratio-item{}
.lrd-ratio-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:5px;}
.lrd-ratio-lbl{font-family:var(--mono);font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text2);}
.lrd-ratio-pct{font-family:var(--mono);font-size:18px;font-weight:700;line-height:1;}
.lrd-bar-wrap{background:var(--bg3);border-radius:4px;height:6px;overflow:hidden;margin-bottom:5px;}
.lrd-bar{height:100%;border-radius:4px;transition:width .6s cubic-bezier(.4,0,.2,1);}
.lrd-bar-amber {background:var(--amber);}
.lrd-bar-purple{background:var(--purple);}
.lrd-bar-green {background:var(--accent);}
.lrd-ratio-sub{font-family:var(--mono);font-size:9px;color:var(--text3);}

/* Feed card */
.lrd-feed-card{
  background:var(--bg2);border:1px solid var(--border2);
  border-radius:var(--r);overflow:hidden;margin-bottom:4px;
}

/* Type badge in feed */
.lrd-type-ar{display:inline-flex;align-items:center;gap:4px;font-family:var(--mono);font-size:9px;font-weight:700;padding:3px 8px;border-radius:10px;background:rgba(0,255,198,.08);border:1px solid rgba(0,255,198,.25);color:var(--accent);white-space:nowrap;}
.lrd-type-fu{display:inline-flex;align-items:center;gap:4px;font-family:var(--mono);font-size:9px;font-weight:700;padding:3px 8px;border-radius:10px;background:rgba(251,146,60,.08);border:1px solid rgba(251,146,60,.25);color:var(--orange);white-space:nowrap;}
</style>

<script>
// ══════════════════════════════════════════════════════════════════
//  Utilities
// ══════════════════════════════════════════════════════════════════
const esc = s => s==null?'—':String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

function stripHtml(h){
  if(!h) return '';
  return h.replace(/<style[\s\S]*?<\/style>/gi,' ')
          .replace(/<[^>]*>/g,' ')
          .replace(/&nbsp;/gi,' ')
          .replace(/\s+/g,' ').trim();
}

function fmtDT(dt){
  if(!dt) return '—';
  try{ const d=new Date(dt.replace(' ','T'));return d.toLocaleString([],{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});}
  catch(e){return dt;}
}

function ordinal(n){
  n=parseInt(n)||1;
  const s=['th','st','nd','rd'],v=n%100;
  return n+(s[(v-20)%10]||s[v]||s[0]);
}

// ── Clock ─────────────────────────────────────────────────────────
function updateClock(){
  const now=new Date();
  document.getElementById('topclock').textContent=now.toLocaleTimeString([],{hour:'2-digit',minute:'2-digit',second:'2-digit'});
  document.getElementById('topdate').textContent=now.toLocaleDateString([],{weekday:'short',year:'numeric',month:'short',day:'numeric'});
  const midnight=new Date(now);midnight.setDate(midnight.getDate()+1);midnight.setHours(0,0,0,0);
  const d=midnight-now;
  const label=`${String(Math.floor(d/3600000)).padStart(2,'0')}:${String(Math.floor((d%3600000)/60000)).padStart(2,'0')}:${String(Math.floor((d%60000)/1000)).padStart(2,'0')}`;
  ['next-reset','next-reset2'].forEach(id=>{const el=document.getElementById(id);if(el)el.textContent='in '+label;});
}
setInterval(updateClock,1000); updateClock();

// ── Refresh ring ──────────────────────────────────────────────────
const REFRESH=15, CIRC=2*Math.PI*12;
let countdown=REFRESH;
function updateRing(){
  document.getElementById('ring').style.strokeDashoffset=CIRC*(1-countdown/REFRESH);
  document.getElementById('ring-sec').textContent=countdown;
}

// ══════════════════════════════════════════════════════════════════
//  Charts
// ══════════════════════════════════════════════════════════════════
const ttCfg={backgroundColor:'rgba(4,6,12,.95)',borderColor:'rgba(0,255,198,.2)',borderWidth:1,titleColor:'#00ffc6',bodyColor:'#c8d8f0',titleFont:{family:'IBM Plex Mono',size:10},bodyFont:{family:'IBM Plex Mono',size:11}};
const baseOpts={responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:ttCfg},scales:{x:{display:false},y:{display:false,beginAtZero:true}},elements:{point:{radius:0,hoverRadius:4},line:{tension:.4}},animation:{duration:500}};

function mkSpark(id,color,data){
  const ctx=document.getElementById(id).getContext('2d');
  const g=ctx.createLinearGradient(0,0,0,130);
  g.addColorStop(0,color.replace('rgb','rgba').replace(')',', .22)'));
  g.addColorStop(1,color.replace('rgb','rgba').replace(')',', 0)'));
  return new Chart(ctx,{type:'line',data:{labels:data.map((_,i)=>i),datasets:[{data,borderColor:color,backgroundColor:g,borderWidth:2,fill:true}]},options:baseOpts});
}

const chartFS=mkSpark('chart-fu-sent',     'rgb(0,255,198)',  Array(24).fill(0));
const chartFC=mkSpark('chart-fu-completed','rgb(245,158,11)', Array(24).fill(0));
const chartFP=mkSpark('chart-fu-pending',  'rgb(56,189,248)', Array(24).fill(0));

const hCtx=document.getElementById('chart-hourly').getContext('2d');
const gAR=hCtx.createLinearGradient(0,0,0,170); gAR.addColorStop(0,'rgba(0,255,198,.22)'); gAR.addColorStop(1,'rgba(0,255,198,0)');
const gFU=hCtx.createLinearGradient(0,0,0,170); gFU.addColorStop(0,'rgba(56,189,248,.18)'); gFU.addColorStop(1,'rgba(56,189,248,0)');
const chartH=new Chart(hCtx,{
  type:'line',
  data:{labels:Array.from({length:24},(_,i)=>`${String(i).padStart(2,'0')}:00`),datasets:[
    {label:'Auto-Reply',data:Array(24).fill(0),borderColor:'rgb(0,255,198)',backgroundColor:gAR,borderWidth:2,fill:true,tension:.4,pointRadius:0,pointHoverRadius:5},
    {label:'Follow-up', data:Array(24).fill(0),borderColor:'rgb(56,189,248)',backgroundColor:gFU,borderWidth:2,fill:true,tension:.4,pointRadius:0,pointHoverRadius:5}
  ]},
  options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:ttCfg},scales:{x:{ticks:{color:'#263852',font:{family:'IBM Plex Mono',size:9},maxTicksLimit:12},grid:{color:'rgba(26,40,64,.5)'}},y:{beginAtZero:true,ticks:{color:'#263852',font:{family:'IBM Plex Mono',size:9}},grid:{color:'rgba(26,40,64,.5)'}}},animation:{duration:500}}
});

// ══════════════════════════════════════════════════════════════════
//  Counter animation
// ══════════════════════════════════════════════════════════════════
function animCount(el,val){
  if(!el)return;
  const cur=parseInt(el.textContent)||0;if(cur===val){el.textContent=val;return;}
  const diff=val-cur,steps=20;let step=0;
  const t=setInterval(()=>{step++;el.textContent=Math.round(cur+diff*(step/steps));if(step>=steps)clearInterval(t);},18);
}

// ══════════════════════════════════════════════════════════════════
//  Render mini tables
// ══════════════════════════════════════════════════════════════════
function renderCompleted(rows){
  document.getElementById('cnt-completed').textContent=rows.length;
  const tb=document.querySelector('#tbl-completed tbody');
  if(!rows.length){tb.innerHTML='<tr class="er"><td colspan="4">No completed replies today</td></tr>';return;}
  tb.innerHTML=rows.map(r=>`<tr><td class="ec">${esc(r.from_email)}</td><td class="rc" title="${esc(r.rule_name)}">${esc(r.rule_name)}</td><td><span class="sb">${r.reply_count||0}</span></td><td class="tc">${fmtDT(r.last_sent_at)}</td></tr>`).join('');
}

function renderFuCompleted(rows){
  const pill=document.getElementById('fu-completed-count');
  if(pill) pill.textContent=rows.length+' completed';
  const tb=document.querySelector('#tbl-fu-completed tbody');
  if(!tb) return;
  if(!rows.length){tb.innerHTML='<tr class="er"><td colspan="3">No completed follow-ups today</td></tr>';return;}
  tb.innerHTML=rows.map(r=>`<tr>
    <td><div class="cp">
      <div class="email-main">${esc(r.email)}</div>
      ${r.contact_name?`<div class="email-name">${esc(r.contact_name)}</div>`:''}
    </div></td>
    <td><div class="cp"><span class="rule-tag" title="${esc(r.rule_name)}">${esc(r.rule_name)}</span></div></td>
    <td><div class="cp"><div class="time-main">${fmtDT(r.completed_at)}</div></div></td>
  </tr>`).join('');
}

function renderPendingMini(rows){
  document.getElementById('cnt-pending').textContent=rows.length;
  const tb=document.querySelector('#tbl-pending-mini tbody');
  if(!rows.length){tb.innerHTML='<tr class="er"><td colspan="5">No pending scheduled sends</td></tr>';return;}
  // All rows are status='active', awaiting_reply=0, next_send_at IS NOT NULL
  // "Awaiting Reply" threads are excluded at the SQL level
  tb.innerHTML=rows.map(r=>`<tr><td class="ec">${esc(r.from_email)}</td><td class="rc" title="${esc(r.rule_name)}">${esc(r.rule_name)}</td><td><span class="sb">${r.current_step||1}</span></td><td><span class="sdot sdot-g">Scheduled</span></td><td class="tc">${fmtDT(r.next_send_at)}</td></tr>`).join('');
}

// ══════════════════════════════════════════════════════════════════
//  AUTO-REPLY PENDING REPORT renderer
//  Only receives threads that are truly scheduled to send:
//    • awaiting_reply = 0  (not waiting for a human reply)
//    • next_send_at IS NOT NULL (has a scheduled send time)
//  "Awaiting Reply" threads are excluded at the SQL level and never appear here.
// ══════════════════════════════════════════════════════════════════
function renderArPending(rows){
  document.getElementById('ar-pending-count').textContent = rows.length+' pending';
  // stat-pending shows only truly-pending (unsent scheduled) threads
  const statPend = document.getElementById('stat-pending');
  if(statPend) animCount(statPend, rows.length);
  const tb=document.querySelector('#tbl-ar-pending tbody');
  if(!rows.length){
    tb.innerHTML='<tr class="er"><td colspan="5">✅ No pending auto-reply threads right now</td></tr>';
    return;
  }
  tb.innerHTML=rows.map(r=>{
    // Build message preview
    let bodyText = (r.msg_text||'').trim() || stripHtml(r.msg_html||'');
    const subject = (r.msg_subject||'').trim();
    const msgHtml = subject
      ? `<div class="msg-subject">${esc(subject)}</div>${bodyText?`<div class="msg-body">${esc(bodyText)}</div>`:'<div class="msg-none">No text body</div>'}`
      : (bodyText ? `<div class="msg-body">${esc(bodyText)}</div>` : '<span class="msg-none">— message content not found —</span>');

    // All rows here are awaiting_reply=0 with a scheduled send time — show Pending badge
    const statusHtml = `<span class="pend-ar"><span class="sds"></span>Pending Send</span>`;

    return `<tr>
      <td><div class="cp">
        <div class="email-main">${esc(r.from_email)}</div>
        ${r.from_name?`<div class="email-name">${esc(r.from_name)}</div>`:''}
        <span class="rule-tag" title="${esc(r.rule_name)}">${esc(r.rule_name)}</span>
      </div></td>
      <td><div class="cp">${msgHtml}</div></td>
      <td><div class="cp"><span class="step-ar">${r.current_step||1}</span></div></td>
      <td><div class="cp">${statusHtml}</div></td>
      <td><div class="cp">
        <div class="time-main">${fmtDT(r.next_send_at)}</div>
        <div class="time-sub">Added: ${fmtDT(r.created_at)}</div>
      </div></td>
    </tr>`;
  }).join('');
}

// ══════════════════════════════════════════════════════════════════
//  FOLLOW-UP PENDING REPORT renderer
// ══════════════════════════════════════════════════════════════════
function renderFuPending(rows){
  document.getElementById('fu-pending-count').textContent = rows.length+' pending';
  document.getElementById('fu-pending-badge').textContent = rows.length;
  const tb=document.querySelector('#tbl-fu-pending tbody');
  if(!rows.length){
    tb.innerHTML='<tr class="er"><td colspan="5">✅ No pending follow-up contacts right now</td></tr>';
    return;
  }
  tb.innerHTML=rows.map(r=>{
    let bodyText = (r.msg_text||'').trim() || stripHtml(r.msg_html||'');
    const subject = (r.msg_subject||'').trim();
    const msgHtml = subject
      ? `<div class="msg-subject">${esc(subject)}</div>${bodyText?`<div class="msg-body">${esc(bodyText)}</div>`:'<div class="msg-none">No text body</div>'}`
      : (bodyText ? `<div class="msg-body">${esc(bodyText)}</div>` : '<span class="msg-none">— message content not found —</span>');

    const stepOrd = ordinal(r.current_step||1);

    return `<tr>
      <td><div class="cp">
        <div class="email-main">${esc(r.email)}</div>
        ${r.contact_name?`<div class="email-name">${esc(r.contact_name)}</div>`:''}
        <span class="rule-tag" title="${esc(r.rule_name)}">${esc(r.rule_name)}</span>
      </div></td>
      <td><div class="cp">${msgHtml}</div></td>
      <td><div class="cp"><span class="step-fu">${stepOrd}</span></div></td>
      <td><div class="cp"><span class="pend-fu"><span class="sds"></span>Pending</span></div></td>
      <td><div class="cp">
        <div class="time-main">${fmtDT(r.next_send_at)}</div>
        <div class="time-sub">Enrolled: ${fmtDT(r.enrolled_at)}</div>
      </div></td>
    </tr>`;
  }).join('');
}

// ══════════════════════════════════════════════════════════════════
//  Main data fetch
// ══════════════════════════════════════════════════════════════════
async function fetchData(){
  try{
    const res=await fetch('dashboard.php?api=1&_='+Date.now());
    if(!res.ok) throw new Error('HTTP '+res.status);
    const d=await res.json();
    // Session expired mid-page: reload the parent window to return to login
    if(d.error==='session_expired'){
      try{ if(window.self!==window.top){ window.top.location.reload(); return; } }catch(e){}
      window.location.reload(); return;
    }
    if(!d.ok) throw new Error('API error');

    // Date header
    const rd=document.getElementById('report-date');
    if(rd) rd.textContent=d.date;

    // ── Stat counters ─────────────────────────────────────────────
    // Auto-replies dispatched today (from autoreply_logs, cross-checked with send_logs)
    animCount(document.getElementById('stat-sent'),      d.total_autoreply_sent);
    // Completed auto-reply threads today
    animCount(document.getElementById('stat-completed'), d.completed_replies.length);
    // stat-pending is set inside renderArPending — only truly-pending (unsent
    // scheduled) threads are counted. "Awaiting Reply" threads are excluded.

    // ── Follow-up counters ────────────────────────────────────────
    // "Follow-ups Sent" badge (Sequences Completed chart header) — total sent today
    const fuSentBadge = document.getElementById('fu-sent-badge');
    if(fuSentBadge) fuSentBadge.textContent = d.followup_sent;

    // "Sequences Completed" badge — how many contacts finished full sequence today
    const fuCompletedBadge = document.getElementById('fu-completed-badge');
    if(fuCompletedBadge) fuCompletedBadge.textContent = d.followup_completed;

    // "Contacts Pending" badge — set here; also set inside renderFuPending
    const fuPendingBadge = document.getElementById('fu-pending-badge');
    if(fuPendingBadge) fuPendingBadge.textContent = d.followup_pending.length;

    // ── Sparkline charts ──────────────────────────────────────────
    // Follow-ups Sent sparkline — real hourly data from followup_logs
    chartFS.data.datasets[0].data = d.hourly_followup;
    chartFS.update('none');

    // Sequences Completed sparkline — real hourly data from followup_contacts completed
    chartFC.data.datasets[0].data = d.hourly_followup_completed;
    chartFC.update('none');

    // Contacts Pending sparkline — no per-hour queue data available (future schedule),
    // so show a flat line at the current total pending count divided across 24 hours.
    // Guard against zero to avoid flat-zero chart when pending > 0.
    const pendingTotal = d.followup_pending.length;
    const pendingPerHour = pendingTotal > 0 ? Math.max(1, Math.round(pendingTotal / 24)) : 0;
    chartFP.data.datasets[0].data = Array(24).fill(pendingPerHour);
    chartFP.update('none');

    // ── Hourly combined chart (Hourly Activity — Today) ───────────
    // dataset[0] = Auto-Reply sends per hour (from autoreply_logs + send_logs fallback)
    // dataset[1] = Follow-up sends per hour (from followup_logs + send_logs fallback)
    chartH.data.datasets[0].data = d.hourly_autoreply;
    chartH.data.datasets[1].data = d.hourly_followup;
    chartH.update();

    // ── Mini overview tables ──────────────────────────────────────
    renderCompleted(d.completed_replies);
    renderPendingMini(d.pending_replies);

    // ── Full pending report tables ────────────────────────────────
    renderArPending(d.pending_replies);
    renderFuPending(d.followup_pending);

    // ── Completed Follow-Up Today table ──────────────────────────
    renderFuCompleted(d.followup_completed_list||[]);

    // ── Timestamps ───────────────────────────────────────────────
    const ts=new Date(d.generated_at.replace(' ','T')).toLocaleTimeString();
    ['last-updated','last-updated2'].forEach(id=>{const el=document.getElementById(id);if(el)el.textContent=ts;});

    // ── Live Reporting Dashboard (Section 4) ─────────────────────
    animCount(document.getElementById('lrd-ar-sent'),      d.total_autoreply_sent);
    animCount(document.getElementById('lrd-ar-completed'), d.completed_replies.length);
    animCount(document.getElementById('lrd-ar-pending'),   d.pending_replies.length);
    animCount(document.getElementById('lrd-fu-sent'),      d.followup_sent);
    animCount(document.getElementById('lrd-fu-completed'), d.followup_completed);
    animCount(document.getElementById('lrd-fu-pending'),   d.followup_pending.length);

    if(window._lrdSparks){
      window._lrdSparks.arSent.data.datasets[0].data      = d.hourly_autoreply;
      window._lrdSparks.arCompleted.data.datasets[0].data = d.hourly_followup_completed;
      window._lrdSparks.arPending.data.datasets[0].data   = Array(24).fill(Math.max(0,Math.round(d.pending_replies.length/24)));
      window._lrdSparks.fuSent.data.datasets[0].data      = d.hourly_followup;
      window._lrdSparks.fuCompleted.data.datasets[0].data = d.hourly_followup_completed;
      window._lrdSparks.fuPending.data.datasets[0].data   = Array(24).fill(Math.max(0,Math.round(d.followup_pending.length/24)));
      Object.values(window._lrdSparks).forEach(c=>c.update('none'));
    }

    const arTotal=d.total_autoreply_sent||0, arDone=d.completed_replies.length||0;
    const arPct=arTotal>0?Math.round(arDone/arTotal*100):0;
    const fuTotal=d.followup_sent||0, fuDone=d.followup_completed||0;
    const fuPct=fuTotal>0?Math.round(fuDone/fuTotal*100):0;
    const grandTotal=arTotal+fuTotal;

    const arPctEl=document.getElementById('lrd-ratio-ar');    if(arPctEl)arPctEl.textContent=arPct+'%';
    const fuPctEl=document.getElementById('lrd-ratio-fu');    if(fuPctEl)fuPctEl.textContent=fuPct+'%';
    const totEl=document.getElementById('lrd-total-emails');  if(totEl)animCount(totEl,grandTotal);
    const arBar=document.getElementById('lrd-bar-ar');        if(arBar)arBar.style.width=arPct+'%';
    const fuBar=document.getElementById('lrd-bar-fu');        if(fuBar)fuBar.style.width=fuPct+'%';
    const arSubEl=document.getElementById('lrd-ratio-ar-sub');if(arSubEl)arSubEl.textContent=arDone+' completed of '+arTotal+' sent';
    const fuSubEl=document.getElementById('lrd-ratio-fu-sub');if(fuSubEl)fuSubEl.textContent=fuDone+' completed of '+fuTotal+' sent';
    const totSubEl=document.getElementById('lrd-ratio-total-sub');if(totSubEl)totSubEl.textContent=arTotal+' auto-replies + '+fuTotal+' follow-ups';

    if(window._lrdChartH){
      window._lrdChartH.data.datasets[0].data=d.hourly_autoreply;
      window._lrdChartH.data.datasets[1].data=d.hourly_followup;
      window._lrdChartH.update();
    }

    renderLrdFeed(d.completed_replies, d.followup_completed_list||[]);
    const tsEl=document.getElementById('lrd-ts');if(tsEl)tsEl.textContent=ts;

  }catch(err){console.error('Dashboard fetch error:',err);}
}

// ══════════════════════════════════════════════════════════════════
//  Section 4 — Live Reporting Dashboard init
// ══════════════════════════════════════════════════════════════════

// Mini sparklines inside KPI cards
(function(){
  const sparkOpts={responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{enabled:false}},scales:{x:{display:false},y:{display:false,beginAtZero:true}},elements:{point:{radius:0},line:{tension:.4}},animation:{duration:400}};
  function mkLrdSpark(id,color){
    const el=document.getElementById(id); if(!el) return null;
    const ctx=el.getContext('2d');
    const g=ctx.createLinearGradient(0,0,0,40);
    g.addColorStop(0,color.replace('rgb','rgba').replace(')',', .3)'));
    g.addColorStop(1,color.replace('rgb','rgba').replace(')',', 0)'));
    return new Chart(ctx,{type:'line',data:{labels:Array(24).fill(''),datasets:[{data:Array(24).fill(0),borderColor:color,backgroundColor:g,borderWidth:1.5,fill:true}]},options:sparkOpts});
  }
  window._lrdSparks={
    arSent:     mkLrdSpark('lrd-spark-ar-sent',     'rgb(0,255,198)'),
    arCompleted:mkLrdSpark('lrd-spark-ar-completed','rgb(245,158,11)'),
    arPending:  mkLrdSpark('lrd-spark-ar-pending',  'rgb(56,189,248)'),
    fuSent:     mkLrdSpark('lrd-spark-fu-sent',     'rgb(251,146,60)'),
    fuCompleted:mkLrdSpark('lrd-spark-fu-completed','rgb(192,132,252)'),
    fuPending:  mkLrdSpark('lrd-spark-fu-pending',  'rgb(45,212,191)')
  };
})();

// Hourly combined chart for Section 4
(function(){
  const hEl=document.getElementById('lrd-chart-hourly'); if(!hEl) return;
  const hCtx=hEl.getContext('2d');
  const gAR2=hCtx.createLinearGradient(0,0,0,180); gAR2.addColorStop(0,'rgba(0,255,198,.22)'); gAR2.addColorStop(1,'rgba(0,255,198,0)');
  const gFU2=hCtx.createLinearGradient(0,0,0,180); gFU2.addColorStop(0,'rgba(56,189,248,.18)'); gFU2.addColorStop(1,'rgba(56,189,248,0)');
  window._lrdChartH=new Chart(hCtx,{
    type:'line',
    data:{labels:Array.from({length:24},(_,i)=>`${String(i).padStart(2,'0')}:00`),datasets:[
      {label:'Auto-Reply',data:Array(24).fill(0),borderColor:'rgb(0,255,198)',backgroundColor:gAR2,borderWidth:2,fill:true,tension:.4,pointRadius:0,pointHoverRadius:5},
      {label:'Follow-up', data:Array(24).fill(0),borderColor:'rgb(56,189,248)',backgroundColor:gFU2,borderWidth:2,fill:true,tension:.4,pointRadius:0,pointHoverRadius:5}
    ]},
    options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:ttCfg},scales:{x:{ticks:{color:'#263852',font:{family:'IBM Plex Mono',size:9},maxTicksLimit:12},grid:{color:'rgba(26,40,64,.5)'}},y:{beginAtZero:true,ticks:{color:'#263852',font:{family:'IBM Plex Mono',size:9}},grid:{color:'rgba(26,40,64,.5)'}}},animation:{duration:500}}
  });
})();

// Activity feed renderer — merges AR completed + FU completed, sorts by time desc
function renderLrdFeed(arRows, fuRows){
  const combined=[];
  (arRows||[]).forEach(r=>combined.push({type:'ar',email:r.from_email,rule:r.rule_name,steps:r.reply_count||0,ts:r.last_sent_at}));
  (fuRows||[]).forEach(r=>combined.push({type:'fu',email:r.email,rule:r.rule_name,steps:'—',ts:r.completed_at||r.last_sent_at}));
  combined.sort((a,b)=>new Date(b.ts)-new Date(a.ts));
  const top=combined.slice(0,50);
  const pill=document.getElementById('lrd-feed-count');if(pill)pill.textContent=top.length+' entries';
  const tb=document.querySelector('#lrd-tbl-feed tbody');if(!tb)return;
  if(!top.length){tb.innerHTML='<tr class="er"><td colspan="5">No completions yet today</td></tr>';return;}
  tb.innerHTML=top.map(r=>{
    const badge=r.type==='ar'
      ?'<span class="lrd-type-ar">🔁 AR</span>'
      :'<span class="lrd-type-fu">📬 FU</span>';
    const stepCell=r.type==='ar'?`<span class="sb">${r.steps}</span>`:'<span style="color:var(--text3);font-family:var(--mono);font-size:11px;">—</span>';
    return `<tr>
      <td class="cp">${badge}</td>
      <td class="cp"><span class="email-main">${esc(r.email)}</span></td>
      <td class="cp"><span class="rule-tag">${esc(r.rule)}</span></td>
      <td class="cp" style="text-align:center">${stepCell}</td>
      <td class="cp"><span class="time-main">${fmtDT(r.ts)}</span></td>
    </tr>`;
  }).join('');
}

// ── Refresh loop ──────────────────────────────────────────────────
fetchData();
setInterval(()=>{countdown--;updateRing();if(countdown<=0){countdown=REFRESH;fetchData();}},1000);
updateRing();
</script>

<!-- ═══════════════════════════════════════════════════════════════
     EMAIL LEADS ANALYTICS (ELA) — JavaScript Module
═══════════════════════════════════════════════════════════════ -->
<script>
(function(){
'use strict';

// ── State ─────────────────────────────────────────────────────────
var elaFilter  = 'today';
var elaRefInt  = null;
var elaChartMain  = null;
var elaChartTrend = null;
var elaIsAdmin = false; // will be detected on first fetch

// ── Helpers ───────────────────────────────────────────────────────
function elaFmt(n){
  n = parseInt(n,10)||0;
  if(n>=1000000) return (n/1000000).toFixed(1)+'M';
  if(n>=1000)    return (n/1000).toFixed(1)+'k';
  return n.toString();
}

function elaAnimCount(el, target){
  if(!el) return;
  var start = parseInt(el.textContent)||0;
  if(start === target){ el.textContent = elaFmt(target); return; }
  var steps = 20, step = 0;
  var inc = (target - start) / steps;
  var t = setInterval(function(){
    step++;
    var val = Math.round(start + inc * step);
    el.textContent = elaFmt(val);
    if(step >= steps){ el.textContent = elaFmt(target); clearInterval(t); }
  }, 30);
}

function elaEsc(s){ return String(s||'').replace(/[&<>"']/g,function(c){return({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];}); }

function elaFilterLabel(f){
  return {'today':'Today (Hourly)','yesterday':'Yesterday (Hourly)','last7':'Last 7 Days','last30':'Last 30 Days','alltime':'All Time'}[f]||f;
}

// ── Set active filter ─────────────────────────────────────────────
window.elaSetFilter = function(f){
  elaFilter = f;
  document.querySelectorAll('.ela-btn').forEach(function(b){
    b.classList.toggle('ela-btn-active', b.dataset.filter === f);
  });
  elaFetch();
};

// ── Build / update charts ─────────────────────────────────────────
function elaInitCharts(){
  var ctxM = document.getElementById('elaChartMain');
  var ctxT = document.getElementById('elaChartTrend');
  if(!ctxM || !ctxT) return;

  var commonOpts = {
    responsive:true, maintainAspectRatio:false,
    interaction:{mode:'index',intersect:false},
    plugins:{legend:{display:false},tooltip:{
      backgroundColor:'rgba(4,6,12,.95)',
      titleColor:'#00ffc6',bodyColor:'#c8d8f0',
      borderColor:'rgba(0,255,198,.2)',borderWidth:1,
      titleFont:{family:'IBM Plex Mono',size:10},
      bodyFont:{family:'IBM Plex Mono',size:10},
      padding:10
    }},
    scales:{
      x:{grid:{color:'rgba(255,255,255,.04)'},ticks:{color:'#5a7a9a',font:{family:'IBM Plex Mono',size:9},maxRotation:45}},
      y:{grid:{color:'rgba(255,255,255,.04)'},ticks:{color:'#5a7a9a',font:{family:'IBM Plex Mono',size:9}},beginAtZero:true}
    }
  };

  elaChartMain = new Chart(ctxM, {
    type:'bar',
    data:{
      labels:[],
      datasets:[{
        label:'Email Leads',
        data:[],
        backgroundColor:'rgba(0,255,198,.18)',
        borderColor:'rgba(0,255,198,.7)',
        borderWidth:1,
        borderRadius:3,
        hoverBackgroundColor:'rgba(0,255,198,.35)'
      }]
    },
    options: Object.assign({},commonOpts)
  });

  elaChartTrend = new Chart(ctxT, {
    type:'line',
    data:{
      labels: Array.from({length:30},function(_,i){return 'D'+(i+1);}),
      datasets:[{
        label:'30-Day Leads',
        data: Array(30).fill(0),
        borderColor:'rgba(56,189,248,.8)',
        backgroundColor:'rgba(56,189,248,.08)',
        borderWidth:2,
        fill:true,
        tension:.4,
        pointRadius:0,
        pointHitRadius:8
      }]
    },
    options: Object.assign({},commonOpts,{
      scales:{
        x:{display:false},
        y:{grid:{color:'rgba(255,255,255,.04)'},ticks:{color:'#5a7a9a',font:{family:'IBM Plex Mono',size:9}},beginAtZero:true}
      }
    })
  });
}

// ── Render summary ────────────────────────────────────────────────
function elaRenderSummary(s){
  elaAnimCount(document.getElementById('ela-total-alltime'), s.total_alltime||0);
  elaAnimCount(document.getElementById('ela-total-today'),   s.total_today||0);
  elaAnimCount(document.getElementById('ela-total-week'),    s.total_week||0);
  elaAnimCount(document.getElementById('ela-total-month'),   s.total_month||0);
  elaAnimCount(document.getElementById('ela-active-servers'),s.active_servers||0);
  elaAnimCount(document.getElementById('ela-total-filtered'),s.total_filtered||0);

  var growthEl = document.getElementById('ela-growth-sub');
  if(growthEl){
    var g = parseFloat(s.day_growth_pct)||0;
    var arrow = g>0?'▲ ':g<0?'▼ ':'';
    var col = g>0?'var(--accent)':g<0?'var(--red)':'var(--text3)';
    growthEl.innerHTML = '<span style="color:'+col+'">'+arrow+Math.abs(g)+'%</span> vs yesterday';
  }
  var lbl = document.getElementById('ela-filtered-lbl');
  if(lbl) lbl.textContent = elaFilterLabel(elaFilter)+' Total';

  // ── Pipeline Stats ─────────────────────────────────────────────
  var pendingTotal   = parseInt(s.pending_total)||0;
  var replyPending   = parseInt(s.reply_pending)||0;
  var followupPending= parseInt(s.followup_pending)||0;
  var processedTotal = parseInt(s.processed_total)||0;
  var totalAlltime   = parseInt(s.total_alltime)||1;
  var todayLive      = parseInt(s.total_today)||0;
  var todayYest      = parseInt(s.total_yesterday)||0;

  elaAnimCount(document.getElementById('ela-pending-total'),  pendingTotal);
  elaAnimCount(document.getElementById('ela-reply-pending'),  replyPending);
  elaAnimCount(document.getElementById('ela-followup-pending'),followupPending);
  elaAnimCount(document.getElementById('ela-processed-total'),processedTotal);
  elaAnimCount(document.getElementById('ela-today-live'),     todayLive);

  // Progress bars
  function setBar(barId, pctId, val, total, color){
    var pct = total > 0 ? Math.min(100, Math.round((val/total)*100)) : 0;
    var barEl = document.getElementById(barId);
    var pctEl = document.getElementById(pctId);
    if(barEl) barEl.style.width = pct+'%';
    if(pctEl) pctEl.textContent = pct+'%';
  }

  setBar('ela-pending-bar',    'ela-pending-pct',    pendingTotal,    totalAlltime);
  setBar('ela-reply-bar',      'ela-reply-pct',      replyPending,    pendingTotal||1);
  setBar('ela-followup-bar',   'ela-followup-pct',   followupPending, pendingTotal||1);
  setBar('ela-processed-bar',  'ela-processed-pct',  processedTotal,  totalAlltime);

  // Today vs yesterday growth bar
  var todayPct = todayYest > 0 ? Math.min(200, Math.round((todayLive/todayYest)*100)) : (todayLive>0?100:0);
  var todayBarEl = document.getElementById('ela-today-bar');
  var todayGrowthEl = document.getElementById('ela-today-growth-pct');
  var gVal = parseFloat(s.day_growth_pct)||0;
  var arrow = gVal>0?'▲ ':gVal<0?'▼ ':'';
  var col = gVal>0?'var(--accent)':gVal<0?'var(--red)':'var(--text3)';
  if(todayGrowthEl) todayGrowthEl.innerHTML = '<span style="color:'+col+'">'+arrow+Math.abs(gVal)+'%</span>';
  if(todayBarEl){
    todayBarEl.style.width = Math.min(100, todayPct)+'%';
    todayBarEl.style.background = gVal>=0 ? 'var(--accent)' : 'var(--red)';
  }
}

// ── Render chart ──────────────────────────────────────────────────
function elaRenderChart(chart){
  if(!elaChartMain) return;
  elaChartMain.data.labels = chart.labels||[];
  elaChartMain.data.datasets[0].data = chart.data||[];
  elaChartMain.update('active');
  var lb = document.getElementById('ela-chart-label');
  if(lb) lb.textContent = elaFilterLabel(elaFilter);
}

function elaRenderTrend(trend){
  if(!elaChartTrend) return;
  elaChartTrend.data.datasets[0].data = trend||[];
  elaChartTrend.update('none');
}

// ── Render server-wise table ──────────────────────────────────────
function elaRenderServers(rows){
  var tb = document.getElementById('ela-server-tbody');
  var cnt = document.getElementById('ela-server-count');
  if(cnt) cnt.textContent = rows.length;
  if(!tb) return;
  if(!rows.length){ tb.innerHTML='<tr class="er"><td colspan="6">No servers found for this period</td></tr>'; return; }
  var maxLeads = Math.max(1, Math.max.apply(null, rows.map(function(r){return parseInt(r.lead_count)||0;})));
  var ownerTh = document.getElementById('ela-th-owner');

  tb.innerHTML = rows.map(function(r,i){
    var pct = Math.round(((parseInt(r.lead_count)||0)/maxLeads)*100);
    var statusHtml = r.status==='active'
      ? '<span class="ela-status-pill ela-status-active">Active</span>'
      : '<span class="ela-status-pill ela-status-disabled">Disabled</span>';
    var ownerCell = r.owner ? '<td>'+elaEsc(r.owner)+'</td>' : '';
    if(r.owner && ownerTh) ownerTh.style.display='';
    return '<tr>'
      +'<td style="color:var(--text3);font-family:var(--mono);font-size:10px;">'+(i+1)+'</td>'
      +'<td style="font-family:var(--mono);font-size:11px;color:var(--text);font-weight:600;">'+elaEsc(r.name)+'</td>'
      +'<td style="font-family:var(--mono);font-size:10px;color:var(--text3);">'+elaEsc(r.host)+'<br><span style="color:var(--text3);font-size:9px;">'+elaEsc(r.username)+'</span></td>'
      +ownerCell
      +'<td>'+statusHtml+'</td>'
      +'<td style="font-family:var(--mono);font-size:12px;color:var(--accent);font-weight:700;">'+(parseInt(r.lead_count)||0)
        +'<span class="ela-lead-bar-wrap"><span class="ela-lead-bar" style="width:'+pct+'%"></span></span>'
      +'</td>'
      +'</tr>';
  }).join('');
}

// ── Render user-wise table (admin only) ───────────────────────────
function elaRenderUsers(rows){
  var card = document.getElementById('ela-user-table-card');
  var tb   = document.getElementById('ela-user-tbody');
  var cnt  = document.getElementById('ela-user-count');
  if(!rows || !rows.length){ if(card) card.style.display='none'; return; }
  if(card) card.style.display='';
  if(cnt)  cnt.textContent = rows.length;
  if(!tb)  return;
  var maxLeads = Math.max(1, Math.max.apply(null, rows.map(function(r){return parseInt(r.lead_count)||0;})));
  tb.innerHTML = rows.map(function(r,i){
    var pct = Math.round(((parseInt(r.lead_count)||0)/maxLeads)*100);
    return '<tr>'
      +'<td style="color:var(--text3);font-family:var(--mono);font-size:10px;">'+(i+1)+'</td>'
      +'<td style="font-family:var(--mono);font-size:11px;color:var(--text);font-weight:600;">'+elaEsc(r.username)+'</td>'
      +'<td style="font-family:var(--mono);font-size:11px;color:var(--text2);">'+(parseInt(r.server_count)||0)+' servers</td>'
      +'<td style="font-family:var(--mono);font-size:12px;color:var(--blue);font-weight:700;">'+(parseInt(r.lead_count)||0)
        +'<span class="ela-lead-bar-wrap" style="--ela-line:var(--blue)"><span class="ela-lead-bar" style="width:'+pct+'%;background:var(--blue)"></span></span>'
      +'</td>'
      +'</tr>';
  }).join('');
}

// ── Main fetch ────────────────────────────────────────────────────
function elaFetch(){
  var url = 'email_leads_stats.php?view=all&filter='+encodeURIComponent(elaFilter)+'&_='+Date.now();
  fetch(url).then(function(r){ return r.json(); }).then(function(d){
    if(!d.ok){
      if(d.error==='session_expired'){
        try{if(window.self!==window.top){window.top.location.reload();return;}}catch(e){}
        window.location.reload(); return;
      }
      return;
    }
    if(d.summary)    elaRenderSummary(d.summary);
    if(d.chart)      elaRenderChart(d.chart);
    if(d.trend_30d)  elaRenderTrend(d.trend_30d);
    if(d.server_wise)elaRenderServers(d.server_wise);
    if(d.user_wise)  elaRenderUsers(d.user_wise);

    var el = document.getElementById('ela-last-updated');
    if(el) el.textContent = new Date(d.generated_at.replace(' ','T')).toLocaleTimeString();

  }).catch(function(e){ console.error('ELA fetch error:',e); });
}

// ── Init ──────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function(){
  elaInitCharts();
  elaFetch();
  elaRefInt = setInterval(elaFetch, 30000);
});

// Fallback if DOM already ready
if(document.readyState !== 'loading'){
  elaInitCharts();
  elaFetch();
  if(!elaRefInt) elaRefInt = setInterval(elaFetch, 30000);
}

})();
</script>
</body>
</html>
