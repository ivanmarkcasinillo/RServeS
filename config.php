<?php
// config.php
// Central Configuration File
// This file automatically detects if running on Localhost (XAMPP) or Production (Hostinger)
$server_name = $_SERVER['HTTP_HOST'] ?? 'localhost';

function rserves_detect_base_path(string $app_root): string
{
    $document_root = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    $app_root_real = realpath($app_root);

    if (!$document_root || !$app_root_real) {
        return '/';
    }

    $document_root = str_replace('\\', '/', rtrim($document_root, '\\/'));
    $app_root_real = str_replace('\\', '/', rtrim($app_root_real, '\\/'));

    if (stripos($app_root_real, $document_root) !== 0) {
        return '/';
    }

    $relative_path = trim(substr($app_root_real, strlen($document_root)), '/');

    return $relative_path === '' ? '/' : '/' . $relative_path . '/';
}

$is_local = strpos($server_name, 'localhost') !== false || strpos($server_name, '127.0.0.1') !== false;
$is_https = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'https')
    || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
);
$base_path = rserves_detect_base_path(__DIR__);
$base_url = ($is_https ? 'https' : 'http') . '://' . $server_name . $base_path;

// Check if running locally
if ($is_local) {
    // === LOCAL ENVIRONMENT (XAMPP) ===
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_NAME', 'rss_db');
    define('DB_PORT', '3306');

    // Error Reporting (Show errors locally)
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

} else {
    // === PRODUCTION ENVIRONMENT (HOSTINGER) ===
    // UPDATE THESE CREDENTIALS AFTER DEPLOYING
    define('DB_HOST', 'localhost'); // Hostinger databases are usually on localhost
    define('DB_USER', 'u656702496_rserves'); // Assumed based on DB_NAME. Check your Hostinger DB User!
    define('DB_PASS', 'RServeS_Live2026!'); // Updated from your dbconnect.php attempt
    define('DB_NAME', 'u656702496_rss_db'); // Updated from your dbconnect.php attempt
    define('DB_PORT', '3306');

    // Error Reporting (Temporarily enabled for debugging)
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

define('BASE_PATH', $base_path);
define('BASE_URL', $base_url);

// === EMAIL CONFIGURATION ===
// These settings work for both Local and Hostinger (if using Gmail SMTP)
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_USER', 'giovanniberdon@gmail.com'); // Admin Email
define('SMTP_PASS', 'hdum cski acvf iovv'); // App Password (NOT login password)
define('SMTP_PORT', 587);
define('SMTP_FROM_EMAIL', 'no-reply@rserve.com');
define('SMTP_FROM_NAME', 'RServe Notification');

?>
