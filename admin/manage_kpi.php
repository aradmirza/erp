<?php
ini_set('session.cookie_lifetime', 86400);
ini_set('session.gc_maxlifetime', 86400);
session_set_cookie_params(86400);
session_start();

if(!isset($_SESSION['admin_logged_in'])) { header("Location: login.php"); exit; }
// === Role-Based Access Control (RBAC) ===
if(isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'staff') {
    $perms = $_SESSION['staff_permissions'] ?? [];
    if(!in_array('manage_kpi', $perms)) {
        die("<div style='text-align:center; padding:50px; font-family:sans-serif;'><h2 style='color:red;'>Access Denied!</h2><p>আপনার এই পেজে প্রবেশের অনুমতি নেই।</p></div>");
    }
}
// ==========================================

require_once __DIR__ . '/db.php';

// ==========================================
// SAFE DATABASE QUERY METHODS (Anti-500 Error)
// ==========================================
function safeQuery($pdo, $sql, $params = []) {
    try { $stmt = $pdo->prepare($sql); $stmt->execute($params); return $stmt->fetchAll(PDO::FETCH_ASSOC); } 
    catch(PDOException $e) { return []; }
}
function safeColumn($pdo, $sql, $params = []) {
    try { $stmt = $pdo->prepare($sql); $stmt->execute($params); return $stmt->fetchColumn(); } 
    catch(PDOException $e) { return null; }
}

// ==========================================
// AUTO-CREATE TABLES & COLUMNS (Safe Mode)
// ==========================================
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `system_settings` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `setting_name` varchar(100) NOT NULL UNIQUE,
      `setting_value` text NULL,
      PRIMARY KEY (`id`)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `kpi_roles` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `role_name` varchar(100) NOT NULL UNIQUE,
      `role_description` text NULL,
      `department` varchar(100) NULL DEFAULT 'General',
      `color` varchar(20) NULL DEFAULT '#3B82F6',
      `icon` varchar(50) NULL DEFAULT 'fa-user-tie',
      `profit_share_pct` float NOT NULL DEFAULT 0,
      `is_active` tinyint(1) DEFAULT 1,
      `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `kpi_metrics` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `role_id` int(11) NULL,
      `role_name` varchar(100) NOT NULL,
      `metric_name` varchar(255) NOT NULL,
      `metric_description` text NULL,
      `category` varchar(100) NULL DEFAULT 'General',
      `max_score` int(11) DEFAULT 100,
      `weight_pct` float DEFAULT 0,
      `measurement_type` varchar(50) DEFAULT 'number',
      `target_value` varchar(255) NULL,
      `sub_fields` text NULL DEFAULT NULL,
      `is_active` tinyint(1) DEFAULT 1,
      PRIMARY KEY (`id`)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `kpi_evaluations` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `user_id` int(11) NOT NULL,
      `role_name` varchar(100) NOT NULL,
      `eval_month` varchar(20) NOT NULL,
      `eval_year` int(4) NOT NULL,
      `total_score` float NOT NULL DEFAULT 0,
      `performance_grade` varchar(50) NULL,
      `remarks` text NULL,
      `evaluated_by` int(11) NOT NULL,
      `metrics_data` text NULL,
      `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `role_settings` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `role_name` varchar(100) NOT NULL UNIQUE,
      `profit_share_pct` float NOT NULL DEFAULT 0,
      PRIMARY KEY (`id`)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `advisor_targets` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `user_id` int(11) NOT NULL,
      `role_name` varchar(100) NOT NULL,
      `target_data` text NULL,
      `assigned_at` timestamp DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    )");

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

    $pdo->exec("INSERT IGNORE INTO system_settings (setting_name, setting_value) VALUES ('advisor_fund_pct', '10')");
} catch (PDOException $e) {}

