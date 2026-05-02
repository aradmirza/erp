<?php
session_start();
set_time_limit(0); 

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

$message = ''; $error = '';

$stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_name = 'dashboard_video'");
$current_video = $stmt->fetchColumn();

// ওয়েবসাইট লোগো ফেচ (সাইডবারের জন্য)
$site_settings_stmt = $pdo->query("SELECT setting_name, setting_value FROM system_settings WHERE setting_name IN ('site_logo', 'site_favicon')");
$site_settings = $site_settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$site_logo = $site_settings['site_logo'] ?? '';
$site_favicon = $site_settings['site_favicon'] ?? '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['action']) && $_POST['action'] == 'upload') {
        
        if (isset($_FILES['dashboard_video'])) {
            $fileError = $_FILES['dashboard_video']['error'];
            
            if ($fileError === UPLOAD_ERR_OK) {
                $maxSize = 2 * 1024 * 1024 * 1024; 
                if ($_FILES['dashboard_video']['size'] > $maxSize) {
                    $error = "ফাইল সাইজ অনেক বড়! সর্বোচ্চ ২ জিবি আপলোড করা যাবে।";
                } else {
                    $allowedTypes = ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime'];
                    if (in_array($_FILES['dashboard_video']['type'], $allowedTypes)) {
                        $uploadDir = 'uploads/videos/';
                        if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
                        
                        $fileName = time() . '_' . preg_replace("/[^a-zA-Z0-9.]/", "", basename($_FILES['dashboard_video']['name']));
                        $targetFilePath = $uploadDir . $fileName;
                        
                        if(move_uploaded_file($_FILES['dashboard_video']['tmp_name'], $targetFilePath)){
                            $updateStmt = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_name = 'dashboard_video'");
                            $updateStmt->execute([$targetFilePath]);
                            
                            if(!empty($current_video) && file_exists($current_video)) {
                                unlink($current_video);
                            }
                            
                            // নতুন ভিডিও আপলোডের সাথে সাথে আগের ভিডিওর সব লাইক, কমেন্ট ও ভিউ রিসেট করা হচ্ছে
                            $pdo->query("TRUNCATE TABLE dashboard_reactions");
                            $pdo->query("TRUNCATE TABLE dashboard_comments");
                            $pdo->query("TRUNCATE TABLE dashboard_video_views");

                            $message = "নতুন ভিডিও সফলভাবে আপলোড হয়েছে এবং ডাটা রিসেট হয়েছে!";
                            $current_video = $targetFilePath; 
                        } else {
                            $error = "সার্ভারে ফাইল সেভ করতে সমস্যা হয়েছে! ডিরেক্টরি পারমিশন চেক করুন।";
                        }
                    } else {
                        $error = "শুধুমাত্র MP4, WEBM বা OGG ফরম্যাটের ভিডিও সাপোর্ট করে!";
                    }
                }
            } else {
                switch ($fileError) {
                    case UPLOAD_ERR_INI_SIZE:
                    case UPLOAD_ERR_FORM_SIZE:
                        $error = "ভিডিওর সাইজ সার্ভারের লিমিটের চেয়ে বড়! সার্ভারের (php.ini) upload_max_filesize বাড়ান।";
                        break;
                    case UPLOAD_ERR_PARTIAL:
                        $error = "ভিডিওটি আংশিকভাবে আপলোড হয়েছে। ইন্টারনেট কানেকশন চেক করুন।";
                        break;
                    case UPLOAD_ERR_NO_FILE:
                        $error = "দয়া করে একটি ভিডিও ফাইল নির্বাচন করুন।";
                        break;
                    default:
                        $error = "অজানা কোনো কারণে ভিডিও আপলোড ব্যর্থ হয়েছে!";
                        break;
                }
            }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] == 'delete') {
        if(!empty($current_video) && file_exists($current_video)) {
            unlink($current_video);
        }
        $pdo->query("UPDATE system_settings SET setting_value = '' WHERE setting_name = 'dashboard_video'");
        
        // ভিডিও ডিলিট হলে লাইক, কমেন্ট ও ভিউ ডিলিট
        $pdo->query("TRUNCATE TABLE dashboard_reactions");
        $pdo->query("TRUNCATE TABLE dashboard_comments");
        $pdo->query("TRUNCATE TABLE dashboard_video_views");

        $message = "ভিডিও রিমুভ করা হয়েছে!";
        $current_video = '';
    }
}

