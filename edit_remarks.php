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

$docId   = intval($_POST['doc_id'] ?? 0);
$remarks = trim($_POST['remarks'] ?? '');
$user    = $_SESSION['username'];

if ($docId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Missing doc_id']);
    exit;
}

// Authorization: only the sender or the current receiver may edit remarks.
$check = $conn->prepare("SELECT sender_name, receiver_name FROM documents WHERE id = ?");
$check->bind_param('i', $docId);
$check->execute();
$check->bind_result($sender, $receiver);
$found = $check->fetch();
$check->close();

if (!$found) {
    echo json_encode(['success' => false, 'message' => 'Document not found']);
    exit;
}

if (
    strcasecmp(trim($sender), $user) !== 0 &&
    strcasecmp(trim($receiver), $user) !== 0
) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not allowed']);
    exit;
}

$upd = $conn->prepare("UPDATE documents SET remarks = ? WHERE id = ?");
$upd->bind_param('si', $remarks, $docId);
$ok = $upd->execute();
$upd->close();

if (!$ok) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

// Optional history log (table created if you have it; ignored otherwise)
@$conn->query("INSERT INTO document_history (document_id, actor_name, action, note, created_at)
               VALUES ($docId, '" . $conn->real_escape_string($user) . "', 'RemarksUpdated',
                       '" . $conn->real_escape_string($remarks) . "', NOW())");

echo json_encode(['success' => true]);
