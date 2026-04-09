<?php
// config.php
// Central Configuration File
// This file automatically detects if running on Localhost (XAMPP) or Production (Hostinger)
$server_name = $_SERVER['HTTP_HOST']; // More reliable than SERVER_NAME

// Check if running locally
if (strpos($server_name, 'localhost') !== false || strpos($server_name, '127.0.0.1') !== false) {
    // === LOCAL ENVIRONMENT (XAMPP) ===
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_NAME', 'rss_db');
    define('DB_PORT', '3306');
    
    // Base URL (Adjust folder name if different)
    define('BASE_URL', 'http://localhost/RServeSv23/');
    
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
    
    // Base URL
    define('BASE_URL', 'https://' . $_SERVER['HTTP_HOST'] . '/');
    
    // Error Reporting (Temporarily enabled for debugging)
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

// === EMAIL CONFIGURATION ===
// These settings work for both Local and Hostinger (if using Gmail SMTP)
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_USER', 'giovanniberdon@gmail.com'); // Admin Email
define('SMTP_PASS', 'hdum cski acvf iovv'); // App Password (NOT login password)
define('SMTP_PORT', 587);
define('SMTP_FROM_EMAIL', 'no-reply@rserve.com');
define('SMTP_FROM_NAME', 'RServe Notification');

?>