// ==========================================
// PHASE E: নতুন টেবিল তৈরি
// ==========================================
try {
    // পদবী মাস্টার (Position Master)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `positions` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `position_name` VARCHAR(100) NOT NULL UNIQUE,
      `position_name_bn` VARCHAR(100),
      `department` VARCHAR(50),
      `tier_level` INT DEFAULT 1,
      `description` TEXT,
      `is_active` TINYINT(1) DEFAULT 1,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // কর্মী (Employee — non-shareholder staff)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `employees` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `name` VARCHAR(255) NOT NULL,
      `name_bn` VARCHAR(255),
      `phone` VARCHAR(20),
      `email` VARCHAR(100),
      `nid_number` VARCHAR(20),
      `address` TEXT,
      `position_id` INT,
      `department` VARCHAR(50),
      `join_date` DATE,
      `monthly_salary` DECIMAL(10,2) DEFAULT 0,
      `employment_type` ENUM('full_time','part_time','contract','rider') DEFAULT 'full_time',
      `status` ENUM('active','inactive','terminated') DEFAULT 'active',
      `profile_picture` VARCHAR(255),
      `emergency_contact` VARCHAR(20),
      `bank_account` VARCHAR(50),
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // KPI বিভাগ (Category Groups)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `kpi_categories` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `category_name` VARCHAR(100) NOT NULL,
      `category_name_bn` VARCHAR(100),
      `icon` VARCHAR(50),
      `color` VARCHAR(20),
      `description` TEXT
    )");

    // মাসিক লক্ষ্যমাত্রা (Monthly Targets)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `kpi_targets_monthly` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `user_id` INT NOT NULL,
      `position_id` INT,
      `role_name` VARCHAR(100),
      `metric_id` INT NOT NULL,
      `target_month` VARCHAR(7) NOT NULL,
      `target_value` VARCHAR(255),
      `actual_value` VARCHAR(255),
      `status` ENUM('pending','achieved','missed') DEFAULT 'pending',
      `set_by` INT,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // দৈনিক স্কোর ইতিহাস (Score History)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `kpi_score_history` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `user_id` INT NOT NULL,
      `role_name` VARCHAR(100),
      `score_date` DATE NOT NULL,
      `daily_score` FLOAT DEFAULT 0,
      `metrics_breakdown` TEXT,
      `calculated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // গ্রাহক মতামত (Customer Feedback)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `customer_feedback` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `order_id` VARCHAR(50),
      `rider_id` INT,
      `rating` TINYINT NOT NULL,
      `comment` TEXT,
      `feedback_type` ENUM('delivery','support','product','general') DEFAULT 'general',
      `status` ENUM('open','resolved','dismissed') DEFAULT 'open',
      `resolved_by` INT,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // অডিট লগ (Audit Log)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `audit_log` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `user_id` INT,
      `user_type` ENUM('admin','staff','shareholder') DEFAULT 'admin',
      `action` VARCHAR(100) NOT NULL,
      `table_name` VARCHAR(100),
      `record_id` INT,
      `old_value` TEXT,
      `new_value` TEXT,
      `ip_address` VARCHAR(45),
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {}

$columns_to_check = [
    ['shareholder_accounts', 'profile_picture', 'VARCHAR(255) NULL AFTER phone'],
    ['kpi_metrics', 'role_id', 'INT(11) NULL AFTER id'],
    ['kpi_metrics', 'metric_description', 'TEXT NULL AFTER metric_name'],
    ['kpi_metrics', 'category', "VARCHAR(100) NULL DEFAULT 'General' AFTER metric_description"],
    ['kpi_metrics', 'weight_pct', "FLOAT DEFAULT 0 AFTER max_score"],
    ['kpi_metrics', 'measurement_type', "VARCHAR(50) DEFAULT 'number' AFTER weight_pct"],
    ['kpi_metrics', 'target_value', "VARCHAR(255) NULL AFTER measurement_type"],
    ['kpi_metrics', 'is_active', "TINYINT(1) DEFAULT 1"],
    ['kpi_evaluations', 'performance_grade', "VARCHAR(50) NULL AFTER total_score"],
    ['kpi_evaluations', 'metrics_data', "TEXT NULL AFTER evaluated_by"],
    ['advisor_targets', 'target_data', "TEXT NULL AFTER role_name"],
    // Phase E: kpi_metrics নতুন কলাম
    ['kpi_metrics', 'category_id',        "INT NULL"],
    ['kpi_metrics', 'kpi_type',           "ENUM('leading','lagging') DEFAULT 'lagging'"],
    ['kpi_metrics', 'data_source',        "VARCHAR(100) NULL"],
    ['kpi_metrics', 'formula',            "TEXT NULL"],
    ['kpi_metrics', 'unit',               "VARCHAR(20) NULL"],
    ['kpi_metrics', 'is_smart_compliant', "TINYINT(1) DEFAULT 0"],
    // Phase E: kpi_evaluations নতুন কলাম
    ['kpi_evaluations', 'auto_calculated',         "TINYINT(1) DEFAULT 0"],
    ['kpi_evaluations', 'kpi_breakdown',            "TEXT NULL"],
    ['kpi_evaluations', 'bonus_amount',             "DECIMAL(10,2) DEFAULT 0"],
    ['kpi_evaluations', 'bonus_paid',               "TINYINT(1) DEFAULT 0"],
    ['kpi_evaluations', 'evaluator_notes',          "TEXT NULL"],
    ['kpi_evaluations', 'employee_acknowledged',    "TINYINT(1) DEFAULT 0"],
];
foreach($columns_to_check as $col) {
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM `{$col[0]}` LIKE '{$col[1]}'");
        if($chk && $chk->rowCount() == 0) { $pdo->exec("ALTER TABLE `{$col[0]}` ADD COLUMN `{$col[1]}` {$col[2]}"); }
    } catch(PDOException $e) {}
}

// ডিফল্ট রোল ও মেট্রিক সিডিং
try {
    $role_count = safeColumn($pdo, "SELECT COUNT(*) FROM kpi_roles");
    if($role_count == 0) {
        $default_roles = [
            ['Strategy Advisor',       'Develops and oversees company growth strategies',  'Strategy',   '#8B5CF6', 'fa-chess'],
            ['Marketing Advisor',      'Manages brand, campaigns, and customer acquisition','Marketing',  '#EC4899', 'fa-bullhorn'],
            ['Operation Advisor',      'Oversees daily operations and order management',    'Operations', '#F59E0B', 'fa-cogs'],
            ['Logistics & Delivery',   'Manages delivery network and order fulfillment',    'Operations', '#EF4444', 'fa-truck'],
            ['Tech Advisor',           'Handles system infrastructure and development',     'Technology', '#06B6D4', 'fa-laptop-code'],
            ['Financial Advisor',      'Manages financial health and reporting',            'Finance',    '#10B981', 'fa-chart-line'],
            ['HR Advisor',             'Handles recruitment, retention, and team culture',  'HR',         '#F97316', 'fa-users'],
            ['Design Advisor',         'Leads UI/UX and visual branding',                  'Creative',   '#A78BFA', 'fa-paint-brush'],
            ['Publicity Advisor',      'Manages PR and brand awareness campaigns',          'Marketing',  '#FB7185', 'fa-newspaper'],
            ['Investor Advisor',       'Manages investor relations and fundraising',        'Finance',    '#34D399', 'fa-hand-holding-usd'],
        ];
        $rs = $pdo->prepare("INSERT IGNORE INTO kpi_roles (role_name, role_description, department, color, icon) VALUES (?, ?, ?, ?, ?)");
        foreach($default_roles as $r) { $rs->execute($r); }
    }

    $metric_count = safeColumn($pdo, "SELECT COUNT(*) FROM kpi_metrics");
    if($metric_count == 0) {
        $def_sub = json_encode(['Target (লক্ষ্যমাত্রা)', 'Execution Plan (পরিকল্পনা)', 'Timeline (সময়সীমা)', 'Result (ফলাফল)'], JSON_UNESCAPED_UNICODE);
        $default_metrics = [
            ['Strategy Advisor',     'Monthly Growth Strategy',       'Strategy',   30, 'Increase revenue by X%'],
            ['Strategy Advisor',     'Quarterly Expansion Plan',      'Planning',   40, 'New market entry plan'],
            ['Marketing Advisor',    'Monthly New Customers',         'Acquisition',25, '100 new customers'],
            ['Marketing Advisor',    'Campaign ROI',                  'Analytics',  25, '3x ROI minimum'],
            ['Operation Advisor',    'Order Success Rate',            'Quality',    40, '95% success rate'],
            ['Operation Advisor',    'Processing Time',               'Efficiency', 30, 'Under 24 hours'],
            ['Logistics & Delivery', 'Delivery Success Rate',         'Quality',    40, '98% on-time delivery'],
            ['Logistics & Delivery', 'Return Rate Management',        'Quality',    30, 'Under 5% returns'],
            ['Tech Advisor',         'System Uptime',                 'Reliability',40, '99.9% uptime'],
            ['Tech Advisor',         'Bug Resolution Rate',           'Quality',    30, 'All P1 bugs in 24hrs'],
            ['Financial Advisor',    'Cost Reduction',                'Finance',    40, '5% monthly reduction'],
            ['Financial Advisor',    'Financial Reporting',           'Reporting',  30, 'On-time monthly report'],
            ['HR Advisor',           'Employee Retention',            'Culture',    40, '90% retention rate'],
            ['HR Advisor',           'Recruitment Speed',             'Talent',     30, 'Fill roles in 2 weeks'],
            ['Design Advisor',       'UI/UX Improvements',            'Design',     50, '2 redesigns per month'],
            ['Design Advisor',       'Marketing Asset Delivery',      'Creative',   50, 'All assets on deadline'],
            ['Publicity Advisor',    'Brand Awareness',               'PR',         50, 'Media coverage 5x/month'],
            ['Publicity Advisor',    'Public Relations',              'PR',         50, 'Zero negative coverage'],
            ['Investor Advisor',     'Fund Management',               'Finance',    50, 'Zero fund leakage'],
            ['Investor Advisor',     'Investor Pitching',             'Growth',     50, '2 pitches per quarter'],
        ];
        $ms = $pdo->prepare("INSERT INTO kpi_metrics (role_name, metric_name, category, max_score, target_value, sub_fields) VALUES (?, ?, ?, ?, ?, ?)");
        $rs2 = $pdo->prepare("INSERT IGNORE INTO role_settings (role_name, profit_share_pct) VALUES (?, 0)");
        foreach($default_metrics as $dm) {
            $ms->execute([$dm[0], $dm[1], $dm[2], $dm[3], $dm[4], $def_sub]);
            $rs2->execute([$dm[0]]);
        }
    }
} catch (Exception $e) {}

// ==========================================
// PHASE E: ডিফল্ট ডেটা সিডিং
// ==========================================
try {
    // ৬টি KPI বিভাগ (categories)
    $cat_count = safeColumn($pdo, "SELECT COUNT(*) FROM kpi_categories");
    if ($cat_count == 0) {
        $cats = [
            ['Operational',   'পরিচালন',          'fa-cogs',          '#F59E0B', 'Daily operations & process KPIs'],
            ['Financial',     'আর্থিক',           'fa-chart-line',    '#10B981', 'Revenue, cost, profit metrics'],
            ['Customer',      'গ্রাহক',           'fa-smile',         '#3B82F6', 'Customer satisfaction & retention'],
            ['Quality',       'মান নিয়ন্ত্রণ',   'fa-check-circle',  '#8B5CF6', 'Quality assurance & compliance'],
            ['Productivity',  'উৎপাদনশীলতা',     'fa-bolt',          '#EF4444', 'Output speed & efficiency'],
            ['Compliance',    'নীতি অনুসরণ',      'fa-shield-alt',    '#6B7280', 'Rules, policy & regulation adherence'],
        ];
        $cs = $pdo->prepare("INSERT IGNORE INTO kpi_categories (category_name, category_name_bn, icon, color, description) VALUES (?,?,?,?,?)");
        foreach ($cats as $c) { $cs->execute($c); }
    }

    // ২৩টি পদবী (positions)
    $pos_count = safeColumn($pdo, "SELECT COUNT(*) FROM positions");
    if ($pos_count == 0) {
        $positions = [
            // Tier 1 — নেতৃত্ব (Leadership)
            ['CEO / Founder',               'সিইও / প্রতিষ্ঠাতা',              'Leadership',       1, 'Company vision, strategy & overall leadership'],
            ['COO',                         'চিফ অপারেটিং অফিসার',             'Leadership',       1, 'Day-to-day operations & execution oversight'],
            // Tier 2 — Operations
            ['Operations Manager',          'অপারেশন ম্যানেজার',               'Operations',       2, 'Manages daily order flow, riders, and vendors'],
            ['Fleet Manager',               'ফ্লিট ম্যানেজার',                 'Operations',       2, 'Manages vehicle/bike fleet and maintenance'],
            ['Vendor Manager',              'ভেন্ডর ম্যানেজার',                'Operations',       2, 'Onboards & manages vendor relationships'],
            ['Quality Control Officer',     'মান নিয়ন্ত্রণ কর্মকর্তা',       'Operations',       2, 'Ensures service quality and compliance standards'],
            // Tier 3 — Field
            ['Delivery Rider',              'ডেলিভারি ম্যান',                  'Field',            3, 'Last-mile delivery execution'],
            // Tier 2 — Technology
            ['CTO',                         'চিফ টেকনোলজি অফিসার',             'Technology',       2, 'Technical strategy and engineering oversight'],
            ['Mobile App Developer',        'মোবাইল অ্যাপ ডেভেলপার',          'Technology',       2, 'Android/iOS app development'],
            ['Backend Developer',           'ব্যাকএন্ড ডেভেলপার',             'Technology',       2, 'Server, API and database development'],
            ['DevOps',                      'ডেভঅপস ইঞ্জিনিয়ার',              'Technology',       2, 'Server infrastructure, CI/CD, and deployment'],
            // Tier 2 — Marketing
            ['Marketing Manager',           'মার্কেটিং ম্যানেজার',             'Marketing',        2, 'Brand strategy, campaigns and customer growth'],
            ['Digital Marketing Specialist','ডিজিটাল মার্কেটিং স্পেশালিস্ট',   'Marketing',        2, 'Social media, SEO and paid ads'],
            ['Content Creator',             'কন্টেন্ট ক্রিয়েটর',              'Marketing',        2, 'Video, photo and written content production'],
            ['Brand Manager',               'ব্র্যান্ড ম্যানেজার',             'Marketing',        2, 'Brand identity, messaging and PR'],
            // Tier 2 — Finance
            ['CFO',                         'চিফ ফিন্যান্সিয়াল অফিসার',        'Finance',          2, 'Financial planning, reporting and compliance'],
            ['Accountant',                  'হিসাবরক্ষক',                       'Finance',          2, 'Bookkeeping, invoicing and payroll'],
            // Tier 2 — Customer Service
            ['CS Manager',                  'কাস্টমার সার্ভিস ম্যানেজার',      'Customer Service', 2, 'Manages support team and escalation resolution'],
            ['CS Executive',                'কাস্টমার সার্ভিস এক্সিকিউটিভ',   'Customer Service', 2, 'Handles inbound customer calls and complaints'],
            // Tier 2 — HR
            ['HR Manager',                  'এইচআর ম্যানেজার',                 'HR',               2, 'Recruitment, culture and employee management'],
            ['Recruiter',                   'রিক্রুটার',                        'HR',               2, 'Talent acquisition and onboarding'],
            // Tier 2 — Design
            ['UI/UX Designer',              'ইউআই/ইউএক্স ডিজাইনার',            'Design',           2, 'User experience design and prototyping'],
            ['Graphic Designer',            'গ্রাফিক ডিজাইনার',                'Design',           2, 'Visual assets, banners and brand materials'],
        ];
        $ps = $pdo->prepare("INSERT IGNORE INTO positions (position_name, position_name_bn, department, tier_level, description) VALUES (?,?,?,?,?)");
        foreach ($positions as $p) { $ps->execute($p); }
    }
} catch (Exception $e) {}

// ==========================================
// SMS Sending Function
// ==========================================
if (!function_exists('sendSMSAdmin')) {
    function sendSMSAdmin($phone, $msg) {
        $api_key = $_ENV['SMS_API_KEY'] ?? '';
        if(empty($api_key)) return false;
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL            => 'https://api.sms.net.bd/sendsms',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => ['api_key' => $api_key, 'msg' => $msg, 'to' => $phone],
        ]);
        $response = curl_exec($curl);
        curl_close($curl);
        return $response;
    }
}

// Session Messages
$message = $_SESSION['msg_success'] ?? '';
$error   = $_SESSION['msg_error'] ?? '';
$warning = $_SESSION['msg_warning'] ?? '';
unset($_SESSION['msg_success'], $_SESSION['msg_error'], $_SESSION['msg_warning']);

// ==========================================
// 3. POST ACTIONS 
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'save_role') {
        $rid  = (int)($_POST['role_id'] ?? 0);
        $name = trim($_POST['role_name']);
        $desc = trim($_POST['role_description']);
        $dept = trim($_POST['department']);
        $color= $_POST['color'] ?? '#3B82F6';
        $icon = $_POST['icon'] ?? 'fa-user-tie';
        if($rid > 0) {
            // পুরনো নাম UPDATE এর আগে নিতে হবে
            $old_stmt = $pdo->prepare("SELECT role_name FROM kpi_roles WHERE id=?");
            $old_stmt->execute([$rid]);
            $old_role_name = $old_stmt->fetchColumn();

            $pdo->prepare("UPDATE kpi_roles SET role_name=?, role_description=?, department=?, color=?, icon=? WHERE id=?")->execute([$name,$desc,$dept,$color,$icon,$rid]);

            if($old_role_name && $old_role_name !== $name) {
                $pdo->prepare("UPDATE kpi_metrics     SET role_name=? WHERE role_name=?")->execute([$name, $old_role_name]);
                $pdo->prepare("UPDATE role_settings   SET role_name=? WHERE role_name=?")->execute([$name, $old_role_name]);
                $pdo->prepare("UPDATE advisor_targets  SET role_name=? WHERE role_name=?")->execute([$name, $old_role_name]);
                $pdo->prepare("UPDATE kpi_evaluations SET role_name=? WHERE role_name=?")->execute([$name, $old_role_name]);
                $pdo->prepare("UPDATE kpi_daily_updates SET role_name=? WHERE role_name=?")->execute([$name, $old_role_name]);
            }
        } else {
            $pdo->prepare("INSERT INTO kpi_roles (role_name, role_description, department, color, icon) VALUES (?,?,?,?,?)")->execute([$name,$desc,$dept,$color,$icon]);
            $pdo->prepare("INSERT IGNORE INTO role_settings (role_name, profit_share_pct) VALUES (?,0)")->execute([$name]);
        }
        $_SESSION['msg_success'] = "পদ সংরক্ষিত হয়েছে!";
        header("Location: manage_kpi.php#roles"); exit;
    }

    if ($action === 'delete_role') {
        $rid = (int)$_POST['role_id'];
        $rn  = $pdo->prepare("SELECT role_name FROM kpi_roles WHERE id=?");
        $rn->execute([$rid]);
        $rname = $rn->fetchColumn();
        $pdo->prepare("DELETE FROM kpi_roles WHERE id=?")->execute([$rid]);
        $pdo->prepare("DELETE FROM kpi_metrics WHERE role_name=?")->execute([$rname]);
        $_SESSION['msg_success'] = "পদ মুছে ফেলা হয়েছে।";
        header("Location: manage_kpi.php#roles"); exit;
    }

    if ($action === 'add_metric') {
        $role    = $_POST['metric_role'];
        $name    = trim($_POST['metric_name']);
        $desc    = trim($_POST['metric_description'] ?? '');
        $cat     = trim($_POST['metric_category'] ?? 'General');
        $score   = (int)$_POST['max_score'];
        $mtype   = $_POST['measurement_type'] ?? 'number';
        $target  = trim($_POST['target_value'] ?? '');
        $subs    = isset($_POST['sub_fields']) ? array_values(array_filter(array_map('trim', $_POST['sub_fields']))) : [];
        $subs_j  = !empty($subs) ? json_encode($subs, JSON_UNESCAPED_UNICODE) : null;
        $pdo->prepare("INSERT INTO kpi_metrics (role_name, metric_name, metric_description, category, max_score, measurement_type, target_value, sub_fields) VALUES (?,?,?,?,?,?,?,?)")->execute([$role,$name,$desc,$cat,$score,$mtype,$target,$subs_j]);
        $_SESSION['msg_success'] = "নতুন KPI মেট্রিক যুক্ত হয়েছে!";
        header("Location: manage_kpi.php#metrics"); exit;
    }

    if ($action === 'delete_metric') {
        $pdo->prepare("DELETE FROM kpi_metrics WHERE id=?")->execute([(int)$_POST['metric_id']]);
        $_SESSION['msg_success'] = "মেট্রিক মুছে ফেলা হয়েছে।";
        header("Location: manage_kpi.php#metrics"); exit;
    }

    if ($action === 'assign_advisor') {
        $uid    = (int)$_POST['user_id'];
        $rname  = $_POST['role_name'];
        $targets= $_POST['targets'] ?? [];
        $td_j   = json_encode($targets, JSON_UNESCAPED_UNICODE);

        // Duplicate check করো — একই user একই role এ দুবার assign হওয়া ঠেকাও
        $chk_dup = $pdo->prepare("SELECT id FROM advisor_targets WHERE user_id=? AND role_name=?");
        $chk_dup->execute([$uid, $rname]);
        $existing = $chk_dup->fetch();
        if($existing) {
            $pdo->prepare("UPDATE advisor_targets SET target_data=? WHERE id=?")->execute([$td_j, $existing['id']]);
        } else {
            $pdo->prepare("INSERT INTO advisor_targets (user_id, role_name, target_data) VALUES (?,?,?)")->execute([$uid, $rname, $td_j]);
        }
        
        // SMS Notification Logic
        $stmt_ph = $pdo->prepare("SELECT phone FROM shareholder_accounts WHERE id=?");
        $stmt_ph->execute([$uid]);
        $phone = $stmt_ph->fetchColumn();
        
        if(!empty($phone)) {
            $msg = "Sodai Lagbe: আপনাকে '{$rname}' পদে নতুন KPI টার্গেট দেওয়া হয়েছে। অনুগ্রহ করে আপনার প্যানেল চেক করুন।";
            sendSMSAdmin($phone, $msg);
            $_SESSION['msg_success'] = "অ্যাডভাইজর নিয়োগ ও টার্গেট সংরক্ষিত হয়েছে এবং ইউজারকে এসএমএস পাঠানো হয়েছে!";
        } else {
            $_SESSION['msg_success'] = "অ্যাডভাইজর নিয়োগ ও টার্গেট সংরক্ষিত হয়েছে!";
            $_SESSION['msg_warning'] = "উক্ত ব্যক্তির অ্যাকাউন্টে কোনো মোবাইল নম্বর না থাকায় এসএমএস পাঠানো যায়নি। দ্রুত তাকে মোবাইল নম্বর যুক্ত করার নির্দেশ দিন।";
        }
        
        header("Location: manage_kpi.php#advisors"); exit;
    }

    if ($action === 'remove_advisor') {
        $pdo->prepare("DELETE FROM advisor_targets WHERE id=?")->execute([(int)$_POST['assign_id']]);
        $_SESSION['msg_success'] = "অ্যাডভাইজর অপসারণ করা হয়েছে।";
        header("Location: manage_kpi.php#advisors"); exit;
    }

    if ($action === 'evaluate_kpi') {
        $parts    = explode('|', $_POST['user_role_val']);
        $uid      = (int)$parts[0];
        $rname    = $parts[1] ?? '';
        $month    = $_POST['eval_month'];
        $year     = date('Y');
        $remarks  = trim($_POST['remarks'] ?? '');
        $scores   = $_POST['scores'] ?? [];

        if($rname && $uid > 0) {
            $total = array_sum($scores);
            $grade = $total >= 90 ? 'Exceptional' : ($total >= 75 ? 'Excellent' : ($total >= 60 ? 'Good' : ($total >= 40 ? 'Average' : ($total >= 20 ? 'Needs Improvement' : 'Poor'))));
            $mdata = json_encode($scores, JSON_UNESCAPED_UNICODE);
            $chk = $pdo->prepare("SELECT id FROM kpi_evaluations WHERE user_id=? AND role_name=? AND eval_month=? AND eval_year=?");
            $chk->execute([$uid,$rname,$month,$year]);
            if($chk->rowCount() > 0) {
                $_SESSION['msg_error'] = "এই মাসে উক্ত ব্যক্তির মূল্যায়ন ইতিমধ্যেই সম্পন্ন হয়েছে।";
            } else {
                $pdo->prepare("INSERT INTO kpi_evaluations (user_id, role_name, eval_month, eval_year, total_score, performance_grade, remarks, evaluated_by, metrics_data) VALUES (?,?,?,?,?,?,?,?,?)")->execute([$uid,$rname,$month,$year,$total,$grade,$remarks,$_SESSION['admin_account_id']??0,$mdata]);
                $_SESSION['msg_success'] = "KPI মূল্যায়ন সফলভাবে সংরক্ষিত হয়েছে!";
            }
        }
        header("Location: manage_kpi.php#evaluations"); exit;
    }

    if ($action === 'delete_evaluation') {
        $pdo->prepare("DELETE FROM kpi_evaluations WHERE id=?")->execute([(int)$_POST['eval_id']]);
        $_SESSION['msg_success'] = "মূল্যায়ন মুছে ফেলা হয়েছে।";
        header("Location: manage_kpi.php#evaluations"); exit;
    }

    if ($action === 'verify_daily_report') {
        $report_id = (int)$_POST['report_id'];
        $status = $_POST['status'];
        $remarks = trim($_POST['admin_remarks'] ?? '');
        
        // Fetch Report Info First
        $rep_stmt = $pdo->prepare("SELECT user_id, report_date FROM kpi_daily_updates WHERE id=?");
        $rep_stmt->execute([$report_id]);
        $rep_info = $rep_stmt->fetch();
        
        // Update Status
        $pdo->prepare("UPDATE kpi_daily_updates SET status=?, admin_remarks=? WHERE id=?")->execute([$status, $remarks, $report_id]);
        
        // SMS Notification Logic
        if($rep_info) {
            $stmt_ph = $pdo->prepare("SELECT phone FROM shareholder_accounts WHERE id=?");
            $stmt_ph->execute([$rep_info['user_id']]);
            $phone = $stmt_ph->fetchColumn();
            
            if(!empty($phone)) {
                $status_txt = ($status == 'verified') ? 'ভেরিফাই (Verified)' : 'বাতিল (Rejected)';
                $date_txt = date('d M', strtotime($rep_info['report_date']));
                $msg = "Sodai Lagbe: আপনার {$date_txt} তারিখের কাজের রিপোর্টটি অ্যাডমিন দ্বারা {$status_txt} হয়েছে। প্যানেল চেক করুন।";
                sendSMSAdmin($phone, $msg);
                $_SESSION['msg_success'] = "রিপোর্ট স্ট্যাটাস আপডেট হয়েছে এবং ইউজারকে এসএমএস পাঠানো হয়েছে!";
            } else {
                $_SESSION['msg_success'] = "রিপোর্ট স্ট্যাটাস আপডেট হয়েছে!";
                $_SESSION['msg_warning'] = "ইউজারের মোবাইল নম্বর না থাকায় এসএমএস পাঠানো যায়নি! দ্রুত তাকে মোবাইল নম্বর যুক্ত করার নির্দেশ দিন।";
            }
        }
        
        header("Location: manage_kpi.php#reports"); exit;
    }

    if ($action === 'save_fund_settings') {
        $pct = (float)$_POST['advisor_fund_pct'];
        $pdo->prepare("INSERT INTO system_settings (setting_name, setting_value) VALUES ('advisor_fund_pct', ?) ON DUPLICATE KEY UPDATE setting_value = ?")->execute([$pct, $pct]);
        $profit_pcts = $_POST['profit_pct'] ?? [];
        $s = $pdo->prepare("INSERT INTO role_settings (role_name, profit_share_pct) VALUES (?,?) ON DUPLICATE KEY UPDATE profit_share_pct=?");
        foreach($profit_pcts as $rn => $pp) { $s->execute([$rn, (float)$pp, (float)$pp]); }
        $_SESSION['msg_success'] = "ফান্ড ও প্রফিট শেয়ার সেটিং আপডেট হয়েছে!";
        header("Location: manage_kpi.php#settings"); exit;
    }
}

// ==========================================
// 4. SAFE FETCH DATA FOR DASHBOARD
// ==========================================
$total_profit = (float)(safeColumn($pdo, "SELECT COALESCE(SUM(amount),0) FROM financials WHERE type='profit' AND status='approved'") ?: 0);
$adv_fund_pct = (float)(safeColumn($pdo, "SELECT setting_value FROM system_settings WHERE setting_name='advisor_fund_pct'") ?: 10);
$total_adv_fund = $total_profit * ($adv_fund_pct / 100);

$all_roles = safeQuery($pdo, "SELECT * FROM kpi_roles ORDER BY department, role_name");
$role_names = array_column($all_roles, 'role_name');

$metrics_raw = safeQuery($pdo, "SELECT * FROM kpi_metrics WHERE is_active=1 ORDER BY role_name, id");
$metrics_by_role = [];
foreach($metrics_raw as $m) { $metrics_by_role[$m['role_name']][] = $m; }

$role_settings_raw = safeQuery($pdo, "SELECT * FROM role_settings");
$role_settings = [];
foreach($role_settings_raw as $rs) { $role_settings[$rs['role_name']] = $rs; }

$accounts = safeQuery($pdo, "SELECT id, name, username, profile_picture FROM shareholder_accounts ORDER BY name ASC");

$assigned_advisors = safeQuery($pdo, "
    SELECT at.*, a.name, a.username, a.profile_picture
    FROM advisor_targets at
    JOIN shareholder_accounts a ON at.user_id = a.id
    ORDER BY at.assigned_at DESC
");

$evaluations = safeQuery($pdo, "
    SELECT k.*, a.name, a.username, a.profile_picture
    FROM kpi_evaluations k
    JOIN shareholder_accounts a ON k.user_id = a.id
    ORDER BY k.created_at DESC
");

$pending_reports = safeQuery($pdo, "
    SELECT d.*, a.name, a.username, a.profile_picture
    FROM kpi_daily_updates d
    JOIN shareholder_accounts a ON d.user_id = a.id
    WHERE d.status = 'pending'
    ORDER BY d.report_date DESC
");

$all_reports = safeQuery($pdo, "
    SELECT d.*, a.name, a.username, a.profile_picture
    FROM kpi_daily_updates d
    JOIN shareholder_accounts a ON d.user_id = a.id
    ORDER BY d.report_date DESC
    LIMIT 50
");

$total_advisors = count($assigned_advisors);
$total_evaluations = count($evaluations);
$avg_score = $total_evaluations > 0 ? array_sum(array_column($evaluations, 'total_score')) / $total_evaluations : 0;

$role_map = [];
foreach($all_roles as $r) { $role_map[$r['role_name']] = $r; }
$departments = array_unique(array_column($all_roles, 'department'));

?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>KPI Management — Sodai Lagbe Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<style>
body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; -webkit-tap-highlight-color: transparent;}
.glass-header { background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border-bottom: 1px solid rgba(226,232,240,0.8); }
.app-card { background: #ffffff; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.03), 0 1px 3px rgba(0,0,0,0.02); border: 1px solid rgba(226, 232, 240, 0.8); }
.custom-scrollbar::-webkit-scrollbar { width: 4px; height: 4px; } 
.custom-scrollbar::-webkit-scrollbar-thumb { background-color: #cbd5e1; border-radius: 10px; } 
.bottom-nav { position: fixed; bottom: 0; left: 0; right: 0; background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px); border-top: 1px solid rgba(0,0,0,0.05); padding-bottom: env(safe-area-inset-bottom); z-index: 50; display: flex; justify-content: space-around; }
.nav-item { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 10px 0; color: #64748b; font-size: 10px; font-weight: 700; width: 100%; transition: all 0.2s; cursor: pointer; text-decoration: none;}
.nav-item.active { color: #2563eb; }
.nav-item i { font-size: 18px; margin-bottom: 3px; transition: transform 0.2s;}
.nav-item.active i { transform: translateY(-2px); }
@media (min-width: 768px) { .bottom-nav { display: none; } }
@keyframes fadeInFast { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
.animate-fade-in { animation: fadeInFast 0.4s ease-out forwards; }
.score-ring { width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 18px; position: relative; }
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
        <a href="manage_permanent_expenses.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-file-invoice w-6"></i> স্থায়ী মাসিক খরচ</a>
        <a href="manage_kpi.php" class="flex items-center px-6 py-3 bg-blue-600/20 text-blue-400 border-l-4 border-blue-500 font-semibold"><i class="fas fa-bullseye w-6"></i> KPI ম্যানেজমেন্ট</a>
        <a href="manage_votes.php" class="flex items-center px-6 py-3 text-slate-300 hover:bg-slate-800 hover:text-white transition font-medium"><i class="fas fa-vote-yea w-6"></i> ভোটিং ও প্রস্তাবনা</a>
        
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
            <h2 class="text-lg font-black tracking-tight text-slate-800 hidden sm:block">KPI ম্যানেজমেন্ট</h2>
        </div>
        <div class="flex items-center gap-3">
            <!-- Pending Reports Alert Bell -->
            <?php if(count($pending_reports) > 0): ?>
            <button onclick="switchTab('reports')" class="relative bg-rose-50 hover:bg-rose-100 border border-rose-200 text-rose-600 px-3 py-1.5 rounded-lg text-xs font-black shadow-sm transition flex items-center gap-2" title="<?= count($pending_reports) ?>টি রিপোর্ট পেন্ডিং">
                <i class="fas fa-bell"></i>
                <span class="hidden md:inline"><?= count($pending_reports) ?> পেন্ডিং</span>
                <span class="absolute -top-1.5 -right-1.5 w-4 h-4 bg-rose-500 text-white text-[9px] font-black rounded-full flex items-center justify-center animate-pulse"><?= count($pending_reports) ?></span>
            </button>
            <?php endif; ?>
            <button onclick="switchTab('settings')" class="bg-white hover:bg-slate-50 text-slate-700 px-3 py-1.5 rounded-lg text-xs font-bold shadow-sm transition flex items-center gap-2 border border-slate-200">
                <i class="fas fa-cogs text-blue-500"></i> <span class="hidden md:inline">Settings</span>
            </button>
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
        <div class="space-y-6 max-w-7xl mx-auto">
            
            <?php if($message): ?><div class="bg-emerald-50 text-emerald-600 px-4 py-3 rounded-xl text-sm font-bold shadow-sm border border-emerald-100 flex items-center animate-fade-in"><i class="fas fa-check-circle mr-2 text-lg"></i> <?= $message ?></div><?php endif; ?>
            <?php if($error): ?><div class="bg-red-50 text-red-600 px-4 py-3 rounded-xl text-sm font-bold shadow-sm border border-red-100 flex items-center animate-fade-in"><i class="fas fa-exclamation-triangle mr-2 text-lg"></i> <?= $error ?></div><?php endif; ?>
            <?php if($warning): ?><div class="bg-amber-50 text-amber-700 px-4 py-3 rounded-xl text-sm font-bold shadow-sm border border-amber-200 flex items-center animate-fade-in"><i class="fas fa-exclamation-circle mr-2 text-lg"></i> <?= $warning ?></div><?php endif; ?>

            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 animate-fade-in">
              <div class="app-card p-5 border-l-4 border-blue-500 cursor-pointer hover:shadow-md transition" onclick="switchTab('roles')">
                <div class="flex items-center justify-between mb-2">
                    <div class="text-[10px] font-bold uppercase tracking-wide text-slate-500">মোট পদ</div>
                    <div class="w-8 h-8 rounded-lg bg-blue-50 flex items-center justify-center"><i class="fas fa-layer-group text-blue-500 text-xs"></i></div>
                </div>
                <div class="text-2xl font-black text-blue-600"><?= count($all_roles) ?></div>
                <div class="text-[9px] font-bold text-slate-400 mt-1"><?= count($departments) ?> বিভাগে সক্রিয়</div>
              </div>
              <div class="app-card p-5 border-l-4 border-indigo-500 cursor-pointer hover:shadow-md transition" onclick="switchTab('reports')">
                <div class="flex items-center justify-between mb-2">
                    <div class="text-[10px] font-bold uppercase tracking-wide text-slate-500">অ্যাডভাইজর</div>
                    <div class="w-8 h-8 rounded-lg bg-indigo-50 flex items-center justify-center relative">
                        <i class="fas fa-user-tie text-indigo-500 text-xs"></i>
                        <?php if(count($pending_reports) > 0): ?><span class="absolute -top-1 -right-1 w-3.5 h-3.5 bg-rose-500 text-white text-[8px] font-black rounded-full flex items-center justify-center"><?= count($pending_reports) ?></span><?php endif; ?>
                    </div>
                </div>
                <div class="text-2xl font-black text-indigo-600"><?= $total_advisors ?></div>
                <div class="text-[9px] font-bold <?= count($pending_reports) > 0 ? 'text-rose-500' : 'text-slate-400' ?> mt-1">
                    <?= count($pending_reports) > 0 ? count($pending_reports).' টি রিপোর্ট অপেক্ষায়!' : 'সব রিপোর্ট review করা হয়েছে ✅' ?>
                </div>
              </div>
              <div class="app-card p-5 border-l-4 border-emerald-500">
                <div class="flex items-center justify-between mb-2">
                    <div class="text-[10px] font-bold uppercase tracking-wide text-slate-500">অ্যাডভাইজর ফান্ড</div>
                    <div class="w-8 h-8 rounded-lg bg-emerald-50 flex items-center justify-center"><i class="fas fa-coins text-emerald-500 text-xs"></i></div>
                </div>
                <div class="text-xl font-black text-emerald-600">৳ <?= number_format($total_adv_fund, 0) ?></div>
                <div class="text-[9px] font-bold text-slate-400 mt-1"><?= $adv_fund_pct ?>% মোট মুনাফার</div>
              </div>
              <div class="app-card p-5 border-l-4 border-amber-500 cursor-pointer hover:shadow-md transition" onclick="switchTab('evaluations')">
                <div class="flex items-center justify-between mb-2">
                    <div class="text-[10px] font-bold uppercase tracking-wide text-slate-500">গড় KPI স্কোর</div>
                    <div class="w-8 h-8 rounded-lg bg-amber-50 flex items-center justify-center"><i class="fas fa-chart-bar text-amber-500 text-xs"></i></div>
                </div>
                <div class="text-2xl font-black <?= $avg_score >= 75 ? 'text-emerald-600' : ($avg_score >= 50 ? 'text-amber-600' : 'text-rose-600') ?>"><?= number_format($avg_score, 1) ?></div>
                <div class="text-[9px] font-bold text-slate-400 mt-1"><?= $total_evaluations ?> টি মূল্যায়নের ভিত্তিতে</div>
              </div>
            </div>

            <div class="animate-fade-in" style="animation-delay:0.1s">
                <div class="flex flex-wrap gap-2 mb-5 overflow-x-auto pb-2 custom-scrollbar" id="tab-buttons">
                    <button class="tab-btn active bg-indigo-600 text-white shadow-md px-4 py-2 rounded-lg text-xs font-bold transition whitespace-nowrap" id="btn-roles" onclick="switchTab('roles')"><i class="fas fa-layer-group mr-1.5 opacity-70"></i> পদ ও ভূমিকা</button>
                    <button class="tab-btn bg-white text-slate-600 border border-slate-200 hover:bg-slate-50 px-4 py-2 rounded-lg text-xs font-bold transition whitespace-nowrap" id="btn-metrics" onclick="switchTab('metrics')"><i class="fas fa-tasks mr-1.5 opacity-70"></i> KPI মেট্রিক্স</button>
                    <button class="tab-btn bg-white text-slate-600 border border-slate-200 hover:bg-slate-50 px-4 py-2 rounded-lg text-xs font-bold transition whitespace-nowrap" id="btn-advisors" onclick="switchTab('advisors')"><i class="fas fa-user-tie mr-1.5 opacity-70"></i> অ্যাডভাইজর ప্যানেল</button>
                    <button class="tab-btn bg-white text-slate-600 border border-slate-200 hover:bg-slate-50 px-4 py-2 rounded-lg text-xs font-bold transition whitespace-nowrap" id="btn-evaluations" onclick="switchTab('evaluations')"><i class="fas fa-clipboard-check mr-1.5 opacity-70"></i> মূল্যায়ন</button>
                    <button class="tab-btn bg-white text-slate-600 border border-slate-200 hover:bg-slate-50 px-4 py-2 rounded-lg text-xs font-bold transition whitespace-nowrap relative" id="btn-reports" onclick="switchTab('reports')">
                        <i class="fas fa-file-alt mr-1.5 opacity-70"></i> দৈনিক রিপোর্ট
                        <?php if(count($pending_reports) > 0): ?><span class="absolute -top-1.5 -right-1.5 px-1.5 py-0.5 text-[9px] font-black rounded-full bg-rose-500 text-white shadow-sm"><?= count($pending_reports) ?></span><?php endif; ?>
                    </button>
                </div>

                <div id="tab-roles" class="tab-pane block">
                    <div class="flex items-center justify-between mb-4 bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
                        <h2 class="text-base font-black text-slate-800"><i class="fas fa-layer-group text-blue-500 mr-2"></i> পদ ও দায়িত্বের তালিকা</h2>
                        <button onclick="openRoleModal()" class="bg-blue-600 text-white px-3 py-1.5 rounded-lg text-xs font-bold shadow-sm hover:bg-blue-700 transition"><i class="fas fa-plus mr-1"></i> নতুন পদ</button>
                    </div>
                    <?php
                    $roles_by_dept = [];
                    foreach($all_roles as $r) { $roles_by_dept[$r['department']][] = $r; }
                    ?>
                    <?php foreach($roles_by_dept as $dept => $droles): ?>
                    <div class="mb-6">
                        <div class="flex items-center gap-3 mb-3">
                            <span class="text-[10px] font-black uppercase tracking-widest text-slate-500 bg-slate-200 px-3 py-1 rounded-full"><?= htmlspecialchars($dept) ?></span>
                            <div class="h-px flex-1 bg-slate-200"></div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                            <?php foreach($droles as $role):
                                $rmetrics = $metrics_by_role[$role['role_name']] ?? [];
                                $rset = $role_settings[$role['role_name']] ?? ['profit_share_pct' => 0];
                                $assigned_count = 0;
                                foreach($assigned_advisors as $adv) { if($adv['role_name'] == $role['role_name']) $assigned_count++; }
                            ?>
                            <div class="app-card border-t-4 bg-white hover:shadow-md transition" style="border-top-color: <?= $role['color'] ?>;">
                                <div class="p-4 border-b border-slate-100 flex justify-between items-start">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 rounded-lg flex items-center justify-center text-lg shadow-sm border border-slate-100" style="background:<?= $role['color'] ?>15;color:<?= $role['color'] ?>">
                                            <i class="fas <?= $role['icon'] ?>"></i>
                                        </div>
                                        <div>
                                            <h4 class="font-bold text-sm text-slate-800"><?= htmlspecialchars($role['role_name']) ?></h4>
                                            <span class="text-[9px] font-bold text-slate-400 uppercase tracking-wider"><?= htmlspecialchars($role['department']) ?></span>
                                        </div>
                                    </div>
                                    <div class="flex gap-1.5">
                                        <button onclick='openRoleModal(<?= json_encode($role) ?>)' class="w-6 h-6 rounded bg-blue-50 text-blue-600 flex items-center justify-center hover:bg-blue-600 hover:text-white transition"><i class="fas fa-edit text-[10px]"></i></button>
                                        <form method="POST" onsubmit="return confirm('এই পদ এবং এর সব মেট্রিক্স মুছে যাবে। নিশ্চিত?')">
                                            <input type="hidden" name="action" value="delete_role">
                                            <input type="hidden" name="role_id" value="<?= $role['id'] ?>">
                                            <button type="submit" class="w-6 h-6 rounded bg-rose-50 text-rose-500 flex items-center justify-center hover:bg-rose-500 hover:text-white transition"><i class="fas fa-trash text-[10px]"></i></button>
                                        </form>
                                    </div>
                                </div>
                                <div class="p-4">
                                    <?php if(!empty($role['role_description'])): ?>
                                        <p class="text-xs text-slate-500 mb-3 leading-relaxed line-clamp-2"><?= htmlspecialchars($role['role_description']) ?></p>
                                    <?php endif; ?>
                                    <div class="flex flex-wrap gap-2 text-[9px] font-bold mt-auto">
                                        <span class="bg-slate-50 border border-slate-100 text-slate-600 px-2 py-1 rounded-md"><i class="fas fa-tasks text-blue-500 mr-1"></i> <?= count($rmetrics) ?> Metrics</span>
                                        <span class="bg-slate-50 border border-slate-100 text-slate-600 px-2 py-1 rounded-md"><i class="fas fa-user-check text-emerald-500 mr-1"></i> <?= $assigned_count ?> Assigned</span>
                                        <span class="bg-slate-50 border border-slate-100 text-slate-600 px-2 py-1 rounded-md"><i class="fas fa-percent text-amber-500 mr-1"></i> <?= $rset['profit_share_pct'] ?>% Share</span>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if(empty($all_roles)): ?>
                    <div class="bg-white rounded-xl border border-dashed border-slate-300 p-12 text-center text-slate-400">
                        <i class="fas fa-layer-group text-4xl mb-3 opacity-50"></i>
                        <p class="font-bold text-sm">কোনো পদ নেই। "নতুন পদ" বাটন দিয়ে শুরু করুন।</p>
                    </div>
                    <?php endif; ?>
                </div>

                <div id="tab-metrics" class="tab-pane hidden">
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <div class="lg:col-span-1">
                            <div class="app-card bg-white sticky top-20 shadow-sm border border-slate-200 overflow-hidden">
                                <div class="p-4 border-b border-slate-100 bg-slate-50/50">
                                    <h3 class="font-black text-sm text-slate-800"><i class="fas fa-plus-circle text-blue-500 mr-1.5"></i> নতুন KPI মেট্রিক</h3>
                                </div>
                                <form method="POST" class="p-5 space-y-4">
                                    <input type="hidden" name="action" value="add_metric">
                                    <div>
                                        <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5">পদ (Role) <span class="text-rose-500">*</span></label>
                                        <select name="metric_role" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-xs outline-none focus:bg-white focus:border-blue-500 font-bold text-slate-700 transition shadow-sm" required>
                                            <option value="">-- পদ নির্বাচন করুন --</option>
                                            <?php foreach($all_roles as $r): ?>
                                                <option value="<?= htmlspecialchars($r['role_name']) ?>"><?= htmlspecialchars($r['role_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5">মেট্রিকের নাম <span class="text-rose-500">*</span></label>
                                        <input type="text" name="metric_name" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-xs outline-none focus:bg-white focus:border-blue-500 transition shadow-sm" placeholder="যেমন: Monthly Sales Target" required>
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5">বিবরণ</label>
                                        <textarea name="metric_description" rows="2" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-xs outline-none focus:bg-white focus:border-blue-500 custom-scrollbar transition shadow-sm" placeholder="এই KPI কী পরিমাপ করে..."></textarea>
                                    </div>
                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5">বিভাগ</label>
                                            <input type="text" name="metric_category" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-xs outline-none focus:bg-white focus:border-blue-500 transition shadow-sm" placeholder="Sales, Quality...">
                                        </div>
                                        <div>
                                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5">সর্বোচ্চ স্কোর <span class="text-rose-500">*</span></label>
                                            <input type="number" name="max_score" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-xs outline-none focus:bg-white focus:border-blue-500 font-black text-indigo-600 transition shadow-sm" value="100" required>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5">লক্ষ্যমাত্রা (Target)</label>
                                        <input type="text" name="target_value" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-xs outline-none focus:bg-white focus:border-blue-500 transition shadow-sm" placeholder="যেমন: 95% success rate">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5">মাপার ধরন</label>
                                        <select name="measurement_type" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-xs outline-none focus:bg-white focus:border-blue-500 transition shadow-sm font-bold text-slate-700">
                                            <option value="number">সংখ্যা (Number)</option>
                                            <option value="percentage">শতাংশ (Percentage)</option>
                                            <option value="rating">রেটিং (1-10)</option>
                                            <option value="boolean">হ্যাঁ/না (Yes/No)</option>
                                            <option value="text">বিবরণ (Text)</option>
                                        </select>
                                    </div>
                                    <div class="bg-blue-50/50 p-4 rounded-xl border border-blue-100">
                                        <label class="block text-[10px] font-bold text-blue-800 uppercase mb-2">রিপোর্ট ফিল্ডসমূহ (User Fields)</label>
                                        <div id="metric_subfields_container" class="space-y-2">
                                            <div class="flex gap-2">
                                                <input type="text" name="sub_fields[]" class="flex-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs outline-none focus:border-blue-500 transition shadow-sm" placeholder="Target (লক্ষ্যমাত্রা)" required>
                                                <button type="button" onclick="this.closest('.flex').remove()" class="w-8 h-8 bg-rose-50 text-rose-500 rounded-lg hover:bg-rose-500 hover:text-white flex items-center justify-center shrink-0 transition border border-rose-100"><i class="fas fa-times text-[10px]"></i></button>
                                            </div>
                                            <div class="flex gap-2">
                                                <input type="text" name="sub_fields[]" class="flex-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs outline-none focus:border-blue-500 transition shadow-sm" placeholder="Result (ফলাফল)">
                                                <button type="button" onclick="this.closest('.flex').remove()" class="w-8 h-8 bg-rose-50 text-rose-500 rounded-lg hover:bg-rose-500 hover:text-white flex items-center justify-center shrink-0 transition border border-rose-100"><i class="fas fa-times text-[10px]"></i></button>
                                            </div>
                                        </div>
                                        <button type="button" onclick="addMetricSubField()" class="mt-3 text-[10px] font-bold text-blue-600 bg-blue-100 px-3 py-1.5 rounded-lg hover:bg-blue-600 hover:text-white transition"><i class="fas fa-plus mr-1"></i> ফিল্ড যোগ করুন</button>
                                    </div>
                                    <button type="submit" class="w-full bg-blue-600 text-white font-bold py-3 rounded-xl shadow-md hover:bg-blue-700 hover:shadow-lg transition-all active:scale-95 text-xs mt-2"><i class="fas fa-save mr-1"></i> মেট্রিক সংরক্ষণ</button>
                                </form>
                            </div>
                        </div>

                        <div class="lg:col-span-2 space-y-5">
                            <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm flex items-center justify-between">
                                <span class="text-xs font-bold text-slate-600"><i class="fas fa-filter mr-1"></i> ফিল্টার:</span>
                                <select id="metricRoleFilter" onchange="filterMetrics()" class="bg-slate-50 border border-slate-200 rounded-lg px-3 py-1.5 text-xs font-bold outline-none focus:border-blue-500 text-slate-700 cursor-pointer transition">
                                    <option value="all">-- সব পদ দেখুন --</option>
                                    <?php foreach($all_roles as $r): ?>
                                        <option value="<?= htmlspecialchars($r['role_name']) ?>"><?= htmlspecialchars($r['role_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <?php foreach($metrics_by_role as $rname => $rmetrics):
                                $rinfo = $role_map[$rname] ?? ['color'=>'#4F8EF7','icon'=>'fa-user-tie'];
                            ?>
                            <div class="metric-role-group bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden" data-role="<?= htmlspecialchars($rname) ?>">
                                <div class="bg-slate-50 px-4 py-3 border-b border-slate-100 flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <div class="w-8 h-8 rounded-lg flex items-center justify-center text-sm shadow-sm border border-slate-100 bg-white" style="color:<?= $rinfo['color'] ?>"><i class="fas <?= $rinfo['icon'] ?>"></i></div>
                                        <h3 class="font-black text-sm text-slate-800"><?= htmlspecialchars($rname) ?></h3>
                                    </div>
                                    <span class="text-[10px] px-2 py-0.5 rounded-full font-bold bg-slate-200 text-slate-600 border border-slate-300"><?= count($rmetrics) ?> metrics</span>
                                </div>
                                <div class="p-3 space-y-2">
                                    <?php foreach($rmetrics as $m):
                                        $subs = json_decode($m['sub_fields'], true);
                                        $mtype_icons = ['number'=>'fa-hashtag','percentage'=>'fa-percent','rating'=>'fa-star','boolean'=>'fa-toggle-on','text'=>'fa-align-left'];
                                        $mtype_icon = $mtype_icons[$m['measurement_type']??'number'] ?? 'fa-hashtag';
                                    ?>
                                    <div class="flex items-start gap-3 p-3 rounded-xl border border-slate-100 hover:border-blue-200 hover:shadow-sm transition group bg-white">
                                        <div class="w-10 h-10 rounded-lg flex items-center justify-center text-sm flex-shrink-0 bg-slate-50 text-slate-400 border border-slate-200 group-hover:bg-blue-50 group-hover:text-blue-500 group-hover:border-blue-200 transition-colors">
                                            <i class="fas <?= $mtype_icon ?>"></i>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-start justify-between gap-2">
                                                <div>
                                                    <div class="font-black text-sm text-slate-800 leading-tight mb-0.5"><?= htmlspecialchars($m['metric_name']) ?></div>
                                                    <?php if(!empty($m['metric_description'])): ?>
                                                        <div class="text-[10px] font-medium text-slate-500 leading-snug"><?= htmlspecialchars($m['metric_description']) ?></div>
                                                    <?php endif; ?>
                                                </div>
                                                <form method="POST" onsubmit="return confirm('মুছে ফেলবেন?')" class="flex-shrink-0">
                                                    <input type="hidden" name="action" value="delete_metric">
                                                    <input type="hidden" name="metric_id" value="<?= $m['id'] ?>">
                                                    <button type="submit" class="w-7 h-7 rounded-lg bg-rose-50 text-rose-400 hover:bg-rose-500 hover:text-white flex items-center justify-center transition border border-rose-100"><i class="fas fa-trash-alt text-[10px]"></i></button>
                                                </form>
                                            </div>
                                            <div class="flex flex-wrap gap-2 mt-2.5">
                                                <?php if(!empty($m['category'])): ?><span class="text-[9px] px-2 py-0.5 rounded-md font-bold bg-purple-50 text-purple-600 border border-purple-100"><?= htmlspecialchars($m['category']) ?></span><?php endif; ?>
                                                <span class="text-[9px] px-2 py-0.5 rounded-md font-bold bg-blue-50 text-blue-600 border border-blue-100">Max Score: <?= $m['max_score'] ?></span>
                                                <?php if(!empty($m['target_value'])): ?><span class="text-[9px] px-2 py-0.5 rounded-md font-bold bg-emerald-50 text-emerald-600 border border-emerald-100 flex items-center gap-1"><i class="fas fa-bullseye"></i> <?= htmlspecialchars($m['target_value']) ?></span><?php endif; ?>
                                                <?php if(is_array($subs) && count($subs)): ?><span class="text-[9px] px-2 py-0.5 rounded-md font-bold bg-amber-50 text-amber-600 border border-amber-100"><?= count($subs) ?> input fields</span><?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php if(empty($metrics_by_role)): ?>
                            <div class="bg-white rounded-xl border border-dashed border-slate-300 p-12 text-center text-slate-400">
                                <i class="fas fa-tasks text-4xl mb-3 opacity-30"></i>
                                <p class="font-bold text-sm">কোনো মেট্রিক নেই।</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div id="tab-advisors" class="tab-pane hidden">
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <div class="lg:col-span-1">
                            <div class="app-card bg-white sticky top-20 shadow-sm border border-slate-200 overflow-hidden">
                                <div class="p-4 border-b border-slate-100 bg-slate-50/50">
                                    <h3 class="font-black text-sm text-slate-800"><i class="fas fa-user-plus text-indigo-500 mr-1.5"></i> অ্যাডভাইজর নিয়োগ</h3>
                                </div>
                                <form method="POST" class="p-5 space-y-4">
                                    <input type="hidden" name="action" value="assign_advisor">
                                    <div>
                                        <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5">অ্যাকাউন্ট <span class="text-rose-500">*</span></label>
                                        <select name="user_id" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2.5 text-xs outline-none focus:bg-white focus:border-indigo-500 font-bold text-slate-700 transition shadow-sm cursor-pointer" required>
                                            <option value="">-- অ্যাকাউন্ট নির্বাচন করুন --</option>
                                            <?php foreach($accounts as $acc): ?>
                                                <option value="<?= $acc['id'] ?>"><?= htmlspecialchars($acc['name']) ?> (<?= htmlspecialchars($acc['username']) ?>)</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5">পদ (Role) <span class="text-rose-500">*</span></label>
                                        <select name="role_name" id="assignRoleSelect" onchange="loadTargetFields()" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2.5 text-xs outline-none focus:bg-white focus:border-indigo-500 font-bold text-indigo-600 transition shadow-sm cursor-pointer" required>
                                            <option value="">-- পদ নির্বাচন করুন --</option>
                                            <?php foreach($all_roles as $r): ?>
                                                <option value="<?= htmlspecialchars($r['role_name']) ?>"><?= htmlspecialchars($r['role_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div id="targetFieldsContainer" class="hidden">
                                        <div class="bg-indigo-50/50 p-4 rounded-xl border border-indigo-100">
                                            <p class="text-[10px] font-black uppercase tracking-wide text-indigo-800 mb-3 pb-2 border-b border-indigo-200 flex items-center gap-1.5"><i class="fas fa-bullseye text-indigo-500"></i> টার্গেট সেটআপ</p>
                                            <div id="targetInputs" class="space-y-4 max-h-72 overflow-y-auto custom-scrollbar pr-1"></div>
                                        </div>
                                    </div>
                                    <button type="submit" id="assignBtn" class="w-full bg-indigo-600 text-white font-bold py-3 rounded-xl shadow-md hover:bg-indigo-700 hover:shadow-lg transition-all active:scale-95 text-xs mt-2 disabled:opacity-50" disabled><i class="fas fa-save mr-1"></i> নিয়োগ সংরক্ষণ</button>
                                </form>
                            </div>
                        </div>

                        <div class="lg:col-span-2">
                            <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm flex items-center justify-between mb-4">
                                <h2 class="font-black text-slate-800 text-base"><i class="fas fa-users-tie text-emerald-500 mr-2"></i> বর্তমান অ্যাডভাইজর প্যানেল</h2>
                                <span class="bg-emerald-100 text-emerald-700 text-xs font-black px-3 py-1 rounded-lg border border-emerald-200"><?= count($assigned_advisors) ?> জন</span>
                            </div>

                            <?php if(count($assigned_advisors) > 0): ?>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                <?php foreach($assigned_advisors as $adv):
                                    $targets = json_decode($adv['target_data'], true) ?: [];
                                    $rinfo2 = $role_map[$adv['role_name']] ?? ['color'=>'#4F8EF7','icon'=>'fa-user-tie'];
                                    
                                    // Progress Calculation
                                    $curr_month = date('Y-m');
                                    $adv_uid = $adv['user_id'];
                                    $adv_role = $adv['role_name'];
                                    $v_days = (int)safeColumn($pdo, "SELECT COUNT(*) FROM kpi_daily_updates WHERE user_id=? AND role_name=? AND status='verified' AND DATE_FORMAT(report_date, '%Y-%m') = ?", [$adv_uid, $adv_role, $curr_month]);
                                    $t_days = (int)date('t');
                                    
                                    $adv_pct = $role_settings[$adv_role]['profit_share_pct'] ?? 0;
                                    $adv_max_b = $total_adv_fund * ($adv_pct / 100);
                                    $adv_daily_b = ($t_days > 0) ? ($adv_max_b / $t_days) : 0;
                                    $adv_est_b = $v_days * $adv_daily_b;
                                    $adv_prog = ($t_days > 0) ? ($v_days / $t_days) * 100 : 0;
                                ?>
                                <div class="app-card bg-white hover:border-blue-300 hover:shadow-md transition overflow-hidden group">
                                    <div class="p-4">
                                        <div class="flex items-start justify-between mb-4 border-b border-slate-100 pb-4">
                                            <div class="flex items-center gap-3 min-w-0">
                                                <div class="w-12 h-12 rounded-full border-2 border-slate-100 shadow-sm overflow-hidden shrink-0 flex items-center justify-center bg-slate-50 font-black text-slate-400">
                                                    <?php if(!empty($adv['profile_picture'])): ?>
                                                        <img src="../<?= htmlspecialchars($adv['profile_picture']) ?>" class="w-full h-full object-cover">
                                                    <?php else: ?>
                                                        <?= strtoupper(substr($adv['name'],0,1)) ?>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="truncate">
                                                    <h4 class="font-black text-sm text-slate-800 truncate mb-1"><?= htmlspecialchars($adv['name']) ?></h4>
                                                    <div class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded border" style="background:<?= $rinfo2['color'] ?>10; border-color:<?= $rinfo2['color'] ?>30">
                                                        <i class="fas <?= $rinfo2['icon'] ?> text-[9px]" style="color:<?= $rinfo2['color'] ?>"></i>
                                                        <span class="text-[9px] font-bold truncate uppercase tracking-widest" style="color:<?= $rinfo2['color'] ?>"><?= htmlspecialchars($adv['role_name']) ?></span>
                                                    </div>
                                                </div>
                                            </div>
                                            <form method="POST" onsubmit="return confirm('এই অ্যাডভাইজরকে অপসারণ করবেন?')">
                                                <input type="hidden" name="action" value="remove_advisor">
                                                <input type="hidden" name="assign_id" value="<?= $adv['id'] ?>">
                                                <button type="submit" class="w-8 h-8 bg-rose-50 text-rose-500 rounded-lg flex items-center justify-center hover:bg-rose-500 hover:text-white transition shadow-sm border border-rose-100"><i class="fas fa-user-minus text-xs"></i></button>
                                            </form>
                                        </div>
                                        
                                        <div class="mb-4 bg-slate-50 p-3 rounded-xl border border-slate-100">
                                            <div class="flex justify-between text-[9px] font-bold mb-1.5">
                                                <span class="text-slate-500 uppercase tracking-widest">Progress (<?= $v_days ?>/<?= $t_days ?> Days)</span>
                                                <span class="text-emerald-600 bg-emerald-50 px-1.5 py-0.5 rounded border border-emerald-100 shadow-sm">৳<?= number_format($adv_est_b,0) ?> Est.</span>
                                            </div>
                                            <div class="w-full h-2 bg-slate-200 rounded-full overflow-hidden shadow-inner flex">
                                                <div class="bg-emerald-500 h-full" style="width:<?= $adv_prog ?>%"></div>
                                            </div>
                                        </div>

                                        <?php if(!empty($targets)): ?>
                                            <div>
                                                <p class="text-[9px] font-bold uppercase tracking-widest text-slate-400 mb-2">নির্ধারিত টার্গেটসমূহ</p>
                                                <div class="space-y-2.5 max-h-[150px] overflow-y-auto custom-scrollbar pr-1">
                                                    <?php foreach($targets as $mid => $tdata):
                                                        $mname = 'Metric #'.$mid;
                                                        foreach($metrics_raw as $raw) { if($raw['id']==$mid) { $mname=$raw['metric_name']; break; } }
                                                    ?>
                                                    <div class="bg-slate-50 p-3 rounded-lg border border-slate-100 text-[10px]">
                                                        <div class="font-bold text-indigo-700 mb-2 pb-1 border-b border-indigo-100 flex items-start gap-1.5">
                                                            <i class="fas fa-check-circle text-indigo-400 mt-0.5"></i> <?= htmlspecialchars($mname) ?>
                                                        </div>
                                                        <?php if(is_array($tdata)): ?>
                                                            <div class="space-y-1.5">
                                                                <?php foreach($tdata as $label => $val): ?>
                                                                <div class="flex justify-between items-start gap-2 bg-white px-2 py-1.5 rounded border border-slate-100">
                                                                    <span class="text-slate-500 font-bold uppercase w-1/3 truncate text-[8px]" title="<?= htmlspecialchars($label) ?>"><?= htmlspecialchars($label) ?>:</span>
                                                                    <span class="text-slate-800 font-black text-right w-2/3 break-words leading-tight"><?= htmlspecialchars($val) ?></span>
                                                                </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="text-slate-600 bg-white p-2 rounded border border-slate-100 font-medium leading-relaxed"><?= htmlspecialchars($tdata) ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div class="text-xs text-slate-400 italic text-center py-6 bg-slate-50 rounded-xl border border-dashed border-slate-200">কোনো বিস্তারিত টার্গেট নেই।</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <div class="bg-white rounded-xl border border-dashed border-slate-300 p-16 text-center text-slate-400">
                                <i class="fas fa-user-slash text-5xl mb-4 opacity-30 block"></i>
                                <p class="font-bold">কোনো অ্যাডভাইজর নিযুক্ত হননি।</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div id="tab-evaluations" class="tab-pane hidden">
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <div class="lg:col-span-1">
                            <div class="app-card bg-white sticky top-20 shadow-sm border border-slate-200 overflow-hidden">
                                <div class="p-4 border-b border-slate-100 bg-slate-50/50">
                                    <h3 class="font-black text-sm text-slate-800"><i class="fas fa-clipboard-check text-emerald-500 mr-1.5"></i> KPI মূল্যায়ন</h3>
                                </div>
                                <form method="POST" class="p-5 space-y-4" id="evalForm">
                                    <input type="hidden" name="action" value="evaluate_kpi">
                                    <div>
                                        <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5">অ্যাডভাইজর ও পদ <span class="text-rose-500">*</span></label>
                                        <select name="user_role_val" id="evalUserSelect" onchange="loadMetricsForEval()" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2.5 text-xs outline-none focus:bg-white focus:border-emerald-500 font-bold text-slate-700 transition shadow-sm cursor-pointer" required>
                                            <option value="">-- নির্বাচন করুন --</option>
                                            <?php foreach($assigned_advisors as $adv): ?>
                                                <option value="<?= $adv['user_id'] ?>|<?= htmlspecialchars($adv['role_name']) ?>" data-role="<?= htmlspecialchars($adv['role_name']) ?>">
                                                    <?= htmlspecialchars($adv['name']) ?> — <?= htmlspecialchars($adv['role_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5">মাস <span class="text-rose-500">*</span></label>
                                        <input type="month" name="eval_month" value="<?= date('Y-m') ?>" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2.5 text-xs outline-none focus:bg-white focus:border-emerald-500 font-bold text-slate-700 transition shadow-sm cursor-pointer" required>
                                    </div>
                                    
                                    <div id="metricsContainer" class="hidden">
                                        <div class="bg-emerald-50/50 p-4 rounded-xl border border-emerald-100">
                                            <div class="flex justify-between items-center mb-3 border-b border-emerald-200 pb-2">
                                                <p class="text-[10px] font-black uppercase tracking-wide text-emerald-800"><i class="fas fa-star text-amber-400 mr-1"></i> মেট্রিক স্কোর দিন</p>
                                                <div class="text-2xl font-black text-emerald-600 bg-white px-2 py-0.5 rounded shadow-sm border border-emerald-100" id="liveTotal">0</div>
                                            </div>
                                            <div id="metricsInputs" class="space-y-2 max-h-[300px] overflow-y-auto custom-scrollbar pr-1"></div>
                                            <div class="mt-4 pt-3 flex flex-col gap-2 border-t border-emerald-200">
                                                <div class="flex justify-between items-center text-[10px] font-bold text-emerald-800 uppercase">
                                                    <span>মোট স্কোর</span>
                                                    <span>১০০</span>
                                                </div>
                                                <div class="w-full h-2.5 bg-white rounded-full overflow-hidden shadow-inner border border-emerald-100 flex">
                                                    <div id="progressBarFill" class="h-full rounded-full transition-all duration-500 bg-emerald-500" style="width:0%;"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5">মন্তব্য (ঐচ্ছিক)</label>
                                        <textarea name="remarks" rows="2" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2.5 text-xs outline-none focus:bg-white focus:border-emerald-500 custom-scrollbar transition shadow-sm" placeholder="পারফরম্যান্স সম্পর্কে লিখুন..."></textarea>
                                    </div>
                                    <button type="submit" id="evalSubmitBtn" class="w-full bg-emerald-600 text-white font-bold py-3 rounded-xl shadow-md hover:bg-emerald-700 hover:shadow-lg transition-all active:scale-95 text-sm mt-2 disabled:opacity-50" disabled><i class="fas fa-paper-plane mr-1"></i> মূল্যায়ন সাবমিট</button>
                                </form>
                            </div>
                        </div>

                        <div class="lg:col-span-2">
                            <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm flex items-center justify-between mb-5">
                                <h2 class="font-black text-slate-800 text-base"><i class="fas fa-history text-amber-500 mr-2"></i> মূল্যায়নের ইতিহাস</h2>
                                <span class="bg-amber-100 text-amber-700 text-xs font-black px-3 py-1 rounded-lg border border-amber-200 shadow-sm"><?= count($evaluations) ?> টি</span>
                            </div>

                            <?php if(count($evaluations) > 0): ?>
                            <div class="space-y-4">
                                <?php foreach($evaluations as $ev):
                                    $grade_class = 'bg-rose-50 text-rose-600 border-rose-200';
                                    if($ev['total_score']>=90) $grade_class='bg-purple-50 text-purple-600 border-purple-200';
                                    elseif($ev['total_score']>=75) $grade_class='bg-emerald-50 text-emerald-600 border-emerald-200';
                                    elseif($ev['total_score']>=60) $grade_class='bg-blue-50 text-blue-600 border-blue-200';
                                    elseif($ev['total_score']>=40) $grade_class='bg-amber-50 text-amber-600 border-amber-200';
                                    
                                    $grade_label = $ev['performance_grade'] ?? ($ev['total_score']>=75?'Excellent':($ev['total_score']>=60?'Good':($ev['total_score']>=40?'Average':'Poor')));
                                    $mdata = json_decode($ev['metrics_data'], true) ?: [];
                                    
                                    $rset2 = $role_settings[$ev['role_name']] ?? ['profit_share_pct'=>0];
                                    $max_b = $total_adv_fund * ($rset2['profit_share_pct']/100);
                                    $earned_b = $max_b * ($ev['total_score']/100);
                                    
                                    $rinfo3 = $role_map[$ev['role_name']] ?? ['color'=>'#4F8EF7','icon'=>'fa-user-tie'];
                                    
                                    $score_pct = min(100, max(0, $ev['total_score']));
                                    $circ = 2 * M_PI * 26; 
                                    $off = $circ - ($score_pct / 100 * $circ);
                                    $ring_color = $ev['total_score']>=75 ? '#10b981' : ($ev['total_score']>=60 ? '#3b82f6' : ($ev['total_score']>=40 ? '#f59e0b' : '#f43f5e'));
                                ?>
                                <div class="app-card bg-white border-l-4 hover:shadow-md transition" style="border-left-color: <?= $rinfo3['color'] ?>">
                                    <div class="p-5">
                                        <div class="flex items-start gap-4 mb-4 pb-4 border-b border-slate-100">
                                            
                                            <div class="relative w-16 h-16 shrink-0 flex items-center justify-center">
                                                <svg width="64" height="64" viewBox="0 0 64 64" class="-rotate-90 drop-shadow-sm">
                                                    <circle cx="32" cy="32" r="26" stroke="#f1f5f9" stroke-width="5" fill="none"/>
                                                    <circle cx="32" cy="32" r="26" stroke="<?= $ring_color ?>" stroke-width="5" fill="none" stroke-dasharray="<?= $circ ?>" stroke-dashoffset="<?= $ev['total_score']>0 ? $off : $circ ?>" stroke-linecap="round"/>
                                                </svg>
                                                <div class="absolute inset-0 flex flex-col items-center justify-center">
                                                    <span class="font-black text-sm" style="color: <?= $ring_color ?>"><?= number_format($ev['total_score'],0) ?></span>
                                                </div>
                                            </div>
                                            
                                            <div class="flex-1 min-w-0">
                                                <div class="flex items-center gap-2 mb-1.5 flex-wrap">
                                                    <div class="w-6 h-6 rounded-full overflow-hidden border border-slate-200 shrink-0 bg-slate-100 flex items-center justify-center font-black text-[10px] text-slate-400">
                                                        <?php if(!empty($ev['profile_picture'])): ?>
                                                            <img src="../<?= htmlspecialchars($ev['profile_picture']) ?>" class="w-full h-full object-cover">
                                                        <?php else: ?>
                                                            <?= strtoupper(substr($ev['name'],0,1)) ?>
                                                        <?php endif; ?>
                                                    </div>
                                                    <h4 class="font-black text-sm text-slate-800"><?= htmlspecialchars($ev['name']) ?></h4>
                                                </div>
                                                <div class="flex items-center gap-2 flex-wrap mt-2">
                                                    <span class="text-[9px] font-bold px-2 py-0.5 rounded border uppercase tracking-wider" style="background:<?= $rinfo3['color'] ?>15;color:<?= $rinfo3['color'] ?>;border-color:<?= $rinfo3['color'] ?>30"><i class="fas <?= $rinfo3['icon'] ?> mr-1"></i><?= htmlspecialchars($ev['role_name']) ?></span>
                                                    <span class="text-[9px] font-black px-2 py-0.5 rounded border uppercase tracking-wider <?= $grade_class ?> shadow-sm"><?= $grade_label ?></span>
                                                    <span class="text-[10px] font-bold text-slate-500 bg-slate-100 px-2 py-0.5 rounded border border-slate-200"><i class="far fa-calendar-alt mr-1"></i><?= date('F Y', strtotime($ev['eval_month'].'-01')) ?></span>
                                                </div>
                                            </div>
                                            <div class="flex flex-col sm:flex-row items-end sm:items-center gap-3">
                                                <div class="text-right bg-emerald-50 px-3 py-2 rounded-xl border border-emerald-100 shadow-sm">
                                                    <div class="text-[9px] font-bold text-emerald-600 uppercase tracking-wide mb-0.5">অর্জিত বোনাস</div>
                                                    <div class="font-black text-base text-emerald-700 leading-none">৳<?= number_format($earned_b,0) ?></div>
                                                </div>
                                                <form method="POST" onsubmit="return confirm('মুছবেন?')">
                                                    <input type="hidden" name="action" value="delete_evaluation"><input type="hidden" name="eval_id" value="<?= $ev['id'] ?>">
                                                    <button type="submit" class="w-8 h-8 rounded-lg bg-rose-50 text-rose-500 hover:bg-rose-500 hover:text-white transition flex items-center justify-center border border-rose-100 shadow-sm"><i class="fas fa-trash text-xs"></i></button>
                                                </form>
                                            </div>
                                        </div>

                                        <?php if(is_array($mdata) && count($mdata)): ?>
                                        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3 mb-3">
                                            <?php foreach($mdata as $mid=>$sc):
                                                $mn='Metric'; $mx=100;
                                                foreach($metrics_raw as $raw){if($raw['id']==$mid){$mn=$raw['metric_name'];$mx=$raw['max_score'];break;}}
                                                $pct = ($mx > 0) ? round(($sc/$mx)*100) : 0;
                                            ?>
                                            <div class="bg-slate-50 p-3 rounded-xl border border-slate-100 hover:border-blue-200 hover:bg-white transition shadow-sm">
                                                <div class="text-[9px] font-bold text-slate-500 truncate mb-1.5" title="<?= htmlspecialchars($mn) ?>"><?= htmlspecialchars($mn) ?></div>
                                                <div class="flex items-center gap-2 mb-1.5">
                                                    <div class="font-black text-sm text-slate-800 leading-none"><?= $sc ?></div>
                                                    <div class="text-[8px] font-bold text-slate-400 bg-white px-1 py-0.5 rounded border border-slate-200 shadow-sm">/<?= $mx ?></div>
                                                </div>
                                                <div class="w-full h-1.5 bg-slate-200 rounded-full overflow-hidden shadow-inner flex">
                                                    <div class="h-full bg-blue-500 rounded-full" style="width:<?= $pct ?>%"></div>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php endif; ?>

                                        <?php if(!empty($ev['remarks'])): ?>
                                        <div class="mt-4 px-4 py-3 rounded-xl text-[10px] font-medium bg-amber-50 border border-amber-100 text-amber-800 flex gap-2 items-start leading-relaxed shadow-sm">
                                            <i class="fas fa-quote-left text-amber-400 mt-0.5 text-sm"></i>
                                            <div><?= nl2br(htmlspecialchars($ev['remarks'])) ?></div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <div class="bg-white rounded-xl border border-dashed border-slate-300 p-16 text-center text-slate-400">
                                <i class="fas fa-clipboard-check text-5xl mb-4 opacity-30 block"></i>
                                <p class="font-bold">কোনো মূল্যায়ন সম্পন্ন হয়নি।</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div id="tab-reports" class="tab-pane hidden">
                    <?php if(count($pending_reports)>0): ?>
                    <div class="mb-6">
                        <div class="flex items-center gap-3 mb-4">
                            <h2 class="text-base font-black text-slate-800"><i class="fas fa-clock text-amber-500 mr-1.5"></i> পেন্ডিং রিপোর্ট</h2>
                            <span class="bg-rose-500 text-white px-2 py-0.5 rounded-full text-[10px] font-black shadow-sm"><?= count($pending_reports) ?> Pending</span>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
                            <?php foreach($pending_reports as $pr):
                                $data = json_decode($pr['update_data'], true) ?: [];
                                $rinfo4 = $role_map[$pr['role_name']] ?? ['color'=>'#4F8EF7','icon'=>'fa-user-tie'];
                            ?>
                            <div class="app-card bg-white border-t-4 hover:shadow-md transition" style="border-top-color: var(--amber)">
                                <div class="p-5">
                                    <div class="flex items-center gap-3 mb-4 pb-3 border-b border-slate-100">
                                        <div class="w-12 h-12 rounded-full border-2 border-slate-100 shadow-sm overflow-hidden shrink-0 flex items-center justify-center bg-slate-50 font-black text-slate-400">
                                            <?php if(!empty($pr['profile_picture'])): ?>
                                                <img src="../<?= htmlspecialchars($pr['profile_picture']) ?>" class="w-full h-full object-cover">
                                            <?php else: ?>
                                                <?= strtoupper(substr($pr['name'],0,1)) ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="font-black text-sm text-slate-800 truncate mb-1"><?= htmlspecialchars($pr['name']) ?></div>
                                            <div class="text-[9px] font-bold text-slate-500 truncate bg-slate-100 inline-block px-1.5 py-0.5 rounded border border-slate-200"><?= htmlspecialchars($pr['role_name']) ?></div>
                                        </div>
                                        <div class="text-[10px] font-bold bg-amber-50 text-amber-700 px-2 py-1 rounded-lg shrink-0 border border-amber-100 shadow-sm flex items-center gap-1"><i class="fas fa-calendar-day"></i><?= date('d M', strtotime($pr['report_date'])) ?></div>
                                    </div>
                                    
                                    <div class="space-y-2 mb-4 bg-slate-50 p-4 rounded-xl border border-slate-100 text-[11px] shadow-inner max-h-[150px] overflow-y-auto custom-scrollbar">
                                        <?php foreach($data as $k=>$v): ?>
                                        <div class="flex flex-col sm:flex-row sm:justify-between sm:gap-2 border-b border-slate-200/50 last:border-0 pb-1.5 last:pb-0 mb-1.5 last:mb-0">
                                            <span class="capitalize font-bold text-slate-500 w-full sm:w-1/3 break-words mb-1 sm:mb-0"><?= str_replace(['_','__'],' ',explode('__',$k)[0]) ?>:</span>
                                            <span class="text-left sm:text-right font-semibold text-slate-800 w-full sm:w-2/3 break-words bg-white px-2 py-1 rounded shadow-sm border border-slate-100"><?= htmlspecialchars($v) ?></span>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    
                                    <form method="POST" class="space-y-3 bg-white p-3 rounded-xl border border-slate-200 shadow-sm">
                                        <input type="hidden" name="action" value="verify_daily_report">
                                        <input type="hidden" name="report_id" value="<?= $pr['id'] ?>">
                                        <textarea name="admin_remarks" rows="2" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-xs outline-none focus:bg-white focus:border-amber-400 focus:ring-1 focus:ring-amber-400 transition custom-scrollbar" placeholder="অ্যাডমিনের মন্তব্য (ঐচ্ছিক)..."></textarea>
                                        <div class="flex gap-2">
                                            <button type="submit" name="status" value="verified" class="flex-1 bg-emerald-500 text-white font-bold py-2.5 rounded-lg text-xs transition shadow-md hover:bg-emerald-600 hover:shadow-lg active:scale-95 flex justify-center items-center gap-1"><i class="fas fa-check-circle"></i> Verify</button>
                                            <button type="submit" name="status" value="rejected" class="flex-1 bg-rose-50 text-rose-600 font-bold py-2.5 rounded-lg text-xs transition border border-rose-200 shadow-sm hover:bg-rose-500 hover:text-white active:scale-95 flex justify-center items-center gap-1"><i class="fas fa-times-circle"></i> Reject</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm flex items-center justify-between mb-4">
                        <h2 class="font-black text-slate-800 text-base"><i class="fas fa-list text-blue-500 mr-2"></i> সাম্প্রতিক সব রিপোর্ট</h2>
                        <span class="bg-slate-100 text-slate-600 text-xs font-black px-2.5 py-0.5 rounded-lg border border-slate-200"><?= count($all_reports) ?></span>
                    </div>
                    
                    <div class="app-card bg-white overflow-x-auto pb-4 shadow-sm">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="bg-slate-50 border-b border-slate-200 text-[10px] uppercase tracking-wider text-slate-500 font-black">
                                    <th class="px-5 py-4">অ্যাডভাইজর</th>
                                    <th class="px-5 py-4">পদ</th>
                                    <th class="px-5 py-4">তারিখ</th>
                                    <th class="px-5 py-4 w-1/3">ডেটা</th>
                                    <th class="px-5 py-4 text-center">স্ট্যাটাস</th>
                                    <th class="px-5 py-4">অ্যাডমিন মন্তব্য</th>
                                </tr>
                            </thead>
                            <tbody class="text-xs divide-y divide-slate-100">
                                <?php foreach($all_reports as $rp):
                                    $data2 = json_decode($rp['update_data'], true) ?: [];
                                    $sc = 'px-2 py-1 rounded text-[9px] font-black uppercase tracking-wider border shadow-sm ';
                                    if($rp['status']=='verified') $sc.='bg-emerald-50 text-emerald-600 border-emerald-200';
                                    elseif($rp['status']=='rejected') $sc.='bg-rose-50 text-rose-600 border-rose-200';
                                    else $sc.='bg-amber-50 text-amber-600 border-amber-200';
                                    
                                    $rinfo5 = $role_map[$rp['role_name']] ?? ['color'=>'#4F8EF7','icon'=>'fa-user-tie'];
                                ?>
                                <tr class="hover:bg-slate-50 transition-colors">
                                    <td class="px-5 py-4 align-top">
                                        <div class="flex items-center gap-3">
                                            <div class="w-8 h-8 rounded-full overflow-hidden shrink-0 border border-slate-200 bg-slate-100 flex items-center justify-center font-bold text-xs text-slate-400 shadow-sm">
                                                <?php if(!empty($rp['profile_picture'])): ?>
                                                    <img src="../<?= htmlspecialchars($rp['profile_picture']) ?>" class="w-full h-full object-cover">
                                                <?php else: ?>
                                                    <?= strtoupper(substr($rp['name'],0,1)) ?>
                                                <?php endif; ?>
                                            </div>
                                            <span class="font-bold text-slate-800 text-sm"><?= htmlspecialchars($rp['name']) ?></span>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 align-top"><span class="text-[9px] font-bold px-2 py-1 rounded border shadow-sm" style="background:<?= $rinfo5['color'] ?>15;color:<?= $rinfo5['color'] ?>;border-color:<?= $rinfo5['color'] ?>30"><i class="fas <?= $rinfo5['icon'] ?> mr-1"></i><?= htmlspecialchars($rp['role_name']) ?></span></td>
                                    <td class="px-5 py-4 align-top font-bold text-slate-600 text-[10px]"><span class="bg-white px-2 py-1 rounded border border-slate-200 shadow-sm"><i class="far fa-calendar-alt text-blue-400 mr-1"></i><?= date('d M Y', strtotime($rp['report_date'])) ?></span></td>
                                    <td class="px-5 py-4 align-top">
                                        <div class="text-[10px] space-y-1.5 bg-white p-2 rounded-lg border border-slate-200 shadow-sm">
                                            <?php $count=0; foreach($data2 as $k=>$v): if($count++>=2) break; ?>
                                                <div class="text-slate-600 flex gap-1"><b class="capitalize text-slate-500 shrink-0"><?= str_replace(['_','__'],' ',explode('__',$k)[0]) ?>:</b> <span class="truncate"><?= htmlspecialchars($v) ?></span></div>
                                            <?php endforeach; ?>
                                            <?php if(count($data2)>2): ?><div class="text-blue-500 font-bold bg-blue-50 px-1.5 py-0.5 rounded inline-block mt-1">+<?= count($data2)-2 ?> আরও...</div><?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 align-top text-center"><span class="<?= $sc ?>"><?= $rp['status'] ?></span></td>
                                    <td class="px-5 py-4 align-top text-[10px] font-medium text-slate-600 leading-relaxed">
                                        <?php if(!empty($rp['admin_remarks'])): ?>
                                            <div class="bg-indigo-50 p-2 rounded border border-indigo-100 text-indigo-800 shadow-sm"><i class="fas fa-reply text-indigo-400 mr-1"></i><?= htmlspecialchars($rp['admin_remarks']) ?></div>
                                        <?php else: ?>
                                            <span class="text-slate-300 italic">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($all_reports)): ?>
                                    <tr><td colspan="6" class="p-16 text-center text-slate-400 font-bold"><i class="fas fa-inbox text-4xl mb-3 opacity-30 block"></i> কোনো রিপোর্ট নেই।</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div id="tab-settings" class="tab-pane hidden">
                    <div class="max-w-3xl mx-auto">
                        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm flex items-center mb-6">
                            <div class="w-10 h-10 bg-amber-50 text-amber-500 rounded-xl flex items-center justify-center text-lg mr-3 shadow-sm border border-amber-100"><i class="fas fa-sliders-h"></i></div>
                            <h2 class="font-black text-slate-800 text-lg">KPI ফান্ড ও প্রফিট শেয়ার সেটিংস</h2>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="action" value="save_fund_settings">
                            
                            <div class="app-card bg-white mb-6 overflow-hidden">
                                <div class="bg-slate-50 px-6 py-4 border-b border-slate-100"><h3 class="font-black text-sm text-slate-800 flex items-center gap-2"><i class="fas fa-wallet text-emerald-500"></i> অ্যাডভাইজর ফান্ড সেটিং</h3></div>
                                <div class="p-6">
                                    <div class="bg-emerald-50 border border-emerald-200 rounded-2xl p-5 flex flex-col sm:flex-row items-start sm:items-center justify-between shadow-sm gap-4">
                                        <div>
                                            <div class="font-black text-emerald-900 text-base mb-1">মোট অ্যাডভাইজর ফান্ড পার্সেন্টেজ</div>
                                            <div class="text-[11px] font-bold text-emerald-600 mb-3">কোম্পানির মোট প্রফিটের কত % অ্যাডভাইজরদের জন্য বরাদ্দ থাকবে?</div>
                                            <div class="text-xl font-black text-emerald-700 bg-white inline-block px-4 py-1.5 rounded-xl border border-emerald-200 shadow-sm flex items-center gap-2"><i class="fas fa-coins text-amber-400"></i> ৳ <?= number_format($total_adv_fund, 0) ?> <span class="text-[10px] text-emerald-500 uppercase tracking-widest mt-1">Current Fund</span></div>
                                        </div>
                                        <div class="flex items-center gap-2 bg-white p-2 rounded-xl border border-emerald-200 shadow-sm">
                                            <input type="number" step="0.1" min="0" max="100" name="advisor_fund_pct" value="<?= $adv_fund_pct ?>" class="w-20 bg-emerald-50 border-none rounded-lg text-center text-2xl font-black text-emerald-700 outline-none focus:ring-2 focus:ring-emerald-400 transition py-2" required>
                                            <span class="font-black text-emerald-500 text-xl pr-2">%</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="app-card bg-white mb-6 overflow-hidden">
                                <div class="bg-slate-50 px-6 py-4 border-b border-slate-100 flex justify-between items-center">
                                    <h3 class="font-black text-sm text-slate-800 flex items-center gap-2"><i class="fas fa-sitemap text-indigo-500"></i> পদ অনুযায়ী প্রফিট শেয়ার</h3>
                                    <div id="total_pct_counter" class="text-xs font-black px-3 py-1 bg-slate-200 text-slate-600 rounded-lg shadow-sm transition-colors duration-300">Total: 0%</div>
                                </div>
                                <div class="p-6">
                                    <div class="text-xs font-bold text-slate-500 mb-5 bg-indigo-50 p-3 rounded-lg border border-indigo-100 flex items-start gap-2 shadow-sm text-indigo-800">
                                        <i class="fas fa-info-circle mt-0.5 text-indigo-500"></i> 
                                        <p>অ্যাডভাইজর ফান্ডের ১০০% কে বিভিন্ন পদের মাঝে ভাগ করুন। সব পদের পার্সেন্টেজ যোগ করলে তা ১০০% হওয়া বাঞ্ছনীয়।</p>
                                    </div>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 max-h-[400px] overflow-y-auto custom-scrollbar pr-2">
                                        <?php foreach($all_roles as $r):
                                            $cp = $role_settings[$r['role_name']]['profit_share_pct'] ?? 0;
                                        ?>
                                        <div class="flex items-center justify-between p-3.5 rounded-xl bg-white border border-slate-200 hover:border-indigo-300 hover:shadow-md transition group">
                                            <div class="flex items-center gap-3 min-w-0">
                                                <div class="w-10 h-10 rounded-lg flex items-center justify-center text-lg shrink-0 transition-colors shadow-sm border border-slate-100" style="background:<?= $r['color'] ?>15;color:<?= $r['color'] ?>"><i class="fas <?= $r['icon'] ?>"></i></div>
                                                <span class="font-black text-sm text-slate-700 truncate group-hover:text-indigo-700 transition-colors"><?= htmlspecialchars($r['role_name']) ?></span>
                                            </div>
                                            <div class="flex items-center gap-1.5 bg-slate-50 p-1.5 rounded-lg border border-slate-200 shrink-0 group-hover:border-indigo-200 transition-colors">
                                                <input type="number" step="0.1" min="0" max="100" name="profit_pct[<?= htmlspecialchars($r['role_name']) ?>]" value="<?= $cp ?>" class="role-pct-input w-16 bg-white border border-slate-200 rounded text-center font-black text-indigo-600 outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 text-sm transition py-1.5" required oninput="calcTotalPct()">
                                                <span class="text-xs font-bold text-slate-400 pr-1">%</span>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="text-right">
                                <button type="submit" class="w-full sm:w-auto bg-blue-600 text-white font-bold px-10 py-3.5 rounded-xl shadow-lg hover:bg-blue-700 hover:shadow-xl transition-all transform active:scale-95 text-sm flex justify-center items-center gap-2 mx-auto sm:mr-0"><i class="fas fa-save"></i> সব সেটিংস সংরক্ষণ করুন</button>
                            </div>
                        </form>
                    </div>
                </div>

            </div>
        </div>
    </main>
</div>

<nav class="bottom-nav md:hidden shadow-[0_-5px_15px_rgba(0,0,0,0.05)] z-50">
    <a href="index.php" class="nav-item"><i class="fas fa-home"></i> Home</a>
    <a href="manage_kpi.php" class="nav-item active"><i class="fas fa-bullseye"></i> KPI</a>
    <a href="add_entry.php" class="nav-item"><i class="fas fa-plus-circle"></i> Entry</a>
</nav>

<div id="roleModal" class="hidden fixed inset-0 bg-slate-900/60 flex items-center justify-center z-50 p-4 backdrop-blur-sm transition-opacity">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg border border-slate-100 overflow-hidden transform scale-100 flex flex-col">
        <div class="px-6 py-5 bg-slate-800 flex items-center justify-between shrink-0">
            <h3 class="font-black text-base text-white flex items-center gap-2" id="roleModalTitle"><i class="fas fa-user-tag text-blue-400"></i> নতুন পদ তৈরি করুন</h3>
            <button onclick="closeRoleModal()" class="w-8 h-8 rounded-lg flex items-center justify-center text-slate-400 hover:bg-rose-500 hover:text-white transition"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" class="p-6 space-y-5">
            <input type="hidden" name="action" value="save_role">
            <input type="hidden" name="role_id" id="roleId" value="0">
            
            <div class="grid grid-cols-2 gap-5">
                <div class="col-span-2">
                    <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 tracking-wide">পদের নাম <span class="text-rose-500">*</span></label>
                    <input type="text" name="role_name" id="roleNameInp" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold outline-none focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-100 transition shadow-sm" placeholder="Ex: Marketing Advisor" required>
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 tracking-wide">বিভাগ</label>
                    <input type="text" name="department" id="roleDeptInp" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm outline-none focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-100 transition shadow-sm" placeholder="Marketing, Finance...">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 tracking-wide">রঙ (Color)</label>
                    <input type="color" name="color" id="roleColorInp" class="w-full h-[46px] p-1 bg-white border border-slate-200 rounded-xl cursor-pointer outline-none shadow-sm" value="#4F8EF7">
                </div>
            </div>
            <div>
                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 tracking-wide">আইকন (Font Awesome class)</label>
                <input type="text" name="icon" id="roleIconInp" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm font-mono outline-none focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-100 transition shadow-sm" placeholder="fa-user-tie" value="fa-user-tie">
                <p class="text-[9px] mt-2 font-bold text-slate-500 bg-blue-50 p-2 rounded-lg border border-blue-100"><i class="fas fa-info-circle text-blue-500 mr-1"></i> fontawesome.com থেকে আইকন নাম নিন। যেমন: fa-bullhorn</p>
            </div>
            <div>
                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 tracking-wide">বিবরণ</label>
                <textarea name="role_description" id="roleDescInp" rows="2" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm outline-none focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-100 custom-scrollbar transition shadow-sm" placeholder="এই পদের দায়িত্ব সম্পর্কে লিখুন..."></textarea>
            </div>
            <div class="flex gap-3 pt-4 border-t border-slate-100">
                <button type="button" onclick="closeRoleModal()" class="flex-1 py-3.5 bg-slate-100 text-slate-600 rounded-xl font-bold hover:bg-slate-200 transition text-sm">বাতিল</button>
                <button type="submit" class="flex-1 py-3.5 bg-blue-600 text-white font-bold rounded-xl shadow-md hover:bg-blue-700 transition text-sm flex justify-center items-center gap-2"><i class="fas fa-save"></i> সংরক্ষণ করুন</button>
            </div>
        </form>
    </div>
</div>

<script>
const kpiData = <?= json_encode($metrics_by_role) ?>;

function switchTab(name) {
    // Hide all panes
    document.querySelectorAll('.tab-pane').forEach(p => {
        p.classList.add('hidden');
        p.classList.remove('block');
    });

    // Reset all buttons
    document.querySelectorAll('.tab-btn').forEach(b => {
        b.classList.remove('bg-indigo-600', 'text-white', 'shadow-md');
        b.classList.add('bg-white', 'text-slate-600');
        b.style.backgroundColor = '';
        b.style.color = '';
        b.style.boxShadow = '';
    });
    
    // Show active pane
    const targetPane = document.getElementById('tab-' + name);
    if(targetPane) {
        targetPane.classList.remove('hidden');
        targetPane.classList.add('block');
    }
    
    // Highlight active button
    const activeBtn = document.getElementById('btn-' + name) || document.querySelector(`.tab-btn[onclick="switchTab('${name}')"]`);
    if(activeBtn) {
        activeBtn.classList.remove('bg-white', 'text-slate-600');
        activeBtn.style.backgroundColor = '<?= $role_color ?? '#4F8EF7' ?>';
        activeBtn.style.color = 'white';
        activeBtn.style.boxShadow = '0 4px 10px <?= $role_color ?? '#4F8EF7' ?>40';
    }
    history.replaceState(null, null, '#' + name);
    if(name === 'settings') calcTotalPct();
}

window.addEventListener('DOMContentLoaded', () => {
    let hash = window.location.hash.replace('#', '');
    if(hash && ['roles','metrics','advisors','evaluations','reports','settings'].includes(hash)) {
        switchTab(hash);
    } else {
        switchTab('roles'); // Default tab
    }
});

function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('-translate-x-full');
    document.getElementById('sidebar-overlay').classList.toggle('hidden');
}

function openRoleModal(roleData = null) {
    document.getElementById('roleModalTitle').innerHTML = roleData ? '<i class="fas fa-edit text-amber-400"></i> পদ সম্পাদনা' : '<i class="fas fa-user-tag text-blue-400"></i> নতুন পদ তৈরি করুন';
    document.getElementById('roleId').value = roleData ? roleData.id : 0;
    document.getElementById('roleNameInp').value = roleData ? roleData.role_name : '';
    document.getElementById('roleDeptInp').value = roleData ? roleData.department : '';
    document.getElementById('roleColorInp').value = roleData ? roleData.color : '#4F8EF7';
    document.getElementById('roleIconInp').value = roleData ? roleData.icon : 'fa-user-tie';
    document.getElementById('roleDescInp').value = roleData ? (roleData.role_description || '') : '';
    document.getElementById('roleModal').classList.remove('hidden');
}
function closeRoleModal() { document.getElementById('roleModal').classList.add('hidden'); }
document.getElementById('roleModal').addEventListener('click', function(e){ if(e.target===this) closeRoleModal(); });

function addMetricSubField() {
    const container = document.getElementById('metric_subfields_container');
    const div = document.createElement('div');
    div.className = 'flex gap-2 mt-2 animate-fade-in';
    div.innerHTML = `<input type="text" name="sub_fields[]" class="flex-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs outline-none focus:border-blue-500 transition shadow-sm" placeholder="নতুন ফিল্ড..." required><button type="button" onclick="this.closest('.flex').remove()" class="w-8 h-8 bg-rose-50 text-rose-500 rounded-lg hover:bg-rose-500 hover:text-white flex items-center justify-center shrink-0 transition border border-rose-100"><i class="fas fa-times text-[10px]"></i></button>`;
    container.appendChild(div);
}

function filterMetrics() {
    const val = document.getElementById('metricRoleFilter').value;
    document.querySelectorAll('.metric-role-group').forEach(g => {
        g.style.display = (val === 'all' || g.dataset.role === val) ? 'block' : 'none';
    });
}

function loadTargetFields() {
    const role = document.getElementById('assignRoleSelect').value;
    const container = document.getElementById('targetFieldsContainer');
    const inputsDiv = document.getElementById('targetInputs');
    const btn = document.getElementById('assignBtn');
    inputsDiv.innerHTML = '';

    if(role && kpiData[role]) {
        container.classList.remove('hidden');
        btn.disabled = false;
        kpiData[role].forEach(metric => {
            const div = document.createElement('div');
            div.className = 'bg-white p-4 rounded-xl border border-blue-100 shadow-sm mb-3 hover:border-blue-300 transition';
            let subFields = [];
            try { subFields = JSON.parse(metric.sub_fields); } catch(e) {}
            
            let inner = `<div class="text-xs font-black text-indigo-800 mb-3 border-b border-slate-100 pb-2"><i class="fas fa-check-circle mr-1.5 text-indigo-400"></i> ${metric.metric_name}</div>`;
            
            if(subFields && subFields.length > 0) {
                inner += `<div class="grid grid-cols-1 sm:grid-cols-2 gap-3">`;
                subFields.forEach(field => {
                    inner += `<div><label class="block text-[10px] font-bold text-slate-500 uppercase mb-1.5 tracking-wide">${field}</label><input type="text" name="targets[${metric.id}][${field}]" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2.5 text-xs outline-none focus:border-indigo-500 focus:bg-white focus:ring-1 focus:ring-indigo-500 transition shadow-sm" placeholder="লিখুন..." required></div>`;
                });
                inner += `</div>`;
            } else {
                inner += `<textarea name="targets[${metric.id}]" rows="2" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2.5 text-xs outline-none focus:border-indigo-500 focus:bg-white focus:ring-1 focus:ring-indigo-500 transition shadow-sm custom-scrollbar" placeholder="টার্গেট বিস্তারিত লিখুন..." required></textarea>`;
            }
            div.innerHTML = inner;
            inputsDiv.appendChild(div);
        });
    } else {
        container.classList.add('hidden');
        btn.disabled = true;
    }
}

