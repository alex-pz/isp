<?php
// ============================================================
// CUSTOMER PROFILE — Credit Score + Full History
// File: modules/customer/profile.php
// ============================================================
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/credit_score.php';
requirePermission('defaulters', 'view');

$user  = getCurrentUser();
$phone = trim($_GET['phone'] ?? '');
$nid   = trim($_GET['nid'] ?? '');

// Search by phone or NID
if (empty($phone) && empty($nid)) {
    $pageTitle = 'গ্রাহক খুঁজুন';
    include __DIR__ . '/../../includes/header.php';
    ?>
    <div class="page-header"><h1>গ্রাহক প্রোফাইল</h1></div>
    <div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card p-4">
            <h5 class="fw-bold mb-3"><i class="bi bi-person-circle me-2"></i>গ্রাহক খুঁজুন</h5>
            <form method="GET">
                <div class="mb-3">
                    <label class="form-label">মোবাইল নম্বর দিয়ে</label>
                    <input type="text" name="phone" class="form-control" placeholder="01XXXXXXXXX" maxlength="11">
                </div>
                <div class="mb-3">
                    <label class="form-label">অথবা NID নম্বর দিয়ে</label>
                    <input type="text" name="nid" class="form-control" placeholder="NID নম্বর">
                </div>
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-search me-1"></i>প্রোফাইল দেখুন
                </button>
            </form>
        </div>
    </div>
    </div>
    <?php
    include __DIR__ . '/../../includes/footer.php';
    exit;
}

// ---- Fetch customer entries ----
$where  = $phone ? 'd.customer_phone = ?' : 'd.nid_number = ?';
$param  = $phone ?: $nid;

$entries = Database::fetchAll(
    "SELECT d.*, c.company_name, c.phone as co_phone, u.full_name as entered_by_name,
            ru.full_name as resolved_by_name,
            (SELECT photo_path FROM defaulter_photos WHERE defaulter_id = d.id AND is_primary = 1 LIMIT 1) as photo
     FROM defaulters d
     JOIN companies c ON c.id = d.company_id
     JOIN users u ON u.id = d.entered_by
     LEFT JOIN users ru ON ru.id = d.resolved_by
     WHERE $where
     ORDER BY d.created_at DESC",
    [$param]
);

if (empty($entries)) {
    setFlash('error', 'কোনো রেকর্ড পাওয়া যায়নি।');
    redirect($_SERVER['PHP_SELF']);
}

$customerName  = $entries[0]['customer_name'];
$customerPhone = $entries[0]['customer_phone'];
$customerNID   = $entries[0]['nid_number'] ?? '';
$primaryPhoto  = $entries[0]['photo'] ?? null;

// Credit Score
$score = CreditScore::calculate($customerPhone, $customerNID);

// Similar customers (same name ~fuzzy)
$similar = Database::fetchAll(
    "SELECT DISTINCT d.customer_name, d.customer_phone, d.nid_number,
            COUNT(*) as entry_count, SUM(d.due_amount) as total_due
     FROM defaulters d
     WHERE d.customer_phone != ? AND (
         d.customer_name LIKE ? OR
         (? != '' AND d.nid_number = ?)
     )
     GROUP BY d.customer_phone
     ORDER BY entry_count DESC LIMIT 5",
    [$customerPhone, '%' . mb_substr($customerName, 0, 4, 'UTF-8') . '%', $customerNID, $customerNID]
);

$pageTitle = 'গ্রাহক প্রোফাইল: ' . $customerName;
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div>
        <h1><?= htmlspecialchars($customerName) ?></h1>
        <nav><ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= SITE_URL ?>/dashboard.php">হোম</a></li>
            <li class="breadcrumb-item"><a href="<?= $_SERVER['PHP_SELF'] ?>">গ্রাহক খুঁজুন</a></li>
            <li class="breadcrumb-item active">প্রোফাইল</li>
        </ol></nav>
    </div>
