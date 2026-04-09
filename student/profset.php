<?php
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

$success_message = '';
$error_message = '';

// Handle form submission for editable fields only
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $year_section = $_POST['year_section'] ?? '';
    $course = $_POST['course'] ?? '';
    $semester = $_POST['semester'] ?? '';
    
    // Update student account table
    $update_account_sql = "UPDATE students SET year_level = ?, course = ? WHERE stud_id = ?";
    $stmt = $conn->prepare($update_account_sql);
    $stmt->bind_param("ssi", $year_section, $course, $student_id);
    
    if ($stmt->execute()) {
        // Also update enrollment table if exists
        $update_enrollment_sql = "UPDATE rss_enrollments SET year_section = ?, course = ? WHERE student_id = ?";
        $stmt2 = $conn->prepare($update_enrollment_sql);
        $stmt2->bind_param("ssi", $year_section, $course, $student_id);
        $stmt2->execute();
        $stmt2->close();
        
        $success_message = "✅ Profile updated successfully!";
    } else {
        $error_message = "❌ Error updating profile: " . $stmt->error;
    }
    $stmt->close();
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Verify current password
    $stmt = $conn->prepare("SELECT password FROM students WHERE stud_id = ?");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if ($user && password_verify($current_password, $user['password'])) {
        if ($new_password === $confirm_password) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $update_stmt = $conn->prepare("UPDATE students SET password = ? WHERE stud_id = ?");
            $update_stmt->bind_param("si", $hashed_password, $student_id);
            if ($update_stmt->execute()) {
                $success_message = "✅ Password changed successfully!";
            } else {
                $error_message = "❌ Error updating password.";
            }
            $update_stmt->close();
        } else {
            $error_message = "❌ New passwords do not match.";
        }
    } else {
        $error_message = "❌ Current password is incorrect.";
    }
}

