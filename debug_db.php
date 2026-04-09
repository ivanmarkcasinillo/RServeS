<?php
require 'student/dbconnect.php';

echo "<h2>Database Diagnostic</h2>";

// 1. Check if section_requests exists
$check = $conn->query("SHOW TABLES LIKE 'section_requests'");
if ($check->num_rows == 0) {
    echo "❌ Table 'section_requests' does NOT exist.<br>";
} else {
    echo "✅ Table 'section_requests' exists.<br>";
    
    // 2. Check columns in section_requests
    $cols = $conn->query("SHOW COLUMNS FROM section_requests");
    $found_year_level = false;
    echo "<ul>";
    while ($row = $cols->fetch_assoc()) {
        echo "<li>" . $row['Field'] . " (" . $row['Type'] . ")</li>";
        if ($row['Field'] == 'year_level') $found_year_level = true;
    }
    echo "</ul>";
    
    if (!$found_year_level) {
        echo "❌ Column 'year_level' is MISSING in 'section_requests'.<br>";
        
        // Attempt to fix it automatically
        echo "🔧 Attempting to add 'year_level' column...<br>";
        $sql = "ALTER TABLE section_requests ADD COLUMN year_level INT NOT NULL AFTER student_id";
        if ($conn->query($sql) === TRUE) {
            echo "✅ Successfully added 'year_level' column!<br>";
        } else {
            echo "❌ Failed to add column: " . $conn->error . "<br>";
        }
    } else {
        echo "✅ Column 'year_level' is present.<br>";
    }
}

// 3. Check master_students
$check = $conn->query("SHOW TABLES LIKE 'master_students'");
if ($check->num_rows == 0) {
    echo "❌ Table 'master_students' does NOT exist.<br>";
} else {
    echo "✅ Table 'master_students' exists.<br>";
}

// 4. Test the problematic query
$inst_id = 1; // Dummy ID
$req_sql = "
    SELECT sr.request_id, sr.student_id, sr.year_level, sr.section, sr.created_at,
           s.firstname, s.lastname, s.mi, s.student_number, s.photo,
           m.id as master_match_id, m.section as master_section, m.year_level as master_year
    FROM section_requests sr
    JOIN students s ON sr.student_id = s.stud_id
    LEFT JOIN rss_enrollments e ON s.stud_id = e.student_id
    LEFT JOIN master_students m ON (
        (m.student_id_number = s.student_number AND s.student_number IS NOT NULL AND s.student_number != '') OR 
        (m.lastname = s.lastname AND m.firstname = s.firstname AND m.birthdate = e.birth_date)
    )
    WHERE sr.adviser_id = ? AND sr.status = 'Pending'
    ORDER BY sr.created_at ASC
";

echo "<h3>Testing Query...</h3>";
if ($stmt = $conn->prepare($req_sql)) {
    echo "✅ Query prepared successfully!<br>";
    $stmt->close();
} else {
    echo "❌ Query preparation FAILED: " . $conn->error . "<br>";
}
?>