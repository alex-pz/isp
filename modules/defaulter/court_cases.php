<?php
// ============================================================
// COURT CASE TRACKER — C-1.4
// File: modules/defaulter/court_cases.php
// ============================================================
require_once __DIR__ . '/../../includes/functions.php';
requirePermission('defaulters', 'view');

$user      = getCurrentUser();
$errors    = [];

// ── Mode নির্ধারণ ─────────────────────────────────────────────
// ?id=X → নির্দিষ্ট defaulter এর cases
// ?all=1 → সব cases (কোম্পানি লেভেল)
$defaulterId = (int)($_GET['id'] ?? 0);
$viewAll     = !$defaulterId;

$defaulter = null;
if ($defaulterId) {
    $defaulter = Database::fetchOne(
        "SELECT d.*, c.company_name FROM defaulters d
         JOIN companies c ON c.id = d.company_id WHERE d.id = ?", [$defaulterId]
    );
    if (!$defaulter) { setFlash('error', 'এন্ট্রি পাওয়া যায়নি।'); redirect('list.php'); }
    if (!isSuperAdmin() && $defaulter['company_id'] != $user['company_id']) {
        setFlash('error', 'অনুমতি নেই।'); redirect('list.php');
    }
}

$pageTitle = $defaulter
    ? 'মামলার তথ্য — '.$defaulter['customer_name']
    : 'সব মামলার তালিকা';

$statusConfig = [
    'preparing' => ['label'=>'প্রস্তুতি',    'color'=>'#64748b', 'badge'=>'secondary'],
    'filed'     => ['label'=>'দাখিল হয়েছে', 'color'=>'#2563a8', 'badge'=>'primary'],
    'hearing'   => ['label'=>'শুনানি চলছে',  'color'=>'#d97706', 'badge'=>'warning'],
    'judgment'  => ['label'=>'রায় অপেক্ষায়', 'color'=>'#7c3aed', 'badge'=>'purple'],
    'won'       => ['label'=>'জয়',           'color'=>'#16a34a', 'badge'=>'success'],
    'lost'      => ['label'=>'পরাজয়',        'color'=>'#dc2626', 'badge'=>'danger'],
    'settled'   => ['label'=>'আপোষ মীমাংসা', 'color'=>'#0891b2', 'badge'=>'info'],
    'withdrawn' => ['label'=>'প্রত্যাহার',   'color'=>'#94a3b8', 'badge'=>'secondary'],
];

// ── নতুন মামলা দাখিল ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_case'])) {
    if (!validateCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $errors[] = 'নিরাপত্তা যাচাই ব্যর্থ।';
    } else {
        $defId      = (int)($_POST['defaulter_id'] ?? $defaulterId);
        $caseNo     = trim($_POST['case_number'] ?? '');
        $courtName  = trim($_POST['court_name'] ?? '');
        $filingDate = $_POST['filing_date'] ?? null;
        $nextDate   = $_POST['next_date'] ?: null;
        $claimAmt   = (float)($_POST['claim_amount'] ?? 0);
        $status     = $_POST['status'] ?? 'preparing';
        $lawyer     = trim($_POST['lawyer_name'] ?? '');
        $lawPhone   = trim($_POST['lawyer_phone'] ?? '');
        $desc       = trim($_POST['description'] ?? '');
        $editId     = (int)($_POST['edit_id'] ?? 0);

        if (empty($courtName)) $errors[] = 'আদালতের নাম দিন।';

        if (empty($errors)) {
            $data = [
                'defaulter_id' => $defId,
                'company_id'   => $user['company_id'],
                'case_number'  => $caseNo ?: null,
                'court_name'   => $courtName,
                'filing_date'  => $filingDate ?: null,
                'next_date'    => $nextDate,
                'claim_amount' => $claimAmt ?: null,
                'status'       => $status,
                'lawyer_name'  => $lawyer ?: null,
                'lawyer_phone' => $lawPhone ?: null,
                'description'  => $desc ?: null,
            ];
            if ($editId) {
                Database::update('court_cases', $data, 'id = ?', [$editId]);
                setFlash('success', 'মামলার তথ্য আপডেট হয়েছে।');
            } else {
                $data['created_by'] = $user['id'];
                Database::insert('court_cases', $data);
                setFlash('success', 'মামলা দাখিল হয়েছে।');
            }
            $redir = $defaulterId ? $_SERVER['PHP_SELF'].'?id='.$defaulterId : $_SERVER['PHP_SELF'];
            redirect($redir);
        }
    }
}

