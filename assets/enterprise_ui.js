/**
 * Enterprise UI Client Engine
 * DKIM Management, Bounce Intelligence, and Asynchronous Queue & DLQ Monitor
 */

let _dkimListCache = [];
let _queuePollTimer = null;

/* ══════════════════════════════════════════════════════════════════
   1. DKIM CRYPTOGRAPHIC SIGNATURES
   ══════════════════════════════════════════════════════════════════ */

async function loadDkimPage() {
  const tb = $('dkim-table-body');
  if (tb) tb.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:32px;color:var(--text3)">Loading DKIM keys...</td></tr>';

  try {
    const res = await get('dkim');
    if (!res || !res.ok) {
      if (tb) tb.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:32px;color:var(--danger)">Error loading DKIM keys: ${esc(res?.error || 'Unknown error')}</td></tr>`;
      return;
    }

    _dkimListCache = res.keys || [];
    renderDkimStats(_dkimListCache);
    renderDkimTable(_dkimListCache);
  } catch (e) {
    if (tb) tb.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:32px;color:var(--danger)">Network error: ${esc(e.message)}</td></tr>`;
  }
}

function renderDkimStats(keys) {
  const total = keys.length;
  const verified = keys.filter(k => Number(k.dns_verified) === 1).length;
  const pending = total - verified;

  set('dkim-stat-total', total);
  set('dkim-stat-verified', verified);
  set('dkim-stat-pending', pending);
}

function renderDkimTable(keys) {
  const tb = $('dkim-table-body');
  if (!tb) return;

  if (!keys.length) {
    tb.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:32px;color:var(--text3)">No DKIM keys configured yet. Click "+ Generate New DKIM Key" to get started.</td></tr>';
    return;
  }

  tb.innerHTML = keys.map(k => {
    const isAct = Number(k.is_active) === 1;
    const isVer = Number(k.dns_verified) === 1;

    return `
      <tr style="border-bottom:1px solid var(--border)">
        <td style="padding:14px 16px;font-weight:700;color:var(--text)">
          <div style="display:flex;align-items:center;gap:8px">
            <span style="font-size:16px">🌐</span>
            <span>${esc(k.domain)}</span>
          </div>
        </td>
        <td style="padding:14px 16px"><code style="background:var(--bg3);padding:3px 8px;border-radius:4px;font-size:12px;color:var(--accent2)">${esc(k.selector)}</code></td>
        <td style="padding:14px 16px;font-size:12px;color:var(--text2)">rsa-sha256 (2048-bit)</td>
        <td style="padding:14px 16px;text-align:center">
          <button class="badge ${isAct ? 'b-green' : 'b-gray'}" onclick="toggleDkimApi(${k.id})" style="cursor:pointer;border:none;padding:4px 10px">
            ${isAct ? 'Active' : 'Inactive'}
          </button>
        </td>
        <td style="padding:14px 16px;text-align:center">
          <span class="badge ${isVer ? 'b-green' : 'b-amber'}" style="padding:4px 8px">
            ${isVer ? '✔ Verified' : '⏳ Pending'}
          </span>
        </td>
        <td style="padding:14px 16px;font-size:11px;color:var(--text3)">
          ${k.last_verified_at ? esc(k.last_verified_at) : 'Never'}
        </td>
        <td style="padding:14px 16px;text-align:right">
          <div style="display:flex;gap:6px;justify-content:flex-end">
            <button class="btn btn-secondary btn-sm" onclick="showDkimInfoModal('${esc(k.dns_host || '')}', '${esc(k.dns_value || '')}')" title="View DNS Instructions">
              📋 DNS Info
            </button>
            <button class="btn btn-purple btn-sm" onclick="verifyDkimDnsApi(${k.id}, '${esc(k.domain)}', '${esc(k.selector)}')" title="Query live DNS record">
              🔍 Verify
            </button>
            <button class="btn btn-danger btn-sm" onclick="deleteDkimApi(${k.id}, '${esc(k.domain)}')" title="Delete key">
              🗑️
            </button>
          </div>
        </td>
      </tr>
    `;
  }).join('');
}

function filterDkimKeys() {
  const q = (v('dkim-search') || '').toLowerCase().trim();
  if (!q) {
    renderDkimTable(_dkimListCache);
    return;
  }
  const filtered = _dkimListCache.filter(k => (k.domain || '').toLowerCase().includes(q) || (k.selector || '').toLowerCase().includes(q));
  renderDkimTable(filtered);
}

