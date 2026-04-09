<?php
require "dbconnect.php";

// Check if 'duration' column exists
$check = $conn->query("SHOW COLUMNS FROM tasks LIKE 'duration'");
if ($check->num_rows == 0) {
    // Add the column
    $sql = "ALTER TABLE tasks ADD COLUMN duration VARCHAR(50) NULL AFTER description";
    if ($conn->query($sql)) {
        echo "✅ Column 'duration' added successfully to 'tasks' table.";
    } else {
        echo "❌ Error adding column: " . $conn->error;
    }
} else {
    echo "ℹ️ Column 'duration' already exists.";
}
?>