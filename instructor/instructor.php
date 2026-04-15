<?php
session_start();
if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['role'], ['Instructor', 'Coordinator'])) {
    header('Location: ../home2.php');
    exit;
}

require_once '../dbconnect.php';
$inst_id = $_SESSION['inst_id'] ?? $_SESSION['coor_id'] ?? 0;
$message = '';

// Handle AJAX approve/reject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'approve_report') {
        $report_id = (int)$_POST['report_id'];
        $stmt = $conn->prepare("UPDATE accomplishment_reports SET status = 'Approved' WHERE id = ? AND assigner_id = ?");
        $stmt->bind_param("ii", $report_id, $inst_id);
        $success = $stmt->execute();
        echo json_encode(['success' => $success, 'message' => $success ? 'Report approved!' : 'Error approving']);
        exit;
    } elseif ($_POST['action'] === 'reject_report') {
        $report_id = (int)$_POST['report_id'];
        $stmt = $conn->prepare("UPDATE accomplishment_reports SET status = 'Rejected' WHERE id = ? AND assigner_id = ?");
        $stmt->bind_param("ii", $report_id, $inst_id);
        $success = $stmt->execute();
        echo json_encode(['success' => $success, 'message' => $success ? 'Report rejected!' : 'Error rejecting']);
        exit;
    } elseif ($_POST['action'] === 'delete_task') {
        $task_id = (int)$_POST['task_id'];
        $stmt = $conn->prepare("UPDATE tasks SET is_deleted = 1 WHERE task_id = ? AND instructor_id = ?");
        $stmt->bind_param("ii", $task_id, $inst_id);
        $success = $stmt->execute();
        echo json_encode(['success' => $success]);
        exit;
    }
}

// Create task
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_task'])) {
    $title = trim($_POST['title']);
    $student_id = (int)$_POST['student_id'];
    $description = trim($_POST['description']);
    $duration = trim($_POST['duration']);

    if ($title && $student_id && in_array($duration, ['Within a Day', 'Within a Week', 'Within a Month'])) {
        $stmt = $conn->prepare("INSERT INTO tasks (title, description, duration, instructor_id, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param("sssi", $title, $description, $duration, $inst_id);
        
        if ($stmt->execute()) {
            $task_id = $stmt->insert_id;
            $st_stmt = $conn->prepare("INSERT INTO student_tasks (task_id, student_id, status) VALUES (?, ?, 'Pending')");
            $st_stmt->bind_param("ii", $task_id, $student_id);
            $st_stmt->execute();
            $st_stmt->close();
            $stmt->close();
            $message = "Task '$title' created successfully!";
        } else {
            $message = 'Error: ' . $conn->error;
        }
    } else {
        $message = 'Please fill all required fields.';
    }
}

// Edit task
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_task'])) {
    $task_id = (int)$_POST['task_id'];
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $duration = trim($_POST['duration']);

    $stmt = $conn->prepare("UPDATE tasks SET title = ?, description = ?, duration = ? WHERE task_id = ? AND instructor_id = ?");
    $stmt->bind_param("ssssi", $title, $description, $duration, $task_id, $inst_id);
    
    if ($stmt->execute()) {
        $message = "Task updated successfully!";
    } else {
        $message = 'Error updating task.';
    }
    $stmt->close();
}

// Fetch data
$students = [];
$stmt = $conn->prepare("SELECT stud_id, firstname, lastname FROM students WHERE department_id IN (SELECT department_id FROM instructors WHERE inst_id = ?) ORDER BY lastname");
$stmt->bind_param("i", $inst_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) $students[] = $row;
$stmt->close();