function openDkimModal() {
  $('dkim-modal-al').className = 'al';
  $('dkim-modal-al').innerHTML = '';
  $('dkim-input-domain').value = '';
  $('dkim-input-selector').value = 'mailpro';
  $('dkim-input-private').value = '';
  $('dkim-input-active').checked = true;
  $('dkim-dns-preview').style.display = 'none';
  showModal('dkim-modal');
}

async function generateDkimKeysApi() {
  const domain = (v('dkim-input-domain') || '').trim();
  const selector = (v('dkim-input-selector') || 'mailpro').trim();
  if (!domain) {
    al('dkim-modal-al', 'Please enter a domain name first', 'err');
    return;
  }

  const btn = $('btn-dkim-gen');
  btn.disabled = true;
  btn.textContent = 'Generating...';

  try {
    const res = await post('dkim/generate', { domain, selector });
    if (res && res.ok) {
      $('dkim-input-private').value = res.private_key || '';
      $('dkim-out-host').textContent = res.dns_host || '';
      $('dkim-out-value').textContent = res.dns_value || '';
      $('dkim-dns-preview').style.display = 'block';
      al('dkim-modal-al', 'Keypair generated successfully! Add the DNS record below to your DNS provider.', 'suc');
    } else {
      al('dkim-modal-al', res?.error || 'Failed to generate keypair', 'err');
    }
  } catch (e) {
    al('dkim-modal-al', e.message, 'err');
  } finally {
    btn.disabled = false;
    btn.textContent = 'Generate 2048-bit Key';
  }
}

async function saveDkimKeyApi() {
  const domain = (v('dkim-input-domain') || '').trim();
  const selector = (v('dkim-input-selector') || 'mailpro').trim();
  const private_key = (v('dkim-input-private') || '').trim();
  const is_active = $('dkim-input-active')?.checked ? 1 : 0;

  if (!domain || !private_key) {
    al('dkim-modal-al', 'Domain and Private Key are required', 'err');
    return;
  }

  const btn = $('btn-dkim-save');
  btn.disabled = true;

  try {
    const res = await post('dkim/save', { domain, selector, private_key, is_active });
    if (res && res.ok) {
      closeModal('dkim-modal');
      al('dkim-alert', 'DKIM key for ' + esc(domain) + ' saved successfully!', 'suc');
      loadDkimPage();
    } else {
      al('dkim-modal-al', res?.error || 'Error saving key', 'err');
    }
  } catch (e) {
    al('dkim-modal-al', e.message, 'err');
  } finally {
    btn.disabled = false;
  }
}

async function verifyDkimDnsApi(id, domain, selector) {
  al('dkim-alert', 'Querying live DNS TXT record for ' + esc(selector) + '._domainkey.' + esc(domain) + '...', 'inf');
  try {
    const res = await get(`dkim/verify&id=${id}&domain=${encodeURIComponent(domain)}&selector=${encodeURIComponent(selector)}`);
    if (res && res.ok) {
      if (res.verified) {
        al('dkim-alert', `✔ Live DNS Verification Succeeded for ${esc(domain)}! Outbound emails are cryptographically verified.`, 'suc');
      } else {
        al('dkim-alert', `⏳ ${esc(res.message || 'DNS record not found yet')}. If recently added, please allow 5-15 minutes for propagation.`, 'warn');
      }
      loadDkimPage();
    } else {
      al('dkim-alert', res?.error || 'Verification failed', 'err');
    }
  } catch (e) {
    al('dkim-alert', e.message, 'err');
  }
}

async function toggleDkimApi(id) {
  try {
    const res = await post('dkim/toggle', { id });
    if (res && res.ok) {
      loadDkimPage();
    }
  } catch (e) {}
}

async function deleteDkimApi(id, domain) {
  if (!confirm(`Are you sure you want to delete the DKIM key for ${domain}?`)) return;
  try {
    const res = await post('dkim/delete', { id });
    if (res && res.ok) {
      al('dkim-alert', `DKIM key for ${domain} deleted.`, 'suc');
      loadDkimPage();
    }
  } catch (e) {
    al('dkim-alert', e.message, 'err');
  }
}

function showDkimInfoModal(host, val) {
  set('dkim-info-host', host || '—');
  set('dkim-info-val', val || '—');
  showModal('dkim-info-modal');
}


/* ══════════════════════════════════════════════════════════════════
   2. BOUNCE INTELLIGENCE & SUPPRESSION
   ══════════════════════════════════════════════════════════════════ */

async function loadBouncesPage() {
  loadBounceStats();
  loadBounceMailboxes();
}

