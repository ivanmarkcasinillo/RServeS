<?php
session_start();
require "dbconnect.php";
require_once __DIR__ . "/task_backend.php";

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Student') {
    header("Location: ../home2.php");
    exit;
}

$student_id = intval($_SESSION['stud_id'] ?? 0);
rserves_student_ensure_task_schema($conn);

$stmt = $conn->prepare("
    SELECT firstname, lastname, mi, department_id
    FROM students
    WHERE stud_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

$department_dashboards = [
    1 => 'student_college_of_education_dashboard.php',
    2 => 'student_college_of_technology_dashboard.php',
    3 => 'student_college_of_hospitality_and_tourism_management_dashboard.php',
];

$dashboard_file = $department_dashboards[intval($student['department_id'] ?? 0)] ?? 'student_college_of_technology_dashboard.php';
$student_name = trim(
    (string) ($student['firstname'] ?? '') . ' ' .
    (!empty($student['mi']) ? strtoupper(substr((string) $student['mi'], 0, 1)) . '. ' : '') .
    (string) ($student['lastname'] ?? '')
);

$archived_tasks = rserves_fetch_student_archived_tasks($conn, $student_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archived Tasks | RServeS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body {
            min-height: 100vh;
            margin: 0;
            font-family: 'Manrope', sans-serif;
            background:
                radial-gradient(circle at top left, rgba(88, 164, 255, 0.12), transparent 28%),
                linear-gradient(180deg, #eef5fb 0%, #f9fbfd 100%);
            color: #102033;
        }

        h1, h2, h3, h4, h5, h6 {
            font-family: 'Sora', sans-serif;
        }

        .page-shell {
            max-width: 1080px;
            margin: 0 auto;
            padding: 32px 20px 48px;
        }

        .hero-card,
        .task-card {
            border: 1px solid rgba(16, 32, 51, 0.08);
            border-radius: 26px;
            background: rgba(255, 255, 255, 0.94);
            box-shadow: 0 24px 56px rgba(16, 32, 51, 0.08);
        }

        .hero-card {
            padding: 28px;
            margin-bottom: 24px;
            background:
                linear-gradient(145deg, rgba(9, 57, 110, 0.98) 0%, rgba(24, 102, 176, 0.92) 100%);
            color: #fff;
        }

        .hero-card p {
            margin: 0;
            color: rgba(235, 244, 255, 0.86);
        }

        .task-card {
            padding: 22px;
            margin-bottom: 16px;
        }

        .task-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .empty-card {
            padding: 48px 24px;
            text-align: center;
            color: #607086;
        }
    </style>
</head>
<body>
    <div class="page-shell">
        <div class="hero-card">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                <div>
                    <div class="text-uppercase small fw-bold mb-2" style="letter-spacing: 0.18em;">Task History</div>
                    <h1 class="h3 mb-2">Archived Tasks</h1>
                    <p><?= htmlspecialchars($student_name !== '' ? $student_name : 'Student') ?> can review tasks that were archived or removed from the active list.</p>
                </div>
                <a href="<?= htmlspecialchars($dashboard_file) ?>?view=tasks" class="btn btn-light">
                    <i class="fas fa-arrow-left me-2"></i>Back to Tasks
                </a>
            </div>
        </div>

        <?php if (empty($archived_tasks)): ?>
            <div class="hero-card empty-card" style="background: rgba(255,255,255,0.94); color: #607086;">
                <i class="fas fa-box-archive fa-3x mb-3 text-secondary"></i>
                <h2 class="h5 mb-2">No archived tasks yet</h2>
                <p class="mb-0">Archived or deleted tasks will appear here once you remove them from your active task list.</p>
            </div>
        <?php else: ?>
            <?php foreach ($archived_tasks as $task): ?>
                <?php $state = (string) ($task['student_view_status'] ?? 'archived'); ?>
                <div class="task-card">
                    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
                        <div>
                            <h2 class="h5 mb-2"><?= htmlspecialchars((string) ($task['title'] ?? 'Untitled Task')) ?></h2>
                            <div class="task-meta mb-3">
                                <span class="badge bg-secondary"><?= htmlspecialchars((string) ($task['duration'] ?? 'No Duration')) ?></span>
                                <span class="badge <?= $state === 'deleted' ? 'bg-danger' : 'bg-warning text-dark' ?>">
                                    <?= $state === 'deleted' ? 'Deleted' : 'Archived' ?>
                                </span>
                                <?php if (!empty($task['inst_fname'])): ?>
                                    <span class="badge bg-primary">
                                        <?= htmlspecialchars($task['inst_fname'] . ' ' . $task['inst_lname']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <p class="mb-2 text-muted"><?= htmlspecialchars((string) ($task['description'] ?: 'No description')) ?></p>
                        </div>
                        <div class="text-lg-end text-muted small">
                            <div>Created: <?= !empty($task['created_at']) ? date('M d, Y h:i A', strtotime((string) $task['created_at'])) : 'N/A' ?></div>
                            <div>
                                <?= $state === 'deleted' ? 'Deleted' : 'Archived' ?>:
                                <?= !empty($task['student_state_changed_at']) ? date('M d, Y h:i A', strtotime((string) $task['student_state_changed_at'])) : 'N/A' ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
</html>
