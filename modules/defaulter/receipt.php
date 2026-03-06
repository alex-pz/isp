<?php
// ============================================================
// PAYMENT RECEIPT — B-1.3
// File: modules/defaulter/receipt.php
// পেমেন্ট রিসিট — প্রিন্টযোগ্য PDF স্টাইল
// ============================================================
require_once __DIR__ . '/../../includes/functions.php';
requirePermission('defaulters', 'view');

$user = getCurrentUser();
$id   = (int)($_GET['id'] ?? 0);

$defaulter = Database::fetchOne(
    "SELECT d.*, c.company_name, c.phone as co_phone, c.email as co_email,
            c.address as co_address, c.logo as co_logo,
            u.full_name as entered_by_name,
            ru.full_name as resolved_by_name
     FROM defaulters d
     JOIN companies c ON c.id = d.company_id
     JOIN users u ON u.id = d.entered_by
     LEFT JOIN users ru ON ru.id = d.resolved_by
     WHERE d.id = ?", [$id]
);

if (!$defaulter) { die('রিসিট পাওয়া যায়নি।'); }

// পেমেন্ট ইতিহাস
$payments = [];
try {
    $payments = Database::fetchAll(
        "SELECT p.*, u.full_name as recorded_by_name
         FROM payments p JOIN users u ON u.id = p.recorded_by
         WHERE p.defaulter_id = ?
         ORDER BY p.payment_date ASC, p.created_at ASC",
        [$id]
    );
} catch (Exception $e) {}

$totalPaid   = (float)($defaulter['payment_amount'] ?? array_sum(array_column($payments, 'amount')));
$waiverAmt   = (float)($defaulter['waiver_amount'] ?? 0);
$remaining   = max(0, (float)$defaulter['due_amount'] - $totalPaid);
$siteName    = getSetting('site_name', 'ISP Defaulter System');
$receiptNo   = 'RCP-' . str_pad($id, 6, '0', STR_PAD_LEFT) . '-' . date('Ymd');

