<?php
//instructor
session_start();
require "dbconnect.php";

/* -------------------  SESSION CHECK ------------------- */
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Instructor' || $_SESSION['department_id'] != 2) {
    header("Location: ../home2.php");
    exit;
}

$email = $_SESSION['email'];

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

    $stmt1 = $conn->prepare("DELETE FROM student_tasks WHERE task_id=?");
    $stmt1->bind_param("i", $del_id);
    $stmt1->execute();
    $stmt1->close();

    $stmt2 = $conn->prepare("DELETE FROM tasks WHERE task_id=? AND instructor_id=?");
    $stmt2->bind_param("ii", $del_id, $inst_id);
    $stmt2->execute();
    $stmt2->close();

    header("Location: ".$_SERVER['PHP_SELF']."?msg=".urlencode("Task deleted successfully!"));
    exit;
}

/* Org Handlers Removed */

/* -------------------  FETCH STUDENTS GROUPED BY YEAR & SECTION ------------------- */
$students_by_year = [];

$sql = "
SELECT 
  s.stud_id,
  s.firstname,
  s.mi,
  s.lastname,
  s.email,
  COALESCE(s.year_level, 1) AS year_level,
  COALESCE(s.section, 'A') AS section,
  COALESCE(SUM(ar.hours), 0) AS completed_hours
FROM students s
LEFT JOIN accomplishment_reports ar
  ON ar.student_id = s.stud_id
GROUP BY s.stud_id
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

/* -------------------  FETCH ADVISORY SECTIONS ------------------- */
$advisory_sections = [];
$stmt_adv = $conn->prepare("SELECT section FROM section_advisers WHERE instructor_id = ? AND department_id = 2");
$stmt_adv->bind_param("i", $inst_id);
$stmt_adv->execute();
$res_adv = $stmt_adv->get_result();
while($row_adv = $res_adv->fetch_assoc()) {
    $advisory_sections[] = $row_adv['section'];
}
$stmt_adv->close();

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

/* Org Fetching Removed */
$pendingOrgs = [];
$pendingAccomps = [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Instructor Dashboard - College of Technology</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
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
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--primary-color);
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
            body.sidebar-toggled::before {
                content: '';
                position: fixed;
                top: 0; left: 0; right: 0; bottom: 0;
                background: rgba(0,0,0,0.5);
                z-index: 999;
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
    </style>
</head>
<body>

<div class="d-flex" id="wrapper">
    <!-- Sidebar -->
    <div id="sidebar-wrapper">
        <div class="sidebar-heading">
            <i class="fas fa-university me-2"></i> TechDept
        </div>
        <div class="list-group list-group-flush">
            <a href="#" class="list-group-item list-group-item-action active" onclick="showView('dashboard', this)">
                <i class="fas fa-th-large"></i> Dashboard
            </a>
            <a href="#" class="list-group-item list-group-item-action" onclick="showView('classes', this)">
                <i class="fas fa-users"></i> Classes
            </a>
            <a href="#" class="list-group-item list-group-item-action" onclick="showView('tasks', this)">
                <i class="fas fa-tasks"></i> Tasks
            </a>
            <!-- Org Sidebar Link Removed -->
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
            <button class="btn btn-outline-primary" id="menu-toggle">
                <i class="fas fa-bars"></i>
            </button>
            
            <div class="ms-auto d-flex align-items-center">
                <div class="me-3 text-end d-none d-md-block">
                    <div class="fw-bold"><?php echo htmlspecialchars($fullname); ?></div>
                    <small class="text-muted">Instructor</small>
                </div>
                <img src="<?php echo htmlspecialchars($photo); ?>" alt="Profile" class="profile-img-nav" data-bs-toggle="modal" data-bs-target="#profileModal" style="cursor: pointer;">
            </div>
        </nav>

        <div class="container-fluid" id="main-content">
            <!-- Content rendered via JS -->
        </div>
    </div>
</div>

<!-- Modals -->
<!-- Profile Modal -->
<div class="modal fade" id="profileModal" tabindex="-1">
    <div class="modal-dialog">
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
            </div>
        </div>
    </div>
</div>

<!-- Create Task Modal -->
<div class="modal fade" id="createTaskModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
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
                    <button type="submit" name="create_task" class="btn btn-primary">Create Task</button>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Data from PHP
    const studentsData = <?php echo json_encode($students_by_year); ?>;
    const pendingOrgs = <?php echo json_encode($pendingOrgs); ?>;
    const pendingAccomps = <?php echo json_encode($pendingAccomps); ?>;
    const myTasks = <?php echo json_encode($myTasks); ?>;
    const taskAssignments = <?php echo json_encode($taskAssignments); ?>;
    let taskMonthFilter = 'latest';
    
    // Toggle Sidebar
    document.getElementById('menu-toggle').addEventListener('click', function() {
        document.body.classList.toggle('sidebar-toggled');
    });

    // View Management
    function showView(viewName, linkElement) {
        // Update Sidebar Active State
        if(linkElement) {
            document.querySelectorAll('.list-group-item').forEach(el => el.classList.remove('active'));
            linkElement.classList.add('active');
        }

        const container = document.getElementById('main-content');
        container.innerHTML = ''; // Clear content

        if(viewName === 'dashboard') renderDashboard(container);
        else if(viewName === 'classes') renderClasses(container);
        else if(viewName === 'tasks') renderTasks(container);
        // Org view removed
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
            <h2 class="mb-4">Dashboard Overview</h2>
            <div class="row g-4">
                <div class="col-md-6">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-user-graduate"></i></div>
                        <h3>${totalStudents}</h3>
                        <p class="text-muted">Total Students</p>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-layer-group"></i></div>
                        <h3>${totalSections}</h3>
                        <p class="text-muted">Active Sections</p>
                    </div>
                </div>
                <!-- Org Pending Card Removed -->
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
        // Placeholder for student details logic
        alert('Student details view to be implemented for ID: ' + studentId);
    }

    function renderTasks(container) {
        // Populate Student Selection in Create Modal first
        populateStudentSelection();
        
        container.innerHTML = `
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Task Management</h2>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createTaskModal">
                    <i class="fas fa-plus"></i> New Task
                </button>
            </div>
            
            <div class="content-card">
                <h4>Your Tasks</h4>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> Tasks shown here are those currently assigned to students.
                </div>
                <!-- Note: The PHP only fetches "pending" tasks per student. 
                     For a full task manager, we might need a different query. 
                     For now, we display what we have. -->
                 <p class="text-muted">Task management logic requires fetching unique tasks created by instructor. Currently showing create interface.</p>
            </div>
        `;
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

    // Org render function removed

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
    });
</script>
</body>
</html>
