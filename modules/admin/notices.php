<?php
// ============================================================
// NOTICE MANAGEMENT — B-4.1 + B-4.3 + B-4.4
// File: modules/admin/notices.php
// ============================================================
require_once __DIR__ . '/../../includes/functions.php';
requirePermission('companies', 'view'); // Admin+ only

$user      = getCurrentUser();
$errors    = [];
$pageTitle = 'নোটিশ ব্যবস্থাপনা';

$typeConfig = [
    'general'     => ['label'=>'সাধারণ ঘোষণা',    'icon'=>'megaphone',       'color'=>'#2563a8', 'bg'=>'#eff6ff'],
    'meeting'     => ['label'=>'মিটিং / কল',        'icon'=>'camera-video',    'color'=>'#7c3aed', 'bg'=>'#f5f3ff'],
    'urgent'      => ['label'=>'জরুরি সতর্কতা',     'icon'=>'exclamation-triangle', 'color'=>'#dc2626', 'bg'=>'#fef2f2'],
    'deadline'    => ['label'=>'পেমেন্ট ডেডলাইন',   'icon'=>'calendar-event',  'color'=>'#d97706', 'bg'=>'#fffbeb'],
    'maintenance' => ['label'=>'রক্ষণাবেক্ষণ',       'icon'=>'tools',           'color'=>'#64748b', 'bg'=>'#f8fafc'],
];

$priorityConfig = [
    'normal'    => ['label'=>'সাধারণ',   'color'=>'#64748b', 'badge'=>'secondary'],
    'important' => ['label'=>'গুরুত্বপূর্ণ', 'color'=>'#d97706', 'badge'=>'warning'],
    'urgent'    => ['label'=>'জরুরি',    'color'=>'#dc2626', 'badge'=>'danger'],
];

// ── নোটিশ তৈরি ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_notice'])) {
    if (!validateCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $errors[] = 'নিরাপত্তা যাচাই ব্যর্থ।';
    } else {
        $title     = trim($_POST['title'] ?? '');
        $message   = trim($_POST['message'] ?? '');
        $type      = $_POST['type'] ?? 'general';
        $priority  = $_POST['priority'] ?? 'normal';
        $target    = $_POST['target'] ?? 'all';
        $targetId  = ($target !== 'all') ? (int)($_POST['target_id'] ?? 0) : null;
        $startsAt  = $_POST['starts_at'] ?? date('Y-m-d H:i:s');
        $expiresAt = $_POST['expires_at'] ? $_POST['expires_at'] : null;
        $editId    = (int)($_POST['edit_id'] ?? 0);

        if (empty($title))   $errors[] = 'শিরোনাম দিন।';
        if (empty($message)) $errors[] = 'বার্তা লিখুন।';
        if ($target !== 'all' && !$targetId) $errors[] = 'টার্গেট নির্বাচন করুন।';

        if (empty($errors)) {
            $data = [
                'title'      => $title,
                'message'    => $message,
                'type'       => $type,
                'priority'   => $priority,
                'target'     => $target,
                'target_id'  => $targetId,
                'starts_at'  => $startsAt,
                'expires_at' => $expiresAt,
                'is_active'  => 1,
            ];
            if ($editId) {
                Database::update('notices', $data, 'id = ?', [$editId]);
                setFlash('success', 'নোটিশ আপডেট হয়েছে।');
            } else {
                $data['created_by'] = $user['id'];
                Database::insert('notices', $data);
                setFlash('success', 'নোটিশ তৈরি হয়েছে।');
            }
            redirect($_SERVER['PHP_SELF']);
        }
    }
}

// ── নোটিশ টগল (active/inactive) ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_notice'])) {
    if (validateCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $nid    = (int)($_POST['notice_id'] ?? 0);
        $notice = Database::fetchOne("SELECT is_active FROM notices WHERE id = ?", [$nid]);
        if ($notice) {
            Database::update('notices', ['is_active' => $notice['is_active'] ? 0 : 1], 'id = ?', [$nid]);
        }
        redirect($_SERVER['PHP_SELF']);
    }
}

