<?php
// ============================================================
// SYSTEM SETTINGS
// File: modules/admin/settings.php
// ============================================================
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
$user = getCurrentUser();
if ($user['role_name'] !== 'super_admin') {
    setFlash('error', 'শুধুমাত্র সুপার অ্যাডমিন সেটিংস পরিবর্তন করতে পারবেন।');
    redirect(SITE_URL . '/dashboard.php');
}

$pageTitle = 'সিস্টেম সেটিংস';
$tab       = $_GET['tab'] ?? 'general';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        setFlash('error', 'নিরাপত্তা যাচাই ব্যর্থ।'); redirect($_SERVER['PHP_SELF']);
    }
    $fields = $_POST['settings'] ?? [];
    foreach ($fields as $key => $value) {
        updateSetting($key, trim($value));
    }

    // Logo upload
    if (!empty($_FILES['site_logo']['name'])) {
        $upload = uploadPhoto($_FILES['site_logo'], 'logos');
        if ($upload['success']) {
            $oldLogo = getSetting('site_logo');
            if ($oldLogo) deleteUploadedFile($oldLogo);
            updateSetting('site_logo', $upload['path']);
        }
    }

    logActivity('settings.update', 'settings', ['description' => "$tab সেটিংস আপডেট করা হয়েছে"]);
    setFlash('success', 'সেটিংস সংরক্ষণ হয়েছে।');
    redirect($_SERVER['PHP_SELF'] . '?tab=' . $tab);
}

// Load current settings
$allSettings = Database::fetchAll("SELECT * FROM settings ORDER BY `group`, `key`");
$settingMap  = array_column($allSettings, 'value', 'key');

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1>সিস্টেম সেটিংস</h1>
    <nav><ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="<?= SITE_URL ?>/dashboard.php">হোম</a></li>
        <li class="breadcrumb-item active">সেটিংস</li>
    </ol></nav>
</div>

