<?php
// ২৪ ঘণ্টার জন্য সেশন ও কুকি সেট করা
ini_set('session.cookie_lifetime', 86400);
ini_set('session.gc_maxlifetime', 86400);
session_set_cookie_params(86400);
session_start();
set_time_limit(0); 

// বাংলাদেশের টাইমজোন সেট করা (PHP এর জন্য)
date_default_timezone_set('Asia/Dhaka');

// কেউ লগইন না থাকলে লগইন পেজে পাঠাবে
if(!isset($_SESSION['admin_logged_in'])) { 
    header("Location: login.php"); 
    exit; 
}

// স্টাফ হলে এন্ট্রি পেজে রিডাইরেক্ট করবে
if(isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'staff') {
    header("Location: add_entry.php"); 
    exit;
}

require_once 'db.php';

// ডাটাবেসের টাইমজোন বাংলাদেশের সময়ের সাথে সিঙ্ক করা (MySQL এর জন্য)
try {
    $pdo->exec("SET time_zone = '+06:00'");
} catch(PDOException $e) {}

$message = ''; $error = '';

// প্রজেক্টের লিস্ট আনা হচ্ছে এডিট মোডালের জন্য
$all_projects = $pdo->query("SELECT * FROM projects")->fetchAll();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Approve Logic
    if(isset($_POST['action']) && $_POST['action'] == 'approve') {
        $id = $_POST['entry_id'];
        $stmt = $pdo->prepare("UPDATE financials SET status = 'approved' WHERE id = ?");
        if($stmt->execute([$id])) { 
            $_SESSION['msg_success'] = "হিসাবটি সফলভাবে অ্যাপ্রুভ করা হয়েছে!"; 
        }
        header("Location: financial_reports.php"); exit;
    }
    // Edit Logic
    elseif (isset($_POST['action']) && $_POST['action'] == 'edit') {
        $id = $_POST['entry_id'];
        $type = $_POST['type'];
        $amount = $_POST['amount'];
        $description = $_POST['description'];
        $date_added = $_POST['entry_date'];
        $project_id = empty($_POST['project_id']) ? null : $_POST['project_id']; // প্রজেক্ট আইডি
        
        $expense_category = null;
        $receipt_image = $_POST['existing_image'];

        if ($type === 'expense') {
            $expense_category = $_POST['expense_category'];
            if ($expense_category === 'online_purchase' && isset($_FILES['receipt_image']) && $_FILES['receipt_image']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = 'uploads/';
                if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
                $fileName = time() . '_' . preg_replace("/[^a-zA-Z0-9.]/", "", basename($_FILES['receipt_image']['name']));
                $targetFilePath = $uploadDir . $fileName;
                if(move_uploaded_file($_FILES['receipt_image']['tmp_name'], $targetFilePath)){
                    $receipt_image = $targetFilePath;
                }
            }
        } else { $receipt_image = null; }

        $stmt = $pdo->prepare("UPDATE financials SET project_id=?, type=?, expense_category=?, receipt_image=?, amount=?, description=?, date_added=? WHERE id=?");
        if($stmt->execute([$project_id, $type, $expense_category, $receipt_image, $amount, $description, $date_added, $id])) {
            $_SESSION['msg_success'] = "হিসাব আপডেট হয়েছে!";
        }
        header("Location: financial_reports.php"); exit;
    } 
    // Delete Logic
    elseif (isset($_POST['action']) && $_POST['action'] == 'delete') {
        $id = $_POST['entry_id'];
        $pin = $_POST['secret_pin'];
        
        $username = $_SESSION['admin_username'];
        $stmt = $pdo->prepare("SELECT secret_pin FROM admins WHERE username = ?");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();
        
        if ($admin && $admin['secret_pin'] === $pin) {
            $stmt = $pdo->prepare("DELETE FROM financials WHERE id = ?");
            if($stmt->execute([$id])) { 
                $_SESSION['msg_success'] = "হিসাব ডিলিট হয়েছে!"; 
            }
        } else { 
            $_SESSION['msg_error'] = "ভুল পিন!"; 
        }
        header("Location: financial_reports.php"); exit;
    }
}

// PRG (Post-Redirect-Get) প্যাটার্ন অনুযায়ী সেশন থেকে মেসেজ পড়া
$message = $_SESSION['msg_success'] ?? ''; 
$error = $_SESSION['msg_error'] ?? '';
unset($_SESSION['msg_success'], $_SESSION['msg_error']);

