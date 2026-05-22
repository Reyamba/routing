<?php
session_start();
if (!isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

require_once __DIR__ . '/db_helpers.php';
ensure_schema($conn);

// Ensure submitted_via column exists
$colCheck = $conn->query("SHOW COLUMNS FROM documents LIKE 'submitted_via'");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE documents ADD COLUMN submitted_via VARCHAR(64) NULL");
}

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

$searchTerm = isset($_GET['q']) ? trim($_GET['q']) : '';
if ($searchTerm !== '') {
    $like = '%' . $searchTerm . '%';
    $stmt = $conn->prepare(
        "SELECT d.id, d.title, d.status, d.route_state, d.sender_name, d.receiver_name, d.created_at, d.received_at, d.remarks, d.submitted_via
         FROM documents d
         WHERE LOWER(TRIM(d.route_state)) = 'received'
           AND (
                d.title COLLATE utf8mb4_general_ci LIKE ?
             OR d.status COLLATE utf8mb4_general_ci LIKE ?
             OR d.sender_name COLLATE utf8mb4_general_ci LIKE ?
             OR d.receiver_name COLLATE utf8mb4_general_ci LIKE ?
             OR d.route_state COLLATE utf8mb4_general_ci LIKE ?
             OR d.remarks COLLATE utf8mb4_general_ci LIKE ?
             OR d.submitted_via COLLATE utf8mb4_general_ci LIKE ?
           )
         ORDER BY d.received_at DESC, d.created_at DESC"
    );
    $stmt->bind_param('sssssss', $like, $like, $like, $like, $like, $like, $like);
    $stmt->execute();
    $res = $stmt->get_result();
} else {
    $stmt = $conn->prepare(
        "SELECT d.id, d.title, d.status, d.route_state, d.sender_name, d.receiver_name, d.created_at, d.received_at, d.remarks, d.submitted_via
         FROM documents d
         WHERE LOWER(TRIM(d.route_state)) = 'received'
         ORDER BY d.received_at DESC, d.created_at DESC"
    );
    $stmt->execute();
    $res = $stmt->get_result();
}

$rows = [];
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
}

echo json_encode([
    'count' => count($rows),
    'documents' => $rows,
    'server_time' => date('Y-m-d H:i:s'),
]);
