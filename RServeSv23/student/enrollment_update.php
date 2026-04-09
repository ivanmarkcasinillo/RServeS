<?php
session_start();
require "dbconnect.php";

// Check if student is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Student') {
    header("Location: profset.php");
    exit;
}

$student_id = $_SESSION['stud_id'] ?? null;
if (!$student_id) {
    die("Student ID not found in session.");
}

// Get student info
$stmt = $conn->prepare("SELECT firstname, lastname, email, department_id FROM students WHERE stud_id = ?");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Check if already enrolled and get existing data
$check = $conn->prepare("SELECT * FROM rss_enrollments WHERE student_id = ?");
$check->bind_param("i", $student_id);
$check->execute();
$existing = $check->get_result()->fetch_assoc();
$check->close();

$is_edit_mode = !empty($existing);
$enrollment_data = $existing ?: [];

// Handle form submission (INSERT or UPDATE)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Handle photo upload
    $photo_path = $enrollment_data['photo_path'] ?? null; // Keep existing photo if not uploading new one
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/enrollment_photos/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
        $new_filename = 'student_' . $student_id . '_' . time() . '.' . $file_ext;
        $photo_path = $upload_dir . $new_filename;
        
        // Delete old photo if exists
        if (!empty($enrollment_data['photo_path']) && file_exists($enrollment_data['photo_path'])) {
            unlink($enrollment_data['photo_path']);
        }
        
        move_uploaded_file($_FILES['photo']['tmp_name'], $photo_path);
    }
    
    // Store ALL POST values in variables (REQUIRED for bind_param by-reference)
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
    
    if ($is_edit_mode) {
        // UPDATE existing record
        $sql = "UPDATE rss_enrollments SET 
            surname = ?, 
            given_name = ?, 
            middle_name = ?, 
            student_number = ?,
            college = ?, 
            course = ?, 
            major = ?, 
            year_level = ?, 
            section = ?, 
            city_address = ?, 
            gender = ?,
            contact_number = ?, 
            email_address = ?, 
            birth_date = ?, 
            birth_place = ?,
            provincial_address = ?, 
            religion = ?, 
            marital_status = ?, 
            photo_path = ?,
            father_name = ?, 
            father_occupation = ?, 
            father_company = ?, 
            father_company_address = ?, 
            father_contact = ?,
            mother_name = ?, 
            mother_occupation = ?, 
            mother_company = ?, 
            mother_company_address = ?, 
            mother_contact = ?,
            guardian_name = ?, 
            guardian_address = ?, 
            guardian_contact = ?,
            tertiary_school = ?, 
            tertiary_address = ?, 
            tertiary_year_grad = ?, 
            tertiary_honors = ?,
            secondary_school = ?, 
            secondary_address = ?, 
            secondary_year_grad = ?, 
            secondary_honors = ?,
            primary_school = ?, 
            primary_address = ?, 
            primary_year_grad = ?, 
            primary_honors = ?,
            height = ?, 
            weight = ?, 
            blood_type = ?, 
            health_problem = ?, 
            vaccination_status = ?,
            vaccine_type = ?, 
            place_vaccination = ?, 
            date_vaccination = ?, 
            health_insurance = ?, 
            private_insurance_details = ?,
            rss_assignment = ?, 
            assigned_job_position = ?, 
            inclusive_dates = ?, 
            rss_site_address = ?,
            signature_image = ?
            WHERE student_id = ?";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            die("❌ SQL PREPARE FAILED: " . $conn->error);
        }
        
        // Bind 59 parameters + student_id at the end
        // Types: 7 strings, 1 int (year_level), 51 strings, 1 int (student_id)
        // Total 60 params
        
        $types = "sssssssi" . str_repeat("s", 51) . "i";
        
        $stmt->bind_param(
            $types,
            $surname,
            $given_name,
            $middle_name,
            $student_number,
            $college_values,
            $course,
            $major,
            $year_level,
            $section,
            $city_address,
            $gender,
            $contact_number,
            $email_address,
            $birth_date,
            $birth_place,
            $provincial_address,
            $religion,
            $marital_status,
            $photo_path,
            $father_name,
            $father_occupation,
            $father_company,
            $father_company_address,
            $father_contact,
            $mother_name,
            $mother_occupation,
            $mother_company,
            $mother_company_address,
            $mother_contact,
            $guardian_name,
            $guardian_address,
            $guardian_contact,
            $tertiary_school,
            $tertiary_address,
            $tertiary_year_grad,
            $tertiary_honors,
            $secondary_school,
            $secondary_address,
            $secondary_year_grad,
            $secondary_honors,
            $primary_school,
            $primary_address,
            $primary_year_grad,
            $primary_honors,
            $height,
            $weight,
            $blood_type,
            $health_problem,
            $vaccination,
            $vaccine_type,
            $place_vaccination,
            $date_vaccination,
            $health_ins,
            $private_specify_text,
            $rss_assignment,
            $assigned_job_position,
            $inclusive_dates,
            $rss_site_address,
            $signature_image,
            $student_id
        );
        
        if ($stmt->execute()) {
            // Find Adviser for the section
            $adviser_id = null;
            $dept_id = $student['department_id'];
            $find_adviser = $conn->prepare("SELECT instructor_id FROM section_advisers WHERE department_id = ? AND section = ?");
            $find_adviser->bind_param("is", $dept_id, $section);
            $find_adviser->execute();
            $res_adviser = $find_adviser->get_result();
            if ($row_adviser = $res_adviser->fetch_assoc()) {
                $adviser_id = $row_adviser['instructor_id'];
            }
            $find_adviser->close();

            // Insert into section_requests
            $req_sql = "INSERT INTO section_requests (student_id, year_level, section, adviser_id, status) VALUES (?, ?, ?, ?, 'Pending')";
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

            // Update progress (Form submitted, but enrollment pending approval)
            // We keep this to track form completion, but maybe add a note
            $prog = $conn->prepare("INSERT INTO rss_progress (student_id, enrollment_completed, enrollment_date, completion_percentage) VALUES (?, TRUE, NOW(), 25) ON DUPLICATE KEY UPDATE enrollment_completed = TRUE, enrollment_date = NOW(), completion_percentage = 25");
            $prog->bind_param("i", $student_id);
            $prog->execute();
            $prog->close();

            $_SESSION['flash'] = "✅ Enrollment submitted! Waiting for Adviser Approval.";
            
            // Redirect to dashboard
            $departments = [
                1 => "College of Education",
                2 => "College of Technology",
                3 => "College of Hospitality and Tourism Management"
            ];
            $dept_id = $_SESSION['department_id'] ?? 2;
            $dept_name = $departments[$dept_id] ?? "College of Technology";
            $dept_code = strtolower(str_replace(' ', '_', $dept_name));
            $dashboard_file = "student_{$dept_code}_dashboard.php";
            
            header("Location: $dashboard_file");
            exit;
        } else {
            $error = "❌ Update Error: " . $stmt->error;
        }
        
    } else {
        // INSERT new record
        $sql = "INSERT INTO rss_enrollments (
            student_id, 
            surname, 
            given_name, 
            middle_name, 
            student_number,
            college, 
            course, 
            major, 
            year_level, 
            section, 
            city_address, 
            gender,
            contact_number, 
            email_address, 
            birth_date, 
            birth_place,
            provincial_address, 
            religion, 
            marital_status, 
            photo_path,
            father_name, 
            father_occupation, 
            father_company, 
            father_company_address, 
            father_contact,
            mother_name, 
            mother_occupation, 
            mother_company, 
            mother_company_address, 
            mother_contact,
            guardian_name, 
            guardian_address, 
            guardian_contact,
            tertiary_school, 
            tertiary_address, 
            tertiary_year_grad, 
            tertiary_honors,
            secondary_school, 
            secondary_address, 
            secondary_year_grad, 
            secondary_honors,
            primary_school, 
            primary_address, 
            primary_year_grad, 
            primary_honors,
            height, 
            weight, 
            blood_type, 
            health_problem, 
            vaccination_status,
            vaccine_type, 
            place_vaccination, 
            date_vaccination, 
            health_insurance, 
            private_insurance_details,
            rss_assignment, 
            assigned_job_position, 
            inclusive_dates, 
            rss_site_address,
            signature_image
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            die("❌ SQL PREPARE FAILED: " . $conn->error);
        }
        
        // Bind 60 parameters (1 int (student_id) + 7 strings + 1 int (year_level) + 51 strings)
        // Total 60.
        
        $types = "isssssssi" . str_repeat("s", 51);
        
        $stmt->bind_param(
            $types,
            $student_id,
            $surname,
            $given_name,
            $middle_name,
            $student_number,
            $college_values,
            $course,
            $major,
            $year_level,
            $section,
            $city_address,
            $gender,
            $contact_number,
            $email_address,
            $birth_date,
            $birth_place,
            $provincial_address,
            $religion,
            $marital_status,
            $photo_path,
            $father_name,
            $father_occupation,
            $father_company,
            $father_company_address,
            $father_contact,
            $mother_name,
            $mother_occupation,
            $mother_company,
            $mother_company_address,
            $mother_contact,
            $guardian_name,
            $guardian_address,
            $guardian_contact,
            $tertiary_school,
            $tertiary_address,
            $tertiary_year_grad,
            $tertiary_honors,
            $secondary_school,
            $secondary_address,
            $secondary_year_grad,
            $secondary_honors,
            $primary_school,
            $primary_address,
            $primary_year_grad,
            $primary_honors,
            $height,
            $weight,
            $blood_type,
            $health_problem,
            $vaccination,
            $vaccine_type,
            $place_vaccination,
            $date_vaccination,
            $health_ins,
            $private_specify_text,
            $rss_assignment,
            $assigned_job_position,
            $inclusive_dates,
            $rss_site_address,
            $signature_image
        );
        
        if ($stmt->execute()) {
            $enrollment_id = $stmt->insert_id;
            
            // Find Adviser for the section
            $adviser_id = null;
            $dept_id = $student['department_id'];
            $find_adviser = $conn->prepare("SELECT instructor_id FROM section_advisers WHERE department_id = ? AND section = ?");
            $find_adviser->bind_param("is", $dept_id, $section);
            $find_adviser->execute();
            $res_adviser = $find_adviser->get_result();
            if ($row_adviser = $res_adviser->fetch_assoc()) {
                $adviser_id = $row_adviser['instructor_id'];
            }
            $find_adviser->close();

            // Insert into section_requests
            $req_sql = "INSERT INTO section_requests (student_id, year_level, section, adviser_id, status) VALUES (?, ?, ?, ?, 'Pending')";
            $req = $conn->prepare($req_sql);
            $req->bind_param("iisi", $student_id, $year_level, $section, $adviser_id);
            $req->execute();
            $req->close();

            // DO NOT update students table yet
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
            $_SESSION['flash'] = "✅ Enrollment submitted! Waiting for Adviser Approval.";
            header("Location: aggreementform.php");
            exit;
        } else {
            $error = "❌ Database Error: " . $stmt->error;
        }
    }
    $stmt->close();
}

