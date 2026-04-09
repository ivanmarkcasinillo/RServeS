<?php
require 'dbconnect.php';

$table = 'students';
$column = 'student_number';

$result = $conn->query("SHOW COLUMNS FROM $table LIKE '$column'");

if ($result && $result->num_rows > 0) {
    echo "Column $column already exists in $table.\n";
} else {
    echo "Column $column does not exist. Adding it...\n";
    $sql = "ALTER TABLE $table ADD COLUMN $column VARCHAR(50) AFTER mi"; // Adding after 'mi' or wherever appropriate
    if ($conn->query($sql) === TRUE) {
        echo "Column $column added successfully.\n";
    } else {
        echo "Error adding column: " . $conn->error . "\n";
    }
}
?>
