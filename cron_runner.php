<?php
// Auto-detect Cron URL & Key if running on the same server
$autoCronUrl = '';
$cronKey = '';
if (file_exists(__DIR__ . '/includes/config.php')) {
    @require_once __DIR__ . '/includes/config.php';
    if (function_exists('getConfig')) {
        $cfg = getConfig();
        $cronKey = $cfg['cron_key'] ?? '';
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
        $autoCronUrl = $proto . '://' . $host . $dir . '/cron.php?key=' . urlencode($cronKey) . '&json=1';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>⚡ MailsZo — Real-Time HTML Cron Runner</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
<style>
:root {
  --bg: #060B16;
  --surface: #0F172A;
  --card: #111C2F;
  --border: rgba(255, 255, 255, 0.08);
  --border2: rgba(255, 255, 255, 0.16);
  --accent: #22C55E;
  --accent-glow: rgba(34, 197, 94, 0.35);
  --accent2: #06B6D4;
  --amber: #F59E0B;
  --red: #EF4444;
  --text: #F8FAFC;
  --text2: #94A3B8;
  --text3: #64748B;
  --font: 'Inter', system-ui, sans-serif;
  --mono: 'JetBrains Mono', monospace;
  --radius: 16px;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
  background: var(--bg);
  color: var(--text);
  font-family: var(--font);
  min-height: 100vh;
  padding: 30px 20px;
  display: flex;
  justify-content: center;
  align-items: flex-start;
  background-image: 
    radial-gradient(ellipse 80% 50% at 50% -20%, rgba(34, 197, 94, 0.12), transparent),
    radial-gradient(ellipse 60% 40% at 80% 80%, rgba(6, 182, 212, 0.08), transparent);
}
.wrap {
  width: 100%;
  max-width: 960px;
  display: flex;
  flex-direction: column;
  gap: 20px;
}
.header {
  background: linear-gradient(135deg, rgba(15, 23, 42, 0.88), rgba(17, 28, 47, 0.78));
  backdrop-filter: blur(20px);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 24px 28px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  box-shadow: 0 20px 50px rgba(0,0,0,0.5);
  flex-wrap: wrap;
  gap: 16px;
}
.logo-title {
  display: flex;
  align-items: center;
  gap: 14px;
}
.logo-icon {
  width: 48px;
  height: 48px;
  border-radius: 12px;
  background: linear-gradient(135deg, #22C55E, #06B6D4);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 24px;
  box-shadow: 0 8px 24px var(--accent-glow);
}
.logo-title h1 {
  font-size: 20px;
  font-weight: 800;
  letter-spacing: -0.02em;
}
.logo-title p {
  font-size: 12px;
  color: var(--text2);
  margin-top: 2px;
}
.status-pill {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 6px 14px;
  border-radius: 20px;
  font-size: 11px;
  font-weight: 700;
  font-family: var(--mono);
  background: rgba(255, 255, 255, 0.04);
  border: 1px solid var(--border);
}
.status-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--text3);
}
.status-pill.active {
  background: rgba(34, 197, 94, 0.12);
  border-color: rgba(34, 197, 94, 0.35);
  color: var(--accent);
}
.status-pill.active .status-dot {
  background: var(--accent);
  box-shadow: 0 0 10px var(--accent);
  animation: pulse 1.5s infinite;
}
@keyframes pulse { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: 0.4; transform: scale(0.75); } }