// ভিডিও থাকলে রিপোর্ট আনার লজিক
$views = []; $likes = []; $loves = []; $comments = [];
if (!empty($current_video)) {
    // ভিউয়ার্স ডাটা
    $views = $pdo->query("SELECT a.name, a.username, a.profile_picture FROM dashboard_video_views v JOIN shareholder_accounts a ON v.user_account_id = a.id ORDER BY v.id DESC")->fetchAll(PDO::FETCH_ASSOC);
    // লাইক ডাটা
    $likes = $pdo->query("SELECT a.name, a.username, a.profile_picture FROM dashboard_reactions r JOIN shareholder_accounts a ON r.user_account_id = a.id WHERE r.reaction_type = 'like' ORDER BY r.id DESC")->fetchAll(PDO::FETCH_ASSOC);
    // লাভ ডাটা
    $loves = $pdo->query("SELECT a.name, a.username, a.profile_picture FROM dashboard_reactions r JOIN shareholder_accounts a ON r.user_account_id = a.id WHERE r.reaction_type = 'love' ORDER BY r.id DESC")->fetchAll(PDO::FETCH_ASSOC);
    // কমেন্টস ডাটা
    $comments = $pdo->query("SELECT c.comment_text, c.created_at, a.name, a.username, a.profile_picture FROM dashboard_comments c JOIN shareholder_accounts a ON c.user_account_id = a.id ORDER BY c.id DESC")->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Manage Video - Sodai Lagbe Admin</title>
    
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
            <a href="manage_video.php" class="flex items-center px-6 py-3 bg-blue-600/20 text-blue-400 border-l-4 border-blue-500 font-semibold"><i class="fas fa-video w-6"></i> লাইভ ভিডিও</a>
            
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
                <h2 class="text-lg font-black tracking-tight text-slate-800 sm:hidden">লাইভ ভিডিও</h2>
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

                <div class="flex flex-wrap gap-4 justify-between items-end animate-fade-in">
                    <div>
                        <h2 class="text-xl md:text-2xl font-black text-slate-800 flex items-center gap-2"><i class="fas fa-video text-blue-500"></i> লাইভ ভিডিও ও এনগেজমেন্ট</h2>
                        <p class="text-xs text-slate-500 mt-1 font-bold">ড্যাশবোর্ডের ভিডিও আপলোড এবং ইউজার এনগেজমেন্ট রিপোর্ট</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    
                    <div class="lg:col-span-1 space-y-6">
                        <form method="POST" enctype="multipart/form-data" id="uploadForm" class="app-card bg-white p-6 relative overflow-hidden text-center animate-fade-in" style="animation-delay: 0.1s;">
                            <input type="hidden" name="action" value="upload">
                            
                            <div class="w-16 h-16 bg-blue-50 text-blue-500 rounded-full flex items-center justify-center text-3xl mx-auto mb-4 border border-blue-100 shadow-sm">
                                <i class="fas fa-cloud-upload-alt"></i>
                            </div>
                            <h3 class="font-black text-slate-800 mb-1">ভিডিও সিলেক্ট করুন</h3>
                            <p class="text-[10px] font-bold text-slate-500 mb-5 leading-relaxed">সর্বোচ্চ সাইজ: ২ জিবি (MP4 বেস্ট)</p>
                            
                            <input type="file" name="dashboard_video" id="videoInput" accept="video/mp4,video/webm,video/ogg" class="w-full text-xs text-slate-500 file:mr-3 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:text-[10px] file:font-bold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 cursor-pointer border border-slate-200 rounded-xl mb-4" required>
                            
                            <button type="submit" id="uploadBtn" class="w-full bg-blue-600 text-white font-bold py-3 rounded-xl shadow-md hover:bg-blue-700 transition-all active:scale-95 text-sm flex justify-center items-center gap-2">
                                <i class="fas fa-upload"></i> আপলোড করুন
                            </button>

                            <div id="loadingDiv" class="hidden mt-4 text-blue-600 bg-blue-50 p-4 rounded-xl border border-blue-100 flex flex-col items-center shadow-inner">
                                <i class="fas fa-circle-notch fa-spin text-2xl mb-2"></i>
                                <p class="text-[10px] font-bold leading-relaxed">আপলোড হচ্ছে... দয়া করে অপেক্ষা করুন। <br><span class="text-rose-500">ব্রাউজার রিফ্রেশ করবেন না!</span></p>
                            </div>
                        </form>
                    </div>

                    <div class="lg:col-span-2">
                        <div class="app-card bg-white overflow-hidden animate-fade-in" style="animation-delay: 0.2s;">
                            <div class="p-4 border-b border-slate-100 bg-slate-50 flex justify-between items-center">
                                <h3 class="font-black text-sm text-slate-800 flex items-center gap-2"><i class="fas fa-play-circle text-emerald-500"></i> বর্তমান লাইভ ভিডিও</h3>
                                <?php if(!empty($current_video)): ?>
                                <form method="POST" onsubmit="return confirm('ভিডিওটি সরিয়ে ফেলতে চান? (সকল লাইক ও কমেন্ট মুছে যাবে)');" class="m-0">
                                    <input type="hidden" name="action" value="delete">
                                    <button type="submit" class="bg-rose-50 text-rose-500 px-3 py-1.5 rounded-lg border border-rose-100 shadow-sm hover:bg-rose-500 hover:text-white transition text-xs font-bold flex items-center gap-1.5"><i class="fas fa-trash-alt"></i> ডিলিট করুন</button>
                                </form>
                                <?php endif; ?>
                            </div>
                            
                            <?php if(!empty($current_video)): ?>
                                <div class="bg-black w-full relative">
                                    <video class="w-full max-h-[350px] object-contain" controls>
                                        <source src="<?= htmlspecialchars($current_video) ?>" type="video/mp4">
                                        Your browser does not support the video tag.
                                    </video>
                                    <div class="absolute top-3 left-3 bg-red-600 text-white px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-widest shadow flex items-center gap-1.5 animate-pulse"><div class="w-1.5 h-1.5 bg-white rounded-full"></div> LIVE</div>
                                </div>
                            <?php else: ?>
                                <div class="py-16 text-center text-slate-400 bg-slate-50 border-t border-slate-100">
                                    <i class="fas fa-video-slash text-5xl mb-3 opacity-30 block"></i>
                                    <p class="font-bold text-sm">ড্যাশবোর্ডে বর্তমানে কোনো ভিডিও নেই।</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if(!empty($current_video)): ?>
                <div class="animate-fade-in" style="animation-delay: 0.3s;">
                    <div class="flex items-center gap-2 mb-4 px-1">
                        <h3 class="font-black text-slate-800 text-base"><i class="fas fa-chart-line text-indigo-500 mr-1.5"></i> এনগেজমেন্ট রিপোর্ট (Analytics)</h3>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                        
                        <div class="app-card bg-white flex flex-col h-[350px]">
                            <div class="px-5 py-4 border-b border-slate-100 bg-slate-50 flex justify-between items-center shrink-0 rounded-t-[15px]">
                                <h4 class="font-black text-slate-700 text-sm flex items-center gap-2"><i class="fas fa-eye text-blue-500"></i> ভিউয়ার্স</h4>
                                <span class="bg-blue-100 text-blue-700 text-[10px] font-black px-2.5 py-0.5 rounded-full shadow-sm"><?= count($views) ?></span>
                            </div>
                            <div class="flex-1 overflow-y-auto custom-scrollbar p-3 space-y-2">
                                <?php foreach($views as $v): ?>
                                    <div class="bg-white p-2.5 rounded-xl border border-slate-100 flex items-center gap-3 hover:border-blue-200 transition shadow-sm">
                                        <div class="w-8 h-8 bg-slate-100 text-slate-400 rounded-full flex items-center justify-center font-bold text-xs shrink-0 overflow-hidden border border-slate-200">
                                            <?php if(!empty($v['profile_picture'])): ?>
                                                <img src="../<?= htmlspecialchars($v['profile_picture']) ?>" class="w-full h-full object-cover">
                                            <?php else: ?>
                                                <?= strtoupper(substr($v['name'], 0, 1)) ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="font-bold text-slate-800 text-xs leading-tight truncate mb-0.5"><?= htmlspecialchars($v['name']) ?></div>
                                            <div class="text-[9px] font-bold text-slate-400 truncate">@<?= htmlspecialchars($v['username']) ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if(empty($views)) echo '<div class="text-[10px] text-slate-400 font-bold text-center py-8 italic border border-dashed border-slate-200 rounded-xl bg-slate-50">কেউ এখনো দেখেনি</div>'; ?>
                            </div>
                        </div>

                        <div class="app-card bg-white flex flex-col h-[350px]">
                            <div class="px-5 py-4 border-b border-slate-100 bg-slate-50 flex justify-between items-center shrink-0 rounded-t-[15px]">
                                <h4 class="font-black text-slate-700 text-sm flex items-center gap-2"><i class="fas fa-thumbs-up text-indigo-500"></i> লাইক করেছে</h4>
                                <span class="bg-indigo-100 text-indigo-700 text-[10px] font-black px-2.5 py-0.5 rounded-full shadow-sm"><?= count($likes) ?></span>
                            </div>
                            <div class="flex-1 overflow-y-auto custom-scrollbar p-3 space-y-2">
                                <?php foreach($likes as $l): ?>
                                    <div class="bg-white p-2.5 rounded-xl border border-slate-100 flex items-center gap-3 hover:border-indigo-200 transition shadow-sm">
                                        <div class="w-8 h-8 bg-indigo-50 text-indigo-400 rounded-full flex items-center justify-center font-bold text-xs shrink-0 overflow-hidden border border-indigo-100">
                                            <?php if(!empty($l['profile_picture'])): ?>
                                                <img src="../<?= htmlspecialchars($l['profile_picture']) ?>" class="w-full h-full object-cover">
                                            <?php else: ?>
                                                <?= strtoupper(substr($l['name'], 0, 1)) ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="font-bold text-slate-800 text-xs leading-tight truncate mb-0.5"><?= htmlspecialchars($l['name']) ?></div>
                                            <div class="text-[9px] font-bold text-slate-400 truncate">@<?= htmlspecialchars($l['username']) ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if(empty($likes)) echo '<div class="text-[10px] text-slate-400 font-bold text-center py-8 italic border border-dashed border-slate-200 rounded-xl bg-slate-50">কোনো লাইক নেই</div>'; ?>
                            </div>
                        </div>

                        <div class="app-card bg-white flex flex-col h-[350px]">
                            <div class="px-5 py-4 border-b border-slate-100 bg-slate-50 flex justify-between items-center shrink-0 rounded-t-[15px]">
                                <h4 class="font-black text-slate-700 text-sm flex items-center gap-2"><i class="fas fa-heart text-rose-500"></i> লাভ করেছে</h4>
                                <span class="bg-rose-100 text-rose-700 text-[10px] font-black px-2.5 py-0.5 rounded-full shadow-sm"><?= count($loves) ?></span>
                            </div>
                            <div class="flex-1 overflow-y-auto custom-scrollbar p-3 space-y-2">
                                <?php foreach($loves as $l): ?>
                                    <div class="bg-white p-2.5 rounded-xl border border-slate-100 flex items-center gap-3 hover:border-rose-200 transition shadow-sm">
                                        <div class="w-8 h-8 bg-rose-50 text-rose-400 rounded-full flex items-center justify-center font-bold text-xs shrink-0 overflow-hidden border border-rose-100">
                                            <?php if(!empty($l['profile_picture'])): ?>
                                                <img src="../<?= htmlspecialchars($l['profile_picture']) ?>" class="w-full h-full object-cover">
                                            <?php else: ?>
                                                <?= strtoupper(substr($l['name'], 0, 1)) ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="font-bold text-slate-800 text-xs leading-tight truncate mb-0.5"><?= htmlspecialchars($l['name']) ?></div>
                                            <div class="text-[9px] font-bold text-slate-400 truncate">@<?= htmlspecialchars($l['username']) ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if(empty($loves)) echo '<div class="text-[10px] text-slate-400 font-bold text-center py-8 italic border border-dashed border-slate-200 rounded-xl bg-slate-50">কোনো লাভ নেই</div>'; ?>
                            </div>
                        </div>

                    </div>

                    <div class="app-card bg-white flex flex-col mt-5">
                        <div class="px-5 py-4 border-b border-slate-100 bg-slate-50 flex justify-between items-center shrink-0 rounded-t-[15px]">
                            <h4 class="font-black text-slate-700 text-sm flex items-center gap-2"><i class="fas fa-comments text-amber-500"></i> শেয়ারহোল্ডারদের মন্তব্য</h4>
                            <span class="bg-amber-100 text-amber-700 text-[10px] font-black px-2.5 py-0.5 rounded-full shadow-sm"><?= count($comments) ?> টি</span>
                        </div>
                        <div class="p-4 max-h-[400px] overflow-y-auto custom-scrollbar space-y-3 bg-slate-50/30">
                            <?php foreach($comments as $c): ?>
                                <div class="bg-white p-4 rounded-xl shadow-sm border border-slate-100 flex gap-4 hover:border-amber-200 transition group">
                                    <div class="w-10 h-10 bg-slate-100 text-slate-400 rounded-full flex items-center justify-center font-bold text-sm shrink-0 overflow-hidden border border-slate-200">
                                        <?php if(!empty($c['profile_picture'])): ?>
                                            <img src="../<?= htmlspecialchars($c['profile_picture']) ?>" class="w-full h-full object-cover">
                                        <?php else: ?>
                                            <?= strtoupper(substr($c['name'], 0, 1)) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex-1">
                                        <div class="flex items-baseline gap-2 mb-1.5 flex-wrap">
                                            <span class="font-black text-slate-800 text-sm"><?= htmlspecialchars($c['name']) ?></span>
                                            <span class="text-[9px] text-slate-400 font-bold"><i class="far fa-calendar-alt mr-1"></i><?= date('d M Y, h:i A', strtotime($c['created_at'])) ?></span>
                                        </div>
                                        <p class="text-slate-600 text-xs leading-relaxed bg-slate-50 p-3 rounded-lg border border-slate-100"><?= nl2br(htmlspecialchars($c['comment_text'])) ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if(empty($comments)) echo '<div class="text-xs text-slate-400 font-bold text-center py-10 italic border border-dashed border-slate-200 rounded-xl bg-slate-50">এখনো কোনো মন্তব্য করা হয়নি।</div>'; ?>
                        </div>
                    </div>

                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <nav class="bottom-nav md:hidden shadow-[0_-5px_15px_rgba(0,0,0,0.05)] z-50">
        <a href="index.php" class="nav-item"><i class="fas fa-home"></i> Home</a>
        <a href="manage_shareholders.php" class="nav-item"><i class="fas fa-users"></i> Users</a>
        <a href="manage_video.php" class="nav-item active"><i class="fas fa-video"></i> Video</a>
        <button onclick="toggleSidebar()" class="nav-item bg-transparent border-none outline-none focus:outline-none"><i class="fas fa-bars"></i> Menu</button>
    </nav>

    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('-translate-x-full');
            document.getElementById('sidebar-overlay').classList.toggle('hidden');
        }

        document.getElementById('uploadForm').onsubmit = function() {
            document.getElementById('uploadBtn').classList.add('hidden');
            document.getElementById('loadingDiv').classList.remove('hidden');
            document.getElementById('loadingDiv').classList.add('animate-fade-in');
            document.getElementById('videoInput').classList.add('opacity-50', 'pointer-events-none');
        };
    </script>
</body>
</html>