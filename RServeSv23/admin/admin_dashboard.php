<?php
session_start();
include "dbconnect.php";

// Only allow Administrator
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Administrator') {
    header("Location: ../home2.php");
    exit;
}

// Ensure photo column exists
$checkCol = $conn->query("SHOW COLUMNS FROM administrators LIKE 'photo'");
if ($checkCol->num_rows == 0) {
    $conn->query("ALTER TABLE administrators ADD COLUMN photo VARCHAR(255) DEFAULT NULL");
}

// Ensure duration column exists in tasks table
$checkTaskCol = $conn->query("SHOW COLUMNS FROM tasks LIKE 'duration'");
if ($checkTaskCol && $checkTaskCol->num_rows == 0) {
    $conn->query("ALTER TABLE tasks ADD COLUMN duration VARCHAR(50) DEFAULT NULL");
}

// ------------------- NOTIFICATION SYSTEM ------------------- //
// Create Notifications Table
$conn->query("CREATE TABLE IF NOT EXISTS admin_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    type VARCHAR(50),
    reference_id INT,
    student_id INT,
    message TEXT,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES administrators(adm_id) ON DELETE CASCADE
)");

// Certificate table creation removed

// Create Section Advisers Table
$conn->query("CREATE TABLE IF NOT EXISTS section_advisers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_id INT NOT NULL,
    section VARCHAR(10) NOT NULL,
    instructor_id INT NOT NULL,
    FOREIGN KEY (department_id) REFERENCES departments(department_id) ON DELETE CASCADE,
    FOREIGN KEY (instructor_id) REFERENCES instructors(inst_id) ON DELETE CASCADE,
    UNIQUE KEY unique_section_adviser (department_id, section)
)");

// Handle Mark All Read
if (isset($_POST['mark_all_read'])) {
    $adm_id = $_SESSION['adm_id'];
    $conn->query("UPDATE admin_notifications SET is_read = TRUE WHERE admin_id = $adm_id");
    exit;
}

// Lazy Notification Generation (Check for Pending items across ALL students)
$adm_id = $_SESSION['adm_id'];

// 1. Check Waivers
$pending_waivers = $conn->query("
    SELECT w.id, w.student_id, s.firstname, s.lastname, d.department_name
    FROM rss_waivers w 
    JOIN students s ON w.student_id = s.stud_id 
    JOIN departments d ON s.department_id = d.department_id
    WHERE w.status = 'Pending'
");
if ($pending_waivers) {
    while ($w = $pending_waivers->fetch_assoc()) {
        $check = $conn->query("SELECT id FROM admin_notifications WHERE type='waiver' AND reference_id={$w['id']}");
        if ($check && $check->num_rows == 0) {
            $msg = "Student {$w['firstname']} {$w['lastname']} ({$w['department_name']}) submitted a waiver.";
            $conn->query("INSERT INTO admin_notifications (admin_id, type, reference_id, student_id, message) VALUES ($adm_id, 'waiver', {$w['id']}, {$w['student_id']}, '$msg')");
        }
    }
}

// 2. Check Agreements
$pending_agreements = $conn->query("
    SELECT a.agreement_id, a.student_id, s.firstname, s.lastname, d.department_name
    FROM rss_agreements a 
    JOIN students s ON a.student_id = s.stud_id 
    JOIN departments d ON s.department_id = d.department_id
    WHERE a.status = 'Pending'
");
if ($pending_agreements) {
    while ($a = $pending_agreements->fetch_assoc()) {
        $check = $conn->query("SELECT id FROM admin_notifications WHERE type='agreement' AND reference_id={$a['agreement_id']}");
        if ($check && $check->num_rows == 0) {
            $msg = "Student {$a['firstname']} {$a['lastname']} ({$a['department_name']}) submitted an agreement form.";
            $conn->query("INSERT INTO admin_notifications (admin_id, type, reference_id, student_id, message) VALUES ($adm_id, 'agreement', {$a['agreement_id']}, {$a['student_id']}, '$msg')");
        }
    }
}

// 3. Check Enrollments
$pending_enrollments = $conn->query("
    SELECT e.enrollment_id, e.student_id, s.firstname, s.lastname, d.department_name
    FROM rss_enrollments e 
    JOIN students s ON e.student_id = s.stud_id 
    JOIN departments d ON s.department_id = d.department_id
    WHERE e.status = 'Pending'
");
if ($pending_enrollments) {
    while ($e = $pending_enrollments->fetch_assoc()) {
        $check = $conn->query("SELECT id FROM admin_notifications WHERE type='enrollment' AND reference_id={$e['enrollment_id']}");
        if ($check && $check->num_rows == 0) {
            $msg = "Student {$e['firstname']} {$e['lastname']} ({$e['department_name']}) submitted an enrollment form.";
            $conn->query("INSERT INTO admin_notifications (admin_id, type, reference_id, student_id, message) VALUES ($adm_id, 'enrollment', {$e['enrollment_id']}, {$e['student_id']}, '$msg')");
        }
    }
}

// Fetch Notifications
$notifs_query = $conn->query("SELECT * FROM admin_notifications WHERE admin_id = $adm_id ORDER BY created_at DESC");
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
// ----------------------------------------------------------- //

// Handle Adviser Assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_adviser'])) {
    // Clear any previous output
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $deptId = intval($_POST['department_id']);
    $section = $conn->real_escape_string($_POST['section']);
    $instId = intval($_POST['instructor_id']);

    if ($instId > 0) {
        // Insert or Update
        $stmt = $conn->prepare("INSERT INTO section_advisers (department_id, section, instructor_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE instructor_id = ?");
        $stmt->bind_param("isii", $deptId, $section, $instId, $instId);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Adviser assigned successfully.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        }
    } else {
        // Remove assignment if instructor_id is 0 or invalid
        // First get the current instructor_id for this section to unlink students
        $get_inst = $conn->prepare("SELECT instructor_id FROM section_advisers WHERE department_id = ? AND section = ?");
        $get_inst->bind_param("is", $deptId, $section);
        $get_inst->execute();
        $res_inst = $get_inst->get_result();
        if ($row_inst = $res_inst->fetch_assoc()) {
            $oldInstId = $row_inst['instructor_id'];
            // Unlink students from this specific instructor who are in this section and department
            $unlink = $conn->prepare("UPDATE students SET instructor_id = NULL WHERE instructor_id = ? AND department_id = ? AND section = ?");
            $unlink->bind_param("iis", $oldInstId, $deptId, $section);
            $unlink->execute();
            $unlink->close();
        }
        $get_inst->close();

        $stmt = $conn->prepare("DELETE FROM section_advisers WHERE department_id = ? AND section = ?");
        $stmt->bind_param("is", $deptId, $section);
        if ($stmt->execute()) {
             echo json_encode(['success' => true, 'message' => 'Adviser assignment removed and students unlinked.']);
        } else {
             echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        }
    }
    exit;
}

// Handle Profile Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $adm_id = $_SESSION['adm_id'];
    
    // Update Name
    if (!empty($_POST['firstname']) && !empty($_POST['lastname'])) {
        $fname = $conn->real_escape_string($_POST['firstname']);
        $lname = $conn->real_escape_string($_POST['lastname']);
        $conn->query("UPDATE administrators SET firstname='$fname', lastname='$lname' WHERE adm_id=$adm_id");
        
        $_SESSION['firstname'] = $_POST['firstname'];
        $_SESSION['lastname'] = $_POST['lastname'];
    }
    
    // Handle Photo Upload
    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = '../uploads/profiles/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $fileExt = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        
        if (in_array($fileExt, $allowed)) {
            $fileName = 'admin_' . $adm_id . '_' . time() . '.' . $fileExt;
            $targetPath = $uploadDir . $fileName;
            
            if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $targetPath)) {
                $conn->query("UPDATE administrators SET photo = '$targetPath' WHERE adm_id = $adm_id");
            }
        }
    }
    
    header("Location: admin_dashboard.php");
    exit;
}

