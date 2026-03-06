<?php
// ============================================================
// DUPLICATE DETECTION (Phase A-2)
// File: modules/customer/duplicates.php
// ============================================================
require_once __DIR__ . '/../../includes/functions.php';
requirePermission('defaulters', 'view');

$user      = getCurrentUser();
$pageTitle = 'ডুপ্লিকেট শনাক্তকরণ';
$tab       = $_GET['tab'] ?? 'phone'; // phone | nid | name

// ============================================================
// SAME PHONE — multiple companies
// ============================================================
$samePhone = Database::fetchAll(
    "SELECT
        d.customer_phone,
        MAX(d.customer_name) as customer_name,
        COUNT(DISTINCT d.company_id) as company_count,
        COUNT(*) as total_entries,
        SUM(CASE WHEN d.status='active' THEN 1 ELSE 0 END) as active_count,
        SUM(CASE WHEN d.status='active' THEN d.due_amount ELSE 0 END) as total_due,
        GROUP_CONCAT(DISTINCT c.company_name ORDER BY c.company_name SEPARATOR ', ') as companies,
        MAX(CASE WHEN d.risk_level='critical' THEN 4 WHEN d.risk_level='high' THEN 3
                 WHEN d.risk_level='medium' THEN 2 ELSE 1 END) as max_risk_num,
        MAX(d.created_at) as last_entry
     FROM defaulters d
     JOIN companies c ON c.id = d.company_id
     GROUP BY d.customer_phone
     HAVING company_count >= 2
     ORDER BY active_count DESC, total_due DESC
     LIMIT 100"
);

// ============================================================
// SAME NID — different phones (fraud indicator)
// ============================================================
$sameNID = Database::fetchAll(
    "SELECT
        d.nid_number,
        COUNT(DISTINCT d.customer_phone) as phone_count,
        COUNT(DISTINCT d.customer_name)  as name_count,
        COUNT(*) as total_entries,
        SUM(CASE WHEN d.status='active' THEN d.due_amount ELSE 0 END) as total_due,
        GROUP_CONCAT(DISTINCT d.customer_name  ORDER BY d.customer_name  SEPARATOR ', ') as names,
        GROUP_CONCAT(DISTINCT d.customer_phone ORDER BY d.customer_phone SEPARATOR ', ') as phones,
        MAX(d.created_at) as last_entry
     FROM defaulters d
     WHERE d.nid_number IS NOT NULL AND d.nid_number != ''
     GROUP BY d.nid_number
     HAVING phone_count >= 2 OR name_count >= 2
     ORDER BY total_due DESC
     LIMIT 100"
);

// ============================================================
// SAME NAME + AREA — possible same person, different phone
// ============================================================
$sameName = Database::fetchAll(
    "SELECT
        d.customer_name,
        d.area,
        COUNT(DISTINCT d.customer_phone) as phone_count,
        COUNT(DISTINCT d.company_id) as company_count,
        COUNT(*) as total_entries,
        SUM(CASE WHEN d.status='active' THEN d.due_amount ELSE 0 END) as total_due,
        GROUP_CONCAT(DISTINCT d.customer_phone ORDER BY d.customer_phone SEPARATOR ', ') as phones,
        MAX(d.created_at) as last_entry
     FROM defaulters d
     WHERE d.customer_name IS NOT NULL AND LENGTH(d.customer_name) > 3
     GROUP BY d.customer_name, d.area
     HAVING phone_count >= 2 AND total_entries >= 2
     ORDER BY total_due DESC
     LIMIT 100"
);

