<?php
// ২৪ ঘণ্টার জন্য সেশন ও কুকি সেট করা
ini_set('session.cookie_lifetime', 86400);
ini_set('session.gc_maxlifetime', 86400);
session_set_cookie_params(86400);
session_start();

if(!isset($_SESSION['admin_logged_in'])) { header("Location: login.php"); exit; }
if(isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'staff') { header("Location: add_entry.php"); exit; }
require_once 'db.php';

$message = '';
$error = '';

$projects = $pdo->query("SELECT * FROM projects")->fetchAll();
$accounts = $pdo->query("SELECT * FROM shareholder_accounts ORDER BY name ASC")->fetchAll();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        $account_type = $_POST['account_type'] ?? 'existing';
        $account_id = null;
        $name = '';

        // নতুন অ্যাকাউন্ট তৈরি 
        if ($account_type == 'new') {
            $name = trim($_POST['new_name']);
            $username = trim($_POST['new_username']);
            $password = $_POST['new_password'];
            
            // ডাটাবেসে ইউজারনেমটি আগে থেকেই আছে কিনা তা চেক করা হচ্ছে
            $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM shareholder_accounts WHERE username = ?");
            $check_stmt->execute([$username]);
            
            if ($check_stmt->fetchColumn() > 0) {
                $error = "দুঃখিত! '{$username}' ইউজারনেমটি ইতিমধ্যে ব্যবহৃত হচ্ছে। দয়া করে নামের সাথে সংখ্যা যুক্ত করে অন্য কোনো ইউজারনেম দিন (যেমন: {$username}123)।";
            } else {
                $stmt = $pdo->prepare("INSERT INTO shareholder_accounts (name, username, password) VALUES (?, ?, ?)");
                $stmt->execute([$name, $username, $password]);
                $account_id = $pdo->lastInsertId();
            }
        } else {
            // বিদ্যমান অ্যাকাউন্ট সিলেক্ট
            $account_id = $_POST['existing_account_id'];
            $stmt = $pdo->prepare("SELECT name FROM shareholder_accounts WHERE id = ?");
            $stmt->execute([$account_id]);
            $name = $stmt->fetchColumn();
        }

        // যদি কোনো এরর না থাকে, তবেই শেয়ার যুক্ত করার ধাপে যাবে
        if (empty($error)) {
            $investment = $_POST['investment'];
            // যদি ফিল্ডটি হিডেন থাকার কারণে ডাটা না আসে, তবে ০ ধরে নেবে
            $number_of_shares = isset($_POST['number_of_shares']) ? (int)$_POST['number_of_shares'] : 0;
            $share_type = $_POST['share_type'];
            $project_id = empty($_POST['project_id']) ? null : $_POST['project_id'];
            $deadline_date = empty($_POST['deadline_date']) ? null : $_POST['deadline_date'];
            
            $slot_numbers = isset($_POST['slot_numbers']) ? trim($_POST['slot_numbers']) : '';

            $sql = "INSERT INTO shareholders (account_id, name, investment_credit, number_of_shares, share_type, assigned_project_id, deadline_date, slot_numbers) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            
            if($stmt->execute([$account_id, $name, $investment, $number_of_shares, $share_type, $project_id, $deadline_date, $slot_numbers])) {
                $message = "অ্যাকাউন্ট এবং শেয়ার সফলভাবে যুক্ত হয়েছে!";
                // ড্রপডাউন লিস্ট আপডেট করার জন্য আবার কোয়েরি
                $accounts = $pdo->query("SELECT * FROM shareholder_accounts ORDER BY name ASC")->fetchAll();
            }
        }
        
    } catch (PDOException $e) {
        // ডাটাবেসে অন্য কোনো ডুপ্লিকেট ভ্যালু থাকলে সুন্দর মেসেজ দেখাবে
        if ($e->getCode() == 23000) {
            $error = "দুঃখিত! আপনি যে তথ্যটি (যেমন: স্লট নাম্বার) দিচ্ছেন সেটি ডাটাবেসের অন্য কারো সাথে মিলে যাচ্ছে। দয়া করে ভিন্ন তথ্য দিন।";
        } else {
            $error = "ডাটাবেস এরর: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Add Shareholder - Sodai Lagbe ERP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap');
        
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; -webkit-tap-highlight-color: transparent;}
        
        .glass-header {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(226,232,240,0.8);
        }
        
        .app-card {
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.03), 0 1px 3px rgba(0,0,0,0.02);
            border: 1px solid rgba(226, 232, 240, 0.8);
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
        
        /* Custom Radio Button Style */
        .custom-radio:checked + div { border-color: #4f46e5; background-color: #eef2ff; color: #4f46e5; }
        .custom-radio:checked + div i { color: #4f46e5; }
    </style>
</head>
<body class="text-slate-800 antialiased selection:bg-blue-200">

    <header class="glass-header sticky top-0 z-40 px-4 py-3 flex items-center justify-between shadow-sm">
        <div class="flex items-center gap-2">
            <div class="w-8 h-8 bg-blue-600 rounded-lg flex items-center justify-center text-white shadow-md">
                <i class="fas fa-user-plus text-sm"></i>
            </div>
            <h1 class="text-lg font-black tracking-tight text-gray-900 hidden sm:block">Sodai Lagbe ERP</h1>
            <h1 class="text-lg font-black tracking-tight text-gray-900 sm:hidden">অ্যাকাউন্ট যুক্ত</h1>
        </div>
        <div class="flex items-center gap-3">
            <nav class="hidden md:flex space-x-2">
                <a href="index.php" class="text-slate-500 hover:text-blue-600 px-3 py-2 rounded-md text-sm font-bold transition border border-transparent hover:border-blue-100">ড্যাশবোর্ড</a>
                <a href="manage_shareholders.php" class="text-slate-500 hover:text-blue-600 px-3 py-2 rounded-md text-sm font-bold transition border border-transparent hover:border-blue-100">অ্যাকাউন্ট লিস্ট</a>
            </nav>
            <div class="text-right hidden sm:block border-l border-slate-200 pl-3">
                <div class="text-sm font-bold leading-tight"><?= htmlspecialchars($_SESSION['admin_username']) ?></div>
                <div class="text-[10px] text-slate-500 font-bold uppercase tracking-wide">System Admin</div>
            </div>
            <a href="logout.php" class="w-9 h-9 bg-slate-100 rounded-full flex items-center justify-center text-slate-600 hover:bg-red-50 hover:text-red-500 transition shadow-sm border border-slate-200">
                <i class="fas fa-power-off text-sm"></i>
            </a>
        </div>
    </header>

    <main class="max-w-2xl mx-auto px-4 py-6 main-content space-y-6">
        
        <div class="flex items-center justify-between animate-fade-in">
            <div>
                <h2 class="text-xl md:text-2xl font-black text-slate-800 tracking-tight flex items-center gap-2">শেয়ারহোল্ডার এন্ট্রি</h2>
                <p class="text-[11px] text-slate-500 font-bold mt-1">নতুন অ্যাকাউন্ট ও শেয়ার যুক্ত করুন</p>
            </div>
            <a href="manage_shareholders.php" class="bg-white border border-slate-200 text-indigo-600 hover:text-white hover:bg-indigo-600 font-bold text-xs px-3 py-2 rounded-lg shadow-sm transition-all flex items-center gap-1"><i class="fas fa-list"></i> <span class="hidden sm:inline">লিস্ট দেখুন</span></a>
        </div>
        
        <?php if($message): ?><div class="bg-emerald-50 text-emerald-600 px-4 py-3 rounded-xl text-sm font-bold shadow-sm border border-emerald-100 flex items-center animate-fade-in"><i class="fas fa-check-circle mr-2 text-lg"></i> <?= $message ?></div><?php endif; ?>
        <?php if($error): ?><div class="bg-rose-50 text-rose-600 px-4 py-3 rounded-xl text-sm font-bold shadow-sm border border-rose-100 flex items-center animate-fade-in"><i class="fas fa-exclamation-triangle mr-2 text-lg"></i> <?= $error ?></div><?php endif; ?>

        <form method="POST" action="" class="space-y-6">
            
            <div class="app-card overflow-hidden animate-fade-in" style="animation-delay: 0.1s;">
                <div class="bg-gradient-to-r from-indigo-600 to-blue-700 px-5 py-4 text-white">
                    <h3 class="font-black text-sm flex items-center gap-2"><i class="fas fa-id-card-alt text-indigo-200"></i> ১. অ্যাকাউন্ট নির্বাচন (Account Info)</h3>
                </div>
                
                <div class="p-5 bg-indigo-50/30">
                    <div class="grid grid-cols-2 gap-3 mb-5">
                        <label class="cursor-pointer relative">
                            <input type="radio" name="account_type" value="existing" checked onchange="toggleAccountView()" class="custom-radio sr-only">
                            <div class="border border-slate-200 rounded-xl p-3 text-center transition-all shadow-sm bg-white">
                                <i class="fas fa-user-check text-slate-400 text-lg mb-1 block"></i>
                                <span class="text-xs font-bold block">বিদ্যমান অ্যাকাউন্ট</span>
                            </div>
                        </label>
                        <label class="cursor-pointer relative">
                            <input type="radio" name="account_type" value="new" onchange="toggleAccountView()" class="custom-radio sr-only">
                            <div class="border border-slate-200 rounded-xl p-3 text-center transition-all shadow-sm bg-white">
                                <i class="fas fa-user-plus text-slate-400 text-lg mb-1 block"></i>
                                <span class="text-xs font-bold block">নতুন অ্যাকাউন্ট</span>
                            </div>
                        </label>
                    </div>

                    <div id="existing_div" class="transition-all duration-300 animate-fade-in">
                        <p class="text-[10px] text-slate-500 mb-2 font-bold bg-white p-2 rounded border border-slate-100"><i class="fas fa-info-circle text-indigo-400"></i> একজনকে নতুন প্রজেক্টে যুক্ত করতে তার পুরোনো অ্যাকাউন্টটি সিলেক্ট করুন।</p>
                        <label class="block text-[10px] font-bold text-slate-500 mb-1.5 uppercase tracking-wide">শেয়ারহোল্ডার সিলেক্ট করুন</label>
                        <select name="existing_account_id" class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 font-bold text-slate-700 shadow-sm cursor-pointer text-sm">
                            <option value="">-- অ্যাকাউন্ট নির্বাচন করুন --</option>
                            <?php foreach($accounts as $acc): ?>
                                <option value="<?= $acc['id'] ?>"><?= htmlspecialchars($acc['name']) ?> (User: <?= htmlspecialchars($acc['username']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="new_div" class="hidden space-y-4 transition-all duration-300">
                        <div>
                            <label class="block text-[10px] font-bold text-slate-500 mb-1.5 uppercase tracking-wide">পূর্ণ নাম <span class="text-red-500">*</span></label>
                            <input type="text" name="new_name" class="w-full px-4 py-2.5 bg-white border border-slate-200 rounded-xl focus:bg-slate-50 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 shadow-sm text-sm font-bold text-slate-700 outline-none transition" placeholder="Ex: Mirza Arad">
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 mb-1.5 uppercase tracking-wide">লগইন ইউজারনেম <span class="text-red-500">*</span></label>
                                <input type="text" name="new_username" class="w-full px-4 py-2.5 bg-white border border-slate-200 rounded-xl focus:bg-slate-50 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 shadow-sm text-sm font-bold text-slate-700 outline-none transition" placeholder="Ex: arad2026">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 mb-1.5 uppercase tracking-wide">পাসওয়ার্ড <span class="text-red-500">*</span></label>
                                <input type="text" name="new_password" class="w-full px-4 py-2.5 bg-white border border-slate-200 rounded-xl focus:bg-slate-50 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 shadow-sm text-sm font-bold text-slate-700 outline-none transition" placeholder="লগইন পাসওয়ার্ড">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="app-card overflow-hidden animate-fade-in" style="animation-delay: 0.2s;">
                <div class="bg-gradient-to-r from-emerald-500 to-teal-600 px-5 py-4 text-white">
                    <h3 class="font-black text-sm flex items-center gap-2"><i class="fas fa-chart-pie text-emerald-200"></i> ২. প্রজেক্ট ও শেয়ারের তথ্য (Investment)</h3>
                </div>
                
                <div class="p-5 bg-emerald-50/30 space-y-4">
                    <div>
                        <label class="block text-[10px] font-bold text-slate-500 mb-1.5 uppercase tracking-wide">প্রজেক্ট <span class="text-emerald-500 lowercase normal-case">(ঐচ্ছিক)</span></label>
                        <select name="project_id" id="project_select" onchange="toggleShareField()" class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 font-bold text-emerald-900 shadow-sm cursor-pointer text-sm">
                            <option value="" data-dist="by_share">-- General Fund (কোম্পানির মূল হিসাব) --</option>
                            <?php foreach($projects as $proj): ?>
                                <option value="<?= $proj['id'] ?>" data-dist="<?= $proj['dist_type'] ?? 'by_share' ?>">
                                    <?= htmlspecialchars($proj['project_name']) ?> (<?= (isset($proj['dist_type']) && $proj['dist_type'] == 'by_investment') ? 'টাকা অনুযায়ী' : 'শেয়ার অনুযায়ী' ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="flex flex-col md:flex-row gap-4">
                        <div class="w-full md:w-1/2 transition-all duration-300" id="share_input_div">
                            <label class="block text-[10px] font-bold text-slate-500 mb-1.5 uppercase tracking-wide">কয়টি শেয়ার? <span class="text-red-500">*</span></label>
                            <input type="number" name="number_of_shares" id="share_input" class="w-full px-4 py-2.5 bg-white border border-slate-200 rounded-xl focus:bg-emerald-50 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm font-black text-indigo-600 text-sm outline-none transition" required placeholder="Ex: 2">
                        </div>
                        <div class="w-full md:w-1/2 transition-all duration-300" id="investment_input_div">
                            <label class="block text-[10px] font-bold text-slate-500 mb-1.5 uppercase tracking-wide">মোট বিনিয়োগ (টাকা) <span class="text-red-500">*</span></label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-4 text-slate-400 font-black">৳</span>
                                <input type="number" step="0.01" name="investment" class="w-full pl-8 pr-4 py-2.5 bg-white border border-slate-200 rounded-xl focus:bg-emerald-50 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm font-black text-slate-800 text-sm outline-none transition" required placeholder="50000">
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold text-slate-500 mb-1.5 uppercase tracking-wide">শেয়ারের ক্যাটাগরি <span class="text-red-500">*</span></label>
                        <select name="share_type" class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm cursor-pointer text-sm font-bold text-slate-700">
                            <option value="passive">প্যাসিভ শেয়ার (শুধুমাত্র বিনিয়োগ)</option>
                            <option value="active_money">অ্যাক্টিভ শেয়ার (শুধুমাত্র অর্থ বিনিয়োগ)</option>
                            <option value="active_labor">অ্যাক্টিভ শেয়ার (অর্থ বিনিয়োগ + শ্রমদানকারী)</option>
                        </select>
                    </div>

                    <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
                        <label class="block text-[10px] font-bold text-slate-500 mb-1.5 uppercase tracking-wide">ড্যাশবোর্ড স্লট নাম্বার <span class="text-emerald-500 lowercase normal-case">(ঐচ্ছিক)</span></label>
                        <input type="text" name="slot_numbers" placeholder="একাধিক হলে কমা দিয়ে লিখুন। Ex: 5, 12" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg focus:outline-none focus:bg-white focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 font-bold text-sm text-indigo-700 transition">
                        <p class="text-[9px] text-slate-400 mt-2 font-bold"><i class="fas fa-th-large text-indigo-400 mr-1"></i> ড্যাশবোর্ডে বক্স আকারে নাম দেখানোর জন্য স্লট নাম্বার দিন।</p>
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold text-slate-500 mb-1.5 uppercase tracking-wide">ডেডলাইন বা মেয়াদ <span class="text-emerald-500 lowercase normal-case">(ঐচ্ছিক)</span></label>
                        <input type="date" name="deadline_date" class="w-full px-4 py-2.5 bg-white border border-slate-200 rounded-xl focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm text-sm font-bold text-slate-700 transition">
                    </div>
                </div>
            </div>

            <button type="submit" class="w-full bg-blue-600 text-white font-black text-sm py-4 rounded-xl shadow-lg hover:bg-blue-700 hover:shadow-xl transition-all transform active:scale-[0.98] flex justify-center items-center gap-2 animate-fade-in" style="animation-delay: 0.3s;">
                <i class="fas fa-save"></i> এন্ট্রি সেভ করুন
            </button>
            
        </form>
    </main>

    <nav class="bottom-nav shadow-[0_-5px_15px_rgba(0,0,0,0.05)]">
        <a href="index.php" class="nav-item"><i class="fas fa-tachometer-alt"></i> Home</a>
        <a href="manage_shareholders.php" class="nav-item"><i class="fas fa-users"></i> Users</a>
        <a href="add_shareholder.php" class="nav-item active"><i class="fas fa-user-plus"></i> Add</a>
        <button onclick="toggleSidebar()" class="nav-item bg-transparent border-none outline-none focus:outline-none"><i class="fas fa-bars"></i> Menu</button>
    </nav>

    <script>
        function toggleAccountView() {
            let type = document.querySelector('input[name="account_type"]:checked').value;
            if(type === 'new') {
                document.getElementById('new_div').classList.remove('hidden');
                document.getElementById('existing_div').classList.add('hidden');
                document.querySelector('input[name="new_name"]').required = true;
                document.querySelector('input[name="new_username"]').required = true;
                document.querySelector('input[name="new_password"]').required = true;
                document.querySelector('select[name="existing_account_id"]').required = false;
            } else {
                document.getElementById('new_div').classList.add('hidden');
                document.getElementById('existing_div').classList.remove('hidden');
                document.querySelector('input[name="new_name"]').required = false;
                document.querySelector('input[name="new_username"]').required = false;
                document.querySelector('input[name="new_password"]').required = false;
                document.querySelector('select[name="existing_account_id"]').required = true;
            }
        }
        
        function toggleShareField() {
            var projSelect = document.getElementById('project_select');
            var selectedOption = projSelect.options[projSelect.selectedIndex];
            var distType = selectedOption.getAttribute('data-dist');
            
            var shareDiv = document.getElementById('share_input_div');
            var shareInput = document.getElementById('share_input');
            var invDiv = document.getElementById('investment_input_div');

            if (distType === 'by_investment') {
                shareDiv.style.display = 'none';
                shareInput.required = false;
                shareInput.value = 0; 
                
                invDiv.classList.remove('md:w-1/2');
                invDiv.classList.add('w-full');
            } else {
                shareDiv.style.display = 'block';
                shareInput.required = true;
                if(shareInput.value == 0) shareInput.value = '';
                
                invDiv.classList.remove('w-full');
                invDiv.classList.add('md:w-1/2');
            }
        }

        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('-translate-x-full');
            document.getElementById('sidebar-overlay').classList.toggle('hidden');
        }

        window.onload = function() {
            toggleAccountView();
            toggleShareField();
        };
    </script>
</body>
</html>