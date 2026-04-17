<?php
// Student Dashboard
// Enable Error Reporting for Debugging (Remove in Production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Force MySQLi to not throw exceptions, so we can handle errors manually
mysqli_report(MYSQLI_REPORT_OFF);

date_default_timezone_set('Asia/Manila');

session_start();
require "dbconnect.php";
include "check_expiration.php";
require_once __DIR__ . "/task_backend.php";
require_once __DIR__ . "/../send_email.php";
rserves_student_ensure_task_schema($conn);

if (empty($_SESSION['student_task_form_token'])) {
    $_SESSION['student_task_form_token'] = bin2hex(random_bytes(16));
}
$student_task_form_token = $_SESSION['student_task_form_token'];

if (!function_exists('rserves_dashboard_view_url')) {
    function rserves_dashboard_view_url(string $view = 'dashboard'): string
    {
        $self = $_SERVER['PHP_SELF'] ?? 'student_dashboard.php';
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
if (!$req_check) {
    die("Prepare failed: (" . $conn->errno . ") " . $conn->error);
}
$req_check->bind_param("iii", $student_id, $student_id, $student_id);
if (!$req_check->execute()) {
    die("Execute failed: (" . $req_check->errno . ") " . $req_check->error);
}
$result = $req_check->get_result();
if (!$result) {
    die("Getting result set failed: (" . $req_check->errno . ") " . $req_check->error);
}
$req_res = $result->fetch_assoc();
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
           s.student_number,
           s.year_level, s.semester, s.section, d.department_name
    FROM students s
    LEFT JOIN departments d ON s.department_id = d.department_id
    WHERE s.stud_id = ?
");

if (!$stmt) {
    die("Database Error (Student Query): " . $conn->error);
}

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
// $display_identity removed - using $fullname and $college_name directly in topbar

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
$student_number = trim((string) ($student['student_number'] ?? ($enrollment_data['student_number'] ?? '')));

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

// Handle add_accomplishment form submission
if (!in_array($_SESSION['role'], ['Coordinator', 'Instructor'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_accomplishment'])) {
        $work_date = trim((string)($_POST['work_date'] ?? ''));
        $activity_check = rserves_student_validate_text_input((string)($_POST['activity'] ?? ''), 'activity description', RSERVES_STUDENT_DESCRIPTION_MAX_CHARS, true);
        if ($activity_check['error'] !== null) {
            $_SESSION['flash'] = $activity_check['error'];
            header("Location: " . rserves_dashboard_view_url('tasks'));
            exit;
        }

        $activity = $activity_check['value'];
        $task_title_check = rserves_student_validate_text_input((string)($_POST['task_title'] ?? ''), 'task title', RSERVES_STUDENT_TASK_TITLE_MAX_CHARS, false);
        if ($task_title_check['error'] !== null) {
            $_SESSION['flash'] = $task_title_check['error'];
            header("Location: " . rserves_dashboard_view_url('tasks'));
            exit;
        }

        if ($task_title_check['value'] !== '') {
            $activity = $task_title_check['value'] . ': ' . $activity;
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

        if (rserves_student_has_same_day_duplicate_report(
            $conn,
            $student_id,
            $work_date,
            intval($linked_student_task_id ?? 0),
            $task_title_check['value']
        )) {
            $_SESSION['flash'] = "This task has already been reported for {$work_date}.";
            header("Location: " . rserves_dashboard_view_url('tasks'));
            exit;
        }
        
        if ($status == 'Pending') {
            $time_end = !empty($time_end) ? $time_end : NULL;
            $hours = $hours > 0 ? $hours : 0;
        }

        // Handle attachment upload
        $uploadDir = __DIR__ . '/uploads/accomplishments/';
        $photo1_upload = rserves_student_save_attachment('photo', $student_id, $uploadDir);
        if ($photo1_upload['error'] !== null) {
            $_SESSION['flash'] = $photo1_upload['error'];
            header("Location: " . rserves_dashboard_view_url('tasks'));
            exit;
        }

        $photo2_upload = rserves_student_save_attachment('photo2', $student_id, $uploadDir);
        if ($photo2_upload['error'] !== null) {
            $_SESSION['flash'] = $photo2_upload['error'];
            header("Location: " . rserves_dashboard_view_url('tasks'));
            exit;
        }

        $photo1 = $photo1_upload['path'];
        $photo2 = $photo2_upload['path'];

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
            if (!empty($assigner_id)) {
                $recipient = rserves_fetch_instructor_email_recipient($conn, intval($assigner_id));
                if ($recipient) {
                    $task_label = $task_title_check['value'] !== '' ? $task_title_check['value'] : 'RSS accomplishment';
                    $body = rserves_notification_build_body(
                        rserves_notification_recipient_name($recipient),
                        "{$fullname} submitted an accomplishment report for your review.",
                        [
                            'Task' => $task_label,
                            'Work Date' => $work_date,
                            'Hours' => number_format($hours, 2),
                            'Activity' => rserves_student_strip_task_tags($activity),
                        ]
                    );
                    rserves_send_bulk_notification_email(
                        [$recipient],
                        "New Accomplishment Submitted: {$task_label}",
                        $body
                    );
                }
            }

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


$required_hours = 320;
$progress_percent = min(($total_hours_completed / $required_hours) * 100, 100);
$progress_message = $progress_percent == 0 ? "Start now! 💪" : "Keep going! You're doing great! 🌟";

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

                            $recipients = array_merge(
                                rserves_fetch_admin_email_recipients($conn),
                                rserves_fetch_coordinator_email_recipients($conn, intval($student['department_id'] ?? 0))
                            );
                            $body = rserves_notification_build_body(
                                'there',
                                "{$fullname} submitted a waiver for verification.",
                                [
                                    'Student ID' => (string) $student_number,
                                    'Department' => (string) ($student['department_name'] ?? 'N/A'),
                                    'Submitted File' => $newName,
                                ]
                            );
                            rserves_send_bulk_notification_email($recipients, 'New Waiver Submission', $body);

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

                            $recipients = array_merge(
                                rserves_fetch_admin_email_recipients($conn),
                                rserves_fetch_coordinator_email_recipients($conn, intval($student['department_id'] ?? 0))
                            );
                            $body = rserves_notification_build_body(
                                'there',
                                "{$fullname} submitted an agreement for verification.",
                                [
                                    'Student ID' => (string) $student_number,
                                    'Department' => (string) ($student['department_name'] ?? 'N/A'),
                                    'Submitted File' => $newName,
                                ]
                            );
                            rserves_send_bulk_notification_email($recipients, 'New Agreement Submission', $body);

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

if (isset($_POST['archive_task']) || isset($_POST['delete_task'])) {
    $stask_id = intval($_POST['stask_id'] ?? 0);
    $target_state = isset($_POST['delete_task']) ? 'deleted' : 'archived';

    if ($stask_id > 0 && rserves_student_update_task_visibility($conn, $student_id, $stask_id, $target_state)) {
        $_SESSION['flash'] = $target_state === 'deleted'
            ? 'Task removed from your active list.'
            : 'Task archived successfully.';
    } else {
        $_SESSION['flash'] = 'Unable to update the selected task.';
    }

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

if (isset($_POST['update_task_desc'])) {
    $stask_id = $_POST['stask_id'];
    $description = rserves_student_normalize_text((string)($_POST['description'] ?? ''));
    $description = rserves_student_text_truncate($description, RSERVES_STUDENT_DESCRIPTION_MAX_CHARS);
    
    $stmt = $conn->prepare("
        UPDATE tasks t 
        INNER JOIN student_tasks st ON t.task_id = st.task_id 
        SET t.description = ? 
        WHERE st.stask_id = ? AND st.student_id = ?
    ");
    $stmt->bind_param("sii", $description, $stask_id, $student_id);
    $stmt->execute();
    $stmt->close();
    
    echo json_encode(['success' => true, 'description' => $description]);
    exit;
}

/* Organization Task Creation Removed */

$tasks = rserves_fetch_student_dashboard_tasks($conn, $student_id, $accomplishment_reports);

/* Org Tasks Fetching Removed */
// Org init removed
// Org counts removed

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

/* Approved Orgs Fetching Removed */
$approved_orgs = [];
/* Org Notification Logic Removed */

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

$announcements = [];
$announcement_table_check = $conn->query("SHOW TABLES LIKE 'student_announcements'");
if ($announcement_table_check instanceof mysqli_result && $announcement_table_check->num_rows > 0) {
    $stmt = $conn->prepare("
        SELECT announcement_id, subject, message, created_at
        FROM student_announcements
        WHERE student_id = ?
        ORDER BY created_at DESC
    ");
    if ($stmt) {
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $announcements[] = $row;
        }
        $stmt->close();
    }
}

// Prepare Notifications
$notifications = [];
foreach ($tasks as $task) {
    $is_read = isset($read_notifications['task_' . $task['task_id']]);
    if ($is_read) {
        continue;
    }

    $notifications[] = [
        'id' => $task['task_id'],
        'key' => 'task-' . $task['task_id'],
        'type' => 'task',
        'message' => 'New Task: ' . $task['title'],
        'date' => $task['created_at'],
        'target_view' => 'tasks',
        'certificate_code' => null
    ];
}
foreach ($announcements as $announcement) {
    $is_read = isset($read_notifications['announcement_' . $announcement['announcement_id']]);
    if ($is_read) {
        continue;
    }

    $notifications[] = [
        'id' => $announcement['announcement_id'],
        'key' => 'announcement-' . $announcement['announcement_id'],
        'type' => 'announcement',
        'message' => $announcement['subject'],
        'details' => $announcement['message'],
        'date' => $announcement['created_at'],
        'target_view' => 'notifications',
        'certificate_code' => null
    ];
}
/* Org Notification Loop Removed */
foreach ($certificates as $cert) {
    $is_read = isset($read_notifications['certificate_' . $cert['certificate_id']]);
    if ($is_read) {
        continue;
    }

    $notifications[] = [
        'id' => $cert['certificate_id'],
        'key' => 'certificate-' . $cert['certificate_id'],
        'type' => 'certificate',
        'message' => 'Certificate Generated: ' . $cert['certificate_code'],
        'date' => $cert['created_at'],
        'target_view' => null,
        'certificate_code' => $cert['certificate_code']
    ];
}

// Sort notifications by date desc
usort($notifications, function($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
});

$unread_count = count($notifications);

$newly_created_task_id = intval($_SESSION['last_created_student_task_id'] ?? 0);
unset($_SESSION['last_created_student_task_id']);

$recent_time_logs = [];
$recent_time_stmt = $conn->prepare("
    SELECT work_date, session, time_in, time_out, hours, status
    FROM time_records
    WHERE student_id = ?
    ORDER BY work_date DESC, created_at DESC
    LIMIT 5
");
if ($recent_time_stmt) {
    $recent_time_stmt->bind_param("i", $student_id);
    $recent_time_stmt->execute();
    $recent_time_result = $recent_time_stmt->get_result();
    while ($row = $recent_time_result->fetch_assoc()) {
        $recent_time_logs[] = $row;
    }
    $recent_time_stmt->close();
}

$hours_remaining = max(0, $required_hours - $total_hours_completed);
$active_task_count = count($tasks);
$completed_milestones = min(4, (int) floor($progress_percent / 25));
$pending_reports_count = count(array_filter($accomplishment_reports, static function ($report) {
    return ($report['status'] ?? '') === 'Pending';
}));
$approved_reports_count = count(array_filter($accomplishment_reports, static function ($report) {
    return ($report['status'] ?? '') === 'Approved';
}));
$recent_activity = array_slice($accomplishment_reports, 0, 5);

$semester_value = trim((string) ($student['semester'] ?? ''));
if ($semester_value === '') {
    $current_session_label = 'Current Academic Session';
} elseif (stripos($semester_value, 'semester') !== false) {
    $current_session_label = $semester_value;
} else {
    $current_session_label = $semester_value . ' Semester';
}

$service_status = 'Awaiting Start';
if ($progress_percent >= 100) {
    $service_status = 'Completed';
} elseif ($total_hours_completed > 0 || $active_task_count > 0 || !empty($recent_time_logs)) {
    $service_status = 'In Progress';
}

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
    <title>Student Dashboard - <?= htmlspecialchars($college_name ?: 'RServeS') ?></title>
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
            box-sizing: border-box;
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
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
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
        .modal-content {
            background: var(--rserve-surface);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid var(--rserve-border);
            box-shadow: var(--rserve-shadow);
            border-radius: 28px;
        }
        .modal-header {
            background: linear-gradient(135deg, var(--rserve-primary) 0%, var(--rserve-primary-deep) 100%);
            color: white;
            border-bottom: 1px solid var(--rserve-border);
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
                padding-top: 100px; /* Adjust for taller header */
                padding-bottom: 20px;
            }
            
            /* Compressed Mobile View */
            .container-fluid {
                padding-left: 10px;
                padding-right: 10px;
            }
            
            /* Hide non-essential elements on mobile to prevent scrolling */
            .card h3, .card .text-muted, .card .instructions, .card .see-more {
                display: none !important;
            }
            .card {
                padding: 10px !important;
                margin-bottom: 10px !important;
                border: none !important;
                background: transparent !important;
                box-shadow: none !important;
            }
            .card::before {
                content: 'Hello, ' attr(data-student-name) '!';
                font-weight: bold;
                font-size: 1.2rem;
                display: block;
                margin-bottom: 5px;
                color: var(--text-dark);
            }
            .card::after {
                content: 'Adviser: ' attr(data-adviser-name);
                font-size: 0.9rem;
                color: #6c757d;
                display: block;
                margin-top: -5px;
                margin-bottom: 10px;
            }
            
            h2.mb-4 { /* Dashboard Overview Title */
                display: none;
            }
            
            .row.g-4 {
                --bs-gutter-y: 0.5rem;
                --bs-gutter-x: 0.5rem;
            }
            
            .stat-card, .content-card {
                padding: 15px !important;
                height: auto !important;
                min-height: 0 !important;
            }
            
            .progress-circle {
                width: 100px !important;
                height: 100px !important;
                margin-bottom: 10px !important;
            }
            
            .progress-circle::before {
                width: 80px !important;
                height: 80px !important;
                /* Ensure it stays centered */
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
            }

            .progress-circle span {
                font-size: 1.2rem !important;
            }
            
            .col-md-6 {
                width: 100%;
            }
            
            /* Compact Form */
            #attendanceForm .form-label {
                font-size: 0.8rem;
                margin-bottom: 2px;
            }
            #attendanceForm .form-control, #attendanceForm .form-select {
                font-size: 0.9rem;
                padding: 5px 10px;
            }
            #attendanceForm .col-12 {
                margin-bottom: 5px;
            }
            #attendanceForm .mt-4 {
                margin-top: 10px !important;
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

        :root {
            --sidebar-width: 292px;
            --primary-color: #153f7a;
            --secondary-color: #0c2e60;
            --accent-color: #153f7a;
            --bg-color: #f6f9fe;
            --text-dark: #0f172a;
            --rserve-primary: #153f7a;
            --rserve-primary-deep: #0c2e60;
            --rserve-primary-soft: #e9f0fb;
            --rserve-surface: #ffffff;
            --rserve-surface-alt: #f6f9fe;
            --rserve-ink: #0f172a;
            --rserve-muted: #64748b;
            --rserve-border: #d9e4f3;
            --rserve-shadow: 0 22px 50px rgba(15, 30, 60, 0.08);
        }

        html,
        body {
            background: linear-gradient(180deg, #f8fbff 0%, #eef3fb 100%);
        }

        body {
            color: var(--rserve-ink);
        }

        body::before {
            background:
                radial-gradient(circle at top left, rgba(21, 63, 122, 0.10), transparent 28%),
                radial-gradient(circle at top right, rgba(58, 142, 189, 0.08), transparent 24%),
                linear-gradient(180deg, #f8fbff 0%, #eef3fb 100%);
            filter: none;
        }

        #sidebar-wrapper {
            background: rgba(248, 250, 253, 0.96);
            border-right: 1px solid var(--rserve-border);
            box-shadow: 10px 0 32px rgba(15, 23, 42, 0.05);
            width: var(--sidebar-width);
            padding: 0;
        }

        #sidebar-wrapper .sidebar-shell {
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
            gap: 22px;
            padding: 28px 20px 24px;
        }

        #sidebar-wrapper .sidebar-heading {
            height: auto;
            padding: 0;
            display: block;
            background: transparent;
            border-bottom: 0;
            color: var(--rserve-primary-deep);
        }

        .sidebar-brand-title {
            display: block;
            font-size: 1.15rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            color: var(--rserve-primary-deep);
            margin-bottom: 0.35rem;
        }

        .sidebar-brand-subtitle {
            display: block;
            font-size: 0.82rem;
            font-weight: 700;
            letter-spacing: 0.24em;
            text-transform: uppercase;
            color: #5d7295;
        }

        #sidebar-wrapper .list-group {
            width: auto;
            gap: 10px;
            flex: 1;
        }

        #sidebar-wrapper .list-group-item {
            background: transparent;
            border: 1px solid transparent;
            color: #516684;
            border-radius: 18px;
            padding: 0.95rem 1rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.85rem;
        }

        #sidebar-wrapper .list-group-item i {
            width: 1.25rem;
            margin-right: 0;
            text-align: center;
        }

        #sidebar-wrapper .list-group-item:hover,
        #sidebar-wrapper .list-group-item.active {
            background: linear-gradient(135deg, rgba(21, 63, 122, 0.10), rgba(21, 63, 122, 0.02));
            color: var(--rserve-primary-deep);
            border-color: rgba(21, 63, 122, 0.14);
            padding-left: 1rem;
            border-left: 1px solid rgba(21, 63, 122, 0.14);
            box-shadow: inset 3px 0 0 var(--rserve-primary);
        }

        .sidebar-student-card {
            padding: 18px;
            border-radius: 26px;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.96), rgba(236, 242, 252, 0.92));
            border: 1px solid var(--rserve-border);
            box-shadow: var(--rserve-shadow);
        }

        .sidebar-student-profile {
            display: flex;
            align-items: center;
            gap: 0.9rem;
            margin-bottom: 1rem;
        }

        .sidebar-student-profile img {
            width: 52px;
            height: 52px;
            object-fit: cover;
            border-radius: 16px;
            border: 2px solid rgba(21, 63, 122, 0.12);
        }

        .sidebar-student-name {
            font-size: 0.98rem;
            font-weight: 800;
            color: var(--rserve-primary-deep);
            line-height: 1.2;
        }

        .sidebar-student-meta {
            font-size: 0.82rem;
            color: var(--rserve-muted);
            margin-top: 0.2rem;
        }

        .sidebar-support-btn {
            width: 100%;
            border: 0;
            border-radius: 16px;
            padding: 0.9rem 1rem;
            font-weight: 800;
            color: #fff;
            background: linear-gradient(135deg, var(--rserve-primary) 0%, var(--rserve-primary-deep) 100%);
            box-shadow: 0 14px 24px rgba(12, 46, 96, 0.22);
        }

        #page-content-wrapper {
            margin-left: var(--sidebar-width);
            background: transparent;
        }

        .navbar {
            background: rgba(255, 255, 255, 0.82);
            color: var(--rserve-ink);
            border-bottom: 1px solid rgba(217, 228, 243, 0.9);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            box-shadow: none;
            padding: 1.25rem 1.9rem 1rem;
            height: auto;
        }

        .topbar-shell {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 1.1rem;
        }

        .topbar-tabs {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            flex-wrap: wrap;
        }

        .topbar-tab {
            position: relative;
            text-decoration: none;
            color: var(--rserve-muted);
            font-weight: 700;
            padding: 0.75rem 0.95rem;
            border-radius: 999px;
        }

        .topbar-tab:hover,
        .topbar-tab.active {
            color: var(--rserve-primary-deep);
            background: rgba(21, 63, 122, 0.08);
        }

        .topbar-tab.active::after {
            content: '';
            position: absolute;
            left: 0.95rem;
            right: 0.95rem;
            bottom: -0.25rem;
            height: 3px;
            border-radius: 999px;
            background: var(--rserve-primary);
        }

        .topbar-actions {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 0.85rem;
        }

        .topbar-secondary-btn,
        .topbar-primary-btn {
            border-radius: 16px;
            padding: 0.85rem 1.2rem;
            font-weight: 800;
            border: 1px solid transparent;
        }

        .topbar-secondary-btn {
            color: var(--rserve-primary-deep);
            background: rgba(21, 63, 122, 0.06);
            border-color: rgba(21, 63, 122, 0.10);
        }

        .topbar-primary-btn {
            color: #fff;
            background: linear-gradient(135deg, var(--rserve-primary) 0%, var(--rserve-primary-deep) 100%);
            box-shadow: 0 16px 26px rgba(12, 46, 96, 0.22);
        }

        .topbar-profile {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            padding: 0.55rem 0.8rem 0.55rem 1rem;
            border-radius: 20px;
            border: 1px solid var(--rserve-border);
            background: rgba(247, 249, 252, 0.96);
            min-width: min(420px, 38vw);
            cursor: pointer;
        }