// Stats
$totalDupPhone  = count($samePhone);
$totalDupNID    = count($sameNID);
$totalDupName   = count($sameName);
$totalSuspect   = Database::fetchOne(
    "SELECT COUNT(DISTINCT customer_phone) as cnt FROM defaulters
     WHERE customer_phone IN (
         SELECT customer_phone FROM defaulters GROUP BY customer_phone HAVING COUNT(DISTINCT company_id) >= 2
     ) AND status='active'"
)['cnt'] ?? 0;

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div>
        <h1>ডুপ্লিকেট শনাক্তকরণ</h1>
        <nav><ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= SITE_URL ?>/dashboard.php">হোম</a></li>
            <li class="breadcrumb-item active">ডুপ্লিকেট</li>
        </ol></nav>
    </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card p-3 text-center" style="border-left:4px solid #dc2626;">
            <div style="font-size:28px;font-weight:800;color:#dc2626;"><?= $totalSuspect ?></div>
            <div class="text-muted small">সন্দেহজনক গ্রাহক</div>
            <div style="font-size:11px;color:#94a3b8;">২+ কোম্পানিতে সক্রিয়</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card p-3 text-center" style="border-left:4px solid #ea580c;">
            <div style="font-size:28px;font-weight:800;color:#ea580c;"><?= $totalDupPhone ?></div>
            <div class="text-muted small">একই নম্বর, একাধিক কোম্পানি</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card p-3 text-center" style="border-left:4px solid #d97706;">
            <div style="font-size:28px;font-weight:800;color:#d97706;"><?= $totalDupNID ?></div>
            <div class="text-muted small">একই NID, ভিন্ন পরিচয়</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card p-3 text-center" style="border-left:4px solid #7c3aed;">
            <div style="font-size:28px;font-weight:800;color:#7c3aed;"><?= $totalDupName ?></div>
            <div class="text-muted small">একই নাম, ভিন্ন নম্বর</div>
        </div>
    </div>
</div>

<!-- Tabs -->
<div class="card mb-3">
    <div class="card-body py-2 px-3">
        <div class="d-flex flex-wrap gap-1">
            <a href="?tab=phone" class="btn btn-sm <?= $tab==='phone' ? 'btn-danger' : 'btn-outline-danger' ?>" style="border-radius:20px;font-size:13px;">
                <i class="bi bi-telephone me-1"></i>একই ফোন
                <span class="badge bg-white text-dark ms-1"><?= $totalDupPhone ?></span>
            </a>
            <a href="?tab=nid" class="btn btn-sm <?= $tab==='nid' ? 'btn-warning' : 'btn-outline-warning' ?>" style="border-radius:20px;font-size:13px;">
                <i class="bi bi-card-text me-1"></i>একই NID
                <span class="badge bg-white text-dark ms-1"><?= $totalDupNID ?></span>
            </a>
            <a href="?tab=name" class="btn btn-sm <?= $tab==='name' ? 'btn-primary' : 'btn-outline-primary' ?>" style="border-radius:20px;font-size:13px;">
                <i class="bi bi-person me-1"></i>একই নাম
                <span class="badge bg-white text-dark ms-1"><?= $totalDupName ?></span>
            </a>
        </div>
    </div>
</div>

