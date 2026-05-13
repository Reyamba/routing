<?php
session_start();
if (!isset($_SESSION['username'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/config.php';

$docId          = intval($_GET['id'] ?? $_POST['id'] ?? 0);
$errorMessage   = '';
$successMessage = '';
$currentUser    = $_SESSION['username'];

if ($docId <= 0) {
    header('Location: dashboard.php');
    exit;
}

/* -------------------------------------------------------------------------
 * AUTHORIZATION — only the original uploader (owner) may edit,
 * and only while the document has NOT yet been Received.
 * ----------------------------------------------------------------------- */
$g = $conn->prepare("SELECT owner, route_state, file_path FROM documents WHERE id = ?");
$g->bind_param('i', $docId);
$g->execute();
$g->bind_result($ownerName, $rState, $existingFilePath);
$hasRow = $g->fetch();
$g->close();

if (!$hasRow) {
    http_response_code(404);
    exit('Document not found.');
}
if (strcasecmp(trim($ownerName), $currentUser) !== 0) {
    http_response_code(403);
    exit('Only the uploader can edit this document.');
}
if (strcasecmp(trim($rState), 'Received') === 0) {
    http_response_code(403);
    exit('This document has already been received and can no longer be edited.');
}

/* -------------------------------------------------------------------------
 * HANDLE UPDATE (text fields + optional file replacement)
 * ----------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title        = trim($_POST['title'] ?? '');
    $status       = trim($_POST['status'] ?? '');
    $receiverName = trim($_POST['receiver_name'] ?? '');
    $remarks      = trim($_POST['remarks'] ?? '');

    $newFilePath = null;   // relative path to save in DB
    $newFileSize = null;
    $newFileType = null;
    $replacedFile = false;

    if ($title === '' || $status === '') {
        $errorMessage = 'Title and status are required.';
    } else {
        // Confirm receiver exists if specified
        if ($receiverName !== '') {
            $userStmt = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
            $userStmt->bind_param('s', $receiverName);
            $userStmt->execute();
            $userRes = $userStmt->get_result();
            $userStmt->close();
            if (!$userRes || $userRes->num_rows === 0) {
                $errorMessage = 'Receiver must be a registered user.';
            }
        }

        /* --------- FILE REPLACEMENT (uploader only) ---------
         * If a new file was uploaded, validate, store it, and replace
         * the old one. Old file is deleted from disk after the DB
         * update succeeds.
         * --------------------------------------------------- */
        if (!$errorMessage && isset($_FILES['document_file']) && $_FILES['document_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            $f = $_FILES['document_file'];

            if ($f['error'] !== UPLOAD_ERR_OK) {
                $errorMessage = 'File upload failed (error code ' . intval($f['error']) . ').';
            } else {
                $maxBytes = 20 * 1024 * 1024; // 20 MB
                $allowedExt = ['pdf','doc','docx','xls','xlsx','ppt','pptx','txt','jpg','jpeg','png'];
                $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));

                if ($f['size'] > $maxBytes) {
                    $errorMessage = 'File too large. Maximum allowed size is 20 MB.';
                } elseif (!in_array($ext, $allowedExt, true)) {
                    $errorMessage = 'File type not allowed. Allowed: ' . implode(', ', $allowedExt) . '.';
                } else {
                    $uploadDir = __DIR__ . '/uploads';
                    if (!is_dir($uploadDir)) {
                        @mkdir($uploadDir, 0775, true);
                    }
                    $safeBase = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', pathinfo($f['name'], PATHINFO_FILENAME));
                    $storedName = $docId . '_' . time() . '_' . $safeBase . '.' . $ext;
                    $destAbs = $uploadDir . '/' . $storedName;

                    if (!move_uploaded_file($f['tmp_name'], $destAbs)) {
                        $errorMessage = 'Could not save the uploaded file.';
                    } else {
                        $newFilePath  = 'uploads/' . $storedName;
                        $newFileSize  = $f['size'];
                        $newFileType  = $ext;
                        $replacedFile = true;
                    }
                }
            }
        }

        if (!$errorMessage) {
            if ($replacedFile) {
                // Update including file fields
                $upd = $conn->prepare(
                    "UPDATE documents
                     SET title = ?, status = ?, receiver_name = ?, remarks = ?,
                         file_path = ?, file_size = ?, type = ?
                     WHERE id = ? AND sender_name = ? AND route_state <> 'Received'"
                );
                $upd->bind_param(
                    'sssssisis',
                    $title, $status, $receiverName, $remarks,
                    $newFilePath, $newFileSize, $newFileType,
                    $docId, $currentUser
                );
            } else {
                $upd = $conn->prepare(
                    "UPDATE documents
                     SET title = ?, status = ?, receiver_name = ?, remarks = ?
                     WHERE id = ? AND sender_name = ? AND route_state <> 'Received'"
                );
                $upd->bind_param('ssssis', $title, $status, $receiverName, $remarks, $docId, $currentUser);
            }

            if ($upd->execute()) {
                $successMessage = $replacedFile
                    ? 'Document and attached file updated successfully.'
                    : 'Document updated successfully.';

                // Delete the old file from disk if it was replaced
                if ($replacedFile && $existingFilePath) {
                    $oldAbs = __DIR__ . '/' . ltrim($existingFilePath, '/');
                    if (is_file($oldAbs) && realpath($oldAbs) !== false &&
                        strpos(realpath($oldAbs), realpath(__DIR__ . '/uploads')) === 0) {
                        @unlink($oldAbs);
                    }
                }

                @$conn->query(
                    "INSERT INTO document_history (document_id, actor, action, notes, created_at)
                     VALUES (" . $docId . ", '" . $conn->real_escape_string($currentUser) . "',
                             '" . ($replacedFile ? 'FileReplaced' : 'Edited') . "',
                             '" . $conn->real_escape_string($replacedFile ? ('Replaced file with ' . $newFilePath) : 'Edited document fields') . "',
                             NOW())"
                );
            } else {
                $errorMessage = 'Error updating document.';
                // If we already moved the new file but DB failed, clean it up
                if ($replacedFile && $newFilePath) {
                    @unlink(__DIR__ . '/' . $newFilePath);
                }
            }
            $upd->close();
        }
    }
}