// ── Case Update যোগ ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_update'])) {
    if (validateCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $caseId     = (int)($_POST['case_id'] ?? 0);
        $updateText = trim($_POST['update_text'] ?? '');
        $nextDate   = $_POST['next_date'] ?: null;
        $newStatus  = $_POST['new_status'] ?? '';

        if ($caseId && $updateText) {
            Database::insert('court_case_updates', [
                'case_id'     => $caseId,
                'updated_by'  => $user['id'],
                'update_text' => $updateText,
                'next_date'   => $nextDate,
            ]);
            if ($newStatus) {
                $upd = ['status' => $newStatus];
                if ($nextDate) $upd['next_date'] = $nextDate;
                Database::update('court_cases', $upd, 'id = ?', [$caseId]);
            }
            setFlash('success', 'আপডেট যোগ হয়েছে।');
        }
        $redir = $defaulterId ? $_SERVER['PHP_SELF'].'?id='.$defaulterId : $_SERVER['PHP_SELF'];
        redirect($redir.'#case-'.$caseId);
    }
}

// ── Cases লোড ────────────────────────────────────────────────
$whereCase  = $defaulterId ? 'cc.defaulter_id = ?' : 'cc.company_id = ?';
$whereParam = $defaulterId ?: $user['company_id'];

$cases = Database::fetchAll(
    "SELECT cc.*, d.customer_name, d.customer_phone, d.due_amount,
            u.full_name as creator_name
     FROM court_cases cc
     JOIN defaulters d ON d.id = cc.defaulter_id
     JOIN users u ON u.id = cc.created_by
     WHERE $whereCase
     ORDER BY cc.created_at DESC",
    [$whereParam]
);

// প্রতিটি case এর updates লোড
foreach ($cases as &$case) {
    $case['updates'] = Database::fetchAll(
        "SELECT cu.*, u.full_name as updater_name
         FROM court_case_updates cu
         JOIN users u ON u.id = cu.updated_by
         WHERE cu.case_id = ?
         ORDER BY cu.created_at DESC",
        [$case['id']]
    );
}
unset($case);

