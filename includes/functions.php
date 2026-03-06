<?php
// ============================================================
// CORE HELPER FUNCTIONS
// File: includes/functions.php
// ============================================================

require_once __DIR__ . '/database.php';

// ============================================================
// SESSION MANAGEMENT
// ============================================================

function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        // Security hardening for sessions
        ini_set('session.use_only_cookies', 1);
        ini_set('session.use_strict_mode', 1);
        
        session_name(SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function isLoggedIn(): bool {
    startSession();
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function getCurrentUser(bool $refresh = false): ?array {
    if (!isLoggedIn()) return null;
    static $user = null;
    
    // $refresh true হলে ডাটাবেস থেকে লেটেস্ট ডাটা আনবে
    if ($user === null || $refresh) {
        $user = Database::fetchOne(
            "SELECT u.*, r.name as role_name, r.label as role_label,
                    c.company_name, c.status as company_status
             FROM users u
             JOIN roles r ON r.id = u.role_id
             LEFT JOIN companies c ON c.id = u.company_id
             WHERE u.id = ? AND u.status = 'active'",
            [$_SESSION['user_id']]
        );
    }
    return $user;
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        redirect(SITE_URL . '/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    }
}

function requirePermission(string $module, string $action): void {
    requireLogin();
    if (!hasPermission($module, $action)) {
        http_response_code(403);
        include SITE_ROOT . '/error/403.php';
        exit;
    }
}

// ============================================================
// PERMISSION SYSTEM
// ============================================================

function isSuperAdmin(): bool {
    $user = getCurrentUser();
    return $user && $user['role_name'] === 'super_admin';
}

function hasPermission(string $module, string $action): bool {
    $user = getCurrentUser();
    if (!$user) return false;

    // Super admin has all permissions
    if ($user['role_name'] === 'super_admin') return true;

    // Check if company is approved (for company users)
    if ($user['company_id'] && $user['company_status'] !== 'approved') return false;

    // Check custom permissions in JSON
    if (!empty($user['custom_perms'])) {
        $custom = json_decode($user['custom_perms'], true);
        $key = "$module.$action";
        if (isset($custom['grant'][$key])) return true;
        if (isset($custom['revoke'][$key])) return false;
    }

    // Check role permissions
    $perm = Database::fetchOne(
        "SELECT rp.permission_id FROM role_permissions rp
         JOIN permissions p ON p.id = rp.permission_id
         WHERE rp.role_id = ? AND p.module = ? AND p.action = ?",
        [$user['role_id'], $module, $action]
    );

    return $perm !== null;
}

function getUserPermissions(int $userId): array {
    $user = Database::fetchOne(
        "SELECT u.role_id, u.custom_perms FROM users u WHERE u.id = ?",
        [$userId]
    );
    if (!$user) return [];

    $perms = Database::fetchAll(
        "SELECT p.module, p.action, p.label FROM role_permissions rp
         JOIN permissions p ON p.id = rp.permission_id
         WHERE rp.role_id = ?",
        [$user['role_id']]
    );

    if (!empty($user['custom_perms'])) {
        $custom = json_decode($user['custom_perms'], true);
        // Apply grants
        foreach ($custom['grant'] ?? [] as $key => $_) {
            [$mod, $act] = explode('.', $key, 2);
            $perm = Database::fetchOne(
                "SELECT module, action, label FROM permissions WHERE module = ? AND action = ?",
                [$mod, $act]
            );
            if ($perm && !in_array($perm, $perms)) {
                $perms[] = $perm;
            }
        }
        // Apply revokes
        foreach ($custom['revoke'] ?? [] as $key => $_) {
            [$mod, $act] = explode('.', $key, 2);
            $perms = array_filter($perms, fn($p) => !($p['module'] === $mod && $p['action'] === $act));
        }
    }

    return array_values($perms);
}

// ============================================================
// CSRF PROTECTION
// ============================================================

function generateCsrfToken(): string {
    startSession();
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

function validateCsrfToken(string $token): bool {
    startSession();
    return isset($_SESSION[CSRF_TOKEN_NAME]) &&
           hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
}

function csrfField(): string {
    return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . generateCsrfToken() . '">';
}

// ============================================================
// FILE UPLOAD & MANAGEMENT
// ============================================================

function uploadPhoto(array $file, string $subfolder = 'photos'): array {
    $result = ['success' => false, 'path' => '', 'error' => ''];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $result['error'] = 'ফাইল আপলোড সমস্যা হয়েছে।';
        return $result;
    }

    if ($file['size'] > PHOTO_MAX_SIZE) {
        $result['error'] = 'ফাইলের সাইজ ৫MB এর বেশি হবে না।';
        return $result;
    }

    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);

    if (!in_array($mimeType, ALLOWED_PHOTO_TYPES)) {
        $result['error'] = 'শুধু JPG, PNG, WEBP ফরম্যাট গ্রহণযোগ্য।';
        return $result;
    }

    $ext = match($mimeType) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        default      => 'jpg'
    };

    $dir          = UPLOAD_PATH . $subfolder . '/' . date('Y/m');
    $filename     = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $fullPath     = $dir . '/' . $filename;
    $relativePath = $subfolder . '/' . date('Y/m') . '/' . $filename;

    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        $result['error'] = 'ফোল্ডার তৈরি করা যায়নি।';
        return $result;
    }

    if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
        $result['error'] = 'ফাইল সংরক্ষণ করা যায়নি।';
        return $result;
    }

    $result['success'] = true;
    $result['path']    = $relativePath;
    return $result;
}

