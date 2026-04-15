<?php

if (!defined('RSERVES_STUDENT_TASK_TITLE_MAX_CHARS')) {
    define('RSERVES_STUDENT_TASK_TITLE_MAX_CHARS', 80);
}

if (!defined('RSERVES_STUDENT_DESCRIPTION_MAX_CHARS')) {
    define('RSERVES_STUDENT_DESCRIPTION_MAX_CHARS', 150);
}

if (!function_exists('rserves_student_schema_identifier')) {
    function rserves_student_schema_identifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    function rserves_student_table_exists(mysqli $conn, string $table): bool
    {
        $table_name = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$table_name}'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }

    function rserves_student_column_exists(mysqli $conn, string $table, string $column): bool
    {
        if (!rserves_student_table_exists($conn, $table)) {
            return false;
        }

        $table_name = rserves_student_schema_identifier($table);
        $column_name = $conn->real_escape_string($column);
        $result = $conn->query("SHOW COLUMNS FROM {$table_name} LIKE '{$column_name}'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }

    function rserves_student_ensure_column(mysqli $conn, string $table, string $column, string $definition): void
    {
        if (!rserves_student_column_exists($conn, $table, $column)) {
            $table_name = rserves_student_schema_identifier($table);
            $column_name = rserves_student_schema_identifier($column);
            $conn->query("ALTER TABLE {$table_name} ADD COLUMN {$column_name} {$definition}");
        }
    }

    function rserves_student_ensure_task_schema(mysqli $conn): void
    {
        if (rserves_student_table_exists($conn, 'tasks')) {
            rserves_student_ensure_column($conn, 'tasks', 'duration', 'VARCHAR(50) NULL');
            rserves_student_ensure_column($conn, 'tasks', 'created_by_student', 'INT NULL');
            rserves_student_ensure_column($conn, 'tasks', 'created_at', 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP');
            rserves_student_ensure_column($conn, 'tasks', 'is_deleted', 'TINYINT(1) NOT NULL DEFAULT 0');
        }

        if (rserves_student_table_exists($conn, 'student_tasks')) {
            rserves_student_ensure_column($conn, 'student_tasks', 'assigned_at', 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP');
            rserves_student_ensure_column($conn, 'student_tasks', 'completed_at', 'DATETIME NULL');
            rserves_student_ensure_column($conn, 'student_tasks', 'student_view_status', "VARCHAR(20) NOT NULL DEFAULT 'active'");
            rserves_student_ensure_column($conn, 'student_tasks', 'student_state_changed_at', 'DATETIME NULL');
        }

        if (rserves_student_table_exists($conn, 'accomplishment_reports')) {
            rserves_student_ensure_column($conn, 'accomplishment_reports', 'assigner_id', 'INT NULL DEFAULT NULL');
            rserves_student_ensure_column($conn, 'accomplishment_reports', 'student_task_id', 'INT NULL DEFAULT NULL');
        }
    }

    function rserves_student_text_length(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value);
        }

        return strlen($value);
    }

    function rserves_student_text_truncate(string $value, int $max_chars): string
    {
        if ($max_chars < 0 || rserves_student_text_length($value) <= $max_chars) {
            return $value;
        }

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max_chars);
        }

        return substr($value, 0, $max_chars);
    }

    function rserves_student_normalize_text(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $normalized = preg_replace('/\s+/u', ' ', $value);

        return $normalized !== null ? trim($normalized) : $value;
    }

    function rserves_student_strip_task_tags(string $value): string
    {
        $clean = preg_replace('/\[\s*TaskID\s*:\s*\d+\s*\]/i', '', $value);

        return trim($clean !== null ? $clean : $value);
    }

    function rserves_student_extract_report_task_title(string $activity): string
    {
        $clean_activity = rserves_student_strip_task_tags($activity);
        $parts = explode(':', $clean_activity, 2);

        if (count($parts) < 2) {
            return '';
        }

        return rserves_student_normalize_text($parts[0]);
    }

    function rserves_student_is_image_attachment(?string $path): bool
    {
        $ext = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));
        $image_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];

        return in_array($ext, $image_extensions, true);
    }

    function rserves_student_save_attachment(string $file_input_name, int $student_id, string $upload_dir, string $relative_dir = 'uploads/accomplishments'): array
    {
        if (empty($_FILES[$file_input_name]['tmp_name'])) {
            return ['path' => null, 'error' => null];
        }

        $file = $_FILES[$file_input_name];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['path' => null, 'error' => 'Unable to upload the selected attachment.'];
        }

        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $blocked_extensions = [
            'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar',
            'cgi', 'pl', 'py', 'jsp', 'asp', 'aspx', 'exe', 'com', 'cmd',
            'bat', 'sh', 'msi', 'scr', 'dll', 'so', 'vb', 'vbs'
        ];

        if ($ext !== '' && in_array($ext, $blocked_extensions, true)) {
            return ['path' => null, 'error' => 'That attachment type is not allowed.'];
        }

        if (!is_dir($upload_dir) && !mkdir($upload_dir, 0777, true) && !is_dir($upload_dir)) {
            return ['path' => null, 'error' => 'Could not prepare the upload folder.'];
        }

        try {
            $random_suffix = bin2hex(random_bytes(4));
        } catch (Throwable $e) {
            $random_suffix = (string) mt_rand(1000, 9999);
        }

        $safe_extension = $ext !== '' ? '.' . preg_replace('/[^a-z0-9]+/i', '', $ext) : '';
        $new_name = $file_input_name . '_' . $student_id . '_' . time() . '_' . $random_suffix . $safe_extension;
        $destination = rtrim($upload_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $new_name;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            return ['path' => null, 'error' => 'Failed to store the uploaded attachment.'];
        }

        return [
            'path' => trim($relative_dir, '/\\') . '/' . $new_name,
            'error' => null,
        ];
    }

    function rserves_student_validate_text_input(string $value, string $label, int $max_chars, bool $required = false): array
    {
        $normalized = rserves_student_normalize_text($value);

        if ($required && $normalized === '') {
            return [
                'value' => $normalized,
                'error' => 'Please enter ' . $label . '.',
            ];
        }

        if (rserves_student_text_length($normalized) > $max_chars) {
            return [
                'value' => $normalized,
                'error' => ucfirst($label) . ' must be ' . $max_chars . ' characters or fewer.',
            ];
        }

        return [
            'value' => $normalized,
            'error' => null,
        ];
    }

    function rserves_student_find_valid_assigner_id(mysqli $conn, array $student, int $requested_assigner_id = 0): int
    {
        $department_id = intval($student['department_id'] ?? 0);
        $candidate_ids = [];

        if ($requested_assigner_id > 0) {
            $candidate_ids[] = $requested_assigner_id;
        }

        $student_instructor_id = intval($student['instructor_id'] ?? 0);
        if ($student_instructor_id > 0) {
            $candidate_ids[] = $student_instructor_id;
        }

        $candidate_ids = array_values(array_unique(array_filter($candidate_ids)));

        foreach ($candidate_ids as $candidate_id) {
            $stmt = $conn->prepare("SELECT inst_id FROM instructors WHERE inst_id = ? AND (? = 0 OR department_id = ?) LIMIT 1");
            if (!$stmt) {
                continue;
            }

            $stmt->bind_param("iii", $candidate_id, $department_id, $department_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $is_valid = $result && $result->fetch_assoc();
            $stmt->close();

            if ($is_valid) {
                return $candidate_id;
            }
        }

        $section = trim((string)($student['section'] ?? ''));
        if ($section !== '' && $department_id > 0 && rserves_student_table_exists($conn, 'section_advisers')) {
            $stmt = $conn->prepare("SELECT instructor_id FROM section_advisers WHERE department_id = ? AND section = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("is", $department_id, $section);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!empty($row['instructor_id'])) {
                    return intval($row['instructor_id']);
                }
            }
        }

        if ($department_id > 0) {
            $stmt = $conn->prepare("SELECT inst_id FROM instructors WHERE department_id = ? ORDER BY inst_id ASC LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("i", $department_id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!empty($row['inst_id'])) {
                    return intval($row['inst_id']);
                }
            }
        }

        return 0;
    }

    function rserves_student_has_duplicate_verbal_task(mysqli $conn, int $student_id, string $task_title, string $task_desc): bool
    {
        $stmt = $conn->prepare("
            SELECT st.stask_id
            FROM tasks t
            INNER JOIN student_tasks st ON t.task_id = st.task_id
            WHERE st.student_id = ?
              AND COALESCE(t.created_by_student, 0) = ?
              AND COALESCE(t.is_deleted, 0) = 0
              AND LOWER(TRIM(t.title)) = LOWER(TRIM(?))
              AND LOWER(TRIM(COALESCE(t.description, ''))) = LOWER(TRIM(?))
            LIMIT 1
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param("iiss", $student_id, $student_id, $task_title, $task_desc);
        $stmt->execute();
        $result = $stmt->get_result();
        $has_duplicate = $result && $result->fetch_assoc();
        $stmt->close();

        return (bool) $has_duplicate;
    }

    function rserves_student_has_same_day_duplicate_report(mysqli $conn, int $student_id, string $work_date, int $student_task_id = 0, string $task_title = ''): bool
    {
        $stmt = $conn->prepare("
            SELECT activity, student_task_id
            FROM accomplishment_reports
            WHERE student_id = ?
              AND work_date = ?
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param("is", $student_id, $work_date);
        $stmt->execute();
        $result = $stmt->get_result();

        $normalized_task_title = rserves_student_normalize_text($task_title);
        while ($row = $result->fetch_assoc()) {
            $existing_task_id = intval($row['student_task_id'] ?? 0);
            $existing_activity = (string) ($row['activity'] ?? '');

            if ($student_task_id > 0) {
                if ($existing_task_id === $student_task_id) {
                    $stmt->close();
                    return true;
                }

                $task_ids = function_exists('rserves_instructor_extract_student_task_ids_from_activity')
                    ? rserves_instructor_extract_student_task_ids_from_activity($existing_activity)
                    : rserves_student_extract_task_ids_from_activity_list($existing_activity);

                if (in_array($student_task_id, $task_ids, true)) {
                    $stmt->close();
                    return true;
                }
            }

            if ($student_task_id <= 0 && $normalized_task_title !== '') {
                $existing_title = rserves_student_extract_report_task_title($existing_activity);
                if ($existing_title !== '' && strcasecmp($existing_title, $normalized_task_title) === 0) {
                    $stmt->close();
                    return true;
                }
            }
        }

        $stmt->close();
        return false;
    }

    function rserves_student_extract_task_ids_from_activity_list(string $activity): array
    {
        if (preg_match_all('/\[TaskID:(\d+)\]/', $activity, $matches) === false || empty($matches[1])) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $matches[1] ?? [])));
    }

    function rserves_student_update_task_visibility(mysqli $conn, int $student_id, int $student_task_id, string $state): bool
    {
        $allowed_states = ['active', 'archived', 'deleted'];
        if (!in_array($state, $allowed_states, true)) {
            return false;
        }

        $stmt = $conn->prepare("
            UPDATE student_tasks
            SET student_view_status = ?, student_state_changed_at = NOW()
            WHERE stask_id = ? AND student_id = ?
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param("sii", $state, $student_task_id, $student_id);
        $stmt->execute();
        $updated = $stmt->affected_rows >= 0;
        $stmt->close();

        return $updated;
    }

    function rserves_create_student_verbal_task(mysqli $conn, array $student, int $student_id, array $post, bool $enforce_token = true): string
    {
        rserves_student_ensure_task_schema($conn);

        if (!rserves_student_table_exists($conn, 'tasks') || !rserves_student_table_exists($conn, 'student_tasks')) {
            return 'Task backend is not ready yet because the tasks tables are missing.';
        }

        $session_token = (string)($_SESSION['student_task_form_token'] ?? '');
        $form_token = trim((string)($post['task_form_token'] ?? ''));

        if ($enforce_token && ($form_token === '' || $session_token === '' || !hash_equals($session_token, $form_token))) {
            // Regenerate token for next attempt
            $_SESSION['student_task_form_token'] = bin2hex(random_bytes(16));
            return 'Task submission expired. Please reopen the Create Verbal Task form and try again.';
        }

        // Regenerate for security after successful validation
        $_SESSION['student_task_form_token'] = bin2hex(random_bytes(16));

        $task_title_check = rserves_student_validate_text_input((string)($post['task_title'] ?? ''), 'task category', RSERVES_STUDENT_TASK_TITLE_MAX_CHARS, true);
        if ($task_title_check['error'] !== null) {
            return $task_title_check['error'];
        }

        $task_desc_check = rserves_student_validate_text_input((string)($post['task_description'] ?? ''), 'description', RSERVES_STUDENT_DESCRIPTION_MAX_CHARS, false);
        if ($task_desc_check['error'] !== null) {
            return $task_desc_check['error'];
        }

        $task_title = $task_title_check['value'];
        $task_desc = $task_desc_check['value'];
        $duration = trim((string)($post['duration'] ?? ''));
        $allowed_durations = ['Within a Day', 'Within a Week', 'Within a Month'];

        if (!in_array($duration, $allowed_durations, true)) {
            return 'Please select a valid duration.';
        }

        $department_id = intval($student['department_id'] ?? 0);
        if ($department_id <= 0) {
            return 'Task creation failed because this student does not have a valid department assignment.';
        }

        $requested_assigner_id = isset($post['assigner_id']) ? intval($post['assigner_id']) : 0;
        $assigner_id = rserves_student_find_valid_assigner_id($conn, $student, $requested_assigner_id);
        if ($assigner_id <= 0) {
            return 'Task creation failed because no valid instructor or adviser could be resolved for this student.';
        }

        if (rserves_student_has_duplicate_verbal_task($conn, $student_id, $task_title, $task_desc)) {
            return 'This verbal task already exists. Please update the existing task instead of creating a duplicate.';
        }

        $conn->begin_transaction();

        try {
            $stmt = $conn->prepare("
                INSERT INTO tasks (title, description, duration, instructor_id, department_id, created_by_student, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            if (!$stmt) {
                throw new RuntimeException($conn->error);
            }

            $stmt->bind_param("sssiii", $task_title, $task_desc, $duration, $assigner_id, $department_id, $student_id);
            if (!$stmt->execute()) {
                $stmt_error = $stmt->error;
                $stmt->close();
                throw new RuntimeException($stmt_error);
            }

            $new_task_id = $stmt->insert_id;
            $stmt->close();

            $stmt2 = $conn->prepare("
                INSERT INTO student_tasks (task_id, student_id, status, assigned_at)
                VALUES (?, ?, 'Pending', NOW())
            ");
            if (!$stmt2) {
                throw new RuntimeException($conn->error);
            }

            $stmt2->bind_param("ii", $new_task_id, $student_id);
            if (!$stmt2->execute()) {
                $stmt2_error = $stmt2->error;
                $stmt2->close();
                throw new RuntimeException($stmt2_error);
            }

            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['last_created_student_task_id'] = intval($stmt2->insert_id);
            }

            $stmt2->close();
            $conn->commit();

            return "Verbal task '{$task_title}' created successfully!";
        } catch (Throwable $e) {
            $conn->rollback();
            error_log("Verbal task creation failed for student {$student_id}: " . $e->getMessage());

            return "Task creation failed: " . $e->getMessage();
        }
    }

    function rserves_student_extract_task_id_from_activity(string $activity): int
    {
        if (preg_match('/\[TaskID:(\d+)\]/', $activity, $matches) === 1) {
            return intval($matches[1]);
        }

        return 0;
    }

    function rserves_student_build_task_report_index(array $accomplishment_reports): array
    {
        $report_index = [];

        foreach ($accomplishment_reports as $report) {
            $student_task_id = intval($report['student_task_id'] ?? 0);
            if ($student_task_id <= 0) {
                $student_task_id = rserves_student_extract_task_id_from_activity((string)($report['activity'] ?? ''));
            }

            if ($student_task_id <= 0) {
                continue;
            }

            if (!isset($report_index[$student_task_id])) {
                $report_index[$student_task_id] = [
                    'status' => null,
                    'attempts' => 0,
                ];
            }

            $report_index[$student_task_id]['attempts']++;

            if ($report_index[$student_task_id]['status'] === null) {
                $status = trim((string)($report['status'] ?? ''));
                $report_index[$student_task_id]['status'] = $status !== '' ? $status : null;
            }
        }

        return $report_index;
    }

    function rserves_fetch_student_dashboard_tasks(mysqli $conn, int $student_id, array $accomplishment_reports = []): array
    {
        rserves_student_ensure_task_schema($conn);

        $stmt = $conn->prepare("
            SELECT
                st.stask_id,
                st.status,
                st.assigned_at,
                t.task_id,
                t.title,
                t.description,
                t.duration,
                t.created_by_student,
                t.created_at,
                t.instructor_id,
                i.firstname AS inst_fname,
                i.lastname AS inst_lname,
                CASE
                    WHEN COALESCE(t.created_by_student, 0) = ? THEN 'verbal'
                    ELSE 'adviser'
                END AS task_type
            FROM student_tasks st
            INNER JOIN tasks t ON st.task_id = t.task_id
            LEFT JOIN instructors i ON t.instructor_id = i.inst_id
            WHERE st.student_id = ?
              AND COALESCE(st.student_view_status, 'active') = 'active'
              AND COALESCE(t.is_deleted, 0) = 0
            ORDER BY COALESCE(t.created_at, st.assigned_at) DESC, st.stask_id DESC
        ");

        if (!$stmt) {
            return [];
        }

        $stmt->bind_param("ii", $student_id, $student_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $tasks = [];
        while ($row = $result->fetch_assoc()) {
            $tasks[] = $row;
        }
        $stmt->close();

        $deduped_tasks = [];
        $seen_task_ids = [];
        foreach ($tasks as $task) {
            $task_key = intval($task['stask_id'] ?? 0);
            if ($task_key > 0 && isset($seen_task_ids[$task_key])) {
                continue;
            }

            if ($task_key > 0) {
                $seen_task_ids[$task_key] = true;
            }

            $deduped_tasks[] = $task;
        }

        $report_index = rserves_student_build_task_report_index($accomplishment_reports);
        $visible_tasks = [];

        foreach ($deduped_tasks as $task) {
            $student_task_id = intval($task['stask_id'] ?? 0);
            $ar_status = $report_index[$student_task_id]['status'] ?? null;
            $ar_attempts = intval($report_index[$student_task_id]['attempts'] ?? 0);
            $task_status = trim((string)($task['status'] ?? 'Pending'));
            $display_status = $task_status !== '' ? $task_status : 'Pending';
            $disable_submit = false;

            if ($ar_status === 'Pending') {
                $display_status = 'Pending Approval';
                $disable_submit = true;
            } elseif ($ar_status === 'Verified' || $ar_status === 'Approved') {
                $display_status = 'Completed';
                $disable_submit = true;
            } elseif ($ar_status === 'Rejected') {
                $display_status = 'Rejected';
            }

            if ($ar_attempts >= 2 && $display_status !== 'Completed') {
                $disable_submit = true;
            }

            $is_legacy_in_review = $ar_status !== null && !in_array($ar_status, ['Verified', 'Approved'], true);
            $should_show = $task_status !== 'Completed' || $is_legacy_in_review;
            if (!$should_show) {
                continue;
            }

            $task['ar_status'] = $ar_status;
            $task['ar_attempts'] = $ar_attempts;
            $task['display_status'] = $display_status;
            $task['disable_submit'] = $disable_submit;
            $task['is_verbal'] = ($task['task_type'] === 'verbal');
            $visible_tasks[] = $task;
        }

        return $visible_tasks;
    }

    function rserves_fetch_student_archived_tasks(mysqli $conn, int $student_id): array
    {
        rserves_student_ensure_task_schema($conn);

        $stmt = $conn->prepare("
            SELECT
                st.stask_id,
                st.status,
                st.assigned_at,
                st.student_view_status,
                st.student_state_changed_at,
                t.task_id,
                t.title,
                t.description,
                t.duration,
                t.created_by_student,
                t.created_at,
                i.firstname AS inst_fname,
                i.lastname AS inst_lname
            FROM student_tasks st
            INNER JOIN tasks t ON st.task_id = t.task_id
            LEFT JOIN instructors i ON t.instructor_id = i.inst_id
            WHERE st.student_id = ?
              AND COALESCE(st.student_view_status, 'active') IN ('archived', 'deleted')
            ORDER BY COALESCE(st.student_state_changed_at, t.created_at, st.assigned_at) DESC, st.stask_id DESC
        ");

        if (!$stmt) {
            return [];
        }

        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $tasks = [];
        while ($row = $result->fetch_assoc()) {
            $row['is_verbal'] = intval($row['created_by_student'] ?? 0) === $student_id;
            $tasks[] = $row;
        }

        $stmt->close();

        return $tasks;
    }
}
