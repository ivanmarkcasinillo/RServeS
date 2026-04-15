<?php

if (!function_exists('rserves_instructor_extract_student_task_ids_from_activity')) {
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

        if ($has_completed_report) {
            $update_stmt = $conn->prepare("
                UPDATE student_tasks
                SET status = 'Completed',
                    completed_at = COALESCE(completed_at, NOW())
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
                    AND ar.status IN ('Approved', 'Verified')
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
}