function loadMetricsForEval() {
    const sel = document.getElementById('evalUserSelect');
    const role = sel.options[sel.selectedIndex]?.getAttribute('data-role');
    const container = document.getElementById('metricsContainer');
    const inputsDiv = document.getElementById('metricsInputs');
    const btn = document.getElementById('evalSubmitBtn');
    inputsDiv.innerHTML = '';
    document.getElementById('liveTotal').textContent = '0';

    if(role && kpiData[role]) {
        container.classList.remove('hidden');
        btn.disabled = false;
        kpiData[role].forEach(metric => {
            const div = document.createElement('div');
            div.className = 'flex items-center justify-between gap-3 p-3.5 rounded-xl bg-white border border-emerald-100 shadow-sm mb-2 hover:border-emerald-300 transition';
            div.innerHTML = `
                <div class="flex-1 min-w-0">
                    <div class="text-xs font-bold text-slate-800 truncate">${metric.metric_name}</div>
                    <div class="text-[10px] font-bold text-slate-400 mt-1 uppercase tracking-widest">Max Score: ${metric.max_score}</div>
                </div>
                <div class="flex items-center gap-1.5 bg-slate-50 border border-slate-200 rounded-lg p-1.5 shrink-0 transition-colors focus-within:border-emerald-400 focus-within:ring-1 focus-within:ring-emerald-400">
                    <input type="number" name="scores[${metric.id}]" min="0" max="${metric.max_score}" step="0.5" class="kpi-eval-input w-16 text-center font-black text-sm bg-white border border-slate-200 rounded px-1 py-1.5 outline-none text-emerald-600 transition" required oninput="calculateTotal()" placeholder="0">
                    <span class="text-[10px] font-bold text-slate-400 px-1">/ ${metric.max_score}</span>
                </div>`;
            inputsDiv.appendChild(div);
        });
    } else {
        container.classList.add('hidden');
        btn.disabled = true;
    }
}

