<?php
//enrollment.php
session_start();
require "dbconnect.php";
require_once __DIR__ . '/enrollment_form_config.php';
require_once __DIR__ . '/../send_email.php';

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

if (!function_exists('rserves_send_enrollment_submission_notification')) {
    function rserves_send_enrollment_submission_notification(mysqli $conn, array $student, int $adviser_id, int $year_level, string $section, string $course): void
    {
        $student_name = trim(((string) ($student['firstname'] ?? '')) . ' ' . ((string) ($student['lastname'] ?? '')));
        $recipients = rserves_fetch_admin_email_recipients($conn);

        if ($adviser_id > 0) {
            $adviser = rserves_fetch_instructor_email_recipient($conn, $adviser_id);
            if ($adviser) {
                $recipients[] = $adviser;
            }
        }

        $body = rserves_notification_build_body(
            'there',
            "{$student_name} submitted an enrollment form for review.",
            [
                'Student ID' => (string) ($student['student_number'] ?? 'N/A'),
                'Course' => $course,
                'Year / Section' => trim($year_level . ' - ' . $section, ' -'),
            ]
        );

        rserves_send_bulk_notification_email($recipients, 'New Enrollment Submission', $body);
    }
}

$selected_college = normalizeEnrollmentCollege($_POST['college'] ?? '', $enrollment_college_aliases);
$selected_course = trim((string) ($_POST['course'] ?? ''));
$selected_major = trim((string) ($_POST['major'] ?? ''));
$selected_marital_status = trim((string) ($_POST['marital_status'] ?? ''));

$available_courses = ($selected_college !== '' && isset($enrollment_program_catalog[$selected_college]))
    ? array_keys($enrollment_program_catalog[$selected_college])
    : [];
if ($selected_course !== '' && !in_array($selected_course, $available_courses, true)) {
    $available_courses[] = $selected_course;
}

$available_majors = ($selected_college !== '' && $selected_course !== '' && isset($enrollment_program_catalog[$selected_college][$selected_course]))
    ? $enrollment_program_catalog[$selected_college][$selected_course]
    : [];
