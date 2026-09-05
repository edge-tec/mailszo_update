<?php
/**
 * DKIM Signatures Management View Partial
 * Enterprise Deliverability & Cryptographic Signing
 */
?>
<!-- DKIM FEAT HERO -->
<div class="feat-hero">
  <div class="feat-hero-left">
    <div class="feat-hero-icon">🔑</div>
    <div class="feat-hero-text">
      <h2>DKIM Cryptographic Signatures <span class="badge b-purple" style="font-size:10px">RFC 6376</span></h2>
      <p>Cryptographically sign outgoing emails with 2048-bit RSA keys to guarantee inbox placement and eliminate domain spoofing.</p>
    </div>
  </div>
  <div class="feat-hero-stats">
    <button class="btn btn-secondary btn-sm" onclick="loadDkimPage()" title="Refresh list">🔄 Refresh</button>
    <button class="btn btn-primary btn-sm" onclick="openDkimModal()" style="display:flex;align-items:center;gap:6px">
      <span>➕</span> Generate New DKIM Key
    </button>
  </div>
</div>

<!-- DKIM STATS ROW -->
<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px">
  <div class="sc" style="--sc-c:var(--purple)">
    <div class="sc-lbl"><span>Configured Domains</span><span style="font-size:9px;color:var(--purple)">DOMAINS</span></div>
    <div class="sc-val" id="dkim-stat-total" style="color:var(--purple)">0</div>
    <div class="sc-sub">Outbound domains with DKIM</div>
  </div>
  <div class="sc" style="--sc-c:var(--accent)">
    <div class="sc-lbl"><span>DNS Verified</span><span style="font-size:9px;color:var(--accent)">ACTIVE</span></div>
    <div class="sc-val" id="dkim-stat-verified" style="color:var(--accent)">0</div>
    <div class="sc-sub">Passing live DNS TXT lookups</div>
  </div>
  <div class="sc" style="--sc-c:var(--amber)">
    <div class="sc-lbl"><span>Propagation</span><span style="font-size:9px;color:var(--amber)">PENDING</span></div>
    <div class="sc-val" id="dkim-stat-pending" style="color:var(--amber)">0</div>
    <div class="sc-sub">DNS record not yet detected</div>
  </div>
  <div class="sc" style="--sc-c:var(--blue)">
    <div class="sc-lbl"><span>Key Standard</span><span style="font-size:9px;color:var(--blue)">RSA</span></div>
    <div class="sc-val" style="font-size:22px;color:var(--blue);margin-top:6px">RSA-SHA256</div>
    <div class="sc-sub">2048-Bit Enterprise Grade</div>
  </div>
</div>

<!-- DKIM KEYS TABLE CARD -->
<div class="card">
  <div class="card-hd">
    <h3>Active Domain Keys</h3>
    <div class="tbl-search-wrap" style="max-width:240px">
      <span class="tbl-search-icon">🔍</span>
      <input type="text" id="dkim-search" class="tbl-search-inp" placeholder="Search domain..." oninput="filterDkimKeys()">
    </div>
  </div>
  <div class="card-body" style="padding:0">
    <div id="dkim-alert" class="al" style="margin:14px 20px 0"></div>
    <div class="tw">
      <table>
        <thead>
          <tr>
            <th>Domain</th>
            <th>Selector</th>
            <th>Algorithm</th>
            <th style="text-align:center">Signing Status</th>
            <th style="text-align:center">DNS Status</th>
            <th>Last Verified</th>
            <th style="text-align:right">Actions</th>
          </tr>
        </thead>
        <tbody id="dkim-table-body">
          <tr><td colspan="7" style="text-align:center;padding:34px;color:var(--text3)">Loading DKIM keys...</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- GENERATE / ADD DKIM MODAL -->
