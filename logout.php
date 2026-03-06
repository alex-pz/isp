<?php
// ============================================================
// LOGOUT
// File: logout.php
// ============================================================
require_once __DIR__ . '/includes/functions.php';
startSession();

if (isLoggedIn()) {
    $user = getCurrentUser();
    logActivity('user.logout', 'auth', [
        'description' => ($user['full_name'] ?? 'ব্যবহারকারী') . ' লগআউট করেছেন',
        'target_id'   => $user['id'] ?? null,
        'target_type' => 'users',
    ]);
}

// Destroy session completely
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}
session_destroy();

redirect(SITE_URL . '/login.php');
