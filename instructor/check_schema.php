<?php
require 'dbconnect.php';
$result = $conn->query("DESCRIBE tasks");
while ($row = $result->fetch_assoc()) {
    echo $row['Field'] . " - " . $row['Type'] . "\n";
}
?>