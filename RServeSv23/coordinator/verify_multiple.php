<?php
session_start();
require "dbconnect.php";

function getBaseUrl() {
    return defined('BASE_URL') ? BASE_URL : 'https://rserves.site/';
}

header('Content-Type: application/json');

// Security Check
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Coordinator') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Get Input
$input = json_decode(file_get_contents('php://input'), true);

// Try to find IDs in common locations
$student_ids = $input['student_ids'] ?? $input['ids'] ?? $input ?? [];

if (empty($student_ids)) {
    // Log what was actually received to help us debug
    $received = file_get_contents('php://input');
    echo json_encode(['success' => false, 'message' => 'No students selected. Received: ' . $received]);
    exit;
}

if (empty($student_ids)) {
    echo json_encode(['success' => false, 'message' => 'No students selected']);
    exit;
}

$success_count = 0;
$tables = [
    'rss_waivers',
    'rss_agreements',
    'rss_enrollments'
];

foreach ($student_ids as $stud_id) {
    $stud_id = intval($stud_id);
    $student_success = true;
    
    foreach ($tables as $table) {
        // Check if record exists
        $check_sql = "SELECT student_id FROM $table WHERE student_id = $stud_id";
        $check_res = $conn->query($check_sql);
        
        if ($check_res && $check_res->num_rows > 0) {
            // Update
            $update_sql = "UPDATE $table SET status = 'Verified'";
            // Add verified_at if it's waiver (or others if they have it)
            if ($table === 'rss_waivers') {
                $update_sql .= ", verified_at = NOW()";
            }
            $update_sql .= " WHERE student_id = $stud_id";
            
            if (!$conn->query($update_sql)) {
                $student_success = false;
            }
        } else {
            // Insert
            // We need to know which columns are required.
            // All tables have: student_id, status.
            // rss_waivers: file_path
            // rss_agreements: student_signature, parent_signature (nullable based on ALTER?)
            // rss_enrollments: signature_image (nullable?)
            
            // To be safe, we insert with minimal required fields.
            // Based on CREATE TABLE in dashboard:
            // rss_waivers: file_path NOT NULL. We must provide it.
            
            if ($table === 'rss_waivers') {
                $insert_sql = "INSERT INTO $table (student_id, status, file_path, verified_at) VALUES ($stud_id, 'Verified', '', NOW())";
            } elseif ($table === 'rss_agreements') {
                // Agreement might require signatures if they are NOT NULL, but ALTER usually makes them nullable or we didn't specify NOT NULL.
                // The ALTER queries: ADD COLUMN ... VARCHAR(255). Default is NULL usually unless specified.
                $insert_sql = "INSERT INTO $table (student_id, status) VALUES ($stud_id, 'Verified')";
            } else { // rss_enrollments
                $insert_sql = "INSERT INTO $table (student_id, status) VALUES ($stud_id, 'Verified')";
            }
            
            if (!$conn->query($insert_sql)) {
                // If insertion fails (e.g. missing not null fields), we log it but continue?
                // For now, assume it works or we catch error.
                // If file_path is NOT NULL and we pass empty string, it should work.
                $student_success = false; 
            }
        }
    }
    
    if ($student_success) {
        $success_count++;
    }
}

$conn->close();

echo json_encode(['success' => true, 'count' => $success_count]);
?>
