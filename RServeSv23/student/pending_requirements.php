<?php
session_start();
require "dbconnect.php";

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Student') {
    header("Location: ../home2.php");
    exit;
}

$student_id = $_SESSION['stud_id'];

// Check Enrollment Status
$stmt = $conn->prepare("SELECT enrollment_id, status FROM rss_enrollments WHERE student_id = ?");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$enrollment = $stmt->get_result()->fetch_assoc();
$stmt->close();

$enrollment_status = $enrollment ? ($enrollment['status'] ?? 'Pending') : 'Not Submitted';
$decline_reason = null;

if ($enrollment_status === 'Rejected') {
    $stmt = $conn->prepare("SELECT decline_reason FROM section_requests WHERE student_id = ? ORDER BY request_id DESC LIMIT 1");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $req = $stmt->get_result()->fetch_assoc();
    $decline_reason = $req['decline_reason'] ?? 'Please contact your adviser.';
    $stmt->close();
}

// Check Waiver Status
$stmt = $conn->prepare("SELECT id, status FROM rss_waivers WHERE student_id = ?");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$waiver = $stmt->get_result()->fetch_assoc();
$stmt->close();

$waiver_status = $waiver ? ($waiver['status'] ?? 'Pending') : 'Not Submitted';

// Check Agreement Status
$stmt = $conn->prepare("SELECT agreement_id, status FROM rss_agreements WHERE student_id = ?");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$agreement = $stmt->get_result()->fetch_assoc();
$stmt->close();

$agreement_status = $agreement ? ($agreement['status'] ?? 'Pending') : 'Not Submitted';

// Fetch Student Details for Dashboard Header
$stmt = $conn->prepare("SELECT firstname, lastname, mi, photo FROM students WHERE stud_id = ?");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student_info = $stmt->get_result()->fetch_assoc();
$stmt->close();

$fullname = $student_info['firstname'] 
          . (!empty($student_info['mi']) ? ' ' . strtoupper(substr($student_info['mi'],0,1)) . '.' : '')
          . ' ' . $student_info['lastname'];
$photo = !empty($student_info['photo']) ? $student_info['photo'] : 'default_profile.png';

