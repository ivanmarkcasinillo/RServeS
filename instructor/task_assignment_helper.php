<?php

if (!function_exists('rserves_instructor_extract_student_task_ids_from_activity')) {
    function rserves_instructor_required_hours(): float
    {
        return 320.0;
    }

    function rserves_instructor_ensure_task_due_date_column(mysqli $conn): void
    {
        $check_col = $conn->query("SHOW COLUMNS FROM tasks LIKE 'due_date'");
        if ($check_col && $check_col->num_rows === 0) {
            $conn->query("ALTER TABLE tasks ADD COLUMN due_date DATE NULL AFTER duration");
        }
    }

    function rserves_instructor_ensure_student_task_meta_columns(mysqli $conn): void
    {
        $columns = [
            'assigned_at' => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
            'completed_at' => 'DATETIME NULL',
            'student_view_status' => "VARCHAR(20) NOT NULL DEFAULT 'active'",
            'student_state_changed_at' => 'DATETIME NULL',
        ];

        foreach ($columns as $column => $definition) {
            $check_col = $conn->query("SHOW COLUMNS FROM student_tasks LIKE '{$column}'");
            if ($check_col && $check_col->num_rows === 0) {
                $conn->query("ALTER TABLE student_tasks ADD COLUMN {$column} {$definition}");
            }
        }
    }

    function rserves_instructor_ensure_student_announcements_table(mysqli $conn): void
    {
        $conn->query("
            CREATE TABLE IF NOT EXISTS student_announcements (
                announcement_id INT AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                instructor_id INT NOT NULL,
                subject VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_student_announcements_student (student_id),
                INDEX idx_student_announcements_instructor (instructor_id)
            )
        ");
    }

    function rserves_instructor_extract_student_task_ids_from_activity(string $activity): array
    {
        if (preg_match_all('/\[TaskID:(\d+)\]/', $activity, $matches) === false || empty($matches[1])) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $matches[1])));
    }

    function rserves_instructor_get_accomplishment_student_task_ids(mysqli $conn, int $accomplishment_id): array
    {
        $stmt = $conn->prepare("
            SELECT student_task_id, activity
            FROM accomplishment_reports
            WHERE id = ?
            LIMIT 1
        ");

        if (!$stmt) {
            return [];
        }

        $stmt->bind_param("i", $accomplishment_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return [];
        }

        $student_task_ids = [];
        $direct_student_task_id = intval($row['student_task_id'] ?? 0);
        if ($direct_student_task_id > 0) {
            $student_task_ids[] = $direct_student_task_id;
        }

        $activity = (string)($row['activity'] ?? '');
        $student_task_ids = array_merge(
            $student_task_ids,
            rserves_instructor_extract_student_task_ids_from_activity($activity)
        );

        return array_values(array_unique(array_filter($student_task_ids)));
    }

    function rserves_instructor_student_task_has_completed_report(mysqli $conn, int $student_task_id): bool
    {
        $task_pattern = '%[TaskID:' . $student_task_id . ']%';
        $stmt = $conn->prepare("
            SELECT 1
            FROM accomplishment_reports
            WHERE (student_task_id = ? OR activity LIKE ?)
              AND status IN ('Approved', 'Verified')
            LIMIT 1
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param("is", $student_task_id, $task_pattern);
        $stmt->execute();
        $has_completed_report = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $has_completed_report;
    }

    function rserves_instructor_student_task_has_pending_report(mysqli $conn, int $student_task_id): bool
    {
        $task_pattern = '%[TaskID:' . $student_task_id . ']%';
        $stmt = $conn->prepare("
            SELECT 1
            FROM accomplishment_reports
            WHERE (student_task_id = ? OR activity LIKE ?)
              AND status = 'Pending'
            LIMIT 1
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param("is", $student_task_id, $task_pattern);
        $stmt->execute();
        $has_pending_report = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $has_pending_report;
    }

    function rserves_instructor_refresh_student_task_status(mysqli $conn, int $student_task_id): void
    {
        $stmt = $conn->prepare("
            SELECT status
            FROM student_tasks
            WHERE stask_id = ?
            LIMIT 1
        ");

        if (!$stmt) {
            throw new RuntimeException($conn->error);
        }

        $stmt->bind_param("i", $student_task_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return;
        }

        $current_status = (string)($row['status'] ?? 'Pending');
        $has_completed_report = rserves_instructor_student_task_has_completed_report($conn, $student_task_id);
        $has_pending_report = !$has_completed_report && rserves_instructor_student_task_has_pending_report($conn, $student_task_id);

        if ($has_completed_report) {
            $update_stmt = $conn->prepare("
                UPDATE student_tasks
                SET status = 'Completed',
                    completed_at = COALESCE(completed_at, NOW())
                WHERE stask_id = ?
            ");
        } elseif ($has_pending_report) {
            $update_stmt = $conn->prepare("
                UPDATE student_tasks
                SET status = 'In Progress',
                    completed_at = NULL
                WHERE stask_id = ?
            ");
        } elseif ($current_status !== 'Expired') {
            $update_stmt = $conn->prepare("
                UPDATE student_tasks
                SET status = 'Pending',
                    completed_at = NULL
                WHERE stask_id = ?
            ");
        } else {
            return;
        }

        if (!$update_stmt) {
            throw new RuntimeException($conn->error);
        }

        $update_stmt->bind_param("i", $student_task_id);
        if (!$update_stmt->execute()) {
            $update_error = $update_stmt->error;
            $update_stmt->close();
            throw new RuntimeException($update_error);
        }
        $update_stmt->close();
    }

    function rserves_instructor_sync_accomplishment_student_tasks(mysqli $conn, int $accomplishment_id): void
    {
        $student_task_ids = rserves_instructor_get_accomplishment_student_task_ids($conn, $accomplishment_id);

        foreach ($student_task_ids as $student_task_id) {
            rserves_instructor_refresh_student_task_status($conn, $student_task_id);
        }
    }

    function rserves_instructor_sync_task_assignments_for_instructor(mysqli $conn, int $instructor_id): void
    {
        rserves_instructor_ensure_student_task_meta_columns($conn);

        $completed_stmt = $conn->prepare("
            UPDATE student_tasks st
            INNER JOIN tasks t ON st.task_id = t.task_id
            SET st.status = 'Completed',
                st.completed_at = COALESCE(st.completed_at, NOW())
            WHERE t.instructor_id = ?
              AND EXISTS (
                  SELECT 1
                  FROM accomplishment_reports ar
                  WHERE ar.student_id = st.student_id
                    AND (ar.student_task_id = st.stask_id OR ar.activity LIKE CONCAT('%[TaskID:', st.stask_id, ']%'))
                    AND ar.status IN ('Approved', 'Verified')
              )
        ");

        if (!$completed_stmt) {
            throw new RuntimeException($conn->error);
        }

        $completed_stmt->bind_param("i", $instructor_id);
        if (!$completed_stmt->execute()) {
            $completed_error = $completed_stmt->error;
            $completed_stmt->close();
            throw new RuntimeException($completed_error);
        }
        $completed_stmt->close();

        $in_progress_stmt = $conn->prepare("
            UPDATE student_tasks st
            INNER JOIN tasks t ON st.task_id = t.task_id
            SET st.status = 'In Progress',
                st.completed_at = NULL
            WHERE t.instructor_id = ?
              AND st.status <> 'Expired'
              AND EXISTS (
                  SELECT 1
                  FROM accomplishment_reports ar
                  WHERE ar.student_id = st.student_id
                    AND (ar.student_task_id = st.stask_id OR ar.activity LIKE CONCAT('%[TaskID:', st.stask_id, ']%'))
                    AND ar.status = 'Pending'
              )
              AND NOT EXISTS (
                  SELECT 1
                  FROM accomplishment_reports ar
                  WHERE ar.student_id = st.student_id
                    AND (ar.student_task_id = st.stask_id OR ar.activity LIKE CONCAT('%[TaskID:', st.stask_id, ']%'))
                    AND ar.status IN ('Approved', 'Verified')
              )
        ");

        if (!$in_progress_stmt) {
            throw new RuntimeException($conn->error);
        }

        $in_progress_stmt->bind_param("i", $instructor_id);
        if (!$in_progress_stmt->execute()) {
            $in_progress_error = $in_progress_stmt->error;
            $in_progress_stmt->close();
            throw new RuntimeException($in_progress_error);
        }
        $in_progress_stmt->close();

        $pending_stmt = $conn->prepare("
            UPDATE student_tasks st
            INNER JOIN tasks t ON st.task_id = t.task_id
            SET st.status = 'Pending',
                st.completed_at = NULL
            WHERE t.instructor_id = ?
              AND st.status <> 'Expired'
              AND NOT EXISTS (
                  SELECT 1
                  FROM accomplishment_reports ar
                  WHERE ar.student_id = st.student_id
                    AND (ar.student_task_id = st.stask_id OR ar.activity LIKE CONCAT('%[TaskID:', st.stask_id, ']%'))
                    AND ar.status IN ('Approved', 'Verified', 'Pending')
              )
        ");

        if (!$pending_stmt) {
            throw new RuntimeException($conn->error);
        }

        $pending_stmt->bind_param("i", $instructor_id);
        if (!$pending_stmt->execute()) {
            $pending_error = $pending_stmt->error;
            $pending_stmt->close();
            throw new RuntimeException($pending_error);
        }
        $pending_stmt->close();
    }

    function rserves_instructor_get_student_approved_hours(mysqli $conn, int $student_id): float
    {
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(hours), 0) AS approved_hours
            FROM accomplishment_reports
            WHERE student_id = ?
              AND status = 'Approved'
        ");

        if (!$stmt) {
            return 0.0;
        }

        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (float) ($row['approved_hours'] ?? 0);
    }

    function rserves_instructor_student_can_end_session(mysqli $conn, int $student_id): bool
    {
        return rserves_instructor_get_student_approved_hours($conn, $student_id) >= rserves_instructor_required_hours();
    }

    function rserves_instructor_send_accomplishment_status_email(mysqli $conn, int $accomplishment_id, string $status): void
    {
        $notify_stmt = $conn->prepare("
            SELECT student_id, activity, work_date
            FROM accomplishment_reports
            WHERE id = ?
            LIMIT 1
        ");

        if (!$notify_stmt) {
            return;
        }

        $notify_stmt->bind_param("i", $accomplishment_id);
        $notify_stmt->execute();
        $notify_row = $notify_stmt->get_result()->fetch_assoc();
        $notify_stmt->close();

        if (empty($notify_row['student_id'])) {
            return;
        }

        $student = rserves_fetch_student_email_recipient($conn, intval($notify_row['student_id']));
        if (!$student) {
            return;
        }

        $activity_label = trim((string) preg_replace('/\[\s*TaskID\s*:\s*\d+\s*\]/i', '', (string) ($notify_row['activity'] ?? '')));
        $normalized_status = strtolower($status) === 'approved' ? 'Approved' : 'Rejected';
        $body = rserves_notification_build_body(
            rserves_notification_recipient_name($student),
            "Your accomplishment report was {$normalized_status}.",
            [
                'Work Date' => (string) ($notify_row['work_date'] ?? ''),
                'Activity' => $activity_label !== '' ? $activity_label : 'RSS accomplishment',
                'Status' => $normalized_status,
            ]
        );

        rserves_send_bulk_notification_email([$student], "Accomplishment {$normalized_status}", $body);
    }

    function rserves_instructor_update_accomplishment_status(mysqli $conn, int $instructor_id, int $accomplishment_id, string $status): array
    {
        $normalized_status = strtolower($status) === 'approved' ? 'Approved' : 'Rejected';
        $success_message = $normalized_status === 'Approved' ? 'Accomplishment approved!' : 'Accomplishment rejected!';
        $failure_message = $normalized_status === 'Approved'
            ? 'Accomplishment approval failed. Please try again.'
            : 'Accomplishment rejection failed. Please try again.';

        try {
            $conn->begin_transaction();

            if ($normalized_status === 'Approved') {
                $stmt = $conn->prepare("
                    UPDATE accomplishment_reports
                    SET status = 'Approved',
                        approver_id = ?
                    WHERE id = ?
                ");
                if (!$stmt) {
                    throw new RuntimeException($conn->error);
                }

                $stmt->bind_param("ii", $instructor_id, $accomplishment_id);
            } else {
                $stmt = $conn->prepare("
                    UPDATE accomplishment_reports
                    SET status = 'Rejected'
                    WHERE id = ?
                ");
                if (!$stmt) {
                    throw new RuntimeException($conn->error);
                }

                $stmt->bind_param("i", $accomplishment_id);
            }

            if (!$stmt->execute()) {
                $stmt_error = $stmt->error;
                $stmt->close();
                throw new RuntimeException($stmt_error);
            }

            if ($stmt->affected_rows < 1) {
                $stmt->close();
                throw new RuntimeException('No accomplishment report was updated.');
            }

            $stmt->close();

            rserves_instructor_sync_accomplishment_student_tasks($conn, $accomplishment_id);
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            error_log("Instructor accomplishment {$normalized_status} failed for report {$accomplishment_id}: " . $e->getMessage());

            return [
                'success' => false,
                'message' => $failure_message,
                'type' => 'danger',
            ];
        }

        rserves_instructor_send_accomplishment_status_email($conn, $accomplishment_id, $normalized_status);

        return [
            'success' => true,
            'message' => $success_message,
            'type' => 'success',
        ];
    }

    function rserves_instructor_bulk_approve_accomplishments(mysqli $conn, int $instructor_id, array $accomplishment_ids): array
    {
        $unique_ids = array_values(array_unique(array_filter(array_map('intval', $accomplishment_ids))));
        if (empty($unique_ids)) {
            return [
                'success' => false,
                'message' => 'Please select at least one pending accomplishment.',
                'type' => 'danger',
                'processed' => 0,
            ];
        }

        $processed = 0;
        $approved_ids = [];

        foreach ($unique_ids as $accomplishment_id) {
            $result = rserves_instructor_update_accomplishment_status($conn, $instructor_id, $accomplishment_id, 'Approved');
            if ($result['success']) {
                $processed++;
                $approved_ids[] = $accomplishment_id;
            }
        }

        if ($processed === 0) {
            return [
                'success' => false,
                'message' => 'No accomplishments were approved. Please try again.',
                'type' => 'danger',
                'processed' => 0,
            ];
        }

        $message = $processed === 1
            ? '1 accomplishment approved successfully.'
            : "{$processed} accomplishments approved successfully.";

        if ($processed < count($unique_ids)) {
            $message .= ' Some selected records could not be approved.';
        }

        return [
            'success' => true,
            'message' => $message,
            'type' => 'success',
            'processed' => $processed,
            'approved_ids' => $approved_ids,
        ];
    }

    function rserves_instructor_format_due_date_label(?string $due_date): string
    {
        $normalized_due_date = trim((string) $due_date);
        if ($normalized_due_date === '') {
            return 'No due date';
        }

        $timestamp = strtotime($normalized_due_date);
        if ($timestamp === false) {
            return $normalized_due_date;
        }

        return date('F d, Y', $timestamp);
    }

    function rserves_instructor_fetch_task(mysqli $conn, int $instructor_id, int $task_id): ?array
    {
        $stmt = $conn->prepare("
            SELECT task_id, title, description, duration, due_date, department_id
            FROM tasks
            WHERE task_id = ?
              AND instructor_id = ?
              AND COALESCE(is_deleted, 0) = 0
            LIMIT 1
        ");

        if (!$stmt) {
            return null;
        }

        $stmt->bind_param("ii", $task_id, $instructor_id);
        $stmt->execute();
        $task = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return $task;
    }

    function rserves_instructor_fetch_task_assignments(mysqli $conn, int $instructor_id, int $task_id, bool $only_incomplete = false): array
    {
        $assignments = [];
        $sql = "
            SELECT
                st.stask_id,
                st.task_id,
                st.student_id,
                st.status,
                st.assigned_at,
                st.completed_at,
                s.student_number,
                s.firstname,
                s.lastname,
                s.email,
                s.year_level,
                s.section
            FROM student_tasks st
            INNER JOIN tasks t ON st.task_id = t.task_id
            INNER JOIN students s ON st.student_id = s.stud_id
            WHERE t.instructor_id = ?
              AND st.task_id = ?
              AND COALESCE(t.is_deleted, 0) = 0
        ";

        if ($only_incomplete) {
            $sql .= " AND st.status <> 'Completed'";
        }

        $sql .= " ORDER BY s.year_level ASC, s.section ASC, s.lastname ASC, s.firstname ASC";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param("ii", $instructor_id, $task_id);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $assignments[] = $row;
        }

        $stmt->close();

        return $assignments;
    }

    function rserves_instructor_send_task_assignment_email(array $student, string $instructor_name, array $task, string $intro): void
    {
        $email = trim((string) ($student['email'] ?? ''));
        if ($email === '') {
            return;
        }

        $details = [
            'Task' => (string) ($task['title'] ?? 'RSS Task'),
        ];

        $description = trim((string) ($task['description'] ?? ''));
        if ($description !== '') {
            $details['Description'] = $description;
        }

        $details['Due Date'] = rserves_instructor_format_due_date_label((string) ($task['due_date'] ?? ''));

        $body = rserves_notification_build_body(
            rserves_notification_recipient_name($student),
            $intro . ' (' . trim($instructor_name) . ').',
            $details,
            'Please log in to RServeS to review the full task details.'
        );

        $subject = 'Task Update: ' . (string) ($task['title'] ?? 'RSS Task');
        $result = sendEmail($email, rserves_notification_recipient_name($student), $subject, $body);

        if ($result !== true) {
            error_log("Task assignment email failed for {$email}: " . $result);
        }
    }

    function rserves_instructor_send_task_reminders(mysqli $conn, int $instructor_id, int $task_id, string $instructor_name): array
    {
        $task = rserves_instructor_fetch_task($conn, $instructor_id, $task_id);
        if (!$task) {
            return [
                'success' => false,
                'message' => 'Task not found or no longer available.',
            ];
        }

        $assignments = rserves_instructor_fetch_task_assignments($conn, $instructor_id, $task_id, true);
        if (empty($assignments)) {
            return [
                'success' => true,
                'message' => 'Everyone assigned to this task has already completed it.',
            ];
        }

        $sent = 0;
        foreach ($assignments as $assignment) {
            $email = trim((string) ($assignment['email'] ?? ''));
            if ($email === '') {
                continue;
            }

            $details = [
                'Task' => (string) ($task['title'] ?? 'RSS Task'),
                'Status' => (string) ($assignment['status'] ?? 'Pending'),
                'Due Date' => rserves_instructor_format_due_date_label((string) ($task['due_date'] ?? '')),
            ];

            $description = trim((string) ($task['description'] ?? ''));
            if ($description !== '') {
                $details['Description'] = $description;
            }

            $body = rserves_notification_build_body(
                rserves_notification_recipient_name($assignment),
                'This is a reminder from ' . trim($instructor_name) . ' to complete your assigned task.',
                $details,
                'Please submit your accomplishment before the due date.'
            );

            $result = sendEmail(
                $email,
                rserves_notification_recipient_name($assignment),
                'Reminder: ' . (string) ($task['title'] ?? 'RSS Task'),
                $body
            );

            if ($result === true) {
                $sent++;
            } else {
                error_log("Task reminder email failed for {$email}: " . $result);
            }
        }

        if ($sent === 0) {
            return [
                'success' => false,
                'message' => 'No reminder emails were sent. Please verify that the assigned students have email addresses.',
            ];
        }

        return [
            'success' => true,
            'message' => $sent === 1
                ? 'Reminder email sent to 1 student.'
                : "Reminder emails sent to {$sent} students.",
        ];
    }

    function rserves_instructor_fetch_advisory_student_recipients(mysqli $conn, int $instructor_id, int $department_id): array
    {
        $recipients = [];
        $stmt = $conn->prepare("
            SELECT DISTINCT
                s.stud_id,
                s.student_number,
                s.firstname,
                s.lastname,
                s.email,
                s.year_level,
                s.section
            FROM section_advisers sa
            INNER JOIN students s
                ON s.section = sa.section
               AND s.department_id = sa.department_id
            WHERE sa.instructor_id = ?
              AND sa.department_id = ?
            ORDER BY s.year_level ASC, s.section ASC, s.lastname ASC, s.firstname ASC
        ");

        if (!$stmt) {
            return [];
        }

        $stmt->bind_param("ii", $instructor_id, $department_id);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $recipients[] = $row;
        }

        $stmt->close();

        return $recipients;
    }

    function rserves_instructor_send_bulk_announcement(mysqli $conn, int $instructor_id, int $department_id, string $instructor_name, string $subject, string $message): array
    {
        $normalized_subject = trim($subject);
        $normalized_message = trim($message);

        if ($normalized_subject === '' || $normalized_message === '') {
            return [
                'success' => false,
                'message' => 'Please enter both an announcement subject and message.',
            ];
        }

        $recipients = rserves_instructor_fetch_advisory_student_recipients($conn, $instructor_id, $department_id);
        if (empty($recipients)) {
            return [
                'success' => false,
                'message' => 'No advisory students were found for this announcement.',
            ];
        }

        rserves_instructor_ensure_student_announcements_table($conn);

        $insert_stmt = $conn->prepare("
            INSERT INTO student_announcements (student_id, instructor_id, subject, message)
            VALUES (?, ?, ?, ?)
        ");

        if (!$insert_stmt) {
            return [
                'success' => false,
                'message' => 'Could not prepare the announcement records.',
            ];
        }

        $inserted = 0;
        foreach ($recipients as $recipient) {
            $student_id = intval($recipient['stud_id'] ?? 0);
            if ($student_id <= 0) {
                continue;
            }

            $insert_stmt->bind_param("iiss", $student_id, $instructor_id, $normalized_subject, $normalized_message);
            if ($insert_stmt->execute()) {
                $inserted++;
            }
        }
        $insert_stmt->close();

        $emailed = 0;
        foreach ($recipients as $recipient) {
            $email = trim((string) ($recipient['email'] ?? ''));
            if ($email === '') {
                continue;
            }

            $body = rserves_notification_build_body(
                rserves_notification_recipient_name($recipient),
                'New announcement from ' . trim($instructor_name) . '.',
                [
                    'Subject' => $normalized_subject,
                    'Message' => $normalized_message,
                ]
            );

            $result = sendEmail(
                $email,
                rserves_notification_recipient_name($recipient),
                'Announcement: ' . $normalized_subject,
                $body
            );

            if ($result === true) {
                $emailed++;
            } else {
                error_log("Announcement email failed for {$email}: " . $result);
            }
        }

        if ($inserted === 0 && $emailed === 0) {
            return [
                'success' => false,
                'message' => 'No announcement notifications were delivered.',
            ];
        }

        return [
            'success' => true,
            'message' => 'Announcement sent to ' . count($recipients) . ' advisory student' . (count($recipients) === 1 ? '' : 's') . '.',
        ];
    }

    function rserves_instructor_duplicate_task(mysqli $conn, int $instructor_id, int $task_id, string $instructor_name): array
    {
        $task = rserves_instructor_fetch_task($conn, $instructor_id, $task_id);
        if (!$task) {
            return [
                'success' => false,
                'message' => 'Task not found or no longer available.',
            ];
        }

        $assignments = rserves_instructor_fetch_task_assignments($conn, $instructor_id, $task_id);
        $new_task_id = 0;

        try {
            $conn->begin_transaction();

            $new_title = trim((string) ($task['title'] ?? 'Task')) . ' (Copy)';
            $insert_task = $conn->prepare("
                INSERT INTO tasks (title, description, duration, due_date, instructor_id, department_id, created_by_student)
                VALUES (?, ?, ?, ?, ?, ?, 0)
            ");

            if (!$insert_task) {
                throw new RuntimeException($conn->error);
            }

            $description = (string) ($task['description'] ?? '');
            $duration = (string) ($task['duration'] ?? '');
            $due_date = (string) ($task['due_date'] ?? '');
            $department_id = intval($task['department_id'] ?? 0);

            $insert_task->bind_param("ssssii", $new_title, $description, $duration, $due_date, $instructor_id, $department_id);
            if (!$insert_task->execute()) {
                $insert_task_error = $insert_task->error;
                $insert_task->close();
                throw new RuntimeException($insert_task_error);
            }

            $new_task_id = intval($insert_task->insert_id);
            $insert_task->close();

            if (!empty($assignments)) {
$insert_assignment = $conn->prepare("
                    INSERT INTO student_tasks (student_id, task_id, status, approval_status, assigned_at)
                    VALUES (?, ?, 'Pending', 'Pending Approval', NOW())
                ");

                if (!$insert_assignment) {
                    throw new RuntimeException($conn->error);
                }

                foreach ($assignments as $assignment) {
                    $student_id = intval($assignment['student_id'] ?? 0);
                    if ($student_id <= 0) {
                        continue;
                    }

$insert_assignment->bind_param("iii", $student_id, $new_task_id, $student_id);
                    if (!$insert_assignment->execute()) {
                        $insert_assignment_error = $insert_assignment->error;
                        $insert_assignment->close();
                        throw new RuntimeException($insert_assignment_error);
                    }
                }

                $insert_assignment->close();
            }

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            error_log("Task duplication failed for task {$task_id}: " . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Task duplication failed. Please try again.',
            ];
        }

        $duplicated_task = $task;
        $duplicated_task['title'] = trim((string) ($task['title'] ?? 'Task')) . ' (Copy)';

        foreach ($assignments as $assignment) {
            rserves_instructor_send_task_assignment_email(
                $assignment,
                $instructor_name,
                $duplicated_task,
                'A duplicate task has been assigned to you by your adviser'
            );
        }

        $assigned_count = count($assignments);
        $message = 'Task duplicated successfully.';
        if ($assigned_count > 0) {
            $message .= ' The copy was assigned to ' . $assigned_count . ' student' . ($assigned_count === 1 ? '' : 's') . '.';
        }

        return [
            'success' => true,
            'message' => $message,
            'task_id' => $new_task_id,
        ];
    }

    function rserves_instructor_reassign_task(mysqli $conn, int $instructor_id, int $student_task_id, int $new_student_id, string $instructor_name): array
    {
        rserves_instructor_ensure_student_task_meta_columns($conn);

        $assignment_stmt = $conn->prepare("
            SELECT
                st.stask_id,
                st.task_id,
                st.student_id,
                t.title,
                t.description,
                t.due_date,
                t.department_id,
                current_student.firstname AS current_firstname,
                current_student.lastname AS current_lastname
            FROM student_tasks st
            INNER JOIN tasks t ON st.task_id = t.task_id
            INNER JOIN students current_student ON current_student.stud_id = st.student_id
            WHERE st.stask_id = ?
              AND t.instructor_id = ?
              AND COALESCE(t.is_deleted, 0) = 0
            LIMIT 1
        ");

        if (!$assignment_stmt) {
            return [
                'success' => false,
                'message' => 'Could not prepare the reassign request.',
            ];
        }

        $assignment_stmt->bind_param("ii", $student_task_id, $instructor_id);
        $assignment_stmt->execute();
        $assignment = $assignment_stmt->get_result()->fetch_assoc() ?: null;
        $assignment_stmt->close();

        if (!$assignment) {
            return [
                'success' => false,
                'message' => 'Task assignment not found.',
            ];
        }

        if (intval($assignment['student_id'] ?? 0) === $new_student_id) {
            return [
                'success' => false,
                'message' => 'Please choose a different student for reassignment.',
            ];
        }

        $student_stmt = $conn->prepare("
            SELECT stud_id, firstname, lastname, email, department_id
            FROM students
            WHERE stud_id = ?
            LIMIT 1
        ");

        if (!$student_stmt) {
            return [
                'success' => false,
                'message' => 'Could not prepare the replacement student lookup.',
            ];
        }

        $student_stmt->bind_param("i", $new_student_id);
        $student_stmt->execute();
        $new_student = $student_stmt->get_result()->fetch_assoc() ?: null;
        $student_stmt->close();

        if (!$new_student) {
            return [
                'success' => false,
                'message' => 'The selected replacement student was not found.',
            ];
        }

        if (intval($new_student['department_id'] ?? 0) !== intval($assignment['department_id'] ?? 0)) {
            return [
                'success' => false,
                'message' => 'The selected student is not in the same department as this task.',
            ];
        }

        $existing_assignment_stmt = $conn->prepare("
            SELECT 1
            FROM student_tasks
            WHERE task_id = ?
              AND student_id = ?
              AND stask_id <> ?
            LIMIT 1
        ");

        if (!$existing_assignment_stmt) {
            return [
                'success' => false,
                'message' => 'Could not verify existing task assignments for the selected student.',
            ];
        }

        $task_id = intval($assignment['task_id'] ?? 0);
        $existing_assignment_stmt->bind_param("iii", $task_id, $new_student_id, $student_task_id);
        $existing_assignment_stmt->execute();
        $already_assigned = (bool) $existing_assignment_stmt->get_result()->fetch_assoc();
        $existing_assignment_stmt->close();

        if ($already_assigned) {
            return [
                'success' => false,
                'message' => 'That student is already assigned to this task.',
            ];
        }

        $task_pattern = '%[TaskID:' . $student_task_id . ']%';
        $report_stmt = $conn->prepare("
            SELECT 1
            FROM accomplishment_reports
            WHERE student_task_id = ?
               OR activity LIKE ?
            LIMIT 1
        ");

        if (!$report_stmt) {
            return [
                'success' => false,
                'message' => 'Could not verify the current task history.',
            ];
        }

        $report_stmt->bind_param("is", $student_task_id, $task_pattern);
        $report_stmt->execute();
        $has_task_reports = (bool) $report_stmt->get_result()->fetch_assoc();
        $report_stmt->close();

        if ($has_task_reports) {
            return [
                'success' => false,
                'message' => 'This task already has accomplishment activity. Duplicate the task instead of reassigning it.',
            ];
        }

$update_stmt = $conn->prepare("
            UPDATE student_tasks
            SET student_id = ?,
                status = 'Pending',
                approval_status = 'Pending Approval',
                assigned_at = NOW(),
                completed_at = NULL,
                student_view_status = 'active',
                student_state_changed_at = NULL
            WHERE stask_id = ?
        ");

        if (!$update_stmt) {
            return [
                'success' => false,
                'message' => 'Could not prepare the reassignment update.',
            ];
        }

        $update_stmt->bind_param("ii", $new_student_id, $student_task_id);
        if (!$update_stmt->execute()) {
            $update_error = $update_stmt->error;
            $update_stmt->close();

            return [
                'success' => false,
                'message' => 'Task reassignment failed: ' . $update_error,
            ];
        }
        $update_stmt->close();

        rserves_instructor_send_task_assignment_email(
            $new_student,
            $instructor_name,
            [
                'title' => (string) ($assignment['title'] ?? 'RSS Task'),
                'description' => (string) ($assignment['description'] ?? ''),
                'due_date' => (string) ($assignment['due_date'] ?? ''),
            ],
            'A task has been reassigned to you by your adviser'
        );

        $old_name = trim((string) ($assignment['current_firstname'] ?? '') . ' ' . (string) ($assignment['current_lastname'] ?? ''));
        $new_name = trim((string) ($new_student['firstname'] ?? '') . ' ' . (string) ($new_student['lastname'] ?? ''));

        return [
            'success' => true,
            'message' => 'Task reassigned from ' . ($old_name !== '' ? $old_name : 'the previous student') . ' to ' . ($new_name !== '' ? $new_name : 'the selected student') . '.',
        ];
    }
}
