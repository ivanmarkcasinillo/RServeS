<?php
// check_expiration.php
// Checks for pending tasks that have exceeded their duration and marks them as Expired.

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
    // SQL to update expired tasks
    // Matches duration strings: "Within a Day", "Within a Week", "Within a Month"
    // AND ensures the student hasn't already submitted an accomplishment report (Pending/Verified/Approved)
    $sql = "
        UPDATE student_tasks st
        JOIN tasks t ON st.task_id = t.task_id
        SET st.status = 'Expired'
        WHERE st.status = 'Pending'
        AND (
            (t.duration = 'Within a Day' AND st.assigned_at < DATE_SUB(NOW(), INTERVAL 1 DAY)) OR
            (t.duration = 'Within a Week' AND st.assigned_at < DATE_SUB(NOW(), INTERVAL 1 WEEK)) OR
            (t.duration = 'Within a Month' AND st.assigned_at < DATE_SUB(NOW(), INTERVAL 1 MONTH))
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