// Handle Password Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $adm_id = $_SESSION['adm_id'];
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    $stmt = $conn->prepare("SELECT password FROM administrators WHERE adm_id = ?");
    $stmt->bind_param("i", $adm_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if ($user && password_verify($current_password, $user['password'])) {
        if ($new_password === $confirm_password) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $update_stmt = $conn->prepare("UPDATE administrators SET password = ? WHERE adm_id = ?");
            $update_stmt->bind_param("si", $hashed_password, $adm_id);
            if ($update_stmt->execute()) {
                $_SESSION['flash_success'] = "✅ Password changed successfully!";
            } else {
                $_SESSION['flash_error'] = "❌ Error updating password.";
            }
            $update_stmt->close();
        } else {
            $_SESSION['flash_error'] = "❌ New passwords do not match.";
        }
    } else {
        $_SESSION['flash_error'] = "❌ Current password is incorrect.";
    }
    header("Location: admin_dashboard.php");
    exit;
}

// Fetch Admin Data
$adm_id = $_SESSION['adm_id'];
$adminQuery = $conn->query("SELECT * FROM administrators WHERE adm_id = $adm_id");
if ($adminQuery->num_rows > 0) {
    $adminData = $adminQuery->fetch_assoc();
    $admin_name = $adminData['firstname'] . ' ' . $adminData['lastname'];
    $admin_photo = !empty($adminData['photo']) ? $adminData['photo'] : 'https://via.placeholder.com/150'; 
} else {
    $admin_name = "Administrator";
    $admin_photo = "https://via.placeholder.com/150";
}

// Department names
$deptNames = [
    1 => "College of Education",
    2 => "College of Technology",
    3 => "College of Hospitality and Tourism Management"
];

$departments = [];

