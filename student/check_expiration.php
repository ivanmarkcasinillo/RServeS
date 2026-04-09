<?php
// check_expiration.php
// Converts task duration labels into actual availability windows.
// Pending tasks disappear from student availability once their time runs out.

// Ensure DB connection is available
if (!isset($conn)) {
    // Try to locate dbconnect.php assuming we are in student/ or instructor/ or root
    if (file_exists('dbconnect.php')) {
        include_once 'dbconnect.php';
    } elseif (file_exists('../dbconnect.php')) {
        include_once '../dbconnect.php';
    } elseif (file_exists('../../dbconnect.php')) {
        include_once '../../dbconnect.php';
    }
}

if (isset($conn) && $conn instanceof mysqli) {
    // Use the assignment timestamp when available; otherwise fall back to the task creation time.
    // This keeps older records functional even if `assigned_at` was not filled before.
    $sql = "
        UPDATE student_tasks st
        JOIN tasks t ON st.task_id = t.task_id
        SET st.status = 'Expired'
        WHERE st.status = 'Pending'
        AND (
            (t.duration = 'Within a Day' AND COALESCE(st.assigned_at, t.created_at) < DATE_SUB(NOW(), INTERVAL 1 DAY)) OR
            (t.duration = 'Within a Week' AND COALESCE(st.assigned_at, t.created_at) < DATE_SUB(NOW(), INTERVAL 1 WEEK)) OR
            (t.duration = 'Within a Month' AND COALESCE(st.assigned_at, t.created_at) < DATE_SUB(NOW(), INTERVAL 1 MONTH))
        )
        AND NOT EXISTS (
            SELECT 1 FROM accomplishment_reports ar 
            WHERE ar.student_id = st.student_id 
            AND ar.activity LIKE CONCAT('%[TaskID:', st.stask_id, ']%')
            AND ar.status IN ('Pending', 'Verified', 'Approved')
        )
    ";

    $conn->query($sql);
    // We suppress output/errors to avoid breaking the dashboard UI if this is included
}
?>