$tasks = [];
$stmt = $conn->prepare("
    SELECT t.task_id, t.title, t.description, t.duration, t.created_at, s.firstname, s.lastname, st.status
    FROM tasks t 
    JOIN student_tasks st ON t.task_id = st.task_id
    JOIN students s ON st.student_id = s.stud_id
    WHERE t.instructor_id = ? AND COALESCE(t.is_deleted, 0) = 0
    ORDER BY t.created_at DESC
");
$stmt->bind_param("i", $inst_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) $tasks[] = $row;
$stmt->close();

$reports = [];
$stmt = $conn->prepare("
    SELECT ar.id, ar.activity, ar.work_date, ar.hours, ar.status, ar.photo, s.firstname, s.lastname
    FROM accomplishment_reports ar
    JOIN students s ON ar.student_id = s.stud_id
    WHERE ar.assigner_id = ? 
    ORDER BY ar.id DESC LIMIT 20
");
$stmt->bind_param("i", $inst_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) $reports[] = $row;
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Adviser Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
        <div class="container">
            <span class="navbar-brand"><i class="fas fa-user-tie me-2"></i><?= htmlspecialchars($_SESSION['firstname'] . ' ' . $_SESSION['lastname']) ?></span>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show position-fixed" style="top: 80px; right: 20px; z-index: 9999; min-width: 300px;" role="alert">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Create Task Section -->
        <div class="row mb-5">
            <div class="col-md-6">
                <div class="card shadow">
                    <div class="card-header bg-success text-white">
                        <h5><i class="fas fa-plus me-2"></i>Create New Task</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="create_task" value="1">
                            <div class="mb-3">
                                <label class="form-label">Title <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="title" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Student <span class="text-danger">*</span></label>
                                <select class="form-select" name="student_id" required>
                                    <option value="">Choose...</option>
                                    <?php foreach ($students as $s): ?>
                                        <option value="<?= $s['stud_id'] ?>"><?= htmlspecialchars($s['firstname'].' '.$s['lastname']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Duration <span class="text-danger">*</span></label>
                                <select class="form-select" name="duration" required>
                                    <option value="">Select...</option>
                                    <option value="Within a Day">Within a Day</option>
                                    <option value="Within a Week">Within a Week</option>
                                    <option value="Within a Month">Within a Month</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="description" rows="2"></textarea>
                            </div>
                            <button type="submit" class="btn btn-success w-100"><i class="fas fa-plus me-2"></i>Create Task</button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card shadow">
                    <div class="card-header bg-info text-white">
                        <h5><i class="fas fa-edit me-2"></i>Quick Actions</h5>
                    </div>
                    <div class="card-body text-center">
                        <p class="text-muted mb-4">View & manage assigned tasks and reports</p>
                        <a href="#tasks" class="btn btn-outline-primary me-2 mb-2">My Tasks</a>
                        <a href="#reports" class="btn btn-outline-warning mb-2">Reports</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tasks Section -->
        <div class="row mb-5">
            <div class="col-12">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h5><i class="fas fa-tasks me-2"></i>My Assigned Tasks</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Title</th>
                                        <th>Student</th>
                                        <th>Duration</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tasks as $task): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($task['title']) ?></td>
                                            <td><?= htmlspecialchars($task['firstname'].' '.$task['lastname']) ?></td>
                                            <td><span class="badge bg-secondary"><?= htmlspecialchars($task['duration']) ?></span></td>
                                            <td><span class="badge bg-<?= $task['status'] === 'Pending' ? 'warning' : 'success' ?>"><?= htmlspecialchars($task['status']) ?></span></td>
                                            <td><?= date('M j', strtotime($task['created_at'])) ?></td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-primary" onclick="editTask(<?= $task['task_id'] ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-sm btn-outline-danger" onclick="confirmDeleteTask(<?= $task['task_id'] ?>)">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($tasks)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted py-4">No tasks assigned yet. Create one above!</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Accomplishment Reports -->
        <div class="row">
            <div class="col-12">
                <div class="card shadow">
                    <div class="card-header bg-warning text-dark">
                        <h5><i class="fas fa-file-alt me-2"></i>Accomplishment Reports (Pending)</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>Activity</th>
                                        <th>Date</th>
                                        <th>Hours</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($reports as $report): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($report['firstname'].' '.$report['lastname']) ?></td>
                                            <td><?= htmlspecialchars(substr($report['activity'], 0, 40)) ?>...</td>
                                            <td><?= date('M j', strtotime($report['work_date'])) ?></td>
                                            <td><?= number_format($report['hours'], 1) ?>h</td>
                                            <td>
                                                <span class="badge bg-<?= $report['status'] === 'Pending' ? 'warning text-dark' : ($report['status'] === 'Approved' ? 'success' : 'danger') ?>">
                                                    <?= htmlspecialchars($report['status']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($report['status'] === 'Pending'): ?>
                                                    <button class="btn btn-sm btn-success me-1" onclick="approveReport(<?= $report['id'] ?>)">
                                                        <i class="fas fa-check"></i> Approve
                                                    </button>
                                                    <button class="btn btn-sm btn-danger" onclick="rejectReport(<?= $report['id'] ?>)">
                                                        <i class="fas fa-times"></i> Reject
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($reports)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted py-4">No reports pending review.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Task Modal -->
    <div class="modal fade" id="editTaskModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Task</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="edit_task" value="1" id="edit_task_id">
                        <div class="mb-3">
                            <label class="form-label">Title</label>
                            <input type="text" class="form-control" name="title" id="edit_title" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Duration</label>
                            <select class="form-select" name="duration" id="edit_duration" required>
                                <option value="Within a Day">Within a Day</option>
                                <option value="Within a Week">Within a Week</option>
                                <option value="Within a Month">Within a Month</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" id="edit_description" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Task</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Confirm Delete Modal -->
    <div class="modal fade" id="confirmDeleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-danger">Confirm Delete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this task? This cannot be undone.</p>
                    <input type="hidden" id="delete_task_id">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="deleteTask()">Delete Task</button>
                </div>
            </div>
        </div>
    </div>

    <!-- End Session Confirmation -->
    <div class="modal fade" id="endSessionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">End Student Session?</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>This will mark the student's residency session as complete. Are you sure?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmEndSession">End Session</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toast notifications
        function showToast(message, type = 'success') {
            const toastHtml = `
                <div class="toast align-items-center text-white bg-${type === 'success' ? 'success' : 'danger'} border-0 position-fixed" style="top: 80px; right: 20px; z-index: 9999;" role="alert">
                    <div class="d-flex">
                        <div class="toast-body">${message}</div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', toastHtml);
            const toastEl = document.querySelector('.toast:last-child');
            const toast = new bootstrap.Toast(toastEl);
            toast.show();
            toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
        }

        // Approve report AJAX
        function approveReport(reportId) {
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=approve_report&report_id=${reportId}`
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(data.message || 'Error', 'danger');
                }
            });
        }

        // Reject report AJAX
        function rejectReport(reportId) {
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=reject_report&report_id=${reportId}`
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(data.message || 'Error', 'danger');
                }
            });
        }

        // Edit task
        function editTask(taskId) {
            // Simple: reload with edit mode or fetch data
            if (confirm('Edit task? (Placeholder - full edit form TBD)')) {
                showToast('Edit functionality coming soon!');
            }
        }

        // Confirm delete
        function confirmDeleteTask(taskId) {
            document.getElementById('delete_task_id').value = taskId;
            new bootstrap.Modal(document.getElementById('confirmDeleteModal')).show();
        }

        function deleteTask() {
            const taskId = document.getElementById('delete_task_id').value;
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=delete_task&task_id=${taskId}`
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast('Task deleted!');
                    setTimeout(() => location.reload(), 800);
                } else {
                    showToast('Delete failed', 'danger');
                }
            });
            bootstrap.Modal.getInstance(document.getElementById('confirmDeleteModal')).hide();
        }

        // End session confirm
        document.getElementById('confirmEndSession')?.addEventListener('click', () => {
            if (confirm('End ALL student sessions? This action cannot be undone.')) {
                showToast('End session functionality coming soon!');
            }
        });

        // Auto-hide alert on create success
        <?php if (isset($message) && strpos($message, 'successfully') !== false): ?>
            setTimeout(() => {
                document.querySelector('.alert')?.classList.add('fade', 'show');
            }, 300);
        <?php endif; ?>
    </script>
</body>
</html>
