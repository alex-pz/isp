<?php
// ============================================================
// DEFAULTER LIST
// File: modules/defaulter/list.php
// ============================================================
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/credit_score.php';
requirePermission('defaulters', 'view');

$user      = getCurrentUser();
$pageTitle = 'বকেয়া তালিকা';

// ── A-3.4: Bulk Status Update Handler ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    requirePermission('defaulters', 'edit_own');
    verifyCsrf();

    $bulkAction = $_POST['bulk_action'];
    $selectedIds = array_map('intval', (array)($_POST['selected_ids'] ?? []));
    $selectedIds = array_filter($selectedIds);

    $allowed = ['mark_resolved','mark_disputed','delete_selected'];
    if (!in_array($bulkAction, $allowed) || empty($selectedIds)) {
        setFlash('error', 'অবৈধ অনুরোধ।');
        redirect($_SERVER['PHP_SELF']);
    }

    $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
    $companyFilter = isSuperAdmin() ? '' : ' AND company_id = ' . (int)$user['company_id'];

    $updated = 0;
    if ($bulkAction === 'mark_resolved') {
        // শুধু active এন্ট্রি resolve করা
        $rows = Database::fetchAll(
            "SELECT id FROM defaulters WHERE id IN ($placeholders) AND status = 'active' $companyFilter",
            $selectedIds
        );
        foreach ($rows as $r) {
            Database::update('defaulters',
                ['status' => 'resolved', 'resolved_by' => $user['id'],
                 'resolved_at' => date('Y-m-d H:i:s'),
                 'resolution_note' => 'বাল্ক আপডেটের মাধ্যমে সমাধান',
                 'resolution_type' => 'other'],
                'id = ?', [$r['id']]
            );
            $updated++;
        }
        setFlash('success', "$updated টি এন্ট্রি সমাধান হিসেবে চিহ্নিত হয়েছে।");

    } elseif ($bulkAction === 'mark_disputed') {
        Database::query(
            "UPDATE defaulters SET status = 'disputed' WHERE id IN ($placeholders) $companyFilter",
            $selectedIds
        );
        $updated = count($selectedIds);
        setFlash('success', "$updated টি এন্ট্রি বিরোধ হিসেবে চিহ্নিত হয়েছে।");

    } elseif ($bulkAction === 'delete_selected' && isSuperAdmin()) {
        Database::query(
            "UPDATE defaulters SET status = 'removed' WHERE id IN ($placeholders)",
            $selectedIds
        );
        $updated = count($selectedIds);
        setFlash('success', "$updated টি এন্ট্রি মুছে ফেলা হয়েছে।");
    }

    logActivity('defaulter.bulk_update', 'defaulters', [
        'description' => "বাল্ক আপডেট ($bulkAction): $updated টি",
    ]);
    redirect($_SERVER['REQUEST_URI']);
}
$perPage   = (int)getSetting('items_per_page', '20');

// ---- Filters ----
$search     = trim($_GET['q']          ?? '');
$searchType = $_GET['search_type'] ?? 'all';  // all | phone | nid | name
$status     = $_GET['status']          ?? 'active';
$riskFilter = $_GET['risk']            ?? '';
$typeFilter = $_GET['type']            ?? '';
$company    = $_GET['company']         ?? '';
$areaFilter = trim($_GET['area']       ?? '');
$page       = max(1, (int)($_GET['page'] ?? 1));
$viewMode   = $_GET['view']            ?? 'table'; // table | map

// ---- Build WHERE ----
$where  = '1=1';
$params = [];

if ($status)     { $where .= ' AND d.status = ?';       $params[] = $status; }
if ($riskFilter) { $where .= ' AND d.risk_level = ?';   $params[] = $riskFilter; }
if ($typeFilter) { $where .= ' AND d.type = ?';         $params[] = $typeFilter; }
if ($areaFilter) { $where .= ' AND d.area LIKE ?';      $params[] = "%$areaFilter%"; }

if ($company)    { $where .= ' AND d.company_id = ?';   $params[] = $company; }

