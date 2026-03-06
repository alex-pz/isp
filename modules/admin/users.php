<?php
// ============================================================
// ADMIN — USER MANAGEMENT
// File: modules/admin/users.php
// ============================================================
require_once __DIR__ . '/../../includes/functions.php';
requirePermission('users', 'view');

$user      = getCurrentUser();
$pageTitle = 'ব্যবহারকারী ব্যবস্থাপনা';
$errors    = [];
$editUser  = null;
$mode      = $_GET['mode'] ?? 'list'; // list | add | edit

// ---- Load all roles ----
$roles = Database::fetchAll("SELECT * FROM roles ORDER BY id");

// ---- Load companies (for dropdown) ----
$companies = Database::fetchAll("SELECT id, company_name FROM companies WHERE status = 'approved' ORDER BY company_name");

// ---- Handle POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        setFlash('error', 'নিরাপত্তা যাচাই ব্যর্থ।'); redirect($_SERVER['PHP_SELF']);
    }

    $action = $_POST['action'] ?? '';

    // ---- Toggle user status ----
    if ($action === 'toggle_status') {
        $uid    = (int)$_POST['user_id'];
        $status = $_POST['status'];
        if ($uid !== $user['id'] && in_array($status, ['active','inactive','suspended'])) {
            Database::update('users', ['status' => $status], 'id = ?', [$uid]);
            logActivity("user.$status", 'users', ['target_id' => $uid, 'target_type' => 'users']);
            setFlash('success', 'স্ট্যাটাস পরিবর্তন হয়েছে।');
        }
        redirect($_SERVER['PHP_SELF']);
    }

    // ---- Delete user ----
    if ($action === 'delete_user' && hasPermission('users', 'delete')) {
        $uid = (int)$_POST['user_id'];
        if ($uid !== $user['id']) {
            Database::update('users', ['status' => 'inactive', 'company_id' => null], 'id = ?', [$uid]);
            logActivity('user.delete', 'users', ['target_id' => $uid]);
            setFlash('success', 'ব্যবহারকারী নিষ্ক্রিয় করা হয়েছে।');
        }
        redirect($_SERVER['PHP_SELF']);
    }

    // ---- Save user (add/edit) ----
    if (in_array($action, ['add_user', 'edit_user'])) {
        $isEdit   = ($action === 'edit_user');
        $editId   = (int)($_POST['edit_id'] ?? 0);
        $formData = [
            'full_name'  => trim($_POST['full_name'] ?? ''),
            'username'   => trim($_POST['username']  ?? ''),
            'email'      => trim($_POST['email']     ?? ''),
            'phone'      => trim($_POST['phone']     ?? ''),
            'role_id'    => (int)($_POST['role_id']  ?? 0),
            'company_id' => (int)($_POST['company_id'] ?? 0) ?: null,
            'status'     => $_POST['status'] ?? 'active',
            'password'   => $_POST['password'] ?? '',
        ];

        // Validation
        if (empty($formData['full_name']))   $errors[] = 'পূর্ণ নাম দিন।';
        if (empty($formData['username']))    $errors[] = 'ইউজারনেম দিন।';
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $formData['username'])) $errors[] = 'ইউজারনেম ফরম্যাট সঠিক নয়।';
        if (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL))  $errors[] = 'সঠিক ইমেইল দিন।';
        if (!$formData['role_id'])           $errors[] = 'রোল নির্বাচন করুন।';
        if (!$isEdit && strlen($formData['password']) < 8) $errors[] = 'পাসওয়ার্ড কমপক্ষে ৮ অক্ষর।';
        if ($isEdit && !empty($formData['password']) && strlen($formData['password']) < 8) $errors[] = 'পাসওয়ার্ড কমপক্ষে ৮ অক্ষর।';

        // Duplicate check
        if (empty($errors)) {
            $dupCheck = $isEdit
                ? "SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?"
                : "SELECT id FROM users WHERE username = ? OR email = ?";
            $dupParams = $isEdit ? [$formData['username'], $formData['email'], $editId] : [$formData['username'], $formData['email']];
            if (Database::fetchOne($dupCheck, $dupParams)) $errors[] = 'এই ইউজারনেম বা ইমেইল আগেই ব্যবহার হচ্ছে।';
        }

        // Custom permissions JSON
        $grantedPerms  = $_POST['grant_perms']  ?? [];
        $revokedPerms  = $_POST['revoke_perms'] ?? [];
        $customPerms   = null;
        if (!empty($grantedPerms) || !empty($revokedPerms)) {
            $customPerms = json_encode([
                'grant'  => array_fill_keys($grantedPerms, true),
                'revoke' => array_fill_keys($revokedPerms, true),
            ], JSON_UNESCAPED_UNICODE);
        }

        if (empty($errors)) {
            $saveData = [
                'full_name'   => $formData['full_name'],
                'username'    => $formData['username'],
                'email'       => $formData['email'],
                'phone'       => $formData['phone'],
                'role_id'     => $formData['role_id'],
                'company_id'  => $formData['company_id'],
                'status'      => $formData['status'],
                'custom_perms'=> $customPerms,
            ];
            if (!empty($formData['password'])) {
                $saveData['password'] = password_hash($formData['password'], PASSWORD_BCRYPT, ['cost' => 12]);
            }

            if ($isEdit) {
                Database::update('users', $saveData, 'id = ?', [$editId]);
                logActivity('user.edit', 'users', ['target_id' => $editId, 'description' => $formData['full_name'] . ' আপডেট হয়েছে']);
                setFlash('success', 'ব্যবহারকারী আপডেট হয়েছে।');
            } else {
                $saveData['created_by'] = $user['id'];
                $newId = Database::insert('users', $saveData);
                logActivity('user.create', 'users', ['target_id' => $newId, 'description' => $formData['full_name'] . ' তৈরি হয়েছে']);
                setFlash('success', 'ব্যবহারকারী তৈরি হয়েছে।');
            }
            redirect($_SERVER['PHP_SELF']);
        }
        $mode = $isEdit ? 'edit' : 'add';
    }
}

