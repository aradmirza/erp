<?php
// ২৪ ঘণ্টার জন্য সেশন ও কুকি সেট করা
ini_set('session.cookie_lifetime', 86400);
ini_set('session.gc_maxlifetime', 86400);
session_set_cookie_params(86400);
session_start();

// বাংলাদেশের টাইমজোন সেট করা
date_default_timezone_set('Asia/Dhaka');

if(!isset($_SESSION['admin_logged_in'])) { header("Location: login.php"); exit; }

// === Role-Based Access Control (RBAC) ===
if(isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'staff') {
    $perms = $_SESSION['staff_permissions'] ?? [];
    if(!in_array('dashboard', $perms)) { 
        die("<div style='text-align:center; padding:50px; font-family:sans-serif;'><h2 style='color:red;'>Access Denied!</h2><p>আপনার ড্যাশবোর্ডে প্রবেশের অনুমতি নেই।</p><a href='login.php'>লগআউট করুন</a></div>"); 
    }
}
// ==========================================
require_once 'db.php';

// অটোমেটিক টেবিল তৈরি করা
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `slot_sales` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `slot_number` int(11) NOT NULL,
      `account_id` int(11) NOT NULL,
      `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `unique_slot_sale` (`slot_number`)
    )");
    
    // সিস্টেম সেটিংস টেবিল 
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

// সেশন থেকে মেসেজ পড়া
$message = $_SESSION['msg_success'] ?? ''; 
$error = $_SESSION['msg_error'] ?? '';
unset($_SESSION['msg_success'], $_SESSION['msg_error']);

// অ্যাডমিন প্যানেল থেকে অ্যাকশন কন্ট্রোল
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['action'])) {
        
        if ($_POST['action'] == 'update_site_settings') {
            $uploadDir = '../uploads/settings/';
            if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }
            
            function updateSettingValue($pdo, $name, $value) {
                $chk = $pdo->prepare("SELECT id FROM system_settings WHERE setting_name = ?");
                $chk->execute([$name]);
                if($chk->rowCount() > 0) {
                    $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_name = ?")->execute([$value, $name]);
                } else {
                    $pdo->prepare("INSERT INTO system_settings (setting_name, setting_value) VALUES (?, ?)")->execute([$name, $value]);
                }
            }
            
            if (isset($_FILES['site_logo']) && $_FILES['site_logo']['error'] === UPLOAD_ERR_OK) {
                $ext = pathinfo($_FILES['site_logo']['name'], PATHINFO_EXTENSION);
                $fileName = 'logo_' . time() . '.' . $ext;
                if(move_uploaded_file($_FILES['site_logo']['tmp_name'], $uploadDir . $fileName)) {
                    updateSettingValue($pdo, 'site_logo', 'uploads/settings/' . $fileName);
                }
            }
            
            if (isset($_FILES['site_favicon']) && $_FILES['site_favicon']['error'] === UPLOAD_ERR_OK) {
                $ext = pathinfo($_FILES['site_favicon']['name'], PATHINFO_EXTENSION);
                $fileName = 'favicon_' . time() . '.' . $ext;
                if(move_uploaded_file($_FILES['site_favicon']['tmp_name'], $uploadDir . $fileName)) {
                    updateSettingValue($pdo, 'site_favicon', 'uploads/settings/' . $fileName);
                }
            }
            
            $_SESSION['msg_success'] = "ড্যাশবোর্ড সেটিংস সফলভাবে আপডেট হয়েছে!";
            header("Location: index.php"); exit;
        }
        
        if ($_POST['action'] == 'refresh_sync_data') {
            try {
                $pdo->exec("UPDATE shareholders SET slot_numbers = REPLACE(slot_numbers, ' ', '') WHERE slot_numbers IS NOT NULL");
                $pdo->exec("UPDATE shareholders SET slot_numbers = TRIM(BOTH ',' FROM slot_numbers) WHERE slot_numbers IS NOT NULL");
                $_SESSION['msg_success'] = "ডাটাবেস এবং সমস্ত স্লটের হিসাব সফলভাবে রিফ্রেশ ও সিঙ্ক করা হয়েছে!";
            } catch(PDOException $e) { 
                $_SESSION['msg_error'] = "রিফ্রেশ এরর: " . $e->getMessage(); 
            }
            header("Location: index.php"); exit;
        }
        
        if ($_POST['action'] == 'admin_sell_slot') {
            $slot_num = (int)$_POST['slot_number'];
            $all_shares = $pdo->query("SELECT account_id, slot_numbers FROM shareholders WHERE slot_numbers IS NOT NULL AND slot_numbers != ''")->fetchAll();
            $owner_id = null;
            foreach($all_shares as $row) {
                $slots = array_map('trim', explode(',', $row['slot_numbers']));
                if(in_array($slot_num, $slots)) {
                    $owner_id = $row['account_id']; break;
                }
            }
            
            if ($owner_id) {
                $pdo->prepare("INSERT IGNORE INTO slot_sales (slot_number, account_id) VALUES (?, ?)")->execute([$slot_num, $owner_id]);
                $_SESSION['msg_success'] = "স্লট $slot_num সফলভাবে বিক্রির জন্য পোস্ট করা হয়েছে!";
            } else {
                $_SESSION['msg_error'] = "এই স্লটটি কারো নামে বুক করা নেই, তাই সেল পোস্ট করা যাবে না!";
            }
            header("Location: index.php"); exit;
        }
        
        if ($_POST['action'] == 'admin_cancel_sell') {
            $slot_num = (int)$_POST['slot_number'];
            $pdo->prepare("DELETE FROM slot_sales WHERE slot_number = ?")->execute([$slot_num]);
            $_SESSION['msg_success'] = "স্লট $slot_num এর সেল পোস্ট বাতিল করা হয়েছে!";
            header("Location: index.php"); exit;
        }
    }
}

// ওয়েবসাইট লোগো ও ফেভিকন ফেচ করা
$site_settings_stmt = $pdo->query("SELECT setting_name, setting_value FROM system_settings WHERE setting_name IN ('site_logo', 'site_favicon', 'mother_project_id')");
$site_settings = $site_settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$site_logo = $site_settings['site_logo'] ?? '';
$site_favicon = $site_settings['site_favicon'] ?? '';
$mother_project_id = (int)($site_settings['mother_project_id'] ?? 0);

try {
    $stmt = $pdo->query("SELECT SUM(investment_credit) as total_credit FROM shareholders");
    $total_credit = (float)($stmt->fetch()['total_credit'] ?? 0);

    $stmt = $pdo->query("SELECT SUM(amount) as total_debit FROM financials WHERE type = 'expense' AND status = 'approved'");
    $total_debit = (float)($stmt->fetch()['total_debit'] ?? 0);

    $stmt = $pdo->query("SELECT SUM(amount) as total_profit FROM financials WHERE type = 'profit' AND status = 'approved'");
    $total_profit = (float)($stmt->fetch()['total_profit'] ?? 0);

    $remaining_balance = $total_credit - $total_debit;
    $is_surplus = $remaining_balance >= 0;
    
    $expense_percentage = ($total_credit > 0) ? ($total_debit / $total_credit) * 100 : (($total_debit > 0) ? 100 : 0);
    $safe_expense_width = min($expense_percentage, 100);

    $general_fund_inv_stmt = $pdo->query("SELECT SUM(investment_credit) FROM shareholders WHERE assigned_project_id IS NULL");
    $general_fund_inv = (float)$general_fund_inv_stmt->fetchColumn() ?: 0;

    // =========================================================================
    // প্রজেক্ট স্পেসিফিক ডাটা এবং মাদার/চাইল্ড লজিক ক্যালকুলেশন
    // =========================================================================
    $projects_raw = $pdo->query("
        SELECT p.id, p.project_name, p.active_percent, p.passive_percent, p.dist_type, p.mother_commission_pct,
               (SELECT COALESCE(SUM(amount), 0) FROM financials WHERE type='profit' AND status='approved' AND project_id = p.id) as gross_profit,
               (SELECT COALESCE(SUM(number_of_shares), 0) FROM shareholders WHERE assigned_project_id = p.id) as total_shares,
               (SELECT COALESCE(SUM(investment_credit), 0) FROM shareholders WHERE assigned_project_id = p.id) as total_inv
        FROM projects p
    ")->fetchAll(PDO::FETCH_ASSOC);

    $total_mother_commission = 0;
    $mother_earnings_details = [];
    $projects_data = [];

    // Step 1: Calculate Child Deductions & Mother's Commission Fund
    foreach ($projects_raw as $p) {
        $gross_profit = (float)$p['gross_profit'];
        $net_profit = $gross_profit; 
        
        if ($mother_project_id > 0 && $p['id'] != $mother_project_id && $p['mother_commission_pct'] > 0) {
            $commission_amount = $gross_profit * ($p['mother_commission_pct'] / 100);
            $total_mother_commission += $commission_amount;
            $net_profit = $gross_profit - $commission_amount; 
            
            if($commission_amount > 0) {
                $mother_earnings_details[] = [
                    'name' => $p['project_name'],
                    'pct' => $p['mother_commission_pct'],
                    'amount' => $commission_amount
                ];
            }
        }
        
        $p['net_distributable_profit'] = $net_profit;
        $projects_data[$p['id']] = $p;
    }

    // Step 2: Add collected commission to Mother Project's distributable profit
    if ($mother_project_id > 0 && isset($projects_data[$mother_project_id])) {
        $projects_data[$mother_project_id]['net_distributable_profit'] += $total_mother_commission;
    }
    
    // Step 3: Calculate Active vs Passive based on NET DISTRIBUTABLE PROFIT
    $total_active_profit = 0;
    $total_passive_profit = 0;
    $total_net_project_profit = 0;

    foreach ($projects_data as $p) {
        $net_prof = (float)$p['net_distributable_profit'];
        if ($net_prof > 0) {
            $total_net_project_profit += $net_prof;
            $act_pct = (float)($p['active_percent'] ?? 0);
            $pass_pct = (float)($p['passive_percent'] ?? 0);
            
            $total_active_profit += ($net_prof * $act_pct) / 100;
            $total_passive_profit += ($net_prof * $pass_pct) / 100;
        }
    }

    $overall_active_pct = $total_net_project_profit > 0 ? ($total_active_profit / $total_net_project_profit) * 100 : 50;
    $overall_passive_pct = $total_net_project_profit > 0 ? ($total_passive_profit / $total_net_project_profit) * 100 : 50;

    // গ্রাফের জন্য গত ১৫ দিনের প্রজেক্ট-ভিত্তিক ডাটা
    $range_days = isset($_GET['range']) ? (int)$_GET['range'] : 15;
    if(!in_array($range_days, [15, 30, 90])) $range_days = 15;

    $raw_dates = []; $chart_dates = [];
    for ($i = $range_days - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $raw_dates[] = $date;
        $chart_dates[] = date('d M', strtotime($date));
    }

    $colors = [
        ['border' => '#10b981', 'bg' => 'rgba(16, 185, 129, 0.1)'], 
        ['border' => '#3b82f6', 'bg' => 'rgba(59, 130, 246, 0.1)'], 
        ['border' => '#f59e0b', 'bg' => 'rgba(245, 158, 11, 0.1)'], 
        ['border' => '#8b5cf6', 'bg' => 'rgba(139, 92, 246, 0.1)'], 
        ['border' => '#ec4899', 'bg' => 'rgba(236, 72, 153, 0.1)'], 
        ['border' => '#0ea5e9', 'bg' => 'rgba(14, 165, 233, 0.1)'], 
        ['border' => '#f43f5e', 'bg' => 'rgba(244, 63, 94, 0.1)']   
    ];

    $profit_ds = []; $expense_ds = [];
    $base_config = ['borderWidth' => 3, 'tension' => 0.4, 'pointRadius' => 2, 'pointHoverRadius' => 6, 'fill' => true, 'pointBackgroundColor' => '#fff'];
    
    $profit_ds['general'] = array_merge(['label' => 'General Fund', 'data' => array_fill_keys($raw_dates, 0), 'borderColor' => '#64748b', 'backgroundColor' => 'rgba(100, 116, 139, 0.2)'], $base_config);
    $expense_ds['general'] = array_merge(['label' => 'General Fund', 'data' => array_fill_keys($raw_dates, 0), 'borderColor' => '#64748b', 'backgroundColor' => 'rgba(100, 116, 139, 0.2)'], $base_config);

    foreach ($projects_data as $idx => $p) {
        $c = $colors[$idx % count($colors)];
        $profit_ds[$p['id']] = array_merge(['label' => $p['project_name'], 'data' => array_fill_keys($raw_dates, 0), 'borderColor' => $c['border'], 'backgroundColor' => $c['bg']], $base_config);
        $expense_ds[$p['id']] = array_merge(['label' => $p['project_name'], 'data' => array_fill_keys($raw_dates, 0), 'borderColor' => $c['border'], 'backgroundColor' => $c['bg']], $base_config);
    }

    $p_stmt = $pdo->prepare("SELECT DATE(date_added) as d, project_id, SUM(amount) as t FROM financials WHERE type='profit' AND status='approved' AND date_added >= DATE_SUB(CURDATE(), INTERVAL ? DAY) GROUP BY DATE(date_added), project_id");
    $p_stmt->execute([$range_days]);
    while($r = $p_stmt->fetch()) { 
        $pid = $r['project_id'] ? $r['project_id'] : 'general';
        if(isset($profit_ds[$pid]['data'][$r['d']])) $profit_ds[$pid]['data'][$r['d']] = (float)$r['t'];
    }

    $e_stmt = $pdo->prepare("SELECT DATE(date_added) as d, project_id, SUM(amount) as t FROM financials WHERE type='expense' AND status='approved' AND date_added >= DATE_SUB(CURDATE(), INTERVAL ? DAY) GROUP BY DATE(date_added), project_id");
    $e_stmt->execute([$range_days]);
    while($r = $e_stmt->fetch()) { 
        $pid = $r['project_id'] ? $r['project_id'] : 'general';
        if(isset($expense_ds[$pid]['data'][$r['d']])) $expense_ds[$pid]['data'][$r['d']] = (float)$r['t'];
    }

    $filtered_profit_ds = [];
    foreach($profit_ds as $ds) { if(array_sum($ds['data']) > 0) { $ds['data'] = array_values($ds['data']); $filtered_profit_ds[] = $ds; } }
    if(empty($filtered_profit_ds)) { $filtered_profit_ds[] = array_merge(['label' => 'কোনো রেকর্ড নেই', 'data' => array_fill(0, $range_days, 0), 'borderColor' => '#cbd5e1', 'backgroundColor' => 'transparent'], $base_config); }

    $filtered_expense_ds = [];
    foreach($expense_ds as $ds) { if(array_sum($ds['data']) > 0) { $ds['data'] = array_values($ds['data']); $filtered_expense_ds[] = $ds; } }
    if(empty($filtered_expense_ds)) { $filtered_expense_ds[] = array_merge(['label' => 'কোনো রেকর্ড নেই', 'data' => array_fill(0, $range_days, 0), 'borderColor' => '#cbd5e1', 'backgroundColor' => 'transparent'], $base_config); }

    $json_dates = json_encode(array_values($chart_dates));
    $json_profit_datasets = json_encode(array_values($filtered_profit_ds));
    $json_expense_datasets = json_encode(array_values($filtered_expense_ds));

    $global_profit_stmt = $pdo->query("SELECT SUM(amount) FROM financials WHERE type='profit' AND status='approved' AND project_id IS NULL");
    $global_profit = (float)$global_profit_stmt->fetchColumn() ?: 0;

    $global_shares_stmt = $pdo->query("SELECT SUM(number_of_shares) FROM shareholders");
    $total_global_shares = (int)$global_shares_stmt->fetchColumn() ?: 0;

    $global_profit_per_share = ($total_global_shares > 0) ? ($global_profit / $total_global_shares) : 0;

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
    $slot_breakdowns = []; $slot_profile_pics = [];
    $shareholder_summary = [];

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
        $proj_name = $row['project_name'] ? $row['project_name'] : 'General Fund';
        $sh_name = $row['name'];

        if(!isset($shareholder_summary[$sh_name])) {
            $shareholder_summary[$sh_name] = ['total_inv' => 0, 'total_profit' => 0, 'slots' => [], 'projects' => []];
        }
        $shareholder_summary[$sh_name]['total_inv'] += $row['investment_credit'];
        $shareholder_summary[$sh_name]['total_profit'] += $entry_profit;
        
        if(!isset($shareholder_summary[$sh_name]['projects'][$proj_name])) {
            $shareholder_summary[$sh_name]['projects'][$proj_name] = ['inv' => 0, 'profit' => 0];
        }
        $shareholder_summary[$sh_name]['projects'][$proj_name]['inv'] += $row['investment_credit'];
        $shareholder_summary[$sh_name]['projects'][$proj_name]['profit'] += $entry_profit;

        foreach($slots as $slot) {
            if(empty($slot)) continue;
            $s_num = (int)$slot;
            $filled_slots[] = $s_num;
            $slot_owners[$s_num] = $sh_name;
            $slot_profile_pics[$s_num] = $row['profile_picture'] ?? null;
            
            if(!in_array($s_num, $shareholder_summary[$sh_name]['slots'])) { $shareholder_summary[$sh_name]['slots'][] = $s_num; }
            if (!isset($slot_profits[$s_num])) { $slot_profits[$s_num] = 0; }
            if (!isset($slot_investments[$s_num])) { $slot_investments[$s_num] = 0; }
            if (!isset($slot_breakdowns[$s_num])) { $slot_breakdowns[$s_num] = []; }
            
            $slot_profits[$s_num] += $profit_per_slot_for_this_entry;
            $slot_investments[$s_num] += $inv_per_slot;
            $slot_breakdowns[$s_num][] = ['p_name' => $proj_name, 'inv' => $inv_per_slot, 'profit' => $profit_per_slot_for_this_entry];
        }
    }
    $filled_slots = array_unique($filled_slots);

    $for_sale_slots = []; $sale_requests = [];
    try {
        $for_sale_stmt = $pdo->query("SELECT slot_number, account_id FROM slot_sales");
        while($row = $for_sale_stmt->fetch()) { $for_sale_slots[$row['slot_number']] = $row['account_id']; }

        $sr_stmt = $pdo->query("
            SELECT ss.id, ss.slot_number, ss.created_at, COALESCE(a.name, 'Unknown') as name, a.username 
            FROM slot_sales ss LEFT JOIN shareholder_accounts a ON ss.account_id = a.id ORDER BY ss.created_at DESC
        ");
        $sale_requests = $sr_stmt->fetchAll();
    } catch(PDOException $e) {}

} catch (PDOException $e) { die("ডাটাবেস টেবিল এরর: " . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Admin Dashboard - Sodai Lagbe</title>
    
    <?php if(!empty($site_favicon)): ?>
        <link rel="icon" href="../<?= htmlspecialchars($site_favicon) ?>">
    <?php else: ?>
        <link rel="icon" href="favicon.ico">
    <?php endif; ?>

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: #f0f4f8; -webkit-tap-highlight-color: transparent; }

        /* ── Header ── */
        .glass-header { background: rgba(255,255,255,0.94); backdrop-filter: blur(18px); -webkit-backdrop-filter: blur(18px); border-bottom: 1px solid rgba(226,232,240,0.9); }

        /* ── Cards ── */
        .app-card { background: #ffffff; border-radius: 20px; box-shadow: 0 1px 4px rgba(0,0,0,0.04), 0 6px 24px rgba(0,0,0,0.05); border: 1px solid rgba(226,232,240,0.75); transition: box-shadow 0.25s ease, transform 0.25s ease; }
        .app-card:hover { box-shadow: 0 4px 28px rgba(0,0,0,0.09); transform: translateY(-1px); }

        /* ── Scrollbar ── */
        .custom-scrollbar::-webkit-scrollbar { width: 4px; height: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background-color: #cbd5e1; border-radius: 10px; }

        /* ── Sidebar Links ── */
        .sidebar-link { display: flex; align-items: center; gap: 10px; padding: 9px 16px; margin: 1px 10px; border-radius: 11px; font-size: 13px; font-weight: 600; color: rgba(255,255,255,0.58); transition: all 0.18s ease; text-decoration: none; }
        .sidebar-link:hover { background: rgba(255,255,255,0.09); color: rgba(255,255,255,0.92); padding-left: 20px; }
        .sidebar-link.active { background: rgba(99,102,241,0.28); color: #fff; border-left: 3px solid #818cf8; margin-left: 8px; padding-left: 18px; }
        .sidebar-link i { width: 16px; text-align: center; font-size: 13px; flex-shrink: 0; opacity: 0.85; }
        .sidebar-section { padding: 14px 20px 5px; font-size: 9px; font-weight: 800; color: rgba(100,116,139,0.75); text-transform: uppercase; letter-spacing: 0.14em; }

        /* ── Slot Boxes ── */
        .slot-sale-glow { animation: gold-pulse 2s infinite; }
        @keyframes gold-pulse { 0% { box-shadow: 0 0 0 0 rgba(245,158,11,0.7); } 70% { box-shadow: 0 0 0 6px rgba(245,158,11,0); } 100% { box-shadow: 0 0 0 0 rgba(245,158,11,0); } }

        /* ── Bottom Nav ── */
        .bottom-nav { position: fixed; bottom: 0; left: 0; right: 0; background: rgba(255,255,255,0.97); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px); border-top: 1px solid rgba(0,0,0,0.06); padding-bottom: env(safe-area-inset-bottom); z-index: 50; display: flex; justify-content: space-around; }
        .nav-item { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 10px 0; color: #64748b; font-size: 10px; font-weight: 700; width: 100%; transition: all 0.2s; cursor: pointer; text-decoration: none; }
        .nav-item.active { color: #4f46e5; }
        .nav-item i { font-size: 18px; margin-bottom: 3px; transition: transform 0.2s; }
        .nav-item.active i { transform: translateY(-2px); }
        @media (min-width: 768px) { .bottom-nav { display: none; } }

        /* ── Animations ── */
        @keyframes fadeInFast { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fade-in { animation: fadeInFast 0.4s ease-out forwards; }

        /* ── Drag & Drop ── */
        .widget-drag-handle { cursor: grab; }
        .widget-drag-handle:active { cursor: grabbing; }
        .sortable-ghost { opacity: 0.4; }

        @media print {
            @page { size: A4 portrait; margin: 12mm 10mm; }
            html, body, .flex-1, main, .md\:pl-64, .h-screen, .min-h-screen { 
                height: auto !important; min-height: auto !important; overflow: visible !important; 
                display: block !important; background-color: #ffffff !important; color: #1e293b !important; 
                margin: 0 !important; padding: 0 !important;
            }
            #sidebar, #sidebar-overlay, header, .bottom-nav, .main-dashboard-content, #smsModal, .glass-header { display: none !important; }
            .print-wrapper { display: block !important; width: 100%; position: static !important; }
            
            .print-header-container { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #2563eb; padding-bottom: 15px; margin-bottom: 30px; }
            .print-logo-area h1 { font-size: 28pt; font-weight: 900; margin: 0; color: #1e3a8a !important; text-transform: uppercase; letter-spacing: -0.5px; -webkit-print-color-adjust: exact; }
            .print-logo-area p { font-size: 11pt; font-weight: 600; color: #64748b !important; margin: 5px 0 0 0; text-transform: uppercase; letter-spacing: 1px; }
            .print-meta-area { text-align: right; font-size: 9.5pt; color: #334155 !important; background: #f8fafc !important; padding: 10px 15px; border-radius: 8px; border: 1px solid #e2e8f0; -webkit-print-color-adjust: exact; }
            .print-section-title { font-size: 14pt; font-weight: 800; color: #0f172a !important; padding: 10px 15px; background: #f1f5f9 !important; border-left: 4px solid #3b82f6 !important; border-radius: 4px 8px 8px 4px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; -webkit-print-color-adjust: exact; page-break-after: avoid; }
            .print-table { width: 100%; border-collapse: separate; border-spacing: 0; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; margin-bottom: 30px; font-size: 9.5pt; page-break-inside: auto; }
            .print-table tr { page-break-inside: avoid; page-break-after: auto; }
            .print-table thead { display: table-header-group; }
            .print-table tfoot { display: table-footer-group; }
            .print-table th, .print-table td { border-bottom: 1px solid #e2e8f0 !important; border-right: 1px solid #f1f5f9 !important; padding: 10px; color: #334155 !important; vertical-align: middle; }
            .print-table th:last-child, .print-table td:last-child { border-right: none !important; }
            .print-table th { background-color: #1e293b !important; color: #ffffff !important; font-weight: 800; text-transform: uppercase; font-size: 8.5pt; letter-spacing: 0.5px; -webkit-print-color-adjust: exact; text-align: center; }
            .print-table tbody tr:nth-child(even) td { background-color: #f8fafc !important; -webkit-print-color-adjust: exact; }
            .text-right { text-align: right !important; } .text-center { text-align: center !important; } .text-left { text-align: left !important; }
            .font-bold { font-weight: 700 !important; }
            .text-blue { color: #2563eb !important; } .text-green { color: #059669 !important; }
            .project-badge { display: inline-block; font-size: 7.5pt; font-weight: 700; background: #dbeafe !important; color: #1d4ed8 !important; border: 1px solid #bfdbfe !important; padding: 2px 6px; border-radius: 6px; margin-bottom: 4px; margin-right: 3px; -webkit-print-color-adjust: exact; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
            .print-footer { text-align: center; margin-top: 40px; font-size: 8.5pt; font-weight: 600; color: #94a3b8; border-top: 1px dashed #cbd5e1; padding-top: 15px; }
            .page-break { page-break-before: always; } .no-break { page-break-inside: avoid; }
        }
        .print-wrapper { display: none; }
    </style>
</head>
<body class="bg-slate-50 font-sans text-slate-800 antialiased selection:bg-blue-200">

    <div id="sidebar-overlay" class="fixed inset-0 bg-slate-900/60 z-40 hidden md:hidden backdrop-blur-sm transition-opacity" onclick="toggleSidebar()"></div>

    <aside id="sidebar" class="fixed inset-y-0 left-0 text-white w-64 flex flex-col transition-transform transform -translate-x-full md:translate-x-0 z-50 shadow-2xl md:shadow-none h-full" style="background:linear-gradient(175deg,#0f172a 0%,#1a1040 55%,#0f172a 100%)">

        <!-- Brand -->
        <div class="flex items-center gap-3 h-[68px] px-5 shrink-0" style="border-bottom:1px solid rgba(255,255,255,0.06)">
            <div class="w-9 h-9 rounded-xl flex items-center justify-center shadow-lg shrink-0 overflow-hidden" style="background:linear-gradient(135deg,#6366f1,#4f46e5)">
                <?php if(!empty($site_logo)): ?>
                    <img src="../<?= htmlspecialchars($site_logo) ?>" alt="Logo" class="w-full h-full object-contain p-0.5">
                <?php else: ?>
                    <i class="fas fa-user-shield text-white text-sm"></i>
                <?php endif; ?>
            </div>
            <div>
                <div class="text-sm font-black text-white tracking-tight leading-tight">Admin Panel</div>
                <div class="text-[10px] font-semibold" style="color:rgba(165,180,252,0.7)">Sodai Lagbe ERP</div>
            </div>
        </div>

        <!-- Navigation -->
        <nav class="flex-1 overflow-y-auto py-3 custom-scrollbar">
            <div class="sidebar-section">Core</div>
            <a href="index.php" class="sidebar-link active"><i class="fas fa-tachometer-alt"></i> ড্যাশবোর্ড</a>

            <div class="sidebar-section">Management</div>
            <a href="manage_shareholders.php" class="sidebar-link"><i class="fas fa-users"></i> শেয়ারহোল্ডার লিস্ট</a>
            <a href="add_shareholder.php" class="sidebar-link"><i class="fas fa-user-plus"></i> অ্যাকাউন্ট তৈরি</a>
            <a href="manage_projects.php" class="sidebar-link"><i class="fas fa-project-diagram"></i> প্রজেক্ট লিস্ট</a>
            <a href="add_project.php" class="sidebar-link"><i class="fas fa-plus-square"></i> নতুন প্রজেক্ট</a>
            <a href="manage_staff.php" class="sidebar-link"><i class="fas fa-users-cog"></i> স্টাফ ম্যানেজমেন্ট</a>
            <a href="manage_kpi.php" class="sidebar-link"><i class="fas fa-bullseye"></i> KPI ম্যানেজমেন্ট</a>
            <a href="manage_votes.php" class="sidebar-link"><i class="fas fa-vote-yea"></i> ভোটিং ও প্রস্তাবনা</a>
            <a href="manage_video.php" class="sidebar-link"><i class="fas fa-video"></i> লাইভ ভিডিও</a>
            <a href="send_sms.php" class="sidebar-link"><i class="fas fa-sms"></i> এসএমএস প্যানেল</a>

            <div class="sidebar-section">Finance & Reports</div>
            <a href="add_entry.php" class="sidebar-link"><i class="fas fa-file-invoice-dollar"></i> দৈনিক হিসাব এন্ট্রি</a>
            <a href="financial_reports.php" class="sidebar-link"><i class="fas fa-chart-pie"></i> লাভ-ক্ষতির রিপোর্ট</a>
            <a href="rider_calculation.php" class="sidebar-link"><i class="fas fa-motorcycle"></i> রাইডার ক্যালকুলেশন</a>
        </nav>

        <!-- Bottom: user + logout -->
        <div class="p-4 shrink-0" style="border-top:1px solid rgba(255,255,255,0.06)">
            <div class="flex items-center gap-3 px-2 mb-3">
                <div class="w-8 h-8 rounded-lg flex items-center justify-center text-white font-black text-sm shadow shrink-0" style="background:linear-gradient(135deg,#6366f1,#4f46e5)">
                    <?= strtoupper(substr($_SESSION['admin_username'] ?? 'A', 0, 1)) ?>
                </div>
                <div class="min-w-0">
                    <div class="text-xs font-bold text-white truncate"><?= htmlspecialchars($_SESSION['admin_username'] ?? 'Admin') ?></div>
                    <div class="text-[9px] font-medium" style="color:rgba(148,163,184,0.7)">System Admin</div>
                </div>
            </div>
            <a href="logout.php" class="sidebar-link" style="color:rgba(248,113,113,0.85);margin:0 4px"><i class="fas fa-sign-out-alt"></i> লগআউট</a>
        </div>
    </aside>

    <div class="flex flex-col min-h-screen w-full md:pl-64 transition-all duration-300">
        
        <header class="glass-header sticky top-0 z-30 px-4 py-3 flex items-center justify-between shadow-sm h-16 shrink-0">
            <div class="flex items-center gap-3">
                <button onclick="toggleSidebar()" class="text-slate-600 focus:outline-none md:hidden text-xl hover:text-blue-600 transition"><i class="fas fa-bars"></i></button>
                <h2 class="text-lg font-black tracking-tight text-slate-800 hidden md:block">অ্যাডমিন ওভারভিউ</h2>
                <button onclick="toggleEditMode()" class="bg-indigo-50 text-indigo-600 hover:bg-indigo-600 hover:text-white px-3 py-1.5 rounded-lg text-xs font-bold shadow-sm transition border border-indigo-200 hidden sm:flex items-center gap-1.5" id="customizeBtn">
                    <i class="fas fa-arrows-alt"></i> কাস্টমাইজ ড্যাশবোর্ড
                </button>
            </div>
            <div class="flex items-center gap-3">
                <button onclick="document.getElementById('siteSettingsModal').classList.remove('hidden')" class="bg-white hover:bg-slate-50 text-slate-700 px-3 py-1.5 rounded-lg text-xs font-bold shadow-sm transition flex items-center gap-2 border border-slate-200">
                    <i class="fas fa-cogs text-blue-500"></i> <span class="hidden md:inline">Settings</span>
                </button>
                <a href="send_sms.php" class="bg-blue-100 text-blue-600 hover:bg-blue-600 hover:text-white w-9 h-9 rounded-full flex items-center justify-center transition shadow-sm border border-blue-200" title="Send SMS">
                    <i class="fas fa-sms text-sm"></i>
                </a>
                <div class="text-right hidden sm:block border-l border-slate-200 pl-3">
                    <div class="text-sm font-bold leading-tight"><?= htmlspecialchars($_SESSION['admin_username'] ?? 'Admin') ?></div>
                    <div class="text-[10px] text-slate-500 font-bold uppercase tracking-wide">System Admin</div>
                </div>
                <div class="h-9 w-9 bg-gradient-to-br from-blue-600 to-indigo-700 rounded-full flex items-center justify-center text-white font-black shadow-md border border-white">
                    <?= strtoupper(substr($_SESSION['admin_username'] ?? 'A', 0, 1)) ?>
                </div>
            </div>
        </header>

        <main class="flex-1 overflow-x-hidden overflow-y-auto p-4 md:p-6 custom-scrollbar pb-24 md:pb-6 relative bg-slate-50">
            
            <div class="main-dashboard-content space-y-6">
                
                <?php if($message): ?><div class="bg-emerald-50 text-emerald-600 px-4 py-3 rounded-xl text-sm font-bold shadow-sm border border-emerald-100 flex items-center animate-fade-in"><i class="fas fa-check-circle mr-2 text-lg"></i> <?= $message ?></div><?php endif; ?>
                <?php if($error): ?><div class="bg-red-50 text-red-600 px-4 py-3 rounded-xl text-sm font-bold shadow-sm border border-red-100 flex items-center animate-fade-in"><i class="fas fa-exclamation-triangle mr-2 text-lg"></i> <?= $error ?></div><?php endif; ?>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 animate-fade-in">
                    <div class="app-card bg-gradient-to-br from-slate-700 to-slate-900 text-white p-5 relative overflow-hidden border-none shadow-sm">
                        <i class="fas fa-wallet absolute -right-4 -bottom-4 text-7xl opacity-20"></i>
                        <p class="text-slate-300 text-[10px] font-bold uppercase tracking-wider mb-1">মোট বিনিয়োগ (Credit)</p>
                        <h3 class="text-2xl font-black mb-2 drop-shadow-sm">৳ <?= number_format($total_credit, 0) ?></h3>
                        <div class="text-[10px] font-medium bg-black/20 inline-block px-2 py-1 rounded backdrop-blur-sm border border-white/10">All Accounts</div>
                    </div>
                    
                    <div class="app-card bg-gradient-to-br from-rose-500 to-red-600 text-white p-5 relative overflow-hidden border-none shadow-sm">
                        <i class="fas fa-hand-holding-usd absolute -right-4 -bottom-4 text-7xl opacity-20"></i>
                        <p class="text-rose-200 text-[10px] font-bold uppercase tracking-wider mb-1">মোট খরচ (Approved)</p>
                        <h3 class="text-2xl font-black mb-3 drop-shadow-sm">৳ <?= number_format($total_debit, 0) ?></h3>
                        
                        <div class="flex justify-between text-[10px] font-bold mb-1">
                            <span class="text-rose-100">খরচ: <?= number_format($expense_percentage, 1) ?>%</span>
                            <span class="text-white"><?= $remaining_balance >= 0 ? 'বাকি: ৳'.number_format($remaining_balance,0) : 'ঘাটতি: ৳'.number_format(abs($remaining_balance),0) ?></span>
                        </div>
                        <div class="w-full bg-black/20 rounded-full h-1.5 overflow-hidden flex backdrop-blur-sm border border-white/10">
                            <div class="bg-white h-full" style="width: <?= $safe_expense_width ?>%"></div>
                        </div>
                    </div>
                    
                    <div class="app-card bg-gradient-to-br from-emerald-500 to-teal-600 text-white p-5 relative overflow-hidden border-none shadow-sm">
                        <i class="fas fa-chart-line absolute -right-4 -bottom-4 text-7xl opacity-20"></i>
                        <p class="text-emerald-200 text-[10px] font-bold uppercase tracking-wider mb-1">মোট লাভ (Approved)</p>
                        <h3 class="text-2xl font-black mb-2 drop-shadow-sm">৳ <?= number_format($total_profit, 0) ?></h3>
                        <div class="text-[10px] font-medium bg-black/20 inline-block px-2 py-1 rounded backdrop-blur-sm border border-white/10">Net Profit</div>
                    </div>

                    <?php if($mother_project_id > 0): ?>
                    <div class="app-card bg-gradient-to-br from-indigo-800 to-indigo-900 text-white p-5 relative overflow-hidden border-none shadow-sm">
                        <i class="fas fa-crown absolute -right-4 -bottom-4 text-7xl opacity-20"></i>
                        <p class="text-indigo-200 text-[10px] font-bold uppercase tracking-wider mb-1">মাদার প্রজেক্ট কমিশন</p>
                        <h3 class="text-2xl font-black mb-2 drop-shadow-sm text-amber-400">৳ <?= number_format($total_mother_commission, 0) ?></h3>
                        <div class="text-[10px] font-medium bg-black/20 inline-block px-2 py-1 rounded backdrop-blur-sm border border-white/10 truncate max-w-full" title="<?= htmlspecialchars($mother_project_name) ?>"><?= htmlspecialchars($mother_project_name) ?></div>
                    </div>
                    <?php else: ?>
                    <div class="app-card bg-slate-100 p-5 relative overflow-hidden border border-slate-200 shadow-sm flex items-center justify-center text-center">
                        <div>
                            <i class="fas fa-crown text-3xl text-slate-300 mb-2"></i>
                            <p class="text-xs font-bold text-slate-400 uppercase">কোনো মাদার প্রজেক্ট নেই</p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div id="dashboard-widgets" class="space-y-6">

                    <div data-id="charts_split" class="widget grid grid-cols-1 lg:grid-cols-3 gap-6 animate-fade-in" style="animation-delay: 0.1s;">
                        
                        <div class="lg:col-span-2 app-card bg-white p-5 relative shadow-sm">
                            <div class="absolute top-4 right-4 widget-drag-handle hidden text-slate-300 hover:text-slate-500 cursor-move"><i class="fas fa-grip-lines text-lg"></i></div>
                            <div class="flex justify-between items-center mb-4 px-1">
                                <h3 class="text-sm font-black text-slate-800 flex items-center gap-2"><i class="fas fa-chart-area text-blue-500"></i> আর্থিক অ্যানালাইসিস</h3>
                                <form method="GET" class="mr-6">
                                    <select name="range" onchange="this.form.submit()" class="bg-white border border-slate-200 text-slate-700 font-bold text-[10px] rounded-lg px-2 py-1 outline-none shadow-sm cursor-pointer hover:border-blue-300 transition">
                                        <option value="15" <?= $range_days == 15 ? 'selected' : '' ?>>15 Days</option>
                                        <option value="30" <?= $range_days == 30 ? 'selected' : '' ?>>30 Days</option>
                                        <option value="90" <?= $range_days == 90 ? 'selected' : '' ?>>90 Days</option>
                                    </select>
                                </form>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                <div>
                                    <h4 class="text-[9px] font-bold text-slate-500 mb-2 uppercase tracking-widest flex items-center gap-1.5"><div class="w-1.5 h-1.5 rounded-full bg-emerald-500"></div> প্রজেক্টভিত্তিক দৈনিক লাভ</h4>
                                    <div class="relative h-48 w-full"><canvas id="profitChart"></canvas></div>
                                </div>
                                <div>
                                    <h4 class="text-[9px] font-bold text-slate-500 mb-2 uppercase tracking-widest flex items-center gap-1.5"><div class="w-1.5 h-1.5 rounded-full bg-rose-500"></div> প্রজেক্টভিত্তিক দৈনিক খরচ</h4>
                                    <div class="relative h-48 w-full"><canvas id="expenseChart"></canvas></div>
                                </div>
                            </div>
                        </div>

                        <div class="lg:col-span-1 app-card bg-white p-5 relative overflow-hidden border-none shadow-sm flex flex-col justify-between">
                            <div class="absolute top-4 right-4 widget-drag-handle hidden text-slate-300 hover:text-slate-500 cursor-move"><i class="fas fa-grip-lines text-lg"></i></div>
                            <div>
                                <div class="flex justify-between items-center mb-4">
                                    <h3 class="text-[11px] font-black uppercase tracking-widest text-slate-700"><i class="fas fa-balance-scale text-purple-500 mr-1.5"></i> ফান্ড ডিস্ট্রিবিউশন</h3>
                                </div>
                                <div class="flex justify-between items-end mb-2">
                                    <div>
                                        <p class="text-[9px] font-black text-blue-500 uppercase tracking-widest mb-0.5">অ্যাক্টিভ (<?= number_format($overall_active_pct, 1) ?>%)</p>
                                        <p class="text-lg font-black text-blue-700 leading-tight">৳ <?= number_format($total_active_profit, 0) ?></p>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-[9px] font-black text-orange-500 uppercase tracking-widest mb-0.5">প্যাসিভ (<?= number_format($overall_passive_pct, 1) ?>%)</p>
                                        <p class="text-lg font-black text-orange-700 leading-tight">৳ <?= number_format($total_passive_profit, 0) ?></p>
                                    </div>
                                </div>
                                <div class="w-full h-2.5 rounded-full overflow-hidden flex shadow-inner mb-4 border border-slate-100">
                                    <div class="bg-blue-500 h-full" style="width: <?= $overall_active_pct ?>%"></div>
                                    <div class="bg-orange-400 h-full" style="width: <?= $overall_passive_pct ?>%"></div>
                                </div>
                            </div>
                            
                            <?php if($mother_project_id > 0 && count($mother_earnings_details) > 0): ?>
                                <div class="mt-2 pt-4 border-t border-slate-100">
                                    <h4 class="text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-2 flex items-center gap-1"><i class="fas fa-crown text-amber-400"></i> মাদার প্রজেক্টে আসা কমিশন:</h4>
                                    <div class="max-h-24 overflow-y-auto custom-scrollbar pr-1 space-y-1.5">
                                        <?php foreach($mother_earnings_details as $med): ?>
                                            <div class="flex justify-between items-center bg-slate-50 p-1.5 rounded border border-slate-100">
                                                <div class="text-[9px] font-bold text-slate-700 truncate w-3/5" title="<?= htmlspecialchars($med['name']) ?>"><?= htmlspecialchars($med['name']) ?> <span class="text-[8px] text-slate-400">(<?= $med['pct'] ?>%)</span></div>
                                                <div class="text-[10px] font-black text-emerald-600">৳<?= number_format($med['amount'], 0) ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <p class="text-[8px] text-slate-400 font-bold mt-auto text-center border-t border-slate-100 pt-3">নেট প্রফিটের ওপর ভিত্তি করে লাভ বণ্টন</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div data-id="slot_board" class="widget app-card animate-fade-in relative" style="animation-delay: 0.2s;">
                        <div class="absolute top-4 right-4 widget-drag-handle hidden text-slate-300 hover:text-slate-500 cursor-move z-10"><i class="fas fa-grip-lines text-lg"></i></div>
                        <div class="p-4 border-b border-slate-100 flex flex-wrap justify-between items-center bg-white rounded-t-2xl">
                            <h3 class="font-black text-slate-800 text-sm flex items-center gap-2"><i class="fas fa-th-large text-indigo-500"></i> শেয়ার স্লট বোর্ড</h3>
                            <div class="flex gap-2 mt-2 sm:mt-0 mr-8">
                                <form method="POST" class="m-0 hidden md:block" id="refreshForm">
                                    <input type="hidden" name="action" value="refresh_sync_data">
                                    <button type="button" onclick="refreshData()" class="bg-white border border-slate-200 text-slate-600 px-3 py-1.5 rounded-lg shadow-sm hover:text-blue-600 hover:border-blue-200 transition text-[10px] font-bold flex items-center gap-1.5">
                                        <i class="fas fa-sync-alt" id="refreshIcon"></i> <span class="hidden sm:inline">Sync</span>
                                    </button>
                                </form>
                                
                                <div class="relative inline-block text-left" id="printDropdownWrapper">
                                    <button type="button" onclick="togglePrintDropdown()" class="bg-gradient-to-r from-indigo-600 to-blue-600 text-white px-3 py-1.5 text-[10px] rounded-lg shadow-sm hover:shadow-md transition-all font-bold flex items-center gap-1.5">
                                        <i class="fas fa-print"></i> প্রিন্ট <i class="fas fa-caret-down"></i>
                                    </button>
                                    <div id="printDropdown" class="hidden origin-top-right absolute right-0 mt-2 w-48 rounded-xl shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-50 overflow-hidden border border-slate-100">
                                        <div class="py-1">
                                            <button onclick="triggerPrint('slot_print')" class="block w-full text-left px-4 py-3 text-xs text-slate-700 hover:bg-slate-50 font-bold border-b border-slate-100 transition"><i class="fas fa-th mr-2 text-blue-500"></i> স্লট অনুযায়ী প্রিন্ট</button>
                                            <button onclick="triggerPrint('person_print')" class="block w-full text-left px-4 py-3 text-xs text-slate-700 hover:bg-slate-50 font-bold transition"><i class="fas fa-users mr-2 text-emerald-500"></i> ব্যক্তি অনুযায়ী প্রিন্ট</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="p-5 bg-slate-50/30 rounded-b-2xl">
                            <div class="w-full bg-slate-200 rounded-full h-2 mb-4 overflow-hidden flex shadow-inner">
                                <div class="bg-blue-600 h-full" style="width: <?= ($total_slots > 0) ? (count($filled_slots)/$total_slots)*100 : 0 ?>%"></div>
                            </div>

                            <div class="flex flex-wrap gap-3 text-[10px] mb-6 font-bold justify-center">
                                <span class="bg-white text-slate-700 px-3 py-1.5 rounded-lg border border-slate-200 shadow-sm flex items-center gap-1.5"><div class="w-2 h-2 rounded-full bg-gradient-to-br from-blue-500 to-indigo-600"></div> বুকড (<?= count($filled_slots) ?>)</span>
                                <span class="bg-white text-slate-700 px-3 py-1.5 rounded-lg border border-slate-200 shadow-sm flex items-center gap-1.5"><div class="w-2 h-2 rounded-full bg-gradient-to-br from-amber-400 to-orange-500"></div> বিক্রির জন্য (<?= count($for_sale_slots) ?>)</span>
                                <span class="bg-white text-slate-700 px-3 py-1.5 rounded-lg border border-slate-200 shadow-sm flex items-center gap-1.5"><div class="w-2 h-2 rounded-full bg-slate-300"></div> ফাঁকা (<?= $total_slots - count($filled_slots) ?>)</span>
                            </div>

                            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 xl:grid-cols-8 gap-4 max-h-[500px] overflow-y-auto custom-scrollbar p-1">
                                <?php for($i=1; $i<=$total_slots; $i++): ?>
                                    <?php 
                                        $is_filled = in_array($i, $filled_slots);
                                        $is_for_sale = isset($for_sale_slots[$i]);
                                        $owner_name = $slot_owners[$i] ?? '';
                                        $slot_profit_val = $slot_profits[$i] ?? 0;
                                        $profile_pic = $slot_profile_pics[$i] ?? null;

                                        if($is_for_sale) {
                                            $bg_class = "bg-gradient-to-br from-amber-400 via-orange-500 to-rose-500 border-none text-white slot-sale-glow hover:-translate-y-1 hover:shadow-lg shadow-md";
                                            $badge_class = "bg-white/20 text-white border-white/30";
                                            $border_class = "border-white/40 bg-white/20";
                                            $text_class = "text-white";
                                            $profit_bg = "bg-black/20 border-white/20";
                                            $profit_text = "text-white";
                                        } elseif($is_filled) {
                                            $bg_class = "bg-gradient-to-br from-blue-500 via-indigo-500 to-purple-600 border-none text-white shadow-md hover:shadow-indigo-500/40 hover:-translate-y-1";
                                            $badge_class = "bg-white/20 text-white border-white/30";
                                            $border_class = "border-white/40 bg-white/20";
                                            $text_class = "text-white";
                                            $profit_bg = "bg-black/20 border-white/20";
                                            $profit_text = "text-white";
                                        } else {
                                            $bg_class = "bg-gradient-to-br from-slate-50 to-slate-100 border-2 border-dashed border-slate-200 hover:from-blue-50 hover:to-indigo-50 hover:border-blue-300";
                                        }
                                    ?>
                                    
                                    <?php if($is_filled || $is_for_sale): ?>
                                        <div class="relative rounded-2xl p-3 <?= $bg_class ?> transition-all duration-300 flex flex-col items-center text-center overflow-hidden group">
                                            
                                            <div class="absolute top-2 right-2 px-1.5 py-0.5 rounded text-[9px] font-black border <?= $badge_class ?>">#<?= $i ?></div>
                                            
                                            <div class="w-10 h-10 rounded-full border-2 <?= $border_class ?> overflow-hidden mb-2 shadow-sm shrink-0 flex items-center justify-center p-0.5 mt-2 bg-white/10">
                                                <?php if(!empty($profile_pic)): ?>
                                                    <img src="../<?= htmlspecialchars($profile_pic) ?>" class="w-full h-full object-cover rounded-full">
                                                <?php else: ?>
                                                    <i class="fas <?= $is_for_sale ? 'fa-bullhorn' : 'fa-user-tie' ?> text-white text-lg opacity-90"></i>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div class="w-full px-1 mb-2.5">
                                                <h4 class="text-[10px] font-black truncate leading-tight <?= $text_class ?>"><?= htmlspecialchars($owner_name) ?></h4>
                                                <?php if($is_for_sale): ?>
                                                    <span class="text-[8px] bg-red-500 text-white px-1.5 py-0.5 rounded uppercase tracking-wider shadow-sm mt-0.5 inline-block">For Sale</span>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div class="w-full py-1.5 rounded-lg mt-auto border <?= $profit_bg ?>">
                                                <span class="text-[10px] font-black tracking-wide <?= $profit_text ?>">+৳ <?= number_format($slot_profit_val, 0) ?></span>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="relative rounded-2xl p-3 <?= $bg_class ?> flex flex-col items-center justify-center text-center transition-all duration-300 group min-h-[110px]">
                                            <div class="absolute top-2 right-2 px-1.5 py-0.5 rounded text-[9px] font-black bg-white border border-slate-200 text-slate-400">#<?= $i ?></div>
                                            <div class="w-8 h-8 rounded-full border border-slate-200 bg-white flex items-center justify-center mb-2 shadow-sm text-slate-300 group-hover:text-blue-400 transition-colors">
                                                <i class="fas fa-plus text-xs"></i>
                                            </div>
                                            <div class="text-[9px] font-bold text-slate-400 uppercase tracking-widest group-hover:text-blue-500 transition-colors">Available</div>
                                        </div>
                                    <?php endif; ?>
                                <?php endfor; ?>
                            </div>
                        </div>
                    </div>

                    <div data-id="manual_sell" class="widget grid grid-cols-1 lg:grid-cols-2 gap-6 animate-fade-in" style="animation-delay: 0.3s;">
                        <div class="app-card relative overflow-hidden">
                            <div class="absolute top-4 right-4 widget-drag-handle hidden text-slate-300 hover:text-slate-500 cursor-move z-10"><i class="fas fa-grip-lines text-lg"></i></div>
                            <div class="absolute -right-6 -top-6 text-amber-50 opacity-40"><i class="fas fa-bullhorn text-9xl"></i></div>
                            <div class="p-6 relative z-10">
                                <h3 class="text-sm font-black text-slate-800 mb-5 flex items-center gap-2"><i class="fas fa-bullhorn text-amber-500"></i> ম্যানুয়ালি সেল পোস্ট করুন</h3>
                                <form method="POST" class="flex gap-3 items-end relative z-10">
                                    <input type="hidden" name="action" value="admin_sell_slot">
                                    <div class="flex-1">
                                        <label class="block text-[10px] font-bold mb-1.5 text-slate-500 uppercase tracking-wide">স্লট নাম্বার</label>
                                        <input type="number" name="slot_number" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:border-amber-500 outline-none transition-all text-sm font-bold shadow-inner" placeholder="Ex: 12" required>
                                    </div>
                                    <button type="submit" class="bg-amber-500 text-white font-bold py-2.5 px-6 rounded-xl shadow-md hover:bg-amber-600 transition h-[42px] text-sm">পোস্ট</button>
                                </form>
                            </div>
                        </div>

                        <div class="app-card flex flex-col h-72 relative">
                            <div class="absolute top-4 right-14 widget-drag-handle hidden text-slate-300 hover:text-slate-500 cursor-move z-10"><i class="fas fa-grip-lines text-lg"></i></div>
                            <div class="p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50/50 rounded-t-2xl">
                                <h3 class="text-sm font-black text-slate-800 flex items-center gap-2"><i class="fas fa-tags text-rose-500"></i> সেল রিকোয়েস্ট</h3>
                                <span class="bg-rose-100 text-rose-700 text-[10px] px-2.5 py-1 rounded-lg font-black shadow-sm"><?= count($sale_requests) ?></span>
                            </div>
                            <div class="flex-1 overflow-y-auto custom-scrollbar p-3">
                                <?php if(count($sale_requests) > 0): ?>
                                    <div class="space-y-3">
                                        <?php foreach($sale_requests as $sr): ?>
                                        <div class="flex items-center justify-between p-3.5 bg-white border border-slate-100 rounded-xl hover:border-slate-200 hover:shadow-sm transition">
                                            <div class="flex items-center gap-4 min-w-0">
                                                <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-600 font-black flex items-center justify-center text-sm border border-amber-100 shadow-sm shrink-0"><?= $sr['slot_number'] ?></div>
                                                <div class="truncate">
                                                    <div class="text-xs font-black text-slate-800 uppercase tracking-wide mb-0.5 truncate"><?= htmlspecialchars($sr['name']) ?></div>
                                                    <div class="text-[9px] text-slate-400 font-bold truncate">@<?= htmlspecialchars($sr['username']) ?></div>
                                                </div>
                                            </div>
                                            <form method="POST" class="m-0 p-0 shrink-0" onsubmit="return confirm('বাতিল করবেন?');">
                                                <input type="hidden" name="action" value="admin_cancel_sell"><input type="hidden" name="slot_number" value="<?= $sr['slot_number'] ?>">
                                                <button type="submit" class="w-8 h-8 rounded-full bg-rose-50 text-rose-500 hover:bg-rose-500 hover:text-white transition flex items-center justify-center border border-rose-100 shadow-sm" title="বাতিল করুন"><i class="fas fa-times text-xs"></i></button>
                                            </form>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="h-full flex flex-col items-center justify-center text-slate-400 py-8">
                                        <i class="fas fa-check-circle text-3xl mb-3 text-slate-200"></i>
                                        <p class="text-[10px] font-bold uppercase tracking-widest">কোনো রিকোয়েস্ট নেই</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                </div> </div> 
            
            <div class="print-wrapper">
                <div class="print-header-container">
                    <div class="print-logo-area">
                        <h1>Sodai Lagbe</h1>
                        <p>কোম্পানির শেয়ারহোল্ডার ও স্লট রিপোর্ট</p>
                    </div>
                    <div class="print-meta-area">
                        <div><i class="fas fa-calendar-alt"></i> <b>তারিখ:</b> <?= date('d M, Y') ?></div>
                        <div style="margin-top:3px;"><i class="fas fa-clock"></i> <b>সময়:</b> <?= date('h:i A') ?></div>
                        <div style="margin-top:3px;"><i class="fas fa-user-shield"></i> <b>প্রিন্ট বাই:</b> Admin</div>
                    </div>
                </div>
                
                <div class="no-break mb-8">
                    <div class="print-section-title">
                        <span><i class="fas fa-chart-pie mr-2"></i> প্রজেক্টভিত্তিক বিনিয়োগের সারসংক্ষেপ</span>
                    </div>
                    <table class="print-table" style="width: 70% !important; margin: 0 auto;">
                        <thead>
                            <tr>
                                <th>প্রজেক্টের নাম</th>
                                <th class="text-right">মোট বিনিয়োগ (৳)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $overall_print_total = 0;
                            if ($general_fund_inv > 0): 
                                $overall_print_total += $general_fund_inv;
                            ?>
                            <tr>
                                <td class="font-bold text-gray-800">General Fund <span style="font-weight:normal; color:#64748b; font-size:8pt;">(কোম্পানির মূল হিসাব)</span></td>
                                <td class="text-right font-bold text-blue">৳ <?= number_format($general_fund_inv, 2) ?></td>
                            </tr>
                            <?php endif; ?>
                            
                            <?php foreach($projects_data as $pd): 
                                if ($pd['total_inv'] > 0): 
                                    $overall_print_total += $pd['total_inv'];
                            ?>
                            <tr>
                                <td class="font-bold text-gray-800"><?= htmlspecialchars($pd['project_name']) ?></td>
                                <td class="text-right font-bold text-blue">৳ <?= number_format($pd['total_inv'], 2) ?></td>
                            </tr>
                            <?php 
                                endif; 
                            endforeach; 
                            ?>
                        </tbody>
                        <tfoot style="border-top: 2px solid #94a3b8;">
                            <tr>
                                <td class="text-right font-bold" style="font-size: 11pt; text-transform: uppercase;">সর্বমোট বিনিয়োগ:</td>
                                <td class="text-right font-bold text-green" style="font-size: 12pt;">৳ <?= number_format($overall_print_total, 2) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                
                <div id="print_section_slot" class="hidden">
                    <div class="print-section-title page-break">
                        <span><i class="fas fa-th mr-2"></i> স্লট অনুযায়ী বিস্তারিত রিপোর্ট</span>
                        <span style="font-size:10pt; font-weight:600; color:#475569;">মোট স্লট: <?= $total_slots ?> | বুকড: <?= count($filled_slots) ?> | ফাঁকা: <?= $total_slots - count($filled_slots) ?></span>
                    </div>
                    
                    <table class="print-table">
                        <thead>
                            <tr>
                                <th class="text-center" style="width: 5%;">নং</th>
                                <th style="width: 25%;">মালিকের নাম</th>
                                <th class="text-center" style="width: 10%;">অবস্থা</th>
                                <th class="text-right" style="width: 15%;">বিনিয়োগ (৳)</th>
                                <th class="text-right" style="width: 15%;">লাভ (৳)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total_slot_value = 0;
                            
                            for($i=1; $i<=$total_slots; $i++): 
                                $s1 = $i;
                                $is_f1 = in_array($s1, $filled_slots);
                                $own1 = $is_f1 ? ($slot_owners[$s1] ?? '-') : '<span style="color:#cbd5e1; font-style:italic;">Empty</span>';
                                $inv1_html = '-'; $prof1_html = '-';
                                
                                if ($is_f1) {
                                    $inv1 = $slot_investments[$s1] ?? 0;
                                    $prof1 = $slot_profits[$s1] ?? 0;
                                    $total_slot_value += $inv1;
                                    
                                    $inv1_html = "<div class='font-bold text-blue' style='font-size:10pt;'>".number_format($inv1, 0)."</div>";
                                    $prof1_html = "<div class='font-bold text-green' style='font-size:10pt;'>".number_format($prof1, 2)."</div>";
                                    
                                    if(isset($slot_breakdowns[$s1]) && count($slot_breakdowns[$s1]) > 1) { 
                                        foreach($slot_breakdowns[$s1] as $bd) {
                                            $pname = htmlspecialchars(mb_strimwidth($bd['p_name'], 0, 18, '..'));
                                            $inv1_html .= "<div style='font-size:8pt; color:#475569; margin-top:4px; line-height:1.3;'><span class='project-badge'>{$pname}</span> ".number_format($bd['inv'], 0)."</div>";
                                            $prof1_html .= "<div style='font-size:8pt; color:#475569; margin-top:4px; line-height:1.3; padding-top:2px;'>".number_format($bd['profit'], 2)."</div>";
                                        }
                                    }
                                }
                                $st1 = isset($for_sale_slots[$s1]) ? 'Sale' : ($is_f1 ? 'Booked' : 'Empty');
                            ?>
                            <tr>
                                <td class="text-center font-bold" style="vertical-align: top; background: #f8fafc; color: #1e3a8a !important; font-size: 11pt;"><?= $s1 ?></td>
                                <td class="font-bold uppercase" style="font-size: 9.5pt; color: #334155; vertical-align: top;"><?= $own1 ?></td>
                                <td class="text-center font-bold" style="vertical-align: top; font-size: 8.5pt;"><?= $st1 ?></td>
                                <td class="text-right" style="vertical-align: top;"><?= $inv1_html ?></td>
                                <td class="text-right" style="vertical-align: top;"><?= $prof1_html ?></td>
                            </tr>
                            <?php endfor; ?>
                        </tbody>
                        <tfoot style="border-top: 2px solid #94a3b8;">
                            <tr>
                                <td colspan="3" class="text-right font-bold uppercase" style="padding: 12px; font-size: 10pt;">বুকড স্লটগুলোতে মোট বিনিয়োগ: <br><span style="font-size: 7.5pt; font-weight: normal; color:#64748b; text-transform:none;">(যেসব বিনিয়োগের স্লট নেই তা এই টেবিলে অন্তর্ভুক্ত নয়)</span></td>
                                <td colspan="2" class="text-left font-bold text-blue" style="padding: 12px; font-size: 12pt;">৳ <?= number_format($total_slot_value, 2) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div id="print_section_person" class="hidden">
                    <div class="print-section-title page-break">
                        <span><i class="fas fa-users mr-2"></i> ব্যক্তি অনুযায়ী বিনিয়োগ ও লাভের রিপোর্ট</span>
                        <span style="font-size:10pt; font-weight:600; color:#475569;">মোট বিনিয়োগকারী: <?= count($shareholder_summary) ?> জন</span>
                    </div>
                    
                    <table class="print-table">
                        <thead>
                            <tr>
                                <th style="width: 5%;" class="text-center">নং</th>
                                <th style="width: 30%;">শেয়ারহোল্ডারের নাম ও স্লট</th>
                                <th style="width: 30%;">প্রজেক্ট অনুযায়ী বিনিয়োগ</th>
                                <th class="text-right" style="width: 15%;">মোট বিনিয়োগ (৳)</th>
                                <th class="text-right" style="width: 20%;">সর্বমোট লাভ (৳)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $sn = 1;
                            $grand_total_inv = 0;
                            $grand_total_profit = 0;
                            ksort($shareholder_summary);
                            foreach($shareholder_summary as $name => $data): 
                                $grand_total_inv += $data['total_inv'];
                                $grand_total_profit += $data['total_profit'];
                                sort($data['slots']);
                                $slots_str = empty($data['slots']) ? '<span style="color:#cbd5e1; font-style:italic;">স্লট নেই</span>' : implode(', ', $data['slots']);
                            ?>
                            <tr class="no-break">
                                <td class="text-center font-bold" style="vertical-align: top; background: #f8fafc; color: #1e3a8a !important;"><?= $sn++ ?></td>
                                <td>
                                    <div class="font-bold uppercase" style="font-size: 10.5pt; color: #0f172a;"><?= htmlspecialchars($name) ?></div>
                                    <div style="font-size: 8.5pt; color: #475569; margin-top: 4px; font-family: monospace;"><b>Slots:</b> <?= $slots_str ?></div>
                                </td>
                                <td>
                                    <?php foreach($data['projects'] as $pname => $pdata): ?>
                                        <div style="margin-bottom: 4px; font-size: 8.5pt;">
                                            <span class="project-badge"><?= htmlspecialchars(mb_strimwidth($pname, 0, 20, '..')) ?></span> 
                                            <span class="font-bold text-gray-700">৳ <?= number_format($pdata['inv'], 0) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </td>
                                <td class="text-right font-bold text-blue" style="vertical-align: top; font-size: 11pt;">৳ <?= number_format($data['total_inv'], 0) ?></td>
                                <td class="text-right font-bold text-green" style="vertical-align: top; font-size: 11pt;">৳ <?= number_format($data['total_profit'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot style="border-top: 2px solid #94a3b8; background-color: #f8fafc;">
                            <tr>
                                <td colspan="3" class="text-right font-bold uppercase" style="padding: 12px; font-size: 11pt;">সর্বমোট হিসাব:</td>
                                <td class="text-right font-bold text-blue" style="padding: 12px; font-size: 12pt;">৳ <?= number_format($grand_total_inv, 0) ?></td>
                                <td class="text-right font-bold text-green" style="padding: 12px; font-size: 12pt;">৳ <?= number_format($grand_total_profit, 2) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            
        </main>
    </div>

    <nav class="bottom-nav md:hidden shadow-[0_-5px_15px_rgba(0,0,0,0.05)] z-50">
        <a href="index.php" class="nav-item active"><i class="fas fa-home"></i> Home</a>
        <a href="manage_shareholders.php" class="nav-item"><i class="fas fa-users"></i> Users</a>
        <a href="add_entry.php" class="nav-item"><i class="fas fa-plus-circle"></i> Entry</a>
        <button onclick="toggleSidebar()" class="nav-item bg-transparent border-none outline-none focus:outline-none"><i class="fas fa-bars"></i> Menu</button>
    </nav>

    <div id="siteSettingsModal" class="hidden fixed inset-0 bg-slate-900/60 flex items-center justify-center z-50 p-4 backdrop-blur-sm transition-opacity">
        <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md border border-slate-100 overflow-hidden transform transition-all scale-100 flex flex-col">
            <div class="px-6 py-5 bg-slate-800 flex justify-between items-center shrink-0">
                <h3 class="text-base font-bold text-white flex items-center gap-2"><i class="fas fa-cogs text-blue-400"></i> ড্যাশবোর্ড সেটিংস</h3>
                <button type="button" onclick="document.getElementById('siteSettingsModal').classList.add('hidden')" class="text-slate-400 hover:text-white transition"><i class="fas fa-times text-lg"></i></button>
            </div>
            
            <form method="POST" enctype="multipart/form-data" class="p-6 space-y-5 bg-slate-50">
                <input type="hidden" name="action" value="update_site_settings">
                
                <div class="bg-white p-4 rounded-2xl shadow-sm border border-slate-200">
                    <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wide mb-3">ওয়েবসাইট লোগো (Logo)</label>
                    <div class="flex items-center gap-4">
                        <div class="w-16 h-16 bg-slate-100 border-2 border-dashed border-slate-300 rounded-xl flex items-center justify-center overflow-hidden shrink-0">
                            <?php if(!empty($site_logo)): ?>
                                <img src="../<?= htmlspecialchars($site_logo) ?>" class="w-full h-full object-contain p-1">
                            <?php else: ?>
                                <i class="fas fa-image text-slate-300 text-xl"></i>
                            <?php endif; ?>
                        </div>
                        <input type="file" name="site_logo" accept="image/*" class="w-full text-xs text-slate-500 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-[10px] file:font-bold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 cursor-pointer">
                    </div>
                </div>

                <div class="bg-white p-4 rounded-2xl shadow-sm border border-slate-200">
                    <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wide mb-3">ফেভিকন (Favicon)</label>
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 bg-slate-100 border-2 border-dashed border-slate-300 rounded-lg flex items-center justify-center overflow-hidden shrink-0">
                            <?php if(!empty($site_favicon)): ?>
                                <img src="../<?= htmlspecialchars($site_favicon) ?>" class="w-full h-full object-contain p-1">
                            <?php else: ?>
                                <i class="fas fa-globe text-slate-300 text-lg"></i>
                            <?php endif; ?>
                        </div>
                        <input type="file" name="site_favicon" accept="image/*" class="w-full text-xs text-slate-500 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-[10px] file:font-bold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 cursor-pointer">
                    </div>
                </div>
                
                <button type="submit" class="w-full bg-blue-600 text-white font-bold py-3.5 rounded-xl shadow-md hover:bg-blue-700 hover:shadow-lg transition transform active:scale-[0.98] text-sm flex justify-center items-center gap-2">
                    <i class="fas fa-save"></i> সেটিংস সেভ করুন
                </button>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    <script>
        function refreshData() {
            const icon = document.getElementById('refreshIcon');
            icon.classList.add('fa-spin');
            setTimeout(() => { document.getElementById('refreshForm').submit(); }, 600);
        }

        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('-translate-x-full');
            document.getElementById('sidebar-overlay').classList.toggle('hidden');
        }

        function togglePrintDropdown() {
            document.getElementById('printDropdown').classList.toggle('hidden');
        }

        window.onclick = function(event) {
            if (!event.target.closest('#printDropdownWrapper')) {
                const pd = document.getElementById('printDropdown');
                if(pd) pd.classList.add('hidden');
            }
        }

        function triggerPrint(type) {
            document.getElementById('printDropdown').classList.add('hidden');
            
            const slotSection = document.getElementById('print_section_slot');
            const personSection = document.getElementById('print_section_person');
            
            slotSection.classList.add('hidden');
            personSection.classList.add('hidden');
            
            if (type === 'slot_print') {
                slotSection.classList.remove('hidden');
                document.title = "Sodai_Lagbe_Slot_Report";
            } else if (type === 'person_print') {
                personSection.classList.remove('hidden');
                document.title = "Sodai_Lagbe_Shareholder_Report";
            }
            
            setTimeout(() => { window.print(); }, 200);
        }

        // ======================================
        // Drag and Drop (SortableJS) Logic
        // ======================================
        let sortableInstance = null;
        
        document.addEventListener('DOMContentLoaded', function () {
            const container = document.getElementById('dashboard-widgets');
            
            // Reorder based on saved state in localStorage
            const savedOrder = localStorage.getItem('dashboard_order');
            if (savedOrder) {
                const orderArr = savedOrder.split('|');
                orderArr.forEach(id => {
                    const el = container.querySelector(`[data-id="${id}"]`);
                    if (el) container.appendChild(el);
                });
            }

            // Initialize Sortable
            sortableInstance = new Sortable(container, {
                animation: 150,
                handle: '.widget-drag-handle',
                disabled: true, // Disabled by default until 'Customize' is clicked
                ghostClass: 'sortable-ghost',
                group: { name: 'dashboard', pull: false, put: false },
                onEnd: function () {
                    const newOrder = sortableInstance.toArray();
                    localStorage.setItem('dashboard_order', newOrder.join('|'));
                }
            });
        });

        function toggleEditMode() {
            if(!sortableInstance) return;
            
            const isCurrentlyDisabled = sortableInstance.option("disabled");
            const btn = document.getElementById('customizeBtn');
            
            // Toggle state
            sortableInstance.option("disabled", !isCurrentlyDisabled);
            
            // Toggle Drag Handles visibility
            document.querySelectorAll('.widget-drag-handle').forEach(el => {
                if(isCurrentlyDisabled) {
                    el.classList.remove('hidden');
                    el.classList.add('flex', 'items-center', 'justify-center', 'w-8', 'h-8', 'bg-slate-100', 'rounded', 'border', 'border-slate-200');
                } else {
                    el.classList.add('hidden');
                    el.classList.remove('flex', 'items-center', 'justify-center', 'w-8', 'h-8', 'bg-slate-100', 'rounded', 'border', 'border-slate-200');
                }
            });
            
            // Update Button Styling
            if(isCurrentlyDisabled) {
                btn.innerHTML = '<i class="fas fa-check"></i> সম্পন্ন করুন (Save)';
                btn.classList.replace('bg-indigo-50', 'bg-emerald-500');
                btn.classList.replace('text-indigo-600', 'text-white');
                btn.classList.replace('border-indigo-200', 'border-emerald-600');
            } else {
                btn.innerHTML = '<i class="fas fa-arrows-alt"></i> কাস্টমাইজ ড্যাশবোর্ড';
                btn.classList.replace('bg-emerald-500', 'bg-indigo-50');
                btn.classList.replace('text-white', 'text-indigo-600');
                btn.classList.replace('border-emerald-600', 'border-indigo-200');
            }
        }

        // ======================================
        // Chart JS Configuration
        // ======================================
        Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
        const chartDates = <?= $json_dates ?>;
        const profitDatasets = <?= $json_profit_datasets ?>;
        const expenseDatasets = <?= $json_expense_datasets ?>;

        const commonOptions = {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { 
                legend: { display: false }, 
                tooltip: { 
                    backgroundColor: 'rgba(15, 23, 42, 0.95)', titleFont: { size: 11, weight: 'bold' }, bodyFont: { size: 11 }, padding: 10, cornerRadius: 8, usePointStyle: true,
                    callbacks: { label: function(c) { return ' ৳ ' + c.parsed.y.toLocaleString('en-IN'); } } 
                } 
            },
            scales: { 
                y: { beginAtZero: true, border: { display: false }, grid: { borderDash: [4, 4], color: '#f1f5f9' }, ticks: { font: { size: 9 }, color: '#94a3b8', maxTicksLimit: 5 } }, 
                x: { grid: { display: false }, border: { display: false }, ticks: { font: { size: 9 }, color: '#94a3b8', maxTicksLimit: 6 } } 
            }
        };

        // Create Gradient for Profit Chart
        let ctxP = document.getElementById('profitChart').getContext('2d');
        profitDatasets.forEach(ds => {
            let grad = ctxP.createLinearGradient(0, 0, 0, 200);
            // using the border color to make a faded gradient
            let colorBase = ds.borderColor === '#10b981' ? '16, 185, 129' : 
                           (ds.borderColor === '#3b82f6' ? '59, 130, 246' : 
                           (ds.borderColor === '#f59e0b' ? '245, 158, 11' : '100, 116, 139'));
            grad.addColorStop(0, `rgba(${colorBase}, 0.4)`);
            grad.addColorStop(1, `rgba(${colorBase}, 0.0)`);
            ds.backgroundColor = grad;
        });

        // Create Gradient for Expense Chart
        let ctxE = document.getElementById('expenseChart').getContext('2d');
        expenseDatasets.forEach(ds => {
            let grad = ctxE.createLinearGradient(0, 0, 0, 200);
            let colorBase = ds.borderColor === '#10b981' ? '16, 185, 129' : 
                           (ds.borderColor === '#3b82f6' ? '59, 130, 246' : 
                           (ds.borderColor === '#f59e0b' ? '245, 158, 11' : '100, 116, 139'));
            grad.addColorStop(0, `rgba(${colorBase}, 0.4)`);
            grad.addColorStop(1, `rgba(${colorBase}, 0.0)`);
            ds.backgroundColor = grad;
        });

        new Chart(ctxP, { type: 'line', data: { labels: chartDates, datasets: profitDatasets }, options: commonOptions });
        new Chart(ctxE, { type: 'line', data: { labels: chartDates, datasets: expenseDatasets }, options: commonOptions });
    </script>
</body>
</html>