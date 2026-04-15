<?php
session_start();
require "../dbconnect.php";
require_once __DIR__ . "/../../send_email.php";

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Student') {
    header("Location: ../../home2.php");
    exit;
}

$student_id = $_SESSION['stud_id'];
$student_stmt = $conn->prepare("SELECT firstname, lastname, student_number, department_id FROM students WHERE stud_id = ? LIMIT 1");
$student_stmt->bind_param("i", $student_id);
$student_stmt->execute();
$student = $student_stmt->get_result()->fetch_assoc() ?: [];
$student_stmt->close();
$student_name = trim(((string) ($student['firstname'] ?? '')) . ' ' . ((string) ($student['lastname'] ?? '')));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['waiver_file'])) {
    $file = $_FILES['waiver_file'];
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
        
        if (in_array($ext, $allowed)) {
            // Ensure table exists (safe check)
            $conn->query("CREATE TABLE IF NOT EXISTS rss_waivers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                file_path VARCHAR(255) NOT NULL,
                status ENUM('Pending', 'Verified', 'Rejected') DEFAULT 'Pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                verified_at TIMESTAMP NULL,
                verified_by INT NULL,
                FOREIGN KEY (student_id) REFERENCES students(stud_id) ON DELETE CASCADE
            )");

            $uploadDir = __DIR__ . '/../../uploads/waivers/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $newName = 'waiver_' . $student_id . '_' . time() . '.' . $ext;
            $dest = $uploadDir . $newName;
            $relPath = 'uploads/waivers/' . $newName;
            
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                // Check if exists
                $check = $conn->prepare("SELECT id FROM rss_waivers WHERE student_id = ?");
                $check->bind_param("i", $student_id);
                $check->execute();
                $existing = $check->get_result()->fetch_assoc();
                $check->close();
                
                if ($existing) {
                    $stmt = $conn->prepare("UPDATE rss_waivers SET file_path = ?, status = 'Pending' WHERE student_id = ?");
                    $stmt->bind_param("si", $relPath, $student_id);
                } else {
                    $stmt = $conn->prepare("INSERT INTO rss_waivers (student_id, file_path, status) VALUES (?, ?, 'Pending')");
                    $stmt->bind_param("is", $student_id, $relPath);
                }
                
                if ($stmt->execute()) {
                    $recipients = array_merge(
                        rserves_fetch_admin_email_recipients($conn),
                        rserves_fetch_coordinator_email_recipients($conn, intval($student['department_id'] ?? 0))
                    );
                    $body = rserves_notification_build_body(
                        'there',
                        "{$student_name} submitted a waiver for verification.",
                        [
                            'Student ID' => (string) ($student['student_number'] ?? 'N/A'),
                            'Submitted File' => $newName,
                        ]
                    );
                    rserves_send_bulk_notification_email($recipients, 'New Waiver Submission', $body);
                    $_SESSION['flash'] = "✅ Waiver submitted successfully!";
                } else {
                    $_SESSION['flash'] = "❌ Database error: " . $conn->error;
                }
                $stmt->close();
            } else {
                $_SESSION['flash'] = "❌ Failed to upload file.";
            }
        } else {
            $_SESSION['flash'] = "❌ Invalid file type. Allowed: PDF, JPG, PNG.";
        }
    } else {
        $_SESSION['flash'] = "❌ Upload error code: " . $file['error'];
    }
}

header("Location: ../pending_requirements.php");
exit;
?>
