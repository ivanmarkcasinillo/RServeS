<?php
require_once 'dbconnect.php';
require_once __DIR__ . '/task_backend.php';
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

echo "=== STUDENT DASHBOARD TASK DEBUG ===\n";

$student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 8;
echo "Student ID: $student_id\n";

echo "Recent dashboard-visible task rows:\n";
$stmt = $conn->prepare("
    SELECT
        st.stask_id,
        st.task_id,
        st.status,
        st.assigned_at,
        t.title,
        t.duration,
        t.created_by_student,
        t.created_at
    FROM student_tasks st
    LEFT JOIN tasks t ON st.task_id = t.task_id
    WHERE st.student_id = ?
    ORDER BY COALESCE(t.created_at, st.assigned_at) DESC, st.stask_id DESC
    LIMIT 15
");

if ($stmt) {
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $count = 0;
    while ($row = $result->fetch_assoc()) {
        $title = addslashes((string)($row['title'] ?? ''));
        $duration = $row['duration'] ?? 'NULL';
        $created_by = $row['created_by_student'] ?? 'NULL';
        $created_at = $row['created_at'] ?? 'NULL';
        $assigned_at = $row['assigned_at'] ?? 'NULL';
        echo "  stask_id={$row['stask_id']} task_id={$row['task_id']} status={$row['status']} title='{$title}' duration='{$duration}' created_by_student={$created_by} created_at={$created_at} assigned_at={$assigned_at}\n";
        $count++;
    }
    echo "Visible row sample count: $count\n";
    $stmt->close();
} else {
    echo "Recent-task query failed: " . $conn->error . "\n";
}

$accomplishment_reports = [];
$ar_stmt = $conn->prepare("
    SELECT id, student_task_id, activity, status
    FROM accomplishment_reports
    WHERE student_id = ?
    ORDER BY id DESC, work_date DESC
");
if ($ar_stmt) {
    $ar_stmt->bind_param("i", $student_id);
    $ar_stmt->execute();
    $ar_result = $ar_stmt->get_result();
    while ($row = $ar_result->fetch_assoc()) {
        $accomplishment_reports[] = $row;
    }
    $ar_stmt->close();
}

$dashboard_tasks = rserves_fetch_student_dashboard_tasks($conn, $student_id, $accomplishment_reports);
echo "Dashboard helper visible count: " . count($dashboard_tasks) . "\n";
foreach (array_slice($dashboard_tasks, 0, 10) as $task) {
    $title = addslashes((string)($task['title'] ?? ''));
    $task_type = $task['task_type'] ?? 'unknown';
    $display_status = $task['display_status'] ?? ($task['status'] ?? 'Unknown');
    $attempts = intval($task['ar_attempts'] ?? 0);
    echo "  visible stask_id={$task['stask_id']} title='{$title}' type={$task_type} display_status={$display_status} attempts={$attempts}\n";
}

$dept_stmt = $conn->prepare("SELECT department_id FROM students WHERE stud_id = ?");
$dept_stmt->bind_param("i", $student_id);
$dept_stmt->execute();
$dept_row = $dept_stmt->get_result()->fetch_assoc();
$dept_stmt->close();
$dept_id = $dept_row ? $dept_row['department_id'] : 0;
echo "Department ID: $dept_id\n";

$inst_stmt = $conn->prepare("SELECT COUNT(*) c, GROUP_CONCAT(inst_id) as ids FROM instructors WHERE department_id = ?");
$inst_stmt->bind_param("i", $dept_id);
$inst_stmt->execute();
$inst_row = $inst_stmt->get_result()->fetch_assoc();
$inst_stmt->close();
echo "Instructors in dept: {$inst_row['c']} (IDs: {$inst_row['ids']})\n";

echo "Pending task counts:\n";
$pending_stmt = $conn->prepare("SELECT COUNT(*) c FROM student_tasks WHERE student_id = ? AND status = 'Pending'");
$pending_stmt->bind_param("i", $student_id);
$pending_stmt->execute();
$pending_count = $pending_stmt->get_result()->fetch_assoc()['c'] ?? 0;
$pending_stmt->close();
echo "Pending count in student_tasks: {$pending_count}\n";

$dashboard_stmt = $conn->prepare("
    SELECT COUNT(*) c
    FROM student_tasks st
    INNER JOIN tasks t ON st.task_id = t.task_id
    WHERE st.student_id = ?
      AND st.status = 'Pending'
");
$dashboard_stmt->bind_param("i", $student_id);
$dashboard_stmt->execute();
$dashboard_count = $dashboard_stmt->get_result()->fetch_assoc()['c'] ?? 0;
$dashboard_stmt->close();
echo "Pending count with dashboard JOIN: {$dashboard_count}\n";

echo "=== END DEBUG ===\n";
?>