$methodLabels = [
    'cash'=>'নগদ','bkash'=>'bKash','nagad'=>'Nagad',
    'rocket'=>'Rocket','bank'=>'ব্যাংক','other'=>'অন্য'
];
$resLabels = [
    'full_payment'=>'পূর্ণ পরিশোধ','partial_payment'=>'আংশিক পরিশোধ',
    'waived'=>'সম্পূর্ণ মাফ','other'=>'সমাধান হয়েছে'
];
?>
<!DOCTYPE html>
<html lang="bn" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>রিসিট — <?= htmlspecialchars($defaulter['customer_name']) ?></title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;600;700;800&display=swap');

  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:'Noto Sans Bengali', Arial, sans-serif; background:#f1f5f9; color:#0f2238; font-size:13px; }

  .receipt-wrap {
    max-width: 720px; margin: 20px auto; background:#fff;
    border-radius: 16px; overflow:hidden;
    box-shadow: 0 4px 24px rgba(0,0,0,.1);
  }

  /* Header */
  .receipt-header {
    background: linear-gradient(135deg, #0f2238 0%, #1a3a5c 100%);
    color:#fff; padding:28px 32px; display:flex;
    align-items:center; justify-content:space-between; gap:16px;
  }
  .company-info h2 { font-size:20px; font-weight:800; margin-bottom:4px; }
  .company-info p  { font-size:12px; color:rgba(255,255,255,.65); }
  .receipt-badge {
    text-align:right;
    background:rgba(255,255,255,.12); border-radius:12px; padding:12px 18px;
  }
  .receipt-badge .label { font-size:10px; text-transform:uppercase; letter-spacing:1px; color:rgba(255,255,255,.6); }
  .receipt-badge .no    { font-size:16px; font-weight:800; font-family:monospace; }
  .receipt-badge .date  { font-size:11px; color:rgba(255,255,255,.7); margin-top:2px; }

  /* Status Banner */
  .status-banner {
    padding:10px 32px; font-size:13px; font-weight:600;
    display:flex; align-items:center; gap:8px;
  }
  .status-banner.resolved { background:#f0fdf4; color:#166534; }
  .status-banner.active   { background:#fffbeb; color:#92400e; }

  /* Body */
  .receipt-body { padding:24px 32px; }

  .section-title {
    font-size:11px; text-transform:uppercase; letter-spacing:1px;
    color:#64748b; font-weight:700; margin-bottom:10px;
    padding-bottom:6px; border-bottom:1px solid #e2e8f0;
  }

  .info-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px 16px; margin-bottom:20px; }
  .info-row  { display:flex; flex-direction:column; gap:2px; }
  .info-row .lbl { font-size:11px; color:#94a3b8; }
  .info-row .val { font-size:13px; font-weight:600; color:#0f2238; }

  /* Amount Summary */
  .amount-box {
    background:#f8fafc; border-radius:12px; padding:16px 20px;
    margin-bottom:20px; border:1px solid #e2e8f0;
  }
  .amount-row { display:flex; justify-content:space-between; align-items:center; padding:5px 0; font-size:13px; }
  .amount-row.total { border-top:2px solid #e2e8f0; margin-top:6px; padding-top:10px; }
  .amount-row.total .lbl { font-size:15px; font-weight:800; }
  .amount-row.total .val { font-size:20px; font-weight:800; }
  .amount-row .val.green  { color:#16a34a; }
  .amount-row .val.red    { color:#dc2626; }
  .amount-row .val.orange { color:#d97706; }

  /* Progress */
  .progress-wrap { margin-bottom:20px; }
  .progress-bar-bg { height:10px; background:#f1f5f9; border-radius:5px; overflow:hidden; margin:6px 0; }
  .progress-bar-fill { height:100%; border-radius:5px; background:#16a34a; }

  /* Payment Table */
  table { width:100%; border-collapse:collapse; margin-bottom:20px; }
  th { background:#f8fafc; color:#64748b; font-size:11px; text-transform:uppercase;
       letter-spacing:.5px; padding:8px 10px; text-align:left; border-bottom:2px solid #e2e8f0; }
  td { padding:8px 10px; border-bottom:1px solid #f1f5f9; font-size:12px; vertical-align:top; }
  tr:last-child td { border-bottom:none; }
  tr:hover td { background:#fafafa; }
  .amount-cell { font-weight:700; color:#16a34a; }

  /* Footer */
  .receipt-footer {
    background:#f8fafc; padding:16px 32px;
    display:flex; justify-content:space-between; align-items:center;
    border-top:1px solid #e2e8f0; font-size:11px; color:#94a3b8;
  }
  .stamp {
    display:inline-block; border:3px solid #16a34a; color:#16a34a;
    border-radius:50%; width:70px; height:70px;
    display:flex; align-items:center; justify-content:center;
    font-size:10px; font-weight:800; text-align:center;
    transform:rotate(-15deg); line-height:1.2;
    opacity:.8;
  }
  .stamp-red { border-color:#dc2626; color:#dc2626; }

  /* Print toolbar */
  .print-toolbar {
    position:fixed; bottom:20px; right:20px;
    display:flex; gap:8px; z-index:99;
  }
  .print-toolbar button {
    padding:10px 18px; border-radius:10px; border:none;
    font-size:13px; font-weight:600; cursor:pointer;
    font-family:'Noto Sans Bengali', Arial, sans-serif;
    box-shadow:0 4px 12px rgba(0,0,0,.15);
  }
  .btn-print  { background:#1a3a5c; color:#fff; }
  .btn-close2 { background:#fff; color:#64748b; border:1px solid #e2e8f0 !important; }

  @media print {
    body { background:#fff; }
    .receipt-wrap { box-shadow:none; border-radius:0; margin:0; max-width:100%; }
    .print-toolbar { display:none; }
  }
</style>
</head>
<body>

<!-- Print Toolbar -->
<div class="print-toolbar">
    <button class="btn-close2" onclick="window.close()">✕ বন্ধ</button>
    <button class="btn-print" onclick="window.print()">🖨️ প্রিন্ট করুন</button>
</div>

<div class="receipt-wrap">

    <!-- Header -->
    <div class="receipt-header">
        <div class="company-info">
            <h2><?= htmlspecialchars($defaulter['company_name']) ?></h2>
            <?php if ($defaulter['co_phone']): ?>
            <p><i>📞</i> <?= htmlspecialchars($defaulter['co_phone']) ?></p>
            <?php endif; ?>
            <?php if ($defaulter['co_email']): ?>
            <p><i>✉</i> <?= htmlspecialchars($defaulter['co_email']) ?></p>
            <?php endif; ?>
        </div>
        <div class="receipt-badge">
            <div class="label">রিসিট নম্বর</div>
            <div class="no"><?= $receiptNo ?></div>
            <div class="date"><?= date('d M Y, h:i A') ?></div>
        </div>
    </div>

    <!-- Status Banner -->
    <div class="status-banner <?= $defaulter['status'] === 'resolved' ? 'resolved' : 'active' ?>">
        <?php if ($defaulter['status'] === 'resolved'): ?>
        ✅ <?= $resLabels[$defaulter['resolution_type'] ?? 'other'] ?? 'সমাধান হয়েছে' ?>
        — <?= $defaulter['resolved_by_name'] ? htmlspecialchars($defaulter['resolved_by_name']) : '' ?>
        <?php if ($defaulter['resolved_at']): ?> (<?= date('d M Y', strtotime($defaulter['resolved_at'])) ?>)<?php endif; ?>
        <?php else: ?>
        ⏳ পেমেন্ট চলমান — এখনো সম্পূর্ণ পরিশোধ হয়নি
        <?php endif; ?>
    </div>

    <div class="receipt-body">

        <!-- Customer Info -->
        <div class="section-title">গ্রাহকের তথ্য</div>
        <div class="info-grid">
            <div class="info-row">
                <span class="lbl">গ্রাহকের নাম</span>
                <span class="val"><?= htmlspecialchars($defaulter['customer_name']) ?></span>
            </div>
            <div class="info-row">
                <span class="lbl">মোবাইল নম্বর</span>
                <span class="val"><?= htmlspecialchars($defaulter['customer_phone']) ?></span>
            </div>
            <?php if ($defaulter['nid_number']): ?>
            <div class="info-row">
                <span class="lbl">NID নম্বর</span>
                <span class="val"><?= htmlspecialchars($defaulter['nid_number']) ?></span>
            </div>
            <?php endif; ?>
            <div class="info-row">
                <span class="lbl">ঠিকানা</span>
                <span class="val"><?= htmlspecialchars($defaulter['area'] ?? $defaulter['address_text'] ?? '') ?></span>
            </div>
            <div class="info-row">
                <span class="lbl">সংযোগের ধরন</span>
                <span class="val"><?= getStatusLabel($defaulter['type']) ?></span>
            </div>
            <?php if ($defaulter['service_period']): ?>
            <div class="info-row">
                <span class="lbl">মেয়াদ</span>
                <span class="val"><?= htmlspecialchars($defaulter['service_period']) ?></span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Amount Summary -->
        <div class="section-title">আর্থিক সারসংক্ষেপ</div>
        <div class="amount-box">
            <div class="amount-row">
                <span class="lbl">মোট বকেয়া</span>
                <span class="val red">৳<?= number_format($defaulter['due_amount'], 2) ?></span>
            </div>
            <div class="amount-row">
                <span class="lbl">মোট আদায়</span>
                <span class="val green">৳<?= number_format($totalPaid, 2) ?></span>
            </div>
            <?php if ($waiverAmt > 0): ?>
            <div class="amount-row">
                <span class="lbl">মাফ / ছাড়</span>
                <span class="val orange">৳<?= number_format($waiverAmt, 2) ?></span>
            </div>
            <?php endif; ?>
            <div class="amount-row total">
                <span class="lbl"><?= $remaining > 0 ? 'এখনো বাকি' : 'সম্পূর্ণ পরিশোধ' ?></span>
                <span class="val <?= $remaining > 0 ? 'orange' : 'green' ?>">
                    <?= $remaining > 0 ? '৳' . number_format($remaining, 2) : '✅ ৳০.০০' ?>
                </span>
            </div>
        </div>

        <!-- Progress -->
        <?php
        $pct = $defaulter['due_amount'] > 0
            ? min(100, round(($totalPaid / $defaulter['due_amount']) * 100)) : 0;
        ?>
        <div class="progress-wrap">
            <div style="display:flex;justify-content:space-between;font-size:12px;">
                <span style="color:#64748b;">পরিশোধের অগ্রগতি</span>
                <span style="font-weight:700;color:#16a34a;"><?= $pct ?>%</span>
            </div>
            <div class="progress-bar-bg">
                <div class="progress-bar-fill" style="width:<?= $pct ?>%;background:<?= $pct>=100?'#16a34a':'#2563a8' ?>;"></div>
            </div>
        </div>

        <!-- Payment Log -->
        <?php if (!empty($payments)): ?>
        <div class="section-title">পেমেন্টের বিস্তারিত</div>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>তারিখ</th>
                    <th>পরিমাণ</th>
                    <th>পদ্ধতি</th>
                    <th>Reference</th>
                </tr>
            </thead>
            <tbody>
            <?php $serial = 1; foreach ($payments as $p): ?>
            <tr>
                <td><?= $serial++ ?></td>
                <td><?= date('d M Y', strtotime($p['payment_date'])) ?></td>
                <td class="amount-cell">৳<?= number_format($p['amount'], 2) ?></td>
                <td><?= $methodLabels[$p['method']] ?? $p['method'] ?></td>
                <td><?= htmlspecialchars($p['reference'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- Resolution Note -->
        <?php if ($defaulter['resolution_note']): ?>
        <div style="background:#f8fafc;border-radius:8px;padding:12px 14px;font-size:12px;color:#475569;margin-bottom:16px;">
            <span style="font-weight:600;">নোট:</span> <?= htmlspecialchars($defaulter['resolution_note']) ?>
        </div>
        <?php endif; ?>

    </div><!-- /receipt-body -->

    <!-- Footer -->
    <div class="receipt-footer">
        <div>
            <div><?= htmlspecialchars($siteName) ?></div>
            <div>রিসিট তৈরি: <?= date('d M Y, h:i A') ?></div>
        </div>
        <div class="stamp <?= $remaining > 0 ? 'stamp-red' : '' ?>">
            <?= $remaining > 0 ? "বাকি\nআছে" : "সম্পূর্ণ\nপরিশোধ" ?>
        </div>
    </div>

</div><!-- /receipt-wrap -->

<script>
// Auto print if ?print=1
if (new URLSearchParams(window.location.search).get('print') === '1') {
    window.onload = () => setTimeout(() => window.print(), 500);
}
</script>
</body>
</html>
