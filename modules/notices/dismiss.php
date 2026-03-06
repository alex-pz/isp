<?php
// B-4.5: Notice Dismiss AJAX endpoint
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$user     = getCurrentUser();
$noticeId = (int)($_POST['notice_id'] ?? 0);

if (!validateCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
    jsonResponse(['ok' => false], 403);
}

if ($noticeId && $user) {
    try {
        Database::query(
            "INSERT IGNORE INTO notice_dismissals (notice_id, user_id) VALUES (?, ?)",
            [$noticeId, $user['id']]
        );
        jsonResponse(['ok' => true]);
    } catch (Exception $e) {
        jsonResponse(['ok' => false]);
    }
}
jsonResponse(['ok' => false]);
