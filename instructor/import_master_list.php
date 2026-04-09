<?php
session_start();
require "dbconnect.php";

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Instructor') {
    header("Location: ../home2.php");
    exit;
}

// Check if file was uploaded
if (isset($_POST['upload_master_list']) && isset($_FILES['master_list_file'])) {
    $file = $_FILES['master_list_file'];
    
    // Check errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $msg = "File upload error: " . $file['error'];
        header("Location: instructor_college_of_technology_dashboard.php?msg=" . urlencode($msg));
        exit;
    }
    
    // Check extension
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        $msg = "Please upload a CSV file.";
        header("Location: instructor_college_of_technology_dashboard.php?msg=" . urlencode($msg));
        exit;
    }
    
    // Open file
    if (($handle = fopen($file['tmp_name'], "r")) !== FALSE) {
        // Skip header row if exists (optional, let's assume first row is header if it contains "Student ID")
        // Or just try to parse. Let's assume standard format.
        // Format: Student ID, Lastname, Firstname, Middlename, Birthdate, Course, Year, Section
        
        $row = 0;
        $imported = 0;
        $updated = 0;
        
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $row++;
            
            // Simple check to skip header
            if ($row == 1 && (strpos(strtolower($data[0]), 'id') !== false || strpos(strtolower($data[1]), 'name') !== false)) {
                continue;
            }
            
            // Map columns (adjust index as needed)
            $sid = trim($data[0] ?? '');
            $lname = trim($data[1] ?? '');
            $fname = trim($data[2] ?? '');
            $mname = trim($data[3] ?? '');
            $bdate = trim($data[4] ?? ''); // YYYY-MM-DD or DD/MM/YYYY
            $course = trim($data[5] ?? '');
            $year = intval($data[6] ?? 0);
            $section = trim($data[7] ?? '');
            
            if (empty($sid) || empty($lname) || empty($fname)) {
                continue; // Skip invalid rows
            }
            
            // Normalize Date
            $timestamp = strtotime($bdate);
            $bdate_sql = $timestamp ? date('Y-m-d', $timestamp) : NULL;
            
            // Insert or Update
            $stmt = $conn->prepare("INSERT INTO master_students (student_id_number, lastname, firstname, middlename, birthdate, course, year_level, section) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?) 
                                    ON DUPLICATE KEY UPDATE 
                                    lastname = VALUES(lastname), 
                                    firstname = VALUES(firstname), 
                                    middlename = VALUES(middlename), 
                                    birthdate = VALUES(birthdate), 
                                    course = VALUES(course), 
                                    year_level = VALUES(year_level), 
                                    section = VALUES(section)");
                                    
            $stmt->bind_param("ssssssis", $sid, $lname, $fname, $mname, $bdate_sql, $course, $year, $section);
            
            if ($stmt->execute()) {
                if ($stmt->affected_rows == 1) $imported++;
                else if ($stmt->affected_rows == 2) $updated++;
            }
            $stmt->close();
        }
        fclose($handle);
        
        // After import, run sync check for pending enrollments
        // Update rss_enrollments.sync_status
        $conn->query("UPDATE rss_enrollments e 
                      JOIN master_students m ON e.student_number = m.student_id_number
                      SET e.sync_status = 'Synced', e.master_id = m.id
                      WHERE e.sync_status != 'Synced'");
                      
        // Also check by Name + Birthdate if ID is missing/mismatch
        $conn->query("UPDATE rss_enrollments e 
                      JOIN master_students m ON e.surname = m.lastname AND e.given_name = m.firstname AND e.birth_date = m.birthdate
                      SET e.sync_status = 'Synced', e.master_id = m.id
                      WHERE e.sync_status != 'Synced'");

        $msg = "Import Success! Added: $imported, Updated: $updated.";
    } else {
        $msg = "Could not open file.";
    }
} else {
    $msg = "No file uploaded.";
}

header("Location: instructor_college_of_technology_dashboard.php?msg=" . urlencode($msg));
exit;
?>