// Helper function to get value
function getValue($data, $key, $default = '') {
    return $data[$key] ?? $default;
}

// Helper function to check if checkbox should be checked
function isChecked($data, $key, $value) {
    if (empty($data[$key])) return false;
    $values = explode(',', $data[$key]);
    return in_array($value, $values);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $is_edit_mode ? 'Edit' : 'New' ?> RSS Enrollment Form</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Urbanist:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #1a4f7a;
            --secondary-color: #123755;
            --accent-color: #3a8ebd;
            --bg-color: #f4f7f6;
            --text-dark: #2c3e50;
        }
        
        body { 
            font-family: 'Urbanist', sans-serif;
            background: var(--bg-color);
            color: var(--text-dark);
            padding: 20px; 
        }
        
        .form-container { 
            background: white; 
            border-radius: 15px; 
            padding: 30px; 
            max-width: 1200px; 
            margin: 0 auto; 
            box-shadow: 0 15px 35px rgba(26, 79, 122, 0.1); 
            border: 1px solid rgba(255, 255, 255, 0.5);
        }
        
        .section-title { 
            background: linear-gradient(90deg, var(--secondary-color), var(--primary-color));
            color: white; 
            padding: 12px 20px; 
            border-radius: 8px; 
            margin: 25px 0 20px 0; 
            font-weight: 600; 
            letter-spacing: 0.5px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        
        .photo-preview { 
            max-width: 150px; 
            max-height: 180px; 
            border: 2px solid var(--primary-color); 
            border-radius: 8px; 
            margin-top: 10px; 
            padding: 3px;
        }
        
        .signature-pad { 
            border: 2px solid var(--primary-color); 
            border-radius: 8px; 
            cursor: crosshair; 
            background: white; 
        }
        
        .btn-custom { 
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); 
            color: white; 
            border: none; 
            padding: 12px 30px;
            font-weight: 600;
            letter-spacing: 0.5px;
            transition: all 0.3s ease;
        }
        
        .btn-custom:hover { 
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(26, 79, 122, 0.3);
            color: white;
        }
        
        .edit-badge { 
            background: var(--accent-color); 
            color: white; 
            padding: 5px 15px; 
            border-radius: 20px; 
            font-size: 14px; 
            font-weight: 600;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--accent-color);
            box-shadow: 0 0 0 0.25rem rgba(58, 142, 189, 0.25);
        }
        
        h2, h3 {
            color: var(--secondary-color);
            font-weight: 700;
        }
    </style>
