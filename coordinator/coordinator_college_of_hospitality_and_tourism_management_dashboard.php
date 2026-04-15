<?php
session_start();
require "dbconnect.php";

// Auto-Setup DB Tables (Lazy Init)
$conn->query("CREATE TABLE IF NOT EXISTS rss_waivers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    status ENUM('Pending', 'Verified', 'Rejected') DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    verified_at TIMESTAMP NULL,
    verified_by INT NULL,
    FOREIGN KEY (student_id) REFERENCES students(stud_id) ON DELETE CASCADE
)");

$conn->query("CREATE TABLE IF NOT EXISTS rss_agreements (
    agreement_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    file_path VARCHAR(255),
    student_signature VARCHAR(255),
    parent_signature VARCHAR(255),
    status ENUM('Pending', 'Verified', 'Rejected') DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    verified_at TIMESTAMP NULL,
    verified_by INT NULL,
    FOREIGN KEY (student_id) REFERENCES students(stud_id) ON DELETE CASCADE
)");

// Ensure columns exist and have correct ENUM values
$conn->query("ALTER TABLE rss_enrollments ADD COLUMN IF NOT EXISTS status ENUM('Pending', 'Verified', 'Rejected') DEFAULT 'Pending'");
$conn->query("ALTER TABLE rss_enrollments MODIFY COLUMN status ENUM('Pending', 'Verified', 'Rejected') DEFAULT 'Pending'");
$conn->query("ALTER TABLE rss_enrollments ADD COLUMN IF NOT EXISTS signature_image LONGTEXT");

$conn->query("ALTER TABLE rss_agreements ADD COLUMN IF NOT EXISTS status ENUM('Pending', 'Verified', 'Rejected') DEFAULT 'Pending'");
$conn->query("ALTER TABLE rss_agreements MODIFY COLUMN status ENUM('Pending', 'Verified', 'Rejected') DEFAULT 'Pending'");
$conn->query("ALTER TABLE rss_agreements ADD COLUMN IF NOT EXISTS file_path VARCHAR(255)");
$conn->query("ALTER TABLE rss_agreements ADD COLUMN IF NOT EXISTS student_signature VARCHAR(255)");
$conn->query("ALTER TABLE rss_agreements ADD COLUMN IF NOT EXISTS parent_signature VARCHAR(255)");
$conn->query("ALTER TABLE rss_agreements ADD COLUMN IF NOT EXISTS verified_at TIMESTAMP NULL");
$conn->query("ALTER TABLE rss_agreements ADD COLUMN IF NOT EXISTS verified_by INT NULL");

// Ensure coordinator_notifications table exists
$conn->query("CREATE TABLE IF NOT EXISTS coordinator_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    coordinator_id INT NOT NULL,
    student_id INT NOT NULL,
    type ENUM('waiver', 'agreement', 'enrollment', 'task', 'other') NOT NULL,
    reference_id INT NOT NULL,
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(stud_id) ON DELETE CASCADE
)");

// Restrict to Coordinators
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Coordinator') {
    header("Location: ../home2.php");
    exit;
}

$email = $_SESSION['email'];
$uploadError = '';

// Handle photo upload form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['photo'])) {
    $uploadDir = 'uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $fileTmpPath = $_FILES['photo']['tmp_name'];
    $fileName = basename($_FILES['photo']['name']);
    $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];

    // Validate file extension and MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $fileTmpPath);
    finfo_close($finfo);

    $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif'];
    if (in_array($fileExt, $allowedExtensions) && in_array($mimeType, $allowedMimeTypes)) {
        $newFileName = uniqid('profile_', true) . '.' . $fileExt;
        $destPath = $uploadDir . $newFileName;

        if (move_uploaded_file($fileTmpPath, $destPath)) {
            $stmt = $conn->prepare("UPDATE coordinator SET photo = ? WHERE email = ?");
            $stmt->bind_param("ss", $destPath, $email);
            $stmt->execute();
            $stmt->close();

            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $uploadError = "Error moving uploaded file.";
        }
    } else {
        $uploadError = "Invalid file type. Only JPG, PNG, GIF allowed.";
    }
}