.topbar-identity {
    flex: 1;
    text-align: right;
    color: var(--rserve-primary-deep);
    line-height: 1.2;
    white-space: normal;
  }
  .topbar-identity > div:first-child {
    font-size: 0.92rem;
    font-weight: 800;
  }
  .topbar-identity > div:last-child {
    font-size: 0.8rem;
  }

        .topbar-avatar {
            width: 46px;
            height: 46px;
            border-radius: 16px;
            object-fit: cover;
            border: 2px solid rgba(21, 63, 122, 0.10);
        }

        .container-fluid {
            padding: 1.65rem 1.9rem 2.25rem;
        }

        .dashboard-hero {
            display: grid;
            grid-template-columns: minmax(0, 1.75fr) minmax(260px, 0.85fr);
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .dashboard-hero-copy,
        .dashboard-hero-side,
        .dashboard-panel,
        .content-card,
        .stat-card,
        .task-item,
        .modal-content {
            border-radius: 28px;
            border: 1px solid var(--rserve-border);
            box-shadow: var(--rserve-shadow);
        }

        .dashboard-hero-copy {
            position: relative;
            overflow: hidden;
            padding: 2.1rem 2.3rem;
            background: linear-gradient(135deg, #ffffff 0%, #f2f7ff 100%);
        }

        .dashboard-hero-copy::before {
            content: '';
            position: absolute;
            inset: auto -80px -80px auto;
            width: 220px;
            height: 220px;
            border-radius: 999px;
            background: radial-gradient(circle, rgba(21, 63, 122, 0.13) 0%, rgba(21, 63, 122, 0) 72%);
        }

        .dashboard-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            margin-bottom: 1rem;
            padding: 0.45rem 0.8rem;
            border-radius: 999px;
            background: rgba(21, 63, 122, 0.08);
            color: var(--rserve-primary);
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.18em;
        }

        .dashboard-hero-copy h1 {
            font-size: clamp(2.4rem, 5vw, 4rem);
            line-height: 0.95;
            letter-spacing: -0.05em;
            margin-bottom: 1rem;
        }

        .dashboard-hero-copy h1 span {
            color: var(--rserve-primary);
        }

        .dashboard-hero-copy p {
            max-width: 48rem;
            margin-bottom: 0;
            color: var(--rserve-muted);
            font-size: 1.02rem;
        }

        .hero-badges {
            margin-top: 1.25rem;
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.75rem 1rem;
            border-radius: 16px;
            background: #fff;
            border: 1px solid var(--rserve-border);
            color: var(--rserve-primary-deep);
            font-size: 0.88rem;
            font-weight: 700;
        }

        .dashboard-hero-side {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 1.75rem;
            background: linear-gradient(180deg, #153f7a 0%, #0f3367 100%);
            color: #fff;
        }

        .hero-side-label {
            font-size: 0.76rem;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: rgba(255, 255, 255, 0.72);
            font-weight: 800;
            margin-bottom: 0.75rem;
        }

        .hero-side-value {
            font-size: 1.9rem;
            line-height: 1.05;
            font-weight: 800;
            letter-spacing: -0.04em;
        }

        .hero-side-meta {
            margin-top: 0.75rem;
            color: rgba(255, 255, 255, 0.82);
            font-size: 0.92rem;
        }

        .hero-side-stats {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.75rem;
            margin-top: 1.4rem;
        }

        .hero-side-stat {
            padding: 0.9rem;
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.10);
            border: 1px solid rgba(255, 255, 255, 0.12);
        }

        .hero-side-stat span {
            display: block;
            color: rgba(255, 255, 255, 0.72);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            margin-bottom: 0.3rem;
        }

        .hero-side-stat strong {
            display: block;
            font-size: 1.15rem;
            font-weight: 800;
        }

        .dashboard-shell {
            display: grid;
            grid-template-columns: minmax(0, 1.7fr) minmax(320px, 0.9fr);
            gap: 1.5rem;
            align-items: start;
        }

        #view-dashboard .rserve-dashboard-scroll > .card,
        #view-dashboard .rserve-dashboard-scroll > h2.mb-4 {
            display: none !important;
        }

        #view-dashboard .rserve-dashboard-scroll > .row.g-4.mb-5 {
            display: grid;
            grid-template-columns: minmax(0, 1.7fr) minmax(320px, 0.9fr);
            gap: 1.5rem;
            --bs-gutter-x: 0;
            --bs-gutter-y: 0;
            margin: 0 !important;
        }

        #view-dashboard .rserve-dashboard-scroll > .row.g-4.mb-5 > [class*='col-'] {
            width: auto;
            max-width: none;
            padding: 0;
        }

        #view-dashboard .rserve-dashboard-scroll > .row.g-4.mb-5 .stat-card {
            align-items: stretch !important;
            text-align: left !important;
        }

        #view-dashboard .rserve-dashboard-scroll > .row.g-4.mb-5 .stat-card h5,
        #view-dashboard .rserve-dashboard-scroll > .row.g-4.mb-5 .stat-card p,
        #view-dashboard .rserve-dashboard-scroll > .row.g-4.mb-5 .stat-card > small {
            width: 100%;
            text-align: left;
        }

        #view-dashboard .rserve-dashboard-scroll > .row.g-4.mb-5 .stat-card h5,
        #view-dashboard .rserve-dashboard-scroll > .row.g-4.mb-5 .content-card h4 {
            color: #111827;
            font-size: 2rem;
            line-height: 1;
            letter-spacing: -0.04em;
            margin-bottom: 0.8rem !important;
        }

        #view-dashboard .rserve-dashboard-scroll > .row.g-4.mb-5 .progress-circle {
            margin: 0 auto 1.35rem 0;
        }

        .dashboard-panel {
            background: rgba(255, 255, 255, 0.9);
            padding: 1.85rem;
        }

        .panel-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .panel-header h2 {
            margin: 0;
            color: #111827;
            font-size: 2rem;
            line-height: 1;
            letter-spacing: -0.04em;
        }

        .panel-header p {
            margin: 0.45rem 0 0;
            color: var(--rserve-muted);
        }

        .panel-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.55rem 1rem;
            border-radius: 999px;
            background: rgba(89, 156, 255, 0.22);
            color: var(--rserve-primary-deep);
            font-size: 0.82rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .progress-panel-body {
            display: grid;
            grid-template-columns: minmax(220px, 0.88fr) minmax(0, 1.12fr);
            gap: 1.6rem;
            align-items: center;
        }

        .progress-ring {
            --progress-ring: calc(var(--progress) * 1%);
            width: 250px;
            height: 250px;
            margin: 0 auto;
            border-radius: 50%;
            background: conic-gradient(var(--rserve-primary) 0 var(--progress-ring), #e4e9f2 var(--progress-ring) 100%);
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .progress-ring::before {
            content: '';
            position: absolute;
            inset: 18px;
            border-radius: 50%;
            background: linear-gradient(180deg, #fff 0%, #f5f8fe 100%);
            box-shadow: inset 0 0 0 1px rgba(217, 228, 243, 0.9);
        }

        .progress-ring span {
            position: relative;
            z-index: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.2rem;
            color: #111827;
        }

        .progress-ring strong {
            font-size: 3.1rem;
            line-height: 1;
            letter-spacing: -0.06em;
        }

        .progress-ring small {
            font-size: 0.82rem;
            font-weight: 800;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--rserve-muted);
        }

        .metric-banner {
            padding: 1.25rem 1.35rem;
            border-radius: 22px;
            background: linear-gradient(180deg, #eef3fb 0%, #e7eef9 100%);
            border: 1px solid #d7e2f3;
        }

        .metric-banner-top {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 1rem;
        }

        .metric-banner span {
            color: #334155;
            font-size: 1rem;
            font-weight: 700;
        }

        .metric-banner strong {
            font-size: 2.2rem;
            line-height: 1;
            letter-spacing: -0.05em;
            color: var(--rserve-primary-deep);
        }

        .metric-progress {
            margin-top: 1rem;
            height: 8px;
            border-radius: 999px;
            background: rgba(21, 63, 122, 0.10);
            overflow: hidden;
        }

        .metric-progress span {
            display: block;
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(90deg, var(--rserve-primary) 0%, #5c97f5 100%);
        }

        .metric-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.9rem;
            margin-top: 0.95rem;
        }

        .metric-card {
            padding: 1.05rem 1.1rem;
            border-radius: 20px;
            border: 1px solid var(--rserve-border);
            background: #fff;
        }

        .metric-card span {
            display: block;
            color: #7c8aa0;
            font-size: 0.74rem;
            font-weight: 800;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            margin-bottom: 0.55rem;
        }

        .metric-card strong {
            display: block;
            font-size: 1.7rem;
            line-height: 1;
            letter-spacing: -0.04em;
            color: #111827;
        }

        .metric-card small {
            display: block;
            margin-top: 0.45rem;
            color: var(--rserve-muted);
            font-size: 0.88rem;
        }

        .progress-roadmap {
            margin-top: 1.75rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--rserve-border);
        }

        .roadmap-label {
            font-size: 0.78rem;
            font-weight: 800;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: #7c8aa0;
        }

        .phase-track {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0.75rem;
            margin-top: 0.95rem;
        }

        .phase-step {
            padding: 0.9rem 0.7rem;
            border-radius: 18px;
            text-align: center;
            background: #eef3fb;
            color: #5f7089;
            font-size: 0.88rem;
            font-weight: 800;
        }

        .phase-step.is-active {
            background: linear-gradient(135deg, rgba(21, 63, 122, 0.18), rgba(21, 63, 122, 0.06));
            color: var(--rserve-primary-deep);
        }

        .dashboard-instructions {
            margin-top: 1.5rem;
            padding: 1.2rem 1.25rem;
            border-radius: 22px;
            border: 1px solid var(--rserve-border);
            background: #f7f9fd;
        }

        .dashboard-instructions .see-more {
            margin-top: 0;
            font-size: 0.94rem;
            font-weight: 800;
            color: var(--rserve-primary-deep);
        }

        .dashboard-instructions .instructions {
            margin-top: 1rem;
        }

        .dashboard-instructions .instructions p,
        .dashboard-instructions .instructions li {
            color: var(--rserve-muted);
            font-size: 0.95rem;
            line-height: 1.6;
        }

        .dashboard-instructions .instructions ul {
            margin-bottom: 0;
            padding-left: 1.2rem;
        }

        .attendance-panel {
            position: sticky;
            top: 6rem;
        }

        .attendance-panel .panel-icon {
            width: 52px;
            height: 52px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 18px;
            background: rgba(21, 63, 122, 0.10);
            color: var(--rserve-primary-deep);
            font-size: 1.2rem;
            margin-bottom: 1rem;
        }

        .attendance-meta {
            display: grid;
            gap: 0.95rem;
            margin-bottom: 1.25rem;
        }

        .attendance-meta-block {
            padding-bottom: 0.95rem;
            border-bottom: 1px solid var(--rserve-border);
        }

        .attendance-meta-block span {
            display: block;
            color: #7c8aa0;
            font-size: 0.74rem;
            font-weight: 800;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            margin-bottom: 0.45rem;
        }

        .attendance-meta-block strong {
            color: #111827;
            font-size: 1rem;
            font-weight: 800;
        }

        .attendance-form .form-label {
            color: #7c8aa0;
            font-size: 0.74rem;
            font-weight: 800;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            margin-bottom: 0.45rem;
        }

        .attendance-form .form-control,
        .attendance-form .form-select {
            border-radius: 16px;
            border: 1px solid var(--rserve-border);
            padding: 0.9rem 1rem;
            background: #fff;
            font-weight: 700;
            color: #111827;
        }

        .attendance-time-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.8rem;
        }

        .attendance-readonly {
            background: #f8fafc !important;
        }

        .attendance-metric {
            margin-top: 1rem;
            padding: 1rem 1.1rem;
            border-radius: 20px;
            background: linear-gradient(180deg, #eef4ff 0%, #e8effc 100%);
            border: 1px solid #d4e1f6;
        }

        .attendance-metric span {
            display: block;
            color: #7c8aa0;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            margin-bottom: 0.4rem;
        }

        .attendance-metric strong {
            font-size: 1.95rem;
            line-height: 1;
            letter-spacing: -0.04em;
            color: var(--rserve-primary-deep);
        }

        .attendance-form .btn-primary {
            border: 0;
            border-radius: 18px;
            padding: 1rem 1.2rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--rserve-primary) 0%, var(--rserve-primary-deep) 100%);
            box-shadow: 0 16px 26px rgba(12, 46, 96, 0.18);
        }

        .dashboard-lower-grid {
            display: grid;
            grid-template-columns: minmax(290px, 0.82fr) minmax(0, 1.18fr);
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        .summary-list {
            display: grid;
            gap: 1rem;
        }

        .summary-item {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            padding-bottom: 0.95rem;
            border-bottom: 1px solid var(--rserve-border);
        }

        .summary-item:last-child {
            border-bottom: 0;
            padding-bottom: 0;
        }

        .summary-item span {
            color: var(--rserve-muted);
            font-weight: 700;
        }

        .summary-item strong {
            color: #111827;
            font-weight: 800;
            text-align: right;
            max-width: 56%;
        }

        .service-status-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 112px;
            padding: 0.45rem 0.85rem;
            border-radius: 999px;
            background: #eef3fb;
            color: var(--rserve-primary-deep);
            font-size: 0.78rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .panel-link {
            color: var(--rserve-primary-deep);
            font-weight: 800;
            text-decoration: none;
        }

        .history-list {
            width: 100%;
        }

        .history-row {
            display: grid;
            grid-template-columns: minmax(120px, 0.8fr) minmax(0, 1.45fr) minmax(110px, 0.7fr) 80px;
            gap: 1rem;
            align-items: center;
            padding: 1rem 0;
            border-top: 1px solid var(--rserve-border);
        }

        .history-row:first-child {
            border-top: 0;
            padding-top: 0;
        }

        .history-row--head {
            color: #7c8aa0;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            padding-bottom: 0.85rem;
        }

        .history-row strong,
        .history-row span,
        .history-row small {
            display: block;
        }

        .history-row strong {
            color: #111827;
            font-size: 0.98rem;
            font-weight: 800;
        }

        .history-row small,
        .history-row span {
            color: var(--rserve-muted);
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.45rem 0.8rem;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 800;
            white-space: nowrap;
        }

        .status-pill--success {
            background: #dcfce7;
            color: #166534;
        }

        .status-pill--warning {
            background: #fef3c7;
            color: #92400e;
        }

        .status-pill--danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-pill--info {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .history-empty {
            padding: 2rem 0;
            text-align: center;
            color: var(--rserve-muted);
        }

        .content-card,
        .stat-card {
            background: rgba(255, 255, 255, 0.9);
            border-top: 0;
            padding: 1.65rem;
        }

        .task-item {
            background: rgba(255, 255, 255, 0.94);
            padding: 1.15rem;
        }

        .mobile-header {
            background: rgba(12, 46, 96, 0.96);
            backdrop-filter: blur(14px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.10);
            box-shadow: 0 10px 24px rgba(12, 46, 96, 0.18);
        }

        .mobile-header-top {
            padding: 0.9rem 1rem 0.75rem;
        }

        .mobile-brand-block {
            display: flex;
            flex-direction: column;
            gap: 0.15rem;
        }

        .mobile-brand-title {
            color: #fff;
            font-size: 1.15rem;
            font-weight: 800;
            line-height: 1;
        }

        .mobile-brand-subtitle {
            color: rgba(255, 255, 255, 0.72);
            font-size: 0.66rem;
            font-weight: 800;
            letter-spacing: 0.18em;
            text-transform: uppercase;
        }

        .mobile-profile-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 42px;
            height: 42px;
            padding: 0;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.16);
            border-radius: 14px;
        }

        .mobile-header .nav-item {
            color: rgba(255, 255, 255, 0.7);
            padding: 0.5rem 0.25rem;
        }

        .mobile-header .nav-item.active {
            color: #fff;
        }

        @media (max-width: 1199.98px) {
            .dashboard-hero,
            .dashboard-shell,
            .dashboard-lower-grid,
            #view-dashboard .rserve-dashboard-scroll > .row.g-4.mb-5 {
                grid-template-columns: 1fr;
            }

            .attendance-panel {
                position: static;
            }

            .topbar-profile {
                min-width: 0;
                max-width: 44vw;
            }
        }

        @media (max-width: 991.98px) {
            #sidebar-wrapper {
                width: 250px;
            }

            #page-content-wrapper {
                margin-left: 250px;
            }

            .topbar-tabs {
                display: none;
            }

            .topbar-shell {
                justify-content: flex-end;
            }

            .topbar-profile {
                max-width: 52vw;
            }
        }

        @media (max-width: 767.98px) {
            #page-content-wrapper {
                margin-left: 0;
                padding-top: 115px;
            }

            .container-fluid {
                padding: 1rem 0.9rem 1.6rem;
            }

            .dashboard-hero-copy,
            .dashboard-panel {
                padding: 1.25rem;
                border-radius: 24px;
            }

            .dashboard-hero-copy h1 {
                font-size: 2.35rem;
            }

            .hero-side-stats,
            .metric-grid,
            .phase-track,
            .attendance-time-grid {
                grid-template-columns: 1fr 1fr;
            }

            .panel-header {
                flex-direction: column;
            }

            .panel-header h2 {
                font-size: 1.7rem;
            }

            .progress-panel-body {
                grid-template-columns: 1fr;
            }

            .progress-ring {
                width: 190px;
                height: 190px;
            }

            .progress-ring strong {
                font-size: 2.3rem;
            }

            .history-row,
            .history-row--head {
                grid-template-columns: 1fr;
                gap: 0.4rem;
            }

            .history-row--head {
                display: none;
            }

            .summary-item {
                flex-direction: column;
            }

            .summary-item strong {
                max-width: none;
                text-align: left;
            }
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
        <div class="mobile-brand-block">
            <span class="mobile-brand-title">RServeS Portal</span>
            <span class="mobile-brand-subtitle">Academic Residency</span>
        </div>
        <button type="button" class="mobile-profile-chip border-0" data-bs-toggle="modal" data-bs-target="#profileModal">
            <img src="../<?php echo htmlspecialchars($photo); ?>" alt="Profile" 
                 class="rounded-circle border border-2 border-white" 
                 style="width: 35px; height: 35px; object-fit: cover;">
        </button>
    </div>
    <div class="mobile-header-nav">
        <a href="#" class="nav-item active" data-view-link="dashboard" onclick="showView('dashboard', this); return false;">
            <i class="fas fa-th-large"></i>
            <span>Overview</span>
        </a>
        <a href="#" class="nav-item position-relative" data-view-link="tasks" onclick="showView('tasks', this); return false;">
            <i class="fas fa-tasks"></i>
            <span>Tasks</span>
            <?php if(count($tasks) > 0): ?>
                <span class="position-absolute top-0 start-100 translate-middle p-1 bg-danger border border-light rounded-circle" style="width: 10px; height: 10px;"></span>
            <?php endif; ?>
        </a>
        <!-- Org Mobile Nav Removed -->
        <a href="#" class="nav-item" data-view-link="documents" onclick="showView('documents', this); return false;">
            <i class="fas fa-file-alt"></i>
            <span>Docs</span>
        </a>
        
        <!-- Mobile Nav Bell -->
        <div class="dropdown">
            <button class="btn btn-link nav-item position-relative border-0 bg-transparent p-0" type="button" id="mobileNotificationDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="fas fa-bell"></i>
                <span>Notifs</span>
                <?php if ($unread_count > 0): ?>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" data-notification-count-badge style="font-size: 0.5rem; transform: translate(-5px, 0px)!important;">
                        <?= $unread_count > 9 ? '9+' : $unread_count ?>
                    </span>
                <?php endif; ?>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow border-0" aria-labelledby="mobileNotificationDropdown" style="width: 280px; max-height: 80vh; overflow-y: auto;">
                <li class="d-flex justify-content-between align-items-center px-3 py-2">
                    <h6 class="dropdown-header p-0 m-0">Notifications</h6>
                    <?php if ($unread_count > 0): ?>
                        <button type="button" class="btn btn-sm btn-primary py-0 px-2" data-notification-mark-all-button style="font-size: 0.75rem;" onclick="return markAllAsRead();">Mark all as read</button>
                    <?php endif; ?>
                </li>
                <li><hr class="dropdown-divider m-0"></li>
                <li class="<?= empty($notifications) ? '' : 'd-none' ?>" data-notification-empty="dropdown">
                    <span class="dropdown-item text-muted text-center small py-3">No new notifications</span>
                </li>
                <?php foreach ($notifications as $notif): ?>
                    <li class="border-bottom" data-notification-item data-notification-key="<?= htmlspecialchars($notif['key']) ?>" data-notification-context="dropdown">
                        <div class="dropdown-item py-2">
                            <div class="d-flex align-items-start gap-2">
                                <div class="me-1 mt-1">
                                    <?php if($notif['type'] === 'task'): ?>
                                        <i class="fas fa-tasks text-primary"></i>
                                    <?php elseif($notif['type'] === 'announcement'): ?>
                                        <i class="fas fa-bullhorn text-info"></i>
                                    <?php elseif($notif['type'] === 'certificate'): ?>
                                        <i class="fas fa-certificate text-warning"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-grow-1" style="white-space: normal; line-height: 1.3;">
                                    <div class="small fw-bold"><?= htmlspecialchars($notif['message']) ?></div>
                                    <?php if (!empty($notif['details'])): ?>
                                        <div class="text-muted" style="font-size: 0.75rem;"><?= htmlspecialchars($notif['details']) ?></div>
                                    <?php endif; ?>
                                    <div class="text-muted" style="font-size: 0.75rem;"><?= date('M d, h:i A', strtotime($notif['date'])) ?></div>
                                </div>
                            </div>
                            <div class="d-flex justify-content-end gap-2 mt-2">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-primary py-0 px-2"
                                    data-notification-action-key="<?= htmlspecialchars($notif['key']) ?>"
                                    onclick='return readNotification(<?= json_encode($notif["type"]) ?>, <?= (int) $notif["id"] ?>, <?= json_encode($notif["target_view"]) ?>, <?= json_encode($notif["certificate_code"]) ?>);'
                                >
                                    Read
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary py-0 px-2"
                                    data-notification-action-key="<?= htmlspecialchars($notif['key']) ?>"
                                    onclick='return markNotificationReadOnly(<?= json_encode($notif["type"]) ?>, <?= (int) $notif["id"] ?>);'
                                >
                                    Mark as read
                                </button>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
                <li class="<?= empty($notifications) ? 'd-none' : '' ?>" data-notification-view-all-link>
                    <a class="dropdown-item text-center small text-primary fw-bold py-2" href="#" onclick="showView('notifications'); return false;">View All Notifications</a>
                </li>
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
        <div class="sidebar-shell">
            <div class="sidebar-heading">
                <span class="sidebar-brand-title">RServeS Portal</span>
                <span class="sidebar-brand-subtitle">Academic Residency</span>
            </div>
            <div class="list-group list-group-flush">
                <a href="#" class="list-group-item list-group-item-action active" data-view-link="dashboard" onclick="showView('dashboard', this); return false;">
                    <i class="fas fa-th-large"></i> Dashboard
                </a>
                <a href="#" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" data-view-link="tasks" onclick="showView('tasks', this); return false;">
                    <span><i class="fas fa-tasks"></i> Tasks</span>
                    <?php if($active_task_count > 0): ?>
                        <span class="badge bg-danger rounded-pill"><?= $active_task_count ?></span>
                    <?php endif; ?>
                </a>
                <a href="#" class="list-group-item list-group-item-action" data-view-link="documents" onclick="showView('documents', this); return false;">
                    <i class="fas fa-file-alt"></i> Documents
                </a>
                <a href="#" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" data-view-link="notifications" onclick="showView('notifications', this); return false;">
                    <span><i class="fas fa-bell"></i> Notifications</span>
                    <?php if ($unread_count > 0): ?>
                        <span class="badge bg-danger rounded-pill" data-notification-count-badge><?= $unread_count ?></span>
                    <?php endif; ?>
                </a>
                <a href="#" class="list-group-item list-group-item-action" data-bs-toggle="modal" data-bs-target="#profileModal">
                    <i class="fas fa-user-circle"></i> Profile
                </a>
                <a href="logout.php" class="list-group-item list-group-item-action">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
            <div class="sidebar-student-card">
                <div class="sidebar-student-profile">
                    <img src="../<?php echo htmlspecialchars($photo); ?>" alt="Profile">
                    <div>
                        <div class="sidebar-student-name"><?= htmlspecialchars($fullname) ?></div>
                        <div class="sidebar-student-meta">Student ID: <?= htmlspecialchars($student_number ?: 'Not assigned') ?></div>
                    </div>
                </div>
                <button type="button" class="sidebar-support-btn" data-bs-toggle="modal" data-bs-target="#profileModal">
                    Profile Center
                </button>
            </div>
        </div>
    </div>

    <!-- Page Content -->
    <div id="page-content-wrapper">
        <nav class="navbar navbar-expand-lg">
            <div class="topbar-shell">
                <div class="topbar-tabs d-none d-lg-flex">
                    <a href="#" class="topbar-tab active" data-view-link="dashboard" onclick="showView('dashboard', this); return false;">Overview</a>
                    <a href="#" class="topbar-tab" data-view-link="tasks" onclick="showView('tasks', this); return false;">Tasks</a>
                    <a href="#" class="topbar-tab" data-view-link="documents" onclick="showView('documents', this); return false;">Documents</a>
                    <a href="#" class="topbar-tab" data-view-link="notifications" onclick="showView('notifications', this); return false;">Notifications</a>
                </div>

                <div class="topbar-actions">
                    <button type="button" class="btn topbar-secondary-btn d-none d-md-inline-flex" onclick="openQuickLogHours(); return false;">
                        Log Hours
                    </button>
                    <button type="button" class="btn topbar-primary-btn" onclick="focusAttendancePanel(); return false;">
                        Check In
                    </button>
