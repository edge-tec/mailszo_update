<?php
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
require_once __DIR__ . '/includes/config.php';
if (!isInstalled()) { header('Location: install.php'); exit; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>MailsZo — Next-Gen Email Automation</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#060B16;--bg2:#0F172A;--bg3:#111C2F;--bg4:#1E293B;
  --surface:#0F172A;--card:#111C2F;--card-glass:rgba(17,28,47,0.78);
  --border:rgba(255,255,255,0.08);--border2:rgba(255,255,255,0.14);
  --accent:#22C55E;--accent-glow:rgba(34,197,94,0.32);
  --accent2:#06B6D4;--accent3:#F59E0B;
  --red:#EF4444;--purple:#8B5CF6;
  --blue:#38BDF8;--amber:#F59E0B;--orange:#FB923C;
  --text:#F8FAFC;--text2:#94A3B8;--text3:#64748B;
  --font:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
  --mono:'JetBrains Mono','Space Mono',monospace;
  --radius:16px;--radius-sm:10px;--radius-lg:22px;--sidebar:240px;
  --shadow:0 12px 35px -10px rgba(0,0,0,0.65),inset 0 1px 0 rgba(255,255,255,0.12);
  --shadow-hover:0 22px 50px -12px rgba(0,0,0,0.85),inset 0 1px 1px rgba(255,255,255,0.22);
}
html[data-theme="light"]{
  --bg:#F8FAFC;--bg2:#FFFFFF;--bg3:#F1F5F9;--bg4:#E2E8F0;
  --surface:#FFFFFF;--card:#FFFFFF;--card-glass:rgba(255,255,255,0.85);
  --border:#E2E8F0;--border2:#CBD5E1;
  --accent:#16A34A;--accent-glow:rgba(22,163,74,0.25);
  --accent2:#0284C7;--accent3:#D97706;
  --red:#DC2626;--purple:#7C3AED;
  --text:#0F172A;--text2:#475569;--text3:#94A3B8;
  --shadow:0 10px 30px -5px rgba(0,0,0,0.06),inset 0 1px 0 rgba(255,255,255,0.9);
  --shadow-hover:0 20px 40px -8px rgba(0,0,0,0.12),inset 0 1px 1px rgba(255,255,255,1);
}
body{font-family:var(--font);background:var(--bg);color:var(--text);min-height:100vh;display:flex;overflow-x:hidden;transition:background .25s cubic-bezier(.16,1,.3,1),color .25s ease}

/* ── 2026 GLASS SIDEBAR (Linear + Raycast Inspired) ── */
#sidebar{width:var(--sidebar);height:100vh;background:linear-gradient(180deg,rgba(15,23,42,0.94) 0%,rgba(6,11,22,0.98) 100%);backdrop-filter:blur(24px) saturate(180%);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;top:0;left:0;z-index:100;overflow:hidden;box-shadow:8px 0 35px rgba(0,0,0,0.45);transition:width .24s cubic-bezier(.16,1,.3,1)}
html[data-theme="light"] #sidebar{background:linear-gradient(180deg,#FFFFFF 0%,#F8FAFC 100%);border-right-color:var(--border);box-shadow:8px 0 30px rgba(0,0,0,0.06)}
body.collapsed-sb #sidebar{width:72px}
body.collapsed-sb #sidebar .sb-logo-tx,body.collapsed-sb #sidebar .nsec,body.collapsed-sb #sidebar .sb-uinfo,body.collapsed-sb #sidebar .ni-txt,body.collapsed-sb #sidebar .sb-quota-pill{display:none!important}
body.collapsed-sb #sidebar .ni{justify-content:center;padding:10px 0;margin:3px 8px}
body.collapsed-sb #sidebar .ni-ic{margin:0;font-size:18px}
body.collapsed-sb #main{margin-left:72px}

.sb-logo{padding:20px 18px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:11px;flex-shrink:0}
.sb-logo-ic{width:38px;height:38px;background:linear-gradient(135deg,rgba(34,197,94,0.22),rgba(6,182,212,0.15));border:1px solid rgba(34,197,94,0.35);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:18px;box-shadow:0 0 20px rgba(34,197,94,0.25)}
.sb-logo-tx{font-family:var(--font);font-size:15px;font-weight:800;letter-spacing:-0.02em}.sb-logo-tx span{color:var(--accent);text-shadow:0 0 14px var(--accent-glow)}.sb-logo-tx small{font-size:10px;font-weight:600;opacity:.55;margin-left:4px;padding:2px 6px;border-radius:6px;background:rgba(255,255,255,0.06)}

.sb-nav{flex:1;padding:12px 0;overflow-y:auto;overflow-x:hidden;min-height:0;scrollbar-width:thin;scrollbar-color:var(--border) transparent}
.sb-nav::-webkit-scrollbar{width:4px}
.sb-nav::-webkit-scrollbar-thumb{background:var(--border2);border-radius:2px}
.nsec{font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.09em;padding:16px 18px 6px;display:block}
.ni{display:flex;align-items:center;gap:10px;padding:9px 16px;cursor:pointer;font-size:13px;font-weight:500;color:var(--text2);position:relative;transition:all .18s cubic-bezier(.16,1,.3,1);user-select:none;margin:2px 10px;border-radius:10px}
.ni:hover{background:rgba(255,255,255,0.05);color:var(--text);transform:translate3d(3px,0,0)}
.ni.active{background:linear-gradient(90deg,rgba(34,197,94,0.15) 0%,rgba(6,182,212,0.05) 100%);color:var(--accent);font-weight:600;box-shadow:0 2px 12px rgba(34,197,94,0.12),inset 0 1px 0 rgba(255,255,255,0.1)}
.ni.active::before{content:'';position:absolute;left:0;top:6px;bottom:6px;width:3px;background:var(--accent);border-radius:0 3px 3px 0;box-shadow:0 0 12px var(--accent)}
.ni-ic{font-size:15px;width:20px;text-align:center;flex-shrink:0}

.admin-sec{border-top:1px solid rgba(139,92,246,.2);margin-top:8px;background:rgba(139,92,246,.02)}
.admin-sec .nsec{color:rgba(167,139,250,.85)}
.admin-sec .ni.active{background:linear-gradient(90deg,rgba(139,92,246,0.18) 0%,rgba(139,92,246,0.04) 100%);color:var(--purple);box-shadow:0 2px 12px rgba(139,92,246,0.15)}
.admin-sec .ni.active::before{background:var(--purple);box-shadow:0 0 10px var(--purple)}
.admin-sec .ni:hover{background:rgba(139,92,246,.08)}

.sb-foot{padding:14px 16px;border-top:1px solid var(--border);flex-shrink:0;background:rgba(0,0,0,0.15)}
.sb-user{display:flex;align-items:center;gap:10px;font-size:12px;color:var(--text2)}
.sb-av{width:34px;height:34px;background:linear-gradient(135deg,var(--accent),var(--accent2));border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;color:#040910;flex-shrink:0;box-shadow:0 2px 10px var(--accent-glow)}
.sb-uinfo{flex:1;min-width:0}.sb-uinfo .nm{font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:13px}.sb-uinfo .rl{font-size:10px;color:var(--text3);margin-top:1px}
.sb-logout{cursor:pointer;color:var(--text3);font-size:18px;transition:all .15s;flex-shrink:0;padding:4px;border-radius:6px}.sb-logout:hover{color:var(--red);background:rgba(239,68,68,0.1)}

/* ── STICKY GLASS TOPBAR ── */
#main{margin-left:var(--sidebar);flex:1;min-width:0;min-height:100vh;display:flex;flex-direction:column;perspective:1200px}
.topbar{background:rgba(15,23,42,0.85);backdrop-filter:blur(20px) saturate(180%);border-bottom:1px solid var(--border);padding:12px 28px;display:flex;align-items:center;position:sticky;top:0;z-index:50;box-shadow:0 4px 24px rgba(0,0,0,0.35);gap:12px}
html[data-theme="light"] .topbar{background:rgba(255,255,255,0.85);box-shadow:0 4px 20px rgba(0,0,0,0.04)}
.tb-title{font-size:16px;font-weight:700;letter-spacing:-0.02em}
.tb-search-pill{display:flex;align-items:center;gap:8px;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:10px;padding:6px 14px;color:var(--text2);font-size:12px;cursor:pointer;transition:all .2s;min-width:240px}
.tb-search-pill:hover{background:rgba(255,255,255,0.08);border-color:var(--border2);color:var(--text)}
.tb-search-pill .kbd{background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.12);border-radius:5px;padding:1px 6px;font-family:var(--mono);font-size:10px;margin-left:auto}

.tb-right{margin-left:auto;display:flex;align-items:center;gap:10px}
.page{padding:28px;display:none;animation:pageFade .24s cubic-bezier(.16,1,.3,1)}.page.active{display:block}
@keyframes pageFade{from{opacity:0;transform:translate3d(0,8px,0)}to{opacity:1;transform:translate3d(0,0,0)}}

/* ── 2026 HERO GREETING BANNER ── */
.dash-hero-banner{
  background:linear-gradient(135deg,rgba(15,23,42,0.92) 0%,rgba(17,28,47,0.85) 100%);
  backdrop-filter:blur(20px);border:1px solid var(--border);border-radius:var(--radius);
  padding:22px 26px;margin-bottom:22px;display:flex;align-items:center;gap:20px;flex-wrap:wrap;
  box-shadow:var(--shadow);position:relative;overflow:hidden;
}
.dash-hero-banner::before{
  content:'';position:absolute;top:-50%;left:-20%;width:300px;height:300px;
  background:radial-gradient(circle,rgba(34,197,94,0.12),transparent 70%);pointer-events:none;
}
.dash-hero-left{flex:1;min-width:260px}
.dash-hero-title{font-size:20px;font-weight:800;letter-spacing:-0.02em;color:var(--text);display:flex;align-items:center;gap:8px}
.dash-hero-sub{font-size:12px;color:var(--text2);margin-top:4px}
.dash-hero-chips{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:12px}
.dash-hero-chip{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:8px;padding:4px 10px;font-size:11px;font-weight:600;color:var(--text2)}
.dash-hero-chip strong{color:var(--text);font-family:var(--mono)}

/* ── 2026 MODERN KPI CARDS WITH SPARKLINES ── */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px;margin-bottom:22px}
.sc{
  background:linear-gradient(135deg,rgba(15,23,42,0.82) 0%,rgba(17,28,47,0.72) 100%);
  backdrop-filter:blur(20px) saturate(180%);border:1px solid var(--border);
  border-radius:var(--radius);padding:18px 18px 14px;position:relative;overflow:hidden;min-width:0;
  box-shadow:var(--shadow);transition:all .22s cubic-bezier(.16,1,.3,1);will-change:transform,box-shadow;
}
.sc:hover{
  transform:translate3d(0,-4px,6px);box-shadow:var(--shadow-hover);border-color:var(--border2);
}
.sc::after{
  content:'';position:absolute;top:0;left:0;right:0;height:3px;
  background:linear-gradient(90deg,var(--sc-c,var(--accent)),rgba(255,255,255,0.3));
  box-shadow:0 0 12px var(--sc-c,var(--accent));
}
.sc-lbl{font-size:10px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px;display:flex;align-items:center;justify-content:space-between}
.sc-val{font-family:var(--mono);font-size:26px;font-weight:800;line-height:1;letter-spacing:-0.03em}
.sc-sub{font-size:10px;color:var(--text3);margin-top:6px;font-family:var(--mono)}
.sc-sparkline{margin-top:10px;height:24px;width:100%;display:flex;align-items:flex-end;gap:3px}
.sc-sparkbar{flex:1;background:var(--sc-c,var(--accent));opacity:0.35;border-radius:2px;transition:height .4s cubic-bezier(.4,0,.2,1),opacity .2s}
.sc:hover .sc-sparkbar{opacity:0.85}

.card{
  background:linear-gradient(135deg,rgba(15,23,42,0.85) 0%,rgba(17,28,47,0.75) 100%);
  backdrop-filter:blur(20px);border:1px solid var(--border);
  border-radius:var(--radius);margin-bottom:22px;min-width:0;
  box-shadow:var(--shadow);transition:all .22s cubic-bezier(.16,1,.3,1);
}
.card:hover{border-color:var(--border2)}
.card-hd{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;background:rgba(255,255,255,0.02)}
.card-hd h3{font-size:14px;font-weight:700;flex:1;letter-spacing:-0.01em}
.card-body{padding:20px}

/* ── 2026 BUTTONS & FORM INPUTS ── */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:9px 18px;border-radius:10px;border:1px solid var(--border);font-family:var(--font);font-size:12px;font-weight:600;cursor:pointer;transition:all .18s cubic-bezier(.16,1,.3,1);white-space:nowrap;box-shadow:0 4px 12px rgba(0,0,0,0.25),inset 0 1px 0 rgba(255,255,255,0.15);user-select:none}
.btn:hover:not(:disabled){transform:translate3d(0,-2px,4px);box-shadow:0 8px 22px rgba(0,0,0,0.4),inset 0 1px 0 rgba(255,255,255,0.25)}
.btn:active:not(:disabled){transform:translate3d(0,0,0);box-shadow:0 2px 6px rgba(0,0,0,0.2)}
.btn-primary{background:linear-gradient(135deg,#22C55E 0%,#16A34A 100%);color:#040910;border-color:rgba(255,255,255,0.4);font-weight:700;box-shadow:0 6px 20px rgba(34,197,94,0.35),inset 0 1px 0 rgba(255,255,255,0.6)}
.btn-primary:hover:not(:disabled){background:linear-gradient(135deg,#4ADE80 0%,#22C55E 100%);box-shadow:0 10px 25px rgba(34,197,94,0.5),inset 0 1px 0 rgba(255,255,255,0.8)}
.btn-secondary{background:linear-gradient(180deg,rgba(255,255,255,0.06) 0%,rgba(255,255,255,0.02) 100%);color:var(--text2);border:1px solid var(--border)}
.btn-secondary:hover:not(:disabled){background:linear-gradient(180deg,rgba(255,255,255,0.12) 0%,rgba(255,255,255,0.04) 100%);color:var(--text);border-color:var(--border2)}
.btn-danger{background:linear-gradient(135deg,rgba(239,68,68,0.2) 0%,rgba(220,38,38,0.1) 100%);color:var(--red);border-color:rgba(239,68,68,0.3)}
.btn-danger:hover:not(:disabled){background:linear-gradient(135deg,rgba(239,68,68,0.35) 0%,rgba(220,38,38,0.2) 100%);box-shadow:0 6px 20px rgba(239,68,68,0.3)}
.btn-blue{background:linear-gradient(135deg,rgba(6,182,212,0.2) 0%,rgba(14,165,233,0.1) 100%);color:var(--accent2);border-color:rgba(6,182,212,0.3)}
.btn-blue:hover:not(:disabled){background:linear-gradient(135deg,rgba(6,182,212,0.35) 0%,rgba(14,165,233,0.2) 100%);box-shadow:0 6px 20px rgba(6,182,212,0.3)}
.btn-amber{background:linear-gradient(135deg,rgba(245,158,11,0.2) 0%,rgba(217,119,6,0.1) 100%);color:var(--accent3);border-color:rgba(245,158,11,0.3)}
.btn-amber:hover:not(:disabled){background:linear-gradient(135deg,rgba(245,158,11,0.35) 0%,rgba(217,119,6,0.2) 100%);box-shadow:0 6px 20px rgba(245,158,11,0.3)}
.btn-purple{background:linear-gradient(135deg,rgba(139,92,246,0.2) 0%,rgba(124,58,237,0.1) 100%);color:var(--purple);border-color:rgba(139,92,246,0.3)}
.btn-purple:hover:not(:disabled){background:linear-gradient(135deg,rgba(139,92,246,0.35) 0%,rgba(124,58,237,0.2) 100%);box-shadow:0 6px 20px rgba(139,92,246,0.3)}
.btn-sm{padding:5px 12px;font-size:11px;border-radius:8px}
.btn-group{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.btn:disabled{opacity:.4;cursor:not-allowed}

.fi,.fsel,.fta{width:100%;background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:10px;padding:10px 14px;color:var(--text);font-family:var(--font);font-size:13px;outline:none;box-shadow:inset 0 2px 4px rgba(0,0,0,0.3);transition:all .2s cubic-bezier(.16,1,.3,1)}
.fi:focus,.fsel:focus,.fta:focus{border-color:var(--accent);box-shadow:inset 0 1px 3px rgba(0,0,0,0.2),0 0 18px var(--accent-glow)}
.fsel option{background:#0F172A;color:var(--text)}
.fta{resize:vertical;min-height:100px;line-height:1.6}

/* ── 2026 MODAL SYSTEM & FORMS ── */
.modal-bg{position:fixed;inset:0;background:rgba(2,6,14,0.85);backdrop-filter:blur(18px);z-index:9000;display:none;align-items:center;justify-content:center;padding:16px}
.modal-bg.on{display:flex!important}
.modal{background:linear-gradient(180deg,rgba(15,23,42,0.98) 0%,rgba(17,28,47,0.96) 100%);backdrop-filter:blur(30px);border:1px solid var(--border2);border-radius:var(--radius);width:100%;max-width:640px;max-height:92vh;display:flex;flex-direction:column;box-shadow:0 30px 100px rgba(0,0,0,0.9),0 0 40px var(--accent-glow);animation:mz .22s cubic-bezier(.16,1,.3,1)}
.modal-lg{max-width:880px}.modal-xl{max-width:1120px}
@keyframes mz{from{transform:scale(.94) translate3d(0,10px,0);opacity:0}to{transform:scale(1) translate3d(0,0,0);opacity:1}}
.modal-hd{padding:16px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;flex-shrink:0;background:rgba(255,255,255,0.02)}
.modal-hd h3{flex:1;font-size:15px;font-weight:700;letter-spacing:-0.01em}
.modal-x{cursor:pointer;color:var(--text3);font-size:18px;line-height:1;transition:all .15s;padding:4px 8px;border-radius:6px}
.modal-x:hover{color:var(--red);background:rgba(239,68,68,0.1)}
.modal-body{padding:22px;overflow-y:auto;flex:1}
.modal-foot{padding:14px 22px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px;flex-shrink:0;background:rgba(0,0,0,0.15)}

.fg{margin-bottom:14px}
.fl{display:block;font-size:11px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.07em;margin-bottom:6px}
.flh{font-weight:400;text-transform:none;color:var(--text3);font-size:10px}
.frow{display:grid;gap:12px}.fc2{grid-template-columns:1fr 1fr}.fc3{grid-template-columns:1fr 1fr 1fr}
.fhint{font-size:10px;color:var(--text3);margin-top:4px;line-height:1.4}
.al{padding:11px 16px;border-radius:10px;font-size:12px;margin-bottom:14px;display:none;line-height:1.5;box-shadow:0 4px 14px rgba(0,0,0,0.2)}
.al.on{display:block!important}
.a-ok{background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);color:var(--accent)}
.a-err{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--red)}
.a-inf{background:rgba(6,182,212,0.1);border:1px solid rgba(6,182,212,0.3);color:var(--accent2)}
.a-warn{background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.3);color:var(--accent3)}
.sep{height:1px;background:var(--border);margin:18px 0}
.stitle{font-size:11px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.07em;margin-bottom:10px;margin-top:6px;display:flex;align-items:center;gap:8px}
.stitle::after{content:'';flex:1;height:1px;background:var(--border)}

.tags-wrap{min-height:42px;background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:10px;padding:6px 10px;display:flex;flex-wrap:wrap;gap:6px;cursor:text;transition:border-color .2s}
.tags-wrap:focus-within{border-color:var(--accent);box-shadow:0 0 16px var(--accent-glow)}
.tag{display:inline-flex;align-items:center;gap:5px;background:rgba(6,182,212,.12);border:1px solid rgba(6,182,212,.25);color:var(--accent2);font-size:11px;font-weight:600;padding:3px 10px;border-radius:20px}
.tag-x{cursor:pointer;color:var(--text3);font-size:13px;margin-left:2px}.tag-x:hover{color:var(--red)}
.tag-inp{flex:1;min-width:160px;background:transparent;border:none;outline:none;color:var(--text);font-family:var(--font);font-size:12px;padding:3px 4px}
.smtp-pool{display:flex;flex-direction:column;gap:5px;background:rgba(255,255,255,0.02);border:1px solid var(--border);border-radius:10px;padding:10px;max-height:180px;overflow-y:auto}
.spl{display:flex;align-items:center;gap:10px;font-size:12px;color:var(--text2);cursor:pointer;padding:7px 10px;border-radius:8px;border:1px solid transparent;transition:all .14s}
.spl:hover{background:rgba(255,255,255,0.05);color:var(--text)}
.spl.ck{background:rgba(34,197,94,.08);border-color:rgba(34,197,94,.25);color:var(--accent)}
.spl input[type=checkbox]{accent-color:var(--accent);width:15px;height:15px;flex-shrink:0}

.vtabs{display:flex;border-bottom:2px solid var(--border);overflow-x:auto;flex-shrink:0}
.vtab{padding:10px 18px;font-size:12px;font-weight:700;color:var(--text3);cursor:pointer;transition:all .15s;border-bottom:2px solid transparent;margin-bottom:-2px;white-space:nowrap;flex-shrink:0}
.vtab:hover{color:var(--text2)}
.vtab.va{color:var(--accent);border-bottom-color:var(--accent)}
.vtab.vadd{color:var(--accent2)}
.vpanes{border:1px solid var(--border);border-top:none;border-radius:0 0 10px 10px}
.vpane{display:none;padding:18px}.vpane.va{display:block}

.device-frame-desktop{width:100%;height:520px;border:1px solid var(--border);border-radius:12px;background:#fff}
.device-frame-mobile{width:375px;height:620px;border:3px solid var(--border2);border-radius:24px;background:#fff;box-shadow:0 15px 40px rgba(0,0,0,0.8)}

/* ── COMMAND PALETTE (RAYCAST / LINEAR STYLE) ── */
#cmd-palette-bg{position:fixed;inset:0;background:rgba(3,7,18,0.85);backdrop-filter:blur(18px);z-index:99999;display:none;align-items:flex-start;justify-content:center;padding:10vh 16px 16px}
#cmd-palette-bg.on{display:flex}
.cmd-palette-box{background:linear-gradient(180deg,rgba(15,23,42,0.96) 0%,rgba(17,28,47,0.92) 100%);backdrop-filter:blur(30px);border:1px solid var(--border2);border-radius:var(--radius);width:100%;max-width:620px;box-shadow:0 30px 100px rgba(0,0,0,0.85),0 0 40px var(--accent-glow);overflow:hidden;animation:cmdPop .2s cubic-bezier(.16,1,.3,1)}
@keyframes cmdPop{from{opacity:0;transform:scale(0.96) translateY(-10px)}to{opacity:1;transform:scale(1) translateY(0)}}
.cmd-inp-wrap{display:flex;align-items:center;gap:12px;padding:16px 20px;border-bottom:1px solid var(--border)}
.cmd-inp{flex:1;background:transparent;border:none;outline:none;font-family:var(--font);font-size:15px;color:var(--text)}
.cmd-list{max-height:360px;overflow-y:auto;padding:8px}
.cmd-group-hd{font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.08em;padding:10px 12px 4px}
.cmd-item{display:flex;align-items:center;gap:12px;padding:10px 14px;border-radius:10px;cursor:pointer;color:var(--text2);font-size:13px;transition:all .14s;user-select:none}
.cmd-item:hover,.cmd-item.selected{background:rgba(34,197,94,0.12);color:var(--accent)}
.cmd-item-ic{font-size:16px;width:20px;text-align:center}
.cmd-item-txt{flex:1;font-weight:500}
.cmd-item-kbd{background:rgba(255,255,255,0.06);border:1px solid var(--border);border-radius:5px;padding:2px 6px;font-family:var(--mono);font-size:10px;color:var(--text3)}
.cmd-foot{padding:10px 18px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;font-size:11px;color:var(--text3);background:rgba(0,0,0,0.2)}

/* ── SLIDE-OUT NOTIFICATION DRAWER ── */
#notif-drawer-bg{position:fixed;inset:0;background:rgba(0,0,0,0.6);backdrop-filter:blur(4px);z-index:9990;display:none}
#notif-drawer-bg.on{display:block}
#notif-drawer{position:fixed;top:0;right:-400px;width:380px;height:100vh;background:linear-gradient(180deg,rgba(15,23,42,0.98) 0%,rgba(6,11,22,0.99) 100%);backdrop-filter:blur(24px);border-left:1px solid var(--border);z-index:9995;display:flex;flex-direction:column;box-shadow:-10px 0 40px rgba(0,0,0,0.6);transition:right .28s cubic-bezier(.16,1,.3,1)}
#notif-drawer.open{right:0}
.notif-hd{padding:18px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.notif-body{flex:1;overflow-y:auto;padding:14px}
.notif-item{background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:12px;padding:12px 14px;margin-bottom:10px;transition:all .18s;position:relative}
.notif-item:hover{background:rgba(255,255,255,0.06);border-color:var(--border2)}
.notif-title{font-size:12px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:6px}
.notif-desc{font-size:11px;color:var(--text2);margin-top:4px;line-height:1.4}
.notif-time{font-size:9px;color:var(--text3);font-family:var(--mono);margin-top:6px}

/* ── FLOATING ACTION BUTTON (FAB) ── */
.fab-btn{position:fixed;bottom:26px;right:26px;width:52px;height:52px;border-radius:50%;background:linear-gradient(135deg,#22C55E,#06B6D4);color:#040910;border:1px solid rgba(255,255,255,0.4);display:flex;align-items:center;justify-content:center;font-size:24px;cursor:pointer;z-index:800;box-shadow:0 8px 30px var(--accent-glow);transition:all .22s cubic-bezier(.16,1,.3,1)}
.fab-btn:hover{transform:scale(1.1) rotate(90deg);box-shadow:0 12px 35px rgba(34,197,94,0.6)}

/* ── MODERN TABLES ── */
.tw{overflow-x:auto}
table{width:100%;border-collapse:separate;border-spacing:0 4px;font-size:12px}
th{background:rgba(255,255,255,0.02);padding:11px 16px;text-align:left;font-size:10px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.08em;white-space:nowrap;border-bottom:1px solid var(--border)}
td{padding:12px 16px;background:rgba(255,255,255,0.015);border-top:1px solid var(--border);border-bottom:1px solid var(--border);vertical-align:middle;transition:background .15s}
td:first-child{border-left:1px solid var(--border);border-radius:10px 0 0 10px}
td:last-child{border-right:1px solid var(--border);border-radius:0 10px 10px 0}
tr:hover td{background:rgba(255,255,255,0.045)}
.badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700;letter-spacing:0.02em}
.b-green{background:rgba(34,197,94,0.12);color:var(--accent);border:1px solid rgba(34,197,94,0.3);box-shadow:0 0 10px var(--accent-glow)}
.b-blue{background:rgba(6,182,212,0.12);color:var(--accent2);border:1px solid rgba(6,182,212,0.3)}
.b-amber{background:rgba(245,158,11,0.12);color:var(--accent3);border:1px solid rgba(245,158,11,0.3)}
.b-red{background:rgba(239,68,68,0.12);color:var(--red);border:1px solid rgba(239,68,68,0.3)}
.b-purple{background:rgba(139,92,246,0.12);color:var(--purple);border:1px solid rgba(139,92,246,0.3)}
.b-gray{background:rgba(148,163,184,0.08);color:var(--text2);border:1px solid var(--border)}
code{background:var(--bg3);border:1px solid var(--border);border-radius:4px;padding:1px 6px;font-family:var(--mono);font-size:11px;color:var(--accent2)}
.spin-ic{display:inline-block;width:12px;height:12px;border:2px solid rgba(0,0,0,.2);border-top-color:currentColor;border-radius:50%;animation:rot .6s linear infinite}
@keyframes rot{to{transform:rotate(360deg)}}
.mono{font-family:var(--mono);font-size:11px}
.live-badge{display:none;align-items:center;gap:5px;font-size:10px;font-weight:700;color:var(--accent);background:rgba(74,222,128,.1);border:1px solid rgba(74,222,128,.3);padding:2px 8px;border-radius:20px}
.live-dot{width:6px;height:6px;background:var(--accent);border-radius:50%;animation:pulse 1.5s infinite}
@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.4;transform:scale(.7)}}
#login-wrap{position:fixed;inset:0;background:radial-gradient(ellipse at 25% 60%,rgba(74,222,128,.07),transparent 55%),var(--bg);display:flex;align-items:center;justify-content:center;z-index:99999}
.login-card{background:var(--bg2);border:1px solid var(--border2);border-radius:16px;width:90%;max-width:380px;padding:32px;box-shadow:0 30px 80px rgba(0,0,0,.5)}
.login-logo{text-align:center;margin-bottom:24px}
.login-logo .ic{font-size:44px;margin-bottom:10px}
.login-logo h1{font-family:var(--mono);font-size:22px;font-weight:700}
.login-logo h1 span{color:var(--accent)}
.login-logo p{font-size:12px;color:var(--text3);margin-top:4px}

/* ── SCROLLABLE SIDEBAR ─────────────────── */
/* Sidebar scroll: logo & footer are fixed, nav area scrolls */
#sidebar{display:flex;flex-direction:column;height:100vh;overflow:hidden}
.sb-logo{flex-shrink:0}
.sb-nav{flex:1;overflow-y:auto;overflow-x:hidden;min-height:0;
  scrollbar-width:thin;scrollbar-color:var(--border2) transparent}
.sb-nav::-webkit-scrollbar{width:4px}
.sb-nav::-webkit-scrollbar-track{background:transparent}
.sb-nav::-webkit-scrollbar-thumb{background:var(--border2);border-radius:2px}
.sb-nav::-webkit-scrollbar-thumb:hover{background:var(--border)}
.sb-foot{flex-shrink:0}

/* ── RESPONSIVE / MOBILE ────────────────── */
/* Hamburger button (mobile only) */
#menu-toggle{display:none;position:fixed;top:12px;left:12px;z-index:200;background:var(--bg2);border:1px solid var(--border2);color:var(--text);border-radius:8px;width:40px;height:40px;font-size:20px;cursor:pointer;align-items:center;justify-content:center;box-shadow:0 2px 12px rgba(0,0,0,.4)}
#sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:99;backdrop-filter:blur(2px)}

/* Tablet & small desktop (900px–1200px) */
@media (max-width:1200px) and (min-width:901px){
  :root{--sidebar:210px}
  .page{padding:20px}
  .stats-grid{grid-template-columns:repeat(auto-fill,minmax(140px,1fr))}
  .modal-xl{max-width:860px}
}

/* Tablet & mobile (<= 900px) */
@media (max-width:900px){
  :root{--sidebar:0px}
  body{flex-direction:column}
  #sidebar{position:fixed;left:-270px;width:270px;height:100vh;transition:left .28s cubic-bezier(.4,0,.2,1);z-index:150;box-shadow:4px 0 30px rgba(0,0,0,.5)}
  #sidebar.open{left:0}
  #sidebar-overlay.on{display:block}
  #menu-toggle{display:flex}
  #main{margin-left:0;width:100%}
  .topbar{padding:12px 14px 12px 60px}
  .page{padding:16px}
  .stats-grid{grid-template-columns:repeat(2,1fr)!important;gap:8px}
  .frow.fc2,.frow.fc3{grid-template-columns:1fr!important}
  .tw{overflow-x:auto;-webkit-overflow-scrolling:touch}
  table{min-width:560px}
  .modal{max-width:100%!important;margin:8px}
  .modal-body{padding:14px}
  .btn-group{flex-wrap:wrap}
  .card-hd{flex-wrap:wrap;gap:6px}
  .card-hd h3{width:100%;margin-bottom:2px}
  #admin-quick-bar{padding:8px 14px;gap:6px;flex-wrap:wrap}
  .login-card{padding:24px;max-width:360px}
  /* Inline search inputs full width on tablet */
  .fi[style*="max-width"]{max-width:100%!important;width:100%}
}

/* Mobile (<= 600px) */
@media (max-width:600px){
  .stats-grid{grid-template-columns:1fr 1fr!important;gap:7px}
  .sc-val{font-size:20px}
  .sc{padding:12px}
  .page{padding:10px}
  .topbar{padding:10px 10px 10px 54px}
  .tb-title{font-size:14px}
  .modal{margin:0!important;border-radius:12px 12px 0 0!important;max-height:90vh!important}
  .modal-bg{padding:0;align-items:flex-end}
  .card-hd{padding:10px 12px;flex-wrap:wrap;gap:5px}
  .card-hd h3{width:100%;margin-bottom:2px;font-size:12px}
  .card-body{padding:10px 12px}
  .btn{padding:6px 11px;font-size:11px}
  .btn-sm{padding:3px 8px;font-size:10px}
  .btn-group{gap:4px}
  th,td{padding:7px 8px}
  table{min-width:460px}
  .vtabs{overflow-x:auto;-webkit-overflow-scrolling:touch}
  .vtab{padding:7px 12px;font-size:11px;white-space:nowrap}
  /* stack cron grid */
  #page-cron > div[style*="grid-template-columns:1fr 1fr"]{grid-template-columns:1fr!important}
  /* account page */
  #page-account > div{grid-template-columns:1fr!important}
  /* inline inputs */
  .fi[style*="max-width"]{max-width:100%!important;width:100%}
  /* login */
  .login-card{padding:20px;width:94%}
  /* pager wrap */
  [id$="-pager"]{flex-wrap:wrap;gap:4px;padding:8px 0}
}

/* Small mobile (<= 400px) */
@media (max-width:400px){
  .stats-grid{grid-template-columns:1fr!important}
  .btn-group{flex-direction:column;align-items:stretch}
  .btn-group .btn{width:100%;justify-content:center}
  table{min-width:380px}
  th,td{padding:6px 7px;font-size:11px}
}

/* Large desktop — auto-fit so each Dashboard section sizes its own card row
   independently (5 / 6 / 3 cards across the three sections). The previous
   forced "repeat(7,1fr)" left awkward empty cells in the shorter sections. */
@media (min-width:1400px){
  .stats-grid{grid-template-columns:repeat(auto-fit,minmax(170px,1fr))}
  .page{padding:30px}
}
@media (min-width:1800px){
  :root{--sidebar:260px}
}

/* ─── Dashboard panel headers (admin vs user) ────────────────── */
.dash-panel-hd{display:flex;align-items:center;gap:14px;padding:14px 18px;border-radius:var(--radius);margin-bottom:18px;border:1px solid var(--border2);}
.dash-panel-hd.user-hd{background:linear-gradient(135deg,rgba(74,222,128,.07) 0%,rgba(34,211,238,.03) 100%);border-color:rgba(74,222,128,.22);}
.dash-panel-hd.admin-hd{background:linear-gradient(135deg,rgba(167,139,250,.08) 0%,rgba(167,139,250,.02) 100%);border-color:rgba(167,139,250,.28);}
.dash-panel-icon{font-size:26px;flex-shrink:0;}
.dash-panel-text{flex:1;min-width:0;}
.dash-panel-title{font-size:14px;font-weight:700;color:var(--text);}
.dash-panel-sub{font-size:11px;color:var(--text2);margin-top:3px;}
.dash-panel-badge{font-family:var(--mono);font-size:9px;font-weight:700;padding:4px 11px;border-radius:20px;text-transform:uppercase;letter-spacing:.08em;white-space:nowrap;flex-shrink:0;}
.dash-panel-badge.user-badge{background:rgba(74,222,128,.12);color:var(--accent);border:1px solid rgba(74,222,128,.28);}
.dash-panel-badge.admin-badge{background:rgba(167,139,250,.12);color:var(--purple);border:1px solid rgba(167,139,250,.28);}

/* ════════════════════════════════════════════════════════════════
   UNIFIED LIVE REPORTING DASHBOARD — styles
   Replaces the per-section CSS that used to live alongside the
   separate Main / Auto-Reply / Follow-Up grids + the embedded
   #dash-live-section. Everything renders inside .lrd-wrap.
════════════════════════════════════════════════════════════════ */
.lrd-wrap{display:block;}
.lrd-meta-bar{
  display:flex;align-items:center;gap:10px;flex-wrap:wrap;
  background:rgba(0,255,198,.025);border:1px solid rgba(0,255,198,.12);
  border-radius:var(--radius);padding:10px 14px;
  font-family:var(--mono);font-size:11px;color:var(--text3);
  margin-bottom:18px;
}
.lrd-meta-bar strong{color:var(--accent);}
.lrd-meta-bar .lrd-dot{color:var(--border2);}
.lrd-meta-bar .lrd-spacer{flex:1;}
.lrd-group-title{
  display:flex;align-items:center;gap:8px;
  margin:18px 0 10px;font-size:12px;font-weight:700;
  color:var(--text2);text-transform:uppercase;letter-spacing:.06em;
}
.lrd-group-title .si{font-size:14px;}
.lrd-grid{display:grid;gap:12px;margin-bottom:6px;}
.lrd-grid-9{grid-template-columns:repeat(9,1fr);}
.lrd-grid-7{grid-template-columns:repeat(7,1fr);}
.lrd-grid-5{grid-template-columns:repeat(5,1fr);}
@media(max-width:1400px){
  .lrd-grid-9{grid-template-columns:repeat(5,1fr);}
  .lrd-grid-7{grid-template-columns:repeat(4,1fr);}
  .lrd-grid-5{grid-template-columns:repeat(3,1fr);}
}
@media(max-width:900px){
  .lrd-grid-9{grid-template-columns:repeat(3,1fr);}
  .lrd-grid-7{grid-template-columns:repeat(2,1fr);}
  .lrd-grid-5{grid-template-columns:repeat(2,1fr);}
}
@media(max-width:520px){
  .lrd-grid-9,.lrd-grid-7,.lrd-grid-5{grid-template-columns:1fr;}
}
.sc-sub{font-family:var(--mono);font-size:9px;color:var(--text3);margin-top:4px;letter-spacing:.04em;}
.lrd-chart-row{display:grid;grid-template-columns:2fr 1fr;gap:14px;margin-bottom:6px;}
.lrd-chart-row + .lrd-chart-row{grid-template-columns:1fr 2fr;}
@media(max-width:1100px){.lrd-chart-row{grid-template-columns:1fr;}.lrd-chart-row + .lrd-chart-row{grid-template-columns:1fr;}}
.lrd-chart-card{
  background:var(--bg2);border:1px solid var(--border2);
  border-radius:var(--radius);padding:14px 16px;min-width:0;
}
.lrd-chart-hd{
  display:flex;align-items:center;gap:10px;margin-bottom:10px;flex-wrap:wrap;
}
.lrd-chart-hd h3{
  font-family:var(--mono);font-size:11px;font-weight:700;
  color:var(--text2);text-transform:uppercase;letter-spacing:.08em;flex:1;margin:0;
}
.lrd-legend{display:flex;align-items:center;gap:6px;font-family:var(--mono);font-size:10px;color:var(--text3);}
.lrd-leg-dot{display:inline-block;width:8px;height:8px;border-radius:50%;flex-shrink:0;margin-right:4px;}
.lrd-ratio-item{}
.lrd-ratio-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:5px;}
.lrd-ratio-lbl{font-family:var(--mono);font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text2);}
.lrd-ratio-pct{font-family:var(--mono);font-size:18px;font-weight:700;line-height:1;}
.lrd-bar-wrap{background:var(--bg4,rgba(255,255,255,.04));border-radius:4px;height:6px;overflow:hidden;margin-bottom:4px;}
.lrd-bar{height:100%;border-radius:4px;transition:width .6s cubic-bezier(.4,0,.2,1);width:0%;}
.lrd-bar-amber{background:var(--amber,#f59e0b);}
.lrd-bar-purple{background:var(--purple);}
.lrd-ratio-sub{font-family:var(--mono);font-size:9px;color:var(--text3);}
.lrd-pill{
  font-family:var(--mono);font-size:10px;font-weight:700;
  padding:3px 10px;border-radius:20px;
  background:rgba(0,255,198,.1);color:var(--accent);
  border:1px solid rgba(0,255,198,.25);
}
.lrd-feed-wrap{
  overflow:auto;max-height:380px;
  border:1px solid var(--border2);border-radius:8px;
  background:rgba(0,0,0,.12);
}
.lrd-feed-table{width:100%;border-collapse:collapse;font-size:12px;}
.lrd-feed-table thead th{
  position:sticky;top:0;z-index:5;
  background:var(--bg2);padding:9px 12px;text-align:left;
  font-family:var(--mono);font-size:9px;font-weight:700;color:var(--text3);
  text-transform:uppercase;letter-spacing:.08em;
  border-bottom:1px solid var(--border2);white-space:nowrap;
}
.lrd-feed-table tbody td{
  padding:8px 12px;border-top:1px solid var(--border);
  font-family:var(--mono);font-size:11px;color:var(--text2);white-space:nowrap;
}
.lrd-feed-empty{text-align:center;padding:32px;color:var(--text3);}
.lrd-type-ar{display:inline-flex;align-items:center;gap:4px;font-family:var(--mono);font-size:9px;font-weight:700;padding:3px 8px;border-radius:10px;background:rgba(0,255,198,.08);border:1px solid rgba(0,255,198,.25);color:var(--accent);}
.lrd-type-fu{display:inline-flex;align-items:center;gap:4px;font-family:var(--mono);font-size:9px;font-weight:700;padding:3px 8px;border-radius:10px;background:rgba(251,146,60,.08);border:1px solid rgba(251,146,60,.25);color:var(--orange,#fb923c);}
/* Loading/error placeholder rendered inside every chart canvas wrapper
   until the chart finishes drawing. Removes itself on first successful
   render — see _chartHideFallback() in the JS. */
.lrd-chart-fallback{
  position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
  font-family:var(--mono);font-size:11px;color:var(--text3);
  background:repeating-linear-gradient(45deg,rgba(255,255,255,.012) 0 12px,transparent 12px 24px);
  border-radius:6px;pointer-events:none;
}

/* ── Step-wise Real-Time Message Report ──────────────────────── */
.lrd-grid-3{grid-template-columns:repeat(3,1fr);}
@media(max-width:900px){.lrd-grid-3{grid-template-columns:1fr;}}
.step-sum{position:relative;}
.step-sum-sent{--sc-c:var(--blue);}
.step-sum-pending{--sc-c:var(--amber);}
.step-sum-completed{--sc-c:var(--accent);}
#step-chart-card{margin-top:6px;margin-bottom:6px;}
.step-lane-grid{
  display:grid;grid-template-columns:repeat(15,1fr);
  gap:6px;margin-top:14px;
}
@media(max-width:1200px){.step-lane-grid{grid-template-columns:repeat(8,1fr);}}
@media(max-width:700px){.step-lane-grid{grid-template-columns:repeat(5,1fr);}}
@media(max-width:480px){.step-lane-grid{grid-template-columns:repeat(3,1fr);}}
.step-lane{
  background:var(--bg3);border:1px solid var(--border2);border-radius:8px;
  padding:8px 6px 6px;cursor:pointer;position:relative;overflow:hidden;
  transition:transform .15s,border-color .15s,box-shadow .15s;
}
.step-lane:hover{transform:translateY(-2px);border-color:var(--accent);box-shadow:0 4px 12px rgba(0,0,0,.25);}
.step-lane.active{border-color:var(--accent);box-shadow:0 0 0 1px rgba(74,222,128,.5);}
.step-lane-num{
  font-family:var(--mono);font-size:9px;font-weight:700;
  color:var(--text3);text-transform:uppercase;letter-spacing:.06em;
  text-align:center;margin-bottom:4px;
}
.step-lane-num strong{color:var(--text);font-size:11px;}
.step-lane-bar{
  display:flex;height:6px;border-radius:3px;overflow:hidden;background:var(--bg4);margin-bottom:5px;
}
.step-lane-seg{height:100%;transition:width .5s cubic-bezier(.4,0,.2,1);}
.step-lane-seg.green {background:var(--accent);}
.step-lane-seg.amber {background:var(--amber);}
.step-lane-seg.blue  {background:var(--blue);}
.step-lane-counts{
  display:flex;justify-content:space-between;align-items:center;
  font-family:var(--mono);font-size:10px;
}
.step-lane-counts .c-blue {color:var(--blue);font-weight:700;}
.step-lane-counts .c-amber{color:var(--amber);font-weight:700;}
.step-lane-counts .c-green{color:var(--accent);font-weight:700;}
.step-lane-pct{
  font-family:var(--mono);font-size:9px;color:var(--text3);
  text-align:center;margin-top:2px;
}
.step-lane-empty .step-lane-num strong{color:var(--text3);}
.step-detail-panel{
  margin-top:14px;background:var(--bg3);border:1px solid var(--border2);
  border-radius:8px;padding:14px 16px;
}
.step-detail-hd{display:flex;align-items:center;gap:10px;margin-bottom:10px;}
.step-detail-title{font-family:var(--mono);font-size:11px;font-weight:700;color:var(--text);text-transform:uppercase;letter-spacing:.08em;flex:1;}
.step-detail-title #step-detail-num{color:var(--accent);}
.step-detail-body{
  display:grid;grid-template-columns:repeat(3,1fr);gap:10px;
  font-family:var(--mono);font-size:11px;
}
@media(max-width:700px){.step-detail-body{grid-template-columns:1fr;}}
.step-detail-col{background:var(--bg2);border:1px solid var(--border);border-radius:6px;padding:10px 12px;}
.step-detail-col h4{font-size:10px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.08em;margin:0 0 6px;}
.step-detail-col .row{display:flex;justify-content:space-between;padding:3px 0;color:var(--text2);}
.step-detail-col .row strong{color:var(--text);font-weight:600;}

/* ── Follow-Up Message Flow funnel ─────────────────────────── */
#fu-flow-card{margin-top:6px;margin-bottom:6px;}
.fu-flow-summary{
  display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:14px;
}
@media(max-width:1100px){.fu-flow-summary{grid-template-columns:repeat(3,1fr);}}
@media(max-width:600px){.fu-flow-summary{grid-template-columns:repeat(2,1fr);}}
.fu-flow-sm-item{
  background:var(--bg3);border:1px solid var(--border2);border-radius:8px;
  padding:10px 12px;display:flex;flex-direction:column;gap:2px;
}
.fu-flow-sm-item .lbl{font-family:var(--mono);font-size:9px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.08em;}
.fu-flow-sm-item strong{font-family:var(--mono);font-size:22px;color:var(--text);line-height:1.1;margin-top:3px;}
.fu-flow-sm-item .sub{font-family:var(--mono);font-size:9px;color:var(--text3);}

.fu-flow-funnel{
  display:flex;align-items:stretch;gap:0;margin-bottom:18px;
  overflow-x:auto;padding-bottom:6px;
}
.fu-flow-funnel::-webkit-scrollbar{height:6px;}
.fu-flow-funnel::-webkit-scrollbar-thumb{background:var(--border2);border-radius:3px;}
.fu-flow-step{
  flex:1 0 90px;min-width:90px;
  background:var(--bg3);border:1px solid var(--border2);border-radius:8px;
  padding:10px 8px 8px;
  display:flex;flex-direction:column;gap:4px;
  position:relative;transition:border-color .15s,transform .15s;
}
.fu-flow-step:hover{border-color:var(--accent);transform:translateY(-2px);}
.fu-flow-step-empty{opacity:.45;}
.fu-flow-step-num{
  font-family:var(--mono);font-size:9px;color:var(--text3);text-transform:uppercase;letter-spacing:.06em;text-align:center;
}
.fu-flow-step-num strong{color:var(--text);font-size:11px;}
.fu-flow-step-val{
  font-family:var(--mono);font-size:18px;font-weight:700;color:var(--blue);text-align:center;line-height:1;
}
.fu-flow-step-bar{
  height:6px;border-radius:3px;background:linear-gradient(90deg,var(--blue) 0%,var(--blue) 100%);
  transition:width .6s cubic-bezier(.4,0,.2,1);margin:2px auto 0;
}
.fu-flow-step-meta{
  display:flex;justify-content:space-between;align-items:center;
  font-family:var(--mono);font-size:9px;margin-top:4px;
}
.fu-flow-step-meta .here{color:var(--amber);font-weight:700;}
.fu-flow-step-meta .done{color:var(--accent);font-weight:700;}
.fu-flow-connector{
  flex:0 0 36px;display:flex;align-items:center;justify-content:center;
  position:relative;font-family:var(--mono);font-size:9px;font-weight:700;
  color:var(--accent);user-select:none;
}
.fu-flow-connector::before{
  content:'';position:absolute;left:0;right:0;top:50%;
  height:2px;background:linear-gradient(90deg,var(--blue),var(--accent));
  transform:translateY(-50%);opacity:.45;
}
.fu-flow-connector .pct{
  position:relative;background:var(--bg2);border:1px solid var(--accent);
  border-radius:10px;padding:1px 6px;font-size:9px;color:var(--accent);white-space:nowrap;
}
.fu-flow-connector .drop{
  position:absolute;bottom:-18px;left:0;right:0;text-align:center;
  font-size:8px;color:var(--red);
}
.fu-flow-table-wrap{
  background:var(--bg3);border:1px solid var(--border2);border-radius:8px;overflow:auto;max-height:380px;
}
.fu-flow-table{width:100%;border-collapse:collapse;font-size:12px;}
.fu-flow-table thead th{
  position:sticky;top:0;background:var(--bg2);padding:9px 12px;text-align:left;
  font-family:var(--mono);font-size:9px;font-weight:700;color:var(--text3);
  text-transform:uppercase;letter-spacing:.06em;border-bottom:1px solid var(--border2);
}
.fu-flow-table tbody td{
  padding:8px 12px;border-top:1px solid var(--border);
  font-family:var(--mono);font-size:11px;color:var(--text2);white-space:nowrap;
}
.fu-flow-table tbody td.s{color:var(--text);font-weight:600;}
.fu-flow-table tbody td.adv strong{color:var(--accent);}
.fu-flow-empty{text-align:center;padding:32px;color:var(--text3);}

/* ══════════════════════════════════════════════════════════════════
   VISUAL SEQUENCE TIMELINE (Interactive Live Follow-up Preview)
   ══════════════════════════════════════════════════════════════════ */
.seq-timeline{
  display:flex;align-items:center;gap:0;overflow-x:auto;padding:16px 8px;margin-bottom:18px;
  background:linear-gradient(135deg,rgba(14,20,32,0.95) 0%,rgba(19,28,46,0.9) 100%);
  border:1px solid rgba(74,222,128,0.2);border-radius:12px;box-shadow:inset 0 1px 2px rgba(255,255,255,0.08),0 8px 24px rgba(0,0,0,0.4);
}
.seq-node{
  flex:0 0 auto;display:flex;flex-direction:column;align-items:center;text-align:center;
  background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.1);border-radius:10px;
  padding:10px 14px;min-width:120px;max-width:160px;position:relative;transition:all .2s cubic-bezier(.16,1,.3,1);
}
.seq-node:hover{
  transform:translateY(-3px);border-color:var(--accent);box-shadow:0 6px 16px rgba(74,222,128,0.25);
}
.seq-node.seq-start{
  border-color:rgba(34,211,238,0.4);background:rgba(34,211,238,0.06);
}
.seq-node.seq-step{
  border-color:rgba(74,222,128,0.3);background:rgba(74,222,128,0.04);
}
.seq-node-ic{font-size:22px;margin-bottom:4px;}
.seq-node-title{font-size:11px;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:130px;}
.seq-node-sub{font-size:9px;color:var(--text3);margin-top:2px;}
.seq-arrow-wrap{
  flex:0 0 70px;display:flex;flex-direction:column;align-items:center;justify-content:center;position:relative;
}
.seq-arrow-line{
  height:2px;width:100%;background:linear-gradient(90deg,var(--accent2),var(--accent));position:relative;
}
.seq-arrow-line::after{
  content:'▶';position:absolute;right:-4px;top:-6px;font-size:9px;color:var(--accent);
}
.seq-delay-badge{
  font-family:var(--mono);font-size:9px;font-weight:700;color:var(--accent);
  background:rgba(14,20,32,0.95);border:1px solid rgba(74,222,128,0.35);
  border-radius:10px;padding:2px 7px;white-space:nowrap;margin-bottom:4px;
  box-shadow:0 2px 6px rgba(0,0,0,0.5);
}
.seq-pulse{
  width:8px;height:8px;border-radius:50%;background:var(--accent);display:inline-block;
  box-shadow:0 0 8px var(--accent);animation:seqp 1.5s infinite;margin-right:4px;
}
@keyframes seqp{0%,100%{transform:scale(1);opacity:1}50%{transform:scale(1.4);opacity:.6}}

/* Drag and Drop Step Card styling */
.step-card-drag-handle{
  cursor:grab;font-size:14px;color:var(--text3);padding:2px 6px;border-radius:4px;user-select:none;
}
.step-card-drag-handle:active{cursor:grabbing;}
.step-card-dragging{opacity:0.4;border:2px dashed var(--accent)!important;}
.step-card-dragover{border:2px dashed var(--accent2)!important;background:rgba(34,211,238,0.06)!important;}

/* ══════════════════════════════════════════════════════════════════
   PROFESSIONAL RICH TEXT COMPOSER & TOOLBAR
   ══════════════════════════════════════════════════════════════════ */
.rte-wrap{
  background:var(--bg3);border:1px solid var(--border);border-radius:10px;overflow:hidden;
  display:flex;flex-direction:column;box-shadow:inset 0 1px 3px rgba(0,0,0,0.4);transition:border-color .2s;
}
.rte-wrap:focus-within{border-color:var(--accent);box-shadow:0 0 16px rgba(74,222,128,0.25);}
.rte-toolbar{
  background:linear-gradient(180deg,rgba(255,255,255,0.05) 0%,rgba(255,255,255,0.02) 100%);
  border-bottom:1px solid rgba(255,255,255,0.08);padding:6px 8px;display:flex;flex-wrap:wrap;gap:4px;align-items:center;
}
.rte-btn{
  background:transparent;border:1px solid transparent;border-radius:5px;color:var(--text2);
  min-width:26px;height:26px;padding:0 6px;display:inline-flex;align-items:center;justify-content:center;
  font-size:12px;font-weight:600;cursor:pointer;transition:all .15s;
}
.rte-btn:hover{
  background:rgba(255,255,255,0.08);color:var(--text);border-color:rgba(255,255,255,0.12);
}
.rte-btn.active{
  background:rgba(74,222,128,0.2);color:var(--accent);border-color:rgba(74,222,128,0.4);font-weight:700;
}
.rte-sep{width:1px;height:18px;background:rgba(255,255,255,0.1);margin:0 3px;}
.rte-select{
  background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:5px;
  color:var(--text2);font-size:11px;padding:3px 6px;height:26px;outline:none;
}
.rte-select option{background:#0e1420;color:var(--text);}
.rte-editor{
  min-height:160px;max-height:450px;overflow-y:auto;padding:14px 16px;color:var(--text);
  font-family:var(--font);font-size:13px;line-height:1.6;outline:none;background:transparent;
}
.rte-editor p{margin:0 0 10px;}
.rte-editor h1{font-size:20px;font-weight:700;margin:12px 0 8px;color:var(--text);}
.rte-editor h2{font-size:16px;font-weight:700;margin:10px 0 6px;color:var(--text);}
.rte-editor h3{font-size:14px;font-weight:700;margin:8px 0 4px;color:var(--text);}
.rte-editor blockquote{border-left:3px solid var(--accent);padding-left:10px;margin:8px 0;color:var(--text2);font-style:italic;}
.rte-editor table{border-collapse:collapse;width:100%;margin:10px 0;}
.rte-editor th,.rte-editor td{border:1px solid rgba(255,255,255,0.15);padding:6px 10px;}
.rte-editor a{color:var(--accent2);text-decoration:underline;}
.rte-editor img{max-width:100%;height:auto;border-radius:6px;}

/* Device Frame Preview */
.device-preview-box{
  background:#090c12;border:1px solid var(--border);border-radius:12px;padding:16px;
  display:flex;flex-direction:column;align-items:center;justify-content:center;overflow:hidden;
}
.device-frame-desktop{width:100%;height:400px;border:none;background:#fff;border-radius:8px;}
.device-frame-mobile{width:375px;height:520px;border:12px solid #1e293b;border-radius:36px;background:#fff;box-shadow:0 20px 50px rgba(0,0,0,0.8);}

/* ══ Quill Snow Dark Theme Styling ══ */
.mailszo-editor-wrapper {
  width: 100%;
  margin-top: 4px;
}

.ql-toolbar.ql-snow {
  background: #111c2e !important;
  border: 1px solid #1e293b !important;
  border-top-left-radius: 8px !important;
  border-top-right-radius: 8px !important;
  padding: 6px 8px !important;
  display: flex !important;
  flex-wrap: wrap !important;
  align-items: center !important;
  gap: 2px !important;
}

.ql-container.ql-snow {
  background: #0b1322 !important;
  color: #f1f5f9 !important;
  border: 1px solid #1e293b !important;
  border-top: none !important;
  border-bottom-left-radius: 8px !important;
  border-bottom-right-radius: 8px !important;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
  font-size: 13.5px !important;
  min-height: 180px !important;
}

.ql-snow .ql-editor {
  min-height: 180px !important;
  line-height: 1.6 !important;
  padding: 12px 14px !important;
  color: #f1f5f9 !important;
}

.ql-snow .ql-editor.ql-blank::before {
  color: #64748b !important;
  font-style: italic !important;
}

.ql-snow .ql-stroke {
  stroke: #cbd5e1 !important;
}

.ql-snow .ql-fill {
  fill: #cbd5e1 !important;
}

.ql-snow .ql-picker {
  color: #cbd5e1 !important;
  font-size: 12px !important;
}

.ql-snow .ql-picker-label:hover,
.ql-snow .ql-picker-label.ql-active,
.ql-snow button:hover,
.ql-snow button.ql-active,
.ql-snow button:focus {
  color: #10b981 !important;
}

.ql-snow .ql-picker-label:hover .ql-stroke,
.ql-snow .ql-picker-label.ql-active .ql-stroke,
.ql-snow button:hover .ql-stroke,
.ql-snow button.ql-active .ql-stroke,
.ql-snow button:focus .ql-stroke {
  stroke: #10b981 !important;
}

.ql-snow .ql-picker-label:hover .ql-fill,
.ql-snow .ql-picker-label.ql-active .ql-fill,
.ql-snow button:hover .ql-fill,
.ql-snow button.ql-active .ql-fill,
.ql-snow button:focus .ql-fill {
  fill: #10b981 !important;
}

.ql-snow .ql-picker-options {
  background: #0f172a !important;
  border: 1px solid #1e293b !important;
  box-shadow: 0 10px 25px -5px rgba(0,0,0,0.6) !important;
  border-radius: 6px !important;
  padding: 6px !important;
  z-index: 1000 !important;
}

.ql-snow .ql-picker-item {
  color: #cbd5e1 !important;
  padding: 4px 8px !important;
  border-radius: 4px !important;
}

.ql-snow .ql-picker-item:hover,
.ql-snow .ql-picker-item.ql-selected {
  background: #1e293b !important;
  color: #10b981 !important;
}

/* Custom Size Picker Labels */
.ql-snow .ql-picker.ql-size .ql-picker-label::before,
.ql-snow .ql-picker.ql-size .ql-picker-item::before {
  content: 'Normal (14px)';
}
.ql-snow .ql-picker.ql-size .ql-picker-label[data-value="small"]::before,
.ql-snow .ql-picker.ql-size .ql-picker-item[data-value="small"]::before {
  content: 'Small (11px)';
}
.ql-snow .ql-picker.ql-size .ql-picker-label[data-value="large"]::before,
.ql-snow .ql-picker.ql-size .ql-picker-item[data-value="large"]::before {
  content: 'Large (18px)';
}
.ql-snow .ql-picker.ql-size .ql-picker-label[data-value="huge"]::before,
.ql-snow .ql-picker.ql-size .ql-picker-item[data-value="huge"]::before {
  content: 'Huge (26px)';
}

/* Tooltip / Link popup dialog */
.ql-snow .ql-tooltip {
  background: #0f172a !important;
  border: 1px solid #334155 !important;
  box-shadow: 0 10px 25px -5px rgba(0,0,0,0.6) !important;
  border-radius: 8px !important;
  color: #f1f5f9 !important;
  padding: 8px 12px !important;
  z-index: 1100 !important;
}

.ql-snow .ql-tooltip input[type="text"] {
  background: #0b1120 !important;
  border: 1px solid #1e293b !important;
  color: #f1f5f9 !important;
  border-radius: 4px !important;
  padding: 4px 8px !important;
  font-size: 12px !important;
}

.ql-snow .ql-tooltip a.ql-action::after {
  border-right-color: #10b981 !important;
}
.ql-snow .ql-tooltip a.ql-remove::before {
  color: #f87171 !important;
}

/* ─── Image Grid ─────────────────────────────── */
.img-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;padding:8px 0;}
.img-item{position:relative;border-radius:var(--radius-sm);overflow:hidden;border:2px solid var(--border);background:var(--bg2);cursor:pointer;transition:border-color .2s,box-shadow .2s,transform .15s;}
.img-item:hover{border-color:var(--accent);box-shadow:0 0 12px var(--accent-glow);transform:translateY(-2px);}
.img-item img{width:100%;height:120px;object-fit:cover;display:block;}
.img-item-name{padding:5px 8px;font-size:10px;color:var(--text3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;background:var(--bg3);border-top:1px solid var(--border);}
.img-del{position:absolute;top:4px;right:4px;width:22px;height:22px;background:rgba(239,68,68,.85);color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;cursor:pointer;opacity:0;transition:opacity .2s;z-index:2;line-height:1;}
.img-item:hover .img-del{opacity:1;}
.img-del:hover{background:var(--red);transform:scale(1.15);}
/* Pick grid selection */
.img-chk{position:absolute;top:4px;left:4px;width:24px;height:24px;background:rgba(0,0,0,.5);color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;opacity:0;transition:opacity .2s,background .2s;z-index:2;}
.img-item:hover .img-chk{opacity:.6;}
.img-item.sel{border-color:var(--accent);box-shadow:0 0 14px var(--accent-glow);}
.img-item.sel .img-chk{opacity:1;background:var(--accent);}
/* Upload zone */
.upload-zone{display:block;border:2px dashed var(--border2);border-radius:var(--radius-sm);padding:18px;text-align:center;color:var(--text3);font-size:13px;cursor:pointer;transition:border-color .2s,background .2s;margin-bottom:12px;}
.upload-zone:hover{border-color:var(--accent);background:rgba(34,197,94,.04);color:var(--text2);}
</style>
<!-- Chart.js -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<!-- Quill WYSIWYG Rich Text Editor CSS & JS -->
<link href="https://cdn.quilljs.com/1.3.7/quill.snow.css" rel="stylesheet">
<script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
</head>
<body>

<!-- Mobile menu toggle -->
<button id="menu-toggle" onclick="toggleSidebar()" aria-label="Menu">☰</button>
<div id="sidebar-overlay" onclick="closeSidebar()"></div>

<!-- LOGIN -->
<div id="login-wrap">
  <div class="login-card">
    <div class="login-logo"><div class="ic">✉️</div><h1>Mails<span>Zo</span></h1><p>v4 · Multi-User Email Platform</p></div>
    <div id="login-al" class="al"></div>
    <div class="fg"><label class="fl">Username</label><input class="fi" id="l-user" placeholder="admin" autofocus></div>
    <div class="fg"><label class="fl">Password</label><input class="fi" id="l-pass" type="password" placeholder="••••••••"></div>
    <button class="btn btn-primary" style="width:100%;padding:11px;font-size:14px;margin-top:4px" type="button" onclick="doLogin()" id="btn-login">Sign In →</button>
  </div>
</div>

<!-- SIDEBAR -->
<div id="sidebar">
  <div class="sb-logo"><div class="sb-logo-ic">✉</div><div class="sb-logo-tx">Mails<span>Zo</span><small>v4</small></div></div>
  <nav class="sb-nav">
    <span class="nsec">Overview</span>
    <div class="ni active" onclick="nav('dashboard')" id="nav-dashboard"><span class="ni-ic">📊</span>Live Reporting Dashboard</div>
    <div class="ni" onclick="nav('stepreporting')" id="nav-stepreporting"><span class="ni-ic">📑</span>Step-by-Step Reporting</div>
    <span class="nsec">Email</span>
    <div class="ni" onclick="nav('campaigns')" id="nav-campaigns"><span class="ni-ic">📤</span>Campaigns</div>
    <div class="ni" onclick="nav('templates')" id="nav-templates"><span class="ni-ic">📝</span>Templates</div>
    <div class="ni" onclick="nav('images')" id="nav-images"><span class="ni-ic">🖼️</span>Images</div>
    <div class="ni" onclick="nav('lists')" id="nav-lists"><span class="ni-ic">👥</span>Email Lists</div>
    <span class="nsec">Automation</span>
    <div class="ni" onclick="nav('imap')" id="nav-imap" style="display:none"><span class="ni-ic">📥</span>IMAP Accounts</div>
    <div class="ni" onclick="nav('autoreply')" id="nav-autoreply"><span class="ni-ic">🔁</span>Auto-Reply</div>
    <div class="ni" onclick="nav('mailrouting')" id="nav-mailrouting"><span class="ni-ic">🔀</span>Smart Mail Routing</div>
    <div class="ni" onclick="nav('followup')" id="nav-followup"><span class="ni-ic">📬</span>Follow-Up</div>
    <div class="ni" onclick="nav('blacklist')" id="nav-blacklist"><span class="ni-ic">🚫</span>Blacklist</div>
    <span class="nsec">Logs & Activity</span>
    <div class="ni" onclick="nav('systemlogs')" id="nav-systemlogs"><span class="ni-ic">🛰️</span>System Activity Logs</div>
    <span class="nsec">Leads</span>
    <div class="ni" onclick="nav('leads')" id="nav-leads"><span class="ni-ic">🗄️</span>Leads Manager</div>
    <span class="nsec">Settings</span>
    <div class="ni" onclick="nav('smtp')" id="nav-smtp" style="display:none"><span class="ni-ic">🔌</span>SMTP Servers</div>
    <div class="ni" onclick="nav('displayname')" id="nav-displayname"><span class="ni-ic">✍️</span>Sender Name</div>
    <div class="ni" onclick="nav('account')" id="nav-account"><span class="ni-ic">🔐</span>My Account</div>
    <!-- ADMIN SECTION — shown only for admins -->
    <div id="admin-nav" style="display:none">
      <div class="admin-sec">
        <span class="nsec">⚡ Admin Panel</span>
        <div class="ni" onclick="nav('users')" id="nav-users"><span class="ni-ic">👤</span>User Management</div>
        <div class="ni" onclick="nav('cron')" id="nav-cron"><span class="ni-ic">⚙️</span>Cron Manager</div>
        <div class="ni" onclick="nav('mailrouting')" id="nav-admin-mailrouting"><span class="ni-ic">🔀</span>Smart Mail Routing</div>
        <div class="ni" onclick="nav('alllogs')" id="nav-alllogs"><span class="ni-ic">📋</span>All Send Logs</div>
      </div>
    </div>
  </nav>
  <div class="sb-foot">
    <div class="sb-user">
      <div class="sb-av" id="sb-av">A</div>
      <div class="sb-uinfo"><div class="nm" id="sb-uname">User</div><div class="rl" id="sb-role">User</div></div>
      <span class="sb-logout" onclick="doLogout()" title="Logout">⏻</span>
    </div>
  </div>
</div>

<!-- MAIN -->
<div id="main">
  <!-- ADMIN QUICK BAR (visible only to admin) -->
  <div id="admin-quick-bar" style="display:none;background:rgba(167,139,250,.07);border-bottom:1px solid rgba(167,139,250,.2);padding:8px 26px;align-items:center;gap:10px;flex-wrap:wrap">
    <span style="font-size:11px;font-weight:700;color:var(--purple);text-transform:uppercase;letter-spacing:.06em">⚡ Admin:</span>
    <button class="btn btn-purple btn-sm" onclick="openUserModal()">+ Create User</button>
    <button class="btn btn-purple btn-sm" onclick="nav('users')">👤 Manage Users</button>
    <button class="btn btn-amber btn-sm" onclick="nav('cron')">⚙️ Cron Manager</button>
    <button class="btn btn-blue btn-sm" onclick="nav('alllogs')">📋 All Logs</button>
    <button class="btn btn-secondary btn-sm" id="quick-cron-btn" onclick="quickRunCron()">▶ Run Cron Now</button>
    <span id="quick-cron-result" style="font-size:11px;color:var(--accent)"></span>
  </div>

  <div class="topbar">
    <button class="btn btn-secondary btn-sm" id="sb-toggle-btn" onclick="toggleSidebarCollapse()" title="Toggle Sidebar" style="padding:6px 10px;margin-right:8px;font-size:14px">☰</button>
    <span class="tb-title" id="tb-title">Dashboard</span>
    
    <div class="tb-search-pill" onclick="openCommandPalette()" title="Raycast Command Palette (⌘K / Ctrl+K)">
      <span style="opacity:0.75">🔍</span>
      <span>Search pages, commands, leads...</span>
      <span class="kbd">⌘K</span>
    </div>

    <div class="tb-right" id="tb-right">
      <span class="live-badge" id="top-live-badge" style="display:inline-flex"><span class="live-dot"></span>LIVE 15s</span>
      <div style="position:relative">
        <button class="btn btn-primary btn-sm" onclick="toggleQuickCreateMenu(event)" title="Quick Create">+ Create</button>
        <div id="quick-create-menu" class="card" style="display:none;position:absolute;top:115%;right:0;width:190px;padding:6px;z-index:200;box-shadow:0 15px 40px rgba(0,0,0,0.85);border:1px solid var(--border2)">
          <div class="cmd-item" onclick="nav('campaigns');openCampaignModal();toggleQuickCreateMenu()"><span class="cmd-item-ic">📤</span> New Campaign</div>
          <div class="cmd-item" onclick="nav('autoreply');openArModal();toggleQuickCreateMenu()"><span class="cmd-item-ic">🔁</span> New Auto-Reply</div>
          <div class="cmd-item" onclick="nav('followup');openFuModal();toggleQuickCreateMenu()"><span class="cmd-item-ic">📬</span> New Follow-Up</div>
          <div class="cmd-item" onclick="nav('lists');openImportModal();toggleQuickCreateMenu()"><span class="cmd-item-ic">👥</span> Upload Contacts</div>
          <div class="cmd-item" onclick="nav('smtp');openSmtpModal();toggleQuickCreateMenu()"><span class="cmd-item-ic">🔌</span> Add SMTP Server</div>
          <div class="cmd-item" onclick="nav('imap');openImapModal();toggleQuickCreateMenu()"><span class="cmd-item-ic">📥</span> Add IMAP Account</div>
        </div>
      </div>
      <button class="btn btn-secondary btn-sm" onclick="toggleNotifDrawer()" title="Notification Center" style="position:relative;padding:6px 10px">
        🔔<span id="notif-pill-dot" style="position:absolute;top:4px;right:4px;width:7px;height:7px;background:var(--accent);border-radius:50%;box-shadow:0 0 8px var(--accent)"></span>
      </button>
      <button class="btn btn-secondary btn-sm" id="theme-toggle-btn" onclick="toggleThemeMode()" title="Toggle Dark/Light Mode" style="padding:6px 10px;font-size:13px">🌙</button>
    </div>
  </div>

  <!-- DASHBOARD -->
  <div class="page active" id="page-dashboard">

    <!-- ── 2026 SaaS Hero Greeting Banner ────────────────────────── -->
    <div class="dash-hero-banner">
      <div class="dash-hero-left">
        <div class="dash-hero-title">
          <span id="dash-greeting-text">Good Day</span>, <span id="dash-hero-uname" style="color:var(--accent)">Mizanur</span> 👋
        </div>
        <div class="dash-hero-sub">High-Performance Email Deliverability, Smart IMAP Routing &amp; Automation Platform</div>
        <div class="dash-hero-chips">
          <span class="dash-hero-chip">⚡ Active SMTPs: <strong id="hero-smtps-cnt">—</strong></span>
          <span class="dash-hero-chip">📥 Active IMAPs: <strong id="hero-imaps-cnt">—</strong></span>
          <span class="dash-hero-chip">📤 Sent Today: <strong id="hero-today-sent-cnt">—</strong></span>
          <span class="dash-hero-chip">💬 Replies: <strong id="hero-replies-cnt">—</strong></span>
          <span class="dash-hero-chip">🎯 Delivery: <strong id="hero-delivery-pct" style="color:var(--accent)">99.4%</strong></span>
          <span class="dash-hero-chip">👁️ Open Rate: <strong id="hero-open-pct" style="color:var(--accent2)">41.8%</strong></span>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:8px;margin-left:auto">
        <button class="btn btn-secondary btn-sm" onclick="loadDash()" title="Refresh Telemetry">🔄 Sync Now</button>
        <button class="btn btn-danger btn-sm" id="btn-dash-clear-hero" onclick="openClearDashModal()" title="Clear stats" style="display:none">🗑 Clear Dashboard</button>
      </div>
    </div>

    <!-- ── User Dashboard Header (non-admin only) ───────────────── -->
    <div id="dash-user-header" class="dash-panel-hd user-hd" style="display:none">
      <div class="dash-panel-icon">📊</div>
      <div class="dash-panel-text">
        <div class="dash-panel-title">My Dashboard</div>
        <div class="dash-panel-sub">Your personal sending overview — stats, campaigns &amp; live activity</div>
      </div>
      <span class="dash-panel-badge user-badge">👤 User Panel</span>
      <button class="btn btn-secondary btn-sm" onclick="loadDash()" title="Reset Dashboard" style="margin-left:10px">🔄 Reset</button>
    </div>

    <!-- ── User Account Info Bar (non-admin only) ───────────────── -->
    <div id="dash-user-info-bar" style="display:none;margin-bottom:18px">
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px">
        <div class="sc" style="--sc-c:var(--accent2)">
          <div class="sc-lbl">Server Expiry</div>
          <div id="dash-expiry-val" class="sc-val" style="font-size:15px;margin-top:4px;color:var(--accent2)">—</div>
        </div>
        <div class="sc" style="--sc-c:var(--accent3)">
          <div class="sc-lbl">Daily IMAP Limit</div>
          <div id="dash-daily-limit-val" class="sc-val" style="color:var(--accent3)">—</div>
        </div>
        <div class="sc" style="--sc-c:var(--accent)">
          <div class="sc-lbl">Remaining Today</div>
          <div id="dash-daily-remaining-val" class="sc-val" style="color:var(--accent)">—</div>
        </div>
      </div>
    </div>

    <!-- ── Admin Dashboard Header (admin only) ──────────────────── -->
    <div id="dash-admin-header" class="dash-panel-hd admin-hd" style="display:none">
      <div class="dash-panel-icon">⚡</div>
      <div class="dash-panel-text">
        <div class="dash-panel-title">Admin Dashboard</div>
        <div class="dash-panel-sub">System-wide overview across all users, campaigns &amp; accounts</div>
      </div>
      <span class="dash-panel-badge admin-badge">⚡ Admin Panel</span>
      <button class="btn btn-danger btn-sm" onclick="openClearDashModal()" style="margin-left:auto;gap:6px" title="Clear all today's dashboard statistics">🗑 Clear Dashboard</button>
    </div>

    <!-- ══ Date Range Filter ════════════════════════════════════════════ -->
    <div id="dash-range-bar" style="display:flex;flex-wrap:wrap;align-items:center;gap:6px;padding:10px 14px;border:1px solid var(--border);border-radius:12px;background:rgba(255,255,255,.015);margin-bottom:18px">
      <span style="font-size:11px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:0.5px;margin-right:6px">📅 Filter Period</span>
      <button class="btn btn-sm dash-rng-btn" data-rng="today"      onclick="setDashRange('today')">Today</button>
      <button class="btn btn-sm dash-rng-btn" data-rng="yesterday"  onclick="setDashRange('yesterday')">Yesterday</button>
      <button class="btn btn-sm dash-rng-btn" data-rng="7d"         onclick="setDashRange('7d')">Last 7 Days</button>
      <button class="btn btn-sm dash-rng-btn" data-rng="15d"        onclick="setDashRange('15d')">Last 15 Days</button>
      <button class="btn btn-sm dash-rng-btn" data-rng="this_month" onclick="setDashRange('this_month')">This Month</button>
      <button class="btn btn-sm dash-rng-btn" data-rng="last_month" onclick="setDashRange('last_month')">Last Month</button>
      <button class="btn btn-sm dash-rng-btn" data-rng="custom"     onclick="setDashRange('custom')">Custom</button>
      <span id="dash-range-custom" style="display:none;align-items:center;gap:4px;margin-left:6px">
        <input class="fi" type="date" id="dash-range-from" style="width:auto;font-size:12px;padding:5px 8px" onchange="onCustomRangeChange()">
        <span style="color:var(--text3);font-size:11px">→</span>
        <input class="fi" type="date" id="dash-range-to"   style="width:auto;font-size:12px;padding:5px 8px" onchange="onCustomRangeChange()">
        <button class="btn btn-blue btn-sm" onclick="applyCustomRange()">Apply</button>
      </span>
      <span id="dash-range-info" style="margin-left:auto;font-size:11px;color:var(--text3)"></span>
    </div>

    <!-- ════════════════════════════════════════════════════════════════
         UNIFIED LIVE REPORTING DASHBOARD
    ════════════════════════════════════════════════════════════════ -->
    <div class="lrd-wrap">

      <!-- Live meta bar -->
      <div class="lrd-meta-bar">
        <span class="live-badge" id="dash-live" style="display:inline-flex"><span class="live-dot"></span>LIVE</span>
        <span>Last updated: <strong id="lrd-ts">—</strong></span>
        <span class="lrd-dot">·</span>
        <span>Stats reset daily at midnight</span>
        <span class="lrd-dot">·</span>
        <span>Next reset: <strong id="lrd-next-reset">—</strong></span>
        <span class="lrd-spacer"></span>
        <span style="font-size:10px">⟳ Auto-refreshes every 15 s</span>
        <button class="btn btn-secondary btn-sm" id="btn-dash-reset" onclick="resetLiveDashboard()" title="Force-reload the dashboard" style="margin-left:8px">↺ Reset</button>
      </div>

      <!-- ── Top-line Pipeline KPIs ──────────────────────────────── -->
      <div class="lrd-group-title"><span class="si">⚡</span> Pipeline Overview</div>
      <div class="lrd-grid lrd-grid-9">
        <div class="sc" style="--sc-c:var(--accent2)">
          <div class="sc-lbl"><span>👥 Total Leads</span><span style="font-size:9px;color:var(--accent2);background:rgba(6,182,212,0.1);padding:1px 5px;border-radius:4px">CRM</span></div>
          <div class="sc-val" id="s-total-leads" style="color:var(--accent2)">—</div>
          <div class="sc-sub">Subscribers in lists</div>
          <div class="sc-sparkline"><div class="sc-sparkbar" style="height:40%"></div><div class="sc-sparkbar" style="height:60%"></div><div class="sc-sparkbar" style="height:50%"></div><div class="sc-sparkbar" style="height:80%"></div><div class="sc-sparkbar" style="height:95%"></div></div>
        </div>
        <div class="sc" style="--sc-c:var(--accent)">
          <div class="sc-lbl"><span>📅 Today's Leads</span><span style="font-size:9px;color:var(--accent);background:rgba(34,197,94,0.1);padding:1px 5px;border-radius:4px">+Today</span></div>
          <div class="sc-val" id="s-today-leads" style="color:var(--accent)">—</div>
          <div class="sc-sub">Added today</div>
          <div class="sc-sparkline"><div class="sc-sparkbar" style="height:20%"></div><div class="sc-sparkbar" style="height:45%"></div><div class="sc-sparkbar" style="height:70%"></div><div class="sc-sparkbar" style="height:60%"></div><div class="sc-sparkbar" style="height:100%"></div></div>
        </div>
        <div class="sc" style="--sc-c:var(--blue)">
          <div class="sc-lbl"><span>🗓️ Month Leads</span><span style="font-size:9px;color:var(--blue);background:rgba(56,189,248,0.1);padding:1px 5px;border-radius:4px">Month</span></div>
          <div class="sc-val" id="s-month-leads" style="color:var(--blue)">—</div>
          <div class="sc-sub">Added this month</div>
          <div class="sc-sparkline"><div class="sc-sparkbar" style="height:55%"></div><div class="sc-sparkbar" style="height:70%"></div><div class="sc-sparkbar" style="height:65%"></div><div class="sc-sparkbar" style="height:85%"></div><div class="sc-sparkbar" style="height:90%"></div></div>
        </div>
        <div class="sc" style="--sc-c:var(--accent3)">
          <div class="sc-lbl"><span>⏳ Pending Leads</span><span style="font-size:9px;color:var(--accent3);background:rgba(245,158,11,0.1);padding:1px 5px;border-radius:4px">Queue</span></div>
          <div class="sc-val" id="s-pending-leads" style="color:var(--accent3)">—</div>
          <div class="sc-sub">Queued for action</div>
          <div class="sc-sparkline"><div class="sc-sparkbar" style="height:80%"></div><div class="sc-sparkbar" style="height:60%"></div><div class="sc-sparkbar" style="height:40%"></div><div class="sc-sparkbar" style="height:50%"></div><div class="sc-sparkbar" style="height:35%"></div></div>
        </div>
        <div class="sc" style="--sc-c:var(--accent2)">
          <div class="sc-lbl"><span>🚀 Active Camps</span><span style="font-size:9px;color:var(--accent2);background:rgba(6,182,212,0.1);padding:1px 5px;border-radius:4px">Live</span></div>
          <div class="sc-val" id="s-active-camps" style="color:var(--accent2)">—</div>
          <div class="sc-sub">Running now</div>
          <div class="sc-sparkline"><div class="sc-sparkbar" style="height:30%"></div><div class="sc-sparkbar" style="height:50%"></div><div class="sc-sparkbar" style="height:80%"></div><div class="sc-sparkbar" style="height:70%"></div><div class="sc-sparkbar" style="height:100%"></div></div>
        </div>
        <div class="sc" style="--sc-c:var(--accent)">
          <div class="sc-lbl"><span>📤 Total Sent</span><span style="font-size:9px;color:var(--accent);background:rgba(34,197,94,0.1);padding:1px 5px;border-radius:4px">Global</span></div>
          <div class="sc-val" id="s-total-sent-emails" style="color:var(--accent)">—</div>
          <div class="sc-sub">Across all channels</div>
          <div class="sc-sparkline"><div class="sc-sparkbar" style="height:40%"></div><div class="sc-sparkbar" style="height:65%"></div><div class="sc-sparkbar" style="height:85%"></div><div class="sc-sparkbar" style="height:75%"></div><div class="sc-sparkbar" style="height:100%"></div></div>
        </div>
        <div class="sc" style="--sc-c:var(--blue)">
          <div class="sc-lbl"><span>📥 Reply Rate</span><span style="font-size:9px;color:var(--blue);background:rgba(56,189,248,0.1);padding:1px 5px;border-radius:4px">%</span></div>
          <div class="sc-val" id="s-reply-rate" style="color:var(--blue)">—</div>
          <div class="sc-sub">Replies / sent</div>
          <div class="sc-sparkline"><div class="sc-sparkbar" style="height:35%"></div><div class="sc-sparkbar" style="height:50%"></div><div class="sc-sparkbar" style="height:70%"></div><div class="sc-sparkbar" style="height:80%"></div><div class="sc-sparkbar" style="height:90%"></div></div>
        </div>
        <div class="sc" style="--sc-c:var(--purple)">
          <div class="sc-lbl"><span>🎯 Conversion</span><span style="font-size:9px;color:var(--purple);background:rgba(139,92,246,0.1);padding:1px 5px;border-radius:4px">%</span></div>
          <div class="sc-val" id="s-conv-rate" style="color:var(--purple)">—</div>
          <div class="sc-sub">Completed sequences</div>
          <div class="sc-sparkline"><div class="sc-sparkbar" style="height:45%"></div><div class="sc-sparkbar" style="height:60%"></div><div class="sc-sparkbar" style="height:75%"></div><div class="sc-sparkbar" style="height:90%"></div><div class="sc-sparkbar" style="height:95%"></div></div>
        </div>
        <div class="sc" style="--sc-c:var(--accent2)">
          <div class="sc-lbl"><span>📨 IMAP Read</span><span style="font-size:9px;color:var(--accent2);background:rgba(6,182,212,0.1);padding:1px 5px;border-radius:4px">Inbox</span></div>
          <div class="sc-val" id="s-imap-read" style="color:var(--accent2)">—</div>
          <div class="sc-sub">Inbound messages</div>
          <div class="sc-sparkline"><div class="sc-sparkbar" style="height:50%"></div><div class="sc-sparkbar" style="height:65%"></div><div class="sc-sparkbar" style="height:60%"></div><div class="sc-sparkbar" style="height:85%"></div><div class="sc-sparkbar" style="height:95%"></div></div>
        </div>
      </div>

      <!-- ── Campaign Performance ────────────────────────────────── -->
      <div class="lrd-group-title"><span class="si">📈</span> Main Campaign Performance</div>
      <div class="lrd-grid lrd-grid-5">
        <div class="sc" style="--sc-c:var(--accent)"><div class="sc-lbl">📤 Total Sent</div><div class="sc-val" id="s-sent" style="color:var(--accent)">—</div></div>
        <div class="sc" style="--sc-c:var(--red)"><div class="sc-lbl">❌ Total Failed</div><div class="sc-val" id="s-failed" style="color:var(--red)">—</div></div>
        <div class="sc" style="--sc-c:var(--accent3)"><div class="sc-lbl">⏳ Total Pending</div><div class="sc-val" id="s-pending" style="color:var(--accent3)">—</div></div>
        <div class="sc" style="--sc-c:var(--accent2)"><div class="sc-lbl">🚀 Total Running</div><div class="sc-val" id="s-active" style="color:var(--accent2)">—</div></div>
        <div class="sc" style="--sc-c:var(--purple)"><div class="sc-lbl">🎯 Total Campaigns</div><div class="sc-val" id="s-camps" style="color:var(--purple)">—</div></div>
      </div>

      <!-- ── Auto-Reply Performance ──────────────────────────────── -->
      <div class="lrd-group-title"><span class="si">↩️</span> Auto-Reply Performance</div>
      <div class="lrd-grid lrd-grid-5">
        <div class="sc" style="--sc-c:var(--accent)"><div class="sc-lbl">📤 AR Sent</div><div class="sc-val" id="s-ar-sent" style="color:var(--accent)">—</div></div>
        <div class="sc" style="--sc-c:var(--red)"><div class="sc-lbl">❌ AR Failed</div><div class="sc-val" id="s-ar-failed" style="color:var(--red)">—</div></div>
        <div class="sc" style="--sc-c:var(--accent2)"><div class="sc-lbl">📥 AR Read (replies)</div><div class="sc-val" id="s-ar-read" style="color:var(--accent2)">—</div></div>
        <div class="sc" style="--sc-c:var(--accent3)"><div class="sc-lbl">⏳ Pending Replies</div><div class="sc-val" id="s-reply-pending" style="color:var(--accent3)">—</div></div>
        <div class="sc" style="--sc-c:var(--purple)"><div class="sc-lbl">✅ AR Completed</div><div class="sc-val" id="s-ar-completed" style="color:var(--purple)">—</div></div>
      </div>

      <!-- ── Follow-Up Performance ───────────────────────────────── -->
      <div class="lrd-group-title"><span class="si">📬</span> Follow-Up Performance</div>
      <div class="lrd-grid lrd-grid-5">
        <div class="sc" style="--sc-c:var(--accent)"><div class="sc-lbl">📤 FU Sent</div><div class="sc-val" id="s-fu-sent" style="color:var(--accent)">—</div></div>
        <div class="sc" style="--sc-c:var(--red)"><div class="sc-lbl">❌ FU Failed</div><div class="sc-val" id="s-fu-failed" style="color:var(--red)">—</div></div>
        <div class="sc" style="--sc-c:var(--accent2)"><div class="sc-lbl">📥 FU Read (replies)</div><div class="sc-val" id="s-fu-read" style="color:var(--accent2)">—</div></div>
        <div class="sc" style="--sc-c:var(--accent3)"><div class="sc-lbl">⏳ Pending Follow-Ups</div><div class="sc-val" id="s-followup-pending" style="color:var(--accent3)">—</div></div>
        <div class="sc" style="--sc-c:var(--purple)"><div class="sc-lbl">✅ FU Completed</div><div class="sc-val" id="s-fu-completed" style="color:var(--purple)">—</div></div>
      </div>

      <!-- ════════════════════════════════════════════════════════════
           LIVE STEP REPORT — Auto-Reply + Follow-Up, Step 1 → Step 15
           Single unified surface: summary cards on top, stacked bar
           chart with hover detail, lane visualization with completion
           percentages, all driven by reports/step-summary on the same
           15s polling loop.
      ════════════════════════════════════════════════════════════════ -->
      <div class="lrd-group-title"><span class="si">🪜</span> Step-wise Real-Time Message Report (Step 1 → Step 15)</div>

      <!-- Summary cards -->
      <div class="lrd-grid lrd-grid-3" id="step-summary-row">
        <div class="sc step-sum step-sum-sent"><div class="sc-lbl">📤 Total Sent (AR + FU)</div><div class="sc-val" id="step-sum-sent" style="color:var(--blue)">—</div><div class="sc-sub">All messages dispatched</div></div>
        <div class="sc step-sum step-sum-pending"><div class="sc-lbl">⏳ Total Pending</div><div class="sc-val" id="step-sum-pending" style="color:var(--amber)">—</div><div class="sc-sub">Awaiting next send</div></div>
        <div class="sc step-sum step-sum-completed"><div class="sc-lbl">✅ Total Completed</div><div class="sc-val" id="step-sum-completed" style="color:var(--accent)">—</div><div class="sc-sub">Moved past this step</div></div>
      </div>

      <!-- Chart + Per-step lane visualization -->
      <div class="lrd-chart-card" id="step-chart-card">
        <div class="lrd-chart-hd">
          <h3>📈 Per-Step Message Flow</h3>
          <span class="lrd-legend">
            <span class="lrd-leg-dot" style="background:var(--accent)"></span>Completed
            <span class="lrd-leg-dot" style="background:var(--amber);margin-left:8px"></span>Pending
            <span class="lrd-leg-dot" style="background:var(--blue);margin-left:8px"></span>Sent (Active)
          </span>
          <span class="live-badge" style="display:inline-flex;margin-left:auto"><span class="live-dot"></span>LIVE</span>
        </div>
        <div style="position:relative;height:260px;"><canvas id="step-chart"></canvas></div>
        <!-- Per-step lane cards: hover/click for detail -->
        <div class="step-lane-grid" id="step-lane-grid"></div>
        <!-- Drill-down detail panel -->
        <div class="step-detail-panel" id="step-detail-panel" hidden>
          <div class="step-detail-hd">
            <span class="step-detail-title">Step <span id="step-detail-num">—</span> Detail</span>
            <button class="btn btn-secondary btn-sm" onclick="stepDetailClose()">✕ Close</button>
          </div>
          <div class="step-detail-body" id="step-detail-body"></div>
        </div>
      </div>

      <!-- ════════════════════════════════════════════════════════════
           FOLLOW-UP MESSAGE FLOW
           Funnel visualization derived from the step-summary payload.
           For each step we render a bar sized to the number of contacts
           that reached step N (currently-at-step + already-past-it),
           with arrow connectors carrying the advance % between bars.
      ════════════════════════════════════════════════════════════════ -->
      <div class="lrd-group-title"><span class="si">📬</span> Follow-Up Message Flow</div>
      <div class="lrd-chart-card" id="fu-flow-card">
        <div class="lrd-chart-hd">
          <h3>🚀 Step 1 → Step 15 — Contact Journey</h3>
          <span class="lrd-legend">
            <span class="lrd-leg-dot" style="background:var(--blue)"></span>Reached
            <span class="lrd-leg-dot" style="background:var(--amber);margin-left:8px"></span>Currently Here
            <span class="lrd-leg-dot" style="background:var(--accent);margin-left:8px"></span>Advanced
            <span class="lrd-leg-dot" style="background:var(--red);margin-left:8px"></span>Drop-off
          </span>
          <span class="live-badge" style="display:inline-flex;margin-left:auto"><span class="live-dot"></span>LIVE</span>
        </div>

        <!-- Summary strip -->
        <div class="fu-flow-summary" id="fu-flow-summary">
          <div class="fu-flow-sm-item"><span class="lbl">📥 Entered</span><strong id="fu-flow-entered">—</strong><span class="sub">total reached step 1</span></div>
          <div class="fu-flow-sm-item"><span class="lbl">📤 Sent</span><strong id="fu-flow-sent">—</strong><span class="sub">messages dispatched</span></div>
          <div class="fu-flow-sm-item"><span class="lbl">⏳ In-Flight</span><strong id="fu-flow-inflight">—</strong><span class="sub">currently progressing</span></div>
          <div class="fu-flow-sm-item"><span class="lbl">🏁 Finished</span><strong id="fu-flow-finished">—</strong><span class="sub">cleared full sequence</span></div>
          <div class="fu-flow-sm-item"><span class="lbl">🎯 End-to-End</span><strong id="fu-flow-conv">—</strong><span class="sub">overall conversion</span></div>
        </div>

        <!-- Funnel rendered into this container (built in JS) -->
        <div class="fu-flow-funnel" id="fu-flow-funnel"></div>

        <!-- Per-step detail table -->
        <div class="fu-flow-table-wrap">
          <table class="fu-flow-table" id="fu-flow-table">
            <thead>
              <tr>
                <th>Step</th>
                <th>Reached</th>
                <th>Sent</th>
                <th>Currently Here</th>
                <th>Advanced</th>
                <th>Drop-off</th>
                <th>Advance %</th>
              </tr>
            </thead>
            <tbody id="fu-flow-tbody"><tr><td colspan="7" class="fu-flow-empty">Loading…</td></tr></tbody>
          </table>
        </div>
      </div>

      <!-- ── Performance Analytics ───────────────────────────────── -->
      <div class="lrd-group-title"><span class="si">📊</span> Performance Analytics</div>
      <div class="lrd-chart-row">
        <div class="lrd-chart-card lrd-chart-wide">
          <div class="lrd-chart-hd">
            <h3>⏱ Hourly Performance — Today</h3>
            <span class="lrd-legend">
              <span class="lrd-leg-dot" style="background:var(--accent)"></span>Auto-Reply
              <span class="lrd-leg-dot" style="background:var(--blue);margin-left:8px"></span>Follow-up
              <span class="lrd-leg-dot" style="background:var(--orange);margin-left:8px"></span>All sends
            </span>
            <span class="live-badge" style="display:inline-flex;margin-left:auto"><span class="live-dot"></span>LIVE</span>
          </div>
          <div style="position:relative;height:200px;"><canvas id="lrd-chart-hourly"></canvas></div>
        </div>
        <div class="lrd-chart-card lrd-chart-narrow">
          <div class="lrd-chart-hd"><h3>📅 14-Day Performance</h3></div>
          <div style="position:relative;height:200px;"><canvas id="lrd-chart-daily"></canvas></div>
        </div>
      </div>

      <!-- ── Ratios / Activity row ───────────────────────────────── -->
      <div class="lrd-chart-row">
        <div class="lrd-chart-card lrd-chart-narrow" id="lrd-ratios-card">
          <div class="lrd-chart-hd"><h3>📐 Conversion Ratios</h3></div>
          <div class="lrd-ratio-item">
            <div class="lrd-ratio-row">
              <span class="lrd-ratio-lbl">Auto-Reply Completion</span>
              <span class="lrd-ratio-pct" style="color:var(--amber)" id="lrd-ratio-ar">—</span>
            </div>
            <div class="lrd-bar-wrap"><div class="lrd-bar lrd-bar-amber" id="lrd-bar-ar" style="width:0%"></div></div>
            <div class="lrd-ratio-sub" id="lrd-ratio-ar-sub">— completed of — sent</div>
          </div>
          <div class="lrd-ratio-item" style="margin-top:14px;">
            <div class="lrd-ratio-row">
              <span class="lrd-ratio-lbl">Follow-Up Completion</span>
              <span class="lrd-ratio-pct" style="color:var(--purple)" id="lrd-ratio-fu">—</span>
            </div>
            <div class="lrd-bar-wrap"><div class="lrd-bar lrd-bar-purple" id="lrd-bar-fu" style="width:0%"></div></div>
            <div class="lrd-ratio-sub" id="lrd-ratio-fu-sub">— completed of — sent</div>
          </div>
          <div class="lrd-ratio-item" style="margin-top:14px;">
            <div class="lrd-ratio-row">
              <span class="lrd-ratio-lbl">Reply Rate (All)</span>
              <span class="lrd-ratio-pct" style="color:var(--blue)" id="lrd-ratio-reply">—</span>
            </div>
            <div class="lrd-bar-wrap"><div class="lrd-bar" style="background:var(--blue)" id="lrd-bar-reply"></div></div>
            <div class="lrd-ratio-sub" id="lrd-ratio-reply-sub">— replies of — sent</div>
          </div>
          <div class="lrd-ratio-item" style="margin-top:14px;">
            <div class="lrd-ratio-row">
              <span class="lrd-ratio-lbl">Total Emails Today</span>
              <span class="lrd-ratio-pct" style="color:var(--accent)" id="lrd-total">—</span>
            </div>
            <div class="lrd-bar-wrap"><div class="lrd-bar" style="background:var(--accent);width:100%"></div></div>
            <div class="lrd-ratio-sub" id="lrd-total-sub">AR + Follow-ups</div>
          </div>
        </div>

        <div class="lrd-chart-card lrd-chart-wide" id="lrd-feed-card">
          <div class="lrd-chart-hd">
            <h3>⚡ Live Activity Feed</h3>
            <span class="lrd-pill" id="lrd-feed-count">0 entries</span>
            <span class="live-badge" style="display:inline-flex;margin-left:auto"><span class="live-dot"></span>LIVE</span>
          </div>
          <div class="lrd-feed-wrap">
            <table class="lrd-feed-table" id="lrd-feed-table">
              <thead>
                <tr>
                  <th style="min-width:55px">Type</th>
                  <th style="min-width:200px">Email</th>
                  <th style="min-width:180px">Rule / Sequence</th>
                  <th style="min-width:60px;text-align:center">Steps</th>
                  <th style="min-width:140px">Completed At</th>
                </tr>
              </thead>
              <tbody id="lrd-feed-body"><tr><td colspan="5" class="lrd-feed-empty">Loading…</td></tr></tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ── Account & Infrastructure (kept inline as final widget) -->
      <div class="lrd-group-title"><span class="si">⚙️</span> Account &amp; Infrastructure</div>
      <div class="lrd-grid lrd-grid-5">
        <div class="sc" style="--sc-c:var(--purple)"><div class="sc-lbl">👥 Subscribers</div><div class="sc-val" id="s-emails" style="color:var(--purple)">—</div></div>
        <div class="sc" id="sc-smtps" style="--sc-c:var(--accent2);display:none"><div class="sc-lbl">📡 SMTP Servers</div><div class="sc-val" id="s-smtps" style="color:var(--accent2)">—</div></div>
        <div class="sc" id="sc-ar-usage" style="--sc-c:var(--accent);display:none">
          <div class="sc-lbl">↩️ AR Messages Used</div>
          <div class="sc-val" id="s-ar-usage" style="color:var(--accent)">—</div>
          <div class="sc-sub" id="s-ar-usage-hint">—</div>
        </div>
        <div class="sc" id="sc-fu-usage" style="--sc-c:var(--accent3);display:none">
          <div class="sc-lbl">📬 FU Messages Used</div>
          <div class="sc-val" id="s-fu-usage" style="color:var(--accent3)">—</div>
          <div class="sc-sub" id="s-fu-usage-hint">—</div>
        </div>
        <div class="sc" id="sc-users" style="--sc-c:var(--purple);display:none"><div class="sc-lbl">👤 Total Users</div><div class="sc-val" id="s-users" style="color:var(--purple)">—</div></div>
      </div>

      <!-- ── Recent Campaigns table ──────────────────────────────── -->
      <div class="lrd-group-title"><span class="si">🚀</span> Recent Campaigns</div>
      <div class="card" style="margin-top:0">
        <div class="card-hd"><h3>Recent Campaigns</h3><button class="btn btn-primary btn-sm" onclick="nav('campaigns')" style="margin-left:auto">View All →</button></div>
        <div class="card-body" style="padding:0"><div class="tw"><table>
          <thead><tr><th>Name</th><th>Status</th><th>Variants</th><th>Sent</th><th>Failed</th><th>Actions</th></tr></thead>
          <tbody id="dash-camps-body"><tr class="empty-row"><td colspan="6">Loading…</td></tr></tbody>
        </table></div></div>
      </div>

    </div><!-- /.lrd-wrap -->

  </div>

  <!-- STEP-BY-STEP REPORTING -->
  <div class="page" id="page-stepreporting">

    <!-- ══════════════════════════════════════════════════════════════
         Step-by-Step Reporting (Auto-Reply + Follow-Up)
         Each table is independent: search, sort, filter, paginate, export.
         ══════════════════════════════════════════════════════════════ -->

    <!-- ── Auto-Reply Step Report ─────────────────────────────────── -->
    <div class="card" style="margin-bottom:18px">
      <div class="card-hd"><h3>↩️ Auto-Reply Step Report</h3>
        <span class="live-badge"><span class="live-dot"></span>LIVE</span>
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-left:auto">
          <button class="btn btn-secondary btn-sm" onclick="loadStepReport('ar',1)">↺ Refresh</button>
          <button class="btn btn-blue btn-sm"      onclick="exportStepReport('ar','csv')">⬇ CSV</button>
          <button class="btn btn-amber btn-sm"     onclick="exportStepReport('ar','xls')">⬇ Excel</button>
          <button class="btn btn-purple btn-sm"    onclick="printStepReport('ar')">🖨 PDF</button>
        </div>
      </div>
      <!-- Filter bar -->
      <div style="display:flex;flex-wrap:wrap;gap:8px;padding:10px 14px;border-bottom:1px solid var(--border);background:rgba(255,255,255,.01)">
        <input class="fi" id="ar-rep-q" placeholder="🔍 Search email/name/campaign…" style="flex:1;min-width:180px;font-size:12px" oninput="stepRepDebounce('ar')">
        <select class="fsel" id="ar-rep-rule"   style="width:auto;font-size:12px" onchange="loadStepReport('ar',1)"><option value="">All Campaigns</option></select>
        <select class="fsel" id="ar-rep-status" style="width:auto;font-size:12px" onchange="loadStepReport('ar',1)">
          <option value="">All Status</option>
          <option value="sent">Sent</option>
          <option value="failed">Failed</option>
          <option value="pending">Pending</option>
          <option value="active">Running</option>
          <option value="completed">Completed</option>
        </select>
        <select class="fsel" id="ar-rep-smtp"   style="width:auto;font-size:12px" onchange="loadStepReport('ar',1)"><option value="">All SMTP</option></select>
        <input  class="fi"  id="ar-rep-step"   placeholder="Step #" style="width:80px;font-size:12px" type="number" min="1" oninput="stepRepDebounce('ar')">
        <input  class="fi"  id="ar-rep-from"   type="date" style="width:auto;font-size:12px" onchange="loadStepReport('ar',1)" title="Last sent from">
        <input  class="fi"  id="ar-rep-to"     type="date" style="width:auto;font-size:12px" onchange="loadStepReport('ar',1)" title="Last sent to">
      </div>
      <div class="card-body" style="padding:0">
        <div class="tw"><table id="ar-rep-table">
          <thead><tr>
            <th class="srt" onclick="stepRepSort('ar','rule_name')">Campaign</th>
            <th class="srt" onclick="stepRepSort('ar','lead_email')">Lead Email</th>
            <th class="srt" onclick="stepRepSort('ar','current_step')">Step</th>
            <th>Subject</th>
            <th>Sent</th>
            <th>Failed</th>
            <th>Read</th>
            <th>Pending</th>
            <th class="srt" onclick="stepRepSort('ar','last_sent_at')">Last Sent</th>
            <th class="srt" onclick="stepRepSort('ar','next_send_at')">Next</th>
            <th>SMTP</th>
            <th class="srt" onclick="stepRepSort('ar','status')">Status</th>
          </tr></thead>
          <tbody id="ar-rep-body"><tr class="empty-row"><td colspan="12">Loading…</td></tr></tbody>
        </table></div>
        <div id="ar-rep-pager" style="display:flex;align-items:center;justify-content:center;gap:8px;padding:12px;border-top:1px solid var(--border)"></div>
      </div>
    </div>

    <!-- ── Follow-Up Step Report ──────────────────────────────────── -->
    <div class="card" style="margin-bottom:18px">
      <div class="card-hd"><h3>📬 Follow-Up Step Report</h3>
        <span class="live-badge"><span class="live-dot"></span>LIVE</span>
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-left:auto">
          <button class="btn btn-secondary btn-sm" onclick="loadStepReport('fu',1)">↺ Refresh</button>
          <button class="btn btn-blue btn-sm"      onclick="exportStepReport('fu','csv')">⬇ CSV</button>
          <button class="btn btn-amber btn-sm"     onclick="exportStepReport('fu','xls')">⬇ Excel</button>
          <button class="btn btn-purple btn-sm"    onclick="printStepReport('fu')">🖨 PDF</button>
        </div>
      </div>
      <div style="display:flex;flex-wrap:wrap;gap:8px;padding:10px 14px;border-bottom:1px solid var(--border);background:rgba(255,255,255,.01)">
        <input class="fi" id="fu-rep-q" placeholder="🔍 Search email/name/campaign…" style="flex:1;min-width:180px;font-size:12px" oninput="stepRepDebounce('fu')">
        <select class="fsel" id="fu-rep-rule"   style="width:auto;font-size:12px" onchange="loadStepReport('fu',1)"><option value="">All Campaigns</option></select>
        <select class="fsel" id="fu-rep-status" style="width:auto;font-size:12px" onchange="loadStepReport('fu',1)">
          <option value="">All Status</option>
          <option value="sent">Sent</option>
          <option value="failed">Failed</option>
          <option value="pending">Pending</option>
          <option value="active">Running</option>
          <option value="completed">Completed</option>
        </select>
        <select class="fsel" id="fu-rep-smtp"   style="width:auto;font-size:12px" onchange="loadStepReport('fu',1)"><option value="">All SMTP</option></select>
        <input  class="fi"  id="fu-rep-step"   placeholder="Step #" style="width:80px;font-size:12px" type="number" min="1" oninput="stepRepDebounce('fu')">
        <input  class="fi"  id="fu-rep-from"   type="date" style="width:auto;font-size:12px" onchange="loadStepReport('fu',1)" title="Last sent from">
        <input  class="fi"  id="fu-rep-to"     type="date" style="width:auto;font-size:12px" onchange="loadStepReport('fu',1)" title="Last sent to">
      </div>
      <div class="card-body" style="padding:0">
        <div class="tw"><table id="fu-rep-table">
          <thead><tr>
            <th class="srt" onclick="stepRepSort('fu','rule_name')">Campaign</th>
            <th class="srt" onclick="stepRepSort('fu','lead_email')">Lead Email</th>
            <th class="srt" onclick="stepRepSort('fu','current_step')">Step</th>
            <th>Subject</th>
            <th>Sent</th>
            <th>Failed</th>
            <th>Read</th>
            <th>Pending</th>
            <th class="srt" onclick="stepRepSort('fu','last_sent_at')">Last Sent</th>
            <th class="srt" onclick="stepRepSort('fu','next_send_at')">Next</th>
            <th>SMTP</th>
            <th class="srt" onclick="stepRepSort('fu','status')">Status</th>
          </tr></thead>
          <tbody id="fu-rep-body"><tr class="empty-row"><td colspan="12">Loading…</td></tr></tbody>
        </table></div>
        <div id="fu-rep-pager" style="display:flex;align-items:center;justify-content:center;gap:8px;padding:12px;border-top:1px solid var(--border)"></div>
      </div>
    </div>

  </div>

  <!-- CAMPAIGNS -->
  <div class="page" id="page-campaigns">
    <div class="card">
      <div class="card-hd"><h3>📤 Campaigns</h3>
        <button class="btn btn-secondary btn-sm" onclick="loadCampaigns()">↺ Refresh</button>
        <button class="btn btn-primary btn-sm" onclick="openCampModal()">+ New Campaign</button>
      </div>
      <div class="card-body" style="padding:0"><div class="tw"><table>
        <thead><tr><th>Name</th><th>Status</th><th>Variants</th><th>SMTPs</th><th>List</th><th>Sent</th><th>Failed</th><th>Scheduled</th><th>Actions</th></tr></thead>
        <tbody id="camps-body"><tr class="empty-row"><td colspan="9">Loading…</td></tr></tbody>
      </table></div></div>
    </div>
  </div>

  <!-- IMAGES -->
  <div class="page" id="page-images">
    <div class="card">
      <div class="card-hd"><h3>🖼️ Image Library</h3>
        <label class="btn btn-primary btn-sm" id="img-lib-upload-btn" style="cursor:pointer">📤 Upload<input type="file" accept="image/*" multiple onchange="uploadImgs(this,true)" style="display:none"></label>
      </div>
      <div class="card-body"><div id="img-lib-al" class="al"></div><div id="img-lib" class="img-grid"><div style="color:var(--text3);font-size:12px">Loading…</div></div></div>
    </div>
  </div>

  <!-- LISTS -->
  <div class="page" id="page-lists">
    <div class="card">
      <div class="card-hd"><h3>👥 Email Lists</h3>
        <button class="btn btn-secondary btn-sm" onclick="exportLeads('lists')">⬇ Export All</button>
        <button class="btn btn-primary btn-sm" onclick="openListModal()">+ Import CSV</button></div>
      <div class="card-body" style="padding:0"><div class="tw"><table>
        <thead><tr><th>Name</th><th>Count</th><th>Created</th><th>Actions</th></tr></thead>
        <tbody id="lists-body"><tr class="empty-row"><td colspan="4">Loading…</td></tr></tbody>
      </table></div></div>
    </div>
  </div>

  <!-- SMTP -->
  <div class="page" id="page-smtp">
    <div id="smtp-info-bar" class="al a-inf" style="display:none;margin-bottom:14px"></div>
    <div class="card">
      <div class="card-hd"><h3>🔌 SMTP Servers</h3><button class="btn btn-primary btn-sm" id="smtp-add-btn" onclick="openSmtpModal()" style="display:none">+ Add SMTP</button></div>
      <div class="card-body" style="padding:0"><div class="tw"><table>
        <thead><tr><th>Name</th><th>Host : Port</th><th>From Email</th><th>From Name</th><th>TLS</th><th>Actions</th></tr></thead>
        <tbody id="smtp-body"><tr class="empty-row"><td colspan="6">Loading…</td></tr></tbody>
      </table></div></div>
    </div>
  </div>

  <!-- SENDER DISPLAY NAME -->
  <div class="page" id="page-displayname">
    <div style="max-width:560px">
      <div class="card">
        <div class="card-hd"><h3>✍️ Global Sender Display Name</h3></div>
        <div class="card-body">
          <div id="dn-al" class="al"></div>
          <div class="al a-inf on" style="margin-bottom:16px">
            <strong>What this does:</strong> When set, this name overrides the "From Name" of every SMTP server for all emails you send — campaigns, auto-replies, and follow-ups. Recipients will see this name regardless of which SMTP server is used. Leave blank to use each SMTP server's own From Name.
          </div>
          <div class="fg">
            <label class="fl">Display Name <span class="flh">(shown in recipient's inbox as the sender name)</span></label>
            <input class="fi" id="dn-input" placeholder="e.g. John Smith or My Company" style="font-size:15px" oninput="updateDnPreview()">
            <div class="fhint">This overrides all SMTP "From Name" fields. All outgoing emails will show this name.</div>
          </div>
          <div style="display:flex;gap:8px;margin-top:4px">
            <button class="btn btn-primary" onclick="saveDn()" id="dn-save-btn">💾 Save Display Name</button>
            <button class="btn btn-secondary" onclick="clearDn()">✕ Clear (use SMTP names)</button>
          </div>
        </div>
      </div>
      <div class="card" style="margin-top:18px">
        <div class="card-hd"><h3>ℹ️ How It Works</h3></div>
        <div class="card-body" style="font-size:13px;color:var(--text2);line-height:1.8">
          <p>• You can add as many SMTP servers as you want with any "From Email" addresses.</p>
          <p style="margin-top:6px">• All SMTP servers will use the <strong style="color:var(--accent)">same sender name</strong> (the display name you set above).</p>
          <p style="margin-top:6px">• The recipient sees: <code id="dn-preview">Your Name &lt;smtp@example.com&gt;</code></p>
          <p style="margin-top:6px">• If left blank, each SMTP server uses its own configured "From Name".</p>
        </div>
      </div>
    </div>
  </div>

  <!-- ACCOUNT -->
  <div class="page" id="page-account">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;max-width:760px">
      <div class="card">
        <div class="card-hd"><h3>🔐 Change Password</h3></div>
        <div class="card-body">
          <div id="acc-al" class="al"></div>
          <div class="fg"><label class="fl">Current Password</label><input class="fi" id="acc-cur" type="password"></div>
          <div class="fg"><label class="fl">New Password</label><input class="fi" id="acc-new" type="password"></div>
          <div class="fg"><label class="fl">Confirm New</label><input class="fi" id="acc-new2" type="password"></div>
          <button class="btn btn-primary" style="width:100%" onclick="changePw()">Update Password</button>
        </div>
      </div>
      <div class="card">
        <div class="card-hd"><h3>📊 My Account Info</h3></div>
        <div class="card-body" id="acc-info"><div style="color:var(--text3);font-size:13px">Loading…</div></div>
      </div>
    </div>
    <!-- Clear My Data — user panel only -->
    <div id="acc-clear-data-card" style="display:none;margin-top:18px;max-width:760px">
      <div class="card" style="border-color:rgba(248,113,113,.3)">
        <div class="card-hd" style="background:rgba(248,113,113,.04)"><h3>🗑️ Clear My Data</h3></div>
        <div class="card-body">
          <div id="acc-clear-al" class="al" style="margin-bottom:10px"></div>
          <div style="font-size:12px;color:var(--text2);margin-bottom:14px;line-height:1.7">
            This will permanently delete <strong>all your campaigns, SMTP servers, email lists, IMAP accounts, auto-reply rules, follow-up rules, and send logs</strong>. Your account credentials will not be affected. <span style="color:var(--red);font-weight:600">This cannot be undone.</span>
          </div>
          <button class="btn btn-danger" onclick="clearMyData()">🗑 Clear All My Data</button>
        </div>
      </div>
    </div>
  </div>

  <!-- SEND LOG -->
  <!-- USERS (admin) -->
  <div class="page" id="page-users">
    <div class="card">
      <div class="card-hd"><h3>👤 User Management</h3>
        <button class="btn btn-secondary btn-sm" onclick="loadUsers()">↺ Refresh</button>
        <button class="btn btn-primary btn-sm" onclick="openUserModal()">+ Create User</button>
      </div>
      <div id="users-al" class="al" style="margin:10px 16px 0"></div>
      <div class="card-body" style="padding:0"><div class="tw"><table>
        <thead><tr><th>#</th><th>Username</th><th>Role</th><th>SMTP Limit</th><th>Camp. Limit</th><th>Daily Send</th><th>Expires</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
        <tbody id="users-body"><tr class="empty-row"><td colspan="10">Loading…</td></tr></tbody>
      </table></div></div>
    </div>
  </div>

  <!-- CRON (admin) -->
  <div class="page" id="page-cron">

    <!-- Cron Key Box -->
    <div class="card" style="margin-bottom:18px;border-color:rgba(167,139,250,.3)">
      <div class="card-hd" style="background:rgba(167,139,250,.05)"><h3>🔑 Cron Key &amp; URL</h3><button class="btn btn-purple btn-sm" onclick="regenCronKey()">↺ Regenerate Key</button></div>
      <div class="card-body">
        <div id="cron-key-al" class="al"></div>
        <div class="frow fc2" style="margin-bottom:14px">
          <div>
            <label class="fl">Secret Cron Key</label>
            <div style="display:flex;gap:8px;align-items:center">
              <div class="cron-box" id="cron-key-box" style="flex:1;letter-spacing:.1em;font-size:13px">Loading…</div>
              <button class="btn btn-secondary btn-sm" onclick="copyText($('cron-key-box'))">📋 Copy</button>
            </div>
          </div>
          <div>
            <label class="fl">Status</label>
            <div id="cron-status-box" class="al a-inf on" style="margin:0">Checking…</div>
          </div>
        </div>
        <label class="fl">Full Cron URL <span class="flh">(use this in cPanel / aaPanel)</span></label>
        <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px">
          <div class="cron-box" id="cron-url-box" style="flex:1;font-size:11px">Loading…</div>
          <button class="btn btn-secondary btn-sm" onclick="copyText($('cron-url-box'))">📋 Copy URL</button>
        </div>
        <label class="fl">cPanel / SSH curl command</label>
        <div style="display:flex;gap:8px;align-items:center">
          <div class="cron-box" id="cron-curl-box" style="flex:1;font-size:10px">Loading…</div>
          <button class="btn btn-secondary btn-sm" onclick="copyText($('cron-curl-box'))">📋 Copy</button>
        </div>
        <div style="margin-top:14px;padding:12px;background:rgba(34,211,238,.04);border:1px solid rgba(34,211,238,.15);border-radius:8px;font-size:12px;color:var(--text2);line-height:1.8">
          <strong style="color:var(--accent2)">📌 How to set up:</strong><br>
          <strong>cPanel:</strong> Login → Cron Jobs → Every Minute → paste the curl command above<br>
          <strong>aaPanel:</strong> Cron → Add Task → Type: <em>Access URL</em> → Cycle: Every 1 minute → paste the URL above<br>
          <strong>Linux CLI:</strong> <code>crontab -e</code> → add: <code>* * * * * curl -s "CRON_URL" &gt; /dev/null</code>
        </div>
      </div>
    </div>

    <!-- Manual Run + Auto Run -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:18px">
      <div class="card" style="margin:0">
        <div class="card-hd" style="background:rgba(74,222,128,.04)">
          <h3>▶ Run Cron Manually</h3>
          <button class="btn btn-primary" id="btn-cron-run" onclick="runCron()" style="padding:8px 18px;font-size:13px">▶ Run Now</button>
        </div>
        <div class="card-body">
          <div style="font-size:12px;color:var(--text2);margin-bottom:12px">Click <strong>Run Now</strong> to immediately process all scheduled campaigns — same as the automatic cron job firing.</div>
          <div id="cron-al" class="al"></div>
          <div class="cron-log" id="cron-log"><div class="cl-dim">— Press "Run Now" to execute —</div></div>
        </div>
      </div>
      <div class="card" style="margin:0">
        <div class="card-hd"><h3>📊 Last Run Stats</h3></div>
        <div class="card-body" id="cron-stats-body">
          <div style="color:var(--text3);font-size:12px">Run cron to see stats…</div>
        </div>
      </div>
    </div>

    <!-- Auto-Run (browser-based interval) -->
    <div class="card" style="margin-bottom:18px;border-color:rgba(74,222,128,.3)">
      <div class="card-hd" style="background:rgba(74,222,128,.04)">
        <h3>🔄 Auto-Run (Browser)</h3>
        <button class="btn btn-primary" id="btn-autorun-start" onclick="startAutoRun()" style="padding:8px 18px;font-size:13px">▶ Start Auto-Run</button>
        <button class="btn btn-danger" id="btn-autorun-stop" onclick="stopAutoRun()" style="padding:8px 18px;font-size:13px;display:none">⏹ Stop</button>
        <span id="autorun-status" style="font-size:12px;color:var(--text2);margin-left:8px"></span>
      </div>
      <div class="card-body">
        <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
          <div>
            <label class="fl" style="margin-bottom:4px">Interval (seconds)</label>
            <input class="fi" id="autorun-interval" type="number" value="60" min="10" max="3600" style="width:100px;padding:5px 10px;font-size:13px">
          </div>
          <div style="font-size:12px;color:var(--text2);max-width:420px">
            When started, the cron will run automatically on the specified interval <strong>as long as this browser tab is open</strong>. Use this if your server does not have a system cron job configured. It will stop if you close this tab or click Stop.
          </div>
        </div>
        <div id="autorun-log" style="margin-top:12px;font-size:11px;color:var(--text3);font-family:var(--mono);line-height:1.8;max-height:80px;overflow-y:auto"></div>
      </div>
    </div>

    <!-- Recent logs -->
    <div class="card">
      <div class="card-hd"><h3>📋 Recent Send Results</h3><button class="btn btn-secondary btn-sm" onclick="loadCronLogs()">↺ Refresh</button></div>
      <div class="card-body" style="padding:0"><div class="tw"><table>
        <thead><tr><th>Campaign</th><th>Email</th><th>Status</th><th>SMTP</th><th>From</th><th>Variant</th><th>Error</th><th>Time</th></tr></thead>
        <tbody id="cron-logs-body"><tr class="empty-row"><td colspan="8">Click Refresh to load logs</td></tr></tbody>
      </table></div></div>
    </div>
  </div>

  <!-- ALL LOGS (admin) -->
  <div class="page" id="page-alllogs">
    <div class="card">
      <div class="card-hd">
        <h3>📋 All Send Logs</h3>
        <span id="alllogs-live" style="display:none;align-items:center;gap:5px;font-size:10px;font-weight:700;padding:3px 9px;border-radius:20px;background:rgba(74,222,128,.08);border:1px solid rgba(74,222,128,.25);color:var(--accent);font-family:var(--mono);text-transform:uppercase;letter-spacing:.07em"><span style="width:6px;height:6px;border-radius:50%;background:var(--accent);animation:pulse 1.4s infinite;display:inline-block"></span>Live · 5s</span>
        <button class="btn btn-secondary btn-sm" onclick="loadAllLogs(1)">↺ Refresh</button>
        <button class="btn btn-danger btn-sm" onclick="clearAllLogs()" style="margin-left:auto">🗑 Clear All Logs</button>
      </div>
      <!-- Stats bar -->
      <div id="alllogs-stats" style="display:flex;gap:12px;padding:12px 16px;border-bottom:1px solid var(--border);flex-wrap:wrap">
        <span style="font-size:12px;color:var(--text3)">Total: <strong id="al-total">—</strong></span>
        <span style="font-size:12px;color:var(--accent)">Sent: <strong id="al-sent">—</strong></span>
        <span style="font-size:12px;color:var(--red)">Failed: <strong id="al-failed">—</strong></span>
      </div>
      <!-- Search / filter bar -->
      <div style="display:flex;gap:8px;padding:10px 14px;border-bottom:1px solid var(--border);flex-wrap:wrap;align-items:center">
        <input class="fi" id="al-search" placeholder="Search email, campaign, SMTP, source…" style="flex:1;min-width:160px;padding:6px 10px;font-size:12px" onkeydown="if(event.key==='Enter')loadAllLogs(1)">
        <select class="fsel" id="al-status" style="padding:6px 10px;font-size:12px" onchange="loadAllLogs(1)">
          <option value="">All Status</option>
          <option value="sent">✓ Sent only</option>
          <option value="failed">✗ Failed only</option>
        </select>
        <select class="fsel" id="al-source" style="padding:6px 10px;font-size:12px" onchange="loadAllLogs(1)">
          <option value="">All Sources</option>
          <option value="campaign">📧 Campaign</option>
          <option value="autoreply">⚡ Auto-Reply</option>
          <option value="followup">📬 Follow-Up</option>
        </select>
        <button class="btn btn-primary btn-sm" onclick="loadAllLogs(1)">🔍 Search</button>
      </div>
      <div class="card-body" style="padding:0"><div class="tw"><table>
        <thead><tr><th>Campaign / Source</th><th>User</th><th>Email</th><th>Status</th><th>SMTP</th><th>From</th><th>Variant</th><th>Error</th><th>Time</th></tr></thead>
        <tbody id="alllogs-body"><tr class="empty-row"><td colspan="9">Loading…</td></tr></tbody>
      </table></div>
      <div id="alllogs-pager" style="display:flex;align-items:center;justify-content:center;gap:8px;padding:12px;border-top:1px solid var(--border)"></div>
      </div>
    </div>
  </div>

  <!-- IMAP ACCOUNTS -->
  <div class="page" id="page-imap">
    <!-- Admin-only: per-cron-run / per-minute IMAP read cap. Hidden for
         non-admin via the .admin-only class which the boot routine toggles
         on the body element. The cap is stored in config.json so cron.php
         can read it without a DB roundtrip. -->
    <div class="card admin-only" id="imap-readlimit-card" style="margin-bottom:14px;display:none">
      <div class="card-hd">
        <h3>⏱️ IMAP Read Limit (per minute / per account)</h3>
        <span class="live-badge" style="background:rgba(167,139,250,0.1);color:var(--purple);border:1px solid rgba(167,139,250,0.2)">Admin only</span>
      </div>
      <div class="card-body" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end">
        <div class="fg" style="flex:0 0 220px;margin-bottom:0">
          <label class="fl">Emails / cron run / IMAP account</label>
          <input class="fi" id="imap-readlimit-inp" type="number" min="1" max="5000" value="100">
          <div class="fhint">When cron runs every minute, this is the per-minute throttle. Default 100, max 5000.</div>
        </div>
        <div style="display:flex;gap:8px">
          <button class="btn btn-primary" onclick="saveImapReadLimit()">💾 Save Limit</button>
        </div>
        <div id="imap-readlimit-al" class="al" style="flex:1 1 100%;margin-bottom:0"></div>
      </div>
    </div>

    <div class="card">
      <div class="card-hd">
        <h3>📥 IMAP Accounts</h3>
        <button class="btn btn-secondary btn-sm" onclick="loadImap()">↺ Refresh</button>
        <button class="btn btn-primary btn-sm" id="imap-add-btn" onclick="openImapModal()" style="display:none">+ Add IMAP Account</button>
      </div>
      <div class="info-box" style="margin:0 0 0 0;border-radius:0;border-left:0;border-right:0;border-top:0">
        IMAP accounts let the server <strong>read your inbox</strong>. Used by Auto-Reply to detect incoming emails and trigger reply chains. Requires <code>php-imap</code> extension on server.
      </div>
      <div class="card-body" style="padding:0"><div class="tw"><table>
        <thead><tr><th>Name</th><th>Host : Port</th><th>Username</th><th>SSL</th><th>Last Check</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody id="imap-body"><tr class="empty-row"><td colspan="7">Loading…</td></tr></tbody>
      </table></div></div>
    </div>
  </div>

  <!-- AUTO-REPLY -->
  <div class="page" id="page-autoreply">
    <div class="card" style="margin-bottom:18px">
      <div class="card-hd">
        <h3>🔁 Auto-Reply Rules</h3>
        <button class="btn btn-secondary btn-sm" onclick="loadAutoreply()">↺ Refresh</button>
        <button class="btn btn-primary btn-sm" onclick="openArModal()">+ New Auto-Reply Rule</button>
      </div>
      <div class="info-box" style="margin:0;border-radius:0;border-left:0;border-right:0;border-top:0">
        <strong>How it works:</strong> Cron reads your IMAP inbox every minute. When a new email arrives from someone, it sends <strong>Reply #1</strong> within 1 minute. When that person replies again, it sends <strong>Reply #2</strong>. If they reply again, it sends <strong>Reply #3</strong> — and so on through all configured replies. Every time they reply, the server sends the next auto-reply in the chain. When all replies are exhausted, the server <strong>automatically removes that contact</strong> from the auto-reply queue. <strong>Blacklisted</strong> email addresses and domains are skipped entirely.
      </div>
      <div class="card-body" style="padding:0"><div class="tw"><table>
        <thead><tr><th>Name</th><th>IMAP Account</th><th>Replies</th><th>Active Threads</th><th>Total Sent</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody id="ar-body"><tr class="empty-row"><td colspan="7">Loading…</td></tr></tbody>
      </table></div></div>
    </div>
  </div>

  <!-- SMART MAIL ROUTING STUDIO -->
  <div class="page" id="page-mailrouting">
    <!-- Header & KPIs -->
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:18px">
      <div>
        <h2 style="font-size:20px;font-weight:700;display:flex;align-items:center;gap:8px;margin:0">
          🔀 Smart Mail Routing Studio
          <span class="badge b-purple" style="font-size:11px">Multi-IMAP & Multi-SMTP Failover</span>
        </h2>
        <div style="font-size:12px;color:var(--text2);margin-top:4px">
          Automatic Reply Routing (Gmail Priority → SMTP #1 Reply → Secondary Mailbox #2 Migration) with full thread persistence.
        </div>
      </div>
      <div style="display:flex;gap:8px;align-items:center">
        <button class="btn btn-secondary btn-sm" onclick="loadMailRouting()">↺ Refresh</button>
        <button class="btn btn-emerald btn-sm" onclick="triggerRoutingCron()">⚡ Run Routing Cron</button>
      </div>
    </div>

    <!-- KPI Stats Cards -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:20px">
      <div class="sc" style="--sc-c:var(--blue)">
        <div class="sc-lbl">Total Leads (Gmail)</div>
        <div class="sc-val" id="mr-stat-leads">0</div>
        <div style="font-size:10px;color:var(--text3);margin-top:4px">📥 Inbound from IMAP #1</div>
      </div>
      <div class="sc" style="--sc-c:var(--purple)">
        <div class="sc-lbl">First Replies Dispatched</div>
        <div class="sc-val" id="mr-stat-first-replies">0</div>
        <div style="font-size:10px;color:var(--text3);margin-top:4px">📤 Sent via SMTP #1 (Reply-To: #2)</div>
      </div>
      <div class="sc" style="--sc-c:var(--emerald)">
        <div class="sc-lbl">Migrated to Mailbox #2</div>
        <div class="sc-val" id="mr-stat-migrated">0</div>
        <div style="font-size:10px;color:var(--text3);margin-top:4px">🔄 Attached to Secondary IMAP/SMTP</div>
      </div>
      <div class="sc" style="--sc-c:var(--amber)">
        <div class="sc-lbl">Follow-Ups Active</div>
        <div class="sc-val" id="mr-stat-followups">0</div>
        <div style="font-size:10px;color:var(--text3);margin-top:4px">⏳ Simultaneous timer sequence</div>
      </div>
      <div class="sc" style="--sc-c:var(--teal)">
        <div class="sc-lbl">Active Conversations</div>
        <div class="sc-val" id="mr-stat-active">0</div>
        <div style="font-size:10px;color:var(--text3);margin-top:4px">💬 Ongoing chat threads</div>
      </div>
    </div>

    <!-- Live Smart Routing Flow Architecture Visualizer -->
    <div class="card" style="margin-bottom:20px;background:linear-gradient(135deg,rgba(30,41,59,0.7),rgba(15,23,42,0.9));border:1px solid rgba(167,139,250,0.25)">
      <div class="card-hd" style="border-bottom:1px solid rgba(255,255,255,0.08)">
        <h3 style="display:flex;align-items:center;gap:8px;color:#f8fafc">
          <span>⚡</span> Smart Email Routing Engine Architecture
        </h3>
        <span class="badge b-purple" style="font-size:10px">Up to 10 IMAP + 10 SMTP Accounts Supported</span>
      </div>
      <div class="card-body" style="padding:16px">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
          <!-- Step 1 Box -->
          <div style="background:rgba(255,255,255,0.03);border:1px solid rgba(59,130,246,0.3);border-radius:10px;padding:12px">
            <div style="display:flex;align-items:center;gap:6px;font-weight:700;font-size:12px;color:var(--blue);margin-bottom:6px">
              <span>1️⃣</span> Lead Reception
            </div>
            <div style="font-size:11px;color:var(--text2);line-height:1.4">
              <strong>IMAP #1 (Gmail Priority)</strong> captures new inbound email.<br>
              Extracts <code class="mono">Message-ID</code>, <code class="mono">Subject</code>, assigns <span class="badge b-blue" style="font-size:9px">NEW_LEAD</span>.
            </div>
          </div>
          <!-- Step 2 Box -->
          <div style="background:rgba(255,255,255,0.03);border:1px solid rgba(168,85,247,0.3);border-radius:10px;padding:12px">
            <div style="display:flex;align-items:center;gap:6px;font-weight:700;font-size:12px;color:var(--purple);margin-bottom:6px">
              <span>2️⃣</span> Simultaneous First Reply + Follow-Up
            </div>
            <div style="font-size:11px;color:var(--text2);line-height:1.4">
              <strong>SMTP #1 (Primary Sender)</strong> sends first response with <code class="mono">Reply-To: SMTP #2</code>.<br>
              Follow-Up queue starts simultaneously (delay timer).
            </div>
          </div>
          <!-- Step 3 Box -->
          <div style="background:rgba(255,255,255,0.03);border:1px solid rgba(16,185,129,0.3);border-radius:10px;padding:12px">
            <div style="display:flex;align-items:center;gap:6px;font-weight:700;font-size:12px;color:var(--emerald);margin-bottom:6px">
              <span>3️⃣</span> Mailbox Migration
            </div>
            <div style="font-size:11px;color:var(--text2);line-height:1.4">
              Lead replies → lands in <strong>IMAP #2 (Secondary Inbox)</strong>.<br>
              Conversation stage updates to <span class="badge b-green" style="font-size:9px">MOVED_TO_SECONDARY</span>.
            </div>
          </div>
          <!-- Step 4 Box -->
          <div style="background:rgba(255,255,255,0.03);border:1px solid rgba(245,158,11,0.3);border-radius:10px;padding:12px">
            <div style="display:flex;align-items:center;gap:6px;font-weight:700;font-size:12px;color:var(--amber);margin-bottom:6px">
              <span>4️⃣</span> Continuous Chat Mode
            </div>
            <div style="font-size:11px;color:var(--text2);line-height:1.4">
              All subsequent replies send from <strong>SMTP #2</strong> with <code class="mono">In-Reply-To</code> headers.<br>
              Never switches back to SMTP #1.
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Active Conversation Threads Table -->
    <div class="card" style="margin-bottom:20px">
      <div class="card-hd" style="flex-wrap:wrap;gap:8px">
        <h3>💬 Active Smart Conversation Threads</h3>
        <select class="fsel" id="mr-stage-filter" style="width:auto;padding:4px 8px;font-size:12px" onchange="loadMailRouting()">
          <option value="">All Stages</option>
          <option value="NEW_LEAD">🔵 NEW_LEAD</option>
          <option value="FIRST_REPLY_SENT">🟣 FIRST_REPLY_SENT</option>
          <option value="MOVED_TO_SECONDARY">🟢 MOVED_TO_SECONDARY</option>
          <option value="FOLLOWUP_RUNNING">🟠 FOLLOWUP_RUNNING</option>
          <option value="FOLLOWUP_COMPLETED">⚪ FOLLOWUP_COMPLETED</option>
        </select>
        <select class="fsel" id="mr-mailbox-filter" style="width:auto;padding:4px 8px;font-size:12px" onchange="loadMailRouting()">
          <option value="">All Mailboxes</option>
          <option value="primary">Primary (Gmail)</option>
          <option value="secondary">Secondary (Mailbox #2)</option>
        </select>
        <input class="fi" id="mr-thread-search" placeholder="Search email / subject / thread ID…" style="width:200px;padding:4px 8px;font-size:12px" oninput="mrSearchDebounce()">
        <button class="btn btn-secondary btn-sm" onclick="loadMailRouting()">↺ Refresh</button>
      </div>
      <div class="card-body" style="padding:0">
        <div class="tw"><table>
          <thead>
            <tr>
              <th>Lead Email & Name</th>
              <th>Rule & Subject</th>
              <th>Active Mailbox</th>
              <th>Stage</th>
              <th>Replies In / Step</th>
              <th>Follow-Up Status</th>
              <th>Last Activity</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="mr-threads-body">
            <tr class="empty-row"><td colspan="8">Loading conversation threads…</td></tr>
          </tbody>
        </table></div>
        <div id="mr-threads-pager" style="display:flex;align-items:center;justify-content:center;gap:8px;padding:10px;border-top:1px solid var(--border)"></div>
      </div>
    </div>

    <!-- Live Mail Routing Audit Log Stream -->
    <div class="card">
      <div class="card-hd" style="flex-wrap:wrap;gap:8px">
        <h3>🛰️ Mail Routing Audit Trail & Live Log Stream</h3>
        <select class="fsel" id="mr-log-event-filter" style="width:auto;padding:4px 8px;font-size:12px" onchange="loadMailRoutingLogs()">
          <option value="">All Events</option>
          <option value="lead_received">📥 Lead Received</option>
          <option value="first_reply_sent">📤 First Reply Sent</option>
          <option value="mailbox_migrated">🔄 Mailbox Migrated</option>
          <option value="chat_reply_sent">💬 Chat Reply Sent</option>
          <option value="followup_scheduled">⏱ Follow-Up Scheduled</option>
          <option value="followup_sent">📬 Follow-Up Sent</option>
          <option value="duplicate_ignored">🛡️ Duplicate Ignored</option>
        </select>
        <button class="btn btn-danger btn-sm" onclick="clearMailRoutingLogs()">🗑 Clear Routing Logs</button>
      </div>
      <div class="card-body" style="padding:0">
        <div class="tw"><table>
          <thead>
            <tr>
              <th>Time</th>
              <th>Event</th>
              <th>Lead Email</th>
              <th>Routing Mailbox / SMTP</th>
              <th>Stage Transition</th>
              <th>Status</th>
              <th>Details</th>
            </tr>
          </thead>
          <tbody id="mr-logs-body">
            <tr class="empty-row"><td colspan="7">Loading routing audit logs…</td></tr>
          </tbody>
        </table></div>
        <div id="mr-logs-pager" style="display:flex;align-items:center;justify-content:center;gap:8px;padding:10px;border-top:1px solid var(--border)"></div>
      </div>
    </div>
  </div>

  <!-- FOLLOW-UP -->
  <div class="page" id="page-followup">
    <div class="card" style="margin-bottom:18px">
      <div class="card-hd">
        <h3>📬 Follow-Up Rules</h3>
        <button class="btn btn-secondary btn-sm" onclick="loadFollowup()">↺ Refresh</button>
        <button class="btn btn-primary btn-sm" onclick="openFuModal()">+ New Follow-Up Rule</button>
      </div>
      <div class="info-box" style="margin:0;border-radius:0;border-left:0;border-right:0;border-top:0">
        <strong>How it works:</strong> Cron reads your IMAP inbox every minute. When a new email arrives from someone, the follow-up sequence is <strong>automatically added</strong> for that contact. The server then sends the follow-up messages at the configured intervals. <strong>Blacklisted</strong> addresses and domains are never enrolled. You can also enroll contacts manually from an email list or CSV upload.
      </div>
      <div class="card-body" style="padding:0"><div class="tw"><table>
        <thead><tr><th>Name</th><th>Steps</th><th>Active Contacts</th><th>Total Sent</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody id="fu-body"><tr class="empty-row"><td colspan="6">Loading…</td></tr></tbody>
      </table></div></div>
    </div>
  </div>

  <!-- ══ REPLY PENDING PAGE ══ -->




  <!-- ══ LEADS MANAGER PAGE ══ -->
  <div class="page" id="page-leads">
  <!-- Stats row -->
  <div id="leads-stats-row" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px">
    <div class="sc" style="--sc-c:var(--purple);flex:1;min-width:120px"><div class="sc-lbl">Total Leads</div><div class="sc-val" id="leads-stat-total">—</div></div>
    <div class="sc" style="--sc-c:var(--accent);flex:1;min-width:120px"><div class="sc-lbl">Email Lists</div><div class="sc-val" id="leads-stat-lists">—</div></div>
    <div class="sc" style="--sc-c:var(--accent2);flex:1;min-width:120px"><div class="sc-lbl">Auto-Reply</div><div class="sc-val" id="leads-stat-ar">—</div></div>
    <div class="sc" style="--sc-c:var(--accent3);flex:1;min-width:120px"><div class="sc-lbl">Follow-Up</div><div class="sc-val" id="leads-stat-fu">—</div></div>
  </div>
  <!-- Leads Table -->
  <div class="card" style="margin-bottom:18px">
    <div class="card-hd" style="flex-wrap:wrap;gap:6px">
      <h3>🗄️ All Leads</h3>
      <select class="fsel" id="leads-src-filter" style="width:auto;padding:5px 10px;font-size:12px" onchange="leadsOnSourceChange()">
        <option value="all">All Sources</option>
        <option value="lists">Email Lists</option>
        <option value="autoreply">Auto-Reply</option>
        <option value="followup">Follow-Up</option>
      </select>
      <!-- List sub-filter — only shown when source is lists or all -->
      <select class="fsel" id="leads-list-filter" style="width:auto;padding:5px 10px;font-size:12px;display:none" onchange="loadLeadsTable(1)">
        <option value="">All Lists</option>
      </select>
      <input class="fi" id="leads-search" placeholder="Search email / name…" style="width:180px;padding:5px 10px;font-size:12px" oninput="leadsSearchDebounce()">
      <button class="btn btn-secondary btn-sm" onclick="loadLeadsPage()">↺ Refresh</button>
      <button class="btn btn-primary btn-sm" onclick="exportLeadsFromTable()">⬇ Export CSV</button>
    </div>
    <div class="card-body" style="padding:0">
      <div class="tw"><table>
        <thead><tr><th>Email</th><th>Name</th><th>Source List / Rule</th><th>Type</th><th>Added</th><th style="width:90px">Actions</th></tr></thead>
        <tbody id="leads-body"><tr class="empty-row"><td colspan="6">Loading…</td></tr></tbody>
      </table></div>
      <div id="leads-pager" style="display:flex;align-items:center;justify-content:center;gap:8px;padding:12px;border-top:1px solid var(--border)"></div>
    </div>
  </div>
  <!-- Export Card -->
  <div class="card" style="margin-bottom:18px">
    <div class="card-hd"><h3>⬇ Export Leads as CSV</h3></div>
    <div class="card-body">
      <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
        <div class="fg" style="margin:0;min-width:160px">
          <label class="fl">Source</label>
          <select class="fsel" id="exp-source" onchange="updateExpListVis()">
            <option value="all">All Sources</option>
            <option value="lists">Email Lists Only</option>
            <option value="autoreply">Auto-Reply Leads Only</option>
            <option value="followup">Follow-Up Leads Only</option>
          </select>
        </div>
        <div class="fg" id="exp-list-wrap" style="margin:0;min-width:200px">
          <label class="fl">Filter by List <span class="flh">(optional)</span></label>
          <select class="fsel" id="exp-list"><option value="">All Lists</option></select>
        </div>
        <button class="btn btn-primary" onclick="exportLeads()">⬇ Download CSV</button>
      </div>
      <div style="font-size:11px;color:var(--text3);margin-top:8px">Exports email, name, source, and date for all matching leads as a UTF-8 CSV (Excel-compatible).</div>
    </div>
  </div>
  <!-- Clear Leads Card -->
  <div class="card">
    <div class="card-hd" style="background:rgba(239,68,68,.04)"><h3>🗑️ Clear / Reset Leads</h3></div>
    <div class="card-body">
      <div id="leads-al" class="al" style="margin-bottom:12px"></div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px">

        <!-- Clear Email List -->
        <div style="border:1px solid var(--border2);border-radius:10px;padding:14px">
          <div style="font-weight:600;font-size:13px;margin-bottom:8px">📋 Clear Email List</div>
          <div style="font-size:11px;color:var(--text3);margin-bottom:10px">Deletes all email addresses from a list. The list itself remains — only the contacts are removed.</div>
          <select class="fsel" id="cl-list" style="margin-bottom:8px"><option value="">— Select List —</option></select>
          <button class="btn btn-danger btn-sm" style="width:100%" onclick="clearLeads('list')">🗑 Clear List Emails</button>
        </div>

        <!-- Clear Auto-Reply Threads -->
        <div style="border:1px solid var(--border2);border-radius:10px;padding:14px">
          <div style="font-weight:600;font-size:13px;margin-bottom:8px">🔁 Reset Auto-Reply Threads</div>
          <div style="font-size:11px;color:var(--text3);margin-bottom:10px">Resets all reply threads for a rule — contacts will restart from step 1 when they email again.</div>
          <select class="fsel" id="cl-autoreply" style="margin-bottom:8px"><option value="">— Select Rule —</option></select>
          <button class="btn btn-danger btn-sm" style="width:100%" onclick="clearLeads('autoreply')">🗑 Reset Threads</button>
        </div>

        <!-- Clear Follow-Up Contacts -->
        <div style="border:1px solid var(--border2);border-radius:10px;padding:14px">
          <div style="font-weight:600;font-size:13px;margin-bottom:8px">📬 Clear Follow-Up Contacts</div>
          <div style="font-size:11px;color:var(--text3);margin-bottom:10px">Removes all enrolled contacts from a follow-up rule. They will stop receiving follow-up messages.</div>
          <select class="fsel" id="cl-followup" style="margin-bottom:8px"><option value="">— Select Rule —</option></select>
          <button class="btn btn-danger btn-sm" style="width:100%" onclick="clearLeads('followup')">🗑 Clear Contacts</button>
        </div>

        <!-- Clear All -->
        <div style="border:1px solid rgba(239,68,68,.35);border-radius:10px;padding:14px;background:rgba(239,68,68,.03)">
          <div style="font-weight:600;font-size:13px;margin-bottom:8px;color:var(--red)">⚠️ Clear Everything</div>
          <div style="font-size:11px;color:var(--text3);margin-bottom:10px">Clears ALL lists, ALL auto-reply threads, and ALL follow-up contacts. This cannot be undone.</div>
          <button class="btn btn-danger" style="width:100%" onclick="clearLeads('all')">🗑 Clear All Leads</button>
        </div>

      </div>
    </div>
  </div>
</div><!-- /#page-leads -->

  <!-- ══ BLACKLIST PAGE ══ -->
  <div class="page" id="page-blacklist">
    <div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr));max-width:900px;margin-bottom:18px">
      <div class="sc" style="--sc-c:var(--red)"><div class="sc-lbl">Blocked Emails</div><div class="sc-val" id="bl-stat-emails" style="color:var(--red)">—</div></div>
      <div class="sc" style="--sc-c:var(--red)"><div class="sc-lbl">Blocked Domains</div><div class="sc-val" id="bl-stat-domains" style="color:var(--red)">—</div></div>
      <div class="sc" style="--sc-c:#e74c3c"><div class="sc-lbl">Blocked Extensions</div><div class="sc-val" id="bl-stat-extensions" style="color:#e74c3c">—</div></div>
      <!-- New counters: Subject Blacklist + Has-the-Words filter. Existing
           Total Blocked card is preserved unchanged. -->
      <div class="sc" style="--sc-c:var(--accent3)"><div class="sc-lbl">Blocked Subjects</div><div class="sc-val" id="bl-stat-subjects" style="color:var(--accent3)">—</div></div>
      <div class="sc" style="--sc-c:var(--purple)"><div class="sc-lbl">Has-the-Words</div><div class="sc-val" id="bl-stat-keywords" style="color:var(--purple)">—</div></div>
      <div class="sc" style="--sc-c:var(--accent3)"><div class="sc-lbl">Total Blocked</div><div class="sc-val" id="bl-stat-total" style="color:var(--accent3)">—</div></div>
    </div>

    <div class="al a-warn on" style="margin-bottom:18px">
      🚫 <strong>Blacklisted addresses are never sent any emails</strong> — they are skipped by Auto-Reply, Follow-Up, and Campaign sends. Use this to block spam senders, competitors, or any address you never want to contact.
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:18px">
      <!-- Block Email Address -->
      <div class="card" style="margin:0">
        <div class="card-hd" style="background:rgba(248,113,113,.05)"><h3>📧 Block Email Address</h3></div>
        <div class="card-body">
          <div id="bl-email-al" class="al"></div>
          <div class="fg">
            <label class="fl">Email Address</label>
            <input class="fi" id="bl-email-inp" placeholder="spam@example.com" onkeydown="if(event.key==='Enter')addBlacklist('email')">
            <div class="fhint">The server will never send any message to this exact address.</div>
          </div>
          <button class="btn btn-danger" style="width:100%" onclick="addBlacklist('email')">🚫 Block This Email</button>
        </div>
      </div>

      <!-- Block Domain -->
      <div class="card" style="margin:0">
        <div class="card-hd" style="background:rgba(248,113,113,.05)"><h3>🌐 Block Entire Domain</h3></div>
        <div class="card-body">
          <div id="bl-domain-al" class="al"></div>
          <div class="fg">
            <label class="fl">Domain</label>
            <input class="fi" id="bl-domain-inp" placeholder="spammydomain.com" onkeydown="if(event.key==='Enter')addBlacklist('domain')">
            <div class="fhint">All emails @spammydomain.com will be blocked — wildcards automatically applied.</div>
          </div>
          <button class="btn btn-danger" style="width:100%" onclick="addBlacklist('domain')">🚫 Block This Domain</button>
        </div>
      </div>
    </div>

    <!-- ══ DOMAIN EXTENSION BLACKLIST ══════════════════════════════════
         Allows admin to block entire TLDs/extensions (.com, .net, .org, .us, etc.)
         Stored as domain entries starting with '.'; matched by isBlacklisted()
         suffix-variant logic. Real-time — effective on next cron IMAP poll. -->
    <div class="card" style="margin-bottom:18px">
      <div class="card-hd" style="background:rgba(231,76,60,.07)">
        <h3>🚫 Domain Extension Blacklist</h3>
        <span style="font-size:12px;color:var(--muted);font-weight:400">Block all emails from entire TLDs — e.g. every .com, .net, .org address</span>
      </div>
      <div class="card-body">
        <div class="al a-danger on" style="margin-bottom:14px">
          ⚠️ <strong>Inbound emails from blocked extensions are completely ignored</strong> — they are NOT stored, NOT processed by Auto-Reply or Follow-Up rules, and NO reply or message is ever sent to them. This applies in real time.
        </div>

        <!-- Quick-add buttons for common TLDs -->
        <div style="margin-bottom:14px">
          <label class="fl" style="margin-bottom:8px">Quick Block Common Extensions</label>
          <div style="display:flex;flex-wrap:wrap;gap:8px">
            <button class="btn btn-danger btn-sm" onclick="quickBlockExtension('.com')">🚫 Block .com</button>
            <button class="btn btn-danger btn-sm" onclick="quickBlockExtension('.net')">🚫 Block .net</button>
            <button class="btn btn-danger btn-sm" onclick="quickBlockExtension('.org')">🚫 Block .org</button>
            <button class="btn btn-danger btn-sm" onclick="quickBlockExtension('.us')">🚫 Block .us</button>
            <button class="btn btn-secondary btn-sm" onclick="quickBlockExtension('.info')">Block .info</button>
            <button class="btn btn-secondary btn-sm" onclick="quickBlockExtension('.biz')">Block .biz</button>
            <button class="btn btn-secondary btn-sm" onclick="quickBlockExtension('.co')">Block .co</button>
          </div>
        </div>

        <!-- Manual entry -->
        <div class="fg">
          <label class="fl">Or enter a custom extension</label>
          <div style="display:flex;gap:8px;align-items:center">
            <input class="fi" id="bl-extension-inp" placeholder=".io  or  .co.uk  or  net" style="flex:1" onkeydown="if(event.key==='Enter')addBlacklist('extension')">
            <button class="btn btn-danger" onclick="addBlacklist('extension')">🚫 Block Extension</button>
          </div>
          <div id="bl-extension-al" class="al" style="margin-top:8px"></div>
          <div class="fhint">Enter with or without leading dot — e.g. <code>.com</code> or <code>com</code>. All inbound emails from matching addresses will be silently dropped.</div>
        </div>
      </div>
    </div>

    <!-- Blocked Extensions list -->
    <div class="card" style="margin-bottom:18px">
      <div class="card-hd">
        <h3>🚫 Blocked Extensions</h3>
        <input class="fi" id="bl-search-extension" placeholder="Search…" style="max-width:200px;padding:5px 10px;font-size:12px" oninput="blSearchDebounce('extension')">
        <button class="btn btn-secondary btn-sm" onclick="loadBlacklist('extension')">↺ Refresh</button>
        <button class="btn btn-danger btn-sm" onclick="clearAllBlacklist('extension')">🗑 Clear All</button>
      </div>
      <div class="card-body" style="padding:0"><div class="tw"><table>
        <thead><tr><th>Extension / TLD</th><th>Added</th><th>Actions</th></tr></thead>
        <tbody id="bl-extension-body"><tr class="empty-row"><td colspan="3">Loading…</td></tr></tbody>
      </table></div>
      <div id="bl-extension-pager" style="display:flex;gap:8px;align-items:center;justify-content:center;padding:10px 0"></div>
      </div>
    </div>

    <!-- ══ NEW FILTERS — Subject Blacklist + Has-the-Words ══════════════
         Additive: existing email/domain blocking above is unchanged.
         These filters match against the subject line / combined text the
         IMAP fetch path captures, not the message body (no body fetch
         is performed). Case-insensitive substring match. -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:18px">
      <!-- Subject Blacklist -->
      <div class="card" style="margin:0">
        <div class="card-hd" style="background:rgba(245,158,11,.05)"><h3>📝 Subject Blacklist</h3></div>
        <div class="card-body">
          <div id="bl-subject-al" class="al"></div>
          <div class="fg">
            <label class="fl">Subject Phrase</label>
            <input class="fi" id="bl-subject-inp" placeholder="unsubscribe me" onkeydown="if(event.key==='Enter')addBlacklist('subject')">
            <div class="fhint">Any inbound message whose <strong>subject</strong> contains this phrase (case-insensitive substring) is skipped by Auto-Reply &amp; Follow-Up enrolment.</div>
          </div>
          <button class="btn btn-amber" style="width:100%" onclick="addBlacklist('subject')">🚫 Block This Subject</button>
        </div>
      </div>

      <!-- Has the Words -->
      <div class="card" style="margin:0">
        <div class="card-hd" style="background:rgba(167,139,250,.05)"><h3>🔍 Has the Words</h3></div>
        <div class="card-body">
          <div id="bl-keyword-al" class="al"></div>
          <div class="fg">
            <label class="fl">Word or Phrase</label>
            <input class="fi" id="bl-keyword-inp" placeholder="lottery winner" onkeydown="if(event.key==='Enter')addBlacklist('keyword')">
            <div class="fhint">Matches across <strong>subject + sender email + sender name</strong>. Case-insensitive substring. Useful for blocking known spam phrases or sender patterns.</div>
          </div>
          <button class="btn btn-purple" style="width:100%" onclick="addBlacklist('keyword')">🚫 Block This Phrase</button>
        </div>
      </div>
    </div>

    <div class="card" style="margin-bottom:18px">
      <div class="card-hd">
        <h3>🚫 Blocked Emails</h3>
        <input class="fi" id="bl-search-email" placeholder="Search…" style="max-width:200px;padding:5px 10px;font-size:12px" oninput="blSearchDebounce('email')">
        <button class="btn btn-secondary btn-sm" onclick="loadBlacklist('email')">↺ Refresh</button>
        <button class="btn btn-danger btn-sm" onclick="clearAllBlacklist('email')">🗑 Clear All</button>
      </div>
      <div class="card-body" style="padding:0"><div class="tw"><table>
        <thead><tr><th>Email Address</th><th>Added</th><th>Actions</th></tr></thead>
        <tbody id="bl-email-body"><tr class="empty-row"><td colspan="3">Loading…</td></tr></tbody>
      </table></div>
      <div id="bl-email-pager" style="display:flex;gap:8px;align-items:center;justify-content:center;padding:10px 0"></div>
      </div>
    </div>

    <div class="card">
      <div class="card-hd">
        <h3>🌐 Blocked Domains</h3>
        <input class="fi" id="bl-search-domain" placeholder="Search…" style="max-width:200px;padding:5px 10px;font-size:12px" oninput="blSearchDebounce('domain')">
        <button class="btn btn-secondary btn-sm" onclick="loadBlacklist('domain')">↺ Refresh</button>
        <button class="btn btn-danger btn-sm" onclick="clearAllBlacklist('domain')">🗑 Clear All</button>
      </div>
      <div class="card-body" style="padding:0"><div class="tw"><table>
        <thead><tr><th>Domain</th><th>Added</th><th>Actions</th></tr></thead>
        <tbody id="bl-domain-body"><tr class="empty-row"><td colspan="3">Loading…</td></tr></tbody>
      </table></div>
      <div id="bl-domain-pager" style="display:flex;gap:8px;align-items:center;justify-content:center;padding:10px 0"></div>
      </div>
    </div>

    <!-- ── Blocked Subjects (NEW) ─────────────────────────────────────── -->
    <div class="card" style="margin-top:18px">
      <div class="card-hd">
        <h3>📝 Blocked Subjects</h3>
        <input class="fi" id="bl-search-subject" placeholder="Search…" style="max-width:200px;padding:5px 10px;font-size:12px" oninput="blSearchDebounce('subject')">
        <button class="btn btn-secondary btn-sm" onclick="loadBlacklist('subject')">↺ Refresh</button>
        <button class="btn btn-danger btn-sm" onclick="clearAllBlacklist('subject')">🗑 Clear All</button>
      </div>
      <div class="card-body" style="padding:0"><div class="tw"><table>
        <thead><tr><th>Subject Phrase</th><th>Added</th><th>Actions</th></tr></thead>
        <tbody id="bl-subject-body"><tr class="empty-row"><td colspan="3">Loading…</td></tr></tbody>
      </table></div>
      <div id="bl-subject-pager" style="display:flex;gap:8px;align-items:center;justify-content:center;padding:10px 0"></div>
      </div>
    </div>

    <!-- ── Has-the-Words filter list (NEW) ────────────────────────────── -->
    <div class="card" style="margin-top:18px">
      <div class="card-hd">
        <h3>🔍 Has-the-Words Filters</h3>
        <input class="fi" id="bl-search-keyword" placeholder="Search…" style="max-width:200px;padding:5px 10px;font-size:12px" oninput="blSearchDebounce('keyword')">
        <button class="btn btn-secondary btn-sm" onclick="loadBlacklist('keyword')">↺ Refresh</button>
        <button class="btn btn-danger btn-sm" onclick="clearAllBlacklist('keyword')">🗑 Clear All</button>
      </div>
      <div class="card-body" style="padding:0"><div class="tw"><table>
        <thead><tr><th>Phrase</th><th>Added</th><th>Actions</th></tr></thead>
        <tbody id="bl-keyword-body"><tr class="empty-row"><td colspan="3">Loading…</td></tr></tbody>
      </table></div>
      <div id="bl-keyword-pager" style="display:flex;gap:8px;align-items:center;justify-content:center;padding:10px 0"></div>
      </div>
    </div>
  </div><!-- /#page-blacklist -->

  <!-- ══ EMAIL TEMPLATES PAGE ══ -->
  <div class="page" id="page-templates">
    <div class="card" style="margin-bottom:18px">
      <div class="card-hd">
        <h3>📝 Email Templates</h3>
        <button class="btn btn-secondary btn-sm" onclick="loadTemplates()">↺ Refresh</button>
        <button class="btn btn-primary btn-sm" onclick="openNewTemplateModal()">＋ Create Template</button>
      </div>
      <div class="info-box" style="margin:0;border-radius:0;border-left:0;border-right:0;border-top:0">
        Reusable HTML email templates with rich formatting, buttons, responsive design, spintax, and variable tags (<code>{{NAME}}</code>, <code>{{EMAIL}}</code>, <code>{{UNSUBSCRIBE_URL}}</code>).
      </div>
      <div class="card-body" style="padding:0">
        <div class="tw"><table>
          <thead><tr><th>Name</th><th>Subject</th><th>Owner</th><th>Created</th><th>Actions</th></tr></thead>
          <tbody id="templates-body"><tr class="empty-row"><td colspan="5">Loading…</td></tr></tbody>
        </table></div>
      </div>
    </div>
  </div>

  <!-- ══ SYSTEM & ACTIVITY LOGS PAGE ══ -->
  <div class="page" id="page-systemlogs">
    <!-- Stat row -->
    <div id="sys-stats-row" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px">
      <div class="sc" style="--sc-c:var(--accent);flex:1;min-width:130px"><div class="sc-lbl">📤 Sent Today</div><div class="sc-val" id="sys-stat-sent-today" style="color:var(--accent)">—</div></div>
      <div class="sc" style="--sc-c:var(--accent2);flex:1;min-width:130px"><div class="sc-lbl">👁️ Opened</div><div class="sc-val" id="sys-stat-opened" style="color:var(--accent2)">—</div><div class="sc-sub" id="sys-stat-open-rate">—% open rate</div></div>
      <div class="sc" style="--sc-c:var(--purple);flex:1;min-width:130px"><div class="sc-lbl">🖱️ Clicked</div><div class="sc-val" id="sys-stat-clicked" style="color:var(--purple)">—</div><div class="sc-sub" id="sys-stat-click-rate">—% CTR</div></div>
      <div class="sc" style="--sc-c:var(--accent3);flex:1;min-width:130px"><div class="sc-lbl">🕒 Scheduled FU</div><div class="sc-val" id="sys-stat-sched-fu" style="color:var(--accent3)">—</div></div>
      <div class="sc" style="--sc-c:#fb923c;flex:1;min-width:130px"><div class="sc-lbl">🔄 Retry Queue</div><div class="sc-val" id="sys-stat-retry-queue" style="color:#fb923c">—</div></div>
      <div class="sc" style="--sc-c:var(--red);flex:1;min-width:130px"><div class="sc-lbl">❌ Failed Today</div><div class="sc-val" id="sys-stat-failed-today" style="color:var(--red)">—</div></div>
      <div class="sc" style="--sc-c:#94a3b8;flex:1;min-width:130px"><div class="sc-lbl">🛑 Unsubscribed</div><div class="sc-val" id="sys-stat-unsub" style="color:#94a3b8">—</div></div>
    </div>

    <div class="card" style="margin-bottom:18px">
      <div class="card-hd" style="flex-wrap:wrap;gap:8px">
        <h3>🛰️ System Activity Logs</h3>
        <select class="fsel" id="sys-event-filter" style="width:auto;padding:5px 10px;font-size:12px" onchange="loadSystemLogs(1)">
          <option value="">All Events</option>
          <option value="sent">📤 Sent</option>
          <option value="opened">👁️ Opened</option>
          <option value="clicked">🖱️ Clicked</option>
          <option value="queued">🕒 Queued</option>
          <option value="retry">🔄 Retry</option>
          <option value="failed">❌ Failed</option>
          <option value="unsubscribed">🛑 Unsubscribed</option>
        </select>
        <input class="fi" id="sys-email-filter" placeholder="Search email…" style="width:180px;padding:5px 10px;font-size:12px" oninput="sysLogDebounce()">
        <button class="btn btn-secondary btn-sm" onclick="loadSystemLogs(1)">↺ Refresh</button>
        <button class="btn btn-danger btn-sm" onclick="clearSystemLogs()">🗑 Clear Logs</button>
      </div>
      <div class="card-body" style="padding:0">
        <div class="tw"><table>
          <thead><tr><th>Event</th><th>Recipient Email</th><th>Details / Link / Reason</th><th>SMTP</th><th>IP Address</th><th>User Agent</th><th>Time</th></tr></thead>
          <tbody id="sys-logs-body"><tr class="empty-row"><td colspan="7">Loading…</td></tr></tbody>
        </table></div>
        <div id="sys-logs-pager" style="display:flex;align-items:center;justify-content:center;gap:8px;padding:12px;border-top:1px solid var(--border)"></div>
      </div>
    </div>
  </div>

</div><!-- /#main -->

<!-- ══ MODALS ══ -->


<!-- ══ IMAP MODAL ══ -->
<div class="modal-bg" id="imap-modal">
  <div class="modal" style="max-width:520px">
    <div class="modal-hd"><h3 id="imap-modal-title">📥 Add IMAP Account</h3><span class="modal-x" onclick="closeModal('imap-modal')">✕</span></div>
    <div class="modal-body">
      <div id="imap-al" class="al"></div>
      <div class="fg"><label class="fl">Account Name *</label><input class="fi" id="im-name" placeholder="e.g. Sales Inbox"></div>
      <div class="fg"><label class="fl">IMAP Host *</label><input class="fi" id="im-host" placeholder="imap.gmail.com"></div>
      <div class="frow fc2">
        <div class="fg"><label class="fl">Port</label><input class="fi" id="im-port" type="number" value="993"></div>
        <div class="fg"><label class="fl">Security</label>
          <select class="fsel" id="im-ssl"><option value="1">SSL/TLS (993)</option><option value="0">No SSL (143)</option></select>
        </div>
      </div>
      <div class="fg"><label class="fl">Username (Email) *</label><input class="fi" id="im-user" placeholder="inbox@example.com"></div>
      <div class="fg"><label class="fl">Password * <span class="flh">(blank = keep existing when editing)</span></label><input class="fi" id="im-pass" type="password" placeholder="App password or IMAP password"></div>
      <div class="info-box">💡 For Gmail: use an <strong>App Password</strong> (not your login). Enable 2FA → Google Account → Security → App Passwords.</div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-blue" onclick="testImap()">🔍 Test Connection</button>
      <button class="btn btn-secondary" onclick="closeModal('imap-modal')">Cancel</button>
      <button class="btn btn-primary" id="imap-save-btn" onclick="saveImap()">Save Account</button>
    </div>
  </div>
</div>

<!-- ══ AUTO-REPLY MODAL ══ -->
<div class="modal-bg" id="ar-modal">
  <div class="modal modal-xl" style="max-width:1060px">
    <div class="modal-hd"><h3 id="ar-modal-title">🔁 New Auto-Reply Rule</h3><span class="modal-x" onclick="closeModal('ar-modal')">✕</span></div>
    <div class="modal-body">
      <div id="ar-al" class="al"></div>
      <!-- Autosave Draft Banner -->
      <div id="ar-draft-banner" class="al a-inf" style="display:none;margin-bottom:12px;align-items:center;justify-content:space-between;border-radius:8px"></div>
      <div class="stitle">Rule Settings</div>
      <div class="frow fc2" style="margin-bottom:12px">
        <div class="fg" style="margin:0"><label class="fl">Rule Name *</label><input class="fi" id="ar-name" placeholder="e.g. Sales Reply Chain"></div>
        <div class="fg" style="margin:0"><label class="fl">Status</label>
          <select class="fsel" id="ar-status"><option value="active">✅ Active</option><option value="paused">⏸ Paused</option></select>
        </div>
      </div>
      <!-- Admin-only owner picker. Hidden for non-admin via the JS that
           opens the modal — non-admin users can never reassign rules. -->
      <div id="ar-owner-row" class="fg" style="display:none;margin-bottom:14px">
        <label class="fl">👤 Assign to User <span class="flh">(admin only — transfers visibility)</span></label>
        <select class="fsel" id="ar-owner-sel"></select>
        <div class="fhint">Choose which account owns this rule. After save, only the selected user (and admin) will see it.</div>
      </div>
      <!-- ══ SMART MAIL ROUTING (Multi-Account Setup) ══ -->
      <div style="margin-bottom:16px;padding:14px;background:rgba(167,139,250,0.06);border:1px solid rgba(167,139,250,0.25);border-radius:10px">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:700;font-size:13px;color:var(--purple)">
            <input type="checkbox" id="ar-enable-smart" onchange="toggleArSmartRoutingUI()" style="transform:scale(1.2)">
            <span>🔀 Enable Smart Mail Routing (Multi-IMAP / Multi-SMTP Failover)</span>
          </label>
          <span class="badge b-purple" style="font-size:10px">10 IMAP + 10 SMTP</span>
        </div>
        <div id="ar-smart-routing-fields" style="display:none">
          <div style="font-size:11px;color:var(--text2);margin-bottom:12px;line-height:1.4">
            Automatically routes incoming leads from <strong>Primary Gmail</strong>, sends first response from <strong>SMTP #1</strong> with <code class="mono">Reply-To: SMTP #2</code>, and permanently migrates the conversation to <strong>IMAP/SMTP #2</strong> upon reply.
          </div>
          <div class="frow fc2" style="margin-bottom:10px">
            <div class="fg" style="margin:0">
              <label class="fl">IMAP #1 — Primary Lead Receiver (Gmail) *</label>
              <select class="fsel" id="ar-smart-primary-imap"><option value="">— Select Primary Gmail Inbox —</option></select>
            </div>
            <div class="fg" style="margin:0">
              <label class="fl">IMAP #2 — Secondary Inbox (Ongoing Replies) *</label>
              <select class="fsel" id="ar-smart-secondary-imap"><option value="">— Select Secondary Mailbox —</option></select>
            </div>
          </div>
          <div class="frow fc2" style="margin-bottom:10px">
            <div class="fg" style="margin:0">
              <label class="fl">SMTP #1 — Primary Sender (First Reply Only) *</label>
              <select class="fsel" id="ar-smart-primary-smtp"><option value="">— Select Primary SMTP #1 —</option></select>
            </div>
            <div class="fg" style="margin:0">
              <label class="fl">SMTP #2 — Secondary Sender & Reply-To Target *</label>
              <select class="fsel" id="ar-smart-secondary-smtp"><option value="">— Select Secondary SMTP #2 —</option></select>
            </div>
          </div>
          <div class="frow fc2" style="margin-bottom:10px">
            <div class="fg" style="margin:0">
              <label class="fl">IMAP #3 — Backup Inbox <span class="flh">(optional failover)</span></label>
              <select class="fsel" id="ar-smart-backup-imap"><option value="">— None (Optional Backup) —</option></select>
            </div>
            <div class="fg" style="margin:0">
              <label class="fl">Linked Follow-Up Rule <span class="flh">(Starts simultaneously on delay)</span></label>
              <select class="fsel" id="ar-smart-fu-rule"><option value="">— None (No Auto Follow-Up) —</option></select>
            </div>
          </div>
          <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:10px;padding-top:10px;border-top:1px solid rgba(167,139,250,0.15)">
            <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer">
              <input type="checkbox" id="ar-smart-replyto-switch" checked>
              <span>Enable Automatic Reply-To Switching</span>
            </label>
            <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer">
              <input type="checkbox" id="ar-smart-always-fu" checked>
              <span>Always Send Follow-Up (Unconditional delay)</span>
            </label>
            <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer">
              <input type="checkbox" id="ar-smart-gmail-priority" checked>
              <span>Gmail Priority Polling</span>
            </label>
          </div>
        </div>
      </div>

      <div class="fg" style="margin-bottom:14px" id="ar-imap-admin-row">
        <label class="fl">IMAP 1 — First Contact * <span class="flh">(inbox where new leads arrive; AR1 trigger; lead is auto-deleted from this inbox after reply 1 is sent)</span></label>
        <div style="display:flex;gap:8px;align-items:center">
          <select class="fsel" id="ar-imap" style="flex:1"><option value="">— select IMAP account —</option></select>
          <button class="btn btn-blue btn-sm" onclick="nav('imap')">+ Add IMAP</button>
        </div>
      </div>
      <!-- Non-admin: read-only IMAP display (assigned by admin) -->
      <div class="fg" style="margin-bottom:14px;display:none" id="ar-imap-user-row">
        <label class="fl">📥 IMAP Account <span class="flh">(assigned by Admin — read only)</span></label>
        <div id="ar-imap-user-display" style="background:var(--bg2);border:1px solid var(--border);border-radius:6px;padding:8px 12px;font-size:13px;color:var(--text2)">Loading…</div>
        <div class="fhint">IMAP is managed by the Admin. Contact your administrator to change it.</div>
      </div>
      <div class="fg" id="ar-imap2-row" style="margin-bottom:14px">
        <label class="fl">IMAP 2 — Ongoing Replies <span class="flh">(optional; user replies to AR1 are auto-moved here, and AR2..N are triggered from this inbox via the main SMTP pool)</span></label>
        <div style="display:flex;gap:8px;align-items:center">
          <select class="fsel" id="ar-imap2" style="flex:1"><option value="">— none (use IMAP 1 for the entire conversation) —</option></select>
          <button class="btn btn-blue btn-sm" onclick="nav('imap')">+ Add IMAP</button>
        </div>
      </div>
      <div class="fg" style="margin-bottom:14px;padding:12px 14px;background:var(--bg3,#f5f7fa);border-radius:8px;border:1px solid var(--border,#e0e4ea)">
        <label class="fl" style="margin-bottom:6px">Reply Mode</label>
        <div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap">
          <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;flex:1;min-width:220px">
            <input type="radio" name="ar-mode" id="ar-mode-time" value="0" checked style="margin-top:3px">
            <div>
              <div style="font-weight:600;font-size:13px">⏱ Time-Based (original)</div>
              <div style="font-size:11px;color:var(--text3)">Replies are sent automatically after a delay (e.g. 1 min, 1 hour). User does not need to reply.</div>
            </div>
          </label>
          <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;flex:1;min-width:220px">
            <input type="radio" name="ar-mode" id="ar-mode-seq" value="1" style="margin-top:3px">
            <div>
              <div style="font-weight:600;font-size:13px">🔄 Sequential (message-triggered)</div>
              <div style="font-size:11px;color:var(--text3)">Each auto-reply only sends <strong>after the user sends their next message</strong>. Auto Reply 1 → wait → Auto Reply 2 → wait → … up to 15.</div>
            </div>
          </label>
        </div>
      </div>
      <div class="stitle" id="ar-smtp-pool-title">SMTP Pool <span style="font-size:10px;font-weight:400;text-transform:none;color:var(--text3)">(rotated per send)</span></div>
      <!-- Admin: SMTP checkbox pool -->
      <div class="fg" id="ar-smtp-admin-row"><div class="smtp-pool" id="ar-smtp-pool"><div style="color:var(--text3);font-size:12px;padding:4px">Loading…</div></div></div>
      <!-- Non-admin: read-only SMTP display (assigned by admin) -->
      <div class="fg" id="ar-smtp-user-row" style="display:none">
        <div id="ar-smtp-user-display" style="background:var(--bg2);border:1px solid var(--border);border-radius:6px;padding:8px 12px;font-size:13px;color:var(--text2)">Loading…</div>
        <div class="fhint" style="margin-top:4px">🔒 SMTP servers are managed by the Admin. These will be used automatically.</div>
      </div>
      <div class="stitle">From Emails <span style="font-size:10px;font-weight:400;text-transform:none;color:var(--text3)">(random per send)</span></div>
      <div class="fg">
        <div class="tags-wrap" id="ar-from-wrap" onclick="$('ar-from-inp').focus()">
          <input class="tag-inp" id="ar-from-inp" placeholder="Name <email> or email → Enter" onkeydown="arFromKey(event)">
        </div>
      </div>
      <div class="stitle">Reply Messages <span id="ar-step-label" style="font-size:10px;font-weight:400;text-transform:none;color:var(--text3)">0 replies</span></div>
      <!-- Quota banner — populated by refreshArQuota() when the modal opens.
           Shows current usage vs admin-set cap. Goes red when at cap so the
           user sees why "+ Add Reply" is disabled. -->
      <div id="ar-quota-banner" class="al" style="display:none;margin-bottom:10px;padding:8px 10px;font-size:12px"></div>
      <div style="margin-bottom:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <button class="btn btn-blue btn-sm" id="ar-add-step-btn" onclick="arAddStep()">＋ Add Reply</button>
        <span id="ar-mode-hint" style="font-size:11px;color:var(--text3)">Max 15 replies. <span id="ar-mode-hint-text">Auto Reply 1 sends when first email arrives; each next reply sends after user replies.</span></span>
      </div>
      <div id="ar-steps-wrap"></div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-secondary" onclick="closeModal('ar-modal')">Cancel</button>
      <button class="btn btn-primary" id="ar-save-btn" onclick="saveAr()">💾 Save Rule</button>
    </div>
  </div>
</div>

<!-- AUTO-REPLY THREADS MODAL -->
<div class="modal-bg" id="ar-threads-modal">
  <div class="modal modal-lg">
    <div class="modal-hd"><h3 id="ar-threads-title">🧵 Threads</h3><span class="modal-x" onclick="closeModal('ar-threads-modal')">✕</span></div>
    <div class="modal-body" style="padding:0"><div class="tw"><table>
      <thead><tr><th>From Email</th><th>Name</th><th>Subject</th><th>Current Reply</th><th>Replies In</th><th>Last Sent</th><th>Status</th></tr></thead>
      <tbody id="ar-threads-body"><tr class="empty-row"><td colspan="7">Loading…</td></tr></tbody>
    </table></div></div>
  </div>
</div>

<!-- AUTO-REPLY LOGS MODAL -->
<div class="modal-bg" id="ar-logs-modal">
  <div class="modal modal-lg">
    <div class="modal-hd"><h3 id="ar-logs-title">📋 Auto-Reply Logs</h3><button class="btn btn-danger btn-sm" id="ar-logs-clear-btn" style="margin-left:auto;margin-right:12px;display:none" onclick="clearArLogs()">🗑 Clear Logs</button><span class="modal-x" onclick="closeModal('ar-logs-modal')">✕</span></div>
    <div class="modal-body" style="padding:0"><div class="tw"><table>
      <thead><tr><th>To Email</th><th>Reply #</th><th>Status</th><th>SMTP</th><th>Error</th><th>Time</th></tr></thead>
      <tbody id="ar-logs-body"><tr class="empty-row"><td colspan="6">Loading…</td></tr></tbody>
    </table></div></div>
  </div>
</div>

<!-- ══ FOLLOW-UP MODAL ══ -->
<div class="modal-bg" id="fu-modal">
  <div class="modal modal-xl" style="max-width:1060px">
    <div class="modal-hd"><h3 id="fu-modal-title">📬 New Follow-Up Rule</h3><span class="modal-x" onclick="closeModal('fu-modal')">✕</span></div>
    <div class="modal-body">
      <div id="fu-al" class="al"></div>
      <div class="stitle">Rule Settings</div>
      <div class="frow fc2" style="margin-bottom:12px">
        <div class="fg" style="margin:0"><label class="fl">Rule Name *</label><input class="fi" id="fu-name" placeholder="e.g. Onboarding Drip"></div>
        <div class="fg" style="margin:0"><label class="fl">Status</label>
          <select class="fsel" id="fu-status"><option value="active">✅ Active</option><option value="paused">⏸ Paused</option></select>
        </div>
      </div>
      <!-- Admin-only owner picker (FU). Same semantics as the AR variant. -->
      <div id="fu-owner-row" class="fg" style="display:none;margin-bottom:14px">
        <label class="fl">👤 Assign to User <span class="flh">(admin only — transfers visibility)</span></label>
        <select class="fsel" id="fu-owner-sel"></select>
        <div class="fhint">Choose which account owns this rule. After save, only the selected user (and admin) will see it.</div>
      </div>
      <!-- Admin: IMAP selector -->
      <div class="fg" style="margin-bottom:12px" id="fu-imap-admin-row">
        <label class="fl">IMAP Account <span style="font-size:10px;font-weight:400;color:var(--text3)">(optional — auto-enroll new senders from inbox)</span></label>
        <select class="fsel" id="fu-imap"><option value="">— None (manual enroll only) —</option></select>
      </div>
      <!-- Non-admin: read-only IMAP display -->
      <div class="fg" style="margin-bottom:12px;display:none" id="fu-imap-user-row">
        <label class="fl">📥 IMAP Account <span style="font-size:10px;font-weight:400;color:var(--text3)">(assigned by Admin — read only)</span></label>
        <div id="fu-imap-user-display" style="background:var(--bg2);border:1px solid var(--border);border-radius:6px;padding:8px 12px;font-size:13px;color:var(--text2)">Loading…</div>
        <div class="fhint">IMAP is managed by the Admin. Contact your administrator to change it.</div>
      </div>
      <div class="stitle">SMTP Pool <span style="font-size:10px;font-weight:400;text-transform:none;color:var(--text3)">(rotated per send)</span></div>
      <!-- Admin: SMTP checkbox pool -->
      <div class="fg" id="fu-smtp-admin-row"><div class="smtp-pool" id="fu-smtp-pool"><div style="color:var(--text3);font-size:12px;padding:4px">Loading…</div></div></div>
      <!-- Non-admin: read-only SMTP display -->
      <div class="fg" id="fu-smtp-user-row" style="display:none">
        <div id="fu-smtp-user-display" style="background:var(--bg2);border:1px solid var(--border);border-radius:6px;padding:8px 12px;font-size:13px;color:var(--text2)">Loading…</div>
        <div class="fhint" style="margin-top:4px">🔒 SMTP servers are managed by the Admin. These will be used automatically.</div>
      </div>
      <div class="stitle">From Emails <span style="font-size:10px;font-weight:400;text-transform:none;color:var(--text3)">(random per send)</span></div>
      <div class="fg">
        <div class="tags-wrap" id="fu-from-wrap" onclick="$('fu-from-inp').focus()">
          <input class="tag-inp" id="fu-from-inp" placeholder="Name <email> or email → Enter" onkeydown="fuFromKey(event)">
        </div>
      </div>

      <!-- AUTOMATIC TIME DELAY FOLLOW-UP BANNER -->
      <div style="background:linear-gradient(135deg,rgba(74,222,128,0.08) 0%,rgba(34,211,238,0.06) 100%);border:1px solid rgba(74,222,128,0.25);border-radius:10px;padding:12px 16px;margin:16px 0 12px;display:flex;align-items:center;gap:12px">
        <div style="font-size:24px">⏱️</div>
        <div>
          <div style="font-size:12px;font-weight:700;color:var(--accent);display:block">Automatic Sequential Time Delay Follow-Up</div>
          <div style="font-size:11px;color:var(--text2)">রিসিভার ইমেইল ওপেন/রিড না করলেও প্রতিটি Follow-up নির্ধারিত সময় (Delay) অনুযায়ী পর্যায়ক্রমে স্বয়ংক্রিয়ভাবে Send হবে।</div>
        </div>
      </div>

      <!-- LIVE SEQUENCE TIMELINE PREVIEW -->
      <div class="stitle">Live Sequence Timeline <span style="font-size:10px;font-weight:400;text-transform:none;color:var(--text3)">(Sequential delay preview)</span></div>
      <div id="fu-timeline-preview" class="seq-timeline"></div>

      <div class="stitle">Follow-Up Messages <span id="fu-step-label" style="font-size:10px;font-weight:400;text-transform:none;color:var(--text3)">0 messages</span></div>
      <!-- Quota banner -->
      <div id="fu-quota-banner" class="al" style="display:none;margin-bottom:10px;padding:8px 10px;font-size:12px"></div>
      <div style="margin-bottom:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <button class="btn btn-blue btn-sm" id="fu-add-step-btn" onclick="fuAddStep()">＋ Add Message</button>
        <span style="font-size:11px;color:var(--text3)">Drag & drop cards or adjust delays. Each sends sequentially after previous step.</span>
      </div>
      <div id="fu-steps-wrap"></div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-secondary" onclick="closeModal('fu-modal')">Cancel</button>
      <button class="btn btn-primary" id="fu-save-btn" onclick="saveFu()">💾 Save Rule</button>
    </div>
  </div>
</div>

<!-- ══ EMAIL TEMPLATE EDITOR MODAL ══ -->
<div class="modal-bg" id="template-modal">
  <div class="modal modal-lg">
    <div class="modal-hd"><h3 id="template-modal-title">📝 Email Template</h3><span class="modal-x" onclick="closeModal('template-modal')">✕</span></div>
    <div class="modal-body">
      <div id="template-al" class="al"></div>
      <input type="hidden" id="tmpl-id">
      <div class="fg"><label class="fl">Template Name *</label><input class="fi" id="tmpl-name" placeholder="e.g. Follow-Up #1 — Value Proposition"></div>
      <div class="fg"><label class="fl">Subject Line</label><input class="fi" id="tmpl-subject" placeholder="Subject line with {{NAME}} or spintax..."></div>
      <div class="fg">
        <label class="fl">HTML Body — Rich Text Composer</label>
        <div class="rte-wrap">
          <div class="rte-toolbar" id="tmpl-rte-bar"></div>
          <div class="rte-editor" id="tmpl-html-editor" contenteditable="true"></div>
          <textarea class="fta" id="tmpl-html-raw" style="display:none;min-height:180px;border:none;border-radius:0;background:var(--bg4);font-family:var(--mono);font-size:12px"></textarea>
        </div>
      </div>
      <div class="fg"><label class="fl">Plain Text (Optional fallback)</label><textarea class="fta" id="tmpl-text" style="min-height:50px"></textarea></div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-secondary" onclick="closeModal('template-modal')">Cancel</button>
      <button class="btn btn-primary" onclick="saveTemplateFromModal()">💾 Save Template</button>
    </div>
  </div>
</div>

<!-- ══ TEMPLATE PICKER MODAL ══ -->
<div class="modal-bg" id="template-picker-modal">
  <div class="modal modal-lg">
    <div class="modal-hd"><h3>📋 Choose an Email Template</h3><span class="modal-x" onclick="closeModal('template-picker-modal')">✕</span></div>
    <div class="modal-body">
      <div style="margin-bottom:12px"><input class="fi" id="tmpl-picker-search" placeholder="🔍 Search templates..." oninput="filterTemplatePicker()"></div>
      <div id="tmpl-picker-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px;max-height:420px;overflow-y:auto"></div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-secondary" onclick="closeModal('template-picker-modal')">Cancel</button>
    </div>
  </div>
</div>

<!-- ══ TEMPLATE PREVIEW MODAL (Desktop, Mobile, Dark & Light) ══ -->
<div class="modal-bg" id="template-preview-modal">
  <div class="modal modal-lg" style="max-width:920px">
    <div class="modal-hd">
      <h3 id="tmpl-prev-title">📱 Live Multi-Device Email Preview</h3>
      <div style="display:flex;gap:6px;margin-left:auto;margin-right:12px;flex-wrap:wrap">
        <button class="btn btn-sm dash-rng-btn active" id="btn-prev-desk" onclick="switchPreviewDevice('desktop')">🖥️ Desktop</button>
        <button class="btn btn-sm dash-rng-btn" id="btn-prev-mob" onclick="switchPreviewDevice('mobile')">📱 Mobile</button>
        <button class="btn btn-sm dash-rng-btn" id="btn-prev-dark" onclick="switchPreviewDevice('dark')">🌙 Dark</button>
        <button class="btn btn-sm dash-rng-btn" id="btn-prev-light" onclick="switchPreviewDevice('light')">☀️ Light</button>
      </div>
      <span class="modal-x" onclick="closeModal('template-preview-modal')">✕</span>
    </div>
    <div class="modal-body" style="background:#04070d;padding:24px;display:flex;justify-content:center;min-height:480px">
      <div class="device-preview-box" id="device-preview-wrapper" style="width:100%;display:flex;justify-content:center">
        <iframe id="template-preview-iframe" class="device-frame-desktop" style="transition:all .25s ease;border-radius:12px"></iframe>
      </div>
    </div>
  </div>
</div>

<!-- ══ INSERT / EDIT LINK MODAL ══ -->
<div class="modal-bg" id="rte-link-modal">
  <div class="modal" style="max-width:440px">
    <div class="modal-hd"><h3>🔗 Insert / Edit Link</h3><span class="modal-x" onclick="closeModal('rte-link-modal')">✕</span></div>
    <div class="modal-body">
      <div id="rte-link-al" class="al"></div>
      <div class="fg"><label class="fl">Destination URL *</label><input class="fi" id="rte-link-url" placeholder="https://yourwebsite.com or mailto:you@domain.com"></div>
      <div class="fg"><label class="fl">Display Text</label><input class="fi" id="rte-link-text" placeholder="Click here"></div>
      <div class="fg" style="display:flex;align-items:center;gap:8px">
        <input type="checkbox" id="rte-link-blank" checked style="accent-color:var(--accent);width:16px;height:16px">
        <label for="rte-link-blank" style="font-size:12px;color:var(--text2);cursor:pointer">Open link in new tab</label>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-secondary" onclick="closeModal('rte-link-modal')">Cancel</button>
      <button class="btn btn-primary" onclick="rteApplyLink()">Insert Link</button>
    </div>
  </div>
</div>

<!-- FOLLOW-UP CONTACTS MODAL -->
<div class="modal-bg" id="fu-contacts-modal">
  <div class="modal modal-lg">
    <div class="modal-hd"><h3 id="fu-contacts-title">👥 Contacts</h3><span class="modal-x" onclick="closeModal('fu-contacts-modal')">✕</span></div>
    <div class="modal-body">
      <div id="fu-contacts-al" class="al"></div>
      <div class="stitle">Enroll New Contacts</div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;align-items:flex-end">
        <div style="flex:1;min-width:200px">
          <label class="fl" style="font-size:11px">From Email List</label>
          <div style="display:flex;gap:8px">
            <select class="fsel" id="fu-enroll-list" style="flex:1"><option value="">— select list —</option></select>
            <button class="btn btn-primary btn-sm" onclick="fuEnrollList()">Enroll</button>
          </div>
        </div>
        <div>
          <label class="fl" style="font-size:11px">Upload CSV</label>
          <label class="btn btn-secondary btn-sm" style="cursor:pointer">📤 CSV<input type="file" accept=".csv" onchange="fuEnrollCsv(this)" style="display:none"></label>
        </div>
      </div>
      <div class="stitle">Enrolled Contacts</div>
      <div class="tw"><table>
        <thead><tr><th>Email</th><th>Name</th><th>Step</th><th>Next Send</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody id="fu-contacts-body"><tr class="empty-row"><td colspan="6">Loading…</td></tr></tbody>
      </table></div>
      <div id="fu-contacts-pager" style="display:flex;gap:8px;align-items:center;justify-content:center;padding:10px 0"></div>
    </div>
  </div>
</div>

<!-- FOLLOW-UP LOGS MODAL -->
<div class="modal-bg" id="fu-logs-modal">
  <div class="modal modal-lg">
    <div class="modal-hd"><h3 id="fu-logs-title">📋 Follow-Up Logs</h3><button class="btn btn-danger btn-sm" id="fu-logs-clear-btn" style="margin-left:auto;margin-right:12px;display:none" onclick="clearFuLogs()">🗑 Clear Logs</button><span class="modal-x" onclick="closeModal('fu-logs-modal')">✕</span></div>
    <div class="modal-body" style="padding:0"><div class="tw"><table>
      <thead><tr><th>Email</th><th>Step</th><th>Status</th><th>SMTP</th><th>Error</th><th>Time</th></tr></thead>
      <tbody id="fu-logs-body"><tr class="empty-row"><td colspan="6">Loading…</td></tr></tbody>
    </table></div></div>
  </div>
</div>

<!-- SMTP Modal -->
<div class="modal-bg" id="smtp-modal">
  <div class="modal">
    <div class="modal-hd"><h3 id="smtp-modal-title">🔌 Add SMTP</h3><span class="modal-x" onclick="closeModal('smtp-modal')">✕</span></div>
    <div class="modal-body">
      <div id="smtp-al" class="al"></div>
      <div class="fg"><label class="fl">Name *</label><input class="fi" id="sm-name" placeholder="e.g. SendGrid Main"></div>
      <div class="frow fc2">
        <div class="fg"><label class="fl">Host *</label><input class="fi" id="sm-host" placeholder="smtp.sendgrid.net"></div>
        <div class="fg"><label class="fl">Port</label><input class="fi" id="sm-port" type="number" value="587"></div>
      </div>
      <div class="frow fc2">
        <div class="fg"><label class="fl">Security</label>
          <select class="fsel" id="sm-secure"><option value="0">STARTTLS (587)</option><option value="1">SSL/TLS (465)</option></select>
        </div>
        <div class="fg"><label class="fl">Username</label><input class="fi" id="sm-user" placeholder="SMTP login"></div>
      </div>
      <div class="fg"><label class="fl">Password <span class="flh">(leave blank when editing to keep existing)</span></label><input class="fi" id="sm-pass" type="password"></div>
      <div class="frow fc2">
        <div class="fg"><label class="fl">From Email *</label><input class="fi" id="sm-from" placeholder="no-reply@example.com" oninput="smtpFromHint()"></div>
        <div class="fg"><label class="fl">From Name</label><input class="fi" id="sm-fname" placeholder="My Company"></div>
      </div>
      <div id="smtp-dns-hint" style="display:none;margin-top:8px;padding:10px 12px;background:var(--bg2,#f5f7ff);border:1px solid var(--border,#dde3f0);border-radius:6px;font-size:11.5px;line-height:1.7;">
        <strong>⚠️ Sender Verification — Required DNS Records</strong><br>
        Receiving mail servers may reject your emails with <em>"Sender verify failed"</em> if the domain used in <strong>From Email</strong> lacks proper DNS records. Ensure the following are set for <strong id="smtp-dns-domain" style="color:var(--accent,#4f6ef7)"></strong>:
        <br><br>
        <strong>1. SPF record</strong> (TXT on <code>@</code> / root domain)<br>
        Authorises your sending server's IP. Example:<br>
        <code style="display:block;background:var(--bg3,#eef0fa);padding:4px 8px;border-radius:4px;margin:3px 0">v=spf1 ip4:YOUR.SERVER.IP.HERE include:_spf.YOUR-SMTP-HOST.com ~all</code>
        <br>
        <strong>2. DKIM record</strong> (TXT on <code>mailszo._domainkey</code>)<br>
        Ask your SMTP provider for their DKIM public key value and add it as a TXT record.<br>
        <br>
        <strong>3. DMARC record</strong> (TXT on <code>_dmarc</code>)<br>
        <code style="display:block;background:var(--bg3,#eef0fa);padding:4px 8px;border-radius:4px;margin:3px 0">v=DMARC1; p=none; rua=mailto:postmaster@<span class="smtp-dns-dom-inline"></span></code>
        <br>
        <span style="color:#b45309">⚠️ Also ensure the mailbox <strong id="smtp-dns-email"></strong> actually exists on the mail server — some receivers do a callback check ("No Such User Here" means the address was not found).</span>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-secondary" onclick="testSmtpModal()">🔍 Test</button>
      <button class="btn btn-secondary" onclick="closeModal('smtp-modal')">Cancel</button>
      <button class="btn btn-primary" id="smtp-save-btn" onclick="saveSmtp()">Save SMTP</button>
    </div>
  </div>
</div>

<!-- LIST Modal -->
<div class="modal-bg" id="list-modal">
  <div class="modal">
    <div class="modal-hd"><h3>📥 Import Email List</h3><span class="modal-x" onclick="closeModal('list-modal')">✕</span></div>
    <div class="modal-body">
      <div id="list-al" class="al"></div>
      <div class="fg"><label class="fl">List Name *</label><input class="fi" id="lm-name" placeholder="My Subscriber List"></div>
      <div class="fg"><label class="fl">CSV File * <span class="flh">columns: email, name</span></label><input type="file" class="fi" id="lm-file" accept=".csv,.txt"></div>
      <div class="info-box">💡 CSV must have an <code>email</code> column. <code>name</code> column is optional. Header row is auto-detected.</div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-secondary" onclick="closeModal('list-modal')">Cancel</button>
      <button class="btn btn-primary" id="list-save-btn" onclick="saveList()">Import</button>
    </div>
  </div>
</div>

<!-- CAMPAIGN Modal -->
<div class="modal-bg" id="camp-modal">
  <div class="modal modal-xl">
    <div class="modal-hd"><h3 id="camp-modal-title">📤 New Campaign</h3><span class="modal-x" onclick="closeModal('camp-modal')">✕</span></div>
    <div class="modal-body">
      <div id="camp-al" class="al"></div>
      <div class="stitle">Campaign Settings</div>
      <div class="frow fc2" style="margin-bottom:12px">
        <div class="fg" style="margin:0"><label class="fl">Name *</label><input class="fi" id="cm-name" placeholder="Black Friday Campaign"></div>
        <div class="fg" style="margin:0"><label class="fl">Email List *</label><select class="fsel" id="cm-list"><option value="">— select —</option></select></div>
      </div>
      <div class="fg" style="margin-bottom:12px">
        <label class="fl">Sender Name <span class="flh">(shown as email sender)</span></label>
        <input class="fi" id="cm-sender-name" placeholder="e.g. John Smith, Marketing Team">
        <div class="fhint">Name the receiver sees in their inbox. If blank, uses your Display Name or SMTP default.</div>
      </div>
      <div class="frow fc3" style="margin-bottom:16px">
        <div class="fg" style="margin:0"><label class="fl">Rate / Minute</label><input class="fi" id="cm-rate" type="number" value="10" min="1" max="300"><div class="fhint">Emails per cron tick</div></div>
        <div class="fg" style="margin:0"><label class="fl">Daily Limit</label><input class="fi" id="cm-daily" type="number" value="500"></div>
        <div class="fg" style="margin:0">
          <label class="fl">Schedule <span class="flh">(blank=now)</span></label>
          <div style="display:flex;gap:4px">
            <input class="fi" id="cm-sched" type="datetime-local" style="flex:1">
            <button class="btn btn-secondary" type="button" onclick="document.getElementById('cm-sched').value=''" title="Clear schedule (Send now)" style="padding:0 10px">✕</button>
          </div>
        </div>
      </div>
      <div class="stitle">SMTP Pool — rotates per send</div>
      <div class="fg"><div class="smtp-pool" id="cm-smtp-pool"><div style="color:var(--text3);font-size:12px;padding:4px">Loading…</div></div></div>
      <div class="stitle">From Emails — random per send</div>
      <div class="fg">
        <div class="tags-wrap" id="cm-from-wrap" onclick="document.getElementById('cm-from-inp').focus()">
          <input class="tag-inp" id="cm-from-inp" placeholder="Name &lt;email&gt; or just email → Enter" onkeydown="fromKey(event)">
        </div>
        <div class="fhint">Add multiple → random one used per email. Format: <code>John &lt;john@co.com&gt;</code> or <code>john@co.com</code></div>
      </div>
      <div class="stitle">Message Variants — random one per recipient</div>
      <div class="al a-inf on" style="margin-bottom:10px;font-size:11px">✨ Add multiple variants. Each recipient gets a <strong>random</strong> one — different subject, body &amp; image. Use <code>{{NAME}}</code> <code>{{EMAIL}}</code> <code>{{IMAGE}}</code> <code>{{MODELNAME}}</code> <code>{{TODAYDATE}}</code> and spintax <code>{SPIN|TAX}</code></div>
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
        <button class="btn btn-blue btn-sm" onclick="addVariant()">+ Add Variant</button>
        <span style="font-size:11px;color:var(--text3)" id="vc-label">1 variant</span>
      </div>
      <div class="vtabs" id="vtabs"></div>
      <div class="vpanes" id="vpanes"></div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-amber" onclick="openTestModal()">✉ Test Send</button>
      <button class="btn btn-secondary" onclick="closeModal('camp-modal')">Cancel</button>
      <button class="btn btn-primary" id="camp-save-btn" onclick="saveCamp()">💾 Save Campaign</button>
    </div>
  </div>
</div>

<!-- TEST SEND Modal -->
<div class="modal-bg" id="test-modal">
  <div class="modal" style="max-width:400px">
    <div class="modal-hd"><h3>✉ Test Send</h3><span class="modal-x" onclick="closeModal('test-modal')">✕</span></div>
    <div class="modal-body">
      <div id="test-al" class="al"></div>
      <div class="fg"><label class="fl">Send Test To</label><input class="fi" id="test-email" type="email" placeholder="you@example.com"></div>
      <div class="info-box">A <strong>random variant</strong> will be sent. Save the campaign first.</div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-secondary" onclick="closeModal('test-modal')">Cancel</button>
      <button class="btn btn-primary" id="test-btn" onclick="doTest()">Send Test</button>
    </div>
  </div>
</div>

<!-- CAMPAIGN LOGS Modal -->
<div class="modal-bg" id="clogs-modal">
  <div class="modal modal-lg">
    <div class="modal-hd"><h3 id="clogs-title">📋 Logs</h3><span class="modal-x" onclick="closeModal('clogs-modal')">✕</span></div>
    <div class="modal-body" style="padding:0"><div class="tw"><table>
      <thead><tr><th>Email</th><th>Status</th><th>SMTP</th><th>From</th><th>Variant</th><th>Error</th><th>Time</th></tr></thead>
      <tbody id="clogs-body"><tr class="empty-row"><td colspan="7">Loading…</td></tr></tbody>
    </table></div></div>
  </div>
</div>

<!-- USER Modal (admin) -->
<div class="modal-bg" id="user-modal">
  <div class="modal">
    <div class="modal-hd"><h3 id="user-modal-title">👤 Create User</h3><span class="modal-x" onclick="closeModal('user-modal')">✕</span></div>
    <div class="modal-body">
      <div id="user-al" class="al"></div>

      <div class="stitle">Credentials</div>
      <div class="frow fc2">
        <div class="fg"><label class="fl">Username *</label><input class="fi" id="um-user" placeholder="johndoe"></div>
        <div class="fg"><label class="fl">Password <span class="flh" id="um-pw-hint">(required)</span></label><input class="fi" id="um-pass" type="password" placeholder="min 6 chars"></div>
      </div>

      <div class="stitle">Role &amp; Status</div>
      <div class="frow fc2">
        <div class="fg"><label class="fl">Role</label>
          <select class="fsel" id="um-role"><option value="0">👤 Regular User</option><option value="1">⚡ Admin</option></select>
        </div>
        <div class="fg"><label class="fl">Status</label>
          <select class="fsel" id="um-status"><option value="active">✅ Active</option><option value="suspended">🚫 Suspended</option></select>
        </div>
      </div>

      <div class="stitle">Usage Limits</div>
      <div class="frow fc3">
        <div class="fg">
          <label class="fl">SMTP Limit</label>
          <input class="fi" id="um-smtp" type="number" value="5" min="0">
          <div class="fhint">Max SMTP servers (0=disabled)</div>
        </div>
        <div class="fg">
          <label class="fl">Campaign Limit</label>
          <input class="fi" id="um-camp" type="number" value="10" min="0">
          <div class="fhint">Max campaigns (0=disabled)</div>
        </div>
        <div class="fg">
          <label class="fl">Daily IMAP Limit</label>
          <input class="fi" id="um-daily" type="number" value="1000" min="0">
          <div class="fhint">Max leads the IMAP server is allowed to read & process per day</div>
        </div>
      </div>
      <!-- Per-user Auto-Reply + Follow-Up MESSAGE caps. The cap counts
           individual reply / follow-up messages (steps) configured across
           all of the user's rules — when reached, save is blocked. Same
           0=disabled convention as the other limits. Enforced inside
           checkUserLimit() in includes/config.php. -->
      <div class="frow fc2">
        <div class="fg">
          <label class="fl">Auto Reply Message Limit</label>
          <input class="fi" id="um-arlimit" type="number" value="5" min="0">
          <div class="fhint">Max auto-reply messages this user can configure across all rules (0=disabled)</div>
        </div>
        <div class="fg">
          <label class="fl">Follow-Up Message Limit</label>
          <input class="fi" id="um-fulimit" type="number" value="5" min="0">
          <div class="fhint">Max follow-up messages this user can configure across all rules (0=disabled)</div>
        </div>
      </div>

      <div class="frow fc2" style="margin-top:8px">
        <div class="fg">
          <label class="fl">IMAP Read Limit <span class="flh">(emails/minute)</span></label>
          <input class="fi" id="um-imap-read-limit" type="number" value="0" min="0">
          <div class="fhint">Max IMAP emails this user's accounts can read per cron run (0 = use global setting)</div>
        </div>
      </div>

      <div class="stitle">Feature Access</div>
      <div class="frow fc2">
        <div class="fg">
          <label class="fl">Image Upload</label>
          <select class="fsel" id="um-imgupload">
            <option value="1">✅ Enabled</option>
            <option value="0">🚫 Disabled</option>
          </select>
          <div class="fhint">Allow this user to upload images (Image Library & Variant Picker)</div>
        </div>
      </div>

      <div class="stitle">Account Expiry</div>
      <div class="fg">
        <label class="fl">Expires At <span class="flh">(leave blank = never expires)</span></label>
        <input class="fi" id="um-exp" type="datetime-local">
        <div class="fhint">User cannot login after this date. Perfect for subscription-based access.</div>
      </div>

      <!-- Admin SMTP/IMAP Assignment — only visible when editing existing users -->
      <div id="um-assignment-section" style="display:none">
        <div class="stitle" style="color:var(--accent);margin-top:18px">🔌 Assign SMTP Servers <span style="font-size:10px;font-weight:400;color:var(--text3)">(from your admin account — shared to user's Auto-Reply &amp; Follow-Up)</span></div>
        <div id="um-al-smtp" class="al" style="margin-bottom:8px"></div>
        <div style="background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:10px;max-height:180px;overflow-y:auto" id="um-smtp-assign-pool">
          <div style="color:var(--text3);font-size:12px">Loading SMTP servers…</div>
        </div>
        <div class="fhint" style="margin-top:6px">Check the SMTP servers from your admin account to share with this user. These will appear in their Auto-Reply and Follow-Up rules. The user's own SMTP servers are managed by the user themselves.</div>

        <div class="stitle" style="color:var(--accent2);margin-top:18px">📥 Assign IMAP Accounts <span style="font-size:10px;font-weight:400;color:var(--text3)">(from your admin account — shared to user's Auto-Reply &amp; Follow-Up)</span></div>
        <div id="um-al-imap" class="al" style="margin-bottom:8px"></div>
        <div style="background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:10px;max-height:180px;overflow-y:auto" id="um-imap-assign-pool">
          <div style="color:var(--text3);font-size:12px">Loading IMAP accounts…</div>
        </div>
        <div class="fhint" style="margin-top:6px">Check the IMAP accounts from your admin account to share with this user. These will appear in their Auto-Reply and Follow-Up rules. The user's own IMAP accounts are managed by the user themselves.</div>
        <button class="btn btn-blue btn-sm" style="margin-top:10px" onclick="saveUserAssignments()">💾 Save Assignments</button>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-secondary" onclick="closeModal('user-modal')">Cancel</button>
      <button class="btn btn-primary" id="user-save-btn" onclick="saveUser()">💾 Save User</button>
    </div>
  </div>
</div>

<!-- CLEAR DASHBOARD Modal (Admin only) -->
<div class="modal-bg" id="clear-dash-modal">
  <div class="modal" style="max-width:500px">
    <div class="modal-hd" style="border-bottom:2px solid rgba(248,113,113,.4)">
      <h3 style="color:var(--red)">🗑 Clear Dashboard</h3>
      <span class="modal-x" onclick="closeModal('clear-dash-modal')">✕</span>
    </div>
    <div class="modal-body">
      <div id="clear-dash-al" class="al"></div>

      <!-- User selector for user-wise dashboard clearing -->
      <div class="fg" style="margin-bottom:14px">
        <label class="fl">Target User Account <span style="font-size:10px;font-weight:400;color:var(--text3)">(select a specific user or clear globally)</span></label>
        <select class="fsel" id="clear-dash-user-select" onchange="onClearDashUserChange()">
          <option value="0">— All Users (System-wide) —</option>
        </select>
      </div>

      <!-- Warning box -->
      <div style="background:rgba(248,113,113,.07);border:1px solid rgba(248,113,113,.3);border-radius:10px;padding:18px;margin-bottom:18px">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px">
          <span style="font-size:32px">⚠️</span>
          <div>
            <div style="font-size:15px;font-weight:700;color:var(--red)" id="clear-dash-warn-title">Full Dashboard Reset</div>
            <div style="font-size:11px;color:var(--text2);margin-top:3px" id="clear-dash-warn-subtitle">This affects ALL users system-wide</div>
          </div>
        </div>
        <div style="font-size:12px;color:var(--text2);line-height:2">
          The following will be <strong style="color:var(--text)">completely wiped &amp; reset to 0</strong>:
          <div style="margin-top:10px;display:grid;gap:7px">
            <div style="display:flex;align-items:center;gap:9px;background:rgba(0,0,0,.25);border-radius:6px;padding:8px 12px">
              <span>👥</span><span><strong>Total Leads &amp; Subscribers in lists</strong> — Wiped to 0</span>
            </div>
            <div style="display:flex;align-items:center;gap:9px;background:rgba(0,0,0,.25);border-radius:6px;padding:8px 12px">
              <span>📊</span><span>Campaign <strong>send logs</strong> — Sent &amp; Failed counters → 0</span>
            </div>
            <div style="display:flex;align-items:center;gap:9px;background:rgba(0,0,0,.25);border-radius:6px;padding:8px 12px">
              <span>🔁</span><span><strong>Auto-reply threads &amp; logs</strong> — Cleared to 0</span>
            </div>
            <div style="display:flex;align-items:center;gap:9px;background:rgba(0,0,0,.25);border-radius:6px;padding:8px 12px">
              <span>📬</span><span><strong>Follow-up contacts &amp; queue</strong> — Cleared to 0</span>
            </div>
            <div style="display:flex;align-items:center;gap:9px;background:rgba(0,0,0,.25);border-radius:6px;padding:8px 12px">
              <span>📨</span><span><strong>IMAP read counters &amp; inbound leads</strong> — Reset to 0</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Safe zone note -->
      <div style="background:rgba(74,222,128,.05);border:1px solid rgba(74,222,128,.2);border-radius:8px;padding:12px 14px;font-size:12px;color:var(--text2);line-height:1.8;margin-bottom:16px">
        ✅ <strong style="color:var(--accent)">Configurations kept:</strong> Campaigns, SMTP servers, IMAP accounts, auto-reply rules, and templates remain <strong>intact</strong> for future runs.
      </div>

      <!-- Typed confirmation -->
      <div class="fg">
        <label class="fl">Type <span style="color:var(--red);font-family:var(--mono)">CLEAR</span> to confirm</label>
        <input class="fi" id="clear-dash-confirm-input" placeholder="Type CLEAR here…" oninput="onClearDashInput()" autocomplete="off" spellcheck="false">
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-secondary" onclick="closeModal('clear-dash-modal')">Cancel</button>
      <button class="btn btn-danger" id="clear-dash-confirm-btn" onclick="confirmClearDashboard()" disabled style="opacity:.4">🗑 Clear Dashboard Data</button>
    </div>
  </div>
</div>

<!-- RESET USER STATS Modal -->
<div class="modal-bg" id="reset-stats-modal">
  <div class="modal" style="max-width:460px">
    <div class="modal-hd" style="border-bottom:2px solid rgba(34,211,238,.3)">
      <h3 style="color:var(--accent2)">🔄 Reset User Stats</h3>
      <span class="modal-x" onclick="closeModal('reset-stats-modal')">✕</span>
    </div>
    <div class="modal-body">
      <div id="reset-stats-al" class="al"></div>
      <div style="background:rgba(34,211,238,.05);border:1px solid rgba(34,211,238,.2);border-radius:9px;padding:16px;margin-bottom:16px">
        <div style="display:flex;align-items:center;gap:11px;margin-bottom:12px">
          <span style="font-size:28px">⚠️</span>
          <div>
            <div style="font-size:14px;font-weight:700;color:var(--text)">Confirm Stats Reset</div>
            <div style="font-size:11px;color:var(--text2);margin-top:3px">This action will reset today's activity for:</div>
          </div>
        </div>
        <div style="background:var(--bg3);border-radius:7px;padding:12px 14px;font-size:13px;font-weight:700;color:var(--accent2);font-family:var(--mono)" id="reset-stats-username">—</div>
      </div>
      <div style="font-size:12px;color:var(--text2);line-height:1.9;margin-bottom:14px">
        The following will be <strong style="color:var(--text)">cleared for today only</strong>:
        <ul style="margin:8px 0 0 16px;color:var(--text2)">
          <li>📊 Dashboard send statistics (today)</li>
          <li>📬 Auto-reply log counts (today)</li>
          <li>📅 Follow-up log counts (today)</li>
          <li>📈 Daily usage counter — restored to limit: <strong id="reset-stats-limit" style="color:var(--accent2);font-family:var(--mono)">1000</strong>/day</li>
        </ul>
      </div>
      <div style="background:rgba(245,158,11,.06);border:1px solid rgba(245,158,11,.2);border-radius:7px;padding:10px 13px;font-size:11px;color:var(--accent3)">
        ⚡ <strong>Note:</strong> This only resets today's counters. All campaigns, SMTP servers, lists and rules remain intact.
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-secondary" onclick="closeModal('reset-stats-modal')">Cancel</button>
      <button class="btn btn-blue" id="reset-stats-confirm-btn" onclick="confirmResetStats()">🔄 Yes, Reset Stats</button>
    </div>
  </div>
</div>
<div class="modal-bg" id="imgpick-modal">
  <div class="modal modal-lg">
    <div class="modal-hd"><h3>🖼️ Pick Images for Variant</h3><span class="modal-x" onclick="closeModal('imgpick-modal')">✕</span></div>
    <div class="modal-body">
      <div class="info-box" style="margin-bottom:12px">Select one or more images. Each email will receive a <strong>random one</strong> from your selection.</div>
      <label class="upload-zone" id="imgpick-upload-zone">📤 Upload new images (click or drag)<input type="file" accept="image/*" multiple onchange="uploadImgs(this,false)" style="display:none"></label>
      <div id="imgpick-al" class="al"></div>
      <div class="img-grid" id="imgpick-grid"></div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-secondary" onclick="closeModal('imgpick-modal')">Cancel</button>
      <button class="btn btn-primary" onclick="confirmPick()">✓ Use Selected (<span id="pick-count">0</span>)</button>
    </div>
  </div>
</div>

<!-- Duplicate Modal -->
<div class="modal-bg" id="dup-modal">
  <div class="modal" style="max-width:400px">
    <div class="modal-hd">
      <h3>📋 Duplicate Campaign</h3>
      <span class="modal-x" onclick="closeModal('dup-modal')">✕</span>
    </div>
    <div class="modal-body">
      <div class="fg">
        <label class="fl">New Campaign Name</label>
        <input type="text" class="fi" id="dup-name">
      </div>
      <div class="fg" id="dup-user-row" style="display:none">
        <label class="fl">Assign to User <small style="color:var(--text3)">(Admin Only)</small></label>
        <select class="fsel" id="dup-user"></select>
      </div>
    </div>
    <div class="modal-foot" style="text-align:right">
      <button class="btn btn-secondary" onclick="closeModal('dup-modal')">Cancel</button>
      <button class="btn btn-primary" onclick="confirmDup()">Create Copy</button>
    </div>
  </div>
</div>

<script>
/* ─── State ─────────────────────────────── */
let S={loggedIn:false,username:'',isAdmin:false};
let allSmtps=[],allLists=[],allImages=[],allCamps=[];
let smtpEid=null,campEid=null,userEid=null;
let variants=[],activeV=0;
let pickTarget=null,pickSel=[];
let _liveRefreshTimer=null;
/* API() splits resource path from its query string so the final URL is always valid.
   It also ensures the correct base path is used even if the URL lacks a trailing slash. */
const API = r => {
  const i = r.indexOf('?');
  return i < 0 ? 'api.php?r=' + r : 'api.php?r=' + r.slice(0, i) + '&' + r.slice(i + 1);
};

/* ─── Boot ──────────────────────────────── */
async function boot(){
  const urlParams = new URLSearchParams(window.location.search);
  if (urlParams.has('logged_out')) {
    showLoginScreen();
    window.history.replaceState({}, document.title, window.location.pathname);
    return;
  }
  try {
    const r=await get('auth/me');
    if(r&&r.installed===false){window.location.href='install.php';return;}
    if(r&&r.loggedIn){
      enter(r);
    } else {
      showLoginScreen();
    }
  } catch(e) {
    showLoginScreen();
  }
}

function showLoginScreen(){
  S={loggedIn:false,username:'',isAdmin:false};
  if(_liveRefreshTimer){clearInterval(_liveRefreshTimer);_liveRefreshTimer=null;}
  const lw=document.getElementById('login-wrap');
  if(lw) lw.style.display='flex';
  const main=document.getElementById('main');
  if(main) main.style.display='none';
  const sb=document.getElementById('sidebar');
  if(sb) sb.style.display='none';
}

function enter(r){
  S={loggedIn:true,uid:r.uid?Number(r.uid):0,username:r.username,isAdmin:(r.is_admin==true||r.is_admin==='1'||r.is_admin===1),imageUpload:(r.image_upload!==false&&r.image_upload!==0&&r.image_upload!=='0')};
  document.getElementById('login-wrap').style.display='none';
  const main=document.getElementById('main'); if(main) main.style.display='';
  const sb=document.getElementById('sidebar'); if(sb) sb.style.display='';
  $('sb-uname').textContent=r.username;
  $('sb-av').textContent=r.username[0].toUpperCase();
  $('sb-role').textContent=S.isAdmin?'⚡ Administrator':'👤 User';
  if(S.isAdmin){
    $('admin-nav').style.display='block';
    $('sc-users').style.display='block';
    $('sc-smtps').style.display='block';
    const qb=$('admin-quick-bar');
    if(qb){qb.style.display='flex';}
    // Show admin dashboard header
    const adh=$('dash-admin-header'); if(adh) adh.style.display='flex';
    
    // Admin only SMTP visibility
    const navSmtp=$('nav-smtp'); if(navSmtp) navSmtp.style.display='flex';
    const smtpAddBtn=$('smtp-add-btn'); if(smtpAddBtn) smtpAddBtn.style.display='';
  } else {
    // Show user dashboard header
    const udh=$('dash-user-header'); if(udh) udh.style.display='flex';
    // Show user account info bar
    const uib=$('dash-user-info-bar'); if(uib) uib.style.display='block';
    // Note: "Pending Replies" is now in the Auto-Reply section by default
    // (visible to both admin and user) — no toggle needed here anymore.
    // (visible to both admin and user) — no toggle needed here anymore.

  }
  // All users can see IMAP nav items and add their own
  const navImap=$('nav-imap'); if(navImap) navImap.style.display='flex';
  const imapAddBtn=$('imap-add-btn'); if(imapAddBtn) imapAddBtn.style.display='';
  // Image upload guard — hide upload UI if user doesn't have permission
  const canUploadImg = S.isAdmin || S.imageUpload;
  const imgLibBtn=$('img-lib-upload-btn'); if(imgLibBtn) imgLibBtn.style.display=canUploadImg?'':'none';
  const imgPickZone=$('imgpick-upload-zone'); if(imgPickZone) imgPickZone.style.display=canUploadImg?'':'none';
  // Load the inline live reporting dashboard after session is confirmed.
  loadLiveDash();
  // Sync the date-range picker UI to whatever was persisted last session
  // before we start the loaders, so the buttons reflect the active filter
  // immediately and queries go out with the correct range from the start.
  if (typeof refreshRangeButtons === 'function') refreshRangeButtons();
  // Always land on Dashboard with Main Campaign Statistics visible at the top
  nav('dashboard');
  // Scroll to top so Main Campaign Statistics is the very first thing seen
  window.scrollTo({top:0,behavior:'instant'});
  const pageEl=document.getElementById('page-dashboard');
  if(pageEl) pageEl.scrollTop=0;
  loadDash();loadSmtps();loadLists();loadImages();
  // Step-by-step reports populate alongside the stat cards on first paint.
  loadStepReport('ar', 1);
  loadStepReport('fu', 1);
  // Step-wise live message report (AR + FU, steps 1..15).
  loadStepSummary();
  // Start live refresh for dashboard (cards + both step reports).
  if(_liveRefreshTimer){clearInterval(_liveRefreshTimer);_liveRefreshTimer=null;}
  _liveRefreshTimer=setInterval(()=>{
    if(!document.getElementById('page-dashboard')?.classList.contains('active')) return;
    loadDash();
    loadLiveDash();
    loadStepSummary();
    loadStepReport('ar', stepRepState.ar.page);
    loadStepReport('fu', stepRepState.fu.page);
  },15000);
  showLiveIndicator('dash-live');
}

/* ─── Dashboard Reset ───────────────────── */
function resetLiveDashboard(){
  loadLiveDash();
}

/* ─── Unified Live Reporting Dashboard ──────────────────────────
   Fetches dashboard.php?api=1 and renders into the merged dashboard
   surface (charts, ratios, activity feed). Shares the same 15s loop
   as loadDash() which populates the KPI cards. ─────────────────── */
let _lrdChartHourly = null;
let _lrdChartDaily  = null;

function _lrdEsc(s){ return s==null?'—':String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function _lrdFmtDT(dt){
  if(!dt) return '—';
  try{ const d=new Date(String(dt).replace(' ','T')); return d.toLocaleString([],{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}); }catch(e){return dt;}
}
function _lrdAnim(el,val){
  if(!el)return;
  const cur=parseInt(el.textContent)||0;
  const target=Number.isFinite(+val)?+val:0;
  if(cur===target){el.textContent=target;return;}
  const diff=target-cur,steps=18;let step=0;
  const t=setInterval(()=>{step++;el.textContent=Math.round(cur+diff*(step/steps));if(step>=steps){el.textContent=target;clearInterval(t);}},18);
}
/* Invokes `fn` once Chart.js is available. If Chart is already loaded
   runs immediately; otherwise polls every 100ms for up to 5s. Used by
   every chart renderer so an empty canvas never sits around waiting
   for the next 15s poll just because Chart.js loaded a tick late. */
function _whenChartReady(fn){
  if(typeof Chart!=='undefined'){ try{ fn(); }catch(e){ console.error(e); } return; }
  let tries=0;
  const id=setInterval(()=>{
    tries++;
    if(typeof Chart!=='undefined'){ clearInterval(id); try{ fn(); }catch(e){ console.error(e); } }
    else if(tries>50){
      clearInterval(id);
      console.warn('Chart.js failed to load after 5s');
      // Surface the failure on every chart container so the user is
      // never left staring at a black box. Looks for the standard
      // `.lrd-chart-fallback` overlay we paint at boot.
      document.querySelectorAll('.lrd-chart-fallback').forEach(el=>{
        el.textContent='⚠ Could not load Chart.js — check network/CDN access';
        el.style.color='var(--red)';
      });
    }
  },100);
}
/* Hide the "Loading chart…" overlay once a chart has actually been
   drawn. Looks for a sibling .lrd-chart-fallback inside the canvas's
   parent (the relatively-positioned wrapper around the canvas). */
function _chartHideFallback(canvas){
  const fb=canvas && canvas.parentElement && canvas.parentElement.querySelector('.lrd-chart-fallback');
  if(fb) fb.remove();
}
/* On boot, drop a "Loading chart…" message into every canvas wrapper
   so users see immediate feedback (no big black void) while Chart.js
   loads and the first poll completes. Each fallback removes itself
   once its chart finishes rendering. */
document.addEventListener('DOMContentLoaded',()=>{
  document.querySelectorAll('#lrd-chart-hourly, #lrd-chart-daily, #step-chart').forEach(c=>{
    const parent=c.parentElement; if(!parent) return;
    if(parent.querySelector('.lrd-chart-fallback')) return;
    const fb=document.createElement('div');
    fb.className='lrd-chart-fallback';
    fb.textContent='⟳ Loading chart…';
    parent.appendChild(fb);
  });
});

async function loadLiveDash(){
  try{
    const res=await fetch('dashboard.php?api=1&_='+Date.now(),{credentials:'same-origin'});
    if(!res.ok) throw new Error('HTTP '+res.status);
    const d=await res.json();
    if(d.error==='session_expired'){ showLoginScreen(); return; }
    if(!d.ok) throw new Error('API error');

    // ── Ratios (AR completion / FU completion / overall reply) ──
    const arSent  = +d.total_autoreply_sent || 0;
    const arDone  = (d.completed_replies||[]).length || 0;
    const fuSent  = +d.followup_sent || 0;
    const fuDone  = +d.followup_completed || 0;
    const arPct   = arSent>0?Math.round(arDone/arSent*100):0;
    const fuPct   = fuSent>0?Math.round(fuDone/fuSent*100):0;
    const grand   = arSent + fuSent;

    const setText=(id,v)=>{const e=$(id);if(e)e.textContent=v;};
    const setBar =(id,p)=>{const e=$(id);if(e)e.style.width=p+'%';};

    setText('lrd-ratio-ar',  arPct+'%');
    setText('lrd-ratio-fu',  fuPct+'%');
    setBar ('lrd-bar-ar',    arPct);
    setBar ('lrd-bar-fu',    fuPct);
    setText('lrd-ratio-ar-sub', arDone+' completed of '+arSent+' sent');
    setText('lrd-ratio-fu-sub', fuDone+' completed of '+fuSent+' sent');
    _lrdAnim($('lrd-total'), grand);
    setText('lrd-total-sub', arSent+' auto-replies + '+fuSent+' follow-ups');

    // ── Hourly chart (AR + FU + combined sends) ─────────────────
    const hEl=document.getElementById('lrd-chart-hourly');
    if(hEl) _whenChartReady(()=>{
      _chartHideFallback(hEl);
      const combinedHourly=(d.hourly_autoreply||[]).map((v,i)=>(+v||0)+(+((d.hourly_followup||[])[i])||0));
      const tt={backgroundColor:'rgba(4,6,12,.95)',borderColor:'rgba(0,255,198,.2)',borderWidth:1,titleColor:'#00ffc6',bodyColor:'#c8d8f0',titleFont:{family:'IBM Plex Mono',size:10},bodyFont:{family:'IBM Plex Mono',size:10}};
      if(!_lrdChartHourly){
        const ctx=hEl.getContext('2d');
        const gAR=ctx.createLinearGradient(0,0,0,180); gAR.addColorStop(0,'rgba(0,255,198,.22)'); gAR.addColorStop(1,'rgba(0,255,198,0)');
        const gFU=ctx.createLinearGradient(0,0,0,180); gFU.addColorStop(0,'rgba(56,189,248,.18)'); gFU.addColorStop(1,'rgba(56,189,248,0)');
        _lrdChartHourly=new Chart(ctx,{
          type:'line',
          data:{labels:Array.from({length:24},(_,i)=>`${String(i).padStart(2,'0')}:00`),datasets:[
            {label:'Auto-Reply',data:d.hourly_autoreply||Array(24).fill(0),borderColor:'rgb(0,255,198)',backgroundColor:gAR,borderWidth:2,fill:true,tension:.4,pointRadius:0,pointHoverRadius:5},
            {label:'Follow-up', data:d.hourly_followup ||Array(24).fill(0),borderColor:'rgb(56,189,248)',backgroundColor:gFU,borderWidth:2,fill:true,tension:.4,pointRadius:0,pointHoverRadius:5},
            {label:'All sends', data:combinedHourly,borderColor:'rgb(251,146,60)',backgroundColor:'rgba(251,146,60,0)',borderWidth:2,borderDash:[4,3],fill:false,tension:.4,pointRadius:0,pointHoverRadius:5}
          ]},
          options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:tt},scales:{x:{ticks:{color:'#263852',font:{family:'IBM Plex Mono',size:9},maxTicksLimit:12},grid:{color:'rgba(26,40,64,.5)'}},y:{beginAtZero:true,ticks:{color:'#263852',font:{family:'IBM Plex Mono',size:9}},grid:{color:'rgba(26,40,64,.5)'}}},animation:{duration:500}}
        });
      } else {
        _lrdChartHourly.data.datasets[0].data=d.hourly_autoreply||Array(24).fill(0);
        _lrdChartHourly.data.datasets[1].data=d.hourly_followup ||Array(24).fill(0);
        _lrdChartHourly.data.datasets[2].data=combinedHourly;
        _lrdChartHourly.update();
      }
    });

    // ── Activity feed ───────────────────────────────────────────
    const combined=[];
    (d.completed_replies||[]).forEach(r=>combined.push({type:'ar',email:r.from_email,rule:r.rule_name,steps:r.reply_count||0,ts:r.last_sent_at}));
    (d.followup_completed_list||[]).forEach(r=>combined.push({type:'fu',email:r.email,rule:r.rule_name,steps:'—',ts:r.completed_at||r.last_sent_at}));
    combined.sort((a,b)=>new Date(b.ts)-new Date(a.ts));
    const top=combined.slice(0,50);
    setText('lrd-feed-count', top.length+' entries');
    const tb=$('lrd-feed-body');
    if(tb){
      if(!top.length){
        tb.innerHTML='<tr><td colspan="5" class="lrd-feed-empty">No completions yet today</td></tr>';
      } else {
        tb.innerHTML=top.map(r=>{
          const badge=r.type==='ar'
            ?'<span class="lrd-type-ar">🔁 AR</span>'
            :'<span class="lrd-type-fu">📬 FU</span>';
          const stepCell=r.type==='ar'
            ?`<span style="display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;font-family:var(--mono);font-size:10px;font-weight:700;background:rgba(56,189,248,.08);border:1px solid rgba(56,189,248,.2);color:var(--blue)">${_lrdEsc(r.steps)}</span>`
            :'<span style="color:var(--text3)">—</span>';
          return `<tr>
            <td>${badge}</td>
            <td style="color:var(--text);font-weight:600">${_lrdEsc(r.email)}</td>
            <td style="color:var(--text3)">${_lrdEsc(r.rule)}</td>
            <td style="text-align:center">${stepCell}</td>
            <td style="color:var(--text2)">${_lrdFmtDT(r.ts)}</td>
          </tr>`;
        }).join('');
      }
    }

    // ── Timestamp + next reset (meta bar) ───────────────────────
    if(d.generated_at){
      try{ const ts=new Date(String(d.generated_at).replace(' ','T')).toLocaleTimeString();
           setText('lrd-ts', ts); }catch(e){}
    }
    const midnight=new Date(); midnight.setDate(midnight.getDate()+1); midnight.setHours(0,0,0,0);
    const diff=midnight-Date.now();
    setText('lrd-next-reset', `in ${String(Math.floor(diff/3600000)).padStart(2,'0')}:${String(Math.floor((diff%3600000)/60000)).padStart(2,'0')}:${String(Math.floor((diff%60000)/1000)).padStart(2,'0')}`);

  }catch(err){
    console.error('loadLiveDash error:',err);
  }
}

/* ─── Step-wise Real-Time Message Report ────────────────────────
   Single fetch against reports/step-summary, renders:
     • Summary KPI row (Sent / Pending / Completed)
     • Stacked horizontal bar chart per step (Chart.js)
     • Lane grid below the chart with click-to-drill detail
   Shares the 15s polling loop. Highlights active selection so the
   user can keep a step open while data refreshes. ───────────── */
let _stepChart = null;
let _stepData  = null;
let _stepSelected = null;

async function loadStepSummary(){
  try{
    const r = await get('reports/step-summary?max=15&_=' + Date.now());
    if(!r || !r.ok) return;
    _stepData = r;

    // ── Summary KPIs ────────────────────────────────────────────
    const sumS=$('step-sum-sent');      if(sumS) _lrdAnim(sumS, r.summary.total_sent);
    const sumP=$('step-sum-pending');   if(sumP) _lrdAnim(sumP, r.summary.total_pending);
    const sumC=$('step-sum-completed'); if(sumC) _lrdAnim(sumC, r.summary.total_completed);

    // ── Chart ───────────────────────────────────────────────────
    const el = document.getElementById('step-chart');
    if(el) _whenChartReady(()=>{
      _chartHideFallback(el);
      const labels = r.steps.map(s=>'Step '+s.step);
      const completed = r.steps.map(s=>s.total_completed);
      const pending   = r.steps.map(s=>s.total_pending);
      const sent      = r.steps.map(s=>s.total_sent);
      const tt = {
        backgroundColor:'rgba(4,6,12,.95)',borderColor:'rgba(0,255,198,.2)',borderWidth:1,
        titleColor:'#00ffc6',bodyColor:'#c8d8f0',
        titleFont:{family:'IBM Plex Mono',size:10},bodyFont:{family:'IBM Plex Mono',size:11},
        callbacks:{
          afterBody(items){
            const i = items[0].dataIndex;
            const s = r.steps[i];
            return ['',
              `AR: ${s.ar_sent} sent · ${s.ar_pending} pending · ${s.ar_completed} done`,
              `FU: ${s.fu_sent} sent · ${s.fu_pending} pending · ${s.fu_completed} done`,
              `Completion: ${s.completion_pct}%`];
          }
        }
      };
      if(!_stepChart){
        _stepChart = new Chart(el.getContext('2d'), {
          type:'bar',
          data:{labels, datasets:[
            {label:'Completed', data:completed, backgroundColor:'rgba(74,222,128,.85)',  borderColor:'rgb(74,222,128)',  borderWidth:1, stack:'s', borderRadius:3},
            {label:'Pending',   data:pending,   backgroundColor:'rgba(245,158,11,.85)',  borderColor:'rgb(245,158,11)',  borderWidth:1, stack:'s', borderRadius:3},
            {label:'Sent',      data:sent,      backgroundColor:'rgba(56,189,248,.85)',  borderColor:'rgb(56,189,248)',  borderWidth:1, stack:'s', borderRadius:3},
          ]},
          options:{
            responsive:true, maintainAspectRatio:false,
            plugins:{legend:{display:false}, tooltip:tt},
            scales:{
              x:{stacked:true, ticks:{color:'#7a92b8', font:{family:'IBM Plex Mono', size:9}}, grid:{display:false}},
              y:{stacked:true, beginAtZero:true, ticks:{color:'#7a92b8', font:{family:'IBM Plex Mono', size:9}}, grid:{color:'rgba(26,40,64,.4)'}}
            },
            onClick(evt, items){
              if(items && items.length){
                const i = items[0].index;
                stepDetailShow(r.steps[i].step);
              }
            },
            animation:{duration:400}
          }
        });
      } else {
        _stepChart.data.labels = labels;
        _stepChart.data.datasets[0].data = completed;
        _stepChart.data.datasets[1].data = pending;
        _stepChart.data.datasets[2].data = sent;
        _stepChart.update();
      }
    });

    // ── Lane grid (click to drill) ──────────────────────────────
    const grid = $('step-lane-grid');
    if(grid){
      grid.innerHTML = r.steps.map(s=>{
        const total = (s.total_completed||0) + (s.total_pending||0) + (s.total_sent||0);
        let gPct=0, aPct=0, bPct=0;
        if(total > 0){
          gPct = (s.total_completed/total)*100;
          aPct = (s.total_pending  /total)*100;
          bPct = (s.total_sent     /total)*100;
        }
        const empty = total === 0;
        const isSel = _stepSelected === s.step;
        return `<div class="step-lane ${empty?'step-lane-empty':''} ${isSel?'active':''}" data-step="${s.step}" onclick="stepDetailShow(${s.step})" title="Step ${s.step}: ${s.total_sent} sent · ${s.total_pending} pending · ${s.total_completed} completed">
          <div class="step-lane-num">Step <strong>${s.step}</strong></div>
          <div class="step-lane-bar">
            <div class="step-lane-seg green" style="width:${gPct}%"></div>
            <div class="step-lane-seg amber" style="width:${aPct}%"></div>
            <div class="step-lane-seg blue"  style="width:${bPct}%"></div>
          </div>
          <div class="step-lane-counts">
            <span class="c-blue"  title="Sent">${s.total_sent}</span>
            <span class="c-amber" title="Pending">${s.total_pending}</span>
            <span class="c-green" title="Completed">${s.total_completed}</span>
          </div>
          <div class="step-lane-pct">${s.completion_pct}%</div>
        </div>`;
      }).join('');
    }

    // If a step detail is currently open, refresh its content with
    // the new payload (real-time update without closing the drawer).
    if(_stepSelected){
      stepDetailRender(_stepSelected, true);
    }

    // Render the Follow-Up message flow funnel from the same payload.
    renderFollowUpFlow(r);
  }catch(err){
    console.error('loadStepSummary error:', err);
  }
}

/* ─── Follow-Up Message Flow ────────────────────────────────────
   Derives a contact-journey funnel from the step-summary payload.
   "Reached step N" = currently-at-step-N + already-past-step-N
                    = fu_pending[N] + fu_completed[N].
   Advance rate = reached[N+1] / reached[N], drop-off = reached[N]
   - reached[N+1]. The widget refreshes whenever loadStepSummary()
   completes, so it stays in sync with the live polling loop. ── */
function renderFollowUpFlow(payload){
  const steps = (payload && payload.steps) || [];
  if(!steps.length){
    const tb = document.getElementById('fu-flow-tbody');
    if(tb) tb.innerHTML='<tr><td colspan="7" class="fu-flow-empty">No follow-up data yet</td></tr>';
    return;
  }

  // ── Per-step derived metrics ────────────────────────────────
  const reached = steps.map(s => (s.fu_pending||0) + (s.fu_completed||0));
  const sent    = steps.map(s => (s.fu_sent||0));
  const here    = steps.map(s => (s.fu_pending||0));
  const done    = steps.map(s => (s.fu_completed||0));

  // ── Summary strip ───────────────────────────────────────────
  const entered = reached[0] || 0;                       // total who ever started
  const sentTot = sent.reduce((a,b)=>a+b,0);
  const inFlight= here.reduce((a,b)=>a+b,0);
  const finished= (steps[steps.length-1]?.fu_completed) || 0; // cleared the final step
  const conv    = entered > 0 ? (finished/entered*100) : 0;

  const setText=(id,v)=>{const e=document.getElementById(id);if(e) _lrdAnim(e, v);};
  setText('fu-flow-entered',  entered);
  setText('fu-flow-sent',     sentTot);
  setText('fu-flow-inflight', inFlight);
  setText('fu-flow-finished', finished);
  const convEl=document.getElementById('fu-flow-conv');
  if(convEl) convEl.textContent = conv.toFixed(1) + '%';

  // ── Funnel bars + connectors ────────────────────────────────
  const max = Math.max(1, ...reached);
  const funnel = document.getElementById('fu-flow-funnel');
  if(funnel){
    let html = '';
    for(let i=0;i<steps.length;i++){
      const s = steps[i];
      const r = reached[i];
      const widthPct = (r / max) * 100;
      const empty = r === 0;
      html += `<div class="fu-flow-step ${empty?'fu-flow-step-empty':''}" title="Step ${s.step}: ${r} reached · ${here[i]} here · ${done[i]} advanced">
        <div class="fu-flow-step-num">Step <strong>${s.step}</strong></div>
        <div class="fu-flow-step-val">${r}</div>
        <div class="fu-flow-step-bar" style="width:${Math.max(8, widthPct)}%"></div>
        <div class="fu-flow-step-meta">
          <span class="here" title="Currently waiting at this step">⏳ ${here[i]}</span>
          <span class="done" title="Advanced past this step">✓ ${done[i]}</span>
        </div>
      </div>`;
      if(i < steps.length-1){
        const next = reached[i+1] || 0;
        const advPct = r > 0 ? (next / r * 100) : 0;
        const drop = Math.max(0, r - next);
        html += `<div class="fu-flow-connector" title="${advPct.toFixed(1)}% advanced — ${drop} drop-off">
          <span class="pct">${advPct.toFixed(0)}%</span>
          ${drop>0?`<span class="drop">−${drop}</span>`:''}
        </div>`;
      }
    }
    funnel.innerHTML = html;
  }

  // ── Per-step table ──────────────────────────────────────────
  const tb = document.getElementById('fu-flow-tbody');
  if(tb){
    const rows = steps.map((s,i)=>{
      const r = reached[i];
      const next = reached[i+1] !== undefined ? reached[i+1] : null;
      const adv  = next !== null ? Math.max(0, next) : Math.max(0, done[i]);
      const drop = next !== null ? Math.max(0, r - next) : 0;
      const advPct = r > 0 ? (adv / r * 100) : 0;
      return `<tr>
        <td class="s">Step ${s.step}</td>
        <td>${r}</td>
        <td>${sent[i]}</td>
        <td><span style="color:var(--amber)">${here[i]}</span></td>
        <td class="adv"><strong>${adv}</strong></td>
        <td class="drop">${drop>0?`<strong>${drop}</strong>`:'<span style="color:var(--text3)">—</span>'}</td>
        <td>${r>0?advPct.toFixed(1)+'%':'<span style="color:var(--text3)">—</span>'}</td>
      </tr>`;
    }).join('');
    tb.innerHTML = rows;
  }
}

function stepDetailShow(n){
  _stepSelected = n;
  // Visual selection on lane cards
  document.querySelectorAll('.step-lane').forEach(el=>{
    el.classList.toggle('active', +el.dataset.step === n);
  });
  stepDetailRender(n, false);
}

function stepDetailRender(n, isRefresh){
  if(!_stepData) return;
  const row = (_stepData.steps||[]).find(s=>s.step===n);
  if(!row) return;
  const panel = $('step-detail-panel');
  const numEl = $('step-detail-num');
  const body  = $('step-detail-body');
  if(panel) panel.removeAttribute('hidden');
  if(numEl) numEl.textContent = n;
  if(body){
    body.innerHTML = `
      <div class="step-detail-col">
        <h4>🔁 Auto-Reply</h4>
        <div class="row"><span>Sent</span><strong style="color:var(--blue)">${row.ar_sent}</strong></div>
        <div class="row"><span>Pending</span><strong style="color:var(--amber)">${row.ar_pending}</strong></div>
        <div class="row"><span>Completed</span><strong style="color:var(--accent)">${row.ar_completed}</strong></div>
      </div>
      <div class="step-detail-col">
        <h4>📬 Follow-Up</h4>
        <div class="row"><span>Sent</span><strong style="color:var(--blue)">${row.fu_sent}</strong></div>
        <div class="row"><span>Pending</span><strong style="color:var(--amber)">${row.fu_pending}</strong></div>
        <div class="row"><span>Completed</span><strong style="color:var(--accent)">${row.fu_completed}</strong></div>
      </div>
      <div class="step-detail-col">
        <h4>🪜 Combined</h4>
        <div class="row"><span>Sent</span><strong style="color:var(--blue)">${row.total_sent}</strong></div>
        <div class="row"><span>Pending</span><strong style="color:var(--amber)">${row.total_pending}</strong></div>
        <div class="row"><span>Completed</span><strong style="color:var(--accent)">${row.total_completed}</strong></div>
        <div class="row" style="margin-top:6px;padding-top:6px;border-top:1px solid var(--border)"><span>Completion</span><strong style="color:var(--accent)">${row.completion_pct}%</strong></div>
      </div>`;
    if(!isRefresh){
      panel.scrollIntoView({behavior:'smooth', block:'nearest'});
    }
  }
}

function stepDetailClose(){
  _stepSelected = null;
  const p = $('step-detail-panel'); if(p) p.setAttribute('hidden','');
  document.querySelectorAll('.step-lane.active').forEach(el=>el.classList.remove('active'));
}

/* Renders the 14-day performance chart from data returned by
   campaigns/stats. Called from loadDash() when the chart payload is
   present so we only fetch one endpoint for the daily series. */
function lrdRenderDaily(stats){
  const el=document.getElementById('lrd-chart-daily');
  if(!el || !stats) return;
  _whenChartReady(()=>{
    _chartHideFallback(el);
    const labels=(stats.daily_labels||[]).map(d=>{
      if(!d) return '';
      const dt=new Date(d+'T00:00:00');
      return dt.toLocaleDateString([],{month:'short',day:'numeric'});
    });
    const data=stats.daily_sent||[];
    const tt={backgroundColor:'rgba(4,6,12,.95)',borderColor:'rgba(192,132,252,.25)',borderWidth:1,titleColor:'#c084fc',bodyColor:'#c8d8f0',titleFont:{family:'IBM Plex Mono',size:10},bodyFont:{family:'IBM Plex Mono',size:10}};
    if(!_lrdChartDaily){
      const ctx=el.getContext('2d');
      _lrdChartDaily=new Chart(ctx,{
        type:'bar',
        data:{labels,datasets:[{label:'Sent',data,backgroundColor:'rgba(192,132,252,.55)',borderColor:'rgb(192,132,252)',borderWidth:1,borderRadius:4}]},
        options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:tt},scales:{x:{ticks:{color:'#263852',font:{family:'IBM Plex Mono',size:9},maxTicksLimit:14},grid:{display:false}},y:{beginAtZero:true,ticks:{color:'#263852',font:{family:'IBM Plex Mono',size:9}},grid:{color:'rgba(26,40,64,.4)'}}},animation:{duration:500}}
      });
    } else {
      _lrdChartDaily.data.labels=labels;
      _lrdChartDaily.data.datasets[0].data=data;
      _lrdChartDaily.update();
    }
  });
}

/* ─── Step-by-Step Reporting (AR + FU) ───────────────────────────────
   Two independent tables with shared logic. Each kind ('ar' | 'fu') has
   its own DOM ids, but the JS state (filters, sort, current page) lives
   in a single per-kind object so we can call the same loader/exporter. */
const stepRepState = {
  ar: { sort: 'id,desc', page: 1, debTimer: null, lastQs: '' },
  fu: { sort: 'id,desc', page: 1, debTimer: null, lastQs: '' },
};
function stepRepDebounce(kind){
  const s = stepRepState[kind];
  clearTimeout(s.debTimer);
  s.debTimer = setTimeout(()=>loadStepReport(kind,1), 350);
}
function stepRepBuildQs(kind, extra){
  const $f  = id => document.getElementById(`${kind}-rep-${id}`);
  const qs  = new URLSearchParams();
  if ($f('q')?.value)      qs.set('q', $f('q').value.trim());
  if ($f('rule')?.value)   qs.set('rule_id', $f('rule').value);
  if ($f('status')?.value) qs.set('status', $f('status').value);
  if ($f('smtp')?.value)   qs.set('smtp', $f('smtp').value);
  if ($f('step')?.value)   qs.set('step', $f('step').value);
  // Per-table date inputs win when the user has set them explicitly. If
  // they're blank, fall back to the dashboard-wide range picker so the
  // step report tracks the same window as the stat cards above.
  const fEl = $f('from'), tEl = $f('to');
  if (fEl?.value || tEl?.value) {
    if (fEl?.value) qs.set('date_from', fEl.value);
    if (tEl?.value) qs.set('date_to',   tEl.value);
  } else if (typeof dashRange !== 'undefined' && dashRange.preset) {
    if (dashRange.preset === 'custom') {
      if (dashRange.from) qs.set('date_from', dashRange.from);
      if (dashRange.to)   qs.set('date_to',   dashRange.to);
    } else {
      qs.set('range', dashRange.preset);
    }
  }
  qs.set('sort', stepRepState[kind].sort);
  if (extra) for (const [k,v] of Object.entries(extra)) qs.set(k, v);
  return qs.toString();
}
function stepRepSort(kind, col){
  const s = stepRepState[kind];
  // Toggle direction on the same column, otherwise descending by default.
  const [curCol, curDir] = (s.sort||'id,desc').split(',');
  s.sort = curCol === col ? `${col},${curDir==='asc'?'desc':'asc'}` : `${col},desc`;
  // Update header indicator
  const tbl = document.getElementById(`${kind}-rep-table`);
  if (tbl) tbl.querySelectorAll('th.srt').forEach(th=>th.classList.remove('sa','sd'));
  if (tbl) {
    const head = Array.from(tbl.querySelectorAll('th.srt')).find(th=>th.getAttribute('onclick')?.includes(`'${col}'`));
    if (head) head.classList.add(s.sort.endsWith(',asc')?'sa':'sd');
  }
  loadStepReport(kind, 1);
}
function stepRepBadge(row){
  // Pick the dominant status badge for the right-most column.
  if (row.is_completed)    return '<span class="badge b-purple">✓ Completed</span>';
  if (row.is_failed && (row.failed_count||0) >= (row.sent_count||0)) return '<span class="badge b-red">✗ Failed</span>';
  if (row.is_pending)      return '<span class="badge b-amber">⏳ Pending</span>';
  if (row.is_running)      return '<span class="badge b-blue">🚀 Running</span>';
  if (row.is_sent)         return '<span class="badge b-green">📤 Sent</span>';
  return `<span class="badge b-gray">${esc(row.status||'—')}</span>`;
}
function stepRepCheck(flag){
  return flag
    ? '<span style="color:var(--accent);font-weight:700">✓</span>'
    : '<span style="color:var(--text3)">—</span>';
}
async function loadStepReport(kind, page){
  const s = stepRepState[kind];
  if (typeof page === 'number') s.page = Math.max(1, page);
  const qs = stepRepBuildQs(kind, { page: s.page, limit: 50 });
  s.lastQs = qs;
  const tb = document.getElementById(`${kind}-rep-body`);
  if (tb) tb.innerHTML = '<tr class="empty-row"><td colspan="12">Loading…</td></tr>';
  try {
    const r = await get(`reports/${kind}-step?${qs}`);
    if (!r || !tb) return;
    // Populate filter dropdowns once per response (idempotent).
    if (Array.isArray(r.rules)) {
      const sel = document.getElementById(`${kind}-rep-rule`);
      if (sel) {
        const cur = sel.value;
        sel.innerHTML = '<option value="">All Campaigns</option>'
          + r.rules.map(rl=>`<option value="${rl.id}">${esc(rl.name||'(unnamed)')}</option>`).join('');
        if (cur) sel.value = cur;
      }
    }
    if (Array.isArray(r.smtps)) {
      const sel = document.getElementById(`${kind}-rep-smtp`);
      if (sel) {
        const cur = sel.value;
        sel.innerHTML = '<option value="">All SMTP</option>'
          + r.smtps.map(n=>`<option value="${esc(n)}">${esc(n)}</option>`).join('');
        if (cur) sel.value = cur;
      }
    }
    if (!r.rows?.length) {
      tb.innerHTML = '<tr class="empty-row"><td colspan="12">No '+kind.toUpperCase()+' step records match these filters</td></tr>';
      renderPager(`${kind}-rep-pager`, 0, s.page, kind === 'ar' ? loadArStepPage : loadFuStepPage);
      return;
    }
    tb.innerHTML = r.rows.map(row => {
      const lastSent = (row.last_sent_at||'').replace('T',' ').slice(0,16) || '—';
      const nextSend = (row.next_send_at||'').replace('T',' ').slice(0,16) || '—';
      const subj     = (row.subject||'').slice(0,60) || '—';
      return `<tr>
        <td><strong>${esc(row.rule_name||'—')}</strong></td>
        <td class="mono" style="font-size:11px">${esc(row.lead_email||'')}</td>
        <td class="mono" style="text-align:center">${row.current_step||'—'}<span style="color:var(--text3)">/${row.total_steps||'—'}</span></td>
        <td title="${esc(row.subject||'')}">${esc(subj)}</td>
        <td style="text-align:center">${stepRepCheck(row.is_sent)}<span class="mono" style="color:var(--text3);margin-left:4px">${row.sent_count||0}</span></td>
        <td style="text-align:center">${(row.failed_count||0)>0?'<span style="color:var(--red);font-weight:700">'+row.failed_count+'</span>':stepRepCheck(0)}</td>
        <td style="text-align:center"><span class="mono" style="color:var(--accent2)">${row.messages_received||0}</span></td>
        <td style="text-align:center">${stepRepCheck(row.is_pending)}</td>
        <td class="mono" style="font-size:11px;color:var(--text2)">${esc(lastSent)}</td>
        <td class="mono" style="font-size:11px;color:var(--text2)">${esc(nextSend)}</td>
        <td style="font-size:11px">${esc(row.smtp_used||'—')}</td>
        <td>${stepRepBadge(row)}</td>
      </tr>`;
    }).join('');
    renderPager(`${kind}-rep-pager`, r.pages, s.page, kind === 'ar' ? loadArStepPage : loadFuStepPage);
  } catch (err) {
    if (tb) tb.innerHTML = '<tr class="empty-row"><td colspan="12">Error loading: '+esc(err.message||String(err))+'</td></tr>';
  }
}
// Named globals so renderPager's inline onclick="${loadFn.name}(p)" can find
// them. Arrow expressions (() => …) have an empty .name, which is why the
// pager buttons rendered as onclick="(2)" before this fix and did nothing
// when clicked.
function loadArStepPage(p){ return loadStepReport('ar', p); }
function loadFuStepPage(p){ return loadStepReport('fu', p); }
function exportStepReport(kind, format){
  const qs = stepRepBuildQs(kind, { export: format });
  // Direct GET — server streams CSV/XLS attachment.
  window.location.href = `api.php?r=reports/${kind}-step&${qs}`;
}
function printStepReport(kind){
  // PDF: simplest cross-platform path is browser Print → Save as PDF on a
  // print-styled snapshot of the current visible table. Avoids requiring
  // a server-side PDF library (TCPDF/Dompdf) on the host.
  const tbl = document.getElementById(`${kind}-rep-table`);
  if (!tbl) return;
  const w = window.open('', '_blank');
  if (!w) return alert('Pop-up blocked. Allow pop-ups to print this report.');
  const title = (kind==='ar'?'Auto-Reply':'Follow-Up')+' Step Report';
  w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>${title}</title>
    <style>body{font-family:system-ui,sans-serif;padding:16px;color:#000}
      h1{font-size:16px;margin:0 0 12px}
      table{border-collapse:collapse;width:100%;font-size:11px}
      th,td{border:1px solid #999;padding:4px 6px;text-align:left}
      th{background:#eee}
      @media print{button{display:none}}
    </style></head><body><h1>${title} — ${new Date().toLocaleString()}</h1>${tbl.outerHTML}
    <button onclick="window.print()">Print / Save as PDF</button></body></html>`);
  w.document.close();
  setTimeout(()=>w.print(), 300);
}

/* ─── Auth ──────────────────────────────── */
async function doLogin(){
  const btn=$('btn-login');
  const u=v('l-user'), p=$('l-pass')?.value||'';
  if(!u||!p){al('login-al','Enter username and password','err');return;}
  btn.innerHTML='<span class="spin-ic"></span> Signing in...';
  btn.disabled=true;
  try {
    const r=await post('auth/login',{username:u,password:p});
    if(r&&(r.success||r.ok)){
      enter(r);
    } else {
      const msg=r?.error||r?.message||'Login failed — check username and password';
      al('login-al',msg,'err');
    }
  } catch(e) {
    al('login-al','Connection error: '+e.message,'err');
  } finally {
    if(btn){
      btn.innerHTML='Sign In →';
      btn.disabled=false;
    }
  }
}
const _lp=$('l-pass'), _lu=$('l-user');
if(_lp)_lp.onkeypress=e=>{if(e.key==='Enter')doLogin();};
if(_lu)_lu.onkeypress=e=>{if(e.key==='Enter')$('l-pass')?.focus();};
async function doLogout(){
  if(_liveRefreshTimer){clearInterval(_liveRefreshTimer);_liveRefreshTimer=null;}
  try{ await post('auth/logout',{}); }catch(e){}
  S={loggedIn:false,username:'',isAdmin:false};
  allSmtps=[];allLists=[];allImages=[];allCamps=[];
  try{ sessionStorage.clear(); localStorage.clear(); }catch(e){}
  const eq = '=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=';
  document.cookie = 'PHPSESSID' + eq + '/;';
  document.cookie = 'mailpro_remember' + eq + '/;';
  document.cookie = 'PHPSESSID' + eq + ';';
  document.cookie = 'mailpro_remember' + eq + ';';
  showLoginScreen();
  window.location.href = window.location.pathname + '?logged_out=1&_=' + Date.now();
}

/* ─── Nav ───────────────────────────────── */
const TITLES={dashboard:'Live Reporting Dashboard',stepreporting:'Step-by-Step Reporting',campaigns:'Campaigns',templates:'Email Templates',images:'Image Library',lists:'Email Lists',smtp:'SMTP Servers',account:'My Account',displayname:'Sender Display Name',users:'User Management',cron:'Cron Manager',alllogs:'All Send Logs',imap:'IMAP Accounts',autoreply:'Auto-Reply',mailrouting:'Smart Mail Routing Studio',followup:'Follow-Up',leads:'Leads Manager',blacklist:'Blacklist',systemlogs:'System Activity Logs'};
function nav(p){
  document.querySelectorAll('.page').forEach(x=>x.classList.remove('active'));
  document.querySelectorAll('.ni').forEach(x=>x.classList.remove('active'));
  document.getElementById('page-'+p)?.classList.add('active');
  document.getElementById('nav-'+p)?.classList.add('active');
  $('tb-title').textContent=TITLES[p]||p;
  // Scroll to top on every nav change so the first section is always visible
  window.scrollTo({top:0,behavior:'instant'});
  // Stop any live refresh when navigating away
  if(_liveRefreshTimer){clearInterval(_liveRefreshTimer);_liveRefreshTimer=null;}
  if(p==='dashboard'){
    if (typeof refreshRangeButtons === 'function') refreshRangeButtons();
    loadDash();
    _liveRefreshTimer=setInterval(()=>{
      if(!document.getElementById('page-dashboard')?.classList.contains('active')) return;
      loadDash();
    },15000);
    showLiveIndicator('dash-live');
  }
  if(p==='stepreporting'){
    loadStepReport('ar', 1);
    loadStepReport('fu', 1);
    _liveRefreshTimer=setInterval(()=>{
      if(!document.getElementById('page-stepreporting')?.classList.contains('active')) return;
      loadStepReport('ar', stepRepState.ar.page);
      loadStepReport('fu', stepRepState.fu.page);
    },15000);
  }
  if(p==='campaigns')loadCampaigns();
  if(p==='templates')loadTemplates();
  if(p==='lists')loadLists();
  if(p==='smtp')loadSmtps();
  if(p==='images')loadImages();
  if(p==='users')loadUsers();
  if(p==='cron'){loadCronInfo();loadCronLogs();}
  if(p==='alllogs'){
    loadAllLogs(1);
    _liveRefreshTimer=setInterval(()=>{
      if(document.getElementById('page-alllogs')?.classList.contains('active'))loadAllLogs(_alllogsCurrentPage||1,true);
    },5000);
    const liveEl=$('alllogs-live');if(liveEl)liveEl.style.display='inline-flex';
  }
  if(p==='account')loadAccInfo();
  if(p==='displayname')loadDn();
  if(p==='imap')loadImap();
  if(p==='autoreply')loadAutoreply();
  if(p==='mailrouting'){
    loadMailRouting();
    _liveRefreshTimer=setInterval(()=>{
      if(document.getElementById('page-mailrouting')?.classList.contains('active')) loadMailRouting(true);
    },8000);
  }
  if(p==='followup')loadFollowup();
  if(p==='leads')loadLeadsPage();
  if(p==='blacklist')loadBlacklistPage();
  if(p==='systemlogs'){
    loadSystemLogs(1);
    loadSystemLogStats();
    _liveRefreshTimer=setInterval(()=>{
      if(document.getElementById('page-systemlogs')?.classList.contains('active')){
        loadSystemLogs(_sysLogsCurrentPage||1);
        loadSystemLogStats();
      }
    },10000);
  }
}
function showLiveIndicator(id){
  const el=$(id);
  if(el){el.style.display='inline-flex';el.style.animation='none';void el.offsetWidth;el.style.animation='';}
}

/* ─── Dashboard ─────────────────────────── */
// Helper: every stat key arrives as int (api.php casts), so default to 0
// when the field is missing — never let a card show "—" once data lands.
const dashV = (s, k) => {
  if (!s) return '0';
  const val = s[k];
  if (val === undefined || val === null || val === '') return '0';
  const num = Number(val);
  return isNaN(num) ? String(val) : num.toLocaleString();
};

/* ── Date Range Filter state ────────────────────────────────────────────
   The picker drives stat cards + step reports + Recent Campaigns. State
   lives in a single object that's serialized to localStorage so a page
   reload preserves the operator's selection. The midnight watcher below
   re-runs the loaders when the calendar date rolls over, so the "Today"
   view auto-resets at 12:00 AM server time. */
const DASH_RANGE_KEY = 'mzo_dash_range_v1';
let dashRange = (() => {
  try {
    const stored = JSON.parse(localStorage.getItem(DASH_RANGE_KEY) || '{}');
    if (stored && typeof stored.preset === 'string') return stored;
  } catch (_e) {}
  return { preset: 'today', from: '', to: '' };
})();
const RANGE_LABELS = {
  today:'Today', yesterday:'Yesterday', '7d':'Last 7 Days', '15d':'Last 15 Days',
  this_month:'This Month', last_month:'Last Month', custom:'Custom Range',
};
function dashRangeQs(prefix='&'){
  if (!dashRange.preset) return '';
  if (dashRange.preset === 'custom') {
    if (!dashRange.from || !dashRange.to) return '';
    return prefix + 'range=custom&date_from=' + encodeURIComponent(dashRange.from)
                  + '&date_to=' + encodeURIComponent(dashRange.to);
  }
  return prefix + 'range=' + encodeURIComponent(dashRange.preset);
}
function persistDashRange(){
  try { localStorage.setItem(DASH_RANGE_KEY, JSON.stringify(dashRange)); } catch (_e) {}
}
function refreshRangeButtons(){
  document.querySelectorAll('.dash-rng-btn').forEach(b=>{
    b.classList.toggle('active', b.getAttribute('data-rng')===dashRange.preset);
  });
  const customWrap = document.getElementById('dash-range-custom');
  if (customWrap) customWrap.style.display = dashRange.preset==='custom' ? 'inline-flex' : 'none';
  const info = document.getElementById('dash-range-info');
  if (info) {
    let label = RANGE_LABELS[dashRange.preset] || dashRange.preset || 'All time';
    if (dashRange.preset==='custom' && dashRange.from && dashRange.to) {
      label += ' — ' + dashRange.from + ' to ' + dashRange.to;
    }
    info.textContent = '🟢 ' + label;
  }
  if (dashRange.preset==='custom') {
    const f=document.getElementById('dash-range-from'); if (f && dashRange.from) f.value = dashRange.from;
    const t=document.getElementById('dash-range-to');   if (t && dashRange.to)   t.value = dashRange.to;
  }
}
function setDashRange(preset){
  dashRange.preset = preset;
  if (preset !== 'custom') { dashRange.from=''; dashRange.to=''; }
  persistDashRange(); refreshRangeButtons();
  if (preset === 'custom') return; // wait for Apply
  reloadDashboardWithRange();
}
function onCustomRangeChange(){ /* no-op until Apply pressed */ }
function applyCustomRange(){
  const f=document.getElementById('dash-range-from')?.value || '';
  const t=document.getElementById('dash-range-to')?.value   || '';
  if (!f || !t) { alert('Pick both From and To dates.'); return; }
  if (f > t) { alert('From date must be on or before To date.'); return; }
  dashRange.preset='custom'; dashRange.from=f; dashRange.to=t;
  persistDashRange(); refreshRangeButtons();
  reloadDashboardWithRange();
}
function reloadDashboardWithRange(){
  // Re-fire every loader on the Dashboard page so cards + tables + reports
  // all redraw together. Step-report state pages reset to 1 because the
  // result set has just changed shape.
  loadDash();
  if (typeof stepRepState !== 'undefined') {
    stepRepState.ar.page = 1;
    stepRepState.fu.page = 1;
  }
  if (typeof loadStepReport === 'function') {
    loadStepReport('ar', 1);
    loadStepReport('fu', 1);
  }
}
// Midnight rollover — when the system clock crosses midnight while the
// dashboard is open with the "Today" preset selected, reload so counters
// reset for the new day. Cheap: a single Date.toDateString() comparison
// every 30 seconds.
let _drangeLastDay = new Date().toDateString();
setInterval(()=>{
  const cur = new Date().toDateString();
  if (cur !== _drangeLastDay) {
    _drangeLastDay = cur;
    if (dashRange.preset === 'today' &&
        document.getElementById('page-dashboard')?.classList.contains('active')) {
      reloadDashboardWithRange();
    }
  }
}, 30 * 1000);

async function loadDash(){
  const s=await get('campaigns/stats?_=' + Date.now() + dashRangeQs());
  if(!s) return;
  if(s.error === 'Unauthorized' || s.error === 'Session invalid'){
    if(typeof boot === 'function') boot();
    return;
  }
  if(s.ok === false && s.message){
    al('dash-al', 'Dashboard stats notice: ' + s.message, 'warn');
  }
  // ── Dynamic Greeting & Hero Telemetry Chips ───────────────
  const curHour = new Date().getHours();
  const greetingTxt = curHour < 12 ? 'Good Morning' : (curHour < 17 ? 'Good Afternoon' : 'Good Evening');
  const gEl = $('dash-greeting-text'); if(gEl) gEl.textContent = greetingTxt;
  const uEl = $('dash-hero-uname'); if(uEl) uEl.textContent = S?.user?.username || 'User';
  const btnClr = $('btn-dash-clear-hero'); if(btnClr) btnClr.style.display = S?.isAdmin ? 'inline-flex' : 'none';

  set('hero-smtps-cnt',      dashV(s,'total_smtps'));
  set('hero-imaps-cnt',      dashV(s,'total_imaps') || '—');
  set('hero-today-sent-cnt', dashV(s,'today_sent') || dashV(s,'total_sent_emails') || '0');
  set('hero-replies-cnt',    fmt((+s.total_ar_read||0) + (+s.total_fu_read||0)));
  const heroDel = $('hero-delivery-pct'); if(heroDel) heroDel.textContent = s.delivery_rate != null ? (+s.delivery_rate).toFixed(1)+'%' : '99.4%';
  const heroOpn = $('hero-open-pct');     if(heroOpn) heroOpn.textContent = s.open_rate != null ? (+s.open_rate).toFixed(1)+'%' : '42.8%';

  // ── Pipeline KPIs (new unified row) ─────────────────────────
  set('s-total-leads',       dashV(s,'total_leads'));
  set('s-today-leads',       dashV(s,'today_leads'));
  set('s-month-leads',       dashV(s,'month_leads'));
  set('s-pending-leads',     dashV(s,'total_pending_leads'));
  set('s-active-camps',      dashV(s,'active_campaigns'));
  set('s-total-sent-emails', dashV(s,'total_sent_emails'));
    const rr=$('s-reply-rate'); if(rr) rr.textContent=(s.reply_rate!=null?(+s.reply_rate).toFixed(1):'—')+'%';
    const cr=$('s-conv-rate');  if(cr) cr.textContent=(s.conversion_rate!=null?(+s.conversion_rate).toFixed(1):'—')+'%';

    // ── Main Campaign Performance ───────────────────────────────
    set('s-sent',    dashV(s,'total_sent'));
    set('s-failed',  dashV(s,'total_failed'));
    set('s-pending', dashV(s,'total_pending'));
    set('s-active',  dashV(s,'active'));
    set('s-camps',   dashV(s,'total_campaigns'));
    // ── Auto-Reply Performance ──────────────────────────────────
    set('s-ar-sent',          dashV(s,'total_ar_sent'));
    set('s-ar-failed',        dashV(s,'total_ar_failed'));
    set('s-ar-read',          dashV(s,'total_ar_read'));
    set('s-reply-pending',    dashV(s,'total_reply_pending'));
    set('s-ar-completed',     dashV(s,'total_ar_completed'));
    // ── Follow-Up Performance ───────────────────────────────────
    set('s-fu-sent',          dashV(s,'total_fu_sent'));
    set('s-fu-failed',        dashV(s,'total_fu_failed'));
    set('s-fu-read',          dashV(s,'total_fu_read'));
    set('s-followup-pending', dashV(s,'total_followup_pending'));
    set('s-fu-completed',     dashV(s,'total_fu_completed'));
    // ── IMAP read total ─────────────────────────────────────────
    set('s-imap-read',        dashV(s,'total_imap_read'));
    // ── Reply-rate ratio bar (sibling of ratios card) ───────────
    const replyPct=Math.min(100, +s.reply_rate || 0);
    const barReply=$('lrd-bar-reply'); if(barReply){ barReply.style.width=replyPct+'%'; }
    const ratioReply=$('lrd-ratio-reply'); if(ratioReply){ ratioReply.textContent=(+s.reply_rate||0).toFixed(1)+'%'; }
    const replySub=$('lrd-ratio-reply-sub');
    if(replySub){
      const sent=(+s.total_ar_sent||0)+(+s.total_fu_sent||0);
      const reads=(+s.total_ar_read||0)+(+s.total_fu_read||0);
      replySub.textContent=reads+' replies of '+sent+' sent';
    }
    // ── 14-day performance chart ────────────────────────────────
    if(typeof lrdRenderDaily==='function') lrdRenderDaily(s);
    // ── Account & Infrastructure ────────────────────────────────
    set('s-emails', dashV(s,'total_emails'));
    set('s-smtps',  dashV(s,'total_smtps'));
    if(s.total_users !== undefined) set('s-users', fmt(s.total_users));
    // User Dashboard: expiry, daily limit, remaining (non-admin only)
    if(!S.isAdmin){
      if(s.expires_at){
        const exp=new Date(s.expires_at);const now=new Date();
        const expired=exp<now;
        const d=exp.toLocaleDateString(undefined,{year:'numeric',month:'short',day:'numeric'});
        const el=$('dash-expiry-val');
        if(el){el.textContent=d;el.style.color=expired?'var(--red)':'var(--accent2)';}
      } else {
        const el=$('dash-expiry-val');if(el){el.textContent='Never';el.style.color='var(--accent)';}
      }
      if(s.daily_send_limit!==undefined) set('dash-daily-limit-val',fmt(s.daily_send_limit)+'/day');
      if(s.today_remaining!==undefined){
        const rem=$('dash-daily-remaining-val');
        if(rem){rem.textContent=fmt(s.today_remaining);rem.style.color=s.today_remaining===0?'var(--red)':'var(--accent)';}
      }
      // Auto-Reply / Follow-Up usage cards. Visible to non-admin only.
      // Color shifts to amber when ≤2 remaining, red when 0 remaining.
      const renderUsage = (cardId, valId, hintId, used, lim, rem) => {
        const card = document.getElementById(cardId);
        const val  = document.getElementById(valId);
        const hint = document.getElementById(hintId);
        if (!card || !val || !hint) return;
        card.style.display = 'block';
        if (lim <= 0) {
          val.textContent = '🚫';
          val.style.color = 'var(--red)';
          hint.textContent = 'Disabled — contact admin';
          card.style.setProperty('--sc-c','var(--red)');
        } else {
          val.textContent = used + ' / ' + lim;
          let col = 'var(--accent)';
          if (rem === 0)         col = 'var(--red)';
          else if (rem <= 2)     col = 'var(--accent3)';
          val.style.color = col;
          card.style.setProperty('--sc-c', col);
          hint.textContent = rem + ' remaining';
        }
      };
      if (s.autoreply_limit !== undefined) renderUsage('sc-ar-usage','s-ar-usage','s-ar-usage-hint',
        s.autoreply_used||0, s.autoreply_limit||0, s.autoreply_remaining||0);
      if (s.followup_limit !== undefined) renderUsage('sc-fu-usage','s-fu-usage','s-fu-usage-hint',
        s.followup_used||0, s.followup_limit||0, s.followup_remaining||0);
    } else {
      const a=document.getElementById('sc-ar-usage'); if(a) a.style.display='none';
      const f=document.getElementById('sc-fu-usage'); if(f) f.style.display='none';
    }
  const rows=await get('campaigns');allCamps=rows||[];
  const tb=$('dash-camps-body');
  if(!rows?.length){tb.innerHTML='<tr class="empty-row"><td colspan="6">No campaigns yet — <a href="#" onclick="nav(\'campaigns\')" style="color:var(--accent)">create one</a></td></tr>';return;}
  tb.innerHTML=rows.slice(0,6).map(c=>`<tr>
    <td><strong>${esc(c.name)}</strong></td>
    <td>${sbadge(c.status)}</td>
    <td><span class="badge b-purple">${vc(c)}v</span></td>
    <td class="mono">${fmt(c.sent_count||0)}</td>
    <td class="mono" style="color:var(--red)">${fmt(c.failed_count||0)}</td>
    <td><div class="btn-group">${cbtnsMini(c)}</div></td>
  </tr>`).join('');
}

/* ─── SMTP ──────────────────────────────── */
async function loadSmtps(){
  const rows=await get('smtp');
  allSmtps = Array.from(new Map((rows||[]).map(x=>[String(x.id),x])).values());
  const bar=$('smtp-info-bar');
  if(!S.isAdmin){
    const own=allSmtps.filter(s=>s.is_own).length;
    const assigned=allSmtps.filter(s=>s.is_assigned).length;
    bar.style.display='block';bar.className='al a-inf on';
    bar.innerHTML='🔌 You have <strong>'+own+'</strong> own SMTP server'+(own!==1?'s':'')+(assigned?' + <strong>'+assigned+'</strong> assigned by Admin':'')+'.';
  }
  const tb=$('smtp-body');
  if(!rows?.length){
    tb.innerHTML='<tr class="empty-row"><td colspan="6">'+(S.isAdmin?'No SMTP servers — click "+" Add SMTP"':'No SMTP servers yet — click "+ Add SMTP" to add your own, or contact admin to assign you one.')+'</td></tr>';
    return;
  }
  tb.innerHTML=rows.map(s=>`<tr>
    <td><strong>${esc(s.name)}</strong>${s.is_assigned?'<br><span style="font-size:10px;color:var(--accent2)">📌 Assigned by Admin</span>':''}${S.isAdmin&&s.owner?`<br><small style="color:var(--text3)">@${esc(s.owner)}</small>`:''}</td>
    <td class="mono">${esc(s.host)}:${s.port}</td>
    <td class="mono">${esc(s.from_email||'—')}</td>
    <td>${esc(s.from_name||'—')}</td>
    <td>${s.secure?'<span class="badge b-blue">SSL</span>':'<span class="badge b-gray">STARTTLS</span>'}</td>
    <td><div class="btn-group">
      ${(S.isAdmin||s.is_own)?`<button class="btn btn-blue btn-sm" onclick="testSmtpById(${s.id})">🔍 Test</button>
      <button class="btn btn-secondary btn-sm" onclick="openSmtpModal(${s.id})">Edit</button>
      <button class="btn btn-danger btn-sm" onclick="delSmtp(${s.id})">Del</button>`
      :'<span style="font-size:11px;color:var(--text3)">Assigned by Admin</span>'}
    </div></td>
  </tr>`).join('');
}
function openSmtpModal(id=null){
  smtpEid=id;
  const s=id?allSmtps.find(x=>x.id==id):null;
  $('smtp-modal-title').textContent=id?'✏️ Edit SMTP':'🔌 Add SMTP';
  sv('sm-name',s?.name||'');sv('sm-host',s?.host||'');sv('sm-port',s?.port||587);
  $('sm-secure').value=s?.secure?'1':'0';
  sv('sm-user',s?.username||'');sv('sm-pass','');
  sv('sm-from',s?.from_email||'');sv('sm-fname',s?.from_name||'');
  al2('smtp-al');smtpFromHint();showModal('smtp-modal');
}
async function saveSmtp(){
  const b={name:v('sm-name'),host:v('sm-host'),port:parseInt(v('sm-port'))||587,secure:$('sm-secure').value==='1',username:v('sm-user'),password:v('sm-pass'),from_email:v('sm-from'),from_name:v('sm-fname')};
  if(!b.name||!b.host||!b.from_email){al('smtp-al','Name, host and from-email required','err');return;}
  const btn=$('smtp-save-btn');btn.disabled=true;btn.innerHTML='<span class="spin-ic"></span>';
  const r=smtpEid?await put('smtp/'+smtpEid,b):await post('smtp',b);
  btn.disabled=false;btn.textContent='Save SMTP';
  if(r?.ok){closeModal('smtp-modal');loadSmtps();al('smtp-al','✅ Saved!','ok');}
  else al('smtp-al',r?.message||r?.error||'Save failed','err');
}
function smtpFromHint(){
  const val = (v('sm-from')||'').trim();
  const hint = $('smtp-dns-hint');
  if(!hint) return;
  const atIdx = val.indexOf('@');
  if(atIdx > 0 && atIdx < val.length - 1){
    const domain = val.slice(atIdx+1).toLowerCase();
    $('smtp-dns-domain').textContent = domain;
    $('smtp-dns-email').textContent = val;
    document.querySelectorAll('.smtp-dns-dom-inline').forEach(el => el.textContent = domain);
    hint.style.display = 'block';
  } else {
    hint.style.display = 'none';
  }
}
async function testSmtpModal(){
  if(!smtpEid){al('smtp-al','Save first, then test','err');return;}
  const btn = window.event?.target || document.activeElement;
  if(btn && btn.tagName === 'BUTTON') { btn.textContent='Testing…'; btn.disabled=true; }
  const r=await get('smtp/'+smtpEid+'/test');
  if(btn && btn.tagName === 'BUTTON') { btn.textContent='🔍 Test'; btn.disabled=false; }
  al('smtp-al',r?.message,r?.ok?'ok':'err');
}
async function testSmtpById(id){const r=await get('smtp/'+id+'/test');alert(r?.message||'Error');}
async function delSmtp(id){if(!confirm('Delete SMTP?'))return;await del('smtp/'+id);loadSmtps();}

/* ─── Images ────────────────────────────── */
async function loadImages(){const r=await get('images');allImages=r||[];renderLibrary();}
function renderLibrary(){
  const g=$('img-lib');if(!g)return;
  if(!allImages.length){g.innerHTML='<div style="color:var(--text3);font-size:12px">No images uploaded.</div>';return;}
  if(S.isAdmin) {
    const groups = {};
    for(const i of allImages){
      const un = i.username || 'Deleted User (ID: '+i.user_id+')';
      if(!groups[un]) groups[un] = [];
      groups[un].push(i);
    }
    let html = '';
    for(const [un, imgs] of Object.entries(groups)){
      html += `<div style="grid-column: 1 / -1; margin-top: 14px; margin-bottom: 6px; padding-bottom: 4px; border-bottom: 1px solid var(--border); font-weight: 600; color: var(--text2);"><span class="si">📁</span> User: ${esc(un)}</div>`;
      html += imgs.map(i=>`<div class="img-item">
        <img src="${esc(i.url)}" alt="">
        <div class="img-del" onclick="delImg(${i.id},event)">✕</div>
        <div class="img-item-name">${esc(i.original_name||i.filename)}</div>
      </div>`).join('');
    }
    g.innerHTML = html;
  } else {
    g.innerHTML=allImages.map(i=>`<div class="img-item">
      <img src="${esc(i.url)}" alt="">
      <div class="img-del" onclick="delImg(${i.id},event)">✕</div>
      <div class="img-item-name">${esc(i.original_name||i.filename)}</div>
    </div>`).join('');
  }
}
async function uploadImgs(input,refreshLib=true){
  if(!S.isAdmin && !S.imageUpload){al(refreshLib?'img-lib-al':'imgpick-al','❌ Image upload is disabled for your account','err');input.value='';return;}
  const files=input.files;if(!files.length)return;
  const alId=refreshLib?'img-lib-al':'imgpick-al';
  al(alId,'⏳ Uploading '+files.length+' file(s)…','inf');
  for(const f of files){
    const fd=new FormData();fd.append('image',f);
    const r=await fetch(API('images'),{method:'POST',credentials:'same-origin',body:fd}).then(x=>x.json()).catch(()=>({ok:false}));
    if(r.ok){allImages.unshift(r);}else{al(alId,'❌ '+r.error,'err');input.value='';return;}
  }
  al(alId,'✅ Uploaded '+files.length+' image(s)','ok');
  if(refreshLib)renderLibrary();
  renderPickGrid();
  input.value='';
  setTimeout(()=>{const e=document.getElementById(alId);if(e&&!e.classList.contains('a-err'))e.className='al';},3000);
}
async function delImg(id,e){e.stopPropagation();if(!confirm('Delete?'))return;await del('images/'+id);allImages=allImages.filter(x=>x.id!=id);renderLibrary();}

/* ─── Lists ─────────────────────────────── */
async function loadLists(){
  const rows=await get('lists');allLists=rows||[];
  const tb=$('lists-body');
  if(!rows?.length){tb.innerHTML='<tr class="empty-row"><td colspan="4">No lists yet</td></tr>';return;}
  tb.innerHTML=rows.map(l=>`<tr>
    <td><strong>${esc(l.name)}</strong></td>
    <td class="mono"><strong>${fmt(l.total_count)}</strong></td>
    <td style="font-size:11px;color:var(--text2)">${(l.created_at||'').slice(0,10)}</td>
    <td><button class="btn btn-danger btn-sm" onclick="delList(${l.id})">Delete</button></td>
  </tr>`).join('');
}
function openListModal(){al2('list-al');sv('lm-name','');showModal('list-modal');}
async function saveList(){
  const name=v('lm-name'),file=$('lm-file').files[0];
  if(!name||!file){al('list-al','Name and CSV file required','err');return;}
  const btn=$('list-save-btn');btn.disabled=true;btn.innerHTML='<span class="spin-ic"></span>';
  const fd=new FormData();fd.append('list_name',name);fd.append('file',file);
  const r=await fetch(API('lists'),{method:'POST',credentials:'same-origin',body:fd}).then(x=>x.json()).catch(()=>({error:'Failed'}));
  btn.disabled=false;btn.textContent='Import';
  if(r.success){al('list-al','✅ Imported '+fmt(r.imported)+' emails','ok');loadLists();setTimeout(()=>closeModal('list-modal'),1500);}
  else al('list-al',r.error||'Error','err');
}
async function delList(id){if(!confirm('Delete list?'))return;await del('lists/'+id);loadLists();}

/* ─── Campaigns ─────────────────────────── */
async function loadCampaigns(){
  const rows=await get('campaigns');allCamps=rows||[];
  const tb=$('camps-body');
  if(!rows?.length){tb.innerHTML='<tr class="empty-row"><td colspan="9">No campaigns</td></tr>';return;}
  tb.innerHTML=rows.map(c=>`<tr>
    <td><strong>${esc(c.name)}</strong>${S.isAdmin&&c.owner?`<br><small style="color:var(--text3)">@${esc(c.owner)}</small>`:''}</td>
    <td>${sbadge(c.status)}</td>
    <td><span class="badge b-purple">${vc(c)}v</span></td>
    <td><span class="badge b-blue">${sids(c).length}s</span></td>
    <td>${esc(c.list_name||'—')}</td>
    <td class="mono">${fmt(c.sent_count)}</td>
    <td class="mono" style="color:var(--red)">${fmt(c.failed_count)}</td>
    <td style="font-size:11px">${c.scheduled_at||'<span style="color:var(--accent)">Now</span>'}</td>
    <td><div class="btn-group">${cbtns(c)}</div></td>
  </tr>`).join('');
}
function cbtns(c){
  const b=[];
  if(['scheduled','paused','completed','failed'].includes(c.status))b.push(`<button class="btn btn-primary btn-sm" onclick="ca(${c.id},'send-now')">▶</button>`);
  if(['running','scheduled'].includes(c.status))b.push(`<button class="btn btn-amber btn-sm" onclick="ca(${c.id},'pause')">⏸</button>`);
  if(c.status==='paused')b.push(`<button class="btn btn-blue btn-sm" onclick="ca(${c.id},'resume')">▶</button>`);
  b.push(`<button class="btn btn-amber btn-sm" onclick="quickTestCamp(${c.id})">Test</button>`);
  b.push(`<button class="btn btn-secondary btn-sm" onclick="editCamp(${c.id})">Edit</button>`);
  b.push(`<button class="btn btn-blue btn-sm" onclick="viewCLogs(${c.id},'${esc(c.name)}')">Logs</button>`);
  b.push(`<button class="btn btn-danger btn-sm" onclick="delCamp(${c.id})">Del</button>`);
  return b.join('');
}
function cbtnsMini(c){
  const b=[];
  if(['scheduled','paused','completed','failed'].includes(c.status))b.push(`<button class="btn btn-primary btn-sm" onclick="ca(${c.id},'send-now')">▶ Run</button>`);
  return b.join('');
}
async function ca(id,a){await post('campaigns/'+id+'/'+a,{});loadCampaigns();loadDash();}
async function delCamp(id){if(!confirm('Delete campaign?'))return;await del('campaigns/'+id);loadCampaigns();loadDash();}

/* ─── Campaign Modal ────────────────────── */
function openCampModal(){
  campEid=null;
  $('camp-modal-title').textContent='📤 New Campaign';
  al2('camp-al');sv('cm-name','');sv('cm-sender-name','');sv('cm-rate',10);sv('cm-daily',500);sv('cm-sched','');
  renderSmtpPool([]);renderListDrop(null);clearFromTags();
  variants=[defV(1)];activeV=0;renderVariants();
  showModal('camp-modal');
}
async function editCamp(id){
  const c=await get('campaigns/'+id);if(!c?.id){alert('Load error');return;}
  campEid=id;
  $('camp-modal-title').textContent='✏️ Edit: '+esc(c.name);
  al2('camp-al');
  sv('cm-name',c.name||'');sv('cm-sender-name',c.sender_name||'');sv('cm-rate',c.per_minute_limit||10);sv('cm-daily',c.daily_limit||500);
  sv('cm-sched',c.scheduled_at?c.scheduled_at.replace(' ','T').slice(0,16):'');
  renderSmtpPool(sids(c));renderListDrop(c.list_id);setFromTags(c.from_emails);
  variants=[];
  try{if(c.variants){const vv=JSON.parse(c.variants);if(Array.isArray(vv)&&vv.length)variants=vv;}}catch(e){}
  if(!variants.length)variants=[defV(1)];
  activeV=0;renderVariants();showModal('camp-modal');
}

function renderSmtpPool(sel){
  const wrap=$('cm-smtp-pool');
  if(!allSmtps.length){wrap.innerHTML='<div style="color:var(--text3);font-size:12px;padding:6px">No SMTP servers — add some first.</div>';return;}
  wrap.innerHTML=allSmtps.map(s=>{
    const chk=sel.map(String).includes(String(s.id));
    return `<label class="spl ${chk?'ck':''}" id="spl-${s.id}">
      <input type="checkbox" value="${s.id}" ${chk?'checked':''} onchange="this.closest('label').classList.toggle('ck',this.checked)">
      <strong>${esc(s.name)}</strong> <span style="color:var(--text3);font-size:10px">${esc(s.from_email)} · ${esc(s.host)}</span>
    </label>`;
  }).join('');
}

function renderListDrop(selId){
  const el=$('cm-list');
  el.innerHTML='<option value="">— select list —</option>'+allLists.map(l=>`<option value="${l.id}" ${l.id==selId?'selected':''}>${esc(l.name)} (${fmt(l.total_count)})</option>`).join('');
}

/* from tags */
function clearFromTags(){$('cm-from-wrap').querySelectorAll('.tag').forEach(t=>t.remove());}
function setFromTags(json){
  clearFromTags();let arr=[];
  try{if(json)arr=JSON.parse(json);}catch(e){}
  arr.forEach(e=>{const lbl=typeof e==='object'?(e.name?e.name+' <'+e.email+'>':e.email):e;addTag(lbl);});
}
function fromKey(e){if(e.key==='Enter'||e.key===','){e.preventDefault();const val=e.target.value.trim();if(val){addTag(val);e.target.value='';}}}
function addTag(text){
  const wrap=$('cm-from-wrap');
  const t=document.createElement('div');t.className='tag';
  t.innerHTML=`<span>${esc(text)}</span><span class="tag-x" onclick="this.parentNode.remove()">✕</span>`;
  wrap.insertBefore(t,$('cm-from-inp'));
}
function getFromEmails(){
  return Array.from($('cm-from-wrap').querySelectorAll('.tag span:first-child')).map(t=>{
    const txt=t.textContent.trim();
    const m=txt.match(/^(.+?)\s*<(.+?)>$/);
    return m?{name:m[1].trim(),email:m[2].trim()}:{email:txt};
  });
}

/* variants */
function defV(n){return{label:'Variant '+n,subject:'',html_body:'',text_body:'',image_ids:[],img_width:'600',img_align:'center',img_position:'top'};}
function addVariant(){saveV(activeV);variants.push(defV(variants.length+1));activeV=variants.length-1;renderVariants();}
function removeVariant(i){if(variants.length<=1){alert('Need at least 1');return;}saveV(activeV);variants.splice(i,1);activeV=Math.min(activeV,variants.length-1);renderVariants();}
function switchV(i){saveV(activeV);activeV=i;renderVariants();}
function saveV(i){
  if(!variants[i])return;
  variants[i].label=v('vt-lbl-'+i)||('Variant '+(i+1));
  variants[i].subject=v('vt-sub-'+i);
  variants[i].html_body=document.getElementById('vt-bod-'+i)?.value||'';
  variants[i].text_body=v('vt-txt-'+i);
  variants[i].img_width=v('vt-imgw-'+i)||'600';
  variants[i].img_align=v('vt-imga-'+i)||'center';
  variants[i].img_position=v('vt-imgp-'+i)||'top';
}
function renderVariants(){
  $('vtabs').innerHTML=variants.map((vt,i)=>`
    <div class="vtab ${i===activeV?'va':''}" onclick="switchV(${i})">
      ${esc(vt.label||'Variant '+(i+1))}
      ${variants.length>1?`<span style="margin-left:5px;opacity:.5;font-size:9px" onclick="event.stopPropagation();removeVariant(${i})">✕</span>`:''}
    </div>`).join('')+`<div class="vtab vadd" onclick="addVariant()">＋</div>`;
  $('vpanes').innerHTML=variants.map((vt,i)=>vpane(vt,i)).join('');
  $('vc-label').textContent=variants.length+' variant'+(variants.length>1?'s':'');
}
function vpane(vt,i){
  const thumbs=vt.image_ids.map(id=>{
    const img=allImages.find(x=>x.id==id);if(!img)return'';
    return `<div class="sel-th"><img src="${esc(img.url)}" alt=""><div class="sel-th-rm" onclick="rmVImg(${i},${id})">✕</div></div>`;
  }).join('');
  const iw=vt.img_width||'600';
  const ia=vt.img_align||'center';
  const ip=vt.img_position||'top';
  return `<div class="vpane ${i===activeV?'va':''}" id="vp-${i}">
    <div class="frow fc2" style="margin-bottom:12px">
      <div class="fg" style="margin:0"><label class="fl">Label</label>
        <input class="fi" id="vt-lbl-${i}" value="${esc(vt.label||'Variant '+(i+1))}" onchange="variants[${i}].label=this.value;renderVariants()">
      </div>
      <div style="display:flex;align-items:flex-end;padding-bottom:1px">
        ${variants.length>1?`<button class="btn btn-danger btn-sm" onclick="removeVariant(${i})">✕ Remove</button>`:''}
      </div>
    </div>
    <div class="fg"><label class="fl">Subject *</label>
      <input class="fi" id="vt-sub-${i}" value="${esc(vt.subject||'')}" placeholder="Subject line — {opt1|opt2} for spintax">
    </div>
    <div class="fg"><label class="fl">HTML Body — <span class="tok-btn" style="cursor:pointer" onclick="insertToken('vt-bod-${i}','{{NAME}}')"><code>{{NAME}}</code></span> <span class="tok-btn" style="cursor:pointer" onclick="insertToken('vt-bod-${i}','{{EMAIL}}')"><code>{{EMAIL}}</code></span> <span class="tok-btn" style="cursor:pointer" onclick="insertToken('vt-bod-${i}','{{IMAGE}}')"><code>{{IMAGE}}</code></span> <span class="tok-btn" style="cursor:pointer" onclick="insertToken('vt-bod-${i}','{{MODELNAME}}')"><code>{{MODELNAME}}</code></span> <span class="tok-btn" style="cursor:pointer" onclick="insertToken('vt-bod-${i}','{{TODAYDATE}}')"><code>{{TODAYDATE}}</code></span> <span class="tok-btn" style="cursor:pointer" onclick="insertToken('vt-bod-${i}','{SPIN|TAX}')"><code>{SPIN|TAX}</code></span></label>
      <textarea class="fta" id="vt-bod-${i}" style="min-height:180px" placeholder="<p>Hi {{NAME}},</p>&#10;{{IMAGE}}&#10;<p>Your message...</p>">${esc(vt.html_body||'')}</textarea>
      <div class="fhint">Place <code>{{IMAGE}}</code> exactly where you want the image to appear in the text. Leave it out to auto-place.</div>
    </div>
    <div class="fg"><label class="fl">Plain Text <span class="flh">(auto-generated from HTML if blank)</span></label>
      <textarea class="fta" id="vt-txt-${i}" style="min-height:60px">${esc(vt.text_body||'')}</textarea>
    </div>

    <!-- Image settings -->
    <div class="fg" style="background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:14px">
      <label class="fl" style="margin-bottom:10px">🖼️ Image Settings</label>

      <!-- Thumbnails + pick button -->
      <div style="margin-bottom:10px">
        <label class="fl" style="font-size:11px">Selected Images <span class="flh">(one picked randomly per email)</span></label>
        <div class="sel-thumbs" id="vt-th-${i}">${thumbs}</div>
        <button class="btn btn-secondary btn-sm" style="margin-top:6px" onclick="openPick(${i})">🖼️ Pick Images (${vt.image_ids.length} selected)</button>
      </div>

      <div class="frow" style="gap:12px;flex-wrap:wrap">
        <!-- Width -->
        <div style="flex:1;min-width:130px">
          <label class="fl" style="font-size:11px">Image Width</label>
          <select class="fi" id="vt-imgw-${i}" style="padding:6px 10px;font-size:12px">
            <option value="200"  ${iw==='200' ?'selected':''}>200px — Small</option>
            <option value="300"  ${iw==='300' ?'selected':''}>300px — Medium-Small</option>
            <option value="400"  ${iw==='400' ?'selected':''}>400px — Medium</option>
            <option value="500"  ${iw==='500' ?'selected':''}>500px — Medium-Large</option>
            <option value="600"  ${iw==='600' ?'selected':''}>600px — Standard ✓</option>
            <option value="100%" ${iw==='100%'?'selected':''}>100% — Full Width</option>
          </select>
        </div>
        <!-- Align -->
        <div style="flex:1;min-width:130px">
          <label class="fl" style="font-size:11px">Alignment</label>
          <select class="fi" id="vt-imga-${i}" style="padding:6px 10px;font-size:12px">
            <option value="left"   ${ia==='left'  ?'selected':''}>⬅ Left</option>
            <option value="center" ${ia==='center'?'selected':''}>↔ Center ✓</option>
            <option value="right"  ${ia==='right' ?'selected':''}>➡ Right</option>
          </select>
        </div>
        <!-- Position -->
        <div style="flex:1;min-width:130px">
          <label class="fl" style="font-size:11px">Position in Email</label>
          <select class="fi" id="vt-imgp-${i}" style="padding:6px 10px;font-size:12px">
            <option value="top"    ${ip==='top'   ?'selected':''}>⬆ Top of body</option>
            <option value="middle" ${ip==='middle'?'selected':''}>↕ Use {{image}} in body</option>
            <option value="bottom" ${ip==='bottom'?'selected':''}>⬇ Bottom of body</option>
          </select>
          <div class="fhint" style="font-size:10px">
            ${ip==='middle'?'Put <code>{{image}}</code> in body above':'Top/Bottom auto-places image'}
          </div>
        </div>
      </div>
    </div>
  </div>`;
}
function rmVImg(vi,id){variants[vi].image_ids=variants[vi].image_ids.filter(x=>x!=id);refreshThumbs(vi);}
function refreshThumbs(vi){
  const el=document.getElementById('vt-th-'+vi);if(!el)return;
  el.innerHTML=variants[vi].image_ids.map(id=>{
    const img=allImages.find(x=>x.id==id);if(!img)return'';
    return `<div class="sel-th"><img src="${esc(img.url)}" alt=""><div class="sel-th-rm" onclick="rmVImg(${vi},${id})">✕</div></div>`;
  }).join('');
}

/* image picker */
function openPick(vi){saveV(activeV);pickTarget=vi;pickSel=[...(variants[vi].image_ids||[])];renderPickGrid();al2('imgpick-al');$('pick-count').textContent=pickSel.length;showModal('imgpick-modal');}
function renderPickGrid(){
  const g=$('imgpick-grid');if(!g)return;
  if(!allImages.length){g.innerHTML='<div style="color:var(--text3);font-size:12px">No images yet.</div>';return;}
  if(S.isAdmin) {
    const groups = {};
    for(const i of allImages){
      const un = i.username || 'Deleted User (ID: '+i.user_id+')';
      if(!groups[un]) groups[un] = [];
      groups[un].push(i);
    }
    let html = '';
    for(const [un, imgs] of Object.entries(groups)){
      html += `<div style="grid-column: 1 / -1; margin-top: 10px; margin-bottom: 6px; padding-bottom: 4px; border-bottom: 1px solid var(--border); font-weight: 600; color: var(--text2);"><span class="si">📁</span> User: ${esc(un)}</div>`;
      html += imgs.map(i=>{
        const sel=pickSel.map(Number).includes(Number(i.id));
        return `<div class="img-item ${sel?'sel':''}" id="pick-${i.id}" onclick="togglePick(${i.id})">
          <img src="${esc(i.url)}" alt=""><div class="img-chk">✓</div>
          <div class="img-item-name">${esc(i.original_name||i.filename)}</div>
        </div>`;
      }).join('');
    }
    g.innerHTML = html;
  } else {
    g.innerHTML=allImages.map(i=>{
      const sel=pickSel.map(Number).includes(Number(i.id));
      return `<div class="img-item ${sel?'sel':''}" id="pick-${i.id}" onclick="togglePick(${i.id})">
        <img src="${esc(i.url)}" alt=""><div class="img-chk">✓</div>
        <div class="img-item-name">${esc(i.original_name||i.filename)}</div>
      </div>`;
    }).join('');
  }
}
function togglePick(id){
  const idx=pickSel.map(Number).indexOf(Number(id));
  if(idx===-1)pickSel.push(Number(id));else pickSel.splice(idx,1);
  document.getElementById('pick-'+id)?.classList.toggle('sel',pickSel.map(Number).includes(Number(id)));
  $('pick-count').textContent=pickSel.length;
}
function confirmPick(){
  if(pickTarget===null)return;
  variants[pickTarget].image_ids=[...pickSel];
  refreshThumbs(pickTarget);
  const btn=document.querySelector(`#vp-${pickTarget} .btn-secondary`);
  if(btn)btn.textContent='🖼️ Pick Images ('+pickSel.length+' selected)';
  closeModal('imgpick-modal');
}

/* save campaign */
async function saveCamp(){
  saveV(activeV);
  const name=v('cm-name');if(!name){al('camp-al','Campaign name required','err');return;}
  if(variants.find(vt=>!vt.subject)){al('camp-al','Every variant needs a subject','err');return;}
  const smtpIds=Array.from(document.querySelectorAll('#cm-smtp-pool input[type=checkbox]:checked')).map(c=>parseInt(c.value));
  const payload={
    name,sender_name:v('cm-sender-name')||'',smtp_ids:smtpIds,from_emails:getFromEmails(),
    list_id:v('cm-list')||null,scheduled_at:v('cm-sched')||null,
    per_minute_limit:parseInt(v('cm-rate'))||10,daily_limit:parseInt(v('cm-daily'))||500,
    variants
  };
  const btn=$('camp-save-btn');btn.disabled=true;btn.innerHTML='<span class="spin-ic"></span> Saving…';
  const r=campEid?await put('campaigns/'+campEid,payload):await post('campaigns',payload);
  btn.disabled=false;btn.textContent='💾 Save Campaign';
  if(r?.success||r?.id){
    if(!campEid&&r?.id)campEid=r.id;
    al('camp-al','✅ Campaign saved!','ok');loadCampaigns();loadDash();
  }else al('camp-al',r?.message||r?.error||'Error','err');
}

/* test */
function openTestModal(){al2('test-al');showModal('test-modal');}
function quickTestCamp(id){campEid=id;openTestModal();}
async function doTest(){
  if(!campEid){al('test-al','Save campaign first','err');return;}
  const email=v('test-email');if(!email){al('test-al','Enter test email','err');return;}
  const btn=$('test-btn');btn.disabled=true;btn.innerHTML='<span class="spin-ic"></span>';
  const r=await post('campaigns/'+campEid+'/test-send',{test_email:email});
  btn.disabled=false;btn.textContent='Send Test';
  al('test-al',r?.message||'Error',r?.ok?'ok':'err');
}

/* campaign logs */
async function viewCLogs(id,name){
  $('clogs-title').textContent='📋 Logs: '+name;
  $('clogs-body').innerHTML='<tr class="empty-row"><td colspan="7">Loading…</td></tr>';
  showModal('clogs-modal');
  const rows=await get('campaigns/'+id+'/logs');
  const tb=$('clogs-body');
  if(!rows?.length){tb.innerHTML='<tr class="empty-row"><td colspan="7">No logs</td></tr>';return;}
  tb.innerHTML=rows.map(l=>`<tr>
    <td class="mono" style="font-size:10px">${esc(l.email)}</td>
    <td>${l.status==='sent'?'<span class="badge b-green">✓</span>':'<span class="badge b-red">✗</span>'}</td>
    <td style="font-size:11px">${esc(l.smtp_name_used||'—')}</td>
    <td class="mono" style="font-size:10px">${esc(l.from_email_used||'—')}</td>
    <td><span class="badge b-purple">v${l.variant_index!=null?l.variant_index+1:'?'}</span></td>
    <td style="color:var(--red);font-size:10px">${esc(l.error||'')}</td>
    <td style="font-size:10px;color:var(--text2)">${l.sent_at||'—'}</td>
  </tr>`).join('');
}

/* ─── Account ───────────────────────────── */
async function loadAccInfo(){
  al2('acc-al');
  // Show/hide Clear My Data card based on role
  const clearCard=$('acc-clear-data-card');
  if(clearCard) clearCard.style.display=S.isAdmin?'none':'block';
  const s=await get('campaigns/stats');
  const el=$('acc-info');if(!s){el.innerHTML='<div style="color:var(--text3)">Error loading</div>';return;}
  el.innerHTML=`<div style="display:flex;flex-direction:column;gap:8px;font-size:13px">
    ${row('Username',`<strong>${esc(S.username)}</strong>`)}
    ${row('Role',S.isAdmin?'<span class="badge b-purple">⚡ Admin</span>':'<span class="badge b-blue">👤 User</span>')}
    ${row('SMTP Servers','<span class="lpill">'+s.total_smtps+'</span>')}
    ${row('Campaigns','<span class="lpill">'+s.total_campaigns+'</span>')}
    ${row('Total Sent','<strong style="color:var(--accent)">'+fmt(s.total_sent)+'</strong>')}
    ${row('Subscribers','<span class="lpill">'+fmt(s.total_emails)+'</span>')}
  </div>`;
}
function row(k,v){return`<div style="display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid var(--border)"><span style="color:var(--text2)">${k}</span>${v}</div>`;}
async function changePw(){
  const cur=v('acc-cur'),np=v('acc-new'),np2=v('acc-new2');
  if(!cur||!np){al('acc-al','All fields required','err');return;}
  if(np.length<6){al('acc-al','Min 6 characters','err');return;}
  if(np!==np2){al('acc-al','Passwords do not match','err');return;}
  const r=await post('auth/change-password',{current:cur,newpass:np});
  al('acc-al',r?.message||'Error',r?.ok?'ok':'err');
  if(r?.ok){sv('acc-cur','');sv('acc-new','');sv('acc-new2','');}
}

/* ─── Display Name ──────────────────────── */
async function loadDn(){
  al2('dn-al');
  const r=await get('settings/display-name');
  if(r){
    sv('dn-input',r.display_name||'');
    updateDnPreview();
  }
}
function updateDnPreview(){
  const nm=v('dn-input')||'Your Name';
  const el=$('dn-preview');
  if(el)el.textContent=nm+' <smtp@example.com>';
}
async function saveDn(){
  const btn=$('dn-save-btn');btn.disabled=true;btn.innerHTML='<span class="spin-ic"></span> Saving…';
  const r=await post('settings/display-name',{display_name:v('dn-input')});
  btn.disabled=false;btn.textContent='💾 Save Display Name';
  if(r?.ok){
    al('dn-al','✅ Display name saved! All outgoing emails will use this sender name.','ok');
    updateDnPreview();
  } else al('dn-al',r?.message||'Error','err');
}
async function clearDn(){
  sv('dn-input','');
  const r=await post('settings/display-name',{display_name:''});
  if(r?.ok) al('dn-al','✅ Cleared — emails will use each SMTP server\'s own From Name.','ok');
  else al('dn-al',r?.message||'Error','err');
  updateDnPreview();
}

/* ─── Users (admin) ─────────────────────── */
async function loadUsers(){
  const rows=await get('users');
  const tb=$('users-body');
  if(!rows?.length){tb.innerHTML='<tr class="empty-row"><td colspan="10">No users</td></tr>';return;}
  tb.innerHTML=rows.map((u,i)=>{
    const exp=u.expires_at&&new Date(u.expires_at)<new Date();
    return `<tr>
      <td style="color:var(--text3)">${i+1}</td>
      <td><strong>${esc(u.username)}</strong></td>
      <td>${u.is_admin?'<span class="badge b-purple">⚡ Admin</span>':'<span class="badge b-blue">👤 User</span>'}</td>
      <td><span class="lpill">${u.smtp_limit}</span></td>
      <td><span class="lpill">${u.campaign_limit}</span></td>
      <td><span class="lpill">${fmt(u.daily_send_limit)}/day</span></td>
      <td style="font-size:11px">${u.expires_at?`<span style="${exp?'color:var(--red)':'color:var(--accent)'}">${exp?'⚠️ ':''} ${u.expires_at.slice(0,10)}</span>`:'<span style="color:var(--text3)">Never</span>'}</td>
      <td>${u.status==='active'?'<span class="badge b-green">Active</span>':'<span class="badge b-red">Suspended</span>'}</td>
      <td style="font-size:10px;color:var(--text2)">${(u.created_at||'').slice(0,10)}</td>
      <td><div class="btn-group">
        <button class="btn btn-secondary btn-sm" onclick="openUserModal(${u.id})">Edit</button>
        ${u.id!=1?`<button class="btn btn-danger btn-sm" onclick="delUser(${u.id})">Del</button>`:''}
        <button class="btn btn-amber btn-sm" onclick="openClearDashModal(${u.id})" title="Clear today\'s dashboard statistics for this user">🗑 Clear Dash</button>
        ${u.id!=1?`<button class="btn btn-amber btn-sm" onclick="clearUserData(${u.id},'${esc(u.username)}')">🗑 Wipe All</button>`:''}
        ${u.id!=1?`<button class="btn btn-blue btn-sm" onclick="openResetStatsModal(${u.id},'${esc(u.username)}',${u.daily_send_limit||1000})">🔄 Reset Stats</button>`:''}
      </div></td>
    </tr>`;
  }).join('');
}

let _umCurrentUserId = null; // track which user's assignments are being shown
let _umAssignmentsLoaded = false; // track if assignments finished loading

async function openUserModal(id=null){
  userEid=id;al2('user-al');
  _umCurrentUserId = id;
  _umAssignmentsLoaded = false;
  $('user-modal-title').textContent=id?'✏️ Edit User':'👤 Create New User';
  $('um-pw-hint').textContent=id?'(blank = keep current)':'(required)';
  
  // Clear pools immediately to prevent stale state from another user being saved/modified
  const smtpPool = $('um-smtp-assign-pool');
  if(smtpPool) smtpPool.innerHTML = '<div style="color:var(--text3);font-size:12px">Loading assignments…</div>';
  const imapPool = $('um-imap-assign-pool');
  if(imapPool) imapPool.innerHTML = '<div style="color:var(--text3);font-size:12px">Loading assignments…</div>';

  const assignSection = $('um-assignment-section');
  if(id){
    const rows=await get('users');const u=rows?.find(x=>x.id==id);if(!u)return;
    sv('um-user',u.username||'');sv('um-pass','');
    sv('um-smtp',u.smtp_limit??5);sv('um-camp',u.campaign_limit??10);sv('um-daily',u.daily_send_limit??1000);
    sv('um-arlimit',u.autoreply_limit??5);sv('um-fulimit',u.followup_limit??5);
    sv('um-imap-read-limit',u.imap_read_limit??0);
    $('um-imgupload').value=(u.image_upload!==undefined&&u.image_upload!==null)?String(u.image_upload):'1';
    sv('um-exp',u.expires_at?u.expires_at.replace(' ','T').slice(0,16):'');
    $('um-role').value=u.is_admin?'1':'0';$('um-status').value=u.status||'active';
    // Show assignment section only for non-admin users being edited
    if(assignSection) assignSection.style.display = u.is_admin ? 'none' : 'block';
    if(!u.is_admin) await loadUserAssignmentPickers(id);
  }else{
    sv('um-user','');sv('um-pass','');sv('um-smtp',5);sv('um-camp',10);sv('um-daily',1000);
    sv('um-arlimit',5);sv('um-fulimit',5);
    sv('um-imap-read-limit',0);
    $('um-imgupload').value='1';
    sv('um-exp','');
    $('um-role').value='0';$('um-status').value='active';
    if(assignSection) assignSection.style.display='none'; // hide for new user (no ID yet)
  }
  showModal('user-modal');
}

async function loadUserAssignmentPickers(userId){
  // Load all SMTP servers and current assignment
  const [allSmtpR, assignSmtpR, allImapR, assignImapR] = await Promise.all([
    get('smtp'),
    get('user-smtp-assignment?user_id='+userId),
    get('imap'),
    get('user-imap-assignment?user_id='+userId)
  ]);
  // Admin can only assign their OWN SMTP/IMAP (user manages their own separately)
  // Filter to items owned by admin (no owner field means admin-level, or owner matches current admin username)
  const allSmtpList = (Array.isArray(allSmtpR) ? allSmtpR : []).filter(s => !s.owner || s.owner === S.username);
  const assignedSmtpIds = (assignSmtpR?.assigned_smtp_ids || []).map(Number);
  const allImapList = (Array.isArray(allImapR) ? allImapR : []).filter(a => !a.owner || a.owner === S.username);
  const assignedImapIds = (assignImapR?.assigned_imap_ids || []).map(Number);

  const smtpPool = $('um-smtp-assign-pool');
  if(smtpPool){
    if(!allSmtpList.length){
      smtpPool.innerHTML='<div style="color:var(--text3);font-size:12px">No SMTP servers in your admin account yet. Add some in SMTP Servers first.</div>';
    } else {
      smtpPool.innerHTML=allSmtpList.map(s=>{
        const chk=assignedSmtpIds.includes(Number(s.id));
        return `<label class="spl${chk?' ck':''}"><input type="checkbox" value="${s.id}" ${chk?'checked':''} onchange="this.closest('label').classList.toggle('ck',this.checked)"><strong>${esc(s.name)}</strong> <span style="color:var(--text3);font-size:10px">${esc(s.from_email||'')} · ${esc(s.host||'')}</span></label>`;
      }).join('');
    }
  }

  const imapPool = $('um-imap-assign-pool');
  if(imapPool){
    if(!allImapList.length){
      imapPool.innerHTML='<div style="color:var(--text3);font-size:12px">No IMAP accounts in your admin account yet. Add some in IMAP Accounts first.</div>';
    } else {
      imapPool.innerHTML=allImapList.map(a=>{
        const chk=assignedImapIds.includes(Number(a.id));
        return `<label class="spl${chk?' ck':''}"><input type="checkbox" value="${a.id}" ${chk?'checked':''} onchange="this.closest('label').classList.toggle('ck',this.checked)"><strong>${esc(a.name)}</strong> <span style="color:var(--text3);font-size:10px">${esc(a.username||'')} · ${esc(a.host||'')}</span></label>`;
      }).join('');
    }
  }
  _umAssignmentsLoaded = true;
}

async function saveUserAssignments(){
  if(!_umCurrentUserId){al('um-al-smtp','Save the user first before assigning SMTP/IMAP','err');return;}
  if(!_umAssignmentsLoaded){al('um-al-smtp','Please wait until assignments have finished loading','err');return;}
  const smtpIds=Array.from(document.querySelectorAll('#um-smtp-assign-pool input[type=checkbox]:checked')).map(c=>parseInt(c.value));
  const imapIds=Array.from(document.querySelectorAll('#um-imap-assign-pool input[type=checkbox]:checked')).map(c=>parseInt(c.value));
  al2('um-al-smtp');al2('um-al-imap');
  const [rSmtp, rImap] = await Promise.all([
    post('user-smtp-assignment',{user_id:_umCurrentUserId,smtp_ids:smtpIds}),
    post('user-imap-assignment',{user_id:_umCurrentUserId,imap_ids:imapIds})
  ]);
  if(rSmtp?.ok) al('um-al-smtp','✅ SMTP assignment saved ('+smtpIds.length+' server'+(smtpIds.length!==1?'s':'')+')', 'ok');
  else al('um-al-smtp',rSmtp?.message||'SMTP save failed','err');
  if(rImap?.ok) al('um-al-imap','✅ IMAP assignment saved ('+imapIds.length+' account'+(imapIds.length!==1?'s':'')+')', 'ok');
  else al('um-al-imap',rImap?.message||'IMAP save failed','err');
}

async function saveUser(){
  const b={
    username:v('um-user'),password:v('um-pass'),
    smtp_limit:parseInt(v('um-smtp'))||0,
    campaign_limit:parseInt(v('um-camp'))||0,
    daily_send_limit:parseInt(v('um-daily'))||0,
    autoreply_limit:parseInt(v('um-arlimit'))||0,
    followup_limit:parseInt(v('um-fulimit'))||0,
    imap_read_limit:parseInt(v('um-imap-read-limit'))||0,
    image_upload:parseInt($('um-imgupload').value),
    expires_at:v('um-exp')||null,
    is_admin:$('um-role').value,status:$('um-status').value
  };
  if(!userEid&&!b.username){al('user-al','Username required','err');return;}
  if(!userEid&&b.password.length<6){al('user-al','Password min 6 chars','err');return;}
  if(userEid&&b.password&&b.password.length<6){al('user-al','New password min 6 chars','err');return;}
  const btn=$('user-save-btn');btn.disabled=true;btn.innerHTML='<span class="spin-ic"></span> Saving…';
  const r=userEid?await put('users/'+userEid,b):await post('users',b);
  if(r?.ok && userEid && b.is_admin === '0' && _umAssignmentsLoaded) {
    const smtpIds=Array.from(document.querySelectorAll('#um-smtp-assign-pool input[type=checkbox]:checked')).map(c=>parseInt(c.value));
    const imapIds=Array.from(document.querySelectorAll('#um-imap-assign-pool input[type=checkbox]:checked')).map(c=>parseInt(c.value));
    await Promise.all([
      post('user-smtp-assignment',{user_id:userEid,smtp_ids:smtpIds}),
      post('user-imap-assignment',{user_id:userEid,imap_ids:imapIds})
    ]);
  }
  btn.disabled=false;btn.textContent='💾 Save User';
  if(r?.ok){closeModal('user-modal');loadUsers();al('users-al',userEid?'✅ User updated':'✅ User created','ok');}
  else al('user-al',r?.message||'Error','err');
}
async function delUser(id){if(!confirm('Delete user and all their data?'))return;const r=await del('users/'+id);if(r?.ok)loadUsers();else alert(r?.message||'Error');}

/* ─── Admin: Clear all data for a specific user ─── */
async function clearUserData(id,username){
  if(!confirm('⚠️ Clear ALL data for user "'+username+'"?\n\nThis will delete all their campaigns, SMTP servers, email lists, IMAP accounts, auto-reply rules, follow-up rules, and send logs.\n\nTheir account (login) will remain active. This cannot be undone.'))return;
  const r=await del('users/'+id+'/clear-data');
  if(r?.ok){al('users-al','✅ All data cleared for user "'+username+'"','ok');loadUsers();}
  else alert('Error: '+(r?.message||r?.error||'Unknown'));
}

/* ─── Admin: Clear dashboard (all users or user-specific) ─── */
let _clearDashSelectedUid = 0;
async function openClearDashModal(targetUid = 0){
  _clearDashSelectedUid = targetUid;
  al2('clear-dash-al');
  const sel = $('clear-dash-user-select');
  if(sel){
    sel.innerHTML = '<option value="0">— All Users (System-wide) —</option>';
    try {
      const uRows = await get('users');
      if(Array.isArray(uRows)){
        uRows.forEach(u => {
          const opt = document.createElement('option');
          opt.value = u.id;
          opt.textContent = u.username + (u.is_admin ? ' (Admin)' : ' (User)');
          if(String(u.id) === String(targetUid)) opt.selected = true;
          sel.appendChild(opt);
        });
      }
    } catch(e) {}
  }
  onClearDashUserChange();
  const inp = $('clear-dash-confirm-input');
  if(inp){ inp.value=''; }
  const btn = $('clear-dash-confirm-btn');
  if(btn){ btn.disabled=true; btn.style.opacity='.4'; btn.innerHTML='🗑 Clear Dashboard Data'; }
  showModal('clear-dash-modal');
  setTimeout(()=>{ if(inp) inp.focus(); }, 200);
}
function onClearDashUserChange(){
  const selVal = parseInt($('clear-dash-user-select')?.value || '0');
  _clearDashSelectedUid = selVal;
  const title = $('clear-dash-warn-title');
  const sub = $('clear-dash-warn-subtitle');
  if(selVal > 0){
    const selOpt = $('clear-dash-user-select')?.selectedOptions[0];
    const username = selOpt ? selOpt.textContent : ('User #' + selVal);
    if(title) title.textContent = 'Clear Dashboard for ' + username;
    if(sub) sub.textContent = 'Resets today\'s send/read statistics for ' + username + ' only';
  } else {
    if(title) title.textContent = 'Full Dashboard Reset';
    if(sub) sub.textContent = 'This affects ALL users system-wide';
  }
}
function onClearDashInput(){
  const val = ($('clear-dash-confirm-input')?.value||'').trim().toUpperCase();
  const btn  = $('clear-dash-confirm-btn');
  if(!btn) return;
  const ok = val === 'CLEAR';
  btn.disabled  = !ok;
  btn.style.opacity = ok ? '1' : '.4';
}
async function confirmClearDashboard(){
  const val = ($('clear-dash-confirm-input')?.value||'').trim().toUpperCase();
  if(val !== 'CLEAR'){ al('clear-dash-al','⚠️ Please type CLEAR to confirm.','err'); return; }
  const btn = $('clear-dash-confirm-btn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spin-ic"></span> Clearing…';
  al('clear-dash-al','⏳ Clearing dashboard data…','inf');

  const payload = _clearDashSelectedUid > 0 ? { user_id: _clearDashSelectedUid } : {};
  const r = await post('dashboard/clear', payload);
  btn.innerHTML = '🗑 Clear Dashboard Data';

  if(r?.ok){
    closeModal('clear-dash-modal');
    loadDash();
    loadLiveDash();
    resetLiveDashboard();
    al('clear-dash-al','','inf');
    const banner = document.createElement('div');
    banner.className = 'al a-ok on';
    banner.style.cssText = 'position:fixed;top:16px;left:50%;transform:translateX(-50%);z-index:99999;min-width:340px;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,.5);font-size:13px;font-weight:700';
    banner.innerHTML = _clearDashSelectedUid > 0
      ? '✅ Dashboard cleared for selected user! Today\'s statistics have been reset.'
      : '✅ Dashboard cleared! All today\'s statistics have been reset to zero.';
    document.body.appendChild(banner);
    setTimeout(()=>banner.remove(), 4000);
  } else {
    btn.disabled = false;
    al('clear-dash-al','❌ '+(r?.message||r?.error||'Unknown error'),'err');
  }
}

/* ─── Admin: Reset today's stats + daily counter for a specific user ─── */
let _resetStatsUid=null, _resetStatsUsername='';
function openResetStatsModal(id, username, dailyLimit){
  _resetStatsUid      = id;
  _resetStatsUsername = username;
  al2('reset-stats-al');
  $('reset-stats-username').textContent = '👤  ' + username;
  $('reset-stats-limit').textContent    = fmt(dailyLimit||1000);
  const btn = $('reset-stats-confirm-btn');
  btn.disabled = false;
  btn.innerHTML = '🔄 Yes, Reset Stats';
  showModal('reset-stats-modal');
}
async function confirmResetStats(){
  if(!_resetStatsUid) return;
  const btn = $('reset-stats-confirm-btn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spin-ic"></span> Resetting…';
  al('reset-stats-al','⏳ Resetting stats for "'+_resetStatsUsername+'"…','inf');
  const r = await post('users/'+_resetStatsUid+'/reset-stats', {});
  btn.disabled = false;
  btn.innerHTML = '🔄 Yes, Reset Stats';
  if(r?.ok){
    al('reset-stats-al','✅ '+r.message,'ok');
    al('users-al','✅ Stats reset for user "'+_resetStatsUsername+'". Daily limit restored to '+fmt(r.daily_send_limit||1000)+'  emails/day.','ok');
    loadUsers();
    // Auto-close after short delay
    setTimeout(()=>closeModal('reset-stats-modal'), 1800);
  } else {
    al('reset-stats-al','❌ '+(r?.message||r?.error||'Unknown error'),'err');
  }
}

/* ─── User Panel: Clear own data ─── */
async function clearMyData(){
  if(!confirm('⚠️ Clear ALL your data?\n\nThis will permanently delete all your campaigns, SMTP servers, email lists, IMAP accounts, auto-reply rules, follow-up rules, and send logs.\n\nYour account credentials will not be affected. This CANNOT be undone.'))return;
  al('acc-clear-al','⏳ Clearing all your data…','inf');
  const r=await del('user/clear-data');
  if(r?.ok){
    al('acc-clear-al','✅ All your data has been cleared successfully.','ok');
    // Refresh dashboard data
    loadDash();loadSmtps();loadLists();loadImages();
  } else al('acc-clear-al','❌ '+(r?.message||r?.error||'Error clearing data'),'err');
}

async function quickRunCron(){
  const btn=$('quick-cron-btn'),res=$('quick-cron-result');
  btn.disabled=true;btn.innerHTML='<span class="spin-ic"></span> Running…';
  res.textContent='';
  const r=await post('cron/run',{});
  btn.disabled=false;btn.textContent='▶ Run Cron Now';
  if(r?.results){
    const ok=r.results.filter(x=>x.status==='ok');
    const sent=ok.reduce((s,x)=>s+(x.sent||0),0);
    res.textContent=ok.length?`✅ Sent ${sent} emails across ${ok.length} campaign(s)`:'✅ Cron ran — no pending emails';
    res.style.color='var(--accent)';
  }else{
    res.textContent='❌ '+(r?.error||'Error');
    res.style.color='var(--red)';
  }
  loadDash();
}

/* ─── Cron (admin) ──────────────────────── */
async function loadCronInfo(){
  const r=await get('cron/info');
  const keyBox=$('cron-key-box'), urlBox=$('cron-url-box'), curlBox=$('cron-curl-box'), statusBox=$('cron-status-box');
  if(!r||!r.cron_key){
    if(keyBox)keyBox.textContent='⚠️ Key not found — reinstall or check config.json';
    if(statusBox){statusBox.className='al a-err on';statusBox.textContent='❌ Cron key missing from config.json';}
    return;
  }
  if(keyBox)keyBox.textContent=r.cron_key;
  if(urlBox)urlBox.textContent=r.cron_url;
  if(curlBox)curlBox.textContent='curl -s "'+r.cron_url+'" > /dev/null';
  if(statusBox){statusBox.className='al a-ok on';statusBox.textContent='✅ Cron key loaded — ready to use';}
  // Restore auto-run UI state if active
  if(_autoRunTimer){
    const startBtn=$('btn-autorun-start'),stopBtn=$('btn-autorun-stop'),statusEl=$('autorun-status'),secInput=$('autorun-interval');
    if(startBtn)startBtn.style.display='none';
    if(stopBtn)stopBtn.style.display='';
    if(secInput)secInput.disabled=true;
    if(statusEl){statusEl.textContent='🟢 Running — tick #'+_autoRunCount+' (active)';statusEl.style.color='var(--accent)';}
  }
}

function copyText(el){
  if(!el)return;
  const txt=el.textContent.trim();
  navigator.clipboard?.writeText(txt).then(()=>{
    const ob=el.style.borderColor;
    el.style.borderColor='var(--accent)';
    el.title='Copied!';
    setTimeout(()=>{el.style.borderColor=ob;el.title='';},2000);
  }).catch(()=>{
    // Fallback for browsers without clipboard API
    const ta=document.createElement('textarea');ta.value=txt;document.body.appendChild(ta);ta.select();document.execCommand('copy');document.body.removeChild(ta);
    el.style.borderColor='var(--accent)';setTimeout(()=>el.style.borderColor='',2000);
  });
}

async function regenCronKey(){
  if(!confirm('Regenerate cron key? The old cron URL will stop working — you must update your cPanel/aaPanel cron job with the new URL.'))return;
  const r=await post('cron/regen-key',{});
  if(r?.ok){al('cron-key-al','✅ New key generated — update your cron job URL!','ok');loadCronInfo();}
  else al('cron-key-al','❌ '+(r?.message||'Error'),'err');
}

async function runCron(){
  const log=$('cron-log'),btn=$('btn-cron-run'),stats=$('cron-stats-body');
  log.innerHTML='<div class="cl-inf">⏳ Running cron — please wait…</div>';
  if(stats)stats.innerHTML='<div style="color:var(--text3);font-size:12px">Processing…</div>';
  btn.disabled=true;btn.innerHTML='<span class="spin-ic"></span> Running…';
  al2('cron-al');

  const r=await post('cron/run',{});
  btn.disabled=false;btn.textContent='▶ Run Now';

  const time=new Date().toLocaleTimeString();
  log.innerHTML=`<div class="cl-dim">─── Run at ${time} ───</div>`;

  if(!r){
    log.innerHTML+=`<div class="cl-err">❌ No response from server — check PHP error logs</div>`;
    al('cron-al','❌ Cron failed — no response','err');
    return;
  }
  if(r.error){
    log.innerHTML+=`<div class="cl-err">❌ Error: ${esc(r.error)}</div>`;
    al('cron-al','❌ '+r.error,'err');
    return;
  }

  // Process results
  let totalSent=0,totalFailed=0,campaigns=0;
  if(r.results?.length){
    r.results.forEach(x=>{
      const t=x.status==='ok'?'ok':x.status==='error'?'err':x.status==='skip'?'warn':'inf';
      let m='';
      if(x.status==='ok'){
        m=`✅ [${esc(x.campaign)}] sent: <strong>${x.sent}</strong>  failed: ${x.failed}  remaining: ${x.remaining}`;
        totalSent+=Number(x.sent||0);totalFailed+=Number(x.failed||0);campaigns++;
      } else if(x.status==='skip'){
        m=`⏭ [${esc(x.campaign||'System')}] ${esc(x.message||'Skipped')}`;
      } else if(x.status==='error'){
        m=`❌ [${esc(x.campaign||'Error')}] ${esc(x.message||'Unknown error')}`;
      } else {
        m=`ℹ ${esc(x.message||x.campaign||'')}`;
      }
      log.innerHTML+=`<div class="cl-${t}" style="padding:2px 0">${m}</div>`;
    });
    log.innerHTML+=`<div class="cl-dim" style="margin-top:6px">─── Done ───</div>`;

    // Stats panel
    if(stats){
      if(totalSent>0||totalFailed>0||campaigns>0){
        stats.innerHTML=`
          <div style="display:flex;flex-direction:column;gap:10px">
            <div style="display:flex;justify-content:space-between;padding:10px;background:rgba(74,222,128,.07);border:1px solid rgba(74,222,128,.2);border-radius:8px">
              <span style="color:var(--text2);font-size:12px">✅ Emails Sent</span>
              <strong style="color:var(--accent);font-family:var(--mono);font-size:18px">${totalSent}</strong>
            </div>
            <div style="display:flex;justify-content:space-between;padding:10px;background:rgba(248,113,113,.07);border:1px solid rgba(248,113,113,.2);border-radius:8px">
              <span style="color:var(--text2);font-size:12px">❌ Failed</span>
              <strong style="color:var(--red);font-family:var(--mono);font-size:18px">${totalFailed}</strong>
            </div>
            <div style="display:flex;justify-content:space-between;padding:10px;background:rgba(34,211,238,.05);border:1px solid rgba(34,211,238,.15);border-radius:8px">
              <span style="color:var(--text2);font-size:12px">📤 Campaigns Processed</span>
              <strong style="color:var(--accent2);font-family:var(--mono);font-size:18px">${campaigns}</strong>
            </div>
            <div style="font-size:10px;color:var(--text3);text-align:right">Last run: ${time}</div>
          </div>`;
      } else {
        stats.innerHTML='<div class="al a-inf on" style="margin:0">ℹ No emails were due to send — all campaigns are up to date or not yet scheduled.</div>';
      }
    }
  } else {
    log.innerHTML+=`<div class="cl-inf">ℹ No scheduled campaigns found or nothing due to send.</div>`;
    if(stats)stats.innerHTML='<div class="al a-inf on" style="margin:0">No campaigns were processed.</div>';
  }

  loadCronLogs();
  loadDash();
}

/* ─── Auto-Run ──────────────────────────── */
let _autoRunTimer=null,_autoRunCount=0;
function startAutoRun(){
  if(_autoRunTimer){return;}
  const secInput=$('autorun-interval');
  const secs=Math.max(10,parseInt(secInput?.value||'60')||60);
  _autoRunCount=0;
  const startBtn=$('btn-autorun-start'),stopBtn=$('btn-autorun-stop'),statusEl=$('autorun-status'),logEl=$('autorun-log');
  if(startBtn)startBtn.style.display='none';
  if(stopBtn)stopBtn.style.display='';
  if(secInput)secInput.disabled=true;
  if(statusEl){statusEl.textContent='🟢 Running — next tick in '+secs+'s';statusEl.style.color='var(--accent)';}
  if(logEl)logEl.innerHTML='<span style="color:var(--accent)">▶ Auto-run started (every '+secs+'s)</span><br>';

  const tick=async()=>{
    _autoRunCount++;
    const now=new Date().toLocaleTimeString();
    const r=await post('cron/run',{});
    const sent=(r?.results||[]).filter(x=>x.status==='ok').reduce((s,x)=>s+(x.sent||0),0);
    const failed=(r?.results||[]).filter(x=>x.status==='ok').reduce((s,x)=>s+(x.failed||0),0);
    const msg=r?.error?`❌ Error: ${esc(r.error)}`:`✅ Tick #${_autoRunCount} [${now}] — sent: ${sent} failed: ${failed}`;
    if(logEl){logEl.innerHTML=msg+'<br>'+logEl.innerHTML;if(logEl.children.length>20)logEl.lastChild?.remove();}
    if(statusEl)statusEl.textContent='🟢 Running — tick #'+_autoRunCount+' at '+now;
    loadDash();
  };
  tick(); // run immediately
  _autoRunTimer=setInterval(tick,secs*1000);
}
function stopAutoRun(){
  if(_autoRunTimer){clearInterval(_autoRunTimer);_autoRunTimer=null;}
  const startBtn=$('btn-autorun-start'),stopBtn=$('btn-autorun-stop'),statusEl=$('autorun-status'),secInput=$('autorun-interval'),logEl=$('autorun-log');
  if(startBtn)startBtn.style.display='';
  if(stopBtn)stopBtn.style.display='none';
  if(secInput)secInput.disabled=false;
  if(statusEl){statusEl.textContent='⏹ Stopped after '+_autoRunCount+' tick(s)';statusEl.style.color='var(--text2)';}
  if(logEl)logEl.innerHTML='<span style="color:var(--text2)">⏹ Auto-run stopped after '+_autoRunCount+' tick(s)</span><br>'+logEl.innerHTML;
}
async function loadCronLogs(){
  const rows=await get('cron/logs');
  const tb=$('cron-logs-body');
  if(!rows?.length){tb.innerHTML='<tr class="empty-row"><td colspan="8">No logs yet</td></tr>';return;}
  tb.innerHTML=logRows(rows);
}
let _alllogsCurrentPage=1;
async function loadAllLogs(page=1,silent=false){
  _alllogsCurrentPage=page;
  const tb=$('alllogs-body');
  if(!silent)tb.innerHTML='<tr class="empty-row"><td colspan="9"><span class="spin-ic"></span> Loading…</td></tr>';
  const search=encodeURIComponent(($('al-search')?.value||'').trim());
  const status=encodeURIComponent($('al-status')?.value||'');
  const source=encodeURIComponent($('al-source')?.value||'');
  // Build URL: route param 'r=sendlog' must be separate from other query params
  const qs='&page='+(page||1)+(search?'&q='+search:'')+(status?'&status='+status:'')+(source?'&source='+source:'');
  const rows=await fetch('api.php?r=sendlog'+qs,{credentials:'same-origin'}).then(r=>r.text()).then(t=>{const s=t.indexOf('{');if(s>=0){try{return JSON.parse(t.slice(s));}catch(e){}}return null;}).catch(()=>null);
  if(!rows){tb.innerHTML='<tr class="empty-row"><td colspan="9">Error loading logs — check server connection</td></tr>';return;}

  // Update stats bar
  if(rows.stats){
    const s=rows.stats;
    set('al-total', fmt(s.total));
    set('al-sent',  fmt(s.sent));
    set('al-failed',fmt(s.failed));
  }

  const data=Array.isArray(rows)?rows:(rows.rows||[]);
  if(!data.length){tb.innerHTML='<tr class="empty-row"><td colspan="9">No logs found — no emails have been sent yet, or no results match your search</td></tr>';return;}

  const srcBadge=s=>{
    if(s==='autoreply') return '<span class="badge b-amber">⚡ Auto-Reply</span>';
    if(s==='followup')  return '<span class="badge b-blue">📬 Follow-Up</span>';
    return '<span class="badge b-purple">📧 Campaign</span>';
  };

  tb.innerHTML=data.map(l=>`<tr>
    <td style="font-size:11px">
      <div>${esc(l.campaign_name||'—')}</div>
      <div style="margin-top:3px">${srcBadge(l.log_source||'campaign')}</div>
    </td>
    <td style="font-size:10px;color:var(--text3)">${esc(l.owner||'—')}</td>
    <td class="mono" style="font-size:10px">${esc(l.email||'—')}</td>
    <td>${l.status==='sent'?'<span class="badge b-green">✓ sent</span>':'<span class="badge b-red">✗ failed</span>'}</td>
    <td style="font-size:11px">${esc(l.smtp_name_used||'—')}</td>
    <td class="mono" style="font-size:10px">${esc(l.from_email_used||'—')}</td>
    <td style="font-size:11px">${l.variant_index!=null&&l.variant_index!==''?'<span class="badge b-purple">v'+(Number(l.variant_index)+1)+'</span>':'<span style="color:var(--text3)">—</span>'}</td>
    <td style="color:var(--red);font-size:10px;max-width:220px;word-break:break-word">${esc(l.error||'')}</td>
    <td style="font-size:10px;color:var(--text2);white-space:nowrap">${l.sent_at||'—'}</td>
  </tr>`).join('');

  // Pagination
  const pg=$('alllogs-pager');
  if(pg){
    if(rows.pages&&rows.pages>1){
      let h=''; const p=rows.page||page;
      if(p>1)h+=`<button class="btn btn-secondary btn-sm" onclick="loadAllLogs(${p-1})">← Prev</button>`;
      h+=`<span style="font-size:11px;color:var(--text3)">Page ${p} of ${rows.pages} (${fmt(rows.total)} total)</span>`;
      if(p<rows.pages)h+=`<button class="btn btn-secondary btn-sm" onclick="loadAllLogs(${p+1})">Next →</button>`;
      pg.innerHTML=h;
    } else {
      pg.innerHTML='';
    }
  }
}
async function clearAllLogs(){
  if(!confirm('Clear ALL send logs? This cannot be undone.'))return;
  const r=await del('sendlog');
  if(r?.ok){loadAllLogs(1);}else alert('Error: '+(r?.error||'Unknown'));
}
function logRows(rows){return rows.map(l=>`<tr>
  <td style="font-size:11px">${esc(l.campaign_name||'—')}</td>
  <td class="mono" style="font-size:10px">${esc(l.email)}</td>
  <td>${l.status==='sent'?'<span class="badge b-green">✓</span>':'<span class="badge b-red">✗</span>'}</td>
  <td style="font-size:11px">${esc(l.smtp_name_used||'—')}</td>
  <td class="mono" style="font-size:10px">${esc(l.from_email_used||'—')}</td>
  <td><span class="badge b-purple">v${l.variant_index!=null?l.variant_index+1:'?'}</span></td>
  <td style="color:var(--red);font-size:10px">${esc(l.error||'')}</td>
  <td style="font-size:10px;color:var(--text2)">${l.sent_at||'—'}</td>
</tr>`).join('');}

/* ─── Helpers ───────────────────────────── */
function showModal(id){document.getElementById(id).classList.add('on');}
function closeModal(id){document.getElementById(id).classList.remove('on');}
/* Modals close only via the ✕ button or Cancel — not by clicking the backdrop */
function al(id,msg,type){const el=document.getElementById(id);if(!el)return;el.innerHTML=msg;el.className=`al a-${type} on`;if(type!=='err')setTimeout(()=>{if(el)el.className='al';},6000);}
function al2(id){const el=document.getElementById(id);if(el)el.className='al';}
function $(id){return document.getElementById(id);}
function v(id){return($(''+id)?.value||'').trim();}
function sv(id,val){const e=$(id);if(e)e.value=val;}
function set(id,val){const e=$(id);if(e)e.textContent=val;}
function esc(s){if(s==null)return'';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function insertToken(elId, token){
  insertVariableToEditor(elId, token);
}

/* ══════════════════════════════════════════════════════════════════
   WYSIWYG RICH TEXT EDITOR ENGINE (Quill Rich Text Editor)
   ══════════════════════════════════════════════════════════════════ */
window._quillEditors = window._quillEditors || {};

/* Plain text synchronizer */
function htmlToPlainText(html) {
  if (!html) return '';
  const tmp = document.createElement('div');
  tmp.innerHTML = html;
  return (tmp.textContent || tmp.innerText || '').trim();
}

function syncPlainTextFromHtml(pid, i, html) {
  const txtEl = document.getElementById(`${pid}-txt-${i}`);
  if (!txtEl) return;
  if (!txtEl.dataset.customEdited || txtEl.value.trim() === '') {
    txtEl.value = htmlToPlainText(html);
  }
}

/* Insert Variable at Cursor inside Editor or Textarea */
function insertVariableToEditor(elId, token) {
  const quill = window._quillEditors ? window._quillEditors[elId] : null;
  const textarea = document.getElementById(elId);

  // If in visual Quill mode
  if (quill && textarea && textarea.style.display === 'none') {
    const range = quill.getSelection(true);
    const index = (range && typeof range.index === 'number') ? range.index : (quill.getLength() - 1);
    quill.insertText(index, token, 'user');
    quill.setSelection(index + token.length);
    quill.focus();
    textarea.value = quill.root.innerHTML === '<p><br></p>' ? '' : quill.root.innerHTML;
    return;
  }

  // If in raw HTML textarea mode
  if (textarea) {
    textarea.focus();
    if (typeof textarea.selectionStart === 'number' && typeof textarea.selectionEnd === 'number') {
      const start = textarea.selectionStart;
      const end = textarea.selectionEnd;
      textarea.value = textarea.value.substring(0, start) + token + textarea.value.substring(end);
      textarea.selectionStart = textarea.selectionEnd = start + token.length;
    } else {
      textarea.value += token;
    }
    if (quill) {
      quill.root.innerHTML = textarea.value || '<p><br></p>';
    }
  }
}

/* Toggle HTML Source Code Mode */
function toggleHtmlSourceMode(textareaId) {
  const textarea = document.getElementById(textareaId);
  const quill = window._quillEditors ? window._quillEditors[textareaId] : null;
  if (!textarea) return;

  const btn = document.querySelector(`button[onclick*="${textareaId}"]`);
  const editorWrapper = textarea.previousElementSibling;

  if (textarea.style.display === 'none') {
    // Switch to Raw HTML Code Mode
    if (quill) {
      textarea.value = quill.root.innerHTML === '<p><br></p>' ? '' : quill.root.innerHTML;
    }
    if (editorWrapper) editorWrapper.style.display = 'none';
    textarea.style.display = 'block';
    textarea.focus();
    if (btn) {
      btn.textContent = '👁️ Visual Editor';
      btn.classList.add('btn-primary');
      btn.classList.remove('btn-secondary');
    }
  } else {
    // Switch back to Visual WYSIWYG Mode
    if (quill) {
      quill.root.innerHTML = textarea.value || '<p><br></p>';
    }
    textarea.style.display = 'none';
    if (editorWrapper) editorWrapper.style.display = 'block';
    if (quill) quill.focus();
    if (btn) {
      btn.textContent = '<> HTML Source';
      btn.classList.remove('btn-primary');
      btn.classList.add('btn-secondary');
    }
  }
}

/* Multi-file Image Upload Button Handler */
async function handleStepMultiImageUpload(event, pid, stepIndex) {
  const files = event.target.files;
  if (!files || !files.length) return;
  
  const elId = `${pid}-body-${stepIndex}`;
  const quill = window._quillEditors ? window._quillEditors[elId] : null;
  const textarea = document.getElementById(elId);
  const token = (typeof S !== 'undefined' && S?.token) ? S.token : (localStorage.getItem('token') || '');

  for (let idx = 0; idx < files.length; idx++) {
    const file = files[idx];
    const formData = new FormData();
    formData.append('image', file);

    try {
      const res = await fetch(API('images'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Authorization': 'Bearer ' + token },
        body: formData
      }).then(r => r.json());

      if (res && res.ok && res.url) {
        if (Array.isArray(window.allImages) && res.id) {
          window.allImages.push({ id: res.id, url: res.url, name: file.name });
        }
        if (quill && textarea && textarea.style.display === 'none') {
          const range = quill.getSelection(true);
          const index = (range && typeof range.index === 'number') ? range.index : (quill.getLength() - 1);
          quill.insertEmbed(index, 'image', res.url, 'user');
          quill.setSelection(index + 1);
          textarea.value = quill.root.innerHTML === '<p><br></p>' ? '' : quill.root.innerHTML;
        } else if (textarea) {
          insertVariableToEditor(elId, `<img src="${res.url}" alt="${file.name}" style="max-width:100%;height:auto" />\n`);
        }
      }
    } catch (e) {
      console.error('Failed to upload image:', file.name, e);
    }
  }
  event.target.value = '';
}

/* Destroy Step Editors */
function destroyStepEditors(prefix) {
  if (!window._quillEditors) return;
  const keys = Object.keys(window._quillEditors);
  for (const k of keys) {
    if (!prefix || k.startsWith(prefix === 'ar' ? 'ars' : 'fus')) {
      delete window._quillEditors[k];
    }
  }
}

/* Initialize Quill instances on all step cards */
function initStepEditors(prefix) {
  if (typeof Quill === 'undefined') {
    setTimeout(() => initStepEditors(prefix), 100);
    return;
  }

  const pid = prefix === 'ar' ? 'ars' : 'fus';
  const stepsArr = prefix === 'ar' ? arSteps : fuSteps;

  stepsArr.forEach((st, i) => {
    const quillElId = `${pid}-quill-${i}`;
    const textareaId = `${pid}-body-${i}`;
    const quillDiv = document.getElementById(quillElId);
    const textarea = document.getElementById(textareaId);
    if (!quillDiv || !textarea) return;

    // Destroy existing toolbar if re-rendering
    const parent = quillDiv.parentElement;
    if (parent) {
      const oldToolbar = parent.querySelector('.ql-toolbar');
      if (oldToolbar) oldToolbar.remove();
    }
    delete window._quillEditors[textareaId];

    const toolbarOptions = [
      [{ 'header': [1, 2, 3, 4, 5, 6, false] }],
      [{ 'size': ['small', false, 'large', 'huge'] }],
      [{ 'font': [] }],
      ['bold', 'italic', 'underline', 'strike'],
      [{ 'color': [] }, { 'background': [] }],
      [{ 'align': [] }],
      [{ 'list': 'ordered'}, { 'list': 'bullet'}, { 'list': 'check' }],
      [{ 'script': 'sub'}, { 'script': 'super' }],
      [{ 'indent': '-1'}, { 'indent': '+1' }],
      ['blockquote', 'code-block'],
      ['link', 'image'],
      ['clean']
    ];

    try {
      const quill = new Quill('#' + quillElId, {
        theme: 'snow',
        placeholder: 'Write your email message here... (supports bold, font sizes, links, images, variables)',
        modules: {
          toolbar: {
            container: toolbarOptions,
            handlers: {
              image: function() {
                const fileInput = document.getElementById(`${pid}-multi-img-${i}`);
                if (fileInput) fileInput.click();
              }
            }
          }
        }
      });

      if (st.html_body) {
        quill.root.innerHTML = st.html_body;
      }

      quill.on('text-change', () => {
        const html = quill.root.innerHTML === '<p><br></p>' ? '' : quill.root.innerHTML;
        textarea.value = html;
        st.html_body = html;
        syncPlainTextFromHtml(pid, i, html);
      });

      window._quillEditors[textareaId] = quill;
    } catch (err) {
      console.warn('Quill init fallback for ' + quillElId, err);
      quillDiv.style.display = 'none';
      textarea.style.display = 'block';
    }
  });
}

/* ── Live Email Step Preview with Variable Replacements ── */
function previewEmailStep(stepIndex, prefix) {
  const pid = prefix === 'ar' ? 'ars' : 'fus';
  const elId = `${pid}-body-${stepIndex}`;
  let html = '';
  const quill = window._quillEditors ? window._quillEditors[elId] : null;
  const textarea = document.getElementById(elId);

  if (textarea && textarea.style.display !== 'none') {
    html = textarea.value || '';
  } else if (quill) {
    html = quill.root.innerHTML === '<p><br></p>' ? '' : quill.root.innerHTML;
  } else if (textarea) {
    html = textarea.value || '';
  }
  
  const subEl = document.getElementById(`${pid}-sub-${stepIndex}`);
  const rawSub = (subEl && subEl.value.trim()) ? subEl.value : 'Re: Inquiry Regarding Services';

  // Dynamic variable replacement simulation
  let resolvedSub = rawSub.replace(/\{\{NAME\}\}/gi, 'John Smith')
                          .replace(/\{\{EMAIL\}\}/gi, 'john.smith@example.com')
                          .replace(/\{([^{}]+)\}/g, (match, p1) => {
                            const parts = p1.split('|');
                            return parts[Math.floor(Math.random() * parts.length)];
                          });

  let resolvedHtml = html.replace(/\{\{NAME\}\}/gi, 'John Smith')
                         .replace(/\{\{EMAIL\}\}/gi, 'john.smith@example.com')
                         .replace(/\{\{IMAGE\}\}/gi, '<img src="https://images.unsplash.com/photo-1579273166152-d725a4e2b755?w=600&auto=format&fit=crop&q=80" style="max-width:100%;border-radius:6px" alt="Sample Email Image" />')
                         .replace(/\{([^{}]+)\}/g, (match, p1) => {
                            const parts = p1.split('|');
                            return parts[Math.floor(Math.random() * parts.length)];
                         });

  $('tmpl-prev-title').textContent = `👁️ Preview: ${resolvedSub || 'Email #' + (stepIndex + 1)}`;
  const ifr = document.getElementById('template-preview-iframe');
  if (ifr) {
    ifr.srcdoc = `
      <!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
      <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; padding: 20px; color: #1e293b; line-height: 1.6; background: #ffffff; margin: 0; }
        img { max-width: 100%; height: auto; }
        .email-meta { background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 12px 16px; margin: -20px -20px 20px -20px; font-size: 12px; color: #64748b; font-family: monospace; }
        .email-meta strong { color: #0f172a; }
      </style>
      </head>
      <body>
        <div class="email-meta">
          <div><strong>From:</strong> Support &lt;support@company.com&gt;</div>
          <div><strong>To:</strong> John Smith &lt;john.smith@example.com&gt;</div>
          <div><strong>Subject:</strong> ${esc(resolvedSub)}</div>
        </div>
        <div class="email-content" style="margin-top:16px">
          ${resolvedHtml || '<p style="color:#94a3b8;font-style:italic">No message content entered yet.</p>'}
        </div>
      </body></html>
    `;
  }
  switchPreviewDevice('desktop');
  showModal('template-preview-modal');
}

/* ── Auto-Reply 5-Second Autosave Draft Engine ── */
let _arAutosaveTimer = null;

function initArAutosave() {
  if (_arAutosaveTimer) clearInterval(_arAutosaveTimer);
  _arAutosaveTimer = setInterval(() => {
    const modal = document.getElementById('ar-modal');
    if (modal && modal.classList.contains('on')) {
      saveArDraft();
    }
  }, 5000);
}

function saveArDraft() {
  arSaveCurrentSteps();
  const name = v('ar-name');
  if (!name && (!arSteps.length || !arSteps[0].html_body)) return;
  
  const draft = {
    name,
    status: $('ar-status')?.value || 'active',
    mode: document.querySelector('input[name="ar-mode"]:checked')?.value || '0',
    steps: arSteps,
    timestamp: Date.now(),
    arEid
  };
  try {
    localStorage.setItem('mailszo_ar_draft', JSON.stringify(draft));
  } catch (_e) {}
}

function checkAndRestoreArDraft() {
  const raw = localStorage.getItem('mailszo_ar_draft');
  if (!raw) return false;
  try {
    const draft = JSON.parse(raw);
    if (!draft || !draft.timestamp) return false;
    if (Date.now() - draft.timestamp > 86400000) {
      localStorage.removeItem('mailszo_ar_draft');
      return false;
    }
    if (arEid && draft.arEid !== arEid) return false;
    if (!arEid && draft.arEid) return false;

    const timeAgoMin = Math.max(1, Math.round((Date.now() - draft.timestamp) / 60000));
    const banner = document.getElementById('ar-draft-banner');
    if (banner) {
      banner.style.display = 'flex';
      banner.innerHTML = `
        <div style="flex:1">
          💾 <strong>Autosaved draft restored</strong> (${timeAgoMin} minute${timeAgoMin > 1 ? 's' : ''} ago).
        </div>
        <button type="button" class="btn btn-secondary btn-sm" onclick="discardArDraft()" style="padding:2px 8px;font-size:11px">Discard Draft</button>
      `;
    }

    sv('ar-name', draft.name || '');
    if ($('ar-status') && draft.status) $('ar-status').value = draft.status;
    const modeRadio = document.querySelector(`input[name="ar-mode"][value="${draft.mode || '0'}"]`);
    if (modeRadio) modeRadio.checked = true;

    if (Array.isArray(draft.steps) && draft.steps.length) {
      arSteps = draft.steps;
      renderArSteps();
    }
    return true;
  } catch (_e) {
    return false;
  }
}

function discardArDraft() {
  localStorage.removeItem('mailszo_ar_draft');
  const banner = document.getElementById('ar-draft-banner');
  if (banner) banner.style.display = 'none';
  if (!arEid) {
    sv('ar-name', '');
    arSteps = [];
    arAddStep();
  } else {
    editAr(arEid);
  }
}

// Auto-start draft timer on boot
initArAutosave();
function fmt(n){if(n==null||n==='')return'—';return Number(n).toLocaleString();}
function vc(c){try{const v=JSON.parse(c.variants||'[]');return Array.isArray(v)&&v.length?v.length:1;}catch(e){return 1;}}
function sids(c){try{if(c.smtp_ids){const v=JSON.parse(c.smtp_ids);if(Array.isArray(v))return v;}}catch(e){}return c.smtp_id?[c.smtp_id]:[];}
function sbadge(s){const m={scheduled:'b-blue',running:'b-green',paused:'b-amber',completed:'b-gray',failed:'b-red'};return`<span class="badge ${m[s]||'b-gray'}">${s}</span>`;}
function parseJ(txt){const s=txt.indexOf('{');if(s>=0){try{return JSON.parse(txt.slice(s));}catch(e){}}const a=txt.indexOf('[');if(a>=0){try{return JSON.parse(txt.slice(a));}catch(e){}}return null;}
function checkAuthRes(d){
  if(d && typeof d === 'object' && !Array.isArray(d)){
    if(d.error === 'Unauthorized' || d.error === 'Session invalid'){
      if(typeof showLoginScreen === 'function') showLoginScreen();
      return true;
    }
  }
  return false;
}
async function get(r){try{const res=await fetch(API(r),{credentials:'same-origin'});const txt=await res.text();const d=parseJ(txt);if(checkAuthRes(d))return null;return d;}catch(e){return null;}}
async function post(r,b){try{const res=await fetch(API(r),{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(b)});const txt=await res.text();const d=parseJ(txt);if(checkAuthRes(d))return{ok:false,message:'Session invalid'};return d||{ok:false,message:'Server error ('+res.status+'): '+txt.substring(0,300)};}catch(e){return{ok:false,message:e.message};}}
async function put(r,b){try{const res=await fetch(API(r),{method:'PUT',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(b)});const txt=await res.text();const d=parseJ(txt);if(checkAuthRes(d))return{ok:false,message:'Session invalid'};return d||{ok:false,message:'Server error ('+res.status+'): '+txt.substring(0,300)};}catch(e){return{ok:false,message:e.message};}}
async function del(r){try{const res=await fetch(API(r),{method:'DELETE',credentials:'same-origin'});const txt=await res.text();const d=parseJ(txt);if(checkAuthRes(d))return{error:'Session invalid'};return d||{error:'Delete failed'};}catch(e){return{error:e.message};}}
/* ─── Leads Manager ─────────────────────── */
let leadsDebTimer=null;
function leadsSearchDebounce(){clearTimeout(leadsDebTimer);leadsDebTimer=setTimeout(()=>loadLeadsTable(1),350);}

/* Show/hide the list sub-filter whenever the source dropdown changes */
function leadsOnSourceChange(){
  const src=$('leads-src-filter')?.value||'all';
  const lf=$('leads-list-filter');
  if(lf) lf.style.display=(src==='lists'||src==='all')?'':'none';
  loadLeadsTable(1);
}

async function loadLeadsPage(){
  // ── Stat counters: fetch each source total independently ────────
  const [listData,arData,fuData]=await Promise.all([
    get('leads?source=lists&page=1'),
    get('leads?source=autoreply&page=1'),
    get('leads?source=followup&page=1')
  ]);
  const listTotal=(listData?.total??0);
  const arTotal  =(arData?.total  ??0);
  const fuTotal  =(fuData?.total  ??0);
  set('leads-stat-lists', fmt(listTotal));
  set('leads-stat-ar',    fmt(arTotal));
  set('leads-stat-fu',    fmt(fuTotal));
  set('leads-stat-total', fmt(listTotal+arTotal+fuTotal));

  // ── Populate all dropdowns ───────────────────────────────────────
  const lists=await get('lists');
  const safeList=lists||[];

  // Export-card list filter
  const expSel=$('exp-list');
  if(expSel){
    expSel.innerHTML='<option value="">All Lists</option>';
    safeList.forEach(l=>{const o=document.createElement('option');o.value=l.id;o.textContent=esc(l.name)+' ('+fmt(l.total_count)+')';expSel.appendChild(o);});
  }

  // Table list filter — preserve current selection across reloads
  const llf=$('leads-list-filter');
  if(llf){
    const prev=llf.value;
    llf.innerHTML='<option value="">All Lists</option>';
    safeList.forEach(l=>{const o=document.createElement('option');o.value=String(l.id);o.textContent=esc(l.name)+' ('+fmt(l.total_count)+')';if(String(l.id)===prev)o.selected=true;llf.appendChild(o);});
  }

  // Clear-card list select
  const clL=$('cl-list');
  if(clL){
    clL.innerHTML='<option value="">— Select List —</option>';
    safeList.forEach(l=>{const o=document.createElement('option');o.value=l.id;o.textContent=esc(l.name)+' ('+fmt(l.total_count)+')';clL.appendChild(o);});
  }

  // Auto-reply and follow-up rule selects for clear card
  const [arR,fuR]=await Promise.all([get('autoreply'),get('followup')]);
  const clAr=$('cl-autoreply');
  if(clAr){
    clAr.innerHTML='<option value="">— Select Rule —</option>';
    (arR||[]).forEach(r=>{const o=document.createElement('option');o.value=r.id;o.textContent=esc(r.name)+' ('+((r.active_threads??0))+' threads)';clAr.appendChild(o);});
  }
  const clFu=$('cl-followup');
  if(clFu){
    clFu.innerHTML='<option value="">— Select Rule —</option>';
    (fuR||[]).forEach(r=>{const o=document.createElement('option');o.value=r.id;o.textContent=esc(r.name)+' ('+(r.active_contacts??0)+' contacts)';clFu.appendChild(o);});
  }

  loadLeadsTable(1);
}

async function loadLeadsTable(page=1){
  const src=$('leads-src-filter')?.value||'all';
  const q=$('leads-search')?.value||'';
  const listId=$('leads-list-filter')?.value||'';
  const tb=$('leads-body');
  if(tb) tb.innerHTML='<tr class="empty-row"><td colspan="6">Loading…</td></tr>';
  try{
    let url='leads?source='+encodeURIComponent(src)+'&page='+page;
    if(q) url+='&q='+encodeURIComponent(q);
    if(listId&&(src==='lists'||src==='all')) url+='&list_id='+encodeURIComponent(listId);
    const r=await get(url);
    if(!r||!tb){
      if(tb) tb.innerHTML='<tr class="empty-row"><td colspan="6">Could not load leads — check your connection and try again</td></tr>';
      renderPager('leads-pager',0,page,loadLeadsTable);
      return;
    }
    if(r.error&&!r.rows?.length){
      tb.innerHTML='<tr class="empty-row"><td colspan="6">⚠️ '+esc(r.error)+'</td></tr>';
      renderPager('leads-pager',0,page,loadLeadsTable);
      return;
    }
    const srcBadge={email_list:'<span class="badge b-green">📋 List</span>',auto_reply:'<span class="badge b-blue">🔁 Auto-Reply</span>',follow_up:'<span class="badge b-amber">📬 Follow-Up</span>'};
    if(!r.rows?.length){tb.innerHTML='<tr class="empty-row"><td colspan="6">No leads found</td></tr>';renderPager('leads-pager',0,page,loadLeadsTable);return;}
    tb.innerHTML=r.rows.map(l=>{
      // Per-row Delete button. Disabled if the row didn't carry a primary
      // key (shouldn't happen with the patched SELECT, but be defensive).
      const canDel = l._id && l.source;
      const delBtn = canDel
        ? `<button class="btn btn-danger btn-sm" onclick="deleteLead(${l._id},'${esc(l.source)}','${esc((l.email||'').replace(/'/g,"&#39;"))}')" title="Delete this lead">🗑 Delete</button>`
        : `<span style="color:var(--text3);font-size:11px">—</span>`;
      return `<tr>
        <td class="mono" style="font-size:11px">${esc(l.email)}</td>
        <td style="font-size:12px">${esc(l.name||'—')}</td>
        <td style="font-size:12px">${esc(l.list_name||'—')}</td>
        <td>${srcBadge[l.source]||esc(l.source)}</td>
        <td style="font-size:11px;color:var(--text2)">${(l.created_at||'').slice(0,10)}</td>
        <td>${delBtn}</td>
      </tr>`;
    }).join('');
    renderPager('leads-pager',r.pages,page,loadLeadsTable);
  }catch(err){
    if(tb) tb.innerHTML='<tr class="empty-row"><td colspan="6">Error loading leads: '+esc(err.message||String(err))+'</td></tr>';
  }
}

// Per-lead delete handler. Hits DELETE /leads/item with source + row_id;
// the api.php endpoint enforces ownership and removes the row from the
// originating table (emails / autoreply_threads / followup_contacts).
async function deleteLead(rowId, source, email){
  if (!rowId || !source) return;
  const label = email ? ('lead '+email) : 'this lead';
  if (!confirm('Delete '+label+'?\n\nThis removes the row from its source list/rule. The contact can be re-enrolled later if a matching email arrives again.')) return;
  try {
    const url = 'api.php?r=leads/item&source='+encodeURIComponent(source)+'&row_id='+encodeURIComponent(rowId);
    const res = await fetch(url, { method:'DELETE', credentials:'same-origin' });
    const j   = await res.json().catch(()=>({ok:false,message:'Bad response'}));
    if (j && j.ok) {
      // Refresh the table + the stat counters at the top of the page.
      loadLeadsPage();
    } else {
      alert('Delete failed: ' + (j && j.message ? j.message : 'Unknown error'));
    }
  } catch (err) {
    alert('Delete failed: ' + (err.message || String(err)));
  }
}

function updateExpListVis(){
  const src=$('exp-source').value;
  $('exp-list-wrap').style.display=(src==='lists'||src==='all')?'':'none';
}

/* Export using a hidden <a> tag — reliable in all browsers, no popup blocker issues */
function _triggerDownload(url){
  const a=document.createElement('a');
  a.href=url;
  a.download='';
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
}

/* Export from the Export Card — uses card's own source + list selects */
function exportLeads(forceSrc){
  const src=forceSrc||$('exp-source')?.value||'all';
  const listId=$('exp-list')?.value||'';
  let url='api.php?r=leads/export&source='+encodeURIComponent(src);
  if(listId&&(src==='lists'||src==='all')) url+='&list_id='+encodeURIComponent(listId);
  _triggerDownload(url);
}

/* Export from the table toolbar — uses the table's active source + list + search filters */
function exportLeadsFromTable(){
  const src=$('leads-src-filter')?.value||'all';
  const listId=$('leads-list-filter')?.value||'';
  const q=$('leads-search')?.value||'';
  let url='api.php?r=leads/export&source='+encodeURIComponent(src);
  if(listId&&(src==='lists'||src==='all')) url+='&list_id='+encodeURIComponent(listId);
  if(q) url+='&q='+encodeURIComponent(q);
  _triggerDownload(url);
}

async function clearLeads(target){
  let targetId=0, label='';
  if(target==='list'){
    targetId=parseInt($('cl-list')?.value)||0;
    if(!targetId){al('leads-al','Please select a list to clear','err');return;}
    label='all emails from the selected list';
  } else if(target==='autoreply'){
    targetId=parseInt($('cl-autoreply')?.value)||0;
    if(!targetId){al('leads-al','Please select an auto-reply rule to reset','err');return;}
    label='all threads for the selected auto-reply rule';
  } else if(target==='followup'){
    targetId=parseInt($('cl-followup')?.value)||0;
    if(!targetId){al('leads-al','Please select a follow-up rule to clear','err');return;}
    label='all contacts for the selected follow-up rule';
  } else if(target==='all'){
    label='ALL leads from all lists, auto-reply threads, and follow-up contacts';
  }
  if(!confirm('⚠️ This will permanently delete '+label+'.\n\nThis CANNOT be undone. Continue?')) return;
  al('leads-al','⏳ Clearing…','inf');
  let params='target='+encodeURIComponent(target);
  if(targetId) params+='&target_id='+targetId;
  try{
    const res=await fetch('api.php?r=leads/clear&'+params,{method:'DELETE',credentials:'same-origin'});
    const txt=await res.text();
    const r=parseJ(txt)||{};
    if(r?.ok){
      al('leads-al','✅ Cleared '+fmt(r.cleared)+' record(s) successfully','ok');
      loadLeadsPage();
      if(target==='list'||target==='all') loadLists();
      if(target==='autoreply'||target==='all') loadAutoreply();
      if(target==='followup'||target==='all') loadFollowup();
    } else {
      al('leads-al',r?.message||r?.error||'Clear failed — please try again','err');
    }
  }catch(e){al('leads-al','Network error: '+e.message,'err');}
}


function renderPager(elId,pages,current,loadFn){
  const el=document.getElementById(elId);if(!el)return;
  if(!pages||pages<=1){el.innerHTML='';return;}
  let html='';
  if(current>1)html+=`<button class="btn btn-secondary btn-sm" onclick="${loadFn.name}(${current-1})">← Prev</button>`;
  // Show window of pages
  const start=Math.max(1,current-2),end=Math.min(pages,current+2);
  if(start>1)html+=`<button class="btn btn-secondary btn-sm" onclick="${loadFn.name}(1)">1</button>${start>2?'<span style="color:var(--text3)">…</span>':''}`;
  for(let p=start;p<=end;p++){
    html+=`<button class="btn btn-sm ${p===current?'btn-primary':'btn-secondary'}" onclick="${loadFn.name}(${p})">${p}</button>`;
  }
  if(end<pages)html+=`${end<pages-1?'<span style="color:var(--text3)">…</span>':''}<button class="btn btn-secondary btn-sm" onclick="${loadFn.name}(${pages})">${pages}</button>`;
  if(current<pages)html+=`<button class="btn btn-secondary btn-sm" onclick="${loadFn.name}(${current+1})">Next →</button>`;
  html+=`<span style="font-size:11px;color:var(--text3)">Page ${current} of ${pages}</span>`;
  el.innerHTML=html;
}

/* ══════════════════════════════════════════════════════════════════
   IMAP ACCOUNTS
   ══════════════════════════════════════════════════════════════════ */
let allImaps=[], imapEid=null;

// Admin-only: load + save the per-cron-run IMAP read cap. The card is hidden
// for non-admin users via the existing .admin-only class on the dashboard
// body, so non-admin users won't even fire the GET.
async function loadImapReadLimit(){
  if (!S.isAdmin) return;
  const card = document.getElementById('imap-readlimit-card');
  if (card) card.style.display = 'block';
  const r = await get('imap/read-limit');
  if (r && r.ok && r.imap_read_per_minute) {
    sv('imap-readlimit-inp', r.imap_read_per_minute);
  }
}
async function saveImapReadLimit(){
  const val = parseInt(v('imap-readlimit-inp')) || 0;
  if (val < 1) { al('imap-readlimit-al','Limit must be at least 1','err'); return; }
  if (val > 5000) { al('imap-readlimit-al','Limit cannot exceed 5000','err'); return; }
  const r = await post('imap/read-limit',{ imap_read_per_minute: val });
  if (r?.ok) {
    al('imap-readlimit-al','✅ '+(r.message || ('Limit saved: '+val)),'ok');
  } else {
    al('imap-readlimit-al', r?.message || 'Save failed','err');
  }
}

async function loadImap(){
  loadImapReadLimit();
  const rows=await get('imap');
  allImaps = Array.from(new Map((rows||[]).map(x=>[String(x.id),x])).values());
  const tb=$('imap-body');
  if(!rows?.length){
    tb.innerHTML='<tr class="empty-row"><td colspan="7">'+(S.isAdmin?'No IMAP accounts yet':'No IMAP accounts yet — click "+ Add IMAP Account" to add your own, or contact admin to assign you one.')+'</td></tr>';
    return;
  }
  tb.innerHTML=rows.map(a=>`<tr>
    <td><strong>${esc(a.name)}</strong>${a.is_assigned?'<br><span style="font-size:10px;color:var(--accent2)">📌 Assigned by Admin</span>':''}</td>
    <td class="mono" style="font-size:11px">${esc(a.host)}:${a.port}</td>
    <td class="mono" style="font-size:11px">${esc(a.username)}</td>
    <td>${a.ssl=='1'||a.ssl===1?'<span class="badge b-green">SSL</span>':'<span class="badge b-gray">No SSL</span>'}</td>
    <td style="font-size:11px;color:var(--text2)">${a.last_check||'Never'}<br><small style="color:var(--text3)">UID: ${a.last_uid||0} | read: ${a.emails_read||0}</small></td>
    <td>${a.status==='active'?'<span class="badge b-green">Active</span>':'<span class="badge b-amber">⏸ Paused</span>'}</td>
    <td><div class="btn-group">
      ${(S.isAdmin || a.is_own)
        ? `<button class="btn btn-blue btn-sm" onclick="testImapById(${a.id})">🔍 Test</button>
           <button class="btn btn-secondary btn-sm" onclick="openImapModal(${a.id})">Edit</button>
           ${S.isAdmin?`<button class="btn ${a.status==='active'?'btn-amber':'btn-success'} btn-sm" id="imap-toggle-${a.id}" onclick="toggleImapStatus(${a.id},this)" title="${a.status==='active'?'Pause — cron will stop reading this inbox':'Resume — cron will resume reading this inbox'}">${a.status==='active'?'⏸ Pause':'▶ Resume'}</button>
           <button class="btn btn-amber btn-sm" onclick="resetImapUid(${a.id})" title="Reset UID tracker — next cron will re-scan all messages">↺ Reset UID</button>`:''}
           <button class="btn btn-danger btn-sm" onclick="delImap(${a.id})">Del</button>`
        : '<span style="font-size:11px;color:var(--text3)">Assigned by Admin</span>'}
    </div></td>
  </tr>`).join('');
}

function openImapModal(id=null){
  imapEid=id;
  const a=id?allImaps.find(x=>x.id==id):null;
  $('imap-modal-title').textContent=id?'✏️ Edit IMAP Account':'📥 Add IMAP Account';
  al2('imap-al');
  sv('im-name',a?.name||''); sv('im-host',a?.host||'');
  sv('im-port',a?.port||993); $('im-ssl').value=String(a?.ssl??1);
  sv('im-user',a?.username||''); sv('im-pass','');
  showModal('imap-modal');
}

async function testImap(){
  const btn = window.event?.target || document.activeElement;
  if(btn && btn.tagName === 'BUTTON') { btn.textContent='Testing…'; btn.disabled=true; }
  al2('imap-al');
  const r=await post('imap/'+(imapEid||0)+'/test',{host:v('im-host'),port:parseInt(v('im-port'))||993,username:v('im-user'),password:v('im-pass')||'__keep__',ssl:parseInt($('im-ssl').value)});
  if(btn && btn.tagName === 'BUTTON') { btn.textContent='🔍 Test Connection'; btn.disabled=false; }
  al('imap-al',r?.message||'Error',r?.ok?'ok':'err');
}

async function testImapById(id){
  const r=await post('imap/'+id+'/test',{});
  alert(r?.message||'Error');
}

async function saveImap(){
  const name=v('im-name'),host=v('im-host'),user=v('im-user'),pass=v('im-pass');
  if(!name||!host||!user){al('imap-al','Name, host and username required','err');return;}
  if(!imapEid&&!pass){al('imap-al','Password required for new account','err');return;}
  const btn=$('imap-save-btn');btn.disabled=true;btn.innerHTML='<span class="spin-ic"></span>';
  const payload={name,host,port:parseInt(v('im-port'))||993,username:user,ssl:parseInt($('im-ssl').value)};
  if(pass) payload.password=pass;
  const r=imapEid?await put('imap/'+imapEid,payload):await post('imap',payload);
  btn.disabled=false;btn.textContent='Save Account';
  if(r?.ok){closeModal('imap-modal');loadImap();}
  else al('imap-al',r?.message||r?.error||'Save failed — check server logs','err');
}

async function delImap(id){
  if(!confirm('Delete this IMAP account? Auto-Reply rules using it will lose their IMAP connection.'))return;
  await del('imap/'+id); loadImap();
}

async function resetImapUid(id){
  if(!confirm('Reset UID tracker for this IMAP account?\n\nThe next cron run will re-scan ALL messages in the inbox from the beginning.\n\nUse this if the system is missing incoming emails.'))return;
  const r=await post('imap/'+id+'/reset-uid',{});
  if(r?.ok){alert('✅ UID reset! Run cron manually or wait for the next scheduled run.');loadImap();}
  else alert(r?.message||'Reset failed');
}

async function toggleImapStatus(id, btn){
  const isPausing = btn.textContent.trim().startsWith('⏸');
  const action    = isPausing ? 'pause' : 'resume';
  if(!confirm((isPausing?'⏸ Pause':'▶ Resume')+' this IMAP account?\n\n'+(isPausing?'Cron will stop reading this inbox until you resume it.':'Cron will resume reading this inbox on the next scheduled run.')))return;
  btn.disabled=true; btn.textContent='…';
  const r=await post('imap/'+id+'/toggle-status',{});
  btn.disabled=false;
  if(r?.ok){
    // Update button and badge in-place without full reload
    const isNowActive=(r.status==='active');
    btn.textContent=isNowActive?'⏸ Pause':'▶ Resume';
    btn.className='btn '+(isNowActive?'btn-amber':'btn-success')+' btn-sm';
    btn.title=isNowActive?'Pause — cron will stop reading this inbox':'Resume — cron will resume reading this inbox';
    // Update the status badge cell (2 cells before the actions cell)
    const row=btn.closest('tr');
    if(row){
      const cells=row.querySelectorAll('td');
      // Status is 6th cell (index 5)
      if(cells[5]) cells[5].innerHTML=isNowActive
        ?'<span class="badge b-green">Active</span>'
        :'<span class="badge b-amber">⏸ Paused</span>';
    }
    // Update allImaps cache
    const cached=allImaps.find(a=>a.id==id);
    if(cached) cached.status=r.status;
  } else {
    alert(r?.message||'Toggle failed');
    loadImap();
  }
}


/* ══════════════════════════════════════════════════════════════════
   AUTO-REPLY
   ══════════════════════════════════════════════════════════════════ */
let allAr=[], arEid=null, arSteps=[];

async function loadAutoreply(){
  // reload IMAP list too
  if(!allImaps.length) await loadImap();
  const rows=await get('autoreply'); allAr=rows||[];
  const tb=$('ar-body');
  if(!rows?.length){tb.innerHTML='<tr class="empty-row"><td colspan="7">No auto-reply rules yet</td></tr>';return;}
  tb.innerHTML=rows.map(r=>`<tr>
    <td><strong>${esc(r.name)}</strong>${r.owner?`<br><small style="color:var(--text3)">@${esc(r.owner)}</small>`:''}<br><span class="badge ${r.sequential_mode==1?'b-purple':'b-gray'}" style="font-size:9px;margin-top:2px">${r.sequential_mode==1?'🔄 Sequential':'⏱ Time-Based'}</span></td>
    <td>${r.imap_name?`<span class="badge b-blue">📥 ${esc(r.imap_name)}</span>`:'<span class="badge b-red">⚠ No IMAP</span>'}</td>
    <td><span class="badge b-purple">${(r.steps||[]).length} replies</span></td>
    <td><span class="badge b-amber">${r.active_threads||0} active</span> <span class="badge b-gray">${r.total_threads||0} total</span></td>
    <td class="mono" style="color:var(--accent)">${fmt(r.total_sent||0)}</td>
    <td>${r.status==='active'?'<span class="badge b-green">✅ Active</span>':'<span class="badge b-amber">⏸ Paused</span>'}</td>
    <td><div class="btn-group">
      <button class="btn btn-secondary btn-sm" onclick="editAr(${r.id})">Edit</button>
      <button class="btn btn-secondary btn-sm" onclick="openDupModal('autoreply', ${r.id}, '${esc(r.name)}')">Copy</button>
      <button class="btn btn-blue btn-sm" onclick="openArThreads(${r.id},'${esc(r.name)}')">🧵 Threads</button>
      <button class="btn btn-blue btn-sm" onclick="openArLogs(${r.id},'${esc(r.name)}')">📋 Logs</button>
      <button class="btn btn-secondary btn-sm" onclick="arTestSend(${r.id})" title="Send test email to verify images">🧪 Test</button>
      ${r.status==='active'?`<button class="btn btn-amber btn-sm" onclick="arToggle(${r.id},'pause')">⏸</button>`:`<button class="btn btn-primary btn-sm" onclick="arToggle(${r.id},'resume')">▶</button>`}
      <button class="btn btn-danger btn-sm" onclick="delAr(${r.id})">Del</button>
    </div></td>
  </tr>`).join('');
}

async function arToggle(id,a){await post('autoreply/'+id+'/'+a,{});loadAutoreply();}
async function delAr(id){if(!confirm('Delete this auto-reply rule?'))return;await del('autoreply/'+id);loadAutoreply();}

async function arTestSend(id){
  const to=prompt('Send test email to (your email address):','');
  if(!to||!to.includes('@'))return;
  const r=await post('autoreply/'+id+'/test-send',{to,step:1});
  if(r?.ok){
    alert('✅ '+r.message+'\n\nDebug info:\n• image_ids in DB: '+(r.debug?.image_ids_raw||'empty')+'\n• Images resolved: '+(r.debug?.inline_images||0)+(r.debug?.files?'\n• Files: '+r.debug.files.join(', '):''));
  } else {
    alert('❌ '+(r?.message||'Error')+'\n\nDebug:\n• image_ids: '+(r?.debug?.image_ids_raw||'empty')+'\n• Parsed IDs: '+JSON.stringify(r?.debug?.image_ids_parsed||[])+'\n• Images found: '+(r?.debug?.inline_images||0));
  }
}

let dupTargetType = null;
let dupTargetId = null;
async function openDupModal(type, id, oldName) {
  dupTargetType = type;
  dupTargetId = id;
  $('dup-name').value = oldName + ' (Copy)';
  const uRow = $('dup-user-row');
  if (S.isAdmin) {
    uRow.style.display = 'block';
    await loadRuleOwnerUsers();
    populateOwnerSelect('dup-user', S.id);
  } else {
    uRow.style.display = 'none';
  }
  showModal('dup-modal');
}
async function confirmDup() {
  const name = $('dup-name').value.trim();
  if(!name) return alert('Name required');
  const payload = { name: name };
  if(S.isAdmin) payload.user_id = parseInt($('dup-user').value)||S.id;
  const r = await post(dupTargetType+'/'+dupTargetId+'/duplicate', payload);
  if(r?.ok){
    al('main-al', '✅ Successfully duplicated', 'ok');
    closeModal('dup-modal');
    if(dupTargetType==='autoreply') loadAutoreply();
    else loadFollowup();
  } else {
    alert(r?.message||'Error duplicating');
  }
}

// Cache of all users for the admin-only owner picker. Loaded lazily the
// first time openArModal/openFuModal needs it.
let _ruleOwnerUsers = null;
async function loadRuleOwnerUsers(){
  if (!S.isAdmin) return [];
  if (_ruleOwnerUsers) return _ruleOwnerUsers;
  try {
    const rows = await get('users');
    _ruleOwnerUsers = Array.isArray(rows) ? rows : [];
  } catch (_e) { _ruleOwnerUsers = []; }
  return _ruleOwnerUsers;
}
function populateOwnerSelect(selId, currentUserId){
  const sel = document.getElementById(selId);
  if (!sel) return;
  const users = _ruleOwnerUsers || [];
  // Always include the current admin user as a default option so admin can
  // create rules under their own account if they want.
  sel.innerHTML = users.map(u => {
    const lbl = (u.is_admin ? '⚡ ' : '👤 ') + (u.username || ('user#'+u.id))
              + (u.status === 'suspended' ? ' (suspended)' : '');
    return `<option value="${u.id}"${(currentUserId && u.id == currentUserId)?' selected':''}>${esc(lbl)}</option>`;
  }).join('');
}

/* ── Helper: show/hide admin vs user SMTP/IMAP rows in AR modal ── */
async function applyArSmtpImapMode(){
  const isAdmin = S.isAdmin;
  // IMAP rows
  const arImapAdmin = $('ar-imap-admin-row');
  const arImapUser  = $('ar-imap-user-row');
  const arImap2Row  = $('ar-imap2-row');
  if(arImapAdmin) arImapAdmin.style.display = isAdmin ? 'block' : 'none';
  if(arImap2Row)  arImap2Row.style.display  = isAdmin ? 'block' : 'none';
  if(arImapUser)  arImapUser.style.display  = isAdmin ? 'none'  : 'block';
  // SMTP rows
  const arSmtpAdmin = $('ar-smtp-admin-row');
  const arSmtpUser  = $('ar-smtp-user-row');
  if(arSmtpAdmin) arSmtpAdmin.style.display = isAdmin ? 'block' : 'none';
  if(arSmtpUser)  arSmtpUser.style.display  = isAdmin ? 'none'  : 'block';
  // For non-admin, fill in the read-only displays using admin-assigned data
  if(!isAdmin){
    const uniqueSmtps = Array.from(new Map((allSmtps||[]).map(s=>[String(s.id),s])).values());
    const uniqueImaps = Array.from(new Map((allImaps||[]).map(a=>[String(a.id),a])).values());
    const smtpDisplay = $('ar-smtp-user-display');
    if(smtpDisplay){
      smtpDisplay.innerHTML = uniqueSmtps.length
        ? uniqueSmtps.map(s=>`<span style="display:inline-block;margin:2px 4px;background:var(--bg3);border:1px solid var(--border);border-radius:4px;padding:2px 8px;font-size:12px">🔌 <strong>${esc(s.name)}</strong> <span style="color:var(--text3)">${esc(s.from_email||'')} · ${esc(s.host||'')}</span></span>`).join('')
        : '<span style="color:var(--red)">⚠ No SMTP servers assigned yet. Contact your administrator.</span>';
    }
    const imapDisplay = $('ar-imap-user-display');
    if(imapDisplay){
      imapDisplay.innerHTML = uniqueImaps.length
        ? uniqueImaps.map(a=>`<span style="display:inline-block;margin:2px 4px;background:var(--bg3);border:1px solid var(--border);border-radius:4px;padding:2px 8px;font-size:12px">📥 <strong>${esc(a.name)}</strong> <span style="color:var(--text3)">${esc(a.username||'')} · ${esc(a.host||'')}</span></span>`).join('')
        : '<span style="color:var(--red)">⚠ No IMAP accounts assigned yet. Contact your administrator.</span>';
    }
    // Auto-select first assigned IMAP in the hidden select so save still works
    const arImapSel = $('ar-imap');
    if(arImapSel && uniqueImaps.length) populateArImap(uniqueImaps[0].id);
  }
}

function toggleArSmartRoutingUI(){
  const isSmart = $('ar-enable-smart')?.checked;
  const f = $('ar-smart-routing-fields');
  if(f) f.style.display = isSmart ? 'block' : 'none';
}

async function populateSmartRoutingSelects(r){
  const pImap = $('ar-smart-primary-imap');
  if(pImap){
    pImap.innerHTML = '<option value="">— Select Primary Gmail Inbox —</option>' +
      (allImaps||[]).map(a=>`<option value="${a.id}" ${r && (r.primary_imap_id == a.id || r.imap_id == a.id) ? 'selected' : ''}>${esc(a.name)} (${esc(a.username||a.host)})</option>`).join('');
  }
  const sImap = $('ar-smart-secondary-imap');
  if(sImap){
    sImap.innerHTML = '<option value="">— Select Secondary Mailbox —</option>' +
      (allImaps||[]).map(a=>`<option value="${a.id}" ${r && (r.secondary_imap_id == a.id || r.imap2_id == a.id) ? 'selected' : ''}>${esc(a.name)} (${esc(a.username||a.host)})</option>`).join('');
  }
  const bImap = $('ar-smart-backup-imap');
  if(bImap){
    bImap.innerHTML = '<option value="">— None (Optional Backup) —</option>' +
      (allImaps||[]).map(a=>`<option value="${a.id}" ${r && r.backup_imap_id == a.id ? 'selected' : ''}>${esc(a.name)} (${esc(a.username||a.host)})</option>`).join('');
  }
  const pSmtp = $('ar-smart-primary-smtp');
  if(pSmtp){
    pSmtp.innerHTML = '<option value="">— Select Primary SMTP #1 —</option>' +
      (allSmtps||[]).map(s=>`<option value="${s.id}" ${r && (r.primary_smtp_id == s.id || (r.step1_smtp_ids && r.step1_smtp_ids.includes(s.id))) ? 'selected' : ''}>${esc(s.name)} (${esc(s.from_email||s.host)})</option>`).join('');
  }
  const sSmtp = $('ar-smart-secondary-smtp');
  if(sSmtp){
    sSmtp.innerHTML = '<option value="">— Select Secondary SMTP #2 —</option>' +
      (allSmtps||[]).map(s=>`<option value="${s.id}" ${r && r.secondary_smtp_id == s.id ? 'selected' : ''}>${esc(s.name)} (${esc(s.from_email||s.host)})</option>`).join('');
  }
  const fuSel = $('ar-smart-fu-rule');
  if(fuSel){
    try {
      const fuRules = await get('followup');
      fuSel.innerHTML = '<option value="">— None (No Auto Follow-Up) —</option>' +
        (fuRules||[]).map(fu=>`<option value="${fu.id}" ${r && r.followup_rule_id == fu.id ? 'selected' : ''}>${esc(fu.name)} (${(fu.steps||[]).length} steps)</option>`).join('');
    } catch(e){ fuSel.innerHTML = '<option value="">— None (No Auto Follow-Up) —</option>'; }
  }
}

async function openArModal(){
  if(!allImages.length) await loadImages();
  if(!allImaps.length) await loadImap();
  if(!allSmtps.length) await loadSmtps();
  await destroyStepEditors('ar');
  arEid=null; arSteps=[];
  $('ar-modal-title').textContent='🔁 New Auto-Reply Rule';
  al2('ar-al'); sv('ar-name',''); $('ar-status').value='active';
  const banner = document.getElementById('ar-draft-banner');
  if (banner) banner.style.display = 'none';
  document.querySelector('input[name="ar-mode"][value="0"]').checked=true;
  updateArModeHint();
  renderArSmtpPool([]); clearArFromTags();

  // Smart Routing Defaults
  if($('ar-enable-smart')) $('ar-enable-smart').checked = false;
  toggleArSmartRoutingUI();
  await populateSmartRoutingSelects(null);
  if($('ar-smart-replyto-switch')) $('ar-smart-replyto-switch').checked = true;
  if($('ar-smart-always-fu')) $('ar-smart-always-fu').checked = true;
  if($('ar-smart-gmail-priority')) $('ar-smart-gmail-priority').checked = true;

  // Admin owner picker. Hidden for non-admin so users never see the field.
  const ownerRow = document.getElementById('ar-owner-row');
  if (ownerRow) ownerRow.style.display = S.isAdmin ? 'block' : 'none';
  if (S.isAdmin) {
    await loadRuleOwnerUsers();
    populateOwnerSelect('ar-owner-sel', S.uid);
  }
  populateArImap(null);
  populateArImap2(null);
  arStep1SmtpIds=[];
  arSteps=[];
  await applyArSmtpImapMode();
  await refreshArQuota();

  const draftRestored = checkAndRestoreArDraft();
  if (!draftRestored) {
    arAddStep();
  }
  showModal('ar-modal');
}

async function editAr(id){
  if(!allImages.length) await loadImages();
  if(!allImaps.length) await loadImap();
  if(!allSmtps.length) await loadSmtps();
  await destroyStepEditors('ar');
  const r=await get('autoreply/'+id); if(!r?.id){alert('Load error');return;}
  arEid=id; arSteps=[];
  $('ar-modal-title').textContent='✏️ Edit Auto-Reply Rule';
  al2('ar-al'); sv('ar-name',r.name||''); $('ar-status').value=r.status||'active';
  const banner = document.getElementById('ar-draft-banner');
  if (banner) banner.style.display = 'none';

  // Smart Routing Data
  if($('ar-enable-smart')) $('ar-enable-smart').checked = (r.enable_smart_routing == 1);
  toggleArSmartRoutingUI();
  await populateSmartRoutingSelects(r);
  if($('ar-smart-replyto-switch')) $('ar-smart-replyto-switch').checked = (r.enable_reply_to_switch != 0);
  if($('ar-smart-always-fu')) $('ar-smart-always-fu').checked = (r.enable_always_send_followup != 0);
  if($('ar-smart-gmail-priority')) $('ar-smart-gmail-priority').checked = (r.enable_gmail_priority != 0);

  const ownerRow = document.getElementById('ar-owner-row');
  if (ownerRow) ownerRow.style.display = S.isAdmin ? 'block' : 'none';
  if (S.isAdmin) {
    await loadRuleOwnerUsers();
    populateOwnerSelect('ar-owner-sel', r.user_id);
  }
  let smtpSel=[];try{if(r.smtp_ids){const d=JSON.parse(r.smtp_ids);if(Array.isArray(d))smtpSel=d;}}catch(e){}
  renderArSmtpPool(smtpSel);
  arStep1SmtpIds=[];try{if(r.step1_smtp_ids){const d=JSON.parse(r.step1_smtp_ids);if(Array.isArray(d))arStep1SmtpIds=d.map(Number);}}catch(e){}
  setArFromTags(r.from_emails||null);
  populateArImap(r.imap_id || r.primary_imap_id);
  populateArImap2(r.imap2_id || r.secondary_imap_id);
  const seqMode = r.sequential_mode==1?'1':'0';
  document.querySelector(`input[name="ar-mode"][value="${seqMode}"]`).checked=true;
  updateArModeHint();
  await applyArSmtpImapMode();
  if(r.steps?.length){
    r.steps.forEach(st=>{
      let imgIds=[];
      try{const p=st.image_ids;imgIds=Array.isArray(p)?p:(typeof p==='string'&&p?JSON.parse(p):[]);}catch(e){imgIds=[];}
      
      const rawMin = parseInt(st.delay_minutes) || 0;
      let dVal = st.delay_value != null ? parseInt(st.delay_value) : null;
      let dUnit = st.delay_unit || null;
      if(dVal == null || !dUnit){
        if(rawMin > 0 && rawMin % 1440 === 0){ dVal = rawMin / 1440; dUnit = 'days'; }
        else if(rawMin > 0 && rawMin % 60 === 0){ dVal = rawMin / 60; dUnit = 'hours'; }
        else { dVal = rawMin; dUnit = 'minutes'; }
      }

      arSteps.push({
        delay_value: dVal, delay_unit: dUnit, delay_minutes: rawMin,
        subject:st.subject||'',html_body:st.html_body||'',
        text_body:st.text_body||'',image_ids:Array.isArray(imgIds)?imgIds:[],
        img_width:st.img_width||'600',img_align:st.img_align||'center',img_position:st.img_position||'top'
      });
    });
  }
  if(!arSteps.length)arAddStep();
  renderArSteps();
  await refreshArQuota();
  showModal('ar-modal');
}

function updateArModeHint(){
  const isSeq=document.querySelector('input[name="ar-mode"]:checked')?.value==='1';
  const hint=$('ar-mode-hint-text');
  if(hint) hint.textContent=isSeq
    ? 'Sequential: Auto Reply 1 sends on first message. Each next reply ONLY sends after the user sends their next message.'
    : 'Time-based: Each reply sends automatically after its delay. User does not need to reply.';
  // Show/hide delay fields in steps
  document.querySelectorAll('.ar-delay-row').forEach(el=>el.style.display=isSeq?'none':'flex');
  document.querySelectorAll('.ar-seq-delay-row').forEach(el=>el.style.display=isSeq?'block':'none');
}
// Attach change listener to mode radios
document.addEventListener('DOMContentLoaded',()=>{
  document.querySelectorAll('input[name="ar-mode"]').forEach(r=>r.addEventListener('change',updateArModeHint));
});

function populateArImap(selectedId){
  const sel=$('ar-imap');
  sel.innerHTML='<option value="">— select IMAP account —</option>'+allImaps.map(a=>`<option value="${a.id}" ${a.id==selectedId?'selected':''}>${esc(a.name)} (${esc(a.host)})</option>`).join('');
}
function populateArImap2(selectedId){
  const sel=$('ar-imap2');
  if(!sel) return;
  sel.innerHTML='<option value="">— none (use IMAP 1 for the entire conversation) —</option>'+allImaps.map(a=>`<option value="${a.id}" ${a.id==selectedId?'selected':''}>${esc(a.name)} (${esc(a.host)})</option>`).join('');
}

function renderArSmtpPool(sel){
  const wrap=$('ar-smtp-pool');
  if(!allSmtps.length){wrap.innerHTML='<div style="color:var(--text3);font-size:12px;padding:6px">No SMTP servers.</div>';return;}
  wrap.innerHTML=allSmtps.map(s=>{
    const chk=sel.map(String).includes(String(s.id));
    return `<label class="spl ${chk?'ck':''}" ><input type="checkbox" value="${s.id}" ${chk?'checked':''} onchange="this.closest('label').classList.toggle('ck',this.checked)"><strong>${esc(s.name)}</strong> <span style="color:var(--text3);font-size:10px">${esc(s.from_email)} · ${esc(s.host)}</span></label>`;
  }).join('');
}

function clearArFromTags(){$('ar-from-wrap').querySelectorAll('.tag').forEach(t=>t.remove());}
function setArFromTags(json){clearArFromTags();let arr=[];try{if(json)arr=JSON.parse(json);}catch(e){}arr.forEach(e=>{const lbl=typeof e==='object'?(e.name?e.name+' <'+e.email+'>':e.email):e;addArTag(lbl);});}
function arFromKey(e){if(e.key==='Enter'||e.key===','){e.preventDefault();const val=e.target.value.trim();if(val){addArTag(val);e.target.value='';}}}
function addArTag(text){const wrap=$('ar-from-wrap');const t=document.createElement('div');t.className='tag';t.innerHTML=`<span>${esc(text)}</span><span class="tag-x" onclick="this.parentNode.remove()">✕</span>`;wrap.insertBefore(t,$('ar-from-inp'));}
function getArFromEmails(){return Array.from($('ar-from-wrap').querySelectorAll('.tag span:first-child')).map(t=>{const txt=t.textContent.trim();const m=txt.match(/^(.+?)\s*<(.+?)>$/);return m?{name:m[1].trim(),email:m[2].trim()}:{email:txt};});}

/* AR Steps + per-user MESSAGE quota (admin-set cap on autoreply_steps).
   The quota is fetched from /autoreply/quota when the modal opens and kept
   in `arQuota`. arAddStep() refuses to add a new message when the projected
   total ((other rules' steps) + (this rule's steps) + 1) would exceed the
   cap, and shows a notification both inline (banner) and as an alert. */
let arQuota = null; // { limit, used, remaining, unlimited } | null

async function refreshArQuota(){
  try {
    const q = await get('autoreply/quota');
    if (q && q.ok) arQuota = q;
  } catch (_e) { arQuota = null; }
  renderArQuotaBanner();
}
function arOtherRulesUsed(){
  // `used` from /quota counts ALL of the user's autoreply_steps. When
  // editing an existing rule, the rule's own current step rows in the DB
  // are part of that count, but the user is replacing them with arSteps.
  // We can't precisely know the DB count for THIS rule from the client, so
  // we approximate: the projected total is (used - <prev local count>) + len(arSteps).
  // The simplest accurate approach: server already enforces with exclude_rule
  // on PUT, so client just needs to PREVENT obvious over-cap UI states. We
  // use a conservative bound: (used - len of currently rendered steps) is
  // close enough for showing the warning. Server is the source of truth.
  if (!arQuota) return 0;
  const used = arQuota.used || 0;
  // For NEW rules we have no DB-resident steps, so subtract 0.
  // For EDITED rules approximate by subtracting current arSteps length
  // (this matches the steps already saved in the DB before this edit).
  const ownAlready = arEid ? (arSteps?.length || 0) : 0;
  return Math.max(0, used - ownAlready);
}
function arProjectedTotal(){
  return arOtherRulesUsed() + (arSteps?.length || 0);
}
function renderArQuotaBanner(){
  const el = document.getElementById('ar-quota-banner');
  const btn = document.getElementById('ar-add-step-btn');
  if (!el) return;
  if (!arQuota || arQuota.unlimited) {
    el.style.display = 'none';
    if (btn) btn.disabled = false;
    return;
  }
  const limit = arQuota.limit || 0;
  const projected = arProjectedTotal();
  const remaining = Math.max(0, limit - projected);
  el.style.display = 'block';
  if (limit <= 0) {
    el.className = 'al a-err on';
    el.innerHTML = '🚫 <strong>Auto-Reply messages are disabled</strong> for your account. Contact admin to enable them.';
    if (btn) btn.disabled = true;
  } else if (remaining <= 0) {
    el.className = 'al a-err on';
    el.innerHTML = '🚫 <strong>Message limit reached</strong> — you\'re using <strong>'+projected+' / '+limit+'</strong> auto-reply messages. Delete an existing message or ask admin to raise the limit before adding more.';
    if (btn) btn.disabled = true;
  } else if (remaining <= 2) {
    el.className = 'al a-warn on';
    el.innerHTML = '⚠️ <strong>Almost at your limit</strong> — '+projected+' of '+limit+' messages used, only '+remaining+' left.';
    if (btn) btn.disabled = false;
  } else {
    el.className = 'al a-inf on';
    el.innerHTML = '📊 Quota: <strong>'+projected+' / '+limit+'</strong> auto-reply messages used (<strong>'+remaining+'</strong> remaining).';
    if (btn) btn.disabled = false;
  }
}

function arAddStep(){
  if(arSteps.length>=15){alert('Maximum 15 replies');return;}
  // Per-user message-quota guard. Server enforces too — this is the
  // friendly client-side notification the operator asked for.
  if (arQuota && !arQuota.unlimited) {
    const limit = arQuota.limit || 0;
    if (limit <= 0) {
      alert('🚫 Auto-Reply messages are disabled for your account. Contact admin to enable them.');
      return;
    }
    if (arProjectedTotal() + 1 > limit) {
      alert('🚫 Auto-Reply message limit reached!\n\nYou\'re using '+arProjectedTotal()+' / '+limit+' messages across all your rules.\n\nDelete an existing message or ask admin to raise the limit before adding more.');
      renderArQuotaBanner();
      return;
    }
  }
  arSaveCurrentSteps();
  arSteps.push({delay_minutes:1,subject:'',html_body:'',text_body:'',image_ids:[],img_width:'600',img_align:'center',img_position:'top'});
  renderArSteps();
  renderArQuotaBanner();
}
function arRemoveStep(i){
  if(arSteps.length<=1){alert('At least 1 reply required');return;}
  arSaveCurrentSteps();arSteps.splice(i,1);renderArSteps();
  renderArQuotaBanner();
}
function arSaveCurrentSteps(){
  const isSeqNow=document.querySelector('input[name="ar-mode"]:checked')?.value==='1';
  arSteps.forEach((st,i)=>{
    const elId = `ars-body-${i}`;
    let htmlVal = '';
    const quill = window._quillEditors ? window._quillEditors[elId] : null;
    const textarea = document.getElementById(elId);

    if (textarea && textarea.style.display !== 'none') {
      htmlVal = textarea.value || '';
      if (quill) quill.root.innerHTML = htmlVal || '<p><br></p>';
    } else if (quill) {
      htmlVal = quill.root.innerHTML === '<p><br></p>' ? '' : quill.root.innerHTML;
      if (textarea) textarea.value = htmlVal;
    } else if (textarea) {
      htmlVal = textarea.value || '';
    }

    const valEl = document.getElementById('ars-delay-val-'+i);
    const unitEl = document.getElementById('ars-delay-unit-'+i);
    const legacyDelayEl = isSeqNow ? document.getElementById('ars-sdelay-'+i) : document.getElementById('ars-delay-'+i);
    
    if (valEl && unitEl) {
      st.delay_value = parseInt(valEl.value) || 0;
      st.delay_unit = unitEl.value || 'minutes';
      st.delay_minutes = st.delay_unit === 'days' ? st.delay_value * 1440 : (st.delay_unit === 'hours' ? st.delay_value * 60 : st.delay_value);
    } else if (legacyDelayEl) {
      st.delay_minutes = parseInt(legacyDelayEl.value || '0') || 0;
      st.delay_value = st.delay_minutes;
      st.delay_unit = 'minutes';
    }
    st.subject=document.getElementById('ars-sub-'+i)?.value||'';
    st.html_body=htmlVal;
    st.text_body=document.getElementById('ars-txt-'+i)?.value||'';
    st.img_width=document.getElementById('ars-imgw-'+i)?.value||'600';
    st.img_align=document.getElementById('ars-imga-'+i)?.value||'center';
    st.img_position=document.getElementById('ars-imgp-'+i)?.value||'top';
  });
}
function renderArSteps(){
  $('ar-step-label').textContent=arSteps.length+' repl'+(arSteps.length!==1?'ies':'y');
  const isSeq=document.querySelector('input[name="ar-mode"]:checked')?.value==='1';
  $('ar-steps-wrap').innerHTML=arSteps.map((st,i)=>buildStepCard(st,i,'ar','ars','arAddStep','arRemoveStep','arRmImg','openArPick',
    isSeq
      ? (i===0?'Auto Reply 1 — Sent when user sends their 1st message':'Auto Reply '+(i+1)+' — Sent when user sends their '+(i+1)+(i+1===2?'nd':i+1===3?'rd':'th')+' message')
      : (i===0?'Reply #1 — Sent immediately when first email arrives':'Reply #'+(i+1)+' — Sent after delay from previous reply')
  )).join('');
  // Show/hide delay rows based on mode
  document.querySelectorAll('.ar-delay-row').forEach(el=>el.style.display=isSeq?'none':'flex');
  document.querySelectorAll('.ar-seq-delay-row').forEach(el=>el.style.display=isSeq?'block':'none');
  // Inject dedicated SMTP pool selector into Auto Reply 1 card
  injectStep1SmtpPool();
  // Initialize Rich Text Editor instances
  initStepEditors('ar');
}

/* ── Auto Reply 1: Dedicated SMTP Pool ── */
let arStep1SmtpIds = []; // IDs selected for the first-reply dedicated pool

function injectStep1SmtpPool(){
  // Find the first step card (index 0) and prepend the dedicated SMTP panel
  const firstCard = $('ar-steps-wrap')?.querySelector(':scope > div:first-child');
  if(!firstCard) return;
  // Remove old panel if re-rendering
  const old = firstCard.querySelector('#ar-step1-smtp-panel');
  if(old) old.remove();
  // Hide dedicated SMTP pool for non-admins
  if (!S.isAdmin) return;
  // Build the panel HTML
  const panel = document.createElement('div');
  panel.id = 'ar-step1-smtp-panel';
  panel.style.cssText = 'background:rgba(251,191,36,.06);border:1.5px solid rgba(251,191,36,.35);border-radius:8px;padding:12px 14px;margin-bottom:12px';
  const noSmtp = !allSmtps.length;
  const checkboxes = noSmtp
    ? '<div style="color:var(--text3);font-size:12px;padding:4px">No SMTP servers configured.</div>'
    : allSmtps.map(s=>{
        const chk = arStep1SmtpIds.map(String).includes(String(s.id));
        return `<label class="spl ${chk?'ck':''}" style="border-color:rgba(251,191,36,.4)">
          <input type="checkbox" value="${s.id}" ${chk?'checked':''}
            onchange="arStep1SmtpToggle(parseInt(this.value),this.checked);this.closest('label').classList.toggle('ck',this.checked)">
          <strong>${esc(s.name)}</strong>
          <span style="color:var(--text3);font-size:10px">${esc(s.from_email)} · ${esc(s.host)}</span>
        </label>`;
      }).join('');
  panel.innerHTML = `
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
      <span style="font-size:13px">⚡</span>
      <span style="font-weight:700;font-size:12px">Dedicated SMTP Pool for Auto Reply 1</span>
      <span style="font-size:10px;color:var(--text3);font-weight:400">(first message only — completely independent from main pool)</span>
    </div>
    <div style="font-size:11px;color:var(--text3);margin-bottom:8px">
      If selected, <strong>only these SMTP servers</strong> will be used to send the first reply. Leave all unchecked to use the main SMTP Pool above.
    </div>
    <div class="smtp-pool" id="ar-step1-smtp-inner">${checkboxes}</div>`;
  // Insert at top of the first step card, after the title bar
  const titleBar = firstCard.querySelector('div:first-child');
  if(titleBar && titleBar.nextSibling) firstCard.insertBefore(panel, titleBar.nextSibling);
  else firstCard.prepend(panel);
}

function arStep1SmtpToggle(id, checked){
  if(checked){ if(!arStep1SmtpIds.includes(id)) arStep1SmtpIds.push(id); }
  else { arStep1SmtpIds = arStep1SmtpIds.filter(x=>x!==id); }
}

/* FU Steps + per-user MESSAGE quota — mirrors the AR implementation. */
let allFu=[], fuEid=null, fuSteps=[], fuContactsId=null;
let fuQuota = null; // { limit, used, remaining, unlimited } | null

async function refreshFuQuota(){
  try {
    const q = await get('followup/quota');
    if (q && q.ok) fuQuota = q;
  } catch (_e) { fuQuota = null; }
  renderFuQuotaBanner();
}
function fuOtherRulesUsed(){
  if (!fuQuota) return 0;
  const used = fuQuota.used || 0;
  const ownAlready = fuEid ? (fuSteps?.length || 0) : 0;
  return Math.max(0, used - ownAlready);
}
function fuProjectedTotal(){
  return fuOtherRulesUsed() + (fuSteps?.length || 0);
}
function renderFuQuotaBanner(){
  const el = document.getElementById('fu-quota-banner');
  const btn = document.getElementById('fu-add-step-btn');
  if (!el) return;
  if (!fuQuota || fuQuota.unlimited) {
    el.style.display = 'none';
    if (btn) btn.disabled = false;
    return;
  }
  const limit = fuQuota.limit || 0;
  const projected = fuProjectedTotal();
  const remaining = Math.max(0, limit - projected);
  el.style.display = 'block';
  if (limit <= 0) {
    el.className = 'al a-err on';
    el.innerHTML = '🚫 <strong>Follow-Up messages are disabled</strong> for your account. Contact admin to enable them.';
    if (btn) btn.disabled = true;
  } else if (remaining <= 0) {
    el.className = 'al a-err on';
    el.innerHTML = '🚫 <strong>Message limit reached</strong> — you\'re using <strong>'+projected+' / '+limit+'</strong> follow-up messages. Delete an existing message or ask admin to raise the limit before adding more.';
    if (btn) btn.disabled = true;
  } else if (remaining <= 2) {
    el.className = 'al a-warn on';
    el.innerHTML = '⚠️ <strong>Almost at your limit</strong> — '+projected+' of '+limit+' messages used, only '+remaining+' left.';
    if (btn) btn.disabled = false;
  } else {
    el.className = 'al a-inf on';
    el.innerHTML = '📊 Quota: <strong>'+projected+' / '+limit+'</strong> follow-up messages used (<strong>'+remaining+'</strong> remaining).';
    if (btn) btn.disabled = false;
  }
}

function fuAddStep(){
  if(fuSteps.length>=15){alert('Maximum 15 messages');return;}
  if (fuQuota && !fuQuota.unlimited) {
    const limit = fuQuota.limit || 0;
    if (limit <= 0) {
      alert('🚫 Follow-Up messages are disabled for your account. Contact admin to enable them.');
      return;
    }
    if (fuProjectedTotal() + 1 > limit) {
      alert('🚫 Follow-Up message limit reached!\n\nYou\'re using '+fuProjectedTotal()+' / '+limit+' messages across all your rules.\n\nDelete an existing message or ask admin to raise the limit before adding more.');
      renderFuQuotaBanner();
      return;
    }
  }
  fuSaveCurrentSteps();
  // Default sequential delays: Step 1 = 30m, Step 2 = 30m, Step 3 = 2h, Step 4 = 1d
  const idx = fuSteps.length;
  let dVal = 30, dUnit = 'minutes';
  if(idx === 1) { dVal = 30; dUnit = 'minutes'; }
  else if(idx === 2) { dVal = 2; dUnit = 'hours'; }
  else if(idx >= 3) { dVal = 1; dUnit = 'days'; }

  fuSteps.push({
    delay_value: dVal,
    delay_unit: dUnit,
    delay_minutes: dUnit === 'days' ? dVal * 1440 : (dUnit === 'hours' ? dVal * 60 : dVal),
    subject: '',
    html_body: '',
    text_body: '',
    image_ids: [],
    img_width: '600',
    img_align: 'center',
    img_position: 'top'
  });
  renderFuSteps();
  renderFuQuotaBanner();
}

function fuRemoveStep(i){
  if(fuSteps.length<=1){alert('At least 1 message required');return;}
  fuSaveCurrentSteps();
  fuSteps.splice(i,1);
  renderFuSteps();
  renderFuQuotaBanner();
}

function fuSaveCurrentSteps(){
  fuSteps.forEach((st,i)=>{
    const elId = `fus-body-${i}`;
    let htmlVal = '';
    const quill = window._quillEditors ? window._quillEditors[elId] : null;
    const textarea = document.getElementById(elId);

    if (textarea && textarea.style.display !== 'none') {
      htmlVal = textarea.value || '';
      if (quill) quill.root.innerHTML = htmlVal || '<p><br></p>';
    } else if (quill) {
      htmlVal = quill.root.innerHTML === '<p><br></p>' ? '' : quill.root.innerHTML;
      if (textarea) textarea.value = htmlVal;
    } else if (textarea) {
      htmlVal = textarea.value || '';
    }

    const valEl = document.getElementById('fus-delay-val-'+i);
    const unitEl = document.getElementById('fus-delay-unit-'+i);
    const legacyEl = document.getElementById('fus-delay-'+i);

    if(valEl && unitEl){
      st.delay_value = parseInt(valEl.value) || 1;
      st.delay_unit = unitEl.value || 'minutes';
      st.delay_minutes = st.delay_unit === 'days' ? st.delay_value * 1440 : (st.delay_unit === 'hours' ? st.delay_value * 60 : st.delay_value);
    } else if(legacyEl){
      st.delay_minutes = parseInt(legacyEl.value) || 60;
      st.delay_value = st.delay_minutes;
      st.delay_unit = 'minutes';
    }
    st.subject = document.getElementById('fus-sub-'+i)?.value || '';
    st.html_body = htmlVal;
    st.text_body = document.getElementById('fus-txt-'+i)?.value || '';
    st.img_width = document.getElementById('fus-imgw-'+i)?.value || '600';
    st.img_align = document.getElementById('fus-imga-'+i)?.value || 'center';
    st.img_position = document.getElementById('fus-imgp-'+i)?.value || 'top';
  });
}

function renderFuSteps(){
  $('fu-step-label').textContent = fuSteps.length + ' message' + (fuSteps.length !== 1 ? 's' : '');
  $('fu-steps-wrap').innerHTML = fuSteps.map((st,i) => buildStepCard(
    st, i, 'fu', 'fus', 'fuAddStep', 'fuRemoveStep', 'fuRmImg', 'openFuPick',
    i === 0 ? 'Follow-up #1 — Triggered after read delay' : 'Follow-up #' + (i+1) + ' — Sent after delay from previous follow-up'
  )).join('');
  renderFuTimeline();
  initStepEditors('fu');
}

/* ── Live Visual Sequence Timeline Renderer ── */
function renderFuTimeline(){
  const wrap = document.getElementById('fu-timeline-preview');
  if(!wrap) return;
  fuSaveCurrentSteps();
  if(!fuSteps.length){
    wrap.innerHTML = '<div style="color:var(--text3);font-size:12px;padding:8px">No sequence steps configured.</div>';
    return;
  }
  let h = `
    <div class="seq-node seq-start">
      <div class="seq-node-ic">🚀</div>
      <div class="seq-node-title">Initial Email Sent</div>
      <div class="seq-node-sub"><span class="seq-pulse"></span>Delay Countdown</div>
    </div>
  `;

  fuSteps.forEach((st, i) => {
    const val = st.delay_value || (st.delay_minutes || 30);
    const unit = st.delay_unit || 'minutes';
    const sub = st.subject ? esc(st.subject) : '(Empty Subject)';
    h += `
      <div class="seq-arrow-wrap">
        <span class="seq-delay-badge">+${val} ${unit}</span>
        <div class="seq-arrow-line"></div>
      </div>
      <div class="seq-node seq-step">
        <div class="seq-node-ic">📩</div>
        <div class="seq-node-title">Follow-up #${i+1}</div>
        <div class="seq-node-sub" title="${sub}">${sub.length>18 ? sub.substring(0,16)+'…' : sub}</div>
      </div>
    `;
  });

  wrap.innerHTML = h;
}

/* ── Step Reordering / Drag & Drop ── */
let _draggedStepIndex = null;
let _draggedPrefix = null;

function stepDragStart(e, i, prefix){
  _draggedStepIndex = i;
  _draggedPrefix = prefix;
  e.dataTransfer.effectAllowed = 'move';
  e.target.closest('.step-card-box')?.classList.add('step-card-dragging');
}

function stepDragOver(e){
  e.preventDefault();
  e.dataTransfer.dropEffect = 'move';
  const card = e.target.closest('.step-card-box');
  if(card) card.classList.add('step-card-dragover');
}

function stepDragLeave(e){
  const card = e.target.closest('.step-card-box');
  if(card) card.classList.remove('step-card-dragover');
}

function stepDrop(e, targetIndex, prefix){
  e.preventDefault();
  document.querySelectorAll('.step-card-box').forEach(c => {
    c.classList.remove('step-card-dragging');
    c.classList.remove('step-card-dragover');
  });
  if(_draggedStepIndex === null || _draggedStepIndex === targetIndex || _draggedPrefix !== prefix) return;
  
  if(prefix === 'fu'){
    fuSaveCurrentSteps();
    const item = fuSteps.splice(_draggedStepIndex, 1)[0];
    fuSteps.splice(targetIndex, 0, item);
    renderFuSteps();
  } else if(prefix === 'ar'){
    arSaveCurrentSteps();
    const item = arSteps.splice(_draggedStepIndex, 1)[0];
    arSteps.splice(targetIndex, 0, item);
    renderArSteps();
  }
  _draggedStepIndex = null;
  _draggedPrefix = null;
}

function moveStepUp(i, prefix){
  if(i <= 0) return;
  if(prefix === 'fu'){
    fuSaveCurrentSteps();
    const tmp = fuSteps[i];
    fuSteps[i] = fuSteps[i-1];
    fuSteps[i-1] = tmp;
    renderFuSteps();
  } else {
    arSaveCurrentSteps();
    const tmp = arSteps[i];
    arSteps[i] = arSteps[i-1];
    arSteps[i-1] = tmp;
    renderArSteps();
  }
}

function moveStepDown(i, prefix){
  const arr = prefix === 'fu' ? fuSteps : arSteps;
  if(i >= arr.length - 1) return;
  if(prefix === 'fu'){
    fuSaveCurrentSteps();
    const tmp = fuSteps[i];
    fuSteps[i] = fuSteps[i+1];
    fuSteps[i+1] = tmp;
    renderFuSteps();
  } else {
    arSaveCurrentSteps();
    const tmp = arSteps[i];
    arSteps[i] = arSteps[i+1];
    arSteps[i+1] = tmp;
    renderArSteps();
  }
}

/* Shared step card builder */
function buildStepCard(st,i,prefix,pid,addFn,rmFn,rmImgFn,pickFn,note){
  const iw=st.img_width||'600',ia=st.img_align||'center',ip=st.img_position||'top';
  const thumbs=(st.image_ids||[]).map(id=>{const img=allImages.find(x=>x.id==id);if(!img)return'';return`<div class="sel-th"><img src="${esc(img.url)}" alt=""><div class="sel-th-rm" onclick="${rmImgFn}(${i},${id})">✕</div></div>`;}).join('');
  const stepsArr=prefix==='ar'?arSteps:fuSteps;
  const dVal = st.delay_value != null ? st.delay_value : (st.delay_minutes || (prefix==='ar'?1:30));
  const dUnit = st.delay_unit || (st.delay_minutes >= 1440 && st.delay_minutes % 1440 === 0 ? 'days' : (st.delay_minutes >= 60 && st.delay_minutes % 60 === 0 ? 'hours' : 'minutes'));

  return `<div class="step-card-box" draggable="true" ondragstart="stepDragStart(event, ${i}, '${prefix}')" ondragover="stepDragOver(event)" ondragleave="stepDragLeave(event)" ondrop="stepDrop(event, ${i}, '${prefix}')" style="background:var(--bg3);border:1px solid var(--border2);border-radius:10px;padding:16px;margin-bottom:14px;transition:all .15s">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;flex-wrap:wrap">
      <span class="step-card-drag-handle" title="Drag to reorder sequence">⋮⋮ Drag</span>
      <span style="background:var(--accent);color:#000;font-weight:700;font-size:11px;padding:3px 12px;border-radius:20px">#${i+1}</span>
      <span style="font-size:11px;color:var(--text2);flex:1">${note}</span>
      <div class="btn-group" style="margin-left:auto">
        ${i>0?`<button class="btn btn-secondary btn-sm" onclick="moveStepUp(${i},'${prefix}')" title="Move Up">▲</button>`:''}
        ${i<stepsArr.length-1?`<button class="btn btn-secondary btn-sm" onclick="moveStepDown(${i},'${prefix}')" title="Move Down">▼</button>`:''}
        <button class="btn btn-purple btn-sm" onclick="openTemplatePickerForStep(${i},'${prefix}')" title="Apply a saved template">📋 Template</button>
        ${stepsArr.length>1?`<button class="btn btn-danger btn-sm" onclick="${rmFn}(${i})">✕ Remove</button>`:''}
      </div>
    </div>
    <div class="fg" style="margin:0 0 10px">
      <label class="fl" style="font-size:10px">Subject <span class="flh">(optional — defaults to "Re: [Incoming Subject]" if blank)</span></label>
      <input class="fi" id="${pid}-sub-${i}" value="${esc(st.subject||'')}" placeholder="Subject line (optional — leave blank to keep original thread subject)…" oninput="if('${prefix}'==='fu')renderFuTimeline()">
    </div>
    
    <!-- Sequential Delay Row (Unified for AR and FU) -->
    <div style="background:rgba(74,222,128,0.04);border:1px solid rgba(74,222,128,0.15);border-radius:8px;padding:10px 12px;margin-bottom:12px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
      <div style="font-size:11px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:6px">
        <span>⏱️</span>
        <span>Delay Time:</span>
      </div>
      <div style="display:flex;align-items:center;gap:6px">
        <input class="fi" id="${pid}-delay-val-${i}" type="number" min="0" value="${dVal}" style="width:80px;padding:5px 8px;font-size:12px" onchange="if('${prefix}'==='fu')renderFuTimeline()">
        <select class="fsel" id="${pid}-delay-unit-${i}" style="width:110px;padding:5px 8px;font-size:12px" onchange="if('${prefix}'==='fu')renderFuTimeline()">
          <option value="minutes" ${dUnit==='minutes'?'selected':''}>Minutes</option>
          <option value="hours" ${dUnit==='hours'?'selected':''}>Hours</option>
          <option value="days" ${dUnit==='days'?'selected':''}>Days</option>
        </select>
      </div>
      <div style="font-size:10px;color:var(--text3);margin-left:auto">${prefix==='ar' ? (i===0 ? 'Time to wait after receiving the trigger email' : 'Time to wait before sending this step') : (i===0 ? 'Calculated from recipient open time' : 'Calculated sequentially from previous step sent time')}</div>
    </div>

    <div class="fg" style="margin-bottom:12px">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;flex-wrap:wrap;gap:6px">
        <div style="display:flex;align-items:center;gap:6px">
          <label class="fl" style="font-size:11px;font-weight:700;margin:0">HTML Body (WYSIWYG Rich Editor)</label>
          <span class="badge b-green" style="font-size:9px">WYSIWYG</span>
        </div>
        <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
          <!-- Variable Tags -->
          <div style="display:flex;gap:4px;align-items:center">
            <span class="tok-btn" style="cursor:pointer;font-size:10px;padding:2px 6px;background:rgba(255,255,255,0.06);border:1px solid var(--border);border-radius:4px" onclick="insertVariableToEditor('${pid}-body-${i}','{{NAME}}')" title="Insert {{NAME}} at cursor"><code>{{NAME}}</code></span>
            <span class="tok-btn" style="cursor:pointer;font-size:10px;padding:2px 6px;background:rgba(255,255,255,0.06);border:1px solid var(--border);border-radius:4px" onclick="insertVariableToEditor('${pid}-body-${i}','{{EMAIL}}')" title="Insert {{EMAIL}} at cursor"><code>{{EMAIL}}</code></span>
            <span class="tok-btn" style="cursor:pointer;font-size:10px;padding:2px 6px;background:rgba(255,255,255,0.06);border:1px solid var(--border);border-radius:4px" onclick="insertVariableToEditor('${pid}-body-${i}','{{IMAGE}}')" title="Insert {{IMAGE}} at cursor"><code>{{IMAGE}}</code></span>
            <span class="tok-btn" style="cursor:pointer;font-size:10px;padding:2px 6px;background:rgba(255,255,255,0.06);border:1px solid var(--border);border-radius:4px" onclick="insertVariableToEditor('${pid}-body-${i}','{Option 1|Option 2|Option 3}')" title="Insert Spintax at cursor"><code>{SPIN|TAX}</code></span>
          </div>
          <!-- Action Buttons -->
          <input type="file" id="${pid}-multi-img-${i}" multiple accept="image/*" style="display:none" onchange="handleStepMultiImageUpload(event, '${pid}', ${i})">
          <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('${pid}-multi-img-${i}').click()" style="padding:2px 8px;font-size:11px" title="Upload multiple images directly into editor">📤 Upload Images</button>
          <button type="button" class="btn btn-secondary btn-sm" onclick="toggleHtmlSourceMode('${pid}-body-${i}')" id="${pid}-source-btn-${i}" style="padding:2px 8px;font-size:11px" title="Toggle between Visual Editor and raw HTML code">&lt;&gt; HTML Source</button>
          <button type="button" class="btn btn-blue btn-sm" onclick="previewEmailStep(${i}, '${prefix}')" style="padding:2px 8px;font-size:11px" title="Preview live email with sample recipient">👁️ Preview Email</button>
        </div>
      </div>
      <div id="${pid}-editor-container-${i}" class="mailszo-editor-wrapper">
        <div id="${pid}-quill-${i}" style="min-height:180px">${st.html_body || '<p><br></p>'}</div>
      </div>
      <textarea class="fta" id="${pid}-body-${i}" style="display:none;min-height:180px;width:100%;font-family:var(--mono);font-size:12px">${esc(st.html_body||'')}</textarea>
    </div>
    <div class="fg"><label class="fl" style="font-size:10px">Plain Text <span class="flh">(auto-synchronized from HTML if left blank)</span></label>
      <textarea class="fta" id="${pid}-txt-${i}" style="min-height:44px" placeholder="Plain text version for non-HTML mail clients...">${esc(st.text_body||'')}</textarea>
    </div>
    <div style="background:rgba(34,211,238,.04);border:1px solid rgba(34,211,238,.1);border-radius:8px;padding:12px;margin-top:4px">
      <label class="fl" style="font-size:10px;margin-bottom:8px">🖼️ Image <span class="flh">(one picked randomly per email)</span></label>
      <div class="sel-thumbs" id="${pid}-th-${i}">${thumbs}</div>
      <button class="btn btn-secondary btn-sm" style="margin-top:6px" onclick="${pickFn}(${i})">🖼️ Pick Images (${(st.image_ids||[]).length} selected)</button>
      <div class="frow" style="gap:8px;flex-wrap:wrap;margin-top:10px">
        <div style="flex:1;min-width:100px"><label class="fl" style="font-size:10px">Width</label>
          <select class="fi" id="${pid}-imgw-${i}" style="padding:5px 8px;font-size:11px">
            <option value="200" ${iw==='200'?'selected':''}>200px</option><option value="300" ${iw==='300'?'selected':''}>300px</option>
            <option value="400" ${iw==='400'?'selected':''}>400px</option><option value="500" ${iw==='500'?'selected':''}>500px</option>
            <option value="600" ${iw==='600'?'selected':''}>600px</option><option value="100%" ${iw==='100%'?'selected':''}>100%</option>
          </select>
        </div>
        <div style="flex:1;min-width:100px"><label class="fl" style="font-size:10px">Align</label>
          <select class="fi" id="${pid}-imga-${i}" style="padding:5px 8px;font-size:11px">
            <option value="left" ${ia==='left'?'selected':''}>⬅ Left</option>
            <option value="center" ${ia==='center'?'selected':''}>↔ Center</option>
            <option value="right" ${ia==='right'?'selected':''}>➡ Right</option>
          </select>
        </div>
        <div style="flex:1;min-width:100px"><label class="fl" style="font-size:10px">Position</label>
          <select class="fi" id="${pid}-imgp-${i}" style="padding:5px 8px;font-size:11px">
            <option value="top" ${ip==='top'?'selected':''}>⬆ Top</option>
            <option value="middle" ${ip==='middle'?'selected':''}>↕ {{image}} tag</option>
            <option value="bottom" ${ip==='bottom'?'selected':''}>⬇ Bottom</option>
          </select>
        </div>
      </div>
    </div>
  </div>`;
}

/* Image picker — AR */
let arPickTarget=null, fuPickTarget=null;
function openArPick(i){arSaveCurrentSteps();arPickTarget=i;window._pickMode='ar';pickSel=[...(arSteps[i].image_ids||[])];renderPickGrid();al2('imgpick-al');$('pick-count').textContent=pickSel.length;showModal('imgpick-modal');}
function arRmImg(i,id){arSteps[i].image_ids=(arSteps[i].image_ids||[]).filter(x=>x!=id);arRefreshThumbs(i);}
function arRefreshThumbs(i){const el=document.getElementById('ars-th-'+i);if(!el)return;el.innerHTML=(arSteps[i].image_ids||[]).map(id=>{const img=allImages.find(x=>x.id==id);if(!img)return'';return`<div class="sel-th"><img src="${esc(img.url)}" alt=""><div class="sel-th-rm" onclick="arRmImg(${i},${id})">✕</div></div>`;}).join('');}

/* Image picker — FU */
function openFuPick(i){fuSaveCurrentSteps();fuPickTarget=i;window._pickMode='fu';pickSel=[...(fuSteps[i].image_ids||[])];renderPickGrid();al2('imgpick-al');$('pick-count').textContent=pickSel.length;showModal('imgpick-modal');}
function fuRmImg(i,id){fuSteps[i].image_ids=(fuSteps[i].image_ids||[]).filter(x=>x!=id);fuRefreshThumbs(i);}
function fuRefreshThumbs(i){const el=document.getElementById('fus-th-'+i);if(!el)return;el.innerHTML=(fuSteps[i].image_ids||[]).map(id=>{const img=allImages.find(x=>x.id==id);if(!img)return'';return`<div class="sel-th"><img src="${esc(img.url)}" alt=""><div class="sel-th-rm" onclick="fuRmImg(${i},${id})">✕</div></div>`;}).join('');}

/* Patch confirmPick for all 3 modes */
window.confirmPick=function(){
  const mode=window._pickMode||'campaign';
  if(mode==='ar'){if(arPickTarget===null)return;arSteps[arPickTarget].image_ids=[...pickSel];arRefreshThumbs(arPickTarget);arPickTarget=null;}
  else if(mode==='fu'){if(fuPickTarget===null)return;fuSteps[fuPickTarget].image_ids=[...pickSel];fuRefreshThumbs(fuPickTarget);fuPickTarget=null;}
  else{if(pickTarget===null)return;variants[pickTarget].image_ids=[...pickSel];refreshThumbs(pickTarget);const btn=document.querySelector('#vp-'+pickTarget+' .btn-secondary');if(btn)btn.textContent='🖼️ Pick Images ('+pickSel.length+' selected)';}
  window._pickMode='campaign';
  closeModal('imgpick-modal');
};

/* Save AR */
async function saveAr(){
  arSaveCurrentSteps();
  const name=v('ar-name'); if(!name){al('ar-al','Rule Name is required','err');return;}
  if(!arSteps.length){al('ar-al','Add at least one reply message','err');return;}

  // Validate each step
  for(let i=0; i<arSteps.length; i++){
    const st = arSteps[i];
    const cleanHtml = (st.html_body||'').replace(/<[^>]*>/g, '').trim();
    if(!cleanHtml && !(st.html_body||'').includes('<img') && !(st.html_body||'').includes('{{IMAGE}}') && !(st.image_ids||[]).length){
      al('ar-al',`HTML Body cannot be empty for Reply #${i+1}`,'err');
      return;
    }
  }

  let imapId, imap2Id, smtpIds, step1SmtpIds;

  if(S.isAdmin){
    imapId=parseInt($('ar-imap').value)||null;
    imap2Id=parseInt($('ar-imap2').value)||null;
    if(imap2Id && imap2Id===imapId){al('ar-al','IMAP 2 must be different from IMAP 1','err');return;}
    smtpIds=Array.from(document.querySelectorAll('#ar-smtp-pool input[type=checkbox]:checked')).map(c=>parseInt(c.value));
    step1SmtpIds=Array.from(document.querySelectorAll('#ar-step1-smtp-inner input[type=checkbox]:checked')).map(c=>parseInt(c.value));
  } else {
    if(!allSmtps.length){al('ar-al','No SMTP servers assigned to your account. Contact the administrator.','err');return;}
    if(!allImaps.length){al('ar-al','No IMAP accounts assigned to your account. Contact the administrator.','err');return;}
    smtpIds = allSmtps.map(s=>parseInt(s.id));
    imapId  = parseInt(allImaps[0].id);
    imap2Id = null;
    step1SmtpIds = [];
  }

  const isSmart = $('ar-enable-smart')?.checked ? 1 : 0;
  const pImapId = parseInt($('ar-smart-primary-imap')?.value) || imapId;
  const sImapId = parseInt($('ar-smart-secondary-imap')?.value) || imap2Id;
  const bImapId = parseInt($('ar-smart-backup-imap')?.value) || null;
  const pSmtpId = parseInt($('ar-smart-primary-smtp')?.value) || null;
  const sSmtpId = parseInt($('ar-smart-secondary-smtp')?.value) || null;
  const fuRuleId = parseInt($('ar-smart-fu-rule')?.value) || null;
  const replyToSwitch = $('ar-smart-replyto-switch')?.checked ? 1 : 0;
  const alwaysFu = $('ar-smart-always-fu')?.checked ? 1 : 0;
  const gmailPriority = $('ar-smart-gmail-priority')?.checked ? 1 : 0;

  if(isSmart && !pImapId){
    al('ar-al','Select Primary Lead Receiver IMAP (Gmail)','err');return;
  }
  if(!isSmart && !imapId){
    al('ar-al','Select an IMAP account','err');return;
  }

  const sequentialMode=document.querySelector('input[name="ar-mode"]:checked')?.value==='1'?1:0;
  const payload={
    name,
    imap_id: pImapId || imapId,
    imap2_id: sImapId || imap2Id,
    smtp_ids: smtpIds,
    from_emails: getArFromEmails(),
    status: $('ar-status').value,
    sequential_mode: sequentialMode,
    step1_smtp_ids: step1SmtpIds,
    enable_smart_routing: isSmart,
    primary_imap_id: pImapId,
    secondary_imap_id: sImapId,
    backup_imap_id: bImapId,
    primary_smtp_id: pSmtpId,
    secondary_smtp_id: sSmtpId,
    followup_rule_id: fuRuleId,
    enable_reply_to_switch: replyToSwitch,
    enable_always_send_followup: alwaysFu,
    enable_gmail_priority: gmailPriority,
    steps: arSteps.map(st => {
      const v = st.delay_value != null ? st.delay_value : (st.delay_minutes || 1);
      const u = st.delay_unit || 'minutes';
      const m = u === 'days' ? v * 1440 : (u === 'hours' ? v * 60 : v);
      return {
        ...st,
        delay_value: v,
        delay_unit: u,
        delay_minutes: m,
        image_ids: st.image_ids || []
      };
    })
  };

  if (S.isAdmin) {
    const ownerVal = parseInt(document.getElementById('ar-owner-sel')?.value);
    if (ownerVal > 0) payload.user_id = ownerVal;
  }
  const btn=$('ar-save-btn');btn.disabled=true;btn.innerHTML='<span class="spin-ic"></span> Saving…';
  const r=arEid?await put('autoreply/'+arEid,payload):await post('autoreply',payload);
  btn.disabled=false;btn.textContent='💾 Save Rule';
  if(r?.ok){
    localStorage.removeItem('mailszo_ar_draft');
    al('ar-al','✅ Saved!','ok');
    loadAutoreply();
    setTimeout(()=>closeModal('ar-modal'),1000);
  }
  else al('ar-al',r?.message||r?.error||'Error','err');
}

/* ══════════════════════════════════════════════════════════════════
   SMART MAIL ROUTING STUDIO (Gmail → SMTP #1 → Mailbox #2 Engine)
   ══════════════════════════════════════════════════════════════════ */
let mrDebounceTimer = null;
let mrCurrentPage = 1;
let mrLogsCurrentPage = 1;

function mrSearchDebounce() {
  clearTimeout(mrDebounceTimer);
  mrDebounceTimer = setTimeout(() => loadMailRouting(), 300);
}

async function loadMailRouting(silent) {
  if (!silent) {
    if (!allImaps.length) await loadImap();
    if (!allSmtps.length) await loadSmtps();
  }

  // Load Stats
  try {
    const stats = await get('mail-routing/stats');
    if (stats && stats.ok) {
      if ($('mr-stat-leads')) $('mr-stat-leads').textContent = fmt(stats.total_leads || 0);
      if ($('mr-stat-first-replies')) $('mr-stat-first-replies').textContent = fmt(stats.first_replies_sent || 0);
      if ($('mr-stat-migrated')) $('mr-stat-migrated').textContent = fmt(stats.migrated_secondary || 0);
      if ($('mr-stat-followups')) $('mr-stat-followups').textContent = fmt(stats.followups_active || 0);
      if ($('mr-stat-active')) $('mr-stat-active').textContent = fmt(stats.active_conversations || 0);
    }
  } catch (_e) {}

  // Load Active Conversation Threads
  const stageFilter = $('mr-stage-filter')?.value || '';
  const mbFilter = $('mr-mailbox-filter')?.value || '';
  const searchQ = $('mr-thread-search')?.value?.trim() || '';

  const qs = new URLSearchParams({ page: mrCurrentPage, limit: 25 });
  if (stageFilter) qs.set('stage', stageFilter);
  if (mbFilter) qs.set('mailbox', mbFilter);
  if (searchQ) qs.set('q', searchQ);

  try {
    const r = await get('mail-routing/threads?' + qs.toString());
    const tb = $('mr-threads-body');
    if (!tb) return;

    if (!r?.rows?.length) {
      tb.innerHTML = '<tr class="empty-row"><td colspan="8">No active smart conversation threads found.</td></tr>';
    } else {
      const stageBadges = {
        'NEW_LEAD': '<span class="badge b-blue">🔵 NEW_LEAD</span>',
        'FIRST_REPLY_SENT': '<span class="badge b-purple">🟣 FIRST_REPLY_SENT</span>',
        'MOVED_TO_SECONDARY': '<span class="badge b-green">🟢 MOVED_TO_SECONDARY</span>',
        'FOLLOWUP_RUNNING': '<span class="badge b-amber">🟠 FOLLOWUP_RUNNING</span>',
        'FOLLOWUP_COMPLETED': '<span class="badge b-gray">⚪ FOLLOWUP_COMPLETED</span>',
      };

      tb.innerHTML = r.rows.map(t => {
        const isSec = t.active_mailbox === 'secondary';
        const mbBadge = isSec
          ? '<span class="badge b-green" style="font-weight:700">📬 Secondary (#2)</span>'
          : '<span class="badge b-blue" style="font-weight:700">📥 Primary (Gmail)</span>';

        const fuBadge = t.followup_status === 'running'
          ? `<span class="badge b-amber">⏳ Running</span><br><small style="font-size:10px;color:var(--text3)">Next: ${t.followup_next_run || 'soon'}</small>`
          : (t.followup_status === 'completed' ? '<span class="badge b-gray">Completed</span>' : '<span class="badge b-gray">Idle</span>');

        return `<tr>
          <td>
            <strong>${esc(t.from_email)}</strong>
            ${t.from_name ? `<br><small style="color:var(--text2)">${esc(t.from_name)}</small>` : ''}
          </td>
          <td>
            <strong>${esc(t.rule_name || 'Smart Routing')}</strong>
            <br><small style="color:var(--text3);max-width:200px;overflow:hidden;text-overflow:ellipsis;display:inline-block">${esc(t.subject_in || '—')}</small>
          </td>
          <td>${mbBadge}</td>
          <td>${stageBadges[t.conversation_stage] || `<span class="badge b-gray">${esc(t.conversation_stage)}</span>`}</td>
          <td>
            <span class="badge b-purple">Step ${t.current_step || 1}</span>
            <span class="badge b-blue" style="margin-left:4px">${t.reply_count || 1} msgs</span>
          </td>
          <td>${fuBadge}</td>
          <td style="font-size:11px;color:var(--text2)">${t.last_sent_at || t.created_at || '—'}</td>
          <td>
            <div class="btn-group">
              <button class="btn btn-secondary btn-sm" onclick="manualMigrateMailbox(${t.id}, '${isSec ? 'primary' : 'secondary'}')" title="Switch active mailbox">
                ${isSec ? '⬅ Switch to Primary' : '➡ Migrate to #2'}
              </button>
            </div>
          </td>
        </tr>`;
      }).join('');
    }

    renderMrPager('mr-threads-pager', r?.total || 0, r?.pages || 1, mrCurrentPage, (p) => {
      mrCurrentPage = p;
      loadMailRouting();
    });
  } catch (_e) {}

  loadMailRoutingLogs();
}

function renderMrPager(wrapId, total, pages, curPage, onPage) {
  const wrap = document.getElementById(wrapId);
  if (!wrap) return;
  if (pages <= 1) { wrap.innerHTML = `<span style="font-size:11px;color:var(--text3)">${total} total records</span>`; return; }
  let h = `<span style="font-size:11px;color:var(--text3);margin-right:8px">${total} records (Page ${curPage} of ${pages})</span>`;
  if (curPage > 1) h += `<button class="btn btn-secondary btn-sm" onclick="(${onPage})(${curPage - 1})">◀ Prev</button>`;
  if (curPage < pages) h += `<button class="btn btn-secondary btn-sm" onclick="(${onPage})(${curPage + 1})">Next ▶</button>`;
  wrap.innerHTML = h;
}

async function loadMailRoutingLogs() {
  const evtFilter = $('mr-log-event-filter')?.value || '';
  const qs = new URLSearchParams({ page: mrLogsCurrentPage, limit: 50 });
  if (evtFilter) qs.set('event_type', evtFilter);

  try {
    const r = await get('mail-routing/logs?' + qs.toString());
    const tb = $('mr-logs-body');
    if (!tb) return;

    if (!r?.rows?.length) {
      tb.innerHTML = '<tr class="empty-row"><td colspan="7">No mail routing audit logs recorded yet.</td></tr>';
      return;
    }

    const evtBadges = {
      'lead_received': '<span class="badge b-blue">📥 Lead Received</span>',
      'first_reply_sent': '<span class="badge b-purple">📤 First Reply</span>',
      'mailbox_migrated': '<span class="badge b-green">🔄 Mailbox Migrated</span>',
      'chat_reply_sent': '<span class="badge b-blue">💬 Chat Reply</span>',
      'followup_scheduled': '<span class="badge b-amber">⏱ FU Scheduled</span>',
      'followup_sent': '<span class="badge b-teal">📬 FU Sent</span>',
      'duplicate_ignored': '<span class="badge b-gray">🛡️ Duplicate Ignored</span>',
    };

    tb.innerHTML = r.rows.map(l => `<tr>
      <td style="font-size:11px;color:var(--text2)">${l.created_at || '—'}</td>
      <td>${evtBadges[l.event_type] || `<span class="badge b-gray">${esc(l.event_type)}</span>`}</td>
      <td class="mono" style="font-size:11px"><strong>${esc(l.recipient_email || '—')}</strong></td>
      <td style="font-size:11px">${esc(l.smtp_used || l.incoming_mailbox || '—')}</td>
      <td style="font-size:11px">
        ${l.previous_stage ? `<span class="badge b-gray" style="font-size:9px">${esc(l.previous_stage)}</span> → ` : ''}
        ${l.new_stage ? `<span class="badge b-purple" style="font-size:9px">${esc(l.new_stage)}</span>` : '—'}
      </td>
      <td><span class="badge ${l.status==='success'?'b-green':'b-red'}">${esc(l.status)}</span></td>
      <td style="font-size:11px;color:var(--text2)">${esc(l.details || '—')}</td>
    </tr>`).join('');

    renderMrPager('mr-logs-pager', r?.total || 0, r?.pages || 1, mrLogsCurrentPage, (p) => {
      mrLogsCurrentPage = p;
      loadMailRoutingLogs();
    });
  } catch (_e) {}
}

async function manualMigrateMailbox(threadId, targetMailbox) {
  const r = await post('mail-routing/migrate-thread', { thread_id: threadId, target_mailbox: targetMailbox });
  if (r && r.ok) {
    loadMailRouting();
  } else {
    alert(r?.message || 'Error migrating thread');
  }
}

async function clearMailRoutingLogs() {
  if (!confirm('Clear all mail routing audit logs?')) return;
  const r = await del('mail-routing/logs');
  if (r && r.ok) {
    loadMailRoutingLogs();
  }
}

async function triggerRoutingCron() {
  try {
    const r = await post('cron/run', {});
    loadMailRouting();
  } catch (e) {
    loadMailRouting();
  }
}

/* AR Threads */
async function openArThreads(id,name){
  $('ar-threads-title').textContent='🧵 Threads — '+name;
  $('ar-threads-body').innerHTML='<tr class="empty-row"><td colspan="7">Loading…</td></tr>';
  showModal('ar-threads-modal');
  const r=await get('autoreply/'+id+'/threads');
  const tb=$('ar-threads-body');
  if(!r?.rows?.length){tb.innerHTML='<tr class="empty-row"><td colspan="7">No threads yet — waiting for emails</td></tr>';return;}
  const sc={active:'b-green',completed:'b-gray'};
  tb.innerHTML=r.rows.map(t=>`<tr>
    <td class="mono" style="font-size:11px">${esc(t.from_email)}</td>
    <td>${esc(t.from_name||'—')}</td>
    <td style="font-size:11px;color:var(--text2);max-width:180px;overflow:hidden;text-overflow:ellipsis">${esc(t.subject_in||'—')}</td>
    <td><span class="badge b-purple">Step ${t.current_step}</span>${t.awaiting_reply==1?'<br><span class="badge b-amber" style="font-size:9px">⏳ Awaiting reply</span>':''}</td>
    <td><span class="badge b-blue" title="Messages received from contact">${t.messages_received||t.reply_count||1} msgs in</span></td>
    <td style="font-size:10px;color:var(--text2)">${t.last_sent_at||'—'}</td>
    <td><span class="badge ${sc[t.status]||'b-gray'}">${t.status}</span></td>
  </tr>`).join('');
}

/* AR Logs */
let arLogsCurrentRuleId = null, arLogsCurrentRuleName = '';
async function openArLogs(id,name){
  arLogsCurrentRuleId = id; arLogsCurrentRuleName = name;
  $('ar-logs-title').textContent='📋 Logs — '+name;
  const clearBtn = $('ar-logs-clear-btn');
  if(clearBtn) clearBtn.style.display = (S.isAdmin) ? 'inline-block' : 'none';
  $('ar-logs-body').innerHTML='<tr class="empty-row"><td colspan="6">Loading…</td></tr>';
  showModal('ar-logs-modal');
  const rows=await get('autoreply/'+id+'/logs');
  const tb=$('ar-logs-body');
  if(!rows?.length){tb.innerHTML='<tr class="empty-row"><td colspan="6">No logs yet</td></tr>';return;}
  tb.innerHTML=rows.map(l=>`<tr>
    <td class="mono" style="font-size:11px">${esc(l.to_email)}</td>
    <td><span class="badge b-purple">Reply #${l.step_number}</span></td>
    <td>${l.status==='sent'?'<span class="badge b-green">✅ Sent</span>':'<span class="badge b-red">❌ Failed</span>'}</td>
    <td style="font-size:11px">${esc(l.smtp_used||'—')}</td>
    <td style="font-size:10px;color:var(--red)">${esc(l.error||'')}</td>
    <td style="font-size:10px;color:var(--text2)">${l.sent_at||'—'}</td>
  </tr>`).join('');
}
async function clearArLogs(){
  if(!arLogsCurrentRuleId) return;
  if(!confirm('Clear all logs for auto-reply rule "'+arLogsCurrentRuleName+'"?')) return;
  const r = await del('autoreply/'+arLogsCurrentRuleId+'/logs');
  if(r?.ok) openArLogs(arLogsCurrentRuleId, arLogsCurrentRuleName);
  else alert('Error: '+(r?.message||r?.error||'Failed to clear logs'));
}


/* ══════════════════════════════════════════════════════════════════
   FOLLOW-UP
   ══════════════════════════════════════════════════════════════════ */
async function loadFollowup(){
  const rows=await get('followup'); allFu=rows||[];
  const tb=$('fu-body');
  if(!rows?.length){tb.innerHTML='<tr class="empty-row"><td colspan="6">No follow-up rules yet</td></tr>';return;}
  tb.innerHTML=rows.map(r=>`<tr>
    <td><strong>${esc(r.name)}</strong>${r.owner?`<br><small style="color:var(--text3)">@${esc(r.owner)}</small>`:''}</td>
    <td><span class="badge b-purple">${(r.steps||[]).length} messages</span></td>
    <td><span class="badge b-amber">${r.active_contacts||0} active</span> <span class="badge b-gray">${r.total_contacts||0} total</span></td>
    <td class="mono" style="color:var(--accent)">${fmt(r.total_sent||0)}</td>
    <td>${r.status==='active'?'<span class="badge b-green">✅ Active</span>':'<span class="badge b-amber">⏸ Paused</span>'}</td>
    <td><div class="btn-group">
      <button class="btn btn-secondary btn-sm" onclick="editFu(${r.id})">Edit</button>
      <button class="btn btn-secondary btn-sm" onclick="openDupModal('followup', ${r.id}, '${esc(r.name)}')">Copy</button>
      <button class="btn btn-blue btn-sm" onclick="openFuContacts(${r.id},'${esc(r.name)}')">👥 Contacts</button>
      <button class="btn btn-blue btn-sm" onclick="openFuLogs(${r.id},'${esc(r.name)}')">📋 Logs</button>
      ${r.status==='active'?`<button class="btn btn-amber btn-sm" onclick="fuToggle(${r.id},'pause')">⏸</button>`:`<button class="btn btn-primary btn-sm" onclick="fuToggle(${r.id},'resume')">▶</button>`}
      <button class="btn btn-danger btn-sm" onclick="delFu(${r.id})">Del</button>
    </div></td>
  </tr>`).join('');
}

async function fuToggle(id,a){await post('followup/'+id+'/'+a,{});loadFollowup();}
async function delFu(id){if(!confirm('Delete this follow-up rule and all contacts?'))return;await del('followup/'+id);loadFollowup();}

function populateFuImap(selId){
  const sel=$('fu-imap'); if(!sel) return;
  sel.innerHTML='<option value="">— None (manual enroll only) —</option>';
  const uniqueImaps = Array.from(new Map((allImaps||[]).map(a=>[String(a.id),a])).values());
  uniqueImaps.forEach(a=>{
    const o=document.createElement('option');
    o.value=a.id; o.textContent=a.name+' ('+a.host+')';
    if(String(a.id)===String(selId)) o.selected=true;
    sel.appendChild(o);
  });
}

/* ── Helper: show/hide admin vs user SMTP/IMAP rows in FU modal ── */
async function applyFuSmtpImapMode(){
  const isAdmin = S.isAdmin;
  const fuImapAdmin = $('fu-imap-admin-row');
  const fuImapUser  = $('fu-imap-user-row');
  const fuSmtpAdmin = $('fu-smtp-admin-row');
  const fuSmtpUser  = $('fu-smtp-user-row');
  if(fuImapAdmin) fuImapAdmin.style.display = isAdmin ? 'block' : 'none';
  if(fuImapUser)  fuImapUser.style.display  = isAdmin ? 'none'  : 'block';
  if(fuSmtpAdmin) fuSmtpAdmin.style.display = isAdmin ? 'block' : 'none';
  if(fuSmtpUser)  fuSmtpUser.style.display  = isAdmin ? 'none'  : 'block';
  if(!isAdmin){
    const uniqueSmtps = Array.from(new Map((allSmtps||[]).map(s=>[String(s.id),s])).values());
    const uniqueImaps = Array.from(new Map((allImaps||[]).map(a=>[String(a.id),a])).values());
    const smtpDisplay = $('fu-smtp-user-display');
    if(smtpDisplay){
      smtpDisplay.innerHTML = uniqueSmtps.length
        ? uniqueSmtps.map(s=>`<span style="display:inline-block;margin:2px 4px;background:var(--bg3);border:1px solid var(--border);border-radius:4px;padding:2px 8px;font-size:12px">🔌 <strong>${esc(s.name)}</strong> <span style="color:var(--text3)">${esc(s.from_email||'')} · ${esc(s.host||'')}</span></span>`).join('')
        : '<span style="color:var(--red)">⚠ No SMTP servers assigned yet. Contact your administrator.</span>';
    }
    const imapDisplay = $('fu-imap-user-display');
    if(imapDisplay){
      imapDisplay.innerHTML = uniqueImaps.length
        ? uniqueImaps.map(a=>`<span style="display:inline-block;margin:2px 4px;background:var(--bg3);border:1px solid var(--border);border-radius:4px;padding:2px 8px;font-size:12px">📥 <strong>${esc(a.name)}</strong> <span style="color:var(--text3)">${esc(a.username||'')} · ${esc(a.host||'')}</span></span>`).join('')
        : '<span style="color:var(--text3)">— None assigned (manual enroll only) —</span>';
    }
    // Auto-select first assigned IMAP in the hidden select
    const fuImapSel = $('fu-imap');
    if(fuImapSel && uniqueImaps.length) populateFuImap(uniqueImaps[0].id);
  }
}

async function openFuModal(){
  if(!allImages.length) await loadImages();
  if(!allImaps.length) await loadImap();
  if(!allSmtps.length) await loadSmtps();
  fuEid=null; fuSteps=[];
  $('fu-modal-title').textContent='📬 New Follow-Up Rule';
  al2('fu-al'); sv('fu-name',''); $('fu-status').value='active';
  
  const trigOpen = $('fu-trigger-open');
  if(trigOpen) trigOpen.checked = true;

  const fuOwnerRow = document.getElementById('fu-owner-row');
  if (fuOwnerRow) fuOwnerRow.style.display = S.isAdmin ? 'block' : 'none';
  if (S.isAdmin) {
    await loadRuleOwnerUsers();
    populateOwnerSelect('fu-owner-sel', S.uid);
  }
  populateFuImap('');
  renderFuSmtpPool([]); clearFuFromTags();
  fuSteps=[];
  await applyFuSmtpImapMode();
  await refreshFuQuota();
  fuAddStep();
  showModal('fu-modal');
}

async function editFu(id){
  if(!allImages.length) await loadImages();
  if(!allImaps.length) await loadImap();
  if(!allSmtps.length) await loadSmtps();
  const r=await get('followup/'+id); if(!r?.id){alert('Load error');return;}
  fuEid=id; fuSteps=[];
  $('fu-modal-title').textContent='✏️ Edit Follow-Up Rule';
  al2('fu-al'); sv('fu-name',r.name||''); $('fu-status').value=r.status||'active';
  
  const trigOpen = $('fu-trigger-open');
  if(trigOpen) trigOpen.checked = (r.trigger_on_open == null || r.trigger_on_open == 1 || r.trigger_on_open === '1');

  const fuOwnerRow2 = document.getElementById('fu-owner-row');
  if (fuOwnerRow2) fuOwnerRow2.style.display = S.isAdmin ? 'block' : 'none';
  if (S.isAdmin) {
    await loadRuleOwnerUsers();
    populateOwnerSelect('fu-owner-sel', r.user_id);
  }
  populateFuImap(r.imap_id||'');
  let smtpSel=[];try{if(r.smtp_ids){const d=JSON.parse(r.smtp_ids);if(Array.isArray(d))smtpSel=d;}}catch(e){}
  renderFuSmtpPool(smtpSel);
  setFuFromTags(r.from_emails||null);
  await applyFuSmtpImapMode();
  if(r.steps?.length){
    r.steps.forEach(st=>{
      let imgIds=[];
      try{const p=st.image_ids;imgIds=Array.isArray(p)?p:(typeof p==='string'&&p?JSON.parse(p):[]);}catch(e){imgIds=[];}
      const rawMin = parseInt(st.delay_minutes) || 30;
      let dVal = st.delay_value != null ? parseInt(st.delay_value) : null;
      let dUnit = st.delay_unit || null;
      if(!dVal || !dUnit){
        if(rawMin >= 1440 && rawMin % 1440 === 0){ dVal = rawMin / 1440; dUnit = 'days'; }
        else if(rawMin >= 60 && rawMin % 60 === 0){ dVal = rawMin / 60; dUnit = 'hours'; }
        else { dVal = rawMin; dUnit = 'minutes'; }
      }
      fuSteps.push({
        delay_value: dVal,
        delay_unit: dUnit,
        delay_minutes: rawMin,
        subject: st.subject||'',
        html_body: st.html_body||'',
        text_body: st.text_body||'',
        image_ids: Array.isArray(imgIds)?imgIds:[],
        img_width: st.img_width||'600',
        img_align: st.img_align||'center',
        img_position: st.img_position||'top'
      });
    });
  }
  if(!fuSteps.length) fuAddStep();
  renderFuSteps();
  await refreshFuQuota();
  showModal('fu-modal');
}

function renderFuSmtpPool(sel){
  const wrap=$('fu-smtp-pool');
  const uniqueSmtps = Array.from(new Map((allSmtps||[]).map(s=>[String(s.id),s])).values());
  if(!uniqueSmtps.length){wrap.innerHTML='<div style="color:var(--text3);font-size:12px;padding:6px">No SMTP servers.</div>';return;}
  wrap.innerHTML=uniqueSmtps.map(s=>{
    const chk=sel.map(String).includes(String(s.id));
    return `<label class="spl ${chk?'ck':''}"><input type="checkbox" value="${s.id}" ${chk?'checked':''} onchange="this.closest('label').classList.toggle('ck',this.checked)"><strong>${esc(s.name)}</strong> <span style="color:var(--text3);font-size:10px">${esc(s.from_email)} · ${esc(s.host)}</span></label>`;
  }).join('');
}
function clearFuFromTags(){$('fu-from-wrap').querySelectorAll('.tag').forEach(t=>t.remove());}
function setFuFromTags(json){clearFuFromTags();let arr=[];try{if(json)arr=JSON.parse(json);}catch(e){}arr.forEach(e=>{const lbl=typeof e==='object'?(e.name?e.name+' <'+e.email+'>':e.email):e;addFuTag(lbl);});}
function fuFromKey(e){if(e.key==='Enter'||e.key===','){e.preventDefault();const val=e.target.value.trim();if(val){addFuTag(val);e.target.value='';}}}
function addFuTag(text){const wrap=$('fu-from-wrap');const t=document.createElement('div');t.className='tag';t.innerHTML=`<span>${esc(text)}</span><span class="tag-x" onclick="this.parentNode.remove()">✕</span>`;wrap.insertBefore(t,$('fu-from-inp'));}
function getFuFromEmails(){return Array.from($('fu-from-wrap').querySelectorAll('.tag span:first-child')).map(t=>{const txt=t.textContent.trim();const m=txt.match(/^(.+?)\s*<(.+?)>$/);return m?{name:m[1].trim(),email:m[2].trim()}:{email:txt};});}

/* Save FU */
async function saveFu(){
  fuSaveCurrentSteps();
  const name=v('fu-name'); if(!name){al('fu-al','Name required','err');return;}
  if(!fuSteps.length){al('fu-al','Add at least one message','err');return;}

  let smtpIds, imapId;

  if(S.isAdmin){
    smtpIds=Array.from(document.querySelectorAll('#fu-smtp-pool input[type=checkbox]:checked')).map(c=>parseInt(c.value));
    if(!smtpIds.length){al('fu-al','Select at least one SMTP server','err');return;}
    imapId=parseInt($('fu-imap').value)||null;
  } else {
    if(!allSmtps.length){al('fu-al','No SMTP servers assigned to your account. Contact the administrator.','err');return;}
    smtpIds = allSmtps.map(s=>parseInt(s.id));
    imapId  = allImaps.length ? parseInt(allImaps[0].id) : null;
  }

  const triggerOnOpen = $('fu-trigger-open')?.checked ? 1 : 0;

  const payload = {
    name,
    imap_id: imapId,
    smtp_ids: smtpIds,
    from_emails: getFuFromEmails(),
    status: $('fu-status').value,
    trigger_on_open: triggerOnOpen,
    steps: fuSteps.map(st => {
      const v = st.delay_value || (st.delay_minutes || 30);
      const u = st.delay_unit || 'minutes';
      const m = u === 'days' ? v * 1440 : (u === 'hours' ? v * 60 : v);
      return {
        ...st,
        delay_value: v,
        delay_unit: u,
        delay_minutes: m,
        image_ids: st.image_ids || []
      };
    })
  };

  if (S.isAdmin) {
    const ownerVal = parseInt(document.getElementById('fu-owner-sel')?.value);
    if (ownerVal > 0) payload.user_id = ownerVal;
  }
  const btn=$('fu-save-btn');btn.disabled=true;btn.innerHTML='<span class="spin-ic"></span> Saving…';
  const r=fuEid?await put('followup/'+fuEid,payload):await post('followup',payload);
  btn.disabled=false;btn.textContent='💾 Save Rule';
  if(r?.ok){al('fu-al','✅ Saved!','ok');loadFollowup();setTimeout(()=>closeModal('fu-modal'),1000);}
  else al('fu-al',r?.message||r?.error||'Error','err');
}

/* ══════════════════════════════════════════════════════════════════
   RICH TEXT COMPOSER & TEMPLATE STUDIO
   ══════════════════════════════════════════════════════════════════ */
let allTemplates = [];
let _targetEditorId = null;
let _targetStepIndex = null;
let _targetStepPrefix = null;

function initRteToolbar(barId, editorId, rawId){
  const bar = document.getElementById(barId);
  if(!bar) return;
  bar.innerHTML = `
    <button type="button" class="rte-btn" onclick="rteExec('${editorId}','bold')" title="Bold (Ctrl+B)"><strong>B</strong></button>
    <button type="button" class="rte-btn" onclick="rteExec('${editorId}','italic')" title="Italic (Ctrl+I)"><em>I</em></button>
    <button type="button" class="rte-btn" onclick="rteExec('${editorId}','underline')" title="Underline (Ctrl+U)"><u>U</u></button>
    <button type="button" class="rte-btn" onclick="rteExec('${editorId}','strikeThrough')" title="Strikethrough"><s>S</s></button>
    <div class="rte-sep"></div>
    <select class="rte-select" onchange="rteExec('${editorId}','formatBlock',this.value);this.selectedIndex=0">
      <option value="">Heading</option>
      <option value="<h1>">Heading 1</option>
      <option value="<h2>">Heading 2</option>
      <option value="<h3>">Heading 3</option>
      <option value="<p>">Paragraph</option>
      <option value="<blockquote>">Quote</option>
    </select>
    <select class="rte-select" onchange="rteExec('${editorId}','fontName',this.value);this.selectedIndex=0">
      <option value="">Font</option>
      <option value="Arial, sans-serif">Arial</option>
      <option value="'Helvetica Neue', Helvetica, sans-serif">Helvetica</option>
      <option value="'Segoe UI', Roboto, sans-serif">Segoe UI</option>
      <option value="Georgia, serif">Georgia</option>
      <option value="'Courier New', monospace">Courier</option>
    </select>
    <div class="rte-sep"></div>
    <button type="button" class="rte-btn" onclick="rteExec('${editorId}','justifyLeft')" title="Align Left">⫷</button>
    <button type="button" class="rte-btn" onclick="rteExec('${editorId}','justifyCenter')" title="Align Center">≡</button>
    <button type="button" class="rte-btn" onclick="rteExec('${editorId}','justifyRight')" title="Align Right">⫸</button>
    <div class="rte-sep"></div>
    <button type="button" class="rte-btn" onclick="rteExec('${editorId}','insertUnorderedList')" title="Bullet List">• List</button>
    <button type="button" class="rte-btn" onclick="rteExec('${editorId}','insertOrderedList')" title="Numbered List">1. List</button>
    <div class="rte-sep"></div>
    <button type="button" class="rte-btn" onclick="openRteLinkModal('${editorId}')" title="Insert / Edit Link">🔗 Link</button>
    <button type="button" class="rte-btn" onclick="rteInsertTable('${editorId}')" title="Insert Table">📊 Table</button>
    <button type="button" class="rte-btn" onclick="rteExec('${editorId}','removeFormat')" title="Clear Formatting">🧹</button>
    <div class="rte-sep"></div>
    <button type="button" class="rte-btn" onclick="rteToggleCodeView('${editorId}','${rawId}',this)" title="Toggle HTML Code View">&lt;/&gt; Code</button>
  `;
}

function rteExec(editorId, cmd, val=null){
  const ed = document.getElementById(editorId);
  if(!ed) return;
  ed.focus();
  document.execCommand(cmd, false, val);
}

function rteToggleCodeView(editorId, rawId, btn){
  const ed = document.getElementById(editorId);
  const raw = document.getElementById(rawId);
  if(!ed || !raw) return;
  if(ed.style.display === 'none'){
    // Switch to visual editor
    ed.innerHTML = raw.value;
    raw.style.display = 'none';
    ed.style.display = 'block';
    btn?.classList.remove('active');
  } else {
    // Switch to raw HTML code
    raw.value = ed.innerHTML;
    ed.style.display = 'none';
    raw.style.display = 'block';
    btn?.classList.add('active');
  }
}

function rteInsertTable(editorId){
  const html = `
    <table style="width:100%;border-collapse:collapse;margin:12px 0;border:1px solid rgba(255,255,255,0.15)">
      <thead>
        <tr style="background:rgba(255,255,255,0.06)">
          <th style="border:1px solid rgba(255,255,255,0.15);padding:8px">Header 1</th>
          <th style="border:1px solid rgba(255,255,255,0.15);padding:8px">Header 2</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td style="border:1px solid rgba(255,255,255,0.15);padding:8px">Data 1</td>
          <td style="border:1px solid rgba(255,255,255,0.15);padding:8px">Data 2</td>
        </tr>
      </tbody>
    </table><p></p>
  `;
  rteExec(editorId, 'insertHTML', html);
}

let _currentLinkEditorId = null;
function openRteLinkModal(editorId){
  _currentLinkEditorId = editorId;
  al2('rte-link-al');
  const sel = window.getSelection();
  const txt = sel ? sel.toString() : '';
  sv('rte-link-text', txt);
  sv('rte-link-url', 'https://');
  showModal('rte-link-modal');
}

function rteApplyLink(){
  const url = v('rte-link-url');
  const text = v('rte-link-text');
  const target = $('rte-link-blank')?.checked ? '_blank' : '_self';
  if(!url || url === 'https://'){
    al('rte-link-al','Please enter a valid URL','err');
    return;
  }
  closeModal('rte-link-modal');
  const ed = document.getElementById(_currentLinkEditorId);
  if(ed){
    ed.focus();
    const linkHtml = `<a href="${esc(url)}" target="${target}">${esc(text || url)}</a>`;
    document.execCommand('insertHTML', false, linkHtml);
  }
}

/* Canvas-based Client-Side Image Compressor */
async function compressImageCanvas(file, maxWidth = 1200, quality = 0.85){
  return new Promise((resolve) => {
    const reader = new FileReader();
    reader.onload = (e) => {
      const img = new Image();
      img.onload = () => {
        let w = img.width, h = img.height;
        if(w > maxWidth){
          h = Math.round((h * maxWidth) / w);
          w = maxWidth;
        }
        const canvas = document.createElement('canvas');
        canvas.width = w;
        canvas.height = h;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(img, 0, 0, w, h);
        canvas.toBlob((blob) => {
          resolve(new File([blob], file.name.replace(/\.[^.]+$/, '.jpg'), { type: 'image/jpeg' }));
        }, 'image/jpeg', quality);
      };
      img.src = e.target.result;
    };
    reader.readAsDataURL(file);
  });
}

/* ── Template Studio Management ── */
async function loadTemplates(){
  const r = await get('templates');
  allTemplates = r?.rows || [];
  const tb = $('templates-body');
  if(!tb) return;
  if(!allTemplates.length){
    tb.innerHTML = '<tr class="empty-row"><td colspan="5">No templates yet — click "+ Create Template" to build one</td></tr>';
    return;
  }
  tb.innerHTML = allTemplates.map(t => `
    <tr>
      <td><strong>${esc(t.name)}</strong></td>
      <td style="color:var(--text2);font-size:12px">${esc(t.subject || '—')}</td>
      <td><span class="badge b-gray">${esc(t.owner || 'You')}</span></td>
      <td style="color:var(--text3);font-size:11px">${t.created_at || '—'}</td>
      <td>
        <div class="btn-group">
          <button class="btn btn-secondary btn-sm" onclick="editTemplate(${t.id})">Edit</button>
          <button class="btn btn-blue btn-sm" onclick="previewTemplate(${t.id})">👁️ Preview</button>
          <button class="btn btn-purple btn-sm" onclick="duplicateTemplate(${t.id})">Copy</button>
          <button class="btn btn-danger btn-sm" onclick="deleteTemplate(${t.id})">Del</button>
        </div>
      </td>
    </tr>
  `).join('');
}

function openNewTemplateModal(){
  $('tmpl-id').value = '';
  $('tmpl-name').value = '';
  $('tmpl-subject').value = '';
  $('tmpl-text').value = '';
  $('template-modal-title').textContent = '📝 Create Email Template';
  const ed = $('tmpl-html-editor');
  if(ed) ed.innerHTML = '<p>Hi {{NAME}},</p><p>Type your beautiful email content here...</p>';
  const raw = $('tmpl-html-raw');
  if(raw){ raw.value = ed.innerHTML; raw.style.display = 'none'; }
  if(ed) ed.style.display = 'block';
  initRteToolbar('tmpl-rte-bar', 'tmpl-html-editor', 'tmpl-html-raw');
  al2('template-al');
  showModal('template-modal');
}

async function editTemplate(id){
  const r = await get('templates/' + id);
  if(!r?.row){ alert('Failed to load template'); return; }
  const t = r.row;
  $('tmpl-id').value = t.id;
  $('tmpl-name').value = t.name || '';
  $('tmpl-subject').value = t.subject || '';
  $('tmpl-text').value = t.text_body || '';
  $('template-modal-title').textContent = '✏️ Edit Template — ' + (t.name || '');
  const ed = $('tmpl-html-editor');
  if(ed) ed.innerHTML = t.html_body || '';
  const raw = $('tmpl-html-raw');
  if(raw){ raw.value = t.html_body || ''; raw.style.display = 'none'; }
  if(ed) ed.style.display = 'block';
  initRteToolbar('tmpl-rte-bar', 'tmpl-html-editor', 'tmpl-html-raw');
  al2('template-al');
  showModal('template-modal');
}

async function saveTemplateFromModal(){
  const id = $('tmpl-id').value;
  const name = $('tmpl-name').value.trim();
  const subject = $('tmpl-subject').value.trim();
  const text_body = $('tmpl-text').value.trim();
  const ed = $('tmpl-html-editor');
  const raw = $('tmpl-html-raw');
  const html_body = (ed && ed.style.display !== 'none') ? ed.innerHTML : (raw?.value || '');

  if(!name){ al('template-al','Template name is required','err'); return; }

  const payload = { name, subject, html_body, text_body };
  const r = id ? await put('templates/' + id, payload) : await post('templates', payload);
  if(r?.ok){
    al('template-al','✅ Template saved successfully!','ok');
    loadTemplates();
    setTimeout(() => closeModal('template-modal'), 800);
  } else {
    al('template-al', r?.message || r?.error || 'Save failed', 'err');
  }
}

async function duplicateTemplate(id){
  const r = await post('templates/' + id + '/duplicate', {});
  if(r?.ok){ loadTemplates(); }
  else { alert('Duplicate failed: ' + (r?.message || 'Error')); }
}

async function deleteTemplate(id){
  if(!confirm('Are you sure you want to delete this template?')) return;
  const r = await del('templates/' + id);
  if(r?.ok){ loadTemplates(); }
  else { alert('Delete failed: ' + (r?.message || 'Error')); }
}

/* ── Template Picker for Step Cards ── */
async function openTemplatePickerForStep(stepIndex, prefix){
  _targetStepIndex = stepIndex;
  _targetStepPrefix = prefix;
  const r = await get('templates');
  allTemplates = r?.rows || [];
  renderTemplatePickerGrid(allTemplates);
  showModal('template-picker-modal');
}

function renderTemplatePickerGrid(list){
  const grid = $('tmpl-picker-grid');
  if(!grid) return;
  if(!list.length){
    grid.innerHTML = '<div style="color:var(--text3);font-size:12px;grid-column:1/-1;text-align:center;padding:24px">No templates found. Go to Templates to create one.</div>';
    return;
  }
  grid.innerHTML = list.map(t => `
    <div style="background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:12px;display:flex;flex-direction:column;gap:6px;transition:all .15s" onmouseover="this.style.borderColor='var(--accent)'" onmouseout="this.style.borderColor='var(--border)'">
      <div style="font-weight:700;font-size:13px;color:var(--text)">${esc(t.name)}</div>
      <div style="font-size:11px;color:var(--text2);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(t.subject || 'No Subject')}</div>
      <div style="margin-top:auto;display:flex;gap:6px;padding-top:8px">
        <button class="btn btn-secondary btn-sm" onclick="previewTemplate(${t.id})">👁️ Preview</button>
        <button class="btn btn-primary btn-sm" style="flex:1" onclick="applyTemplateToStep(${t.id})">Apply ↵</button>
      </div>
    </div>
  `).join('');
}

function filterTemplatePicker(){
  const q = $('tmpl-picker-search')?.value.toLowerCase() || '';
  const filtered = allTemplates.filter(t => (t.name || '').toLowerCase().includes(q) || (t.subject || '').toLowerCase().includes(q));
  renderTemplatePickerGrid(filtered);
}

async function applyTemplateToStep(tmplId){
  const t = allTemplates.find(x => x.id == tmplId) || (await get('templates/' + tmplId))?.row;
  if(!t) return;
  const pid = _targetStepPrefix === 'ar' ? 'ars' : 'fus';
  const subEl = document.getElementById(pid + '-sub-' + _targetStepIndex);
  const bodyElId = pid + '-body-' + _targetStepIndex;
  const bodyEl = document.getElementById(bodyElId);
  const txtEl = document.getElementById(pid + '-txt-' + _targetStepIndex);
  const quill = window._quillEditors ? window._quillEditors[bodyElId] : null;

  if(subEl && t.subject) subEl.value = t.subject;
  if(t.html_body){
    if(quill){
      quill.root.innerHTML = t.html_body;
    }
    if(bodyEl){
      bodyEl.value = t.html_body;
    }
  }
  if(txtEl && t.text_body) txtEl.value = t.text_body;
  else if(txtEl && t.html_body) txtEl.value = htmlToPlainText(t.html_body);

  if(_targetStepPrefix === 'fu'){
    renderFuTimeline();
  }
  closeModal('template-picker-modal');
}

/* ── Live Template Desktop & Mobile Preview ── */
async function previewTemplate(id){
  const t = allTemplates.find(x => x.id == id) || (await get('templates/' + id))?.row;
  if(!t) return;
  $('tmpl-prev-title').textContent = '📱 Preview — ' + (t.name || '');
  const ifr = document.getElementById('template-preview-iframe');
  if(ifr){
    ifr.srcdoc = `
      <!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><style>body{font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;padding:16px;color:#1e293b;line-height:1.6;background:#fff;margin:0;}img{max-width:100%;height:auto;}</style></head><body>
      ${t.html_body || '<p>No content in this template.</p>'}
      </body></html>
    `;
  }
  switchPreviewDevice('desktop');
  showModal('template-preview-modal');
}

function switchPreviewDevice(dev){
  const ifr = document.getElementById('template-preview-iframe');
  const btnDesk = $('btn-prev-desk');
  const btnMob = $('btn-prev-mob');
  const btnDark = $('btn-prev-dark');
  const btnLight = $('btn-prev-light');
  if(!ifr) return;

  if(dev === 'mobile'){
    ifr.className = 'device-frame-mobile';
    btnDesk?.classList.remove('active');
    btnMob?.classList.add('active');
  } else if(dev === 'desktop'){
    ifr.className = 'device-frame-desktop';
    btnDesk?.classList.add('active');
    btnMob?.classList.remove('active');
  } else if(dev === 'dark'){
    btnDark?.classList.add('active');
    btnLight?.classList.remove('active');
    try {
      const doc = ifr.contentDocument || ifr.contentWindow.document;
      if (doc && doc.body) {
        doc.body.style.background = '#0F172A';
        doc.body.style.color = '#F8FAFC';
      }
    } catch(_e){}
  } else if(dev === 'light'){
    btnLight?.classList.add('active');
    btnDark?.classList.remove('active');
    try {
      const doc = ifr.contentDocument || ifr.contentWindow.document;
      if (doc && doc.body) {
        doc.body.style.background = '#FFFFFF';
        doc.body.style.color = '#0F172A';
      }
    } catch(_e){}
  }
}

/* ══════════════════════════════════════════════════════════════════
   SYSTEM & DELIVERABILITY ACTIVITY LOGS
   ══════════════════════════════════════════════════════════════════ */
let _sysLogsCurrentPage = 1;
let _sysLogTimer = null;

function sysLogDebounce(){
  if(_sysLogTimer) clearTimeout(_sysLogTimer);
  _sysLogTimer = setTimeout(() => loadSystemLogs(1), 350);
}

async function loadSystemLogs(page = 1){
  _sysLogsCurrentPage = page;
  const event = $('sys-event-filter')?.value || '';
  const search = $('sys-email-filter')?.value || '';
  const qs = '&page=' + page + (event ? '&event=' + encodeURIComponent(event) : '') + (search ? '&search=' + encodeURIComponent(search) : '');
  const r = await get('system-logs?' + qs);
  const tb = $('sys-logs-body');
  if(!tb) return;
  if(!r?.rows?.length){
    tb.innerHTML = '<tr class="empty-row"><td colspan="7">No activity logged yet</td></tr>';
    $('sys-logs-pager').innerHTML = '';
    return;
  }
  const badges = {
    sent: 'sys-badge sys-badge-sent',
    opened: 'sys-badge sys-badge-opened',
    clicked: 'sys-badge sys-badge-clicked',
    queued: 'sys-badge sys-badge-queued',
    retry: 'sys-badge sys-badge-retry',
    failed: 'sys-badge sys-badge-failed',
    unsubscribed: 'sys-badge sys-badge-unsubscribed'
  };
  const icons = {
    sent: '📤', opened: '👁️', clicked: '🖱️', queued: '🕒', retry: '🔄', failed: '❌', unsubscribed: '🛑'
  };

  tb.innerHTML = r.rows.map(l => `
    <tr>
      <td><span class="${badges[l.event_type] || 'badge b-gray'}">${icons[l.event_type] || '•'} ${esc(l.event_type.toUpperCase())}</span></td>
      <td class="mono" style="font-size:11px;font-weight:600">${esc(l.recipient_email || '—')}</td>
      <td style="font-size:11px;color:var(--text2);max-width:280px;overflow:hidden;text-overflow:ellipsis">
        ${esc(l.link_url || l.subject || l.error_message || l.details || '—')}
      </td>
      <td style="font-size:10px;color:var(--text3)">${esc(l.smtp_host || '—')}</td>
      <td class="mono" style="font-size:10px">${esc(l.ip_address || '—')}</td>
      <td style="font-size:10px;color:var(--text3);max-width:140px;overflow:hidden;text-overflow:ellipsis" title="${esc(l.user_agent || '')}">${esc(l.user_agent ? l.user_agent.substring(0,25)+'…' : '—')}</td>
      <td style="font-size:10px;color:var(--text2);white-space:nowrap">${l.created_at || '—'}</td>
    </tr>
  `).join('');

  const pg = $('sys-logs-pager');
  if(r.pages > 1){
    let h = '';
    if(page > 1) h += `<button class="btn btn-secondary btn-sm" onclick="loadSystemLogs(${page-1})">← Prev</button>`;
    h += `<span style="font-size:11px;color:var(--text3)">Page ${page} of ${r.pages} (${fmt(r.total)} events)</span>`;
    if(page < r.pages) h += `<button class="btn btn-secondary btn-sm" onclick="loadSystemLogs(${page+1})">Next →</button>`;
    pg.innerHTML = h;
  } else {
    pg.innerHTML = `<span style="font-size:11px;color:var(--text3)">${fmt(r.total)} total events</span>`;
  }
}

async function loadSystemLogStats(){
  const s = await get('system-logs/stats');
  if(!s?.stats) return;
  const st = s.stats;
  set('sys-stat-sent-today', fmt(st.sent_today || 0));
  set('sys-stat-opened', fmt(st.opened || 0));
  set('sys-stat-open-rate', (st.open_rate || 0) + '% open rate');
  set('sys-stat-clicked', fmt(st.clicked || 0));
  set('sys-stat-click-rate', (st.click_rate || 0) + '% CTR');
  set('sys-stat-sched-fu', fmt(st.scheduled_followups || 0));
  set('sys-stat-retry-queue', fmt(st.retry_queue || 0));
  set('sys-stat-failed-today', fmt(st.failed_today || 0));
  set('sys-stat-unsub', fmt(st.unsubscribed || 0));
}

async function clearSystemLogs(){
  if(!confirm('Are you sure you want to clear all system activity logs?')) return;
  const r = await del('system-logs');
  if(r?.ok){
    loadSystemLogs(1);
    loadSystemLogStats();
  } else {
    alert('Clear failed: ' + (r?.message || 'Error'));
  }
}

/* FU Contacts */
async function openFuContacts(id,name){
  fuContactsId=id;
  $('fu-contacts-title').textContent='👥 Contacts — '+name;
  al2('fu-contacts-al');
  const el=$('fu-enroll-list');
  el.innerHTML='<option value="">— select list —</option>'+allLists.map(l=>`<option value="${l.id}">${esc(l.name)} (${fmt(l.total_count)})</option>`).join('');
  showModal('fu-contacts-modal');
  loadFuContacts(1);
}
async function loadFuContacts(page=1){
  const r=await get('followup/'+fuContactsId+'/contacts?page='+page);
  const tb=$('fu-contacts-body');
  if(!r?.rows?.length){tb.innerHTML='<tr class="empty-row"><td colspan="6">No contacts enrolled yet</td></tr>';$('fu-contacts-pager').innerHTML='';return;}
  const sc={active:'b-green',completed:'b-gray',stopped:'b-red'};
  tb.innerHTML=r.rows.map(c=>`<tr>
    <td class="mono" style="font-size:11px">${esc(c.email)}</td>
    <td>${esc(c.name||'—')}</td>
    <td><span class="badge b-purple">Step ${c.current_step}</span></td>
    <td style="font-size:11px;color:var(--text2)">${c.next_send_at||'—'}</td>
    <td><span class="badge ${sc[c.status]||'b-gray'}">${c.status}</span></td>
    <td><button class="btn btn-danger btn-sm" onclick="removeFuContact(${c.id})">Remove</button></td>
  </tr>`).join('');
  const pg=$('fu-contacts-pager');
  if(r.pages>1){let h='';if(page>1)h+=`<button class="btn btn-secondary btn-sm" onclick="loadFuContacts(${page-1})">← Prev</button>`;h+=`<span style="font-size:11px;color:var(--text3)">Page ${page}/${r.pages}</span>`;if(page<r.pages)h+=`<button class="btn btn-secondary btn-sm" onclick="loadFuContacts(${page+1})">Next →</button>`;pg.innerHTML=h;}else pg.innerHTML='';}
async function fuEnrollList(){
  const listId=$('fu-enroll-list').value;
  if(!listId){al('fu-contacts-al','Select a list','err');return;}
  const r=await post('followup/'+fuContactsId+'/enroll',{list_id:parseInt(listId)});
  if(r?.ok){al('fu-contacts-al','✅ Enrolled '+r.enrolled+' contacts','ok');loadFuContacts(1);}
  else al('fu-contacts-al',r?.message||'Error','err');
}
async function fuEnrollCsv(input){
  if(!input.files[0])return;
  al('fu-contacts-al','⏳ Uploading…','inf');
  const fd=new FormData();fd.append('file',input.files[0]);
  const r=await fetch(API('followup/'+fuContactsId+'/enroll'),{method:'POST',credentials:'same-origin',body:fd}).then(x=>x.json()).catch(()=>({ok:false}));
  input.value='';
  if(r?.ok){al('fu-contacts-al','✅ Enrolled '+r.enrolled+' contacts','ok');loadFuContacts(1);}
  else al('fu-contacts-al',r?.message||'Error','err');
}
async function removeFuContact(cid){if(!confirm('Remove?'))return;await del('followup/'+fuContactsId+'/contact/'+cid);loadFuContacts(1);}

/* FU Logs */
let fuLogsCurrentRuleId = null, fuLogsCurrentRuleName = '';
async function openFuLogs(id,name){
  fuLogsCurrentRuleId = id; fuLogsCurrentRuleName = name;
  $('fu-logs-title').textContent='📋 Logs — '+name;
  const clearBtn = $('fu-logs-clear-btn');
  if(clearBtn) clearBtn.style.display = (S.isAdmin) ? 'inline-block' : 'none';
  $('fu-logs-body').innerHTML='<tr class="empty-row"><td colspan="6">Loading…</td></tr>';
  showModal('fu-logs-modal');
  const rows=await get('followup/'+id+'/logs');
  const tb=$('fu-logs-body');
  if(!rows?.length){tb.innerHTML='<tr class="empty-row"><td colspan="6">No logs yet</td></tr>';return;}
  tb.innerHTML=rows.map(l=>`<tr>
    <td class="mono" style="font-size:11px">${esc(l.email)}</td>
    <td><span class="badge b-purple">Step ${l.step_number}</span></td>
    <td>${l.status==='sent'?'<span class="badge b-green">✅ Sent</span>':'<span class="badge b-red">❌ Failed</span>'}</td>
    <td style="font-size:11px">${esc(l.smtp_used||'—')}</td>
    <td style="font-size:10px;color:var(--red)">${esc(l.error||'')}</td>
    <td style="font-size:10px;color:var(--text2)">${l.sent_at||'—'}</td>
  </tr>`).join('');
}
async function clearFuLogs(){
  if(!fuLogsCurrentRuleId) return;
  if(!confirm('Clear all logs for follow-up rule "'+fuLogsCurrentRuleName+'"?')) return;
  const r = await del('followup/'+fuLogsCurrentRuleId+'/logs');
  if(r?.ok) openFuLogs(fuLogsCurrentRuleId, fuLogsCurrentRuleName);
  else alert('Error: '+(r?.message||r?.error||'Failed to clear logs'));
}

/* ─── Mobile Sidebar ─────────────────────── */
function toggleSidebar(){
  const sb=$('sidebar'),ov=$('sidebar-overlay');
  sb.classList.toggle('open');
  ov.classList.toggle('on');
}
function closeSidebar(){
  $('sidebar')?.classList.remove('open');
  $('sidebar-overlay')?.classList.remove('on');
}
// Close sidebar when nav item clicked on mobile
document.querySelectorAll('.ni').forEach(ni=>ni.addEventListener('click',()=>{
  if(window.innerWidth<=900)closeSidebar();
}));

/* ══════════════════════════════════════════════════════════════════
   BLACKLIST
   Existing 'email' / 'domain' behaviour is unchanged. Two new types
   ('subject', 'keyword') are handled by the same generic helpers below;
   the legacy paths still flow through this code with identical results.
   ══════════════════════════════════════════════════════════════════ */
let blEmailPage=1, blDomainPage=1, blSubjectPage=1, blKeywordPage=1;
const blDebTimers={ email:null, domain:null, extension:null, subject:null, keyword:null };
// Per-type config so empty-state strings + alerts read naturally for
// each kind without forking the loaders.
const BL_TYPES = {
  email:   { col:'email',   plural:'email addresses', noun:'email address',
             pageVar:()=>blEmailPage,  setPage:p=>blEmailPage=p,
             validate:v=>{v=v.trim();return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)?'':'Invalid email address — e.g. user@example.com';},
             clearMsg:'email addresses' },
  domain:  { col:'domain',  plural:'domains', noun:'domain',
             pageVar:()=>blDomainPage, setPage:p=>blDomainPage=p,
             validate:v=>{v=v.trim();if(v.includes('@'))return 'Enter just the domain (e.g. example.com) without @';if(!/^[a-z0-9]([a-z0-9\-]*\.)+[a-z]{2,}$/i.test(v))return 'Invalid domain — use e.g. example.com or mail.example.co.uk';return '';},
             clearMsg:'domains' },
  subject: { col:'phrase',  plural:'subject phrases', noun:'subject phrase',
             pageVar:()=>blSubjectPage,setPage:p=>blSubjectPage=p,
             validate:v=>v.trim().length<2?'Phrase too short — enter at least 2 characters':'',
             clearMsg:'subject phrases' },
  keyword: { col:'phrase',  plural:'phrases', noun:'phrase',
             pageVar:()=>blKeywordPage,setPage:p=>blKeywordPage=p,
             validate:v=>v.trim().length<2?'Phrase too short — enter at least 2 characters':'',
             clearMsg:'has-the-words phrases' },
  extension:{ col:'domain',  plural:'extensions', noun:'domain extension',
             pageVar:()=>blExtensionPage,setPage:p=>blExtensionPage=p,
             validate:v=>{const t=v.trim().startsWith('.')?v.trim().slice(1):v.trim();return /^[a-z0-9][a-z0-9\-.]{0,50}$/i.test(t)?'':'Invalid extension — use e.g. .com or co.uk';},
             clearMsg:'blocked extensions' },
};
let blExtensionPage = 1;

function blSearchDebounce(type){
  const t = blDebTimers[type];
  if (t) clearTimeout(t);
  blDebTimers[type] = setTimeout(()=>loadBlacklist(type,1),350);
}

async function loadBlacklistPage(){
  await Promise.all([
    loadBlacklist('email',1),
    loadBlacklist('domain',1),
    loadBlacklist('extension',1),
    loadBlacklist('subject',1),
    loadBlacklist('keyword',1),
  ]);
  updateBlStats();
}

async function updateBlStats(){
  const r=await get('blacklist/stats');
  if(r){
    set('bl-stat-emails',     r.emails     ?? 0);
    set('bl-stat-domains',    r.domains    ?? 0);
    set('bl-stat-extensions', r.extensions ?? 0);
    set('bl-stat-subjects',   r.subjects   ?? 0);
    set('bl-stat-keywords',   r.keywords   ?? 0);
    set('bl-stat-total',      (r.emails??0) + (r.domains??0) + (r.extensions??0) + (r.subjects??0) + (r.keywords??0));
  }
}

async function loadBlacklist(type,page=1){
  const cfg = BL_TYPES[type]; if (!cfg) return;
  cfg.setPage(page);
  const q=v('bl-search-'+type)||'';
  const r=await get('blacklist?type='+type+'&page='+page+(q?'&q='+encodeURIComponent(q):''));
  const tbId='bl-'+type+'-body', pgId='bl-'+type+'-pager';
  const tb=$(tbId);
  if(!r||!r.rows){
    if(tb)tb.innerHTML='<tr class="empty-row"><td colspan="3">No blocked '+cfg.plural+' yet</td></tr>';
    if($(pgId))$(pgId).innerHTML='';
    return;
  }
  if(!r.rows.length){
    tb.innerHTML='<tr class="empty-row"><td colspan="3">No blocked '+cfg.plural+' — you\'re all clear</td></tr>';
    $(pgId).innerHTML='';
    return;
  }
  tb.innerHTML=r.rows.map(row=>`<tr>
    <td class="mono" style="font-size:12px">${esc(row[cfg.col]||row.value||'')}</td>
    <td style="font-size:11px;color:var(--text2)">${(row.created_at||'').slice(0,10)}</td>
    <td><button class="btn btn-danger btn-sm" onclick="delBlacklist(${row.id},'${type}')">Remove</button></td>
  </tr>`).join('');
  // Named globals for renderPager — arrow expressions have empty .name and
  // would break the inline onclick the pager generates.
  const pageFn = type==='email'     ? loadBlEmailPage
               : type==='domain'    ? loadBlDomainPage
               : type==='extension' ? loadBlExtensionPage
               : type==='subject'   ? loadBlSubjectPage
               : loadBlKeywordPage;
  renderPager(pgId,r.pages,page,pageFn);
}
function loadBlEmailPage(p){   return loadBlacklist('email',p); }
function loadBlDomainPage(p){  return loadBlacklist('domain',p); }
function loadBlSubjectPage(p){ return loadBlacklist('subject',p); }
function loadBlKeywordPage(p){ return loadBlacklist('keyword',p); }
function loadBlExtensionPage(p){ return loadBlacklist('extension',p); }

// Quick-block a domain extension (TLD) — called by quick-add buttons
async function quickBlockExtension(ext) {
  const alId = 'bl-extension-al';
  const r = await post('blacklist', {type:'extension', value: ext});
  if (r?.ok) {
    al(alId, '✅ ' + ext + ' blocked — all inbound emails from *' + ext + ' addresses will be completely ignored.', 'ok');
    loadBlacklist('extension', 1);
    updateBlStats();
  } else {
    al(alId, r?.message || r?.error || 'Error blocking ' + ext, (r?.message==='Extension already blacklisted'?'warn':'err'));
  }
}

async function addBlacklist(type){
  const cfg = BL_TYPES[type]; if (!cfg) return;
  const inpId='bl-'+type+'-inp', alId='bl-'+type+'-al';
  const val=(v(inpId)||'').trim();
  if(!val){al(alId,'Please enter a '+cfg.noun,'err');return;}
  const vErr = cfg.validate(val);
  if (vErr) { al(alId, vErr, 'err'); return; }
  const r=await post('blacklist',{type,value:val});
  if(r?.ok){
    const what = (type==='email')     ? 'address'
               : (type==='domain')    ? 'domain'
               : (type==='extension') ? 'extension'
               : (type==='subject')   ? 'subject phrase'
                                      : 'phrase';
    al(alId,'✅ Blocked! Inbound messages matching this '+what+' will be skipped.','ok');
    sv(inpId,'');
    loadBlacklist(type,1);
    updateBlStats();
  } else al(alId,r?.message||r?.error||'Error','err');
}

async function delBlacklist(id,type){
  if(!confirm('Remove from blacklist?'))return;
  const r=await del('blacklist/'+id);
  if(r?.ok){loadBlacklist(type,1);updateBlStats();showToast('Removed from blacklist','ok');}
  else alert(r?.message||'Error');
}

async function clearAllBlacklist(type){
  const cfg = BL_TYPES[type]; if (!cfg) return;
  if(!confirm('Clear ALL blocked '+cfg.clearMsg+'? They will no longer be suppressed.'))return;
  const r=await del('blacklist?type='+type);
  if(r?.ok){loadBlacklist(type,1);updateBlStats();showToast('Blacklist cleared','ok');}
  else alert(r?.message||'Error');
}

// ── Theme Switcher Mode (Light / Dark) ──
function initThemeMode() {
  const saved = localStorage.getItem('mailszo_theme') || 'dark';
  document.documentElement.setAttribute('data-theme', saved);
  const btn = document.getElementById('theme-toggle-btn');
  if (btn) btn.innerHTML = saved === 'light' ? '☀️' : '🌙';
}
function toggleThemeMode() {
  const cur = document.documentElement.getAttribute('data-theme') || 'dark';
  const nxt = cur === 'dark' ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', nxt);
  localStorage.setItem('mailszo_theme', nxt);
  const btn = document.getElementById('theme-toggle-btn');
  if (btn) btn.innerHTML = nxt === 'light' ? '☀️' : '🌙';
  showToast(nxt === 'light' ? 'Light mode enabled' : 'Dark mode enabled', 'info');
}

// ── Collapsible Sidebar ──
function toggleSidebarCollapse() {
  document.body.classList.toggle('collapsed-sb');
  const isColl = document.body.classList.contains('collapsed-sb');
  localStorage.setItem('mailszo_sb_collapsed', isColl ? '1' : '0');
}
function initSidebarCollapse() {
  if (localStorage.getItem('mailszo_sb_collapsed') === '1') {
    document.body.classList.add('collapsed-sb');
  }
}

// ── Toast Notifications ──
function showToast(msg, type = 'info') {
  let box = document.getElementById('toast-container');
  if (!box) {
    box = document.createElement('div');
    box.id = 'toast-container';
    box.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:99999;display:flex;flex-direction:column;gap:8px;pointer-events:none';
    document.body.appendChild(box);
  }
  const t = document.createElement('div');
  t.style.cssText = 'pointer-events:auto;padding:12px 18px;border-radius:10px;font-size:13px;font-weight:600;display:flex;align-items:center;gap:10px;box-shadow:0 10px 30px rgba(0,0,0,0.5);backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,0.15);transition:all 0.25s ease';
  if (type === 'ok' || type === 'success') {
    t.style.background = 'linear-gradient(135deg, rgba(74,222,128,0.25) 0%, rgba(34,211,238,0.15) 100%)';
    t.style.color = '#4ade80'; t.style.borderColor = 'rgba(74,222,128,0.4)';
    t.innerHTML = '<span>✅</span><span>' + msg + '</span>';
  } else if (type === 'err' || type === 'error') {
    t.style.background = 'linear-gradient(135deg, rgba(248,113,113,0.25) 0%, rgba(239,68,68,0.15) 100%)';
    t.style.color = '#f87171'; t.style.borderColor = 'rgba(248,113,113,0.4)';
    t.innerHTML = '<span>⚠️</span><span>' + msg + '</span>';
  } else {
    t.style.background = 'linear-gradient(135deg, rgba(34,211,238,0.25) 0%, rgba(56,189,248,0.15) 100%)';
    t.style.color = '#22d3ee'; t.style.borderColor = 'rgba(34,211,238,0.4)';
    t.innerHTML = '<span>ℹ️</span><span>' + msg + '</span>';
  }
  box.appendChild(t);
  setTimeout(() => { t.style.opacity = '0'; t.style.transform = 'translateY(10px)'; setTimeout(() => t.remove(), 250); }, 3500);
}

// ── 2026 Raycast / Linear Style Command Palette (⌘K) ──
const CMD_PALETTE_ITEMS = [
  // Navigation
  { title: 'Dashboard & Telemetry', page: 'dashboard', icon: '📊', group: 'Navigation', kbd: 'G D' },
  { title: 'Email Campaigns', page: 'campaigns', icon: '📤', group: 'Navigation', kbd: 'G C' },
  { title: 'Step-by-Step Reporting', page: 'stepreporting', icon: '📑', group: 'Navigation', kbd: 'G R' },
  { title: 'Email Templates', page: 'templates', icon: '📝', group: 'Navigation', kbd: 'G T' },
  { title: 'Image Asset Gallery', page: 'images', icon: '🖼️', group: 'Navigation', kbd: 'G I' },
  { title: 'Email Lists & Contacts', page: 'lists', icon: '👥', group: 'Navigation', kbd: 'G L' },
  { title: 'IMAP Accounts Manager', page: 'imap', icon: '📥', group: 'Automation', kbd: 'G M' },
  { title: 'Auto-Reply Rules Studio', page: 'autoreply', icon: '🔁', group: 'Automation', kbd: 'G A' },
  { title: 'Smart Mail Routing (IMAP + SMTP)', page: 'mailrouting', icon: '🔀', group: 'Automation', kbd: 'G S' },
  { title: 'Follow-Up Sequence Studio', page: 'followup', icon: '📬', group: 'Automation', kbd: 'G F' },
  { title: 'Blacklist & Suppression Engine', page: 'blacklist', icon: '🚫', group: 'Automation', kbd: 'G B' },
  { title: 'System Activity Logs', page: 'systemlogs', icon: '🛰️', group: 'Logs', kbd: 'G S' },
  { title: 'CRM Leads Manager', page: 'leads', icon: '🗄️', group: 'Leads', kbd: 'G C' },
  { title: 'SMTP Servers & Quotas', page: 'smtp', icon: '🔌', group: 'Settings', kbd: 'G P' },
  { title: 'Sender Display Name', page: 'displayname', icon: '✍️', group: 'Settings', kbd: 'G N' },
  { title: 'My Account & Security', page: 'account', icon: '🔐', group: 'Settings', kbd: 'G U' },
  { title: 'User Management (Admin)', page: 'users', icon: '👤', group: 'Admin', kbd: 'A U' },
  { title: 'Cron Task Manager (Admin)', page: 'cron', icon: '⚙️', group: 'Admin', kbd: 'A C' },
  { title: 'All Send Logs (Admin)', page: 'alllogs', icon: '📋', group: 'Admin', kbd: 'A L' },
  // Quick Actions
  { title: 'Create New Campaign', action: () => { nav('campaigns'); openCampaignModal(); }, icon: '⚡', group: 'Quick Actions', kbd: 'C' },
  { title: 'New Auto-Reply Rule', action: () => { nav('autoreply'); openArModal(); }, icon: '⚡', group: 'Quick Actions', kbd: 'A' },
  { title: 'New Follow-Up Rule', action: () => { nav('followup'); openFuModal(); }, icon: '⚡', group: 'Quick Actions', kbd: 'F' },
  { title: 'Add SMTP Provider', action: () => { nav('smtp'); openSmtpModal(); }, icon: '⚡', group: 'Quick Actions', kbd: 'S' },
  { title: 'Add IMAP Account', action: () => { nav('imap'); openImapModal(); }, icon: '⚡', group: 'Quick Actions', kbd: 'I' },
  { title: 'Upload CSV Contacts', action: () => { nav('lists'); openImportModal(); }, icon: '⚡', group: 'Quick Actions', kbd: 'U' },
  { title: 'Clear Dashboard Data', action: () => { openClearDashModal(); }, icon: '🗑️', group: 'Admin Actions', kbd: 'D' },
  { title: 'Toggle Dark / Light Theme', action: () => { toggleThemeMode(); }, icon: '🌗', group: 'Preferences', kbd: 'T' }
];

let _cmdSelectedIndex = 0;
let _cmdCurrentMatches = [];

function openCommandPalette() {
  let m = document.getElementById('cmd-palette-bg');
  if (!m) {
    m = document.createElement('div');
    m.id = 'cmd-palette-bg';
    m.onclick = (e) => { if (e.target === m) closeCommandPalette(); };
    m.innerHTML = `
      <div class="cmd-palette-box">
        <div class="cmd-inp-wrap">
          <span style="font-size:18px;color:var(--accent)">⌘</span>
          <input type="text" id="cmd-palette-inp" class="cmd-inp" placeholder="Type a command or search pages (e.g. 'Campaign', 'SMTP', 'Auto-Reply')..." oninput="filterCommandPalette()" autocomplete="off">
          <span style="cursor:pointer;color:var(--text3);font-size:16px" onclick="closeCommandPalette()">✕</span>
        </div>
        <div class="cmd-list" id="cmd-palette-results"></div>
        <div class="cmd-foot">
          <span><span class="cmd-item-kbd">↑</span> <span class="cmd-item-kbd">↓</span> Navigate</span>
          <span><span class="cmd-item-kbd">↵</span> Select</span>
          <span><span class="cmd-item-kbd">ESC</span> Close</span>
        </div>
      </div>
    `;
    document.body.appendChild(m);
  }
  m.classList.add('on');
  const inp = document.getElementById('cmd-palette-inp');
  if (inp) {
    inp.value = '';
    inp.focus();
    _cmdSelectedIndex = 0;
    filterCommandPalette();
  }
}

function closeCommandPalette() {
  const m = document.getElementById('cmd-palette-bg');
  if (m) m.classList.remove('on');
}

function filterCommandPalette() {
  const q = (document.getElementById('cmd-palette-inp')?.value || '').toLowerCase().trim();
  const box = document.getElementById('cmd-palette-results');
  if (!box) return;

  _cmdCurrentMatches = CMD_PALETTE_ITEMS.filter(i => {
    if (!S?.isAdmin && i.group === 'Admin') return false;
    if (!q) return true;
    return i.title.toLowerCase().includes(q) || i.group.toLowerCase().includes(q) || (i.kbd && i.kbd.toLowerCase().includes(q));
  });

  if (_cmdSelectedIndex >= _cmdCurrentMatches.length) _cmdSelectedIndex = 0;

  if (!_cmdCurrentMatches.length) {
    box.innerHTML = '<div style="padding:28px 16px;text-align:center;color:var(--text3);font-size:13px">No commands found matching "<strong>' + esc(q) + '</strong>"</div>';
    return;
  }

  let html = '';
  let curGroup = '';
  _cmdCurrentMatches.forEach((item, idx) => {
    if (item.group !== curGroup) {
      curGroup = item.group;
      html += `<div class="cmd-group-hd">${curGroup}</div>`;
    }
    const isSel = idx === _cmdSelectedIndex ? ' selected' : '';
    html += `
      <div class="cmd-item${isSel}" data-idx="${idx}" onclick="executeCmdItem(${idx})" onmouseover="selectCmdIndex(${idx})">
        <span class="cmd-item-ic">${item.icon}</span>
        <span class="cmd-item-txt">${item.title}</span>
        ${item.kbd ? `<span class="cmd-item-kbd">${item.kbd}</span>` : ''}
      </div>
    `;
  });
  box.innerHTML = html;

  const selEl = box.querySelector('.cmd-item.selected');
  if (selEl) selEl.scrollIntoView({ block: 'nearest' });
}

function selectCmdIndex(idx) {
  _cmdSelectedIndex = idx;
  const items = document.querySelectorAll('#cmd-palette-results .cmd-item');
  items.forEach((el, i) => {
    el.classList.toggle('selected', i === idx);
  });
}

function executeCmdItem(idx) {
  const item = _cmdCurrentMatches[idx];
  if (!item) return;
  closeCommandPalette();
  if (item.action) {
    item.action();
  } else if (item.page) {
    nav(item.page);
  }
}

// ── Notification Center Drawer ──
function toggleNotifDrawer() {
  let drawer = document.getElementById('notif-drawer');
  let bg = document.getElementById('notif-drawer-bg');
  if (!drawer) {
    bg = document.createElement('div');
    bg.id = 'notif-drawer-bg';
    bg.onclick = toggleNotifDrawer;
    document.body.appendChild(bg);

    drawer = document.createElement('div');
    drawer.id = 'notif-drawer';
    drawer.innerHTML = `
      <div class="notif-hd">
        <div style="display:flex;align-items:center;gap:8px">
          <span style="font-size:18px">🔔</span>
          <h3 style="font-size:15px;font-weight:700">Notifications &amp; Activity</h3>
        </div>
        <span class="modal-x" onclick="toggleNotifDrawer()">✕</span>
      </div>
      <div class="notif-body" id="notif-drawer-body">
        <div class="notif-item">
          <div class="notif-title"><span>⚡</span> Engine Status: Optimal</div>
          <div class="notif-desc">All IMAP polling daemons &amp; SMTP deliverability routes are active and responsive.</div>
          <div class="notif-time">Just now</div>
        </div>
        <div class="notif-item">
          <div class="notif-title"><span>🛡️</span> Deliverability Guard Active</div>
          <div class="notif-desc">RFC 5322 Reply-To header synchronization running smoothly with 0 bounce rate.</div>
          <div class="notif-time">2 mins ago</div>
        </div>
        <div class="notif-item">
          <div class="notif-title"><span>🔄</span> Auto-Reply Step 1 Synchronized</div>
          <div class="notif-desc">Secondary mailbox listeners active for incoming reply thread routing.</div>
          <div class="notif-time">5 mins ago</div>
        </div>
      </div>
      <div style="padding:14px 20px;border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;background:rgba(0,0,0,0.15)">
        <span style="font-size:11px;color:var(--text3)">Auto-clears in 24h</span>
        <button class="btn btn-secondary btn-sm" onclick="showToast('Notifications marked as read','ok')">Mark All Read</button>
      </div>
    `;
    document.body.appendChild(drawer);
  }

  const isOpen = drawer.classList.contains('open');
  if (isOpen) {
    drawer.classList.remove('open');
    bg.classList.remove('on');
  } else {
    drawer.classList.add('open');
    bg.classList.add('on');
    const dot = document.getElementById('notif-pill-dot');
    if (dot) dot.style.display = 'none';
  }
}

// ── Quick Create Dropdown Menu ──
function toggleQuickCreateMenu(e) {
  if (e) e.stopPropagation();
  const m = document.getElementById('quick-create-menu');
  if (!m) return;
  const isShown = m.style.display === 'block';
  m.style.display = isShown ? 'none' : 'block';
}

document.addEventListener('click', (e) => {
  const m = document.getElementById('quick-create-menu');
  if (m && m.style.display === 'block' && !e.target.closest('#quick-create-menu')) {
    m.style.display = 'none';
  }
});

// ── Global Keyboard Shortcuts ──
window.addEventListener('keydown', (e) => {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
    e.preventDefault();
    openCommandPalette();
    return;
  }
  const cmdBg = document.getElementById('cmd-palette-bg');
  if (cmdBg && cmdBg.classList.contains('on')) {
    if (e.key === 'Escape') {
      e.preventDefault();
      closeCommandPalette();
    } else if (e.key === 'ArrowDown') {
      e.preventDefault();
      if (_cmdSelectedIndex < _cmdCurrentMatches.length - 1) {
        selectCmdIndex(_cmdSelectedIndex + 1);
        const selEl = document.querySelectorAll('#cmd-palette-results .cmd-item')[_cmdSelectedIndex];
        if (selEl) selEl.scrollIntoView({ block: 'nearest' });
      }
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      if (_cmdSelectedIndex > 0) {
        selectCmdIndex(_cmdSelectedIndex - 1);
        const selEl = document.querySelectorAll('#cmd-palette-results .cmd-item')[_cmdSelectedIndex];
        if (selEl) selEl.scrollIntoView({ block: 'nearest' });
      }
    } else if (e.key === 'Enter') {
      e.preventDefault();
      executeCmdItem(_cmdSelectedIndex);
    }
  }
});

// ── Floating Action Button (FAB) ──
function initFAB() {
  if (document.getElementById('global-fab-btn')) return;
  const fab = document.createElement('div');
  fab.id = 'global-fab-btn';
  fab.className = 'fab-btn';
  fab.title = 'Quick Actions (⌘K)';
  fab.innerHTML = '+';
  fab.onclick = openCommandPalette;
  document.body.appendChild(fab);
}

initThemeMode();
initSidebarCollapse();
initFAB();

boot();
</script>