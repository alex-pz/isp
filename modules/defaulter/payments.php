<?php
// ============================================================
// PAYMENT TRACKING — B-1.1
// File: modules/defaulter/payments.php
// আংশিক পেমেন্ট রেকর্ড, ইতিহাস ও রিসিট
// ============================================================
require_once __DIR__ . '/../../includes/functions.php';
requirePermission('defaulters', 'view');

$user = getCurrentUser();
$id   = (int)($_GET['id'] ?? 0);

$defaulter = Database::fetchOne(
    "SELECT d.*, c.company_name, c.phone as co_phone, c.email as co_email,
            u.full_name as entered_by_name
     FROM defaulters d
     JOIN companies c ON c.id = d.company_id
     JOIN users u ON u.id = d.entered_by
     WHERE d.id = ?", [$id]
);
if (!$defaulter) { setFlash('error', 'এন্ট্রি পাওয়া যায়নি।'); redirect('list.php'); }

$canEdit = hasPermission('defaulters','edit_own') && $defaulter['company_id'] == $user['company_id'];

$errors  = [];
$success = false;

// ── নতুন পেমেন্ট যোগ ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_payment'])) {
    requirePermission('defaulters', 'edit_own');
    if (!validateCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $errors[] = 'নিরাপত্তা যাচাই ব্যর্থ।';
    } elseif ($defaulter['company_id'] != $user['company_id']) {
        $errors[] = 'অনুমতি নেই।';
    } else {
        $amount  = (float)($_POST['amount'] ?? 0);
        $method  = $_POST['method'] ?? 'cash';
        $ref     = trim($_POST['reference'] ?? '');
        $note    = trim($_POST['note'] ?? '');
        $date    = $_POST['payment_date'] ?? date('Y-m-d');

        if ($amount <= 0)  $errors[] = 'পেমেন্টের পরিমাণ দিন।';
        if ($amount > (float)$defaulter['due_amount']) $errors[] = 'পেমেন্ট বকেয়ার চেয়ে বেশি হতে পারে না।';

        if (empty($errors)) {
            // মোট আদায় হিসাব করা
            $totalPaid = (float)Database::fetchOne(
                "SELECT COALESCE(SUM(amount),0) as t FROM payments WHERE defaulter_id = ?", [$id]
            )['t'];
            $newTotal  = $totalPaid + $amount;

            Database::insert('payments', [
                'defaulter_id' => $id,
                'company_id'   => $user['company_id'],
                'recorded_by'  => $user['id'],
                'amount'       => $amount,
                'payment_date' => $date,
                'method'       => $method,
                'reference'    => $ref ?: null,
                'note'         => $note ?: null,
            ]);

            // বকেয়া সম্পূর্ণ পরিশোধ হলে auto-resolve
            if ($newTotal >= (float)$defaulter['due_amount']) {
                $waiver = max(0, (float)$defaulter['due_amount'] - $newTotal);
                Database::update('defaulters', [
                    'status'          => 'resolved',
                    'resolution_type' => 'partial_payment',
                    'payment_amount'  => $newTotal,
                    'waiver_amount'   => $waiver > 0 ? $waiver : null,
                    'resolved_at'     => date('Y-m-d H:i:s'),
                    'resolved_by'     => $user['id'],
                    'resolution_note' => 'পেমেন্ট ট্র্যাকিং থেকে সম্পূর্ণ পরিশোধ।',
                ], 'id = ?', [$id]);
                setFlash('success', 'পেমেন্ট রেকর্ড হয়েছে — সম্পূর্ণ পরিশোধ সম্পন্ন! এন্ট্রি সমাধান হিসেবে চিহ্নিত হয়েছে।');
            } else {
                setFlash('success', '৳' . number_format($amount) . ' পেমেন্ট রেকর্ড হয়েছে। বাকি: ৳' . number_format((float)$defaulter['due_amount'] - $newTotal));
            }

            logActivity('payment.add', 'defaulters', [
                'target_id'   => $id, 'target_type' => 'defaulters',
                'description' => $defaulter['customer_name'] . ' — পেমেন্ট: ৳' . number_format($amount),
            ]);
            redirect($_SERVER['PHP_SELF'] . '?id=' . $id);
        }
    }
}

