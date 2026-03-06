<?php
// ============================================================
// EXPORT — Excel & PDF
// File: modules/reports/export.php
// ============================================================
require_once __DIR__ . '/../../includes/functions.php';
requirePermission('reports', 'view');

$user      = getCurrentUser();
$isAdmin   = in_array($user['role_name'], ['super_admin', 'admin']);

$format    = $_GET['format']    ?? '';   // excel | pdf | '' = selection page দেখাও
$dateFrom  = $_GET['date_from'] ?? '';
$dateTo    = $_GET['date_to']   ?? '';
$status    = $_GET['status']    ?? '';
$company   = $_GET['company']   ?? ($isAdmin ? '' : $user['company_id']);
$risk      = $_GET['risk']      ?? '';
$type      = $_GET['type']      ?? '';

$siteName   = getSetting('site_name', 'ISP Defaulter System');
$exportDate = date('d-m-Y H:i');
$fileName   = 'defaulter-list-' . date('Ymd-His');

// ============================================================
// FORMAT আছে — Query চালাও
// ============================================================
if ($format === 'excel' || $format === 'pdf') {

    $where  = '1=1';
    $params = [];

    if ($dateFrom) { $where .= ' AND d.created_at >= ?';                          $params[] = $dateFrom; }
    if ($dateTo)   { $where .= ' AND d.created_at <= DATE_ADD(?, INTERVAL 1 DAY)'; $params[] = $dateTo; }
    if ($status)   { $where .= ' AND d.status = ?';                                $params[] = $status; }
    if ($company)  { $where .= ' AND d.company_id = ?';                            $params[] = $company; }
    if ($risk)     { $where .= ' AND d.risk_level = ?';                            $params[] = $risk; }
    if ($type)     { $where .= ' AND d.type = ?';                                  $params[] = $type; }

    $defaulters = Database::fetchAll(
        "SELECT d.id, d.customer_name, d.customer_phone, d.alt_phone,
                d.nid_number, d.address_text, d.area, d.thana, d.district,
                d.due_amount, d.type, d.risk_level, d.status,
                d.connection_type, d.service_period, d.description,
                d.created_at, d.resolved_at,
                c.company_name, c.phone as company_phone,
                u.full_name as entered_by
         FROM defaulters d
         JOIN companies c ON c.id = d.company_id
         JOIN users u ON u.id = d.entered_by
         WHERE $where
         ORDER BY d.created_at DESC
         LIMIT 5000",
        $params
    );

    logActivity('report.export', 'reports', [
        'description' => count($defaulters) . ' টি রেকর্ড ' . strtoupper($format) . ' এ এক্সপোর্ট'
    ]);

    // ============================================================
    // EXCEL EXPORT
    // ============================================================
    if ($format === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fileName . '.xls"');
        header('Cache-Control: max-age=0');
        echo "\xEF\xBB\xBF"; // UTF-8 BOM
        ?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">
<head><meta charset="UTF-8">
<style>
body { font-family: Arial, sans-serif; }
table { border-collapse: collapse; width: 100%; }
th { background: #1a3a5c; color: white; padding: 8px; border: 1px solid #ccc; font-size: 12px; }
td { padding: 6px 8px; border: 1px solid #ddd; font-size: 11px; vertical-align: top; }
tr:nth-child(even) td { background: #f8f9fa; }
.title { font-size: 16px; font-weight: bold; color: #0f2238; }
.danger { color: #dc2626; font-weight: bold; }
</style>
</head>
<body>
<table>
    <tr><td colspan="16" class="title"><?= htmlspecialchars($siteName) ?> — বকেয়া তালিকা</td></tr>
    <tr><td colspan="16">এক্সপোর্ট তারিখ: <?= $exportDate ?> | মোট রেকর্ড: <?= count($defaulters) ?></td></tr>
    <tr><td colspan="16"></td></tr>
    <tr>
        <th>#</th><th>গ্রাহকের নাম</th><th>মোবাইল</th><th>বিকল্প নম্বর</th>
        <th>NID নম্বর</th><th>ঠিকানা</th><th>এলাকা</th><th>থানা</th>
        <th>জেলা</th><th>বকেয়া (টাকা)</th><th>ধরন</th><th>ঝুঁকি</th>
        <th>স্ট্যাটাস</th><th>সংযোগ</th><th>কোম্পানি</th><th>তারিখ</th>
    </tr>
    <?php foreach ($defaulters as $i => $d): ?>
    <tr>
        <td><?= $i + 1 ?></td>
        <td><?= htmlspecialchars($d['customer_name']) ?></td>
        <td><?= htmlspecialchars($d['customer_phone']) ?></td>
        <td><?= htmlspecialchars($d['alt_phone'] ?? '') ?></td>
        <td><?= htmlspecialchars($d['nid_number'] ?? '') ?></td>
        <td><?= htmlspecialchars($d['address_text']) ?></td>
        <td><?= htmlspecialchars($d['area'] ?? '') ?></td>
        <td><?= htmlspecialchars($d['thana'] ?? '') ?></td>
        <td><?= htmlspecialchars($d['district'] ?? '') ?></td>
        <td class="danger"><?= number_format($d['due_amount'], 2) ?></td>
        <td><?= getStatusLabel($d['type']) ?></td>
        <td><?= getStatusLabel($d['risk_level']) ?></td>
        <td><?= getStatusLabel($d['status']) ?></td>
        <td><?= match($d['connection_type']) { 'home'=>'আবাসিক','office'=>'অফিস','corporate'=>'কর্পোরেট',default=>'অন্যান্য' } ?></td>
        <td><?= htmlspecialchars($d['company_name']) ?></td>
        <td><?= formatDate($d['created_at'], 'd/m/Y') ?></td>
    </tr>
    <?php endforeach; ?>
    <tr>
        <td colspan="9" style="text-align:right;font-weight:bold;">মোট বকেয়া:</td>
        <td class="danger" style="font-weight:bold;"><?= number_format(array_sum(array_column($defaulters, 'due_amount')), 2) ?></td>
        <td colspan="6"></td>
    </tr>
</table>
</body></html>
        <?php
        exit;
    }

    // ============================================================
    // PDF EXPORT
    // ============================================================
    if ($format === 'pdf') {
        $totalDue      = array_sum(array_column($defaulters, 'due_amount'));
        $activeCount   = count(array_filter($defaulters, fn($d) => $d['status'] === 'active'));
        $resolvedCount = count(array_filter($defaulters, fn($d) => $d['status'] === 'resolved'));
        ?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <title>বকেয়া তালিকা — <?= htmlspecialchars($siteName) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Hind Siliguri', Arial, sans-serif; margin: 0; padding: 0; box-sizing: border-box; }
        body { font-size: 11px; color: #1a1a1a; background: #fff; }
        .page { padding: 20px 24px; }
        .report-header { display: flex; justify-content: space-between; align-items: flex-start;
                         border-bottom: 3px solid #1a3a5c; padding-bottom: 14px; margin-bottom: 14px; }
        .brand { display: flex; align-items: center; gap: 12px; }
        .brand-icon { width: 44px; height: 44px; background: #1a3a5c; color: #fff;
                      border-radius: 8px; display: flex; align-items: center; justify-content: center;
                      font-weight: 800; font-size: 14px; }
        .brand-text h1 { font-size: 16px; font-weight: 700; color: #0f2238; }
        .brand-text p  { font-size: 11px; color: #64748b; }
        .report-meta { text-align: right; font-size: 11px; color: #64748b; }
        .summary-row { display: flex; gap: 12px; margin-bottom: 14px; }
        .summary-box { flex: 1; border-radius: 8px; padding: 10px 14px; text-align: center; }
        .summary-box .val { font-size: 20px; font-weight: 800; }
        .summary-box .lbl { font-size: 10px; color: #64748b; margin-top: 2px; }
        .box-red    { background: #fef2f2; border: 1px solid #fecaca; }
        .box-green  { background: #f0fdf4; border: 1px solid #bbf7d0; }
        .box-blue   { background: #eff6ff; border: 1px solid #bfdbfe; }
        .box-yellow { background: #fffbeb; border: 1px solid #fde68a; }
        .box-red .val { color: #dc2626; } .box-green .val { color: #16a34a; }
        .box-blue .val { color: #2563a8; } .box-yellow .val { color: #d97706; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        thead th { background: #1a3a5c; color: #fff; padding: 7px 8px; font-size: 10px; font-weight: 600; text-align: left; white-space: nowrap; }
        tbody td { padding: 6px 8px; border-bottom: 1px solid #f1f5f9; vertical-align: top; font-size: 10px; }
        tbody tr:nth-child(even) td { background: #f8fafc; }
        .risk-critical { background:#fef2f2;color:#dc2626;padding:2px 6px;border-radius:10px;font-size:9px;font-weight:600; }
        .risk-high     { background:#fff7ed;color:#ea580c;padding:2px 6px;border-radius:10px;font-size:9px;font-weight:600; }
        .risk-medium   { background:#eff6ff;color:#2563a8;padding:2px 6px;border-radius:10px;font-size:9px;font-weight:600; }
        .risk-low      { background:#f0fdf4;color:#16a34a;padding:2px 6px;border-radius:10px;font-size:9px;font-weight:600; }
        .status-active   { color:#dc2626;font-weight:600; }
        .status-resolved { color:#16a34a;font-weight:600; }
        .due-amount { color:#dc2626;font-weight:700; }
        .total-row td { background:#f0f4f8!important;font-weight:700;border-top:2px solid #e2e8f0; }
        .report-footer { border-top:2px solid #e2e8f0;padding-top:10px;display:flex;justify-content:space-between;font-size:10px;color:#94a3b8; }
        @media print {
            .no-print { display: none !important; }
            body { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        }
        @page { margin: 10mm; size: A4 landscape; }
    </style>
</head>
<body>
<div class="page">
    <div class="no-print" style="margin-bottom:16px;display:flex;gap:8px;">
        <button onclick="window.print()" style="background:#1a3a5c;color:#fff;border:none;padding:8px 20px;border-radius:8px;cursor:pointer;font-family:inherit;font-size:13px;">
            🖨️ প্রিন্ট / PDF সেভ করুন
        </button>
        <a href="<?= SITE_URL ?>/modules/reports/export.php"
           style="background:#f1f5f9;color:#475569;border:none;padding:8px 16px;border-radius:8px;cursor:pointer;font-family:inherit;font-size:13px;text-decoration:none;display:inline-block;">
            ← ফিরে যান
        </a>
    </div>
    <div class="report-header">
        <div class="brand">
            <div class="brand-icon">ISP</div>
            <div class="brand-text">
                <h1><?= htmlspecialchars($siteName) ?></h1>
                <p>বকেয়া গ্রাহকের তালিকা</p>
            </div>
        </div>
        <div class="report-meta">
            <div><strong>এক্সপোর্ট তারিখ:</strong> <?= $exportDate ?></div>
            <div><strong>মোট রেকর্ড:</strong> <?= count($defaulters) ?> টি</div>
            <div><strong>এক্সপোর্টকারী:</strong> <?= htmlspecialchars($user['full_name']) ?></div>
        </div>
    </div>
    <div class="summary-row">
        <div class="summary-box box-red"><div class="val"><?= $activeCount ?></div><div class="lbl">সক্রিয় বকেয়া</div></div>
        <div class="summary-box box-green"><div class="val"><?= $resolvedCount ?></div><div class="lbl">সমাধান হয়েছে</div></div>
        <div class="summary-box box-yellow"><div class="val">৳<?= number_format($totalDue) ?></div><div class="lbl">মোট বকেয়া</div></div>
        <div class="summary-box box-blue"><div class="val"><?= count($defaulters) ?></div><div class="lbl">মোট রেকর্ড</div></div>
    </div>
    <table>
        <thead>
            <tr>
                <th>#</th><th>গ্রাহকের নাম</th><th>মোবাইল</th><th>NID</th>
                <th>ঠিকানা / এলাকা</th><th>বকেয়া (৳)</th><th>ধরন</th>
                <th>ঝুঁকি</th><th>স্ট্যাটাস</th><th>কোম্পানি</th><th>তারিখ</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($defaulters as $i => $d): ?>
        <tr>
            <td style="color:#94a3b8;"><?= $i + 1 ?></td>
            <td><strong><?= htmlspecialchars($d['customer_name']) ?></strong></td>
            <td><?= htmlspecialchars($d['customer_phone']) ?><?= $d['alt_phone'] ? '<br><span style="color:#94a3b8;">'.$d['alt_phone'].'</span>' : '' ?></td>
            <td style="color:#64748b;"><?= htmlspecialchars($d['nid_number'] ?? '—') ?></td>
            <td>
                <span style="font-size:10px;"><?= htmlspecialchars(mb_substr($d['address_text'], 0, 40, 'UTF-8')) ?><?= mb_strlen($d['address_text'], 'UTF-8') > 40 ? '...' : '' ?></span>
                <?php if ($d['area']): ?><br><span style="color:#64748b;"><?= htmlspecialchars($d['area']) ?></span><?php endif; ?>
            </td>
            <td class="due-amount"><?= number_format($d['due_amount'], 0) ?></td>
            <td><?= getStatusLabel($d['type']) ?></td>
            <td><span class="risk-<?= $d['risk_level'] ?>"><?= getStatusLabel($d['risk_level']) ?></span></td>
            <td class="status-<?= $d['status'] ?>"><?= getStatusLabel($d['status']) ?></td>
            <td style="font-size:10px;"><?= htmlspecialchars($d['company_name']) ?></td>
            <td style="color:#64748b;white-space:nowrap;"><?= formatDate($d['created_at'], 'd/m/Y') ?></td>
        </tr>
        <?php endforeach; ?>
        <tr class="total-row">
            <td colspan="5" style="text-align:right;">মোট বকেয়া:</td>
            <td style="color:#dc2626;">৳<?= number_format($totalDue, 0) ?></td>
            <td colspan="5"></td>
        </tr>
        </tbody>
    </table>
    <div class="report-footer">
        <div><?= htmlspecialchars($siteName) ?> — গোপনীয় নথি</div>
        <div>এই রিপোর্ট শুধুমাত্র অনুমোদিত ব্যবহারকারীদের জন্য</div>
        <div>মুদ্রণ তারিখ: <?= $exportDate ?></div>
    </div>
</div>
<script>
if (new URLSearchParams(window.location.search).get('autoprint') === '1') {
    window.onload = () => setTimeout(() => window.print(), 500);
}
</script>
</body>
</html>
        <?php
        exit;
    }
}

// ============================================================
// SELECTION PAGE — format নেই, তাই ফর্ম দেখাও
// ============================================================
$pageTitle = 'এক্সপোর্ট';
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1>ডেটা এক্সপোর্ট</h1>
    <nav><ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="index.php">রিপোর্ট</a></li>
        <li class="breadcrumb-item active">এক্সপোর্ট</li>
    </ol></nav>
</div>

<div class="row justify-content-center">
<div class="col-lg-8">
<div class="card">
    <div class="card-header"><h6 class="card-title"><i class="bi bi-download me-2"></i>এক্সপোর্ট ফিল্টার</h6></div>
    <div class="card-body p-4">
        <form method="GET">

            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label">তারিখ থেকে</label>
                    <input type="date" name="date_from" class="form-control" value="<?= date('Y-m-01') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">তারিখ পর্যন্ত</label>
                    <input type="date" name="date_to" class="form-control" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">স্ট্যাটাস</label>
                    <select name="status" class="form-select">
                        <option value="">সব</option>
                        <option value="active">সক্রিয়</option>
                        <option value="resolved">সমাধান হয়েছে</option>
                    </select>
                </div>
                <?php if ($isAdmin): ?>
                <div class="col-md-6">
                    <label class="form-label">কোম্পানি</label>
                    <select name="company" class="form-select">
                        <option value="">সব কোম্পানি</option>
                        <?php foreach (Database::fetchAll("SELECT id, company_name FROM companies WHERE status='approved'") as $co): ?>
                        <option value="<?= $co['id'] ?>"><?= htmlspecialchars($co['company_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-md-6">
                    <label class="form-label">ঝুঁকির মাত্রা</label>
                    <select name="risk" class="form-select">
                        <option value="">সব</option>
                        <option value="critical">অতি ঝুঁকিপূর্ণ</option>
                        <option value="high">উচ্চ ঝুঁকি</option>
                        <option value="medium">মধ্যম</option>
                        <option value="low">কম</option>
                    </select>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <button type="submit" name="format" value="excel"
                            class="btn w-100 py-3" style="background:#217346;color:#fff;border-radius:12px;font-size:15px;font-weight:600;">
                        <i class="bi bi-file-earmark-excel fs-4 d-block mb-1"></i>
                        Excel এ ডাউনলোড
                        <div style="font-size:11px;opacity:.8;font-weight:400;">.xls ফরম্যাটে ডাউনলোড হবে</div>
                    </button>
                </div>
                <div class="col-md-6">
                    <button type="submit" name="format" value="pdf"
                            class="btn w-100 py-3" style="background:#dc2626;color:#fff;border-radius:12px;font-size:15px;font-weight:600;">
                        <i class="bi bi-file-earmark-pdf fs-4 d-block mb-1"></i>
                        PDF প্রিন্ট / সেভ
                        <div style="font-size:11px;opacity:.8;font-weight:400;">নতুন ট্যাবে খুলবে, প্রিন্ট করুন</div>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>