<?php
// debug_hostinger.php
// Run this file to check for errors on Hostinger

// 1. Enable Error Reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h1>Debug Hostinger Compatibility</h1>";
echo "<p>PHP Version: " . phpversion() . "</p>";

// 2. Check File Paths
echo "<h2>1. Checking File Paths</h2>";
$files = [
    'config.php',
    'dbconnect.php',
    'home2.php'
];

foreach ($files as $file) {
    if (file_exists(__DIR__ . '/' . $file)) {
        echo "<p>✅ Found: $file</p>";
    } else {
        echo "<p style='color:red;'>❌ Missing: $file</p>";
    }
}

// 3. Test Database Connection
echo "<h2>2. Testing Database Connection</h2>";

if (file_exists(__DIR__ . '/config.php')) {
    echo "<p>Loading config.php...</p>";
    include __DIR__ . '/config.php';
    
    echo "<p><strong>Detected Environment:</strong> " . ($server_name == 'localhost' || $server_name == '127.0.0.1' ? 'Localhost' : 'Production (Hostinger)') . "</p>";
    
    echo "<p><strong>DB Host:</strong> " . DB_HOST . "</p>";
    echo "<p><strong>DB User:</strong> " . DB_USER . "</p>";
    echo "<p><strong>DB Name:</strong> " . DB_NAME . "</p>";
    
    echo "<p>Attempting connection...</p>";
    
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        
        if ($conn->connect_error) {
            echo "<p style='color:red;'>❌ Connection Failed: " . $conn->connect_error . "</p>";
            echo "<p><strong>Hint:</strong> Have you updated <code>config.php</code> with your Hostinger database credentials?</p>";
        } else {
            echo "<p style='color:green;'>✅ Database Connected Successfully!</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color:red;'>❌ Exception: " . $e->getMessage() . "</p>";
    }
    
} else {
    echo "<p style='color:red;'>❌ config.php not found, cannot test DB.</p>";
}

// 4. Check for Syntax Errors in home2.php (Basic check)
echo "<h2>3. Checking home2.php Inclusion</h2>";
try {
    // We can't really include home2.php because it has session_start() and HTML, 
    // but we can check if it throws a parse error by doing a lint check if we had shell access.
    // Since we don't, we'll just say:
    echo "<p>If you see this page, PHP is working. If home2.php gives Error 500, it is likely the DB connection above.</p>";
} catch (Throwable $t) {
    echo "<p style='color:red;'>❌ Error: " . $t->getMessage() . "</p>";
}

echo "<hr>";
echo "<p><em>Delete this file after debugging!</em></p>";
?>
