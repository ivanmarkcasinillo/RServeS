<?php
//coordinator
session_start();
require "dbconnect.php";

// Auto-Setup DB Tables (Lazy Init)
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

$conn->query("CREATE TABLE IF NOT EXISTS rss_agreements (
    agreement_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    file_path VARCHAR(255),
    student_signature VARCHAR(255),
    parent_signature VARCHAR(255),
    status ENUM('Pending', 'Verified', 'Rejected') DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    verified_at TIMESTAMP NULL,
    verified_by INT NULL,
    FOREIGN KEY (student_id) REFERENCES students(stud_id) ON DELETE CASCADE
)");

// Ensure columns exist and have correct ENUM values
$conn->query("ALTER TABLE rss_enrollments ADD COLUMN IF NOT EXISTS status ENUM('Pending', 'Verified', 'Rejected') DEFAULT 'Pending'");
$conn->query("ALTER TABLE rss_enrollments MODIFY COLUMN status ENUM('Pending', 'Verified', 'Rejected') DEFAULT 'Pending'");
$conn->query("ALTER TABLE rss_enrollments ADD COLUMN IF NOT EXISTS signature_image LONGTEXT");

$conn->query("ALTER TABLE rss_agreements ADD COLUMN IF NOT EXISTS status ENUM('Pending', 'Verified', 'Rejected') DEFAULT 'Pending'");
$conn->query("ALTER TABLE rss_agreements MODIFY COLUMN status ENUM('Pending', 'Verified', 'Rejected') DEFAULT 'Pending'");
$conn->query("ALTER TABLE rss_agreements ADD COLUMN IF NOT EXISTS file_path VARCHAR(255)");
$conn->query("ALTER TABLE rss_agreements ADD COLUMN IF NOT EXISTS student_signature VARCHAR(255)");
$conn->query("ALTER TABLE rss_agreements ADD COLUMN IF NOT EXISTS parent_signature VARCHAR(255)");
$conn->query("ALTER TABLE rss_agreements ADD COLUMN IF NOT EXISTS verified_at TIMESTAMP NULL");
$conn->query("ALTER TABLE rss_agreements ADD COLUMN IF NOT EXISTS verified_by INT NULL");

// Ensure coordinator_notifications table exists
$conn->query("CREATE TABLE IF NOT EXISTS coordinator_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    coordinator_id INT NOT NULL,
    student_id INT NOT NULL,
    type ENUM('waiver', 'agreement', 'enrollment', 'task', 'other') NOT NULL,
    reference_id INT NOT NULL,
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(stud_id) ON DELETE CASCADE
)");


// Restrict to Coordinators
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Coordinator') {
    header("Location: ../home2.php");
    exit;
}

$email = $_SESSION['email'];

// Fetch coordinator info
$stmt = $conn->prepare("SELECT coor_id, firstname, mi, lastname, photo FROM coordinator WHERE email=?");
$stmt->bind_param("s", $email);
$stmt->execute();
$coord = $stmt->get_result()->fetch_assoc();
$stmt->close();

$mi_val = trim($coord['mi']);
$coord_name = $coord['lastname'] . ', ' . $coord['firstname'] . ($mi_val ? ' ' . $mi_val : '');
$coord_photo = $coord['photo'] ?: 'default_profile.png';
if ($coord_photo !== 'default_profile.png' && !str_starts_with($coord_photo, 'uploads/')) {
     $coord_photo = 'uploads/' . $coord_photo;
}

// Handle Mark All Read
if (isset($_POST['mark_all_read'])) {
    $conn->query("UPDATE coordinator_notifications SET is_read = TRUE WHERE coordinator_id = {$coord['coor_id']}");
    exit;
}

