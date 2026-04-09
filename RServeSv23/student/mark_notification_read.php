<?php
session_start();
require "dbconnect.php";

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Student') {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $student_id = $_SESSION['stud_id'];
    
    // Check if it's a "Mark All" request
    if (isset($data['action']) && $data['action'] === 'mark_all') {
        
        // 1. Mark all pending tasks as read
        $stmt = $conn->prepare("
            INSERT IGNORE INTO notification_reads (student_id, notification_type, reference_id)
            SELECT ?, 'task', t.task_id
            FROM student_tasks st
            INNER JOIN tasks t ON st.task_id = t.task_id
            WHERE st.student_id = ? AND st.status != 'Completed'
        ");
        $stmt->bind_param("ii", $student_id, $student_id);
        $stmt->execute();
        $stmt->close();

        /* Org notifications removed */

        // 3. Mark all certificates as read
        $stmt = $conn->prepare("
            INSERT IGNORE INTO notification_reads (student_id, notification_type, reference_id)
            SELECT ?, 'certificate', certificate_id
            FROM student_certificates
            WHERE student_id = ?
        ");
        $stmt->bind_param("ii", $student_id, $student_id);
        $stmt->execute();
        $stmt->close();
        
        echo json_encode(['success' => true]);
        exit;
    }

    // Single item mark read
    $type = $data['type'] ?? '';
    $id = $data['id'] ?? 0;

    if (!$type || !$id) {
        http_response_code(400);
        exit(json_encode(['success' => false, 'message' => 'Invalid parameters']));
    }

    $stmt = $conn->prepare("INSERT IGNORE INTO notification_reads (student_id, notification_type, reference_id) VALUES (?, ?, ?)");
    $stmt->bind_param("isi", $student_id, $type, $id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $conn->error]);
    }
    $stmt->close();
} else {
    http_response_code(405);
}
?>