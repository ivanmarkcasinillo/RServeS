<?php
session_start();
include "dbconnect.php";
require_once __DIR__ . "/../send_email.php";

// Only allow Administrator
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Administrator') {
    header("Location: ../home2.php");
    exit;
}

$enrollment_id = $_GET['id'] ?? null;
if (!$enrollment_id) {
    die("Invalid Enrollment ID");
}

// Ensure columns exist (Fix for missing columns error)
$checkStatus = $conn->query("SHOW COLUMNS FROM rss_enrollments LIKE 'status'");
if ($checkStatus->num_rows == 0) {
    $conn->query("ALTER TABLE rss_enrollments ADD COLUMN status ENUM('Pending', 'Verified', 'Rejected') DEFAULT 'Pending'");
}

$checkVerifiedAt = $conn->query("SHOW COLUMNS FROM rss_enrollments LIKE 'verified_at'");
if ($checkVerifiedAt->num_rows == 0) {
    $conn->query("ALTER TABLE rss_enrollments ADD COLUMN verified_at TIMESTAMP NULL");
}

$checkVerifiedBy = $conn->query("SHOW COLUMNS FROM rss_enrollments LIKE 'verified_by'");
if ($checkVerifiedBy->num_rows == 0) {
    $conn->query("ALTER TABLE rss_enrollments ADD COLUMN verified_by INT NULL");
}

// Handle Verification / Rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $status = ($action === 'verify') ? 'Verified' : (($action === 'reject') ? 'Rejected' : '');
    
    if ($status) {
        $stmt = $conn->prepare("UPDATE rss_enrollments SET status = ?, verified_at = NOW(), verified_by = ? WHERE enrollment_id = ?");
        $stmt->bind_param("sii", $status, $_SESSION['adm_id'], $enrollment_id);
        
        if ($stmt->execute()) {
            $notify_stmt = $conn->prepare("
                SELECT s.stud_id
                FROM rss_enrollments e
                INNER JOIN students s ON e.student_id = s.stud_id
                WHERE e.enrollment_id = ?
                LIMIT 1
            ");
            if ($notify_stmt) {
                $notify_stmt->bind_param("i", $enrollment_id);
                $notify_stmt->execute();
                $notify_row = $notify_stmt->get_result()->fetch_assoc();
                $notify_stmt->close();

                if (!empty($notify_row['stud_id'])) {
                    $student = rserves_fetch_student_email_recipient($conn, intval($notify_row['stud_id']));
                    if ($student) {
                        $body = rserves_notification_build_body(
                            rserves_notification_recipient_name($student),
                            "Your enrollment form was {$status}.",
                            [
                                'Document' => 'Enrollment Form',
                                'Status' => $status,
                            ]
                        );
                        rserves_send_bulk_notification_email([$student], "Enrollment Form {$status}", $body);
                    }
                }
            }

            $_SESSION['flash'] = "Enrollment marked as $status.";
            header("Location: admin_dashboard.php");
            exit;
        } else {
            $error = "Error updating status: " . $conn->error;
        }
    }
}

// Fetch Enrollment Data
$stmt = $conn->prepare("SELECT e.*, s.email as student_email FROM rss_enrollments e JOIN students s ON e.student_id = s.stud_id WHERE e.enrollment_id = ?");
$stmt->bind_param("i", $enrollment_id);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();