if ($search) {
    // A-2.1: ধরন অনুযায়ী সার্চ
    if ($searchType === 'nid') {
        $where .= ' AND d.nid_number LIKE ?';
        $params[] = "%$search%";
    } elseif ($searchType === 'phone') {
        $where .= ' AND d.customer_phone LIKE ?';
        $params[] = "%$search%";
    } elseif ($searchType === 'name') {
        // A-2.2: Fuzzy name — prefix match + common variations
        $namePrefix = mb_substr($search, 0, 4, 'UTF-8');
        $where .= ' AND (d.customer_name LIKE ? OR d.customer_name LIKE ?)';
        array_push($params, "%$search%", "%$namePrefix%");
    } else {
        $where .= ' AND (d.customer_name LIKE ? OR d.customer_phone LIKE ? OR d.nid_number LIKE ? OR d.address_text LIKE ? OR d.area LIKE ?)';
        $s = "%$search%"; array_push($params, $s, $s, $s, $s, $s);
    }
}

$total  = Database::fetchOne("SELECT COUNT(*) as cnt FROM defaulters d WHERE $where", $params)['cnt'];
$paging = paginate($total, $perPage, $page,
    '?' . http_build_query(array_filter(['q'=>$search,'status'=>$status,'risk'=>$riskFilter,
        'type'=>$typeFilter,'company'=>$company,'area'=>$areaFilter,'view'=>$viewMode])) . '&page=');

$defaulters = Database::fetchAll(
    "SELECT d.*,
            c.company_name,
            u.full_name as entered_by_name,
            (SELECT photo_path FROM defaulter_photos WHERE defaulter_id = d.id AND is_primary = 1 LIMIT 1) as primary_photo,
            (SELECT COUNT(*) FROM defaulter_photos WHERE defaulter_id = d.id) as photo_count,
            (SELECT COUNT(*) FROM disputes WHERE defaulter_id = d.id AND status = 'open') as open_disputes,
            (SELECT COUNT(DISTINCT d2.company_id) FROM defaulters d2
             WHERE d2.customer_phone = d.customer_phone AND d2.status != 'removed') as company_count
     FROM defaulters d
     JOIN companies c ON c.id = d.company_id
     JOIN users u ON u.id = d.entered_by
     WHERE $where
     ORDER BY FIELD(d.risk_level,'critical','high','medium','low'), d.created_at DESC
     LIMIT $perPage OFFSET {$paging['offset']}",
    $params
);

// For map view — get all with coords (no pagination)
$mapDefaulters = [];
if ($viewMode === 'map') {
    $mapDefaulters = Database::fetchAll(
        "SELECT d.id, d.customer_name, d.customer_phone, d.due_amount, d.risk_level, d.status,
                d.lat, d.lng, d.address_text, c.company_name,
                (SELECT photo_path FROM defaulter_photos WHERE defaulter_id = d.id AND is_primary = 1 LIMIT 1) as primary_photo
         FROM defaulters d JOIN companies c ON c.id = d.company_id
         WHERE $where AND d.lat IS NOT NULL AND d.lng IS NOT NULL
         LIMIT 500",
        $params
    );
}

