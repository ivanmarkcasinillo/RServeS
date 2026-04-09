<?php
session_start();
include "dbconnect.php";

// ✅ Ensure user is logged in as Student
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Student') {
    header("Location: ../home2.php");
    exit;
}

// ✅ Handle photo upload
if (isset($_FILES['profilePhoto']) && $_FILES['profilePhoto']['error'] === UPLOAD_ERR_OK) {
    $fileTmpPath   = $_FILES['profilePhoto']['tmp_name'];
    $fileName      = $_FILES['profilePhoto']['name'];
    $fileNameCmps  = explode(".", $fileName);
    $fileExtension = strtolower(end($fileNameCmps));

    $allowedExts = ['jpg', 'jpeg', 'png', 'gif'];

    if (in_array($fileExtension, $allowedExts)) {
        // Generate a new unique filename based on the user’s email and current time
        $newFileName    = $_SESSION['email'] . "_" . time() . "." . $fileExtension;
        $uploadFileDir  = 'uploads/profile_photos/';
        if (!is_dir($uploadFileDir)) {
            mkdir($uploadFileDir, 0755, true);
        }
        $destPath = $uploadFileDir . $newFileName;

        // Move uploaded file and update database
        if (move_uploaded_file($fileTmpPath, $destPath)) {
            $stmt = $conn->prepare("UPDATE students SET photo = ? WHERE email = ?");
            $stmt->bind_param("ss", $destPath, $_SESSION['email']);
            $stmt->execute();
            $stmt->close();

            // Update session variable so the new photo shows immediately
            $_SESSION['photo'] = $destPath;
        }
    }
}

// ✅ Redirect back to dashboard or referring page
$redirect = !empty($_SERVER['HTTP_REFERER']) 
    ? $_SERVER['HTTP_REFERER'] 
    : 'student_college_of_technology_dashboard.php';
header("Location: $redirect");
exit;
?>
