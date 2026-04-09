<?php
session_start();
require "dbconnect.php";

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Student') {
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

$student_id = $_SESSION['stud_id'];
$stask_id = isset($_POST['stask_id']) ? intval($_POST['stask_id']) : 0;
$work_date = $_POST['work_date'];
$time_start = $_POST['time_start'];
$time_end = $_POST['time_end'];

if (empty($stask_id) || empty($work_date) || empty($time_start) || empty($time_end)) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
    exit;
}

// Get task details
$stmt = $conn->prepare("SELECT t.title, t.description FROM tasks t JOIN student_tasks st ON t.task_id = st.task_id WHERE st.stask_id = ? AND st.student_id = ?");
$stmt->bind_param("ii", $stask_id, $student_id);
$stmt->execute();
$task = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$task) {
    echo json_encode(['success' => false, 'message' => 'Task not found.']);
    exit;
}

$activity = $task['title'];
if (!empty($task['description'])) {
    $activity .= ' - ' . $task['description'];
}

// Calculate hours
$start = new DateTime($time_start);
$end = new DateTime($time_end);
$diff = $end->diff($start);
$hours = $diff->h + ($diff->i / 60);

$conn->begin_transaction();

try {
    // Insert into accomplishment_reports
    $stmt = $conn->prepare("INSERT INTO accomplishment_reports (student_id, work_date, activity, organization, time_start, time_end, hours, status) VALUES (?, ?, ?, '', ?, ?, ?, 'Completed')");
    $stmt->bind_param("issssd", $student_id, $work_date, $activity, $time_start, $time_end, $hours);
    $stmt->execute();
    $stmt->close();

    // Update student_tasks status
    $stmt = $conn->prepare("UPDATE student_tasks SET status = 'Completed', completed_at = NOW() WHERE stask_id = ? AND student_id = ?");
    $stmt->bind_param("ii", $stask_id, $student_id);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
    echo json_encode(['success' => true]);

} catch (mysqli_sql_exception $exception) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $exception->getMessage()]);
}
?>
