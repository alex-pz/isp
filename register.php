<?php
// ============================================================
// COMPANY REGISTRATION PAGE
// File: register.php
// ============================================================
require_once __DIR__ . '/includes/functions.php';
startSession();

if (isLoggedIn()) redirect(SITE_URL . '/dashboard.php');

if (getSetting('allow_registration', '1') !== '1') {
    die('<div style="text-align:center;padding:60px;font-family:sans-serif;">
        নতুন রেজিস্ট্রেশন এই মুহূর্তে বন্ধ আছে।
        <br><a href="' . SITE_URL . '/login.php">লগইন পেজে যান</a></div>');
}

$errors = [];
$data   = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!validateCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $errors[] = 'নিরাপত্তা যাচাই ব্যর্থ।';
    } else {
        // Collect & sanitize
        $data = [
            'company_name'  => trim($_POST['company_name']  ?? ''),
            'owner_name'    => trim($_POST['owner_name']    ?? ''),
            'email'         => trim($_POST['email']         ?? ''),
            'phone'         => trim($_POST['phone']         ?? ''),
            'alt_phone'     => trim($_POST['alt_phone']     ?? ''),
            'address'       => trim($_POST['address']       ?? ''),
            'area'          => trim($_POST['area']          ?? ''),
            'trade_license' => trim($_POST['trade_license'] ?? ''),
            'nid_number'    => trim($_POST['nid_number']    ?? ''),
            'description'   => trim($_POST['description']   ?? ''),
            // User account
            'username'      => trim($_POST['username']      ?? ''),
            'password'      => $_POST['password']           ?? '',
            'confirm_pass'  => $_POST['confirm_pass']       ?? '',
        ];

        // Validation
        if (empty($data['company_name']))  $errors[] = 'কোম্পানির নাম দিন।';
        if (empty($data['owner_name']))    $errors[] = 'মালিকের নাম দিন।';
        if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL))
            $errors[] = 'সঠিক ইমেইল দিন।';
        if (empty($data['phone']) || !preg_match('/^01[3-9]\d{8}$/', $data['phone']))
            $errors[] = 'সঠিক মোবাইল নম্বর দিন (11 সংখ্যা, 01 দিয়ে শুরু)।';
        if (empty($data['address']))       $errors[] = 'ঠিকানা দিন।';
        if (empty($data['area']))          $errors[] = 'সার্ভিস এলাকা দিন।';
        if (empty($data['username']) || strlen($data['username']) < 4)
            $errors[] = 'ইউজারনেম কমপক্ষে ৪ অক্ষর হতে হবে।';
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $data['username']))
            $errors[] = 'ইউজারনেমে শুধু ইংরেজি অক্ষর, সংখ্যা ও _ ব্যবহার করুন।';
        if (strlen($data['password']) < 8) $errors[] = 'পাসওয়ার্ড কমপক্ষে ৮ অক্ষর হতে হবে।';
        if ($data['password'] !== $data['confirm_pass'])
            $errors[] = 'পাসওয়ার্ড দুটো মিলছে না।';

        // Duplicate checks
        if (empty($errors)) {
            if (Database::count('companies', 'email = ?', [$data['email']]))
                $errors[] = 'এই ইমেইল দিয়ে আগেই রেজিস্ট্রেশন হয়েছে।';
            if (Database::count('users', 'username = ?', [$data['username']]))
                $errors[] = 'এই ইউজারনেম আগেই নেওয়া হয়েছে। অন্য ইউজারনেম বেছে নিন।';
            if (Database::count('users', 'email = ?', [$data['email']]))
                $errors[] = 'এই ইমেইল দিয়ে আগেই অ্যাকাউন্ট আছে।';
        }

        // Logo upload
        $logoPath = null;
        if (!empty($_FILES['logo']['name'])) {
            $upload = uploadPhoto($_FILES['logo'], 'logos');
            if (!$upload['success']) {
                $errors[] = 'লোগো আপলোড: ' . $upload['error'];
            } else {
                $logoPath = $upload['path'];
            }
        }

        if (empty($errors)) {
            Database::beginTransaction();
            try {
                // Insert company
                $companyId = Database::insert('companies', [
                    'company_name'  => $data['company_name'],
                    'owner_name'    => $data['owner_name'],
                    'email'         => $data['email'],
                    'phone'         => $data['phone'],
                    'alt_phone'     => $data['alt_phone'] ?: null,
                    'address'       => $data['address'],
                    'area'          => $data['area'],
                    'trade_license' => $data['trade_license'] ?: null,
                    'nid_number'    => $data['nid_number'] ?: null,
                    'logo'          => $logoPath,
                    'description'   => $data['description'] ?: null,
                    'status'        => 'pending',
                ]);

                // Insert user as company_admin (role_id = 3)
                $userId = Database::insert('users', [
                    'company_id' => $companyId,
                    'role_id'    => 3,
                    'full_name'  => $data['owner_name'],
                    'username'   => $data['username'],
                    'email'      => $data['email'],
                    'phone'      => $data['phone'],
                    'password'   => password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]),
                    'status'     => 'active',
                    'created_by' => null,
                ]);

                // Notify super admins
                $admins = Database::fetchAll(
                    "SELECT id FROM users WHERE role_id IN (1,2) AND status = 'active'"
                );
                foreach ($admins as $admin) {
                    createNotification(
                        'নতুন কোম্পানি রেজিস্ট্রেশন',
                        '"' . $data['company_name'] . '" অনুমোদনের জন্য অপেক্ষা করছে।',
                        [
                            'user_id' => $admin['id'],
                            'type'    => 'warning',
                            'icon'    => 'building',
                            'link'    => SITE_URL . '/modules/admin/companies.php?status=pending',
                        ]
                    );
                }

                logActivity('company.register', 'companies', [
                    'target_id'   => $companyId,
                    'target_type' => 'companies',
                    'description' => '"' . $data['company_name'] . '" রেজিস্ট্রেশন করেছে',
                ]);

                Database::commit();
                $success = true;

            } catch (Exception $e) {
                Database::rollback();
                if ($logoPath) deleteUploadedFile($logoPath);
                $errors[] = 'রেজিস্ট্রেশনে সমস্যা হয়েছে। পুনরায় চেষ্টা করুন।';
                if (DEBUG_MODE) $errors[] = $e->getMessage();
            }
        }
    }
}

