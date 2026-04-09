<?php
//instructor
session_start();
require "dbconnect.php";
include "../student/check_expiration.php";
require_once "../send_email.php";

/* -------------------  SESSION CHECK ------------------- */
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Instructor' || $_SESSION['department_id'] != 2) {
    header("Location: ../home2.php");
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
    $dept_id = 2; // Technology

    // Check if section is already taken by ANOTHER instructor
    $check_stmt = $conn->prepare("SELECT instructor_id FROM section_advisers WHERE section = ? AND department_id = ? AND instructor_id != ?");
    $check_stmt->bind_param("sii", $new_section, $dept_id, $inst_id);
    $check_stmt->execute();
    $check_res = $check_stmt->get_result();

    if ($check_res && $check_res->num_rows > 0) {
        header("Location: " . $_SERVER['PHP_SELF'] . "?msg=" . urlencode("❌ Error: Section $new_section already has an adviser. Only 1 adviser per section is allowed."));
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

        // ✅ Automatic Linking: Link all students in this section/department to this instructor
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
                $msg = "✅ Password changed successfully!";
            } else {
                $msg = "❌ Error updating password.";
            }
            $up->close();
        } else {
            $msg = "❌ New passwords do not match.";
        }
    } else {
        $msg = "❌ Current password is incorrect.";
    }
    header("Location: " . $_SERVER['PHP_SELF'] . "?msg=" . urlencode($msg));
    exit;
}

/* -------------------  CREATE TASK ------------------- */
if (isset($_POST['create_task'])) {
    $title       = trim($_POST['title']);
    $description = trim($_POST['description']);
    $duration    = $_POST['duration'];
    $selected    = $_POST['send_to'] ?? [];
    $fixed_dept_id = 2;
    $created_by_student = 0;
    $task_message = "Task created successfully" . (!empty($selected) ? " and sent!" : "!");

    try {
        $conn->begin_transaction();

        $tstmt = $conn->prepare("
            INSERT INTO tasks (title, description, duration, instructor_id, department_id, created_by_student)
            VALUES (?,?,?,?,?,?)
        ");
        if (!$tstmt) {
            throw new RuntimeException($conn->error);
        }

        $tstmt->bind_param("sssiii", $title, $description, $duration, $inst_id, $fixed_dept_id, $created_by_student);
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
                    $body = "Hello $name,\n\nA new task has been assigned to you by your adviser ($fullname).\n\nTask: $title\nDescription: $description\n\nPlease log in to your dashboard to view details.";
                    sendEmail($to, $name, $subject, $body);
                }
            }

            $estmt->close();
        } else {
            error_log("Task email prepare failed for instructor {$inst_id}: " . $conn->error);
        }
    }

    header("Location: ".$_SERVER['PHP_SELF']."?msg=".urlencode($task_message));
    exit;
}

/* -------------------  EDIT TASK ------------------- */
if (isset($_POST['edit_task'])) {
    $task_id = intval($_POST['task_id']);
    $new_title = trim($_POST['edit_title']);
    $new_desc  = trim($_POST['edit_description']);
    $new_duration = $_POST['edit_duration'];

    $stmt = $conn->prepare("UPDATE tasks SET title=?, description=?, duration=? WHERE task_id=? AND instructor_id=?");
    $stmt->bind_param("sssii", $new_title, $new_desc, $new_duration, $task_id, $inst_id);
    $stmt->execute();
    $stmt->close();

    header("Location: ".$_SERVER['PHP_SELF']."?msg=".urlencode("Task updated successfully!"));
    exit;
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

    header("Location: ".$_SERVER['PHP_SELF']."?msg=".urlencode("Task hidden successfully!"));
    exit;
}

/* -------------------  APPROVE/REJECT ORGANIZATION REQUESTS ------------------- */
// Removed as per user request

/* -------------------  APPROVE/REJECT ORGANIZATION ACCOMPLISHMENTS ------------------- */
// Removed as per user request

