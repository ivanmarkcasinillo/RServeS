<?php
require_once 'dbconnect.php';

// 1. Add verbal_assigner_name to tasks
$check = $conn->query("SHOW COLUMNS FROM tasks LIKE 'verbal_assigner_name'");
if ($check->num_rows == 0) {
    $sql = "ALTER TABLE tasks ADD COLUMN verbal_assigner_name VARCHAR(255) NULL AFTER instructor_id";
    if ($conn->query($sql)) {
        echo "✅ Added verbal_assigner_name to tasks\n";
    } else {
        echo "❌ Failed to add verbal_assigner_name: " . $conn->error . "\n";
    }
}

// 2. Add approval columns to student_tasks
$approval_cols = [
    'approval_status' => "ENUM('Pending Approval', 'Approved', 'Rejected') NOT NULL DEFAULT 'Pending Approval'",
    'approved_by' => 'INT NULL',
    'approved_at' => 'DATETIME NULL',
    'reject_reason' => 'TEXT NULL',
    'reject_by' => 'INT NULL',
    'rejected_at' => 'DATETIME NULL'
];

foreach ($approval_cols as $col => $type) {
    $check = $conn->query("SHOW COLUMNS FROM student_tasks LIKE '$col'");
    if ($check->num_rows == 0) {
        $sql = "ALTER TABLE student_tasks ADD COLUMN $col $type";
        if ($conn->query($sql)) {
            echo "✅ Added $col to student_tasks\n";
        } else {
            echo "❌ Failed to add $col: " . $conn->error . "\n";
        }
    } else {
        echo "ℹ️ $col already exists\n";
    }
}

// 3. Update existing tasks to Pending Approval (migration)
$result = $conn->query("
    UPDATE student_tasks 
    SET approval_status = 'Pending Approval'
    WHERE approval_status = '' OR approval_status IS NULL OR approval_status NOT IN ('Approved', 'Rejected')
");
$affected = $conn->affected_rows;
echo "✅ Updated $affected existing assignments to 'Pending Approval'\n";

echo "\n🎉 Schema migration complete! Run 'php admin/schema_updates.php' once only.\n";
?>

