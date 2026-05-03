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

// ── Schema ensure ────────────────────────────────────────
try {
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
} catch (PDOException $e) {}

$msg = $_SESSION['msg_success'] ?? '';
$err = $_SESSION['msg_error']   ?? '';
unset($_SESSION['msg_success'], $_SESSION['msg_error']);

// ── Helpers ───────────────────────────────────────────────
function validateUpload($file) {
    $allowed_mime = ['image/jpeg','image/png','image/webp','image/gif'];
    $max_size     = 2 * 1024 * 1024; // 2 MB
    if (!in_array($file['type'], $allowed_mime)) return ['ok'=>false,'msg'=>'শুধু JPG/PNG/WEBP ছবি অনুমোদিত।'];
    if ($file['size'] > $max_size)              return ['ok'=>false,'msg'=>'ছবির আকার ২MB এর বেশি হবে না।'];
    return ['ok'=>true];
}

// ── POST actions ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'save_employee') {
        $id         = (int)($_POST['id'] ?? 0);
        $name       = trim($_POST['name']);
        $name_bn    = trim($_POST['name_bn']    ?? '');
        $phone      = trim($_POST['phone']      ?? '');
        $email      = trim($_POST['email']      ?? '');
        $nid        = trim($_POST['nid_number'] ?? '');
        $address    = trim($_POST['address']    ?? '');
        $pos_id     = (int)($_POST['position_id'] ?? 0) ?: null;
        $dept       = trim($_POST['department'] ?? '');
        $join_date  = $_POST['join_date'] ?: null;
        $salary     = (float)($_POST['monthly_salary'] ?? 0);
        $emp_type   = $_POST['employment_type'] ?? 'full_time';
        $status     = $_POST['status']          ?? 'active';
        $emergency  = trim($_POST['emergency_contact'] ?? '');
        $bank       = trim($_POST['bank_account']      ?? '');

        if (empty($name)) { $_SESSION['msg_error'] = "কর্মীর নাম দেওয়া আবশ্যক।"; header("Location: manage_employees.php"); exit; }

        // Photo upload
        $pic_path = $_POST['existing_picture'] ?? null;
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $check = validateUpload($_FILES['profile_picture']);
            if (!$check['ok']) { $_SESSION['msg_error'] = $check['msg']; header("Location: manage_employees.php"); exit; }
            $uploadDir = __DIR__ . '/uploads/employees/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $ext      = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
            $fileName = 'emp_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . strtolower($ext);
            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $uploadDir . $fileName)) {
                $pic_path = 'admin/uploads/employees/' . $fileName;
            }
        }

        try {
            if ($id > 0) {
                $pdo->prepare("UPDATE employees SET name=?,name_bn=?,phone=?,email=?,nid_number=?,address=?,position_id=?,department=?,join_date=?,monthly_salary=?,employment_type=?,status=?,profile_picture=?,emergency_contact=?,bank_account=? WHERE id=?")
                    ->execute([$name,$name_bn,$phone,$email,$nid,$address,$pos_id,$dept,$join_date,$salary,$emp_type,$status,$pic_path,$emergency,$bank,$id]);
                $_SESSION['msg_success'] = "কর্মীর তথ্য আপডেট হয়েছে!";
            } else {
                $pdo->prepare("INSERT INTO employees (name,name_bn,phone,email,nid_number,address,position_id,department,join_date,monthly_salary,employment_type,status,profile_picture,emergency_contact,bank_account) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$name,$name_bn,$phone,$email,$nid,$address,$pos_id,$dept,$join_date,$salary,$emp_type,$status,$pic_path,$emergency,$bank]);
                $_SESSION['msg_success'] = "নতুন কর্মী যোগ হয়েছে!";
            }
        } catch (PDOException $e) {
            $_SESSION['msg_error'] = "সমস্যা হয়েছে: " . $e->getMessage();
        }
        header("Location: manage_employees.php"); exit;
    }

    if ($action === 'delete_employee') {
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM employees WHERE id=?")->execute([$id]);
        $_SESSION['msg_success'] = "কর্মী মুছে ফেলা হয়েছে।";
        header("Location: manage_employees.php"); exit;
    }

    if ($action === 'change_status') {
        $id  = (int)$_POST['id'];
        $new = $_POST['new_status'];
        $pdo->prepare("UPDATE employees SET status=? WHERE id=?")->execute([$new, $id]);
        $_SESSION['msg_success'] = "কর্মীর অবস্থা পরিবর্তন হয়েছে।";
        header("Location: manage_employees.php"); exit;
    }
}