// ── নোটিশ মুছুন ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_notice'])) {
    if (validateCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $nid = (int)($_POST['notice_id'] ?? 0);
        Database::query("DELETE FROM notices WHERE id = ?", [$nid]);
        Database::query("DELETE FROM notice_dismissals WHERE notice_id = ?", [$nid]);
        setFlash('success', 'নোটিশ মুছে ফেলা হয়েছে।');
        redirect($_SERVER['PHP_SELF']);
    }
}

// ── Edit লোড ─────────────────────────────────────────────────
$editNotice = null;
if (isset($_GET['edit'])) {
    $editNotice = Database::fetchOne("SELECT * FROM notices WHERE id = ?", [(int)$_GET['edit']]);
}

// ── সব নোটিশ ─────────────────────────────────────────────────
$notices = Database::fetchAll(
    "SELECT n.*, u.full_name as creator_name,
            CASE WHEN n.expires_at IS NOT NULL AND n.expires_at < NOW() THEN 1 ELSE 0 END as is_expired
     FROM notices n
     JOIN users u ON u.id = n.created_by
     ORDER BY n.created_at DESC"
);

// ── কোম্পানি ও রোল তালিকা (target select এর জন্য) ─────────────
$companies = Database::fetchAll("SELECT id, company_name FROM companies WHERE status='approved' ORDER BY company_name");
$roles     = Database::fetchAll("SELECT id, name FROM roles ORDER BY id");

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-megaphone me-2"></i>নোটিশ ব্যবস্থাপনা</h1>
        <nav><ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= SITE_URL ?>/dashboard.php">হোম</a></li>
            <li class="breadcrumb-item active">নোটিশ</li>
        </ol></nav>
    </div>
    <button class="btn btn-primary" onclick="document.getElementById('noticeForm').scrollIntoView({behavior:'smooth'})">
        <i class="bi bi-plus-circle me-1"></i>নতুন নোটিশ
    </button>
</div>

