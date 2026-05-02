<?php
session_start();
require_once 'db.php';
if (isset($_SESSION['user_logged_in']) && empty($_GET['view'])) { header("Location: dashboard.php"); exit; }
$site_settings_stmt = $pdo->query("SELECT setting_name, setting_value FROM system_settings WHERE setting_name IN ('site_logo', 'site_favicon')");
$site_settings = $site_settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$site_logo     = $site_settings['site_logo']    ?? '';
$site_favicon  = $site_settings['site_favicon'] ?? '';
?>
<!DOCTYPE html>
<html lang="bn" data-theme="dark">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0,user-scalable=no"/>
<title>Sodai Lagbe | Shareholder ERP</title>
<?php if (!empty($site_favicon)): ?><link rel="icon" href="<?= htmlspecialchars($site_favicon) ?>"><?php else: ?><link rel="icon" href="favicon.ico"><?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<style>
/* ══ RESET ══ */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}

/* ══ THEME VARIABLES ══ */
:root{
  --red:#e63946;--orange:#f4845f;--amber:#fbbf24;
  --emerald:#06d6a0;--blue:#4361ee;--indigo:#3a0ca3;
}

/* DARK MODE (default) */
[data-theme="dark"]{
  --bg:#0d1117;--card:#161b22;--card2:#1c2128;
  --border:rgba(255,255,255,.08);--border2:rgba(255,255,255,.14);
  --text:#e6edf3;--muted:#8b949e;--muted2:#6e7681;
  --nav-bg:rgba(13,17,23,.92);
  --hero-orb1:rgba(230,57,70,.22);--hero-orb2:rgba(67,97,238,.18);--hero-orb3:rgba(6,214,160,.13);
  --grid-line:rgba(255,255,255,.025);
  --section-bg:transparent;
  --exp-bg:rgba(255,255,255,.02);
  --exp-border:rgba(255,255,255,.06);
  --exp-card:#161b22;
  --inv-node-bg:rgba(67,97,238,.06);
  --noise-op:.35;
  --shadow-card:0 30px 60px rgba(0,0,0,.4);
}

/* LIGHT MODE */
[data-theme="light"]{
  --bg:#f8fafc;--card:#ffffff;--card2:#f1f5f9;
  --border:rgba(0,0,0,.08);--border2:rgba(0,0,0,.14);
  --text:#0f172a;--muted:#64748b;--muted2:#94a3b8;
  --nav-bg:rgba(248,250,252,.95);
  --hero-orb1:rgba(230,57,70,.1);--hero-orb2:rgba(67,97,238,.08);--hero-orb3:rgba(6,214,160,.07);
  --grid-line:rgba(0,0,0,.04);
  --section-bg:#f1f5f9;
  --exp-bg:#ffffff;
  --exp-border:rgba(0,0,0,.07);
  --exp-card:#f8fafc;
  --inv-node-bg:rgba(67,97,238,.04);
  --noise-op:.1;
  --shadow-card:0 20px 60px rgba(15,23,42,.08);
}

body{font-family:'Sora',sans-serif;background:var(--bg);color:var(--text);overflow-x:hidden;-webkit-font-smoothing:antialiased;transition:background .35s,color .35s}

