<?php
session_start();
if (!isset($_SESSION['username'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/config.php';

$exportType = isset($_GET['type']) ? $_GET['type'] : 'files'; // 'files' or 'transactions'
$docId = isset($_GET['doc_id']) ? intval($_GET['doc_id']) : 0;

if ($docId > 0) {
    $stmt = $conn->prepare('SELECT title, status, created_at, receiver_name, sender_name, remarks, route_state, file_path, type FROM documents WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $docId);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    if (!$result || $result->num_rows === 0) {
        header('Location: dashboard.php?status=document_not_found');
        exit;
    }

    $row = $result->fetch_assoc();

    // For transaction export, allow access if user is sender or receiver
    // For file export, only allow if user is receiver (to maintain security for file downloads)
    if ($exportType === 'files') {
        if (trim($row['receiver_name']) !== $_SESSION['username']) {
            header('Location: dashboard.php?status=export_error');
            exit;
        }
    } else {
        // For transaction export, allow if user is sender or receiver
        if (trim($row['sender_name']) !== $_SESSION['username'] && trim($row['receiver_name']) !== $_SESSION['username']) {
            header('Location: dashboard.php?status=export_error');
            exit;
        }
    }

    if ($exportType === 'files') {
        if (!empty($row['file_path'])) {
            $file_path = 'uploads/documents/' . $row['file_path'];
            if (file_exists($file_path)) {
                $mime_type = mime_content_type($file_path);
                header('Content-Type: ' . $mime_type);
                header('Content-Disposition: attachment; filename="' . basename($file_path) . '"');
                header('Content-Length: ' . filesize($file_path));
                readfile($file_path);
                exit;
            }
        }

        // Fallback to CSV if no file
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="document-export-' . $docId . '-' . date('YmdHis') . '.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Title', 'Status', 'Date & Time', 'Receiver Name', 'Sender Name', 'Remarks', 'Route State', 'File Path']);
        fputcsv($output, [
            $row['title'],
            $row['status'],
            $row['created_at'],
            $row['receiver_name'],
            $row['sender_name'],
            $row['remarks'],
            $row['route_state'],
            $row['file_path']
        ]);
        fclose($output);
        exit;
    }
}

if ($exportType === 'files') {
    $query = 'SELECT title, status, created_at, receiver_name, sender_name, remarks, route_state, file_path FROM documents WHERE sender_name = ? AND file_path IS NOT NULL AND file_path != "" ORDER BY created_at DESC';
    $result = $conn->prepare($query);
    $result->bind_param('s', $_SESSION['username']);
    $result->execute();
    $result = $result->get_result();

    if (!$result || $result->num_rows === 0) {
        header('Location: dashboard.php?status=export_error');
        exit;
    }

    // Create ZIP
    $zip = new ZipArchive();
    $zip_filename = 'document-files-' . date('YmdHis') . '.zip';
    $zip_path = sys_get_temp_dir() . '/' . $zip_filename;

    if ($zip->open($zip_path, ZipArchive::CREATE) !== TRUE) {
        header('Location: dashboard.php?status=export_error');
        exit;
    }

    while ($row = $result->fetch_assoc()) {
        $file_path = 'uploads/documents/' . $row['file_path'];
        if (file_exists($file_path)) {
            $zip->addFile($file_path, basename($file_path));
        }
    }

    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
    header('Content-Length: ' . filesize($zip_path));
    readfile($zip_path);
    unlink($zip_path);
    exit;
}


// Transaction Export
if ($exportType === 'transactions') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="document-transactions-' . date('YmdHis') . '.csv"');
    $output = fopen('php://output', 'w');

    // CSV Headers
    fputcsv($output, [
        'Document ID',
        'Title',
        'Status',
        'Route State',
        'Sender',
        'Receiver',
        'Remarks',
        'Created At',
        'Received At',
        'Action',
        'Actor',
        'From State',
        'To State',
        'Notes',
        'History Timestamp'
    ]);

    if ($docId > 0) {
        // Single document transaction export
        $stmt = $conn->prepare('SELECT * FROM documents WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $docId);
        $stmt->execute();
        $doc = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$doc) {
            header('Location: dashboard.php?status=document_not_found');
            exit;
        }

        // Get related document IDs for full transaction history
        $ids = [intval($doc['id'])];
        $find = $conn->prepare("SELECT id FROM documents WHERE title = ? AND ((sender_name = ? AND receiver_name = ?) OR (sender_name = ? AND receiver_name = ?))");
        $t = $doc['title']; $s = $doc['sender_name'] ?? ''; $r = $doc['receiver_name'] ?? '';
        $find->bind_param('sssss', $t, $s, $r, $s, $r);
        $find->execute();
        $res = $find->get_result();
        while ($row = $res->fetch_assoc()) {
            $ids[] = intval($row['id']);
        }
        $find->close();

        // Export document info
        fputcsv($output, [
            $doc['id'],
            $doc['title'],
            $doc['status'],
            $doc['route_state'],
            $doc['sender_name'],
            $doc['receiver_name'],
            $doc['remarks'],
            $doc['created_at'],
            $doc['received_at'],
            'Document Created',
            $doc['sender_name'],
            '',
            $doc['route_state'],
            'Initial document creation',
            $doc['created_at']
        ]);

        // Export history for all related documents
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $sql = "SELECT h.*, d.title, d.sender_name, d.receiver_name FROM document_history h
                JOIN documents d ON h.document_id = d.id
                WHERE h.document_id IN ($placeholders) ORDER BY h.created_at ASC, h.id ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        $history = $stmt->get_result();

        while ($h = $history->fetch_assoc()) {
            fputcsv($output, [
                $h['document_id'],
                $h['title'],
                '', // status
                '', // route_state
                $h['sender_name'],
                $h['receiver_name'],
                '', // remarks
                '', // created_at
                '', // received_at
                $h['action'],
                $h['actor'],
                $h['from_state'],
                $h['to_state'],
                $h['notes'],
                $h['created_at']
            ]);
        }
    } else {
        // Bulk transaction export for all user's documents
        $query = 'SELECT d.*, h.action, h.actor, h.from_state, h.to_state, h.notes, h.created_at as history_created_at
                  FROM documents d
                  LEFT JOIN document_history h ON d.id = h.document_id
                  WHERE d.sender_name = ? OR d.receiver_name = ?
                  ORDER BY d.id ASC, h.created_at ASC';
        $stmt = $conn->prepare($query);
        $stmt->bind_param('ss', $_SESSION['username'], $_SESSION['username']);
        $stmt->execute();
        $result = $stmt->get_result();

        $currentDocId = null;
        while ($row = $result->fetch_assoc()) {
            // Export document info once per document
            if ($currentDocId !== $row['id']) {
                fputcsv($output, [
                    $row['id'],
                    $row['title'],
                    $row['status'],
                    $row['route_state'],
                    $row['sender_name'],
                    $row['receiver_name'],
                    $row['remarks'],
                    $row['created_at'],
                    $row['received_at'],
                    'Document Created',
                    $row['sender_name'],
                    '',
                    $row['route_state'],
                    'Initial document creation',
                    $row['created_at']
                ]);
                $currentDocId = $row['id'];
            }

            // Export history if exists
            if ($row['action']) {
                fputcsv($output, [
                    $row['id'],
                    $row['title'],
                    '', // status
                    '', // route_state
                    $row['sender_name'],
                    $row['receiver_name'],
                    '', // remarks
                    '', // created_at
                    '', // received_at
                    $row['action'],
                    $row['actor'],
                    $row['from_state'],
                    $row['to_state'],
                    $row['notes'],
                    $row['history_created_at']
                ]);
            }
        }
    }

    fclose($output);
    exit;
}
