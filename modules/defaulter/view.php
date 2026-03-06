<?php
// ============================================================
// DEFAULTER DETAIL VIEW
// File: modules/defaulter/view.php
// ============================================================
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/credit_score.php';
requirePermission('defaulters', 'view');

$user = getCurrentUser();
$id   = (int)($_GET['id'] ?? 0);

$defaulter = Database::fetchOne(
    "SELECT d.*, c.company_name, c.phone as company_phone, c.email as company_email,
            u.full_name as entered_by_name, u.phone as entered_by_phone,
            ru.full_name as resolved_by_name
     FROM defaulters d
     JOIN companies c ON c.id = d.company_id
     JOIN users u ON u.id = d.entered_by
     LEFT JOIN users ru ON ru.id = d.resolved_by
     WHERE d.id = ?",
    [$id]
);

if (!$defaulter) {
    setFlash('error', 'এন্ট্রিটি পাওয়া যায়নি।');
    redirect(SITE_URL . '/modules/defaulter/list.php');
}

// Increment view count
Database::query("UPDATE defaulters SET view_count = view_count + 1 WHERE id = ?", [$id]);

$photos   = Database::fetchAll("SELECT * FROM defaulter_photos WHERE defaulter_id = ? ORDER BY is_primary DESC", [$id]);
$disputes = Database::fetchAll(
    "SELECT dis.*, u.full_name as raised_by_name, c.company_name as raised_by_company,
            ru.full_name as resolved_by_name
     FROM disputes dis
     JOIN users u ON u.id = dis.raised_by
     JOIN companies c ON c.id = u.company_id
     LEFT JOIN users ru ON ru.id = dis.resolved_by
     WHERE dis.defaulter_id = ?
     ORDER BY dis.created_at DESC", [$id]
);

$logs = Database::fetchAll(
    "SELECT al.*, u.full_name FROM activity_logs al
     LEFT JOIN users u ON u.id = al.user_id
     WHERE al.target_id = ? AND al.target_type = 'defaulters'
     ORDER BY al.created_at DESC LIMIT 20", [$id]
);

$mapsKey   = getSetting('google_maps_key');
$creditScore = CreditScore::calculate($defaulter['customer_phone'], $defaulter['nid_number'] ?? '');
$pageTitle = 'বকেয়া বিবরণ: ' . $defaulter['customer_name'];

$canEdit   = hasPermission('defaulters','edit_own') && $defaulter['company_id'] == $user['company_id'];

// B-5.3: পেমেন্ট ট্র্যাকিং চালু আছে কিনা চেক
$masterTracking  = getSetting('payment_tracking_enabled', '1') === '1';
$companyTracking = false;
try {
    $companyRow = Database::fetchOne(
        "SELECT payment_tracking FROM companies WHERE id = ?",
        [$defaulter['company_id']]
    );
    $companyTracking = (bool)($companyRow['payment_tracking'] ?? 1);
} catch (Exception $e) {
    $companyTracking = true;
}
$paymentTrackingOn = $masterTracking && $companyTracking;

// B-3.3: Mutual Blacklist status
$mutualBlacklist = null;
try {
    $mutualBlacklist = Database::fetchOne(
        "SELECT mb.*, c.company_name as requester_name
         FROM mutual_blacklist mb
         JOIN companies c ON c.id = mb.requested_by
         WHERE mb.defaulter_id = ?
         ORDER BY mb.created_at DESC LIMIT 1",
        [$id]
    );
} catch (Exception $e) {}