async function loadBounceStats() {
  try {
    const res = await get('bounces/stats');
    if (res && res.ok) {
      set('bounce-stat-hard', res.hard_bounces || 0);
      set('bounce-stat-soft', res.soft_bounces || 0);

      // Render recent
      const rtb = $('bounce-recent-tbody');
      if (rtb) {
        if (!res.recent || !res.recent.length) {
          rtb.innerHTML = '<tr><td colspan="3" style="text-align:center;padding:32px;color:var(--text3)">No recent bounce events recorded.</td></tr>';
        } else {
          rtb.innerHTML = res.recent.map(r => `
            <tr style="border-bottom:1px solid var(--border)">
              <td style="padding:12px 16px;font-weight:700;color:var(--text)">${esc(r.recipient_email || '—')}</td>
              <td style="padding:12px 16px;font-size:12px;color:var(--text2)"><code style="font-size:11px">${esc(r.details || '—')}</code></td>
              <td style="padding:12px 16px;font-size:11px;color:var(--text3)">${esc(r.created_at || '—')}</td>
            </tr>
          `).join('');
        }
      }
    }
  } catch (e) {}
}

async function loadBounceMailboxes() {
  const tb = $('bounce-mailboxes-tbody');
  if (tb) tb.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:32px;color:var(--text3)">Loading bounce mailboxes...</td></tr>';

  try {
    const res = await get('bounces/mailboxes');
    if (!res || !res.ok) {
      if (tb) tb.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:32px;color:var(--danger)">Error: ${esc(res?.error || 'Failed to load')}</td></tr>`;
      return;
    }

    const mboxes = res.mailboxes || [];
    set('bounce-stat-mailboxes', mboxes.length);

    if (!mboxes.length) {
      if (tb) tb.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:32px;color:var(--text3)">No dedicated bounce mailboxes configured yet. Add one to automatically process inbound bounce NDRs.</td></tr>';
      return;
    }

    if (tb) {
      tb.innerHTML = mboxes.map(m => `
        <tr style="border-bottom:1px solid var(--border)">
          <td style="padding:14px 16px;font-weight:700;color:var(--text)">${esc(m.name)}</td>
          <td style="padding:14px 16px;font-size:12px;color:var(--text2)">${esc(m.host)}</td>
          <td style="padding:14px 16px"><span class="badge b-purple">${m.port} (${Number(m.secure) ? 'SSL' : 'Plain'})</span></td>
          <td style="padding:14px 16px;font-size:12px;color:var(--text)">${esc(m.username)}</td>
          <td style="padding:14px 16px;text-align:center">
            <span class="badge ${Number(m.delete_after_processing) ? 'b-amber' : 'b-gray'}" style="font-size:10px">
              ${Number(m.delete_after_processing) ? 'Delete' : 'Keep'}
            </span>
          </td>
          <td style="padding:14px 16px;font-size:11px;color:var(--text3)">${m.last_polled_at ? esc(m.last_polled_at) : 'Never'}</td>
          <td style="padding:14px 16px;text-align:right">
            <button class="btn btn-danger btn-sm" onclick="deleteBounceMailboxApi(${m.id})">🗑️ Delete</button>
          </td>
        </tr>
      `).join('');
    }
  } catch (e) {
    if (tb) tb.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:32px;color:var(--danger)">Error: ${esc(e.message)}</td></tr>`;
  }
}

function switchBounceTab(tab) {
  document.querySelectorAll('.bounce-tab').forEach(b => {
    b.classList.remove('active');
    b.style.borderBottom = '2px solid transparent';
  });
  $('tab-btn-' + tab)?.classList.add('active');
  const activeBtn = $('tab-btn-' + tab);
  if (activeBtn) activeBtn.style.borderBottom = '2px solid var(--accent)';

  $('bounce-pane-mailboxes').style.display = tab === 'mailboxes' ? 'block' : 'none';
  $('bounce-pane-recent').style.display = tab === 'recent' ? 'block' : 'none';
  $('bounce-pane-sandbox').style.display = tab === 'sandbox' ? 'block' : 'none';
}

function openBounceMailboxModal() {
  $('bounce-modal-al').className = 'al';
  $('bounce-modal-al').innerHTML = '';
  $('bounce-box-id').value = '0';
  $('bounce-box-name').value = '';
  $('bounce-box-host').value = '';
  $('bounce-box-port').value = '993';
  $('bounce-box-secure').value = '1';
  $('bounce-box-username').value = '';
  $('bounce-box-password').value = '';
  $('bounce-box-delete').checked = true;
  showModal('bounce-mailbox-modal');
}

