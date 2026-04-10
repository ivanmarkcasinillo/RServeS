<?php
session_start();
require "../dbconnect.php";

// Check if student is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Student') {
    header("Location: ../../home2.php");
    exit;
}

$student_id = $_SESSION['stud_id'] ?? null;
if (!$student_id) {
    die("Student ID not found in session.");
}

// Check if enrollment form is completed
$check_enrollment = $conn->prepare("SELECT enrollment_id FROM rss_enrollments WHERE student_id = ?");
$check_enrollment->bind_param("i", $student_id);
$check_enrollment->execute();
$enrollment = $check_enrollment->get_result()->fetch_assoc();
$check_enrollment->close();

if (!$enrollment) {
    $_SESSION['flash_error'] = "Please complete the enrollment form first.";
    header("Location: ../enrolment.php");
    exit;
}

$enrollment_id = $enrollment['enrollment_id'];

// Check if already submitted agreement
$check_agreement = $conn->prepare("SELECT agreement_id FROM rss_agreements WHERE student_id = ?");
$check_agreement->bind_param("i", $student_id);
$check_agreement->execute();
$existing_agreement = $check_agreement->get_result()->fetch_assoc();
$check_agreement->close();

if ($existing_agreement) {
    $_SESSION['flash'] = "You have already submitted an agreement form.";
    header("Location: ../pending_requirements.php");
    exit;
}

if ($existing_agreement) {
    $_SESSION['flash'] = "You have already submitted an agreement form.";

if ($existing_agreement) {
    // Student already submitted agreement → go back to dashboard
    $departments = [
        1 => "../student_college_of_education_dashboard.php",
        2 => "../student_college_of_technology_dashboard.php",
        3 => "../student_college_of_hospitality_and_tourism_management_dashboard.php"
    ];

    $redirect = $departments[$_SESSION['department_id']]
        ?? "../student_dashboard.php";

    header("Location: $redirect");
    exit;
}

}

