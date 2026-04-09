<?php
session_start();
require "dbconnect.php";

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set the student ID you want to test
$test_student_id = isset($_SESSION['stud_id']) ? $_SESSION['stud_id'] : 1;

?>
<!DOCTYPE html>
<html>
<head>
    <title>Task Database Debug Tool</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .section { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #333; }
        h3 { color: #666; border-bottom: 2px solid #4CAF50; padding-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th { background: #4CAF50; color: white; padding: 12px; text-align: left; }
        td { padding: 10px; border-bottom: 1px solid #ddd; }
        tr:hover { background: #f5f5f5; }
        .success { background: #d4edda; color: #155724; padding: 10px; border-radius: 4px; margin: 10px 0; }
        .error { background: #f8d7da; color: #721c24; padding: 10px; border-radius: 4px; margin: 10px 0; }
        .warning { background: #fff3cd; color: #856404; padding: 10px; border-radius: 4px; margin: 10px 0; }
        .info { background: #d1ecf1; color: #0c5460; padding: 10px; border-radius: 4px; margin: 10px 0; }
        button { background: #4CAF50; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
        button:hover { background: #45a049; }
        input[type="text"], textarea { width: 100%; padding: 8px; margin: 5px 0; border: 1px solid #ddd; border-radius: 4px; }
        .code { background: #f4f4f4; padding: 10px; border-left: 4px solid #4CAF50; margin: 10px 0; font-family: monospace; }
    </style>
</head>
<body>

<h1>🔍 Task Database Debug Tool</h1>

<?php

// ============================================
// SECTION 1: CHECK TABLE EXISTS
// ============================================
echo '<div class="section">';
echo '<h3>1. Checking if tables exist...</h3>';
$tables = ['tasks', 'student_tasks', 'students'];
$all_exist = true;
foreach ($tables as $table) {
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    if ($result->num_rows > 0) {
        echo "<div class='success'>✅ Table '$table' exists</div>";
    } else {
        echo "<div class='error'>❌ Table '$table' MISSING!</div>";
        $all_exist = false;
    }
}
echo '</div>';

// ============================================
// SECTION 2: CHECK TASKS TABLE STRUCTURE
// ============================================
echo '<div class="section">';
echo '<h3>2. Checking tasks table structure...</h3>';
$result = $conn->query("DESCRIBE tasks");
echo "<table><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
$has_created_by = false;
while ($row = $result->fetch_assoc()) {
    echo "<tr>";
    echo "<td><strong>" . $row['Field'] . "</strong></td>";
    echo "<td>" . $row['Type'] . "</td>";
    echo "<td>" . $row['Null'] . "</td>";
    echo "<td>" . $row['Key'] . "</td>";
    echo "<td>" . ($row['Default'] ?? 'NULL') . "</td>";
    echo "</tr>";
    if ($row['Field'] == 'created_by_student') {
        $has_created_by = true;
    }
}
echo "</table>";

if ($has_created_by) {
    echo "<div class='success'>✅ Column 'created_by_student' EXISTS in tasks table</div>";
} else {
    echo "<div class='error'>❌ Column 'created_by_student' MISSING! This is the problem!</div>";
    echo "<div class='code'>ALTER TABLE tasks ADD COLUMN created_by_student INT(11) NULL AFTER department_id;</div>";
    echo "<p><strong>Run this SQL in phpMyAdmin to fix the issue!</strong></p>";
}
echo '</div>';

// ============================================
// SECTION 3: CHECK STUDENT INFO
// ============================================
echo '<div class="section">';
echo '<h3>3. Student Information (ID: ' . $test_student_id . ')</h3>';
$stmt = $conn->prepare("SELECT * FROM students WHERE stud_id = ?");
$stmt->bind_param("i", $test_student_id);
$stmt->execute();
$student_info = $stmt->get_result()->fetch_assoc();

if ($student_info) {
    echo "<table>";
    echo "<tr><th>Field</th><th>Value</th></tr>";
    echo "<tr><td>Student ID</td><td>" . $student_info['stud_id'] . "</td></tr>";
    echo "<tr><td>Name</td><td>" . $student_info['firstname'] . " " . $student_info['lastname'] . "</td></tr>";
    echo "<tr><td>Email</td><td>" . $student_info['email'] . "</td></tr>";
    echo "<tr><td>Department ID</td><td>" . ($student_info['department_id'] ?? 'NULL') . "</td></tr>";
    echo "<tr><td>Instructor ID</td><td>" . ($student_info['instructor_id'] ?? 'NULL') . "</td></tr>";
    echo "</table>";
} else {
    echo "<div class='error'>❌ Student not found!</div>";
}
echo '</div>';

// ============================================
// SECTION 4: ALL TASKS IN DATABASE
// ============================================
echo '<div class="section">';
echo '<h3>4. All tasks in database</h3>';
$result = $conn->query("SELECT * FROM tasks ORDER BY task_id DESC LIMIT 20");
if ($result->num_rows > 0) {
    echo "<div class='info'>Found " . $result->num_rows . " task(s)</div>";
    echo "<table><tr><th>Task ID</th><th>Title</th><th>Instructor ID</th><th>Dept ID</th><th>Created By Student</th><th>Created At</th></tr>";
    while ($row = $result->fetch_assoc()) {
        $taskType = isset($row['created_by_student']) && $row['created_by_student'] ? 
            "<span style='color: blue;'>Verbal (Student " . $row['created_by_student'] . ")</span>" : 
            "<span style='color: green;'>Adviser</span>";
        echo "<tr>";
        echo "<td>" . $row['task_id'] . "</td>";
        echo "<td>" . htmlspecialchars($row['title']) . "</td>";
        echo "<td>" . $row['instructor_id'] . "</td>";
        echo "<td>" . $row['department_id'] . "</td>";
        echo "<td>" . $taskType . "</td>";
        echo "<td>" . ($row['created_at'] ?? 'N/A') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<div class='warning'>⚠️ No tasks found in database</div>";
}
echo '</div>';

// ============================================
// SECTION 5: STUDENT TASK ASSIGNMENTS
// ============================================
echo '<div class="section">';
echo '<h3>5. Task assignments for Student ID: ' . $test_student_id . '</h3>';
$stmt = $conn->prepare("SELECT * FROM student_tasks WHERE student_id = ? ORDER BY assigned_at DESC");
$stmt->bind_param("i", $test_student_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    echo "<div class='info'>Found " . $result->num_rows . " assignment(s)</div>";
    echo "<table><tr><th>STask ID</th><th>Task ID</th><th>Status</th><th>Assigned At</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['stask_id'] . "</td>";
        echo "<td>" . $row['task_id'] . "</td>";
        echo "<td>" . $row['status'] . "</td>";
        echo "<td>" . $row['assigned_at'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<div class='warning'>⚠️ No task assignments found for this student</div>";
}
echo '</div>';

// ============================================
// SECTION 6: TEST DASHBOARD QUERY
// ============================================
echo '<div class="section">';
echo '<h3>6. Testing dashboard JOIN query</h3>';

if ($has_created_by) {
    $stmt = $conn->prepare("
        SELECT 
            st.stask_id, 
            st.status, 
            st.assigned_at, 
            t.task_id, 
            t.title, 
            t.description, 
            t.created_by_student,
            t.created_at
        FROM student_tasks st
        INNER JOIN tasks t ON st.task_id = t.task_id
        WHERE st.student_id = ?
        ORDER BY t.created_at DESC
    ");
    
    $stmt->bind_param("i", $test_student_id);
    
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            echo "<div class='success'>✅ Found " . $result->num_rows . " task(s) with JOIN query</div>";
            echo "<table><tr><th>STask ID</th><th>Task Title</th><th>Status</th><th>Type</th><th>Created At</th></tr>";
            while ($row = $result->fetch_assoc()) {
                $taskType = $row['created_by_student'] ? 
                    "<span style='color: blue; font-weight: bold;'>Verbal (Student " . $row['created_by_student'] . ")</span>" : 
                    "<span style='color: green; font-weight: bold;'>Adviser</span>";
                echo "<tr>";
                echo "<td>" . $row['stask_id'] . "</td>";
                echo "<td><strong>" . htmlspecialchars($row['title']) . "</strong></td>";
                echo "<td>" . $row['status'] . "</td>";
                echo "<td>" . $taskType . "</td>";
                echo "<td>" . $row['created_at'] . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<div class='warning'>⚠️ No tasks found with JOIN query</div>";
            echo "<p>This means either:</p>";
            echo "<ul>";
            echo "<li>No tasks have been assigned to this student</li>";
            echo "<li>The student_tasks table is empty for this student</li>";
            echo "</ul>";
        }
    } else {
        echo "<div class='error'>❌ Query execution failed: " . $stmt->error . "</div>";
    }
} else {
    echo "<div class='error'>❌ Cannot test query - 'created_by_student' column is missing!</div>";
}
echo '</div>';

// ============================================
// SECTION 7: CREATE TEST TASK FORM
// ============================================
echo '<div class="section">';
echo '<h3>7. Create Test Verbal Task</h3>';

if (isset($_POST['create_test_task'])) {
    $test_title = trim($_POST['test_title']);
    $test_desc = trim($_POST['test_desc']);
    
    echo "<div class='info'>Attempting to create task...</div>";
    
    if ($student_info && $has_created_by) {
        // Try to insert task
        $stmt = $conn->prepare("INSERT INTO tasks (title, description, instructor_id, department_id, created_by_student) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("ssiii", 
            $test_title, 
            $test_desc, 
            $student_info['instructor_id'], 
            $student_info['department_id'], 
            $test_student_id
        );
        
        if ($stmt->execute()) {
            $new_task_id = $stmt->insert_id;
            echo "<div class='success'>✅ Task created successfully! Task ID: $new_task_id</div>";
            
            // Assign to student
            $stmt2 = $conn->prepare("INSERT INTO student_tasks (task_id, student_id, status) VALUES (?, ?, 'Pending')");
            $stmt2->bind_param("ii", $new_task_id, $test_student_id);
            
            if ($stmt2->execute()) {
                echo "<div class='success'>✅ Task assigned to student successfully!</div>";
                echo "<script>setTimeout(function(){ location.reload(); }, 2000);</script>";
            } else {
                echo "<div class='error'>❌ Failed to assign task: " . $stmt2->error . "</div>";
            }
        } else {
            echo "<div class='error'>❌ Failed to create task: " . $stmt->error . "</div>";
        }
    } else {
        if (!$student_info) {
            echo "<div class='error'>❌ Student not found!</div>";
        }
        if (!$has_created_by) {
            echo "<div class='error'>❌ Cannot create task - 'created_by_student' column missing!</div>";
        }
    }
}

echo '<form method="POST">';
echo '<label>Task Title:</label>';
echo '<input type="text" name="test_title" value="Test Verbal Task ' . time() . '" required>';
echo '<label>Description:</label>';
echo '<textarea name="test_desc" rows="3">This is a test task created by the debug tool</textarea>';
echo '<button type="submit" name="create_test_task">Create Test Task</button>';
echo '</form>';
echo '</div>';

// ============================================
// SECTION 8: RECOMMENDATIONS
// ============================================
echo '<div class="section">';
echo '<h3>8. Recommendations</h3>';

if (!$has_created_by) {
    echo "<div class='error'>";
    echo "<h4>🚨 CRITICAL ISSUE FOUND!</h4>";
    echo "<p>The 'created_by_student' column is missing from the tasks table.</p>";
    echo "<p><strong>To fix this, run the following SQL in phpMyAdmin:</strong></p>";
    echo "<div class='code'>";
    echo "ALTER TABLE tasks <br>";
    echo "ADD COLUMN created_by_student INT(11) NULL AFTER department_id,<br>";
    echo "ADD CONSTRAINT fk_tasks_created_by_student <br>";
    echo "FOREIGN KEY (created_by_student) REFERENCES students(stud_id) <br>";
    echo "ON DELETE SET NULL ON UPDATE CASCADE;";
    echo "</div>";
    echo "</div>";
} else if ($result->num_rows == 0) {
    echo "<div class='warning'>";
    echo "<h4>⚠️ No Tasks Assigned</h4>";
    echo "<p>The tasks table exists and has the correct structure, but:</p>";
    echo "<ul>";
    echo "<li>Either no tasks have been created yet</li>";
    echo "<li>Or no tasks have been assigned to student ID: $test_student_id</li>";
    echo "</ul>";
    echo "<p>Try creating a test task using the form above.</p>";
    echo "</div>";
} else {
    echo "<div class='success'>";
    echo "<h4>✅ Everything looks good!</h4>";
    echo "<p>The database structure is correct and tasks are being stored properly.</p>";
    echo "</div>";
}

echo '</div>';

?>

<div class="section">
    <h3>Quick Links</h3>
    <a href="student_dashboard.php" style="display: inline-block; padding: 10px 20px; background: #4CAF50; color: white; text-decoration: none; border-radius: 4px; margin: 5px;">← Back to Dashboard</a>
    <button onclick="location.reload()" style="background: #2196F3;">🔄 Refresh Page</button>
</div>

</body>
</html>