// B-3.3: Mutual blacklist request handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mutual_action'])) {
    if (validateCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $mAction = $_POST['mutual_action'];
        if ($mAction === 'request' && $defaulter['company_id'] != $user['company_id']) {
            try {
                Database::insert('mutual_blacklist', [
                    'defaulter_id' => $id,
                    'requested_by' => $user['company_id'],
                    'reason'       => trim($_POST['reason'] ?? ''),
                    'status'       => 'pending',
                ]);
                setFlash('success', 'যৌথ ব্ল্যাকলিস্ট অনুরোধ পাঠানো হয়েছে।');
            } catch (Exception $e) {
                setFlash('error', 'অনুরোধ পাঠানো যায়নি।');
            }
            redirect($_SERVER['PHP_SELF'] . '?id=' . $id);
        } elseif ($mAction === 'confirm' && $mutualBlacklist) {
            try {
                Database::update('mutual_blacklist',
                    ['status' => 'confirmed', 'confirmed_by' => $user['company_id'],
                     'confirmed_at' => date('Y-m-d H:i:s')],
                    'id = ?', [$mutualBlacklist['id']]
                );
                setFlash('success', 'যৌথ ব্ল্যাকলিস্ট নিশ্চিত হয়েছে।');
            } catch (Exception $e) {}
            redirect($_SERVER['PHP_SELF'] . '?id=' . $id);
        } elseif ($mAction === 'reject' && $mutualBlacklist) {
            try {
                Database::update('mutual_blacklist',
                    ['status' => 'rejected'], 'id = ?', [$mutualBlacklist['id']]
                );
                setFlash('success', 'অনুরোধ প্রত্যাখ্যান করা হয়েছে।');
            } catch (Exception $e) {}
            redirect($_SERVER['PHP_SELF'] . '?id=' . $id);
        }
    }
}
$canDelete = hasPermission('defaulters','delete_any') ||
             (hasPermission('defaulters','delete_own') && $defaulter['company_id'] == $user['company_id']);
$canResolve = hasPermission('defaulters','mark_done') && $defaulter['company_id'] == $user['company_id']
              && $defaulter['status'] === 'active';

// Other company entries for same phone
$samePhone = Database::fetchAll(
    "SELECT d.id, d.status, d.due_amount, d.created_at, c.company_name
     FROM defaulters d JOIN companies c ON c.id = d.company_id
     WHERE d.customer_phone = ? AND d.id != ?
     ORDER BY d.created_at DESC LIMIT 10",
    [$defaulter['customer_phone'], $id]
);

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div>
        <h1><?= htmlspecialchars($defaulter['customer_name']) ?></h1>
        <nav><ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= SITE_URL ?>/dashboard.php">হোম</a></li>
            <li class="breadcrumb-item"><a href="list.php">বকেয়া তালিকা</a></li>
            <li class="breadcrumb-item active">বিবরণ</li>
        </ol></nav>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($canEdit): ?>
        <a href="edit.php?id=<?= $id ?>" class="btn btn-outline-warning btn-sm">
            <i class="bi bi-pencil me-1"></i>সম্পাদনা
        </a>
        <a href="legal_notice.php?id=<?= $id ?>" class="btn btn-outline-danger btn-sm">
            <i class="bi bi-file-earmark-text me-1"></i>আইনি নোটিশ
        </a>
        <a href="court_cases.php?id=<?= $id ?>" class="btn btn-outline-dark btn-sm">
            <i class="bi bi-bank me-1"></i>মামলা ট্র্যাকার
        </a>
        <?php endif; ?>
        <?php if ($canResolve): ?>
        <?php if ($paymentTrackingOn): ?>
        <a href="payments.php?id=<?= $id ?>" class="btn btn-primary btn-sm">
            <i class="bi bi-cash-coin me-1"></i>পেমেন্ট ট্র্যাকিং
        </a>
        <?php endif; ?>
        <a href="resolve.php?id=<?= $id ?>" class="btn btn-success btn-sm">
            <i class="bi bi-check-circle me-1"></i>সমাধান চিহ্নিত করুন
        </a>
        <?php endif; ?>
        <?php if ($canDelete): ?>
        <a href="delete.php?id=<?= $id ?>" class="btn btn-outline-danger btn-sm"
           data-confirm="এই এন্ট্রি মুছে ফেলবেন?">
            <i class="bi bi-trash me-1"></i>মুছুন
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Status Banner -->
<?php if ($defaulter['status'] === 'resolved'): ?>
<div class="alert mb-3" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;color:#166534;padding:16px 20px;">
    <div class="d-flex align-items-center gap-3 flex-wrap">
        <i class="bi bi-check-circle-fill fs-4"></i>
        <div class="flex-grow-1">
            <?php
            $resTypes = [
                'full_payment'    => ['icon'=>'check-circle-fill', 'label'=>'পূর্ণ পরিশোধ',      'color'=>'#16a34a'],
                'partial_payment' => ['icon'=>'circle-half',       'label'=>'আংশিক পরিশোধ',     'color'=>'#2563a8'],
                'waived'          => ['icon'=>'hand-thumbs-up-fill','label'=>'সম্পূর্ণ মাফ',      'color'=>'#d97706'],
                'other'           => ['icon'=>'check2-circle',     'label'=>'অন্য উপায়ে সমাধান','color'=>'#64748b'],
            ];
            $rt = $resTypes[$defaulter['resolution_type'] ?? ''] ?? $resTypes['other'];
            ?>
            <div class="fw-bold" style="font-size:15px;">
                <i class="bi bi-<?= $rt['icon'] ?> me-1" style="color:<?= $rt['color'] ?>"></i>
                <?= $rt['label'] ?>
            </div>
            <div style="font-size:13px;margin-top:2px;">
                <?= $defaulter['resolved_by_name'] ? 'সমাধান করেছেন: <strong>' . htmlspecialchars($defaulter['resolved_by_name']) . '</strong>' : '' ?>
                <?= $defaulter['resolved_at'] ? ' &nbsp;·&nbsp; ' . formatDate($defaulter['resolved_at']) : '' ?>
            </div>
        </div>
        <!-- Payment/Waiver Summary -->
        <div class="d-flex gap-3" style="font-size:13px;">
            <?php if ((float)($defaulter['payment_amount'] ?? 0) > 0): ?>
            <div class="text-center">
                <div class="fw-bold" style="color:#16a34a;font-size:16px;">৳<?= number_format($defaulter['payment_amount']) ?></div>
                <div style="font-size:10px;color:#64748b;">আদায়</div>
            </div>
            <?php endif; ?>
            <?php if ((float)($defaulter['waiver_amount'] ?? 0) > 0): ?>
            <div class="text-center">
                <div class="fw-bold" style="color:#d97706;font-size:16px;">৳<?= number_format($defaulter['waiver_amount']) ?></div>
                <div style="font-size:10px;color:#64748b;">মাফ/ছাড়</div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($defaulter['resolution_note']): ?>
    <div class="mt-2 pt-2" style="border-top:1px solid #bbf7d0;font-size:12px;color:#166534;">
        <i class="bi bi-chat-text me-1"></i><?= htmlspecialchars($defaulter['resolution_note']) ?>
    </div>
    <?php endif; ?>