<div class="row g-3">
    <!-- Tabs sidebar -->
    <div class="col-lg-3">
        <div class="card">
            <div class="card-body p-2">
                <?php
                $tabs = [
                    'general'      => ['icon' => 'gear',           'label' => 'সাধারণ সেটিংস'],
                    'registration' => ['icon' => 'person-plus',    'label' => 'রেজিস্ট্রেশন'],
                    'security'     => ['icon' => 'shield-lock',    'label' => 'নিরাপত্তা'],
                    'email'        => ['icon' => 'envelope',       'label' => 'ইমেইল / SMTP'],
                    'integration'  => ['icon' => 'puzzle',         'label' => 'API ইন্টিগ্রেশন'],
                    'display'      => ['icon' => 'layout-text-sidebar', 'label' => 'প্রদর্শন'],
                    'payment'      => ['icon' => 'credit-card',        'label' => 'পেমেন্ট ট্র্যাকিং'],
                ];
                foreach ($tabs as $key => $info): ?>
                <a href="?tab=<?= $key ?>"
                   class="d-flex align-items-center gap-2 px-3 py-2 rounded-2 text-decoration-none mb-1 <?= $tab === $key ? 'text-white' : 'text-dark' ?>"
                   style="<?= $tab === $key ? 'background:#1a3a5c;' : '' ?>font-size:14px;">
                    <i class="bi bi-<?= $info['icon'] ?>"></i>
                    <?= $info['label'] ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Settings Form -->
    <div class="col-lg-9">
        <div class="card">
            <div class="card-header">
                <h6 class="card-title"><i class="bi bi-<?= $tabs[$tab]['icon'] ?? 'gear' ?> me-2"></i><?= $tabs[$tab]['label'] ?? '' ?></h6>
            </div>
            <div class="card-body p-4">
            <form method="POST" enctype="multipart/form-data">
                <?= csrfField() ?>

                <?php if ($tab === 'general'): ?>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">সাইটের নাম</label>
                        <input type="text" name="settings[site_name]" class="form-control"
                               value="<?= htmlspecialchars($settingMap['site_name'] ?? '') ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">ট্যাগলাইন</label>
                        <input type="text" name="settings[site_tagline]" class="form-control"
                               value="<?= htmlspecialchars($settingMap['site_tagline'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">যোগাযোগের ইমেইল</label>
                        <input type="email" name="settings[site_email]" class="form-control"
                               value="<?= htmlspecialchars($settingMap['site_email'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">যোগাযোগের ফোন</label>
                        <input type="text" name="settings[site_phone]" class="form-control"
                               value="<?= htmlspecialchars($settingMap['site_phone'] ?? '') ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">সাইট লোগো</label>
                        <div class="d-flex align-items-center gap-3">
                            <?php $logo = $settingMap['site_logo'] ?? ''; ?>
                            <div style="width:60px;height:60px;border-radius:10px;border:2px solid #e2e8f0;overflow:hidden;background:#f8fafc;display:flex;align-items:center;justify-content:center;">
                                <?php if ($logo): ?>
                                    <img src="<?= UPLOAD_URL . $logo ?>" style="max-width:100%;max-height:100%;object-fit:contain;">
                                <?php else: ?>
                                    <i class="bi bi-image text-muted fs-4"></i>
                                <?php endif; ?>
                            </div>
                            <input type="file" name="site_logo" class="form-control" style="max-width:300px;" accept="image/*">
                        </div>
                        <div class="form-text">PNG, JPG — সর্বোচ্চ ৫MB</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">টাইমজোন</label>
                        <select name="settings[timezone]" class="form-select">
                            <option value="Asia/Dhaka" <?= ($settingMap['timezone'] ?? '') === 'Asia/Dhaka' ? 'selected' : '' ?>>Asia/Dhaka (বাংলাদেশ)</option>
                            <option value="UTC" <?= ($settingMap['timezone'] ?? '') === 'UTC' ? 'selected' : '' ?>>UTC</option>
                        </select>
                    </div>
                </div>

                <?php elseif ($tab === 'registration'): ?>
                <div class="row g-3">
                    <div class="col-12">
                        <div class="p-3 rounded-3" style="background:#f8fafc;border:1px solid #e2e8f0;">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="fw-semibold">নতুন রেজিস্ট্রেশন চালু রাখুন</div>
                                    <div class="text-muted small">বন্ধ করলে নতুন কোম্পানি রেজিস্ট্রেশন করতে পারবে না</div>
                                </div>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           id="allowReg" onchange="document.getElementById('allowRegVal').value = this.checked ? '1' : '0'"
                                           <?= ($settingMap['allow_registration'] ?? '1') === '1' ? 'checked' : '' ?>>
                                    <input type="hidden" name="settings[allow_registration]" id="allowRegVal"
                                           value="<?= ($settingMap['allow_registration'] ?? '1') ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="p-3 rounded-3" style="background:#f8fafc;border:1px solid #e2e8f0;">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="fw-semibold">অ্যাডমিন অনুমোদন আবশ্যক</div>
                                    <div class="text-muted small">চালু থাকলে অ্যাডমিন অনুমোদন না দিলে লগইন করা যাবে না</div>
                                </div>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           id="reqApproval" onchange="document.getElementById('reqApprovalVal').value = this.checked ? '1' : '0'"
                                           <?= ($settingMap['require_approval'] ?? '1') === '1' ? 'checked' : '' ?>>
                                    <input type="hidden" name="settings[require_approval]" id="reqApprovalVal"
                                           value="<?= ($settingMap['require_approval'] ?? '1') ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php elseif ($tab === 'security'): ?>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">সর্বোচ্চ ভুল লগইন চেষ্টা</label>
                        <input type="number" name="settings[max_login_attempts]" class="form-control"
                               value="<?= (int)($settingMap['max_login_attempts'] ?? 5) ?>" min="3" max="20">
                        <div class="form-text">এতবার ভুল হলে অ্যাকাউন্ট লক হবে</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">লকআউট সময় (মিনিট)</label>
                        <input type="number" name="settings[lockout_duration]" class="form-control"
                               value="<?= (int)($settingMap['lockout_duration'] ?? 30) ?>" min="5" max="1440">
                    </div>
                </div>

                <?php elseif ($tab === 'email'): ?>
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label">SMTP Host</label>
                        <input type="text" name="settings[smtp_host]" class="form-control"
                               value="<?= htmlspecialchars($settingMap['smtp_host'] ?? '') ?>" placeholder="smtp.gmail.com">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">SMTP Port</label>
                        <input type="number" name="settings[smtp_port]" class="form-control"
                               value="<?= $settingMap['smtp_port'] ?? 587 ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">SMTP Username</label>
                        <input type="text" name="settings[smtp_user]" class="form-control"
                               value="<?= htmlspecialchars($settingMap['smtp_user'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">SMTP Password</label>
                        <input type="password" name="settings[smtp_pass]" class="form-control"
                               placeholder="পরিবর্তন না করলে ফাঁকা রাখুন">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">From Email</label>
                        <input type="email" name="settings[smtp_from]" class="form-control"
                               value="<?= htmlspecialchars($settingMap['smtp_from'] ?? '') ?>">
                    </div>
                </div>

                <?php elseif ($tab === 'integration'): ?>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Google Maps API Key</label>
                        <input type="text" name="settings[google_maps_key]" class="form-control"
                               value="<?= htmlspecialchars($settingMap['google_maps_key'] ?? '') ?>"
                               placeholder="AIzaSy...">
                        <div class="form-text">
                            <a href="https://console.cloud.google.com/apis/credentials" target="_blank">
                                Google Cloud Console <i class="bi bi-box-arrow-up-right"></i>
                            </a> থেকে API Key নিন
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="alert" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;color:#1e40af;font-size:13px;">
                            <i class="bi bi-info-circle me-2"></i>
                            Google Maps API Key ছাড়াও বকেয়া এন্ট্রি করা যাবে তবে ম্যাপ পিন ফিচার কাজ করবে না।
                        </div>
                    </div>
                </div>

                <?php elseif ($tab === 'display'): ?>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">প্রতি পেজে আইটেম</label>
                        <select name="settings[items_per_page]" class="form-select">
                            <?php foreach ([10,15,20,25,50] as $n): ?>
                            <option value="<?= $n ?>" <?= ($settingMap['items_per_page'] ?? 20) == $n ? 'selected' : '' ?>><?= $n ?> টি</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <?php elseif ($tab === 'payment'): ?>
                <div class="row g-3">
                    <div class="col-12">
                        <div class="p-3 rounded-3" style="background:#eff6ff;border:1px solid #bfdbfe;">
                            <h6 class="fw-bold mb-1" style="color:#1e40af;"><i class="bi bi-info-circle me-1"></i>পেমেন্ট ট্র্যাকিং কী?</h6>
                            <p class="mb-0" style="font-size:13px;color:#1e40af;">
                                এটি চালু থাকলে কোম্পানিগুলো বকেয়া গ্রাহকের আংশিক পেমেন্ট রেকর্ড করতে পারবে।
                                বন্ধ থাকলে পেমেন্ট বাটন ও হিস্ট্রি সব কোম্পানির জন্য লুকিয়ে যাবে।
                            </p>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">সিস্টেম-লেভেল মাস্টার সুইচ</label>
                        <div class="d-flex align-items-center gap-3 p-3 rounded-3" style="border:1px solid #e2e8f0;">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch"
                                       name="settings[payment_tracking_enabled]" value="1"
                                       id="paymentMasterSwitch"
                                       <?= ($settingMap['payment_tracking_enabled'] ?? '1') === '1' ? 'checked' : '' ?>
                                       style="width:48px;height:24px;cursor:pointer;">
                            </div>
                            <div>
                                <label for="paymentMasterSwitch" class="fw-semibold mb-0" style="cursor:pointer;">
                                    পেমেন্ট ট্র্যাকিং সিস্টেম
                                </label>
                                <div style="font-size:12px;color:#64748b;">
                                    বন্ধ করলে সব কোম্পানির পেমেন্ট ফিচার একসাথে বন্ধ হবে
                                </div>
                            </div>
                            <span id="masterSwitchBadge" class="badge rounded-pill ms-auto"
                                  style="background:<?= ($settingMap['payment_tracking_enabled'] ?? '1') === '1' ? '#16a34a' : '#94a3b8' ?>;">
                                <?= ($settingMap['payment_tracking_enabled'] ?? '1') === '1' ? 'চালু' : 'বন্ধ' ?>
                            </span>
                        </div>
                    </div>
                </div>
                <script>
                document.getElementById('paymentMasterSwitch').addEventListener('change', function() {
                    const badge = document.getElementById('masterSwitchBadge');
                    badge.textContent = this.checked ? 'চালু' : 'বন্ধ';
                    badge.style.background = this.checked ? '#16a34a' : '#94a3b8';
                });
                </script>
                <?php endif; ?>

                <div class="mt-4 pt-3 border-top">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-check-lg me-1"></i>সংরক্ষণ করুন
                    </button>
                </div>
            </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
