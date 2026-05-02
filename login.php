<?php
// ২৪ ঘণ্টার জন্য সেশন ও কুকি সেট করা
ini_set('session.cookie_lifetime', 86400);
ini_set('session.gc_maxlifetime', 86400);
session_set_cookie_params(86400);
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if(isset($_SESSION['user_logged_in'])) { header("Location: index.php"); exit; }

// ডাটাবেসে ফোন নাম্বারের কলাম না থাকলে অ্যাড করে নেওয়া (OTP এর জন্য)
try { $pdo->exec("ALTER TABLE shareholder_accounts ADD COLUMN IF NOT EXISTS phone VARCHAR(20) NULL AFTER username"); } catch(PDOException $e) {}

$error = ''; $success = '';
$step = isset($_GET['step']) && $_GET['step'] == 'forgot' ? 'forgot' : 'login';

// ==========================================
// SMS.net.bd Gateway Integration Function
// ==========================================
function sendSMS($phone, $otp) {
    $api_key = $_ENV['SMS_API_KEY'];
    $msg = "Sodai Lagbe ERP: Your OTP for password reset is $otp. Do not share this with anyone.";
    
    $curl = curl_init();
    curl_setopt_array($curl, array(
      CURLOPT_URL => 'https://api.sms.net.bd/sendsms',
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CUSTOMREQUEST => 'POST',
      CURLOPT_POSTFIELDS => array('api_key' => $api_key, 'msg' => $msg, 'to' => $phone),
    ));
    $response = curl_exec($curl);
    curl_close($curl);
    
    return true; 
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST['action'] ?? 'login';

    // 1. লগইন প্রসেস (ইউজারনেম অথবা মোবাইল নম্বর দিয়ে)
    if ($action === 'login') {
        $login_id = trim($_POST['login_id']); // এটি ইউজারনেম বা ফোন নম্বর হতে পারে
        $password = $_POST['password'];

        // কোয়েরি আপডেট করা হয়েছে যাতে username অথবা phone যেকোনো একটি মিললেই লগইন হয়
        $stmt = $pdo->prepare("SELECT * FROM shareholder_accounts WHERE (username = ? OR phone = ?) AND password = ?");
        $stmt->execute([$login_id, $login_id, $password]);
        $user = $stmt->fetch();

        if ($user) {
            $_SESSION['user_logged_in'] = true;
            $_SESSION['user_account_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            header("Location: index.php"); exit;
        } else {
            $error = "ভুল ইউজারনেম/মোবাইল নম্বর বা পাসওয়ার্ড!";
            $step = 'login';
        }
    } 
    // 2. ফরগেট পাসওয়ার্ড রিকোয়েস্ট (OTP পাঠানো)
    elseif ($action === 'forgot_password') {
        $phone = trim($_POST['reset_phone']);
        $stmt = $pdo->prepare("SELECT id, phone FROM shareholder_accounts WHERE phone = ? LIMIT 1");
        $stmt->execute([$phone]);
        $user = $stmt->fetch();

        if ($user && !empty($user['phone'])) {
            $otp = rand(100000, 999999);
            $_SESSION['reset_user_id'] = $user['id'];
            $_SESSION['reset_otp'] = $otp;
            
            // সেন্ড SMS ফাংশন কল
            sendSMS($user['phone'], $otp);
            
            $success = "আপনার ফোন নাম্বারে (শেষ ৩ ডিজিট: ".substr($user['phone'], -3).") একটি OTP পাঠানো হয়েছে।";
            $step = 'verify_otp';
        } else {
            $error = "এই মোবাইল নাম্বারের সাথে কোনো অ্যাকাউন্ট যুক্ত নেই।";
            $step = 'forgot';
        }
    }
    // 3. OTP যাচাই করা
    elseif ($action === 'verify_otp') {
        $entered_otp = trim($_POST['otp']);
        if (isset($_SESSION['reset_otp']) && $entered_otp == $_SESSION['reset_otp']) {
            $success = "OTP যাচাই সফল হয়েছে। নিচে নতুন পাসওয়ার্ড সেট করুন।";
            $step = 'reset_password';
        } else {
            $error = "ভুল OTP প্রদান করেছেন!";
            $step = 'verify_otp';
        }
    }
    // 4. নতুন পাসওয়ার্ড সেট করা
    elseif ($action === 'reset_password') {
        $new_pass = $_POST['new_password'];
        $confirm_pass = $_POST['confirm_password'];
        
        if ($new_pass === $confirm_pass && isset($_SESSION['reset_user_id'])) {
            $stmt = $pdo->prepare("UPDATE shareholder_accounts SET password = ? WHERE id = ?");
            if($stmt->execute([$new_pass, $_SESSION['reset_user_id']])) {
                unset($_SESSION['reset_user_id'], $_SESSION['reset_otp']);
                $success = "পাসওয়ার্ড সফলভাবে পরিবর্তন করা হয়েছে! এখন লগইন করুন।";
                $step = 'login';
            }
        } else {
            $error = "পাসওয়ার্ড দুটি মিলেনি! পুনরায় চেষ্টা করুন।";
            $step = 'reset_password';
        }
    }
}
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
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0,user-scalable=no">
<title>Login — Sodai Lagbe ERP</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--red:#e63946;--orange:#f4845f;--blue:#4361ee;--emerald:#06d6a0;--amber:#fbbf24;--dark:#0d1117;--card:rgba(22,27,34,.85);--border:rgba(255,255,255,.08);--muted:#8b949e}
body{font-family:'Sora',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;background:var(--dark);overflow:hidden;position:relative}
body::before{content:'';position:fixed;inset:0;background:radial-gradient(ellipse at 20% 10%,rgba(230,57,70,.18) 0%,transparent 45%),radial-gradient(ellipse at 80% 80%,rgba(67,97,238,.15) 0%,transparent 45%);pointer-events:none}
.grid-bg{position:fixed;inset:0;background-image:linear-gradient(rgba(255,255,255,.03) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.03) 1px,transparent 1px);background-size:44px 44px;pointer-events:none}
.card{background:var(--card);backdrop-filter:blur(24px);border:1px solid var(--border);border-radius:28px;padding:36px 32px;width:100%;max-width:400px;position:relative;box-shadow:0 32px 80px rgba(0,0,0,.5),0 0 0 1px rgba(255,255,255,.04) inset;animation:slideUp .45s cubic-bezier(.16,1,.3,1) forwards}
@media(max-width:440px){.card{padding:28px 22px;border-radius:24px}}
@keyframes slideUp{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:translateY(0)}}
.logo-wrap{width:60px;height:60px;border-radius:18px;background:linear-gradient(135deg,var(--red),var(--orange));display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;margin:0 auto 20px;box-shadow:0 12px 30px rgba(230,57,70,.4)}
.brand{font-size:22px;font-weight:800;letter-spacing:-.5px;text-align:center;color:#e6edf3}
.sub{font-size:9px;font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.2em;color:var(--muted);text-align:center;margin-top:4px;margin-bottom:28px}
.lbl{font-size:10px;font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.12em;color:var(--muted);display:block;margin-bottom:7px}
.inp-wrap{position:relative;margin-bottom:16px}
.inp-wrap i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:13px}
input.inp{width:100%;background:rgba(0,0,0,.25);border:1px solid var(--border);border-radius:14px;padding:13px 16px 13px 40px;color:#e6edf3;font-size:14px;font-family:'Sora',sans-serif;outline:none;transition:border-color .2s,box-shadow .2s}
input.inp::placeholder{color:rgba(255,255,255,.2)}
input.inp:focus{border-color:rgba(67,97,238,.6);box-shadow:0 0 0 4px rgba(67,97,238,.12),0 0 0 1px rgba(67,97,238,.4)}
input.inp.center{text-align:center;padding-left:16px;letter-spacing:.4em;font-family:'Space Mono',monospace;font-size:18px;font-weight:700}
.forgot-link{text-align:right;margin-top:-8px;margin-bottom:18px}
.forgot-link a{font-size:11px;font-weight:600;color:var(--blue);text-decoration:none;opacity:.8;transition:opacity .2s}
.forgot-link a:hover{opacity:1}
.btn{width:100%;padding:14px;border-radius:14px;font-size:14px;font-weight:700;font-family:'Sora',sans-serif;cursor:pointer;border:none;transition:transform .2s,box-shadow .2s;display:flex;align-items:center;justify-content:center;gap:8px;margin-top:4px}
.btn:active{transform:scale(.98)}
.btn-blue{background:linear-gradient(135deg,var(--blue),#3b5ee8);color:#fff;box-shadow:0 8px 24px rgba(67,97,238,.4)}
.btn-blue:hover{box-shadow:0 12px 32px rgba(67,97,238,.55)}
.btn-red{background:linear-gradient(135deg,var(--red),var(--orange));color:#fff;box-shadow:0 8px 24px rgba(230,57,70,.4)}
.btn-emerald{background:linear-gradient(135deg,var(--emerald),#0d9488);color:#fff;box-shadow:0 8px 24px rgba(6,214,160,.35)}
.btn-amber{background:linear-gradient(135deg,#f59e0b,var(--orange));color:#fff;box-shadow:0 8px 24px rgba(245,158,11,.35)}
.back-link{display:block;text-align:center;font-size:12px;font-weight:600;color:var(--muted);text-decoration:none;margin-top:14px;transition:color .2s}
.back-link:hover{color:#e6edf3}
.info-text{font-size:12px;color:var(--muted);text-align:center;line-height:1.7;margin-bottom:20px}
.alert{padding:12px 16px;border-radius:13px;font-size:12px;font-weight:600;margin-bottom:20px;display:flex;align-items:center;gap:10px;line-height:1.5}
.alert-err{background:rgba(230,57,70,.1);border:1px solid rgba(230,57,70,.25);color:#fca5a5}
.alert-ok{background:rgba(6,214,160,.1);border:1px solid rgba(6,214,160,.25);color:#6ee7b7}
.divider{height:1px;background:var(--border);margin:20px 0}
.step-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:999px;border:1px solid var(--border);background:rgba(255,255,255,.04);font-size:10px;font-family:'Space Mono',monospace;color:var(--muted);text-transform:uppercase;letter-spacing:.1em;margin-bottom:20px}
</style>
</head>
<body>
<div class="grid-bg"></div>
<div class="card">

  <!-- Brand -->
  <div class="logo-wrap"><i class="fas fa-store-alt"></i></div>
  <div class="brand">Sodai Lagbe</div>
  <div class="sub">Shareholder ERP Portal</div>

  <?php if($error): ?>
    <div class="alert alert-err"><i class="fas fa-exclamation-circle text-base flex-shrink-0"></i><span><?= htmlspecialchars($error) ?></span></div>
  <?php endif; ?>
  <?php if($success): ?>
    <div class="alert alert-ok"><i class="fas fa-check-circle text-base flex-shrink-0"></i><span><?= htmlspecialchars($success) ?></span></div>
  <?php endif; ?>

  <?php if($step === 'login'): ?>
  <form method="POST">
    <input type="hidden" name="action" value="login">
    <label class="lbl">Username or Mobile</label>
    <div class="inp-wrap"><i class="fas fa-user"></i><input type="text" name="login_id" class="inp" placeholder="Enter username or mobile" required></div>
    <label class="lbl">Password</label>
    <div class="inp-wrap"><i class="fas fa-lock"></i><input type="password" name="password" class="inp" placeholder="••••••••" required></div>
    <div class="forgot-link"><a href="login.php?step=forgot"><i class="fas fa-key" style="margin-right:4px;font-size:9px"></i>Forgot Password?</a></div>
    <button type="submit" class="btn btn-red"><i class="fas fa-sign-in-alt"></i> লগইন করুন</button>
  </form>

  <?php elseif($step === 'forgot'): ?>
  <div class="step-badge"><i class="fas fa-key"></i> Password Recovery</div>
  <p class="info-text">আপনার অ্যাকাউন্টে যুক্ত মোবাইল নম্বরটি লিখুন। একটি OTP পাঠানো হবে।</p>
  <form method="POST">
    <input type="hidden" name="action" value="forgot_password">
    <label class="lbl">Mobile Number</label>
    <div class="inp-wrap"><i class="fas fa-mobile-alt"></i><input type="tel" name="reset_phone" class="inp" placeholder="01XXXXXXXXX" required></div>
    <button type="submit" class="btn btn-amber"><i class="fas fa-paper-plane"></i> OTP পাঠান</button>
    <a href="login.php" class="back-link"><i class="fas fa-arrow-left" style="margin-right:5px;font-size:10px"></i>লগইনে ফিরুন</a>
  </form>

  <?php elseif($step === 'verify_otp'): ?>
  <div class="step-badge"><i class="fas fa-shield-alt"></i> OTP Verify</div>
  <p class="info-text">আপনার মোবাইলে পাঠানো ৬ সংখ্যার কোডটি লিখুন।</p>
  <form method="POST">
    <input type="hidden" name="action" value="verify_otp">
    <label class="lbl">Enter OTP Code</label>
    <div class="inp-wrap" style="margin-bottom:20px"><input type="number" name="otp" class="inp center" placeholder="— — — — — —" required></div>
    <button type="submit" class="btn btn-emerald"><i class="fas fa-check-double"></i> যাচাই করুন</button>
    <a href="login.php" class="back-link">বাতিল করুন</a>
  </form>

  <?php elseif($step === 'reset_password'): ?>
  <div class="step-badge"><i class="fas fa-lock-open"></i> New Password</div>
  <form method="POST">
    <input type="hidden" name="action" value="reset_password">
    <label class="lbl">New Password</label>
    <div class="inp-wrap"><i class="fas fa-lock"></i><input type="password" name="new_password" class="inp" placeholder="••••••••" required></div>
    <label class="lbl">Confirm Password</label>
    <div class="inp-wrap"><i class="fas fa-check-circle"></i><input type="password" name="confirm_password" class="inp" placeholder="••••••••" required></div>
    <button type="submit" class="btn btn-blue" style="margin-top:16px"><i class="fas fa-save"></i> পাসওয়ার্ড সেভ করুন</button>
  </form>
  <?php endif; ?>

</div>
</body>
</html>