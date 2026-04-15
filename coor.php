<?php
//coordinator
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
// Ensure columns exist and have correct ENUM values
$conn->query("ALTER TABLE rss_enrollments ADD COLUMN IF NOT EXISTS status ENUM('Pending', 'Verified', 'Rejected') DEFAULT 'Pending'");
$conn->query("ALTER TABLE rss_enrollments MODIFY COLUMN status ENUM('Pending', 'Verified', 'Rejected') DEFAULT 'Pending'");
$conn->query("ALTER TABLE rss_enrollments ADD COLUMN IF NOT EXISTS signature_image LONGTEXT");

$conn->query("ALTER TABLE rss_agreements ADD COLUMN IF NOT EXISTS status ENUM('Pending', 'Verified', 'Rejected') DEFAULT 'Pending'");
$conn->query("ALTER TABLE rss_agreements MODIFY COLUMN status ENUM('Pending', 'Verified', 'Rejected') DEFAULT 'Pending'");
$conn->query("ALTER TABLE rss_agreements ADD COLUMN IF NOT EXISTS student_signature VARCHAR(255)");
$conn->query("ALTER TABLE rss_agreements ADD COLUMN IF NOT EXISTS parent_signature VARCHAR(255)");


// Restrict to Coordinators
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Coordinator') {
    header("Location: ../home2.php");
    exit;
}

$email = $_SESSION['email'];

// Fetch coordinator info
$stmt = $conn->prepare("SELECT coor_id, firstname, mi, lastname, photo FROM coordinator WHERE email=?");
$stmt->bind_param("s", $email);
$stmt->execute();
$coord = $stmt->get_result()->fetch_assoc();
$stmt->close();

$mi_val = trim($coord['mi']);
$coord_name = $coord['lastname'] . ', ' . $coord['firstname'] . ($mi_val ? ' ' . $mi_val : '');
$coord_photo = $coord['photo'] ?: 'default_profile.png';
if ($coord_photo !== 'default_profile.png' && !str_starts_with($coord_photo, 'uploads/')) {
     $coord_photo = 'uploads/' . $coord_photo;
}

// Fetch students grouped by year level and section
$sql = "
  SELECT 
    s.stud_id, 
    s.firstname, 
    s.mi, 
    s.lastname, 
    s.email, 
    COALESCE(s.year_level, 1) as year_level,
    COALESCE(s.section, 'A') as section,
    d.department_name
  FROM students s
  JOIN departments d ON s.department_id = d.department_id
  WHERE d.department_id = 2
  ORDER BY s.year_level, s.section, s.lastname
";

$result = $conn->query($sql);
$students_by_year = [];
$total_students = 0;
$pending_verifications = 0;
$completed_students = 0;