</div>
<?php elseif ($defaulter['status'] === 'disputed'): ?>
<div class="alert d-flex align-items-center gap-3 mb-3"
     style="background:#fffbeb;border:1px solid #fde68a;border-radius:12px;color:#92400e;">
    <i class="bi bi-flag-fill fs-4"></i>
    <strong>এই এন্ট্রিতে বিরোধ আছে।</strong>
</div>
<?php endif; ?>

<div class="row g-3">
<!-- ====== Left ====== -->
<div class="col-lg-8">

    <!-- Customer Card -->
    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3">
                <!-- Photo -->
                <div class="col-auto">
                    <?php $primary = array_filter($photos, fn($p) => $p['is_primary']); $primary = reset($primary); ?>
                    <?php if ($primary): ?>
                    <img src="<?= UPLOAD_URL . $primary['photo_path'] ?>" alt=""
                         style="width:100px;height:100px;border-radius:12px;object-fit:cover;border:3px solid #e2e8f0;cursor:pointer;"
                         onclick="openLightbox('<?= UPLOAD_URL . $primary['photo_path'] ?>')">
                    <?php else: ?>
                    <div style="width:100px;height:100px;border-radius:12px;background:#f1f5f9;border:3px solid #e2e8f0;
                                display:flex;align-items:center;justify-content:center;font-size:40px;color:#cbd5e1;">
                        <i class="bi bi-person"></i>
                    </div>
                    <?php endif; ?>
                </div>
                <!-- Info -->
                <div class="col">
                    <div class="d-flex align-items-start gap-2 flex-wrap mb-1">
                        <h4 class="fw-bold mb-0" style="color:#0f2238;"><?= htmlspecialchars($defaulter['customer_name']) ?></h4>
                        <span class="risk-badge risk-<?= $defaulter['risk_level'] ?>"><?= getStatusLabel($defaulter['risk_level']) ?></span>
                        <span class="badge bg-<?= getBadgeClass($defaulter['status']) ?> rounded-pill"><?= getStatusLabel($defaulter['status']) ?></span>
                        <?php if ($creditScore['is_repeat']): ?>
                        <?= CreditScore::repeatBadge($creditScore['companies']) ?>
                        <?php endif; ?>
                        <?php if ($creditScore['blacklist']['level'] !== 'clean'): ?>
                        <?= CreditScore::blacklistBadge($creditScore['blacklist']) ?>
                        <?php endif; ?>
                    </div>
                    <div class="row g-2 mt-1" style="font-size:14px;">
                        <div class="col-sm-6">
                            <i class="bi bi-telephone text-muted me-1"></i>
                            <a href="tel:<?= $defaulter['customer_phone'] ?>" class="text-dark text-decoration-none fw-semibold">
                                <?= htmlspecialchars($defaulter['customer_phone']) ?>
                            </a>
                        </div>
                        <?php if ($defaulter['alt_phone']): ?>
                        <div class="col-sm-6">
                            <i class="bi bi-telephone-forward text-muted me-1"></i>
                            <?= htmlspecialchars($defaulter['alt_phone']) ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($defaulter['nid_number']): ?>
                        <div class="col-sm-6">
                            <i class="bi bi-card-text text-muted me-1"></i>NID: <?= htmlspecialchars($defaulter['nid_number']) ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($defaulter['email']): ?>
                        <div class="col-sm-6">
                            <i class="bi bi-envelope text-muted me-1"></i><?= htmlspecialchars($defaulter['email']) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <!-- Due Amount -->
                <div class="col-auto text-end">
                    <div style="background:#fef2f2;border:2px solid #fecaca;border-radius:12px;padding:14px 20px;text-align:center;">
                        <div style="font-size:11px;color:#dc2626;font-weight:600;letter-spacing:.5px;text-transform:uppercase;">বকেয়া</div>
                        <div style="font-size:26px;font-weight:800;color:#dc2626;line-height:1.1;"><?= formatMoney($defaulter['due_amount']) ?></div>
                        <div style="font-size:11px;color:#94a3b8;"><?= htmlspecialchars($defaulter['service_period'] ?? '') ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Address -->
    <div class="card mb-3">
        <div class="card-header"><h6 class="card-title"><i class="bi bi-geo-alt me-2"></i>ঠিকানা ও অবস্থান</h6></div>
        <div class="card-body">
            <div class="mb-3">
                <div class="fw-semibold" style="font-size:14px;"><?= htmlspecialchars($defaulter['address_text']) ?></div>
                <div class="text-muted small mt-1">
                    <?= implode(', ', array_filter([
                        $defaulter['area'], $defaulter['thana'], $defaulter['district']
                    ])) ?>
                </div>
            </div>
            <?php if ($defaulter['lat'] && $defaulter['lng']): ?>
            <?php if ($mapsKey): ?>
            <div id="detailMap" style="height:280px;border-radius:10px;border:2px solid #e2e8f0;"></div>
            <div class="d-flex gap-2 mt-2">
                <a href="https://maps.google.com/?q=<?= $defaulter['lat'] ?>,<?= $defaulter['lng'] ?>" target="_blank"
                   class="btn btn-sm btn-outline-success" style="border-radius:8px;">
                    <i class="bi bi-map me-1"></i>Google Maps এ খুলুন
                </a>
                <a href="https://maps.google.com/maps?daddr=<?= $defaulter['lat'] ?>,<?= $defaulter['lng'] ?>" target="_blank"
                   class="btn btn-sm btn-outline-primary" style="border-radius:8px;">
                    <i class="bi bi-navigation me-1"></i>দিকনির্দেশনা পান
                </a>
            </div>
            <?php else: ?>
            <div class="text-muted small">
                <i class="bi bi-geo-alt-fill text-success me-1"></i>
                লোকেশন পিন আছে: <?= $defaulter['lat'] ?>, <?= $defaulter['lng'] ?>
                <a href="https://maps.google.com/?q=<?= $defaulter['lat'] ?>,<?= $defaulter['lng'] ?>" target="_blank" class="ms-2">
                    Google Maps এ দেখুন →
                </a>
            </div>
            <?php endif; ?>
            <?php else: ?>
            <div class="text-muted small"><i class="bi bi-geo-alt text-muted me-1"></i>ম্যাপ পিন দেওয়া হয়নি</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Photos Gallery -->
    <?php if (!empty($photos)): ?>
    <div class="card mb-3">
        <div class="card-header"><h6 class="card-title"><i class="bi bi-images me-2"></i>ছবি (<?= count($photos) ?>টি)</h6></div>
        <div class="card-body">
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($photos as $photo): ?>
                <div style="position:relative;">
                    <img src="<?= UPLOAD_URL . $photo['photo_path'] ?>" alt=""
                         style="width:100px;height:100px;border-radius:10px;object-fit:cover;
                                border:2px solid #e2e8f0;cursor:pointer;transition:transform .2s;"
                         onmouseover="this.style.transform='scale(1.05)'"
                         onmouseout="this.style.transform='scale(1)'"
                         onclick="openLightbox('<?= UPLOAD_URL . $photo['photo_path'] ?>')">
                    <?php if ($photo['is_primary']): ?>
                    <span style="position:absolute;top:4px;left:4px;background:#1a3a5c;color:#fff;
                                 font-size:9px;padding:2px 5px;border-radius:4px;">প্রধান</span>
                    <?php endif; ?>
                    <div class="text-center mt-1 text-muted" style="font-size:10px;">
                        <?= match($photo['photo_type']) {
                            'customer_face' => 'গ্রাহক',
                            'nid_front' => 'NID সামনে',
                            'nid_back'  => 'NID পেছনে',
                            'equipment' => 'সরঞ্জাম',
                            default => 'অন্যান্য'
                        } ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Disputes -->
    <?php if (!empty($disputes)): ?>
    <div class="card mb-3">
        <div class="card-header"><h6 class="card-title"><i class="bi bi-flag me-2"></i>বিরোধ (<?= count($disputes) ?>টি)</h6></div>
        <div class="card-body p-0">
            <?php foreach ($disputes as $dis): ?>
            <div class="p-3 border-bottom">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="fw-semibold small"><?= htmlspecialchars($dis['raised_by_name']) ?>
                            <span class="text-muted">/ <?= htmlspecialchars($dis['raised_by_company']) ?></span>
                        </div>
                        <div style="font-size:13px;margin-top:4px;"><?= htmlspecialchars($dis['reason']) ?></div>
                        <?php if ($dis['admin_note']): ?>
                        <div class="mt-1 p-2 rounded" style="background:#f0fdf4;font-size:12px;color:#166534;">
                            <i class="bi bi-reply me-1"></i><?= htmlspecialchars($dis['admin_note']) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <span class="badge bg-<?= getBadgeClass($dis['status']) ?> rounded-pill ms-2"><?= $dis['status'] ?></span>
                </div>
                <div class="text-muted mt-1" style="font-size:11px;"><?= timeAgo($dis['created_at']) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Raise Dispute -->
    <?php if ($defaulter['status'] === 'active' && $defaulter['company_id'] != $user['company_id']): ?>
    <div class="card mb-3">
        <div class="card-header"><h6 class="card-title"><i class="bi bi-flag me-2"></i>বিরোধ উত্থাপন করুন</h6></div>
        <div class="card-body">
            <form method="POST" action="disputes.php">
                <?= csrfField() ?>
                <input type="hidden" name="defaulter_id" value="<?= $id ?>">
                <input type="hidden" name="action" value="raise">
                <textarea name="reason" class="form-control mb-2" rows="2" required
                          placeholder="এই এন্ট্রিতে আপত্তির কারণ লিখুন..."></textarea>
                <button type="submit" class="btn btn-warning btn-sm">
                    <i class="bi bi-flag me-1"></i>বিরোধ উত্থাপন করুন
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ====== Right ====== -->
<div class="col-lg-4">

    <!-- A-1.4: Credit Score Card -->
    <div class="card mb-3" style="border:2px solid <?= $creditScore['label']['border'] ?>;background:<?= $creditScore['label']['bg'] ?>;">
        <div class="card-body p-3">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#64748b;font-weight:600;">
                    ক্রেডিট স্কোর
                </div>
                <a href="<?= SITE_URL ?>/modules/customer/profile.php?phone=<?= urlencode($defaulter['customer_phone']) ?>"
                   style="font-size:11px;color:#2563a8;text-decoration:none;">
                    সম্পূর্ণ প্রোফাইল →
                </a>
            </div>
            <div class="d-flex align-items-end gap-3 mb-2">
                <div style="font-size:48px;font-weight:800;color:<?= $creditScore['label']['color'] ?>;line-height:1;">
                    <?= $creditScore['score'] ?>
                </div>
                <div style="flex:1;padding-bottom:6px;">
                    <?= CreditScore::scoreBar($creditScore['score'], false) ?>
                    <div class="mt-1 fw-semibold" style="font-size:12px;color:<?= $creditScore['label']['color'] ?>;">
                        <?= $creditScore['label']['text'] ?>
                    </div>
                </div>
            </div>

            <!-- Blacklist Level Badge -->
            <div class="mb-2">
                <?= CreditScore::blacklistBadge($creditScore['blacklist']) ?>
                <?php if ($creditScore['is_repeat']): ?>
                <div class="mt-1"><?= CreditScore::repeatBadge($creditScore['companies']) ?></div>
                <?php endif; ?>
            </div>

            <!-- Quick Stats -->
            <div class="row g-1 text-center" style="font-size:12px;">
                <div class="col-4" style="background:rgba(255,255,255,.6);border-radius:6px;padding:6px 4px;">
                    <div class="fw-bold text-danger"><?= $creditScore['active_count'] ?></div>
                    <div class="text-muted" style="font-size:10px;">সক্রিয়</div>
                </div>
                <div class="col-4" style="background:rgba(255,255,255,.6);border-radius:6px;padding:6px 4px;">
                    <div class="fw-bold text-success"><?= $creditScore['resolved_count'] ?></div>
                    <div class="text-muted" style="font-size:10px;">সমাধান</div>
                </div>
                <div class="col-4" style="background:rgba(255,255,255,.6);border-radius:6px;padding:6px 4px;">
                    <div class="fw-bold" style="color:#7c3aed;"><?= $creditScore['companies'] ?></div>
                    <div class="text-muted" style="font-size:10px;">কোম্পানি</div>
                </div>
            </div>

            <!-- Score Breakdown -->
            <?php if (!empty($creditScore['details'])): ?>
            <hr style="border-color:<?= $creditScore['label']['border'] ?>;margin:10px 0;">
            <div style="font-size:11px;">
                <?php foreach ($creditScore['details'] as $det): ?>
                <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted">
                        <i class="bi bi-<?= $det['icon'] ?> me-1"></i><?= $det['label'] ?>
                    </span>
                    <span class="fw-bold" style="color:<?= $det['points'] > 0 ? '#16a34a' : '#dc2626' ?>">
                        <?= $det['points'] > 0 ? '+' : '' ?><?= $det['points'] ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Classification -->
    <div class="card mb-3">
        <div class="card-header"><h6 class="card-title"><i class="bi bi-info-circle me-2"></i>বিবরণ</h6></div>
        <div class="card-body" style="font-size:13px;">
            <table class="table table-sm table-borderless mb-0">
                <tr>
                    <td class="text-muted" style="width:110px;">ধরন</td>
                    <td><span class="badge rounded-pill" style="background:#f1f5f9;color:#475569;"><?= getStatusLabel($defaulter['type']) ?></span></td>
                </tr>
                <tr>
                    <td class="text-muted">সংযোগ</td>
                    <td><?= match($defaulter['connection_type']) { 'home'=>'আবাসিক','office'=>'অফিস','corporate'=>'কর্পোরেট',default=>'অন্যান্য' } ?></td>
                </tr>
                <tr>
                    <td class="text-muted">দেখা হয়েছে</td>
                    <td><?= $defaulter['view_count'] ?> বার</td>
                </tr>
                <tr>
                    <td class="text-muted">যোগ তারিখ</td>
                    <td><?= formatDate($defaulter['created_at']) ?></td>
                </tr>
                <tr>
                    <td class="text-muted">শেষ আপডেট</td>
                    <td><?= formatDate($defaulter['updated_at']) ?></td>
                </tr>
            </table>
            <?php if ($defaulter['description']): ?>
            <hr>
            <div class="text-muted small"><?= nl2br(htmlspecialchars($defaulter['description'])) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Entered by Company -->
    <div class="card mb-3">
        <div class="card-header"><h6 class="card-title"><i class="bi bi-building me-2"></i>এন্ট্রিকারী কোম্পানি</h6></div>
        <div class="card-body" style="font-size:13px;">
            <div class="fw-semibold mb-1"><?= htmlspecialchars($defaulter['company_name']) ?></div>
            <div class="text-muted"><i class="bi bi-person me-1"></i><?= htmlspecialchars($defaulter['entered_by_name']) ?></div>
            <div class="text-muted"><i class="bi bi-telephone me-1"></i><?= htmlspecialchars($defaulter['company_phone']) ?></div>
            <div class="text-muted"><i class="bi bi-envelope me-1"></i><?= htmlspecialchars($defaulter['company_email']) ?></div>
        </div>
    </div>

    <!-- Same phone other entries -->
    <?php if (!empty($samePhone)): ?>
    <div class="card mb-3">
        <div class="card-header"><h6 class="card-title text-danger"><i class="bi bi-exclamation-triangle me-2"></i>একই নম্বরের অন্য এন্ট্রি</h6></div>
        <div class="card-body p-0">
            <?php foreach ($samePhone as $sp): ?>
            <a href="view.php?id=<?= $sp['id'] ?>"
               class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom text-decoration-none text-dark"
               style="font-size:13px;">
                <div>
                    <div class="fw-semibold"><?= htmlspecialchars($sp['company_name']) ?></div>
                    <div class="text-muted small"><?= timeAgo($sp['created_at']) ?></div>
                </div>
                <div class="text-end">
                    <div style="color:#dc2626;font-weight:700;"><?= formatMoney($sp['due_amount']) ?></div>
                    <span class="badge bg-<?= getBadgeClass($sp['status']) ?> rounded-pill" style="font-size:10px;"><?= getStatusLabel($sp['status']) ?></span>
                </div>
            </a>
            <?php endforeach; ?>

            <!-- B-3.3: Mutual Blacklist -->
            <?php if ($defaulter['company_id'] != $user['company_id']): ?>
            <div class="px-3 py-2 border-top" style="background:#fef2f2;">
                <?php if (!$mutualBlacklist || $mutualBlacklist['status'] === 'rejected'): ?>
                <button class="btn btn-sm btn-outline-danger" style="font-size:12px;border-radius:20px;"
                        onclick="document.getElementById('mutualBlacklistForm').style.display='block';this.style.display='none'">
                    <i class="bi bi-shield-exclamation me-1"></i>যৌথ ব্ল্যাকলিস্ট অনুরোধ
                </button>
                <form id="mutualBlacklistForm" method="POST" style="display:none;margin-top:8px;">
                    <?= csrfField() ?>
                    <input type="hidden" name="mutual_action" value="request">
                    <textarea name="reason" class="form-control form-control-sm mb-2" rows="2"
                              placeholder="ব্ল্যাকলিস্টের কারণ লিখুন..." style="font-size:12px;"></textarea>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-danger btn-sm">পাঠান</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm"
                                onclick="this.closest('form').style.display='none';document.querySelector('.btn-outline-danger').style.display='inline-block'">বাতিল</button>
                    </div>
                </form>

                <?php elseif ($mutualBlacklist['status'] === 'pending'): ?>
                    <?php if ($mutualBlacklist['requested_by'] == $user['company_id']): ?>
                    <div style="font-size:12px;color:#d97706;">
                        <i class="bi bi-hourglass-split me-1"></i>যৌথ ব্ল্যাকলিস্ট অনুরোধ অপেক্ষায় আছে
                    </div>
                    <?php else: ?>
                    <div style="font-size:12px;font-weight:600;color:#dc2626;margin-bottom:6px;">
                        <i class="bi bi-shield-exclamation me-1"></i>
                        <strong><?= htmlspecialchars($mutualBlacklist['requester_name']) ?></strong> যৌথ ব্ল্যাকলিস্টের অনুরোধ করেছে
                    </div>
                    <div class="d-flex gap-2">
                        <form method="POST" style="display:inline;">
                            <?= csrfField() ?><input type="hidden" name="mutual_action" value="confirm">
                            <button type="submit" class="btn btn-danger btn-sm">নিশ্চিত করুন</button>
                        </form>
                        <form method="POST" style="display:inline;">
                            <?= csrfField() ?><input type="hidden" name="mutual_action" value="reject">
                            <button type="submit" class="btn btn-outline-secondary btn-sm">প্রত্যাখ্যান</button>
                        </form>
                    </div>
                    <?php endif; ?>

                <?php elseif ($mutualBlacklist['status'] === 'confirmed'): ?>
                <div style="font-size:12px;color:#dc2626;font-weight:600;">
                    <i class="bi bi-shield-fill-exclamation me-1"></i>যৌথভাবে ব্ল্যাকলিস্টেড ✅
                </div>
                <?php endif; ?>

            </div>
            <?php endif; ?>

            <!-- B-3.4: Message Button -->
            <?php if ($defaulter['company_id'] != $user['company_id']): ?>
            <div class="px-3 py-2 border-top">
                <a href="<?= SITE_URL ?>/modules/company/messages.php?defaulter_id=<?= $id ?>&to=<?= $defaulter['company_id'] ?>"
                   class="btn btn-sm btn-outline-primary" style="font-size:12px;border-radius:20px;">
                    <i class="bi bi-chat-dots me-1"></i>এই কোম্পানিকে মেসেজ করুন
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Activity Log -->
    <?php if (!empty($logs)): ?>
    <div class="card">
        <div class="card-header"><h6 class="card-title"><i class="bi bi-clock-history me-2"></i>ইতিহাস</h6></div>
        <div class="card-body p-0">
            <?php foreach ($logs as $log): ?>
            <div class="px-3 py-2 border-bottom" style="font-size:12px;">
                <div class="fw-semibold"><?= htmlspecialchars($log['description'] ?? $log['action']) ?></div>
                <div class="text-muted"><?= htmlspecialchars($log['full_name'] ?? 'System') ?> — <?= timeAgo($log['created_at']) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div>
