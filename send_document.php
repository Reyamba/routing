<?php
session_start();
header('Content-Type: application/json');
if (!isset($_SESSION['username'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

require_once __DIR__ . '/db_helpers.php';
ensure_schema($conn);

$docId = isset($_POST['doc_id']) ? intval($_POST['doc_id']) : 0;
$receiverName = trim($_POST['receiver_name'] ?? '');
$title = trim($_POST['title'] ?? '');
$status = trim($_POST['status'] ?? '');
$remarks = trim($_POST['remarks'] ?? '');
if ($docId <= 0 || $receiverName === '' || $title === '' || $status === '') {
    echo json_encode(['success' => false, 'message' => 'Invalid document, receiver, title, or status.']);
    exit;
}

$userStmt = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
$userStmt->bind_param('s', $receiverName);
$userStmt->execute();
$userResult = $userStmt->get_result();
$userStmt->close();
if (!$userResult || $userResult->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Receiver is not a registered user.']);
    exit;
}

$stmt = $conn->prepare('SELECT title, status, remarks, created_at, sender_name, doc_name, file_path, file_size, type, owner FROM documents WHERE id = ? AND route_state = ? LIMIT 1');
$pendingState = 'Pending';
$stmt->bind_param('is', $docId, $pendingState);
$stmt->execute();
$result = $stmt->get_result();
if (!$result || $result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Pending document not found.']);
    exit;
}
$document = $result->fetch_assoc();
$stmt->close();

// Verify the current user owns this document
if (strcasecmp(trim($document['owner']), $_SESSION['username']) !== 0) {
    echo json_encode(['success' => false, 'message' => 'You can only send your own documents.']);
    exit;
}

$currentSender = $document['sender_name'] ?? '';
if (empty($currentSender)) {
    $claimStmt = $conn->prepare('UPDATE documents SET sender_name = ? WHERE id = ?');
    $claimStmt->bind_param('si', $_SESSION['username'], $docId);
    $claimStmt->execute();
    $claimStmt->close();
    $currentSender = $_SESSION['username'];
}
if ($currentSender !== $_SESSION['username']) {
    echo json_encode(['success' => false, 'message' => 'Only the sender can send this document.']);
    exit;
}

// Move pending -> Outgoing for sender
$update = $conn->prepare('UPDATE documents SET route_state = ?, receiver_name = ?, title = ?, status = ?, remarks = ? WHERE id = ?');
$outgoingState = 'Outgoing';
$update->bind_param('sssssi', $outgoingState, $receiverName, $title, $status, $remarks, $docId);
if (!$update->execute()) {
    echo json_encode(['success' => false, 'message' => 'Failed to update outgoing document.']);
    exit;
}
$update->close();

log_history($conn, $docId, 'sent', $_SESSION['username'], 'Pending', 'Outgoing',
    "Sent to {$receiverName}");

// Create the receiver's Incoming copy
$incomingState = 'Incoming';
$timestamp = date('Y-m-d H:i:s');
$insert = $conn->prepare('INSERT INTO documents (title, status, receiver_name, remarks, route_state, created_at, sender_name, doc_name, file_path, file_size, type, owner, is_read) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)');
$insert->bind_param('ssssssssisss', $title, $status, $receiverName, $remarks, $incomingState, $timestamp, $currentSender, $document['doc_name'], $document['file_path'], $document['file_size'], $document['type'], $document['owner']);
if (!$insert->execute()) {
    echo json_encode(['success' => false, 'message' => 'Failed to create incoming document.']);
    exit;
}
$incomingId = $insert->insert_id;
$insert->close();

log_history($conn, $incomingId, 'received_in_inbox', $receiverName, null, 'Incoming',
    "Delivered to {$receiverName}'s inbox from {$currentSender}");

echo json_encode(['success' => true]);
exit;
