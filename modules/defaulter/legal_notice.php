<?php
// ============================================================
// LEGAL NOTICE PDF — C-1.1 + C-1.3
// File: modules/defaulter/legal_notice.php
// ============================================================
require_once __DIR__ . '/../../includes/functions.php';
requirePermission('defaulters', 'view');

$user = getCurrentUser();
$id   = (int)($_GET['id'] ?? 0);

$defaulter = Database::fetchOne(
    "SELECT d.*, c.company_name, c.phone as company_phone, c.email as company_email,
            c.address as company_address, c.logo as company_logo
     FROM defaulters d
     JOIN companies c ON c.id = d.company_id
     WHERE d.id = ?", [$id]
);
if (!$defaulter) { setFlash('error', 'এন্ট্রি পাওয়া যায়নি।'); redirect('list.php'); }

// শুধু নিজের কোম্পানির এন্ট্রিতে নোটিশ দেওয়া যাবে
if (!isSuperAdmin() && $defaulter['company_id'] != $user['company_id']) {
    setFlash('error', 'অনুমতি নেই।'); redirect('view.php?id='.$id);
}

// ── Templates লোড ───────────────────────────────────────────
$templates = Database::fetchAll(
    "SELECT * FROM notice_templates
     WHERE company_id IS NULL OR company_id = ?
     ORDER BY is_default DESC, type, name",
    [$user['company_id']]
);

// ── নোটিশ Log ও Generate ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_notice'])) {
    if (!validateCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        setFlash('error', 'নিরাপত্তা যাচাই ব্যর্থ।');
        redirect($_SERVER['PHP_SELF'].'?id='.$id);
    }

    $templateId  = (int)($_POST['template_id'] ?? 0);
    $customBody  = trim($_POST['custom_body'] ?? '');
    $subject     = trim($_POST['subject'] ?? '');
    $noticeType  = $_POST['notice_type'] ?? 'warning';
    $deliveryMethod = $_POST['delivery_method'] ?? 'print';
    $note        = trim($_POST['note'] ?? '');

    // Body নির্ধারণ
    $body = $customBody;

    // Placeholder replace
    $replaceMap = [
        '{{customer_name}}'   => $defaulter['customer_name'],
        '{{customer_phone}}'  => $defaulter['customer_phone'],
        '{{nid_number}}'      => $defaulter['nid_number'] ?? 'N/A',
        '{{due_amount}}'      => '৳'.number_format($defaulter['due_amount']),
        '{{service_period}}'  => $defaulter['service_period'] ?? 'N/A',
        '{{address}}'         => $defaulter['address_text'],
        '{{company_name}}'    => $defaulter['company_name'],
        '{{company_phone}}'   => $defaulter['company_phone'] ?? '',
        '{{company_address}}' => $defaulter['company_address'] ?? '',
        '{{notice_date}}'     => date('d F Y'),
        '{{notice_number}}'   => 'NTC-'.strtoupper(base_convert($id.date('ymd'), 10, 36)),
    ];
    $body    = str_replace(array_keys($replaceMap), array_values($replaceMap), $body);
    $subject = str_replace(array_keys($replaceMap), array_values($replaceMap), $subject);

    // Log সংরক্ষণ
    Database::insert('legal_notice_log', [
        'defaulter_id'    => $id,
        'company_id'      => $user['company_id'],
        'template_id'     => $templateId ?: null,
        'sent_by'         => $user['id'],
        'notice_type'     => $noticeType,
        'subject'         => $subject,
        'body'            => $body,
        'delivery_method' => $deliveryMethod,
        'note'            => $note ?: null,
    ]);

    // Print view এ redirect
    redirect($_SERVER['PHP_SELF'].'?id='.$id.'&print=1&log_id='.Database::lastInsertId());
}

