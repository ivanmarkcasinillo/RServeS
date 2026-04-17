<?php
session_start();
require "dbconnect.php";

function getStudentDashboardFile($department_id) {
    $departments = [
        1 => "College of Education",
        2 => "College of Technology",
        3 => "College of Hospitality and Tourism Management",
    ];

    $dept_name = $departments[(int) $department_id] ?? "College of Technology";
    $dept_code = strtolower(str_replace(' ', '_', $dept_name));

    return "student_{$dept_code}_dashboard.php";
}

if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'Student') {
    header("Location: ../home2.php");
    exit;
}

$student_id = $_SESSION['stud_id'] ?? null;
if (!$student_id) {
    die("Student ID not found in session.");
}

$student_stmt = $conn->prepare("SELECT firstname, lastname, email, department_id FROM students WHERE stud_id = ?");
$student_stmt->bind_param("i", $student_id);
$student_stmt->execute();
$student = $student_stmt->get_result()->fetch_assoc();
$student_stmt->close();

$dashboard_file = getStudentDashboardFile($_SESSION['department_id'] ?? ($student['department_id'] ?? 2));
$success_message = $_SESSION['password_success'] ?? '';
$error_message = $_SESSION['password_error'] ?? '';
unset($_SESSION['password_success'], $_SESSION['password_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($current_password === '' || $new_password === '' || $confirm_password === '') {
        $_SESSION['password_error'] = "All password fields are required.";
    } else {
        $password_stmt = $conn->prepare("SELECT password FROM students WHERE stud_id = ?");
        $password_stmt->bind_param("i", $student_id);
        $password_stmt->execute();
        $user = $password_stmt->get_result()->fetch_assoc();
        $password_stmt->close();

        if (!$user || !password_verify($current_password, $user['password'])) {
            $_SESSION['password_error'] = "Current password is incorrect.";
        } elseif ($new_password !== $confirm_password) {
            $_SESSION['password_error'] = "New passwords do not match.";
        } else {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $update_stmt = $conn->prepare("UPDATE students SET password = ? WHERE stud_id = ?");
            $update_stmt->bind_param("si", $hashed_password, $student_id);

            if ($update_stmt->execute()) {
                $_SESSION['password_success'] = "Password changed successfully.";
            } else {
                $_SESSION['password_error'] = "Unable to update your password right now.";
            }

            $update_stmt->close();
        }
    }

    header("Location: change_password.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password</title>
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

        .password-container {
            background: white;
            border-radius: 15px;
            padding: 30px;
            max-width: 760px;
            margin: 0 auto;
            box-shadow: 0 15px 35px rgba(26, 79, 122, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.5);
        }

        .password-header {
            background: linear-gradient(90deg, var(--secondary-color), var(--primary-color));
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
        }

        .btn-custom {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border: none;
        }

        .btn-custom:hover {
            color: white;
            transform: translateY(-1px);
            box-shadow: 0 5px 15px rgba(26, 79, 122, 0.25);
        }

        .password-field {
            position: relative;
        }

        .password-field input {
            padding-right: 44px;
        }

        .password-toggle {
            position: absolute;
            top: 50%;
            right: 14px;
            transform: translateY(-50%);
            cursor: pointer;
            color: #6c757d;
        }
    </style>
</head>
<body>
<div class="password-container">
    <div class="password-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h3 class="mb-1">Change Password</h3>
            <p class="mb-0"><small>Update your student account password separately from enrollment details.</small></p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="enrollment_update.php" class="btn btn-light btn-sm">Update Enrollment</a>
            <a href="<?= htmlspecialchars($dashboard_file) ?>" class="btn btn-outline-light btn-sm">Back to Dashboard</a>
        </div>
    </div>

    <div class="mb-4">
        <h5 class="mb-1"><?= htmlspecialchars(trim(($student['firstname'] ?? '') . ' ' . ($student['lastname'] ?? ''))) ?></h5>
        <p class="text-muted mb-0"><?= htmlspecialchars($student['email'] ?? '') ?></p>
    </div>

    <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($success_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($error_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <form method="POST">
        <div class="mb-3 password-field">
            <label class="form-label fw-semibold">Current Password</label>
            <input type="password" id="current_password" name="current_password" class="form-control" required>
            <i class="fas fa-eye-slash password-toggle" data-target="current_password"></i>
        </div>

        <div class="mb-3 password-field">
            <label class="form-label fw-semibold">New Password</label>
            <input type="password" id="new_password" name="new_password" class="form-control" required>
            <i class="fas fa-eye-slash password-toggle" data-target="new_password"></i>
        </div>

        <div class="mb-4 password-field">
            <label class="form-label fw-semibold">Confirm New Password</label>
            <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
            <i class="fas fa-eye-slash password-toggle" data-target="confirm_password"></i>
        </div>

        <div class="d-flex flex-wrap gap-2 justify-content-center">
            <button type="reset" class="btn btn-outline-secondary">Reset Form</button>
            <button type="submit" class="btn btn-custom px-4">Change Password</button>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.password-toggle').forEach(function(toggle) {
    toggle.addEventListener('click', function() {
        const field = document.getElementById(toggle.dataset.target);
        const isPassword = field.type === 'password';
        field.type = isPassword ? 'text' : 'password';
        toggle.classList.toggle('fa-eye-slash', !isPassword);
        toggle.classList.toggle('fa-eye', isPassword);
    });
});
</script>
</body>
</html>