while ($row = $result->fetch_assoc()) {
    $year = $row['year_level'];
    $section = $row['section'];
    
    // Get completed hours
    $ar_query = $conn->query("
        SELECT COALESCE(SUM(hours), 0) as total_hours 
        FROM accomplishment_reports 
        WHERE student_id = {$row['stud_id']}
    ");
    $ar_result = $ar_query ? $ar_query->fetch_assoc() : ['total_hours' => 0];
    $row['completed_hours'] = $ar_result['total_hours'];
    
    // Get document statuses
    // Waiver
    $w_q = $conn->query("SELECT status FROM rss_waivers WHERE student_id = {$row['stud_id']}");
    $row['waiver_status'] = ($w_q && $w_q->num_rows > 0) ? $w_q->fetch_assoc()['status'] : 'Pending';

    // Agreement
    $a_q = $conn->query("SELECT status FROM rss_agreements WHERE student_id = {$row['stud_id']}");
    $row['agreement_status'] = ($a_q && $a_q->num_rows > 0) ? $a_q->fetch_assoc()['status'] : 'Pending';

    // Enrollment
    $e_q = $conn->query("SELECT status FROM rss_enrollments WHERE student_id = {$row['stud_id']}");
    $row['enrollment_status'] = ($e_q && $e_q->num_rows > 0) ? $e_q->fetch_assoc()['status'] : 'Pending';
    
    // Determine status
    $docsVerified = ($row['waiver_status'] === 'Verified' && $row['agreement_status'] === 'Verified' && $row['enrollment_status'] === 'Verified');
    $hoursComplete = $row['completed_hours'] >= 320; // Assuming 320 hours required
    
    if ($docsVerified && $hoursComplete) {
        $row['overall_status'] = 'Completed';
    } elseif ($docsVerified) {
        $row['overall_status'] = 'Verified';
    } else {
        $row['overall_status'] = 'Pending';
    }
    
    if ($row['overall_status'] === 'Pending') {
        $pending_verifications++;
    }
    if ($row['overall_status'] === 'Completed' || $row['overall_status'] === 'Verified') {
        $completed_students++;
    }
    
    if (!isset($students_by_year[$year])) {
        $students_by_year[$year] = [];
    }
    if (!isset($students_by_year[$year][$section])) {
        $students_by_year[$year][$section] = [];
    }
    $students_by_year[$year][$section][] = $row;
    $total_students++;
}

$result->free();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Coordinator Dashboard</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Urbanist:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <style>
        :root {
            --primary-color: #1d6ea0;
            --secondary-color: #0d3c61;
            --accent-color: #4fb2d8;
            --bg-color: #f8f9fa;
            --text-dark: #123047;
            --sidebar-width: 260px;
        }

        body {
            font-family: 'Urbanist', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-dark);
            overflow-x: hidden;
        }

        /* Sidebar Styles */
        #sidebar-wrapper {
            min-height: 100vh;
            width: var(--sidebar-width);
            margin-left: 0;
            transition: margin 0.25s ease-out;
            background: linear-gradient(180deg, var(--primary-color), var(--secondary-color));
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1000;
        }

        #sidebar-wrapper .sidebar-heading {
            padding: 1.5rem;
            font-size: 1.5rem;
            font-weight: 700;
            color: white;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        #sidebar-wrapper .list-group {
            width: var(--sidebar-width);
        }

        #sidebar-wrapper .list-group-item {
            background-color: transparent;
            color: rgba(255,255,255,0.8);
            border: none;
            padding: 1rem 1.5rem;
            font-weight: 500;
            transition: all 0.3s;
        }

        #sidebar-wrapper .list-group-item:hover,
        #sidebar-wrapper .list-group-item.active {
            background-color: rgba(255,255,255,0.1);
            color: white;
            padding-left: 2rem;
            border-left: 4px solid var(--accent-color);
        }

        #sidebar-wrapper .list-group-item i {
            width: 25px;
            margin-right: 10px;
        }

        /* Page Content */
        #page-content-wrapper {
            width: 100%;
            margin-left: var(--sidebar-width);
            transition: margin 0.25s ease-out;
        }

        .navbar {
            padding: 1rem 2rem;
            background: white;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }

        .container-fluid {
            padding: 2rem;
        }

        /* Cards */
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            transition: transform 0.3s;
            height: 100%;
            border-left: 5px solid var(--primary-color);
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-icon {
            font-size: 2.5rem;
            color: var(--primary-color);
            margin-bottom: 1rem;
        }

        /* Data Tables / Grids */
        .content-card {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
        }

        .year-card {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            border: 1px solid #eee;
            box-shadow: 0 4px 6px rgba(0,0,0,0.02);
        }

        .year-card:hover {
            transform: translateY(-5px);
            border-color: var(--accent-color);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }

        .year-icon {
            font-size: 3rem;
            color: var(--secondary-color);
            margin-bottom: 1rem;
        }

        .section-badge {
            font-size: 0.9rem;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            background: #e9ecef;
            color: var(--text-dark);
            margin: 0.25rem;
            display: inline-block;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .section-badge:hover {
            background: var(--accent-color);
            color: white;
        }

        /* Profile */
        .profile-img-nav {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--primary-color);
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            #sidebar-wrapper {
                margin-left: calc(-1 * var(--sidebar-width));
            }
            #page-content-wrapper {
                margin-left: 0;
            }
            body.sidebar-toggled #sidebar-wrapper {
                margin-left: 0;
            }
            body.sidebar-toggled #page-content-wrapper {
                margin-left: 0; /* Overlay effect */
            }
            body.sidebar-toggled::before {
                content: '';
                position: fixed;
                top: 0; left: 0; right: 0; bottom: 0;
                background: rgba(0,0,0,0.5);
                z-index: 999;
            }
        }
        
        .table th {
            font-weight: 600;
            color: var(--secondary-color);
        }
        .table td {
            vertical-align: middle;
        }
    </style>