if ($selected_major !== '' && !in_array($selected_major, $available_majors, true)) {
    $available_majors[] = $selected_major;
}

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
    $college_values = normalizeEnrollmentCollege($_POST['college'] ?? '', $enrollment_college_aliases);
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

        rserves_send_enrollment_submission_notification($conn, $student, intval($adviser_id), intval($year_level), (string) $section, (string) $course);

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
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Sora:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --navy-950: #082746;
            --navy-900: #0d355f;
            --navy-800: #174d84;
            --navy-700: #28629d;
            --sky-500: #46b2ff;
            --ink-900: #0f1728;
            --ink-700: #47556c;
            --ink-500: #748298;
            --border-soft: rgba(15, 23, 40, 0.1);
            --surface: rgba(255, 255, 255, 0.96);
            --surface-muted: rgba(255, 255, 255, 0.88);
            --shadow-soft: 0 26px 70px rgba(10, 33, 60, 0.12);
            --shadow-button: 0 18px 34px rgba(13, 53, 95, 0.22);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'Manrope', sans-serif;
            color: var(--ink-900);
            background: radial-gradient(circle at top left, rgba(34, 96, 160, 0.1), transparent 28%),
                        linear-gradient(180deg, #edf2f8 0%, #f7f8fb 100%);
            min-height: 100vh;
            animation: pageFadeIn 0.45s ease forwards;
        }

        body::before {
            content: "";
            position: fixed;
            inset: 0;
            background: url('../img/bg.jpg') center center / cover no-repeat;
            opacity: 0.14;
            transform: scale(1.03);
            z-index: -2;
        }

        body::after {
            content: "";
            position: fixed;
            inset: 0;
            background: linear-gradient(180deg, rgba(237, 242, 248, 0.94) 0%, rgba(247, 248, 251, 0.98) 100%);
            z-index: -1;
        }

        .page-shell {
            width: min(1380px, 100%);
            margin: 0 auto;
            padding: 32px 24px 48px;
        }

        .form-hero {
            position: relative;
            overflow: hidden;
            border-radius: 30px;
            padding: 32px 36px;
            margin-bottom: 24px;
            color: #fff;
            background: linear-gradient(180deg, rgba(6, 35, 66, 0.34) 0%, rgba(6, 35, 66, 0.78) 100%),
                        linear-gradient(140deg, rgba(8, 39, 70, 0.94) 0%, rgba(16, 76, 133, 0.84) 100%),
                        url('../img/bg.jpg') center center / cover no-repeat;
            box-shadow: var(--shadow-soft);
        }

        .form-hero::before {
            content: "";
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at top right, rgba(120, 183, 255, 0.2), transparent 32%),
                        radial-gradient(circle at bottom left, rgba(120, 183, 255, 0.12), transparent 34%);
        }

        .form-hero > * {
            position: relative;
            z-index: 1;
        }

        .hero-brand {
            display: flex;
            align-items: center;
            gap: 0.95rem;
        }

        .hero-brand-logo {
            width: 62px;
            height: 62px;
            object-fit: contain;
            padding: 0.55rem;
            border-radius: 20px;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.16);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.12);
        }

        .hero-brand-copy strong {
            display: block;
            font-family: 'Sora', sans-serif;
            font-size: 1.65rem;
            font-weight: 700;
            letter-spacing: -0.05em;
        }

        .hero-brand-copy span {
            display: inline-flex;
            width: 62px;
            height: 4px;
            margin-top: 0.35rem;
            border-radius: 999px;
            background: linear-gradient(90deg, #2aa3ff 0%, #75c0ff 100%);
        }

        .hero-copy {
            max-width: 820px;
            margin-top: 2rem;
        }

        .hero-copy h1 {
            margin: 0 0 1rem;
            font-family: 'Sora', sans-serif;
            font-size: clamp(2.5rem, 4.8vw, 4.35rem);
            line-height: 0.94;
            letter-spacing: -0.08em;
            font-weight: 800;
            text-wrap: balance;
        }

        .hero-copy p {
            margin: 0;
            max-width: 640px;
            color: rgba(222, 235, 255, 0.82);
            font-size: 1.05rem;
            line-height: 1.8;
        }

        .hero-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.85rem;
            margin-top: 1.6rem;
        }

        .hero-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.7rem;
            padding: 0.88rem 1rem;
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.12);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.08);
            font-size: 0.95rem;
            font-weight: 700;
        }

        .hero-pill i {
            color: #8fd1ff;
        }

        .form-container {
            position: relative;
            background: var(--surface);
            border: 1px solid rgba(255, 255, 255, 0.72);
            border-radius: 30px;
            padding: 32px;
            box-shadow: var(--shadow-soft);
            backdrop-filter: blur(12px);
        }

        .form-container::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            border-radius: 30px 30px 0 0;
            background: linear-gradient(90deg, #1f7be0 0%, #46b2ff 48%, #7bcfff 100%);
        }

        .form-intro {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1.25rem;
            margin-bottom: 1.9rem;
        }

        .form-intro h2 {
            margin: 0 0 0.75rem;
            font-family: 'Sora', sans-serif;
            font-size: clamp(2rem, 3vw, 2.7rem);
            line-height: 1;
            letter-spacing: -0.06em;
            font-weight: 700;
            color: var(--navy-900);
        }

        .form-intro p {
            margin: 0;
            max-width: 640px;
            color: var(--ink-700);
            font-size: 1rem;
            line-height: 1.7;
        }

        .section-title {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin: 2rem 0 1.1rem;
            padding: 0.95rem 1.1rem;
            border-radius: 18px;
            background: linear-gradient(135deg, var(--navy-900) 0%, var(--navy-700) 100%);
            color: #fff;
            font-weight: 700;
            letter-spacing: 0.02em;
            box-shadow: 0 18px 40px rgba(13, 53, 95, 0.18);
        }

        .section-title i {
            width: 34px;
            height: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.12);
        }

        .form-label {
            margin-bottom: 0.55rem;
            color: var(--ink-900);
            font-size: 0.95rem;
            font-weight: 700;
            letter-spacing: 0.01em;
        }

        .form-control,
        .form-select {
            min-height: 56px;
            padding: 0.9rem 1rem;
            border-radius: 18px;
            border: 1px solid var(--border-soft);
            background: #fff;
            color: var(--ink-900);
            transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
        }

        textarea.form-control {
            min-height: auto;
            padding-top: 0.95rem;
            padding-bottom: 0.95rem;
        }

        .form-control::placeholder {
            color: #8d99ad;
        }

        .form-control:hover,
        .form-select:hover {
            transform: translateY(-1px);
        }

        .form-control:focus,
        .form-select:focus {
            outline: none;
            border-color: rgba(23, 77, 132, 0.45);
            box-shadow: 0 0 0 4px rgba(39, 99, 168, 0.12);
        }

        .form-select:disabled {
            background: #f5f7fb;
            color: var(--ink-500);
            cursor: not-allowed;
        }

        .field-hint {
            margin-top: 0.45rem;
            color: var(--ink-500);
            font-size: 0.83rem;
            line-height: 1.55;
        }

        .form-check-label {
            color: var(--ink-700);
            font-weight: 600;
        }

        .form-check-input {
            margin-top: 0.22rem;
        }

        .form-check-input:checked {
            background-color: var(--navy-700);
            border-color: var(--navy-700);
        }

        h6 {
            margin-top: 1.4rem;
            color: var(--navy-900);
            font-weight: 700;
        }

        .signature-panel {
            padding: 1rem;
            border-radius: 22px;
            background: #f7fbff;
            border: 1px solid rgba(23, 77, 132, 0.1);
        }

        .signature-pad {
            display: block;
            width: 100%;
            max-width: 100%;
            height: auto;
            border: 1px dashed rgba(23, 77, 132, 0.3);
            border-radius: 20px;
            background: #fff;
            cursor: crosshair;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.9);
        }

        .btn {
            border-radius: 18px;
            font-weight: 700;
        }

        .btn-custom {
            min-height: 58px;
            padding: 0.95rem 1.6rem;
            border: 0;
            background: linear-gradient(135deg, var(--navy-900) 0%, var(--navy-700) 100%);
            color: #fff;
            box-shadow: var(--shadow-button);
            transition: transform 0.2s ease, box-shadow 0.2s ease, filter 0.2s ease;
        }

        .btn-custom:hover {
            transform: translateY(-1px);
            box-shadow: 0 20px 36px rgba(13, 53, 95, 0.28);
            filter: brightness(1.02);
            color: #fff;
        }

        .btn-secondary {
            min-height: 58px;
            padding: 0.95rem 1.4rem;
            border: 1px solid rgba(23, 77, 132, 0.16);
            background: #f5f8fc;
            color: var(--navy-900);
            transition: transform 0.2s ease, border-color 0.2s ease, background-color 0.2s ease;
        }

        .btn-secondary:hover {
            transform: translateY(-1px);
            border-color: rgba(23, 77, 132, 0.28);
            background: #edf4fb;
            color: var(--navy-900);
        }

        .alert {
            border: 0;
            border-radius: 18px;
            padding: 1rem 1.1rem;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.85rem;
            flex-wrap: wrap;
            margin-top: 2rem;
        }

        @keyframes pageFadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        @media (max-width: 991.98px) {
            .page-shell {
                padding: 20px 16px 32px;
            }

            .form-hero,
            .form-container {
                border-radius: 24px;
            }

            .form-hero {
                padding: 28px 24px;
            }

            .form-container {
                padding: 24px 20px;
            }

            .form-intro {
                flex-direction: column;
            }
        }

        @media (max-width: 767.98px) {
            .hero-copy h1 {
                font-size: 2.7rem;
            }

            .hero-meta,
            .form-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .btn-custom,
            .btn-secondary {
                width: 100%;
            }
        }
    </style>
