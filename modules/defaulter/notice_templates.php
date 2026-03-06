<?php
// ============================================================
// NOTICE TEMPLATE EDITOR — C-1.2
// File: modules/defaulter/notice_templates.php
// ============================================================
require_once __DIR__ . '/../../includes/functions.php';
requirePermission('defaulters', 'view');

$user      = getCurrentUser();
$errors    = [];
$pageTitle = 'নোটিশ টেমপ্লেট';

$typeConfig = [
    'warning'      => ['label' => 'সতর্কতামূলক',  'color' => '#d97706', 'icon' => 'exclamation-triangle'],
    'final_notice' => ['label' => 'চূড়ান্ত নোটিশ', 'color' => '#dc2626', 'icon' => 'exclamation-octagon'],
    'legal'        => ['label' => 'আইনি নোটিশ',   'color' => '#7c3aed', 'icon' => 'briefcase'],
    'court'        => ['label' => 'আদালত নোটিশ',  'color' => '#0f2238', 'icon' => 'bank'],
];

$placeholders = [
    '{{customer_name}}'   => 'গ্রাহকের নাম',
    '{{customer_phone}}'  => 'গ্রাহকের ফোন',
    '{{nid_number}}'      => 'NID নম্বর',
    '{{due_amount}}'      => 'বকেয়া টাকা',
    '{{service_period}}'  => 'সেবার মেয়াদ',
    '{{address}}'         => 'ঠিকানা',
    '{{company_name}}'    => 'কোম্পানির নাম',
    '{{company_phone}}'   => 'কোম্পানির ফোন',
    '{{company_address}}' => 'কোম্পানির ঠিকানা',
    '{{notice_date}}'     => 'নোটিশের তারিখ',
    '{{notice_number}}'   => 'নোটিশ নম্বর',
];

// ── Save Template ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_template'])) {
    if (!validateCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $errors[] = 'নিরাপত্তা যাচাই ব্যর্থ।';
    } else {
        $name    = trim($_POST['name'] ?? '');
        $type    = $_POST['type'] ?? 'warning';
        $subject = trim($_POST['subject'] ?? '');
        $body    = trim($_POST['body'] ?? '');
        $editId  = (int)($_POST['edit_id'] ?? 0);

        if (empty($name))    $errors[] = 'টেমপ্লেটের নাম দিন।';
        if (empty($subject)) $errors[] = 'বিষয় লিখুন।';
        if (empty($body))    $errors[] = 'বার্তা লিখুন।';

        if (empty($errors)) {
            $data = [
                'name'       => $name,
                'type'       => $type,
                'subject'    => $subject,
                'body'       => $body,
                'company_id' => isSuperAdmin() ? null : $user['company_id'],
            ];
            if ($editId) {
                Database::update('notice_templates', $data, 'id = ?', [$editId]);
                setFlash('success', 'টেমপ্লেট আপডেট হয়েছে।');
            } else {
                $data['created_by'] = $user['id'];
                Database::insert('notice_templates', $data);
                setFlash('success', 'টেমপ্লেট তৈরি হয়েছে।');
            }
            redirect($_SERVER['PHP_SELF']);
        }
    }
}

// ── Delete Template ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_template'])) {
    if (validateCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $tid = (int)($_POST['template_id'] ?? 0);
        // শুধু নিজের কোম্পানির বা সুপার অ্যাডমিন মুছতে পারবে
        $tpl = Database::fetchOne("SELECT * FROM notice_templates WHERE id = ?", [$tid]);
        if ($tpl && (isSuperAdmin() || $tpl['company_id'] == $user['company_id'])) {
            Database::query("DELETE FROM notice_templates WHERE id = ?", [$tid]);
            setFlash('success', 'টেমপ্লেট মুছে ফেলা হয়েছে।');
        }
        redirect($_SERVER['PHP_SELF']);
    }
}

// ── Edit লোড ─────────────────────────────────────────────────
$editTpl = null;
if (isset($_GET['edit'])) {
    $editTpl = Database::fetchOne("SELECT * FROM notice_templates WHERE id = ?", [(int)$_GET['edit']]);
}

// ── সব টেমপ্লেট ──────────────────────────────────────────────
$templates = Database::fetchAll(
    "SELECT t.*, u.full_name as creator_name,
            CASE WHEN t.company_id IS NULL THEN 'সিস্টেম' ELSE c.company_name END as owner_name
     FROM notice_templates t
     JOIN users u ON u.id = t.created_by
     LEFT JOIN companies c ON c.id = t.company_id
     WHERE t.company_id IS NULL OR t.company_id = ?
     ORDER BY t.is_default DESC, t.type, t.name",
    [$user['company_id']]
);

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-file-text me-2"></i>নোটিশ টেমপ্লেট</h1>
        <nav><ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="list.php">বকেয়া তালিকা</a></li>
            <li class="breadcrumb-item active">টেমপ্লেট</li>
        </ol></nav>
    </div>
    <button class="btn btn-primary" onclick="document.getElementById('tplForm').scrollIntoView({behavior:'smooth'})">
        <i class="bi bi-plus-circle me-1"></i>নতুন টেমপ্লেট
    </button>
</div>

