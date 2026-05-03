<?php
ini_set('session.cookie_lifetime', 86400);
ini_set('session.gc_maxlifetime', 86400);
session_set_cookie_params(86400);
session_start();

if (!isset($_SESSION['admin_logged_in'])) { header("Location: login.php"); exit; }
if (isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'staff') {
    $perms = $_SESSION['staff_permissions'] ?? [];
    if (!in_array('manage_kpi', $perms)) {
        die("<div style='text-align:center;padding:50px;font-family:sans-serif'><h2 style='color:red'>Access Denied!</h2><p>আপনার এই পেজে প্রবেশের অনুমতি নেই।</p></div>");
    }
}

require_once __DIR__ . '/db.php';

// ── Schema ──────────────────────────────────────────────
try {
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
} catch (PDOException $e) {}

// ── Session messages ────────────────────────────────────
$msg = $_SESSION['msg_success'] ?? '';
$err = $_SESSION['msg_error']   ?? '';
unset($_SESSION['msg_success'], $_SESSION['msg_error']);

// ── POST actions ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'save_position') {
        $id      = (int)($_POST['id'] ?? 0);
        $name    = trim($_POST['position_name']);
        $name_bn = trim($_POST['position_name_bn'] ?? '');
        $dept    = trim($_POST['department'] ?? '');
        $tier    = (int)($_POST['tier_level'] ?? 1);
        $desc    = trim($_POST['description'] ?? '');

        if (empty($name)) {
            $_SESSION['msg_error'] = "পদবীর নাম দেওয়া আবশ্যক।";
        } else {
            try {
                if ($id > 0) {
                    $pdo->prepare("UPDATE positions SET position_name=?, position_name_bn=?, department=?, tier_level=?, description=? WHERE id=?")
                        ->execute([$name, $name_bn, $dept, $tier, $desc, $id]);
                    $_SESSION['msg_success'] = "পদবী আপডেট হয়েছে!";
                } else {
                    $pdo->prepare("INSERT INTO positions (position_name, position_name_bn, department, tier_level, description) VALUES (?,?,?,?,?)")
                        ->execute([$name, $name_bn, $dept, $tier, $desc]);
                    $_SESSION['msg_success'] = "নতুন পদবী যোগ হয়েছে!";
                }
            } catch (PDOException $e) {
                $_SESSION['msg_error'] = "এই নামের পদবী ইতিমধ্যে আছে।";
            }
        }
        header("Location: manage_positions.php"); exit;
    }

    if ($action === 'toggle_status') {
        $id  = (int)$_POST['id'];
        $val = (int)$_POST['current_status'] === 1 ? 0 : 1;
        $pdo->prepare("UPDATE positions SET is_active=? WHERE id=?")->execute([$val, $id]);
        $_SESSION['msg_success'] = $val ? "পদবী সক্রিয় করা হয়েছে।" : "পদবী নিষ্ক্রিয় করা হয়েছে।";
        header("Location: manage_positions.php"); exit;
    }

    if ($action === 'delete_position') {
        $id = (int)$_POST['id'];
        // Check if any employee uses this position
        try {
            $in_use = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE position_id=?");
            $in_use->execute([$id]);
            if ($in_use->fetchColumn() > 0) {
                $_SESSION['msg_error'] = "এই পদবীতে কর্মী আছে। আগে কর্মীদের পদবী পরিবর্তন করুন।";
                header("Location: manage_positions.php"); exit;
            }
        } catch (PDOException $e) {}
        $pdo->prepare("DELETE FROM positions WHERE id=?")->execute([$id]);
        $_SESSION['msg_success'] = "পদবী মুছে ফেলা হয়েছে।";
        header("Location: manage_positions.php"); exit;
    }
}

// ── Data fetch ──────────────────────────────────────────
$filter_dept = $_GET['dept'] ?? '';
$filter_tier = $_GET['tier'] ?? '';
$search      = trim($_GET['q'] ?? '');