body::before{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 512 512' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.04'/%3E%3C/svg%3E");pointer-events:none;z-index:9999;opacity:var(--noise-op)}

/* ══ NAV ══ */
.nav{position:fixed;top:0;left:0;right:0;z-index:200;height:64px;padding:0 20px;display:flex;align-items:center;justify-content:space-between;background:var(--nav-bg);backdrop-filter:blur(20px);border-bottom:1px solid var(--border);transition:box-shadow .3s,background .35s}
.nav-logo{display:flex;align-items:center;gap:10px;text-decoration:none}
.nav-logo-icon{width:38px;height:38px;border-radius:12px;background:linear-gradient(135deg,var(--red),var(--orange));display:flex;align-items:center;justify-content:center;color:#fff;font-size:16px;box-shadow:0 0 20px rgba(230,57,70,.4);flex-shrink:0}
.nav-logo-text{font-size:15px;font-weight:800;color:var(--text);letter-spacing:-.3px;display:block;transition:color .35s}
.nav-logo-sub{font-size:9px;color:var(--muted);font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.15em;display:block}
.nav-right{display:flex;align-items:center;gap:12px}
.nav-eco-link{font-size:13px;font-weight:600;color:var(--muted);text-decoration:none;transition:color .2s}
.nav-eco-link:hover{color:var(--text)}

/* Theme toggle button */
.theme-toggle{width:38px;height:38px;border-radius:10px;border:1px solid var(--border);background:var(--card);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:16px;transition:all .25s;color:var(--muted)}
.theme-toggle:hover{border-color:var(--border2);color:var(--text);background:var(--card2)}

.nav-btn{display:inline-flex;align-items:center;gap:8px;padding:9px 20px;border-radius:10px;background:linear-gradient(135deg,var(--red),var(--orange));color:#fff;font-size:13px;font-weight:700;text-decoration:none;box-shadow:0 4px 20px rgba(230,57,70,.35);transition:transform .2s,box-shadow .2s}
.nav-btn:hover{transform:translateY(-2px);box-shadow:0 8px 30px rgba(230,57,70,.5)}

/* ══ HERO ══ */
.hero{min-height:100vh;display:flex;align-items:center;padding:100px 20px 60px;position:relative;overflow:hidden}
.orb{position:absolute;border-radius:50%;filter:blur(80px);pointer-events:none}
.orb1{width:500px;height:500px;top:-100px;left:-150px;animation:drift 15s ease-in-out infinite}
.orb2{width:400px;height:400px;bottom:-80px;right:-100px;animation:drift 18s ease-in-out infinite reverse}
.orb3{width:300px;height:300px;top:40%;left:50%;animation:drift 12s ease-in-out infinite 3s}
[data-theme="dark"] .orb1{background:radial-gradient(circle,var(--hero-orb1),transparent 70%)}
[data-theme="dark"] .orb2{background:radial-gradient(circle,var(--hero-orb2),transparent 70%)}
[data-theme="dark"] .orb3{background:radial-gradient(circle,var(--hero-orb3),transparent 70%)}
[data-theme="light"] .orb1{background:radial-gradient(circle,rgba(230,57,70,.12),transparent 70%)}
[data-theme="light"] .orb2{background:radial-gradient(circle,rgba(67,97,238,.1),transparent 70%)}
[data-theme="light"] .orb3{background:radial-gradient(circle,rgba(6,214,160,.08),transparent 70%)}
.hero-grid{position:absolute;inset:0;pointer-events:none;background-image:linear-gradient(var(--grid-line) 1px,transparent 1px),linear-gradient(90deg,var(--grid-line) 1px,transparent 1px);background-size:48px 48px}
.hero-inner{max-width:1100px;margin:0 auto;width:100%;position:relative;z-index:2;display:grid;grid-template-columns:1fr 1fr;gap:60px;align-items:center}
@media(max-width:900px){.hero-inner{grid-template-columns:1fr;text-align:center}.hero-right{display:none}}
.hero-badge{display:inline-flex;align-items:center;gap:8px;padding:6px 14px;border-radius:999px;border:1px solid rgba(6,214,160,.35);background:rgba(6,214,160,.1);color:var(--emerald);font-size:11px;font-weight:700;font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.1em;margin-bottom:24px}
.bdot{width:6px;height:6px;border-radius:50%;background:var(--emerald);box-shadow:0 0 10px var(--emerald);animation:pdot 2s infinite}
.hero-h1{font-size:clamp(32px,5vw,54px);font-weight:800;line-height:1.15;letter-spacing:-1px;margin-bottom:20px;color:var(--text)}
[data-theme="dark"] .hero-h1{color:#e6edf3}
.hero-h1 span{background:linear-gradient(135deg,var(--red),var(--orange),var(--amber));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.hero-p{font-size:15px;color:var(--muted);line-height:1.8;margin-bottom:36px;max-width:440px}
@media(max-width:900px){.hero-p{margin:0 auto 36px}}
.hero-btns{display:flex;gap:12px;flex-wrap:wrap}
@media(max-width:900px){.hero-btns{justify-content:center}}
.btn-p{display:inline-flex;align-items:center;gap:8px;padding:14px 28px;border-radius:14px;background:linear-gradient(135deg,var(--red),var(--orange));color:#fff;font-size:14px;font-weight:700;text-decoration:none;box-shadow:0 8px 30px rgba(230,57,70,.4);transition:all .3s}
.btn-p:hover{transform:translateY(-3px);box-shadow:0 16px 40px rgba(230,57,70,.5)}
.btn-g{display:inline-flex;align-items:center;gap:8px;padding:14px 28px;border-radius:14px;border:1px solid var(--border);color:var(--text);font-size:14px;font-weight:600;text-decoration:none;background:var(--card);transition:all .3s}
.btn-g:hover{background:var(--card2);border-color:var(--border2)}

/* mini dash */
.mini-dash{background:var(--card);border:1px solid var(--border);border-radius:24px;padding:20px;box-shadow:var(--shadow-card)}
.dstat{background:var(--card2);border:1px solid var(--border);border-radius:14px;padding:14px;margin-bottom:10px}
.slbl{font-size:10px;color:var(--muted);font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.1em;margin-bottom:4px}
.sval{font-size:22px;font-weight:800;letter-spacing:-.5px}
.cbars{display:flex;align-items:flex-end;gap:6px;height:64px;background:var(--card2);border-radius:10px;padding:10px}
.cbar{flex:1;border-radius:4px 4px 0 0;animation:growUp 1.2s cubic-bezier(.16,1,.3,1) forwards;transform-origin:bottom;transform:scaleY(0)}

/* ══ DIAGRAM SECTION ══ */
.diag-section{padding:80px 20px;position:relative;overflow:hidden;background:var(--section-bg);transition:background .35s}
.sec-lbl{font-family:'Space Mono',monospace;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.2em;color:var(--red);margin-bottom:12px}
.sec-title{font-size:clamp(24px,4vw,38px);font-weight:800;letter-spacing:-.5px;margin-bottom:12px;line-height:1.2;color:var(--text)}
.sec-sub{font-size:14px;color:var(--muted);line-height:1.7;max-width:520px;margin:0 auto 36px}
.legend{display:flex;align-items:center;justify-content:center;gap:20px;flex-wrap:wrap;margin-bottom:40px;padding:10px 20px;background:var(--card);border:1px solid var(--border);border-radius:12px;width:fit-content;margin-left:auto;margin-right:auto}
.li{display:flex;align-items:center;gap:7px;font-size:11px;color:var(--muted);font-family:'Space Mono',monospace}

/* ══ DIAGRAM LAYOUT ══ */
.diag-outer{max-width:700px;margin:0 auto;position:relative}
.d-row{display:flex;justify-content:center;position:relative;z-index:2}
.d-row-2{display:grid;grid-template-columns:1fr 1fr;gap:16px;position:relative;z-index:2}
.fnode{background:var(--card);border:1px solid var(--border2);border-radius:20px;padding:16px 20px;display:flex;align-items:center;gap:14px;transition:border-color .25s,transform .3s,box-shadow .3s,background .35s;cursor:default}
.fnode:hover{transform:translateY(-4px)}
.fnode-col{flex-direction:column;align-items:center;text-align:center;padding:22px 16px}
.ficon{width:46px;height:46px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:19px;color:#fff;flex-shrink:0}
.ficon-sm{width:40px;height:40px;border-radius:12px}
.ftitle{font-size:15px;font-weight:700;color:var(--text)}
.fsub{font-size:11px;color:var(--muted);margin-top:3px}
.fbadge{padding:4px 12px;border-radius:999px;font-size:9px;font-weight:700;font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.1em;margin-left:auto;flex-shrink:0}
.n-inv{border-color:rgba(67,97,238,.4)}.n-inv:hover{border-color:rgba(67,97,238,.7);box-shadow:0 16px 48px rgba(67,97,238,.14)}
.n-reg{border-color:rgba(67,97,238,.3)}.n-reg:hover{border-color:rgba(67,97,238,.6);box-shadow:0 16px 48px rgba(67,97,238,.12)}
.n-spe{border-color:rgba(6,214,160,.4);background:rgba(6,214,160,.04)}.n-spe:hover{border-color:rgba(6,214,160,.7);box-shadow:0 16px 48px rgba(6,214,160,.14)}
.n-mot{border-color:rgba(230,57,70,.55);background:rgba(230,57,70,.04);animation:glowR 3s ease-in-out infinite}.n-mot:hover{box-shadow:0 20px 56px rgba(230,57,70,.2)}
.n-chi{border-color:rgba(251,191,36,.3);background:rgba(251,191,36,.025)}.n-chi:hover{border-color:rgba(251,191,36,.6)}
.n-act{border-color:rgba(6,214,160,.35)}.n-act:hover{border-color:rgba(6,214,160,.65);box-shadow:0 16px 48px rgba(6,214,160,.12)}
.n-pas{border-color:rgba(67,97,238,.35)}.n-pas:hover{border-color:rgba(67,97,238,.65);box-shadow:0 16px 48px rgba(67,97,238,.12)}
.n-pro{border-color:rgba(6,214,160,.5);background:rgba(6,214,160,.05);animation:glowE 3s ease-in-out infinite}.n-pro:hover{box-shadow:0 20px 56px rgba(6,214,160,.2)}
.ch-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:12px;width:100%}
.ch-node{background:var(--card2);border:1px solid rgba(251,191,36,.22);border-radius:14px;padding:14px 10px;display:flex;flex-direction:column;align-items:center;gap:8px;transition:border-color .25s,transform .25s,background .35s}
.ch-node:hover{border-color:rgba(251,191,36,.55);transform:translateY(-3px)}
.ch-ic{width:38px;height:38px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:16px}
.conn-strip{width:100%;position:relative;z-index:1;display:flex;justify-content:center}
.conn-strip svg{display:block;overflow:visible}

/* ══ EXPLANATION SECTION ══ */
.exp-section{padding:80px 20px;background:var(--exp-bg);border-top:1px solid var(--border);border-bottom:1px solid var(--border);transition:background .35s}
.exp-inner{max-width:1000px;margin:0 auto}
.exp-intro{text-align:center;max-width:680px;margin:0 auto 60px}
.exp-intro p{font-size:16px;color:var(--muted);line-height:1.85}
.how-steps{display:grid;grid-template-columns:repeat(3,1fr);gap:24px;margin-bottom:60px}
@media(max-width:760px){.how-steps{grid-template-columns:1fr}}
.step-card{background:var(--exp-card);border:1px solid var(--exp-border);border-radius:20px;padding:28px 24px;position:relative;transition:border-color .25s,transform .3s,background .35s}
.step-card:hover{transform:translateY(-5px);border-color:var(--border2)}
.step-num{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;font-family:'Space Mono',monospace;margin-bottom:16px;flex-shrink:0}
.step-title{font-size:16px;font-weight:700;color:var(--text);margin-bottom:10px;line-height:1.3}
.step-body{font-size:13px;color:var(--muted);line-height:1.8}

/* investor types grid */
.inv-types{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:60px}
@media(max-width:600px){.inv-types{grid-template-columns:1fr}}
.inv-card{border-radius:20px;padding:28px 24px;border:1px solid var(--exp-border);transition:background .35s}
.inv-card-title{font-size:17px;font-weight:800;margin-bottom:10px}
.inv-card-sub{font-size:13px;line-height:1.8;color:var(--muted)}
.inv-feature{display:flex;align-items:flex-start;gap:10px;margin-top:12px;font-size:13px;color:var(--muted);line-height:1.6}
.inv-feature i{font-size:12px;margin-top:3px;flex-shrink:0}

/* profit flow table */
.profit-flow{background:var(--exp-card);border:1px solid var(--exp-border);border-radius:20px;padding:32px;margin-bottom:60px;transition:background .35s}
.pf-title{font-size:18px;font-weight:800;color:var(--text);margin-bottom:6px}
.pf-sub{font-size:13px;color:var(--muted);margin-bottom:24px}
.pf-steps{display:flex;flex-direction:column;gap:0}
.pf-step{display:flex;align-items:flex-start;gap:16px;padding:16px 0;border-bottom:1px solid var(--exp-border);position:relative}
.pf-step:last-child{border-bottom:none}
.pf-dot{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;font-family:'Space Mono',monospace;flex-shrink:0;margin-top:2px}
.pf-content{flex:1}
.pf-step-title{font-size:14px;font-weight:700;color:var(--text);margin-bottom:4px}
.pf-step-body{font-size:12px;color:var(--muted);line-height:1.7}

/* why invest */
.why-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:16px;margin-bottom:60px}
@media(max-width:600px){.why-grid{grid-template-columns:1fr}}
.why-card{background:var(--exp-card);border:1px solid var(--exp-border);border-radius:16px;padding:22px 20px;display:flex;gap:14px;align-items:flex-start;transition:border-color .25s,background .35s}
.why-card:hover{border-color:var(--border2)}
.why-icon{width:40px;height:40px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:16px;color:#fff;flex-shrink:0}
.why-title{font-size:14px;font-weight:700;color:var(--text);margin-bottom:5px}
.why-body{font-size:12px;color:var(--muted);line-height:1.7}

/* trust row */
.trust-row{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;text-align:center}
@media(max-width:600px){.trust-row{grid-template-columns:repeat(2,1fr)}}
.trust-item{background:var(--exp-card);border:1px solid var(--exp-border);border-radius:16px;padding:20px 14px;transition:background .35s}
.trust-num{font-size:28px;font-weight:800;letter-spacing:-1px;background:linear-gradient(135deg,var(--red),var(--orange));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.trust-label{font-size:11px;color:var(--muted);margin-top:4px;font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.1em}

/* ══ CTA ══ */
.cta-sec{padding:60px 20px 80px;background:var(--bg);transition:background .35s}
.cta-card{max-width:680px;margin:0 auto;background:var(--card);border:1px solid var(--border);border-radius:28px;padding:48px 40px;text-align:center;position:relative;overflow:hidden;box-shadow:var(--shadow-card)}
.cta-card::before{content:'';position:absolute;top:-50%;left:50%;transform:translateX(-50%);width:300px;height:300px;background:radial-gradient(circle,rgba(230,57,70,.1),transparent 70%);pointer-events:none}
@media(max-width:560px){.cta-card{padding:32px 20px}}

/* ══ FOOTER ══ */
footer{border-top:1px solid var(--border);padding:24px 20px;background:var(--bg);transition:background .35s}
.foot{max-width:1100px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.foot-copy{font-size:11px;color:var(--muted);font-family:'Space Mono',monospace}

/* ══ REVEAL ══ */
.reveal{opacity:0;transform:translateY(28px);transition:opacity .7s ease,transform .7s ease}
.reveal.in{opacity:1;transform:translateY(0)}
.reveal.d1{transition-delay:.1s}.reveal.d2{transition-delay:.2s}.reveal.d3{transition-delay:.3s}.reveal.d4{transition-delay:.4s}

/* ══ MOBILE ══ */
@media(max-width:480px){
  .diag-section{padding:48px 12px}
  .nav-eco-link{display:none}
  .diag-outer{padding:0 2px}
  .d-row-2{gap:8px}
  .fnode{border-radius:14px;padding:10px 12px;gap:8px}
  .fnode-col{padding:12px 8px}
  .ficon{width:32px;height:32px;border-radius:10px;font-size:13px}
  .ficon-sm{width:28px;height:28px;border-radius:9px;font-size:12px}
  .ftitle{font-size:11px}.fsub{font-size:9px;margin-top:2px}
  .fbadge{padding:2px 7px;font-size:7px;letter-spacing:.05em}
  #n-investor{width:100% !important}#n-profit{width:100% !important}
  .fnode-col .ficon-sm{margin-bottom:5px !important}
  .fnode-col>div:first-child{margin-bottom:6px !important}
  .n-mot [style*="top:-11px"]{width:18px !important;height:18px !important;font-size:9px !important}
  .ch-grid{gap:6px;margin-top:8px}
  .ch-node{padding:8px 4px;border-radius:10px;gap:4px}
  .ch-ic{width:26px;height:26px;border-radius:7px;font-size:12px}
  .ch-node [style*="font-size:13px"]{font-size:10px !important}
  .ch-node [style*="font-size:10px"]{font-size:8px !important}
  .n-chi [style*="font-size:10px"]{font-size:8px !important;letter-spacing:.08em}
  .conn-strip-split{height:34px !important}
  .conn-strip-tall{height:72px !important}
  .conn-strip-medium{height:64px !important}
  .conn-strip-join{height:34px !important}
  .conn-strip-split svg{height:34px !important}
  .conn-strip-tall svg{height:72px !important}
  .conn-strip-medium svg{height:64px !important}
  .conn-strip-join svg{height:34px !important}
  .legend{gap:10px;padding:8px 12px}.li{font-size:9px;gap:5px}
  .sec-title{font-size:20px}.sec-sub{font-size:12px}
  .n-spe [style*="top:-9px"]{width:16px !important;height:16px !important;font-size:8px !important;top:-6px !important;right:-6px !important}
  .conn-strip svg text{font-size:8px !important}
  .conn-strip svg circle{r:3 !important}
  .exp-section{padding:48px 16px}
  .how-steps{gap:14px}
  .step-card{padding:20px 16px}
  .profit-flow{padding:20px 16px}
  .why-card{padding:16px 14px}
  .trust-num{font-size:22px}
}

/* ══ KEYFRAMES ══ */
@keyframes drift{0%,100%{transform:translate(0,0) scale(1)}33%{transform:translate(30px,-20px) scale(1.05)}66%{transform:translate(-20px,15px) scale(.95)}}
@keyframes pdot{0%,100%{opacity:1;box-shadow:0 0 10px var(--emerald)}50%{opacity:.4;box-shadow:0 0 4px var(--emerald)}}
@keyframes growUp{to{transform:scaleY(1)}}
@keyframes glowR{0%,100%{box-shadow:0 0 0 0 rgba(230,57,70,0)}50%{box-shadow:0 0 24px 5px rgba(230,57,70,.18)}}
@keyframes glowE{0%,100%{box-shadow:0 0 0 0 rgba(6,214,160,0)}50%{box-shadow:0 0 24px 5px rgba(6,214,160,.18)}}
@keyframes livepulse{0%,100%{opacity:1}50%{opacity:.25}}
</style>
</head>
<body>

<!-- ══ NAV ══ -->
<nav class="nav" id="navbar">
  <a href="#" class="nav-logo">
    <div class="nav-logo-icon">
      <?php if (!empty($site_logo)): ?>
        <img src="<?= htmlspecialchars($site_logo) ?>" alt="Logo" style="width:100%;height:100%;object-fit:contain;border-radius:12px">
      <?php else: ?><i class="fas fa-chart-pie"></i><?php endif; ?>
    </div>
    <div>
      <span class="nav-logo-text">Sodai Lagbe</span>
      <span class="nav-logo-sub">Shareholder ERP</span>
    </div>
  </a>
  <div class="nav-right">
    <a href="#diagram" class="nav-eco-link">ইকোসিস্টেম</a>
    <a href="#how-it-works" class="nav-eco-link">কীভাবে কাজ করে</a>
    <button class="theme-toggle" id="themeBtn" title="Toggle theme">🌙</button>
    <a href="login.php" class="nav-btn"><i class="fas fa-sign-in-alt"></i> লগইন</a>
  </div>
</nav>

<!-- ══ HERO ══ -->
<section class="hero">
  <div class="orb orb1"></div><div class="orb orb2"></div><div class="orb orb3"></div>
  <div class="hero-grid"></div>
  <div class="hero-inner">
    <div>
      <div class="hero-badge"><span class="bdot"></span> Live Investment Ecosystem</div>
      <h1 class="hero-h1">স্মার্ট <span>প্রফিট ট্র্যাকিং</span><br>শেয়ারহোল্ডার ইআরপি</h1>
      <p class="hero-p">সদাই লাগবে বাংলাদেশের ডেলিভারি ইন্ডাস্ট্রিতে একটি নতুন বিনিয়োগ মডেল। আপনার বিনিয়োগ মাদার প্রজেক্টের মাধ্যমে একাধিক চাইল্ড প্রজেক্টে ছড়িয়ে পড়ে এবং প্রতিটি থেকে আপনি স্বয়ংক্রিয়ভাবে প্রফিট পান।</p>
      <div class="hero-btns">
        <a href="login.php" class="btn-p"><i class="fas fa-chart-pie"></i> লাইভ ড্যাশবোর্ড</a>
        <a href="#how-it-works" class="btn-g">কীভাবে কাজ করে <i class="fas fa-arrow-down"></i></a>
      </div>
    </div>
    <div class="hero-right">
      <div class="mini-dash">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;padding-bottom:14px;border-bottom:1px solid var(--border)">
          <div style="display:flex;align-items:center;gap:10px">
            <div style="width:32px;height:32px;border-radius:10px;background:linear-gradient(135deg,var(--red),var(--orange));display:flex;align-items:center;justify-content:center;color:#fff;font-size:13px"><i class="fas fa-check"></i></div>
            <div><div style="font-size:12px;font-weight:700;color:var(--text)">Live Profit Synced</div><div style="font-size:10px;color:var(--muted)">System operational</div></div>
          </div>
          <div style="display:flex;gap:5px"><div style="width:10px;height:10px;border-radius:50%;background:#e63946"></div><div style="width:10px;height:10px;border-radius:50%;background:#fbbf24"></div><div style="width:10px;height:10px;border-radius:50%;background:#06d6a0"></div></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px">
          <div class="dstat"><div class="slbl">Total Share</div><div class="sval" style="color:var(--orange)">24.5%</div></div>
          <div class="dstat"><div class="slbl">Incentive</div><div class="sval" style="color:var(--emerald)">+৳12,500</div></div>
        </div>
        <div class="dstat">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
            <div class="slbl">Growth Chart</div>
            <span style="font-size:9px;background:rgba(6,214,160,.1);border:1px solid rgba(6,214,160,.3);color:var(--emerald);padding:2px 8px;border-radius:999px;font-family:'Space Mono',monospace">ACTIVE</span>
          </div>
          <div class="cbars">
            <div class="cbar" style="height:38%;background:linear-gradient(180deg,var(--red),var(--orange));animation-delay:0s"></div>
            <div class="cbar" style="height:55%;background:linear-gradient(180deg,var(--red),var(--orange));animation-delay:.1s"></div>
            <div class="cbar" style="height:42%;background:linear-gradient(180deg,var(--red),var(--orange));animation-delay:.2s"></div>
            <div class="cbar" style="height:75%;background:linear-gradient(180deg,var(--red),var(--orange));animation-delay:.3s"></div>
            <div class="cbar" style="height:62%;background:linear-gradient(180deg,var(--red),var(--orange));animation-delay:.4s"></div>
            <div class="cbar" style="height:90%;background:linear-gradient(180deg,var(--emerald),#34d399);animation-delay:.5s;box-shadow:0 0 12px rgba(6,214,160,.4)"></div>
          </div>
        </div>
        <div style="display:flex;align-items:center;gap:8px;margin-top:12px;padding:10px;border-radius:12px;background:var(--card2);border:1px solid var(--border)">
          <div style="width:8px;height:8px;border-radius:50%;background:var(--emerald);box-shadow:0 0 8px var(--emerald);animation:pdot 2s infinite;flex-shrink:0"></div>
          <span style="font-size:11px;color:var(--muted)">3 Active Child Projects Running</span>
          <span style="margin-left:auto;font-size:10px;font-family:'Space Mono',monospace;color:var(--emerald)">+8.2%</span>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ══ DIAGRAM ══ -->
<section id="diagram" class="diag-section">
  <div style="position:absolute;width:320px;height:320px;border-radius:50%;filter:blur(80px);background:radial-gradient(circle,rgba(230,57,70,.1),transparent 70%);top:6%;left:-90px;pointer-events:none"></div>
  <div style="position:absolute;width:280px;height:280px;border-radius:50%;filter:blur(80px);background:radial-gradient(circle,rgba(6,214,160,.08),transparent 70%);bottom:6%;right:-70px;pointer-events:none"></div>
  <div style="max-width:1100px;margin:0 auto;position:relative;z-index:2">
    <div style="text-align:center;margin-bottom:14px" class="reveal">
      <div class="sec-lbl"><i class="fas fa-diagram-project" style="margin-right:6px"></i>System Architecture</div>
      <h2 class="sec-title">Investment Ecosystem Diagram</h2>
      <p class="sec-sub">রেগুলার ও স্পেশাল ইনভেস্টর থেকে শুরু করে মাদার প্রজেক্ট, চাইল্ড প্রজেক্ট, শেয়ারহোল্ডার এবং প্রফিট অ্যাকাউন্ট — সম্পূর্ণ ফ্লো।</p>
    </div>
    <div class="legend reveal d1">
      <div class="li"><div style="height:2px;width:24px;border-radius:2px;background:#4361ee"></div><span>Regular invest</span></div>
      <div class="li"><div style="height:2px;width:24px;border-radius:2px;background:#06d6a0"></div><span>Special invest</span></div>
      <div class="li"><div style="height:0;width:24px;border-top:2px dashed #fbbf24"></div><span>Incentive (Child→Mother)</span></div>
    </div>

    <div class="diag-outer reveal d2">
      <div style="display:flex;flex-direction:column;align-items:center;gap:0;width:100%">

        <!-- ROW 1: INVESTOR -->
        <div class="d-row"><div class="fnode n-inv" style="width:260px;max-width:100%;justify-content:center" id="n-investor">
          <div class="ficon" style="background:linear-gradient(135deg,var(--red),var(--orange))"><i class="fas fa-user-tie"></i></div>
          <div><div class="ftitle">Investor</div><div class="fsub">Entry point</div></div>
          <div class="fbadge" style="background:rgba(67,97,238,.15);border:1px solid rgba(67,97,238,.35);color:#818cf8">ENTRY</div>
        </div></div>

        <!-- Investor → split -->
        <div class="conn-strip conn-strip-split" style="height:52px"><svg viewBox="0 0 640 52" style="width:100%;max-width:640px;height:52px">
          <path id="c1l" d="M320,0 L320,26 L120,26 L120,52" fill="none" stroke="#4361ee" stroke-width="2" stroke-linecap="round"/>
          <path id="c1r" d="M320,0 L320,26 L520,26 L520,52" fill="none" stroke="#06d6a0" stroke-width="2" stroke-linecap="round"/>
          <circle r="4.5" fill="#4361ee"><animateMotion dur="1.8s" repeatCount="indefinite"><mpath href="#c1l"/></animateMotion><animate attributeName="opacity" values="0;1;1;0" dur="1.8s" repeatCount="indefinite"/></circle>
          <circle r="4.5" fill="#06d6a0"><animateMotion dur="1.8s" repeatCount="indefinite" begin=".7s"><mpath href="#c1r"/></animateMotion><animate attributeName="opacity" values="0;1;1;0" dur="1.8s" repeatCount="indefinite" begin=".7s"/></circle>
        </svg></div>

        <!-- ROW 2: REGULAR + SPECIAL -->
        <div class="d-row-2" style="width:100%">
          <div class="fnode n-reg fnode-col" id="n-regular">
            <div class="ficon ficon-sm" style="background:linear-gradient(135deg,var(--blue),var(--indigo));margin-bottom:12px"><i class="fas fa-users"></i></div>
            <div class="ftitle">Regular Investor</div><div class="fsub" style="margin-top:6px">Standard profit flow</div>
          </div>
          <div class="fnode n-spe fnode-col" id="n-special" style="position:relative">
            <div style="position:absolute;top:-9px;right:-9px;width:24px;height:24px;border-radius:50%;background:rgba(6,214,160,.18);border:1px solid rgba(6,214,160,.55);display:flex;align-items:center;justify-content:center;font-size:11px;color:var(--emerald)">★</div>
            <div class="ficon ficon-sm" style="background:linear-gradient(135deg,var(--emerald),#0d9488);margin-bottom:12px"><i class="fas fa-gem"></i></div>
            <div class="ftitle" style="color:var(--emerald)">Special Investor</div><div class="fsub" style="margin-top:6px">Extra benefits</div>
          </div>
        </div>

        <!-- Connector A: Regular+Special → Mother, incentive left rail -->
        <div class="conn-strip conn-strip-tall" style="height:120px"><svg viewBox="0 0 640 120" style="width:100%;max-width:640px;height:120px">
          <path id="c2-rm" d="M120,0 L120,70 L320,70 L320,120" fill="none" stroke="#4361ee" stroke-width="2" stroke-linecap="round"/>
          <path id="c2-rail" d="M520,0 L520,120" fill="none" stroke="#06d6a0" stroke-width="2"/>
          <path id="c2-sm" d="M520,60 L400,60 L400,90" fill="none" stroke="#06d6a0" stroke-width="2" stroke-linecap="round"/>
          <polygon points="396,88 400,100 404,88" fill="#06d6a0"/>
          <text x="406" y="54" fill="#06d6a0" font-size="11" font-family="Sora,sans-serif" font-weight="600">invest ↓</text>
          <path id="c2-inc-seg" d="M28,120 L28,0" fill="none" stroke="#fbbf24" stroke-width="2" stroke-dasharray="6,5"/>
          <circle r="4.5" fill="#4361ee"><animateMotion dur="2.2s" repeatCount="indefinite"><mpath href="#c2-rm"/></animateMotion><animate attributeName="opacity" values="0;1;1;0" dur="2.2s" repeatCount="indefinite"/></circle>
          <circle r="4.5" fill="#06d6a0"><animateMotion dur="3s" repeatCount="indefinite" begin=".4s"><mpath href="#c2-rail"/></animateMotion><animate attributeName="opacity" values="0;1;1;0" dur="3s" repeatCount="indefinite" begin=".4s"/></circle>
          <circle r="4" fill="#06d6a0"><animateMotion dur="1.6s" repeatCount="indefinite" begin="1.1s"><mpath href="#c2-sm"/></animateMotion><animate attributeName="opacity" values="0;1;1;0" dur="1.6s" repeatCount="indefinite" begin="1.1s"/></circle>
          <circle r="3.5" fill="#fbbf24"><animateMotion dur="2.4s" repeatCount="indefinite" begin=".8s"><mpath href="#c2-inc-seg"/></animateMotion><animate attributeName="opacity" values="0;1;1;0" dur="2.4s" repeatCount="indefinite" begin=".8s"/></circle>
        </svg></div>

        <!-- ROW 3: MOTHER PROJECT -->
        <div class="d-row"><div class="fnode n-mot" style="width:100%;position:relative" id="n-mother">
          <div style="position:absolute;top:-11px;left:14px;width:26px;height:26px;border-radius:50%;background:rgba(251,191,36,.14);border:1px solid rgba(251,191,36,.5);display:flex;align-items:center;justify-content:center;font-size:13px">👑</div>
          <div class="ficon" style="background:linear-gradient(135deg,var(--red),var(--orange))"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></div>
          <div><div class="ftitle" style="font-size:17px">Mother Project</div><div class="fsub" style="font-size:12px;margin-top:4px">Sodai Lagbe — core platform</div></div>
          <div class="fbadge" style="background:rgba(230,57,70,.15);border:1px solid rgba(230,57,70,.4);color:#f87171">CORE</div>
        </div></div>

        <!-- Connector B: Mother → Children, special rail + incentive -->
        <div class="conn-strip conn-strip-medium" style="height:100px"><svg viewBox="0 0 640 100" style="width:100%;max-width:640px;height:100px">
          <path id="c3-mc" d="M320,0 L320,100" fill="none" stroke="#fbbf24" stroke-width="2" stroke-linecap="round"/>
          <path id="c3-rail" d="M520,0 L520,100" fill="none" stroke="#06d6a0" stroke-width="2"/>
          <path id="c3-sc" d="M520,60 L430,60 L430,90" fill="none" stroke="#06d6a0" stroke-width="2" stroke-linecap="round"/>
          <polygon points="426,88 430,100 434,88" fill="#06d6a0"/>
          <text x="436" y="54" fill="#06d6a0" font-size="11" font-family="Sora,sans-serif" font-weight="600">direct ↓</text>
          <path id="c3-inc" d="M28,100 L28,0" fill="none" stroke="#fbbf24" stroke-width="2" stroke-dasharray="6,5"/>
          <polygon points="24,2 28,-10 32,2" fill="#fbbf24"/>
          <text x="36" y="55" fill="#fbbf24" font-size="11" font-family="Sora,sans-serif" font-weight="600">Incentive ↑</text>
          <circle r="4.5" fill="#fbbf24"><animateMotion dur="2s" repeatCount="indefinite"><mpath href="#c3-mc"/></animateMotion><animate attributeName="opacity" values="0;1;1;0" dur="2s" repeatCount="indefinite"/></circle>
          <circle r="4.5" fill="#06d6a0"><animateMotion dur="3s" repeatCount="indefinite" begin=".3s"><mpath href="#c3-rail"/></animateMotion><animate attributeName="opacity" values="0;1;1;0" dur="3s" repeatCount="indefinite" begin=".3s"/></circle>
          <circle r="4" fill="#06d6a0"><animateMotion dur="1.6s" repeatCount="indefinite" begin=".6s"><mpath href="#c3-sc"/></animateMotion><animate attributeName="opacity" values="0;1;1;0" dur="1.6s" repeatCount="indefinite" begin=".6s"/></circle>
          <circle r="3.5" fill="#fbbf24"><animateMotion dur="2.4s" repeatCount="indefinite" begin="1s"><mpath href="#c3-inc"/></animateMotion><animate attributeName="opacity" values="0;1;1;0" dur="2.4s" repeatCount="indefinite" begin="1s"/></circle>
        </svg></div>

        <!-- ROW 4: CHILD PROJECTS -->
        <div class="d-row"><div class="fnode n-chi fnode-col" style="width:100%">
          <div style="font-size:10px;font-family:'Space Mono',monospace;color:var(--amber);text-transform:uppercase;letter-spacing:.15em;margin-bottom:4px"><i class="fas fa-diagram-project" style="margin-right:6px"></i>Child Projects</div>
          <div class="ch-grid">
            <div class="ch-node">
              <div class="ch-ic" style="background:rgba(249,115,22,.15);border:1px solid rgba(249,115,22,.3)">🛒</div>
              <div style="font-size:13px;font-weight:700;color:var(--text)">Groceries</div>
              <div style="font-size:10px;color:var(--muted)">Active</div>
            </div>
            <div class="ch-node">
              <div class="ch-ic" style="background:rgba(230,57,70,.15);border:1px solid rgba(230,57,70,.3)">🥛</div>
              <div style="font-size:13px;font-weight:700;color:var(--text)">Dairy Items</div>
              <div style="font-size:10px;color:var(--muted)">Active</div>
            </div>
            <div class="ch-node">
              <div class="ch-ic" style="background:rgba(6,214,160,.12);border:1px solid rgba(6,214,160,.3)">📦</div>
              <div style="font-size:13px;font-weight:700;color:var(--text)">China Import</div>
              <div style="font-size:10px;color:var(--muted)">Active</div>
            </div>
          </div>
        </div></div>

        <!-- Children → split -->
        <div class="conn-strip conn-strip-split" style="height:56px"><svg viewBox="0 0 640 56" style="width:100%;max-width:640px;height:56px">
          <path id="c4l" d="M320,0 L320,28 L120,28 L120,56" fill="none" stroke="#06d6a0" stroke-width="2" stroke-linecap="round"/>
          <path id="c4r" d="M320,0 L320,28 L520,28 L520,56" fill="none" stroke="#4361ee" stroke-width="2" stroke-linecap="round"/>
          <circle r="4.5" fill="#06d6a0"><animateMotion dur="2s" repeatCount="indefinite"><mpath href="#c4l"/></animateMotion><animate attributeName="opacity" values="0;1;1;0" dur="2s" repeatCount="indefinite"/></circle>
          <circle r="4.5" fill="#4361ee"><animateMotion dur="2s" repeatCount="indefinite" begin=".6s"><mpath href="#c4r"/></animateMotion><animate attributeName="opacity" values="0;1;1;0" dur="2s" repeatCount="indefinite" begin=".6s"/></circle>
        </svg></div>

        <!-- ROW 5: ACTIVE + PASSIVE -->
        <div class="d-row-2" style="width:100%">
          <div class="fnode n-act fnode-col" id="n-active">
            <div style="display:flex;align-items:center;gap:6px;margin-bottom:12px"><div style="width:8px;height:8px;border-radius:50%;background:var(--emerald);animation:livepulse 2s infinite"></div><span style="font-size:9px;font-family:'Space Mono',monospace;color:var(--emerald);letter-spacing:.1em">LIVE</span></div>
            <div class="ficon ficon-sm" style="background:linear-gradient(135deg,var(--emerald),#0d9488);margin-bottom:12px"><i class="fas fa-bolt"></i></div>
            <div class="ftitle" style="color:var(--emerald)">Active</div><div class="fsub" style="margin-top:6px">Shareholders</div>
          </div>
          <div class="fnode n-pas fnode-col" id="n-passive">
            <div style="display:flex;align-items:center;gap:6px;margin-bottom:12px"><div style="width:8px;height:8px;border-radius:50%;background:var(--blue)"></div><span style="font-size:9px;font-family:'Space Mono',monospace;color:#818cf8;letter-spacing:.1em">STEADY</span></div>
            <div class="ficon ficon-sm" style="background:linear-gradient(135deg,var(--blue),var(--indigo));margin-bottom:12px"><i class="fas fa-wallet"></i></div>
            <div class="ftitle" style="color:#818cf8">Passive</div><div class="fsub" style="margin-top:6px">Shareholders</div>
          </div>
        </div>

        <!-- join → Profit -->
        <div class="conn-strip conn-strip-join" style="height:56px"><svg viewBox="0 0 640 56" style="width:100%;max-width:640px;height:56px">
          <path id="c5l" d="M120,0 L120,28 L320,28 L320,56" fill="none" stroke="#06d6a0" stroke-width="2" stroke-linecap="round"/>
          <path id="c5r" d="M520,0 L520,28 L320,28 L320,56" fill="none" stroke="#4361ee" stroke-width="2" stroke-linecap="round"/>
          <circle r="4.5" fill="#06d6a0"><animateMotion dur="2s" repeatCount="indefinite"><mpath href="#c5l"/></animateMotion><animate attributeName="opacity" values="0;1;1;0" dur="2s" repeatCount="indefinite"/></circle>
          <circle r="4.5" fill="#4361ee"><animateMotion dur="2s" repeatCount="indefinite" begin=".6s"><mpath href="#c5r"/></animateMotion><animate attributeName="opacity" values="0;1;1;0" dur="2s" repeatCount="indefinite" begin=".6s"/></circle>
        </svg></div>

        <!-- ROW 6: PROFIT -->
        <div class="d-row"><div class="fnode n-pro" style="width:300px;max-width:100%;justify-content:center" id="n-profit">
          <div class="ficon" style="background:linear-gradient(135deg,var(--emerald),#0d9488)"><i class="fas fa-chart-line"></i></div>
          <div style="text-align:center"><div class="ftitle" style="color:var(--emerald)">Shareholder Account Profit</div><div class="fsub" style="margin-top:4px">Auto-distributed</div></div>
        </div></div>

      </div>
    </div>
  </div>
</section>

<!-- ══ HOW IT WORKS — BUSINESS EXPLANATION ══ -->
<section id="how-it-works" class="exp-section">
  <div class="exp-inner">

    <!-- Intro -->
    <div class="exp-intro reveal">
      <div class="sec-lbl" style="text-align:center"><i class="fas fa-lightbulb" style="margin-right:6px"></i>আমাদের বিজনেস মডেল</div>
      <h2 class="sec-title" style="text-align:center">সদাই লাগবে কীভাবে কাজ করে?</h2>
      <p style="font-size:16px;color:var(--muted);line-height:1.85;text-align:center">সদাই লাগবে একটি মাল্টি-প্রজেক্ট ডেলিভারি ইকোসিস্টেম। আপনি একবার বিনিয়োগ করলে আপনার টাকা স্বয়ংক্রিয়ভাবে একাধিক সক্রিয় ব্যবসায় কাজ করে এবং প্রতিটি থেকে লাভ আপনার অ্যাকাউন্টে জমা হয়। কোনো ঝামেলা নেই — সম্পূর্ণ ডিজিটাল, সম্পূর্ণ স্বচ্ছ।</p>
    </div>

    <!-- Steps: How investment flows -->
    <div style="margin-bottom:16px" class="reveal d1">
      <div class="sec-lbl" style="text-align:center;margin-bottom:20px"><i class="fas fa-route" style="margin-right:6px"></i>বিনিয়োগের ধাপসমূহ</div>
    </div>
    <div class="how-steps reveal d2">
      <div class="step-card">
        <div class="step-num" style="background:rgba(67,97,238,.15);border:1px solid rgba(67,97,238,.35);color:#818cf8">01</div>
        <div class="step-title">ইনভেস্টর রেজিস্ট্রেশন ও বিনিয়োগ</div>
        <div class="step-body">আপনি রেগুলার অথবা স্পেশাল ইনভেস্টর হিসেবে নিবন্ধন করেন। নির্ধারিত স্লট অনুযায়ী বিনিয়োগ করলে আপনি কোম্পানির একজন শেয়ারহোল্ডার হয়ে যান এবং আপনার একটি ডেডিকেটেড ERP অ্যাকাউন্ট তৈরি হয়।</div>
      </div>
      <div class="step-card">
        <div class="step-num" style="background:rgba(230,57,70,.12);border:1px solid rgba(230,57,70,.3);color:#f87171">02</div>
        <div class="step-title">মাদার প্রজেক্টে ফান্ড পুলিং</div>
        <div class="step-body">সকল ইনভেস্টরের অর্থ Sodai Lagbe-এর মাদার প্রজেক্টে একত্রিত হয়। এই পুলড ফান্ড থেকে পরিচালনা খরচ, অপারেশন এবং চাইল্ড প্রজেক্টগুলোতে মূলধন সরবরাহ করা হয়। প্রতিটি লেনদেন ERP সিস্টেমে রেকর্ড থাকে।</div>
      </div>
      <div class="step-card">
        <div class="step-num" style="background:rgba(251,191,36,.12);border:1px solid rgba(251,191,36,.3);color:#fbbf24">03</div>
        <div class="step-title">চাইল্ড প্রজেক্ট অপারেশন</div>
        <div class="step-body">মাদার প্রজেক্ট থেকে মূলধন পেয়ে চাইল্ড প্রজেক্টগুলো (Groceries, Dairy Items, China Import) স্বতন্ত্রভাবে পরিচালিত হয়। প্রতিটি প্রজেক্ট তার নিজস্ব ক্যাটাগরিতে ডেলিভারি ও বিক্রয় কার্যক্রম পরিচালনা করে।</div>
      </div>
      <div class="step-card">
        <div class="step-num" style="background:rgba(6,214,160,.1);border:1px solid rgba(6,214,160,.3);color:var(--emerald)">04</div>
        <div class="step-title">প্রফিট ক্যালকুলেশন ও ডিস্ট্রিবিউশন</div>
        <div class="step-body">চাইল্ড প্রজেক্টগুলো থেকে অর্জিত লাভের একটি অংশ ইনসেন্টিভ হিসেবে মাদার প্রজেক্টে ফেরত আসে। তারপর প্রতিটি শেয়ারহোল্ডারের শেয়ার অনুযায়ী স্বয়ংক্রিয়ভাবে প্রফিট তাদের ERP অ্যাকাউন্টে বিতরণ করা হয়।</div>
      </div>
      <div class="step-card">
        <div class="step-num" style="background:rgba(67,97,238,.12);border:1px solid rgba(67,97,238,.3);color:#818cf8">05</div>
        <div class="step-title">Active vs Passive শেয়ারহোল্ডার</div>
        <div class="step-body">Active শেয়ারহোল্ডাররা অপারেশনে সরাসরি অংশ নেন (ডেলিভারি, ম্যানেজমেন্ট) — তারা বেশি প্রফিট পান। Passive শেয়ারহোল্ডাররা শুধু বিনিয়োগ করেন এবং নিয়মিত রিটার্ন পান। উভয়ই ERP পোর্টালে সব তথ্য রিয়েল-টাইমে দেখতে পারেন।</div>
      </div>
      <div class="step-card">
        <div class="step-num" style="background:rgba(230,57,70,.12);border:1px solid rgba(230,57,70,.3);color:#f87171">06</div>
        <div class="step-title">স্বচ্ছ ERP ট্র্যাকিং সিস্টেম</div>
        <div class="step-body">আমাদের ERP পোর্টালে আপনি যেকোনো সময় আপনার বিনিয়োগ, প্রফিট ব্যালেন্স, শেয়ার শতাংশ, প্রজেক্টের পারফর্মেন্স এবং সর্বশেষ ডিভিডেন্ড রিপোর্ট দেখতে পারবেন। কোনো লুকোনো তথ্য নেই।</div>
      </div>
    </div>

    <!-- Investor Types -->
    <div style="margin-bottom:24px" class="reveal d1">
      <div class="sec-lbl" style="text-align:center;margin-bottom:20px"><i class="fas fa-users" style="margin-right:6px"></i>ইনভেস্টরের ধরন</div>
    </div>
    <div class="inv-types reveal d2">
      <div class="inv-card" style="background:rgba(67,97,238,.05);border-color:rgba(67,97,238,.3)">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
          <div style="width:44px;height:44px;border-radius:14px;background:linear-gradient(135deg,var(--blue),var(--indigo));display:flex;align-items:center;justify-content:center;font-size:18px;color:#fff"><i class="fas fa-users"></i></div>
          <div><div class="inv-card-title" style="color:var(--blue)">Regular Investor</div><div style="font-size:11px;color:var(--muted);font-family:'Space Mono',monospace">STANDARD PLAN</div></div>
        </div>
        <div class="inv-card-sub">নির্ধারিত স্লট মূল্যে বিনিয়োগ করে সাধারণ শেয়ারহোল্ডার হওয়ার সুযোগ। মাদার প্রজেক্টের মাধ্যমে চাইল্ড প্রজেক্টে ফান্ড যায় এবং প্রফিট শেয়ার পান।</div>
        <div class="inv-feature"><i class="fas fa-check-circle" style="color:var(--blue)"></i><span>মাদার প্রজেক্টে বিনিয়োগ</span></div>
        <div class="inv-feature"><i class="fas fa-check-circle" style="color:var(--blue)"></i><span>নিয়মিত শেয়ার অনুযায়ী প্রফিট</span></div>
        <div class="inv-feature"><i class="fas fa-check-circle" style="color:var(--blue)"></i><span>ERP পোর্টালে সম্পূর্ণ ট্র্যাকিং</span></div>
        <div class="inv-feature"><i class="fas fa-check-circle" style="color:var(--blue)"></i><span>Active বা Passive — নিজে বেছে নিন</span></div>
      </div>
      <div class="inv-card" style="background:rgba(6,214,160,.04);border-color:rgba(6,214,160,.35)">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
          <div style="width:44px;height:44px;border-radius:14px;background:linear-gradient(135deg,var(--emerald),#0d9488);display:flex;align-items:center;justify-content:center;font-size:18px;color:#fff"><i class="fas fa-gem"></i></div>
          <div><div class="inv-card-title" style="color:var(--emerald)">Special Investor</div><div style="font-size:11px;color:var(--muted);font-family:'Space Mono',monospace">PREMIUM PLAN ★</div></div>
        </div>
        <div class="inv-card-sub">স্পেশাল ইনভেস্টর মাদার প্রজেক্টে বিনিয়োগের পাশাপাশি সরাসরি চাইল্ড প্রজেক্টেও বিনিয়োগ করতে পারেন। ফলে চাইল্ড প্রজেক্টের ইনসেন্টিভ থেকেও আলাদা সুবিধা পান।</div>
        <div class="inv-feature"><i class="fas fa-star" style="color:var(--emerald)"></i><span>মাদার + চাইল্ড উভয়তে বিনিয়োগ</span></div>
        <div class="inv-feature"><i class="fas fa-star" style="color:var(--emerald)"></i><span>চাইল্ড প্রজেক্টের ইনসেন্টিভ বোনাস</span></div>
        <div class="inv-feature"><i class="fas fa-star" style="color:var(--emerald)"></i><span>উচ্চতর রিটার্ন রেট</span></div>
        <div class="inv-feature"><i class="fas fa-star" style="color:var(--emerald)"></i><span>অগ্রাধিকারমূলক সাপোর্ট ও রিপোর্ট</span></div>
      </div>
    </div>

    <!-- Profit Flow breakdown -->
    <div class="profit-flow reveal d3">
      <div class="pf-title">💰 প্রফিট ফ্লো — কীভাবে আপনার লাভ আসে</div>
      <div class="pf-sub">প্রতিটি ধাপে কী হয় তা সংক্ষেপে বোঝুন</div>
      <div class="pf-steps">
        <div class="pf-step">
          <div class="pf-dot" style="background:rgba(67,97,238,.15);border:1px solid rgba(67,97,238,.35);color:#818cf8">১</div>
          <div class="pf-content"><div class="pf-step-title">ইনভেস্টর → মাদার প্রজেক্ট</div><div class="pf-step-body">আপনার বিনিয়োগ মাদার প্রজেক্ট Sodai Lagbe-এ যায়। এখানে সব শেয়ারহোল্ডারের ফান্ড একত্রিত হয়ে একটি শক্তিশালী পুঁজি তৈরি করে।</div></div>
        </div>
        <div class="pf-step">
          <div class="pf-dot" style="background:rgba(230,57,70,.12);border:1px solid rgba(230,57,70,.3);color:#f87171">২</div>
          <div class="pf-content"><div class="pf-step-title">মাদার → চাইল্ড প্রজেক্ট (৩টি বিভাগ)</div><div class="pf-step-body">মাদার প্রজেক্ট থেকে Groceries, Dairy Items ও China Import প্রজেক্টে মূলধন সরবরাহ করা হয়। প্রতিটি প্রজেক্ট স্বাধীনভাবে পরিচালিত হয়।</div></div>
        </div>
        <div class="pf-step">
          <div class="pf-dot" style="background:rgba(251,191,36,.12);border:1px solid rgba(251,191,36,.3);color:#fbbf24">৩</div>
          <div class="pf-content"><div class="pf-step-title">স্পেশাল ইনভেস্টর → সরাসরি চাইল্ড</div><div class="pf-step-body">স্পেশাল ইনভেস্টররা মাদার প্রজেক্টে বিনিয়োগ করার পাশাপাশি সরাসরি চাইল্ড প্রজেক্টেও বিনিয়োগ করতে পারেন, যা তাদের অতিরিক্ত ইনসেন্টিভ দেয়।</div></div>
        </div>
        <div class="pf-step">
          <div class="pf-dot" style="background:rgba(6,214,160,.1);border:1px solid rgba(6,214,160,.3);color:var(--emerald)">৪</div>
          <div class="pf-content"><div class="pf-step-title">চাইল্ড → মাদার: Incentive ফেরত</div><div class="pf-step-body">চাইল্ড প্রজেক্টের আয়ের একটি নির্দিষ্ট অংশ ইনসেন্টিভ হিসেবে মাদার প্রজেক্টে ফেরত আসে। এই অর্থ কোম্পানির বৃদ্ধি এবং শেয়ারহোল্ডারদের প্রফিট পুলে যোগ হয়।</div></div>
        </div>
        <div class="pf-step">
          <div class="pf-dot" style="background:rgba(6,214,160,.1);border:1px solid rgba(6,214,160,.3);color:var(--emerald)">৫</div>
          <div class="pf-content"><div class="pf-step-title">শেয়ারহোল্ডার অ্যাকাউন্টে প্রফিট</div><div class="pf-step-body">চূড়ান্ত প্রফিট Active ও Passive শেয়ারহোল্ডারদের মধ্যে শেয়ার অনুপাতে স্বয়ংক্রিয়ভাবে বিতরণ হয়। ERP পোর্টালে লগইন করে যেকোনো সময় ব্যালেন্স দেখুন।</div></div>
        </div>
      </div>
    </div>

    <!-- Why invest -->
    <div style="margin-bottom:24px" class="reveal d1">
      <div class="sec-lbl" style="text-align:center;margin-bottom:20px"><i class="fas fa-trophy" style="margin-right:6px"></i>কেন বিনিয়োগ করবেন?</div>
    </div>
    <div class="why-grid reveal d2">
      <div class="why-card">
        <div class="why-icon" style="background:linear-gradient(135deg,var(--red),var(--orange))"><i class="fas fa-shield-halved"></i></div>
        <div><div class="why-title">সম্পূর্ণ স্বচ্ছ সিস্টেম</div><div class="why-body">প্রতিটি টাকার হিসাব ERP-তে রেকর্ড। কোনো লুকানো চার্জ নেই, কোনো অস্পষ্ট শর্ত নেই। আপনি নিজে দেখতে পাবেন আপনার অর্থ কোথায় যাচ্ছে।</div></div>
      </div>
      <div class="why-card">
        <div class="why-icon" style="background:linear-gradient(135deg,var(--emerald),#0d9488)"><i class="fas fa-money-bill-trend-up"></i></div>
        <div><div class="why-title">মাল্টিপল রেভিনিউ স্ট্রিম</div><div class="why-body">একটি বিনিয়োগে তিনটি চাইল্ড প্রজেক্ট — Groceries, Dairy Items, China Import। তিনটি থেকেই প্রফিট আসে, ঝুঁকি কম, লাভ বেশি।</div></div>
      </div>
      <div class="why-card">
        <div class="why-icon" style="background:linear-gradient(135deg,var(--blue),var(--indigo))"><i class="fas fa-mobile-screen"></i></div>
        <div><div class="why-title">রিয়েল-টাইম ডিজিটাল ম্যানেজমেন্ট</div><div class="why-body">মোবাইল বা কম্পিউটার থেকে যেকোনো সময় আপনার পোর্টফোলিও, প্রফিট, শেয়ার পারসেন্টেজ ও ট্রানজেকশন হিস্ট্রি দেখুন।</div></div>
      </div>
      <div class="why-card">
        <div class="why-icon" style="background:linear-gradient(135deg,#f59e0b,#d97706)"><i class="fas fa-handshake"></i></div>
        <div><div class="why-title">বিশ্বস্ত স্থানীয় ব্যবসা</div><div class="why-body">সদাই লাগবে টাঙ্গাইল সিটি থেকে শুরু হয়ে সারা বাংলাদেশে ছড়িয়ে পড়ার পরিকল্পনায় আছে। স্থানীয় মানুষের জন্য, স্থানীয় মানুষের বিনিয়োগে।</div></div>
      </div>
      <div class="why-card">
        <div class="why-icon" style="background:linear-gradient(135deg,#8b5cf6,#6d28d9)"><i class="fas fa-chart-pie"></i></div>
        <div><div class="why-title">ন্যায্য শেয়ার ভিত্তিক প্রফিট</div><div class="why-body">আপনার শেয়ার যত বেশি, প্রফিট তত বেশি। কেউ বঞ্চিত হয় না — গাণিতিকভাবে হিসাব করা ERP সিস্টেমে সবার প্রফিট নির্ধারিত হয়।</div></div>
      </div>
      <div class="why-card">
        <div class="why-icon" style="background:linear-gradient(135deg,#ec4899,#be185d)"><i class="fas fa-expand"></i></div>
        <div><div class="why-title">প্রজেক্ট সম্প্রসারণের সুযোগ</div><div class="why-body">ভবিষ্যতে আরও চাইল্ড প্রজেক্ট যুক্ত হবে। আপনি একজন শেয়ারহোল্ডার হিসেবে প্রতিটি নতুন প্রজেক্টের ভোটিং ও সিদ্ধান্তে অংশ নিতে পারবেন।</div></div>
      </div>
    </div>

    <!-- Trust Numbers -->
    <div class="trust-row reveal d3">
      <div class="trust-item"><div class="trust-num">৩+</div><div class="trust-label">Active Projects</div></div>
      <div class="trust-item"><div class="trust-num">১০০%</div><div class="trust-label">Transparent ERP</div></div>
      <div class="trust-item"><div class="trust-num">২৪/৭</div><div class="trust-label">Portal Access</div></div>
      <div class="trust-item"><div class="trust-num">∞</div><div class="trust-label">Scalable Growth</div></div>
    </div>

  </div>
</section>

<!-- ══ CTA ══ -->
<section class="cta-sec">
  <div class="cta-card reveal">
    <div style="display:inline-flex;align-items:center;gap:8px;padding:6px 14px;border-radius:999px;background:rgba(6,214,160,.08);border:1px solid rgba(6,214,160,.25);color:var(--emerald);font-size:11px;font-family:'Space Mono',monospace;margin-bottom:20px"><span style="width:6px;height:6px;border-radius:50%;background:var(--emerald);animation:pdot 2s infinite"></span>Portal Active</div>
    <h2 style="font-size:clamp(20px,3vw,30px);font-weight:800;letter-spacing:-.5px;margin-bottom:14px;color:var(--text)">এখনই শেয়ারহোল্ডার হোন</h2>
    <p style="color:var(--muted);font-size:14px;line-height:1.8;margin-bottom:28px;max-width:480px;margin-left:auto;margin-right:auto">লগইন করে আপনার বিনিয়োগের অবস্থান দেখুন, প্রফিট ট্র্যাক করুন এবং কোম্পানির সিদ্ধান্তে অংশ নিন। সদাই লাগবের সাথে আপনার আর্থিক ভবিষ্যৎ গড়ুন।</p>
    <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
      <a href="login.php" class="btn-p"><i class="fas fa-sign-in-alt"></i> পোর্টালে লগইন করুন</a>
      <a href="#how-it-works" class="btn-g"><i class="fas fa-book-open"></i> আরও জানুন</a>
    </div>
  </div>
</section>

<!-- ══ FOOTER ══ -->
<footer>
  <div class="foot">
    <div style="display:flex;align-items:center;gap:10px">
      <div style="width:28px;height:28px;border-radius:8px;background:linear-gradient(135deg,var(--red),var(--orange));display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px">
        <?php if (!empty($site_logo)): ?><img src="<?= htmlspecialchars($site_logo) ?>" alt="Logo" style="width:100%;height:100%;object-fit:contain;border-radius:8px"><?php else: ?><i class="fas fa-chart-pie"></i><?php endif; ?>
      </div>
      <span style="font-weight:800;font-size:14px;color:var(--text)">Sodai Lagbe</span>
    </div>
    <p class="foot-copy">&copy; <?= date('Y') ?> All rights reserved. Shareholder ERP — Tangail, Bangladesh</p>
  </div>
</footer>

<script>
// Theme toggle
const html = document.documentElement;
const btn  = document.getElementById('themeBtn');
const saved = localStorage.getItem('sl-theme') || 'dark';
html.setAttribute('data-theme', saved);
btn.textContent = saved === 'dark' ? '☀️' : '🌙';
btn.addEventListener('click', () => {
  const t = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
  html.setAttribute('data-theme', t);
  btn.textContent = t === 'dark' ? '☀️' : '🌙';
  localStorage.setItem('sl-theme', t);
});

// Nav shadow
const nav = document.getElementById('navbar');
window.addEventListener('scroll', () => {
  nav.style.boxShadow = window.scrollY > 10 ? '0 4px 30px rgba(0,0,0,.3)' : 'none';
});

// Scroll reveal
const obs = new IntersectionObserver(entries => {
  entries.forEach(e => { if (e.isIntersecting) e.target.classList.add('in'); });
}, { threshold: 0.08 });
document.querySelectorAll('.reveal').forEach(el => obs.observe(el));
</script>
</body>
</html>