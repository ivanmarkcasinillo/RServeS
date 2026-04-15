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

if (!function_exists('rserves_pending_status_meta')) {
    function rserves_pending_status_meta($status)
    {
        switch ($status) {
            case 'Verified':
                return ['class' => 'verified', 'label' => 'Verified'];
            case 'Pending':
                return ['class' => 'pending', 'label' => 'Pending Review'];
            case 'Rejected':
                return ['class' => 'rejected', 'label' => 'Needs Update'];
            default:
                return ['class' => 'not-submitted', 'label' => 'Not Submitted'];
        }
    }
}

$all_statuses = [$enrollment_status, $waiver_status, $agreement_status];
$verified_count = 0;
$pending_count = 0;
$needs_action_count = 0;

foreach ($all_statuses as $status) {
    if ($status === 'Verified') {
        $verified_count++;
    }

    if ($status === 'Pending') {
        $pending_count++;
    }

    if ($status === 'Not Submitted' || $status === 'Rejected') {
        $needs_action_count++;
    }
}

$completion_percentage = (int) round(($verified_count / 3) * 100);
$student_initials = strtoupper(substr($student_info['firstname'] ?? '', 0, 1) . substr($student_info['lastname'] ?? '', 0, 1));
$student_photo_path = null;

if (!empty($student_info['photo'])) {
    $normalized_photo = ltrim(str_replace('\\', '/', $student_info['photo']), '/');
    $photo_disk_path = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized_photo);

    if (file_exists($photo_disk_path)) {
        $student_photo_path = '../' . $normalized_photo;
    }
}

