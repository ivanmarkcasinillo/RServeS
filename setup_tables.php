<?php
require 'student/dbconnect.php';

function executeQuery($conn, $sql, $message) {
    if ($conn->query($sql) === TRUE) {
        echo "✅ $message<br>";
    } else {
        echo "❌ Error $message: " . $conn->error . "<br>";
    }
}

// 1. Create section_requests table
$sql = "CREATE TABLE IF NOT EXISTS section_requests (
    request_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    section VARCHAR(50) NOT NULL,
    adviser_id INT NULL,
    status ENUM('Pending', 'Approved', 'Declined', 'Completed') DEFAULT 'Pending',
    decline_reason TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    approved_at DATETIME NULL,
    approved_by INT NULL,
    FOREIGN KEY (student_id) REFERENCES students(stud_id) ON DELETE CASCADE
)";
executeQuery($conn, $sql, "Table section_requests created/checked");

// 2. Create master_students table
$sql = "CREATE TABLE IF NOT EXISTS master_students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id_number VARCHAR(50) UNIQUE NOT NULL,
    lastname VARCHAR(100) NOT NULL,
    firstname VARCHAR(100) NOT NULL,
    middlename VARCHAR(100) NULL,
    birthdate DATE NULL,
    course VARCHAR(100) NULL,
    year_level INT NULL,
    section VARCHAR(50) NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
executeQuery($conn, $sql, "Table master_students created/checked");

// 3. Add sync_status to rss_enrollments if not exists
// Check if column exists first
$check = $conn->query("SHOW COLUMNS FROM rss_enrollments LIKE 'sync_status'");
if ($check->num_rows == 0) {
    $sql = "ALTER TABLE rss_enrollments ADD COLUMN sync_status ENUM('Pending', 'Synced', 'Mismatch') DEFAULT 'Pending'";
    executeQuery($conn, $sql, "Column sync_status added to rss_enrollments");
} else {
    echo "ℹ️ Column sync_status already exists in rss_enrollments<br>";
}

// 4. Add master_id to rss_enrollments for linking
$check = $conn->query("SHOW COLUMNS FROM rss_enrollments LIKE 'master_id'");
if ($check->num_rows == 0) {
    $sql = "ALTER TABLE rss_enrollments ADD COLUMN master_id INT NULL";
    executeQuery($conn, $sql, "Column master_id added to rss_enrollments");
} else {
    echo "ℹ️ Column master_id already exists in rss_enrollments<br>";
}

echo "<br>🎉 Database setup completed!";
?>