// Data Fetch (Pending গুলো আগে দেখাবে এবং প্রজেক্টের নাম সহ আনবে)
$financials = $pdo->query("
    SELECT f.*, p.project_name 
    FROM financials f 
    LEFT JOIN projects p ON f.project_id = p.id 
    ORDER BY FIELD(f.status, 'pending', 'approved'), f.date_added DESC, f.id DESC
")->fetchAll();

$category_map = ['general' => 'জেনারেল', 'third_party' => 'থার্ড পার্টি', 'online_purchase' => 'অনলাইন', 'no_record' => 'রেকর্ড নেই'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Financial Reports - Sodai Lagbe ERP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap');
        
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; -webkit-tap-highlight-color: transparent;}
        
        .glass-header {
            background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(226,232,240,0.8);
        }
        
        .app-card {
            background: #ffffff; border-radius: 16px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.03), 0 1px 3px rgba(0,0,0,0.02);
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
        
        /* Print Styles */
        @media print {
            @page { size: A4 portrait; margin: 12mm 10mm; }
            html, body, .flex-1, main, .md\:pl-64, .h-screen, .min-h-screen { 
                height: auto !important; min-height: auto !important; overflow: visible !important; 
                display: block !important; background-color: #ffffff !important; color: #1e293b !important; 
                margin: 0 !important; padding: 0 !important;
            }
            #sidebar, #sidebar-overlay, header, .bottom-nav, .print\:hidden, .filter-section { display: none !important; }
            
            .print-wrapper { display: block !important; width: 100%; position: static !important; }
            .print-header-container { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #2563eb; padding-bottom: 15px; margin-bottom: 30px; }
            .print-logo-area h1 { font-size: 28pt; font-weight: 900; margin: 0; color: #1e3a8a !important; text-transform: uppercase; letter-spacing: -0.5px; -webkit-print-color-adjust: exact; }
            .print-logo-area p { font-size: 11pt; font-weight: 600; color: #64748b !important; margin: 5px 0 0 0; text-transform: uppercase; letter-spacing: 1px; }
            .print-meta-area { text-align: right; font-size: 9.5pt; color: #334155 !important; background: #f8fafc !important; padding: 10px 15px; border-radius: 8px; border: 1px solid #e2e8f0; -webkit-print-color-adjust: exact; }
            
            .print-table { width: 100%; border-collapse: separate; border-spacing: 0; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; margin-bottom: 30px; font-size: 9.5pt; page-break-inside: auto; }
            .print-table tr { page-break-inside: avoid; page-break-after: auto; }
            .print-table thead { display: table-header-group; }
            .print-table th, .print-table td { border-bottom: 1px solid #e2e8f0 !important; border-right: 1px solid #f1f5f9 !important; padding: 10px; color: #334155 !important; vertical-align: middle; text-align: left; }
            .print-table th:last-child, .print-table td:last-child { border-right: none !important; }
            .print-table th { background-color: #1e293b !important; color: #ffffff !important; font-weight: 800; text-transform: uppercase; font-size: 8.5pt; letter-spacing: 0.5px; -webkit-print-color-adjust: exact; text-align: center; }
            .print-table tbody tr:nth-child(even) td { background-color: #f8fafc !important; -webkit-print-color-adjust: exact; }
            
            .text-right { text-align: right !important; } .text-center { text-align: center !important; }
            .text-blue { color: #2563eb !important; } .text-green { color: #059669 !important; } .text-red { color: #dc2626 !important; }
            .font-bold { font-weight: 700 !important; }
        }
    </style>
</head>
<body class="text-slate-800 antialiased selection:bg-blue-200 flex h-screen overflow-hidden">

    <div id="sidebar-overlay" class="fixed inset-0 bg-slate-900/60 z-40 hidden md:hidden backdrop-blur-sm transition-opacity" onclick="toggleSidebar()"></div>

    <aside id="sidebar" class="fixed inset-y-0 left-0 bg-slate-900 text-white w-64 flex flex-col transition-transform transform -translate-x-full md:translate-x-0 z-50 shadow-2xl md:shadow-none h-full">
        <div class="flex items-center justify-center h-20 border-b border-slate-800 bg-slate-950 shrink-0">
            <h1 class="text-2xl font-black text-emerald-400 tracking-tight flex items-center gap-2"><i class="fas fa-user-shield"></i> Admin Panel</h1>
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
            <a href="manage_votes.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-vote-yea w-6"></i> ভোটিং ও প্রস্তাবনা</a>
            <a href="manage_video.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-video w-6"></i> লাইভ ভিডিও</a>
            
            <div class="px-6 py-2 text-[10px] font-bold text-slate-500 uppercase tracking-widest mt-4">Finance & Reports</div>
            <a href="add_entry.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-file-invoice-dollar w-6"></i> দৈনিক হিসাব এন্ট্রি</a>
            <a href="financial_reports.php" class="flex items-center px-6 py-3 bg-blue-600/20 text-blue-400 border-l-4 border-blue-500 font-semibold"><i class="fas fa-chart-pie w-6"></i> লাভ-ক্ষতির রিপোর্ট</a>
        </nav>
        <div class="p-4 border-t border-slate-800 shrink-0">
            <a href="logout.php" class="flex items-center px-4 py-2.5 text-red-400 hover:bg-red-500 hover:text-white rounded-lg transition-colors font-bold"><i class="fas fa-sign-out-alt w-6"></i> লগআউট</a>
        </div>
    </aside>

    <div class="flex flex-col min-h-screen w-full md:ml-64 transition-all duration-300">
        
        <header class="glass-header sticky top-0 z-30 px-4 py-3 flex items-center justify-between shadow-sm h-16 shrink-0 print:hidden">
            <div class="flex items-center gap-3">
                <button onclick="toggleSidebar()" class="text-slate-600 focus:outline-none md:hidden text-xl hover:text-blue-600 transition"><i class="fas fa-bars"></i></button>
                <h2 class="text-lg font-black tracking-tight text-slate-800 hidden sm:block">ফিনান্সিয়াল রিপোর্টস</h2>
            </div>
            <div class="flex items-center gap-3">
                <div class="text-right hidden sm:block border-r border-slate-200 pr-3">
                    <div class="text-sm font-bold leading-tight"><?= htmlspecialchars($_SESSION['admin_username']) ?></div>
                    <div class="text-[10px] text-slate-500 font-bold uppercase tracking-wide">System Admin</div>
                </div>
                <div class="h-9 w-9 bg-gradient-to-br from-blue-600 to-indigo-700 rounded-full flex items-center justify-center text-white font-black shadow-md">
                    <?= strtoupper(substr($_SESSION['admin_username'] ?? 'A', 0, 1)) ?>
                </div>
            </div>
        </header>

        <main class="flex-1 overflow-x-hidden overflow-y-auto main-content p-4 md:p-6 custom-scrollbar relative">
            
            <?php if($message): ?><div class="bg-emerald-50 text-emerald-600 px-4 py-3 rounded-xl text-sm font-bold shadow-sm border border-emerald-100 flex items-center animate-fade-in mb-4 print:hidden"><i class="fas fa-check-circle mr-2 text-lg"></i> <?= $message ?></div><?php endif; ?>
            <?php if($error): ?><div class="bg-red-50 text-red-600 px-4 py-3 rounded-xl text-sm font-bold shadow-sm border border-red-100 flex items-center animate-fade-in mb-4 print:hidden"><i class="fas fa-exclamation-triangle mr-2 text-lg"></i> <?= $error ?></div><?php endif; ?>

            <div class="flex flex-wrap gap-3 justify-between items-center mb-4 animate-fade-in print:hidden">
                <div>
                    <h2 class="text-xl md:text-2xl font-black text-slate-800 flex items-center gap-2"><i class="fas fa-chart-pie text-blue-500"></i> লাভ ও খরচের রিপোর্ট</h2>
                    <p class="text-[11px] text-slate-500 mt-1 font-bold">কোম্পানির সকল লেনদেনের বিস্তারিত তালিকা</p>
                </div>
                <div class="flex gap-2">
                    <button onclick="window.print()" class="bg-indigo-600 text-white px-4 py-2 rounded-lg shadow-md hover:bg-indigo-700 transition font-bold flex items-center gap-1.5 text-xs"><i class="fas fa-print"></i> <span class="hidden sm:inline">প্রিন্ট রিপোর্ট</span></button>
                </div>
            </div>

            <div class="filter-section bg-white p-4 rounded-xl border border-slate-200 shadow-sm flex flex-col sm:flex-row justify-between items-center gap-4 mb-6 animate-fade-in" style="animation-delay: 0.1s;">
                <div class="flex items-center w-full sm:w-auto">
                    <div class="w-10 h-10 bg-blue-50 text-blue-500 rounded-lg flex items-center justify-center mr-3 shrink-0"><i class="fas fa-filter"></i></div>
                    <select id="reportFilter" onchange="filterReports()" class="w-full sm:w-48 px-3 py-2 border border-slate-200 rounded-lg bg-slate-50 text-slate-700 font-bold focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 shadow-sm cursor-pointer text-sm">
                        <option value="all">সব হিসাব (All Data)</option>
                        <option value="profit">শুধু লাভ (Profit Only)</option>
                        <option value="expense">শুধু খরচ (Expense Only)</option>
                    </select>
                </div>
                <div class="text-sm font-bold text-slate-600 flex items-center gap-2">
                    মোট এন্ট্রি: <span id="visibleCount" class="bg-blue-100 text-blue-700 px-2 py-0.5 rounded-md text-base">0</span>
                </div>
            </div>

            <div class="block md:hidden space-y-3 pb-4 print:hidden animate-fade-in" style="animation-delay: 0.2s;">
                <?php if(count($financials) > 0): ?>
                    <?php foreach($financials as $fin): 
                        $is_profit = ($fin['type'] === 'profit');
                        $is_pending = ($fin['status'] === 'pending');
                        
                        if($is_pending) { $bg = "bg-white border-l-4 border-amber-400"; $icon_bg = "bg-amber-50 text-amber-500"; }
                        else { $bg = "bg-white border-l-4 ".($is_profit ? 'border-emerald-500' : 'border-rose-500'); $icon_bg = $is_profit ? "bg-emerald-50 text-emerald-500" : "bg-rose-50 text-rose-500"; }
                        
                        $text_color = $is_profit ? 'text-emerald-600' : 'text-rose-600';
                    ?>
                    <div class="report-row type-<?= $fin['type'] ?> p-4 rounded-xl shadow-sm border border-slate-100 <?= $bg ?> relative" data-amount="<?= $fin['amount'] ?>">
                        
                        <?php if($is_pending): ?>
                            <div class="absolute top-0 right-0 bg-amber-400 text-white text-[9px] font-black px-2 py-0.5 rounded-bl-lg uppercase tracking-wider shadow-sm">Pending</div>
                        <?php endif; ?>

                        <div class="flex items-start gap-3 mb-3 border-b border-slate-100 pb-3">
                            <div class="w-10 h-10 rounded-full flex items-center justify-center shrink-0 <?= $icon_bg ?> border border-slate-100"><i class="fas <?= $is_profit ? 'fa-arrow-down' : 'fa-arrow-up' ?> text-sm"></i></div>
                            <div class="flex-1 min-w-0 pr-4">
                                <div class="text-[9px] text-slate-400 font-bold uppercase tracking-wider mb-1"><i class="fas fa-calendar-alt mr-1"></i> <?= date('d M, Y', strtotime($fin['date_added'])) ?></div>
                                <h4 class="text-sm font-bold text-slate-800 leading-snug line-clamp-2"><?= htmlspecialchars($fin['description']) ?></h4>
                                <div class="flex flex-wrap gap-1.5 mt-2">
                                    <span class="bg-indigo-50 text-indigo-600 px-1.5 py-0.5 rounded border border-indigo-100 text-[9px] font-bold"><i class="fas fa-project-diagram mr-1"></i> <?= htmlspecialchars($fin['project_name'] ?? 'General Fund') ?></span>
                                    <?php if(!$is_profit && !empty($fin['expense_category'])): ?>
                                        <span class="bg-slate-100 text-slate-600 px-1.5 py-0.5 rounded border border-slate-200 text-[9px] font-bold"><?= $category_map[$fin['expense_category']] ?? '' ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex justify-between items-end">
                            <div>
                                <span class="text-[9px] font-bold text-slate-400 bg-slate-50 px-2 py-1 rounded border border-slate-100">By: <?= htmlspecialchars($fin['added_by'] ?? 'Admin') ?></span>
                                <?php if(!empty($fin['receipt_image'])): ?>
                                    <button onclick="openImageModal('<?= htmlspecialchars(addslashes($fin['receipt_image'])) ?>')" class="text-[9px] font-bold text-blue-500 bg-blue-50 px-2 py-1 rounded border border-blue-100 ml-1"><i class="fas fa-image mr-1"></i> Memo</button>
                                <?php endif; ?>
                            </div>
                            <div class="text-right">
                                <span class="text-[9px] font-bold text-slate-400 uppercase block mb-0.5"><?= $is_profit ? 'Profit' : 'Expense' ?></span>
                                <span class="text-lg font-black <?= $text_color ?>">৳ <?= number_format($fin['amount'], 2) ?></span>
                            </div>
                        </div>

                        <div class="mt-3 flex gap-2 pt-3 border-t border-slate-50">
                            <?php if($is_pending): ?>
                                <form method="POST" class="flex-1">
                                    <input type="hidden" name="action" value="approve"><input type="hidden" name="entry_id" value="<?= $fin['id'] ?>">
                                    <button type="submit" class="w-full bg-emerald-50 text-emerald-600 border border-emerald-200 px-3 py-1.5 rounded-lg text-xs font-bold hover:bg-emerald-500 hover:text-white transition shadow-sm"><i class="fas fa-check-double mr-1"></i> Approve</button>
                                </form>
                            <?php endif; ?>
                            <button onclick="openEditModal(<?= $fin['id'] ?>, '<?= $fin['date_added'] ?>', '<?= $fin['type'] ?>', '<?= $fin['expense_category'] ?>', <?= $fin['amount'] ?>, '<?= htmlspecialchars(addslashes($fin['description'])) ?>', '<?= htmlspecialchars(addslashes($fin['receipt_image'] ?? '')) ?>', '<?= $fin['project_id'] ?>')" class="flex-1 text-blue-600 hover:text-white bg-blue-50 hover:bg-blue-600 border border-blue-200 py-1.5 rounded-lg shadow-sm transition text-xs font-bold"><i class="fas fa-edit mr-1"></i> Edit</button>
                            <button onclick="openDeleteModal(<?= $fin['id'] ?>)" class="w-10 text-rose-500 hover:text-white bg-rose-50 hover:bg-rose-500 border border-rose-200 py-1.5 rounded-lg shadow-sm transition flex items-center justify-center"><i class="fas fa-trash text-xs"></i></button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="app-card p-8 text-center text-slate-400"><i class="fas fa-inbox text-3xl mb-2 text-slate-300 block"></i> কোনো ডাটা নেই।</div>
                <?php endif; ?>
            </div>

            <div class="hidden md:block app-card overflow-x-auto print:hidden animate-fade-in" style="animation-delay: 0.2s;">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-200 text-[11px] uppercase tracking-wider text-slate-500 font-black">
                            <th class="px-5 py-4 pl-6 text-center w-16">SN</th>
                            <th class="px-5 py-4">স্ট্যাটাস / স্টাফ</th>
                            <th class="px-5 py-4">তারিখ ও ধরন</th>
                            <th class="px-5 py-4 w-1/3">বিবরণ ও প্রজেক্ট</th>
                            <th class="px-5 py-4 text-right">পরিমাণ (৳)</th>
                            <th class="px-5 py-4 text-center">মেমো</th>
                            <th class="px-5 py-4 text-center pr-6">অ্যাকশন</th>
                        </tr>
                    </thead>
                    <tbody class="text-sm divide-y divide-slate-100">
                        <?php if(count($financials) > 0): ?>
                            <?php $sn = 1; ?>
                            <?php foreach($financials as $fin): 
                                $is_profit = ($fin['type'] === 'profit');
                                $is_pending = ($fin['status'] === 'pending');
                                
                                if($is_pending) $row_bg = 'bg-amber-50/30 hover:bg-amber-50 border-l-2 border-amber-400';
                                else $row_bg = 'bg-white hover:bg-slate-50 border-l-2 '.($is_profit ? 'border-emerald-400' : 'border-rose-400');
                                
                                $text_color = $is_profit ? 'text-emerald-600' : 'text-rose-600';
                            ?>
                            <tr class="report-row type-<?= $fin['type'] ?> <?= $row_bg ?> transition-colors" data-amount="<?= $fin['amount'] ?>">
                                <td class="px-5 py-4 pl-6 text-center align-top">
                                    <div class="font-black text-slate-700 text-sm"><?= $sn++ ?></div>
                                    <div class="text-[9px] font-mono text-slate-400 mt-1">#<?= $fin['id'] ?></div>
                                </td>
                                <td class="px-5 py-4 align-top">
                                    <?php if($is_pending): ?>
                                        <span class="bg-amber-100 text-amber-700 px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wide"><i class="fas fa-clock mr-1"></i> Pending</span>
                                    <?php else: ?>
                                        <span class="bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wide"><i class="fas fa-check mr-1"></i> Approved</span>
                                    <?php endif; ?>
                                    <div class="text-[10px] font-bold text-slate-500 mt-2 bg-slate-100 inline-block px-1.5 py-0.5 rounded border border-slate-200">By: <?= htmlspecialchars($fin['added_by'] ?? 'Admin') ?></div>
                                </td>
                                <td class="px-5 py-4 align-top">
                                    <div class="font-bold text-slate-700 text-xs"><i class="fas fa-calendar-alt text-slate-400 mr-1"></i> <?= date('d M, Y', strtotime($fin['date_added'])) ?></div>
                                    <div class="font-black <?= $text_color ?> text-[10px] mt-1.5 uppercase tracking-wide bg-<?= $is_profit?'emerald':'rose' ?>-50 inline-block px-1.5 py-0.5 rounded border border-<?= $is_profit?'emerald':'rose' ?>-100"><?= $is_profit ? 'Profit' : 'Expense' ?></div>
                                </td>
                                <td class="px-5 py-4 align-top">
                                    <div class="text-[10px] font-black text-indigo-600 mb-1.5 bg-indigo-50 inline-block px-2 py-0.5 rounded shadow-sm border border-indigo-100">
                                        <i class="fas fa-project-diagram mr-1"></i> <?= htmlspecialchars($fin['project_name'] ?? 'General Fund') ?>
                                    </div>
                                    <div class="text-xs text-slate-700 font-medium leading-snug"><?= htmlspecialchars($fin['description']) ?></div>
                                    <?php if(!$is_profit && !empty($fin['expense_category'])): ?>
                                        <div class="mt-1.5"><span class="bg-slate-100 text-slate-600 px-1.5 py-0.5 rounded border border-slate-200 text-[9px] font-bold"><?= $category_map[$fin['expense_category']] ?? '' ?></span></div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-4 text-right align-top font-black <?= $text_color ?> text-base whitespace-nowrap">৳ <?= number_format($fin['amount'], 2) ?></td>
                                <td class="px-5 py-4 text-center align-top">
                                    <?php if(!empty($fin['receipt_image'])): ?>
                                        <button onclick="openImageModal('<?= htmlspecialchars(addslashes($fin['receipt_image'])) ?>')" class="w-8 h-8 rounded-lg bg-blue-50 text-blue-600 hover:bg-blue-600 hover:text-white transition flex items-center justify-center mx-auto border border-blue-100 shadow-sm" title="View Memo"><i class="fas fa-image text-sm"></i></button>
                                    <?php else: ?><span class="text-slate-300 text-xs"><i class="fas fa-minus"></i></span><?php endif; ?>
                                </td>
                                <td class="px-5 py-4 pr-6 align-top">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <?php if($is_pending): ?>
                                            <form method="POST" class="m-0">
                                                <input type="hidden" name="action" value="approve"><input type="hidden" name="entry_id" value="<?= $fin['id'] ?>">
                                                <button type="submit" class="w-7 h-7 rounded-lg bg-emerald-50 text-emerald-600 hover:bg-emerald-600 hover:text-white transition flex items-center justify-center border border-emerald-100 shadow-sm" title="Approve"><i class="fas fa-check-double text-[10px]"></i></button>
                                            </form>
                                        <?php endif; ?>
                                        <button onclick="openEditModal(<?= $fin['id'] ?>, '<?= $fin['date_added'] ?>', '<?= $fin['type'] ?>', '<?= $fin['expense_category'] ?>', <?= $fin['amount'] ?>, '<?= htmlspecialchars(addslashes($fin['description'])) ?>', '<?= htmlspecialchars(addslashes($fin['receipt_image'] ?? '')) ?>', '<?= $fin['project_id'] ?>')" class="w-7 h-7 rounded-lg bg-blue-50 text-blue-600 hover:bg-blue-600 hover:text-white transition flex items-center justify-center border border-blue-100 shadow-sm" title="Edit"><i class="fas fa-edit text-[10px]"></i></button>
                                        <button onclick="openDeleteModal(<?= $fin['id'] ?>)" class="w-7 h-7 rounded-lg bg-rose-50 text-rose-500 hover:bg-rose-600 hover:text-white transition flex items-center justify-center border border-rose-100 shadow-sm" title="Delete"><i class="fas fa-trash text-[10px]"></i></button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr id="noDataRow"><td colspan="7" class="px-6 py-8 text-center text-slate-400 text-sm font-medium"><i class="fas fa-inbox text-2xl mb-2 block opacity-50"></i> কোনো ডাটা নেই।</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="mt-4 bg-gradient-to-r from-slate-800 to-slate-900 rounded-xl p-5 text-white flex justify-between items-center shadow-lg print:hidden">
                <span class="text-sm font-bold uppercase tracking-wider text-slate-300">সর্বমোট পরিমাণ:</span>
                <span class="text-2xl font-black tracking-tight" id="totalAmountDisplay">৳ 0.00</span>
            </div>

            <div class="print-wrapper">
                <div class="print-header-container">
                    <div class="print-logo-area">
                        <h1>Sodai Lagbe</h1>
                        <p id="print_title_display">কোম্পানির লাভ ও খরচের রিপোর্ট</p>
                    </div>
                    <div class="print-meta-area">
                        <div><i class="fas fa-calendar-alt"></i> <b>তারিখ:</b> <?= date('d M, Y') ?></div>
                        <div style="margin-top:3px;"><i class="fas fa-clock"></i> <b>সময়:</b> <?= date('h:i A') ?></div>
                        <div style="margin-top:3px;"><i class="fas fa-user-shield"></i> <b>প্রিন্ট বাই:</b> Admin</div>
                    </div>
                </div>

                <table class="print-table">
                    <thead>
                        <tr>
                            <th style="width:5%;">নং</th>
                            <th style="width:15%;">তারিখ</th>
                            <th style="width:15%;">প্রজেক্ট</th>
                            <th style="width:10%;">ধরন</th>
                            <th style="width:35%;">বিবরণ</th>
                            <th style="width:10%;">স্ট্যাটাস</th>
                            <th class="text-right" style="width:10%;">পরিমাণ (৳)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $sn = 1; foreach($financials as $fin): ?>
                        <tr class="report-row type-<?= $fin['type'] ?>" data-amount="<?= $fin['amount'] ?>">
                            <td class="text-center font-bold"><?= $sn++ ?></td>
                            <td class="text-center"><?= date('d/m/Y', strtotime($fin['date_added'])) ?></td>
                            <td class="font-bold text-blue text-center"><?= htmlspecialchars($fin['project_name'] ?? 'General Fund') ?></td>
                            <td class="text-center font-bold <?= $fin['type']=='profit' ? 'text-green' : 'text-red' ?>"><?= $fin['type']=='profit' ? 'Profit' : 'Expense' ?></td>
                            <td><?= htmlspecialchars($fin['description']) ?></td>
                            <td class="text-center font-bold text-[10px]"><?= $fin['status']=='pending' ? 'Pending' : 'Approved' ?></td>
                            <td class="text-right font-bold">৳ <?= number_format($fin['amount'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot style="border-top: 2px solid #94a3b8;">
                        <tr>
                            <td colspan="6" class="text-right font-bold uppercase" style="padding: 12px; font-size: 11pt;">সর্বমোট পরিমাণ:</td>
                            <td class="text-right font-bold text-blue" style="padding: 12px; font-size: 12pt;" id="printTotalAmountDisplay">৳ 0.00</td>
                        </tr>
                    </tfoot>
                </table>

                <div class="print-footer">
                    Sodai Lagbe ERP System | Generated automatically on <?= date('d M, Y h:i A') ?>
                </div>
            </div>

        </main>
    </div>

    <nav class="bottom-nav shadow-[0_-5px_15px_rgba(0,0,0,0.05)]">
        <a href="index.php" class="nav-item"><i class="fas fa-tachometer-alt"></i> Home</a>
        <a href="manage_shareholders.php" class="nav-item"><i class="fas fa-users"></i> Users</a>
        <a href="financial_reports.php" class="nav-item active"><i class="fas fa-chart-pie"></i> Report</a>
        <button onclick="toggleSidebar()" class="nav-item bg-transparent border-none outline-none focus:outline-none"><i class="fas fa-bars"></i> Menu</button>
    </nav>

    <div id="editModal" class="hidden print:hidden fixed inset-0 bg-slate-900/60 flex items-center justify-center z-50 p-4 backdrop-blur-sm transition-opacity">
        <div class="bg-white p-6 rounded-2xl shadow-2xl w-full max-w-lg border border-slate-100 max-h-[90vh] overflow-y-auto custom-scrollbar">
            <h3 class="text-lg font-black mb-4 border-b border-slate-100 pb-3 text-slate-800 flex items-center gap-2"><i class="fas fa-edit text-blue-500"></i> হিসাব এডিট করুন</h3>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="entry_id" id="edit_entry_id">
                <input type="hidden" name="existing_image" id="existing_image">
                
                <div class="mb-4 bg-indigo-50/50 p-3.5 rounded-xl border border-indigo-100">
                    <label class="block text-[10px] font-bold mb-1.5 text-indigo-900 uppercase">কোন প্রজেক্টের হিসাব?</label>
                    <select name="project_id" id="edit_project_id" class="w-full px-4 py-2.5 border border-slate-200 rounded-lg bg-white focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none shadow-sm text-sm font-bold text-slate-800 cursor-pointer">
                        <option value="">-- General Fund (কোম্পানির মূল হিসাব) --</option>
                        <?php foreach($all_projects as $proj): ?>
                            <option value="<?= $proj['id'] ?>"><?= htmlspecialchars($proj['project_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-[10px] font-bold mb-1.5 text-slate-500 uppercase">তারিখ</label>
                        <input type="date" name="entry_date" id="edit_entry_date" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg focus:bg-white focus:border-blue-500 outline-none text-sm font-bold text-slate-700 transition" required>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold mb-1.5 text-slate-500 uppercase">হিসাবের ধরন</label>
                        <select name="type" id="edit_entry_type" onchange="toggleEditFields()" class="w-full px-4 py-2.5 border border-slate-200 rounded-lg bg-slate-50 focus:bg-white focus:border-blue-500 outline-none text-sm font-bold text-slate-700 transition cursor-pointer" required>
                            <option value="expense">খরচ (Debit)</option>
                            <option value="profit">দৈনিক লাভ (Profit)</option>
                        </select>
                    </div>
                </div>

                <div id="edit_category_div" class="mb-4 bg-rose-50/50 p-4 rounded-xl border border-rose-100 transition-all duration-300">
                    <label class="block text-[10px] font-bold mb-1.5 text-rose-900 uppercase">খরচের ক্যাটাগরি</label>
                    <select name="expense_category" id="edit_expense_category" onchange="toggleEditFields()" class="w-full px-4 py-2.5 border border-slate-200 rounded-lg bg-white focus:border-rose-500 outline-none shadow-sm text-sm font-bold text-slate-800 cursor-pointer">
                        <option value="general">জেনারেল খরচ</option>
                        <option value="third_party">থার্ড পার্টি</option>
                        <option value="online_purchase">অনলাইন পারচেজ</option>
                        <option value="no_record">রেকর্ড নেই</option>
                    </select>
                </div>

                <div id="edit_image_div" class="mb-4 hidden bg-blue-50/50 p-4 rounded-xl border border-blue-100 transition-all duration-300">
                    <label class="block text-[10px] font-bold mb-1.5 text-blue-900 uppercase"><i class="fas fa-image mr-1"></i> নতুন মেমো (পরিবর্তন করতে চাইলে)</label>
                    <input type="file" name="receipt_image" accept="image/*" class="w-full px-3 py-2 bg-white border border-blue-200 rounded-lg text-xs font-medium text-slate-600 file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-[10px] file:font-bold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 cursor-pointer transition">
                </div>

                <div class="mb-4">
                    <label class="block text-[10px] font-bold mb-1.5 text-slate-500 uppercase">পরিমাণ (টাকা)</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-4 text-slate-400 font-black">৳</span>
                        <input type="number" step="0.01" name="amount" id="edit_amount" class="w-full pl-8 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg focus:bg-white focus:border-blue-500 outline-none text-sm font-black text-slate-800 transition" required>
                    </div>
                </div>

                <div class="mb-6">
                    <label class="block text-[10px] font-bold mb-1.5 text-slate-500 uppercase">বিবরণ</label>
                    <textarea name="description" id="edit_description" rows="2" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:border-blue-500 outline-none text-sm text-slate-700 custom-scrollbar transition" required></textarea>
                </div>

                <div class="flex gap-2 border-t border-slate-100 pt-4">
                    <button type="button" onclick="closeModals()" class="flex-1 py-3 bg-slate-100 text-slate-600 rounded-xl font-bold hover:bg-slate-200 transition text-sm">বাতিল</button>
                    <button type="submit" class="flex-1 py-3 bg-blue-600 text-white rounded-xl font-bold hover:bg-blue-700 shadow-md transition text-sm">আপডেট করুন</button>
                </div>
            </form>
        </div>
    </div>

    <div id="deleteModal" class="hidden print:hidden fixed inset-0 bg-slate-900/60 flex items-center justify-center z-50 p-4 backdrop-blur-sm transition-opacity">
        <div class="bg-white p-6 rounded-2xl shadow-2xl w-full max-w-sm border border-slate-100 text-center">
            <div class="w-14 h-14 bg-red-100 text-red-500 rounded-full flex items-center justify-center text-2xl mx-auto mb-3"><i class="fas fa-exclamation-triangle"></i></div>
            <h3 class="text-lg font-black text-slate-800 mb-2">সতর্কতা!</h3>
            <p class="text-[11px] font-bold text-slate-500 mb-5 leading-relaxed">এই হিসাবটি ডিলিট করলে তা ডাটাবেস থেকে মুছে যাবে। নিশ্চিত করতে পিন দিন।</p>
            <form method="POST">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="entry_id" id="delete_entry_id">
                <input type="password" name="secret_pin" placeholder="অ্যাডমিন PIN..." class="w-full px-4 py-3 border border-red-200 rounded-xl mb-5 focus:border-red-500 focus:ring-1 focus:ring-red-500 outline-none bg-red-50/50 text-center font-bold tracking-[0.5em] text-red-700 transition" required>
                <div class="flex gap-2">
                    <button type="button" onclick="closeModals()" class="flex-1 py-2.5 bg-slate-100 text-slate-600 rounded-xl font-bold hover:bg-slate-200 transition text-sm">বাতিল</button>
                    <button type="submit" class="flex-1 py-2.5 bg-red-600 text-white rounded-xl font-bold hover:bg-red-700 shadow-md transition text-sm">ডিলিট করুন</button>
                </div>
            </form>
        </div>
    </div>

    <div id="imageModal" class="hidden print:hidden fixed inset-0 bg-slate-900/90 flex items-center justify-center z-50 p-4 backdrop-blur-sm transition-opacity">
        <div class="bg-white p-3 rounded-2xl shadow-2xl max-w-3xl w-full relative">
            <button onclick="closeImageModal()" class="absolute -top-3 -right-3 bg-red-500 text-white w-8 h-8 rounded-full font-bold text-sm hover:bg-red-600 shadow-lg border-2 border-white flex items-center justify-center transition"><i class="fas fa-times"></i></button>
            <div class="flex justify-between items-center mb-3 px-2 pt-1 border-b border-slate-100 pb-2">
                <h3 class="text-sm font-black text-slate-800"><i class="fas fa-file-image text-blue-500 mr-2"></i> মেমো / প্রমাণপত্র</h3>
            </div>
            <div class="flex justify-center overflow-hidden rounded-xl bg-slate-100 p-2" style="max-height: 75vh;">
                <img id="receipt_image_display" src="" alt="Receipt Image" class="object-contain max-h-full rounded-lg shadow-sm">
            </div>
        </div>
    </div>

    <script>
        function filterReports() {
            var filterValue = document.getElementById('reportFilter').value;
            var rows = document.querySelectorAll('.report-row');
            var printTitleDisplay = document.getElementById('print_title_display');
            var visibleCount = 0;
            var totalAmount = 0;

            rows.forEach(function(row) {
                if (filterValue === 'all' || row.classList.contains('type-' + filterValue)) {
                    row.style.display = '';
                    visibleCount++;
                    totalAmount += parseFloat(row.getAttribute('data-amount') || 0);
                } else {
                    row.style.display = 'none';
                }
            });

            document.getElementById('visibleCount').innerText = visibleCount;
            
            var formattedAmount = '৳ ' + totalAmount.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById('totalAmountDisplay').innerText = formattedAmount;
            document.getElementById('printTotalAmountDisplay').innerText = formattedAmount;

            if (filterValue === 'profit') {
                printTitleDisplay.innerText = "কোম্পানির লাভের রিপোর্ট (Profit)";
            } else if (filterValue === 'expense') {
                printTitleDisplay.innerText = "কোম্পানির খরচের রিপোর্ট (Expense)";
            } else {
                printTitleDisplay.innerText = "কোম্পানির লাভ ও খরচের রিপোর্ট";
            }
        }

        window.onload = function() {
            filterReports();
            if(document.getElementById('edit_entry_type')) toggleEditFields();
        };

        function openImageModal(imageSrc) {
            document.getElementById('receipt_image_display').src = imageSrc;
            document.getElementById('imageModal').classList.remove('hidden');
        }
        function closeImageModal() {
            document.getElementById('imageModal').classList.add('hidden');
            document.getElementById('receipt_image_display').src = '';
        }
        function openEditModal(id, date, type, category, amount, desc, img, proj_id) {
            document.getElementById('edit_entry_id').value = id;
            document.getElementById('edit_entry_date').value = date;
            document.getElementById('edit_entry_type').value = type;
            document.getElementById('edit_amount').value = amount;
            document.getElementById('edit_description').value = desc;
            document.getElementById('existing_image').value = img;
            
            document.getElementById('edit_project_id').value = proj_id || '';
            
            if(category) document.getElementById('edit_expense_category').value = category;
            else document.getElementById('edit_expense_category').value = 'general';
            toggleEditFields();
            document.getElementById('editModal').classList.remove('hidden');
        }
        function openDeleteModal(id) {
            document.getElementById('delete_entry_id').value = id;
            document.getElementById('deleteModal').classList.remove('hidden');
        }
        function closeModals() {
            document.getElementById('editModal').classList.add('hidden');
            document.getElementById('deleteModal').classList.add('hidden');
        }
        function toggleEditFields() {
            var type = document.getElementById('edit_entry_type').value;
            var catDiv = document.getElementById('edit_category_div');
            var imgDiv = document.getElementById('edit_image_div');
            var category = document.getElementById('edit_expense_category').value;
            if (type === 'profit') {
                catDiv.style.display = 'none'; imgDiv.style.display = 'none';
                document.getElementById('edit_expense_category').removeAttribute('required');
            } else {
                catDiv.style.display = 'block';
                document.getElementById('edit_expense_category').setAttribute('required', 'required');
                if (category === 'online_purchase') imgDiv.style.display = 'block';
                else imgDiv.style.display = 'none';
            }
        }
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('-translate-x-full');
            document.getElementById('sidebar-overlay').classList.toggle('hidden');
        }
    </script>
</body>
</html>