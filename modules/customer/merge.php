<?php
// ============================================================
// MERGE DUPLICATE CUSTOMERS — A-2.3
// File: modules/customer/merge.php
// দুটো একই গ্রাহকের এন্ট্রি একসাথে মার্জ করা
// ============================================================
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/credit_score.php';
requirePermission('defaulters', 'edit_own');

$user      = getCurrentUser();
$pageTitle = 'ডুপ্লিকেট মার্জ করুন';

$idA = (int)($_GET['a'] ?? $_POST['id_a'] ?? 0);
$idB = (int)($_GET['b'] ?? $_POST['id_b'] ?? 0);

$errors  = [];
$success = false;

// ── ফোন দিয়ে দ্বিতীয় এন্ট্রি খুঁজে পাওয়ার helper ─────────
$searchPhone  = trim($_GET['search_phone'] ?? '');
$searchResult = [];
if ($searchPhone) {
    $searchResult = Database::fetchAll(
        "SELECT d.id, d.customer_name, d.customer_phone, d.nid_number,
                d.due_amount, d.status, d.risk_level, d.created_at, c.company_name,
                (SELECT photo_path FROM defaulter_photos WHERE defaulter_id=d.id AND is_primary=1 LIMIT 1) as photo
         FROM defaulters d JOIN companies c ON c.id=d.company_id
         WHERE d.customer_phone LIKE ? AND d.status != 'removed'
         ORDER BY d.status='active' DESC, d.created_at DESC LIMIT 20",
        ["%$searchPhone%"]
    );
}

// ── দুটো এন্ট্রি লোড ────────────────────────────────────────
$entryQuery = "SELECT d.*, c.company_name, u.full_name as entered_by_name,
        (SELECT photo_path FROM defaulter_photos WHERE defaulter_id=d.id AND is_primary=1 LIMIT 1) as photo
     FROM defaulters d JOIN companies c ON c.id=d.company_id
     JOIN users u ON u.id=d.entered_by
     WHERE d.id=? AND d.status!='removed'";

$entryA = $idA ? Database::fetchOne($entryQuery, [$idA]) : null;
$entryB = $idB ? Database::fetchOne($entryQuery, [$idB]) : null;

