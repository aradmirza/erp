<?php
// ২৪ ঘণ্টার জন্য সেশন ও কুকি সেট করা
ini_set('session.cookie_lifetime', 86400);
ini_set('session.gc_maxlifetime', 86400);
session_set_cookie_params(86400);
session_start();
date_default_timezone_set('Asia/Dhaka');

if(!isset($_SESSION['user_logged_in'])) { header("Location: login.php"); exit; }
require_once 'db.php';

$user_id = $_SESSION['user_account_id'];
$user_name = $_SESSION['user_name'];

// ইউজারের প্রোফাইল পিকচার ফেচ করা
$stmt_pic = $pdo->prepare("SELECT profile_picture FROM shareholder_accounts WHERE id = ?");
$stmt_pic->execute([$user_id]);
$u_pic = $stmt_pic->fetchColumn();

// ইউজারের পারমিশন চেক করা
$stmt = $pdo->prepare("SELECT can_vote FROM shareholder_accounts WHERE id = ?");
$stmt->execute([$user_id]);
$can_vote = (int)$stmt->fetchColumn();

$message = $_SESSION['msg_success'] ?? ''; 
$error = $_SESSION['msg_error'] ?? '';
unset($_SESSION['msg_success'], $_SESSION['msg_error']);

if ($_SERVER["REQUEST_METHOD"] == "POST" && $can_vote) {
    
    // নতুন প্রস্তাবনা যোগ করা
    if (isset($_POST['action']) && $_POST['action'] == 'submit_proposal') {
        $title = trim($_POST['title']);
        $desc = trim($_POST['description']);
        
        $options_array = $_POST['options'] ?? []; 
        $options_json = null;
        if(is_array($options_array)) {
            $opts_filtered = array_filter(array_map('trim', $options_array), function($val) { return $val !== ''; });
            if(count($opts_filtered) > 1) { $options_json = json_encode(array_values($opts_filtered), JSON_UNESCAPED_UNICODE); }
        }

        if (!empty($title) && !empty($desc)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO proposals (account_id, title, description, options) VALUES (?, ?, ?, ?)");
                if($stmt->execute([$user_id, $title, $desc, $options_json])) {
                    $_SESSION['msg_success'] = "আপনার প্রস্তাবনা সফলভাবে জমা হয়েছে! অ্যাডমিন অ্যাপ্রুভ করলে ভোটিং শুরু হবে।";
                }
            } catch(PDOException $e) {
                $_SESSION['msg_error'] = "প্রস্তাবনা জমা দিতে সমস্যা হয়েছে।";
            }
        }
        header("Location: user_votes.php"); exit;
    }
    
    // ভোট দেওয়া
    if (isset($_POST['action']) && $_POST['action'] == 'submit_vote') {
        $proposal_id = (int)$_POST['proposal_id'];
        $vote = $_POST['vote_type'];
        
        try {
            $stmt = $pdo->prepare("INSERT INTO votes (proposal_id, account_id, vote) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE vote = ?");
            if($stmt->execute([$proposal_id, $user_id, $vote, $vote])) {
                $_SESSION['msg_success'] = "আপনার ভোট গ্রহণ করা হয়েছে!";
            }
        } catch(PDOException $e) {
            $_SESSION['msg_error'] = "ভোট দিতে সমস্যা হয়েছে।";
        }
        header("Location: user_votes.php"); exit;
    }

    // প্রস্তাবনায় মতামত/কমেন্ট দেওয়া
    if (isset($_POST['action']) && $_POST['action'] == 'submit_comment') {
        $proposal_id = (int)$_POST['proposal_id'];
        $comment = trim($_POST['comment_text']);
        
        if (!empty($comment)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO proposal_comments (proposal_id, account_id, comment) VALUES (?, ?, ?)");
                if($stmt->execute([$proposal_id, $user_id, $comment])) {
                    $_SESSION['msg_success'] = "আপনার মতামত সফলভাবে যুক্ত হয়েছে!";
                }
            } catch(PDOException $e) {
                $_SESSION['msg_error'] = "মতামত যুক্ত করতে সমস্যা হয়েছে।";
            }
        }
        header("Location: user_votes.php"); exit;
    }

    // নিজের প্রস্তাবনা ডিলিট করা (যদি পেন্ডিং থাকে)
    if (isset($_POST['action']) && $_POST['action'] == 'delete_proposal') {
        $proposal_id = (int)$_POST['proposal_id'];
        $stmt = $pdo->prepare("DELETE FROM proposals WHERE id = ? AND account_id = ? AND status = 'pending'"); 
        $stmt->execute([$proposal_id, $user_id]);
        if($stmt->rowCount() > 0) {
            $pdo->prepare("DELETE FROM votes WHERE proposal_id = ?")->execute([$proposal_id]);
            $_SESSION['msg_success'] = "আপনার প্রস্তাবনাটি সফলভাবে মুছে ফেলা হয়েছে।";
        } else { 
            $_SESSION['msg_error'] = "প্রস্তাবনাটি মুছে ফেলা সম্ভব হয়নি।"; 
        }
        header("Location: user_votes.php"); exit;
    }
}

