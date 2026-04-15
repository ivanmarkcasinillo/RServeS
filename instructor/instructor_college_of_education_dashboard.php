<?php
//instructor
session_start();
require "dbconnect.php";
 include "../student/check_expiration.php";
require_once "../send_email.php";
require_once __DIR__ . '/task_assignment_helper.php';

/* -------------------  SESSION CHECK ------------------- */
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Instructor' || $_SESSION['department_id'] != 1) {
    header("Location: ../home2.php");
    exit;
}

function rserves_instructor_dashboard_is_ajax_request(): bool
{
    return (isset($_POST['ajax']) && $_POST['ajax'] === '1')
        || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

function rserves_instructor_dashboard_respond(bool $success, string $message): void
{
    if (rserves_instructor_dashboard_is_ajax_request()) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'type' => $success ? 'success' : 'danger',
        ]);
        exit;
    }

    header(
        "Location: "
        . $_SERVER['PHP_SELF']
        . "?msg="
        . urlencode($message)
        . "&msg_type="
        . urlencode($success ? 'success' : 'danger')
    );
    exit;
}

// Auto-migration: Add is_deleted column to tasks if it doesn't exist
$check_col = $conn->query("SHOW COLUMNS FROM tasks LIKE 'is_deleted'");
if ($check_col && $check_col->num_rows == 0) {
    $conn->query("ALTER TABLE tasks ADD COLUMN is_deleted TINYINT DEFAULT 0");
}

// Auto-migration: Add approver_id column to accomplishment_reports if it doesn't exist
$check_col_app = $conn->query("SHOW COLUMNS FROM accomplishment_reports LIKE 'approver_id'");
if ($check_col_app && $check_col_app->num_rows == 0) {
    $conn->query("ALTER TABLE accomplishment_reports ADD COLUMN approver_id INT NULL DEFAULT NULL");
}

rserves_instructor_ensure_task_due_date_column($conn);
rserves_instructor_ensure_student_task_meta_columns($conn);

$email = $_SESSION['email'];

// Auto-Setup Notification Table
$conn->query("CREATE TABLE IF NOT EXISTS instructor_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    instructor_id INT NOT NULL,
    type VARCHAR(50),
    reference_id INT,
    student_id INT,
    message TEXT,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (instructor_id) REFERENCES instructors(inst_id) ON DELETE CASCADE
)");

/* -------------------  FETCH INSTRUCTOR ------------------- */
$stmt = $conn->prepare("
    SELECT inst_id, firstname, lastname, mi, email, photo
    FROM instructors
    WHERE email = ?
");
if (!$stmt) {
    die("Prepare failed: " . $conn->error);
}

$stmt->bind_param("s", $email);
$stmt->execute();
$instructor = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$instructor) { 
    die("Instructor not found."); 
}

$inst_id  = $instructor['inst_id'];
$fullname = $instructor['firstname']
          . (!empty($instructor['mi']) ? ' ' . strtoupper(substr($instructor['mi'],0,1)) . '.' : '')
          . ' ' . $instructor['lastname'];
$photo = !empty($instructor['photo'])
       ? 'uploads/' . basename($instructor['photo'])
       : 'default_profile.jpg';

try {
    rserves_instructor_sync_task_assignments_for_instructor($conn, $inst_id);
} catch (Throwable $e) {
    error_log("Instructor task assignment sync failed for instructor {$inst_id}: " . $e->getMessage());
}

/* -------------------  HANDLE PHOTO UPLOAD ------------------- */
if (!empty($_FILES['profilePhoto']['tmp_name'])) {
    $file = $_FILES['profilePhoto'];
    if ($file['error'] === UPLOAD_ERR_OK) {
        $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','gif','webp'];
        if (in_array($ext,$allowed)) {
            $newName = 'inst_'.$inst_id.'_'.time().'.'.$ext;
            $dest    = __DIR__ . '/uploads/'.$newName;
            if (!is_dir(__DIR__.'/uploads')) mkdir(__DIR__.'/uploads', 0777, true);
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                $up = $conn->prepare("UPDATE instructors SET photo=? WHERE inst_id=?");
                $up->bind_param("si", $newName, $inst_id);
                $up->execute();
                $up->close();
                header("Location: ".$_SERVER['PHP_SELF']);
                exit;
            }
        }
    }
}

/* -------------------  UPDATE ADVISORY SECTION ------------------- */
if (isset($_POST['update_advisory'])) {
    $new_section = trim($_POST['advisory_section']);
    $dept_id = 1; // Education

    // Check if section is already taken by ANOTHER instructor
    $check_stmt = $conn->prepare("SELECT instructor_id FROM section_advisers WHERE section = ? AND department_id = ? AND instructor_id != ?");
    $check_stmt->bind_param("sii", $new_section, $dept_id, $inst_id);
    $check_stmt->execute();
    $check_res = $check_stmt->get_result();

    if ($check_res && $check_res->num_rows > 0) {
        header("Location: " . $_SERVER['PHP_SELF'] . "?msg=" . urlencode("âŒ Error: Section $new_section already has an adviser. Only 1 adviser per section is allowed."));
        exit;
    }
    $check_stmt->close();

    // Remove current advisory (if any)
    $del_stmt = $conn->prepare("DELETE FROM section_advisers WHERE instructor_id = ? AND department_id = ?");
    $del_stmt->bind_param("ii", $inst_id, $dept_id);
    $del_stmt->execute();
    $del_stmt->close();

    if (!empty($new_section)) {
        // Add new advisory
        $ins_stmt = $conn->prepare("INSERT INTO section_advisers (instructor_id, section, department_id) VALUES (?, ?, ?)");
        $ins_stmt->bind_param("isi", $inst_id, $new_section, $dept_id);
        $ins_stmt->execute();
        $ins_stmt->close();

        // âœ… Automatic Linking: Link all students in this section/department to this instructor
        $link_stmt = $conn->prepare("UPDATE students SET instructor_id = ? WHERE section = ? AND department_id = ?");
        $link_stmt->bind_param("isi", $inst_id, $new_section, $dept_id);
        $link_stmt->execute();
        $link_stmt->close();

        $msg = "Advisory section updated to $new_section and students linked!";
    } else {
        // If clearing advisory, remove link from students
        $unlink_stmt = $conn->prepare("UPDATE students SET instructor_id = NULL WHERE instructor_id = ? AND department_id = ?");
        $unlink_stmt->bind_param("ii", $inst_id, $dept_id);
        $unlink_stmt->execute();
        $unlink_stmt->close();
        $msg = "Advisory section removed.";
    }

    header("Location: " . $_SERVER['PHP_SELF'] . "?msg=" . urlencode($msg));
    exit;
}

/* -------------------  CHANGE PASSWORD ------------------- */
if (isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    $stmt = $conn->prepare("SELECT password FROM instructors WHERE inst_id = ?");
    $stmt->bind_param("i", $inst_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res->fetch_assoc();
    $stmt->close();

    if ($user && password_verify($current_password, $user['password'])) {
        if ($new_password === $confirm_password) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $up = $conn->prepare("UPDATE instructors SET password = ? WHERE inst_id = ?");
            $up->bind_param("si", $hashed_password, $inst_id);
            if ($up->execute()) {
                $msg = "âœ… Password changed successfully!";
            } else {
                $msg = "âŒ Error updating password.";
            }
            $up->close();
        } else {
            $msg = "âŒ New passwords do not match.";
        }
    } else {
        $msg = "âŒ Current password is incorrect.";
    }
    header("Location: " . $_SERVER['PHP_SELF'] . "?msg=" . urlencode($msg));
    exit;
}

