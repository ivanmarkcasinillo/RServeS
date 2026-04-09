<!--AR.php-->
<?php
session_start();
require "../dbconnect.php";

date_default_timezone_set('Asia/Manila'); // set to your timezone


// Verify student is logged in
if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['role'], ['Student', 'Coordinator', 'Instructor'])) {
    header("Location: ../../home2.php");
    exit;
}


// Department names
$departments = [
    1 => "College of Education",
    2 => "College of Technology",
    3 => "College of Hospitality and Tourism Management"
];
/* Determine whose AR to load */
if (in_array($_SESSION['role'], ['Coordinator', 'Instructor'])) {
    if (!isset($_GET['stud_id'])) {
        die("No student selected.");
    }
    $student_id = intval($_GET['stud_id']);
} else {
    $student_id = $_SESSION['stud_id'];
}

// Get student info
$stmt = $conn->prepare("
    SELECT s.stud_id, s.firstname, s.lastname, s.mi, s.year_level, s.semester, s.section,
       d.department_name, s.department_id,
       i.firstname AS adv_fname, i.lastname AS adv_lname, i.mi AS adv_mi
    FROM students s
    LEFT JOIN departments d ON s.department_id = d.department_id
    LEFT JOIN instructors i ON s.instructor_id = i.inst_id
    WHERE s.stud_id = ?
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

$fullname = $student['firstname'] 
          . (!empty($student['mi']) ? ' ' . strtoupper(substr($student['mi'],0,1)) . '.' : '')
          . ' ' . $student['lastname'];

// Fetch Adviser Name (Priority: Approvers > Section Adviser > Student Adviser)
$adviser_name = "";

// 1. Default to Student Adviser (if assigned directly)
if (!empty($student['adv_fname'])) {
    $adviser_name = strtoupper($student['adv_fname'] . ' ' . 
        (!empty($student['adv_mi']) ? substr($student['adv_mi'], 0, 1) . '. ' : '') . 
        $student['adv_lname']);
}

// 2. Try Section Adviser (Overwrites Student Adviser as it's more specific to the section)
if (!empty($student['section']) && !empty($student['department_id'])) {
    $stmt_adv = $conn->prepare("
        SELECT i.firstname, i.lastname, i.mi 
        FROM section_advisers sa
        JOIN instructors i ON sa.instructor_id = i.inst_id
        WHERE sa.department_id = ? AND sa.section = ?
    ");
    $stmt_adv->bind_param("is", $student['department_id'], $student['section']);
    $stmt_adv->execute();
    $res_adv = $stmt_adv->get_result();
    if ($row_adv = $res_adv->fetch_assoc()) {
        $adviser_name = strtoupper($row_adv['firstname'] . ' ' . 
            (!empty($row_adv['mi']) ? substr($row_adv['mi'], 0, 1) . '. ' : '') . 
            $row_adv['lastname']);
    }
    $stmt_adv->close();
}

// Fetch all accomplishment records
$stmt = $conn->prepare("
    SELECT ar.*, 
           i_assigner.firstname AS assigner_fname, i_assigner.lastname AS assigner_lname,
           i_approver.firstname AS approver_fname, i_approver.lastname AS approver_lname, i_approver.mi AS approver_mi
    FROM accomplishment_reports ar
    LEFT JOIN instructors i_assigner ON ar.assigner_id = i_assigner.inst_id
    LEFT JOIN instructors i_approver ON ar.approver_id = i_approver.inst_id
    WHERE ar.student_id = ? AND ar.status = 'Approved'
    ORDER BY ar.work_date ASC
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$accomplishments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 3. Use Actual Approvers from Accomplishment Reports (Highest Priority)
// If specific instructors approved these tasks, they are the ones who "Reviewed" it.
$approvers = [];
foreach ($accomplishments as $acc) {
    if (!empty($acc['approver_fname'])) {
        $name = strtoupper($acc['approver_fname'] . ' ' . 
            (!empty($acc['approver_mi']) ? substr($acc['approver_mi'], 0, 1) . '. ' : '') . 
            $acc['approver_lname']);
        if (!in_array($name, $approvers)) {
            $approvers[] = $name;
        }
    }
}
// If we found actual approvers in the records, use them instead of the generic section adviser
if (!empty($approvers)) {
    $adviser_name = implode(" / ", $approvers);
}

// Prefill task logic removed (moved to dashboard)

// Fetch organization accomplishments removed


// Calculate total hours
$total_hours = 0;      // Grand total
$daily_total_hours = 0; // Today's total
$today = date('Y-m-d');

foreach ($accomplishments as $acc) {
    $total_hours += floatval($acc['hours']);
    if ($acc['work_date'] === $today) {
        $daily_total_hours += floatval($acc['hours']);
    }
}

/* Org hours calculation removed */


// Time check logic removed (moved to dashboard)

// Handle form submission
if (!in_array($_SESSION['role'], ['Coordinator', 'Instructor'])) {
// Submission logic removed (moved to dashboard)


    // Handle delete
    if (isset($_GET['delete_id'])) {
        $delete_id = $_GET['delete_id'];

        // 1. Get the accomplishment report details before deleting
        $stmt_check = $conn->prepare("SELECT activity FROM accomplishment_reports WHERE id = ? AND student_id = ?");
        $stmt_check->bind_param("ii", $delete_id, $student_id);
        $stmt_check->execute();
        $res_check = $stmt_check->get_result();
        
        if ($row_check = $res_check->fetch_assoc()) {
            $activity = $row_check['activity'];
            
            // 2. Check for embedded TaskID
            if (preg_match('/\[\s*TaskID\s*:\s*(\d+)\s*\]/i', $activity, $matches)) {
                $stask_id = intval($matches[1]);
                
                // 3. Check if this is a Verbal Task created by the student
                // Join student_tasks and tasks to find if created_by_student matches current student
                $stmt_task = $conn->prepare("
                    SELECT t.task_id 
                    FROM student_tasks st 
                    JOIN tasks t ON st.task_id = t.task_id 
                    WHERE st.stask_id = ? AND t.created_by_student = ?
                ");
                $stmt_task->bind_param("ii", $stask_id, $student_id);
                $stmt_task->execute();
                $res_task = $stmt_task->get_result();
                
                if ($row_task = $res_task->fetch_assoc()) {
                    $task_id_to_delete = $row_task['task_id'];
                    
                    // 4. Delete the Verbal Task (and its assignment)
                    // Delete from student_tasks first (manual cascade)
                    $stmt_del_st = $conn->prepare("DELETE FROM student_tasks WHERE task_id = ?");
                    $stmt_del_st->bind_param("i", $task_id_to_delete);
                    $stmt_del_st->execute();
                    $stmt_del_st->close();
                    
                    // Delete from tasks
                    $stmt_del_t = $conn->prepare("DELETE FROM tasks WHERE task_id = ?");
                    $stmt_del_t->bind_param("i", $task_id_to_delete);
                    $stmt_del_t->execute();
                    $stmt_del_t->close();
                }
                $stmt_task->close();
            }
        }
        $stmt_check->close();

        // 5. Delete the Accomplishment Report
        $stmt = $conn->prepare("DELETE FROM accomplishment_reports WHERE id = ? AND student_id = ?");
        $stmt->bind_param("ii", $delete_id, $student_id);
        $stmt->execute();
        $stmt->close();
        
        $_SESSION['flash'] = "Accomplishment and associated verbal task deleted!";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>RSS Daily Accomplishment Report</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

<style>
@import url('https://fonts.googleapis.com/css2?family=Urbanist:wght@300;400;500;600;700&display=swap');

  :root {
  /* Dashboard Theme Colors */
  --primary-color: #1a4f7a;
  --secondary-color: #123755;
  --accent-color: #3a8ebd;
  --bg-color: #f4f7f6;
  --text-dark: #2c3e50;
  --sidebar-width: 260px;

  /* Existing Font Sizes */
  --fs-xs: clamp(0.75rem, 2vw, 0.85rem);
  --fs-sm: clamp(0.85rem, 2vw, 0.95rem);
  --fs-md: clamp(0.95rem, 2.5vw, 1rem);
  --fs-lg: clamp(1.1rem, 3vw, 1.3rem);
}

  html {
  font-size: 16px; /* baseline for rem scaling */
}

body {
  line-height: 1.4;
}

* {
  margin: 0;
  padding: 0;
box-sizing: border-box;
}


body {
  font-family: 'Urbanist', sans-serif;
  background: var(--bg-color);
  color: var(--text-dark);
  min-height: 100vh;
}

html, body {
  min-height: 100%;
}

.page-container {
  max-width: 1200px;
  margin: 20px auto;
  background: white;
  box-shadow: 0 0 20px rgba(26, 79, 122, 0.1);
  padding: 0;
  border-radius: 10px;
  overflow: hidden;
}

/* Tablet */
@media (max-width: 992px) {
  .page-container {
    margin: 10px;
  }
}

/* Mobile */
@media (max-width: 576px) {
  .page-container {
    margin: 0;
    box-shadow: none;
  }
}


.header-section {
  border-bottom: 3px solid #226baaff;
  padding: 15px 30px;
  text-align: center;
}

.header-section h1 {
 font-size: var(--fs-lg);
  font-weight: bold;
  margin-bottom: 5px;
}

.header-section h2 {
font-size: var(--fs-md);
  font-style: italic;
  margin-bottom: 10px;
}

.student-info-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
  padding: 20px 30px;
  font-size: var(--fs-sm);
  border-bottom: 2px solid var(--primary-color);
}


/* Student info responsiveness */
@media (max-width: 768px) {
  .student-info-grid {
    grid-template-columns: 1fr;
    font-size: 0.85rem;
  }

  .info-label {
    min-width: auto;
    width: 45%;
     font-size: var(--fs-sm);
  }
}

@media (max-width: 480px) {
  .student-info-grid {
    padding: 15px;
    font-size: 0.8rem;
  }
}

.info-row {
  display: flex;
  gap: 10px;
}

.info-label {
  font-weight: bold;
  min-width: 150px;
}

.table-section {
  padding: 20px 30px;
}

.table-section {
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}
.report-table {
  min-width: 900px;
  font-size: var(--fs-xs);
}


.report-table {
  width: 100%;
  border-collapse: collapse;
  margin-top: 15px;
  font-size: 0.85rem;
}

.report-table th {
  background: var(--secondary-color);
  color: white;
font-size: var(--fs-xs);
  padding: clamp(6px, 1.5vw, 8px);
  text-align: center;
  border: 1px solid #000;
  font-weight: bold;

}

@media (max-width: 576px) {
  .report-table {
    min-width: 720px; /* keeps columns readable */
  }
}

.report-table td {
  padding: clamp(6px, 1.5vw, 8px);
  border: 1px solid #000;
  text-align: center;
  vertical-align: middle;
}

.report-table td:nth-child(2) {
  text-align: left;
  padding-left: 12px;
}

.total-row {
  background: #f0f0f0;
  font-weight: bold;
}

.signature-section {
  padding: 40px 30px 30px;
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 50px;
}

.signature-box {
  text-align: center;
}

.signature-label {
  font-weight: bold;
  margin-top: 50px;
  padding-top: 10px;
  border-top: 2px solid #000000ff;
  font-size: 0.85rem;
}

.date-line {
  margin-top: 20px;
  text-align: right;
  font-size: 0.9rem;
}

/* Attachments Page */
.attachments-page {
  page-break-before: always;
  padding: 30px;
}

.attachments-header {
  text-align: center;
  font-size: 1.5rem;
  font-weight: bold;
  margin-bottom: 30px;
  border-bottom: 3px solid #000;
  padding-bottom: 10px;
}

.attachment-table {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 30px;
}

.attachment-table td {
  border: 2px solid #000;
  padding: 15px;
  vertical-align: top;
}

.attachment-table .event-cell {
  width: 35%;
  text-align: center;
  background: #f8f9fa;
}

.attachment-table .photos-cell {
  width: 65%;
}

.event-date {
  font-weight: bold;
  font-size: 0.95rem;
  margin-bottom: 10px;
}

.event-description {
  font-size: 0.85rem;
  color: #333;
}

.photo-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}

@media (max-width: 576px) {
  .photo-grid {
    grid-template-columns: 1fr;
  }

  .event-photo {
    height: 180px;
  }
}


.photo-item {
  text-align: center;
}

.photo-label {
  font-weight: bold;
  margin-bottom: 5px;
  font-size: 0.9rem;
}

.event-photo {
  width: 100%;
  height: 200px;
  object-fit: cover;
  border: 1px solid #ddd;
}

.footer-section {
  border-top: 3px solid #000;
  padding: 15px 30px;
  display: flex;
  justify-content: space-between;
  font-size: 0.8rem;
}

.action-buttons {
  padding: 20px;
  text-align: center;
  background: #f8f9fa;
   padding: 15px;
}

@media (max-width: 576px) {
  .action-buttons a,
  .action-buttons button {
    display: block;
    width: 100%;
    margin: 8px 0;
  }
}

.btn-custom {
  background: #007bff;
  color: white;
  border: none;
  padding: 12px 30px;
  border-radius: 5px;
  margin: 0 10px;
  font-weight: 600;
  cursor: pointer;
}

.btn-custom:hover {
  background: #0056b3;
  color: white;
}

.btn-print {
  background: #28a745;
}

.btn-print:hover {
  background: #218838;
}

@media print {
  .no-print {
    display: none !important;
  }
  
  .page-container {
    box-shadow: none;
    margin: 0;
  }
  
  .attachments-page {
    page-break-before: always;
  }
  
  body {
    background: white;
  }
    /* Disable scrolling containers */
  .table-section {
    overflow: visible !important;
  }

  /* Force table to fit page width */
  .report-table {
    width: 100% !important;
    min-width: 0 !important;
    table-layout: fixed;
  }

  /* Ensure all columns are visible */
  .report-table th,
  .report-table td {
    font-size: 0.75rem;
    padding: 6px;
    word-wrap: break-word;
    white-space: normal;
  }
 .report-table th:nth-child(1),
  .report-table td:nth-child(1) {
    width: 14%; /* Date */
  }

  .report-table th:nth-child(2),
  .report-table td:nth-child(2) {
    width: 40%; /* Activities */
    text-align: left;
  }

  .report-table th:nth-child(3),
  .report-table td:nth-child(3),
  .report-table th:nth-child(4),
  .report-table td:nth-child(4) {
    width: 12%; /* Time In / Out */
  }

  .report-table th:nth-child(5),
  .report-table td:nth-child(5) {
    width: 10%; /* Hours */
  }

  .report-table th:nth-child(6),
  .report-table td:nth-child(6) {
    width: 12%; /* Status */
  }


  @page {
    size: A4 portrait;
    margin: 15mm;
  }


  .report-table tr {
    page-break-inside: avoid;
  }



}

/* ---------- Action Icon (Formal + Responsive) ---------- */
.delete-icon i {
  font-size: 0.9rem;        /* default desktop */
  color: #6c757d;           /* muted gray */
}

.delete-icon:hover i {
  color: #495057;           /* subtle emphasis */
}




  /* Dashboard Theme Overrides */
  .modal-content {
      background: rgba(255, 255, 255, 0.95);
      backdrop-filter: blur(10px);
      border: 1px solid rgba(255, 255, 255, 0.2);
      box-shadow: 0 15px 35px rgba(0,0,0,0.2);
  }

  .modal-header {
      background: linear-gradient(90deg, var(--primary-color), var(--secondary-color)) !important;
      color: white;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
  }

  .btn-custom {
      background: linear-gradient(45deg, var(--primary-color), var(--accent-color));
      border: none;
      color: white;
      padding: 10px 25px;
      border-radius: 8px;
      transition: all 0.3s ease;
  }

  .btn-custom:hover {
      transform: translateY(-2px);
      box-shadow: 0 5px 15px rgba(58, 142, 189, 0.3);
      color: white;
  }

  .btn-secondary-custom {
      background: var(--secondary-color);
      color: white;
      border: none;
      padding: 10px 25px;
      border-radius: 8px;
      transition: all 0.3s ease;
      text-decoration: none;
      display: inline-block;
  }

  .btn-secondary-custom:hover {
      background: var(--primary-color);
      color: white;
      transform: translateY(-2px);
      box-shadow: 0 5px 15px rgba(26, 79, 122, 0.3);
  }

  .form-control:focus {
      border-color: var(--accent-color);
      box-shadow: 0 0 0 0.25rem rgba(58, 142, 189, 0.25);
  }
</style>
</head>
<body>

<!-- Flash Message -->
<?php if (isset($_SESSION['flash'])): ?>
  <div class="alert alert-success alert-dismissible fade show m-3 no-print">
    <?= htmlspecialchars($_SESSION['flash']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<!-- Action Buttons -->
<div class="action-buttons no-print">
    <?php
    // Dynamic dashboard URL based on role
    $dashboard_url = "#";
    $dept_name = isset($departments[$_SESSION['department_id']]) ? $departments[$_SESSION['department_id']] : "";
    $dept_code = strtolower(str_replace(" ", "_", $dept_name));

    if ($_SESSION['role'] === 'Student') {
        $dashboard_url = "../student_{$dept_code}_dashboard.php";
    } elseif ($_SESSION['role'] === 'Instructor') {
        $dashboard_url = "../../instructor/instructor_{$dept_code}_dashboard.php";
    } elseif ($_SESSION['role'] === 'Coordinator') {
        $dashboard_url = "../../coordinator/coordinator_{$dept_code}_dashboard.php";
    }
    ?>
    <a href="<?= htmlspecialchars($dashboard_url) ?>" class="btn btn-secondary-custom" aria-label="Return to Dashboard">
        Back to Dashboard
    </a>
    <?php if (!in_array($_SESSION['role'], ['Coordinator', 'Instructor'])): ?>
    <button class="btn btn-custom btn-print" onclick="window.print()" aria-label="Print Report">
        Print Report
    </button>
    <?php endif; ?>
</div>

<!-- Main Report Page -->
<div class="page-container">
  <!-- Header -->
  <div class="header-section">
    <h1>RETURN OF SERVICE SYSTEM (RSS)</h1>
    <h2>Daily Accomplishment Report</h2>
  </div>

  <!-- Student Information -->
  <div class="student-info-grid">
    <div class="info-row">
      <span class="info-label">Student's Name:</span>
      <span><?= htmlspecialchars($fullname) ?></span>
    </div>
    <div class="info-row">
      <span class="info-label">Course & Major:</span>
      <span><?= htmlspecialchars($student['course'] ?? 'BSIT Computer Technology') ?></span>
    </div>
    <div class="info-row">
      <span class="info-label">Year & Section:</span>
      <span><?= htmlspecialchars(
        ($student['year_level'] ?? 'N/A') . 
        (isset($student['section']) && $student['section'] ? ' - ' . $student['section'] : '')
      ) ?></span>
    </div>
    <div class="info-row">
      <span class="info-label">Department Area:</span>
      <span><?= htmlspecialchars($student['department_name']) ?></span>
    </div>
  </div>

  <!-- Accomplishment Table -->
  <div class="table-section">
    <?php if (empty($accomplishments)): ?>
      <div class="alert alert-info no-print">
        <strong>ℹ️ No accomplishments yet.</strong>
        <p class="mb-0">Click "Add Accomplishment" to start recording your RSS activities.</p>
      </div>
    <?php else: ?>
      <table class="report-table">
        <thead>
          <tr>
            <th style="width: 12%;">Date</th>
            <th style="width: 40%;">List of Activities Accomplished</th>
            <th style="width: 10%;">Time Started</th>
            <th style="width: 10%;">Time Ended</th>
            <th style="width: 10%;">No. of Hours</th>
            <th class="no-print" style="width: 10%;">Assigned<br> By</th>
            <th class="no-print" style="width: 10%;">Approved By</th>
            <th style="width: 13%;">Remarks/<br>Status</th>
<!-- Actions column removed -->
          </tr>
        </thead>
        <tbody>
  <?php foreach ($accomplishments as $acc): ?>
    <?php 
      // Parse activity for title
      $clean_activity = preg_replace('/\[\s*TaskID\s*:\s*\d+\s*\]/i', '', $acc['activity']);
      $parts = explode(':', $clean_activity, 2);
      $has_title = count($parts) > 1;
      $title = $has_title ? trim($parts[0]) : '';
      $desc = $has_title ? trim($parts[1]) : $clean_activity;
    ?>
    <tr>
      <td><?= date('F d, Y', strtotime($acc['work_date'])) ?></td>
      <td>
        <?php if ($has_title): ?>
          <strong><?= htmlspecialchars($title) ?> : </strong>
        <?php endif; ?>
        <?= htmlspecialchars($desc) ?>
      </td>
      <td><?= date('g:i A', strtotime($acc['time_start'])) ?></td>
      <td><?= $acc['time_end'] ? date('g:i A', strtotime($acc['time_end'])) : 'Pending' ?></td>
      <td><?= number_format(floatval($acc['hours']), 2) ?> hours</td>
      <td class="no-print">
        <?= !empty($acc['assigner_fname']) ? htmlspecialchars($acc['assigner_fname'] . ' ' . $acc['assigner_lname']) : 'N/A' ?>
      </td>
      <td class="no-print">
        <?= !empty($acc['approver_fname']) ? htmlspecialchars($acc['approver_fname'] . ' ' . $acc['approver_lname']) : 'N/A' ?>
      </td>
      <td><?= !empty($acc['hours']) ? 'Completed' : 'Pending' ?></td>
<!-- Action cell removed -->

    </tr>
  <?php endforeach; ?>

<!-- Organization rows removed -->

  <!-- Total Row -->
  <tr class="total-row">
  </tr>
</tbody>

          <!-- Total Hours Row -->
          <tr class="total-row">
            <td colspan="5" style="text-align: left; padding-left: 12px;">
              <strong>TOTAL HOURS: <?= number_format($total_hours, 2) ?> hours</strong>
            </td>
            <!-- Empty cells for Assigned/Approved columns (Screen only) -->
            <td class="no-print"></td>
            <td class="no-print"></td>
            <!-- Remarks column -->
            <td></td>
<!-- Action footer removed -->
          </tr>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <!-- Signature Section -->
  <div class="signature-section">
    <div class="signature-box">
      <div style="text-align: left;">
        <strong>Reviewed by:</strong>
      </div>
      <div class="signature-label">
        <?php if (!empty($adviser_name)): ?>
          <?= htmlspecialchars($adviser_name) ?><br>
          <span style="font-weight: normal; font-size: 0.9em;">Class Adviser</span>
        <?php else: ?>
          Class Adviser's Signature Over Printed Name
        <?php endif; ?>
      </div>
      <div class="date-line">
        Date: _________________
      </div>
    </div>
    
    <div class="signature-box">
      <div style="text-align: left;">
        <strong>Approved by:</strong>
      </div>
      <div class="signature-label">
        RSS Coordinator/College Dean Signature Over Printed Name
      </div>
      <div class="date-line">
        Date: _________________
      </div>
    </div>
  </div>

  <!-- Footer -->
  <div class="footer-section">
    <span>Website: www.llcc.edu.ph</span>
    <span>Fb page: LLCC Public Information Office</span>
    <span>Email: llccadmin@llcc.edu.ph</span>
  </div>
</div>

<!-- Attachments Page -->
<?php 
$photos_exist = false;
foreach ($accomplishments as $acc) {
  if (!empty($acc['photo']) || !empty($acc['photo'])) {
    
$photo_groups = [];
foreach ($accomplishments as $acc) {
    if (!empty($acc['photo']) || !empty($acc['photo2'])) {
        $date_key = date('F d, Y', strtotime($acc['work_date']));
        if (!isset($photo_groups[$date_key])) {
            $photo_groups[$date_key] = [
                'date' => $date_key,
                'activity' => $acc['activity'],
                'photos' => []
            ];
        }
        if (!empty($acc['photo'])) {
            $photo_groups[$date_key]['photos'][] = $acc['photo'];
        }
        if (!empty($acc['photo2'])) {
            $photo_groups[$date_key]['photos'][] = $acc['photo2'];
        }
    }
}

    $photos_exist = true;
    break;
  }
}
?>

<?php if ($photos_exist): ?>
<div class="page-container attachments-page">
  <!-- Attachments Header -->
  <div class="attachments-header">
    ATTACHMENTS
  </div>

  <!-- Attachments Table -->
  <table class="attachment-table">
    <thead>
      <tr>
        <td style="background: #333; color: white; font-weight: bold; text-align: center; padding: 10px;">EVENT</td>
        <td style="background: #333; color: white; font-weight: bold; text-align: center; padding: 10px;">SUPPORTING PHOTOS</td>
      </tr>
    </thead>
    <tbody>
      <?php 
      // Group photos by date for better organization
      $photo_groups = [];
      foreach ($accomplishments as $acc) {
        $date_key = date('F d, Y', strtotime($acc['work_date']));
        
        // Parse activity for title
        $clean_activity = preg_replace('/\[\s*TaskID\s*:\s*\d+\s*\]/i', '', $acc['activity']);
        $parts = explode(':', $clean_activity, 2);
        $has_title = count($parts) > 1;
        $title = $has_title ? trim($parts[0]) : '';
        $desc = $has_title ? trim($parts[1]) : $clean_activity;

        if (!isset($photo_groups[$date_key])) {
          $photo_groups[$date_key] = [
            'date' => $date_key,
            'activity' => $desc,
            'title' => $title,
            'photos' => []
          ];
        }
// Student photos
if (!empty($acc['photo'])) {
  $photo_groups[$date_key]['photos']['before'] = $acc['photo'];
}
if (!empty($acc['photo2'])) {
  $photo_groups[$date_key]['photos']['after'] = $acc['photo2'];
}

      }

/* Org photos processing removed */

      ?>
      
     <?php foreach ($photo_groups as $group): ?>

  <?php
    // Set paths for before and after photos
    $beforePath = $group['photos']['before'] ?? null;
    $afterPath  = $group['photos']['after'] ?? null;

    // Remove leading slash if present
    if ($beforePath && strpos($beforePath, '/') === 0) {
        $beforePath = ltrim($beforePath, '/');
    }
    if ($afterPath && strpos($afterPath, '/') === 0) {
        $afterPath = ltrim($afterPath, '/');
    }

    // Determine correct path (../ or ../../)
    $beforeFull = null;
    $afterFull  = null;

    if ($beforePath) {
        if (file_exists("../$beforePath")) {
            $beforeFull = "../$beforePath";
        } elseif (file_exists("../../$beforePath")) {
            $beforeFull = "../../$beforePath";
        }
    }

    if ($afterPath) {
        if (file_exists("../$afterPath")) {
            $afterFull = "../$afterPath";
        } elseif (file_exists("../../$afterPath")) {
            $afterFull = "../../$afterPath";
        }
    }
  ?>

  <tr>
    <td class="event-cell">
      <div class="event-date"><?= htmlspecialchars($group['date']) ?></div>
      <div class="event-description">
<!-- Org info removed -->
        <?php if (!empty($group['title'])): ?>
            <strong><?= htmlspecialchars($group['title']) ?> : </strong>
        <?php endif; ?>
        <?= htmlspecialchars($group['activity']) ?>
      </div>
    </td>
    <td class="photos-cell">
      <div class="photo-grid">

        <?php if ($beforeFull): ?>
          <div class="photo-item">
            <div class="photo-label">Before Documentation</div>
            <a href="<?= htmlspecialchars($beforeFull) ?>" target="_blank">
                <img src="<?= htmlspecialchars($beforeFull) ?>" alt="Before Photo" class="event-photo">
            </a>
          </div>
        <?php endif; ?>

        <?php if ($afterFull): ?>
          <div class="photo-item">
            <div class="photo-label">After Documentation</div>
            <a href="<?= htmlspecialchars($afterFull) ?>" target="_blank">
                <img src="<?= htmlspecialchars($afterFull) ?>" alt="After Photo" class="event-photo">
            </a>
          </div>
        <?php endif; ?>

      </div>
    </td>
  </tr>

<?php endforeach; ?>

    </tbody>
  </table>

  <!-- Footer -->
  <div class="footer-section">
    <span>Website: www.llcc.edu.ph</span>
    <span>Fb page: LLCC Public Information Office</span>
    <span>Email: llccadmin@llcc.edu.ph</span>
  </div>
</div>
<?php endif; ?>

<!-- Add Accomplishment Modal Moved to Dashboard -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<!-- Modal Scripts Moved -->


</body>
</html>
