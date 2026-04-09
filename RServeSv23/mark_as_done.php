<?php
session_start();
require "dbconnect.php";

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Student') {
    echo json_encode(['success'=>false]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
$stask_id = intval($data['stask_id']);

$stmt = $conn->prepare("UPDATE student_tasks SET status='Completed' WHERE stask_id=? AND student_id=?");
$stmt->bind_param("ii", $stask_id, $_SESSION['student_id']); // make sure student_id is in session
$success = $stmt->execute();
$stmt->close();

echo json_encode(['success'=>$success]);
?>