</head>
<body>
<div class="page-shell">
    <section class="form-hero">
        <div class="hero-brand">
            <img src="../img/logo3.png" alt="RServeS logo" class="hero-brand-logo">
            <div class="hero-brand-copy">
                <strong>RServeS</strong>
                <span></span>
            </div>
        </div>

        <div class="hero-copy">
            <h1>Complete your enrollment with the new student form experience.</h1>
            <p>Review your personal, scholastic, and health details in one place. College, course, and major choices now follow the updated academic offerings automatically.</p>
        </div>

        <div class="hero-meta">
            <span class="hero-pill"><i class="fas fa-school"></i>Lapu-Lapu City College</span>
            <span class="hero-pill"><i class="fas fa-map-marker-alt"></i>Don B. Benedicto Rd., Gun-ob, Lapu-Lapu City</span>
            <span class="hero-pill"><i class="fas fa-id-card"></i>School Code: 7174</span>
        </div>
    </section>

    <div class="form-container">
        <div class="form-intro">
            <div>
                <h2>Return Service System Enrollment Form</h2>
                <p>Use your current academic details and upload a recent photo. Course and specialization options will adjust based on the college you choose.</p>
            </div>
        </div>

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
        
        <div class="row mb-3">
            <div class="col-lg-4">
                <label class="form-label">College *</label>
                <select class="form-select" name="college" id="collegeSelect" required>
                    <option value="">Select college</option>
                    <?php foreach (array_keys($enrollment_program_catalog) as $college_option): ?>
                        <option value="<?= htmlspecialchars($college_option) ?>" <?= $selected_college === $college_option ? 'selected' : '' ?>>
                            <?= htmlspecialchars($college_option) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-4">
                <label class="form-label">Course *</label>
                <select class="form-select" name="course" id="courseSelect" data-selected="<?= htmlspecialchars($selected_course) ?>" required <?= empty($available_courses) ? 'disabled' : '' ?>>
                    <option value=""><?= $selected_college !== '' ? 'Select course' : 'Select college first' ?></option>
                    <?php foreach ($available_courses as $course_option): ?>
                        <option value="<?= htmlspecialchars($course_option) ?>" <?= $selected_course === $course_option ? 'selected' : '' ?>>
                            <?= htmlspecialchars($course_option) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-4">
                <label class="form-label">Major / Specialization</label>
                <select class="form-select" name="major" id="majorSelect" data-selected="<?= htmlspecialchars($selected_major) ?>" <?= empty($available_majors) ? 'disabled' : '' ?>>
                    <option value="">
                        <?php
                        if ($selected_college === '') {
                            echo 'Select college first';
                        } elseif ($selected_course === '') {
                            echo 'Select course first';
                        } elseif (empty($available_majors)) {
                            echo 'Not applicable for selected course';
                        } else {
                            echo 'Select major';
                        }
                        ?>
                    </option>
                    <?php foreach ($available_majors as $major_option): ?>
                        <option value="<?= htmlspecialchars($major_option) ?>" <?= $selected_major === $major_option ? 'selected' : '' ?>>
                            <?= htmlspecialchars($major_option) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="field-hint">Major is only needed for programs that offer a specialization.</div>
            </div>
        </div>

        <div class="row mb-3">
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
                <select class="form-select" name="marital_status" required>
                    <option value="">Select status</option>
                    <?php foreach ($enrollment_marital_status_options as $status_option): ?>
                        <option value="<?= htmlspecialchars($status_option) ?>" <?= $selected_marital_status === $status_option ? 'selected' : '' ?>>
                            <?= htmlspecialchars($status_option) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
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
        <div class="signature-panel text-center mb-3">
            <label class="form-label fw-bold">Student Signature Over Printed Name</label><br>
            <canvas id="signature-pad" class="signature-pad" width="500" height="150"></canvas><br>
            <button type="button" class="btn btn-secondary mt-3" id="clear-signature">
                <i class="fas fa-eraser me-1"></i>Clear Signature
            </button>
            <input type="hidden" name="signature_image" id="signature_image">
        </div>

        <div class="form-actions">
            <a href="pending_requirements.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Requirements
            </a>
            <button type="submit" class="btn btn-custom btn-lg px-5">
                <i class="fas fa-paper-plane me-2"></i>Submit Enrollment Form
            </button>
        </div>
    </form>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const programCatalog = <?= json_encode($enrollment_program_catalog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const collegeSelect = document.getElementById('collegeSelect');
const courseSelect = document.getElementById('courseSelect');
const majorSelect = document.getElementById('majorSelect');

function populateDependentSelect(select, options, placeholder, selectedValue = '') {
    const values = [...options];

    if (selectedValue && !values.includes(selectedValue)) {
        values.unshift(selectedValue);
    }

    select.innerHTML = '';

    const placeholderOption = document.createElement('option');
    placeholderOption.value = '';
    placeholderOption.textContent = placeholder;
    select.appendChild(placeholderOption);

    values.forEach((value) => {
        const option = document.createElement('option');
        option.value = value;
        option.textContent = value;
        if (value === selectedValue) {
            option.selected = true;
        }
        select.appendChild(option);
    });
}

function syncCourseOptions(selectedValue = '') {
    const selectedCollege = collegeSelect.value;
    const courseOptions = selectedCollege && programCatalog[selectedCollege]
        ? Object.keys(programCatalog[selectedCollege])
        : [];

    populateDependentSelect(
        courseSelect,
        courseOptions,
        selectedCollege ? 'Select course' : 'Select college first',
        selectedValue
    );

    courseSelect.disabled = courseOptions.length === 0;
}

function syncMajorOptions(selectedValue = '') {
    const selectedCollege = collegeSelect.value;
    const selectedCourse = courseSelect.value;
    const majorOptions = selectedCollege && selectedCourse && programCatalog[selectedCollege] && programCatalog[selectedCollege][selectedCourse]
        ? programCatalog[selectedCollege][selectedCourse]
        : [];

    let placeholder = 'Select college first';
    if (selectedCollege && !selectedCourse) {
        placeholder = 'Select course first';
    } else if (selectedCollege && selectedCourse && majorOptions.length === 0) {
        placeholder = 'Not applicable for selected course';
    } else if (majorOptions.length > 0) {
        placeholder = 'Select major';
    }

    populateDependentSelect(majorSelect, majorOptions, placeholder, selectedValue);
    majorSelect.disabled = majorOptions.length === 0 && selectedValue === '';
}

if (collegeSelect && courseSelect && majorSelect) {
    const initialCourse = courseSelect.dataset.selected || '';
    const initialMajor = majorSelect.dataset.selected || '';

    syncCourseOptions(initialCourse);
    syncMajorOptions(initialMajor);

    collegeSelect.addEventListener('change', function() {
        syncCourseOptions('');
        syncMajorOptions('');
    });

    courseSelect.addEventListener('change', function() {
        syncMajorOptions('');
    });
}

// Signature Pad
const canvas = document.getElementById('signature-pad');
const ctx = canvas.getContext('2d');
const signatureField = document.getElementById('signature_image');
const baseCanvasWidth = parseInt(canvas.getAttribute('width'), 10) || 500;
const baseCanvasHeight = parseInt(canvas.getAttribute('height'), 10) || 150;
let drawing = false;
let lastX = 0;
let lastY = 0;
let displayWidth = baseCanvasWidth;
let displayHeight = baseCanvasHeight;
let hasSignature = false;

canvas.style.touchAction = 'none';
canvas.style.aspectRatio = `${baseCanvasWidth} / ${baseCanvasHeight}`;

function applySignatureStyles() {
    ctx.strokeStyle = '#000';
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
}

function fillSignatureBackground() {
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, displayWidth, displayHeight);
}