<div class="row g-4">
<!-- ===== LEFT: Template List ===== -->
<div class="col-lg-5">
    <?php foreach ($typeConfig as $typeKey => $tc):
        $group = array_filter($templates, fn($t) => $t['type'] === $typeKey);
        if (empty($group)) continue;
    ?>
    <div class="card mb-3">
        <div class="card-header d-flex align-items-center gap-2 py-2">
            <i class="bi bi-<?= $tc['icon'] ?>" style="color:<?= $tc['color'] ?>;"></i>
            <h6 class="mb-0 fw-bold" style="color:<?= $tc['color'] ?>;font-size:13px;"><?= $tc['label'] ?></h6>
            <span class="badge rounded-pill ms-auto" style="background:<?= $tc['color'] ?>;"><?= count($group) ?></span>
        </div>
        <div class="list-group list-group-flush">
        <?php foreach ($group as $t): ?>
        <div class="list-group-item px-3 py-2">
            <div class="d-flex align-items-start justify-content-between gap-2">
                <div style="flex:1;min-width:0;">
                    <div class="fw-semibold" style="font-size:13px;">
                        <?= htmlspecialchars($t['name']) ?>
                        <?php if ($t['is_default']): ?>
                        <span class="badge bg-secondary rounded-pill ms-1" style="font-size:10px;">ডিফল্ট</span>
                        <?php endif; ?>
                        <?php if (!$t['company_id']): ?>
                        <span class="badge bg-primary rounded-pill ms-1" style="font-size:10px;">সিস্টেম</span>
                        <?php endif; ?>
                    </div>
                    <div class="text-muted text-truncate" style="font-size:11px;max-width:220px;">
                        <?= htmlspecialchars($t['subject']) ?>
                    </div>
                </div>
                <div class="d-flex gap-1 flex-shrink-0">
                    <a href="?edit=<?= $t['id'] ?>" class="btn btn-sm btn-outline-primary"
                       style="padding:2px 7px;border-radius:6px;" title="সম্পাদনা">
                        <i class="bi bi-pencil" style="font-size:11px;"></i>
                    </a>
                    <?php if ($t['company_id'] || isSuperAdmin()): ?>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('টেমপ্লেট মুছবেন?')">
                        <?= csrfField() ?>
                        <input type="hidden" name="delete_template" value="1">
                        <input type="hidden" name="template_id" value="<?= $t['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger"
                                style="padding:2px 7px;border-radius:6px;" title="মুছুন">
                            <i class="bi bi-trash" style="font-size:11px;"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ===== RIGHT: Form ===== -->
<div class="col-lg-7">
<div class="card" id="tplForm">
    <div class="card-header">
        <h6 class="card-title mb-0">
            <i class="bi bi-<?= $editTpl ? 'pencil' : 'plus-circle' ?> me-2 text-<?= $editTpl ? 'warning' : 'success' ?>"></i>
            <?= $editTpl ? 'টেমপ্লেট সম্পাদনা' : 'নতুন টেমপ্লেট তৈরি' ?>
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
            <input type="hidden" name="save_template" value="1">
            <input type="hidden" name="edit_id" value="<?= $editTpl['id'] ?? 0 ?>">

            <div class="row g-3">
                <div class="col-md-7">
                    <label class="form-label fw-semibold">টেমপ্লেটের নাম <span class="required-star">*</span></label>
                    <input type="text" name="name" class="form-control"
                           value="<?= htmlspecialchars($editTpl['name'] ?? '') ?>"
                           placeholder="যেমন: প্রথম সতর্কতা নোটিশ" required>
                </div>
                <div class="col-md-5">
                    <label class="form-label fw-semibold">ধরন</label>
                    <select name="type" class="form-select">
                        <?php foreach ($typeConfig as $tv => $tc): ?>
                        <option value="<?= $tv ?>" <?= ($editTpl['type'] ?? 'warning') === $tv ? 'selected' : '' ?>>
                            <?= $tc['label'] ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold">বিষয় <span class="required-star">*</span></label>
                    <input type="text" name="subject" class="form-control"
                           value="<?= htmlspecialchars($editTpl['subject'] ?? '') ?>"
                           placeholder="নোটিশের বিষয় লিখুন..." required>
                </div>

                <!-- Placeholder Chips -->
                <div class="col-12">
                    <label class="form-label fw-semibold">Placeholder — ক্লিক করে বার্তায় যোগ করুন</label>
                    <div class="d-flex flex-wrap gap-1">
                        <?php foreach ($placeholders as $ph => $label): ?>
                        <button type="button" class="btn btn-sm"
                                onclick="insertPlaceholder('<?= $ph ?>')"
                                style="background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;
                                       font-size:11px;border-radius:20px;padding:2px 10px;">
                            <?= $ph ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold">বার্তা <span class="required-star">*</span></label>
                    <textarea name="body" id="templateBody" class="form-control" rows="12"
                              placeholder="নোটিশের বার্তা লিখুন। Placeholder ব্যবহার করুন..." required><?= htmlspecialchars($editTpl['body'] ?? '') ?></textarea>
                </div>

                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-success fw-semibold px-4">
                        <i class="bi bi-<?= $editTpl ? 'check-lg' : 'plus-circle' ?> me-1"></i>
                        <?= $editTpl ? 'আপডেট করুন' : 'টেমপ্লেট সংরক্ষণ করুন' ?>
                    </button>
                    <?php if ($editTpl): ?>
                    <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-outline-secondary">বাতিল</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<script>
function insertPlaceholder(ph) {
    const ta = document.getElementById('templateBody');
    const start = ta.selectionStart;
    const end   = ta.selectionEnd;
    ta.value = ta.value.substring(0, start) + ph + ta.value.substring(end);
    ta.selectionStart = ta.selectionEnd = start + ph.length;
    ta.focus();
}
<?php if ($editTpl): ?>
document.getElementById('tplForm').scrollIntoView({behavior:'smooth'});
<?php endif; ?>
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
