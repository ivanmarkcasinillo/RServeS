<?php
session_start();

require "dbconnect.php";
require_once __DIR__ . "/task_assignment_helper.php";

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Instructor') {
    header("Location: ../home2.php");
    exit;
}

$email = $_SESSION['email'] ?? '';
$type = (string) ($_GET['type'] ?? 'advisory_records');
$department_id = intval($_SESSION['department_id'] ?? 0);

$stmt = $conn->prepare("
    SELECT inst_id, firstname, lastname
    FROM instructors
    WHERE email = ?
    LIMIT 1
");

if (!$stmt) {
    http_response_code(500);
    exit('Could not prepare instructor lookup.');
}

$stmt->bind_param("s", $email);
$stmt->execute();
$instructor = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$instructor) {
    http_response_code(404);
    exit('Instructor not found.');
}

$inst_id = intval($instructor['inst_id']);
$safe_date = date('Y-m-d');
$filename = $type . '_' . $safe_date . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');
if ($output === false) {
    http_response_code(500);
    exit('Could not open export stream.');
}

if ($type === 'task_assignments') {
    fputcsv($output, [
        'Task ID',
        'Task Title',
        'Duration',
        'Due Date',
        'Student Number',
        'Student Name',
        'Year Level',
        'Section',
        'Assignment Status',
        'Assigned At',
        'Completed At',
    ]);

    $stmt = $conn->prepare("
        SELECT
            t.task_id,
            t.title,
            t.duration,
            t.due_date,
            s.student_number,
            s.lastname,
            s.firstname,
            s.mi,
            s.year_level,
            s.section,
            st.status,
            st.assigned_at,
            st.completed_at
        FROM tasks t
        JOIN student_tasks st ON st.task_id = t.task_id
        JOIN students s ON s.stud_id = st.student_id
        WHERE t.instructor_id = ?
          AND t.is_deleted = 0
        ORDER BY t.created_at DESC, s.year_level ASC, s.section ASC, s.lastname ASC
    ");

    if ($stmt) {
        $stmt->bind_param("i", $inst_id);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $name = trim($row['lastname'] . ', ' . $row['firstname'] . (!empty($row['mi']) ? ' ' . $row['mi'] . '.' : ''));
            fputcsv($output, [
                $row['task_id'],
                $row['title'],
                $row['duration'],
                $row['due_date'],
                $row['student_number'],
                $name,
                $row['year_level'],
                $row['section'],
                $row['status'],
                $row['assigned_at'],
                $row['completed_at'],
            ]);
        }

        $stmt->close();
    }

    fclose($output);
    exit;
}