function updateSignatureField() {
    signatureField.value = hasSignature ? canvas.toDataURL('image/png') : '';
}

function resizeSignatureCanvas(preserveDrawing = true) {
    const previousImage = preserveDrawing && hasSignature ? canvas.toDataURL('image/png') : '';
    const rect = canvas.getBoundingClientRect();
    const nextWidth = Math.max(Math.round(rect.width || baseCanvasWidth), 1);
    const nextHeight = Math.max(Math.round((nextWidth * baseCanvasHeight) / baseCanvasWidth), 1);
    const ratio = Math.max(window.devicePixelRatio || 1, 1);

    displayWidth = nextWidth;
    displayHeight = nextHeight;

    canvas.width = Math.round(displayWidth * ratio);
    canvas.height = Math.round(displayHeight * ratio);
    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);

    applySignatureStyles();
    fillSignatureBackground();

    if (previousImage) {
        const img = new Image();
        img.onload = function() {
            fillSignatureBackground();
            ctx.drawImage(img, 0, 0, displayWidth, displayHeight);
            applySignatureStyles();
            updateSignatureField();
        };
        img.src = previousImage;
    } else {
        updateSignatureField();
    }
}

function getCanvasPoint(event) {
    const rect = canvas.getBoundingClientRect();
    return {
        x: event.clientX - rect.left,
        y: event.clientY - rect.top,
    };
}

