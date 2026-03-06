<?php
// ============================================================
// RESOLVE DEFAULTER — সমাধান চিহ্নিত করুন
// File: modules/defaulter/resolve.php
// ============================================================
require_once __DIR__ . '/../../includes/functions.php';
requirePermission('defaulters', 'mark_done');

$user = getCurrentUser();
$id   = (int)($_GET['id'] ?? 0);

$defaulter = Database::fetchOne(
    "SELECT d.*, c.company_name FROM defaulters d
     JOIN companies c ON c.id = d.company_id
     WHERE d.id = ?", [$id]
);
if (!$defaulter || $defaulter['company_id'] != $user['company_id']) {
    setFlash('error', 'এন্ট্রি পাওয়া যায়নি বা অনুমতি নেই।');
    redirect('list.php');
}
if ($defaulter['status'] !== 'active') {
    setFlash('error', 'এই এন্ট্রি ইতোমধ্যে সমাধান হয়েছে।');
    redirect('view.php?id=' . $id);
}

$errors    = [];
$pageTitle = 'সমাধান চিহ্নিত করুন';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $errors[] = 'নিরাপত্তা যাচাই ব্যর্থ।';
    } else {
        $resolutionType = $_POST['resolution_type'] ?? 'full_payment';
        $note           = trim($_POST['resolution_note'] ?? '');
        $paymentAmount  = 0.0;
        $waiverAmount   = 0.0;

        if (empty($note)) $errors[] = 'সমাধানের বিবরণ লিখুন।';

        if ($resolutionType === 'full_payment') {
            $paymentAmount = (float)$defaulter['due_amount'];
            $waiverAmount  = 0.0;
        } elseif ($resolutionType === 'partial_payment') {
            $paymentAmount = (float)($_POST['payment_amount'] ?? 0);
            if ($paymentAmount <= 0) $errors[] = 'আদায় করা টাকার পরিমাণ দিন।';
            if ($paymentAmount > (float)$defaulter['due_amount']) $errors[] = 'আদায় করা টাকা বকেয়ার চেয়ে বেশি হতে পারে না।';
            $waiverAmount = (float)$defaulter['due_amount'] - $paymentAmount;
        } elseif ($resolutionType === 'waived') {
            $paymentAmount = 0.0;
            $waiverAmount  = (float)$defaulter['due_amount'];
        } else {
            $paymentAmount = (float)($_POST['payment_amount'] ?? 0);
            $waiverAmount  = max(0.0, (float)$defaulter['due_amount'] - $paymentAmount);
        }

        if (empty($errors)) {
            Database::update('defaulters', [
                'status'          => 'resolved',
                'resolution_type' => $resolutionType,
                'payment_amount'  => $paymentAmount,
                'waiver_amount'   => $waiverAmount > 0 ? $waiverAmount : null,
                'resolved_at'     => date('Y-m-d H:i:s'),
                'resolved_by'     => $user['id'],
                'resolution_note' => $note,
            ], 'id = ?', [$id]);

            logActivity('defaulter.resolve', 'defaulters', [
                'target_id'   => $id, 'target_type' => 'defaulters',
                'description' => '"'.$defaulter['customer_name'].'" সমাধান — আদায়: '.formatMoney($paymentAmount).
                                 ($waiverAmount > 0 ? ', মাফ: '.formatMoney($waiverAmount) : ''),
            ]);
            setFlash('success', '"'.$defaulter['customer_name'].'" সমাধান হিসেবে চিহ্নিত হয়েছে।');
            redirect('view.php?id=' . $id);
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1>সমাধান চিহ্নিত করুন</h1>
    <nav><ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="list.php">তালিকা</a></li>
        <li class="breadcrumb-item"><a href="view.php?id=<?= $id ?>">বিবরণ</a></li>
        <li class="breadcrumb-item active">সমাধান</li>
    </ol></nav>
</div>

<div class="row justify-content-center">
<div class="col-md-6">
<div class="card">
    <div class="card-header"><h6 class="card-title"><i class="bi bi-check-circle text-success me-2"></i>বকেয়া সমাধান নিশ্চিত করুন</h6></div>
    <div class="card-body">

        <div class="p-3 rounded-3 mb-4" style="background:#f8fafc;border:1px solid #e2e8f0;">
            <div class="fw-bold"><?= htmlspecialchars($defaulter['customer_name']) ?></div>
            <div class="text-muted small"><?= htmlspecialchars($defaulter['customer_phone']) ?></div>
            <div class="mt-2 d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted small">মোট বকেয়া:</span>
                    <span style="color:#dc2626;font-size:20px;font-weight:800;margin-left:6px;"><?= formatMoney($defaulter['due_amount']) ?></span>
                </div>
                <span class="risk-badge risk-<?= $defaulter['risk_level'] ?>"><?= getStatusLabel($defaulter['risk_level']) ?></span>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger rounded-3 mb-3" style="font-size:13px;">
            <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
        </div>
        <?php endif; ?>

        <form method="POST">
            <?= csrfField() ?>

            <div class="mb-4">
                <label class="form-label fw-semibold">সমাধানের ধরন <span class="required-star">*</span></label>
                <div class="d-flex flex-column gap-2">
                    <?php $types = [
                        'full_payment'    => ['icon'=>'✅','label'=>'পূর্ণ পরিশোধ',       'desc'=>'সম্পূর্ণ '.formatMoney($defaulter['due_amount']).' আদায় হয়েছে'],
                        'partial_payment' => ['icon'=>'💰','label'=>'আংশিক পরিশোধ',      'desc'=>'কিছু নেওয়া হয়েছে, বাকিটা মাফ/ছাড়'],
                        'waived'          => ['icon'=>'🤝','label'=>'সম্পূর্ণ মাফ',        'desc'=>'সম্পর্ক রক্ষার জন্য পুরোটা মাফ করা হয়েছে'],
                        'other'           => ['icon'=>'📝','label'=>'অন্য উপায়ে সমাধান','desc'=>'বিস্তারিত নোটে লিখুন'],
                    ];
                    foreach ($types as $tv => $ti): ?>
                    <label class="res-type-opt" data-type="<?= $tv ?>"
                           style="cursor:pointer;display:flex;align-items:flex-start;gap:10px;padding:10px 12px;
                                  border-radius:10px;border:2px solid <?= $tv==='full_payment'?'#2563a8':'#e2e8f0' ?>;
                                  background:<?= $tv==='full_payment'?'#eff6ff':'#fff' ?>;">
                        <input type="radio" name="resolution_type" value="<?= $tv ?>"
                               <?= $tv==='full_payment'?'checked':'' ?> style="margin-top:3px;"
                               onchange="updateForm(this.value)">
                        <div>
                            <div style="font-size:14px;font-weight:600;"><?= $ti['icon'] ?> <?= $ti['label'] ?></div>
                            <div style="font-size:12px;color:#94a3b8;"><?= $ti['desc'] ?></div>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div id="payWrap" style="display:none;" class="mb-3">
                <label class="form-label fw-semibold">আদায় করা টাকা (৳)</label>
                <div class="input-group">
                    <span class="input-group-text" style="border-radius:8px 0 0 8px;">৳</span>
                    <input type="number" name="payment_amount" id="payInput" class="form-control"
                           placeholder="0.00" min="0" step="0.01" max="<?= $defaulter['due_amount'] ?>"
                           oninput="updateSummary(this.value)">
                </div>
                <div id="waiverInfo" class="form-text text-warning fw-semibold mt-1"></div>
            </div>

            <div id="summaryBox" class="p-3 rounded-3 mb-3" style="background:#f0fdf4;border:1px solid #bbf7d0;font-size:13px;">
                <div class="fw-semibold text-success mb-1">✅ পূর্ণ পরিশোধ</div>
                <div>আদায়: <strong><?= formatMoney($defaulter['due_amount']) ?></strong> &nbsp;|&nbsp; মাফ/ছাড়: <strong>৳০.০০</strong></div>
            </div>

            <div class="mb-4">
                <label class="form-label fw-semibold">বিবরণ / নোট <span class="required-star">*</span></label>
                <textarea name="resolution_note" class="form-control" rows="3" required
                          placeholder="কিভাবে সমাধান হলো, কার মাধ্যমে ইত্যাদি..."></textarea>
            </div>

            <div class="d-flex gap-2">
                <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary flex-fill">বাতিল</a>
                <button type="submit" class="btn btn-success flex-fill fw-semibold">
                    <i class="bi bi-check-lg me-1"></i>সমাধান নিশ্চিত করুন
                </button>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<script>
const totalDue = <?= (float)$defaulter['due_amount'] ?>;
function updateForm(type) {
    document.querySelectorAll('.res-type-opt').forEach(el => {
        const sel = el.dataset.type === type;
        el.style.borderColor = sel ? '#2563a8' : '#e2e8f0';
        el.style.background  = sel ? '#eff6ff' : '#fff';
    });
    document.getElementById('payWrap').style.display =
        (type === 'partial_payment' || type === 'other') ? 'block' : 'none';
    updateSummary(document.getElementById('payInput')?.value || 0, type);
}
function updateSummary(val, type) {
    type = type || document.querySelector('[name=resolution_type]:checked')?.value;
    const box = document.getElementById('summaryBox');
    let paid = 0, waiver = 0;
    if (type === 'full_payment')    { paid = totalDue; waiver = 0; }
    else if (type === 'waived')     { paid = 0; waiver = totalDue; }
    else { paid = parseFloat(val) || 0; waiver = Math.max(0, totalDue - paid); }
    document.getElementById('waiverInfo').textContent =
        waiver > 0 ? 'মাফ/ছাড়: ৳' + waiver.toFixed(2) + ' টাকা' : '';
    const colors = {
        full_payment: ['#f0fdf4','#bbf7d0','✅ পূর্ণ পরিশোধ','text-success'],
        partial_payment: ['#eff6ff','#bfdbfe','💰 আংশিক পরিশোধ','text-primary'],
        waived: ['#fffbeb','#fde68a','🤝 সম্পূর্ণ মাফ','text-warning'],
        other: ['#eff6ff','#bfdbfe','📝 অন্য উপায়','text-primary'],
    };
    const c = colors[type] || colors.full_payment;
    box.style.background = c[0]; box.style.borderColor = c[1];
    box.innerHTML = `<div class="fw-semibold ${c[3]} mb-1">${c[2]}</div>
        <div>আদায়: <strong>৳${paid.toFixed(2)}</strong> &nbsp;|&nbsp;
             মাফ/ছাড়: <strong>৳${waiver.toFixed(2)}</strong></div>`;
}
updateForm('full_payment');
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
