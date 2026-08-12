<?php
// ══════════════════════════════════════════════════════════════════════════════
//  MailsZo — Step-Wise Analytics: Auto Reply & Follow-Up
//  Separate real-time charts and reports for each step (1–15)
//  Does NOT modify any existing file, table, or system behaviour.
// ══════════════════════════════════════════════════════════════════════════════
require_once __DIR__ . '/includes/config.php';
if (!isInstalled()) { header('Location: install.php'); exit; }
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['uid'])) {
    if (isset($_GET['api'])) {
        header('Content-Type: application/json');
        echo json_encode(['ok'=>false,'error'=>'session_expired']);
        exit;
    }
    header('Location: index.php'); exit;
}

// ══════════════════════════════════════════════════════════════════
//  API endpoint — returns step-level stats as JSON
// ══════════════════════════════════════════════════════════════════
if (isset($_GET['api'])) {
    header('Content-Type: application/json');

    $uid     = (int)$_SESSION['uid'];
    $isAdmin = !empty($_SESSION['is_admin']);
    $pdo     = db();

    // ── Date range resolution ──────────────────────────────────────
    // Matches the exact same logic as api.php::resolveDateRange()
    function saResolveDateRange(string $preset, string $cf, string $ct): array {
        $tz  = new DateTimeZone(date_default_timezone_get());
        $now = new DateTime('now', $tz);
        $from = null; $to = null; $active = true;
        try {
            switch ($preset) {
                case 'today':
                    $from = new DateTime('today', $tz);
                    $to   = (clone $from)->modify('+1 day -1 second'); break;
                case 'yesterday':
                    $from = new DateTime('yesterday', $tz);
                    $to   = (clone $from)->modify('+1 day -1 second'); break;
                case '7d':
                    $to = clone $now; $from = (clone $now)->modify('-7 days'); break;
                case '15d':
                    $to = clone $now; $from = (clone $now)->modify('-15 days'); break;
                case 'this_month':
                    $from = new DateTime($now->format('Y-m-01 00:00:00'), $tz);
                    $to   = clone $now; break;
                case 'last_month':
                    $from = new DateTime($now->format('Y-m-01 00:00:00'), $tz);
                    $from->modify('-1 month');
                    $to = (clone $from)->modify('+1 month -1 second'); break;
                case 'custom':
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $cf) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ct)) {
                        $active = false; break;
                    }
                    $from = new DateTime($cf.' 00:00:00', $tz);
                    $to   = new DateTime($ct.' 23:59:59', $tz);
                    if ($to < $from) { $tmp=$from; $from=$to; $to=$tmp; }
                    break;
                default: $active = false;
            }
        } catch (Exception $e) { $active = false; }
        return [
            'from'   => ($active && $from) ? $from->format('Y-m-d H:i:s') : '',
            'to'     => ($active && $to)   ? $to->format('Y-m-d H:i:s')   : '',
            'active' => (bool)$active && $from && $to,
        ];
    }

    $rng = saResolveDateRange(
        $_GET['range']     ?? 'today',
        $_GET['date_from'] ?? '',
        $_GET['date_to']   ?? ''
    );

    // Default to "today" when no valid range is supplied
    if (!$rng['active']) {
        $tz   = new DateTimeZone(date_default_timezone_get());
        $from = new DateTime('today', $tz);
        $to   = (clone $from)->modify('+1 day -1 second');
        $rng  = ['from'=>$from->format('Y-m-d H:i:s'),'to'=>$to->format('Y-m-d H:i:s'),'active'=>true];
    }

    $dtFrom = $rng['from'];
    $dtTo   = $rng['to'];

    // ── Build user ownership filters ───────────────────────────────
    // We avoid passing user-controlled values into SQL strings directly;
    // $uid is cast to int above so interpolation is safe.
    $arOwn = $isAdmin ? '1=1' : "r.user_id=$uid";
    $fuOwn = $isAdmin ? '1=1' : "r.user_id=$uid";

    // ── Helper: per-step stats for autoreply_logs ──────────────────
    // Returns an array keyed by step_number (1–15) with sent/failed/pending/completed counts.
    function arStepStats(object $pdo, bool $isAdmin, int $uid, string $dtFrom, string $dtTo, string $arOwn): array {
        // Sent per step in date range
        $rows = $pdo->query(
            "SELECT l.step_number, l.status, COUNT(*) cnt
               FROM autoreply_logs l
               JOIN autoreply_rules r ON r.id = l.rule_id
              WHERE $arOwn
                AND l.sent_at BETWEEN '$dtFrom' AND '$dtTo'
              GROUP BY l.step_number, l.status"
        )->fetchAll();

        $steps = [];
        for ($i=1;$i<=15;$i++) $steps[$i] = ['step'=>$i,'sent'=>0,'failed'=>0,'pending'=>0,'completed'=>0,'replies_received'=>0];

        foreach ($rows as $row) {
            $s = (int)$row['step_number'];
            if ($s<1||$s>15) continue;
            if ($row['status']==='sent')   $steps[$s]['sent']   += (int)$row['cnt'];
            if ($row['status']==='failed') $steps[$s]['failed'] += (int)$row['cnt'];
        }

        // Pending threads per step (current_step = active threads at that step)
        $pending = $pdo->query(
            "SELECT t.current_step, COUNT(*) cnt
               FROM autoreply_threads t
               JOIN autoreply_rules r ON r.id = t.rule_id
              WHERE $arOwn AND t.status='active'
              GROUP BY t.current_step"
        )->fetchAll();
        foreach ($pending as $row) {
            $s = (int)$row['current_step'];
            if ($s<1||$s>15) continue;
            $steps[$s]['pending'] += (int)$row['cnt'];
        }

        // Completed threads that reached each step (reply_count = steps completed)
        $completed = $pdo->query(
            "SELECT t.reply_count, COUNT(*) cnt
               FROM autoreply_threads t
               JOIN autoreply_rules r ON r.id = t.rule_id
              WHERE $arOwn AND t.status='completed'
                AND t.last_sent_at BETWEEN '$dtFrom' AND '$dtTo'
              GROUP BY t.reply_count"
        )->fetchAll();
        foreach ($completed as $row) {
            $s = (int)$row['reply_count'];
            if ($s<1||$s>15) continue;
            $steps[$s]['completed'] += (int)$row['cnt'];
        }

        // Replies received: messages_received field on threads (inbound per step not directly stored,
        // so we attribute total messages_received to the thread's current_step as best proxy)
        $replies = $pdo->query(
            "SELECT t.current_step, SUM(t.messages_received) cnt
               FROM autoreply_threads t
               JOIN autoreply_rules r ON r.id = t.rule_id
              WHERE $arOwn
              GROUP BY t.current_step"
        )->fetchAll();
        foreach ($replies as $row) {
            $s = (int)$row['current_step'];
            if ($s<1||$s>15) continue;
            $steps[$s]['replies_received'] += (int)$row['cnt'];
        }

        return array_values($steps);
    }

    // ── Helper: per-step stats for followup_logs ───────────────────
    function fuStepStats(object $pdo, bool $isAdmin, int $uid, string $dtFrom, string $dtTo, string $fuOwn): array {
        $rows = $pdo->query(
            "SELECT l.step_number, l.status, COUNT(*) cnt
               FROM followup_logs l
               JOIN followup_rules r ON r.id = l.rule_id
              WHERE $fuOwn
                AND l.sent_at BETWEEN '$dtFrom' AND '$dtTo'
              GROUP BY l.step_number, l.status"
        )->fetchAll();

        $steps = [];
        for ($i=1;$i<=15;$i++) $steps[$i] = ['step'=>$i,'sent'=>0,'failed'=>0,'pending'=>0,'completed'=>0,'fu_replies_received'=>0];

        foreach ($rows as $row) {
            $s = (int)$row['step_number'];
            if ($s<1||$s>15) continue;
            if ($row['status']==='sent')   $steps[$s]['sent']   += (int)$row['cnt'];
            if ($row['status']==='failed') $steps[$s]['failed'] += (int)$row['cnt'];
        }

        // Pending contacts at each step
        $pending = $pdo->query(
            "SELECT c.current_step, COUNT(*) cnt
               FROM followup_contacts c
               JOIN followup_rules r ON r.id = c.rule_id
              WHERE $fuOwn AND c.status='active'
              GROUP BY c.current_step"
        )->fetchAll();
        foreach ($pending as $row) {
            $s = (int)$row['current_step'];
            if ($s<1||$s>15) continue;
            $steps[$s]['pending'] += (int)$row['cnt'];
        }

        // Completed (finished the full sequence — logged at max step)
        $completed = $pdo->query(
            "SELECT c.current_step, COUNT(*) cnt
               FROM followup_contacts c
               JOIN followup_rules r ON r.id = c.rule_id
              WHERE $fuOwn AND c.status='completed'
                AND c.last_sent_at BETWEEN '$dtFrom' AND '$dtTo'
              GROUP BY c.current_step"
        )->fetchAll();
        foreach ($completed as $row) {
            $s = (int)$row['current_step'];
            if ($s<1||$s>15) continue;
            $steps[$s]['completed'] += (int)$row['cnt'];
        }

        // FU replies received: count inbound emails from contacts at each step
        $fuReplies = $pdo->query(
            "SELECT c.current_step, COUNT(DISTINCT i.id) cnt
               FROM followup_contacts c
               JOIN followup_rules r ON r.id = c.rule_id
               JOIN inbound_emails i ON LOWER(i.from_email) = LOWER(c.email)
              WHERE $fuOwn
                AND i.received_at BETWEEN '$dtFrom' AND '$dtTo'
              GROUP BY c.current_step"
        )->fetchAll();
        foreach ($fuReplies as $row) {
            $s = (int)$row['current_step'];
            if ($s<1||$s>15) continue;
            $steps[$s]['fu_replies_received'] += (int)$row['cnt'];
        }

        return array_values($steps);
    }

    // ── Helper: per-step detail table rows ────────────────────────
    function arStepTableRows(object $pdo, bool $isAdmin, int $uid, int $step, string $dtFrom, string $dtTo, string $arOwn, int $limit=200): array {
        return $pdo->query(
            "SELECT
                u.username         AS user_id,
                r.name             AS campaign_name,
                l.step_number      AS reply_step,
                NULL               AS followup_step,
                CASE WHEN ie.id IS NOT NULL THEN 'Read' ELSE 'Unread' END AS read_status,
                l.status           AS reply_status,
                l.sent_at          AS datetime
              FROM autoreply_logs l
              JOIN autoreply_rules r ON r.id = l.rule_id
              JOIN users u ON u.id = r.user_id
              LEFT JOIN inbound_emails ie ON LOWER(ie.from_email) = LOWER(l.to_email)
                    AND ie.received_at >= l.sent_at
              WHERE $arOwn
                AND l.step_number = $step
                AND l.sent_at BETWEEN '$dtFrom' AND '$dtTo'
              ORDER BY l.sent_at DESC
              LIMIT $limit"
        )->fetchAll();
    }

    function fuStepTableRows(object $pdo, bool $isAdmin, int $uid, int $step, string $dtFrom, string $dtTo, string $fuOwn, int $limit=200): array {
        return $pdo->query(
            "SELECT
                u.username         AS user_id,
                r.name             AS campaign_name,
                NULL               AS reply_step,
                l.step_number      AS followup_step,
                CASE WHEN ie.id IS NOT NULL THEN 'Read' ELSE 'Unread' END AS read_status,
                l.status           AS reply_status,
                l.sent_at          AS datetime
              FROM followup_logs l
              JOIN followup_rules r ON r.id = l.rule_id
              JOIN users u ON u.id = r.user_id
              LEFT JOIN inbound_emails ie ON LOWER(ie.from_email) = LOWER(l.email)
                    AND ie.received_at >= l.sent_at
              WHERE $fuOwn
                AND l.step_number = $step
                AND l.sent_at BETWEEN '$dtFrom' AND '$dtTo'
              ORDER BY l.sent_at DESC
              LIMIT $limit"
        )->fetchAll();
    }

    // ── Compute all stats ──────────────────────────────────────────
    $arStats = arStepStats($pdo, $isAdmin, $uid, $dtFrom, $dtTo, $arOwn);
    $fuStats = fuStepStats($pdo, $isAdmin, $uid, $dtFrom, $dtTo, $fuOwn);

    // ── Optional: step detail rows (when ?detail=ar&step=N or ?detail=fu&step=N) ──
    $detail = $_GET['detail'] ?? '';
    $detailStep = max(1, min(15, (int)($_GET['step'] ?? 1)));
    $tableRows = [];
    if ($detail === 'ar') {
        $tableRows = arStepTableRows($pdo, $isAdmin, $uid, $detailStep, $dtFrom, $dtTo, $arOwn);
    } elseif ($detail === 'fu') {
        $tableRows = fuStepTableRows($pdo, $isAdmin, $uid, $detailStep, $dtFrom, $dtTo, $fuOwn);
    }

    echo json_encode([
        'ok'           => true,
        'generated_at' => date('Y-m-d H:i:s'),
        'range_from'   => $dtFrom,
        'range_to'     => $dtTo,
        'ar_steps'     => $arStats,
        'fu_steps'     => $fuStats,
        'table_rows'   => $tableRows,
        'detail_type'  => $detail,
        'detail_step'  => $detailStep,
    ]);
    exit;
}

