<?php
// ২৪ ঘণ্টার জন্য সেশন ও কুকি সেট করা
ini_set('session.cookie_lifetime', 86400);
ini_set('session.gc_maxlifetime', 86400);
session_set_cookie_params(86400);
session_start();

// বাংলাদেশের টাইমজোন সেট করা
date_default_timezone_set('Asia/Dhaka');

if(!isset($_SESSION['admin_logged_in'])) { header("Location: login.php"); exit; }

require_once 'db.php';

// ভেরিয়েবল ডিফাইন করা হলো (যাতে Error না আসে)
$is_staff = (isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'staff');

// === Role-Based Access Control (RBAC) ===
if($is_staff) {
    $perms = $_SESSION['staff_permissions'] ?? [];
    if(!in_array('add_entry', $perms)) { 
        die("<div style='text-align:center; padding:50px; font-family:sans-serif;'><h2 style='color:red;'>Access Denied!</h2><p>আপনার এই পেজে প্রবেশের অনুমতি নেই।</p><a href='login.php' style='color:blue; text-decoration:underline;'>লগআউট করুন</a></div>"); 
    }
}
// ==========================================

$message = '';
if (isset($_SESSION['success_message'])) {
    $message = $_SESSION['success_message']; 
    unset($_SESSION['success_message']);
}

// প্রজেক্টগুলো ফেচ করা হলো (যাতে ফর্মের ড্রপডাউনে এরর না আসে)
$projects = [];
try {
    $projects = $pdo->query("SELECT id, project_name FROM projects ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $type = $_POST['type'];
    $amount = $_POST['amount'];
    $description = $_POST['description'];
    $date_added = $_POST['entry_date'];
    $project_id = empty($_POST['project_id']) ? null : $_POST['project_id'];
    
    $expense_category = null;
    $receipt_image = null;
    // স্টাফ হলে পেন্ডিং, অ্যাডমিন হলে সরাসরি অ্যাপ্রুভড
    $status = $is_staff ? 'pending' : 'approved';
    $added_by = $_SESSION['admin_username'];

    if ($type === 'expense') {
        $expense_category = $_POST['expense_category'];
        // মেমো/ছবি আপলোড লজিক
        if ($expense_category === 'online_purchase' && isset($_FILES['receipt_image']) && $_FILES['receipt_image']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = '../uploads/receipts/';
            if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }
            
            $ext = pathinfo($_FILES['receipt_image']['name'], PATHINFO_EXTENSION);
            $fileName = time() . '_' . rand(100, 999) . '.' . $ext;
            $targetFilePath = $uploadDir . $fileName;
            
            if(move_uploaded_file($_FILES['receipt_image']['tmp_name'], $targetFilePath)){
                $receipt_image = 'uploads/receipts/' . $fileName;
            }
        }
    }

    try {
        $sql = "INSERT INTO financials (project_id, type, expense_category, receipt_image, amount, description, date_added, status, added_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        
        if($stmt->execute([$project_id, $type, $expense_category, $receipt_image, $amount, $description, $date_added, $status, $added_by])) {
            if($is_staff){ 
                $_SESSION['success_message'] = "এন্ট্রি সফল হয়েছে! অ্যাডমিনের অনুমোদনের (Approval) অপেক্ষায় আছে।"; 
            } else { 
                $_SESSION['success_message'] = "হিসাব সফলভাবে যুক্ত হয়েছে!"; 
            }
            header("Location: add_entry.php"); exit;
        }
    } catch(PDOException $e) {
        $_SESSION['success_message'] = "ডাটাবেস এরর: " . $e->getMessage();
        header("Location: add_entry.php"); exit;
    }
}