function calculateTotal() {
    let total = 0;
    document.querySelectorAll('.kpi-eval-input').forEach(inp => {
        const v = parseFloat(inp.value); if(!isNaN(v)) total += v;
    });
    document.getElementById('liveTotal').textContent = total.toFixed(1);
    const pct = Math.min(100, total);
    document.getElementById('progressBarFill').style.width = pct + '%';
    const fill = document.getElementById('progressBarFill');
    if(pct >= 75) fill.className = 'h-full rounded-full transition-all duration-500 bg-emerald-500';
    else if(pct >= 60) fill.className = 'h-full rounded-full transition-all duration-500 bg-blue-500';
    else if(pct >= 40) fill.className = 'h-full rounded-full transition-all duration-500 bg-amber-500';
    else fill.className = 'h-full rounded-full transition-all duration-500 bg-rose-500';
}

function calcTotalPct() {
    let total = 0;
    document.querySelectorAll('.role-pct-input').forEach(inp => { total += parseFloat(inp.value) || 0; });
    const counter = document.getElementById('total_pct_counter');
    counter.innerText = 'Total: ' + total.toFixed(1) + '%';
    if (total === 100) { counter.className = 'text-xs font-black px-3 py-1 bg-emerald-100 text-emerald-700 rounded-lg border border-emerald-200 shadow-sm'; } 
    else if (total > 100) { counter.className = 'text-xs font-black px-3 py-1 bg-rose-100 text-rose-700 rounded-lg border border-rose-200 shadow-sm'; } 
    else { counter.className = 'text-xs font-black px-3 py-1 bg-amber-100 text-amber-700 rounded-lg border border-amber-200 shadow-sm'; }
}
</script>
</body>
</html>