// ── MERGE সম্পন্ন করা ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_merge'])) {
    if (!validateCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $errors[] = 'নিরাপত্তা যাচাই ব্যর্থ।';
    } elseif (!$entryA || !$entryB) {
        $errors[] = 'উভয় এন্ট্রি পাওয়া যায়নি।';
    } else {
        // Permission: যেকোনো একটি নিজের কোম্পানির হতে হবে
        $isAdmin = in_array($user['role_name'], ['super_admin', 'admin']);
        $canMerge = $isAdmin
            || $entryA['company_id'] == $user['company_id']
            || $entryB['company_id'] == $user['company_id'];

        if (!$canMerge) {
            $errors[] = 'এই এন্ট্রি মার্জ করার অনুমতি নেই।';
        } else {
            // কোনটা রাখা হবে (keep) কোনটা মুছবে (remove)
            $keepId   = (int)$_POST['keep_id'];
            $removeId = $keepId === $idA ? $idB : $idA;
            $keepEntry   = $keepId === $idA ? $entryA : $entryB;
            $removeEntry = $keepId === $idA ? $entryB : $entryA;

            // override fields from form
            $mergedName  = trim($_POST['merged_name']  ?? $keepEntry['customer_name']);
            $mergedPhone = trim($_POST['merged_phone'] ?? $keepEntry['customer_phone']);
            $mergedNID   = trim($_POST['merged_nid']   ?? $keepEntry['nid_number'] ?? '');

            try {
                Database::beginTransaction();

                // 1. keep এর তথ্য আপডেট (সেরা তথ্য নিয়ে)
                $updateData = [
                    'customer_name'  => $mergedName,
                    'customer_phone' => $mergedPhone,
                    'nid_number'     => $mergedNID ?: null,
                    // NID না থাকলে remove entry থেকে নেওয়া
                    'alt_phone'      => $_POST['merged_alt_phone'] ?? $keepEntry['alt_phone'],
                    'address_text'   => $_POST['merged_address']   ?? $keepEntry['address_text'],
                    'area'           => $_POST['merged_area']       ?? $keepEntry['area'],
                ];
                // NID সিলেক্ট করা
                if (empty($mergedNID) && !empty($removeEntry['nid_number'])) {
                    $updateData['nid_number'] = $removeEntry['nid_number'];
                }
                Database::update('defaulters', $updateData, 'id = ?', [$keepId]);

                // 2. remove entry এর photos → keep entry তে স্থানান্তর
                Database::update('defaulter_photos',
                    ['defaulter_id' => $keepId, 'is_primary' => 0],
                    'defaulter_id = ?', [$removeId]
                );

                // 3. remove entry এর disputes → keep entry তে
                Database::update('disputes',
                    ['defaulter_id' => $keepId],
                    'defaulter_id = ?', [$removeId]
                );

                // 4. remove entry কে removed করা (soft delete)
                Database::update('defaulters',
                    ['status' => 'removed', 'resolution_note' => "মার্জ: ID #{$keepId} এ মিলিত হয়েছে"],
                    'id = ?', [$removeId]
                );

                // 5. Activity log
                logActivity('customer.merge', 'defaulters', [
                    'target_id'   => $keepId,
                    'target_type' => 'defaulters',
                    'description' => "#{$removeId} ({$removeEntry['customer_name']}) → #{$keepId} ({$keepEntry['customer_name']}) মার্জ করা হয়েছে",
                    'old_data'    => ['removed_id' => $removeId, 'removed_name' => $removeEntry['customer_name']],
                ]);

                Database::commit();
                $success = true;
                setFlash('success', 'মার্জ সফল হয়েছে! এন্ট্রি #' . $keepId . ' এ মিলিত হয়েছে।');
                redirect(SITE_URL . '/modules/defaulter/view.php?id=' . $keepId);

            } catch (Exception $e) {
                Database::rollback();
                $errors[] = 'মার্জ ব্যর্থ হয়েছে। আবার চেষ্টা করুন।';
            }
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div>
        <h1>ডুপ্লিকেট মার্জ করুন</h1>
        <nav><ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= SITE_URL ?>/dashboard.php">হোম</a></li>
            <li class="breadcrumb-item"><a href="duplicates.php">ডুপ্লিকেট</a></li>
            <li class="breadcrumb-item active">মার্জ</li>
        </ol></nav>
    </div>
    <a href="duplicates.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>ফিরে যান
    </a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger rounded-3 mb-3">
    <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── STEP 1: Search / Select entries ─────────────────── -->
<?php if (!$entryA || !$entryB): ?>

<div class="row g-3">
<!-- Search Panel -->
<div class="col-lg-5">
    <div class="card">
        <div class="card-header">
            <h6 class="card-title"><i class="bi bi-search me-2"></i>এন্ট্রি খুঁজুন</h6>
        </div>
        <div class="card-body">
            <form method="GET">
                <div class="mb-3">
                    <label class="form-label">মোবাইল নম্বর বা নাম দিয়ে খুঁজুন</label>
                    <?php if ($idA): ?><input type="hidden" name="a" value="<?= $idA ?>"> <?php endif; ?>
                    <div class="input-group">
                        <input type="text" name="search_phone" class="form-control"
                               value="<?= htmlspecialchars($searchPhone) ?>"
                               placeholder="01XXXXXXXXX বা নাম">
                        <button class="btn btn-primary">খুঁজুন</button>
                    </div>
                </div>
            </form>

            <?php if (!empty($searchResult)): ?>
            <div style="font-size:13px;font-weight:600;color:#64748b;margin-bottom:8px;">
                <?= count($searchResult) ?>টি এন্ট্রি পাওয়া গেছে
            </div>
            <?php foreach ($searchResult as $r): ?>
            <?php if ($r['id'] == $idA) continue; // নিজেকে বাদ ?>
            <div class="d-flex align-items-center gap-2 p-2 rounded mb-2"
                 style="background:#f8fafc;border:1px solid #e2e8f0;font-size:12px;">
                <?php if ($r['photo']): ?>
                <img src="<?= UPLOAD_URL . $r['photo'] ?>" style="width:36px;height:36px;border-radius:50%;object-fit:cover;flex-shrink:0;">
                <?php else: ?>
                <div style="width:36px;height:36px;border-radius:50%;background:#1a3a5c;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0;">
                    <?= mb_substr($r['customer_name'],0,1,'UTF-8') ?>
                </div>
                <?php endif; ?>
                <div style="flex:1;min-width:0;">
                    <div class="fw-semibold text-truncate"><?= htmlspecialchars($r['customer_name']) ?></div>
                    <div class="text-muted"><?= htmlspecialchars($r['customer_phone']) ?> · <?= htmlspecialchars($r['company_name']) ?></div>
                </div>
                <div class="text-end">
                    <div style="color:#dc2626;font-weight:700;">৳<?= number_format($r['due_amount']) ?></div>
                    <span class="badge bg-<?= getBadgeClass($r['status']) ?> rounded-pill" style="font-size:10px;"><?= getStatusLabel($r['status']) ?></span>
                </div>
                <a href="?a=<?= $idA ?: $r['id'] ?>&b=<?= $idA ? $r['id'] : '' ?>&search_phone=<?= urlencode($searchPhone) ?>"
                   class="btn btn-sm btn-outline-primary" style="padding:3px 8px;white-space:nowrap;">
                   <?= $idA ? '<i class="bi bi-2-circle me-1"></i>B হিসেবে নির্বাচন' : '<i class="bi bi-1-circle me-1"></i>A হিসেবে নির্বাচন' ?>
                </a>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Selected Entries Preview -->
<div class="col-lg-7">
    <div class="row g-3">
        <!-- Entry A -->
        <div class="col-md-6">
            <div class="card" style="border:2px solid <?= $entryA ? '#2563a8' : '#e2e8f0' ?>;">
                <div class="card-header" style="background:<?= $entryA ? '#eff6ff' : '#f8fafc' ?>;">
                    <h6 class="card-title mb-0" style="color:#2563a8;">
                        <i class="bi bi-1-circle-fill me-1"></i>এন্ট্রি A (রাখা হবে)
                    </h6>
                </div>
                <div class="card-body">
                    <?php if ($entryA): ?>
                    <?= renderEntryCard($entryA) ?>
                    <a href="?<?= http_build_query(array_filter(['b'=>$idB,'search_phone'=>$searchPhone])) ?>"
                       class="btn btn-sm btn-outline-danger mt-2 w-100">পরিবর্তন করুন</a>
                    <?php else: ?>
                    <div class="text-center text-muted py-4" style="font-size:13px;">
                        <i class="bi bi-plus-circle fs-3 d-block mb-2 text-muted"></i>
                        উপরের তালিকা থেকে নির্বাচন করুন
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <!-- Entry B -->
        <div class="col-md-6">
            <div class="card" style="border:2px solid <?= $entryB ? '#dc2626' : '#e2e8f0' ?>;">
                <div class="card-header" style="background:<?= $entryB ? '#fef2f2' : '#f8fafc' ?>;">
                    <h6 class="card-title mb-0" style="color:#dc2626;">
                        <i class="bi bi-2-circle-fill me-1"></i>এন্ট্রি B (মুছে যাবে)
                    </h6>
                </div>
                <div class="card-body">
                    <?php if ($entryB): ?>
                    <?= renderEntryCard($entryB) ?>
                    <a href="?<?= http_build_query(array_filter(['a'=>$idA,'search_phone'=>$searchPhone])) ?>"
                       class="btn btn-sm btn-outline-danger mt-2 w-100">পরিবর্তন করুন</a>
                    <?php else: ?>
                    <div class="text-center text-muted py-4" style="font-size:13px;">
                        <i class="bi bi-plus-circle fs-3 d-block mb-2 text-muted"></i>
                        খুঁজে নির্বাচন করুন
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($entryA && $entryB): ?>
    <div class="text-center mt-3">
        <a href="?a=<?= $idA ?>&b=<?= $idB ?>" class="btn btn-primary btn-lg" style="border-radius:12px;">
            <i class="bi bi-arrow-left-right me-2"></i>এই দুটো মার্জ করুন
        </a>
    </div>
    <?php endif; ?>
</div>
</div>

<?php else: ?>

<!-- ── STEP 2: Merge Confirmation ──────────────────────── -->
<div class="row g-3 justify-content-center">
<div class="col-lg-8">

    <!-- Warning Banner -->
    <div class="alert mb-3" style="background:#fef2f2;border:1px solid #fecaca;border-radius:12px;color:#dc2626;">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-exclamation-triangle-fill fs-5"></i>
            <div>
                <strong>সতর্কতা!</strong> মার্জ করলে B এন্ট্রিটি সরিয়ে দেওয়া হবে।
                B এর সব ছবি ও বিরোধ A তে স্থানান্তর হবে। এটি পূর্বাবস্থায় ফেরানো যাবে না।
            </div>
        </div>
    </div>

    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="id_a" value="<?= $idA ?>">
        <input type="hidden" name="id_b" value="<?= $idB ?>">

        <!-- Side by side comparison -->
        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <div class="card" style="border:2px solid #2563a8;background:#eff6ff;">
                    <div class="card-header" style="background:#dbeafe;">
                        <h6 class="card-title mb-0 text-primary">
                            <i class="bi bi-check-circle-fill me-1"></i>A — রাখা হবে (#<?= $idA ?>)
                        </h6>
                    </div>
                    <div class="card-body"><?= renderEntryCard($entryA) ?></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card" style="border:2px solid #dc2626;background:#fef2f2;">
                    <div class="card-header" style="background:#fee2e2;">
                        <h6 class="card-title mb-0 text-danger">
                            <i class="bi bi-x-circle-fill me-1"></i>B — মুছে যাবে (#<?= $idB ?>)
                        </h6>
                    </div>
                    <div class="card-body"><?= renderEntryCard($entryB) ?></div>
                </div>
            </div>
        </div>

        <!-- কোনটা রাখবেন? -->
        <div class="card mb-3">
            <div class="card-header">
                <h6 class="card-title"><i class="bi bi-toggles me-2"></i>কোন এন্ট্রিটি রাখবেন?</h6>
            </div>
            <div class="card-body">
                <div class="d-flex gap-3 mb-3">
                    <label class="flex-fill p-3 rounded border" style="cursor:pointer;" id="keepA_label">
                        <input type="radio" name="keep_id" value="<?= $idA ?>" checked onchange="updateKeep(<?= $idA ?>)">
                        <span class="ms-2 fw-semibold">A রাখব (#<?= $idA ?> — <?= htmlspecialchars($entryA['customer_name']) ?>)</span>
                    </label>
                    <label class="flex-fill p-3 rounded border" style="cursor:pointer;" id="keepB_label">
                        <input type="radio" name="keep_id" value="<?= $idB ?>" onchange="updateKeep(<?= $idB ?>)">
                        <span class="ms-2 fw-semibold">B রাখব (#<?= $idB ?> — <?= htmlspecialchars($entryB['customer_name']) ?>)</span>
                    </label>
                </div>

                <!-- মার্জড তথ্য -->
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" style="font-size:13px;">চূড়ান্ত নাম</label>
                        <input type="text" name="merged_name" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($entryA['customer_name']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" style="font-size:13px;">চূড়ান্ত ফোন</label>
                        <input type="text" name="merged_phone" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($entryA['customer_phone']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" style="font-size:13px;">চূড়ান্ত NID</label>
                        <input type="text" name="merged_nid" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($entryA['nid_number'] ?? $entryB['nid_number'] ?? '') ?>"
                               placeholder="NID নম্বর">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" style="font-size:13px;">বিকল্প নম্বর</label>
                        <input type="text" name="merged_alt_phone" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($entryA['alt_phone'] ?? $entryB['alt_phone'] ?? '') ?>"
                               placeholder="বিকল্প নম্বর">
                    </div>
                </div>
            </div>
        </div>

        <!-- What happens summary -->
        <div class="card mb-4" style="background:#f0fdf4;border:1px solid #bbf7d0;">
            <div class="card-body" style="font-size:13px;">
                <div class="fw-semibold text-success mb-2"><i class="bi bi-info-circle me-1"></i>মার্জের ফলে যা হবে:</div>
                <div>✅ B এর <strong><?= Database::count('defaulter_photos','defaulter_id=?',[$idB]) ?>টি ছবি</strong> A তে যোগ হবে</div>
                <div>✅ B এর <strong><?= Database::count('disputes','defaulter_id=?',[$idB]) ?>টি বিরোধ</strong> A তে স্থানান্তর হবে</div>
                <div>✅ B এর এন্ট্রি "মুছে ফেলা" হিসেবে চিহ্নিত হবে</div>
                <div>✅ Activity log এ রেকর্ড থাকবে</div>
            </div>
        </div>

        <div class="d-flex gap-2">
            <a href="?a=<?= $idA ?>&b=<?= $idB ?>&cancel=1" class="btn btn-outline-secondary flex-fill">বাতিল</a>
            <button type="submit" name="confirm_merge" value="1"
                    class="btn btn-danger flex-fill fw-semibold"
                    onclick="return confirm('নিশ্চিতভাবে মার্জ করবেন? এটি পূর্বাবস্থায় ফেরানো যাবে না।')">
                <i class="bi bi-arrow-left-right me-2"></i>মার্জ নিশ্চিত করুন
            </button>
        </div>
    </form>
</div>
</div>

<script>
const dataA = {
    name:  '<?= addslashes($entryA['customer_name']) ?>',
    phone: '<?= addslashes($entryA['customer_phone']) ?>',
    nid:   '<?= addslashes($entryA['nid_number'] ?? '') ?>',
    alt:   '<?= addslashes($entryA['alt_phone'] ?? '') ?>',
};
const dataB = {
    name:  '<?= addslashes($entryB['customer_name']) ?>',
    phone: '<?= addslashes($entryB['customer_phone']) ?>',
    nid:   '<?= addslashes($entryB['nid_number'] ?? '') ?>',
    alt:   '<?= addslashes($entryB['alt_phone'] ?? '') ?>',
};
function updateKeep(id) {
    const d = id === <?= $idA ?> ? dataA : dataB;
    document.querySelector('[name=merged_name]').value  = d.name;
    document.querySelector('[name=merged_phone]').value = d.phone;
    document.querySelector('[name=merged_nid]').value   = d.nid || (id===<?=$idA?> ? dataB.nid : dataA.nid);
    document.querySelector('[name=merged_alt_phone]').value = d.alt || (id===<?=$idA?> ? dataB.alt : dataA.alt);
    document.getElementById('keepA_label').style.borderColor = id===<?=$idA?> ? '#2563a8' : '#e2e8f0';
    document.getElementById('keepA_label').style.background  = id===<?=$idA?> ? '#eff6ff' : '#fff';
    document.getElementById('keepB_label').style.borderColor = id===<?=$idB?> ? '#dc2626' : '#e2e8f0';
    document.getElementById('keepB_label').style.background  = id===<?=$idB?> ? '#fef2f2' : '#fff';
}
updateKeep(<?= $idA ?>);
</script>

<?php endif; ?>

<?php
// ── Helper: Entry Card ─────────────────────────────────────
function renderEntryCard(array $e): string {
    $photo = !empty($e['photo']) ? UPLOAD_URL . $e['photo'] : null;
    $html  = '<div class="d-flex align-items-center gap-2 mb-2">';
    if ($photo) {
        $html .= '<img src="' . $photo . '" style="width:44px;height:44px;border-radius:50%;object-fit:cover;">';
    } else {
        $html .= '<div style="width:44px;height:44px;border-radius:50%;background:#1a3a5c;color:#fff;display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:700;">'
               . mb_substr($e['customer_name'],0,1,'UTF-8') . '</div>';
    }
    $html .= '<div><div class="fw-bold" style="font-size:14px;">' . htmlspecialchars($e['customer_name']) . '</div>'
           . '<div class="text-muted" style="font-size:12px;">' . htmlspecialchars($e['customer_phone']) . '</div></div></div>';

    $html .= '<table style="font-size:12px;width:100%;">';
    $html .= '<tr><td class="text-muted" style="width:80px;">কোম্পানি:</td><td class="fw-semibold">' . htmlspecialchars($e['company_name']) . '</td></tr>';
    if ($e['nid_number']) $html .= '<tr><td class="text-muted">NID:</td><td><code style="font-size:11px;">' . htmlspecialchars($e['nid_number']) . '</code></td></tr>';
    $html .= '<tr><td class="text-muted">বকেয়া:</td><td style="color:#dc2626;font-weight:700;">' . formatMoney($e['due_amount']) . '</td></tr>';
    $html .= '<tr><td class="text-muted">অবস্থা:</td><td><span class="badge bg-' . getBadgeClass($e['status']) . ' rounded-pill" style="font-size:10px;">' . getStatusLabel($e['status']) . '</span></td></tr>';
    $html .= '<tr><td class="text-muted">তারিখ:</td><td>' . formatDate($e['created_at'], 'd M Y') . '</td></tr>';
    $html .= '</table>';

    $html .= '<a href="' . SITE_URL . '/modules/defaulter/view.php?id=' . $e['id'] . '" target="_blank" class="btn btn-sm btn-outline-secondary mt-2 w-100" style="font-size:11px;">বিস্তারিত দেখুন ↗</a>';
    return $html;
}
?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