$siteName = getSetting('site_name', 'ISP Defaulter System');
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>কোম্পানি রেজিস্ট্রেশন — <?= htmlspecialchars($siteName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Hind Siliguri', sans-serif; }
        body { background: #f0f4f8; padding: 30px 0; }
        .reg-card {
            max-width: 800px; margin: 0 auto;
            background: #fff; border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,.08); overflow: hidden;
        }
        .reg-header {
            background: linear-gradient(135deg, #0f2238, #2563a8);
            padding: 30px 40px; color: #fff;
        }
        .reg-header h1 { font-size: 22px; font-weight: 700; margin: 0; }
        .reg-header p  { font-size: 14px; opacity: .75; margin: 6px 0 0; }
        .reg-body { padding: 36px 40px; }
        .section-title {
            font-size: 14px; font-weight: 700; color: #1a3a5c;
            text-transform: uppercase; letter-spacing: .8px;
            border-left: 4px solid #2563a8; padding-left: 12px;
            margin: 28px 0 18px;
        }
        .form-label { font-size: 13px; font-weight: 600; color: #475569; }
        .required-star { color: #e8392d; }
        .form-control, .form-select {
            border-radius: 8px; border: 2px solid #e2e8f0; padding: 9px 14px; font-size: 14px;
        }
        .form-control:focus, .form-select:focus {
            border-color: #2563a8; box-shadow: 0 0 0 3px rgba(37,99,168,.12);
        }
        .form-text { font-size: 12px; color: #94a3b8; }
        .logo-preview {
            width: 80px; height: 80px; border-radius: 12px;
            border: 2px dashed #e2e8f0; background: #f8fafc;
            display: flex; align-items: center; justify-content: center;
            font-size: 28px; color: #cbd5e1; overflow: hidden; cursor: pointer;
        }
        .logo-preview img { width: 100%; height: 100%; object-fit: cover; }
        .btn-submit {
            background: linear-gradient(135deg, #1a3a5c, #2563a8);
            border: none; border-radius: 10px; padding: 13px 32px;
            font-size: 15px; font-weight: 600; color: #fff; transition: opacity .2s;
        }
        .btn-submit:hover { opacity: .88; color: #fff; }
        .success-box {
            text-align: center; padding: 50px 40px;
        }
        .success-box .icon {
            width: 80px; height: 80px; border-radius: 50%;
            background: #f0fdf4; color: #16a34a;
            display: flex; align-items: center; justify-content: center;
            font-size: 36px; margin: 0 auto 20px;
        }
        .password-strength { height: 4px; border-radius: 2px; transition: all .3s; margin-top: 6px; }
        @media (max-width: 767px) {
            .reg-header, .reg-body { padding: 24px 20px; }
        }
    </style>
</head>
<body>
<div class="container px-3">
<div class="reg-card">

    <div class="reg-header">
        <div class="d-flex align-items-center gap-3">
            <div style="width:46px;height:46px;background:rgba(255,255,255,.15);border-radius:10px;
                        display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:800;">
                ISP
            </div>
            <div>
                <h1>কোম্পানি রেজিস্ট্রেশন</h1>
                <p><?= htmlspecialchars($siteName) ?></p>
            </div>
        </div>
    </div>

    <div class="reg-body">

    <?php if ($success): ?>
    <!-- SUCCESS STATE -->
    <div class="success-box">
        <div class="icon"><i class="bi bi-check-lg"></i></div>
        <h3 style="color:#0f2238;font-weight:700;">রেজিস্ট্রেশন সফল!</h3>
        <p class="text-muted" style="max-width:420px;margin:12px auto;">
            আপনার কোম্পানির রেজিস্ট্রেশন সফলভাবে সম্পন্ন হয়েছে।
            অ্যাডমিন অনুমোদন দিলে আপনি লগইন করে সিস্টেম ব্যবহার করতে পারবেন।
        </p>
        <div class="alert" style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;color:#92400e;max-width:420px;margin:20px auto;font-size:13px;">
            <i class="bi bi-clock me-2"></i>অনুমোদন প্রক্রিয়া সাধারণত ১-২৪ ঘন্টার মধ্যে সম্পন্ন হয়।
        </div>
        <a href="<?= SITE_URL ?>/login.php" class="btn-submit btn">
            <i class="bi bi-box-arrow-in-right me-2"></i>লগইন পেজে যান
        </a>
    </div>

    <?php else: ?>

        <!-- ERRORS -->
        <?php if (!empty($errors)): ?>
        <div class="alert" style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;color:#dc2626;font-size:13px;">
            <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle me-1"></i>নিচের সমস্যাগুলো ঠিক করুন:</div>
            <ul class="mb-0 ps-3">
                <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <?= csrfField() ?>

            <!-- Company Info -->
            <div class="section-title">কোম্পানির তথ্য</div>
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label">কোম্পানির নাম <span class="required-star">*</span></label>
                    <input type="text" name="company_name" class="form-control"
                           value="<?= htmlspecialchars($data['company_name'] ?? '') ?>"
                           placeholder="যেমন: SpeedNet Broadband" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">কোম্পানি লোগো</label>
                    <div class="d-flex align-items-center gap-3">
                        <div class="logo-preview" id="logoPreview" onclick="document.getElementById('logoInput').click()">
                            <i class="bi bi-building"></i>
                        </div>
                        <div>
                            <input type="file" name="logo" id="logoInput" accept="image/*" hidden onchange="previewLogo(this)">
                            <button type="button" onclick="document.getElementById('logoInput').click()"
                                    class="btn btn-sm" style="border:2px solid #e2e8f0;border-radius:8px;font-size:12px;color:#475569;">
                                <i class="bi bi-upload me-1"></i>লোগো আপলোড
                            </button>
                            <div class="form-text">JPG, PNG — সর্বোচ্চ ৫MB</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">মালিকের নাম <span class="required-star">*</span></label>
                    <input type="text" name="owner_name" class="form-control"
                           value="<?= htmlspecialchars($data['owner_name'] ?? '') ?>"
                           placeholder="পূর্ণ নাম" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">ইমেইল <span class="required-star">*</span></label>
                    <input type="email" name="email" class="form-control"
                           value="<?= htmlspecialchars($data['email'] ?? '') ?>"
                           placeholder="company@email.com" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">মোবাইল নম্বর <span class="required-star">*</span></label>
                    <input type="text" name="phone" class="form-control"
                           value="<?= htmlspecialchars($data['phone'] ?? '') ?>"
                           placeholder="01XXXXXXXXX" maxlength="11" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">বিকল্প নম্বর</label>
                    <input type="text" name="alt_phone" class="form-control"
                           value="<?= htmlspecialchars($data['alt_phone'] ?? '') ?>"
                           placeholder="01XXXXXXXXX (ঐচ্ছিক)" maxlength="11">
                </div>
                <div class="col-md-6">
                    <label class="form-label">ট্রেড লাইসেন্স নম্বর</label>
                    <input type="text" name="trade_license" class="form-control"
                           value="<?= htmlspecialchars($data['trade_license'] ?? '') ?>"
                           placeholder="ঐচ্ছিক">
                </div>
                <div class="col-md-6">
                    <label class="form-label">মালিকের NID নম্বর</label>
                    <input type="text" name="nid_number" class="form-control"
                           value="<?= htmlspecialchars($data['nid_number'] ?? '') ?>"
                           placeholder="ঐচ্ছিক">
                </div>
                <div class="col-md-6">
                    <label class="form-label">সার্ভিস এলাকা <span class="required-star">*</span></label>
                    <input type="text" name="area" class="form-control"
                           value="<?= htmlspecialchars($data['area'] ?? '') ?>"
                           placeholder="যেমন: মিরপুর, ঢাকা" required>
                    <div class="form-text">আপনার ইন্টারনেট সার্ভিস কোন এলাকায়?</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">পূর্ণ ঠিকানা <span class="required-star">*</span></label>
                    <input type="text" name="address" class="form-control"
                           value="<?= htmlspecialchars($data['address'] ?? '') ?>"
                           placeholder="বাড়ি/ফ্ল্যাট, রাস্তা, এলাকা" required>
                </div>
                <div class="col-12">
                    <label class="form-label">কোম্পানির বিবরণ</label>
                    <textarea name="description" class="form-control" rows="2"
                              placeholder="সংক্ষিপ্ত পরিচয় (ঐচ্ছিক)"><?= htmlspecialchars($data['description'] ?? '') ?></textarea>
                </div>
            </div>

            <!-- Account Info -->
            <div class="section-title">লগইন অ্যাকাউন্ট</div>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">ইউজারনেম <span class="required-star">*</span></label>
                    <input type="text" name="username" class="form-control"
                           value="<?= htmlspecialchars($data['username'] ?? '') ?>"
                           placeholder="mycompany123" maxlength="50" required>
                    <div class="form-text">শুধু ইংরেজি অক্ষর, সংখ্যা ও _ ব্যবহার করুন</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">পাসওয়ার্ড <span class="required-star">*</span></label>
                    <input type="password" name="password" id="passInput" class="form-control"
                           placeholder="কমপক্ষে ৮ অক্ষর" required oninput="checkStrength(this.value)">
                    <div class="password-strength bg-secondary" id="strengthBar"></div>
                    <div class="form-text" id="strengthText">কমপক্ষে ৮ অক্ষর দিন</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">পাসওয়ার্ড নিশ্চিত করুন <span class="required-star">*</span></label>
                    <input type="password" name="confirm_pass" class="form-control"
                           placeholder="পুনরায় পাসওয়ার্ড দিন" required>
                </div>
            </div>

            <!-- Notice -->
            <div class="alert mt-4" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;color:#1e40af;font-size:13px;">
                <i class="bi bi-info-circle me-2"></i>
                রেজিস্ট্রেশনের পর অ্যাডমিন আপনার তথ্য যাচাই করে অনুমোদন দেবেন।
                অনুমোদনের আগে সিস্টেমে প্রবেশ করা যাবে না।
            </div>

            <div class="d-flex align-items-center justify-content-between mt-4">
                <a href="<?= SITE_URL ?>/login.php" class="text-muted text-decoration-none" style="font-size:14px;">
                    <i class="bi bi-arrow-left me-1"></i>লগইনে ফিরুন
                </a>
                <button type="submit" class="btn-submit btn">
                    <i class="bi bi-send me-2"></i>রেজিস্ট্রেশন করুন
                </button>
            </div>
        </form>

    <?php endif; ?>
    </div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function previewLogo(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            document.getElementById('logoPreview').innerHTML =
                `<img src="${e.target.result}" alt="Logo">`;
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function checkStrength(val) {
    let score = 0;
    if (val.length >= 8)  score++;
    if (val.length >= 12) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;

    const bar  = document.getElementById('strengthBar');
    const text = document.getElementById('strengthText');
    const levels = [
        { color: '#e2e8f0', label: '' },
        { color: '#ef4444', label: 'অনেক দুর্বল' },
        { color: '#f97316', label: 'দুর্বল' },
        { color: '#eab308', label: 'মাঝারি' },
        { color: '#22c55e', label: 'ভালো' },
        { color: '#16a34a', label: 'খুব শক্তিশালী' },
    ];
    const lvl = Math.min(score, 5);
    bar.style.background = levels[lvl].color;
    bar.style.width = (lvl * 20) + '%';
    text.textContent = levels[lvl].label;
    text.style.color = levels[lvl].color;
}
</script>
</body>
</html>