// ── Print Mode ───────────────────────────────────────────────
$printMode = isset($_GET['print']) && isset($_GET['log_id']);
if ($printMode) {
    $log = Database::fetchOne("SELECT * FROM legal_notice_log WHERE id = ?", [(int)$_GET['log_id']]);
    if ($log) {
        $noticeNumber = 'NTC-'.strtoupper(base_convert($id.date('ymd', strtotime($log['sent_at'])), 10, 36));
        $typeLabels = ['warning'=>'সতর্কতামূলক নোটিশ','final_notice'=>'চূড়ান্ত নোটিশ','legal'=>'আইনি নোটিশ','court'=>'আদালত নোটিশ'];
        ?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <title>আইনি নোটিশ — <?= htmlspecialchars($defaulter['customer_name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Hind Siliguri', Arial, sans-serif; margin:0; padding:0; box-sizing:border-box; }
        body { background:#f0f4f8; }
        .toolbar { background:#1a3a5c; color:#fff; padding:10px 24px; display:flex; gap:10px; align-items:center; }
        .toolbar button { border:none; padding:7px 18px; border-radius:8px; cursor:pointer; font-size:13px; font-family:inherit; }
        .notice-page { max-width:700px; margin:24px auto; background:#fff; padding:48px; box-shadow:0 4px 20px rgba(0,0,0,.1); }
        .watermark { position:absolute; top:50%; left:50%; transform:translate(-50%,-50%) rotate(-30deg);
                     font-size:64px; font-weight:700; color:rgba(220,38,38,.07); white-space:nowrap; pointer-events:none; }
        .notice-header { border-bottom:3px solid #1a3a5c; padding-bottom:16px; margin-bottom:20px; }
        .company-name { font-size:20px; font-weight:700; color:#0f2238; }
        .notice-title { background:#1a3a5c; color:#fff; text-align:center; padding:10px; border-radius:6px;
                        font-size:16px; font-weight:700; margin:16px 0; }
        .notice-meta { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin:16px 0;
                       padding:12px; background:#f8fafc; border-radius:8px; font-size:13px; }
        .notice-meta span { color:#64748b; }
        .notice-meta strong { color:#0f2238; }
        .notice-body { font-size:14px; line-height:2; color:#1a1a1a; white-space:pre-wrap;
                       margin:20px 0; padding:16px; border:1px solid #e2e8f0; border-radius:8px; }
        .notice-footer { border-top:2px solid #e2e8f0; padding-top:16px; margin-top:24px;
                         display:flex; justify-content:space-between; align-items:flex-end; }
        .seal-box { text-align:center; }
        .seal-circle { width:90px; height:90px; border:2px dashed #94a3b8; border-radius:50%;
                       display:flex; align-items:center; justify-content:center;
                       margin:0 auto 6px; color:#94a3b8; font-size:11px; }
        @media print {
            .toolbar { display:none !important; }
            body { background:#fff; }
            .notice-page { margin:0; box-shadow:none; padding:24px; }
        }
        @page { size: A4; margin: 15mm; }
    </style>
</head>
<body>
<div class="toolbar no-print">
    <button onclick="window.print()" style="background:#16a34a;color:#fff;">🖨️ প্রিন্ট / PDF সেভ</button>
    <button onclick="window.close()" style="background:#64748b;color:#fff;">বন্ধ করুন</button>
    <span style="font-size:13px;opacity:.8;">← প্রিন্ট করুন বা PDF হিসেবে সেভ করুন</span>
</div>

<div class="notice-page" style="position:relative;">
    <div class="watermark"><?= strtoupper($typeLabels[$log['notice_type']] ?? '') ?></div>

    <!-- Header -->
    <div class="notice-header d-flex justify-content-between">
        <div>
            <?php if ($defaulter['company_logo']): ?>
            <img src="<?= UPLOAD_URL . $defaulter['company_logo'] ?>" style="height:50px;margin-bottom:8px;"><br>
            <?php endif; ?>
            <div class="company-name"><?= htmlspecialchars($defaulter['company_name']) ?></div>
            <?php if ($defaulter['company_address']): ?>
            <div style="font-size:12px;color:#64748b;"><?= htmlspecialchars($defaulter['company_address']) ?></div>
            <?php endif; ?>
            <?php if ($defaulter['company_phone']): ?>
            <div style="font-size:12px;color:#64748b;">📞 <?= htmlspecialchars($defaulter['company_phone']) ?></div>
            <?php endif; ?>
        </div>
        <div style="text-align:right;font-size:12px;color:#64748b;">
            <div><strong>নোটিশ নং:</strong> <?= $noticeNumber ?></div>
            <div><strong>তারিখ:</strong> <?= date('d F Y', strtotime($log['sent_at'])) ?></div>
        </div>
    </div>

    <!-- Title -->
    <div class="notice-title"><?= htmlspecialchars($typeLabels[$log['notice_type']] ?? 'নোটিশ') ?></div>

    <!-- Customer Info -->
    <div class="notice-meta">
        <div><span>প্রাপক: </span><strong><?= htmlspecialchars($defaulter['customer_name']) ?></strong></div>
        <div><span>মোবাইল: </span><strong><?= htmlspecialchars($defaulter['customer_phone']) ?></strong></div>
        <div><span>NID: </span><strong><?= htmlspecialchars($defaulter['nid_number'] ?? 'N/A') ?></strong></div>
        <div><span>বকেয়া: </span><strong style="color:#dc2626;">৳<?= number_format($defaulter['due_amount']) ?></strong></div>
        <div style="grid-column:1/-1;"><span>ঠিকানা: </span><strong><?= htmlspecialchars($defaulter['address_text']) ?></strong></div>
    </div>

    <!-- Body -->
    <div style="font-size:13px;font-weight:600;color:#0f2238;margin-bottom:8px;">বিষয়: <?= htmlspecialchars($log['subject']) ?></div>
    <div class="notice-body"><?= htmlspecialchars($log['body']) ?></div>

    <!-- Footer -->
    <div class="notice-footer">
        <div style="font-size:12px;color:#64748b;">
            <div>প্রেরণ পদ্ধতি: <?= match($log['delivery_method']){'print'=>'প্রিন্ট/ডাক','email'=>'ইমেইল','hand'=>'হাতে হাতে', default=>'অন্যান্য'} ?></div>
            <?php if ($log['note']): ?>
            <div>নোট: <?= htmlspecialchars($log['note']) ?></div>
            <?php endif; ?>
        </div>
        <div class="seal-box">
            <div class="seal-circle">সিলমোহর</div>
            <div style="font-size:11px;color:#64748b;">অনুমোদিত স্বাক্ষর</div>
        </div>
    </div>
</div>
</body>
</html>
        <?php
        exit;
    }
}

// ── Delivery Log লোড ─────────────────────────────────────────
$noticeLog = Database::fetchAll(
    "SELECT l.*, u.full_name as sender_name
     FROM legal_notice_log l
     JOIN users u ON u.id = l.sent_by
     WHERE l.defaulter_id = ?
     ORDER BY l.sent_at DESC",
    [$id]
);

$pageTitle = 'আইনি নোটিশ — '.$defaulter['customer_name'];
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-file-earmark-text me-2"></i>আইনি নোটিশ</h1>
        <nav><ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="list.php">বকেয়া তালিকা</a></li>
            <li class="breadcrumb-item"><a href="view.php?id=<?= $id ?>"><?= htmlspecialchars($defaulter['customer_name']) ?></a></li>
            <li class="breadcrumb-item active">আইনি নোটিশ</li>
        </ol></nav>
    </div>
</div>

<div class="row g-4">
<!-- ===== LEFT: FORM ===== -->
<div class="col-lg-7">
<div class="card">
    <div class="card-header">
        <h6 class="card-title mb-0"><i class="bi bi-pencil-square me-2 text-danger"></i>নতুন নোটিশ তৈরি করুন</h6>
    </div>
    <div class="card-body">

        <!-- Customer Summary -->
        <div class="d-flex align-items-center gap-3 p-3 rounded-3 mb-3" style="background:#fef2f2;border:1px solid #fecaca;">
            <div>
                <div class="fw-bold"><?= htmlspecialchars($defaulter['customer_name']) ?></div>
                <div style="font-size:12px;color:#64748b;"><?= htmlspecialchars($defaulter['customer_phone']) ?> · <?= htmlspecialchars($defaulter['address_text']) ?></div>
            </div>
            <div class="ms-auto text-end">
                <div style="font-size:18px;font-weight:700;color:#dc2626;">৳<?= number_format($defaulter['due_amount']) ?></div>
                <div style="font-size:11px;color:#64748b;">মোট বকেয়া</div>
            </div>
        </div>

        <form method="POST" id="noticeForm">
            <?= csrfField() ?>
            <input type="hidden" name="generate_notice" value="1">

            <div class="row g-3">
                <!-- Template নির্বাচন -->
                <div class="col-12">
                    <label class="form-label fw-semibold">টেমপ্লেট বেছে নিন</label>
                    <select class="form-select" id="templateSelect" onchange="loadTemplate(this.value)">
                        <option value="">-- টেমপ্লেট নির্বাচন করুন --</option>
                        <?php foreach ($templates as $t): ?>
                        <option value="<?= $t['id'] ?>"
                                data-type="<?= $t['type'] ?>"
                                data-subject="<?= htmlspecialchars($t['subject']) ?>"
                                data-body="<?= htmlspecialchars($t['body']) ?>">
                            <?= htmlspecialchars($t['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="template_id" id="templateId">
                </div>

                <!-- নোটিশের ধরন -->
                <div class="col-md-6">
                    <label class="form-label fw-semibold">নোটিশের ধরন</label>
                    <select name="notice_type" id="noticeType" class="form-select">
                        <option value="warning">⚠️ সতর্কতামূলক</option>
                        <option value="final_notice">🔴 চূড়ান্ত নোটিশ</option>
                        <option value="legal">⚖️ আইনি নোটিশ</option>
                        <option value="court">🏛️ আদালত নোটিশ</option>
                    </select>
                </div>

                <!-- প্রেরণ পদ্ধতি -->
                <div class="col-md-6">
                    <label class="form-label fw-semibold">প্রেরণ পদ্ধতি</label>
                    <select name="delivery_method" class="form-select">
                        <option value="print">🖨️ প্রিন্ট / ডাকযোগে</option>
                        <option value="hand">🤝 হাতে হাতে</option>
                        <option value="email">📧 ইমেইলে</option>
                    </select>
                </div>

                <!-- বিষয় -->
                <div class="col-12">
                    <label class="form-label fw-semibold">বিষয় <span class="required-star">*</span></label>
                    <input type="text" name="subject" id="noticeSubject" class="form-control"
                           placeholder="নোটিশের বিষয় লিখুন..." required>
                </div>

                <!-- বার্তা -->
                <div class="col-12">
                    <label class="form-label fw-semibold">নোটিশের বার্তা <span class="required-star">*</span></label>
                    <textarea name="custom_body" id="noticeBody" class="form-control" rows="10"
                              placeholder="নোটিশের বিস্তারিত বার্তা লিখুন..." required></textarea>
                    <div class="form-text">
                        Placeholder: <code>{{customer_name}}</code> <code>{{due_amount}}</code>
                        <code>{{company_name}}</code> <code>{{notice_date}}</code> <code>{{service_period}}</code>
                    </div>
                </div>

                <!-- নোট -->
                <div class="col-12">
                    <label class="form-label fw-semibold">অভ্যন্তরীণ নোট (ঐচ্ছিক)</label>
                    <input type="text" name="note" class="form-control"
                           placeholder="যেমন: ডাকযোগে পাঠানো হয়েছে, রশিদ নং...">
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-danger fw-semibold px-4">
                        <i class="bi bi-file-earmark-pdf me-1"></i>নোটিশ তৈরি ও প্রিন্ট করুন
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
</div>

<!-- ===== RIGHT: LOG ===== -->
<div class="col-lg-5">
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="card-title mb-0"><i class="bi bi-clock-history me-2"></i>নোটিশ ইতিহাস</h6>
        <span class="badge bg-secondary rounded-pill"><?= count($noticeLog) ?></span>
    </div>
    <?php if (empty($noticeLog)): ?>
    <div class="card-body text-center py-4 text-muted">
        <i class="bi bi-file-earmark-x fs-2 d-block mb-2"></i>
        এখনো কোনো নোটিশ পাঠানো হয়নি
    </div>
    <?php else: ?>
    <div style="max-height:500px;overflow-y:auto;">
        <?php
        $typeIcons = ['warning'=>'exclamation-triangle','final_notice'=>'exclamation-octagon','legal'=>'briefcase','court'=>'bank'];
        $typeColors = ['warning'=>'#d97706','final_notice'=>'#dc2626','legal'=>'#7c3aed','court'=>'#0f2238'];
        $typeLabels2 = ['warning'=>'সতর্কতা','final_notice'=>'চূড়ান্ত','legal'=>'আইনি','court'=>'আদালত'];
        foreach ($noticeLog as $log):
        $color = $typeColors[$log['notice_type']] ?? '#64748b';
        ?>
        <div class="px-3 py-2 border-bottom" style="font-size:13px;">
            <div class="d-flex align-items-start justify-content-between gap-2">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-<?= $typeIcons[$log['notice_type']] ?? 'file-text' ?>"
                       style="color:<?= $color ?>;font-size:16px;"></i>
                    <div>
                        <div class="fw-semibold" style="color:<?= $color ?>;">
                            <?= $typeLabels2[$log['notice_type']] ?? '' ?> নোটিশ
                        </div>
                        <div class="text-muted" style="font-size:11px;">
                            <?= htmlspecialchars($log['sender_name']) ?> ·
                            <?= date('d M Y, h:i A', strtotime($log['sent_at'])) ?>
                        </div>
                        <div style="font-size:11px;color:#94a3b8;">
                            <?= match($log['delivery_method']){'print'=>'🖨️ প্রিন্ট','email'=>'📧 ইমেইল','hand'=>'🤝 হাতে হাতে', default=>''} ?>
                        </div>
                    </div>
                </div>
                <a href="?id=<?= $id ?>&print=1&log_id=<?= $log['id'] ?>"
                   class="btn btn-sm btn-outline-secondary flex-shrink-0"
                   style="font-size:11px;border-radius:8px;" target="_blank">
                    <i class="bi bi-printer"></i> পুনরায়
                </a>
            </div>
            <?php if ($log['note']): ?>
            <div class="mt-1 ps-4" style="font-size:11px;color:#64748b;">📝 <?= htmlspecialchars($log['note']) ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
</div>
</div>

<script>
// Template load করা
const templates = {
    <?php foreach ($templates as $t): ?>
    <?= $t['id'] ?>: {
        type: "<?= $t['type'] ?>",
        subject: <?= json_encode($t['subject']) ?>,
        body: <?= json_encode($t['body']) ?>
    },
    <?php endforeach; ?>
};

function loadTemplate(id) {
    if (!id || !templates[id]) return;
    const t = templates[id];
    document.getElementById('noticeSubject').value = t.subject;
    document.getElementById('noticeBody').value    = t.body;
    document.getElementById('noticeType').value    = t.type;
    document.getElementById('templateId').value    = id;
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