async function saveBounceMailboxApi() {
  const name = (v('bounce-box-name') || '').trim();
  const host = (v('bounce-box-host') || '').trim();
  const port = parseInt(v('bounce-box-port') || 993, 10);
  const secure = parseInt(v('bounce-box-secure') || 1, 10);
  const username = (v('bounce-box-username') || '').trim();
  const password = (v('bounce-box-password') || '').trim();
  const delete_after_processing = $('bounce-box-delete')?.checked ? 1 : 0;
  const id = parseInt(v('bounce-box-id') || 0, 10);

  if (!name || !host || !username) {
    al('bounce-modal-al', 'Name, Host, and Username are required', 'err');
    return;
  }
  if (!id && !password) {
    al('bounce-modal-al', 'Password is required for new mailbox', 'err');
    return;
  }

  const btn = $('btn-save-bounce-box');
  btn.disabled = true;

  try {
    const res = await post('bounces/mailboxes', { id, name, host, port, secure, username, password, delete_after_processing });
    if (res && res.ok) {
      closeModal('bounce-mailbox-modal');
      al('bounce-alert', 'Bounce mailbox saved successfully!', 'suc');
      loadBounceMailboxes();
    } else {
      al('bounce-modal-al', res?.error || 'Error saving mailbox', 'err');
    }
  } catch (e) {
    al('bounce-modal-al', e.message, 'err');
  } finally {
    btn.disabled = false;
  }
}

async function deleteBounceMailboxApi(id) {
  if (!confirm('Are you sure you want to remove this bounce mailbox?')) return;
  try {
    const res = await del(`bounces/mailboxes/${id}`);
    if (res && res.ok) {
      al('bounce-alert', 'Bounce mailbox removed.', 'suc');
      loadBounceMailboxes();
    }
  } catch (e) {
    al('bounce-alert', e.message, 'err');
  }
}

async function triggerBounceScanApi() {
  const btn = $('btn-bounce-scan');
  btn.disabled = true;
  btn.textContent = 'Scanning...';
  al('bounce-alert', 'Connecting to bounce inboxes and parsing DSN messages...', 'inf');

  try {
    const res = await post('bounces/process', {});
    if (res && res.ok) {
      const r = res.results || {};
      al('bounce-alert', `Scan complete: Processed ${r.processed || 0} messages (${r.hard_bounces || 0} Hard Bounces auto-suppressed, ${r.soft_bounces || 0} Soft Bounces strike-tracked).`, 'suc');
      loadBounceStats();
      loadBounceMailboxes();
    } else {
      al('bounce-alert', res?.error || 'Bounce scan failed', 'err');
    }
  } catch (e) {
    al('bounce-alert', e.message, 'err');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<span>▶</span> Run Bounce Scan Now';
  }
}

async function testDsnParserApi() {
  const raw = (v('bounce-raw-input') || '').trim();
  const out = $('bounce-parse-output');
  if (!raw) {
    if (out) out.textContent = '// Please paste raw bounce message text above';
    return;
  }

  if (out) out.textContent = '// Parsing RFC 3464 headers and DSN body...';
  try {
    const res = await post('bounces/test-parse', { raw_email: raw });
    if (out) {
      out.textContent = JSON.stringify(res, null, 2);
    }
  } catch (e) {
    if (out) out.textContent = '// Error: ' + e.message;
  }
}


/* ══════════════════════════════════════════════════════════════════
   3. ASYNCHRONOUS QUEUE & DEAD-LETTER QUEUE (DLQ) MONITOR
   ══════════════════════════════════════════════════════════════════ */

async function loadQueuesPage() {
  loadQueueStats();
  loadFailedJobs();
}

async function loadQueueStats() {
  try {
    const res = await get('queue/stats');
    if (res && res.ok) {
      const s = res.stats || {};
      const byQ = s.by_queue || {};

      set('q-stat-urgent', byQ.urgent || 0);
      set('q-stat-autoreply', byQ.autoreply || 0);
      set('q-stat-followup', byQ.followup || 0);
      set('q-stat-campaign', byQ.campaign || 0);
      set('q-stat-reserved', s.reserved || 0);
      set('q-stat-failed', s.failed_dlq || 0);
    }
  } catch (e) {}
}