// Fetch coordinator info with mi
$stmt = $conn->prepare("SELECT coor_id, firstname, mi, lastname, photo FROM coordinator WHERE email=?");
$stmt->bind_param("s", $email);
$stmt->execute();
$coord = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (isset($_POST['mark_all_read'])) {
    $conn->query("UPDATE coordinator_notifications SET is_read = TRUE WHERE coordinator_id = {$coord['coor_id']}");
    exit;
}

/* -------------------  CHANGE PASSWORD ------------------- */
if (isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    $coor_id = $coord['coor_id'];

    $stmt = $conn->prepare("SELECT password FROM coordinator WHERE coor_id = ?");
    $stmt->bind_param("i", $coor_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res->fetch_assoc();
    $stmt->close();

    if ($user && password_verify($current_password, $user['password'])) {
        if ($new_password === $confirm_password) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $up = $conn->prepare("UPDATE coordinator SET password = ? WHERE coor_id = ?");
            $up->bind_param("si", $hashed_password, $coor_id);
            if ($up->execute()) {
                $msg = "✅ Password changed successfully!";
            } else {
                $msg = "❌ Error updating password.";
            }
            $up->close();
        } else {
            $msg = "❌ New passwords do not match.";
        }
    } else {
        $msg = "❌ Current password is incorrect.";
    }
    header("Location: " . $_SERVER['PHP_SELF'] . "?msg=" . urlencode($msg));
    exit;
}

$mi_val = trim($coord['mi']);
$coord_name = $coord['lastname'] . ', ' . $coord['firstname'] . ($mi_val ? ' ' . $mi_val : '');
$coord_photo = $coord['photo'] ?: 'default_profile.png';

// Fetch all enrolled RSS students + mi + waiver status
$sql = "
  SELECT s.stud_id, s.firstname, s.mi, s.lastname, s.email, d.dept_name AS department,
         w.status AS waiver_status, w.file_path AS waiver_file
  FROM students s
  JOIN departments d ON s.department_id = d.dept_id
  LEFT JOIN documents w ON w.stud_id = s.stud_id AND w.doc_type = 'waiver'
  WHERE d.dept_id = 3
  ORDER BY s.lastname
";

$result = $conn->query($sql);
if (!$result) {
    die("Query failed: " . $conn->error);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Coordinator – RSS Dashboard</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<style>
:root {
  /* Standard Palette */
  --primary-color: #1a4f7a;
  --secondary-color: #123755;
  --accent-color: #3a8ebd;
  --bg-color: #f4f7f6;
  --text-dark: #2c3e50;

  /* Aliases for existing code */
  --bg:          var(--bg-color);
  --card:        #ffffff;
  --accent:      var(--accent-color);
  --primary:     var(--primary-color);
  --secondary:   var(--secondary-color);
}
body {
  background: var(--bg);
  color: var(--text-dark);
  font-family: "Segoe UI", Arial, sans-serif;
}
.navbar {
  background: linear-gradient(90deg, var(--primary), var(--secondary));
  opacity: 0.95;
}
.card {
  background: var(--card);
  border-color: var(--accent);
}
.btn-accent {
  background: var(--accent);
  color: #fff;
}
.btn-accent:hover {
  background: var(--primary);
  color: #fff;
}

/* Password Toggle Styles */
.password-container {
    position: relative;
    width: 100%;
}

.password-container input {
    padding-right: 35px !important;
}

.password-toggle-icon {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    cursor: pointer;
    color: #666;
    z-index: 10;
    font-size: 0.9rem;
}

/* Mobile Header Styles */
.mobile-header {
    display: none; /* Hidden by default */
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    background: linear-gradient(90deg, var(--primary) 0%, var(--secondary) 100%);
    flex-direction: column;
    padding: 0;
    z-index: 1040;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
}

.mobile-header-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.5rem 1rem;
    width: 100%;
}