// Dropdown data
$companies   = Database::fetchAll("SELECT id, company_name FROM companies WHERE status='approved' ORDER BY company_name");
$statusCounts = [];
foreach (['active','resolved','disputed'] as $s) {
    $statusCounts[$s] = Database::count('defaulters', "status = ?", [$s]);
}
$mapsKey = getSetting('google_maps_key');

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div>
        <h1>বকেয়া তালিকা</h1>
        <nav><ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= SITE_URL ?>/dashboard.php">হোম</a></li>
            <li class="breadcrumb-item active">বকেয়া তালিকা</li>
        </ol></nav>
    </div>
    <div class="d-flex gap-2">
        <!-- View toggle -->
        <div class="btn-group" role="group">
            <a href="?<?= http_build_query(array_merge($_GET, ['view'=>'table'])) ?>"
               class="btn btn-sm <?= $viewMode==='table' ? 'btn-primary' : 'btn-outline-primary' ?>">
                <i class="bi bi-table"></i>
            </a>
            <a href="<?= SITE_URL ?>/modules/defaulter/map.php"
               class="btn btn-sm btn-outline-primary">
                <i class="bi bi-map"></i>
            </a>
        </div>
        <?php if (hasPermission('defaulters', 'create')): ?>
        <a href="<?= SITE_URL ?>/modules/defaulter/add.php" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-circle me-1"></i>নতুন এন্ট্রি
        </a>
        <?php endif; ?>
        <?php if (hasPermission('defaulters', 'export')): ?>
        <a href="<?= SITE_URL ?>/modules/reports/export.php?<?= http_build_query(array_filter(['q'=>$search,'status'=>$status,'risk'=>$riskFilter,'company'=>$company])) ?>"
           class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-download me-1"></i>এক্সপোর্ট
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Status Tabs -->
<div class="card mb-3">
    <div class="card-body py-2 px-3">
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <div class="d-flex gap-1">
                <?php
                $statusTabs = [
                    '' => ['label' => 'সব', 'class' => 'secondary'],
                    'active'   => ['label' => 'সক্রিয়',        'class' => 'danger'],
                    'resolved' => ['label' => 'সমাধান হয়েছে', 'class' => 'success'],
                    'disputed' => ['label' => 'বিরোধ আছে',    'class' => 'warning'],
                ];
                foreach ($statusTabs as $sv => $info):
                    $cnt = $sv ? ($statusCounts[$sv] ?? 0) : array_sum($statusCounts);
                    $active = $status === $sv; ?>
                <a href="?<?= http_build_query(array_merge(array_filter($_GET), ['status'=>$sv,'page'=>1])) ?>"
                   class="btn btn-sm <?= $active ? 'btn-'.$info['class'] : 'btn-outline-'.$info['class'] ?>"
                   style="border-radius:20px;font-size:13px;">
                    <?= $info['label'] ?> <span class="badge bg-white text-dark ms-1"><?= $cnt ?></span>
                </a>
                <?php endforeach; ?>
            </div>

            <!-- Quick Search -->
            <form class="ms-auto d-flex gap-2" method="GET">
                <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
                <input type="hidden" name="view" value="<?= htmlspecialchars($viewMode) ?>">
                <input type="text" name="q" class="form-control form-control-sm"
                       style="width:220px;border-radius:20px;"
                       placeholder="<?= $searchType==='nid' ? 'NID নম্বর লিখুন...' : ($searchType==='phone' ? 'মোবাইল নম্বর...' : ($searchType==='name' ? 'গ্রাহকের নাম...' : 'নাম / মোবাইল / NID...')) ?>"
                       value="<?= htmlspecialchars($search) ?>">
                <!-- A-2.1: Search type selector -->
                <select name="search_type" class="form-select form-select-sm"
                        style="width:auto;border-radius:20px;border-color:#e2e8f0;"
                        onchange="this.form.submit()">
                    <option value="all"   <?= $searchType==='all'   ?'selected':'' ?>>সব</option>
                    <option value="phone" <?= $searchType==='phone' ?'selected':'' ?>>📞 ফোন</option>
                    <option value="nid"   <?= $searchType==='nid'   ?'selected':'' ?>>🪪 NID</option>
                    <option value="name"  <?= $searchType==='name'  ?'selected':'' ?>>👤 নাম</option>
                </select>
                <button class="btn btn-sm btn-primary" style="border-radius:20px;"><i class="bi bi-search"></i></button>
                <?php if ($search): ?>
                <a href="?status=<?= $status ?>" class="btn btn-sm btn-outline-secondary" style="border-radius:20px;"><i class="bi bi-x"></i></a>
                <?php endif; ?>
            </form>

            <!-- Advanced Filter Toggle -->
            <button class="btn btn-sm btn-outline-secondary" style="border-radius:20px;"
                    type="button" data-bs-toggle="collapse" data-bs-target="#advFilter">
                <i class="bi bi-funnel me-1"></i>ফিল্টার
            </button>
        </div>

        <!-- Advanced Filters -->
        <div class="collapse <?= ($riskFilter || $typeFilter || $company || $areaFilter) ? 'show' : '' ?> mt-2" id="advFilter">
            <form method="GET" class="d-flex flex-wrap gap-2 pt-2 border-top">
                <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
                <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>">
                <input type="hidden" name="view" value="<?= htmlspecialchars($viewMode) ?>">
                <select name="risk" class="form-select form-select-sm" style="width:150px;border-radius:8px;">
                    <option value="">সব ঝুঁকি</option>
                    <?php foreach (['critical','high','medium','low'] as $r): ?>
                    <option value="<?= $r ?>" <?= $riskFilter===$r?'selected':'' ?>><?= getStatusLabel($r) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="type" class="form-select form-select-sm" style="width:160px;border-radius:8px;">
                    <option value="">সব ধরন</option>
                    <?php foreach (['due_payment','fraud','equipment_theft','contract_breach','other'] as $t): ?>
                    <option value="<?= $t ?>" <?= $typeFilter===$t?'selected':'' ?>><?= getStatusLabel($t) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="company" class="form-select form-select-sm" style="width:180px;border-radius:8px;">
                    <option value="">সব কোম্পানি</option>
                    <?php foreach ($companies as $co): ?>
                    <option value="<?= $co['id'] ?>" <?= $company==$co['id']?'selected':'' ?>><?= htmlspecialchars($co['company_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="area" class="form-control form-control-sm" style="width:150px;border-radius:8px;"
                       placeholder="এলাকা" value="<?= htmlspecialchars($areaFilter) ?>">
                <button class="btn btn-sm btn-primary" style="border-radius:8px;">প্রয়োগ</button>
                <a href="?status=<?= $status ?>" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;">রিসেট</a>
            </form>
        </div>
    </div>
</div>


<?php if ($viewMode === 'map' && $mapsKey): ?>
<!-- ============================= MAP VIEW ============================= -->
<div class="card">
    <div class="card-header justify-content-between">
        <h6 class="card-title"><i class="bi bi-map me-2"></i>ম্যাপ ভিউ — <?= count($mapDefaulters) ?> টি লোকেশন</h6>
        <small class="text-muted">শুধু যাদের লোকেশন পিন আছে তারাই দেখাচ্ছে</small>
    </div>
    <div class="card-body p-0">
        <div id="defaulterMap" style="height:520px;border-radius:0 0 12px 12px;"></div>
    </div>
</div>

<script>
const mapDefaulters = <?= json_encode(array_map(fn($d) => [
    'id'           => $d['id'],
    'name'         => $d['customer_name'],
    'phone'        => $d['customer_phone'],
    'due'          => formatMoney($d['due_amount']),
    'risk'         => $d['risk_level'],
    'status'       => $d['status'],
    'company'      => $d['company_name'],
    'address'      => $d['address_text'],
    'lat'          => (float)$d['lat'],
    'lng'          => (float)$d['lng'],
    'photo'        => $d['primary_photo'] ? UPLOAD_URL . $d['primary_photo'] : '',
    'url'          => SITE_URL . '/modules/defaulter/view.php?id=' . $d['id'],
], $mapDefaulters), JSON_UNESCAPED_UNICODE) ?>;

const riskColors = { critical:'#dc2626', high:'#ea580c', medium:'#2563a8', low:'#16a34a' };

function initMap() { try {
    const center = mapDefaulters.length
        ? { lat: mapDefaulters[0].lat, lng: mapDefaulters[0].lng }
        : { lat: 23.8103, lng: 90.4125 }; // Dhaka default

    const map = new google.maps.Map(document.getElementById('defaulterMap'), {
        zoom: 13, center,
        styles: [{ featureType:'poi', elementType:'labels', stylers:[{visibility:'off'}] }]
    });

    const infoWindow = new google.maps.InfoWindow();
    mapDefaulters.forEach(d => {
        const marker = new google.maps.Marker({
            position: { lat: d.lat, lng: d.lng },
            map,
            title: d.name,
            icon: {
                path: google.maps.SymbolPath.CIRCLE,
                scale: 9,
                fillColor: riskColors[d.risk] || '#64748b',
                fillOpacity: 1,
                strokeColor: '#fff',
                strokeWeight: 2,
            }
        });
        marker.addListener('click', () => {
            infoWindow.setContent(`
                <div style="font-family:'Hind Siliguri',sans-serif;min-width:200px;padding:4px;">
                    <div style="font-size:15px;font-weight:700;margin-bottom:4px;">${d.name}</div>
                    <div style="font-size:13px;color:#64748b;margin-bottom:6px;">${d.phone}</div>
                    <div style="font-size:13px;"><strong style="color:#dc2626;">বকেয়া: ${d.due}</strong></div>
                    <div style="font-size:12px;color:#64748b;margin-top:2px;">${d.company}</div>
                    <div style="font-size:12px;color:#94a3b8;margin-top:2px;">${d.address}</div>
                    <a href="${d.url}" style="display:inline-block;margin-top:8px;background:#1a3a5c;color:#fff;
                       padding:4px 12px;border-radius:6px;text-decoration:none;font-size:12px;">বিস্তারিত দেখুন →</a>
                </div>`);
            infoWindow.open(map, marker);
        });
    });
} catch(e) { console.warn('Map init failed:', e); }
}

// ── A-3.4: Bulk Selection JS ─────────────────────────────
const selectAll     = document.getElementById('selectAll');
const bulkToolbar   = document.getElementById('bulkToolbar');
const selectedCount = document.getElementById('selectedCount');
const bulkForm      = document.getElementById('bulkForm');

function updateBulkToolbar() {
    const checked = document.querySelectorAll('.row-checkbox:checked');
    const n = checked.length;
    if (n > 0) {
        bulkToolbar.style.display = 'block';
        selectedCount.textContent = n + 'টি নির্বাচিত';
    } else {
        bulkToolbar.style.display = 'none';
    }
}

if (selectAll) {
    selectAll.addEventListener('change', function() {
        document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = this.checked);
        updateBulkToolbar();
    });
}

document.addEventListener('change', function(e) {
    if (e.target.classList.contains('row-checkbox')) {
        const all = document.querySelectorAll('.row-checkbox');
        const checked = document.querySelectorAll('.row-checkbox:checked');
        if (selectAll) selectAll.checked = all.length === checked.length;
        updateBulkToolbar();
    }
});

function doBulkAction(action) {
    const checked = document.querySelectorAll('.row-checkbox:checked');
    if (checked.length === 0) return;
    document.getElementById('bulkActionInput').value = action;
    bulkForm.submit();
}

function clearSelection() {
    document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = false);
    if (selectAll) selectAll.checked = false;
    updateBulkToolbar();
}
</script>
<script src="https://maps.googleapis.com/maps/api/js?key=<?= htmlspecialchars($mapsKey) ?>&callback=initMap" async defer></script>

