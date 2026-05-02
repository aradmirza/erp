<?php
// ২৪ ঘণ্টার জন্য সেশন ও কুকি সেট করা
ini_set('session.cookie_lifetime', 86400);
ini_set('session.gc_maxlifetime', 86400);
session_set_cookie_params(86400);
session_start();

// বাংলাদেশের টাইমজোন সেট করা
date_default_timezone_set('Asia/Dhaka');

// কেউ লগইন না থাকলে লগইন পেজে পাঠাবে
if(!isset($_SESSION['admin_logged_in'])) { 
    header("Location: login.php"); 
    exit; 
}

// স্টাফ হলে এন্ট্রি পেজে রিডাইরেক্ট করবে (Security Check)
if(isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'staff') {
    header("Location: add_entry.php"); 
    exit;
}

require_once 'db.php';

// ==========================================
// 1. SAFE DATABASE MIGRATION (Add Permissions)
// ==========================================
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `staff_accounts` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `name` varchar(100) NOT NULL,
      `username` varchar(50) NOT NULL UNIQUE,
      `password` varchar(255) NOT NULL,
      `permissions` text NULL,
      `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    )");

    $chk = $pdo->query("SHOW COLUMNS FROM `staff_accounts` LIKE 'permissions'");
    if($chk && $chk->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `staff_accounts` ADD COLUMN `permissions` TEXT NULL AFTER `password`");
    }
} catch(PDOException $e) {}

// Available Permissions List
$available_permissions = [
    'dashboard' => ['icon' => 'fa-tachometer-alt', 'label' => 'ড্যাশবোর্ড (Dashboard)'],
    'add_entry' => ['icon' => 'fa-file-invoice-dollar', 'label' => 'হিসাব এন্ট্রি (Add Entry)'],
    'financial_reports' => ['icon' => 'fa-chart-pie', 'label' => 'লাভ-ক্ষতির রিপোর্ট (Reports)'],
    'manage_shareholders' => ['icon' => 'fa-users', 'label' => 'শেয়ারহোল্ডার লিস্ট (Shareholders)'],
    'add_shareholder' => ['icon' => 'fa-user-plus', 'label' => 'অ্যাকাউন্ট তৈরি (Add Account)'],
    'manage_projects' => ['icon' => 'fa-project-diagram', 'label' => 'প্রজেক্ট ম্যানেজমেন্ট (Projects)'],
    'manage_kpi' => ['icon' => 'fa-bullseye', 'label' => 'KPI প্যানেল (KPI)'],
    'manage_votes' => ['icon' => 'fa-vote-yea', 'label' => 'ভোটিং (Voting)'],
    'manage_video' => ['icon' => 'fa-video', 'label' => 'লাইভ ভিডিও (Live Video)']
];

$message = $_SESSION['msg_success'] ?? ''; 
$error = $_SESSION['msg_error'] ?? '';
unset($_SESSION['msg_success'], $_SESSION['msg_error']);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['action'])) {
        
        // Add Staff
        if ($_POST['action'] == 'add') {
            $name = trim($_POST['name']);
            $username = trim($_POST['username']);
            $password = $_POST['password'];
            $permissions = isset($_POST['permissions']) ? $_POST['permissions'] : [];
            $permissions_json = json_encode($permissions, JSON_UNESCAPED_UNICODE);

            try {
                $stmt = $pdo->prepare("INSERT INTO staff_accounts (name, username, password, permissions) VALUES (?, ?, ?, ?)");
                if($stmt->execute([$name, $username, $password, $permissions_json])) {
                    $_SESSION['msg_success'] = "নতুন স্টাফ সফলভাবে যুক্ত হয়েছে!";
                }
            } catch (PDOException $e) { 
                $_SESSION['msg_error'] = "ইউজারনেমটি আগে থেকেই আছে অথবা সমস্যা হয়েছে!"; 
            }
            header("Location: manage_staff.php"); exit;
        } 
        
        // Edit Staff
        elseif ($_POST['action'] == 'edit') {
            $id = (int)$_POST['staff_id'];
            $name = trim($_POST['name']);
            $username = trim($_POST['username']);
            $password = $_POST['password'];
            $permissions = isset($_POST['permissions']) ? $_POST['permissions'] : [];
            $permissions_json = json_encode($permissions, JSON_UNESCAPED_UNICODE);

            try {
                if (!empty($password)) {
                    $stmt = $pdo->prepare("UPDATE staff_accounts SET name=?, username=?, password=?, permissions=? WHERE id=?");
                    $success = $stmt->execute([$name, $username, $password, $permissions_json, $id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE staff_accounts SET name=?, username=?, permissions=? WHERE id=?");
                    $success = $stmt->execute([$name, $username, $permissions_json, $id]);
                }

                if($success) {
                    $_SESSION['msg_success'] = "স্টাফের তথ্য ও পারমিশন সফলভাবে আপডেট হয়েছে!";
                }
            } catch (PDOException $e) {
                $_SESSION['msg_error'] = "আপডেট ব্যর্থ হয়েছে! ইউজারনেমটি হয়তো অন্য কেউ ব্যবহার করছে।";
            }
            header("Location: manage_staff.php"); exit;
        }
        
        // Delete Staff
        elseif ($_POST['action'] == 'delete') {
            $id = (int)$_POST['staff_id'];
            try {
                $pdo->prepare("DELETE FROM staff_accounts WHERE id = ?")->execute([$id]);
                $_SESSION['msg_success'] = "স্টাফ অ্যাকাউন্ট ডিলিট হয়েছে!";
            } catch(PDOException $e) {
                $_SESSION['msg_error'] = "ডিলিট করতে সমস্যা হয়েছে।";
            }
            header("Location: manage_staff.php"); exit;
        }
    }
}

$staffs = $pdo->query("SELECT * FROM staff_accounts ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

// ওয়েবসাইট লোগো ফেচ (সাইডবারের জন্য)
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
    <title>Manage Staff - Sodai Lagbe Admin</title>
    
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
        .glass-header { background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border-bottom: 1px solid rgba(226,232,240,0.8); }
        .app-card { background: #ffffff; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.03), 0 1px 3px rgba(0,0,0,0.02); border: 1px solid rgba(226, 232, 240, 0.8); transition: transform 0.2s ease, box-shadow 0.2s ease; }
        .custom-scrollbar::-webkit-scrollbar { width: 4px; height: 4px; } 
        .custom-scrollbar::-webkit-scrollbar-thumb { background-color: #cbd5e1; border-radius: 10px; } 
        
        .bottom-nav { position: fixed; bottom: 0; left: 0; right: 0; background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px); border-top: 1px solid rgba(0,0,0,0.05); padding-bottom: env(safe-area-inset-bottom); z-index: 50; display: flex; justify-content: space-around; }
        .nav-item { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 10px 0; color: #64748b; font-size: 10px; font-weight: 700; width: 100%; transition: all 0.2s; text-decoration: none;}
        .nav-item.active { color: #2563eb; }
        .nav-item i { font-size: 18px; margin-bottom: 3px; transition: transform 0.2s;}
        .nav-item.active i { transform: translateY(-2px); }
        @media (min-width: 768px) { .bottom-nav { display: none; } }
        
        @keyframes fadeInFast { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fade-in { animation: fadeInFast 0.4s ease-out forwards; }

        /* Custom Checkbox Style */
        .perm-checkbox:checked + div { background-color: #eef2ff; border-color: #6366f1; }
        .perm-checkbox:checked + div .check-icon { display: block; color: #4f46e5; }
        .check-icon { display: none; }
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
            <a href="manage_staff.php" class="flex items-center px-6 py-3 bg-blue-600/20 text-blue-400 border-l-4 border-blue-500 font-semibold"><i class="fas fa-users-cog w-6"></i> স্টাফ ম্যানেজমেন্ট</a>
            <a href="manage_permanent_expenses.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-file-invoice w-6"></i> স্থায়ী মাসিক খরচ</a>
            <a href="manage_kpi.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-bullseye w-6"></i> KPI ম্যানেজমেন্ট</a>
            <a href="manage_votes.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-vote-yea w-6"></i> ভোটিং ও প্রস্তাবনা</a>
            
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
                <h2 class="text-lg font-black tracking-tight text-slate-800 hidden sm:block">অ্যাডমিন ওভারভিউ</h2>
                <h2 class="text-lg font-black tracking-tight text-slate-800 sm:hidden">স্টাফ ম্যানেজ</h2>
            </div>
            <div class="flex items-center gap-3">
                <div class="text-right hidden sm:block border-r border-slate-200 pr-3">
                    <div class="text-sm font-bold leading-tight"><?= htmlspecialchars($_SESSION['admin_username'] ?? 'Admin') ?></div>
                    <div class="text-[10px] text-slate-500 font-bold uppercase tracking-wide">System Admin</div>
                </div>
                <div class="h-9 w-9 bg-gradient-to-br from-blue-600 to-indigo-700 rounded-full flex items-center justify-center text-white font-black shadow-md border border-white">
                    <i class="fas fa-user-shield text-xs"></i>
                </div>
            </div>
        </header>

        <main class="flex-1 overflow-x-hidden overflow-y-auto p-4 md:p-6 custom-scrollbar pb-24 md:pb-6 relative bg-slate-50">
            
            <div class="max-w-5xl mx-auto space-y-6">
                
                <?php if($message): ?><div class="bg-emerald-50 text-emerald-600 px-4 py-3 rounded-xl text-sm font-bold shadow-sm border border-emerald-100 flex items-center animate-fade-in"><i class="fas fa-check-circle mr-2 text-lg"></i> <?= $message ?></div><?php endif; ?>
                <?php if($error): ?><div class="bg-red-50 text-red-600 px-4 py-3 rounded-xl text-sm font-bold shadow-sm border border-red-100 flex items-center animate-fade-in"><i class="fas fa-exclamation-triangle mr-2 text-lg"></i> <?= $error ?></div><?php endif; ?>

                <div class="flex flex-wrap gap-4 justify-between items-center animate-fade-in">
                    <div>
                        <h2 class="text-xl md:text-2xl font-black text-slate-800 flex items-center gap-2"><i class="fas fa-users-cog text-blue-500"></i> স্টাফ ম্যানেজমেন্ট</h2>
                        <p class="text-xs text-slate-500 mt-1 font-bold">অ্যাকাউন্ট তৈরি এবং পারমিশন (Access Control) নির্ধারণ করুন</p>
                    </div>
                    <button onclick="document.getElementById('addStaffModal').classList.remove('hidden')" class="bg-blue-600 text-white px-5 py-2.5 rounded-xl shadow-md hover:bg-blue-700 hover:shadow-lg transition-all active:scale-95 font-bold flex items-center gap-2 text-sm">
                        <i class="fas fa-user-plus"></i> নতুন স্টাফ
                    </button>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5 animate-fade-in" style="animation-delay: 0.1s;">
                    <?php if(count($staffs) > 0): ?>
                        <?php foreach($staffs as $st): 
                            $perms = json_decode($st['permissions'], true) ?: [];
                        ?>
                        <div class="app-card bg-white overflow-hidden hover:border-blue-300 transition-colors shadow-sm group">
                            <div class="p-5 border-b border-slate-100 bg-slate-50/50 flex justify-between items-start">
                                <div class="flex items-center gap-3">
                                    <div class="w-12 h-12 rounded-full border-2 border-slate-100 shadow-sm overflow-hidden shrink-0 flex items-center justify-center bg-white font-black text-slate-400 group-hover:border-blue-200 transition-colors">
                                        <i class="fas fa-user text-xl"></i>
                                    </div>
                                    <div>
                                        <h3 class="font-black text-slate-800 text-base leading-tight mb-1"><?= htmlspecialchars($st['name']) ?></h3>
                                        <span class="bg-indigo-50 text-indigo-600 px-2 py-0.5 rounded text-[10px] font-black tracking-wider shadow-sm border border-indigo-100"><i class="fas fa-at mr-0.5"></i> <?= htmlspecialchars($st['username']) ?></span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="p-5">
                                <div class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-3 flex items-center gap-1.5"><i class="fas fa-shield-alt text-blue-400"></i> অ্যাক্সেস পারমিশন:</div>
                                
                                <div class="flex flex-wrap gap-2 mb-5">
                                    <?php if(!empty($perms)): ?>
                                        <?php foreach($perms as $p_key): 
                                            if(isset($available_permissions[$p_key])): 
                                                $p_info = $available_permissions[$p_key];
                                        ?>
                                            <span class="bg-slate-50 border border-slate-200 text-slate-600 px-2 py-1 rounded-lg text-[10px] font-bold flex items-center gap-1.5 shadow-sm"><i class="fas <?= $p_info['icon'] ?> text-slate-400"></i> <?= $p_info['label'] ?></span>
                                        <?php endif; endforeach; ?>
                                    <?php else: ?>
                                        <span class="text-xs text-rose-500 font-bold bg-rose-50 px-2 py-1 rounded border border-rose-100"><i class="fas fa-exclamation-triangle mr-1"></i> কোনো পারমিশন দেওয়া হয়নি</span>
                                    <?php endif; ?>
                                </div>

                                <div class="flex gap-2 pt-4 border-t border-slate-100">
                                    <button onclick='openEditStaffModal(<?= json_encode($st) ?>)' class="flex-1 w-full bg-blue-50 text-blue-600 font-bold py-2 rounded-xl text-xs hover:bg-blue-600 hover:text-white transition flex items-center justify-center gap-1.5 border border-blue-100 shadow-sm"><i class="fas fa-edit"></i> এডিট করুন</button>
                                    
                                    <form method="POST" onsubmit="return confirm('স্টাফ অ্যাকাউন্টটি ডিলিট করতে চান?');" class="flex-shrink-0">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="staff_id" value="<?= $st['id'] ?>">
                                        <button type="submit" class="w-9 h-9 bg-rose-50 text-rose-500 rounded-xl flex items-center justify-center hover:bg-rose-500 hover:text-white transition border border-rose-100 shadow-sm"><i class="fas fa-trash-alt text-[10px]"></i></button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="col-span-full app-card p-12 text-center bg-white border-dashed">
                            <i class="fas fa-user-slash text-5xl mb-4 text-slate-300 block"></i>
                            <p class="font-bold text-sm text-slate-500">কোনো স্টাফ অ্যাকাউন্ট নেই। "নতুন স্টাফ" বাটনে ক্লিক করে যুক্ত করুন।</p>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </main>
    </div>

    <nav class="bottom-nav md:hidden shadow-[0_-5px_15px_rgba(0,0,0,0.05)] z-50">
        <a href="index.php" class="nav-item"><i class="fas fa-home"></i> Home</a>
        <a href="manage_shareholders.php" class="nav-item"><i class="fas fa-users"></i> Users</a>
        <a href="manage_staff.php" class="nav-item active"><i class="fas fa-users-cog"></i> Staff</a>
        <button onclick="toggleSidebar()" class="nav-item bg-transparent border-none outline-none focus:outline-none"><i class="fas fa-bars"></i> Menu</button>
    </nav>

    <div id="addStaffModal" class="hidden fixed inset-0 bg-slate-900/60 flex items-center justify-center z-50 p-4 backdrop-blur-sm transition-opacity">
        <div class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl border border-slate-100 overflow-hidden transform scale-100 flex flex-col max-h-[90vh]">
            <div class="px-6 py-5 bg-slate-800 flex items-center justify-between shrink-0">
                <h3 class="font-black text-base text-white flex items-center gap-2"><i class="fas fa-user-plus text-blue-400"></i> নতুন স্টাফ যুক্ত করুন</h3>
                <button onclick="document.getElementById('addStaffModal').classList.add('hidden')" class="w-8 h-8 rounded-lg flex items-center justify-center text-slate-400 hover:bg-rose-500 hover:text-white transition"><i class="fas fa-times"></i></button>
            </div>
            <form method="POST" class="p-6 overflow-y-auto custom-scrollbar flex-1 bg-slate-50 space-y-6">
                <input type="hidden" name="action" value="add">
                
                <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm space-y-4">
                    <h4 class="text-xs font-black text-slate-800 border-b border-slate-100 pb-2 mb-3"><i class="fas fa-info-circle text-blue-500 mr-1.5"></i> বেসিক ইনফরমেশন</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2">
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 tracking-wide">স্টাফের পূর্ণ নাম <span class="text-rose-500">*</span></label>
                            <input type="text" name="name" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold outline-none focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-100 transition" placeholder="Mr. Staff Name" required>
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 tracking-wide">লগইন ইউজারনেম <span class="text-rose-500">*</span></label>
                            <input type="text" name="username" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-mono outline-none focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-100 transition" placeholder="staff_01" required>
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 tracking-wide">পাসওয়ার্ড <span class="text-rose-500">*</span></label>
                            <input type="text" name="password" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm outline-none focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-100 transition" placeholder="••••••••" required>
                        </div>
                    </div>
                </div>

                <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
                    <h4 class="text-xs font-black text-slate-800 border-b border-slate-100 pb-2 mb-4 flex items-center justify-between">
                        <span><i class="fas fa-shield-alt text-indigo-500 mr-1.5"></i> অ্যাক্সেস পারমিশন (Access Roles)</span>
                    </h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 max-h-60 overflow-y-auto custom-scrollbar pr-1">
                        <?php foreach($available_permissions as $key => $perm): ?>
                        <label class="relative cursor-pointer">
                            <input type="checkbox" name="permissions[]" value="<?= $key ?>" class="perm-checkbox sr-only">
                            <div class="flex items-center justify-between p-3.5 border border-slate-200 rounded-xl hover:bg-slate-50 transition-colors">
                                <div class="flex items-center gap-2.5">
                                    <div class="w-7 h-7 rounded-lg bg-white border border-slate-100 flex items-center justify-center text-slate-400 shadow-sm"><i class="fas <?= $perm['icon'] ?> text-[11px]"></i></div>
                                    <span class="text-xs font-bold text-slate-700"><?= $perm['label'] ?></span>
                                </div>
                                <i class="fas fa-check-circle check-icon text-lg drop-shadow-sm"></i>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="text-[9px] font-bold text-slate-400 mt-3 text-center bg-slate-50 p-2 rounded-lg border border-slate-100"><i class="fas fa-lightbulb text-amber-500 mr-1"></i> যেসব পেজে টিক চিহ্ন দেবেন, স্টাফ শুধুমাত্র সেই পেজগুলোতেই প্রবেশ করতে পারবে।</p>
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="button" onclick="document.getElementById('addStaffModal').classList.add('hidden')" class="flex-1 py-3.5 bg-slate-100 text-slate-600 rounded-xl font-bold hover:bg-slate-200 transition text-sm">বাতিল</button>
                    <button type="submit" class="flex-1 py-3.5 bg-blue-600 text-white font-bold rounded-xl shadow-md hover:bg-blue-700 transition text-sm flex justify-center items-center gap-2"><i class="fas fa-plus-circle"></i> স্টাফ তৈরি করুন</button>
                </div>
            </form>
        </div>
    </div>

    <div id="editStaffModal" class="hidden fixed inset-0 bg-slate-900/60 flex items-center justify-center z-50 p-4 backdrop-blur-sm transition-opacity">
        <div class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl border border-slate-100 overflow-hidden transform scale-100 flex flex-col max-h-[90vh]">
            <div class="px-6 py-5 bg-slate-800 flex items-center justify-between shrink-0">
                <h3 class="font-black text-base text-white flex items-center gap-2"><i class="fas fa-user-edit text-amber-400"></i> স্টাফ এডিট করুন</h3>
                <button onclick="closeEditStaffModal()" class="w-8 h-8 rounded-lg flex items-center justify-center text-slate-400 hover:bg-rose-500 hover:text-white transition"><i class="fas fa-times"></i></button>
            </div>
            <form method="POST" class="p-6 overflow-y-auto custom-scrollbar flex-1 bg-slate-50 space-y-6">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="staff_id" id="edit_staff_id">
                
                <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm space-y-4">
                    <h4 class="text-xs font-black text-slate-800 border-b border-slate-100 pb-2 mb-3"><i class="fas fa-info-circle text-amber-500 mr-1.5"></i> বেসিক ইনফরমেশন</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2">
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 tracking-wide">স্টাফের পূর্ণ নাম <span class="text-rose-500">*</span></label>
                            <input type="text" name="name" id="edit_staff_name" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold outline-none focus:border-amber-500 focus:bg-white focus:ring-2 focus:ring-amber-100 transition" required>
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 tracking-wide">লগইন ইউজারনেম <span class="text-rose-500">*</span></label>
                            <input type="text" name="username" id="edit_staff_username" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-mono outline-none focus:border-amber-500 focus:bg-white focus:ring-2 focus:ring-amber-100 transition" required>
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 tracking-wide">নতুন পাসওয়ার্ড</label>
                            <input type="text" name="password" id="edit_staff_password" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm outline-none focus:border-amber-500 focus:bg-white focus:ring-2 focus:ring-amber-100 transition" placeholder="পরিবর্তন না করলে ফাঁকা রাখুন">
                        </div>
                    </div>
                </div>

                <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
                    <h4 class="text-xs font-black text-slate-800 border-b border-slate-100 pb-2 mb-4 flex items-center justify-between">
                        <span><i class="fas fa-shield-alt text-indigo-500 mr-1.5"></i> অ্যাক্সেস পারমিশন (Access Roles)</span>
                    </h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 max-h-60 overflow-y-auto custom-scrollbar pr-1">
                        <?php foreach($available_permissions as $key => $perm): ?>
                        <label class="relative cursor-pointer">
                            <input type="checkbox" name="permissions[]" value="<?= $key ?>" id="edit_perm_<?= $key ?>" class="perm-checkbox sr-only">
                            <div class="flex items-center justify-between p-3.5 border border-slate-200 rounded-xl hover:bg-slate-50 transition-colors">
                                <div class="flex items-center gap-2.5">
                                    <div class="w-7 h-7 rounded-lg bg-white border border-slate-100 flex items-center justify-center text-slate-400 shadow-sm"><i class="fas <?= $perm['icon'] ?> text-[11px]"></i></div>
                                    <span class="text-xs font-bold text-slate-700"><?= $perm['label'] ?></span>
                                </div>
                                <i class="fas fa-check-circle check-icon text-lg drop-shadow-sm"></i>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="button" onclick="closeEditStaffModal()" class="flex-1 py-3.5 bg-slate-100 text-slate-600 rounded-xl font-bold hover:bg-slate-200 transition text-sm">বাতিল</button>
                    <button type="submit" class="flex-1 py-3.5 bg-blue-600 text-white font-bold rounded-xl shadow-md hover:bg-blue-700 transition text-sm flex justify-center items-center gap-2"><i class="fas fa-save"></i> আপডেট করুন</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('-translate-x-full');
            document.getElementById('sidebar-overlay').classList.toggle('hidden');
        }

        function openEditStaffModal(staffObj) {
            document.getElementById('edit_staff_id').value = staffObj.id;
            document.getElementById('edit_staff_name').value = staffObj.name;
            document.getElementById('edit_staff_username').value = staffObj.username;
            document.getElementById('edit_staff_password').value = ''; 
            
            // Reset all checkboxes first
            document.querySelectorAll('#editStaffModal .perm-checkbox').forEach(cb => cb.checked = false);
            
            // Check the ones user has
            if (staffObj.permissions) {
                try {
                    const perms = JSON.parse(staffObj.permissions);
                    if(Array.isArray(perms)) {
                        perms.forEach(p => {
                            const cb = document.getElementById('edit_perm_' + p);
                            if (cb) cb.checked = true;
                        });
                    }
                } catch(e) {}
            }
            
            document.getElementById('editStaffModal').classList.remove('hidden');
        }

        function closeEditStaffModal() {
            document.getElementById('editStaffModal').classList.add('hidden');
        }
    </script>
</body>
</html>