async function loadFailedJobs() {
  const tb = $('queue-failed-tbody');
  try {
    const res = await get('queue/failed&limit=50');
    if (!res || !res.ok) {
      if (tb) tb.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:32px;color:var(--danger)">Error loading DLQ: ${esc(res?.error || 'Failed')}</td></tr>`;
      return;
    }

    const jobs = res.failed_jobs || [];
    if (!jobs.length) {
      if (tb) tb.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:32px;color:var(--accent)">✔ Dead-Letter Queue is empty! All workers operating with 100% throughput.</td></tr>';
      return;
    }

    if (tb) {
      tb.innerHTML = jobs.map(j => {
        const payload = j.payload_data || {};
        const recipient = payload.recipient || payload.to_email || payload.email || (payload.contact ? payload.contact.email : '—');
        const err = (j.exception || '').substring(0, 120);

        return `
          <tr style="border-bottom:1px solid var(--border)">
            <td style="padding:12px 16px;font-family:monospace;font-size:11px;color:var(--text3)">#${j.id}</td>
            <td style="padding:12px 16px"><span class="badge b-purple" style="font-size:10px">${esc(j.queue)}</span></td>
            <td style="padding:12px 16px;font-weight:700;color:var(--text);font-size:12px">${esc(payload.handler || '—')}</td>
            <td style="padding:12px 16px;font-size:12px;color:var(--accent2)">${esc(recipient)}</td>
            <td style="padding:12px 16px;font-size:11px;color:var(--danger);font-family:monospace" title="${esc(j.exception || '')}">${esc(err)}</td>
            <td style="padding:12px 16px;font-size:11px;color:var(--text3)">${esc(j.failed_at || '—')}</td>
            <td style="padding:12px 16px;text-align:right">
              <div style="display:flex;gap:6px;justify-content:flex-end">
                <button class="btn btn-purple btn-sm" onclick="retryFailedJobApi(${j.id})">↺ Retry</button>
                <button class="btn btn-danger btn-sm" onclick="deleteFailedJobApi(${j.id})">🗑️</button>
              </div>
            </td>
          </tr>
        `;
      }).join('');
    }
  } catch (e) {
    if (tb) tb.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:32px;color:var(--danger)">Error: ${esc(e.message)}</td></tr>`;
  }
}

function toggleQueueAutoRefresh() {
  const isChecked = $('queue-auto-refresh')?.checked;
  const badge = $('queue-live-badge');
  if (isChecked) {
    if (badge) { badge.textContent = '● LIVE POLLING'; badge.className = 'badge b-purple'; }
    if (!_queuePollTimer) {
      _queuePollTimer = setInterval(() => {
        if (!document.getElementById('page-queues')?.classList.contains('active')) return;
        loadQueuesPage();
      }, 4000);
    }
  } else {
    if (badge) { badge.textContent = 'PAUSED'; badge.className = 'badge b-gray'; }
    if (_queuePollTimer) {
      clearInterval(_queuePollTimer);
      _queuePollTimer = null;
    }
  }
}

async function retryFailedJobApi(id) {
  try {
    const res = await post('queue/retry', { id });
    if (res && res.ok) {
      al('queue-alert', res.message || `Job #${id} re-enqueued`, 'suc');
      loadQueuesPage();
    } else {
      al('queue-alert', res?.error || 'Retry failed', 'err');
    }
  } catch (e) {
    al('queue-alert', e.message, 'err');
  }
}

async function retryAllFailedJobsApi() {
  if (!confirm('Re-enqueue all failed jobs in the Dead-Letter Queue?')) return;
  try {
    const res = await post('queue/retry-all', {});
    if (res && res.ok) {
      al('queue-alert', res.message || 'All failed jobs re-enqueued', 'suc');
      loadQueuesPage();
    } else {
      al('queue-alert', res?.error || 'Failed to retry jobs', 'err');
    }
  } catch (e) {
    al('queue-alert', e.message, 'err');
  }
}

async function flushCompletedJobsApi() {
  try {
    const res = await post('queue/flush', { older_than_hours: 24 });
    if (res && res.ok) {
      al('queue-alert', res.message || 'Flushed completed jobs older than 24h', 'suc');
      loadQueuesPage();
    }
  } catch (e) {
    al('queue-alert', e.message, 'err');
  }
}

async function deleteFailedJobApi(id) {
  if (!confirm(`Permanently dismiss failed job #${id}?`)) return;
  try {
    const res = await del(`queue/failed/${id}`);
    if (res && res.ok) {
      al('queue-alert', `Job #${id} dismissed`, 'suc');
      loadQueuesPage();
    }
  } catch (e) {
    al('queue-alert', e.message, 'err');
  }
}

