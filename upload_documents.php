<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Database connection
require 'config.php';

header('Content-Type: application/json');

try {
    // Check if it's a POST request
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    // Validate file upload
    if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No file uploaded or upload error');
    }

    $file = $_FILES['document'];
    $title = trim($_POST['title'] ?? '');
    $status = trim($_POST['status'] ?? '');
    $receiverName = trim($_POST['receiver'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    $doc_name = trim(pathinfo($file['name'], PATHINFO_FILENAME));
    $date_time_receiving = ($_POST['date_time_receiving'] ?? '') ? $_POST['date_time_receiving'] : NULL;
    $time_stamp_received = ($_POST['time_stamp_received'] ?? '') ? $_POST['time_stamp_received'] : NULL;
    $time_record_in_logbook = ($_POST['time_record_in_logbook'] ?? '') ? $_POST['time_record_in_logbook'] : NULL;
    $date_time_filed = ($_POST['date_time_filed'] ?? '') ? $_POST['date_time_filed'] : NULL;

    if ($title === '') {
        $title = $doc_name;
    }

    if ($status === '') {
        $status = 'For Appropriate Action';
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

    // Validate file type
    $allowed_types = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'jpg', 'jpeg', 'png'];
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($file_extension, $allowed_types)) {
        throw new Exception('Invalid file type. Allowed types: ' . implode(', ', $allowed_types));
    }

    // Check file size (max 2GB)
    $max_size = 2 * 1024 * 1024 * 1024; // 2GB
    if ($file['size'] > $max_size) {
        throw new Exception('File size too large. Maximum size is 2GB');
    }

    // Create uploads directory if it doesn't exist
    $upload_dir = 'uploads/documents/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    // Generate unique filename
    $unique_filename = uniqid() . '_' . time() . '.' . $file_extension;
    $file_path = $upload_dir . $unique_filename;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $file_path)) {
        throw new Exception('Failed to save uploaded file');
    }

    // Start transaction
    $conn->begin_transaction();

    try {
        // Insert document record
        $stmt = $conn->prepare("INSERT INTO documents (title, status, doc_name, file_path, file_size, type, owner, sender_name, receiver_name, remarks, route_state, date_created, is_read, date_time_receiving, time_stamp_received, time_record_in_logbook, date_time_filed) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW(), 0, ?, ?, ?, ?)");
        $owner = $_SESSION['username'];
        $senderName = $_SESSION['username'];
        $stmt->bind_param("ssssississssss", $title, $status, $doc_name, $unique_filename, $file['size'], $file_extension, $owner, $senderName, $receiverName, $remarks, $date_time_receiving, $time_stamp_received, $time_record_in_logbook, $date_time_filed);
        if (!$stmt->execute()) {
            $errno = $stmt->errno;
            $error = $stmt->error;
            $stmt->close();
            if ($errno === 1062) {
                throw new Exception('Control number already exists. Please use a different control number.');
            }
            throw new Exception('Database error: ' . $error);
        }
        $document_id = $conn->insert_id;
        $stmt->close();

        // Commit transaction
        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Document uploaded successfully',
            'document_id' => $document_id
        ]);

    } catch (Exception $e) {
        // Rollback transaction
        $conn->rollback();
        // Delete uploaded file if database operation failed
        if (file_exists($file_path)) {
            unlink($file_path);
        }
        throw $e;
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

$conn->close();
?>
