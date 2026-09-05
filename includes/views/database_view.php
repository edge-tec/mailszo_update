<?php
/**
 * Database Storage, Partitioning & Archiving View Partial
 * Enterprise Scalability & High-Volume Storage Engine
 */
?>
<!-- DATABASE HEADER / HERO BANNER -->
<div class="feat-hero">
  <div class="feat-hero-content">
    <div class="feat-hero-icon" style="background:linear-gradient(135deg,rgba(16,185,129,0.2),rgba(5,150,105,0.1));color:var(--accent);box-shadow:0 0 24px rgba(16,185,129,0.25)">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>
    </div>
    <div class="feat-hero-text">
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <h2 style="font-size:22px;font-weight:800;letter-spacing:-0.03em;margin:0;color:var(--text)">Database Storage &amp; Time-Series Archiving</h2>
        <span class="badge b-purple" style="font-size:11px;padding:3px 10px;font-weight:700" id="db-engine-badge">MySQL</span>
      </div>
      <p style="margin:4px 0 0;font-size:13px;color:var(--text2);line-height:1.5">
        Zero-lock chunked historical log archival, time-series partition management, index defragmentation, and disk reclamation.
      </p>
    </div>
  </div>
  <div class="feat-hero-actions">
    <button class="btn btn-secondary btn-sm" onclick="loadDatabasePage()" title="Refresh metrics" style="display:inline-flex;align-items:center;gap:6px">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>
      Refresh
    </button>
    <button class="btn btn-purple btn-sm" id="btn-db-optimize" onclick="optimizeDatabaseApi()" style="display:inline-flex;align-items:center;gap:6px">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
      Optimize &amp; Reclaim
    </button>
    <button class="btn btn-primary btn-sm" onclick="openArchivalModal()" style="display:inline-flex;align-items:center;gap:6px">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>
      Run Archival
    </button>
  </div>
</div>

<!-- STORAGE KPI CARDS -->
<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:24px">
  <div class="sc" style="--sc-c:var(--purple)">
    <div class="sc-top">
      <span class="sc-label">Total Database Size</span>
      <div class="sc-icon" style="background:rgba(168,85,247,0.12);color:var(--purple)">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>
      </div>
    </div>
    <div class="sc-val" id="db-stat-total-mb">0 MB</div>
    <div class="sc-sub">Data + Index disk allocation</div>
  </div>

  <div class="sc" style="--sc-c:var(--accent)">
    <div class="sc-top">
      <span class="sc-label">Reclaimable Overhead</span>
      <div class="sc-icon" style="background:rgba(16,185,129,0.12);color:var(--accent)">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      </div>
    </div>
    <div class="sc-val" id="db-stat-free-mb">0 MB</div>
    <div class="sc-sub">Fragmented space reclaimable via Optimize</div>
  </div>

  <div class="sc" style="--sc-c:#38bdf8">
    <div class="sc-top">
      <span class="sc-label">Time-Series Partitioning</span>
      <div class="sc-icon" style="background:rgba(56,189,248,0.12);color:#38bdf8">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      </div>
    </div>
    <div class="sc-val" style="font-size:20px;font-weight:800;color:#38bdf8;margin-top:6px" id="db-stat-partition-status">CHECKING...</div>
    <div class="sc-sub">Sub-millisecond data dropping</div>
  </div>

  <div class="sc" style="--sc-c:var(--amber)">
    <div class="sc-top">
      <span class="sc-label">Zero-Lock Archiver</span>
      <div class="sc-icon" style="background:rgba(245,158,11,0.12);color:var(--amber)">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>
      </div>
    </div>
    <div class="sc-val" style="font-size:20px;font-weight:800;color:var(--amber);margin-top:6px">READY</div>
    <div class="sc-sub">1,000 rows/chunk batch migration</div>
  </div>
</div>

<!-- TABLE STORAGE BREAKDOWN CARD -->
<div class="card" style="margin-bottom:24px;box-shadow:var(--shadow)">
  <div class="card-hd" style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid var(--border)">
    <h3 style="font-size:15px;font-weight:700;margin:0">High-Volume Table Storage Breakdown</h3>
    <span style="font-size:12px;color:var(--text3);font-weight:500">Sorted by storage footprint</span>
  </div>
  <div class="card-body" style="padding:0">
    <div id="db-alert" class="al" style="margin:16px 20px 0"></div>
    <div class="tbl-wrap">
      <table style="width:100%;border-collapse:collapse">
        <thead>
          <tr style="border-bottom:1px solid var(--border);background:var(--bg3);font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--text3);font-weight:700">
            <th style="padding:12px 18px;text-align:left">Table Name</th>
            <th style="padding:12px 18px;text-align:right">Estimated Rows</th>
            <th style="padding:12px 18px;text-align:right">Data Size</th>
            <th style="padding:12px 18px;text-align:right">Index Size</th>
            <th style="padding:12px 18px;text-align:right">Total Footprint</th>
            <th style="padding:12px 18px;text-align:right">Overhead</th>
          </tr>
        </thead>
        <tbody id="db-tables-tbody">
          <tr><td colspan="6" style="text-align:center;padding:36px;color:var(--text3)">Analyzing database tables...</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- AUTOMATED CLI & CRON RUNNERS CARD -->
