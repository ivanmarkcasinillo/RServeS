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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['agreement_file'])) {
    $file = $_FILES['agreement_file'];
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
        
        if (in_array($ext, $allowed)) {
            // Ensure table exists and has columns
            $conn->query("CREATE TABLE IF NOT EXISTS rss_agreements (
                agreement_id INT AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                file_path VARCHAR(255) NOT NULL,
                status ENUM('Pending', 'Verified', 'Rejected') DEFAULT 'Pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                verified_at TIMESTAMP NULL,
                verified_by INT NULL,
                FOREIGN KEY (student_id) REFERENCES students(stud_id) ON DELETE CASCADE
            )");
            
            // Check columns if table existed but without file_path (legacy support)
            $checkCols = $conn->query("SHOW COLUMNS FROM rss_agreements LIKE 'file_path'");
            if ($checkCols->num_rows == 0) {
                $conn->query("ALTER TABLE rss_agreements ADD COLUMN file_path VARCHAR(255) NOT NULL AFTER student_id");
                $conn->query("ALTER TABLE rss_agreements ADD COLUMN status ENUM('Pending', 'Verified', 'Rejected') DEFAULT 'Pending' AFTER file_path");
            }

            $uploadDir = __DIR__ . '/../../uploads/agreements/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $newName = 'agreement_' . $student_id . '_' . time() . '.' . $ext;
            $dest = $uploadDir . $newName;
            $relPath = 'uploads/agreements/' . $newName;
            
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                // Check if exists
                $check = $conn->prepare("SELECT agreement_id FROM rss_agreements WHERE student_id = ?");
                $check->bind_param("i", $student_id);
                $check->execute();
                $existing = $check->get_result()->fetch_assoc();
                $check->close();
                
                if ($existing) {
                    $stmt = $conn->prepare("UPDATE rss_agreements SET file_path = ?, status = 'Pending' WHERE student_id = ?");
                    $stmt->bind_param("si", $relPath, $student_id);
                } else {
                    $stmt = $conn->prepare("INSERT INTO rss_agreements (student_id, file_path, status) VALUES (?, ?, 'Pending')");
                    $stmt->bind_param("is", $student_id, $relPath);
                }
                
                if ($stmt->execute()) {
                    $recipients = array_merge(
                        rserves_fetch_admin_email_recipients($conn),
                        rserves_fetch_coordinator_email_recipients($conn, intval($student['department_id'] ?? 0))
                    );
                    $body = rserves_notification_build_body(
                        'there',
                        "{$student_name} submitted an agreement for verification.",
                        [
                            'Student ID' => (string) ($student['student_number'] ?? 'N/A'),
                            'Submitted File' => $newName,
                        ]
                    );
                    rserves_send_bulk_notification_email($recipients, 'New Agreement Submission', $body);
                    $_SESSION['flash'] = "✅ Agreement submitted successfully!";
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
