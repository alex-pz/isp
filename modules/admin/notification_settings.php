<?php
// ============================================================
// NOTIFICATION SETTINGS (tab in admin/settings.php — standalone)
// File: modules/admin/notification_settings.php
// ============================================================
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/notification_service.php';
requireLogin();

$user = getCurrentUser();
if ($user['role_name'] !== 'super_admin') {
    setFlash('error', 'শুধুমাত্র সুপার অ্যাডমিন পরিবর্তন করতে পারবেন।');
    redirect(SITE_URL . '/dashboard.php');
}

$pageTitle = 'নোটিফিকেশন সেটিংস';
$tab       = $_GET['tab'] ?? 'email';

// ---- Test notifications ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_email'])) {
    require_once __DIR__ . '/../../includes/notification_service.php';
    $result = NotificationService::sendEmail(
        $user['email'], $user['full_name'],
        'টেস্ট ইমেইল — ' . getSetting('site_name'),
        '<p>এটি একটি টেস্ট ইমেইল। SMTP সেটিংস সঠিক আছে!</p>'
    );
    setFlash($result['success'] ? 'success' : 'error',
        $result['success'] ? 'টেস্ট ইমেইল সফলভাবে পাঠানো হয়েছে!' : 'ইমেইল পাঠানো যায়নি: ' . ($result['error'] ?? ''));
    redirect($_SERVER['PHP_SELF'] . '?tab=email');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_sms'])) {
    require_once __DIR__ . '/../../includes/notification_service.php';
    $result = NotificationService::sendSMS($user['phone'] ?? '01700000000', 'টেস্ট SMS — ' . getSetting('site_name'));
    setFlash($result['success'] ? 'success' : 'error',
        $result['success'] ? 'টেস্ট SMS পাঠানো হয়েছে!' : 'SMS পাঠানো যায়নি: ' . ($result['error'] ?? ''));
    redirect($_SERVER['PHP_SELF'] . '?tab=sms');
}

// ---- Save settings ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['settings'])) {
    if (!validateCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        setFlash('error', 'নিরাপত্তা যাচাই ব্যর্থ।'); redirect($_SERVER['PHP_SELF']);
    }
    foreach ($_POST['settings'] as $key => $value) {
        // Don't overwrite password if empty
        if (in_array($key, ['smtp_pass','sms_api_token','sms_password']) && empty(trim($value))) continue;
        updateSetting($key, trim($value));
    }
    // Handle checkboxes (may not be in POST if unchecked)
    $toggles = ['email_notify_new_defaulter','email_notify_approval','email_notify_resolution',
                 'sms_notify_new_defaulter','sms_notify_approval'];
    foreach ($toggles as $t) {
        updateSetting($t, isset($_POST['settings'][$t]) ? '1' : '0');
    }
    logActivity('settings.notification', 'settings', ['description' => "নোটিফিকেশন সেটিংস আপডেট ($tab)"]);
    setFlash('success', 'সেটিংস সংরক্ষণ হয়েছে।');
    redirect($_SERVER['PHP_SELF'] . '?tab=' . $tab);
}

$sm = function($key, $default = '') { return getSetting($key, $default); };

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1>নোটিফিকেশন সেটিংস</h1>
    <nav><ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="settings.php">সেটিংস</a></li>
        <li class="breadcrumb-item active">নোটিফিকেশন</li>
    </ol></nav>
</div>

<div class="row g-3">
<!-- Sidebar -->
<div class="col-lg-3">
    <div class="card">
        <div class="card-body p-2">
            <?php foreach ([
                'email' => ['bi-envelope','ইমেইল সেটিংস'],
                'sms'   => ['bi-phone','SMS সেটিংস'],
                'rules' => ['bi-bell','নোটিফিকেশন নিয়ম'],
            ] as $t => [$icon, $label]): ?>
            <a href="?tab=<?= $t ?>" class="d-flex align-items-center gap-2 px-3 py-2 rounded-2 text-decoration-none mb-1
               <?= $tab === $t ? 'text-white' : 'text-dark' ?>"
               style="<?= $tab === $t ? 'background:#1a3a5c;' : '' ?>font-size:14px;">
                <i class="bi <?= $icon ?>"></i><?= $label ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Main Form -->
