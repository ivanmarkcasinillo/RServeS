<?php
require __DIR__ . "/../dbconnect.php";

echo "<h2>Waivers</h2>";
$res = $conn->query("SELECT * FROM rss_waivers");
if ($res) {
    echo "<table border='1'><tr><th>ID</th><th>Student ID</th><th>File Path</th><th>Status</th></tr>";
    while ($row = $res->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . $row['student_id'] . "</td>";
        echo "<td>" . htmlspecialchars($row['file_path']) . "</td>";
        echo "<td>" . $row['status'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "Error: " . $conn->error;
}

echo "<h2>Agreements</h2>";
$res = $conn->query("SELECT * FROM rss_agreements");
if ($res) {
    echo "<table border='1'><tr><th>ID</th><th>Student ID</th><th>File Path</th><th>Status</th></tr>";
    while ($row = $res->fetch_assoc()) {
        echo "<tr>";
        // ID column might be agreement_id or id
        $id = $row['agreement_id'] ?? $row['id'] ?? 'N/A';
        echo "<td>" . $id . "</td>";
        echo "<td>" . $row['student_id'] . "</td>";
        echo "<td>" . htmlspecialchars($row['file_path'] ?? 'NULL') . "</td>";
        echo "<td>" . $row['status'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "Error: " . $conn->error;
}
?>