</head>
<body>
<div class="form-container">
    <div class="text-center mb-4">
        <h3 style="color: var(--primary-color);">Lapu-Lapu City College</h3>
        <p class="mb-0">Don B. Benedicto Rd., Gun-ob, Lapu-Lapu City, 6015</p>
        <p><em>School Code: 7174</em></p>
        <?php if ($is_edit_mode): ?>
            <span class="edit-badge">EDITING MODE</span>
        <?php endif; ?>
    </div>
    
    <h2 class="text-center mb-4" style="color: var(--secondary-color);">RETURN SERVICE SYSTEM (RSS)<br>Enrollment Form</h2>
    
    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?= $error ?></div>
    <?php endif; ?>
    
    <form method="POST" enctype="multipart/form-data" id="enrollmentForm">
        
        <!-- A. PERSONAL DATA -->
        <div class="section-title">A. PERSONAL DATA</div>
        <div class="row mb-3">
            <div class="col-md-4">
                <label class="form-label">Surname *</label>
                <input type="text" class="form-control" name="surname" value="<?= htmlspecialchars(getValue($enrollment_data, 'surname')) ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Given Name *</label>
                <input type="text" class="form-control" name="given_name" value="<?= htmlspecialchars(getValue($enrollment_data, 'given_name')) ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Middle Name</label>
                <input type="text" class="form-control" name="middle_name" value="<?= htmlspecialchars(getValue($enrollment_data, 'middle_name')) ?>">
            </div>
        </div>
        
        <div class="row mb-3">
            <div class="col-md-8">
                <label class="form-label">Student ID Number *</label>
                <input type="text" class="form-control" name="student_number" value="<?= htmlspecialchars(getValue($enrollment_data, 'student_number')) ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Upload Photo <?= $is_edit_mode ? '(Leave empty to keep current)' : '*' ?></label>
                <input type="file" class="form-control" name="photo" accept="image/*" <?= $is_edit_mode ? '' : 'required' ?>>
                <?php if ($is_edit_mode && !empty($enrollment_data['photo_path'])): ?>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="mb-3">
            <label class="form-label">College *</label><br>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" name="college[]" value="COED" id="coed" <?= isChecked($enrollment_data, 'college', 'COED') ? 'checked' : '' ?>>
                <label class="form-check-label" for="coed">COED</label>
            </div>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" name="college[]" value="COT" id="cot" <?= isChecked($enrollment_data, 'college', 'COT') ? 'checked' : '' ?>>
                <label class="form-check-label" for="cot">COT</label>
            </div>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" name="college[]" value="CHTM" id="chtm" <?= isChecked($enrollment_data, 'college', 'CHTM') ? 'checked' : '' ?>>
                <label class="form-check-label" for="chtm">CHTM</label>
            </div>
        </div>
        
        <div class="row mb-3">
            <div class="col-md-4">
                <label class="form-label">Course *</label>
                <input type="text" class="form-control" name="course" value="<?= htmlspecialchars(getValue($enrollment_data, 'course')) ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Major</label>
                <input type="text" class="form-control" name="major" value="<?= htmlspecialchars(getValue($enrollment_data, 'major')) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Year Level *</label>
                <select class="form-select" name="year_level" required>
                    <option value="">Select</option>
                    <option value="1" <?= getValue($enrollment_data, 'year_level') == 1 ? 'selected' : '' ?>>1st Year</option>
                    <option value="2" <?= getValue($enrollment_data, 'year_level') == 2 ? 'selected' : '' ?>>2nd Year</option>
                    <option value="3" <?= getValue($enrollment_data, 'year_level') == 3 ? 'selected' : '' ?>>3rd Year</option>
                    <option value="4" <?= getValue($enrollment_data, 'year_level') == 4 ? 'selected' : '' ?>>4th Year</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Section *</label>
                <select class="form-select" name="section" required>
                    <option value="">Select</option>
                    <option value="A" <?= getValue($enrollment_data, 'section') == 'A' ? 'selected' : '' ?>>A</option>
                    <option value="B" <?= getValue($enrollment_data, 'section') == 'B' ? 'selected' : '' ?>>B</option>
                    <option value="C" <?= getValue($enrollment_data, 'section') == 'C' ? 'selected' : '' ?>>C</option>
                    <option value="D" <?= getValue($enrollment_data, 'section') == 'D' ? 'selected' : '' ?>>D</option>
                    <option value="AE" <?= getValue($enrollment_data, 'section') == 'AE' ? 'selected' : '' ?>>AE</option>
                </select>
            </div>
        </div>
        
        <div class="mb-3">
            <label class="form-label">City Address *</label>
            <textarea class="form-control" name="city_address" rows="2" required><?= htmlspecialchars(getValue($enrollment_data, 'city_address')) ?></textarea>
        </div>
        
        <div class="row mb-3">
            <div class="col-md-4">
                <label class="form-label">Gender *</label><br>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="gender" value="Female" id="female" <?= getValue($enrollment_data, 'gender') === 'Female' ? 'checked' : '' ?> required>
                    <label class="form-check-label" for="female">Female</label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="gender" value="Male" id="male" <?= getValue($enrollment_data, 'gender') === 'Male' ? 'checked' : '' ?> required>
                    <label class="form-check-label" for="male">Male</label>
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Contact Number *</label>
                <input type="text" class="form-control" name="contact_number" value="<?= htmlspecialchars(getValue($enrollment_data, 'contact_number')) ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Email Address *</label>
                <input type="email" class="form-control" name="email_address" value="<?= htmlspecialchars(getValue($enrollment_data, 'email_address', $student['email'])) ?>" required>
            </div>
        </div>
        
        <div class="row mb-3">
            <div class="col-md-4">
                <label class="form-label">Birth Date *</label>
                <input type="date" class="form-control" name="birth_date" value="<?= htmlspecialchars(getValue($enrollment_data, 'birth_date')) ?>" required>
            </div>
            <div class="col-md-8">
                <label class="form-label">Birth Place *</label>
                <input type="text" class="form-control" name="birth_place" value="<?= htmlspecialchars(getValue($enrollment_data, 'birth_place')) ?>" required>
            </div>
        </div>
        
        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label">Provincial Address</label>
                <textarea class="form-control" name="provincial_address" rows="2"><?= htmlspecialchars(getValue($enrollment_data, 'provincial_address')) ?></textarea>
            </div>
            <div class="col-md-3">
                <label class="form-label">Religion</label>
                <input type="text" class="form-control" name="religion" value="<?= htmlspecialchars(getValue($enrollment_data, 'religion')) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Marital Status *</label>
                <input type="text" class="form-control" name="marital_status" value="<?= htmlspecialchars(getValue($enrollment_data, 'marital_status')) ?>" required>
            </div>
        </div>
        
        <!-- B. FAMILY DATA -->
        <div class="section-title">B. FAMILY DATA</div>
        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label">Father's Name</label>
                <input type="text" class="form-control" name="father_name" value="<?= htmlspecialchars(getValue($enrollment_data, 'father_name')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Mother's Name</label>
                <input type="text" class="form-control" name="mother_name" value="<?= htmlspecialchars(getValue($enrollment_data, 'mother_name')) ?>">
            </div>
        </div>
        
        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label">Father's Occupation</label>
                <input type="text" class="form-control" name="father_occupation" value="<?= htmlspecialchars(getValue($enrollment_data, 'father_occupation')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Mother's Occupation</label>
                <input type="text" class="form-control" name="mother_occupation" value="<?= htmlspecialchars(getValue($enrollment_data, 'mother_occupation')) ?>">
            </div>
        </div>
        
        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label">Father's Company</label>
                <input type="text" class="form-control" name="father_company" value="<?= htmlspecialchars(getValue($enrollment_data, 'father_company')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Mother's Company</label>
                <input type="text" class="form-control" name="mother_company" value="<?= htmlspecialchars(getValue($enrollment_data, 'mother_company')) ?>">
            </div>
        </div>
        
        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label">Father's Company Address</label>
                <textarea class="form-control" name="father_company_address" rows="2"><?= htmlspecialchars(getValue($enrollment_data, 'father_company_address')) ?></textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label">Mother's Company Address</label>
                <textarea class="form-control" name="mother_company_address" rows="2"><?= htmlspecialchars(getValue($enrollment_data, 'mother_company_address')) ?></textarea>
            </div>
        </div>
        
        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label">Father's Contact Number</label>
                <input type="text" class="form-control" name="father_contact" value="<?= htmlspecialchars(getValue($enrollment_data, 'father_contact')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Mother's Contact Number</label>
                <input type="text" class="form-control" name="mother_contact" value="<?= htmlspecialchars(getValue($enrollment_data, 'mother_contact')) ?>">
            </div>
        </div>
        
        <div class="mb-3">
            <label class="form-label">Guardian's Name (if applicable)</label>
            <input type="text" class="form-control" name="guardian_name" value="<?= htmlspecialchars(getValue($enrollment_data, 'guardian_name')) ?>">
        </div>
        
        <div class="mb-3">
            <label class="form-label">Guardian's Address</label>
            <textarea class="form-control" name="guardian_address" rows="2"><?= htmlspecialchars(getValue($enrollment_data, 'guardian_address')) ?></textarea>
        </div>
        
        <div class="mb-3">
            <label class="form-label">Guardian's Contact Number</label>
            <input type="text" class="form-control" name="guardian_contact" value="<?= htmlspecialchars(getValue($enrollment_data, 'guardian_contact')) ?>">
        </div>
        
        <!-- C. EDUCATIONAL BACKGROUND -->
        <div class="section-title">C. EDUCATIONAL BACKGROUND</div>
        
        <h6 class="mt-3">Tertiary</h6>
        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label">Name of School</label>
                <input type="text" class="form-control" name="tertiary_school" value="<?= htmlspecialchars(getValue($enrollment_data, 'tertiary_school')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">School Address</label>
                <input type="text" class="form-control" name="tertiary_address" value="<?= htmlspecialchars(getValue($enrollment_data, 'tertiary_address')) ?>">
            </div>
        </div>
        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label">Year Graduated</label>
                <input type="text" class="form-control" name="tertiary_year_grad" value="<?= htmlspecialchars(getValue($enrollment_data, 'tertiary_year_grad')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Honors Received</label>
                <input type="text" class="form-control" name="tertiary_honors" value="<?= htmlspecialchars(getValue($enrollment_data, 'tertiary_honors')) ?>">
            </div>
        </div>
        
        <h6 class="mt-3">Secondary</h6>
        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label">Name of School</label>
                <input type="text" class="form-control" name="secondary_school" value="<?= htmlspecialchars(getValue($enrollment_data, 'secondary_school')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">School Address</label>
                <input type="text" class="form-control" name="secondary_address" value="<?= htmlspecialchars(getValue($enrollment_data, 'secondary_address')) ?>">
            </div>
        </div>
        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label">Year Graduated</label>
                <input type="text" class="form-control" name="secondary_year_grad" value="<?= htmlspecialchars(getValue($enrollment_data, 'secondary_year_grad')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Honors Received</label>
                <input type="text" class="form-control" name="secondary_honors" value="<?= htmlspecialchars(getValue($enrollment_data, 'secondary_honors')) ?>">
            </div>
        </div>
        
        <h6 class="mt-3">Primary</h6>
        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label">Name of School</label>
                <input type="text" class="form-control" name="primary_school" value="<?= htmlspecialchars(getValue($enrollment_data, 'primary_school')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">School Address</label>
                <input type="text" class="form-control" name="primary_address" value="<?= htmlspecialchars(getValue($enrollment_data, 'primary_address')) ?>">
            </div>
        </div>
        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label">Year Graduated</label>
                <input type="text" class="form-control" name="primary_year_grad" value="<?= htmlspecialchars(getValue($enrollment_data, 'primary_year_grad')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Honors Received</label>
                <input type="text" class="form-control" name="primary_honors" value="<?= htmlspecialchars(getValue($enrollment_data, 'primary_honors')) ?>">
            </div>
        </div>
        
        <!-- D. HEALTH INFORMATION -->
        <div class="section-title">D. HEALTH INFORMATION</div>
        <div class="row mb-3">
            <div class="col-md-3">
                <label class="form-label">Height (cm)</label>
                <input type="text" class="form-control" name="height" value="<?= htmlspecialchars(getValue($enrollment_data, 'height')) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Weight (kg)</label>
                <input type="text" class="form-control" name="weight" value="<?= htmlspecialchars(getValue($enrollment_data, 'weight')) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Blood Type</label>
                <input type="text" class="form-control" name="blood_type" value="<?= htmlspecialchars(getValue($enrollment_data, 'blood_type')) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Health Problems</label>
                <input type="text" class="form-control" name="health_problem" value="<?= htmlspecialchars(getValue($enrollment_data, 'health_problem')) ?>">
            </div>
        </div>
        
        <div class="mb-3">
            <label class="form-label">Vaccination Status</label><br>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" name="vaccination_status[]" value="Fully Vaccinated" id="fully_vax" <?= isChecked($enrollment_data, 'vaccination_status', 'Fully Vaccinated') ? 'checked' : '' ?>>
                <label class="form-check-label" for="fully_vax">Fully Vaccinated</label>
            </div>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" name="vaccination_status[]" value="Partially Vaccinated" id="partial_vax" <?= isChecked($enrollment_data, 'vaccination_status', 'Partially Vaccinated') ? 'checked' : '' ?>>
                <label class="form-check-label" for="partial_vax">Partially Vaccinated</label>
            </div>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" name="vaccination_status[]" value="Not Vaccinated" id="not_vax" <?= isChecked($enrollment_data, 'vaccination_status', 'Not Vaccinated') ? 'checked' : '' ?>>
                <label class="form-check-label" for="not_vax">Not Vaccinated</label>
            </div>
        </div>
        
        <div class="row mb-3">
            <div class="col-md-4">
                <label class="form-label">Vaccine Type</label>
                <input type="text" class="form-control" name="vaccine_type" value="<?= htmlspecialchars(getValue($enrollment_data, 'vaccine_type')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Place of Vaccination</label>
                <input type="text" class="form-control" name="place_vaccination" value="<?= htmlspecialchars(getValue($enrollment_data, 'place_vaccination')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Date of Vaccination</label>
                <input type="date" class="form-control" name="date_vaccination" value="<?= htmlspecialchars(getValue($enrollment_data, 'date_vaccination')) ?>">
            </div>
        </div>
        
        <div class="mb-3">
            <label class="form-label">Health Insurance</label><br>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" name="health_insurance[]" value="PhilHealth" id="philhealth" <?= isChecked($enrollment_data, 'health_insurance', 'PhilHealth') ? 'checked' : '' ?>>
                <label class="form-check-label" for="philhealth">PhilHealth</label>
            </div>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" name="health_insurance[]" value="Private" id="private_ins" <?= isChecked($enrollment_data, 'health_insurance', 'Private') ? 'checked' : '' ?>>
                <label class="form-check-label" for="private_ins">Private</label>
            </div>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" name="health_insurance[]" value="None" id="no_ins" <?= isChecked($enrollment_data, 'health_insurance', 'None') ? 'checked' : '' ?>>
                <label class="form-check-label" for="no_ins">None</label>
            </div>
        </div>
        
        <div class="mb-3">
            <label class="form-label">If Private, please specify:</label>
            <input type="text" class="form-control" name="private_specify_text" value="<?= htmlspecialchars(getValue($enrollment_data, 'private_insurance_details')) ?>">
        </div>
        
        <!-- E. RSS ASSIGNMENT -->
        <div class="section-title">E. RSS ASSIGNMENT</div>
        <div class="mb-3">
            <label class="form-label">RSS Assignment/Designation</label>
            <input type="text" class="form-control" name="rss_assignment" value="<?= htmlspecialchars(getValue($enrollment_data, 'rss_assignment')) ?>">
        </div>
        
        <div class="mb-3">
            <label class="form-label">Assigned Job Position</label>
            <input type="text" class="form-control" name="assigned_job_position" value="<?= htmlspecialchars(getValue($enrollment_data, 'assigned_job_position')) ?>">
        </div>
        
        <div class="mb-3">
            <label class="form-label">Inclusive Dates</label>
            <input type="text" class="form-control" name="inclusive_dates" placeholder="e.g., January 15 - March 15, 2025" value="<?= htmlspecialchars(getValue($enrollment_data, 'inclusive_dates')) ?>">
        </div>
        
        <div class="mb-3">
            <label class="form-label">RSS Site Address</label>
            <textarea class="form-control" name="rss_site_address" rows="2"><?= htmlspecialchars(getValue($enrollment_data, 'rss_site_address')) ?></textarea>
        </div>
        
        <!-- F. SIGNATURE -->
        <div class="section-title">F. STUDENT SIGNATURE</div>
        <div class="mb-3">
            <label class="form-label">Draw your signature below:</label>
            <canvas id="signaturePad" class="signature-pad" width="600" height="200"></canvas>
            <input type="hidden" name="signature_image" id="signatureData" value="<?= htmlspecialchars(getValue($enrollment_data, 'signature_image')) ?>">
            <div class="mt-2">
                <button type="button" class="btn btn-secondary btn-sm" id="clearSignature">Clear Signature</button>
                <?php if ($is_edit_mode && !empty($enrollment_data['signature_image'])): ?>
                    <button type="button" class="btn btn-info btn-sm" id="loadExistingSignature">Load Existing Signature</button>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- SUBMIT BUTTON -->
        <div class="text-center mt-4">
            <a href="../student/profset.php" class="btn btn-secondary me-2">Cancel</a>
            <button type="submit" class="btn btn-custom btn-lg">
                <?= $is_edit_mode ? '✅ Update Enrollment Form' : '✅ Submit Enrollment Form' ?>
            </button>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Signature Pad Implementation
