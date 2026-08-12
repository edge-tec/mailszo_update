<?php
/**
 * Email Leads Statistics API
 * Provides real-time lead analytics for both Admin and User dashboards.
 * 
 * GET params:
 *   filter   = today | yesterday | last7 | last30 | alltime  (default: today)
 *   view     = summary | server_wise | user_wise | chart     (default: summary)
 */
require_once __DIR__ . '/includes/config.php';
if (!isInstalled()) { header('HTTP/1.1 503 Service Unavailable'); exit; }
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

// Auth check
if (empty($_SESSION['uid'])) {
    echo json_encode(['ok' => false, 'error' => 'session_expired']);
    exit;
}

$uid     = (int)$_SESSION['uid'];
$isAdmin = !empty($_SESSION['is_admin']);
$pdo     = db();

// ── Date range builder ────────────────────────────────────────────
$filter = $_GET['filter'] ?? 'today';
$view   = $_GET['view']   ?? 'summary';
$now    = new DateTime();

switch ($filter) {
    case 'yesterday':
        $from = (clone $now)->modify('-1 day')->format('Y-m-d') . ' 00:00:00';
        $to   = (clone $now)->modify('-1 day')->format('Y-m-d') . ' 23:59:59';
        break;
    case 'last7':
        $from = (clone $now)->modify('-6 days')->format('Y-m-d') . ' 00:00:00';
        $to   = $now->format('Y-m-d') . ' 23:59:59';
        break;
    case 'last30':
        $from = (clone $now)->modify('-29 days')->format('Y-m-d') . ' 00:00:00';
        $to   = $now->format('Y-m-d') . ' 23:59:59';
        break;
    case 'alltime':
        $from = null;
        $to   = null;
        break;
    default: // today
        $from = $now->format('Y-m-d') . ' 00:00:00';
        $to   = $now->format('Y-m-d') . ' 23:59:59';
        break;
}

$today     = $now->format('Y-m-d');
$weekStart = (clone $now)->modify('monday this week')->format('Y-m-d') . ' 00:00:00';
$monthStart= $now->format('Y-m-') . '01 00:00:00';

// Range snippet for WHERE clauses
$rangeSnip  = ($from && $to) ? " AND ie.received_at BETWEEN '$from' AND '$to'" : '';

// ════════════════════════════════════════════════════════════════════
//  HELPER — Base subquery restriction by user (non-admin)
// ════════════════════════════════════════════════════════════════════
// For non-admin, inbound_emails are only accessible via their own imap_accounts.
function userImapJoin(bool $isAdmin, int $uid): string {
    if ($isAdmin) return '';
    return " JOIN imap_accounts ia2 ON ia2.id = ie.imap_account_id AND ia2.user_id = $uid";
}

// ════════════════════════════════════════════════════════════════════
//  SUMMARY STATS
// ════════════════════════════════════════════════════════════════════
$resp = ['ok' => true, 'filter' => $filter, 'generated_at' => date('Y-m-d H:i:s')];

