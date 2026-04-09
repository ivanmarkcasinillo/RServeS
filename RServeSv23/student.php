<!--Student Dashboard-->
<?php
date_default_timezone_set('Asia/Manila');

session_start();
require "dbconnect.php";

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Student') {
    header("Location: home2.php");
    exit;
}

$student_id = $_SESSION['stud_id'];
$email = $_SESSION['email'];

$stmt = $conn->prepare("
    SELECT s.stud_id, s.firstname, s.lastname, s.mi, s.photo, s.instructor_id, s.department_id,
           s.year_level, s.semester, d.department_name
    FROM students s
    LEFT JOIN departments d ON s.department_id = d.department_id
    WHERE s.stud_id = ?
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

$fullname = $student['firstname'] 
          . (!empty($student['mi']) ? ' ' . strtoupper(substr($student['mi'],0,1)) . '.' : '')
          . ' ' . $student['lastname'];
$photo = $student['photo'] ?: 'default_profile.png';
$college_name = $student['department_name'];

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


$stmt_ar = $conn->prepare("
    SELECT SUM(hours) AS total_hours
    FROM (
        SELECT hours
        FROM accomplishment_reports
        WHERE student_id = ?
    ) AS all_hours
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

// Handle add_accomplishment form submission
if (!in_array($_SESSION['role'], ['Coordinator', 'Instructor'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_accomplishment'])) {
        $work_date = $_POST['work_date'];
        $activity = trim($_POST['activity']);
        if (!empty($_POST['task_title'])) {
            $activity = trim($_POST['task_title']) . ': ' . $activity;
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
    (student_id, work_date, activity, time_start, time_end, hours, status, photo, photo2) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("issssdsss", 
            $student_id, $work_date, $activity, $time_start, $time_end, 
            $hours, $status, $photo1, $photo2
        );

        if ($stmt->execute()) {
            // If prefill_task, mark student_task as completed
            if (!empty($_POST['prefill_stask_id'])) {
                $stask_id = intval($_POST['prefill_stask_id']);
                $stmt2 = $conn->prepare("UPDATE student_tasks SET status = 'Completed', completed_at = NOW() WHERE stask_id = ? AND student_id = ?");
                $stmt2->bind_param("ii", $stask_id, $student_id);
                $stmt2->execute();
                $stmt2->close();
            }
            $_SESSION['flash'] = "Accomplishment submitted to adviser for approval!";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }
        $stmt->close();
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
    
    if (!empty($task_title)) {
        $stmt = $conn->prepare("INSERT INTO tasks (title, description, instructor_id, department_id, created_by_student, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("ssiii", $task_title, $task_desc, $student['instructor_id'], $student['department_id'], $student_id);
        
        if ($stmt->execute()) {
            $new_task_id = $stmt->insert_id;
            $stmt->close();
            
            $stmt2 = $conn->prepare("INSERT INTO student_tasks (task_id, student_id, status, assigned_at) VALUES (?, ?, 'Pending', NOW())");
            $stmt2->bind_param("ii", $new_task_id, $student_id);
            $stmt2->execute();
            $stmt2->close();
            
            $_SESSION['flash'] = "Verbal task '{$task_title}' created successfully!";
        }
        header("Location: ".$_SERVER['PHP_SELF']);
        exit;
    }
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

if (isset($_POST['create_org_task'])) {
    $org_name = trim($_POST['organization']);
    $org_desc = trim($_POST['org_description']);
    
    if (!empty($org_name)) {
        $stmt = $conn->prepare("INSERT INTO organization_tasks (student_id, organization_name, description, status) VALUES (?, ?, ?, 'Pending')");
        $stmt->bind_param("iss", $student_id, $org_name, $org_desc);
        $stmt->execute();
        $stmt->close();
        
        $_SESSION['flash'] = "Organization '{$org_name}' added! Waiting for adviser approval.";
        header("Location: ".$_SERVER['PHP_SELF']);
        exit;
    }
}

$tasks = [];
$query = "
    SELECT 
        st.stask_id, st.status, st.assigned_at, 
        t.task_id, t.title, t.description, t.created_by_student, t.created_at,
        CASE 
            WHEN t.created_by_student = ? THEN 'verbal'
            ELSE 'adviser'
        END as task_type
    FROM student_tasks st
    INNER JOIN tasks t ON st.task_id = t.task_id
    WHERE st.student_id = ? AND st.status != 'Completed'
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

$org_tasks = [];
$stmt = $conn->prepare("
    SELECT ot.* 
    FROM organization_tasks ot
    LEFT JOIN organization_accomplishments oa ON ot.id = oa.org_task_id
    WHERE ot.student_id = ? 
    AND ot.status != 'Approved'
    AND oa.id IS NULL
    ORDER BY ot.created_at DESC
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $org_tasks[] = $row;
}
$stmt->close();

// Fetch pending organization accomplishments for display
$pending_org_accomps = [];
if ($org_tasks) {
    $org_ids = implode(',', array_column($org_tasks, 'id'));
    if (!empty($org_ids)) {
        $stmt = $conn->prepare("
            SELECT oa.*, ot.organization_name 
            FROM organization_accomplishments oa
            JOIN organization_tasks ot ON oa.org_task_id = ot.id
            WHERE oa.org_task_id IN ($org_ids) AND oa.status = 'Pending'
            ORDER BY oa.submitted_at DESC
        ");
        $stmt->execute();
        $pending_org_accomps = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

// Calculate total pending organization items for sidebar badge
$total_pending_org = 0;

// 1. Pending Organization Tasks (Requests to add org)
$stmt = $conn->prepare("SELECT COUNT(*) FROM organization_tasks WHERE student_id = ? AND status = 'Pending'");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$stmt->bind_result($cnt_org_tasks);
$stmt->fetch();
$stmt->close();
$total_pending_org += $cnt_org_tasks;

// 2. Pending Organization Accomplishments (Submitted hours)
$stmt = $conn->prepare("
    SELECT COUNT(*) 
    FROM organization_accomplishments oa
    JOIN organization_tasks ot ON oa.org_task_id = ot.id
    WHERE ot.student_id = ? AND oa.status = 'Pending'
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$stmt->bind_result($cnt_org_accomps);
$stmt->fetch();
$stmt->close();
$total_pending_org += $cnt_org_accomps;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Dashboard</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Urbanist:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<style>
:root {
  --bg: linear-gradient(135deg, #e6f4f9, #d1ecf1);
  --card: #ffffff;
  --accent: #4fb2d8;
  --primary: #1d6ea0;
  --secondary: #0d3c61;
  --text-dark: #123047;
  --text-light: #ffffff;
  --shadow: 0 8px 32px rgba(0,0,0,0.1);
  --border-radius: 16px;
  --transition: all 0.3s ease;
}
html {
  font-size: 16px;
}


body {
  font-family: 'Urbanist', sans-serif;
  background: var(--bg);
  color: var(--text-dark);
  margin: 0;
  padding: 0;
  min-height: 100vh;
}

body::before {
  content: "";
  position: fixed;
  top: 0; left: 0; right: 0; bottom: 0;
  background: url(../img/bg.jpg);
  filter: blur(2px) brightness(0.8);
  opacity: 80%;
  z-index: -1;
}

.navbar {
  background: linear-gradient(90deg, var(--primary), var(--secondary));
  box-shadow: var(--shadow);
  backdrop-filter: blur(10px);
  border-bottom: 1px solid rgba(255,255,255,0.2);
}
.navbar-text i {
  font-size: 1em; /* inherits from text */
}


.navbar-text {
 font-size: clamp(0.85rem, 2.5vw, 1.1rem);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  color: var(--text-light);
}

.navbar .btn {
  font-weight: 500;
  border-radius: 25px;
  padding: 8px 20px;
  transition: var(--transition);
}

.navbar .btn:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0,0,0,0.2);
}

.card {
  background: var(--card);
  border: none;
  border-radius: var(--border-radius);
  box-shadow: var(--shadow);
  padding: 25px;
  transition: var(--transition);
  overflow: hidden;
  position: relative;
  margin-bottom: 30px;
}

.card::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 8px;
  background: linear-gradient(100deg, var(--accent), var(--primary));
}

.card h3, .card h4, .card h5 {
  color: var(--primary);
  font-weight: 700;
  display: flex;
  align-items: center;
  margin-bottom: 20px;
}

.card h3 i, .card h4 i, .card h5 i {
  margin-right: 10px;
  color: var(--accent);
}

.btn-custom {
  background: var(--accent);
  color: var(--text-light);
  border: none;
  font-weight: 500;
  border-radius: 10px;
  padding: 5px 10px;
  transition: var(--transition);
}

.btn-custom:hover {
  background: var(--primary);
  color: var(--text-light);
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0,0,0,0.2);
}

.btn-dark {
  background: var(--primary);
  border-color: var(--secondary);
  border-radius: 10px;
}

.btn-dark:hover {
  background: var(--secondary);
  transform: translateY(-2px);
}

.progress-circle {
  width: 180px;
  height: 180px;
  border-radius: 50%;
  background: conic-gradient(var(--accent) 0% var(--progress), #e0e0e0 var(--progress) 100%);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.8rem;
  font-weight: bold;
  color: var(--primary);
  position: relative;
  margin: 20px auto;
  box-shadow: var(--shadow);
}

.progress-circle::before {
  content: '';
  position: absolute;
  width: 140px;
  height: 140px;
  border-radius: 50%;
  background: white;
  z-index: 1;
}

.progress-circle span {
  z-index: 2;
}

.progress-message {
  text-align: center;
  font-size: 1.1rem;
  color: var(--primary);
  font-weight: 600;
  margin-top: 10px;
}

.task-item {
  background: rgba(255,255,255,0.8);
  border: 1px solid rgba(0,0,0,0.05);
  border-radius: 10px;
  padding: 15px;
  margin-bottom: 12px;
  transition: var(--transition);
}

.task-item:hover {
  background: rgba(79, 178, 216, 0.05);
  transform: translateX(5px);
  box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}
.form-label {
  font-size: clamp(0.85rem, 1.8vw, 0.95rem);
}

.modal-body {
  font-size: clamp(0.9rem, 2vw, 1rem);
}

@media (max-width: 576px) {
  .modal-title {
    font-size: 1.1rem;
  }
}

.btn-submit-task {
  background: #28a745;
  color: white;
  border: none;
  padding: 5px 15px;
  border-radius: 8px;
  font-size: 0.9rem;
  transition: var(--transition);
}

.btn-submit-task:hover {
  background: #218838;
  color: white;
  transform: translateY(-2px);
  box-shadow: 0 4px 8px rgba(0,0,0,0.2);
}

.offcanvas {
  width: 280px !important;
  background: rgba(255,255,255,0.95);
  backdrop-filter: blur(10px);
}

.offcanvas-header {
  background: linear-gradient(90deg, var(--primary), var(--secondary));
  color: white;
}

.offcanvas-title {
  color: white !important;
}

.menu-link {
  color: var(--primary);
  text-decoration: none;
  display: block;
  padding: 12px 15px;
  border-radius: 10px;
  transition: var(--transition);
  font-weight: 500;
}

.menu-link:hover {
  background: var(--accent);
  color: white;
  transform: translateX(5px);
}

.menu-link i {
  margin-right: 10px;
  width: 20px;
  text-align: center;
}

.org-instruction {
  background: rgba(255, 243, 205, 0.9);
  border-left: 4px solid #ffc107;
  padding: 15px;
  margin-bottom: 20px;
  border-radius: 10px;
  font-size: 0.9rem;
  box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.instructions {
  max-height: 80px;
  overflow: hidden;
  transition: max-height 0.3s ease;
}

.instructions.expanded {
  max-height: 600px;
}

.see-more {
  color: var(--primary);
  cursor: pointer;
  text-decoration: underline;
  font-size: 0.9rem;
  display: inline-block;
  margin-top: 8px;
  font-weight: 500;
}

.see-more:hover {
  color: var(--accent);
}

.stats-box {
  background: rgba(79, 178, 216, 0.1);
  border-radius: 10px;
  padding: 15px;
  margin: 15px 0;
  font-weight: 600;
  color: var(--primary);
}

.alert-warning {
  background: rgba(255, 193, 7, 0.15);
  border-left: 4px solid #ffc107;
  padding: 12px 15px;
  border-radius: 10px;
  font-size: 0.9rem;
  animation: slideIn 0.3s ease;
}

@keyframes slideIn {
  from {
    opacity: 0;
    transform: translateY(-10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.accordion-button {
  background: rgba(255,255,255,0.9) !important;
  font-weight: 600;
}

.accordion-button:not(.collapsed) {
  background: rgba(79, 178, 216, 0.1) !important;
  color: var(--primary);
}

.modal-header {
  background: linear-gradient(90deg, var(--primary), var(--secondary));
  color: white;
}

.modal-title {
  font-size: 1.25rem;
  font-size: clamp(1.1rem, 2.5vw, 1.5rem);
}


@media (max-width: 768px) {
  .container {
    padding: 15px;
  }
  
  .card {
    padding: 20px;
  }
  
  .progress-circle {
    width: 150px;
    height: 150px;
  }
  
  .progress-circle::before {
    width: 110px;
    height: 110px;
  }
}

.task-btn {
  border-radius: 20px;
  font-size: clamp(0.85rem, 2vw, 1rem);
  padding: clamp(0.4rem, 1vw, 0.6rem) clamp(0.8rem, 2vw, 1rem);
  transition: all 0.3s ease;
  border: 2px solid var(--primary);
}

.task-btn:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}

.task-btn.active {
  background: var(--primary) !important;
  color: white !important;
  border-color: var(--primary) !important;
}

#addCustomTaskBtn {
  border: 2px dashed #28a745;
  border-radius: 20px;
}

#addCustomTaskBtn:hover {
  background: #28a745;
  color: white;
  transform: translateY(-2px);
}

/* Organization Button Styles */
.org-btn {
  border-radius: 20px;
  padding: 8px 16px;
  font-weight: 500;
  transition: all 0.3s ease;
  border: 2px solid var(--primary);
}

.org-btn:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}

.org-btn.active {
  background: var(--primary) !important;
  color: white !important;
  border-color: var(--primary) !important;
}

#addCustomOrgBtn {
  border: 2px dashed #28a745;
  border-radius: 20px;
}

#addCustomOrgBtn:hover {
  background: #28a745;
  color: white;
  transform: translateY(-2px);
}
</style>
</head>
<body>

<nav class="navbar p-3">
  <div class="container-fluid">
    <button class="btn btn-dark me-3" data-bs-toggle="offcanvas" data-bs-target="#sidebar">
      <i class="fas fa-bars"></i>
    </button>
    <span class="navbar-text">
      <i class="fas fa-graduation-cap me-2"></i>Student Dashboard <br> <?= htmlspecialchars($college_name) ?>
    </span>
  </div>
</nav>

<div class="offcanvas offcanvas-start" id="sidebar">
  <div class="offcanvas-header border-bottom">
    <h5 class="offcanvas-title"><i class="fas fa-user-graduate me-2"></i>Student Panel</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
  </div>
  <div class="p-3 text-center">
    <form method="POST" enctype="multipart/form-data">
      <label for="profilePhoto" style="cursor:pointer;">
        <img src="../<?= htmlspecialchars($photo) ?>" alt="Profile"
             class="rounded-circle mb-2" width="100" height="100" 
             style="object-fit:cover; border:3px solid var(--primary); box-shadow: var(--shadow);">
        <div style="font-size:0.85rem; color:var(--primary); font-weight:600;">
          <i class="fas fa-camera me-1"></i>Change Photo
        </div>
      </label>
      <input type="file" name="profilePhoto" id="profilePhoto" style="display:none;" 
             accept="image/*" onchange="this.form.submit()">
    </form>
    
    <h5 class="mt-3" style="color: var(--primary);"><?= htmlspecialchars($fullname) ?></h5>
    <p class="small mb-0"><?= htmlspecialchars($email) ?></p>
    <p class="text-muted small mb-3"><?= htmlspecialchars($college_name) ?></p>
    
    <a href="#progressSection" class="menu-link mb-2" data-bs-dismiss="offcanvas" onclick="scrollToSection('progressSection')">
      <i class="fas fa-chart-line"></i>View Progress
    </a>
    <a href="profset.php" class="menu-link mb-2">
      <i class="fas fa-cog"></i>Profile Settings
    </a>
    <a href="documents/ar.php" class="menu-link mb-2">
      <i class="fas fa-file-alt"></i>Accomplishment Report
    </a>
    <a href="#tasksSection" class="menu-link mb-2" data-bs-dismiss="offcanvas" onclick="scrollToSection('tasksSection')">
      <i class="fas fa-tasks"></i>Tasks
    </a>
    <a href="#organizationSection" class="menu-link mb-2" data-bs-dismiss="offcanvas" onclick="scrollToSection('organizationSection')">
      <i class="fas fa-users"></i>Organization
      <?php if ($total_pending_org > 0): ?>
        <span class="badge bg-warning text-dark rounded-circle ms-2" style="font-size: 0.7rem; vertical-align: top;"><?= $total_pending_org ?></span>
      <?php endif; ?>
    </a>
    <a href="logout.php" class="btn btn-danger w-100 mt-3">
      <i class="fas fa-sign-out-alt me-1"></i>Logout
    </a>
  </div>
</div>

<div class="container my-4">
  
  <?php if (isset($_SESSION['flash'])): ?>
    <div class="alert alert-success alert-dismissible fade show" style="border-radius: var(--border-radius);">
      <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($_SESSION['flash']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['flash']); ?>
  <?php endif; ?>

  <div class="card">
    <h3><i class="fas fa-hand-wave"></i>Greetings! Hello, <?= htmlspecialchars($student['firstname']) ?>! 👋</h3>
    
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

  <div class="row mb-4" id="progressSection">
    <div class="col-12">
      <div class="card text-center">
        <h4><i class="fas fa-chart-line"></i>Your RSS Progress</h4>
        <div class="progress-circle mx-auto" style="--progress: <?= $progress_percent ?>%;">
          <span><?= number_format($progress_percent, 1) ?>%</span>
        </div>
        <p class="mt-2 mb-0"><strong><?= number_format($total_hours_completed, 2) ?></strong> / <?= $required_hours ?> hours</p>
        <p class="progress-message"><?= $progress_message ?></p>
      </div>
    </div>
  </div>

  <div class="row mb-4">
    <div class="col-12">
      <div class="card">
        <h4><i class="fas fa-clock"></i>Real-Time Attendance Tracking</h4>
        <div class="alert alert-info mb-3" style="border-radius: 10px;">
          <strong><i class="fas fa-calendar-check me-2"></i>Schedule:</strong> Morning: 8:00-12:00 (4hrs) | Afternoon: 1:00-5:00 (4hrs)<br>
          <small>You can time in anytime. Late arrivals will have hours deducted proportionally.</small>              
        </div>
        
        <form method="POST" id="attendanceForm">
          <div class="row mb-3">
            <div class="col-md-4">
              <label class="form-label fw-bold">Current Date</label>
              <input type="date" name="work_date" class="form-control" 
                     value="<?= date('Y-m-d') ?>" required readonly>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-bold">Session</label>
              <select name="session" id="sessionSelect" class="form-select" required>
                <option value="">Select Session</option>
                <option value="morning">Morning (8:00 AM - 12:00 PM)</option>
                <option value="afternoon">Afternoon (1:00 PM - 5:00 PM)</option>
                <option value="fullday">Full Day (8:00 AM - 5:00 PM)</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-bold">Time In</label>
              <input type="time" name="time_in" id="timeIn" class="form-control" readonly>
            </div>
          </div>
          
          <input type="hidden" name="calculated_hours" id="calculatedHoursHidden">
          
          <div class="stats-box">
            <i class="fas fa-clock me-2"></i>Calculated Hours: <strong><span id="hoursDisplay">0.00</span></strong>
          </div>

          <div id="lateStatusMsg" style="display: none;"></div>
          
          <button type="submit" name="submit_time" id="submitBtn" class="btn btn-custom" disabled>
            <i class="fas fa-check me-2"></i>Submit Attendance
          </button>
        </form>
      </div>
    </div>
  </div>

  <div class="row">
    <div class="col-md-6 mb-4" id="tasksSection">
      <div class="card">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h4><i class="fas fa-tasks"></i>Tasks (<?= count($tasks) ?>)</h4>
          <button class="btn btn-custom btn-sm" data-bs-toggle="modal" data-bs-target="#verbalTaskModal">
            <i class="fas fa-plus me-1"></i>Create Verbal Task
          </button>
        </div>
        
        <?php if (empty($tasks)): ?>
          <div class="text-center py-4">
            <i class="fas fa-clipboard-list fa-3x mb-3" style="color: var(--accent);"></i>
            <p class="text-muted mb-3">No tasks yet.</p>
            <small class="text-muted">Create a verbal task or wait for adviser assignment.</small>
          </div>
        <?php else: ?>
          <?php foreach ($tasks as $task): ?>
            <?php $isVerbal = ($task['task_type'] === 'verbal'); ?>
            <div class="task-item">
              <div class="d-flex justify-content-between align-items-start mb-2">
                <div class="flex-grow-1">
                  <strong><?= htmlspecialchars($task['title']) ?></strong>
                  <span class="badge bg-<?= $isVerbal ? 'info' : 'success' ?> ms-2">
                    <?= $isVerbal ? '💬 Verbal' : '👨‍🏫 Adviser' ?>
                  </span>
                </div>
                <?php if ($task['status'] !== 'Completed'): ?>
                  <button class="btn-submit-task" onclick="submitToAccomplishment(<?= $task['stask_id'] ?>, '<?= addslashes(htmlspecialchars($task['title'])) ?>')">
                    <i class="fas fa-upload me-1"></i>Submit
                  </button>
                <?php endif; ?>
              </div>
              
              <?php if ($task['status'] !== 'Completed'): ?>
                <textarea class="form-control task-description-auto" 
                          placeholder="Type what you did for this task (auto-saved)..."
                          rows="2"
                          data-stask-id="<?= $task['stask_id'] ?>"><?= htmlspecialchars($task['description'] ?: '') ?></textarea>
              <?php else: ?>
                <p class="mb-0 small text-muted"><?= htmlspecialchars($task['description'] ?: 'No description') ?></p>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="col-md-6 mb-4" id="organizationSection">
      <div class="card">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h4><i class="fas fa-users"></i>Organization</h4>
          <button class="btn btn-custom btn-sm" data-bs-toggle="modal" data-bs-target="#orgTaskModal">
            <i class="fas fa-plus me-1"></i>Add Organization
          </button>
        </div>
        
        <div class="org-instruction">
          <strong><i class="fas fa-info-circle me-2"></i>Note:</strong> Only add organization-related tasks here. Regular tasks should be added in the Tasks section.
        </div>
        
        <?php if (empty($org_tasks)): ?>
          <div class="text-center py-4">
            <i class="fas fa-building fa-3x mb-3" style="color: var(--accent);"></i>
            <p class="text-muted">No organization added yet. Click "Add Organization" to start.</p>
          </div>
        <?php else: ?>
        <?php foreach ($org_tasks as $idx => $org): ?>
  <div class="accordion-item mb-2" style="border-radius: 5px; overflow: visible;">
    <h2 class="accordion-header" id="orgHeading<?= $idx ?>">
      <button class="accordion-button collapsed" type="button" 
            data-bs-toggle="collapse" data-bs-target="#orgCollapse<?= $idx ?>">
      <strong><i class="fas fa-building me-2"></i><?= htmlspecialchars($org['organization_name'] . ' : ' . ($org['description'] ?: '')) ?></strong>
      <?php if ($org['status'] === 'Approved'): ?>
        <span class="badge bg-success ms-2">✓ Approved</span>
      <?php endif; ?>
    </button>
    </h2>
    <div id="orgCollapse<?= $idx ?>" class="accordion-collapse collapse">
      <div class="accordion-body">
       
        
        <!-- Show pending accomplishments for this organization -->
        <?php 
        $org_pending = array_filter($pending_org_accomps, fn($accomp) => $accomp['org_task_id'] == $org['id']);
        if (!empty($org_pending)): ?>
          <div class="mb-3">
            <h6><i class="fas fa-clock me-2"></i>Pending Accomplishments:</h6>
            <?php foreach ($org_pending as $accomp): ?>
              <div class="alert alert-warning" style="border-radius: 10px;">
                <strong>Task:</strong> <?= htmlspecialchars($accomp['task_assignment']) ?><br>
                <strong>Date:</strong> <?= htmlspecialchars($accomp['work_date']) ?> | 
                <strong>Time:</strong> <?= htmlspecialchars($accomp['time_start']) ?> - <?= htmlspecialchars($accomp['time_end']) ?> | 
                <strong>Hours:</strong> <?= htmlspecialchars($accomp['total_hours']) ?> hrs<br>
                <em>Status: Pending Adviser Approval</em>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        
      <!-- Submission form for new accomplishments -->
<form method="POST" action="submit_org_accomplishment.php" enctype="multipart/form-data" class="org-work-form">
  <input type="hidden" name="org_task_id" value="<?= $org['id'] ?>">
  <input type="hidden" name="organization_name" value="<?= htmlspecialchars($org['organization_name']) ?>">
  
  <div class="mb-3">
    <br>
    <label class="form-label fw-bold">Task Assignment</label>
    <textarea name="task_assignment" class="form-control" rows="2" 
              placeholder="Describe what you did..." required></textarea>
  </div>
  
  <div class="row mb-3">
    <div class="col-md-4">
      <label class="form-label fw-bold">Date</label>
      <input type="date" name="work_date" class="form-control" required>
    </div>
    <div class="col-md-4">
      <label class="form-label fw-bold">Time Start</label>
      <input type="time" name="time_start" class="form-control org-time-start" required>
    </div>
    <div class="col-md-4">
      <label class="form-label fw-bold">Time End</label>
      <input type="time" name="time_end" class="form-control org-time-end" required>
    </div>
  </div>
  
  <div class="mb-3">
    <label class="form-label fw-bold">Total Hours</label>
    <input type="text" name="total_hours" class="form-control org-total-hours" readonly 
           style="background: #e8f5e9; font-weight: bold;">
  </div>
  
  <div class="row mb-3">
    <div class="col-md-6">
      <label class="form-label">Documentation 1 (Before)</label>
      <input type="file" name="photo" class="form-control" accept="image/*" required>
    </div>
    <div class="col-md-6">
      <label class="form-label">Documentation 2 (After)</label>
      <input type="file" name="photo2" class="form-control" accept="image/*">
    </div>
  </div>
  
  <button type="submit" class="btn btn-custom w-100">
    <i class="fas fa-check me-2"></i>Submit (Will be Pending Until Approved)
  </button>
</form>
      </div>
    </div>
  </div>
<?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- MODAL FOR VERBAL TASK WITH BUTTONS-->
<div class="modal fade" id="verbalTaskModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content" style="border-radius: var(--border-radius);">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-comments me-2"></i>Create Verbal Task</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" id="verbalTaskForm">
        <div class="modal-body">
          <div class="alert alert-info" style="border-radius: 10px;">
            <strong><i class="fas fa-lightbulb me-2"></i>What are verbal tasks?</strong><br>
            <small>Tasks assigned verbally by your adviser that aren't in the system yet. Select from common tasks or create a custom one.</small>
          </div>
          
          <div class="mb-3">
            <label class="form-label fw-bold">Select Task Category <span class="text-danger">*</span></label>
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
            
            <!-- Hidden input to store selected task -->
            <input type="hidden" name="task_title" id="selectedTaskTitle" required>
            
            <!-- Display selected task -->
            <div id="selectedTaskDisplay" class="alert alert-success d-none" style="border-radius: 10px;">
              <strong>Selected Task:</strong> <span id="selectedTaskName"></span>
              <button type="button" class="btn-close float-end" id="clearTaskBtn"></button>
            </div>
          </div>
          
          <div class="mb-3">
            <label class="form-label fw-bold">Description (Optional)</label>
            <textarea name="task_description" class="form-control" rows="3" 
                      placeholder="What did you do?"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="create_verbal_task" class="btn btn-custom" id="submitTaskBtn" disabled>
            <i class="fas fa-check me-1"></i>Create Task
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Organization Modal with Button Selection -->
<div class="modal fade" id="orgTaskModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content" style="border-radius: var(--border-radius);">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-building me-2"></i>Add Organization</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" id="orgTaskForm">
        <div class="modal-body">
          <div class="alert alert-info" style="border-radius: 10px;">
            <strong><i class="fas fa-info-circle me-2"></i>Organization Tasks</strong><br>
            <small>Select from available organizations or add a custom one.</small>
          </div>
          
          <div class="mb-3">
            <label class="form-label fw-bold">Select Organization or Create Custom <span class="text-danger">*</span></label>
            <div id="orgButtonsContainer" class="d-flex flex-wrap gap-2 mb-3">
              <!-- Predefined organization buttons -->
              <button type="button" class="btn btn-outline-primary org-btn" data-org="SCES">
                <i class="fas fa-users me-1"></i>SCES
              </button>
              <button type="button" class="btn btn-outline-primary org-btn" data-org="DBC">
                <i class="fas fa-drum me-1"></i>DBC
              </button>
              <button type="button" class="btn btn-outline-primary org-btn" data-org="LCW">
                <i class="fas fa-hands-helping me-1"></i>LCW
              </button>
              <button type="button" class="btn btn-outline-primary org-btn" data-org="Emblem">
                <i class="fas fa-newspaper me-1"></i>Emblem
              </button>
              <button type="button" class="btn btn-outline-primary org-btn" data-org="ROTC">
                <i class="fas fa-shield-alt me-1"></i>ROTC
              </button>
              <button type="button" class="btn btn-outline-primary org-btn" data-org="YES-O">
                <i class="fas fa-heart me-1"></i>YES-O
              </button>
              <button type="button" class="btn btn-outline-primary org-btn" data-org="Student Council">
                <i class="fas fa-landmark me-1"></i>Student Council
              </button>
              
              <!-- Add Custom Organization Button -->
              <button type="button" class="btn btn-outline-success" id="addCustomOrgBtn">
                <i class="fas fa-plus me-1"></i>Add Custom
              </button>
            </div>
            
            <!-- Hidden input to store selected organization -->
            <input type="hidden" name="organization" id="selectedOrgName" required>
            
            <!-- Display selected organization -->
            <div id="selectedOrgDisplay" class="alert alert-success d-none" style="border-radius: 10px;">
              <strong>Selected Organization:</strong> <span id="selectedOrgDisplayName"></span>
              <button type="button" class="btn-close float-end" id="clearOrgBtn"></button>
            </div>
            
            <!-- Custom organization input (hidden by default) -->
            <div id="customOrgInput" class="d-none">
              <label class="form-label fw-bold">Custom Organization Name</label>
              <div class="input-group">
                <input type="text" id="customOrgName" class="form-control" 
                       placeholder="Enter custom organization name">
                <button type="button" class="btn btn-success" id="saveCustomOrgBtn">
                  <i class="fas fa-check"></i> Add
                </button>
                <button type="button" class="btn btn-secondary" id="cancelCustomOrgBtn">
                  <i class="fas fa-times"></i>
                </button>
              </div>
            </div>
          </div>
          
      
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="create_org_task" class="btn btn-custom" id="submitOrgBtn" disabled>
            <i class="fas fa-check me-1"></i>Add Organization
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
function scrollToSection(sectionId) {
  setTimeout(() => {
    const element = document.getElementById(sectionId);
    if (element) {
      element.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }, 300);
}

function toggleInstructions() {
  const inst = document.getElementById('instructions');
  const btn = document.querySelector('.see-more');
  if (inst.classList.contains('expanded')) {
    inst.classList.remove('expanded');
    btn.textContent = 'See More ▼';
  } else {
    inst.classList.add('expanded');
    btn.textContent = 'See Less ▲';
  }
}

/* ===== ATTENDANCE - ALLOW LATE WITH DEDUCTION ===== */
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
    if (!session) {
        timeIn.value = '';
        hoursDisplay.textContent = '0.00';
        calculatedHoursHidden.value = '0.00';
        submitBtn.disabled = true;
        return;
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

    const phTime = getPhilippineTime();
    const formattedTime = `${String(phTime.getHours()).padStart(2,'0')}:${String(phTime.getMinutes()).padStart(2,'0')}`;
    
    timeIn.value = formattedTime;
    calculateAttendanceHours(session, formattedTime);
}, 10000);

if (sessionSelect) {
    sessionSelect.addEventListener('change', updateSessionSettings);
}

document.getElementById('attendanceForm').addEventListener('submit', function(e) {
    if (!timeIn.value || !sessionSelect.value) {
        e.preventDefault();
        alert('Please select a session first.');
    }
});

/* ===== AUTO-SAVE TASK DESCRIPTIONS ===== */
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

/* ===== SUBMIT TASK TO AR ===== */
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

    // Add Accomplishment Modal Logic
    document.addEventListener('DOMContentLoaded', () => {
      const modalEl = document.getElementById('addAccomplishmentModal');
      if (!modalEl) return;

      const modal = new bootstrap.Modal(modalEl);

      // Calculation Logic
      modalEl.addEventListener('shown.bs.modal', () => {
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

        timeStart.addEventListener('change', calculate);
        timeEnd.addEventListener('change', calculate);
        if (workDate) workDate.addEventListener('change', calculate);
      });

      // Global function to open modal
      window.openAccomplishmentModal = function(staskId, title, description) {
        document.getElementById('modal_prefill_stask_id').value = staskId;
        document.getElementById('modal_task_title').value = title || '';
        document.getElementById('modal_activity').value = description || '';
        
        // Reset times
        const timeStart = document.querySelector('.acc-time-start');
        if (timeStart && !timeStart.value) {
           // If PHP didn't set it (no Time In today), maybe set default?
           // For now, leave as is or set to current time
        }
        
        modal.show();
      };
    });

/* ===== ORGANIZATION HOURS ===== */
document.querySelectorAll('.org-work-form').forEach(form => {
  const timeStart = form.querySelector('.org-time-start');
  const timeEnd = form.querySelector('.org-time-end');
  const totalHours = form.querySelector('.org-total-hours');

  function updateOrgHours() {
    if (timeStart && timeEnd && totalHours && timeStart.value && timeEnd.value) {
      const start = new Date(`1970-01-01T${timeStart.value}:00`);
      const end = new Date(`1970-01-01T${timeEnd.value}:00`);
      let diff = (end - start) / 3600000;
      if (diff < 0) diff += 24;
      totalHours.value = diff.toFixed(2) + ' hours';
    }
  }

  if (timeStart && timeEnd) {
    timeStart.addEventListener('change', updateOrgHours);
    timeEnd.addEventListener('change', updateOrgHours);
  }
});

//STUDENT VERBAL TASK MODAL SCRIPT (NESTED)
// Task Selection Logic
document.addEventListener('DOMContentLoaded', function() {
  const mainCategorySelect = document.getElementById('mainCategorySelect');
  const subCategorySelects = document.querySelectorAll('.sub-category-select');
  const selectedTaskTitle = document.getElementById('selectedTaskTitle');
  const selectedTaskDisplay = document.getElementById('selectedTaskDisplay');
  const selectedTaskName = document.getElementById('selectedTaskName');
  const clearTaskBtn = document.getElementById('clearTaskBtn');
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

  if (clearTaskBtn) {
    clearTaskBtn.addEventListener('click', function() {
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
});

/* ===== ORGANIZATION BUTTON SELECTION LOGIC ===== */
const orgButtons = document.querySelectorAll('.org-btn');
const selectedOrgName = document.getElementById('selectedOrgName');
const selectedOrgDisplay = document.getElementById('selectedOrgDisplay');
const selectedOrgDisplayName = document.getElementById('selectedOrgDisplayName');
const clearOrgBtn = document.getElementById('clearOrgBtn');
const submitOrgBtn = document.getElementById('submitOrgBtn');
const addCustomOrgBtn = document.getElementById('addCustomOrgBtn');
const customOrgInput = document.getElementById('customOrgInput');
const customOrgName = document.getElementById('customOrgName');
const saveCustomOrgBtn = document.getElementById('saveCustomOrgBtn');
const cancelCustomOrgBtn = document.getElementById('cancelCustomOrgBtn');
const orgButtonsContainer = document.getElementById('orgButtonsContainer');

// Handle predefined organization button clicks
orgButtons.forEach(btn => {
  btn.addEventListener('click', function() {
    // Remove active class from all buttons
    document.querySelectorAll('.org-btn').forEach(b => b.classList.remove('active'));
    
    // Add active class to clicked button
    this.classList.add('active');
    
    // Set selected organization
    const orgName = this.dataset.org;
    selectedOrgName.value = orgName;
    selectedOrgDisplayName.textContent = orgName;
    selectedOrgDisplay.classList.remove('d-none');
    submitOrgBtn.disabled = false;
    
    // Hide custom input if visible
    customOrgInput.classList.add('d-none');
    customOrgName.value = '';
  });
});

// Clear organization selection
clearOrgBtn.addEventListener('click', function() {
  document.querySelectorAll('.org-btn').forEach(b => b.classList.remove('active'));
  selectedOrgName.value = '';
  selectedOrgDisplay.classList.add('d-none');
  submitOrgBtn.disabled = true;
});

// Show custom organization input
addCustomOrgBtn.addEventListener('click', function() {
  customOrgInput.classList.toggle('d-none');
  if (!customOrgInput.classList.contains('d-none')) {
    customOrgName.focus();
  }
});

// Cancel custom organization
cancelCustomOrgBtn.addEventListener('click', function() {
  customOrgInput.classList.add('d-none');
  customOrgName.value = '';
});

// Save custom organization as new button
saveCustomOrgBtn.addEventListener('click', function() {
  const orgName = customOrgName.value.trim();
  if (!orgName) {
    alert('Please enter an organization name');
    return;
  }
  
  // Create new button for custom organization
  const newBtn = document.createElement('button');
  newBtn.type = 'button';
  newBtn.className = 'btn btn-outline-primary org-btn active';
  newBtn.dataset.org = orgName;
  newBtn.innerHTML = `<i class="fas fa-star me-1"></i>${orgName}`;
  
  // Insert before the "Add Custom" button
  orgButtonsContainer.insertBefore(newBtn, addCustomOrgBtn);
  
  // Add click handler to new button
  newBtn.addEventListener('click', function() {
    document.querySelectorAll('.org-btn').forEach(b => b.classList.remove('active'));
    this.classList.add('active');
    
    selectedOrgName.value = this.dataset.org;
    selectedOrgDisplayName.textContent = this.dataset.org;
    selectedOrgDisplay.classList.remove('d-none');
    submitOrgBtn.disabled = false;
  });
  
  // Remove active from existing buttons
  document.querySelectorAll('.org-btn').forEach(b => b.classList.remove('active'));
  newBtn.classList.add('active');
  
  // Set selected organization
  selectedOrgName.value = orgName;
  selectedOrgDisplayName.textContent = orgName;
  selectedOrgDisplay.classList.remove('d-none');
  submitOrgBtn.disabled = false;
  
  // Hide custom input
  customOrgInput.classList.add('d-none');
  customOrgName.value = '';
});

// Allow Enter key to save custom organization
customOrgName.addEventListener('keypress', function(e) {
  if (e.key === 'Enter') {
    e.preventDefault();
    saveCustomOrgBtn.click();
  }
});

// Form validation for organization
document.getElementById('orgTaskForm').addEventListener('submit', function(e) {
  if (!selectedOrgName.value) {
    e.preventDefault();
    alert('Please select or create an organization first');
  }
});

// Reset form when organization modal is closed
const orgTaskModal = document.getElementById('orgTaskModal');
orgTaskModal.addEventListener('hidden.bs.modal', function() {
  document.querySelectorAll('.org-btn').forEach(b => b.classList.remove('active'));
  selectedOrgName.value = '';
  selectedOrgDisplay.classList.add('d-none');
  submitOrgBtn.disabled = true;
  customOrgInput.classList.add('d-none');
  customOrgName.value = '';
});
</script>

</body>
</html>