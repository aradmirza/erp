<?php
ini_set('session.cookie_lifetime', 86400);
ini_set('session.gc_maxlifetime', 86400);
session_set_cookie_params(86400);
session_start();
if(!isset($_SESSION['user_logged_in'])){ header("Location: login.php"); exit; }
require_once 'db.php';

$user_account_id = $_SESSION['user_account_id'];
$user_name       = $_SESSION['user_name'];

$adv_stmt = $pdo->prepare("SELECT role_name FROM advisor_targets WHERE user_id = ?");
$adv_stmt->execute([$user_account_id]);
$my_advisor_role = $adv_stmt->fetchColumn();

$stmt_perm = $pdo->prepare("SELECT can_vote FROM shareholder_accounts WHERE id = ?");
$stmt_perm->execute([$user_account_id]);
$can_vote = (int)$stmt_perm->fetchColumn();

try {
    $financials    = $pdo->query("SELECT * FROM financials ORDER BY FIELD(status,'pending','approved'), date_added DESC, id DESC")->fetchAll();
    $category_map  = ['general'=>'জেনারেল খরচ','third_party'=>'থার্ড পার্টি','online_purchase'=>'অনলাইন পারচেজ','no_record'=>'রেকর্ড নেই'];
} catch(PDOException $e){ die($e->getMessage()); }

