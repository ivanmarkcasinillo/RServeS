
<?php
session_start();
require "../dbconnect.php";

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Student') {
    echo json_encode(['time_in' => '']);
    exit;
}

$student_id = $_SESSION['stud_id'];
$work_date = $_GET['work_date'] ?? date('Y-m-d');

// Get the latest time_in for the specified date
$stmt = $conn->prepare("
    SELECT time_in 
    FROM time_records 
    WHERE student_id = ? AND work_date = ? 
    ORDER BY created_at DESC 
    LIMIT 1
");
$stmt->bind_param("is", $student_id, $work_date);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$stmt->close();

$time_in = $result ? $result['time_in'] : '';

echo json_encode(['time_in' => $time_in]);
?>