</div>

<!-- Lightbox -->
<div id="lightbox" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.9);z-index:9999;align-items:center;justify-content:center;cursor:pointer;"
     onclick="this.style.display='none'">
    <img id="lightboxImg" src="" style="max-width:90vw;max-height:90vh;border-radius:8px;">
</div>

<?php if ($mapsKey && $defaulter['lat'] && $defaulter['lng']): ?>
<script>
function initMap() {
    const pos = { lat: <?= (float)$defaulter['lat'] ?>, lng: <?= (float)$defaulter['lng'] ?> };
    const map = new google.maps.Map(document.getElementById('detailMap'), { zoom: 16, center: pos });
    const marker = new google.maps.Marker({ position: pos, map, title: '<?= addslashes($defaulter['customer_name']) ?>' });
    const info = new google.maps.InfoWindow({
        content: `<div style="font-family:'Hind Siliguri',sans-serif;padding:4px;">
            <strong><?= htmlspecialchars($defaulter['customer_name']) ?></strong><br>
            <span style="color:#dc2626;"><?= formatMoney($defaulter['due_amount']) ?> বকেয়া</span>
        </div>`
    });
    marker.addListener('click', () => info.open(map, marker));
}
</script>
<script src="https://maps.googleapis.com/maps/api/js?key=<?= htmlspecialchars($mapsKey) ?>&callback=initMap" async defer></script>
<?php endif; ?>

<script>
function openLightbox(src) {
    document.getElementById('lightboxImg').src = src;
    document.getElementById('lightbox').style.display = 'flex';
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
