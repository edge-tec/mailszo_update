<?php
/**
 * Asynchronous Queue & Dead-Letter Queue Management View Partial
 * Enterprise Scalability & Multi-Process Worker Telemetry
 */
?>
<!-- QUEUE HEADER / HERO BANNER -->
<div class="feat-hero">
  <div class="feat-hero-content">
    <div class="feat-hero-icon" style="background:linear-gradient(135deg,rgba(99,102,241,0.2),rgba(139,92,246,0.1));color:var(--indigo);box-shadow:0 0 24px rgba(99,102,241,0.25)">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>
    </div>
    <div class="feat-hero-text">
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <h2 style="font-size:22px;font-weight:800;letter-spacing:-0.03em;margin:0;color:var(--text)">Multi-Process Queue &amp; DLQ Telemetry</h2>
        <span class="badge b-purple" style="font-size:11px;padding:3px 10px;font-weight:700;display:inline-flex;align-items:center;gap:5px" id="queue-live-badge">
          <span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:currentColor;animation:livePulse 1.8s infinite"></span> LIVE POLLING
        </span>
      </div>
      <p style="margin:4px 0 0;font-size:13px;color:var(--text2);line-height:1.5">
        High-throughput asynchronous job workers, atomic priority scheduling, exponential backoff, and dead-letter queue recovery.
      </p>
    </div>
  </div>
  <div class="feat-hero-actions">
    <div style="display:flex;align-items:center;gap:8px;background:var(--bg3);padding:6px 12px;border-radius:var(--r);border:1px solid var(--border);backdrop-filter:blur(10px)">
      <input type="checkbox" id="queue-auto-refresh" checked onchange="toggleQueueAutoRefresh()" style="accent-color:var(--accent);cursor:pointer;width:14px;height:14px">
      <label for="queue-auto-refresh" style="font-size:12px;font-weight:600;color:var(--text2);cursor:pointer">Auto-Refresh (4s)</label>
    </div>
    <button class="btn btn-secondary btn-sm" onclick="loadQueuesPage()" style="display:inline-flex;align-items:center;gap:6px">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>
      Refresh
    </button>
    <button class="btn btn-purple btn-sm" onclick="retryAllFailedJobsApi()" style="display:inline-flex;align-items:center;gap:6px">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg>
      Retry All Failed
    </button>
    <button class="btn btn-secondary btn-sm" onclick="flushCompletedJobsApi()" title="Purge completed jobs older than 24h" style="display:inline-flex;align-items:center;gap:6px">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
      Flush Old (24h)
    </button>
  </div>
</div>

<!-- QUEUE DEPTHS KPI CARDS -->
<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:24px">
  <div class="sc" style="--sc-c:#f43f5e">
    <div class="sc-top">
      <span class="sc-label">Urgent (Prio 100)</span>
      <div class="sc-icon" style="background:rgba(244,63,94,0.12);color:#f43f5e">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
      </div>
    </div>
    <div class="sc-val" id="q-stat-urgent">0</div>
    <div class="sc-sub">Instant Auto-Replies</div>
  </div>

  <div class="sc" style="--sc-c:var(--purple)">
    <div class="sc-top">
      <span class="sc-label">Auto-Reply (Prio 80)</span>
      <div class="sc-icon" style="background:rgba(168,85,247,0.12);color:var(--purple)">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
      </div>
    </div>
    <div class="sc-val" id="q-stat-autoreply">0</div>
    <div class="sc-sub">Sequential steps</div>
  </div>

  <div class="sc" style="--sc-c:#38bdf8">
    <div class="sc-top">
      <span class="sc-label">Follow-Up (Prio 60)</span>
      <div class="sc-icon" style="background:rgba(56,189,248,0.12);color:#38bdf8">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      </div>
    </div>
    <div class="sc-val" id="q-stat-followup">0</div>
    <div class="sc-sub">Drip sequences</div>
  </div>

  <div class="sc" style="--sc-c:var(--amber)">
    <div class="sc-top">
      <span class="sc-label">Campaign (Prio 20)</span>
      <div class="sc-icon" style="background:rgba(245,158,11,0.12);color:var(--amber)">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
      </div>
    </div>
    <div class="sc-val" id="q-stat-campaign">0</div>
    <div class="sc-sub">Bulk outbound</div>
  </div>

  <div class="sc" style="--sc-c:var(--accent)">
    <div class="sc-top">
      <span class="sc-label">Active Reserved</span>
      <div class="sc-icon" style="background:rgba(16,185,129,0.12);color:var(--accent)">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      </div>
    </div>
    <div class="sc-val" id="q-stat-reserved">0</div>
    <div class="sc-sub">Processing in workers</div>
  </div>

  <div class="sc" style="--sc-c:var(--danger)">
    <div class="sc-top">
      <span class="sc-label">Dead-Letter (DLQ)</span>
      <div class="sc-icon" style="background:rgba(239,68,68,0.12);color:var(--danger)">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
      </div>
    </div>
    <div class="sc-val" id="q-stat-failed">0</div>
    <div class="sc-sub">Exhausted retries</div>
  </div>