const canvas = document.getElementById('signaturePad');
const ctx = canvas.getContext('2d');
const signatureData = document.getElementById('signatureData');
let isDrawing = false;
let lastX = 0;
let lastY = 0;

// Set canvas background to white
ctx.fillStyle = 'white';
ctx.fillRect(0, 0, canvas.width, canvas.height);

// Load existing signature if in edit mode
<?php if ($is_edit_mode && !empty($enrollment_data['signature_image'])): ?>
document.getElementById('loadExistingSignature')?.addEventListener('click', function() {
    const img = new Image();
    img.onload = function() {
        ctx.fillStyle = 'white';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(img, 0, 0);
    };
    img.src = '<?= htmlspecialchars($enrollment_data['signature_image']) ?>';
});

// Auto-load existing signature on page load
window.addEventListener('load', function() {
    const img = new Image();
    img.onload = function() {
        ctx.drawImage(img, 0, 0);
    };
    img.src = '<?= htmlspecialchars($enrollment_data['signature_image']) ?>';
});
<?php endif; ?>

canvas.addEventListener('mousedown', startDrawing);
canvas.addEventListener('mousemove', draw);
canvas.addEventListener('mouseup', stopDrawing);
canvas.addEventListener('mouseout', stopDrawing);

// Touch support for mobile
canvas.addEventListener('touchstart', function(e) {
    e.preventDefault();
    const touch = e.touches[0];
    const rect = canvas.getBoundingClientRect();
    lastX = touch.clientX - rect.left;
    lastY = touch.clientY - rect.top;
    isDrawing = true;
});