// ── পেমেন্ট মুছুন ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_payment'])) {
    requirePermission('defaulters', 'edit_own');
    if (validateCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '') && $canEdit) {
        $pid = (int)($_POST['payment_id'] ?? 0);
        Database::query("DELETE FROM payments WHERE id = ? AND company_id = ?",
            [$pid, $user['company_id']]);
        setFlash('success', 'পেমেন্ট মুছে ফেলা হয়েছে।');
        redirect($_SERVER['PHP_SELF'] . '?id=' . $id);
    }
}

// ── পেমেন্ট ইতিহাস ──────────────────────────────────────────
$payments = Database::fetchAll(
    "SELECT p.*, u.full_name as recorded_by_name
     FROM payments p JOIN users u ON u.id = p.recorded_by
     WHERE p.defaulter_id = ?
     ORDER BY p.payment_date DESC, p.created_at DESC",
    [$id]
);

$totalPaid    = array_sum(array_column($payments, 'amount'));
$remaining    = max(0, (float)$defaulter['due_amount'] - $totalPaid);
$paidPercent  = $defaulter['due_amount'] > 0
    ? min(100, round(($totalPaid / $defaulter['due_amount']) * 100)) : 0;

$methodLabels = [
    'cash'  => ['label'=>'নগদ',   'icon'=>'cash',        'color'=>'#16a34a'],
    'bkash' => ['label'=>'bKash', 'icon'=>'phone',       'color'=>'#e11d48'],
    'nagad' => ['label'=>'Nagad', 'icon'=>'phone',       'color'=>'#ea580c'],
    'rocket'=> ['label'=>'Rocket','icon'=>'phone',       'color'=>'#7c3aed'],
    'bank'  => ['label'=>'ব্যাংক','icon'=>'bank',        'color'=>'#2563a8'],
    'other' => ['label'=>'অন্য',  'icon'=>'three-dots',  'color'=>'#64748b'],
];

$pageTitle = 'পেমেন্ট ট্র্যাকিং: ' . $defaulter['customer_name'];
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div>
        <h1>পেমেন্ট ট্র্যাকিং</h1>
        <nav><ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="list.php">তালিকা</a></li>
            <li class="breadcrumb-item"><a href="view.php?id=<?= $id ?>">বিবরণ</a></li>
            <li class="breadcrumb-item active">পেমেন্ট</li>
        </ol></nav>
    </div>
    <a href="<?= SITE_URL ?>/modules/defaulter/receipt.php?id=<?= $id ?>"
       class="btn btn-outline-primary" target="_blank">
        <i class="bi bi-printer me-1"></i>রিসিট প্রিন্ট
    </a>
</div>

<!-- ===== TOP SUMMARY ===== -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card p-3 text-center" style="border-left:4px solid #dc2626;">
            <div style="font-size:11px;text-transform:uppercase;color:#64748b;letter-spacing:1px;">মোট বকেয়া</div>
            <div style="font-size:28px;font-weight:800;color:#dc2626;"><?= formatMoney($defaulter['due_amount']) ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card p-3 text-center" style="border-left:4px solid #16a34a;">
            <div style="font-size:11px;text-transform:uppercase;color:#64748b;letter-spacing:1px;">মোট আদায়</div>
            <div style="font-size:28px;font-weight:800;color:#16a34a;"><?= formatMoney($totalPaid) ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card p-3 text-center" style="border-left:4px solid <?= $remaining > 0 ? '#d97706' : '#16a34a' ?>;">
            <div style="font-size:11px;text-transform:uppercase;color:#64748b;letter-spacing:1px;">বাকি</div>
            <div style="font-size:28px;font-weight:800;color:<?= $remaining > 0 ? '#d97706' : '#16a34a' ?>;">
                <?= $remaining > 0 ? formatMoney($remaining) : '✅ পরিশোধ' ?>
            </div>
        </div>
    </div>
</div>