<div class="topbar-profile" data-bs-toggle="modal" data-bs-target="#profileModal">
<div class="topbar-identity">
  <div class="fw-bold"><?= htmlspecialchars($fullname) ?></div>
  <div class="text-muted small">Student | <?= htmlspecialchars($college_name) ?></div>
</div>
                        <img src="../<?php echo htmlspecialchars($photo); ?>" alt="Profile" class="topbar-avatar">
                    </div>
                </div>
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
                    <section class="dashboard-hero">
                        <div class="dashboard-hero-copy">
                            <span class="dashboard-eyebrow">Student Overview</span>
                            <h1>Greetings!<br><span>Hello, <?= htmlspecialchars($student['firstname']) ?>!</span></h1>
                            <p>Your current academic adviser is <strong><?= htmlspecialchars($adviser_name) ?></strong>. Track your residency progress, time in for service hours, and manage your submissions from one place.</p>
                            <div class="hero-badges">
                                <span class="hero-badge"><?= htmlspecialchars($college_name) ?></span>
                                <span class="hero-badge"><?= htmlspecialchars($current_session_label) ?></span>
                                <span class="hero-badge"><?= date('F d, Y') ?></span>
                            </div>
                        </div>

                        <aside class="dashboard-hero-side">
                            <div>
                                <div class="hero-side-label">Current Session</div>
                                <div class="hero-side-value"><?= htmlspecialchars($current_session_label) ?></div>
                                <div class="hero-side-meta"><?= htmlspecialchars($college_name) ?></div>
                            </div>
                            <div class="hero-side-stats">
                                <div class="hero-side-stat">
                                    <span>Hours Done</span>
                                    <strong><?= number_format($total_hours_completed, 2) ?></strong>
                                </div>
                                <div class="hero-side-stat">
                                    <span>Hours Left</span>
                                    <strong><?= number_format($hours_remaining, 2) ?></strong>
                                </div>
                            </div>
                        </aside>
                    </section>

                <div class="card" data-student-name="<?= htmlspecialchars($student['firstname']) ?>" data-adviser-name="<?= htmlspecialchars($adviser_name) ?>"> 
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
                        <div class="stat-card text-center h-100 d-flex flex-column justify-content-center align-items-center">
                            <div class="progress-circle mx-auto" style="--progress: <?= $progress_percent ?>%;">
                                <span><?= number_format($progress_percent, 1) ?>%</span>
                            </div>
                            <h5>Progress</h5>
                            <p class="text-muted mb-0" style="line-height: 1.15;"><strong><?= number_format($total_hours_completed, 2) ?></strong> / <?= $required_hours ?> hours</p>
                            <small class="text-primary d-block mb-3"><?= $progress_message ?></small>

                            <div class="metric-banner text-start w-100 mt-2">
                                <div class="metric-banner-top">
                                    <span>Hours Remaining</span>
                                    <strong><?= number_format($hours_remaining, 2) ?>h</strong>
                                </div>
                                <div class="metric-progress">
                                    <span style="width: <?= max(0, min(100, $progress_percent)) ?>%;"></span>
                                </div>
                            </div>

                            <div class="metric-grid w-100">
                                <div class="metric-card text-start">
                                    <span>Total Goal</span>
                                    <strong><?= number_format($required_hours) ?></strong>
                                    <small>hours required</small>
                                </div>
                                <div class="metric-card text-start">
                                    <span>Milestones</span>
                                    <strong><?= $completed_milestones ?> / 4</strong>
                                    <small>completion phases</small>
                                </div>
                            </div>
                            
                            <div class="d-flex justify-content-center align-items-center gap-2 mt-3">
                                <span class="text-muted small"><i class="fas me-1"></i>Active Tasks</span>
                                <span class="badge bg-danger rounded-pill"><?= count($tasks) ?></span>
                            </div>

                            <div class="progress-roadmap w-100 text-start">
                                <div class="roadmap-label">Milestone Roadmap</div>
                                <div class="phase-track">
                                    <?php foreach (['Phase 1', 'Phase 2', 'Phase 3', 'Completion'] as $phase_index => $phase_label): ?>
                                        <div class="phase-step <?= $phase_index < $completed_milestones ? 'is-active' : '' ?>">
                                            <?= htmlspecialchars($phase_label) ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="dashboard-instructions w-100 text-start">
                                <button type="button" class="see-more border-0 bg-transparent p-0" onclick="toggleDashboardInstructions(this)">View RSS attendance guide</button>
                                <div class="instructions" id="dashboardInstructions">
                                    <p><strong>Instructions:</strong></p>
                                    <p>Students must complete <strong>320 hours</strong> of Return Service System work, distributed at roughly <strong>53.33 hours per semester</strong>. All required hours should be completed before your 4th year.</p>
                                    <p><strong>Attendance Rules:</strong></p>
                                    <ul>
                                        <li><strong>Morning Session:</strong> Time in between 8:00 AM and 8:30 AM, with time out at 12:00 PM for up to 4 hours.</li>
                                        <li><strong>Afternoon Session:</strong> Time in between 1:00 PM and 1:30 PM, with time out at 5:00 PM for up to 4 hours.</li>
                                        <li><strong>Full Day:</strong> Time in between 8:00 AM and 8:30 AM, with time out at 5:00 PM for up to 8 hours.</li>
                                        <li>Late arrivals reduce credited hours automatically based on the time you log in.</li>
                                    </ul>
                                    <p>Track your progress below. Hours are added after adviser approval, while attendance calculations update in real time as you prepare your log.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Time In/Out Section -->
                    <div class="col-md-6">
                        <div class="content-card h-100 mb-0 attendance-panel" id="attendanceCard">
                            <div class="panel-icon">
                                <i class="fas fa-calendar-check"></i>
                            </div>
                            <h4 class="mb-4">Daily Attendance</h4>
                            <div class="attendance-meta">
                                <div class="attendance-meta-block">
                                    <span>Reporting Date</span>
                                    <strong><?= date('m/d/Y') ?></strong>
                                </div>
                                <div class="attendance-meta-block">
                                    <span>Residency Session</span>
                                    <strong id="selectedSessionLabel">Select Session</strong>
                                </div>
                            </div>

                            <form method="POST" id="attendanceForm" class="row g-2 attendance-form">
                                <div class="col-6 col-md-12">
                                    <label class="form-label">Date</label>
                                    <input type="date" name="work_date" class="form-control" value="<?= date('Y-m-d') ?>" readonly>
                                </div>
                                <div class="col-6 col-md-12">
                                    <label class="form-label">Session</label>
                                    <select name="session" id="sessionSelect" class="form-select" required>
                                        <option value="">Select Session</option>
                                        <option value="morning">Morning (8-12)</option>
                                        <option value="afternoon">Afternoon (1-5)</option>
                                        <option value="fullday">Full Day (8-5)</option>
                                    </select>
                                </div>
                                <div class="col-12 attendance-time-grid">
                                    <div>
                                        <label class="form-label">Time In</label>
                                        <input type="time" name="time_in" id="timeIn" class="form-control" readonly>
                                    </div>
                                    <div>
                                        <label class="form-label">Time Out</label>
                                        <input type="text" id="timeOutDisplay" class="form-control attendance-readonly" value="--:-- --" readonly>
                                    </div>
                                </div>
                                
                                <input type="hidden" name="calculated_hours" id="calculatedHoursHidden">
                                
                                <div class="col-12">
                                    <div class="attendance-metric">
                                        <span>Projected Hours</span>
                                        <strong><span id="hoursDisplay">0.00</span></strong>
                                    </div>
                                </div>

                                <div class="col-12 mt-2">
                                    <button type="submit" name="submit_time" id="submitBtn" class="btn btn-primary w-100" disabled>
                                        Submit Attendance Log
                                    </button>
                                </div>
                                <div class="col-12">
                                    <div id="lateStatusMsg" style="display: none;"></div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                    <div class="dashboard-lower-grid">
                        <section class="dashboard-panel summary-panel">
                            <div class="panel-header">
                                <div>
                                    <h2>Residency Summary</h2>
                                    <p>Your current academic and service details.</p>
                                </div>
                            </div>

                            <div class="summary-list">
                                <div class="summary-item">
                                    <span>Student Number</span>
                                    <strong><?= htmlspecialchars($student_number ?: 'Not assigned') ?></strong>
                                </div>
                                <div class="summary-item">
                                    <span>Year and Section</span>
                                    <strong><?= htmlspecialchars(trim(($student['year_level'] ? 'Year ' . $student['year_level'] : 'Year not set') . (!empty($student['section']) ? ' - Section ' . $student['section'] : ''))) ?></strong>
                                </div>
                                <div class="summary-item">
                                    <span>Course</span>
                                    <strong><?= htmlspecialchars($enrollment_data['course'] ?? 'Not submitted') ?></strong>
                                </div>
                                <div class="summary-item">
                                    <span>Major</span>
                                    <strong><?= htmlspecialchars($enrollment_data['major'] ?? 'Not specified') ?></strong>
                                </div>
                                <div class="summary-item">
                                    <span>RSS Assignment</span>
                                    <strong><?= htmlspecialchars($enrollment_data['rss_assignment'] ?? 'Not assigned') ?></strong>
                                </div>
                                <div class="summary-item">
                                    <span>Service Status</span>
                                    <strong><span class="service-status-pill"><?= htmlspecialchars($service_status) ?></span></strong>
                                </div>
                            </div>
                        </section>

                        <section class="dashboard-panel history-panel">
                            <div class="panel-header">
                                <div>
                                    <h2>Recent Attendance History</h2>
                                    <p>Latest attendance records logged in your account.</p>
                                </div>
                                <a href="documents/ar.php" class="panel-link">View full report</a>
                            </div>

                            <?php if (empty($recent_time_logs)): ?>
                                <div class="history-empty">
                                    No attendance records yet. Use the attendance panel above to log your first session.
                                </div>
                            <?php else: ?>
                                <div class="history-list">
                                    <div class="history-row history-row--head">
                                        <div>Date</div>
                                        <div>Session</div>
                                        <div>Status</div>
                                        <div>Hours</div>
                                    </div>
                                    <?php foreach ($recent_time_logs as $log): ?>
                                        <?php
                                            $attendance_status = (string) ($log['status'] ?? 'Pending');
                                            $attendance_status_class = 'status-pill--warning';
                                            if (in_array($attendance_status, ['Approved', 'Verified'], true)) {
                                                $attendance_status_class = 'status-pill--success';
                                            } elseif ($attendance_status === 'Rejected') {
                                                $attendance_status_class = 'status-pill--danger';
                                            } elseif ($attendance_status !== 'Pending') {
                                                $attendance_status_class = 'status-pill--info';
                                            }
                                        ?>
                                        <div class="history-row">
                                            <div>
                                                <strong><?= date('M d, Y', strtotime($log['work_date'])) ?></strong>
                                                <small><?= htmlspecialchars(date('D', strtotime($log['work_date']))) ?></small>
                                            </div>
                                            <div>
                                                <strong><?= htmlspecialchars(ucfirst((string) ($log['session'] ?? 'Session'))) ?></strong>
                                                <span><?= htmlspecialchars(date('h:i A', strtotime((string) $log['time_in'])) . ' - ' . date('h:i A', strtotime((string) $log['time_out']))) ?></span>
                                            </div>
                                            <div>
                                                <span class="status-pill <?= $attendance_status_class ?>"><?= htmlspecialchars($attendance_status) ?></span>
                                            </div>
                                            <div>
                                                <strong><?= number_format((float) ($log['hours'] ?? 0), 1) ?>h</strong>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </section>
                    </div>
                </div>
            </div>

            <!-- NOTIFICATIONS VIEW -->
            <div id="view-notifications" class="d-none">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>Notifications</h2>
                    <?php if ($unread_count > 0): ?>
                        <button type="button" class="btn btn-outline-primary btn-sm" data-notification-mark-all-button onclick="return markAllAsRead();">
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

                            <div class="text-center py-5 text-muted <?= empty($grouped_notifications) ? '' : 'd-none' ?>" data-notification-empty="full">
                                <i class="fas fa-bell-slash fa-3x mb-3"></i>
                                <p>No new notifications.</p>
                            </div>
                            <?php if (!empty($grouped_notifications)): ?>
                                <div class="accordion" id="notificationsAccordion" data-notification-groups>
                                    <?php $acc_index = 0; ?>
                                    <?php foreach ($grouped_notifications as $group => $notifs): ?>
                                        <div class="accordion-item" data-notification-group>
                                            <h2 class="accordion-header" id="heading<?= $acc_index ?>">
                                                <button class="accordion-button <?= $acc_index === 0 ? '' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?= $acc_index ?>" aria-expanded="<?= $acc_index === 0 ? 'true' : 'false' ?>" aria-controls="collapse<?= $acc_index ?>">
                                                    <?= htmlspecialchars($group) ?>
                                                </button>
                                            </h2>
                                            <div id="collapse<?= $acc_index ?>" class="accordion-collapse collapse <?= $acc_index === 0 ? 'show' : '' ?>" aria-labelledby="heading<?= $acc_index ?>" data-bs-parent="#notificationsAccordion">
                                                <div class="accordion-body p-0">
                                                    <div class="list-group list-group-flush">
                                                        <?php foreach ($notifs as $notif): ?>
                                                            <div class="list-group-item p-3" data-notification-item data-notification-key="<?= htmlspecialchars($notif['key']) ?>" data-notification-context="full">
                                                                <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-start gap-3">
                                                                    <div class="flex-grow-1">
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
                                                                                <?php elseif($notif['type'] === 'announcement'): ?>
                                                                                    <div class="bg-info text-dark rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px;">
                                                                                        <i class="fas fa-bullhorn"></i>
                                                                                    </div>
                                                                                    <div>
                                                                                        <h6 class="mb-0 fw-bold">Announcement</h6>
                                                                                        <small class="text-muted">Adviser</small>
                                                                                    </div>
                                                                                <?php elseif($notif['type'] === 'certificate'): ?>
                                                                                    <div class="bg-warning text-white rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px;">
                                                                                        <i class="fas fa-certificate"></i>
                                                                                    </div>
                                                                                    <div>
                                                                                        <h6 class="mb-0 fw-bold">Certificate Issued</h6>
                                                                                        <small class="text-muted">Certificate</small>
                                                                                    </div>
                                                                                <?php else: ?>
                                                                                    <div class="bg-success text-white rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px;">
                                                                                        <i class="fas fa-check-circle"></i>
                                                                                    </div>
                                                                                    <div>
                                                                                        <h6 class="mb-0 fw-bold">Organization Verified</h6>
                                                                                        <small class="text-muted">Organization</small>
                                                                                    </div>
                                                                                <?php endif; ?>
                                                                            </div>
                                                                            <small class="text-muted"><?= date('M d, h:i A', strtotime($notif['date'])) ?></small>
                                                                        </div>
                                                                        <p class="mb-0 ms-5 ps-2 text-dark"><?= htmlspecialchars($notif['message']) ?></p>
                                                                        <?php if (!empty($notif['details'])): ?>
                                                                            <p class="mb-0 ms-5 ps-2 text-muted small"><?= htmlspecialchars($notif['details']) ?></p>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                    <div class="d-flex flex-wrap gap-2 ms-lg-3">
                                                                        <button
                                                                            type="button"
                                                                            class="btn btn-sm btn-primary"
                                                                            data-notification-action-key="<?= htmlspecialchars($notif['key']) ?>"
                                                                            onclick='return readNotification(<?= json_encode($notif["type"]) ?>, <?= (int) $notif["id"] ?>, <?= json_encode($notif["target_view"]) ?>, <?= json_encode($notif["certificate_code"]) ?>);'
                                                                        >
                                                                            Read
                                                                        </button>
                                                                        <button
                                                                            type="button"
                                                                            class="btn btn-sm btn-outline-secondary"
                                                                            data-notification-action-key="<?= htmlspecialchars($notif['key']) ?>"
                                                                            onclick='return markNotificationReadOnly(<?= json_encode($notif["type"]) ?>, <?= (int) $notif["id"] ?>);'
                                                                        >
                                                                            Mark as read
                                                                        </button>
                                                                    </div>
                                                                </div>
                                                            </div>
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
                    <div class="d-flex gap-2">
                        <a href="archived_tasks.php" class="btn btn-outline-secondary">
                            <i class="fas fa-box-archive me-2"></i>Archived Tasks
                        </a>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#verbalTaskModal">
                            <i class="fas fa-plus me-2"></i>Create Verbal Task
                        </button>
                    </div>
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
                                            <span class="badge bg-secondary ms-1"><?= htmlspecialchars($task['duration'] ?? 'No Duration') ?></span>
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
                                    <div class="d-flex flex-wrap gap-2 justify-content-end">
                                        <?php if ($displayStatus !== 'Completed' && !$disableSubmit): ?>
                                            <button class="btn btn-sm btn-outline-success" onclick="submitToAccomplishment(<?= $task['stask_id'] ?>, '<?= htmlspecialchars($task['title'], ENT_QUOTES) ?>')">
                                                <i class="fas fa-upload me-1"></i> <?= $displayStatus === 'Rejected' ? 'Resubmit' : 'Submit' ?>
                                            </button>
                                        <?php endif; ?>
                                        <form method="POST" onsubmit="return confirm('Archive this task?');">
                                            <input type="hidden" name="stask_id" value="<?= (int) $task['stask_id'] ?>">
                                            <button type="submit" name="archive_task" class="btn btn-sm btn-outline-secondary">
                                                <i class="fas fa-box-archive me-1"></i>Archive
                                            </button>
                                        </form>
                                        <form method="POST" onsubmit="return confirm('Delete this task from your active list? You can still review it in Archived Tasks.');">
                                            <input type="hidden" name="stask_id" value="<?= (int) $task['stask_id'] ?>">
                                            <button type="submit" name="delete_task" class="btn btn-sm btn-outline-danger">
                                                <i class="fas fa-trash-alt me-1"></i>Delete
                                            </button>
                                        </form>
                                    </div>
                                </div>
                                
                                <?php if ($displayStatus !== 'Completed'): ?>
                                    <textarea class="form-control mt-2 task-description-auto" 
                                              placeholder="Describe your work (auto-saved)..."
                                              rows="2"
                                              maxlength="<?= RSERVES_STUDENT_DESCRIPTION_MAX_CHARS ?>"
                                              data-stask-id="<?= $task['stask_id'] ?>"><?= htmlspecialchars($task['description'] ?: '') ?></textarea>
                                <?php else: ?>
                                    <p class="mt-2 text-muted"><?= htmlspecialchars($task['description'] ?: 'No description') ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ORGANIZATIONS VIEW -->
            <!-- Orgs View Removed -->

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
                    <a href="enrollment_update.php" class="btn btn-primary w-100">
                        <i class="fas fa-file-contract me-1"></i> Submit Enrollment
                    </a>
                <?php endif; ?>
                <a href="change_password.php" class="btn btn-outline-secondary w-100 mt-2">
                    <i class="fas fa-key me-1"></i> Change Password
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Create Verbal Task Modal -->
<div class="modal fade" id="verbalTaskModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
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
                        <textarea name="task_description" class="form-control" rows="3" placeholder="What did you do?" maxlength="<?= RSERVES_STUDENT_DESCRIPTION_MAX_CHARS ?>"></textarea>
                        <div class="form-text">Optional, up to <?= RSERVES_STUDENT_DESCRIPTION_MAX_CHARS ?> characters.</div>
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

