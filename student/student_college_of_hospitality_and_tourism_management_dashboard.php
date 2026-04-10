<!--Student Dashboard-->
<?php
date_default_timezone_set('Asia/Manila');

session_start();
require "dbconnect.php";
include "check_expiration.php";
require_once __DIR__ . "/task_backend.php";
rserves_student_ensure_task_schema($conn);

if (empty($_SESSION['student_task_form_token'])) {
    $_SESSION['student_task_form_token'] = bin2hex(random_bytes(16));
}
$student_task_form_token = $_SESSION['student_task_form_token'];

if (!function_exists('rserves_dashboard_view_url')) {
    function rserves_dashboard_view_url(string $view = 'dashboard'): string
    {
        $self = $_SERVER['PHP_SELF'] ?? 'student_college_of_hospitality_and_tourism_management_dashboard.php';
        $allowed_views = ['dashboard', 'tasks', 'documents', 'notifications'];

        if (!in_array($view, $allowed_views, true) || $view === 'dashboard') {
            return $self;
        }

        return $self . '?view=' . urlencode($view);
    }
}

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Student') {
    header("Location: ../home2.php");
    exit;
}

$student_id = $_SESSION['stud_id'];
$email = $_SESSION['email'];

// Check Document Verification Status
$req_check = $conn->prepare("
    SELECT 
        (SELECT status FROM rss_enrollments WHERE student_id = ? ORDER BY enrollment_id DESC LIMIT 1) as enrollment_status,
        (SELECT status FROM rss_waivers WHERE student_id = ? ORDER BY id DESC LIMIT 1) as waiver_status,
        (SELECT status FROM rss_agreements WHERE student_id = ? ORDER BY agreement_id DESC LIMIT 1) as agreement_status
");
$req_check->bind_param("iii", $student_id, $student_id, $student_id);
$req_check->execute();
$req_res = $req_check->get_result()->fetch_assoc();
$req_check->close();

// Auto-migration: Add assigner_id to accomplishment_reports if not exists
$check_col = $conn->query("SHOW COLUMNS FROM accomplishment_reports LIKE 'assigner_id'");
if ($check_col && $check_col->num_rows == 0) {
    $conn->query("ALTER TABLE accomplishment_reports ADD COLUMN assigner_id INT NULL DEFAULT NULL");
}

// Auto-migration: Store the originating student task directly for reliable status matching
$check_task_col = $conn->query("SHOW COLUMNS FROM accomplishment_reports LIKE 'student_task_id'");
if ($check_task_col && $check_task_col->num_rows == 0) {
    $conn->query("ALTER TABLE accomplishment_reports ADD COLUMN student_task_id INT NULL DEFAULT NULL");
}

// Moved instructor fetching below

if (
    ($req_res['enrollment_status'] ?? 'Pending') !== 'Verified' ||
    ($req_res['waiver_status'] ?? 'Pending') !== 'Verified' ||
    ($req_res['agreement_status'] ?? 'Pending') !== 'Verified'
) {
    header("Location: pending_requirements.php");
    exit;
}

$stmt = $conn->prepare("
    SELECT s.stud_id, s.firstname, s.lastname, s.mi, s.photo, s.instructor_id, s.department_id,
           s.year_level, s.semester, s.section, d.department_name
    FROM students s
    LEFT JOIN departments d ON s.department_id = d.department_id
    WHERE s.stud_id = ?
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fetch instructors for selection (filtered by department)
$instructors_list = [];
if (!empty($student['department_id'])) {
    $inst_query = "SELECT inst_id, firstname, lastname FROM instructors WHERE department_id = " . intval($student['department_id']) . " ORDER BY lastname ASC";
    $inst_res = $conn->query($inst_query);
    if ($inst_res) {
        while ($row = $inst_res->fetch_assoc()) {
            $instructors_list[] = $row;
        }
    }
}

$fullname = $student['firstname'] 
          . (!empty($student['mi']) ? ' ' . strtoupper(substr($student['mi'],0,1)) . '.' : '')
          . ' ' . $student['lastname'];
$photo = $student['photo'] ?: 'default_profile.png';
$college_name = $student['department_name'];

// Fetch Adviser
$adviser_name = "Not Assigned";
if (!empty($student['section'])) {
     $stmt_adv = $conn->prepare("
        SELECT i.firstname, i.lastname
        FROM section_advisers sa
        JOIN instructors i ON sa.instructor_id = i.inst_id
        WHERE sa.department_id = ? AND sa.section = ?
    ");
    $stmt_adv->bind_param("is", $student['department_id'], $student['section']);
    $stmt_adv->execute();
    $res_adv = $stmt_adv->get_result();
    if ($row_adv = $res_adv->fetch_assoc()) {
        $adviser_name = $row_adv['firstname'] . ' ' . $row_adv['lastname'];
    }
    $stmt_adv->close();
}

 // Fetch Enrollment Data
$enrollment_stmt = $conn->prepare("SELECT * FROM rss_enrollments WHERE student_id = ?");
$enrollment_stmt->bind_param("i", $student_id);
$enrollment_stmt->execute();
$enrollment_data = $enrollment_stmt->get_result()->fetch_assoc();
$enrollment_stmt->close();

// Get today's time-in records so DTR is only available once per day
$today = date('Y-m-d');
$todays_records = [];
$time_record = null;
$stmt = $conn->prepare("SELECT time_in, session FROM time_records WHERE student_id = ? AND work_date = ? ORDER BY created_at DESC");
$stmt->bind_param("is", $student_id, $today);
$stmt->execute();
$time_records_result = $stmt->get_result();
while ($row = $time_records_result->fetch_assoc()) {
    if ($time_record === null) {
        $time_record = $row;
    }
    $todays_records[$row['session']] = $row['time_in'];
}
$time_in_today = $time_record ? $time_record['time_in'] : '';
$current_session = $time_record ? $time_record['session'] : '';
$attendance_locked_for_today = !empty($todays_records);
$is_after_dtr_cutoff = false; // Temporary: disable 5 PM DTR cutoff while validating live task flow
$stmt->close();

// Organization query removed


$stmt_ar = $conn->prepare("
    SELECT SUM(hours) AS total_hours
    FROM accomplishment_reports
    WHERE student_id = ? AND status = 'Approved'
");

if (!$stmt_ar) {
    die("SQL Error: " . $conn->error);
}

$stmt_ar->bind_param("i", $student_id);
$stmt_ar->execute();
$result_ar = $stmt_ar->get_result()->fetch_assoc();
$total_hours_completed = $result_ar['total_hours'] ?? 0;
$stmt_ar->close();

// Fetch Accomplishment Reports for Task Status Tracking
$stmt = $conn->prepare("
    SELECT 
        id,
        student_id,
        student_task_id,
        activity AS activity,
        work_date,
        time_start,
        time_end,
        hours,
        photo AS photo,
        photo2 AS photo2,
        status
    FROM accomplishment_reports
    WHERE student_id = ?
    ORDER BY id DESC, work_date DESC
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$res = $stmt->get_result();
$accomplishment_reports = [];
while ($row = $res->fetch_assoc()) {
    $accomplishment_reports[] = $row;
}
$stmt->close();


$required_hours = 320;
$progress_percent = min(($total_hours_completed / $required_hours) * 100, 100);
$progress_message = $progress_percent == 0 ? "Start now! 💪" : "Keep going! You're doing great! 🌟";

// Handle add_accomplishment form submission
if (!in_array($_SESSION['role'], ['Coordinator', 'Instructor'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_accomplishment'])) {
        $work_date = $_POST['work_date'];
        $activity = trim($_POST['activity']);
        if (!empty($_POST['task_title'])) {
            $activity = trim($_POST['task_title']) . ': ' . $activity;
        }

        // Append Task ID for tracking if available
        $assigner_id = null;
        $linked_student_task_id = null;
        if (!empty($_POST['prefill_stask_id'])) {
            $stask_id_val = intval($_POST['prefill_stask_id']);
            $linked_student_task_id = $stask_id_val;
            $attempt_pattern = '%[TaskID:' . $stask_id_val . ']%';
            $attempt_stmt = $conn->prepare("
                SELECT COUNT(*) AS cnt
                FROM accomplishment_reports
                WHERE student_id = ?
                AND (
                    student_task_id = ?
                    OR activity LIKE ?
                )
            ");
            if ($attempt_stmt) {
                $attempt_stmt->bind_param("iis", $student_id, $stask_id_val, $attempt_pattern);
                $attempt_stmt->execute();
                $attempt_cnt = intval(($attempt_stmt->get_result()->fetch_assoc()['cnt'] ?? 0));
                $attempt_stmt->close();
                if ($attempt_cnt >= 2) {
                    $_SESSION['flash'] = "You can only submit this task to your adviser twice.";
                    header("Location: " . rserves_dashboard_view_url('tasks'));
                    exit;
                }
            }
            $activity .= ' [TaskID:' . $stask_id_val . ']';
            
            // Fetch task's instructor_id
            $task_q = $conn->prepare("SELECT instructor_id FROM tasks t JOIN student_tasks st ON t.task_id = st.task_id WHERE st.stask_id = ?");
            $task_q->bind_param("i", $stask_id_val);
            $task_q->execute();
            $task_res = $task_q->get_result()->fetch_assoc();
            $assigner_id = $task_res['instructor_id'] ?? null;
            $task_q->close();
        }
        $time_start = $_POST['time_start'];
        $time_end = $_POST['time_end'];
        $hours = floatval($_POST['hours']);
        $status = 'Pending';
        
        if ($status == 'Pending') {
            $time_end = !empty($time_end) ? $time_end : NULL;
            $hours = $hours > 0 ? $hours : 0;
        }

        // Handle photo upload
        $photo1 = null;
        $photo2 = null;
        $uploadDir = __DIR__ . '/uploads/accomplishments/';

        // Ensure folder exists
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        // Function to process each file
        if (!function_exists('savePhoto')) {
            function savePhoto($fileInputName, $student_id, $uploadDir) {
                if (!empty($_FILES[$fileInputName]['tmp_name'])) {
                    $file = $_FILES[$fileInputName];
                    if ($file['error'] === UPLOAD_ERR_OK) {
                        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                        $allowed = ['jpg','jpeg','png','gif','webp'];

                        if (in_array($ext, $allowed)) {
                            $newName = $fileInputName . "_" . $student_id . "_" . time() . "." . $ext;
                            $dest = $uploadDir . $newName;

                            if (move_uploaded_file($file['tmp_name'], $dest)) {
                                return 'uploads/accomplishments/' . $newName;
                            }
                        }
                    }
                }
                return null;
            }
        }

        // Save both photos
        $photo1 = savePhoto('photo', $student_id, $uploadDir);
        $photo2 = savePhoto('photo2', $student_id, $uploadDir);

        $stmt = $conn->prepare("
           INSERT INTO accomplishment_reports 
    (student_id, work_date, activity, time_start, time_end, hours, status, photo, photo2, assigner_id, student_task_id) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("issssdsssii", 
            $student_id, $work_date, $activity, $time_start, $time_end, 
            $hours, $status, $photo1, $photo2, $assigner_id, $linked_student_task_id
        );

        if ($stmt->execute()) {
            // If prefill_task, mark student_task as completed
            /* 
               MODIFICATION: Do NOT mark as completed yet. 
               Wait for Adviser Approval. 
               The Task ID is now embedded in the activity description for tracking.
            */
            /*
            if (!empty($_POST['prefill_stask_id'])) {
                $stask_id = intval($_POST['prefill_stask_id']);
                $stmt2 = $conn->prepare("UPDATE student_tasks SET status = 'Completed', completed_at = NOW() WHERE stask_id = ? AND student_id = ?");
                $stmt2->bind_param("ii", $stask_id, $student_id);
                $stmt2->execute();
                $stmt2->close();
            }
            */
            $_SESSION['flash'] = "Accomplishment submitted to adviser for approval!";
            header("Location: " . rserves_dashboard_view_url('tasks'));
            exit;
        }
        $stmt->close();
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['upload_waiver'])) {
    $conn->query("CREATE TABLE IF NOT EXISTS rss_waivers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        status ENUM('Pending', 'Verified', 'Rejected') DEFAULT 'Pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        verified_at TIMESTAMP NULL,
        verified_by INT NULL,
        FOREIGN KEY (student_id) REFERENCES students(stud_id) ON DELETE CASCADE
    )");

    if (!empty($_FILES['waiver_file']['tmp_name'])) {
        $file = $_FILES['waiver_file'];
        if ($file['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'heic'];
            if (in_array($ext, $allowed)) {
                $uploadDir = __DIR__ . '/../uploads/waivers/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                $newName = 'waiver_' . $student_id . '_' . time() . '.' . $ext;
                $dest = $uploadDir . $newName;
                if (move_uploaded_file($file['tmp_name'], $dest)) {
                    $relPath = 'uploads/waivers/' . $newName;

                    $stmt = $conn->prepare("SELECT id FROM rss_waivers WHERE student_id = ? LIMIT 1");
                    if ($stmt) {
                        $stmt->bind_param("i", $student_id);
                        $stmt->execute();
                        $existing = $stmt->get_result()->fetch_assoc();
                        $stmt->close();

                        if ($existing) {
                            $stmt = $conn->prepare("UPDATE rss_waivers SET file_path = ?, status = 'Pending', created_at = NOW(), verified_at = NULL WHERE student_id = ?");
                            $stmt->bind_param("si", $relPath, $student_id);
                        } else {
                            $stmt = $conn->prepare("INSERT INTO rss_waivers (student_id, file_path, status) VALUES (?, ?, 'Pending')");
                            $stmt->bind_param("is", $student_id, $relPath);
                        }

                        if ($stmt) {
                            $stmt->execute();
                            $stmt->close();
                            $_SESSION['flash'] = "Waiver uploaded and submitted to your coordinator for verification.";
                        } else {
                            $_SESSION['flash'] = "Database error while saving waiver.";
                        }
                    } else {
                        $_SESSION['flash'] = "Database error while checking existing waiver.";
                    }
                } else {
                    $_SESSION['flash'] = "Failed to move uploaded waiver file.";
                }
            } else {
                $_SESSION['flash'] = "Invalid file type. Please upload a PDF or image file.";
            }
        } else {
            $_SESSION['flash'] = "Upload error. Please try again.";
        }
    } else {
        $_SESSION['flash'] = "Please choose a waiver file to upload.";
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['upload_agreement'])) {
    $conn->query("ALTER TABLE rss_agreements ADD COLUMN IF NOT EXISTS file_path VARCHAR(255)");
    $conn->query("ALTER TABLE rss_agreements ADD COLUMN IF NOT EXISTS status ENUM('Pending', 'Verified', 'Rejected') DEFAULT 'Pending'");

    if (!empty($_FILES['agreement_file']['tmp_name'])) {
        $file = $_FILES['agreement_file'];
        if ($file['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'heic'];
            if (in_array($ext, $allowed)) {
                $uploadDir = __DIR__ . '/../uploads/agreements/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                $newName = 'agreement_' . $student_id . '_' . time() . '.' . $ext;
                $dest = $uploadDir . $newName;
                if (move_uploaded_file($file['tmp_name'], $dest)) {
                    $relPath = 'uploads/agreements/' . $newName;

                    $stmt = $conn->prepare("SELECT agreement_id FROM rss_agreements WHERE student_id = ? LIMIT 1");
                    if ($stmt) {
                        $stmt->bind_param("i", $student_id);
                        $stmt->execute();
                        $existing = $stmt->get_result()->fetch_assoc();
                        $stmt->close();

                        if ($existing) {
                            $stmt = $conn->prepare("UPDATE rss_agreements SET file_path = ?, status = 'Pending' WHERE student_id = ?");
                            $stmt->bind_param("si", $relPath, $student_id);
                        } else {
                            $stmt = $conn->prepare("INSERT INTO rss_agreements (student_id, file_path, status) VALUES (?, ?, 'Pending')");
                            $stmt->bind_param("is", $student_id, $relPath);
                        }

                        if ($stmt) {
                            $stmt->execute();
                            $stmt->close();
                            $_SESSION['flash'] = "Agreement uploaded and submitted to your coordinator for verification.";
                        } else {
                            $_SESSION['flash'] = "Database error while saving agreement.";
                        }
                    } else {
                        $_SESSION['flash'] = "Database error while checking existing agreement.";
                    }
                } else {
                    $_SESSION['flash'] = "Failed to move uploaded agreement file.";
                }
            } else {
                $_SESSION['flash'] = "Invalid file type. Please upload a PDF or image file.";
            }
        } else {
            $_SESSION['flash'] = "Upload error. Please try again.";
        }
    } else {
        $_SESSION['flash'] = "Please choose an agreement file to upload.";
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES["profilePhoto"])) {
    $file = $_FILES['profilePhoto'];
    if ($file['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','gif','webp'];
        if (in_array($ext, $allowed)) {
            $newName = 'student_'.$student_id.'_'.time().'.'.$ext;
            $dest = __DIR__ . '/../uploads/profile_photos/'.$newName;
            if (!is_dir(__DIR__.'/../uploads/profile_photos')) mkdir(__DIR__.'/../uploads/profile_photos', 0777, true);
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                $relPath = 'uploads/profile_photos/'.$newName;
                $up = $conn->prepare("UPDATE students SET photo=? WHERE stud_id=?");
                $up->bind_param("si", $relPath, $student_id);
                $up->execute();
                $up->close();
                header("Location: ".$_SERVER['PHP_SELF']);
                exit;
            }
        }
    }
}

// Handle Time In/Out submission - FIXED
if (isset($_POST['submit_time'])) {
    $date = $_POST['work_date'] ?? '';
    $session = $_POST['session'] ?? '';
    $time_in = $_POST['time_in'] ?? '';

    if ($date !== date('Y-m-d')) {
        $_SESSION['flash'] = "DTR login is only available for today's date.";
        header("Location: ".$_SERVER['PHP_SELF']);
        exit;
    }

    if ($is_after_dtr_cutoff) {
        $_SESSION['flash'] = "DTR login is unavailable after 5:00 PM.";
        header("Location: ".$_SERVER['PHP_SELF']);
        exit;
    }

    $existing_stmt = $conn->prepare("
        SELECT session, time_in
        FROM time_records
        WHERE student_id = ? AND work_date = ?
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $existing_stmt->bind_param("is", $student_id, $date);
    $existing_stmt->execute();
    $existing_time_record = $existing_stmt->get_result()->fetch_assoc();
    $existing_stmt->close();

    if ($existing_time_record) {
        $_SESSION['flash'] = "DTR login is unavailable after your first time-in for the day.";
        header("Location: ".$_SERVER['PHP_SELF']);
        exit;
    }
    
    $time_out = null;
    if ($session === 'morning') {
        $time_out = '12:00';
    } else if ($session === 'afternoon') {
        $time_out = '17:00';
    } else if ($session === 'fullday') {
        $time_out = '17:00';
    }
    
    $calculated_hours = floatval($_POST['calculated_hours']);
    
    $stmt = $conn->prepare("
        INSERT INTO time_records 
        (student_id, work_date, session, time_in, time_out, hours, status) 
        VALUES (?, ?, ?, ?, ?, ?, 'Pending')
    ");
    $stmt->bind_param("issssd", $student_id, $date, $session, $time_in, $time_out, $calculated_hours);
    $stmt->execute();
    $stmt->close();
    
    $_SESSION['flash'] = "Attendance submitted! Time In: {$time_in}, Hours: {$calculated_hours}";
    header("Location: ".$_SERVER['PHP_SELF']);
    exit;
}

if (isset($_POST['create_verbal_task']) || isset($_POST['task_form_token'])) {
    $_SESSION['flash'] = rserves_create_student_verbal_task($conn, $student, $student_id, $_POST, true);
    header("Location: " . rserves_dashboard_view_url('tasks'));
    exit;
}

if (isset($_POST['update_task_duration'])) {
    $stask_id = $_POST['stask_id'];
    $duration = $_POST['duration'];
    
    $stmt = $conn->prepare("
        UPDATE tasks t 
        INNER JOIN student_tasks st ON t.task_id = st.task_id 
        SET t.duration = ? 
        WHERE st.stask_id = ?
    ");
    $stmt->bind_param("si", $duration, $stask_id);
    $stmt->execute();
    $stmt->close();
    
    echo json_encode(['success' => true]);
    exit;
}

if (isset($_POST['update_task_duration'])) {
    $stask_id = $_POST['stask_id'];
    $duration = $_POST['duration'];
    
    $stmt = $conn->prepare("
        UPDATE tasks t 
        INNER JOIN student_tasks st ON t.task_id = st.task_id 
        SET t.duration = ? 
        WHERE st.stask_id = ?
    ");
    $stmt->bind_param("si", $duration, $stask_id);
    $stmt->execute();
    $stmt->close();
    
    echo json_encode(['success' => true]);
    exit;
}

if (isset($_POST['update_task_desc'])) {
    $stask_id = $_POST['stask_id'];
    $description = trim($_POST['description']);
    
    $stmt = $conn->prepare("
        UPDATE tasks t 
        INNER JOIN student_tasks st ON t.task_id = st.task_id 
        SET t.description = ? 
        WHERE st.stask_id = ?
    ");
    $stmt->bind_param("si", $description, $stask_id);
    $stmt->execute();
    $stmt->close();
    
    echo json_encode(['success' => true]);
    exit;
}

// Org task creation removed

$tasks = rserves_fetch_student_dashboard_tasks($conn, $student_id, $accomplishment_reports);

/* Org Tasks Fetching Removed */
// Org init removed

// Pending org accomps fetching removed

// Total pending org calculation removed

// Fetch read notifications
$read_notifications = [];
$stmt = $conn->prepare("SELECT notification_type, reference_id FROM notification_reads WHERE student_id = ?");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $read_notifications[$row['notification_type'] . '_' . $row['reference_id']] = true;
}
$stmt->close();

// Approved orgs fetching removed

// Fetch certificates
$certificates = [];
$stmt = $conn->prepare("SELECT certificate_id, certificate_code, created_at FROM student_certificates WHERE student_id = ? ORDER BY created_at DESC");
if ($stmt) {
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $certificates[] = $row;
    }
    $stmt->close();
}

// Prepare Notifications
$notifications = [];
foreach ($tasks as $task) {
    $is_read = isset($read_notifications['task_' . $task['task_id']]);
    $notifications[] = [
        'id' => $task['task_id'],
        'type' => 'task',
        'message' => 'New Task: ' . $task['title'],
        'date' => $task['created_at'],
        'link' => "markAsRead('task', {$task['task_id']}, function() { showView('tasks', document.querySelector('#sidebar-wrapper .list-group-item:nth-child(2)')); })",
        'is_read' => $is_read
    ];
}
// Approved orgs notifications removed
foreach ($certificates as $cert) {
    $is_read = isset($read_notifications['certificate_' . $cert['certificate_id']]);
    $notifications[] = [
        'id' => $cert['certificate_id'],
        'type' => 'certificate',
        'message' => 'Certificate Generated: ' . $cert['certificate_code'],
        'date' => $cert['created_at'],
        'link' => "markAsRead('certificate', {$cert['certificate_id']}, function() { window.location.href='view_certificate.php?code={$cert['certificate_code']}'; })",
        'is_read' => $is_read
    ];
}

// Sort notifications by date desc
usort($notifications, function($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
});

$unread_count = count(array_filter($notifications, function($n) { return !$n['is_read']; }));

$newly_created_task_id = intval($_SESSION['last_created_student_task_id'] ?? 0);
unset($_SESSION['last_created_student_task_id']);

$allowed_dashboard_views = ['dashboard', 'tasks', 'documents', 'notifications'];
$initial_view = 'dashboard';
if (isset($_GET['view']) && in_array($_GET['view'], $allowed_dashboard_views, true)) {
    $initial_view = $_GET['view'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - College of Hospitality and Tourism Management</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Urbanist:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <style>
        :root {
            --primary-color: #1a4f7a;
            --secondary-color: #123755;
            --accent-color: #3a8ebd;
            --bg-color: #f4f7f6;
            --text-dark: #2c3e50;
            --sidebar-width: 260px;
            --navbar-height: 64px;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: 'Urbanist', sans-serif;
            color: var(--text-dark);
            overflow-x: hidden;
            position: relative;
            min-height: 100vh;
        }

        html, body {
            min-height: 100%;
            background-color: var(--bg-color);
        }

        #wrapper,
        #page-content-wrapper,
        .container-fluid {
            min-height: 100vh;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: var(--bg-color);
            background-size: cover;
            background-position: center;
            filter: blur(5px);
            z-index: -1;
        }

        /* Sidebar Styles */
        #sidebar-wrapper {
            min-height: 100vh;
            min-height: 100dvh;
            height: 100vh;
            height: 100dvh;
            width: var(--sidebar-width);
            margin-left: 0;
            transition: margin 0.25s ease-out;
            background: var(--primary-color);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            position: fixed !important;
            top: 0 !important;
            bottom: 0 !important;
            left: 0;
            z-index: 1000;
            border-right: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            flex-direction: column;
        }

        #sidebar-wrapper .sidebar-heading {
            height: var(--navbar-height);
            padding: 1rem 1.25rem;
            display: flex;
            align-items: center;
            font-size: 1.5rem;
            font-weight: 700;
            color: white;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        #sidebar-wrapper .sidebar-heading img {
            display: inline-block;
            margin-right: 8px;
            vertical-align: middle;
        }

        #sidebar-wrapper .list-group {
            width: var(--sidebar-width);
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        #sidebar-wrapper .list-group-item {
            background-color: transparent;
            color: rgba(255,255,255,0.8);
            border: none;
            padding: 1rem 1.5rem;
            font-weight: 500;
            transition: all 0.3s;
        }

        #sidebar-wrapper .list-group-item:hover,
        #sidebar-wrapper .list-group-item.active {
            background-color: rgba(255,255,255,0.1);
            color: white;
            padding-left: 2rem;
            border-left: 4px solid var(--accent-color);
        }

        #sidebar-wrapper .list-group-item i {
            width: 25px;
            margin-right: 10px;
        }

        /* Page Content */
        #page-content-wrapper {
            width: 100%;
            margin-left: var(--sidebar-width);
            transition: margin 0.25s ease-out;
        }

        .navbar {
            height: var(--navbar-height);
            padding: 1rem 2rem;
            display: flex;
            align-items: center;
            background: var(--primary-color);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            color: white;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            position: sticky;
            top: 0;
            z-index: 1020;
        }

        .container-fluid {
            padding: 2rem;
        }

        .btn,
        .form-control,
        .form-select,
        .accordion-button,
        .content-card,
        .stat-card,
        .task-item,
        .card {
            transition: transform 0.28s ease, box-shadow 0.28s ease, background-color 0.28s ease, border-color 0.28s ease, color 0.28s ease;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 24px rgba(17, 55, 85, 0.16);
        }

        .content-card:hover,
        .card:hover {
            transform: translateY(-4px);
            box-shadow: 0 18px 38px rgba(10, 31, 48, 0.12);
        }

        .form-control:hover,
        .form-select:hover,
        .form-control:focus,
        .form-select:focus {
            transform: translateY(-1px);
            box-shadow: 0 0 0 0.2rem rgba(58, 142, 189, 0.15);
        }

        /* Cards */
        .stat-card {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.4);
            transition: transform 0.3s;
            height: 100%;
            border-top: 5px solid var(--primary-color);
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-icon {
            font-size: 2.5rem;
            color: var(--primary-color);
            margin-bottom: 1rem;
        }
        
        .progress-circle {
            width: 200px;
            height: 200px;
            border-radius: 50%;
            background: conic-gradient(var(--accent-color) 0% var(--progress), #e0e0e0 var(--progress) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.2rem;
            font-weight: bold;
            color: var(--primary-color);
            position: relative;
            margin: 0 auto 1.5rem auto;
        }

        .progress-circle::before {
            content: '';
            position: absolute;
            width: 170px;
            height: 170px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.85);
            z-index: 1;
        }

        .progress-circle span {
            z-index: 2;
        }

        /* Data Tables / Grids */
        .content-card {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.4);
            margin-bottom: 2rem;
            border-top: 5px solid var(--primary-color);
        }

        #view-dashboard .rserve-dashboard-scroll {
            max-height: none;
            overflow: visible;
            overscroll-behavior: auto;
            scrollbar-gutter: auto;
        }

        #view-notifications .content-card {
            padding: 0;
        }

        #view-notifications .rserve-notifications-scroll {
            max-height: none;
            overflow: visible;
            padding: 2rem;
            overscroll-behavior: auto;
            scrollbar-gutter: auto;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            #sidebar-wrapper {
                margin-left: calc(-1 * var(--sidebar-width));
            }
            #page-content-wrapper {
                margin-left: 0;
            }
            body.sidebar-toggled #sidebar-wrapper {
                margin-left: 0;
            }
            body.sidebar-toggled #page-content-wrapper {
                margin-left: 0; /* Overlay effect */
            }
            body.sidebar-toggled::after {
                content: '';
                position: fixed;
                top: 0; left: 0; right: 0; bottom: 0;
                background: rgba(0,0,0,0.5);
                z-index: 999;
            }
        }
        
        .task-item {
            background: rgba(248, 249, 250, 0.5);
            border: 1px solid rgba(0,0,0,0.05);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 12px;
            transition: all 0.3s;
        }

        .task-item:hover {
            background: white;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }

        .task-item.task-item-new {
            background: linear-gradient(135deg, rgba(255, 248, 214, 0.95), rgba(255, 255, 255, 0.96));
            border-color: rgba(255, 193, 7, 0.7);
            box-shadow: 0 10px 24px rgba(255, 193, 7, 0.16);
        }

        .task-item.task-item-new:hover {
            background: linear-gradient(135deg, rgba(255, 250, 228, 1), rgba(255, 255, 255, 1));
            box-shadow: 0 14px 28px rgba(255, 193, 7, 0.2);
        }
        
        .btn-custom {
            background: var(--primary-color);
            color: white;
            border: none;
        }
        .btn-custom:hover {
            background: var(--secondary-color);
            color: white;
        }
        
        /* Modal tweaks */
        .modal-backdrop {
            z-index: 1050;
        }
        .modal {
            z-index: 1055;
        }
        #addAccomplishmentModal .modal-dialog {
            margin-top: 72px;
            max-width: 600px;
        }
        .modal-content {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
        }
        .modal-header {
            background: linear-gradient(90deg, rgba(29, 110, 160, 0.9), rgba(13, 60, 97, 0.95));
            color: white;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        .btn-close-white {
            filter: invert(1) grayscale(100%) brightness(200%);
        }

        /* Instructions Card */
        .card {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.4);
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        .instructions {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.5s ease-out, opacity 0.5s ease-out;
            opacity: 0;
        }
        .instructions.show {
            max-height: 1000px;
            opacity: 1;
            transition: max-height 0.5s ease-in, opacity 0.5s ease-in;
        }
        .see-more {
            color: var(--primary-color);
            cursor: pointer;
            font-weight: 600;
            user-select: none;
            display: inline-block;
            margin-top: 0.5rem;
        }
        .see-more:hover {
            text-decoration: underline;
        }

        /* Combined Card Utilities */
        .hover-shadow:hover {
            transform: translateY(-3px);
            box-shadow: 0 .25rem .5rem rgba(0,0,0,.1)!important;
        }
        .transition-all {
            transition: all 0.2s ease;
        }
        @media (min-width: 992px) {
            .border-end-lg {
                border-right: 1px solid #dee2e6;
            }
        }

        /* Mobile Header Styles */
        .mobile-header {
            display: none; /* Hidden by default */
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            background: var(--primary-color);
            flex-direction: column;
            padding: 0;
            z-index: 1040;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }

        .mobile-header-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.5rem 1rem;
            width: 100%;
        }

        .mobile-header-nav {
            display: flex;
            justify-content: space-around;
            width: 100%;
            padding-bottom: 0.5rem;
            border-top: 1px solid rgba(255,255,255,0.1);
            padding-top: 0.5rem;
        }

        .mobile-header .nav-item {
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            display: flex;
            flex-direction: column;
            align-items: center;
            font-size: 0.75rem;
            padding: 0.25rem;
        }

        .mobile-header .nav-item.active {
            color: white;
            font-weight: bold;
        }

        .mobile-header .nav-item i {
            font-size: 1.2rem;
            margin-bottom: 2px;
        }

        @media (max-width: 768px) {
            .mobile-header {
                display: flex;
            }
            
            #sidebar-wrapper {
                display: none;
            }
            
            .navbar {
                display: none !important;
            }

            #page-content-wrapper {
                margin-left: 0;
                padding-top: 110px; /* Adjust for taller header */
            }
        }
        /* Compact Progress Circle */
        .progress-circle-sm {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: conic-gradient(var(--accent-color) 0% var(--progress), #e0e0e0 var(--progress) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            flex-shrink: 0;
        }
        .progress-circle-sm::before {
            content: '';
            position: absolute;
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: white;
            z-index: 1;
        }
        .progress-circle-sm span {
            z-index: 2;
            font-size: 1.2rem;
        }
    </style>
    <style>
        body {
            opacity: 0;
            animation: rservePageFadeIn 520ms ease forwards;
            transform: none !important;
        }

        @keyframes rservePageFadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .rserve-page-loader {
            position: fixed;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(rgba(13, 61, 97, 0.92), rgba(29, 110, 160, 0.88));
            z-index: 99999;
            opacity: 1;
            transition: opacity 360ms ease;
        }

        .rserve-page-loader.rserve-page-loader--hide {
            opacity: 0;
        }

        .rserve-page-loader__inner {
            width: min(420px, 90vw);
            padding: 22px 18px;
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.10);
            box-shadow: 0 16px 40px rgba(0,0,0,0.35);
            text-align: center;
            backdrop-filter: blur(8px);
        }

        .rserve-page-loader__brand {
            font-weight: 800;
            letter-spacing: 0.4px;
            color: rgba(255, 255, 255, 0.95);
            margin-bottom: 10px;
            font-size: 1.15rem;
        }

        .rserve-page-loader__spinner {
            width: 42px;
            height: 42px;
            border-radius: 999px;
            border: 4px solid rgba(255, 255, 255, 0.25);
            border-top-color: rgba(255, 255, 255, 0.95);
            margin: 0 auto 12px;
            animation: rserveSpin 900ms linear infinite;
        }

        .rserve-page-loader__text {
            color: rgba(255, 255, 255, 0.92);
            font-weight: 600;
            font-size: 0.95rem;
        }

        @keyframes rserveSpin {
            to { transform: rotate(360deg); }
        }

        .rserve-view-enter {
            animation: rserveViewSlideUp 420ms cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes rserveViewSlideUp {
            from {
                opacity: 0;
                transform: translateY(24px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (prefers-reduced-motion: reduce) {
            body { animation: none; opacity: 1; }
            .rserve-page-loader { transition: none; }
            .rserve-page-loader__spinner { animation: none; }
            .rserve-view-enter { animation: none; }
        }
    </style>
</head>
<body>

<div id="rserve-page-loader" class="rserve-page-loader" aria-hidden="true">
    <div class="rserve-page-loader__inner">
        <div class="rserve-page-loader__brand">RServeS</div>
        <div class="rserve-page-loader__spinner"></div>
        <div class="rserve-page-loader__text">Loading your dashboard...</div>
    </div>
</div>

<div class="mobile-header d-md-none">
    <div class="mobile-header-top">
        <div class="brand-section">
            <img src="../img/logo.png" alt="RServeS Logo" style="height: 30px; width: auto; margin-right: 8px;">
            <span class="brand-text" style="color: white; font-weight: bold; font-size: 1.2rem;">RServeS</span>
        </div>
        <div class="profile-section" style="cursor: pointer; display: flex; align-items: center;" data-bs-toggle="modal" data-bs-target="#profileModal">
            <span class="text-white me-2 fw-bold" style="font-size: 0.9rem;">Student</span>
            <img src="../<?php echo htmlspecialchars($photo); ?>" alt="Profile" 
                 class="rounded-circle border border-2 border-white" 
                 style="width: 35px; height: 35px; object-fit: cover;">
        </div>
    </div>
    <div class="mobile-header-nav">
        <a href="#" class="nav-item active" onclick="showView('dashboard', this)">
            <i class="fas fa-th-large"></i>
            <span>Dashboard</span>
        </a>
        <a href="#" class="nav-item position-relative" onclick="showView('tasks', this)">
            <i class="fas fa-tasks"></i>
            <span>Tasks</span>
            <?php if(count($tasks) > 0): ?>
                <span class="position-absolute top-0 start-100 translate-middle p-1 bg-danger border border-light rounded-circle" style="width: 10px; height: 10px;"></span>
            <?php endif; ?>
        </a>
// Org nav removed
        <a href="#" class="nav-item" onclick="showView('documents', this)">
            <i class="fas fa-file-alt"></i>
            <span>Docs</span>
        </a>
        
        <!-- Mobile Nav Bell -->
        <div class="dropdown">
            <button class="btn btn-link nav-item position-relative border-0 bg-transparent p-0" type="button" id="mobileNotificationDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="fas fa-bell"></i>
                <span>Notifs</span>
                <?php if ($unread_count > 0): ?>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.5rem; transform: translate(-5px, 0px)!important;">
                        <?= $unread_count > 9 ? '9+' : $unread_count ?>
                    </span>
                <?php endif; ?>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow border-0" aria-labelledby="mobileNotificationDropdown" style="width: 280px; max-height: 80vh; overflow-y: auto;">
                <li class="d-flex justify-content-between align-items-center px-3 py-2">
                    <h6 class="dropdown-header p-0 m-0">Notifications</h6>
                    <?php if ($unread_count > 0): ?>
                        <button class="btn btn-sm btn-primary py-0 px-2" style="font-size: 0.75rem;" onclick="markAllAsRead()">Mark all as read</button>
                    <?php endif; ?>
                </li>
                <li><hr class="dropdown-divider m-0"></li>
                <?php if (empty($notifications)): ?>
                    <li><a class="dropdown-item text-muted text-center small py-3" href="#">No new notifications</a></li>
                <?php else: ?>
                    <?php foreach ($notifications as $notif): ?>
                        <li>
                            <a class="dropdown-item d-flex align-items-start py-2 border-bottom" href="#" onclick="<?= $notif['link'] ?>">
                                <div class="me-2 mt-1">
                                    <?php if($notif['type'] === 'task'): ?>
                                        <i class="fas fa-tasks text-primary"></i>
                                    <?php elseif($notif['type'] === 'certificate'): ?>
                                        <i class="fas fa-certificate text-warning"></i>
                                    <?php endif; ?>
                                </div>
                                <div style="white-space: normal; line-height: 1.3;">
                                    <div class="small fw-bold"><?= htmlspecialchars($notif['message']) ?></div>
                                    <div class="text-muted" style="font-size: 0.75rem;"><?= date('M d, h:i A', strtotime($notif['date'])) ?></div>
                                </div>
                                <?php if(!$notif['is_read']): ?>
                                    <span class="ms-auto mt-2 bg-primary rounded-circle" style="width: 8px; height: 8px;"></span>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                    <li><a class="dropdown-item text-center small text-primary fw-bold py-2" href="#" onclick="showView('notifications', null)">View All Notifications</a></li>
                <?php endif; ?>
            </ul>
        </div>

        <a href="logout.php" class="nav-item">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
    </div>
</div>

<div class="d-flex" id="wrapper">
    <!-- Sidebar -->
    <div id="sidebar-wrapper">
        <div class="sidebar-heading">
            <i class="fas"></i> <img src="../img/logo.png" alt="RServeS Logo" style="width: 40px; height: auto;"> RServeS
        </div>
        <div class="list-group list-group-flush">
            <a href="#" class="list-group-item list-group-item-action active" onclick="showView('dashboard', this)">
                <i class="fas fa-th-large"></i> Dashboard
            </a>
            <a href="#" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" onclick="showView('tasks', this)">
                <span><i class="fas fa-tasks"></i> Tasks</span>
                <?php if(count($tasks) > 0): ?>
                    <span class="badge bg-danger rounded-pill"><?= count($tasks) ?></span>
                <?php endif; ?>
            </a>
<!-- Org sidebar link removed -->
            <a href="#" class="list-group-item list-group-item-action" onclick="showView('documents', this)">
                <i class="fas fa-file-alt"></i> Documents
            </a>
            <a href="#" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" onclick="showView('notifications', this)">
                <span><i class="fas fa-bell"></i> Notifications</span>
                <?php if ($unread_count > 0): ?>
                    <span class="badge bg-danger rounded-pill"><?= $unread_count ?></span>
                <?php endif; ?>
            </a>
            <a href="#" class="list-group-item list-group-item-action" data-bs-toggle="modal" data-bs-target="#profileModal">
                <i class="fas fa-user-circle"></i> Profile
            </a>
            <a href="logout.php" class="list-group-item list-group-item-action mt-auto">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>

    <!-- Page Content -->
    <div id="page-content-wrapper">
        <nav class="navbar navbar-expand-lg navbar-dark">

            <div class="ms-auto d-flex align-items-center">
                
                <div class="me-3 text-end d-none d-md-block">
                    <div class="fw-bold text-white"><?php echo htmlspecialchars($fullname); ?></div>
                    <small class="text-white-50">Student</small>
                </div>
                <img src="../<?php echo htmlspecialchars($photo); ?>" alt="Profile" class="rounded-circle border border-2 border-white" style="width: 40px; height: 40px; object-fit: cover; cursor: pointer;" data-bs-toggle="modal" data-bs-target="#profileModal">
            </div>
        </nav>

        <div class="container-fluid" id="main-content">
            
            <!-- Flash Message -->
            <?php if (isset($_SESSION['flash'])): ?>
                <?php
                    $flash_message = (string) $_SESSION['flash'];
                    $flash_is_error = stripos($flash_message, 'failed') !== false
                        || stripos($flash_message, 'expired') !== false
                        || stripos($flash_message, 'please ') === 0;
                ?>
                <div class="alert alert-<?= $flash_is_error ? 'danger' : 'success' ?> alert-dismissible fade show mb-4" role="alert">
                    <i class="fas <?= $flash_is_error ? 'fa-exclamation-circle' : 'fa-check-circle' ?> me-2"></i><?= htmlspecialchars($flash_message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['flash']); ?>
            <?php endif; ?>

            <!-- DASHBOARD VIEW -->
            <div id="view-dashboard">
                <div class="rserve-dashboard-scroll">
                <div class="card"> 
                    <h3><i class="fas fa-hand me-1"></i>Greetings! Hello, <?= htmlspecialchars($student['firstname']) ?>! </h3> 
                    <p class="text-muted mb-3"><i class="fas fa-chalkboard-teacher me-2"></i>Adviser: <strong><?= htmlspecialchars($adviser_name) ?></strong></p>
                    
                    <div class="instructions" id="instructions"> 
                    <p><strong><i class="fas fa-info-circle me-2"></i>Instructions:</strong></p> 
                    <p>Students must complete <strong>320 hours</strong> of Return Service System (RSS) work, distributed as <strong>53.33 hours per semester</strong>. You must finish all required hours before your 4th year.</p> 
                    <p><strong>Attendance Rules:</strong></p> 
                    <ul> 
                        <li><strong>Morning Session:</strong> Time in between 8:00 AM - 8:30 AM, Time out at 12:00 PM (4 hours max). Late arrivals reduce hours proportionally.</li> 
                        <li><strong>Afternoon Session:</strong> Time in between 1:00 PM - 1:30 PM, Time out at 5:00 PM (4 hours max). Late arrivals reduce hours proportionally.</li> 
                        <li><strong>Full Day:</strong> Time in between 8:00 AM - 8:30 AM, Time out at 5:00 PM (8 hours max). Late arrivals reduce hours proportionally.</li> 
                        <li>You can time in anytime, but late arrivals will have hours deducted.</li> 
                    </ul> 
                    <p>Track your progress below. Hours are automatically calculated based on your time in and will be added after adviser approval.</p> 
                    </div> 
                    <span class="see-more" onclick="toggleInstructions()">See More ▼</span> 
                </div>

                <h2 class="mb-4">Dashboard Overview</h2>
                
                <div class="row g-4 mb-5">
                    <!-- Progress Card -->
                    <div class="col-md-6">
                        <div class="stat-card text-center h-100">
                            <br>
                            <br>
                            <div class="progress-circle mx-auto" style="--progress: <?= $progress_percent ?>%;">
                                <span><?= number_format($progress_percent, 1) ?>%</span>
                            </div>
                            <h5>Progress</h5>
                            <p class="text-muted mb-0" style="line-height: 1.15;"><strong><?= number_format($total_hours_completed, 2) ?></strong> / <?= $required_hours ?> hours</p>
                            <small class="text-primary d-block mb-3"><?= $progress_message ?></small>
                            
                            <div class="d-flex justify-content-center align-items-center gap-2 mt-3">
                                <span class="text-muted small"><i class="fas me-1"></i>Active Tasks</span>
                                <span class="badge bg-danger rounded-pill"><?= count($tasks) ?></span>
<!-- Pending Orgs badge removed -->
                            </div>
                        </div>
                    </div>

                    <!-- Time In/Out Section -->
                    <div class="col-md-6">
                        <div class="content-card h-100 mb-0">
                            <h4 class="mb-4"><i class="fas fa-clock me-2"></i>Attendance</h4>
                            <div class="alert alert-info mb-4">
                                <strong><i class="fas fa-info-circle me-2"></i>Schedule:</strong> Morning (8:00-12:00) | Afternoon (1:00-5:00)<br>
                                <small>Late arrivals will have hours deducted automatically.</small>
                            </div>

                            <form method="POST" id="attendanceForm" class="row g-3">
                                <div class="col-12">
                                    <label class="form-label fw-bold">Date</label>
                                    <input type="date" name="work_date" class="form-control" value="<?= date('Y-m-d') ?>" readonly>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-bold">Session</label>
                                    <select name="session" id="sessionSelect" class="form-select" required>
                                        <option value="">Select Session</option>
                                        <option value="morning">Morning (8:00 AM - 12:00 PM)</option>
                                        <option value="afternoon">Afternoon (1:00 PM - 5:00 PM)</option>
                                        <option value="fullday">Full Day (8:00 AM - 5:00 PM)</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-bold">Time In</label>
                                    <input type="time" name="time_in" id="timeIn" class="form-control" readonly>
                                </div>
                                
                                <input type="hidden" name="calculated_hours" id="calculatedHoursHidden">
                                
                                <div class="col-12 mt-4 d-flex align-items-center justify-content-between">
                                    <div class="mb-0 text-primary fw-bold">
                                        Calculated Hours: <strong><span id="hoursDisplay">0.00</span></strong>
                                    </div>
                                    <button type="submit" name="submit_time" id="submitBtn" class="btn btn-primary btn-sm px-3" disabled>
                                        <i class="fas fa-check me-2"></i>Submit
                                    </button>
                                </div>
                                <div class="col-12">
                                    <div id="lateStatusMsg" style="display: none;"></div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                </div>
            </div>

            <!-- NOTIFICATIONS VIEW -->
            <div id="view-notifications" class="d-none">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>Notifications</h2>
                    <?php if ($unread_count > 0): ?>
                        <button class="btn btn-outline-primary btn-sm" onclick="markAllAsRead()">
                            <i class="fas fa-check-double me-1"></i> Mark all as read
                        </button>
                    <?php endif; ?>
                </div>
                <div class="row">
                    <div class="col-12">
                        <div class="content-card">
                            <div class="rserve-notifications-scroll">
                            <?php 
                            // Group notifications by week
                            $grouped_notifications = [];
                            $current_time = time();

                            if (!empty($notifications)) {
                                foreach ($notifications as $notif) {
                                    $notif_time = strtotime($notif['date']);
                                    $diff_seconds = $current_time - $notif_time;
                                    $diff_days = floor($diff_seconds / (60 * 60 * 24));
                                    
                                    if ($diff_days < 7) {
                                        $group_label = "This Week";
                                    } elseif ($diff_days < 14) {
                                        $group_label = "Last Week";
                                    } else {
                                        $weeks_ago = floor($diff_days / 7);
                                        $group_label = "$weeks_ago Weeks Ago";
                                    }
                                    
                                    $grouped_notifications[$group_label][] = $notif;
                                }
                            }
                            ?>

                            <?php if (empty($grouped_notifications)): ?>
                                <div class="text-center py-5 text-muted">
                                    <i class="fas fa-bell-slash fa-3x mb-3"></i>
                                    <p>No new notifications.</p>
                                </div>
                            <?php else: ?>
                                <div class="accordion" id="notificationsAccordion">
                                    <?php $acc_index = 0; ?>
                                    <?php foreach ($grouped_notifications as $group => $notifs): ?>
                                        <div class="accordion-item">
                                            <h2 class="accordion-header" id="heading<?= $acc_index ?>">
                                                <button class="accordion-button <?= $acc_index === 0 ? '' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?= $acc_index ?>" aria-expanded="<?= $acc_index === 0 ? 'true' : 'false' ?>" aria-controls="collapse<?= $acc_index ?>">
                                                    <?= htmlspecialchars($group) ?>
                                                </button>
                                            </h2>
                                            <div id="collapse<?= $acc_index ?>" class="accordion-collapse collapse <?= $acc_index === 0 ? 'show' : '' ?>" aria-labelledby="heading<?= $acc_index ?>" data-bs-parent="#notificationsAccordion">
                                                <div class="accordion-body p-0">
                                                    <div class="list-group list-group-flush">
                                                        <?php foreach ($notifs as $notif): ?>
                                                            <a href="#" class="list-group-item list-group-item-action p-3" onclick="<?= $notif['link'] ?>">
                                                                <div class="d-flex w-100 justify-content-between align-items-center mb-1">
                                                                    <div class="d-flex align-items-center">
                                                                        <?php if($notif['type'] === 'task'): ?>
                                                                            <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px;">
                                                                                <i class="fas fa-tasks"></i>
                                                                            </div>
                                                                            <div>
                                                                                <h6 class="mb-0 fw-bold">New Task Assigned</h6>
                                                                                <small class="text-muted">Task</small>
                                                                            </div>
                                                                        <?php elseif($notif['type'] === 'certificate'): ?>
                                                                            <div class="bg-warning text-white rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px;">
                                                                                <i class="fas fa-certificate"></i>
                                                                            </div>
                                                                            <div>
                                                                                <h6 class="mb-0 fw-bold">Certificate Issued</h6>
                                                                                <small class="text-muted">Certificate</small>
                                                                            </div>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                    <small class="text-muted"><?= date('M d, h:i A', strtotime($notif['date'])) ?></small>
                                                                </div>
                                                                <p class="mb-1 ms-5 ps-2 text-dark"><?= htmlspecialchars($notif['message']) ?></p>
                                                            </a>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php $acc_index++; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TASKS VIEW -->
            <div id="view-tasks" class="d-none">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>My Tasks</h2>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#verbalTaskModal">
                        <i class="fas fa-plus me-2"></i>Create Verbal Task
                    </button>
                </div>

                <div class="content-card">
                    <?php if (empty($tasks)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fas fa-clipboard-list fa-3x mb-3"></i>
                            <p>No tasks assigned yet.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($tasks as $task): ?>
                            <?php 
                            $isVerbal = !empty($task['is_verbal']);
                            $arAttempts = intval($task['ar_attempts'] ?? 0);
                            $displayStatus = (string)($task['display_status'] ?? ($task['status'] ?? 'Pending'));
                            $disableSubmit = !empty($task['disable_submit']);
                            $isJustCreated = ($newly_created_task_id > 0 && intval($task['stask_id']) === $newly_created_task_id);
                            ?>
                            <div class="task-item<?= $isJustCreated ? ' task-item-new' : '' ?>" data-task-row-id="<?= $task['stask_id'] ?>">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <h5 class="fw-bold mb-1"><?= htmlspecialchars($task['title']) ?></h5>
                                        <?php if ($isVerbal): ?>
                                            <span class="badge bg-info">Verbal</span>
                                            <?php if (!empty($task['inst_fname'])): ?>
                                                <span class="badge bg-primary" title="Assigned by Instructor"><i class="fas fa-user-tie me-1"></i><?= htmlspecialchars($task['inst_fname'] . ' ' . $task['inst_lname']) ?></span>
                                            <?php endif; ?>
                                            
                                            <?php if ($displayStatus !== 'Completed' && empty($task['duration'])): ?>
                                                <select class="form-select form-select-sm d-inline-block w-auto ms-1 task-duration-auto" data-stask-id="<?= $task['stask_id'] ?>" style="padding-top: 0; padding-bottom: 0; height: 22px; font-size: 0.75rem;">
                                                    <option value="">Select Duration</option>
                                                    <option value="Within a Day">Within a Day</option>
                                                    <option value="Within a Week">Within a Week</option>
                                                    <option value="Within a Month">Within a Month</option>
                                                </select>
                                            <?php else: ?>
                                                 <span class="badge bg-secondary ms-1"><?= htmlspecialchars($task['duration'] ?? 'No Duration') ?></span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <?php if (!empty($task['inst_fname'])): ?>
                                                <span class="badge bg-success" title="Assigned by Instructor"><i class="fas fa-user-tie me-1"></i><?= htmlspecialchars($task['inst_fname'] . ' ' . $task['inst_lname']) ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-success">Adviser Assigned</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php if($displayStatus == 'Pending Approval'): ?>
                                            <span class="badge bg-warning text-dark ms-1">Pending Approval</span>
                                        <?php elseif($displayStatus == 'Rejected'): ?>
                                             <span class="badge bg-danger ms-1">Rejected</span>
                                        <?php elseif($displayStatus == 'Completed'): ?>
                                             <span class="badge bg-success ms-1">Completed</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark ms-1"><?= htmlspecialchars($displayStatus) ?></span>
                                        <?php endif; ?>
                                        <?php if ($arAttempts > 0): ?>
                                            <span class="badge bg-secondary ms-1"><?= intval($arAttempts) ?>/2</span>
                                        <?php endif; ?>
                                        <?php if ($isJustCreated): ?>
                                            <span class="badge bg-warning text-dark ms-1">Just Created</span>
                                        <?php endif; ?>
                                        <small class="text-muted ms-2"><?= date('M d, Y h:i A', strtotime($task['created_at'])) ?></small>
                                    </div>
                                    <?php if ($displayStatus !== 'Completed' && !$disableSubmit): ?>
                                        <button class="btn btn-sm btn-outline-success" onclick="submitToAccomplishment(<?= $task['stask_id'] ?>, '<?= htmlspecialchars($task['title'], ENT_QUOTES) ?>')">
                                            <i class="fas fa-upload me-1"></i> <?= $displayStatus === 'Rejected' ? 'Resubmit' : 'Submit' ?>
                                        </button>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if ($displayStatus !== 'Completed'): ?>
                                    <textarea class="form-control mt-2 task-description-auto" 
                                              placeholder="Describe your work (auto-saved)..."
                                              rows="2"
                                              data-stask-id="<?= $task['stask_id'] ?>"><?= htmlspecialchars($task['description'] ?: '') ?></textarea>
                                <?php else: ?>
                                    <p class="mt-2 text-muted"><?= htmlspecialchars($task['description'] ?: 'No description') ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

<!-- Organizations View Removed -->

            <!-- DOCUMENTS VIEW -->
            <div id="view-documents" class="d-none">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>Documents</h2>
                </div>

                <div class="row g-4">
                    <div class="col-md-4">
                        <div class="content-card h-100 text-center p-4 hover-shadow transition-all d-flex flex-column align-items-center justify-content-center">
                            <div class="mb-3 text-primary">
                                <i class="fas fa-file-invoice fa-3x"></i>
                            </div>
                            <h5 class="card-title fw-bold">Accomplishment Report</h5>
                            <p class="card-text text-muted mb-4">View and manage your accomplishment reports.</p>
                            <a href="documents/ar.php" class="btn btn-outline-primary mt-auto stretched-link">View Report</a>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Modals -->

<!-- Profile Modal -->
<div class="modal fade" id="profileModal" tabindex="-1" style="z-index: 10000;">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">My Profile</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <img src="../<?php echo htmlspecialchars($photo); ?>" class="rounded-circle mb-3 border border-3 border-primary" style="width: 120px; height: 120px; object-fit: cover;">
                <h4><?php echo htmlspecialchars($fullname); ?></h4>
                <p class="text-muted mb-0"><?php echo htmlspecialchars($email); ?></p>
                <p class="text-muted small"><?php echo htmlspecialchars($college_name); ?></p>
                
                <form action="<?php echo $_SERVER['PHP_SELF']; ?>" method="POST" enctype="multipart/form-data" class="mt-4 text-start">
                    <div class="mb-3">
                        <label class="form-label">Change Profile Photo</label>
                        <input type="file" name="profilePhoto" class="form-control" accept="image/*" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Upload New Photo</button>
                </form>

                <hr class="my-4">
                
                <h5 class="fw-bold text-start mb-3">Enrollment Overview</h5>
                <?php if ($enrollment_data): ?>
                    <div class="text-start mb-3 bg-light p-3 rounded">
                        <p class="mb-1"><strong>Course:</strong> <?php echo htmlspecialchars($enrollment_data['course']); ?></p>
                        <p class="mb-1"><strong>Major:</strong> <?php echo htmlspecialchars($enrollment_data['major']); ?></p>
                        <p class="mb-1"><strong>Year Level:</strong> <?php echo htmlspecialchars($enrollment_data['year_level']); ?></p>
                        <p class="mb-1"><strong>RSS Assignment:</strong> <?php echo htmlspecialchars($enrollment_data['rss_assignment'] ?? 'Not assigned'); ?></p>
                    </div>
                    <a href="enrollment_update.php" class="btn btn-outline-primary w-100">
                        <i class="fas fa-edit me-1"></i> Update Enrollment
                    </a>
                <?php else: ?>
                    <div class="alert alert-info text-start">
                        <small>You haven't submitted your enrollment form yet.</small>
                    </div>
                    <a href="enrolment.php" class="btn btn-primary w-100">
                        <i class="fas fa-file-contract me-1"></i> Submit Enrollment
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Create Verbal Task Modal -->
<div class="modal fade" id="verbalTaskModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create Verbal Task</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="verbalTaskForm">
                <input type="hidden" name="create_verbal_task" value="1">
                <input type="hidden" name="task_form_token" value="<?= htmlspecialchars($student_task_form_token) ?>">
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i> Tasks assigned verbally by your adviser.
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Select Task Category</label>
                        <div class="mb-3">
                            <select id="mainCategorySelect" class="form-select mb-2">
                                <option value="">-- Select RSS Activity Type --</option>
                                <option value="School-Related Activities">A. School-Related Activities</option>
                                <option value="Community-Based Activities">B. Community-Based Activities</option>
                                <option value="Other Acceptable Activities">C. Other Acceptable Activities</option>
                            </select>

                            <!-- Sub-categories for School-Related -->
                            <select id="schoolRelatedSelect" class="form-select d-none sub-category-select mb-2">
                                <option value="">-- Select School-Related Activity --</option>
                                <option value="Clerical and Administrative Tasks">Clerical and Administrative Tasks</option>
                                <option value="Facilities Maintenance">Facilities Maintenance</option>
                                <option value="IT and Technical Services">IT and Technical Services</option>
                                <option value="School Environment and Sustainability">School Environment and Sustainability</option>
                                <option value="Academic Support">Academic Support</option>
                                <option value="Student Organization and Campus Activities">Student Organization and Campus Activities</option>
                            </select>

                            <!-- Sub-categories for Community-Based -->
                            <select id="communityBasedSelect" class="form-select d-none sub-category-select mb-2">
                                <option value="">-- Select Community-Based Activity --</option>
                                <option value="Public Area Clean-up and Beautification">Public Area Clean-up and Beautification</option>
                                <option value="Educational Support">Educational Support</option>
                                <option value="Health and Safety Campaigns">Health and Safety Campaigns</option>
                                <option value="Government or NGO Assistance">Government or NGO Assistance</option>
                            </select>

                            <!-- Sub-categories for Other Acceptable -->
                            <select id="otherAcceptableSelect" class="form-select d-none sub-category-select mb-2">
                                <option value="">-- Select Other Acceptable Activity --</option>
                                <option value="Participation in research or extension projects">Participation in research or extension projects</option>
                                <option value="Content creation for museum and school publication or media">Content creation for museum and school publication or media</option>
                                <option value="Student assistantship for school clinics or guidance offices">Student assistantship for school clinics or guidance offices</option>
                                <option value="Cultural or artistic contribution">Cultural or artistic contribution</option>
                            </select>
                        </div>

                        <input type="hidden" name="task_title" id="selectedTaskTitle" required>
                        <div id="selectedTaskDisplay" class="alert alert-success d-none">
                            Selected: <strong id="selectedTaskName"></strong>
                            <button type="button" class="btn-close float-end" id="clearTaskBtn"></button>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Assigned By <span class="text-danger">*</span></label>
                        <select name="assigner_id" class="form-select" required>
                            <option value="">Select Instructor</option>
                            <?php foreach ($instructors_list as $inst): ?>
                                <option value="<?= $inst['inst_id'] ?>" <?= ($inst['inst_id'] == $student['instructor_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($inst['firstname'] . ' ' . $inst['lastname']) ?> 
                                    <?= ($inst['inst_id'] == $student['instructor_id']) ? '(Adviser)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Duration</label>
                        <select name="duration" class="form-select" required>
                            <option value="">Select Duration</option>
                            <option value="Within a Day">Within a Day</option>
                            <option value="Within a Week">Within a Week</option>
                            <option value="Within a Month">Within a Month</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="task_description" class="form-control" rows="3" placeholder="What did you do?"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_verbal_task" class="btn btn-primary" id="submitTaskBtn" disabled>Create Task</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Org Task Modal Removed -->

<!-- Add Accomplishment Modal -->
<?php if (!in_array($_SESSION['role'], ['Coordinator', 'Instructor'])): ?>
<div class="modal fade" id="addAccomplishmentModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">➕ Submit to Adviser</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" enctype="multipart/form-data">
        <!-- Hidden input for task ID -->
        <input type="hidden" name="prefill_stask_id" id="modal_prefill_stask_id">
        
        <div class="modal-body">
          <div class="card border-warning mb-3 d-none" id="submitConfirmCard">
            <div class="card-body">
              <div class="fw-bold mb-2">Submit Task now?</div>
              <div class="text-muted small mb-3">Please double-check your task title, description, time, and photos before submitting.</div>
              <div class="d-flex gap-2 justify-content-end">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="confirmCancelBtn">Review</button>
                <button type="button" class="btn btn-warning btn-sm" id="confirmSubmitBtn">Submit now</button>
              </div>
            </div>
          </div>
          <div class="row mb-3">
            <div class="col-md-6">
              <label class="form-label">Date of Work <span class="text-danger">*</span></label>
              <input type="date" name="work_date" id="work-date-input" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Documentation 1 (Before)</label>
              <input type="file" name="photo" class="form-control mb-3" accept="image/*">

              <label class="form-label">Documentation 2 (After)</label>
              <input type="file" name="photo2" class="form-control" accept="image/*">
            </div>
          </div>
          
          <div class="mb-3">
            <label class="form-label">Task Title <span class="text-muted">(Optional)</span></label>
            <input type="text" name="task_title" id="modal_task_title" class="form-control mb-2" 
                   placeholder="e.g., Task 1">
            
            <label class="form-label">Activity Description <span class="text-danger">*</span></label>
            <textarea name="activity" id="modal_activity" class="form-control" rows="3" 
                      placeholder="e.g., Gardening (1st Phase)" required></textarea>
          </div>
          
          <div class="row mb-3">
            <div class="col-md-4">
              <label class="form-label">Time Started <span class="text-danger">*</span></label>
              <input type="time" name="time_start" class="form-control acc-time-start" value="<?= htmlspecialchars($time_in_today ?? '') ?>" readonly>
            </div>
            <div class="col-md-4">
              <label class="form-label">Time Ended <span class="text-danger req-star">*</span></label>
              <input type="time" name="time_end" class="form-control acc-time-end" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">No. of Hours <span class="text-danger req-star">*</span></label>
              <input type="text" name="hours" class="form-control acc-hours" readonly 
                     style="background: #e8f5e9; font-weight: bold;" required>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="add_accomplishment" class="btn btn-custom">✓ Submit to Adviser</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>


<script>


    // Toggle Instructions
    function toggleInstructions() {
        const instructions = document.getElementById('instructions');
        const seeMoreBtn = document.querySelector('.see-more');
        instructions.classList.toggle('show');
        if (instructions.classList.contains('show')) {
            seeMoreBtn.textContent = 'See Less ▲';
        } else {
            seeMoreBtn.textContent = 'See More ▼';
        }
    }

    function animateView(viewElement) {
        if (!viewElement) return;
        viewElement.classList.remove('rserve-view-enter');
        void viewElement.offsetWidth;
        viewElement.classList.add('rserve-view-enter');
    }

    // Close sidebar when any sidebar link is clicked (Mobile)
    document.querySelectorAll('#sidebar-wrapper .list-group-item').forEach(item => {
        item.addEventListener('click', () => {
            if (window.innerWidth <= 768) {
                document.body.classList.remove('sidebar-toggled');
            }
        });
    });

    // View Management
    function showView(viewName, linkElement) {
        // Update Sidebar Active State
        if(linkElement) {
            document.querySelectorAll('.list-group-item, .mobile-header-nav .nav-item').forEach(el => el.classList.remove('active'));
            linkElement.classList.add('active');
        }

        // Hide all views
        document.getElementById('view-dashboard').classList.add('d-none');
        document.getElementById('view-tasks').classList.add('d-none');
// Org view removed
        document.getElementById('view-documents').classList.add('d-none');
        document.getElementById('view-notifications').classList.add('d-none');

        // Show target view
        const targetView = document.getElementById('view-' + viewName);
        targetView.classList.remove('d-none');
        animateView(targetView);

        try {
            localStorage.setItem('rserve_last_view', viewName);
        } catch (e) {}

        if (!linkElement) {
            const allLinks = document.querySelectorAll('.list-group-item, .mobile-header-nav .nav-item');
            allLinks.forEach(el => el.classList.remove('active'));
            let matched = null;
            document.querySelectorAll('#sidebar-wrapper .list-group-item').forEach(a => {
                const oc = a.getAttribute('onclick') || '';
                if (oc.includes(`'${viewName}'`)) matched = a;
            });
            if (!matched) {
                document.querySelectorAll('.mobile-header-nav .nav-item').forEach(a => {
                    const oc = a.getAttribute('onclick') || '';
                    if (oc.includes(`'${viewName}'`)) matched = a;
                });
            }
            if (matched) matched.classList.add('active');
        }
    }

    /* ===== ATTENDANCE LOGIC ===== */
    const initialViewName = <?= json_encode($initial_view) ?>;
    const newlyCreatedTaskId = <?= json_encode($newly_created_task_id) ?>;
    document.addEventListener('DOMContentLoaded', () => {
        if (initialViewName && document.getElementById('view-' + initialViewName)) {
            showView(initialViewName, null);
        } else {
            try {
                const last = localStorage.getItem('rserve_last_view');
                if (last && document.getElementById('view-' + last)) {
                    showView(last, null);
                }
            } catch (e) {}
        }

        if (newlyCreatedTaskId) {
            window.requestAnimationFrame(() => {
                const createdTaskRow = document.querySelector(`[data-task-row-id="${newlyCreatedTaskId}"]`);
                if (createdTaskRow) {
                    createdTaskRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            });
        }
    });
    const sessionSelect = document.getElementById('sessionSelect');
    const timeIn = document.getElementById('timeIn');
    const calculatedHoursHidden = document.getElementById('calculatedHoursHidden');
    const hoursDisplay = document.getElementById('hoursDisplay');
    const submitBtn = document.getElementById('submitBtn');
    const todaysRecords = <?= json_encode($todays_records) ?>;
    const attendanceLockedForToday = <?= json_encode($attendance_locked_for_today) ?>;
    const dtrCutoffReached = <?= json_encode($is_after_dtr_cutoff) ?>;

    function getPhilippineTime() {
        const now = new Date();
        const utc = now.getTime() + (now.getTimezoneOffset() * 60000);
        return new Date(utc + (8 * 60 * 60000));
    }

    function getSubmittedAttendance() {
        const entries = Object.entries(todaysRecords || {});
        if (!entries.length) {
            return null;
        }

        const [session, submittedTime] = entries[0];
        return { session, submittedTime };
    }

    function setAttendanceUnavailable(message, alertClass = 'alert alert-secondary mt-2') {
        const statusMsg = document.getElementById('lateStatusMsg');
        const existingAttendance = getSubmittedAttendance();

        if (existingAttendance) {
            sessionSelect.value = existingAttendance.session;
            timeIn.value = existingAttendance.submittedTime;
            hoursDisplay.textContent = 'Submitted';
        } else {
            timeIn.value = '';
            hoursDisplay.textContent = 'Unavailable';
        }

        calculatedHoursHidden.value = '0.00';
        sessionSelect.disabled = true;
        submitBtn.disabled = true;
        submitBtn.textContent = 'DTR Closed';
        submitBtn.classList.remove('btn-primary');
        submitBtn.classList.add('btn-secondary');

        if (statusMsg) {
            statusMsg.innerHTML = message;
            statusMsg.className = alertClass;
            statusMsg.style.display = 'block';
        }
    }

    function calculateAttendanceHours(session, timeInValue) {
        if (!session || !timeInValue) return;
        
        const [hours, minutes] = timeInValue.split(':').map(Number);
        const timeInMinutes = hours * 60 + minutes;
        
        let sessionStart, sessionEnd, maxHours, cutoffTime;
        
        if (session === 'morning') {
            sessionStart = 8 * 60;
            cutoffTime = 8 * 60 + 30;
            sessionEnd = 12 * 60;
            maxHours = 4;
        } else if (session === 'afternoon') {
            sessionStart = 13 * 60;
            cutoffTime = 13 * 60 + 30;
            sessionEnd = 17 * 60;
            maxHours = 4;
        } else if (session === 'fullday') {
            sessionStart = 8 * 60;
            cutoffTime = 8 * 60 + 30;
            sessionEnd = 17 * 60;
            maxHours = 8;
        } else {
            return;
        }
        
        let workedHours = maxHours;
        
        if (timeInMinutes > cutoffTime) {
            const lateMinutes = timeInMinutes - cutoffTime;
            workedHours = Math.max(0, maxHours - (lateMinutes / 60));
        }
        
        if (timeInMinutes < sessionStart) {
            workedHours = maxHours;
        }
        
        hoursDisplay.textContent = workedHours.toFixed(2);
        calculatedHoursHidden.value = workedHours.toFixed(2);
        
        const statusMsg = document.getElementById('lateStatusMsg');
        if (timeInMinutes > cutoffTime) {
            const lateMinutes = timeInMinutes - cutoffTime;
            statusMsg.innerHTML = `<i class="fas fa-exclamation-triangle"></i> Late by ${lateMinutes} minutes. Hours deducted: ${(lateMinutes / 60).toFixed(2)}`;
            statusMsg.className = 'alert alert-warning mt-2';
            statusMsg.style.display = 'block';
        } else {
            statusMsg.style.display = 'none';
        }
    }

    function updateSessionSettings() {
        const session = sessionSelect.value;
        const statusMsg = document.getElementById('lateStatusMsg');

        if (attendanceLockedForToday) {
            const existingAttendance = getSubmittedAttendance();
            const existingSession = existingAttendance ? existingAttendance.session : 'selected';
            const existingTime = existingAttendance ? existingAttendance.submittedTime : '';
            setAttendanceUnavailable(
                `<i class="fas fa-lock me-1"></i> You already timed in for today's <strong>${existingSession}</strong> session${existingTime ? ` at ${existingTime}` : ''}. DTR login is unavailable after the first time-in.`,
                'alert alert-success mt-2'
            );
            return;
        }

        if (dtrCutoffReached) {
            setAttendanceUnavailable(
                '<i class="fas fa-clock me-1"></i> DTR login is unavailable after 5:00 PM.',
                'alert alert-danger mt-2'
            );
            return;
        }

        sessionSelect.disabled = false;

        if (!session) {
            timeIn.value = '';
            hoursDisplay.textContent = '0.00';
            calculatedHoursHidden.value = '0.00';
            submitBtn.disabled = true;
            submitBtn.textContent = 'Submit Attendance';
            submitBtn.classList.add('btn-primary');
            submitBtn.classList.remove('btn-secondary');
            if (statusMsg) {
                statusMsg.style.display = 'none';
            }
            return;
        }

        const phTime = getPhilippineTime();
        const currentHours = phTime.getHours();
        const currentMinutes = phTime.getMinutes();
        const formattedTime = `${String(currentHours).padStart(2,'0')}:${String(currentMinutes).padStart(2,'0')}`;
        
        timeIn.value = formattedTime;
        submitBtn.disabled = false;
        submitBtn.textContent = 'Submit Attendance';
        submitBtn.classList.add('btn-primary');
        submitBtn.classList.remove('btn-secondary');
        calculateAttendanceHours(session, formattedTime);
    }

    setInterval(() => {
        if (attendanceLockedForToday) return;
        if (dtrCutoffReached) {
            updateSessionSettings();
            return;
        }

        const session = sessionSelect.value;
        if (!session) return;
        const phTime = getPhilippineTime();
        const formattedTime = `${String(phTime.getHours()).padStart(2,'0')}:${String(phTime.getMinutes()).padStart(2,'0')}`;
        timeIn.value = formattedTime;
        calculateAttendanceHours(session, formattedTime);
    }, 10000);

    if (sessionSelect) {
        sessionSelect.addEventListener('change', updateSessionSettings);
    }

    updateSessionSettings();

    /* ===== AUTO-SAVE TASK DESC ===== */
    document.querySelectorAll('.task-description-auto').forEach(textarea => {
        let timeout;
        textarea.addEventListener('input', function() {
            clearTimeout(timeout);
            const staskId = this.dataset.staskId;
            const description = this.value;
            timeout = setTimeout(() => {
                fetch('<?= $_SERVER["PHP_SELF"] ?>', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `update_task_desc=1&stask_id=${staskId}&description=${encodeURIComponent(description)}`
                });
            }, 1000);
        });
    });

    /* ===== AUTO-SAVE TASK DURATION ===== */
    document.querySelectorAll('.task-duration-auto').forEach(select => {
        select.addEventListener('change', function() {
            const staskId = this.dataset.staskId;
            const duration = this.value;
            
            fetch('<?= $_SERVER["PHP_SELF"] ?>', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `update_task_duration=1&stask_id=${staskId}&duration=${encodeURIComponent(duration)}`
            })
            .then(response => response.json())
            .then(data => {
                if(data.success) {
                    location.reload();
                }
            });
        });
    });

    function submitToAccomplishment(staskId, title) {
        const taskItem = document.querySelector(`textarea[data-stask-id="${staskId}"]`);
        const description = taskItem ? taskItem.value : '';
        if (!description || description.trim() === '') {
            alert('Please add a description first.');
            return;
        }
        
        // Open the modal instead of redirecting
        if (typeof window.openAccomplishmentModal === 'function') {
            window.openAccomplishmentModal(staskId, title, description);
        } else {
            console.error('Modal function not found');
            alert('Error: Could not open accomplishment modal.');
        }
    }

    // Add Accomplishment Modal Logic
    document.addEventListener('DOMContentLoaded', () => {
      const modalEl = document.getElementById('addAccomplishmentModal');
      if (!modalEl) return;

      const modal = new bootstrap.Modal(modalEl);
      const formEl = modalEl.querySelector('form');
      const confirmCard = document.getElementById('submitConfirmCard');
      const confirmSubmitBtn = document.getElementById('confirmSubmitBtn');
      const confirmCancelBtn = document.getElementById('confirmCancelBtn');
      let allowSubmit = false;

      // Calculation Logic
      modalEl.addEventListener('shown.bs.modal', () => {
        allowSubmit = false;
        if (confirmCard) confirmCard.classList.add('d-none');
        const timeStart = document.querySelector('.acc-time-start');
        const timeEnd   = document.querySelector('.acc-time-end');
        const hours     = document.querySelector('.acc-hours');
        const workDate  = document.querySelector('input[name="work_date"]');

        if (!timeStart || !timeEnd || !hours) return;

        function getWorkDate() {
          if (workDate && workDate.value) return workDate.value;
          const t = new Date();
          return `${t.getFullYear()}-${String(t.getMonth()+1).padStart(2,'0')}-${String(t.getDate()).padStart(2,'0')}`;
        }

        function calculate() {
          if (!timeStart.value || !timeEnd.value) return;
          const date = getWorkDate();
          const start = new Date(`${date}T${timeStart.value}`);
          let end     = new Date(`${date}T${timeEnd.value}`);
          if (end <= start) end.setDate(end.getDate() + 1);
          const diff = (end - start) / 3600000;
          if (!isNaN(diff) && diff > 0) {
            hours.value = diff.toFixed(2);
          }
        }
        timeEnd.addEventListener('input', calculate);
        timeStart.addEventListener('input', calculate);
      });

      if (formEl && confirmCard && confirmSubmitBtn && confirmCancelBtn) {
        formEl.addEventListener('submit', (e) => {
          if (allowSubmit) return;
          e.preventDefault();
          confirmCard.classList.remove('d-none');
        });

        confirmCancelBtn.addEventListener('click', () => {
          confirmCard.classList.add('d-none');
        });

        confirmSubmitBtn.addEventListener('click', () => {
          allowSubmit = true;
          confirmCard.classList.add('d-none');
          const submitter = formEl.querySelector('button[name="add_accomplishment"]');
          if (typeof formEl.requestSubmit === 'function' && submitter) {
            formEl.requestSubmit(submitter);
          } else if (submitter) {
            submitter.click();
          } else {
            formEl.submit();
          }
        });
      }
      
      // Expose function to open modal from Task list
      window.openAccomplishmentModal = function(staskId, title, description) {
          document.getElementById('modal_prefill_stask_id').value = staskId || '';
          document.getElementById('modal_task_title').value = title || '';
          document.getElementById('modal_activity').value = description || '';
          
          // Toggle required fields based on whether it's a task
          const timeEndInput = document.querySelector('.acc-time-end');
          const hoursInput = document.querySelector('.acc-hours');
          const stars = document.querySelectorAll('.req-star');
          
          if (staskId) {
              if(timeEndInput) timeEndInput.removeAttribute('required');
              if(hoursInput) hoursInput.removeAttribute('required');
              stars.forEach(s => s.style.display = 'none');
          } else {
              if(timeEndInput) timeEndInput.setAttribute('required', 'required');
              if(hoursInput) hoursInput.setAttribute('required', 'required');
              stars.forEach(s => s.style.display = 'inline');
          }
          
          modal.show();
      };
    });

/* ===== ORG HOURS CALC REMOVED ===== */

    /* ===== VERBAL TASK MODAL LOGIC (NESTED) ===== */
    const mainCategorySelect = document.getElementById('mainCategorySelect');
    const subCategorySelects = document.querySelectorAll('.sub-category-select');
    const selectedTaskTitle = document.getElementById('selectedTaskTitle');
    const selectedTaskName = document.getElementById('selectedTaskName');
    const selectedTaskDisplay = document.getElementById('selectedTaskDisplay');
    const submitTaskBtn = document.getElementById('submitTaskBtn');
    const verbalTaskForm = document.getElementById('verbalTaskForm');

    if (mainCategorySelect) {
        mainCategorySelect.addEventListener('change', function() {
            const selectedMain = this.value;
            
            // Hide all sub-category selects first
            subCategorySelects.forEach(select => {
                select.classList.add('d-none');
                select.value = ''; // Reset value
            });

            // Show relevant sub-category select
            if (selectedMain === 'School-Related Activities') {
                document.getElementById('schoolRelatedSelect').classList.remove('d-none');
            } else if (selectedMain === 'Community-Based Activities') {
                document.getElementById('communityBasedSelect').classList.remove('d-none');
            } else if (selectedMain === 'Other Acceptable Activities') {
                document.getElementById('otherAcceptableSelect').classList.remove('d-none');
            }

            // Reset final selection if main category changes
            selectedTaskTitle.value = '';
            selectedTaskDisplay.classList.add('d-none');
            submitTaskBtn.disabled = true;
        });
    }

    subCategorySelects.forEach(select => {
        select.addEventListener('change', function() {
            const task = this.value;
            if (task) {
                selectedTaskTitle.value = task;
                selectedTaskName.textContent = task;
                selectedTaskDisplay.classList.remove('d-none');
                submitTaskBtn.disabled = false;
            } else {
                selectedTaskTitle.value = '';
                selectedTaskDisplay.classList.add('d-none');
                submitTaskBtn.disabled = true;
            }
        });
    });

    const clearTaskBtn = document.getElementById('clearTaskBtn');
    if (clearTaskBtn) {
        clearTaskBtn.addEventListener('click', () => {
            mainCategorySelect.value = '';
            subCategorySelects.forEach(select => {
                select.classList.add('d-none');
                select.value = '';
            });
            selectedTaskTitle.value = '';
            selectedTaskDisplay.classList.add('d-none');
            submitTaskBtn.disabled = true;
        });
    }

    if (verbalTaskForm && submitTaskBtn) {
        verbalTaskForm.addEventListener('submit', function() {
            submitTaskBtn.disabled = true;
            submitTaskBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Creating...';
        });
    }

/* ===== ORG MODAL LOGIC REMOVED ===== */

    // Notification Read Status
    window.markAsRead = function(type, id, callback) {
        fetch('mark_notification_read.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ type: type, id: id })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update badges
                const badges = document.querySelectorAll('.badge.bg-danger');
                badges.forEach(badge => {
                    // Try to parse number
                    let text = badge.innerText;
                    let count = parseInt(text);
                    
                    if (!isNaN(count)) {
                        // If it is 9+, we don't know the real count, but usually we just want to hide if it was 1
                        if (text.includes('+')) {
                           // do nothing or maybe decrement if we knew
                        } else {
                            count--;
                            if (count <= 0) {
                                badge.style.display = 'none';
                            } else {
                                badge.innerText = count;
                            }
                        }
                    }
                });
            }
            // Execute callback regardless of success to ensure navigation
            if (callback && typeof callback === 'function') callback();
        })
        .catch((error) => {
            console.error('Error:', error);
            if (callback && typeof callback === 'function') callback();
        });
    };

    window.markAllAsRead = function() {
        fetch('mark_notification_read.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ action: 'mark_all' })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Hide all badges
                const badges = document.querySelectorAll('.badge.bg-danger');
                badges.forEach(badge => {
                    badge.style.display = 'none';
                });
                
                // Hide blue dots (unread indicators)
                const blueDots = document.querySelectorAll('.bg-primary.rounded-circle');
                blueDots.forEach(dot => {
                    if(dot.style.width === '8px') {
                        dot.style.display = 'none';
                    }
                });
                
                // Reload page to refresh state cleanly or just let user continue
                // Ideally we reload or just hide everything. Reloading is safer for consistent state.
                location.reload();
            }
        })
        .catch(error => console.error('Error:', error));
    };


</script>
<script>
    window.addEventListener('load', function() {
        const loader = document.getElementById('rserve-page-loader');
        if (!loader) return;
        loader.classList.add('rserve-page-loader--hide');
        window.setTimeout(() => {
            if (loader && loader.parentNode) loader.parentNode.removeChild(loader);
        }, 420);
    });
</script>
</body>
</html>