canvas.addEventListener('touchmove', function(e) {
    e.preventDefault();
    if (!isDrawing) return;
    const touch = e.touches[0];
    const rect = canvas.getBoundingClientRect();
    const x = touch.clientX - rect.left;
    const y = touch.clientY - rect.top;
    
    ctx.beginPath();
    ctx.moveTo(lastX, lastY);
    ctx.lineTo(x, y);
    ctx.strokeStyle = '#000';
    ctx.lineWidth = 2;
    ctx.stroke();
    
    lastX = x;
    lastY = y;
});

canvas.addEventListener('touchend', stopDrawing);

function startDrawing(e) {
    isDrawing = true;
    [lastX, lastY] = [e.offsetX, e.offsetY];
}

function draw(e) {
    if (!isDrawing) return;
    ctx.beginPath();
    ctx.moveTo(lastX, lastY);
    ctx.lineTo(e.offsetX, e.offsetY);
    ctx.strokeStyle = '#000';
    ctx.lineWidth = 2;
    ctx.stroke();
    [lastX, lastY] = [e.offsetX, e.offsetY];
}

function stopDrawing() {
    if (isDrawing) {
        signatureData.value = canvas.toDataURL();
        isDrawing = false;
    }
}

document.getElementById('clearSignature').addEventListener('click', () => {
    ctx.fillStyle = 'white';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    signatureData.value = '';
});

// Update signature data before submit
document.getElementById('enrollmentForm').addEventListener('submit', function() {
    if (canvas.toDataURL() !== document.getElementById('empty-canvas')?.toDataURL()) {
        signatureData.value = canvas.toDataURL();
    }
});
</script>
</body>
</html>