<!-- Org Modal Removed -->

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

    function toggleDashboardInstructions(triggerButton) {
        const instructions = document.getElementById('dashboardInstructions');
        if (!instructions) return;
        instructions.classList.toggle('show');
        if (triggerButton) {
            triggerButton.textContent = instructions.classList.contains('show')
                ? 'Hide RSS attendance guide'
                : 'View RSS attendance guide';
        }
    }

    function focusAttendancePanel() {
        const dashboardLink = document.querySelector('[data-view-link="dashboard"]');
        showView('dashboard', dashboardLink);
        const attendanceCard = document.getElementById('attendanceCard');
        if (attendanceCard) {
            attendanceCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        const sessionField = document.getElementById('sessionSelect');
        if (sessionField) {
            setTimeout(() => sessionField.focus(), 250);
        }
    }

    function openQuickLogHours() {
        const dashboardLink = document.querySelector('[data-view-link="dashboard"]');
        showView('dashboard', dashboardLink);
        if (typeof window.openAccomplishmentModal === 'function') {
            window.openAccomplishmentModal('', '', '');
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
        document.querySelectorAll('[data-view-link]').forEach(el => {
            el.classList.toggle('active', el.dataset.viewLink === viewName);
        });

        // Hide all views
        document.getElementById('view-dashboard').classList.add('d-none');
        document.getElementById('view-tasks').classList.add('d-none');
        document.getElementById('view-documents').classList.add('d-none');
        document.getElementById('view-notifications').classList.add('d-none');

        // Show target view
        const targetView = document.getElementById('view-' + viewName);
        if (!targetView) {
            return false;
        }

        targetView.classList.remove('d-none');
        animateView(targetView);

        if (window.history && window.history.replaceState) {
            const currentUrl = new URL(window.location.href);
            if (viewName === 'dashboard') {
                currentUrl.searchParams.delete('view');
            } else {
                currentUrl.searchParams.set('view', viewName);
            }
            window.history.replaceState({}, '', currentUrl.toString());
        }

        return false;
    }

    const initialViewName = <?= json_encode($initial_view) ?>;
    const newlyCreatedTaskId = <?= json_encode($newly_created_task_id) ?>;
    document.addEventListener('DOMContentLoaded', () => {
        if (!initialViewName || initialViewName === 'dashboard') {
            return;
        }

        showView(initialViewName, document.querySelector(`[data-view-link="${initialViewName}"]`));

        if (newlyCreatedTaskId) {
            window.requestAnimationFrame(() => {
                const createdTaskRow = document.querySelector(`[data-task-row-id="${newlyCreatedTaskId}"]`);
                if (createdTaskRow) {
                    createdTaskRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            });
        }
    });

    /* ===== ATTENDANCE LOGIC ===== */
    const sessionSelect = document.getElementById('sessionSelect');
    const timeIn = document.getElementById('timeIn');
    const timeOutDisplay = document.getElementById('timeOutDisplay');
    const selectedSessionLabel = document.getElementById('selectedSessionLabel');
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

    function getSessionLabel(session) {
        if (session === 'morning') return 'Morning Session';
        if (session === 'afternoon') return 'Afternoon Session';
        if (session === 'fullday') return 'Full Day Session';
        return 'Select Session';
    }

    function getSessionEndTime(session) {
        if (session === 'morning') return '12:00';
        if (session === 'afternoon' || session === 'fullday') return '17:00';
        return '';
    }

    function formatDisplayTime(timeValue) {
        if (!timeValue) return '--:-- --';
        const [hours, minutes] = timeValue.split(':').map(Number);
        if (Number.isNaN(hours) || Number.isNaN(minutes)) return '--:-- --';
        const suffix = hours >= 12 ? 'PM' : 'AM';
        const hour12 = hours % 12 || 12;
        return `${hour12}:${String(minutes).padStart(2, '0')} ${suffix}`;
    }

    function syncAttendanceMeta(session) {
        if (selectedSessionLabel) {
            selectedSessionLabel.textContent = getSessionLabel(session);
        }
        if (timeOutDisplay) {
            timeOutDisplay.value = formatDisplayTime(getSessionEndTime(session));
        }
    }

    function setAttendanceUnavailable(message, alertClass = 'alert alert-secondary mt-2') {
        const statusMsg = document.getElementById('lateStatusMsg');
        const existingAttendance = getSubmittedAttendance();

        if (existingAttendance) {
            sessionSelect.value = existingAttendance.session;
            timeIn.value = existingAttendance.submittedTime;
            syncAttendanceMeta(existingAttendance.session);
            hoursDisplay.textContent = 'Submitted';
        } else {
            timeIn.value = '';
            syncAttendanceMeta('');
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
            const existingSession = existingAttendance ? getSessionLabel(existingAttendance.session) : 'selected';
            const existingTime = existingAttendance ? existingAttendance.submittedTime : '';
            setAttendanceUnavailable(
                `<i class="fas fa-lock me-1"></i> You already timed in for today's <strong>${existingSession}</strong>${existingTime ? ` at ${existingTime}` : ''}. DTR login is unavailable after the first time-in.`,
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
            syncAttendanceMeta('');
            hoursDisplay.textContent = '0.00';
            calculatedHoursHidden.value = '0.00';
            submitBtn.disabled = true;
            submitBtn.textContent = 'Submit Attendance Log';
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
        syncAttendanceMeta(session);
        submitBtn.disabled = false;
        submitBtn.textContent = 'Submit Attendance Log';
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

    /* Org Hours Calc Removed */

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

    /* Org Modal Logic Removed */
</script>

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
        <input type="hidden" id="current_session" value="<?= htmlspecialchars($current_session ?? '') ?>">
        
        <div class="modal-body">
              <div class="card border-warning mb-3 d-none" id="submitConfirmCard">
            <div class="card-body">
              <div class="fw-bold mb-2">Submit Task now?</div>
              <div class="text-muted small mb-3">Please double-check your task title, description, time, and attachments before submitting.</div>
              <div class="d-flex gap-2 justify-content-end">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="confirmCancelBtn">Review</button>
                <button type="button" class="btn btn-warning btn-sm" id="confirmSubmitBtn">Submit now</button>
              </div>
            </div>
          </div>
          <div class="row mb-3">
            <div class="col-md-6">
              <label class="form-label">Date of Work <span class="text-danger">*</span></label>
             <input type="date" name="work_date" id="work-date-input" class="form-control"  value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-6">
             <label class="form-label">Attachment 1 (Before)</label>
<input type="file" name="photo" class="form-control mb-3">

<label class="form-label">Attachment 2 (After)</label>
<input type="file" name="photo2" class="form-control">
            <div class="form-text">Upload images, PDFs, documents, spreadsheets, or other non-executable files.</div>

            </div>
          </div>
          
          <div class="mb-3">
            <label class="form-label">Task Title <span class="text-muted">(Optional)</span></label>
            <input type="text" name="task_title" id="modal_task_title" class="form-control mb-2" 
                   placeholder="e.g., Task 1"
                   maxlength="<?= RSERVES_STUDENT_TASK_TITLE_MAX_CHARS ?>">
            
            <label class="form-label">Activity Description <span class="text-danger">*</span></label>
            <textarea name="activity" id="modal_activity" class="form-control" rows="3" 
                      placeholder="e.g., Gardening (1st Phase)"
                      maxlength="<?= RSERVES_STUDENT_DESCRIPTION_MAX_CHARS ?>" required></textarea>
            <div class="form-text">Keep descriptions within <?= RSERVES_STUDENT_DESCRIPTION_MAX_CHARS ?> characters.</div>
          </div>
          
          <div class="row mb-3">
            <div class="col-md-4">
              <label class="form-label">Time Started <span class="text-danger">*</span></label>
              <input type="text" class="form-control acc-time-start-display" value="<?= htmlspecialchars($time_in_today ? date('h:i A', strtotime($time_in_today)) : '') ?>" readonly>
              <input type="hidden" name="time_start" class="form-control acc-time-start" value="<?= htmlspecialchars($time_in_today ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Time Ended <span class="text-danger req-star">*</span></label>
              <input type="text" class="form-control acc-time-end-display" readonly>
              <input type="hidden" name="time_end" class="form-control acc-time-end" required>
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

<script>
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
      // const modalEl = document.getElementById('addAccomplishmentModal');
      modalEl.addEventListener('show.bs.modal', () => {
        allowSubmit = false;
        if (confirmCard) confirmCard.classList.add('d-none');
        const timeStart = document.querySelector('.acc-time-start');
        const timeEnd   = document.querySelector('.acc-time-end');
        const timeEndDisplay = document.querySelector('.acc-time-end-display');
        const hours     = document.querySelector('.acc-hours');
        const sessionInput = document.getElementById('current_session');
        const session   = sessionInput ? sessionInput.value.toLowerCase() : '';
        const submitBtn = modalEl.querySelector('button[name="add_accomplishment"]');
        
        // Helper to format 24h to 12h AM/PM
        function format12h(timeStr) {
            if (!timeStr) return '';
            const [h, m] = timeStr.split(':').map(Number);
            const ampm = h >= 12 ? 'PM' : 'AM';
            const h12 = h % 12 || 12;
            return `${h12}:${String(m).padStart(2, '0')} ${ampm}`;
        }

        // Determine allowed end time based on session
        let allowedEndTimeStr = '';
        if (session === 'morning') allowedEndTimeStr = '12:00';
        else if (session === 'afternoon') allowedEndTimeStr = '17:00';
        else if (session === 'fullday') allowedEndTimeStr = '17:00';
        
        // Strict logic: If a session is set, use the session's exact end time.
        // If not (e.g., timed in but no session, which shouldn't happen), use current time.
        // However, we must wait until that time is reached.

        const now = new Date();
        const currentHours = now.getHours();
        const currentMins = now.getMinutes();
        
        let isAllowed = true;

        if (false && allowedEndTimeStr) { // Temporary: keep the submit cutoff disabled while testing.
            const [allowedH, allowedM] = allowedEndTimeStr.split(':').map(Number);
            // Check if current time is BEFORE allowed time
            if (currentHours < allowedH || (currentHours === allowedH && currentMins < allowedM)) {
                isAllowed = false;
            }
        }
        
        const allowedTimeDisplay = format12h(allowedEndTimeStr);

        if (allowedEndTimeStr && !isAllowed) {
            // Not yet time
            timeEnd.value = '';
            timeEndDisplay.value = '';
            hours.value = '';
            submitBtn.disabled = true;
            submitBtn.innerHTML = `Wait until ${allowedTimeDisplay} to submit`;
            submitBtn.classList.add('btn-secondary');
            submitBtn.classList.remove('btn-custom');
        } else {
            // Time is reached or passed
            submitBtn.disabled = false;
            submitBtn.innerHTML = '✓ Submit to Adviser';
            submitBtn.classList.remove('btn-secondary');
            submitBtn.classList.add('btn-custom');

            // Auto-set Time Ended - STRICTLY to session end time if session exists
            if (allowedEndTimeStr) {
                timeEnd.value = allowedEndTimeStr;
                timeEndDisplay.value = format12h(allowedEndTimeStr);
            } else {
                // Fallback to current time only if no session logic applies
                const h = String(currentHours).padStart(2, '0');
                const m = String(currentMins).padStart(2, '0');
                const timeStr = `${h}:${m}`;
                timeEnd.value = timeStr;
                timeEndDisplay.value = format12h(timeStr);
            }
            
            // Trigger calculation
            if (timeStart.value && timeEnd.value) {
                 const workDate = document.querySelector('input[name="work_date"]').value;
                 const start = new Date(`${workDate}T${timeStart.value}`);
                 const end = new Date(`${workDate}T${timeEnd.value}`);
                 
                 let diff = (end - start) / 3600000; // hours
                 if (diff < 0) diff = 0; // Prevent negative hours
                 
                 hours.value = diff.toFixed(2);
            }
        }
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

    /* Custom Org Listeners Removed */

    // Notification Read Status
    let unreadNotificationCount = <?= (int) $unread_count ?>;

    function getNotificationKey(type, id) {
        return `${type}-${id}`;
    }

    function updateNotificationBadges() {
        const count = Math.max(0, unreadNotificationCount);

        document.querySelectorAll('[data-notification-count-badge]').forEach((badge) => {
            if (count <= 0) {
                badge.style.display = 'none';
                return;
            }

            badge.style.display = '';
            badge.textContent = count > 9 ? '9+' : String(count);
        });

        document.querySelectorAll('[data-notification-mark-all-button]').forEach((button) => {
            button.style.display = count > 0 ? '' : 'none';
            button.disabled = count <= 0;
        });
    }

    function syncNotificationEmptyStates() {
        const hasNotifications = unreadNotificationCount > 0;
        const dropdownEmptyState = document.querySelector('[data-notification-empty="dropdown"]');
        const fullEmptyState = document.querySelector('[data-notification-empty="full"]');
        const viewAllLink = document.querySelector('[data-notification-view-all-link]');
        const notificationGroups = document.querySelector('[data-notification-groups]');

        if (dropdownEmptyState) {
            dropdownEmptyState.classList.toggle('d-none', hasNotifications);
        }

        if (fullEmptyState) {
            fullEmptyState.classList.toggle('d-none', hasNotifications);
        }

        if (viewAllLink) {
            viewAllLink.classList.toggle('d-none', !hasNotifications);
        }

        if (notificationGroups) {
            notificationGroups.classList.toggle('d-none', !hasNotifications);
        }
    }

    function cleanupNotificationGroups() {
        document.querySelectorAll('[data-notification-group]').forEach((group) => {
            if (!group.querySelector('[data-notification-item][data-notification-context="full"]')) {
                group.remove();
            }
        });
    }

    function setNotificationPending(type, id, isPending) {
        const key = getNotificationKey(type, id);
        document.querySelectorAll(`[data-notification-action-key="${key}"]`).forEach((button) => {
            button.disabled = isPending;
        });
    }

    function removeNotificationFromDom(type, id) {
        const key = getNotificationKey(type, id);
        const notificationItems = document.querySelectorAll(`[data-notification-key="${key}"]`);

        if (!notificationItems.length) {
            return false;
        }

        notificationItems.forEach((item) => item.remove());
        cleanupNotificationGroups();
        return true;
    }

    function applyNotificationRemoval(type, id) {
        if (removeNotificationFromDom(type, id)) {
            unreadNotificationCount = Math.max(0, unreadNotificationCount - 1);
            updateNotificationBadges();
            syncNotificationEmptyStates();
        }
    }

    function clearAllNotificationsFromDom() {
        document.querySelectorAll('[data-notification-item]').forEach((item) => item.remove());
        document.querySelectorAll('[data-notification-group]').forEach((group) => group.remove());
        unreadNotificationCount = 0;
        updateNotificationBadges();
        syncNotificationEmptyStates();
    }

    function submitNotificationRead(payload) {
        return fetch('mark_notification_read.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(payload)
        }).then(async (response) => {
            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Unable to update notification.');
            }

            return data;
        });
    }

    function openNotificationDestination(type, targetView, certificateCode) {
        if (type === 'certificate' && certificateCode) {
            window.location.href = `view_certificate.php?code=${encodeURIComponent(certificateCode)}`;
            return;
        }

        if (targetView) {
            showView(targetView, document.querySelector(`[data-view-link="${targetView}"]`));
        }
    }

    window.markAsRead = function(type, id, callback) {
        setNotificationPending(type, id, true);

        return submitNotificationRead({ type, id })
            .then(() => {
                applyNotificationRemoval(type, id);
            })
            .catch((error) => {
                console.error('Error:', error);
            })
            .finally(() => {
                setNotificationPending(type, id, false);
                if (callback && typeof callback === 'function') {
                    callback();
                }
            });
    };

    window.readNotification = function(type, id, targetView, certificateCode) {
        window.markAsRead(type, id, function() {
            openNotificationDestination(type, targetView, certificateCode);
        });
        return false;
    };

    window.markNotificationReadOnly = function(type, id) {
        window.markAsRead(type, id);
        return false;
    };

    window.markAllAsRead = function() {
        document.querySelectorAll('[data-notification-mark-all-button]').forEach((button) => {
            button.disabled = true;
        });

        submitNotificationRead({ action: 'mark_all' })
            .then(() => {
                clearAllNotificationsFromDom();
            })
            .catch((error) => {
                console.error('Error:', error);
            })
            .finally(() => {
                updateNotificationBadges();
            });

        return false;
    };

    updateNotificationBadges();
    syncNotificationEmptyStates();

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