// ── Data fetch ────────────────────────────────────────────
$filter_dept   = $_GET['dept']   ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_type   = $_GET['type']   ?? '';
$search        = trim($_GET['q'] ?? '');
$page          = max(1, (int)($_GET['p'] ?? 1));
$per_page      = 12;

$where  = "WHERE 1=1";
$params = [];
if ($filter_dept)   { $where .= " AND e.department=?";        $params[] = $filter_dept; }
if ($filter_status) { $where .= " AND e.status=?";            $params[] = $filter_status; }
if ($filter_type)   { $where .= " AND e.employment_type=?";   $params[] = $filter_type; }
if ($search)        { $where .= " AND (e.name LIKE ? OR e.name_bn LIKE ? OR e.phone LIKE ?)"; $p="%$search%"; $params=array_merge($params,[$p,$p,$p]); }

$total_count = $pdo->prepare("SELECT COUNT(*) FROM employees e $where");
$total_count->execute($params);
$total_rows = (int)$total_count->fetchColumn();
$total_pages = max(1, ceil($total_rows / $per_page));
$offset = ($page - 1) * $per_page;

$stmt = $pdo->prepare("SELECT e.*, p.position_name, p.position_name_bn FROM employees e LEFT JOIN positions p ON e.position_id=p.id $where ORDER BY e.created_at DESC LIMIT $per_page OFFSET $offset");
$stmt->execute($params);
$employees = $stmt->fetchAll();

// Positions for dropdown
$positions = $pdo->query("SELECT id, position_name, position_name_bn, department FROM positions WHERE is_active=1 ORDER BY department, position_name")->fetchAll();

// Summary stats
$stats_rows = $pdo->query("SELECT status, COUNT(*) as cnt FROM employees GROUP BY status")->fetchAll();
$status_map = [];
foreach ($stats_rows as $r) $status_map[$r['status']] = $r['cnt'];