/* -------------------  APPROVE/REJECT STUDENT ACCOMPLISHMENTS (ADVISORY) ------------------- */
if (isset($_POST['approve_accomp'])) {
    $ar_id = intval($_POST['ar_id']);
    // Update status AND set the approver_id to current instructor
    $stmt = $conn->prepare("UPDATE accomplishment_reports SET status='Approved', approver_id=? WHERE id=?");
    $stmt->bind_param("ii", $inst_id, $ar_id);
    $stmt->execute();
    $stmt->close();
    header("Location: ".$_SERVER['PHP_SELF']."?msg=".urlencode("Accomplishment approved!"));
    exit;
}

if (isset($_POST['reject_accomp'])) {
    $ar_id = intval($_POST['ar_id']);
    $stmt = $conn->prepare("UPDATE accomplishment_reports SET status='Rejected' WHERE id=?");
    $stmt->bind_param("i", $ar_id);
    $stmt->execute();
    $stmt->close();
    header("Location: ".$_SERVER['PHP_SELF']."?msg=".urlencode("Accomplishment rejected!"));
    exit;
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
WHERE s.department_id = 2
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
$stmt_adv = $conn->prepare("SELECT section FROM section_advisers WHERE instructor_id = ? AND department_id = 2");
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
          s.stud_id, s.firstname, s.mi, s.lastname, s.email, s.photo,
          COALESCE(s.year_level, 1) AS year_level,
          COALESCE(s.section, 'A') AS section,
          COALESCE(ar_sum.hours, 0) AS completed_hours
        FROM students s
        LEFT JOIN (
            SELECT student_id, SUM(hours) as hours FROM accomplishment_reports WHERE status = 'Approved' GROUP BY student_id
        ) ar_sum ON s.stud_id = ar_sum.student_id
        WHERE s.department_id = 2 AND COALESCE(s.section, 'A') IN ($sections_in)
        ORDER BY s.lastname
    ";
    $res_adv_stud = $conn->query($sql_adv_stud);
    if ($res_adv_stud) {
        while ($row = $res_adv_stud->fetch_assoc()) {
            $my_advisory_students[] = $row;
        }
    }
}

