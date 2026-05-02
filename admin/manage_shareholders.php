<?php
// ২৪ ঘণ্টার জন্য সেশন ও কুকি সেট করা
ini_set('session.cookie_lifetime', 86400);
ini_set('session.gc_maxlifetime', 86400);
session_set_cookie_params(86400);
session_start();

// বাংলাদেশের টাইমজোন সেট করা
date_default_timezone_set('Asia/Dhaka');

if(!isset($_SESSION['admin_logged_in'])) { header("Location: login.php"); exit; }
if(isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'staff') { header("Location: add_entry.php"); exit; }
require_once 'db.php';

// OTP এবং Profile Picture এর জন্য কলাম তৈরি (যদি না থাকে)
try { 
    $pdo->exec("ALTER TABLE shareholder_accounts ADD COLUMN IF NOT EXISTS phone VARCHAR(20) NULL AFTER username"); 
    $chk_pic = $pdo->query("SHOW COLUMNS FROM shareholder_accounts LIKE 'profile_picture'");
    if($chk_pic && $chk_pic->rowCount() == 0) {
        $pdo->exec("ALTER TABLE shareholder_accounts ADD COLUMN profile_picture VARCHAR(255) NULL AFTER phone");
    }
} catch(PDOException $e) {}

$message = ''; $error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // স্লট সেটিং আপডেট লজিক
    if (isset($_POST['action']) && $_POST['action'] == 'update_slots') {
        $slots = (int)$_POST['total_slots'];
        $chk = $pdo->query("SELECT COUNT(*) FROM system_settings WHERE setting_name = 'total_share_slots'")->fetchColumn();
        if ($chk > 0) {
            $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_name = 'total_share_slots'")->execute([$slots]);
        } else {
            $pdo->prepare("INSERT INTO system_settings (setting_name, setting_value) VALUES ('total_share_slots', ?)")->execute([$slots]);
        }
        $message = "কোম্পানির মোট স্লট সেটিং আপডেট হয়েছে!";
    }
    elseif (isset($_POST['action']) && $_POST['action'] == 'edit_account') {
        $acc_id = $_POST['account_id'];
        $acc_phone = trim($_POST['acc_phone']);
        
        // আগের ছবি ফেচ করা
        $stmt_old_pic = $pdo->prepare("SELECT profile_picture FROM shareholder_accounts WHERE id = ?");
        $stmt_old_pic->execute([$acc_id]);
        $old_pic = $stmt_old_pic->fetchColumn();
        $profile_pic_path = $old_pic;
        
        // নতুন ছবি আপলোডের লজিক
        if (isset($_FILES['acc_profile_pic']) && $_FILES['acc_profile_pic']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = '../uploads/profiles/';
            if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }
            
            $fileName = time() . '_' . preg_replace("/[^a-zA-Z0-9.]/", "", basename($_FILES['acc_profile_pic']['name']));
            $targetFilePath = $uploadDir . $fileName;
            
            if(move_uploaded_file($_FILES['acc_profile_pic']['tmp_name'], $targetFilePath)){
                // ডাটাবেসে সেভ করার সময় 'uploads/profiles/...' হিসেবে সেভ হবে
                $profile_pic_path = 'uploads/profiles/' . $fileName; 
            }
        }
        
        if (!empty($_POST['acc_password'])) {
            $stmt = $pdo->prepare("UPDATE shareholder_accounts SET name=?, username=?, phone=?, profile_picture=?, password=? WHERE id=?");
            $success = $stmt->execute([$_POST['acc_name'], $_POST['acc_username'], $acc_phone, $profile_pic_path, $_POST['acc_password'], $acc_id]);
        } else {
            $stmt = $pdo->prepare("UPDATE shareholder_accounts SET name=?, username=?, phone=?, profile_picture=? WHERE id=?");
            $success = $stmt->execute([$_POST['acc_name'], $_POST['acc_username'], $acc_phone, $profile_pic_path, $acc_id]);
        }
        if($success) {
            $pdo->prepare("UPDATE shareholders SET name=? WHERE account_id=?")->execute([$_POST['acc_name'], $acc_id]);
            $message = "অ্যাকাউন্ট আপডেট হয়েছে!";
        }
    }
    elseif (isset($_POST['action']) && $_POST['action'] == 'edit_share') {
        $id = $_POST['share_id'];
        $num_shares = isset($_POST['number_of_shares']) ? (int)$_POST['number_of_shares'] : 0;
        
        $stmt = $pdo->prepare("UPDATE shareholders SET number_of_shares=?, investment_credit=?, share_type=?, assigned_project_id=?, deadline_date=?, slot_numbers=? WHERE id=?");
        if($stmt->execute([$num_shares, $_POST['investment'], $_POST['share_type'], empty($_POST['project_id']) ? null : $_POST['project_id'], $_POST['deadline_date'], $_POST['slot_numbers'], $id])) {
            $message = "শেয়ার আপডেট সফল হয়েছে!";
        }
    }
    elseif (isset($_POST['action']) && $_POST['action'] == 'delete_share') {
        $pin = $_POST['secret_pin'];
        $stmt = $pdo->prepare("SELECT secret_pin FROM admins WHERE username = ?");
        $stmt->execute([$_SESSION['admin_username']]);
        $admin = $stmt->fetch();
        if ($admin && $admin['secret_pin'] === $pin) {
            $pdo->prepare("DELETE FROM shareholders WHERE id = ?")->execute([$_POST['share_id']]);
            $message = "শেয়ার ডিলিট সফল হয়েছে!";
        } else { $error = "ভুল পিন!"; }
    }
}

// স্লট সেটিং ডাটা
$slot_stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_name = 'total_share_slots'");
$total_slots_setting = $slot_stmt->fetchColumn() ?: 100;

// কোম্পানির মোট শেয়ার (সব প্রজেক্ট মিলিয়ে)
$total_shares_stmt = $pdo->query("SELECT SUM(number_of_shares) FROM shareholders");
$total_company_shares = (float)$total_shares_stmt->fetchColumn() ?: 0;

// গ্লোবাল প্রফিট (General Fund Profit)
$global_profit_stmt = $pdo->query("SELECT SUM(amount) FROM financials WHERE type='profit' AND status='approved' AND project_id IS NULL");
$global_profit = (float)$global_profit_stmt->fetchColumn() ?: 0;

// জেনারেল ফান্ডের মোট ইনভেস্টমেন্ট
$general_fund_inv_stmt = $pdo->query("SELECT SUM(investment_credit) FROM shareholders WHERE assigned_project_id IS NULL");
$general_fund_inv = (float)$general_fund_inv_stmt->fetchColumn() ?: 0;

// মাদার প্রজেক্ট ফেচ করা
$sys_settings_stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_name = 'mother_project_id'");
$mother_project_id = (int)($sys_settings_stmt->fetchColumn() ?: 0);