$where = "WHERE 1=1";
$params = [];
if ($filter_dept) { $where .= " AND department=?";  $params[] = $filter_dept; }
if ($filter_tier) { $where .= " AND tier_level=?";  $params[] = (int)$filter_tier; }
if ($search)      { $where .= " AND (position_name LIKE ? OR position_name_bn LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }

$stmt = $pdo->prepare("SELECT * FROM positions $where ORDER BY tier_level, department, position_name");
$stmt->execute($params);
$positions = $stmt->fetchAll();

$total      = count($positions);
$active_cnt = count(array_filter($positions, fn($p) => $p['is_active']));

$depts_raw = $pdo->query("SELECT DISTINCT department FROM positions WHERE department != '' ORDER BY department")->fetchAll(PDO::FETCH_COLUMN);

$dept_stats = [];
foreach ($positions as $p) {
    $d = $p['department'] ?: 'অন্যান্য';
    $dept_stats[$d] = ($dept_stats[$d] ?? 0) + 1;
}

$tier_labels = [1 => 'Tier ১ — নেতৃত্ব', 2 => 'Tier ২ — ম্যানেজমেন্ট', 3 => 'Tier ৩ — ফিল্ড'];
$tier_colors = [1 => '#DC2626', 2 => '#F97316', 3 => '#10B981'];
$dept_icons  = [
    'Leadership'       => 'fa-crown',
    'Operations'       => 'fa-cogs',
    'Field'            => 'fa-motorcycle',
    'Technology'       => 'fa-laptop-code',
    'Marketing'        => 'fa-bullhorn',
    'Finance'          => 'fa-chart-line',
    'Customer Service' => 'fa-headset',
    'HR'               => 'fa-users',
    'Design'           => 'fa-paint-brush',
];
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>পদবী ব্যবস্থাপনা — Sodai Lagbe ERP</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = {
    theme: {
        extend: {
            fontFamily: { bn: ['Hind Siliguri', 'sans-serif'], en: ['Inter', 'sans-serif'] },
            colors: { primary: '#DC2626', secondary: '#F97316' }
        }
    }
}
</script>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Hind Siliguri', 'Inter', sans-serif; background: #f1f5f9; min-height: 100vh; -webkit-tap-highlight-color: transparent; }
::-webkit-scrollbar { width: 4px; height: 4px; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 99px; }

/* Glass card */
.glass { background: rgba(255,255,255,0.85); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border: 1px solid rgba(255,255,255,0.6); }

/* Gradient button */
.btn-primary { background: linear-gradient(135deg, #DC2626, #F97316); color: white; transition: all .2s; box-shadow: 0 4px 14px rgba(220,38,38,0.3); }
.btn-primary:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(220,38,38,0.4); }
.btn-primary:active { transform: translateY(0); }

/* Tier badge */
.tier-1 { background: linear-gradient(135deg, #fee2e2, #fecaca); color: #991b1b; border: 1px solid #fca5a5; }
.tier-2 { background: linear-gradient(135deg, #ffedd5, #fed7aa); color: #9a3412; border: 1px solid #fdba74; }
.tier-3 { background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #065f46; border: 1px solid #6ee7b7; }

/* Status badge */
.badge-active   { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
.badge-inactive { background: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0; }

/* Position card */
.pos-card { transition: all .2s; }
.pos-card:hover { transform: translateY(-2px); box-shadow: 0 12px 32px rgba(0,0,0,0.08); }

/* Toggle switch */
.toggle { position: relative; display: inline-block; width: 40px; height: 22px; }
.toggle input { opacity: 0; width: 0; height: 0; }
.slider { position: absolute; cursor: pointer; inset: 0; background: #cbd5e1; border-radius: 99px; transition: .3s; }
.slider::before { content:''; position: absolute; width: 16px; height: 16px; left: 3px; bottom: 3px; background: white; border-radius: 50%; transition: .3s; box-shadow: 0 1px 3px rgba(0,0,0,0.2); }
input:checked + .slider { background: linear-gradient(135deg, #DC2626, #F97316); }
input:checked + .slider::before { transform: translateX(18px); }

/* Modal */
.modal-bg { position: fixed; inset: 0; background: rgba(15,23,42,0.5); backdrop-filter: blur(4px); z-index: 50; display: none; align-items: center; justify-content: center; padding: 16px; }
.modal-bg.open { display: flex; }
.modal-box { background: white; border-radius: 24px; width: 100%; max-width: 520px; max-height: 90vh; overflow-y: auto; box-shadow: 0 24px 64px rgba(0,0,0,0.15); animation: slideUp .25s ease; }
@keyframes slideUp { from { opacity:0; transform: translateY(20px); } to { opacity:1; transform: translateY(0); } }

/* Input */
.form-input { width: 100%; padding: 12px 16px; background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 12px; font-size: 14px; font-family: inherit; color: #1e293b; outline: none; transition: all .2s; }
.form-input:focus { background: white; border-color: #DC2626; box-shadow: 0 0 0 3px rgba(220,38,38,0.1); }
.form-label { display: block; font-size: 12px; font-weight: 700; color: #64748b; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px; }

/* Toast */
.toast { position: fixed; bottom: 80px; left: 50%; transform: translateX(-50%); z-index: 100; padding: 12px 20px; border-radius: 14px; font-size: 14px; font-weight: 600; white-space: nowrap; animation: toastIn .3s ease; box-shadow: 0 8px 24px rgba(0,0,0,0.15); }
@keyframes toastIn { from { opacity:0; transform: translateX(-50%) translateY(10px); } to { opacity:1; transform: translateX(-50%) translateY(0); } }

/* Sidebar */
@media (min-width: 1024px) { body { padding-bottom: 0 !important; } }
</style>
</head>
<body class="pb-20 lg:pb-0">

<!-- ── TOP NAV ──────────────────────────────────────────── -->
<nav class="glass sticky top-0 z-40 shadow-sm">
    <div class="max-w-7xl mx-auto px-4 h-14 flex items-center gap-3">
        <a href="index.php" class="w-8 h-8 flex items-center justify-center rounded-xl bg-slate-100 text-slate-600 hover:bg-red-50 hover:text-red-600 transition text-sm">
            <i class="fas fa-arrow-left"></i>
        </a>
        <div class="flex items-center gap-2 flex-1 min-w-0">
            <div class="w-8 h-8 rounded-xl flex items-center justify-center text-white text-sm" style="background:linear-gradient(135deg,#DC2626,#F97316)">
                <i class="fas fa-briefcase"></i>
            </div>
            <div class="min-w-0">
                <h1 class="text-[15px] font-bold text-slate-800 leading-none">পদবী ব্যবস্থাপনা</h1>
                <p class="text-[11px] text-slate-400 mt-0.5">Position Management</p>
            </div>
        </div>
        <button onclick="openModal()" class="btn-primary flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-bold">
            <i class="fas fa-plus text-xs"></i>
            <span class="hidden sm:inline">নতুন পদবী</span>
        </button>
    </div>
</nav>

<!-- ── TOAST ──────────────────────────────────────────────── -->
<?php if ($msg): ?>
<div class="toast" style="background:#dcfce7;color:#166534;border:1px solid #bbf7d0" id="toast">
    <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($msg) ?>
</div>
<script>setTimeout(()=>{const t=document.getElementById('toast');if(t){t.style.opacity='0';t.style.transition='opacity .4s';setTimeout(()=>t.remove(),400);}},3000);</script>
<?php endif; ?>
<?php if ($err): ?>
<div class="toast" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5" id="toast">
    <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($err) ?>
</div>
<script>setTimeout(()=>{const t=document.getElementById('toast');if(t){t.style.opacity='0';t.style.transition='opacity .4s';setTimeout(()=>t.remove(),400);}},4000);</script>
<?php endif; ?>

<div class="max-w-7xl mx-auto px-4 py-5 space-y-5">

    <!-- ── STATS ROW ──────────────────────────────────────── -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <?php
        $stats = [
            ['মোট পদবী',    $total,      'fa-briefcase',  '#DC2626', '#fee2e2'],
            ['সক্রিয়',      $active_cnt, 'fa-check-circle','#10B981','#d1fae5'],
            ['বিভাগ',       count($dept_stats), 'fa-sitemap', '#3B82F6','#dbeafe'],
            ['নিষ্ক্রিয়', $total - $active_cnt, 'fa-ban', '#6B7280','#f1f5f9'],
        ];
        foreach ($stats as [$label, $value, $icon, $color, $bg]):
        ?>
        <div class="glass rounded-2xl p-4 flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl flex items-center justify-center text-base flex-shrink-0" style="background:<?= $bg ?>;color:<?= $color ?>">
                <i class="fas <?= $icon ?>"></i>
            </div>
            <div>
                <div class="text-xl font-black text-slate-800"><?= $value ?></div>
                <div class="text-xs text-slate-500 font-medium"><?= $label ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ── DEPARTMENT CHIPS ───────────────────────────────── -->
    <?php if (!empty($dept_stats)): ?>
    <div class="glass rounded-2xl p-4">
        <p class="text-xs font-bold text-slate-400 uppercase tracking-wide mb-3">বিভাগ অনুযায়ী</p>
        <div class="flex flex-wrap gap-2">
            <a href="manage_positions.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold border transition
                <?= !$filter_dept ? 'text-white border-transparent' : 'bg-white text-slate-600 border-slate-200 hover:border-red-300' ?>"
                style="<?= !$filter_dept ? 'background:linear-gradient(135deg,#DC2626,#F97316)' : '' ?>">
                <i class="fas fa-th-large text-[10px]"></i> সব
            </a>
            <?php foreach ($dept_stats as $dept => $cnt): ?>
            <a href="?dept=<?= urlencode($dept) ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold border transition
                <?= $filter_dept === $dept ? 'text-white border-transparent' : 'bg-white text-slate-600 border-slate-200 hover:border-red-300' ?>"
                style="<?= $filter_dept === $dept ? 'background:linear-gradient(135deg,#DC2626,#F97316)' : '' ?>">
                <i class="fas <?= $dept_icons[$dept] ?? 'fa-building' ?> text-[10px]"></i>
                <?= htmlspecialchars($dept) ?> <span class="opacity-70">(<?= $cnt ?>)</span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── SEARCH & TIER FILTER ───────────────────────────── -->
    <div class="flex flex-col sm:flex-row gap-3">
        <form method="GET" class="flex-1">
            <?php if ($filter_dept): ?><input type="hidden" name="dept" value="<?= htmlspecialchars($filter_dept) ?>"><?php endif; ?>
            <div class="relative">
                <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="পদবী খুঁজুন..."
                    class="form-input pl-10 pr-4">
            </div>
        </form>
        <div class="flex gap-2">
            <?php foreach ([0=>'সব Tier', 1=>'Tier ১', 2=>'Tier ২', 3=>'Tier ৩'] as $t => $label): ?>
            <a href="?<?= http_build_query(array_filter(['dept'=>$filter_dept,'q'=>$search,'tier'=>$t?:null])) ?>"
               class="px-3 py-2.5 rounded-xl text-xs font-bold border transition
               <?= (string)$filter_tier === (string)$t ? 'text-white border-transparent' : 'bg-white text-slate-600 border-slate-200' ?>"
               style="<?= (string)$filter_tier === (string)$t ? 'background:linear-gradient(135deg,#DC2626,#F97316)' : '' ?>">
                <?= $label ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ── POSITION LIST ──────────────────────────────────── -->
    <?php if (empty($positions)): ?>
    <div class="glass rounded-2xl p-12 text-center">
        <div class="w-16 h-16 rounded-2xl mx-auto mb-4 flex items-center justify-center text-2xl" style="background:#fee2e2;color:#DC2626">
            <i class="fas fa-briefcase"></i>
        </div>
        <h3 class="text-base font-bold text-slate-700 mb-1">কোনো পদবী পাওয়া যায়নি</h3>
        <p class="text-sm text-slate-400 mb-4">নতুন পদবী যোগ করুন অথবা ফিল্টার পরিবর্তন করুন।</p>
        <button onclick="openModal()" class="btn-primary px-5 py-2.5 rounded-xl text-sm font-bold inline-flex items-center gap-2">
            <i class="fas fa-plus"></i> নতুন পদবী যোগ করুন
        </button>
    </div>
    <?php else: ?>

    <?php
    // Group by tier
    $grouped = [];
    foreach ($positions as $p) { $grouped[$p['tier_level']][] = $p; }
    ksort($grouped);
    foreach ($grouped as $tier => $items):
        $tc = $tier_colors[$tier] ?? '#64748b';
    ?>
    <div>
        <!-- Tier header -->
        <div class="flex items-center gap-2 mb-3">
            <div class="w-6 h-6 rounded-lg flex items-center justify-center text-white text-xs font-black flex-shrink-0"
                 style="background:<?= $tc ?>">
                <?= $tier ?>
            </div>
            <h2 class="text-sm font-black text-slate-600 uppercase tracking-wide">
                <?= $tier_labels[$tier] ?? "Tier $tier" ?>
            </h2>
            <div class="flex-1 h-px bg-slate-200"></div>
            <span class="text-xs text-slate-400 font-bold"><?= count($items) ?>টি</span>
        </div>

        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3 mb-5">
        <?php foreach ($items as $p):
            $dicon = $dept_icons[$p['department']] ?? 'fa-building';
        ?>
        <div class="glass rounded-2xl p-4 pos-card <?= !$p['is_active'] ? 'opacity-60' : '' ?>">
            <div class="flex items-start gap-3">
                <!-- Icon -->
                <div class="w-11 h-11 rounded-xl flex items-center justify-center text-white text-base flex-shrink-0"
                     style="background:linear-gradient(135deg,<?= $tc ?>,<?= $tc ?>bb)">
                    <i class="fas <?= $dicon ?>"></i>
                </div>
                <!-- Info -->
                <div class="flex-1 min-w-0">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <h3 class="text-sm font-bold text-slate-800 leading-tight truncate">
                                <?= htmlspecialchars($p['position_name']) ?>
                            </h3>
                            <?php if ($p['position_name_bn']): ?>
                            <p class="text-xs text-slate-500 font-medium mt-0.5">
                                <?= htmlspecialchars($p['position_name_bn']) ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        <span class="badge-<?= $p['is_active'] ? 'active' : 'inactive' ?> text-[10px] font-bold px-2 py-0.5 rounded-full flex-shrink-0">
                            <?= $p['is_active'] ? 'সক্রিয়' : 'বন্ধ' ?>
                        </span>
                    </div>

                    <div class="flex items-center gap-2 mt-2 flex-wrap">
                        <?php if ($p['department']): ?>
                        <span class="text-[11px] font-semibold text-slate-500 bg-slate-100 px-2 py-0.5 rounded-lg">
                            <i class="fas <?= $dicon ?> mr-1 text-[9px]"></i><?= htmlspecialchars($p['department']) ?>
                        </span>
                        <?php endif; ?>
                        <span class="tier-<?= $p['tier_level'] ?> text-[10px] font-bold px-2 py-0.5 rounded-full">
                            T<?= $p['tier_level'] ?>
                        </span>
                    </div>

                    <?php if ($p['description']): ?>
                    <p class="text-[11px] text-slate-400 mt-2 line-clamp-2 leading-relaxed">
                        <?= htmlspecialchars($p['description']) ?>
                    </p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex items-center gap-2 mt-3 pt-3 border-t border-slate-100">
                <!-- Toggle status -->
                <form method="POST" class="flex items-center gap-1.5">
                    <input type="hidden" name="action" value="toggle_status">
                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                    <input type="hidden" name="current_status" value="<?= $p['is_active'] ?>">
                    <label class="toggle"><input type="checkbox" onchange="this.form.submit()" <?= $p['is_active'] ? 'checked' : '' ?>>
                    <span class="slider"></span></label>
                </form>
                <div class="flex-1"></div>
                <!-- Edit -->
                <button onclick="editPosition(<?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>)"
                    class="w-8 h-8 rounded-xl bg-blue-50 text-blue-600 hover:bg-blue-100 transition text-xs flex items-center justify-center">
                    <i class="fas fa-pencil-alt"></i>
                </button>
                <!-- Delete -->
                <button onclick="confirmDelete(<?= $p['id'] ?>, '<?= htmlspecialchars($p['position_name'], ENT_QUOTES) ?>')"
                    class="w-8 h-8 rounded-xl bg-red-50 text-red-500 hover:bg-red-100 transition text-xs flex items-center justify-center">
                    <i class="fas fa-trash-alt"></i>
                </button>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

</div><!-- /max-w-7xl -->

<!-- ── ADD / EDIT MODAL ───────────────────────────────────── -->
<div class="modal-bg" id="posModal">
    <div class="modal-box">
        <div class="p-5 border-b border-slate-100 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl text-white flex items-center justify-center text-sm" style="background:linear-gradient(135deg,#DC2626,#F97316)">
                    <i class="fas fa-briefcase"></i>
                </div>
                <div>
                    <h2 class="text-base font-black text-slate-800" id="modalTitle">নতুন পদবী যোগ করুন</h2>
                    <p class="text-xs text-slate-400">Position details</p>
                </div>
            </div>
            <button onclick="closeModal()" class="w-8 h-8 rounded-xl bg-slate-100 text-slate-500 hover:bg-slate-200 transition text-sm flex items-center justify-center">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <form method="POST" id="posForm" class="p-5 space-y-4">
            <input type="hidden" name="action" value="save_position">
            <input type="hidden" name="id" id="f_id" value="0">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="form-label">পদবীর নাম (English) <span class="text-red-500">*</span></label>
                    <input type="text" name="position_name" id="f_name" class="form-input" placeholder="e.g. Delivery Rider" required>
                </div>
                <div>
                    <label class="form-label">পদবীর নাম (বাংলা)</label>
                    <input type="text" name="position_name_bn" id="f_name_bn" class="form-input" placeholder="যেমন: ডেলিভারি ম্যান">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="form-label">বিভাগ (Department)</label>
                    <select name="department" id="f_dept" class="form-input">
                        <option value="">-- বিভাগ নির্বাচন করুন --</option>
                        <?php
                        $all_depts = ['Leadership','Operations','Field','Technology','Marketing','Finance','Customer Service','HR','Design'];
                        foreach ($all_depts as $d): ?>
                        <option value="<?= $d ?>"><?= $d ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Tier স্তর</label>
                    <select name="tier_level" id="f_tier" class="form-input">
                        <option value="1">Tier ১ — নেতৃত্ব (CEO, COO)</option>
                        <option value="2" selected>Tier ২ — ম্যানেজমেন্ট</option>
                        <option value="3">Tier ৩ — ফিল্ড ওয়ার্কার</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="form-label">বিবরণ (Description)</label>
                <textarea name="description" id="f_desc" rows="3" class="form-input resize-none" placeholder="পদবীর দায়িত্ব ও কাজের বিবরণ..."></textarea>
            </div>

            <div class="flex gap-3 pt-2">
                <button type="button" onclick="closeModal()" class="flex-1 py-3 rounded-xl border border-slate-200 text-slate-600 font-bold text-sm hover:bg-slate-50 transition">
                    বাতিল
                </button>
                <button type="submit" class="flex-1 btn-primary py-3 rounded-xl font-bold text-sm flex items-center justify-center gap-2">
                    <i class="fas fa-save"></i> সংরক্ষণ করুন
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── DELETE CONFIRM MODAL ───────────────────────────────── -->
<div class="modal-bg" id="delModal">
    <div class="modal-box max-w-sm">
        <div class="p-6 text-center">
            <div class="w-14 h-14 rounded-2xl mx-auto mb-4 flex items-center justify-center text-2xl bg-red-50 text-red-500">
                <i class="fas fa-trash-alt"></i>
            </div>
            <h3 class="text-base font-black text-slate-800 mb-1">পদবী মুছবেন?</h3>
            <p class="text-sm text-slate-500 mb-1"><strong id="delName" class="text-slate-700"></strong></p>
            <p class="text-xs text-slate-400 mb-5">এই কাজ পূর্বাবস্থায় ফেরানো যাবে না।</p>
            <form method="POST" id="delForm">
                <input type="hidden" name="action" value="delete_position">
                <input type="hidden" name="id" id="delId">
                <div class="flex gap-3">
                    <button type="button" onclick="document.getElementById('delModal').classList.remove('open')"
                        class="flex-1 py-3 rounded-xl border border-slate-200 text-slate-600 font-bold text-sm">বাতিল</button>
                    <button type="submit" class="flex-1 py-3 rounded-xl bg-red-600 text-white font-bold text-sm hover:bg-red-700 transition">
                        <i class="fas fa-trash-alt mr-1"></i> হ্যাঁ, মুছুন
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── BOTTOM NAV (mobile) ───────────────────────────────── -->
<nav class="fixed bottom-0 left-0 right-0 glass border-t border-slate-200 lg:hidden z-40">
    <div class="flex items-center justify-around py-2">
        <a href="index.php" class="flex flex-col items-center gap-0.5 px-4 py-1 text-slate-400 hover:text-red-600 transition">
            <i class="fas fa-home text-base"></i><span class="text-[10px] font-bold">হোম</span>
        </a>
        <a href="manage_employees.php" class="flex flex-col items-center gap-0.5 px-4 py-1 text-slate-400 hover:text-red-600 transition">
            <i class="fas fa-users text-base"></i><span class="text-[10px] font-bold">কর্মী</span>
        </a>
        <button onclick="openModal()" class="flex flex-col items-center gap-0.5 px-4 py-1">
            <div class="w-10 h-10 rounded-xl text-white flex items-center justify-center -mt-5 shadow-lg" style="background:linear-gradient(135deg,#DC2626,#F97316)">
                <i class="fas fa-plus text-base"></i>
            </div>
            <span class="text-[10px] font-bold text-red-600">যোগ করুন</span>
        </button>
        <a href="manage_kpi.php" class="flex flex-col items-center gap-0.5 px-4 py-1 text-slate-400 hover:text-red-600 transition">
            <i class="fas fa-bullseye text-base"></i><span class="text-[10px] font-bold">KPI</span>
        </a>
        <a href="logout.php" class="flex flex-col items-center gap-0.5 px-4 py-1 text-slate-400 hover:text-red-600 transition">
            <i class="fas fa-sign-out-alt text-base"></i><span class="text-[10px] font-bold">বের হন</span>
        </a>
    </div>
</nav>

<script>
function openModal(data = null) {
    document.getElementById('posModal').classList.add('open');
    if (!data) {
        document.getElementById('modalTitle').textContent = 'নতুন পদবী যোগ করুন';
        document.getElementById('posForm').reset();
        document.getElementById('f_id').value = '0';
    }
}
function closeModal() { document.getElementById('posModal').classList.remove('open'); }

function editPosition(p) {
    document.getElementById('modalTitle').textContent = 'পদবী সম্পাদনা করুন';
    document.getElementById('f_id').value      = p.id;
    document.getElementById('f_name').value    = p.position_name;
    document.getElementById('f_name_bn').value = p.position_name_bn || '';
    document.getElementById('f_dept').value    = p.department || '';
    document.getElementById('f_tier').value    = p.tier_level || '2';
    document.getElementById('f_desc').value    = p.description || '';
    document.getElementById('posModal').classList.add('open');
}

function confirmDelete(id, name) {
    document.getElementById('delId').value        = id;
    document.getElementById('delName').textContent = name;
    document.getElementById('delModal').classList.add('open');
}

// Close modal on backdrop click
['posModal','delModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('open');
    });
});
</script>
</body>
</html>