.mobile-header-nav {
    display: flex;
    justify-content: space-around;
    width: 100%;
    padding-bottom: 0.5rem;
    border-top: 1px solid rgba(255,255,255,0.1);
    padding-top: 0.5rem;
}

.mobile-header .nav-item {
    color: rgba(255,255,255,0.7);
    text-decoration: none;
    display: flex;
    flex-direction: column;
    align-items: center;
    font-size: 0.75rem;
    padding: 0.25rem;
}

.mobile-header .nav-item.active {
    color: white;
    font-weight: bold;
}

.mobile-header .nav-item i {
    font-size: 1.2rem;
    margin-bottom: 2px;
}

/* Mobile Responsive */
@media (max-width: 768px) {
    .mobile-header {
        display: flex;
    }
    
    .navbar { display: none !important; } /* Hide default navbar on mobile */

    body {
        padding-top: 110px; /* Adjust for taller header */
    }
}

body {
    opacity: 0;
    animation: rservePageFadeIn 520ms ease forwards;
}

@keyframes rservePageFadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.rserve-page-loader {
    position: fixed;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(rgba(13, 61, 97, 0.92), rgba(29, 110, 160, 0.88));
    z-index: 99999;
    opacity: 1;
    transition: opacity 360ms ease;
}

.rserve-page-loader.rserve-page-loader--hide {
    opacity: 0;
}

.rserve-page-loader__inner {
    width: min(420px, 90vw);
    padding: 22px 18px;
    border-radius: 16px;
    background: rgba(255, 255, 255, 0.10);
    box-shadow: 0 16px 40px rgba(0,0,0,0.35);
    text-align: center;
    backdrop-filter: blur(8px);
}

.rserve-page-loader__brand {
    font-weight: 800;
    letter-spacing: 0.4px;
    color: rgba(255, 255, 255, 0.95);
    margin-bottom: 10px;
    font-size: 1.15rem;
}

.rserve-page-loader__spinner {
    width: 42px;
    height: 42px;
    border-radius: 999px;
    border: 4px solid rgba(255, 255, 255, 0.25);
    border-top-color: rgba(255, 255, 255, 0.95);
    margin: 0 auto 12px;
    animation: rserveSpin 900ms linear infinite;
}

.rserve-page-loader__text {
    color: rgba(255, 255, 255, 0.92);
    font-weight: 600;
    font-size: 0.95rem;
}

@keyframes rserveSpin {
    to { transform: rotate(360deg); }
}

@media (prefers-reduced-motion: reduce) {
    body { animation: none; opacity: 1; }
    .rserve-page-loader { transition: none; }
    .rserve-page-loader__spinner { animation: none; }
}

</style>
<link rel="stylesheet" href="../assets/css/rserve-dashboard-theme.css">
</head>
<body class="rserve-theme">

<div id="rserve-page-loader" class="rserve-page-loader" aria-hidden="true">
    <div class="rserve-page-loader__inner">
        <div class="rserve-page-loader__brand">RServeS</div>
        <div class="rserve-page-loader__spinner"></div>
        <div class="rserve-page-loader__text">Loading your dashboard...</div>
    </div>
</div>

<div class="mobile-header d-md-none">
    <div class="mobile-header-top">
        <div class="d-flex align-items-center">
             <img src="../img/logo.png" alt="Logo" style="height: 30px; width: auto; margin-right: 8px;">
             <span class="text-white fw-bold fs-5">RServeS</span>
        </div>
        <div class="d-flex align-items-center" data-bs-toggle="modal" data-bs-target="#profileModal" style="cursor: pointer;">
            <span class="text-white me-2 fw-bold" style="font-size: 0.9rem;">Coordinator</span>
            <img src="<?= htmlspecialchars($coord_photo) ?>?v=<?=time()?>" alt="Profile" 
                 class="rounded-circle border border-2 border-white"
                 style="width: 35px; height: 35px; object-fit: cover;">
        </div>
    </div>
    <div class="mobile-header-nav">
        <a href="#dashboard-overview" class="nav-item active">
            <i class="fas fa-th-large"></i>
            <span>Overview</span>
        </a>
        <a href="#students-panel" class="nav-item">
            <i class="fas fa-users"></i>
            <span>Students</span>
        </a>
        <a href="logout.php" class="nav-item">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
    </div>