/* -------------------  CREATE TASK ------------------- */
if (isset($_POST['create_task'])) {
    $title       = trim($_POST['title']);
    $description = trim($_POST['description']);
    $duration    = $_POST['duration'];
    $due_date    = trim((string) ($_POST['due_date'] ?? ''));
    $selected    = $_POST['send_to'] ?? [];
    $fixed_dept_id = 1;
    $created_by_student = 0;
    $task_message = "Task created successfully" . (!empty($selected) ? " and sent!" : "!");
    $due_date_value = null;

    if ($due_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_date)) {
        rserves_instructor_dashboard_respond(false, "Please select a valid due date.");
    }

    $due_date_value = $due_date;

    try {
        $conn->begin_transaction();

        $tstmt = $conn->prepare("
            INSERT INTO tasks (title, description, duration, due_date, instructor_id, department_id, created_by_student)
            VALUES (?,?,?,?,?,?,?)
        ");
        if (!$tstmt) {
            throw new RuntimeException($conn->error);
        }

        $tstmt->bind_param("ssssiii", $title, $description, $duration, $due_date_value, $inst_id, $fixed_dept_id, $created_by_student);
        if (!$tstmt->execute()) {
            $tstmt_error = $tstmt->error;
            $tstmt->close();
            throw new RuntimeException($tstmt_error);
        }

        $task_id = $tstmt->insert_id;
        $tstmt->close();

        if (!empty($selected)) {
            $astmt = $conn->prepare("
                INSERT INTO student_tasks (student_id, task_id, status)
                VALUES (?, ?, 'Pending')
            ");
            if (!$astmt) {
                throw new RuntimeException($conn->error);
            }

            foreach ($selected as $sid) {
                $sid = intval($sid);
                $astmt->bind_param("ii", $sid, $task_id);
                if (!$astmt->execute()) {
                    $astmt_error = $astmt->error;
                    $astmt->close();
                    throw new RuntimeException($astmt_error);
                }
            }

            $astmt->close();
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log("Instructor task creation failed for instructor {$inst_id}: " . $e->getMessage());
        $task_message = "Task creation failed. Please try again.";
    }

    if ($task_message !== "Task creation failed. Please try again." && !empty($selected)) {
        $estmt = $conn->prepare("SELECT email, firstname, lastname FROM students WHERE stud_id = ?");
        if ($estmt) {
            foreach ($selected as $sid) {
                $sid = intval($sid);
                $estmt->bind_param("i", $sid);
                if (!$estmt->execute()) {
                    error_log("Task email lookup failed for student {$sid}: " . $estmt->error);
                    continue;
                }

                $res = $estmt->get_result();
                if ($student = $res->fetch_assoc()) {
                    $to = $student['email'];
                    $name = $student['firstname'] . ' ' . $student['lastname'];
                    $subject = "New Task Assigned: $title";
                    $body = "Hello $name,\n\nA new task has been assigned to you by your adviser ($fullname).\n\nTask: $title\nDescription: $description\nDue Date: " . date('F d, Y', strtotime($due_date_value)) . "\n\nPlease log in to your dashboard to view details.";
                    sendEmail($to, $name, $subject, $body);
                }
            }

            $estmt->close();
        } else {
            error_log("Task email prepare failed for instructor {$inst_id}: " . $conn->error);
        }
    }

    rserves_instructor_dashboard_respond($task_message !== "Task creation failed. Please try again.", $task_message);
}

/* -------------------  EDIT TASK ------------------- */
if (isset($_POST['edit_task'])) {
    $task_id = intval($_POST['task_id']);
    $new_title = trim($_POST['edit_title']);
    $new_desc  = trim($_POST['edit_description']);
    $new_duration = $_POST['edit_duration'];
    $new_due_date = trim((string) ($_POST['edit_due_date'] ?? ''));

    if ($new_due_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_due_date)) {
        rserves_instructor_dashboard_respond(false, "Please select a valid due date.");
    }

    $stmt = $conn->prepare("UPDATE tasks SET title=?, description=?, duration=?, due_date=? WHERE task_id=? AND instructor_id=?");
    $stmt->bind_param("ssssii", $new_title, $new_desc, $new_duration, $new_due_date, $task_id, $inst_id);
    $stmt->execute();
    $stmt->close();

    rserves_instructor_dashboard_respond(true, "Task updated successfully!");
}

/* -------------------  DELETE TASK ------------------- */
if (isset($_POST['delete_task'])) {
    $del_id = intval($_POST['delete_task']);

    // Soft delete: just hide the task from the instructor's view by setting is_deleted to 1
    // We do NOT delete from student_tasks to preserve the records as requested
    
    $up2 = $conn->prepare("UPDATE tasks SET is_deleted = 1 WHERE task_id=? AND instructor_id=?");
    $up2->bind_param("ii", $del_id, $inst_id);
    $up2->execute();
    $up2->close();

    rserves_instructor_dashboard_respond(true, "Task hidden successfully!");
}

/* -------------------  APPROVE/REJECT STUDENT ACCOMPLISHMENTS (ADVISORY) ------------------- */
if (isset($_POST['bulk_approve_accomp'])) {
    $bulk_ids = $_POST['ar_ids'] ?? [];
    if (!is_array($bulk_ids)) {
        $bulk_ids = explode(',', (string) $bulk_ids);
    }

    $result = rserves_instructor_bulk_approve_accomplishments($conn, $inst_id, $bulk_ids);
    rserves_instructor_dashboard_respond($result['success'], $result['message']);
}

if (isset($_POST['send_bulk_announcement'])) {
    $subject = trim((string) ($_POST['announcement_subject'] ?? ''));
    $message = trim((string) ($_POST['announcement_message'] ?? ''));
    $result = rserves_instructor_send_bulk_announcement($conn, $inst_id, 1, $fullname, $subject, $message);
    rserves_instructor_dashboard_respond($result['success'], $result['message']);
}

if (isset($_POST['remind_task'])) {
    $task_id = intval($_POST['task_id'] ?? 0);
    $result = rserves_instructor_send_task_reminders($conn, $inst_id, $task_id, $fullname);
    rserves_instructor_dashboard_respond($result['success'], $result['message']);
}

if (isset($_POST['duplicate_task'])) {
    $task_id = intval($_POST['task_id'] ?? 0);
    $result = rserves_instructor_duplicate_task($conn, $inst_id, $task_id, $fullname);
    rserves_instructor_dashboard_respond($result['success'], $result['message']);
}

if (isset($_POST['reassign_task_assignment'])) {
    $student_task_id = intval($_POST['student_task_id'] ?? 0);
    $new_student_id = intval($_POST['new_student_id'] ?? 0);
    $result = rserves_instructor_reassign_task($conn, $inst_id, $student_task_id, $new_student_id, $fullname);
    rserves_instructor_dashboard_respond($result['success'], $result['message']);
}

if (isset($_POST['approve_accomp'])) {
    $ar_id = intval($_POST['ar_id']);
    $result = rserves_instructor_update_accomplishment_status($conn, $inst_id, $ar_id, 'Approved');
    rserves_instructor_dashboard_respond($result['success'], $result['message']);
}

if (isset($_POST['reject_accomp'])) {
    $ar_id = intval($_POST['ar_id']);
    $result = rserves_instructor_update_accomplishment_status($conn, $inst_id, $ar_id, 'Rejected');
    rserves_instructor_dashboard_respond($result['success'], $result['message']);
}

/* -------------------  MARK ALL NOTIFICATIONS AS READ (Moved to external file) ------------------- */
// Logic handled in mark_notification_read.php

/* -------------------  FETCH STUDENTS GROUPED BY YEAR & SECTION ------------------- */
$students_by_year = [];

$sql = "
SELECT 
  s.stud_id,
  s.firstname,
  s.mi,
  s.lastname,
  s.email,
  s.student_number,
  s.photo,
  COALESCE(s.year_level, 1) AS year_level,
  COALESCE(s.section, 'A') AS section,
  COALESCE(ar_sum.hours, 0) AS completed_hours
FROM students s
LEFT JOIN (
            SELECT student_id, SUM(hours) as hours 
            FROM accomplishment_reports 
            WHERE status = 'Approved'
            GROUP BY student_id
        ) ar_sum ON s.stud_id = ar_sum.student_id
WHERE s.department_id = 1
ORDER BY s.year_level, s.section, s.lastname
";

$result = $conn->query($sql);

if (!$result) {
    die("SQL Error: " . $conn->error);
}

while ($row = $result->fetch_assoc()) {
    $year = $row['year_level'];
    $section = $row['section'];

    if (!isset($students_by_year[$year])) {
        $students_by_year[$year] = [];
    }
    if (!isset($students_by_year[$year][$section])) {
        $students_by_year[$year][$section] = [];
    }

    $students_by_year[$year][$section][] = $row;
}

$result->free();

/* -------------------  FETCH ADVISORY STUDENTS ------------------- */
$my_advisory_students = [];
$advisory_sections = [];

// Get sections assigned to this instructor
$stmt_adv = $conn->prepare("SELECT section FROM section_advisers WHERE instructor_id = ? AND department_id = 1");
$stmt_adv->bind_param("i", $inst_id);
$stmt_adv->execute();
$res_adv = $stmt_adv->get_result();
while($row_adv = $res_adv->fetch_assoc()) {
    $advisory_sections[] = $row_adv['section'];
}
$stmt_adv->close();

if (!empty($advisory_sections)) {
    // Escape sections for SQL IN clause
    $sections_in = "'" . implode("','", $advisory_sections) . "'";
    $sql_adv_stud = "
        SELECT 
          s.stud_id, s.firstname, s.mi, s.lastname, s.email, s.student_number, s.photo,
          COALESCE(s.year_level, 1) AS year_level,
          COALESCE(s.section, 'A') AS section,
          COALESCE(ar_sum.hours, 0) AS completed_hours
        FROM students s
        LEFT JOIN (
            SELECT student_id, SUM(hours) as hours FROM accomplishment_reports WHERE status = 'Approved' GROUP BY student_id
        ) ar_sum ON s.stud_id = ar_sum.student_id
        WHERE s.department_id = 1 AND COALESCE(s.section, 'A') IN ($sections_in)
        ORDER BY s.lastname
    ";
    $res_adv_stud = $conn->query($sql_adv_stud);
    if ($res_adv_stud) {
        while ($row = $res_adv_stud->fetch_assoc()) {
            $my_advisory_students[] = $row;
        }
    }
}

/* -------------------  FETCH PENDING ADVISORY ACCOMPLISHMENTS ------------------- */
$pendingAdvisoryAccomps = [];
if (!empty($advisory_sections)) {
    // Re-use sections_in from above or regenerate if scope is different (it's in scope)
    $sections_in = "'" . implode("','", $advisory_sections) . "'";
    $sql_pending_adv = "
        SELECT ar.*, s.firstname, s.lastname, s.mi, s.photo as student_photo, s.section,
               i.firstname as assigner_fname, i.lastname as assigner_lname, i.inst_id as assigner_id_val,
               s.instructor_id as student_adviser_id,
               (SELECT COUNT(*) FROM section_advisers sa WHERE sa.instructor_id = i.inst_id AND sa.section = s.section AND sa.department_id = s.department_id) as is_section_adviser
        FROM accomplishment_reports ar
        JOIN students s ON ar.student_id = s.stud_id
        LEFT JOIN instructors i ON ar.assigner_id = i.inst_id
        WHERE s.department_id = 1 
        AND s.section IN ($sections_in) 
        AND ar.status = 'Pending'
        ORDER BY ar.work_date ASC
    ";
    $res_pending_adv = $conn->query($sql_pending_adv);
    if ($res_pending_adv) {
        while ($row = $res_pending_adv->fetch_assoc()) {
            $row['activity'] = preg_replace('/\[TaskID:\d+\]/', '', $row['activity']);
            $pendingAdvisoryAccomps[] = $row;
        }
    }
}
$pending_count = count($pendingAdvisoryAccomps);

/* -------------------  FETCH TASKS PER STUDENT ------------------- */
$studentTasks = [];
$res2 = $conn->query("
   SELECT 
    st.student_id,
    st.task_id,
    st.status,
    t.task_id,
    t.title,
    t.description,
    t.due_date,
    t.created_at
FROM student_tasks st
JOIN tasks t ON st.task_id = t.task_id
WHERE 
    t.instructor_id = $inst_id
    AND st.status = 'Pending'
ORDER BY t.created_at DESC

");
if ($res2) {
    while ($row = $res2->fetch_assoc()) {
        $studentTasks[$row['student_id']][] = $row;
    }
}

/* -------------------  FETCH TASKS PER STUDENT (ALL STATUS) ------------------- */
$allStudentTasks = [];
$resTasks = $conn->query("
   SELECT 
    st.student_id,
    st.status,
    t.title,
    t.description,
    t.due_date,
    t.created_at
FROM student_tasks st
JOIN tasks t ON st.task_id = t.task_id
WHERE t.instructor_id = $inst_id
ORDER BY t.created_at DESC
");
if ($resTasks) {
    while ($row = $resTasks->fetch_assoc()) {
        $allStudentTasks[$row['student_id']][] = $row;
    }
}

/* -------------------  FETCH ALL ACCOMPLISHMENTS PER STUDENT ------------------- */
$allStudentAccomps = [];
$resAccomps = $conn->query("
    SELECT 
        id,
        student_id, 
        work_date, 
        activity, 
        time_start,
        time_end,
        hours, 
        status,
        created_at
    FROM accomplishment_reports 
    ORDER BY work_date DESC
");
if ($resAccomps) {
    while ($row = $resAccomps->fetch_assoc()) {
        $row['activity'] = preg_replace('/\[TaskID:\d+\]/', '', $row['activity']);
        $allStudentAccomps[$row['student_id']][] = $row;
    }
}

/* -------------------  FETCH INSTRUCTOR'S CREATED TASKS ------------------- */
$myTasks = [];
$mt_stmt = $conn->prepare("
    SELECT 
        t.*, 
        (SELECT COUNT(*) FROM student_tasks st WHERE st.task_id = t.task_id) as assigned_count,
        (SELECT COUNT(DISTINCT st.student_id) 
         FROM student_tasks st 
         JOIN accomplishment_reports ar ON ar.student_id = st.student_id 
            AND (ar.student_task_id = st.stask_id OR ar.activity LIKE CONCAT('%[TaskID:', st.stask_id, ']%')) 
         WHERE st.task_id = t.task_id 
         AND ar.status IN ('Verified', 'Approved')) as completed_count
    FROM tasks t 
    WHERE t.instructor_id = ? AND t.is_deleted = 0 
    ORDER BY t.created_at DESC
");
$mt_stmt->bind_param("i", $inst_id);
$mt_stmt->execute();
$mt_res = $mt_stmt->get_result();
while ($row = $mt_res->fetch_assoc()) {
    $myTasks[] = $row;
}
$mt_stmt->close();

/* -------------------  FETCH TASK ASSIGNMENTS ------------------- */
$taskAssignments = [];
$ta_res = $conn->query("
    SELECT 
        st.stask_id,
        st.task_id,
        st.student_id,
        st.status as assignment_status,
        st.assigned_at,
        st.completed_at,
        s.student_number,
        s.firstname,
        s.lastname,
        s.email,
        s.section,
        s.year_level
    FROM student_tasks st
    JOIN students s ON st.student_id = s.stud_id
    JOIN tasks t ON st.task_id = t.task_id
    WHERE t.instructor_id = $inst_id
    ORDER BY s.section, s.lastname
");
if ($ta_res) {
    while ($row = $ta_res->fetch_assoc()) {
        $taskAssignments[$row['task_id']][] = $row;
    }
}

// Handle Mark All Read
if (isset($_POST['mark_all_read'])) {
    $conn->query("UPDATE instructor_notifications SET is_read = TRUE WHERE instructor_id = $inst_id");
    exit;
}

// Fetch Notifications
$notifs_query = $conn->query("SELECT * FROM instructor_notifications WHERE instructor_id = $inst_id ORDER BY created_at DESC");
$notifications = [];
$unread_notifs = 0;
if ($notifs_query) {
    while ($n = $notifs_query->fetch_assoc()) {
        $notifications[] = $n;
        if (!$n['is_read']) {
            $unread_notifs++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Adviser Dashboard - College of Education</title>
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
        }

        body {
            font-family: 'Urbanist', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-dark);
            overflow-x: hidden;
        }

        /* Sidebar Styles */
        #sidebar-wrapper {
            min-height: 100vh;
            width: var(--sidebar-width);
            margin-left: 0;
            transition: margin 0.25s ease-out;
            background: linear-gradient(180deg, var(--primary-color), var(--secondary-color));
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1000;
        }

        #sidebar-wrapper .sidebar-heading {
            padding: 1.5rem;
            font-size: 1.5rem;
            font-weight: 700;
            color: white;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        #sidebar-wrapper .list-group {
            width: var(--sidebar-width);
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
            padding: 1rem 2rem;
            background: white;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
             background: var(--primary-color);
        }

        .container-fluid {
            padding: 2rem;
        }

        /* Cards */
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            transition: transform 0.3s;
            height: 100%;
            border-left: 5px solid var(--primary-color);
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-icon {
            font-size: 2.5rem;
            color: var(--primary-color);
            margin-bottom: 1rem;
        }

        /* Data Tables / Grids */
        .content-card {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
            color:#123755;
        }

        .year-card {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            border: 1px solid #eee;
            box-shadow: 0 4px 6px rgba(0,0,0,0.02);
        }

        .year-card:hover {
            transform: translateY(-5px);
            border-color: var(--accent-color);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }

        .year-icon {
            font-size: 3rem;
            color: var(--secondary-color);
            margin-bottom: 1rem;
        }

        .section-badge {
            font-size: 0.9rem;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            background: #e9ecef;
            color: var(--text-dark);
            margin: 0.25rem;
            display: inline-block;
        }

        /* Profile */
        .profile-img-nav {
            width: 40px;
            height: 40px;
            border: solid 5px #e9ecef;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--primary-color);
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

        /* Mobile Responsive */
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
                padding-top: 80px; /* Adjust for taller header */
            }
        }
        
        /* Normalize font sizes for instructor dashboard cards */
        .stat-card h3 {
            font-size: 1.5rem !important; /* Normal size */
            font-weight: 600;
        }
        .stat-card p {
            font-size: 0.9rem;
        }
        .year-card h4 {
            font-size: 1.2rem !important; /* Normal size */
            font-weight: 600;
        }
        
        /* Table Styles */
        .table th {
            font-weight: 600;
            color: var(--secondary-color);
        }
        .table td {
            vertical-align: middle;
        }

        /* Password Toggle Styles */
        .password-container {
            position: relative;
            width: 100%;
        }

        .password-container input {
            padding-right: 35px !important;
        }

        .password-toggle-icon {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #666;
            z-index: 10;
            font-size: 0.9rem;
        }

        body {
            opacity: 0;
            animation: rservePageFadeIn 520ms ease forwards;
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

        @media (prefers-reduced-motion: reduce) {
            body { animation: none; opacity: 1; }
            .rserve-page-loader { transition: none; }
            .rserve-page-loader__spinner { animation: none; }
        }

        .modal {
            --rserve-modal-gap: clamp(0.75rem, 2vh, 1.5rem);
        }

        .modal .modal-dialog {
            margin: var(--rserve-modal-gap) auto;
            width: calc(100vw - (var(--rserve-modal-gap) * 2));
            max-width: min(var(--bs-modal-width, 500px), calc(100vw - (var(--rserve-modal-gap) * 2)));
        }

        .modal .modal-dialog.modal-dialog-centered {
            min-height: calc(100vh - (var(--rserve-modal-gap) * 2));
            min-height: calc(100dvh - (var(--rserve-modal-gap) * 2));
        }

        .modal .modal-content {
            max-height: calc(100vh - (var(--rserve-modal-gap) * 2));
            max-height: calc(100dvh - (var(--rserve-modal-gap) * 2));
            overflow: hidden;
        }

        .modal .modal-body {
            overflow-y: auto;
            overscroll-behavior: contain;
        }

        .modal-table-shell {
            border: 1px solid rgba(18, 55, 85, 0.08);
            border-radius: 14px;
        }

        .modal-table-pagination {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            margin-top: 0.9rem;
        }

        .modal-table-pagination__summary {
            color: #6b7280;
            font-size: 0.9rem;
        }

        .modal-table-pagination__controls {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 0.45rem;
            flex-wrap: wrap;
        }

        .modal-table-pagination__page {
            color: var(--secondary-color);
            font-size: 0.9rem;
            font-weight: 600;
            white-space: nowrap;
        }

        @media (max-width: 575.98px) {
            .modal-table-pagination {
                flex-direction: column;
                align-items: stretch;
            }

            .modal-table-pagination__controls {
                justify-content: space-between;
            }
        }
    </style>
    <link rel="stylesheet" href="../assets/css/rserve-dashboard-theme.css">
</head>
<body class="rserve-theme">

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
            <span class="text-white me-2 fw-bold" style="font-size: 0.9rem;">Instructor</span>
            <img src="<?php echo htmlspecialchars($photo); ?>" alt="Profile" 
                 class="rounded-circle border border-2 border-white" 
                 style="width: 35px; height: 35px; object-fit: cover;">
        </div>
    </div>
    <div class="mobile-header-nav">
        <a href="#" class="nav-item active" data-view-link="dashboard" onclick="showView('dashboard', this); return false;">
            <i class="fas fa-th-large"></i>
            <span>Overview</span>
        </a>
        <a href="#" class="nav-item position-relative" data-view-link="advisory" onclick="showView('advisory', this); return false;">
            <i class="fas fa-chalkboard-teacher"></i>
            <span>Advisory</span>
            <?php if ($pending_count > 0): ?>
                <span class="position-absolute top-0 start-50 translate-middle badge rounded-pill bg-warning text-dark" style="font-size: 0.5rem; transform: translate(10px, 5px) !important;">
                    <?php echo $pending_count; ?>
                </span>
            <?php endif; ?>
        </a>
        <a href="#" class="nav-item" data-view-link="classes" onclick="showView('classes', this); return false;">
            <i class="fas fa-users"></i>
            <span>Classes</span>
        </a>
        <a href="#" class="nav-item" data-view-link="tasks" onclick="showView('tasks', this); return false;">
            <i class="fas fa-tasks"></i>
            <span>Tasks</span>
        </a>
        <a href="#" class="nav-item position-relative" data-view-link="notifications" onclick="showView('notifications', this); return false;">
            <i class="fas fa-bell"></i>
            <span>Notifs</span>
            <?php if ($unread_notifs > 0): ?>
                <span class="position-absolute top-0 start-50 translate-middle badge rounded-pill bg-danger" style="font-size: 0.5rem; transform: translate(10px, 5px) !important;">
                    <?php echo $unread_notifs; ?>
                </span>
            <?php endif; ?>
        </a>
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
                <span class="sidebar-brand-subtitle">Adviser Workspace</span>
            </div>
            <div class="list-group list-group-flush">
                <a href="#" class="list-group-item list-group-item-action active" data-view-link="dashboard" onclick="showView('dashboard', this); return false;">
                    <i class="fas fa-th-large"></i> Dashboard
                </a>
                <a href="#" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" data-view-link="advisory" onclick="showView('advisory', this); return false;">
                    <span><i class="fas fa-chalkboard-teacher"></i> Advisory</span>
                    <?php if ($pending_count > 0): ?>
                        <span class="badge bg-warning text-dark rounded-pill"><?php echo $pending_count; ?></span>
                    <?php endif; ?>
                </a>
                <a href="#" class="list-group-item list-group-item-action" data-view-link="classes" onclick="showView('classes', this); return false;">
                    <i class="fas fa-users"></i> Classes
                </a>
                <a href="#" class="list-group-item list-group-item-action" data-view-link="tasks" onclick="showView('tasks', this); return false;">
                    <i class="fas fa-tasks"></i> Tasks
                </a>
                <a href="#" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" data-view-link="notifications" onclick="showView('notifications', this); return false;">
                    <span><i class="fas fa-bell"></i> Notifications</span>
                    <?php if ($unread_notifs > 0): ?>
                        <span class="badge bg-danger rounded-pill"><?php echo $unread_notifs; ?></span>
                    <?php endif; ?>
                </a>
                <a href="#" class="list-group-item list-group-item-action" data-bs-toggle="modal" data-bs-target="#profileModal">
                    <i class="fas fa-user-circle"></i> Profile
                </a>
                <a href="logout.php" class="list-group-item list-group-item-action">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
            <div class="role-sidebar-card">
                <div class="sidebar-role-profile">
                    <img src="<?php echo htmlspecialchars($photo); ?>" alt="Profile" class="sidebar-role-avatar">
                    <div>
                        <div class="sidebar-role-name"><?php echo htmlspecialchars($fullname); ?></div>
                        <div class="sidebar-role-meta"><?php echo !empty($advisory_sections) ? 'Sections: ' . htmlspecialchars(implode(', ', $advisory_sections)) : 'No section assigned'; ?></div>
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
                    <a href="#" class="topbar-tab active" data-view-link="dashboard" onclick="showView('dashboard'); return false;">Overview</a>
                    <a href="#" class="topbar-tab" data-view-link="advisory" onclick="showView('advisory'); return false;">Advisory</a>
                    <a href="#" class="topbar-tab" data-view-link="classes" onclick="showView('classes'); return false;">Classes</a>
                    <a href="#" class="topbar-tab" data-view-link="tasks" onclick="showView('tasks'); return false;">Tasks</a>
                    <a href="#" class="topbar-tab" data-view-link="notifications" onclick="showView('notifications'); return false;">Notifications</a>
                </div>

                <div class="topbar-actions">
                    <div class="topbar-profile" data-bs-toggle="modal" data-bs-target="#profileModal">
                        <div class="topbar-identity">
                            <div><?php echo htmlspecialchars($fullname); ?></div>
                            <div>Adviser | College of Education</div>
                        </div>
                        <img src="<?php echo htmlspecialchars($photo); ?>" alt="Profile" class="topbar-avatar">
                    </div>
                </div>
            </div>
        </nav>

        <div class="container-fluid" id="main-content">
            <!-- Content rendered via JS -->
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
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <img src="<?php echo htmlspecialchars($photo); ?>" class="rounded-circle mb-3" style="width: 120px; height: 120px; object-fit: cover; border: 4px solid var(--primary-color);">
                <h4><?php echo htmlspecialchars($fullname); ?></h4>
                <p class="text-muted"><?php echo htmlspecialchars($email); ?></p>
                
                <form action="<?php echo $_SERVER['PHP_SELF']; ?>" method="POST" enctype="multipart/form-data" class="mt-4 text-start border-bottom pb-3 mb-3">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Change Profile Photo</label>
                        <input type="file" name="profilePhoto" class="form-control" accept="image/*" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Upload New Photo</button>
                </form>

                <form action="<?php echo $_SERVER['PHP_SELF']; ?>" method="POST" class="text-start">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Advisory Section</label>
                        <input type="text" name="advisory_section" class="form-control" placeholder="Enter section (e.g. A, B, C)" value="<?php echo !empty($advisory_sections) ? htmlspecialchars($advisory_sections[0]) : ''; ?>">
                        <small class="text-muted">Setting this will automatically link students in this section to you.</small>
                    </div>
                    <button type="submit" name="update_advisory" class="btn btn-success w-100">Update Advisory Section</button>
                </form>

                <hr>
                <form action="<?php echo $_SERVER['PHP_SELF']; ?>" method="POST" class="text-start">
                    <h6 class="fw-bold mb-3">Change Password</h6>
                    <div class="mb-2">
                        <label class="form-label small">Current Password</label>
                        <div class="password-container">
                            <input type="password" name="current_password" class="form-control form-control-sm" required id="inst_edu_current_password">
                            <i class="fas fa-eye-slash password-toggle-icon" onclick="togglePasswordVisibility(this, 'inst_edu_current_password')"></i>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">New Password</label>
                        <div class="password-container">
                            <input type="password" name="new_password" class="form-control form-control-sm" required id="inst_edu_new_password">
                            <i class="fas fa-eye-slash password-toggle-icon" onclick="togglePasswordVisibility(this, 'inst_edu_new_password')"></i>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Confirm New Password</label>
                        <div class="password-container">
                            <input type="password" name="confirm_password" class="form-control form-control-sm" required id="inst_edu_confirm_password">
                            <i class="fas fa-eye-slash password-toggle-icon" onclick="togglePasswordVisibility(this, 'inst_edu_confirm_password')"></i>
                        </div>
                    </div>
                    <button type="submit" name="change_password" class="btn btn-warning btn-sm w-100">Update Password</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function togglePasswordVisibility(icon, fieldId) {
    const field = document.getElementById(fieldId);
    if (field.type === "password") {
        field.type = "text";
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    } else {
        field.type = "password";
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    }
}
</script>

<!-- Create Task Modal -->
<div class="modal fade" id="createTaskModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form action="<?php echo $_SERVER['PHP_SELF']; ?>" method="POST" id="createTaskForm">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Task</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
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

                        <input type="hidden" name="title" id="finalTaskTitle" required>
                        <div id="selectedTaskDisplay" class="alert alert-success d-none">
                            Selected: <strong id="selectedTaskName"></strong>
                            <button type="button" class="btn-close float-end" id="clearTaskBtn"></button>
                        </div>
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
                        <label class="form-label fw-bold">Due Date</label>
                        <input type="date" name="due_date" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3" required></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Assign To Students</label>
                        <div class="row g-2 mb-3">
                            <div class="col-lg-4">
                                <label for="studentSelectionSearch" class="form-label small text-muted mb-1">Search Student</label>
                                <input
                                    type="search"
                                    id="studentSelectionSearch"
                                    class="form-control"
                                    placeholder="Search by student name or email"
                                    oninput="studentSelectionSearch = this.value; populateStudentSelection();"
                                >
                            </div>
                            <div class="col-sm-6 col-lg-3">
                                <label for="studentSelectionYearFilter" class="form-label small text-muted mb-1">Year Level</label>
                                <select
                                    id="studentSelectionYearFilter"
                                    class="form-select"
                                    onchange="studentSelectionYearFilter = this.value; studentSelectionSectionFilter = ''; populateStudentSelection();"
                                >
                                    <option value="">All Year Levels</option>
                                </select>
                            </div>
                            <div class="col-sm-6 col-lg-3">
                                <label for="studentSelectionSectionFilter" class="form-label small text-muted mb-1">Section</label>
                                <select
                                    id="studentSelectionSectionFilter"
                                    class="form-select"
                                    onchange="studentSelectionSectionFilter = this.value; populateStudentSelection();"
                                >
                                    <option value="">All Sections</option>
                                </select>
                            </div>
                            <div class="col-lg-2 d-grid align-items-end">
                                <button type="button" class="btn btn-outline-secondary" onclick="clearStudentSelectionFilters()">Clear</button>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                            <div id="studentSelectionSummary" class="small text-muted">Showing 0 students. 0 selected.</div>
                            <div class="small text-muted">Selections stay saved while you filter the list.</div>
                        </div>
                        <div id="selectedStudentsHiddenInputs"></div>
                        <div class="accordion" id="studentAccordion">
                            <!-- Populated by JS -->
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_task" class="btn btn-primary">Submit Task</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Task Modal -->
<div class="modal fade" id="editTaskModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="<?php echo $_SERVER['PHP_SELF']; ?>" method="POST" id="editTaskForm">
                <input type="hidden" name="task_id" id="edit_task_id">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Task</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Task Title</label>
                        <input type="text" name="edit_title" id="edit_task_title" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Duration</label>
                        <select name="edit_duration" id="edit_task_duration" class="form-select" required>
                            <option value="">Select Duration</option>
                            <option value="Within a Day">Within a Day</option>
                            <option value="Within a Week">Within a Week</option>
                            <option value="Within a Month">Within a Month</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Due Date</label>
                        <input type="date" name="edit_due_date" id="edit_task_due_date" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="edit_description" id="edit_task_desc" class="form-control" rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="edit_task" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Student Details Modal -->
<div class="modal fade" id="studentDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="studentDetailsTitle">Student Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-4">
                    <h6 class="text-primary fw-bold mb-3">Assigned Tasks</h6>
                    <div id="studentTasksList" class="table-responsive">
                        <!-- Tasks populated via JS -->
                    </div>
                </div>
                
                <div>
                    <h6 class="text-success fw-bold mb-3">Accomplishment History</h6>
                    <div id="studentAccompsList" class="table-responsive">
                         <!-- Accomps populated via JS -->
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- End Session Confirmation Modal -->
<div class="modal fade" id="endSessionConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-user-lock me-2"></i>Confirm End Session</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">This will archive the student's RSS session and prevent accidental completion mistakes.</p>
                <p class="fw-bold mb-0" id="endSessionStudentName">Selected student</p>
                <input type="hidden" id="endSessionStudentId">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmEndSessionBtn">End Session</button>
            </div>
        </div>
    </div>
</div>

<!-- Organization Details Modal Removed -->

<!-- Task Details Modal -->
<div class="modal fade" id="taskDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="taskDetailsTitle">Assigned Students</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 mb-3">
                    <div class="col-lg-4">
                        <label for="taskAssignmentSearch" class="form-label small text-muted mb-1">Search Student</label>
                        <input
                            type="search"
                            id="taskAssignmentSearch"
                            class="form-control"
                            placeholder="Search by student name"
                            oninput="taskAssignmentSearch = this.value; resetModalTablePage('taskAssignments'); renderTaskAssignmentsList();"
                        >
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <label for="taskAssignmentYearFilter" class="form-label small text-muted mb-1">Year Level</label>
                        <select
                            id="taskAssignmentYearFilter"
                            class="form-select"
                            onchange="taskAssignmentYearFilter = this.value; taskAssignmentSectionFilter = ''; resetModalTablePage('taskAssignments'); renderTaskAssignmentsList();"
                        >
                            <option value="">All Year Levels</option>
                        </select>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <label for="taskAssignmentSectionFilter" class="form-label small text-muted mb-1">Section</label>
                        <select
                            id="taskAssignmentSectionFilter"
                            class="form-select"
                            onchange="taskAssignmentSectionFilter = this.value; resetModalTablePage('taskAssignments'); renderTaskAssignmentsList();"
                        >
                            <option value="">All Sections</option>
                        </select>
                    </div>
                    <div class="col-sm-6 col-lg-2">
                        <label for="taskAssignmentStatusFilter" class="form-label small text-muted mb-1">Status</label>
                        <select
                            id="taskAssignmentStatusFilter"
                            class="form-select"
                            onchange="taskAssignmentStatusFilter = this.value; resetModalTablePage('taskAssignments'); renderTaskAssignmentsList();"
                        >
                            <option value="all">All Statuses</option>
                            <option value="in progress">In Progress</option>
                            <option value="completed">Completed</option>
                            <option value="pending">Pending</option>
                        </select>
                    </div>
                    <div class="col-lg-12 d-grid d-lg-flex justify-content-lg-end align-items-end">
                        <button type="button" class="btn btn-outline-secondary" onclick="clearTaskAssignmentFilters()">Clear</button>
                    </div>
                </div>
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div id="taskAssignmentsSummary" class="small text-muted mb-0">Showing 0 assigned students.</div>
                    <form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>" data-loading-label="Sending reminders..." onsubmit="return confirm('Send reminders to students who have not yet completed this task?');">
                        <input type="hidden" name="task_id" id="taskReminderTaskId">
                        <button type="submit" name="remind_task" class="btn btn-outline-warning btn-sm" id="taskReminderBtn">
                            <i class="fas fa-bell me-1"></i> Remind All
                        </button>
                    </form>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Student Name</th>
                                <th>Section</th>
                                <th>Status</th>
                                <th>Timeline</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="taskAssignmentsList">
                            <!-- Populated via JS -->
                        </tbody>
                    </table>
                </div>
                <div id="taskAssignmentsPagination"></div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="announcementModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>" data-loading-label="Sending announcement..." onsubmit="return confirm('Send this announcement to all advisory students?');">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-bullhorn me-2"></i>Send Announcement</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        This sends both an email and a dashboard notification to your advisory students.
                    </div>
                    <div class="mb-3">
                        <label for="announcementSubject" class="form-label fw-bold">Subject</label>
                        <input type="text" class="form-control" id="announcementSubject" name="announcement_subject" maxlength="255" required>
                    </div>
                    <div class="mb-0">
                        <label for="announcementMessage" class="form-label fw-bold">Message</label>
                        <textarea class="form-control" id="announcementMessage" name="announcement_message" rows="5" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="send_bulk_announcement" class="btn btn-primary">
                        <i class="fas fa-paper-plane me-1"></i> Send
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="reassignTaskModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>" data-loading-label="Reassigning task..." onsubmit="return confirm('Reassign this task to the selected student?');">
                <input type="hidden" name="student_task_id" id="reassignStudentTaskId">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-random me-2"></i>Reassign Task</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted mb-3" id="reassignTaskContext">Choose a new student for this task assignment.</p>
                    <div class="mb-0">
                        <label for="reassignStudentSelect" class="form-label fw-bold">New Student</label>
                        <select class="form-select" id="reassignStudentSelect" name="new_student_id" required>
                            <option value="">Select student</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="reassign_task_assignment" class="btn btn-primary">
                        <i class="fas fa-exchange-alt me-1"></i> Reassign
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="toast-container position-fixed top-0 end-0 p-3" id="appToastContainer" style="z-index: 1100;"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Data from PHP
    const studentsData = <?php echo json_encode($students_by_year); ?>;
    const advisoryStudentsData = <?php echo json_encode($my_advisory_students); ?>;
    const pendingAdvisoryAccomps = <?php echo json_encode($pendingAdvisoryAccomps); ?>;
    const notifications = <?php echo json_encode($notifications); ?>;
    const allStudentTasks = <?php echo json_encode($allStudentTasks); ?>;
    const allStudentAccomps = <?php echo json_encode($allStudentAccomps); ?>;
    const myTasks = <?php echo json_encode($myTasks); ?>;
    const taskAssignments = <?php echo json_encode($taskAssignments); ?>;
    const currentDashboardPath = <?php echo json_encode($_SERVER['PHP_SELF']); ?>;
    const initialFlashMessage = <?php echo json_encode($_GET['msg'] ?? ''); ?>;
    const initialFlashType = <?php echo json_encode($_GET['msg_type'] ?? ''); ?>;
    let taskMonthFilter = 'latest';
    let studentSelectionSearch = '';
    let studentSelectionYearFilter = '';
    let studentSelectionSectionFilter = '';
    const selectedStudentIds = new Set();
    let activeTaskDetailsId = null;
    let activeStudentDetailsId = null;
    let taskAssignmentSearch = '';
    let taskAssignmentYearFilter = '';
    let taskAssignmentSectionFilter = '';
    let taskAssignmentStatusFilter = 'all';
    const MODAL_TABLE_PAGE_SIZE = 6;
    const modalTableState = {};
    let pendingSubmissionDateFrom = '';
    let pendingSubmissionDateTo = '';
    let advisoryStudentSearch = '';
    let classStudentSearch = '';
    const selectedPendingAccomplishmentIds = new Set();
    const requiredHours = <?php echo json_encode(rserves_instructor_required_hours()); ?>;
    

    function inferToastType(message, fallback = 'info') {
        const normalized = String(message || '').toLowerCase();

        if (!normalized) {
            return fallback;
        }

        if (
            normalized.includes('error')
            || normalized.includes('failed')
            || normalized.includes('invalid')
            || normalized.includes('incorrect')
            || normalized.includes('missing')
            || normalized.includes('wrong')
            || normalized.includes('could not')
            || normalized.includes('cannot')
        ) {
            return 'danger';
        }

        if (normalized.includes('warning')) {
            return 'warning';
        }

        if (
            normalized.includes('success')
            || normalized.includes('approved')
            || normalized.includes('created')
            || normalized.includes('updated')
            || normalized.includes('hidden')
            || normalized.includes('sent')
            || normalized.includes('archived')
            || normalized.includes('ended')
        ) {
            return 'success';
        }

        return fallback;
    }

    function showToast(message, type = 'info') {
        const container = document.getElementById('appToastContainer');
        if (!container || !message) return;

        const toastEl = document.createElement('div');
        const variantClass = {
            success: 'text-bg-success',
            danger: 'text-bg-danger',
            warning: 'bg-warning text-dark',
            info: 'text-bg-primary'
        }[type] || 'text-bg-primary';
        const closeButtonClass = type === 'warning' ? 'btn-close' : 'btn-close btn-close-white';

        toastEl.className = `toast align-items-center border-0 ${variantClass}`;
        toastEl.setAttribute('role', 'alert');
        toastEl.setAttribute('aria-live', 'assertive');
        toastEl.setAttribute('aria-atomic', 'true');
        toastEl.innerHTML = `
            <div class="d-flex">
                <div class="toast-body"></div>
                <button type="button" class="${closeButtonClass} me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        `;
        toastEl.querySelector('.toast-body').textContent = message;

        container.appendChild(toastEl);

        const toast = new bootstrap.Toast(toastEl, {
            delay: 3600
        });
        toast.show();

        toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
    }

    function getModalTableState(key, pageSize = MODAL_TABLE_PAGE_SIZE) {
        if (!modalTableState[key]) {
            modalTableState[key] = {
                page: 1,
                pageSize
            };
        }

        modalTableState[key].pageSize = pageSize;

        if (!Number.isInteger(modalTableState[key].page) || modalTableState[key].page < 1) {
            modalTableState[key].page = 1;
        }

        return modalTableState[key];
    }

    function resetModalTablePage(key) {
        getModalTableState(key).page = 1;
    }

    function resetModalTablePages(keys) {
        keys.forEach(resetModalTablePage);
    }

    function getModalTablePagination(items, key, pageSize = MODAL_TABLE_PAGE_SIZE) {
        const state = getModalTableState(key, pageSize);
        const totalItems = Array.isArray(items) ? items.length : 0;
        const totalPages = Math.max(1, Math.ceil(totalItems / state.pageSize));

        if (state.page > totalPages) {
            state.page = totalPages;
        }

        const startIndex = totalItems === 0 ? 0 : (state.page - 1) * state.pageSize;
        const endIndex = Math.min(startIndex + state.pageSize, totalItems);

        return {
            currentPage: state.page,
            items: totalItems === 0 ? [] : items.slice(startIndex, endIndex),
            pageSize: state.pageSize,
            startIndex,
            endIndex,
            totalItems,
            totalPages
        };
    }

    function renderModalPagination(key, pagination, emptyLabel = 'No records found.') {
        if (!pagination.totalItems) {
            return `
                <div class="modal-table-pagination">
                    <span class="modal-table-pagination__summary">${emptyLabel}</span>
                </div>
            `;
        }

        const summary = `Showing ${pagination.startIndex + 1}-${pagination.endIndex} of ${pagination.totalItems}`;

        if (pagination.totalPages <= 1) {
            return `
                <div class="modal-table-pagination">
                    <span class="modal-table-pagination__summary">${summary}</span>
                </div>
            `;
        }

        return `
            <div class="modal-table-pagination">
                <span class="modal-table-pagination__summary">${summary}</span>
                <div class="modal-table-pagination__controls">
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setModalTablePage('${key}', 1)" ${pagination.currentPage === 1 ? 'disabled' : ''}>First</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setModalTablePage('${key}', ${pagination.currentPage - 1})" ${pagination.currentPage === 1 ? 'disabled' : ''}>Prev</button>
                    <span class="modal-table-pagination__page">Page ${pagination.currentPage} of ${pagination.totalPages}</span>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setModalTablePage('${key}', ${pagination.currentPage + 1})" ${pagination.currentPage === pagination.totalPages ? 'disabled' : ''}>Next</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setModalTablePage('${key}', ${pagination.totalPages})" ${pagination.currentPage === pagination.totalPages ? 'disabled' : ''}>Last</button>
                </div>
            </div>
        `;
    }

    function renderModalTableCard({
        key,
        title,
        titleClass,
        badgeClass,
        badgeLabel,
        headersHtml,
        rows,
        emptyRowHtml,
        emptyLabel,
        wrapperClass = 'content-card',
        tableClass = 'table table-hover align-middle mb-0'
    }) {
        const pagination = getModalTablePagination(rows, key);
        const bodyHtml = pagination.items.length > 0 ? pagination.items.join('') : emptyRowHtml;
        const badgeMarkup = badgeLabel ? `<span class="badge ${badgeClass} rounded-pill">${badgeLabel}</span>` : '';

        return `
            <div class="${wrapperClass}">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <h6 class="${titleClass} fw-bold mb-0">${title}</h6>
                    ${badgeMarkup}
                </div>
                <div class="table-responsive modal-table-shell">
                    <table class="${tableClass}">
                        <thead class="table-light">
                            <tr>${headersHtml}</tr>
                        </thead>
                        <tbody>${bodyHtml}</tbody>
                    </table>
                </div>
                ${renderModalPagination(key, pagination, emptyLabel)}
            </div>
        `;
    }

    function setModalTablePage(key, page) {
        const state = getModalTableState(key);
        state.page = Math.max(1, Number(page) || 1);

        if (key === 'taskAssignments') {
            renderTaskAssignmentsList();
            return;
        }

        if (activeStudentDetailsId !== null) {
            renderStudentDetailsModal(activeStudentDetailsId);
        }
    }

    function formatDueDate(dueDate) {
        if (!dueDate) {
            return 'No due date';
        }

        const parsedDate = new Date(`${dueDate}T00:00:00`);
        if (Number.isNaN(parsedDate.getTime())) {
            return dueDate;
        }

        return parsedDate.toLocaleDateString(undefined, {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    }

    function formatHours(value) {
        const parsedValue = Number(value || 0);
        return Number.isFinite(parsedValue) ? parsedValue.toFixed(2) : '0.00';
    }

    function getRemainingHours(approvedHours) {
        return Math.max(Number(requiredHours || 0) - Number(approvedHours || 0), 0);
    }

    function isNearRequiredHours(approvedHours) {
        const normalizedRequiredHours = Number(requiredHours || 0);
        const normalizedApprovedHours = Number(approvedHours || 0);

        if (!normalizedRequiredHours || normalizedApprovedHours >= normalizedRequiredHours) {
            return false;
        }

        return normalizedApprovedHours >= (normalizedRequiredHours * 0.9);
    }

    function calculateApprovedHoursFromAccomplishments(accomplishmentList = []) {
        return accomplishmentList.reduce((total, accomplishment) => {
            if (String(accomplishment.status || '').toLowerCase() === 'approved') {
                return total + Number(accomplishment.hours || 0);
            }

            return total;
        }, 0);
    }

    function formatDateTime(dateValue) {
        if (!dateValue) {
            return 'N/A';
        }

        const parsedDate = new Date(dateValue);
        if (Number.isNaN(parsedDate.getTime())) {
            return dateValue;
        }

        return parsedDate.toLocaleString(undefined, {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function getStatusBadgeClass(status) {
        const normalizedStatus = String(status || '').toLowerCase();

        if (normalizedStatus === 'approved' || normalizedStatus === 'completed' || normalizedStatus === 'verified') {
            return 'bg-success';
        }

        if (normalizedStatus === 'pending') {
            return 'bg-warning text-dark';
        }

        if (normalizedStatus === 'in progress') {
            return 'bg-info text-dark';
        }

        if (normalizedStatus === 'rejected') {
            return 'bg-danger';
        }

        return 'bg-secondary';
    }

    function setButtonLoading(button, isLoading, loadingLabel = 'Processing...') {
        if (!button) return;

        if (isLoading) {
            if (!button.dataset.originalHtml) {
                button.dataset.originalHtml = button.innerHTML;
            }

            button.disabled = true;
            button.innerHTML = `<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>${loadingLabel}`;
            return;
        }

        if (button.dataset.originalHtml) {
            button.innerHTML = button.dataset.originalHtml;
            delete button.dataset.originalHtml;
        }

        button.disabled = false;
    }

    async function markNotificationRead(notificationId) {
        if (!notificationId) return;

        const notification = notifications.find((item) => String(item.id) === String(notificationId));
        if (!notification || Number(notification.is_read) === 1) {
            return;
        }

        notification.is_read = 1;

        try {
            await fetch('mark_notification_read.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ notification_id: notificationId })
            });
        } catch (error) {
            // Keep the UI responsive even if the read-state sync fails.
        }
    }

    function studentMatchesNameSearch(student, searchTerm) {
        const normalizedTerm = String(searchTerm || '').trim().toLowerCase();
        if (!normalizedTerm) {
            return true;
        }

        const haystack = [
            student.firstname,
            student.mi,
            student.lastname,
            `${student.lastname}, ${student.firstname}`,
            `${student.firstname} ${student.lastname}`,
            student.email,
            student.student_number
        ].join(' ').toLowerCase();

        return haystack.includes(normalizedTerm);
    }

    function getFilteredAdvisoryStudents() {
        return advisoryStudentsData.filter(student => studentMatchesNameSearch(student, advisoryStudentSearch));
    }

    function getFilteredPendingAdvisoryAccomplishments() {
        const hasInvalidDateRange = pendingSubmissionDateFrom !== ''
            && pendingSubmissionDateTo !== ''
            && pendingSubmissionDateFrom > pendingSubmissionDateTo;

        if (hasInvalidDateRange) {
            return {
                hasInvalidDateRange: true,
                items: []
            };
        }

        return {
            hasInvalidDateRange: false,
            items: pendingAdvisoryAccomps.filter(acc => {
                const workDate = String(acc.work_date || '').slice(0, 10);

                if (pendingSubmissionDateFrom && workDate < pendingSubmissionDateFrom) {
                    return false;
                }

                if (pendingSubmissionDateTo && workDate > pendingSubmissionDateTo) {
                    return false;
                }

                return studentMatchesNameSearch(acc, advisoryStudentSearch);
            })
        };
    }

    function refreshStudentCompletedHours(studentId) {
        const accomplishmentList = allStudentAccomps[studentId] || [];
        const approvedHours = calculateApprovedHoursFromAccomplishments(accomplishmentList);

        advisoryStudentsData.forEach(student => {
            if (String(student.stud_id) === String(studentId)) {
                student.completed_hours = approvedHours;
            }
        });

        Object.keys(studentsData).forEach(year => {
            Object.keys(studentsData[year]).forEach(section => {
                studentsData[year][section].forEach(student => {
                    if (String(student.stud_id) === String(studentId)) {
                        student.completed_hours = approvedHours;
                    }
                });
            });
        });

        return approvedHours;
    }

    function syncLocalAccomplishmentStatus(accomplishmentId, nextStatus) {
        let relatedStudentId = null;
        let foundInHistory = false;

        Object.keys(allStudentAccomps).forEach(studentKey => {
            const accomplishment = (allStudentAccomps[studentKey] || []).find(item => String(item.id) === String(accomplishmentId));
            if (accomplishment) {
                accomplishment.status = nextStatus;
                relatedStudentId = accomplishment.student_id;
                foundInHistory = true;
            }
        });

        if (!foundInHistory) {
            const pendingAccomplishment = pendingAdvisoryAccomps.find(item => String(item.id) === String(accomplishmentId));
            if (pendingAccomplishment) {
                const studentKey = String(pendingAccomplishment.student_id);
                if (!Array.isArray(allStudentAccomps[studentKey])) {
                    allStudentAccomps[studentKey] = [];
                }

                allStudentAccomps[studentKey].unshift({
                    ...pendingAccomplishment,
                    status: nextStatus
                });
                relatedStudentId = pendingAccomplishment.student_id;
            }
        }

        if (relatedStudentId !== null && relatedStudentId !== undefined) {
            refreshStudentCompletedHours(relatedStudentId);
        }
    }

    function togglePendingAccomplishmentSelection(accomplishmentId, isSelected) {
        const normalizedId = String(accomplishmentId);

        if (isSelected) {
            selectedPendingAccomplishmentIds.add(normalizedId);
        } else {
            selectedPendingAccomplishmentIds.delete(normalizedId);
        }

        renderAdvisory(document.getElementById('main-content'));
    }

    function toggleAllPendingAccomplishments(sourceCheckbox) {
        const { items } = getFilteredPendingAdvisoryAccomplishments();
        const shouldSelectAll = Boolean(sourceCheckbox && sourceCheckbox.checked);

        items.forEach(accomplishment => {
            const normalizedId = String(accomplishment.id);
            if (shouldSelectAll) {
                selectedPendingAccomplishmentIds.add(normalizedId);
            } else {
                selectedPendingAccomplishmentIds.delete(normalizedId);
            }
        });

        renderAdvisory(document.getElementById('main-content'));
    }

    async function handleBulkAccomplishmentApproval(button) {
        const selectedIds = Array.from(selectedPendingAccomplishmentIds);

        if (selectedIds.length === 0) {
            showToast('Select at least one pending accomplishment first.', 'warning');
            return;
        }

        if (!window.confirm(`Approve ${selectedIds.length} selected accomplishment report${selectedIds.length === 1 ? '' : 's'}?`)) {
            return;
        }

        setButtonLoading(button, true, 'Approving...');

        try {
            const payload = new URLSearchParams();
            payload.append('ajax', '1');
            payload.append('bulk_approve_accomp', '1');
            selectedIds.forEach(id => payload.append('ar_ids[]', id));

            const response = await fetch(currentDashboardPath, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: payload.toString()
            });
            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Unable to bulk approve the selected accomplishment reports.');
            }

            selectedIds.forEach(id => {
                syncLocalAccomplishmentStatus(id, 'Approved');
            });

            for (let index = pendingAdvisoryAccomps.length - 1; index >= 0; index -= 1) {
                if (selectedPendingAccomplishmentIds.has(String(pendingAdvisoryAccomps[index].id))) {
                    pendingAdvisoryAccomps.splice(index, 1);
                }
            }

            selectedPendingAccomplishmentIds.clear();
            showToast(data.message || 'Selected accomplishment reports approved successfully.', data.type || 'success');
            renderAdvisory(document.getElementById('main-content'));
        } catch (error) {
            showToast(error.message || 'Unable to bulk approve the selected accomplishment reports.', 'danger');
            setButtonLoading(button, false);
        }
    }

    function attachLoadingStateToForms(root = document) {
        root.querySelectorAll('form[data-loading-label]').forEach(form => {
            if (form.dataset.loadingBound === '1') {
                return;
            }

            form.dataset.loadingBound = '1';
            form.addEventListener('submit', () => {
                if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
                    return;
                }

                const submitButton = form.querySelector('button[type="submit"]');
                setButtonLoading(submitButton, true, form.dataset.loadingLabel || 'Processing...');
            });
        });
    }

    function getSortedUniqueValues(values, numeric = false) {
        return Array.from(new Set(values.filter(value => value !== null && value !== undefined && value !== '')))
            .sort((a, b) => {
                if (numeric) {
                    return Number(a) - Number(b);
                }

                return String(a).localeCompare(String(b), undefined, {
                    numeric: true,
                    sensitivity: 'base'
                });
            });
    }

    function getAllAssignableStudents() {
        const students = [];

        Object.keys(studentsData).forEach(year => {
            Object.keys(studentsData[year]).forEach(section => {
                studentsData[year][section].forEach(student => {
                    students.push(student);
                });
            });
        });

        return students.sort((a, b) => {
            const yearDiff = Number(a.year_level || 0) - Number(b.year_level || 0);
            if (yearDiff !== 0) return yearDiff;

            const sectionDiff = String(a.section || '').localeCompare(String(b.section || ''), undefined, {
                numeric: true,
                sensitivity: 'base'
            });
            if (sectionDiff !== 0) return sectionDiff;

            const lastNameDiff = String(a.lastname || '').localeCompare(String(b.lastname || ''), undefined, {
                sensitivity: 'base'
            });
            if (lastNameDiff !== 0) return lastNameDiff;

            return String(a.firstname || '').localeCompare(String(b.firstname || ''), undefined, {
                sensitivity: 'base'
            });
        });
    }

    function formatStudentDisplayName(student) {
        return `${student.lastname}, ${student.firstname}${student.mi ? ' ' + student.mi + '.' : ''}`;
    }

    function studentMatchesSelectionFilters(student) {
        const searchTerm = String(studentSelectionSearch || '').trim().toLowerCase();
        const haystack = [
            student.firstname,
            student.mi,
            student.lastname,
            `${student.lastname}, ${student.firstname}`,
            student.email
        ].join(' ').toLowerCase();

        if (searchTerm && !haystack.includes(searchTerm)) {
            return false;
        }

        if (studentSelectionYearFilter && String(student.year_level) !== String(studentSelectionYearFilter)) {
            return false;
        }

        if (studentSelectionSectionFilter && String(student.section || '') !== String(studentSelectionSectionFilter)) {
            return false;
        }

        return true;
    }

    function syncSelectedStudentInputs() {
        const hiddenInputs = document.getElementById('selectedStudentsHiddenInputs');
        if (!hiddenInputs) return;

        hiddenInputs.innerHTML = Array.from(selectedStudentIds)
            .sort((a, b) => Number(a) - Number(b))
            .map(studentId => `<input type="hidden" name="send_to[]" value="${studentId}">`)
            .join('');
    }

    function updateStudentSelectionSummary(totalCount, visibleCount) {
        const summary = document.getElementById('studentSelectionSummary');
        if (!summary) return;

        summary.textContent = `Showing ${visibleCount} of ${totalCount} students. ${selectedStudentIds.size} selected.`;
    }

    function updateStudentSelectionFilterOptions(allStudents) {
        const yearFilter = document.getElementById('studentSelectionYearFilter');
        const sectionFilter = document.getElementById('studentSelectionSectionFilter');
        const availableYears = getSortedUniqueValues(allStudents.map(student => String(student.year_level || '')), true);
        const studentsForSections = studentSelectionYearFilter
            ? allStudents.filter(student => String(student.year_level) === String(studentSelectionYearFilter))
            : allStudents;
        const availableSections = getSortedUniqueValues(studentsForSections.map(student => String(student.section || '')));

        if (studentSelectionYearFilter && !availableYears.includes(String(studentSelectionYearFilter))) {
            studentSelectionYearFilter = '';
        }

        if (studentSelectionSectionFilter && !availableSections.includes(String(studentSelectionSectionFilter))) {
            studentSelectionSectionFilter = '';
        }

        if (yearFilter) {
            yearFilter.innerHTML = `
                <option value="">All Year Levels</option>
                ${availableYears.map(year => `<option value="${year}">Year ${year}</option>`).join('')}
            `;
            yearFilter.value = studentSelectionYearFilter;
        }

        if (sectionFilter) {
            sectionFilter.innerHTML = `
                <option value="">All Sections</option>
                ${availableSections.map(section => `<option value="${section}">Section ${section}</option>`).join('')}
            `;
            sectionFilter.value = studentSelectionSectionFilter;
        }
    }

    function clearStudentSelectionFilters() {
        studentSelectionSearch = '';
        studentSelectionYearFilter = '';
        studentSelectionSectionFilter = '';

        const searchInput = document.getElementById('studentSelectionSearch');
        const yearFilter = document.getElementById('studentSelectionYearFilter');
        const sectionFilter = document.getElementById('studentSelectionSectionFilter');

        if (searchInput) searchInput.value = '';
        if (yearFilter) yearFilter.value = '';
        if (sectionFilter) sectionFilter.value = '';

        populateStudentSelection();
    }

    function handleStudentAssignmentToggle(checkbox) {
        const studentId = String(checkbox.value);

        if (checkbox.checked) {
            selectedStudentIds.add(studentId);
        } else {
            selectedStudentIds.delete(studentId);
        }

        syncSelectedStudentInputs();

        const allStudents = getAllAssignableStudents();
        const filteredStudents = allStudents.filter(studentMatchesSelectionFilters);
        updateStudentSelectionSummary(allStudents.length, filteredStudents.length);
    }

    function taskAssignmentMatchesFilters(assignment) {
        const searchTerm = String(taskAssignmentSearch || '').trim().toLowerCase();
        const haystack = [
            assignment.firstname,
            assignment.lastname,
            `${assignment.lastname}, ${assignment.firstname}`,
            assignment.email,
            assignment.student_number
        ].join(' ').toLowerCase();

        if (searchTerm && !haystack.includes(searchTerm)) {
            return false;
        }

        if (taskAssignmentYearFilter && String(assignment.year_level) !== String(taskAssignmentYearFilter)) {
            return false;
        }

        if (taskAssignmentSectionFilter && String(assignment.section || '') !== String(taskAssignmentSectionFilter)) {
            return false;
        }

        if (taskAssignmentStatusFilter !== 'all' && String(assignment.assignment_status || '').toLowerCase() !== taskAssignmentStatusFilter) {
            return false;
        }

        return true;
    }

    function updateTaskAssignmentFilterOptions(assignments) {
        const yearFilter = document.getElementById('taskAssignmentYearFilter');
        const sectionFilter = document.getElementById('taskAssignmentSectionFilter');
        const availableYears = getSortedUniqueValues(assignments.map(assignment => String(assignment.year_level || '')), true);
        const assignmentsForSections = taskAssignmentYearFilter
            ? assignments.filter(assignment => String(assignment.year_level) === String(taskAssignmentYearFilter))
            : assignments;
        const availableSections = getSortedUniqueValues(assignmentsForSections.map(assignment => String(assignment.section || '')));

        if (taskAssignmentYearFilter && !availableYears.includes(String(taskAssignmentYearFilter))) {
            taskAssignmentYearFilter = '';
        }

        if (taskAssignmentSectionFilter && !availableSections.includes(String(taskAssignmentSectionFilter))) {
            taskAssignmentSectionFilter = '';
        }

        if (yearFilter) {
            yearFilter.innerHTML = `
                <option value="">All Year Levels</option>
                ${availableYears.map(year => `<option value="${year}">Year ${year}</option>`).join('')}
            `;
            yearFilter.value = taskAssignmentYearFilter;
        }

        if (sectionFilter) {
            sectionFilter.innerHTML = `
                <option value="">All Sections</option>
                ${availableSections.map(section => `<option value="${section}">Section ${section}</option>`).join('')}
            `;
            sectionFilter.value = taskAssignmentSectionFilter;
        }
    }

    function renderTaskAssignmentsList() {
        const assignments = activeTaskDetailsId !== null ? (taskAssignments[activeTaskDetailsId] || []) : [];
        const task = myTasks.find(item => String(item.task_id) === String(activeTaskDetailsId));
        const title = document.getElementById('taskDetailsTitle');
        const summary = document.getElementById('taskAssignmentsSummary');
        const container = document.getElementById('taskAssignmentsList');
        const paginationContainer = document.getElementById('taskAssignmentsPagination');
        const reminderTaskIdInput = document.getElementById('taskReminderTaskId');
        const reminderButton = document.getElementById('taskReminderBtn');

        if (!container) return;

        updateTaskAssignmentFilterOptions(assignments);

        if (title) {
            if (task) {
                title.textContent = `${task.title} (${assignments.length} assigned student${assignments.length === 1 ? '' : 's'})`;
            } else {
                title.textContent = `Assigned Students (${assignments.length})`;
            }
        }

        const filteredAssignments = assignments.filter(taskAssignmentMatchesFilters);
        const pagination = getModalTablePagination(filteredAssignments, 'taskAssignments');
        const visibleAssignments = pagination.items;
        const completedAssignments = assignments.filter(assignment => String(assignment.assignment_status || '').toLowerCase() === 'completed').length;
        const inProgressAssignments = assignments.filter(assignment => String(assignment.assignment_status || '').toLowerCase() === 'in progress').length;
        const pendingAssignments = assignments.filter(assignment => String(assignment.assignment_status || '').toLowerCase() === 'pending').length;
        const incompleteAssignments = assignments.filter(assignment => String(assignment.assignment_status || '').toLowerCase() !== 'completed').length;

        if (reminderTaskIdInput) {
            reminderTaskIdInput.value = activeTaskDetailsId !== null ? String(activeTaskDetailsId) : '';
        }

        if (reminderButton) {
            reminderButton.disabled = incompleteAssignments === 0;
        }

        if (summary) {
            const visibleLabel = filteredAssignments.length === 0
                ? 'Showing 0 filtered students.'
                : `Showing ${pagination.startIndex + 1}-${pagination.endIndex} of ${filteredAssignments.length} filtered students.`;
            const summaryParts = [`${visibleLabel} ${assignments.length} assigned student${assignments.length === 1 ? '' : 's'} total.`];

            if (task && task.due_date) {
                summaryParts.push(`Due ${formatDueDate(task.due_date)}.`);
            }

            if (assignments.length > 0) {
                summaryParts.push(`${completedAssignments} completed.`);
                summaryParts.push(`${inProgressAssignments} in progress.`);
                summaryParts.push(`${pendingAssignments} pending.`);
            }

            summary.textContent = summaryParts.join(' ');
        }

        if (filteredAssignments.length === 0) {
            container.innerHTML = `
                <tr>
                    <td colspan="5" class="text-center text-muted">
                        ${assignments.length === 0 ? 'No students assigned to this task.' : 'No assigned students match the current filters.'}
                    </td>
                </tr>
            `;
            if (paginationContainer) {
                paginationContainer.innerHTML = renderModalPagination(
                    'taskAssignments',
                    pagination,
                    assignments.length === 0
                        ? 'No students assigned to this task yet.'
                        : 'No assigned students match the current filters.'
                );
            }
            return;
        }

        container.innerHTML = visibleAssignments.map(assignment => {
            const normalizedStatus = String(assignment.assignment_status || '').toLowerCase();
            let badgeClass = 'bg-secondary';
            if (normalizedStatus === 'completed') badgeClass = 'bg-success';
            else if (normalizedStatus === 'in progress') badgeClass = 'bg-info text-dark';
            else if (normalizedStatus === 'pending') badgeClass = 'bg-warning text-dark';

            const timeline = assignment.completed_at
                ? `Completed ${formatDateTime(assignment.completed_at)}`
                : `Assigned ${formatDateTime(assignment.assigned_at)}`;
            const canReassign = normalizedStatus === 'pending';
            const studentLabel = `${assignment.lastname}, ${assignment.firstname}`;
            const studentMeta = [assignment.student_number, assignment.email].filter(Boolean).join(' â€¢ ');

            return `
                <tr>
                    <td>
                        <div class="fw-bold">${studentLabel}</div>
                        <div class="small text-muted">${studentMeta || 'No additional student details'}</div>
                    </td>
                    <td>Section ${assignment.section} (Year ${assignment.year_level})</td>
                    <td><span class="badge ${badgeClass}">${assignment.assignment_status}</span></td>
                    <td><small class="text-muted">${timeline}</small></td>
                    <td>
                        ${canReassign ? `
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-primary"
                                onclick="openReassignTaskModal(${assignment.stask_id}, ${assignment.student_id}, ${assignment.task_id})"
                            >
                                <i class="fas fa-random me-1"></i> Reassign
                            </button>
                        ` : '<span class="small text-muted">Reassign unavailable</span>'}
                    </td>
                </tr>
            `;
        }).join('');

        if (paginationContainer) {
            paginationContainer.innerHTML = renderModalPagination('taskAssignments', pagination);
        }
    }

    function clearTaskAssignmentFilters() {
        taskAssignmentSearch = '';
        taskAssignmentYearFilter = '';
        taskAssignmentSectionFilter = '';
        taskAssignmentStatusFilter = 'all';
        resetModalTablePage('taskAssignments');

        const searchInput = document.getElementById('taskAssignmentSearch');
        const yearFilter = document.getElementById('taskAssignmentYearFilter');
        const sectionFilter = document.getElementById('taskAssignmentSectionFilter');
        const statusFilter = document.getElementById('taskAssignmentStatusFilter');

        if (searchInput) searchInput.value = '';
        if (yearFilter) yearFilter.value = '';
        if (sectionFilter) sectionFilter.value = '';
        if (statusFilter) statusFilter.value = 'all';

        renderTaskAssignmentsList();
    }

    function openReassignTaskModal(studentTaskId, currentStudentId, taskId) {
        const assignment = (taskAssignments[taskId] || []).find(item => String(item.stask_id) === String(studentTaskId));
        const task = myTasks.find(item => String(item.task_id) === String(taskId));
        const studentSelect = document.getElementById('reassignStudentSelect');
        const context = document.getElementById('reassignTaskContext');
        const studentTaskInput = document.getElementById('reassignStudentTaskId');

        if (!assignment || !studentSelect || !studentTaskInput) {
            showToast('Unable to prepare the task reassignment form.', 'danger');
            return;
        }

        studentTaskInput.value = String(studentTaskId);

        const eligibleStudents = getAllAssignableStudents().filter(student => String(student.stud_id) !== String(currentStudentId));
        studentSelect.innerHTML = `
            <option value="">Select student</option>
            ${eligibleStudents.map(student => `
                <option value="${student.stud_id}">
                    ${formatStudentDisplayName(student)}${student.section ? ` â€¢ Section ${student.section}` : ''}
                </option>
            `).join('')}
        `;

        if (context) {
            const taskTitle = task ? task.title : 'this task';
            context.textContent = `Reassign "${taskTitle}" from ${assignment.lastname}, ${assignment.firstname} to another student.`;
        }

        bootstrap.Modal.getOrCreateInstance(document.getElementById('reassignTaskModal')).show();
    }

    function attachTaskFormValidation() {
        const forms = [
            {
                form: document.getElementById('createTaskForm'),
                extraCheck() {
                    const selectedTitle = document.getElementById('finalTaskTitle');
                    if (selectedTitle && !selectedTitle.value.trim()) {
                        showToast('Please choose a task category before submitting.', 'danger');
                        return false;
                    }
                    return true;
                }
            },
            {
                form: document.getElementById('editTaskForm'),
                extraCheck() {
                    return true;
                }
            }
        ];

        forms.forEach(({ form, extraCheck }) => {
            if (!form) return;

            form.addEventListener('submit', (event) => {
                if (form.id === 'createTaskForm') {
                    syncSelectedStudentInputs();
                }

                if (!extraCheck()) {
                    event.preventDefault();
                    event.stopPropagation();
                    return;
                }

                if (!form.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();
                    showToast('Please complete all required fields before submitting.', 'danger');
                    form.reportValidity();
                    return;
                }

                const submitButton = form.querySelector('button[type="submit"]');
                setButtonLoading(submitButton, true, form.id === 'editTaskForm' ? 'Saving...' : 'Submitting...');
            });
        });
    }

    function clearFlashParamsFromUrl() {
        const url = new URL(window.location.href);
        url.searchParams.delete('msg');
        url.searchParams.delete('msg_type');
        window.history.replaceState({}, document.title, `${url.pathname}${url.search}${url.hash}`);
    }


    // View Management
    function setActiveViewLinks(viewName) {
        document.querySelectorAll('[data-view-link]').forEach((item) => {
            item.classList.toggle('active', item.getAttribute('data-view-link') === viewName);
        });
    }

    function showView(viewName) {
        setActiveViewLinks(viewName);
        sessionStorage.setItem('instructorActiveView', viewName);

        const container = document.getElementById('main-content');
        container.innerHTML = ''; // Clear content

        if(viewName === 'dashboard') renderDashboard(container);
        else if(viewName === 'classes') renderClasses(container);
        else if(viewName === 'advisory') renderAdvisory(container);
        else if(viewName === 'tasks') renderTasks(container);
        else if(viewName === 'notifications') renderNotifications(container);
    }

    function findViewTrigger(viewName) {
        return document.querySelector(`[data-view-link="${viewName}"]`);
    }

    function highlightNotificationTarget(selector) {
        if (!selector) return;

        const target = document.querySelector(selector);
        if (!target) return;

        target.classList.add('border-primary', 'shadow-sm');
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });

        window.setTimeout(() => {
            target.classList.remove('border-primary', 'shadow-sm');
        }, 2200);
    }

    function openInstructorNotification(notificationId) {
        const notification = (notifications || []).find((item) => String(item.id) === String(notificationId));
        if (!notification) return;

        markNotificationRead(notificationId);

        const type = String(notification.type || '').toLowerCase();

        if (type.includes('task')) {
            showView('tasks', findViewTrigger('tasks'));
            window.requestAnimationFrame(() => {
                highlightNotificationTarget(notification.reference_id ? `[data-task-id="${notification.reference_id}"]` : '');
                if (notification.reference_id && typeof openTaskDetailsModal === 'function') {
                    openTaskDetailsModal(notification.reference_id);
                }
            });
            return;
        }

        showView('advisory', findViewTrigger('advisory'));

        window.requestAnimationFrame(() => {
            if (type.includes('accomp') || type.includes('submission') || type.includes('report')) {
                highlightNotificationTarget(
                    notification.reference_id
                        ? `[data-accomp-id="${notification.reference_id}"]`
                        : (notification.student_id ? `[data-student-id="${notification.student_id}"]` : '')
                );
                return;
            }

            if (notification.student_id && typeof viewStudentDetails === 'function') {
                highlightNotificationTarget(`[data-student-id="${notification.student_id}"]`);
                viewStudentDetails(notification.student_id);
            }
        });
    }

    async function handleAccomplishmentAction(action, accomplishmentId, button = null) {
        const verb = action === 'approve' ? 'approve' : 'reject';
        const confirmationMessage = action === 'approve'
            ? 'Approve this accomplishment report?'
            : 'Reject this accomplishment report?';

        if (!window.confirm(confirmationMessage)) {
            return;
        }

        const row = document.querySelector(`[data-accomp-id="${accomplishmentId}"]`);
        const actionButtons = row ? Array.from(row.querySelectorAll('button')) : [];
        actionButtons.forEach((actionButton) => {
            if (button && actionButton === button) {
                return;
            }
            actionButton.disabled = true;
        });
        if (button) {
            setButtonLoading(button, true, action === 'approve' ? 'Approving...' : 'Rejecting...');
        }

        try {
            const response = await fetch(currentDashboardPath, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({
                    ajax: '1',
                    ar_id: String(accomplishmentId),
                    [action === 'approve' ? 'approve_accomp' : 'reject_accomp']: '1'
                }).toString()
            });
            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.message || `Unable to ${verb} the accomplishment report.`);
            }

            syncLocalAccomplishmentStatus(accomplishmentId, action === 'approve' ? 'Approved' : 'Rejected');
            selectedPendingAccomplishmentIds.delete(String(accomplishmentId));
            const accomplishmentIndex = pendingAdvisoryAccomps.findIndex((item) => String(item.id) === String(accomplishmentId));
            if (accomplishmentIndex !== -1) {
                pendingAdvisoryAccomps.splice(accomplishmentIndex, 1);
            }

            showToast(data.message || `Accomplishment ${verb}d successfully.`, data.type || 'success');
            renderAdvisory(document.getElementById('main-content'));
        } catch (error) {
            showToast(error.message || `Unable to ${verb} the accomplishment report.`, 'danger');
            if (button) {
                setButtonLoading(button, false);
            }
            actionButtons.forEach((actionButton) => {
                actionButton.disabled = false;
            });
        }
    }

    function openEndSessionModal(studentId, studentName) {
        document.getElementById('endSessionStudentId').value = studentId;
        document.getElementById('endSessionStudentName').textContent = studentName || 'Selected student';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('endSessionConfirmModal')).show();
    }

    async function confirmEndSession() {
        const studentId = document.getElementById('endSessionStudentId').value;
        const confirmButton = document.getElementById('confirmEndSessionBtn');

        if (!studentId) {
            showToast('Unable to identify the selected student.', 'danger');
            return;
        }

        setButtonLoading(confirmButton, true, 'Ending...');

        try {
            const response = await fetch('process_section_request.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({
                    ajax: '1',
                    end_session: '1',
                    student_id: String(studentId)
                }).toString()
            });
            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Could not end the student session.');
            }

            for (let index = advisoryStudentsData.length - 1; index >= 0; index -= 1) {
                if (String(advisoryStudentsData[index].stud_id) === String(studentId)) {
                    advisoryStudentsData.splice(index, 1);
                }
            }

            for (let index = pendingAdvisoryAccomps.length - 1; index >= 0; index -= 1) {
                if (String(pendingAdvisoryAccomps[index].student_id) === String(studentId)) {
                    selectedPendingAccomplishmentIds.delete(String(pendingAdvisoryAccomps[index].id));
                    pendingAdvisoryAccomps.splice(index, 1);
                }
            }

            bootstrap.Modal.getInstance(document.getElementById('endSessionConfirmModal'))?.hide();
            showToast(data.message || 'Student session ended successfully.', data.type || 'success');
            renderAdvisory(document.getElementById('main-content'));
        } catch (error) {
            showToast(error.message || 'Could not end the student session.', 'danger');
        } finally {
            setButtonLoading(confirmButton, false);
        }
    }

    function renderAdvisory(container) {
        const hasPendingSubmissions = pendingAdvisoryAccomps.length > 0;
        const filteredAdvisoryStudents = getFilteredAdvisoryStudents();
        const { hasInvalidDateRange: hasInvalidPendingDateRange, items: filteredPendingAdvisoryAccomps } = getFilteredPendingAdvisoryAccomplishments();
        const allVisiblePendingSelected = filteredPendingAdvisoryAccomps.length > 0
            && filteredPendingAdvisoryAccomps.every(acc => selectedPendingAccomplishmentIds.has(String(acc.id)));
        const requiredHoursLabel = Number(requiredHours || 0).toFixed(0);

        let html = `
        <br>
        <br>
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
                <h2>My Advisory Class</h2>
                <div class="d-flex gap-2 flex-wrap">
                    <button class="btn btn-outline-secondary" type="button" onclick="window.location.href='export_records.php?type=student_list'">
                        <i class="fas fa-users me-2"></i> Export Student List
                    </button>
                    <button class="btn btn-outline-secondary" type="button" onclick="window.location.href='export_records.php?type=advisory_records'">
                        <i class="fas fa-file-csv me-2"></i> Export Records
                    </button>
                    <button class="btn btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#announcementModal">
                        <i class="fas fa-bullhorn me-2"></i> Send Announcement
                    </button>
                </div>
            </div>

            <div class="content-card mb-4">
                <div class="row g-2 align-items-end">
                    <div class="col-lg-6">
                        <label for="advisoryStudentSearch" class="form-label small text-muted mb-1">Search Student</label>
                        <input
                            type="search"
                            id="advisoryStudentSearch"
                            class="form-control"
                            placeholder="Search by name, email, or student number"
                            value="${advisoryStudentSearch}"
                            oninput="advisoryStudentSearch = this.value; renderAdvisory(document.getElementById('main-content'));"
                        >
                    </div>
                    <div class="col-lg-2 d-grid align-items-end">
                        <button
                            type="button"
                            class="btn btn-outline-secondary"
                            onclick="advisoryStudentSearch = ''; selectedPendingAccomplishmentIds.clear(); renderAdvisory(document.getElementById('main-content'));"
                        >
                            Clear Search
                        </button>
                    </div>
                    <div class="col-lg-4">
                        <div class="small text-muted">
                            Search applies to advisory students and pending submissions.
                        </div>
                    </div>
                </div>
            </div>
            
            ${hasPendingSubmissions ? `
            <div class="content-card mb-4 border-start border-warning border-5">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div class="d-flex align-items-center flex-wrap gap-2">
                        <h4 class="text-warning mb-0"><i class="fas fa-hourglass-half me-2"></i>Pending Submissions</h4>
                        <span class="badge bg-warning text-dark rounded-pill">${filteredPendingAdvisoryAccomps.length} of ${pendingAdvisoryAccomps.length} shown</span>
                        ${selectedPendingAccomplishmentIds.size > 0 ? `<span class="badge bg-primary rounded-pill">${selectedPendingAccomplishmentIds.size} selected</span>` : ''}
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <button
                            type="button"
                            class="btn btn-outline-secondary btn-sm"
                            onclick="selectedPendingAccomplishmentIds.clear(); renderAdvisory(document.getElementById('main-content'));"
                            ${selectedPendingAccomplishmentIds.size === 0 ? 'disabled' : ''}
                        >
                            Clear Selection
                        </button>
                        <button
                            type="button"
                            class="btn btn-success btn-sm"
                            onclick="handleBulkAccomplishmentApproval(this)"
                            ${selectedPendingAccomplishmentIds.size === 0 ? 'disabled' : ''}
                        >
                            <i class="fas fa-check-double me-1"></i> Bulk Approve
                        </button>
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label for="pendingSubmissionDateFrom" class="form-label small text-muted mb-1">From</label>
                        <input
                            type="date"
                            id="pendingSubmissionDateFrom"
                            class="form-control"
                            value="${pendingSubmissionDateFrom}"
                            onchange="pendingSubmissionDateFrom = this.value; renderAdvisory(document.getElementById('main-content'));"
                        >
                    </div>
                    <div class="col-md-4">
                        <label for="pendingSubmissionDateTo" class="form-label small text-muted mb-1">To</label>
                        <input
                            type="date"
                            id="pendingSubmissionDateTo"
                            class="form-control"
                            value="${pendingSubmissionDateTo}"
                            onchange="pendingSubmissionDateTo = this.value; renderAdvisory(document.getElementById('main-content'));"
                        >
                    </div>
                    <div class="col-md-4 d-grid align-items-end">
                        <button
                            type="button"
                            class="btn btn-outline-secondary"
                            onclick="pendingSubmissionDateFrom = ''; pendingSubmissionDateTo = ''; renderAdvisory(document.getElementById('main-content'));"
                        >
                            Clear Date Range
                        </button>
                    </div>
                </div>
                ${hasInvalidPendingDateRange ? `
                <div class="alert alert-warning mb-3">
                    Start date must be on or before end date.
                </div>
                ` : ''}
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 40px;">
                                    <input
                                        type="checkbox"
                                        class="form-check-input"
                                        ${allVisiblePendingSelected ? 'checked' : ''}
                                        onchange="toggleAllPendingAccomplishments(this)"
                                        ${filteredPendingAdvisoryAccomps.length === 0 ? 'disabled' : ''}
                                    >
                                </th>
                                <th>Student</th>
                                <th>Activity / Date</th>
                                <th>Hours</th>
                                <th>Proof</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${filteredPendingAdvisoryAccomps.length > 0 ? filteredPendingAdvisoryAccomps.map(acc => {
                                const fullName = `${acc.firstname} ${acc.mi ? acc.mi + '.' : ''} ${acc.lastname}`;
                                const date = new Date(acc.work_date).toLocaleDateString();
                                let photoHtml = '';
                                if(acc.photo) photoHtml += `<a href="../student/${acc.photo}" target="_blank" class="btn btn-sm btn-outline-info me-1"><i class="fas fa-paperclip"></i> 1</a>`;
                                if(acc.photo2) photoHtml += `<a href="../student/${acc.photo2}" target="_blank" class="btn btn-sm btn-outline-info"><i class="fas fa-paperclip"></i> 2</a>`;
                                
                                let assignedByHtml = '';
                                if (acc.assigner_fname) {
                                    if (acc.assigner_id_val == acc.student_adviser_id || acc.is_section_adviser > 0) {
                                         assignedByHtml = `<div class="small text-success mt-1"><i class="fas fa-user-shield me-1"></i> Adviser: ${acc.assigner_fname} ${acc.assigner_lname}</div>`;
                                    } else {
                                         assignedByHtml = `<div class="small text-primary mt-1"><i class="fas fa-user-tag me-1"></i> Instructor: ${acc.assigner_fname} ${acc.assigner_lname}</div>`;
                                    }
                                }

                                return `
                                <tr data-accomp-id="${acc.id}" data-student-id="${acc.student_id}">
                                    <td>
                                        <input
                                            type="checkbox"
                                            class="form-check-input"
                                            ${selectedPendingAccomplishmentIds.has(String(acc.id)) ? 'checked' : ''}
                                            onchange="togglePendingAccomplishmentSelection(${acc.id}, this.checked)"
                                        >
                                    </td>
                                    <td>
                                        <div class="fw-bold">${fullName}</div>
                                        <div class="small text-muted">Section ${acc.section}</div>
                                    </td>
                                    <td>
                                        <div class="fw-bold">${acc.activity}</div>
                                        <div class="small text-muted">${date} (${acc.time_start} - ${acc.time_end})</div>
                                        ${assignedByHtml}
                                    </td>
                                    <td><span class="badge bg-secondary">${acc.hours} hrs</span></td>
                                    <td>${photoHtml || '<span class="text-muted small">No attachments</span>'}</td>
                                    <td>
                                        <div class="d-flex gap-2">
                                            <button type="button" class="btn btn-success btn-sm" title="Approve" onclick="handleAccomplishmentAction('approve', ${acc.id}, this)">
                                                <i class="fas fa-check"></i>
                                            </button>
                                            <button type="button" class="btn btn-danger btn-sm" title="Reject" onclick="handleAccomplishmentAction('reject', ${acc.id}, this)">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                `;
                            }).join('') : `
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">No pending submissions found for the current filters.</td>
                                </tr>
                            `}
                        </tbody>
                    </table>
                </div>
            </div>
            ` : ''}

            `;
        
        if (advisoryStudentsData.length === 0) {
             html += '<div class="alert alert-info">No advisory section assigned yet or no students found in your assigned section.</div>';
        } else if (filteredAdvisoryStudents.length === 0) {
             html += '<div class="alert alert-info">No advisory students match the current search.</div>';
        } else {
            const studentsBySection = filteredAdvisoryStudents.reduce((acc, student) => {
                const section = student.section || 'Unassigned';
                if (!acc[section]) acc[section] = [];
                acc[section].push(student);
                return acc;
            }, {});

            Object.keys(studentsBySection).sort().forEach(section => {
                const sectionStudents = studentsBySection[section];
                const yearLevel = sectionStudents.length > 0 ? sectionStudents[0].year_level : 'N/A';
                
                html += `
                <div class="content-card mb-4">
                    <h4 class="mb-3 text-primary">Year: ${yearLevel} - Section: ${section}</h4>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Student Name</th>
                                    <th>Progress</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                `;
                
                sectionStudents.forEach(student => {
                     let photoPath = student.photo;
                     if (photoPath && !photoPath.startsWith('uploads/')) {
                         photoPath = 'uploads/profile_photos/' + photoPath;
                     }
                     const photoUrl = (student.photo && student.photo !== '') ? '../' + photoPath : '../img/logo.png';
                     
                     const fullName = `${student.firstname} ${student.mi ? student.mi + '.' : ''} ${student.lastname}`;
                     const hours = Number(student.completed_hours || 0);
                     const hoursLabel = formatHours(hours);
                     const remainingHoursLabel = formatHours(getRemainingHours(hours));
                     const progress = Math.min((hours / Number(requiredHours || 1)) * 100, 100).toFixed(1);
                     const canEndSession = hours >= Number(requiredHours);
                     const nearRequirement = isNearRequiredHours(hours);
                     const progressMessage = canEndSession
                        ? 'Ready for End Session'
                        : (nearRequirement
                            ? `Only ${remainingHoursLabel} hrs left to reach the ${requiredHoursLabel}-hour requirement`
                            : `${remainingHoursLabel} hrs remaining`);
                     const progressMessageClass = canEndSession
                        ? 'text-success fw-semibold'
                        : (nearRequirement ? 'text-warning fw-semibold' : 'text-muted');
                     
                     html += `
                        <tr data-student-id="${student.stud_id}">
                            <td>
                                <div class="d-flex align-items-center">
                                    <img src="${photoUrl}" class="rounded-circle me-3" style="width: 40px; height: 40px; object-fit: cover;" onerror="this.onerror=null;this.src='../img/logo.png'">
                                    <div class="fw-bold">${fullName}</div>
                                </div>
                            </td>
                            <td style="min-width: 150px;">
                                <div class="d-flex justify-content-between small text-muted mb-1">
                                    <span>${hoursLabel}/${requiredHoursLabel} hrs</span>
                                </div>
                                <div class="progress" style="height: 6px;">
                                    <div class="progress-bar bg-success" role="progressbar" style="width: ${progress}%"></div>
                                </div>
                                <div class="small ${progressMessageClass} mt-2">${progressMessage}</div>
                            </td>
                            <td>
                                <div class="d-flex gap-2">
                                    <button class="btn btn-outline-primary btn-sm" onclick="viewStudentDetails(${student.stud_id})">
                                        <i class="fas fa-eye me-1"></i> View Details
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-outline-danger btn-sm"
                                        title="${canEndSession ? 'End Session & Archive' : `Available after ${requiredHoursLabel} approved hours`}"
                                        onclick='openEndSessionModal(${student.stud_id}, ${JSON.stringify(fullName)})'
                                        ${canEndSession ? '' : 'disabled'}
                                    >
                                        <i class="fas fa-user-lock me-1"></i> End Session
                                    </button>
                                </div>
                            </td>
                        </tr>
                     `;
                });
                
                html += `
                            </tbody>
                        </table>
                    </div>
                </div>
                `;
            });
        }
        container.innerHTML = html;
        attachLoadingStateToForms(container);
    }

    function renderNotifications(container) {
        const unreadCount = notifications.filter(n => !n.is_read).length;
        
        // Group notifications by week
        const groups = {};
        const now = new Date();
        const oneDay = 24 * 60 * 60 * 1000;
        
        // Calculate Monday of current week
        const day = now.getDay() || 7; // Sunday is 7
        // Reset to Monday 00:00:00
        const currentWeekStart = new Date(now.getFullYear(), now.getMonth(), now.getDate() - day + 1);
        currentWeekStart.setHours(0,0,0,0);
        
        // Last week start
        const lastWeekStart = new Date(currentWeekStart.getTime() - 7 * oneDay);

        notifications.forEach(n => {
            const date = new Date(n.created_at);
            let label = 'Older';
            
            if (date >= currentWeekStart) {
                label = 'This Week';
            } else if (date >= lastWeekStart) {
                label = 'Last Week';
            } else {
                label = 'Older';
            }
            
            if (!groups[label]) groups[label] = [];
            groups[label].push(n);
        });
        
        // Defined order
        const groupOrder = ['This Week', 'Last Week', 'Older'];

        let html = `
        <br>
        <br>
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Notifications</h2>
                ${unreadCount > 0 ? '<button class="btn btn-sm btn-primary py-0 px-2" style="font-size: 0.75rem;" onclick="markAllRead()">Mark all as read</button>' : ''}
            </div>
            <div class="accordion" id="notificationAccordion">
        `;

        let hasNotifs = false;
        groupOrder.forEach((label, index) => {
            if (groups[label] && groups[label].length > 0) {
                hasNotifs = true;
                // Expand first group only
                const isExpanded = index === 0 ? 'true' : 'false';
                const showClass = index === 0 ? 'show' : '';
                const collapsedClass = index === 0 ? '' : 'collapsed';
                const id = label.replace(/\s+/g, '');
                
                html += `
                <div class="accordion-item">
                    <h2 class="accordion-header" id="heading${id}">
                        <button class="accordion-button ${collapsedClass}" type="button" data-bs-toggle="collapse" data-bs-target="#collapse${id}" aria-expanded="${isExpanded}" aria-controls="collapse${id}">
                            ${label}
                            <span class="badge bg-secondary ms-2">${groups[label].length}</span>
                        </button>
                    </h2>
                    <div id="collapse${id}" class="accordion-collapse collapse ${showClass}" aria-labelledby="heading${id}" data-bs-parent="#notificationAccordion">
                        <div class="accordion-body p-0">
                            <div class="list-group list-group-flush">
                `;
                
                groups[label].forEach(n => {
                    const bgClass = n.is_read == 0 ? 'bg-light' : '';
                    const type = String(n.type || '').toLowerCase();
                    const icon = type.includes('task')
                        ? 'fa-tasks'
                        : (type.includes('accomp') || type.includes('submission') || type.includes('report') ? 'fa-file-circle-check' : 'fa-user-clock');
                    const date = new Date(n.created_at).toLocaleString();
                    html += `
                        <button type="button" class="list-group-item list-group-item-action ${bgClass} d-flex justify-content-between align-items-center text-start" onclick="openInstructorNotification(${n.id})">
                            <div>
                                <i class="fas ${icon} me-2 text-primary"></i>
                                ${n.message}
                                <br><small class="text-muted ms-4">${date}</small>
                                <br><small class="text-primary fw-semibold ms-4">Open related feature</small>
                            </div>
                            ${n.is_read == 0 ? '<span class="badge bg-primary rounded-pill">New</span>' : ''}
                        </button>
                    `;
                });
                
                html += `
                            </div>
                        </div>
                    </div>
                </div>
                `;
            }
        });

        if (!hasNotifs) {
            html += '<div class="text-center p-4 text-muted">No notifications</div>';
        }

        html += `</div>`; // Close accordion
        container.innerHTML = html;
    }

    function markAllRead() {
        fetch('mark_notification_read.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ action: 'mark_all' })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                window.location.reload();
            }
        });
    }

    function renderDashboard(container) {
        let totalStudents = 0;
        let totalSections = 0;
        Object.keys(studentsData).forEach(year => {
            Object.keys(studentsData[year]).forEach(sec => {
                totalSections++;
                totalStudents += studentsData[year][sec].length;
            });
        });

        const html = `
        <br>
        <br>
            <h2 class="mb-2" Dashboard Overview</h2>
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-user-graduate"></i></div>
                        <h3 style="color: var(--secondary-color);">${totalStudents}</h3>
                        <p class="text-muted">Total Students</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-layer-group"></i></div>
                        <h3 style="color: var(--secondary-color);">${totalSections}</h3>
                        <p class="text-muted">Active Sections</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-clipboard-check"></i></div>
                        <h3 style="color: var(--secondary-color);">${pendingOrgs.length + pendingAccomps.length}</h3>
                        <p class="text-muted">Pending Approvals</p>
                    </div>
                </div>
            </div>
            
            <div class="row mt-5">
                <div class="col-12">
                    <div class="content-card">
                        <h4>Quick Actions</h4>
                        <div class="d-flex gap-3 mt-3">
                            <button class="btn btn-primary" onclick="showView('tasks', findViewTrigger('tasks'))">
                                <i class="fas fa-plus-circle me-2"></i> Create Task
                            </button>
                            <button class="btn btn-outline-secondary" onclick="showView('classes', findViewTrigger('classes'))">
                                <i class="fas fa-eye me-2"></i> View Students
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        container.innerHTML = html;
    }

    function renderClasses(container) {
        container.innerHTML = `
        <br>
        <br>
            <div id="classes-nav" class="mb-4">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <h2 class="mb-4">Year Levels</h2>
                    </ol>
                </nav>
            </div>
            <div class="content-card mb-4">
                <div class="row g-2 align-items-end">
                    <div class="col-lg-6">
                        <label for="classStudentSearch" class="form-label small text-muted mb-1">Search Student</label>
                        <input
                            type="search"
                            id="classStudentSearch"
                            class="form-control"
                            placeholder="Search by name, email, or student number"
                            value="${classStudentSearch}"
                            oninput="classStudentSearch = this.value; renderYears();"
                        >
                    </div>
                    <div class="col-lg-2 d-grid align-items-end">
                        <button
                            type="button"
                            class="btn btn-outline-secondary"
                            onclick="classStudentSearch = ''; const input = document.getElementById('classStudentSearch'); if (input) input.value = ''; renderYears();"
                        >
                            Clear Search
                        </button>
                    </div>
                    <div class="col-lg-4">
                        <div class="small text-muted">
                            Search to jump straight to a student instead of browsing by year and section.
                        </div>
                    </div>
                </div>
            </div>
            <div id="classes-content" class="row g-4"></div>
        `;
        renderYears();
    }

    function renderYears() {
        const content = document.getElementById('classes-content');
        const nav = document.getElementById('classes-nav').querySelector('.breadcrumb');
        const normalizedSearch = String(classStudentSearch || '').trim();

        if (normalizedSearch) {
            nav.innerHTML = `
                <li class="breadcrumb-item"><a href="#" onclick="classStudentSearch = ''; const input = document.getElementById('classStudentSearch'); if (input) input.value = ''; renderYears(); return false;">Year Levels</a></li>
                <li class="breadcrumb-item active">Search Results</li>
            `;

            const matchingStudents = getAllAssignableStudents().filter(student => studentMatchesNameSearch(student, normalizedSearch));

            if (matchingStudents.length === 0) {
                content.innerHTML = '<div class="col-12"><div class="content-card text-center text-muted">No students match the current search.</div></div>';
                return;
            }

            const rows = matchingStudents.map(student => `
                <tr data-student-id="${student.stud_id}">
                    <td>
                        <div class="fw-bold">${student.lastname}, ${student.firstname} ${student.mi ? student.mi + '.' : ''}</div>
                        <div class="small text-muted">${student.email}</div>
                    </td>
                    <td>Year ${student.year_level}</td>
                    <td>Section ${student.section}</td>
                    <td>${formatHours(student.completed_hours)} hrs</td>
                    <td>
                        <button class="btn btn-sm btn-info text-white" onclick="viewStudentDetails(${student.stud_id})">
                            <i class="fas fa-eye"></i> Details
                        </button>
                    </td>
                </tr>
            `).join('');

            content.innerHTML = `
                <div class="col-12">
                    <div class="content-card">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                            <h4 class="mb-0">Search Results</h4>
                            <span class="badge bg-primary rounded-pill">${matchingStudents.length} Students</span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Student</th>
                                        <th>Year</th>
                                        <th>Section</th>
                                        <th>Approved Hours</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>${rows}</tbody>
                            </table>
                        </div>
                    </div>
                </div>
            `;
            return;
        }

        nav.innerHTML = '<h2 class="mb-4">Year Levels</h2>';
        
        let html = '';
        const years = Object.keys(studentsData).sort();
        
        if (years.length === 0) {
            html = '<div class="col-12 text-center text-muted">No students found.</div>';
        } else {
            years.forEach(year => {
                const sectionCount = Object.keys(studentsData[year]).length;
                let studentCount = 0;
                Object.values(studentsData[year]).forEach(s => studentCount += s.length);
                
                html += `
                    <div class="col-md-6 col-lg-3">
                        <div class="year-card" onclick="renderSections('${year}')">
                            <div class="year-icon"><i class="fas fa-calendar-alt"></i></div>
                            <h4>Year ${year}</h4>
                            <p class="text-muted mb-0">${sectionCount} Sections</p>
                            <p class="text-muted small">${studentCount} Students</p>
                        </div>
                    </div>
                `;
            });
        }
        content.innerHTML = html;
    }

    function renderSections(year) {
        const content = document.getElementById('classes-content');
        const nav = document.getElementById('classes-nav').querySelector('.breadcrumb');
        
        nav.innerHTML = `
            <li class="breadcrumb-item"><a href="#" onclick="renderYears(); return false;">Year Levels</a></li>
            <li class="breadcrumb-item active">Year ${year}</li>
        `;

        let html = '';
        const sections = Object.keys(studentsData[year]).sort();
        
        sections.forEach(section => {
            const students = studentsData[year][section];
            html += `
                <div class="col-md-4 col-lg-3">
                    <div class="year-card" onclick="renderStudentList('${year}', '${section}')">
                        <div class="year-icon"><i class="fas fa-users"></i></div>
                        <h4>Section ${section}</h4>
                        <p class="text-muted mb-0">${students.length} Students</p>
                    </div>
                </div>
            `;
        });
        content.innerHTML = html;
    }

    function renderStudentList(year, section) {
        const content = document.getElementById('classes-content');
        const nav = document.getElementById('classes-nav').querySelector('.breadcrumb');
        
        nav.innerHTML = `
            <li class="breadcrumb-item"><a href="#" onclick="renderYears(); return false;">Year Levels</a></li>
            <li class="breadcrumb-item"><a href="#" onclick="renderSections('${year}'); return false;">Year ${year}</a></li>
            <li class="breadcrumb-item active">Section ${section}</li>
        `;

        const students = studentsData[year][section];
        let rows = '';
        students.forEach(s => {
            rows += `
                <tr>
                    <td>
                        <div class="d-flex align-items-center">
                            <div class="ms-2">
                                <div class="fw-bold">${s.lastname}, ${s.firstname} ${s.mi ? s.mi + '.' : ''}</div>
                                <div class="small text-muted">${s.email}</div>
                            </div>
                        </div>
                    </td>
                    <td>${formatHours(s.completed_hours)} hrs</td>
                    <td>
                        <button class="btn btn-sm btn-info text-white" onclick="viewStudentDetails(${s.stud_id})">
                            <i class="fas fa-eye"></i> Details
                        </button>
                    </td>
                </tr>
            `;
        });

        content.innerHTML = `
            <div class="col-12">
                <div class="content-card">
                    <h4 class="mb-4">Students List - Year ${year} Section ${section}</h4>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Hours Completed</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>${rows}</tbody>
                        </table>
                    </div>
                </div>
            </div>
        `;
    }
    
    function renderStudentDetailsModal(studentId) {
        let student = null;

        if (studentsData) {
            outerLoop:
            for (const year of Object.keys(studentsData)) {
                for (const sec of Object.keys(studentsData[year])) {
                    const found = studentsData[year][sec].find(s => s.stud_id == studentId);
                    if (found) {
                        student = found;
                        break outerLoop;
                    }
                }
            }
        }

        if (!student && advisoryStudentsData) {
             student = advisoryStudentsData.find(s => s.stud_id == studentId);
        }
        
        if (!student) {
            console.error('Student not found:', studentId);
            return;
        }

        document.getElementById('studentDetailsTitle').textContent = 'Student Details';

        let photoPath = student.photo;
        if (photoPath && !photoPath.startsWith('uploads/')) {
            photoPath = 'uploads/profile_photos/' + photoPath;
        }
        const photoUrl = (student.photo && student.photo !== '') ? '../' + photoPath : '../img/logo.png';
        
        const fullName = `${student.firstname} ${student.mi ? student.mi + '. ' : ''}${student.lastname}`;
        const arLink = `../student/documents/ar.php?stud_id=${studentId}`;
        const tasks = [...(allStudentTasks[studentId] || [])].sort((a, b) => new Date(b.created_at || 0) - new Date(a.created_at || 0));
        const accomplishments = [...(allStudentAccomps[studentId] || [])].sort((a, b) => {
            const left = new Date(b.work_date || b.created_at || 0).getTime();
            const right = new Date(a.work_date || a.created_at || 0).getTime();
            return left - right;
        });
        const approvedHours = calculateApprovedHoursFromAccomplishments(accomplishments);
        const requiredHoursLabel = Number(requiredHours || 0).toFixed(0);
        const remainingHoursLabel = formatHours(getRemainingHours(approvedHours));
        const hasReachedRequirement = approvedHours >= Number(requiredHours || 0);
        const nearRequirement = isNearRequiredHours(approvedHours);
        const pendingHours = accomplishments.reduce((total, accomplishment) => (
            String(accomplishment.status || '').toLowerCase() === 'pending'
                ? total + Number(accomplishment.hours || 0)
                : total
        ), 0);
        const rejectedHours = accomplishments.reduce((total, accomplishment) => (
            String(accomplishment.status || '').toLowerCase() === 'rejected'
                ? total + Number(accomplishment.hours || 0)
                : total
        ), 0);
        const completedTaskCount = tasks.filter(task => String(task.status || '').toLowerCase() === 'completed').length;
        const pendingTaskCount = tasks.filter(task => String(task.status || '').toLowerCase() === 'pending').length;
        const progressPercent = Math.min((approvedHours / Number(requiredHours || 1)) * 100, 100).toFixed(1);
        const progressNote = hasReachedRequirement
            ? `Student has reached the ${requiredHoursLabel}-hour requirement.`
            : (nearRequirement
                ? `Only ${remainingHoursLabel} hrs remain before the ${requiredHoursLabel}-hour requirement is met.`
                : `${remainingHoursLabel} hrs remain before the ${requiredHoursLabel}-hour requirement is met.`);
        const progressNoteClass = hasReachedRequirement
            ? 'text-success fw-semibold'
            : (nearRequirement ? 'text-warning fw-semibold' : 'text-muted');

        const exactBreakdownRows = accomplishments.map(accomplishment => `
            <tr>
                <td>${formatDueDate(accomplishment.work_date)}</td>
                <td>
                    <div class="fw-semibold">${accomplishment.activity || 'No activity provided.'}</div>
                    <div class="small text-muted">${accomplishment.time_start || 'N/A'} - ${accomplishment.time_end || 'N/A'}</div>
                </td>
                <td class="text-nowrap">${formatHours(accomplishment.hours)} hrs</td>
            </tr>
        `);

        const tasksRows = tasks.map(task => `
            <tr>
                <td>
                    <div class="fw-bold">${task.title}</div>
                    <div class="small text-muted">${task.description || 'No description provided.'}</div>
                </td>
                <td>${formatDueDate(task.due_date)}</td>
                <td>${formatDateTime(task.created_at)}</td>
                <td><span class="badge ${getStatusBadgeClass(task.status)}">${task.status}</span></td>
            </tr>
        `);

        const accomplishmentRows = accomplishments.map(accomplishment => `
            <tr>
                <td>${formatDueDate(accomplishment.work_date)}</td>
                <td>${accomplishment.time_start || 'N/A'} - ${accomplishment.time_end || 'N/A'}</td>
                <td>${formatHours(accomplishment.hours)} hrs</td>
                <td><span class="badge ${getStatusBadgeClass(accomplishment.status)}">${accomplishment.status}</span></td>
                <td>${accomplishment.activity || 'No activity provided.'}</td>
                <td>${formatDateTime(accomplishment.created_at)}</td>
            </tr>
        `);

        const modalBody = `
            <div class="container-fluid p-0">
                <div class="row g-4 align-items-center mb-4">
                    <div class="col-auto">
                        <img src="${photoUrl}" alt="Profile" class="rounded-circle border border-3 border-primary" style="width: 100px; height: 100px; object-fit: cover;" onerror="this.onerror=null;this.src='../img/logo.png'"> 
                    </div>
                    <div class="col" style="min-width: 0;">
                        <h3 class="fw-bold text-dark mb-1 text-truncate" title="${fullName}">${fullName}</h3>
                        <p class="text-muted mb-2 text-truncate" title="${student.email}"><i class="fas fa-envelope me-1"></i> ${student.email}</p>
                        <div class="d-flex flex-wrap gap-2">
                            <span class="badge bg-success fs-6 rounded-pill">
                                <i class="fas fa-hourglass-half me-1"></i> Approved: ${formatHours(approvedHours)} hrs
                            </span>
                            <span class="badge bg-warning text-dark fs-6 rounded-pill">
                                Pending: ${formatHours(pendingHours)} hrs
                            </span>
                            <span class="badge bg-danger fs-6 rounded-pill">
                                Rejected: ${formatHours(rejectedHours)} hrs
                            </span>
                        </div>
                    </div>
                    <div class="col-md-auto">
                        <a href="${arLink}" target="_blank" class="btn btn-primary btn-sm px-3 shadow-sm">
                            <i class="fas fa-file-alt me-2"></i> View Accomplishment Report
                        </a>
                    </div>
                </div>

                <div class="content-card mb-4">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                        <h6 class="text-primary fw-bold mb-0">Approved Hours Progress</h6>
                        <span class="small text-muted">${formatHours(approvedHours)} / ${requiredHoursLabel} hrs</span>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar bg-success" role="progressbar" style="width: ${progressPercent}%"></div>
                    </div>
                    <div class="d-flex flex-wrap gap-3 small text-muted mt-2">
                        <span>${completedTaskCount} completed task${completedTaskCount === 1 ? '' : 's'}</span>
                        <span>${pendingTaskCount} pending task${pendingTaskCount === 1 ? '' : 's'}</span>
                        <span>${hasReachedRequirement ? 'Requirement reached' : `${remainingHoursLabel} hrs remaining`}</span>
                        <span>Section ${student.section} | Year ${student.year_level}</span>
                    </div>
                    <div class="small ${progressNoteClass} mt-2">${progressNote}</div>
                </div>

                <div class="row g-4">
                    <div class="col-lg-7">
                        ${renderModalTableCard({
                            key: 'studentDetailsTasks',
                            title: 'Assigned Tasks',
                            titleClass: 'text-primary',
                            badgeClass: 'bg-primary',
                            badgeLabel: `${tasks.length} Total`,
                            headersHtml: `
                                <th>Task</th>
                                <th>Due Date</th>
                                <th>Assigned</th>
                                <th>Status</th>
                            `,
                            rows: tasksRows,
                            emptyRowHtml: `
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-3">No task assignments found for this student.</td>
                                </tr>
                            `,
                            emptyLabel: 'No task assignments found for this student.',
                            wrapperClass: 'content-card h-100'
                        })}
                    </div>
                    <div class="col-lg-5">
                        ${renderModalTableCard({
                            key: 'studentDetailsBreakdown',
                            title: 'Exact Hours Breakdown',
                            titleClass: 'text-success',
                            badgeClass: 'bg-success',
                            badgeLabel: `${accomplishments.length} Accomplishment${accomplishments.length === 1 ? '' : 's'}`,
                            headersHtml: `
                                <th>Date</th>
                                <th>Activity</th>
                                <th>Hours</th>
                            `,
                            rows: exactBreakdownRows,
                            emptyRowHtml: `
                                <tr>
                                    <td colspan="3" class="text-center text-muted py-3">No accomplishment submissions yet.</td>
                                </tr>
                            `,
                            emptyLabel: 'No accomplishment submissions yet.',
                            wrapperClass: 'content-card h-100'
                        })}
                    </div>
                </div>

                ${renderModalTableCard({
                    key: 'studentDetailsHistory',
                    title: 'Accomplishment History',
                    titleClass: 'text-success',
                    badgeClass: 'bg-secondary',
                    badgeLabel: 'Includes status and submission timestamps',
                    headersHtml: `
                        <th>Work Date</th>
                        <th>Time</th>
                        <th>Hours</th>
                        <th>Status</th>
                        <th>Activity</th>
                        <th>Submitted</th>
                    `,
                    rows: accomplishmentRows,
                    emptyRowHtml: `
                        <tr>
                            <td colspan="6" class="text-center text-muted py-3">No accomplishment history found for this student.</td>
                        </tr>
                    `,
                    emptyLabel: 'No accomplishment history found for this student.',
                    wrapperClass: 'content-card mt-4'
                })}
            </div>
        `;
        
        const modalContainer = document.querySelector('#studentDetailsModal .modal-body');
        modalContainer.innerHTML = modalBody;
    }

    function viewStudentDetails(studentId) {
        activeStudentDetailsId = studentId;
        resetModalTablePages([
            'studentDetailsTasks',
            'studentDetailsBreakdown',
            'studentDetailsHistory'
        ]);
        renderStudentDetailsModal(studentId);

        const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('studentDetailsModal'));
        modal.show();
    }

    function renderTasks(container) {
        // Populate Student Selection in Create Modal first
        populateStudentSelection();

        // --- FILTER LOGIC ---
        const months = new Set();
        const years = new Set();
        
        myTasks.forEach(t => {
            const d = new Date(t.created_at);
            const y = d.getFullYear();
            const m = d.getMonth() + 1;
            years.add(y);
            months.add(`${y}-${String(m).padStart(2, '0')}`);
        });

        const sortedYears = Array.from(years).sort((a,b) => b - a);
        const sortedMonths = Array.from(months).sort().reverse();

        let filteredTasks = myTasks;
        
        if (taskMonthFilter === 'latest') {
            if (sortedMonths.length > 0) {
                const latest = sortedMonths[0]; 
                filteredTasks = myTasks.filter(t => t.created_at.startsWith(latest));
            }
        } else if (taskMonthFilter === 'all') {
            filteredTasks = myTasks;
        } else if (taskMonthFilter.startsWith('Y-')) {
            const year = taskMonthFilter.split('-')[1];
            filteredTasks = myTasks.filter(t => new Date(t.created_at).getFullYear() == year);
        } else if (taskMonthFilter.startsWith('M-')) {
            const ym = taskMonthFilter.substring(2); 
            filteredTasks = myTasks.filter(t => t.created_at.startsWith(ym));
        }

        let optionsHtml = `
            <option value="latest" ${taskMonthFilter === 'latest' ? 'selected' : ''}>Latest Month</option>
            <option value="all" ${taskMonthFilter === 'all' ? 'selected' : ''}>All Tasks</option>
            <option disabled>â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€</option>
        `;

        sortedYears.forEach(y => {
            optionsHtml += `<option value="Y-${y}" ${taskMonthFilter === `Y-${y}` ? 'selected' : ''}>Year ${y}</option>`;
            const yearMonths = sortedMonths.filter(m => m.startsWith(`${y}-`));
            yearMonths.forEach(ym => {
                const [year, month] = ym.split('-');
                const date = new Date(parseInt(year), parseInt(month)-1, 1);
                const monthName = date.toLocaleString('default', { month: 'long' });
                optionsHtml += `<option value="M-${ym}" ${taskMonthFilter === `M-${ym}` ? 'selected' : ''}>&nbsp;&nbsp;${monthName} ${year}</option>`;
            });
        });
        
        let tasksHtml = '';
        if (filteredTasks.length === 0) {
            tasksHtml = '<div class="text-center text-muted p-4">No tasks found for this period.</div>';
        } else {
            tasksHtml = `
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Task Title</th>
                                <th>Description</th>
                                <th>Assigned Students</th>
                                <th>Due Date</th>
                                <th>Created At</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            filteredTasks.forEach(task => {
                // Formatting date nicely
                const d = new Date(task.created_at);
                const dateStr = d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                const assignments = taskAssignments[task.task_id] || [];
                const assignedCount = assignments.length;
                const completedCount = assignments.filter(assignment => String(assignment.assignment_status || '').toLowerCase() === 'completed').length;
                const inProgressCount = assignments.filter(assignment => String(assignment.assignment_status || '').toLowerCase() === 'in progress').length;
                const pendingCount = assignments.filter(assignment => String(assignment.assignment_status || '').toLowerCase() === 'pending').length;
                const incompleteCount = Math.max(assignedCount - completedCount, 0);
                const dueDateLabel = formatDueDate(task.due_date);
                
                    tasksHtml += `
                        <tr data-task-id="${task.task_id}">
                            <td class="fw-bold text-primary">${task.title}</td>
                        <td><div class="text-truncate" style="max-width: 300px;" title="${task.description}">${task.description}</div></td>
                        <td>
                            <div class="fw-semibold">${assignedCount} student${assignedCount === 1 ? '' : 's'}</div>
                            <div class="small text-muted">${completedCount} completed â€¢ ${inProgressCount} in progress â€¢ ${pendingCount} pending</div>
                        </td>
                        <td><span class="badge bg-light text-dark border">${dueDateLabel}</span></td>
                        <td><small class="text-muted">${dateStr}</small></td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-info me-1" title="View ${assignedCount} assigned student${assignedCount === 1 ? '' : 's'}" onclick="openTaskDetailsModal(${task.task_id})">
                                <i class="fas fa-users me-1"></i>${assignedCount}
                            </button>
                            <form method="POST" action="${currentDashboardPath}" class="d-inline" data-loading-label="Sending reminders..." onsubmit="return confirm('Send reminders to students who have not yet completed this task?');">
                                <input type="hidden" name="task_id" value="${task.task_id}">
                                <button class="btn btn-sm btn-outline-warning me-1" type="submit" name="remind_task" title="${incompleteCount > 0 ? `Send reminders to ${incompleteCount} student${incompleteCount === 1 ? '' : 's'}` : 'Everyone has completed this task'}" ${incompleteCount === 0 ? 'disabled' : ''}>
                                    <i class="fas fa-bell"></i>
                                </button>
                            </form>
                            <form method="POST" action="${currentDashboardPath}" class="d-inline" data-loading-label="Duplicating task..." onsubmit="return confirm('Create a duplicate of this task and assign it to the same students?');">
                                <input type="hidden" name="task_id" value="${task.task_id}">
                                <button class="btn btn-sm btn-outline-primary me-1" type="submit" name="duplicate_task" title="Duplicate task">
                                    <i class="fas fa-copy"></i>
                                </button>
                            </form>
                            <button class="btn btn-sm btn-outline-secondary me-1" onclick="openEditTaskModal(${task.task_id})">
                                <i class="fas fa-edit"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });
            
            tasksHtml += `
                        </tbody>
                    </table>
                </div>
            `;
        }
        
        container.innerHTML = `
        <br>
        <br>
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Task Management</h2>
                
                <div class="d-flex gap-2">
                    <select class="form-select w-auto" onchange="taskMonthFilter = this.value; renderTasks(document.getElementById('main-content'))">
                        ${optionsHtml}
                    </select>
                    <button class="btn btn-outline-secondary" type="button" onclick="window.location.href='export_records.php?type=task_assignments'">
                        <i class="fas fa-file-csv me-1"></i> Export CSV
                    </button>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createTaskModal">
                        <i class="fas fa-plus"></i> New Task
                    </button>
                </div>
            </div>
            
            <div class="content-card">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4 class="mb-0">Your Created Tasks</h4>
                    <span class="badge bg-primary rounded-pill">${filteredTasks.length} Tasks</span>
                </div>
                <div class="alert alert-info mb-4">
                    <i class="fas fa-info-circle me-2"></i> These are the tasks you have created. Assign them to students using the "New Task" button.
                </div>
                ${tasksHtml}
            </div>
        `;
        attachLoadingStateToForms(container);
    }

    function openEditTaskModal(taskId) {
        const task = myTasks.find(t => t.task_id == taskId);
        if(!task) return;
        
        document.getElementById('edit_task_id').value = task.task_id;
        document.getElementById('edit_task_title').value = task.title;
        document.getElementById('edit_task_desc').value = task.description;
        document.getElementById('edit_task_duration').value = task.duration || "";
        document.getElementById('edit_task_due_date').value = task.due_date || "";
        
        const modal = new bootstrap.Modal(document.getElementById('editTaskModal'));
        modal.show();
    }

    function openTaskDetailsModal(taskId) {
        activeTaskDetailsId = taskId;
        taskAssignmentSearch = '';
        taskAssignmentYearFilter = '';
        taskAssignmentSectionFilter = '';
        taskAssignmentStatusFilter = 'all';
        resetModalTablePage('taskAssignments');

        const searchInput = document.getElementById('taskAssignmentSearch');
        const yearFilter = document.getElementById('taskAssignmentYearFilter');
        const sectionFilter = document.getElementById('taskAssignmentSectionFilter');
        const statusFilter = document.getElementById('taskAssignmentStatusFilter');

        if (searchInput) searchInput.value = '';
        if (yearFilter) yearFilter.value = '';
        if (sectionFilter) sectionFilter.value = '';
        if (statusFilter) statusFilter.value = 'all';

        renderTaskAssignmentsList();
        const modal = new bootstrap.Modal(document.getElementById('taskDetailsModal'));
        modal.show();
    }

    function populateStudentSelection() {
        const allStudents = getAllAssignableStudents();
        const filteredStudents = allStudents.filter(studentMatchesSelectionFilters);
        const accordion = document.getElementById('studentAccordion');
        if (!accordion) return;

        const groupedStudents = {};
        let html = '';
        let index = 0;

        updateStudentSelectionFilterOptions(allStudents);

        filteredStudents.forEach(student => {
            const year = String(student.year_level || 'N/A');
            const section = String(student.section || 'Unassigned');

            if (!groupedStudents[year]) {
                groupedStudents[year] = {};
            }

            if (!groupedStudents[year][section]) {
                groupedStudents[year][section] = [];
            }

            groupedStudents[year][section].push(student);
        });

        Object.keys(groupedStudents).sort((a, b) => Number(a) - Number(b)).forEach(year => {
            Object.keys(groupedStudents[year]).sort((a, b) => String(a).localeCompare(String(b), undefined, {
                numeric: true,
                sensitivity: 'base'
            })).forEach(sec => {
                const students = groupedStudents[year][sec];
                const id = `collapse${index}`;
                const allChecked = students.length > 0 && students.every(student => selectedStudentIds.has(String(student.stud_id)));
                
                html += `
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#${id}">
                                Year ${year} - Section ${sec}
                                <span class="badge bg-primary ms-2">${students.length}</span>
                            </button>
                        </h2>
                        <div id="${id}" class="accordion-collapse collapse">
                            <div class="accordion-body">
                                <div class="form-check mb-2 pb-2 border-bottom">
                                    <input class="form-check-input" type="checkbox" id="selectAll_${index}" onchange="toggleSection(this, '${id}')" ${allChecked ? 'checked' : ''}>
                                    <label class="form-check-label fw-bold" for="selectAll_${index}">
                                        Select All Visible Students
                                    </label>
                                </div>
                `;
                
                students.forEach(s => {
                    const studentId = String(s.stud_id);
                    const checked = selectedStudentIds.has(studentId) ? 'checked' : '';

                    html += `
                        <div class="form-check mb-2">
                            <input class="form-check-input student-selection-checkbox" type="checkbox" value="${studentId}" id="check_${studentId}" onchange="handleStudentAssignmentToggle(this)" ${checked}>
                            <label class="form-check-label" for="check_${s.stud_id}">
                                <div class="fw-semibold">${formatStudentDisplayName(s)}</div>
                                <div class="small text-muted">${s.email || 'No email available'}</div>
                            </label>
                        </div>
                    `;
                });
                
                html += `
                            </div>
                        </div>
                    </div>
                `;
                index += 1;
            });
        });
        accordion.innerHTML = html || '<div class="text-center text-muted py-4">No students match the current filters.</div>';
        syncSelectedStudentInputs();
        updateStudentSelectionSummary(allStudents.length, filteredStudents.length);
    }

    function toggleSection(source, sectionId) {
        const checkboxes = document.querySelectorAll(`#${sectionId} .student-selection-checkbox`);

        checkboxes.forEach(checkbox => {
            checkbox.checked = source.checked;

            if (source.checked) {
                selectedStudentIds.add(String(checkbox.value));
            } else {
                selectedStudentIds.delete(String(checkbox.value));
            }
        });

        syncSelectedStudentInputs();

        const allStudents = getAllAssignableStudents();
        const filteredStudents = allStudents.filter(studentMatchesSelectionFilters);
        updateStudentSelectionSummary(allStudents.length, filteredStudents.length);
    }

    document.addEventListener('DOMContentLoaded', function() {
        attachTaskFormValidation();
        attachLoadingStateToForms();
        document.getElementById('confirmEndSessionBtn')?.addEventListener('click', confirmEndSession);
        document.getElementById('createTaskModal')?.addEventListener('shown.bs.modal', clearStudentSelectionFilters);

        const savedView = sessionStorage.getItem('instructorActiveView') || 'dashboard';
        showView(savedView);

        if (initialFlashMessage) {
            showToast(initialFlashMessage, initialFlashType || inferToastType(initialFlashMessage));
            clearFlashParamsFromUrl();
        }
    });

    // RSS ACTIVITY TYPE SELECTION LOGIC
    document.addEventListener('DOMContentLoaded', function() {
        const mainCategorySelect = document.getElementById('mainCategorySelect');
        const subCategorySelects = document.querySelectorAll('.sub-category-select');
        const finalTaskTitle = document.getElementById('finalTaskTitle');
        const selectedTaskName = document.getElementById('selectedTaskName');
        const selectedTaskDisplay = document.getElementById('selectedTaskDisplay');
        const clearTaskBtn = document.getElementById('clearTaskBtn');

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
                finalTaskTitle.value = '';
                selectedTaskDisplay.classList.add('d-none');
            });
        }

        subCategorySelects.forEach(select => {
            select.addEventListener('change', function() {
                const task = this.value;
                if (task) {
                    finalTaskTitle.value = task;
                    selectedTaskName.textContent = task;
                    selectedTaskDisplay.classList.remove('d-none');
                } else {
                    finalTaskTitle.value = '';
                    selectedTaskDisplay.classList.add('d-none');
                }
            });
        });

        if (clearTaskBtn) {
            clearTaskBtn.addEventListener('click', () => {
                mainCategorySelect.value = '';
                subCategorySelects.forEach(select => {
                    select.classList.add('d-none');
                    select.value = '';
                });
                finalTaskTitle.value = '';
                selectedTaskDisplay.classList.add('d-none');
            });
        }

        const loader = document.getElementById('rserve-page-loader');
        if (loader) {
            window.addEventListener('load', function() {
                loader.classList.add('rserve-page-loader--hide');
                window.setTimeout(() => {
                    if (loader && loader.parentNode) loader.parentNode.removeChild(loader);
                }, 420);
            });
        }
    });
</script>
</body>
</html>
