<?php
session_start();

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
// ===================================

require_once 'db.php';

// ওয়েবসাইট লোগো ফেচ (সাইডবারের জন্য)
$site_settings_stmt = $pdo->query("SELECT setting_name, setting_value FROM system_settings WHERE setting_name IN ('site_logo', 'site_favicon')");
$site_settings = $site_settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$site_logo = $site_settings['site_logo'] ?? '';
$site_favicon = $site_settings['site_favicon'] ?? '';

$message = '';
$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $project_name = trim($_POST['project_name']);
    $active_percent = (float)$_POST['active_percent'];
    $passive_percent = (float)$_POST['passive_percent'];
    $dist_type = $_POST['dist_type']; // প্রফিট ডিস্ট্রিবিউশন টাইপ
    
    // ম্যানুয়াল স্প্লিট অপশনের ডাটা
    $has_active_split = isset($_POST['has_active_split']) ? 1 : 0;
    $active_investment_percent = $has_active_split ? (float)$_POST['active_investment_percent'] : 0;
    $active_labor_percent = $has_active_split ? (float)$_POST['active_labor_percent'] : 0;

    // ভ্যালিডেশন: অ্যাক্টিভ ও প্যাসিভ মিলে ১০০% হতে হবে
    if(($active_percent + $passive_percent) > 100) {
        $error = "অ্যাক্টিভ এবং প্যাসিভ ফান্ডের যোগফল ১০০% এর বেশি হতে পারবে না!";
    } else {
        try {
            $sql = "INSERT INTO projects (project_name, active_percent, passive_percent, dist_type, has_active_split, active_investment_percent, active_labor_percent) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            
            if($stmt->execute([$project_name, $active_percent, $passive_percent, $dist_type, $has_active_split, $active_investment_percent, $active_labor_percent])) {
                $message = "নতুন প্রজেক্ট এবং লাভ বণ্টনের নিয়ম সফলভাবে যুক্ত হয়েছে!";
            }
        } catch(PDOException $e) {
            $error = "প্রজেক্ট যুক্ত করতে সমস্যা হয়েছে।";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Add Project - Sodai Lagbe Admin</title>
    
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
        .app-card { background: #ffffff; border-radius: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.03), 0 1px 3px rgba(0,0,0,0.02); border: 1px solid rgba(226, 232, 240, 0.8); transition: transform 0.2s ease, box-shadow 0.2s ease; overflow: hidden;}
        .custom-scrollbar::-webkit-scrollbar { width: 4px; height: 4px; } 
        .custom-scrollbar::-webkit-scrollbar-thumb { background-color: #cbd5e1; border-radius: 10px; } 
        
        .bottom-nav { position: fixed; bottom: 0; left: 0; right: 0; background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px); border-top: 1px solid rgba(0,0,0,0.05); padding-bottom: env(safe-area-inset-bottom); z-index: 50; display: flex; justify-content: space-around; }
        .nav-item { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 10px 0; color: #64748b; font-size: 10px; font-weight: 700; width: 100%; transition: all 0.2s; cursor: pointer; text-decoration: none;}
        .nav-item.active { color: #2563eb; }
        .nav-item i { font-size: 18px; margin-bottom: 3px; transition: transform 0.2s;}
        .nav-item.active i { transform: translateY(-2px); }
        @media (min-width: 768px) { .bottom-nav { display: none; } }
        
        @keyframes fadeInFast { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fade-in { animation: fadeInFast 0.4s ease-out forwards; }
        
        /* Custom Toggle Switch */
        .toggle-checkbox:checked { right: 0; border-color: #4f46e5; }
        .toggle-checkbox:checked + .toggle-label { background-color: #4f46e5; }
        input:checked ~ .dot { transform: translateX(100%); background-color: #fff; }
        input:checked ~ .block { background-color: #4f46e5; }
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
            <a href="add_project.php" class="flex items-center px-6 py-3 bg-blue-600/20 text-blue-400 border-l-4 border-blue-500 font-semibold"><i class="fas fa-plus-square w-6"></i> নতুন প্রজেক্ট</a>
            <a href="manage_staff.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-users-cog w-6"></i> স্টাফ ম্যানেজমেন্ট</a>
            <a href="manage_permanent_expenses.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-file-invoice w-6"></i> স্থায়ী মাসিক খরচ</a>
            <a href="manage_kpi.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-bullseye w-6"></i> KPI ম্যানেজমেন্ট</a>
            <a href="manage_votes.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-vote-yea w-6"></i> ভোটিং ও প্রস্তাবনা</a>
            <a href="manage_video.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-video w-6"></i> লাইভ ভিডিও</a>
            
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
                <h2 class="text-lg font-black tracking-tight text-slate-800 sm:hidden">নতুন প্রজেক্ট</h2>
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

        <main class="flex-1 overflow-x-hidden overflow-y-auto p-4 md:p-6 custom-scrollbar pb-24 md:pb-6 relative bg-slate-50 flex justify-center items-start">
            
            <div class="w-full max-w-2xl space-y-6 animate-fade-in mt-4 md:mt-10">
                
                <?php if($message): ?><div class="bg-emerald-50 text-emerald-600 px-4 py-3 rounded-xl text-sm font-bold shadow-sm border border-emerald-100 flex items-center animate-fade-in"><i class="fas fa-check-circle mr-2 text-lg"></i> <?= $message ?></div><?php endif; ?>
                <?php if($error): ?><div class="bg-red-50 text-red-600 px-4 py-3 rounded-xl text-sm font-bold shadow-sm border border-red-100 flex items-center animate-fade-in"><i class="fas fa-exclamation-triangle mr-2 text-lg"></i> <?= $error ?></div><?php endif; ?>

                <div class="app-card">
                    <div class="px-6 py-5 border-b border-slate-100 bg-slate-800 flex justify-between items-center rounded-t-[15px]">
                        <h2 class="text-lg font-black text-white flex items-center gap-2"><i class="fas fa-plus-circle text-blue-400"></i> নতুন প্রজেক্ট তৈরি করুন</h2>
                        <a href="manage_projects.php" class="w-8 h-8 rounded-lg flex items-center justify-center text-slate-400 hover:text-white transition"><i class="fas fa-times"></i></a>
                    </div>

                    <form method="POST" action="" class="p-6 space-y-6">
                        
                        <div class="bg-slate-50 p-4 rounded-xl border border-slate-200 shadow-sm">
                            <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wide mb-1.5">প্রজেক্টের নাম <span class="text-rose-500">*</span></label>
                            <input type="text" name="project_name" placeholder="যেমন: Tangail Delivery" class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 transition shadow-sm text-sm font-bold text-slate-800" required>
                        </div>
                        
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="bg-blue-50/50 p-4 rounded-xl border border-blue-100 shadow-sm">
                                <label class="block text-[10px] font-bold text-blue-700 uppercase tracking-wide mb-1.5">অ্যাক্টিভ ফান্ড (%) <span class="text-rose-500">*</span></label>
                                <div class="relative">
                                    <input type="number" step="0.01" name="active_percent" value="50" class="w-full px-4 py-3 pr-8 bg-white border border-blue-200 rounded-xl focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition shadow-sm text-sm font-black text-blue-700 text-center" required>
                                    <span class="absolute inset-y-0 right-0 flex items-center pr-4 text-blue-400 font-bold">%</span>
                                </div>
                            </div>
                            <div class="bg-orange-50/50 p-4 rounded-xl border border-orange-100 shadow-sm">
                                <label class="block text-[10px] font-bold text-orange-700 uppercase tracking-wide mb-1.5">প্যাসিভ ফান্ড (%) <span class="text-rose-500">*</span></label>
                                <div class="relative">
                                    <input type="number" step="0.01" name="passive_percent" value="50" class="w-full px-4 py-3 pr-8 bg-white border border-orange-200 rounded-xl focus:outline-none focus:border-orange-500 focus:ring-2 focus:ring-orange-200 transition shadow-sm text-sm font-black text-orange-700 text-center" required>
                                    <span class="absolute inset-y-0 right-0 flex items-center pr-4 text-orange-400 font-bold">%</span>
                                </div>
                            </div>
                        </div>

                        <div class="bg-indigo-50/50 p-5 rounded-xl border border-indigo-100 shadow-sm">
                            <label class="block text-[10px] font-bold text-indigo-900 uppercase tracking-wide mb-2"><i class="fas fa-balance-scale text-indigo-500 mr-1"></i> লাভ বণ্টনের ভিত্তি (Distribution Policy) <span class="text-rose-500">*</span></label>
                            <select name="dist_type" class="w-full px-4 py-3 bg-white border border-indigo-200 rounded-xl focus:outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 transition shadow-sm text-sm font-bold text-indigo-700 cursor-pointer" required>
                                <option value="by_share">শেয়ার সংখ্যা অনুযায়ী (By Shares)</option>
                                <option value="by_investment">বিনিয়োগকৃত টাকার পরিমাণ অনুযায়ী (By Investment)</option>
                            </select>
                            <p class="text-[9px] font-bold text-indigo-600 mt-2 bg-indigo-100/50 p-2 rounded-lg"><i class="fas fa-info-circle mr-1"></i> এই প্রজেক্টের লাভ কীভাবে ভাগ হবে তা নির্ধারণ করুন।</p>
                        </div>

                        <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
                            <label class="flex items-center cursor-pointer group">
                                <div class="relative">
                                    <input type="checkbox" name="has_active_split" id="has_active_split" onchange="toggleSplitFields()" class="sr-only">
                                    <div class="block bg-slate-200 w-10 h-6 rounded-full transition-colors group-hover:bg-slate-300"></div>
                                    <div class="dot absolute left-1 top-1 bg-white w-4 h-4 rounded-full transition-transform transform shadow-sm border border-slate-100"></div>
                                </div>
                                <div class="ml-3 text-sm font-black text-slate-700"><i class="fas fa-sliders-h text-amber-500 mr-1.5"></i> ম্যানুয়াল কন্ট্রোল: অ্যাক্টিভ ফান্ড ভাগ করুন</div>
                            </label>
                            
                            <div id="split_fields" class="hidden mt-4 pt-4 border-t border-slate-100 animate-fade-in">
                                <p class="text-[10px] font-bold text-slate-500 mb-3 leading-relaxed bg-slate-50 p-2 rounded-lg border border-slate-100"><i class="fas fa-info-circle text-blue-400 mr-1"></i> অ্যাক্টিভ ফান্ডের জন্য নির্ধারিত অংশটিকে অর্থ বিনিয়োগকারী এবং শ্রমদানকারীর মাঝে ম্যানুয়ালি কত পারসেন্ট করে দেবেন তা ঠিক করুন:</p>
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-slate-600 text-[10px] font-bold uppercase tracking-wide mb-1.5"><i class="fas fa-coins text-amber-500 mr-1"></i> অর্থ বিনিয়োগকারী (%)</label>
                                        <input type="number" step="0.01" name="active_investment_percent" id="inv_percent" class="w-full px-3 py-2.5 border border-slate-200 bg-slate-50 rounded-lg focus:outline-none focus:border-blue-500 focus:bg-white focus:ring-1 focus:ring-blue-500 text-sm font-bold transition text-center" placeholder="উদা: 40">
                                    </div>
                                    <div>
                                        <label class="block text-slate-600 text-[10px] font-bold uppercase tracking-wide mb-1.5"><i class="fas fa-briefcase text-emerald-500 mr-1"></i> শ্রমদানকারী (%)</label>
                                        <input type="number" step="0.01" name="active_labor_percent" id="lab_percent" class="w-full px-3 py-2.5 border border-slate-200 bg-slate-50 rounded-lg focus:outline-none focus:border-blue-500 focus:bg-white focus:ring-1 focus:ring-blue-500 text-sm font-bold transition text-center" placeholder="উদা: 60">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex gap-3 pt-2">
                            <a href="manage_projects.php" class="flex-1 py-3.5 bg-slate-100 text-slate-600 font-bold rounded-xl hover:bg-slate-200 transition text-sm text-center flex justify-center items-center gap-1.5"><i class="fas fa-arrow-left"></i> প্রজেক্ট লিস্ট</a>
                            <button type="submit" class="flex-1 py-3.5 bg-blue-600 text-white font-bold rounded-xl shadow-md hover:bg-blue-700 hover:shadow-lg transition-all active:scale-95 text-sm flex justify-center items-center gap-1.5"><i class="fas fa-save"></i> সেভ করুন</button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>

    <nav class="bottom-nav md:hidden shadow-[0_-5px_15px_rgba(0,0,0,0.05)] z-50">
        <a href="index.php" class="nav-item"><i class="fas fa-home"></i> Home</a>
        <a href="manage_projects.php" class="nav-item"><i class="fas fa-project-diagram"></i> Projects</a>
        <a href="add_project.php" class="nav-item active"><i class="fas fa-plus-circle"></i> Add</a>
        <button onclick="toggleSidebar()" class="nav-item bg-transparent border-none outline-none focus:outline-none"><i class="fas fa-bars"></i> Menu</button>
    </nav>

    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('-translate-x-full');
            document.getElementById('sidebar-overlay').classList.toggle('hidden');
        }

        function toggleSplitFields() {
            var checkbox = document.getElementById('has_active_split');
            var splitFields = document.getElementById('split_fields');
            var invPercent = document.getElementById('inv_percent');
            var labPercent = document.getElementById('lab_percent');

            if (checkbox.checked) {
                splitFields.classList.remove('hidden');
                invPercent.required = true;
                labPercent.required = true;
            } else {
                splitFields.classList.add('hidden');
                invPercent.required = false;
                labPercent.required = false;
                invPercent.value = '';
                labPercent.value = '';
            }
        }
    </script>
</body>
</html>