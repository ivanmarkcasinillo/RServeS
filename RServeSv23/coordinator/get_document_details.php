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
$normalized_file_path = str_replace('\\', '/', trim((string) $file_path, " \t\n\r\0\x0B/"));

// Special handling for Agreement which might be generated or have signatures
if ($doc_type === 'agreement') {
    // If agreement is a generated form, we might need to display fields
    // But if there is a file_path, use it.
    // Based on DB schema: student_signature, parent_signature exist.
    // If no file_path, maybe we show signatures?
    // Let's assume file_path exists if it was uploaded, or we generate a view.
    // For now, let's try to show what's there.
}

// Resolve both the server file path and the public URL used by the browser.
$display_path = '';
$display_url = '';
$file_exists = false;

if ($normalized_file_path !== '') {
    $path_candidates = [];

    if (strpos($normalized_file_path, 'uploads/') === 0) {
        $path_candidates[] = [
            'server' => dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized_file_path),
            'url' => (defined('BASE_PATH') ? BASE_PATH : '../') . ltrim($normalized_file_path, '/')
        ];
    } else {
        $path_candidates[] = [
            'server' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized_file_path),
            'url' => (defined('BASE_PATH') ? BASE_PATH : '../') . 'uploads/' . ltrim($normalized_file_path, '/')
        ];
        $path_candidates[] = [
            'server' => dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized_file_path),
            'url' => (defined('BASE_PATH') ? BASE_PATH : '../') . ltrim($normalized_file_path, '/')
        ];
    }

    foreach ($path_candidates as $candidate) {
        if (file_exists($candidate['server'])) {
            $display_path = $candidate['server'];
            $display_url = $candidate['url'];
            $file_exists = true;
            break;
        }
    }

    if (!$file_exists) {
        $fallback_url = strpos($normalized_file_path, 'uploads/') === 0
            ? (defined('BASE_PATH') ? BASE_PATH : '../') . ltrim($normalized_file_path, '/')
            : (defined('BASE_PATH') ? BASE_PATH : '../') . 'uploads/' . ltrim($normalized_file_path, '/');
        $display_url = $fallback_url;
    }
}

$ext = strtolower(pathinfo($normalized_file_path, PATHINFO_EXTENSION));
$is_image = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'heic']);
$is_pdf = ($ext === 'pdf');

?>

<div class="row doc-verify-layout">
    <div class="col-md-4 border-end doc-meta-column">
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
    
    <div class="col-md-8 doc-preview-column">
        <h6 class="fw-bold text-muted mb-3">Preview</h6>
        <div class="bg-light border rounded d-flex align-items-center justify-content-center doc-preview-pane">
            <?php if (empty($file_path)): ?>
                <div class="text-center text-muted">
                    <i class="fas fa-file-alt fa-3x mb-2"></i>
                    <p>No file attachment found.</p>
                </div>
            <?php elseif (!$file_exists): ?>
                <div class="text-center text-muted px-4">
                    <i class="fas fa-triangle-exclamation fa-3x mb-3 text-warning"></i>
                    <p class="mb-2 fw-semibold">Preview unavailable</p>
                    <p class="mb-0">The document record exists, but the uploaded file is missing on this server.</p>
                </div>
            <?php elseif ($is_image): ?>
                <img src="<?= htmlspecialchars($display_url) ?>" class="doc-preview-image" alt="Document Preview">
            <?php elseif ($is_pdf): ?>
                <iframe src="<?= htmlspecialchars($display_url) ?>" class="doc-preview-frame" title="Document Preview"></iframe>
            <?php else: ?>
                <div class="text-center">
                    <i class="fas fa-file-download fa-3x text-primary mb-3"></i>
                    <p class="mb-3">File type not supported for preview.</p>
                    <a href="<?= htmlspecialchars($display_url) ?>" target="_blank" class="btn btn-primary btn-sm">
                        <i class="fas fa-download me-1"></i> Download File
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