// Get student info
$stmt = $conn->prepare("SELECT firstname, lastname, email FROM students WHERE stud_id = ?");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 🔒 Safety check: prevent duplicate agreement
    if ($existing_agreement) {
        $_SESSION['flash_error'] = "Agreement already submitted.";
        header("Location: ../pending_requirements.php");
        exit;

        // Go back to student dashboard
        $departments = [
            1 => "../student_college_of_education_dashboard.php",
            2 => "../student_college_of_technology_dashboard.php",
            3 => "../student_college_of_hospitality_and_tourism_management_dashboard.php"
        ];

        $redirect = $departments[$_SESSION['department_id']]
            ?? "../student_dashboard.php";

        header("Location: $redirect");
        exit;
    }

    // Assign ALL POST values to variables (required for bind_param)
    $semester_term = $_POST['semester_term'];
    $acad_year_start = $_POST['acad_year_start'];
    $acad_year_end = $_POST['acad_year_end'];
    $agreement_day = $_POST['day'];
    $agreement_month = $_POST['month'];
    $agreement_place = $_POST['place'];
    $first_name = $_POST['first_name'];
    $middle_name = $_POST['middle_name'];
    $family_name = $_POST['family_name'];
    $civil_status = $_POST['civil_status'] ?? 'Single';
    $name_of_spouse = $_POST['name_of_spouse'];
    $student_address = $_POST['student_address'];
    $parent_guardian_name = $_POST['parent_guardian_name'];
    $legal_spouse_name = $_POST['legal_spouse_name'];
    $permanent_address = $_POST['permanent_address'];
    $college_name = $_POST['college_name'];
    $day_signed = $_POST['day_signed'];
    $month_signed = $_POST['month_signed'];
    $year_signed = $_POST['year_signed'];
    $student_signature = $_POST['student_signature'];
    $parent_signature = $_POST['parent_signature'];

    // SQL Insert
    $sql = "INSERT INTO rss_agreements (
        student_id, enrollment_id, semester_term, acad_year_start, acad_year_end,
        agreement_day, agreement_month, agreement_place,
        first_name, middle_name, family_name, civil_status, name_of_spouse,
        student_address, parent_guardian_name, legal_spouse_name, permanent_address,
        college_name, day_signed, month_signed, year_signed,
        student_signature, parent_signature
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);

    // BIND — 2 integers + 21 strings = 23 total
    $stmt->bind_param(
        "iisssssssssssssssssssss",
        $student_id,
        $enrollment_id,
        $semester_term,
        $acad_year_start,
        $acad_year_end,
        $agreement_day,
        $agreement_month,
        $agreement_place,
        $first_name,
        $middle_name,
        $family_name,
        $civil_status,
        $name_of_spouse,
        $student_address,
        $parent_guardian_name,
        $legal_spouse_name,
        $permanent_address,
        $college_name,
        $day_signed,
        $month_signed,
        $year_signed,
        $student_signature,
        $parent_signature
    );

    if ($stmt->execute()) {
        // Update progress
        $prog = $conn->prepare("UPDATE rss_progress 
            SET agreement_completed = TRUE, agreement_date = NOW(), completion_percentage = 50, overall_status = 'In Progress' 
            WHERE student_id = ?");
        $prog->bind_param("i", $student_id);
        $prog->execute();
        $prog->close();

        $_SESSION['flash'] = "Agreement form submitted successfully!";
        header("Location: ../pending_requirements.php");
        exit;

        // Redirect based on department
      function redirectStudentDashboard($deptId) {
    $map = [
        1 => "../student_college_of_education_dashboard.php",
        2 => "../student_college_of_technology_dashboard.php",
        3 => "../student_college_of_hospitality_and_tourism_management_dashboard.php"
    ];
    header("Location: " . ($map[$deptId] ?? "../student_dashboard.php"));
    exit;
}


    } else {
        $error = "Error: " . $stmt->error;
    }

    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RSS Agreement Form</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 20px; }
        .form-container { background: white; border-radius: 15px; padding: 40px; max-width: 1000px; margin: 0 auto; box-shadow: 0 10px 40px rgba(0,0,0,0.2); }
        .section-title { background: #667eea; color: white; padding: 12px 20px; border-radius: 8px; margin: 25px 0 15px 0; font-weight: bold; font-size: 1.1rem; }
        .article-title { color: #667eea; font-weight: bold; margin-top: 20px; }
        .legal-text { text-align: justify; line-height: 1.8; margin-bottom: 15px; }
        .input-inline { border: none; border-bottom: 2px solid #667eea; background: transparent; display: inline-block; width: auto; min-width: 150px; padding: 2px 8px; }
        .input-small { min-width: 80px; }
        .signature-area { border: 2px solid #667eea; border-radius: 8px; padding: 20px; margin: 15px 0; }
        .btn-custom { background: linear-gradient(135deg, #667eea, #764ba2); color: white; border: none; }
        .btn-custom:hover { opacity: 0.9; color: white; }
    </style>
</head>
<body>
<div class="form-container">
    <div class="text-center mb-4">
        <h4>Lapu-Lapu City College</h4>
        <p class="mb-0">Don B. Benedicto Rd., Gun-ob, Lapu-Lapu City, 6015</p>
        <p><em>School Code: 7174</em></p>
    </div>
    
    <h2 class="text-center mb-4" style="color: #667eea;">RETURN SERVICE AGREEMENT</h2>
    
    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?= $error ?></div>
    <?php endif; ?>
    
    <form method="POST" id="agreementForm">
        
        <div class="legal-text">
            (For <input type="text" name="semester_term" class="input-inline input-small" placeholder="1st/2nd" required> 
            semester/term of academic year 
            <input type="text" name="acad_year_start" class="input-inline input-small" placeholder="2024" required> - 
            <input type="text" name="acad_year_end" class="input-inline input-small" placeholder="2025" required>)
        </div>
        
        <div class="legal-text">
            This Return Service Agreement (RSA) is made and executed this 
            <input type="text" name="day" class="input-inline input-small" placeholder="day" required> day of 
            <input type="text" name="month" class="input-inline" placeholder="month" required> in 
            <input type="text" name="place" class="input-inline" placeholder="place" required>, Philippines by and between:
        </div>
        
        <div class="legal-text">
            <input type="text" name="first_name" class="input-inline" placeholder="FIRST NAME" value="<?= htmlspecialchars($student['firstname']) ?>" required>, 
            <input type="text" name="middle_name" class="input-inline" placeholder="MIDDLE NAME" required>, 
            <input type="text" name="family_name" class="input-inline" placeholder="FAMILY NAME" value="<?= htmlspecialchars($student['lastname']) ?>" required>, 
            Filipino, of legal age, 
            <select name="civil_status" class="input-inline" style="border: none; border-bottom: 2px solid #667eea;" required>
                <option value="Single">Single</option>
                <option value="Married">Married</option>
                <option value="Widowed">Widowed</option>
            </select>
        </div>
        
        <div class="legal-text">
            Married to <input type="text" name="name_of_spouse" class="input-inline" placeholder="Spouse name (if applicable)">, 
            with residence and postal address at 
            <input type="text" name="student_address" class="input-inline w-50" placeholder="Complete address" required>, 
            herein referred to as <strong>STUDENT;</strong>
        </div>
        
        <div class="legal-text">
            Assisted by: <input type="text" name="parent_guardian_name" class="input-inline w-50" placeholder="Parent/Guardian name (if student is a minor)">
        </div>
        
        <div class="legal-text">
            With the consent and knowledge of: <input type="text" name="legal_spouse_name" class="input-inline w-50" placeholder="Legal spouse name (if student is married)">
        </div>
        
        <div class="legal-text">
            Filipino, legal age, single/married/widow(er) and with residence and postal address at 
            <input type="text" name="permanent_address" class="input-inline w-50" placeholder="Permanent address" required>, 
            hereinafter referred to as the <strong>Parent/Legal Guardian/Legal Spouse;</strong>
        </div>
        
        <div class="legal-text">
            <strong>-and-</strong>
        </div>
        
        <div class="legal-text">
            <strong>LAPU-LAPU CITY COLLEGE</strong>, a public educational institution of higher learning established and existing under the laws of the Republic of the Philippines, having office at Don B. Benedicto, Canjagi, Gun-ob, Lapu-Lapu City, Cebu, Philippines, represented herein by its College Administrator, <strong>DR. MARIA NOELEEN M. BORBAJO</strong>, hereinafter referred to as "LLCC"
        </div>
        
        <div class="section-title">WITNESSETH, That</div>
        
        <div class="legal-text">
            <strong>WHEREAS</strong>, Republic Act No. 10931 also known as the "Universal Access to Quality Tertiary Education Act" provides that Filipino students shall be granted free higher education in SUCs, LUCs, and other government-run tertiary schools as defined by this Act. It further states that grantees of free higher education shall be required to render return service equivalent to the subsidy received from the government in the form of service or in cash/kind to LLCC.
        </div>
        
        <div class="legal-text">
            <strong>WHEREAS</strong>, in compliance with the said law, LLCC has established a Return Service Policy (RSP) to formalize the terms and conditions of the return service requirement;
        </div>
        
        <div class="legal-text">
            <strong>NOW THEREFORE</strong>, for and in consideration of the foregoing premises, the parties hereby agree as follows:
        </div>
        
        <div class="article-title">Article 1: Obligation of the Student</div>
        <div class="legal-text">
            The student, having been accepted to the Lapu-Lapu City College of 
            <input type="text" name="college_name" class="input-inline" placeholder="College name" required> 
            and covered by the RETURN SERVICE POLICY (RSP), shall:
        </div>
        
        <div class="legal-text ps-4">
            1. Abide by the Vision, Mission, Goals and Objectives of LLCC and the Program objectives and outcomes of the College;
        </div>
        <div class="legal-text ps-4">
            2. Abide by the prescribed course of instruction unless sooner separated or dismissed for causes under the laws and regulations of LLCC;
        </div>
        <div class="legal-text ps-4">
            3. Comply with the return service policy of the college under this agreement which requires the student to render service to LLCC equivalent to the free tuition and other fees received from the government, computed at the rate of ONE (1) HOUR of service for every ONE HUNDRED PESOS (Php 100.00) of subsidy received;
        </div>
        <div class="legal-text ps-4">
            4. Complete the required return service hours within the period prescribed by LLCC;
        </div>
        <div class="legal-text ps-4">
            5. Perform tasks assigned by LLCC with diligence, competence, and good faith during the return service period.
        </div>
        
        <div class="article-title">Article 2: Penalty for Breach of Obligation</div>
        <div class="legal-text">
            1. The Student acknowledges and agrees that failure to comply with the return service requirement shall result in:
        </div>
        <div class="legal-text ps-4">
            a) Withholding of academic credentials including diploma, transcripts of records, and other documents;
        </div>
        <div class="legal-text ps-4">
            b) Payment of the full amount of tuition and other fees subsidized by the government;
        </div>
        <div class="legal-text ps-4">
            c) Such other penalties as may be imposed by LLCC in accordance with its policies.
        </div>
        
        <div class="article-title">Article 3: Free and Hold Harmless Clause</div>
        <div class="legal-text">
            Any loss and/or damage incurred by or caused by the student during the return service period shall be the sole responsibility of the student and/or their parent/legal guardian/legal spouse. The student hereby agrees to free and hold harmless LLCC from any and all claims, demands, liabilities, and expenses arising from the student's acts or omissions during the return service period.
        </div>
        
        <div class="section-title">IN WITNESS WHEREOF</div>
        <div class="legal-text">
            The Parties hereto hereby sign this Return Service Agreement together with the parent(s)/legal guardian/legal spouse of the student, this 
            <input type="text" name="day_signed" class="input-inline input-small" placeholder="day" required> day of 
            <input type="text" name="month_signed" class="input-inline" placeholder="month" required> 
            <input type="text" name="year_signed" class="input-inline input-small" placeholder="2024" required> 
            at Lapu-Lapu City, Cebu, Philippines.
        </div>
        
        <div class="signature-area mt-4">
            <div class="row">
                <div class="col-md-6 mb-4">
                    <label class="fw-bold">Student Signature</label>
                    <input type="text" name="student_signature" class="form-control" placeholder="Type your full name" required>
                    <small class="text-muted">By typing your name, you acknowledge that this serves as your electronic signature</small>
                </div>
                <div class="col-md-6 mb-4">
                    <label class="fw-bold">Date</label>
                    <input type="date" class="form-control" value="<?= date('Y-m-d') ?>" readonly>
                </div>
            </div>
        </div>
        
        <div class="text-center mb-3">
            <p class="fw-bold mb-0">DR. ROBERT B. PABILLARAN</p>
            <p class="text-muted">Vice President for Academics/Dean, COT</p>
        </div>
        
        <div class="signature-area">
            <p class="fw-bold">Conforme:</p>
            <div class="row">
                <div class="col-md-6 mb-4">
                    <label class="fw-bold">Parent/Legal Guardian/Legal Spouse Signature</label>
                    <input type="text" name="parent_signature" class="form-control" placeholder="Type full name" required>
                    <small class="text-muted">By typing the name, parent/guardian acknowledges consent to this agreement</small>
                </div>
                <div class="col-md-6 mb-4">
                    <label class="fw-bold">Date</label>
                    <input type="date" class="form-control" value="<?= date('Y-m-d') ?>" readonly>
                </div>
            </div>
        </div>
        
        <div class="text-center mb-4">
            <p class="fw-bold mb-0">DR. MARIA NOELEEN M. BORBAJO</p>
            <p class="text-muted">College Administrator</p>
        </div>
        
        <div class="text-center mt-4">
            <button type="submit" class="btn btn-custom btn-lg px-5">Submit Agreement & Proceed to Dashboard</button>
        </div>
        
        <div class="text-center mt-3">
            <p class="text-muted">Optional: <a href="3-RSS-Parents-Consent (2).docx" download>Download Waiver Document</a></p>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
