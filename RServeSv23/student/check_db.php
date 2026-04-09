<?php
require "dbconnect.php";

echo "Checking database schema...\n";

// Check students table for department_id
$check_dept = $conn->query("SHOW COLUMNS FROM students LIKE 'department_id'");
if ($check_dept && $check_dept->num_rows > 0) {
    echo "SUCCESS: students table has department_id column.\n";
} else {
    echo "FAIL: students table MISSING department_id column.\n";
}

// Check section_advisers table
$check_advisers = $conn->query("SHOW TABLES LIKE 'section_advisers'");
if ($check_advisers && $check_advisers->num_rows > 0) {
    echo "SUCCESS: section_advisers table exists.\n";
    $count = $conn->query("SELECT COUNT(*) as c FROM section_advisers")->fetch_assoc()['c'];
    echo "section_advisers count: " . $count . "\n";
} else {
    echo "FAIL: section_advisers table MISSING.\n";
}

// Check if any students exist to test with
$check_students = $conn->query("SELECT COUNT(*) as c FROM students");
if ($check_students) {
    echo "Students count: " . $check_students->fetch_assoc()['c'] . "\n";
}

// Check if any instructors exist
$check_instructors = $conn->query("SELECT COUNT(*) as c FROM instructors"); // Assuming table is instructors
if ($check_instructors) {
    echo "Instructors count: " . $check_instructors->fetch_assoc()['c'] . "\n";
} else {
    // Maybe table name is different?
    $check_instructors = $conn->query("SELECT COUNT(*) as c FROM instructor"); 
    if ($check_instructors) {
        echo "Instructors (table: instructor) count: " . $check_instructors->fetch_assoc()['c'] . "\n";
    } else {
         echo "FAIL: Could not find instructors table.\n";
    }
}
?>