/* -------------------  FETCH PENDING SECTION REQUESTS ------------------- */
$pendingSectionRequests = [];
if (!empty($advisory_sections)) {
    $req_sql = "
        SELECT sr.request_id, sr.student_id, s.year_level, sr.section, sr.created_at,
               s.firstname, s.lastname, s.mi, s.student_number, s.photo,
               m.id as master_match_id, m.section as master_section, m.year_level as master_year
        FROM section_requests sr
        JOIN students s ON sr.student_id = s.stud_id
        LEFT JOIN rss_enrollments e ON s.stud_id = e.student_id
        LEFT JOIN master_students m ON (
            (m.student_id_number = s.student_number AND s.student_number IS NOT NULL AND s.student_number != '') OR 
            (m.lastname = s.lastname AND m.firstname = s.firstname AND m.birthdate = e.birth_date)
        )
        WHERE sr.adviser_id = ? AND sr.status = 'Pending'
        ORDER BY sr.created_at ASC
    ";
    $stmt_req = $conn->prepare($req_sql);
    if ($stmt_req) {
        $stmt_req->bind_param("i", $inst_id);
        $stmt_req->execute();
        $res_req = $stmt_req->get_result();
        while ($row = $res_req->fetch_assoc()) {
            $pendingSectionRequests[] = $row;
        }
        $stmt_req->close();
    } else {
        // Fallback or log error if needed
        error_log("Prepare failed: " . $conn->error);
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
        WHERE s.department_id = 2 
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
        student_id, 
        work_date, 
        activity, 
        hours, 
        status 
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
         JOIN accomplishment_reports ar ON ar.student_id = st.student_id AND ar.activity LIKE CONCAT('%[TaskID:', st.task_id, ']%') 
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
        st.task_id,
        st.student_id,
        st.status as assignment_status,
        s.firstname,
        s.lastname,
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

/* -------------------  FETCH PENDING ORGANIZATIONS - REMOVED ------------------- */
/* -------------------  FETCH ORGANIZATION STATISTICS - REMOVED ------------------- */
/* -------------------  FETCH PENDING ORGANIZATION ACCOMPLISHMENTS - REMOVED ------------------- */
/* -------------------  GROUP DATA FOR MODALS - REMOVED ------------------- */

// Handle Mark All Read
if (isset($_POST['mark_all_read'])) {
    $conn->query("UPDATE instructor_notifications SET is_read = TRUE WHERE instructor_id = $inst_id");
    exit;
}

// Fetch Notifications
$notifs_query = $conn->query("SELECT * FROM instructor_notifications WHERE instructor_id = $inst_id AND type != 'org_req' ORDER BY created_at DESC");
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
    <title>Adviser Dashboard - College of Technology</title>
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
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
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

        /* Keep the Create Task modal usable on shorter viewports/live hosts */
        #createTaskModal .modal-dialog {
            margin: 1rem auto;
        }

        #createTaskModal .modal-content {
            max-height: calc(100vh - 2rem);
        }

        #createTaskModal .modal-body {
            overflow-y: auto;
            max-height: calc(100vh - 210px);
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
            <span class="text-white me-2 fw-bold" style="font-size: 0.9rem;">Instructor</span>
            <img src="<?php echo htmlspecialchars($photo); ?>" alt="Profile" 
                 class="rounded-circle border border-2 border-white" 
                 style="width: 35px; height: 35px; object-fit: cover;">
        </div>
    </div>
    <div class="mobile-header-nav">
        <a href="#" class="nav-item active" onclick="showView('dashboard', this)">
            <i class="fas fa-th-large"></i>
            <span>Dashboard</span>
        </a>
        <a href="#" class="nav-item position-relative" onclick="showView('advisory', this)">
            <i class="fas fa-chalkboard-teacher"></i>
            <span>Advisory</span>
            <?php if ($pending_count > 0): ?>
                <span class="position-absolute top-0 start-50 translate-middle badge rounded-pill bg-warning text-dark" style="font-size: 0.5rem; transform: translate(10px, 5px) !important;">
                    <?php echo $pending_count; ?>
                </span>
            <?php endif; ?>
        </a>
        <a href="#" class="nav-item" onclick="showView('classes', this)">
            <i class="fas fa-users"></i>
            <span>Classes</span>
        </a>
        <a href="#" class="nav-item" onclick="showView('tasks', this)">
            <i class="fas fa-tasks"></i>
            <span>Tasks</span>
        </a>
        <a href="#" class="nav-item position-relative" onclick="showView('notifications', this)">
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
         <div class="sidebar-heading">
            <i class="fas"></i> <img src="../img/logo.png" alt="RServeS Logo" style="width: 40px; height: auto;"> RServeS
        </div>
        <div class="list-group list-group-flush">
            <a href="#" class="list-group-item list-group-item-action active" onclick="showView('dashboard', this)">
                <i class="fas fa-th-large"></i> Dashboard
            </a>
            <a href="#" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" onclick="showView('advisory', this)">
                <span><i class="fas fa-chalkboard-teacher"></i> Advisory</span>
                <?php if ($pending_count > 0): ?>
                    <span class="badge bg-warning text-dark rounded-pill"><?php echo $pending_count; ?></span>
                <?php endif; ?>
            </a>
            <a href="#" class="list-group-item list-group-item-action" onclick="showView('classes', this)">
                <i class="fas fa-users"></i> Classes
            </a>
            <a href="#" class="list-group-item list-group-item-action" onclick="showView('tasks', this)">
                <i class="fas fa-tasks"></i> Tasks
            </a>
            <a href="#" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" onclick="showView('notifications', this)">
                <span><i class="fas fa-bell"></i> Notifications</span>
                <?php if ($unread_notifs > 0): ?>
                    <span class="badge bg-danger rounded-pill"><?php echo $unread_notifs; ?></span>
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
        <nav class="navbar navbar-expand-lg navbar-light">

            
            <div class="ms-auto d-flex align-items-center">
                <div class="me-3 text-end d-none d-md-block text-white">
                    <div class="fw-bold"><?php echo htmlspecialchars($fullname); ?></div>
                    <small>Adviser</small>
                </div>
                <img src="<?php echo htmlspecialchars($photo); ?>" alt="Profile" class="profile-img-nav" data-bs-toggle="modal" data-bs-target="#profileModal" style="cursor: pointer;">
            </div>
        </nav>

        <div class="container-fluid" id="main-content">
            <?php if (isset($_GET['msg'])): ?>
                <div class="alert alert-info alert-dismissible fade show mt-3" role="alert">
                    <?php echo htmlspecialchars($_GET['msg']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
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
                            <input type="password" name="current_password" class="form-control form-control-sm" required id="inst_current_password">
                            <i class="fas fa-eye-slash password-toggle-icon" onclick="togglePasswordVisibility(this, 'inst_current_password')"></i>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">New Password</label>
                        <div class="password-container">
                            <input type="password" name="new_password" class="form-control form-control-sm" required id="inst_new_password">
                            <i class="fas fa-eye-slash password-toggle-icon" onclick="togglePasswordVisibility(this, 'inst_new_password')"></i>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Confirm New Password</label>
                        <div class="password-container">
                            <input type="password" name="confirm_password" class="form-control form-control-sm" required id="inst_confirm_password">
                            <i class="fas fa-eye-slash password-toggle-icon" onclick="togglePasswordVisibility(this, 'inst_confirm_password')"></i>
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
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form action="<?php echo $_SERVER['PHP_SELF']; ?>" method="POST">
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
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3" required></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Assign To Students</label>
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
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="<?php echo $_SERVER['PHP_SELF']; ?>" method="POST">
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
    <div class="modal-dialog modal-lg modal-dialog-centered">
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

<!-- Organization Details Modal Removed -->

<!-- Import Master List Modal -->
<div class="modal fade" id="importMasterModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Import Master Student List</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form action="import_master_list.php" method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label for="csv_file" class="form-label">Upload CSV File</label>
                        <input class="form-control" type="file" id="csv_file" name="csv_file" accept=".csv" required>
                        <div class="form-text">
                            Expected columns: Student ID, Last Name, First Name, Middle Name, Birthdate (YYYY-MM-DD), Course, Year, Section
                        </div>
                    </div>
                    <div class="alert alert-info small">
                        <i class="fas fa-info-circle me-1"></i> This list is used to verify student enrollments.
                    </div>
                    <div class="d-flex justify-content-end">
                        <button type="submit" name="import_master" class="btn btn-primary">Upload & Sync</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Task Details Modal -->
<div class="modal fade" id="taskDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="taskDetailsTitle">Assigned Students</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Student Name</th>
                                <th>Section</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="taskAssignmentsList">
                            <!-- Populated via JS -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Data from PHP
    const studentsData = <?php echo json_encode($students_by_year); ?>;
    const advisoryStudentsData = <?php echo json_encode($my_advisory_students); ?>;
    const pendingSectionRequests = <?php echo json_encode($pendingSectionRequests); ?>;
    const pendingAdvisoryAccomps = <?php echo json_encode($pendingAdvisoryAccomps); ?>;
    const notifications = <?php echo json_encode($notifications); ?>;
    const allStudentTasks = <?php echo json_encode($allStudentTasks); ?>;
    const allStudentAccomps = <?php echo json_encode($allStudentAccomps); ?>;
    const myTasks = <?php echo json_encode($myTasks); ?>;
    const taskAssignments = <?php echo json_encode($taskAssignments); ?>;
    let taskMonthFilter = 'latest';
    


    // View Management
    function showView(viewName, linkElement) {
        // Update Sidebar Active State
        if(linkElement) {
            document.querySelectorAll('.list-group-item, .mobile-header-nav .nav-item').forEach(el => el.classList.remove('active'));
            linkElement.classList.add('active');
        }

        const container = document.getElementById('main-content');
        container.innerHTML = ''; // Clear content

        if(viewName === 'dashboard') renderDashboard(container);
        else if(viewName === 'classes') renderClasses(container);
        else if(viewName === 'advisory') renderAdvisory(container);
        else if(viewName === 'tasks') renderTasks(container);
        else if(viewName === 'notifications') renderNotifications(container);
    }

    function renderAdvisory(container) {
        let html = `
        <br>
        <br>
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>My Advisory Class</h2>
                <div>
                     <button class="btn btn-outline-success me-2" data-bs-toggle="modal" data-bs-target="#importMasterModal">
                        <i class="fas fa-file-upload me-2"></i> Import Master List
                     </button>
                </div>
            </div>
            
            ${pendingSectionRequests.length > 0 ? `
            <div class="content-card mb-4 border-start border-primary border-5">
                <h4 class="text-primary mb-3"><i class="fas fa-user-clock me-2"></i>Pending Enrollment Requests</h4>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Student</th>
                                <th>Requested Section</th>
                                <th>Master List Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${pendingSectionRequests.map(req => {
                                const fullName = `${req.firstname} ${req.lastname}`;
                                const hasMatch = req.master_match_id != null;
                                const statusBadge = hasMatch 
                                    ? '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i> Verified</span>' 
                                    : '<span class="badge bg-danger"><i class="fas fa-exclamation-triangle me-1"></i> Record Mismatch</span>';
                                
                                let photoPath = req.photo;
                                if (photoPath && !photoPath.startsWith('uploads/')) {
                                    photoPath = 'uploads/profile_photos/' + photoPath;
                                }
                                const photoUrl = (req.photo && req.photo !== '') ? '../' + photoPath : '../img/logo.png';

                                return `
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <img src="${photoUrl}" class="rounded-circle me-3" style="width: 40px; height: 40px; object-fit: cover;" onerror="this.onerror=null;this.src='../img/logo.png'">
                                            <div>
                                                <div class="fw-bold">${fullName}</div>
                                                <div class="small text-muted">ID: ${req.student_number || 'N/A'}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fw-bold">Section ${req.section}</div>
                                        <div class="small text-muted">Year ${req.year_level}</div>
                                    </td>
                                    <td>${statusBadge}</td>
                                    <td>
                                        <div class="d-flex gap-2">
                                            <form method="POST" action="process_section_request.php" onsubmit="return confirm('Approve this enrollment?');">
                                                <input type="hidden" name="request_id" value="${req.request_id}">
                                                <button type="submit" name="approve_request" class="btn btn-success btn-sm" ${!hasMatch ? 'disabled title="Cannot approve: Record Mismatch"' : 'title="Approve"'}>
                                                    <i class="fas fa-check"></i>
                                                </button>
                                            </form>
                                            <form method="POST" action="process_section_request.php" onsubmit="return confirm('Decline this enrollment?');">
                                                <input type="hidden" name="request_id" value="${req.request_id}">
                                                <button type="submit" name="decline_request" class="btn btn-danger btn-sm" title="Decline">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                `;
                            }).join('')}
                        </tbody>
                    </table>
                </div>
            </div>
            ` : ''}
            
            ${pendingAdvisoryAccomps.length > 0 ? `
            <div class="content-card mb-4 border-start border-warning border-5">
                <h4 class="text-warning mb-3"><i class="fas fa-hourglass-half me-2"></i>Pending Submissions</h4>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Student</th>
                                <th>Activity / Date</th>
                                <th>Hours</th>
                                <th>Proof</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${pendingAdvisoryAccomps.map(acc => {
                                const fullName = `${acc.firstname} ${acc.mi ? acc.mi + '.' : ''} ${acc.lastname}`;
                                const date = new Date(acc.work_date).toLocaleDateString();
                                let photoHtml = '';
                                if(acc.photo) photoHtml += `<a href="../student/${acc.photo}" target="_blank" class="btn btn-sm btn-outline-info me-1"><i class="fas fa-image"></i> 1</a>`;
                                if(acc.photo2) photoHtml += `<a href="../student/${acc.photo2}" target="_blank" class="btn btn-sm btn-outline-info"><i class="fas fa-image"></i> 2</a>`;
                                
                                let assignedByHtml = '';
                                if (acc.assigner_fname) {
                                    if (acc.assigner_id_val == acc.student_adviser_id || acc.is_section_adviser > 0) {
                                         assignedByHtml = `<div class="small text-success mt-1"><i class="fas fa-user-shield me-1"></i> Adviser: ${acc.assigner_fname} ${acc.assigner_lname}</div>`;
                                    } else {
                                         assignedByHtml = `<div class="small text-primary mt-1"><i class="fas fa-user-tag me-1"></i> Instructor: ${acc.assigner_fname} ${acc.assigner_lname}</div>`;
                                    }
                                }

                                return `
                                <tr>
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
                                    <td>${photoHtml || '<span class="text-muted small">No photos</span>'}</td>
                                    <td>
                                        <div class="d-flex gap-2">
                                            <form method="POST" onsubmit="return confirm('Approve this accomplishment?');">
                                                <input type="hidden" name="ar_id" value="${acc.id}">
                                                <button type="submit" name="approve_accomp" class="btn btn-success btn-sm" title="Approve">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                            </form>
                                            <form method="POST" onsubmit="return confirm('Reject this accomplishment?');">
                                                <input type="hidden" name="ar_id" value="${acc.id}">
                                                <button type="submit" name="reject_accomp" class="btn btn-danger btn-sm" title="Reject">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                `;
                            }).join('')}
                        </tbody>
                    </table>
                </div>
            </div>
            ` : ''}

            `;
        
        if (advisoryStudentsData.length === 0) {
             html += '<div class="alert alert-info">No advisory section assigned yet or no students found in your assigned section.</div>';
        } else {
            // Group students by section
            const studentsBySection = advisoryStudentsData.reduce((acc, student) => {
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
                     const hours = student.completed_hours || 0;
                     const progress = Math.min((hours / 300) * 100, 100).toFixed(1);
                     
                     html += `
                        <tr>
                            <td>
                                <div class="d-flex align-items-center">
                                    <img src="${photoUrl}" class="rounded-circle me-3" style="width: 40px; height: 40px; object-fit: cover;" onerror="this.onerror=null;this.src='../img/logo.png'">
                                    <div class="fw-bold">${fullName}</div>
                                </div>
                            </td>
                            <td style="min-width: 150px;">
                                <div class="d-flex justify-content-between small text-muted mb-1">
                                    <span>${hours}/300 hrs</span>
                                </div>
                                <div class="progress" style="height: 6px;">
                                    <div class="progress-bar bg-success" role="progressbar" style="width: ${progress}%"></div>
                                </div>
                            </td>
                            <td>
                                <div class="d-flex gap-2">
                                    <button class="btn btn-outline-primary btn-sm" onclick="viewStudentDetails(${student.stud_id})">
                                        <i class="fas fa-eye me-1"></i> View Details
                                    </button>
                                    <form method="POST" action="process_section_request.php" class="d-inline" onsubmit="return confirm('End Session for this student? This will mark enrollment as Completed and lock the account.');">
                                        <input type="hidden" name="student_id" value="${student.stud_id}">
                                        <button type="submit" name="end_session" class="btn btn-outline-danger btn-sm" title="End Session & Archive">
                                            <i class="fas fa-user-lock me-1"></i> End Session
                                        </button>
                                    </form>
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
                    const icon = 'fa-file-alt';
                    const date = new Date(n.created_at).toLocaleString();
                    html += `
                        <div class="list-group-item ${bgClass} d-flex justify-content-between align-items-center">
                            <div>
                                <i class="fas ${icon} me-2 text-primary"></i>
                                ${n.message}
                                <br><small class="text-muted ms-4">${date}</small>
                            </div>
                            ${n.is_read == 0 ? '<span class="badge bg-primary rounded-pill">New</span>' : ''}
                        </div>
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
                <div class="col-md-6">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-user-graduate"></i></div>
                        <h3 style="color: var(--secondary-color);">${totalStudents}</h3>
                        <p class="text-muted">Total Students</p>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-layer-group"></i></div>
                        <h3 style="color: var(--secondary-color);">${totalSections}</h3>
                        <p class="text-muted">Active Sections</p>
                    </div>
                </div>
            </div>
            
            <div class="row mt-5">
                <div class="col-12">
                    <div class="content-card">
                        <h4>Quick Actions</h4>
                        <div class="d-flex gap-3 mt-3">
                            <button class="btn btn-primary" onclick="showView('tasks', document.querySelector('[onclick*=\\'tasks\\']'))">
                                <i class="fas fa-plus-circle me-2"></i> Create Task
                            </button>
                            <button class="btn btn-outline-secondary" onclick="showView('classes', document.querySelector('[onclick*=\\'classes\\']'))">
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
            <div id="classes-content" class="row g-4"></div>
        `;
        renderYears();
    }

    function renderYears() {
        const content = document.getElementById('classes-content');
        const nav = document.getElementById('classes-nav').querySelector('.breadcrumb');
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
                    <td>${s.completed_hours} hrs</td>
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
    
    function viewStudentDetails(studentId) {
        // Find student
        let student = null;
        
        // Search in Class Students (grouped structure)
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
        
        // Search in Advisory Students if not found
        if (!student && advisoryStudentsData) {
             student = advisoryStudentsData.find(s => s.stud_id == studentId);
        }
        
        if (!student) {
            console.error('Student not found:', studentId);
            return;
        }

        document.getElementById('studentDetailsTitle').textContent = 'Student Details';
        
        // Prepare Data
        // Fix photo path: ensure we don't double 'uploads/' if DB already has it
        // If DB has 'uploads/...' then '../student/' + photo is correct.
        // If DB has 'filename.jpg', we might need '../student/uploads/' + photo.
        let photoPath = student.photo;
        if (photoPath && !photoPath.startsWith('uploads/')) {
            photoPath = 'uploads/profile_photos/' + photoPath; // Fallback for legacy data
        }
        const photoUrl = (student.photo && student.photo !== '') ? '../' + photoPath : '../img/logo.png';
        
        const fullName = `${student.firstname} ${student.lastname}`;
        const totalHours = parseFloat(student.completed_hours || 0).toFixed(2);
        const arLink = `../student/documents/ar.php?stud_id=${studentId}`;
        
        // Pending Tasks
        const tasks = allStudentTasks[studentId] || [];
        const pendingTasks = tasks.filter(t => t.status === 'Pending');

        // Build HTML
        let pendingTasksHtml = '';
        if (pendingTasks.length > 0) {
            pendingTasksHtml = `
                <div class="flex-grow-1 ms-3">
                    <h6 class="text-primary fw-bold mb-3"><i class="fas fa-tasks me-1"></i> Pending Tasks</h6>
                    <div class="table-responsive">
                        <table class="table table-hover table-sm align-middle border">
                            <thead class="table-light">
                                <tr>
                                    <th>Task</th>
                                    <th>Date Assigned</th>
                                </tr>
                            </thead>
                            <tbody>
            `;
            pendingTasks.forEach(t => {
                pendingTasksHtml += `
                    <tr>
                        <td>
                            <div class="fw-bold text-dark">${t.title}</div>
                            <div class="small text-muted text-truncate" style="max-width: 200px;">${t.description}</div>
                        </td>
                        <td class="small text-muted">${new Date(t.created_at).toLocaleDateString()}</td>
                    </tr>
                `;
            });
            pendingTasksHtml += `
                            </tbody>
                        </table>
                    </div>
                </div>
            `;
        }

        const modalBody = `
            <div class="container-fluid p-0">
                <div class="row mb-4 align-items-center">
                    <div class="col-auto">
                        <img src="${photoUrl}" alt="Profile" class="rounded-circle border border-3 border-primary" style="width: 100px; height: 100px; object-fit: cover;" onerror="this.onerror=null;this.src='../img/logo.png'"> 
                    </div>
                    <div class="col" style="min-width: 0;">
                        <h3 class="fw-bold text-dark mb-1 text-truncate" title="${fullName}">${fullName}</h3>
                        <p class="text-muted mb-2 text-truncate" title="${student.email}"><i class="fas fa-envelope me-1"></i> ${student.email}</p>
                        <span class="badge bg-success fs-6 rounded-pill">
                            <i class="fas fa-hourglass-half me-1"></i> Total Hours: ${totalHours}
                        </span>
                    </div>
                </div>
                
                <hr class="my-4">

                <div class="d-flex align-items-start">
                    <div class="${pendingTasks.length > 0 ? 'me-4' : 'w-100 text-center'}">
                        <br>
                            
                    <a href="${arLink}" target="_blank" class="btn btn-primary btn-sm px-3 shadow-sm">
                            
                         <i class="fas fa-file-alt me-2"></i> View Accomplishment Report
                        </a>
                    </div>
                    ${pendingTasksHtml}
                </div>
            </div>
        `;
        
        const modalContainer = document.querySelector('#studentDetailsModal .modal-body');
        modalContainer.innerHTML = modalBody;

        const modal = new bootstrap.Modal(document.getElementById('studentDetailsModal'));
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
            <option disabled>──────────</option>
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
                
                tasksHtml += `
                    <tr>
                        <td class="fw-bold text-primary">${task.title}</td>
                        <td><div class="text-truncate" style="max-width: 300px;" title="${task.description}">${task.description}</div></td>
                        <td><small class="text-muted">${dateStr}</small></td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-info me-1" onclick="openTaskDetailsModal(${task.task_id})">
                                <i class="fas fa-users"></i>
                            </button>
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
    }

    function openEditTaskModal(taskId) {
        const task = myTasks.find(t => t.task_id == taskId);
        if(!task) return;
        
        document.getElementById('edit_task_id').value = task.task_id;
        document.getElementById('edit_task_title').value = task.title;
        document.getElementById('edit_task_desc').value = task.description;
        document.getElementById('edit_task_duration').value = task.duration || "";
        
        const modal = new bootstrap.Modal(document.getElementById('editTaskModal'));
        modal.show();
    }

    function openTaskDetailsModal(taskId) {
        const assignments = taskAssignments[taskId] || [];
        const container = document.getElementById('taskAssignmentsList');
        let html = '';
        
        if (assignments.length === 0) {
            html = '<tr><td colspan="3" class="text-center text-muted">No students assigned to this task.</td></tr>';
        } else {
            assignments.forEach(a => {
                let badgeClass = 'bg-secondary';
                if (a.assignment_status === 'Completed') badgeClass = 'bg-success';
                else if (a.assignment_status === 'Pending') badgeClass = 'bg-warning text-dark';
                
                html += `
                    <tr>
                        <td class="fw-bold">${a.lastname}, ${a.firstname}</td>
                        <td>Section ${a.section} (Year ${a.year_level})</td>
                        <td><span class="badge ${badgeClass}">${a.assignment_status}</span></td>
                    </tr>
                `;
            });
        }
        
        container.innerHTML = html;
        const modal = new bootstrap.Modal(document.getElementById('taskDetailsModal'));
        modal.show();
    }

    function populateStudentSelection() {
        const accordion = document.getElementById('studentAccordion');
        let html = '';
        let index = 0;
        
        Object.keys(studentsData).forEach(year => {
            Object.keys(studentsData[year]).forEach(sec => {
                const students = studentsData[year][sec];
                const id = `collapse${index}`;
                
                html += `
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#${id}">
                                Year ${year} - Section ${sec}
                            </button>
                        </h2>
                        <div id="${id}" class="accordion-collapse collapse">
                            <div class="accordion-body">
                                <div class="form-check mb-2 pb-2 border-bottom">
                                    <input class="form-check-input" type="checkbox" id="selectAll_${index}" onchange="toggleSection(this, '${id}')">
                                    <label class="form-check-label fw-bold" for="selectAll_${index}">
                                        Select All
                                    </label>
                                </div>
                `;
                
                students.forEach(s => {
                    html += `
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="send_to[]" value="${s.stud_id}" id="check_${s.stud_id}">
                            <label class="form-check-label" for="check_${s.stud_id}">
                                ${s.lastname}, ${s.firstname}
                            </label>
                        </div>
                    `;
                });
                
                html += `
                            </div>
                        </div>
                    </div>
                `;
                index++;
            });
        });
        accordion.innerHTML = html;
    }

    function toggleSection(source, sectionId) {
        const checkboxes = document.querySelectorAll(`#${sectionId} input[name="send_to[]"]`);
        checkboxes.forEach(cb => cb.checked = source.checked);
    }

    // Initialize
    showView('dashboard');

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