// Edit case
$editCase = null;
if (isset($_GET['edit'])) {
    $editCase = Database::fetchOne("SELECT * FROM court_cases WHERE id = ?", [(int)$_GET['edit']]);
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-bank me-2"></i><?= htmlspecialchars($pageTitle) ?></h1>
        <nav><ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="list.php">বকেয়া তালিকা</a></li>
            <?php if ($defaulter): ?>
            <li class="breadcrumb-item"><a href="view.php?id=<?= $defaulterId ?>"><?= htmlspecialchars($defaulter['customer_name']) ?></a></li>
            <?php endif; ?>
            <li class="breadcrumb-item active">মামলা ট্র্যাকার</li>
        </ol></nav>
    </div>
    <button class="btn btn-danger fw-semibold" onclick="document.getElementById('caseForm').scrollIntoView({behavior:'smooth'})">
        <i class="bi bi-plus-circle me-1"></i>নতুন মামলা
    </button>
</div>

<!-- ===== STATS ===== -->
<?php if (!empty($cases)):
    $active   = count(array_filter($cases, fn($c) => in_array($c['status'], ['filed','hearing','judgment'])));
    $won      = count(array_filter($cases, fn($c) => $c['status'] === 'won'));
    $settled  = count(array_filter($cases, fn($c) => $c['status'] === 'settled'));
    $totalClaim = array_sum(array_column($cases, 'claim_amount'));
?>
<div class="row g-3 mb-4">
    <?php foreach ([
        ['label'=>'মোট মামলা',    'val'=>count($cases),     'icon'=>'bank',        'color'=>'#1a3a5c'],
        ['label'=>'সক্রিয় মামলা', 'val'=>$active,           'icon'=>'hourglass',   'color'=>'#d97706'],
        ['label'=>'জয়',           'val'=>$won,              'icon'=>'trophy',      'color'=>'#16a34a'],
        ['label'=>'মোট দাবি',     'val'=>'৳'.number_format($totalClaim), 'icon'=>'cash-stack', 'color'=>'#dc2626'],
    ] as $s): ?>
    <div class="col-6 col-md-3">
        <div class="card text-center py-3">
            <i class="bi bi-<?= $s['icon'] ?>" style="color:<?= $s['color'] ?>;font-size:24px;"></i>
            <div style="font-size:20px;font-weight:700;color:<?= $s['color'] ?>;"><?= $s['val'] ?></div>
            <div style="font-size:12px;color:#64748b;"><?= $s['label'] ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="row g-4">
<!-- ===== CASES LIST ===== -->
<div class="col-lg-7">
<?php if (empty($cases)): ?>
<div class="card"><div class="card-body text-center py-5 text-muted">
    <i class="bi bi-bank fs-1 d-block mb-2"></i>কোনো মামলা নেই
</div></div>
<?php endif; ?>

<?php foreach ($cases as $case):
    $sc = $statusConfig[$case['status']] ?? $statusConfig['preparing'];
    $nextDue = $case['next_date'] && strtotime($case['next_date']) < time() + 86400*7;
?>
<div class="card mb-3" id="case-<?= $case['id'] ?>">
    <div class="card-header d-flex align-items-center justify-content-between gap-2" style="flex-wrap:wrap;">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-bank" style="color:<?= $sc['color'] ?>;font-size:18px;"></i>
            <div>
                <div class="fw-bold" style="font-size:14px;">
                    <?= htmlspecialchars($case['court_name']) ?>
                    <?php if ($case['case_number']): ?>
                    <span style="font-size:12px;color:#64748b;font-weight:400;">
                        · মামলা নং: <?= htmlspecialchars($case['case_number']) ?>
                    </span>
                    <?php endif; ?>
                </div>
                <?php if ($viewAll): ?>
                <div style="font-size:12px;color:#2563a8;">
                    <a href="view.php?id=<?= $case['defaulter_id'] ?>" class="text-decoration-none">
                        <?= htmlspecialchars($case['customer_name']) ?>
                    </a>
                    · ৳<?= number_format($case['due_amount']) ?> বকেয়া
                </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-<?= $sc['badge'] ?> rounded-pill"><?= $sc['label'] ?></span>
            <a href="?<?= $defaulterId ? 'id='.$defaulterId.'&' : '' ?>edit=<?= $case['id'] ?>"
               class="btn btn-sm btn-outline-primary" style="padding:2px 8px;border-radius:6px;">
                <i class="bi bi-pencil" style="font-size:11px;"></i>
            </a>
        </div>
    </div>
    <div class="card-body pb-2">
        <!-- Meta -->
        <div class="row g-2 mb-3" style="font-size:12px;">
            <?php if ($case['filing_date']): ?>
            <div class="col-6"><span class="text-muted">দাখিলের তারিখ:</span> <?= date('d M Y', strtotime($case['filing_date'])) ?></div>
            <?php endif; ?>
            <?php if ($case['next_date']): ?>
            <div class="col-6">
                <span class="text-muted">পরবর্তী তারিখ:</span>
                <span style="color:<?= $nextDue ? '#dc2626' : '#16a34a' ?>;font-weight:600;">
                    <?= date('d M Y', strtotime($case['next_date'])) ?>
                    <?= $nextDue ? ' ⚠️' : '' ?>
                </span>
            </div>
            <?php endif; ?>
            <?php if ($case['claim_amount']): ?>
            <div class="col-6"><span class="text-muted">দাবির পরিমাণ:</span> ৳<?= number_format($case['claim_amount']) ?></div>
            <?php endif; ?>
            <?php if ($case['lawyer_name']): ?>
            <div class="col-6">
                <span class="text-muted">আইনজীবী:</span> <?= htmlspecialchars($case['lawyer_name']) ?>
                <?= $case['lawyer_phone'] ? ' · '.$case['lawyer_phone'] : '' ?>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($case['description']): ?>
        <p style="font-size:13px;color:#475569;margin-bottom:12px;"><?= nl2br(htmlspecialchars($case['description'])) ?></p>
        <?php endif; ?>

        <!-- Updates Timeline -->
        <?php if (!empty($case['updates'])): ?>
        <div style="border-left:3px solid #e2e8f0;padding-left:14px;margin-bottom:12px;">
            <div style="font-size:12px;font-weight:600;color:#64748b;margin-bottom:6px;">আপডেট লগ</div>
            <?php foreach (array_slice($case['updates'], 0, 3) as $upd): ?>
            <div class="mb-2" style="font-size:12px;">
                <div style="color:#0f2238;"><?= nl2br(htmlspecialchars($upd['update_text'])) ?></div>
                <div style="color:#94a3b8;">
                    <?= htmlspecialchars($upd['updater_name']) ?> · <?= date('d M Y', strtotime($upd['created_at'])) ?>
                    <?= $upd['next_date'] ? ' · পরবর্তী: '.date('d M Y', strtotime($upd['next_date'])) : '' ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (count($case['updates']) > 3): ?>
            <div style="font-size:11px;color:#94a3b8;">+ আরো <?= count($case['updates'])-3 ?> টি আপডেট</div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Add Update Form -->
        <details style="font-size:13px;">
            <summary style="cursor:pointer;color:#2563a8;font-weight:600;list-style:none;user-select:none;">
                <i class="bi bi-plus-circle me-1"></i>নতুন আপডেট যোগ করুন
            </summary>
            <form method="POST" class="mt-2">
                <?= csrfField() ?>
                <input type="hidden" name="add_update" value="1">
                <input type="hidden" name="case_id" value="<?= $case['id'] ?>">
                <div class="row g-2">
                    <div class="col-12">
                        <textarea name="update_text" class="form-control form-control-sm" rows="2"
                                  placeholder="শুনানির ফলাফল, পরিস্থিতি আপডেট করুন..." required></textarea>
                    </div>
                    <div class="col-md-5">
                        <input type="date" name="next_date" class="form-control form-control-sm"
                               placeholder="পরবর্তী তারিখ">
                    </div>
                    <div class="col-md-4">
                        <select name="new_status" class="form-select form-select-sm">
                            <option value="">স্ট্যাটাস অপরিবর্তিত</option>
                            <?php foreach ($statusConfig as $sv => $sc2): ?>
                            <option value="<?= $sv ?>"><?= $sc2['label'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary btn-sm w-100">যোগ করুন</button>
                    </div>
                </div>
            </form>
        </details>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- ===== FORM ===== -->
<div class="col-lg-5">
<div class="card" id="caseForm">
    <div class="card-header">
        <h6 class="card-title mb-0">
            <i class="bi bi-<?= $editCase ? 'pencil' : 'plus-circle' ?> me-2 text-<?= $editCase ? 'warning' : 'danger' ?>"></i>
            <?= $editCase ? 'মামলার তথ্য সম্পাদনা' : 'নতুন মামলা দাখিল' ?>
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
            <input type="hidden" name="save_case" value="1">
            <input type="hidden" name="edit_id" value="<?= $editCase['id'] ?? 0 ?>">
            <input type="hidden" name="defaulter_id" value="<?= $defaulterId ?>">

            <div class="row g-3">
                <?php if ($viewAll): ?>
                <div class="col-12">
                    <label class="form-label fw-semibold">গ্রাহক <span class="required-star">*</span></label>
                    <select name="defaulter_id" class="form-select" required>
                        <option value="">-- গ্রাহক বেছে নিন --</option>
                        <?php foreach (Database::fetchAll(
                            "SELECT id, customer_name, customer_phone FROM defaulters
                             WHERE company_id = ? AND status = 'active' ORDER BY customer_name",
                            [$user['company_id']]
                        ) as $def): ?>
                        <option value="<?= $def['id'] ?>"
                            <?= ($editCase['defaulter_id'] ?? 0) == $def['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($def['customer_name']) ?> · <?= $def['customer_phone'] ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="col-12">
                    <label class="form-label fw-semibold">আদালতের নাম <span class="required-star">*</span></label>
                    <input type="text" name="court_name" class="form-control"
                           value="<?= htmlspecialchars($editCase['court_name'] ?? '') ?>"
                           placeholder="যেমন: ঢাকা জেলা আদালত" required>
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold">মামলার নম্বর</label>
                    <input type="text" name="case_number" class="form-control"
                           value="<?= htmlspecialchars($editCase['case_number'] ?? '') ?>"
                           placeholder="যেমন: ১২৩/২০২৪">
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">দাখিলের তারিখ</label>
                    <input type="date" name="filing_date" class="form-control"
                           value="<?= $editCase['filing_date'] ?? '' ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">পরবর্তী তারিখ</label>
                    <input type="date" name="next_date" class="form-control"
                           value="<?= $editCase['next_date'] ?? '' ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">দাবির পরিমাণ (৳)</label>
                    <input type="number" name="claim_amount" class="form-control"
                           value="<?= $editCase['claim_amount'] ?? $defaulter['due_amount'] ?? '' ?>"
                           placeholder="0.00" step="0.01">
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">মামলার স্ট্যাটাস</label>
                    <select name="status" class="form-select">
                        <?php foreach ($statusConfig as $sv => $sc3): ?>
                        <option value="<?= $sv ?>" <?= ($editCase['status'] ?? 'preparing') === $sv ? 'selected' : '' ?>>
                            <?= $sc3['label'] ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-7">
                    <label class="form-label fw-semibold">আইনজীবীর নাম</label>
                    <input type="text" name="lawyer_name" class="form-control"
                           value="<?= htmlspecialchars($editCase['lawyer_name'] ?? '') ?>"
                           placeholder="আইনজীবীর নাম">
                </div>

                <div class="col-md-5">
                    <label class="form-label fw-semibold">আইনজীবীর ফোন</label>
                    <input type="text" name="lawyer_phone" class="form-control"
                           value="<?= htmlspecialchars($editCase['lawyer_phone'] ?? '') ?>"
                           placeholder="01XXXXXXXXX">
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold">বিবরণ</label>
                    <textarea name="description" class="form-control" rows="3"
                              placeholder="মামলার বিস্তারিত, অভিযোগ ইত্যাদি..."><?= htmlspecialchars($editCase['description'] ?? '') ?></textarea>
                </div>

                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-danger fw-semibold px-4">
                        <i class="bi bi-<?= $editCase ? 'check-lg' : 'bank' ?> me-1"></i>
                        <?= $editCase ? 'আপডেট করুন' : 'মামলা দাখিল করুন' ?>
                    </button>
                    <?php if ($editCase): ?>
                    <a href="<?= $defaulterId ? '?id='.$defaulterId : $_SERVER['PHP_SELF'] ?>"
                       class="btn btn-outline-secondary">বাতিল</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<?php if ($editCase): ?>
<script>document.getElementById('caseForm').scrollIntoView({behavior:'smooth'});</script>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
