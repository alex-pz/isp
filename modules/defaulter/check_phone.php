<?php
// File: modules/defaulter/check_phone.php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
$phone = trim($_GET['phone'] ?? '');
$count = Database::count('defaulters', "customer_phone = ? AND status = 'active'", [$phone]);
jsonResponse(['found' => $count > 0, 'count' => $count]);