<!-- ===== SAME PHONE TAB ===== -->
<?php if ($tab === 'phone'): ?>
<div class="card table-card">
    <div class="card-header">
        <h6 class="card-title text-danger">
            <i class="bi bi-exclamation-triangle me-2"></i>একই মোবাইলে একাধিক কোম্পানির বকেয়া
        </h6>
        <small class="text-muted">এরা একাধিক ISP কোম্পানিতে বকেয়া রেখে গেছে — সর্বোচ্চ ঝুঁকিপূর্ণ</small>
    </div>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead><tr>
                <th>গ্রাহক</th>
                <th>মোবাইল</th>
                <th class="text-center">কোম্পানি</th>
                <th class="text-center">সক্রিয় বকেয়া</th>
                <th>জড়িত কোম্পানি</th>
                <th>মোট বকেয়া</th>
                <th>শেষ এন্ট্রি</th>
                <th>অ্যাকশন</th>
            </tr></thead>
            <tbody>
            <?php if (empty($samePhone)): ?>
            <tr><td colspan="8" class="text-center text-muted py-5">
                <i class="bi bi-shield-check fs-2 text-success d-block mb-2"></i>কোনো ডুপ্লিকেট পাওয়া যায়নি
            </td></tr>
            <?php else: foreach ($samePhone as $d): ?>
            <tr>
                <td>
                    <div class="fw-semibold"><?= htmlspecialchars($d['customer_name']) ?></div>
                    <?php if ($d['active_count'] > 0): ?>
                    <span class="badge bg-danger rounded-pill" style="font-size:10px;">
                        <i class="bi bi-exclamation-triangle me-1"></i>সক্রিয় বকেয়া আছে
                    </span>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="../customer/profile.php?phone=<?= urlencode($d['customer_phone']) ?>"
                       class="fw-semibold text-decoration-none" style="color:#1a3a5c;">
                        <?= htmlspecialchars($d['customer_phone']) ?>
                    </a>
                </td>
                <td class="text-center">
                    <span class="badge bg-danger rounded-pill" style="font-size:13px;"><?= $d['company_count'] ?></span>
                </td>
                <td class="text-center">
                    <span class="fw-bold" style="color:<?= $d['active_count'] > 0 ? '#dc2626' : '#16a34a' ?>">
                        <?= $d['active_count'] ?>
                    </span>
                </td>
                <td>
                    <div style="font-size:12px;color:#64748b;max-width:200px;" title="<?= htmlspecialchars($d['companies']) ?>">
                        <?= htmlspecialchars(mb_substr($d['companies'], 0, 60, 'UTF-8')) ?>
                        <?= mb_strlen($d['companies'], 'UTF-8') > 60 ? '...' : '' ?>
                    </div>
                </td>
                <td>
                    <strong style="color:#dc2626;"><?= formatMoney($d['total_due']) ?></strong>
                </td>
                <td><small class="text-muted"><?= timeAgo($d['last_entry']) ?></small></td>
                <td>
                    <div class="d-flex flex-column gap-1">
                    <a href="../customer/profile.php?phone=<?= urlencode($d['customer_phone']) ?>"
                       class="btn btn-sm btn-outline-primary" style="border-radius:6px;padding:3px 10px;font-size:11px;">
                        <i class="bi bi-person-circle me-1"></i>প্রোফাইল
                    </a>
                    <?php if ($d['total_entries'] >= 2): ?>
                    <a href="merge.php?search_phone=<?= urlencode($d['customer_phone']) ?>"
                       class="btn btn-sm btn-outline-warning" style="border-radius:6px;padding:3px 10px;font-size:11px;">
                        <i class="bi bi-arrow-left-right me-1"></i>মার্জ
                    </a>
                    <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ===== SAME NID TAB ===== -->
<?php elseif ($tab === 'nid'): ?>
<div class="card table-card">
    <div class="card-header">
        <h6 class="card-title text-warning">
            <i class="bi bi-card-text me-2"></i>একই NID দিয়ে ভিন্ন পরিচয়ে সংযোগ
        </h6>
        <small class="text-muted">নাম বদলে বা ভিন্ন নম্বর দিয়ে নতুন সংযোগ নেওয়ার সম্ভাবনা</small>
    </div>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead><tr>
                <th>NID নম্বর</th>
                <th>নামগুলো</th>
                <th>ফোন নম্বরগুলো</th>
                <th class="text-center">ভিন্ন ফোন</th>
                <th class="text-center">ভিন্ন নাম</th>
                <th>মোট বকেয়া</th>
                <th>অ্যাকশন</th>
            </tr></thead>
            <tbody>
            <?php if (empty($sameNID)): ?>
            <tr><td colspan="7" class="text-center text-muted py-5">
                <i class="bi bi-shield-check fs-2 text-success d-block mb-2"></i>কোনো সন্দেহজনক NID পাওয়া যায়নি
            </td></tr>
            <?php else: foreach ($sameNID as $d): ?>
            <tr>
                <td>
                    <code style="background:#f1f5f9;padding:3px 8px;border-radius:4px;font-size:12px;">
                        <?= htmlspecialchars($d['nid_number']) ?>
                    </code>
                </td>
                <td>
                    <div style="font-size:12px;">
                        <?php foreach (explode(', ', $d['names']) as $name): ?>
                        <span class="badge" style="background:#f1f5f9;color:#374151;margin:1px;"><?= htmlspecialchars($name) ?></span>
                        <?php endforeach; ?>
                    </div>
                </td>
                <td>
                    <div style="font-size:12px;">
                        <?php foreach (explode(', ', $d['phones']) as $ph): ?>
                        <a href="../customer/profile.php?phone=<?= urlencode($ph) ?>"
                           class="badge text-decoration-none me-1" style="background:#eff6ff;color:#2563a8;">
                            <?= htmlspecialchars($ph) ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </td>
                <td class="text-center">
                    <span class="badge bg-<?= $d['phone_count'] > 1 ? 'danger' : 'secondary' ?> rounded-pill">
                        <?= $d['phone_count'] ?>
                    </span>
                </td>
                <td class="text-center">
                    <span class="badge bg-<?= $d['name_count'] > 1 ? 'warning text-dark' : 'secondary' ?> rounded-pill">
                        <?= $d['name_count'] ?>
                    </span>
                </td>
                <td><strong style="color:#dc2626;"><?= formatMoney($d['total_due']) ?></strong></td>
                <td>
                    <div class="d-flex flex-column gap-1">
                    <a href="../defaulter/list.php?q=<?= urlencode($d['nid_number']) ?>&search_type=nid"
                       class="btn btn-sm btn-outline-warning" style="border-radius:6px;padding:3px 10px;font-size:11px;">
                        <i class="bi bi-search me-1"></i>সব দেখুন
                    </a>
                    <a href="merge.php?search_phone=<?= urlencode(explode(', ', $d['phones'])[0]) ?>"
                       class="btn btn-sm btn-outline-danger" style="border-radius:6px;padding:3px 10px;font-size:11px;">
                        <i class="bi bi-arrow-left-right me-1"></i>মার্জ করুন
                    </a>
                    </div>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ===== SAME NAME TAB ===== -->
