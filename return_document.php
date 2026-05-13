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
$note  = trim($_POST['note'] ?? '');
$user  = $_SESSION['username'];

if ($docId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Missing doc_id']);
    exit;
}

// Authorization: only the current receiver may return the document.
$check = $conn->prepare("SELECT sender_name, receiver_name, route_state FROM documents WHERE id = ?");
$check->bind_param('i', $docId);
$check->execute();
$check->bind_result($sender, $receiver, $state);
$found = $check->fetch();
$check->close();

if (!$found) {
    echo json_encode(['success' => false, 'message' => 'Document not found']);
    exit;
}
if (strcasecmp(trim($receiver), $user) !== 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only the receiver can return this document']);
    exit;
}
if (strcasecmp(trim($state), 'Received') === 0) {
    echo json_encode(['success' => false, 'message' => 'Cannot return — already received']);
    exit;
}

// Swap parties: returning sends it BACK to the original sender as their incoming.
$upd = $conn->prepare(
    "UPDATE documents
     SET sender_name = ?,
         receiver_name = ?,
         route_state = 'Incoming',
         is_read = 0,
         date_time_receiving = NULL
     WHERE id = ?"
);
$upd->bind_param('ssi', $user, $sender, $docId);
$ok = $upd->execute();
$upd->close();

if (!$ok) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

@$conn->query("INSERT INTO document_history (document_id, actor_name, action, note, created_at)
               VALUES ($docId,
                       '" . $conn->real_escape_string($user) . "',
                       'Returned',
                       '" . $conn->real_escape_string($note) . "',
                       NOW())");

echo json_encode(['success' => true]);
