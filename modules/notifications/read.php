<?php
// File: modules/notifications/read.php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$user = getCurrentUser();
$id   = (int)($_GET['id'] ?? 0);

if ($id) {
    markNotificationRead($id, $user['id']);
}

// Open Redirect protection — শুধু internal path allow
$redirectParam = $_GET['redirect'] ?? '';
$default       = SITE_URL . '/modules/notifications/index.php';

if ($redirectParam) {
    $parsed   = parse_url($redirectParam);
    $siteHost = parse_url(SITE_URL, PHP_URL_HOST);
    // বাইরের domain reject করো
    if (!empty($parsed['host']) && $parsed['host'] !== $siteHost) {
        redirect($default);
    }
}

redirect($redirectParam ?: $default);
