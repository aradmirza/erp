<?php
// ২৪ ঘণ্টার জন্য সেশন ও কুকি সেট করা
ini_set('session.cookie_lifetime', 86400);
ini_set('session.gc_maxlifetime', 86400);
session_set_cookie_params(86400);
session_start();

if(!isset($_SESSION['admin_logged_in'])) { header("Location: login.php"); exit; }

// === Role-Based Access Control (RBAC) ===
if(isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'staff') {
    $perms = $_SESSION['staff_permissions'] ?? [];
    if(!in_array('send_sms', $perms) && !in_array('dashboard', $perms)) { 
        die("<div style='text-align:center; padding:50px; font-family:sans-serif;'><h2 style='color:red;'>Access Denied!</h2><p>আপনার এই পেজে প্রবেশের অনুমতি নেই।</p><a href='login.php'>লগআউট করুন</a></div>"); 
    }
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';

// এসএমএস হিস্টোরি রাখার জন্য অটোমেটিক টেবিল তৈরি
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `sms_history` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `message` text NOT NULL,
      `target_type` varchar(50) NOT NULL,
      `recipient_count` int(11) NOT NULL DEFAULT 0,
      `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    )");
} catch (PDOException $e) {}

// ============================================================
// AJAX HANDLERS FOR LIVE SENDING & PROGRESS
// ============================================================
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    // ১. ফোন নম্বরগুলো কালেক্ট করা
    if ($_POST['ajax_action'] == 'get_numbers') {
        $target_type = $_POST['target_type'];
        $target_users = $_POST['target_users'] ?? [];
        $phones = [];
        
        if ($target_type === 'all') {
            $stmt = $pdo->query("SELECT phone FROM shareholder_accounts WHERE phone IS NOT NULL AND phone != ''");
            while ($row = $stmt->fetch()) { $phones[] = $row['phone']; }
        } else {
            if (!empty($target_users)) {
                $placeholders = implode(',', array_fill(0, count($target_users), '?'));
                $stmt = $pdo->prepare("SELECT phone FROM shareholder_accounts WHERE id IN ($placeholders) AND phone IS NOT NULL AND phone != ''");
                $stmt->execute($target_users);
                while ($row = $stmt->fetch()) { $phones[] = $row['phone']; }
            }
        }
        echo json_encode(['status' => 'success', 'phones' => array_values(array_unique($phones))]);
        exit;
    }
    
    // ২. সিঙ্গেল এসএমএস সেন্ড করা (লুপের জন্য) - Added Masking/Sender ID
    if ($_POST['ajax_action'] == 'send_single_sms') {
        $phone = preg_replace('/[^0-9]/', '', $_POST['phone']);
        $msg = $_POST['msg'];
        
        // SMS Net BD API Credentials
        $api_key = $_ENV['SMS_API_KEY'];
        $sender_id = $_ENV['SMS_SENDER_ID'] ?? 'Sodai Lagbe';
        
        if(strlen($phone) >= 11) {
            $curl = curl_init();
            curl_setopt_array($curl, array(
              CURLOPT_URL => 'https://api.sms.net.bd/sendsms',
              CURLOPT_RETURNTRANSFER => true,
              CURLOPT_CUSTOMREQUEST => 'POST',
              CURLOPT_POSTFIELDS => array(
                  'api_key' => $api_key, 
                  'msg' => $msg, 
                  'to' => $phone,
                  'sender_id' => $sender_id // মাস্কিং নেম পাঠানো হচ্ছে
              ),
            ));
            $response = curl_exec($curl);
            curl_close($curl);
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error']);
        }
        exit;
    }
    
    // ৩. হিস্টোরি ডাটাবেসে সেভ করা
    if ($_POST['ajax_action'] == 'save_history') {
        $msg = $_POST['msg'];
        $type = $_POST['target_type'];
        $count = (int)$_POST['count'];
        $stmt = $pdo->prepare("INSERT INTO sms_history (message, target_type, recipient_count) VALUES (?, ?, ?)");
        $stmt->execute([$msg, $type, $count]);
        echo json_encode(['status' => 'success']);
        exit;
    }
}
// ============================================================

// ডাটা ফেচ
$users_with_phones = [];
try {
    $users_with_phones = $pdo->query("SELECT id, name, phone, username, profile_picture FROM shareholder_accounts WHERE phone IS NOT NULL AND phone != '' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {}

$sms_history = [];
try {
    $sms_history = $pdo->query("SELECT * FROM sms_history ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {}

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
    <title>SMS Panel - Sodai Lagbe Admin</title>
    
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
        
        /* Modal Spin Animation */
        @keyframes fly {
            0% { transform: rotate(0deg) translate(0, 0); }
            25% { transform: rotate(10deg) translate(5px, -5px); }
            50% { transform: rotate(0deg) translate(10px, 0); }
            75% { transform: rotate(-10deg) translate(5px, 5px); }
            100% { transform: rotate(0deg) translate(0, 0); }
        }
        .animate-fly { animation: fly 1s ease-in-out infinite; }
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
            <a href="manage_votes.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-vote-yea w-6"></i> ভোটিং ও প্রস্তাবনা</a>
            <a href="manage_video.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-video w-6"></i> লাইভ ভিডিও</a>
            <a href="send_sms.php" class="flex items-center px-6 py-3 bg-blue-600/20 text-blue-400 border-l-4 border-blue-500 font-semibold"><i class="fas fa-sms w-6"></i> এসএমএস প্যানেল</a>
            
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
                <h2 class="text-lg font-black tracking-tight text-slate-800 flex items-center gap-2">
                    <i class="fas fa-sms text-blue-500 hidden sm:block"></i> এসএমএস প্যানেল
                </h2>
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
                        <h2 class="text-xl md:text-2xl font-black text-slate-800 flex items-center gap-2"><i class="fas fa-paper-plane text-blue-500"></i> মেসেজ পাঠান</h2>
                        <p class="text-xs text-slate-500 mt-1 font-bold">শেয়ারহোল্ডারদের নাম্বারে সরাসরি <span class="bg-slate-200 text-slate-700 px-1.5 py-0.5 rounded border border-slate-300">Sodai Lagbe</span> মাস্কিং সহ মেসেজ ডেলিভারি</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-5 gap-6 animate-fade-in" style="animation-delay: 0.1s;">
                    
                    <div class="lg:col-span-3">
                        <div class="app-card bg-white overflow-hidden shadow-sm">
                            <form onsubmit="processSMS(event)" id="smsForm" class="p-6">
                                <h3 class="text-sm font-black text-slate-800 mb-4 border-b border-slate-100 pb-2"><i class="fas fa-users text-indigo-500 mr-1.5"></i> প্রাপক নির্বাচন করুন</h3>
                                
                                <div class="bg-indigo-50/50 p-4 rounded-xl border border-indigo-100 mb-5 shadow-sm">
                                    <div class="flex gap-6">
                                        <label class="flex items-center gap-2 cursor-pointer group">
                                            <input type="radio" name="target_type" value="all" checked onchange="toggleSmsTarget()" class="w-5 h-5 text-indigo-600 focus:ring-indigo-500">
                                            <span class="text-sm font-black text-slate-700 group-hover:text-indigo-600 transition">সবাইকে (All)</span>
                                        </label>
                                        <label class="flex items-center gap-2 cursor-pointer group">
                                            <input type="radio" name="target_type" value="specific" onchange="toggleSmsTarget()" class="w-5 h-5 text-indigo-600 focus:ring-indigo-500">
                                            <span class="text-sm font-black text-slate-700 group-hover:text-indigo-600 transition">নির্দিষ্ট (Specific)</span>
                                        </label>
                                    </div>
                                    <p class="text-[10px] text-slate-500 font-bold mt-3"><i class="fas fa-info-circle text-indigo-400 mr-1"></i> সিস্টেম থেকে যাদের নম্বর সেভ করা আছে, শুধু তাদের কাছেই মেসেজ যাবে।</p>
                                </div>

                                <div id="specific_users_div" class="hidden animate-fade-in mb-6">
                                    <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                                        <div class="bg-slate-50 px-4 py-2 border-b border-slate-200 flex justify-between items-center">
                                            <span class="text-xs font-bold text-slate-600">ইউজার সিলেক্ট করুন</span>
                                            <span class="bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded text-[10px] font-black"><?= count($users_with_phones) ?> জন</span>
                                        </div>
                                        <div class="p-3 max-h-64 overflow-y-auto custom-scrollbar space-y-2">
                                            <?php foreach($users_with_phones as $u): ?>
                                            <label class="flex items-center gap-3 p-3 bg-white rounded-lg border border-slate-100 cursor-pointer hover:border-indigo-300 hover:bg-indigo-50/30 transition shadow-sm group">
                                                <input type="checkbox" name="target_users[]" value="<?= $u['id'] ?>" class="w-4 h-4 text-indigo-600 rounded focus:ring-indigo-500">
                                                <div class="w-8 h-8 rounded-full border border-slate-200 bg-slate-50 flex items-center justify-center font-bold text-[10px] text-slate-400 overflow-hidden shrink-0">
                                                    <?php if(!empty($u['profile_picture'])): ?>
                                                        <img src="../<?= htmlspecialchars($u['profile_picture']) ?>" class="w-full h-full object-cover">
                                                    <?php else: ?>
                                                        <?= strtoupper(substr($u['name'], 0, 1)) ?>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="min-w-0">
                                                    <span class="block text-xs font-black text-slate-800 truncate group-hover:text-indigo-700 transition-colors"><?= htmlspecialchars($u['name']) ?></span>
                                                    <span class="block text-[10px] text-slate-500 font-bold mt-0.5"><i class="fas fa-phone-alt text-[8px] text-slate-400 mr-1"></i><?= htmlspecialchars($u['phone']) ?></span>
                                                </div>
                                            </label>
                                            <?php endforeach; ?>
                                            <?php if(empty($users_with_phones)): ?>
                                                <div class="text-center py-6 text-slate-400 font-bold text-xs bg-slate-50 rounded-lg border border-dashed border-slate-200">
                                                    <i class="fas fa-phone-slash text-2xl mb-2 opacity-50 block"></i>
                                                    কোনো ইউজারের ফোন নম্বর পাওয়া যায়নি!
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <h3 class="text-sm font-black text-slate-800 mb-4 border-b border-slate-100 pb-2"><i class="fas fa-envelope-open-text text-emerald-500 mr-1.5"></i> মেসেজ কম্পোজ (SMS Body)</h3>
                                
                                <div class="relative mb-5">
                                    <textarea name="sms_message" id="smsInput" rows="6" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3.5 text-sm outline-none focus:bg-white focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 font-medium text-slate-700 transition custom-scrollbar shadow-sm" placeholder="এখানে আপনার মেসেজ লিখুন..." required onkeyup="countChars()"></textarea>
                                    <div class="absolute bottom-3 right-3 text-[10px] font-black text-slate-400 bg-white px-2 py-1 rounded shadow-sm border border-slate-100"><span id="charCount">0</span>/160 <span id="smsPart" class="text-blue-500 ml-1">| 1 SMS</span></div>
                                </div>
                                
                                <div class="bg-amber-50 p-3 rounded-xl border border-amber-100 mb-6 shadow-sm flex items-start gap-2">
                                    <i class="fas fa-info-circle text-amber-500 mt-0.5"></i>
                                    <p class="text-[10px] font-bold text-amber-800 leading-relaxed">সাবধানে মেসেজ সেন্ড করুন। একবার সেন্ড বাটনে ক্লিক করলে তা সরাসরি ইউজারদের নাম্বারে চলে যাবে এবং API ব্যালেন্স থেকে টাকা কাটবে।</p>
                                </div>

                                <button type="submit" id="submitBtn" class="w-full bg-blue-600 text-white font-black py-4 rounded-xl shadow-lg hover:bg-blue-700 hover:shadow-xl transition-all transform active:scale-95 text-sm flex justify-center items-center gap-2">
                                    <i class="fas fa-paper-plane"></i> মেসেজ সেন্ড করুন
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="lg:col-span-2">
                        <div class="app-card bg-white flex flex-col h-full max-h-[700px]">
                            <div class="px-5 py-4 border-b border-slate-100 bg-slate-50 flex justify-between items-center shrink-0 rounded-t-[15px]">
                                <h4 class="font-black text-slate-700 text-sm flex items-center gap-2"><i class="fas fa-history text-amber-500"></i> মেসেজ হিস্টোরি</h4>
                                <span class="bg-amber-100 text-amber-700 text-[10px] font-black px-2.5 py-0.5 rounded-full shadow-sm"><?= count($sms_history) ?></span>
                            </div>
                            
                            <div class="flex-1 overflow-y-auto custom-scrollbar p-4 space-y-3 bg-slate-50/30">
                                <?php foreach($sms_history as $hist): ?>
                                    <div class="bg-white p-4 rounded-xl shadow-sm border border-slate-100 hover:border-blue-200 transition group">
                                        <div class="flex justify-between items-start mb-2">
                                            <span class="text-[9px] font-bold text-slate-400"><i class="far fa-calendar-alt mr-1"></i><?= date('d M, h:i A', strtotime($hist['created_at'])) ?></span>
                                            <span class="bg-emerald-50 text-emerald-600 border border-emerald-100 px-1.5 py-0.5 rounded text-[9px] font-black uppercase tracking-wider"><i class="fas fa-check-circle mr-0.5"></i> <?= $hist['recipient_count'] ?> Sent</span>
                                        </div>
                                        <p class="text-xs text-slate-600 font-medium leading-relaxed bg-slate-50 p-2.5 rounded-lg border border-slate-100 mb-3 line-clamp-3">
                                            <?= htmlspecialchars($hist['message']) ?>
                                        </p>
                                        <button onclick="resendSms(`<?= htmlspecialchars(addslashes($hist['message'])) ?>`, '<?= $hist['target_type'] ?>')" class="w-full text-[10px] font-bold text-blue-600 bg-blue-50 py-1.5 rounded-lg border border-blue-100 hover:bg-blue-600 hover:text-white transition flex items-center justify-center gap-1.5">
                                            <i class="fas fa-sync-alt"></i> পুনরায় পাঠান (Resend)
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                                
                                <?php if(empty($sms_history)): ?>
                                    <div class="text-center py-10 text-slate-400 font-bold text-xs">
                                        <i class="fas fa-history text-4xl mb-3 opacity-30 block"></i>
                                        কোনো মেসেজ হিস্টোরি নেই।
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
        <a href="index.php" class="nav-item"><i class="fas fa-home"></i> Home</a>
        <a href="send_sms.php" class="nav-item active"><i class="fas fa-sms"></i> SMS</a>
        <a href="add_entry.php" class="nav-item"><i class="fas fa-plus-circle"></i> Entry</a>
        <button onclick="toggleSidebar()" class="nav-item bg-transparent border-none outline-none focus:outline-none"><i class="fas fa-bars"></i> Menu</button>
    </nav>

    <div id="liveSendModal" class="hidden fixed inset-0 bg-slate-900/80 flex items-center justify-center z-50 p-4 backdrop-blur-sm">
        <div class="bg-white p-8 rounded-3xl shadow-2xl w-full max-w-sm border border-slate-100 text-center transform scale-100 animate-fade-in flex flex-col items-center">
            
            <div class="relative w-24 h-24 mb-6 flex items-center justify-center">
                <div class="absolute inset-0 bg-blue-100 rounded-full animate-ping opacity-70"></div>
                <div class="relative w-20 h-20 bg-blue-500 text-white rounded-full flex items-center justify-center shadow-lg border-4 border-white z-10 transition-colors duration-300" id="iconContainer">
                    <i id="sendIcon" class="fas fa-paper-plane text-3xl animate-fly"></i>
                </div>
            </div>
            
            <h3 id="sendStatusText" class="text-lg font-black text-slate-800 mb-2">মেসেজ পাঠানো হচ্ছে...</h3>
            
            <div class="w-full bg-slate-100 rounded-full h-3 mb-4 overflow-hidden shadow-inner border border-slate-200">
                <div id="sendProgressBar" class="bg-gradient-to-r from-blue-400 to-blue-600 h-full transition-all duration-300" style="width: 0%"></div>
            </div>
            
            <div class="text-3xl font-black text-blue-600 font-mono tracking-widest drop-shadow-sm transition-colors" id="sendCounter">0 <span class="text-sm text-slate-400">/ 0</span></div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-2">Messages Sent</p>
            
            <p class="text-xs text-rose-500 font-bold mt-6 bg-rose-50 px-3 py-1.5 rounded-lg border border-rose-100 animate-pulse" id="warningText">দয়া করে পেজটি রিফ্রেশ বা বন্ধ করবেন না!</p>
        </div>
    </div>

    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('-translate-x-full');
            document.getElementById('sidebar-overlay').classList.toggle('hidden');
        }

        function toggleSmsTarget() {
            const type = document.querySelector('input[name="target_type"]:checked').value;
            const div = document.getElementById('specific_users_div');
            if (type === 'specific') {
                div.classList.remove('hidden');
            } else {
                div.classList.add('hidden');
            }
        }

        function countChars() {
            const text = document.getElementById('smsInput').value;
            const length = text.length;
            document.getElementById('charCount').innerText = length;
            
            let parts = 1;
            if(length > 160) {
                parts = Math.ceil(length / 153);
            }
            document.getElementById('smsPart').innerText = '| ' + parts + ' SMS';
        }

        function resendSms(msg, type) {
            document.getElementById('smsInput').value = msg;
            countChars();
            
            const radios = document.getElementsByName('target_type');
            for(let i=0; i<radios.length; i++) {
                if(radios[i].value === type) {
                    radios[i].checked = true;
                    toggleSmsTarget();
                    break;
                }
            }
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // Live AJAX SMS Sending Logic
        async function processSMS(e) {
            e.preventDefault();
            
            const msg = document.getElementById('smsInput').value;
            if(!msg.trim()) { alert('মেসেজ বডি ফাঁকা!'); return; }
            
            const targetType = document.querySelector('input[name="target_type"]:checked').value;
            let targetUsers = [];
            
            if(targetType === 'specific') {
                document.querySelectorAll('input[name="target_users[]"]:checked').forEach(cb => {
                    targetUsers.push(cb.value);
                });
                if(targetUsers.length === 0) { alert('অনুগ্রহ করে অন্তত একজন ইউজার সিলেক্ট করুন!'); return; }
            }
            
            // Show Live Modal
            const modal = document.getElementById('liveSendModal');
            const statusText = document.getElementById('sendStatusText');
            const counterEl = document.getElementById('sendCounter');
            const progress = document.getElementById('sendProgressBar');
            const icon = document.getElementById('sendIcon');
            const iconContainer = document.getElementById('iconContainer');
            const warningText = document.getElementById('warningText');
            
            modal.classList.remove('hidden');
            statusText.innerText = "নম্বর সংগ্রহ করা হচ্ছে...";
            counterEl.innerHTML = `0 <span class="text-sm text-slate-400">/ 0</span>`;
            progress.style.width = "0%";
            icon.className = "fas fa-sync-alt text-3xl fa-spin"; 
            iconContainer.className = "relative w-20 h-20 bg-blue-500 text-white rounded-full flex items-center justify-center shadow-lg border-4 border-white z-10 transition-colors duration-300";
            
            // 1. Get Phone Numbers via AJAX
            let formData = new FormData();
            formData.append('ajax_action', 'get_numbers');
            formData.append('target_type', targetType);
            targetUsers.forEach(id => formData.append('target_users[]', id));
            
            try {
                let res = await fetch('send_sms.php', { method: 'POST', body: formData });
                let data = await res.json();
                
                let phones = data.phones;
                if(phones.length === 0) {
                    statusText.innerText = "কোনো বৈধ নম্বর পাওয়া যায়নি!";
                    statusText.classList.add('text-rose-500');
                    icon.className = "fas fa-exclamation-triangle text-3xl";
                    iconContainer.classList.replace('bg-blue-500', 'bg-rose-500');
                    warningText.classList.add('hidden');
                    setTimeout(() => { window.location.reload(); }, 2500);
                    return;
                }
                
                statusText.innerText = "মেসেজ পাঠানো হচ্ছে...";
                icon.className = "fas fa-paper-plane text-3xl animate-fly"; 
                
                let successCount = 0;
                let total = phones.length;
                counterEl.innerHTML = `0 <span class="text-sm text-slate-400">/ ${total}</span>`;
                
                // 2. Loop and send 1 by 1
                for(let i=0; i<total; i++) {
                    let smsData = new FormData();
                    smsData.append('ajax_action', 'send_single_sms');
                    smsData.append('phone', phones[i]);
                    smsData.append('msg', msg);
                    
                    await fetch('send_sms.php', { method: 'POST', body: smsData });
                    
                    successCount++;
                    counterEl.innerHTML = `${successCount} <span class="text-sm text-slate-400">/ ${total}</span>`;
                    progress.style.width = ((successCount / total) * 100) + "%";
                }
                
                // 3. Save to History
                let histData = new FormData();
                histData.append('ajax_action', 'save_history');
                histData.append('msg', msg);
                histData.append('target_type', targetType);
                histData.append('count', successCount);
                await fetch('send_sms.php', { method: 'POST', body: histData });
                
                // 4. Show Success
                statusText.innerText = "সফলভাবে পাঠানো হয়েছে!";
                statusText.classList.remove('text-rose-500');
                statusText.classList.add('text-emerald-500');
                
                counterEl.classList.replace('text-blue-600', 'text-emerald-600');
                icon.className = "fas fa-check text-4xl";
                icon.classList.remove('animate-fly');
                iconContainer.classList.replace('bg-blue-500', 'bg-emerald-500');
                progress.classList.replace('from-blue-400', 'from-emerald-400');
                progress.classList.replace('to-blue-600', 'to-emerald-500');
                warningText.classList.add('hidden');
                
                setTimeout(() => window.location.reload(), 2000);
                
            } catch (err) {
                alert("সার্ভার এরর! মেসেজ পাঠানো বন্ধ হয়েছে।");
                modal.classList.add('hidden');
            }
        }
    </script>
</body>
</html>