function startDrawing(event) {
    if (event.pointerType === 'mouse' && event.button !== 0) {
        return;
    }

    const point = getCanvasPoint(event);
    drawing = true;
    lastX = point.x;
    lastY = point.y;
    canvas.setPointerCapture?.(event.pointerId);
    event.preventDefault();
}

function draw(event) {
    if (!drawing) {
        return;
    }

    const point = getCanvasPoint(event);
    ctx.beginPath();
    ctx.moveTo(lastX, lastY);
    ctx.lineTo(point.x, point.y);
    ctx.stroke();

    lastX = point.x;
    lastY = point.y;
    hasSignature = true;
    updateSignatureField();
    event.preventDefault();
}

function stopDrawing(event) {
    if (!drawing) {
        return;
    }

    drawing = false;
    if (event && canvas.hasPointerCapture?.(event.pointerId)) {
        canvas.releasePointerCapture(event.pointerId);
    }
    updateSignatureField();
}

resizeSignatureCanvas(false);

canvas.addEventListener('pointerdown', startDrawing);
canvas.addEventListener('pointermove', draw);
canvas.addEventListener('pointerup', stopDrawing);
canvas.addEventListener('pointercancel', stopDrawing);
canvas.addEventListener('pointerleave', stopDrawing);
window.addEventListener('resize', () => resizeSignatureCanvas(true));

document.getElementById('clear-signature').addEventListener('click', () => {
    hasSignature = false;
    resizeSignatureCanvas(false);
});

document.getElementById('enrollmentForm').addEventListener('submit', function() {
    updateSignatureField();
});
</script>
</body>
</html>
