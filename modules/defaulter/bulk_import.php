<?php
// ============================================================
// BULK IMPORT (Phase A-3)
// File: modules/defaulter/bulk_import.php
// ============================================================
require_once __DIR__ . '/../../includes/functions.php';
requirePermission('defaulters', 'create');

$user      = getCurrentUser();
$pageTitle = 'বাল্ক ইমপোর্ট';
$step      = $_GET['step'] ?? '1'; // 1=upload, 2=preview, 3=done
$errors    = [];
$preview   = [];
$importLog = [];

// ---- Download Template ----
if (isset($_GET['download_template'])) {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="defaulter-import-template.xls"');
    echo "\xEF\xBB\xBF";
    ?>
<html><head><meta charset="UTF-8"></head><body>
<table>
<tr style="background:#1a3a5c;color:white;">
    <th>গ্রাহকের নাম *</th>
    <th>মোবাইল নম্বর *</th>
    <th>বিকল্প নম্বর</th>
    <th>NID নম্বর</th>
    <th>ইমেইল</th>
    <th>পূর্ণ ঠিকানা *</th>
    <th>এলাকা</th>
    <th>থানা</th>
    <th>জেলা</th>
    <th>বকেয়া (টাকা) *</th>
    <th>সমস্যার ধরন</th>
    <th>ঝুঁকির মাত্রা</th>
    <th>সংযোগের ধরন</th>
    <th>মেয়াদ</th>
    <th>বিবরণ</th>
</tr>
<tr>
    <td>রহিম উদ্দিন</td><td>01712345678</td><td>01812345678</td><td>1234567890123</td>
    <td>rahim@email.com</td><td>বাড়ি ২, রোড ৫, মিরপুর</td><td>মিরপুর-১০</td>
    <td>মিরপুর</td><td>ঢাকা</td><td>5000</td>
    <td>due_payment</td><td>medium</td><td>home</td>
    <td>জানু-জুন ২০২৪</td><td>বিস্তারিত বিবরণ</td>
</tr>
<tr>
    <td colspan="15" style="color:#94a3b8;font-size:11px;">
        ধরন: due_payment | fraud | equipment_theft | contract_breach | other |
        ঝুঁকি: critical | high | medium | low |
        সংযোগ: home | office | corporate | other
    </td>
</tr>
</table>
</body></html>
<?php
    exit;
}

