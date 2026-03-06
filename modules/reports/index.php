<?php
// ============================================================
// REPORTS DASHBOARD
// File: modules/reports/index.php
// ============================================================
require_once __DIR__ . '/../../includes/functions.php';
requirePermission('reports', 'view');

$user      = getCurrentUser();
$pageTitle = 'রিপোর্ট ও পরিসংখ্যান';
$isAdmin   = in_array($user['role_name'], ['super_admin', 'admin']);
$companyId = $user['company_id'];

// ---- Date Range Filter ----
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');          // এই মাসের শুরু
$dateTo   = $_GET['date_to']   ?? date('Y-m-d');           // আজকে
$cFilter  = $_GET['company']   ?? ($isAdmin ? '' : $companyId);

$whereBase  = "WHERE d.created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)";
$paramsBase = [$dateFrom, $dateTo];
if ($cFilter) { $whereBase .= " AND d.company_id = ?"; $paramsBase[] = $cFilter; }

// ============================================================
// SUMMARY STATS
// ============================================================
$summary = Database::fetchOne(
    "SELECT
        COUNT(*) as total_entries,
        COALESCE(SUM(CASE WHEN d.status = 'active'   THEN 1 ELSE 0 END), 0) as active_count,
        COALESCE(SUM(CASE WHEN d.status = 'resolved' THEN 1 ELSE 0 END), 0) as resolved_count,
        COALESCE(SUM(CASE WHEN d.status = 'disputed' THEN 1 ELSE 0 END), 0) as disputed_count,
        COALESCE(SUM(CASE WHEN d.status = 'active'   THEN d.due_amount ELSE 0 END), 0) as total_due,
        COALESCE(SUM(CASE WHEN d.status = 'resolved' THEN d.due_amount ELSE 0 END), 0) as total_recovered,
        COALESCE(SUM(CASE WHEN d.status = 'resolved' AND d.waiver_amount > 0 THEN d.waiver_amount ELSE 0 END), 0) as total_waived,
        COALESCE(AVG(d.due_amount), 0) as avg_due,
        COALESCE(MAX(d.due_amount), 0) as max_due,
        COUNT(DISTINCT d.company_id) as companies_involved
     FROM defaulters d $whereBase",
    $paramsBase
);
// null-safe defaults
$summary = array_merge([
    'total_entries'      => 0,
    'active_count'       => 0,
    'resolved_count'     => 0,
    'disputed_count'     => 0,
    'total_due'          => 0.0,
    'total_recovered'    => 0.0,
    'total_waived'       => 0.0,
    'avg_due'            => 0.0,
    'max_due'            => 0.0,
    'companies_involved' => 0,
], array_map(fn($v) => $v ?? 0, $summary ?? []));

// Recovery Rate
$totalDue       = (float)($summary['total_due'] ?? 0) + (float)($summary['total_recovered'] ?? 0);
$recoveryRate   = $totalDue > 0 ? round(($summary['total_recovered'] / $totalDue) * 100, 1) : 0;

// ============================================================
// B-1.4: FINANCIAL DASHBOARD — Payment + Waiver Stats
// ============================================================
$financialStats = Database::fetchOne(
    "SELECT
        COALESCE(SUM(CASE WHEN d.status='resolved' AND d.resolution_type='full_payment'    THEN d.payment_amount ELSE 0 END),0) as full_payment_total,
        COALESCE(SUM(CASE WHEN d.status='resolved' AND d.resolution_type='partial_payment' THEN d.payment_amount ELSE 0 END),0) as partial_payment_total,
        COALESCE(SUM(CASE WHEN d.status='resolved' AND d.resolution_type='waived'          THEN d.due_amount    ELSE 0 END),0) as waived_total,
        COALESCE(SUM(CASE WHEN d.status='resolved' THEN COALESCE(d.waiver_amount,0) ELSE 0 END),0) as total_waived,
        COUNT(CASE WHEN d.status='resolved' AND d.resolution_type='full_payment'    THEN 1 END) as full_payment_count,
        COUNT(CASE WHEN d.status='resolved' AND d.resolution_type='partial_payment' THEN 1 END) as partial_count,
        COUNT(CASE WHEN d.status='resolved' AND d.resolution_type='waived'          THEN 1 END) as waived_count
     FROM defaulters d $whereBase",
    $paramsBase
);
$financialStats = array_map(fn($v) => $v ?? 0, $financialStats ?? []);

