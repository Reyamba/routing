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

$user = $_SESSION['username'];

$upd = $conn->prepare(
    "UPDATE documents
     SET is_read = 1
     WHERE receiver_name = ? AND route_state = 'Incoming' AND is_read = 0"
);
$upd->bind_param('s', $user);
$ok = $upd->execute();
$count = $upd->affected_rows;
$upd->close();

if (!$ok) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

echo json_encode(['success' => true, 'updated' => $count]);