</head>
<body>

<div class="d-flex" id="wrapper">
    <!-- Sidebar -->
    <div id="sidebar-wrapper">
        <div class="sidebar-heading">
            <i class="fas fa-university me-2"></i> Coordinator
        </div>
        <div class="list-group list-group-flush">
            <a href="#" class="list-group-item list-group-item-action active" onclick="showView('dashboard', this)">
                <i class="fas fa-th-large"></i> Dashboard
            </a>
            <a href="#" class="list-group-item list-group-item-action" onclick="showView('records', this)">
                <i class="fas fa-users"></i> Student Records
            </a>
            <a href="#" class="list-group-item list-group-item-action" onclick="showView('reports', this)">
                <i class="fas fa-chart-bar"></i> Reports
            </a>
            <a href="#" class="list-group-item list-group-item-action" data-bs-toggle="modal" data-bs-target="#profileModal">
                <i class="fas fa-user-circle"></i> Profile
            </a>
            <a href="logout.php" class="list-group-item list-group-item-action mt-auto">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>

    <!-- Page Content -->
    <div id="page-content-wrapper">
        <nav class="navbar navbar-expand-lg navbar-light">
            <button class="btn btn-outline-primary" id="menu-toggle">
                <i class="fas fa-bars"></i>
            </button>
            
            <div class="ms-auto d-flex align-items-center">
                <div class="me-3 text-end d-none d-md-block">
                    <div class="fw-bold"><?php echo htmlspecialchars($coord_name); ?></div>
                    <small class="text-muted">Coordinator</small>
                </div>
                <img src="<?php echo htmlspecialchars($coord_photo); ?>" alt="Profile" class="profile-img-nav" data-bs-toggle="modal" data-bs-target="#profileModal" style="cursor: pointer;">
            </div>
        </nav>

        <div class="container-fluid" id="main-content">
            <!-- Content rendered via JS -->
        </div>
    </div>
</div>

<!-- Modals -->

<!-- Document Verification Modal -->
<div class="modal fade" id="docVerifyModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Document Verification</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="docVerifyContent">
                <div class="text-center"><div class="spinner-border"></div> Loading...</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" onclick="verifyAction('reject')">Reject</button>
                <button type="button" class="btn btn-success" onclick="verifyAction('verify')">Verify</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Profile Modal -->
