<?php
require_once __DIR__ . '/includes/functions.php';
startSession();
if (isLoggedIn()) {
    redirect(SITE_URL . '/dashboard.php');
} else {
    redirect(SITE_URL . '/login.php');
}