// রিসেন্ট হিস্টোরি ফেচ করা
$history = [];
try {
    if ($is_staff) {
        $stmt = $pdo->prepare("SELECT f.*, p.project_name FROM financials f LEFT JOIN projects p ON f.project_id = p.id WHERE f.added_by = ? ORDER BY f.id DESC LIMIT 30");
        $stmt->execute([$_SESSION['admin_username']]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $history = $pdo->query("SELECT f.*, p.project_name FROM financials f LEFT JOIN projects p ON f.project_id = p.id ORDER BY f.id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch(PDOException $e) {}

$category_map = ['general' => 'জেনারেল', 'third_party' => 'থার্ড পার্টি', 'online_purchase' => 'অনলাইন', 'no_record' => 'রেকর্ড নেই'];

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
    <title><?= $is_staff ? 'Staff Entry' : 'Add Entry' ?> - Sodai Lagbe ERP</title>
    
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
        .app-card { background: #ffffff; border-radius: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.03), 0 1px 3px rgba(0,0,0,0.02); border: 1px solid rgba(226, 232, 240, 0.8); }
        .custom-scrollbar::-webkit-scrollbar { width: 4px; height: 4px; } 
        .custom-scrollbar::-webkit-scrollbar-thumb { background-color: #cbd5e1; border-radius: 10px; } 
        .bottom-nav { position: fixed; bottom: 0; left: 0; right: 0; background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px); border-top: 1px solid rgba(0,0,0,0.05); padding-bottom: env(safe-area-inset-bottom); z-index: 50; display: flex; justify-content: space-around; }
        .nav-item { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 10px 0; color: #64748b; font-size: 10px; font-weight: 700; width: 100%; transition: all 0.2s; text-decoration: none;}
        .nav-item.active { color: #2563eb; }
        .nav-item i { font-size: 18px; margin-bottom: 3px; transition: transform 0.2s;}
        .nav-item.active i { transform: translateY(-2px); }
        @media (min-width: 768px) { .bottom-nav { display: none; } }
        .main-content { padding-bottom: 80px; }
        @media (min-width: 768px) { .main-content { padding-bottom: 30px; } }
        @keyframes fadeInFast { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fade-in { animation: fadeInFast 0.4s ease-out forwards; }
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
                <span class="truncate"><?= $is_staff ? 'Staff Panel' : 'Admin Panel' ?></span>
            </h1>
        </div>
        <nav class="flex-1 overflow-y-auto py-4 custom-scrollbar space-y-1">
            <?php if(!$is_staff): ?>
                <div class="px-6 py-2 text-[10px] font-bold text-slate-500 uppercase tracking-widest mt-2">Core</div>
                <a href="index.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-tachometer-alt w-6"></i> ড্যাশবোর্ড</a>
                
                <div class="px-6 py-2 text-[10px] font-bold text-slate-500 uppercase tracking-widest mt-4">Management</div>
                <a href="manage_shareholders.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-users w-6"></i> শেয়ারহোল্ডার লিস্ট</a>
                <a href="add_shareholder.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-user-plus w-6"></i> অ্যাকাউন্ট তৈরি</a>
                <a href="manage_projects.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-project-diagram w-6"></i> প্রজেক্ট লিস্ট</a>
                <a href="add_project.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-plus-square w-6"></i> নতুন প্রজেক্ট</a>
                <a href="manage_staff.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-users-cog w-6"></i> স্টাফ ম্যানেজমেন্ট</a>
                <a href="manage_kpi.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-bullseye w-6"></i> KPI ম্যানেজমেন্ট</a>
                <a href="manage_votes.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-vote-yea w-6"></i> ভোটিং ও প্রস্তাবনা</a>
                <a href="send_sms.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-sms w-6"></i> এসএমএস প্যানেল</a>
                
                <div class="px-6 py-2 text-[10px] font-bold text-slate-500 uppercase tracking-widest mt-4">Finance & Reports</div>
                <a href="add_entry.php" class="flex items-center px-6 py-3 bg-blue-600/20 text-blue-400 border-l-4 border-blue-500 font-semibold"><i class="fas fa-file-invoice-dollar w-6"></i> দৈনিক হিসাব এন্ট্রি</a>
                <a href="financial_reports.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-chart-pie w-6"></i> লাভ-ক্ষতির রিপোর্ট</a>
            <?php else: ?>
                <div class="px-6 py-2 text-[10px] font-bold text-slate-500 uppercase tracking-widest mt-2">Data Entry</div>
                <a href="add_entry.php" class="flex items-center px-6 py-3 bg-blue-600/20 text-blue-400 border-l-4 border-blue-500 font-semibold"><i class="fas fa-file-invoice-dollar w-6"></i> দৈনিক হিসাব এন্ট্রি</a>
            <?php endif; ?>
        </nav>
        <div class="p-4 border-t border-slate-800 shrink-0">
            <a href="logout.php" class="flex items-center px-4 py-2.5 text-red-400 hover:bg-red-500 hover:text-white rounded-lg transition-colors font-bold"><i class="fas fa-sign-out-alt w-6"></i> লগআউট</a>
        </div>
    </aside>

    <div class="flex flex-col min-h-screen w-full md:pl-64 transition-all duration-300">
        
        <header class="glass-header sticky top-0 z-30 px-4 py-3 flex items-center justify-between shadow-sm h-16 shrink-0">
            <div class="flex items-center gap-3">
                <button onclick="toggleSidebar()" class="text-slate-600 focus:outline-none md:hidden text-xl hover:text-blue-600 transition"><i class="fas fa-bars"></i></button>
                <h2 class="text-lg font-black tracking-tight text-slate-800 hidden sm:block"><?= $is_staff ? 'স্টাফ প্যানেল' : 'অ্যাডমিন ওভারভিউ' ?></h2>
                <h2 class="text-lg font-black tracking-tight text-slate-800 sm:hidden">নতুন এন্ট্রি</h2>
            </div>
            <div class="flex items-center gap-3">
                <div class="text-right hidden sm:block border-r border-slate-200 pr-3">
                    <div class="text-sm font-bold leading-tight"><?= htmlspecialchars($_SESSION['admin_username']) ?></div>
                    <div class="text-[10px] text-slate-500 font-bold uppercase tracking-wide"><?= $is_staff ? 'Staff User' : 'System Admin' ?></div>
                </div>
                <div class="h-9 w-9 bg-gradient-to-br from-blue-600 to-indigo-700 rounded-full flex items-center justify-center text-white font-black shadow-md border border-white">
                    <?= strtoupper(substr($_SESSION['admin_username'], 0, 1)) ?>
                </div>
            </div>
        </header>

        <main class="flex-1 overflow-x-hidden overflow-y-auto main-content p-4 md:p-6 custom-scrollbar relative bg-slate-50">
            
            <div class="max-w-4xl mx-auto space-y-6">
                
                <?php if($message): ?><div class="bg-emerald-50 text-emerald-600 px-4 py-3 rounded-xl text-sm font-bold shadow-sm border border-emerald-100 flex items-center animate-fade-in"><i class="fas fa-check-circle mr-2 text-lg"></i> <?= $message ?></div><?php endif; ?>

                <?php if($is_staff): ?>
                <div class="bg-blue-50/80 text-blue-800 p-4 rounded-xl text-xs font-medium border border-blue-100 shadow-sm flex items-start gap-3 animate-fade-in" style="animation-delay: 0.1s;">
                    <i class="fas fa-info-circle text-blue-500 text-lg mt-0.5"></i> 
                    <p class="leading-relaxed">স্টাফদের আপলোড করা হিসাবগুলো প্রথমে <span class="bg-amber-100 text-amber-700 px-1.5 rounded text-[10px] font-bold border border-amber-200">Pending</span> অবস্থায় থাকে। অ্যাডমিন যাচাই করে অ্যাপ্রুভ করার পরই তা কোম্পানির মূল ফান্ডের সাথে যুক্ত হবে।</p>
                </div>
                <?php endif; ?>

                <div class="grid grid-cols-1 lg:grid-cols-5 gap-6 animate-fade-in" style="animation-delay: 0.1s;">
                    
                    <div class="lg:col-span-3">
                        <div class="app-card overflow-hidden shadow-sm">
                            <div class="bg-gradient-to-r from-blue-700 to-indigo-800 px-6 py-5 text-white">
                                <h2 class="text-xl font-black flex items-center gap-2"><i class="fas fa-file-invoice-dollar"></i> দৈনিক হিসাব এন্ট্রি</h2>
                                <p class="text-blue-200 text-xs font-medium mt-1 opacity-90">কোম্পানির আয়-ব্যয়ের নতুন রেকর্ড যুক্ত করুন</p>
                            </div>
                            
                            <form method="POST" action="" enctype="multipart/form-data" class="p-6 space-y-5">
                                
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-[10px] font-bold text-slate-500 mb-1.5 uppercase tracking-wide">তারিখ <span class="text-red-500">*</span></label>
                                        <input type="date" name="entry_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-100 outline-none transition font-bold text-slate-700 text-sm shadow-sm" required>
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-slate-500 mb-1.5 uppercase tracking-wide">পরিমাণ (টাকা) <span class="text-red-500">*</span></label>
                                        <div class="relative shadow-sm rounded-xl">
                                            <span class="absolute left-4 top-1/2 -translate-y-1/2 font-bold text-slate-400">৳</span>
                                            <input type="number" step="0.01" name="amount" class="w-full pl-8 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-100 outline-none transition font-black text-slate-800 text-sm" placeholder="0.00" required>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="bg-indigo-50/50 p-4 rounded-xl border border-indigo-100 shadow-sm">
                                    <label class="block text-[10px] font-bold text-indigo-800 mb-1.5 uppercase tracking-wide">কোন প্রজেক্টের হিসাব? <span class="text-red-500">*</span></label>
                                    <select name="project_id" class="w-full px-4 py-3 bg-white border border-indigo-200 rounded-xl focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none transition text-sm font-bold text-indigo-900 cursor-pointer">
                                        <option value="">জেনারেল ফান্ড (কোম্পানির মূল হিসাব)</option>
                                        <?php foreach($projects as $p): ?>
                                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['project_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-[10px] font-bold text-slate-500 mb-1.5 uppercase tracking-wide">হিসাবের ধরন <span class="text-red-500">*</span></label>
                                    <select name="type" id="entry_type" onchange="toggleFields()" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-100 outline-none transition text-sm font-bold text-slate-700 cursor-pointer shadow-sm" required>
                                        <option value="expense">খরচ (Debit / Expense)</option>
                                        <option value="profit">লাভ (Credit / Profit)</option>
                                    </select>
                                </div>
                                
                                <div id="category_div" class="bg-rose-50/50 p-4 rounded-xl border border-rose-100 transition-all duration-300 shadow-sm">
                                    <label class="block text-[10px] font-bold text-rose-800 mb-1.5 uppercase tracking-wide">খরচের ক্যাটাগরি <span class="text-red-500">*</span></label>
                                    <select name="expense_category" id="expense_category" onchange="toggleFields()" class="w-full px-4 py-3 bg-white border border-rose-200 rounded-xl focus:border-rose-500 focus:ring-2 focus:ring-rose-100 outline-none transition text-sm font-bold text-rose-900 cursor-pointer">
                                        <option value="general">জেনারেল খরচ</option>
                                        <option value="third_party">থার্ড পার্টি (বাহির থেকে)</option>
                                        <option value="online_purchase">অনলাইন পারচেজ (পণ্য ক্রয়)</option>
                                    </select>
                                </div>

                                <div id="image_div" class="hidden bg-sky-50/50 p-4 rounded-xl border border-sky-100 transition-all duration-300 shadow-sm">
                                    <label class="block text-[10px] font-bold text-sky-800 mb-2 uppercase tracking-wide"><i class="fas fa-image mr-1"></i> ক্রয়ের মেমো / প্রমাণপত্র আপলোড</label>
                                    <input type="file" name="receipt_image" accept="image/*" class="w-full px-3 py-2 bg-white border border-sky-200 rounded-xl text-xs font-medium text-slate-600 file:mr-4 file:py-1.5 file:px-4 file:rounded-lg file:border-0 file:text-[10px] file:font-bold file:bg-sky-100 file:text-sky-700 hover:file:bg-sky-200 cursor-pointer transition">
                                </div>

                                <div>
                                    <label class="block text-[10px] font-bold text-slate-500 mb-1.5 uppercase tracking-wide">বিস্তারিত বিবরণ <span class="text-red-500">*</span></label>
                                    <textarea name="description" rows="3" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-100 outline-none transition text-sm text-slate-700 custom-scrollbar shadow-sm" placeholder="হিসাবের বিস্তারিত তথ্য লিখুন..." required></textarea>
                                </div>

                                <button type="submit" class="w-full bg-blue-600 text-white font-black text-sm py-4 rounded-xl shadow-lg hover:bg-blue-700 hover:shadow-xl transition-all transform active:scale-95 flex justify-center items-center gap-2 mt-4"><i class="fas fa-save"></i> হিসাব সেভ করুন</button>
                            </form>
                        </div>
                    </div>

                    <div class="lg:col-span-2">
                        <div class="app-card bg-white flex flex-col h-full max-h-[700px] shadow-sm">
                            <div class="p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50 shrink-0 rounded-t-[15px]">
                                <h3 class="font-black text-slate-800 text-sm flex items-center gap-2"><i class="fas fa-history text-indigo-500"></i> সাম্প্রতিক এন্ট্রিসমূহ</h3>
                                <span class="text-[10px] font-bold text-slate-500 bg-white px-2 py-1 rounded-md border border-slate-200 shadow-sm">Last <?= count($history) ?></span>
                            </div>
                            
                            <div class="flex-1 overflow-y-auto custom-scrollbar p-4 space-y-3 bg-slate-50/30">
                                <?php if(count($history) > 0): ?>
                                    <?php foreach($history as $h): 
                                        $is_profit = ($h['type'] === 'profit');
                                        $is_pending = ($h['status'] === 'pending');
                                        
                                        $icon_bg = $is_profit ? 'bg-emerald-100 text-emerald-600 border-emerald-200' : 'bg-rose-100 text-rose-600 border-rose-200';
                                        $icon = $is_profit ? 'fa-arrow-down' : 'fa-arrow-up';
                                        $amount_color = $is_profit ? 'text-emerald-600' : 'text-rose-600';
                                    ?>
                                    <div class="bg-white border border-slate-200 rounded-xl p-4 hover:border-blue-300 transition shadow-sm relative overflow-hidden group">
                                        <?php if($is_pending): ?>
                                            <div class="absolute top-0 right-0 bg-amber-400 text-white text-[8px] font-black px-2 py-0.5 rounded-bl-lg uppercase tracking-wider shadow-sm">Pending</div>
                                        <?php else: ?>
                                            <div class="absolute top-0 right-0 bg-emerald-500 text-white text-[8px] font-black px-2 py-0.5 rounded-bl-lg uppercase tracking-wider shadow-sm">Approved</div>
                                        <?php endif; ?>
                                        
                                        <div class="flex items-start gap-3">
                                            <div class="w-10 h-10 rounded-full flex items-center justify-center shrink-0 border <?= $icon_bg ?>"><i class="fas <?= $icon ?> text-sm"></i></div>
                                            <div class="flex-1 min-w-0 pr-6">
                                                <div class="flex justify-between items-start mb-0.5">
                                                    <p class="text-[9px] text-slate-400 font-bold uppercase tracking-wider"><i class="far fa-calendar-alt mr-1"></i><?= date('d M, y', strtotime($h['date_added'])) ?></p>
                                                </div>
                                                <h4 class="text-sm font-bold text-slate-800 leading-snug mb-1.5"><?= htmlspecialchars($h['description']) ?></h4>
                                                
                                                <div class="flex flex-wrap gap-1.5 mt-2">
                                                    <span class="bg-indigo-50 text-indigo-600 px-2 py-0.5 rounded border border-indigo-100 text-[9px] font-bold"><i class="fas fa-project-diagram mr-1"></i> <?= htmlspecialchars($h['project_name'] ?? 'Gen Fund') ?></span>
                                                    <?php if(!$is_profit): ?>
                                                        <span class="bg-slate-50 text-slate-600 px-2 py-0.5 rounded border border-slate-200 text-[9px] font-bold"><?= $category_map[$h['expense_category']] ?? 'N/A' ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="mt-3 pt-3 border-t border-slate-100 flex justify-between items-center">
                                            <span class="text-[9px] font-bold text-slate-400 bg-slate-50 px-2 py-1 rounded border border-slate-100"><i class="fas fa-user mr-1"></i> By: <?= htmlspecialchars($h['added_by']) ?></span>
                                            <span class="text-lg font-black <?= $amount_color ?>">৳ <?= number_format($h['amount'], 2) ?></span>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="text-center py-10 text-slate-400 bg-white rounded-xl border border-dashed border-slate-300">
                                        <i class="fas fa-inbox text-4xl mb-3 opacity-30 block"></i>
                                        <p class="text-xs font-bold uppercase tracking-wide">কোনো এন্ট্রি নেই</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </main>
    </div>

    <nav class="bottom-nav md:hidden shadow-[0_-5px_15px_rgba(0,0,0,0.05)] z-50">
        <?php if(!$is_staff): ?>
            <a href="index.php" class="nav-item"><i class="fas fa-home"></i> Home</a>
            <a href="manage_shareholders.php" class="nav-item"><i class="fas fa-users"></i> Users</a>
            <a href="add_entry.php" class="nav-item active"><i class="fas fa-plus-circle"></i> Entry</a>
        <?php else: ?>
            <a href="add_entry.php" class="nav-item active"><i class="fas fa-plus-circle"></i> Entry</a>
            <a href="logout.php" class="nav-item text-rose-500"><i class="fas fa-sign-out-alt"></i> Logout</a>
        <?php endif; ?>
        <button onclick="toggleSidebar()" class="nav-item bg-transparent border-none outline-none focus:outline-none"><i class="fas fa-bars"></i> Menu</button>
    </nav>

    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('-translate-x-full');
            document.getElementById('sidebar-overlay').classList.toggle('hidden');
        }

        function toggleFields() {
            var type = document.getElementById('entry_type').value;
            var catDiv = document.getElementById('category_div');
            var imgDiv = document.getElementById('image_div');
            var category = document.getElementById('expense_category').value;
            
            if (type === 'profit') { 
                catDiv.style.display = 'none'; 
                imgDiv.style.display = 'none'; 
                document.getElementById('expense_category').removeAttribute('required'); 
            } else { 
                catDiv.style.display = 'block'; 
                document.getElementById('expense_category').setAttribute('required', 'required'); 
                if (category === 'online_purchase') { 
                    imgDiv.style.display = 'block'; 
                } else { 
                    imgDiv.style.display = 'none'; 
                } 
            }
        }
        window.onload = toggleFields;
    </script>
</body>
</html>