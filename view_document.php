<?php
session_start();
if (!isset($_SESSION['username'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/config.php';

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    header('Location: incoming.php');
    exit;
}

// Verify the document belongs to this user and is in Incoming state
$stmt = $conn->prepare('SELECT * FROM documents WHERE id = ? AND route_state = ? AND receiver_name = ? LIMIT 1');
$incomingState = 'Incoming';
$stmt->bind_param('iss', $id, $incomingState, $_SESSION['username']);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

if (!$result || $result->num_rows === 0) {
    header('Location: incoming.php');
    exit;
}

$document = $result->fetch_assoc();

// Mark document as read when viewed
$markRead = $conn->prepare('UPDATE documents SET is_read = 1 WHERE id = ?');
$markRead->bind_param('i', $id);
$markRead->execute();
$markRead->close();

function resolve_document_file_path(array $document): ?string {
    $candidates = [];
    $stored = trim($document['file_path']);

    if (!empty($stored)) {
        if (strpos($stored, '/') !== false || strpos($stored, '\\') !== false || strpos($stored, 'uploads') !== false) {
            $candidates[] = __DIR__ . '/' . ltrim($stored, '/\\');
        }
        $base = basename($stored);
        $candidates[] = __DIR__ . '/uploads/documents/' . $base;
        $candidates[] = __DIR__ . '/uploads/' . $base;
    }

    if (!empty($document['doc_name'])) {
        $base = basename($document['doc_name']);
        $candidates[] = __DIR__ . '/uploads/documents/' . $base;
        $candidates[] = __DIR__ . '/uploads/' . $base;
    }

    foreach ($candidates as $candidate) {
        if (!empty($candidate) && is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function file_url_for_path(string $path): string {
    $relative = str_replace('\\', '/', substr($path, strlen(__DIR__)));
    $relative = '/' . ltrim($relative, '/');
    $segments = array_map('rawurlencode', explode('/', trim($relative, '/')));
    return '/' . implode('/', $segments);
}

function auto_recover_document_path($conn, array $document): ?string {
    $file_path = resolve_document_file_path($document);
    if ($file_path) {
        return $file_path;
    }

    $possibleNames = [];
    if (!empty($document['file_path'])) {
        $possibleNames[] = basename($document['file_path']);
    }
    if (!empty($document['doc_name'])) {
        $possibleNames[] = basename($document['doc_name']);
    }
    $possibleNames = array_unique(array_filter(array_map('trim', $possibleNames)));

    $searchDirs = [__DIR__ . '/uploads/documents', __DIR__ . '/uploads'];
    foreach ($possibleNames as $name) {
        foreach ($searchDirs as $dir) {
            $candidate = $dir . '/' . $name;
            if (is_file($candidate)) {
                return $candidate;
            }
        }
    }

    if (!empty($document['created_at'])) {
        $targetTs = strtotime($document['created_at']);
        if ($targetTs !== false) {
            $best = null;
            $bestDiff = PHP_INT_MAX;
            foreach (glob(__DIR__ . '/uploads/documents/*') as $file) {
                if (!is_file($file)) {
                    continue;
                }
                $diff = abs(filemtime($file) - $targetTs);
                if ($diff < $bestDiff) {
                    $bestDiff = $diff;
                    $best = $file;
                }
            }
            if ($best !== null && $bestDiff <= 86400) {
                return $best;
            }
        }
    }

    return null;
}

$file_path = auto_recover_document_path($conn, $document);
if ($file_path) {
    $basename = basename($file_path);
    $size = filesize($file_path);
    $type = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
    $update = $conn->prepare('UPDATE documents SET file_path = ?, file_size = ?, type = ? WHERE id = ?');
    if ($update) {
        $update->bind_param('sisi', $basename, $size, $type, $document['id']);
        $update->execute();
        $update->close();
    }
}

if (!$file_path) {
    die('File not found on server. The stored file reference does not match any file on disk.');
}

// Get file extension and determine viewing method
$file_extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
$filename = basename($file_path);

// Native inline viewable types
$native_viewable = ['pdf', 'txt', 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg'];

// Office/document types that can be viewed via a local page
$office_types = ['doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx'];

// All viewable types
$all_viewable = array_merge($native_viewable, $office_types);

if (!in_array($file_extension, $all_viewable)) {
    // For unsupported types, force download
    $mime_type = mime_content_type($file_path) ?: 'application/octet-stream';
    header('Content-Type: ' . $mime_type);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($file_path));
    readfile($file_path);
    exit;
}

// Check if download is requested
$isDownload = isset($_GET['download']) && $_GET['download'] === '1';
if ($isDownload) {
    $mime_type = mime_content_type($file_path) ?: 'application/octet-stream';
    header('Content-Type: ' . $mime_type);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($file_path));
    readfile($file_path);
    exit;
}

$fileUrl = file_url_for_path($file_path);

if (in_array($file_extension, $native_viewable)) {
    $mime_type = mime_content_type($file_path);
    header('Content-Type: ' . $mime_type);
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($file_path));
    readfile($file_path);
    exit;
} else {
    // Display office/document types using a local viewer/fallback page
    $filesize_kb = number_format($document['file_size'] / 1024, 1);
    $mime_type = mime_content_type($file_path) ?: 'application/octet-stream';
    $baseUrl = rtrim(dirname($_SERVER['REQUEST_URI']), '/\\');
    $fileUrl = $baseUrl . '/uploads/documents/' . rawurlencode($filename);
    ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Document - <?php echo htmlspecialchars($document['title']); ?></title>
    <style>
        body { margin: 0; padding: 20px; font-family: Arial, sans-serif; background: #f5f5f5; }
        .viewer-container { max-width: 100%; margin: 0 auto; background: white; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); overflow: hidden; }
        .viewer-header { padding: 16px 20px; border-bottom: 1px solid #e0e0e0; }
        .viewer-title { margin: 0; font-size: 18px; color: #333; }
        .viewer-subtitle { margin: 4px 0 0 0; font-size: 12px; color: #999; }
        .viewer-controls { margin-top: 12px; }
        .btn { display: inline-block; padding: 8px 16px; margin-right: 8px; background: #0012b0; color: white; text-decoration: none; border-radius: 4px; font-size: 14px; }
        .btn:hover { background: #000d8a; }
        .btn-download { background: #10b981; }
        .btn-download:hover { background: #059669; }
        .viewer-frame, .viewer-object { width: 100%; height: 700px; border: none; }
        .viewer-info { padding: 16px 20px; background: #fef3c7; border-top: 1px solid #fcd34d; display: flex; align-items: center; justify-content: space-between; }
        .info-text { font-size: 14px; color: #92400e; }
        .fallback { padding: 24px; text-align: center; }
    </style>
</head>
<body>
    <div class="viewer-container">
        <div class="viewer-header">
            <h1 class="viewer-title"><?php echo htmlspecialchars($document['title']); ?></h1>
            <p class="viewer-subtitle">From: <?php echo htmlspecialchars($document['sender_name']); ?> | Type: <?php echo strtoupper($file_extension); ?> | Size: <?php echo $filesize_kb; ?> KB</p>
            <div class="viewer-controls">
                <a href="incoming.php" class="btn">← Back to Inbox</a>
                <a href="view_document.php?id=<?php echo intval($id); ?>&download=1" class="btn btn-download">⬇ Download</a>
                <a href="<?php echo htmlspecialchars($fileUrl); ?>" class="btn btn-download" target="_blank">Open Directly</a>
            </div>
        </div>
        <?php if ($file_extension === 'csv'): ?>
            <div class="viewer-frame">
                <?php
                $csvData = array_map('str_getcsv', file($file_path));
                if ($csvData): ?>
                    <table style="width:100%; border-collapse:collapse;">
                        <?php foreach ($csvData as $row): ?>
                            <tr>
                                <?php foreach ($row as $cell): ?>
                                    <td style="border:1px solid #e5e7eb; padding:8px; font-family:monospace; white-space:pre-wrap;"><?php echo htmlspecialchars($cell); ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php else: ?>
                    <div class="fallback"><p>No preview available for this CSV file.</p></div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <object class="viewer-object" data="<?php echo htmlspecialchars($fileUrl); ?>" type="<?php echo htmlspecialchars($mime_type); ?>">
                <div class="fallback">
                    <p>No preview available for this file type in your browser.</p>
                    <p><a href="<?php echo htmlspecialchars($fileUrl); ?>" target="_blank">Open file directly</a> or use the Download button above.</p>
                </div>
            </object>
        <?php endif; ?>
        <div class="viewer-info">
            <span class="info-text">📄 Viewing <?php echo strtoupper($file_extension); ?> document</span>
        </div>
    </div>
</body>
</html><?php
    exit;
}