$type_map_label = ['full_time'=>'ফুলটাইম','part_time'=>'পার্টটাইম','contract'=>'চুক্তি','rider'=>'রাইডার'];
$status_label   = ['active'=>'কর্মরত','inactive'=>'নিষ্ক্রিয়','terminated'=>'বরখাস্ত'];
$status_color   = ['active'=>['#d1fae5','#065f46','#6ee7b7'],'inactive'=>['#f1f5f9','#64748b','#e2e8f0'],'terminated'=>['#fee2e2','#991b1b','#fca5a5']];
$type_color     = ['full_time'=>'#3B82F6','part_time'=>'#F59E0B','contract'=>'#8B5CF6','rider'=>'#EF4444'];
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>কর্মী ব্যবস্থাপনা — Sodai Lagbe ERP</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config={theme:{extend:{fontFamily:{bn:['Hind Siliguri','sans-serif'],en:['Inter','sans-serif']},colors:{primary:'#DC2626',secondary:'#F97316'}}}}</script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Hind Siliguri','Inter',sans-serif;background:#f1f5f9;min-height:100vh;-webkit-tap-highlight-color:transparent}
::-webkit-scrollbar{width:4px;height:4px}::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:99px}
.glass{background:rgba(255,255,255,.85);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.6)}
.btn-primary{background:linear-gradient(135deg,#DC2626,#F97316);color:white;transition:all .2s;box-shadow:0 4px 14px rgba(220,38,38,.3)}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(220,38,38,.4)}
.form-input{width:100%;padding:11px 14px;background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:12px;font-size:13.5px;font-family:inherit;color:#1e293b;outline:none;transition:all .2s}
.form-input:focus{background:white;border-color:#DC2626;box-shadow:0 0 0 3px rgba(220,38,38,.1)}
.form-label{display:block;font-size:11px;font-weight:700;color:#64748b;margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px}
.modal-bg{position:fixed;inset:0;background:rgba(15,23,42,.55);backdrop-filter:blur(4px);z-index:50;display:none;align-items:flex-start;justify-content:center;padding:16px;overflow-y:auto}
.modal-bg.open{display:flex}
.modal-box{background:white;border-radius:24px;width:100%;max-width:600px;margin:auto;box-shadow:0 24px 64px rgba(0,0,0,.15);animation:slideUp .25s ease}
@keyframes slideUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
.emp-card{transition:all .2s;cursor:default}
.emp-card:hover{transform:translateY(-2px);box-shadow:0 12px 32px rgba(0,0,0,.08)}
.toast{position:fixed;bottom:80px;left:50%;transform:translateX(-50%);z-index:100;padding:12px 20px;border-radius:14px;font-size:14px;font-weight:600;white-space:nowrap;animation:toastIn .3s ease;box-shadow:0 8px 24px rgba(0,0,0,.15)}
@keyframes toastIn{from{opacity:0;transform:translateX(-50%) translateY(10px)}to{opacity:1;transform:translateX(-50%) translateY(0)}}
.avatar{width:44px;height:44px;border-radius:14px;object-fit:cover;background:#f1f5f9;flex-shrink:0}
.avatar-placeholder{width:44px;height:44px;border-radius:14px;background:linear-gradient(135deg,#DC2626,#F97316);display:flex;align-items:center;justify-content:center;color:white;font-size:16px;font-weight:800;flex-shrink:0}
</style>
</head>
<body class="pb-20 lg:pb-0">

<!-- TOP NAV -->
<nav class="glass sticky top-0 z-40 shadow-sm">
    <div class="max-w-7xl mx-auto px-4 h-14 flex items-center gap-3">
        <a href="index.php" class="w-8 h-8 flex items-center justify-center rounded-xl bg-slate-100 text-slate-600 hover:bg-red-50 hover:text-red-600 transition text-sm"><i class="fas fa-arrow-left"></i></a>
        <div class="flex items-center gap-2 flex-1 min-w-0">
            <div class="w-8 h-8 rounded-xl flex items-center justify-center text-white text-sm" style="background:linear-gradient(135deg,#DC2626,#F97316)"><i class="fas fa-users"></i></div>
            <div class="min-w-0">
                <h1 class="text-[15px] font-bold text-slate-800 leading-none">কর্মী ব্যবস্থাপনা</h1>
                <p class="text-[11px] text-slate-400 mt-0.5">Employee Management</p>
            </div>
        </div>
        <button onclick="openModal()" class="btn-primary flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-bold">
            <i class="fas fa-user-plus text-xs"></i><span class="hidden sm:inline">নতুন কর্মী</span>
        </button>
    </div>
</nav>

<!-- TOAST -->
<?php if ($msg): ?><div class="toast" style="background:#dcfce7;color:#166534;border:1px solid #bbf7d0" id="toast"><i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($msg) ?></div>
<script>setTimeout(()=>{const t=document.getElementById('toast');if(t){t.style.opacity='0';t.style.transition='opacity .4s';setTimeout(()=>t.remove(),400);}},3000);</script><?php endif; ?>
<?php if ($err): ?><div class="toast" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5" id="toast"><i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($err) ?></div>
<script>setTimeout(()=>{const t=document.getElementById('toast');if(t){t.style.opacity='0';t.style.transition='opacity .4s';setTimeout(()=>t.remove(),400);}},4000);</script><?php endif; ?>

<div class="max-w-7xl mx-auto px-4 py-5 space-y-5">

    <!-- STATS -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <?php
        $s_stats = [
            ['মোট কর্মী', $total_rows, 'fa-users', '#DC2626', '#fee2e2'],
            ['কর্মরত', $status_map['active'] ?? 0, 'fa-user-check', '#10B981', '#d1fae5'],
            ['নিষ্ক্রিয়', $status_map['inactive'] ?? 0, 'fa-user-clock', '#F59E0B', '#fef3c7'],
            ['বরখাস্ত', $status_map['terminated'] ?? 0, 'fa-user-times', '#6B7280', '#f1f5f9'],
        ];
        foreach ($s_stats as [$label,$val,$ico,$col,$bg]):
        ?>
        <div class="glass rounded-2xl p-4 flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl flex items-center justify-center text-base flex-shrink-0" style="background:<?= $bg ?>;color:<?= $col ?>"><i class="fas <?= $ico ?>"></i></div>
            <div><div class="text-xl font-black text-slate-800"><?= $val ?></div><div class="text-xs text-slate-500 font-medium"><?= $label ?></div></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- FILTERS -->
    <div class="glass rounded-2xl p-4 flex flex-col sm:flex-row gap-3">
        <form method="GET" class="flex-1 min-w-0">
            <div class="relative">
                <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="নাম, ফোন দিয়ে খুঁজুন..."
                       class="form-input pl-10">
            </div>
        </form>
        <div class="flex gap-2 flex-wrap">
            <?php
            $f_url = fn($k,$v) => '?' . http_build_query(array_filter(array_merge(['dept'=>$filter_dept,'status'=>$filter_status,'type'=>$filter_type,'q'=>$search], [$k=>$v])));
            foreach ([''=>'সব','active'=>'কর্মরত','inactive'=>'নিষ্ক্রিয়','terminated'=>'বরখাস্ত'] as $val=>$lbl):
            ?>
            <a href="<?= $f_url('status',$val) ?>" class="px-3 py-2 rounded-xl text-xs font-bold border transition
                <?= $filter_status===$val ? 'text-white border-transparent' : 'bg-white text-slate-500 border-slate-200' ?>"
                style="<?= $filter_status===$val ? 'background:linear-gradient(135deg,#DC2626,#F97316)' : '' ?>">
                <?= $lbl ?>
            </a>
            <?php endforeach; ?>
            <?php foreach ([''=>'সব ধরন','rider'=>'🏍️ রাইডার','full_time'=>'ফুলটাইম','part_time'=>'পার্টটাইম','contract'=>'চুক্তি'] as $val=>$lbl): ?>
            <a href="<?= $f_url('type',$val) ?>" class="px-3 py-2 rounded-xl text-xs font-bold border transition
                <?= $filter_type===$val ? 'text-white border-transparent' : 'bg-white text-slate-500 border-slate-200' ?>"
                style="<?= $filter_type===$val ? 'background:linear-gradient(135deg,#DC2626,#F97316)' : '' ?>">
                <?= $lbl ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- EMPLOYEE GRID -->
    <?php if (empty($employees)): ?>
    <div class="glass rounded-2xl p-12 text-center">
        <div class="w-16 h-16 rounded-2xl mx-auto mb-4 flex items-center justify-center text-2xl" style="background:#fee2e2;color:#DC2626"><i class="fas fa-users-slash"></i></div>
        <h3 class="text-base font-bold text-slate-700 mb-1">কোনো কর্মী পাওয়া যায়নি</h3>
        <p class="text-sm text-slate-400 mb-4">ফিল্টার পরিবর্তন করুন অথবা নতুন কর্মী যোগ করুন।</p>
        <button onclick="openModal()" class="btn-primary px-5 py-2.5 rounded-xl text-sm font-bold inline-flex items-center gap-2"><i class="fas fa-user-plus"></i> নতুন কর্মী যোগ</button>
    </div>
    <?php else: ?>
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
    <?php foreach ($employees as $e):
        $sc = $status_color[$e['status']] ?? ['#f1f5f9','#64748b','#e2e8f0'];
        $initials = mb_strtoupper(mb_substr($e['name'], 0, 1, 'UTF-8'));
    ?>
    <div class="glass rounded-2xl p-4 emp-card <?= $e['status']==='terminated'?'opacity-60':'' ?>">
        <div class="flex items-start gap-3">
            <!-- Avatar -->
            <?php if ($e['profile_picture'] && file_exists('../' . $e['profile_picture'])): ?>
            <img src="../<?= htmlspecialchars($e['profile_picture']) ?>" class="avatar" alt="photo">
            <?php else: ?>
            <div class="avatar-placeholder"><?= htmlspecialchars($initials) ?></div>
            <?php endif; ?>

            <div class="flex-1 min-w-0">
                <div class="flex items-start justify-between gap-1">
                    <div class="min-w-0">
                        <h3 class="text-sm font-bold text-slate-800 leading-tight truncate"><?= htmlspecialchars($e['name']) ?></h3>
                        <?php if ($e['name_bn']): ?><p class="text-xs text-slate-500 mt-0.5"><?= htmlspecialchars($e['name_bn']) ?></p><?php endif; ?>
                    </div>
                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-full flex-shrink-0"
                          style="background:<?= $sc[0] ?>;color:<?= $sc[1] ?>;border:1px solid <?= $sc[2] ?>">
                        <?= $status_label[$e['status']] ?? $e['status'] ?>
                    </span>
                </div>

                <div class="flex flex-wrap gap-1.5 mt-2">
                    <?php if ($e['position_name']): ?>
                    <span class="text-[11px] bg-slate-100 text-slate-600 px-2 py-0.5 rounded-lg font-semibold truncate max-w-full">
                        <i class="fas fa-briefcase text-[9px] mr-1"></i><?= htmlspecialchars($e['position_name_bn'] ?: $e['position_name']) ?>
                    </span>
                    <?php endif; ?>
                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-full text-white"
                          style="background:<?= $type_color[$e['employment_type']] ?? '#64748b' ?>">
                        <?= $type_map_label[$e['employment_type']] ?? $e['employment_type'] ?>
                    </span>
                </div>

                <div class="mt-2 space-y-0.5">
                    <?php if ($e['phone']): ?>
                    <p class="text-xs text-slate-500"><i class="fas fa-phone text-[10px] mr-1.5 text-slate-400"></i><?= htmlspecialchars($e['phone']) ?></p>
                    <?php endif; ?>
                    <?php if ($e['department']): ?>
                    <p class="text-xs text-slate-500"><i class="fas fa-building text-[10px] mr-1.5 text-slate-400"></i><?= htmlspecialchars($e['department']) ?></p>
                    <?php endif; ?>
                    <?php if ($e['monthly_salary'] > 0): ?>
                    <p class="text-xs text-slate-500"><i class="fas fa-money-bill-wave text-[10px] mr-1.5 text-slate-400"></i>৳<?= number_format($e['monthly_salary']) ?>/মাস</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Actions -->
        <div class="flex items-center gap-2 mt-3 pt-3 border-t border-slate-100">
            <div class="flex-1">
                <?php if ($e['join_date']): ?>
                <p class="text-[10px] text-slate-400"><i class="fas fa-calendar mr-1"></i>যোগদান: <?= date('d M Y', strtotime($e['join_date'])) ?></p>
                <?php endif; ?>
            </div>
            <button onclick="editEmployee(<?= htmlspecialchars(json_encode($e), ENT_QUOTES) ?>)"
                    class="w-8 h-8 rounded-xl bg-blue-50 text-blue-600 hover:bg-blue-100 transition text-xs flex items-center justify-center">
                <i class="fas fa-pencil-alt"></i>
            </button>
            <button onclick="confirmDelete(<?= $e['id'] ?>, '<?= htmlspecialchars($e['name'], ENT_QUOTES) ?>')"
                    class="w-8 h-8 rounded-xl bg-red-50 text-red-500 hover:bg-red-100 transition text-xs flex items-center justify-center">
                <i class="fas fa-trash-alt"></i>
            </button>
        </div>
    </div>
    <?php endforeach; ?>
    </div>

    <!-- PAGINATION -->
    <?php if ($total_pages > 1): ?>
    <div class="flex justify-center gap-2">
        <?php for ($i=1; $i<=$total_pages; $i++): ?>
        <a href="?<?= http_build_query(array_merge(['dept'=>$filter_dept,'status'=>$filter_status,'type'=>$filter_type,'q'=>$search,'p'=>$i])) ?>"
           class="w-9 h-9 flex items-center justify-center rounded-xl text-sm font-bold border transition
           <?= $i===$page ? 'text-white border-transparent' : 'bg-white text-slate-600 border-slate-200 hover:border-red-300' ?>"
           style="<?= $i===$page ? 'background:linear-gradient(135deg,#DC2626,#F97316)' : '' ?>">
            <?= $i ?>
        </a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

</div>

<!-- ADD / EDIT MODAL -->
<div class="modal-bg" id="empModal">
<div class="modal-box my-4">
    <div class="p-5 border-b border-slate-100 flex items-center justify-between sticky top-0 bg-white rounded-t-3xl z-10">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl text-white flex items-center justify-center text-sm" style="background:linear-gradient(135deg,#DC2626,#F97316)"><i class="fas fa-user-plus"></i></div>
            <div><h2 class="text-base font-black text-slate-800" id="modalTitle">নতুন কর্মী যোগ</h2><p class="text-xs text-slate-400">Employee details</p></div>
        </div>
        <button onclick="closeModal()" class="w-8 h-8 rounded-xl bg-slate-100 text-slate-500 hover:bg-slate-200 transition text-sm flex items-center justify-center"><i class="fas fa-times"></i></button>
    </div>

    <form method="POST" enctype="multipart/form-data" id="empForm" class="p-5 space-y-4">
        <input type="hidden" name="action" value="save_employee">
        <input type="hidden" name="id" id="f_id" value="0">
        <input type="hidden" name="existing_picture" id="f_existing_pic" value="">

        <!-- Photo upload -->
        <div class="flex items-center gap-4">
            <div class="relative">
                <div id="avatarPreview" class="w-16 h-16 rounded-2xl bg-gradient-to-br from-red-500 to-orange-400 flex items-center justify-center text-white text-2xl font-black overflow-hidden">
                    <i class="fas fa-user"></i>
                </div>
                <label for="photoInput" class="absolute -bottom-1 -right-1 w-6 h-6 rounded-full bg-white border-2 border-white shadow-md flex items-center justify-center cursor-pointer text-xs" style="background:linear-gradient(135deg,#DC2626,#F97316);border-color:white">
                    <i class="fas fa-camera text-white"></i>
                </label>
                <input type="file" name="profile_picture" id="photoInput" accept="image/*" class="hidden" onchange="previewPhoto(this)">
            </div>
            <div>
                <p class="text-xs font-bold text-slate-700">প্রোফাইল ছবি</p>
                <p class="text-[11px] text-slate-400 mt-0.5">JPG/PNG, সর্বোচ্চ ২MB</p>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div><label class="form-label">নাম (English) <span class="text-red-500">*</span></label>
                <input type="text" name="name" id="f_name" class="form-input" placeholder="Full Name" required></div>
            <div><label class="form-label">নাম (বাংলা)</label>
                <input type="text" name="name_bn" id="f_name_bn" class="form-input" placeholder="বাংলায় নাম"></div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div><label class="form-label">ফোন নম্বর</label>
                <input type="tel" name="phone" id="f_phone" class="form-input" placeholder="01XXXXXXXXX"></div>
            <div><label class="form-label">ইমেইল</label>
                <input type="email" name="email" id="f_email" class="form-input" placeholder="email@example.com"></div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div><label class="form-label">পদবী</label>
                <select name="position_id" id="f_pos" class="form-input">
                    <option value="">-- পদবী নির্বাচন --</option>
                    <?php
                    $cur_dept = '';
                    foreach ($positions as $pos):
                        if ($pos['department'] !== $cur_dept) {
                            if ($cur_dept) echo '</optgroup>';
                            echo '<optgroup label="' . htmlspecialchars($pos['department']) . '">';
                            $cur_dept = $pos['department'];
                        }
                    ?>
                    <option value="<?= $pos['id'] ?>"><?= htmlspecialchars($pos['position_name']) ?><?= $pos['position_name_bn'] ? ' ('.$pos['position_name_bn'].')' : '' ?></option>
                    <?php endforeach; if ($cur_dept) echo '</optgroup>'; ?>
                </select>
            </div>
            <div><label class="form-label">কর্মসংস্থানের ধরন</label>
                <select name="employment_type" id="f_type" class="form-input">
                    <option value="full_time">ফুলটাইম</option>
                    <option value="part_time">পার্টটাইম</option>
                    <option value="contract">চুক্তিভিত্তিক</option>
                    <option value="rider">ডেলিভারি রাইডার</option>
                </select>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div><label class="form-label">যোগদানের তারিখ</label>
                <input type="date" name="join_date" id="f_join" class="form-input"></div>
            <div><label class="form-label">মাসিক বেতন (৳)</label>
                <input type="number" name="monthly_salary" id="f_salary" class="form-input" placeholder="0" min="0" step="100"></div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div><label class="form-label">NID নম্বর</label>
                <input type="text" name="nid_number" id="f_nid" class="form-input" placeholder="NID Number"></div>
            <div><label class="form-label">জরুরি যোগাযোগ</label>
                <input type="tel" name="emergency_contact" id="f_emg" class="form-input" placeholder="01XXXXXXXXX"></div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div><label class="form-label">ব্যাংক অ্যাকাউন্ট</label>
                <input type="text" name="bank_account" id="f_bank" class="form-input" placeholder="Account number"></div>
            <div><label class="form-label">অবস্থা</label>
                <select name="status" id="f_status" class="form-input">
                    <option value="active">কর্মরত</option>
                    <option value="inactive">নিষ্ক্রিয়</option>
                    <option value="terminated">বরখাস্ত</option>
                </select>
            </div>
        </div>

        <div><label class="form-label">ঠিকানা</label>
            <textarea name="address" id="f_address" rows="2" class="form-input resize-none" placeholder="সম্পূর্ণ ঠিকানা..."></textarea>
        </div>

        <div class="flex gap-3 pt-1">
            <button type="button" onclick="closeModal()" class="flex-1 py-3 rounded-xl border border-slate-200 text-slate-600 font-bold text-sm hover:bg-slate-50 transition">বাতিল</button>
            <button type="submit" class="flex-1 btn-primary py-3 rounded-xl font-bold text-sm flex items-center justify-center gap-2">
                <i class="fas fa-save"></i> সংরক্ষণ করুন
            </button>
        </div>
    </form>
</div>
</div>

<!-- DELETE MODAL -->
<div class="modal-bg" id="delModal">
<div class="modal-box max-w-sm">
    <div class="p-6 text-center">
        <div class="w-14 h-14 rounded-2xl mx-auto mb-4 flex items-center justify-center text-2xl bg-red-50 text-red-500"><i class="fas fa-user-times"></i></div>
        <h3 class="text-base font-black text-slate-800 mb-1">কর্মী মুছবেন?</h3>
        <p class="text-sm text-slate-700 mb-1 font-bold" id="delName"></p>
        <p class="text-xs text-slate-400 mb-5">এই কাজ পূর্বাবস্থায় ফেরানো যাবে না।</p>
        <form method="POST" id="delForm">
            <input type="hidden" name="action" value="delete_employee">
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

<!-- BOTTOM NAV -->
<nav class="fixed bottom-0 left-0 right-0 glass border-t border-slate-200 lg:hidden z-40">
    <div class="flex items-center justify-around py-2">
        <a href="index.php" class="flex flex-col items-center gap-0.5 px-4 py-1 text-slate-400 hover:text-red-600 transition"><i class="fas fa-home text-base"></i><span class="text-[10px] font-bold">হোম</span></a>
        <a href="manage_positions.php" class="flex flex-col items-center gap-0.5 px-4 py-1 text-slate-400 hover:text-red-600 transition"><i class="fas fa-briefcase text-base"></i><span class="text-[10px] font-bold">পদবী</span></a>
        <button onclick="openModal()" class="flex flex-col items-center gap-0.5 px-4 py-1">
            <div class="w-10 h-10 rounded-xl text-white flex items-center justify-center -mt-5 shadow-lg" style="background:linear-gradient(135deg,#DC2626,#F97316)"><i class="fas fa-user-plus text-base"></i></div>
            <span class="text-[10px] font-bold text-red-600">যোগ করুন</span>
        </button>
        <a href="manage_kpi.php" class="flex flex-col items-center gap-0.5 px-4 py-1 text-slate-400 hover:text-red-600 transition"><i class="fas fa-bullseye text-base"></i><span class="text-[10px] font-bold">KPI</span></a>
        <a href="logout.php" class="flex flex-col items-center gap-0.5 px-4 py-1 text-slate-400 hover:text-red-600 transition"><i class="fas fa-sign-out-alt text-base"></i><span class="text-[10px] font-bold">বের হন</span></a>
    </div>
</nav>

<script>
function openModal(data=null){
    document.getElementById('empModal').classList.add('open');
    if(!data){
        document.getElementById('modalTitle').textContent='নতুন কর্মী যোগ';
        document.getElementById('empForm').reset();
        document.getElementById('f_id').value='0';
        document.getElementById('f_existing_pic').value='';
        document.getElementById('avatarPreview').innerHTML='<i class="fas fa-user"></i>';
        document.getElementById('avatarPreview').className='w-16 h-16 rounded-2xl flex items-center justify-center text-white text-2xl font-black overflow-hidden';
        document.getElementById('avatarPreview').style.background='linear-gradient(135deg,#DC2626,#F97316)';
    }
}
function closeModal(){document.getElementById('empModal').classList.remove('open');}

function editEmployee(e){
    document.getElementById('modalTitle').textContent='কর্মীর তথ্য সম্পাদনা';
    document.getElementById('f_id').value=e.id;
    document.getElementById('f_name').value=e.name||'';
    document.getElementById('f_name_bn').value=e.name_bn||'';
    document.getElementById('f_phone').value=e.phone||'';
    document.getElementById('f_email').value=e.email||'';
    document.getElementById('f_nid').value=e.nid_number||'';
    document.getElementById('f_address').value=e.address||'';
    document.getElementById('f_pos').value=e.position_id||'';
    document.getElementById('f_type').value=e.employment_type||'full_time';
    document.getElementById('f_join').value=e.join_date||'';
    document.getElementById('f_salary').value=e.monthly_salary||'';
    document.getElementById('f_status').value=e.status||'active';
    document.getElementById('f_emg').value=e.emergency_contact||'';
    document.getElementById('f_bank').value=e.bank_account||'';
    document.getElementById('f_existing_pic').value=e.profile_picture||'';
    // Avatar preview
    const av=document.getElementById('avatarPreview');
    if(e.profile_picture){
        av.innerHTML='<img src="../'+e.profile_picture+'" style="width:100%;height:100%;object-fit:cover">';
        av.style.background='';
    } else {
        av.innerHTML=e.name.charAt(0).toUpperCase();
        av.style.background='linear-gradient(135deg,#DC2626,#F97316)';
    }
    document.getElementById('empModal').classList.add('open');
}

function previewPhoto(input){
    if(input.files&&input.files[0]){
        const reader=new FileReader();
        reader.onload=e=>{
            const av=document.getElementById('avatarPreview');
            av.innerHTML='<img src="'+e.target.result+'" style="width:100%;height:100%;object-fit:cover">';
            av.style.background='';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function confirmDelete(id,name){
    document.getElementById('delId').value=id;
    document.getElementById('delName').textContent=name;
    document.getElementById('delModal').classList.add('open');
}

['empModal','delModal'].forEach(id=>{
    document.getElementById(id).addEventListener('click',function(e){if(e.target===this)this.classList.remove('open');});
});
</script>
</body>
</html>