$sections = [];
$section_stmt = $conn->prepare("
    SELECT section
    FROM section_advisers
    WHERE instructor_id = ?
      AND department_id = ?
    ORDER BY section ASC
");

if ($section_stmt) {
    $section_stmt->bind_param("ii", $inst_id, $department_id);
    $section_stmt->execute();
    $section_result = $section_stmt->get_result();

    while ($section = $section_result->fetch_assoc()) {
        $sections[] = (string) $section['section'];
    }

    $section_stmt->close();
}

if ($type === 'student_list') {
    fputcsv($output, [
        'Student Number',
        'Student Name',
        'Email',
        'Year Level',
        'Section',
        'Approved Hours',
        'Pending Hours',
        'Remaining Hours',
        'Progress Percentage',
        'Pending Tasks',
        'In Progress Tasks',
        'Completed Tasks',
    ]);

    if (!empty($sections)) {
        $required_hours = rserves_instructor_required_hours();
        $placeholders = implode(',', array_fill(0, count($sections), '?'));
        $types = 'i' . str_repeat('s', count($sections));
        $params = array_merge([$department_id], $sections);

        $stmt = $conn->prepare("
            SELECT
                s.student_number,
                s.lastname,
                s.firstname,
                s.mi,
                s.email,
                s.year_level,
                s.section,
                COALESCE(hours.approved_hours, 0) AS approved_hours,
                COALESCE(hours.pending_hours, 0) AS pending_hours,
                COALESCE(task_totals.pending_tasks, 0) AS pending_tasks,
                COALESCE(task_totals.in_progress_tasks, 0) AS in_progress_tasks,
                COALESCE(task_totals.completed_tasks, 0) AS completed_tasks
            FROM students s
            LEFT JOIN (
                SELECT
                    student_id,
                    SUM(CASE WHEN status = 'Approved' THEN hours ELSE 0 END) AS approved_hours,
                    SUM(CASE WHEN status = 'Pending' THEN hours ELSE 0 END) AS pending_hours
                FROM accomplishment_reports
                GROUP BY student_id
            ) hours ON hours.student_id = s.stud_id
            LEFT JOIN (
                SELECT
                    student_id,
                    SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pending_tasks,
                    SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) AS in_progress_tasks,
                    SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) AS completed_tasks
                FROM student_tasks
                GROUP BY student_id
            ) task_totals ON task_totals.student_id = s.stud_id
            WHERE s.department_id = ?
              AND s.section IN ($placeholders)
            ORDER BY s.year_level ASC, s.section ASC, s.lastname ASC, s.firstname ASC
        ");

        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $student_name = trim($row['lastname'] . ', ' . $row['firstname'] . (!empty($row['mi']) ? ' ' . $row['mi'] . '.' : ''));
                $approved_hours = floatval($row['approved_hours'] ?? 0);
                $pending_hours = floatval($row['pending_hours'] ?? 0);
                $remaining_hours = max(0, $required_hours - $approved_hours);
                $progress_percent = $required_hours > 0 ? min(($approved_hours / $required_hours) * 100, 100) : 0;

                fputcsv($output, [
                    $row['student_number'],
                    $student_name,
                    $row['email'],
                    $row['year_level'],
                    $row['section'],
                    number_format($approved_hours, 2, '.', ''),
                    number_format($pending_hours, 2, '.', ''),
                    number_format($remaining_hours, 2, '.', ''),
                    number_format($progress_percent, 2, '.', ''),
                    $row['pending_tasks'],
                    $row['in_progress_tasks'],
                    $row['completed_tasks'],
                ]);
            }

            $stmt->close();
        }
    }

    fclose($output);
    exit;
}

fputcsv($output, [
    'Student Number',
    'Student Name',
    'Year Level',
    'Section',
    'Work Date',
    'Time Start',
    'Time End',
    'Hours',
    'Status',
    'Activity',
    'Assigned By',
    'Approved By',
    'Submitted At',
]);

if (!empty($sections)) {
    $placeholders = implode(',', array_fill(0, count($sections), '?'));
    $types = 'i' . str_repeat('s', count($sections));
    $params = array_merge([$department_id], $sections);

    $stmt = $conn->prepare("
        SELECT
            s.student_number,
            s.lastname,
            s.firstname,
            s.mi,
            s.year_level,
            s.section,
            ar.work_date,
            ar.time_start,
            ar.time_end,
            ar.hours,
            ar.status,
            ar.activity,
            ar.created_at,
            assigner.firstname AS assigner_firstname,
            assigner.lastname AS assigner_lastname,
            approver.firstname AS approver_firstname,
            approver.lastname AS approver_lastname
        FROM accomplishment_reports ar
        JOIN students s ON s.stud_id = ar.student_id
        LEFT JOIN instructors assigner ON assigner.inst_id = ar.assigner_id
        LEFT JOIN instructors approver ON approver.inst_id = ar.approver_id
        WHERE s.department_id = ?
          AND s.section IN ($placeholders)
        ORDER BY s.year_level ASC, s.section ASC, s.lastname ASC, ar.work_date DESC, ar.created_at DESC
    ");

    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $student_name = trim($row['lastname'] . ', ' . $row['firstname'] . (!empty($row['mi']) ? ' ' . $row['mi'] . '.' : ''));
            $assigner_name = trim((string) ($row['assigner_firstname'] ?? '') . ' ' . (string) ($row['assigner_lastname'] ?? ''));
            $approver_name = trim((string) ($row['approver_firstname'] ?? '') . ' ' . (string) ($row['approver_lastname'] ?? ''));
            $activity = trim((string) preg_replace('/\[\s*TaskID\s*:\s*\d+\s*\]/i', '', (string) ($row['activity'] ?? '')));

            fputcsv($output, [
                $row['student_number'],
                $student_name,
                $row['year_level'],
                $row['section'],
                $row['work_date'],
                $row['time_start'],
                $row['time_end'],
                $row['hours'],
                $row['status'],
                $activity,
                $assigner_name,
                $approver_name,
                $row['created_at'],
            ]);
        }

        $stmt->close();
    }
}

fclose($output);
exit;
