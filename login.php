<?php
// ============================================================
// LOGIN PAGE
// File: login.php
// ============================================================
require_once __DIR__ . '/includes/functions.php';
startSession();

// Already logged in?
if (isLoggedIn()) {
    redirect(SITE_URL . '/dashboard.php');
}

$error    = '';
$username = '';
$lockMsg  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF check
    if (!validateCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $error = 'নিরাপত্তা যাচাই ব্যর্থ। পুনরায় চেষ্টা করুন।';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $error = 'ইউজারনেম ও পাসওয়ার্ড দিন।';
        } else {
            // Find user by username OR email
            $user = Database::fetchOne(
                "SELECT u.*, r.name as role_name, r.label as role_label,
                        c.status as company_status, c.company_name
                 FROM users u
                 JOIN roles r ON r.id = u.role_id
                 LEFT JOIN companies c ON c.id = u.company_id
                 WHERE (u.username = ? OR u.email = ?)",
                [$username, $username]
            );

            if (!$user) {
                $error = 'ইউজারনেম বা পাসওয়ার্ড সঠিক নয়।';
            } elseif ($user['status'] === 'suspended') {
                $error = 'এই অ্যাকাউন্টটি স্থগিত করা হয়েছে। অ্যাডমিনের সাথে যোগাযোগ করুন।';
            } elseif ($user['status'] === 'inactive') {
                $error = 'এই অ্যাকাউন্টটি নিষ্ক্রিয়।';
            } elseif (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
                $remaining = ceil((strtotime($user['locked_until']) - time()) / 60);
                $lockMsg   = "অনেক বার ভুল পাসওয়ার্ড দেওয়া হয়েছে। আরো {$remaining} মিনিট পর চেষ্টা করুন।";
            } else {
                // Verify password
                if (password_verify($password, $user['password'])) {

                    // Check company approval for company users
                    if ($user['company_id'] && $user['company_status'] !== 'approved') {
                        $statusMsg = match($user['company_status']) {
                            'pending'   => 'আপনার কোম্পানি এখনো অনুমোদন পায়নি। অ্যাডমিন অনুমোদন দিলে লগইন করতে পারবেন।',
                            'rejected'  => 'আপনার কোম্পানির রেজিস্ট্রেশন বাতিল করা হয়েছে।',
                            'suspended' => 'আপনার কোম্পানি স্থগিত করা হয়েছে।',
                            default     => 'কোম্পানির অবস্থা সঠিক নয়।',
                        };
                        $error = $statusMsg;
                    } else {
                        // SUCCESS — create session
                        session_regenerate_id(true);
                        $_SESSION['user_id']    = $user['id'];
                        $_SESSION['role']       = $user['role_name'];
                        $_SESSION['company_id'] = $user['company_id'];
                        $_SESSION['login_time'] = time();

                        // Reset login attempts & update last login
                        Database::update('users',
                            ['login_attempts' => 0, 'locked_until' => null,
                             'last_login' => date('Y-m-d H:i:s'), 'last_login_ip' => getClientIp()],
                            'id = ?', [$user['id']]
                        );

                        // Log activity
                        logActivity('user.login', 'auth', [
                            'description' => $user['full_name'] . ' লগইন করেছেন',
                            'target_id'   => $user['id'],
                            'target_type' => 'users',
                        ]);

                        // Open Redirect protection
$redirectParam = $_GET['redirect'] ?? '';
$siteHost      = parse_url(SITE_URL, PHP_URL_HOST);
$parsed        = $redirectParam ? parse_url($redirectParam) : [];
$safeRedirect  = (!empty($parsed['host']) && $parsed['host'] !== $siteHost)
    ? SITE_URL . '/dashboard.php'
    : ($redirectParam ?: SITE_URL . '/dashboard.php');
redirect($safeRedirect);
                    }
                } else {
                    // Wrong password — increment attempts
                    $attempts = (int)$user['login_attempts'] + 1;
                    $maxAttempts = (int)getSetting('max_login_attempts', '5');
                    $lockDuration = (int)getSetting('lockout_duration', '30');

                    if ($attempts >= $maxAttempts) {
                        $lockedUntil = date('Y-m-d H:i:s', time() + ($lockDuration * 60));
                        Database::update('users',
                            ['login_attempts' => $attempts, 'locked_until' => $lockedUntil],
                            'id = ?', [$user['id']]
                        );
                        $lockMsg = "অনেক বার ভুল পাসওয়ার্ড। {$lockDuration} মিনিটের জন্য অ্যাকাউন্ট লক করা হয়েছে।";
                    } else {
                        Database::update('users',
                            ['login_attempts' => $attempts],
                            'id = ?', [$user['id']]
                        );
                        $remaining = $maxAttempts - $attempts;
                        $error = "পাসওয়ার্ড সঠিক নয়। আরো {$remaining} বার ভুল হলে অ্যাকাউন্ট লক হবে।";
                    }
                }
            }
        }
    }
}