<div class="card" style="border-color:rgba(99,102,241,0.22);background:linear-gradient(180deg,rgba(99,102,241,0.02) 0%,var(--card) 100%)">
  <div class="card-hd" style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid var(--border)">
    <h3 style="display:flex;align-items:center;gap:10px;font-size:15px;font-weight:700;margin:0">
      <span style="color:var(--indigo)">⚙️</span> Automated Database Maintenance CLI Runners
    </h3>
    <span class="badge b-purple" style="font-size:10px;padding:3px 8px;font-weight:700">Server Automation</span>
  </div>
  <div class="card-body" style="padding:20px">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:18px">
      <div style="background:var(--bg3);padding:16px;border-radius:var(--r);border:1px solid var(--border);box-shadow:inset 0 1px 0 rgba(255,255,255,0.03)">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <strong style="color:var(--accent);font-size:13px;letter-spacing:-0.01em">Full Daily Maintenance (Recommended)</strong>
          <button class="btn btn-secondary btn-sm" style="padding:3px 10px;font-size:11px" onclick="copyText($('cli-maint-cmd'))">📋 Copy</button>
        </div>
        <div style="font-size:12px;color:var(--text2);margin-bottom:10px;line-height:1.5">
          Runs daily at midnight via crontab to clean queues, archive 30-day logs, and optimize tables:
        </div>
        <div class="cron-box" id="cli-maint-cmd" style="font-size:12px;font-family:var(--font-mono);color:var(--accent);background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:10px 14px">0 2 * * * php /path/to/maintenance.php --full &gt; /dev/null 2&gt;&amp;1</div>
      </div>

      <div style="background:var(--bg3);padding:16px;border-radius:var(--r);border:1px solid var(--border);box-shadow:inset 0 1px 0 rgba(255,255,255,0.03)">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <strong style="color:var(--accent2);font-size:13px;letter-spacing:-0.01em">On-Demand Chunked Archival</strong>
          <button class="btn btn-secondary btn-sm" style="padding:3px 10px;font-size:11px" onclick="copyText($('cli-archive-cmd'))">📋 Copy</button>
        </div>
        <div style="font-size:12px;color:var(--text2);margin-bottom:10px;line-height:1.5">
          Moves logs older than 60 days into archive tables without locking the live system:
        </div>
        <div class="cron-box" id="cli-archive-cmd" style="font-size:12px;font-family:var(--font-mono);color:var(--accent2);background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:10px 14px">php maintenance.php --archive --days=60 --batch=1000</div>
      </div>
    </div>
  </div>
</div>

<!-- RUN ARCHIVAL MODAL -->
<div class="modal-bg" id="db-archive-modal" style="display:none">
  <div class="modal-box" style="max-width:520px;width:95%">
    <div class="modal-hd" style="display:flex;justify-content:space-between;align-items:center;padding:18px 22px;border-bottom:1px solid var(--border)">
      <h3 style="margin:0;font-size:16px;font-weight:700;display:flex;align-items:center;gap:8px">
        <span>📦</span> Run Zero-Lock Chunked Archival
      </h3>
      <button class="modal-x" onclick="closeModal('db-archive-modal')">&times;</button>
    </div>
    <div class="modal-body" style="padding:22px">
      <div id="db-archive-modal-al" class="al"></div>

      <p style="font-size:13px;color:var(--text2);margin-bottom:18px;line-height:1.5">
        Historical records from <code style="font-family:var(--font-mono);background:var(--bg3);padding:2px 6px;border-radius:4px;border:1px solid var(--border)">system_logs</code> and <code style="font-family:var(--font-mono);background:var(--bg3);padding:2px 6px;border-radius:4px;border:1px solid var(--border)">send_logs</code> will be safely moved into archive tables in non-blocking batches.
      </p>

      <div class="frow fc2" style="margin-bottom:18px;display:grid;grid-template-columns:1fr 1fr;gap:14px">
        <div>
          <label class="fl" style="font-size:12px;font-weight:600;color:var(--text2);margin-bottom:6px;display:block">Retention Window</label>
          <select id="db-archive-days" class="fi" style="font-size:13px;width:100%">
            <option value="15">Older than 15 days</option>
            <option value="30" selected>Older than 30 days (Recommended)</option>
            <option value="60">Older than 60 days</option>
            <option value="90">Older than 90 days</option>
          </select>
        </div>
        <div>
          <label class="fl" style="font-size:12px;font-weight:600;color:var(--text2);margin-bottom:6px;display:block">Batch Chunk Size</label>
          <select id="db-archive-batch" class="fi" style="font-size:13px;width:100%">
            <option value="500">500 rows / chunk</option>
            <option value="1000" selected>1,000 rows / chunk</option>
            <option value="2500">2,500 rows / chunk</option>
          </select>
        </div>
      </div>

      <div style="padding:12px 16px;background:rgba(16,185,129,0.06);border:1px solid rgba(16,185,129,0.22);border-radius:var(--r);font-size:12px;color:var(--text2);line-height:1.6">
        <strong style="color:var(--accent)">✔ Zero Downtime:</strong> Active email sending campaigns and auto-replies will continue without interruption.
      </div>
    </div>
    <div class="modal-ft" style="display:flex;justify-content:flex-end;gap:10px;padding:16px 22px;border-top:1px solid var(--border);background:var(--bg3)">
      <button class="btn btn-secondary" onclick="closeModal('db-archive-modal')">Cancel</button>
      <button class="btn btn-primary" id="btn-execute-archival" onclick="runArchivalApi()">📦 Start Archival</button>
    </div>
  </div>
</div>
