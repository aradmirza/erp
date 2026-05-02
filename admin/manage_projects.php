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

// === স্টাফ এক্সেস রেস্ট্রিকশন ===
if(isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'staff') {
    header("Location: add_entry.php"); 
    exit;
}

require_once 'db.php';

// সিস্টেম সেটিংস টেবিল ও প্রজেক্ট টেবিলে নতুন কলাম নিশ্চিত করা
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `system_settings` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `setting_name` varchar(100) NOT NULL UNIQUE,
      `setting_value` text NULL,
      PRIMARY KEY (`id`)
    )");

    // প্রতিটি প্রজেক্টের আলাদা কমিশনের জন্য কলাম যুক্ত করা
    $chk_col = $pdo->query("SHOW COLUMNS FROM `projects` LIKE 'mother_commission_pct'");
    if ($chk_col && $chk_col->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `projects` ADD COLUMN `mother_commission_pct` FLOAT NOT NULL DEFAULT 0");
    }
} catch (PDOException $e) {}

$message = ''; $error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // প্রজেক্ট এডিট (Active, Passive & Individual Mother Commission)
    if (isset($_POST['action']) && $_POST['action'] == 'edit') {
        $id = (int)$_POST['project_id'];
        $name = trim($_POST['project_name']);
        $active = (float)$_POST['active_percent'];
        $passive = (float)$_POST['passive_percent'];
        $dist_type = $_POST['dist_type']; 
        $mother_comm = (float)($_POST['mother_commission_pct'] ?? 0);
        
        $has_active_split = isset($_POST['has_active_split']) ? 1 : 0;
        $active_inv = $has_active_split ? (float)$_POST['active_investment_percent'] : 0;
        $active_lab = $has_active_split ? (float)$_POST['active_labor_percent'] : 0;

        if(($active + $passive) > 100) {
            $error = "অ্যাক্টিভ এবং প্যাসিভ ফান্ডের যোগফল ১০০% এর বেশি হতে পারবে না!";
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE projects SET project_name = ?, active_percent = ?, passive_percent = ?, dist_type = ?, has_active_split = ?, active_investment_percent = ?, active_labor_percent = ?, mother_commission_pct = ? WHERE id = ?");
                if($stmt->execute([$name, $active, $passive, $dist_type, $has_active_split, $active_inv, $active_lab, $mother_comm, $id])) {
                    $message = "প্রজেক্ট সেটিং সফলভাবে আপডেট হয়েছে!";
                }
            } catch(PDOException $e) {
                $error = "আপডেট করতে সমস্যা হয়েছে: " . $e->getMessage();
            }
        }
    } 
    
    // প্রজেক্ট ডিলিট
    elseif (isset($_POST['action']) && $_POST['action'] == 'delete') {
        $id = $_POST['project_id'];
        $pin = $_POST['secret_pin'];
        
        $username = $_SESSION['admin_username'];
        $stmt = $pdo->prepare("SELECT secret_pin FROM admins WHERE username = ?");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();
        
        if ($admin && $admin['secret_pin'] === $pin) {
            try {
                $stmt = $pdo->prepare("UPDATE shareholders SET assigned_project_id = NULL WHERE assigned_project_id = ?");
                $stmt->execute([$id]);
                $stmt = $pdo->prepare("DELETE FROM projects WHERE id = ?");
                if($stmt->execute([$id])) { 
                    $message = "প্রজেক্ট সফলভাবে ডিলিট হয়েছে!"; 
                    $pdo->prepare("UPDATE system_settings SET setting_value = '0' WHERE setting_name = 'mother_project_id' AND setting_value = ?")->execute([$id]);
                }
            } catch(PDOException $e) {
                $error = "ডিলিট করতে সমস্যা হয়েছে।";
            }
        } else {
            $error = "ভুল পিন! ডিলিট করা সম্ভব হয়নি।";
        }
    }

    // মাদার প্রজেক্ট নির্বাচন (শুধু ID সেভ করবে, কমিশন নয়)
    elseif (isset($_POST['action']) && $_POST['action'] == 'save_mother_settings') {
        $m_id = empty($_POST['mother_project_id']) ? 0 : (int)$_POST['mother_project_id'];
        try {
            $pdo->prepare("INSERT INTO system_settings (setting_name, setting_value) VALUES ('mother_project_id', ?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$m_id, $m_id]);
            $message = "মাদার প্রজেক্ট সফলভাবে নির্ধারণ করা হয়েছে!";
        } catch (PDOException $e) {
            $error = "মাদার প্রজেক্ট সেভ করতে সমস্যা হয়েছে।";
        }
    }
}

