<?php
// ============================================================
// ADD NEW DEFAULTER ENTRY
// File: modules/defaulter/add.php
// ============================================================
require_once __DIR__ . '/../../includes/functions.php';
requirePermission('defaulters', 'create');

$user      = getCurrentUser();
$pageTitle = 'নতুন বকেয়া এন্ট্রি';
$errors    = [];
$data      = [];
$mapsKey   = getSetting('google_maps_key');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $errors[] = 'নিরাপত্তা যাচাই ব্যর্থ।';
    } else {
        $data = [
            'customer_name'   => trim($_POST['customer_name']  ?? ''),
            'customer_phone'  => trim($_POST['customer_phone'] ?? ''),
            'alt_phone'       => trim($_POST['alt_phone']      ?? ''),
            'nid_number'      => trim($_POST['nid_number']     ?? ''),
            'email'           => trim($_POST['email']          ?? ''),
            'address_text'    => trim($_POST['address_text']   ?? ''),
            'area'            => trim($_POST['area']           ?? ''),
            'thana'           => trim($_POST['thana']          ?? ''),
            'district'        => trim($_POST['district']       ?? ''),
            'lat'             => trim($_POST['lat']            ?? ''),
            'lng'             => trim($_POST['lng']            ?? ''),
            'map_address'     => trim($_POST['map_address']    ?? ''),
            'due_amount'      => trim($_POST['due_amount']     ?? '0'),
            'connection_type' => $_POST['connection_type']     ?? 'home',
            'service_period'  => trim($_POST['service_period'] ?? ''),
            'type'            => $_POST['type']                ?? 'due_payment',
            'risk_level'      => $_POST['risk_level']          ?? 'medium',
            'description'     => trim($_POST['description']    ?? ''),
        ];

        // Validation
        if (empty($data['customer_name']))   $errors[] = 'গ্রাহকের নাম দিন।';
        if (empty($data['customer_phone']) || !preg_match('/^01[3-9]\d{8}$/', $data['customer_phone']))
            $errors[] = 'সঠিক মোবাইল নম্বর দিন।';
        if (empty($data['address_text']))    $errors[] = 'ঠিকানা দিন।';
        if (!is_numeric($data['due_amount']) || $data['due_amount'] < 0)
            $errors[] = 'সঠিক বকেয়া পরিমাণ দিন।';
        if ($data['lat'] && !is_numeric($data['lat']))  $errors[] = 'অক্ষাংশ সঠিক নয়।';
        if ($data['lng'] && !is_numeric($data['lng']))  $errors[] = 'দ্রাঘিমাংশ সঠিক নয়।';

        // Duplicate check by phone + company
        if (empty($errors)) {
            $dup = Database::fetchOne(
                "SELECT id FROM defaulters WHERE customer_phone = ? AND company_id = ? AND status = 'active'",
                [$data['customer_phone'], $user['company_id']]
            );
            if ($dup) {
                $errors[] = 'এই মোবাইল নম্বরে আপনার কোম্পানির সক্রিয় এন্ট্রি আগেই আছে।
                            <a href="' . SITE_URL . '/modules/defaulter/view.php?id=' . $dup['id'] . '">এখানে দেখুন →</a>';
            }
        }

        if (empty($errors)) {
            Database::beginTransaction();
            try {
                $defaulterId = Database::insert('defaulters', [
                    'company_id'     => $user['company_id'],
                    'entered_by'     => $user['id'],
                    'customer_name'  => $data['customer_name'],
                    'customer_phone' => $data['customer_phone'],
                    'alt_phone'      => $data['alt_phone']  ?: null,
                    'nid_number'     => $data['nid_number'] ?: null,
                    'email'          => $data['email']      ?: null,
                    'address_text'   => $data['address_text'],
                    'area'           => $data['area']       ?: null,
                    'thana'          => $data['thana']      ?: null,
                    'district'       => $data['district']   ?: null,
                    'lat'            => $data['lat']        ? (float)$data['lat']  : null,
                    'lng'            => $data['lng']        ? (float)$data['lng']  : null,
                    'map_address'    => $data['map_address'] ?: null,
                    'due_amount'     => (float)$data['due_amount'],
                    'connection_type'=> $data['connection_type'],
                    'service_period' => $data['service_period'] ?: null,
                    'type'           => $data['type'],
                    'risk_level'     => $data['risk_level'],
                    'description'    => $data['description'] ?: null,
                    'status'         => 'active',
                ]);

                // Upload photos
                $photoTypes = $_POST['photo_type'] ?? [];
                if (!empty($_FILES['photos']['name'][0])) {
                    $isPrimary = true;
                    foreach ($_FILES['photos']['tmp_name'] as $idx => $tmpName) {
                        if (!$tmpName || $_FILES['photos']['error'][$idx] !== UPLOAD_ERR_OK) continue;
                        $singleFile = [
                            'name'     => $_FILES['photos']['name'][$idx],
                            'tmp_name' => $tmpName,
                            'size'     => $_FILES['photos']['size'][$idx],
                            'error'    => $_FILES['photos']['error'][$idx],
                        ];
                        $upload = uploadPhoto($singleFile, 'photos');
                        if ($upload['success']) {
                            Database::insert('defaulter_photos', [
                                'defaulter_id' => $defaulterId,
                                'photo_path'   => $upload['path'],
                                'photo_type'   => $photoTypes[$idx] ?? 'customer_face',
                                'is_primary'   => $isPrimary ? 1 : 0,
                                'uploaded_by'  => $user['id'],
                            ]);
                            $isPrimary = false;
                        }
                    }
                }

                // Notify all approved companies
                $allCompanyAdmins = Database::fetchAll(
                    "SELECT u.id, u.company_id FROM users u
                     JOIN companies c ON c.id = u.company_id
                     WHERE c.status = 'approved' AND u.company_id != ? AND u.role_id IN (3,4) AND u.status = 'active'",
                    [$user['company_id']]
                );
                foreach ($allCompanyAdmins as $ca) {
                    createNotification(
                        'নতুন বকেয়া এন্ট্রি',
                        '"' . $data['customer_name'] . '" (' . $data['customer_phone'] . ') — ' . formatMoney((float)$data['due_amount']),
                        ['user_id' => $ca['id'], 'company_id' => $ca['company_id'],
                         'type' => 'warning', 'icon' => 'exclamation-triangle',
                         'link' => SITE_URL . '/modules/defaulter/view.php?id=' . $defaulterId]
                    );
                }

                logActivity('defaulter.create', 'defaulters', [
                    'target_id'   => $defaulterId,
                    'target_type' => 'defaulters',
                    'description' => '"' . $data['customer_name'] . '" বকেয়া এন্ট্রি যোগ করা হয়েছে',
                    'new_data'    => $data,
                ]);

                Database::commit();
                setFlash('success', 'বকেয়া এন্ট্রি সফলভাবে যোগ হয়েছে।');
                redirect(SITE_URL . '/modules/defaulter/view.php?id=' . $defaulterId);

            } catch (Exception $e) {
                Database::rollback();
                $errors[] = 'সংরক্ষণে সমস্যা হয়েছে।' . (DEBUG_MODE ? ' ' . $e->getMessage() : '');
            }
        }
    }
}