// =========================================================================
// প্রজেক্ট স্পেসিফিক ডাটা এবং মাদার/চাইল্ড লজিক ক্যালকুলেশন
// =========================================================================
$projects_raw = $pdo->query("
    SELECT p.id, p.project_name, p.dist_type, p.mother_commission_pct,
           (SELECT COALESCE(SUM(amount), 0) FROM financials WHERE type='profit' AND status='approved' AND project_id = p.id) as gross_profit,
           (SELECT COALESCE(SUM(number_of_shares), 0) FROM shareholders WHERE assigned_project_id = p.id) as total_shares,
           (SELECT COALESCE(SUM(investment_credit), 0) FROM shareholders WHERE assigned_project_id = p.id) as total_inv
    FROM projects p
")->fetchAll(PDO::FETCH_ASSOC);

$total_mother_commission = 0;
$mother_commission_sources = []; 
$projects_data = [];

// Step 1: Calculate Child Deductions & Mother's Commission Fund
foreach ($projects_raw as $p) {
    $gross_profit = (float)$p['gross_profit'];
    $net_profit = $gross_profit; // Default
    
    // If it's a child project and has commission
    if ($mother_project_id > 0 && $p['id'] != $mother_project_id && $p['mother_commission_pct'] > 0) {
        $commission_amount = $gross_profit * ($p['mother_commission_pct'] / 100);
        if($commission_amount > 0) {
            $total_mother_commission += $commission_amount;
            $mother_commission_sources[] = [
                'name' => $p['project_name'],
                'amount' => $commission_amount
            ];
        }
        $net_profit = $gross_profit - $commission_amount; // Child's actual distributable profit
    }
    
    $p['net_distributable_profit'] = $net_profit;
    $projects_data[$p['id']] = $p;
}

// Step 2: Add collected commission to Mother Project's distributable profit
if ($mother_project_id > 0 && isset($projects_data[$mother_project_id])) {
    $projects_data[$mother_project_id]['net_distributable_profit'] += $total_mother_commission;
}
// =========================================================================

$sql = "SELECT s.*, a.username, a.phone, a.profile_picture, a.password as acc_pass, a.name as acc_name, p.project_name 
        FROM shareholders s LEFT JOIN shareholder_accounts a ON s.account_id = a.id 
        LEFT JOIN projects p ON s.assigned_project_id = p.id ORDER BY a.name ASC, s.id DESC";
$all_shares = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
$all_projects = $pdo->query("SELECT * FROM projects")->fetchAll();

$grouped = [];
foreach($all_shares as $sh) {
    if ($sh['account_id']) {
        $key = 'acc_' . $sh['account_id'];
        $grouped[$key]['is_account'] = true;
        $grouped[$key]['account_id'] = $sh['account_id'];
        $grouped[$key]['name'] = $sh['acc_name'];
        $grouped[$key]['username'] = $sh['username'];
        $grouped[$key]['phone'] = $sh['phone'];
        $grouped[$key]['profile_picture'] = $sh['profile_picture'];
    } else {
        $key = 'old_' . $sh['name'];
        $grouped[$key]['is_account'] = false;
        $grouped[$key]['account_id'] = null;
        $grouped[$key]['name'] = $sh['name'];
        $grouped[$key]['username'] = 'অ্যাকাউন্ট নেই';
        $grouped[$key]['phone'] = '';
        $grouped[$key]['profile_picture'] = '';
    }
    $grouped[$key]['shares'][] = $sh;
}

// ওয়েবসাইট লোগো ও ফেভিকন ফেচ করা 
$site_settings_stmt = $pdo->query("SELECT setting_name, setting_value FROM system_settings WHERE setting_name IN ('site_logo', 'site_favicon')");
$site_settings = $site_settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$site_logo = $site_settings['site_logo'] ?? '';
$site_favicon = $site_settings['site_favicon'] ?? '';

?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Shareholders - Sodai Lagbe ERP</title>
    
    <?php if(!empty($site_favicon)): ?>
        <link rel="icon" href="../<?= htmlspecialchars($site_favicon) ?>">
    <?php else: ?>
        <link rel="icon" href="favicon.ico">
    <?php endif; ?>

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap');
        
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; -webkit-tap-highlight-color: transparent;}
        
        .glass-header {
            background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(226,232,240,0.8);
        }
        
        .app-card {
            background: #ffffff; border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.03), 0 1px 3px rgba(0,0,0,0.02);
            border: 1px solid rgba(226, 232, 240, 0.8);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .custom-scrollbar::-webkit-scrollbar { width: 4px; height: 4px; } 
        .custom-scrollbar::-webkit-scrollbar-thumb { background-color: #cbd5e1; border-radius: 10px; } 

        .bottom-nav {
            position: fixed; bottom: 0; left: 0; right: 0;
            background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px);
            border-top: 1px solid rgba(0,0,0,0.05); padding-bottom: env(safe-area-inset-bottom);
            z-index: 50; display: flex; justify-content: space-around;
        }
        .nav-item {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            padding: 10px 0; color: #64748b; font-size: 10px; font-weight: 700; width: 100%; transition: all 0.2s;
        }
        .nav-item.active { color: #2563eb; }
        .nav-item i { font-size: 18px; margin-bottom: 3px; transition: transform 0.2s;}
        .nav-item.active i { transform: translateY(-2px); }
        
        @media (min-width: 768px) { .bottom-nav { display: none; } }
        .main-content { padding-bottom: 80px; }
        @media (min-width: 768px) { .main-content { padding-bottom: 30px; } }
        
        @keyframes fadeInFast { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fade-in { animation: fadeInFast 0.4s ease-out forwards; }

        /* Print Specific Styles */
        @media print {
            @page { size: A4 portrait; margin: 15mm; }
            html, body, .flex-1, main, .md\:pl-64, .h-screen, .min-h-screen { 
                height: auto !important; min-height: auto !important; overflow: visible !important; 
                display: block !important; background-color: #ffffff !important; color: #000 !important; 
                margin: 0 !important; padding: 0 !important;
            }
            #sidebar, #sidebar-overlay, header, .bottom-nav, .filter-section, .app-card, .main-header, #slotSettingModal, #editAccModal, #editShareModal, #deleteShareModal { display: none !important; }
            
            .print-deed-wrapper { display: block !important; width: 100%; position: static !important; font-family: 'Times New Roman', serif, 'Plus Jakarta Sans'; color: #1e293b; }
            .deed-header { text-align: center; border-bottom: 3px solid #1e3a8a; padding-bottom: 20px; margin-bottom: 30px; position: relative; }
            .deed-header h1 { font-size: 32pt; font-weight: 900; margin: 0; color: #1e3a8a !important; text-transform: uppercase; letter-spacing: 2px; }
            .deed-header h2 { font-size: 16pt; font-weight: bold; margin: 10px 0 0 0; color: #0f172a !important; text-transform: uppercase; letter-spacing: 1px; }
            .deed-header p { font-size: 10pt; color: #64748b !important; margin-top: 5px; font-weight: bold; text-transform: uppercase; letter-spacing: 2px; }
            
            .deed-content { font-size: 12pt; line-height: 1.8; margin-bottom: 30px; text-align: justify; }
            .deed-content p { margin-bottom: 15px; }
            .deed-strong { font-weight: 900; color: #0f172a !important; font-size: 13pt; }
            
            .deed-table { width: 100%; border-collapse: collapse; margin: 30px 0; font-size: 11pt; }
            .deed-table th, .deed-table td { border: 1px solid #94a3b8 !important; padding: 12px; vertical-align: middle; }
            .deed-table th { background-color: #f1f5f9 !important; font-weight: 800; color: #1e293b !important; text-transform: uppercase; font-size: 10pt; text-align: center; -webkit-print-color-adjust: exact;}
            
            .deed-signatures { display: flex; justify-content: space-between; margin-top: 80px; padding-top: 20px; }
            .deed-sig-box { text-align: center; width: 30%; }
            .deed-sig-line { border-top: 1px solid #1e293b; margin-bottom: 8px; padding-top: 8px; }
            .deed-sig-box p { margin: 0; font-size: 10pt; font-weight: bold; color: #334155 !important; }
            
            .deed-watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-45deg); font-size: 80pt; color: rgba(203, 213, 225, 0.15) !important; z-index: -1; white-space: nowrap; pointer-events: none; }
            .deed-footer { text-align: center; margin-top: 40px; font-size: 9pt; color: #94a3b8 !important; font-style: italic; border-top: 1px dashed #cbd5e1; padding-top: 10px;}
        }
        .print-deed-wrapper { display: none; }
    </style>
</head>
<body class="text-slate-800 antialiased flex h-screen overflow-hidden">

    <div id="sidebar-overlay" class="fixed inset-0 bg-slate-900/60 z-40 hidden md:hidden backdrop-blur-sm transition-opacity" onclick="toggleSidebar()"></div>

    <aside id="sidebar" class="fixed inset-y-0 left-0 bg-slate-900 text-white w-64 flex flex-col transition-transform transform -translate-x-full md:translate-x-0 z-50 shadow-2xl md:shadow-none h-full">
        <div class="flex items-center justify-center h-20 border-b border-slate-800 bg-slate-950 shrink-0 px-4">
            <h1 class="text-2xl font-black text-emerald-400 tracking-tight flex items-center justify-center w-full">
                <?php if(!empty($site_logo)): ?>
                    <img src="../<?= htmlspecialchars($site_logo) ?>" alt="Logo" class="h-8 w-auto object-contain mr-2 rounded">
                <?php else: ?>
                    <i class="fas fa-user-shield mr-2"></i>
                <?php endif; ?>
                <span class="truncate">Admin Panel</span>
            </h1>
        </div>
        <nav class="flex-1 overflow-y-auto py-4 custom-scrollbar space-y-1">
            <div class="px-6 py-2 text-[10px] font-bold text-slate-500 uppercase tracking-widest mt-2">Core</div>
            <a href="index.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-tachometer-alt w-6"></i> ড্যাশবোর্ড</a>
            
            <div class="px-6 py-2 text-[10px] font-bold text-slate-500 uppercase tracking-widest mt-4">Management</div>
            <a href="manage_shareholders.php" class="flex items-center px-6 py-3 bg-blue-600/20 text-blue-400 border-l-4 border-blue-500 font-semibold"><i class="fas fa-users w-6"></i> শেয়ারহোল্ডার লিস্ট</a>
            <a href="add_shareholder.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-user-plus w-6"></i> অ্যাকাউন্ট তৈরি</a>
            <a href="manage_projects.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-project-diagram w-6"></i> প্রজেক্ট লিস্ট</a>
            <a href="add_project.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-plus-square w-6"></i> নতুন প্রজেক্ট</a>
            <a href="manage_staff.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-users-cog w-6"></i> স্টাফ ম্যানেজমেন্ট</a>
            <a href="manage_permanent_expenses.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-file-invoice w-6"></i> স্থায়ী মাসিক খরচ</a>
            <a href="manage_kpi.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-bullseye w-6"></i> KPI ম্যানেজমেন্ট</a>
            <a href="manage_votes.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-vote-yea w-6"></i> ভোটিং ও প্রস্তাবনা</a>
            <a href="manage_video.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-video w-6"></i> লাইভ ভিডিও</a>
            <a href="send_sms.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-sms w-6"></i> এসএমএস প্যানেল</a>
            
            <div class="px-6 py-2 text-[10px] font-bold text-slate-500 uppercase tracking-widest mt-4">Finance & Reports</div>
            <a href="add_entry.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-file-invoice-dollar w-6"></i> দৈনিক হিসাব এন্ট্রি</a>
            <a href="financial_reports.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-chart-pie w-6"></i> লাভ-ক্ষতির রিপোর্ট</a>
        </nav>
        <div class="p-4 border-t border-slate-800 shrink-0">
            <a href="logout.php" class="flex items-center px-4 py-2.5 text-red-400 hover:bg-red-500 hover:text-white rounded-lg transition-colors font-bold"><i class="fas fa-sign-out-alt w-6"></i> লগআউট</a>
        </div>
    </aside>

    <div class="flex flex-col min-h-screen w-full md:pl-64 transition-all duration-300">
        
        <header class="glass-header sticky top-0 z-30 px-4 py-3 flex items-center justify-between shadow-sm h-16 shrink-0">
            <div class="flex items-center gap-3">
                <button onclick="toggleSidebar()" class="text-slate-600 focus:outline-none md:hidden text-xl hover:text-blue-600 transition"><i class="fas fa-bars"></i></button>
                <h2 class="text-lg font-black tracking-tight text-slate-800 hidden sm:block">অ্যাকাউন্ট ম্যানেজমেন্ট</h2>
                <h2 class="text-lg font-black tracking-tight text-slate-800 sm:hidden">শেয়ারহোল্ডার লিস্ট</h2>
            </div>
            <div class="flex items-center gap-3">
                <div class="text-right hidden sm:block border-r border-slate-200 pr-3">
                    <div class="text-sm font-bold leading-tight"><?= htmlspecialchars($_SESSION['admin_username']) ?></div>
                    <div class="text-[10px] text-slate-500 font-bold uppercase tracking-wide">System Admin</div>
                </div>
                <div class="h-9 w-9 bg-gradient-to-br from-blue-600 to-indigo-700 rounded-full flex items-center justify-center text-white font-black shadow-md border border-white">
                    <?= strtoupper(substr($_SESSION['admin_username'] ?? 'A', 0, 1)) ?>
                </div>
            </div>
        </header>

        <main class="flex-1 overflow-x-hidden overflow-y-auto main-content p-4 md:p-6 custom-scrollbar relative">
            
            <div class="main-header">
                <?php if($message): ?><div class="bg-emerald-50 text-emerald-600 px-4 py-3 rounded-xl text-sm font-bold shadow-sm border border-emerald-100 flex items-center animate-fade-in mb-4"><i class="fas fa-check-circle mr-2 text-lg"></i> <?= $message ?></div><?php endif; ?>
                <?php if($error): ?><div class="bg-red-50 text-red-600 px-4 py-3 rounded-xl text-sm font-bold shadow-sm border border-red-100 flex items-center animate-fade-in mb-4"><i class="fas fa-exclamation-triangle mr-2 text-lg"></i> <?= $error ?></div><?php endif; ?>

                <div class="flex flex-wrap gap-3 justify-between items-center mb-4 animate-fade-in">
                    <div>
                        <h2 class="text-xl md:text-2xl font-black text-slate-800 flex items-center gap-2"><i class="fas fa-users text-blue-500"></i> শেয়ারহোল্ডার ও প্রজেক্ট লিস্ট</h2>
                        <p class="text-[11px] text-slate-500 mt-1 font-bold">অ্যাকাউন্ট, বিনিয়োগ এবং এগ্রিমেন্ট প্রিন্ট ম্যানেজমেন্ট</p>
                    </div>
                    <div class="flex gap-2">
                        <button onclick="document.getElementById('slotSettingModal').classList.remove('hidden')" class="bg-white border border-slate-200 text-slate-600 hover:text-purple-600 hover:border-purple-200 px-3 py-2 rounded-lg shadow-sm font-bold transition flex items-center gap-1.5 text-xs"><i class="fas fa-cog"></i> <span class="hidden sm:inline">স্লট সেটিং</span></button>
                        <a href="add_shareholder.php" class="bg-blue-600 text-white px-3 py-2 rounded-lg shadow-md hover:bg-blue-700 transition font-bold flex items-center gap-1.5 text-xs"><i class="fas fa-user-plus"></i> <span class="hidden sm:inline">নতুন অ্যাকাউন্ট</span></a>
                    </div>
                </div>

                <div class="flex overflow-x-auto custom-scrollbar gap-3 pb-3 mb-4 animate-fade-in" style="animation-delay: 0.1s;">
                    <div class="bg-gradient-to-br from-slate-700 to-slate-900 rounded-xl p-4 shadow-sm text-white min-w-[200px] flex-shrink-0 relative overflow-hidden border-none">
                        <i class="fas fa-building absolute -right-3 -bottom-3 text-6xl opacity-10"></i>
                        <div class="flex justify-between items-start mb-2 relative z-10">
                            <p class="text-[10px] font-black text-slate-300 uppercase tracking-wider">কোম্পানির মূল হিসাব</p>
                        </div>
                        <p class="text-xs text-slate-300 font-bold mb-1 relative z-10">General Fund</p>
                        <p class="text-xl font-black relative z-10 drop-shadow-sm">৳ <?= number_format($general_fund_inv, 0) ?></p>
                    </div>
                    
                    <?php foreach($projects_data as $pd): 
                        $is_mother_card = ($pd['id'] == $mother_project_id);
                    ?>
                        <div class="bg-gradient-to-br <?= $is_mother_card ? 'from-amber-500 to-orange-600' : 'from-blue-600 to-indigo-700' ?> rounded-xl p-4 shadow-sm text-white min-w-[200px] flex-shrink-0 relative overflow-hidden border-none">
                            <i class="fas <?= $is_mother_card ? 'fa-crown' : 'fa-project-diagram' ?> absolute -right-3 -bottom-3 text-6xl opacity-10"></i>
                            <div class="flex justify-between items-start mb-2 relative z-10">
                                <p class="text-[10px] font-black <?= $is_mother_card ? 'text-amber-100' : 'text-blue-200' ?> uppercase tracking-wider">
                                    <?= $is_mother_card ? 'Mother Project' : 'প্রজেক্ট ফান্ড' ?>
                                </p>
                                <?php if(!$is_mother_card && $mother_project_id > 0 && $pd['mother_commission_pct'] > 0): ?>
                                    <span class="text-[8px] bg-white/20 px-1.5 py-0.5 rounded backdrop-blur-sm border border-white/20">-<?= $pd['mother_commission_pct'] ?>%</span>
                                <?php endif; ?>
                            </div>
                            <p class="text-xs <?= $is_mother_card ? 'text-amber-50' : 'text-blue-100' ?> font-bold mb-1 truncate relative z-10" title="<?= htmlspecialchars($pd['project_name']) ?>"><?= htmlspecialchars($pd['project_name']) ?></p>
                            <p class="text-xl font-black relative z-10 drop-shadow-sm">৳ <?= number_format($pd['total_inv'], 0) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="app-card overflow-hidden animate-fade-in" style="animation-delay: 0.2s;">
                
                <div class="block md:hidden divide-y divide-slate-100">
                    <?php $index = 0; foreach($grouped as $group): 
                        $acc = $group; $shares_list = $group['shares'];
                        $total_person_shares = 0; $total_person_investment = 0; $personal_profit = 0; $total_person_commission = 0; $involved_projects = [];
                        
                        foreach($shares_list as $sl) { 
                            $total_person_shares += $sl['number_of_shares']; 
                            $total_person_investment += $sl['investment_credit']; 
                            $p_name = $sl['project_name'] ?? 'General Fund';
                            if(!in_array($p_name, $involved_projects)) { $involved_projects[] = $p_name; }
                            
                            $p_id = $sl['assigned_project_id'];
                            if ($p_id) {
                                if (isset($projects_data[$p_id])) {
                                    $proj = $projects_data[$p_id];
                                    $fraction = 0;
                                    
                                    if ($proj['dist_type'] == 'by_investment' && $proj['total_inv'] > 0) { 
                                        $fraction = $sl['investment_credit'] / $proj['total_inv'];
                                    } elseif ($proj['dist_type'] == 'by_share' && $proj['total_shares'] > 0) { 
                                        $fraction = $sl['number_of_shares'] / $proj['total_shares'];
                                    }

                                    if ($proj['net_distributable_profit'] > 0) {
                                        $personal_profit += ($proj['net_distributable_profit'] * $fraction);
                                    }

                                    // Mother Commission calculation for this specific share
                                    if ($p_id == $mother_project_id && $mother_project_id > 0 && !empty($mother_commission_sources) && $fraction > 0) {
                                        foreach($mother_commission_sources as $src) {
                                            $total_person_commission += ($src['amount'] * $fraction);
                                        }
                                    }
                                }
                            } else {
                                if ($total_company_shares > 0) { 
                                    $personal_profit += ($global_profit * ($sl['number_of_shares'] / $total_company_shares)); 
                                }
                            }
                        }
                        
                        $ownership_percent = ($total_company_shares > 0 && $total_person_shares > 0) ? ($total_person_shares / $total_company_shares) * 100 : 0;
                        $index++;
                    ?>
                    <div class="p-4 bg-white hover:bg-slate-50 transition">
                        <div class="flex justify-between items-start mb-3 border-b border-slate-100 pb-3">
                            <div class="flex items-center gap-3">
                                <div class="w-12 h-12 bg-indigo-100 text-indigo-600 rounded-full flex items-center justify-center font-black border border-indigo-200 shrink-0 overflow-hidden shadow-sm">
                                    <?php if(!empty($acc['profile_picture'])): ?>
                                        <img src="../<?= htmlspecialchars($acc['profile_picture']) ?>" class="w-full h-full object-cover">
                                    <?php else: ?>
                                        <?= strtoupper(substr($acc['name'], 0, 1)) ?>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <h3 class="font-black text-slate-800 text-base leading-tight flex items-center gap-2">
                                        <?= htmlspecialchars($acc['name']) ?>
                                        <?php if($acc['is_account']): ?>
                                            <button onclick="openEditAccModal(<?= $acc['account_id'] ?>, '<?= htmlspecialchars(addslashes($acc['name'])) ?>', '<?= htmlspecialchars(addslashes($acc['username'])) ?>', '<?= htmlspecialchars(addslashes($acc['phone'])) ?>', '<?= htmlspecialchars(addslashes($acc['profile_picture'])) ?>')" class="text-blue-500 hover:text-blue-700 bg-blue-50 w-6 h-6 rounded-full flex items-center justify-center border border-blue-100"><i class="fas fa-edit text-[10px]"></i></button>
                                        <?php endif; ?>
                                        <button onclick='printDeed(<?= htmlspecialchars(json_encode($group), ENT_QUOTES, 'UTF-8') ?>)' class="text-emerald-500 hover:text-emerald-700 bg-emerald-50 w-6 h-6 rounded-full flex items-center justify-center border border-emerald-100 shadow-sm" title="চুক্তিপত্র প্রিন্ট করুন"><i class="fas fa-file-contract text-[10px]"></i></button>
                                    </h3>
                                    <div class="text-[10px] text-slate-500 font-bold mt-1 flex flex-wrap items-center gap-2">
                                        <span class="bg-slate-100 px-1.5 py-0.5 rounded">@<?= htmlspecialchars($acc['username']) ?></span>
                                        <?php if(!empty($acc['phone'])): ?>
                                            <span class="bg-slate-100 px-1.5 py-0.5 rounded flex items-center gap-1 text-slate-600"><i class="fas fa-phone-alt text-[9px]"></i> <?= htmlspecialchars($acc['phone']) ?></span>
                                        <?php endif; ?>
                                        <span class="text-indigo-500 font-black"><?= number_format($ownership_percent, 1) ?>% Share</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-3 gap-2 mb-3 bg-slate-50 p-2.5 rounded-xl border border-slate-100">
                            <div class="text-center border-r border-slate-200">
                                <p class="text-[9px] font-bold text-slate-400 uppercase mb-0.5">মোট শেয়ার</p>
                                <p class="font-black text-indigo-600 text-sm"><?= $total_person_shares ?></p>
                            </div>
                            <div class="text-center border-r border-slate-200">
                                <p class="text-[9px] font-bold text-slate-400 uppercase mb-0.5">বিনিয়োগ</p>
                                <p class="font-black text-slate-700 text-sm">৳<?= number_format($total_person_investment, 0) ?></p>
                            </div>
                            <div class="text-center">
                                <p class="text-[9px] font-bold text-emerald-500 uppercase mb-0.5">মোট লাভ</p>
                                <p class="font-black text-emerald-600 text-sm">৳<?= number_format($personal_profit, 0) ?></p>
                            </div>
                        </div>

                        <?php if($total_person_commission > 0.01): ?>
                        <div class="bg-amber-50 rounded-xl p-2.5 mb-3 flex justify-between items-center border border-amber-200 shadow-sm">
                            <span class="text-[10px] font-bold text-amber-700 uppercase tracking-widest"><i class="fas fa-gift mr-1"></i> মোট প্রাপ্ত কমিশন</span>
                            <span class="font-black text-amber-600 text-sm">+৳<?= number_format($total_person_commission, 2) ?></span>
                        </div>
                        <?php endif; ?>

                        <button onclick="toggleRow('mob_row_<?= $index ?>')" class="w-full bg-white border border-slate-200 text-slate-600 font-bold text-[11px] py-2 rounded-lg shadow-sm hover:bg-slate-50 transition flex justify-center items-center gap-1.5">
                            <i class="fas fa-layer-group text-indigo-400"></i> বিস্তারিত প্রজেক্ট লিস্ট (<?= count($shares_list) ?>) <i class="fas fa-chevron-down text-[10px] ml-1"></i>
                        </button>
                        
                        <div id="mob_row_<?= $index ?>" class="hidden mt-3 space-y-2 bg-slate-100/50 p-2 rounded-xl border border-slate-200">
                            <?php foreach($shares_list as $sh): 
                                $p_id = $sh['assigned_project_id']; $entry_profit = 0; $entry_percent = 0; $dist_label = 'Share Basis'; $fraction = 0;
                                $commission_html = '';

                                if ($p_id) {
                                    if (isset($projects_data[$p_id])) {
                                        $proj = $projects_data[$p_id];
                                        if ($proj['dist_type'] == 'by_investment') {
                                            $fraction = ($proj['total_inv'] > 0) ? ($sh['investment_credit'] / $proj['total_inv']) : 0;
                                            $entry_percent = $fraction * 100;
                                            if ($proj['net_distributable_profit'] > 0) { $entry_profit = $proj['net_distributable_profit'] * $fraction; }
                                            $dist_label = 'Amount Basis';
                                        } else {
                                            $fraction = ($proj['total_shares'] > 0) ? ($sh['number_of_shares'] / $proj['total_shares']) : 0;
                                            $entry_percent = $fraction * 100;
                                            if ($proj['net_distributable_profit'] > 0) { $entry_profit = $proj['net_distributable_profit'] * $fraction; }
                                        }
                                        
                                        // Specific Share Commission Display
                                        if ($p_id == $mother_project_id && $mother_project_id > 0 && !empty($mother_commission_sources) && $fraction > 0) {
                                            $commission_html .= '<div class="mt-2 pt-2 border-t border-amber-100">';
                                            $commission_html .= '<p class="text-[9px] font-black text-amber-600 mb-1.5 flex items-center gap-1"><i class="fas fa-gift"></i> চাইল্ড প্রজেক্ট থেকে প্রাপ্ত কমিশন:</p>';
                                            $commission_html .= '<div class="flex flex-wrap gap-1.5">';
                                            foreach($mother_commission_sources as $src) {
                                                $user_bonus = $src['amount'] * $fraction;
                                                if($user_bonus > 0.01) { 
                                                    $commission_html .= '<span class="bg-amber-50 text-amber-700 px-2 py-0.5 rounded border border-amber-200 text-[9px] font-bold shadow-sm" title="Project: '.htmlspecialchars($src['name']).'">' . htmlspecialchars(mb_strimwidth($src['name'], 0, 12, '..')) . ': <span class="text-amber-600 font-black">+৳' . number_format($user_bonus, 2) . '</span></span>';
                                                }
                                            }
                                            $commission_html .= '</div></div>';
                                        }
                                    }
                                } else {
                                    $fraction = ($total_company_shares > 0) ? ($sh['number_of_shares'] / $total_company_shares) : 0;
                                    $entry_percent = $fraction * 100;
                                    $dist_label = 'Global Share';
                                    if ($total_company_shares > 0) { $entry_profit = ($global_profit * $fraction); }
                                }
                            ?>
                            <div class="bg-white p-3 rounded-lg border border-slate-200 shadow-sm relative">
                                <div class="absolute top-2 right-2 flex gap-1">
                                    <button onclick="openEditShareModal(<?= $sh['id'] ?>, <?= $sh['number_of_shares'] ?>, <?= $sh['investment_credit'] ?>, '<?= $sh['share_type'] ?>', '<?= $sh['assigned_project_id'] ?>', '<?= $sh['deadline_date'] ?>', '<?= htmlspecialchars(addslashes($sh['slot_numbers'] ?? '')) ?>')" class="w-6 h-6 bg-blue-50 text-blue-600 rounded flex items-center justify-center border border-blue-100 hover:bg-blue-600 hover:text-white transition"><i class="fas fa-edit text-[10px]"></i></button>
                                    <button onclick="openDeleteShareModal(<?= $sh['id'] ?>)" class="w-6 h-6 bg-red-50 text-red-500 rounded flex items-center justify-center border border-red-100 hover:bg-red-500 hover:text-white transition"><i class="fas fa-trash text-[10px]"></i></button>
                                </div>
                                
                                <h4 class="font-bold text-slate-800 text-xs pr-14 flex items-center gap-1.5">
                                    <?= htmlspecialchars($sh['project_name'] ?? 'General Fund') ?>
                                    <?php if($p_id == $mother_project_id && $mother_project_id > 0): ?>
                                        <i class="fas fa-crown text-amber-500 text-[10px]"></i>
                                    <?php endif; ?>
                                </h4>
                                <span class="text-[9px] bg-slate-100 text-slate-500 px-1.5 py-0.5 rounded font-bold border border-slate-200 mt-1 inline-block"><?= $dist_label ?></span>
                                
                                <div class="grid grid-cols-2 gap-2 mt-3">
                                    <div>
                                        <p class="text-[9px] font-bold text-slate-400 uppercase">Share / Slot</p>
                                        <p class="text-[11px] font-black text-indigo-600"><?= $sh['number_of_shares'] > 0 ? $sh['number_of_shares'] : '-' ?> <?= !empty($sh['slot_numbers']) ? ' (S: '.$sh['slot_numbers'].')' : '' ?></p>
                                    </div>
                                    <div>
                                        <p class="text-[9px] font-bold text-slate-400 uppercase text-right">Investment</p>
                                        <p class="text-[11px] font-black text-slate-700 text-right">৳<?= number_format($sh['investment_credit'], 0) ?></p>
                                    </div>
                                </div>
                                <div class="mt-2 pt-2 border-t border-slate-100 flex justify-between items-center">
                                    <span class="text-[9px] font-bold text-indigo-500 bg-indigo-50 px-1.5 py-0.5 rounded"><?= number_format($entry_percent, 1) ?>% Own</span>
                                    <span class="text-[11px] font-black text-emerald-600">৳ <?= number_format($entry_profit, 2) ?> Profit</span>
                                </div>
                                
                                <?= $commission_html ?> 
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="hidden md:block overflow-x-auto pb-6">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-slate-50 border-b border-slate-200 text-[11px] uppercase tracking-wider text-slate-500 font-black">
                                <th class="px-5 py-4 pl-6">অ্যাকাউন্ট ইনফো ও প্রজেক্টসমূহ</th>
                                <th class="px-5 py-4 text-center">মোট শেয়ার</th>
                                <th class="px-5 py-4 text-center">কোম্পানিতে মালিকানা</th>
                                <th class="px-5 py-4 text-right">মোট বিনিয়োগ</th>
                                <th class="px-5 py-4 text-right">সর্বমোট লাভ</th>
                                <th class="px-5 py-4 text-center pr-6">অ্যাকশন</th>
                            </tr>
                        </thead>
                        <tbody class="text-sm divide-y divide-slate-100">
                            <?php 
                            $index = 0;
                            foreach($grouped as $group): 
                                $acc = $group; $shares_list = $group['shares'];
                                $total_person_shares = 0; $total_person_investment = 0; $personal_profit = 0; $total_person_commission = 0; $involved_projects = [];
                                
                                foreach($shares_list as $sl) { 
                                    $total_person_shares += $sl['number_of_shares']; 
                                    $total_person_investment += $sl['investment_credit']; 
                                    
                                    $p_name = $sl['project_name'] ?? 'General Fund';
                                    if(!in_array($p_name, $involved_projects)) { $involved_projects[] = $p_name; }
                                    
                                    $p_id = $sl['assigned_project_id'];
                                    if ($p_id) {
                                        if (isset($projects_data[$p_id])) {
                                            $proj = $projects_data[$p_id];
                                            $fraction = 0;
                                            if ($proj['dist_type'] == 'by_investment' && $proj['total_inv'] > 0) {
                                                $fraction = $sl['investment_credit'] / $proj['total_inv'];
                                            } elseif ($proj['dist_type'] == 'by_share' && $proj['total_shares'] > 0) {
                                                $fraction = $sl['number_of_shares'] / $proj['total_shares'];
                                            }
                                            
                                            if ($proj['net_distributable_profit'] > 0) {
                                                $personal_profit += ($proj['net_distributable_profit'] * $fraction);
                                            }

                                            if ($p_id == $mother_project_id && $mother_project_id > 0 && !empty($mother_commission_sources) && $fraction > 0) {
                                                foreach($mother_commission_sources as $src) {
                                                    $total_person_commission += ($src['amount'] * $fraction);
                                                }
                                            }
                                        }
                                    } else {
                                        if ($total_company_shares > 0) { 
                                            $personal_profit += ($global_profit * ($sl['number_of_shares'] / $total_company_shares)); 
                                        }
                                    }
                                }
                                
                                $ownership_percent = ($total_company_shares > 0 && $total_person_shares > 0) ? ($total_person_shares / $total_company_shares) * 100 : 0;
                                $index++;
                            ?>
                            <tr class="bg-blue-50/30 hover:bg-blue-50/70 transition border-b-2 border-slate-200">
                                <td class="px-5 py-4 pl-6 align-top">
                                    <div class="font-black text-slate-800 text-base flex items-center gap-2">
                                        <div class="w-9 h-9 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center text-xs border border-blue-200 shrink-0 overflow-hidden shadow-sm">
                                            <?php if(!empty($acc['profile_picture'])): ?>
                                                <img src="../<?= htmlspecialchars($acc['profile_picture']) ?>" class="w-full h-full object-cover">
                                            <?php else: ?>
                                                <i class="fas fa-user"></i>
                                            <?php endif; ?>
                                        </div>
                                        <?= htmlspecialchars($acc['name']) ?>
                                    </div>
                                    <div class="text-xs text-slate-500 mt-2 font-bold flex flex-wrap items-center gap-2">
                                        <span class="bg-white border border-slate-200 px-2 py-0.5 rounded text-[10px] text-slate-600">@<?= htmlspecialchars($acc['username']) ?></span>
                                        <?php if(!empty($acc['phone'])): ?>
                                            <span class="bg-white border border-slate-200 px-2 py-0.5 rounded text-[10px] text-slate-600 flex items-center gap-1"><i class="fas fa-phone-alt"></i> <?= htmlspecialchars($acc['phone']) ?></span>
                                        <?php endif; ?>
                                        <?php if($acc['is_account']): ?>
                                            <button onclick="openEditAccModal(<?= $acc['account_id'] ?>, '<?= htmlspecialchars(addslashes($acc['name'])) ?>', '<?= htmlspecialchars(addslashes($acc['username'])) ?>', '<?= htmlspecialchars(addslashes($acc['phone'])) ?>', '<?= htmlspecialchars(addslashes($acc['profile_picture'])) ?>')" class="text-blue-500 hover:text-blue-700 transition bg-blue-50 px-2 py-0.5 rounded border border-blue-100 ml-1"><i class="fas fa-edit"></i> Edit Account</button>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex flex-wrap gap-1.5 mt-2.5">
                                        <?php foreach($involved_projects as $ip): ?>
                                            <span class="bg-indigo-50 text-indigo-600 px-2 py-0.5 rounded text-[9px] font-bold border border-indigo-100 shadow-sm"><?= htmlspecialchars($ip) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td class="px-5 py-4 text-center font-black text-indigo-600 text-lg align-middle"><?= $total_person_shares ?> <span class="text-xs text-slate-400 font-bold">টি</span></td>
                                <td class="px-5 py-4 text-center align-middle"><span class="bg-slate-800 text-white px-3 py-1 rounded-full text-[11px] font-bold shadow-sm"><?= number_format($ownership_percent, 2) ?>%</span></td>
                                <td class="px-5 py-4 text-slate-700 font-black text-lg text-right align-middle pr-4">৳ <?= number_format($total_person_investment, 0) ?></td>
                                <td class="px-5 py-4 text-right align-middle pr-4">
                                    <div class="text-emerald-600 font-black text-lg">৳ <?= number_format($personal_profit, 2) ?></div>
                                    <?php if($total_person_commission > 0.01): ?>
                                        <div class="text-[10px] font-bold text-amber-600 bg-amber-50 px-2 py-0.5 rounded-md border border-amber-200 shadow-sm inline-flex items-center gap-1 mt-1" title="মোট প্রাপ্ত কমিশন">
                                            <i class="fas fa-gift"></i> +৳<?= number_format($total_person_commission, 2) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-4 text-center align-middle pr-6">
                                    <div class="flex flex-col items-center gap-2">
                                        <button onclick="toggleRow('row_<?= $index ?>')" class="w-full bg-white border border-slate-200 text-slate-600 font-bold text-[11px] px-3 py-1.5 rounded-lg shadow-sm hover:bg-blue-50 hover:text-blue-600 hover:border-blue-200 transition flex items-center justify-center gap-1.5 mx-auto">
                                            <i class="fas fa-layer-group"></i> Details (<?= count($shares_list) ?>) <i class="fas fa-chevron-down text-[10px]"></i>
                                        </button>
                                        <button onclick='printDeed(<?= htmlspecialchars(json_encode($group), ENT_QUOTES, 'UTF-8') ?>)' class="w-full bg-emerald-50 border border-emerald-200 text-emerald-700 font-bold text-[11px] px-3 py-1.5 rounded-lg shadow-sm hover:bg-emerald-600 hover:text-white transition flex items-center justify-center gap-1.5 mx-auto">
                                            <i class="fas fa-file-contract"></i> এগ্রিমেন্ট প্রিন্ট
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            
                            <tr id="row_<?= $index ?>" class="hidden bg-slate-100/50">
                                <td colspan="6" class="p-4 border-b border-slate-300">
                                    <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                                        <div class="bg-slate-50 px-4 py-2.5 border-b border-slate-200 flex items-center gap-2">
                                            <i class="fas fa-project-diagram text-blue-500 text-xs"></i> <span class="text-xs font-black text-slate-700">প্রজেক্ট অনুযায়ী বিস্তারিত হিসাব</span>
                                        </div>
                                        <table class="w-full text-left">
                                            <thead class="bg-white border-b border-slate-100 text-[10px] text-slate-400 uppercase font-black tracking-wider">
                                                <tr>
                                                    <th class="p-3 pl-4">প্রজেক্ট ও পলিসি</th>
                                                    <th class="p-3 text-center">শেয়ার ও স্লট</th>
                                                    <th class="p-3 text-right">বিনিয়োগ</th>
                                                    <th class="p-3 text-center">মালিকানা (%)</th>
                                                    <th class="p-3 text-right">অর্জিত লাভ (৳)</th>
                                                    <th class="p-3 text-center pr-4">অ্যাকশন</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-slate-50">
                                                <?php foreach($shares_list as $sh): 
                                                    $p_id = $sh['assigned_project_id']; $entry_profit = 0; $entry_percent = 0; $dist_label = 'Share Basis'; $fraction = 0;
                                                    $commission_html = '';

                                                    if ($p_id) {
                                                        if (isset($projects_data[$p_id])) {
                                                            $proj = $projects_data[$p_id];
                                                            if ($proj['dist_type'] == 'by_investment') {
                                                                $fraction = ($proj['total_inv'] > 0) ? ($sh['investment_credit'] / $proj['total_inv']) : 0;
                                                                $entry_percent = $fraction * 100;
                                                                if ($proj['net_distributable_profit'] > 0) { $entry_profit = $proj['net_distributable_profit'] * $fraction; }
                                                                $dist_label = 'Amount Basis';
                                                            } else {
                                                                $fraction = ($proj['total_shares'] > 0) ? ($sh['number_of_shares'] / $proj['total_shares']) : 0;
                                                                $entry_percent = $fraction * 100;
                                                                if ($proj['net_distributable_profit'] > 0) { $entry_profit = $proj['net_distributable_profit'] * $fraction; }
                                                            }
                                                            
                                                            if ($p_id == $mother_project_id && $mother_project_id > 0 && !empty($mother_commission_sources) && $fraction > 0) {
                                                                $commission_html .= '<div class="mt-2 pt-2 border-t border-amber-100">';
                                                                $commission_html .= '<p class="text-[9px] font-black text-amber-600 mb-1.5 flex items-center gap-1"><i class="fas fa-gift"></i> চাইল্ড প্রজেক্ট থেকে প্রাপ্ত কমিশন:</p>';
                                                                $commission_html .= '<div class="flex flex-wrap gap-1.5">';
                                                                foreach($mother_commission_sources as $src) {
                                                                    $user_bonus = $src['amount'] * $fraction;
                                                                    if($user_bonus > 0.01) { 
                                                                        $commission_html .= '<span class="bg-amber-50 text-amber-700 px-2 py-0.5 rounded border border-amber-200 text-[9px] font-bold shadow-sm" title="Project: '.htmlspecialchars($src['name']).'">' . htmlspecialchars(mb_strimwidth($src['name'], 0, 12, '..')) . ': <span class="text-amber-600 font-black">+৳' . number_format($user_bonus, 2) . '</span></span>';
                                                                    }
                                                                }
                                                                $commission_html .= '</div></div>';
                                                            }
                                                        }
                                                    } else {
                                                        $fraction = ($total_company_shares > 0) ? ($sh['number_of_shares'] / $total_company_shares) : 0;
                                                        $entry_percent = $fraction * 100;
                                                        $dist_label = 'Global Share';
                                                        if ($total_company_shares > 0) { $entry_profit = ($global_profit * $fraction); }
                                                    }
                                                ?>
                                                <tr class="hover:bg-slate-50 transition text-xs">
                                                    <td class="p-3 pl-4">
                                                        <div class="text-blue-600 font-bold text-sm mb-1 flex items-center gap-1.5">
                                                            <?= htmlspecialchars($sh['project_name'] ?? 'General Fund') ?>
                                                            <?php if($p_id == $mother_project_id && $mother_project_id > 0): ?>
                                                                <i class="fas fa-crown text-amber-500 text-xs"></i>
                                                            <?php endif; ?>
                                                        </div>
                                                        <span class="text-[9px] text-slate-500 font-bold bg-slate-100 px-1.5 py-0.5 rounded border border-slate-200 block w-max mb-1.5"><?= $dist_label ?></span>
                                                        <?= $commission_html ?>
                                                    </td>
                                                    <td class="p-3 text-center align-top">
                                                        <?php if($sh['number_of_shares'] > 0): ?>
                                                            <div class="font-black text-indigo-600 text-sm"><?= $sh['number_of_shares'] ?> <span class="text-[9px] font-bold text-slate-400">টি</span></div>
                                                        <?php else: ?><div class="font-bold text-slate-300">-</div><?php endif; ?>
                                                        
                                                        <?php if(!empty($sh['slot_numbers'])): ?>
                                                            <div class="text-[9px] text-slate-500 mt-1 font-bold bg-white px-1.5 py-0.5 rounded inline-block border border-slate-200 shadow-sm">Slot: <?= htmlspecialchars($sh['slot_numbers']) ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="p-3 text-slate-700 font-bold text-right align-top">৳ <?= number_format($sh['investment_credit'], 0) ?></td>
                                                    <td class="p-3 text-center align-top">
                                                        <span class="font-bold text-indigo-700 bg-indigo-50 px-2 py-0.5 rounded border border-indigo-100 text-[10px]"><?= number_format($entry_percent, 2) ?>%</span>
                                                    </td>
                                                    <td class="p-3 text-right align-top">
                                                        <span class="font-black text-emerald-600">৳ <?= number_format($entry_profit, 2) ?></span>
                                                    </td>
                                                    <td class="p-3 text-center pr-4 align-top">
                                                        <div class="flex items-center justify-center gap-1.5">
                                                            <button onclick="openEditShareModal(<?= $sh['id'] ?>, <?= $sh['number_of_shares'] ?>, <?= $sh['investment_credit'] ?>, '<?= $sh['share_type'] ?>', '<?= $sh['assigned_project_id'] ?>', '<?= $sh['deadline_date'] ?>', '<?= htmlspecialchars(addslashes($sh['slot_numbers'] ?? '')) ?>')" class="w-7 h-7 rounded-lg bg-blue-50 text-blue-600 hover:bg-blue-600 hover:text-white transition flex items-center justify-center border border-blue-100" title="এডিট"><i class="fas fa-edit text-[10px]"></i></button>
                                                            <button onclick="openDeleteShareModal(<?= $sh['id'] ?>)" class="w-7 h-7 rounded-lg bg-red-50 text-red-500 hover:bg-red-600 hover:text-white transition flex items-center justify-center border border-red-100" title="ডিলিট"><i class="fas fa-trash text-[10px]"></i></button>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="print-deed-wrapper" id="print_section_deed">
                <div class="deed-watermark">SODAI LAGBE</div>
                <div class="deed-header">
                    <h1>Sodai Lagbe</h1>
                    <h2>Shareholder E-Agreement & Proof of Investment</h2>
                    <p>Internal Record Document (অভ্যন্তরীণ চুক্তিপত্র)</p>
                </div>
                
                <div class="deed-content">
                    <p>
                        This E-Document serves as an internal agreement and proof of investment for 'Sodai Lagbe'. While not a non-judicial stamp paper, it ensures the shareholder's rights and ownership as per the company's internal ledger under the <b>Companies Act 1994 (Bangladesh)</b>.
                    </p>
                    <p style="margin-top: 10px;">
                        এই ই-ডকুমেন্টটি 'সদাই লাগবে' কোম্পানির একটি অভ্যন্তরীণ চুক্তিপত্র এবং বিনিয়োগের প্রমাণপত্র হিসেবে গণ্য হবে। এটি কোনো সরকারি নন-জুডিশিয়াল স্ট্যাম্প পেপার নয়, তবে বাংলাদেশ <b>কোম্পানি আইন ১৯৯৪</b> এর আওতায় কোম্পানির নিজস্ব রেকর্ড অনুযায়ী শেয়ারহোল্ডারের অধিকার ও মালিকানা সম্পূর্ণভাবে নিশ্চিত করে।
                    </p>
                    
                    <p style="margin-top: 30px; font-size: 13pt;">
                        This is to certify that Mr./Ms. <span class="deed-strong" id="deedName"></span> (Username: <span class="deed-strong" id="deedUser"></span>, Mobile: <span class="deed-strong" id="deedPhone"></span>) has successfully invested in our company and holds shares/ownership in the following projects.
                    </p>
                    <p style="margin-top: 10px; font-size: 13pt;">
                        এতদ্বারা প্রত্যয়ন করা যাচ্ছে যে, জনাব/বেগম <span class="deed-strong" id="deedNameBn"></span> (ইউজারনেম: <span class="deed-strong" id="deedUserBn"></span>, মোবাইল: <span class="deed-strong" id="deedPhoneBn"></span>) আমাদের কোম্পানিতে সফলভাবে বিনিয়োগ করেছেন এবং নিম্নোক্ত প্রজেক্টগুলোতে তার শেয়ার ও মালিকানা নিশ্চিত করা হলো।
                    </p>
                </div>
                
                <table class="deed-table">
                    <thead>
                        <tr>
                            <th style="width: 5%;">No. (নং)</th>
                            <th style="width: 40%;">Project Name (প্রজেক্ট)</th>
                            <th style="width: 20%;">Category (ধরন)</th>
                            <th style="width: 15%;">Shares/Slots (শেয়ার)</th>
                            <th style="width: 20%; text-align: right;">Investment (বিনিয়োগ)</th>
                        </tr>
                    </thead>
                    <tbody id="deedTableBody">
                    </tbody>
                </table>
                
                <div class="deed-content" style="margin-top: 30px; font-size: 11pt;">
                    <p><b>Declaration:</b> By accepting this document, both the company authority and the shareholder agree to the internal profit-sharing distribution policies set by Sodai Lagbe management. All calculations regarding profits, liabilities, and bonuses will be strictly governed by the company's official ERP system and terms of service.</p>
                </div>

                <div class="deed-signatures">
                    <div class="deed-sig-box">
                        <div class="deed-sig-line"></div>
                        <p>Shareholder's Signature</p>
                        <p style="font-weight:normal; font-size:9pt;">(শেয়ারহোল্ডারের স্বাক্ষর)</p>
                    </div>
                    <div class="deed-sig-box">
                        <div class="deed-sig-line"></div>
                        <p>Managing Director / CEO</p>
                        <p style="font-weight:normal; font-size:9pt;">(ব্যবস্থাপনা পরিচালক / সিইও)</p>
                    </div>
                    <div class="deed-sig-box">
                        <div class="deed-sig-line" style="border-color:transparent;"></div>
                        <p>Company Seal</p>
                        <p style="font-weight:normal; font-size:9pt;">(কোম্পানির সীলমোহর)</p>
                    </div>
                </div>
                
                <div class="deed-footer">
                    Document Generated On: <span id="deedDate"></span> | Sodai Lagbe ERP System
                </div>
            </div>
            
        </main>
    </div>

    <nav class="bottom-nav shadow-[0_-5px_15px_rgba(0,0,0,0.05)]">
        <a href="index.php" class="nav-item"><i class="fas fa-tachometer-alt"></i> Home</a>
        <a href="manage_shareholders.php" class="nav-item active"><i class="fas fa-users"></i> Users</a>
        <a href="add_entry.php" class="nav-item"><i class="fas fa-file-invoice-dollar"></i> Add</a>
        <button onclick="toggleSidebar()" class="nav-item bg-transparent border-none outline-none focus:outline-none"><i class="fas fa-bars"></i> Menu</button>
    </nav>

    <div id="slotSettingModal" class="hidden fixed inset-0 bg-slate-900/60 flex items-center justify-center z-50 p-4 backdrop-blur-sm transition-opacity">
        <div class="bg-white p-6 rounded-2xl shadow-2xl w-full max-w-sm transform scale-100 border border-slate-100">
            <h3 class="text-base font-black mb-4 border-b border-slate-100 pb-3 flex items-center gap-2 text-slate-800"><i class="fas fa-th text-blue-500"></i> মোট স্লট সেটিং</h3>
            <form method="POST">
                <input type="hidden" name="action" value="update_slots">
                <label class="block text-[10px] font-bold mb-2 text-slate-500 uppercase">ড্যাশবোর্ডে দেখানোর জন্য স্লট সংখ্যা</label>
                <input type="number" name="total_slots" value="<?= $total_slots_setting ?>" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg focus:border-blue-500 focus:bg-white outline-none mb-5 text-sm font-bold text-slate-800 transition" required>
                <div class="flex gap-2">
                    <button type="button" onclick="document.getElementById('slotSettingModal').classList.add('hidden')" class="flex-1 py-2.5 bg-slate-100 text-slate-600 rounded-lg font-bold hover:bg-slate-200 text-xs transition">বাতিল</button>
                    <button type="submit" class="flex-1 py-2.5 bg-blue-600 text-white rounded-lg font-bold hover:bg-blue-700 shadow-md text-xs transition">সেভ করুন</button>
                </div>
            </form>
        </div>
    </div>

    <div id="editAccModal" class="hidden fixed inset-0 bg-slate-900/60 flex items-center justify-center z-50 p-4 backdrop-blur-sm transition-opacity">
        <div class="bg-white p-6 rounded-2xl shadow-2xl w-full max-w-md border border-slate-100">
            <h3 class="text-base font-black mb-4 border-b border-slate-100 pb-3 flex items-center gap-2 text-slate-800"><i class="fas fa-user-edit text-indigo-500"></i> অ্যাকাউন্ট এডিট</h3>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="edit_account">
                <input type="hidden" name="account_id" id="edit_acc_id">
                
                <div class="flex items-center gap-4 mb-4">
                    <div class="relative w-14 h-14 rounded-full border-2 border-indigo-100 overflow-hidden shrink-0 bg-slate-100 flex items-center justify-center shadow-sm">
                        <img src="" alt="Profile" class="w-full h-full object-cover hidden" id="adminAccProfilePreview">
                        <i class="fas fa-user text-xl text-slate-300" id="adminAccProfileIcon"></i>
                    </div>
                    <div class="flex-1">
                        <label class="block text-[10px] font-bold mb-1 text-slate-500 uppercase">প্রোফাইল ছবি</label>
                        <input type="file" name="acc_profile_pic" accept="image/*" onchange="previewAdminAccImage(this)" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-2 py-1.5 text-xs outline-none focus:border-indigo-500 transition cursor-pointer file:mr-2 file:py-1 file:px-3 file:rounded-md file:border-0 file:text-[10px] file:font-bold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
                    </div>
                </div>

                <div class="space-y-3">
                    <div>
                        <label class="block text-[10px] font-bold mb-1 text-slate-500 uppercase">পূর্ণ নাম</label>
                        <input type="text" name="acc_name" id="edit_acc_name" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg focus:border-indigo-500 focus:bg-white outline-none text-sm font-bold text-slate-800 transition" required>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold mb-1 text-slate-500 uppercase">ইউজারনেম</label>
                        <input type="text" name="acc_username" id="edit_acc_user" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg focus:border-indigo-500 focus:bg-white outline-none text-sm font-bold text-slate-800 transition" required>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold mb-1 text-slate-500 uppercase">মোবাইল নম্বর (OTP এর জন্য)</label>
                        <input type="text" name="acc_phone" id="edit_acc_phone" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg focus:border-indigo-500 focus:bg-white outline-none text-sm font-bold text-slate-800 transition" placeholder="Ex: 01XXXXXXXXX">
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold mb-1 text-slate-500 uppercase">নতুন পাসওয়ার্ড</label>
                        <input type="password" name="acc_password" id="edit_acc_pass" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg focus:border-indigo-500 focus:bg-white outline-none text-sm transition" placeholder="পরিবর্তন না করলে ফাঁকা রাখুন">
                    </div>
                </div>
                
                <div class="flex gap-2 mt-5">
                    <button type="button" onclick="closeModals()" class="flex-1 py-2.5 bg-slate-100 text-slate-600 rounded-lg font-bold hover:bg-slate-200 text-xs transition">বাতিল</button>
                    <button type="submit" class="flex-1 py-2.5 bg-indigo-600 text-white rounded-lg font-bold hover:bg-indigo-700 shadow-md text-xs transition">আপডেট</button>
                </div>
            </form>
        </div>
    </div>

    <div id="editShareModal" class="hidden fixed inset-0 bg-slate-900/60 flex items-center justify-center z-50 p-4 backdrop-blur-sm transition-opacity">
        <div class="bg-white p-6 rounded-2xl shadow-2xl w-full max-w-lg border border-slate-100 max-h-[90vh] overflow-y-auto custom-scrollbar">
            <h3 class="text-base font-black mb-4 border-b border-slate-100 pb-3 flex items-center gap-2 text-slate-800"><i class="fas fa-chart-pie text-blue-500"></i> শেয়ার এডিট করুন</h3>
            <form method="POST">
                <input type="hidden" name="action" value="edit_share">
                <input type="hidden" name="share_id" id="edit_share_id">
                
                <div class="mb-4 bg-slate-50 p-3.5 rounded-xl border border-slate-200">
                    <label class="block text-[10px] font-bold mb-1.5 text-slate-500 uppercase">প্রজেক্ট নির্বাচন</label>
                    <select name="project_id" id="edit_share_proj" onchange="toggleEditShareField()" class="w-full px-4 py-2.5 border border-slate-200 rounded-lg focus:border-blue-500 focus:ring-1 focus:ring-blue-500 bg-white outline-none text-sm font-bold text-slate-800 cursor-pointer shadow-sm">
                        <option value="" data-dist="by_share">-- General Fund (প্রজেক্ট নেই) --</option>
                        <?php foreach($all_projects as $proj): ?>
                            <option value="<?= $proj['id'] ?>" data-dist="<?= $proj['dist_type'] ?? 'by_share' ?>">
                                <?= htmlspecialchars($proj['project_name']) ?> (<?= (isset($proj['dist_type']) && $proj['dist_type'] == 'by_investment') ? 'টাকা অনুযায়ী' : 'শেয়ার অনুযায়ী' ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                    <div id="edit_share_input_div">
                        <label class="block text-[10px] font-bold mb-1.5 text-slate-500 uppercase">শেয়ার সংখ্যা <span class="text-red-500">*</span></label>
                        <input type="number" name="number_of_shares" id="edit_share_num" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg focus:bg-white focus:border-blue-500 outline-none font-black text-indigo-600 text-sm" required>
                    </div>
                    <div id="edit_investment_input_div">
                        <label class="block text-[10px] font-bold mb-1.5 text-slate-500 uppercase">মোট বিনিয়োগ (টাকা) <span class="text-red-500">*</span></label>
                        <input type="number" step="0.01" name="investment" id="edit_share_inv" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg focus:bg-white focus:border-blue-500 outline-none font-black text-slate-800 text-sm" required>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="block text-[10px] font-bold mb-1.5 text-slate-500 uppercase">ড্যাশবোর্ড স্লট নাম্বার</label>
                    <input type="text" name="slot_numbers" id="edit_share_slots" class="w-full px-4 py-2.5 border rounded-lg focus:border-indigo-500 outline-none bg-indigo-50/50 border-indigo-200 text-sm font-bold text-indigo-700" placeholder="উদা: 5, 12">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
                    <div>
                        <label class="block text-[10px] font-bold mb-1.5 text-slate-500 uppercase">ক্যাটাগরি</label>
                        <select name="share_type" id="edit_share_type" class="w-full px-4 py-2.5 border rounded-lg focus:border-green-500 outline-none bg-emerald-50/50 border-emerald-200 text-sm font-bold text-emerald-700 cursor-pointer">
                            <option value="passive">প্যাসিভ</option>
                            <option value="active_money">অ্যাক্টিভ (শুধু অর্থ)</option>
                            <option value="active_labor">অ্যাক্টিভ (অর্থ + শ্রম)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold mb-1.5 text-slate-500 uppercase">ডেডলাইন</label>
                        <input type="date" name="deadline_date" id="edit_share_dead" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg focus:bg-white focus:border-blue-500 outline-none text-sm font-bold text-slate-700">
                    </div>
                </div>
                
                <div class="flex gap-2 border-t border-slate-100 pt-4">
                    <button type="button" onclick="closeModals()" class="flex-1 py-3 bg-slate-100 text-slate-600 rounded-xl font-bold hover:bg-slate-200 transition text-sm">বাতিল</button>
                    <button type="submit" class="flex-1 py-3 bg-blue-600 text-white rounded-xl font-bold hover:bg-blue-700 shadow-md transition text-sm">আপডেট করুন</button>
                </div>
            </form>
        </div>
    </div>

    <div id="deleteShareModal" class="hidden fixed inset-0 bg-slate-900/60 flex items-center justify-center z-50 p-4 backdrop-blur-sm transition-opacity">
        <div class="bg-white p-6 rounded-2xl shadow-2xl w-full max-w-sm border border-slate-100 text-center">
            <div class="w-14 h-14 bg-red-100 text-red-500 rounded-full flex items-center justify-center text-2xl mx-auto mb-3"><i class="fas fa-exclamation-triangle"></i></div>
            <h3 class="text-lg font-black text-slate-800 mb-2">সতর্কতা!</h3>
            <p class="text-[11px] font-bold text-slate-500 mb-5 leading-relaxed">এই শেয়ারটি ডিলিট করলে তা আর ফেরত পাওয়া যাবে না। নিশ্চিত করতে অ্যাডমিন পিন দিন।</p>
            <form method="POST">
                <input type="hidden" name="action" value="delete_share">
                <input type="hidden" name="share_id" id="delete_share_id">
                <input type="password" name="secret_pin" placeholder="অ্যাডমিন PIN..." class="w-full px-4 py-3 border border-red-200 rounded-xl mb-5 focus:border-red-500 focus:ring-1 focus:ring-red-500 outline-none bg-red-50/50 text-center font-bold tracking-[0.5em] text-red-700" required>
                <div class="flex gap-2">
                    <button type="button" onclick="closeModals()" class="flex-1 py-2.5 bg-slate-100 text-slate-600 rounded-xl font-bold hover:bg-slate-200 transition text-sm">বাতিল</button>
                    <button type="submit" class="flex-1 py-2.5 bg-red-600 text-white rounded-xl font-bold hover:bg-red-700 shadow-md transition text-sm">ডিলিট</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleRow(id) { 
            const row = document.getElementById(id);
            if(row.classList.contains('hidden')) {
                row.classList.remove('hidden');
                row.classList.add('animate-fade-in');
            } else {
                row.classList.add('hidden');
                row.classList.remove('animate-fade-in');
            }
        }
        
        function openEditAccModal(id, name, user, phone, profile_pic) {
            document.getElementById('edit_acc_id').value = id;
            document.getElementById('edit_acc_name').value = name;
            document.getElementById('edit_acc_user').value = user;
            document.getElementById('edit_acc_phone').value = phone || '';
            document.getElementById('edit_acc_pass').value = '';
            
            const previewImg = document.getElementById('adminAccProfilePreview');
            const previewIcon = document.getElementById('adminAccProfileIcon');
            
            if(profile_pic && profile_pic !== '') {
                previewImg.src = '../' + profile_pic; 
                previewImg.classList.remove('hidden');
                previewIcon.classList.add('hidden');
            } else {
                previewImg.src = '';
                previewImg.classList.add('hidden');
                previewIcon.classList.remove('hidden');
            }
            
            document.getElementById('editAccModal').classList.remove('hidden');
        }

        function previewAdminAccImage(input) {
            if (input.files && input.files[0]) {
                var reader = new FileReader();
                reader.onload = function(e) {
                    var preview = document.getElementById('adminAccProfilePreview');
                    var icon = document.getElementById('adminAccProfileIcon');
                    icon.classList.add('hidden');
                    preview.classList.remove('hidden');
                    preview.src = e.target.result;
                }
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        function toggleEditShareField() {
            var projSelect = document.getElementById('edit_share_proj');
            var selectedOption = projSelect.options[projSelect.selectedIndex];
            var distType = selectedOption.getAttribute('data-dist');
            
            var shareDiv = document.getElementById('edit_share_input_div');
            var shareInput = document.getElementById('edit_share_num');
            var invDiv = document.getElementById('edit_investment_input_div');

            if (distType === 'by_investment') {
                shareDiv.style.display = 'none';
                shareInput.required = false;
                shareInput.value = 0; 
            } else {
                shareDiv.style.display = 'block';
                shareInput.required = true;
            }
        }
        
        function openEditShareModal(id, shares, inv, type, proj, dead, slots) {
            document.getElementById('edit_share_id').value = id;
            document.getElementById('edit_share_num').value = shares;
            document.getElementById('edit_share_inv').value = inv;
            document.getElementById('edit_share_slots').value = slots;
            document.getElementById('edit_share_type').value = (type == 'active') ? 'active_money' : type;
            document.getElementById('edit_share_proj').value = proj;
            document.getElementById('edit_share_dead').value = dead;
            
            toggleEditShareField();
            document.getElementById('editShareModal').classList.remove('hidden');
        }
        
        function openDeleteShareModal(id) {
            document.getElementById('delete_share_id').value = id;
            document.getElementById('deleteShareModal').classList.remove('hidden');
        }
        
        function closeModals() {
            document.getElementById('editAccModal').classList.add('hidden');
            document.getElementById('editShareModal').classList.add('hidden');
            document.getElementById('deleteShareModal').classList.add('hidden');
            document.getElementById('slotSettingModal').classList.add('hidden');
        }

        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('-translate-x-full');
            document.getElementById('sidebar-overlay').classList.toggle('hidden');
        }
        
        // ==========================================
        // Print Agreement / Deed Logic
        // ==========================================
        function printDeed(userData) {
            document.querySelectorAll('.main-header, .main-dashboard-content, .app-card, .bottom-nav, #sidebar, #sidebar-overlay').forEach(el => {
                if(el) el.classList.add('print:hidden');
            });

            document.getElementById('deedName').innerText = userData.name;
            document.getElementById('deedUser').innerText = userData.username;
            document.getElementById('deedPhone').innerText = userData.phone || 'N/A';
            
            document.getElementById('deedNameBn').innerText = userData.name;
            document.getElementById('deedUserBn').innerText = userData.username;
            document.getElementById('deedPhoneBn').innerText = userData.phone || 'N/A';
            
            const options = { year: 'numeric', month: 'long', day: 'numeric' };
            document.getElementById('deedDate').innerText = new Date().toLocaleDateString('en-GB', options);
            
            let tbody = '';
            let totalInv = 0;
            
            if(userData.shares && userData.shares.length > 0) {
                userData.shares.forEach((s, index) => {
                    let pName = s.project_name ? s.project_name : 'General Fund';
                    let sCount = parseInt(s.number_of_shares) > 0 ? s.number_of_shares : '-';
                    let slots = s.slot_numbers ? `<br><span style="font-size:8pt;color:#64748b;">Slot: ${s.slot_numbers}</span>` : '';
                    let inv = parseFloat(s.investment_credit);
                    totalInv += inv;
                    
                    let sType = '';
                    if(s.share_type === 'active_money') sType = 'Active (Money)';
                    else if(s.share_type === 'active_labor') sType = 'Active (Labor)';
                    else sType = 'Passive';
                    
                    tbody += `
                        <tr>
                            <td style="text-align:center; font-weight:bold;">${index + 1}</td>
                            <td style="font-weight:bold; color:#1e3a8a;">${pName}</td>
                            <td style="text-align:center;">${sType}</td>
                            <td style="text-align:center; font-weight:bold; color:#0f172a;">${sCount}${slots}</td>
                            <td style="text-align:right; font-weight:bold; color:#059669;">৳ ${inv.toLocaleString('en-IN', {minimumFractionDigits: 2})}</td>
                        </tr>
                    `;
                });
            } else {
                tbody = `<tr><td colspan="5" style="text-align:center; padding: 20px;">No investment data found.</td></tr>`;
            }
            
            tbody += `
                <tr style="background-color: #f8fafc;">
                    <td colspan="4" style="text-align:right; font-weight:900; padding-right:15px; text-transform:uppercase;">Total Investment (সর্বমোট বিনিয়োগ):</td>
                    <td style="text-align:right; font-weight:900; font-size:12pt; color:#1d4ed8;">৳ ${totalInv.toLocaleString('en-IN', {minimumFractionDigits: 2})}</td>
                </tr>
            `;
            
            document.getElementById('deedTableBody').innerHTML = tbody;
            
            document.getElementById('print_section_deed').classList.remove('hidden');
            
            let originalTitle = document.title;
            document.title = "Shareholder_Agreement_" + userData.username;
            
            setTimeout(() => { 
                window.print(); 
                document.title = originalTitle;
                document.getElementById('print_section_deed').classList.add('hidden');
                document.querySelectorAll('.main-header, .main-dashboard-content, .app-card, .bottom-nav, #sidebar').forEach(el => {
                    if(el) el.classList.remove('print:hidden');
                });
            }, 300);
        }
    </script>
</body>
</html>