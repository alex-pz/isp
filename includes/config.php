<?php
// ============================================================
// DATABASE CONFIGURATION
// File: includes/config.php
// ⚠️  শেয়ার্ড হোস্টিংয়ে এই ফাইলটি web root এর বাইরে রাখুন
//     অথবা .htaccess দিয়ে direct access বন্ধ করুন
// ============================================================

// --- এখানে আপনার হোস্টিং এর তথ্য দিন ---
define('DB_HOST',     'localhost');
define('DB_NAME',     'dbname');     // আপনার database নাম
define('DB_USER',     'dbuser');              // আপনার database user
define('DB_PASS',     'dbpass');                  // আপনার database password
define('DB_CHARSET',  'utf8mb4');

// --- সাইটের URL (trailing slash ছাড়া) ---
define('SITE_URL',    'http://kib.ksbnet.net');  // আপনার domain
define('SITE_ROOT',   __DIR__ . '/..');

// --- ফোল্ডার পাথ ---
define('UPLOAD_PATH', SITE_ROOT . '/uploads/');
define('UPLOAD_URL',  SITE_URL  . '/uploads/');
define('PHOTO_MAX_SIZE', 5 * 1024 * 1024);  // 5MB
define('ALLOWED_PHOTO_TYPES', ['image/jpeg', 'image/png', 'image/webp']);

// --- সিকিউরিটি ---
// ⚠️ প্রোডাকশনে এটা অবশ্যই বদলান!
// php -r "echo bin2hex(random_bytes(32));" কমান্ড দিয়ে তৈরি করুন
define('SECRET_KEY',  'change-this-to-a-random-64-char-string-in-production!');

// Secret key weak হলে warning (dev mode এ)
if (defined('APP_ENV') && APP_ENV === 'development' &&
    SECRET_KEY === 'change-this-to-a-random-64-char-string-in-production!') {
    trigger_error('⚠️ SECRET_KEY পরিবর্তন করুন!', E_USER_WARNING);
}
define('SESSION_NAME', 'ISP_SESS');
define('CSRF_TOKEN_NAME', '_csrf_token');

// --- টাইমজোন ---
date_default_timezone_set('Asia/Dhaka');

// --- এরর হ্যান্ডলিং (production এ false করুন) ---
define('DEBUG_MODE', true);

if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}