/* -------------------------------------------------------------------------
 * LOAD DOCUMENT + USER LIST
 * ----------------------------------------------------------------------- */
$stmt = $conn->prepare("SELECT * FROM documents WHERE id = ?");
$stmt->bind_param('i', $docId);
$stmt->execute();
$document = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$document) {
    header('Location: dashboard.php');
    exit;
}

$users = [];
if ($res = $conn->query("SELECT username FROM users ORDER BY username ASC")) {
    while ($u = $res->fetch_assoc()) $users[] = $u['username'];
}

$returnTo = $_GET['return_to'] ?? 'pending.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Document - PSA Routing</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary-color:#0012b0; --bg-light:#f9fafb; --border-color:#e5e7eb; --text-main:#111827; --text-muted:#6b7280; }
        * { box-sizing:border-box; margin:0; padding:0; font-family:'Inter',sans-serif; }
        body { background:var(--bg-light); color:var(--text-main); min-height:100vh; }
        .container { max-width:640px; margin:40px auto; padding:24px; background:white; border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,0.1); }
        h1 { font-size:1.5rem; margin-bottom:20px; color:var(--primary-color); }
        .form-group { margin-bottom:16px; display:flex; flex-direction:column; gap:6px; }
        label { font-size:.875rem; font-weight:500; }
        input, select, textarea { width:100%; padding:10px; border:1px solid var(--border-color); border-radius:8px; font-family:inherit; }
        textarea { min-height:100px; resize:vertical; }
        .file-current { font-size:.85rem; color:var(--text-muted); margin-top:4px; }
        .file-current a { color:var(--primary-color); text-decoration:none; }
        .file-help { font-size:.8rem; color:var(--text-muted); }
        .btn-submit { background:var(--primary-color); color:white; border:none; padding:12px 16px; border-radius:8px; font-weight:600; cursor:pointer; width:100%; margin-top:12px; }
        .btn-secondary { display:block; text-align:center; margin-top:12px; padding:10px; background:#e5e7eb; color:var(--text-main); text-decoration:none; border-radius:8px; }
        .alert { padding:10px; border-radius:6px; margin-bottom:16px; }
        .alert.success { background:#ecfdf5; color:#065f46; border:1px solid #d1fae5; }
        .alert.error { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
    </style>
</head>
<body>
<div class="container">
    <h1><i class="fas fa-edit"></i> Edit Document</h1>

    <?php if ($errorMessage): ?>
        <div class="alert error"><?php echo htmlspecialchars($errorMessage); ?></div>
    <?php endif; ?>
    <?php if ($successMessage): ?>
        <div class="alert success"><?php echo htmlspecialchars($successMessage); ?></div>
    <?php endif; ?>

    <form action="edit_document.php?id=<?php echo $docId; ?>&return_to=<?php echo urlencode($returnTo); ?>"
          method="POST" enctype="multipart/form-data">
        <input type="hidden" name="id" value="<?php echo $docId; ?>">

        <div class="form-group">
            <label>Document Title</label>
            <input type="text" name="title" value="<?php echo htmlspecialchars($document['title']); ?>" required>
        </div>

        <div class="form-group">
            <label>Status</label>
            <select name="status" required>
                <option value="">Select status</option>
                <?php
                $statusOptions = [
                    'For Appropriate Action','For Comments','For Consideration',
                    'For Initial/Signature','For Information/File','For Drafting of Reply','Specify'
                ];
                foreach ($statusOptions as $opt) {
                    $sel = $document['status'] === $opt ? 'selected' : '';
                    echo '<option value="' . htmlspecialchars($opt) . '" ' . $sel . '>' . htmlspecialchars($opt) . '</option>';
                } ?>
            </select>
        </div>

        <div class="form-group">
            <label>Receiver Username</label>
            <select name="receiver_name">
                <option value="">Select registered receiver (optional)</option>
                <?php foreach ($users as $username): ?>
                    <option value="<?php echo htmlspecialchars($username); ?>"
                        <?php echo $document['receiver_name'] === $username ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($username); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>Remarks</label>
            <textarea name="remarks"><?php echo htmlspecialchars($document['remarks'] ?? ''); ?></textarea>
        </div>

        <div class="form-group">
            <label>Replace Document File <span style="color:var(--text-muted); font-weight:400;">(optional)</span></label>
            <input type="file" name="document_file"
                   accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.jpg,.jpeg,.png">
            <?php if (!empty($document['file_path'])): ?>
                <div class="file-current">
                    Current file:
                    <a href="<?php echo htmlspecialchars($document['file_path']); ?>" target="_blank">
                        <i class="fas fa-paperclip"></i>
                        <?php echo htmlspecialchars(basename($document['file_path'])); ?>
                    </a>
                </div>
            <?php else: ?>
                <div class="file-current">No file currently attached.</div>
            <?php endif; ?>
            <div class="file-help">
                Choose a new file only if you need to replace the wrong one.
                Leave empty to keep the existing file.
                Allowed: pdf, doc, docx, xls, xlsx, ppt, pptx, txt, jpg, jpeg, png. Max 20 MB.
            </div>
        </div>

        <button type="submit" class="btn-submit">
            <i class="fas fa-save"></i> Update Document
        </button>
        <a href="<?php echo htmlspecialchars($returnTo); ?>" class="btn-secondary">Back</a>
    </form>
</div>
</body>
</html>