<!-- Progress Bar -->
<div class="card p-3 mb-4">
    <div class="d-flex justify-content-between mb-1" style="font-size:13px;">
        <span class="fw-semibold">পরিশোধের অগ্রগতি</span>
        <span class="fw-bold" style="color:#16a34a;"><?= $paidPercent ?>%</span>
    </div>
    <div style="height:12px;background:#f1f5f9;border-radius:6px;overflow:hidden;">
        <div style="height:100%;width:<?= $paidPercent ?>%;background:<?= $paidPercent >= 100 ? '#16a34a' : '#2563a8' ?>;
                    border-radius:6px;transition:width .8s ease;"></div>
    </div>
    <div class="d-flex justify-content-between mt-1" style="font-size:11px;color:#94a3b8;">
        <span><?= count($payments) ?> টি পেমেন্ট</span>
        <span>সর্বশেষ: <?= !empty($payments) ? date('d M Y', strtotime($payments[0]['payment_date'])) : 'কোনো পেমেন্ট নেই' ?></span>
    </div>
</div>

<div class="row g-3">
<!-- ===== LEFT: ADD PAYMENT ===== -->
<?php if ($canEdit && $defaulter['status'] === 'active'): ?>
<div class="col-lg-4">
    <div class="card">
        <div class="card-header"><h6 class="card-title"><i class="bi bi-plus-circle text-success me-2"></i>নতুন পেমেন্ট যোগ</h6></div>
        <div class="card-body">
            <?php if (!empty($errors)): ?>
            <div class="alert alert-danger rounded-3 mb-3" style="font-size:13px;">
                <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
            </div>
            <?php endif; ?>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="add_payment" value="1">

                <div class="mb-3">
                    <label class="form-label fw-semibold">পেমেন্টের পরিমাণ (৳) <span class="required-star">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text">৳</span>
                        <input type="number" name="amount" class="form-control" id="amountInput"
                               placeholder="0.00" min="1" step="0.01"
                               max="<?= $remaining ?>" oninput="updateRemaining(this.value)"
                               value="<?= $_POST['amount'] ?? '' ?>" required>
                    </div>
                    <div id="remainingInfo" class="form-text fw-semibold text-warning mt-1"></div>
                </div>

                <!-- Quick Amount Buttons -->
                <div class="d-flex flex-wrap gap-1 mb-3">
                    <?php
                    $quick = [];
                    $due = (float)$defaulter['due_amount'];
                    if ($remaining >= 500)  $quick[] = 500;
                    if ($remaining >= 1000) $quick[] = 1000;
                    if ($remaining >= 2000) $quick[] = 2000;
                    $quick[] = (int)$remaining; // পুরো বাকি
                    $quick = array_unique($quick);
                    foreach ($quick as $q): ?>
                    <button type="button" class="btn btn-sm btn-outline-primary"
                            style="font-size:11px;border-radius:20px;"
                            onclick="document.getElementById('amountInput').value=<?= $q ?>;updateRemaining(<?= $q ?>)">
                        ৳<?= number_format($q) ?>
                    </button>
                    <?php endforeach; ?>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">পেমেন্টের তারিখ</label>
                    <input type="date" name="payment_date" class="form-control"
                           value="<?= $_POST['payment_date'] ?? date('Y-m-d') ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">পদ্ধতি</label>
                    <div class="row g-2">
                        <?php foreach ($methodLabels as $mv => $ml): ?>
                        <div class="col-4">
                            <label style="cursor:pointer;display:block;text-align:center;padding:6px 4px;
                                          border-radius:8px;border:2px solid <?= $mv==='cash'?'#2563a8':'#e2e8f0' ?>;
                                          background:<?= $mv==='cash'?'#eff6ff':'#fff' ?>;font-size:11px;"
                                   class="method-opt" data-method="<?= $mv ?>">
                                <input type="radio" name="method" value="<?= $mv ?>"
                                       <?= ($mv==='cash')?'checked':'' ?>
                                       style="display:none;" onchange="pickMethod(this)">
                                <i class="bi bi-<?= $ml['icon'] ?>" style="font-size:14px;color:<?= $ml['color'] ?>;display:block;"></i>
                                <?= $ml['label'] ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Reference / Transaction ID</label>
                    <input type="text" name="reference" class="form-control"
                           placeholder="bKash TrxID / চেক নম্বর..."
                           value="<?= htmlspecialchars($_POST['reference'] ?? '') ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">নোট</label>
                    <textarea name="note" class="form-control" rows="2"
                              placeholder="অতিরিক্ত তথ্য..."><?= htmlspecialchars($_POST['note'] ?? '') ?></textarea>
                </div>

                <button type="submit" class="btn btn-success w-100 fw-semibold">
                    <i class="bi bi-plus-circle me-1"></i>পেমেন্ট যোগ করুন
                </button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ===== RIGHT: PAYMENT HISTORY ===== -->
