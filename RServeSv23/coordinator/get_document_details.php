<?php
require "dbconnect.php";

if (!isset($_GET['student_id']) || !isset($_GET['doc_type'])) {
    echo '<div class="alert alert-danger">Invalid request parameters.</div>';
    exit;
}

$stud_id = intval($_GET['student_id']);
$doc_type = $_GET['doc_type'];
$table = '';
$path_col = 'file_path';

switch($doc_type) {
    case 'waiver': 
        $table = 'rss_waivers'; 
        break;
    case 'agreement': 
        $table = 'rss_agreements'; 
        // Agreement might store signature paths instead of a single file
        // But dashboard implies a single view. Let's check columns.
        break;
    case 'enrollment': 
        $table = 'rss_enrollments'; 
        break;
    default: 
        echo '<div class="alert alert-danger">Unknown document type.</div>'; 
        exit;
}

// Fetch document info
$pk = 'id';
if ($doc_type === 'agreement') $pk = 'agreement_id';
elseif ($doc_type === 'enrollment') $pk = 'enrollment_id';

// We order by PK descending to get the latest submission
$sql = "SELECT * FROM $table WHERE student_id = ? ORDER BY $pk DESC LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $stud_id);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    echo '<div class="text-center py-5">
            <i class="fas fa-file-excel fa-3x text-muted mb-3"></i>
            <p class="text-muted">No document uploaded yet.</p>
          </div>';
    exit;
}

$doc = $res->fetch_assoc();
$status = $doc['status'] ?? 'Pending';
$file_path = $doc['file_path'] ?? ''; // Default column

// Special handling for Agreement which might be generated or have signatures
if ($doc_type === 'agreement') {
    // If agreement is a generated form, we might need to display fields
    // But if there is a file_path, use it.
    // Based on DB schema: student_signature, parent_signature exist.
    // If no file_path, maybe we show signatures?
    // Let's assume file_path exists if it was uploaded, or we generate a view.
    // For now, let's try to show what's there.
}

// Construct full path
// Assuming file_path is stored as relative to uploads/ or root
// e.g. "waivers/abc.pdf" -> "../uploads/waivers/abc.pdf"
// We need to check if it starts with "uploads/" or not.
$display_path = '';
if (strpos($file_path, 'uploads/') === 0) {
    $display_path = "../" . $file_path;
} else {
    // Try to guess based on known structure
    $display_path = "../uploads/" . $file_path;
}

// Check if file exists
if (!file_exists($display_path) && !empty($file_path)) {
    // Try without "uploads/" prefix if it was double
    if (file_exists("../" . $file_path)) {
        $display_path = "../" . $file_path;
    }
}

$ext = strtolower(pathinfo($display_path, PATHINFO_EXTENSION));
$is_image = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'heic']);
$is_pdf = ($ext === 'pdf');

?>

<div class="row">
    <div class="col-md-4 border-end">
        <h6 class="fw-bold text-muted mb-3">Document Info</h6>
        <div class="mb-3">
            <label class="small text-muted">Status</label>
            <div>
                <?php if($status === 'Verified'): ?>
                    <span class="badge bg-success">Verified</span>
                <?php elseif($status === 'Rejected'): ?>
                    <span class="badge bg-danger">Rejected</span>
                <?php else: ?>
                    <span class="badge bg-warning text-dark">Pending</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="mb-3">
            <label class="small text-muted">Uploaded On</label>
            <div class="fw-medium"><?= isset($doc['created_at']) ? date('M d, Y h:i A', strtotime($doc['created_at'])) : 'N/A' ?></div>
        </div>
        
        <?php if($doc_type === 'agreement'): ?>
            <hr>
            <h6 class="fw-bold text-muted mb-3">Signatures</h6>
            <div class="mb-2">
                <label class="small text-muted">Student Signature</label>
                <?php if(!empty($doc['student_signature'])): ?>
                    <div class="border rounded p-2 text-center bg-light">
                        <img src="../<?= htmlspecialchars($doc['student_signature']) ?>" style="max-height: 60px; max-width: 100%;">
                    </div>
                <?php else: ?>
                    <div class="text-muted small">Not signed</div>
                <?php endif; ?>
            </div>
            <div class="mb-2">
                <label class="small text-muted">Parent Signature</label>
                <?php if(!empty($doc['parent_signature'])): ?>
                    <div class="border rounded p-2 text-center bg-light">
                        <img src="../<?= htmlspecialchars($doc['parent_signature']) ?>" style="max-height: 60px; max-width: 100%;">
                    </div>
                <?php else: ?>
                    <div class="text-muted small">Not signed</div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if($doc_type === 'enrollment' && !empty($doc['signature_image'])): ?>
             <hr>
            <div class="mb-2">
                <label class="small text-muted">Signature</label>
                 <div class="border rounded p-2 text-center bg-light">
                    <img src="<?= htmlspecialchars($doc['signature_image']) ?>" style="max-height: 60px; max-width: 100%;">
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="col-md-8">
        <h6 class="fw-bold text-muted mb-3">Preview</h6>
        <div class="bg-light border rounded d-flex align-items-center justify-content-center" style="min-height: 400px; overflow: hidden;">
            <?php if (empty($file_path)): ?>
                <div class="text-center text-muted">
                    <i class="fas fa-file-alt fa-3x mb-2"></i>
                    <p>No file attachment found.</p>
                </div>
            <?php elseif ($is_image): ?>
                <img src="<?= htmlspecialchars($display_path) ?>" class="img-fluid" alt="Document Preview">
            <?php elseif ($is_pdf): ?>
                <iframe src="<?= htmlspecialchars($display_path) ?>" width="100%" height="450px" style="border:none;"></iframe>
            <?php else: ?>
                <div class="text-center">
                    <i class="fas fa-file-download fa-3x text-primary mb-3"></i>
                    <p class="mb-3">File type not supported for preview.</p>
                    <a href="<?= htmlspecialchars($display_path) ?>" target="_blank" class="btn btn-primary btn-sm">
                        <i class="fas fa-download me-1"></i> Download File
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
