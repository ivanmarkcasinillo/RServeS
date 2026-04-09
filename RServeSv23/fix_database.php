<?php
require 'student/dbconnect.php';

echo "<h2>Database Repair Tool</h2>";

// 1. Fix section_requests table (Add year_level)
$table = "section_requests";
$col = "year_level";
$def = "INT NOT NULL AFTER student_id";

$check = $conn->query("SHOW COLUMNS FROM $table LIKE '$col'");
if ($check->num_rows == 0) {
    $sql = "ALTER TABLE $table ADD COLUMN $col $def";
    if ($conn->query($sql) === TRUE) {
        echo "✅ Added column '$col' to '$table'.<br>";
    } else {
        echo "❌ Error adding '$col': " . $conn->error . "<br>";
    }
} else {
    echo "✅ Column '$col' already exists in '$table'.<br>";
}

// 2. Check students table for birth_date column name
$table = "students";
$col_check = $conn->query("SHOW COLUMNS FROM $table");
$has_birth_date = false;
$has_birthdate = false;
$has_year_level = false;
while ($row = $col_check->fetch_assoc()) {
    if ($row['Field'] == 'birth_date') $has_birth_date = true;
    if ($row['Field'] == 'birthdate') $has_birthdate = true;
    if ($row['Field'] == 'year_level') $has_year_level = true;
}

if ($has_birth_date) {
    echo "✅ Column 'birth_date' exists in 'students'.<br>";
} elseif ($has_birthdate) {
    echo "⚠️ Column 'birth_date' MISSING in 'students', but 'birthdate' found. <br>";
    echo "👉 You might need to update your code to use 'birthdate' instead of 'birth_date'.<br>";
} else {
    echo "❌ NEITHER 'birth_date' NOR 'birthdate' found in 'students' table!<br>";
}

if ($has_year_level) {
    echo "✅ Column 'year_level' exists in 'students'.<br>";
} else {
    echo "❌ Column 'year_level' MISSING in 'students'.<br>";
    // Attempt to add it
    $sql = "ALTER TABLE students ADD COLUMN year_level INT NOT NULL DEFAULT 1";
    if ($conn->query($sql) === TRUE) {
        echo "✅ Added column 'year_level' to 'students'.<br>";
    } else {
        echo "❌ Error adding 'year_level' to 'students': " . $conn->error . "<br>";
    }
}

// 3. Check master_students table
$check = $conn->query("SHOW TABLES LIKE 'master_students'");
if ($check->num_rows == 0) {
    // Create it
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
    if ($conn->query($sql) === TRUE) {
        echo "✅ Created table 'master_students'.<br>";
    } else {
        echo "❌ Error creating 'master_students': " . $conn->error . "<br>";
    }
} else {
    echo "✅ Table 'master_students' exists.<br>";
}

echo "<br><h3>🎉 Database check completed. Please reload your dashboard.</h3>";
echo "<a href='instructor/instructor_college_of_technology_dashboard.php'>Go to Dashboard</a>";
?>