if (!$data) {
    die("Enrollment not found.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Enrollment</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .data-label { font-weight: 600; color: #555; }
        .data-value { border-bottom: 1px solid #eee; padding-bottom: 5px; margin-bottom: 15px; }
        .section-title { margin-top: 30px; margin-bottom: 20px; border-bottom: 2px solid #1a4f7a; padding-bottom: 10px; color: #1a4f7a; }
        .photo-box { width: 150px; height: 150px; border: 1px solid #ddd; object-fit: cover; }
    </style>
</head>
<body class="bg-light">

<div class="container py-5">
    <div class="card shadow">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h4 class="mb-0">Enrollment Verification</h4>
            <a href="admin_dashboard.php" class="btn btn-light btn-sm">Back to Dashboard</a>
        </div>
        <div class="card-body">
            
            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>

            <div class="row">
                <div class="col-md-9">
                    <h5 class="section-title mt-0">Personal Information</h5>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="data-label">Surname</div>
                            <div class="data-value"><?php echo htmlspecialchars($data['surname']); ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="data-label">Given Name</div>
                            <div class="data-value"><?php echo htmlspecialchars($data['given_name']); ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="data-label">Middle Name</div>
                            <div class="data-value"><?php echo htmlspecialchars($data['middle_name']); ?></div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="data-label">Student Number</div>
                            <div class="data-value"><?php echo htmlspecialchars($data['student_number']); ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="data-label">Course / Major</div>
                            <div class="data-value"><?php echo htmlspecialchars($data['course'] . ' / ' . $data['major']); ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="data-label">Year / Section</div>
                            <div class="data-value"><?php echo htmlspecialchars($data['year_level'] . ' - ' . $data['section']); ?></div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="data-label">Address</div>
                            <div class="data-value"><?php echo htmlspecialchars($data['city_address']); ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="data-label">Email</div>
                            <div class="data-value"><?php echo htmlspecialchars($data['email_address']); ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 text-center">
                    <div class="data-label mb-2">Student Photo</div>
                    <?php if (!empty($data['photo_path'])): ?>
                        <img src="../student/<?php echo htmlspecialchars($data['photo_path']); ?>" class="photo-box img-thumbnail">
                    <?php else: ?>
                        <div class="photo-box d-flex align-items-center justify-content-center bg-secondary text-white">No Photo</div>
                    <?php endif; ?>
                    <div class="mt-3">
                        <span class="badge bg-<?php echo ($data['status'] == 'Verified' ? 'success' : ($data['status'] == 'Rejected' ? 'danger' : 'warning')); ?> fs-6">
                            <?php echo $data['status']; ?>
                        </span>
                    </div>
                </div>
            </div>

            <h5 class="section-title">Family Background</h5>
            <div class="row">
                <div class="col-md-4">
                    <div class="data-label">Father's Name</div>
                    <div class="data-value"><?php echo htmlspecialchars($data['father_name']); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="data-label">Occupation</div>
                    <div class="data-value"><?php echo htmlspecialchars($data['father_occupation']); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="data-label">Contact</div>
                    <div class="data-value"><?php echo htmlspecialchars($data['father_contact']); ?></div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-4">
                    <div class="data-label">Mother's Name</div>
                    <div class="data-value"><?php echo htmlspecialchars($data['mother_name']); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="data-label">Occupation</div>
                    <div class="data-value"><?php echo htmlspecialchars($data['mother_occupation']); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="data-label">Contact</div>
                    <div class="data-value"><?php echo htmlspecialchars($data['mother_contact']); ?></div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-4">
                    <div class="data-label">Guardian's Name</div>
                    <div class="data-value"><?php echo htmlspecialchars($data['guardian_name']); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="data-label">Address</div>
                    <div class="data-value"><?php echo htmlspecialchars($data['guardian_address']); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="data-label">Contact</div>
                    <div class="data-value"><?php echo htmlspecialchars($data['guardian_contact']); ?></div>
                </div>
            </div>

            <h5 class="section-title">Health Information</h5>
            <div class="row">
                <div class="col-md-3">
                    <div class="data-label">Height</div>
                    <div class="data-value"><?php echo htmlspecialchars($data['height']); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="data-label">Weight</div>
                    <div class="data-value"><?php echo htmlspecialchars($data['weight']); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="data-label">Blood Type</div>
                    <div class="data-value"><?php echo htmlspecialchars($data['blood_type']); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="data-label">Vaccination</div>
                    <div class="data-value"><?php echo htmlspecialchars($data['vaccination_status']); ?></div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="data-label">Health Problems</div>
                    <div class="data-value"><?php echo htmlspecialchars($data['health_problem']); ?></div>
                </div>
            </div>

            <h5 class="section-title">Educational Background</h5>
            <div class="row">
                <div class="col-md-6">
                    <div class="data-label">Tertiary School</div>
                    <div class="data-value"><?php echo htmlspecialchars($data['tertiary_school']); ?></div>
                </div>
                <div class="col-md-6">
                    <div class="data-label">Secondary School</div>
                    <div class="data-value"><?php echo htmlspecialchars($data['secondary_school']); ?></div>
                </div>
            </div>

            <div class="mt-4 pt-3 border-top d-flex justify-content-end gap-2">
                <form method="POST" onsubmit="return confirm('Are you sure you want to REJECT this enrollment?');">
                    <input type="hidden" name="action" value="reject">
                    <button type="submit" class="btn btn-danger" <?php echo ($data['status'] == 'Rejected') ? 'disabled' : ''; ?>>
                        <i class="fas fa-times"></i> Reject
                    </button>
                </form>
                
                <form method="POST" onsubmit="return confirm('Are you sure you want to VERIFY this enrollment?');">
                    <input type="hidden" name="action" value="verify">
                    <button type="submit" class="btn btn-success" <?php echo ($data['status'] == 'Verified') ? 'disabled' : ''; ?>>
                        <i class="fas fa-check"></i> Verify
                    </button>
                </form>
            </div>

        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/js/all.min.js"></script>
</body>
</html>