/* Cards */
.card {
  background: linear-gradient(135deg, rgba(15, 23, 42, 0.85), rgba(17, 28, 47, 0.75));
  backdrop-filter: blur(20px);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 22px;
  box-shadow: 0 10px 30px rgba(0,0,0,0.4);
}
.stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 14px;
}
.stat-box {
  background: rgba(255, 255, 255, 0.02);
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 16px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.stat-lbl {
  font-size: 11px;
  color: var(--text2);
  text-transform: uppercase;
  letter-spacing: 0.06em;
  font-weight: 600;
}
.stat-val {
  font-size: 24px;
  font-weight: 800;
  font-family: var(--mono);
  color: var(--text);
}

/* Control Row */
.ctrl-row {
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.input-wrap {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}
.cron-input {
  flex: 1;
  min-width: 280px;
  background: rgba(0, 0, 0, 0.35);
  border: 1px solid var(--border2);
  border-radius: 10px;
  padding: 12px 16px;
  color: var(--text);
  font-family: var(--mono);
  font-size: 13px;
  outline: none;
  transition: all .2s;
}
.cron-input:focus {
  border-color: var(--accent);
  box-shadow: 0 0 16px var(--accent-glow);
}
.interval-select {
  background: rgba(15, 23, 42, 0.95);
  border: 1px solid var(--border2);
  border-radius: 10px;
  padding: 12px 16px;
  color: var(--text);
  font-family: var(--font);
  font-size: 13px;
  outline: none;
  cursor: pointer;
}
.btn-group {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}
.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 12px 24px;
  border-radius: 10px;
  font-family: var(--font);
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  border: none;
  transition: all .2s;
  user-select: none;
}
.btn-start {
  background: linear-gradient(135deg, #22C55E, #16A34A);
  color: #040910;
  box-shadow: 0 4px 20px var(--accent-glow);
}
.btn-start:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 25px rgba(34, 197, 94, 0.6);
}
.btn-stop {
  background: linear-gradient(135deg, #EF4444, #DC2626);
  color: #fff;
  box-shadow: 0 4px 18px rgba(239, 68, 68, 0.4);
}
.btn-stop:hover {
  transform: translateY(-2px);
}
.btn-run-once {
  background: rgba(255, 255, 255, 0.06);
  color: var(--text);
  border: 1px solid var(--border2);
}
.btn-run-once:hover {
  background: rgba(255, 255, 255, 0.12);
}
.btn-clear {
  background: transparent;
  color: var(--text3);
  border: 1px solid var(--border);
  padding: 8px 16px;
  font-size: 11px;
}
.btn-clear:hover {
  color: var(--text);
  border-color: var(--border2);
}

/* Progress Bar */
.progress-wrap {
  height: 4px;
  background: rgba(255, 255, 255, 0.05);
  border-radius: 2px;
  overflow: hidden;
  position: relative;
}
.progress-bar {
  height: 100%;
  width: 0%;
  background: linear-gradient(90deg, var(--accent), var(--accent2));
  transition: width 0.1s linear;
}

/* Console Log Box */
.console-hd {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}
.console-hd h3 {
  font-size: 13px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--text2);
}
.console-box {
  background: rgba(3, 7, 18, 0.75);
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 16px;
  height: 320px;
  overflow-y: auto;
  font-family: var(--mono);
  font-size: 12px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.log-line {
  display: flex;
  gap: 10px;
  line-height: 1.5;
  word-break: break-all;
  border-bottom: 1px solid rgba(255, 255, 255, 0.02);
  padding-bottom: 4px;
}
.log-time { color: var(--text3); flex-shrink: 0; }
.log-ok { color: var(--accent); }
.log-warn { color: var(--amber); }
.log-err { color: var(--red); }
.log-info { color: var(--accent2); }

@media (max-width: 640px) {
  .header { flex-direction: column; align-items: flex-start; }
  .btn-group { width: 100%; }
  .btn { flex: 1; }
}
</style>
</head>
<body>

<div class="wrap">
  <!-- Topbar Header -->
  <div class="header">
    <div class="logo-title">
      <div class="logo-icon">⚡</div>
      <div>
        <h1>MailsZo HTML Cron Runner</h1>
        <p>Continuous Browser-Based Cron Engine for Auto-Reply & Follow-Up</p>
      </div>
    </div>
    <div id="status-pill" class="status-pill">
      <span class="status-dot"></span>
      <span id="status-text">PAUSED</span>
    </div>
  </div>

  <!-- Real-time Stats Cards -->
  <div class="stats-grid">
    <div class="stat-box">
      <div class="stat-lbl">Total Executions</div>
      <div class="stat-val" id="stat-runs">0</div>
    </div>
    <div class="stat-box">
      <div class="stat-lbl">Total Emails Sent</div>
      <div class="stat-val" id="stat-sent" style="color:var(--accent)">0</div>
    </div>
    <div class="stat-box">
      <div class="stat-lbl">Next Execution In</div>
      <div class="stat-val" id="stat-countdown" style="color:var(--accent2)">--</div>
    </div>
    <div class="stat-box">
      <div class="stat-lbl">Last Latency</div>
      <div class="stat-val" id="stat-latency">0 ms</div>
    </div>
  </div>

  <!-- Cron Settings & Controls -->
  <div class="card">
    <div class="ctrl-row">
      <div style="font-size:12px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:0.06em">
        ⚙️ Cron Configuration
      </div>
      <div class="input-wrap">
        <input type="text" id="cron-url" class="cron-input" placeholder="http://your-domain.com/cron.php?key=YOUR_KEY&json=1" value="<?= htmlspecialchars($autoCronUrl) ?>">
        <select id="cron-interval" class="interval-select">
          <option value="5">Every 5 Seconds</option>
          <option value="10">Every 10 Seconds</option>
          <option value="15" selected>Every 15 Seconds</option>
          <option value="30">Every 30 Seconds</option>
          <option value="60">Every 1 Minute</option>
        </select>
      </div>

      <div class="progress-wrap">
        <div id="progress-bar" class="progress-bar"></div>
      </div>

      <div class="btn-group">
        <button id="btn-start" class="btn btn-start" onclick="startRunner()">▶ Start Continuous Cron</button>
        <button id="btn-stop" class="btn btn-stop" onclick="stopRunner()" style="display:none">⏹ Stop Runner</button>
        <button id="btn-once" class="btn btn-run-once" onclick="runOnce()">⚡ Run Once Now</button>
      </div>
    </div>
  </div>

  <!-- Live Output Console -->
  <div class="card">
    <div class="console-hd">
      <h3>📟 Live Execution Console</h3>
      <button class="btn btn-clear" onclick="clearConsole()">Clear Console</button>
    </div>
    <div id="console-box" class="console-box">
      <div class="log-line">
        <span class="log-time">[System]</span>
        <span class="log-info">HTML Cron Runner initialized. Click "Start Continuous Cron" to begin processing queues in real time.</span>
      </div>
    </div>
  </div>
</div>

<script>
let _timer = null;
let _countdownTimer = null;
let _isRunning = false;
let _intervalSec = 15;
let _remainingSec = 0;
let _totalRuns = 0;
let _totalSent = 0;

// Initialize on page load
window.addEventListener('DOMContentLoaded', () => {
  const urlEl = document.getElementById('cron-url');
  if (!urlEl.value.trim()) {
    const loc = window.location;
    const base = loc.origin + loc.pathname.substring(0, loc.pathname.lastIndexOf('/'));
    urlEl.value = base + '/cron.php?key=<?= htmlspecialchars($cronKey) ?>&json=1';
  }
});

function log(msg, type = 'info') {
  const box = document.getElementById('console-box');
  const line = document.createElement('div');
  line.className = 'log-line';
  
  const now = new Date();
  const timeStr = now.toTimeString().split(' ')[0] + '.' + String(now.getMilliseconds()).padStart(3, '0');
  
  line.innerHTML = `<span class="log-time">[${timeStr}]</span> <span class="log-${type}">${msg}</span>`;
  box.appendChild(line);
  box.scrollTop = box.scrollHeight;
}

function clearConsole() {
  document.getElementById('console-box').innerHTML = '';
  log('Console cleared.', 'info');
}

async function triggerCron() {
  const url = document.getElementById('cron-url').value.trim();
  if (!url) {
    log('Error: Cron URL is empty.', 'err');
    return;
  }

  const startT = performance.now();
  _totalRuns++;
  document.getElementById('stat-runs').textContent = _totalRuns;

  try {
    const res = await fetch(url, { method: 'GET', cache: 'no-store' });
    const latency = Math.round(performance.now() - startT);
    document.getElementById('stat-latency').textContent = latency + ' ms';

    if (res.ok) {
      let data = null;
      try {
        data = await res.json();
      } catch (_e) {
        data = null;
      }

      if (data && data.results) {
        const okItems = data.results.filter(x => x.status === 'ok');
        const sentCount = okItems.reduce((acc, curr) => acc + (curr.sent || 0), 0);
        _totalSent += sentCount;
        document.getElementById('stat-sent').textContent = _totalSent;

        if (sentCount > 0) {
          log(`✅ Cron ran successfully in ${latency}ms — Sent ${sentCount} email(s)!`, 'ok');
        } else {
          log(`⚡ Cron tick #${_totalRuns} executed in ${latency}ms — Queues up to date (no pending due emails).`, 'info');
        }
      } else {
        log(`✅ Cron executed (HTTP ${res.status}) in ${latency}ms.`, 'ok');
      }
    } else {
      log(`⚠️ HTTP Error ${res.status}: ${res.statusText}`, 'err');
    }
  } catch (err) {
    const latency = Math.round(performance.now() - startT);
    log(`❌ Connection Error (${latency}ms): ${err.message}`, 'err');
  }
}

function startRunner() {
  if (_isRunning) return;
  _isRunning = true;
  
  _intervalSec = parseInt(document.getElementById('cron-interval').value, 10) || 15;
  _remainingSec = _intervalSec;

  document.getElementById('btn-start').style.display = 'none';
  document.getElementById('btn-stop').style.display = 'inline-flex';
  document.getElementById('cron-interval').disabled = true;
  document.getElementById('cron-url').disabled = true;

  const pill = document.getElementById('status-pill');
  pill.className = 'status-pill active';
  document.getElementById('status-text').textContent = `RUNNING (${_intervalSec}s)`;

  log(`🟢 Cron Runner started (Interval: ${_intervalSec}s). Executing first tick immediately...`, 'ok');
  triggerCron();

  // Reset Countdown
  _remainingSec = _intervalSec;
  updateCountdown();

  _countdownTimer = setInterval(() => {
    _remainingSec--;
    if (_remainingSec <= 0) {
      _remainingSec = _intervalSec;
      triggerCron();
    }
    updateCountdown();
  }, 1000);
}

function stopRunner() {
  if (!_isRunning) return;
  _isRunning = false;

  if (_countdownTimer) clearInterval(_countdownTimer);
  if (_timer) clearInterval(_timer);

  document.getElementById('btn-start').style.display = 'inline-flex';
  document.getElementById('btn-stop').style.display = 'none';
  document.getElementById('cron-interval').disabled = false;
  document.getElementById('cron-url').disabled = false;

  const pill = document.getElementById('status-pill');
  pill.className = 'status-pill';
  document.getElementById('status-text').textContent = 'PAUSED';
  document.getElementById('stat-countdown').textContent = '--';
  document.getElementById('progress-bar').style.width = '0%';

  log('🛑 Cron Runner stopped.', 'warn');
}

function runOnce() {
  log('⚡ Triggering one-time cron execution...', 'info');
  triggerCron();
}

function updateCountdown() {
  document.getElementById('stat-countdown').textContent = _remainingSec + 's';
  const pct = ((_intervalSec - _remainingSec) / _intervalSec) * 100;
  document.getElementById('progress-bar').style.width = pct + '%';
}
</script>
</body>
</html>