// প্রজেক্ট ফেচ করা
$projects = $pdo->query("SELECT * FROM projects ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

// ওয়েবসাইট লোগো ও মাদার প্রজেক্ট সেটিংস ফেচ করা
$sys_settings_stmt = $pdo->query("SELECT setting_name, setting_value FROM system_settings WHERE setting_name IN ('site_logo', 'mother_project_id')");
$settings_data = $sys_settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$site_logo = $settings_data['site_logo'] ?? '';
$mother_project_id = (int)($settings_data['mother_project_id'] ?? 0);

// ==========================================
// মাদার প্রজেক্টের লাইভ প্রফিট ক্যালকুলেশন
// ==========================================
$mother_earnings_details = [];
$total_mother_earnings = 0;
$mother_project_name = "নির্ধারণ করা হয়নি";

foreach ($projects as &$p) {
    // প্রতিটি প্রজেক্টের মোট প্রফিট বের করা
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM financials WHERE type='profit' AND status='approved' AND project_id = ?");
    $stmt->execute([$p['id']]);
    $p['project_profit'] = (float)$stmt->fetchColumn();

    if ($p['id'] == $mother_project_id) {
        $mother_project_name = $p['project_name'];
    }

    // যদি প্রজেক্টটি মাদার প্রজেক্ট না হয় এবং এর কমিশন > ০ হয়
    if ($mother_project_id > 0 && $p['id'] != $mother_project_id && $p['mother_commission_pct'] > 0) {
        $commission_amount = $p['project_profit'] * ($p['mother_commission_pct'] / 100);
        if ($commission_amount > 0) {
            $total_mother_earnings += $commission_amount;
            $mother_earnings_details[] = [
                'name' => $p['project_name'],
                'pct' => $p['mother_commission_pct'],
                'amount' => $commission_amount
            ];
        }
    }
}
unset($p);
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Manage Projects - Sodai Lagbe ERP</title>
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
            background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
            border-top: 1px solid rgba(0,0,0,0.05); padding-bottom: env(safe-area-inset-bottom);
            z-index: 50; display: flex; justify-content: space-around;
        }
        .nav-item {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            padding: 10px 0; color: #64748b; font-size: 10px; font-weight: 700; width: 100%; transition: all 0.2s; text-decoration: none;
        }
        .nav-item.active { color: #2563eb; }
        .nav-item i { font-size: 18px; margin-bottom: 3px; transition: transform 0.2s;}
        .nav-item.active i { transform: translateY(-2px); }
        
        @media (min-width: 768px) { .bottom-nav { display: none; } }
        .main-content { padding-bottom: 80px; }
        @media (min-width: 768px) { .main-content { padding-bottom: 30px; } }
        
        @keyframes fadeInFast { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fade-in { animation: fadeInFast 0.4s ease-out forwards; }
        
        /* Modern Checkbox */
        .toggle-checkbox:checked { right: 0; border-color: #4f46e5; }
        .toggle-checkbox:checked + .toggle-label { background-color: #4f46e5; }
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
            <a href="manage_projects.php" class="flex items-center px-6 py-3 bg-blue-600/20 text-blue-400 border-l-4 border-blue-500 font-semibold"><i class="fas fa-project-diagram w-6"></i> প্রজেক্ট লিস্ট</a>
            <a href="add_project.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-plus-square w-6"></i> নতুন প্রজেক্ট</a>
            <a href="manage_staff.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-users-cog w-6"></i> স্টাফ ম্যানেজমেন্ট</a>
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
                <h2 class="text-lg font-black tracking-tight text-slate-800 hidden sm:block">প্রজেক্ট ম্যানেজমেন্ট</h2>
                <h2 class="text-lg font-black tracking-tight text-slate-800 sm:hidden">প্রজেক্ট লিস্ট</h2>
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

        <main class="flex-1 overflow-x-hidden overflow-y-auto main-content p-4 md:p-6 custom-scrollbar relative bg-slate-50">
            
            <div class="max-w-6xl mx-auto space-y-6">
                
                <?php if($message): ?><div class="bg-emerald-50 text-emerald-600 px-4 py-3 rounded-xl text-sm font-bold shadow-sm border border-emerald-100 flex items-center animate-fade-in"><i class="fas fa-check-circle mr-2 text-lg"></i> <?= $message ?></div><?php endif; ?>
                <?php if($error): ?><div class="bg-rose-50 text-rose-600 px-4 py-3 rounded-xl text-sm font-bold shadow-sm border border-rose-100 flex items-center animate-fade-in"><i class="fas fa-exclamation-triangle mr-2 text-lg"></i> <?= $error ?></div><?php endif; ?>

                <div class="flex flex-wrap gap-4 justify-between items-center animate-fade-in">
                    <div>
                        <h2 class="text-xl md:text-2xl font-black text-slate-800 flex items-center gap-2"><i class="fas fa-project-diagram text-blue-500"></i> প্রজেক্ট লিস্ট ও সেটিংস</h2>
                        <p class="text-[11px] text-slate-500 mt-1 font-bold">এখানে সেট করা পার্সেন্টেজ অনুযায়ী ড্যাশবোর্ডে ফান্ডের পরিমাণ দেখাবে</p>
                    </div>
                    <div class="flex gap-2">
                        <button onclick="openMotherModal()" class="bg-indigo-100 text-indigo-700 border border-indigo-200 px-4 py-2.5 rounded-xl shadow-sm hover:bg-indigo-600 hover:text-white transition font-bold flex items-center gap-2 text-xs sm:text-sm active:scale-95">
                            <i class="fas fa-crown text-amber-500 hover:text-white transition-colors"></i> <span class="hidden sm:inline">মাদার প্রজেক্ট সেটিং</span>
                        </button>
                        <a href="add_project.php" class="bg-blue-600 text-white px-4 py-2.5 rounded-xl shadow-md hover:bg-blue-700 transition font-bold flex items-center gap-2 text-xs sm:text-sm active:scale-95">
                            <i class="fas fa-plus"></i> <span class="hidden sm:inline">নতুন প্রজেক্ট</span>
                        </a>
                    </div>
                </div>

                <?php if($mother_project_id > 0): ?>
                <div class="app-card bg-gradient-to-br from-indigo-800 to-slate-900 overflow-hidden shadow-lg border-none animate-fade-in mb-8 relative">
                    <i class="fas fa-crown absolute -right-6 -bottom-6 text-9xl text-indigo-500 opacity-20"></i>
                    <div class="p-6 relative z-10">
                        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6 border-b border-indigo-500/30 pb-4">
                            <div>
                                <h3 class="font-black text-white text-lg flex items-center gap-2"><i class="fas fa-chart-pie text-amber-400"></i> মাদার প্রজেক্ট লাইভ আর্নিংস</h3>
                                <p class="text-indigo-200 text-xs mt-1">মাদার প্রজেক্ট <b>(<?= htmlspecialchars($mother_project_name) ?>)</b> অন্যান্য চাইল্ড প্রজেক্ট থেকে কত টাকা কমিশন পাচ্ছে তার লাইভ হিসাব।</p>
                            </div>
                            <div class="bg-black/30 backdrop-blur-sm border border-white/10 px-5 py-3 rounded-xl text-right shrink-0">
                                <div class="text-[10px] text-indigo-200 font-bold uppercase tracking-widest mb-1">মোট অর্জিত কমিশন</div>
                                <div class="text-2xl font-black text-emerald-400 drop-shadow-sm">৳ <?= number_format($total_mother_earnings, 0) ?></div>
                            </div>
                        </div>

                        <?php if(count($mother_earnings_details) > 0): ?>
                            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                                <?php foreach($mother_earnings_details as $med): ?>
                                    <div class="bg-white/10 border border-white/20 backdrop-blur-md p-4 rounded-xl hover:bg-white/20 transition">
                                        <div class="text-sm font-bold text-white mb-1 truncate" title="<?= htmlspecialchars($med['name']) ?>"><?= htmlspecialchars($med['name']) ?></div>
                                        <div class="flex justify-between items-end mt-3">
                                            <span class="text-[10px] bg-indigo-500/50 text-indigo-100 px-2 py-0.5 rounded font-bold uppercase tracking-wider border border-indigo-400/30">Gives <?= $med['pct'] ?>%</span>
                                            <span class="text-lg font-black text-white">৳ <?= number_format($med['amount'], 0) ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="bg-white/5 border border-white/10 rounded-xl p-8 text-center text-indigo-200">
                                <i class="fas fa-inbox text-4xl mb-3 opacity-50"></i>
                                <p class="text-sm font-bold">চাইল্ড প্রজেক্টগুলো থেকে এখনো কোনো কমিশন যোগ হয়নি।</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                <div class="block md:hidden space-y-4 animate-fade-in" style="animation-delay: 0.1s;">
                    <?php if(count($projects) > 0): ?>
                        <?php foreach($projects as $p): 
                            $is_mother = ($p['id'] == $mother_project_id);
                        ?>
                            <div class="app-card bg-white overflow-hidden hover:border-blue-300 transition-colors shadow-sm relative <?= $is_mother ? 'border-amber-300 ring-2 ring-amber-100' : '' ?>">
                                <div class="p-4 border-b border-slate-100 flex justify-between items-start <?= $is_mother ? 'bg-amber-50/50' : 'bg-slate-50/50' ?>">
                                    <div>
                                        <h3 class="font-black text-slate-800 text-base leading-tight mb-1.5 flex items-center gap-1.5">
                                            <?= htmlspecialchars($p['project_name']) ?>
                                            <?php if($is_mother): ?>
                                                <i class="fas fa-crown text-amber-500 text-sm" title="Mother Project"></i>
                                            <?php endif; ?>
                                        </h3>
                                        <div class="flex flex-wrap gap-1.5">
                                            <?php if(isset($p['dist_type']) && $p['dist_type'] == 'by_investment'): ?>
                                                <span class="bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded text-[9px] font-black uppercase tracking-wider shadow-sm border border-indigo-200">By Inv (টাকা)</span>
                                            <?php else: ?>
                                                <span class="bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded text-[9px] font-black uppercase tracking-wider shadow-sm border border-emerald-200">By Share (শেয়ার)</span>
                                            <?php endif; ?>
                                            
                                            <?php if($is_mother): ?>
                                                <span class="bg-amber-100 text-amber-700 px-2 py-0.5 rounded text-[9px] font-black uppercase tracking-wider shadow-sm border border-amber-200">Mother Project</span>
                                            <?php elseif($p['mother_commission_pct'] > 0): ?>
                                                <span class="bg-slate-100 text-slate-600 px-2 py-0.5 rounded text-[9px] font-black uppercase tracking-wider shadow-sm border border-slate-200"><i class="fas fa-arrow-up mr-1 text-rose-400"></i> Gives <?= $p['mother_commission_pct'] ?>%</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="flex gap-1.5 shrink-0">
                                        <button onclick="openEditModal(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['project_name'])) ?>', <?= $p['active_percent'] ?>, <?= $p['passive_percent'] ?>, '<?= $p['dist_type'] ?? 'by_share' ?>', <?= $p['has_active_split'] ?>, <?= $p['active_investment_percent'] ?>, <?= $p['active_labor_percent'] ?>, <?= $p['mother_commission_pct'] ?>)" class="w-8 h-8 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center hover:bg-blue-600 hover:text-white transition shadow-sm border border-blue-100"><i class="fas fa-cog text-xs"></i></button>
                                        <button onclick="openDeleteModal(<?= $p['id'] ?>)" class="w-8 h-8 rounded-lg bg-rose-50 text-rose-500 flex items-center justify-center hover:bg-rose-500 hover:text-white transition shadow-sm border border-rose-100"><i class="fas fa-trash text-xs"></i></button>
                                    </div>
                                </div>
                                
                                <div class="p-4">
                                    <div class="flex justify-between items-end mb-2">
                                        <div>
                                            <p class="text-[9px] font-black text-blue-500 uppercase tracking-widest mb-0.5">অ্যাক্টিভ ফান্ড</p>
                                            <p class="text-base font-black text-blue-700 leading-tight"><?= $p['active_percent'] ?>%</p>
                                        </div>
                                        <div class="text-right">
                                            <p class="text-[9px] font-black text-orange-500 uppercase tracking-widest mb-0.5">প্যাসিভ ফান্ড</p>
                                            <p class="text-base font-black text-orange-700 leading-tight"><?= $p['passive_percent'] ?>%</p>
                                        </div>
                                    </div>
                                    <div class="w-full h-2.5 rounded-full overflow-hidden flex shadow-inner border border-slate-100 bg-slate-100 mb-3">
                                        <div class="bg-blue-500 h-full" style="width: <?= $p['active_percent'] ?>%"></div>
                                        <div class="bg-orange-400 h-full" style="width: <?= $p['passive_percent'] ?>%"></div>
                                    </div>

                                    <?php if($p['has_active_split']): ?>
                                        <div class="bg-blue-50/50 p-2.5 rounded-lg border border-blue-100 mt-3">
                                            <p class="text-[9px] font-bold text-blue-800 uppercase tracking-widest mb-2 border-b border-blue-200 pb-1">অ্যাক্টিভ ফান্ড স্প্লিট (ম্যানুয়াল)</p>
                                            <div class="flex justify-between items-center text-[10px] font-bold text-slate-600 mb-1">
                                                <span><i class="fas fa-coins text-amber-500 mr-1"></i> অর্থ বিনিয়োগকারী:</span>
                                                <span class="text-blue-700 font-black"><?= $p['active_investment_percent'] ?>%</span>
                                            </div>
                                            <div class="flex justify-between items-center text-[10px] font-bold text-slate-600">
                                                <span><i class="fas fa-briefcase text-emerald-500 mr-1"></i> শ্রমদানকারী:</span>
                                                <span class="text-blue-700 font-black"><?= $p['active_labor_percent'] ?>%</span>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="app-card p-10 text-center bg-white border-dashed">
                            <i class="fas fa-project-diagram text-4xl mb-3 text-slate-300 block"></i>
                            <p class="font-bold text-sm text-slate-500">কোনো প্রজেক্ট তৈরি করা হয়নি।</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="hidden md:block app-card overflow-hidden shadow-sm animate-fade-in" style="animation-delay: 0.2s;">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-slate-50 border-b border-slate-200 text-[11px] uppercase tracking-wider text-slate-500 font-black">
                                <th class="px-5 py-4 pl-6">প্রজেক্টের নাম</th>
                                <th class="px-5 py-4 text-center">লাভ বণ্টন পলিসি</th>
                                <th class="px-5 py-4 text-center">অ্যাক্টিভ ফান্ড (%)</th>
                                <th class="px-5 py-4 text-center">প্যাসিভ ফান্ড (%)</th>
                                <th class="px-5 py-4 text-right pr-6">অ্যাকশন</th>
                            </tr>
                        </thead>
                        <tbody class="text-sm divide-y divide-slate-100">
                            <?php if(count($projects) > 0): ?>
                                <?php foreach($projects as $p): 
                                    $is_mother = ($p['id'] == $mother_project_id);
                                ?>
                                <tr class="hover:bg-slate-50 transition-colors group <?= $is_mother ? 'bg-amber-50/20' : 'bg-white' ?>">
                                    <td class="px-5 py-4 pl-6 align-top">
                                        <div class="font-black text-slate-800 text-base mb-1.5 flex items-center gap-2">
                                            <?= htmlspecialchars($p['project_name']) ?>
                                            <?php if($is_mother): ?>
                                                <i class="fas fa-crown text-amber-500 text-sm drop-shadow-sm" title="Mother Project"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="flex flex-wrap gap-2">
                                            <span class="text-[9px] font-bold text-slate-400 uppercase tracking-widest bg-slate-100 px-2 py-0.5 rounded border border-slate-200">ID: PRJ-<?= str_pad($p['id'], 4, '0', STR_PAD_LEFT) ?></span>
                                            
                                            <?php if($is_mother): ?>
                                                <span class="bg-amber-100 text-amber-700 px-2 py-0.5 rounded text-[9px] font-black uppercase tracking-wider shadow-sm border border-amber-200"><i class="fas fa-crown mr-1"></i> Mother Project</span>
                                            <?php elseif($p['mother_commission_pct'] > 0): ?>
                                                <span class="bg-slate-100 text-slate-600 px-2 py-0.5 rounded text-[9px] font-black uppercase tracking-wider shadow-sm border border-slate-200"><i class="fas fa-arrow-up mr-1 text-rose-400"></i> Gives <?= $p['mother_commission_pct'] ?>%</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    
                                    <td class="px-5 py-4 text-center align-top">
                                        <?php if(isset($p['dist_type']) && $p['dist_type'] == 'by_investment'): ?>
                                            <span class="bg-indigo-100 text-indigo-700 px-3 py-1 rounded-md text-[10px] font-black uppercase tracking-wider shadow-sm border border-indigo-200">By Investment (টাকা)</span>
                                        <?php else: ?>
                                            <span class="bg-emerald-100 text-emerald-700 px-3 py-1 rounded-md text-[10px] font-black uppercase tracking-wider shadow-sm border border-emerald-200">By Share (শেয়ার)</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="px-5 py-4 align-top">
                                        <div class="text-center mb-2">
                                            <span class="font-black text-blue-600 text-xl bg-blue-50 px-3 py-1 rounded-lg border border-blue-100"><?= $p['active_percent'] ?>%</span>
                                        </div>
                                        <?php if($p['has_active_split']): ?>
                                            <div class="mt-3 bg-white border border-slate-200 rounded-lg p-2.5 text-[10px] font-bold text-slate-600 shadow-sm mx-auto max-w-[180px]">
                                                <div class="flex justify-between border-b border-slate-100 pb-1.5 mb-1.5">
                                                    <span><i class="fas fa-coins text-amber-500 mr-1"></i> অর্থ বিনিয়োগকারী:</span> 
                                                    <span class="text-blue-700 font-black"><?= $p['active_investment_percent'] ?>%</span>
                                                </div>
                                                <div class="flex justify-between">
                                                    <span><i class="fas fa-briefcase text-emerald-500 mr-1"></i> শ্রমদানকারী:</span> 
                                                    <span class="text-blue-700 font-black"><?= $p['active_labor_percent'] ?>%</span>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div class="mt-2 text-[10px] text-slate-400 font-bold text-center uppercase tracking-widest">(অটোমেটিক ডিস্ট্রিবিউশন)</div>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td class="px-5 py-4 text-center align-top">
                                        <span class="font-black text-orange-600 text-xl bg-orange-50 px-3 py-1 rounded-lg border border-orange-100"><?= $p['passive_percent'] ?>%</span>
                                    </td>
                                    
                                    <td class="px-5 py-4 text-right pr-6 align-top">
                                        <div class="flex items-center justify-end gap-2">
                                            <button onclick="openEditModal(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['project_name'])) ?>', <?= $p['active_percent'] ?>, <?= $p['passive_percent'] ?>, '<?= $p['dist_type'] ?? 'by_share' ?>', <?= $p['has_active_split'] ?>, <?= $p['active_investment_percent'] ?>, <?= $p['active_labor_percent'] ?>, <?= $p['mother_commission_pct'] ?>)" class="text-blue-600 hover:text-white bg-blue-50 px-3 py-1.5 rounded-lg shadow-sm hover:bg-blue-600 transition border border-blue-100 font-bold text-xs flex items-center gap-1.5"><i class="fas fa-cog"></i> সেটিং</button>
                                            <button onclick="openDeleteModal(<?= $p['id'] ?>)" class="text-rose-500 hover:text-white bg-rose-50 px-3 py-1.5 rounded-lg shadow-sm hover:bg-rose-500 transition border border-rose-100 font-bold text-xs"><i class="fas fa-trash"></i></button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr id="noDataRow"><td colspan="5" class="px-6 py-12 text-center text-slate-400 text-sm font-bold"><i class="fas fa-project-diagram text-4xl mb-3 opacity-30 block"></i> কোনো প্রজেক্ট তৈরি করা হয়নি।</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </div>
        </main>
    </div>

    <nav class="bottom-nav md:hidden shadow-[0_-5px_15px_rgba(0,0,0,0.05)] z-50">
        <a href="index.php" class="nav-item"><i class="fas fa-home"></i> Home</a>
        <a href="manage_shareholders.php" class="nav-item"><i class="fas fa-users"></i> Users</a>
        <a href="manage_projects.php" class="nav-item active"><i class="fas fa-project-diagram"></i> Projects</a>
        <button onclick="toggleSidebar()" class="nav-item bg-transparent border-none outline-none focus:outline-none"><i class="fas fa-bars"></i> Menu</button>
    </nav>

    <div id="motherProjectModal" class="hidden fixed inset-0 bg-slate-900/70 flex items-center justify-center z-50 p-4 backdrop-blur-sm transition-opacity">
        <div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg border border-slate-100 overflow-hidden transform scale-100 flex flex-col max-h-[90vh]">
            <div class="px-6 py-5 bg-gradient-to-r from-indigo-800 to-slate-800 flex items-center justify-between shrink-0">
                <h3 class="font-black text-base text-white flex items-center gap-2"><i class="fas fa-crown text-amber-400"></i> মাদার প্রজেক্ট সেটিং</h3>
                <button onclick="closeMotherModal()" class="w-8 h-8 rounded-lg flex items-center justify-center text-slate-300 hover:bg-rose-500 hover:text-white transition"><i class="fas fa-times"></i></button>
            </div>
            
            <form method="POST" class="p-6 overflow-y-auto custom-scrollbar flex-1 bg-slate-50 space-y-6">
                <input type="hidden" name="action" value="save_mother_settings">
                
                <div class="bg-white p-5 rounded-2xl border border-indigo-100 shadow-sm">
                    <p class="text-[11px] font-bold text-slate-500 mb-4 leading-relaxed bg-indigo-50 p-3 rounded-xl border border-indigo-100"><i class="fas fa-info-circle text-indigo-500 mr-1"></i> যে প্রজেক্টটিকে মাদার প্রজেক্ট হিসেবে সিলেক্ট করবেন, সেটি অন্যান্য চাইল্ড প্রজেক্ট থেকে কমিশন গ্রহণ করবে। প্রতিটি চাইল্ড প্রজেক্টের সেটিং অপশন থেকে কমিশনের পরিমাণ নির্ধারণ করা যাবে।</p>
                    
                    <div class="space-y-5">
                        <div>
                            <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wide mb-1.5"><i class="fas fa-sitemap text-blue-500 mr-1"></i> মাদার প্রজেক্ট সিলেক্ট করুন</label>
                            <select name="mother_project_id" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold text-slate-800 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 transition shadow-sm cursor-pointer">
                                <option value="0">-- মাদার প্রজেক্ট বন্ধ রাখুন (None) --</option>
                                <?php foreach($projects as $p): ?>
                                    <option value="<?= $p['id'] ?>" <?= ($p['id'] == $mother_project_id) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($p['project_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="button" onclick="closeMotherModal()" class="flex-1 py-3.5 bg-slate-200 text-slate-700 font-bold rounded-xl hover:bg-slate-300 transition text-sm">বাতিল</button>
                    <button type="submit" class="flex-1 py-3.5 bg-indigo-600 text-white font-bold rounded-xl shadow-md hover:bg-indigo-700 transition active:scale-95 text-sm flex justify-center items-center gap-2"><i class="fas fa-save"></i> সেটিং সেভ করুন</button>
                </div>
            </form>
        </div>
    </div>

    <div id="editModal" class="hidden fixed inset-0 bg-slate-900/70 flex items-center justify-center z-50 p-4 backdrop-blur-sm transition-opacity">
        <div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg border border-slate-100 overflow-hidden transform scale-100 flex flex-col max-h-[90vh]">
            <div class="px-6 py-5 bg-slate-800 flex items-center justify-between shrink-0">
                <h3 class="font-black text-base text-white flex items-center gap-2"><i class="fas fa-cog text-blue-400"></i> প্রজেক্ট সেটিং আপডেট</h3>
                <button onclick="closeModals()" class="w-8 h-8 rounded-lg flex items-center justify-center text-slate-400 hover:bg-rose-500 hover:text-white transition"><i class="fas fa-times"></i></button>
            </div>
            
            <form method="POST" class="p-6 overflow-y-auto custom-scrollbar flex-1 bg-slate-50 space-y-5">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="project_id" id="edit_id">
                
                <div>
                    <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wide mb-1.5">প্রজেক্টের নাম <span class="text-rose-500">*</span></label>
                    <input type="text" name="project_name" id="edit_name" class="w-full bg-white border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 shadow-sm transition" required>
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-bold text-blue-600 uppercase tracking-wide mb-1.5">অ্যাক্টিভ ফান্ড (%) <span class="text-rose-500">*</span></label>
                        <input type="number" step="0.01" name="active_percent" id="edit_active" class="w-full bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 text-sm font-black text-blue-700 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 shadow-sm transition text-center" required>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-orange-600 uppercase tracking-wide mb-1.5">প্যাসিভ ফান্ড (%) <span class="text-rose-500">*</span></label>
                        <input type="number" step="0.01" name="passive_percent" id="edit_passive" class="w-full bg-orange-50 border border-orange-200 rounded-xl px-4 py-3 text-sm font-black text-orange-700 outline-none focus:border-orange-500 focus:ring-2 focus:ring-orange-200 shadow-sm transition text-center" required>
                    </div>
                </div>

                <div class="bg-indigo-50/50 p-4 rounded-xl border border-indigo-100">
                    <label class="block text-indigo-900 text-[10px] font-black uppercase tracking-wide mb-2">লাভ বণ্টনের ভিত্তি (Distribution Policy) <span class="text-rose-500">*</span></label>
                    <select name="dist_type" id="edit_dist_type" class="w-full px-4 py-3 border border-indigo-200 rounded-xl focus:outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 bg-white text-sm font-bold text-indigo-700 shadow-sm cursor-pointer transition" required>
                        <option value="by_share">শেয়ার সংখ্যা অনুযায়ী (By Shares)</option>
                        <option value="by_investment">বিনিয়োগকৃত টাকার পরিমাণ অনুযায়ী (By Investment)</option>
                    </select>
                </div>
                
                <div class="bg-amber-50/50 p-4 rounded-xl border border-amber-100">
                    <label class="block text-[10px] font-bold text-amber-900 uppercase tracking-wide mb-2"><i class="fas fa-crown text-amber-500 mr-1"></i> মাদার প্রজেক্টকে প্রদানকৃত কমিশন (%)</label>
                    <div class="relative">
                        <input type="number" step="0.01" name="mother_commission_pct" id="edit_mother_comm" class="w-full bg-white border border-amber-200 rounded-xl pl-4 pr-10 py-3 text-sm font-black text-amber-700 outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-200 transition shadow-sm" value="0">
                        <span class="absolute inset-y-0 right-0 flex items-center pr-4 text-amber-400 font-bold">%</span>
                    </div>
                    <p class="text-[9px] font-bold text-amber-700 mt-1.5">এই প্রজেক্টটি তার মোট লাভের কত % মাদার প্রজেক্টকে দেবে? (0 দিলে কোনো কমিশন কাটবে না)</p>
                </div>

                <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
                    <label class="flex items-center cursor-pointer group">
                        <div class="relative">
                            <input type="checkbox" name="has_active_split" id="edit_has_active_split" onchange="toggleEditSplitFields()" class="sr-only">
                            <div class="block bg-slate-200 w-10 h-6 rounded-full transition-colors group-hover:bg-slate-300"></div>
                            <div class="dot absolute left-1 top-1 bg-white w-4 h-4 rounded-full transition-transform transform shadow-sm"></div>
                        </div>
                        <div class="ml-3 text-sm font-black text-slate-700">ম্যানুয়াল কন্ট্রোল: অ্যাক্টিভ ফান্ড ভাগ করুন</div>
                    </label>
                    
                    <div id="edit_split_fields" class="hidden mt-4 pt-4 border-t border-slate-100">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-slate-600 text-[10px] font-bold uppercase tracking-wide mb-1.5"><i class="fas fa-coins text-amber-500 mr-1"></i> অর্থ বিনিয়োগকারী (%)</label>
                                <input type="number" step="0.01" name="active_investment_percent" id="edit_inv_percent" class="w-full px-3 py-2.5 border border-slate-200 bg-slate-50 rounded-lg focus:outline-none focus:border-blue-500 focus:bg-white text-sm font-bold transition">
                            </div>
                            <div>
                                <label class="block text-slate-600 text-[10px] font-bold uppercase tracking-wide mb-1.5"><i class="fas fa-briefcase text-emerald-500 mr-1"></i> শ্রমদানকারী (%)</label>
                                <input type="number" step="0.01" name="active_labor_percent" id="edit_lab_percent" class="w-full px-3 py-2.5 border border-slate-200 bg-slate-50 rounded-lg focus:outline-none focus:border-blue-500 focus:bg-white text-sm font-bold transition">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex gap-3 pt-3 border-t border-slate-200">
                    <button type="button" onclick="closeModals()" class="flex-1 py-3.5 bg-slate-200 text-slate-700 font-bold rounded-xl hover:bg-slate-300 transition text-sm">বাতিল</button>
                    <button type="submit" class="flex-1 py-3.5 bg-blue-600 text-white font-bold rounded-xl shadow-md hover:bg-blue-700 transition active:scale-95 text-sm flex justify-center items-center gap-2"><i class="fas fa-save"></i> সেভ করুন</button>
                </div>
            </form>
        </div>
    </div>

    <div id="deleteModal" class="hidden fixed inset-0 bg-slate-900/70 flex items-center justify-center z-50 p-4 backdrop-blur-sm transition-opacity">
        <div class="bg-white p-8 rounded-3xl shadow-2xl w-full max-w-sm border border-rose-100 text-center transform scale-100 animate-fade-in">
            <div class="w-16 h-16 bg-rose-100 text-rose-500 rounded-full flex items-center justify-center text-3xl mx-auto mb-4 border border-rose-200 shadow-sm"><i class="fas fa-exclamation-triangle"></i></div>
            <h3 class="text-xl font-black text-slate-800 mb-2">সতর্কতা!</h3>
            <p class="text-[11px] font-bold text-slate-500 mb-6 leading-relaxed">এই প্রজেক্টটি ডিলিট করলে এর সাথে যুক্ত সকল ইউজারের প্রজেক্ট ডিটেইলস মুছে যাবে। নিশ্চিত করতে পিন দিন।</p>
            <form method="POST">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="project_id" id="delete_id">
                <input type="password" name="secret_pin" placeholder="অ্যাডমিন PIN..." class="w-full px-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl mb-5 focus:border-rose-500 focus:bg-white focus:ring-2 focus:ring-rose-200 outline-none text-center font-black tracking-[0.5em] text-rose-600 transition" required>
                <div class="flex gap-3">
                    <button type="button" onclick="closeModals()" class="flex-1 py-3 bg-slate-100 text-slate-600 rounded-xl font-bold hover:bg-slate-200 transition text-sm">বাতিল</button>
                    <button type="submit" class="flex-1 py-3 bg-rose-600 text-white rounded-xl font-bold hover:bg-rose-700 shadow-md transition text-sm flex justify-center items-center gap-1.5"><i class="fas fa-trash"></i> ডিলিট</button>
                </div>
            </form>
        </div>
    </div>

    <style>
        /* CSS for Custom Toggle Switch */
        input:checked ~ .dot { transform: translateX(100%); background-color: #fff; }
        input:checked ~ .block { background-color: #4f46e5; }
    </style>

    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('-translate-x-full');
            document.getElementById('sidebar-overlay').classList.toggle('hidden');
        }

        function toggleEditSplitFields() {
            var checkbox = document.getElementById('edit_has_active_split');
            var splitFields = document.getElementById('edit_split_fields');
            var invPercent = document.getElementById('edit_inv_percent');
            var labPercent = document.getElementById('edit_lab_percent');

            if (checkbox.checked) {
                splitFields.classList.remove('hidden');
                invPercent.required = true;
                labPercent.required = true;
            } else {
                splitFields.classList.add('hidden');
                invPercent.required = false;
                labPercent.required = false;
            }
        }

        function openEditModal(id, name, active, passive, dist_type, has_split, inv, lab, mother_comm) {
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_name').value = name;
            document.getElementById('edit_active').value = active;
            document.getElementById('edit_passive').value = passive;
            
            document.getElementById('edit_dist_type').value = dist_type; 
            document.getElementById('edit_mother_comm').value = mother_comm || 0; 
            
            document.getElementById('edit_has_active_split').checked = (has_split == 1);
            document.getElementById('edit_inv_percent').value = inv;
            document.getElementById('edit_lab_percent').value = lab;
            
            toggleEditSplitFields(); 
            document.getElementById('editModal').classList.remove('hidden');
        }

        function openMotherModal() {
            document.getElementById('motherProjectModal').classList.remove('hidden');
        }
        function closeMotherModal() {
            document.getElementById('motherProjectModal').classList.add('hidden');
        }

        function openDeleteModal(id) {
            document.getElementById('delete_id').value = id;
            document.getElementById('deleteModal').classList.remove('hidden');
        }

        function closeModals() {
            document.getElementById('editModal').classList.add('hidden');
            document.getElementById('deleteModal').classList.add('hidden');
        }
    </script>
</body>
</html>