$total_profit  = array_sum(array_map(fn($f)=>$f['type']==='profit'&&$f['status']==='approved'?(float)$f['amount']:0,$financials));
$total_expense = array_sum(array_map(fn($f)=>$f['type']==='expense'&&$f['status']==='approved'?(float)$f['amount']:0,$financials));
$pending_count = count(array_filter($financials,fn($f)=>$f['status']==='pending'));
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<script>(function(){var t=localStorage.getItem('erpTheme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="theme.css">
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
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0,user-scalable=no">
<title>লেনদেন — Sodai Lagbe ERP</title>

<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<style>
@keyframes modalIn{from{opacity:0;transform:scale(.94) translateY(10px)}to{opacity:1;transform:scale(1) translateY(0)}}
.chip{display:inline-flex;align-items:center;gap:5px;padding:7px 14px;border-radius:11px;font-size:11px;font-weight:700;font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.07em;cursor:pointer;border:1px solid var(--border);background:var(--surface2);color:var(--muted);transition:all .2s;-webkit-tap-highlight-color:transparent}
.chip.on{border-color:var(--border2);background:var(--surface3);color:var(--text)}
.chip.p-on{border-color:rgba(6,214,160,.35);background:rgba(6,214,160,.1);color:var(--emerald)}
.chip.e-on{border-color:rgba(252,165,165,.3);background:rgba(252,165,165,.07);color:#fca5a5}
.chip.q-on{border-color:rgba(251,191,36,.3);background:rgba(251,191,36,.08);color:var(--amber)}
.trow{display:flex;align-items:flex-start;gap:13px;padding:15px 0;border-bottom:1px solid var(--border)}
.trow:last-child{border-bottom:none}
.tic{width:42px;height:42px;border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0}
.tmeta{flex:1;min-width:0}
.tdate{font-size:10px;color:var(--muted2);font-family:'Space Mono',monospace;margin-bottom:3px}
.tdesc{font-size:13px;font-weight:600;color:var(--text);line-height:1.4;margin-bottom:6px}
.ttags{display:flex;flex-wrap:wrap;gap:5px}
.tamt{font-size:15px;font-weight:800;flex-shrink:0;text-align:right;padding-top:2px;white-space:nowrap}
.sgrid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:22px}
@media(max-width:460px){.sgrid{grid-template-columns:1fr 1fr}.sgrid .sc3{grid-column:1/-1}}
</style>
</head>
<body>

<nav class="erp-nav" id="enav">
    <a href="index.php" class="nav-brand">
        <div class="nav-brand-icon"><i class="fas fa-store-alt" style="color:#fff;font-size:15px"></i></div>
        <div><div class="nav-brand-text">Sodai Lagbe</div><div class="nav-brand-sub">ERP Portal</div></div>
    </a>
    <div class="nav-right">
        <div class="nav-links">
            <a href="index.php" class="nav-link">ড্যাশবোর্ড</a>
            <a href="transactions.php" class="nav-link active">লেনদেন</a>
            <?php if($can_vote): ?><a href="user_votes.php" class="nav-link">ভোটিং</a><?php endif; ?>
            <?php if($my_advisor_role): ?><a href="user_kpi.php" class="nav-link" style="color:var(--amber)">KPI</a><?php endif; ?>
        </div>
        <div class="nav-user">
            <div class="nav-user-name"><?= htmlspecialchars($user_name) ?></div>
            <div class="nav-user-role">Shareholder</div>
        </div>
        <button onclick="toggleTheme()" class="nav-icon-btn theme-toggle-btn" title="Dark Mode এ যান" aria-label="Toggle theme">
            <i class="fas fa-moon"></i>
        </button>
        <a href="logout.php" class="nav-icon-btn danger"><i class="fas fa-power-off"></i></a>
    </div>
</nav>

<nav class="erp-bottom-nav">
    <a href="index.php" class="bnav-item"><i class="fas fa-home"></i><span>Home</span></a>
    <a href="transactions.php" class="bnav-item active"><div class="bnav-indicator"></div><i class="fas fa-exchange-alt"></i><span>Trans</span></a>
    <?php if($can_vote): ?><a href="user_votes.php" class="bnav-item"><i class="fas fa-poll"></i><span>Vote</span></a><?php endif; ?>
    <?php if($my_advisor_role): ?><a href="user_kpi.php" class="bnav-item kpi-active"><i class="fas fa-bullseye"></i><span>KPI</span></a><?php endif; ?>
    <a href="index.php" class="bnav-item"><i class="fas fa-arrow-left"></i><span>Back</span></a>
</nav>

<main class="erp-page">

    <div class="fade-in" style="margin-bottom:20px">
        <div class="sec-label">Company Financials</div>
        <h1 class="sec-title">লেনদেন ও হিসাব</h1>
        <p class="sec-sub">কোম্পানির সম্পূর্ণ আয়-ব্যয়ের তালিকা</p>
    </div>

    <!-- Summary -->
    <div class="sgrid fade-in-1">
        <div class="stat-card" style="border-color:rgba(6,214,160,.25)">
            <div style="font-size:9px;font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.12em;color:var(--emerald);margin-bottom:5px">মোট আয়</div>
            <div style="font-size:19px;font-weight:800;color:var(--emerald)">৳<?= number_format($total_profit,0) ?></div>
        </div>
        <div class="stat-card" style="border-color:rgba(252,165,165,.2)">
            <div style="font-size:9px;font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.12em;color:#fca5a5;margin-bottom:5px">মোট খরচ</div>
            <div style="font-size:19px;font-weight:800;color:#fca5a5">৳<?= number_format($total_expense,0) ?></div>
        </div>
        <div class="stat-card sc3" style="border-color:rgba(251,191,36,.2)">
            <div style="font-size:9px;font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.12em;color:var(--amber);margin-bottom:5px">Pending</div>
            <div style="font-size:19px;font-weight:800;color:var(--amber)"><?= $pending_count ?> টি</div>
        </div>
    </div>

    <div class="alert alert-info fade-in-1" style="margin-bottom:18px">
        <i class="fas fa-info-circle" style="flex-shrink:0;font-size:15px"></i>
        <span>হিসাব প্রথমে <strong style="color:var(--amber)">Pending</strong> থাকে। অ্যাডমিন অ্যাপ্রুভ করলে <strong style="color:var(--emerald)">Approved</strong> হয়ে মূল ফান্ডে যুক্ত হয়।</span>
    </div>

    <!-- Filters -->
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px" class="fade-in-2">
        <div class="chip on" onclick="ft('all',this)"><i class="fas fa-list" style="font-size:10px"></i>সব (<?= count($financials) ?>)</div>
        <div class="chip" onclick="ft('profit',this)" id="cp"><i class="fas fa-arrow-trend-up" style="font-size:10px"></i>Profit</div>
        <div class="chip" onclick="ft('expense',this)" id="ce"><i class="fas fa-arrow-trend-down" style="font-size:10px"></i>Expense</div>
        <?php if($pending_count>0): ?>
        <div class="chip" onclick="ft('pending',this)" id="cq"><i class="fas fa-clock" style="font-size:10px"></i>Pending (<?= $pending_count ?>)</div>
        <?php endif; ?>
    </div>

    <!-- List -->
    <div class="card card-p fade-in-3">
    <?php if(count($financials)>0): foreach($financials as $fin):
        $ip = ($fin['type']==='profit');
        $iq = ($fin['status']==='pending');
    ?>
    <div class="trow" data-t="<?= $ip?'profit':'expense' ?>" data-s="<?= $iq?'pending':'approved' ?>">
        <div class="tic" style="<?= $iq?'background:rgba(251,191,36,.1);color:var(--amber)':($ip?'background:rgba(6,214,160,.1);color:var(--emerald)':'background:rgba(252,165,165,.08);color:#fca5a5') ?>">
            <i class="fas <?= $ip?'fa-arrow-trend-up':'fa-arrow-trend-down' ?>"></i>
        </div>
        <div class="tmeta">
            <div class="tdate"><?= date('d M Y',strtotime($fin['date_added'])) ?> · <?= htmlspecialchars(!empty($fin['added_by'])?$fin['added_by']:'Admin') ?></div>
            <div class="tdesc"><?= htmlspecialchars($fin['description']) ?></div>
            <div class="ttags">
                <?php if($iq): ?><span class="badge badge-amber"><i class="fas fa-clock"></i>Pending</span>
                <?php else: ?><span class="badge badge-emerald"><i class="fas fa-check"></i>Approved</span><?php endif; ?>
                <span class="badge badge-gray"><?= $ip ? 'Profit' : ($category_map[$fin['expense_category']]??'Expense') ?></span>
                <?php if(!empty($fin['receipt_image'])): ?>
                <button onclick="openImg('admin/<?= htmlspecialchars(addslashes($fin['receipt_image'])) ?>')"
                    style="display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:999px;border:1px solid rgba(67,97,238,.3);background:rgba(67,97,238,.08);color:#a5b4fc;font-size:9px;font-weight:700;cursor:pointer;font-family:'Space Mono',monospace;text-transform:uppercase;transition:background .2s"
                    onmouseover="this.style.background='rgba(67,97,238,.2)'" onmouseout="this.style.background='rgba(67,97,238,.08)'">
                    <i class="fas fa-image"></i>মেমো</button>
                <?php endif; ?>
            </div>
        </div>
        <div class="tamt" style="color:<?= $ip?'var(--emerald)':'#fca5a5' ?>"><?= $ip?'+':'−' ?>৳<?= number_format($fin['amount'],2) ?></div>
    </div>
    <?php endforeach; else: ?>
    <div style="text-align:center;padding:48px 20px;color:var(--muted)">
        <i class="fas fa-file-invoice-dollar" style="font-size:38px;opacity:.15;display:block;margin-bottom:12px"></i>
        <p>কোনো লেনদেনের হিসাব পাওয়া যায়নি।</p>
    </div>
    <?php endif; ?>
    </div>

</main>

<!-- Image Modal -->
<div id="imgModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.88);backdrop-filter:blur(12px);z-index:400;align-items:center;justify-content:center;padding:20px">
    <div style="background:var(--surface);border:1px solid var(--border2);border-radius:24px;padding:18px;max-width:540px;width:100%;position:relative;animation:modalIn .3s cubic-bezier(.16,1,.3,1)">
        <button onclick="closeImg()" style="position:absolute;top:-12px;right:-12px;width:30px;height:30px;border-radius:50%;background:var(--red);border:none;color:#fff;font-size:12px;cursor:pointer;display:flex;align-items:center;justify-content:center"><i class="fas fa-times"></i></button>
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;padding-bottom:10px;border-bottom:1px solid var(--border)">
            <div style="width:26px;height:26px;border-radius:7px;background:rgba(67,97,238,.1);display:flex;align-items:center;justify-content:center;color:var(--blue);font-size:11px"><i class="fas fa-receipt"></i></div>
            <span style="font-size:13px;font-weight:700">মেমো / রসিদ</span>
        </div>
        <div style="max-height:65vh;overflow:hidden;border-radius:12px;background:var(--surface2);display:flex;align-items:center;justify-content:center">
            <img id="imgSrc" src="" alt="Receipt" style="max-width:100%;max-height:65vh;object-fit:contain;display:block;border-radius:12px">
        </div>
    </div>
</div>

<script>
window.addEventListener('scroll',()=>document.getElementById('enav').classList.toggle('shadow',window.scrollY>8));
document.getElementById('imgModal').addEventListener('click',function(e){if(e.target===this)closeImg();});
function openImg(s){document.getElementById('imgSrc').src=s;document.getElementById('imgModal').style.display='flex';document.body.style.overflow='hidden';}
function closeImg(){document.getElementById('imgModal').style.display='none';document.getElementById('imgSrc').src='';document.body.style.overflow='';}
function ft(type,el){
    document.querySelectorAll('.chip').forEach(c=>{c.classList.remove('on','p-on','e-on','q-on');});
    el.classList.add('on');
    if(type==='profit')el.classList.add('p-on');
    else if(type==='expense')el.classList.add('e-on');
    else if(type==='pending')el.classList.add('q-on');
    document.querySelectorAll('.trow').forEach(r=>{
        const show=type==='all'||(type==='profit'&&r.dataset.t==='profit')||(type==='expense'&&r.dataset.t==='expense')||(type==='pending'&&r.dataset.s==='pending');

        r.style.display=show?'flex':'none';
    });
}
</script>
<script src="theme.js"></script>
</body>
</html>