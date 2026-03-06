<?php
// ============================================================
// DASHBOARD
// File: dashboard.php
// ============================================================
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$user      = getCurrentUser();
$pageTitle = 'ড্যাশবোর্ড';

// ---- Stats based on role ----
$isAdmin   = in_array($user['role_name'], ['super_admin', 'admin']);
$companyId = $user['company_id'];

// Global stats (admin) OR company stats
if ($isAdmin) {
    $totalDefaulters  = Database::count('defaulters', 'status = ?', ['active']);
    $totalResolved    = Database::count('defaulters', 'status = ?', ['resolved']);
    $totalCompanies   = Database::count('companies',  'status = ?', ['approved']);
    $pendingCompanies = Database::count('companies',  'status = ?', ['pending']);
    $totalDue         = Database::fetchOne("SELECT SUM(due_amount) as total FROM defaulters WHERE status = 'active'");
    $totalDueAmount   = (float)($totalDue['total'] ?? 0);
    $openDisputes     = Database::count('disputes', 'status = ?', ['open']);

    // Recent entries (all companies)
    $recentDefaulters = Database::fetchAll(
        "SELECT d.*, c.company_name, u.full_name as entered_by_name
         FROM defaulters d
         JOIN companies c ON c.id = d.company_id
         JOIN users u ON u.id = d.entered_by
         ORDER BY d.created_at DESC LIMIT 8"
    );

    // Stats by company
    $companyStats = Database::fetchAll(
        "SELECT c.company_name, c.area,
                COUNT(d.id) as total,
                SUM(CASE WHEN d.status='active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN d.status='resolved' THEN 1 ELSE 0 END) as resolved,
                SUM(CASE WHEN d.status='active' THEN d.due_amount ELSE 0 END) as total_due
         FROM companies c
         LEFT JOIN defaulters d ON d.company_id = c.id
         WHERE c.status = 'approved'
         GROUP BY c.id
         ORDER BY active DESC LIMIT 10"
    );

    // Risk breakdown
    $riskStats = Database::fetchAll(
        "SELECT risk_level, COUNT(*) as cnt FROM defaulters WHERE status = 'active' GROUP BY risk_level ORDER BY FIELD(risk_level,'critical','high','medium','low')"
    );

} else {
    // Company user — own stats only
    $totalDefaulters  = Database::count('defaulters', 'company_id = ? AND status = ?', [$companyId, 'active']);
    $totalResolved    = Database::count('defaulters', 'company_id = ? AND status = ?', [$companyId, 'resolved']);
    $pendingCompanies = 0;
    $openDisputes     = Database::count('disputes', 'status = ? AND defaulter_id IN (SELECT id FROM defaulters WHERE company_id = ?)', ['open', $companyId]);
    $totalDue         = Database::fetchOne("SELECT SUM(due_amount) as total FROM defaulters WHERE company_id = ? AND status = 'active'", [$companyId]);
    $totalDueAmount   = (float)($totalDue['total'] ?? 0);

    // Total visible (all approved companies)
    $totalCompanies   = Database::count('defaulters', 'status = ?', ['active']);

    $recentDefaulters = Database::fetchAll(
        "SELECT d.*, c.company_name, u.full_name as entered_by_name
         FROM defaulters d
         JOIN companies c ON c.id = d.company_id
         JOIN users u ON u.id = d.entered_by
         ORDER BY d.created_at DESC LIMIT 8"
    );

    $riskStats = Database::fetchAll(
        "SELECT risk_level, COUNT(*) as cnt FROM defaulters WHERE company_id = ? AND status = 'active' GROUP BY risk_level ORDER BY FIELD(risk_level,'critical','high','medium','low')",
        [$companyId]
    );
    $companyStats = [];
}

// Monthly chart data (last 6 months)
$monthlyData = Database::fetchAll(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') as month,
            COUNT(*) as count,
            SUM(due_amount) as total_due
     FROM defaulters
     WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
     " . (!$isAdmin ? "AND company_id = $companyId" : "") . "
     GROUP BY month ORDER BY month ASC"
);

include __DIR__ . '/includes/header.php';
?>

<!-- Page Header -->
<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div>
        <h1>ড্যাশবোর্ড</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item active">হোম</li>
            </ol>
        </nav>
    </div>
    <div class="d-flex align-items-center gap-2">
        <span class="text-muted small">স্বাগতম, <strong><?= htmlspecialchars($user['full_name']) ?></strong></span>
        <?php if (hasPermission('defaulters', 'create')): ?>
        <a href="<?= SITE_URL ?>/modules/defaulter/add.php" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-circle me-1"></i>নতুন এন্ট্রি
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- ============ STAT CARDS ============ -->
<div class="row g-3 mb-4">

    <div class="col-6 col-lg-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#dc2626,#ef4444);">
            <div class="stat-icon"><i class="bi bi-exclamation-triangle"></i></div>
            <div>
                <div class="stat-value"><?= number_format($totalDefaulters) ?></div>
                <div class="stat-label"><?= $isAdmin ? 'সক্রিয় বকেয়া' : 'আমার এন্ট্রি' ?></div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#059669,#10b981);">
            <div class="stat-icon"><i class="bi bi-check-circle"></i></div>
            <div>
                <div class="stat-value"><?= number_format($totalResolved) ?></div>
                <div class="stat-label">সমাধান হয়েছে</div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#d97706,#f59e0b);">
            <div class="stat-icon"><i class="bi bi-currency-dollar"></i></div>
            <div>
                <div class="stat-value" style="font-size:20px;"><?= formatMoney($totalDueAmount) ?></div>
                <div class="stat-label">মোট বকেয়া টাকা</div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <?php if ($isAdmin): ?>
        <div class="stat-card" style="background:linear-gradient(135deg,#1a3a5c,#2563a8);">
            <div class="stat-icon"><i class="bi bi-building"></i></div>
            <div>
                <div class="stat-value"><?= $totalCompanies ?></div>
                <div class="stat-label">
                    অনুমোদিত কোম্পানি
                    <?php if ($pendingCompanies > 0): ?>
                    <br><small style="background:rgba(255,255,255,.2);border-radius:4px;padding:1px 6px;">
                        <?= $pendingCompanies ?> পেন্ডিং
                    </small>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="stat-card" style="background:linear-gradient(135deg,#1a3a5c,#2563a8);">
            <div class="stat-icon"><i class="bi bi-globe"></i></div>
            <div>
                <div class="stat-value"><?= $totalCompanies ?></div>
                <div class="stat-label">সব কোম্পানির সক্রিয় বকেয়া</div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ============ ALERTS ============ -->
<?php if ($isAdmin && $pendingCompanies > 0): ?>
<div class="alert d-flex align-items-center gap-3 mb-4"
     style="background:#fffbeb;border:1px solid #fde68a;border-radius:12px;color:#92400e;">
    <i class="bi bi-building-exclamation fs-5"></i>
    <div>
        <strong><?= $pendingCompanies ?>টি</strong> কোম্পানি অনুমোদনের অপেক্ষায় আছে।
        <a href="<?= SITE_URL ?>/modules/admin/companies.php?status=pending" class="fw-semibold text-decoration-none ms-2">
            এখনই দেখুন →
        </a>
    </div>
</div>
<?php endif; ?>

<?php if ($openDisputes > 0): ?>
<div class="alert d-flex align-items-center gap-3 mb-4"
     style="background:#fef2f2;border:1px solid #fecaca;border-radius:12px;color:#dc2626;">
    <i class="bi bi-flag fs-5"></i>
    <div>
        <strong><?= $openDisputes ?>টি</strong> বিরোধ সমাধান বাকি আছে।
        <a href="<?= SITE_URL ?>/modules/defaulter/disputes.php" class="fw-semibold text-decoration-none ms-2">
            দেখুন →
        </a>
    </div>
</div>
<?php endif; ?>

<!-- ============ CHARTS + RISK ============ -->
<div class="row g-3 mb-4">
    <!-- Monthly Chart -->
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header">
                <h6 class="card-title"><i class="bi bi-bar-chart-line me-2"></i>মাসিক এন্ট্রি (গত ৬ মাস)</h6>
            </div>
            <div class="card-body">
                <canvas id="monthlyChart" height="110"></canvas>
            </div>
        </div>
    </div>

    <!-- Risk Breakdown -->
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">
                <h6 class="card-title"><i class="bi bi-shield-exclamation me-2"></i>ঝুঁকির মাত্রা</h6>
            </div>
            <div class="card-body">
                <?php if (empty($riskStats)): ?>
                    <div class="empty-state" style="padding:30px 0;">
                        <i class="bi bi-shield-check d-block fs-3 text-success mb-2"></i>
                        <small>কোনো সক্রিয় বকেয়া নেই</small>
                    </div>
                <?php else: ?>
                    <canvas id="riskChart" height="190"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ============ RECENT ENTRIES ============ -->
<div class="row g-3">
    <div class="col-lg-<?= $isAdmin && !empty($companyStats) ? '8' : '12' ?>">
        <div class="card table-card">
            <div class="card-header justify-content-between">
                <h6 class="card-title"><i class="bi bi-clock-history me-2"></i>সাম্প্রতিক এন্ট্রি</h6>
                <a href="<?= SITE_URL ?>/modules/defaulter/list.php" class="btn btn-sm btn-outline-primary" style="border-radius:6px;font-size:12px;">
                    সব দেখুন <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>গ্রাহক</th>
                            <th>মোবাইল</th>
                            <th>বকেয়া</th>
                            <th>ঝুঁকি</th>
                            <th>কোম্পানি</th>
                            <th>স্ট্যাটাস</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentDefaulters)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">কোনো এন্ট্রি নেই</td>
                        </tr>
                        <?php else: foreach ($recentDefaulters as $d): ?>
                        <tr>
                            <td>
                                <a href="<?= SITE_URL ?>/modules/defaulter/view.php?id=<?= $d['id'] ?>"
                                   class="text-decoration-none text-dark fw-semibold">
                                    <?= htmlspecialchars($d['customer_name']) ?>
                                </a>
                                <div class="text-muted" style="font-size:11px;"><?= htmlspecialchars($d['area'] ?? '') ?></div>
                            </td>
                            <td><span style="font-size:13px;"><?= htmlspecialchars($d['customer_phone']) ?></span></td>
                            <td><strong style="color:#dc2626;"><?= formatMoney($d['due_amount']) ?></strong></td>
                            <td>
                                <span class="risk-badge risk-<?= $d['risk_level'] ?>">
                                    <?= getStatusLabel($d['risk_level']) ?>
                                </span>
                            </td>
                            <td><small><?= htmlspecialchars($d['company_name']) ?></small></td>
                            <td>
                                <span class="badge bg-<?= getBadgeClass($d['status']) ?> rounded-pill" style="font-size:11px;">
                                    <?= getStatusLabel($d['status']) ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Company Leaderboard (admin only) -->
    <?php if ($isAdmin && !empty($companyStats)): ?>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <h6 class="card-title"><i class="bi bi-building me-2"></i>কোম্পানি পরিসংখ্যান</h6>
            </div>
            <div class="card-body p-0">
                <?php foreach ($companyStats as $cs): ?>
                <div class="d-flex align-items-center gap-3 px-3 py-2 border-bottom" style="font-size:13px;">
                    <div style="width:36px;height:36px;border-radius:8px;background:#eff6ff;
                                color:#2563a8;display:flex;align-items:center;justify-content:center;
                                font-size:14px;font-weight:700;flex-shrink:0;">
                        <?= mb_substr($cs['company_name'], 0, 1, 'UTF-8') ?>
                    </div>
                    <div style="flex:1;min-width:0;">
                        <div class="fw-semibold text-truncate"><?= htmlspecialchars($cs['company_name']) ?></div>
                        <div class="text-muted" style="font-size:11px;"><?= htmlspecialchars($cs['area']) ?></div>
                    </div>
                    <div class="text-end">
                        <div style="color:#dc2626;font-weight:700;"><?= $cs['active'] ?></div>
                        <div class="text-muted" style="font-size:11px;">সক্রিয়</div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// Monthly Chart