<div class="modal fade" id="profileModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">My Profile</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <img src="<?php echo htmlspecialchars($coord_photo); ?>" class="rounded-circle mb-3" style="width: 120px; height: 120px; object-fit: cover; border: 4px solid var(--primary-color);">
                <h4><?php echo htmlspecialchars($coord_name); ?></h4>
                <p class="text-muted"><?php echo htmlspecialchars($email); ?></p>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const studentsData = <?php echo json_encode($students_by_year); ?>;
    const stats = {
        total: <?php echo $total_students; ?>,
        pending: <?php echo $pending_verifications; ?>,
        completed: <?php echo $completed_students; ?>
    };

    // Toggle Sidebar
    document.getElementById('menu-toggle').addEventListener('click', function() {
        document.body.classList.toggle('sidebar-toggled');
    });

    // View Management
    function showView(view, el) {
        if(el) {
            document.querySelectorAll('.list-group-item').forEach(item => item.classList.remove('active'));
            el.classList.add('active');
        }
        
        const container = document.getElementById('main-content');
        container.innerHTML = '';
        
        if (view === 'dashboard') {
            renderDashboard(container);
        } else if (view === 'records') {
            renderRecords(container);
        } else if (view === 'reports') {
            container.innerHTML = '<div class="alert alert-info">Reports module coming soon...</div>';
        }
    }

    function renderDashboard(container) {
        const html = `
            <h2 class="mb-4">Dashboard Overview</h2>
            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-user-graduate"></i></div>
                        <h3>${stats.total}</h3>
                        <p class="text-muted">Total Students</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card">
                        <div class="stat-icon" style="color: #ffc107;"><i class="fas fa-file-contract"></i></div>
                        <h3>${stats.pending}</h3>
                        <p class="text-muted">Pending Verifications</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card">
                        <div class="stat-icon" style="color: #198754;"><i class="fas fa-check-circle"></i></div>
                        <h3>${stats.completed}</h3>
                        <p class="text-muted">Completed Students</p>
                    </div>
                </div>
            </div>
            
            <div class="content-card">
                <h4 class="mb-3">Quick Actions</h4>
                <button class="btn btn-primary" onclick="showView('records', document.querySelectorAll('.list-group-item')[1])">
                    <i class="fas fa-search me-2"></i> Review Student Documents
                </button>
            </div>
        `;
        container.innerHTML = html;
    }

    function renderRecords(container) {
        container.innerHTML = `
            <div id="classes-nav" class="mb-4">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item active" aria-current="page">Year Levels</li>
                    </ol>
                </nav>
            </div>
            <div id="classes-content" class="row g-4"></div>
        `;
        renderYears();
    }

    function renderYears() {
        const content = document.getElementById('classes-content');
        const nav = document.getElementById('classes-nav').querySelector('.breadcrumb');
        nav.innerHTML = '<li class="breadcrumb-item active">Year Levels</li>';
        
        let html = '';
        const years = Object.keys(studentsData).sort();
        
        if (years.length === 0) {
            html = '<div class="col-12 text-center text-muted">No student records found.</div>';
        } else {
            years.forEach(year => {
                const sections = studentsData[year];
                const sectionCount = Object.keys(sections).length;
                let studentCount = 0;
                Object.values(sections).forEach(s => studentCount += s.length);

                html += `
                    <div class="col-md-6 col-lg-3">
                        <div class="year-card" onclick="renderSections(${year})">
                            <div class="year-icon"><i class="fas fa-layer-group"></i></div>
                            <h4>Year ${year}</h4>
                            <p class="text-muted mb-0">${sectionCount} Sections</p>
                            <p class="text-muted small">${studentCount} Students</p>
                        </div>
                    </div>
                `;
            });
        }
        content.innerHTML = html;
    }

    function renderSections(year) {
        const content = document.getElementById('classes-content');
        const nav = document.getElementById('classes-nav').querySelector('.breadcrumb');
        
        nav.innerHTML = `
            <li class="breadcrumb-item"><a href="#" onclick="renderYears(); return false;">Year Levels</a></li>
            <li class="breadcrumb-item active">Year ${year}</li>
        `;
        
        let html = '';
        const sections = studentsData[year];
        
        Object.keys(sections).sort().forEach(section => {
            const count = sections[section].length;
            html += `
                <div class="col-md-4 col-lg-3">
                    <div class="year-card" onclick="renderStudentList(${year}, '${section}')">
                        <div class="year-icon"><i class="fas fa-users"></i></div>
                        <h4>Section ${section}</h4>
                        <p class="text-muted mb-0">${count} Students</p>
                    </div>
                </div>
            `;
        });
        
        content.innerHTML = html;
    }

    function renderStudentList(year, section) {
        const content = document.getElementById('classes-content');
        const nav = document.getElementById('classes-nav').querySelector('.breadcrumb');
        
        nav.innerHTML = `
            <li class="breadcrumb-item"><a href="#" onclick="renderYears(); return false;">Year Levels</a></li>
            <li class="breadcrumb-item"><a href="#" onclick="renderSections(${year}); return false;">Year ${year}</a></li>
            <li class="breadcrumb-item active">Section ${section}</li>
        `;

        const students = studentsData[year][section];
        
        let rows = '';
        students.forEach(s => {
            let statusClass = 'bg-warning text-dark';
            if (s.overall_status === 'Completed') statusClass = 'bg-success';
            else if (s.overall_status === 'Verified') statusClass = 'bg-info text-dark';

            rows += `
                <tr>
                    <td><input type="checkbox" class="student-cb" data-id="${s.stud_id}"></td>
                    <td>
                        <div class="d-flex align-items-center">
                            <div>
                                <div class="fw-bold">${s.lastname}, ${s.firstname} ${s.mi}</div>
                                <div class="small text-muted">${s.email}</div>
                            </div>
                        </div>
                    </td>
                    <td><span class="badge ${statusClass}">${s.overall_status}</span></td>
                    <td>${getStatusBadge(s.stud_id, 'waiver', s.waiver_status)}</td>
                    <td>${getStatusBadge(s.stud_id, 'agreement', s.agreement_status)}</td>
                    <td>${getStatusBadge(s.stud_id, 'enrollment', s.enrollment_status)}</td>
                    <td>${s.completed_hours}</td>
                </tr>
            `;
        });
        
        content.innerHTML = `
            <div class="col-12">
                <div class="content-card">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4 class="mb-0">Student List - Year ${year} Section ${section}</h4>
                        <button class="btn btn-success btn-sm" onclick="verifySelected()">
                            <i class="fas fa-check-double me-2"></i> Verify Selected
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th width="40"><input type="checkbox" id="selectAll" onclick="toggleAll(this)"></th>
                                    <th>Student</th>
                                    <th>Status</th>
                                    <th>Waiver</th>
                                    <th>Agreement</th>
                                    <th>Enrollment</th>
                                    <th>Hours</th>
                                </tr>
                            </thead>
                            <tbody>${rows}</tbody>
                        </table>
                    </div>
                </div>
            </div>
        `;
    }

    function getStatusBadge(id, type, status) {
        if (!status) status = 'Pending';
        let cls = 'bg-secondary';
        if (status === 'Verified') cls = 'bg-success';
        else if (status === 'Pending') cls = 'bg-warning text-dark';
        else if (status === 'Rejected') cls = 'bg-danger';
        
        return `<span class="badge ${cls}" style="cursor:pointer" onclick="openVerifyModal(${id}, '${type}')">${status}</span>`;
    }

    function toggleAll(source) {
        document.querySelectorAll('.student-cb').forEach(cb => cb.checked = source.checked);
    }

    // Modal & Verification Logic
    let currentVerify = { id: null, type: null };

    function openVerifyModal(id, type) {
        currentVerify = { id, type };
        const modal = new bootstrap.Modal(document.getElementById('docVerifyModal'));
        modal.show();
        
        const content = document.getElementById('docVerifyContent');
        content.innerHTML = '<div class="text-center"><div class="spinner-border"></div> Loading...</div>';
        
        fetch(`get_document_details.php?student_id=${id}&doc_type=${type}`)
            .then(res => res.text())
            .then(html => {
                content.innerHTML = html;
            })
            .catch(err => content.innerHTML = '<div class="alert alert-danger">Error loading details</div>');
    }

    function verifyAction(action) {
        if (!currentVerify.id) return;
        
        if (!confirm(`Are you sure you want to ${action} this document?`)) return;
        
        fetch('verify_document.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                student_id: currentVerify.id,
                doc_type: currentVerify.type,
                action: action
            })
        })
        .then(res => res.json())
        .then(data => {
            if(data.success) {
                alert('Success!');
                location.reload(); 
            } else {
                alert('Error: ' + data.message);
            }
        });
    }

    function verifySelected() {
        const selected = Array.from(document.querySelectorAll('.student-cb:checked'));
        if (selected.length === 0) {
            alert('Please select at least one student');
            return;
        }
        
        if (confirm(`Verify ${selected.length} student(s) as completed for all documents?`)) {
            const btn = event.target.closest('button'); // Quick access
            const originalText = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Processing...';
            btn.disabled = true;
            
            const studentIds = selected.map(cb => cb.dataset.id);
            
            fetch('verify_multiple.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ student_ids: studentIds })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert(`Successfully verified ${data.count} student(s)!`);
                    location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Unknown error'));
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                }
            })
            .catch(err => {
                alert('Network error');
                btn.innerHTML = originalText;
                btn.disabled = false;
            });
        }
    }

    // Initialize
    renderDashboard(document.getElementById('main-content'));

</script>
</body>
</html>