<!-- ===== NOTICE LIST ===== -->
<div class="card mb-4">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="card-title mb-0"><i class="bi bi-list-ul me-2"></i>সব নোটিশ</h6>
        <span class="badge bg-secondary rounded-pill"><?= count($notices) ?></span>
    </div>
    <?php if (empty($notices)): ?>
    <div class="card-body text-center py-5 text-muted">
        <i class="bi bi-megaphone fs-1 d-block mb-2"></i>
        কোনো নোটিশ নেই। নিচ থেকে তৈরি করুন।
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover mb-0" style="font-size:13px;">
            <thead class="table-light">
                <tr>
                    <th>শিরোনাম ও বার্তা</th>
                    <th>ধরন</th>
                    <th>অগ্রাধিকার</th>
                    <th>টার্গেট</th>
                    <th>মেয়াদ</th>
                    <th>স্ট্যাটাস</th>
                    <th>একশন</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($notices as $n):
                $tc = $typeConfig[$n['type']] ?? $typeConfig['general'];
                $pc = $priorityConfig[$n['priority']] ?? $priorityConfig['normal'];
                $expired = $n['is_expired'];
                $active  = $n['is_active'] && !$expired;
            ?>
            <tr style="<?= !$active ? 'opacity:.6;' : '' ?>">
                <td style="max-width:260px;">
                    <div class="fw-semibold"><?= htmlspecialchars($n['title']) ?></div>
                    <div class="text-muted text-truncate" style="font-size:12px;max-width:240px;">
                        <?= htmlspecialchars(mb_substr($n['message'], 0, 60, 'UTF-8')) ?>...
                    </div>
                    <div style="font-size:11px;color:#94a3b8;margin-top:2px;">
                        <?= htmlspecialchars($n['creator_name']) ?> · <?= timeAgo($n['created_at']) ?>
                    </div>
                </td>
                <td>
                    <span class="d-flex align-items-center gap-1" style="color:<?= $tc['color'] ?>;font-size:12px;font-weight:600;">
                        <i class="bi bi-<?= $tc['icon'] ?>"></i><?= $tc['label'] ?>
                    </span>
                </td>
                <td>
                    <span class="badge bg-<?= $pc['badge'] ?> rounded-pill"><?= $pc['label'] ?></span>
                </td>
                <td style="font-size:12px;">
                    <?php if ($n['target'] === 'all'): ?>
                    <span class="badge bg-primary rounded-pill">সবাই</span>
                    <?php elseif ($n['target'] === 'company'): ?>
                    <span class="badge bg-info rounded-pill">নির্দিষ্ট কোম্পানি</span>
                    <?php else: ?>
                    <span class="badge bg-secondary rounded-pill">নির্দিষ্ট রোল</span>
                    <?php endif; ?>
                </td>
                <td style="font-size:11px;color:#64748b;">
                    <div>শুরু: <?= date('d M Y', strtotime($n['starts_at'])) ?></div>
                    <?php if ($n['expires_at']): ?>
                    <div style="color:<?= $expired ? '#dc2626' : '#d97706' ?>;">
                        শেষ: <?= date('d M Y', strtotime($n['expires_at'])) ?>
                        <?= $expired ? ' (মেয়াদোত্তীর্ণ)' : '' ?>
                    </div>
                    <?php else: ?>
                    <div>মেয়াদ নেই</div>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($expired): ?>
                    <span class="badge bg-secondary">মেয়াদোত্তীর্ণ</span>
                    <?php elseif ($active): ?>
                    <span class="badge bg-success">সক্রিয়</span>
                    <?php else: ?>
                    <span class="badge bg-secondary">বন্ধ</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="d-flex gap-1">
                        <!-- Edit -->
                        <a href="?edit=<?= $n['id'] ?>" class="btn btn-sm btn-outline-primary"
                           style="border-radius:6px;padding:3px 8px;" title="সম্পাদনা">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <!-- Toggle -->
                        <form method="POST" style="display:inline;">
                            <?= csrfField() ?>
                            <input type="hidden" name="toggle_notice" value="1">
                            <input type="hidden" name="notice_id" value="<?= $n['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-<?= $n['is_active'] ? 'warning' : 'success' ?>"
                                    style="border-radius:6px;padding:3px 8px;"
                                    title="<?= $n['is_active'] ? 'বন্ধ করুন' : 'চালু করুন' ?>">
                                <i class="bi bi-<?= $n['is_active'] ? 'pause' : 'play' ?>"></i>
                            </button>
                        </form>
                        <!-- Delete -->
                        <form method="POST" style="display:inline;"
                              onsubmit="return confirm('এই নোটিশ মুছবেন?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="delete_notice" value="1">
                            <input type="hidden" name="notice_id" value="<?= $n['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"
                                    style="border-radius:6px;padding:3px 8px;" title="মুছুন">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ===== CREATE / EDIT FORM ===== -->