/* ══════════════════════════════════════════════════════════════════
   4. DATABASE STORAGE & TIME-SERIES ARCHIVING
   ══════════════════════════════════════════════════════════════════ */

async function loadDatabasePage() {
  const tb = $('db-tables-tbody');
  if (tb) tb.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:32px;color:var(--text3)">Analyzing database storage footprint...</td></tr>';

  try {
    const res = await get('database/stats');
    if (!res || !res.ok) {
      if (tb) tb.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:32px;color:var(--danger)">Error loading stats: ${esc(res?.error || 'Failed')}</td></tr>`;
      return;
    }

    const st = res.storage || {};
    set('db-stat-total-mb', (st.total_size_mb || 0) + ' MB');
    set('db-stat-free-mb', (st.free_space_mb || 0) + ' MB');

    const badge = $('db-engine-badge');
    if (badge) badge.textContent = (st.driver || 'MySQL').toUpperCase();

    const partStat = $('db-stat-partition-status');
    if (partStat) {
      partStat.textContent = res.partition_supported ? 'ACTIVE (RANGE)' : 'TABLE ARCHIVE';
      partStat.style.color = res.partition_supported ? 'var(--accent)' : 'var(--amber)';
    }

    const tables = st.tables || [];
    if (!tables.length) {
      if (tb) tb.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:32px;color:var(--text3)">No tables found.</td></tr>';
      return;
    }

    if (tb) {
      tb.innerHTML = tables.map(t => `
        <tr style="border-bottom:1px solid var(--border)">
          <td style="padding:12px 16px;font-weight:700;color:var(--text)">
            <code>${esc(t.name)}</code>
          </td>
          <td style="padding:12px 16px;text-align:right;color:var(--text2)">${Number(t.rows || 0).toLocaleString()}</td>
          <td style="padding:12px 16px;text-align:right;color:var(--text)">${t.data_mb} MB</td>
          <td style="padding:12px 16px;text-align:right;color:var(--text3)">${t.index_mb} MB</td>
          <td style="padding:12px 16px;text-align:right;font-weight:700;color:var(--accent2)">${t.total_mb} MB</td>
          <td style="padding:12px 16px;text-align:right;color:${Number(t.overhead_mb) > 5 ? 'var(--amber)' : 'var(--text3)'}">
            ${t.overhead_mb || 0} MB
          </td>
        </tr>
      `).join('');
    }
  } catch (e) {
    if (tb) tb.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:32px;color:var(--danger)">Error: ${esc(e.message)}</td></tr>`;
  }
}

async function optimizeDatabaseApi() {
  const btn = $('btn-db-optimize');
  btn.disabled = true;
  btn.textContent = 'Optimizing...';
  al('db-alert', 'Defragmenting indexes and reclaiming disk space...', 'inf');

  try {
    const res = await post('database/optimize', {});
    if (res && res.ok) {
      al('db-alert', 'Optimization completed successfully! Reclaimed fragmented disk space.', 'suc');
      loadDatabasePage();
    } else {
      al('db-alert', res?.error || 'Optimization failed', 'err');
    }
  } catch (e) {
    al('db-alert', e.message, 'err');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<span>🚀</span> Optimize &amp; Reclaim Space';
  }
}

function openArchivalModal() {
  $('db-archive-modal-al').className = 'al';
  $('db-archive-modal-al').innerHTML = '';
  showModal('db-archive-modal');
}

async function runArchivalApi() {
  const days = parseInt(v('db-archive-days') || 30, 10);
  const batch = parseInt(v('db-archive-batch') || 1000, 10);
  const btn = $('btn-execute-archival');
  btn.disabled = true;
  btn.textContent = 'Archiving...';
  al('db-archive-modal-al', 'Running zero-lock chunked batch migration...', 'inf');

  try {
    const res = await post('database/archive', { days, batch });
    if (res && res.ok) {
      closeModal('db-archive-modal');
      const sysCount = res.system_logs?.total_archived || 0;
      const sendCount = res.send_logs?.total_archived || 0;
      al('db-alert', `Archival completed: Archived ${sysCount} system logs and ${sendCount} send logs older than ${days} days.`, 'suc');
      loadDatabasePage();
    } else {
      al('db-archive-modal-al', res?.error || 'Archival failed', 'err');
    }
  } catch (e) {
    al('db-archive-modal-al', e.message, 'err');
  } finally {
    btn.disabled = false;
    btn.textContent = '📦 Start Archival';
  }
}
