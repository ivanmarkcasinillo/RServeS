<?php
session_start();
require "dbconnect.php";

header('Content-Type: application/json');

// Verify student is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Student') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$student_id = $_SESSION['stud_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_complete'])) {
    $stask_id = $_POST['stask_id'];
    $task_description = trim($_POST['task_description']);
    
    // Verify this task belongs to the student
    $stmt = $conn->prepare("SELECT task_id FROM student_tasks WHERE stask_id = ? AND student_id = ?");
    $stmt->bind_param("ii", $stask_id, $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Task not found']);
        exit;
    }
    
    $task = $result->fetch_assoc();
    $task_id = $task['task_id'];
    $stmt->close();
    
    // Update task description
    if (!empty($task_description)) {
        $stmt = $conn->prepare("UPDATE tasks SET description = ? WHERE task_id = ?");
        $stmt->bind_param("si", $task_description, $task_id);
        $stmt->execute();
        $stmt->close();
    }
    
    // Mark task as completed with timestamp
    $stmt = $conn->prepare("UPDATE student_tasks SET status = 'Completed', completed_at = NOW() WHERE stask_id = ?");
    $stmt->bind_param("i", $stask_id);
    
    if ($stmt->execute()) {
        $stmt->close();
        
        // Get task details for accomplishment report
        $stmt = $conn->prepare("SELECT t.title, t.description FROM tasks t INNER JOIN student_tasks st ON t.task_id = st.task_id WHERE st.stask_id = ?");
        $stmt->bind_param("i", $stask_id);
        $stmt->execute();
        $task = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        // Get latest time_in for today
        $today = date('Y-m-d');
        $stmt = $conn->prepare("SELECT time_in, time_out FROM time_records WHERE student_id = ? AND work_date = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->bind_param("is", $student_id, $today);
        $stmt->execute();
        $time_record = $stmt->get_result()->fetch_assoc();
        $time_in = $time_record ? $time_record['time_in'] : date('H:i');
        $time_out = $time_record ? $time_record['time_out'] : null;
        $stmt->close();
        
        // Insert into accomplishment_reports
        $activity = $task['title'] . ': ' . $task['description'];
        $stmt = $conn->prepare("INSERT INTO accomplishment_reports (student_id, work_date, activity, time_start, time_end, hours, status) VALUES (?, ?, ?, ?, NULL, 0, 'Pending')");
        $stmt->bind_param("isss", $student_id, $today, $activity, $time_in);
        $stmt->execute();
        $stmt->close();
        
        echo json_encode(['success' => true, 'message' => 'Task submitted to Accomplishment Report!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to complete task']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid request']);
?>