<?php else: ?>
<!-- ============================= TABLE VIEW ============================= -->
<!-- A-3.4: Bulk Action Form -->
<form id="bulkForm" method="POST" action="">
    <?= csrfField() ?>
    <input type="hidden" name="bulk_action" id="bulkActionInput" value="">

    <!-- Bulk Toolbar (hidden unless checked) -->
    <div id="bulkToolbar" style="display:none;" class="mb-2">
        <div class="d-flex align-items-center gap-2 p-3 rounded-3" style="background:#1a3a5c;color:#fff;">
            <span class="fw-semibold" id="selectedCount">০টি নির্বাচিত</span>
            <div class="ms-auto d-flex gap-2">
                <?php if (hasPermission('defaulters','mark_done')): ?>
                <button type="button" class="btn btn-sm btn-success"
                        onclick="doBulkAction('mark_resolved')">
                    <i class="bi bi-check-circle me-1"></i>সমাধান চিহ্নিত করুন
                </button>
                <button type="button" class="btn btn-sm btn-warning"
                        onclick="doBulkAction('mark_disputed')">
                    <i class="bi bi-flag me-1"></i>বিরোধ চিহ্নিত করুন
                </button>
                <?php endif; ?>
                <?php if (isSuperAdmin()): ?>
                <button type="button" class="btn btn-sm btn-danger"
                        onclick="if(confirm('নির্বাচিত এন্ট্রি মুছবেন?')) doBulkAction('delete_selected')">
                    <i class="bi bi-trash me-1"></i>মুছুন
                </button>
                <?php endif; ?>
                <button type="button" class="btn btn-sm btn-outline-light"
                        onclick="clearSelection()">বাতিল</button>
            </div>
        </div>
    </div>

