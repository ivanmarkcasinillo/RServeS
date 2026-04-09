<?php
// Simple schema checker for CLI
// Manually define connection since CLI doesn't have $_SERVER

$host = 'localhost';
$user = 'root';
$pass = '';
$db   = 'rss_db';

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$tables = ['students', 'instructors', 'section_advisers', 'rss_enrollments', 'master_students', 'section_requests'];

foreach ($tables as $table) {
    echo "Table: $table\n";
    $result = $conn->query("DESCRIBE $table");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            echo "  " . $row['Field'] . " (" . $row['Type'] . ")\n";
        }
    } else {
        echo "  Table not found or error: " . $conn->error . "\n";
    }
    echo "\n";
}
?>