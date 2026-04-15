<?php
session_start();
require "dbconnect.php";

header('Content-Type: application/json');

function respond_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Student') {
    respond_json(['success' => false, 'message' => 'Unauthorized'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(['success' => false, 'message' => 'Method not allowed'], 405);
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = [];
}

$student_id = intval($_SESSION['stud_id'] ?? 0);

// Check if it's a "Mark All" request
if (($data['action'] ?? '') === 'mark_all') {
    // Mark all task notifications as read for this student.
    $stmt = $conn->prepare("
        INSERT IGNORE INTO notification_reads (student_id, notification_type, reference_id)
        SELECT ?, 'task', t.task_id
        FROM student_tasks st
        INNER JOIN tasks t ON st.task_id = t.task_id
        WHERE st.student_id = ?
    ");

    if (!$stmt) {
        respond_json(['success' => false, 'error' => $conn->error], 500);
    }

    $stmt->bind_param("ii", $student_id, $student_id);
    $stmt->execute();
    $stmt->close();

    /* Org notifications removed */

    $stmt = $conn->prepare("
        INSERT IGNORE INTO notification_reads (student_id, notification_type, reference_id)
        SELECT ?, 'certificate', certificate_id
        FROM student_certificates
        WHERE student_id = ?
    ");

    if (!$stmt) {
        respond_json(['success' => false, 'error' => $conn->error], 500);
    }

    $stmt->bind_param("ii", $student_id, $student_id);
    $stmt->execute();
    $stmt->close();

    respond_json(['success' => true]);
}

$type = trim((string) ($data['type'] ?? ''));
$id = intval($data['id'] ?? 0);
$allowed_types = ['task', 'certificate'];

if (!in_array($type, $allowed_types, true) || $id <= 0) {
    respond_json(['success' => false, 'message' => 'Invalid parameters'], 400);
}

$stmt = $conn->prepare("INSERT IGNORE INTO notification_reads (student_id, notification_type, reference_id) VALUES (?, ?, ?)");
if (!$stmt) {
    respond_json(['success' => false, 'error' => $conn->error], 500);
}

$stmt->bind_param("isi", $student_id, $type, $id);

if ($stmt->execute()) {
    $stmt->close();
    respond_json(['success' => true]);
}

$stmt->close();
respond_json(['success' => false, 'error' => $conn->error], 500);
?>
