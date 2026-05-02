<?php
ini_set('session.cookie_lifetime', 86400);
ini_set('session.gc_maxlifetime', 86400);
session_set_cookie_params(86400);
session_start();
date_default_timezone_set('Asia/Dhaka');

if(!isset($_SESSION['user_logged_in'])) { header("Location: login.php"); exit; }
require_once 'db.php';

$user_id = $_SESSION['user_account_id'];
$user_name = $_SESSION['user_name'];

// ইউজারের প্রোফাইল পিকচার ফেচ করা
$stmt_pic = $pdo->prepare("SELECT profile_picture FROM shareholder_accounts WHERE id = ?");
$stmt_pic->execute([$user_id]);
$u_pic = $stmt_pic->fetchColumn();

// ইউজারের পারমিশন চেক করা
$stmt = $pdo->prepare("SELECT can_vote FROM shareholder_accounts WHERE id = ?");
$stmt->execute([$user_id]);
$can_vote = (int)$stmt->fetchColumn();

$message = $_SESSION['msg_success'] ?? ''; 
$error = $_SESSION['msg_error'] ?? '';
unset($_SESSION['msg_success'], $_SESSION['msg_error']);

if ($_SERVER["REQUEST_METHOD"] == "POST" && $can_vote) {
    
    // নতুন প্রস্তাবনা যোগ করা
    if (isset($_POST['action']) && $_POST['action'] == 'submit_proposal') {
        $title = trim($_POST['title']);
        $desc = trim($_POST['description']);
        
        $options_array = $_POST['options'] ?? []; 
        $options_json = null;
        if(is_array($options_array)) {
            $opts_filtered = array_filter(array_map('trim', $options_array), function($val) { return $val !== ''; });
            if(count($opts_filtered) > 1) { $options_json = json_encode(array_values($opts_filtered), JSON_UNESCAPED_UNICODE); }
        }

        if (!empty($title) && !empty($desc)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO proposals (account_id, title, description, options) VALUES (?, ?, ?, ?)");
                if($stmt->execute([$user_id, $title, $desc, $options_json])) {
                    $_SESSION['msg_success'] = "আপনার প্রস্তাবনা সফলভাবে জমা হয়েছে! অ্যাডমিন অ্যাপ্রুভ করলে ভোটিং শুরু হবে।";
                }
            } catch(PDOException $e) {
                $_SESSION['msg_error'] = "প্রস্তাবনা জমা দিতে সমস্যা হয়েছে।";
            }
        }
        header("Location: user_votes.php"); exit;
    }
    
    // ভোট দেওয়া
    if (isset($_POST['action']) && $_POST['action'] == 'submit_vote') {
        $proposal_id = (int)$_POST['proposal_id'];
        $vote = $_POST['vote_type'];
        
        try {
            $stmt = $pdo->prepare("INSERT INTO votes (proposal_id, account_id, vote) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE vote = ?");
            if($stmt->execute([$proposal_id, $user_id, $vote, $vote])) {
                $_SESSION['msg_success'] = "আপনার ভোট গ্রহণ করা হয়েছে!";
            }
        } catch(PDOException $e) {
            $_SESSION['msg_error'] = "ভোট দিতে সমস্যা হয়েছে।";
        }
        header("Location: user_votes.php"); exit;
    }

    // প্রস্তাবনায় মতামত/কমেন্ট দেওয়া
    if (isset($_POST['action']) && $_POST['action'] == 'submit_comment') {
        $proposal_id = (int)$_POST['proposal_id'];
        $comment = trim($_POST['comment_text']);
        
        if (!empty($comment)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO proposal_comments (proposal_id, account_id, comment) VALUES (?, ?, ?)");
                if($stmt->execute([$proposal_id, $user_id, $comment])) {
                    $_SESSION['msg_success'] = "আপনার মতামত সফলভাবে যুক্ত হয়েছে!";
                }
            } catch(PDOException $e) {
                $_SESSION['msg_error'] = "মতামত যুক্ত করতে সমস্যা হয়েছে।";
            }
        }
        header("Location: user_votes.php"); exit;
    }

    // নিজের প্রস্তাবনা ডিলিট করা (যদি পেন্ডিং থাকে)
    if (isset($_POST['action']) && $_POST['action'] == 'delete_proposal') {
        $proposal_id = (int)$_POST['proposal_id'];
        $stmt = $pdo->prepare("DELETE FROM proposals WHERE id = ? AND account_id = ? AND status = 'pending'"); 
        $stmt->execute([$proposal_id, $user_id]);
        if($stmt->rowCount() > 0) {
            $pdo->prepare("DELETE FROM votes WHERE proposal_id = ?")->execute([$proposal_id]);
            $_SESSION['msg_success'] = "আপনার প্রস্তাবনাটি সফলভাবে মুছে ফেলা হয়েছে।";
        } else { 
            $_SESSION['msg_error'] = "প্রস্তাবনাটি মুছে ফেলা সম্ভব হয়নি।"; 
        }
        header("Location: user_votes.php"); exit;
    }
}

