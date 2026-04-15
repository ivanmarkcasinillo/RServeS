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

/* Org Task Creation Removed */

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

// Org init removed

/* Org counts removed */
?>
<!DOCTYPE html>
<html lang="en">
<head class="rserve-theme">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - College of Technology</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- RServeS Theme -->
    <link rel="stylesheet" href="../assets/css/rserve-dashboard-theme.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Urbanist:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <style>
        :root {
            --primary-color: #1d6ea0;
            --secondary-color: #0d3c61;
            --accent-color: #4fb2d8;
            --bg-color: #f8f9fa;
            --text-dark: #123047;
            --sidebar-width: 260px;
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

        body {
            font-family: 'Urbanist', sans-serif;
            color: var(--text-dark);
            overflow-x: hidden;
            position: relative;
            min-height: 100vh;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: url('../img/bg.jpg');
            background-size: cover;
            background-position: center;
            filter: blur(5px);
            z-index: -1;
        }

        /* Sidebar Styles */
        #sidebar-wrapper {
            min-height: 100vh;
            width: var(--sidebar-width);
            margin-left: 0;
            transition: margin 0.25s ease-out;
            background: linear-gradient(180deg, rgba(8, 59, 74, 0.85), rgba(13, 60, 97, 0.95));
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1000;
            border-right: 1px solid rgba(255, 255, 255, 0.1);
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
            background: linear-gradient(90deg, rgba(29, 110, 160, 0.85), rgba(13, 60, 97, 0.95));
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            color: white;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .container-fluid {
            padding: 2rem;
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
        
        .btn-custom {
            background: var(--primary-color);
            color: white;
            border: none;
        }
        .btn-custom:hover {
            background: var(--secondary-color);
            color: white;
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
</head>
<body>

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
<!-- Org nav removed -->
            <a href="documents/ar.php" class="list-group-item list-group-item-action">
                <i class="fas fa-file-alt"></i> Documents
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
            <button class="btn btn-outline-light position-relative" id="menu-toggle">
                <i class="fas fa-bars"></i>
                <?php 
                $total_notifs = count($tasks);
                if ($total_notifs > 0): 
                ?>
                <span class="position-absolute top-0 start-100 translate-middle p-2 bg-danger border border-light rounded-circle d-lg-none">
                    <span class="visually-hidden">New alerts</span>
                </span>
                <?php endif; ?>
            </button>
            
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
                <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($_SESSION['flash']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['flash']); ?>
            <?php endif; ?>

            <!-- DASHBOARD VIEW -->
            <div id="view-dashboard">
                <div class="card"> 
                    <h3><i class="fas fa-hand-spock me-1"></i>Greetings! Hello, <?= htmlspecialchars($student['firstname']) ?>! </h3> 
                    
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
                            <h4 class="mb-4"><i class="fas fa-clock me-2"></i>Real-Time Attendance</h4>
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
                            <?php $isVerbal = ($task['task_type'] === 'verbal'); ?>
                            <div class="task-item">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <h5 class="fw-bold mb-1"><?= htmlspecialchars($task['title']) ?></h5>
                                        <span class="badge bg-<?= $isVerbal ? 'info' : 'success' ?>">
                                            <?= $isVerbal ? 'Verbal' : 'Adviser Assigned' ?>
                                        </span>
                                        <small class="text-muted ms-2"><?= date('M d, Y', strtotime($task['created_at'])) ?></small>
                                    </div>
                                    <?php if ($task['status'] !== 'Completed'): ?>
                                        <button class="btn btn-sm btn-outline-success" onclick="submitToAccomplishment(<?= $task['stask_id'] ?>)">
                                            <i class="fas fa-upload me-1"></i> Submit
                                        </button>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if ($task['status'] !== 'Completed'): ?>
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

        </div>
    </div>
</div>

<!-- Modals -->

<!-- Profile Modal -->
<div class="modal fade" id="profileModal" tabindex="-1">
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
  <div class="modal-dialog modal-lg modal-dialog-centered">
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
    // Toggle Sidebar
    document.getElementById('menu-toggle').addEventListener('click', function() {
        document.body.classList.toggle('sidebar-toggled');
    });

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
            document.querySelectorAll('.list-group-item').forEach(el => el.classList.remove('active'));
            linkElement.classList.add('active');
        }

        // Hide all views
        document.getElementById('view-dashboard').classList.add('d-none');
        document.getElementById('view-tasks').classList.add('d-none');
        document.getElementById('view-orgs').classList.add('d-none');

        // Show target view
        document.getElementById('view-' + viewName).classList.remove('d-none');
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

    /* Org hours calc removed */

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

    /* Org modal logic removed */

</script>
</body>
</html>