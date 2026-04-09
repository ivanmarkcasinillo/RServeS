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

// Get today's time_in records to prevent duplicate submissions
$today = date('Y-m-d');
$todays_records = [];
$stmt = $conn->prepare("SELECT session, time_in FROM time_records WHERE student_id = ? AND work_date = ?");
$stmt->bind_param("is", $student_id, $today);
$stmt->execute();
$res = $stmt->get_result();
while($row = $res->fetch_assoc()) {
    $todays_records[$row['session']] = $row['time_in'];
}
$stmt->close();
$time_in_today = !empty($todays_records) ? reset($todays_records) : ''; 


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
        if (!empty($_POST['prefill_stask_id'])) {
            $stask_id_val = intval($_POST['prefill_stask_id']);
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
    (student_id, work_date, activity, time_start, time_end, hours, status, photo, photo2, assigner_id) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("issssdsssi", 
            $student_id, $work_date, $activity, $time_start, $time_end, 
            $hours, $status, $photo1, $photo2, $assigner_id
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
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }
        $stmt->close();
    }
}

$stmt = $conn->prepare("
    SELECT 
        id,
        student_id,
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

    ORDER BY work_date ASC
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$res = $stmt->get_result();
$accomplishment_reports = [];
$daily_hours = [];
while ($row = $res->fetch_assoc()) {
    $accomplishment_reports[] = $row;
    
    // Aggregate hours for chart (only Approved/Verified)
    if ($row['status'] == 'Approved' || $row['status'] == 'Verified') {
        $date = date('M d', strtotime($row['work_date']));
        if (!isset($daily_hours[$date])) {
            $daily_hours[$date] = 0;
        }
        $daily_hours[$date] += $row['hours'];
    }
}
// Keep only last 7 days for the chart if too many
if (count($daily_hours) > 7) {
    $daily_hours = array_slice($daily_hours, -7, 7, true);
}
$chart_labels = array_keys($daily_hours);
$chart_data = array_values($daily_hours);
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
    $date = $_POST['work_date'];
    $session = $_POST['session'];
    $time_in = $_POST['time_in']; // Get actual time_in from form
    
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

if (isset($_POST['create_verbal_task'])) {
    $task_title = trim($_POST['task_title']);
    $task_desc = trim($_POST['task_description']);
    $duration = $_POST['duration'];
    $assigner_id = isset($_POST['assigner_id']) ? intval($_POST['assigner_id']) : $student['instructor_id'];
    
    if (!empty($task_title)) {
        $conn->begin_transaction();

        try {
            $stmt = $conn->prepare("INSERT INTO tasks (title, description, duration, instructor_id, department_id, created_by_student, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            if (!$stmt) {
                throw new RuntimeException($conn->error);
            }

            $stmt->bind_param("sssiii", $task_title, $task_desc, $duration, $assigner_id, $student['department_id'], $student_id);
            if (!$stmt->execute()) {
                $stmt_error = $stmt->error;
                $stmt->close();
                throw new RuntimeException($stmt_error);
            }

            $new_task_id = $stmt->insert_id;
            $stmt->close();

            $stmt2 = $conn->prepare("INSERT INTO student_tasks (task_id, student_id, status, assigned_at) VALUES (?, ?, 'Pending', NOW())");
            if (!$stmt2) {
                throw new RuntimeException($conn->error);
            }

            $stmt2->bind_param("ii", $new_task_id, $student_id);
            if (!$stmt2->execute()) {
                $stmt2_error = $stmt2->error;
                $stmt2->close();
                throw new RuntimeException($stmt2_error);
            }

            $stmt2->close();
            $conn->commit();
            $_SESSION['flash'] = "Verbal task '{$task_title}' created successfully!";
        } catch (Throwable $e) {
            $conn->rollback();
            error_log("Verbal task creation failed for student {$student_id}: " . $e->getMessage());
            $_SESSION['flash'] = "Task creation failed. Please try again.";
        }

        header("Location: ".$_SERVER['PHP_SELF']);
        exit;
    }
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

/* Organization Task Creation Removed */

$tasks = [];
$query = "
    SELECT 
        st.stask_id, st.status, st.assigned_at, 
        t.task_id, t.title, t.description, t.duration, t.created_by_student, t.created_at, t.instructor_id,
        i.firstname as inst_fname, i.lastname as inst_lname,
        CASE 
            WHEN t.created_by_student = ? THEN 'verbal'
            ELSE 'adviser'
        END as task_type
    FROM student_tasks st
    INNER JOIN tasks t ON st.task_id = t.task_id
    LEFT JOIN instructors i ON t.instructor_id = i.inst_id
    WHERE st.student_id = ? AND st.status = 'Pending'
    ORDER BY t.created_at DESC
";

$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $student_id, $student_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $tasks[] = $row;
}
$stmt->close();

// Filter out tasks that are effectively completed (Verified in Accomplishment Reports)
$pending_tasks = [];
foreach ($tasks as $task) {
    $is_completed = false;
    foreach ($accomplishment_reports as $ar) {
        // Check if this task is linked to this accomplishment report
        if (strpos($ar['activity'], '[TaskID:' . $task['stask_id'] . ']') !== false) {
            // If the report is Verified or Approved, the task is effectively completed
            if ($ar['status'] === 'Verified' || $ar['status'] === 'Approved') {
                $is_completed = true;
                break;
            }
        }
    }
    if (!$is_completed) {
        $pending_tasks[] = $task;
    }
}
$tasks = $pending_tasks;

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
/* Org Notification Loop Removed */
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - College of Technology</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Urbanist:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        :root {
            --primary-color: #0e2a47;
            --secondary-color: #1a4f7a;
            --accent-color: #3a8ebd;
            --bg-color: #eef2f5;
            --text-dark: #2c3e50;
            --text-muted: #6c757d;
            --card-bg: #ffffff;
            --sidebar-width: 280px;
            --border-radius: 16px;
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 12px rgba(0,0,0,0.08);
        }

        body {
            font-family: 'Urbanist', sans-serif;
            color: var(--text-dark);
            background-color: var(--bg-color);
            overflow-x: hidden;
        }

        /* Sidebar Styles */
        #sidebar-wrapper {
            min-height: 100vh;
            width: var(--sidebar-width);
            margin-left: 0;
            transition: margin 0.25s ease-out;
            background: var(--primary-color);
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            padding: 2rem 1.5rem;
        }

        .sidebar-profile {
            text-align: center;
            margin-bottom: 2rem;
            color: white;
        }

        .sidebar-profile img {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 50%;
            border: 3px solid rgba(255,255,255,0.2);
            margin-bottom: 1rem;
        }
        
        .sidebar-profile h5 {
            font-size: 1.1rem;
            margin-bottom: 0.2rem;
            font-weight: 700;
        }
        
        .sidebar-profile p {
            font-size: 0.85rem;
            opacity: 0.7;
            margin-bottom: 1rem;
        }

        .sidebar-menu {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            flex-grow: 1;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            padding: 0.8rem 1rem;
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            border-radius: 12px;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .sidebar-link:hover, .sidebar-link.active {
            background: rgba(255,255,255,0.1);
            color: white;
        }

        .sidebar-link i {
            width: 24px;
            margin-right: 12px;
            font-size: 1.1rem;
        }
        
        .sidebar-footer {
            margin-top: auto;
        }

        /* Page Content */
        #page-content-wrapper {
            width: 100%;
            margin-left: var(--sidebar-width);
            transition: margin 0.25s ease-out;
            padding: 2rem;
        }

        /* Header */
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            background: var(--card-bg);
            padding: 1rem 2rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
        }

        .search-bar {
            position: relative;
            width: 300px;
        }

        .search-bar input {
            width: 100%;
            padding: 0.6rem 1rem 0.6rem 2.5rem;
            border-radius: 20px;
            border: 1px solid #e0e0e0;
            background: #f8f9fa;
            font-size: 0.9rem;
        }

        .search-bar i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #aaa;
        }

        .header-actions {
            display: flex;
            gap: 1rem;
        }

        .icon-btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-dark);
            text-decoration: none;
            transition: all 0.2s;
            position: relative;
        }

        .icon-btn:hover {
            background: #e9ecef;
            color: var(--primary-color);
        }

        /* Stats Cards */
        .stat-card-v2 {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
            height: 100%;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: transform 0.2s;
        }

        .stat-card-v2:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }

        .stat-icon-wrapper {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
        }
        
        .stat-content h3 {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0;
            color: var(--text-dark);
        }
        
        .stat-content p {
            margin: 0;
            font-size: 0.85rem;
            color: var(--text-muted);
            font-weight: 500;
        }

        /* Charts & Content Area */
        .content-card-v2 {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
            height: 100%;
            border: none;
        }
        
        .card-header-v2 {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .card-title-v2 {
            font-size: 1.1rem;
            font-weight: 700;
            margin: 0;
            color: var(--text-dark);
        }

        /* Responsive */
        /* Media queries moved to end of style block */
        
        /* Utility */
        .bg-light-blue { background-color: #e3f2fd; color: #1976d2; }
        .bg-light-green { background-color: #e8f5e9; color: #2e7d32; }
        .bg-light-orange { background-color: #fff3e0; color: #ef6c00; }
        .bg-light-red { background-color: #ffebee; color: #c62828; }
        
        /* Modal Customization */
        .modal-content {
            border-radius: var(--border-radius);
            border: none;
            box-shadow: var(--shadow-md);
        }
        
        /* Hide scrollbar for clean look */
        ::-webkit-scrollbar {
            width: 8px;
        }
        ::-webkit-scrollbar-track {
            background: #f1f1f1; 
        }
        ::-webkit-scrollbar-thumb {
            background: #ccc; 
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #aaa; 
        }
        
        /* Enhanced Card Styles */
        .stat-card-v2 {
            border-top: 4px solid transparent; /* Prepare for colored top border */
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .stat-card-v2:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        .stat-card-blue { border-top-color: #4e73df; }
        .stat-card-green { border-top-color: #1cc88a; }
        .stat-card-orange { border-top-color: #f6c23e; }
        .stat-card-red { border-top-color: #e74a3b; }
        
        /* Table Enhancements */
        .table-hover tbody tr:hover {
            background-color: rgba(78, 115, 223, 0.05);
        }
        .table thead th {
            background-color: #f8f9fc;
            border-bottom: 2px solid #e3e6f0;
            color: #858796;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.8rem;
        }

        /* Bottom Navbar (Mobile) */
        .bottom-navbar {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 65px;
            background: #ffffff;
            box-shadow: 0 -4px 20px rgba(0,0,0,0.08);
            display: flex;
            justify-content: space-around;
            align-items: center;
            z-index: 1050;
            padding-bottom: env(safe-area-inset-bottom);
            border-top-left-radius: 20px;
            border-top-right-radius: 20px;
        }
        
        .bottom-nav-link {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            color: #b0b3c5;
            font-size: 0.7rem;
            font-weight: 600;
            flex: 1;
            height: 100%;
            transition: all 0.2s ease;
            position: relative;
        }
        
        .bottom-nav-link i {
            font-size: 1.3rem;
            margin-bottom: 4px;
            transition: transform 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        
        .bottom-nav-link.active {
            color: var(--primary-color);
        }
        
        .bottom-nav-link.active i {
            transform: translateY(-2px);
        }
        
        .bottom-nav-indicator {
            position: absolute;
            top: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 40px;
            height: 4px;
            background: var(--primary-color);
            border-bottom-left-radius: 4px;
            border-bottom-right-radius: 4px;
            opacity: 0;
            transition: opacity 0.2s;
        }
        
        .bottom-nav-link.active .bottom-nav-indicator {
            opacity: 1;
        }

        /* Responsive Adjustments */
        @media (min-width: 993px) {
            .bottom-navbar {
                display: none;
            }
        }
        
        @media (max-width: 992px) {
            #sidebar-wrapper {
                display: none !important;
            }
            #page-content-wrapper {
                margin-left: 0;
                padding-bottom: 80px !important; /* Space for bottom nav */
            }
            #menu-toggle {
                display: none !important;
            }
            .dashboard-header {
                flex-direction: column;
                gap: 1rem;
                align-items: stretch;
                padding: 1.5rem;
            }
            .search-bar {
                width: 100%;
            }
            .header-actions {
                justify-content: flex-end;
            }
        }
    </style>
</head>
<body>

<div class="d-flex" id="wrapper">
    <!-- Sidebar -->
    <div id="sidebar-wrapper">
        <div class="sidebar-profile">
            <img src="../<?php echo htmlspecialchars($photo); ?>" alt="Profile">
            <h5 class="text-truncate px-2"><?php echo htmlspecialchars($student['firstname']); ?></h5>
            <p class="mb-0 text-white-50">Student</p>
        </div>
        
        <div class="sidebar-menu">
            <a href="#" class="sidebar-link active" onclick="showView('dashboard', this)">
                <i class="fas fa-th-large"></i> Dashboard
            </a>
            <a href="#" class="sidebar-link" onclick="showView('tasks', this)">
                <i class="fas fa-tasks"></i> Tasks
                <?php if(count($tasks) > 0): ?>
                    <span class="badge bg-danger rounded-pill ms-auto"><?= count($tasks) ?></span>
                <?php endif; ?>
            </a>
            <a href="#" class="sidebar-link" onclick="showView('documents', this)">
                <i class="fas fa-file-alt"></i> Documents
            </a>
            <a href="#" class="sidebar-link" onclick="showView('notifications', this)">
                <i class="fas fa-bell"></i> Notifications
                <?php if ($unread_count > 0): ?>
                    <span class="badge bg-danger rounded-pill ms-auto"><?= $unread_count ?></span>
                <?php endif; ?>
            </a>
            <a href="#" class="sidebar-link" data-bs-toggle="modal" data-bs-target="#profileModal">
                <i class="fas fa-user-circle"></i> Profile
            </a>
        </div>
        
        <div class="sidebar-footer">
            <a href="logout.php" class="sidebar-link">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>

    <!-- Page Content -->
    <div id="page-content-wrapper">
        <!-- New Header -->
        <div class="dashboard-header">
            <button class="btn btn-link d-lg-none me-3" id="menu-toggle">
                <i class="fas fa-bars fa-lg text-dark"></i>
            </button>
            <div>
                <h4 class="mb-1 fw-bold">Welcome, <?php echo htmlspecialchars($student['firstname']); ?>! 👋</h4>
                <p class="text-muted mb-0">Here's what's happening with your internship today.</p>
            </div>
            
            <div class="d-flex align-items-center gap-3">
                <div class="search-bar d-none d-md-block">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Search tasks or activities..." id="searchInput">
                </div>
                
                <div class="header-actions">
                    <a href="#" class="icon-btn" onclick="showView('notifications', document.querySelector('.sidebar-link:nth-child(4)'))">
                        <i class="fas fa-bell"></i>
                        <?php if ($unread_count > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle p-1 bg-danger border border-light rounded-circle"></span>
                        <?php endif; ?>
                    </a>
                    <a href="#" class="icon-btn" data-bs-toggle="modal" data-bs-target="#profileModal">
                        <i class="fas fa-user"></i>
                    </a>
                </div>
            </div>
        </div>

        <div class="container-fluid p-0" id="main-content">
            
            <!-- Flash Message -->
            <?php if (isset($_SESSION['flash'])): ?>
                <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($_SESSION['flash']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['flash']); ?>
            <?php endif; ?>

            <!-- DASHBOARD VIEW -->
            <div id="view-dashboard">
                <!-- Stats Row -->
                <!-- Stats Cards -->
                <div class="row g-2 g-md-4 mb-4">
                    <div class="col-6 col-xl-3">
                        <div class="stat-card-v2 stat-card-blue p-2 p-md-3">
                            <div class="stat-icon-wrapper bg-light-blue mb-2">
                                <i class="fas fa-clock text-primary"></i>
                            </div>
                            <div class="stat-content">
                                <h3 class="fs-4 mb-0">300</h3>
                                <p class="small text-muted mb-0 fw-bold">Required</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-xl-3">
                        <div class="stat-card-v2 stat-card-green p-2 p-md-3">
                            <div class="stat-icon-wrapper bg-light-green mb-2">
                                <i class="fas fa-check-circle text-success"></i>
                            </div>
                            <div class="stat-content">
                                <h3 class="fs-4 mb-0"><?= number_format($total_hours_completed, 1) ?></h3>
                                <p class="small text-muted mb-0 fw-bold">Completed</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-xl-3">
                        <div class="stat-card-v2 stat-card-orange p-2 p-md-3">
                            <div class="stat-icon-wrapper bg-light-orange mb-2">
                                <i class="fas fa-hourglass-half text-warning"></i>
                            </div>
                            <div class="stat-content">
                                <h3 class="fs-4 mb-0"><?= number_format(max(0, 300 - $total_hours_completed), 1) ?></h3>
                                <p class="small text-muted mb-0 fw-bold">Remaining</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-xl-3">
                        <div class="stat-card-v2 stat-card-red p-2 p-md-3">
                            <div class="stat-icon-wrapper bg-light-red mb-2">
                                <i class="fas fa-tasks text-danger"></i>
                            </div>
                            <div class="stat-content">
                                <h3 class="fs-4 mb-0"><?= count($tasks) ?></h3>
                                <p class="small text-muted mb-0 fw-bold">Pending Tasks</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Middle Row: Unified Performance -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="content-card-v2 position-relative overflow-hidden">
                            <div class="card-header-v2 d-flex flex-wrap justify-content-between align-items-center gap-2">
                                <div>
                                    <h5 class="card-title-v2">Performance Tracker</h5>
                                    <p class="text-muted small mb-0">Real-time progress monitoring</p>
                                </div>
                                <div class="d-flex gap-3 align-items-center">
                                    <div class="d-flex align-items-center">
                                        <span class="d-inline-block rounded-circle me-1" style="width: 10px; height: 10px; background-color: #1cc88a;"></span>
                                        <small class="text-muted fw-bold" style="font-size: 0.75rem;">Progress</small>
                                    </div>
                                    <div class="d-flex align-items-center">
                                        <span class="d-inline-block rounded-circle me-1" style="width: 10px; height: 10px; background-color: #4e73df;"></span>
                                        <small class="text-muted fw-bold" style="font-size: 0.75rem;">Activity</small>
                                    </div>
                                    <span class="badge bg-light text-primary border ms-2"><?= $progress_message ?></span>
                                </div>
                            </div>
                            <div class="position-relative w-100 mt-2" style="height: 320px;">
                                <!-- Background Graph -->
                                <div style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: 1;">
                                    <canvas id="activityChart"></canvas>
                                </div>
                                <!-- Foreground Circle -->
                                <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); height: 260px; width: 260px; z-index: 10;">
                                    <canvas id="completionChart"></canvas>
                                </div>
                                <!-- Center Text -->
                                <div class="position-absolute top-50 start-50 translate-middle text-center rounded-circle d-flex flex-column justify-content-center align-items-center" 
                                     style="z-index: 11; width: 150px; height: 150px; pointer-events: none;">
                                    <h1 class="display-6 fw-bold mb-0 text-dark" style="letter-spacing: -1px; text-shadow: 2px 2px 4px rgba(255,255,255,0.8);"><?= round($progress_percent) ?><span class="fs-4">%</span></h1>
                                    <small class="text-uppercase text-muted fw-bold" style="font-size: 0.65rem; letter-spacing: 1px;">Completed</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Bottom Row: Attendance Form -->
                <div class="row g-3 g-lg-4">
                    <div class="col-12">
                        <div class="content-card-v2">
                            <div class="card-header-v2">
                                <h5 class="card-title-v2"><i class="fas fa-user-clock me-2"></i>Daily Attendance</h5>
                                <span class="badge bg-primary d-none d-sm-inline">Today: <?= date('M d, Y') ?></span>
                            </div>
                            
                            <div class="alert alert-light border-start border-4 border-info py-2 mb-3 d-none d-md-block">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-info-circle text-info me-3"></i>
                                    <div>
                                        <strong class="small">Attendance Rules:</strong>
                                        <span class="small text-muted ms-2">Morning: 8-12 • Afternoon: 1-5 • Full Day: 8-5</span>
                                    </div>
                                </div>
                            </div>
                            <!-- Mobile Rules (Compact) -->
                            <div class="d-md-none mb-3 text-muted small">
                                <i class="fas fa-info-circle text-info me-1"></i> Morning: 8-12, Afternoon: 1-5
                            </div>

                            <form method="POST" id="attendanceForm" class="row g-2 g-md-3 align-items-end">
                                <div class="col-6 col-md-3">
                                    <label class="form-label fw-bold small text-muted">Session Type</label>
                                    <select name="session" id="sessionSelect" class="form-select border-0 bg-light" required>
                                        <option value="">Select Session...</option>
                                        <option value="morning">Morning (8-12)</option>
                                        <option value="afternoon">Afternoon (1-5)</option>
                                        <option value="fullday">Full Day (8-5)</option>
                                    </select>
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label fw-bold small text-muted">Time In</label>
                                    <input type="time" name="time_in" id="timeIn" class="form-control border-0 bg-light" readonly>
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label fw-bold small text-muted">Calculated Hours</label>
                                    <div class="form-control border-0 bg-light fw-bold text-primary" id="hoursDisplay">0.00</div>
                                    <input type="hidden" name="calculated_hours" id="calculatedHoursHidden">
                                    <input type="hidden" name="work_date" value="<?= date('Y-m-d') ?>">
                                </div>
                                <div class="col-6 col-md-3">
                                    <button type="submit" name="submit_time" id="submitBtn" class="btn btn-primary w-100 py-2" disabled>
                                        Submit
                                    </button>
                                </div>
                            </form>
                            <div id="lateStatusMsg" class="mt-2" style="display: none;"></div>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Activity -->
                <div class="row mt-4 mb-4">
                    <div class="col-12">
                        <div class="content-card-v2">
                            <div class="card-header-v2">
                                <h5 class="card-title-v2">Recent Activity</h5>
                                <a href="#" class="btn btn-sm btn-link" onclick="showView('documents', document.querySelector('.sidebar-link:nth-child(3)'))">View All</a>
                            </div>
                            <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                                <table class="table table-hover table-sm align-middle mb-0">
                                    <thead class="sticky-top bg-white">
                                        <tr>
                                            <th class="py-3 ps-3">Date</th>
                                            <th class="py-3">Activity</th>
                                            <th class="py-3">Hours</th>
                                            <th class="py-3 pe-3">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        // $accomplishment_reports is already sorted by date ASC from the query
                                        $recent_activities = array_slice($accomplishment_reports, -5); 
                                        $recent_activities = array_reverse($recent_activities); // Show newest first
                                        if (empty($recent_activities)): ?>
                                            <tr>
                                                <td colspan="4" class="text-center text-muted py-4">
                                                    <div class="py-3">
                                                        <i class="fas fa-folder-open fa-2x mb-2 text-gray-300"></i>
                                                        <p class="mb-0 small">No recent activity found.</p>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($recent_activities as $activity): ?>
                                                <tr>
                                                    <td class="ps-3 fw-bold text-muted small"><?= date('M d, Y', strtotime($activity['work_date'])) ?></td>
                                                    <td>
                                                        <span class="d-inline-block text-truncate" style="max-width: 200px;" title="<?= htmlspecialchars($activity['activity']) ?>">
                                                            <?= htmlspecialchars($activity['activity']) ?>
                                                        </span>
                                                    </td>
                                                    <td class="fw-bold text-dark"><?= number_format($activity['hours'], 2) ?></td>
                                                    <td class="pe-3">
                                                        <?php if($activity['status'] == 'Approved' || $activity['status'] == 'Verified'): ?>
                                                            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1 rounded-pill small"><i class="fas fa-check-circle me-1"></i>Completed</span>
                                                        <?php elseif($activity['status'] == 'Pending'): ?>
                                                            <span class="badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25 px-2 py-1 rounded-pill small"><i class="fas fa-clock me-1"></i>Pending</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 px-2 py-1 rounded-pill small"><i class="fas fa-times-circle me-1"></i><?= $activity['status'] ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Instructions Toggle (Kept minimal) -->
                <div class="text-center mt-4">
                    <button class="btn btn-link text-muted text-decoration-none btn-sm" onclick="toggleInstructions()">
                        <i class="fas fa-question-circle me-1"></i> View Detailed Guidelines
                    </button>
                </div>
                
                <div class="card mt-3 d-none" id="instructionsCard"> 
                    <div class="card-body">
                        <h5><i class="fas fa-info-circle me-2"></i>Guidelines</h5> 
                        <p class="small text-muted">Students must complete <strong>320 hours</strong> of Return Service System (RSS) work. You must finish all required hours before your 4th year.</p> 
                        <ul class="small text-muted"> 
                            <li><strong>Morning Session:</strong> Time in between 8:00 AM - 8:30 AM, Time out at 12:00 PM (4 hours max). Late arrivals reduce hours proportionally.</li> 
                            <li><strong>Afternoon Session:</strong> Time in between 1:00 PM - 1:30 PM, Time out at 5:00 PM (4 hours max). Late arrivals reduce hours proportionally.</li> 
                            <li><strong>Full Day:</strong> Time in between 8:00 AM - 8:30 AM, Time out at 5:00 PM (8 hours max). Late arrivals reduce hours proportionally.</li> 
                        </ul> 
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
                        <div class="content-card-v2">
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

            <!-- TASKS VIEW -->
            <div id="view-tasks" class="d-none">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>My Tasks</h2>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#verbalTaskModal">
                        <i class="fas fa-plus me-2"></i>Create Verbal Task
                    </button>
                </div>

                <div class="content-card-v2">
                    <?php if (empty($tasks)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fas fa-clipboard-list fa-3x mb-3"></i>
                            <p>No tasks assigned yet.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($tasks as $task): ?>
                            <?php 
                            $isVerbal = ($task['task_type'] === 'verbal');
                            
                            // Check for matching accomplishment report
                            $arStatus = null;
                            foreach ($accomplishment_reports as $ar) {
                                if (strpos($ar['activity'], '[TaskID:' . $task['stask_id'] . ']') !== false) {
                                    $arStatus = $ar['status'];
                                    break;
                                }
                            }
                            
                            $displayStatus = $task['status'];
                            $disableSubmit = false;
                            
                            if ($arStatus === 'Pending') {
                                $displayStatus = 'Pending Approval';
                                $disableSubmit = true;
                            } elseif ($arStatus === 'Verified' || $arStatus === 'Approved') {
                                 $displayStatus = 'Completed';
                                 $disableSubmit = true;
                            } elseif ($arStatus === 'Rejected') {
                                $displayStatus = 'Rejected';
                            }
                            ?>
                            <div class="task-item">
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
                                            <span class="badge bg-warning text-dark ms-1"><?= htmlspecialchars($task['status']) ?></span>
                                        <?php endif; ?>
                                        <small class="text-muted ms-2"><?= date('M d, Y', strtotime($task['created_at'])) ?></small>
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

            <!-- ORGANIZATIONS VIEW -->
            <!-- Orgs View Removed -->

            <!-- DOCUMENTS VIEW -->
            <div id="view-documents" class="d-none">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>Documents</h2>
                </div>

                <div class="row g-4">
                    <div class="col-md-4">
                        <div class="content-card-v2 h-100 text-center p-4 hover-shadow transition-all d-flex flex-column align-items-center justify-content-center">
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

<!-- Org Modal Removed -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>


<!-- Bottom Navbar (Mobile) -->
<div class="bottom-navbar">
    <a href="#" class="bottom-nav-link active" onclick="showView('dashboard', this)">
        <div class="bottom-nav-indicator"></div>
        <i class="fas fa-th-large"></i>
        <span>Home</span>
    </a>
    <a href="#" class="bottom-nav-link" onclick="showView('tasks', this)">
        <div class="bottom-nav-indicator"></div>
        <i class="fas fa-tasks"></i>
        <span>Tasks</span>
    </a>
    <a href="#" class="bottom-nav-link" onclick="showView('documents', this)">
        <div class="bottom-nav-indicator"></div>
        <i class="fas fa-file-alt"></i>
        <span>Docs</span>
    </a>
    <a href="#" class="bottom-nav-link" onclick="showView('notifications', this)">
        <div class="bottom-nav-indicator"></div>
        <i class="fas fa-bell"></i>
        <span>Alerts</span>
        <?php if ($unread_count > 0): ?>
            <span class="position-absolute top-0 end-0 translate-middle p-1 bg-danger border border-light rounded-circle" style="width: 8px; height: 8px; margin-top: 10px; margin-right: 15px;"></span>
        <?php endif; ?>
    </a>
    <a href="#" class="bottom-nav-link" data-bs-toggle="modal" data-bs-target="#profileModal">
        <div class="bottom-nav-indicator"></div>
        <i class="fas fa-user-circle"></i>
        <span>Profile</span>
    </a>
</div>

<script>
    const todaysRecords = <?= json_encode($todays_records) ?>;

    // Sidebar Toggle
    const menuToggle = document.getElementById('menu-toggle');
    if (menuToggle) {
        menuToggle.addEventListener('click', function(e) {
            e.preventDefault();
            document.body.classList.toggle('sidebar-toggled');
        });
    }

    // Close sidebar when any sidebar link is clicked (Mobile)
    document.querySelectorAll('#sidebar-wrapper .sidebar-link').forEach(item => {
        item.addEventListener('click', () => {
            if (window.innerWidth <= 768) {
                document.body.classList.remove('sidebar-toggled');
            }
        });
    });

    // View Management
    function showView(viewName, linkElement) {
        // Update Active State for Sidebar and Bottom Navbar
        document.querySelectorAll('.sidebar-link').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.bottom-nav-link').forEach(el => el.classList.remove('active'));
        
        // Sync active state across both navigations
        const selector = `[onclick*="showView('${viewName}'"]`;
        document.querySelectorAll(selector).forEach(el => {
            el.classList.add('active');
        });

        // Hide all views
        document.getElementById('view-dashboard').classList.add('d-none');
        document.getElementById('view-tasks').classList.add('d-none');
        document.getElementById('view-documents').classList.add('d-none');
        document.getElementById('view-notifications').classList.add('d-none');

        // Show target view
        document.getElementById('view-' + viewName).classList.remove('d-none');

        // Reset Search
        const searchInput = document.getElementById('searchInput');
        if(searchInput) {
            searchInput.value = '';
            // Manually reset visibility of all filterable items to ensure clean state
            document.querySelectorAll('table tbody tr, .task-item, .list-group-item').forEach(el => {
                el.style.display = '';
            });
        }
    }

    /* ===== ATTENDANCE LOGIC ===== */
    const sessionSelect = document.getElementById('sessionSelect');
    const timeIn = document.getElementById('timeIn');
    const calculatedHoursHidden = document.getElementById('calculatedHoursHidden');
    const hoursDisplay = document.getElementById('hoursDisplay');
    const submitBtn = document.getElementById('submitBtn');

    function getPhilippineTime() {
        const now = new Date();
        const utc = now.getTime() + (now.getTimezoneOffset() * 60000);
        return new Date(utc + (8 * 60 * 60000));
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

        if (!session) {
            timeIn.value = '';
            hoursDisplay.textContent = '0.00';
            calculatedHoursHidden.value = '0.00';
            submitBtn.disabled = true;
            submitBtn.textContent = 'Submit Attendance';
            submitBtn.classList.add('btn-primary');
            submitBtn.classList.remove('btn-secondary');
            if(statusMsg) statusMsg.style.display = 'none';
            return;
        }

        // Check if already submitted
        if (todaysRecords && todaysRecords[session]) {
            timeIn.value = todaysRecords[session];
            hoursDisplay.textContent = 'Submitted';
            calculatedHoursHidden.value = '0.00'; 
            submitBtn.disabled = true;
            submitBtn.textContent = 'Already Submitted';
            submitBtn.classList.remove('btn-primary');
            submitBtn.classList.add('btn-secondary');
            
            if(statusMsg) {
                statusMsg.innerHTML = `<i class="fas fa-check-circle"></i> You have already timed in for the <strong>${session}</strong> session at ${todaysRecords[session]}.`;
                statusMsg.className = 'alert alert-success mt-2';
                statusMsg.style.display = 'block';
            }
            return;
        } else {
             // Reset button state if previously disabled
             submitBtn.textContent = 'Submit Attendance';
             submitBtn.classList.add('btn-primary');
             submitBtn.classList.remove('btn-secondary');
             if(statusMsg) statusMsg.style.display = 'none';
        }

        const phTime = getPhilippineTime();
        const currentHours = phTime.getHours();
        const currentMinutes = phTime.getMinutes();
        const formattedTime = `${String(currentHours).padStart(2,'0')}:${String(currentMinutes).padStart(2,'0')}`;
        
        timeIn.value = formattedTime;
        submitBtn.disabled = false;
        calculateAttendanceHours(session, formattedTime);
    }

    setInterval(() => {
        const session = sessionSelect.value;
        if (!session) return;
        
        // If already submitted, do not update time automatically
        if (todaysRecords && todaysRecords[session]) return;

        const phTime = getPhilippineTime();
        const formattedTime = `${String(phTime.getHours()).padStart(2,'0')}:${String(phTime.getMinutes()).padStart(2,'0')}`;
        timeIn.value = formattedTime;
        calculateAttendanceHours(session, formattedTime);
    }, 10000);

    if (sessionSelect) {
        sessionSelect.addEventListener('change', updateSessionSettings);
    }

    /* ===== SEARCH LOGIC ===== */
    const searchInput = document.getElementById('searchInput');
    if(searchInput) {
        searchInput.addEventListener('input', function() {
            const filter = this.value.toLowerCase();
            const activeView = document.querySelector('div[id^="view-"]:not(.d-none)');
            
            if(!activeView) return;
            
            if(activeView.id === 'view-dashboard') {
                // Filter Recent Activity Table
                const rows = activeView.querySelectorAll('table tbody tr');
                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    row.style.display = text.includes(filter) ? '' : 'none';
                });
            } else if (activeView.id === 'view-tasks') {
                // Filter Task Items
                const items = activeView.querySelectorAll('.task-item');
                items.forEach(item => {
                    const text = item.textContent.toLowerCase();
                    // Task item is inside content-card-v2 -> div
                    // We want to hide the task item itself
                    item.style.display = text.includes(filter) ? '' : 'none';
                });
            } else if (activeView.id === 'view-notifications') {
                 // Filter Notifications
                 const items = activeView.querySelectorAll('.list-group-item');
                 items.forEach(item => {
                     const text = item.textContent.toLowerCase();
                     item.style.display = text.includes(filter) ? '' : 'none';
                 });
            } else if (activeView.id === 'view-documents') {
                 // Filter Document Cards
                 const items = activeView.querySelectorAll('.col-md-4');
                 items.forEach(item => {
                     const text = item.textContent.toLowerCase();
                     item.style.display = text.includes(filter) ? '' : 'none';
                 });
            }
        });
    }

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
        const taskItem = document.querySelector(`[data-stask-id="${staskId}"]`);
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

    /* Org Modal Logic Removed */
</script>

<!-- Add Accomplishment Modal -->
<?php if (!in_array($_SESSION['role'], ['Coordinator', 'Instructor'])): ?>
<div class="modal fade" id="addAccomplishmentModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">➕ Submit to Adviser</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" enctype="multipart/form-data">
        <!-- Hidden input for task ID -->
        <input type="hidden" name="prefill_stask_id" id="modal_prefill_stask_id">
        
        <div class="modal-body">
          <div class="row mb-3">
            <div class="col-md-6">
              <label class="form-label">Date of Work <span class="text-danger">*</span></label>
             <input type="date" name="work_date" id="work-date-input" class="form-control"  value="<?= date('Y-m-d') ?>" required>
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

<script>
    // Add Accomplishment Modal Logic
    document.addEventListener('DOMContentLoaded', () => {
      const modalEl = document.getElementById('addAccomplishmentModal');
      if (!modalEl) return;

      const modal = new bootstrap.Modal(modalEl);
      
      const timeStart = document.querySelector('.acc-time-start');
      const timeEnd   = document.querySelector('.acc-time-end');
      const hours     = document.querySelector('.acc-hours');
      const workDate  = document.querySelector('input[name="work_date"]');

      function calculate() {
          if (!timeStart || !timeEnd || !hours) return;
          if (!timeStart.value || !timeEnd.value) return;
          
          let dateVal = '';
          if (workDate && workDate.value) {
              dateVal = workDate.value;
          } else {
              const t = new Date();
              dateVal = `${t.getFullYear()}-${String(t.getMonth()+1).padStart(2,'0')}-${String(t.getDate()).padStart(2,'0')}`;
          }
          
          // Use a dummy date base to calculate time difference correctly
          const baseDate = "2000-01-01"; 
          const start = new Date(`${baseDate}T${timeStart.value}`);
          let end     = new Date(`${baseDate}T${timeEnd.value}`);
          
          // Handle overnight shifts (if end time is earlier than start time)
          if (end < start) {
              end.setDate(end.getDate() + 1);
          }
          
          const diff = (end - start) / 3600000; // hours
          if (!isNaN(diff) && diff > 0) {
            hours.value = diff.toFixed(2);
          } else {
             hours.value = '';
          }
      }

      if (timeStart && timeEnd) {
          timeEnd.addEventListener('input', calculate);
          timeStart.addEventListener('input', calculate);
      }
      
      // Also allow editing timeStart if it's empty initially (e.g. forgot to time in)
      if (timeStart && !timeStart.value) {
          timeStart.removeAttribute('readonly');
      }

      // Expose function to open modal from Task list
      window.openAccomplishmentModal = function(staskId, title, description) {
          document.getElementById('modal_prefill_stask_id').value = staskId || '';
          document.getElementById('modal_task_title').value = title || '';
          document.getElementById('modal_activity').value = description || '';
          
          // Toggle required fields based on whether it's a task
          const stars = document.querySelectorAll('.req-star');
          
          if (staskId) {
              if(timeEnd) timeEnd.removeAttribute('required');
              if(hours) hours.removeAttribute('required');
              stars.forEach(s => s.style.display = 'none');
          } else {
              if(timeEnd) timeEnd.setAttribute('required', 'required');
              if(hours) hours.setAttribute('required', 'required');
              stars.forEach(s => s.style.display = 'inline');
          }
          
          modal.show();
      };
    });

    /* Custom Org Listeners Removed */

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
    // Chart.js Implementation
    document.addEventListener("DOMContentLoaded", function() {
        // Activity Chart
        const ctxActivity = document.getElementById('activityChart');
        if (ctxActivity) {
            new Chart(ctxActivity, {
                type: 'line',
                data: {
                    labels: <?= json_encode($chart_labels ?? []) ?>,
                    datasets: [{
                        label: 'Hours Worked',
                        data: <?= json_encode($chart_data ?? []) ?>,
                        borderColor: 'rgba(78, 115, 223, 0.25)', // Subtle transparent blue line
                        backgroundColor: 'rgba(78, 115, 223, 0.05)', // Very faint transparent fill
                        pointRadius: 3,
                        pointBackgroundColor: 'rgba(78, 115, 223, 0.25)',
                        pointBorderColor: 'rgba(255, 255, 255, 0.8)',
                        pointHoverRadius: 5,
                        pointHoverBackgroundColor: '#4e73df',
                        pointHoverBorderColor: '#fff',
                        pointHitRadius: 10,
                        pointBorderWidth: 1,
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    maintainAspectRatio: false,
                    layout: {
                        padding: {
                            left: 10,
                            right: 25,
                            top: 25,
                            bottom: 0
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false,
                                drawBorder: false
                            },
                            ticks: {
                                maxTicksLimit: 7
                            }
                        },
                        y: {
                            ticks: {
                                maxTicksLimit: 5,
                                padding: 10,
                            },
                            grid: {
                                color: "rgb(234, 236, 244)",
                                zeroLineColor: "rgb(234, 236, 244)",
                                drawBorder: false,
                                borderDash: [2],
                                zeroLineBorderDash: [2]
                            }
                        },
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: "rgb(255,255,255)",
                            bodyColor: "#858796",
                            titleMarginBottom: 10,
                            titleColor: '#6e707e',
                            titleFontSize: 14,
                            borderColor: '#dddfeb',
                            borderWidth: 1,
                            xPadding: 15,
                            yPadding: 15,
                            displayColors: false,
                            intersect: false,
                            mode: 'index',
                            caretPadding: 10,
                        }
                    }
                }
            });
        }

        // Completion Chart
        const ctxCompletion = document.getElementById('completionChart');
        if (ctxCompletion) {
            new Chart(ctxCompletion, {
                type: 'doughnut',
                data: {
                    labels: ["Completed", "Remaining"],
                    datasets: [{
                        data: [<?= $total_hours_completed ?>, <?= max(0, 300 - $total_hours_completed) ?>],
                        backgroundColor: ['rgba(28, 200, 138, 0.9)', 'rgba(234, 236, 244, 0.5)'], // High opacity green, transparent gray
                        hoverBackgroundColor: ['#1cc88a', '#eaecf4'],
                        hoverBorderColor: "rgba(234, 236, 244, 0.8)",
                        borderColor: ['#fff', 'rgba(255,255,255,0.5)'],
                        borderWidth: 2
                    }],
                },
                options: {
                    maintainAspectRatio: false,
                    cutout: '80%',
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: "rgb(255,255,255)",
                            bodyColor: "#858796",
                            borderColor: '#dddfeb',
                            borderWidth: 1,
                            xPadding: 15,
                            yPadding: 15,
                            displayColors: false,
                            caretPadding: 10,
                        },
                    },
                },
            });
        }
    });
</script>
</body>
</html>
