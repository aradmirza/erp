<?php
// ২৪ ঘণ্টার জন্য সেশন ও কুকি সেট করা
ini_set('session.cookie_lifetime', 86400);
ini_set('session.gc_maxlifetime', 86400);
session_set_cookie_params(86400);
session_start();

// বাংলাদেশের টাইমজোন সেট করা
date_default_timezone_set('Asia/Dhaka');

if(!isset($_SESSION['admin_logged_in'])) { header("Location: login.php"); exit; }

// === Role-Based Access Control (RBAC) ===
if(isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'staff') {
    $perms = $_SESSION['staff_permissions'] ?? [];
    if(!in_array('manage_votes', $perms)) { 
        die("<div style='text-align:center; padding:50px; font-family:sans-serif;'><h2 style='color:red;'>Access Denied!</h2><p>আপনার এই পেজে প্রবেশের অনুমতি নেই।</p><a href='login.php'>লগআউট করুন</a></div>"); 
    }
}
// ==========================================

require_once 'db.php';

// ডাটাবেসে end_time কলাম যুক্ত করা (যদি না থাকে)
try { $pdo->exec("ALTER TABLE proposals ADD COLUMN IF NOT EXISTS end_time DATETIME DEFAULT NULL"); } catch (PDOException $e) {}

// মেয়াদোত্তীর্ণ প্রস্তাবনাগুলো অটোমেটিক 'closed' করে দেওয়া
try { $pdo->exec("UPDATE proposals SET status = 'closed' WHERE status = 'approved' AND end_time IS NOT NULL AND end_time <= NOW()"); } catch (PDOException $e) {}