// ---- STEP 2: Parse & Preview CSV/Excel ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === '1') {
    if (!validateCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $errors[] = 'নিরাপত্তা যাচাই ব্যর্থ।';
    } elseif (empty($_FILES['import_file']['tmp_name'])) {
        $errors[] = 'ফাইল নির্বাচন করুন।';
    } else {
        $file    = $_FILES['import_file'];
        $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['csv', 'xls', 'xlsx'];

        if (!in_array($ext, $allowed)) {
            $errors[] = 'শুধুমাত্র CSV, XLS বা XLSX ফাইল গ্রহণযোগ্য।';
        } else {
            // Read file content
            $rows = [];
            if ($ext === 'csv') {
                $handle = fopen($file['tmp_name'], 'r');
                // Detect BOM
                $bom = fread($handle, 3);
                if ($bom !== "\xEF\xBB\xBF") rewind($handle);
                while (($row = fgetcsv($handle, 2000, ',')) !== false) {
                    $rows[] = $row;
                }
                fclose($handle);
            } else {
                // XLS — read as HTML/text table (simple approach)
                // For proper XLS parsing, PhpSpreadsheet would be needed
                // ফাইলের মূল নাম session এ রাখি (A-3.3 history এর জন্য)
            $_SESSION['import_filename'] = $_FILES['import_file']['name'];
            // We use a simple HTML-to-array parser for our generated template
                $content = file_get_contents($file['tmp_name']);
                // Strip HTML tags, get text content
                $content = preg_replace('/<th[^>]*>/i', "\t", $content);
                $content = preg_replace('/<td[^>]*>/i', "\t", $content);
                $content = preg_replace('/<tr[^>]*>/i', "\n", $content);
                $content = strip_tags($content);
                foreach (explode("\n", $content) as $line) {
                    $line = trim($line);
                    if (empty($line)) continue;
                    $cols = array_map('trim', explode("\t", $line));
                    if (count(array_filter($cols)) > 2) $rows[] = $cols;
                }
            }

            if (count($rows) < 2) {
                $errors[] = 'ফাইলে কোনো ডেটা পাওয়া যায়নি।';
            } else {
                // Skip header row(s)
                $headerRow = array_shift($rows);
                // Skip instruction row if present
                if (isset($rows[0]) && str_contains(implode('', $rows[0]), 'due_payment')) {
                    array_shift($rows);
                }

                $validTypes  = ['due_payment','fraud','equipment_theft','contract_breach','other'];
                $validRisks  = ['critical','high','medium','low'];
                $validConns  = ['home','office','corporate','other'];

                foreach ($rows as $ri => $row) {
                    // Pad row to 15 columns
                    while (count($row) < 15) $row[] = '';

                    $rowNum  = $ri + 2;
                    $rowErrors = [];

                    $name    = trim($row[0] ?? '');
                    $phone   = trim($row[1] ?? '');
                    $altPhone= trim($row[2] ?? '');
                    $nid     = trim($row[3] ?? '');
                    $email   = trim($row[4] ?? '');
                    $address = trim($row[5] ?? '');
                    $area    = trim($row[6] ?? '');
                    $thana   = trim($row[7] ?? '');
                    $district= trim($row[8] ?? '');
                    $due     = trim($row[9] ?? '0');
                    $type    = trim($row[10] ?? 'due_payment');
                    $risk    = trim($row[11] ?? 'medium');
                    $conn    = trim($row[12] ?? 'home');
                    $period  = trim($row[13] ?? '');
                    $desc    = trim($row[14] ?? '');

                    if (empty($name))    $rowErrors[] = 'নাম খালি';
                    if (empty($phone) || !preg_match('/^01[3-9]\d{8}$/', $phone)) $rowErrors[] = 'মোবাইল সঠিক নয়';
                    if (empty($address)) $rowErrors[] = 'ঠিকানা খালি';
                    if (!is_numeric($due) || $due < 0) $rowErrors[] = 'বকেয়া সঠিক নয়';
                    if (!in_array($type, $validTypes)) { $type = 'due_payment'; }
                    if (!in_array($risk, $validRisks)) { $risk = 'medium'; }
                    if (!in_array($conn, $validConns)) { $conn = 'home'; }

                    // Duplicate check
                    $dupExists = Database::fetchOne(
                        "SELECT id FROM defaulters WHERE customer_phone = ? AND company_id = ? AND status='active'",
                        [$phone, $user['company_id']]
                    );

                    $preview[] = [
                        'row'      => $rowNum,
                        'name'     => $name,
                        'phone'    => $phone,
                        'alt_phone'=> $altPhone,
                        'nid'      => $nid,
                        'email'    => $email,
                        'address'  => $address,
                        'area'     => $area,
                        'thana'    => $thana,
                        'district' => $district,
                        'due'      => (float)$due,
                        'type'     => $type,
                        'risk'     => $risk,
                        'conn'     => $conn,
                        'period'   => $period,
                        'desc'     => $desc,
                        'errors'   => $rowErrors,
                        'is_dup'   => (bool)$dupExists,
                        'dup_id'   => $dupExists['id'] ?? null,
                        'status'   => empty($rowErrors) && !$dupExists ? 'ok' : (empty($rowErrors) && $dupExists ? 'dup' : 'error'),
                    ];
                }

                // Save preview to session
                $_SESSION['import_preview'] = $preview;
                $_SESSION['import_company'] = $user['company_id'];
                $step = '2';
            }
        }
    }
}