<div class="col-lg-9">
<div class="card">
    <div class="card-body p-4">
    <form method="POST">
        <?= csrfField() ?>

        <?php if ($tab === 'email'): ?>
        <!-- ===== EMAIL ===== -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h5 class="fw-bold mb-0" style="color:#0f2238;"><i class="bi bi-envelope me-2"></i>SMTP ইমেইল সেটিংস</h5>
            <button type="submit" name="test_email" value="1" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-send me-1"></i>টেস্ট ইমেইল পাঠান
            </button>
        </div>

        <div class="row g-3">
            <div class="col-md-8">
                <label class="form-label">SMTP Host</label>
                <input type="text" name="settings[smtp_host]" class="form-control"
                       value="<?= htmlspecialchars($sm('smtp_host')) ?>" placeholder="smtp.gmail.com">
                <div class="form-text">Gmail: smtp.gmail.com | Outlook: smtp.office365.com</div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Port</label>
                <select name="settings[smtp_port]" class="form-select">
                    <?php foreach ([587=>'587 (TLS)', 465=>'465 (SSL)', 25=>'25 (Plain)'] as $p=>$l): ?>
                    <option value="<?= $p ?>" <?= $sm('smtp_port','587') == $p ? 'selected':'' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">SMTP Username (ইমেইল)</label>
                <input type="email" name="settings[smtp_user]" class="form-control"
                       value="<?= htmlspecialchars($sm('smtp_user')) ?>" placeholder="your@gmail.com">
            </div>
            <div class="col-md-6">
                <label class="form-label">SMTP Password</label>
                <input type="password" name="settings[smtp_pass]" class="form-control"
                       placeholder="পরিবর্তন না করলে ফাঁকা রাখুন">
                <div class="form-text">Gmail ব্যবহারকারীরা: <a href="https://myaccount.google.com/apppasswords" target="_blank">App Password</a> ব্যবহার করুন</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">From Email</label>
                <input type="email" name="settings[smtp_from]" class="form-control"
                       value="<?= htmlspecialchars($sm('smtp_from')) ?>" placeholder="noreply@yourcompany.com">
            </div>
        </div>

        <!-- Tips -->
        <div class="alert mt-3" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;color:#1e40af;font-size:13px;">
            <strong><i class="bi bi-lightbulb me-1"></i>টিপস:</strong>
            Gmail অ্যাকাউন্টে <a href="https://myaccount.google.com/security" target="_blank">2-Step Verification</a> চালু করুন,
            তারপর App Password তৈরি করুন। সরাসরি Gmail পাসওয়ার্ড কাজ নাও করতে পারে।
        </div>

        <?php elseif ($tab === 'sms'): ?>
        <!-- ===== SMS ===== -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h5 class="fw-bold mb-0" style="color:#0f2238;"><i class="bi bi-phone me-2"></i>SMS গেটওয়ে সেটিংস</h5>
            <button type="submit" name="test_sms" value="1" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-send me-1"></i>টেস্ট SMS পাঠান
            </button>
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">SMS প্রোভাইডার</label>
                <select name="settings[sms_provider]" class="form-select" id="smsProvider" onchange="showProviderHelp(this.value)">
                    <option value="">— নির্বাচন করুন —</option>
                    <option value="ssl"       <?= $sm('sms_provider')==='ssl'?'selected':'' ?>>SSL Wireless (BD)</option>
                    <option value="bulksmsbd" <?= $sm('sms_provider')==='bulksmsbd'?'selected':'' ?>>BulkSMSBD</option>
                    <option value="infobip"   <?= $sm('sms_provider')==='infobip'?'selected':'' ?>>Infobip</option>
                    <option value="twilio"    <?= $sm('sms_provider')==='twilio'?'selected':'' ?>>Twilio</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Sender ID / SID</label>
                <input type="text" name="settings[sms_sid]" class="form-control"
                       value="<?= htmlspecialchars($sm('sms_sid')) ?>" placeholder="ISPSystem">
            </div>
            <div class="col-12">
                <label class="form-label">API Token / Key</label>
                <input type="password" name="settings[sms_api_token]" class="form-control"
                       placeholder="পরিবর্তন না করলে ফাঁকা রাখুন">
            </div>
            <div class="col-md-6" id="baseUrlField">
                <label class="form-label">Base URL (Infobip only)</label>
                <input type="text" name="settings[sms_base_url]" class="form-control"
                       value="<?= htmlspecialchars($sm('sms_base_url')) ?>" placeholder="https://XXXXX.api.infobip.com">
            </div>
        </div>

        <!-- Provider Help -->
        <div id="providerHelp" class="mt-3" style="display:none;"></div>

        <script>
        const providerHelps = {
            ssl: '<div class="alert" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;color:#166534;font-size:13px;"><strong>SSL Wireless:</strong> <a href="https://sslwireless.com" target="_blank">sslwireless.com</a> থেকে API Token ও Sender ID নিন।</div>',
            bulksmsbd: '<div class="alert" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;color:#166534;font-size:13px;"><strong>BulkSMSBD:</strong> <a href="https://bulksmsbd.net" target="_blank">bulksmsbd.net</a> থেকে API Key নিন।</div>',
            infobip: '<div class="alert" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;color:#1e40af;font-size:13px;"><strong>Infobip:</strong> Dashboard থেকে API Key ও Base URL কপি করুন।</div>',
            twilio: '<div class="alert" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;color:#1e40af;font-size:13px;"><strong>Twilio:</strong> Account SID এবং Auth Token ব্যবহার করুন।</div>',
        };
        function showProviderHelp(v) {
            const el = document.getElementById('providerHelp');
            el.innerHTML = providerHelps[v] || '';
            el.style.display = v ? 'block' : 'none';
        }
        showProviderHelp('<?= $sm('sms_provider') ?>');
        </script>

        <?php elseif ($tab === 'rules'): ?>
        <!-- ===== NOTIFICATION RULES ===== -->
        <h5 class="fw-bold mb-4" style="color:#0f2238;"><i class="bi bi-bell me-2"></i>কখন নোটিফিকেশন যাবে</h5>

        <?php
        $rules = [
            ['key' => 'email_notify_new_defaulter', 'label' => 'নতুন বকেয়া এন্ট্রি হলে', 'channel' => 'ইমেইল',
             'desc' => 'সব কোম্পানি অ্যাডমিনকে ইমেইল করা হবে'],
            ['key' => 'email_notify_approval', 'label' => 'কোম্পানি অনুমোদন হলে', 'channel' => 'ইমেইল',
             'desc' => 'কোম্পানি অ্যাডমিনকে অনুমোদনের ইমেইল'],
            ['key' => 'email_notify_resolution', 'label' => 'বকেয়া সমাধান হলে', 'channel' => 'ইমেইল',
             'desc' => 'এন্ট্রিকারী কোম্পানিকে ইমেইল'],
            ['key' => 'sms_notify_new_defaulter', 'label' => 'নতুন বকেয়া এন্ট্রি হলে', 'channel' => 'SMS',
             'desc' => 'সব কোম্পানি অ্যাডমিনকে SMS'],
            ['key' => 'sms_notify_approval', 'label' => 'কোম্পানি অনুমোদন হলে', 'channel' => 'SMS',
             'desc' => 'কোম্পানি অ্যাডমিনকে SMS'],
        ];
        foreach ($rules as $r): ?>
        <div class="d-flex align-items-center justify-content-between p-3 mb-2 rounded-3" style="background:#f8fafc;border:1px solid #e2e8f0;">
            <div>
                <div class="fw-semibold" style="font-size:14px;">
                    <span class="badge me-2" style="background:<?= str_starts_with($r['key'],'email') ? '#2563a8' : '#16a34a' ?>;font-size:10px;">
                        <?= $r['channel'] ?>
                    </span>
                    <?= $r['label'] ?>
                </div>
                <div class="text-muted small mt-1"><?= $r['desc'] ?></div>
            </div>
            <div class="form-check form-switch ms-3">
                <input class="form-check-input" type="checkbox" role="switch"
                       name="settings[<?= $r['key'] ?>]" value="1"
                       <?= $sm($r['key'], '0') === '1' ? 'checked' : '' ?>>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="mt-4">
            <label class="form-label fw-semibold">বকেয়া রিমাইন্ডার — কত দিন পরে?</label>
            <div class="d-flex align-items-center gap-2">
                <input type="number" name="settings[due_reminder_days]" class="form-control" style="width:120px;"
                       value="<?= $sm('due_reminder_days', '30') ?>" min="7" max="365">
                <span class="text-muted">দিন পরে রিমাইন্ডার পাঠানো হবে</span>
            </div>
        </div>
        <?php endif; ?>

        <div class="mt-4 pt-3 border-top">
            <button type="submit" name="settings[_save]" value="1" class="btn btn-primary px-4">
                <i class="bi bi-check-lg me-1"></i>সংরক্ষণ করুন
            </button>
        </div>
    </form>
    </div>
</div>
</div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