</div>
<div class="d-flex" id="wrapper">
  <div id="sidebar-wrapper">
    <div class="sidebar-shell">
      <div class="sidebar-heading">
        <span class="sidebar-brand-title">RServeS Portal</span>
        <span class="sidebar-brand-subtitle">Coordinator Workspace</span>
      </div>
      <div class="list-group list-group-flush">
        <a href="#dashboard-overview" class="list-group-item list-group-item-action active">
          <i class="fas fa-th-large"></i> Overview
        </a>
        <a href="#students-panel" class="list-group-item list-group-item-action">
          <i class="fas fa-users"></i> Students
        </a>
        <a href="#" class="list-group-item list-group-item-action" data-bs-toggle="modal" data-bs-target="#profileModal">
          <i class="fas fa-user-circle"></i> Profile
        </a>
        <a href="logout.php" class="list-group-item list-group-item-action">
          <i class="fas fa-sign-out-alt"></i> Logout
        </a>
      </div>
      <div class="role-sidebar-card">
        <div class="sidebar-role-profile">
          <img src="<?= htmlspecialchars($coord_photo) ?>?v=<?= time() ?>" alt="Profile" class="sidebar-role-avatar">
          <div>
            <div class="sidebar-role-name"><?= htmlspecialchars($coord_name) ?></div>
            <div class="sidebar-role-meta">Coordinator | Hospitality and Tourism</div>
          </div>
        </div>
        <button type="button" class="sidebar-support-btn" data-bs-toggle="modal" data-bs-target="#profileModal">
          Profile Center
        </button>
      </div>
    </div>
  </div>

  <div id="page-content-wrapper">
    <nav class="navbar navbar-expand-lg">
      <div class="topbar-shell">
        <div class="topbar-tabs d-none d-lg-flex">
          <a href="#dashboard-overview" class="topbar-tab active">Overview</a>
          <a href="#students-panel" class="topbar-tab">Students</a>
        </div>

        <div class="topbar-actions">
          <div class="topbar-profile" data-bs-toggle="modal" data-bs-target="#profileModal">
            <div class="topbar-identity">
              <div><?= htmlspecialchars($coord_name) ?></div>
              <div>Coordinator | Hospitality and Tourism</div>
            </div>
            <img src="<?= htmlspecialchars($coord_photo) ?>?v=<?= time() ?>" alt="Profile" class="topbar-avatar">
          </div>
        </div>
      </div>
    </nav>

    <div class="container-fluid py-4">
      <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-info alert-dismissible fade show mb-4" role="alert">
          <?php echo htmlspecialchars($_GET['msg']); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>
      <?php if (!empty($uploadError)): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
          <?php echo htmlspecialchars($uploadError); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <div class="content-card mb-4" id="dashboard-overview">
        <h3 class="mb-2">Coordinator Dashboard</h3>
        <p class="mb-1"><i class="fas fa-envelope me-2"></i><?= htmlspecialchars($email) ?></p>
        <p class="text-muted mb-0">Review submitted coordinator requirements and keep Hospitality and Tourism student records moving.</p>
      </div>

      <div class="card p-3 mb-4" id="students-panel">
        <h3>Enrolled RSS Students</h3>
        <div class="table-responsive">
          <table class="table table-hover align-middle">
            <thead class="table-primary">
              <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Department</th>
                <th>Waiver Status</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php
              if ($result->num_rows > 0):
                  while ($s = $result->fetch_assoc()):
                      $status = $s['waiver_status'] ?: 'Pending';
                      $badge = $status === 'Verified' ? 'success' : ($status === 'Rejected' ? 'danger' : 'warning');

                      $middleInitial = trim($s['mi']);
                      $fullName = $s['lastname'] . ', ' . $s['firstname'] . ($middleInitial ? ' ' . $middleInitial : '');
              ?>
              <tr>
                <td><?= htmlspecialchars($fullName) ?></td>
                <td><?= htmlspecialchars($s['email']) ?></td>
                <td><?= htmlspecialchars($s['department']) ?></td>
                <td><span class="badge bg-<?= $badge ?>"><?= htmlspecialchars($status) ?></span></td>
                <td>
                  <?php if ($s['waiver_file']): ?>
                    <a href="<?= htmlspecialchars($s['waiver_file']) ?>" target="_blank" class="btn btn-sm btn-accent">View</a>
                  <?php else: ?>
                    <span class="text-muted">No File</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php
                  endwhile;
              else:
              ?>
              <tr><td colspan="5" class="text-center">No records found.</td></tr>
              <?php
              endif;
              $result->free();
              $conn->close();
              ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>


