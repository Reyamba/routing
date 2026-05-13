<?php
session_start();
if (!isset($_SESSION['username'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/db_helpers.php';
ensure_schema($conn);

// Outgoing for the current user only
$senderName = $_SESSION['username'];
$incomingState = 'Incoming';

// Get total incoming count for sidebar notification
$incomingCountStmt = $conn->prepare("SELECT COUNT(*) as total_count FROM documents WHERE route_state = ? AND receiver_name = ?");
$incomingCountStmt->bind_param('ss', $incomingState, $senderName);
$incomingCountStmt->execute();
$incomingCountResult = $incomingCountStmt->get_result();
$totalIncomingCount = $incomingCountResult->fetch_assoc()['total_count'];
$incomingCountStmt->close();
// Show only outgoing docs that the receiver has NOT yet marked as received.
// Once acknowledged, the document disappears from the sender's Outgoing page
// but remains visible to everyone in dashboard.php for transparency.
$stmt = $conn->prepare("
    SELECT o.*
    FROM documents o
    WHERE o.route_state = 'Outgoing'
      AND o.sender_name = ?
      AND NOT EXISTS (
          SELECT 1 FROM documents r
          WHERE r.route_state   = 'Received'
            AND r.title         = o.title
            AND r.sender_name   = o.sender_name
            AND r.receiver_name = o.receiver_name
      )
    ORDER BY o.created_at DESC
");
$stmt->bind_param('s', $senderName);
$stmt->execute();
$outgoingDocuments = $stmt->get_result();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Outgoing Documents</title>
    <link rel="shortcut icon" type="image/x-icon" href="logo.png" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <style>
        :root { --primary-color: #0012b0; --bg-light: #f9fafb; --text-main: #111827; --text-muted: #6b7280; --border-color: #e5e7eb; }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', sans-serif; }
        body { background: var(--bg-light); min-height: 100vh; color: var(--text-main); }
        .dashboard-container { display: flex; min-height: 100vh; }
        .sidebar { width: 260px; background: white; border-right: 1px solid var(--border-color); padding: 24px; position: fixed; height: 100vh; overflow-y: auto; }
        .logo-section { display: flex; align-items: center; gap: 12px; margin-bottom: 32px; font-size: 1.1rem; font-weight: 700; color: var(--primary-color); }
        .sidebar-menu { display: flex; flex-direction: column; gap: 8px; }
        .menu-item { display: flex; align-items: center; gap: 10px; padding: 12px 16px; color: var(--text-muted); text-decoration: none; border-radius: 8px; transition: background 0.2s; }
        .menu-item:hover, .menu-item.active { background: rgba(0, 18, 176, 0.08); color: var(--primary-color); }
        .main-content { margin-left: 260px; padding: 32px; flex:1; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .header h1 { font-size: 1.75rem; }
        .content-card { background: white; border-radius: 16px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 14px 16px; border-bottom: 1px solid var(--border-color); text-align: left; }
        th { text-transform: uppercase; font-size: 0.82rem; letter-spacing: 0.05em; color: var(--text-muted); }
        tr:hover { background: #f8fafc; }
        .empty-state { padding: 48px; text-align: center; color: var(--text-muted); }
        .badge { display:inline-block; padding:4px 10px; border-radius:999px; font-size:.75rem; font-weight:600; }
        .badge.delivered { background:#dcfce7; color:#166534; }
        .badge.sent { background:#fef3c7; color:#92400e; }
        .action-btn { display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border-radius:8px; background:#f3f4f6; color:#374151; text-decoration:none; font-size:.8rem; font-weight:600; }
    </style>
</head>
<body>
<div class="dashboard-container">
    <div class="sidebar">
        <div class="logo-section"><i class="fas fa-file-alt"></i><span>PSA ROUTING</span></div>
        <nav class="sidebar-menu">
            <a href="dashboard.php" class="menu-item"><i class="fas fa-home"></i><span>Dashboard</span></a>
            <a href="incoming.php" class="menu-item">
                <i class="fas fa-arrow-down"></i>
                <span>Incoming</span>
                <?php if ($totalIncomingCount > 0): ?>
                    <span style="background:#3b82f6;color:white;padding:2px 6px;border-radius:10px;font-size:.7rem;margin-left:auto;"><?php echo $totalIncomingCount; ?></span>
                <?php endif; ?>
            </a>
            <a href="outgoing.php" class="menu-item active"><i class="fas fa-arrow-up"></i><span>Outgoing</span></a>
            <a href="pending.php" class="menu-item"><i class="fas fa-clock"></i><span>Pending</span></a>
        </nav>
    </div>
    <main class="main-content">
        <div class="header"><h1>Outgoing Documents</h1></div>
        <div class="content-card">
            <?php if ($outgoingDocuments && $outgoingDocuments->num_rows > 0): ?>
                <div class="table-container"><table>
                    <thead><tr><th>Title</th><th>Status</th><th>Receiver</th><th>Sent At</th><th>File Info</th><th>Receipt</th><th>Remarks</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php while ($row = $outgoingDocuments->fetch_assoc()):
                        // Has the receiver acknowledged? Look for a Received row matching title+receiver+sender.
                        $ack = null;
                        $a = $conn->prepare("SELECT date_time_receiving FROM documents WHERE route_state = 'Received' AND receiver_name = ? AND sender_name = ? AND title = ? ORDER BY date_time_receiving DESC LIMIT 1");
                        $a->bind_param('sss', $row['receiver_name'], $senderName, $row['title']);
                        $a->execute();
                        $ar = $a->get_result();
                        if ($ar && $arow = $ar->fetch_assoc()) { $ack = $arow['date_time_receiving']; }
                        $a->close();
                        
                        // File info display
                        $fileInfo = '';
                        if (!empty($row['file_path'])) {
                            $fileName = htmlspecialchars($row['doc_name'] ?? basename($row['file_path']));
                            $fileSize = $row['file_size'] ? number_format($row['file_size'] / 1024, 1) . ' KB' : '';
                            $fileType = htmlspecialchars($row['type'] ?? '');
                            
                            $fileInfo = '<div style="font-size:.85rem;">';
                            $fileInfo .= '<i class="fas fa-file" style="color:#6b7280;"></i> ' . $fileName;
                            if ($fileSize) $fileInfo .= '<br><small style="color:#6b7280;">' . $fileSize . '</small>';
                            if ($fileType) $fileInfo .= '<br><small style="color:#6b7280;">' . strtoupper($fileType) . '</small>';
                            $fileInfo .= '</div>';
                        } else {
                            $fileInfo = '<span style="color:#6b7280;font-size:.85rem;">No file attached</span>';
                        }
                    ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['title']); ?></td>
                            <td><?php echo htmlspecialchars($row['status']); ?></td>
                            <td><?php echo htmlspecialchars($row['receiver_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['created_at']); ?></td>
                            <td><?php echo $fileInfo; ?></td>
                            <td>
                                <?php if ($ack): ?>
                                    <span class="badge delivered">Received <?php echo htmlspecialchars($ack); ?></span>
                                <?php else: ?>
                                    <span class="badge sent">Awaiting receipt</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($row['remarks']); ?></td>
                            <td><a class="action-btn" href="history.php?id=<?php echo intval($row['id']); ?>&return_to=outgoing.php"><i class="fas fa-clock-rotate-left"></i> History</a></td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table></div>
            <?php else: ?>
                <div class="empty-state"><i class="fas fa-paper-plane" style="font-size: 3rem; margin-bottom: 16px;"></i><p>No outgoing documents yet.</p></div>
            <?php endif; ?>
        </div>
    </main>
</div>
</body>
</html>