// ---- Load user for editing ----
if ($mode === 'edit' && isset($_GET['id'])) {
    $editUser = Database::fetchOne("SELECT * FROM users WHERE id = ?", [(int)$_GET['id']]);
    if (!$editUser) { setFlash('error', 'ব্যবহারকারী পাওয়া যায়নি।'); redirect($_SERVER['PHP_SELF']); }
}

// ---- All permissions list ----
$allPermissions = Database::fetchAll("SELECT * FROM permissions ORDER BY module, action");
$permsByModule  = [];
foreach ($allPermissions as $p) {
    $permsByModule[$p['module']][] = $p;
}

// ---- User list filters ----
$search       = trim($_GET['q'] ?? '');
$roleFilter   = $_GET['role'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 20;

$where  = '1=1';
$params = [];
if ($search) {
    $where .= ' AND (u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
    $s = "%$search%"; array_push($params, $s, $s, $s, $s);
}
if ($roleFilter) { $where .= ' AND u.role_id = ?'; $params[] = $roleFilter; }
if ($statusFilter) { $where .= ' AND u.status = ?'; $params[] = $statusFilter; }

// Non-super-admins can only see own company users
if (!in_array($user['role_name'], ['super_admin','admin']) && $user['company_id']) {
    $where .= ' AND u.company_id = ?'; $params[] = $user['company_id'];
}

$total = Database::fetchOne("SELECT COUNT(*) as cnt FROM users u WHERE $where", $params)['cnt'];
$paging = paginate($total, $perPage, $page, $_SERVER['PHP_SELF'] . '?q=' . urlencode($search) . '&role=' . $roleFilter . '&status=' . $statusFilter . '&page=');

$users = Database::fetchAll(
    "SELECT u.*, r.label as role_label, r.name as role_name, c.company_name
     FROM users u
     JOIN roles r ON r.id = u.role_id
     LEFT JOIN companies c ON c.id = u.company_id
     WHERE $where
     ORDER BY u.created_at DESC
     LIMIT $perPage OFFSET {$paging['offset']}",
    $params
);

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div>
        <h1>ব্যবহারকারী ব্যবস্থাপনা</h1>
        <nav><ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= SITE_URL ?>/dashboard.php">হোম</a></li>
            <li class="breadcrumb-item active">ব্যবহারকারী</li>
        </ol></nav>
    </div>
    <?php if (hasPermission('users', 'create') && $mode === 'list'): ?>
    <a href="?mode=add" class="btn btn-primary"><i class="bi bi-person-plus me-1"></i>নতুন ব্যবহারকারী</a>
    <?php endif; ?>
</div>

<?php if ($mode === 'list'): ?>
<!-- ============================= USER LIST ============================= -->

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2 px-3">
        <form class="d-flex flex-wrap gap-2 align-items-center" method="GET">
            <input type="text" name="q" class="form-control form-control-sm" style="width:200px;border-radius:20px;"
                   placeholder="নাম / ইমেইল খুঁজুন..." value="<?= htmlspecialchars($search) ?>">
            <select name="role" class="form-select form-select-sm" style="width:160px;border-radius:20px;">
                <option value="">সব রোল</option>
                <?php foreach ($roles as $r): ?>
                <option value="<?= $r['id'] ?>" <?= $roleFilter == $r['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($r['label']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <select name="status" class="form-select form-select-sm" style="width:140px;border-radius:20px;">
                <option value="">সব স্ট্যাটাস</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>সক্রিয়</option>
                <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>নিষ্ক্রিয়</option>
                <option value="suspended" <?= $statusFilter === 'suspended' ? 'selected' : '' ?>>স্থগিত</option>
            </select>
            <button class="btn btn-sm btn-primary" style="border-radius:20px;"><i class="bi bi-search me-1"></i>খুঁজুন</button>
            <?php if ($search || $roleFilter || $statusFilter): ?>
            <a href="?" class="btn btn-sm btn-outline-secondary" style="border-radius:20px;"><i class="bi bi-x"></i> রিসেট</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card table-card">
    <div class="table-responsive">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>ব্যবহারকারী</th>
                    <th>ইউজারনেম</th>
                    <th>রোল</th>
                    <th>কোম্পানি</th>
                    <th>ফোন</th>
                    <th>শেষ লগইন</th>
                    <th>স্ট্যাটাস</th>
                    <th>অ্যাকশন</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($users)): ?>
                <tr><td colspan="8" class="text-center text-muted py-5">কোনো ব্যবহারকারী পাওয়া যায়নি</td></tr>
            <?php else: foreach ($users as $u): ?>
            <tr>
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <div style="width:38px;height:38px;border-radius:50%;background:<?= $u['status'] === 'active' ? '#1a3a5c' : '#94a3b8' ?>;
                                    color:#fff;display:flex;align-items:center;justify-content:center;
                                    font-weight:700;font-size:14px;flex-shrink:0;">
                            <?php if ($u['avatar']): ?>
                                <img src="<?= UPLOAD_URL . $u['avatar'] ?>" style="width:100%;height:100%;border-radius:50%;object-fit:cover;">
                            <?php else: ?>
                                <?= mb_substr($u['full_name'], 0, 1, 'UTF-8') ?>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="fw-semibold" style="font-size:14px;"><?= htmlspecialchars($u['full_name']) ?></div>
                            <div class="text-muted small"><?= htmlspecialchars($u['email']) ?></div>
                        </div>
                    </div>
                </td>
                <td><code style="font-size:12px;background:#f1f5f9;padding:2px 6px;border-radius:4px;"><?= htmlspecialchars($u['username']) ?></code></td>
                <td>
                    <span class="badge rounded-pill"
                          style="background:<?= match($u['role_name']) {
                              'super_admin' => '#0f2238', 'admin' => '#1a3a5c',
                              'company_admin' => '#2563a8', default => '#64748b'
                          } ?>;font-size:11px;">
                        <?= htmlspecialchars($u['role_label']) ?>
                    </span>
                </td>
                <td><small><?= $u['company_name'] ? htmlspecialchars($u['company_name']) : '<span class="text-muted">—</span>' ?></small></td>
                <td><small><?= htmlspecialchars($u['phone']) ?></small></td>
                <td><small class="text-muted"><?= $u['last_login'] ? timeAgo($u['last_login']) : 'কখনো না' ?></small></td>
                <td>
                    <span class="badge bg-<?= getBadgeClass($u['status']) ?> rounded-pill" style="font-size:11px;">
                        <?= match($u['status']) { 'active' => 'সক্রিয়', 'inactive' => 'নিষ্ক্রিয়', 'suspended' => 'স্থগিত', default => $u['status'] } ?>
                    </span>
                </td>
                <td>
                    <div class="d-flex gap-1">
                        <?php if (hasPermission('users', 'edit') && $u['id'] !== $user['id'] && $u['role_name'] !== 'super_admin'): ?>
                        <a href="?mode=edit&id=<?= $u['id'] ?>" class="btn btn-sm btn-outline-primary" style="border-radius:6px;padding:3px 8px;" title="সম্পাদনা">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <?php endif; ?>

                        <?php if ($u['id'] !== $user['id'] && $u['role_name'] !== 'super_admin'): ?>
                        <form method="POST" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <?php if ($u['status'] === 'active'): ?>
                            <input type="hidden" name="status" value="suspended">
                            <button type="submit" class="btn btn-sm btn-outline-warning" style="border-radius:6px;padding:3px 8px;" title="স্থগিত"
                                    onclick="return confirm('এই ব্যবহারকারীকে স্থগিত করবেন?')">
                                <i class="bi bi-pause-circle"></i>
                            </button>
                            <?php else: ?>
                            <input type="hidden" name="status" value="active">
                            <button type="submit" class="btn btn-sm btn-outline-success" style="border-radius:6px;padding:3px 8px;" title="সক্রিয়">
                                <i class="bi bi-play-circle"></i>
                            </button>
                            <?php endif; ?>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($paging['total_pages'] > 1): ?>
    <div class="card-footer bg-white d-flex align-items-center justify-content-between flex-wrap gap-2">
        <small class="text-muted">মোট <?= $paging['total'] ?> জন</small>
        <nav><ul class="pagination pagination-sm mb-0">
            <?php if ($paging['has_prev']): ?>
            <li class="page-item"><a class="page-link" href="<?= $paging['base_url'].$paging['current']-1 ?>">‹</a></li>
            <?php endif; ?>
            <?php for ($p=max(1,$paging['current']-2); $p<=min($paging['total_pages'],$paging['current']+2); $p++): ?>
            <li class="page-item <?= $p===$paging['current']?'active':'' ?>">
                <a class="page-link" href="<?= $paging['base_url'].$p ?>"><?= $p ?></a>
            </li>
            <?php endfor; ?>
            <?php if ($paging['has_next']): ?>
            <li class="page-item"><a class="page-link" href="<?= $paging['base_url'].$paging['current']+1 ?>">›</a></li>
            <?php endif; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>