// ---- STEP 3: Execute Import ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === '2') {
    if (!validateCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $errors[] = 'নিরাপত্তা যাচাই ব্যর্থ।';
    } else {
        $preview   = $_SESSION['import_preview'] ?? [];
        $skipDups  = isset($_POST['skip_duplicates']);
        $imported  = 0;
        $skipped   = 0;
        $failed    = 0;

        Database::beginTransaction();
        try {
            foreach ($preview as $row) {
                if (!empty($row['errors'])) { $failed++; continue; }
                if ($row['is_dup'] && $skipDups) { $skipped++; continue; }

                $newId = Database::insert('defaulters', [
                    'company_id'     => $user['company_id'],
                    'entered_by'     => $user['id'],
                    'customer_name'  => $row['name'],
                    'customer_phone' => $row['phone'],
                    'alt_phone'      => $row['alt_phone'] ?: null,
                    'nid_number'     => $row['nid'] ?: null,
                    'email'          => $row['email'] ?: null,
                    'address_text'   => $row['address'],
                    'area'           => $row['area'] ?: null,
                    'thana'          => $row['thana'] ?: null,
                    'district'       => $row['district'] ?: null,
                    'due_amount'     => $row['due'],
                    'type'           => $row['type'],
                    'risk_level'     => $row['risk'],
                    'connection_type'=> $row['conn'],
                    'service_period' => $row['period'] ?: null,
                    'description'    => $row['desc'] ?: null,
                    'status'         => 'active',
                ]);
                $importLog[] = ['name' => $row['name'], 'phone' => $row['phone'], 'id' => $newId, 'status' => 'imported'];
                $imported++;
            }
            Database::commit();

            logActivity('defaulter.bulk_import', 'defaulters', [
                'description' => "বাল্ক ইমপোর্ট: $imported আমদানি, $skipped বাদ, $failed ব্যর্থ",
            ]);

            // A-3.3: Import History সংরক্ষণ
            $origFilename = $_SESSION['import_filename'] ?? 'import.xls';
            try {
                Database::insert('import_logs', [
                    'company_id'    => $user['company_id'],
                    'user_id'       => $user['id'],
                    'filename'      => $origFilename,
                    'total_rows'    => count($preview),
                    'success_count' => $imported,
                    'skip_count'    => $skipped,
                    'error_count'   => $failed,
                    'notes'         => "$imported আমদানি, $skipped বাদ দেওয়া, $failed ব্যর্থ",
                ]);
            } catch (Exception $e) { /* টেবিল না থাকলে এড়িয়ে যাবে */ }
            unset($_SESSION['import_filename']);

            unset($_SESSION['import_preview']);
            $_SESSION['import_result'] = compact('imported','skipped','failed','importLog');
            $step = '3';

        } catch (Exception $e) {
            Database::rollback();
            $errors[] = 'ইমপোর্ট ব্যর্থ হয়েছে: ' . $e->getMessage();
            $step = '2';
            $preview = $_SESSION['import_preview'] ?? [];
        }
    }
}

// Load result for step 3
if ($step === '3' && isset($_SESSION['import_result'])) {
    extract($_SESSION['import_result']);
    unset($_SESSION['import_result']);
}

