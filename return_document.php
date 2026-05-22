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
$note  = isset($_POST['remarks']) ? trim((string)$_POST['remarks']) : '';

if ($docId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid document id']);
    exit;
}

// Fetch original incoming row (must belong to current user as receiver)
$sel = $conn->prepare("SELECT * FROM documents WHERE id = ? AND receiver_name = ? LIMIT 1");
$sel->bind_param('is', $docId, $currentUser);
$sel->execute();
$res = $sel->get_result();
$orig = $res ? $res->fetch_assoc() : null;
$sel->close();

if (!$orig) {
    echo json_encode(['success' => false, 'message' => 'Document not found']);
    exit;
}

$origSender   = $orig['sender_name'];
$origReceiver = $orig['receiver_name']; // = currentUser
$title        = $orig['title'];
$status       = $orig['status'];
$submittedVia = $orig['submitted_via'] ?? null;
$origRemarks  = (string)($orig['remarks'] ?? '');
$returnRemark = 'Returned by ' . $currentUser . ($note !== '' ? ': ' . $note : '');
$newRemarks   = trim($origRemarks . ($origRemarks !== '' ? ' | ' : '') . $returnRemark);

$conn->begin_transaction();
try {
    // 1) Remove the original row from sender's outgoing view by flipping its route_state.
    $upd = $conn->prepare("UPDATE documents SET route_state = 'Returned' WHERE id = ?");
    $upd->bind_param('i', $docId);
    $upd->execute();
    $upd->close();

    // 2) Create a new Incoming row sent back to the original sender.
    $ins = $conn->prepare("INSERT INTO documents
        (title, status, sender_name, receiver_name, route_state, submitted_via, remarks, is_read, created_at)
        VALUES (?, ?, ?, ?, 'Incoming', ?, ?, 0, NOW())");
    $ins->bind_param('ssssss', $title, $status, $currentUser, $origSender, $submittedVia, $newRemarks);
    $ins->execute();
    $newId = $ins->insert_id;
    $ins->close();

    // 3) Log history if table exists
    $hCheck = $conn->query("SHOW TABLES LIKE 'document_history'");
    if ($hCheck && $hCheck->num_rows > 0) {
        $h = $conn->prepare("INSERT INTO document_history (document_id, actor, action, details, created_at) VALUES (?, ?, 'Return', ?, NOW())");
        $details = 'Returned to ' . $origSender;
        $h->bind_param('iss', $docId, $currentUser, $details);
        $h->execute();
        $h->close();

        $h2 = $conn->prepare("INSERT INTO document_history (document_id, actor, action, details, created_at) VALUES (?, ?, 'Return', ?, NOW())");
        $details2 = 'Received as return from ' . $currentUser;
        $h2->bind_param('iss', $newId, $currentUser, $details2);
        $h2->execute();
        $h2->close();
    }

    $conn->commit();
    echo json_encode(['success' => true, 'new_id' => $newId]);
} catch (Throwable $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