// ── Page render ────────────────────────────────────────────────────
$cfg      = getConfig();
$siteName = $cfg['site_name'] ?? 'MailsZo';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Step Analytics — <?= htmlspecialchars($siteName) ?></title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
/* ── CSS Variables (match existing dashboard palette) ─────────── */
:root{
  --bg:#070b12;--bg2:#0d1421;--bg3:#111827;--bg4:#161e2e;
  --border:#1e293b;--border2:#243044;
  --text:#e2eaf6;--text2:#94a3b8;--text3:#4e6283;
  --accent:#00ffc6;--accent-dim:rgba(0,255,198,.08);
  --blue:#38bdf8;--blue-dim:rgba(56,189,248,.08);
  --orange:#fb923c;--orange-dim:rgba(251,146,60,.08);
  --amber:#f59e0b;--red:#f87171;--purple:#a78bfa;
  --mono:'DM Mono',monospace;
  --r:12px;
}
*{box-sizing:border-box;margin:0;padding:0;}
html,body{height:100%;background:var(--bg);color:var(--text);font-family:'DM Sans','Inter',sans-serif;font-size:14px;line-height:1.5;}

/* ── Top bar ─────────────────────────────────────────────────── */
.topbar{
  position:sticky;top:0;z-index:100;
  background:rgba(7,11,18,.92);backdrop-filter:blur(12px);
  border-bottom:1px solid var(--border2);
  display:flex;align-items:center;gap:12px;padding:0 20px;height:52px;flex-wrap:wrap;
}
.topbar-logo{display:flex;align-items:center;gap:8px;font-family:var(--mono);font-size:13px;font-weight:700;color:var(--text);}
.live-dot{width:8px;height:8px;border-radius:50%;background:var(--accent);box-shadow:0 0 8px var(--accent);animation:pulse 1.5s infinite;}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
.topbar-sep{flex:1;}
.back-btn{
  display:inline-flex;align-items:center;gap:5px;
  padding:6px 14px;border-radius:7px;font-size:12px;font-weight:600;font-family:var(--mono);
  background:transparent;border:1px solid var(--border2);color:var(--text2);
  cursor:pointer;text-decoration:none;white-space:nowrap;
  transition:border-color .15s,color .15s;
}
.back-btn:hover{border-color:var(--accent);color:var(--accent);}