// B-1.4: পেমেন্ট ট্র্যাকিং থেকে মাসিক আদায় (payments table)
try {
    $monthlyPayments = Database::fetchAll(
        "SELECT DATE_FORMAT(p.payment_date,'%Y-%m') as month,
                DATE_FORMAT(p.payment_date,'%b %Y') as month_label,
                SUM(p.amount) as total_paid, COUNT(*) as cnt
         FROM payments p
         JOIN defaulters d ON d.id = p.defaulter_id
         WHERE p.payment_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH) $whereBase
         GROUP BY month ORDER BY month ASC",
        $paramsBase
    );
} catch (Exception $e) { $monthlyPayments = []; }

// ============================================================
// MONTHLY TREND (last 12 months)
// ============================================================
$monthlyParams = [];
$monthlyCompanyClause = '';
if ($cFilter) { $monthlyCompanyClause = 'AND d.company_id = ?'; $monthlyParams[] = $cFilter; }
$monthlyTrend = Database::fetchAll(
    "SELECT
        DATE_FORMAT(d.created_at, '%Y-%m') as month,
        DATE_FORMAT(d.created_at, '%b %Y') as month_label,
        COUNT(*) as entries,
        SUM(d.due_amount) as total_due,
        SUM(CASE WHEN d.status='resolved' THEN 1 ELSE 0 END) as resolved
     FROM defaulters d
     WHERE d.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
     $monthlyCompanyClause
     GROUP BY month ORDER BY month ASC",
    $monthlyParams
);

// ============================================================
// RISK LEVEL BREAKDOWN
// ============================================================
$riskBreakdown = Database::fetchAll(
    "SELECT risk_level, COUNT(*) as cnt, SUM(due_amount) as total_due
     FROM defaulters d $whereBase AND d.status = 'active'
     GROUP BY risk_level ORDER BY FIELD(risk_level,'critical','high','medium','low')",
    $paramsBase
);

// ============================================================
// TYPE BREAKDOWN
// ============================================================
$typeBreakdown = Database::fetchAll(
    "SELECT type, COUNT(*) as cnt, SUM(due_amount) as total_due
     FROM defaulters d $whereBase
     GROUP BY type ORDER BY cnt DESC",
    $paramsBase
);

// ============================================================
// AREA WISE STATS (top 10)
// ============================================================
$areaStats = Database::fetchAll(
    "SELECT
        COALESCE(d.area, 'অজানা') as area,
        COUNT(*) as total,
        SUM(CASE WHEN d.status='active' THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN d.status='active' THEN d.due_amount ELSE 0 END) as due_amount
     FROM defaulters d $whereBase
     GROUP BY d.area ORDER BY active DESC LIMIT 10",
    $paramsBase
);

// ============================================================
// TOP DEFAULTERS (highest due)
// ============================================================
$topDefaulters = Database::fetchAll(
    "SELECT d.id, d.customer_name, d.customer_phone, d.due_amount,
            d.risk_level, d.status, d.area,
            COUNT(DISTINCT d2.company_id) as company_count,
            c.company_name
     FROM defaulters d
     JOIN companies c ON c.id = d.company_id
     LEFT JOIN defaulters d2 ON d2.customer_phone = d.customer_phone AND d2.status = 'active'
     $whereBase AND d.status = 'active'
     GROUP BY d.id
     ORDER BY d.due_amount DESC LIMIT 10",
    $paramsBase
);

