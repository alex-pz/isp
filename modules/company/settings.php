<?php
// ============================================================
// COMPANY SETTINGS — B-5.2
// File: modules/company/settings.php
// কোম্পানি-লেভেল কনফিগারেশন
// ============================================================
require_once __DIR__ . '/../../includes/functions.php';
requirePermission('companies', 'view');

$user = getCurrentUser();

// Company Admin বা তার উপরে
if (!in_array($user['role_name'], ['super_admin', 'admin', 'company_admin'])) {
    setFlash('error', 'এই পেজ দেখার অনুমতি নেই।');
    redirect(SITE_URL . '/dashboard.php');
}

$companyId = (int)$user['company_id'];
$company   = Database::fetchOne("SELECT * FROM companies WHERE id = ?", [$companyId]);

if (!$company) {
    setFlash('error', 'কোম্পানি পাওয়া যায়নি।');
    redirect(SITE_URL . '/dashboard.php');
}

// Master switch বন্ধ কিনা চেক
$masterEnabled = getSetting('payment_tracking_enabled', '1') === '1';

// ── সেটিংস সেভ ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    if (!validateCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        setFlash('error', 'নিরাপত্তা যাচাই ব্যর্থ।');
    } else {
        $paymentTracking = isset($_POST['payment_tracking']) ? 1 : 0;
        Database::update('companies',
            ['payment_tracking' => $paymentTracking],
            'id = ?', [$companyId]
        );
        // Refresh company data
        $company = Database::fetchOne("SELECT * FROM companies WHERE id = ?", [$companyId]);
        setFlash('success', 'কোম্পানি সেটিংস সংরক্ষিত হয়েছে।');
        redirect($_SERVER['PHP_SELF']);
    }
}

$pageTitle = 'কোম্পানি সেটিংস';
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1><i class="bi bi-building-gear me-2"></i>কোম্পানি সেটিংস</h1>
    <nav><ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="<?= SITE_URL ?>/dashboard.php">হোম</a></li>
        <li class="breadcrumb-item active">কোম্পানি সেটিংস</li>
    </ol></nav>
</div>

<div class="row justify-content-center">
<div class="col-lg-7">

<!-- ===== পেমেন্ট ট্র্যাকিং সুইচ ===== -->
<div class="card mb-4">
    <div class="card-header">
        <h6 class="card-title mb-0">
            <i class="bi bi-credit-card me-2 text-primary"></i>পেমেন্ট ট্র্যাকিং কনফিগারেশন
        </h6>
    </div>
    <div class="card-body">

        <?php if (!$masterEnabled): ?>
        <!-- Master বন্ধ থাকলে warning -->
        <div class="alert alert-warning rounded-3 mb-3" style="font-size:13px;">
            <i class="bi bi-exclamation-triangle me-1"></i>
            <strong>সিস্টেম-লেভেল পেমেন্ট ট্র্যাকিং বন্ধ আছে।</strong>
            সুপার অ্যাডমিন চালু না করলে এই সেটিং কাজ করবে না।
        </div>
        <?php endif; ?>

        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="save_settings" value="1">

            <div class="d-flex align-items-center justify-content-between p-3 rounded-3"
                 style="border:2px solid <?= $company['payment_tracking'] ? '#bbf7d0' : '#fecaca' ?>;
                        background:<?= $company['payment_tracking'] ? '#f0fdf4' : '#fef2f2' ?>;"
                 id="trackingCard">

                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle d-flex align-items-center justify-content-center"
                         style="width:48px;height:48px;background:<?= $company['payment_tracking'] ? '#16a34a' : '#dc2626' ?>;
                                color:#fff;font-size:20px;" id="trackingIcon">
                        <i class="bi bi-<?= $company['payment_tracking'] ? 'credit-card' : 'credit-card-2-back' ?>"></i>
                    </div>
                    <div>
                        <div class="fw-bold" style="font-size:15px;" id="trackingLabel">
                            পেমেন্ট ট্র্যাকিং
                            <?= $company['payment_tracking'] ? 'চালু আছে' : 'বন্ধ আছে' ?>
                        </div>
                        <div style="font-size:12px;color:#64748b;" id="trackingDesc">
                            <?= $company['payment_tracking']
                                ? 'আংশিক পেমেন্ট রেকর্ড, রিসিট, হিস্ট্রি সব চালু'
                                : 'পেমেন্ট বাটন ও হিস্ট্রি লুকানো থাকবে' ?>
                        </div>
                    </div>
                </div>

                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch"
                           name="payment_tracking" id="paymentSwitch"
                           <?= $company['payment_tracking'] ? 'checked' : '' ?>
                           <?= !$masterEnabled ? 'disabled' : '' ?>
                           style="width:52px;height:26px;cursor:pointer;"
                           onchange="updateCard(this.checked)">
                </div>
            </div>

            <?php if ($masterEnabled): ?>
            <div class="mt-3">
                <button type="submit" class="btn btn-primary px-4 fw-semibold">
                    <i class="bi bi-check-lg me-1"></i>সেটিংস সংরক্ষণ করুন
                </button>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- ===== কী কী প্রভাবিত হবে ===== -->
