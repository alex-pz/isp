<?php
// ============================================================
// ADVANCED MAP — Phase B-2
// File: modules/defaulter/map.php
// B-2.1: Area Heatmap | B-2.2: Cluster Markers
// B-2.3: Radius Search | B-2.4: Route Planner
// ============================================================
require_once __DIR__ . '/../../includes/functions.php';
requirePermission('defaulters', 'view');

$user    = getCurrentUser();
$mapsKey = getSetting('google_maps_key');

// ── ফিল্টার ──────────────────────────────────────────────
$riskFilter = $_GET['risk']    ?? '';
$status     = $_GET['status']  ?? 'active';
$company    = $_GET['company'] ?? ($user['company_id'] ?? '');

$where  = 'd.lat IS NOT NULL AND d.lng IS NOT NULL AND d.status != ?';
$params = ['removed'];

if ($status && $status !== 'all') { $where .= ' AND d.status = ?';      $params[] = $status; }
if ($riskFilter)                  { $where .= ' AND d.risk_level = ?';  $params[] = $riskFilter; }
if ($company && !isSuperAdmin())  { $where .= ' AND d.company_id = ?';  $params[] = $user['company_id']; }
elseif ($company)                 { $where .= ' AND d.company_id = ?';  $params[] = $company; }

$defaulters = Database::fetchAll(
    "SELECT d.id, d.customer_name, d.customer_phone, d.due_amount,
            d.risk_level, d.status, d.lat, d.lng, d.area, d.address_text,
            d.nid_number, c.company_name,
            (SELECT photo_path FROM defaulter_photos WHERE defaulter_id = d.id AND is_primary=1 LIMIT 1) as photo
     FROM defaulters d
     JOIN companies c ON c.id = d.company_id
     WHERE $where
     ORDER BY d.risk_level, d.due_amount DESC
     LIMIT 500",
    $params
);

// Area summary for heatmap weights
$areaSummary = Database::fetchAll(
    "SELECT d.lat, d.lng, d.due_amount, d.risk_level
     FROM defaulters d
     WHERE $where",
    $params
);

$companies = Database::fetchAll(
    "SELECT id, company_name FROM companies WHERE status='approved' ORDER BY company_name"
);

$pageTitle = 'অ্যাডভান্সড ম্যাপ';
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-map me-2"></i>অ্যাডভান্সড ম্যাপ</h1>
        <nav><ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= SITE_URL ?>/dashboard.php">হোম</a></li>
            <li class="breadcrumb-item"><a href="list.php">তালিকা</a></li>
            <li class="breadcrumb-item active">অ্যাডভান্সড ম্যাপ</li>
        </ol></nav>
    </div>
    <div class="badge rounded-pill" style="background:#1a3a5c;color:#fff;font-size:13px;padding:8px 14px;">
        <i class="bi bi-geo-alt-fill me-1"></i><?= count($defaulters) ?> টি লোকেশন
    </div>
</div>

<?php if (!$mapsKey): ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-2"></i>
    Google Maps API Key সেট করা নেই।
    <a href="<?= SITE_URL ?>/modules/admin/settings.php" class="alert-link">সেটিংস থেকে যোগ করুন</a>
</div>
<?php else: ?>