if ($view === 'summary' || $view === 'all') {
    $uJoin = userImapJoin($isAdmin, $uid);

    // Total all-time
    $q = $pdo->prepare("SELECT COUNT(*) FROM inbound_emails ie $uJoin WHERE 1=1" . ($isAdmin ? '' : ''));
    if (!$isAdmin) {
        $q = $pdo->prepare("SELECT COUNT(*) FROM inbound_emails ie JOIN imap_accounts ia2 ON ia2.id=ie.imap_account_id WHERE ia2.user_id=?");
        $q->execute([$uid]);
    } else {
        $q = $pdo->query("SELECT COUNT(*) FROM inbound_emails");
    }
    $totalAllTime = (int)$q->fetchColumn();

    // Today
    if (!$isAdmin) {
        $q = $pdo->prepare("SELECT COUNT(*) FROM inbound_emails ie JOIN imap_accounts ia2 ON ia2.id=ie.imap_account_id WHERE ia2.user_id=? AND DATE(ie.received_at)=?");
        $q->execute([$uid, $today]);
    } else {
        $q = $pdo->prepare("SELECT COUNT(*) FROM inbound_emails WHERE DATE(received_at)=?");
        $q->execute([$today]);
    }
    $totalToday = (int)$q->fetchColumn();

    // This week
    if (!$isAdmin) {
        $q = $pdo->prepare("SELECT COUNT(*) FROM inbound_emails ie JOIN imap_accounts ia2 ON ia2.id=ie.imap_account_id WHERE ia2.user_id=? AND ie.received_at >= ?");
        $q->execute([$uid, $weekStart]);
    } else {
        $q = $pdo->prepare("SELECT COUNT(*) FROM inbound_emails WHERE received_at >= ?");
        $q->execute([$weekStart]);
    }
    $totalWeek = (int)$q->fetchColumn();

    // This month
    if (!$isAdmin) {
        $q = $pdo->prepare("SELECT COUNT(*) FROM inbound_emails ie JOIN imap_accounts ia2 ON ia2.id=ie.imap_account_id WHERE ia2.user_id=? AND ie.received_at >= ?");
        $q->execute([$uid, $monthStart]);
    } else {
        $q = $pdo->prepare("SELECT COUNT(*) FROM inbound_emails WHERE received_at >= ?");
        $q->execute([$monthStart]);
    }
    $totalMonth = (int)$q->fetchColumn();

    // Range-filtered total (for the active filter)
    if ($from && $to) {
        if (!$isAdmin) {
            $q = $pdo->prepare("SELECT COUNT(*) FROM inbound_emails ie JOIN imap_accounts ia2 ON ia2.id=ie.imap_account_id WHERE ia2.user_id=? AND ie.received_at BETWEEN ? AND ?");
            $q->execute([$uid, $from, $to]);
        } else {
            $q = $pdo->prepare("SELECT COUNT(*) FROM inbound_emails WHERE received_at BETWEEN ? AND ?");
            $q->execute([$from, $to]);
        }
        $totalFiltered = (int)$q->fetchColumn();
    } else {
        $totalFiltered = $totalAllTime;
    }

    // Active IMAP servers count
    if (!$isAdmin) {
        $q = $pdo->prepare("SELECT COUNT(*) FROM imap_accounts WHERE user_id=? AND status='active'");
        $q->execute([$uid]);
    } else {
        $q = $pdo->query("SELECT COUNT(*) FROM imap_accounts WHERE status='active'");
    }
    $activeServers = (int)$q->fetchColumn();

    // Yesterday total for growth calc
    $yesterday = (clone $now)->modify('-1 day')->format('Y-m-d');
    if (!$isAdmin) {
        $q = $pdo->prepare("SELECT COUNT(*) FROM inbound_emails ie JOIN imap_accounts ia2 ON ia2.id=ie.imap_account_id WHERE ia2.user_id=? AND DATE(ie.received_at)=?");
        $q->execute([$uid, $yesterday]);
    } else {
        $q = $pdo->prepare("SELECT COUNT(*) FROM inbound_emails WHERE DATE(received_at)=?");
        $q->execute([$yesterday]);
    }
    $totalYesterday = (int)$q->fetchColumn();

    $dayGrowth = $totalYesterday > 0
        ? round((($totalToday - $totalYesterday) / $totalYesterday) * 100, 1)
        : ($totalToday > 0 ? 100.0 : 0.0);

    // ── Pipeline stats: pending & processed ──────────────────────
    $pendingTotal = 0; $replyPending = 0; $followupPending = 0; $processedTotal = 0;

    // Auto-reply pending
    try {
        if (!$isAdmin) {
            $q2 = $pdo->prepare("SELECT COUNT(*) FROM inbound_emails ie JOIN imap_accounts ia2 ON ia2.id=ie.imap_account_id WHERE ia2.user_id=? AND (ie.auto_replied IS NULL OR ie.auto_replied=0)");
            $q2->execute([$uid]);
        } else {
            $q2 = $pdo->query("SELECT COUNT(*) FROM inbound_emails WHERE (auto_replied IS NULL OR auto_replied=0)");
        }
        $replyPending = (int)$q2->fetchColumn();
    } catch (\Exception $e) { $replyPending = 0; }

    // Follow-up pending
    try {
        if (!$isAdmin) {
            $q2 = $pdo->prepare("SELECT COUNT(*) FROM inbound_emails ie JOIN imap_accounts ia2 ON ia2.id=ie.imap_account_id WHERE ia2.user_id=? AND (ie.followup_sent IS NULL OR ie.followup_sent=0)");
            $q2->execute([$uid]);
        } else {
            $q2 = $pdo->query("SELECT COUNT(*) FROM inbound_emails WHERE (followup_sent IS NULL OR followup_sent=0)");
        }
        $followupPending = (int)$q2->fetchColumn();
    } catch (\Exception $e) { $followupPending = 0; }

    // Total pending: neither auto-reply nor followup done
    try {
        if (!$isAdmin) {
            $q2 = $pdo->prepare("SELECT COUNT(*) FROM inbound_emails ie JOIN imap_accounts ia2 ON ia2.id=ie.imap_account_id WHERE ia2.user_id=? AND (ie.auto_replied IS NULL OR ie.auto_replied=0) AND (ie.followup_sent IS NULL OR ie.followup_sent=0)");
            $q2->execute([$uid]);
        } else {
            $q2 = $pdo->query("SELECT COUNT(*) FROM inbound_emails WHERE (auto_replied IS NULL OR auto_replied=0) AND (followup_sent IS NULL OR followup_sent=0)");
        }
        $pendingTotal = (int)$q2->fetchColumn();
    } catch (\Exception $e) { $pendingTotal = max($replyPending, $followupPending); }

    // Total processed: at least one action done
    try {
        if (!$isAdmin) {
            $q2 = $pdo->prepare("SELECT COUNT(*) FROM inbound_emails ie JOIN imap_accounts ia2 ON ia2.id=ie.imap_account_id WHERE ia2.user_id=? AND (ie.auto_replied=1 OR ie.followup_sent=1)");
            $q2->execute([$uid]);
        } else {
            $q2 = $pdo->query("SELECT COUNT(*) FROM inbound_emails WHERE (auto_replied=1 OR followup_sent=1)");
        }
        $processedTotal = (int)$q2->fetchColumn();
    } catch (\Exception $e) { $processedTotal = max(0, $totalAllTime - $pendingTotal); }

    $resp['summary'] = [
        'total_alltime'    => $totalAllTime,
        'total_today'      => $totalToday,
        'total_yesterday'  => $totalYesterday,
        'total_week'       => $totalWeek,
        'total_month'      => $totalMonth,
        'total_filtered'   => $totalFiltered,
        'active_servers'   => $activeServers,
        'day_growth_pct'   => $dayGrowth,
        // Pipeline
        'pending_total'    => $pendingTotal,
        'reply_pending'    => $replyPending,
        'followup_pending' => $followupPending,
        'processed_total'  => $processedTotal,
    ];
}

