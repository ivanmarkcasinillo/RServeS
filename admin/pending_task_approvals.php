<?php
session_start();
include "../dbconnect.php";
require_once "../send_email.php";

if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['role'], ['Administrator', 'Instructor'])) {
    header("Location: ../home2.php");
    exit;
}

$is_admin = $_SESSION['role'] === 'Administrator';
$instructor_id = $_SESSION['inst_id'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['approve_task']) || isset($_POST['reject_task'])) {
        $stask_ids = array_map('intval', $_POST['stask_ids'] ?? []);
        $reject_reason = trim($_POST['reject_reason'] ?? '');
        $action = $_POST['action'] ?? '';

        if (empty($stask_ids)) {
            $_SESSION['flash'] = 'No tasks selected.';
        } else {
            $conn->begin_transaction();
            try {
                $count = 0;
                foreach ($stask_ids as $stask_id) {
                    $check_stmt = $conn->prepare("
                        SELECT st.*, t.title, s.firstname, s.lastname, s.student_number, s.stud_id, 
                               e.status as enrollment_status
                        FROM student_tasks st
                        JOIN tasks t ON st.task_id = t.task_id
                        JOIN students s ON st.student_id = s.stud_id
                        LEFT JOIN rss_enrollments e ON s.stud_id = e.student_id AND e.status = 'Verified'
                        WHERE st.stask_id = ? AND st.approval_status = 'Pending Approval'
                    ");
                    $check_stmt->bind_param("i", $stask_id);
                    $check_stmt->execute();
                    $task = $check_stmt->get_result()->fetch_assoc();
                    $check_stmt->close();

                    if (!$task || ($is_admin === false && $task['instructor_id'] != $instructor_id)) continue;

                    // Enrollment check
                    if ($task['enrollment_status'] !== 'Verified') continue;

                    if ($action === 'approve') {
                        $stmt = $conn->prepare("
                            UPDATE student_tasks 
                            SET approval_status = 'Approved', approved_by = ?, approved_at = NOW(), status = 'Pending'
                            WHERE stask_id = ?
                        ");
                        $stmt->bind_param("ii", $instructor_id ?: $_SESSION['adm_id'], $stask_id);
                        $stmt->execute();
                        $stmt->close();

                        // Notify student
                        $student = rserves_fetch_student_email_recipient($conn, $task['stud_id']);
                        if ($student) {
                            $body = rserves_notification_build_body(
                                rserves_notification_recipient_name($student),
                                'Task approved by your adviser.',
                                ['Task' => $task['title']]
                            );
                            rserves_send_bulk_notification_email([$student], 'Task Approved', $body);
                        }
                    } elseif ($action === 'reject' && $reject_reason !== '') {
                        $stmt = $conn->prepare("
                            UPDATE student_tasks 
                            SET approval_status = 'Rejected', reject_reason = ?, reject_by = ?, rejected_at = NOW()
                            WHERE stask_id = ?
                        ");
                        $stmt->bind_param("sii", $reject_reason, $instructor_id ?: $_SESSION['adm_id'], $stask_id);
                        $stmt->execute();
                        $stmt->close();

                        // Notify student
                        $student = rserves_fetch_student_email_recipient($conn, $task['stud_id']);
                        if ($student) {
                            $body = rserves_notification_build_body(
                                rserves_notification_recipient_name($student),
                                'Task rejected by your adviser.',
                                ['Task' => $task['title'], 'Reason' => $reject_reason]
                            );
                            rserves_send_bulk_notification_email([$student], 'Task Rejected', $body);
                        }
                    }
                    $count++;
                }
                $conn->commit();
                $_SESSION['flash'] = "$count task(s) processed.";
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['flash'] = 'Error: ' . $e->getMessage();
            }
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Fetch pending tasks
$where = $is_admin ? "" : "AND t.instructor_id = $instructor_id";
$query = "
    SELECT st.stask_id, st.status, st.approval_status, s.student_number, s.firstname, s.lastname, 
           s.year_level, s.section, t.title, t.description, t.duration, t.created_at,
           i.firstname as inst_fname, i.lastname as inst_lname, t.verbal_assigner_name,
           CASE WHEN t.created_by_student = s.stud_id THEN 'Verbal (Student)' ELSE 'Adviser Assigned' END as type
    FROM student_tasks st
    JOIN tasks t ON st.task_id = t.task_id
    JOIN students s ON st.student_id = s.stud_id
    LEFT JOIN instructors i ON t.instructor_id = i.inst_id
    WHERE st.approval_status = 'Pending Approval' $where
    ORDER BY st.stask_id DESC
";
$pending_tasks = $conn->query($query) or die($conn->error);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Pending Task Approvals</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="fas fa-tasks"></i> Pending Task Approvals (<?= $pending_tasks->num_rows ?>)</h2>
        <a href="admin_dashboard.php" class="btn btn-secondary">← Back to Dashboard</a>
    </div>

    <?php if (isset($_SESSION['flash'])): ?>
        <div class="alert alert-info"><?= $_SESSION['flash'] ?></div>
        <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <form method="POST" id="approvalForm">
        <div class="card shadow">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th><input type="checkbox" id="selectAll"></th>
                            <th>Student</th>
                            <th>Task</th>
                            <th>Type</th>
                            <th>Section</th>
                            <th>Created</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($task = $pending_tasks->fetch_assoc()): ?>
                            <tr>
                                <td><input type="checkbox" name="stask_ids[]" value="<?= $task['stask_id'] ?>"></td>
                                <td><?= htmlspecialchars($task['firstname'].' '.$task['lastname'].' ('.$task['student_number'].')') ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($task['title']) ?></strong><br>
                                    <small><?= htmlspecialchars(substr($task['description'], 0, 100)) ?>...</small>
                                </td>
                                <td>
                                    <span class="badge bg-info"><?= htmlspecialchars($task['type']) ?></span><br>
                                    <small><?= htmlspecialchars($task['inst_fname'] ?? '') ?> <?= htmlspecialchars($task['inst_lname'] ?? '') ?></small>
                                </td>
                                <td><?= htmlspecialchars($task['year_level'].' - '.$task['section']) ?></td>
                                <td><?= date('M d, Y', strtotime($task['created_at'])) ?></td>
                                <td>
                                    <button type="submit" name="action" value="approve" class="btn btn-sm btn-success" onclick="setRejectReason('')">Approve</button>
                                    <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#rejectModal<?= $task['stask_id'] ?>">Reject</button>
                                </td>
                            </tr>

                            <!-- Reject Modal per task -->
                            <div class="modal fade" id="rejectModal<?= $task['stask_id'] ?>">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Reject Task</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST">
                                            <input type="hidden" name="stask_ids[]" value="<?= $task['stask_id'] ?>">
                                            <input type="hidden" name="action" value="reject">
                                            <div class="modal-body">
                                                <textarea name="reject_reason" class="form-control" placeholder="Reason for rejection (required)" required rows="3"></textarea>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-danger">Reject Task</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                        </tr>
                    </tbody>
                                <td><input type="checkbox" name="stask_ids[]" value="<?= $task['stask_id'] ?>"></td>
                                <td><?= htmlspecialchars($task['firstname'].' '.$task['lastname'].' ('.$task['student_number'].')') ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($task['title']) ?></strong><br>
                                    <small><?= htmlspecialchars(substr($task['description'], 0, 100)) ?>...</small>
                                </td>
                                <td>
                                    <span class="badge bg-info"><?= htmlspecialchars($task['type']) ?></span><br>
                                    <small><?= htmlspecialchars($task['inst_fname'] ?? '') ?> <?= htmlspecialchars($task['inst_lname'] ?? '') ?></small>
                                </td>
                                <td><?= htmlspecialchars($task['year_level'].' - '.$task['section']) ?></td>
                                <td><?= date('M d, Y', strtotime($task['created_at'])) ?></td>
                                <td>
                                    <button type="submit" name="action" value="approve" class="btn btn-sm btn-success" onclick="setRejectReason('')">Approve</button>
                                    <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#rejectModal<?= $task['stask_id'] ?>">Reject</button>
                                </td>
                            </tr>

                            <!-- Reject Modal per task -->
                            <div class="modal fade" id="rejectModal<?= $task['stask_id'] ?>">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Reject Task</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST">
                                            <input type="hidden" name="stask_ids[]" value="<?= $task['stask_id'] ?>">
                                            <input type="hidden" name="action" value="reject">
                                            <div class="modal-body">
                                                <textarea name="reject_reason" class="form-control" placeholder="Reason for rejection (required)" required rows="3"></textarea>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-danger">Reject Task</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($pending_tasks->num_rows > 0): ?>
                <div class="card-footer">
                    <input type="checkbox" id="selectAll" class="form-check-input me-2">
                    <label class="form-check-label me-3">Select All</label>
                    <button type="submit" name="action" value="approve" class="btn btn-success me-2">Bulk Approve Selected</button>
                    <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#bulkRejectModal">Bulk Reject Selected</button>
                </div>
            <?php endif; ?>
        </div>
    </form>

    <!-- Bulk Reject Modal -->
    <div class="modal fade" id="bulkRejectModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Bulk Reject Tasks</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="reject">
                    <div class="modal-body">
                        <textarea name="reject_reason" class="form-control" placeholder="Common reason for rejection (required)" required rows="3"></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Reject Selected Tasks</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('selectAll').addEventListener('change', function() {
    document.querySelectorAll('input[name="stask_ids[]"]').forEach(cb => cb.checked = this.checked);
});
</script>
</body>
</html>
