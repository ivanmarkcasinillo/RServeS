<?php
require 'dbconnect.php';

function inspectTable($conn, $table) {
    echo "Table: $table\n";
    $result = $conn->query("SHOW COLUMNS FROM $table");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            echo "  " . $row['Field'] . " (" . $row['Type'] . ")\n";
        }
    } else {
        echo "  Error: " . $conn->error . "\n";
    }
    echo "\n";
}

inspectTable($conn, 'accomplishment_reports');
inspectTable($conn, 'instructors');
inspectTable($conn, 'section_advisers');
?>
