<?php
// ২৪ ঘণ্টার জন্য সেশন ও কুকি সেট করা
ini_set('session.cookie_lifetime', 86400);
ini_set('session.gc_maxlifetime', 86400);
session_set_cookie_params(86400);
session_start();

// বাংলাদেশের টাইমজোন সেট করা
date_default_timezone_set('Asia/Dhaka');

if(!isset($_SESSION['user_logged_in'])) { header("Location: login.php"); exit; }
require_once 'db.php';

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `slot_sales` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `slot_number` int(11) NOT NULL,
      `account_id` int(11) NOT NULL,
      `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `unique_slot_sale` (`slot_number`)
    )");
    
    $pdo->exec("UPDATE proposals SET status = 'closed' WHERE status = 'approved' AND end_time IS NOT NULL AND end_time <= NOW()");
    $pdo->exec("ALTER TABLE votes MODIFY vote VARCHAR(255) NOT NULL");

    // প্রোপোজাল অপশন কলাম তৈরি
    $chk_options = $pdo->query("SHOW COLUMNS FROM proposals LIKE 'options'");
    if($chk_options && $chk_options->rowCount() == 0) {
        $pdo->exec("ALTER TABLE proposals ADD COLUMN options TEXT NULL DEFAULT NULL");
    }
    
    // OTP এর জন্য ফোন নাম্বারের কলাম তৈরি
    $chk_phone = $pdo->query("SHOW COLUMNS FROM shareholder_accounts LIKE 'phone'");
    if($chk_phone && $chk_phone->rowCount() == 0) {
        $pdo->exec("ALTER TABLE shareholder_accounts ADD COLUMN phone VARCHAR(20) NULL AFTER username");
    }

    // প্রোফাইল ছবির জন্য কলাম তৈরি
    $chk_pic = $pdo->query("SHOW COLUMNS FROM shareholder_accounts LIKE 'profile_picture'");
    if($chk_pic && $chk_pic->rowCount() == 0) {
        $pdo->exec("ALTER TABLE shareholder_accounts ADD COLUMN profile_picture VARCHAR(255) NULL AFTER phone");
    }
} catch (PDOException $e) {}

$user_account_id = $_SESSION['user_account_id'];

// ইউজারের লেটেস্ট ডাটা ফেচ করা
$user_stmt = $pdo->prepare("SELECT * FROM shareholder_accounts WHERE id = ?");
$user_stmt->execute([$user_account_id]);
$current_user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);

$user_name = $current_user_data['name'];
$_SESSION['user_name'] = $user_name; 

$message = $_SESSION['msg_success'] ?? ''; 
$error = $_SESSION['msg_error'] ?? '';
$show_otp_modal = $_SESSION['show_otp_modal'] ?? false;
unset($_SESSION['msg_success'], $_SESSION['msg_error'], $_SESSION['show_otp_modal']);

$stmt_perm = $pdo->prepare("SELECT can_vote FROM shareholder_accounts WHERE id = ?");
$stmt_perm->execute([$user_account_id]);
$can_vote = (int)$stmt_perm->fetchColumn();

// Check if user is an advisor
$adv_stmt = $pdo->prepare("SELECT role_name FROM advisor_targets WHERE user_id = ?");
$adv_stmt->execute([$user_account_id]);
$my_advisor_role = $adv_stmt->fetchColumn();

// সময় অনুযায়ী গ্রিটিং (Greeting) সেট করা
$current_hour = date('G');
$greeting = 'শুভ সকাল';
if ($current_hour >= 12 && $current_hour < 16) { $greeting = 'শুভ দুপুর'; }
elseif ($current_hour >= 16 && $current_hour < 18) { $greeting = 'শুভ বিকাল'; }
elseif ($current_hour >= 18 && $current_hour < 20) { $greeting = 'শুভ সন্ধ্যা'; }
elseif ($current_hour >= 20 || $current_hour < 6) { $greeting = 'শুভ রাত্রি'; }

// ওয়েবসাইট লোগো ও ফেভিকন ফেচ করা
$site_settings_stmt = $pdo->query("SELECT setting_name, setting_value FROM system_settings WHERE setting_name IN ('site_logo', 'site_favicon', 'mother_project_id')");
$site_settings = $site_settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$site_logo = $site_settings['site_logo'] ?? '';
$site_favicon = $site_settings['site_favicon'] ?? '';
$mother_project_id = (int)($site_settings['mother_project_id'] ?? 0);

// SMS Function
if (!function_exists('sendSMS')) {
    function sendSMS($phone, $otp) {
        $api_key = 'iBfwXO9JKX7X1Yul8dGE76RPk5dOiLg7vRzQv6vM'; 
        $msg = "Sodai Lagbe ERP: Your OTP for password reset is $otp. Do not share this with anyone.";
        $curl = curl_init();
        curl_setopt_array($curl, array( CURLOPT_URL => 'https://api.sms.net.bd/sendsms', CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_POSTFIELDS => array('api_key' => $api_key,'msg' => $msg,'to' => $phone), ));
        $response = curl_exec($curl); curl_close($curl); return true;
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    
    // Profile Update
    if ($_POST['action'] == 'update_profile') {
        $name = trim($_POST['name']); $username = trim($_POST['username']); $phone = trim($_POST['phone']);
        $profile_pic_path = $current_user_data['profile_picture'];
        
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = 'uploads/profiles/';
            if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }
            $fileName = time() . '_' . preg_replace("/[^a-zA-Z0-9.]/", "", basename($_FILES['profile_picture']['name']));
            if(move_uploaded_file($_FILES['profile_picture']['tmp_name'], $uploadDir . $fileName)){
                $profile_pic_path = $uploadDir . $fileName;
            }
        }
        try {
            $check_user = $pdo->prepare("SELECT id FROM shareholder_accounts WHERE username = ? AND id != ?");
            $check_user->execute([$username, $user_account_id]);
            if ($check_user->rowCount() > 0) { $_SESSION['msg_error'] = "ইউজারনেমটি ইতিমধ্যে ব্যবহৃত হচ্ছে।"; } 
            else {
                $pdo->prepare("UPDATE shareholder_accounts SET name=?, username=?, phone=?, profile_picture=? WHERE id=?")->execute([$name, $username, $phone, $profile_pic_path, $user_account_id]);
                $pdo->prepare("UPDATE shareholders SET name=? WHERE account_id=?")->execute([$name, $user_account_id]);
                $_SESSION['user_name'] = $name; $_SESSION['msg_success'] = "প্রোফাইল আপডেট হয়েছে!";
            }
        } catch(PDOException $e) { $_SESSION['msg_error'] = "সমস্যা হয়েছে!"; }
        header("Location: index.php"); exit;
    }
    
    // Request Password Change
    if ($_POST['action'] == 'request_password_change') {
        $old_pass = $_POST['old_password']; $new_pass = $_POST['new_password'];
        if ($old_pass === $current_user_data['password']) {
            if (!empty($current_user_data['phone'])) {
                $otp = rand(100000, 999999);
                $_SESSION['pending_new_password'] = $new_pass; $_SESSION['change_pass_otp'] = $otp;
                sendSMS($current_user_data['phone'], $otp);
                $_SESSION['msg_success'] = "OTP পাঠানো হয়েছে।"; $_SESSION['show_otp_modal'] = true;
            } else { $_SESSION['msg_error'] = "মোবাইল নাম্বার যুক্ত নেই!"; }
        } else { $_SESSION['msg_error'] = "পুরাতন পাসওয়ার্ড সঠিক নয়!"; }
        header("Location: index.php"); exit;
    }

    // Verify OTP
    if ($_POST['action'] == 'verify_password_otp') {
        $entered_otp = trim($_POST['otp']);
        if (isset($_SESSION['change_pass_otp']) && $entered_otp == $_SESSION['change_pass_otp']) {
            $new_pass = $_SESSION['pending_new_password'];
            $pdo->prepare("UPDATE shareholder_accounts SET password = ? WHERE id = ?")->execute([$new_pass, $user_account_id]);
            unset($_SESSION['change_pass_otp'], $_SESSION['pending_new_password']);
            $_SESSION['msg_success'] = "পাসওয়ার্ড পরিবর্তন হয়েছে।";
        } else {
            $_SESSION['msg_error'] = "ভুল OTP!"; $_SESSION['show_otp_modal'] = true; 
        }
        header("Location: index.php"); exit;
    }

    // Video Reactions & Comments
    if ($_POST['action'] == 'react') {
        $reaction = $_POST['reaction_type'];
        $stmt = $pdo->prepare("SELECT reaction_type FROM dashboard_reactions WHERE user_account_id = ?"); $stmt->execute([$user_account_id]); $current_react = $stmt->fetchColumn();
        if ($current_react) {
            if ($current_react == $reaction) { $pdo->prepare("DELETE FROM dashboard_reactions WHERE user_account_id = ?")->execute([$user_account_id]); } 
            else { $pdo->prepare("UPDATE dashboard_reactions SET reaction_type = ? WHERE user_account_id = ?")->execute([$reaction, $user_account_id]); }
        } else { $pdo->prepare("INSERT INTO dashboard_reactions (user_account_id, reaction_type) VALUES (?, ?)")->execute([$user_account_id, $reaction]); }
        header("Location: index.php"); exit;
    }
    if ($_POST['action'] == 'comment') {
        $comment = trim($_POST['comment_text']);
        if (!empty($comment)) { $pdo->prepare("INSERT INTO dashboard_comments (user_account_id, comment_text) VALUES (?, ?)")->execute([$user_account_id, $comment]); }
        $_SESSION['show_comments'] = true; header("Location: index.php"); exit;
    }

    // Sell Slot
    if ($_POST['action'] == 'sell_slot') {
        $sell_slot_num = (int)$_POST['sell_slot_number'];
        $pdo->prepare("INSERT IGNORE INTO slot_sales (slot_number, account_id) VALUES (?, ?)")->execute([$sell_slot_num, $user_account_id]);
        header("Location: index.php"); exit;
    }
    if ($_POST['action'] == 'cancel_sell_slot') {
        $sell_slot_num = (int)$_POST['sell_slot_number'];
        $pdo->prepare("DELETE FROM slot_sales WHERE slot_number = ? AND account_id = ?")->execute([$sell_slot_num, $user_account_id]);
        header("Location: index.php"); exit;
    }
}

$show_comments = isset($_SESSION['show_comments']) ? true : false; unset($_SESSION['show_comments']);

// Video & Engagement Data
try {
    $video_stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_name = 'dashboard_video'");
    $dashboard_video = $video_stmt->fetchColumn();

    // Resolve correct file path (admin previously saved to admin/uploads/videos/ by mistake)
    $video_src = $dashboard_video;
    if (!empty($dashboard_video) && !filter_var($dashboard_video, FILTER_VALIDATE_URL)) {
        if (!file_exists($dashboard_video) && file_exists('admin/' . $dashboard_video)) {
            $video_src = 'admin/' . $dashboard_video;
        }
    }

    $view_count = 0;
    if (!empty($dashboard_video)) {
        $pdo->prepare("INSERT IGNORE INTO dashboard_video_views (user_account_id) VALUES (?)")->execute([$user_account_id]);
        $view_count = $pdo->query("SELECT COUNT(*) FROM dashboard_video_views")->fetchColumn();
    }
    $reactions_stmt = $pdo->query("SELECT reaction_type, COUNT(*) as count FROM dashboard_reactions GROUP BY reaction_type");
    $reaction_counts = ['like' => 0, 'love' => 0];
    while($row = $reactions_stmt->fetch()) { $reaction_counts[$row['reaction_type']] = $row['count']; }

    $my_reaction_stmt = $pdo->prepare("SELECT reaction_type FROM dashboard_reactions WHERE user_account_id = ?");
    $my_reaction_stmt->execute([$user_account_id]);
    $my_reaction = $my_reaction_stmt->fetchColumn();

    $comments = $pdo->query("SELECT c.*, a.name, a.profile_picture FROM dashboard_comments c JOIN shareholder_accounts a ON c.user_account_id = a.id ORDER BY c.id DESC")->fetchAll(PDO::FETCH_ASSOC);

    $global_profit_stmt = $pdo->query("SELECT SUM(amount) FROM financials WHERE type='profit' AND status='approved' AND project_id IS NULL");
    $global_profit = (float)$global_profit_stmt->fetchColumn() ?: 0;

    $global_shares_stmt = $pdo->query("SELECT SUM(number_of_shares) FROM shareholders");
    $total_global_shares = (float)$global_shares_stmt->fetchColumn() ?: 0;
    $global_profit_per_share = ($total_global_shares > 0) ? ($global_profit / $total_global_shares) : 0;

    // =========================================================================
    // প্রজেক্ট স্পেসিফিক ডাটা এবং মাদার/চাইল্ড লজিক ক্যালকুলেশন
    // =========================================================================
    $projects_raw = $pdo->query("
        SELECT p.id, p.project_name, p.dist_type, p.mother_commission_pct,
               (SELECT COALESCE(SUM(amount), 0) FROM financials WHERE type='profit' AND status='approved' AND project_id = p.id) as gross_profit,
               (SELECT COALESCE(SUM(number_of_shares), 0) FROM shareholders WHERE assigned_project_id = p.id) as total_shares,
               (SELECT COALESCE(SUM(investment_credit), 0) FROM shareholders WHERE assigned_project_id = p.id) as total_inv
        FROM projects p
    ")->fetchAll(PDO::FETCH_ASSOC);

    $total_mother_commission = 0; $projects_data = [];
    foreach ($projects_raw as $p) {
        $gross_profit = (float)$p['gross_profit']; $net_profit = $gross_profit; 
        if ($mother_project_id > 0 && $p['id'] != $mother_project_id && $p['mother_commission_pct'] > 0) {
            $commission_amount = $gross_profit * ($p['mother_commission_pct'] / 100);
            $total_mother_commission += $commission_amount;
            $net_profit = $gross_profit - $commission_amount; 
        }
        $p['net_distributable_profit'] = $net_profit; $projects_data[$p['id']] = $p;
    }
    if ($mother_project_id > 0 && isset($projects_data[$mother_project_id])) { $projects_data[$mother_project_id]['net_distributable_profit'] += $total_mother_commission; }

    // User Shares
    $my_shares_stmt = $pdo->prepare("SELECT s.*, p.project_name FROM shareholders s LEFT JOIN projects p ON s.assigned_project_id = p.id WHERE s.account_id = ? ORDER BY s.id DESC");
    $my_shares_stmt->execute([$user_account_id]);
    $my_shares = $my_shares_stmt->fetchAll(PDO::FETCH_ASSOC);

    $personal_total_shares = 0; 
    $personal_total_investment = 0; 
    $personal_total_profit = 0; 
    $personal_incentive_bonus = 0; // NEW: Track total incentive for this user
    $user_projects_summary = [];

    foreach ($my_shares as $sh) {
        $personal_total_shares += $sh['number_of_shares']; 
        $personal_total_investment += $sh['investment_credit'];
        $p_id = $sh['assigned_project_id'] ? $sh['assigned_project_id'] : 'general';
        $p_name = $sh['project_name'] ?? 'General Fund';
        
        if(!isset($user_projects_summary[$p_id])) { 
            $user_projects_summary[$p_id] = [ 'name' => $p_name, 'id' => $p_id, 'investment' => 0, 'shares' => 0, 'profit' => 0, 'percent' => 0, 'dist_type' => 'by_share', 'incentive' => 0 ]; 
        }
        $user_projects_summary[$p_id]['investment'] += $sh['investment_credit']; 
        $user_projects_summary[$p_id]['shares'] += $sh['number_of_shares'];
        
        $entry_profit = 0;
        if ($sh['assigned_project_id']) {
            if (isset($projects_data[$p_id])) {
                $proj = $projects_data[$p_id];
                $user_projects_summary[$p_id]['dist_type'] = $proj['dist_type'];
                
                $fraction = 0;
                if ($proj['dist_type'] == 'by_investment' && $proj['total_inv'] > 0) {
                    $fraction = $sh['investment_credit'] / $proj['total_inv'];
                    $user_projects_summary[$p_id]['percent'] = ($user_projects_summary[$p_id]['investment'] / $proj['total_inv']) * 100;
                } elseif ($proj['dist_type'] == 'by_share' && $proj['total_shares'] > 0) {
                    $fraction = $sh['number_of_shares'] / $proj['total_shares'];
                    $user_projects_summary[$p_id]['percent'] = ($user_projects_summary[$p_id]['shares'] / $proj['total_shares']) * 100;
                }
                
                if ($proj['net_distributable_profit'] > 0) {
                    $entry_profit = ($proj['net_distributable_profit'] * $fraction);
                }
                
                // Calculate Incentive specifically for this user if this is the Mother Project
                if ($p_id == $mother_project_id && $total_mother_commission > 0 && $fraction > 0) {
                    $my_incentive = $total_mother_commission * $fraction;
                    $personal_incentive_bonus += $my_incentive;
                    $user_projects_summary[$p_id]['incentive'] += $my_incentive;
                }
            }
        } else {
            $entry_profit = ($global_profit_per_share * $sh['number_of_shares']);
            $user_projects_summary[$p_id]['percent'] = ($total_global_shares > 0) ? ($user_projects_summary[$p_id]['shares'] / $total_global_shares) * 100 : 0;
        }

        $user_projects_summary[$p_id]['profit'] += $entry_profit; 
        $personal_total_profit += $entry_profit;
    }
    
    $personal_global_percentage = ($total_global_shares > 0) ? ($personal_total_shares / $total_global_shares) * 100 : 0;

    // Recovery Logic
    $stmt_perm_exp = $pdo->query("SELECT SUM(amount) as perm_exp FROM permanent_expenses");
    $total_permanent_expense = (float)($stmt_perm_exp->fetch()['perm_exp'] ?? 0);
    $personal_target_profit = ($total_global_shares > 0) ? $total_permanent_expense * ($personal_total_shares / $total_global_shares) : 0;
    
    $personal_recovery_gap = $personal_target_profit - $personal_total_profit;
    $personal_recovery_pct = ($personal_target_profit > 0) ? ($personal_total_profit / $personal_target_profit) * 100 : 100;
    if($personal_recovery_pct > 100) $personal_recovery_pct = 100;

    // Graph Data
    $range_days = isset($_GET['range']) ? (int)$_GET['range'] : 15;
    if(!in_array($range_days, [15, 30, 90])) $range_days = 15;
    $raw_dates = []; $chart_dates = [];
    for ($i = $range_days - 1; $i >= 0; $i--) { $date = date('Y-m-d', strtotime("-$i days")); $raw_dates[] = $date; $chart_dates[] = date('d M', strtotime($date)); }

    $colors = [ ['border'=>'#10b981','bg'=>'rgba(16, 185, 129, 0.1)'], ['border'=>'#3b82f6','bg'=>'rgba(59, 130, 246, 0.1)'], ['border'=>'#f59e0b','bg'=>'rgba(245, 158, 11, 0.1)'], ['border'=>'#8b5cf6','bg'=>'rgba(139, 92, 246, 0.1)'] ];
    $profit_ds = []; $expense_ds = [];
    $base_config = ['borderWidth' => 3, 'tension' => 0.4, 'pointRadius' => 2, 'pointHoverRadius' => 6, 'fill' => true, 'pointBackgroundColor' => '#fff'];
    
    $profit_ds['general'] = array_merge(['label' => 'General Fund', 'data' => array_fill_keys($raw_dates, 0), 'borderColor' => '#64748b', 'backgroundColor' => 'rgba(100, 116, 139, 0.1)'], $base_config);
    $expense_ds['general'] = array_merge(['label' => 'General Fund', 'data' => array_fill_keys($raw_dates, 0), 'borderColor' => '#64748b', 'backgroundColor' => 'rgba(100, 116, 139, 0.1)'], $base_config);

    foreach ($projects_data as $idx => $p) {
        $c = $colors[$idx % count($colors)];
        $profit_ds[$p['id']] = array_merge(['label' => $p['project_name'], 'data' => array_fill_keys($raw_dates, 0), 'borderColor' => $c['border'], 'backgroundColor' => $c['bg']], $base_config);
        $expense_ds[$p['id']] = array_merge(['label' => $p['project_name'], 'data' => array_fill_keys($raw_dates, 0), 'borderColor' => $c['border'], 'backgroundColor' => $c['bg']], $base_config);
    }

    $p_stmt = $pdo->prepare("SELECT DATE(date_added) as d, project_id, SUM(amount) as t FROM financials WHERE type='profit' AND status='approved' AND date_added >= DATE_SUB(CURDATE(), INTERVAL ? DAY) GROUP BY DATE(date_added), project_id"); $p_stmt->execute([$range_days]);
    while($r = $p_stmt->fetch()) { $pid = $r['project_id'] ? $r['project_id'] : 'general'; if(isset($profit_ds[$pid]['data'][$r['d']])) $profit_ds[$pid]['data'][$r['d']] = (float)$r['t']; }

    $e_stmt = $pdo->prepare("SELECT DATE(date_added) as d, project_id, SUM(amount) as t FROM financials WHERE type='expense' AND status='approved' AND date_added >= DATE_SUB(CURDATE(), INTERVAL ? DAY) GROUP BY DATE(date_added), project_id"); $e_stmt->execute([$range_days]);
    while($r = $e_stmt->fetch()) { $pid = $r['project_id'] ? $r['project_id'] : 'general'; if(isset($expense_ds[$pid]['data'][$r['d']])) $expense_ds[$pid]['data'][$r['d']] = (float)$r['t']; }

    $filtered_profit_ds = []; foreach($profit_ds as $ds) { if(array_sum($ds['data']) > 0) { $ds['data'] = array_values($ds['data']); $filtered_profit_ds[] = $ds; } }
    if(empty($filtered_profit_ds)) { $filtered_profit_ds[] = array_merge(['label' => 'No Data', 'data' => array_fill(0, $range_days, 0), 'borderColor' => '#cbd5e1', 'backgroundColor' => 'transparent'], $base_config); }

    $filtered_expense_ds = []; foreach($expense_ds as $ds) { if(array_sum($ds['data']) > 0) { $ds['data'] = array_values($ds['data']); $filtered_expense_ds[] = $ds; } }
    if(empty($filtered_expense_ds)) { $filtered_expense_ds[] = array_merge(['label' => 'No Data', 'data' => array_fill(0, $range_days, 0), 'borderColor' => '#cbd5e1', 'backgroundColor' => 'transparent'], $base_config); }

    $json_dates = json_encode(array_values($chart_dates));
    $json_profit_datasets = json_encode(array_values($filtered_profit_ds));
    $json_expense_datasets = json_encode(array_values($filtered_expense_ds));

    // Slots Data
    $slot_setting_stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_name = 'total_share_slots'");
    $total_slots = (int)($slot_setting_stmt->fetchColumn() ?: 100);

    $all_slots_stmt = $pdo->query("
        SELECT s.account_id, s.slot_numbers, s.investment_credit, s.number_of_shares, s.assigned_project_id, 
               COALESCE(a.name, s.name) as name, a.profile_picture, p.project_name 
        FROM shareholders s 
        LEFT JOIN shareholder_accounts a ON s.account_id = a.id 
        LEFT JOIN projects p ON s.assigned_project_id = p.id
        WHERE s.slot_numbers IS NOT NULL AND s.slot_numbers != ''
    ");
    
    $filled_slots = []; $slot_owners = []; 
    $slot_profits = []; $slot_investments = [];
    $slot_profile_pics = [];

    foreach($all_slots_stmt->fetchAll() as $row) {
        $slots = array_map('trim', explode(',', $row['slot_numbers']));
        $slot_count = count(array_filter($slots));
        if($slot_count == 0) continue;

        $entry_profit = 0;
        $entry_profit += ($global_profit_per_share * $row['number_of_shares']);
        
        if ($row['assigned_project_id']) {
            $p_id = $row['assigned_project_id'];
            if (isset($projects_data[$p_id])) {
                $proj = $projects_data[$p_id];
                if ($proj['net_distributable_profit'] > 0) {
                    if ($proj['dist_type'] == 'by_investment' && $proj['total_inv'] > 0) {
                        $entry_profit += ($proj['net_distributable_profit'] * ($row['investment_credit'] / $proj['total_inv']));
                    } elseif ($proj['dist_type'] == 'by_share' && $proj['total_shares'] > 0) {
                        $entry_profit += ($proj['net_distributable_profit'] * ($row['number_of_shares'] / $proj['total_shares']));
                    }
                }
            }
        }

        $profit_per_slot_for_this_entry = $entry_profit / $slot_count;
        $inv_per_slot = $row['investment_credit'] / $slot_count;
        $sh_name = $row['name'];

        foreach($slots as $slot) {
            if(empty($slot)) continue;
            $s_num = (int)$slot;
            $filled_slots[] = $s_num;
            $slot_owners[$s_num] = $sh_name;
            $slot_profile_pics[$s_num] = $row['profile_picture'] ?? null;
            
            if (!isset($slot_profits[$s_num])) { $slot_profits[$s_num] = 0; }
            if (!isset($slot_investments[$s_num])) { $slot_investments[$s_num] = 0; }
            
            $slot_profits[$s_num] += $profit_per_slot_for_this_entry;
            $slot_investments[$s_num] += $inv_per_slot;
            
            if($row['account_id'] == $user_account_id) { $my_slots[] = $s_num; } else { $other_slots[] = $s_num; }
        }
    }
    
    $my_slots = array_unique($my_slots ?? []); 
    $other_slots = array_unique($other_slots ?? []);
    $total_booked = count($my_slots) + count($other_slots);
    $booking_percentage = ($total_slots > 0) ? ($total_booked / $total_slots) * 100 : 0;

    $for_sale_slots = [];
    try {
        $for_sale_stmt = $pdo->query("SELECT slot_number, account_id FROM slot_sales");
        while($row = $for_sale_stmt->fetch()) { $for_sale_slots[$row['slot_number']] = $row['account_id']; }
    } catch(PDOException $e) {}

} catch (PDOException $e) {}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Dashboard — Sodai Lagbe ERP</title>
    
    <?php if(!empty($site_favicon)): ?>
        <link rel="icon" href="<?= htmlspecialchars($site_favicon) ?>">
    <?php else: ?>
        <link rel="icon" href="favicon.ico">
    <?php endif; ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Theme: apply saved preference before first paint to prevent flash -->
    <script>
        (function(){var t=localStorage.getItem('erpTheme')||'dark';document.documentElement.setAttribute('data-theme',t);})();
    </script>
    <link rel="stylesheet" href="theme.css">
    <style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
:root{
  --red:#e63946;--orange:#f4845f;--amber:#fbbf24;
  --emerald:#06d6a0;--blue:#4361ee;--indigo:#3a0ca3;--teal:#0d9488;
  --bg:#0d1117;--surface:#161b22;--surface2:#1c2128;--surface3:#21262d;
  --border:rgba(255,255,255,.08);--border2:rgba(255,255,255,.13);
  --text:#e6edf3;--muted:#8b949e;--muted2:#6e7681;
  --nav-h:64px;--bottom-nav-h:72px;
  --radius-sm:12px;--radius-md:18px;--radius-lg:24px;--radius-xl:28px;
}
body{font-family:'Sora',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;-webkit-font-smoothing:antialiased;-webkit-tap-highlight-color:transparent;overflow-x:hidden}
::-webkit-scrollbar{width:4px;height:4px}
::-webkit-scrollbar-thumb{background:rgba(255,255,255,.1);border-radius:99px}

/* NAV */
.erp-nav{position:fixed;top:0;left:0;right:0;z-index:100;height:var(--nav-h);padding:0 16px;display:flex;align-items:center;justify-content:space-between;background:rgba(13,17,23,.92);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border-bottom:1px solid var(--border);transition:box-shadow .3s}
.erp-nav.shadow{box-shadow:0 4px 30px rgba(0,0,0,.5)}
.nav-brand{display:flex;align-items:center;gap:10px;text-decoration:none}
.nav-brand-icon{width:38px;height:38px;border-radius:12px;background:linear-gradient(135deg,var(--red),var(--orange));display:flex;align-items:center;justify-content:center;color:#fff;font-size:16px;flex-shrink:0;box-shadow:0 4px 14px rgba(230,57,70,.4);overflow:hidden}
.nav-brand-icon img{width:100%;height:100%;object-fit:contain}
.nav-brand-text{font-size:15px;font-weight:800;color:var(--text);letter-spacing:-.3px;display:block}
.nav-brand-sub{font-size:9px;color:var(--muted);font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.15em;display:block}
.nav-right{display:flex;align-items:center;gap:8px}
.nav-links{display:none;align-items:center;gap:4px}
@media(min-width:768px){.nav-links{display:flex}}
.nav-link{font-size:12px;font-weight:600;color:var(--muted);text-decoration:none;padding:7px 14px;border-radius:10px;border:1px solid transparent;transition:all .2s}
.nav-link:hover{color:var(--text);background:var(--surface2);border-color:var(--border)}
.nav-link.active{color:var(--text);background:var(--surface2);border-color:var(--border2)}
.nav-user{display:none;flex-direction:column;text-align:right;padding-right:10px;border-right:1px solid var(--border)}
@media(min-width:640px){.nav-user{display:flex}}
.nav-user-name{font-size:13px;font-weight:700;color:var(--text)}
.nav-user-role{font-size:9px;color:var(--muted);font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.12em}
.nav-icon-btn{width:36px;height:36px;border-radius:10px;border:1px solid var(--border);background:var(--surface);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:14px;color:var(--muted);transition:all .2s;text-decoration:none;overflow:hidden}
.nav-icon-btn:hover{border-color:var(--border2);color:var(--text);background:var(--surface2)}
.nav-icon-btn.danger:hover{background:rgba(230,57,70,.1);border-color:rgba(230,57,70,.3);color:var(--red)}

/* BOTTOM NAV */
.erp-bottom-nav{display:flex;position:fixed;bottom:0;left:0;right:0;height:var(--bottom-nav-h);background:rgba(22,27,34,.97);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border-top:1px solid var(--border);padding-bottom:env(safe-area-inset-bottom);z-index:100;justify-content:space-around;align-items:stretch}
@media(min-width:768px){.erp-bottom-nav{display:none}}
.bnav-item{display:flex;flex-direction:column;align-items:center;justify-content:center;flex:1;gap:3px;text-decoration:none;color:var(--muted2);font-size:9px;font-weight:700;font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.06em;transition:color .2s;position:relative;border:none;background:none;cursor:pointer}
.bnav-item i{font-size:19px;transition:transform .2s}
.bnav-item.active{color:var(--red)}
.bnav-item.active i{transform:translateY(-2px)}
.bnav-item.kpi-active{color:var(--amber)}
.bnav-indicator{position:absolute;top:10px;width:28px;height:3px;border-radius:99px;background:var(--red);opacity:0;transition:opacity .2s}
.bnav-item.active .bnav-indicator{opacity:1}

/* PAGE */
.erp-page{max-width:900px;margin:0 auto;padding:calc(var(--nav-h) + 24px) 16px calc(var(--bottom-nav-h) + 20px)}
@media(min-width:768px){.erp-page{padding-bottom:36px;padding-left:24px;padding-right:24px}}

/* CARDS */
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;transition:border-color .25s,box-shadow .25s}
.card:hover{border-color:var(--border2);box-shadow:0 8px 30px rgba(0,0,0,.3)}
.card-p{padding:20px}
@media(min-width:640px){.card-p{padding:24px}}
.hero-card{background:linear-gradient(135deg,var(--red) 0%,#c0392b 100%);border:1px solid rgba(230,57,70,.4);border-radius:var(--radius-xl);padding:24px;position:relative;overflow:hidden;box-shadow:0 20px 50px rgba(230,57,70,.2);color:#fff}
.hero-card::before{content:'';position:absolute;top:-60%;right:-40%;width:280px;height:280px;background:radial-gradient(circle,rgba(255,255,255,.12),transparent 70%);pointer-events:none}
.hero-card-alt{background:linear-gradient(135deg,#f59e0b,var(--orange));border-color:rgba(245,158,11,.4);box-shadow:0 20px 50px rgba(245,158,11,.18)}
.gradient-card-advisor{background:linear-gradient(135deg,#f59e0b,var(--orange));border:1px solid rgba(245,158,11,.35);box-shadow:0 20px 50px rgba(245,158,11,.18);color:#fff}
.stat-card{background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius-md);padding:16px;transition:border-color .25s}
.stat-card:hover{border-color:var(--border2)}

/* BADGES */
.badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:999px;font-size:9px;font-weight:700;font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.1em}
.badge-red{background:rgba(230,57,70,.12);border:1px solid rgba(230,57,70,.3);color:#fca5a5}
.badge-emerald{background:rgba(6,214,160,.1);border:1px solid rgba(6,214,160,.25);color:#6ee7b7}
.badge-amber{background:rgba(251,191,36,.1);border:1px solid rgba(251,191,36,.25);color:#fde68a}
.badge-blue{background:rgba(67,97,238,.12);border:1px solid rgba(67,97,238,.3);color:#a5b4fc}
.badge-gray{background:rgba(255,255,255,.06);border:1px solid var(--border);color:var(--muted)}

/* SECTION */
.sec-label{font-family:'Space Mono',monospace;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.2em;color:var(--muted2);margin-bottom:8px;display:flex;align-items:center;gap:6px}
.sec-label::before{content:'';width:16px;height:2px;border-radius:2px;background:var(--red)}

/* ALERTS */
.alert{padding:13px 16px;border-radius:var(--radius-md);font-size:13px;font-weight:600;display:flex;align-items:flex-start;gap:10px;line-height:1.5;margin-bottom:16px;animation:fadeIn .35s ease}
.alert i{font-size:15px;flex-shrink:0;margin-top:1px}
.alert-ok{background:rgba(6,214,160,.08);border:1px solid rgba(6,214,160,.22);color:#6ee7b7}
.alert-ok i{color:var(--emerald)}
.alert-err{background:rgba(230,57,70,.08);border:1px solid rgba(230,57,70,.22);color:#fca5a5}
.alert-err i{color:var(--red)}
.alert-info{background:rgba(67,97,238,.08);border:1px solid rgba(67,97,238,.22);color:#a5b4fc}
.alert-info i{color:var(--blue)}

/* BUTTONS */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 20px;border-radius:var(--radius-sm);font-size:13px;font-weight:700;font-family:'Sora',sans-serif;cursor:pointer;border:none;text-decoration:none;transition:transform .18s,box-shadow .18s}
.btn:active{transform:scale(.97)}
.btn-red{background:linear-gradient(135deg,var(--red),var(--orange));color:#fff;box-shadow:0 6px 20px rgba(230,57,70,.35)}
.btn-blue{background:linear-gradient(135deg,var(--blue),var(--indigo));color:#fff;box-shadow:0 6px 20px rgba(67,97,238,.35)}
.btn-ghost{background:var(--surface2);border:1px solid var(--border);color:var(--muted)}
.btn-full{width:100%}

/* FORM */
.form-label{font-size:10px;font-weight:700;font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.12em;color:var(--muted);display:block;margin-bottom:7px}
.form-input{width:100%;background:rgba(0,0,0,.2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:11px 14px;color:var(--text);font-size:14px;font-family:'Sora',sans-serif;outline:none;transition:border-color .2s,box-shadow .2s}
.form-input::placeholder{color:rgba(255,255,255,.18)}
.form-input:focus{border-color:rgba(67,97,238,.5);box-shadow:0 0 0 4px rgba(67,97,238,.1)}

/* DIVIDER */
.divider{height:1px;background:var(--border);margin:20px 0}

/* MODAL */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);z-index:200;align-items:center;justify-content:center;padding:16px}

/* PROGRESS */
.prog-bar{height:6px;background:rgba(255,255,255,.06);border-radius:99px;overflow:hidden}
.prog-bar-fill{height:100%;border-radius:99px;transition:width 1s cubic-bezier(.16,1,.3,1)}
.prog-bar-emerald{background:linear-gradient(90deg,var(--emerald),var(--teal))}
.prog-bar-amber{background:linear-gradient(90deg,var(--amber),var(--orange))}

/* ANIMATIONS */
@keyframes fadeIn{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
.fade-in{animation:fadeIn .4s ease forwards}
.fade-in-1{animation:fadeIn .4s .1s ease both}
.fade-in-2{animation:fadeIn .4s .2s ease both}
.fade-in-3{animation:fadeIn .4s .3s ease both}
.fade-in-4{animation:fadeIn .4s .4s ease both}
@keyframes wave{0%{transform:rotate(0)}10%{transform:rotate(14deg)}20%{transform:rotate(-8deg)}30%{transform:rotate(14deg)}40%{transform:rotate(-4deg)}50%{transform:rotate(10deg)}60%{transform:rotate(0)}100%{transform:rotate(0)}}
.wave{display:inline-block;animation:wave 2.5s infinite;transform-origin:70% 70%}
.typewriter{overflow:hidden;white-space:nowrap;display:inline-block;animation:type 1.4s steps(30,end) forwards}
@keyframes type{from{width:0}to{width:100%}}
@keyframes modalIn{from{opacity:0;transform:scale(.94) translateY(10px)}to{opacity:1;transform:scale(1) translateY(0)}}
@keyframes gold-pulse{0%{box-shadow:0 0 0 0 rgba(245,158,11,.6)}70%{box-shadow:0 0 0 6px rgba(245,158,11,0)}100%{box-shadow:0 0 0 0 rgba(245,158,11,0)}}
@keyframes livepulse{0%,100%{opacity:1}50%{opacity:.25}}

/* CHART */
.chart-container{position:relative;height:200px;width:100%}
@media(min-width:640px){.chart-container{height:220px}}

/* SLOT */
@keyframes gold-pulse{0%{box-shadow:0 0 0 0 rgba(245,158,11,.6)}70%{box-shadow:0 0 0 6px rgba(245,158,11,0)}100%{box-shadow:0 0 0 0 rgba(245,158,11,0)}}

/* INDEX BANNER BUTTON */
.index-banner-btn{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:13px 18px;border-radius:var(--radius-md);background:var(--surface);border:1px solid var(--border);text-decoration:none;margin-bottom:20px;transition:border-color .2s,box-shadow .2s,background .2s}
.index-banner-btn:hover{border-color:var(--border2);box-shadow:0 4px 16px rgba(0,0,0,.25);background:var(--surface2)}
.index-banner-left{display:flex;align-items:center;gap:12px}
.index-banner-icon{width:38px;height:38px;border-radius:11px;background:rgba(67,97,238,.12);border:1px solid rgba(67,97,238,.25);color:#818cf8;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
.index-banner-title{font-size:13px;font-weight:700;color:var(--text);line-height:1.2}
.index-banner-sub{font-size:10px;color:var(--muted);font-family:'Space Mono',monospace;letter-spacing:.04em;margin-top:2px}
.index-banner-arrow{width:30px;height:30px;border-radius:8px;background:rgba(67,97,238,.1);color:#818cf8;display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0;transition:transform .2s}
.index-banner-btn:hover .index-banner-arrow{transform:translateX(3px)}
html[data-theme="light"] .index-banner-btn{background:#fff;border-color:rgba(0,0,0,.08)}
html[data-theme="light"] .index-banner-btn:hover{background:#f8fafc;box-shadow:0 4px 16px rgba(0,0,0,.08)}

/* PORTAL FAB */
.portal-fab{position:fixed;right:18px;bottom:calc(var(--bottom-nav-h) + 14px);z-index:99;display:flex;align-items:center;gap:0;background:var(--surface);border:1px solid var(--border2);border-radius:50px;box-shadow:0 6px 24px rgba(0,0,0,.3);cursor:pointer;text-decoration:none;overflow:hidden;transition:all .25s cubic-bezier(.34,1.56,.64,1);max-width:42px}
.portal-fab:hover{max-width:180px;box-shadow:0 8px 32px rgba(0,0,0,.35)}
.portal-fab-icon{width:42px;height:42px;border-radius:50px;background:linear-gradient(135deg,var(--blue),var(--indigo));display:flex;align-items:center;justify-content:center;color:#fff;font-size:14px;flex-shrink:0}
.portal-fab-label{font-size:11px;font-weight:700;color:var(--text);white-space:nowrap;padding:0 12px 0 6px;font-family:'Sora',sans-serif;letter-spacing:-.2px;opacity:0;transition:opacity .2s .05s}
.portal-fab:hover .portal-fab-label{opacity:1}
@media(min-width:768px){.portal-fab{bottom:24px}}

/* VIDEO PLAYER */
.vid-player-card{background:linear-gradient(145deg,#0d1117,#161b22);border:1px solid rgba(255,255,255,.07);border-radius:28px;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.55),0 0 0 1px rgba(255,255,255,.04);position:relative}
.vid-topbar{padding:15px 18px 13px;display:flex;justify-content:space-between;align-items:center;background:rgba(255,255,255,.015);border-bottom:1px solid rgba(255,255,255,.05)}
.vid-views-pill{display:flex;align-items:center;gap:5px;padding:5px 10px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.07);border-radius:20px}
.vid-reactions-bar{padding:12px 18px 14px;display:flex;align-items:center;gap:8px}
.vid-btn-neutral{border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.04);color:var(--muted)}
.vid-comments{border-top:1px solid rgba(255,255,255,.05);padding:16px 18px 18px;background:rgba(0,0,0,.15)}
.vid-comment-bubble{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);border-radius:14px;padding:9px 13px;flex:1}
.vid-comment-input{flex:1;padding:10px 14px;font-size:13px;border-radius:50px;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.04);color:var(--text);font-family:'Sora',sans-serif;outline:none;transition:border-color .2s}
.vid-comment-input:focus{border-color:rgba(67,97,238,.5)}
html[data-theme="light"] .vid-player-card{background:linear-gradient(145deg,#f1f5f9,#ffffff);border-color:rgba(0,0,0,.09);box-shadow:0 20px 50px rgba(0,0,0,.1),0 0 0 1px rgba(0,0,0,.04)}
html[data-theme="light"] .vid-topbar{background:rgba(0,0,0,.02);border-bottom-color:rgba(0,0,0,.07)}
html[data-theme="light"] .vid-views-pill{background:rgba(0,0,0,.05);border-color:rgba(0,0,0,.09)}
html[data-theme="light"] .vid-btn-neutral{border-color:rgba(0,0,0,.1);background:rgba(0,0,0,.04)}
html[data-theme="light"] .vid-comments{background:rgba(0,0,0,.03);border-top-color:rgba(0,0,0,.07)}
html[data-theme="light"] .vid-comment-bubble{background:rgba(0,0,0,.03);border-color:rgba(0,0,0,.07)}
html[data-theme="light"] .vid-comment-input{border-color:rgba(0,0,0,.1);background:rgba(0,0,0,.04)}
    </style>
</head>
<body>

    <!-- ══ TOP NAV ══ -->
    <nav class="erp-nav" id="erp-navbar">
        <a href="index.php" class="nav-brand">
            <div class="nav-brand-icon">
                <?php if(!empty($site_logo)): ?>
                    <img src="<?= htmlspecialchars($site_logo) ?>" alt="Logo">
                <?php else: ?>
                    <i class="fas fa-store-alt"></i>
                <?php endif; ?>
            </div>
            <div>
                <div class="nav-brand-text">Sodai Lagbe</div>
                <div class="nav-brand-sub">ERP Portal</div>
            </div>
        </a>
        <div class="nav-right">
            <div class="nav-links">
                <a href="index.php" class="nav-link active">ড্যাশবোর্ড</a>
                <a href="transactions.php" class="nav-link">লেনদেন</a>
                <?php if($can_vote): ?><a href="user_votes.php" class="nav-link">ভোটিং</a><?php endif; ?>
                <?php if($my_advisor_role): ?><a href="user_kpi.php" class="nav-link" style="color:var(--amber)">KPI</a><?php endif; ?>
            </div>
            <div class="nav-user">
                <div class="nav-user-name" style="cursor:pointer" onclick="openProfileModal()"><?= htmlspecialchars($user_name) ?></div>
                <div class="nav-user-role">Shareholder</div>
            </div>
            <button onclick="toggleTheme()" class="nav-icon-btn theme-toggle-btn" title="Dark Mode এ যান" aria-label="Toggle theme">
                <i class="fas fa-moon"></i>
            </button>
            <button onclick="openProfileModal()" class="nav-icon-btn" title="Profile">
                <?php if(!empty($current_user_data['profile_picture'])): ?>
                    <img src="<?= htmlspecialchars($current_user_data['profile_picture']) ?>" alt="Profile" style="width:100%;height:100%;object-fit:cover">
                <?php else: ?>
                    <i class="fas fa-user"></i>
                <?php endif; ?>
            </button>
            <a href="logout.php" class="nav-icon-btn danger" title="Logout"><i class="fas fa-power-off"></i></a>
        </div>
    </nav>

    <!-- ══ BOTTOM NAV ══ -->
    <nav class="erp-bottom-nav">
        <a href="index.php" class="bnav-item active">
            <div class="bnav-indicator"></div>
            <i class="fas fa-home"></i><span>Home</span>
        </a>
        <a href="transactions.php" class="bnav-item">
            <i class="fas fa-exchange-alt"></i><span>Trans</span>
        </a>
        <?php if($can_vote): ?>
        <a href="user_votes.php" class="bnav-item">
            <i class="fas fa-poll"></i><span>Vote</span>
        </a>
        <?php endif; ?>
        <?php if($my_advisor_role): ?>
        <a href="user_kpi.php" class="bnav-item kpi-active">
            <i class="fas fa-bullseye"></i><span>KPI</span>
        </a>
        <?php endif; ?>
        <button onclick="openProfileModal()" class="bnav-item">
            <i class="fas fa-user-circle"></i><span>Profile</span>
        </button>
    </nav>

    <main class="erp-page">


        <?php if($message): ?>
            <div class="alert alert-ok fade-in"><i class="fas fa-check-circle"></i><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if($error): ?>
            <div class="alert alert-err fade-in"><i class="fas fa-exclamation-triangle"></i><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Greeting -->
        <div class="fade-in" style="margin-bottom:24px">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
                <h2 style="font-size:clamp(20px,5vw,26px);font-weight:800;letter-spacing:-.5px;color:var(--text)">
                    <span class="typewriter"><?= $greeting ?>, <?= htmlspecialchars($user_name) ?></span>
                    <span class="wave">👋</span>
                </h2>
            </div>
            <p style="font-size:11px;color:var(--muted);font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.15em"><?= date('l, d F Y') ?></p>
        </div>

        <!-- Hero Profit Card -->
        <div class="hero-card fade-in-1" style="margin-bottom:16px">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:6px">
                <div style="display:flex;align-items:center;gap:8px">
                    <i class="fas fa-wallet" style="font-size:13px;opacity:.7"></i>
                    <span style="font-size:10px;font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.15em;opacity:.8">মোট অর্জিত লাভ</span>
                </div>
                <div class="badge" style="background:rgba(255,255,255,.15);border-color:rgba(255,255,255,.2);color:#fff">Main Balance</div>
            </div>
            <div style="font-size:clamp(32px,8vw,46px);font-weight:800;letter-spacing:-1px;margin-bottom:18px;position:relative;z-index:1">৳ <?= number_format($personal_total_profit, 2) ?></div>
            
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
                <div style="background:rgba(0,0,0,.2);border-radius:14px;padding:12px 14px;border:1px solid rgba(255,255,255,.08)">
                    <div style="font-size:9px;font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.12em;opacity:.7;margin-bottom:4px">মালিকানা</div>
                    <div style="font-size:18px;font-weight:800"><?= number_format($personal_global_percentage, 2) ?>%</div>
                </div>
                <div style="background:rgba(0,0,0,.2);border-radius:14px;padding:12px 14px;border:1px solid rgba(255,255,255,.08)">
                    <div style="font-size:9px;font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.12em;opacity:.7;margin-bottom:4px">মোট বিনিয়োগ</div>
                    <div style="font-size:18px;font-weight:800">৳ <?= number_format($personal_total_investment, 0) ?></div>
                </div>
            </div>
            
            <div style="background:rgba(0,0,0,.25);border-radius:14px;padding:14px;border:1px solid rgba(255,255,255,.08)">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                    <div style="display:flex;align-items:center;gap:6px;font-size:10px;font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.1em;opacity:.8">
                        <i class="fas fa-bullseye" style="color:var(--amber)"></i> প্রফিট টার্গেট
                    </div>
                    <span style="font-size:12px;font-weight:800;color:var(--amber)">৳ <?= number_format($personal_target_profit, 0) ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                    <span style="font-size:11px;opacity:.7">স্থায়ী খরচ তুলতে আর প্রয়োজন</span>
                    <span style="font-size:12px;font-weight:800;color:<?= $personal_recovery_gap <= 0 ? '#6ee7b7' : '#fca5a5' ?>">
                        <?= $personal_recovery_gap <= 0 ? '✓ কমপ্লিট' : '৳ ' . number_format($personal_recovery_gap, 0) ?>
                    </span>
                </div>
                <div class="prog-bar">
                    <div class="prog-bar-fill <?= $personal_recovery_pct >= 100 ? 'prog-bar-emerald' : 'prog-bar-amber' ?>" style="width:<?= min($personal_recovery_pct, 100) ?>%"></div>
                </div>
            </div>
        </div>

        <!-- Incentive Bonus Card -->
        <?php if($personal_incentive_bonus > 0): ?>
        <div class="hero-card hero-card-alt fade-in-2" style="margin-bottom:16px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                <div style="display:flex;align-items:center;gap:6px;font-size:10px;font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.12em;opacity:.85">
                    <i class="fas fa-gift" style="animation:wave 2s infinite;transform-origin:center"></i> ইন্সেন্টিভ বোনাস
                </div>
                <div class="badge" style="background:rgba(255,255,255,.15);border-color:rgba(255,255,255,.2);color:#fff">Mother Project</div>
            </div>
            <div style="font-size:clamp(26px,6vw,36px);font-weight:800;letter-spacing:-.5px;margin-bottom:8px">+৳ <?= number_format($personal_incentive_bonus, 2) ?></div>
            <p style="font-size:12px;opacity:.85;line-height:1.65">চাইল্ড প্রজেক্টগুলো থেকে আপনার মাদার প্রজেক্টের মালিকানা (<?= number_format($user_projects_summary[$mother_project_id]['percent'] ?? 0, 2) ?>%) অনুযায়ী প্রাপ্ত কমিশন।</p>
        </div>
        <?php endif; ?>

        <!-- Video & Reactions Section -->
        <?php if(!empty($dashboard_video)): ?>
        <div class="fade-in-4" style="margin-bottom:20px">
            <div class="vid-player-card">

                <!-- Top bar -->
                <div class="vid-topbar">
                    <div style="display:flex;align-items:center;gap:10px">
                        <div style="width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,var(--red),var(--orange));display:flex;align-items:center;justify-content:center;box-shadow:0 4px 12px rgba(230,57,70,.4);flex-shrink:0">
                            <i class="fas fa-play" style="color:#fff;font-size:11px;margin-left:1px"></i>
                        </div>
                        <div>
                            <div style="font-size:13px;font-weight:700;color:var(--text);letter-spacing:-.2px">কোম্পানি আপডেট</div>
                            <div style="font-size:10px;color:var(--muted2);margin-top:1px;font-family:'Space Mono',monospace;display:flex;align-items:center;gap:5px"><span style="width:6px;height:6px;border-radius:50%;background:#06d6a0;display:inline-block;box-shadow:0 0 6px rgba(6,214,160,.7);animation:livepulse 2s infinite"></span>LIVE UPDATE</div>
                        </div>
                    </div>
                    <div class="vid-views-pill">
                        <i class="fas fa-eye" style="font-size:10px;color:var(--muted2)"></i>
                        <span style="font-size:10px;color:var(--muted);font-family:'Space Mono',monospace;font-weight:700"><?= number_format($view_count) ?></span>
                    </div>
                </div>

                <!-- Video Wrapper -->
                <div style="background:#000;margin:12px;border-radius:18px;overflow:hidden;box-shadow:0 8px 32px rgba(0,0,0,.7)">
                    <div style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden">
                        <?php if(filter_var($dashboard_video, FILTER_VALIDATE_URL)): ?>
                            <?php
                            $vid_embed='';
                            if(preg_match('/youtu\.be\/([^\?]+)|youtube\.com\/watch\?v=([^&]+)/',$dashboard_video,$m)){$vid_id=$m[1]?:$m[2];$vid_embed="https://www.youtube.com/embed/{$vid_id}?autoplay=1&rel=0&modestbranding=1&color=white";}
                            elseif(strpos($dashboard_video,'facebook.com')!==false){$vid_embed="https://www.facebook.com/plugins/video.php?href=".urlencode($dashboard_video)."&autoplay=1&show_text=0&width=800";}
                            ?>
                            <?php if($vid_embed): ?>
                            <iframe src="<?= htmlspecialchars($vid_embed) ?>" style="position:absolute;top:0;left:0;width:100%;height:100%;border:none" allowfullscreen allow="autoplay;encrypted-media;picture-in-picture"></iframe>
                            <?php else: ?>
                            <video id="dashVid" src="<?= htmlspecialchars($video_src) ?>" autoplay muted playsinline preload="metadata" controls style="position:absolute;top:0;left:0;width:100%;height:100%;object-fit:contain"></video>
                            <?php endif; ?>
                        <?php else: ?>
                        <video id="dashVid" src="<?= htmlspecialchars($video_src) ?>" autoplay muted playsinline preload="metadata" controls style="position:absolute;top:0;left:0;width:100%;height:100%;object-fit:contain"></video>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Reactions Bar -->
                <div class="vid-reactions-bar">
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="action" value="react">
                        <input type="hidden" name="reaction_type" value="like">
                        <button type="submit" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:50px;border:1px solid <?= $my_reaction==='like'?'rgba(67,97,238,.45)':'transparent' ?>;background:<?= $my_reaction==='like'?'rgba(67,97,238,.15)':'transparent' ?>;color:<?= $my_reaction==='like'?'#a5b4fc':'var(--muted)' ?>;font-size:12px;font-weight:700;cursor:pointer;font-family:'Sora',sans-serif;transition:all .2s;letter-spacing:.01em<?= $my_reaction!=='like'?';' : '' ?><?= $my_reaction!=='like'?' ' : '' ?>" class="<?= $my_reaction!=='like'?'vid-btn-neutral':'' ?>">
                            <i class="fas fa-thumbs-up" style="font-size:11px"></i><?= $reaction_counts['like'] ?>
                        </button>
                    </form>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="action" value="react">
                        <input type="hidden" name="reaction_type" value="love">
                        <button type="submit" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:50px;border:1px solid <?= $my_reaction==='love'?'rgba(230,57,70,.45)':'transparent' ?>;background:<?= $my_reaction==='love'?'rgba(230,57,70,.12)':'transparent' ?>;color:<?= $my_reaction==='love'?'#fca5a5':'var(--muted)' ?>;font-size:12px;font-weight:700;cursor:pointer;font-family:'Sora',sans-serif;transition:all .2s;letter-spacing:.01em" class="<?= $my_reaction!=='love'?'vid-btn-neutral':'' ?>">
                            <i class="fas fa-heart" style="font-size:11px"></i><?= $reaction_counts['love'] ?>
                        </button>
                    </form>
                    <button onclick="const c=document.getElementById('cmtSec');c.style.display=c.style.display==='none'?'block':'none'" class="vid-btn-neutral" style="margin-left:auto;display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:50px;font-size:12px;font-weight:700;cursor:pointer;font-family:'Sora',sans-serif;transition:all .2s;letter-spacing:.01em;color:var(--muted)">
                        <i class="fas fa-comment-alt" style="font-size:11px"></i><?= count($comments) ?>
                    </button>
                </div>

                <!-- Comments Section -->
                <div id="cmtSec" class="vid-comments" style="display:<?= $show_comments?'block':'none' ?>">
                    <?php foreach($comments as $c): ?>
                    <div style="display:flex;align-items:flex-start;gap:10px;margin-bottom:12px">
                        <div style="width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,var(--red),var(--orange));display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:800;color:#fff;flex-shrink:0;overflow:hidden;box-shadow:0 2px 8px rgba(230,57,70,.3)">
                            <?php if(!empty($c['profile_picture'])): ?><img src="<?= htmlspecialchars($c['profile_picture']) ?>" style="width:100%;height:100%;object-fit:cover"><?php else: ?><?= strtoupper(substr($c['name'],0,1)) ?><?php endif; ?>
                        </div>
                        <div class="vid-comment-bubble">
                            <div style="font-size:11px;font-weight:700;color:var(--text);margin-bottom:3px"><?= htmlspecialchars($c['name']) ?></div>
                            <div style="font-size:13px;color:var(--muted);line-height:1.6"><?= nl2br(htmlspecialchars($c['comment_text'])) ?></div>
                        </div>
                    </div>
                    <?php endforeach; if(empty($comments)): ?>
                    <div style="text-align:center;padding:14px;color:var(--muted2);font-size:12px">কোনো মতামত নেই।</div>
                    <?php endif; ?>
                    <form method="POST" style="display:flex;gap:8px;margin-top:10px">
                        <input type="hidden" name="action" value="comment">
                        <input type="text" name="comment_text" class="vid-comment-input" placeholder="মতামত লিখুন..." required>
                        <button type="submit" style="width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,var(--red),var(--orange));border:none;color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 14px rgba(230,57,70,.35);flex-shrink:0;transition:transform .15s" onmouseover="this.style.transform='scale(1.08)'" onmouseout="this.style.transform='scale(1)'"><i class="fas fa-paper-plane" style="font-size:12px;margin-left:1px"></i></button>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Advisor KPI Card -->
        <?php if($my_advisor_role): ?>
        <div class="gradient-card-advisor card fade-in-2" style="padding:20px;margin-bottom:16px;cursor:pointer;display:flex;align-items:center;justify-content:space-between" onclick="window.location.href='user_kpi.php'">
            <div>
                <div class="badge" style="background:rgba(255,255,255,.18);border-color:rgba(255,255,255,.25);color:#fff;margin-bottom:8px">Assigned Role</div>
                <div style="font-size:17px;font-weight:800;color:#fff"><?= htmlspecialchars($my_advisor_role) ?></div>
                <p style="font-size:12px;color:rgba(255,255,255,.75);margin-top:4px">নতুন টার্গেট ও KPI রিপোর্ট দেখুন</p>
            </div>
            <div style="width:38px;height:38px;background:rgba(255,255,255,.18);border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:transform .2s" onmouseover="this.style.transform='scale(1.1)'" onmouseout="this.style.transform='scale(1)'">
                <i class="fas fa-chevron-right" style="color:#fff;font-size:13px"></i>
            </div>
        </div>
        <?php endif; ?>

        <!-- Projects -->
        <div class="fade-in-2" style="margin-bottom:24px">
            <div class="sec-label">Running Projects</div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px">
                <?php if(count($user_projects_summary) > 0): ?>
                    <?php foreach($user_projects_summary as $pid => $up): 
                        $is_mother_card = ($up['id'] == $mother_project_id);
                    ?>
                    <div class="card card-p" style="<?= $is_mother_card ? 'border-color:rgba(245,158,11,.3);background:rgba(245,158,11,.03)' : '' ?>">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px">
                            <div style="font-size:14px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:7px">
                                <?= htmlspecialchars($up['name']) ?>
                                <?php if($is_mother_card): ?><i class="fas fa-crown" style="color:var(--amber);font-size:11px"></i><?php endif; ?>
                            </div>
                            <div class="badge badge-gray"><?= $up['dist_type'] == 'by_investment' ? 'Amount' : 'Share' ?></div>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px">
                            <div style="background:var(--surface2);border-radius:12px;padding:12px">
                                <div style="font-size:9px;font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.1em;color:var(--muted2);margin-bottom:4px">বিনিয়োগ</div>
                                <div style="font-size:15px;font-weight:800">৳ <?= number_format($up['investment'], 0) ?></div>
                            </div>
                            <div style="background:var(--surface2);border-radius:12px;padding:12px">
                                <div style="font-size:9px;font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.1em;color:var(--muted2);margin-bottom:4px">মালিকানা</div>
                                <div style="font-size:15px;font-weight:800;color:var(--blue)"><?= number_format($up['percent'], 2) ?>%</div>
                            </div>
                        </div>
                        <div style="background:rgba(6,214,160,.08);border:1px solid rgba(6,214,160,.2);border-radius:12px;padding:12px;display:flex;justify-content:space-between;align-items:center">
                            <span style="font-size:10px;font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.1em;color:var(--emerald)">অর্জিত লাভ</span>
                            <span style="font-size:15px;font-weight:800;color:var(--emerald)">৳ <?= number_format($up['profit'], 2) ?></span>
                        </div>
                        <?php if($up['incentive'] > 0.01): ?>
                        <div style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);border-radius:12px;padding:10px;display:flex;justify-content:space-between;align-items:center;margin-top:8px">
                            <span style="font-size:10px;font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.08em;color:var(--amber)"><i class="fas fa-gift" style="margin-right:5px"></i>ইনসেনটিভ</span>
                            <span style="font-size:13px;font-weight:800;color:var(--amber)">+৳ <?= number_format($up['incentive'], 2) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="card card-p" style="text-align:center;border-style:dashed;color:var(--muted)">
                        <i class="fas fa-briefcase" style="font-size:28px;opacity:.3;display:block;margin-bottom:10px"></i>
                        আপনি এখনো কোনো প্রজেক্টে যুক্ত হননি।
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Charts -->
        <div class="fade-in-3" style="margin-bottom:24px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                <div class="sec-label" style="margin-bottom:0">আর্থিক অ্যানালাইসিস</div>
                <form method="GET">
                    <select name="range" onchange="this.form.submit()" class="form-input" style="padding:6px 12px;font-size:11px;font-family:'Space Mono',monospace;width:auto">
                        <option value="15" <?= $range_days == 15 ? 'selected' : '' ?>>15 Days</option>
                        <option value="30" <?= $range_days == 30 ? 'selected' : '' ?>>30 Days</option>
                        <option value="90" <?= $range_days == 90 ? 'selected' : '' ?>>90 Days</option>
                    </select>
                </form>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px">
                <div class="card card-p">
                    <div style="font-size:10px;font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.12em;color:var(--emerald);display:flex;align-items:center;gap:7px;margin-bottom:14px">
                        <div style="width:6px;height:6px;border-radius:50%;background:var(--emerald)"></div>প্রজেক্টভিত্তিক দৈনিক লাভ
                    </div>
                    <div class="chart-container"><canvas id="profitChart"></canvas></div>
                </div>
                <div class="card card-p">
                    <div style="font-size:10px;font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.12em;color:#fca5a5;display:flex;align-items:center;gap:7px;margin-bottom:14px">
                        <div style="width:6px;height:6px;border-radius:50%;background:#fca5a5"></div>প্রজেক্টভিত্তিক দৈনিক খরচ
                    </div>
                    <div class="chart-container"><canvas id="expenseChart"></canvas></div>
                </div>
            </div>
        </div>

        <!-- Slot Board -->
        <div class="card fade-in-4" style="margin-bottom:24px">
            <div style="padding:18px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;cursor:pointer" onclick="toggleSlotBoard()">
                <div class="sec-label" style="margin-bottom:0"><i class="fas fa-th-large" style="margin-right:6px"></i>শেয়ার স্লট বোর্ড</div>
                <div style="display:flex;align-items:center;gap:8px">
                    <div class="badge badge-blue"><?= number_format($booking_percentage, 0) ?>% Booked</div>
                    <i id="slotIcon" class="fas fa-chevron-down" style="color:var(--muted);font-size:13px;transition:transform .3s"></i>
                </div>
            </div>
            
            <div id="slotBoardContent" style="display:none;padding:20px">
                <div class="prog-bar" style="margin-bottom:16px">
                    <div class="prog-bar-fill" style="background:linear-gradient(90deg,var(--blue),var(--indigo));width:<?= ($total_slots > 0) ? (count($filled_slots)/$total_slots)*100 : 0 ?>%"></div>
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px;font-size:10px;font-family:'Space Mono',monospace">
                    <div style="background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:5px 10px;display:flex;align-items:center;gap:6px"><div style="width:7px;height:7px;border-radius:50%;background:linear-gradient(135deg,var(--blue),var(--indigo))"></div>আপনার (<?= count($my_slots) ?>)</div>
                    <div style="background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:5px 10px;display:flex;align-items:center;gap:6px"><div style="width:7px;height:7px;border-radius:50%;background:var(--muted)"></div>অন্যান্য (<?= count($other_slots) ?>)</div>
                    <div style="background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:5px 10px;display:flex;align-items:center;gap:6px"><div style="width:7px;height:7px;border-radius:50%;background:var(--amber)"></div>বিক্রির জন্য (<?= count($for_sale_slots) ?>)</div>
                    <div style="background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:5px 10px;display:flex;align-items:center;gap:6px"><div style="width:7px;height:7px;border-radius:50%;background:var(--surface3);border:1px solid var(--border)"></div>ফাঁকা (<?= $total_slots - count($filled_slots) ?>)</div>
                </div>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(82px,1fr));gap:8px;max-height:480px;overflow-y:auto">
                    <?php for($i=1; $i<=$total_slots; $i++):
                        $is_mine    = in_array($i, $my_slots);
                        $is_others  = in_array($i, $other_slots);
                        $is_for_sale = isset($for_sale_slots[$i]);
                        $owner_name = $slot_owners[$i] ?? '';
                        $slot_profit_val = $slot_profits[$i] ?? 0;
                        $profile_pic = $slot_profile_pics[$i] ?? null;
                    ?>
                        <?php if($is_for_sale): ?>
                        <div onclick="<?= $is_mine ? "if(confirm('সেল পোস্ট বাতিল করবেন?')){ const f=document.createElement('form');f.method='POST';f.innerHTML='<input type=\\'hidden\\' name=\\'action\\' value=\\'cancel_sell_slot\\'><input type=\\'hidden\\' name=\\'sell_slot_number\\' value=\\'$i\\'>';document.body.appendChild(f);f.submit();}" : '' ?>" style="background:linear-gradient(135deg,#f59e0b,var(--orange));border-radius:12px;padding:10px 6px;display:flex;flex-direction:column;align-items:center;gap:5px;cursor:pointer;box-shadow:0 4px 14px rgba(245,158,11,.35);animation:gold-pulse 2s infinite;position:relative">
                            <span style="position:absolute;top:5px;right:6px;font-size:8px;font-family:'Space Mono',monospace;color:rgba(255,255,255,.7)">#<?= $i ?></span>
                            <div style="width:34px;height:34px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;overflow:hidden"><?php if($profile_pic): ?><img src="<?= htmlspecialchars($profile_pic) ?>" style="width:100%;height:100%;object-fit:cover"><?php else: ?><i class="fas fa-bullhorn" style="font-size:13px;color:#fff"></i><?php endif; ?></div>
                            <div style="font-size:9px;font-weight:700;color:#fff;text-align:center;width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding:0 4px"><?= htmlspecialchars($owner_name) ?></div>
                            <div style="font-size:8px;color:rgba(255,255,255,.85);font-family:'Space Mono',monospace">+৳<?= number_format($slot_profit_val,0) ?></div>
                            <div style="font-size:7px;background:rgba(220,38,38,.7);color:#fff;padding:1px 6px;border-radius:4px;font-weight:700;font-family:'Space Mono',monospace">SALE</div>
                        </div>
                        <?php elseif($is_mine): ?>
                        <div onclick="openSellModal(<?= $i ?>)" style="background:linear-gradient(135deg,var(--blue),var(--indigo));border-radius:12px;padding:10px 6px;display:flex;flex-direction:column;align-items:center;gap:5px;cursor:pointer;box-shadow:0 4px 14px rgba(67,97,238,.35);position:relative;transition:transform .2s" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='none'">
                            <span style="position:absolute;top:5px;right:6px;font-size:8px;font-family:'Space Mono',monospace;color:rgba(255,255,255,.55)">#<?= $i ?></span>
                            <div style="width:34px;height:34px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;overflow:hidden"><?php if($profile_pic): ?><img src="<?= htmlspecialchars($profile_pic) ?>" style="width:100%;height:100%;object-fit:cover"><?php else: ?><i class="fas fa-user" style="font-size:13px;color:#fff"></i><?php endif; ?></div>
                            <div style="font-size:9px;font-weight:700;color:#fff;text-align:center;width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding:0 4px"><?= htmlspecialchars($owner_name) ?></div>
                            <div style="font-size:8px;color:rgba(255,255,255,.85);font-family:'Space Mono',monospace">+৳<?= number_format($slot_profit_val,0) ?></div>
                        </div>
                        <?php elseif($is_others): ?>
                        <div style="background:var(--surface2);border:1px solid var(--border);border-radius:12px;padding:10px 6px;display:flex;flex-direction:column;align-items:center;gap:5px;position:relative">
                            <span style="position:absolute;top:5px;right:6px;font-size:8px;font-family:'Space Mono',monospace;color:var(--muted2)">#<?= $i ?></span>
                            <div style="width:34px;height:34px;border-radius:50%;background:var(--surface3);display:flex;align-items:center;justify-content:center;overflow:hidden"><?php if($profile_pic): ?><img src="<?= htmlspecialchars($profile_pic) ?>" style="width:100%;height:100%;object-fit:cover"><?php else: ?><i class="fas fa-user-tie" style="font-size:13px;color:var(--muted2)"></i><?php endif; ?></div>
                            <div style="font-size:9px;font-weight:600;color:var(--muted);text-align:center;width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding:0 4px"><?= htmlspecialchars($owner_name) ?></div>
                            <div style="font-size:8px;color:var(--muted2);font-family:'Space Mono',monospace">+৳<?= number_format($slot_profit_val,0) ?></div>
                        </div>
                        <?php else: ?>
                        <div style="background:var(--surface2);border:2px dashed rgba(255,255,255,.06);border-radius:12px;padding:10px 6px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:5px;min-height:88px;position:relative;opacity:.45">
                            <span style="position:absolute;top:5px;right:6px;font-size:8px;font-family:'Space Mono',monospace;color:var(--muted2)">#<?= $i ?></span>
                            <i class="fas fa-plus" style="font-size:14px;color:var(--muted2)"></i>
                            <div style="font-size:8px;color:var(--muted2);font-family:'Space Mono',monospace;text-transform:uppercase">Free</div>
                        </div>
                        <?php endif; ?>
                    <?php endfor; ?>
                </div>
            </div>
        </div>

    </main>

    <!-- ══ PORTAL HOME FAB ══ -->
    <a href="index.php?view=1" class="portal-fab" title="পোর্টাল হোম পেজ">
        <div class="portal-fab-icon"><i class="fas fa-globe"></i></div>
        <span class="portal-fab-label">পোর্টাল হোম</span>
    </a>

    <!-- ══ SELL MODAL ══ -->
    <div id="sellModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);backdrop-filter:blur(8px);z-index:300;align-items:center;justify-content:center;padding:20px">
        <div style="background:var(--surface);border:1px solid var(--border2);border-radius:24px;padding:28px 24px;width:100%;max-width:400px;text-align:center;animation:modalIn .3s cubic-bezier(.16,1,.3,1)">
            <div style="width:56px;height:56px;border-radius:50%;background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.3);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:22px;color:var(--amber)"><i class="fas fa-bullhorn"></i></div>
            <div style="font-size:18px;font-weight:800;margin-bottom:8px">সেল পোস্ট</div>
            <p style="font-size:13px;color:var(--muted);margin-bottom:20px;line-height:1.65">স্লট <strong id="display_slot_number" style="color:var(--amber)"></strong> বিক্রির জন্য পোস্ট করবেন?</p>
            <form method="POST">
                <input type="hidden" name="action" value="sell_slot">
                <input type="hidden" name="sell_slot_number" id="modal_sell_slot_number">
                <div style="display:flex;gap:10px">
                    <button type="button" onclick="closeSellModal()" style="flex:1;padding:12px;border-radius:12px;background:var(--surface2);border:1px solid var(--border);color:var(--muted);font-size:13px;font-weight:700;cursor:pointer;font-family:'Sora',sans-serif">বাতিল</button>
                    <button type="submit" style="flex:1;padding:12px;border-radius:12px;background:linear-gradient(135deg,var(--amber),var(--orange));border:none;color:#fff;font-size:13px;font-weight:700;cursor:pointer;font-family:'Sora',sans-serif;box-shadow:0 6px 18px rgba(245,158,11,.3)">পোস্ট করুন</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ══ PROFILE MODAL ══ -->
    <div id="profileModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);backdrop-filter:blur(8px);z-index:300;align-items:center;justify-content:center;padding:16px">
        <div style="background:var(--surface);border:1px solid var(--border2);border-radius:24px;width:100%;max-width:460px;max-height:90vh;overflow:hidden;display:flex;flex-direction:column;animation:modalIn .3s cubic-bezier(.16,1,.3,1)">
            <div style="padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;flex-shrink:0">
                <div style="font-size:15px;font-weight:800;display:flex;align-items:center;gap:8px"><i class="fas fa-user-cog" style="color:var(--blue)"></i>প্রোফাইল ও সেটিংস</div>
                <button onclick="closeProfileModal()" style="width:30px;height:30px;border-radius:8px;background:var(--surface2);border:1px solid var(--border);color:var(--muted);cursor:pointer;font-size:13px;transition:all .2s;display:flex;align-items:center;justify-content:center" onmouseover="this.style.color='var(--text)'" onmouseout="this.style.color='var(--muted)'"><i class="fas fa-times"></i></button>
            </div>
            <div style="padding:22px;overflow-y:auto;flex:1">
                <!-- Profile pic + info -->
                <div style="font-size:11px;font-weight:700;color:var(--muted);font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.12em;margin-bottom:14px;display:flex;align-items:center;gap:6px"><i class="fas fa-id-badge" style="color:var(--blue)"></i>ব্যক্তিগত তথ্য</div>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_profile">
                    <div style="display:flex;align-items:center;gap:14px;margin-bottom:18px">
                        <div style="position:relative;width:64px;height:64px;border-radius:16px;overflow:hidden;background:var(--surface2);border:1px solid var(--border);flex-shrink:0;cursor:pointer" onclick="document.getElementById('picInput').click()">
                            <?php if(!empty($current_user_data['profile_picture'])): ?>
                                <img src="<?= htmlspecialchars($current_user_data['profile_picture']) ?>" id="profilePreview" style="width:100%;height:100%;object-fit:cover">
                            <?php else: ?>
                                <i class="fas fa-user" id="profilePreviewIcon" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);font-size:22px;color:var(--muted)"></i>
                                <img id="profilePreview" style="display:none;width:100%;height:100%;object-fit:cover">
                            <?php endif; ?>
                            <div id="picHover" style="position:absolute;inset:0;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .2s"><i class="fas fa-camera" style="color:#fff;font-size:16px"></i></div>
                        </div>
                        <input type="file" name="profile_picture" id="picInput" accept="image/*" style="display:none" onchange="previewImage(this)">
                        <div style="font-size:12px;color:var(--muted);line-height:1.7">ছবি পরিবর্তন করতে ক্লিক করুন<br><span style="font-size:10px;font-family:'Space Mono',monospace">JPG, PNG সমর্থিত</span></div>
                    </div>
                    <div style="display:grid;gap:12px;margin-bottom:16px">
                        <div><label class="form-label">পূর্ণ নাম</label><input type="text" name="name" value="<?= htmlspecialchars($current_user_data['name']) ?>" class="form-input" required></div>
                        <div><label class="form-label">ইউজারনেম</label><input type="text" name="username" value="<?= htmlspecialchars($current_user_data['username']) ?>" class="form-input" required></div>
                        <div><label class="form-label">মোবাইল নম্বর</label><input type="text" name="phone" value="<?= htmlspecialchars($current_user_data['phone'] ?? '') ?>" class="form-input" placeholder="01XXXXXXXXX"></div>
                    </div>
                    <button type="submit" style="width:100%;padding:12px;border-radius:12px;background:linear-gradient(135deg,var(--blue),var(--indigo));border:none;color:#fff;font-size:13px;font-weight:700;cursor:pointer;font-family:'Sora',sans-serif;box-shadow:0 6px 18px rgba(67,97,238,.3);display:flex;align-items:center;justify-content:center;gap:8px"><i class="fas fa-save"></i>আপডেট করুন</button>
                </form>
                <div style="height:1px;background:var(--border);margin:20px 0"></div>
                <!-- Password change -->
                <div style="font-size:11px;font-weight:700;color:var(--muted);font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.12em;margin-bottom:14px;display:flex;align-items:center;gap:6px"><i class="fas fa-lock" style="color:var(--red)"></i>পাসওয়ার্ড পরিবর্তন</div>
                <form method="POST">
                    <input type="hidden" name="action" value="request_password_change">
                    <div style="display:grid;gap:12px;margin-bottom:16px">
                        <div><label class="form-label">পুরাতন পাসওয়ার্ড</label><input type="password" name="old_password" class="form-input" placeholder="••••••••" required></div>
                        <div><label class="form-label">নতুন পাসওয়ার্ড</label><input type="password" name="new_password" class="form-input" placeholder="••••••••" required></div>
                    </div>
                    <button type="submit" style="width:100%;padding:12px;border-radius:12px;background:var(--surface2);border:1px solid var(--border);color:var(--text);font-size:13px;font-weight:700;cursor:pointer;font-family:'Sora',sans-serif;display:flex;align-items:center;justify-content:center;gap:8px"><i class="fas fa-paper-plane"></i>OTP পাঠান</button>
                    <div style="margin-top:10px;padding:10px 14px;background:rgba(67,97,238,.07);border:1px solid rgba(67,97,238,.2);border-radius:10px;font-size:11px;color:#a5b4fc;display:flex;align-items:center;gap:8px"><i class="fas fa-info-circle"></i>আপনার প্রোফাইলে দেওয়া মোবাইল নম্বরে OTP পাঠানো হবে।</div>
                </form>
            </div>
        </div>
    </div>

    <!-- ══ OTP MODAL ══ -->
    <div id="otpModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);backdrop-filter:blur(8px);z-index:300;align-items:center;justify-content:center;padding:20px">
        <div style="background:var(--surface);border:1px solid rgba(6,214,160,.3);border-radius:24px;padding:28px 24px;width:100%;max-width:380px;text-align:center;animation:modalIn .3s cubic-bezier(.16,1,.3,1)">
            <div style="width:56px;height:56px;border-radius:50%;background:rgba(6,214,160,.1);border:1px solid rgba(6,214,160,.3);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:22px;color:var(--emerald)"><i class="fas fa-shield-alt"></i></div>
            <div style="font-size:18px;font-weight:800;margin-bottom:8px">OTP ভেরিফিকেশন</div>
            <p style="font-size:13px;color:var(--muted);margin-bottom:20px;line-height:1.65">আপনার মোবাইলে পাঠানো ৬ সংখ্যার কোডটি লিখুন।</p>
            <form method="POST">
                <input type="hidden" name="action" value="verify_password_otp">
                <input type="number" name="otp" style="width:100%;padding:14px;background:rgba(0,0,0,.2);border:1px solid rgba(6,214,160,.3);border-radius:14px;color:var(--emerald);font-size:22px;font-weight:800;text-align:center;letter-spacing:.4em;font-family:'Space Mono',monospace;outline:none;margin-bottom:16px;transition:border-color .2s" placeholder="——————" required>
                <div style="display:flex;gap:10px">
                    <button type="button" onclick="document.getElementById('otpModal').style.display='none'" style="flex:1;padding:12px;border-radius:12px;background:var(--surface2);border:1px solid var(--border);color:var(--muted);font-size:13px;font-weight:700;cursor:pointer;font-family:'Sora',sans-serif">বাতিল</button>
                    <button type="submit" style="flex:1;padding:12px;border-radius:12px;background:linear-gradient(135deg,var(--emerald),#0d9488);border:none;color:#fff;font-size:13px;font-weight:700;cursor:pointer;font-family:'Sora',sans-serif;box-shadow:0 6px 18px rgba(6,214,160,.25)">যাচাই করুন</button>
                </div>
            </form>
        </div>
    </div>

    <style>
        @keyframes modalIn{from{opacity:0;transform:scale(.94) translateY(10px)}to{opacity:1;transform:scale(1) translateY(0)}}
    </style>
    <script>
        
        <?php if($show_otp_modal): ?>
            document.getElementById('otpModal').style.display = 'flex';
        <?php endif; ?>

        // Nav shadow on scroll
        window.addEventListener('scroll', () => {
            document.getElementById('erp-navbar').classList.toggle('shadow', window.scrollY > 8);
        });

        function openProfileModal() {
            const m = document.getElementById('profileModal');
            m.style.display = 'flex';
        }
        function closeProfileModal() {
            document.getElementById('profileModal').style.display = 'none';
        }
        function closeSellModal() {
            document.getElementById('sellModal').style.display = 'none';
        }

        // Close modals on backdrop click
        ['profileModal','sellModal','otpModal'].forEach(id => {
            document.getElementById(id).addEventListener('click', function(e) {
                if(e.target === this) this.style.display = 'none';
            });
        });

        // Profile pic hover
        const picWrap = document.querySelector('[onclick="document.getElementById(\'picInput\').click()"]');
        if(picWrap) {
            const hov = document.getElementById('picHover');
            picWrap.addEventListener('mouseenter', () => { if(hov) hov.style.opacity = '1'; });
            picWrap.addEventListener('mouseleave', () => { if(hov) hov.style.opacity = '0'; });
        }

        function previewImage(input) {
            if(input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = e => {
                    const prev = document.getElementById('profilePreview');
                    const icon = document.getElementById('profilePreviewIcon');
                    if(icon) icon.style.display = 'none';
                    prev.style.display = 'block';
                    prev.src = e.target.result;
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        function toggleSlotBoard() {
            const c = document.getElementById('slotBoardContent');
            const ic = document.getElementById('slotIcon');
            const open = c.style.display === 'block';
            c.style.display = open ? 'none' : 'block';
            ic.style.transform = open ? '' : 'rotate(180deg)';
        }

        function openSellModal(n) {
            document.getElementById('modal_sell_slot_number').value = n;
            document.getElementById('display_slot_number').innerText = '#' + n;
            document.getElementById('sellModal').style.display = 'flex';
        }

        // Charts — dark theme
        Chart.defaults.font.family = "'Sora', sans-serif";
        Chart.defaults.color = '#6e7681';

        const commonOptions = {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(22,27,34,.96)', borderColor: 'rgba(255,255,255,.08)', borderWidth: 1,
                    titleFont: { size: 11, weight: '700' }, bodyFont: { size: 11 },
                    padding: 12, cornerRadius: 10, usePointStyle: true,
                    callbacks: { label: c => ' ৳ ' + c.parsed.y.toLocaleString('en-IN') }
                }
            },
            scales: {
                y: { beginAtZero: true, border: { display: false }, grid: { color: 'rgba(255,255,255,.04)', borderDash: [4,4] }, ticks: { font: { size: 9 }, maxTicksLimit: 5 } },
                x: { grid: { display: false }, border: { display: false }, ticks: { font: { size: 9 }, maxTicksLimit: 6 } }
            }
        };

        const chartDates = <?= $json_dates ?>;
        const profitDatasets = <?= $json_profit_datasets ?>;
        const expenseDatasets = <?= $json_expense_datasets ?>;

        function applyGradient(ctx, datasets) {
            datasets.forEach(ds => {
                const grad = ctx.createLinearGradient(0, 0, 0, 200);
                const cb = ds.borderColor === '#10b981' ? '16,185,129' : ds.borderColor === '#3b82f6' ? '59,130,246' : ds.borderColor === '#f59e0b' ? '245,158,11' : '139,148,158';
                grad.addColorStop(0, `rgba(${cb},.35)`);
                grad.addColorStop(1, `rgba(${cb},0)`);
                ds.backgroundColor = grad;
                ds.tension = 0.4;
                ds.pointRadius = 0;
                ds.pointHoverRadius = 5;
                ds.borderWidth = 2;
            });
        }

        const ctxP = document.getElementById('profitChart').getContext('2d');
        const ctxE = document.getElementById('expenseChart').getContext('2d');
        applyGradient(ctxP, profitDatasets);
        applyGradient(ctxE, expenseDatasets);

        new Chart(ctxP, { type: 'line', data: { labels: chartDates, datasets: profitDatasets }, options: commonOptions });
        new Chart(ctxE, { type: 'line', data: { labels: chartDates, datasets: expenseDatasets }, options: commonOptions });
    </script>
    <script src="theme.js"></script>
    <script>
    function toggleVidSound() {
        var v = document.getElementById('dashVid');
        var btn = document.getElementById('vidSoundBtn');
        if (!v) return;
        v.muted = !v.muted;
        btn.innerHTML = v.muted
            ? '<i class="fas fa-volume-mute"></i> Tap for Sound'
            : '<i class="fas fa-volume-up"></i> Sound On';
        btn.style.background = v.muted ? 'rgba(0,0,0,.65)' : 'rgba(67,97,238,.85)';
    }
    </script>
</body>
</html>