<div class="card table-card">
    <div class="table-responsive">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th style="width:50px;">
                        <input type="checkbox" id="selectAll" class="form-check-input"
                               style="cursor:pointer;" title="সব নির্বাচন করুন">
                    </th>
                    <th>গ্রাহকের তথ্য</th>
                    <th>মোবাইল</th>
                    <th>ঠিকানা</th>
                    <th>বকেয়া</th>
                    <th>স্কোর</th>
                    <th>ঝুঁকি</th>
                    <th>কোম্পানি</th>
                    <th>স্ট্যাটাস</th>
                    <th>অ্যাকশন</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($defaulters)): ?>
            <tr>
                <td colspan="10">
                    <div class="empty-state">
                        <i class="bi bi-clipboard-x d-block"></i>
                        <p>কোনো বকেয়া তথ্য পাওয়া যায়নি</p>
                        <?php if (hasPermission('defaulters','create')): ?>
                        <a href="<?= SITE_URL ?>/modules/defaulter/add.php" class="btn btn-primary btn-sm">
                            <i class="bi bi-plus-circle me-1"></i>প্রথম এন্ট্রি যোগ করুন
                        </a>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php else: foreach ($defaulters as $d): ?>
            <tr>
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <input type="checkbox" name="selected_ids[]" value="<?= $d['id'] ?>"
                               class="form-check-input row-checkbox" style="cursor:pointer;flex-shrink:0;">
                        <?php if ($d['primary_photo']): ?>
                            <img src="<?= UPLOAD_URL . $d['primary_photo'] ?>" class="customer-photo" alt="">
                        <?php else: ?>
                            <div class="customer-photo-placeholder"><i class="bi bi-person"></i></div>
                        <?php endif; ?>
                    </div>
                </td>
                <td>
                    <a href="<?= SITE_URL ?>/modules/defaulter/view.php?id=<?= $d['id'] ?>"
                       class="fw-semibold text-decoration-none text-dark">
                        <?= htmlspecialchars($d['customer_name']) ?>
                    </a>
                    <?php if ($d['photo_count'] > 0): ?>
                    <span class="badge bg-light text-muted ms-1" style="font-size:10px;">
                        <i class="bi bi-images"></i> <?= $d['photo_count'] ?>
                    </span>
                    <?php endif; ?>
                    <?php if ($d['open_disputes'] > 0): ?>
                    <span class="badge bg-warning text-dark ms-1" style="font-size:10px;">বিরোধ</span>
                    <?php endif; ?>
                    <?php if ($d['lat']): ?>
                    <i class="bi bi-geo-alt-fill text-success ms-1" style="font-size:12px;" title="ম্যাপ পিন আছে"></i>
                    <?php endif; ?>
                    <?php if (($d['company_count'] ?? 1) >= 2): ?>
                    <div class="mt-1"><?= CreditScore::repeatBadge((int)$d['company_count']) ?></div>
                    <?php endif; ?>
                    <div class="text-muted" style="font-size:11px;"><?= htmlspecialchars($d['nid_number'] ?? '') ?></div>
                </td>
                <td>
                    <a href="tel:<?= $d['customer_phone'] ?>" class="text-decoration-none text-dark" style="font-size:13px;">
                        <?= htmlspecialchars($d['customer_phone']) ?>
                    </a>
                    <?php if ($d['alt_phone']): ?>
                    <div style="font-size:11px;color:#94a3b8;"><?= htmlspecialchars($d['alt_phone']) ?></div>
                    <?php endif; ?>
                </td>
                <td style="max-width:160px;">
                    <div class="text-truncate" style="font-size:13px;" title="<?= htmlspecialchars($d['address_text']) ?>">
                        <?= htmlspecialchars($d['area'] ?? $d['address_text']) ?>
                    </div>
                    <?php if ($d['thana']): ?>
                    <div style="font-size:11px;color:#94a3b8;"><?= htmlspecialchars($d['thana']) ?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <strong style="color:#dc2626;font-size:14px;"><?= formatMoney($d['due_amount']) ?></strong>
                    <?php if ($d['service_period']): ?>
                    <div style="font-size:11px;color:#94a3b8;"><?= htmlspecialchars($d['service_period']) ?></div>
                    <?php endif; ?>
                </td>
                <td style="min-width:90px;">
                    <?php
                    $miniScore = CreditScore::calculate($d['customer_phone']);
                    ?>
                    <?= CreditScore::scoreBar($miniScore['score']) ?>
                    <?php if ($miniScore['blacklist']['level'] !== 'clean'): ?>
                    <div class="mt-1" style="font-size:10px;">
                        <?= CreditScore::blacklistBadge($miniScore['blacklist']) ?>
                    </div>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="risk-badge risk-<?= $d['risk_level'] ?>">
                        <?= getStatusLabel($d['risk_level']) ?>
                    </span>
                </td>
                <td><small style="font-size:12px;"><?= htmlspecialchars($d['company_name']) ?></small></td>
                <td>
                    <span class="badge bg-<?= getBadgeClass($d['status']) ?> rounded-pill" style="font-size:11px;">
                        <?= getStatusLabel($d['status']) ?>
                    </span>
                    <div class="text-muted" style="font-size:11px;"><?= timeAgo($d['created_at']) ?></div>
                </td>
                <td>
                    <div class="d-flex gap-1">
                        <a href="<?= SITE_URL ?>/modules/defaulter/view.php?id=<?= $d['id'] ?>"
                           class="btn btn-sm btn-outline-primary" style="border-radius:6px;padding:3px 8px;" title="দেখুন">
                            <i class="bi bi-eye"></i>
                        </a>

                        <?php
                        $canEdit = hasPermission('defaulters','edit_own') && $d['company_id'] == $user['company_id'];
                        $canDelete = hasPermission('defaulters','delete_any') ||
                                    (hasPermission('defaulters','delete_own') && $d['company_id'] == $user['company_id']);
                        ?>

                        <?php if ($canEdit): ?>
                        <a href="<?= SITE_URL ?>/modules/defaulter/edit.php?id=<?= $d['id'] ?>"
                           class="btn btn-sm btn-outline-warning" style="border-radius:6px;padding:3px 8px;" title="সম্পাদনা">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <?php endif; ?>

                        <?php if ($d['status'] === 'active' && hasPermission('defaulters','mark_done') && $d['company_id'] == $user['company_id']): ?>
                        <a href="<?= SITE_URL ?>/modules/defaulter/resolve.php?id=<?= $d['id'] ?>"
                           class="btn btn-sm btn-outline-success" style="border-radius:6px;padding:3px 8px;" title="সমাধান হয়েছে">
                            <i class="bi bi-check-circle"></i>
                        </a>
                        <?php endif; ?>

                        <?php if ($canDelete): ?>
                        <a href="<?= SITE_URL ?>/modules/defaulter/delete.php?id=<?= $d['id'] ?>"
                           class="btn btn-sm btn-outline-danger" style="border-radius:6px;padding:3px 8px;" title="মুছুন"
                           data-confirm="এই এন্ট্রিটি মুছে ফেলবেন?">
                            <i class="bi bi-trash"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($paging['total_pages'] > 1): ?>
    <div class="card-footer bg-white d-flex align-items-center justify-content-between flex-wrap gap-2">
        <small class="text-muted">মোট <?= number_format($paging['total']) ?> টি | দেখাচ্ছে <?= $paging['offset']+1 ?>–<?= min($paging['offset']+$perPage, $paging['total']) ?></small>
        <nav><ul class="pagination pagination-sm mb-0">
            <?php if ($paging['has_prev']): ?>
            <li class="page-item"><a class="page-link" href="<?= $paging['base_url'].($paging['current']-1) ?>">‹</a></li>
            <?php endif; ?>
            <?php for ($p = max(1,$paging['current']-2); $p <= min($paging['total_pages'],$paging['current']+2); $p++): ?>
            <li class="page-item <?= $p===$paging['current']?'active':'' ?>">
                <a class="page-link" href="<?= $paging['base_url'].$p ?>"><?= $p ?></a>
            </li>
            <?php endfor; ?>
            <?php if ($paging['has_next']): ?>
            <li class="page-item"><a class="page-link" href="<?= $paging['base_url'].($paging['current']+1) ?>">›</a></li>
            <?php endif; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
</form><!-- /#bulkForm A-3.4 end -->

<?php include __DIR__ . '/../../includes/footer.php'; ?>