// সকল প্রস্তাবনা আনা
$all_proposals = [];
if($can_vote) {
    try {
        // টাইম ওভার হলে স্ট্যাটাস অটো-ক্লোজ আপডেট
        $pdo->exec("UPDATE proposals SET status = 'closed' WHERE status = 'approved' AND end_time IS NOT NULL AND end_time <= NOW()");
        
        $all_proposals = $pdo->query("
            SELECT p.*, a.name, a.profile_picture 
            FROM proposals p 
            JOIN shareholder_accounts a ON p.account_id = a.id 
            WHERE p.status IN ('pending', 'approved', 'closed', 'rejected') 
            ORDER BY p.created_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {}
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Voting System - Sodai Lagbe</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; -webkit-tap-highlight-color: transparent;}
        .glass-header { background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border-bottom: 1px solid rgba(226,232,240,0.8); }
        .app-card { background: #ffffff; border-radius: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.03), 0 1px 3px rgba(0,0,0,0.02); border: 1px solid rgba(226, 232, 240, 0.8); overflow: hidden;}
        .custom-scrollbar::-webkit-scrollbar { width: 4px; height: 4px; } 
        .custom-scrollbar::-webkit-scrollbar-thumb { background-color: #cbd5e1; border-radius: 10px; } 
        
        .bottom-nav { position: fixed; bottom: 0; left: 0; right: 0; background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px); border-top: 1px solid rgba(0,0,0,0.05); padding-bottom: env(safe-area-inset-bottom); z-index: 50; display: flex; justify-content: space-around; }
        .nav-item { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 10px 0; color: #64748b; font-size: 10px; font-weight: 700; width: 100%; transition: all 0.2s; cursor: pointer; text-decoration: none;}
        .nav-item.active { color: #2563eb; }
        .nav-item i { font-size: 18px; margin-bottom: 3px; transition: transform 0.2s;}
        .nav-item.active i { transform: translateY(-2px); }
        @media (min-width: 768px) { .bottom-nav { display: none; } }
        .main-content { padding-bottom: 80px; }
        @media (min-width: 768px) { .main-content { padding-bottom: 30px; } }
        
        @keyframes fadeInFast { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fade-in { animation: fadeInFast 0.4s ease-out forwards; }
        
        .vote-radio:checked + div { border-color: #3b82f6; background-color: #eff6ff; }
        .vote-radio:checked + div .radio-icon { color: #3b82f6; }
    </style>
</head>
<body class="text-slate-800 antialiased selection:bg-blue-200">

    <header class="glass-header sticky top-0 z-40 px-4 py-3 flex items-center justify-between shadow-sm">
        <div class="flex items-center gap-3">
            <a href="index.php" class="w-9 h-9 rounded-lg flex items-center justify-center bg-slate-100 text-slate-500 hover:bg-blue-50 hover:text-blue-600 transition"><i class="fas fa-arrow-left text-sm"></i></a>
            <div>
                <h1 class="text-sm font-black leading-none text-slate-800">ভোটিং প্যানেল</h1>
                <p class="text-[10px] mt-0.5 text-slate-500 font-bold">প্রস্তাবনা ও মতামত</p>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <div class="text-right hidden sm:block border-r border-slate-200 pr-3">
                <div class="text-sm font-bold leading-tight"><?= htmlspecialchars($user_name) ?></div>
                <div class="text-[10px] text-slate-500 font-bold uppercase tracking-wide">Shareholder</div>
            </div>
            <div class="w-9 h-9 bg-slate-100 rounded-full flex items-center justify-center text-slate-400 border border-slate-200 overflow-hidden shadow-sm shrink-0">
                <?php if(!empty($u_pic)): ?>
                    <img src="<?= htmlspecialchars($u_pic) ?>" class="w-full h-full object-cover">
                <?php else: ?>
                    <i class="fas fa-user text-sm"></i>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="max-w-4xl mx-auto px-4 py-6 main-content space-y-6">

        <?php if(!$can_vote): ?>
            <div class="app-card p-10 text-center bg-rose-50 border-rose-100 flex flex-col items-center justify-center animate-fade-in">
                <div class="w-20 h-20 bg-white rounded-full flex items-center justify-center text-rose-500 text-4xl mb-4 shadow-sm border border-rose-100">
                    <i class="fas fa-lock"></i>
                </div>
                <h2 class="text-xl font-black text-rose-700 mb-2">অ্যাক্সেস সংরক্ষিত!</h2>
                <p class="text-xs font-bold text-rose-600/80 leading-relaxed max-w-sm">দুঃখিত, এই মুহূর্তে আপনার প্রস্তাবনা দেওয়া বা ভোট দেওয়ার কোনো পারমিশন নেই। অ্যাডমিনের সাথে যোগাযোগ করুন।</p>
            </div>
        <?php else: ?>
            
            <?php if($message): ?><div class="bg-emerald-50 text-emerald-600 px-4 py-3 rounded-xl text-sm font-bold shadow-sm border border-emerald-100 flex items-center animate-fade-in"><i class="fas fa-check-circle mr-2 text-lg"></i> <?= $message ?></div><?php endif; ?>
            <?php if($error): ?><div class="bg-red-50 text-red-600 px-4 py-3 rounded-xl text-sm font-bold shadow-sm border border-red-100 flex items-center animate-fade-in"><i class="fas fa-exclamation-triangle mr-2 text-lg"></i> <?= $error ?></div><?php endif; ?>

            <div class="flex justify-between items-center animate-fade-in">
                <h2 class="text-lg font-black text-slate-800 flex items-center gap-2"><i class="fas fa-poll-h text-blue-500"></i> প্রস্তাবনা ও ভোটিং বোর্ড</h2>
                <button onclick="document.getElementById('newProposalModal').classList.remove('hidden')" class="bg-blue-600 text-white px-4 py-2.5 rounded-xl shadow-md hover:bg-blue-700 transition font-bold flex items-center gap-2 text-xs active:scale-95">
                    <i class="fas fa-pen-nib"></i> নতুন প্রস্তাবনা
                </button>
            </div>

            <div class="space-y-5">
                <?php foreach($all_proposals as $p): 
                    $votes_stmt = $pdo->query("SELECT vote, COUNT(*) as cnt FROM votes WHERE proposal_id = {$p['id']} GROUP BY vote");
                    $vote_counts = []; $total_votes = 0;
                    while($vr = $votes_stmt->fetch()) { $vote_counts[$vr['vote']] = $vr['cnt']; $total_votes += $vr['cnt']; }
                    
                    $my_vote_stmt = $pdo->prepare("SELECT vote FROM votes WHERE proposal_id = ? AND account_id = ?");
                    $my_vote_stmt->execute([$p['id'], $user_id]);
                    $my_vote = $my_vote_stmt->fetchColumn();
                    
                    $p_comments = $pdo->query("SELECT pc.*, IF(pc.account_id=0, 'Admin', a.name) as name FROM proposal_comments pc LEFT JOIN shareholder_accounts a ON pc.account_id = a.id WHERE pc.proposal_id = {$p['id']} ORDER BY pc.created_at ASC")->fetchAll();
                    
                    $remaining_seconds = ($p['status'] == 'approved' && $p['end_time']) ? strtotime($p['end_time']) - time() : 0;
                    $custom_options = json_decode($p['options'], true);
                    
                    $status_colors = ['pending'=>'bg-amber-100 text-amber-700 border-amber-200', 'approved'=>'bg-emerald-100 text-emerald-700 border-emerald-200', 'closed'=>'bg-slate-100 text-slate-600 border-slate-200', 'rejected'=>'bg-rose-100 text-rose-700 border-rose-200'];
                    $status_texts = ['pending'=>'Pending', 'approved'=>'Voting Live', 'closed'=>'Closed', 'rejected'=>'Rejected'];
                    $status_icons = ['pending'=>'fa-clock', 'approved'=>'fa-broadcast-tower animate-pulse', 'closed'=>'fa-lock', 'rejected'=>'fa-ban'];
                ?>
                <div class="app-card bg-white animate-fade-in hover:border-blue-200 transition-colors shadow-sm group">
                    <div class="p-5 border-b border-slate-100 flex flex-col sm:flex-row sm:justify-between sm:items-start gap-4 bg-slate-50/50">
                        <div class="flex items-start gap-3 min-w-0">
                            <div class="w-10 h-10 rounded-full border-2 border-slate-100 shadow-sm overflow-hidden shrink-0 flex items-center justify-center bg-white font-black text-slate-400 text-xs">
                                <?php if(!empty($p['profile_picture'])): ?>
                                    <img src="<?= htmlspecialchars($p['profile_picture']) ?>" class="w-full h-full object-cover">
                                <?php else: ?>
                                    <?= strtoupper(substr($p['name'], 0, 1)) ?>
                                <?php endif; ?>
                            </div>
                            <div class="truncate pr-2">
                                <h4 class="font-black text-slate-800 text-sm leading-tight mb-1 truncate group-hover:text-blue-600 transition-colors" title="<?= htmlspecialchars($p['title']) ?>"><?= htmlspecialchars($p['title']) ?></h4>
                                <div class="text-[9px] font-bold text-slate-500 uppercase tracking-widest flex items-center gap-1.5"><i class="fas fa-user text-slate-300"></i> <?= htmlspecialchars($p['name']) ?></div>
                            </div>
                        </div>
                        <div class="shrink-0">
                            <span class="text-[9px] font-black uppercase tracking-widest px-2.5 py-1 rounded-md border <?= $status_colors[$p['status']] ?> shadow-sm flex items-center justify-center gap-1.5 whitespace-nowrap">
                                <i class="fas <?= $status_icons[$p['status']] ?>"></i> <?= $status_texts[$p['status']] ?>
                            </span>
                        </div>
                    </div>

                    <div class="p-5">
                        <p class="text-xs text-slate-600 leading-relaxed mb-5 bg-slate-50 p-4 rounded-xl border border-slate-100 shadow-inner">
                            <?= nl2br(htmlspecialchars($p['description'])) ?>
                        </p>

                        <?php if($p['status'] == 'approved' && $p['end_time']): ?>
                            <div class="mb-5 bg-rose-50 text-rose-600 border border-rose-100 rounded-xl p-3 text-center text-xs font-black flex items-center justify-center gap-2 shadow-sm">
                                <i class="fas fa-hourglass-half animate-pulse text-rose-400"></i> <span class="countdown-timer tracking-wide" data-remaining="<?= $remaining_seconds ?>">লোড হচ্ছে...</span>
                            </div>
                        <?php endif; ?>

                        <?php if($p['status'] != 'pending' && $p['status'] != 'rejected'): ?>
                            <?php if($p['status'] == 'closed' || !empty($my_vote)): ?>
                                <div class="mb-5 bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
                                    <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4 flex items-center justify-between border-b border-slate-100 pb-2">
                                        <span><i class="fas fa-chart-pie mr-1"></i> ভোটের ফলাফল</span>
                                        <span class="bg-blue-50 text-blue-600 px-2 py-0.5 rounded shadow-sm border border-blue-100">Total Votes: <?= $total_votes ?></span>
                                    </div>
                                    
                                    <?php if(is_array($custom_options) && count($custom_options) > 0): ?>
                                        <div class="space-y-3">
                                            <?php foreach($custom_options as $opt): 
                                                $v_cnt = $vote_counts[$opt] ?? 0; 
                                                $v_pct = $total_votes > 0 ? ($v_cnt / $total_votes) * 100 : 0;
                                                $is_my_opt = ($my_vote == $opt);
                                            ?>
                                                <div>
                                                    <div class="flex justify-between text-[10px] font-bold mb-1.5 <?= $is_my_opt ? 'text-blue-700' : 'text-slate-600' ?>">
                                                        <span><?= htmlspecialchars($opt) ?> <?= $is_my_opt ? '<i class="fas fa-check-circle ml-1"></i>' : '' ?></span>
                                                        <span><?= round($v_pct) ?>% (<?= $v_cnt ?>)</span>
                                                    </div>
                                                    <div class="w-full bg-slate-100 rounded-full h-2 overflow-hidden shadow-inner flex">
                                                        <div class="h-full rounded-full transition-all duration-1000 <?= $is_my_opt ? 'bg-blue-500' : 'bg-slate-400' ?>" style="width: <?= $v_pct ?>%"></div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <?php 
                                            $yes_v = $vote_counts['yes'] ?? 0; $no_v = $vote_counts['no'] ?? 0;
                                            $yes_p = $total_votes > 0 ? ($yes_v / $total_votes) * 100 : 0; 
                                            $no_p = $total_votes > 0 ? ($no_v / $total_votes) * 100 : 0;
                                        ?>
                                        <div class="flex justify-between text-[10px] font-black mb-2 px-1">
                                            <span class="text-emerald-600 flex items-center gap-1"><i class="fas fa-check"></i> হ্যাঁ (<?= $yes_v ?>) <?= $my_vote == 'yes' ? '<span class="bg-emerald-100 px-1 rounded ml-1">You</span>' : '' ?></span>
                                            <span class="text-rose-500 flex items-center gap-1"><?= $my_vote == 'no' ? '<span class="bg-rose-100 px-1 rounded mr-1">You</span>' : '' ?> না (<?= $no_v ?>) <i class="fas fa-times"></i></span>
                                        </div>
                                        <div class="w-full bg-slate-100 rounded-full h-2.5 flex overflow-hidden shadow-inner border border-slate-200">
                                            <div class="bg-emerald-500 h-full transition-all duration-1000" style="width: <?= $yes_p ?>%"></div>
                                            <div class="bg-rose-500 h-full transition-all duration-1000" style="width: <?= $no_p ?>%"></div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="mb-5 bg-slate-50 p-6 rounded-xl border border-dashed border-slate-200 text-center">
                                    <i class="fas fa-lock text-slate-300 text-3xl mb-2"></i>
                                    <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest">ভোট প্রদান করার পর ফলাফল দেখতে পারবেন</p>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if($p['status'] == 'approved' && empty($my_vote)): ?>
                            <?php if(is_array($custom_options) && count($custom_options) > 0): ?>
                                <form method="POST" class="mb-5">
                                    <input type="hidden" name="action" value="submit_vote">
                                    <input type="hidden" name="proposal_id" value="<?= $p['id'] ?>">
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4">
                                        <?php foreach($custom_options as $opt): ?>
                                            <label class="relative cursor-pointer group">
                                                <input type="radio" name="vote_type" value="<?= htmlspecialchars($opt) ?>" class="vote-radio sr-only" required>
                                                <div class="flex items-center gap-3 p-3.5 border border-slate-200 rounded-xl bg-white hover:border-blue-400 hover:shadow-sm transition">
                                                    <i class="far fa-circle radio-icon text-slate-300 text-lg transition-colors group-hover:text-blue-300"></i>
                                                    <span class="text-xs font-bold text-slate-700 transition"><?= htmlspecialchars($opt) ?></span>
                                                </div>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <button type="submit" class="w-full bg-blue-600 text-white font-bold py-3.5 rounded-xl text-sm shadow-md hover:bg-blue-700 hover:shadow-lg transition-all active:scale-95 flex justify-center items-center gap-2"><i class="fas fa-paper-plane"></i> সাবমিট ভোট</button>
                                </form>
                            <?php else: ?>
                                <form method="POST" class="flex gap-3 mb-5">
                                    <input type="hidden" name="action" value="submit_vote">
                                    <input type="hidden" name="proposal_id" value="<?= $p['id'] ?>">
                                    <button type="submit" name="vote_type" value="yes" class="flex-1 py-3.5 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-xl text-sm font-bold hover:bg-emerald-500 hover:text-white transition shadow-sm flex justify-center items-center gap-2"><i class="fas fa-check-circle"></i> হ্যাঁ (Yes)</button>
                                    <button type="submit" name="vote_type" value="no" class="flex-1 py-3.5 bg-rose-50 text-rose-700 border border-rose-200 rounded-xl text-sm font-bold hover:bg-rose-500 hover:text-white transition shadow-sm flex justify-center items-center gap-2"><i class="fas fa-times-circle"></i> না (No)</button>
                                </form>
                            <?php endif; ?>
                        <?php elseif($p['status'] == 'approved' && !empty($my_vote)): ?>
                            <div class="mb-5 bg-emerald-50 text-emerald-700 py-3 rounded-xl border border-emerald-200 text-center font-bold text-xs flex justify-center items-center gap-2 shadow-sm"><i class="fas fa-check-double text-emerald-500"></i> আপনার ভোট গৃহীত হয়েছে: <span class="bg-white px-2 py-0.5 rounded shadow-sm"><?= htmlspecialchars($my_vote) ?></span></div>
                        <?php endif; ?>
                        
                        <div class="border-t border-slate-100 pt-4">
                            <button type="button" onclick="document.getElementById('prop_comments_<?= $p['id'] ?>').classList.toggle('hidden')" class="text-[10px] font-black text-slate-500 uppercase tracking-widest flex items-center gap-1.5 hover:text-blue-600 transition bg-slate-50 px-3 py-1.5 rounded-lg border border-slate-200">
                                <i class="fas fa-comments text-blue-500"></i> মতামত দেখুন (<?= count($p_comments) ?>) <i class="fas fa-angle-down ml-1"></i>
                            </button>
                            
                            <div id="prop_comments_<?= $p['id'] ?>" class="hidden mt-4">
                                <div class="space-y-3 mb-4 max-h-48 overflow-y-auto custom-scrollbar pr-2">
                                    <?php foreach($p_comments as $pc): 
                                        $is_admin = $pc['account_id'] == 0;
                                    ?>
                                        <div class="bg-slate-50 p-3.5 rounded-xl border <?= $is_admin ? 'border-indigo-200 bg-indigo-50/50 shadow-sm' : 'border-slate-100' ?>">
                                            <div class="flex justify-between items-baseline mb-1.5 border-b <?= $is_admin ? 'border-indigo-100' : 'border-slate-200' ?> pb-1.5">
                                                <span class="text-[11px] font-black <?= $is_admin ? 'text-indigo-700' : 'text-slate-700' ?>"><?= htmlspecialchars($pc['name']) ?> <?= $is_admin ? '<i class="fas fa-shield-alt text-[9px] ml-1 text-indigo-500"></i>' : '' ?></span>
                                                <span class="text-[9px] font-bold text-slate-400"><i class="far fa-clock mr-1"></i><?= date('d M, h:i A', strtotime($pc['created_at'])) ?></span>
                                            </div>
                                            <p class="text-xs text-slate-600 leading-relaxed"><?= nl2br(htmlspecialchars($pc['comment'])) ?></p>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if(empty($p_comments)): ?><div class="text-[10px] text-center text-slate-400 font-bold py-4 bg-slate-50 rounded-xl border border-dashed border-slate-200">এখনো কোনো মতামত দেওয়া হয়নি।</div><?php endif; ?>
                                </div>
                                
                                <?php if($p['status'] == 'approved' || $p['status'] == 'pending'): ?>
                                <form method="POST" class="flex gap-2 relative">
                                    <input type="hidden" name="action" value="submit_comment"><input type="hidden" name="proposal_id" value="<?= $p['id'] ?>">
                                    <input type="text" name="comment_text" class="w-full bg-white border border-slate-200 rounded-xl pl-4 pr-12 py-3 text-xs font-medium outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 shadow-sm transition" placeholder="আপনার মতামত লিখুন..." required>
                                    <button type="submit" class="absolute right-2 top-1/2 -translate-y-1/2 w-8 h-8 bg-blue-50 text-blue-600 rounded-lg text-xs font-bold hover:bg-blue-600 hover:text-white shadow-sm transition flex items-center justify-center border border-blue-100"><i class="fas fa-paper-plane text-[10px]"></i></button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if($p['account_id'] == $user_id && $p['status'] == 'pending'): ?>
                            <form method="POST" class="mt-4 text-right border-t border-slate-100 pt-3" onsubmit="return confirm('প্রস্তাবনাটি ডিলিট করতে চান?');">
                                <input type="hidden" name="action" value="delete_proposal"><input type="hidden" name="proposal_id" value="<?= $p['id'] ?>">
                                <button type="submit" class="text-[10px] text-rose-500 font-bold hover:underline bg-rose-50 px-2.5 py-1.5 rounded-lg border border-rose-100 flex items-center gap-1.5 ml-auto transition hover:bg-rose-500 hover:text-white"><i class="fas fa-trash-alt"></i> প্রস্তাবনা ডিলিট করুন</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <?php if(empty($all_proposals)): ?>
                    <div class="app-card p-12 text-center bg-white border-dashed border-slate-300">
                        <i class="fas fa-inbox text-5xl mb-4 text-slate-300 block"></i>
                        <p class="font-bold text-sm text-slate-500">বর্তমানে কোনো প্রস্তাবনা বা ভোটিং চলছে না।</p>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>

    <nav class="bottom-nav md:hidden shadow-[0_-5px_15px_rgba(0,0,0,0.05)] z-50">
        <a href="index.php" class="nav-item"><i class="fas fa-home"></i> Home</a>
        <a href="transactions.php" class="nav-item"><i class="fas fa-exchange-alt"></i> Trans</a>
        <a href="user_votes.php" class="nav-item active"><i class="fas fa-poll"></i> Vote</a>
    </nav>

    <div id="newProposalModal" class="hidden fixed inset-0 bg-slate-900/80 flex items-center justify-center z-50 p-4 backdrop-blur-sm transition-opacity">
        <div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg border border-slate-100 overflow-hidden transform scale-100 flex flex-col max-h-[90vh] animate-fade-in">
            <div class="px-6 py-5 bg-slate-800 flex justify-between items-center shrink-0">
                <h3 class="text-base font-black text-white flex items-center gap-2"><i class="fas fa-pen-nib text-blue-400"></i> নতুন প্রস্তাবনা দিন</h3>
                <button type="button" onclick="document.getElementById('newProposalModal').classList.add('hidden')" class="text-slate-400 hover:text-white transition"><i class="fas fa-times text-lg"></i></button>
            </div>
            
            <form method="POST" class="p-6 overflow-y-auto custom-scrollbar flex-1 bg-slate-50 space-y-5">
                <input type="hidden" name="action" value="submit_proposal">
                
                <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm space-y-4">
                    <div>
                        <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wide mb-1.5">প্রস্তাবনার শিরোনাম <span class="text-rose-500">*</span></label>
                        <input type="text" name="title" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold outline-none focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-100 transition" placeholder="কী বিষয়ে প্রস্তাব দিতে চান..." required>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wide mb-1.5">বিস্তারিত বিবরণ <span class="text-rose-500">*</span></label>
                        <textarea name="description" rows="4" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm outline-none focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-100 transition custom-scrollbar" placeholder="বিস্তারিতভাবে আপনার প্রস্তাবনাটি লিখুন..." required></textarea>
                    </div>
                </div>

                <div class="bg-blue-50/50 p-5 rounded-2xl border border-blue-100 shadow-sm">
                    <label class="block text-[10px] font-black text-blue-800 uppercase tracking-wide mb-2"><i class="fas fa-list-ul text-blue-500 mr-1"></i> কাস্টম ভোটিং অপশন (ঐচ্ছিক)</label>
                    <p class="text-[9px] font-bold text-blue-600 mb-3 leading-relaxed">আপনি যদি হ্যাঁ/না এর বদলে অন্য কোনো অপশন দিতে চান, তবে নিচে লিখুন। ফাঁকা রাখলে অটোমেটিক হ্যাঁ/না অপশন তৈরি হবে।</p>
                    
                    <div id="options_container" class="space-y-2">
                        <input type="text" name="options[]" class="w-full bg-white border border-slate-200 rounded-xl px-4 py-2.5 text-xs font-bold outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-200 transition shadow-sm" placeholder="অপশন ১ (যেমন: Project A)">
                        <input type="text" name="options[]" class="w-full bg-white border border-slate-200 rounded-xl px-4 py-2.5 text-xs font-bold outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-200 transition shadow-sm" placeholder="অপশন ২ (যেমন: Project B)">
                    </div>
                    <button type="button" onclick="addOptionField()" class="mt-3 text-[10px] font-bold text-blue-600 bg-white border border-blue-200 px-3 py-1.5 rounded-lg shadow-sm hover:bg-blue-600 hover:text-white hover:border-blue-600 transition flex items-center gap-1.5"><i class="fas fa-plus"></i> আরও অপশন যোগ করুন</button>
                </div>
                
                <div class="flex gap-3 pt-2">
                    <button type="button" onclick="document.getElementById('newProposalModal').classList.add('hidden')" class="flex-1 py-3.5 bg-slate-200 text-slate-700 font-bold rounded-xl hover:bg-slate-300 transition text-sm">বাতিল</button>
                    <button type="submit" class="flex-1 py-3.5 bg-blue-600 text-white font-bold rounded-xl shadow-md hover:bg-blue-700 hover:shadow-lg transition transform active:scale-[0.98] text-sm flex justify-center items-center gap-2"><i class="fas fa-paper-plane"></i> সাবমিট করুন</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function addOptionField() {
            const container = document.getElementById('options_container');
            const currentCount = container.querySelectorAll('input').length;
            const div = document.createElement('div');
            div.className = 'flex items-center gap-2 mt-2 animate-fade-in';
            div.innerHTML = `<input type="text" name="options[]" class="w-full bg-white border border-slate-200 rounded-xl px-4 py-2.5 text-xs font-bold outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-200 transition shadow-sm" placeholder="অপশন ${currentCount + 1}">
            <button type="button" onclick="this.parentElement.remove(); updateOptionNumbers();" class="w-10 h-10 bg-rose-50 text-rose-500 rounded-xl flex items-center justify-center shrink-0 border border-rose-100 hover:bg-rose-500 hover:text-white transition"><i class="fas fa-times text-[10px]"></i></button>`;
            container.appendChild(div);
        }
        function updateOptionNumbers() {
            document.querySelectorAll('#options_container input[name="options[]"]').forEach((input, index) => { 
                input.placeholder = 'অপশন ' + (index + 1); 
            });
        }

        // Live Countdown Timer JS
        setInterval(function() {
            document.querySelectorAll('.countdown-timer').forEach(function(el) {
                let remaining = parseInt(el.dataset.remaining);
                if (remaining <= 0) {
                    el.innerHTML = "Voting Closed";
                    if(el.dataset.reloaded !== "true") { 
                        el.dataset.reloaded = "true"; 
                        setTimeout(() => window.location.reload(), 2000); 
                    }
                } else {
                    el.dataset.remaining = remaining - 1;
                    let d = Math.floor(remaining / (3600*24));
                    let h = Math.floor((remaining % (3600*24)) / 3600);
                    let m = Math.floor((remaining % 3600) / 60);
                    let s = remaining % 60;
                    
                    let text = ""; 
                    if (d > 0) text += `<span class="bg-white/50 px-1 rounded">${d}d</span> `; 
                    text += `<span class="bg-white/50 px-1 rounded">${h}h</span> : <span class="bg-white/50 px-1 rounded">${m}m</span> : <span class="bg-white/50 px-1 rounded">${s}s</span> Left`; 
                    el.innerHTML = text;
                }
            });
        }, 1000);
        
        // Radio Button UI Update (Change icon when selected)
        document.querySelectorAll('.vote-radio').forEach(radio => {
            radio.addEventListener('change', function() {
                const form = this.closest('form');
                form.querySelectorAll('.radio-icon').forEach(icon => {
                    icon.classList.remove('fa-dot-circle', 'text-blue-500');
                    icon.classList.add('fa-circle');
                });
                if(this.checked) {
                    const activeIcon = this.nextElementSibling.querySelector('.radio-icon');
                    activeIcon.classList.remove('fa-circle');
                    activeIcon.classList.add('fa-dot-circle', 'text-blue-500');
                }
            });
        });
    </script>
</body>
</html>