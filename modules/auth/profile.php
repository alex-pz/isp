<?php
// ============================================================
// USER PROFILE
// File: modules/auth/profile.php
// ============================================================
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$user      = getCurrentUser();
$pageTitle = 'আমার প্রোফাইল';
$errors    = [];
$success   = false;
$tab       = $_GET['tab'] ?? 'info';

// ---- Update profile info ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if (!validateCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $errors[] = 'নিরাপত্তা যাচাই ব্যর্থ।';
    } elseif ($_POST['action'] === 'update_info') {

        $fullName = trim($_POST['full_name'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');

        if (empty($fullName)) $errors[] = 'নাম দিন।';
        if (!preg_match('/^01[3-9]\d{8}$/', $phone)) $errors[] = 'সঠিক মোবাইল নম্বর দিন।';

        // Avatar upload
        $avatarPath = $user['avatar'];
        if (!empty($_FILES['avatar']['name'])) {
            $upload = uploadPhoto($_FILES['avatar'], 'avatars');
            if (!$upload['success']) {
                $errors[] = $upload['error'];
            } else {
                if ($avatarPath) deleteUploadedFile($avatarPath);
                $avatarPath = $upload['path'];
            }
        }

        if (empty($errors)) {
            Database::update('users',
                ['full_name' => $fullName, 'phone' => $phone, 'avatar' => $avatarPath],
                'id = ?', [$user['id']]
            );
            logActivity('user.update_profile', 'auth', ['target_id' => $user['id'], 'target_type' => 'users']);
            setFlash('success', 'প্রোফাইল আপডেট হয়েছে।');
            redirect($_SERVER['PHP_SELF'] . '?tab=info');
        }

    } elseif ($_POST['action'] === 'change_password') {

        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!password_verify($current, $user['password']))
            $errors[] = 'বর্তমান পাসওয়ার্ড সঠিক নয়।';
        if (strlen($new) < 8) $errors[] = 'নতুন পাসওয়ার্ড কমপক্ষে ৮ অক্ষর হতে হবে।';
        if ($new !== $confirm) $errors[] = 'নিশ্চিত পাসওয়ার্ড মিলছে না।';

        if (empty($errors)) {
            Database::update('users',
                ['password' => password_hash($new, PASSWORD_BCRYPT, ['cost' => 12])],
                'id = ?', [$user['id']]
            );
            logActivity('user.change_password', 'auth', ['target_id' => $user['id'], 'target_type' => 'users']);
            setFlash('success', 'পাসওয়ার্ড পরিবর্তন হয়েছে। পুনরায় লগইন করুন।');
            redirect(SITE_URL . '/logout.php');
        }
        $tab = 'password';
    }
}

// Refresh user data
$user = Database::fetchOne(
    "SELECT u.*, r.label as role_label, c.company_name, c.area
     FROM users u JOIN roles r ON r.id = u.role_id
     LEFT JOIN companies c ON c.id = u.company_id
     WHERE u.id = ?", [$user['id']]
);

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1>আমার প্রোফাইল</h1>
    <nav><ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="<?= SITE_URL ?>/dashboard.php">হোম</a></li>
        <li class="breadcrumb-item active">প্রোফাইল</li>
    </ol></nav>
</div>

