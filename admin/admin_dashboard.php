<?php
session_start();
include "dbconnect.php";
require_once __DIR__ . '/../send_email.php';
require_once __DIR__ . '/../student/enrollment_form_config.php';

// Only allow Administrator
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Administrator') {
    header("Location: ../home2.php");
    exit;
}

if (!function_exists('rserves_admin_json_response')) {
    function rserves_admin_json_response(array $payload, int $statusCode = 200): void
    {
        if (ob_get_length()) {
            ob_clean();
        }

        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($payload);
        exit;
    }
}

if (!function_exists('rserves_admin_bind_params')) {
    function rserves_admin_bind_params(mysqli_stmt $stmt, string $types, array $values): bool
    {
        if ($types === '') {
            return true;
        }

        $bindValues = [$types];
        foreach ($values as $index => $value) {
            $bindValues[] = &$values[$index];
        }

        return call_user_func_array([$stmt, 'bind_param'], $bindValues);
    }
}

if (!function_exists('rserves_admin_generate_temp_password')) {
    function rserves_admin_generate_temp_password(int $length = 10): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $password = '';
        $maxIndex = strlen($alphabet) - 1;

        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, $maxIndex)];
        }

        return $password;
    }
}

if (!function_exists('rserves_admin_resolve_asset_url')) {
    function rserves_admin_resolve_asset_url(?string $relativePath): ?string
    {
        $path = trim((string) $relativePath);
        if ($path === '') {
            return null;
        }

        if (preg_match('/^data:image\//i', $path) === 1) {
            return $path;
        }

        $normalized = ltrim(str_replace('\\', '/', $path), '/');
        $rootDir = dirname(__DIR__);
        $candidates = [
            [
                'server' => $rootDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized),
                'url' => '../' . $normalized,
            ],
            [
                'server' => $rootDir . DIRECTORY_SEPARATOR . 'student' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized),
                'url' => '../student/' . $normalized,
            ],
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate['server'])) {
                return $candidate['url'];
            }
        }

        return '../' . $normalized;
    }
}

if (!function_exists('rserves_admin_resolve_profile_photo_url')) {
    function rserves_admin_resolve_profile_photo_url(?string $photoPath): ?string
    {
        $path = trim((string) $photoPath);
        if ($path === '') {
            return null;
        }

        $normalized = str_replace('\\', '/', $path);
        $candidates = [];

        if (strpos($normalized, 'uploads/') === 0) {
            $candidates[] = $normalized;
        }

        $candidates[] = 'uploads/profile_photos/' . ltrim(basename($normalized), '/');
        $rootDir = dirname(__DIR__);

        foreach ($candidates as $candidate) {
            $resolvedPath = ltrim(str_replace('\\', '/', $candidate), '/');
            $serverCandidates = [
                $rootDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $resolvedPath),
                $rootDir . DIRECTORY_SEPARATOR . 'student' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $resolvedPath),
            ];

            foreach ($serverCandidates as $serverPath) {
                if (is_file($serverPath)) {
                    return rserves_admin_resolve_asset_url($candidate);
                }
            }
        }

        return rserves_admin_resolve_asset_url($candidates[count($candidates) - 1] ?? $normalized);
    }
}

if (!function_exists('rserves_admin_department_id_from_college')) {
    function rserves_admin_department_id_from_college(string $collegeName): ?int
    {
        $normalized = trim($collegeName);
        if ($normalized === '') {
            return null;
        }

        $map = [
            'College of Education' => 1,
            'College of Technology' => 2,
            'College of Hospitality and Tourism Management' => 3,
        ];

        return $map[$normalized] ?? null;
    }
}

$deptNames = [
    1 => "College of Education",
    2 => "College of Technology",
    3 => "College of Hospitality and Tourism Management"
];

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

$conn->query("CREATE TABLE IF NOT EXISTS student_login_history (
    login_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    login_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    INDEX idx_student_login_history_student (student_id),
    INDEX idx_student_login_history_login_at (login_at)
)");

$conn->query("CREATE TABLE IF NOT EXISTS student_announcements (
    announcement_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    instructor_id INT NOT NULL,
    subject VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    sender_role VARCHAR(20) NOT NULL DEFAULT 'Instructor',
    admin_id INT NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_student_announcements_student (student_id),
    INDEX idx_student_announcements_instructor (instructor_id),
    INDEX idx_student_announcements_admin (admin_id)
)");

$studentAnnouncementsSenderRole = $conn->query("SHOW COLUMNS FROM student_announcements LIKE 'sender_role'");
if ($studentAnnouncementsSenderRole && $studentAnnouncementsSenderRole->num_rows === 0) {
    $conn->query("ALTER TABLE student_announcements ADD COLUMN sender_role VARCHAR(20) NOT NULL DEFAULT 'Instructor' AFTER message");
}

$studentAnnouncementsAdminId = $conn->query("SHOW COLUMNS FROM student_announcements LIKE 'admin_id'");
if ($studentAnnouncementsAdminId && $studentAnnouncementsAdminId->num_rows === 0) {
    $conn->query("ALTER TABLE student_announcements ADD COLUMN admin_id INT NULL DEFAULT NULL AFTER sender_role");
}

$adminEnrollmentEditableFields = [
    'surname' => 's',
    'given_name' => 's',
    'middle_name' => 's',
    'student_number' => 's',
    'college' => 's',
    'course' => 's',
    'major' => 's',
    'year_level' => 'i',
    'section' => 's',
    'city_address' => 's',
    'gender' => 's',
    'contact_number' => 's',
    'email_address' => 's',
    'birth_date' => 's',
    'birth_place' => 's',
    'provincial_address' => 's',
    'religion' => 's',
    'marital_status' => 's',
    'father_name' => 's',
    'father_occupation' => 's',
    'father_company' => 's',
    'father_company_address' => 's',
    'father_contact' => 's',
    'mother_name' => 's',
    'mother_occupation' => 's',
    'mother_company' => 's',
    'mother_company_address' => 's',
    'mother_contact' => 's',
    'guardian_name' => 's',
    'guardian_address' => 's',
    'guardian_contact' => 's',
    'tertiary_school' => 's',
    'tertiary_address' => 's',
    'tertiary_year_grad' => 's',
    'tertiary_honors' => 's',
    'secondary_school' => 's',
    'secondary_address' => 's',
    'secondary_year_grad' => 's',
    'secondary_honors' => 's',
    'primary_school' => 's',
    'primary_address' => 's',
    'primary_year_grad' => 's',
    'primary_honors' => 's',
    'height' => 's',
    'weight' => 's',
    'blood_type' => 's',
    'health_problem' => 's',
    'vaccination_status' => 's',
    'vaccine_type' => 's',
    'place_vaccination' => 's',
    'date_vaccination' => 's',
    'health_insurance' => 's',
    'private_insurance_details' => 's',
    'rss_assignment' => 's',
    'assigned_job_position' => 's',
    'inclusive_dates' => 's',
    'rss_site_address' => 's',
];

