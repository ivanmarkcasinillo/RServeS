<?php
session_start();
require "dbconnect.php";
require_once __DIR__ . "/../send_email.php";
require_once __DIR__ . "/task_assignment_helper.php";

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Instructor') {
    header("Location: ../home2.php");
    exit;
}

function rserves_process_request_is_ajax(): bool
{
    return (isset($_POST['ajax']) && $_POST['ajax'] === '1')
        || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

function rserves_process_request_dashboard_path(): string
{
    $departmentId = intval($_SESSION['department_id'] ?? 2);

    return match ($departmentId) {
        1 => 'instructor_college_of_education_dashboard.php',
        2 => 'instructor_college_of_technology_dashboard.php',
        3 => 'instructor_college_of_hospitality_and_tourism_management_dashboard.php',
        default => 'instructor_college_of_technology_dashboard.php',
    };
}

function rserves_process_request_respond(bool $success, string $message, ?string $type = null): void
{
    $toastType = $type ?? ($success ? 'success' : 'danger');

    if (rserves_process_request_is_ajax()) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'type' => $toastType,
        ]);
        exit;
    }

    header(
        "Location: "
        . rserves_process_request_dashboard_path()
        . "?msg="
        . urlencode($message)
        . "&msg_type="
        . urlencode($toastType)
    );
    exit;
}

// Get Instructor ID
$email = $_SESSION['email'];
$stmt = $conn->prepare("SELECT inst_id, firstname, lastname FROM instructors WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $inst_id = $row['inst_id'];
} else {
    die("Instructor not found");
}
$stmt->close();

// APPROVE REQUEST
if (isset($_POST['approve_request'])) {
    $request_id = intval($_POST['request_id']);
    
    // Verify request
    $check = $conn->prepare("SELECT * FROM section_requests WHERE request_id = ? AND adviser_id = ? AND status = 'Pending'");
    $check->bind_param("ii", $request_id, $inst_id);
    $check->execute();
    $res_check = $check->get_result();
    
    if ($req = $res_check->fetch_assoc()) {
        $student_id = $req['student_id'];
        $section = $req['section'];
        $year_level = $req['year_level'];
        
        // 1. Update Request Status
        $up_req = $conn->prepare("UPDATE section_requests SET status = 'Approved', approved_at = NOW(), approved_by = ? WHERE request_id = ?");
        $up_req->bind_param("ii", $inst_id, $request_id);
        $up_req->execute();
        $up_req->close();

        // 1.1 Update RSS Enrollment Status
        $up_rss = $conn->prepare("UPDATE rss_enrollments SET status = 'Verified', verified_at = NOW(), verified_by = ? WHERE student_id = ?");
        $up_rss->bind_param("ii", $inst_id, $student_id);
        $up_rss->execute();
        $up_rss->close();
        
        // 2. Update Student Record (Lock Section)
        $up_stud = $conn->prepare("UPDATE students SET section = ?, year_level = ? WHERE stud_id = ?");
        $up_stud->bind_param("sii", $section, $year_level, $student_id);
        $up_stud->execute();
        $up_stud->close();
        
        // 3. Update Progress (100% Enrollment)
        $prog = $conn->prepare("INSERT INTO rss_progress (student_id, enrollment_completed, enrollment_date, completion_percentage) VALUES (?, TRUE, NOW(), 100) ON DUPLICATE KEY UPDATE enrollment_completed = TRUE, enrollment_date = NOW(), completion_percentage = 100");
        $prog->bind_param("i", $student_id);
        $prog->execute();
        $prog->close();

        $student = rserves_fetch_student_email_recipient($conn, $student_id);
        if ($student) {
            $body = rserves_notification_build_body(
                rserves_notification_recipient_name($student),
                "Your enrollment request was approved.",
                [
                    'Section' => (string) $section,
                    'Year Level' => (string) $year_level,
                    'Status' => 'Approved',
                ]
            );
            rserves_send_bulk_notification_email([$student], 'Enrollment Approved', $body);
        }
        
        $msg = "Student enrollment approved successfully!";
    } else {
        $msg = "Invalid request or already processed.";
    }
    rserves_process_request_respond(stripos($msg, 'Invalid') !== 0, $msg);
}

// DECLINE REQUEST
if (isset($_POST['decline_request'])) {
    $request_id = intval($_POST['request_id']);
    $reason = isset($_POST['decline_reason']) ? $_POST['decline_reason'] : "Declined by Adviser";
    
    // Verify
    $check = $conn->prepare("SELECT * FROM section_requests WHERE request_id = ? AND adviser_id = ? AND status = 'Pending'");
    $check->bind_param("ii", $request_id, $inst_id);
    $check->execute();
    $res = $check->get_result();
    
    if ($req = $res->fetch_assoc()) {
        $student_id = $req['student_id'];

        $up = $conn->prepare("UPDATE section_requests SET status = 'Declined', decline_reason = ? WHERE request_id = ?");
        $up->bind_param("si", $reason, $request_id);
        $up->execute();
        $up->close();
        
        // Update RSS Enrollment Status to Rejected
        $up_rss = $conn->prepare("UPDATE rss_enrollments SET status = 'Rejected' WHERE student_id = ?");
        $up_rss->bind_param("i", $student_id);
        $up_rss->execute();
        $up_rss->close();

        $student = rserves_fetch_student_email_recipient($conn, $student_id);
        if ($student) {
            $body = rserves_notification_build_body(
                rserves_notification_recipient_name($student),
                "Your enrollment request was rejected.",
                [
                    'Status' => 'Rejected',
                    'Reason' => $reason,
                ]
            );
            rserves_send_bulk_notification_email([$student], 'Enrollment Rejected', $body);
        }
        
        $msg = "Request declined.";
    } else {
        $msg = "Invalid request.";
    }
    rserves_process_request_respond(stripos($msg, 'Invalid') !== 0, $msg);
}

// END SESSION (Mark as Completed)
if (isset($_POST['end_session'])) {
    $student_id = intval($_POST['student_id']);

    if (!rserves_instructor_student_can_end_session($conn, $student_id)) {
        $required_hours = number_format(rserves_instructor_required_hours(), 0);
        $current_hours = number_format(rserves_instructor_get_student_approved_hours($conn, $student_id), 2);
        rserves_process_request_respond(
            false,
            "End Session is only available once the student reaches {$required_hours} approved hours. Current approved hours: {$current_hours}.",
            'warning'
        );
    }
    
    // Mark section request as Completed (if approved)
    $up = $conn->prepare("UPDATE section_requests SET status = 'Completed' WHERE student_id = ? AND adviser_id = ? AND status = 'Approved'");
    $up->bind_param("ii", $student_id, $inst_id);
    $up->execute();
    
    if ($up->affected_rows > 0) {
         $student = rserves_fetch_student_email_recipient($conn, $student_id);
         if ($student) {
             $body = rserves_notification_build_body(
                 rserves_notification_recipient_name($student),
                 "Your RSS session was archived by your adviser.",
                 [
                     'Status' => 'Completed',
                 ]
             );
             rserves_send_bulk_notification_email([$student], 'RSS Session Archived', $body);
         }

         $msg = "Student session ended and archived.";
    } else {
         $msg = "Could not end session (Student might not be approved or not in your advisory class).";
    }
    
    rserves_process_request_respond(stripos($msg, 'Could not') !== 0, $msg);
}
?>
