<?php
// ============================================================
// EDIT DEFAULTER
// File: modules/defaulter/edit.php
// ============================================================
require_once __DIR__ . '/../../includes/functions.php';
requirePermission('defaulters', 'edit_own');

$user = getCurrentUser();
$id   = (int)($_GET['id'] ?? 0);

$defaulter = Database::fetchOne("SELECT * FROM defaulters WHERE id = ?", [$id]);
if (!$defaulter) { setFlash('error', 'এন্ট্রি পাওয়া যায়নি।'); redirect('list.php'); }

// Permission: own company OR delete_any
$canEdit = hasPermission('defaulters','delete_any') ||
           ($defaulter['company_id'] == $user['company_id']);
if (!$canEdit) { setFlash('error', 'এই এন্ট্রি সম্পাদনার অনুমতি নেই।'); redirect('view.php?id='.$id); }

$errors  = [];
$mapsKey = getSetting('google_maps_key');
$data    = $defaulter; // pre-fill

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $errors[] = 'নিরাপত্তা যাচাই ব্যর্থ।';
    } else {
        $data = array_merge($defaulter, [
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
        ]);

        if (empty($data['customer_name']))  $errors[] = 'গ্রাহকের নাম দিন।';
        if (empty($data['address_text']))   $errors[] = 'ঠিকানা দিন।';
        if (!is_numeric($data['due_amount']) || $data['due_amount'] < 0) $errors[] = 'সঠিক বকেয়া পরিমাণ দিন।';

        if (empty($errors)) {
            $oldData = $defaulter;
            Database::update('defaulters', [
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
            ], 'id = ?', [$id]);

            // Upload new photos
            if (!empty($_FILES['photos']['name'][0])) {
                foreach ($_FILES['photos']['tmp_name'] as $idx => $tmpName) {
                    if (!$tmpName || $_FILES['photos']['error'][$idx] !== UPLOAD_ERR_OK) continue;
                    $singleFile = ['name'=>$_FILES['photos']['name'][$idx],'tmp_name'=>$tmpName,'size'=>$_FILES['photos']['size'][$idx],'error'=>$_FILES['photos']['error'][$idx]];
                    $upload = uploadPhoto($singleFile, 'photos');
                    if ($upload['success']) {
                        Database::insert('defaulter_photos', [
                            'defaulter_id' => $id,
                            'photo_path'   => $upload['path'],
                            'photo_type'   => ($_POST['photo_type'][$idx] ?? 'customer_face'),
                            'is_primary'   => 0,
                            'uploaded_by'  => $user['id'],
                        ]);
                    }
                }
            }

            // Delete selected photos
            foreach ($_POST['delete_photo'] ?? [] as $photoId) {
                $photo = Database::fetchOne("SELECT * FROM defaulter_photos WHERE id = ? AND defaulter_id = ?", [(int)$photoId, $id]);
                if ($photo) {
                    deleteUploadedFile($photo['photo_path']);
                    Database::delete('defaulter_photos', 'id = ?', [(int)$photoId]);
                }
            }

            logActivity('defaulter.edit', 'defaulters', [
                'target_id' => $id, 'target_type' => 'defaulters',
                'description' => '"' . $data['customer_name'] . '" আপডেট করা হয়েছে',
                'old_data' => $oldData, 'new_data' => $data,
            ]);
            setFlash('success', 'এন্ট্রি আপডেট হয়েছে।');
            redirect('view.php?id=' . $id);
        }
    }
}

$photos  = Database::fetchAll("SELECT * FROM defaulter_photos WHERE defaulter_id = ? ORDER BY is_primary DESC", [$id]);
$pageTitle = 'এন্ট্রি সম্পাদনা';