<!-- ===== TOOLBAR ===== -->
<div class="card mb-3 p-3">
    <div class="d-flex flex-wrap gap-2 align-items-center">

        <!-- Map Mode Buttons (B-2.1, B-2.2) -->
        <div class="btn-group" role="group">
            <button class="btn btn-sm btn-primary" id="btnMarker" onclick="setMode('marker')" title="সাধারণ মার্কার">
                <i class="bi bi-geo-alt-fill me-1"></i>মার্কার
            </button>
            <button class="btn btn-sm btn-outline-primary" id="btnCluster" onclick="setMode('cluster')" title="B-2.2 ক্লাস্টার">
                <i class="bi bi-circles me-1"></i>ক্লাস্টার
            </button>
            <button class="btn btn-sm btn-outline-danger" id="btnHeat" onclick="setMode('heat')" title="B-2.1 হিটম্যাপ">
                <i class="bi bi-fire me-1"></i>হিটম্যাপ
            </button>
        </div>

        <div class="vr d-none d-md-block"></div>

        <!-- B-2.3: Radius Search -->
        <div class="d-flex align-items-center gap-2">
            <input type="number" id="radiusKm" class="form-control form-control-sm"
                   style="width:80px;" value="1" min="0.1" max="50" step="0.1" placeholder="km">
            <button class="btn btn-sm btn-outline-warning" onclick="enableRadiusSearch()" title="B-2.3 রেডিয়াস সার্চ">
                <i class="bi bi-record-circle me-1"></i>রেডিয়াস সার্চ
            </button>
            <button class="btn btn-sm btn-outline-secondary" onclick="clearRadius()" id="btnClearRadius" style="display:none;">
                <i class="bi bi-x"></i> ক্লিয়ার
            </button>
        </div>

        <div class="vr d-none d-md-block"></div>

        <!-- B-2.4: Route Planner -->
        <button class="btn btn-sm btn-outline-success" onclick="toggleRoutePlanner()" id="btnRoute" title="B-2.4 রুট প্ল্যানার">
            <i class="bi bi-signpost-split me-1"></i>রুট প্ল্যানার
        </button>

        <div class="ms-auto d-flex gap-2">
            <!-- Risk Filter -->
            <select class="form-select form-select-sm" style="width:120px;" onchange="filterByRisk(this.value)">
                <option value="">সব ঝুঁকি</option>
                <option value="critical">ক্রিটিকাল</option>
                <option value="high">উচ্চ</option>
                <option value="medium">মধ্যম</option>
                <option value="low">নিম্ন</option>
            </select>
            <!-- Status Filter -->
            <select class="form-select form-select-sm" style="width:120px;" onchange="filterByStatus(this.value)">
                <option value="active" <?= $status==='active'?'selected':'' ?>>সক্রিয়</option>
                <option value="resolved" <?= $status==='resolved'?'selected':'' ?>>সমাধান</option>
                <option value="all" <?= $status==='all'?'selected':'' ?>>সব</option>
            </select>
        </div>
    </div>
</div>

<!-- ===== ROUTE PLANNER PANEL (B-2.4) ===== -->
<div id="routePanel" class="card mb-3 p-3" style="display:none;border:2px solid #16a34a;">
    <div class="d-flex align-items-center justify-content-between mb-2">
        <h6 class="mb-0 fw-bold text-success"><i class="bi bi-signpost-split me-2"></i>রুট প্ল্যানার</h6>
        <button class="btn btn-sm btn-outline-secondary" onclick="toggleRoutePlanner()">বন্ধ করুন</button>
    </div>
    <div class="row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label small fw-semibold mb-1">শুরুর স্থান</label>
            <input type="text" id="routeStart" class="form-control form-control-sm"
                   placeholder="আপনার অবস্থান বা ঠিকানা">
        </div>
        <div class="col-md-2">
            <button class="btn btn-sm btn-outline-primary w-100" onclick="useMyLocation()">
                <i class="bi bi-crosshair me-1"></i>আমার লোকেশন
            </button>
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-semibold mb-1">ভ্রমণ পদ্ধতি</label>
            <select id="travelMode" class="form-select form-select-sm">
                <option value="DRIVING">🚗 গাড়ি</option>
                <option value="MOTORCYCLING">🏍️ মোটরসাইকেল</option>
                <option value="WALKING">🚶 হাঁটা</option>
                <option value="BICYCLING">🚲 সাইকেল</option>
            </select>
        </div>
        <div class="col-md-3">
            <button class="btn btn-success btn-sm w-100" onclick="calculateRoute()">
                <i class="bi bi-arrow-right-circle me-1"></i>রুট দেখান
            </button>
        </div>
    </div>
    <div id="routeInstructions" class="mt-2" style="max-height:160px;overflow-y:auto;font-size:12px;"></div>
    <div id="routeInfo" class="mt-1" style="font-size:12px;color:#16a34a;font-weight:600;"></div>
    <p class="text-muted small mt-2 mb-0">
        <i class="bi bi-info-circle me-1"></i>মার্কারে ক্লিক করে "এই গ্রাহকের কাছে যান" বলুন
    </p>
</div>

<!-- ===== RADIUS INFO (B-2.3) ===== -->
<div id="radiusInfo" class="alert alert-warning py-2 mb-3" style="display:none;font-size:13px;">
    <i class="bi bi-record-circle me-2"></i>
    ম্যাপে যেকোনো জায়গায় ক্লিক করুন — সেই এলাকার <strong id="radiusKmShow">1</strong> কি.মি. এর মধ্যে সব বকেয়াদার দেখাবে।
    <span id="radiusResult" class="fw-bold ms-2"></span>
</div>

<!-- ===== MAP ===== -->
<div class="card">
    <div class="card-body p-0">
        <div id="advancedMap" style="height:600px;border-radius:12px;"></div>
    </div>
</div>

