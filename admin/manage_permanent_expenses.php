<?php
session_start();
date_default_timezone_set('Asia/Dhaka');

if(!isset($_SESSION['admin_logged_in'])) { header("Location: login.php"); exit; }
if(isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'staff') { header("Location: add_entry.php"); exit; }

require_once 'db.php';

// টেবিল তৈরি (যদি না থাকে)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `permanent_expenses` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `expense_name` varchar(255) NOT NULL,
      `amount` decimal(10,2) NOT NULL,
      `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    )");
} catch(PDOException $e) {}

$message = $_SESSION['msg_success'] ?? ''; 
$error = $_SESSION['msg_error'] ?? '';
unset($_SESSION['msg_success'], $_SESSION['msg_error']);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['action'])) {
        
        // Add Expense
        if ($_POST['action'] == 'add') {
            $name = trim($_POST['expense_name']);
            $amount = (float)$_POST['amount'];
            
            if (!empty($name) && $amount > 0) {
                $stmt = $pdo->prepare("INSERT INTO permanent_expenses (expense_name, amount) VALUES (?, ?)");
                if($stmt->execute([$name, $amount])) {
                    $_SESSION['msg_success'] = "নতুন স্থায়ী খরচ সফলভাবে যুক্ত হয়েছে!";
                } else {
                    $_SESSION['msg_error'] = "খরচ যুক্ত করতে সমস্যা হয়েছে।";
                }
            } else {
                $_SESSION['msg_error'] = "সঠিক তথ্য প্রদান করুন।";
            }
            header("Location: manage_permanent_expenses.php"); exit;
        }
        
        // Delete Expense
        if ($_POST['action'] == 'delete') {
            $id = (int)$_POST['expense_id'];
            $stmt = $pdo->prepare("DELETE FROM permanent_expenses WHERE id = ?");
            if($stmt->execute([$id])) {
                $_SESSION['msg_success'] = "স্থায়ী খরচটি মুছে ফেলা হয়েছে!";
            } else {
                $_SESSION['msg_error'] = "মুছে ফেলতে সমস্যা হয়েছে।";
            }
            header("Location: manage_permanent_expenses.php"); exit;
        }
    }
}

$expenses = $pdo->query("SELECT * FROM permanent_expenses ORDER BY created_at DESC")->fetchAll();
$total_expense = array_sum(array_column($expenses, 'amount'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Permanent Expenses - Sodai Lagbe</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; -webkit-tap-highlight-color: transparent;}
        .glass-header { background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border-bottom: 1px solid rgba(226,232,240,0.8); }
        .app-card { background: #ffffff; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.03), 0 1px 3px rgba(0,0,0,0.02); border: 1px solid rgba(226, 232, 240, 0.8); }
        .custom-scrollbar::-webkit-scrollbar { width: 4px; height: 4px; } 
        .custom-scrollbar::-webkit-scrollbar-thumb { background-color: #cbd5e1; border-radius: 10px; } 
        .bottom-nav { position: fixed; bottom: 0; left: 0; right: 0; background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px); border-top: 1px solid rgba(0,0,0,0.05); padding-bottom: env(safe-area-inset-bottom); z-index: 50; display: flex; justify-content: space-around; }
        .nav-item { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 10px 0; color: #64748b; font-size: 10px; font-weight: 700; width: 100%; transition: all 0.2s; }
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
            <a href="manage_permanent_expenses.php" class="flex items-center px-6 py-3 bg-blue-600/20 text-blue-400 border-l-4 border-blue-500 font-semibold"><i class="fas fa-file-invoice w-6"></i> স্থায়ী মাসিক খরচ</a>
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
                <h2 class="text-lg font-black tracking-tight text-slate-800 hidden sm:block">স্থায়ী খরচ</h2>
            </div>
            <div class="flex items-center gap-3">
                <div class="text-right hidden sm:block border-r border-slate-200 pr-3">
                    <div class="text-sm font-bold leading-tight"><?= htmlspecialchars($_SESSION['admin_username']) ?></div>
                    <div class="text-[10px] text-slate-500 font-bold uppercase tracking-wide">System Admin</div>
                </div>
                <div class="h-9 w-9 bg-gradient-to-br from-blue-600 to-indigo-700 rounded-full flex items-center justify-center text-white font-black shadow-md border border-white">
                    <i class="fas fa-user-shield text-xs"></i>
                </div>
            </div>
        </header>

        <main class="flex-1 overflow-x-hidden overflow-y-auto p-4 md:p-6 custom-scrollbar pb-24 md:pb-6 relative bg-slate-50">
            
            <div class="main-dashboard-content max-w-4xl mx-auto space-y-6">
                
                <?php if($message): ?><div class="bg-emerald-50 text-emerald-600 px-4 py-3 rounded-xl text-sm font-bold shadow-sm border border-emerald-100 flex items-center animate-fade-in"><i class="fas fa-check-circle mr-2 text-lg"></i> <?= $message ?></div><?php endif; ?>
                <?php if($error): ?><div class="bg-red-50 text-red-600 px-4 py-3 rounded-xl text-sm font-bold shadow-sm border border-red-100 flex items-center animate-fade-in"><i class="fas fa-exclamation-triangle mr-2 text-lg"></i> <?= $error ?></div><?php endif; ?>

                <div class="flex flex-col md:flex-row gap-6 animate-fade-in">
                    
                    <div class="w-full md:w-1/3">
                        <div class="app-card bg-white overflow-hidden sticky top-6">
                            <div class="bg-gradient-to-r from-slate-800 to-slate-900 p-4">
                                <h3 class="text-white font-black flex items-center gap-2 text-sm"><i class="fas fa-plus-circle text-amber-400"></i> নতুন খরচ যুক্ত করুন</h3>
                            </div>
                            <form method="POST" class="p-5 space-y-4">
                                <input type="hidden" name="action" value="add">
                                <div>
                                    <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wide mb-1.5">খরচের বিবরণ/নাম</label>
                                    <input type="text" name="expense_name" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-100 outline-none text-sm font-bold text-slate-700 transition" placeholder="যেমন: দোকান ভাড়া" required>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wide mb-1.5">পরিমাণ (টাকা)</label>
                                    <div class="relative">
                                        <span class="absolute inset-y-0 left-0 flex items-center pl-4 text-slate-400 font-black">৳</span>
                                        <input type="number" step="0.01" name="amount" class="w-full pl-8 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-100 outline-none text-sm font-black text-slate-800 transition" placeholder="0.00" required>
                                    </div>
                                </div>
                                <button type="submit" class="w-full bg-blue-600 text-white font-bold py-3 rounded-xl shadow-md hover:bg-blue-700 transition-all active:scale-[0.98] text-sm mt-2">
                                    <i class="fas fa-save mr-1"></i> সেভ করুন
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="w-full md:w-2/3">
                        <div class="app-card bg-white p-5 border-l-4 border-amber-500 mb-6 flex justify-between items-center shadow-sm">
                            <div>
                                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">সর্বমোট স্থায়ী মাসিক খরচ</p>
                                <h2 class="text-3xl font-black text-slate-800 tracking-tight">৳ <?= number_format($total_expense, 2) ?></h2>
                            </div>
                            <div class="w-12 h-12 rounded-full bg-amber-50 text-amber-500 flex items-center justify-center text-2xl shadow-inner border border-amber-100">
                                <i class="fas fa-file-invoice-dollar"></i>
                            </div>
                        </div>

                        <div class="app-card bg-white overflow-hidden">
                            <div class="p-4 border-b border-slate-100 bg-slate-50/50 flex justify-between items-center">
                                <h3 class="text-sm font-black text-slate-800"><i class="fas fa-list text-blue-500 mr-1.5"></i> খরচের তালিকা</h3>
                                <span class="bg-blue-100 text-blue-700 text-[10px] font-black px-2 py-0.5 rounded-lg shadow-sm"><?= count($expenses) ?> টি আইটেম</span>
                            </div>
                            
                            <div class="p-3">
                                <?php if(count($expenses) > 0): ?>
                                    <div class="space-y-2">
                                        <?php foreach($expenses as $exp): ?>
                                        <div class="flex items-center justify-between p-3.5 bg-white border border-slate-100 rounded-xl hover:border-slate-200 hover:shadow-sm transition group">
                                            <div class="flex items-center gap-3">
                                                <div class="w-10 h-10 rounded-full bg-slate-100 text-slate-500 flex items-center justify-center group-hover:bg-blue-50 group-hover:text-blue-500 transition-colors">
                                                    <i class="fas fa-receipt text-sm"></i>
                                                </div>
                                                <div>
                                                    <h4 class="text-sm font-black text-slate-800 leading-tight"><?= htmlspecialchars($exp['expense_name']) ?></h4>
                                                    <p class="text-[9px] text-slate-400 font-bold mt-0.5">Added: <?= date('d M, Y', strtotime($exp['created_at'])) ?></p>
                                                </div>
                                            </div>
                                            <div class="flex items-center gap-4">
                                                <span class="font-black text-rose-500 text-base">৳ <?= number_format($exp['amount'], 2) ?></span>
                                                <form method="POST" class="m-0 p-0" onsubmit="return confirm('আপনি কি এই খরচটি মুছে ফেলতে চান?');">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="expense_id" value="<?= $exp['id'] ?>">
                                                    <button type="submit" class="w-8 h-8 rounded-full bg-rose-50 text-rose-500 hover:bg-rose-500 hover:text-white transition flex items-center justify-center border border-rose-100 shadow-sm" title="Delete">
                                                        <i class="fas fa-trash text-[10px]"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-10 bg-slate-50 rounded-xl border border-dashed border-slate-200">
                                        <i class="fas fa-clipboard-check text-4xl text-slate-300 mb-3"></i>
                                        <p class="text-sm font-bold text-slate-500">কোনো স্থায়ী খরচ যুক্ত করা হয়নি।</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </main>
    </div>

    <nav class="bottom-nav shadow-[0_-5px_15px_rgba(0,0,0,0.05)]">
        <a href="index.php" class="nav-item"><i class="fas fa-tachometer-alt"></i> Home</a>
        <a href="manage_shareholders.php" class="nav-item"><i class="fas fa-users"></i> Users</a>
        <a href="add_entry.php" class="nav-item"><i class="fas fa-file-invoice-dollar"></i> Add</a>
        <button onclick="toggleSidebar()" class="nav-item bg-transparent border-none outline-none focus:outline-none"><i class="fas fa-bars"></i> Menu</button>
    </nav>

    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('-translate-x-full');
            document.getElementById('sidebar-overlay').classList.toggle('hidden');
        }
    </script>
</body>
</html>