$enrollment_meta = rserves_pending_status_meta($enrollment_status);
$waiver_meta = rserves_pending_status_meta($waiver_status);
$agreement_meta = rserves_pending_status_meta($agreement_status);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Requirements | RServeS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Sora:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --navy-950: #082746;
            --navy-900: #0d355f;
            --navy-700: #28629d;
            --sky-500: #46b2ff;
            --ink-900: #0f1728;
            --ink-700: #4b586d;
            --ink-500: #76849a;
            --surface: rgba(255, 255, 255, 0.94);
            --border-soft: rgba(15, 23, 40, 0.1);
            --shadow-soft: 0 26px 70px rgba(10, 33, 60, 0.14);
            --shadow-button: 0 18px 34px rgba(13, 53, 95, 0.22);
            --success: #138a5b;
            --warning: #a26700;
            --danger: #ba2e4f;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: 'Manrope', sans-serif;
            color: var(--ink-900);
            min-height: 100vh;
            background:
                radial-gradient(circle at top left, rgba(70, 178, 255, 0.16), transparent 28%),
                linear-gradient(180deg, rgba(237, 242, 248, 0.84) 0%, rgba(247, 248, 251, 0.92) 100%),
                url('../img/bg.jpg') center center / cover no-repeat fixed;
        }

        h1, h2, h3, h4, h5, h6 { font-family: 'Sora', sans-serif; }

        .page-shell {
            max-width: 1180px;
            margin: 0 auto;
            padding: 32px 24px 48px;
        }

        .hero-panel, .glass-panel, .req-card, .summary-card, .modal-content {
            border-radius: 30px;
            border: 1px solid rgba(255, 255, 255, 0.72);
            box-shadow: var(--shadow-soft);
            backdrop-filter: blur(14px);
        }

        .hero-panel {
            position: relative;
            overflow: hidden;
            padding: 32px 36px;
            margin-bottom: 24px;
            color: #fff;
            background:
                linear-gradient(180deg, rgba(6, 35, 66, 0.3) 0%, rgba(6, 35, 66, 0.78) 100%),
                linear-gradient(140deg, rgba(8, 39, 70, 0.95) 0%, rgba(16, 76, 133, 0.82) 100%),
                url('../img/bg.jpg') center center / cover no-repeat;
        }

        .hero-panel::before {
            content: "";
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle at top right, rgba(120, 183, 255, 0.2), transparent 32%),
                radial-gradient(circle at bottom left, rgba(120, 183, 255, 0.12), transparent 34%);
        }

        .hero-panel > * { position: relative; z-index: 1; }
        .brand-lockup, .hero-row, .summary-user { display: flex; align-items: center; gap: 1rem; }
        .hero-row { justify-content: space-between; align-items: flex-start; gap: 1rem; }
        .brand-logo {
            width: 62px; height: 62px; object-fit: contain; padding: 0.55rem; border-radius: 20px;
            background: rgba(255, 255, 255, 0.12); border: 1px solid rgba(255, 255, 255, 0.16);
        }
        .brand-mark strong { display: block; font-size: 1.7rem; font-weight: 700; letter-spacing: -0.05em; }
        .brand-mark span {
            display: block; width: 62px; height: 4px; margin-top: 0.35rem; border-radius: 999px;
            background: linear-gradient(90deg, #2aa3ff 0%, #75c0ff 100%);
        }
        .hero-copy { max-width: 760px; margin-top: 2rem; }
        .hero-copy h1 {
            margin: 0 0 1rem; font-size: clamp(2.5rem, 5vw, 4.3rem); line-height: 0.95;
            letter-spacing: -0.08em; font-weight: 800;
        }
        .hero-copy p, .section-lead, .req-main p, .note-box, .modal-copy { color: var(--ink-700); line-height: 1.7; }
        .hero-copy p { color: rgba(222, 235, 255, 0.84); max-width: 640px; font-size: 1.05rem; }
        .hero-tag, .meta-pill, .step-pill, .care-pill, .status-chip {
            display: inline-flex; align-items: center; gap: 0.65rem; border-radius: 999px; font-weight: 800;
        }
        .hero-tag {
            margin-bottom: 1rem; padding: 0.5rem 0.85rem; font-size: 0.78rem; letter-spacing: 0.16em;
            text-transform: uppercase; background: rgba(255, 255, 255, 0.1); border: 1px solid rgba(255, 255, 255, 0.12);
        }
        .meta-row { display: flex; flex-wrap: wrap; gap: 0.85rem; margin-top: 1.6rem; }
        .meta-pill, .hero-link {
            display: inline-flex; align-items: center; gap: 0.65rem;
            padding: 0.88rem 1rem; border-radius: 18px; border: 1px solid rgba(255, 255, 255, 0.14);
            background: rgba(255, 255, 255, 0.1); color: #fff; text-decoration: none; font-weight: 700;
        }
        .hero-link:hover { color: #fff; background: rgba(255, 255, 255, 0.16); }
        .glass-panel {
            position: relative; padding: 32px; background: var(--surface);
        }
        .glass-panel::before {
            content: ""; position: absolute; top: 0; left: 0; right: 0; height: 6px;
            border-radius: 30px 30px 0 0; background: linear-gradient(90deg, #1f7be0 0%, #46b2ff 48%, #7bcfff 100%);
        }
        .section-kicker, .step-pill, .care-pill {
            padding: 0.46rem 0.78rem; font-size: 0.74rem; letter-spacing: 0.16em; text-transform: uppercase;
        }
        .section-kicker { display: inline-flex; align-items: center; border-radius: 999px; }
        .section-kicker, .step-pill {
            background: rgba(13, 53, 95, 0.08); color: var(--navy-900);
        }
        .progress-pill {
            min-width: 124px; padding: 1rem 1.1rem; border-radius: 22px; text-align: center;
            background: #f5f8fd; border: 1px solid rgba(23, 77, 132, 0.1);
        }
        .progress-pill span { display: block; color: var(--ink-500); font-size: 0.8rem; font-weight: 700; letter-spacing: 0.14em; text-transform: uppercase; }
        .progress-pill strong { display: block; margin-top: 0.35rem; font-size: 2rem; line-height: 1; color: var(--navy-900); }
        .req-list { display: grid; gap: 1rem; }
        .req-card {
            padding: 1.35rem; background: linear-gradient(135deg, #ffffff 0%, #f7fbff 100%);
            border: 1px solid rgba(23, 77, 132, 0.1);
        }
        .req-head { display: flex; align-items: flex-start; gap: 1rem; flex-wrap: wrap; }
        .req-icon {
            width: 56px; height: 56px; border-radius: 18px; display: inline-flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, var(--navy-900) 0%, var(--navy-700) 100%); color: #fff; font-size: 1.1rem;
            box-shadow: 0 18px 40px rgba(13, 53, 95, 0.18);
        }
        .req-main { flex: 1 1 260px; min-width: 0; }
        .req-main h3 { margin: 0.95rem 0 0.4rem; font-size: 1.35rem; letter-spacing: -0.04em; }
        .req-meta { display: flex; flex-wrap: wrap; gap: 0.75rem; margin-top: 0.9rem; }
        .care-pill { background: rgba(23, 77, 132, 0.08); color: var(--navy-700); }
        .status-chip { padding: 0.72rem 0.95rem; border-radius: 16px; border: 1px solid transparent; white-space: nowrap; }
        .status-verified { background: rgba(19, 138, 91, 0.1); border-color: rgba(19, 138, 91, 0.16); color: var(--success); }
        .status-pending { background: rgba(246, 178, 52, 0.14); border-color: rgba(246, 178, 52, 0.16); color: var(--warning); }
        .status-rejected { background: rgba(186, 46, 79, 0.11); border-color: rgba(186, 46, 79, 0.16); color: var(--danger); }
        .status-not-submitted { background: rgba(15, 23, 40, 0.07); border-color: rgba(15, 23, 40, 0.08); color: var(--ink-700); }
        .note-box {
            margin-top: 1rem; padding: 0.95rem 1rem; border-radius: 18px;
            background: rgba(13, 53, 95, 0.05); border: 1px solid rgba(13, 53, 95, 0.08);
        }
        .note-box strong { color: var(--navy-900); }
        .note-danger { background: rgba(186, 46, 79, 0.08); border-color: rgba(186, 46, 79, 0.12); color: #8f2741; }
        .note-success { background: rgba(19, 138, 91, 0.08); border-color: rgba(19, 138, 91, 0.12); color: #126746; }
        .actions-row, .modal-actions { display: flex; justify-content: flex-end; gap: 0.75rem; flex-wrap: wrap; margin-top: 1rem; }
        .btn-theme, .btn-soft, .btn-light-soft {
            display: inline-flex; align-items: center; justify-content: center;
            min-height: 50px; padding: 0.85rem 1.35rem; border-radius: 18px; font-weight: 700; transition: 0.2s ease;
        }
        .btn-theme { border: 0; background: linear-gradient(135deg, var(--navy-900) 0%, var(--navy-700) 100%); color: #fff; box-shadow: var(--shadow-button); }
        .btn-theme:hover { transform: translateY(-1px); color: #fff; box-shadow: 0 20px 36px rgba(13, 53, 95, 0.28); }
        .btn-soft {
            border: 1px solid rgba(23, 77, 132, 0.16); background: #f5f8fc; color: var(--navy-900);
        }
        .btn-soft:hover { transform: translateY(-1px); background: #edf4fb; color: var(--navy-900); }
        .btn-light-soft {
            border: 1px solid rgba(255, 255, 255, 0.14); background: rgba(255, 255, 255, 0.1); color: #fff;
        }
        .btn-light-soft:hover { transform: translateY(-1px); background: rgba(255, 255, 255, 0.16); color: #fff; }
        .summary-card {
            height: 100%; padding: 28px; background: linear-gradient(180deg, rgba(8, 39, 70, 0.94) 0%, rgba(16, 76, 133, 0.9) 100%); color: #fff;
        }
        .avatar {
            width: 64px; height: 64px; flex-shrink: 0; border-radius: 20px; overflow: hidden; display: inline-flex; align-items: center; justify-content: center;
            background: rgba(255, 255, 255, 0.15); border: 1px solid rgba(255, 255, 255, 0.12);
        }
        .avatar img { width: 100%; height: 100%; object-fit: cover; }
        .avatar span { font-size: 1.1rem; font-weight: 700; letter-spacing: 0.08em; }
        .summary-label, .summary-stat span { display: block; color: rgba(226, 238, 255, 0.72); font-size: 0.75rem; font-weight: 800; letter-spacing: 0.14em; text-transform: uppercase; }
        .summary-name { display: block; font-size: 1.1rem; font-weight: 800; line-height: 1.3; }
        .summary-help, .summary-card p, .summary-item small { color: rgba(226, 238, 255, 0.76); }
        .summary-item small { display: block; margin-top: 0.2rem; }
        .progress-box, .summary-stat, .summary-item, .summary-user {
            padding: 1rem; border-radius: 22px; background: rgba(255, 255, 255, 0.08); border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .progress-box { margin: 1rem 0; }
        .progress-row { display: flex; justify-content: space-between; gap: 1rem; margin-bottom: 0.75rem; }
        .progress-row strong, .summary-stat strong { font-size: 1.35rem; line-height: 1; }
        .progress-track { width: 100%; height: 12px; border-radius: 999px; background: rgba(255, 255, 255, 0.12); overflow: hidden; }
        .progress-track span { display: block; height: 100%; border-radius: inherit; background: linear-gradient(90deg, #7bd1ff 0%, #f0fbff 100%); }
        .summary-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.8rem; }
        .summary-list { display: grid; gap: 0.75rem; margin-top: 1rem; }
        .summary-item { display: flex; justify-content: space-between; align-items: center; gap: 0.8rem; }
        .alert { border: 0; border-radius: 18px; }
        .alert-info { background: rgba(32, 113, 187, 0.1); color: var(--navy-900); }
        .modal-content { background: rgba(255, 255, 255, 0.98); color: var(--ink-900); }
        .modal-title { font-size: 1.35rem; letter-spacing: -0.04em; }
        .form-label { font-weight: 700; color: var(--ink-900); }
        .form-control {
            min-height: 56px; padding: 0.9rem 1rem; border-radius: 18px; border: 1px solid var(--border-soft);
        }
        .form-control:focus { border-color: rgba(23, 77, 132, 0.45); box-shadow: 0 0 0 4px rgba(39, 99, 168, 0.12); }
        @media (max-width: 991.98px) {
            .page-shell { padding: 20px 16px 32px; }
            .hero-panel, .glass-panel, .summary-card, .req-card, .modal-content { border-radius: 26px; }
            .hero-panel, .glass-panel { padding: 28px 24px; }
        }
        @media (max-width: 767.98px) {
            .hero-row, .req-head, .summary-item { flex-direction: column; align-items: stretch; }
            .hero-copy h1 { font-size: clamp(2.15rem, 11vw, 3.3rem); }
            .summary-grid { grid-template-columns: 1fr; }
            .hero-link, .btn-theme, .btn-soft, .btn-light-soft { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>
<div class="page-shell">
    <section class="hero-panel">
        <div class="hero-row">
            <div class="brand-lockup">
                <img src="../img/logo3.png" alt="RServeS logo" class="brand-logo">
                <div class="brand-mark">
                    <strong>RServeS</strong>
                    <span></span>
                </div>
            </div>

            <a href="logout.php" class="hero-link">
                <i class="fas fa-sign-out-alt"></i>Logout
            </a>
        </div>

        <div class="hero-copy">
            <span class="hero-tag"><i class="fas fa-list-check"></i>Pre-dashboard Checklist</span>
            <h1>Finish each requirement to unlock your student dashboard.</h1>
            <p>Welcome, <strong><?= htmlspecialchars($fullname) ?></strong>. Review your latest submission status below and complete the remaining items to continue into the full student workspace.</p>
        </div>

        <div class="meta-row">
            <span class="meta-pill"><i class="fas fa-school"></i>Lapu-Lapu City College</span>
            <span class="meta-pill"><i class="fas fa-check-circle"></i><?= $verified_count ?> of 3 verified</span>
            <span class="meta-pill"><i class="fas fa-calendar-alt"></i><?= date('F d, Y') ?></span>
        </div>
    </section>

    <div class="row g-4">
        <div class="col-lg-8">
            <section class="glass-panel">
                <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-4">
                    <div>
                        <span class="section-kicker"><i class="fas fa-shield-alt me-2"></i>Requirement Status</span>
                        <h2 class="mt-3 mb-2">Pending requirements</h2>
                        <p class="section-lead mb-0">The page now follows the new student theme while keeping the same background image, links, modals, and checking flow.</p>
                    </div>
                    <div class="progress-pill">
                        <span>Completion</span>
                        <strong><?= $completion_percentage ?>%</strong>
                    </div>
                </div>

                <?php if (isset($_SESSION['flash'])): ?>
                    <div class="alert alert-info mb-4">
                        <i class="fas fa-circle-info me-2"></i><?= htmlspecialchars($_SESSION['flash']); unset($_SESSION['flash']); ?>
                    </div>
                <?php endif; ?>

                <div class="req-list">
                    <article class="req-card">
                        <div class="req-head">
                            <div class="req-icon"><i class="fas fa-file-alt"></i></div>
                            <div class="req-main">
                                <span class="step-pill">Step 1</span>
                                <h3>Enrollment Form</h3>
                                <p>Personal, scholastic, and student profile information required for your return service records.</p>
                                <div class="req-meta">
                                    <span class="care-pill"><i class="fas fa-user-shield"></i>Care of Administrator</span>
                                </div>
                            </div>
                            <span class="status-chip status-<?= htmlspecialchars($enrollment_meta['class']) ?>"><?= htmlspecialchars($enrollment_meta['label']) ?></span>
                        </div>

                        <?php if ($enrollment_status === 'Rejected'): ?>
                            <div class="note-box note-danger"><strong>Revision note:</strong> <?= htmlspecialchars($decline_reason ?? 'Please contact your adviser.') ?></div>
                        <?php elseif ($enrollment_status === 'Verified'): ?>
                            <div class="note-box note-success"><strong>Verified:</strong> Your enrollment form has already been reviewed and approved.</div>
                        <?php elseif ($enrollment_status === 'Pending'): ?>
                            <div class="note-box"><strong>Review in progress:</strong> Your enrollment form was submitted and is waiting for verification.</div>
                        <?php else: ?>
                            <div class="note-box"><strong>Next action:</strong> Complete the enrollment form first so the rest of your requirements can move forward smoothly.</div>
                        <?php endif; ?>

                        <div class="actions-row">
                            <?php if ($enrollment_status === 'Not Submitted'): ?>
                                <a href="enrolment.php" class="btn btn-theme">Fill Out Enrollment Form</a>
                            <?php elseif ($enrollment_status === 'Rejected'): ?>
                                <a href="enrolment.php" class="btn btn-theme">Update Enrollment Form</a>
                            <?php endif; ?>
                        </div>
                    </article>

                    <article class="req-card">
                        <div class="req-head">
                            <div class="req-icon"><i class="fas fa-file-signature"></i></div>
                            <div class="req-main">
                                <span class="step-pill">Step 2</span>
                                <h3>Waiver</h3>
                                <p>Download the waiver template, sign it, then upload the completed PDF or image copy for review.</p>
                                <div class="req-meta">
                                    <span class="care-pill"><i class="fas fa-user-tie"></i>Care of Coordinator</span>
                                </div>
                            </div>
                            <span class="status-chip status-<?= htmlspecialchars($waiver_meta['class']) ?>"><?= htmlspecialchars($waiver_meta['label']) ?></span>
                        </div>

                        <?php if ($waiver_status === 'Verified'): ?>
                            <div class="note-box note-success"><strong>Verified:</strong> Your waiver has already been approved and recorded.</div>
                        <?php elseif ($waiver_status === 'Pending'): ?>
                            <div class="note-box"><strong>Pending review:</strong> Your waiver upload is currently being checked by the coordinator.</div>
                        <?php else: ?>
                            <div class="note-box"><strong>Next action:</strong> Download the waiver template, sign it, and submit the signed copy from the upload modal.</div>
                        <?php endif; ?>

                        <div class="actions-row">
                            <?php if ($waiver_status === 'Not Submitted'): ?>
                                <button type="button" class="btn btn-theme" data-bs-toggle="modal" data-bs-target="#waiverModal">Submit Waiver</button>
                            <?php endif; ?>
                        </div>
                    </article>

                    <article class="req-card">
                        <div class="req-head">
                            <div class="req-icon"><i class="fas fa-handshake"></i></div>
                            <div class="req-main">
                                <span class="step-pill">Step 3</span>
                                <h3>Agreement Form</h3>
                                <p>Submit your signed internship agreement so your deployment documents can be completed on time.</p>
                                <div class="req-meta">
                                    <span class="care-pill"><i class="fas fa-user-tie"></i>Care of Coordinator</span>
                                </div>
                            </div>
                            <span class="status-chip status-<?= htmlspecialchars($agreement_meta['class']) ?>"><?= htmlspecialchars($agreement_meta['label']) ?></span>
                        </div>

                        <?php if ($agreement_status === 'Verified'): ?>
                            <div class="note-box note-success"><strong>Verified:</strong> Your agreement form has already been approved.</div>
                        <?php elseif ($agreement_status === 'Pending'): ?>
                            <div class="note-box"><strong>Pending review:</strong> Your agreement submission is in line for coordinator verification.</div>
                        <?php else: ?>
                            <div class="note-box"><strong>Next action:</strong> Download the agreement template, sign it, and upload your completed file when ready.</div>
                        <?php endif; ?>

                        <div class="actions-row">
                            <?php if ($agreement_status === 'Not Submitted'): ?>
                                <button type="button" class="btn btn-theme" data-bs-toggle="modal" data-bs-target="#agreementModal">Submit Agreement</button>
                            <?php endif; ?>
                        </div>
                    </article>
                </div>
            </section>
        </div>

        <div class="col-lg-4">
            <aside class="summary-card">
                <div class="summary-user mb-3">
                    <div class="avatar">
                        <?php if ($student_photo_path): ?>
                            <img src="<?= htmlspecialchars($student_photo_path) ?>" alt="<?= htmlspecialchars($fullname) ?>">
                        <?php else: ?>
                            <span><?= htmlspecialchars($student_initials ?: 'RS') ?></span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <span class="summary-label">Student Access</span>
                        <strong class="summary-name"><?= htmlspecialchars($fullname) ?></strong>
                        <small class="summary-help">All three requirements must be verified before dashboard access opens.</small>
                    </div>
                </div>

                <h3 class="mb-2">Checklist overview</h3>
                <p class="mb-0">Track your current progress and see who is assigned to review each requirement.</p>

                <div class="progress-box">
                    <div class="progress-row">
                        <span>Overall progress</span>
                        <strong><?= $completion_percentage ?>%</strong>
                    </div>
                    <div class="progress-track">
                        <span style="width: <?= $completion_percentage ?>%;"></span>
                    </div>
                </div>

                <div class="summary-grid">
                    <div class="summary-stat">
                        <span>Verified</span>
                        <strong><?= $verified_count ?>/3</strong>
                    </div>
                    <div class="summary-stat">
                        <span>Pending</span>
                        <strong><?= $pending_count ?></strong>
                    </div>
                    <div class="summary-stat">
                        <span>Needs Action</span>
                        <strong><?= $needs_action_count ?></strong>
                    </div>
                </div>

                <div class="summary-list">
                    <div class="summary-item">
                        <div>
                            <strong>Enrollment Form</strong>
                            <small>Care of Administrator</small>
                        </div>
                        <span class="status-chip status-<?= htmlspecialchars($enrollment_meta['class']) ?>"><?= htmlspecialchars($enrollment_meta['label']) ?></span>
                    </div>
                    <div class="summary-item">
                        <div>
                            <strong>Waiver</strong>
                            <small>Care of Coordinator</small>
                        </div>
                        <span class="status-chip status-<?= htmlspecialchars($waiver_meta['class']) ?>"><?= htmlspecialchars($waiver_meta['label']) ?></span>
                    </div>
                    <div class="summary-item">
                        <div>
                            <strong>Agreement Form</strong>
                            <small>Care of Coordinator</small>
                        </div>
                        <span class="status-chip status-<?= htmlspecialchars($agreement_meta['class']) ?>"><?= htmlspecialchars($agreement_meta['label']) ?></span>
                    </div>
                </div>

                <a href="logout.php" class="btn btn-light-soft mt-3 w-100">
                    <i class="fas fa-sign-out-alt me-2"></i>Sign out
                </a>
            </aside>
        </div>
    </div>
</div>

<!-- Waiver Modal -->
<div class="modal fade" id="waiverModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Submit Waiver</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form action="documents/upload_waiver.php" method="POST" enctype="multipart/form-data">
                    <p class="modal-copy">Download the waiver template, sign it, scan it, then upload the signed PDF or image copy here.</p>
                    <a href="documents/Waiver.docx" class="btn btn-soft mb-3" download><i class="fas fa-download me-2"></i>Download Waiver Template</a>
                    <div class="mb-3">
                        <label class="form-label">Upload Signed Waiver (PDF or image)</label>
                        <input type="file" class="form-control" name="waiver_file" accept=".pdf,image/*" required>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-soft" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-theme">Submit Waiver</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Agreement Modal -->
<div class="modal fade" id="agreementModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Submit Agreement Form</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form action="documents/upload_agreement.php" method="POST" enctype="multipart/form-data">
                    <p class="modal-copy">Download the agreement template, sign it, scan it, then upload the signed PDF or image copy here.</p>
                    <a href="documents/Agreement.docx" class="btn btn-soft mb-3" download><i class="fas fa-download me-2"></i>Download Agreement Template</a>
                    <div class="mb-3">
                        <label class="form-label">Upload Signed Agreement (PDF or image)</label>
                        <input type="file" class="form-control" name="agreement_file" accept=".pdf,image/*" required>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-soft" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-theme">Submit Agreement</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
