<?php
session_start();
require "dbconnect.php";

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Instructor') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$email = $_SESSION['email'];

// Get Instructor ID
$stmt = $conn->prepare("SELECT inst_id FROM instructors WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Instructor not found']);
    exit;
}
$row = $res->fetch_assoc();
$inst_id = $row['inst_id'];
$stmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (isset($data['action']) && $data['action'] === 'mark_all') {
        $up_notif = $conn->prepare("UPDATE instructor_notifications SET is_read = 1 WHERE instructor_id = ?");
        $up_notif->bind_param("i", $inst_id);
        
        if ($up_notif->execute()) {
            echo json_encode(['success' => true]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $conn->error]);
        }
        $up_notif->close();
        exit;
    }
}

echo json_encode(['success' => false, 'message' => 'Invalid request']);
?>