</div>

<div class="row g-3">
<!-- ===== LEFT: Profile + Score ===== -->
<div class="col-lg-4">

    <!-- Profile Card -->
    <div class="card mb-3 text-center p-4">
        <?php if ($primaryPhoto): ?>
        <img src="<?= UPLOAD_URL . $primaryPhoto ?>" alt=""
             style="width:90px;height:90px;border-radius:50%;object-fit:cover;border:3px solid #e2e8f0;margin:0 auto 12px;">
        <?php else: ?>
        <div style="width:90px;height:90px;border-radius:50%;background:#1a3a5c;color:#fff;
                    display:flex;align-items:center;justify-content:center;font-size:32px;font-weight:700;
                    margin:0 auto 12px;">
            <?= mb_substr($customerName, 0, 1, 'UTF-8') ?>
        </div>
        <?php endif; ?>

        <h5 class="fw-bold mb-1"><?= htmlspecialchars($customerName) ?></h5>
        <div class="text-muted small mb-2"><?= htmlspecialchars($customerPhone) ?></div>
        <?php if ($customerNID): ?><div class="text-muted small">NID: <?= htmlspecialchars($customerNID) ?></div><?php endif; ?>

        <!-- A-1.1: Repeat Offender Badge -->
        <?php if ($score['is_repeat']): ?>
        <div class="mt-2">
            <?= CreditScore::repeatBadge($score['companies']) ?>
        </div>
        <?php endif; ?>
        <!-- A-1.2: Blacklist Level Badge -->
        <?php if ($score['blacklist']['level'] !== 'clean'): ?>
        <div class="mt-1">
            <?= CreditScore::blacklistBadge($score['blacklist']) ?>
        </div>
        <?php endif; ?>

        <hr>
        <div class="row g-2 text-center" style="font-size:13px;">
            <div class="col-4">
                <div class="fw-bold text-danger" style="font-size:18px;"><?= $score['active_count'] ?></div>
                <div class="text-muted small">সক্রিয়</div>
            </div>
            <div class="col-4">
                <div class="fw-bold text-success" style="font-size:18px;"><?= $score['resolved_count'] ?></div>
                <div class="text-muted small">সমাধান</div>
            </div>
            <div class="col-4">
                <div class="fw-bold" style="font-size:18px;color:#d97706;"><?= $score['companies'] ?></div>
                <div class="text-muted small">কোম্পানি</div>
            </div>
        </div>
        <div class="mt-2 fw-bold" style="color:#dc2626;font-size:16px;">মোট: <?= formatMoney($score['total_due']) ?></div>
    </div>

    <!-- A-1.2 + A-1.4: Credit Score Card (উন্নত) -->
    <div class="card mb-3 p-4" style="border:2px solid <?= $score['label']['border'] ?>;background:<?= $score['label']['bg'] ?>">
        <div class="text-center mb-3">
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#64748b;font-weight:600;">ক্রেডিট স্কোর</div>
            <div style="font-size:56px;font-weight:800;color:<?= $score['label']['color'] ?>;line-height:1.1;">
                <?= $score['score'] ?>
            </div>
            <div style="font-size:13px;font-weight:600;color:<?= $score['label']['color'] ?>">/ 100</div>
            <div class="mt-2 mx-2">
                <?= CreditScore::scoreBar($score['score'], false) ?>
            </div>
            <div class="mt-2 fw-semibold" style="color:<?= $score['label']['color'] ?>">
                <?= $score['label']['text'] ?>
            </div>
        </div>

        <!-- Blacklist Level (A-1.2) -->
        <div class="text-center mb-2">
            <?= CreditScore::blacklistBadge($score['blacklist']) ?>
        </div>

        <!-- আর্থিক সারসংক্ষেপ -->
        <?php if ($score['total_paid'] > 0 || $score['total_waived'] > 0): ?>
        <div class="p-2 rounded mb-2" style="background:rgba(255,255,255,.6);font-size:11px;">
            <?php if ($score['total_paid'] > 0): ?>
            <div class="d-flex justify-content-between">
                <span class="text-muted">মোট আদায়:</span>
                <span class="text-success fw-semibold">৳<?= number_format($score['total_paid']) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($score['total_waived'] > 0): ?>
            <div class="d-flex justify-content-between">
                <span class="text-muted">মোট মাফ/ছাড়:</span>
                <span class="text-warning fw-semibold">৳<?= number_format($score['total_waived']) ?></span>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($score['has_fraud']): ?>
        <div class="text-center mb-2">
            <span class="badge rounded-pill bg-danger" style="font-size:11px;">
                <i class="bi bi-shield-x me-1"></i>প্রতারণা / চুরির রেকর্ড আছে
            </span>
        </div>
        <?php endif; ?>

        <!-- Score breakdown -->
        <?php if (!empty($score['details'])): ?>
        <hr style="border-color:<?= $score['label']['border'] ?>;margin:10px 0;">
        <div style="font-size:11px;">
            <?php foreach ($score['details'] as $det): ?>
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="text-muted">
                    <i class="bi bi-<?= $det['icon'] ?> me-1"></i><?= $det['label'] ?>
                    <span style="color:#94a3b8;">(<?= $det['value'] ?>)</span>
                </span>
                <span class="fw-bold" style="color:<?= $det['points'] > 0 ? '#16a34a' : '#dc2626' ?>">
                    <?= $det['points'] > 0 ? '+' : '' ?><?= $det['points'] ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Similar Customers -->
    <?php if (!empty($similar)): ?>
    <div class="card p-3">
        <div class="fw-semibold mb-2 text-danger" style="font-size:13px;">
            <i class="bi bi-exclamation-triangle me-1"></i>সম্ভাব্য একই ব্যক্তি
        </div>
        <?php foreach ($similar as $s): ?>
        <a href="?phone=<?= urlencode($s['customer_phone']) ?>"
           class="d-flex justify-content-between align-items-center text-decoration-none text-dark py-2 border-bottom"
           style="font-size:12px;">
            <div>
                <div class="fw-semibold"><?= htmlspecialchars($s['customer_name']) ?></div>
                <div class="text-muted"><?= htmlspecialchars($s['customer_phone']) ?></div>
            </div>
            <div class="text-end">
                <div style="color:#dc2626;font-weight:600;"><?= formatMoney($s['total_due']) ?></div>
                <div class="text-muted"><?= $s['entry_count'] ?>টি এন্ট্রি</div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ===== RIGHT: History Timeline ===== -->