$adminEnrollmentFieldGroups = [
    [
        'title' => 'Personal and Scholastic Information',
        'fields' => [
            ['name' => 'surname', 'label' => 'Surname'],
            ['name' => 'given_name', 'label' => 'Given Name'],
            ['name' => 'middle_name', 'label' => 'Middle Name'],
            ['name' => 'student_number', 'label' => 'Student Number'],
            ['name' => 'college', 'label' => 'College', 'type' => 'select', 'options' => array_keys($enrollment_program_catalog)],
            ['name' => 'course', 'label' => 'Course'],
            ['name' => 'major', 'label' => 'Major'],
            ['name' => 'year_level', 'label' => 'Year Level', 'type' => 'select', 'options' => [
                ['value' => '1', 'label' => '1st Year'],
                ['value' => '2', 'label' => '2nd Year'],
                ['value' => '3', 'label' => '3rd Year'],
                ['value' => '4', 'label' => '4th Year'],
            ]],
            ['name' => 'section', 'label' => 'Section', 'type' => 'select', 'options' => [
                ['value' => 'A', 'label' => 'A'],
                ['value' => 'B', 'label' => 'B'],
                ['value' => 'C', 'label' => 'C'],
                ['value' => 'D', 'label' => 'D'],
                ['value' => 'AE', 'label' => 'AE'],
            ]],
            ['name' => 'city_address', 'label' => 'City Address', 'type' => 'textarea'],
            ['name' => 'gender', 'label' => 'Gender'],
            ['name' => 'contact_number', 'label' => 'Contact Number'],
            ['name' => 'email_address', 'label' => 'Email Address', 'type' => 'email'],
            ['name' => 'birth_date', 'label' => 'Birth Date', 'type' => 'date'],
            ['name' => 'birth_place', 'label' => 'Birth Place'],
            ['name' => 'provincial_address', 'label' => 'Provincial Address', 'type' => 'textarea'],
            ['name' => 'religion', 'label' => 'Religion'],
            ['name' => 'marital_status', 'label' => 'Marital Status', 'type' => 'select', 'options' => $enrollment_marital_status_options],
        ],
    ],
    [
        'title' => 'Family Background',
        'fields' => [
            ['name' => 'father_name', 'label' => 'Father Name'],
            ['name' => 'father_occupation', 'label' => 'Father Occupation'],
            ['name' => 'father_company', 'label' => 'Father Company'],
            ['name' => 'father_company_address', 'label' => 'Father Company Address', 'type' => 'textarea'],
            ['name' => 'father_contact', 'label' => 'Father Contact'],
            ['name' => 'mother_name', 'label' => 'Mother Name'],
            ['name' => 'mother_occupation', 'label' => 'Mother Occupation'],
            ['name' => 'mother_company', 'label' => 'Mother Company'],
            ['name' => 'mother_company_address', 'label' => 'Mother Company Address', 'type' => 'textarea'],
            ['name' => 'mother_contact', 'label' => 'Mother Contact'],
            ['name' => 'guardian_name', 'label' => 'Guardian Name'],
            ['name' => 'guardian_address', 'label' => 'Guardian Address', 'type' => 'textarea'],
            ['name' => 'guardian_contact', 'label' => 'Guardian Contact'],
        ],
    ],
    [
        'title' => 'Educational Background',
        'fields' => [
            ['name' => 'tertiary_school', 'label' => 'Tertiary School'],
            ['name' => 'tertiary_address', 'label' => 'Tertiary Address', 'type' => 'textarea'],
            ['name' => 'tertiary_year_grad', 'label' => 'Tertiary Year Graduated'],
            ['name' => 'tertiary_honors', 'label' => 'Tertiary Honors'],
            ['name' => 'secondary_school', 'label' => 'Secondary School'],
            ['name' => 'secondary_address', 'label' => 'Secondary Address', 'type' => 'textarea'],
            ['name' => 'secondary_year_grad', 'label' => 'Secondary Year Graduated'],
            ['name' => 'secondary_honors', 'label' => 'Secondary Honors'],
            ['name' => 'primary_school', 'label' => 'Primary School'],
            ['name' => 'primary_address', 'label' => 'Primary Address', 'type' => 'textarea'],
            ['name' => 'primary_year_grad', 'label' => 'Primary Year Graduated'],
            ['name' => 'primary_honors', 'label' => 'Primary Honors'],
        ],
    ],
    [
        'title' => 'Health Data',
        'fields' => [
            ['name' => 'height', 'label' => 'Height'],
            ['name' => 'weight', 'label' => 'Weight'],
            ['name' => 'blood_type', 'label' => 'Blood Type'],
            ['name' => 'health_problem', 'label' => 'Health Problem'],
            ['name' => 'vaccination_status', 'label' => 'Vaccination Status'],
            ['name' => 'vaccine_type', 'label' => 'Type of Vaccine'],
            ['name' => 'place_vaccination', 'label' => 'Place of Vaccination'],
            ['name' => 'date_vaccination', 'label' => 'Date of Vaccination', 'type' => 'date'],
            ['name' => 'health_insurance', 'label' => 'Health Insurance'],
            ['name' => 'private_insurance_details', 'label' => 'Private Insurance Details'],
        ],
    ],
    [
        'title' => 'RSS Assignment',
        'fields' => [
            ['name' => 'rss_assignment', 'label' => 'RSS Assignment'],
            ['name' => 'assigned_job_position', 'label' => 'Assigned Job Position'],
            ['name' => 'inclusive_dates', 'label' => 'Inclusive Dates'],
            ['name' => 'rss_site_address', 'label' => 'RSS Site Address', 'type' => 'textarea'],
        ],
    ],
];

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'get_student_details')) {
    $studentId = intval($_POST['student_id'] ?? 0);
    if ($studentId <= 0) {
        rserves_admin_json_response([
            'success' => false,
            'message' => 'A valid student record is required.',
        ], 422);
    }

    $studentStmt = $conn->prepare("
        SELECT
            s.stud_id,
            s.firstname,
            s.lastname,
            s.mi,
            s.email,
            s.student_number,
            COALESCE(s.year_level, 1) AS year_level,
            COALESCE(s.section, 'A') AS section,
            s.department_id,
            s.photo,
            d.department_name,
            COALESCE((SELECT SUM(hours) FROM accomplishment_reports WHERE student_id = s.stud_id AND status = 'Approved'), 0) AS completed_hours,
            (SELECT status FROM rss_waivers WHERE student_id = s.stud_id ORDER BY id DESC LIMIT 1) AS waiver_status,
            (SELECT status FROM rss_agreements WHERE student_id = s.stud_id ORDER BY agreement_id DESC LIMIT 1) AS agreement_status,
            (SELECT status FROM rss_enrollments WHERE student_id = s.stud_id ORDER BY enrollment_id DESC LIMIT 1) AS enrollment_status,
            (SELECT enrollment_id FROM rss_enrollments WHERE student_id = s.stud_id ORDER BY enrollment_id DESC LIMIT 1) AS enrollment_id
        FROM students s
        LEFT JOIN departments d ON s.department_id = d.department_id
        WHERE s.stud_id = ?
        LIMIT 1
    ");

    if (!$studentStmt) {
        rserves_admin_json_response([
            'success' => false,
            'message' => 'Unable to prepare the student lookup.',
        ], 500);
    }

    $studentStmt->bind_param("i", $studentId);
    $studentStmt->execute();
    $student = $studentStmt->get_result()->fetch_assoc();
    $studentStmt->close();

    if (!$student) {
        rserves_admin_json_response([
            'success' => false,
            'message' => 'Student record not found.',
        ], 404);
    }

    $student['photo_url'] = rserves_admin_resolve_profile_photo_url($student['photo'] ?? null);
    $student['waiver_status'] = $student['waiver_status'] ?? 'None';
    $student['agreement_status'] = $student['agreement_status'] ?? 'None';
    $student['enrollment_status'] = $student['enrollment_status'] ?? 'None';

    $enrollment = null;
    $enrollmentStmt = $conn->prepare("SELECT * FROM rss_enrollments WHERE student_id = ? ORDER BY enrollment_id DESC LIMIT 1");
    if ($enrollmentStmt) {
        $enrollmentStmt->bind_param("i", $studentId);
        $enrollmentStmt->execute();
        $enrollment = $enrollmentStmt->get_result()->fetch_assoc();
        $enrollmentStmt->close();
    }

    if ($enrollment) {
        $enrollment['photo_url'] = rserves_admin_resolve_asset_url($enrollment['photo_path'] ?? null);
        $signatureImage = trim((string) ($enrollment['signature_image'] ?? ''));
        $enrollment['signature_url'] = $signatureImage !== '' ? rserves_admin_resolve_asset_url($signatureImage) : null;
    }

    $loginHistory = [];
    $historyStmt = $conn->prepare("
        SELECT login_at, ip_address, user_agent
        FROM student_login_history
        WHERE student_id = ?
        ORDER BY login_at DESC
        LIMIT 15
    ");
    if ($historyStmt) {
        $historyStmt->bind_param("i", $studentId);
        $historyStmt->execute();
        $historyResult = $historyStmt->get_result();
        while ($row = $historyResult->fetch_assoc()) {
            $loginHistory[] = $row;
        }
        $historyStmt->close();
    }

    $recentMessages = [];
    $messageStmt = $conn->prepare("
        SELECT announcement_id, subject, message, sender_role, created_at
        FROM student_announcements
        WHERE student_id = ?
        ORDER BY created_at DESC
        LIMIT 10
    ");
    if ($messageStmt) {
        $messageStmt->bind_param("i", $studentId);
        $messageStmt->execute();
        $messageResult = $messageStmt->get_result();
        while ($row = $messageResult->fetch_assoc()) {
            $recentMessages[] = $row;
        }
        $messageStmt->close();
    }

    $recentReports = [];
    $reportStmt = $conn->prepare("
        SELECT id, work_date, activity, time_start, time_end, hours, status, photo, photo2, created_at
        FROM accomplishment_reports
        WHERE student_id = ?
        ORDER BY created_at DESC
        LIMIT 12
    ");
    if ($reportStmt) {
        $reportStmt->bind_param("i", $studentId);
        $reportStmt->execute();
        $reportResult = $reportStmt->get_result();
        while ($row = $reportResult->fetch_assoc()) {
            $photos = [];
            $photoOneUrl = rserves_admin_resolve_asset_url($row['photo'] ?? null);
            $photoTwoUrl = rserves_admin_resolve_asset_url($row['photo2'] ?? null);

            if ($photoOneUrl !== null) {
                $photos[] = [
                    'label' => 'Photo 1',
                    'url' => $photoOneUrl,
                ];
            }

            if ($photoTwoUrl !== null) {
                $photos[] = [
                    'label' => 'Photo 2',
                    'url' => $photoTwoUrl,
                ];
            }

            $row['activity'] = trim((string) preg_replace('/\[TaskID:\d+\]/', '', (string) ($row['activity'] ?? '')));
            $row['photos'] = $photos;
            unset($row['photo'], $row['photo2']);
            $recentReports[] = $row;
        }
        $reportStmt->close();
    }

    rserves_admin_json_response([
        'success' => true,
        'student' => $student,
        'enrollment' => $enrollment,
        'login_history' => $loginHistory,
        'recent_messages' => $recentMessages,
        'recent_reports' => $recentReports,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'update_student_record')) {
    $studentId = intval($_POST['student_id'] ?? 0);
    if ($studentId <= 0) {
        rserves_admin_json_response([
            'success' => false,
            'message' => 'A valid student record is required.',
        ], 422);
    }

    $studentStmt = $conn->prepare("
        SELECT stud_id, firstname, lastname, mi, email, student_number, COALESCE(year_level, 1) AS year_level,
               COALESCE(section, 'A') AS section, department_id
        FROM students
        WHERE stud_id = ?
        LIMIT 1
    ");
    if (!$studentStmt) {
        rserves_admin_json_response([
            'success' => false,
            'message' => 'Unable to prepare the student update.',
        ], 500);
    }

    $studentStmt->bind_param("i", $studentId);
    $studentStmt->execute();
    $studentRow = $studentStmt->get_result()->fetch_assoc();
    $studentStmt->close();

    if (!$studentRow) {
        rserves_admin_json_response([
            'success' => false,
            'message' => 'Student record not found.',
        ], 404);
    }

    $enrollmentLookup = $conn->prepare("SELECT enrollment_id FROM rss_enrollments WHERE student_id = ? ORDER BY enrollment_id DESC LIMIT 1");
    if (!$enrollmentLookup) {
        rserves_admin_json_response([
            'success' => false,
            'message' => 'Unable to locate the enrollment record.',
        ], 500);
    }

    $enrollmentLookup->bind_param("i", $studentId);
    $enrollmentLookup->execute();
    $enrollmentRow = $enrollmentLookup->get_result()->fetch_assoc();
    $enrollmentLookup->close();

    $enrollmentId = intval($enrollmentRow['enrollment_id'] ?? 0);
    if ($enrollmentId <= 0) {
        rserves_admin_json_response([
            'success' => false,
            'message' => 'This student does not have an enrollment record to update yet.',
        ], 404);
    }

    $enrollmentValues = [];
    $enrollmentTypes = '';
    $enrollmentSetClauses = [];
    $normalizedEnrollment = [];

    foreach ($adminEnrollmentEditableFields as $field => $bindType) {
        if (!array_key_exists($field, $_POST)) {
            continue;
        }

        $value = $_POST[$field];

        if ($field === 'college') {
            $value = normalizeEnrollmentCollege((string) $value, $enrollment_college_aliases);
        } elseif ($field === 'year_level') {
            $value = max(1, min(4, intval($value)));
        } else {
            $value = trim((string) $value);
        }

        $normalizedEnrollment[$field] = $value;
        $enrollmentSetClauses[] = $field . ' = ?';
        $enrollmentTypes .= $bindType;
        $enrollmentValues[] = $value;
    }

    if (empty($enrollmentSetClauses)) {
        rserves_admin_json_response([
            'success' => false,
            'message' => 'No enrollment fields were provided for update.',
        ], 422);
    }

    $newDepartmentId = intval($studentRow['department_id'] ?? 0);
    if (isset($normalizedEnrollment['college'])) {
        $mappedDepartmentId = rserves_admin_department_id_from_college((string) $normalizedEnrollment['college']);
        if ($mappedDepartmentId !== null) {
            $newDepartmentId = $mappedDepartmentId;
        }
    }

    $newYearLevel = intval($normalizedEnrollment['year_level'] ?? $studentRow['year_level']);
    $newSection = trim((string) ($normalizedEnrollment['section'] ?? $studentRow['section']));

    $studentUpdates = [];
    $studentTypes = '';
    $studentValues = [];
    $studentFieldMap = [
        'given_name' => ['column' => 'firstname', 'type' => 's'],
        'surname' => ['column' => 'lastname', 'type' => 's'],
        'middle_name' => ['column' => 'mi', 'type' => 's'],
        'student_number' => ['column' => 'student_number', 'type' => 's'],
        'email_address' => ['column' => 'email', 'type' => 's'],
        'year_level' => ['column' => 'year_level', 'type' => 'i'],
        'section' => ['column' => 'section', 'type' => 's'],
    ];

    foreach ($studentFieldMap as $enrollmentField => $mapping) {
        if (!array_key_exists($enrollmentField, $normalizedEnrollment)) {
            continue;
        }

        $studentUpdates[] = $mapping['column'] . ' = ?';
        $studentTypes .= $mapping['type'];
        $studentValues[] = $normalizedEnrollment[$enrollmentField];
    }

    if ($newDepartmentId !== intval($studentRow['department_id'] ?? 0)) {
        $studentUpdates[] = 'department_id = ?';
        $studentTypes .= 'i';
        $studentValues[] = $newDepartmentId;
    }

    try {
        $conn->begin_transaction();

        $updateEnrollmentSql = "UPDATE rss_enrollments SET " . implode(', ', $enrollmentSetClauses) . " WHERE enrollment_id = ?";
        $updateEnrollmentStmt = $conn->prepare($updateEnrollmentSql);
        if (!$updateEnrollmentStmt) {
            throw new RuntimeException('Unable to prepare the enrollment update.');
        }

        $enrollmentTypesWithId = $enrollmentTypes . 'i';
        $enrollmentValuesWithId = $enrollmentValues;
        $enrollmentValuesWithId[] = $enrollmentId;
        if (!rserves_admin_bind_params($updateEnrollmentStmt, $enrollmentTypesWithId, $enrollmentValuesWithId) || !$updateEnrollmentStmt->execute()) {
            $errorMessage = $updateEnrollmentStmt->error ?: 'Unable to update the enrollment record.';
            $updateEnrollmentStmt->close();
            throw new RuntimeException($errorMessage);
        }
        $updateEnrollmentStmt->close();

        if (!empty($studentUpdates)) {
            $updateStudentSql = "UPDATE students SET " . implode(', ', $studentUpdates) . " WHERE stud_id = ?";
            $updateStudentStmt = $conn->prepare($updateStudentSql);
            if (!$updateStudentStmt) {
                throw new RuntimeException('Unable to prepare the student profile update.');
            }

            $studentTypesWithId = $studentTypes . 'i';
            $studentValuesWithId = $studentValues;
            $studentValuesWithId[] = $studentId;
            if (!rserves_admin_bind_params($updateStudentStmt, $studentTypesWithId, $studentValuesWithId) || !$updateStudentStmt->execute()) {
                $errorMessage = $updateStudentStmt->error ?: 'Unable to update the student profile.';
                $updateStudentStmt->close();
                throw new RuntimeException($errorMessage);
            }
            $updateStudentStmt->close();
        }

        $adviserId = 0;
        if ($newDepartmentId > 0 && $newSection !== '') {
            $adviserStmt = $conn->prepare("
                SELECT instructor_id
                FROM section_advisers
                WHERE department_id = ? AND section = ?
                ORDER BY id DESC
                LIMIT 1
            ");

            if ($adviserStmt) {
                $adviserStmt->bind_param("is", $newDepartmentId, $newSection);
                $adviserStmt->execute();
                $adviserRow = $adviserStmt->get_result()->fetch_assoc();
                $adviserId = intval($adviserRow['instructor_id'] ?? 0);
                $adviserStmt->close();
            }
        }

        $sectionRequestStmt = $conn->prepare("
            INSERT INTO section_requests (student_id, year_level, section, adviser_id, status)
            VALUES (?, ?, ?, ?, 'Pending')
            ON DUPLICATE KEY UPDATE
                year_level = VALUES(year_level),
                section = VALUES(section),
                adviser_id = VALUES(adviser_id)
        ");
        if ($sectionRequestStmt) {
            $sectionRequestStmt->bind_param("iisi", $studentId, $newYearLevel, $newSection, $adviserId);
            $sectionRequestStmt->execute();
            $sectionRequestStmt->close();
        }

        if ($adviserId > 0) {
            $adviserLinkStmt = $conn->prepare("UPDATE students SET instructor_id = ? WHERE stud_id = ?");
            if ($adviserLinkStmt) {
                $adviserLinkStmt->bind_param("ii", $adviserId, $studentId);
                $adviserLinkStmt->execute();
                $adviserLinkStmt->close();
            }
        } else {
            $clearAdviserStmt = $conn->prepare("UPDATE students SET instructor_id = NULL WHERE stud_id = ?");
            if ($clearAdviserStmt) {
                $clearAdviserStmt->bind_param("i", $studentId);
                $clearAdviserStmt->execute();
                $clearAdviserStmt->close();
            }
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        rserves_admin_json_response([
            'success' => false,
            'message' => $e->getMessage(),
        ], 500);
    }

    rserves_admin_json_response([
        'success' => true,
        'message' => 'Student enrollment information was updated successfully.',
        'updated' => [
            'student_id' => $studentId,
            'department_id' => $newDepartmentId,
            'department_name' => $deptNames[$newDepartmentId] ?? ('Department ' . $newDepartmentId),
            'year_level' => $newYearLevel,
            'section' => $newSection,
        ],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'reset_student_password')) {
    $studentId = intval($_POST['student_id'] ?? 0);
    if ($studentId <= 0) {
        rserves_admin_json_response([
            'success' => false,
            'message' => 'A valid student record is required.',
        ], 422);
    }

    $studentLookup = $conn->prepare("SELECT firstname, lastname, email FROM students WHERE stud_id = ? LIMIT 1");
    if (!$studentLookup) {
        rserves_admin_json_response([
            'success' => false,
            'message' => 'Unable to prepare the password reset request.',
        ], 500);
    }

    $studentLookup->bind_param("i", $studentId);
    $studentLookup->execute();
    $student = $studentLookup->get_result()->fetch_assoc();
    $studentLookup->close();

    if (!$student) {
        rserves_admin_json_response([
            'success' => false,
            'message' => 'Student record not found.',
        ], 404);
    }

    $temporaryPassword = rserves_admin_generate_temp_password();
    $passwordHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
    $resetStmt = $conn->prepare("UPDATE students SET password = ? WHERE stud_id = ?");
    if (!$resetStmt) {
        rserves_admin_json_response([
            'success' => false,
            'message' => 'Unable to prepare the password reset update.',
        ], 500);
    }

    $resetStmt->bind_param("si", $passwordHash, $studentId);
    $passwordUpdated = $resetStmt->execute();
    $resetError = $resetStmt->error;
    $resetStmt->close();

    if (!$passwordUpdated) {
        rserves_admin_json_response([
            'success' => false,
            'message' => $resetError !== '' ? $resetError : 'The password could not be reset.',
        ], 500);
    }

    $studentName = trim(((string) ($student['firstname'] ?? '')) . ' ' . ((string) ($student['lastname'] ?? '')));
    $emailStatus = null;
    if (trim((string) ($student['email'] ?? '')) !== '') {
        $emailStatus = sendEmail(
            (string) $student['email'],
            $studentName !== '' ? $studentName : 'Student',
            'RServeS Password Reset',
            "Hello {$studentName},\n\nYour RServeS account password has been reset by the administrator.\n\nTemporary password: {$temporaryPassword}\n\nPlease sign in and change this password immediately."
        );
    }

    rserves_admin_json_response([
        'success' => true,
        'message' => 'Temporary password generated successfully.',
        'temporary_password' => $temporaryPassword,
        'email_sent' => $emailStatus === true,
        'email_status' => $emailStatus === true
            ? 'The temporary password was emailed to the student.'
            : ((is_string($emailStatus) && $emailStatus !== '') ? 'The password was reset, but email delivery failed: ' . $emailStatus : 'The password was reset. Share the temporary password securely with the student if needed.'),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'send_direct_message')) {
    $studentId = intval($_POST['student_id'] ?? 0);
    $subject = trim((string) ($_POST['subject'] ?? ''));
    $messageBody = trim((string) ($_POST['message'] ?? ''));

    if ($studentId <= 0) {
        rserves_admin_json_response([
            'success' => false,
            'message' => 'A valid student record is required.',
        ], 422);
    }

    if ($subject === '' || $messageBody === '') {
        rserves_admin_json_response([
            'success' => false,
            'message' => 'Please provide both a subject and a message.',
        ], 422);
    }

    $studentLookup = $conn->prepare("SELECT firstname, lastname, email FROM students WHERE stud_id = ? LIMIT 1");
    if (!$studentLookup) {
        rserves_admin_json_response([
            'success' => false,
            'message' => 'Unable to prepare the direct message lookup.',
        ], 500);
    }

    $studentLookup->bind_param("i", $studentId);
    $studentLookup->execute();
    $student = $studentLookup->get_result()->fetch_assoc();
    $studentLookup->close();

    if (!$student) {
        rserves_admin_json_response([
            'success' => false,
            'message' => 'Student record not found.',
        ], 404);
    }

    $adminId = intval($_SESSION['adm_id'] ?? 0);
    $messageStmt = $conn->prepare("
        INSERT INTO student_announcements (student_id, instructor_id, subject, message, sender_role, admin_id)
        VALUES (?, 0, ?, ?, 'Administrator', ?)
    ");

    if (!$messageStmt) {
        rserves_admin_json_response([
            'success' => false,
            'message' => 'Unable to prepare the direct message.',
        ], 500);
    }

    $messageStmt->bind_param("issi", $studentId, $subject, $messageBody, $adminId);
    $saved = $messageStmt->execute();
    $insertedId = intval($messageStmt->insert_id);
    $messageError = $messageStmt->error;
    $messageStmt->close();

    if (!$saved) {
        rserves_admin_json_response([
            'success' => false,
            'message' => $messageError !== '' ? $messageError : 'The direct message could not be saved.',
        ], 500);
    }

    $studentName = trim(((string) ($student['firstname'] ?? '')) . ' ' . ((string) ($student['lastname'] ?? '')));
    $emailStatus = null;
    if (trim((string) ($student['email'] ?? '')) !== '') {
        $emailStatus = sendEmail(
            (string) $student['email'],
            $studentName !== '' ? $studentName : 'Student',
            'Direct Message from Administration: ' . $subject,
            $messageBody
        );
    }

    rserves_admin_json_response([
        'success' => true,
        'message' => 'Direct message sent successfully.',
        'email_sent' => $emailStatus === true,
        'email_status' => $emailStatus === true
            ? 'A copy of the message was also emailed to the student.'
            : ((is_string($emailStatus) && $emailStatus !== '') ? 'The portal message was saved, but email delivery failed: ' . $emailStatus : 'The portal message was saved for the student.'),
        'saved_message' => [
            'announcement_id' => $insertedId,
            'subject' => $subject,
            'message' => $messageBody,
            'sender_role' => 'Administrator',
            'created_at' => date('Y-m-d H:i:s'),
        ],
    ]);
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

$departments = [];

// ✅ Fetch students
$students = $conn->query("
    SELECT 
        s.stud_id,
        s.firstname,
        s.lastname,
        s.email,
        s.student_number,
        s.department_id,
        COALESCE(s.year_level, 1) as year_level,
        COALESCE(s.section, 'A') as section,
        COALESCE((SELECT SUM(hours) FROM accomplishment_reports WHERE student_id = s.stud_id AND status = 'Approved'), 0) as completed_hours,
        (SELECT status FROM rss_waivers WHERE student_id = s.stud_id ORDER BY id DESC LIMIT 1) as waiver_status,
        (SELECT status FROM rss_agreements WHERE student_id = s.stud_id ORDER BY agreement_id DESC LIMIT 1) as agreement_status,
        (SELECT status FROM rss_enrollments WHERE student_id = s.stud_id ORDER BY enrollment_id DESC LIMIT 1) as enrollment_status,
        (SELECT enrollment_id FROM rss_enrollments WHERE student_id = s.stud_id ORDER BY enrollment_id DESC LIMIT 1) as enrollment_id
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
            'stud_id' => $s['stud_id'],
            'name' => $s['firstname'] . ' ' . $s['lastname'],
            'email' => $s['email'],
            'student_number' => $s['student_number'],
            'department_id' => $deptId,
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

function adminDashboardDepartmentIcon($departmentName) {
    $name = strtolower($departmentName);

    if (strpos($name, 'education') !== false) {
        return 'fa-book-open';
    }

    if (strpos($name, 'technology') !== false) {
        return 'fa-laptop-code';
    }

    if (strpos($name, 'hospitality') !== false || strpos($name, 'tourism') !== false) {
        return 'fa-utensils';
    }

    return 'fa-university';
}

function adminDashboardRelativeTime($timestamp) {
    if (empty($timestamp)) {
        return 'Just now';
    }

    $createdAt = strtotime($timestamp);
    if (!$createdAt) {
        return 'Recently';
    }

    $elapsed = time() - $createdAt;

    if ($elapsed < 60) {
        return 'Just now';
    }

    if ($elapsed < 3600) {
        $minutes = (int) floor($elapsed / 60);
        return $minutes . ' min' . ($minutes === 1 ? '' : 's') . ' ago';
    }

    if ($elapsed < 86400) {
        $hours = (int) floor($elapsed / 3600);
        return $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ago';
    }

    if ($elapsed < 172800) {
        return 'Yesterday';
    }

    $days = (int) floor($elapsed / 86400);
    if ($days < 7) {
        return $days . ' days ago';
    }

    return date('M j, Y', $createdAt);
}

$pendingApprovalsQuery = $conn->query("SELECT COUNT(*) as count FROM student_tasks st JOIN tasks t ON st.task_id = t.task_id WHERE st.approval_status = 'Pending Approval'");
$pendingApprovalsRow = $pendingApprovalsQuery->fetch_assoc();
$pendingApprovals = intval($pendingApprovalsRow['count'] ?? 0);
$verifiedDocuments = 0;
$totalExpectedDocuments = $totalStudents * 3;
$totalReadyStudents = 0;
$totalCompletedStudents = 0;
$totalApprovedHours = 0;
$totalSections = 0;
$assignedSections = 0;
$departmentsWithStudents = 0;
$departmentPerformance = [];

foreach ($departments as $deptId => $dept) {
    $deptStudents = $dept['students'] ?? [];
    $deptInstructors = $dept['instructors'] ?? [];
    $deptSections = $dept['sections'] ?? [];
    $deptAdvisers = $dept['advisers'] ?? [];
    $studentCount = count($deptStudents);

    if ($studentCount > 0) {
        $departmentsWithStudents++;
    }

    $deptPendingApprovals = 0;
    $deptVerifiedDocuments = 0;
    $deptReadyStudents = 0;
    $deptCompletedStudents = 0;
    $deptApprovedHours = 0;

    foreach ($deptStudents as $student) {
        foreach (['waiver_status', 'agreement_status', 'enrollment_status'] as $statusKey) {
            $status = $student[$statusKey] ?? 'None';

            if ($status === 'Pending') {
                $pendingApprovals++;
                $deptPendingApprovals++;
            }

            if ($status === 'Verified') {
                $verifiedDocuments++;
                $deptVerifiedDocuments++;
            }
        }

        $overallStatus = $student['overall_status'] ?? 'Pending';
        if ($overallStatus === 'Completed') {
            $totalCompletedStudents++;
            $deptCompletedStudents++;
        }

        if ($overallStatus === 'Verified' || $overallStatus === 'Completed') {
            $totalReadyStudents++;
            $deptReadyStudents++;
        }

        $approvedHours = (int) ($student['completed_hours'] ?? 0);
        $totalApprovedHours += $approvedHours;
        $deptApprovedHours += $approvedHours;
    }

    $sectionCount = count($deptSections);
    $assignedCount = count($deptAdvisers);
    $totalSections += $sectionCount;
    $assignedSections += $assignedCount;

    $documentRate = $studentCount > 0 ? (int) round(($deptVerifiedDocuments / ($studentCount * 3)) * 100) : 0;
    $readinessRate = $studentCount > 0 ? (int) round(($deptReadyStudents / $studentCount) * 100) : 0;
    $completionRate = $studentCount > 0 ? (int) round(($deptCompletedStudents / $studentCount) * 100) : 0;
    $deptScore = $studentCount > 0 ? (int) round(($documentRate * 0.6) + ($readinessRate * 0.25) + ($completionRate * 0.15)) : 0;

    $performanceLabel = 'Attention Needed';
    $performanceTone = 'warning';
    if ($deptScore >= 90) {
        $performanceLabel = 'High Compliance';
        $performanceTone = 'success';
    } elseif ($deptScore >= 75) {
        $performanceLabel = 'On Track';
        $performanceTone = 'info';
    }

    $departmentPerformance[] = [
        'id' => $deptId,
        'name' => $dept['name'],
        'icon' => adminDashboardDepartmentIcon($dept['name']),
        'students' => $studentCount,
        'instructors' => count($deptInstructors),
        'sections' => $sectionCount,
        'assigned_sections' => $assignedCount,
        'pending_approvals' => $deptPendingApprovals,
        'ready_students' => $deptReadyStudents,
        'document_rate' => $documentRate,
        'readiness_rate' => $readinessRate,
        'completion_rate' => $completionRate,
        'score' => max(0, min(100, $deptScore)),
        'label' => $performanceLabel,
        'tone' => $performanceTone,
        'hours' => $deptApprovedHours
    ];
}

usort($departmentPerformance, function ($left, $right) {
    if ($left['score'] === $right['score']) {
        return strcmp($left['name'], $right['name']);
    }

    return $right['score'] - $left['score'];
});

$documentComplianceRate = $totalExpectedDocuments > 0 ? (int) round(($verifiedDocuments / $totalExpectedDocuments) * 100) : 0;
$studentReadinessRate = $totalStudents > 0 ? (int) round(($totalReadyStudents / $totalStudents) * 100) : 0;
$overallCompletionRate = $totalStudents > 0 ? (int) round(($totalCompletedStudents / $totalStudents) * 100) : 0;
$hoursProgressRate = $totalStudents > 0 ? (int) round(($totalApprovedHours / ($totalStudents * 300)) * 100) : 0;
$globalHealthScore = $totalStudents > 0 ? (int) round(($documentComplianceRate * 0.6) + ($studentReadinessRate * 0.25) + ($overallCompletionRate * 0.15)) : 0;
$globalHealthScore = max(0, min(100, $globalHealthScore));
$sectionCoverageRate = $totalSections > 0 ? (int) round(($assignedSections / $totalSections) * 100) : 0;
$departmentCoverageRate = $totalDepartments > 0 ? (int) round(($departmentsWithStudents / $totalDepartments) * 100) : 0;

$dashboardState = 'optimal';
$dashboardStateText = 'System status is performing strongly.';
if ($pendingApprovals >= 15 || $globalHealthScore < 70) {
    $dashboardState = 'attention';
    $dashboardStateText = 'System status needs attention.';
} elseif ($pendingApprovals >= 5 || $globalHealthScore < 85) {
    $dashboardState = 'monitoring';
    $dashboardStateText = 'System status is stable and under watch.';
}

$recentActivity = array_slice($notifications, 0, 4);
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

        .admin-overview {
            --admin-accent: #0f4c97;
            --admin-accent-deep: #0a3470;
            --admin-accent-soft: #eef4ff;
            --admin-surface: #ffffff;
            --admin-surface-muted: #f7fafe;
            --admin-border: #dbe7f6;
            --admin-ink: #10213d;
            --admin-muted: #69809f;
            --admin-success: #16795d;
            --admin-success-soft: #dff7ed;
            --admin-warning: #c97710;
            --admin-warning-soft: #fff1d6;
            --admin-info: #0c6ea8;
            --admin-info-soft: #dff2fb;
            color: var(--admin-ink);
        }

        .admin-overview-hero {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 1.5rem;
            margin-bottom: 1.75rem;
        }

        .admin-overview-copy {
            max-width: 56rem;
        }

        .admin-overview-kicker {
            margin: 0 0 0.7rem;
            font-size: 0.78rem;
            font-weight: 800;
            letter-spacing: 0.24em;
            text-transform: uppercase;
            color: var(--admin-muted);
        }

        .admin-overview-title {
            margin: 0;
            font-size: clamp(2rem, 4.2vw, 3.4rem);
            font-weight: 800;
            letter-spacing: -0.04em;
            color: var(--admin-accent-deep);
        }

        .admin-overview-subtitle {
            margin: 0.8rem 0 0;
            max-width: 48rem;
            font-size: 1.05rem;
            color: #556b88;
        }

        .admin-overview-subtitle strong {
            color: var(--admin-accent);
            font-weight: 800;
        }

        .admin-state-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.55rem;
            padding: 0.85rem 1.05rem;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.88);
            border: 1px solid var(--admin-border);
            color: var(--admin-accent-deep);
            font-weight: 700;
            box-shadow: 0 16px 32px rgba(15, 37, 76, 0.08);
            white-space: nowrap;
        }

        .admin-state-dot {
            width: 0.7rem;
            height: 0.7rem;
            border-radius: 999px;
            background: #0f4c97;
            box-shadow: 0 0 0 6px rgba(15, 76, 151, 0.12);
            flex-shrink: 0;
        }

        .admin-state-badge.is-monitoring .admin-state-dot {
            background: #c97710;
            box-shadow: 0 0 0 6px rgba(201, 119, 16, 0.14);
        }

        .admin-state-badge.is-attention .admin-state-dot {
            background: #c5542d;
            box-shadow: 0 0 0 6px rgba(197, 84, 45, 0.14);
        }

        .admin-overview-top-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.8fr) repeat(2, minmax(220px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .admin-card {
            position: relative;
            overflow: hidden;
            background: var(--admin-surface);
            border: 1px solid rgba(219, 231, 246, 0.95);
            border-radius: 28px;
            box-shadow: 0 24px 60px rgba(16, 33, 61, 0.08);
        }

        .admin-focus-card {
            display: grid;
            grid-template-columns: minmax(0, 1.2fr) auto;
            gap: 1.5rem;
            align-items: center;
            padding: 1.9rem 2rem;
            background:
                linear-gradient(135deg, rgba(255, 255, 255, 0.98), rgba(245, 249, 255, 0.92)),
                radial-gradient(circle at top left, rgba(15, 76, 151, 0.18), transparent 36%);
        }

        .admin-focus-card::before {
            content: '';
            position: absolute;
            left: 0;
            top: 1.4rem;
            bottom: 1.4rem;
            width: 4px;
            border-radius: 999px;
            background: linear-gradient(180deg, var(--admin-accent), #2e78c7);
        }

        .admin-focus-kicker {
            margin: 0 0 0.85rem;
            font-size: 0.9rem;
            font-weight: 800;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: #4f75a5;
        }

        .admin-focus-title {
            margin: 0;
            font-size: clamp(1.8rem, 3vw, 2.9rem);
            font-weight: 800;
            letter-spacing: -0.04em;
        }

        .admin-focus-text {
            margin: 0.9rem 0 1.4rem;
            max-width: 32rem;
            color: #4b617c;
            font-size: 1rem;
            line-height: 1.65;
        }

        .admin-focus-stats {
            display: flex;
            flex-wrap: wrap;
            gap: 0.85rem;
            margin-bottom: 1.35rem;
        }

        .admin-focus-chip {
            min-width: 10.75rem;
            padding: 0.9rem 1rem;
            border-radius: 18px;
            background: var(--admin-surface-muted);
            border: 1px solid rgba(219, 231, 246, 0.95);
        }

        .admin-focus-chip-label {
            display: block;
            margin-bottom: 0.3rem;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--admin-muted);
        }

        .admin-focus-chip-value {
            display: block;
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--admin-accent-deep);
        }

        .admin-focus-progress {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .admin-focus-progress-copy {
            min-width: 10rem;
            font-size: 0.95rem;
            font-weight: 800;
            color: var(--admin-accent-deep);
        }

        .admin-focus-progress-track {
            position: relative;
            flex: 1;
            height: 0.65rem;
            border-radius: 999px;
            background: rgba(15, 76, 151, 0.12);
            overflow: hidden;
        }

        .admin-focus-progress-track span {
            position: absolute;
            inset: 0 auto 0 0;
            width: 0;
            border-radius: inherit;
            background: linear-gradient(90deg, var(--admin-accent-deep), #2b7acb);
        }

        .admin-score-ring {
            --score: 0;
            position: relative;
            width: 11rem;
            height: 11rem;
            border-radius: 50%;
            background: conic-gradient(var(--admin-accent) calc(var(--score) * 1%), rgba(15, 76, 151, 0.10) 0);
            display: grid;
            place-items: center;
            flex-shrink: 0;
        }

        .admin-score-ring::before {
            content: '';
            position: absolute;
            inset: 1.15rem;
            border-radius: 50%;
            background: #ffffff;
            box-shadow: inset 0 0 0 1px rgba(219, 231, 246, 0.95);
        }

        .admin-score-ring-value {
            position: relative;
            z-index: 1;
            text-align: center;
            color: var(--admin-accent);
            font-weight: 800;
            line-height: 1;
        }

        .admin-score-ring-value strong {
            display: block;
            font-size: 2.2rem;
            letter-spacing: -0.06em;
        }

        .admin-score-ring-value span {
            display: block;
            margin-top: 0.3rem;
            font-size: 0.78rem;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--admin-muted);
        }

        .admin-summary-card {
            padding: 1.65rem 1.5rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 100%;
        }

        .admin-summary-icon {
            width: 3.1rem;
            height: 3.1rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 18px;
            background: var(--admin-accent-soft);
            color: var(--admin-accent);
            font-size: 1.3rem;
            margin-bottom: 1.25rem;
        }

        .admin-summary-label {
            display: block;
            margin-bottom: 0.35rem;
            font-size: 0.95rem;
            color: #607897;
            font-weight: 700;
        }

        .admin-summary-value {
            margin: 0;
            font-size: 2.15rem;
            font-weight: 800;
            letter-spacing: -0.04em;
            color: var(--admin-ink);
        }

        .admin-summary-footer {
            margin-top: 1.4rem;
            padding-top: 1rem;
            border-top: 1px solid rgba(219, 231, 246, 0.95);
            display: flex;
            justify-content: space-between;
            gap: 0.8rem;
            font-size: 0.92rem;
            color: var(--admin-muted);
        }

        .admin-summary-footer strong {
            color: var(--admin-accent-deep);
            font-weight: 800;
        }

        .admin-overview-main-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.65fr) minmax(320px, 0.9fr);
            gap: 1.75rem;
            align-items: start;
        }

        .admin-section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .admin-section-title {
            margin: 0;
            font-size: 2rem;
            font-weight: 800;
            letter-spacing: -0.04em;
        }

        .admin-section-link {
            border: 0;
            background: transparent;
            padding: 0;
            color: var(--admin-accent-deep);
            font-size: 1rem;
            font-weight: 800;
        }

        .admin-performance-list {
            display: grid;
            gap: 1rem;
        }

        .admin-department-card {
            padding: 1.4rem 1.5rem;
        }

        .admin-department-head {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr) auto;
            gap: 1rem;
            align-items: center;
            margin-bottom: 1rem;
        }

        .admin-department-icon {
            width: 3.4rem;
            height: 3.4rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 18px;
            background: var(--admin-surface-muted);
            color: var(--admin-accent-deep);
            font-size: 1.25rem;
        }

        .admin-department-name {
            margin: 0;
            font-size: 1.45rem;
            font-weight: 800;
            letter-spacing: -0.03em;
        }

        .admin-department-meta {
            margin: 0.2rem 0 0;
            color: var(--admin-muted);
            font-weight: 600;
        }

        .admin-status-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.55rem 0.9rem;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 800;
            letter-spacing: 0.09em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .admin-status-pill.is-success {
            color: var(--admin-success);
            background: var(--admin-success-soft);
        }

        .admin-status-pill.is-info {
            color: var(--admin-info);
            background: var(--admin-info-soft);
        }

        .admin-status-pill.is-warning {
            color: var(--admin-warning);
            background: var(--admin-warning-soft);
        }

        .admin-department-progress {
            position: relative;
            height: 0.7rem;
            border-radius: 999px;
            background: rgba(15, 76, 151, 0.10);
            overflow: hidden;
        }

        .admin-department-progress span {
            position: absolute;
            inset: 0 auto 0 0;
            width: 0;
            border-radius: inherit;
            background: linear-gradient(90deg, var(--admin-accent-deep), #2576c7);
        }

        .admin-department-footer {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.85rem;
            margin-top: 0.95rem;
            color: var(--admin-muted);
            font-size: 0.95rem;
            font-weight: 700;
        }

        .admin-side-stack {
            display: grid;
            gap: 1.25rem;
        }

        .admin-quick-card {
            padding: 1.5rem;
            background: linear-gradient(160deg, #144784, #275d9a);
            color: #ffffff;
        }

        .admin-quick-card .admin-section-title,
        .admin-quick-card .admin-section-link {
            color: #ffffff;
        }

        .admin-action-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.85rem;
            margin-top: 1.1rem;
        }

        .admin-action-tile {
            border: 1px solid rgba(255, 255, 255, 0.12);
            background: rgba(255, 255, 255, 0.10);
            color: #ffffff;
            border-radius: 18px;
            padding: 1.15rem 0.95rem;
            min-height: 8.3rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            gap: 0.8rem;
            transition: transform 180ms ease, background 180ms ease;
        }

        .admin-action-tile:hover {
            transform: translateY(-2px);
            background: rgba(255, 255, 255, 0.16);
        }

        .admin-action-icon {
            width: 2.6rem;
            height: 2.6rem;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.92);
            color: #154884;
            font-size: 1.05rem;
        }

        .admin-action-label {
            font-size: 0.9rem;
            font-weight: 800;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .admin-activity-card {
            padding: 1.45rem 1.5rem;
        }

        .admin-activity-timeline {
            position: relative;
            margin-top: 1.2rem;
            padding-left: 1.05rem;
        }

        .admin-activity-timeline::before {
            content: '';
            position: absolute;
            left: 0.25rem;
            top: 0.2rem;
            bottom: 0.2rem;
            width: 2px;
            background: rgba(15, 76, 151, 0.12);
        }

        .admin-activity-item,
        .admin-activity-empty {
            position: relative;
        }

        .admin-activity-item {
            width: 100%;
            margin: 0 0 1.1rem;
            padding: 0 0 0 1.5rem;
            border: 0;
            background: transparent;
            text-align: left;
            cursor: pointer;
        }

        .admin-activity-item:last-child {
            margin-bottom: 0;
        }

        .admin-activity-item::before {
            content: '';
            position: absolute;
            left: -0.1rem;
            top: 0.35rem;
            width: 0.8rem;
            height: 0.8rem;
            border-radius: 999px;
            background: var(--admin-accent);
            box-shadow: 0 0 0 5px #ffffff;
        }

        .admin-activity-item.is-agreement::before {
            background: var(--admin-info);
        }

        .admin-activity-item.is-enrollment::before {
            background: #d39a19;
        }

        .admin-activity-time {
            display: block;
            margin-bottom: 0.3rem;
            color: var(--admin-muted);
            font-size: 0.94rem;
            font-weight: 700;
        }

        .admin-activity-title {
            display: block;
            margin-bottom: 0.25rem;
            font-size: 1.2rem;
            font-weight: 800;
            color: var(--admin-ink);
        }

        .admin-activity-text {
            display: block;
            color: #5e7491;
            line-height: 1.55;
        }

        .admin-activity-empty {
            padding: 1rem 1rem 1rem 2.2rem;
            color: var(--admin-muted);
            font-weight: 600;
        }

        .admin-history-link {
            margin-top: 0.8rem;
            border: 0;
            background: transparent;
            padding: 0;
            color: var(--admin-accent-deep);
            font-weight: 800;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        .student-record-shell {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }

        .student-record-summary {
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 22px;
            padding: 1.25rem;
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.96) 0%, rgba(240, 246, 255, 0.92) 100%);
        }

        .student-record-summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 0.9rem;
            margin-top: 1rem;
        }

        .student-record-metric {
            border-radius: 18px;
            padding: 0.95rem 1rem;
            background: rgba(255, 255, 255, 0.86);
            border: 1px solid rgba(15, 23, 42, 0.06);
        }

        .student-record-metric span {
            display: block;
            color: var(--admin-muted);
            font-size: 0.8rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }

        .student-record-metric strong {
            display: block;
            margin-top: 0.35rem;
            font-size: 1rem;
            color: var(--admin-ink);
        }

        .student-record-section {
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 20px;
            background: #fff;
            overflow: hidden;
        }

        .student-record-section .accordion-button {
            font-weight: 800;
            color: var(--admin-ink);
            background: rgba(248, 251, 255, 0.92);
        }

        .student-record-section .accordion-button:not(.collapsed) {
            color: var(--admin-accent-deep);
            background: rgba(232, 241, 255, 0.96);
            box-shadow: inset 0 -1px 0 rgba(15, 23, 42, 0.06);
        }

        .student-record-form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
        }

        .student-record-form-grid .full-span {
            grid-column: 1 / -1;
        }

        .student-record-photo-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
        }

        .student-record-photo-card {
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 18px;
            background: #fff;
            padding: 0.9rem;
            box-shadow: 0 14px 32px rgba(15, 23, 42, 0.06);
        }

        .student-record-photo-card img {
            width: 100%;
            max-height: 210px;
            object-fit: cover;
            border-radius: 14px;
            background: #edf2f7;
        }

        .student-record-proof-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 0.85rem;
            margin-top: 0.85rem;
        }

        .student-record-proof-thumb {
            border-radius: 14px;
            overflow: hidden;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: #f8fafc;
        }

        .student-record-proof-thumb img {
            width: 100%;
            height: 140px;
            object-fit: cover;
            display: block;
        }

        .student-record-proof-caption {
            padding: 0.6rem 0.7rem;
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--admin-ink);
        }

        .student-record-history-table th,
        .student-record-history-table td {
            font-size: 0.92rem;
            vertical-align: top;
        }

        .student-record-submission {
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 18px;
            padding: 1rem;
            background: rgba(248, 251, 255, 0.78);
        }

        .student-record-message-item {
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 18px;
            padding: 0.95rem 1rem;
            background: #fff;
        }

        .student-record-message-item + .student-record-message-item,
        .student-record-submission + .student-record-submission {
            margin-top: 0.85rem;
        }

        .student-record-inline-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-top: 1rem;
        }

        @media (max-width: 1399px) {
            .admin-overview-top-grid {
                grid-template-columns: minmax(0, 1.5fr) repeat(2, minmax(0, 1fr));
            }

            .admin-overview-main-grid {
                grid-template-columns: minmax(0, 1.4fr) minmax(300px, 0.95fr);
            }
        }

        @media (max-width: 1199px) {
            .admin-overview-hero {
                flex-direction: column;
                align-items: flex-start;
            }

            .admin-overview-top-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .admin-focus-card {
                grid-column: 1 / -1;
            }

            .admin-overview-main-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 767px) {
            .admin-overview-top-grid,
            .admin-action-grid {
                grid-template-columns: 1fr;
            }

            .admin-focus-card {
                grid-template-columns: 1fr;
                padding: 1.45rem;
            }

            .admin-focus-progress {
                flex-direction: column;
                align-items: flex-start;
            }

            .admin-department-head {
                grid-template-columns: auto 1fr;
            }

            .admin-status-pill {
                grid-column: 1 / -1;
                justify-self: flex-start;
            }

            .admin-summary-footer,
            .admin-department-footer {
                flex-direction: column;
                align-items: flex-start;
            }

            .student-record-form-grid .full-span {
                grid-column: auto;
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
<div class="d-flex" id="wrapper">
    <div id="sidebar-wrapper">
        <div class="sidebar-shell">
            <div class="sidebar-heading">
                <span class="sidebar-brand-title">RServeS Portal</span>
                <span class="sidebar-brand-subtitle">Administrator Console</span>
            </div>
            <div class="list-group list-group-flush">
                <a href="#" class="list-group-item list-group-item-action active" data-view="dashboard" onclick="showView('dashboard'); return false;">
                    <i class="fas fa-th-large"></i> Dashboard
                </a>
                <a href="#" class="list-group-item list-group-item-action" data-view="departments" onclick="showView('departments'); return false;">
                    <i class="fas fa-university"></i> Departments
                </a>
                <a href="#" class="list-group-item list-group-item-action" data-view="reports" onclick="showView('reports'); return false;">
                    <i class="fas fa-chart-bar"></i> Reports
                </a>
                <a href="#" class="list-group-item list-group-item-action" data-view="advisory" onclick="showView('advisory'); return false;">
                    <i class="fas fa-chalkboard-teacher"></i> Advisory Management
                </a>
                <a href="#" class="list-group-item list-group-item-action" data-view="users" onclick="showView('users'); return false;">
                    <i class="fas fa-users-cog"></i> User Management
                </a>
                <a href="#" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" data-view="notifications" onclick="showView('notifications'); return false;">
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
                    <img src="<?php echo htmlspecialchars($admin_photo); ?>" alt="Profile" class="sidebar-role-avatar">
                    <div>
                        <div class="sidebar-role-name"><?php echo htmlspecialchars($admin_name); ?></div>
                        <div class="sidebar-role-meta">System Administrator</div>
                    </div>
                </div>
                <button type="button" class="sidebar-support-btn" data-bs-toggle="modal" data-bs-target="#profileModal">
                    Profile Center
                </button>
            </div>
        </div>
    </div>

    <div id="page-content-wrapper">
        <nav class="navbar navbar-expand-lg">
            <div class="topbar-shell">
                <div class="topbar-tabs d-none d-lg-flex">
                    <a href="#" class="topbar-tab active" data-view="dashboard" onclick="showView('dashboard'); return false;">Overview</a>
                    <a href="#" class="topbar-tab" data-view="departments" onclick="showView('departments'); return false;">Departments</a>
                    <a href="#" class="topbar-tab" data-view="reports" onclick="showView('reports'); return false;">Reports</a>
                    <a href="#" class="topbar-tab" data-view="advisory" onclick="showView('advisory'); return false;">Advisory</a>
                    <a href="#" class="topbar-tab" data-view="users" onclick="showView('users'); return false;">Users</a>
                    <a href="#" class="topbar-tab" data-view="notifications" onclick="showView('notifications'); return false;">Notifications</a>
                </div>

                <div class="topbar-actions">
                    <div class="topbar-profile" data-bs-toggle="modal" data-bs-target="#profileModal">
                        <div class="topbar-identity">
                            <div><?php echo htmlspecialchars($admin_name); ?></div>
                            <div>Administrator</div>
                        </div>
                        <img src="<?php echo htmlspecialchars($admin_photo); ?>" alt="Profile" class="topbar-avatar">
                    </div>
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
            
            <div id="dashboard-view" class="admin-overview">
                <section class="admin-overview-hero">
                    <div class="admin-overview-copy">
                        <p class="admin-overview-kicker">Administrator Workspace</p>
                        <h1 class="admin-overview-title">
                            <span id="admin-greeting-label">Welcome back</span>, <?php echo htmlspecialchars($adminData['firstname'] ?? 'Admin'); ?>
                        </h1>
                        <p class="admin-overview-subtitle">
                            <?php echo htmlspecialchars($dashboardStateText); ?>
                            <?php if ($pendingApprovals > 0): ?>
                                You have <strong><?php echo number_format($pendingApprovals); ?> pending approvals</strong> awaiting review.
                            <?php else: ?>
                                No pending approvals are waiting in the queue.
                            <?php endif; ?>
                        </p>
                    </div>

                    <div class="admin-state-badge <?php echo $dashboardState === 'attention' ? 'is-attention' : ($dashboardState === 'monitoring' ? 'is-monitoring' : ''); ?>">
                        <span class="admin-state-dot"></span>
                        <span><?php echo ucfirst($dashboardState); ?> Mode</span>
                    </div>
                </section>

                <div class="admin-overview-top-grid">
                    <article class="admin-card admin-focus-card">
                        <div>
                            <p class="admin-focus-kicker">Institutional Compliance</p>
                            <h2 class="admin-focus-title">System Readiness</h2>
                            <p class="admin-focus-text">
                                Verified documents are holding at <?php echo $documentComplianceRate; ?>% while scholar readiness is at <?php echo $studentReadinessRate; ?>%.
                                Approved service hours are pacing at <?php echo $hoursProgressRate; ?>% of the institutional target, and advisers are covering <?php echo $sectionCoverageRate; ?>% of active sections across the portal.
                            </p>

                            <div class="admin-focus-stats">
                                <div class="admin-focus-chip">
                                    <span class="admin-focus-chip-label">Pending approvals</span>
                                    <span class="admin-focus-chip-value"><?php echo number_format($pendingApprovals); ?></span>
                                </div>
                                <div class="admin-focus-chip">
                                    <span class="admin-focus-chip-label">Active advisers</span>
                                    <span class="admin-focus-chip-value"><?php echo number_format($totalInstructors); ?></span>
                                </div>
                                <div class="admin-focus-chip">
                                    <span class="admin-focus-chip-label">Approved hours</span>
                                    <span class="admin-focus-chip-value"><?php echo number_format($totalApprovedHours); ?></span>
                                </div>
                            </div>

                            <div class="admin-focus-progress">
                                <div class="admin-focus-progress-copy"><?php echo $globalHealthScore; ?>% global score</div>
                                <div class="admin-focus-progress-track">
                                    <span style="width: <?php echo $globalHealthScore; ?>%;"></span>
                                </div>
                            </div>
                        </div>

                        <div class="admin-score-ring" style="--score: <?php echo $globalHealthScore; ?>;">
                            <div class="admin-score-ring-value">
                                <strong><?php echo $globalHealthScore; ?>%</strong>
                                <span>Health score</span>
                            </div>
                        </div>
                    </article>

                    <article class="admin-card admin-summary-card">
                        <div>
                            <div class="admin-summary-icon"><i class="fas fa-university"></i></div>
                            <span class="admin-summary-label">Departments</span>
                            <p class="admin-summary-value"><?php echo number_format($totalDepartments); ?> Active</p>
                        </div>
                        <div class="admin-summary-footer">
                            <span><strong><?php echo $departmentCoverageRate; ?>%</strong> with scholars</span>
                            <span><strong><?php echo $assignedSections; ?>/<?php echo $totalSections; ?></strong> sections assigned</span>
                        </div>
                    </article>

                    <article class="admin-card admin-summary-card">
                        <div>
                            <div class="admin-summary-icon"><i class="fas fa-user-graduate"></i></div>
                            <span class="admin-summary-label">Total Scholars</span>
                            <p class="admin-summary-value"><?php echo number_format($totalStudents); ?></p>
                        </div>
                        <div class="admin-summary-footer">
                            <span><strong><?php echo number_format($totalCompletedStudents); ?></strong> completed</span>
                            <span><strong><?php echo $studentReadinessRate; ?>%</strong> ready</span>
                        </div>
                    </article>
                </div>

                <div class="admin-overview-main-grid">
                    <section>
                        <div class="admin-section-header">
                            <h2 class="admin-section-title">Departmental Performance</h2>
                            <button type="button" class="admin-section-link" onclick="showView('reports')">View Detailed Metrics <i class="fas fa-arrow-right ms-1"></i></button>
                        </div>

                        <div class="admin-performance-list">
                            <?php if (!empty($departmentPerformance)): ?>
                                <?php foreach ($departmentPerformance as $metric): ?>
                                    <article class="admin-card admin-department-card">
                                        <div class="admin-department-head">
                                            <div class="admin-department-icon">
                                                <i class="fas <?php echo htmlspecialchars($metric['icon']); ?>"></i>
                                            </div>

                                            <div>
                                                <h3 class="admin-department-name"><?php echo htmlspecialchars($metric['name']); ?></h3>
                                                <p class="admin-department-meta">
                                                    <?php echo number_format($metric['students']); ?> scholars |
                                                    <?php echo number_format($metric['instructors']); ?> advisers |
                                                    <?php echo number_format($metric['sections']); ?> sections
                                                </p>
                                            </div>

                                            <span class="admin-status-pill is-<?php echo htmlspecialchars($metric['tone']); ?>">
                                                <?php echo htmlspecialchars($metric['label']); ?>
                                            </span>
                                        </div>

                                        <div class="admin-department-progress">
                                            <span style="width: <?php echo $metric['score']; ?>%;"></span>
                                        </div>

                                        <div class="admin-department-footer">
                                            <span><?php echo $metric['score']; ?>% performance score</span>
                                            <span><?php echo $metric['ready_students']; ?> ready scholars</span>
                                            <span><?php echo $metric['pending_approvals']; ?> pending approvals</span>
                                            <span>Target: 95%</span>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <article class="admin-card admin-department-card">
                                    <div class="admin-activity-empty">Department performance will appear once student records are available.</div>
                                </article>
                            <?php endif; ?>
                        </div>
                    </section>

                    <aside class="admin-side-stack">
                        <section class="admin-card admin-quick-card">
                            <div class="admin-section-header">
                                <h2 class="admin-section-title">Quick Management</h2>
                            </div>

                            <div class="admin-action-grid">
                                <button type="button" class="admin-action-tile" onclick="showView('departments')">
                                    <span class="admin-action-icon"><i class="fas fa-building"></i></span>
                                    <span class="admin-action-label">Departments</span>
                                </button>
                                <button type="button" class="admin-action-tile" onclick="showView('reports')">
                                    <span class="admin-action-icon"><i class="fas fa-chart-bar"></i></span>
                                    <span class="admin-action-label">Reports</span>
                                </button>
                                <button type="button" class="admin-action-tile" onclick="showView('advisory')">
                                    <span class="admin-action-icon"><i class="fas fa-user-cog"></i></span>
                                    <span class="admin-action-label">Advisers</span>
                                </button>
                                <button type="button" class="admin-action-tile" onclick="showView('users')">
                                    <span class="admin-action-icon"><i class="fas fa-user-plus"></i></span>
                                    <span class="admin-action-label">User Setup</span>
                                </button>
                            </div>
                        </section>

                        <section class="admin-card admin-activity-card">
                            <div class="admin-section-header">
                                <h2 class="admin-section-title">Recent Activity</h2>
                            </div>

                            <div class="admin-activity-timeline">
                                <?php if (!empty($recentActivity)): ?>
                                    <?php foreach ($recentActivity as $activity): ?>
                                        <?php
                                            $activityType = $activity['type'] ?? 'waiver';
                                            $activityTitle = ucfirst($activityType) . ' Submission';
                                            if ($activityType === 'agreement') {
                                                $activityTitle = 'Agreement Update';
                                            } elseif ($activityType === 'enrollment') {
                                                $activityTitle = 'Enrollment Review';
                                            }
                                        ?>
                                        <button type="button"
                                                class="admin-activity-item is-<?php echo htmlspecialchars($activityType); ?>"
                                                onclick="openAdminNotification(<?php echo (int) $activity['id']; ?>)">
                                            <span class="admin-activity-time"><?php echo htmlspecialchars(adminDashboardRelativeTime($activity['created_at'] ?? null)); ?></span>
                                            <span class="admin-activity-title"><?php echo htmlspecialchars($activityTitle); ?></span>
                                            <span class="admin-activity-text"><?php echo htmlspecialchars($activity['message']); ?></span>
                                        </button>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="admin-activity-empty">
                                        Approval activity will appear here as students submit their records.
                                    </div>
                                <?php endif; ?>
                            </div>

                            <button type="button" class="admin-history-link" onclick="showView('notifications')">View full history</button>
                        </section>
                    </aside>
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
        <a href="#" class="nav-item active" data-view="dashboard" onclick="showView('dashboard'); return false;">
            <i class="fas fa-th-large"></i>
            <span>Dashboard</span>
        </a>
        <a href="#" class="nav-item" data-view="departments" onclick="showView('departments'); return false;">
            <i class="fas fa-university"></i>
            <span>Departments</span>
        </a>
        <a href="#" class="nav-item" data-view="reports" onclick="showView('reports'); return false;">
            <i class="fas fa-chart-bar"></i>
            <span>Reports</span>
        </a>
        <a href="#" class="nav-item" data-view="advisory" onclick="showView('advisory'); return false;">
            <i class="fas fa-chalkboard-teacher"></i>
            <span>Advisory</span>
        </a>
        <a href="#" class="nav-item" data-view="users" onclick="showView('users'); return false;">
            <i class="fas fa-users-cog"></i>
            <span>Users</span>
        </a>
        <a href="#" class="nav-item position-relative" data-view="notifications" onclick="showView('notifications'); return false;">
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
    const adminEnrollmentFieldGroups = <?php echo json_encode($adminEnrollmentFieldGroups); ?>;
    let studentRecordModalInstance = null;
    let activeStudentRecord = null;

    // Menu toggle logic removed as we use mobile nav on mobile and sidebar on desktop

    function getStudentRecordModalInstance() {
        const modalEl = document.getElementById('studentRecordModal');
        if (!modalEl) return null;
        if (!studentRecordModalInstance) {
            studentRecordModalInstance = new bootstrap.Modal(modalEl);
        }
        return studentRecordModalInstance;
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function nl2brSafe(value) {
        return escapeHtml(value).replace(/\r?\n/g, '<br>');
    }

    function formatAdminDateTime(value) {
        if (!value) return 'Not available';
        const parsed = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(parsed.getTime())) {
            return escapeHtml(value);
        }
        return parsed.toLocaleString();
    }

    function adminRecordMetric(label, value) {
        return `
            <div class="student-record-metric">
                <span>${escapeHtml(label)}</span>
                <strong>${value}</strong>
            </div>
        `;
    }

    function renderEnrollmentField(field, values) {
        const fieldName = field.name;
        const rawValue = values && values[fieldName] != null ? values[fieldName] : '';
        const value = String(rawValue);
        const type = field.type || 'text';
        const isTextarea = type === 'textarea';
        const wrapperClass = isTextarea ? 'full-span' : '';

        if (type === 'select') {
            const options = (field.options || []).map(option => {
                const optionValue = typeof option === 'string' ? option : option.value;
                const optionLabel = typeof option === 'string' ? option : option.label;
                const selected = String(optionValue) === value ? 'selected' : '';
                return `<option value="${escapeHtml(optionValue)}" ${selected}>${escapeHtml(optionLabel)}</option>`;
            }).join('');

            return `
                <div class="${wrapperClass}">
                    <label class="form-label fw-semibold">${escapeHtml(field.label)}</label>
                    <select class="form-select" name="${escapeHtml(fieldName)}">
                        <option value="">Select</option>
                        ${options}
                    </select>
                </div>
            `;
        }

        if (isTextarea) {
            return `
                <div class="${wrapperClass}">
                    <label class="form-label fw-semibold">${escapeHtml(field.label)}</label>
                    <textarea class="form-control" rows="3" name="${escapeHtml(fieldName)}">${escapeHtml(value)}</textarea>
                </div>
            `;
        }

        return `
            <div class="${wrapperClass}">
                <label class="form-label fw-semibold">${escapeHtml(field.label)}</label>
                <input type="${escapeHtml(type)}" class="form-control" name="${escapeHtml(fieldName)}" value="${escapeHtml(value)}">
            </div>
        `;
    }

    function renderEnrollmentEditor(enrollment) {
        if (!enrollment) {
            return '<div class="alert alert-warning mb-0">This student has not submitted an enrollment form yet.</div>';
        }

        const photoCards = [];
        if (enrollment.photo_url) {
            photoCards.push(`
                <div class="student-record-photo-card">
                    <div class="small text-uppercase text-muted fw-bold mb-2">Enrollment Photo</div>
                    <a href="${escapeHtml(enrollment.photo_url)}" target="_blank" rel="noopener noreferrer">
                        <img src="${escapeHtml(enrollment.photo_url)}" alt="Enrollment Photo">
                    </a>
                </div>
            `);
        }
        if (enrollment.signature_url) {
            photoCards.push(`
                <div class="student-record-photo-card">
                    <div class="small text-uppercase text-muted fw-bold mb-2">Student Signature</div>
                    <a href="${escapeHtml(enrollment.signature_url)}" target="_blank" rel="noopener noreferrer">
                        <img src="${escapeHtml(enrollment.signature_url)}" alt="Student Signature">
                    </a>
                </div>
            `);
        }

        const groupsHtml = adminEnrollmentFieldGroups.map(group => `
            <div class="mb-4">
                <h6 class="fw-bold mb-3">${escapeHtml(group.title)}</h6>
                <div class="student-record-form-grid">
                    ${(group.fields || []).map(field => renderEnrollmentField(field, enrollment)).join('')}
                </div>
            </div>
        `).join('');

        return `
            ${photoCards.length > 0 ? `<div class="student-record-photo-grid mb-4">${photoCards.join('')}</div>` : ''}
            <form id="studentEnrollmentEditForm">
                <input type="hidden" name="student_id" value="${escapeHtml(enrollment.student_id || ((activeStudentRecord && activeStudentRecord.student) ? activeStudentRecord.student.stud_id : '') || '')}">
                ${groupsHtml}
                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Save Enrollment Changes
                    </button>
                </div>
            </form>
        `;
    }

    function renderLoginHistory(history) {
        if (!Array.isArray(history) || history.length === 0) {
            return '<div class="text-muted">No successful student sign-ins have been recorded yet.</div>';
        }

        const rows = history.map(entry => `
            <tr>
                <td>${formatAdminDateTime(entry.login_at)}</td>
                <td>${escapeHtml(entry.ip_address || 'Unknown')}</td>
                <td>${escapeHtml(entry.user_agent || 'Unknown device')}</td>
            </tr>
        `).join('');

        return `
            <div class="table-responsive">
                <table class="table table-hover student-record-history-table mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Logged In At</th>
                            <th>IP Address</th>
                            <th>Device / Browser</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>
        `;
    }

    function renderMessageHistory(messages) {
        if (!Array.isArray(messages) || messages.length === 0) {
            return '<div class="text-muted">No direct or announcement messages are available for this student yet.</div>';
        }

        return messages.map(entry => `
            <div class="student-record-message-item">
                <div class="d-flex justify-content-between align-items-start gap-3">
                    <div>
                        <div class="fw-bold">${escapeHtml(entry.subject || 'Untitled message')}</div>
                        <div class="small text-muted">${escapeHtml(entry.sender_role || 'Instructor')}</div>
                    </div>
                    <div class="small text-muted text-nowrap">${formatAdminDateTime(entry.created_at)}</div>
                </div>
                <div class="mt-2">${nl2brSafe(entry.message || '')}</div>
            </div>
        `).join('');
    }

    function renderSubmissionPhotos(reports) {
        if (!Array.isArray(reports) || reports.length === 0) {
            return '<div class="text-muted">No accomplishment submissions are available for this student yet.</div>';
        }

        return reports.map(report => {
            const statusBadge = getAdminStatusBadge(report.status || 'Pending');
            const photos = Array.isArray(report.photos) ? report.photos : [];
            const photoHtml = photos.length > 0
                ? `
                    <div class="student-record-proof-grid">
                        ${photos.map(photo => `
                            <a href="${escapeHtml(photo.url)}" target="_blank" rel="noopener noreferrer" class="student-record-proof-thumb text-decoration-none">
                                <img src="${escapeHtml(photo.url)}" alt="${escapeHtml(photo.label || 'Proof photo')}">
                                <div class="student-record-proof-caption">${escapeHtml(photo.label || 'Proof photo')}</div>
                            </a>
                        `).join('')}
                    </div>
                `
                : '<div class="small text-muted mt-2">No proof photos submitted for this report.</div>';

            return `
                <div class="student-record-submission">
                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                        <div>
                            <div class="fw-bold">${escapeHtml(report.activity || 'Activity')}</div>
                            <div class="small text-muted">
                                ${escapeHtml(report.work_date || 'No work date')}
                                ${report.time_start || report.time_end ? ` | ${escapeHtml(report.time_start || '--')} - ${escapeHtml(report.time_end || '--')}` : ''}
                            </div>
                        </div>
                        <div class="text-end">
                            <div class="small text-muted">${formatAdminDateTime(report.created_at)}</div>
                            <div class="mt-1">${statusBadge}</div>
                        </div>
                    </div>
                    <div class="small text-muted mt-2">Hours submitted: ${escapeHtml(report.hours || '0')}</div>
                    ${photoHtml}
                </div>
            `;
        }).join('');
    }

    async function adminPostAction(formData) {
        const response = await fetch('admin_dashboard.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Request failed.');
        }

        return data;
    }

    function renderStudentRecord(record) {
        const modalBody = document.getElementById('studentRecordBody');
        const modalTitle = document.getElementById('studentRecordModalTitle');
        const modalSubtitle = document.getElementById('studentRecordModalSubtitle');
        const enrollmentLink = document.getElementById('studentRecordEnrollmentLink');
        const resetButton = document.getElementById('studentResetPasswordBtn');
        if (!modalBody || !record || !record.student) return;

        const student = record.student;
        const enrollment = record.enrollment;
        const studentName = [student.firstname, student.mi ? `${student.mi}.` : '', student.lastname].filter(Boolean).join(' ');
        const studentPhotoHtml = student.photo_url
            ? `<img src="${escapeHtml(student.photo_url)}" alt="${escapeHtml(studentName)}" class="rounded-circle border" style="width:72px;height:72px;object-fit:cover;">`
            : `<div class="rounded-circle d-inline-flex align-items-center justify-content-center border bg-light fw-bold" style="width:72px;height:72px;">${escapeHtml((student.firstname || 'S').charAt(0).toUpperCase())}</div>`;

        modalTitle.textContent = studentName || 'Student Record';
        modalSubtitle.textContent = student.department_name ? `${student.department_name} - Section ${student.section || 'N/A'}` : `Section ${student.section || 'N/A'}`;

        if (enrollmentLink) {
            if (student.enrollment_id) {
                enrollmentLink.href = `verify_enrollment.php?id=${encodeURIComponent(student.enrollment_id)}`;
                enrollmentLink.classList.remove('d-none');
            } else {
                enrollmentLink.classList.add('d-none');
            }
        }

        if (resetButton) {
            resetButton.dataset.studentId = student.stud_id;
        }

        modalBody.innerHTML = `
            <div class="student-record-shell">
                <div class="student-record-summary">
                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                        <div class="d-flex align-items-center gap-3">
                            ${studentPhotoHtml}
                            <div>
                                <h4 class="mb-1">${escapeHtml(studentName || 'Student')}</h4>
                                <div class="text-muted">${escapeHtml(student.email || 'No email address')}</div>
                                <div class="small text-muted mt-1">Student No. ${escapeHtml(student.student_number || 'Not set')}</div>
                            </div>
                        </div>
                        <div class="d-flex gap-2 flex-wrap">
                            <div class="student-record-metric">
                                <span>Waiver</span>
                                <strong>${getAdminStatusBadge(student.waiver_status || 'None')}</strong>
                            </div>
                            <div class="student-record-metric">
                                <span>Agreement</span>
                                <strong>${getAdminStatusBadge(student.agreement_status || 'None')}</strong>
                            </div>
                            <div class="student-record-metric">
                                <span>Enrollment</span>
                                <strong>${getAdminStatusBadge(student.enrollment_status || 'None')}</strong>
                            </div>
                        </div>
                    </div>
                    <div class="student-record-summary-grid">
                        ${adminRecordMetric('Year Level', escapeHtml(student.year_level || 'N/A'))}
                        ${adminRecordMetric('Section', escapeHtml(student.section || 'N/A'))}
                        ${adminRecordMetric('Completed Hours', `${escapeHtml(student.completed_hours || 0)} / 300`)}
                        ${adminRecordMetric('Last Login', record.login_history && record.login_history[0] ? formatAdminDateTime(record.login_history[0].login_at) : 'No history yet')}
                    </div>
                </div>

                <div class="accordion" id="studentRecordAccordion">
                    <div class="accordion-item student-record-section">
                        <h2 class="accordion-header" id="studentRecordHeadingEnrollment">
                            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#studentRecordCollapseEnrollment" aria-expanded="true" aria-controls="studentRecordCollapseEnrollment">
                                Editable Enrollment Information
                            </button>
                        </h2>
                        <div id="studentRecordCollapseEnrollment" class="accordion-collapse collapse show" aria-labelledby="studentRecordHeadingEnrollment" data-bs-parent="#studentRecordAccordion">
                            <div class="accordion-body">
                                ${renderEnrollmentEditor(enrollment)}
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item student-record-section">
                        <h2 class="accordion-header" id="studentRecordHeadingHistory">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#studentRecordCollapseHistory" aria-expanded="false" aria-controls="studentRecordCollapseHistory">
                                Login History
                            </button>
                        </h2>
                        <div id="studentRecordCollapseHistory" class="accordion-collapse collapse" aria-labelledby="studentRecordHeadingHistory" data-bs-parent="#studentRecordAccordion">
                            <div class="accordion-body">
                                ${renderLoginHistory(record.login_history)}
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item student-record-section">
                        <h2 class="accordion-header" id="studentRecordHeadingMessages">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#studentRecordCollapseMessages" aria-expanded="false" aria-controls="studentRecordCollapseMessages">
                                Direct Message
                            </button>
                        </h2>
                        <div id="studentRecordCollapseMessages" class="accordion-collapse collapse" aria-labelledby="studentRecordHeadingMessages" data-bs-parent="#studentRecordAccordion">
                            <div class="accordion-body">
                                <form id="studentDirectMessageForm" class="mb-4">
                                    <input type="hidden" name="student_id" value="${escapeHtml(student.stud_id)}">
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Subject</label>
                                        <input type="text" class="form-control" name="subject" maxlength="255" placeholder="Enter a private subject" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Message</label>
                                        <textarea class="form-control" name="message" rows="4" placeholder="Write a private message to the student" required></textarea>
                                    </div>
                                    <div class="d-flex justify-content-end">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-paper-plane me-2"></i>Send Direct Message
                                        </button>
                                    </div>
                                </form>
                                <div>
                                    <h6 class="fw-bold mb-3">Recent Messages</h6>
                                    ${renderMessageHistory(record.recent_messages)}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item student-record-section">
                        <h2 class="accordion-header" id="studentRecordHeadingPhotos">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#studentRecordCollapsePhotos" aria-expanded="false" aria-controls="studentRecordCollapsePhotos">
                                Submitted Photos and Reports
                            </button>
                        </h2>
                        <div id="studentRecordCollapsePhotos" class="accordion-collapse collapse" aria-labelledby="studentRecordHeadingPhotos" data-bs-parent="#studentRecordAccordion">
                            <div class="accordion-body">
                                ${renderSubmissionPhotos(record.recent_reports)}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;

        const enrollmentForm = document.getElementById('studentEnrollmentEditForm');
        if (enrollmentForm) {
            enrollmentForm.addEventListener('submit', handleStudentEnrollmentSave);
        }

        const directMessageForm = document.getElementById('studentDirectMessageForm');
        if (directMessageForm) {
            directMessageForm.addEventListener('submit', handleStudentDirectMessage);
        }

        if (resetButton) {
            resetButton.onclick = handleStudentPasswordReset;
        }
    }

    async function openStudentRecord(studentId) {
        const modal = getStudentRecordModalInstance();
        const modalBody = document.getElementById('studentRecordBody');
        const modalTitle = document.getElementById('studentRecordModalTitle');
        const modalSubtitle = document.getElementById('studentRecordModalSubtitle');
        if (!modal || !modalBody) return;

        activeStudentRecord = null;
        if (modalTitle) modalTitle.textContent = 'Student Record';
        if (modalSubtitle) modalSubtitle.textContent = 'Loading student details...';
        modalBody.innerHTML = '<div class="text-center py-5 text-muted"><div class="spinner-border text-primary mb-3" role="status"></div><div>Loading student record...</div></div>';
        modal.show();

        const formData = new FormData();
        formData.append('action', 'get_student_details');
        formData.append('student_id', String(studentId));

        try {
            const data = await adminPostAction(formData);
            activeStudentRecord = data;
            renderStudentRecord(data);
        } catch (error) {
            if (modalTitle) modalTitle.textContent = 'Student Record';
            if (modalSubtitle) modalSubtitle.textContent = 'Unable to load student details';
            modalBody.innerHTML = `<div class="alert alert-danger mb-0">${escapeHtml(error.message || 'Unable to load the student record.')}</div>`;
        }
    }

    async function handleStudentEnrollmentSave(event) {
        event.preventDefault();
        const form = event.currentTarget;
        if (!form) return;

        const submitButton = form.querySelector('button[type="submit"]');
        if (submitButton) submitButton.disabled = true;

        const formData = new FormData(form);
        formData.append('action', 'update_student_record');

        try {
            const data = await adminPostAction(formData);
            alert(data.message);
            window.location.reload();
        } catch (error) {
            alert(error.message || 'Unable to save the enrollment changes.');
        } finally {
            if (submitButton) submitButton.disabled = false;
        }
    }

    async function handleStudentPasswordReset() {
        const studentId = activeStudentRecord && activeStudentRecord.student ? activeStudentRecord.student.stud_id : null;
        if (!studentId) return;

        if (!confirm('Reset this student\'s password and generate a temporary password?')) {
            return;
        }

        const formData = new FormData();
        formData.append('action', 'reset_student_password');
        formData.append('student_id', String(studentId));

        try {
            const data = await adminPostAction(formData);
            alert(`${data.message}\nTemporary password: ${data.temporary_password}\n${data.email_status}`);
        } catch (error) {
            alert(error.message || 'Unable to reset the student password.');
        }
    }

    async function handleStudentDirectMessage(event) {
        event.preventDefault();
        const form = event.currentTarget;
        if (!form) return;

        const submitButton = form.querySelector('button[type="submit"]');
        if (submitButton) submitButton.disabled = true;

        const formData = new FormData(form);
        formData.append('action', 'send_direct_message');

        try {
            const data = await adminPostAction(formData);
            form.reset();
            if (activeStudentRecord) {
                const existingMessages = Array.isArray(activeStudentRecord.recent_messages) ? activeStudentRecord.recent_messages : [];
                activeStudentRecord.recent_messages = [data.saved_message].concat(existingMessages).slice(0, 10);
                renderStudentRecord(activeStudentRecord);
            }
            alert(`${data.message}\n${data.email_status}`);
        } catch (error) {
            alert(error.message || 'Unable to send the direct message.');
        } finally {
            if (submitButton) submitButton.disabled = false;
        }
    }

    function renderAdminGreeting() {
        const greetingNode = document.getElementById('admin-greeting-label');
        if (!greetingNode) return;

        const currentHour = new Date().getHours();
        let greeting = 'Welcome back';

        if (currentHour < 12) {
            greeting = 'Good Morning';
        } else if (currentHour < 18) {
            greeting = 'Good Afternoon';
        } else {
            greeting = 'Good Evening';
        }

        greetingNode.textContent = greeting;
    }

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

    function findAdminStudent(studentId) {
        const departments = adminDepartments || {};

        for (const deptId of Object.keys(departments)) {
            const dept = departments[deptId];
            const sections = dept.sections || {};

            for (const sectionLabel of Object.keys(sections)) {
                const students = sections[sectionLabel] || [];
                const student = students.find((entry) => String(entry.stud_id) === String(studentId));

                if (student) {
                    return { deptId, section: sectionLabel, student };
                }
            }
        }

        return null;
    }

    function getAdminStatusBadge(status) {
        if (!status || status === 'None') {
            return '<span class="badge bg-secondary opacity-50">Not Submitted</span>';
        }

        if (status === 'Verified') {
            return '<span class="badge bg-success">Verified</span>';
        }

        if (status === 'Rejected') {
            return '<span class="badge bg-danger">Rejected</span>';
        }

        return '<span class="badge bg-warning text-dark">Pending</span>';
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

    function openAdminNotification(notificationId) {
        const notification = (adminNotifications || []).find((item) => String(item.id) === String(notificationId));
        if (!notification) return;

        const match = findAdminStudent(notification.student_id);
        if (!match) {
            showView('departments');
            return;
        }

        if (notification.type === 'enrollment' && match.student && match.student.enrollment_id) {
            window.location.href = 'verify_enrollment.php?id=' + encodeURIComponent(match.student.enrollment_id);
            return;
        }

        openStudentRecord(notification.student_id);
    }
    
    function renderNotifications() {
        const list = document.getElementById('notifications-list');
        if (!list) return;
        
        if (adminNotifications.length === 0) {
            list.innerHTML = '<div class="list-group-item text-center text-muted">No notifications found.</div>';
            return;
        }
        
        list.innerHTML = adminNotifications.map(n => {
            const icon = n.type === 'waiver'
                ? 'fa-file-signature'
                : (n.type === 'agreement' ? 'fa-file-contract' : 'fa-user-check');
            const actionText = n.type === 'enrollment' ? 'Open enrollment review' : 'Open student record';

            return `
                <button type="button" class="list-group-item list-group-item-action ${!n.is_read ? 'bg-light' : ''} text-start" onclick="openAdminNotification(${n.id})">
                    <div class="d-flex w-100 justify-content-between align-items-start gap-3">
                        <div class="d-flex align-items-start gap-3">
                            <span class="text-primary fs-5 mt-1"><i class="fas ${icon}"></i></span>
                            <div>
                                <h5 class="mb-1">${n.type.charAt(0).toUpperCase() + n.type.slice(1)} Notification</h5>
                                <p class="mb-1">${n.message}</p>
                                <small class="text-primary fw-semibold">${actionText}</small>
                            </div>
                        </div>
                        <small class="text-muted text-nowrap">${new Date(n.created_at).toLocaleDateString()}</small>
                    </div>
                </button>
            `;
        }).join('');
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

            let actionBtn = '<button type="button" class="btn btn-sm btn-primary" onclick="openStudentRecord(' + s.stud_id + ')"><i class="fas fa-id-card me-1"></i> Open Record</button>';
            if (s.enrollment_id) {
                actionBtn += '<a href="verify_enrollment.php?id=' + s.enrollment_id + '" class="btn btn-sm btn-outline-primary ms-2">View Enrollment</a>';
            }

            rows +=
                '<tr data-student-id="' + s.stud_id + '">' +
                    '<td>' +
                        '<div class="fw-semibold">' + s.name + '</div>' +
                        '<div class="small text-muted"><span class="me-2">' + s.email + '</span></div>' +
                        '<div class="small text-muted">Student No. ' + (s.student_number || 'Not set') + ' - Year ' + s.year_level + '</div>' +
                    '</td>' +
                    '<td>' + getAdminStatusBadge(s.waiver_status) + '</td>' +
                    '<td>' + getAdminStatusBadge(s.agreement_status) + '</td>' +
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
                                    '<th>Waiver</th>' +
                                    '<th>Agreement</th>' +
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
<!-- Student Record Modal -->
<div class="modal fade" id="studentRecordModal" tabindex="-1" aria-labelledby="studentRecordModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="studentRecordModalTitle">Student Record</h5>
                    <div class="small text-muted" id="studentRecordModalSubtitle">Loading student details...</div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <a href="#" id="studentRecordEnrollmentLink" class="btn btn-outline-primary btn-sm d-none" target="_blank" rel="noopener noreferrer">
                        <i class="fas fa-file-alt me-1"></i>View Enrollment
                    </a>
                    <button type="button" class="btn btn-outline-warning btn-sm" id="studentResetPasswordBtn">
                        <i class="fas fa-key me-1"></i>Reset Password
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>
            <div class="modal-body" id="studentRecordBody">
                <div class="text-center py-5 text-muted">
                    Select a student to view the full record.
                </div>
            </div>
        </div>
    </div>
</div>
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
    renderAdminGreeting();

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