// মতামতের (Comments) জন্য টেবিল তৈরি
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `proposal_comments` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `proposal_id` int(11) NOT NULL,
      `account_id` int(11) NOT NULL,
      `comment` text NOT NULL,
      `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    )");
} catch (PDOException $e) {}

// সেশন থেকে মেসেজ ফেচ করা 
$message = $_SESSION['msg_success'] ?? ''; 
$error = $_SESSION['msg_error'] ?? '';
unset($_SESSION['msg_success'], $_SESSION['msg_error']);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // প্রস্তাবনার স্ট্যাটাস ও টাইমার আপডেট
    if (isset($_POST['action']) && $_POST['action'] == 'update_proposal') {
        $proposal_id = (int)$_POST['proposal_id'];
        $status = $_POST['status'];
        $duration = (int)$_POST['duration_minutes'];
        
        try {
            $current = $pdo->query("SELECT status FROM proposals WHERE id = $proposal_id")->fetchColumn();
            
            if($status == 'approved' && $duration > 0) {
                if($current != 'approved') {
                    $stmt = $pdo->prepare("UPDATE proposals SET status = ?, end_time = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ?");
                    $stmt->execute([$status, $duration, $proposal_id]);
                    $_SESSION['msg_success'] = "ভোটিং শুরু হয়েছে! সময় নির্ধারণ করা হয়েছে $duration মিনিট।";
                } else {
                    $_SESSION['msg_success'] = "ভোটিং ইতিমধ্যে চলমান রয়েছে।";
                }
            } else {
                $stmt = $pdo->prepare("UPDATE proposals SET status = ?, end_time = NULL WHERE id = ?");
                $stmt->execute([$status, $proposal_id]);
                $_SESSION['msg_success'] = "প্রস্তাবনার স্ট্যাটাস আপডেট করা হয়েছে!";
            }
        } catch(PDOException $e) { $_SESSION['msg_error'] = "স্ট্যাটাস আপডেট করতে সমস্যা হয়েছে!"; }
        
        header("Location: manage_votes.php"); exit;
    }
    
    // অ্যাডমিন হিসেবে মতামত (Comment) সাবমিট
    if (isset($_POST['action']) && $_POST['action'] == 'admin_submit_comment') {
        $proposal_id = (int)$_POST['proposal_id'];
        $comment_text = trim($_POST['comment_text']);
        
        if (!empty($comment_text)) {
            try {
                // অ্যাডমিনের account_id 0 হিসেবে সেভ করা হবে
                $stmt = $pdo->prepare("INSERT INTO proposal_comments (proposal_id, account_id, comment) VALUES (?, 0, ?)");
                if($stmt->execute([$proposal_id, $comment_text])) {
                    $_SESSION['msg_success'] = "আপনার মতামত যুক্ত করা হয়েছে!";
                }
            } catch(PDOException $e) { 
                $_SESSION['msg_error'] = "মতামত যোগ করতে সমস্যা হয়েছে।"; 
            }
        }
        header("Location: manage_votes.php"); exit;
    }

    // গোপন ভোট সেটিং আপডেট (Secret Ballot Toggle)
    if (isset($_POST['action']) && $_POST['action'] == 'toggle_secret') {
        $proposal_id = (int)$_POST['proposal_id'];
        $is_secret = isset($_POST['is_secret']) ? 1 : 0;
        try {
            try { $pdo->exec("ALTER TABLE proposals ADD COLUMN IF NOT EXISTS is_secret TINYINT(1) DEFAULT 0"); } catch(PDOException $e){}
            
            $pdo->prepare("UPDATE proposals SET is_secret = ? WHERE id = ?")->execute([$is_secret, $proposal_id]);
            $_SESSION['msg_success'] = "গোপন ভোটের সেটিং আপডেট হয়েছে!";
        } catch(PDOException $e) { 
            $_SESSION['msg_error'] = "আপডেট করতে সমস্যা হয়েছে!"; 
        }
        header("Location: manage_votes.php"); exit;
    }

    // ভোট পারমিশন আপডেট
    if (isset($_POST['action']) && $_POST['action'] == 'update_permission') {
        $account_id = (int)$_POST['account_id'];
        $can_vote = isset($_POST['can_vote']) ? 1 : 0;
        try {
            $stmt = $pdo->prepare("UPDATE shareholder_accounts SET can_vote = ? WHERE id = ?");
            if($stmt->execute([$can_vote, $account_id])) $_SESSION['msg_success'] = "ভোটিং পারমিশন আপডেট হয়েছে!";
        } catch(PDOException $e) { $_SESSION['msg_error'] = "পারমিশন আপডেট করতে সমস্যা হয়েছে!"; }
        
        header("Location: manage_votes.php"); exit;
    }

    // অ্যাডমিন দ্বারা ভোট ডিলিট
    if (isset($_POST['action']) && $_POST['action'] == 'delete_proposal') {
        $proposal_id = (int)$_POST['proposal_id'];
        try {
            $pdo->prepare("DELETE FROM proposals WHERE id = ?")->execute([$proposal_id]);
            $pdo->prepare("DELETE FROM votes WHERE proposal_id = ?")->execute([$proposal_id]);
            $pdo->prepare("DELETE FROM proposal_comments WHERE proposal_id = ?")->execute([$proposal_id]);
            $_SESSION['msg_success'] = "প্রস্তাবনাটি সফলভাবে ডিলিট করা হয়েছে!";
        } catch(PDOException $e) { 
            $_SESSION['msg_error'] = "ডিলিট করতে সমস্যা হয়েছে!"; 
        }
        header("Location: manage_votes.php"); exit;
    }
}

// ওয়েবসাইট লোগো ও ফেভিকন ফেচ করা (সাইডবারের জন্য)
$site_settings_stmt = $pdo->query("SELECT setting_name, setting_value FROM system_settings WHERE setting_name IN ('site_logo', 'site_favicon')");
$site_settings = $site_settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$site_logo = $site_settings['site_logo'] ?? '';
$site_favicon = $site_settings['site_favicon'] ?? '';

$proposals = $pdo->query("SELECT p.*, a.name, a.username, a.profile_picture FROM proposals p JOIN shareholder_accounts a ON p.account_id = a.id ORDER BY p.created_at DESC")->fetchAll();
$accounts = $pdo->query("SELECT id, name, username, profile_picture, can_vote FROM shareholder_accounts ORDER BY name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Manage Votes - Sodai Lagbe Admin</title>
    
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
        
        .glass-header { background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(12px); border-bottom: 1px solid rgba(226,232,240,0.8); }
        .app-card { background: #ffffff; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid rgba(226, 232, 240, 0.8); }
        .custom-scrollbar::-webkit-scrollbar { width: 4px; height: 4px; } 
        .custom-scrollbar::-webkit-scrollbar-thumb { background-color: #cbd5e1; border-radius: 10px; } 
        
        /* Custom Toggle Switch CSS */
        .toggle-checkbox:checked { right: 0; border-color: #10B981; }
        .toggle-checkbox:checked + .toggle-label { background-color: #10B981; }
        .toggle-checkbox { right: 0; z-index: 1; border-color: #e2e8f0; transition: all 0.3s ease; }
        .toggle-label { background-color: #e2e8f0; transition: all 0.3s ease; }
        
        /* Mobile Bottom Nav */
        .bottom-nav { position: fixed; bottom: 0; left: 0; right: 0; background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px); border-top: 1px solid rgba(0,0,0,0.05); padding-bottom: env(safe-area-inset-bottom); z-index: 50; display: flex; justify-content: space-around; }
        .nav-item { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 10px 0; color: #64748b; font-size: 10px; font-weight: 700; width: 100%; transition: all 0.2s; text-decoration: none;}
        .nav-item.active { color: #2563eb; }
        .nav-item i { font-size: 18px; margin-bottom: 3px; transition: transform 0.2s;}
        .nav-item.active i { transform: translateY(-2px); }
        @media (min-width: 768px) { .bottom-nav { display: none; } }
        
        @keyframes fadeInFast { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fade-in { animation: fadeInFast 0.4s ease-out forwards; }
        
        /* ==========================================
           PRINT STYLES - FIXED TO WORK PERFECTLY
           ========================================== */
        @media print {
            @page { size: A4 portrait; margin: 15mm; }
            html, body { 
                height: auto !important; 
                min-height: auto !important; 
                overflow: visible !important; 
                background-color: white !important; 
                color: #000 !important;
                margin: 0 !important; 
                padding: 0 !important;
            }
            
            /* Hide ALL UI Elements */
            #sidebar, #sidebar-overlay, header, .bottom-nav, main, .modal, .glass-header { 
                display: none !important; 
            }
            
            /* Show ONLY the print wrapper */
            #main_print_wrapper { 
                display: block !important; 
                visibility: visible !important;
                position: static !important; 
                width: 100% !important;
            }
            
            /* Print Specific Typography & Layout */
            .print-header { text-align: center; border-bottom: 2px solid #1e3a8a; padding-bottom: 10px; margin-bottom: 20px; }
            .print-header h1 { font-size: 28pt; font-weight: 900; color: #1e3a8a !important; text-transform: uppercase; margin:0; -webkit-print-color-adjust: exact;}
            .print-header p { font-size: 13pt; font-weight: bold; margin: 5px 0 0 0; color: #475569 !important; -webkit-print-color-adjust: exact;}
            
            .print-table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 11pt; }
            .print-table th, .print-table td { border: 1px solid #475569 !important; padding: 10px; text-align: left; }
            .print-table th { background-color: #f1f5f9 !important; -webkit-print-color-adjust: exact; }
            .page-break { page-break-inside: avoid; }
        }
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
            <a href="manage_shareholders.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-users w-6"></i> শেয়ারহোল্ডার লিস্ট</a>
            <a href="add_shareholder.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-user-plus w-6"></i> অ্যাকাউন্ট তৈরি</a>
            <a href="manage_projects.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-project-diagram w-6"></i> প্রজেক্ট লিস্ট</a>
            <a href="add_project.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-plus-square w-6"></i> নতুন প্রজেক্ট</a>
            <a href="manage_staff.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-users-cog w-6"></i> স্টাফ ম্যানেজমেন্ট</a>
            <a href="manage_kpi.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-bullseye w-6"></i> KPI ম্যানেজমেন্ট</a>
            <a href="manage_votes.php" class="flex items-center px-6 py-3 bg-blue-600/20 text-blue-400 border-l-4 border-blue-500 font-semibold"><i class="fas fa-vote-yea w-6"></i> ভোটিং ও প্রস্তাবনা</a>
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
                <h2 class="text-lg font-black tracking-tight text-slate-800 hidden sm:block">ভোটিং ও প্রস্তাবনা</h2>
                <h2 class="text-lg font-black tracking-tight text-slate-800 sm:hidden">ভোটিং প্যানেল</h2>
            </div>
            <div class="flex items-center gap-3">
                <div class="text-right hidden sm:block border-r border-slate-200 pr-3">
                    <div class="text-sm font-bold leading-tight"><?= htmlspecialchars($_SESSION['admin_username'] ?? 'Admin') ?></div>
                    <div class="text-[10px] text-slate-500 font-bold uppercase tracking-wide">System Admin</div>
                </div>
                <div class="h-9 w-9 bg-gradient-to-br from-blue-600 to-indigo-700 rounded-full flex items-center justify-center text-white font-black shadow-md border border-white">
                    <?= strtoupper(substr($_SESSION['admin_username'] ?? 'A', 0, 1)) ?>
                </div>
            </div>
        </header>

        <main class="flex-1 overflow-x-hidden overflow-y-auto p-4 md:p-6 custom-scrollbar pb-24 md:pb-6 relative bg-slate-50">
            
            <div class="max-w-6xl mx-auto space-y-6">
                
                <?php if($message): ?><div class="bg-emerald-50 text-emerald-600 px-4 py-3 rounded-xl text-sm font-bold shadow-sm border border-emerald-100 flex items-center animate-fade-in"><i class="fas fa-check-circle mr-2 text-lg"></i> <?= $message ?></div><?php endif; ?>
                <?php if($error): ?><div class="bg-red-50 text-red-600 px-4 py-3 rounded-xl text-sm font-bold shadow-sm border border-red-100 flex items-center animate-fade-in"><i class="fas fa-exclamation-triangle mr-2 text-lg"></i> <?= $error ?></div><?php endif; ?>

                <div class="flex flex-wrap gap-4 justify-between items-center animate-fade-in">
                    <div>
                        <h2 class="text-xl md:text-2xl font-black text-slate-800 flex items-center gap-2"><i class="fas fa-vote-yea text-blue-500"></i> প্রস্তাবনা ও ভোটিং কন্ট্রোল</h2>
                        <p class="text-xs text-slate-500 mt-1 font-bold">শেয়ারহোল্ডারদের প্রস্তাবনাগুলো পরিচালনা করুন এবং ভোটিং স্ট্যাটাস নিয়ন্ত্রণ করুন</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    
                    <div class="lg:col-span-2 space-y-6">
                        <?php foreach($proposals as $p): 
                            // Fetch votes
                            $votes_stmt = $pdo->query("
                                SELECT v.vote, a.name 
                                FROM votes v 
                                JOIN shareholder_accounts a ON v.account_id = a.id 
                                WHERE v.proposal_id = {$p['id']}
                                ORDER BY a.name ASC
                            ");
                            
                            $vote_counts = []; $voters_list = []; $participants = []; $all_voter_names = []; 
                            $total_votes = 0;
                            
                            while($vr = $votes_stmt->fetch()) {
                                $vote_option = $vr['vote'];
                                if(!isset($vote_counts[$vote_option])) $vote_counts[$vote_option] = 0;
                                $vote_counts[$vote_option]++;
                                $voters_list[$vote_option][] = $vr['name'];
                                $participants[] = ['name' => $vr['name'], 'vote' => $vote_option];
                                $all_voter_names[] = $vr['name'];
                                $total_votes++;
                            }
                            
                            // Fetch comments
                            $p_comments_stmt = $pdo->query("
                                SELECT pc.*, IF(pc.account_id=0, 'System Admin', a.name) as name, IF(pc.account_id=0, '', a.profile_picture) as profile_picture 
                                FROM proposal_comments pc 
                                LEFT JOIN shareholder_accounts a ON pc.account_id = a.id 
                                WHERE pc.proposal_id = {$p['id']} 
                                ORDER BY pc.created_at ASC
                            ");
                            $p_comments = $p_comments_stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            $custom_options = json_decode($p['options'], true);
                            
                            // Timer Calculation
                            $remaining_seconds = 0;
                            if($p['status'] == 'approved' && $p['end_time']) {
                                $remaining_seconds = strtotime($p['end_time']) - time();
                            }

                            // Status Logic
                            $status_html = ''; $formal_result = ''; 
                            if($p['status'] == 'pending') { 
                                $status_html = '<span class="bg-amber-100 text-amber-700 px-2.5 py-1 rounded-md text-[10px] font-black uppercase tracking-widest border border-amber-200 shadow-sm"><i class="fas fa-clock mr-1"></i> Pending</span>'; 
                                $formal_result = 'ভোটিং প্রক্রিয়া এখনো শুরু হয়নি।';
                            }
                            elseif($p['status'] == 'approved') { 
                                $status_html = '<span class="bg-emerald-100 text-emerald-700 px-2.5 py-1 rounded-md text-[10px] font-black uppercase tracking-widest border border-emerald-200 shadow-sm animate-pulse"><i class="fas fa-broadcast-tower mr-1"></i> Voting Live</span>'; 
                                $formal_result = 'ভোটিং প্রক্রিয়া চলমান রয়েছে।';
                            }
                            elseif($p['status'] == 'rejected') { 
                                $status_html = '<span class="bg-rose-100 text-rose-700 px-2.5 py-1 rounded-md text-[10px] font-black uppercase tracking-widest border border-rose-200 shadow-sm"><i class="fas fa-ban mr-1"></i> Rejected</span>'; 
                                $formal_result = 'পরিচালনা পর্ষদ কর্তৃক প্রস্তাবনাটি বাতিল করা হয়েছে।';
                            }
                            elseif($p['status'] == 'closed') { 
                                if(is_array($custom_options) && count($custom_options) > 0) {
                                    $max_vote = 0; $winner_opt = '';
                                    foreach($custom_options as $opt) {
                                        $v_cnt = $vote_counts[$opt] ?? 0;
                                        if($v_cnt > $max_vote) { $max_vote = $v_cnt; $winner_opt = $opt; }
                                    }
                                    if($total_votes > 0) {
                                        $status_html = '<span class="bg-blue-100 text-blue-700 px-2.5 py-1 rounded-md text-[10px] font-black uppercase tracking-widest border border-blue-200 shadow-sm"><i class="fas fa-trophy mr-1 text-amber-500"></i> Winner: '.htmlspecialchars($winner_opt).'</span>';
                                        $formal_result = "সংখ্যাগরিষ্ঠ ভোটের ভিত্তিতে '<b>" . htmlspecialchars($winner_opt) . "</b>' অপশনটি চূড়ান্তভাবে নির্বাচিত হয়েছে।";
                                    } else {
                                        $status_html = '<span class="bg-slate-200 text-slate-700 px-2.5 py-1 rounded-md text-[10px] font-black uppercase tracking-widest border border-slate-300 shadow-sm">No Votes</span>';
                                        $formal_result = "কোনো ভোট না পড়ায় কোনো সিদ্ধান্ত নেওয়া হয়নি।";
                                    }
                                } else {
                                    $yes_pct = $total_votes > 0 ? (($vote_counts['yes'] ?? 0) / $total_votes) * 100 : 0;
                                    if($yes_pct > 50) { 
                                        $status_html = '<span class="bg-blue-100 text-blue-700 px-2.5 py-1 rounded-md text-[10px] font-black uppercase tracking-widest border border-blue-200 shadow-sm"><i class="fas fa-check-double mr-1 text-emerald-500"></i> Passed</span>'; 
                                        $formal_result = "সংখ্যাগরিষ্ঠ ভোটের ভিত্তিতে প্রস্তাবটি <b>গৃহীত (Passed)</b> হয়েছে।";
                                    }
                                    else { 
                                        $status_html = '<span class="bg-slate-200 text-slate-700 px-2.5 py-1 rounded-md text-[10px] font-black uppercase tracking-widest border border-slate-300 shadow-sm"><i class="fas fa-times mr-1 text-rose-500"></i> Failed</span>'; 
                                        $formal_result = "পর্যাপ্ত ভোট না পাওয়ায় প্রস্তাবটি <b>বাতিল (Failed)</b> হয়েছে।";
                                    }
                                }
                            }
                        ?>
                        
                        <div class="app-card bg-white hover:border-blue-300 transition-colors shadow-sm group">
                            
                            <div class="bg-slate-50/50 px-5 py-4 border-b border-slate-100 flex flex-wrap justify-between items-center gap-3">
                                <div class="flex items-center gap-3 min-w-0">
                                    <div class="w-10 h-10 rounded-full border-2 border-slate-100 shadow-sm overflow-hidden shrink-0 flex items-center justify-center bg-white font-black text-slate-400 text-xs">
                                        <?php if(!empty($p['profile_picture'])): ?>
                                            <img src="../<?= htmlspecialchars($p['profile_picture']) ?>" class="w-full h-full object-cover">
                                        <?php else: ?>
                                            <?= strtoupper(substr($p['name'], 0, 1)) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="truncate pr-2">
                                        <div class="font-black text-slate-800 text-sm leading-tight mb-0.5 truncate group-hover:text-blue-600 transition-colors" title="<?= htmlspecialchars($p['title']) ?>"><?= htmlspecialchars($p['name']) ?></div>
                                        <div class="text-[9px] font-bold text-slate-400 uppercase tracking-widest"><i class="far fa-calendar-alt mr-1"></i><?= date('d M Y, h:i A', strtotime($p['created_at'])) ?></div>
                                    </div>
                                </div>
                                
                                <div class="flex items-center gap-2">
                                    <form method="POST" class="m-0 flex items-center bg-white rounded-lg px-3 py-1.5 border border-slate-200 shadow-sm" title="গোপন ভোট (Secret Ballot)">
                                        <input type="hidden" name="action" value="toggle_secret">
                                        <input type="hidden" name="proposal_id" value="<?= $p['id'] ?>">
                                        <span class="text-[10px] font-black text-slate-600 mr-2 uppercase tracking-wide"><i class="fas fa-user-secret text-indigo-400 mr-1"></i> Secret</span>
                                        <div class="relative inline-block w-8 h-4 align-middle select-none mt-0.5">
                                            <input type="checkbox" name="is_secret" id="sec_<?= $p['id'] ?>" value="1" <?= !empty($p['is_secret']) ? 'checked' : '' ?> onchange="this.form.submit()" class="toggle-checkbox absolute block w-4 h-4 rounded-full bg-white border-2 border-slate-300 appearance-none cursor-pointer shadow-sm transition-all"/>
                                            <label for="sec_<?= $p['id'] ?>" class="toggle-label block overflow-hidden h-4 rounded-full bg-slate-300 cursor-pointer shadow-inner transition-all"></label>
                                        </div>
                                    </form>
                                    
                                    <button onclick="printResolution(<?= $p['id'] ?>)" class="text-blue-600 hover:text-white hover:bg-blue-600 w-8 h-8 rounded-lg flex items-center justify-center transition shadow-sm bg-white border border-blue-200" title="রেজোলিউশন প্রিন্ট করুন">
                                        <i class="fas fa-print text-xs"></i>
                                    </button>
                                    
                                    <button onclick="openDeleteModal(<?= $p['id'] ?>)" class="text-rose-500 hover:text-white hover:bg-rose-500 w-8 h-8 rounded-lg flex items-center justify-center transition shadow-sm bg-white border border-rose-200" title="প্রস্তাবনাটি ডিলিট করুন">
                                        <i class="fas fa-trash-alt text-xs"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="p-5">
                                <div class="flex justify-between items-start mb-3 gap-3">
                                    <h4 class="text-base font-black text-slate-800 leading-tight flex-1"><?= htmlspecialchars($p['title']) ?></h4>
                                    <div class="shrink-0"><?= $status_html ?></div>
                                </div>
                                
                                <p class="text-xs text-slate-600 leading-relaxed mb-5 bg-slate-50 p-4 rounded-xl border border-slate-100 shadow-inner">
                                    <?= nl2br(htmlspecialchars($p['description'])) ?>
                                </p>
                                
                                <?php if($p['status'] == 'approved' && $p['end_time']): ?>
                                    <div class="mb-5 bg-rose-50 text-rose-600 border border-rose-100 rounded-xl p-3 text-center text-xs font-black flex items-center justify-center gap-2 shadow-sm">
                                        <i class="fas fa-hourglass-half animate-pulse text-rose-400"></i> <span class="countdown-timer tracking-wide" data-remaining="<?= $remaining_seconds ?>">লোড হচ্ছে...</span>
                                    </div>
                                <?php endif; ?>

                                <div class="mb-5 bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
                                    <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4 flex items-center justify-between border-b border-slate-100 pb-2">
                                        <span><i class="fas fa-chart-pie text-indigo-400 mr-1"></i> ভোটের ফলাফল</span>
                                        <span class="bg-indigo-50 text-indigo-600 px-2 py-0.5 rounded shadow-sm border border-indigo-100">Total Votes: <?= $total_votes ?></span>
                                    </div>
                                    
                                    <?php if(is_array($custom_options) && count($custom_options) > 0): ?>
                                        <div class="space-y-3">
                                            <?php foreach($custom_options as $opt): 
                                                $v_cnt = $vote_counts[$opt] ?? 0; 
                                                $v_pct = $total_votes > 0 ? ($v_cnt / $total_votes) * 100 : 0;
                                            ?>
                                                <div>
                                                    <div class="flex justify-between text-[10px] font-bold mb-1.5 text-slate-600">
                                                        <span class="uppercase tracking-wide"><?= htmlspecialchars($opt) ?></span>
                                                        <span class="text-indigo-600 font-black"><?= round($v_pct) ?>% (<?= $v_cnt ?>)</span>
                                                    </div>
                                                    <div class="w-full bg-slate-100 rounded-full h-2 overflow-hidden shadow-inner flex">
                                                        <div class="h-full rounded-full transition-all duration-1000 bg-indigo-500" style="width: <?= $v_pct ?>%"></div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php if(empty($p['is_secret'])): ?>
                                            <div class="mt-4 text-[10px] text-slate-500 leading-relaxed bg-slate-50 p-2.5 rounded-lg border border-slate-100">
                                                <div class="font-black text-slate-700 uppercase tracking-wide mb-1"><i class="fas fa-users text-slate-400 mr-1"></i> কে কোন ভোট দিয়েছেন:</div>
                                                <?php foreach($custom_options as $opt): $voters_str = isset($voters_list[$opt]) ? implode(', ', $voters_list[$opt]) : 'কেউ দেয়নি'; ?>
                                                    <div class="mb-0.5"><b class="text-indigo-600"><?= htmlspecialchars($opt) ?>:</b> <?= htmlspecialchars($voters_str) ?></div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php 
                                            $yes_v = $vote_counts['yes'] ?? 0; $no_v = $vote_counts['no'] ?? 0;
                                            $yes_p = $total_votes > 0 ? ($yes_v / $total_votes) * 100 : 0; 
                                            $no_p = $total_votes > 0 ? ($no_v / $total_votes) * 100 : 0;
                                            $yes_voters_str = isset($voters_list['yes']) ? implode(', ', $voters_list['yes']) : 'কেউ দেয়নি';
                                            $no_voters_str = isset($voters_list['no']) ? implode(', ', $voters_list['no']) : 'কেউ দেয়নি';
                                        ?>
                                        <div class="flex justify-between text-[10px] font-black mb-2 px-1 uppercase tracking-wide">
                                            <span class="text-emerald-600 flex items-center gap-1"><i class="fas fa-check"></i> হ্যাঁ (<?= $yes_v ?>)</span>
                                            <span class="text-rose-500 flex items-center gap-1">না (<?= $no_v ?>) <i class="fas fa-times"></i></span>
                                        </div>
                                        <div class="w-full bg-slate-100 rounded-full h-2.5 flex overflow-hidden shadow-inner border border-slate-200 mb-3">
                                            <div class="bg-emerald-500 h-full transition-all duration-1000" style="width: <?= $yes_p ?>%"></div>
                                            <div class="bg-rose-500 h-full transition-all duration-1000" style="width: <?= $no_p ?>%"></div>
                                        </div>
                                        <?php if(empty($p['is_secret'])): ?>
                                            <div class="text-[10px] bg-slate-50 p-2.5 rounded-lg border border-slate-100 leading-relaxed">
                                                <div class="text-emerald-700 mb-1"><i class="fas fa-check-circle mr-1 text-emerald-400"></i> <b>হ্যাঁ:</b> <span class="text-slate-600"><?= htmlspecialchars($yes_voters_str) ?></span></div>
                                                <div class="text-rose-700"><i class="fas fa-times-circle mr-1 text-rose-400"></i> <b>না:</b> <span class="text-slate-600"><?= htmlspecialchars($no_voters_str) ?></span></div>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <?php if(!empty($p['is_secret'])): ?>
                                        <div class="mt-3 text-[11px] bg-indigo-50 p-3 rounded-lg border border-indigo-100 leading-relaxed">
                                            <div class="text-indigo-700 font-black mb-1 uppercase tracking-wide"><i class="fas fa-user-secret mr-1"></i> ভোট গোপন রাখা হয়েছে</div>
                                            <div class="text-slate-600"><b>অংশগ্রহণ করেছেন:</b> <?= !empty($all_voter_names) ? htmlspecialchars(implode(', ', $all_voter_names)) : 'কেউ দেয়নি' ?></div>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <form method="POST" class="bg-slate-50 p-4 rounded-xl border border-slate-200 flex flex-col sm:flex-row items-end sm:items-center gap-3 shadow-sm mb-5">
                                    <input type="hidden" name="action" value="update_proposal">
                                    <input type="hidden" name="proposal_id" value="<?= $p['id'] ?>">
                                    
                                    <div class="flex-1 w-full">
                                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1.5">অ্যাকশন পরিবর্তন করুন:</label>
                                        <select name="status" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 bg-white font-bold text-slate-700 shadow-sm cursor-pointer transition" onchange="toggleDurationInput(this, <?= $p['id'] ?>)">
                                            <option value="pending" <?= $p['status'] == 'pending' ? 'selected' : '' ?>>🟡 পেন্ডিং (ভোটিং বন্ধ)</option>
                                            <option value="approved" <?= $p['status'] == 'approved' ? 'selected' : '' ?>>🟢 অ্যাপ্রুভ (ভোটিং চালু করুন)</option>
                                            <option value="closed" <?= $p['status'] == 'closed' ? 'selected' : '' ?>>⚪ ভোট গ্রহণ শেষ (ফলাফল দেখাবে)</option>
                                            <option value="rejected" <?= $p['status'] == 'rejected' ? 'selected' : '' ?>>🔴 প্রস্তাবনা বাতিল করুন</option>
                                        </select>
                                    </div>
                                    <div id="duration_box_<?= $p['id'] ?>" class="w-full sm:w-32 <?= $p['status'] == 'approved' ? '' : 'hidden' ?>">
                                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1.5">সময় (মিনিট):</label>
                                        <input type="number" name="duration_minutes" class="w-full border border-slate-200 rounded-lg px-3 py-2.5 text-sm font-black text-slate-700 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 shadow-sm transition" placeholder="মিনিট" min="1">
                                    </div>
                                    <button type="submit" class="bg-blue-600 text-white font-bold py-2.5 px-6 rounded-lg shadow-md hover:bg-blue-700 transition w-full sm:w-auto h-[42px] mt-auto flex items-center justify-center gap-1.5 active:scale-95">
                                        <i class="fas fa-save"></i> সেভ
                                    </button>
                                </form>
                                
                                <div class="border-t border-slate-100 pt-4">
                                    <button type="button" onclick="document.getElementById('prop_comments_<?= $p['id'] ?>').classList.toggle('hidden')" class="text-[10px] font-black text-slate-500 uppercase tracking-widest flex items-center gap-1.5 hover:text-blue-600 transition bg-white px-3 py-1.5 rounded-lg border border-slate-200 shadow-sm">
                                        <i class="fas fa-comments text-blue-500"></i> মতামত ও আলোচনা (<?= count($p_comments) ?>) <i class="fas fa-angle-down ml-1"></i>
                                    </button>
                                    
                                    <div id="prop_comments_<?= $p['id'] ?>" class="hidden mt-4">
                                        <div class="space-y-3 mb-4 max-h-40 overflow-y-auto custom-scrollbar pr-2">
                                            <?php foreach($p_comments as $pc): 
                                                $is_admin = $pc['account_id'] == 0;
                                            ?>
                                                <div class="bg-slate-50 p-3 rounded-xl border <?= $is_admin ? 'border-indigo-200 bg-indigo-50/50 shadow-sm' : 'border-slate-100' ?> flex gap-3">
                                                    <div class="w-8 h-8 rounded-full border border-slate-200 bg-white flex items-center justify-center font-bold text-[10px] text-slate-400 shrink-0 overflow-hidden">
                                                        <?php if($is_admin): ?>
                                                            <i class="fas fa-user-shield text-indigo-500"></i>
                                                        <?php elseif(!empty($pc['profile_picture'])): ?>
                                                            <img src="../<?= htmlspecialchars($pc['profile_picture']) ?>" class="w-full h-full object-cover">
                                                        <?php else: ?>
                                                            <?= strtoupper(substr($pc['name'], 0, 1)) ?>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="flex-1">
                                                        <div class="flex justify-between items-baseline mb-1">
                                                            <span class="text-[11px] font-black <?= $is_admin ? 'text-indigo-700' : 'text-slate-700' ?>">
                                                                <?= htmlspecialchars($pc['name']) ?>
                                                            </span>
                                                            <span class="text-[9px] font-bold text-slate-400"><i class="far fa-clock mr-1"></i><?= date('d M, h:i A', strtotime($pc['created_at'])) ?></span>
                                                        </div>
                                                        <p class="text-xs text-slate-600 leading-relaxed"><?= nl2br(htmlspecialchars($pc['comment'])) ?></p>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                            <?php if(empty($p_comments)): ?>
                                                <p class="text-xs font-bold text-slate-400 italic text-center py-4 bg-slate-50 rounded-xl border border-dashed border-slate-200">এখনো কোনো মতামত নেই।</p>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <form method="POST" class="flex gap-2 relative">
                                            <input type="hidden" name="action" value="admin_submit_comment">
                                            <input type="hidden" name="proposal_id" value="<?= $p['id'] ?>">
                                            <input type="text" name="comment_text" class="flex-1 bg-white border border-slate-200 rounded-xl pl-4 pr-12 py-3 text-xs font-medium outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 shadow-sm transition" placeholder="অ্যাডমিন হিসেবে আপনার মতামত লিখুন..." required>
                                            <button type="submit" class="absolute right-2 top-1/2 -translate-y-1/2 w-8 h-8 bg-blue-50 text-blue-600 rounded-lg text-xs font-bold hover:bg-blue-600 hover:text-white shadow-sm transition flex items-center justify-center border border-blue-100"><i class="fas fa-paper-plane"></i></button>
                                        </form>
                                    </div>
                                </div>
                                
                            </div>
                        </div>
                        
                        <div id="print_data_<?= $p['id'] ?>" class="hidden">
                            <div class="print-header">
                                <h1>Sodai Lagbe</h1>
                                <p>শেয়ারহোল্ডার রেজোলিউশন ও ভোটিং রেকর্ড</p>
                            </div>
                            
                            <div style="font-size: 11.5pt; text-align: justify; margin-bottom: 25px; line-height: 1.6;">
                                এতদ্বারা <b>সদাই লাগবে</b>-এর সকল শেয়ারহোল্ডার ও পরিচালনা পর্ষদের অবগতির জন্য জানানো যাচ্ছে যে, গত <b><?= date('d M Y, h:i A', strtotime($p['created_at'])) ?></b> তারিখে শেয়ারহোল্ডার <b><?= htmlspecialchars($p['name']) ?></b> কর্তৃক একটি প্রস্তাবনা উত্থাপিত হয়। শেয়ারহোল্ডারগণের ভোটিং প্রক্রিয়া শেষে উক্ত প্রস্তাবনার চূড়ান্ত ফলাফল নিচে লিপিবদ্ধ করা হলো।
                            </div>

                            <table class="print-table" style="margin-bottom: 25px;">
                                <tr><th style="width: 25%;">প্রস্তাবের শিরোনাম</th><td style="font-weight: bold; font-size: 12pt;"><?= htmlspecialchars($p['title']) ?></td></tr>
                                <tr><th>বিস্তারিত বিবরণ</th><td><?= nl2br(htmlspecialchars($p['description'])) ?></td></tr>
                                <tr><th>উত্থাপক</th><td><?= htmlspecialchars($p['name']) ?></td></tr>
                                <tr><th>চূড়ান্ত ফলাফল</th><td style="font-weight: bold;"><?= $formal_result ?></td></tr>
                            </table>

                            <?php if(count($participants) > 0): ?>
                                <div style="font-size: 12pt; font-weight: bold; margin-bottom: 10px; border-bottom: 1px solid #000; display: inline-block;">অংশগ্রহণকারী শেয়ারহোল্ডারদের তালিকা ও স্বাক্ষর:</div>
                                <table class="print-table">
                                    <thead>
                                        <tr>
                                            <th style="width: 8%; text-align: center;">নং</th>
                                            <th style="width: 42%;">শেয়ারহোল্ডারের নাম</th>
                                            <th style="width: 25%; text-align: center;">প্রদত্ত ভোট</th>
                                            <th style="width: 25%; text-align: center;">স্বাক্ষর</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $sl = 1; foreach($participants as $part): ?>
                                            <tr class="page-break">
                                                <td style="text-align: center;"><?= $sl++ ?></td>
                                                <td style="font-weight: bold;"><?= htmlspecialchars($part['name']) ?></td>
                                                <td style="text-align: center; text-transform: capitalize;">
                                                    <?php if(!empty($p['is_secret'])): ?>
                                                        <span style="color: #64748b; font-style: italic;">গোপন</span>
                                                    <?php else: ?>
                                                        <?= htmlspecialchars($part['vote']) ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td></td> 
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <p style="font-style: italic; color: #555;">এই প্রস্তাবনায় কেউ ভোট প্রদান করেননি。</p>
                            <?php endif; ?>

                            <div style="margin-top: 80px; display: flex; justify-content: space-between; padding: 0 40px;">
                                <div style="text-align: center; border-top: 1px solid #000; width: 200px; padding-top: 5px; font-weight: bold;">সিস্টেম অ্যাডমিন</div>
                                <div style="text-align: center; border-top: 1px solid #000; width: 200px; padding-top: 5px; font-weight: bold;">পরিচালক / সিইও</div>
                            </div>
                            <div style="text-align: center; margin-top: 30px; font-size: 9pt; color: #777;">
                                প্রিন্ট ইস্যুর তারিখ: <?= date('d M Y, h:i A') ?>
                            </div>
                        </div>

                        <?php endforeach; if(empty($proposals)): ?>
                            <div class="app-card p-12 text-center bg-white border-dashed border-slate-300 animate-fade-in">
                                <i class="fas fa-inbox text-5xl mb-4 text-slate-300 block"></i>
                                <p class="font-bold text-sm text-slate-500">কোনো প্রস্তাবনা বা ভোটিং রেকর্ড নেই।</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="lg:col-span-1">
                        <div class="app-card bg-white sticky top-20 shadow-sm border border-slate-200 overflow-hidden animate-fade-in" style="animation-delay: 0.1s;">
                            <div class="px-5 py-4 bg-slate-800 flex justify-between items-center border-b border-slate-700">
                                <h3 class="text-sm font-black text-white flex items-center gap-2"><i class="fas fa-user-shield text-blue-400"></i> ভোটিং পারমিশন</h3>
                                <span class="bg-slate-700 text-white px-2 py-0.5 rounded text-[10px] font-bold"><?= count($accounts) ?> Users</span>
                            </div>
                            <div class="p-3 bg-slate-50 border-b border-slate-100">
                                <div class="relative">
                                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                                    <input type="text" id="searchInput" onkeyup="filterAccounts()" placeholder="নাম খুঁজুন..." class="w-full pl-9 pr-3 py-2 bg-white border border-slate-200 rounded-lg text-xs font-bold outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-200 transition shadow-sm">
                                </div>
                            </div>
                            <div class="max-h-[500px] overflow-y-auto custom-scrollbar p-2 space-y-1">
                                <?php foreach($accounts as $acc): ?>
                                <div class="account-item flex items-center justify-between p-2.5 rounded-lg hover:bg-slate-50 transition border border-transparent hover:border-slate-100">
                                    <div class="flex items-center gap-2.5 min-w-0">
                                        <div class="w-8 h-8 rounded-full border border-slate-200 bg-white flex items-center justify-center font-bold text-[10px] text-slate-400 shrink-0 overflow-hidden shadow-sm">
                                            <?php if(!empty($acc['profile_picture'])): ?>
                                                <img src="../<?= htmlspecialchars($acc['profile_picture']) ?>" class="w-full h-full object-cover">
                                            <?php else: ?>
                                                <?= strtoupper(substr($acc['name'], 0, 1)) ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="truncate">
                                            <div class="font-black text-xs text-slate-800 truncate leading-tight"><?= htmlspecialchars($acc['name']) ?></div>
                                            <div class="text-[9px] font-bold text-slate-400 truncate mt-0.5">@<?= htmlspecialchars($acc['username']) ?></div>
                                        </div>
                                    </div>
                                    <form method="POST" class="m-0 shrink-0 flex items-center">
                                        <input type="hidden" name="action" value="update_permission">
                                        <input type="hidden" name="account_id" value="<?= $acc['id'] ?>">
                                        <div class="relative inline-block w-9 h-5 align-middle select-none">
                                            <input type="checkbox" name="can_vote" id="tg_<?= $acc['id'] ?>" value="1" <?= $acc['can_vote'] ? 'checked' : '' ?> onchange="this.form.submit()" class="toggle-checkbox absolute block w-5 h-5 rounded-full bg-white border-2 appearance-none cursor-pointer shadow-sm"/>
                                            <label for="tg_<?= $acc['id'] ?>" class="toggle-label block overflow-hidden h-5 rounded-full cursor-pointer shadow-inner"></label>
                                        </div>
                                    </form>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <nav class="bottom-nav md:hidden shadow-[0_-5px_15px_rgba(0,0,0,0.05)] z-50">
        <a href="index.php" class="nav-item"><i class="fas fa-home"></i> Home</a>
        <a href="manage_shareholders.php" class="nav-item"><i class="fas fa-users"></i> Users</a>
        <a href="manage_votes.php" class="nav-item active"><i class="fas fa-vote-yea"></i> Vote</a>
        <button onclick="toggleSidebar()" class="nav-item bg-transparent border-none outline-none focus:outline-none"><i class="fas fa-bars"></i> Menu</button>
    </nav>

    <div id="main_print_wrapper" class="hidden"></div>

    <div id="deleteModal" class="hidden modal fixed inset-0 bg-slate-900/80 flex items-center justify-center z-50 p-4 backdrop-blur-sm transition-opacity">
        <div class="bg-white p-8 rounded-3xl shadow-2xl w-full max-w-sm border border-slate-100 text-center transform scale-100 animate-fade-in">
            <div class="w-16 h-16 bg-rose-100 text-rose-500 rounded-full flex items-center justify-center text-3xl mx-auto mb-4 border border-rose-200 shadow-sm"><i class="fas fa-trash-alt"></i></div>
            <h3 class="text-xl font-black text-slate-800 mb-2">প্রস্তাবনা ডিলিট!</h3>
            <p class="text-xs font-bold text-slate-500 mb-6 leading-relaxed">আপনি কি নিশ্চিত যে এই প্রস্তাবনা এবং এর সকল ভোট চিরতরে মুছে ফেলতে চান? এটি আর ফেরত পাওয়া যাবে না।</p>
            <form method="POST">
                <input type="hidden" name="action" value="delete_proposal">
                <input type="hidden" name="proposal_id" id="modal_del_proposal_id">
                <div class="flex gap-3">
                    <button type="button" onclick="closeDeleteModal()" class="flex-1 py-3 bg-slate-100 text-slate-600 rounded-xl font-bold hover:bg-slate-200 transition text-sm">বাতিল</button>
                    <button type="submit" class="flex-1 py-3 bg-rose-600 text-white rounded-xl font-bold hover:bg-rose-700 shadow-md transition text-sm flex justify-center items-center gap-1.5"><i class="fas fa-trash"></i> ডিলিট করুন</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('-translate-x-full');
            document.getElementById('sidebar-overlay').classList.toggle('hidden');
        }
        
        function filterAccounts() {
            let filter = document.getElementById('searchInput').value.toLowerCase();
            document.querySelectorAll('.account-item').forEach(node => {
                node.style.display = node.innerText.toLowerCase().includes(filter) ? "flex" : "none";
            });
        }
        
        function toggleDurationInput(selectEl, id) {
            let box = document.getElementById('duration_box_' + id);
            let input = box.querySelector('input');
            if (selectEl.value === 'approved') { 
                box.classList.remove('hidden'); 
                input.required = true; 
            } else { 
                box.classList.add('hidden'); 
                input.required = false; 
            }
        }

        // Print Logic (Fixed to ensure it works correctly)
        function printResolution(id) {
            const printWrapper = document.getElementById('main_print_wrapper');
            const dataToPrint = document.getElementById('print_data_' + id).innerHTML;
            
            // Set the content
            printWrapper.innerHTML = dataToPrint;
            
            // Optional: Backup original title
            let originalTitle = document.title;
            document.title = "Resolution_Print_" + id;
            
            // Make sure the print wrapper is visible
            printWrapper.classList.remove('hidden');
            
            setTimeout(() => { 
                window.print(); 
                document.title = originalTitle;
                // Hide it again after printing is initiated
                printWrapper.classList.add('hidden');
            }, 300);
        }

        // Delete Modal Logic
        function openDeleteModal(proposalId) {
            document.getElementById('modal_del_proposal_id').value = proposalId;
            document.getElementById('deleteModal').classList.remove('hidden');
        }
        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.add('hidden');
        }

        // Live Countdown Logic
        setInterval(function() {
            document.querySelectorAll('.countdown-timer').forEach(function(el) {
                let remaining = parseInt(el.dataset.remaining);
                if (remaining <= 0) {
                    el.innerHTML = "<span class='text-rose-600'><i class='fas fa-stopwatch'></i> ভোটিং বন্ধ</span>";
                    if(el.dataset.reloaded !== "true") {
                        el.dataset.reloaded = "true";
                        setTimeout(() => window.location.reload(), 1500); 
                    }
                } else {
                    el.dataset.remaining = remaining - 1;
                    let d = Math.floor(remaining / (3600*24));
                    let h = Math.floor((remaining % (3600*24)) / 3600);
                    let m = Math.floor((remaining % 3600) / 60);
                    let s = remaining % 60;
                    
                    let text = "<i class='fas fa-clock text-rose-500'></i> ";
                    if (d > 0) text += `<span class='bg-white/60 px-1 rounded text-rose-700'>${d}d</span> `;
                    text += `<span class='bg-white/60 px-1 rounded text-rose-700'>${h}h</span> : <span class='bg-white/60 px-1 rounded text-rose-700'>${m}m</span> : <span class='bg-white/60 px-1 rounded text-rose-700'>${s}s</span>`;
                    el.innerHTML = text;
                }
            });
        }, 1000);
    </script>
</body>
</html>