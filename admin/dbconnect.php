<?php
// dbconnect.php - Admin Version
// Connects to the database using settings from ../config.php

// Load configuration
if (file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
} else {
    // Fallback defaults
    define('DB_HOST', 'localhost');
    define('DB_USER', 'RServeS');
    define('DB_PASS', 'RServeS_2026');
    define('DB_NAME', 'rss_db');
    define('DB_PORT', '3306');
    define('BASE_URL', 'http://localhost/' . basename(dirname(__DIR__)) . '/');
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);

if ($conn->connect_error) { 
    error_log("Database Connection Failed: " . $conn->connect_error);
    
    // Redirect to root error.php
    if (defined('BASE_URL')) {
        header("Location: " . BASE_URL . "error.php");
    } else {
        header("Location: ../error.php");
    }
    exit;
}
?>
