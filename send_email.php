<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// Load config if available (for SMTP constants)
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

// Prevent multiple inclusions
if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
    // Adjust path assuming this file is in the root directory
    require_once __DIR__ . '/PHPMailer-6.9.1/src/Exception.php';
    require_once __DIR__ . '/PHPMailer-6.9.1/src/PHPMailer.php';
    require_once __DIR__ . '/PHPMailer-6.9.1/src/SMTP.php';
}

if (!function_exists('sendEmail')) {
    function sendEmail($to, $name, $subject, $body) {
        $mail = new PHPMailer(true);

        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host       = defined('SMTP_HOST') ? SMTP_HOST : 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            
            // Credentials
            $mail->Username   = defined('SMTP_USER') ? SMTP_USER : 'giovanniberdon@gmail.com'; 
            $mail->Password   = defined('SMTP_PASS') ? SMTP_PASS : 'hdum cski acvf iovv'; 

            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = defined('SMTP_PORT') ? SMTP_PORT : 587;

            // Recipients
            $fromEmail = defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : 'no-reply@rserve.com';
            $fromName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'RServe Notification';
            
            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($to, $name);

            // Content
            $mail->isHTML(false);
            $mail->Subject = $subject;
            $mail->Body    = $body;

            $mail->send();
            return true;
        } catch (Exception $e) {
            // If it's our custom error about the password, return that directly
            if (strpos($e->getMessage(), 'Gmail App Password') !== false) {
                return $e->getMessage();
            }
            // Otherwise return PHPMailer error
            return "Mailer Error: " . $mail->ErrorInfo . " (Details: " . $e->getMessage() . ")";
        }
    }
}

if (!function_exists('rserves_notification_recipient_name')) {
    function rserves_notification_recipient_name(array $row): string
    {
        $parts = [];

        foreach (['firstname', 'lastname'] as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        $name = trim(implode(' ', $parts));
        if ($name !== '') {
            return $name;
        }

        return trim((string) ($row['email'] ?? 'RServeS User'));
    }
}

if (!function_exists('rserves_notification_build_body')) {
    function rserves_notification_build_body(string $name, string $intro, array $details = [], string $closing = 'Please log in to RServeS for more details.'): string
    {
        $safe_name = trim($name) !== '' ? trim($name) : 'there';
        $body = "Hello {$safe_name},\n\n{$intro}";

        if (!empty($details)) {
            $body .= "\n\n";
            foreach ($details as $label => $value) {
                $body .= $label . ': ' . $value . "\n";
            }
        }

        if (trim($closing) !== '') {
            $body .= "\n" . trim($closing);
        }

        return $body . "\n\nRServeS Notifications";
    }
}

if (!function_exists('rserves_send_bulk_notification_email')) {
    function rserves_send_bulk_notification_email(array $recipients, string $subject, string $body): void
    {
        $sent_to = [];

        foreach ($recipients as $recipient) {
            $email = strtolower(trim((string) ($recipient['email'] ?? '')));
            if ($email === '' || isset($sent_to[$email])) {
                continue;
            }

            $sent_to[$email] = true;
            $name = rserves_notification_recipient_name($recipient);
            $result = sendEmail($email, $name, $subject, $body);

            if ($result !== true) {
                error_log("Notification email failed for {$email}: " . $result);
            }
        }
    }
}

if (!function_exists('rserves_fetch_student_email_recipient')) {
    function rserves_fetch_student_email_recipient(mysqli $conn, int $student_id): ?array
    {
        $stmt = $conn->prepare("SELECT firstname, lastname, email FROM students WHERE stud_id = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return $row;
    }
}

if (!function_exists('rserves_fetch_instructor_email_recipient')) {
    function rserves_fetch_instructor_email_recipient(mysqli $conn, int $instructor_id): ?array
    {
        $stmt = $conn->prepare("SELECT firstname, lastname, email FROM instructors WHERE inst_id = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param("i", $instructor_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return $row;
    }
}

if (!function_exists('rserves_fetch_admin_email_recipients')) {
    function rserves_fetch_admin_email_recipients(mysqli $conn): array
    {
        $recipients = [];
        $result = $conn->query("SELECT firstname, lastname, email FROM admin WHERE email IS NOT NULL AND email <> ''");
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $recipients[] = $row;
            }
            $result->free();
        }

        return $recipients;
    }
}

if (!function_exists('rserves_fetch_coordinator_email_recipients')) {
    function rserves_fetch_coordinator_email_recipients(mysqli $conn, int $department_id = 0): array
    {
        $recipients = [];

        if ($department_id > 0) {
            $stmt = $conn->prepare("SELECT firstname, lastname, email FROM coordinator WHERE department_id = ? AND email IS NOT NULL AND email <> ''");
            if ($stmt) {
                $stmt->bind_param("i", $department_id);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $recipients[] = $row;
                }
                $stmt->close();
            }
        } else {
            $result = $conn->query("SELECT firstname, lastname, email FROM coordinator WHERE email IS NOT NULL AND email <> ''");
            if ($result instanceof mysqli_result) {
                while ($row = $result->fetch_assoc()) {
                    $recipients[] = $row;
                }
                $result->free();
            }
        }

        return $recipients;
    }
}
?>