<div class="col-lg-8">
    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <h6 class="card-title mb-0">
                <i class="bi bi-clock-history me-2"></i>সম্পূর্ণ ইতিহাস
                <span class="badge bg-secondary rounded-pill ms-1"><?= count($entries) ?></span>
            </h6>
            <!-- A-1.3: Timeline Summary Bar -->
            <div class="d-flex gap-2" style="font-size:12px;">
                <?php
                $tActive   = count(array_filter($entries, fn($e)=>$e['status']==='active'));
                $tResolved = count(array_filter($entries, fn($e)=>$e['status']==='resolved'));
                $tDisputed = count(array_filter($entries, fn($e)=>$e['status']==='disputed'));
                $tPaid     = array_sum(array_column(array_filter($entries,fn($e)=>$e['status']==='resolved'),'payment_amount'));
                $tWaived   = array_sum(array_column(array_filter($entries,fn($e)=>$e['status']==='resolved'),'waiver_amount'));
                ?>
                <?php if ($tActive > 0): ?>
                <span class="badge rounded-pill bg-danger"><?= $tActive ?> সক্রিয়</span>
                <?php endif; ?>
                <?php if ($tResolved > 0): ?>
                <span class="badge rounded-pill bg-success"><?= $tResolved ?> সমাধান</span>
                <?php endif; ?>
                <?php if ($tDisputed > 0): ?>
                <span class="badge rounded-pill bg-warning text-dark"><?= $tDisputed ?> বিরোধ</span>
                <?php endif; ?>
            </div>
        </div>
        <!-- A-1.3: Financial Summary Row -->
        <?php if ($tPaid > 0 || $tWaived > 0 || $score['total_due'] > 0): ?>
        <div class="px-4 py-2 d-flex flex-wrap gap-3" style="background:#f8fafc;border-bottom:1px solid #e2e8f0;font-size:12px;">
            <?php if ($score['total_due'] > 0): ?>
            <div><span class="text-muted">মোট বকেয়া: </span><strong style="color:#dc2626;">৳<?= number_format($score['total_due']) ?></strong></div>
            <?php endif; ?>
            <?php if ($tPaid > 0): ?>
            <div><span class="text-muted">মোট আদায়: </span><strong style="color:#16a34a;">৳<?= number_format($tPaid) ?></strong></div>
            <?php endif; ?>
            <?php if ($tWaived > 0): ?>
            <div><span class="text-muted">মোট মাফ: </span><strong style="color:#d97706;">৳<?= number_format($tWaived) ?></strong></div>
            <?php endif; ?>
            <?php
            $totalOriginal = $score['total_due'] + ($tPaid > 0 ? $tPaid : 0) + ($tWaived > 0 ? $tWaived : 0);
            $recRate = $totalOriginal > 0 ? round(($tPaid / $totalOriginal) * 100) : 0;
            ?>
            <?php if ($recRate > 0): ?>
            <div><span class="text-muted">রিকভারি: </span><strong style="color:#2563a8;"><?= $recRate ?>%</strong></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="card-body p-0">
            <div class="timeline p-4">
                <?php foreach ($entries as $i => $e): ?>
                <div class="d-flex gap-3 <?= $i < count($entries)-1 ? 'mb-4' : '' ?>" style="position:relative;">
                    <!-- Timeline dot -->
                    <div style="flex-shrink:0;position:relative;">
                        <div style="width:40px;height:40px;border-radius:50%;
                                    background:<?= $e['status'] === 'resolved' ? '#f0fdf4' : '#fef2f2' ?>;
                                    border:2px solid <?= $e['status'] === 'resolved' ? '#16a34a' : '#dc2626' ?>;
                                    display:flex;align-items:center;justify-content:center;font-size:16px;">
                            <i class="bi bi-<?= $e['status'] === 'resolved' ? 'check-circle-fill text-success' : 'exclamation-circle-fill text-danger' ?>"></i>
                        </div>
                        <?php if ($i < count($entries)-1): ?>
                        <div style="position:absolute;left:50%;top:42px;bottom:-16px;width:2px;background:#e2e8f0;transform:translateX(-50%);"></div>
                        <?php endif; ?>
                    </div>

                    <!-- Content -->
                    <div style="flex:1;background:#f8fafc;border-radius:12px;padding:16px;border:1px solid #e2e8f0;">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                            <div>
                                <div class="fw-bold" style="color:#0f2238;font-size:15px;"><?= htmlspecialchars($e['company_name']) ?></div>
                                <div class="text-muted small"><?= formatDate($e['created_at']) ?></div>
                            </div>
                            <div class="text-end">
                                <div style="font-size:20px;font-weight:800;color:#dc2626;">৳<?= number_format($e['due_amount']) ?></div>
                                <span class="badge bg-<?= getBadgeClass($e['status']) ?> rounded-pill" style="font-size:11px;">
                                    <?= getStatusLabel($e['status']) ?>
                                </span>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap gap-2 mt-2" style="font-size:12px;">
                            <span class="risk-badge risk-<?= $e['risk_level'] ?>"><?= getStatusLabel($e['risk_level']) ?></span>
                            <span style="background:#f1f5f9;color:#475569;padding:2px 8px;border-radius:10px;">
                                <?= getStatusLabel($e['type']) ?>
                            </span>
                            <?php if ($e['service_period']): ?>
                            <span style="background:#f1f5f9;color:#475569;padding:2px 8px;border-radius:10px;">
                                <i class="bi bi-calendar me-1"></i><?= htmlspecialchars($e['service_period']) ?>
                            </span>
                            <?php endif; ?>
                        </div>

                        <?php if ($e['address_text']): ?>
                        <div class="text-muted mt-2" style="font-size:12px;">
                            <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars(mb_substr($e['address_text'], 0, 80, 'UTF-8')) ?>
                        </div>
                        <?php endif; ?>

                        <?php if ($e['status'] === 'resolved'): ?>
                        <div class="mt-2 p-2 rounded" style="background:#f0fdf4;border:1px solid #bbf7d0;font-size:12px;">
                            <!-- Resolution type badge -->
                            <?php
                            $resIcon = match($e['resolution_type'] ?? 'full_payment') {
                                'full_payment'    => ['icon'=>'check-circle-fill','color'=>'#16a34a','label'=>'পূর্ণ পরিশোধ'],
                                'partial_payment' => ['icon'=>'circle-half',      'color'=>'#2563a8','label'=>'আংশিক পরিশোধ'],
                                'waived'          => ['icon'=>'hand-thumbs-up-fill','color'=>'#d97706','label'=>'সম্পূর্ণ মাফ'],
                                default           => ['icon'=>'check2-circle',    'color'=>'#64748b','label'=>'সমাধান হয়েছে'],
                            };
                            ?>
                            <div class="d-flex align-items-center justify-content-between flex-wrap gap-1">
                                <span style="font-weight:600;color:<?= $resIcon['color'] ?>;">
                                    <i class="bi bi-<?= $resIcon['icon'] ?> me-1"></i><?= $resIcon['label'] ?>
                                </span>
                                <span class="text-muted"><?= $e['resolved_by_name'] ?? '' ?></span>
                            </div>
                            <!-- Payment breakdown -->
                            <?php if ($e['payment_amount'] !== null || $e['waiver_amount'] !== null): ?>
                            <div class="d-flex gap-3 mt-1" style="font-size:11px;">
                                <?php if ((float)($e['payment_amount'] ?? 0) > 0): ?>
                                <span style="color:#16a34a;"><i class="bi bi-check me-1"></i>আদায়: ৳<?= number_format($e['payment_amount']) ?></span>
                                <?php endif; ?>
                                <?php if ((float)($e['waiver_amount'] ?? 0) > 0): ?>
                                <span style="color:#d97706;"><i class="bi bi-dash me-1"></i>মাফ: ৳<?= number_format($e['waiver_amount']) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            <?php if ($e['resolution_note']): ?>
                            <div class="mt-1 text-muted"><?= htmlspecialchars(mb_substr($e['resolution_note'], 0, 100, 'UTF-8')) ?></div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <?php if ($e['status'] === 'disputed'): ?>
                        <div class="mt-2 p-2 rounded" style="background:#fffbeb;border:1px solid #fde68a;font-size:12px;color:#92400e;">
                            <i class="bi bi-flag-fill me-1"></i><strong>বিরোধ উত্থাপিত আছে</strong>
                        </div>
                        <?php endif; ?>

                        <div class="mt-2 d-flex justify-content-between align-items-center">
                            <a href="<?= SITE_URL ?>/modules/defaulter/view.php?id=<?= $e['id'] ?>"
                               class="text-decoration-none" style="font-size:12px;color:#2563a8;">
                                বিস্তারিত দেখুন <i class="bi bi-arrow-right ms-1"></i>
                            </a>
                            <span class="text-muted" style="font-size:11px;"><?= timeAgo($e['created_at']) ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