<!-- Legend -->
<div class="d-flex flex-wrap gap-3 mt-2" style="font-size:12px;">
    <span><span style="display:inline-block;width:12px;height:12px;background:#dc2626;border-radius:50%;margin-right:4px;"></span>ক্রিটিকাল</span>
    <span><span style="display:inline-block;width:12px;height:12px;background:#ea580c;border-radius:50%;margin-right:4px;"></span>উচ্চ</span>
    <span><span style="display:inline-block;width:12px;height:12px;background:#2563a8;border-radius:50%;margin-right:4px;"></span>মধ্যম</span>
    <span><span style="display:inline-block;width:12px;height:12px;background:#16a34a;border-radius:50%;margin-right:4px;"></span>নিম্ন</span>
    <span class="ms-3 text-muted">হিটম্যাপ মোড: লাল = বেশি বকেয়া, নীল = কম</span>
</div>

<script>
const ALL_DATA = <?= json_encode(array_map(fn($d) => [
    'id'      => $d['id'],
    'name'    => $d['customer_name'],
    'phone'   => $d['customer_phone'],
    'due'     => (float)$d['due_amount'],
    'dueStr'  => formatMoney($d['due_amount']),
    'risk'    => $d['risk_level'],
    'status'  => $d['status'],
    'company' => $d['company_name'],
    'address' => $d['address_text'],
    'area'    => $d['area'] ?? '',
    'lat'     => (float)$d['lat'],
    'lng'     => (float)$d['lng'],
    'photo'   => $d['photo'] ? UPLOAD_URL . $d['photo'] : '',
    'url'     => SITE_URL . '/modules/defaulter/view.php?id=' . $d['id'],
    'weight'  => min(10, max(1, (int)($d['due_amount'] / 1000))), // heatmap weight
], $defaulters), JSON_UNESCAPED_UNICODE) ?>;

const riskColors = { critical:'#dc2626', high:'#ea580c', medium:'#2563a8', low:'#16a34a' };
const SITE_URL   = '<?= SITE_URL ?>';

let map, markers = [], heatmap, markerClusterer;
let currentMode   = 'marker';
let infoWindow;
let radiusCircle  = null;
let routePlanner  = false;
let routeTarget   = null;
let directionsRenderer, directionsService;
let filteredData  = [...ALL_DATA];
let riskFilterVal = '';

// ── Map Init ──────────────────────────────────────────────
function initMap() { try {
    const center = filteredData.length
        ? { lat: filteredData[0].lat, lng: filteredData[0].lng }
        : { lat: 23.8103, lng: 90.4125 };

    map = new google.maps.Map(document.getElementById('advancedMap'), {
        zoom: 13, center,
        mapTypeControl: true,
        fullscreenControl: true,
        styles: [{ featureType:'poi', elementType:'labels', stylers:[{visibility:'off'}] }]
    });

    infoWindow = new google.maps.InfoWindow();
    directionsService  = new google.maps.DirectionsService();
    directionsRenderer = new google.maps.DirectionsRenderer({ suppressMarkers: false });
    directionsRenderer.setMap(map);

    // B-2.3: Radius click listener
    map.addListener('click', (e) => {
        if (radiusCircle) {
            drawRadius(e.latLng);
        }
    });

    renderMode('marker');
} catch(e) { console.warn('Map init failed:', e); } }

// ── Render Mode ───────────────────────────────────────────
function renderMode(mode) {
    clearAll();
    currentMode = mode;
    updateModeButtons();

    if (mode === 'marker')  renderMarkers();
    if (mode === 'cluster') renderClusters();
    if (mode === 'heat')    renderHeatmap();
}

function setMode(mode) { renderMode(mode); }

function clearAll() {
    markers.forEach(m => m.setMap(null));
    markers = [];
    if (heatmap)         { heatmap.setMap(null); heatmap = null; }
    if (markerClusterer) { markerClusterer.clearMarkers(); markerClusterer = null; }
}

// ── B-2.2: Regular Markers ────────────────────────────────
function renderMarkers() {
    filteredData.forEach(d => {
        const marker = new google.maps.Marker({
            position: { lat: d.lat, lng: d.lng },
            map,
            title: d.name,
            icon: {
                path: google.maps.SymbolPath.CIRCLE,
                scale: 10,
                fillColor: riskColors[d.risk] || '#64748b',
                fillOpacity: 1,
                strokeColor: '#fff',
                strokeWeight: 2,
            }
        });
        marker.data = d;
        marker.addListener('click', () => showInfo(marker, d));
        markers.push(marker);
    });
}

