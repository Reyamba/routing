<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['username'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/db_helpers.php';
ensure_schema($conn);

$docId    = isset($_POST['doc_id']) ? intval($_POST['doc_id']) : 0;
$receiver = isset($_POST['receiver']) ? trim($_POST['receiver']) : '';
$remarks  = isset($_POST['remarks']) ? trim($_POST['remarks']) : '';
$me       = $_SESSION['username'];

if ($docId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid document id']);
    exit;
}
if ($receiver === '') {
    echo json_encode(['success' => false, 'message' => 'Please select a user to forward to']);
    exit;
}
if (strcasecmp($receiver, $me) === 0) {
    echo json_encode(['success' => false, 'message' => 'You cannot forward a document to yourself']);
    exit;
}

// Verify recipient exists
$uChk = $conn->prepare("SELECT username FROM users WHERE username = ? LIMIT 1");
$uChk->bind_param('s', $receiver);
$uChk->execute();
$uRes = $uChk->get_result()->fetch_assoc();
$uChk->close();
if (!$uRes) {
    echo json_encode(['success' => false, 'message' => 'Recipient user does not exist']);
    exit;
}

// Verify the document belongs to current user and is in Received state
$check = $conn->prepare("SELECT id, receiver_name, route_state, title FROM documents WHERE id = ? LIMIT 1");
$check->bind_param('i', $docId);
$check->execute();
$doc = $check->get_result()->fetch_assoc();
$check->close();

if (!$doc) {
    echo json_encode(['success' => false, 'message' => 'Document not found']);
    exit;
}
if ($doc['receiver_name'] !== $me) {
    echo json_encode(['success' => false, 'message' => 'You are not the current holder of this document']);
    exit;
}
if (strcasecmp(trim($doc['route_state']), 'Received') !== 0) {
    echo json_encode(['success' => false, 'message' => 'Only received documents can be forwarded']);
    exit;
}

// Forward: sender becomes current user, receiver becomes selected user.
// route_state = 'Incoming' so the doc appears in the new receiver's Incoming page.
// outgoing.php also includes 'Incoming' docs where sender_name = me, so the
// forwarder still sees the forwarded doc in their Outgoing page.
$update = $conn->prepare(
    "UPDATE documents
     SET sender_name = ?, receiver_name = ?, route_state = 'Incoming',
         received_at = NULL, date_time_receiving = NULL, time_stamp_received = NULL,
         is_read = 0, remarks = ?, created_at = NOW()
     WHERE id = ?"
);
$update->bind_param('sssi', $me, $receiver, $remarks, $docId);
$ok = $update->execute();
$update->close();

if (!$ok) {
    echo json_encode(['success' => false, 'message' => 'Failed to forward document']);
    exit;
}

// Log history
$hist = $conn->prepare("INSERT INTO document_history (document_id, actor, action, notes) VALUES (?, ?, 'Forward', ?)");
if ($hist) {
    $note = 'Forwarded to ' . $receiver . ($remarks !== '' ? ' — ' . $remarks : '');
    $hist->bind_param('iss', $docId, $me, $note);
    @$hist->execute();
    $hist->close();
}

echo json_encode([
    'success' => true,
    'message' => 'Document forwarded to ' . $receiver
]);