// Check if all Verified
if ($enrollment_status === 'Verified' && $waiver_status === 'Verified' && $agreement_status === 'Verified') {
    // Determine dashboard
    $departments = [
        1 => "College of Education",
        2 => "College of Technology",
        3 => "College of Hospitality and Tourism Management"
    ];
    $dept_id = $_SESSION['department_id'] ?? 2;
    $dept_name = $departments[$dept_id] ?? "College of Technology";
    $dept_code = strtolower(str_replace(' ', '_', $dept_name));
    $dashboard_file = "student_{$dept_code}_dashboard.php";
    
    header("Location: $dashboard_file");
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Requirements</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Urbanist:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
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
            color: var(--text-dark);
            background-image: url('../img/bg.jpg');
            background-size: cover;
            background-position: center;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .requirements-card {
            background: rgba(255, 255, 255, 0.87);
            border-radius: 16px;
            padding: 2.5rem;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.4);
            border-top: 5px solid var(--primary-color);
            width: 100%;
            max-width: 700px;
            position: relative;
        }

        .brand-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .brand-header img {
            width: 60px;
            height: auto;
            margin-bottom: 10px;
        }

        .status-badge {
            font-size: 0.9rem;
            padding: 5px 12px;
            border-radius: 20px;
        }
        .status-verified { background-color: #d1e7dd; color: #0f5132; }
        .status-pending { background-color: #fff3cd; color: #664d03; }
        .status-rejected { background-color: #f8d7da; color: #842029; }
        .status-none { background-color: #e2e3e5; color: #41464b; }

        .logout-link {
            position: absolute;
            top: 20px;
            right: 20px;
            color: #6c757d;
            text-decoration: none;
            font-size: 0.9rem;
            transition: color 0.2s;
        }
        .logout-link:hover {
            color: var(--primary-color);
        }
    </style>
</head>
<body>

<div class="requirements-card">
    <a href="logout.php" class="logout-link"><i class="fas fa-sign-out-alt me-1"></i> Logout</a>
    
    <div class="brand-header">
        <img src="../img/logo3.png" alt="RServeS Logo">
        <h4 class="fw-bold text">RServeS</h4>
        <p class="text-muted">Return Service System</p>
    </div>

    <h3 class="mb-2 text-center">Requirements Checklist</h3>
    <p class="text-center text-muted mb-4">Welcome, <strong><?= htmlspecialchars($fullname) ?></strong>! Please complete the following to access your dashboard.</p>
    
    <?php if (isset($_SESSION['flash'])): ?>
        <div class="alert alert-info text-center">
            <?= $_SESSION['flash']; unset($_SESSION['flash']); ?>
        </div>
    <?php endif; ?>

    <div class="list-group">
        <!-- Enrollment Form -->
        <div class="list-group-item d-flex justify-content-between align-items-center py-3">
            <div>
                <h6 class="mb-1 fw-bold"><i class="fas fa-file-alt me-2 text-primary"></i>Enrollment Form</h6>
                <small class="text-muted d-block">Personal and scholastic data.</small>
            </div>
            <div class="d-flex align-items-center">
                <?php if ($enrollment_status === 'Not Submitted'): ?>
                    <a href="enrolment.php" class="btn btn-sm btn-primary px-3">Fill Out</a>
                <?php else: ?>
                    <span class="status-badge status-<?= strtolower($enrollment_status) ?>"><?= $enrollment_status ?></span>
                    <?php if ($enrollment_status === 'Rejected'): ?>
                        <div class="mt-2 text-danger small">
                            <strong>Reason:</strong> <?= htmlspecialchars($decline_reason ?? '') ?>
                        </div>
                        <a href="enrolment.php" class="btn btn-sm btn-outline-danger ms-2">Update</a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Waiver -->
        <div class="list-group-item d-flex justify-content-between align-items-center py-3">
            <div>
                <h6 class="mb-1 fw-bold"><i class="fas fa-file-signature me-2 text-primary"></i>Waiver</h6>
                <small class="text-muted d-block">Liability waiver form.</small>
            </div>
            <div class="d-flex align-items-center">
                <?php if ($waiver_status === 'Not Submitted'): ?>
                    <button class="btn btn-sm btn-primary px-3" data-bs-toggle="modal" data-bs-target="#waiverModal">Submit</button>
                <?php else: ?>
                    <span class="status-badge status-<?= strtolower($waiver_status) ?>"><?= $waiver_status ?></span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Agreement -->
        <div class="list-group-item d-flex justify-content-between align-items-center py-3">
            <div>
                <h6 class="mb-1 fw-bold"><i class="fas fa-handshake me-2 text-primary"></i>Agreement Form</h6>
                <small class="text-muted d-block">Internship agreement.</small>
            </div>
            <div class="d-flex align-items-center">
                <?php if ($agreement_status === 'Not Submitted'): ?>
                    <button class="btn btn-sm btn-primary px-3" data-bs-toggle="modal" data-bs-target="#agreementModal">Submit</button>
                <?php else: ?>
                    <span class="status-badge status-<?= strtolower($agreement_status) ?>"><?= $agreement_status ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Scripts -->

<!-- Waiver Modal -->
<div class="modal fade" id="waiverModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Submit Waiver</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form action="documents/upload_waiver.php" method="POST" enctype="multipart/form-data">
                    <p>Please download the waiver form, sign it, scan it, and upload it here in pdf form.</p>
                    <a href="documents/Waiver.docx" class="btn btn-outline-secondary mb-3" download><i class="fas fa-download me-2"></i>Download Template</a>
                    <div class="mb-3">
                        <label class="form-label">Upload Signed Waiver (PDF/Image)</label>
                        <input type="file" class="form-control" name="waiver_file" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Submit Waiver</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Agreement Modal -->
<div class="modal fade" id="agreementModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Submit Agreement Form</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form action="documents/upload_agreement.php" method="POST" enctype="multipart/form-data">
                    <p>Please download the agreement form, sign it, scan it, and upload it here in pdf form.</p>
                    <a href="documents/Agreement.docx" class="btn btn-outline-secondary mb-3" download><i class="fas fa-download me-2"></i>Download Template</a>
                    <div class="mb-3">
                        <label class="form-label">Upload Signed Agreement (PDF/Image)</label>
                        <input type="file" class="form-control" name="agreement_file" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Submit Agreement</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