// ── B-2.2: Cluster Markers ────────────────────────────────
function renderClusters() {
    // Create markers first
    filteredData.forEach(d => {
        const marker = new google.maps.Marker({
            position: { lat: d.lat, lng: d.lng },
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
        marker.data = d;
        marker.addListener('click', () => showInfo(marker, d));
        markers.push(marker);
    });

    // MarkerClusterer via CDN
    if (typeof MarkerClusterer !== 'undefined') {
        markerClusterer = new MarkerClusterer(map, markers, {
            imagePath: 'https://developers.google.com/maps/documentation/javascript/examples/markerclusterer/m',
            gridSize: 50,
            maxZoom: 14,
        });
    } else {
        // Fallback: just show markers
        markers.forEach(m => m.setMap(map));
        console.info('MarkerClusterer not loaded, showing plain markers');
    }
}

// ── B-2.1: Heatmap ────────────────────────────────────────
function renderHeatmap() {
    const heatData = filteredData.map(d => ({
        location: new google.maps.LatLng(d.lat, d.lng),
        weight: d.weight
    }));

    heatmap = new google.maps.visualization.HeatmapLayer({
        data: heatData,
        map,
        radius: 40,
        opacity: 0.75,
        gradient: [
            'rgba(0,128,255,0)',
            'rgba(0,128,255,1)',
            'rgba(0,255,128,1)',
            'rgba(255,255,0,1)',
            'rgba(255,128,0,1)',
            'rgba(255,0,0,1)',
        ]
    });

    // Also show small dots on heatmap for clickability
    filteredData.forEach(d => {
        const marker = new google.maps.Marker({
            position: { lat: d.lat, lng: d.lng },
            map,
            title: d.name,
            icon: {
                path: google.maps.SymbolPath.CIRCLE,
                scale: 5,
                fillColor: '#fff',
                fillOpacity: 0.6,
                strokeColor: riskColors[d.risk],
                strokeWeight: 2,
            }
        });
        marker.data = d;
        marker.addListener('click', () => showInfo(marker, d));
        markers.push(marker);
    });
}

// ── Info Window ───────────────────────────────────────────
function showInfo(marker, d) {
    const photo = d.photo
        ? `<img src="${d.photo}" style="width:40px;height:40px;border-radius:8px;object-fit:cover;float:right;margin-left:8px;">`
        : '';
    infoWindow.setContent(`
        <div style="font-family:'Noto Sans Bengali',Arial,sans-serif;min-width:220px;padding:4px;">
            ${photo}
            <div style="font-size:15px;font-weight:700;">${d.name}</div>
            <div style="font-size:12px;color:#64748b;">${d.phone}</div>
            <div style="font-size:14px;font-weight:700;color:#dc2626;margin:4px 0;">বকেয়া: ${d.dueStr}</div>
            <div style="font-size:11px;color:#94a3b8;">${d.company}</div>
            <div style="font-size:11px;color:#94a3b8;margin-bottom:8px;">${d.address || d.area || ''}</div>
            <div style="display:flex;gap:6px;">
                <a href="${d.url}" style="flex:1;text-align:center;background:#1a3a5c;color:#fff;
                   padding:5px 8px;border-radius:6px;text-decoration:none;font-size:11px;">
                   বিস্তারিত →</a>
                <button onclick="goRoute(${d.lat},${d.lng},'${d.name.replace(/'/g,"\\'")}');document.querySelector('.gm-ui-hover-effect').click();"
                   style="flex:1;background:#16a34a;color:#fff;border:none;
                   padding:5px 8px;border-radius:6px;font-size:11px;cursor:pointer;font-family:inherit;">
                   🗺️ রুট</button>
            </div>
        </div>`);
    infoWindow.open(map, marker);
}

// ── B-2.3: Radius Search ──────────────────────────────────
function enableRadiusSearch() {
    radiusCircle = true; // flag — will be replaced on click
    document.getElementById('radiusInfo').style.display = 'block';
    document.getElementById('btnClearRadius').style.display = 'inline-block';
    document.getElementById('radiusKmShow').textContent = document.getElementById('radiusKm').value;
    map.setOptions({ draggableCursor: 'crosshair' });
}

function drawRadius(latLng) {
    const km = parseFloat(document.getElementById('radiusKm').value) || 1;
    // Remove old circle
    if (radiusCircle && typeof radiusCircle.setMap === 'function') radiusCircle.setMap(null);

    radiusCircle = new google.maps.Circle({
        center: latLng,
        radius: km * 1000,
        map,
        strokeColor: '#2563a8',
        strokeOpacity: 0.8,
        strokeWeight: 2,
        fillColor: '#2563a8',
        fillOpacity: 0.12,
    });

    // Count markers inside
    const inside = filteredData.filter(d => {
        const dist = google.maps.geometry.spherical.computeDistanceBetween(
            latLng, new google.maps.LatLng(d.lat, d.lng)
        );
        return dist <= km * 1000;
    });

    document.getElementById('radiusResult').textContent =
        `এই এলাকায় ${inside.length} জন বকেয়াদার পাওয়া গেছে।`;
    map.setOptions({ draggableCursor: null });
}

function clearRadius() {
    if (radiusCircle && typeof radiusCircle.setMap === 'function') radiusCircle.setMap(null);
    radiusCircle = null;
    document.getElementById('radiusInfo').style.display = 'none';
    document.getElementById('btnClearRadius').style.display = 'none';
    document.getElementById('radiusResult').textContent = '';
    map.setOptions({ draggableCursor: null });
}

// ── B-2.4: Route Planner ─────────────────────────────────
function toggleRoutePlanner() {
    const panel = document.getElementById('routePanel');
    routePlanner = !routePlanner;
    panel.style.display = routePlanner ? 'block' : 'none';
    document.getElementById('btnRoute').classList.toggle('btn-success', routePlanner);
    document.getElementById('btnRoute').classList.toggle('btn-outline-success', !routePlanner);
}

function useMyLocation() {
    if (!navigator.geolocation) return alert('লোকেশন সাপোর্ট নেই।');
    navigator.geolocation.getCurrentPosition(pos => {
        const lat = pos.coords.latitude;
        const lng = pos.coords.longitude;
        document.getElementById('routeStart').value = lat + ',' + lng;
    }, () => alert('লোকেশন নেওয়া যায়নি।'));
}

function goRoute(lat, lng, name) {
    document.getElementById('routePanel').style.display = 'block';
    routePlanner = true;
    routeTarget  = { lat, lng, name };
    document.getElementById('routeInstructions').innerHTML =
        `<span class="text-success">গন্তব্য নির্বাচিত: <strong>${name}</strong></span>`;
}

function calculateRoute() {
    const start = document.getElementById('routeStart').value.trim();
    if (!start) return alert('শুরুর স্থান দিন।');
    if (!routeTarget) return alert('মার্কারে ক্লিক করে গন্তব্য নির্বাচন করুন।');

    const mode = document.getElementById('travelMode').value;
    directionsService.route({
        origin: start,
        destination: { lat: routeTarget.lat, lng: routeTarget.lng },
        travelMode: google.maps.TravelMode[mode === 'MOTORCYCLING' ? 'DRIVING' : mode],
    }, (result, status) => {
        if (status === 'OK') {
            directionsRenderer.setDirections(result);
            const leg = result.routes[0].legs[0];
            document.getElementById('routeInfo').innerHTML =
                `📍 ${leg.distance.text} &nbsp;·&nbsp; ⏱️ ${leg.duration.text}`;

            // Step-by-step
            const steps = leg.steps.map((s,i) =>
                `<div style="padding:3px 0;border-bottom:1px solid #f1f5f9;">
                    <strong>${i+1}.</strong> ${s.instructions} <span style="color:#64748b;">(${s.distance.text})</span>
                </div>`
            ).join('');
            document.getElementById('routeInstructions').innerHTML = steps;
        } else {
            alert('রুট পাওয়া যায়নি: ' + status);
        }
    });
}

// ── Filters ───────────────────────────────────────────────
function filterByRisk(risk) {
    riskFilterVal = risk;
    applyFilters();
}
function filterByStatus(status) {
    window.location.href = '?status=' + status;
}
function applyFilters() {
    filteredData = ALL_DATA.filter(d =>
        (!riskFilterVal || d.risk === riskFilterVal)
    );
    renderMode(currentMode);
}

// ── UI Helpers ────────────────────────────────────────────
function updateModeButtons() {
    document.getElementById('btnMarker').className  = currentMode==='marker'  ? 'btn btn-sm btn-primary' : 'btn btn-sm btn-outline-primary';
    document.getElementById('btnCluster').className = currentMode==='cluster' ? 'btn btn-sm btn-primary' : 'btn btn-sm btn-outline-primary';
    document.getElementById('btnHeat').className    = currentMode==='heat'    ? 'btn btn-sm btn-danger'  : 'btn btn-sm btn-outline-danger';
}
</script>

<!-- MarkerClusterer library (B-2.2) -->
<script src="https://unpkg.com/@googlemaps/markerclusterer/dist/index.min.js"></script>

<!-- Google Maps with Visualization (for Heatmap) -->
<script src="https://maps.googleapis.com/maps/api/js?key=<?= htmlspecialchars($mapsKey) ?>&libraries=visualization,geometry&callback=initMap" async defer></script>

<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