// ============================================================
// COMPANY PERFORMANCE (admin only)
// ============================================================
$companyPerformance = [];
if ($isAdmin) {
    $companyPerformance = Database::fetchAll(
        "SELECT
            c.company_name, c.area,
            COUNT(d.id) as total,
            SUM(CASE WHEN d.status='active'   THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN d.status='resolved' THEN 1 ELSE 0 END) as resolved,
            SUM(CASE WHEN d.status='active'   THEN d.due_amount ELSE 0 END) as active_due,
            SUM(CASE WHEN d.status='resolved' THEN d.due_amount ELSE 0 END) as recovered,
            ROUND(SUM(CASE WHEN d.status='resolved' THEN 1 ELSE 0 END) /
                  NULLIF(COUNT(d.id),0) * 100, 1) as recovery_rate
         FROM companies c
         LEFT JOIN defaulters d ON d.company_id = c.id
            AND d.created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)
         WHERE c.status = 'approved'
         GROUP BY c.id ORDER BY active_due DESC",
        [$dateFrom, $dateTo]
    );
}

// ============================================================
// WEEKLY HEATMAP (last 7 weeks, by day)
// ============================================================
$heatmapParams = [];
$heatmapCompanyClause = '';
if ($cFilter) { $heatmapCompanyClause = 'AND d.company_id = ?'; $heatmapParams[] = $cFilter; }
$weeklyHeatmap = Database::fetchAll(
    "SELECT DAYOFWEEK(d.created_at) as dow,
            WEEK(d.created_at) as wk,
            COUNT(*) as cnt
     FROM defaulters d
     WHERE d.created_at >= DATE_SUB(NOW(), INTERVAL 7 WEEK)
     $heatmapCompanyClause
     GROUP BY dow, wk",
    $heatmapParams
);

// Companies list for filter
$companies = $isAdmin ? Database::fetchAll("SELECT id, company_name FROM companies WHERE status='approved' ORDER BY company_name") : [];

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div>
        <h1>রিপোর্ট ও পরিসংখ্যান</h1>
        <nav><ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= SITE_URL ?>/dashboard.php">হোম</a></li>
            <li class="breadcrumb-item active">রিপোর্ট</li>
        </ol></nav>
    </div>
    <?php if (hasPermission('reports','export')): ?>
    <div class="d-flex gap-2">
        <a href="export.php?format=excel&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>&company=<?= $cFilter ?>"
           class="btn btn-success btn-sm">
            <i class="bi bi-file-earmark-excel me-1"></i>Excel
        </a>
        <a href="export.php?format=pdf&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>&company=<?= $cFilter ?>"
           class="btn btn-danger btn-sm">
            <i class="bi bi-file-earmark-pdf me-1"></i>PDF
        </a>
    </div>
    <?php endif; ?>
</div>