<!-- Profile Modal -->
<div class="modal fade" id="profileModal" tabindex="-1" style="z-index: 10000;">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">My Profile</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center">
        <img src="<?= htmlspecialchars($coord_photo) ?>?v=<?=time()?>" class="rounded-circle mb-3" style="width: 120px; height: 120px; object-fit: cover; border: 4px solid var(--primary-color);">
        <h4><?= htmlspecialchars($coord_name) ?></h4>
        <p class="text-muted"><?= htmlspecialchars($email) ?></p>
        <hr>
        <form action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" method="POST" class="text-start">
            <h6 class="fw-bold mb-3 text-center">Change Password</h6>
            <div class="mb-2">
                <label class="form-label small">Current Password</label>
                <div class="password-container">
                    <input type="password" name="current_password" class="form-control form-control-sm" required id="coord_hosp_current_password">
                    <i class="fas fa-eye-slash password-toggle-icon" onclick="togglePasswordVisibility(this, 'coord_hosp_current_password')"></i>
                </div>
            </div>
            <div class="mb-2">
                <label class="form-label small">New Password</label>
                <div class="password-container">
                    <input type="password" name="new_password" class="form-control form-control-sm" required id="coord_hosp_new_password">
                    <i class="fas fa-eye-slash password-toggle-icon" onclick="togglePasswordVisibility(this, 'coord_hosp_new_password')"></i>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label small">Confirm New Password</label>
                <div class="password-container">
                    <input type="password" name="confirm_password" class="form-control form-control-sm" required id="coord_hosp_confirm_password">
                    <i class="fas fa-eye-slash password-toggle-icon" onclick="togglePasswordVisibility(this, 'coord_hosp_confirm_password')"></i>
                </div>
            </div>
            <button type="submit" name="change_password" class="btn btn-warning btn-sm w-100">Update Password</button>
        </form>
        <hr>
        <form method="POST" enctype="multipart/form-data" action="">
            <div class="mb-3 text-start">
                <label class="form-label">Change Profile Photo</label>
                <input type="file" name="photo" class="form-control" accept="image/*" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">Upload New Photo</button>
        </form>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePasswordVisibility(icon, fieldId) {
    const field = document.getElementById(fieldId);
    if (field.type === "password") {
        field.type = "text";
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    } else {
        field.type = "password";
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    }
}
</script>
<script>
    window.addEventListener('load', function() {
        const loader = document.getElementById('rserve-page-loader');
        if (!loader) return;
        loader.classList.add('rserve-page-loader--hide');
        window.setTimeout(() => {
            if (loader && loader.parentNode) loader.parentNode.removeChild(loader);
        }, 420);
    });
</script>
</body>
</html>
