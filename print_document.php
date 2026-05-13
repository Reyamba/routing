<?php
session_start();
if (!isset($_SESSION['username'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/config.php';

$docId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($docId <= 0) {
    header('Location: dashboard.php');
    exit;
}

$stmt = $conn->prepare('SELECT * FROM documents WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $docId);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

if (!$result || $result->num_rows === 0) {
    header('Location: dashboard.php?status=document_not_found');
    exit;
}

$row = $result->fetch_assoc();

// Check if user is sender or receiver
if (trim($row['sender_name']) !== $_SESSION['username'] && trim($row['receiver_name']) !== $_SESSION['username']) {
    header('Location: dashboard.php?status=export_error');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Document - <?php echo htmlspecialchars($row['title']); ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .header { text-align: center; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 20px; }
        .details { margin-bottom: 20px; }
        .details table { width: 100%; border-collapse: collapse; }
        .details td { padding: 8px; border: 1px solid #ddd; }
        .details .label { font-weight: bold; width: 30%; }
        @media print { body { margin: 0; } }
    </style>
</head>
<body>
    <div class="header">
        <h1>PSA Routing System</h1>
        <h2>Document Details</h2>
    </div>
    <div class="details">
        <table>
            <tr><td class="label">Title:</td><td><?php echo htmlspecialchars($row['title']); ?></td></tr>
            <tr><td class="label">Status:</td><td><?php echo htmlspecialchars($row['status']); ?></td></tr>
            <tr><td class="label">Route State:</td><td><?php echo htmlspecialchars($row['route_state']); ?></td></tr>
            <tr><td class="label">Created At:</td><td><?php echo htmlspecialchars($row['created_at']); ?></td></tr>
            <tr><td class="label">Sender:</td><td><?php echo htmlspecialchars($row['sender_name'] ?? '—'); ?></td></tr>
            <tr><td class="label">Receiver:</td><td><?php echo htmlspecialchars($row['receiver_name'] ?? '—'); ?></td></tr>
            <tr><td class="label">Remarks:</td><td><?php echo htmlspecialchars($row['remarks'] ?? ''); ?></td></tr>
            <?php if (!empty($row['file_path'])): ?>
            <tr><td class="label">File:</td><td><?php echo htmlspecialchars($row['file_path']); ?></td></tr>
            <?php endif; ?>
        </table>
    </div>
    <script>
        window.onload = function() {
            window.print();
        }
    </script>
</body>
</html>