// সকল প্রস্তাবনা আনা
$all_proposals = [];
if($can_vote) {
    try {
        // টাইম ওভার হলে স্ট্যাটাস অটো-ক্লোজ আপডেট
        $pdo->exec("UPDATE proposals SET status = 'closed' WHERE status = 'approved' AND end_time IS NOT NULL AND end_time <= NOW()");
        
        $all_proposals = $pdo->query("
            SELECT p.*, a.name, a.profile_picture 
            FROM proposals p 
            JOIN shareholder_accounts a ON p.account_id = a.id 
            WHERE p.status IN ('pending', 'approved', 'closed', 'rejected') 
            ORDER BY p.created_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {}
}
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
<title>ভোটিং — Sodai Lagbe ERP</title>

<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<style>
@keyframes modalIn{from{opacity:0;transform:scale(.94) translateY(10px)}to{opacity:1;transform:scale(1) translateY(0)}}
.vcard{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;margin-bottom:16px;transition:border-color .25s}
.vcard:hover{border-color:var(--border2)}
.vote-opt{display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:12px;border:1px solid var(--border);background:var(--surface2);cursor:pointer;transition:all .2s;margin-bottom:8px}
.vote-opt:hover{border-color:rgba(67,97,238,.4);background:rgba(67,97,238,.06)}
.vote-opt input[type=radio]{display:none}
.vote-opt.selected{border-color:rgba(67,97,238,.5);background:rgba(67,97,238,.1)}
.vote-opt .opt-dot{width:16px;height:16px;border-radius:50%;border:2px solid var(--border2);display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:all .2s}
.vote-opt.selected .opt-dot{border-color:var(--blue);background:var(--blue)}
.vote-opt.selected .opt-dot::after{content:'';width:6px;height:6px;border-radius:50%;background:#fff}
.pbar-wrap{margin-bottom:10px}
.pbar-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;font-size:11px;font-weight:700}
.comment-box{background:var(--surface2);border:1px solid var(--border);border-radius:12px;padding:12px;display:flex;align-items:flex-start;gap:10px;margin-bottom:8px}
.comment-avatar{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;overflow:hidden}
.comment-avatar img{width:100%;height:100%;object-fit:cover}
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
            <a href="transactions.php" class="nav-link">লেনদেন</a>
            <a href="user_votes.php" class="nav-link active">ভোটিং</a>
        </div>
        <div class="nav-user">
            <div class="nav-user-name"><?= htmlspecialchars($user_name) ?></div>
            <div class="nav-user-role">Shareholder</div>
        </div>
        <div class="nav-icon-btn" style="overflow:hidden">
            <?php if(!empty($u_pic)): ?><img src="<?= htmlspecialchars($u_pic) ?>" style="width:100%;height:100%;object-fit:cover"><?php else: ?><i class="fas fa-user"></i><?php endif; ?>
        </div>
        <button onclick="toggleTheme()" class="nav-icon-btn theme-toggle-btn" title="Dark Mode এ যান" aria-label="Toggle theme">
            <i class="fas fa-moon"></i>
        </button>
        <a href="logout.php" class="nav-icon-btn danger"><i class="fas fa-power-off"></i></a>
    </div>
</nav>

<nav class="erp-bottom-nav">
    <a href="index.php" class="bnav-item"><i class="fas fa-home"></i><span>Home</span></a>
    <a href="transactions.php" class="bnav-item"><i class="fas fa-exchange-alt"></i><span>Trans</span></a>
    <a href="user_votes.php" class="bnav-item active"><div class="bnav-indicator"></div><i class="fas fa-poll"></i><span>Vote</span></a>
</nav>

<main class="erp-page">

    <div class="fade-in" style="margin-bottom:20px">
        <div class="sec-label">Democracy & Governance</div>
        <h1 class="sec-title">ভোটিং প্যানেল</h1>
        <p class="sec-sub">প্রস্তাবনা দিন এবং কোম্পানির সিদ্ধান্তে অংশ নিন</p>
    </div>

<?php if(!$can_vote): ?>
    <div class="card card-p" style="text-align:center;border-color:rgba(230,57,70,.3);background:rgba(230,57,70,.04)">
        <div style="width:60px;height:60px;border-radius:50%;background:rgba(230,57,70,.1);border:1px solid rgba(230,57,70,.3);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:24px;color:var(--red)"><i class="fas fa-lock"></i></div>
        <div style="font-size:18px;font-weight:800;color:var(--red);margin-bottom:8px">অ্যাক্সেস নেই</div>
        <p style="font-size:13px;color:var(--muted);max-width:320px;margin:0 auto;line-height:1.65">আপনার ভোটিং পারমিশন নেই। অ্যাডমিনের সাথে যোগাযোগ করুন।</p>
    </div>
<?php else: ?>

<?php if($message): ?><div class="alert alert-ok fade-in"><i class="fas fa-check-circle"></i><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if($error): ?><div class="alert alert-err fade-in"><i class="fas fa-exclamation-triangle"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px" class="fade-in-1">
        <div class="sec-label" style="margin-bottom:0"><i class="fas fa-poll-h" style="margin-right:6px"></i>প্রস্তাবনা বোর্ড</div>
        <button onclick="document.getElementById('newPropModal').style.display='flex'"
            style="display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:11px;background:linear-gradient(135deg,var(--blue),var(--indigo));border:none;color:#fff;font-size:12px;font-weight:700;cursor:pointer;font-family:'Sora',sans-serif;box-shadow:0 6px 18px rgba(67,97,238,.3);transition:all .2s"
            onmouseover="this.style.boxShadow='0 10px 26px rgba(67,97,238,.45)'" onmouseout="this.style.boxShadow='0 6px 18px rgba(67,97,238,.3)'">
            <i class="fas fa-pen-nib"></i>নতুন প্রস্তাবনা
        </button>
    </div>

    <div class="fade-in-2">
    <?php if(empty($all_proposals)): ?>
        <div class="card card-p" style="text-align:center;border-style:dashed;color:var(--muted);padding:48px 20px">
            <i class="fas fa-inbox" style="font-size:38px;opacity:.15;display:block;margin-bottom:12px"></i>
            <p>বর্তমানে কোনো প্রস্তাবনা নেই।</p>
        </div>
    <?php else: foreach($all_proposals as $p):
        $votes_stmt = $pdo->query("SELECT vote, COUNT(*) as cnt FROM votes WHERE proposal_id = {$p['id']} GROUP BY vote");
        $vote_counts = []; $total_votes = 0;
        while($vr = $votes_stmt->fetch()){ $vote_counts[$vr['vote']] = $vr['cnt']; $total_votes += $vr['cnt']; }
        $my_vote_stmt = $pdo->prepare("SELECT vote FROM votes WHERE proposal_id=? AND account_id=?");
        $my_vote_stmt->execute([$p['id'],$user_id]);
        $my_vote = $my_vote_stmt->fetchColumn();
        $p_comments = $pdo->query("SELECT pc.*,IF(pc.account_id=0,'Admin',a.name) as name,a.profile_picture FROM proposal_comments pc LEFT JOIN shareholder_accounts a ON pc.account_id=a.id WHERE pc.proposal_id={$p['id']} ORDER BY pc.created_at ASC")->fetchAll();
        $remaining_seconds = ($p['status']=='approved'&&$p['end_time']) ? strtotime($p['end_time'])-time() : 0;
        $custom_options = json_decode($p['options'],true);
        $sbadge = ['pending'=>['badge-amber','fa-clock','Pending'],'approved'=>['badge-emerald','fa-broadcast-tower','Voting Live'],'closed'=>['badge-gray','fa-lock','Closed'],'rejected'=>['badge-red','fa-ban','Rejected']];
        [$sc,$si,$st] = $sbadge[$p['status']] ?? ['badge-gray','fa-question','Unknown'];
    ?>
    <div class="vcard fade-in">
        <!-- Header -->
        <div style="padding:16px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:flex-start;gap:12px;background:var(--surface2)">
            <div style="display:flex;align-items:flex-start;gap:10px;min-width:0">
                <div class="comment-avatar" style="background:linear-gradient(135deg,var(--red),var(--orange));color:#fff;width:36px;height:36px;flex-shrink:0">
                    <?php if(!empty($p['profile_picture'])): ?><img src="<?= htmlspecialchars($p['profile_picture']) ?>"><?php else: ?><?= strtoupper(substr($p['name'],0,1)) ?><?php endif; ?>
                </div>
                <div style="min-width:0">
                    <div style="font-size:14px;font-weight:700;color:var(--text);line-height:1.3;margin-bottom:3px"><?= htmlspecialchars($p['title']) ?></div>
                    <div style="font-size:10px;color:var(--muted2);font-family:'Space Mono',monospace"><?= htmlspecialchars($p['name']) ?> · <?= date('d M Y', strtotime($p['created_at'])) ?></div>
                </div>
            </div>
            <div class="badge <?= $sc ?>" style="flex-shrink:0"><i class="fas <?= $si ?> <?= $p['status']==='approved'?'fa-beat':'' ?>"></i><?= $st ?></div>
        </div>

        <div style="padding:18px">
            <!-- Description -->
            <div style="font-size:13px;color:var(--muted);line-height:1.7;background:var(--surface2);border:1px solid var(--border);border-radius:12px;padding:14px;margin-bottom:16px"><?= nl2br(htmlspecialchars($p['description'])) ?></div>

            <!-- Countdown -->
            <?php if($p['status']==='approved'&&$p['end_time']): ?>
            <div style="background:rgba(230,57,70,.08);border:1px solid rgba(230,57,70,.25);border-radius:12px;padding:10px 14px;text-align:center;font-size:12px;font-weight:700;color:#fca5a5;margin-bottom:16px;display:flex;align-items:center;justify-content:center;gap:8px">
                <i class="fas fa-hourglass-half"></i>
                <span class="countdown-timer" data-remaining="<?= $remaining_seconds ?>">লোড হচ্ছে...</span>
            </div>
            <?php endif; ?>

            <!-- Results -->
            <?php if(in_array($p['status'],['closed','approved'])&&($p['status']==='closed'||!empty($my_vote))): ?>
            <div style="background:var(--surface2);border:1px solid var(--border);border-radius:12px;padding:14px;margin-bottom:16px">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                    <span style="font-size:10px;font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.1em;color:var(--muted)">ভোটের ফলাফল</span>
                    <div class="badge badge-blue">Total: <?= $total_votes ?></div>
                </div>
                <?php $options_to_show = (is_array($custom_options)&&count($custom_options)>0) ? $custom_options : ['yes'=>'হ্যাঁ (Yes)','no'=>'না (No)']; ?>
                <?php foreach($options_to_show as $ok=>$ov):
                    $key = is_int($ok) ? $ov : $ok;
                    $label = is_int($ok) ? $ov : ($ok==='yes'?'হ্যাঁ (Yes)':'না (No)');
                    $vc = $vote_counts[$key] ?? 0;
                    $vp = $total_votes>0 ? round(($vc/$total_votes)*100) : 0;
                    $is_mine = ($my_vote===$key);
                ?>
                <div class="pbar-wrap">
                    <div class="pbar-row" style="color:<?= $is_mine?'var(--blue)':'var(--muted)' ?>">
                        <span><?= htmlspecialchars($label) ?><?= $is_mine?' ✓':'' ?></span>
                        <span><?= $vp ?>% (<?= $vc ?>)</span>
                    </div>
                    <div class="prog-bar"><div class="prog-bar-fill" style="width:<?= $vp ?>%;background:<?= $is_mine?'var(--blue)':'rgba(255,255,255,.2)' ?>"></div></div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Already voted -->
            <?php if(!empty($my_vote)&&$p['status']==='approved'): ?>
            <div class="alert alert-ok" style="margin-bottom:16px"><i class="fas fa-check-double"></i>আপনার ভোট গৃহীত: <strong><?= htmlspecialchars($my_vote) ?></strong></div>
            <?php endif; ?>

            <!-- Voting form (open, not yet voted) -->
            <?php elseif($p['status']==='approved'&&empty($my_vote)): ?>
            <form method="POST" style="margin-bottom:16px">
                <input type="hidden" name="action" value="submit_vote">
                <input type="hidden" name="proposal_id" value="<?= $p['id'] ?>">
                <?php if(is_array($custom_options)&&count($custom_options)>0): foreach($custom_options as $opt): ?>
                <label class="vote-opt" onclick="this.classList.add('selected');this.querySelectorAll('~.vote-opt').forEach(e=>e.classList.remove('selected'));this.querySelectorAll('.vote-opt:not(.selected)').forEach(e=>e.classList.remove('selected'))">
                    <input type="radio" name="vote_type" value="<?= htmlspecialchars($opt) ?>" required>
                    <div class="opt-dot"></div>
                    <span style="font-size:13px;font-weight:600;color:var(--text)"><?= htmlspecialchars($opt) ?></span>
                </label>
                <?php endforeach; else: ?>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px">
                    <button type="submit" name="vote_type" value="yes" style="padding:12px;border-radius:12px;background:rgba(6,214,160,.1);border:1px solid rgba(6,214,160,.3);color:var(--emerald);font-size:13px;font-weight:700;cursor:pointer;font-family:'Sora',sans-serif;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:7px" onmouseover="this.style.background='rgba(6,214,160,.2)'" onmouseout="this.style.background='rgba(6,214,160,.1)'"><i class="fas fa-check-circle"></i>হ্যাঁ (Yes)</button>
                    <button type="submit" name="vote_type" value="no" style="padding:12px;border-radius:12px;background:rgba(252,165,165,.08);border:1px solid rgba(252,165,165,.25);color:#fca5a5;font-size:13px;font-weight:700;cursor:pointer;font-family:'Sora',sans-serif;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:7px" onmouseover="this.style.background='rgba(252,165,165,.16)'" onmouseout="this.style.background='rgba(252,165,165,.08)'"><i class="fas fa-times-circle"></i>না (No)</button>
                </div>
                <?php endif; ?>
                <?php if(is_array($custom_options)&&count($custom_options)>0): ?>
                <button type="submit" style="width:100%;padding:12px;border-radius:12px;background:linear-gradient(135deg,var(--blue),var(--indigo));border:none;color:#fff;font-size:13px;font-weight:700;cursor:pointer;font-family:'Sora',sans-serif;box-shadow:0 6px 18px rgba(67,97,238,.3);display:flex;align-items:center;justify-content:center;gap:7px"><i class="fas fa-paper-plane"></i>সাবমিট ভোট</button>
                <?php endif; ?>
            </form>
            <?php endif; ?>

            <!-- Comments -->
            <div style="border-top:1px solid var(--border);padding-top:14px">
                <button onclick="const c=document.getElementById('cmt_<?= $p['id'] ?>');c.style.display=c.style.display==='none'?'block':'none'"
                    style="font-size:11px;font-weight:700;color:var(--muted);font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.08em;background:var(--surface2);border:1px solid var(--border);border-radius:9px;padding:6px 12px;cursor:pointer;display:flex;align-items:center;gap:7px;transition:all .2s"
                    onmouseover="this.style.color='var(--text)'" onmouseout="this.style.color='var(--muted)'">
                    <i class="fas fa-comments" style="color:var(--blue)"></i>মতামত (<?= count($p_comments) ?>) <i class="fas fa-chevron-down" style="font-size:9px"></i>
                </button>
                <div id="cmt_<?= $p['id'] ?>" style="display:none;margin-top:12px">
                    <?php if(!empty($p_comments)): foreach($p_comments as $pc):
                        $ia = $pc['account_id']==0; ?>
                    <div class="comment-box" style="<?= $ia?'border-color:rgba(67,97,238,.25);background:rgba(67,97,238,.06)':'' ?>">
                        <div class="comment-avatar" style="background:<?= $ia?'linear-gradient(135deg,var(--blue),var(--indigo))':'var(--surface3)' ?>;color:#fff">
                            <?php if($ia): ?><i class="fas fa-user-shield" style="font-size:11px"></i>
                            <?php elseif(!empty($pc['profile_picture'])): ?><img src="<?= htmlspecialchars($pc['profile_picture']) ?>">
                            <?php else: ?><?= strtoupper(substr($pc['name'],0,1)) ?><?php endif; ?>
                        </div>
                        <div style="flex:1">
                            <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                                <span style="font-size:12px;font-weight:700;color:<?= $ia?'#a5b4fc':'var(--text)' ?>"><?= htmlspecialchars($pc['name']) ?><?= $ia?' 🛡':'' ?></span>
                                <span style="font-size:10px;color:var(--muted2);font-family:'Space Mono',monospace"><?= date('d M, H:i',strtotime($pc['created_at'])) ?></span>
                            </div>
                            <p style="font-size:12px;color:var(--muted);line-height:1.6"><?= nl2br(htmlspecialchars($pc['comment'])) ?></p>
                        </div>
                    </div>
                    <?php endforeach; else: ?>
                    <div style="text-align:center;padding:20px;color:var(--muted2);font-size:12px">এখনো কোনো মতামত নেই।</div>
                    <?php endif; ?>

                    <?php if(in_array($p['status'],['approved','pending'])): ?>
                    <form method="POST" style="display:flex;gap:8px;margin-top:8px">
                        <input type="hidden" name="action" value="submit_comment">
                        <input type="hidden" name="proposal_id" value="<?= $p['id'] ?>">
                        <input type="text" name="comment_text" class="form-input" style="flex:1;padding:9px 12px;font-size:12px" placeholder="মতামত লিখুন..." required>
                        <button type="submit" style="width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,var(--blue),var(--indigo));border:none;color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:0 4px 14px rgba(67,97,238,.3)"><i class="fas fa-paper-plane" style="font-size:12px"></i></button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Delete own proposal -->
            <?php if($p['account_id']==$user_id&&$p['status']==='pending'): ?>
            <form method="POST" style="margin-top:12px;text-align:right" onsubmit="return confirm('প্রস্তাবনাটি ডিলিট করবেন?')">
                <input type="hidden" name="action" value="delete_proposal">
                <input type="hidden" name="proposal_id" value="<?= $p['id'] ?>">
                <button type="submit" style="display:inline-flex;align-items:center;gap:5px;padding:6px 12px;border-radius:9px;background:rgba(230,57,70,.08);border:1px solid rgba(230,57,70,.25);color:#fca5a5;font-size:11px;font-weight:700;cursor:pointer;font-family:'Sora',sans-serif;transition:all .2s" onmouseover="this.style.background='rgba(230,57,70,.15)'" onmouseout="this.style.background='rgba(230,57,70,.08)'"><i class="fas fa-trash-alt"></i>ডিলিট করুন</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; endif; ?>
    </div>

<?php endif; ?>

</main>

<!-- New Proposal Modal -->
<div id="newPropModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);backdrop-filter:blur(10px);z-index:300;align-items:center;justify-content:center;padding:16px">
    <div style="background:var(--surface);border:1px solid var(--border2);border-radius:24px;width:100%;max-width:520px;max-height:88vh;overflow:hidden;display:flex;flex-direction:column;animation:modalIn .3s cubic-bezier(.16,1,.3,1)">
        <div style="padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;flex-shrink:0">
            <div style="font-size:15px;font-weight:800;display:flex;align-items:center;gap:8px"><i class="fas fa-pen-nib" style="color:var(--blue)"></i>নতুন প্রস্তাবনা</div>
            <button onclick="document.getElementById('newPropModal').style.display='none'" style="width:30px;height:30px;border-radius:8px;background:var(--surface2);border:1px solid var(--border);color:var(--muted);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:12px"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" style="padding:22px;overflow-y:auto;flex:1">
            <input type="hidden" name="action" value="submit_proposal">
            <div class="form-group">
                <label class="form-label">প্রস্তাবনার শিরোনাম <span style="color:var(--red)">*</span></label>
                <input type="text" name="title" class="form-input" placeholder="কী বিষয়ে প্রস্তাব দিতে চান..." required>
            </div>
            <div class="form-group">
                <label class="form-label">বিস্তারিত বিবরণ <span style="color:var(--red)">*</span></label>
                <textarea name="description" rows="4" class="form-input" style="resize:vertical;min-height:90px" placeholder="বিস্তারিত লিখুন..." required></textarea>
            </div>
            <div style="background:rgba(67,97,238,.06);border:1px solid rgba(67,97,238,.2);border-radius:12px;padding:16px;margin-bottom:18px">
                <div style="font-size:11px;font-weight:700;color:#a5b4fc;font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.1em;margin-bottom:8px"><i class="fas fa-list-ul" style="margin-right:6px"></i>কাস্টম ভোটিং অপশন (ঐচ্ছিক)</div>
                <p style="font-size:11px;color:var(--muted);margin-bottom:10px;line-height:1.6">ফাঁকা রাখলে হ্যাঁ/না অপশন তৈরি হবে।</p>
                <div id="optContainer" style="display:grid;gap:8px">
                    <input type="text" name="options[]" class="form-input" style="font-size:12px;padding:9px 12px" placeholder="অপশন ১ (যেমন: Project A)">
                    <input type="text" name="options[]" class="form-input" style="font-size:12px;padding:9px 12px" placeholder="অপশন ২ (যেমন: Project B)">
                </div>
                <button type="button" onclick="addOpt()" style="margin-top:10px;display:inline-flex;align-items:center;gap:5px;padding:6px 12px;border-radius:9px;background:var(--surface2);border:1px solid var(--border);color:var(--muted);font-size:11px;font-weight:700;cursor:pointer;font-family:'Sora',sans-serif;transition:all .2s" onmouseover="this.style.color='var(--text)'" onmouseout="this.style.color='var(--muted)'"><i class="fas fa-plus"></i>আরও অপশন</button>
            </div>
            <div style="display:flex;gap:10px">
                <button type="button" onclick="document.getElementById('newPropModal').style.display='none'" style="flex:1;padding:12px;border-radius:12px;background:var(--surface2);border:1px solid var(--border);color:var(--muted);font-size:13px;font-weight:700;cursor:pointer;font-family:'Sora',sans-serif">বাতিল</button>
                <button type="submit" style="flex:1;padding:12px;border-radius:12px;background:linear-gradient(135deg,var(--blue),var(--indigo));border:none;color:#fff;font-size:13px;font-weight:700;cursor:pointer;font-family:'Sora',sans-serif;box-shadow:0 6px 18px rgba(67,97,238,.3);display:flex;align-items:center;justify-content:center;gap:7px"><i class="fas fa-paper-plane"></i>সাবমিট</button>
            </div>
        </form>
    </div>
</div>

<script>
window.addEventListener('scroll',()=>document.getElementById('enav').classList.toggle('shadow',window.scrollY>8));
document.getElementById('newPropModal').addEventListener('click',function(e){if(e.target===this)this.style.display='none';});

function addOpt(){
    const c=document.getElementById('optContainer');
    const n=c.querySelectorAll('input').length+1;
    const i=document.createElement('input');
    i.type='text';i.name='options[]';i.className='form-input';i.style.cssText='font-size:12px;padding:9px 12px';i.placeholder='অপশন '+n;
    c.appendChild(i);
}

// Vote option selection
document.querySelectorAll('.vote-opt').forEach(opt=>{
    opt.addEventListener('click',function(){
        const form=this.closest('form');
        form.querySelectorAll('.vote-opt').forEach(o=>o.classList.remove('selected'));
        this.classList.add('selected');
        const radio=this.querySelector('input[type=radio]');
        if(radio)radio.checked=true;
    });
});

// Countdown
setInterval(()=>{
    document.querySelectorAll('.countdown-timer').forEach(el=>{
        let r=parseInt(el.dataset.remaining);
        if(r<=0){el.textContent='Voting Closed';if(!el.dataset.reloaded){el.dataset.reloaded='true';setTimeout(()=>location.reload(),2000);}return;}
        el.dataset.remaining=r-1;
        const d=Math.floor(r/86400),h=Math.floor((r%86400)/3600),m=Math.floor((r%3600)/60),s=r%60;
        el.innerHTML=`${d>0?d+'d ':''}<b>${h}h</b> : <b>${m}m</b> : <b>${s}s</b> Left`;
    });
},1000);
</script>
<script src="theme.js"></script>
</body>
</html>