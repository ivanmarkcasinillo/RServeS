<?php
require 'dbconnect.php';

echo "Students Columns:\n";
$res = $conn->query("SHOW COLUMNS FROM students");
while ($row = $res->fetch_assoc()) {
    echo $row['Field'] . "\n";
}

echo "\nAccomplishment Reports Sample (status='Approved'):\n";
$res = $conn->query("SELECT id, status, approver_id FROM accomplishment_reports WHERE status='Approved' LIMIT 5");
while ($row = $res->fetch_assoc()) {
    print_r($row);
}

echo "\nSection Advisers Count:\n";
$res = $conn->query("SELECT COUNT(*) FROM section_advisers");
print_r($res->fetch_row());
?>