/* ── Refresh ring ────────────────────────────────────────────── */
.refresh-ring{display:flex;align-items:center;gap:6px;font-family:var(--mono);font-size:10px;color:var(--text3);}
.ring-wrap{position:relative;width:28px;height:28px;}
.ring-svg{width:28px;height:28px;transform:rotate(-90deg);}
.ring-track{stroke:var(--border2);}
.ring-fill{stroke:var(--accent);stroke-dasharray:75.4;stroke-dashoffset:0;transition:stroke-dashoffset .9s linear;}
.ring-label{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-family:var(--mono);font-size:8px;font-weight:700;color:var(--accent);}

/* ── Page layout ─────────────────────────────────────────────── */
.page{padding:24px 20px 60px;max-width:1500px;margin:0 auto;}
.page-title{font-family:var(--mono);font-size:18px;font-weight:700;color:var(--text);margin-bottom:4px;}
.page-sub{font-size:12px;color:var(--text3);margin-bottom:24px;}

/* ── Filter bar ──────────────────────────────────────────────── */
.filter-bar{
  background:var(--bg2);border:1px solid var(--border2);border-radius:var(--r);
  padding:14px 18px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;
  margin-bottom:26px;
}
.filter-label{font-family:var(--mono);font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.08em;white-space:nowrap;}
.range-btn{
  padding:5px 12px;border-radius:6px;font-size:11px;font-weight:600;font-family:var(--mono);
  background:transparent;border:1px solid var(--border2);color:var(--text3);cursor:pointer;
  transition:all .15s;white-space:nowrap;
}
.range-btn:hover,.range-btn.active{background:var(--accent-dim);border-color:var(--accent);color:var(--accent);}
.custom-inputs{display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
.custom-inputs input[type=date]{
  background:var(--bg3);border:1px solid var(--border2);border-radius:6px;
  color:var(--text);font-size:11px;font-family:var(--mono);padding:5px 9px;
}
.custom-inputs input[type=date]:focus{outline:none;border-color:var(--accent);}
.apply-btn{
  padding:5px 14px;border-radius:6px;font-size:11px;font-weight:700;font-family:var(--mono);
  background:var(--accent-dim);border:1px solid var(--accent);color:var(--accent);cursor:pointer;
  transition:background .15s;
}
.apply-btn:hover{background:rgba(0,255,198,.16);}
.sep-v{width:1px;height:22px;background:var(--border2);}

/* ── Section tabs ────────────────────────────────────────────── */
.section-tabs{display:flex;gap:8px;margin-bottom:24px;}
.stab{
  padding:8px 20px;border-radius:8px;font-size:12px;font-weight:700;font-family:var(--mono);
  background:var(--bg2);border:1px solid var(--border2);color:var(--text3);cursor:pointer;
  transition:all .15s;
}
.stab:hover,.stab.active-ar{background:var(--accent-dim);border-color:var(--accent);color:var(--accent);}
.stab.active-fu{background:var(--orange-dim);border-color:var(--orange);color:var(--orange);}

/* ── Step grid ───────────────────────────────────────────────── */
.step-section{display:none;}
.step-section.visible{display:block;}
.steps-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:16px;margin-bottom:30px;}