</div>

<!-- DAEMONS & SUPERVISOR CLI CARD -->
<div class="card" style="margin-bottom:24px;border-color:rgba(99,102,241,0.22);background:linear-gradient(180deg,rgba(99,102,241,0.02) 0%,var(--card) 100%)">
  <div class="card-hd" style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid var(--border)">
    <h3 style="display:flex;align-items:center;gap:10px;font-size:15px;font-weight:700;margin:0">
      <span style="color:var(--indigo)">⚙️</span> Enterprise Daemon Runners &amp; Supervisor
    </h3>
    <span class="badge b-purple" style="font-size:10px;padding:3px 8px;font-weight:700">High Concurrency</span>
  </div>
  <div class="card-body" style="padding:20px">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:18px">
      <!-- Worker CLI -->
      <div style="background:var(--bg3);padding:16px;border-radius:var(--r);border:1px solid var(--border);box-shadow:inset 0 1px 0 rgba(255,255,255,0.03)">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <strong style="color:var(--accent);font-size:13px;letter-spacing:-0.01em">1. Multi-Process Queue Supervisor</strong>
          <button class="btn btn-secondary btn-sm" style="padding:3px 10px;font-size:11px" onclick="copyText($('cli-worker-cmd'))">📋 Copy</button>
        </div>
        <div style="font-size:12px;color:var(--text2);margin-bottom:10px;line-height:1.5">
          Spawns 4 concurrent worker processes with auto-recycle and memory management:
        </div>
        <div class="cron-box" id="cli-worker-cmd" style="font-size:12px;font-family:var(--font-mono);color:var(--accent);background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:10px 14px">php worker.php --concurrency=4</div>
      </div>

      <!-- IMAP IDLE CLI -->
      <div style="background:var(--bg3);padding:16px;border-radius:var(--r);border:1px solid var(--border);box-shadow:inset 0 1px 0 rgba(255,255,255,0.03)">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <strong style="color:var(--accent2);font-size:13px;letter-spacing:-0.01em">2. Real-Time IMAP IDLE Push Daemon</strong>
          <button class="btn btn-secondary btn-sm" style="padding:3px 10px;font-size:11px" onclick="copyText($('cli-idle-cmd'))">📋 Copy</button>
        </div>
        <div style="font-size:12px;color:var(--text2);margin-bottom:10px;line-height:1.5">
          Listens to incoming mailboxes via non-blocking sockets (&lt; 3s response time):
        </div>
        <div class="cron-box" id="cli-idle-cmd" style="font-size:12px;font-family:var(--font-mono);color:var(--accent2);background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:10px 14px">php imap_daemon.php</div>
      </div>
    </div>
  </div>
</div>

<!-- DEAD-LETTER QUEUE (FAILED JOBS) TABLE -->
<div class="card" style="box-shadow:var(--shadow)">
  <div class="card-hd" style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid var(--border)">
    <h3 style="display:flex;align-items:center;gap:10px;font-size:15px;font-weight:700;margin:0">
      <span style="color:var(--danger)">💀</span> Dead-Letter Queue (Failed Jobs Requiring Review)
    </h3>
    <div style="display:flex;gap:8px">
      <button class="btn btn-purple btn-sm" onclick="retryAllFailedJobsApi()" style="display:inline-flex;align-items:center;gap:6px">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg>
        Retry All
      </button>
    </div>
  </div>
  <div class="card-body" style="padding:0">
    <div id="queue-alert" class="al" style="margin:16px 20px 0"></div>
    <div class="tbl-wrap">
      <table style="width:100%;border-collapse:collapse">
        <thead>
          <tr style="border-bottom:1px solid var(--border);background:var(--bg3);font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--text3);font-weight:700">
            <th style="padding:12px 18px;text-align:left">ID</th>
            <th style="padding:12px 18px;text-align:left">Queue</th>
            <th style="padding:12px 18px;text-align:left">Handler</th>
            <th style="padding:12px 18px;text-align:left">Recipient / Target</th>
            <th style="padding:12px 18px;text-align:left">Error Trace</th>
            <th style="padding:12px 18px;text-align:left">Failed At</th>
            <th style="padding:12px 18px;text-align:right">Actions</th>
          </tr>
        </thead>
        <tbody id="queue-failed-tbody">
          <tr><td colspan="7" style="text-align:center;padding:36px;color:var(--text3)">Loading failed jobs...</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>
