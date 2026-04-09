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
?>