/* ── Step card ───────────────────────────────────────────────── */
.step-card{
  background:var(--bg2);border:1px solid var(--border2);border-radius:var(--r);
  overflow:hidden;cursor:pointer;transition:border-color .15s;
}
.step-card:hover{border-color:var(--accent);}
.step-card.fu-card:hover{border-color:var(--orange);}

.step-card-hd{
  padding:13px 16px;display:flex;align-items:center;gap:10px;
  border-bottom:1px solid var(--border);
  background:linear-gradient(90deg,rgba(0,255,198,.04) 0%,transparent 70%);
}
.step-card.fu-card .step-card-hd{background:linear-gradient(90deg,rgba(251,146,60,.04) 0%,transparent 70%);}
.step-badge{
  display:inline-flex;align-items:center;justify-content:center;
  width:32px;height:32px;border-radius:8px;font-family:var(--mono);font-size:13px;font-weight:700;flex-shrink:0;
  background:var(--accent-dim);border:1px solid rgba(0,255,198,.3);color:var(--accent);
}
.step-card.fu-card .step-badge{background:var(--orange-dim);border-color:rgba(251,146,60,.3);color:var(--orange);}
.step-card-title{font-family:var(--mono);font-size:12px;font-weight:700;color:var(--text);}
.step-card-sub{font-size:10px;color:var(--text3);margin-top:1px;}
.live-badge-sm{
  display:inline-flex;align-items:center;gap:4px;
  font-family:var(--mono);font-size:8px;font-weight:700;padding:2px 7px;
  border-radius:20px;background:rgba(0,255,198,.06);border:1px solid rgba(0,255,198,.18);
  color:var(--accent);text-transform:uppercase;margin-left:auto;
}
.live-badge-sm .ld{width:4px;height:4px;border-radius:50%;background:var(--accent);animation:pulse 1.4s infinite;}

/* ── Mini stat pills ─────────────────────────────────────────── */
.stat-pills{display:flex;gap:6px;padding:10px 16px;flex-wrap:wrap;border-bottom:1px solid var(--border);}
.s-pill{display:flex;flex-direction:column;align-items:center;padding:5px 10px;border-radius:7px;min-width:52px;}
.s-pill.sp-sent    {background:rgba(0,255,198,.06);border:1px solid rgba(0,255,198,.2);}
.s-pill.sp-failed  {background:rgba(248,113,113,.06);border:1px solid rgba(248,113,113,.2);}
.s-pill.sp-pending {background:rgba(245,158,11,.06);border:1px solid rgba(245,158,11,.2);}
.s-pill.sp-completed{background:rgba(56,189,248,.06);border:1px solid rgba(56,189,248,.2);}
.s-pill.sp-replies {background:rgba(167,139,250,.06);border:1px solid rgba(167,139,250,.2);}
.s-pill-val{font-family:var(--mono);font-size:14px;font-weight:700;line-height:1;}
.sp-sent .s-pill-val    {color:var(--accent);}
.sp-failed .s-pill-val  {color:var(--red);}
.sp-pending .s-pill-val {color:var(--amber);}
.sp-completed .s-pill-val{color:var(--blue);}
.sp-replies .s-pill-val {color:var(--purple);}
.s-pill-lbl{font-family:var(--mono);font-size:8px;color:var(--text3);text-transform:uppercase;letter-spacing:.06em;margin-top:3px;}

/* ── Mini chart ──────────────────────────────────────────────── */
.step-chart-wrap{padding:10px 14px 14px;height:90px;}

/* ── Detail modal ────────────────────────────────────────────── */
#detail-modal-bg{
  display:none;position:fixed;inset:0;background:rgba(0,0,0,.8);z-index:999;
  align-items:flex-start;justify-content:center;padding:24px 16px;
  overflow-y:auto;
}
#detail-modal-bg.open{display:flex;}
.detail-modal{
  background:var(--bg2);border:1px solid var(--border2);border-radius:var(--r);
  width:100%;max-width:1100px;max-height:90vh;overflow:hidden;display:flex;flex-direction:column;
  animation:mza .18s;
}
@keyframes mza{from{opacity:0;transform:translateY(-12px)}to{opacity:1;transform:translateY(0)}}
.detail-modal-hd{
  padding:16px 20px;border-bottom:1px solid var(--border2);
  display:flex;align-items:center;gap:12px;flex-shrink:0;
}
.detail-modal-title{font-family:var(--mono);font-size:14px;font-weight:700;color:var(--text);flex:1;}
.close-btn{
  width:30px;height:30px;border-radius:7px;border:1px solid var(--border2);
  background:transparent;color:var(--text3);cursor:pointer;font-size:16px;
  display:flex;align-items:center;justify-content:center;
}
.close-btn:hover{border-color:var(--red);color:var(--red);}
.detail-modal-body{overflow-y:auto;flex:1;}