// ✅ Fetch students
$students = $conn->query("
    SELECT 
        s.stud_id,
        s.firstname,
        s.lastname,
        s.email,
        s.department_id,
        COALESCE(s.year_level, 1) as year_level,
        COALESCE(s.section, 'A') as section,
        COALESCE((SELECT SUM(hours) FROM accomplishment_reports WHERE student_id = s.stud_id AND status = 'Approved'), 0) as completed_hours,
        (SELECT status FROM rss_waivers WHERE student_id = s.stud_id LIMIT 1) as waiver_status,
        (SELECT status FROM rss_agreements WHERE student_id = s.stud_id LIMIT 1) as agreement_status,
        (SELECT status FROM rss_enrollments WHERE student_id = s.stud_id LIMIT 1) as enrollment_status,
        (SELECT enrollment_id FROM rss_enrollments WHERE student_id = s.stud_id LIMIT 1) as enrollment_id
    FROM students s
    ORDER BY s.department_id, s.section, s.lastname
");

if ($students && $students->num_rows > 0) {
    while ($s = $students->fetch_assoc()) {
        $deptId = $s['department_id'];
        if (!isset($departments[$deptId])) {
            $departments[$deptId] = [
                'name' => $deptNames[$deptId] ?? "Department $deptId",
                'students' => [],
                'instructors' => [],
                'sections' => []
            ];
        }
        
        // Determine Overall Status
        $wStatus = $s['waiver_status'] ?? 'None';
        $aStatus = $s['agreement_status'] ?? 'None';
        $eStatus = $s['enrollment_status'] ?? 'None';
        $eId = $s['enrollment_id'] ?? null;
        $hours = $s['completed_hours'];
        $docsVerified = ($wStatus === 'Verified' && $aStatus === 'Verified' && $eStatus === 'Verified');
        $hoursComplete = $hours >= 300;
        
        if ($hoursComplete) {
            $overall = 'Completed';
        } elseif ($docsVerified) {
            $overall = 'Verified';
        } else {
            $overall = 'Pending';
        }
        
        $studentRow = [
            'id' => $s['stud_id'],
            'name' => $s['firstname'] . ' ' . $s['lastname'],
            'email' => $s['email'],
            'year_level' => $s['year_level'],
            'section' => $s['section'],
            'completed_hours' => $hours,
            'waiver_status' => $wStatus,
            'agreement_status' => $aStatus,
            'enrollment_status' => $eStatus,
            'enrollment_id' => $eId,
            'overall_status' => $overall
        ];
        $departments[$deptId]['students'][] = $studentRow;
        $secKey = $s['section'];
        if (!isset($departments[$deptId]['sections'][$secKey])) {
            $departments[$deptId]['sections'][$secKey] = [];
        }
        $departments[$deptId]['sections'][$secKey][] = $studentRow;
    }
}


// ✅ Fetch instructors
$instructors = $conn->query("SELECT inst_id, firstname, lastname, email, department_id FROM instructors ORDER BY department_id, lastname");
if ($instructors && $instructors->num_rows > 0) {
    while ($i = $instructors->fetch_assoc()) {
        $deptId = $i['department_id'];
        if (!isset($departments[$deptId])) {
            $departments[$deptId] = [
                'name' => $deptNames[$deptId] ?? "Department $deptId",
                'students' => [],
                'instructors' => []
            ];
        }
        $departments[$deptId]['instructors'][] = [
            'id' => $i['inst_id'],
            'name' => $i['firstname'] . ' ' . $i['lastname'],
            'email' => $i['email']
        ];
    }
}

// ✅ Fetch Section Advisers
$advisers = $conn->query("SELECT * FROM section_advisers");
if ($advisers && $advisers->num_rows > 0) {
    while ($row = $advisers->fetch_assoc()) {
        $deptId = $row['department_id'];
        $sec = $row['section'];
        $instId = $row['instructor_id'];
        if (isset($departments[$deptId])) {
            if (!isset($departments[$deptId]['advisers'])) {
                $departments[$deptId]['advisers'] = [];
            }
            $departments[$deptId]['advisers'][$sec] = $instId;
        }
    }
}

 $totalDepartments = count($departments);
 $totalStudents = 0;
 $totalInstructors = 0;
 foreach ($departments as $dept) {
     $totalStudents += count($dept['students']);
     $totalInstructors += count($dept['instructors']);
 }
 $admin_email = $_SESSION['email'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrator Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Urbanist:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-color: #1a4f7a;
            --secondary-color: #123755;
            --accent-color: #3a8ebd;
            --bg-color: #f4f7f6;
            --text-dark: #2c3e50;
            --sidebar-width: 250px;
            --card-shadow: 0 4px 6px rgba(0,0,0,0.07);
        }

        body {
            font-family: 'Urbanist', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-dark);
            overflow-x: hidden;
        }

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
            border-top: 4px solid var(--accent-color);
        }

        #sidebar-wrapper .list-group-item i {
            width: 25px;
            margin-right: 10px;
        }

        #page-content-wrapper {
            width: 100%;
            margin-left: var(--sidebar-width);
            transition: margin 0.25s ease-out;
        }

        .navbar {
            padding: 1rem 2rem;
            background: white;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            background-color: var(--primary-color);
        }

        .container-fluid {
            padding: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
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

        .content-card {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
        }

        @media (max-width: 767px) {
            #sidebar-wrapper {
                margin-left: calc(-1 * var(--sidebar-width));
            }
            #page-content-wrapper {
                margin-left: 0;
                padding-top: 110px; /* Space for taller mobile header (approx 100px) */
            }
            body.sidebar-toggled #sidebar-wrapper {
                margin-left: 0;
            }
            body.sidebar-toggled #page-content-wrapper {
                margin-left: 0;
            }
            body.sidebar-toggled::before {
                content: '';
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0,0,0,0.5);
                z-index: 999;
            }
        }

        .table th {
            font-weight: 600;
            color: var(--secondary-color);
        }
        .table td {
            vertical-align: middle;
        }

        /* Mobile Header */
        .mobile-header {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            background: var(--primary-color);
            display: flex;
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

        .mobile-header .brand-section {
            display: flex;
            align-items: center;
        }

        .mobile-header .brand-section img {
            height: 35px;
            width: auto;
            margin-right: 10px;
        }

        .mobile-header .brand-text {
            color: white;
            font-weight: 700;
            font-size: 1.1rem;
            letter-spacing: 0.5px;
        }

        .mobile-header-nav {
            display: flex;
            justify-content: space-around;
            width: 100%;
            padding-bottom: 0.5rem;
            border-top: 1px solid rgba(255,255,255,0.1);
            padding-top: 0.5rem;
        }

        /* Nav items inside header */
        .mobile-header-nav .nav-item {
            text-decoration: none;
            color: rgba(255,255,255,0.6);
            display: flex;
            flex-direction: column;
            align-items: center;
            font-size: 0.7rem;
            flex: 1;
            transition: color 0.3s;
        }

        .mobile-header-nav .nav-item i {
            font-size: 1.2rem;
            margin-bottom: 2px;
        }

        .mobile-header-nav .nav-item.active {
            color: #ffffff;
            font-weight: bold;
        }
        
        .mobile-header-nav .nav-item:hover {
            color: #ffffff;
        }

        /* Fix font sizes as requested */
        .stat-card h4 {
            font-size: 1.3rem;
            font-weight: 700;
        }

        .stat-card h3 {
            font-size: 2rem;
            font-weight: 700;
        }

        /* Fix breadcrumb font size */
        .breadcrumb-item {
            font-size: 1.3rem;
            font-weight: 600;
        }

        /* Hide menu toggle on all screens since we use mobile nav on mobile */
        #menu-toggle {
            display: none !important;
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
<div class="d-flex" id="wrapper">
    <div id="sidebar-wrapper">
        <div class="sidebar-heading">
            <i class="fas fa-user-shield me-2"></i> Administrator
        </div>
        <div class="list-group list-group-flush">
            <a href="#" class="list-group-item list-group-item-action active" data-view="dashboard" onclick="showView('dashboard')">
                <i class="fas fa-th-large"></i> Dashboard
            </a>
            <a href="#" class="list-group-item list-group-item-action" data-view="departments" onclick="showView('departments')">
                <i class="fas fa-university"></i> Departments
            </a>
            <a href="#" class="list-group-item list-group-item-action" data-view="reports" onclick="showView('reports')">
                <i class="fas fa-chart-bar"></i> Reports
            </a>
            <a href="#" class="list-group-item list-group-item-action" data-view="advisory" onclick="showView('advisory')">
                <i class="fas fa-chalkboard-teacher"></i> Advisory Management
            </a>
            <a href="#" class="list-group-item list-group-item-action" data-view="users" onclick="showView('users')">
                <i class="fas fa-users-cog"></i> User Management
            </a>
            <a href="#" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" data-view="notifications" onclick="showView('notifications')">
                <span><i class="fas fa-bell"></i> Notifications</span>
                <?php if ($unread_notifs > 0): ?>
                    <span class="badge bg-danger rounded-pill"><?php echo $unread_notifs; ?></span>
                <?php endif; ?>
            </a>
            <a href="logout.php" class="list-group-item list-group-item-action mt-auto">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>

    <div id="page-content-wrapper">
        <nav class="navbar navbar-expand-lg navbar-light d-none d-md-flex">
            
            <div class="ms-auto d-flex align-items-center">
                <!-- Name and Role (Hidden on very small screens, visible on md+) -->
                <!-- Profile Image (Always visible, acts as trigger for modal) -->
                <div class="position-relative" style="cursor: pointer;" data-bs-toggle="modal" data-bs-target="#profileModal">
                  
                </div>
            </div>
        </nav>

        <div class="container-fluid">
            <?php if (isset($_SESSION['flash_success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo $_SESSION['flash_success']; unset($_SESSION['flash_success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if (isset($_SESSION['flash_error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo $_SESSION['flash_error']; unset($_SESSION['flash_error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if (isset($_SESSION['flash'])): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <?php echo $_SESSION['flash']; unset($_SESSION['flash']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <div id="dashboard-view">
                <h2 class="mb-4">Dashboard Overview</h2>
                <div class="row g-4 mb-4">
                    <div class="col-md-4">
                        <div class="stat-card">
                            <div class="stat-icon"><i class="fas fa-building"></i></div>
                            <h3><?php echo $totalDepartments; ?></h3>
                            <p class="text-muted">Departments</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card">
                            <div class="stat-icon"><i class="fas fa-user-graduate"></i></div>
                            <h3><?php echo $totalStudents; ?></h3>
                            <p class="text-muted">Students</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card">
                            <div class="stat-icon"><i class="fas fa-chalkboard-teacher"></i></div>
                            <h3><?php echo $totalInstructors; ?></h3>
                            <p class="text-muted">Advisers</p>
                        </div>
                    </div>
                </div>

                <div class="content-card">
                    <h4 class="mb-3">Welcome</h4>
                    <p class="mb-1"><i class="fas fa-envelope me-2"></i><?php echo htmlspecialchars($admin_email); ?></p>
                    <p class="text-muted mb-0">Use the navigation to review departments, students, and advisers across the system.</p>
                </div>
            </div>

            <div id="departments-view" style="display:none;">
                <div id="admin-dept-nav" class="mb-4">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <h2 class="mb-4">Departments</h2>
                        </ol>
                    </nav>
                </div>
                <div id="admin-dept-content" class="row g-4"></div>
            </div>

            <!-- Reports View -->
            <div id="reports-view" style="display:none;">
                <h2 class="mb-4">Reports & Analytics</h2>
                <div id="reports-content"></div>
            </div>

            <!-- Advisory Management View -->
            <div id="advisory-view" style="display:none;">
                <h2 class="mb-4">Advisory Management</h2>
                <div id="advisory-content"></div>
            </div>

            <!-- User Management View -->
            <div id="users-view" style="display:none;">
                <h2 class="mb-4">User Management</h2>
                <div class="row">
                    <div class="col-md-8 col-lg-6">
                        <div class="content-card">
                            <h4 class="mb-4">Create New Account</h4>
                            <form action="create_account.php" method="POST">
                                <div class="mb-3">
                                    <label class="form-label">Role</label>
                                    <select name="role" class="form-select" required>
                                        <option value="">Select Role</option>
                                        <option value="Instructor">Adviser (Instructor)</option>
                                        <option value="Coordinator">Coordinator</option>
                                    </select>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">First Name</label>
                                        <input type="text" name="firstname" class="form-control" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Last Name</label>
                                        <input type="text" name="lastname" class="form-control" required>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Email Address</label>
                                    <input type="email" name="email" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Department</label>
                                    <select name="department" class="form-select" required>
                                        <option value="">Select Department</option>
                                        <option value="1">College of Education</option>
                                        <option value="2">College of Technology</option>
                                        <option value="3">College of Hospitality and Tourism Management</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-primary w-100">Create Account & Send Credentials</button>
                            </form>
                        </div>
                    </div>
                    <div class="col-md-4 col-lg-6">
                        <div class="content-card bg-light">
                            <h5><i class="fas fa-info-circle me-2"></i>Note</h5>
                            <p>When you create an account, a random password will be generated and sent to the user's email address.</p>
                            <p>The user will be required to change their password upon first login (if implemented) or they should change it immediately.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Notifications View -->
            <div id="notifications-view" style="display:none;">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>Notifications</h2>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="mark_all_read" value="1">
                        <button type="submit" class="btn btn-outline-primary btn-sm">Mark All as Read</button>
                    </form>
                </div>
                <div class="list-group" id="notifications-list">
                    <!-- JS will populate -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Mobile Header -->
<div class="mobile-header d-md-none">
    <div class="mobile-header-top">
        <div class="brand-section">
            <img src="../img/logo.png" alt="RServeS Logo">
            <span class="brand-text">RServeS</span>
        </div>
        
        <div class="profile-section" style="cursor: pointer; display: flex; align-items: center;" data-bs-toggle="modal" data-bs-target="#profileModal">
            <span class="text-white me-2 fw-bold" style="font-size: 0.9rem;">Administrator</span>
            <img src="<?php echo htmlspecialchars($admin_photo); ?>" alt="Profile" 
                 class="rounded-circle border border-2 border-white" 
                 style="width: 35px; height: 35px; object-fit: cover;">
        </div>
    </div>
    
    <!-- Nav Row -->
    <div class="mobile-header-nav">
        <a href="#" class="nav-item active" data-view="dashboard" onclick="showView('dashboard')">
            <i class="fas fa-th-large"></i>
            <span>Dashboard</span>
        </a>
        <a href="#" class="nav-item" data-view="departments" onclick="showView('departments')">
            <i class="fas fa-university"></i>
            <span>Departments</span>
        </a>
        <a href="#" class="nav-item" data-view="reports" onclick="showView('reports')">
            <i class="fas fa-chart-bar"></i>
            <span>Reports</span>
        </a>
        <a href="#" class="nav-item" data-view="advisory" onclick="showView('advisory')">
            <i class="fas fa-chalkboard-teacher"></i>
            <span>Advisory</span>
        </a>
        <a href="#" class="nav-item" data-view="users" onclick="showView('users')">
            <i class="fas fa-users-cog"></i>
            <span>Users</span>
        </a>
        <a href="#" class="nav-item position-relative" data-view="notifications" onclick="showView('notifications')">
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



<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const adminDepartments = <?php echo json_encode($departments); ?>;
    const adminNotifications = <?php echo json_encode($notifications); ?>;

    // Menu toggle logic removed as we use mobile nav on mobile and sidebar on desktop

    function showView(view) {
        // Update active class for both sidebar and mobile nav based on data-view
        document.querySelectorAll('[data-view]').forEach(function(item) {
            if (item.getAttribute('data-view') === view) {
                item.classList.add('active');
            } else {
                item.classList.remove('active');
            }
        });

        const dashboardView = document.getElementById('dashboard-view');
        const departmentsView = document.getElementById('departments-view');
        const reportsView = document.getElementById('reports-view');
        const advisoryView = document.getElementById('advisory-view');
        const notificationsView = document.getElementById('notifications-view');
        const usersView = document.getElementById('users-view');
        
        if (dashboardView) dashboardView.style.display = view === 'dashboard' ? 'block' : 'none';
        if (departmentsView) departmentsView.style.display = view === 'departments' ? 'block' : 'none';
        if (reportsView) reportsView.style.display = view === 'reports' ? 'block' : 'none';
        if (advisoryView) advisoryView.style.display = view === 'advisory' ? 'block' : 'none';
        if (notificationsView) notificationsView.style.display = view === 'notifications' ? 'block' : 'none';
        if (usersView) usersView.style.display = view === 'users' ? 'block' : 'none';
        
        if (view === 'departments') {
            renderAdminDepartments();
        } else if (view === 'reports') {
            renderReports();
        } else if (view === 'advisory') {
            renderAdvisoryManagement();
        } else if (view === 'notifications') {
            renderNotifications();
        }
        
        window.scrollTo(0, 0);
    }

    function renderAdminDepartments() {
        const nav = document.querySelector('#admin-dept-nav .breadcrumb');
        const content = document.getElementById('admin-dept-content');
        if (!nav || !content) return;
        nav.innerHTML = '<h2 class="mb-4">Departments</h2>';
        content.innerHTML = '';
        const ids = Object.keys(adminDepartments || {}).sort(function(a, b) {
            return parseInt(a, 10) - parseInt(b, 10);
        });
        if (ids.length === 0) {
            content.innerHTML = '<p class="text-muted">No departments found.</p>';
            return;
        }
        ids.forEach(function(id) {
            const dept = adminDepartments[id];
            const col = document.createElement('div');
            col.className = 'col-md-4';
            col.innerHTML = `
                <div class="stat-card" style="cursor: pointer;" onclick="viewDepartment(${id})">
                    <div class="stat-icon"><i class="fas fa-university"></i></div>
                    <h4>${dept.name}</h4>
                    <p class="text-muted mb-0">${dept.students.length} Students</p>
                    <p class="text-muted mb-0">${dept.instructors.length} Advisers</p>
                </div>
            `;
            content.appendChild(col);
        });
    }

    function viewDepartment(deptId) {
        renderAdminDepartmentOptions(deptId);
    }

    function renderAdvisoryManagement() {
        const container = document.getElementById('advisory-content');
        if (!container) return;
        
        let html = `
            <div class="content-card">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Department</th>
                                <th>Section</th>
                                <th>Assigned Adviser</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
        `;

        // Loop through each department and its sections
        Object.keys(adminDepartments).forEach(deptId => {
            const dept = adminDepartments[deptId];
            const sections = dept.sections || {};
            const assignedAdvisers = dept.advisers || {};

            Object.keys(sections).sort().forEach(sec => {
                const instructorId = assignedAdvisers[sec];
                let adviserName = '<span class="text-muted">No adviser assigned</span>';
                let actionBtn = '';

                if (instructorId) {
                    const instructor = dept.instructors.find(i => i.id == instructorId);
                    if (instructor) {
                        adviserName = `<span class="fw-bold text-primary">${instructor.name}</span><br><small class="text-muted">${instructor.email}</small>`;
                        actionBtn = `
                            <button class="btn btn-danger btn-sm" onclick="removeAdviser(${deptId}, '${sec}')">
                                <i class="fas fa-user-minus me-1"></i> Remove Adviser
                            </button>
                        `;
                    }
                }

                html += `
                    <tr>
                        <td>${dept.name}</td>
                        <td><span class="badge bg-secondary">Section ${sec}</span></td>
                        <td>${adviserName}</td>
                        <td>${actionBtn}</td>
                    </tr>
                `;
            });
        });

        html += `
                        </tbody>
                    </table>
                </div>
            </div>
        `;
        
        container.innerHTML = html;
    }

    function removeAdviser(deptId, section) {
        if (!confirm(`Are you sure you want to remove the adviser for Section ${section}? This will allow other advisers to apply for this section.`)) return;

        const formData = new FormData();
        formData.append('assign_adviser', '1');
        formData.append('department_id', deptId);
        formData.append('section', section);
        formData.append('instructor_id', '0'); // 0 means remove

        fetch('admin_dashboard.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update local data
                if (adminDepartments[deptId] && adminDepartments[deptId].advisers) {
                    delete adminDepartments[deptId].advisers[section];
                }
                renderAdvisoryManagement();
                alert('Adviser removed successfully.');
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An unexpected error occurred.');
        });
    }

    function renderReports() {
        const container = document.getElementById('reports-content');
        if (!container) return;
        
        // Aggregate Data
        let stats = {
            waiver: { Verified: 0, Pending: 0, Rejected: 0, None: 0 },
            agreement: { Verified: 0, Pending: 0, Rejected: 0, None: 0 },
            overall: { Completed: 0, Verified: 0, Pending: 0 }
        };

        let lists = {
            Completed: [],
            Verified: [],
            Pending: []
        };
        
        Object.values(adminDepartments).forEach(dept => {
            dept.students.forEach(s => {
                // Waiver
                let w = s.waiver_status || 'None';
                if (stats.waiver[w] !== undefined) stats.waiver[w]++;
                
                // Agreement
                let a = s.agreement_status || 'None';
                if (stats.agreement[a] !== undefined) stats.agreement[a]++;
                
                // Overall
                let o = s.overall_status || 'Pending';
                if (stats.overall[o] !== undefined) stats.overall[o]++;

                // Add to list with department name
                if (lists[o]) {
                    lists[o].push({ ...s, deptName: dept.name });
                } else {
                    if (!lists['Pending']) lists['Pending'] = [];
                    lists['Pending'].push({ ...s, deptName: dept.name });
                }
            });
        });

        // Helper to generate table HTML
        const generateTable = (students, type) => {
            if (students.length === 0) return `<p class="text-muted p-3">No students found in this category.</p>`;
            
            const isCompleted = type === 'Completed';

            let rows = students.map(s => `
                <tr>
                    <td>${s.name}</td>
                    <td>${s.deptName}</td>
                    <td>${s.year_level}</td>
                    <td>${s.section}</td>
                    <td>${s.completed_hours}</td>
                    <td><span class="badge ${type === 'Completed' ? 'bg-success' : (type === 'Verified' ? 'bg-info text-dark' : 'bg-warning text-dark')}">${s.overall_status}</span></td>
                    ${isCompleted ? '' : ''}
                </tr>
            `).join('');
            
            return `
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Name</th>
                                <th>Department</th>
                                <th>Year</th>
                                <th>Section</th>
                                <th>Hours</th>
                                <th>Status</th>
                                ${isCompleted ? '' : ''}
                            </tr>
                        </thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>
            `;
        };
        
        container.innerHTML = `
            <div class="row g-4 mb-4">
                <div class="col-md-6">
                    <div class="content-card h-100">
                        <h5 class="mb-4">Document Verification Status (System-Wide)</h5>
                        <div style="height: 300px;">
                            <canvas id="adminDocChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="content-card h-100">
                        <h5 class="mb-4">Overall Completion Status (System-Wide)</h5>
                        <div style="height: 300px; display: flex; justify-content: center">
                            <canvas id="adminCompletionChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="content-card">
                <h4 class="mb-4">Student Status Details</h4>
                <ul class="nav nav-tabs mb-3" id="reportTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="completed-tab" data-bs-toggle="tab" data-bs-target="#completed" type="button" role="tab">Completed (${lists.Completed.length})</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="verified-tab" data-bs-toggle="tab" data-bs-target="#verified" type="button" role="tab">Verified (Incomplete) (${lists.Verified.length})</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="pending-tab" data-bs-toggle="tab" data-bs-target="#pending" type="button" role="tab">Pending (${lists.Pending.length})</button>
                    </li>
                </ul>
                <div class="tab-content" id="reportTabsContent">
                    <div class="tab-pane fade show active" id="completed" role="tabpanel">
                        ${generateTable(lists.Completed, 'Completed')}
                    </div>
                    <div class="tab-pane fade" id="verified" role="tabpanel">
                        ${generateTable(lists.Verified, 'Verified')}
                    </div>
                    <div class="tab-pane fade" id="pending" role="tabpanel">
                        ${generateTable(lists.Pending, 'Pending')}
                    </div>
                </div>
            </div>
        `;
        
        // Render Charts
        new Chart(document.getElementById('adminDocChart'), {
            type: 'bar',
            data: {
                labels: ['Waiver', 'Agreement'],
                datasets: [
                    { label: 'Verified', data: [stats.waiver.Verified, stats.agreement.Verified], backgroundColor: '#198754' },
                    { label: 'Pending', data: [stats.waiver.Pending, stats.agreement.Pending], backgroundColor: '#ffc107' },
                    { label: 'Rejected', data: [stats.waiver.Rejected, stats.agreement.Rejected], backgroundColor: '#dc3545' }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, scales: { x: { stacked: true }, y: { stacked: true } } }
        });
        
        new Chart(document.getElementById('adminCompletionChart'), {
            type: 'doughnut',
            data: {
                labels: ['Completed', 'Verified', 'Pending'],
                datasets: [{
                    data: [stats.overall.Completed, stats.overall.Verified, stats.overall.Pending],
                    backgroundColor: ['#198754', '#0dcaf0', '#ffc107']
                }]
            },
            options: { responsive: true, maintainAspectRatio: false }
        });
    }
    
    function renderNotifications() {
        const list = document.getElementById('notifications-list');
        if (!list) return;
        
        if (adminNotifications.length === 0) {
            list.innerHTML = '<div class="list-group-item text-center text-muted">No notifications found.</div>';
            return;
        }
        
        list.innerHTML = adminNotifications.map(n => `
            <div class="list-group-item list-group-item-action ${!n.is_read ? 'bg-light' : ''}">
                <div class="d-flex w-100 justify-content-between">
                    <h5 class="mb-1">${n.type.charAt(0).toUpperCase() + n.type.slice(1)} Notification</h5>
                    <small class="text-muted">${new Date(n.created_at).toLocaleDateString()}</small>
                </div>
                <p class="mb-1">${n.message}</p>
            </div>
        `).join('');
    }

    // Certificate generation removed

    function renderAdminDepartmentOptions(deptId) {
        const nav = document.querySelector('#admin-dept-nav .breadcrumb');
        const content = document.getElementById('admin-dept-content');
        if (!nav || !content) return;
        const dept = adminDepartments[deptId];
        if (!dept) return;
        
        nav.innerHTML =
            '<li class="breadcrumb-item"><a href="#" onclick="renderAdminDepartments(); return false;">Departments</a></li>' +
            '<li class="breadcrumb-item active" aria-current="page">' + dept.name + '</li>';
            
        content.innerHTML = '';
        
        const studentCount = (dept.students || []).length;
        const instructorCount = (dept.instructors || []).length;

        const html = 
            '<div class="col-md-6">' +
                '<div class="content-card mb-0" style="cursor:pointer" onclick="renderAdminStudentSections(\'' + deptId + '\')">' +
                    '<div class="year-icon mb-2"><i class="fas fa-user-graduate"></i></div>' +
                    '<h5 class="mb-1">Students</h5>' +
                    '<p class="text-muted small mb-0">' + studentCount + ' Students</p>' +
                    '<p class="text-muted small mt-2">View sections and student lists</p>' +
                '</div>' +
            '</div>' +
            '<div class="col-md-6">' +
                '<div class="content-card mb-0" style="cursor:pointer" onclick="renderAdminInstructors(\'' + deptId + '\')">' +
                    '<div class="year-icon mb-2"><i class="fas fa-chalkboard-teacher"></i></div>' +
                    '<h5 class="mb-1">Advisers</h5>' +
                    '<p class="text-muted small mb-0">' + instructorCount + ' Advisers</p>' +
                    '<p class="text-muted small mt-2">View list of Advisers</p>' +
                '</div>' +
            '</div>';
            
        content.innerHTML = html;
    }

    function renderAdminStudentSections(deptId) {
        const nav = document.querySelector('#admin-dept-nav .breadcrumb');
        const content = document.getElementById('admin-dept-content');
        if (!nav || !content) return;
        const dept = adminDepartments[deptId];
        if (!dept) return;

        nav.innerHTML =
            '<li class="breadcrumb-item"><a href="#" onclick="renderAdminDepartments(); return false;">Departments</a></li>' +
            '<li class="breadcrumb-item"><a href="#" onclick="renderAdminDepartmentOptions(\'' + deptId + '\'); return false;">' + dept.name + '</a></li>' +
            '<li class="breadcrumb-item active" aria-current="page">Students</li>';

        content.innerHTML = '';
        const sections = dept.sections || {};
        const sectionKeys = Object.keys(sections).sort();
        
        if (sectionKeys.length === 0) {
            content.innerHTML = '<div class="col-12 text-center text-muted">No student sections found.</div>';
            return;
        }

        const instructors = dept.instructors || [];
        const advisers = dept.advisers || {};

        let html = '';
        sectionKeys.forEach(function(label) {
            const students = sections[label] || [];
            const currentAdviser = advisers[label] || 0;
            
            let options = '<option value="0">-- Select Adviser --</option>';
            instructors.forEach(inst => {
                const selected = (inst.id == currentAdviser) ? 'selected' : '';
                options += `<option value="${inst.id}" ${selected}>${inst.name}</option>`;
            });

            html +=
                '<div class="col-md-6 col-lg-4">' +
                    '<div class="content-card mb-0" style="cursor:pointer" onclick="renderAdminSectionStudents(\'' + deptId + '\', \'' + label + '\')">' +
                        '<div class="d-flex justify-content-between align-items-start">' +
                            '<div>' +
                                '<div class="year-icon mb-2"><i class="fas fa-layer-group"></i></div>' +
                                '<h5 class="mb-1">Section ' + label + '</h5>' +
                                '<p class="text-muted small mb-0">' + students.length + ' Students</p>' +
                            '</div>' +
                            '<div onclick="event.stopPropagation()">' +
                                '<small class="d-block text-muted mb-1">Adviser:</small>' +
                                '<select class="form-select form-select-sm" style="width: 150px;" onchange="assignAdviser(' + deptId + ', \'' + label + '\', this.value)">' +
                                    options +
                                '</select>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                '</div>';
        });
        content.innerHTML = html;
    }

    function assignAdviser(deptId, section, instId) {
        const formData = new FormData();
        formData.append('assign_adviser', '1');
        formData.append('department_id', deptId);
        formData.append('section', section);
        formData.append('instructor_id', instId);

        fetch('admin_dashboard.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update local model
                if (!adminDepartments[deptId].advisers) {
                    adminDepartments[deptId].advisers = {};
                }
                adminDepartments[deptId].advisers[section] = instId;
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Failed to assign adviser.');
        });
    }

    function renderAdminInstructors(deptId) {
        const nav = document.querySelector('#admin-dept-nav .breadcrumb');
        const content = document.getElementById('admin-dept-content');
        if (!nav || !content) return;
        const dept = adminDepartments[deptId];
        if (!dept) return;
        
        nav.innerHTML =
            '<li class="breadcrumb-item"><a href="#" onclick="renderAdminDepartments(); return false;">Departments</a></li>' +
            '<li class="breadcrumb-item"><a href="#" onclick="renderAdminDepartmentOptions(\'' + deptId + '\'); return false;">' + dept.name + '</a></li>' +
            '<li class="breadcrumb-item active" aria-current="page">Advisers</li>';
            
        const instructors = dept.instructors || [];
        
        if (instructors.length === 0) {
            content.innerHTML = '<div class="col-12 text-center text-muted">No advisers found in this department.</div>';
            return;
        }

        let rows = '';
        instructors.forEach(function(i) {
            rows +=
                '<tr>' +
                    '<td>' + i.name + '</td>' +
                    '<td>' + i.email + '</td>' +
                '</tr>';
        });

        content.innerHTML =
            '<div class="col-12">' +
                '<div class="content-card">' +
                    '<h4 class="mb-4">Advisers - ' + dept.name + '</h4>' +
                    '<div class="table-responsive">' +
                        '<table class="table table-hover align-middle">' +
                            '<thead>' +
                                '<tr>' +
                                    '<th>Instructor Name</th>' +
                                    '<th>Email</th>' +
                                '</tr>' +
                            '</thead>' +
                            '<tbody>' + rows + '</tbody>' +
                        '</table>' +
                    '</div>' +
                '</div>' +
            '</div>';
    }

    function renderAdminSectionStudents(deptId, sectionLabel) {
        const nav = document.querySelector('#admin-dept-nav .breadcrumb');
        const content = document.getElementById('admin-dept-content');
        if (!nav || !content) return;
        const dept = adminDepartments[deptId];
        if (!dept) return;
        const sections = dept.sections || {};
        const students = sections[sectionLabel] || [];
        nav.innerHTML =
            '<li class="breadcrumb-item"><a href="#" onclick="renderAdminDepartments(); return false;">Departments</a></li>' +
            '<li class="breadcrumb-item"><a href="#" onclick="renderAdminDepartmentOptions(\'' + deptId + '\'); return false;">' + dept.name + '</a></li>' +
            '<li class="breadcrumb-item"><a href="#" onclick="renderAdminStudentSections(\'' + deptId + '\'); return false;">Students</a></li>' +
            '<li class="breadcrumb-item active" aria-current="page">Section ' + sectionLabel + '</li>';
        if (students.length === 0) {
            content.innerHTML = '<div class="col-12 text-center text-muted">No students found in this section.</div>';
            return;
        }
        let rows = '';
        students.forEach(function(s) {
            let enrollBadge = '<span class="badge bg-secondary">None</span>';
            if (s.enrollment_status === 'Pending') enrollBadge = '<span class="badge bg-warning text-dark">Pending</span>';
            else if (s.enrollment_status === 'Verified') enrollBadge = '<span class="badge bg-success">Verified</span>';
            else if (s.enrollment_status === 'Rejected') enrollBadge = '<span class="badge bg-danger">Rejected</span>';

            let actionBtn = '';
            if (s.enrollment_id) {
                actionBtn = '<a href="verify_enrollment.php?id=' + s.enrollment_id + '" class="btn btn-sm btn-outline-primary ms-2">View Enrollment</a>';
            }

            rows +=
                '<tr>' +
                    '<td>' +
                        '<div class="fw-semibold">' + s.name + '</div>' +
                        '<div class="small text-muted"><span class="me-2">' + s.email + '</span></div>' +
                    '</td>' +
                    '<td>' + enrollBadge + '</td>' +
                    '<td class="text-end">' +
                        '<span class="badge bg-light text-dark">' +
                            '<i class="fas fa-clock me-1"></i>' + s.completed_hours + '/300' +
                        '</span>' +
                        actionBtn +
                    '</td>' +
                '</tr>';
        });
        content.innerHTML =
            '<div class="col-12">' +
                '<div class="content-card">' +
                    '<h4 class="mb-4">Students - ' + dept.name + ' / Section ' + sectionLabel + '</h4>' +
                    '<div class="table-responsive">' +
                        '<table class="table table-hover align-middle">' +
                            '<thead>' +
                                '<tr>' +
                                    '<th>Student</th>' +
                                    '<th>Enrollment</th>' +
                                    '<th class="text-end">Actions</th>' +
                                '</tr>' +
                            '</thead>' +
                            '<tbody>' + rows + '</tbody>' +
                        '</table>' +
                    '</div>' +
                '</div>' +
            '</div>';
    }
</script>
<!-- Profile Modal -->
<div class="modal fade" id="profileModal" tabindex="-1" style="z-index: 10000;">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Profile</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body text-center">
                    <img src="<?php echo htmlspecialchars($admin_photo); ?>" class="rounded-circle mb-3 border" style="width: 120px; height: 120px; object-fit: cover;">
                    <div class="mb-3 text-start">
                        <label class="form-label">First Name</label>
                        <input type="text" name="firstname" class="form-control" value="<?php echo htmlspecialchars($adminData['firstname'] ?? ''); ?>" required>
                    </div>
                    <div class="mb-3 text-start">
                        <label class="form-label">Last Name</label>
                        <input type="text" name="lastname" class="form-control" value="<?php echo htmlspecialchars($adminData['lastname'] ?? ''); ?>" required>
                    </div>
                    <div class="mb-3 text-start">
                        <label class="form-label">Update Profile Photo</label>
                        <input type="file" name="profile_photo" class="form-control" accept="image/*">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="update_profile" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
            <hr>
            <form method="POST">
                <div class="modal-body text-start pt-0">
                    <h6 class="mb-3 fw-bold">Change Password</h6>
                    <div class="mb-2">
                        <label class="form-label small">Current Password</label>
                        <div class="password-container">
                            <input type="password" name="current_password" class="form-control form-control-sm" required id="admin_current_password">
                            <i class="fas fa-eye-slash password-toggle-icon" onclick="togglePasswordVisibility(this, 'admin_current_password')"></i>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">New Password</label>
                        <div class="password-container">
                            <input type="password" name="new_password" class="form-control form-control-sm" required id="admin_new_password">
                            <i class="fas fa-eye-slash password-toggle-icon" onclick="togglePasswordVisibility(this, 'admin_new_password')"></i>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Confirm New Password</label>
                        <div class="password-container">
                            <input type="password" name="confirm_password" class="form-control form-control-sm" required id="admin_confirm_password">
                            <i class="fas fa-eye-slash password-toggle-icon" onclick="togglePasswordVisibility(this, 'admin_confirm_password')"></i>
                        </div>
                    </div>
                    <button type="submit" name="change_password" class="btn btn-warning btn-sm w-100">Update Password</button>
                </div>
            </form>
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

window.addEventListener('load', function() {
    const loader = document.getElementById('rserve-page-loader');
    if (!loader) return;
    loader.classList.add('rserve-page-loader--hide');
    window.setTimeout(() => {
        if (loader && loader.parentNode) loader.parentNode.removeChild(loader);
    }, 420);
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