<div class="col-lg-<?= ($canEdit && $defaulter['status']==='active') ? '8' : '12' ?>">
    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h6 class="card-title mb-0">
                <i class="bi bi-clock-history me-2"></i>পেমেন্ট ইতিহাস
            </h6>
            <span class="badge bg-secondary rounded-pill"><?= count($payments) ?></span>
        </div>
        <?php if (empty($payments)): ?>
        <div class="card-body text-center py-5 text-muted">
            <i class="bi bi-inbox fs-1 d-block mb-2"></i>
            কোনো পেমেন্ট রেকর্ড নেই
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:13px;">
                <thead class="table-light">
                    <tr>
                        <th>তারিখ</th>
                        <th>পরিমাণ</th>
                        <th>পদ্ধতি</th>
                        <th>Reference</th>
                        <th>রেকর্ডকারী</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $runningTotal = 0;
                $rows = array_reverse($payments);
                foreach ($rows as $p):
                    $runningTotal += (float)$p['amount'];
                    $ml = $methodLabels[$p['method']] ?? $methodLabels['other'];
                ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><?= date('d M Y', strtotime($p['payment_date'])) ?></div>
                        <div class="text-muted" style="font-size:11px;"><?= timeAgo($p['created_at']) ?></div>
                    </td>
                    <td>
                        <div class="fw-bold" style="color:#16a34a;font-size:15px;">৳<?= number_format($p['amount'], 2) ?></div>
                        <div class="text-muted" style="font-size:10px;">মোট: ৳<?= number_format($runningTotal, 2) ?></div>
                    </td>
                    <td>
                        <span class="badge rounded-pill"
                              style="background:<?= $ml['color'] ?>22;color:<?= $ml['color'] ?>;border:1px solid <?= $ml['color'] ?>44;">
                            <i class="bi bi-<?= $ml['icon'] ?> me-1"></i><?= $ml['label'] ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($p['reference']): ?>
                        <code style="font-size:11px;"><?= htmlspecialchars($p['reference']) ?></code>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        <?php if ($p['note']): ?>
                        <div class="text-muted" style="font-size:11px;"><?= htmlspecialchars(mb_substr($p['note'],0,40,'UTF-8')) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;"><?= htmlspecialchars($p['recorded_by_name']) ?></td>
                    <td>
                        <?php if ($canEdit): ?>
                        <form method="POST" style="display:inline;"
                              onsubmit="return confirm('এই পেমেন্ট মুছবেন?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="delete_payment" value="1">
                            <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"
                                    style="border-radius:6px;padding:2px 6px;">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <td class="fw-bold">মোট</td>
                        <td class="fw-bold" style="color:#16a34a;">৳<?= number_format($totalPaid, 2) ?></td>
                        <td colspan="4">
                            <?php if ($remaining > 0): ?>
                            <span style="color:#d97706;font-weight:600;">বাকি: ৳<?= number_format($remaining, 2) ?></span>
                            <?php else: ?>
                            <span style="color:#16a34a;font-weight:600;"><i class="bi bi-check-circle-fill me-1"></i>সম্পূর্ণ পরিশোধ</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
</div>

<script>
const remaining = <?= $remaining ?>;
function updateRemaining(val) {
    const v = parseFloat(val) || 0;
    const left = Math.max(0, remaining - v);
    const el = document.getElementById('remainingInfo');
    if (v > 0) {
        el.textContent = 'পরিশোধের পর বাকি থাকবে: ৳' + left.toFixed(2);
        el.style.color = left > 0 ? '#d97706' : '#16a34a';
    } else {
        el.textContent = '';
    }
}
function pickMethod(radio) {
    document.querySelectorAll('.method-opt').forEach(el => {
        const sel = el.dataset.method === radio.value;
        el.style.borderColor = sel ? '#2563a8' : '#e2e8f0';
        el.style.background  = sel ? '#eff6ff' : '#fff';
    });
}
// click on label
document.querySelectorAll('.method-opt').forEach(el => {
    el.addEventListener('click', function() {
        const r = this.querySelector('input[type=radio]');
        r.checked = true;
        pickMethod(r);
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
