<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not signed in']);
    exit;
}

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$docId = intval($_POST['doc_id'] ?? 0);
$user  = $_SESSION['username'];

if ($docId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Missing doc_id']);
    exit;
}

// Authorization: only the current receiver may mark a doc as Received,
// and only while it's still Incoming.
$check = $conn->prepare("SELECT receiver_name, route_state FROM documents WHERE id = ?");
$check->bind_param('i', $docId);
$check->execute();
$check->bind_result($receiver, $state);
$found = $check->fetch();
$check->close();

if (!$found) {
    echo json_encode(['success' => false, 'message' => 'Document not found']);
    exit;
}
if (strcasecmp(trim($receiver), $user) !== 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only the receiver can mark this as received']);
    exit;
}
if (strcasecmp(trim($state), 'Received') === 0) {
    echo json_encode(['success' => false, 'message' => 'Already received']);
    exit;
}

$receivedAt = date('Y-m-d H:i:s');

$upd = $conn->prepare(
    "UPDATE documents
     SET route_state = 'Received',
         status = 'Received',
         is_read = 1,
         received_at = ?,
         date_time_receiving = ?,
         time_stamp_received = ?
     WHERE id = ?"
);
$upd->bind_param('sssi', $receivedAt, $receivedAt, $receivedAt, $docId);
$ok = $upd->execute();
$upd->close();

if (!$ok) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

@$conn->query("INSERT INTO document_history (document_id, actor_name, action, created_at)
               VALUES ($docId, '" . $conn->real_escape_string($user) . "', 'Received', NOW())");

echo json_encode(['success' => true, 'received_at' => $receivedAt]);