$extraHead = '
<style>
#mapPicker { height: 320px; border-radius: 10px; border: 2px solid #e2e8f0; }
.photo-preview-wrap { display:flex; flex-wrap:wrap; gap:10px; margin-top:10px; }
.photo-thumb {
    width:80px; height:80px; border-radius:8px; object-fit:cover;
    border:2px solid #e2e8f0; position:relative;
}
.photo-thumb-wrap { position:relative; }
.photo-thumb-wrap .remove-btn {
    position:absolute; top:-6px; right:-6px; background:#dc2626; color:#fff;
    border:none; border-radius:50%; width:20px; height:20px;
    font-size:12px; cursor:pointer; display:flex; align-items:center; justify-content:center;
    padding:0;
}
.drop-zone {
    border: 2px dashed #e2e8f0; border-radius: 10px; padding: 28px;
    text-align: center; cursor: pointer; transition: all .2s; background: #f8fafc;
}
.drop-zone:hover, .drop-zone.drag-over { border-color: #2563a8; background: #eff6ff; }
.section-card { border-left: 4px solid #2563a8; padding-left: 16px; margin-bottom: 20px; }
.section-card h6 { color: #1a3a5c; font-weight: 700; margin-bottom: 16px; font-size: 14px; text-transform: uppercase; letter-spacing: .5px; }
</style>';

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div>
        <h1>নতুন বকেয়া এন্ট্রি</h1>
        <nav><ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= SITE_URL ?>/dashboard.php">হোম</a></li>
            <li class="breadcrumb-item"><a href="list.php">বকেয়া তালিকা</a></li>
            <li class="breadcrumb-item active">নতুন এন্ট্রি</li>
        </ol></nav>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger rounded-3 mb-4" style="font-size:13px;">
    <strong><i class="bi bi-exclamation-triangle me-1"></i>সমস্যা:</strong>
    <ul class="mb-0 mt-1 ps-3">
        <?php foreach ($errors as $e): ?><li><?= $e ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" id="defaulterForm">
<?= csrfField() ?>

<div class="row g-3">
<!-- ====== Left Column ====== -->
<div class="col-lg-8">

    <!-- Customer Info -->
    <div class="card mb-3">
        <div class="card-header"><h6 class="card-title"><i class="bi bi-person me-2"></i>গ্রাহকের তথ্য</h6></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">গ্রাহকের নাম <span class="required-star">*</span></label>
                    <input type="text" name="customer_name" class="form-control" id="nameInput"
                           value="<?= htmlspecialchars($data['customer_name'] ?? '') ?>"
                           placeholder="পূর্ণ নাম" required
                           onblur="checkNameDuplicate(this.value)">
                    <div id="nameCheckMsg" class="form-text"></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">মোবাইল নম্বর <span class="required-star">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text" style="border-radius:8px 0 0 8px;border:2px solid #e2e8f0;border-right:none;background:#f8fafc;">
                            <i class="bi bi-telephone"></i>
                        </span>
                        <input type="text" name="customer_phone" class="form-control" id="phoneInput"
                               value="<?= htmlspecialchars($data['customer_phone'] ?? '') ?>"
                               placeholder="01XXXXXXXXX" maxlength="11" required
                               style="border-left:none;"
                               oninput="checkDuplicate(this.value)">
                    </div>
                    <div id="phoneCheckMsg" class="form-text"></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">বিকল্প নম্বর</label>
                    <input type="text" name="alt_phone" class="form-control"
                           value="<?= htmlspecialchars($data['alt_phone'] ?? '') ?>"
                           placeholder="01XXXXXXXXX (ঐচ্ছিক)" maxlength="11">
                </div>
                <div class="col-md-6">
                    <label class="form-label">জাতীয় পরিচয়পত্র (NID) নম্বর</label>
                    <input type="text" name="nid_number" class="form-control" id="nidInput"
                           value="<?= htmlspecialchars($data['nid_number'] ?? '') ?>"
                           placeholder="NID নম্বর (ঐচ্ছিক)"
                           oninput="checkNIDDuplicate(this.value)">
                    <div id="nidCheckMsg" class="form-text"></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">ইমেইল</label>
                    <input type="email" name="email" class="form-control"
                           value="<?= htmlspecialchars($data['email'] ?? '') ?>"
                           placeholder="ঐচ্ছিক">
                </div>
            </div>
        </div>
    </div>

    <!-- Address + Map -->
    <div class="card mb-3">
        <div class="card-header"><h6 class="card-title"><i class="bi bi-geo-alt me-2"></i>ঠিকানা ও অবস্থান</h6></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label">পূর্ণ ঠিকানা <span class="required-star">*</span></label>
                    <textarea name="address_text" class="form-control" rows="2" required
                              placeholder="বাড়ি/ফ্ল্যাট নম্বর, রাস্তা, এলাকার বিস্তারিত ঠিকানা"><?= htmlspecialchars($data['address_text'] ?? '') ?></textarea>
                </div>
                <div class="col-md-4">
                    <label class="form-label">এলাকা</label>
                    <input type="text" name="area" class="form-control"
                           value="<?= htmlspecialchars($data['area'] ?? '') ?>"
                           placeholder="যেমন: মিরপুর-১০">
                </div>
                <div class="col-md-4">
                    <label class="form-label">থানা/উপজেলা</label>
                    <input type="text" name="thana" class="form-control"
                           value="<?= htmlspecialchars($data['thana'] ?? '') ?>"
                           placeholder="থানা">
                </div>
                <div class="col-md-4">
                    <label class="form-label">জেলা</label>
                    <input type="text" name="district" class="form-control"
                           value="<?= htmlspecialchars($data['district'] ?? '') ?>"
                           placeholder="জেলা">
                </div>

                <!-- Google Maps Picker -->
                <div class="col-12">
                    <label class="form-label">
                        <i class="bi bi-pin-map me-1 text-success"></i>
                        Google Maps এ অবস্থান চিহ্নিত করুন
                        <span class="badge bg-light text-muted ms-1" style="font-size:11px;">ঐচ্ছিক</span>
                    </label>

                    <?php if ($mapsKey): ?>
                    <div class="mb-2 d-flex gap-2">
                        <input type="text" id="mapSearchInput" class="form-control"
                               placeholder="ঠিকানা লিখুন বা নিচের ম্যাপে ক্লিক করুন..."
                               style="border-radius:8px;">
                        <button type="button" class="btn btn-outline-primary" onclick="searchMapAddress()" style="border-radius:8px;white-space:nowrap;">
                            <i class="bi bi-search me-1"></i>খুঁজুন
                        </button>
                        <button type="button" class="btn btn-outline-success" onclick="getMyLocation()" style="border-radius:8px;white-space:nowrap;" title="আমার লোকেশন">
                            <i class="bi bi-cursor"></i>
                        </button>
                    </div>
                    <div id="mapPicker"></div>
                    <div id="mapSelectedAddr" class="form-text text-success mt-1"></div>
                    <?php else: ?>
                    <div class="alert" style="background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;color:#92400e;font-size:13px;">
                        <i class="bi bi-info-circle me-1"></i>
                        Google Maps ব্যবহার করতে
                        <a href="<?= SITE_URL ?>/modules/admin/settings.php?tab=integration">সেটিংসে API Key দিন।</a>
                        এখন latitude/longitude ম্যানুয়ালি দিতে পারেন।
                    </div>
                    <?php endif; ?>

                    <div class="row g-2 mt-1">
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Latitude (অক্ষাংশ)</label>
                            <input type="text" name="lat" id="latInput" class="form-control form-control-sm"
                                   value="<?= htmlspecialchars($data['lat'] ?? '') ?>"
                                   placeholder="23.XXXX">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Longitude (দ্রাঘিমাংশ)</label>
                            <input type="text" name="lng" id="lngInput" class="form-control form-control-sm"
                                   value="<?= htmlspecialchars($data['lng'] ?? '') ?>"
                                   placeholder="90.XXXX">
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <button type="button" class="btn btn-outline-danger btn-sm w-100"
                                    onclick="clearMapPin()" style="border-radius:8px;">
                                <i class="bi bi-x-circle me-1"></i>পিন সরান
                            </button>
                        </div>
                    </div>
                    <input type="hidden" name="map_address" id="mapAddressInput" value="<?= htmlspecialchars($data['map_address'] ?? '') ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- Photos Upload -->
    <div class="card mb-3">
        <div class="card-header"><h6 class="card-title"><i class="bi bi-images me-2"></i>ছবি আপলোড</h6></div>
        <div class="card-body">
            <div class="drop-zone" id="dropZone" onclick="document.getElementById('photosInput').click()">
                <i class="bi bi-cloud-upload fs-3 text-muted d-block mb-2"></i>
                <div class="fw-semibold text-muted">ছবি টেনে ছাড়ুন বা ক্লিক করুন</div>
                <div class="text-muted small mt-1">JPG, PNG, WEBP — সর্বোচ্চ ৫MB প্রতিটি</div>
                <div class="text-muted small">গ্রাহকের ছবি, NID, সরঞ্জাম ইত্যাদি</div>
            </div>
            <input type="file" name="photos[]" id="photosInput" multiple accept="image/*" hidden onchange="previewPhotos(this)">
            <div class="photo-preview-wrap" id="photoPreviewWrap"></div>
            <div id="photoTypeSelects"></div>
        </div>
    </div>

</div><!-- /col-lg-8 -->

<!-- ====== Right Column ====== -->
<div class="col-lg-4">

    <!-- Financial Info -->
    <div class="card mb-3">
        <div class="card-header"><h6 class="card-title"><i class="bi bi-cash-stack me-2"></i>আর্থিক তথ্য</h6></div>
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label">বকেয়া পরিমাণ (টাকা) <span class="required-star">*</span></label>
                <div class="input-group">
                    <span class="input-group-text" style="border-radius:8px 0 0 8px;border:2px solid #e2e8f0;border-right:none;">৳</span>
                    <input type="number" name="due_amount" class="form-control" id="dueAmount"
                           value="<?= htmlspecialchars($data['due_amount'] ?? '') ?>"
                           placeholder="0.00" min="0" step="0.01" required
                           style="border-left:none;"
                           oninput="updateDueDisplay(this.value)">
                </div>
                <div id="dueDisplay" class="form-text text-danger fw-semibold"></div>
            </div>
            <div class="mb-3">
                <label class="form-label">সংযোগের ধরন</label>
                <select name="connection_type" class="form-select">
                    <option value="home"      <?= ($data['connection_type'] ?? '') === 'home'      ? 'selected' : '' ?>>আবাসিক</option>
                    <option value="office"    <?= ($data['connection_type'] ?? '') === 'office'    ? 'selected' : '' ?>>অফিস</option>
                    <option value="corporate" <?= ($data['connection_type'] ?? '') === 'corporate' ? 'selected' : '' ?>>কর্পোরেট</option>
                    <option value="other"     <?= ($data['connection_type'] ?? '') === 'other'     ? 'selected' : '' ?>>অন্যান্য</option>
                </select>
            </div>
            <div class="mb-0">
                <label class="form-label">বকেয়ার মেয়াদ</label>
                <input type="text" name="service_period" class="form-control"
                       value="<?= htmlspecialchars($data['service_period'] ?? '') ?>"
                       placeholder="যেমন: জানুয়ারি-জুন ২০২৪">
            </div>
        </div>
    </div>

    <!-- Classification -->
    <div class="card mb-3">
        <div class="card-header"><h6 class="card-title"><i class="bi bi-tags me-2"></i>শ্রেণীবিভাগ</h6></div>
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label">সমস্যার ধরন <span class="required-star">*</span></label>
                <select name="type" class="form-select" required>
                    <option value="due_payment"     <?= ($data['type'] ?? '') === 'due_payment'     ? 'selected' : '' ?>>💰 বকেয়া বিল</option>
                    <option value="fraud"           <?= ($data['type'] ?? '') === 'fraud'           ? 'selected' : '' ?>>⚠️ প্রতারণা</option>
                    <option value="equipment_theft" <?= ($data['type'] ?? '') === 'equipment_theft' ? 'selected' : '' ?>>🔌 সরঞ্জাম চুরি</option>
                    <option value="contract_breach" <?= ($data['type'] ?? '') === 'contract_breach' ? 'selected' : '' ?>>📄 চুক্তি ভঙ্গ</option>
                    <option value="other"           <?= ($data['type'] ?? '') === 'other'           ? 'selected' : '' ?>>অন্যান্য</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">ঝুঁকির মাত্রা <span class="required-star">*</span></label>
                <div class="d-flex flex-column gap-2">
                    <?php
                    $risks = [
                        'critical' => ['label' => '🔴 অতি ঝুঁকিপূর্ণ', 'desc' => 'বড় প্রতারণা বা পুনরাবৃত্তি'],
                        'high'     => ['label' => '🟠 উচ্চ ঝুঁকি',     'desc' => 'বড় বকেয়া বা সরঞ্জাম চুরি'],
                        'medium'   => ['label' => '🔵 মধ্যম ঝুঁকি',   'desc' => 'সাধারণ বকেয়া'],
                        'low'      => ['label' => '🟢 কম ঝুঁকি',       'desc' => 'ছোট বকেয়া'],
                    ];
                    foreach ($risks as $rv => $ri): ?>
                    <label style="cursor:pointer;display:flex;align-items:flex-start;gap:8px;padding:8px;border-radius:8px;
                                  border:2px solid <?= ($data['risk_level'] ?? 'medium') === $rv ? '#2563a8' : '#e2e8f0' ?>;
                                  background:<?= ($data['risk_level'] ?? 'medium') === $rv ? '#eff6ff' : '#fff' ?>;"
                           class="risk-opt" data-value="<?= $rv ?>">
                        <input type="radio" name="risk_level" value="<?= $rv ?>"
                               <?= ($data['risk_level'] ?? 'medium') === $rv ? 'checked' : '' ?>
                               style="margin-top:3px;">
                        <div>
                            <div style="font-size:13px;font-weight:600;"><?= $ri['label'] ?></div>
                            <div style="font-size:11px;color:#94a3b8;"><?= $ri['desc'] ?></div>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div>
                <label class="form-label">বিস্তারিত বিবরণ</label>
                <textarea name="description" class="form-control" rows="3"
                          placeholder="ঘটনার বিস্তারিত বিবরণ (ঐচ্ছিক)"><?= htmlspecialchars($data['description'] ?? '') ?></textarea>
            </div>
        </div>
    </div>

    <!-- Submit -->
    <div class="card">
        <div class="card-body">
            <button type="submit" class="btn btn-danger w-100 py-2 mb-2" style="font-size:15px;font-weight:600;">
                <i class="bi bi-plus-circle me-2"></i>এন্ট্রি সংরক্ষণ করুন
            </button>
            <a href="list.php" class="btn btn-outline-secondary w-100">বাতিল করুন</a>
            <div class="alert mt-3 mb-0" style="background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;color:#92400e;font-size:12px;">
                <i class="bi bi-info-circle me-1"></i>
                এই এন্ট্রি সব অনুমোদিত কোম্পানি দেখতে পাবে। সঠিক তথ্য দিন।
            </div>
        </div>
    </div>

</div><!-- /col-lg-4 -->
</div><!-- /row -->
</form>

<?php if ($mapsKey): ?>
<script>
let map, marker, geocoder;

function initMap() {
    geocoder = new google.maps.Geocoder();
    map = new google.maps.Map(document.getElementById('mapPicker'), {
        zoom: 13,
        center: { lat: 23.8103, lng: 90.4125 },
    });
    // Click to place marker
    map.addListener('click', e => setMarker(e.latLng.lat(), e.latLng.lng()));

    // If existing coords, show marker
    const lat = parseFloat(document.getElementById('latInput').value);
    const lng = parseFloat(document.getElementById('lngInput').value);
    if (lat && lng) setMarker(lat, lng);
}

function setMarker(lat, lng) {
    if (marker) marker.setMap(null);
    marker = new google.maps.Marker({ position:{lat,lng}, map, draggable:true,
        icon:{ url:'https://maps.google.com/mapfiles/ms/icons/red-dot.png' }
    });
    marker.addListener('dragend', e => {
        setLatLng(e.latLng.lat(), e.latLng.lng());
        reverseGeocode(e.latLng.lat(), e.latLng.lng());
    });
    setLatLng(lat, lng);
    reverseGeocode(lat, lng);
}

function setLatLng(lat, lng) {
    document.getElementById('latInput').value = lat.toFixed(7);
    document.getElementById('lngInput').value = lng.toFixed(7);
}

function reverseGeocode(lat, lng) {
    geocoder.geocode({location:{lat,lng}}, (results, status) => {
        if (status === 'OK' && results[0]) {
            const addr = results[0].formatted_address;
            document.getElementById('mapAddressInput').value = addr;
            document.getElementById('mapSelectedAddr').innerHTML =
                '<i class="bi bi-check-circle-fill me-1"></i>' + addr;
        }
    });
}

function searchMapAddress() {
    const q = document.getElementById('mapSearchInput').value.trim();
    if (!q) return;
    geocoder.geocode({address: q + ', Bangladesh'}, (results, status) => {
        if (status === 'OK') {
            const loc = results[0].geometry.location;
            map.setCenter(loc);
            map.setZoom(16);
            setMarker(loc.lat(), loc.lng());
        } else {
            alert('ঠিকানা খুঁজে পাওয়া যায়নি।');
        }
    });
}

function getMyLocation() {
    if (!navigator.geolocation) return alert('আপনার ব্রাউজার লোকেশন সাপোর্ট করে না।');
    navigator.geolocation.getCurrentPosition(pos => {
        const {latitude: lat, longitude: lng} = pos.coords;
        map.setCenter({lat,lng}); map.setZoom(17);
        setMarker(lat, lng);
    }, () => alert('লোকেশন নেওয়া যায়নি।'));
}

function clearMapPin() {
    if (marker) { marker.setMap(null); marker = null; }
    document.getElementById('latInput').value = '';
    document.getElementById('lngInput').value = '';
    document.getElementById('mapAddressInput').value = '';
    document.getElementById('mapSelectedAddr').innerHTML = '';
}

// Manual lat/lng change → update map
['latInput','lngInput'].forEach(id => {
    document.getElementById(id).addEventListener('change', () => {
        const lat = parseFloat(document.getElementById('latInput').value);
        const lng = parseFloat(document.getElementById('lngInput').value);
        if (lat && lng && map) { map.setCenter({lat,lng}); setMarker(lat,lng); }
    });
});
</script>
<script src="https://maps.googleapis.com/maps/api/js?key=<?= htmlspecialchars($mapsKey) ?>&callback=initMap" async defer></script>
<?php endif; ?>

<script>
// ---- Due amount display ----
function updateDueDisplay(val) {
    const n = parseFloat(val);
    document.getElementById('dueDisplay').textContent = n > 0 ? '৳' + n.toLocaleString('bn-BD') + ' টাকা' : '';
}

// ---- Risk level visual selection ----
document.querySelectorAll('.risk-opt').forEach(opt => {
    opt.addEventListener('click', () => {
        document.querySelectorAll('.risk-opt').forEach(o => {
            o.style.borderColor = '#e2e8f0';
            o.style.background = '#fff';
        });
        opt.style.borderColor = '#2563a8';
        opt.style.background = '#eff6ff';
    });
});

// ============================================================
// A-2.1 + A-2.4: Duplicate Check System
// ============================================================
const DUP_URL = '<?= SITE_URL ?>/modules/defaulter/check_duplicate.php';
let phoneTimer, nidTimer, nameTimer;

// ── Alert box helper ──────────────────────────────────────
function showDupAlert(msgId, data, type) {
    const msg = document.getElementById(msgId);
    if (!data.found) {
        msg.innerHTML = `<span class="text-success"><i class="bi bi-check-circle me-1"></i>
            ${type==='nid' ? 'NID পাওয়া যায়নি' : type==='name' ? 'একই নামে কেউ নেই' : 'নতুন নম্বর'}</span>`;
        return;
    }

    // Build compact entry list
    let entriesHtml = '';
    (data.entries || []).slice(0,3).forEach(e => {
        const riskColors = {critical:'#dc2626',high:'#ea580c',medium:'#d97706',low:'#16a34a'};
        const statusColors = {active:'#dc2626',resolved:'#16a34a',disputed:'#d97706'};
        entriesHtml += `
        <div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid #fde68a;">
            ${e.photo ? `<img src="${e.photo}" style="width:28px;height:28px;border-radius:50%;object-fit:cover;flex-shrink:0;">` :
                        `<div style="width:28px;height:28px;border-radius:50%;background:#1a3a5c;color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;">${e.name.charAt(0)}</div>`}
            <div style="flex:1;min-width:0;">
                <div style="font-weight:600;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${e.name}</div>
                <div style="font-size:11px;color:#64748b;">${e.company} · ${e.time_ago}</div>
            </div>
            <div style="text-align:right;flex-shrink:0;">
                <div style="font-weight:700;color:${statusColors[e.status]||'#64748b'};font-size:12px;">${e.due_fmt}</div>
                <div style="font-size:10px;color:${riskColors[e.risk]||'#64748b'};">${e.status_label}</div>
            </div>
            <a href="${e.view_url}" target="_blank" style="color:#2563a8;font-size:11px;white-space:nowrap;">দেখুন →</a>
        </div>`;
    });
    if (data.count > 3) {
        entriesHtml += `<div style="font-size:11px;color:#64748b;padding-top:4px;">+ আরও ${data.count-3}টি এন্ট্রি</div>`;
    }

    // Score info for phone check
    let scoreHtml = '';
    if (data.score) {
        scoreHtml = `<div style="margin-top:6px;padding:4px 8px;border-radius:6px;background:rgba(0,0,0,.05);font-size:11px;">
            ক্রেডিট স্কোর: <strong style="color:${data.score.color};">${data.score.value} — ${data.score.label}</strong>
            ${data.score.repeat ? ' · <strong style="color:#dc2626;">Repeat Offender</strong>' : ''}
        </div>`;
    }

    // Fraud warning for NID check
    let fraudHtml = '';
    if (type === 'nid' && data.is_fraud) {
        fraudHtml = `<div style="margin-top:6px;padding:6px 10px;background:#fef2f2;border-radius:6px;font-size:12px;color:#dc2626;font-weight:600;">
            <i class="bi bi-shield-x me-1"></i>⚠️ একই NID তে ${data.unique_names} টি ভিন্ন নাম ও ${data.unique_phones} টি ভিন্ন নম্বর — সম্ভাব্য প্রতারণা!
        </div>`;
    }

    msg.innerHTML = `<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 12px;margin-top:4px;">
        <div style="font-size:12px;font-weight:600;color:#92400e;margin-bottom:6px;">
            <i class="bi bi-exclamation-triangle me-1"></i>
            ${type==='nid' ? 'এই NID তে ' : type==='name' ? 'একই নামে ' : 'এই নম্বরে '}
            <strong>${data.count}</strong> টি এন্ট্রি পাওয়া গেছে!
        </div>
        ${entriesHtml}
        ${scoreHtml}
        ${fraudHtml}
    </div>`;
}

// ── Phone check (A-2.4) ────────────────────────────────────
function checkDuplicate(phone) {
    clearTimeout(phoneTimer);
    const msg = document.getElementById('phoneCheckMsg');
    if (phone.length < 11) { msg.innerHTML = ''; return; }
    msg.innerHTML = '<span class="text-muted" style="font-size:11px;"><i class="bi bi-hourglass me-1"></i>চেক করা হচ্ছে...</span>';
    phoneTimer = setTimeout(() => {
        fetch(`${DUP_URL}?type=phone&value=${encodeURIComponent(phone)}`)
            .then(r => r.json()).then(data => showDupAlert('phoneCheckMsg', data, 'phone'))
            .catch(() => { msg.innerHTML = ''; });
    }, 600);
}

// ── NID check (A-2.1 / A-2.4) ─────────────────────────────
function checkNIDDuplicate(nid) {
    clearTimeout(nidTimer);
    const msg = document.getElementById('nidCheckMsg');
    const clean = nid.replace(/[^0-9]/g, '');
    if (clean.length < 10) { msg.innerHTML = ''; return; }
    msg.innerHTML = '<span class="text-muted" style="font-size:11px;"><i class="bi bi-hourglass me-1"></i>NID চেক করা হচ্ছে...</span>';
    nidTimer = setTimeout(() => {
        fetch(`${DUP_URL}?type=nid&value=${encodeURIComponent(nid)}`)
            .then(r => r.json()).then(data => showDupAlert('nidCheckMsg', data, 'nid'))
            .catch(() => { msg.innerHTML = ''; });
    }, 800);
}

// ── Fuzzy Name check (A-2.2 / A-2.4) ─────────────────────
function checkNameDuplicate(name) {
    clearTimeout(nameTimer);
    const msg = document.getElementById('nameCheckMsg');
    if (name.length < 3) { msg.innerHTML = ''; return; }
    nameTimer = setTimeout(() => {
        fetch(`${DUP_URL}?type=name&value=${encodeURIComponent(name)}`)
            .then(r => r.json()).then(data => {
                if (data.found) showDupAlert('nameCheckMsg', data, 'name');
                else msg.innerHTML = '';
            }).catch(() => { msg.innerHTML = ''; });
    }, 1000);
}

// ---- Photo preview ----
const photosArr = [];

function previewPhotos(input) {
    const wrap = document.getElementById('photoPreviewWrap');
    const typeSelects = document.getElementById('photoTypeSelects');
    Array.from(input.files).forEach((file, i) => {
        if (!file.type.startsWith('image/')) return;
        const reader = new FileReader();
        const idx = photosArr.length;
        photosArr.push(file);
        reader.onload = e => {
            const div = document.createElement('div');
            div.className = 'photo-thumb-wrap';
            div.innerHTML = `<img src="${e.target.result}" class="photo-thumb">
                <button type="button" class="remove-btn" onclick="removePhoto(this)">×</button>`;
            wrap.appendChild(div);

            // Type select
            const sel = document.createElement('div');
            sel.className = 'mt-2';
            sel.innerHTML = `<small class="text-muted">ছবি ${idx+1}:</small>
                <select name="photo_type[]" class="form-select form-select-sm d-inline-block" style="width:auto;">
                    <option value="customer_face">গ্রাহকের ছবি</option>
                    <option value="nid_front">NID সামনে</option>
                    <option value="nid_back">NID পেছনে</option>
                    <option value="equipment">সরঞ্জাম</option>
                    <option value="other">অন্যান্য</option>
                </select>`;
            typeSelects.appendChild(sel);
        };
        reader.readAsDataURL(file);
    });
}

function removePhoto(btn) {
    btn.closest('.photo-thumb-wrap').remove();
}

// ---- Drag & drop ----
const dropZone = document.getElementById('dropZone');
['dragover','dragenter'].forEach(e => dropZone.addEventListener(e, ev => { ev.preventDefault(); dropZone.classList.add('drag-over'); }));
['dragleave','drop'].forEach(e => dropZone.addEventListener(e, ev => { ev.preventDefault(); dropZone.classList.remove('drag-over'); }));
dropZone.addEventListener('drop', ev => {
    const dt = ev.dataTransfer;
    if (dt.files.length) {
        document.getElementById('photosInput').files = dt.files;
        previewPhotos(document.getElementById('photosInput'));
    }
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