// ════════════════════════════════════════════════════════════════════
//  CHART DATA — daily breakdown for the selected range
// ════════════════════════════════════════════════════════════════════
if ($view === 'chart' || $view === 'all') {
    // Determine grouping based on filter
    $groupBy = ($filter === 'today' || $filter === 'yesterday') ? 'HOUR' : 'DATE';
    $dateExpr= ($groupBy === 'HOUR') ? "HOUR(ie.received_at)" : "DATE(ie.received_at)";
    $rangeW  = ($from && $to) ? "ie.received_at BETWEEN '$from' AND '$to'" : "1=1";

    if (!$isAdmin) {
        $q = $pdo->prepare(
            "SELECT $dateExpr AS period, COUNT(*) AS cnt
             FROM inbound_emails ie
             JOIN imap_accounts ia2 ON ia2.id=ie.imap_account_id
             WHERE ia2.user_id=? AND $rangeW
             GROUP BY period ORDER BY period ASC"
        );
        $q->execute([$uid]);
    } else {
        $q = $pdo->query(
            "SELECT $dateExpr AS period, COUNT(*) AS cnt
             FROM inbound_emails ie
             WHERE $rangeW
             GROUP BY period ORDER BY period ASC"
        );
    }
    $chartRows = $q->fetchAll(PDO::FETCH_ASSOC);

    // Build a full label array
    if ($groupBy === 'HOUR') {
        $labels = array_map(fn($h) => sprintf('%02d:00', $h), range(0, 23));
        $data   = array_fill(0, 24, 0);
        foreach ($chartRows as $r) $data[(int)$r['period']] = (int)$r['cnt'];
    } else {
        // Fill every day in the range
        $startDT = new DateTime(explode(' ', $from)[0]);
        $endDT   = new DateTime(explode(' ', $to)[0]);
        $labels = $data = [];
        for ($d = clone $startDT; $d <= $endDT; $d->modify('+1 day')) {
            $labels[] = $d->format('M d');
            $data[$d->format('Y-m-d')] = 0;
        }
        foreach ($chartRows as $r) {
            if (isset($data[$r['period']])) $data[$r['period']] = (int)$r['cnt'];
        }
        $data = array_values($data);
    }

    $resp['chart'] = ['labels' => $labels, 'data' => $data, 'group_by' => $groupBy];
}