/* -------------------  CHANGE PASSWORD ------------------- */
if (isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    $coor_id = $coord['coor_id'];

    $stmt = $conn->prepare("SELECT password FROM coordinator WHERE coor_id = ?");
    $stmt->bind_param("i", $coor_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res->fetch_assoc();
    $stmt->close();

    if ($user && password_verify($current_password, $user['password'])) {
        if ($new_password === $confirm_password) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $up = $conn->prepare("UPDATE coordinator SET password = ? WHERE coor_id = ?");
            $up->bind_param("si", $hashed_password, $coor_id);
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

// Lazy Notification Generation
// 1. Check Waivers
$pending_waivers = $conn->query("
    SELECT w.id, w.student_id, s.firstname, s.lastname 
    FROM rss_waivers w 
    JOIN students s ON w.student_id = s.stud_id 
    WHERE w.status = 'Pending'
");
if ($pending_waivers) {
    while ($w = $pending_waivers->fetch_assoc()) {
        $check = $conn->query("SELECT id FROM coordinator_notifications WHERE type='waiver' AND reference_id={$w['id']}");
        if ($check && $check->num_rows == 0) {
            $msg = "Student {$w['firstname']} {$w['lastname']} submitted a waiver.";
            $conn->query("INSERT INTO coordinator_notifications (coordinator_id, type, reference_id, student_id, message) VALUES ({$coord['coor_id']}, 'waiver', {$w['id']}, {$w['student_id']}, '$msg')");
        }
    }
}

// 2. Check Agreements
$pending_agreements = $conn->query("
    SELECT a.agreement_id, a.student_id, s.firstname, s.lastname 
    FROM rss_agreements a 
    JOIN students s ON a.student_id = s.stud_id 
    WHERE a.status = 'Pending'
");
if ($pending_agreements) {
    while ($a = $pending_agreements->fetch_assoc()) {
        $check = $conn->query("SELECT id FROM coordinator_notifications WHERE type='agreement' AND reference_id={$a['agreement_id']}");
        if ($check && $check->num_rows == 0) {
            $msg = "Student {$a['firstname']} {$a['lastname']} submitted an agreement form.";
            $conn->query("INSERT INTO coordinator_notifications (coordinator_id, type, reference_id, student_id, message) VALUES ({$coord['coor_id']}, 'agreement', {$a['agreement_id']}, {$a['student_id']}, '$msg')");
        }
    }
}

// Fetch Notifications
$notifs_query = $conn->query("SELECT * FROM coordinator_notifications WHERE coordinator_id = {$coord['coor_id']} ORDER BY created_at DESC");
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

// Fetch students grouped by year level and section
$sql = "
  SELECT 
    s.stud_id, 
    s.firstname, 
    s.mi, 
    s.lastname, 
    s.email, 
    COALESCE(s.year_level, 1) as year_level,
    COALESCE(s.section, 'A') as section,
    d.department_name
  FROM students s
  JOIN departments d ON s.department_id = d.department_id
  WHERE d.department_id = 2
  ORDER BY d.department_name, s.year_level, s.section, s.lastname
";

$result = $conn->query($sql);
$students_by_year = [];
$total_students = 0;
$pending_verifications = 0;
$completed_students = 0;

while ($row = $result->fetch_assoc()) {
    $year = $row['year_level'];
    $section = $row['section'];
    
    // Get completed hours (Synced with Student Dashboard)
    $hours_sql = "
        SELECT COALESCE(SUM(hours), 0) as total_hours 
        FROM accomplishment_reports 
        WHERE student_id = {$row['stud_id']} AND status = 'Approved'
    ";
    $ar_query = $conn->query($hours_sql);
    $ar_result = $ar_query ? $ar_query->fetch_assoc() : ['total_hours' => 0];
    $row['completed_hours'] = $ar_result['total_hours'] ?? 0;
    
    // Get document statuses
    // Waiver
    $w_q = $conn->query("SELECT status FROM rss_waivers WHERE student_id = {$row['stud_id']}");
    $row['waiver_status'] = ($w_q && $w_q->num_rows > 0) ? $w_q->fetch_assoc()['status'] : 'None';

    // Agreement
    $a_q = $conn->query("SELECT status FROM rss_agreements WHERE student_id = {$row['stud_id']}");
    $row['agreement_status'] = ($a_q && $a_q->num_rows > 0) ? $a_q->fetch_assoc()['status'] : 'None';

    // Enrollment (Removed from logic as requested)
    // $e_q = $conn->query("SELECT status FROM rss_enrollments WHERE student_id = {$row['stud_id']}");
    // $row['enrollment_status'] = ($e_q && $e_q->num_rows > 0) ? $e_q->fetch_assoc()['status'] : 'Pending';
    
    // Determine status
    $docsVerified = ($row['waiver_status'] === 'Verified' && $row['agreement_status'] === 'Verified');
    $hoursComplete = $row['completed_hours'] >= 300; 
    
    // Logic: 300 Hours = Completed (Primary Condition)
    if ($hoursComplete) {
        $row['overall_status'] = 'Completed';
    } elseif ($docsVerified) {
        $row['overall_status'] = 'Verified';
    } else {
        $row['overall_status'] = 'Pending';
    }
    
    if ($row['overall_status'] === 'Pending') {
        $pending_verifications++;
    }
    if ($row['overall_status'] === 'Completed' || $row['overall_status'] === 'Verified') {
        $completed_students++;
    }
    
    if (!isset($students_by_year[$year])) {
        $students_by_year[$year] = [];
    }
    if (!isset($students_by_year[$year][$section])) {
        $students_by_year[$year][$section] = [];
    }
    $students_by_year[$year][$section][] = $row;
    $total_students++;
}

$result->free();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Coordinator Dashboard</title>
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
            border-top: 4px solid var(--accent-color);
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
            background: var(--primary-color);
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            color: white;
        }

        .container-fluid {
            padding: 2rem;
        }

/* Custom #docVerifyModal styles removed - theme handles centering */

        /* Cards */
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

        /* Data Tables / Grids */
        .content-card {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
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
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .section-badge:hover {
            background: var(--accent-color);
            color: white;
        }

        /* Fix font sizes as requested */
        .year-card h4 {
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

        /* Profile */
        .profile-img-nav {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--primary-color);
        }

        /* Mobile Header Styles */
        .mobile-header {
            display: none;
        }
        @media (max-width: 768px) {
            #sidebar-wrapper {
                display: none;
            }
            .navbar {
                display: none;
            }
            #page-content-wrapper {
                margin-left: 0;
                width: 100%;
                padding-top: 80px; /* Space for 2-row header */
            }
            
            .mobile-header {
                display: flex;
                flex-direction: column;
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                background: var(--primary-color);
                z-index: 1040;
                box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            }

            .mobile-header-top {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 10px 20px;
                border-bottom: 1px solid rgba(255,255,255,0.1);
                height: 60px;
            }

            .mobile-logo {
                font-size: 1.25rem;
                font-weight: 700;
                color: white;
                text-decoration: none;
                display: flex;
                align-items: center;
            }

            .mobile-header-nav {
                display: flex;
                justify-content: space-around;
                align-items: center;
                height: 50px;
                background: rgba(0,0,0,0.1);
            }

            .mobile-header-nav .nav-item {
                color: rgba(255,255,255,0.7);
                text-decoration: none;
                font-size: 0.9rem;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                width: 100%;
                height: 100%;
                transition: all 0.2s;
            }

            .mobile-header-nav .nav-item:hover,
            .mobile-header-nav .nav-item.active {
                color: white;
                background: rgba(255,255,255,0.05);
            }

            .mobile-header-nav .nav-item i {
                font-size: 1.1rem;
                margin-bottom: 2px;
            }

            .mobile-header-nav .nav-item span {
                font-size: 0.7rem;
            }
        }
        
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

        @media print {
            #sidebar-wrapper, .navbar, .btn, .alert {
                display: none !important;
            }
            #page-content-wrapper {
                margin-left: 0 !important;
                width: 100% !important;
            }
            .content-card {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
            }
            body {
                background: white !important;
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

    <!-- Mobile Header -->
    <div class="mobile-header d-md-none">
        <div class="mobile-header-top">
            <a href="#" class="mobile-logo">
                <img src="../img/logo.png" alt="Logo" class="me-2" style="height: 40px; padding-right:0%"> RServeS
            </a>
            <div class="d-flex align-items-center" style="cursor: pointer;" data-bs-toggle="modal" data-bs-target="#profileModal">
                <span class="text-white me-2 fw-bold" style="font-size: 0.9rem;">Coordinator</span>
                <img src="<?php echo htmlspecialchars($coord_photo); ?>" alt="Profile" 
                     class="rounded-circle border border-2 border-white" 
                     style="width: 35px; height: 35px; object-fit: cover;">
            </div>
        </div>
        <div class="mobile-header-nav">
            <a href="#" class="nav-item active" data-view-link="dashboard" onclick="showView('dashboard', this); return false;">
                <i class="fas fa-th-large"></i>
                <span>Overview</span>
            </a>
            <a href="#" class="nav-item position-relative" data-view-link="records" onclick="showView('records', this); return false;">
                <i class="fas fa-users"></i>
                <span>Records</span>
                <?php if($pending_verifications > 0): ?>
                    <span class="position-absolute top-0 start-50 translate-middle badge rounded-pill bg-danger" style="font-size: 0.5rem; transform: translate(10px, 5px) !important;">
                        <?php echo $pending_verifications; ?>
                    </span>
                <?php endif; ?>
            </a>
            <a href="#" class="nav-item" data-view-link="reports" onclick="showView('reports', this); return false;">
                <i class="fas fa-chart-bar"></i>
                <span>Reports</span>
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
                <span class="sidebar-brand-subtitle">Coordinator Workspace</span>
            </div>
            <div class="list-group list-group-flush">
                <a href="#" class="list-group-item list-group-item-action active" data-view-link="dashboard" onclick="showView('dashboard', this); return false;">
                    <i class="fas fa-th-large"></i> Dashboard
                </a>
                <a href="#" class="list-group-item list-group-item-action" data-view-link="records" onclick="showView('records', this); return false;">
                    <i class="fas fa-users"></i> Student Records
                </a>
                <a href="#" class="list-group-item list-group-item-action" data-view-link="reports" onclick="showView('reports', this); return false;">
                    <i class="fas fa-chart-bar"></i> Reports
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
                    <img src="<?php echo htmlspecialchars($coord_photo); ?>" alt="Profile" class="sidebar-role-avatar">
                    <div>
                        <div class="sidebar-role-name"><?php echo htmlspecialchars($coord_name); ?></div>
                        <div class="sidebar-role-meta">Coordinator | College of Technology</div>
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
                    <a href="#" class="topbar-tab" data-view-link="records" onclick="showView('records'); return false;">Records</a>
                    <a href="#" class="topbar-tab" data-view-link="reports" onclick="showView('reports'); return false;">Reports</a>
                    <a href="#" class="topbar-tab" data-view-link="notifications" onclick="showView('notifications'); return false;">Notifications</a>
                </div>

                <div class="topbar-actions">
                    <div class="topbar-profile" data-bs-toggle="modal" data-bs-target="#profileModal">
                        <div class="topbar-identity">
                            <div><?php echo htmlspecialchars($coord_name); ?></div>
                            <div>Coordinator | College of Technology</div>
                        </div>
                        <img src="<?php echo htmlspecialchars($coord_photo); ?>" alt="Profile" class="topbar-avatar">
                    </div>
                </div>
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

<!-- Document Verification Modal -->
<div class="modal fade" id="docVerifyModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Document Verification</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="docVerifyContent">
                <div class="text-center"><div class="spinner-border"></div> Loading...</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" onclick="verifyAction('reject')">Reject</button>
                <button type="button" class="btn btn-success" onclick="verifyAction('verify')">Verify</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Profile Modal -->
<div class="modal fade" id="profileModal" tabindex="-1" style="z-index: 10000;">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">My Profile</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <img src="<?php echo htmlspecialchars($coord_photo); ?>" class="rounded-circle mb-3" style="width: 120px; height: 120px; object-fit: cover; border: 4px solid var(--primary-color);">
                <h4><?php echo htmlspecialchars($coord_name); ?></h4>
                <p class="text-muted"><?php echo htmlspecialchars($email); ?></p>
                <hr>
                <form action="<?php echo $_SERVER['PHP_SELF']; ?>" method="POST" class="text-start">
                    <h6 class="fw-bold mb-3 text-center">Change Password</h6>
                    <div class="mb-2">
                        <label class="form-label small">Current Password</label>
                        <div class="password-container">
                            <input type="password" name="current_password" class="form-control form-control-sm" required id="coord_current_password">
                            <i class="fas fa-eye-slash password-toggle-icon" onclick="togglePasswordVisibility(this, 'coord_current_password')"></i>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">New Password</label>
                        <div class="password-container">
                            <input type="password" name="new_password" class="form-control form-control-sm" required id="coord_new_password">
                            <i class="fas fa-eye-slash password-toggle-icon" onclick="togglePasswordVisibility(this, 'coord_new_password')"></i>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Confirm New Password</label>
                        <div class="password-container">
                            <input type="password" name="confirm_password" class="form-control form-control-sm" required id="coord_confirm_password">
                            <i class="fas fa-eye-slash password-toggle-icon" onclick="togglePasswordVisibility(this, 'coord_confirm_password')"></i>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const studentsData = <?php echo json_encode($students_by_year); ?>;
    const notifications = <?php echo json_encode($notifications); ?>;
    const stats = {
        total: <?php echo $total_students; ?>,
        pending: <?php echo $pending_verifications; ?>,
        completed: <?php echo $completed_students; ?>
    };



    // View Management
    function setActiveViewLinks(view) {
        document.querySelectorAll('[data-view-link]').forEach((item) => {
            item.classList.toggle('active', item.getAttribute('data-view-link') === view);
        });
    }

    function showView(view) {
        setActiveViewLinks(view);
        
        const container = document.getElementById('main-content');
        container.innerHTML = '';
        
        if (view === 'dashboard') {
            renderDashboard(container);
        } else if (view === 'records') {
            renderRecords(container);
        } else if (view === 'reports') {
            renderReports(container);
        } else if (view === 'notifications') {
            renderNotifications(container);
        }
    }

    function findViewTrigger(view) {
        return document.querySelector(`[data-view-link="${view}"]`);
    }

    function findCoordinatorStudent(studentId) {
        for (const year of Object.keys(studentsData || {})) {
            const sections = studentsData[year] || {};

            for (const section of Object.keys(sections)) {
                const students = sections[section] || [];
                const student = students.find((entry) => String(entry.stud_id) === String(studentId));

                if (student) {
                    return { year, section, student };
                }
            }
        }

        return null;
    }

    function highlightNotificationTarget(selector) {
        const target = document.querySelector(selector);
        if (!target) return;

        target.classList.add('border-primary', 'shadow-sm');
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });

        window.setTimeout(() => {
            target.classList.remove('border-primary', 'shadow-sm');
        }, 2200);
    }

    function openCoordinatorNotification(notificationId) {
        const notification = (notifications || []).find((item) => String(item.id) === String(notificationId));
        if (!notification) return;

        showView('records', findViewTrigger('records'));

        const match = findCoordinatorStudent(notification.student_id);
        if (!match) return;

        renderSections(match.year);
        renderStudentList(match.year, match.section);

        window.requestAnimationFrame(() => {
            highlightNotificationTarget(`[data-student-id="${notification.student_id}"]`);

            if (['waiver', 'agreement', 'enrollment'].includes(String(notification.type || '').toLowerCase())) {
                openVerifyModal(notification.student_id, notification.type);
            }
        });
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
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Notifications</h2>
                ${unreadCount > 0 ? '<button class="btn btn-sm btn-outline-primary" onclick="markAllRead()">Mark all as read</button>' : ''}
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
                    const icon = n.type === 'waiver'
                        ? 'fa-file-signature'
                        : (n.type === 'agreement' ? 'fa-file-contract' : 'fa-folder-open');
                    const date = new Date(n.created_at).toLocaleString();
                    html += `
                        <button type="button" class="list-group-item list-group-item-action ${bgClass} d-flex justify-content-between align-items-center text-start" onclick="openCoordinatorNotification(${n.id})">
                            <div>
                                <i class="fas ${icon} me-2 text-primary"></i>
                                ${n.message}
                                <br><small class="text-muted ms-4">${date}</small>
                                <br><small class="text-primary fw-semibold ms-4">Open related record</small>
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
        const formData = new FormData();
        formData.append('mark_all_read', 'true');
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => {
            window.location.reload();
        });
    }

    function renderDashboard(container) {
        const html = `
            <h2 class="mb-4">Dashboard Overview</h2>
            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-user-graduate"></i></div>
                        <h3>${stats.total}</h3>
                        <p class="text-muted">Total Students</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card">
                        <div class="stat-icon" style="color: #ffc107;"><i class="fas fa-file-contract"></i></div>
                        <h3>${stats.pending}</h3>
                        <p class="text-muted">Pending Verifications</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card">
                        <div class="stat-icon" style="color: #198754;"><i class="fas fa-check-circle"></i></div>
                        <h3>${stats.completed}</h3>
                        <p class="text-muted">Completed Students</p>
                    </div>
                </div>
            </div>
            
            <div class="content-card">
                <h4 class="mb-3">Quick Actions</h4>
                <button class="btn btn-primary" onclick="showView('records', findViewTrigger('records'))">
                    <i class="fas fa-search me-2"></i> Review Student Documents
                </button>
            </div>
        `;
        container.innerHTML = html;
    }

    function renderRecords(container) {
        container.innerHTML = `
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
            html = '<div class="col-12 text-center text-muted">No student records found.</div>';
        } else {
            years.forEach(year => {
                const sections = studentsData[year];
                const sectionCount = Object.keys(sections).length;
                let studentCount = 0;
                Object.values(sections).forEach(s => studentCount += s.length);

                html += `
                    <div class="col-md-6 col-lg-3">
                        <div class="year-card" onclick="renderSections(${year})">
                            <div class="year-icon"><i class="fas fa-layer-group"></i></div>
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
        const sections = studentsData[year];
        
        Object.keys(sections).sort().forEach(section => {
            const count = sections[section].length;
            html += `
                <div class="col-md-4 col-lg-3">
                    <div class="year-card" onclick="renderStudentList(${year}, '${section}')">
                        <div class="year-icon"><i class="fas fa-users"></i></div>
                        <h4>Section ${section}</h4>
                        <p class="text-muted mb-0">${count} Students</p>
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
            <li class="breadcrumb-item"><a href="#" onclick="renderSections(${year}); return false;">Year ${year}</a></li>
            <li class="breadcrumb-item active">Section ${section}</li>
        `;

        const students = studentsData[year][section];
        
        let rows = '';
        students.forEach(s => {
            let statusClass = 'bg-warning text-dark';
            if (s.overall_status === 'Completed') statusClass = 'bg-success';
            else if (s.overall_status === 'Verified') statusClass = 'bg-info text-dark';

            rows += `
                <tr data-student-id="${s.stud_id}">
                    <td><input type="checkbox" class="student-cb" data-id="${s.stud_id}"></td>
                    <td>
                        <div class="d-flex align-items-center">
                            <div>
                                <div class="fw-bold">${s.lastname}, ${s.firstname} ${s.mi}</div>
                                <div class="small text-muted">${s.email}</div>
                            </div>
                        </div>
                    </td>
                    <td><span class="badge ${statusClass}">${s.overall_status}</span></td>
                    <td>${getStatusBadge(s.stud_id, 'waiver', s.waiver_status)}</td>
                    <td>${getStatusBadge(s.stud_id, 'agreement', s.agreement_status)}</td>
                    <td>${s.completed_hours}</td>
                </tr>
            `;
        });
        
        content.innerHTML = `
            <div class="col-12">
                <div class="content-card">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4 class="mb-0">Student List - Year ${year} Section ${section}</h4>
                        <button class="btn btn-success btn-sm" onclick="verifySelected()">
                            <i class="fas fa-check-double me-2"></i> Verify Selected
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th width="40"><input type="checkbox" id="selectAll" onclick="toggleAll(this)"></th>
                                    <th>Student</th>
                                    <th>Status</th>
                                    <th>Waiver</th>
                                    <th>Agreement</th>
                                    <th>Hours</th>
                                </tr>
                            </thead>
                            <tbody>${rows}</tbody>
                        </table>
                    </div>
                </div>
            </div>
        `;
    }

    function getStatusBadge(id, type, status) {
        if (!status || status === 'None') {
            return `<span class="badge bg-secondary opacity-50">Not Submitted</span>`;
        }
        
        let cls = 'bg-secondary';
        if (status === 'Verified') cls = 'bg-success';
        else if (status === 'Pending') cls = 'bg-warning text-dark';
        else if (status === 'Rejected') cls = 'bg-danger';
        
        return `<span class="badge ${cls}" style="cursor:pointer" onclick="openVerifyModal(${id}, '${type}')">${status}</span>`;
    }

    function toggleAll(source) {
        document.querySelectorAll('.student-cb').forEach(cb => cb.checked = source.checked);
    }

    // Modal & Verification Logic
    let currentVerify = { id: null, type: null };

    function openVerifyModal(id, type) {
        currentVerify = { id, type };
        const modal = new bootstrap.Modal(document.getElementById('docVerifyModal'));
        modal.show();
        
        const content = document.getElementById('docVerifyContent');
        content.innerHTML = '<div class="text-center"><div class="spinner-border"></div> Loading...</div>';
        
        fetch(`get_document_details.php?student_id=${id}&doc_type=${type}`)
            .then(res => res.text())
            .then(html => {
                content.innerHTML = html;
            })
            .catch(err => content.innerHTML = '<div class="alert alert-danger">Error loading details</div>');
    }

    function verifyAction(action) {
        if (!currentVerify.id) return;
        
        if (!confirm(`Are you sure you want to ${action} this document?`)) return;
        
        fetch('verify_document.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                student_id: currentVerify.id,
                doc_type: currentVerify.type,
                action: action
            })
        })
        .then(res => res.json())
        .then(data => {
            if(data.success) {
                alert('Success!');
                location.reload(); 
            } else {
                alert('Error: ' + data.message);
            }
        });
    }

    function verifySelected() {
        const selected = Array.from(document.querySelectorAll('.student-cb:checked'));
        if (selected.length === 0) {
            alert('Please select at least one student');
            return;
        }
        
        if (confirm(`Verify ${selected.length} student(s) as completed for all documents?`)) {
            const btn = event.target.closest('button'); // Quick access
            const originalText = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Processing...';
            btn.disabled = true;
            
            const studentIds = selected.map(cb => cb.dataset.id);
            
            fetch('verify_multiple.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ student_ids: studentIds })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert(`Successfully verified ${data.count} student(s)!`);
                    location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Unknown error'));
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                }
            })
            .catch(err => {
                alert('Network error');
                btn.innerHTML = originalText;
                btn.disabled = false;
            });
        }
    }

    function renderReports(container) {
        // 1. Process Data
        let stats = {
            waiver: { Verified: 0, Pending: 0, Rejected: 0 },
            agreement: { Verified: 0, Pending: 0, Rejected: 0 },
            overall: { Completed: 0, Verified: 0, Pending: 0 }
        };
        
        let completers = [];
        let deficient = [];

        Object.values(studentsData).forEach(sections => {
            Object.values(sections).forEach(students => {
                students.forEach(s => {
                    // Count Doc Statuses
                    if(stats.waiver[s.waiver_status] !== undefined) stats.waiver[s.waiver_status]++;
                    else stats.waiver.Pending++;
                    
                    if(stats.agreement[s.agreement_status] !== undefined) stats.agreement[s.agreement_status]++;
                    else stats.agreement.Pending++;
                    
                    // Count Overall
                    if(s.overall_status === 'Completed') {
                        stats.overall.Completed++;
                        completers.push(s);
                    } else if (s.overall_status === 'Verified') {
                        stats.overall.Verified++;
                        deficient.push(s);
                    } else {
                        stats.overall.Pending++;
                        deficient.push(s);
                    }
                });
            });
        });

        // 2. Build HTML
        const html = `
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">Reports & Analytics</h2>
                <button class="btn btn-outline-primary" onclick="window.print()"><i class="fas fa-print me-2"></i> Print Report</button>
            </div>

            <!-- Charts Row -->
            <div class="row g-4 mb-5">
                <div class="col-md-8">
                    <div class="content-card h-100">
                        <h5 class="mb-4">Document Verification Status</h5>
                        <div style="height: 300px;">
                            <canvas id="docStatusChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="content-card h-100">
                        <h5 class="mb-4">Overall Completion</h5>
                        <div style="height: 300px; display: flex; justify-content: center;">
                            <canvas id="completionChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Completers List -->
            <div class="content-card mb-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="mb-0 text-success"><i class="fas fa-award me-2"></i> Completed Students (Ready for Grading)</h5>
                    <span class="badge bg-success rounded-pill">${completers.length} Students</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Name</th>
                                <th>Year/Section</th>
                                <th>Hours Rendered</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${completers.length > 0 ? completers.map(s => `
                                <tr>
                                    <td>${s.lastname}, ${s.firstname} ${s.mi}</td>
                                    <td>${s.year_level} - ${s.section}</td>
                                    <td>${s.completed_hours}</td>
                                    <td><span class="badge bg-success">Completed</span></td>
                                </tr>
                            `).join('') : '<tr><td colspan="4" class="text-center text-muted">No students have completed all requirements yet.</td></tr>'}
                        </tbody>
                    </table>
                </div>
            </div>
            
             <!-- Deficient List (Collapsible) -->
            <div class="content-card">
                 <div class="d-flex justify-content-between align-items-center mb-4" style="cursor:pointer" data-bs-toggle="collapse" data-bs-target="#deficientList">
                    <h5 class="mb-0 text-warning"><i class="fas fa-exclamation-triangle me-2"></i> Pending/Deficient Students <i class="fas fa-chevron-down ms-2 small"></i></h5>
                    <span class="badge bg-warning text-dark rounded-pill">${deficient.length} Students</span>
                </div>
                <div class="collapse" id="deficientList">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Name</th>
                                    <th>Year/Section</th>
                                    <th>Missing Requirements</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${deficient.map(s => {
                                    let missing = [];
                                    if(s.waiver_status !== 'Verified') missing.push('Waiver');
                                    if(s.agreement_status !== 'Verified') missing.push('Agreement');
                                    if(s.completed_hours < 300) missing.push('Hours (' + s.completed_hours + '/300)');
                                    return `
                                    <tr>
                                        <td>${s.lastname}, ${s.firstname}</td>
                                        <td>${s.year_level} - ${s.section}</td>
                                        <td class="text-danger small">${missing.join(', ')}</td>
                                    </tr>
                                    `;
                                }).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        `;
        
        container.innerHTML = html;

        // 3. Render Charts
        new Chart(document.getElementById('docStatusChart'), {
            type: 'bar',
            data: {
                labels: ['Waiver', 'Agreement'],
                datasets: [
                    {
                        label: 'Verified',
                        data: [stats.waiver.Verified, stats.agreement.Verified],
                        backgroundColor: '#198754'
                    },
                    {
                        label: 'Pending',
                        data: [stats.waiver.Pending, stats.agreement.Pending],
                        backgroundColor: '#ffc107'
                    },
                    {
                        label: 'Rejected',
                        data: [stats.waiver.Rejected, stats.agreement.Rejected],
                        backgroundColor: '#dc3545'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { stacked: true },
                    y: { stacked: true, beginAtZero: true }
                }
            }
        });

        new Chart(document.getElementById('completionChart'), {
            type: 'doughnut',
            data: {
                labels: ['Completed', 'In Progress'],
                datasets: [{
                    data: [stats.overall.Completed, stats.overall.Pending + stats.overall.Verified],
                    backgroundColor: ['#198754', '#ffc107']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
    }

    // Initialize
    renderDashboard(document.getElementById('main-content'));

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
