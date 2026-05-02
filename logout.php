<?php
session_start();
session_unset();
session_destroy();
setcookie(session_name(), '', time() - 3600, '/');
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
:root{--red:#e63946;--orange:#f4845f;--amber:#fbbf24;--emerald:#06d6a0;--blue:#4361ee;--indigo:#3a0ca3;--teal:#0d9488;--bg:#0d1117;--surface:#161b22;--surface2:#1c2128;--surface3:#21262d;--border:rgba(255,255,255,.08);--border2:rgba(255,255,255,.13);--text:#e6edf3;--muted:#8b949e;--muted2:#6e7681;--nav-h:64px;--bottom-nav-h:72px;--radius-sm:12px;--radius-md:18px;--radius-lg:24px;--radius-xl:28px}
body{font-family:'Sora',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;-webkit-font-smoothing:antialiased;-webkit-tap-highlight-color:transparent;overflow-x:hidden}
::-webkit-scrollbar{width:4px;height:4px}::-webkit-scrollbar-thumb{background:rgba(255,255,255,.1);border-radius:99px}
.erp-nav{position:fixed;top:0;left:0;right:0;z-index:100;height:var(--nav-h);padding:0 16px;display:flex;align-items:center;justify-content:space-between;background:rgba(13,17,23,.92);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border-bottom:1px solid var(--border);transition:box-shadow .3s}
.erp-nav.shadow{box-shadow:0 4px 30px rgba(0,0,0,.5)}
.nav-brand{display:flex;align-items:center;gap:10px;text-decoration:none}
.nav-brand-icon{width:38px;height:38px;border-radius:12px;background:linear-gradient(135deg,var(--red),var(--orange));display:flex;align-items:center;justify-content:center;color:#fff;font-size:16px;flex-shrink:0;box-shadow:0 4px 14px rgba(230,57,70,.4);overflow:hidden}
.nav-brand-icon img{width:100%;height:100%;object-fit:contain}
.nav-brand-text{font-size:15px;font-weight:800;color:var(--text);letter-spacing:-.3px;display:block}
.nav-brand-sub{font-size:9px;color:var(--muted);font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.15em;display:block}
.nav-right{display:flex;align-items:center;gap:8px}
.nav-links{display:none;align-items:center;gap:4px}
@media(min-width:768px){.nav-links{display:flex}}
.nav-link{font-size:12px;font-weight:600;color:var(--muted);text-decoration:none;padding:7px 14px;border-radius:10px;border:1px solid transparent;transition:all .2s}
.nav-link:hover{color:var(--text);background:var(--surface2);border-color:var(--border)}
.nav-link.active{color:var(--text);background:var(--surface2);border-color:var(--border2)}
.nav-user{display:none;flex-direction:column;text-align:right;padding-right:10px;border-right:1px solid var(--border)}
@media(min-width:640px){.nav-user{display:flex}}
.nav-user-name{font-size:13px;font-weight:700;color:var(--text)}
.nav-user-role{font-size:9px;color:var(--muted);font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.12em}
.nav-icon-btn{width:36px;height:36px;border-radius:10px;border:1px solid var(--border);background:var(--surface);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:14px;color:var(--muted);transition:all .2s;text-decoration:none;overflow:hidden}
.nav-icon-btn:hover{border-color:var(--border2);color:var(--text);background:var(--surface2)}
.nav-icon-btn.danger:hover{background:rgba(230,57,70,.1);border-color:rgba(230,57,70,.3);color:var(--red)}
.erp-bottom-nav{display:flex;position:fixed;bottom:0;left:0;right:0;height:var(--bottom-nav-h);background:rgba(22,27,34,.97);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border-top:1px solid var(--border);padding-bottom:env(safe-area-inset-bottom);z-index:100;justify-content:space-around;align-items:stretch}
@media(min-width:768px){.erp-bottom-nav{display:none}}
.bnav-item{display:flex;flex-direction:column;align-items:center;justify-content:center;flex:1;gap:3px;text-decoration:none;color:var(--muted2);font-size:9px;font-weight:700;font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.06em;transition:color .2s;position:relative;border:none;background:none;cursor:pointer}
.bnav-item i{font-size:19px;transition:transform .2s}
.bnav-item.active{color:var(--red)}.bnav-item.active i{transform:translateY(-2px)}
.bnav-item.kpi-active{color:var(--amber)}
.bnav-indicator{position:absolute;top:10px;width:28px;height:3px;border-radius:99px;background:var(--red);opacity:0;transition:opacity .2s}
.bnav-item.active .bnav-indicator{opacity:1}
.erp-page{max-width:900px;margin:0 auto;padding:calc(var(--nav-h) + 24px) 16px calc(var(--bottom-nav-h) + 20px)}
@media(min-width:768px){.erp-page{padding-bottom:36px;padding-left:24px;padding-right:24px}}
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;transition:border-color .25s,box-shadow .25s}
.card:hover{border-color:var(--border2);box-shadow:0 8px 30px rgba(0,0,0,.3)}
.card-p{padding:20px}
@media(min-width:640px){.card-p{padding:24px}}
.hero-card{background:linear-gradient(135deg,var(--red),#c0392b);border:1px solid rgba(230,57,70,.4);border-radius:var(--radius-xl);padding:24px;position:relative;overflow:hidden;box-shadow:0 20px 50px rgba(230,57,70,.2)}
.hero-card::before{content:'';position:absolute;top:-60%;right:-40%;width:280px;height:280px;background:radial-gradient(circle,rgba(255,255,255,.12),transparent 70%);pointer-events:none}
.hero-card-alt{background:linear-gradient(135deg,#f59e0b,var(--orange));border-color:rgba(245,158,11,.4);box-shadow:0 20px 50px rgba(245,158,11,.18)}
.stat-card{background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius-md);padding:16px}
.badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:999px;font-size:9px;font-weight:700;font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.1em}
.badge-red{background:rgba(230,57,70,.12);border:1px solid rgba(230,57,70,.3);color:#fca5a5}
.badge-emerald{background:rgba(6,214,160,.1);border:1px solid rgba(6,214,160,.25);color:#6ee7b7}
.badge-amber{background:rgba(251,191,36,.1);border:1px solid rgba(251,191,36,.25);color:#fde68a}
.badge-blue{background:rgba(67,97,238,.12);border:1px solid rgba(67,97,238,.3);color:#a5b4fc}
.badge-gray{background:rgba(255,255,255,.06);border:1px solid var(--border);color:var(--muted)}
.sec-label{font-family:'Space Mono',monospace;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.2em;color:var(--muted2);margin-bottom:8px;display:flex;align-items:center;gap:6px}
.sec-label::before{content:'';width:16px;height:2px;border-radius:2px;background:var(--red)}
.sec-title{font-size:clamp(18px,4vw,24px);font-weight:800;letter-spacing:-.5px;color:var(--text);margin-bottom:6px}
.sec-sub{font-size:13px;color:var(--muted);line-height:1.65}
.alert{padding:13px 16px;border-radius:var(--radius-md);font-size:13px;font-weight:600;display:flex;align-items:flex-start;gap:10px;line-height:1.5;margin-bottom:16px;animation:fadeIn .35s ease}
.alert i{font-size:15px;flex-shrink:0;margin-top:1px}
.alert-ok{background:rgba(6,214,160,.08);border:1px solid rgba(6,214,160,.22);color:#6ee7b7}.alert-ok i{color:var(--emerald)}
.alert-err{background:rgba(230,57,70,.08);border:1px solid rgba(230,57,70,.22);color:#fca5a5}.alert-err i{color:var(--red)}
.alert-info{background:rgba(67,97,238,.08);border:1px solid rgba(67,97,238,.22);color:#a5b4fc}.alert-info i{color:var(--blue)}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 20px;border-radius:var(--radius-sm);font-size:13px;font-weight:700;font-family:'Sora',sans-serif;cursor:pointer;border:none;text-decoration:none;transition:transform .18s,box-shadow .18s}
.btn:active{transform:scale(.97)}
.btn-red{background:linear-gradient(135deg,var(--red),var(--orange));color:#fff;box-shadow:0 6px 20px rgba(230,57,70,.35)}
.btn-blue{background:linear-gradient(135deg,var(--blue),var(--indigo));color:#fff;box-shadow:0 6px 20px rgba(67,97,238,.35)}
.btn-ghost{background:var(--surface2);border:1px solid var(--border);color:var(--muted)}
.btn-full{width:100%}
.form-label{font-size:10px;font-weight:700;font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.12em;color:var(--muted);display:block;margin-bottom:7px}
.form-input{width:100%;background:rgba(0,0,0,.2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:11px 14px;color:var(--text);font-size:14px;font-family:'Sora',sans-serif;outline:none;transition:border-color .2s,box-shadow .2s}
.form-input::placeholder{color:rgba(255,255,255,.18)}
.form-input:focus{border-color:rgba(67,97,238,.5);box-shadow:0 0 0 4px rgba(67,97,238,.1)}
.divider{height:1px;background:var(--border);margin:20px 0}
.prog-bar{height:6px;background:rgba(255,255,255,.06);border-radius:99px;overflow:hidden}
.prog-bar-fill{height:100%;border-radius:99px;transition:width 1s cubic-bezier(.16,1,.3,1)}
.prog-bar-emerald{background:linear-gradient(90deg,var(--emerald),var(--teal))}
.prog-bar-amber{background:linear-gradient(90deg,var(--amber),var(--orange))}
@keyframes fadeIn{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
.fade-in{animation:fadeIn .4s ease forwards}
.fade-in-1{animation:fadeIn .4s .1s ease both}
.fade-in-2{animation:fadeIn .4s .2s ease both}
.fade-in-3{animation:fadeIn .4s .3s ease both}
.fade-in-4{animation:fadeIn .4s .4s ease both}
@keyframes wave{0%{transform:rotate(0)}10%{transform:rotate(14deg)}20%{transform:rotate(-8deg)}30%{transform:rotate(14deg)}40%{transform:rotate(-4deg)}50%{transform:rotate(10deg)}60%{transform:rotate(0)}100%{transform:rotate(0)}}
.wave{display:inline-block;animation:wave 2.5s infinite;transform-origin:70% 70%}
.typewriter{overflow:hidden;white-space:nowrap;display:inline-block;animation:type 1.4s steps(30,end) forwards}
@keyframes type{from{width:0}to{width:100%}}
@keyframes modalIn{from{opacity:0;transform:scale(.94) translateY(10px)}to{opacity:1;transform:scale(1) translateY(0)}}
@keyframes gold-pulse{0%{box-shadow:0 0 0 0 rgba(245,158,11,.6)}70%{box-shadow:0 0 0 6px rgba(245,158,11,0)}100%{box-shadow:0 0 0 0 rgba(245,158,11,0)}}
@keyframes livepulse{0%,100%{opacity:1}50%{opacity:.25}}
</style>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Logging Out — Sodai Lagbe</title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;800&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Sora',sans-serif;background:#0d1117;min-height:100vh;display:flex;align-items:center;justify-content:center;color:#e6edf3}
body::before{content:'';position:fixed;inset:0;background:radial-gradient(ellipse at center,rgba(67,97,238,.12) 0%,transparent 70%);pointer-events:none}
.wrap{text-align:center;padding:40px}
.icon-ring{width:72px;height:72px;border-radius:50%;background:rgba(230,57,70,.1);border:1px solid rgba(230,57,70,.25);display:flex;align-items:center;justify-content:center;margin:0 auto 20px;font-size:26px;color:#e63946;animation:pulse 1.5s ease-in-out infinite}
@keyframes pulse{0%,100%{box-shadow:0 0 0 0 rgba(230,57,70,.3)}50%{box-shadow:0 0 0 14px rgba(230,57,70,0)}}
.spinner{width:28px;height:28px;border:3px solid rgba(255,255,255,.06);border-top-color:#4361ee;border-radius:50%;animation:spin 0.8s linear infinite;margin:16px auto 0}
@keyframes spin{to{transform:rotate(360deg)}}
h2{font-size:18px;font-weight:800;color:#e6edf3;margin-bottom:6px}
p{font-size:10px;color:#8b949e;text-transform:uppercase;letter-spacing:.18em;font-weight:600}
</style>
</head>
<body>
<div class="wrap">
  <div class="icon-ring"><i class="fas fa-power-off"></i></div>
  <h2>Logging Out</h2>
  <p>Sodai Lagbe ERP</p>
  <div class="spinner"></div>
</div>
<script>setTimeout(()=>{ window.location.href='login.php'; },1100);</script>
</body>
</html>