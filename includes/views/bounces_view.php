<?php
/**
 * Bounce & Suppression Management View Partial
 * Enterprise VERP & RFC 3464/3463 DSN Engine
 */
?>
<!-- BOUNCES FEAT HERO -->
<div class="feat-hero">
  <div class="feat-hero-left">
    <div class="feat-hero-icon">⚡</div>
    <div class="feat-hero-text">
      <h2>Bounce Intelligence &amp; Suppression <span class="badge b-amber" style="font-size:10px">RFC 3464 / VERP</span></h2>
      <p>Automated DSN bounce detection, HMAC-secured VERP return-path, soft-bounce strike tracking, and instant reputation protection.</p>
    </div>
  </div>
  <div class="feat-hero-stats">
    <button class="btn btn-secondary btn-sm" onclick="loadBouncesPage()" title="Refresh stats">🔄 Refresh</button>
    <button class="btn btn-amber btn-sm" id="btn-bounce-scan" onclick="triggerBounceScanApi()" style="display:flex;align-items:center;gap:6px">
      <span>▶</span> Run Bounce Scan Now
    </button>
    <button class="btn btn-primary btn-sm" onclick="openBounceMailboxModal()" style="display:flex;align-items:center;gap:6px">
      <span>➕</span> Add Bounce Mailbox
    </button>
  </div>
</div>

<!-- BOUNCE STATS CARDS -->
<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px">
  <div class="sc" style="--sc-c:var(--red)">
    <div class="sc-lbl"><span>Hard Bounces (5.x.x)</span><span style="font-size:9px;color:var(--red)">PERMANENT</span></div>
    <div class="sc-val" id="bounce-stat-hard" style="color:var(--red)">0</div>
    <div class="sc-sub">Permanent failures (Auto-suppressed)</div>
  </div>
  <div class="sc" style="--sc-c:var(--amber)">
    <div class="sc-lbl"><span>Soft Bounces (4.x.x)</span><span style="font-size:9px;color:var(--amber)">TRANSIENT</span></div>
    <div class="sc-val" id="bounce-stat-soft" style="color:var(--amber)">0</div>
    <div class="sc-sub">Temporary failures with strike counter</div>
  </div>
  <div class="sc" style="--sc-c:var(--purple)">
    <div class="sc-lbl"><span>Monitored Inboxes</span><span style="font-size:9px;color:var(--purple)">IMAP</span></div>
    <div class="sc-val" id="bounce-stat-mailboxes" style="color:var(--purple)">0</div>
    <div class="sc-sub">Active IMAP bounce listeners</div>
  </div>
  <div class="sc" style="--sc-c:var(--accent)">
    <div class="sc-lbl"><span>Gatekeeper Protection</span><span style="font-size:9px;color:var(--accent)">ONLINE</span></div>
    <div class="sc-val" style="font-size:22px;color:var(--accent);margin-top:6px">ACTIVE</div>
    <div class="sc-sub">Pre-send suppression enabled</div>
  </div>
</div>

<!-- TABS NAVIGATION -->
<div class="tbl-filter-chips" style="gap:8px;border-bottom:1px solid var(--border);padding-bottom:12px;margin-bottom:20px">
  <button class="chip-btn active bounce-tab" id="tab-btn-mailboxes" onclick="switchBounceTab('mailboxes')">
    📬 Bounce Mailboxes
  </button>
  <button class="chip-btn bounce-tab" id="tab-btn-recent" onclick="switchBounceTab('recent')">
    📜 Recent Bounce Activity
  </button>
  <button class="chip-btn bounce-tab" id="tab-btn-sandbox" onclick="switchBounceTab('sandbox')">
    🔬 Live DSN Parser Sandbox
  </button>
</div>

<!-- TAB 1: BOUNCE MAILBOXES -->
<div id="bounce-pane-mailboxes" class="card" style="margin-bottom:24px">
  <div class="card-hd">
    <h3>Configured IMAP Bounce Mailboxes</h3>
    <button class="btn btn-purple btn-sm" onclick="openBounceMailboxModal()">+ Add New Mailbox</button>
  </div>
  <div class="card-body" style="padding:0">
    <div id="bounce-alert" class="al" style="margin:14px 20px 0"></div>
    <div class="tw">
      <table>
        <thead>
          <tr>
            <th>Name</th>
            <th>IMAP Server</th>
            <th>Port / SSL</th>
            <th>Username</th>
            <th style="text-align:center">Purge Mode</th>
            <th>Last Polled</th>
            <th style="text-align:right">Actions</th>
          </tr>
        </thead>
        <tbody id="bounce-mailboxes-tbody">
          <tr><td colspan="7" style="text-align:center;padding:34px;color:var(--text3)">Loading mailboxes...</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- TAB 2: RECENT BOUNCE ACTIVITY -->