/* ── Detail table ────────────────────────────────────────────── */
.dt-wrap{overflow-x:auto;}
.dt{width:100%;border-collapse:collapse;font-size:12px;}
.dt th{
  position:sticky;top:0;z-index:5;background:var(--bg3);
  padding:11px 14px;text-align:left;white-space:nowrap;
  font-family:var(--mono);font-size:9px;font-weight:700;color:var(--text3);
  text-transform:uppercase;letter-spacing:.1em;border-bottom:1px solid var(--border2);
}
.dt td{padding:10px 14px;border-top:1px solid var(--border);color:var(--text2);vertical-align:middle;}
.dt tr:hover td{background:rgba(255,255,255,.01);}
.dt .er td{text-align:center;padding:50px;color:var(--text3);font-family:var(--mono);font-size:11px;}
.status-sent{display:inline-flex;align-items:center;gap:4px;font-family:var(--mono);font-size:10px;font-weight:700;padding:3px 9px;border-radius:20px;background:rgba(0,255,198,.08);border:1px solid rgba(0,255,198,.25);color:var(--accent);}
.status-failed{display:inline-flex;align-items:center;gap:4px;font-family:var(--mono);font-size:10px;font-weight:700;padding:3px 9px;border-radius:20px;background:rgba(248,113,113,.08);border:1px solid rgba(248,113,113,.25);color:var(--red);}
.read-pill{font-family:var(--mono);font-size:10px;font-weight:700;padding:3px 9px;border-radius:20px;}
.rp-read{background:rgba(56,189,248,.08);border:1px solid rgba(56,189,248,.25);color:var(--blue);}
.rp-unread{background:rgba(78,98,131,.08);border:1px solid var(--border2);color:var(--text3);}
.step-num{font-family:var(--mono);font-size:12px;font-weight:700;color:var(--accent);}
.fu-card .step-num{color:var(--orange);}
.user-mono{font-family:var(--mono);font-size:11px;color:var(--text);}
.camp-name{font-family:var(--mono);font-size:11px;color:var(--text2);max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.dt-time{font-family:var(--mono);font-size:10px;color:var(--text3);white-space:nowrap;}

/* ── Status bar at bottom of modal ──────────────────────────── */
.detail-modal-foot{
  padding:10px 20px;border-top:1px solid var(--border);flex-shrink:0;
  font-family:var(--mono);font-size:10px;color:var(--text3);
  display:flex;align-items:center;justify-content:space-between;gap:12px;
}

/* ── Loading indicator ───────────────────────────────────────── */
.loader{display:flex;align-items:center;justify-content:center;padding:60px;color:var(--text3);font-family:var(--mono);font-size:12px;gap:10px;}
.spin{width:16px;height:16px;border:2px solid var(--border2);border-top-color:var(--accent);border-radius:50%;animation:spin .6s linear infinite;}
@keyframes spin{to{transform:rotate(360deg)}}

/* ── Empty state ─────────────────────────────────────────────── */
.empty-state{text-align:center;padding:60px 20px;color:var(--text3);font-family:var(--mono);font-size:12px;}
.empty-state .ei{font-size:32px;margin-bottom:12px;}

/* ── Update bar ──────────────────────────────────────────────── */
.update-bar{text-align:center;font-family:var(--mono);font-size:10px;color:var(--text3);padding:14px;margin-top:6px;}
.update-bar span{color:var(--accent);}

/* ── Range info ──────────────────────────────────────────────── */
.range-info{
  background:rgba(0,255,198,.025);border:1px solid rgba(0,255,198,.1);border-radius:8px;
  padding:8px 14px;font-family:var(--mono);font-size:10px;color:var(--text3);
  display:inline-flex;align-items:center;gap:8px;margin-bottom:22px;
}
.range-info strong{color:var(--accent);}

/* ── Summary overview row ────────────────────────────────────── */
.summary-row{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:24px;}
.sum-card{
  flex:1;min-width:140px;padding:14px 18px;background:var(--bg2);
  border:1px solid var(--border2);border-radius:var(--r);
}
.sum-val{font-family:var(--mono);font-size:22px;font-weight:700;color:var(--accent);}
.sum-card.fu-sum .sum-val{color:var(--orange);}
.sum-lbl{font-size:11px;color:var(--text3);margin-top:4px;}

</style>
</head>
<body>

<!-- ── Top bar ─────────────────────────────────────────────────── -->
<div class="topbar">
  <div class="topbar-logo">
    <div class="live-dot"></div>
    <span><?= htmlspecialchars($siteName) ?></span>
  </div>
  <span style="font-size:11px;color:var(--text3);font-family:var(--mono);">Step Analytics — Auto Reply &amp; Follow-Up</span>
  <div class="topbar-sep"></div>
  <div class="refresh-ring">
    <div class="ring-wrap">
      <svg class="ring-svg" viewBox="0 0 28 28">
        <circle class="ring-track" cx="14" cy="14" r="12" fill="none" stroke-width="2.5"/>
        <circle class="ring-fill" id="ring" cx="14" cy="14" r="12" fill="none" stroke-width="2.5" stroke-linecap="round"/>
      </svg>
      <div class="ring-label" id="ring-sec">30</div>
    </div>
    <span>auto-refresh</span>
  </div>
  <a href="dashboard.php" class="back-btn">📊 Dashboard</a>
  <a href="index.php" class="back-btn">← Main</a>
</div>

<!-- ── Page ────────────────────────────────────────────────────── -->
<div class="page">
  <div class="page-title">📈 Step-Wise Analytics</div>
  <div class="page-sub">Real-time per-step charts and reports for Auto Reply and Follow-Up sequences — auto-refreshes every 30 seconds.</div>

  <!-- ── Filter bar ─────────────────────────────────────────────── -->
  <div class="filter-bar">
    <span class="filter-label">Range:</span>
    <button class="range-btn active" data-range="today"      onclick="setRange('today',this)">Today</button>
    <button class="range-btn"        data-range="yesterday"  onclick="setRange('yesterday',this)">Yesterday</button>
    <button class="range-btn"        data-range="7d"         onclick="setRange('7d',this)">Last 7 Days</button>
    <button class="range-btn"        data-range="15d"        onclick="setRange('15d',this)">Last 15 Days</button>
    <button class="range-btn"        data-range="this_month" onclick="setRange('this_month',this)">This Month</button>
    <button class="range-btn"        data-range="last_month" onclick="setRange('last_month',this)">Last Month</button>
    <div class="sep-v"></div>
    <button class="range-btn"        data-range="custom"     onclick="setRange('custom',this)">Custom Date</button>
    <div class="custom-inputs" id="custom-inputs" style="display:none;">
      <input type="date" id="custom-from" placeholder="From">
      <span style="font-size:11px;color:var(--text3);">→</span>
      <input type="date" id="custom-to"   placeholder="To">
      <button class="apply-btn" onclick="applyCustom()">Apply</button>
    </div>
    <div class="topbar-sep"></div>
    <div class="range-info" id="range-info-bar" style="margin-bottom:0;font-size:10px;">
      <strong id="range-label-top">Today</strong>
      <span id="range-dates-top" style="color:var(--text3);"></span>
    </div>
  </div>

  <!-- ── Section tabs ───────────────────────────────────────────── -->
  <div class="section-tabs">
    <button class="stab active-ar" id="tab-ar" onclick="showSection('ar')">⚡ Auto Reply Steps</button>
    <button class="stab"           id="tab-fu" onclick="showSection('fu')">📬 Follow-Up Steps</button>
  </div>

  <!-- ── Auto Reply section ──────────────────────────────────────── -->
  <div class="step-section visible" id="section-ar">
    <div id="ar-summary" class="summary-row"></div>
    <div id="ar-range-info" class="range-info" style="margin-bottom:20px;">
      Loading…
    </div>
    <div class="steps-grid" id="ar-grid">
      <div class="loader"><div class="spin"></div> Loading Auto Reply step data…</div>
    </div>
  </div>

  <!-- ── Follow-Up section ───────────────────────────────────────── -->
  <div class="step-section" id="section-fu">
    <div id="fu-summary" class="summary-row"></div>
    <div id="fu-range-info" class="range-info" style="margin-bottom:20px;">
      Loading…
    </div>
    <div class="steps-grid" id="fu-grid">
      <div class="loader"><div class="spin"></div> Loading Follow-Up step data…</div>
    </div>
  </div>

  <div class="update-bar">Last updated: <span id="last-updated">—</span></div>
</div>

<!-- ── Detail modal ────────────────────────────────────────────── -->
<div id="detail-modal-bg" onclick="closeModal(event)">
  <div class="detail-modal" id="detail-modal">
    <div class="detail-modal-hd">
      <div class="detail-modal-title" id="modal-title">Step Detail</div>
      <button class="close-btn" onclick="closeModalDirect()">✕</button>
    </div>
    <div class="detail-modal-body" id="modal-body">
      <div class="loader"><div class="spin"></div> Loading…</div>
    </div>
    <div class="detail-modal-foot">
      <span id="modal-range-info" style="color:var(--text3);"></span>
      <span id="modal-row-count" style="color:var(--text3);"></span>
    </div>
  </div>
</div>

<script>
// ═══════════════════════════════════════════════════════════════════
//  State
// ═══════════════════════════════════════════════════════════════════
const REFRESH = 30;
let countdown = REFRESH;
let currentRange = 'today';
let customFrom = '', customTo = '';
let arData = [], fuData = [];
let arCharts = {}, fuCharts = {};
let currentSection = 'ar';
let activeModal = null; // {type:'ar'|'fu', step:N}

// ═══════════════════════════════════════════════════════════════════
//  Utilities
// ═══════════════════════════════════════════════════════════════════
function esc(s){ const d=document.createElement('div');d.textContent=s??'';return d.innerHTML; }
function fmtDT(s){
  if(!s) return '—';
  const d=new Date(s.replace(' ','T'));
  return isNaN(d)?s:d.toLocaleString(undefined,{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});
}
const ordinals=['','1st','2nd','3rd','4th','5th','6th','7th','8th','9th','10th',
                '11th','12th','13th','14th','15th'];
function ord(n){ return ordinals[n] || n+'th'; }

// ═══════════════════════════════════════════════════════════════════
//  Refresh ring
// ═══════════════════════════════════════════════════════════════════
function updateRing(){
  const frac=countdown/REFRESH;
  const circ=75.4;
  const el=document.getElementById('ring');
  const lb=document.getElementById('ring-sec');
  if(el) el.style.strokeDashoffset=circ-(circ*frac);
  if(lb) lb.textContent=countdown;
}

// ═══════════════════════════════════════════════════════════════════
//  Date range controls
// ═══════════════════════════════════════════════════════════════════
const rangeLabels={today:'Today',yesterday:'Yesterday','7d':'Last 7 Days','15d':'Last 15 Days',this_month:'This Month',last_month:'Last Month',custom:'Custom'};

function setRange(r, btn){
  currentRange = r;
  document.querySelectorAll('.range-btn').forEach(b=>b.classList.remove('active'));
  if(btn) btn.classList.add('active');
  const ci=document.getElementById('custom-inputs');
  if(ci) ci.style.display = r==='custom'?'flex':'none';
  updateRangeLabelTop();
  if(r!=='custom') fetchAll();
}

function applyCustom(){
  customFrom = document.getElementById('custom-from').value;
  customTo   = document.getElementById('custom-to').value;
  if(!customFrom||!customTo){ alert('Please select both From and To dates.'); return; }
  fetchAll();
}

function buildApiUrl(extra=''){
  let url=`step_analytics.php?api=1&range=${encodeURIComponent(currentRange)}`;
  if(currentRange==='custom'){
    url+=`&date_from=${encodeURIComponent(customFrom)}&date_to=${encodeURIComponent(customTo)}`;
  }
  return url+extra+'&_='+Date.now();
}

function updateRangeLabelTop(){
  const lb=document.getElementById('range-label-top');
  if(lb) lb.textContent=rangeLabels[currentRange]||currentRange;
}

// ═══════════════════════════════════════════════════════════════════
//  Section tabs
// ═══════════════════════════════════════════════════════════════════
function showSection(s){
  currentSection=s;
  document.getElementById('section-ar').classList.toggle('visible',s==='ar');
  document.getElementById('section-fu').classList.toggle('visible',s==='fu');
  document.getElementById('tab-ar').className='stab'+(s==='ar'?' active-ar':'');
  document.getElementById('tab-fu').className='stab'+(s==='fu'?' active-fu':'');
}

// ═══════════════════════════════════════════════════════════════════
//  Fetch all step stats
// ═══════════════════════════════════════════════════════════════════
async function fetchAll(){
  try{
    const res=await fetch(buildApiUrl());
    if(!res.ok) throw new Error('HTTP '+res.status);
    const d=await res.json();
    if(d.error==='session_expired'){window.top?window.top.location.reload():window.location.reload();return;}
    if(!d.ok) throw new Error('API error');

    arData=d.ar_steps||[];
    fuData=d.fu_steps||[];

    // Range info
    const ri=`<strong>${rangeLabels[currentRange]||currentRange}</strong> — ${esc(d.range_from.slice(0,10))} → ${esc(d.range_to.slice(0,10))}`;
    ['ar-range-info','fu-range-info'].forEach(id=>{const el=document.getElementById(id);if(el)el.innerHTML=ri;});
    const rd=document.getElementById('range-dates-top');
    if(rd) rd.textContent=`${d.range_from.slice(0,10)} → ${d.range_to.slice(0,10)}`;

    renderARSection(arData);
    renderFUSection(fuData);

    const ts=new Date(d.generated_at.replace(' ','T')).toLocaleTimeString();
    const lu=document.getElementById('last-updated');if(lu)lu.textContent=ts;

    // If modal is open, refresh it too
    if(activeModal){
      loadModalData(activeModal.type, activeModal.step, false);
    }

  }catch(e){ console.error('Step analytics fetch error:',e); }
}

// ═══════════════════════════════════════════════════════════════════
//  Render AR section
// ═══════════════════════════════════════════════════════════════════
function renderARSection(steps){
  // Summary totals
  let tSent=0,tFailed=0,tPending=0,tCompleted=0,tReplies=0;
  steps.forEach(s=>{tSent+=s.sent;tFailed+=s.failed;tPending+=s.pending;tCompleted+=s.completed;tReplies+=s.replies_received;});
  document.getElementById('ar-summary').innerHTML=`
    <div class="sum-card"><div class="sum-val">${tSent}</div><div class="sum-lbl">Total Sent (All AR Steps)</div></div>
    <div class="sum-card"><div class="sum-val" style="color:var(--red)">${tFailed}</div><div class="sum-lbl">Total Failed</div></div>
    <div class="sum-card"><div class="sum-val" style="color:var(--amber)">${tPending}</div><div class="sum-lbl">Total Pending</div></div>
    <div class="sum-card"><div class="sum-val" style="color:var(--blue)">${tCompleted}</div><div class="sum-lbl">Total Completed</div></div>
    <div class="sum-card"><div class="sum-val" style="color:var(--purple)">${tReplies}</div><div class="sum-lbl">Total Replies Received</div></div>
  `;

  // Only show steps that have at least some data OR the first 5 steps always
  const grid=document.getElementById('ar-grid');
  grid.innerHTML='';
  let hasAny=false;
  steps.forEach((s,idx)=>{
    const hasData=s.sent||s.failed||s.pending||s.completed||s.replies_received;
    if(!hasData && idx>=5) return;
    hasAny=true;
    grid.appendChild(buildStepCard(s,'ar'));
  });
  if(!hasAny){
    grid.innerHTML=`<div class="empty-state"><div class="ei">⚡</div>No Auto Reply step activity in this range yet.</div>`;
  }
}

// ═══════════════════════════════════════════════════════════════════
//  Render FU section
// ═══════════════════════════════════════════════════════════════════
function renderFUSection(steps){
  let tSent=0,tFailed=0,tPending=0,tCompleted=0,tReplies=0;
  steps.forEach(s=>{tSent+=s.sent;tFailed+=s.failed;tPending+=s.pending;tCompleted+=s.completed;tReplies+=s.fu_replies_received||0;});
  document.getElementById('fu-summary').innerHTML=`
    <div class="sum-card fu-sum"><div class="sum-val">${tSent}</div><div class="sum-lbl">Total Sent (All FU Steps)</div></div>
    <div class="sum-card fu-sum"><div class="sum-val" style="color:var(--red)">${tFailed}</div><div class="sum-lbl">Total Failed</div></div>
    <div class="sum-card fu-sum"><div class="sum-val" style="color:var(--amber)">${tPending}</div><div class="sum-lbl">Total Pending</div></div>
    <div class="sum-card fu-sum"><div class="sum-val" style="color:var(--blue)">${tCompleted}</div><div class="sum-lbl">Total Completed</div></div>
    <div class="sum-card fu-sum"><div class="sum-val" style="color:var(--purple)">${tReplies}</div><div class="sum-lbl">Total Replies Received</div></div>
  `;

  const grid=document.getElementById('fu-grid');
  grid.innerHTML='';
  let hasAny=false;
  steps.forEach((s,idx)=>{
    const hasData=s.sent||s.failed||s.pending||s.completed||(s.fu_replies_received||0);
    if(!hasData && idx>=5) return;
    hasAny=true;
    grid.appendChild(buildStepCard(s,'fu'));
  });
  if(!hasAny){
    grid.innerHTML=`<div class="empty-state"><div class="ei">📬</div>No Follow-Up step activity in this range yet.</div>`;
  }
}

// ═══════════════════════════════════════════════════════════════════
//  Build a step card with a mini bar chart
// ═══════════════════════════════════════════════════════════════════
function buildStepCard(s, type){
  const isFu = type==='fu';
  const step  = s.step;
  const replies = isFu ? (s.fu_replies_received||0) : (s.replies_received||0);
  const label = isFu ? `Follow-Up ${ord(step)}` : `Auto Reply ${ord(step)}`;
  const typeLabel = isFu ? 'Follow-Up Step' : 'Auto-Reply Step';
  const chartId = `chart-${type}-${step}`;
  const clickFn = `openModal('${type}',${step})`;

  const card = document.createElement('div');
  card.className = 'step-card'+(isFu?' fu-card':'');
  card.setAttribute('onclick', clickFn);
  card.setAttribute('title', `Click to view ${label} detail report`);
  card.innerHTML = `
    <div class="step-card-hd">
      <div class="step-badge">${step}</div>
      <div>
        <div class="step-card-title">${esc(label)}</div>
        <div class="step-card-sub">${esc(typeLabel)} · click for detail report</div>
      </div>
      <div class="live-badge-sm"><div class="ld"></div>Live</div>
    </div>
    <div class="stat-pills">
      <div class="s-pill sp-sent">
        <div class="s-pill-val">${s.sent}</div>
        <div class="s-pill-lbl">Sent</div>
      </div>
      <div class="s-pill sp-failed">
        <div class="s-pill-val">${s.failed}</div>
        <div class="s-pill-lbl">Failed</div>
      </div>
      <div class="s-pill sp-pending">
        <div class="s-pill-val">${s.pending}</div>
        <div class="s-pill-lbl">Pending</div>
      </div>
      <div class="s-pill sp-completed">
        <div class="s-pill-val">${s.completed}</div>
        <div class="s-pill-lbl">Completed</div>
      </div>
      <div class="s-pill sp-replies">
        <div class="s-pill-val">${replies}</div>
        <div class="s-pill-lbl">Replies</div>
      </div>
    </div>
    <div class="step-chart-wrap">
      <canvas id="${chartId}" style="width:100%;height:68px;"></canvas>
    </div>
  `;

  // Build chart after DOM insertion (requestAnimationFrame)
  setTimeout(()=>{
    const canvas=document.getElementById(chartId);
    if(!canvas) return;
    const existingKey = chartId;
    if(arCharts[existingKey]) arCharts[existingKey].destroy();
    const accent = isFu ? '#fb923c' : '#00ffc6';
    const accentFade = isFu ? 'rgba(251,146,60,.15)' : 'rgba(0,255,198,.15)';
    arCharts[existingKey] = new Chart(canvas.getContext('2d'),{
      type:'bar',
      data:{
        labels:['Sent','Failed','Pending','Completed','Replies'],
        datasets:[{
          data:[s.sent, s.failed, s.pending, s.completed, replies],
          backgroundColor:[
            'rgba(0,255,198,.25)',
            'rgba(248,113,113,.25)',
            'rgba(245,158,11,.25)',
            'rgba(56,189,248,.25)',
            'rgba(167,139,250,.25)',
          ],
          borderColor:[
            'rgba(0,255,198,.7)',
            'rgba(248,113,113,.7)',
            'rgba(245,158,11,.7)',
            'rgba(56,189,248,.7)',
            'rgba(167,139,250,.7)',
          ],
          borderWidth:1,
          borderRadius:4,
        }]
      },
      options:{
        responsive:true,
        maintainAspectRatio:false,
        animation:false,
        plugins:{legend:{display:false},tooltip:{
          callbacks:{label:ctx=>`${ctx.label}: ${ctx.parsed.y}`},
          backgroundColor:'#0d1421',borderColor:'#243044',borderWidth:1,
          titleColor:'#e2eaf6',bodyColor:'#94a3b8',titleFont:{family:'DM Mono,monospace',size:11},bodyFont:{family:'DM Mono,monospace',size:10},
        }},
        scales:{
          x:{ticks:{color:'#4e6283',font:{family:'DM Mono,monospace',size:8}},grid:{color:'rgba(30,41,59,.4)'},border:{display:false}},
          y:{ticks:{color:'#4e6283',font:{family:'DM Mono,monospace',size:8},maxTicksLimit:4},grid:{color:'rgba(30,41,59,.4)'},border:{display:false},beginAtZero:true},
        },
      }
    });
  },0);

  return card;
}

// ═══════════════════════════════════════════════════════════════════
//  Modal — open / close / load data
// ═══════════════════════════════════════════════════════════════════
function openModal(type, step){
  activeModal = {type, step};
  const isFu = type==='fu';
  const label = isFu ? `Follow-Up — ${ord(step)} Step — Detail Report` : `Auto Reply — ${ord(step)} Step — Detail Report`;
  document.getElementById('modal-title').textContent = label;
  document.getElementById('modal-body').innerHTML = '<div class="loader"><div class="spin"></div> Loading…</div>';
  document.getElementById('detail-modal-bg').classList.add('open');
  loadModalData(type, step, true);
}

function closeModal(e){
  if(e.target===document.getElementById('detail-modal-bg')) closeModalDirect();
}
function closeModalDirect(){
  document.getElementById('detail-modal-bg').classList.remove('open');
  activeModal = null;
}

async function loadModalData(type, step, showLoader){
  if(showLoader) document.getElementById('modal-body').innerHTML='<div class="loader"><div class="spin"></div> Loading…</div>';
  try{
    const url = buildApiUrl(`&detail=${encodeURIComponent(type)}&step=${step}`);
    const res = await fetch(url);
    const d   = await res.json();
    if(!d.ok) throw new Error('API error');

    const rows = d.table_rows || [];
    const isFu = type==='fu';

    document.getElementById('modal-row-count').textContent = `${rows.length} rows (max 200 per load)`;
    document.getElementById('modal-range-info').textContent = `${d.range_from.slice(0,10)} → ${d.range_to.slice(0,10)}`;

    if(!rows.length){
      document.getElementById('modal-body').innerHTML=`<div class="empty-state"><div class="ei">${isFu?'📬':'⚡'}</div>No data for this step in the selected range.</div>`;
      return;
    }

    const stepCol = isFu
      ? `<th>Follow-Up Step</th>`
      : `<th>Reply Step</th>`;

    const rowsHtml = rows.map(r=>{
      const stepVal = isFu ? (r.followup_step||'—') : (r.reply_step||'—');
      const readCls = r.read_status==='Read' ? 'rp-read' : 'rp-unread';
      const statusCls = r.reply_status==='sent' ? 'status-sent' : 'status-failed';
      return `<tr>
        <td><span class="user-mono">${esc(r.user_id||'—')}</span></td>
        <td><span class="camp-name" title="${esc(r.campaign_name||'')}">${esc(r.campaign_name||'—')}</span></td>
        <td><span class="step-num${isFu?' fu-card':''}">${esc(String(stepVal))}</span></td>
        <td><span class="${readCls} read-pill">${esc(r.read_status||'—')}</span></td>
        <td><span class="${statusCls}">${esc(r.reply_status||'—')}</span></td>
        <td><span class="dt-time">${esc(fmtDT(r.datetime))}</span></td>
      </tr>`;
    }).join('');

    document.getElementById('modal-body').innerHTML=`
      <div class="dt-wrap">
        <table class="dt">
          <thead><tr>
            <th>User ID</th>
            <th>Campaign Name</th>
            ${stepCol}
            <th>Read Status</th>
            <th>Reply Status</th>
            <th>Date &amp; Time</th>
          </tr></thead>
          <tbody>${rowsHtml}</tbody>
        </table>
      </div>`;
  }catch(e){
    document.getElementById('modal-body').innerHTML=`<div class="empty-state"><div class="ei">⚠️</div>Error loading data. Please try again.</div>`;
    console.error('Modal load error:',e);
  }
}

// ═══════════════════════════════════════════════════════════════════
//  Boot + refresh loop
// ═══════════════════════════════════════════════════════════════════
updateRangeLabelTop();
fetchAll();

setInterval(()=>{
  countdown--;
  updateRing();
  if(countdown<=0){
    countdown=REFRESH;
    fetchAll();
  }
},1000);
</script>
</body>
</html>
