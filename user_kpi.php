<?php
ini_set('session.cookie_lifetime', 86400);
ini_set('session.gc_maxlifetime', 86400);
session_set_cookie_params(86400);
session_start();

if(!isset($_SESSION['user_logged_in'])) { header("Location: login.php"); exit; }
require_once __DIR__ . '/db.php';

// Auto-create daily updates table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `kpi_daily_updates` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `user_id` int(11) NOT NULL,
      `role_name` varchar(100) NOT NULL,
      `report_date` date NOT NULL,
      `update_data` text NOT NULL,
      `status` enum('pending','verified','rejected') DEFAULT 'pending',
      `admin_remarks` text NULL,
      `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    )");
} catch (PDOException $e) {}

$uid   = $_SESSION['user_account_id'];
$uname = $_SESSION['user_name'];

// PRG (Post-Redirect-Get) প্যাটার্ন অনুযায়ী সেশন থেকে মেসেজ পড়া
$msg   = $_SESSION['msg_success'] ?? '';
$err   = $_SESSION['msg_error']   ?? '';
unset($_SESSION['msg_success'], $_SESSION['msg_error']);

// Get user profile pic
$up_stmt = $pdo->prepare("SELECT profile_picture FROM shareholder_accounts WHERE id=?");
$up_stmt->execute([$uid]);
$u_pic = $up_stmt->fetchColumn();

// Get advisor assignments
try {
    $adv_stmt = $pdo->prepare("SELECT at.*, kr.color, kr.icon, kr.role_description, kr.department FROM advisor_targets at LEFT JOIN kpi_roles kr ON at.role_name = kr.role_name WHERE at.user_id = ? ORDER BY at.assigned_at DESC");
    $adv_stmt->execute([$uid]);
    $all_assignments = $adv_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $all_assignments = [];
}

if(empty($all_assignments)) {
    ?><!DOCTYPE html>
<html lang="bn"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Access Restricted</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;700;800;900&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Plus Jakarta Sans',sans-serif;background:linear-gradient(135deg,#f0f4ff 0%,#fafafa 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.card{text-align:center;max-width:400px;width:100%;padding:48px 32px;background:white;border-radius:28px;box-shadow:0 20px 60px rgba(0,0,0,0.08);border:1px solid rgba(241,245,249,0.8)}
.icon-wrap{width:88px;height:88px;border-radius:28px;background:linear-gradient(135deg,#fff1f2,#ffe4e6);border:1px solid #fecdd3;display:flex;align-items:center;justify-content:center;margin:0 auto 28px;font-size:36px;color:#f43f5e;box-shadow:0 8px 24px rgba(244,63,94,0.15)}
h2{font-size:26px;font-weight:900;margin:0 0 10px;color:#0f172a;letter-spacing:-0.5px}
p{color:#64748b;margin:0 0 28px;font-size:14px;line-height:1.7;font-weight:500}
a{display:inline-flex;align-items:center;gap:10px;padding:14px 28px;background:linear-gradient(135deg,#3b82f6,#2563eb);color:white;border-radius:14px;text-decoration:none;font-weight:800;font-size:14px;box-shadow:0 8px 20px rgba(37,99,235,0.3);transition:all 0.2s;letter-spacing:0.2px}
a:hover{transform:translateY(-2px);box-shadow:0 12px 28px rgba(37,99,235,0.4)}
</style>
</head><body>
<div class="card">
  <div class="icon-wrap"><i class="fas fa-lock"></i></div>
  <h2>অ্যাক্সেস নেই</h2>
  <p>আপনাকে এখনো কোনো দায়িত্বের পদে নিযুক্ত করা হয়নি।<br>অ্যাডমিনের সাথে যোগাযোগ করুন।</p>
  <a href="index.php"><i class="fas fa-arrow-left"></i> ড্যাশবোর্ডে ফিরুন</a>
</div>
</body></html><?php
    exit;
}

$current_role_idx = 0;
if(isset($_GET['role']) && is_numeric($_GET['role'])) {
    foreach($all_assignments as $i => $a) { if($a['id'] == $_GET['role']) { $current_role_idx = $i; break; } }
}
$advisor_data = $all_assignments[$current_role_idx];
$role_name    = $advisor_data['role_name'];
$role_color   = $advisor_data['color'] ?? '#3b82f6';
$role_icon    = $advisor_data['icon']  ?? 'fa-user-tie';
$target_text  = json_decode($advisor_data['target_data'], true) ?: [];

// POST: submit daily report
if($_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['action'] ?? '') == 'submit_daily_update') {
    $rdate  = $_POST['report_date'];
    $active_role = $_POST['active_role'] ?? $role_name;
    $udata  = json_encode($_POST['daily_data'] ?? [], JSON_UNESCAPED_UNICODE);

    $chk = $pdo->prepare("SELECT id FROM kpi_daily_updates WHERE user_id=? AND role_name=? AND report_date=?");
    $chk->execute([$uid, $active_role, $rdate]);
    if($chk->rowCount() > 0) {
        $_SESSION['msg_error'] = "আপনি ইতিমধ্যে এই তারিখের রিপোর্ট জমা দিয়েছেন।";
    } else {
        $pdo->prepare("INSERT INTO kpi_daily_updates (user_id, role_name, report_date, update_data) VALUES (?,?,?,?)")->execute([$uid, $active_role, $rdate, $udata]);
        $_SESSION['msg_success'] = "রিপোর্ট সফলভাবে জমা হয়েছে! অ্যাডমিন ভেরিফাইয়ের অপেক্ষায়।";
    }
    header("Location: user_kpi.php?role=".$advisor_data['id']."#report"); exit;
}

// SAFE FETCH METHODS
function safeQuery($pdo, $sql, $params = []) {
    try { $stmt = $pdo->prepare($sql); $stmt->execute($params); return $stmt->fetchAll(PDO::FETCH_ASSOC); }
    catch(Exception $e) { return []; }
}
function safeColumn($pdo, $sql, $params = []) {
    try { $stmt = $pdo->prepare($sql); $stmt->execute($params); return $stmt->fetchColumn(); }
    catch(Exception $e) { return null; }
}

$metrics_raw = safeQuery($pdo, "SELECT * FROM kpi_metrics WHERE role_name=? AND is_active=1 ORDER BY id", [$role_name]);

$total_profit  = (float)(safeColumn($pdo, "SELECT COALESCE(SUM(amount),0) FROM financials WHERE type='profit' AND status='approved'") ?: 0);
$adv_fund_pct  = (float)(safeColumn($pdo, "SELECT setting_value FROM system_settings WHERE setting_name='advisor_fund_pct'") ?: 10);
$total_adv_fund= $total_profit * ($adv_fund_pct / 100);

$role_pct      = (float)(safeColumn($pdo, "SELECT profit_share_pct FROM role_settings WHERE role_name=?", [$role_name]) ?: 0);
$max_bonus     = $total_adv_fund * ($role_pct / 100);

$evaluations = safeQuery($pdo, "SELECT * FROM kpi_evaluations WHERE user_id=? AND role_name=? ORDER BY created_at DESC", [$uid, $role_name]);

$latest_eval = $evaluations[0] ?? null;
$latest_score = $latest_eval ? $latest_eval['total_score'] : 0;
$latest_grade = $latest_eval ? ($latest_eval['performance_grade'] ?? 'N/A') : 'N/A';
$latest_bonus = $latest_eval ? ($max_bonus * ($latest_eval['total_score']/100)) : 0;

$daily_reports = safeQuery($pdo, "SELECT * FROM kpi_daily_updates WHERE user_id=? AND role_name=? ORDER BY report_date DESC LIMIT 14", [$uid, $role_name]);

$today_check = safeColumn($pdo, "SELECT id FROM kpi_daily_updates WHERE user_id=? AND role_name=? AND report_date=?", [$uid, $role_name, date('Y-m-d')]);
$already_submitted_today = $today_check ? true : false;

$streak = 0;
$check_date = new DateTime();
$check_date->modify('-1 day');
$report_dates = array_column($daily_reports, 'report_date');
while(in_array($check_date->format('Y-m-d'), $report_dates)) {
    $streak++;
    $check_date->modify('-1 day');
}

$perf_trend = array_slice($evaluations, 0, 3);
$trend_scores = array_column(array_reverse($perf_trend), 'total_score');

// Level system — score history থেকে level নির্ধারণ
$all_evals_for_level = safeQuery($pdo, "SELECT total_score FROM kpi_evaluations WHERE user_id=? AND role_name=? ORDER BY created_at DESC LIMIT 6", [$uid, $role_name]);
$avg_eval_score = count($all_evals_for_level) > 0 ? array_sum(array_column($all_evals_for_level, 'total_score')) / count($all_evals_for_level) : 0;
$level_data = $avg_eval_score >= 90 ? ['name'=>'Diamond', 'icon'=>'fa-gem',          'color'=>'#06b6d4', 'bg'=>'#ecfeff', 'border'=>'#a5f3fc', 'next'=>''] :
             ($avg_eval_score >= 75 ? ['name'=>'Gold',    'icon'=>'fa-trophy',       'color'=>'#f59e0b', 'bg'=>'#fffbeb', 'border'=>'#fde68a', 'next'=>'Diamond'] :
             ($avg_eval_score >= 60 ? ['name'=>'Silver',  'icon'=>'fa-medal',        'color'=>'#94a3b8', 'bg'=>'#f8fafc', 'border'=>'#e2e8f0', 'next'=>'Gold'] :
             ($avg_eval_score >= 40 ? ['name'=>'Bronze',  'icon'=>'fa-award',        'color'=>'#d97706', 'bg'=>'#fffbeb', 'border'=>'#fde68a', 'next'=>'Silver'] :
                                      ['name'=>'Starter', 'icon'=>'fa-seedling',     'color'=>'#10b981', 'bg'=>'#ecfdf5', 'border'=>'#a7f3d0', 'next'=>'Bronze'])));

// Streak milestone badge
$streak_badge = '';
if($streak >= 30)     $streak_badge = ['label'=>'🔥 ৩০ দিনের যোদ্ধা!', 'color'=>'#7c3aed', 'bg'=>'#f5f3ff', 'border'=>'#ddd6fe'];
elseif($streak >= 14) $streak_badge = ['label'=>'⚡ দুই সপ্তাহের অগ্রগতি!', 'color'=>'#0369a1', 'bg'=>'#eff6ff', 'border'=>'#bfdbfe'];
elseif($streak >= 7)  $streak_badge = ['label'=>'🌟 এক সপ্তাহ ধরে কাজ!', 'color'=>'#047857', 'bg'=>'#ecfdf5', 'border'=>'#a7f3d0'];

// Count unread admin remarks (rejected reports or reports with remarks user hasn't ack'd)
$unread_remarks = (int)safeColumn($pdo, "SELECT COUNT(*) FROM kpi_daily_updates WHERE user_id=? AND role_name=? AND admin_remarks IS NOT NULL AND admin_remarks != '' AND status='rejected'", [$uid, $role_name]);

$curr_month = date('Y-m');
$verified_days = (int)safeColumn($pdo, "SELECT COUNT(*) FROM kpi_daily_updates WHERE user_id=? AND role_name=? AND status='verified' AND DATE_FORMAT(report_date, '%Y-%m') = ?", [$uid, $role_name, $curr_month]);

$total_days_in_month = (int)date('t');
$passed_days = (int)date('j');

$daily_potential_bonus = ($total_days_in_month > 0) ? ($max_bonus / $total_days_in_month) : 0;
$estimated_earned_bonus = $verified_days * $daily_potential_bonus;
$pacing_pct = ($passed_days > 0) ? ($verified_days / $passed_days) * 100 : 0;
if($pacing_pct > 100) $pacing_pct = 100;
$work_progress_pct = ($total_days_in_month > 0) ? ($verified_days / $total_days_in_month) * 100 : 0;

// Score ring calc
$circ = 2 * M_PI * 36;
$pct  = min(100, max(0, $latest_score));
$off  = $circ - ($pct / 100 * $circ);
$rc   = $latest_score>=75 ? '#10b981' : ($latest_score>=60 ? '#3b82f6' : ($latest_score>=40 ? '#f59e0b' : '#f43f5e'));

// Grade style map
$gc_key = strtolower(str_replace(' ','_',$latest_grade));
$grade_map = [
    'exceptional'       => ['label'=>'Exceptional', 'bg'=>'#f5f3ff','color'=>'#7c3aed','border'=>'#ddd6fe'],
    'excellent'         => ['label'=>'Excellent',   'bg'=>'#ecfdf5','color'=>'#059669','border'=>'#a7f3d0'],
    'good'              => ['label'=>'Good',         'bg'=>'#eff6ff','color'=>'#2563eb','border'=>'#bfdbfe'],
    'average'           => ['label'=>'Average',      'bg'=>'#fffbeb','color'=>'#d97706','border'=>'#fde68a'],
    'needs_improvement' => ['label'=>'Needs Work',   'bg'=>'#fff1f2','color'=>'#e11d48','border'=>'#fecdd3'],
    'poor'              => ['label'=>'Poor',          'bg'=>'#fff1f2','color'=>'#e11d48','border'=>'#fecdd3'],
];
$grade_style = $grade_map[$gc_key] ?? ['label'=>$latest_grade,'bg'=>'#f8fafc','color'=>'#64748b','border'=>'#e2e8f0'];
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>KPI প্যানেল — <?= htmlspecialchars($role_name) ?></title>
<script>(function(){var t=localStorage.getItem('erpTheme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;0,900;1,400&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="theme.css">
<style>
/* ── RESET & BASE ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; -webkit-text-size-adjust: 100%; }
body {
    font-family: 'Plus Jakarta Sans', sans-serif;
    background: #f1f5f9;
    color: #1e293b;
    min-height: 100vh;
    -webkit-tap-highlight-color: transparent;
    padding-bottom: 80px;
}
@media (min-width: 768px) { body { padding-bottom: 24px; } }

/* ── CSS VARIABLES ── */
:root {
    --accent: <?= $role_color ?>;
    --accent-light: <?= $role_color ?>18;
    --accent-mid: <?= $role_color ?>35;
    --accent-border: <?= $role_color ?>55;
    --white: #ffffff;
    --surface: #ffffff;
    --surface-2: #f8fafc;
    --border: #e2e8f0;
    --border-2: #f1f5f9;
    --text-1: #0f172a;
    --text-2: #334155;
    --text-3: #64748b;
    --text-4: #94a3b8;
    --radius-sm: 10px;
    --radius: 16px;
    --radius-lg: 22px;
    --radius-xl: 28px;
    --shadow-xs: 0 1px 3px rgba(0,0,0,0.06), 0 1px 2px rgba(0,0,0,0.04);
    --shadow-sm: 0 4px 12px rgba(0,0,0,0.05), 0 1px 3px rgba(0,0,0,0.04);
    --shadow:    0 8px 24px rgba(0,0,0,0.07), 0 2px 6px rgba(0,0,0,0.04);
    --shadow-lg: 0 16px 48px rgba(0,0,0,0.1),  0 4px 12px rgba(0,0,0,0.06);
}

/* ── SCROLLBAR ── */
::-webkit-scrollbar { width: 5px; height: 5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 99px; }

/* ── ANIMATIONS ── */
@keyframes fadeUp   { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }
@keyframes fadeIn   { from { opacity: 0; } to { opacity: 1; } }
@keyframes scaleIn  { from { opacity: 0; transform: scale(0.94); } to { opacity: 1; transform: scale(1); } }
@keyframes pulse    { 0%,100% { opacity: 1; } 50% { opacity: .4; } }
@keyframes dashGrow { from { stroke-dashoffset: <?= $circ ?>; } to { stroke-dashoffset: <?= $latest_score > 0 ? $off : $circ ?>; } }

.anim-up    { animation: fadeUp  0.45s cubic-bezier(.22,.68,0,1.2) both; }
.anim-up-d1 { animation: fadeUp  0.45s cubic-bezier(.22,.68,0,1.2) 0.08s both; }
.anim-up-d2 { animation: fadeUp  0.45s cubic-bezier(.22,.68,0,1.2) 0.16s both; }
.anim-up-d3 { animation: fadeUp  0.45s cubic-bezier(.22,.68,0,1.2) 0.24s both; }
.anim-scale { animation: scaleIn 0.35s cubic-bezier(.22,.68,0,1.2) both; }
.pulse-dot  { animation: pulse 1.6s ease-in-out infinite; }

/* ── HEADER ── */
.header {
    position: sticky; top: 0; z-index: 100;
    background: rgba(255,255,255,0.92);
    backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);
    border-bottom: 1px solid var(--border);
    padding: 0 16px;
    height: 60px;
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px;
}
.header-left  { display: flex; align-items: center; gap: 10px; min-width: 0; }
.header-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
.header-back {
    width: 36px; height: 36px; border-radius: 10px;
    background: var(--surface-2); border: 1px solid var(--border);
    display: flex; align-items: center; justify-content: center;
    color: var(--text-3); text-decoration: none;
    transition: background .15s, color .15s, transform .15s;
    flex-shrink: 0;
}
.header-back:hover { background: var(--accent-light); color: var(--accent); transform: scale(1.05); }
.header-title { font-size: 15px; font-weight: 800; color: var(--text-1); line-height: 1.2; }
.header-sub   { font-size: 10px; font-weight: 600; color: var(--text-4); letter-spacing: .04em; }
.role-select {
    background: var(--surface-2); border: 1.5px solid var(--border);
    border-radius: 10px; padding: 6px 10px; font-size: 11px; font-weight: 700;
    color: var(--text-2); outline: none; cursor: pointer;
    max-width: 120px; font-family: inherit;
    transition: border-color .15s;
}
.role-select:focus { border-color: var(--accent); }
.avatar {
    width: 36px; height: 36px; border-radius: 50%;
    background: var(--surface-2); border: 2px solid var(--border);
    overflow: hidden; display: flex; align-items: center; justify-content: center;
    color: var(--text-4); font-size: 14px; flex-shrink: 0;
}
.avatar img { width: 100%; height: 100%; object-fit: cover; }

/* ── MAIN LAYOUT ── */
.main {
    max-width: 860px; margin: 0 auto;
    padding: 20px 16px;
    display: flex; flex-direction: column; gap: 16px;
}

/* ── TOAST MESSAGES ── */
.toast {
    display: flex; align-items: center; gap: 12px;
    padding: 14px 18px; border-radius: var(--radius);
    font-size: 13px; font-weight: 700; border: 1px solid;
}
.toast-success { background: #ecfdf5; color: #065f46; border-color: #a7f3d0; }
.toast-error   { background: #fff1f2; color: #9f1239; border-color: #fecdd3; }
.toast i       { font-size: 16px; flex-shrink: 0; }

/* ── HERO CARD ── */
.hero-card {
    background: var(--white);
    border-radius: var(--radius-xl);
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
    overflow: hidden;
    position: relative;
}
.hero-banner {
    height: 7px;
    background: linear-gradient(90deg, var(--accent), <?= $role_color ?>cc);
}
.hero-body { padding: 24px; }
.hero-grid {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 20px;
    align-items: start;
}
@media (max-width: 400px) {
    .hero-grid { grid-template-columns: 1fr; justify-items: center; text-align: center; }
}

/* Score Ring */
.score-ring-wrap {
    position: relative; width: 88px; height: 88px;
    display: flex; align-items: center; justify-content: center;
}
.score-ring-wrap svg { transform: rotate(-90deg); }
.score-ring-inner {
    position: absolute; inset: 0;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 1px;
}
.score-value  { font-size: 22px; font-weight: 900; line-height: 1; }
.score-denom  { font-size: 9px; font-weight: 700; color: var(--text-4); letter-spacing: .03em; }
.score-ring   { stroke-dasharray: <?= $circ ?>; stroke-dashoffset: <?= $latest_score > 0 ? $off : $circ ?>; animation: dashGrow 1.2s cubic-bezier(.4,0,.2,1) .3s both; }

/* Hero Meta */
.hero-name    { font-size: 20px; font-weight: 900; color: var(--text-1); letter-spacing: -.4px; line-height: 1.2; margin-bottom: 6px; }
.hero-badges  { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 14px; align-items: center; }
.badge-role {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 10px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase;
    padding: 5px 10px; border-radius: 8px;
    background: var(--accent-light); color: var(--accent); border: 1px solid var(--accent-border);
}
.badge-dept {
    font-size: 10px; font-weight: 700; color: var(--text-3);
    background: var(--surface-2); padding: 5px 10px; border-radius: 8px;
    border: 1px solid var(--border);
}
.badge-grade {
    font-size: 10px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase;
    padding: 4px 10px; border-radius: 99px; border: 1px solid;
}

/* Hero Stats */
.hero-stats {
    display: grid; grid-template-columns: repeat(3, 1fr);
    gap: 8px;
}
.hero-stat {
    background: var(--surface-2); border: 1px solid var(--border-2);
    border-radius: var(--radius-sm); padding: 10px 8px;
}
.hero-stat-label { font-size: 9px; font-weight: 700; color: var(--text-4); text-transform: uppercase; letter-spacing: .05em; margin-bottom: 4px; }
.hero-stat-value { font-size: 13px; font-weight: 900; }

/* Role description */
.role-desc {
    margin: 16px 24px 0;
    padding: 12px 16px; border-radius: var(--radius-sm);
    background: #eff6ff; border: 1px solid #bfdbfe;
    font-size: 12px; font-weight: 500; color: #1e40af; line-height: 1.6;
    display: flex; gap: 10px; align-items: flex-start;
}
.role-desc i { margin-top: 1px; font-size: 13px; flex-shrink: 0; color: #3b82f6; }

/* ── PROGRESS CARD ── */
.progress-card {
    background: var(--white); border-radius: var(--radius-lg);
    border: 1px solid var(--border); box-shadow: var(--shadow-sm);
    padding: 22px;
}
.section-header {
    display: flex; align-items: center; gap: 8px;
    margin-bottom: 18px;
}
.section-icon {
    width: 32px; height: 32px; border-radius: 9px;
    background: var(--accent-light); color: var(--accent);
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; flex-shrink: 0;
}
.section-title { font-size: 14px; font-weight: 800; color: var(--text-1); }

.stats-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
    margin-bottom: 18px;
}
@media (min-width: 480px) { .stats-grid { grid-template-columns: repeat(4, 1fr); } }

.stat-box {
    background: var(--surface-2); border: 1px solid var(--border-2);
    border-radius: var(--radius-sm); padding: 14px 10px; text-align: center;
}
.stat-box.highlight { background: #ecfdf5; border-color: #a7f3d0; }
.stat-box-label { font-size: 9px; font-weight: 700; color: var(--text-4); text-transform: uppercase; letter-spacing: .05em; margin-bottom: 6px; }
.stat-box-value { font-size: 20px; font-weight: 900; line-height: 1; }
.stat-box-sub   { font-size: 10px; color: var(--text-4); margin-top: 2px; font-weight: 600; }
.stat-box.highlight .stat-box-label { color: #059669; }
.stat-box.highlight .stat-box-value { color: #059669; }

.progress-bar-wrap { }
.progress-bar-meta {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 8px;
}
.progress-bar-label { font-size: 11px; font-weight: 700; color: var(--text-3); }
.progress-bar-pct   { font-size: 12px; font-weight: 900; color: #059669; }
.progress-track {
    width: 100%; height: 10px; background: #f1f5f9;
    border-radius: 99px; overflow: hidden;
    box-shadow: inset 0 1px 3px rgba(0,0,0,.06);
}
.progress-fill {
    height: 100%; border-radius: 99px;
    background: linear-gradient(90deg, #34d399, #10b981);
    transition: width 1s cubic-bezier(.4,0,.2,1);
    position: relative;
}
.progress-note {
    font-size: 10px; color: var(--text-4); font-weight: 600;
    text-align: center; margin-top: 8px;
    display: flex; align-items: center; justify-content: center; gap: 4px;
}

/* ── TABS ── */
.tabs-card {
    background: var(--white); border-radius: var(--radius-lg);
    border: 1px solid var(--border); box-shadow: var(--shadow-sm);
    overflow: hidden;
}
.tabs-nav {
    display: flex; gap: 4px;
    padding: 12px 16px 0;
    overflow-x: auto; -webkit-overflow-scrolling: touch;
    border-bottom: 1px solid var(--border-2);
    scrollbar-width: none;
}
.tabs-nav::-webkit-scrollbar { display: none; }
.tab-btn {
    display: flex; align-items: center; gap: 7px;
    padding: 10px 16px; border-radius: 10px 10px 0 0;
    font-size: 12px; font-weight: 800; white-space: nowrap;
    color: var(--text-3); background: transparent; border: none;
    cursor: pointer; position: relative; transition: all .2s;
    font-family: inherit; letter-spacing: .01em;
    border-bottom: 2px solid transparent; margin-bottom: -1px;
}
.tab-btn:hover:not(.active) { color: var(--text-2); background: var(--surface-2); }
.tab-btn.active {
    color: var(--accent);
    border-bottom-color: var(--accent);
    background: var(--accent-light);
}
.tab-btn .tab-dot {
    width: 7px; height: 7px; border-radius: 50%;
    background: #f43f5e; position: absolute; top: 8px; right: 8px;
}
.tab-content { padding: 20px; }

/* Tab panes */
.tab-pane { display: none; }
.tab-pane.active { display: block; animation: fadeUp .3s ease both; }

/* ── METRIC CARDS ── */
.metric-list { display: flex; flex-direction: column; gap: 12px; }
.metric-card {
    background: var(--surface-2); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 18px;
    transition: border-color .2s, box-shadow .2s;
}
.metric-card:hover { border-color: var(--accent-border); box-shadow: 0 4px 16px rgba(0,0,0,.05); }
.metric-card-head {
    display: flex; align-items: flex-start; gap: 14px; margin-bottom: 12px;
}
.metric-icon {
    width: 42px; height: 42px; border-radius: 12px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: 16px;
    background: var(--accent-light); color: var(--accent);
    border: 1px solid var(--accent-border);
}
.metric-name { font-size: 14px; font-weight: 800; color: var(--text-1); margin-bottom: 4px; line-height: 1.3; }
.metric-desc { font-size: 11px; font-weight: 500; color: var(--text-3); line-height: 1.5; margin-bottom: 8px; }
.metric-chips { display: flex; flex-wrap: wrap; gap: 5px; }
.chip {
    font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: .06em;
    padding: 4px 8px; border-radius: 6px; border: 1px solid;
}
.chip-score  { background: #f8fafc; color: var(--text-3); border-color: var(--border); }
.chip-target { background: #ecfdf5; color: #065f46; border-color: #a7f3d0; }

.target-box {
    background: #eff6ff; border: 1px solid #bfdbfe;
    border-radius: var(--radius-sm); padding: 14px;
}
.target-box-label {
    font-size: 9px; font-weight: 900; text-transform: uppercase; letter-spacing: .08em;
    color: #3b82f6; margin-bottom: 10px;
    display: flex; align-items: center; gap: 6px;
}
.target-kv-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 8px;
}
.target-kv {
    background: white; border: 1px solid #dbeafe; border-radius: 8px;
    padding: 8px 10px; display: flex; flex-direction: column; gap: 2px;
}
.target-kv-key { font-size: 9px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .04em; }
.target-kv-val { font-size: 12px; font-weight: 800; color: #1e40af; }

.empty-state {
    text-align: center; padding: 48px 20px;
    display: flex; flex-direction: column; align-items: center; gap: 12px;
}
.empty-state i { font-size: 40px; color: var(--text-4); }
.empty-state p { font-size: 13px; font-weight: 700; color: var(--text-3); }

/* ── REPORT FORM ── */
.already-done {
    text-align: center; padding: 40px 20px;
    background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: var(--radius);
}
.done-icon {
    width: 64px; height: 64px; border-radius: 50%;
    background: white; border: 2px solid #a7f3d0;
    display: flex; align-items: center; justify-content: center;
    font-size: 26px; color: #10b981; margin: 0 auto 16px;
    box-shadow: 0 4px 12px rgba(16,185,129,.15);
}
.done-title { font-size: 18px; font-weight: 900; color: #065f46; margin-bottom: 8px; }
.done-text  { font-size: 12px; font-weight: 600; color: #047857; line-height: 1.7; max-width: 320px; margin: 0 auto; }

.report-form { display: flex; flex-direction: column; gap: 16px; }
.date-banner {
    background: #eff6ff; border: 1px solid #bfdbfe; border-radius: var(--radius-sm);
    padding: 14px 16px;
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    flex-wrap: wrap; gap: 12px;
}
.date-label  { font-size: 9px; font-weight: 900; color: #3b82f6; text-transform: uppercase; letter-spacing: .08em; margin-bottom: 4px; }
.date-value  { font-size: 15px; font-weight: 900; color: #1e40af; }
.date-input  {
    background: white; border: 1.5px solid #bfdbfe; border-radius: 10px;
    padding: 8px 12px; font-size: 12px; font-weight: 700; color: #1e40af;
    outline: none; font-family: inherit; cursor: pointer;
    transition: border-color .15s;
}
.date-input:focus { border-color: #3b82f6; }

.form-field {
    background: var(--surface-2); border: 1.5px solid var(--border);
    border-radius: var(--radius-sm); padding: 16px;
    transition: border-color .2s;
}
.form-field:focus-within { border-color: var(--accent); background: white; }
.form-field-head {
    display: flex; align-items: flex-start; gap: 10px; margin-bottom: 12px;
}
.form-field-icon {
    width: 28px; height: 28px; border-radius: 8px; flex-shrink: 0;
    background: var(--accent-light); color: var(--accent);
    display: flex; align-items: center; justify-content: center; font-size: 11px;
}
.form-field-name { font-size: 13px; font-weight: 800; color: var(--text-1); margin-bottom: 2px; }
.form-field-target { font-size: 10px; font-weight: 700; color: #d97706; display: flex; align-items: center; gap: 4px; }
.form-sub-fields { display: flex; flex-direction: column; gap: 10px; }
.sub-label {
    font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em;
    color: var(--text-3); margin-bottom: 6px;
    display: flex; align-items: center; gap: 4px;
}
.sub-label .req { color: #f43f5e; }

.f-input, .f-textarea {
    width: 100%; background: white; border: 1.5px solid var(--border);
    border-radius: 10px; padding: 10px 14px; font-size: 13px;
    font-family: inherit; color: var(--text-1); outline: none;
    transition: border-color .15s, box-shadow .15s;
    resize: vertical;
}
.f-input:focus, .f-textarea:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-light);
}
.f-input::placeholder, .f-textarea::placeholder { color: var(--text-4); }
.f-input-wrap { position: relative; }
.f-suffix, .f-prefix {
    position: absolute; top: 50%; transform: translateY(-50%);
    font-size: 13px; font-weight: 700; color: var(--text-4);
}
.f-suffix { right: 12px; }
.f-prefix { left: 12px; }
.f-input.has-suffix { padding-right: 36px; }
.f-input.has-prefix { padding-left: 36px; }
.f-input.number-style { font-weight: 900; font-size: 16px; color: var(--accent); }

.submit-btn {
    width: 100%; padding: 15px;
    background: linear-gradient(135deg, var(--accent), var(--accent)cc);
    color: white; border: none; border-radius: var(--radius-sm);
    font-size: 14px; font-weight: 900; font-family: inherit;
    cursor: pointer; letter-spacing: .03em;
    display: flex; align-items: center; justify-content: center; gap: 10px;
    box-shadow: 0 6px 20px var(--accent-mid);
    transition: transform .15s, box-shadow .15s, filter .15s;
}
.submit-btn:hover  { transform: translateY(-1px); box-shadow: 0 10px 28px var(--accent-mid); filter: brightness(1.06); }
.submit-btn:active { transform: scale(.98); }

/* ── HISTORY ── */
.history-list { display: flex; flex-direction: column; gap: 12px; }
.history-card {
    background: var(--surface-2); border: 1px solid var(--border);
    border-radius: var(--radius); overflow: hidden;
    transition: box-shadow .2s;
}
.history-card:hover { box-shadow: var(--shadow-sm); }
.history-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 16px; background: white; border-bottom: 1px solid var(--border-2);
    gap: 10px;
}
.history-date {
    display: flex; align-items: center; gap: 10px;
    font-size: 13px; font-weight: 800; color: var(--text-1);
}
.cal-icon {
    width: 34px; height: 34px; border-radius: 9px;
    background: #eff6ff; color: #3b82f6; border: 1px solid #bfdbfe;
    display: flex; align-items: center; justify-content: center; font-size: 14px;
}
.status-badge {
    font-size: 9px; font-weight: 900; text-transform: uppercase; letter-spacing: .07em;
    padding: 5px 10px; border-radius: 7px; border: 1px solid;
    display: flex; align-items: center; gap: 5px; flex-shrink: 0;
}
.status-pending  { background: #fffbeb; color: #b45309; border-color: #fde68a; }
.status-verified { background: #ecfdf5; color: #065f46; border-color: #a7f3d0; }
.status-rejected { background: #fff1f2; color: #9f1239; border-color: #fecdd3; }

.history-data { padding: 14px 16px; display: flex; flex-direction: column; gap: 8px; }
.history-row {
    display: flex; align-items: flex-start; gap: 10px;
    font-size: 11px; padding-bottom: 8px; border-bottom: 1px solid var(--border-2);
}
.history-row:last-child { border-bottom: none; padding-bottom: 0; }
.history-key   { font-weight: 700; color: var(--text-4); width: 110px; flex-shrink: 0; text-transform: capitalize; }
.history-val   { font-weight: 600; color: var(--text-2); flex: 1; word-break: break-word; }
.history-more  { font-size: 10px; font-weight: 800; color: var(--accent); text-align: center; padding-top: 4px; }

.admin-remark {
    margin: 0 16px 14px;
    padding: 10px 14px; border-radius: 9px;
    background: #f5f3ff; border: 1px solid #ddd6fe;
    font-size: 11px; font-weight: 600; color: #4c1d95;
    display: flex; gap: 8px; align-items: flex-start; line-height: 1.6;
}
.admin-remark-label {
    font-size: 8px; font-weight: 900; text-transform: uppercase; letter-spacing: .08em;
    color: #7c3aed; display: block; margin-bottom: 3px;
}

/* ── EVALUATIONS ── */
.eval-list { display: flex; flex-direction: column; gap: 14px; }
.eval-card {
    background: var(--surface-2); border: 1px solid var(--border);
    border-radius: var(--radius); overflow: hidden;
    border-left: 4px solid var(--accent);
    transition: box-shadow .2s;
}
.eval-card:hover { box-shadow: var(--shadow-sm); }
.eval-head {
    display: flex; align-items: center; gap: 16px;
    padding: 16px 18px; background: white; border-bottom: 1px solid var(--border-2);
}
.eval-ring-wrap {
    position: relative; width: 64px; height: 64px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
}
.eval-ring-wrap svg { transform: rotate(-90deg); }
.eval-ring-inner {
    position: absolute; inset: 0;
    display: flex; align-items: center; justify-content: center;
}
.eval-score { font-size: 16px; font-weight: 900; }
.eval-meta  { flex: 1; min-width: 0; }
.eval-badges { display: flex; flex-wrap: wrap; gap: 5px; margin-bottom: 6px; align-items: center; }
.eval-bonus { font-size: 15px; font-weight: 900; color: #059669; display: flex; align-items: center; gap: 5px; }
.eval-bonus-cap { font-size: 10px; font-weight: 600; color: var(--text-4); margin-top: 2px; }

.metric-scores-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 8px;
    padding: 14px 18px;
}
.mscore-box {
    background: white; border: 1px solid var(--border);
    border-radius: 9px; padding: 10px;
}
.mscore-name { font-size: 10px; font-weight: 700; color: var(--text-3); margin-bottom: 6px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mscore-row  { display: flex; align-items: center; gap: 8px; }
.mscore-val  { font-size: 15px; font-weight: 900; color: var(--text-1); width: 28px; flex-shrink: 0; }
.mscore-bar-track { flex: 1; height: 6px; background: #f1f5f9; border-radius: 99px; overflow: hidden; }
.mscore-bar-fill  { height: 100%; border-radius: 99px; background: linear-gradient(90deg, #60a5fa, #3b82f6); }

.eval-remark {
    margin: 0 18px 16px;
    padding: 10px 14px; border-radius: 9px;
    background: #fffbeb; border: 1px solid #fde68a;
    font-size: 11px; font-weight: 500; color: #78350f; line-height: 1.6;
    display: flex; gap: 8px; align-items: flex-start;
}

/* ── BOTTOM NAV ── */
.bottom-nav {
    display: none;
    position: fixed; bottom: 0; left: 0; right: 0;
    background: rgba(255,255,255,.96); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
    border-top: 1px solid var(--border);
    padding-bottom: env(safe-area-inset-bottom);
    z-index: 99;
}
@media (max-width: 767px) { .bottom-nav { display: flex; } }
.nav-item {
    flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center;
    padding: 10px 0; gap: 3px; text-decoration: none;
    font-size: 10px; font-weight: 700; color: var(--text-4);
    transition: color .15s;
}
.nav-item i { font-size: 19px; transition: transform .2s; }
.nav-item.active { color: var(--accent); }
.nav-item.active i { transform: translateY(-2px); }

/* ── LEVEL BADGE ── */
.level-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 12px; border-radius: 99px; border: 1.5px solid;
    font-size: 10px; font-weight: 900; letter-spacing: .05em;
    text-transform: uppercase;
}

/* ── STREAK MILESTONE BANNER ── */
.streak-milestone {
    padding: 12px 16px; border-radius: var(--radius-sm);
    border: 1.5px solid; font-size: 12px; font-weight: 800;
    display: flex; align-items: center; gap: 10px;
    animation: scaleIn .4s cubic-bezier(.22,.68,0,1.2) both;
}

/* ── ADMIN FEEDBACK HIGHLIGHT ── */
.admin-remark.urgent {
    background: #fff7ed; border-color: #fed7aa;
}
.admin-remark.urgent .admin-remark-label { color: #c2410c; }
.feedback-dot {
    width: 8px; height: 8px; border-radius: 50%;
    background: #f43f5e; display: inline-block;
    animation: pulse 1.6s ease-in-out infinite;
    margin-left: 4px;
}

/* ── MOTIVATIONAL CARD ── */
.motivation-card {
    background: linear-gradient(135deg, var(--accent)08, var(--accent)15);
    border: 1.5px solid var(--accent-border);
    border-radius: var(--radius-sm);
    padding: 14px 16px;
    display: flex; align-items: center; gap: 12px;
    font-size: 12px; font-weight: 700; color: var(--text-2);
    line-height: 1.6;
}
.motivation-icon {
    width: 38px; height: 38px; border-radius: 10px; flex-shrink: 0;
    background: var(--accent-light); color: var(--accent);
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
}

/* ── UTILITY ── */
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0,0,0,0); }
</style>
</head>
<body class="kpi-page">

<!-- ═══ HEADER ═══ -->
<header class="header anim-up">
    <div class="header-left">
        <a href="index.php" class="header-back" title="ড্যাশবোর্ড">
            <i class="fas fa-arrow-left" style="font-size:13px"></i>
        </a>
        <div>
            <div class="header-title">KPI প্যানেল</div>
            <div class="header-sub">Sodai Lagbe</div>
        </div>
    </div>
    <div class="header-right">
        <?php if(count($all_assignments) > 1): ?>
        <select class="role-select" onchange="window.location.href='?role='+this.value" title="পদ পরিবর্তন করুন">
            <?php foreach($all_assignments as $a): ?>
                <option value="<?= $a['id'] ?>" <?= $a['id']==$advisor_data['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars(explode(' ',$a['role_name'])[0]) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <button onclick="toggleTheme()" class="header-back theme-toggle-btn" style="border:1px solid var(--border)" title="Dark Mode এ যান" aria-label="Toggle theme">
            <i class="fas fa-moon" style="font-size:13px"></i>
        </button>
        <div class="avatar">
            <?php if(!empty($u_pic)): ?>
                <img src="<?= htmlspecialchars($u_pic) ?>" alt="<?= htmlspecialchars($uname) ?>">
            <?php else: ?>
                <i class="fas fa-user"></i>
            <?php endif; ?>
        </div>
    </div>
</header>

<!-- ═══ MAIN ═══ -->
<main class="main">

    <!-- Flash Messages -->
    <?php if($msg): ?>
    <div class="toast toast-success anim-up" role="alert">
        <i class="fas fa-check-circle"></i>
        <span><?= htmlspecialchars($msg) ?></span>
    </div>
    <?php endif; ?>
    <?php if($err): ?>
    <div class="toast toast-error anim-up" role="alert">
        <i class="fas fa-exclamation-circle"></i>
        <span><?= htmlspecialchars($err) ?></span>
    </div>
    <?php endif; ?>

    <!-- ── HERO CARD ── -->
    <div class="hero-card anim-up">
        <div class="hero-banner"></div>
        <div class="hero-body">
            <div class="hero-grid">
                <!-- Score Ring -->
                <div class="score-ring-wrap">
                    <svg width="88" height="88" viewBox="0 0 88 88">
                        <circle cx="44" cy="44" r="36" stroke="#f1f5f9" stroke-width="9" fill="none"/>
                        <circle cx="44" cy="44" r="36"
                            stroke="<?= $rc ?>" stroke-width="9" fill="none"
                            stroke-linecap="round"
                            class="score-ring"
                        />
                    </svg>
                    <div class="score-ring-inner">
                        <span class="score-value" style="color:<?= $rc ?>"><?= number_format($latest_score,0) ?></span>
                        <span class="score-denom">/100</span>
                    </div>
                </div>

                <!-- User Info -->
                <div>
                    <h2 class="hero-name"><?= htmlspecialchars($uname) ?></h2>
                    <div class="hero-badges">
                        <span class="badge-role">
                            <i class="fas <?= htmlspecialchars($role_icon) ?>"></i>
                            <?= htmlspecialchars($role_name) ?>
                        </span>
                        <?php if(!empty($advisor_data['department'])): ?>
                            <span class="badge-dept"><?= htmlspecialchars($advisor_data['department']) ?></span>
                        <?php endif; ?>
                        <?php if($latest_grade !== 'N/A'): ?>
                            <span class="badge-grade" style="background:<?= $grade_style['bg'] ?>;color:<?= $grade_style['color'] ?>;border-color:<?= $grade_style['border'] ?>">
                                <?= htmlspecialchars($grade_style['label']) ?>
                            </span>
                        <?php endif; ?>
                        <!-- Level Badge -->
                        <span class="level-badge" style="background:<?= $level_data['bg'] ?>;color:<?= $level_data['color'] ?>;border-color:<?= $level_data['border'] ?>">
                            <i class="fas <?= $level_data['icon'] ?>"></i>
                            <?= $level_data['name'] ?>
                        </span>
                        <?php if($unread_remarks > 0): ?>
                        <span class="badge-grade" style="background:#fff1f2;color:#e11d48;border-color:#fecdd3">
                            <i class="fas fa-comment-dots" style="margin-right:3px"></i>
                            <?= $unread_remarks ?> নতুন ফিডব্যাক
                            <span class="feedback-dot"></span>
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="hero-stats">
                        <div class="hero-stat">
                            <div class="hero-stat-label">সর্বোচ্চ বোনাস</div>
                            <div class="hero-stat-value" style="color:#059669">৳<?= number_format($max_bonus, 0) ?></div>
                        </div>
                        <div class="hero-stat">
                            <div class="hero-stat-label">অর্জিত বোনাস</div>
                            <div class="hero-stat-value" style="color:#d97706">৳<?= number_format($latest_bonus, 0) ?></div>
                        </div>
                        <div class="hero-stat">
                            <div class="hero-stat-label">রিপোর্ট স্ট্রিক</div>
                            <div class="hero-stat-value" style="color:#3b82f6">
                                <?= $streak ?> <span style="font-size:11px;font-weight:700;color:#94a3b8">দিন</span>
                                <?php if($streak > 0): ?><i class="fas fa-fire" style="font-size:13px;color:#f97316;margin-left:2px"></i><?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if(!empty($advisor_data['role_description'])): ?>
            <div class="role-desc" style="margin-top:18px">
                <i class="fas fa-info-circle"></i>
                <div><strong>দায়িত্ব:</strong> <?= htmlspecialchars($advisor_data['role_description']) ?></div>
            </div>
            <?php endif; ?>

            <?php if($streak_badge): ?>
            <div class="streak-milestone" style="margin:16px 24px 0;background:<?= $streak_badge['bg'] ?>;color:<?= $streak_badge['color'] ?>;border-color:<?= $streak_badge['border'] ?>">
                <span style="font-size:20px"><?= mb_substr($streak_badge['label'],0,2) ?></span>
                <div>
                    <div style="font-size:11px;font-weight:900"><?= htmlspecialchars(mb_substr($streak_badge['label'],3)) ?></div>
                    <div style="font-size:10px;font-weight:600;opacity:.8">টানা <?= $streak ?> দিন রিপোর্ট দিচ্ছেন — অসাধারণ ধারাবাহিকতা!</div>
                </div>
            </div>
            <?php endif; ?>

            <?php
            $motivations = [
                'Exceptional' => ['আপনি অসাধারণ পারফরম্যান্স দিচ্ছেন! এই ধারা বজায় রাখুন।', 'fa-star'],
                'Excellent'   => ['চমৎকার কাজ! আরও একটু চেষ্টা করলেই Exceptional লেভেলে পৌঁছাবেন।', 'fa-thumbs-up'],
                'Good'        => ['ভালো যাচ্ছেন! আজকের রিপোর্ট দিয়ে স্কোর আরও বাড়ান।', 'fa-chart-line'],
                'Average'     => ['একটু বেশি মনোযোগ দিন — আপনি আরও ভালো করতে পারবেন।', 'fa-fire'],
                'Needs Improvement' => ['এখনো সময় আছে। আজ থেকেই নতুন করে শুরু করুন!', 'fa-rocket'],
                'Poor'        => ['হতাশ হবেন না। প্রতিদিন ছোট ছোট পদক্ষেপ নিন।', 'fa-heart'],
            ];
            if($latest_grade !== 'N/A' && isset($motivations[$latest_grade])): $mot = $motivations[$latest_grade]; ?>
            <div class="motivation-card" style="margin:12px 24px 0">
                <div class="motivation-icon"><i class="fas <?= $mot[1] ?>"></i></div>
                <div><?= $mot[0] ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── MONTHLY PROGRESS CARD ── -->
    <div class="progress-card anim-up-d1">
        <div class="section-header">
            <div class="section-icon"><i class="fas fa-chart-bar"></i></div>
            <div class="section-title">চলতি মাসের অগ্রগতি ও সম্ভাব্য আয়</div>
        </div>
        <div class="stats-grid">
            <div class="stat-box">
                <div class="stat-box-label">ভেরিফাইড দিন</div>
                <div class="stat-box-value" style="color:#059669"><?= $verified_days ?></div>
                <div class="stat-box-sub">/ <?= $total_days_in_month ?> দিন</div>
            </div>
            <div class="stat-box">
                <div class="stat-box-label">কাজের গতি</div>
                <div class="stat-box-value" style="color:<?= $pacing_pct >= 80 ? '#059669' : ($pacing_pct >= 50 ? '#d97706' : '#e11d48') ?>">
                    <?= number_format($pacing_pct, 1) ?>%
                </div>
                <div class="stat-box-sub">Pacing</div>
            </div>
            <div class="stat-box">
                <div class="stat-box-label">দৈনিক আয়</div>
                <div class="stat-box-value" style="color:#3b82f6">৳<?= number_format($daily_potential_bonus, 0) ?></div>
                <div class="stat-box-sub">প্রতিদিন সম্ভাব্য</div>
            </div>
            <div class="stat-box highlight">
                <div class="stat-box-label">সম্ভাব্য মোট আয়</div>
                <div class="stat-box-value">৳<?= number_format($estimated_earned_bonus, 0) ?></div>
                <div class="stat-box-sub">এই মাসে অর্জিত</div>
            </div>
        </div>
        <div class="progress-bar-wrap">
            <div class="progress-bar-meta">
                <span class="progress-bar-label">মাসিক কাজের অগ্রগতি</span>
                <span class="progress-bar-pct"><?= number_format($work_progress_pct, 1) ?>%</span>
            </div>
            <div class="progress-track">
                <div class="progress-fill" style="width:<?= $work_progress_pct ?>%"></div>
            </div>
            <div class="progress-note">
                <i class="fas fa-info-circle" style="color:#3b82f6;font-size:11px"></i>
                ভেরিফাইকৃত রিপোর্টের ওপর ভিত্তি করে হিসাব করা হয়েছে
            </div>
        </div>
    </div>

    <!-- ── TABS CARD ── -->
    <div class="tabs-card anim-up-d2">
        <nav class="tabs-nav" role="tablist">
            <button class="tab-btn active" id="btn-targets" role="tab" onclick="switchTab('targets')" aria-selected="true">
                <i class="fas fa-bullseye"></i> আমার টার্গেট
                <span class="chip chip-score" style="border-radius:99px;margin-left:2px;padding:2px 6px"><?= count($metrics_raw) ?></span>
            </button>
            <button class="tab-btn" id="btn-report" role="tab" onclick="switchTab('report')" aria-selected="false" style="position:relative">
                <i class="fas fa-edit"></i> রিপোর্ট দিন
                <?php if(!$already_submitted_today): ?>
                    <span class="tab-dot pulse-dot"></span>
                <?php endif; ?>
            </button>
            <button class="tab-btn" id="btn-history" role="tab" onclick="switchTab('history')" aria-selected="false">
                <i class="fas fa-history"></i> ইতিহাস
                <span class="chip chip-score" style="border-radius:99px;margin-left:2px;padding:2px 6px"><?= count($daily_reports) ?></span>
            </button>
            <?php if(count($evaluations) > 0): ?>
            <button class="tab-btn" id="btn-evals" role="tab" onclick="switchTab('evals')" aria-selected="false">
                <i class="fas fa-award"></i> মূল্যায়ন
            </button>
            <?php endif; ?>
        </nav>

        <div class="tab-content">

            <!-- ─ TAB: TARGETS ─ -->
            <div id="tab-targets" class="tab-pane active" role="tabpanel">
                <?php if(!empty($metrics_raw)): ?>
                <div class="metric-list">
                    <?php foreach($metrics_raw as $m):
                        $subs      = json_decode($m['sub_fields'], true);
                        $my_target = $target_text[$m['id']] ?? null;
                        $mtype_icons = ['number'=>'fa-hashtag','percentage'=>'fa-percent','rating'=>'fa-star','boolean'=>'fa-toggle-on','text'=>'fa-align-left'];
                        $mtype_icon  = $mtype_icons[$m['measurement_type']??'number'] ?? 'fa-hashtag';
                    ?>
                    <div class="metric-card">
                        <div class="metric-card-head">
                            <div class="metric-icon">
                                <i class="fas <?= htmlspecialchars($mtype_icon) ?>"></i>
                            </div>
                            <div style="flex:1;min-width:0">
                                <div class="metric-name"><?= htmlspecialchars($m['metric_name']) ?></div>
                                <?php if(!empty($m['metric_description'])): ?>
                                    <div class="metric-desc"><?= htmlspecialchars($m['metric_description']) ?></div>
                                <?php endif; ?>
                                <div class="metric-chips">
                                    <span class="chip chip-score">Max: <?= $m['max_score'] ?> pts</span>
                                    <?php if(!empty($m['target_value'])): ?>
                                        <span class="chip chip-target"><i class="fas fa-bullseye" style="font-size:8px"></i> <?= htmlspecialchars($m['target_value']) ?></span>
                                    <?php endif; ?>
                                    <?php if(!empty($m['category'])): ?>
                                        <span class="chip" style="background:var(--accent-light);color:var(--accent);border-color:var(--accent-border)"><?= htmlspecialchars($m['category']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php if(!empty($my_target)): ?>
                        <div class="target-box">
                            <div class="target-box-label">
                                <i class="fas fa-check-circle"></i> আপনার জন্য নির্ধারিত লক্ষ্য
                            </div>
                            <?php if(is_array($my_target)): ?>
                                <div class="target-kv-grid">
                                    <?php foreach($my_target as $label => $val): ?>
                                    <div class="target-kv">
                                        <div class="target-kv-key"><?= htmlspecialchars($label) ?></div>
                                        <div class="target-kv-val"><?= htmlspecialchars(is_array($val) ? implode(', ',$val) : $val) ?></div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div style="background:white;border:1px solid #dbeafe;border-radius:9px;padding:12px 14px;font-size:13px;font-weight:600;color:#1e40af;line-height:1.6">
                                    <?= htmlspecialchars($my_target) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-tasks"></i>
                    <p>এই পদের জন্য এখনো কোনো KPI মেট্রিক্স সেট করা হয়নি।</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- ─ TAB: REPORT ─ -->
            <div id="tab-report" class="tab-pane" role="tabpanel">
                <?php if($already_submitted_today): ?>
                <div class="already-done">
                    <div class="done-icon"><i class="fas fa-check"></i></div>
                    <div class="done-title">আজকের রিপোর্ট জমা হয়েছে!</div>
                    <div class="done-text">
                        আপনি আজ (<?= date('d M, Y') ?>) তারিখের রিপোর্ট ইতিমধ্যে জমা দিয়েছেন।
                        অ্যাডমিন ভেরিফাইয়ের অপেক্ষায় আছে।
                        <?php if($streak > 0): ?>
                        <br><br>
                        <strong style="color:#059669">🔥 আপনার বর্তমান streak: <?= $streak ?> দিন!</strong><br>
                        কালকেও রিপোর্ট দিয়ে streak ধরে রাখুন।
                        <?php else: ?>
                        কাল আবার নতুন রিপোর্ট দিতে পারবেন।
                        <?php endif; ?>
                    </div>
                </div>
                <?php else: ?>
                <form method="POST" class="report-form" id="report-form">
                    <input type="hidden" name="action" value="submit_daily_update">
                    <input type="hidden" name="active_role" value="<?= htmlspecialchars($role_name) ?>">

                    <!-- Date row -->
                    <div class="date-banner">
                        <div>
                            <div class="date-label">রিপোর্টের তারিখ</div>
                            <div class="date-value"><?= date('d F, Y') ?></div>
                        </div>
                        <input type="date" name="report_date" value="<?= date('Y-m-d') ?>"
                            max="<?= date('Y-m-d') ?>" class="date-input" required>
                    </div>

                    <?php if(!empty($metrics_raw)):
                        foreach($metrics_raw as $m):
                            $subs      = json_decode($m['sub_fields'], true);
                            $field_key = strtolower(preg_replace('/[^a-z0-9]/i','_',$m['metric_name']));
                            $mtype_icons = ['number'=>'fa-hashtag','percentage'=>'fa-percent','rating'=>'fa-star','boolean'=>'fa-toggle-on','text'=>'fa-align-left'];
                            $mtype_icon  = $mtype_icons[$m['measurement_type']??'number'] ?? 'fa-hashtag';
                    ?>
                    <div class="form-field">
                        <div class="form-field-head">
                            <div class="form-field-icon"><i class="fas <?= htmlspecialchars($mtype_icon) ?>"></i></div>
                            <div>
                                <div class="form-field-name"><?= htmlspecialchars($m['metric_name']) ?></div>
                                <?php if(!empty($m['target_value'])): ?>
                                <div class="form-field-target"><i class="fas fa-flag" style="font-size:9px"></i> লক্ষ্য: <?= htmlspecialchars($m['target_value']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if(is_array($subs) && count($subs) > 0): ?>
                        <div class="form-sub-fields">
                            <?php foreach($subs as $sf):
                                $sf_key = $field_key.'__'.strtolower(preg_replace('/[^a-z0-9]/i','_',$sf));
                                $is_textarea = stripos($sf,'plan')!==false || stripos($sf,'result')!==false || stripos($sf,'পরিকল্পনা')!==false || stripos($sf,'ফলাফল')!==false;
                            ?>
                            <div>
                                <div class="sub-label"><?= htmlspecialchars($sf) ?> <span class="req">*</span></div>
                                <?php if($is_textarea): ?>
                                    <textarea name="daily_data[<?= htmlspecialchars($sf_key) ?>]" rows="2"
                                        class="f-textarea"
                                        placeholder="<?= htmlspecialchars($sf) ?> লিখুন..." required></textarea>
                                <?php else: ?>
                                    <input type="text" name="daily_data[<?= htmlspecialchars($sf_key) ?>]"
                                        class="f-input"
                                        placeholder="<?= htmlspecialchars($sf) ?>" required>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else:
                            $ptype = $m['measurement_type'] ?? 'text';
                        ?>
                        <div>
                            <?php if($ptype === 'number'): ?>
                                <div class="sub-label">মান লিখুন <span class="req">*</span></div>
                                <input type="number" name="daily_data[<?= htmlspecialchars($field_key) ?>]"
                                    class="f-input number-style"
                                    placeholder="সংখ্যা লিখুন" required>
                            <?php elseif($ptype === 'percentage'): ?>
                                <div class="sub-label">শতকরা হার <span class="req">*</span></div>
                                <div class="f-input-wrap" style="max-width:200px">
                                    <input type="number" name="daily_data[<?= htmlspecialchars($field_key) ?>]"
                                        class="f-input number-style has-suffix"
                                        placeholder="0–100" min="0" max="100" required>
                                    <span class="f-suffix">%</span>
                                </div>
                            <?php elseif($ptype === 'rating'): ?>
                                <div class="sub-label">রেটিং দিন (১–১০) <span class="req">*</span></div>
                                <div class="f-input-wrap" style="max-width:200px">
                                    <input type="number" name="daily_data[<?= htmlspecialchars($field_key) ?>]"
                                        class="f-input number-style has-prefix"
                                        placeholder="1–10" min="1" max="10" required>
                                    <span class="f-prefix" style="color:#f59e0b"><i class="fas fa-star"></i></span>
                                </div>
                            <?php else: ?>
                                <div class="sub-label">বিস্তারিত লিখুন <span class="req">*</span></div>
                                <textarea name="daily_data[<?= htmlspecialchars($field_key) ?>]" rows="2"
                                    class="f-textarea"
                                    placeholder="বিস্তারিত লিখুন..." required></textarea>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach;
                    else: ?>
                    <!-- Fallback generic fields -->
                    <div class="form-field">
                        <div class="sub-label">আজকের কাজের বিবরণ <span class="req">*</span></div>
                        <textarea name="daily_data[description]" rows="3" class="f-textarea"
                            placeholder="আজকে আপনার টার্গেট পূরণে কী কী কাজ করেছেন তা বিস্তারিত লিখুন..." required></textarea>
                    </div>
                    <div class="form-field">
                        <div class="sub-label">অগ্রগতি (Progress) <span class="req">*</span></div>
                        <textarea name="daily_data[progress]" rows="2" class="f-textarea"
                            placeholder="কোন লক্ষ্যে কতটুকু এগিয়েছেন..." required></textarea>
                    </div>
                    <div class="form-field">
                        <div class="sub-label">চ্যালেঞ্জ / সমস্যা</div>
                        <textarea name="daily_data[challenges]" rows="2" class="f-textarea"
                            placeholder="আজকে কোনো সমস্যায় পড়েছেন কি..."></textarea>
                    </div>
                    <?php endif; ?>

                    <button type="submit" class="submit-btn">
                        <i class="fas fa-paper-plane"></i>
                        রিপোর্ট জমা দিন
                    </button>
                </form>
                <?php endif; ?>
            </div>

            <!-- ─ TAB: HISTORY ─ -->
            <div id="tab-history" class="tab-pane" role="tabpanel">
                <?php if(count($daily_reports) > 0): ?>
                <div class="history-list">
                    <?php foreach($daily_reports as $dr):
                        $data3   = json_decode($dr['update_data'], true) ?: [];
                        $st      = $dr['status'];
                        $sc_cls  = $st==='verified' ? 'status-verified' : ($st==='rejected' ? 'status-rejected' : 'status-pending');
                        $sc_icon = $st==='verified' ? 'fa-check-circle' : ($st==='rejected' ? 'fa-times-circle' : 'fa-clock');
                        $st_label = $st==='verified' ? 'Verified' : ($st==='rejected' ? 'Rejected' : 'Pending');
                    ?>
                    <div class="history-card">
                        <div class="history-head">
                            <div class="history-date">
                                <div class="cal-icon"><i class="far fa-calendar-alt"></i></div>
                                <?= date('d M, Y', strtotime($dr['report_date'])) ?>
                            </div>
                            <span class="status-badge <?= $sc_cls ?>">
                                <i class="fas <?= $sc_icon ?>"></i> <?= $st_label ?>
                            </span>
                        </div>
                        <div class="history-data">
                            <?php $cnt=0; foreach($data3 as $k => $v): if(++$cnt > 4) break; ?>
                            <div class="history-row">
                                <span class="history-key"><?= htmlspecialchars(str_replace(['_','__'],' ',explode('__',$k)[0])) ?></span>
                                <span class="history-val"><?= htmlspecialchars(mb_strimwidth(is_array($v) ? implode(', ',$v) : (string)$v, 0, 80, '…')) ?></span>
                            </div>
                            <?php endforeach; ?>
                            <?php if(count($data3) > 4): ?>
                            <div class="history-more">+<?= count($data3)-4 ?> আরও ফিল্ড…</div>
                            <?php endif; ?>
                        </div>
                        <?php if(!empty($dr['admin_remarks'])): ?>
                        <div class="admin-remark <?= $dr['status']==='rejected' ? 'urgent' : '' ?>">
                            <i class="fas <?= $dr['status']==='rejected' ? 'fa-exclamation-circle' : 'fa-reply' ?>" style="margin-top:2px;opacity:.7"></i>
                            <div>
                                <span class="admin-remark-label">
                                    <?= $dr['status']==='rejected' ? '⚠️ অ্যাডমিন মন্তব্য (Rejected)' : '✅ অ্যাডমিন ফিডব্যাক' ?>
                                </span>
                                <?= htmlspecialchars($dr['admin_remarks']) ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-file-alt"></i>
                    <p>আপনি এখনো কোনো রিপোর্ট জমা দেননি।</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- ─ TAB: EVALUATIONS ─ -->
            <?php if(count($evaluations) > 0): ?>
            <div id="tab-evals" class="tab-pane" role="tabpanel">
                <div class="eval-list">
                    <?php foreach($evaluations as $ev):
                        $gc2     = strtolower(str_replace(' ','_',$ev['performance_grade']??''));
                        $gc_s2   = $grade_map[$gc2] ?? ['label'=>$ev['performance_grade']??'N/A','bg'=>'#f8fafc','color'=>'#64748b','border'=>'#e2e8f0'];
                        $ev_bonus= $max_bonus * ($ev['total_score']/100);
                        $mdata4  = json_decode($ev['metrics_data'], true) ?: [];
                        $sp2     = min(100, max(0, $ev['total_score']));
                        $circ2   = 2*M_PI*24; $off2 = $circ2 - ($sp2/100*$circ2);
                        $rc2     = $ev['total_score']>=75 ? '#10b981' : ($ev['total_score']>=60 ? '#3b82f6' : ($ev['total_score']>=40 ? '#f59e0b' : '#f43f5e'));
                        $ev_month_ts = strtotime($ev['eval_month'].'-01');
                    ?>
                    <div class="eval-card">
                        <div class="eval-head">
                            <!-- Mini Ring -->
                            <div class="eval-ring-wrap">
                                <svg width="64" height="64" viewBox="0 0 64 64">
                                    <circle cx="32" cy="32" r="24" stroke="#f1f5f9" stroke-width="6" fill="none"/>
                                    <circle cx="32" cy="32" r="24"
                                        stroke="<?= $rc2 ?>" stroke-width="6" fill="none"
                                        stroke-linecap="round"
                                        stroke-dasharray="<?= $circ2 ?>"
                                        stroke-dashoffset="<?= $ev['total_score']>0 ? $off2 : $circ2 ?>"/>
                                </svg>
                                <div class="eval-ring-inner">
                                    <span class="eval-score" style="color:<?= $rc2 ?>"><?= number_format($ev['total_score'],0) ?></span>
                                </div>
                            </div>
                            <!-- Meta -->
                            <div class="eval-meta">
                                <div class="eval-badges">
                                    <span class="badge-grade" style="background:<?= $gc_s2['bg'] ?>;color:<?= $gc_s2['color'] ?>;border-color:<?= $gc_s2['border'] ?>">
                                        <?= htmlspecialchars($gc_s2['label']) ?>
                                    </span>
                                    <span class="badge-dept">
                                        <i class="far fa-calendar-alt" style="margin-right:3px"></i>
                                        <?= $ev_month_ts ? date('F Y', $ev_month_ts) : htmlspecialchars($ev['eval_month']) ?>
                                    </span>
                                </div>
                                <div class="eval-bonus">
                                    <i class="fas fa-coins" style="color:#f59e0b;font-size:14px"></i>
                                    ৳<?= number_format($ev_bonus,0) ?> বোনাস অর্জিত
                                </div>
                                <div class="eval-bonus-cap">সর্বোচ্চ: ৳<?= number_format($max_bonus,0) ?></div>
                            </div>
                        </div>

                        <?php if(is_array($mdata4) && count($mdata4)): ?>
                        <div class="metric-scores-grid">
                            <?php foreach($mdata4 as $mid2 => $sc2):
                                $mn2 = 'Metric'; $mx2 = 100;
                                foreach($metrics_raw as $raw) { if($raw['id']==$mid2){ $mn2=$raw['metric_name']; $mx2=$raw['max_score']; break; } }
                                $pct2 = ($mx2 > 0) ? round(($sc2/$mx2)*100) : 0;
                            ?>
                            <div class="mscore-box">
                                <div class="mscore-name" title="<?= htmlspecialchars($mn2) ?>"><?= htmlspecialchars($mn2) ?></div>
                                <div class="mscore-row">
                                    <span class="mscore-val"><?= $sc2 ?></span>
                                    <div class="mscore-bar-track">
                                        <div class="mscore-bar-fill" style="width:<?= $pct2 ?>%"></div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <?php if(!empty($ev['remarks'])): ?>
                        <div class="eval-remark">
                            <i class="fas fa-quote-left" style="color:#d97706;margin-top:2px;flex-shrink:0"></i>
                            <div><?= nl2br(htmlspecialchars($ev['remarks'])) ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /.tab-content -->
    </div><!-- /.tabs-card -->

</main>

<!-- ═══ BOTTOM NAV ═══ -->
<nav class="bottom-nav" role="navigation" aria-label="মোবাইল নেভিগেশন">
    <a href="index.php" class="nav-item">
        <i class="fas fa-home"></i>
        <span>Home</span>
    </a>
    <a href="transactions.php" class="nav-item">
        <i class="fas fa-exchange-alt"></i>
        <span>লেনদেন</span>
    </a>
    <a href="user_kpi.php" class="nav-item active">
        <i class="fas fa-bullseye"></i>
        <span>KPI</span>
    </a>
    <a href="user_votes.php" class="nav-item">
        <i class="fas fa-vote-yea"></i>
        <span>ভোট</span>
    </a>
</nav>

<script>
    const ACCENT = '<?= addslashes($role_color ?? '#3b82f6') ?>';

    function switchTab(name) {
        // Hide all panes
        document.querySelectorAll('.tab-pane').forEach(p => {
            p.classList.remove('active');
        });
        // Reset all buttons
        document.querySelectorAll('.tab-btn').forEach(b => {
            b.classList.remove('active');
            b.setAttribute('aria-selected', 'false');
            b.style.color = '';
            b.style.borderBottomColor = '';
            b.style.background = '';
        });

        // Show target pane
        const pane = document.getElementById('tab-' + name);
        if (pane) pane.classList.add('active');

        // Activate button
        const btn = document.getElementById('btn-' + name);
        if (btn) {
            btn.classList.add('active');
            btn.setAttribute('aria-selected', 'true');
            btn.style.color = ACCENT;
            btn.style.borderBottomColor = ACCENT;
            btn.style.background = ACCENT + '18';
        }

        history.replaceState(null, null, '#' + name);
    }

    // Restore tab from URL hash
    window.addEventListener('DOMContentLoaded', () => {
        const hash = location.hash.replace('#', '');
        const valid = ['targets', 'report', 'history', 'evals'];
        switchTab(valid.includes(hash) ? hash : 'targets');
    });

    // Auto-dismiss toasts after 4s
    document.querySelectorAll('.toast').forEach(t => {
        setTimeout(() => {
            t.style.transition = 'opacity .4s, transform .4s';
            t.style.opacity = '0'; t.style.transform = 'translateY(-6px)';
            setTimeout(() => t.remove(), 400);
        }, 4000);
    });
</script>
<script src="theme.js"></script>
</body>
</html>