// ════════════════════════════════════════════════════════════════════
//  SERVER-WISE STATS (Admin + User)
// ════════════════════════════════════════════════════════════════════
if ($view === 'server_wise' || $view === 'all') {
    $rangeW  = ($from && $to) ? "ie.received_at BETWEEN '$from' AND '$to'" : "1=1";
    if (!$isAdmin) {
        $q = $pdo->prepare(
            "SELECT ia.id, ia.name, ia.host, ia.username, ia.status,
                    COUNT(DISTINCT ie.id) AS lead_count
             FROM imap_accounts ia
             LEFT JOIN inbound_emails ie ON ie.imap_account_id=ia.id AND $rangeW
             WHERE ia.user_id=?
             GROUP BY ia.id ORDER BY lead_count DESC, ia.name ASC
             LIMIT 50"
        );
        $q->execute([$uid]);
    } else {
        $q = $pdo->query(
            "SELECT ia.id, ia.name, ia.host, ia.username, ia.status,
                    u.username AS owner,
                    COUNT(DISTINCT ie.id) AS lead_count
             FROM imap_accounts ia
             LEFT JOIN users u ON u.id=ia.user_id
             LEFT JOIN inbound_emails ie ON ie.imap_account_id=ia.id AND $rangeW
             GROUP BY ia.id ORDER BY lead_count DESC, ia.name ASC
             LIMIT 100"
        );
    }
    $resp['server_wise'] = $q->fetchAll(PDO::FETCH_ASSOC);
}

// ════════════════════════════════════════════════════════════════════
//  USER-WISE STATS (Admin only)
// ════════════════════════════════════════════════════════════════════
if ($isAdmin && ($view === 'user_wise' || $view === 'all')) {
    $rangeW = ($from && $to) ? "AND ie.received_at BETWEEN '$from' AND '$to'" : '';
    $q = $pdo->query(
        "SELECT u.id, u.username, u.is_admin,
                COUNT(DISTINCT ie.id)   AS lead_count,
                COUNT(DISTINCT ia.id)   AS server_count
         FROM users u
         LEFT JOIN imap_accounts ia ON ia.user_id=u.id
         LEFT JOIN inbound_emails ie ON ie.imap_account_id=ia.id $rangeW
         WHERE u.is_admin=0
         GROUP BY u.id ORDER BY lead_count DESC, u.username ASC
         LIMIT 100"
    );
    $resp['user_wise'] = $q->fetchAll(PDO::FETCH_ASSOC);
}

// ════════════════════════════════════════════════════════════════════
//  DAILY TREND — last 30 days (for sparkline)
// ════════════════════════════════════════════════════════════════════
if ($view === 'summary' || $view === 'all') {
    $rangeW30 = "ie.received_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)";
    if (!$isAdmin) {
        $q = $pdo->prepare(
            "SELECT DATE(ie.received_at) AS day, COUNT(*) AS cnt
             FROM inbound_emails ie
             JOIN imap_accounts ia2 ON ia2.id=ie.imap_account_id
             WHERE ia2.user_id=? AND $rangeW30
             GROUP BY day ORDER BY day ASC"
        );
        $q->execute([$uid]);
    } else {
        $q = $pdo->query(
            "SELECT DATE(ie.received_at) AS day, COUNT(*) AS cnt
             FROM inbound_emails ie
             WHERE $rangeW30
             GROUP BY day ORDER BY day ASC"
        );
    }
    $trend = [];
    $trendMap = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $trendMap[$r['day']] = (int)$r['cnt'];
    $dt = (clone $now)->modify('-29 days');
    for ($i = 0; $i < 30; $i++, $dt->modify('+1 day')) {
        $trend[] = $trendMap[$dt->format('Y-m-d')] ?? 0;
    }
    $resp['trend_30d'] = $trend;
}

echo json_encode($resp, JSON_NUMERIC_CHECK);
exit;
