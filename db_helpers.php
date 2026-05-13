<?php
// Shared helpers for ensuring schema columns/tables and logging history.
require_once __DIR__ . '/config.php';

function ensure_schema(mysqli $conn): void {
    $r = $conn->query("SHOW COLUMNS FROM documents LIKE 'route_state'");
    if ($r && $r->num_rows === 0) {
        $conn->query("ALTER TABLE documents ADD COLUMN route_state VARCHAR(32) NOT NULL DEFAULT 'Pending'");
    }
    $r = $conn->query("SHOW COLUMNS FROM documents LIKE 'sender_name'");
    if ($r && $r->num_rows === 0) {
        $conn->query("ALTER TABLE documents ADD COLUMN sender_name VARCHAR(255) DEFAULT NULL");
    }
    $r = $conn->query("SHOW COLUMNS FROM documents LIKE 'received_at'");
    if ($r && $r->num_rows === 0) {
        $conn->query("ALTER TABLE documents ADD COLUMN received_at DATETIME DEFAULT NULL");
    }

    $conn->query("CREATE TABLE IF NOT EXISTS document_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        document_id INT NOT NULL,
        action VARCHAR(64) NOT NULL,
        actor VARCHAR(255) NOT NULL,
        from_state VARCHAR(32) DEFAULT NULL,
        to_state VARCHAR(32) DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (document_id)
    )");
}

function log_history(mysqli $conn, int $documentId, string $action, string $actor,
                     ?string $fromState = null, ?string $toState = null, ?string $notes = null): void {
    $stmt = $conn->prepare('INSERT INTO document_history (document_id, action, actor, from_state, to_state, notes) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('isssss', $documentId, $action, $actor, $fromState, $toState, $notes);
    $stmt->execute();
    $stmt->close();
}