<div class="card">
    <div class="card-header">
        <h6 class="card-title mb-0">
            <i class="bi bi-info-circle me-2 text-info"></i>পেমেন্ট ট্র্যাকিং চালু/বন্ধের প্রভাব
        </h6>
    </div>
    <div class="card-body p-0">
        <?php
        $effects = [
            ['icon'=>'credit-card',    'color'=>'#2563a8', 'label'=>'পেমেন্ট বাটন',    'on'=>'বকেয়া বিবরণে দেখাবে',       'off'=>'লুকিয়ে যাবে'],
            ['icon'=>'clock-history',  'color'=>'#7c3aed', 'label'=>'পেমেন্ট হিস্ট্রি', 'on'=>'সব লেনদেন দেখাবে',          'off'=>'লুকিয়ে যাবে'],
            ['icon'=>'receipt',        'color'=>'#16a34a', 'label'=>'রিসিট PDF',         'on'=>'প্রিন্ট করা যাবে',          'off'=>'লুকিয়ে যাবে'],
            ['icon'=>'bar-chart-line', 'color'=>'#d97706', 'label'=>'আর্থিক ড্যাশবোর্ড', 'on'=>'পেমেন্ট স্ট্যাটিস্টিক্স', 'off'=>'খালি দেখাবে'],
        ];
        foreach ($effects as $ef): ?>
        <div class="d-flex align-items-center gap-3 px-3 py-2 border-bottom" style="font-size:13px;">
            <i class="bi bi-<?= $ef['icon'] ?>" style="color:<?= $ef['color'] ?>;font-size:18px;width:22px;text-align:center;"></i>
            <div style="flex:1;">
                <span class="fw-semibold"><?= $ef['label'] ?></span>
            </div>
            <div class="text-end">
                <div style="color:#16a34a;font-size:11px;"><i class="bi bi-check-circle me-1"></i>চালু হলে: <?= $ef['on'] ?></div>
                <div style="color:#dc2626;font-size:11px;"><i class="bi bi-x-circle me-1"></i>বন্ধ হলে: <?= $ef['off'] ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

</div>
</div>

<script>
function updateCard(checked) {
    const card  = document.getElementById('trackingCard');
    const icon  = document.getElementById('trackingIcon');
    const label = document.getElementById('trackingLabel');
    const desc  = document.getElementById('trackingDesc');

    if (checked) {
        card.style.borderColor  = '#bbf7d0';
        card.style.background   = '#f0fdf4';
        icon.style.background   = '#16a34a';
        icon.innerHTML          = '<i class="bi bi-credit-card"></i>';
        label.textContent       = 'পেমেন্ট ট্র্যাকিং চালু আছে';
        desc.textContent        = 'আংশিক পেমেন্ট রেকর্ড, রিসিট, হিস্ট্রি সব চালু';
    } else {
        card.style.borderColor  = '#fecaca';
        card.style.background   = '#fef2f2';
        icon.style.background   = '#dc2626';
        icon.innerHTML          = '<i class="bi bi-credit-card-2-back"></i>';
        label.textContent       = 'পেমেন্ট ট্র্যাকিং বন্ধ আছে';
        desc.textContent        = 'পেমেন্ট বাটন ও হিস্ট্রি লুকানো থাকবে';
    }
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