// Get student account information
$student_sql = "SELECT * FROM students WHERE stud_id = ?";
$stmt = $conn->prepare($student_sql);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student_account = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Get enrollment data if exists
$enrollment_sql = "SELECT * FROM rss_enrollments WHERE student_id = ? ORDER BY submission_date DESC LIMIT 1";
$stmt = $conn->prepare($enrollment_sql);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$enrollment = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Merge data from enrollment and account (enrollment takes priority)
$profile_data = [
    'surname' => $enrollment['surname'] ?? $student_account['lastname'] ?? '',
    'given_name' => $enrollment['given_name'] ?? $student_account['firstname'] ?? '',
    'middle_name' => $enrollment['middle_name'] ?? $student_account['middlename'] ?? '',
    'student_number' => $enrollment['student_number'] ?? $student_account['student_id_number'] ?? '',
    'email_address' => $enrollment['email_address'] ?? $student_account['email'] ?? '',
    'contact_number' => $enrollment['contact_number'] ?? $student_account['contact_number'] ?? '',
    'gender' => $enrollment['gender'] ?? $student_account['gender'] ?? '',
    'birth_date' => $enrollment['birth_date'] ?? '',
    'birth_place' => $enrollment['birth_place'] ?? '',
    'city_address' => $enrollment['city_address'] ?? '',
    'provincial_address' => $enrollment['provincial_address'] ?? '',
    'religion' => $enrollment['religion'] ?? '',
    'marital_status' => $enrollment['marital_status'] ?? '',
    'course' => $enrollment['course'] ?? $student_account['course'] ?? '',
    'major' => $enrollment['major'] ?? '',
    'year_section' => $enrollment['year_section'] ?? $student_account['year_level'] ?? '',
    'college' => $enrollment['college'] ?? '',
    'photo_path' => $enrollment['photo_path'] ?? '',
    
    // Family Data
    'father_name' => $enrollment['father_name'] ?? '',
    'father_occupation' => $enrollment['father_occupation'] ?? '',
    'father_contact' => $enrollment['father_contact'] ?? '',
    'mother_name' => $enrollment['mother_name'] ?? '',
    'mother_occupation' => $enrollment['mother_occupation'] ?? '',
    'mother_contact' => $enrollment['mother_contact'] ?? '',
    'guardian_name' => $enrollment['guardian_name'] ?? '',
    'guardian_contact' => $enrollment['guardian_contact'] ?? '',
    
    // Health Data
    'height' => $enrollment['height'] ?? '',
    'weight' => $enrollment['weight'] ?? '',
    'blood_type' => $enrollment['blood_type'] ?? '',
    'health_problem' => $enrollment['health_problem'] ?? '',
    'vaccination_status' => $enrollment['vaccination_status'] ?? '',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile Settings</title>
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
        .profile-container { 
            background: white; 
            border-radius: 15px; 
            padding: 30px; 
            max-width: 1200px; 
            margin: 0 auto; 
            box-shadow: 0 15px 35px rgba(26, 79, 122, 0.1); 
            border: 1px solid rgba(255, 255, 255, 0.5);
        }
        .profile-header {
            background: linear-gradient(90deg, var(--secondary-color), var(--primary-color));
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        .section-title { 
            background: linear-gradient(90deg, var(--secondary-color), var(--primary-color));
            color: white; 
            padding: 10px 15px; 
            border-radius: 8px; 
            margin: 20px 0 15px 0; 
            font-weight: 600; 
            letter-spacing: 0.5px;
        }
        .profile-photo {
            width: 150px;
            height: 150px;
            object-fit: cover;
            border-radius: 10px;
            border: 3px solid var(--primary-color);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .info-label {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 5px;
        }
        .info-value {
            padding: 10px;
            background: #f8f9fa;
            border-radius: 5px;
            margin-bottom: 15px;
            min-height: 40px;
            border: 1px solid #e9ecef;
        }
        .editable-field {
            background: #fff3cd !important;
            border: 2px solid #ffc107;
        }
        .edit-badge {
            background: var(--accent-color);
            color: white;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 12px;
            margin-left: 10px;
        }
        .btn-custom { 
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); 
            color: white; 
            border: none; 
            transition: all 0.3s ease;
        }
        .btn-custom:hover { 
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(26, 79, 122, 0.3);
            color: white; 
        }
        .btn-secondary-custom {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.3);
            padding: 8px 20px;
            border-radius: 8px;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }

        .btn-secondary-custom:hover {
            background: rgba(255, 255, 255, 0.3);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        /* Password Toggle Styles */
        .password-container {
            position: relative;
            width: 100%;
        }

        .password-container input {
            padding-right: 40px !important;
            box-sizing: border-box;
        }

        .password-toggle-icon {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #666;
            z-index: 10;
            font-size: 1rem;
        }
    </style>
</head>
<body>
<div class="profile-container">
    <div class="profile-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <div>
                <h3 class="mb-0">Student Profile Settings</h3>
                <p class="mb-0"><small>View and manage your profile information</small></p>
            </div>
            <div>
               <?php
        // Dynamic dashboard URL for students
        $departments = [
            1 => "College of Education",
            2 => "College of Technology",
            3 => "College of Hospitality and Tourism Management"
        ];
        $dept_code = strtolower(str_replace(" ", "_", $departments[$_SESSION['department_id']]));
        $dashboard_url = "student_{$dept_code}_dashboard.php";  // Adjust folder if needed, e.g., "student_folder/student_{$dept_code}_dashboard.php"
        ?>
        <a href="<?= htmlspecialchars($dashboard_url) ?>" class="btn btn-secondary-custom">
            <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
        </a>
            </div>
        </div>
    </div>
    
    <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= $success_message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?= $error_message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if (!$enrollment): ?>
        <div class="alert alert-info">
            <strong>ℹ️ Notice:</strong> You haven't completed your RSS enrollment form yet. Some profile information may be incomplete. Please complete your <a href="rss_enrollment_form.php">enrollment form</a> to populate all fields.
        </div>
    <?php endif; ?>
    
    <form method="POST">
        <!-- Personal Information -->
        <div class="section-title">Personal Information</div>
        
        <div class="row mb-3">
            <?php if ($profile_data['photo_path'] && file_exists($profile_data['photo_path'])): ?>
                <div class="col-md-12 text-center mb-3">
                    <img src="<?= htmlspecialchars($profile_data['photo_path']) ?>" alt="Profile Photo" class="profile-photo">
                </div>
            <?php endif; ?>
        </div>
        
        <div class="row">
            <div class="col-md-4">
                <div class="info-label">Surname</div>
                <div class="info-value"><?= htmlspecialchars($profile_data['surname']) ?: 'N/A' ?></div>
            </div>
            <div class="col-md-4">
                <div class="info-label">Given Name</div>
                <div class="info-value"><?= htmlspecialchars($profile_data['given_name']) ?: 'N/A' ?></div>
            </div>
            <div class="col-md-4">
                <div class="info-label">Middle Name</div>
                <div class="info-value"><?= htmlspecialchars($profile_data['middle_name']) ?: 'N/A' ?></div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="info-label">Student ID Number</div>
                <div class="info-value"><?= htmlspecialchars($profile_data['student_number']) ?: 'N/A' ?></div>
            </div>
            <div class="col-md-6">
                <div class="info-label">Email Address</div>
                <div class="info-value"><?= htmlspecialchars($profile_data['email_address']) ?: 'N/A' ?></div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-4">
                <div class="info-label">Gender</div>
                <div class="info-value"><?= htmlspecialchars($profile_data['gender']) ?: 'N/A' ?></div>
            </div>
            <div class="col-md-4">
                <div class="info-label">Contact Number</div>
                <div class="info-value"><?= htmlspecialchars($profile_data['contact_number']) ?: 'N/A' ?></div>
            </div>
            <div class="col-md-4">
                <div class="info-label">Birth Date</div>
                <div class="info-value"><?= htmlspecialchars($profile_data['birth_date']) ?: 'N/A' ?></div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="info-label">Birth Place</div>
                <div class="info-value"><?= htmlspecialchars($profile_data['birth_place']) ?: 'N/A' ?></div>
            </div>
            <div class="col-md-6">
                <div class="info-label">Religion</div>
                <div class="info-value"><?= htmlspecialchars($profile_data['religion']) ?: 'N/A' ?></div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-12">
                <div class="info-label">City Address</div>
                <div class="info-value"><?= htmlspecialchars($profile_data['city_address']) ?: 'N/A' ?></div>
            </div>
        </div>
        
        <!-- Academic Information (Editable) -->
        <div class="section-title">Academic Information</div>
        
        <div class="row">
            <div class="col-md-4">
                <div class="info-label">College</div>
                <div class="info-value"><?= htmlspecialchars($profile_data['college']) ?: 'N/A' ?></div>
            </div>
            <div class="col-md-4">
                <label class="info-label">Course</label>
                <div class ="info-value"><?= htmlspecialchars($profile_data['course'])? : 'N/A' ?></div>
            </div>
            <div class="col-md-4">
                <label class="info-label">Year & Section</label>
                <div class="info-value"><?= htmlspecialchars($profile_data['year_section']) ?: 'N/A' ?></div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-12">
                <div class="info-label">Major/Specialization</div>
                <div class="info-value"><?= htmlspecialchars($profile_data['major']) ?: 'N/A' ?></div>
            </div>
        </div>
        
        <!-- Family Information -->
        <div class="section-title">Family Information</div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="info-label">Father's Name</div>
                <div class="info-value"><?= htmlspecialchars($profile_data['father_name']) ?: 'N/A' ?></div>
            </div>
            <div class="col-md-6">
                <div class="info-label">Mother's Name</div>
                <div class="info-value"><?= htmlspecialchars($profile_data['mother_name']) ?: 'N/A' ?></div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="info-label">Father's Occupation</div>
                <div class="info-value"><?= htmlspecialchars($profile_data['father_occupation']) ?: 'N/A' ?></div>
            </div>
            <div class="col-md-6">
                <div class="info-label">Mother's Occupation</div>
                <div class="info-value"><?= htmlspecialchars($profile_data['mother_occupation']) ?: 'N/A' ?></div>
            </div>
        </div>
        
        <!-- Health Information -->
        <div class="section-title">Health Information</div>
        
        <div class="row">
            <div class="col-md-3">
                <div class="info-label">Height</div>
                <div class="info-value"><?= htmlspecialchars($profile_data['height']) ?: 'N/A' ?></div>
            </div>
            <div class="col-md-3">
                <div class="info-label">Weight</div>
                <div class="info-value"><?= htmlspecialchars($profile_data['weight']) ?: 'N/A' ?></div>
            </div>
            <div class="col-md-3">
                <div class="info-label">Blood Type</div>
                <div class="info-value"><?= htmlspecialchars($profile_data['blood_type']) ?: 'N/A' ?></div>
            </div>
            <div class="col-md-3">
                <div class="info-label">Vaccination Status</div>
                <div class="info-value"><?= htmlspecialchars($profile_data['vaccination_status']) ?: 'N/A' ?></div>
            </div>
        </div>
        
        <div class="alert alert-warning mt-4">
            <strong>⚠️ Note:</strong> Only <strong>Course</strong> and <strong>Year & Section</strong> can be edited. To update other information, please contact the administrator or update your <a href="enrollment_update.php">RSS enrollment form</a>.
        </div>
        
        <div class="text-center mt-4 mb-5">
            <button type="submit" class="btn btn-custom btn-lg px-5">
                💾 Save Profile Changes
            </button>
        </div>
    </form>

    <!-- Change Password Section -->
    <div class="section-title">Change Password</div>
    <form method="POST" class="mt-3">
        <input type="hidden" name="change_password" value="1">
        <div class="row">
            <div class="col-md-4">
                <label class="info-label">Current Password</label>
                <div class="password-container">
                    <input type="password" name="current_password" class="form-control mb-3" required>
                    <i class="fas fa-eye-slash password-toggle-icon" onclick="togglePasswordVisibility(this, 'current_password')"></i>
                </div>
            </div>
            <div class="col-md-4">
                <label class="info-label">New Password</label>
                <div class="password-container">
                    <input type="password" id="new_password_change" name="new_password" class="form-control mb-3" required>
                    <i class="fas fa-eye-slash password-toggle-icon" onclick="togglePasswordVisibility(this, 'new_password_change')"></i>
                </div>
            </div>
            <div class="col-md-4">
                <label class="info-label">Confirm New Password</label>
                <div class="password-container">
                    <input type="password" id="confirm_password_change" name="confirm_password" class="form-control mb-3" required>
                    <i class="fas fa-eye-slash password-toggle-icon" onclick="togglePasswordVisibility(this, 'confirm_password_change')"></i>
                </div>
            </div>
        </div>
        <div class="text-center mt-3">
            <button type="submit" class="btn btn-warning btn-lg px-5">
                🔑 Change Password
            </button>
        </div>
    </form>
</div>

<script>
function togglePasswordVisibility(icon, fieldId) {
    var field = document.getElementById(fieldId) || document.getElementsByName(fieldId)[0];
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
</body>
</html>