<div class="row g-3">
    <!-- Left: Profile Card -->
    <div class="col-lg-3">
        <div class="card text-center p-4">
            <div class="position-relative d-inline-block mx-auto mb-3">
                <?php if ($user['avatar']): ?>
                    <img src="<?= UPLOAD_URL . $user['avatar'] ?>" alt=""
                         style="width:90px;height:90px;border-radius:50%;object-fit:cover;border:3px solid #e2e8f0;">
                <?php else: ?>
                    <div style="width:90px;height:90px;border-radius:50%;background:#1a3a5c;color:#fff;
                                display:flex;align-items:center;justify-content:center;font-size:32px;font-weight:700;
                                margin:0 auto;border:3px solid #e2e8f0;">
                        <?= mb_substr($user['full_name'], 0, 1, 'UTF-8') ?>
                    </div>
                <?php endif; ?>
            </div>
            <h5 class="fw-bold mb-1"><?= htmlspecialchars($user['full_name']) ?></h5>
            <span class="badge bg-primary rounded-pill mb-2"><?= htmlspecialchars($user['role_label']) ?></span>
            <?php if ($user['company_name']): ?>
            <div class="text-muted small"><?= htmlspecialchars($user['company_name']) ?></div>
            <?php endif; ?>
            <hr class="my-3">
            <div class="text-start small text-muted">
                <div class="mb-1"><i class="bi bi-person me-2"></i><?= htmlspecialchars($user['username']) ?></div>
                <div class="mb-1"><i class="bi bi-envelope me-2"></i><?= htmlspecialchars($user['email']) ?></div>
                <div class="mb-1"><i class="bi bi-phone me-2"></i><?= htmlspecialchars($user['phone']) ?></div>
                <?php if ($user['last_login']): ?>
                <div class="mt-2 pt-2 border-top">
                    <i class="bi bi-clock-history me-1"></i>শেষ লগইন:<br>
                    <small><?= formatDate($user['last_login']) ?></small>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right: Edit Tabs -->
    <div class="col-lg-9">
        <div class="card">
            <div class="card-header">
                <ul class="nav nav-tabs card-header-tabs" id="profileTabs">
                    <li class="nav-item">
                        <a class="nav-link <?= $tab === 'info' ? 'active' : '' ?>"
                           href="?tab=info">
                            <i class="bi bi-person-gear me-1"></i>তথ্য সম্পাদনা
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $tab === 'password' ? 'active' : '' ?>"
                           href="?tab=password">
                            <i class="bi bi-key me-1"></i>পাসওয়ার্ড পরিবর্তন
                        </a>
                    </li>
                </ul>
            </div>
            <div class="card-body p-4">

            <?php if (!empty($errors)): ?>
            <div class="alert alert-danger rounded-3 mb-4" style="font-size:13px;">
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <!-- Info Tab -->
            <?php if ($tab === 'info'): ?>
            <form method="POST" enctype="multipart/form-data">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update_info">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">পূর্ণ নাম <span style="color:#e8392d;">*</span></label>
                        <input type="text" name="full_name" class="form-control"
                               value="<?= htmlspecialchars($user['full_name']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">মোবাইল নম্বর <span style="color:#e8392d;">*</span></label>
                        <input type="text" name="phone" class="form-control"
                               value="<?= htmlspecialchars($user['phone']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">ইমেইল</label>
                        <input type="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" disabled>
                        <div class="form-text">ইমেইল পরিবর্তন করতে অ্যাডমিনের সাথে যোগাযোগ করুন।</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">ইউজারনেম</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" disabled>
                    </div>
                    <div class="col-12">
                        <label class="form-label">প্রোফাইল ছবি</label>
                        <div class="d-flex align-items-center gap-3">
                            <div id="avatarPreview" style="width:56px;height:56px;border-radius:50%;background:#e2e8f0;
                                  overflow:hidden;display:flex;align-items:center;justify-content:center;">
                                <?php if ($user['avatar']): ?>
                                    <img src="<?= UPLOAD_URL . $user['avatar'] ?>" style="width:100%;height:100%;object-fit:cover;">
                                <?php else: ?>
                                    <i class="bi bi-person text-muted fs-4"></i>
                                <?php endif; ?>
                            </div>
                            <div>
                                <input type="file" name="avatar" id="avatarFile" accept="image/*" hidden
                                       onchange="previewAvatar(this)">
                                <button type="button" onclick="document.getElementById('avatarFile').click()"
                                        class="btn btn-sm" style="border:2px solid #e2e8f0;border-radius:8px;font-size:13px;">
                                    <i class="bi bi-upload me-1"></i>ছবি পরিবর্তন করুন
                                </button>
                                <div class="form-text">JPG, PNG — সর্বোচ্চ ৫MB</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>সংরক্ষণ করুন
                    </button>
                </div>
            </form>

            <!-- Password Tab -->
            <?php elseif ($tab === 'password'): ?>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="change_password">

                <div class="row g-3" style="max-width:440px;">
                    <div class="col-12">
                        <label class="form-label">বর্তমান পাসওয়ার্ড</label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">নতুন পাসওয়ার্ড</label>
                        <input type="password" name="new_password" class="form-control"
                               required minlength="8" placeholder="কমপক্ষে ৮ অক্ষর">
                    </div>
                    <div class="col-12">
                        <label class="form-label">নতুন পাসওয়ার্ড নিশ্চিত করুন</label>
                        <input type="password" name="confirm_password" class="form-control" required>
                    </div>
                </div>

                <div class="alert mt-3" style="background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;color:#92400e;font-size:13px;max-width:440px;">
                    <i class="bi bi-info-circle me-1"></i>
                    পাসওয়ার্ড পরিবর্তন করলে আপনাকে পুনরায় লগইন করতে হবে।
                </div>

                <div class="mt-3">
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-key me-1"></i>পাসওয়ার্ড পরিবর্তন করুন
                    </button>
                </div>
            </form>
            <?php endif; ?>

            </div>
        </div>
    </div>
</div>

<script>
function previewAvatar(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            document.getElementById('avatarPreview').innerHTML =
                `<img src="${e.target.result}" style="width:100%;height:100%;object-fit:cover;">`;
        };
        reader.readAsDataURL(input.files[0]);
    }
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