$extraHead = '<style>
#mapPicker{height:280px;border-radius:10px;border:2px solid #e2e8f0;}
.drop-zone{border:2px dashed #e2e8f0;border-radius:10px;padding:20px;text-align:center;cursor:pointer;background:#f8fafc;}
.drop-zone:hover{border-color:#2563a8;background:#eff6ff;}
</style>';

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between">
    <div>
        <h1>এন্ট্রি সম্পাদনা</h1>
        <nav><ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="list.php">বকেয়া তালিকা</a></li>
            <li class="breadcrumb-item"><a href="view.php?id=<?= $id ?>">বিবরণ</a></li>
            <li class="breadcrumb-item active">সম্পাদনা</li>
        </ol></nav>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger rounded-3 mb-4" style="font-size:13px;">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data">
<?= csrfField() ?>
<div class="row g-3">
<div class="col-lg-8">
    <div class="card mb-3">
        <div class="card-header"><h6 class="card-title"><i class="bi bi-person me-2"></i>গ্রাহকের তথ্য</h6></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">গ্রাহকের নাম <span class="required-star">*</span></label>
                    <input type="text" name="customer_name" class="form-control" id="editNameInput"
                           value="<?= htmlspecialchars($data['customer_name']) ?>" required
                           onblur="editCheckName(this.value)">
                    <div id="editNameMsg" class="form-text"></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">মোবাইল নম্বর <span class="required-star">*</span></label>
                    <input type="text" name="customer_phone" class="form-control" id="editPhoneInput"
                           value="<?= htmlspecialchars($data['customer_phone']) ?>" maxlength="11" required
                           oninput="editCheckPhone(this.value)">
                    <div id="editPhoneMsg" class="form-text"></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">বিকল্প নম্বর</label>
                    <input type="text" name="alt_phone" class="form-control" value="<?= htmlspecialchars($data['alt_phone'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">NID নম্বর</label>
                    <input type="text" name="nid_number" class="form-control" id="editNidInput"
                           value="<?= htmlspecialchars($data['nid_number'] ?? '') ?>"
                           oninput="editCheckNID(this.value)">
                    <div id="editNidMsg" class="form-text"></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">ইমেইল</label>
                    <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($data['email'] ?? '') ?>">
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><h6 class="card-title"><i class="bi bi-geo-alt me-2"></i>ঠিকানা</h6></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label">পূর্ণ ঠিকানা <span class="required-star">*</span></label>
                    <textarea name="address_text" class="form-control" rows="2" required><?= htmlspecialchars($data['address_text']) ?></textarea>
                </div>
                <div class="col-md-4"><label class="form-label">এলাকা</label><input type="text" name="area" class="form-control" value="<?= htmlspecialchars($data['area'] ?? '') ?>"></div>
                <div class="col-md-4"><label class="form-label">থানা</label><input type="text" name="thana" class="form-control" value="<?= htmlspecialchars($data['thana'] ?? '') ?>"></div>
                <div class="col-md-4"><label class="form-label">জেলা</label><input type="text" name="district" class="form-control" value="<?= htmlspecialchars($data['district'] ?? '') ?>"></div>
                <?php if ($mapsKey): ?>
                <div class="col-12">
                    <label class="form-label"><i class="bi bi-pin-map me-1 text-success"></i>ম্যাপ পিন</label>
                    <div id="mapPicker"></div>
                    <div class="row g-2 mt-1">
                        <div class="col-md-5"><input type="text" name="lat" id="latInput" class="form-control form-control-sm" placeholder="Latitude" value="<?= htmlspecialchars($data['lat'] ?? '') ?>"></div>
                        <div class="col-md-5"><input type="text" name="lng" id="lngInput" class="form-control form-control-sm" placeholder="Longitude" value="<?= htmlspecialchars($data['lng'] ?? '') ?>"></div>
                        <div class="col-md-2"><button type="button" class="btn btn-outline-danger btn-sm w-100" onclick="clearPin()"><i class="bi bi-x"></i></button></div>
                    </div>
                    <input type="hidden" name="map_address" id="mapAddressInput" value="<?= htmlspecialchars($data['map_address'] ?? '') ?>">
                </div>
                <?php else: ?>
                <div class="col-md-6"><label class="form-label">Latitude</label><input type="text" name="lat" class="form-control form-control-sm" value="<?= htmlspecialchars($data['lat'] ?? '') ?>"></div>
                <div class="col-md-6"><label class="form-label">Longitude</label><input type="text" name="lng" class="form-control form-control-sm" value="<?= htmlspecialchars($data['lng'] ?? '') ?>"><input type="hidden" name="map_address" value="<?= htmlspecialchars($data['map_address'] ?? '') ?>"></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Existing Photos -->
    <?php if (!empty($photos)): ?>
    <div class="card mb-3">
        <div class="card-header"><h6 class="card-title"><i class="bi bi-images me-2"></i>বিদ্যমান ছবি</h6></div>
        <div class="card-body">
            <div class="d-flex flex-wrap gap-2">
            <?php foreach ($photos as $photo): ?>
            <div class="text-center">
                <div style="position:relative;display:inline-block;">
                    <img src="<?= UPLOAD_URL . $photo['photo_path'] ?>" style="width:80px;height:80px;border-radius:8px;object-fit:cover;border:2px solid #e2e8f0;">
                </div>
                <div class="mt-1">
                    <label style="font-size:11px;cursor:pointer;color:#dc2626;">
                        <input type="checkbox" name="delete_photo[]" value="<?= $photo['id'] ?>"> মুছুন
                    </label>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Add New Photos -->
    <div class="card mb-3">
        <div class="card-header"><h6 class="card-title"><i class="bi bi-cloud-upload me-2"></i>নতুন ছবি যোগ করুন</h6></div>
        <div class="card-body">
            <div class="drop-zone" onclick="document.getElementById('editPhotos').click()">
                <i class="bi bi-cloud-upload fs-3 text-muted d-block mb-1"></i>
                <div class="text-muted small">ক্লিক করে ছবি বেছে নিন</div>
            </div>
            <input type="file" name="photos[]" id="editPhotos" multiple accept="image/*" hidden onchange="previewEditPhotos(this)">
            <div id="editPhotoPreview" class="d-flex flex-wrap gap-2 mt-2"></div>
        </div>
    </div>
</div>

<div class="col-lg-4">
    <div class="card mb-3">
        <div class="card-header"><h6 class="card-title"><i class="bi bi-cash-stack me-2"></i>আর্থিক তথ্য</h6></div>
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label">বকেয়া (টাকা) <span class="required-star">*</span></label>
                <input type="number" name="due_amount" class="form-control" value="<?= htmlspecialchars($data['due_amount']) ?>" min="0" step="0.01" required>
            </div>
            <div class="mb-3">
                <label class="form-label">সংযোগের ধরন</label>
                <select name="connection_type" class="form-select">
                    <?php foreach (['home'=>'আবাসিক','office'=>'অফিস','corporate'=>'কর্পোরেট','other'=>'অন্যান্য'] as $v=>$l): ?>
                    <option value="<?= $v ?>" <?= $data['connection_type']===$v?'selected':'' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-0">
                <label class="form-label">মেয়াদ</label>
                <input type="text" name="service_period" class="form-control" value="<?= htmlspecialchars($data['service_period'] ?? '') ?>">
            </div>
        </div>
    </div>
    <div class="card mb-3">
        <div class="card-header"><h6 class="card-title"><i class="bi bi-tags me-2"></i>শ্রেণীবিভাগ</h6></div>
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label">ধরন</label>
                <select name="type" class="form-select">
                    <?php foreach (['due_payment'=>'বকেয়া বিল','fraud'=>'প্রতারণা','equipment_theft'=>'সরঞ্জাম চুরি','contract_breach'=>'চুক্তি ভঙ্গ','other'=>'অন্যান্য'] as $v=>$l): ?>
                    <option value="<?= $v ?>" <?= $data['type']===$v?'selected':'' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">ঝুঁকির মাত্রা</label>
                <select name="risk_level" class="form-select">
                    <?php foreach (['critical'=>'অতি ঝুঁকিপূর্ণ','high'=>'উচ্চ ঝুঁকি','medium'=>'মধ্যম ঝুঁকি','low'=>'কম ঝুঁকি'] as $v=>$l): ?>
                    <option value="<?= $v ?>" <?= $data['risk_level']===$v?'selected':'' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label">বিবরণ</label>
                <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($data['description'] ?? '') ?></textarea>
            </div>
        </div>
    </div>
    <div class="d-flex flex-column gap-2">
        <button type="submit" class="btn btn-primary py-2 fw-semibold"><i class="bi bi-check-lg me-1"></i>আপডেট করুন</button>
        <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary">বাতিল</a>
    </div>
</div>
</div>
</form>

<?php if ($mapsKey): ?>
<script>
let map, marker, geocoder;
function initMap() {
    geocoder = new google.maps.Geocoder();
    const lat = parseFloat(document.getElementById('latInput').value) || 23.8103;
    const lng = parseFloat(document.getElementById('lngInput').value) || 90.4125;
    map = new google.maps.Map(document.getElementById('mapPicker'), { zoom: lat !== 23.8103 ? 16 : 12, center:{lat,lng} });
    map.addListener('click', e => setMarker(e.latLng.lat(), e.latLng.lng()));
    if (document.getElementById('latInput').value) setMarker(lat, lng);
}
function setMarker(lat, lng) {
    if (marker) marker.setMap(null);
    marker = new google.maps.Marker({ position:{lat,lng}, map, draggable:true });
    marker.addListener('dragend', e => { setLatLng(e.latLng.lat(), e.latLng.lng()); });
    setLatLng(lat, lng);
}
function setLatLng(lat, lng) {
    document.getElementById('latInput').value = lat.toFixed(7);
    document.getElementById('lngInput').value = lng.toFixed(7);
}
function clearPin() {
    if (marker) { marker.setMap(null); marker = null; }
    document.getElementById('latInput').value = '';
    document.getElementById('lngInput').value = '';
    document.getElementById('mapAddressInput').value = '';
}
</script>
<script>
// ── A-2.4: Edit.php duplicate check (edit_id নিজেকে বাদ দেয়) ──
const EDIT_DUP_URL = '<?= SITE_URL ?>/modules/defaulter/check_duplicate.php';
const EDIT_ID = <?= $id ?>;
let editPhTimer, editNidTimer, editNameTimer;

function editShowAlert(msgId, data, type) {
    const msg = document.getElementById(msgId);
    if (!data || !data.found) { msg.innerHTML = ''; return; }
    const colors = {active:'#dc2626',resolved:'#16a34a',disputed:'#d97706'};
    let rows = (data.entries||[]).slice(0,2).map(e =>
        `<div style="font-size:11px;padding:4px 0;border-bottom:1px solid #fde68a;display:flex;gap:8px;align-items:center;">
            <div style="flex:1;"><span class="fw-semibold">${e.name}</span>
            <span class="text-muted ms-1">${e.company}</span></div>
            <span style="color:${colors[e.status]||'#64748b'};font-weight:700;">${e.due_fmt}</span>
            <a href="${e.view_url}" target="_blank" style="font-size:10px;color:#2563a8;">দেখুন →</a>
        </div>`
    ).join('');
    let extra = data.is_fraud
        ? `<div style="color:#dc2626;font-weight:600;font-size:11px;margin-top:4px;"><i class="bi bi-shield-x me-1"></i>ভিন্ন নামে একই NID — সম্ভাব্য প্রতারণা!</div>`
        : '';
    msg.innerHTML = `<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:8px 10px;margin-top:3px;">
        <div style="font-size:11px;font-weight:600;color:#92400e;margin-bottom:4px;">
            <i class="bi bi-exclamation-triangle me-1"></i>${data.count}টি এন্ট্রি পাওয়া গেছে
        </div>${rows}${extra}</div>`;
}

let editPhTimer2, editNidTimer2, editNameTimer2;
function editCheckPhone(v) {
    clearTimeout(editPhTimer2);
    const msg = document.getElementById('editPhoneMsg');
    if (v.length < 11) { msg.innerHTML = ''; return; }
    editPhTimer2 = setTimeout(() =>
        fetch(`${EDIT_DUP_URL}?type=phone&value=${encodeURIComponent(v)}&edit_id=${EDIT_ID}`)
            .then(r=>r.json()).then(d=>editShowAlert('editPhoneMsg',d,'phone')).catch(()=>{}), 700);
}
function editCheckNID(v) {
    clearTimeout(editNidTimer2);
    const msg = document.getElementById('editNidMsg');
    if (v.replace(/[^0-9]/g,'').length < 10) { msg.innerHTML = ''; return; }
    editNidTimer2 = setTimeout(() =>
        fetch(`${EDIT_DUP_URL}?type=nid&value=${encodeURIComponent(v)}&edit_id=${EDIT_ID}`)
            .then(r=>r.json()).then(d=>editShowAlert('editNidMsg',d,'nid')).catch(()=>{}), 800);
}
function editCheckName(v) {
    clearTimeout(editNameTimer2);
    const msg = document.getElementById('editNameMsg');
    if (v.length < 3) { msg.innerHTML = ''; return; }
    editNameTimer2 = setTimeout(() =>
        fetch(`${EDIT_DUP_URL}?type=name&value=${encodeURIComponent(v)}&edit_id=${EDIT_ID}`)
            .then(r=>r.json()).then(d=>{ if(d.found) editShowAlert('editNameMsg',d,'name'); else msg.innerHTML=''; }).catch(()=>{}), 1000);
}
</script>
<script src="https://maps.googleapis.com/maps/api/js?key=<?= htmlspecialchars($mapsKey) ?>&callback=initMap" async defer></script>
<?php endif; ?>
<script>
function previewEditPhotos(input) {
    const wrap = document.getElementById('editPhotoPreview');
    Array.from(input.files).forEach(file => {
        if (!file.type.startsWith('image/')) return;
        const r = new FileReader();
        r.onload = e => {
            const img = document.createElement('img');
            img.src = e.target.result;
            img.style.cssText = 'width:70px;height:70px;border-radius:8px;object-fit:cover;border:2px solid #e2e8f0;';
            wrap.appendChild(img);
        };
        r.readAsDataURL(file);
    });
}
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