const monthlyLabels = <?= json_encode(array_map(fn($r) => $r['month'], $monthlyData)) ?>;
const monthlyCounts = <?= json_encode(array_map(fn($r) => (int)$r['count'], $monthlyData)) ?>;

new Chart(document.getElementById('monthlyChart'), {
    type: 'bar',
    data: {
        labels: monthlyLabels,
        datasets: [{
            label: 'নতুন বকেয়া এন্ট্রি',
            data: monthlyCounts,
            backgroundColor: 'rgba(37,99,168,0.15)',
            borderColor: '#2563a8',
            borderWidth: 2,
            borderRadius: 6,
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { stepSize: 1 } },
            x: { grid: { display: false } }
        }
    }
});

// Risk Chart
<?php if (!empty($riskStats)): ?>
const riskLabels = <?= json_encode(array_map(fn($r) => getStatusLabel($r['risk_level']), $riskStats)) ?>;
const riskData   = <?= json_encode(array_map(fn($r) => (int)$r['cnt'], $riskStats)) ?>;
const riskColors = ['#dc2626','#ea580c','#2563a8','#16a34a'];

new Chart(document.getElementById('riskChart'), {
    type: 'doughnut',
    data: {
        labels: riskLabels,
        datasets: [{ data: riskData, backgroundColor: riskColors, borderWidth: 0 }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'bottom', labels: { font: { family: 'Hind Siliguri', size: 12 }, padding: 12 } }
        },
        cutout: '65%'
    }
});
<?php endif; ?>
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
