<?php
require 'student/dbconnect.php';

$result = $conn->query("SHOW COLUMNS FROM accomplishment_reports");
while ($row = $result->fetch_assoc()) {
    echo $row['Field'] . "\n";
}
?>
