<?php
require_once __DIR__ . '/config.php';

$query = 'SELECT title, status, created_at, receiver_name, sender_name, remarks, route_state FROM documents ORDER BY created_at DESC';
$result = $conn->query($query);

if (!$result) {
    echo "Query failed: " . $conn->error;
} else {
    echo "Number of documents: " . $result->num_rows;
}
?>