function deleteUploadedFile(string $relativePath): void {
    if (empty($relativePath)) return;
    
    // Path Traversal protection
    $fullPath = realpath(UPLOAD_PATH . ltrim($relativePath, '/'));
    $uploadBase = realpath(UPLOAD_PATH);
    
    // UPLOAD_PATH এর বাইরে যাওয়া ব্লক করো এবং ফাইল থাকলে ডিলিট করো
    if ($fullPath && $uploadBase && str_starts_with($fullPath, $uploadBase)) {
        if (file_exists($fullPath)) {
            @unlink($fullPath);
        }
    }
}

// ============================================================
// ACTIVITY LOGGING
// ============================================================

function logActivity(string $action, string $module, array $options = []): void {
    $user = getCurrentUser();
    try {
        Database::insert('activity_logs', [
            'user_id'     => $user['id'] ?? null,
            'company_id'  => $user['company_id'] ?? null,
            'action'      => $action,
            'module'      => $module,
            'target_id'   => $options['target_id']   ?? null,
            'target_type' => $options['target_type'] ?? null,
            'description' => $options['description'] ?? null,
            'old_data'    => isset($options['old_data']) ? json_encode($options['old_data'], JSON_UNESCAPED_UNICODE) : null,
            'new_data'    => isset($options['new_data']) ? json_encode($options['new_data'], JSON_UNESCAPED_UNICODE) : null,
            'ip_address'  => getClientIp(),
            'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    } catch (Exception $e) {
        // Logging should never break the app
        error_log("Activity Log Error: " . $e->getMessage());
    }
}

// ============================================================
// NOTIFICATIONS (Fixed Logic)
// ============================================================

function createNotification(string $title, string $message, array $options = []): void {
    Database::insert('notifications', [
        'user_id'    => $options['user_id']    ?? null,
        'company_id' => $options['company_id'] ?? null,
        'title'      => $title,
        'message'    => $message,
        'type'       => $options['type']  ?? 'info',
        'icon'       => $options['icon']  ?? null,
        'link'       => $options['link']  ?? null,
    ]);
}

function getUnreadNotifications(int $userId, int $companyId = 0): array {
    $params = [$userId];
    $companyClause = '';
    
    // সংশোধিত লজিক: ইউজার আইডি বা কোম্পানির জন্য নির্দিষ্ট নোটিফিকেশন আনা
    if ($companyId > 0) {
        $companyClause = ' OR (company_id = ? AND user_id IS NULL)';
        $params[] = $companyId;
    }

    return Database::fetchAll(
        "SELECT * FROM notifications
         WHERE is_read = 0
           AND (user_id = ? OR (user_id IS NULL AND company_id IS NULL) $companyClause)
         ORDER BY created_at DESC LIMIT 20",
        $params
    );
}

function markNotificationRead(int $notifId, int $userId): void {
    Database::update(
        'notifications',
        ['is_read' => 1, 'read_at' => date('Y-m-d H:i:s')],
        'id = ? AND (user_id = ? OR user_id IS NULL)',
        [$notifId, $userId]
    );
}

// ============================================================
// SETTINGS
// ============================================================

function getSetting(string $key, string $default = ''): string {
    static $cache = [];
    if (!isset($cache[$key])) {
        $row = Database::fetchOne("SELECT `value` FROM settings WHERE `key` = ?", [$key]);
        $cache[$key] = $row ? (string)$row['value'] : $default;
    }
    return $cache[$key];
}

function updateSetting(string $key, string $value): void {
    Database::query(
        "INSERT INTO settings (`key`, `value`) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$key, $value]
    );
}

// ============================================================
// UTILITY FUNCTIONS
// ============================================================

function redirect(string $url): void {
    $parsed = parse_url($url);
    if (!empty($parsed['host'])) {
        $siteHost = parse_url(SITE_URL, PHP_URL_HOST);
        if ($parsed['host'] !== $siteHost) {
            header('Location: ' . SITE_URL . '/dashboard.php');
            exit;
        }
    }
    header('Location: ' . $url);
    exit;
}

function sanitize(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function getClientIp(): string {
    $keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

function formatMoney(float|null $amount): string {
    $amount = $amount ?? 0.0;
    return '৳' . number_format($amount, 2);
}

function formatDate(string $date, string $format = 'd M Y, h:i A'): string {
    return $date ? date($format, strtotime($date)) : '-';
}

function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)      return $diff . ' সেকেন্ড আগে';
    if ($diff < 3600)    return floor($diff / 60) . ' মিনিট আগে';
    if ($diff < 86400)   return floor($diff / 3600) . ' ঘন্টা আগে';
    if ($diff < 604800)  return floor($diff / 86400) . ' দিন আগে';
    return formatDate($datetime, 'd M Y');
}

function paginate(int $total, int $perPage, int $page, string $baseUrl): array {
    $totalPages = (int) ceil($total / $perPage);
    $page       = max(1, min($page, $totalPages));
    $offset     = ($page - 1) * $perPage;
    return [
        'total'       => $total,
        'per_page'    => $perPage,
        'current'     => $page,
        'total_pages' => $totalPages,
        'offset'      => $offset,
        'has_prev'    => $page > 1,
        'has_next'    => $page < $totalPages,
        'base_url'    => $baseUrl,
    ];
}

function getBadgeClass(string $status): string {
    return match($status) {
        'active'    => 'danger',
        'resolved'  => 'success',
        'disputed'  => 'warning',
        'pending'   => 'warning',
        'approved'  => 'success',
        'suspended' => 'secondary',
        'rejected'  => 'danger',
        'critical'  => 'danger',
        'high'      => 'warning',
        'medium'    => 'info',
        'low'       => 'success',
        default     => 'secondary',
    };
}

function getStatusLabel(string $status): string {
    return match($status) {
        'active'          => 'সক্রিয়',
        'resolved'        => 'সমাধান হয়েছে',
        'disputed'        => 'বিরোধ আছে',
        'removed'         => 'মুছে ফেলা হয়েছে',
        'pending'         => 'অনুমোদন বাকি',
        'approved'        => 'অনুমোদিত',
        'suspended'       => 'স্থগিত',
        'rejected'        => 'বাতিল',
        'due_payment'     => 'বকেয়া বিল',
        'fraud'           => 'প্রতারণা',
        'equipment_theft' => 'সরঞ্জাম চুরি',
        'contract_breach' => 'চুক্তি ভঙ্গ',
        'critical'        => 'অতি ঝুঁকিপূর্ণ',
        'high'            => 'উচ্চ ঝুঁকি',
        'medium'          => 'মধ্যম ঝুঁকি',
        'low'             => 'কম ঝুঁকি',
        default           => $status,
    };
}

function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// RATE LIMITING
// ============================================================
function checkRateLimit(string $key, int $limit = 30, int $windowSeconds = 60): void {
    startSession();
    $sessionKey = 'rl_' . $key;
    $now        = time();

    if (!isset($_SESSION[$sessionKey])) {
        $_SESSION[$sessionKey] = ['count' => 0, 'window_start' => $now];
    }

    $rl = &$_SESSION[$sessionKey];

    if ($now - $rl['window_start'] >= $windowSeconds) {
        $rl = ['count' => 0, 'window_start' => $now];
    }

    $rl['count']++;

    if ($rl['count'] > $limit) {
        $retryAfter = $windowSeconds - ($now - $rl['window_start']);
        header('Retry-After: ' . $retryAfter);
        jsonResponse([
            'error'       => 'অনেক বেশি অনুরোধ। কিছুক্ষণ পর আবার চেষ্টা করুন।',
            'retry_after' => $retryAfter,
        ], 429);
    }
}

// FLASH MESSAGES
function setFlash(string $type, string $message): void {
    startSession();
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    startSession();
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}
