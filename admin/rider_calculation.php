<?php
ob_start();
ini_set('session.cookie_lifetime', 86400);
ini_set('session.gc_maxlifetime', 86400);
session_set_cookie_params(86400);
session_start();
date_default_timezone_set('Asia/Dhaka');

if (!isset($_SESSION['admin_logged_in'])) { header("Location: login.php"); exit; }
if (isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'staff') {
    $perms = $_SESSION['staff_permissions'] ?? [];
    if (!in_array('dashboard', $perms))
        die("<div style='text-align:center;padding:50px;font-family:sans-serif'><h2 style='color:red'>Access Denied</h2><a href='login.php'>লগআউট</a></div>");
}
require_once 'db.php';

// ── Table & Column Setup ─────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `riders` (
        `id`                  INT AUTO_INCREMENT PRIMARY KEY,
        `name`                VARCHAR(100) NOT NULL,
        `phone`               VARCHAR(20)  DEFAULT '',
        `monthly_salary`      DECIMAL(10,2) NOT NULL DEFAULT 0,
        `commission_per_order` DECIMAL(10,2) NOT NULL DEFAULT 0,
        `join_date`           DATE NULL,
        `status`              ENUM('active','inactive') DEFAULT 'active',
        `created_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Add commission_per_order if it doesn't exist yet
    $chk = $pdo->query("SHOW COLUMNS FROM `riders` LIKE 'commission_per_order'");
    if ($chk && $chk->rowCount() === 0)
        $pdo->exec("ALTER TABLE `riders` ADD COLUMN `commission_per_order` DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER `monthly_salary`");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `rider_payments` (
        `id`           INT AUTO_INCREMENT PRIMARY KEY,
        `rider_id`     INT NOT NULL,
        `amount`       DECIMAL(10,2) NOT NULL,
        `payment_date` DATE NOT NULL,
        `notes`        VARCHAR(255) DEFAULT '',
        `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `rider_orders` (
        `id`          INT AUTO_INCREMENT PRIMARY KEY,
        `rider_id`    INT NOT NULL,
        `order_count` INT NOT NULL DEFAULT 0,
        `record_date` DATE NOT NULL,
        `notes`       VARCHAR(255) DEFAULT '',
        `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {}

$message = ''; $error = '';

// ── POST Handlers ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'add_rider') {
        $name  = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $sal   = (float)($_POST['monthly_salary'] ?? 0);
        $cpo   = (float)($_POST['commission_per_order'] ?? 0);
        $join  = $_POST['join_date'] ?: null;
        if ($name !== '') {
            $pdo->prepare("INSERT INTO riders (name,phone,monthly_salary,commission_per_order,join_date) VALUES (?,?,?,?,?)")
                ->execute([$name,$phone,$sal,$cpo,$join]);
            $message = "রাইডার '$name' যোগ করা হয়েছে।";
        } else { $error = "নাম আবশ্যক।"; }
    }

    elseif ($_POST['action'] === 'update_salary') {
        $rid = (int)$_POST['rider_id'];
        $sal = (float)$_POST['monthly_salary'];
        $cpo = (float)$_POST['commission_per_order'];
        $pdo->prepare("UPDATE riders SET monthly_salary=?, commission_per_order=? WHERE id=?")
            ->execute([$sal, $cpo, $rid]);
        $message = "রাইডারের তথ্য আপডেট হয়েছে।";
    }

    elseif ($_POST['action'] === 'add_payment') {
        $rid    = (int)$_POST['rider_id'];
        $amount = (float)$_POST['amount'];
        $date   = $_POST['payment_date'];
        $notes  = trim($_POST['notes'] ?? '');
        if ($rid && $amount > 0 && $date) {
            $pdo->prepare("INSERT INTO rider_payments (rider_id,amount,payment_date,notes) VALUES (?,?,?,?)")
                ->execute([$rid,$amount,$date,$notes]);
            $message = "পেমেন্ট যোগ করা হয়েছে।";
        } else { $error = "সঠিক তথ্য দিন।"; }
    }

    elseif ($_POST['action'] === 'delete_payment') {
        $pdo->prepare("DELETE FROM rider_payments WHERE id=?")->execute([(int)$_POST['payment_id']]);
        $message = "পেমেন্ট মুছে ফেলা হয়েছে।";
    }

    elseif ($_POST['action'] === 'add_orders') {
        $rid   = (int)$_POST['rider_id'];
        $cnt   = (int)$_POST['order_count'];
        $date  = $_POST['record_date'];
        $notes = trim($_POST['notes'] ?? '');
        if ($rid && $cnt > 0 && $date) {
            $pdo->prepare("INSERT INTO rider_orders (rider_id,order_count,record_date,notes) VALUES (?,?,?,?)")
                ->execute([$rid,$cnt,$date,$notes]);
            $message = "$cnt টি অর্ডার যোগ করা হয়েছে।";
        } else { $error = "সঠিক অর্ডার সংখ্যা দিন।"; }
    }

    elseif ($_POST['action'] === 'delete_order_record') {
        $pdo->prepare("DELETE FROM rider_orders WHERE id=?")->execute([(int)$_POST['order_id']]);
        $message = "অর্ডার রেকর্ড মুছে ফেলা হয়েছে।";
    }

    elseif ($_POST['action'] === 'delete_rider') {
        $pdo->prepare("UPDATE riders SET status='inactive' WHERE id=?")->execute([(int)$_POST['rider_id']]);
        $message = "রাইডার নিষ্ক্রিয় করা হয়েছে।";
    }

    $ts = time();
    if ($message)
        header("Location: rider_calculation.php?msg=".urlencode($message)."&t=$ts");
    elseif ($error)
        header("Location: rider_calculation.php?err=".urlencode($error)."&t=$ts");
    else
        header("Location: rider_calculation.php?t=$ts");
    exit;
}

if (isset($_GET['msg'])) $message = htmlspecialchars($_GET['msg']);
if (isset($_GET['err'])) $error   = htmlspecialchars($_GET['err']);

// ── Date Helpers ──────────────────────────────────────────────
$cur_year        = (int)date('Y');
$cur_month       = (int)date('m');
$cur_month_label = date('F Y');
$week_start      = date('Y-m-d', strtotime('monday this week'));
$week_end        = date('Y-m-d', strtotime('sunday this week'));

// ── Fetch Riders ──────────────────────────────────────────────
$riders_raw = $pdo->query("SELECT * FROM riders WHERE status='active' ORDER BY name ASC")->fetchAll();

// Monthly payment totals
$q = $pdo->prepare("SELECT rider_id, SUM(amount) as t FROM rider_payments WHERE YEAR(payment_date)=? AND MONTH(payment_date)=? GROUP BY rider_id");
$q->execute([$cur_year, $cur_month]);
$monthly_pay_map = $q->fetchAll(PDO::FETCH_KEY_PAIR);

// All-time payment totals
$alltime_pay_map = $pdo->query("SELECT rider_id, SUM(amount) as t FROM rider_payments GROUP BY rider_id")->fetchAll(PDO::FETCH_KEY_PAIR);

// Weekly payment totals
$q = $pdo->prepare("SELECT rider_id, SUM(amount) as t FROM rider_payments WHERE payment_date BETWEEN ? AND ? GROUP BY rider_id");
$q->execute([$week_start, $week_end]);
$weekly_pay_map = $q->fetchAll(PDO::FETCH_KEY_PAIR);

// Monthly order totals
$q = $pdo->prepare("SELECT rider_id, SUM(order_count) as t FROM rider_orders WHERE YEAR(record_date)=? AND MONTH(record_date)=? GROUP BY rider_id");
$q->execute([$cur_year, $cur_month]);
$monthly_ord_map = $q->fetchAll(PDO::FETCH_KEY_PAIR);

// Weekly order totals
$q = $pdo->prepare("SELECT rider_id, SUM(order_count) as t FROM rider_orders WHERE record_date BETWEEN ? AND ? GROUP BY rider_id");
$q->execute([$week_start, $week_end]);
$weekly_ord_map = $q->fetchAll(PDO::FETCH_KEY_PAIR);

// All-time order totals
$alltime_ord_map = $pdo->query("SELECT rider_id, SUM(order_count) as t FROM rider_orders GROUP BY rider_id")->fetchAll(PDO::FETCH_KEY_PAIR);

// Payment history
$pay_hist_raw = $pdo->query("SELECT * FROM rider_payments ORDER BY payment_date DESC, id DESC")->fetchAll();
$pay_hist = [];
foreach ($pay_hist_raw as $h) $pay_hist[$h['rider_id']][] = $h;

// Order history
$ord_hist_raw = $pdo->query("SELECT * FROM rider_orders ORDER BY record_date DESC, id DESC")->fetchAll();
$ord_hist = [];
foreach ($ord_hist_raw as $h) $ord_hist[$h['rider_id']][] = $h;

// ── Build Rider Data ──────────────────────────────────────────
$riders = [];
foreach ($riders_raw as $r) {
    $id     = $r['id'];
    $salary = (float)$r['monthly_salary'];
    $cpo    = (float)$r['commission_per_order'];

    $paid_month   = (float)($monthly_pay_map[$id]  ?? 0);
    $paid_alltime = (float)($alltime_pay_map[$id]  ?? 0);
    $paid_week    = (float)($weekly_pay_map[$id]   ?? 0);

    $orders_month   = (int)($monthly_ord_map[$id]  ?? 0);
    $orders_week    = (int)($weekly_ord_map[$id]   ?? 0);
    $orders_alltime = (int)($alltime_ord_map[$id]  ?? 0);

    $monthly_commission = $orders_month * $cpo;
    $total_earnings     = $salary + $monthly_commission;

    $weekly_limit  = $salary > 0 ? $salary / 4 : 0;
    $weekly_remain = max(0, $weekly_limit - $paid_week);
    $weekly_extra  = max(0, $paid_week - $weekly_limit);

    // Salary progress (out of salary alone)
    $sal_pct  = $salary > 0 ? min(100, ($paid_month / $salary) * 100) : 0;
    // Total earnings progress (paid vs salary+commission)
    $earn_pct = $total_earnings > 0 ? min(100, ($paid_month / $total_earnings) * 100) : 0;

    $remaining_month = max(0, $total_earnings - $paid_month);

    $riders[] = array_merge($r, [
        'paid_month'         => $paid_month,
        'paid_alltime'       => $paid_alltime,
        'paid_week'          => $paid_week,
        'orders_month'       => $orders_month,
        'orders_week'        => $orders_week,
        'orders_alltime'     => $orders_alltime,
        'monthly_commission' => $monthly_commission,
        'total_earnings'     => $total_earnings,
        'weekly_limit'       => $weekly_limit,
        'weekly_remain'      => $weekly_remain,
        'weekly_extra'       => $weekly_extra,
        'sal_pct'            => $sal_pct,
        'earn_pct'           => $earn_pct,
        'remaining_month'    => $remaining_month,
        'pay_history'        => $pay_hist[$id] ?? [],
        'ord_history'        => $ord_hist[$id] ?? [],
    ]);
}

// ── Summary Stats ─────────────────────────────────────────────
$sum_salary      = array_sum(array_column($riders, 'monthly_salary'));
$sum_paid_month  = array_sum(array_column($riders, 'paid_month'));
$sum_commission  = array_sum(array_column($riders, 'monthly_commission'));
$sum_earnings    = array_sum(array_column($riders, 'total_earnings'));
$sum_remaining   = array_sum(array_column($riders, 'remaining_month'));
$sum_orders      = array_sum(array_column($riders, 'orders_month'));
$avg_earn_pct    = count($riders) > 0 ? array_sum(array_column($riders, 'earn_pct')) / count($riders) : 0;

// Site logo/favicon
try {
    $ss = $pdo->query("SELECT setting_name, setting_value FROM system_settings WHERE setting_name IN ('site_logo','site_favicon')")->fetchAll(PDO::FETCH_KEY_PAIR);
    $site_logo = $ss['site_logo'] ?? ''; $site_favicon = $ss['site_favicon'] ?? '';
} catch(Exception $e){ $site_logo=''; $site_favicon=''; }
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>রাইডার ক্যালকুলেশন – Admin</title>
<?php if(!empty($site_favicon)): ?><link rel="icon" href="../<?= htmlspecialchars($site_favicon) ?>"><?php endif; ?>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap');
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Plus Jakarta Sans',sans-serif;background:#f0f4f8;-webkit-tap-highlight-color:transparent}
.glass-header{background:rgba(255,255,255,.94);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);border-bottom:1px solid rgba(226,232,240,.9)}
.app-card{background:#fff;border-radius:20px;box-shadow:0 1px 4px rgba(0,0,0,.04),0 6px 24px rgba(0,0,0,.05);border:1px solid rgba(226,232,240,.75);transition:box-shadow .25s,transform .25s}
.app-card:hover{box-shadow:0 4px 28px rgba(0,0,0,.09)}
.custom-scrollbar::-webkit-scrollbar{width:4px;height:4px}
.custom-scrollbar::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:10px}

/* Sidebar */
.sidebar-link{display:flex;align-items:center;gap:10px;padding:9px 16px;margin:1px 10px;border-radius:11px;font-size:13px;font-weight:600;color:rgba(255,255,255,.58);transition:all .18s ease;text-decoration:none}
.sidebar-link:hover{background:rgba(255,255,255,.09);color:rgba(255,255,255,.92);padding-left:20px}
.sidebar-link.active{background:rgba(99,102,241,.28);color:#fff;border-left:3px solid #818cf8;margin-left:8px;padding-left:18px}
.sidebar-link i{width:16px;text-align:center;font-size:13px;flex-shrink:0;opacity:.85}
.sidebar-section{padding:14px 20px 5px;font-size:9px;font-weight:800;color:rgba(100,116,139,.75);text-transform:uppercase;letter-spacing:.14em}

/* Progress bars — animated via JS */
.prog-track{height:9px;background:#e2e8f0;border-radius:99px;overflow:hidden;position:relative}
.prog-track.slim{height:5px}
.prog-fill{height:100%;border-radius:99px;width:0%;transition:width 1.1s cubic-bezier(.16,1,.3,1)}

/* Bottom nav */
.bottom-nav{position:fixed;bottom:0;left:0;right:0;background:rgba(255,255,255,.97);backdrop-filter:blur(14px);border-top:1px solid rgba(0,0,0,.06);padding-bottom:env(safe-area-inset-bottom);z-index:50;display:flex;justify-content:space-around}
.nav-item{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:10px 0;color:#64748b;font-size:10px;font-weight:700;width:100%;transition:all .2s;cursor:pointer;text-decoration:none}
.nav-item.active{color:#4f46e5}
.nav-item i{font-size:18px;margin-bottom:3px}
@media(min-width:768px){.bottom-nav{display:none}}

@keyframes fadeInUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
.fade-up{animation:fadeInUp .4s ease-out forwards}

.badge-green{background:rgba(16,185,129,.12);color:#059669;border:1px solid rgba(16,185,129,.25)}
.badge-amber{background:rgba(245,158,11,.12);color:#d97706;border:1px solid rgba(245,158,11,.25)}
.badge-red{background:rgba(239,68,68,.12);color:#dc2626;border:1px solid rgba(239,68,68,.25)}
.badge-blue{background:rgba(99,102,241,.12);color:#4f46e5;border:1px solid rgba(99,102,241,.25)}

.tbl-head th{background:#1e293b;color:#fff;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;padding:10px 14px}
.tbl-row td{padding:10px 14px;border-bottom:1px solid #f1f5f9;font-size:13px;vertical-align:middle}
.tbl-row:last-child td{border-bottom:none}
.tbl-row:nth-child(even) td{background:#f8fafc}
.tbl-row:hover td{background:#eef2ff}

/* Section tabs inside rider card */
.rtab-btn{padding:7px 14px;font-size:11px;font-weight:700;border-radius:10px;cursor:pointer;border:1px solid transparent;transition:all .15s;color:#64748b;background:transparent}
.rtab-btn.on{background:#eef2ff;color:#4f46e5;border-color:#c7d2fe}
</style>
</head>
<body class="antialiased text-slate-800">

<div id="sidebar-overlay" class="fixed inset-0 bg-slate-900/60 z-40 hidden md:hidden backdrop-blur-sm" onclick="toggleSidebar()"></div>

<!-- ═══ SIDEBAR ═══ -->
<aside id="sidebar" class="fixed inset-y-0 left-0 text-white w-64 flex flex-col transition-transform transform -translate-x-full md:translate-x-0 z-50 shadow-2xl h-full" style="background:linear-gradient(175deg,#0f172a 0%,#1a1040 55%,#0f172a 100%)">
    <div class="flex items-center gap-3 h-[68px] px-5 shrink-0" style="border-bottom:1px solid rgba(255,255,255,.06)">
        <div class="w-9 h-9 rounded-xl flex items-center justify-center shadow-lg shrink-0 overflow-hidden" style="background:linear-gradient(135deg,#6366f1,#4f46e5)">
            <?php if(!empty($site_logo)): ?><img src="../<?= htmlspecialchars($site_logo) ?>" class="w-full h-full object-contain p-0.5"><?php else: ?><i class="fas fa-user-shield text-white text-sm"></i><?php endif; ?>
        </div>
        <div>
            <div class="text-sm font-black text-white leading-tight">Admin Panel</div>
            <div class="text-[10px] font-semibold" style="color:rgba(165,180,252,.7)">Sodai Lagbe ERP</div>
        </div>
    </div>
    <nav class="flex-1 overflow-y-auto py-3 custom-scrollbar">
        <div class="sidebar-section">Core</div>
        <a href="index.php" class="sidebar-link"><i class="fas fa-tachometer-alt"></i> ড্যাশবোর্ড</a>
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
        <a href="rider_calculation.php" class="sidebar-link active"><i class="fas fa-motorcycle"></i> রাইডার ক্যালকুলেশন</a>
    </nav>
    <div class="p-4 shrink-0" style="border-top:1px solid rgba(255,255,255,.06)">
        <div class="flex items-center gap-3 px-2 mb-3">
            <div class="w-8 h-8 rounded-lg flex items-center justify-center text-white font-black text-sm shrink-0" style="background:linear-gradient(135deg,#6366f1,#4f46e5)"><?= strtoupper(substr($_SESSION['admin_username']??'A',0,1)) ?></div>
            <div class="min-w-0">
                <div class="text-xs font-bold text-white truncate"><?= htmlspecialchars($_SESSION['admin_username']??'Admin') ?></div>
                <div class="text-[9px] font-medium" style="color:rgba(148,163,184,.7)">System Admin</div>
            </div>
        </div>
        <a href="logout.php" class="sidebar-link" style="color:rgba(248,113,113,.85);margin:0 4px"><i class="fas fa-sign-out-alt"></i> লগআউট</a>
    </div>
</aside>

<!-- ═══ MAIN WRAPPER ═══ -->
<div class="flex flex-col min-h-screen w-full md:pl-64">

    <!-- Header -->
    <header class="glass-header sticky top-0 z-30 px-4 py-3 flex items-center justify-between h-16 shrink-0">
        <div class="flex items-center gap-3">
            <button onclick="toggleSidebar()" class="text-slate-600 md:hidden text-xl hover:text-indigo-600 transition"><i class="fas fa-bars"></i></button>
            <div>
                <h2 class="text-base font-black tracking-tight text-slate-800 leading-tight">পার্মানেন্ট রাইডার ক্যালকুলেশন</h2>
                <p class="text-[10px] text-slate-500 font-semibold hidden md:block"><?= $cur_month_label ?> &nbsp;·&nbsp; সপ্তাহ: <?= date('d M', strtotime($week_start)) ?> – <?= date('d M', strtotime($week_end)) ?></p>
            </div>
        </div>
        <button onclick="document.getElementById('addRiderModal').classList.remove('hidden')" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-xl text-xs font-bold shadow-md transition flex items-center gap-2">
            <i class="fas fa-plus"></i><span class="hidden sm:inline">নতুন রাইডার</span>
        </button>
    </header>

    <!-- Page Content -->
    <main class="flex-1 p-4 md:p-6 pb-24 md:pb-8 overflow-x-hidden space-y-6">

        <!-- Alerts -->
        <?php if($message): ?><div class="bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-xl text-sm font-bold flex items-center gap-2 fade-up"><i class="fas fa-check-circle"></i><?= $message ?></div><?php endif; ?>
        <?php if($error): ?><div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl text-sm font-bold flex items-center gap-2 fade-up"><i class="fas fa-exclamation-triangle"></i><?= $error ?></div><?php endif; ?>

        <!-- ═══ SUMMARY CARDS ═══ -->
        <div class="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4 fade-up">

            <div class="app-card p-5 relative overflow-hidden col-span-1" style="background:linear-gradient(135deg,#1e40af,#3b82f6);border:none">
                <i class="fas fa-wallet absolute -right-3 -bottom-3 text-6xl text-white/10"></i>
                <p class="text-blue-200 text-[9px] font-bold uppercase tracking-wider mb-1">মাসিক বেতন</p>
                <h3 class="text-lg font-black text-white">৳ <?= number_format($sum_salary,0) ?></h3>
                <span class="text-[9px] bg-white/15 text-white px-2 py-0.5 rounded font-bold mt-1 inline-block"><?= count($riders) ?> রাইডার</span>
            </div>

            <div class="app-card p-5 relative overflow-hidden" style="background:linear-gradient(135deg,#065f46,#10b981);border:none">
                <i class="fas fa-money-bill-wave absolute -right-3 -bottom-3 text-6xl text-white/10"></i>
                <p class="text-emerald-200 text-[9px] font-bold uppercase tracking-wider mb-1">পরিশোধিত</p>
                <h3 class="text-lg font-black text-white">৳ <?= number_format($sum_paid_month,0) ?></h3>
                <span class="text-[9px] bg-white/15 text-white px-2 py-0.5 rounded font-bold mt-1 inline-block">এই মাসে</span>
            </div>

            <div class="app-card p-5 relative overflow-hidden" style="background:linear-gradient(135deg,#7c2d12,#f97316);border:none">
                <i class="fas fa-clock absolute -right-3 -bottom-3 text-6xl text-white/10"></i>
                <p class="text-orange-200 text-[9px] font-bold uppercase tracking-wider mb-1">বাকি পরিশোধ</p>
                <h3 class="text-lg font-black text-white">৳ <?= number_format($sum_remaining,0) ?></h3>
                <span class="text-[9px] bg-white/15 text-white px-2 py-0.5 rounded font-bold mt-1 inline-block">মোট উপার্জন থেকে</span>
            </div>

            <div class="app-card p-5 relative overflow-hidden" style="background:linear-gradient(135deg,#1e1b4b,#7c3aed);border:none">
                <i class="fas fa-shopping-bag absolute -right-3 -bottom-3 text-6xl text-white/10"></i>
                <p class="text-violet-200 text-[9px] font-bold uppercase tracking-wider mb-1">মোট অর্ডার</p>
                <h3 class="text-lg font-black text-white"><?= number_format($sum_orders) ?> টি</h3>
                <span class="text-[9px] bg-white/15 text-white px-2 py-0.5 rounded font-bold mt-1 inline-block">এই মাসে</span>
            </div>

            <div class="app-card p-5 relative overflow-hidden" style="background:linear-gradient(135deg,#831843,#ec4899);border:none">
                <i class="fas fa-coins absolute -right-3 -bottom-3 text-6xl text-white/10"></i>
                <p class="text-pink-200 text-[9px] font-bold uppercase tracking-wider mb-1">অর্ডার কমিশন</p>
                <h3 class="text-lg font-black text-white">৳ <?= number_format($sum_commission,0) ?></h3>
                <span class="text-[9px] bg-white/15 text-white px-2 py-0.5 rounded font-bold mt-1 inline-block">এই মাসে</span>
            </div>

            <div class="app-card p-5 relative overflow-hidden" style="background:linear-gradient(135deg,#134e4a,#0d9488);border:none">
                <i class="fas fa-chart-line absolute -right-3 -bottom-3 text-6xl text-white/10"></i>
                <p class="text-teal-200 text-[9px] font-bold uppercase tracking-wider mb-1">মোট উপার্জন</p>
                <h3 class="text-lg font-black text-white">৳ <?= number_format($sum_earnings,0) ?></h3>
                <div class="prog-track mt-2" style="background:rgba(255,255,255,.2)">
                    <div class="prog-fill" data-pct="<?= round($avg_earn_pct) ?>" style="background:#fff"></div>
                </div>
                <span class="text-[9px] text-teal-100 font-bold mt-1 inline-block">গড় <?= number_format($avg_earn_pct,1) ?>% সম্পন্ন</span>
            </div>

        </div>

        <!-- ═══ RIDERS ═══ -->
        <?php if(empty($riders)): ?>
        <div class="app-card p-12 text-center fade-up">
            <div class="w-16 h-16 bg-slate-100 rounded-2xl flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-motorcycle text-slate-300 text-3xl"></i>
            </div>
            <h3 class="font-bold text-slate-600 mb-2">কোনো রাইডার যোগ করা হয়নি</h3>
            <p class="text-sm text-slate-400 mb-4">উপরের "নতুন রাইডার" বাটনে ক্লিক করে শুরু করুন।</p>
            <button onclick="document.getElementById('addRiderModal').classList.remove('hidden')" class="bg-indigo-600 text-white px-5 py-2 rounded-xl text-sm font-bold inline-flex items-center gap-2 hover:bg-indigo-700 transition">
                <i class="fas fa-plus"></i> রাইডার যোগ করুন
            </button>
        </div>
        <?php else: ?>

        <div class="space-y-6">
        <?php foreach($riders as $idx => $r):
            $pct   = $r['earn_pct'];
            $spct  = $r['sal_pct'];
            $wpct  = $r['weekly_limit'] > 0 ? min(100, ($r['paid_week']/$r['weekly_limit'])*100) : 0;
            $opct  = $r['orders_month'] > 0 ? min(100, ($r['orders_month'] / max(1, $r['orders_month'])) * 100) : 0;
            $bar_color = $pct >= 100 ? '#10b981' : ($pct >= 75 ? '#3b82f6' : ($pct >= 50 ? '#f59e0b' : '#ef4444'));
            $w_color   = $r['weekly_extra'] > 0 ? '#ef4444' : ($wpct >= 80 ? '#f59e0b' : '#6366f1');
            $sbadge    = $pct >= 100 ? 'badge-green' : ($pct >= 75 ? 'badge-green' : ($pct >= 50 ? 'badge-amber' : 'badge-red'));
            $slabel    = $pct >= 100 ? 'সম্পূর্ণ' : ($pct >= 75 ? 'ভালো' : ($pct >= 50 ? 'অর্ধেক' : 'কম'));
        ?>

        <div class="app-card fade-up" style="animation-delay:<?= $idx*0.07 ?>s">

            <!-- ── Header ── -->
            <div class="p-5 flex flex-wrap gap-4 items-start justify-between" style="border-bottom:1px solid #f1f5f9">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-2xl flex items-center justify-center text-white font-black text-xl shadow-md shrink-0" style="background:linear-gradient(135deg,#6366f1,#4f46e5)"><?= strtoupper(substr($r['name'],0,1)) ?></div>
                    <div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <h3 class="text-base font-black text-slate-800"><?= htmlspecialchars($r['name']) ?></h3>
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full <?= $sbadge ?>"><?= $slabel ?></span>
                        </div>
                        <?php if($r['phone']): ?><p class="text-xs text-slate-500 mt-0.5"><i class="fas fa-phone-alt mr-1"></i><?= htmlspecialchars($r['phone']) ?></p><?php endif; ?>
                    </div>
                </div>
                <!-- Inline salary + commission rate edit (two separate forms in a flex wrapper) -->
                <div class="flex flex-wrap items-end gap-2">
                    <form method="POST" class="flex flex-wrap items-end gap-2 m-0">
                        <input type="hidden" name="action" value="update_salary">
                        <input type="hidden" name="rider_id" value="<?= $r['id'] ?>">
                        <div>
                            <label class="block text-[9px] font-bold text-slate-400 uppercase tracking-wide mb-1">মাসিক বেতন</label>
                            <div class="relative">
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs font-bold">৳</span>
                                <input type="number" name="monthly_salary" value="<?= $r['monthly_salary'] ?>" step="100" min="0" class="w-28 pl-6 pr-2 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold outline-none focus:border-indigo-400 transition">
                            </div>
                        </div>
                        <div>
                            <label class="block text-[9px] font-bold text-slate-400 uppercase tracking-wide mb-1">কমিশন / অর্ডার</label>
                            <div class="relative">
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs font-bold">৳</span>
                                <input type="number" name="commission_per_order" value="<?= $r['commission_per_order'] ?>" step="0.5" min="0" class="w-24 pl-6 pr-2 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold outline-none focus:border-indigo-400 transition">
                            </div>
                        </div>
                        <button type="submit" class="bg-indigo-50 text-indigo-600 border border-indigo-200 hover:bg-indigo-600 hover:text-white px-3 py-2 rounded-xl text-xs font-bold transition flex items-center gap-1.5 whitespace-nowrap"><i class="fas fa-save"></i> সেভ</button>
                    </form>
                    <form method="POST" class="m-0" onsubmit="return confirm('এই রাইডারকে নিষ্ক্রিয় করবেন?')">
                        <input type="hidden" name="action" value="delete_rider">
                        <input type="hidden" name="rider_id" value="<?= $r['id'] ?>">
                        <button type="submit" class="bg-red-50 text-red-400 border border-red-100 hover:bg-red-500 hover:text-white w-9 h-9 rounded-xl flex items-center justify-center text-xs transition" title="নিষ্ক্রিয়"><i class="fas fa-trash-alt"></i></button>
                    </form>
                </div>
            </div>

            <!-- ── Stats Grid ── -->
            <div class="grid grid-cols-2 sm:grid-cols-4 divide-x divide-y sm:divide-y-0 divide-slate-100">
                <div class="p-4 text-center">
                    <div class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">মাসিক বেতন</div>
                    <div class="text-base font-black text-slate-800">৳ <?= number_format($r['monthly_salary'],0) ?></div>
                    <div class="text-[9px] text-slate-400 mt-0.5">সাপ্তাহিক ÷ ৳ <?= number_format($r['weekly_limit'],0) ?></div>
                </div>
                <div class="p-4 text-center">
                    <div class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">এই মাসে পেয়েছে</div>
                    <div class="text-base font-black text-emerald-600">৳ <?= number_format($r['paid_month'],0) ?></div>
                    <div class="text-[9px] text-slate-400 mt-0.5"><?= number_format($spct,1) ?>% বেতন সম্পন্ন</div>
                </div>
                <div class="p-4 text-center">
                    <div class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">অর্ডার (এই মাসে)</div>
                    <div class="text-base font-black text-violet-600"><?= number_format($r['orders_month']) ?> টি</div>
                    <div class="text-[9px] text-slate-400 mt-0.5">এ সপ্তাহে: <?= $r['orders_week'] ?> টি</div>
                </div>
                <div class="p-4 text-center">
                    <div class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">মোট উপার্জন</div>
                    <div class="text-base font-black text-indigo-700">৳ <?= number_format($r['total_earnings'],0) ?></div>
                    <div class="text-[9px] text-slate-400 mt-0.5">বেতন + কমিশন</div>
                </div>
            </div>

            <!-- ── Commission Breakdown ── -->
            <?php if($r['commission_per_order'] > 0 || $r['orders_month'] > 0): ?>
            <div class="mx-5 mb-4 p-3 rounded-2xl flex flex-wrap gap-4 items-center justify-between" style="background:linear-gradient(135deg,rgba(99,102,241,.07),rgba(139,92,246,.07));border:1px solid rgba(99,102,241,.15)">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-xl bg-indigo-100 flex items-center justify-center shrink-0"><i class="fas fa-coins text-indigo-500 text-sm"></i></div>
                    <div>
                        <div class="text-[10px] font-black text-indigo-800 uppercase tracking-wide">অর্ডার কমিশন বিশ্লেষণ</div>
                        <div class="text-xs text-slate-500 mt-0.5"><?= $r['orders_month'] ?> অর্ডার × ৳<?= number_format($r['commission_per_order'],2) ?> = <strong class="text-indigo-700">৳ <?= number_format($r['monthly_commission'],2) ?></strong></div>
                    </div>
                </div>
                <div class="flex gap-4 text-center">
                    <div>
                        <div class="text-[9px] text-slate-400 font-bold uppercase">বেতন</div>
                        <div class="text-sm font-black text-slate-700">৳ <?= number_format($r['monthly_salary'],0) ?></div>
                    </div>
                    <div class="text-slate-300 self-center text-lg">+</div>
                    <div>
                        <div class="text-[9px] text-slate-400 font-bold uppercase">কমিশন</div>
                        <div class="text-sm font-black text-violet-700">৳ <?= number_format($r['monthly_commission'],0) ?></div>
                    </div>
                    <div class="text-slate-300 self-center text-lg">=</div>
                    <div>
                        <div class="text-[9px] text-slate-400 font-bold uppercase">মোট</div>
                        <div class="text-sm font-black text-indigo-700">৳ <?= number_format($r['total_earnings'],0) ?></div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- ── Progress Bars ── -->
            <div class="px-5 pb-4 space-y-3">
                <!-- Total earnings progress -->
                <div>
                    <div class="flex justify-between items-center mb-1.5">
                        <span class="text-[10px] font-bold text-slate-500">মোট উপার্জন অগ্রগতি <span class="text-[9px] text-slate-400">(বেতন + কমিশন)</span></span>
                        <span class="text-[10px] font-black" style="color:<?= $bar_color ?>"><?= number_format($pct,1) ?>%</span>
                    </div>
                    <div class="prog-track">
                        <div class="prog-fill" data-pct="<?= round(min(100,$pct),2) ?>" style="background:<?= $bar_color ?>"></div>
                    </div>
                    <div class="flex justify-between mt-1">
                        <span class="text-[9px] text-slate-400">পরিশোধিত ৳ <?= number_format($r['paid_month'],0) ?></span>
                        <span class="text-[9px] text-slate-400">বাকি ৳ <?= number_format($r['remaining_month'],0) ?></span>
                    </div>
                </div>

                <!-- Weekly salary progress -->
                <div>
                    <div class="flex justify-between items-center mb-1.5">
                        <span class="text-[10px] font-bold text-slate-500">সাপ্তাহিক বেতন <span class="text-[9px] text-slate-400">(<?= date('d',strtotime($week_start)) ?>–<?= date('d M',strtotime($week_end)) ?>)</span></span>
                        <span class="text-[10px] font-black" style="color:<?= $w_color ?>">
                            <?php if($r['weekly_extra'] > 0): ?>+৳ <?= number_format($r['weekly_extra'],0) ?> অতিরিক্ত<?php else: ?>৳ <?= number_format($r['paid_week'],0) ?> / ৳ <?= number_format($r['weekly_limit'],0) ?><?php endif; ?>
                        </span>
                    </div>
                    <div class="prog-track slim">
                        <div class="prog-fill" data-pct="<?= round(min(100,$wpct),2) ?>" style="background:<?= $w_color ?>"></div>
                    </div>
                </div>
            </div>

            <!-- ── Tabs ── -->
            <div style="border-top:1px solid #f1f5f9">
                <div class="flex gap-2 px-4 pt-3 pb-1">
                    <button class="rtab-btn on" onclick="switchTab(this, 'pay-<?= $r['id'] ?>', 'order-<?= $r['id'] ?>')">
                        <i class="fas fa-money-bill-wave mr-1 text-emerald-500"></i> পেমেন্ট যোগ / ইতিহাস
                    </button>
                    <button class="rtab-btn" onclick="switchTab(this, 'order-<?= $r['id'] ?>', 'pay-<?= $r['id'] ?>')">
                        <i class="fas fa-shopping-bag mr-1 text-violet-500"></i> অর্ডার যোগ / ইতিহাস
                    </button>
                </div>

                <!-- Payment Tab -->
                <div id="pay-<?= $r['id'] ?>" class="p-4 pt-3">
                    <!-- Add payment form -->
                    <form method="POST" class="flex flex-wrap gap-2 items-end bg-slate-50 rounded-2xl p-3 border border-slate-100 mb-4">
                        <input type="hidden" name="action" value="add_payment">
                        <input type="hidden" name="rider_id" value="<?= $r['id'] ?>">
                        <div class="flex-1 min-w-[110px]">
                            <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-wide mb-1">পরিমাণ (৳)</label>
                            <input type="number" name="amount" placeholder="0.00" step="0.01" min="0.01" required class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-sm font-bold outline-none focus:border-indigo-400 transition">
                        </div>
                        <div class="flex-1 min-w-[130px]">
                            <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-wide mb-1">তারিখ</label>
                            <input type="date" name="payment_date" value="<?= date('Y-m-d') ?>" required class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-sm font-bold outline-none focus:border-indigo-400 transition">
                        </div>
                        <div class="flex-1 min-w-[130px]">
                            <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-wide mb-1">মন্তব্য</label>
                            <input type="text" name="notes" placeholder="ঐচ্ছিক..." class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-sm outline-none focus:border-indigo-400 transition">
                        </div>
                        <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-xl text-xs font-bold shadow-sm transition flex items-center gap-1.5 whitespace-nowrap"><i class="fas fa-plus"></i> পেমেন্ট</button>
                    </form>

                    <!-- Payment history table -->
                    <?php if(!empty($r['pay_history'])): ?>
                    <div class="overflow-x-auto rounded-xl border border-slate-200">
                        <table class="w-full text-sm">
                            <thead><tr class="tbl-head">
                                <th class="text-left rounded-tl-xl">তারিখ</th>
                                <th class="text-right">পরিমাণ</th>
                                <th class="text-left hidden sm:table-cell">মন্তব্য</th>
                                <th class="text-center w-12 rounded-tr-xl">মুছুন</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach($r['pay_history'] as $ph): ?>
                            <tr class="tbl-row">
                                <td class="font-bold text-slate-700 whitespace-nowrap">
                                    <?= date('d M Y', strtotime($ph['payment_date'])) ?>
                                    <div class="text-[9px] text-slate-400"><?= date('l', strtotime($ph['payment_date'])) ?></div>
                                </td>
                                <td class="text-right font-black text-emerald-600 whitespace-nowrap">৳ <?= number_format($ph['amount'],2) ?></td>
                                <td class="text-slate-500 text-xs hidden sm:table-cell"><?= $ph['notes'] ? htmlspecialchars($ph['notes']) : '—' ?></td>
                                <td class="text-center">
                                    <form method="POST" class="m-0 inline" onsubmit="return confirm('মুছবেন?')">
                                        <input type="hidden" name="action" value="delete_payment">
                                        <input type="hidden" name="payment_id" value="<?= $ph['id'] ?>">
                                        <button type="submit" class="w-7 h-7 rounded-lg bg-red-50 text-red-400 hover:bg-red-500 hover:text-white border border-red-100 transition flex items-center justify-center mx-auto"><i class="fas fa-times text-[10px]"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot><tr class="bg-slate-100">
                                <td class="px-4 py-2.5 font-black text-slate-700 text-xs" colspan="1">সর্বমোট পরিশোধ</td>
                                <td class="px-4 py-2.5 text-right font-black text-slate-800 whitespace-nowrap">৳ <?= number_format($r['paid_alltime'],2) ?></td>
                                <td colspan="2" class="hidden sm:table-cell"></td>
                            </tr></tfoot>
                        </table>
                    </div>
                    <?php else: ?>
                    <p class="text-center text-sm text-slate-400 py-4">কোনো পেমেন্ট রেকর্ড নেই।</p>
                    <?php endif; ?>
                </div>

                <!-- Order Tab (hidden by default) -->
                <div id="order-<?= $r['id'] ?>" class="p-4 pt-3 hidden">
                    <!-- Add orders form -->
                    <form method="POST" class="flex flex-wrap gap-2 items-end bg-violet-50 rounded-2xl p-3 border border-violet-100 mb-4">
                        <input type="hidden" name="action" value="add_orders">
                        <input type="hidden" name="rider_id" value="<?= $r['id'] ?>">
                        <div class="flex-1 min-w-[110px]">
                            <label class="block text-[9px] font-bold text-violet-600 uppercase tracking-wide mb-1">অর্ডার সংখ্যা</label>
                            <input type="number" name="order_count" placeholder="0" min="1" required class="w-full px-3 py-2 bg-white border border-violet-200 rounded-xl text-sm font-bold outline-none focus:border-violet-400 transition">
                        </div>
                        <div class="flex-1 min-w-[130px]">
                            <label class="block text-[9px] font-bold text-violet-600 uppercase tracking-wide mb-1">তারিখ</label>
                            <input type="date" name="record_date" value="<?= date('Y-m-d') ?>" required class="w-full px-3 py-2 bg-white border border-violet-200 rounded-xl text-sm font-bold outline-none focus:border-violet-400 transition">
                        </div>
                        <div class="flex-1 min-w-[130px]">
                            <label class="block text-[9px] font-bold text-violet-600 uppercase tracking-wide mb-1">মন্তব্য</label>
                            <input type="text" name="notes" placeholder="ঐচ্ছিক..." class="w-full px-3 py-2 bg-white border border-violet-200 rounded-xl text-sm outline-none focus:border-violet-400 transition">
                        </div>
                        <button type="submit" class="bg-violet-600 hover:bg-violet-700 text-white px-4 py-2 rounded-xl text-xs font-bold shadow-sm transition flex items-center gap-1.5 whitespace-nowrap"><i class="fas fa-plus"></i> অর্ডার</button>
                    </form>

                    <!-- Commission summary mini card -->
                    <div class="flex gap-3 mb-4 flex-wrap">
                        <div class="bg-violet-50 border border-violet-100 rounded-xl px-4 py-3 flex items-center gap-3">
                            <i class="fas fa-shopping-bag text-violet-400"></i>
                            <div>
                                <div class="text-[9px] font-black text-violet-600 uppercase">এই মাসে মোট</div>
                                <div class="font-black text-violet-800"><?= $r['orders_month'] ?> অর্ডার</div>
                            </div>
                        </div>
                        <div class="bg-indigo-50 border border-indigo-100 rounded-xl px-4 py-3 flex items-center gap-3">
                            <i class="fas fa-coins text-indigo-400"></i>
                            <div>
                                <div class="text-[9px] font-black text-indigo-600 uppercase">কমিশন অর্জিত</div>
                                <div class="font-black text-indigo-800">৳ <?= number_format($r['monthly_commission'],2) ?></div>
                            </div>
                        </div>
                        <div class="bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 flex items-center gap-3">
                            <i class="fas fa-history text-slate-400"></i>
                            <div>
                                <div class="text-[9px] font-black text-slate-500 uppercase">সর্বমোট অর্ডার</div>
                                <div class="font-black text-slate-700"><?= $r['orders_alltime'] ?> টি</div>
                            </div>
                        </div>
                    </div>

                    <!-- Order history table -->
                    <?php if(!empty($r['ord_history'])): ?>
                    <div class="overflow-x-auto rounded-xl border border-slate-200">
                        <table class="w-full text-sm">
                            <thead><tr class="tbl-head" style="background:linear-gradient(90deg,#4c1d95,#7c3aed)">
                                <th class="text-left rounded-tl-xl">তারিখ</th>
                                <th class="text-center">অর্ডার</th>
                                <th class="text-right">কমিশন</th>
                                <th class="text-left hidden sm:table-cell">মন্তব্য</th>
                                <th class="text-center w-12 rounded-tr-xl">মুছুন</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach($r['ord_history'] as $oh): ?>
                            <tr class="tbl-row">
                                <td class="font-bold text-slate-700 whitespace-nowrap">
                                    <?= date('d M Y', strtotime($oh['record_date'])) ?>
                                    <div class="text-[9px] text-slate-400"><?= date('l', strtotime($oh['record_date'])) ?></div>
                                </td>
                                <td class="text-center">
                                    <span class="bg-violet-100 text-violet-700 font-black text-xs px-2.5 py-1 rounded-full"><?= $oh['order_count'] ?> টি</span>
                                </td>
                                <td class="text-right font-black text-indigo-600 whitespace-nowrap">৳ <?= number_format($oh['order_count'] * $r['commission_per_order'], 2) ?></td>
                                <td class="text-slate-500 text-xs hidden sm:table-cell"><?= $oh['notes'] ? htmlspecialchars($oh['notes']) : '—' ?></td>
                                <td class="text-center">
                                    <form method="POST" class="m-0 inline" onsubmit="return confirm('মুছবেন?')">
                                        <input type="hidden" name="action" value="delete_order_record">
                                        <input type="hidden" name="order_id" value="<?= $oh['id'] ?>">
                                        <button type="submit" class="w-7 h-7 rounded-lg bg-red-50 text-red-400 hover:bg-red-500 hover:text-white border border-red-100 transition flex items-center justify-center mx-auto"><i class="fas fa-times text-[10px]"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot><tr style="background:#f8fafc">
                                <td class="px-4 py-2.5 font-black text-slate-700 text-xs">সর্বমোট</td>
                                <td class="px-4 py-2.5 text-center font-black text-violet-700"><?= $r['orders_alltime'] ?> টি</td>
                                <td class="px-4 py-2.5 text-right font-black text-indigo-700">৳ <?= number_format($r['orders_alltime'] * $r['commission_per_order'], 2) ?></td>
                                <td colspan="2" class="hidden sm:table-cell"></td>
                            </tr></tfoot>
                        </table>
                    </div>
                    <?php else: ?>
                    <p class="text-center text-sm text-slate-400 py-4">কোনো অর্ডার রেকর্ড নেই।</p>
                    <?php endif; ?>
                </div>

            </div><!-- /tabs -->

        </div><!-- /rider card -->
        <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </main>
</div>

<!-- Bottom Nav -->
<nav class="bottom-nav shadow-[0_-4px_15px_rgba(0,0,0,.05)]">
    <a href="index.php" class="nav-item"><i class="fas fa-home"></i>Home</a>
    <a href="manage_shareholders.php" class="nav-item"><i class="fas fa-users"></i>Users</a>
    <a href="add_entry.php" class="nav-item"><i class="fas fa-plus-circle"></i>Entry</a>
    <a href="rider_calculation.php" class="nav-item active"><i class="fas fa-motorcycle"></i>Riders</a>
    <button onclick="toggleSidebar()" class="nav-item bg-transparent border-none outline-none"><i class="fas fa-bars"></i>Menu</button>
</nav>

<!-- ═══ ADD RIDER MODAL ═══ -->
<div id="addRiderModal" class="hidden fixed inset-0 bg-slate-900/60 flex items-center justify-center z-50 p-4 backdrop-blur-sm">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md overflow-hidden">
        <div class="px-6 py-5 flex justify-between items-center" style="background:linear-gradient(135deg,#4f46e5,#6366f1)">
            <h3 class="text-base font-black text-white flex items-center gap-2"><i class="fas fa-motorcycle"></i> নতুন রাইডার যোগ করুন</h3>
            <button onclick="document.getElementById('addRiderModal').classList.add('hidden')" class="text-white/70 hover:text-white transition text-xl"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" class="p-6 space-y-4">
            <input type="hidden" name="action" value="add_rider">
            <div>
                <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">নাম *</label>
                <input type="text" name="name" placeholder="রাইডারের পূর্ণ নাম" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold outline-none focus:border-indigo-400 focus:bg-white transition">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">ফোন</label>
                    <input type="text" name="phone" placeholder="01XXXXXXXXX" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold outline-none focus:border-indigo-400 focus:bg-white transition">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">যোগদানের তারিখ</label>
                    <input type="date" name="join_date" value="<?= date('Y-m-d') ?>" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold outline-none focus:border-indigo-400 focus:bg-white transition">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">মাসিক বেতন (৳) *</label>
                    <input type="number" name="monthly_salary" placeholder="0" step="100" min="0" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold outline-none focus:border-indigo-400 focus:bg-white transition">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">কমিশন / অর্ডার (৳)</label>
                    <input type="number" name="commission_per_order" placeholder="0.00" step="0.5" min="0" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold outline-none focus:border-indigo-400 focus:bg-white transition">
                </div>
            </div>
            <p class="text-[10px] text-slate-400">সাপ্তাহিক সীমা = মাসিক বেতন ÷ ৪ &nbsp;|&nbsp; মোট উপার্জন = বেতন + (অর্ডার × কমিশন)</p>
            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-3.5 rounded-xl shadow-md transition text-sm flex items-center justify-center gap-2">
                <i class="fas fa-plus"></i> রাইডার যোগ করুন
            </button>
        </form>
    </div>
</div>

<script>
// ── Sidebar ─────────────────────────────────────────────
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('-translate-x-full');
    document.getElementById('sidebar-overlay').classList.toggle('hidden');
}
document.getElementById('addRiderModal').addEventListener('click', function(e){ if(e.target===this) this.classList.add('hidden'); });

// ── Tab Switcher ─────────────────────────────────────────
function switchTab(btn, showId, hideId) {
    document.getElementById(showId).classList.remove('hidden');
    document.getElementById(hideId).classList.add('hidden');
    const parent = btn.closest('.flex');
    parent.querySelectorAll('.rtab-btn').forEach(b => b.classList.remove('on'));
    btn.classList.add('on');
}

// ── Progress Bar Animation ───────────────────────────────
window.addEventListener('load', function () {
    var fills = document.querySelectorAll('.prog-fill');
    fills.forEach(function(el) { el.style.width = '0%'; });
    requestAnimationFrame(function () {
        requestAnimationFrame(function () {
            fills.forEach(function(el) {
                var pct = parseFloat(el.getAttribute('data-pct')) || 0;
                el.style.width = pct + '%';
            });
        });
    });
});
</script>
</body>
</html>