<?php else: ?>
<!-- ============================= ADD / EDIT FORM ============================= -->

<?php
$fd = $editUser ?? [];
$editCustomPerms = [];
if (!empty($fd['custom_perms'])) {
    $editCustomPerms = json_decode($fd['custom_perms'], true) ?? [];
}
// Get role permissions for the selected role
$rolePermsQuery = !empty($fd['role_id']) ? Database::fetchAll(
    "SELECT p.module, p.action FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?",
    [$fd['role_id']]
) : [];
$rolePermSet = [];
foreach ($rolePermsQuery as $rp) $rolePermSet[$rp['module'].'.'.$rp['action']] = true;
?>

<div class="row justify-content-center">
<div class="col-lg-10">
<div class="card">
    <div class="card-header justify-content-between">
        <h6 class="card-title">
            <i class="bi bi-person-<?= $mode === 'edit' ? 'gear' : 'plus' ?> me-2"></i>
            <?= $mode === 'edit' ? 'ব্যবহারকারী সম্পাদনা' : 'নতুন ব্যবহারকারী' ?>
        </h6>
        <a href="?" class="btn btn-sm btn-outline-secondary" style="border-radius:6px;">
            <i class="bi bi-arrow-left me-1"></i>তালিকায় ফিরুন
        </a>
    </div>
    <div class="card-body p-4">

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger rounded-3 mb-4" style="font-size:13px;">
            <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
        </div>
        <?php endif; ?>

        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="<?= $mode === 'edit' ? 'edit_user' : 'add_user' ?>">
            <?php if ($mode === 'edit'): ?>
            <input type="hidden" name="edit_id" value="<?= $editUser['id'] ?>">
            <?php endif; ?>

            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label">পূর্ণ নাম <span style="color:#e8392d;">*</span></label>
                    <input type="text" name="full_name" class="form-control"
                           value="<?= htmlspecialchars($fd['full_name'] ?? '') ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">ইউজারনেম <span style="color:#e8392d;">*</span></label>
                    <input type="text" name="username" class="form-control"
                           value="<?= htmlspecialchars($fd['username'] ?? '') ?>"
                           <?= $mode === 'edit' && isset($fd['role_name']) && $fd['role_name'] === 'super_admin' ? 'readonly' : '' ?>
                           required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">ইমেইল <span style="color:#e8392d;">*</span></label>
                    <input type="email" name="email" class="form-control"
                           value="<?= htmlspecialchars($fd['email'] ?? '') ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">মোবাইল নম্বর</label>
                    <input type="text" name="phone" class="form-control"
                           value="<?= htmlspecialchars($fd['phone'] ?? '') ?>"
                           placeholder="01XXXXXXXXX">
                </div>
                <div class="col-md-4">
                    <label class="form-label">রোল <span style="color:#e8392d;">*</span></label>
                    <select name="role_id" id="roleSelect" class="form-select" required onchange="updateRolePerms(this.value)">
                        <option value="">— রোল নির্বাচন করুন —</option>
                        <?php foreach ($roles as $r):
                            if ($r['name'] === 'super_admin' && $user['role_name'] !== 'super_admin') continue; ?>
                        <option value="<?= $r['id'] ?>" <?= ($fd['role_id'] ?? '') == $r['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($r['label']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">কোম্পানি</label>
                    <select name="company_id" class="form-select">
                        <option value="">— সিস্টেম ব্যবহারকারী —</option>
                        <?php foreach ($companies as $co): ?>
                        <option value="<?= $co['id'] ?>" <?= ($fd['company_id'] ?? '') == $co['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($co['company_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">স্ট্যাটাস</label>
                    <select name="status" class="form-select">
                        <option value="active" <?= ($fd['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>সক্রিয়</option>
                        <option value="inactive" <?= ($fd['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>নিষ্ক্রিয়</option>
                        <option value="suspended" <?= ($fd['status'] ?? '') === 'suspended' ? 'selected' : '' ?>>স্থগিত</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?= $mode === 'edit' ? 'নতুন পাসওয়ার্ড' : 'পাসওয়ার্ড' ?> <?= $mode === 'add' ? '<span style="color:#e8392d;">*</span>' : '' ?></label>
                    <input type="password" name="password" class="form-control"
                           placeholder="<?= $mode === 'edit' ? 'পরিবর্তন না করলে ফাঁকা রাখুন' : 'কমপক্ষে ৮ অক্ষর' ?>"
                           <?= $mode === 'add' ? 'required minlength="8"' : '' ?>>
                </div>
            </div>

            <!-- ===== PERMISSIONS SECTION ===== -->
            <div style="border:2px solid #e2e8f0;border-radius:12px;padding:20px;background:#fafbfc;">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <div class="fw-bold" style="color:#1a3a5c;"><i class="bi bi-shield-lock me-2"></i>কাস্টম পারমিশন</div>
                    <small class="text-muted">রোলের ডিফল্ট পারমিশনের উপরে অতিরিক্ত বা বাদ দেওয়া পারমিশন</small>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm table-bordered" style="font-size:13px;">
                        <thead style="background:#f1f5f9;">
                            <tr>
                                <th style="width:160px;">মডিউল / পারমিশন</th>
                                <th class="text-center" style="width:90px;">রোলে আছে</th>
                                <th class="text-center" style="width:90px;">অতিরিক্ত দিন</th>
                                <th class="text-center" style="width:90px;">বাদ দিন</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($permsByModule as $module => $perms): ?>
                            <tr style="background:#f8fafc;">
                                <td colspan="4" class="fw-semibold text-uppercase" style="font-size:11px;color:#64748b;letter-spacing:.8px;">
                                    <?= htmlspecialchars($module) ?>
                                </td>
                            </tr>
                            <?php foreach ($perms as $p):
                                $key = $p['module'] . '.' . $p['action'];
                                $inRole   = isset($rolePermSet[$key]);
                                $granted  = isset($editCustomPerms['grant'][$key]);
                                $revoked  = isset($editCustomPerms['revoke'][$key]);
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($p['label']) ?></td>
                                <td class="text-center">
                                    <?php if ($inRole): ?>
                                        <i class="bi bi-check-circle-fill text-success"></i>
                                    <?php else: ?>
                                        <i class="bi bi-dash-circle text-muted"></i>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if (!$inRole): ?>
                                    <input type="checkbox" name="grant_perms[]" value="<?= $key ?>"
                                           class="form-check-input perm-grant" data-key="<?= $key ?>"
                                           <?= $granted ? 'checked' : '' ?>>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($inRole): ?>
                                    <input type="checkbox" name="revoke_perms[]" value="<?= $key ?>"
                                           class="form-check-input perm-revoke" data-key="<?= $key ?>"
                                           <?= $revoked ? 'checked' : '' ?>>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="d-flex justify-content-between mt-4">
                <a href="?" class="btn btn-outline-secondary">বাতিল</a>
                <button type="submit" class="btn btn-primary px-4">
                    <i class="bi bi-check-lg me-1"></i>সংরক্ষণ করুন
                </button>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<script>
// All permissions data from PHP
const allRolePerms = <?= json_encode(
    array_reduce(
        Database::fetchAll("SELECT rp.role_id, p.module, p.action FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id"),
        function($carry, $item) {
            $carry[$item['role_id']][$item['module'].'.'.$item['action']] = true;
            return $carry;
        }, []
    ), JSON_PRETTY_PRINT
) ?>;

function updateRolePerms(roleId) {
    const perms = allRolePerms[roleId] || {};
    document.querySelectorAll('tr[data-key]').forEach(row => {
        const key = row.dataset.key;
        const hasRole = !!perms[key];
        row.querySelector('.role-indicator').innerHTML = hasRole
            ? '<i class="bi bi-check-circle-fill text-success"></i>'
            : '<i class="bi bi-dash-circle text-muted"></i>';
    });
}
</script>

<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
