<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['username'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/db_helpers.php';
ensure_schema($conn);

$currentUser = $_SESSION['username'];
$docId = isset($_POST['doc_id']) ? intval($_POST['doc_id']) : 0;

if ($docId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid document id']);
    exit;
}

if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
    exit;
}

// Validate file type
$allowed_types = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'jpg', 'jpeg', 'png'];
$file_extension = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));

if (!in_array($file_extension, $allowed_types)) {
    echo json_encode(['success' => false, 'message' => 'Invalid file type. Allowed types: ' . implode(', ', $allowed_types)]);
    exit;
}

// Check file size (max 2GB)
$max_size = 2 * 1024 * 1024 * 1024; // 2GB
if ($_FILES['file']['size'] > $max_size) {
    echo json_encode(['success' => false, 'message' => 'File size too large. Maximum size is 2GB']);
    exit;
}

// Verify the current user is the original uploader (owner)
$stmt = $conn->prepare("SELECT owner, route_state, file_path FROM documents WHERE id = ?");
$stmt->bind_param('i', $docId);
$stmt->execute();
$res = $stmt->get_result();
$doc = $res->fetch_assoc();
$stmt->close();

if (!$doc) {
    echo json_encode(['success' => false, 'message' => 'Document not found']);
    exit;
}

if (strcasecmp(trim($doc['owner']), $currentUser) !== 0) {
    echo json_encode(['success' => false, 'message' => 'Only the original uploader can replace this file']);
    exit;
}

if (strcasecmp(trim($doc['route_state']), 'Received') === 0) {
    echo json_encode(['success' => false, 'message' => 'Document has already been received and cannot be replaced']);
    exit;
}

// Save new file
$uploadDir = __DIR__ . '/uploads/documents/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0775, true);
}

$origName = basename($_FILES['file']['name']);
$ext = pathinfo($origName, PATHINFO_EXTENSION);
$safeName = uniqid() . '_' . time() . '.' . $ext;
$destPath = $uploadDir . $safeName;

if (!move_uploaded_file($_FILES['file']['tmp_name'], $destPath)) {
    echo json_encode(['success' => false, 'message' => 'Failed to save uploaded file']);
    exit;
}

$relativePath = 'uploads/documents/' . $safeName;
$fileSize = filesize($destPath);
$mimeType = mime_content_type($destPath) ?: ($_FILES['file']['type'] ?? '');

// Delete the previous file if it exists
if (!empty($doc['file_path'])) {
    $stored = trim($doc['file_path']);
    $candidates = [];
    
    // Try different path formats that might exist in the database
    if (strpos($stored, '/') !== false || strpos($stored, '\\') !== false || strpos($stored, 'uploads') !== false) {
        $candidates[] = __DIR__ . '/' . ltrim($stored, '/\\');
    }
    $base = basename($stored);
    $candidates[] = __DIR__ . '/uploads/documents/' . $base;
    $candidates[] = __DIR__ . '/uploads/' . $base;
    
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            @unlink($candidate);
            break; // Delete the first match found
        }
    }
}

// Update DB record + reset is_read so receiver sees the new file
$upd = $conn->prepare("UPDATE documents SET file_path = ?, file_size = ?, type = ?, is_read = 0 WHERE id = ?");
$upd->bind_param('sisi', $relativePath, $fileSize, $ext, $docId);
$ok = $upd->execute();
$upd->close();

if (!$ok) {
    echo json_encode(['success' => false, 'message' => 'Failed to update document record']);
    exit;
}

// Optional: log to history table
log_history($conn, $docId, 'File Replaced', $currentUser, null, null, 'Uploader replaced the file: ' . $origName);

echo json_encode(['success' => true, 'message' => 'File replaced successfully']);
