<?php
session_start();
if (!isset($_SESSION['username'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/config.php';

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$errorMessage = '';
$successMessage = '';

// Fetch the document
$stmt = $conn->prepare("SELECT * FROM documents WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$document = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$document) {
    header('Location: dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $status = trim($_POST['status'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');

    if ($title === '' || $status === '') {
        $errorMessage = 'Please fill in all required fields.';
    } else {
        $updateStmt = $conn->prepare("UPDATE documents SET title = ?, status = ?, remarks = ? WHERE id = ?");
        $updateStmt->bind_param("sssi", $title, $status, $remarks, $id);
        
        if ($updateStmt->execute()) {
            $successMessage = 'Document updated successfully.';
            // Refresh document variable
            $document['title'] = $title;
            $document['status'] = $status;
            $document['remarks'] = $remarks;
        } else {
            $errorMessage = 'Failed to update document. Please try again.';
        }
        $updateStmt->close();
    }
}
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
        :root {
            --primary-color: #0012b0;
            --bg-light: #f9fafb;
            --border-color: #e5e7eb;
            --text-main: #111827;
            --text-muted: #6b7280;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', sans-serif; }
        body { background-color: var(--bg-light); color: var(--text-main); min-height: 100vh; }
        .container { max-width: 600px; margin: 40px auto; padding: 24px; background: white; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        h1 { font-size: 1.5rem; margin-bottom: 20px; color: var(--primary-color); }
        .form-group { margin-bottom: 16px; display: flex; flex-direction: column; gap: 6px; }
        label { font-size: 0.875rem; font-weight: 500; }
        input, textarea { width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 8px; font-size: 0.95rem; }
        input:focus, textarea:focus { outline: none; border-color: var(--primary-color); }
        .btn-submit { background: var(--primary-color); color: white; border: none; padding: 12px 16px; border-radius: 8px; font-weight: 600; cursor: pointer; width: 100%; margin-top: 12px; }
        .btn-submit:hover { opacity: 0.9; }
        .btn-secondary { display: block; text-align: center; margin-top: 12px; padding: 10px; background: #e5e7eb; color: var(--text-main); text-decoration: none; border-radius: 8px; font-size: 0.95rem; }
        .error-box { padding: 10px; background: #fee2e2; color: #991b1b; border-radius: 6px; margin-bottom: 16px; }
        .success-box { padding: 10px; background: #d1fae5; color: #065f46; border-radius: 6px; margin-bottom: 16px; }
    </style>
</head>
<body>
<div class="container">
    <h1><i class="fas fa-edit"></i> Edit Document</h1>
    <?php if ($errorMessage): ?>
        <div class="error-box"><?php echo htmlspecialchars($errorMessage); ?></div>
    <?php endif; ?>
    <?php if ($successMessage): ?>
        <div class="success-box"><?php echo htmlspecialchars($successMessage); ?></div>
    <?php endif; ?>
    
    <form action="edit_document.php?id=<?php echo $id; ?>" method="POST">
        <div class="form-group">
            <label>Title</label>
            <input type="text" name="title" value="<?php echo htmlspecialchars($document['title']); ?>" required>
        </div>
        <div class="form-group">
            <label>Status</label>
            <input type="text" name="status" value="<?php echo htmlspecialchars($document['status']); ?>" required>
        </div>
        <div class="form-group">
            <label>Remarks</label>
            <textarea name="remarks"><?php echo htmlspecialchars($document['remarks']); ?></textarea>
        </div>
        <button type="submit" class="btn-submit">Update Document</button>
        <a href="dashboard.php" class="btn-secondary">Back to Dashboard</a>
    </form>
</div>
</body>
</html>
