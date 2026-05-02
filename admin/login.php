<?php
session_start();
require_once 'db.php';

if(isset($_SESSION['admin_logged_in'])) {
    header("Location: index.php");
    exit;
}

$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    // ১. প্রথমে সুপার অ্যাডমিন টেবিলে চেক করবে
    $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ? AND password = ?");
    $stmt->execute([$username, $password]);
    $admin = $stmt->fetch();

    if ($admin) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $admin['username'];
        $_SESSION['admin_role'] = 'super_admin'; 
        header("Location: index.php");
        exit;
    } else {
        // ২. যদি অ্যাডমিন না হয়, তবে স্টাফ টেবিলে চেক করবে
        $stmt_staff = $pdo->prepare("SELECT * FROM staff_accounts WHERE username = ? AND password = ?");
        $stmt_staff->execute([$username, $password]);
        $staff = $stmt_staff->fetch();

        if ($staff) {
            // ডাটাবেস থেকে পারমিশনগুলো ডিকোড করে সেশনে সেভ করা
            $permissions = json_decode($staff['permissions'], true) ?: [];
            
            if (empty($permissions)) {
                $error = "আপনার কোনো পেজে অ্যাক্সেস নেই! অ্যাডমিনের সাথে যোগাযোগ করুন।";
            } else {
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_username'] = $staff['username'];
                $_SESSION['admin_name'] = $staff['name'];
                $_SESSION['admin_role'] = 'staff';
                $_SESSION['staff_permissions'] = $permissions; // স্টাফের পারমিশন সেশনে সেভ
                
                // স্টাফের যে পেজে পারমিশন আছে, তাকে প্রথমে সেই পেজেই পাঠানো হবে
                if(in_array('dashboard', $permissions)) {
                    header("Location: index.php");
                } elseif(in_array('add_entry', $permissions)) {
                    header("Location: add_entry.php");
                } elseif(in_array('manage_shareholders', $permissions)) {
                    header("Location: manage_shareholders.php");
                } elseif(in_array('financial_reports', $permissions)) {
                    header("Location: financial_reports.php");
                } else {
                    // ডিফল্ট ড্যাশবোর্ড
                    header("Location: index.php");
                }
                exit;
            }
        } else {
            $error = "ভুল ইউজারনেম বা পাসওয়ার্ড!";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Login - Sodai Lagbe ERP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>
<body class="bg-slate-900 flex items-center justify-center h-screen p-4">
    <div class="bg-white p-8 rounded-3xl shadow-2xl w-full max-w-md border border-slate-100">
        <div class="text-center mb-8">
            <div class="w-16 h-16 bg-emerald-100 text-emerald-500 rounded-2xl flex items-center justify-center text-3xl mx-auto mb-4 shadow-sm border border-emerald-200">
                <i class="fas fa-boxes"></i>
            </div>
            <h1 class="text-3xl font-black text-slate-800 mb-1">Sodai Lagbe</h1>
            <p class="text-slate-500 font-bold text-sm tracking-wide uppercase">সিস্টেম লগইন (অ্যাডমিন/স্টাফ)</p>
        </div>

        <?php if($error): ?>
            <div class="bg-rose-50 text-rose-600 p-4 rounded-xl mb-6 text-center text-sm font-bold border border-rose-200 shadow-sm flex items-center justify-center gap-2">
                <i class="fas fa-exclamation-circle"></i> <?= $error ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="mb-5">
                <label class="block text-slate-600 text-[11px] font-black uppercase tracking-widest mb-2 ml-1">ইউজারনেম</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-4 text-slate-400"><i class="fas fa-user"></i></span>
                    <input type="text" name="username" class="w-full pl-11 px-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 transition shadow-sm font-bold text-slate-800" placeholder="ইউজারনেম দিন" required>
                </div>
            </div>
            
            <div class="mb-8">
                <label class="block text-slate-600 text-[11px] font-black uppercase tracking-widest mb-2 ml-1">পাসওয়ার্ড</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-4 text-slate-400"><i class="fas fa-lock"></i></span>
                    <input type="password" name="password" class="w-full pl-11 px-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 transition shadow-sm font-bold text-slate-800 tracking-[0.2em]" placeholder="••••••••" required>
                </div>
            </div>

            <button type="submit" class="w-full bg-emerald-600 text-white font-black py-4 px-4 rounded-xl hover:bg-emerald-700 hover:shadow-lg transition-all transform active:scale-95 shadow-md flex justify-center items-center gap-2">
                লগইন করুন <i class="fas fa-arrow-right"></i>
            </button>
        </form>
    </div>
</body>
</html>