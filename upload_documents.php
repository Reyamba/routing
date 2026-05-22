<?php
session_start();

if (!isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require 'config.php';
header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    $title        = trim($_POST['title'] ?? '');
    $status       = trim($_POST['status'] ?? '');
    $receiverName = trim($_POST['receiver'] ?? '');
    $remarks      = trim($_POST['remarks'] ?? '');
    $submittedVia = trim($_POST['submitted_via'] ?? '');

    $date_time_receiving    = ($_POST['date_time_receiving'] ?? '') ?: NULL;
    $time_stamp_received    = ($_POST['time_stamp_received'] ?? '') ?: NULL;
    $time_record_in_logbook = ($_POST['time_record_in_logbook'] ?? '') ?: NULL;
    $date_time_filed        = ($_POST['date_time_filed'] ?? '') ?: NULL;

    if ($title === '') {
        throw new Exception('Title is required.');
    }
    if ($status === '') {
        $status = 'For Appropriate Action';
    }

    $allowedSubmittedVia = ['Official PSA Email', 'Hardcopy', 'Facebook Messenger'];
    if (!in_array($submittedVia, $allowedSubmittedVia, true)) {
        throw new Exception('Submitted Via must be one of: ' . implode(', ', $allowedSubmittedVia));
    }

    if ($receiverName !== '') {
        $userStmt = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $userStmt->bind_param('s', $receiverName);
        $userStmt->execute();
        $userResult = $userStmt->get_result();
        $userStmt->close();
        if (!$userResult || $userResult->num_rows === 0) {
            throw new Exception('Receiver must be a registered user.');
        }
    }

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("INSERT INTO documents
            (title, status, doc_name, submitted_via, owner, sender_name, receiver_name, remarks, route_state, date_created, is_read, date_time_receiving, time_stamp_received, time_record_in_logbook, date_time_filed)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW(), 0, ?, ?, ?, ?)");
        $owner      = $_SESSION['username'];
        $senderName = $_SESSION['username'];
        $docName    = $title;
        $stmt->bind_param(
            "ssssssssssss",
            $title, $status, $docName, $submittedVia,
            $owner, $senderName, $receiverName, $remarks,
            $date_time_receiving, $time_stamp_received, $time_record_in_logbook, $date_time_filed
        );

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new Exception('Database error: ' . $error);
        }
        $document_id = $conn->insert_id;
        $stmt->close();

        // History log
        $hist = $conn->prepare("INSERT INTO document_history (document_id, action, actor, from_state, to_state, notes) VALUES (?, 'Upload', ?, NULL, 'Pending', ?)");
        $note = 'Submitted via: ' . $submittedVia;
        $hist->bind_param('iss', $document_id, $owner, $note);
        $hist->execute();
        $hist->close();

        $conn->commit();

        echo json_encode([
            'success'     => true,
            'message'     => 'Document submitted successfully',
            'document_id' => $document_id
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

$conn->close();