<div id="bounce-pane-recent" class="card" style="display:none;margin-bottom:24px">
  <div class="card-hd">
    <h3>Recent Bounce DSN Events</h3>
    <span class="badge b-gray">Latest 50 incidents</span>
  </div>
  <div class="card-body" style="padding:0">
    <div class="tw">
      <table>
        <thead>
          <tr>
            <th>Recipient</th>
            <th>Diagnostic / DSN Reason</th>
            <th>Date &amp; Time</th>
          </tr>
        </thead>
        <tbody id="bounce-recent-tbody">
          <tr><td colspan="3" style="text-align:center;padding:34px;color:var(--text3)">No recent bounce events recorded.</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- TAB 3: LIVE DSN PARSER SANDBOX -->
<div id="bounce-pane-sandbox" class="card" style="display:none;margin-bottom:24px">
  <div class="card-hd">
    <h3>RFC 3464 DSN Parser Interactive Sandbox</h3>
  </div>
  <div class="card-body">
    <div style="font-size:12px;color:var(--text2);margin-bottom:14px">
      Paste raw non-delivery report (NDR) headers and body to test the real-time parser and observe classified action codes.
    </div>
    <div class="frow fc2">
      <div>
        <label class="fl">Raw Bounce Message / NDR Content</label>
        <textarea id="bounce-raw-input" class="fi" rows="12" placeholder="Action: failed&#10;Status: 5.1.1&#10;Diagnostic-Code: smtp; 550 User unknown&#10;Final-Recipient: rfc822; user@example.com" style="font-family:var(--mono);font-size:11px"></textarea>
        <button class="btn btn-purple btn-sm" onclick="testDsnParserApi()" style="margin-top:10px">🔬 Test Parse DSN</button>
      </div>
      <div>
        <label class="fl">Parsed Result (RFC 3464 Breakdown)</label>
        <pre id="bounce-parse-output" style="background:var(--bg3);border:1px solid var(--border);border-radius:10px;padding:16px;font-size:11px;font-family:var(--mono);height:260px;overflow:auto;color:var(--text2)">// Parsed output will appear here...</pre>
      </div>
    </div>
  </div>
</div>

<!-- ADD / EDIT BOUNCE MAILBOX MODAL -->
<div class="modal-bg" id="bounce-mailbox-modal">
  <div class="modal modal-lg">
    <div class="modal-hd">
      <h3 id="bounce-modal-title">➕ Configure Bounce IMAP Mailbox</h3>
      <span class="modal-x" onclick="closeModal('bounce-mailbox-modal')">&times;</span>
    </div>
    <div class="modal-body">
      <div id="bounce-modal-al" class="al"></div>
      <input type="hidden" id="bounce-box-id" value="0">

      <div class="frow fc2" style="margin-bottom:14px">
        <div>
          <label class="fl">Mailbox Label <span style="color:var(--red)">*</span></label>
          <input type="text" id="bounce-box-name" class="fi" placeholder="e.g. Bounce Handler 1">
        </div>
        <div>
          <label class="fl">IMAP Server Host <span style="color:var(--red)">*</span></label>
          <input type="text" id="bounce-box-host" class="fi" placeholder="mail.yourdomain.com">
        </div>
      </div>

      <div class="frow fc2" style="margin-bottom:14px">
        <div>
          <label class="fl">Port</label>
          <input type="number" id="bounce-box-port" class="fi" value="993">
        </div>
        <div>
          <label class="fl">Security</label>
          <select id="bounce-box-secure" class="fsel">
            <option value="1" selected>SSL / TLS (Recommended)</option>
            <option value="0">Plain / STARTTLS</option>
          </select>
        </div>
      </div>

      <div class="frow fc2" style="margin-bottom:14px">
        <div>
          <label class="fl">IMAP Username <span style="color:var(--red)">*</span></label>
          <input type="text" id="bounce-box-username" class="fi" placeholder="bounce@yourdomain.com">
        </div>
        <div>
          <label class="fl">IMAP Password <span id="bounce-pw-required" style="color:var(--red)">*</span></label>
          <input type="password" id="bounce-box-password" class="fi" placeholder="••••••••">
        </div>
      </div>

      <div style="display:flex;align-items:center;gap:10px;margin-top:14px;background:rgba(255,255,255,.02);padding:12px 16px;border-radius:10px;border:1px solid var(--border)">
        <input type="checkbox" id="bounce-box-delete" checked style="width:16px;height:16px;accent-color:var(--accent)">
        <label for="bounce-box-delete" style="font-size:12px;color:var(--text);cursor:pointer;font-weight:600">
          Delete processed bounce emails from mailbox to keep mailbox fast and clean
        </label>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-secondary" onclick="closeModal('bounce-mailbox-modal')">Cancel</button>
      <button class="btn btn-primary" id="btn-save-bounce-box" onclick="saveBounceMailboxApi()">💾 Save Mailbox</button>
    </div>
  </div>
</div>
