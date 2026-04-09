<?php
session_start();
include "dbconnect.php";
require_once "../send_email.php";

// Only allow Administrator
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Administrator') {
    header("Location: ../home2.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role = $_POST['role'];
    $firstname = trim($_POST['firstname']);
    $lastname = trim($_POST['lastname']);
    $email = trim($_POST['email']);
    $department_id = intval($_POST['department']);
    
    // Validation
    if (empty($firstname) || empty($lastname) || empty($email) || empty($role)) {
        $_SESSION['flash_error'] = "All fields are required.";
        header("Location: admin_dashboard.php");
        exit;
    }

    // Generate Random Password
    $raw_password = substr(str_shuffle("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"), 0, 10);
    $hashed_password = password_hash($raw_password, PASSWORD_DEFAULT);
    
    $table = '';
    if ($role === 'Instructor') {
        $table = 'instructors';
    } elseif ($role === 'Coordinator') {
        $table = 'coordinator';
    } else {
        $_SESSION['flash_error'] = "Invalid role selected.";
        header("Location: admin_dashboard.php");
        exit;
    }

    // Check if email exists
    $check = $conn->prepare("SELECT email FROM $table WHERE email = ?");
    $check->bind_param("s", $email);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        $_SESSION['flash_error'] = "Email already exists.";
        header("Location: admin_dashboard.php");
        exit;
    }

    // Insert
    // Note: Adjust column names if they differ. Based on home2.php, columns seem standard.
    // However, I should double check column names for `coordinator` and `instructors`.
    // home2.php used `SELECT *`, so I assume firstname, lastname, email, password, department_id exist.
    
    $stmt = $conn->prepare("INSERT INTO $table (firstname, lastname, email, password, department_id) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssi", $firstname, $lastname, $email, $hashed_password, $department_id);
    
    if ($stmt->execute()) {
        // Send Email using PHPMailer
        $subject = "RServe Account Credentials";
        $message = "Hello $firstname,\n\nYour account has been created.\n\nRole: $role\nEmail: $email\nPassword: $raw_password\n\nPlease login and change your password immediately.";
        
        $result = sendEmail($email, "$firstname $lastname", $subject, $message);

        if ($result === true) {
            $_SESSION['flash_success'] = "Account created for $firstname $lastname ($role). Credentials sent to $email.";
        } else {
             $errorMsg = $result;
             if (strpos($errorMsg, '535') !== false || strpos($errorMsg, 'Username and Password not accepted') !== false || strpos($errorMsg, 'Could not authenticate') !== false) {
                $errorMsg .= "<br><br><strong>GMAIL CONFIGURATION HELP:</strong><br>
                1. Ensure 2-Step Verification is ENABLED on your Google Account.<br>
                2. You MUST use an <strong>App Password</strong>, not your regular Gmail password.<br>
                3. Generate one at: <a href='https://myaccount.google.com/apppasswords' target='_blank'>https://myaccount.google.com/apppasswords</a>";
            }
            $_SESSION['flash_error'] = "Account created, but email failed: " . $errorMsg;
        }
    } else {
        $_SESSION['flash_error'] = "Database error: " . $conn->error;
    }
    
    header("Location: admin_dashboard.php");
    exit;
}
