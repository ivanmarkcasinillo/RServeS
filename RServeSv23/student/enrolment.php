<?php
//enrollment.php
session_start();
require "dbconnect.php";

// Check if student is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Student') {
    header("Location: ../home2.php");
    exit;
}

$student_id = $_SESSION['stud_id'] ?? null;
if (!$student_id) {
    die("Student ID not found in session.");
}

// Get student info
$stmt = $conn->prepare("SELECT firstname, lastname, email, student_number FROM students WHERE stud_id = ?");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Check if already enrolled
$check = $conn->prepare("SELECT enrollment_id, status FROM rss_enrollments WHERE student_id = ?");
$check->bind_param("i", $student_id);
$check->execute();
$existing = $check->get_result()->fetch_assoc();
$check->close();

if ($existing && $existing['status'] !== 'Rejected') {
    $_SESSION['flash'] = "You have already submitted an enrollment form.";
    header("Location: pending_requirements.php");
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // If previously rejected, delete the old record so we can insert a new one
    if ($existing && $existing['status'] === 'Rejected') {
        $conn->query("DELETE FROM rss_enrollments WHERE enrollment_id = " . $existing['enrollment_id']);
    }
    
    // Handle photo upload
    $photo_path = null;
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/enrollment_photos/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
        $new_filename = 'student_' . $student_id . '_' . time() . '.' . $file_ext;
        $photo_path = 'uploads/enrollment_photos/' . $new_filename;
        
        move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $new_filename);
    }
    
    // Store ALL POST values in variables
    $surname = $_POST['surname'] ?? '';
    $given_name = $_POST['given_name'] ?? '';
    $middle_name = $_POST['middle_name'] ?? '';
    $student_number = $_POST['student_number'] ?? '';
    $college_values = isset($_POST['college']) ? implode(',', $_POST['college']) : '';
    $course = $_POST['course'] ?? '';
    $major = $_POST['major'] ?? '';
    $year_level = intval($_POST['year_level'] ?? 1);
    $section = $_POST['section'] ?? '';
    $city_address = $_POST['city_address'] ?? '';
    $gender = $_POST['gender'] ?? '';
    $contact_number = $_POST['contact_number'] ?? '';
    $email_address = $_POST['email_address'] ?? '';
    $birth_date = $_POST['birth_date'] ?? '';
    $birth_place = $_POST['birth_place'] ?? '';
    $provincial_address = $_POST['provincial_address'] ?? '';
    $religion = $_POST['religion'] ?? '';
    $marital_status = $_POST['marital_status'] ?? '';
    
    $father_name = $_POST['father_name'] ?? '';
    $father_occupation = $_POST['father_occupation'] ?? '';
    $father_company = $_POST['father_company'] ?? '';
    $father_company_address = $_POST['father_company_address'] ?? '';
    $father_contact = $_POST['father_contact'] ?? '';
    
    $mother_name = $_POST['mother_name'] ?? '';
    $mother_occupation = $_POST['mother_occupation'] ?? '';
    $mother_company = $_POST['mother_company'] ?? '';
    $mother_company_address = $_POST['mother_company_address'] ?? '';
    $mother_contact = $_POST['mother_contact'] ?? '';
    
    $guardian_name = $_POST['guardian_name'] ?? '';
    $guardian_address = $_POST['guardian_address'] ?? '';
    $guardian_contact = $_POST['guardian_contact'] ?? '';
    
    $tertiary_school = $_POST['tertiary_school'] ?? '';
    $tertiary_address = $_POST['tertiary_address'] ?? '';
    $tertiary_year_grad = $_POST['tertiary_year_grad'] ?? '';
    $tertiary_honors = $_POST['tertiary_honors'] ?? '';
    
    $secondary_school = $_POST['secondary_school'] ?? '';
    $secondary_address = $_POST['secondary_address'] ?? '';
    $secondary_year_grad = $_POST['secondary_year_grad'] ?? '';
    $secondary_honors = $_POST['secondary_honors'] ?? '';
    
    $primary_school = $_POST['primary_school'] ?? '';
    $primary_address = $_POST['primary_address'] ?? '';
    $primary_year_grad = $_POST['primary_year_grad'] ?? '';
    $primary_honors = $_POST['primary_honors'] ?? '';
    
    $height = $_POST['height'] ?? '';
    $weight = $_POST['weight'] ?? '';
    $blood_type = $_POST['blood_type'] ?? '';
    $health_problem = $_POST['health_problem'] ?? '';
    $vaccination = isset($_POST['vaccination_status']) ? implode(',', $_POST['vaccination_status']) : '';
    $vaccine_type = $_POST['vaccine_type'] ?? '';
    $place_vaccination = $_POST['place_vaccination'] ?? '';
    $date_vaccination = $_POST['date_vaccination'] ?? '';
    $health_ins = isset($_POST['health_insurance']) ? implode(',', $_POST['health_insurance']) : '';
    $private_specify_text = $_POST['private_specify_text'] ?? '';
    
    $rss_assignment = $_POST['rss_assignment'] ?? '';
    $assigned_job_position = $_POST['assigned_job_position'] ?? '';
    $inclusive_dates = $_POST['inclusive_dates'] ?? '';
    $rss_site_address = $_POST['rss_site_address'] ?? '';
    $signature_image = $_POST['signature_image'] ?? '';
    
    // Ensure status and verification columns exist
    $checkCol = $conn->query("SHOW COLUMNS FROM rss_enrollments LIKE 'status'");
    if ($checkCol && $checkCol->num_rows == 0) {
        $conn->query("ALTER TABLE rss_enrollments ADD COLUMN status ENUM('Pending', 'Verified', 'Rejected') DEFAULT 'Pending'");
    }
    
    $checkCol = $conn->query("SHOW COLUMNS FROM rss_enrollments LIKE 'verified_at'");
    if ($checkCol && $checkCol->num_rows == 0) {
        $conn->query("ALTER TABLE rss_enrollments ADD COLUMN verified_at TIMESTAMP NULL");
    }

    $checkCol = $conn->query("SHOW COLUMNS FROM rss_enrollments LIKE 'verified_by'");
    if ($checkCol && $checkCol->num_rows == 0) {
        $conn->query("ALTER TABLE rss_enrollments ADD COLUMN verified_by INT NULL");
    }

    // Ensure section_requests table exists
    $conn->query("CREATE TABLE IF NOT EXISTS section_requests (
        request_id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        year_level INT,
        section VARCHAR(50),
        adviser_id INT,
        status ENUM('Pending', 'Approved', 'Declined', 'Completed') DEFAULT 'Pending',
        decline_reason TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        approved_at DATETIME,
        approved_by INT,
        UNIQUE KEY unique_req (student_id)
    )");

    // Ensure section_advisers table exists
    $conn->query("CREATE TABLE IF NOT EXISTS section_advisers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        instructor_id INT NOT NULL,
        department_id INT NOT NULL,
        section VARCHAR(10) NOT NULL,
        year_level INT NOT NULL,
        UNIQUE KEY unique_sec (department_id, section, year_level)
    )");

    // Ensure master_students table exists (for adviser verification)
    $conn->query("CREATE TABLE IF NOT EXISTS master_students (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id_number VARCHAR(50),
        lastname VARCHAR(100),
        firstname VARCHAR(100),
        middlename VARCHAR(100),
        course VARCHAR(50),
        year_level INT,
        section VARCHAR(10),
        birthdate DATE
    )");

    $sql = "INSERT INTO rss_enrollments (
        student_id, surname, given_name, middle_name, student_number,
        college, course, major, year_level, section, city_address, gender,
        contact_number, email_address, birth_date, birth_place,
        provincial_address, religion, marital_status, photo_path,
        father_name, father_occupation, father_company, father_company_address, father_contact,
        mother_name, mother_occupation, mother_company, mother_company_address, mother_contact,
        guardian_name, guardian_address, guardian_contact,
        tertiary_school, tertiary_address, tertiary_year_grad, tertiary_honors,
        secondary_school, secondary_address, secondary_year_grad, secondary_honors,
        primary_school, primary_address, primary_year_grad, primary_honors,
        height, weight, blood_type, health_problem, vaccination_status,
        vaccine_type, place_vaccination, date_vaccination, health_insurance, private_insurance_details,
        rss_assignment, assigned_job_position, inclusive_dates, rss_site_address, signature_image
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("❌ SQL PREPARE FAILED: " . $conn->error);
    }
    
    // Bind 60 parameters: 2 integers (student_id, year_level) + 58 strings
    $stmt->bind_param(
        "isssssssississssssssssssssssssssssssssssssssssssssssssssssss",
        $student_id,              // 1 - i (integer)
        $surname,                 // 2 - s
        $given_name,              // 3 - s
        $middle_name,             // 4 - s
        $student_number,          // 5 - s
        $college_values,          // 6 - s
        $course,                  // 7 - s
        $major,                   // 8 - s
        $year_level,              // 9 - i (integer)
        $section,                 // 10 - s
        $city_address,            // 11 - s
        $gender,                  // 12 - s
        $contact_number,          // 13 - s
        $email_address,           // 14 - s
        $birth_date,              // 15 - s
        $birth_place,             // 16 - s
        $provincial_address,      // 17 - s
        $religion,                // 18 - s
        $marital_status,          // 19 - s
        $photo_path,              // 20 - s
        $father_name,             // 21 - s
        $father_occupation,       // 22 - s
        $father_company,          // 23 - s
        $father_company_address,  // 24 - s
        $father_contact,          // 25 - s
        $mother_name,             // 26 - s
        $mother_occupation,       // 27 - s
        $mother_company,          // 28 - s
        $mother_company_address,  // 29 - s
        $mother_contact,          // 30 - s
        $guardian_name,           // 31 - s
        $guardian_address,        // 32 - s
        $guardian_contact,        // 33 - s
        $tertiary_school,         // 34 - s
        $tertiary_address,        // 35 - s
        $tertiary_year_grad,      // 36 - s
        $tertiary_honors,         // 37 - s
        $secondary_school,        // 38 - s
        $secondary_address,       // 39 - s
        $secondary_year_grad,     // 40 - s
        $secondary_honors,        // 41 - s
        $primary_school,          // 42 - s
        $primary_address,         // 43 - s
        $primary_year_grad,       // 44 - s
        $primary_honors,          // 45 - s
        $height,                  // 46 - s
        $weight,                  // 47 - s
        $blood_type,              // 48 - s
        $health_problem,          // 49 - s
        $vaccination,             // 50 - s
        $vaccine_type,            // 51 - s
        $place_vaccination,       // 52 - s
        $date_vaccination,        // 53 - s
        $health_ins,              // 54 - s
        $private_specify_text,    // 55 - s
        $rss_assignment,          // 56 - s
        $assigned_job_position,   // 57 - s
        $inclusive_dates,         // 58 - s
        $rss_site_address,        // 59 - s
        $signature_image          // 60 - s
    );
    
    if ($stmt->execute()) {
        $enrollment_id = $stmt->insert_id;
        
        // Find Adviser for the section
        $adviser_id = null;
        // Fetch department_id from students table first
        $dept_q = $conn->prepare("SELECT department_id FROM students WHERE stud_id = ?");
        $dept_q->bind_param("i", $student_id);
        $dept_q->execute();
        $res_dept = $dept_q->get_result();
        if ($row_dept = $res_dept->fetch_assoc()) {
            $dept_id = $row_dept['department_id'];
            
            $find_adviser = $conn->prepare("SELECT instructor_id FROM section_advisers WHERE department_id = ? AND section = ?");
            $find_adviser->bind_param("is", $dept_id, $section);
            $find_adviser->execute();
            $res_adviser = $find_adviser->get_result();
            if ($row_adviser = $res_adviser->fetch_assoc()) {
                $adviser_id = $row_adviser['instructor_id'];
            }
            $find_adviser->close();

            // FALLBACK: If no specific adviser found for section, assign to any instructor in the department
            if (!$adviser_id) {
                $fallback = $conn->prepare("SELECT inst_id FROM instructors WHERE department_id = ? LIMIT 1");
                $fallback->bind_param("i", $dept_id);
                $fallback->execute();
                $res_fallback = $fallback->get_result();
                if ($row_fallback = $res_fallback->fetch_assoc()) {
                    $adviser_id = $row_fallback['inst_id'];
                }
                $fallback->close();
            }
        }
        $dept_q->close();

        // Insert into section_requests
        $req_sql = "INSERT INTO section_requests (student_id, year_level, section, adviser_id, status) VALUES (?, ?, ?, ?, 'Pending') ON DUPLICATE KEY UPDATE status = 'Pending', adviser_id = VALUES(adviser_id), section = VALUES(section), year_level = VALUES(year_level), decline_reason = NULL";
        $req = $conn->prepare($req_sql);
        $req->bind_param("iisi", $student_id, $year_level, $section, $adviser_id);
        $req->execute();
        $req->close();
        
        // DO NOT update students table yet (wait for approval)
        /*
        $update_student = $conn->prepare("UPDATE students SET year_level = ?, section = ? WHERE stud_id = ?");
        $update_student->bind_param("isi", $year_level, $section, $student_id);
        $update_student->execute();
        $update_student->close();
        */
        
        // Update progress
        $prog = $conn->prepare("INSERT INTO rss_progress (student_id, enrollment_completed, enrollment_date, completion_percentage) VALUES (?, TRUE, NOW(), 25) ON DUPLICATE KEY UPDATE enrollment_completed = TRUE, enrollment_date = NOW(), completion_percentage = 25");
        $prog->bind_param("i", $student_id);
        $prog->execute();
        $prog->close();
        
        $_SESSION['enrollment_id'] = $enrollment_id;
        $_SESSION['flash'] = "✅ Enrollment form submitted successfully!";
        
        // Determine dashboard based on department
        $departments = [
            1 => "College of Education",
            2 => "College of Technology",
            3 => "College of Hospitality and Tourism Management"
        ];
        
        header("Location: pending_requirements.php");
        exit;
    } else {
        $error = "❌ Database Error: " . $stmt->error;
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RSS Enrollment Form</title>
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
            --shadow: 0 8px 32px rgba(0,0,0,0.1);
            --border-radius: 16px;
            --transition: all 0.3s ease;
        }
        
        body { 
            font-family: 'Urbanist', sans-serif;
            background: var(--bg);
            padding: 20px;
            min-height: 100vh;
        }
        
        body::before {
            content: "";
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: url('../img/bg.jpg') no-repeat center center/cover;
            filter: blur(8px) brightness(0.8);
            z-index: -1;
        }
        
        .form-container { 
            background: var(--card);
            border-radius: var(--border-radius);
            padding: 40px;
            max-width: 1200px;
            margin: 0 auto;
            box-shadow: var(--shadow);
            position: relative;
        }
        
        .form-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--accent), var(--primary));
            border-radius: var(--border-radius) var(--border-radius) 0 0;
        }
        
        .section-title { 
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            color: white;
            padding: 12px 20px;
            border-radius: 10px;
            margin: 25px 0 15px 0;
            font-weight: 700;
            display: flex;
            align-items: center;
        }
        
        .section-title i {
            margin-right: 10px;
        }
        
        .form-control, .form-select {
            border-radius: 10px;
            border: 1px solid rgba(0,0,0,0.1);
            padding: 10px 15px;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 0.2rem rgba(79, 178, 216, 0.25);
        }
        
        .form-label {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 8px;
        }
        
        .signature-pad { 
            border: 2px solid var(--accent);
            border-radius: 10px;
            cursor: crosshair;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .btn-custom { 
            background: linear-gradient(90deg, var(--accent), var(--primary));
            color: white;
            border: none;
            border-radius: 10px;
            padding: 12px 30px;
            font-weight: 600;
            transition: var(--transition);
        }
        
        .btn-custom:hover { 
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            color: white;
        }
        
        .btn-secondary {
            border-radius: 10px;
            font-weight: 600;
        }
        
        h2, h3 {
            color: var(--primary);
            font-weight: 700;
        }
        
        h6 {
            color: var(--secondary);
            font-weight: 600;
            margin-top: 20px;
        }
        
        .alert {
            border-radius: 10px;
            border: none;
        }
        
        .form-check-input:checked {
            background-color: var(--accent);
            border-color: var(--accent);
        }
    </style>
</head>
<body>
<div class="form-container">
    <div class="text-center mb-4">
        <h3><i class="fas fa-university me-2"></i>Lapu-Lapu City College</h3>
        <p class="mb-0">Don B. Benedicto Rd., Gun-ob, Lapu-Lapu City, 6015</p>
        <p><em>School Code: 7174</em></p>
    </div>
    
    <h2 class="text-center mb-4">
        <i class="fas fa-file-alt me-2"></i>RETURN SERVICE SYSTEM (RSS)<br>Enrollment Form
    </h2>
    
    <?php if (isset($error)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle me-2"></i><?= $error ?>
        </div>
    <?php endif; ?>
    
    <form method="POST" enctype="multipart/form-data" id="enrollmentForm">
        
        <!-- A. PERSONAL DATA -->
        <div class="section-title"><i class="fas fa-user"></i>A. PERSONAL DATA</div>
        <div class="row mb-3">
            <div class="col-md-4">
                <label class="form-label">Surname *</label>
                <input type="text" class="form-control" name="surname" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Given Name *</label>
                <input type="text" class="form-control" name="given_name" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Middle Name</label>
                <input type="text" class="form-control" name="middle_name">
            </div>
        </div>
        
        <div class="row mb-3">
            <div class="col-md-8">
                <label class="form-label">Student ID Number *</label>
                <input type="text" class="form-control" name="student_number" value="<?= htmlspecialchars($student['student_number'] ?? '') ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Upload Photo *</label>
                <input type="file" class="form-control" name="photo" accept="image/*" required>
            </div>
        </div>
        
        <div class="mb-3">
            <label class="form-label">College *</label><br>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" name="college[]" value="COED" id="coed">
                <label class="form-check-label" for="coed">COED</label>
            </div>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" name="college[]" value="COT" id="cot">
                <label class="form-check-label" for="cot">COT</label>
            </div>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" name="college[]" value="CHTM" id="chtm">
                <label class="form-check-label" for="chtm">CHTM</label>
            </div>
        </div>
        
        <div class="row mb-3">
            <div class="col-md-4">
                <label class="form-label">Course *</label>
                <input type="text" class="form-control" name="course" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Major</label>
                <input type="text" class="form-control" name="major">
            </div>
            <div class="col-md-2">
                <label class="form-label">Year Level *</label>
                <select class="form-select" name="year_level" required>
                    <option value="">Select</option>
                    <option value="1">1st Year</option>
                    <option value="2">2nd Year</option>
                    <option value="3">3rd Year</option>
                    <option value="4">4th Year</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Section *</label>
                <select class="form-select" name="section" required>
                    <option value="">Select</option>
                    <option value="A">A</option>
                    <option value="B">B</option>
                    <option value="C">C</option>
                    <option value="D">D</option>
                    <option value="AE">AE</option>
                </select>
            </div>
        </div>
        
        <div class="mb-3">
            <label class="form-label">City Address *</label>
            <textarea class="form-control" name="city_address" rows="2" required></textarea>
        </div>
        
        <div class="row mb-3">
            <div class="col-md-4">
                <label class="form-label">Gender *</label><br>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="gender" value="Female" id="female" required>
                    <label class="form-check-label" for="female">Female</label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="gender" value="Male" id="male">
                    <label class="form-check-label" for="male">Male</label>
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Contact Number *</label>
                <input type="text" class="form-control" name="contact_number" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Email Address *</label>
                <input type="email" class="form-control" name="email_address" value="<?= htmlspecialchars($student['email']) ?>" required>
            </div>
        </div>
        
        <div class="row mb-3">
            <div class="col-md-4">
                <label class="form-label">Birth Date *</label>
                <input type="date" class="form-control" name="birth_date" required>
            </div>
            <div class="col-md-8">
                <label class="form-label">Birth Place *</label>
                <input type="text" class="form-control" name="birth_place" required>
            </div>
        </div>
        
        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label">Provincial Address</label>
                <textarea class="form-control" name="provincial_address" rows="2"></textarea>
            </div>
            <div class="col-md-3">
                <label class="form-label">Religion</label>
                <input type="text" class="form-control" name="religion">
            </div>
            <div class="col-md-3">
                <label class="form-label">Marital Status *</label>
                <input type="text" class="form-control" name="marital_status" required>
            </div>
        </div>
        
        <!-- B. FAMILY DATA -->
        <div class="section-title"><i class="fas fa-users"></i>B. FAMILY DATA</div>
        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label">Father's Name</label>
                <input type="text" class="form-control" name="father_name">
            </div>
            <div class="col-md-6">
                <label class="form-label">Mother's Name</label>
                <input type="text" class="form-control" name="mother_name">
            </div>
        </div>
        
        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label">Father's Occupation</label>
                <input type="text" class="form-control" name="father_occupation">
            </div>
            <div class="col-md-6">
                <label class="form-label">Mother's Occupation</label>
                <input type="text" class="form-control" name="mother_occupation">
            </div>
        </div>
        
        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label">Father's Company</label>
                <input type="text" class="form-control" name="father_company">
            </div>
            <div class="col-md-6">
                <label class="form-label">Mother's Company</label>
                <input type="text" class="form-control" name="mother_company">
            </div>
        </div>
        
        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label">Father's Company Address</label>
                <textarea class="form-control" name="father_company_address" rows="2"></textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label">Mother's Company Address</label>
                <textarea class="form-control" name="mother_company_address" rows="2"></textarea>
            </div>
        </div>
        
        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label">Father's Contact Number</label>
                <input type="text" class="form-control" name="father_contact">
            </div>
            <div class="col-md-6">
                <label class="form-label">Mother's Contact Number</label>
                <input type="text" class="form-control" name="mother_contact">
            </div>
        </div>
        
        <div class="mb-3">
            <label class="form-label">Guardian's Name (if applicable)</label>
            <input type="text" class="form-control" name="guardian_name">
        </div>
        
        <div class="mb-3">
            <label class="form-label">Guardian's Address</label>
            <textarea class="form-control" name="guardian_address" rows="2"></textarea>
        </div>
        
        <div class="mb-3">
            <label class="form-label">Guardian's Contact Number</label>
            <input type="text" class="form-control" name="guardian_contact">
        </div>
        
        <!-- C. SCHOLASTIC DATA -->
        <div class="section-title"><i class="fas fa-graduation-cap"></i>C. SCHOLASTIC DATA</div>
        
        <h6 class="mt-3"><i class="fas fa-university me-2"></i>Tertiary</h6>
        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label">School Name</label>
                <input type="text" class="form-control" name="tertiary_school">
            </div>
            <div class="col-md-6">
                <label class="form-label">Address</label>
                <input type="text" class="form-control" name="tertiary_address">
            </div>
        </div>
        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label">Year Graduated</label>
                <input type="text" class="form-control" name="tertiary_year_grad">
            </div>
            <div class="col-md-6">
                <label class="form-label">Honors/Awards</label>
                <input type="text" class="form-control" name="tertiary_honors">
            </div>
        </div>
        
        <h6 class="mt-3"><i class="fas fa-school me-2"></i>Secondary</h6>
        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label">School Name</label>
                <input type="text" class="form-control" name="secondary_school">
            </div>
            <div class="col-md-6">
                <label class="form-label">Address</label>
                <input type="text" class="form-control" name="secondary_address">
            </div>
        </div>
        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label">Year Graduated</label>
                <input type="text" class="form-control" name="secondary_year_grad">
            </div>
            <div class="col-md-6">
                <label class="form-label">Honors/Awards</label>
                <input type="text" class="form-control" name="secondary_honors">
            </div>
        </div>
        
        <h6 class="mt-3"><i class="fas fa-child me-2"></i>Primary</h6>
        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label">School Name</label>
                <input type="text" class="form-control" name="primary_school">
            </div>
            <div class="col-md-6">
                <label class="form-label">Address</label>
                <input type="text" class="form-control" name="primary_address">
            </div>
        </div>
        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label">Year Graduated</label>
                <input type="text" class="form-control" name="primary_year_grad">
            </div>
            <div class="col-md-6">
                <label class="form-label">Honors/Awards</label>
                <input type="text" class="form-control" name="primary_honors">
            </div>
        </div>
        
        <!-- D. HEALTH DATA -->
        <div class="section-title"><i class="fas fa-heartbeat"></i>D. HEALTH DATA</div>
        <div class="row mb-3">
            <div class="col-md-3">
                <label class="form-label">Height</label>
                <input type="text" class="form-control" name="height" placeholder="e.g. 5'6">
            </div>
            <div class="col-md-3">
                <label class="form-label">Weight</label>
                <input type="text" class="form-control" name="weight" placeholder="e.g. 60kg">
            </div>
            <div class="col-md-3">
                <label class="form-label">Blood Type</label>
                <input type="text" class="form-control" name="blood_type">
            </div>
            <div class="col-md-3">
                <label class="form-label">Health Problem</label>
                <input type="text" class="form-control" name="health_problem">
            </div>
        </div>
        
        <div class="mb-3">
            <label class="form-label">Vaccination Status</label><br>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" name="vaccination_status[]" value="Unvaccinated" id="unvaccinated">
                <label class="form-check-label" for="unvaccinated">Unvaccinated</label>
            </div>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" name="vaccination_status[]" value="First Dose" id="first_dose">
                <label class="form-check-label" for="first_dose">First Dose</label>
            </div>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" name="vaccination_status[]" value="Second Dose" id="second_dose">
                <label class="form-check-label" for="second_dose">Second Dose</label>
            </div>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" name="vaccination_status[]" value="Booster" id="booster">
                <label class="form-check-label" for="booster">Booster</label>
            </div>
        </div>
        
        <div class="row mb-3">
            <div class="col-md-4">
                <label class="form-label">Type of Vaccine</label>
                <input type="text" class="form-control" name="vaccine_type">
            </div>
            <div class="col-md-4">
                <label class="form-label">Place of Vaccination</label>
                <input type="text" class="form-control" name="place_vaccination">
            </div>
            <div class="col-md-4">
                <label class="form-label">Date of Vaccination</label>
                <input type="date" class="form-control" name="date_vaccination">
            </div>
        </div>
        
        <div class="mb-3">
            <label class="form-label">Health Insurance</label><br>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" name="health_insurance[]" value="PhilHealth" id="philhealth">
                <label class="form-check-label" for="philhealth">PhilHealth</label>
            </div>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" name="health_insurance[]" value="Private" id="private_ins">
                <label class="form-check-label" for="private_ins">Private</label>
            </div>
            <input type="text" class="form-control d-inline w-25 ms-2" name="private_specify_text" placeholder="Specify...">
        </div>
        
        <!-- SIGNATURE -->
        <div class="section-title"><i class="fas fa-signature"></i>STUDENT SIGNATURE</div>
        <p>I hereby affix my signature to attest the above data and statement are true and correct.</p>
        <div class="text-center mb-3">
            <label class="form-label fw-bold">Student Signature Over Printed Name</label><br>
            <canvas id="signature-pad" class="signature-pad" width="500" height="150"></canvas><br>
            <button type="button" class="btn btn-secondary mt-2" id="clear-signature">
                <i class="fas fa-eraser me-1"></i>Clear Signature
            </button>
            <input type="hidden" name="signature_image" id="signature_image">
        </div>
        
        <div class="text-center mt-4">
            <button type="submit" class="btn btn-custom btn-lg px-5">
                <i class="fas fa-paper-plane me-2"></i>Submit Enrollment Form
            </button>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Signature Pad
const canvas = document.getElementById('signature-pad');
const ctx = canvas.getContext('2d');
let drawing = false;

ctx.strokeStyle = '#000';
ctx.lineWidth = 2;

canvas.addEventListener('mousedown', (e) => {
    drawing = true;
    ctx.beginPath();
    ctx.moveTo(e.offsetX, e.offsetY);
});

canvas.addEventListener('mousemove', (e) => {
    if (drawing) {
        ctx.lineTo(e.offsetX, e.offsetY);
        ctx.stroke();
    }
});

canvas.addEventListener('mouseup', () => drawing = false);
canvas.addEventListener('mouseout', () => drawing = false);

// Touch events for mobile
canvas.addEventListener('touchstart', (e) => {
    e.preventDefault();
    const touch = e.touches[0];
    const rect = canvas.getBoundingClientRect();
    drawing = true;
    ctx.beginPath();
    ctx.moveTo(touch.clientX - rect.left, touch.clientY - rect.top);
});

canvas.addEventListener('touchmove', (e) => {
    e.preventDefault();
    if (drawing) {
        const touch = e.touches[0];
        const rect = canvas.getBoundingClientRect();
        ctx.lineTo(touch.clientX - rect.left, touch.clientY - rect.top);
        ctx.stroke();
    }
});

canvas.addEventListener('touchend', () => drawing = false);

document.getElementById('clear-signature').addEventListener('click', () => {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
});

// Save signature on submit
document.getElementById('enrollmentForm').addEventListener('submit', function(e) {
    const dataURL = canvas.toDataURL();
    document.getElementById('signature_image').value = dataURL;
});
</script>
</body>
</html