<div class="card" id="noticeForm">
    <div class="card-header">
        <h6 class="card-title mb-0">
            <i class="bi bi-<?= $editNotice ? 'pencil' : 'plus-circle' ?> me-2 text-<?= $editNotice ? 'warning' : 'success' ?>"></i>
            <?= $editNotice ? 'নোটিশ সম্পাদনা' : 'নতুন নোটিশ তৈরি' ?>
        </h6>
    </div>
    <div class="card-body">
        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger rounded-3 mb-3" style="font-size:13px;">
            <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
        </div>
        <?php endif; ?>

        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="save_notice" value="1">
            <input type="hidden" name="edit_id" value="<?= $editNotice['id'] ?? 0 ?>">

            <div class="row g-3">
                <!-- শিরোনাম -->
                <div class="col-12">
                    <label class="form-label fw-semibold">শিরোনাম <span class="required-star">*</span></label>
                    <input type="text" name="title" class="form-control"
                           placeholder="নোটিশের শিরোনাম লিখুন..."
                           value="<?= htmlspecialchars($editNotice['title'] ?? '') ?>" required>
                </div>

                <!-- বার্তা -->
                <div class="col-12">
                    <label class="form-label fw-semibold">বার্তা <span class="required-star">*</span></label>
                    <textarea name="message" class="form-control" rows="3"
                              placeholder="নোটিশের বিস্তারিত বার্তা লিখুন..." required><?= htmlspecialchars($editNotice['message'] ?? '') ?></textarea>
                </div>

                <!-- ধরন -->
                <div class="col-md-4">
                    <label class="form-label fw-semibold">নোটিশের ধরন</label>
                    <div class="row g-2">
                        <?php foreach ($typeConfig as $tv => $tc): ?>
                        <div class="col-12">
                            <label class="type-opt d-flex align-items-center gap-2 p-2 rounded-3"
                                   style="cursor:pointer;border:2px solid <?= ($editNotice['type'] ?? 'general') === $tv ? $tc['color'] : '#e2e8f0' ?>;
                                          background:<?= ($editNotice['type'] ?? 'general') === $tv ? $tc['bg'] : '#fff' ?>;">
                                <input type="radio" name="type" value="<?= $tv ?>"
                                       <?= ($editNotice['type'] ?? 'general') === $tv ? 'checked' : '' ?>
                                       style="display:none;" onchange="pickType(this)">
                                <i class="bi bi-<?= $tc['icon'] ?>" style="color:<?= $tc['color'] ?>;font-size:16px;"></i>
                                <span style="font-size:12px;font-weight:600;color:<?= $tc['color'] ?>;"><?= $tc['label'] ?></span>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="col-md-8">
                    <div class="row g-3">
                        <!-- অগ্রাধিকার -->
                        <div class="col-12">
                            <label class="form-label fw-semibold">অগ্রাধিকার</label>
                            <div class="d-flex gap-2">
                                <?php foreach ($priorityConfig as $pv => $pc): ?>
                                <label class="priority-opt flex-fill text-center p-2 rounded-3"
                                       style="cursor:pointer;border:2px solid <?= ($editNotice['priority'] ?? 'normal') === $pv ? $pc['color'] : '#e2e8f0' ?>;
                                              background:<?= ($editNotice['priority'] ?? 'normal') === $pv ? '#f8fafc' : '#fff' ?>;">
                                    <input type="radio" name="priority" value="<?= $pv ?>"
                                           <?= ($editNotice['priority'] ?? 'normal') === $pv ? 'checked' : '' ?>
                                           style="display:none;" onchange="pickPriority(this)">
                                    <div style="font-size:12px;font-weight:600;color:<?= $pc['color'] ?>;"><?= $pc['label'] ?></div>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- টার্গেট -->
                        <div class="col-12">
                            <label class="form-label fw-semibold">টার্গেট — কাকে দেখাবে?</label>
                            <select name="target" class="form-select" onchange="toggleTarget(this.value)">
                                <option value="all" <?= ($editNotice['target'] ?? 'all') === 'all' ? 'selected' : '' ?>>সব কোম্পানি ও ইউজার</option>
                                <option value="company" <?= ($editNotice['target'] ?? '') === 'company' ? 'selected' : '' ?>>নির্দিষ্ট কোম্পানি</option>
                                <option value="role" <?= ($editNotice['target'] ?? '') === 'role' ? 'selected' : '' ?>>নির্দিষ্ট রোল</option>
                            </select>
                        </div>

                        <!-- Target ID -->
                        <div class="col-12" id="targetCompanyWrap"
                             style="display:<?= ($editNotice['target'] ?? 'all') === 'company' ? 'block' : 'none' ?>;">
                            <label class="form-label fw-semibold">কোম্পানি নির্বাচন করুন</label>
                            <select name="target_id" id="targetCompanySelect" class="form-select">
                                <option value="">-- কোম্পানি বেছে নিন --</option>
                                <?php foreach ($companies as $co): ?>
                                <option value="<?= $co['id'] ?>"
                                    <?= ($editNotice['target_id'] ?? 0) == $co['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($co['company_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12" id="targetRoleWrap"
                             style="display:<?= ($editNotice['target'] ?? 'all') === 'role' ? 'block' : 'none' ?>;">
                            <label class="form-label fw-semibold">রোল নির্বাচন করুন</label>
                            <select name="target_id" id="targetRoleSelect" class="form-select">
                                <option value="">-- রোল বেছে নিন --</option>
                                <?php foreach ($roles as $r): ?>
                                <option value="<?= $r['id'] ?>"
                                    <?= ($editNotice['target_id'] ?? 0) == $r['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($r['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- তারিখ -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">শুরুর তারিখ ও সময়</label>
                            <input type="datetime-local" name="starts_at" class="form-control"
                                   value="<?= $editNotice ? date('Y-m-d\TH:i', strtotime($editNotice['starts_at'])) : date('Y-m-d\TH:i') ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">শেষের তারিখ (ঐচ্ছিক)</label>
                            <input type="datetime-local" name="expires_at" class="form-control"
                                   value="<?= ($editNotice && $editNotice['expires_at']) ? date('Y-m-d\TH:i', strtotime($editNotice['expires_at'])) : '' ?>">
                            <div class="form-text">খালি রাখলে মেয়াদ থাকবে না</div>
                        </div>
                    </div>
                </div>

                <!-- Submit -->
                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-success fw-semibold px-4">
                        <i class="bi bi-<?= $editNotice ? 'check-lg' : 'plus-circle' ?> me-1"></i>
                        <?= $editNotice ? 'আপডেট করুন' : 'নোটিশ তৈরি করুন' ?>
                    </button>
                    <?php if ($editNotice): ?>
                    <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-outline-secondary">বাতিল</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function toggleTarget(val) {
    document.getElementById('targetCompanyWrap').style.display = val === 'company' ? 'block' : 'none';
    document.getElementById('targetRoleWrap').style.display    = val === 'role'    ? 'block' : 'none';
}

const typeColors = <?= json_encode(array_map(fn($t) => ['color'=>$t['color'],'bg'=>$t['bg']], $typeConfig)) ?>;
function pickType(radio) {
    document.querySelectorAll('.type-opt').forEach(el => {
        const r = el.querySelector('input[type=radio]');
        const c = typeColors[r.value];
        const sel = r.value === radio.value;
        el.style.borderColor = sel ? c.color : '#e2e8f0';
        el.style.background  = sel ? c.bg : '#fff';
    });
}

const priorityColors = <?= json_encode(array_map(fn($p) => $p['color'], $priorityConfig)) ?>;
function pickPriority(radio) {
    document.querySelectorAll('.priority-opt').forEach(el => {
        const r = el.querySelector('input[type=radio]');
        const sel = r.value === radio.value;
        el.style.borderColor = sel ? (priorityColors[r.value] || '#e2e8f0') : '#e2e8f0';
        el.style.background  = sel ? '#f8fafc' : '#fff';
    });
}

// Click on label
document.querySelectorAll('.type-opt').forEach(el => {
    el.addEventListener('click', () => {
        const r = el.querySelector('input[type=radio]');
        r.checked = true; pickType(r);
    });
});
document.querySelectorAll('.priority-opt').forEach(el => {
    el.addEventListener('click', () => {
        const r = el.querySelector('input[type=radio]');
        r.checked = true; pickPriority(r);
    });
});

<?php if ($editNotice): ?>
document.getElementById('noticeForm').scrollIntoView({behavior:'smooth'});
<?php endif; ?>
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
