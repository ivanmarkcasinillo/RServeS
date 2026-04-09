<?php
session_start();
require "dbconnect.php";

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Coordinator') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['student_id']) || !isset($input['doc_type']) || !isset($input['action'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

$stud_id = intval($input['student_id']);
$doc_type = $input['doc_type'];
$action = $input['action']; // 'verify' or 'reject'
$status = ($action === 'verify') ? 'Verified' : 'Rejected';

// Get coordinator ID for audit
// Assuming session has coor_id, if not fetch from email
$verifier_id = $_SESSION['coor_id'] ?? null;
if (!$verifier_id && isset($_SESSION['email'])) {
    $e = $_SESSION['email'];
    $q = $conn->query("SELECT coor_id FROM coordinator WHERE email = '$e'");
    if ($q && $q->num_rows > 0) {
        $verifier_id = $q->fetch_assoc()['coor_id'];
    }
}

$table = '';
switch($doc_type) {
    case 'waiver': $table = 'rss_waivers'; break;
    case 'agreement': $table = 'rss_agreements'; break;
    case 'enrollment': $table = 'rss_enrollments'; break;
    default: 
        echo json_encode(['success' => false, 'message' => 'Unknown document type']);
        exit;
}

// Update status
// We try to update verified_at/by if columns exist.
// Since we ensured they exist in dashboard, we can try.
// But safely, we can check if it fails or just try.
// Better: Construct query.

$sql = "UPDATE $table SET status = ?, verified_at = NOW(), verified_by = ? WHERE student_id = ?";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("sii", $status, $verifier_id, $stud_id);
} else {
    // Fallback if columns missing (shouldn't happen due to dashboard auto-alter)
    $sql = "UPDATE $table SET status = ? WHERE student_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $status, $stud_id);
}

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
}

$stmt->close();
$conn->close();
?>