<!-- ===== DATE FILTER ===== -->
<div class="card mb-4">
    <div class="card-body py-2 px-3">
        <form class="d-flex flex-wrap gap-2 align-items-center" method="GET">
            <label class="text-muted small fw-semibold">তারিখ:</label>
            <input type="date" name="date_from" class="form-control form-control-sm" style="width:150px;border-radius:8px;" value="<?= $dateFrom ?>">
            <span class="text-muted">—</span>
            <input type="date" name="date_to" class="form-control form-control-sm" style="width:150px;border-radius:8px;" value="<?= $dateTo ?>">
            <?php if ($isAdmin && !empty($companies)): ?>
            <select name="company" class="form-select form-select-sm" style="width:180px;border-radius:8px;">
                <option value="">সব কোম্পানি</option>
                <?php foreach ($companies as $co): ?>
                <option value="<?= $co['id'] ?>" <?= $cFilter == $co['id'] ? 'selected' : '' ?>><?= htmlspecialchars($co['company_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <!-- Quick date buttons -->
            <div class="d-flex gap-1 ms-2">
                <?php
                $quickDates = [
                    'আজ'         => [date('Y-m-d'), date('Y-m-d')],
                    'এই মাস'    => [date('Y-m-01'), date('Y-m-d')],
                    'গত মাস'    => [date('Y-m-01', strtotime('last month')), date('Y-m-t', strtotime('last month'))],
                    'এই বছর'   => [date('Y-01-01'), date('Y-m-d')],
                ];
                foreach ($quickDates as $label => $range): ?>
                <a href="?date_from=<?= $range[0] ?>&date_to=<?= $range[1] ?>&company=<?= $cFilter ?>"
                   class="btn btn-sm <?= ($dateFrom === $range[0] && $dateTo === $range[1]) ? 'btn-primary' : 'btn-outline-secondary' ?>"
                   style="border-radius:20px;font-size:12px;padding:3px 10px;">
                    <?= $label ?>
                </a>
                <?php endforeach; ?>
            </div>
            <button class="btn btn-sm btn-primary" style="border-radius:8px;"><i class="bi bi-search me-1"></i>প্রয়োগ</button>
        </form>
    </div>
</div>

<!-- ===== SUMMARY STATS ===== -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card h-100 p-3 text-center" style="border-left:4px solid #dc2626;">
            <div style="font-size:28px;font-weight:800;color:#dc2626;"><?= number_format((int)($summary['active_count'] ?? 0)) ?></div>
            <div class="text-muted small">সক্রিয় বকেয়া</div>
            <div style="font-size:12px;color:#dc2626;font-weight:600;"><?= formatMoney((float)($summary['total_due'] ?? 0.0)) ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100 p-3 text-center" style="border-left:4px solid #16a34a;">
            <div style="font-size:28px;font-weight:800;color:#16a34a;"><?= number_format((int)($summary['resolved_count'] ?? 0)) ?></div>
            <div class="text-muted small">সমাধান হয়েছে</div>
            <div style="font-size:12px;color:#16a34a;font-weight:600;"><?= formatMoney((float)($summary['total_recovered'] ?? 0.0)) ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100 p-3 text-center" style="border-left:4px solid #2563a8;">
            <div style="font-size:28px;font-weight:800;color:#2563a8;"><?= $recoveryRate ?>%</div>
            <div class="text-muted small">রিকভারি রেট</div>
            <div style="height:6px;background:#e2e8f0;border-radius:3px;margin-top:6px;overflow:hidden;">
                <div style="height:100%;width:<?= $recoveryRate ?>%;background:#2563a8;border-radius:3px;transition:width 1s;"></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100 p-3 text-center" style="border-left:4px solid #d97706;">
            <div style="font-size:28px;font-weight:800;color:#d97706;"><?= number_format((int)($summary['total_entries'] ?? 0)) ?></div>
            <div class="text-muted small">মোট এন্ট্রি</div>
            <div style="font-size:12px;color:#d97706;font-weight:600;">গড় <?= formatMoney((float)($summary['avg_due'] ?? 0.0)) ?></div>
        </div>
    </div>
</div>

<!-- ===== B-1.4: FINANCIAL DASHBOARD ===== -->
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card p-3">
            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                <h6 class="mb-0 fw-bold"><i class="bi bi-cash-coin me-2 text-success"></i>আর্থিক ড্যাশবোর্ড</h6>
            </div>
            <div class="row g-3">
                <!-- পূর্ণ পরিশোধ -->
                <div class="col-6 col-lg-3">
                    <div class="p-3 rounded-3 text-center" style="background:#f0fdf4;border:1px solid #bbf7d0;">
                        <div style="font-size:10px;text-transform:uppercase;letter-spacing:1px;color:#16a34a;font-weight:700;">পূর্ণ পরিশোধ</div>
                        <div style="font-size:22px;font-weight:800;color:#16a34a;"><?= formatMoney((float)($financialStats['full_payment_total'] ?? 0)) ?></div>
                        <div style="font-size:12px;color:#64748b;"><?= (int)($financialStats['full_payment_count'] ?? 0) ?>টি এন্ট্রি</div>
                    </div>
                </div>
                <!-- আংশিক পরিশোধ -->
                <div class="col-6 col-lg-3">
                    <div class="p-3 rounded-3 text-center" style="background:#eff6ff;border:1px solid #bfdbfe;">
                        <div style="font-size:10px;text-transform:uppercase;letter-spacing:1px;color:#2563a8;font-weight:700;">আংশিক পরিশোধ</div>
                        <div style="font-size:22px;font-weight:800;color:#2563a8;"><?= formatMoney((float)($financialStats['partial_payment_total'] ?? 0)) ?></div>
                        <div style="font-size:12px;color:#64748b;"><?= (int)($financialStats['partial_count'] ?? 0) ?>টি এন্ট্রি</div>
                    </div>
                </div>
                <!-- মাফ/ছাড় -->
                <div class="col-6 col-lg-3">
                    <div class="p-3 rounded-3 text-center" style="background:#fffbeb;border:1px solid #fde68a;">
                        <div style="font-size:10px;text-transform:uppercase;letter-spacing:1px;color:#d97706;font-weight:700;">মাফ / ছাড়</div>
                        <div style="font-size:22px;font-weight:800;color:#d97706;"><?= formatMoney((float)($financialStats['total_waived'] ?? 0)) ?></div>
                        <div style="font-size:12px;color:#64748b;"><?= (int)($financialStats['waived_count'] ?? 0) ?>টি সম্পূর্ণ মাফ</div>
                    </div>
                </div>
                <!-- মোট সক্রিয় বকেয়া -->
                <div class="col-6 col-lg-3">
                    <div class="p-3 rounded-3 text-center" style="background:#fef2f2;border:1px solid #fecaca;">
                        <div style="font-size:10px;text-transform:uppercase;letter-spacing:1px;color:#dc2626;font-weight:700;">সক্রিয় বকেয়া</div>
                        <div style="font-size:22px;font-weight:800;color:#dc2626;"><?= formatMoney((float)($summary['total_due'] ?? 0.0)) ?></div>
                        <div style="font-size:12px;color:#64748b;"><?= (int)($summary['active_count'] ?? 0) ?>টি পেন্ডিং</div>
                    </div>
                </div>
            </div>

            <?php if (!empty($monthlyPayments)): ?>
            <div class="mt-3 pt-3" style="border-top:1px solid #e2e8f0;">
                <div style="font-size:12px;font-weight:600;color:#64748b;margin-bottom:8px;">মাসিক আদায় (Payment Tracking)</div>
                <div class="d-flex gap-2 overflow-auto pb-1">
                <?php foreach ($monthlyPayments as $mp): ?>
                <div style="text-align:center;min-width:60px;flex-shrink:0;">
                    <div style="font-size:11px;font-weight:700;color:#16a34a;">৳<?= number_format($mp['total_paid']/1000, 1) ?>k</div>
                    <div style="height:40px;background:#f1f5f9;border-radius:4px;overflow:hidden;position:relative;margin:3px 0;">
                        <?php
                        $maxPay = max(array_column($monthlyPayments, 'total_paid') ?: [1]);
                        $barH   = $maxPay > 0 ? round(($mp['total_paid']/$maxPay)*40) : 0;
                        ?>
                        <div style="position:absolute;bottom:0;left:0;right:0;height:<?= $barH ?>px;background:#16a34a;border-radius:4px;"></div>
                    </div>
                    <div style="font-size:10px;color:#94a3b8;"><?= substr($mp['month_label'],0,3) ?></div>
                </div>
                <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ===== CHARTS ROW 1 ===== -->
<div class="row g-3 mb-4">
    <!-- Monthly Trend -->
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header justify-content-between">
                <h6 class="card-title"><i class="bi bi-graph-up me-2"></i>মাসিক প্রবণতা (১২ মাস)</h6>
                <div class="d-flex gap-2" style="font-size:12px;">
                    <span style="color:#dc2626;"><i class="bi bi-circle-fill me-1" style="font-size:8px;"></i>এন্ট্রি</span>
                    <span style="color:#16a34a;"><i class="bi bi-circle-fill me-1" style="font-size:8px;"></i>সমাধান</span>
                </div>
            </div>
            <div class="card-body"><canvas id="monthlyChart" height="120"></canvas></div>
        </div>
    </div>
    <!-- Risk Pie -->
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header"><h6 class="card-title"><i class="bi bi-pie-chart me-2"></i>ঝুঁকির বিভাজন</h6></div>
            <div class="card-body d-flex align-items-center justify-content-center">
                <?php if (!empty($riskBreakdown)): ?>
                <canvas id="riskChart" style="max-height:200px;"></canvas>
                <?php else: ?>
                <div class="text-center text-muted"><i class="bi bi-shield-check fs-2 d-block mb-2 text-success"></i>কোনো সক্রিয় বকেয়া নেই</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ===== CHARTS ROW 2 ===== -->
<div class="row g-3 mb-4">
    <!-- Type Breakdown -->
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header"><h6 class="card-title"><i class="bi bi-bar-chart me-2"></i>সমস্যার ধরন</h6></div>
            <div class="card-body">
                <?php foreach ($typeBreakdown as $t):
                    $maxCnt = max(array_column($typeBreakdown, 'cnt'));
                    $pct = $maxCnt > 0 ? ($t['cnt'] / $maxCnt * 100) : 0;
                ?>
                <div class="mb-3">
                    <div class="d-flex justify-content-between mb-1" style="font-size:13px;">
                        <span><?= getStatusLabel($t['type']) ?></span>
                        <span class="fw-bold"><?= $t['cnt'] ?> টি <span class="text-muted">(<?= formatMoney($t['total_due']) ?>)</span></span>
                    </div>
                    <div style="height:8px;background:#f1f5f9;border-radius:4px;overflow:hidden;">
                        <div style="height:100%;width:<?= $pct ?>%;background:#2563a8;border-radius:4px;transition:width 1s;"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <!-- Area Stats -->
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header"><h6 class="card-title"><i class="bi bi-geo-alt me-2"></i>এলাকাভিত্তিক বকেয়া (শীর্ষ ১০)</h6></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0" style="font-size:13px;">
                        <thead><tr>
                            <th class="ps-3">এলাকা</th>
                            <th class="text-center">সক্রিয়</th>
                            <th class="text-center">মোট</th>
                            <th class="text-end pe-3">বকেয়া টাকা</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($areaStats as $i => $a): ?>
                        <tr>
                            <td class="ps-3">
                                <span style="background:#f1f5f9;color:#475569;font-size:10px;padding:1px 5px;border-radius:4px;margin-right:5px;"><?= $i+1 ?></span>
                                <?= htmlspecialchars($a['area']) ?>
                            </td>
                            <td class="text-center">
                                <span class="fw-bold" style="color:#dc2626;"><?= $a['active'] ?></span>
                            </td>
                            <td class="text-center text-muted"><?= $a['total'] ?></td>
                            <td class="text-end pe-3 fw-semibold" style="color:#dc2626;"><?= formatMoney($a['due_amount']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ===== TOP DEFAULTERS ===== -->
<div class="card mb-4 table-card">
    <div class="card-header justify-content-between">
        <h6 class="card-title"><i class="bi bi-trophy me-2 text-danger"></i>শীর্ষ বকেয়াদার (সর্বোচ্চ বকেয়া)</h6>
        <a href="../defaulter/list.php?status=active" class="btn btn-sm btn-outline-primary" style="border-radius:6px;font-size:12px;">সব দেখুন</a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr>
                <th>#</th>
                <th>গ্রাহক</th>
                <th>মোবাইল</th>
                <th>এলাকা</th>
                <th>কোম্পানি সংখ্যা</th>
                <th>বকেয়া</th>
                <th>ঝুঁকি</th>
            </tr></thead>
            <tbody>
            <?php foreach ($topDefaulters as $i => $d): ?>
            <tr>
                <td>
                    <?php if ($i < 3): ?>
                    <span style="background:<?= ['#f59e0b','#94a3b8','#cd7f32'][$i] ?>;color:#fff;border-radius:50%;width:24px;height:24px;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;"><?= $i+1 ?></span>
                    <?php else: ?>
                    <span class="text-muted"><?= $i+1 ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="../defaulter/view.php?id=<?= $d['id'] ?>" class="fw-semibold text-decoration-none text-dark">
                        <?= htmlspecialchars($d['customer_name']) ?>
                    </a>
                </td>
                <td style="font-size:13px;"><?= htmlspecialchars($d['customer_phone']) ?></td>
                <td style="font-size:13px;"><?= htmlspecialchars($d['area'] ?? '—') ?></td>
                <td class="text-center">
                    <?php if ($d['company_count'] > 1): ?>
                    <span class="badge bg-danger rounded-pill"><?= $d['company_count'] ?> কোম্পানি</span>
                    <?php else: ?>
                    <span class="text-muted small"><?= htmlspecialchars($d['company_name']) ?></span>
                    <?php endif; ?>
                </td>
                <td><strong style="color:#dc2626;font-size:15px;"><?= formatMoney($d['due_amount']) ?></strong></td>
                <td><span class="risk-badge risk-<?= $d['risk_level'] ?>"><?= getStatusLabel($d['risk_level']) ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ===== COMPANY PERFORMANCE (admin only) ===== -->
<?php if ($isAdmin && !empty($companyPerformance)): ?>
<div class="card mb-4 table-card">
    <div class="card-header"><h6 class="card-title"><i class="bi bi-building me-2"></i>কোম্পানির কার্যক্ষমতা</h6></div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr>
                <th>কোম্পানি</th>
                <th class="text-center">মোট</th>
                <th class="text-center">সক্রিয়</th>
                <th class="text-center">সমাধান</th>
                <th>বকেয়া</th>
                <th>আদায়</th>
                <th>রিকভারি রেট</th>
            </tr></thead>
            <tbody>
            <?php foreach ($companyPerformance as $cp): ?>
            <tr>
                <td>
                    <div class="fw-semibold" style="font-size:14px;"><?= htmlspecialchars($cp['company_name']) ?></div>
                    <div class="text-muted small"><?= htmlspecialchars($cp['area']) ?></div>
                </td>
                <td class="text-center"><?= $cp['total'] ?></td>
                <td class="text-center"><span class="fw-bold text-danger"><?= $cp['active'] ?></span></td>
                <td class="text-center"><span class="fw-bold text-success"><?= $cp['resolved'] ?></span></td>
                <td style="color:#dc2626;font-weight:600;"><?= formatMoney($cp['active_due']) ?></td>
                <td style="color:#16a34a;font-weight:600;"><?= formatMoney($cp['recovered']) ?></td>
                <td>
                    <?php $rate = (float)($cp['recovery_rate'] ?? 0); ?>
                    <div class="d-flex align-items-center gap-2">
                        <div style="flex:1;height:6px;background:#f1f5f9;border-radius:3px;overflow:hidden;">
                            <div style="height:100%;width:<?= $rate ?>%;background:<?= $rate >= 70 ? '#16a34a' : ($rate >= 40 ? '#d97706' : '#dc2626') ?>;border-radius:3px;"></div>
                        </div>
                        <span style="font-size:12px;font-weight:600;"><?= $rate ?>%</span>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// Monthly Trend Chart
const mlabels = <?= json_encode(array_column($monthlyTrend, 'month_label')) ?>;
const mentries = <?= json_encode(array_map(fn($r) => (int)$r['entries'], $monthlyTrend)) ?>;
const mresolved = <?= json_encode(array_map(fn($r) => (int)$r['resolved'], $monthlyTrend)) ?>;

new Chart(document.getElementById('monthlyChart'), {
    type: 'bar',
    data: {
        labels: mlabels,
        datasets: [
            { label: 'নতুন এন্ট্রি', data: mentries, backgroundColor: 'rgba(220,38,38,0.15)', borderColor: '#dc2626', borderWidth: 2, borderRadius: 5 },
            { label: 'সমাধান', data: mresolved, backgroundColor: 'rgba(22,163,74,0.15)', borderColor: '#16a34a', borderWidth: 2, borderRadius: 5, type: 'line', tension: 0.4, fill: true }
        ]
    },
    options: {
        responsive: true, plugins: { legend: { position: 'top', labels: { font: { family: 'Hind Siliguri', size: 12 } } } },
        scales: { y: { beginAtZero: true, grid: { color: '#f1f5f9' } }, x: { grid: { display: false } } }
    }
});

// Risk Doughnut
<?php if (!empty($riskBreakdown)): ?>
new Chart(document.getElementById('riskChart'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_map(fn($r) => getStatusLabel($r['risk_level']), $riskBreakdown)) ?>,
        datasets: [{ data: <?= json_encode(array_column($riskBreakdown, 'cnt')) ?>, backgroundColor: ['#dc2626','#ea580c','#2563a8','#16a34a'], borderWidth: 0 }]
    },
    options: { cutout: '68%', plugins: { legend: { position: 'bottom', labels: { font: { family: 'Hind Siliguri', size: 12 }, padding: 12 } } } }
});
<?php endif; ?>
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>