<div class="modal-bg" id="dkim-modal">
  <div class="modal modal-lg">
    <div class="modal-hd">
      <h3 id="dkim-modal-title">🔑 Generate &amp; Add DKIM Key</h3>
      <span class="modal-x" onclick="closeModal('dkim-modal')">&times;</span>
    </div>
    <div class="modal-body">
      <div id="dkim-modal-al" class="al"></div>

      <div class="frow fc2" style="margin-bottom:14px">
        <div>
          <label class="fl">Domain Name <span style="color:var(--red)">*</span></label>
          <input type="text" id="dkim-input-domain" class="fi" placeholder="e.g. yourbrand.com">
          <span class="fhint">The domain used in your From/Sender address</span>
        </div>
        <div>
          <label class="fl">Selector <span style="color:var(--red)">*</span></label>
          <input type="text" id="dkim-input-selector" class="fi" value="mailpro">
          <span class="fhint">Default: <code>mailpro</code></span>
        </div>
      </div>

      <div style="margin-bottom:18px;display:flex;justify-content:space-between;align-items:center;background:rgba(99,102,241,0.06);padding:14px 18px;border-radius:12px;border:1px solid rgba(99,102,241,0.2)">
        <div>
          <div style="font-size:13px;font-weight:700;color:var(--indigo)">⚡ 1-Click Keypair Generator</div>
          <div style="font-size:11px;color:var(--text2);margin-top:2px">Generates cryptographically secure 2048-bit RSA keys instantly</div>
        </div>
        <button class="btn btn-purple btn-sm" id="btn-dkim-gen" onclick="generateDkimKeysApi()">Generate 2048-bit Key</button>
      </div>

      <!-- Generated DNS Details Preview -->
      <div id="dkim-dns-preview" style="display:none;background:var(--bg3);padding:16px;border-radius:12px;border:1px solid var(--border);margin-bottom:18px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <label class="fl" style="margin:0;color:var(--accent2)">DNS TXT Host / Name</label>
          <button class="btn btn-secondary btn-sm" style="padding:3px 9px;font-size:10px" onclick="copyText($('dkim-out-host'))">📋 Copy Host</button>
        </div>
        <div class="cron-box" id="dkim-out-host" style="margin-bottom:14px;font-size:12px;font-weight:700;color:var(--accent2);padding:8px 12px;background:var(--bg2);border-radius:8px;border:1px solid var(--border)"></div>

        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <label class="fl" style="margin:0;color:var(--accent)">DNS TXT Value</label>
          <button class="btn btn-secondary btn-sm" style="padding:3px 9px;font-size:10px" onclick="copyText($('dkim-out-value'))">📋 Copy Value</button>
        </div>
        <div class="cron-box" id="dkim-out-value" style="font-size:11px;word-break:break-all;max-height:100px;overflow-y:auto;padding:8px 12px;background:var(--bg2);border-radius:8px;border:1px solid var(--border)"></div>
      </div>

      <div style="margin-bottom:16px">
        <label class="fl">Private Key (PEM)</label>
        <textarea id="dkim-input-private" class="fi" rows="5" placeholder="-----BEGIN PRIVATE KEY-----&#10;...&#10;-----END PRIVATE KEY-----" style="font-family:var(--mono);font-size:11px"></textarea>
        <span class="fhint">Stored securely in your database for local OpenSSL signing</span>
      </div>

      <div style="display:flex;align-items:center;gap:10px;margin-top:10px">
        <input type="checkbox" id="dkim-input-active" checked style="width:16px;height:16px;accent-color:var(--accent)">
        <label for="dkim-input-active" style="font-size:13px;color:var(--text);cursor:pointer;font-weight:600">Enable signing immediately for this domain</label>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-secondary" onclick="closeModal('dkim-modal')">Cancel</button>
      <button class="btn btn-primary" id="btn-dkim-save" onclick="saveDkimKeyApi()">💾 Save DKIM Key</button>
    </div>
  </div>
</div>

<!-- VIEW DNS RECORD & HOW-TO MODAL -->
<div class="modal-bg" id="dkim-info-modal">
  <div class="modal modal-lg">
    <div class="modal-hd">
      <h3>📋 DNS Configuration Instructions</h3>
      <span class="modal-x" onclick="closeModal('dkim-info-modal')">&times;</span>
    </div>
    <div class="modal-body">
      <p style="font-size:13px;color:var(--text2);margin-bottom:16px;line-height:1.5">
        Add the following <strong>TXT record</strong> in your DNS provider (Cloudflare, cPanel, Namecheap, Route 53, etc.):
      </p>

      <div style="background:var(--bg3);padding:16px;border-radius:12px;border:1px solid var(--border);margin-bottom:18px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <label class="fl" style="margin:0">RECORD TYPE</label>
          <span class="badge b-purple" style="font-size:10px">TXT</span>
        </div>
        <hr style="border:0;border-top:1px solid var(--border);margin:10px 0">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <label class="fl" style="margin:0">HOST / NAME</label>
          <button class="btn btn-secondary btn-sm" style="padding:2px 8px;font-size:10px" onclick="copyText($('dkim-info-host'))">📋 Copy</button>
        </div>
        <div class="cron-box" id="dkim-info-host" style="font-size:12px;font-weight:700;color:var(--accent2);margin-bottom:12px;padding:8px 12px;background:var(--bg2);border-radius:8px;border:1px solid var(--border)"></div>

        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <label class="fl" style="margin:0">VALUE / TARGET</label>
          <button class="btn btn-secondary btn-sm" style="padding:2px 8px;font-size:10px" onclick="copyText($('dkim-info-val'))">📋 Copy</button>
        </div>
        <div class="cron-box" id="dkim-info-val" style="font-size:11px;word-break:break-all;max-height:110px;overflow-y:auto;padding:8px 12px;background:var(--bg2);border-radius:8px;border:1px solid var(--border)"></div>
      </div>

      <div style="padding:14px 16px;background:rgba(16,185,129,.07);border:1px solid rgba(16,185,129,.25);border-radius:12px;font-size:12px;color:var(--text2);line-height:1.7">
        <strong style="color:var(--accent)">💡 DNS Propagation Tip:</strong><br>
        DNS records typically propagate within 5–15 minutes (or up to 24 hours on slow registrars). Click <strong>Live Verify</strong> in the table to test live DNS status anytime.
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-secondary" onclick="closeModal('dkim-info-modal')">Close</button>
    </div>
  </div>
</div>