<?php elseif ($tab === 'name'): ?>
<div class="card table-card">
    <div class="card-header">
        <h6 class="card-title" style="color:#7c3aed;">
            <i class="bi bi-people me-2"></i>একই নাম ও এলাকায় ভিন্ন নম্বর
        </h6>
        <small class="text-muted">একই এলাকায় একই নামে ভিন্ন ফোন নম্বর — পরিচয় যাচাই প্রয়োজন</small>
    </div>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead><tr>
                <th>নাম</th>
                <th>এলাকা</th>
                <th>ফোন নম্বরগুলো</th>
                <th class="text-center">ভিন্ন ফোন</th>
                <th class="text-center">কোম্পানি</th>
                <th>মোট বকেয়া</th>
                <th>অ্যাকশন</th>
            </tr></thead>
            <tbody>
            <?php if (empty($sameName)): ?>
            <tr><td colspan="7" class="text-center text-muted py-5">
                <i class="bi bi-shield-check fs-2 text-success d-block mb-2"></i>কোনো সন্দেহজনক নাম পাওয়া যায়নি
            </td></tr>
            <?php else: foreach ($sameName as $d): ?>
            <tr>
                <td><div class="fw-semibold"><?= htmlspecialchars($d['customer_name']) ?></div></td>
                <td><small class="text-muted"><?= htmlspecialchars($d['area'] ?? '—') ?></small></td>
                <td>
                    <div style="font-size:12px;">
                        <?php foreach (explode(', ', $d['phones']) as $ph): ?>
                        <a href="../customer/profile.php?phone=<?= urlencode(trim($ph)) ?>"
                           class="badge text-decoration-none me-1" style="background:#eff6ff;color:#2563a8;">
                            <?= htmlspecialchars(trim($ph)) ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </td>
                <td class="text-center">
                    <span class="badge bg-primary rounded-pill"><?= $d['phone_count'] ?></span>
                </td>
                <td class="text-center"><?= $d['company_count'] ?></td>
                <td><strong style="color:#dc2626;"><?= formatMoney($d['total_due']) ?></strong></td>
                <td>
                    <div class="d-flex flex-column gap-1">
                    <a href="../defaulter/list.php?q=<?= urlencode($d['customer_name']) ?>&search_type=name"
                       class="btn btn-sm btn-outline-primary" style="border-radius:6px;padding:3px 10px;font-size:11px;">
                        <i class="bi bi-search me-1"></i>সব দেখুন
                    </a>
                    <a href="merge.php?search_phone=<?= urlencode(explode(', ', $d['phones'])[0]) ?>"
                       class="btn btn-sm btn-outline-secondary" style="border-radius:6px;padding:3px 10px;font-size:11px;">
                        <i class="bi bi-arrow-left-right me-1"></i>মার্জ করুন
                    </a>
                    </div>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