$siteName = getSetting('site_name', 'ISP Defaulter System');
$siteTagline = getSetting('site_tagline', 'এলাকার ISP গুলোর যৌথ বকেয়া ব্যবস্থাপনা');
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>লগইন — <?= htmlspecialchars($siteName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Hind Siliguri', sans-serif; }
        body {
            min-height: 100vh;
            background: linear-gradient(135deg, #0f2238 0%, #1a3a5c 50%, #2563a8 100%);
            display: flex; align-items: center; justify-content: center;
            padding: 20px;
        }
        .login-wrapper {
            display: flex; width: 100%; max-width: 960px;
            background: #fff; border-radius: 20px;
            box-shadow: 0 25px 60px rgba(0,0,0,.35);
            overflow: hidden; min-height: 560px;
        }
        /* Left panel */
        .login-left {
            flex: 1; background: linear-gradient(160deg, #0f2238, #2563a8);
            padding: 50px 40px; display: flex; flex-direction: column;
            justify-content: center; align-items: flex-start; color: #fff;
        }
        .brand-icon {
            width: 60px; height: 60px; border-radius: 14px;
            background: rgba(255,255,255,.15); backdrop-filter: blur(10px);
            display: flex; align-items: center; justify-content: center;
            font-size: 28px; font-weight: 800; margin-bottom: 28px;
            border: 2px solid rgba(255,255,255,.2);
        }
        .login-left h1 { font-size: 26px; font-weight: 700; margin-bottom: 10px; }
        .login-left p  { font-size: 15px; opacity: .75; margin-bottom: 32px; line-height: 1.6; }
        .feature-list li {
            display: flex; align-items: center; gap: 10px;
            font-size: 14px; opacity: .8; margin-bottom: 12px; list-style: none;
        }
        .feature-list li i { background: rgba(255,255,255,.15); border-radius: 6px;
                              padding: 5px; font-size: 14px; }
        /* Right panel */
        .login-right {
            width: 400px; padding: 50px 40px; display: flex;
            flex-direction: column; justify-content: center;
        }
        .login-right h2 { font-size: 22px; font-weight: 700; color: #0f2238; margin-bottom: 6px; }
        .login-right .sub { color: #64748b; font-size: 14px; margin-bottom: 28px; }
        .form-control {
            border-radius: 10px; border: 2px solid #e2e8f0;
            padding: 10px 14px; font-size: 14px; transition: border .2s;
        }
        .form-control:focus { border-color: #2563a8; box-shadow: 0 0 0 3px rgba(37,99,168,.12); }
        .input-group .form-control { border-right: none; }
        .input-group .btn-outline-secondary {
            border: 2px solid #e2e8f0; border-left: none;
            border-radius: 0 10px 10px 0; background: #f8fafc;
            color: #94a3b8;
        }
        .input-group .btn-outline-secondary:hover { background: #e2e8f0; }
        .form-label { font-size: 13px; font-weight: 600; color: #475569; }
        .btn-login {
            background: linear-gradient(135deg, #1a3a5c, #2563a8);
            border: none; border-radius: 10px; padding: 12px;
            font-size: 15px; font-weight: 600; color: #fff;
            width: 100%; transition: opacity .2s, transform .15s;
        }
        .btn-login:hover { opacity: .92; transform: translateY(-1px); color: #fff; }
        .btn-login:active { transform: translateY(0); }
        .divider { display: flex; align-items: center; gap: 12px; margin: 22px 0; }
        .divider hr { flex: 1; border-color: #e2e8f0; }
        .divider span { color: #94a3b8; font-size: 12px; }
        .btn-register {
            border: 2px solid #e2e8f0; border-radius: 10px; padding: 11px;
            font-size: 14px; font-weight: 600; color: #1a3a5c;
            width: 100%; background: #fff; transition: all .2s;
            text-decoration: none; display: block; text-align: center;
        }
        .btn-register:hover { border-color: #2563a8; background: #eff6ff; color: #1a3a5c; }
        .alert-error {
            background: #fef2f2; border: 1px solid #fecaca;
            color: #dc2626; border-radius: 10px; padding: 12px 16px;
            font-size: 13px; display: flex; align-items: flex-start; gap: 8px;
            margin-bottom: 20px;
        }
        .alert-lock {
            background: #fff7ed; border: 1px solid #fed7aa;
            color: #ea580c; border-radius: 10px; padding: 12px 16px;
            font-size: 13px; display: flex; align-items: flex-start; gap: 8px;
            margin-bottom: 20px;
        }
        @media (max-width: 767px) {
            .login-left { display: none; }
            .login-right { width: 100%; padding: 40px 28px; }
            .login-wrapper { max-width: 420px; }
        }
    </style>
</head>
<body>
<div class="login-wrapper">

    <!-- Left Info Panel -->
    <div class="login-left">
        <div class="brand-icon">ISP</div>
        <h1><?= htmlspecialchars($siteName) ?></h1>
        <p><?= htmlspecialchars($siteTagline) ?></p>
        <ul class="feature-list ps-0">
            <li><i class="bi bi-shield-check"></i> যৌথ বকেয়া গ্রাহক তালিকা</li>
            <li><i class="bi bi-geo-alt"></i> Google Maps ঠিকানা পিন</li>
            <li><i class="bi bi-building"></i> সব রেজিস্টার্ড ISP একসাথে</li>
            <li><i class="bi bi-lock"></i> নিরাপদ ও এনক্রিপ্টেড</li>
        </ul>
    </div>

    <!-- Right Login Form -->
    <div class="login-right">
        <h2>স্বাগতম!</h2>
        <p class="sub">আপনার অ্যাকাউন্টে লগইন করুন</p>

        <?php if ($lockMsg): ?>
        <div class="alert-lock"><i class="bi bi-lock-fill mt-1"></i><span><?= htmlspecialchars($lockMsg) ?></span></div>
        <?php elseif ($error): ?>
        <div class="alert-error"><i class="bi bi-exclamation-circle-fill mt-1"></i><span><?= htmlspecialchars($error) ?></span></div>
        <?php endif; ?>

        <form method="POST" action="" autocomplete="off">
            <?= csrfField() ?>

            <div class="mb-3">
                <label class="form-label">ইউজারনেম বা ইমেইল</label>
                <input type="text" name="username" class="form-control"
                       placeholder="username অথবা email@example.com"
                       value="<?= htmlspecialchars($username) ?>"
                       <?= !empty($lockMsg) ? 'disabled' : '' ?>
                       autocomplete="username" required>
            </div>

            <div class="mb-4">
                <label class="form-label">পাসওয়ার্ড</label>
                <div class="input-group">
                    <input type="password" name="password" id="passwordInput"
                           class="form-control" placeholder="••••••••"
                           <?= !empty($lockMsg) ? 'disabled' : '' ?>
                           autocomplete="current-password" required>
                    <button type="button" class="btn btn-outline-secondary"
                            onclick="togglePassword()">
                        <i class="bi bi-eye" id="eyeIcon"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn-login" <?= !empty($lockMsg) ? 'disabled' : '' ?>>
                <i class="bi bi-box-arrow-in-right me-2"></i>লগইন করুন
            </button>
        </form>

        <?php if (getSetting('allow_registration', '1') === '1'): ?>
        <div class="divider"><hr><span>নতুন ISP?</span><hr></div>
        <a href="<?= SITE_URL ?>/register.php" class="btn-register">
            <i class="bi bi-building-add me-1"></i> কোম্পানি রেজিস্ট্রেশন করুন
        </a>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePassword() {
    const inp = document.getElementById('passwordInput');
    const ico = document.getElementById('eyeIcon');
    if (inp.type === 'password') {
        inp.type = 'text';
        ico.className = 'bi bi-eye-slash';
    } else {
        inp.type = 'password';
        ico.className = 'bi bi-eye';
    }
}
</script>
</body>
</html>
