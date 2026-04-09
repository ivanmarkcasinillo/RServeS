<?php
require "dbconnect.php";

$result = $conn->query("SHOW COLUMNS FROM tasks");
while ($row = $result->fetch_assoc()) {
    echo $row['Field'] . " - " . $row['Type'] . "\n";
}
?>