// Load preview from session for step 2
if ($step === '2' && empty($preview)) {
    $preview = $_SESSION['import_preview'] ?? [];
    if (empty($preview)) $step = '1';
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div>
        <h1>বাল্ক ইমপোর্ট</h1>
        <nav><ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="list.php">বকেয়া তালিকা</a></li>
            <li class="breadcrumb-item active">বাল্ক ইমপোর্ট</li>
        </ol></nav>
    </div>
    <a href="?download_template=1" class="btn btn-outline-success btn-sm">
        <i class="bi bi-download me-1"></i>টেমপ্লেট ডাউনলোড করুন
    </a>
</div>

<!-- Progress Steps -->
<div class="d-flex align-items-center gap-0 mb-4">
    <?php foreach ([1=>'ফাইল আপলোড', 2=>'প্রিভিউ ও যাচাই', 3=>'সম্পন্ন'] as $s => $label): ?>
    <div class="d-flex align-items-center <?= $s < 3 ? 'flex-fill' : '' ?>">
        <div class="d-flex align-items-center gap-2">
            <div style="width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;
                        background:<?= (int)$step >= $s ? '#1a3a5c' : '#e2e8f0' ?>;
                        color:<?= (int)$step >= $s ? '#fff' : '#94a3b8' ?>;">
                <?= (int)$step > $s ? '✓' : $s ?>
            </div>
            <span style="font-size:13px;color:<?= (int)$step >= $s ? '#0f2238' : '#94a3b8' ?>;font-weight:<?= (int)$step === $s ? '600' : '400' ?>;">
                <?= $label ?>
            </span>
        </div>
        <?php if ($s < 3): ?>
        <div style="flex:1;height:2px;background:<?= (int)$step > $s ? '#1a3a5c' : '#e2e8f0' ?>;margin:0 12px;"></div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger rounded-3 mb-4" style="font-size:13px;">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<!-- ============================= STEP 1: Upload ============================= -->
<?php if ($step === '1'): ?>
<div class="row justify-content-center">
<div class="col-lg-7">
    <div class="card">
        <div class="card-header"><h6 class="card-title"><i class="bi bi-cloud-upload me-2"></i>Excel/CSV ফাইল আপলোড করুন</h6></div>
        <div class="card-body p-4">
            <!-- Instructions -->
            <div class="alert mb-4" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;color:#1e40af;font-size:13px;">
                <div class="fw-semibold mb-2"><i class="bi bi-info-circle me-1"></i>নির্দেশনা:</div>
                <ol class="mb-0 ps-3">
                    <li>প্রথমে <a href="?download_template=1" class="fw-semibold">টেমপ্লেট ডাউনলোড করুন</a></li>
                    <li>টেমপ্লেটে ডেটা পূরণ করুন (হেডার সরাবেন না)</li>
                    <li>CSV বা Excel ফরম্যাটে সেভ করুন</li>
                    <li>নিচে আপলোড করুন</li>
                </ol>
            </div>

            <form method="POST" enctype="multipart/form-data">
                <?= csrfField() ?>
                <input type="hidden" name="step" value="1">

                <div class="drop-zone mb-3" id="dropZone" onclick="document.getElementById('importFile').click()"
                     style="border:2px dashed #e2e8f0;border-radius:12px;padding:40px;text-align:center;cursor:pointer;background:#f8fafc;">
                    <i class="bi bi-file-earmark-spreadsheet fs-2 text-muted d-block mb-2"></i>
                    <div class="fw-semibold text-muted">CSV বা Excel ফাইল টেনে আনুন বা ক্লিক করুন</div>
                    <div class="text-muted small mt-1">সর্বোচ্চ ৫,০০০ রেকর্ড একসাথে ইমপোর্ট করা যাবে</div>
                    <div id="selectedFile" class="mt-3 fw-semibold text-primary" style="display:none;"></div>
                </div>
                <input type="file" id="importFile" name="import_file" accept=".csv,.xls,.xlsx" hidden
                       onchange="document.getElementById('selectedFile').textContent = this.files[0].name;
                                 document.getElementById('selectedFile').style.display='block';">

                <button type="submit" class="btn btn-primary w-100 py-2 mt-2" style="font-size:15px;">
                    <i class="bi bi-upload me-2"></i>আপলোড ও যাচাই করুন
                </button>
            </form>
        </div>
    </div>
</div>
</div>

<!-- ============================= STEP 2: Preview ============================= -->
<?php elseif ($step === '2'): ?>
<?php
$okCount    = count(array_filter($preview, fn($r) => $r['status'] === 'ok'));
$dupCount   = count(array_filter($preview, fn($r) => $r['status'] === 'dup'));
$errCount   = count(array_filter($preview, fn($r) => $r['status'] === 'error'));
$totalAmount= array_sum(array_column(array_filter($preview, fn($r) => $r['status'] === 'ok'), 'due'));
?>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="card p-3 text-center" style="border-left:4px solid #16a34a;">
            <div style="font-size:24px;font-weight:800;color:#16a34a;"><?= $okCount ?></div>
            <div class="text-muted small">ইমপোর্ট হবে</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card p-3 text-center" style="border-left:4px solid #d97706;">
            <div style="font-size:24px;font-weight:800;color:#d97706;"><?= $dupCount ?></div>
            <div class="text-muted small">ডুপ্লিকেট (আগেই আছে)</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card p-3 text-center" style="border-left:4px solid #dc2626;">
            <div style="font-size:24px;font-weight:800;color:#dc2626;"><?= $errCount ?></div>
            <div class="text-muted small">ত্রুটি আছে</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card p-3 text-center" style="border-left:4px solid #2563a8;">
            <div style="font-size:20px;font-weight:800;color:#2563a8;"><?= formatMoney($totalAmount) ?></div>
            <div class="text-muted small">মোট বকেয়া</div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header justify-content-between">
        <h6 class="card-title"><i class="bi bi-table me-2"></i>প্রিভিউ (<?= count($preview) ?> রেকর্ড)</h6>
        <div class="d-flex gap-2">
            <span style="font-size:12px;color:#16a34a;"><i class="bi bi-circle-fill me-1" style="font-size:8px;"></i>ঠিক আছে</span>
            <span style="font-size:12px;color:#d97706;"><i class="bi bi-circle-fill me-1" style="font-size:8px;"></i>ডুপ্লিকেট</span>
            <span style="font-size:12px;color:#dc2626;"><i class="bi bi-circle-fill me-1" style="font-size:8px;"></i>ত্রুটি</span>
        </div>
    </div>
    <div class="table-responsive" style="max-height:400px;overflow-y:auto;">
        <table class="table table-sm" style="font-size:12px;">
            <thead style="position:sticky;top:0;background:#f8fafc;z-index:1;">
                <tr>
                    <th>লাইন</th><th>নাম</th><th>মোবাইল</th>
                    <th>ঠিকানা</th><th>বকেয়া</th><th>ঝুঁকি</th>
                    <th>অবস্থা</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($preview as $r): ?>
            <tr style="background:<?= match($r['status']) { 'ok'=>'#f0fdf4','dup'=>'#fffbeb','error'=>'#fef2f2',default=>'#fff' } ?>">
                <td class="text-muted"><?= $r['row'] ?></td>
                <td><?= htmlspecialchars($r['name']) ?></td>
                <td><?= htmlspecialchars($r['phone']) ?></td>
                <td style="max-width:150px;" class="text-truncate"><?= htmlspecialchars($r['address']) ?></td>
                <td style="color:#dc2626;font-weight:600;">৳<?= number_format($r['due']) ?></td>
                <td><span class="risk-badge risk-<?= $r['risk'] ?>" style="font-size:10px;"><?= $r['risk'] ?></span></td>
                <td>
                    <?php if ($r['status'] === 'ok'): ?>
                    <span class="text-success"><i class="bi bi-check-circle-fill me-1"></i>ঠিক আছে</span>
                    <?php elseif ($r['status'] === 'dup'): ?>
                    <span class="text-warning">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>ডুপ্লিকেট
                        <a href="../defaulter/view.php?id=<?= $r['dup_id'] ?>" target="_blank" style="font-size:10px;">(দেখুন)</a>
                    </span>
                    <?php else: ?>
                    <span class="text-danger"><i class="bi bi-x-circle-fill me-1"></i><?= htmlspecialchars(implode(', ', $r['errors'])) ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="step" value="2">
    <div class="card p-4">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div>
                <?php if ($dupCount > 0): ?>
                <label class="d-flex align-items-center gap-2" style="cursor:pointer;font-size:14px;">
                    <input type="checkbox" name="skip_duplicates" checked class="form-check-input mt-0">
                    ডুপ্লিকেট রেকর্ড বাদ দিন (<?= $dupCount ?>টি)
                </label>
                <?php endif; ?>
                <?php if ($errCount > 0): ?>
                <div class="text-danger small mt-1">
                    <i class="bi bi-info-circle me-1"></i><?= $errCount ?> টি ত্রুটিপূর্ণ রেকর্ড স্বয়ংক্রিয়ভাবে বাদ যাবে
                </div>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-2">
                <a href="?" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i>পিছনে যান
                </a>
                <button type="submit" class="btn btn-success px-4" <?= $okCount === 0 ? 'disabled' : '' ?>>
                    <i class="bi bi-cloud-upload me-2"></i><?= $okCount ?> টি রেকর্ড ইমপোর্ট করুন
                </button>
            </div>
        </div>
    </div>
</form>

<!-- ============================= STEP 3: Done ============================= -->
<?php elseif ($step === '3'): ?>
<div class="row justify-content-center">
<div class="col-lg-6">
    <div class="card text-center p-5">
        <div style="width:80px;height:80px;border-radius:50%;background:#f0fdf4;border:3px solid #16a34a;
                    display:flex;align-items:center;justify-content:center;font-size:36px;margin:0 auto 20px;">
            ✅
        </div>
        <h4 class="fw-bold mb-1" style="color:#0f2238;">ইমপোর্ট সম্পন্ন!</h4>
        <p class="text-muted mb-4">বাল্ক ইমপোর্ট সফলভাবে শেষ হয়েছে।</p>

        <div class="row g-3 mb-4">
            <div class="col-4">
                <div class="p-3 rounded-3" style="background:#f0fdf4;border:1px solid #bbf7d0;">
                    <div style="font-size:28px;font-weight:800;color:#16a34a;"><?= $imported ?? 0 ?></div>
                    <div class="text-muted small">ইমপোর্ট হয়েছে</div>
                </div>
            </div>
            <div class="col-4">
                <div class="p-3 rounded-3" style="background:#fffbeb;border:1px solid #fde68a;">
                    <div style="font-size:28px;font-weight:800;color:#d97706;"><?= $skipped ?? 0 ?></div>
                    <div class="text-muted small">বাদ গেছে</div>
                </div>
            </div>
            <div class="col-4">
                <div class="p-3 rounded-3" style="background:#fef2f2;border:1px solid #fecaca;">
                    <div style="font-size:28px;font-weight:800;color:#dc2626;"><?= $failed ?? 0 ?></div>
                    <div class="text-muted small">ব্যর্থ হয়েছে</div>
                </div>
            </div>
        </div>

        <div class="d-flex gap-2 justify-content-center">
            <a href="list.php" class="btn btn-primary px-4">
                <i class="bi bi-list me-1"></i>তালিকায় দেখুন
            </a>
            <a href="?" class="btn btn-outline-secondary">
                <i class="bi bi-upload me-1"></i>আরো ইমপোর্ট করুন
            </a>
        </div>
    </div>
</div>
</div>
<?php endif; ?>

<?php
// A-3.3: Import History — সব সময় নিচে দেখাবে
try {
    $importHistory = Database::fetchAll(
        "SELECT il.*, u.full_name as imported_by
         FROM import_logs il
         JOIN users u ON u.id = il.user_id
         WHERE il.company_id = ?
         ORDER BY il.created_at DESC LIMIT 20",
        [$user['company_id']]
    );
} catch (Exception $e) {
    $importHistory = []; // টেবিল না থাকলে চুপচাপ এড়িয়ে যাবে
}
?>
<?php if (!empty($importHistory)): ?>
<div class="row justify-content-center mt-4">
<div class="col-lg-8">
    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h6 class="card-title mb-0">
                <i class="bi bi-clock-history me-2"></i>ইমপোর্ট ইতিহাস
            </h6>
            <span class="badge bg-secondary rounded-pill"><?= count($importHistory) ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:13px;">
                <thead class="table-light">
                    <tr>
                        <th>তারিখ</th>
                        <th>ফাইল</th>
                        <th>মোট সারি</th>
                        <th class="text-success">সফল</th>
                        <th class="text-warning">বাদ</th>
                        <th class="text-danger">ব্যর্থ</th>
                        <th>করেছেন</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($importHistory as $h): ?>
                <tr>
                    <td>
                        <div><?= date('d M Y', strtotime($h['created_at'])) ?></div>
                        <div class="text-muted" style="font-size:11px;"><?= date('h:i A', strtotime($h['created_at'])) ?></div>
                    </td>
                    <td>
                        <span style="font-size:12px;">
                            <i class="bi bi-file-earmark-spreadsheet text-success me-1"></i>
                            <?= htmlspecialchars(mb_substr($h['filename'], 0, 30, 'UTF-8')) ?>
                        </span>
                    </td>
                    <td class="text-center fw-semibold"><?= $h['total_rows'] ?></td>
                    <td class="text-center">
                        <span class="badge bg-success rounded-pill"><?= $h['success_count'] ?></span>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-warning text-dark rounded-pill"><?= $h['skip_count'] ?></span>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-danger rounded-pill"><?= $h['error_count'] ?></span>
                    </td>
                    <td style="font-size:12px;"><?= htmlspecialchars($h['imported_by']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>