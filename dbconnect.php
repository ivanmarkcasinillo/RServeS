<?php
// dbconnect.php - Root Version
// Connects to the database using settings from config.php

// Load configuration
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
} else {
    // Fallback if config is missing (Default XAMPP)
    define('DB_HOST', 'localhost');
    define('DB_USER', 'u656702496_rserves');
    define('DB_PASS', 'RServeS_Live2026!');
    define('DB_NAME', 'u656702496_rss_db');
    define('DB_PORT', '3306');
    define('BASE_URL', 'http://localhost/' . basename(__DIR__) . '/');
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);

if ($conn->connect_error) { 
    // Log the error for admin review
    error_log("Database Connection Failed: " . $conn->connect_error);
    
    // Redirect to a friendly error page to avoid HTTP 500 White Screen
    // Ensure we don't cause a redirect loop if error.php uses dbconnect
    if (basename($_SERVER['PHP_SELF']) !== 'error.php') {
        header("Location: " . (defined('BASE_URL') ? BASE_URL : '') . "error.php");
        exit;
    } else {
        die("System currently unavailable. Please try again later.");
    }
}

$schema_bootstrap = __DIR__ . '/schema_bootstrap.php';
if (file_exists($schema_bootstrap)) {
    require_once $schema_bootstrap;
    rserves_bootstrap_schema($conn);
}
?>
