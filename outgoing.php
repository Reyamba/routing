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
// Show outgoing docs the current user sent (originally or via forward)
// that the receiver has NOT yet marked as received.
$stmt = $conn->prepare("
    SELECT o.*
    FROM documents o
    WHERE o.sender_name = ?
    
      -- Only active outgoing docs
      AND o.route_state IN ('Outgoing','Incoming')

      -- Hide if already returned
      AND o.route_state != 'Returned'

      -- Hide if already forwarded
      AND NOT EXISTS (
          SELECT 1
          FROM document_history h
          WHERE h.document_id = o.id
            AND h.action = 'Forward'
      )

      -- Hide if already received
      AND NOT EXISTS (
          SELECT 1
          FROM documents r
          WHERE r.title = o.title
            AND r.route_state = 'Received'
            AND (
                r.sender_name = o.receiver_name
                OR r.receiver_name = o.receiver_name
            )
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
        .badge.forwarded { background:#e0e7ff; color:#3730a3; margin-left:6px; }
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
                <span id="incomingBadge" data-count="<?php echo (int)$totalIncomingCount; ?>" style="background:#3b82f6;color:white;padding:2px 6px;border-radius:10px;font-size:.7rem;margin-left:auto;<?php echo $totalIncomingCount > 0 ? '' : 'display:none;'; ?>"><?php echo (int)$totalIncomingCount; ?></span>
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
                    <thead><tr><th>Title</th><th>Status</th><th>Receiver</th><th>Sent At</th><th>Submitted Via</th><th>Receipt</th><th>Remarks</th><th>Action</th></tr></thead>
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
                        
                        // Submitted via display
                        $submittedVia = trim((string)($row['submitted_via'] ?? ''));
                        if ($submittedVia !== '') {
                            $iconMap = [
                                'Official PSA Email'   => 'fa-envelope',
                                'Hardcopy'             => 'fa-file-lines',
                                'Facebook Messenger'   => 'fa-facebook-messenger',
                            ];
                            $icon = $iconMap[$submittedVia] ?? 'fa-inbox';
                            $iconPrefix = ($submittedVia === 'Facebook Messenger') ? 'fab' : 'fas';
                            $submittedViaDisplay = '<div style="font-size:.85rem;"><i class="' . $iconPrefix . ' ' . $icon . '" style="color:#6b7280;"></i> ' . htmlspecialchars($submittedVia) . '</div>';
                        } else {
                            $submittedViaDisplay = '<span style="color:#6b7280;font-size:.85rem;">—</span>';
                        }
                    ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['title']); ?><?php if (!empty($row['is_forwarded'])): ?> <span class="badge forwarded"><i class="fas fa-share"></i> Forwarded</span><?php endif; ?></td>
                            <td><?php echo htmlspecialchars($row['status']); ?></td>
                            <td><?php echo htmlspecialchars($row['receiver_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['created_at']); ?></td>
                            <td><?php echo $submittedViaDisplay; ?></td>
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
<script>
(function(){
    var badge = document.getElementById('incomingBadge');
    if (!badge) return;
    var lastCount = parseInt(badge.getAttribute('data-count') || '0', 10);
    var reloadOnIncrease = false;

    function ensureToast(msg){
        var t = document.createElement('div');
        t.textContent = msg;
        t.style.cssText = 'position:fixed;top:20px;right:20px;background:#3b82f6;color:#fff;padding:12px 18px;border-radius:8px;font-family:Inter,sans-serif;font-size:.9rem;font-weight:600;box-shadow:0 6px 18px rgba(0,0,0,.15);z-index:9999;';
        document.body.appendChild(t);
        setTimeout(function(){ t.remove(); }, 3500);
    }

    function tick(){
        fetch('incoming_count.php', { credentials: 'same-origin', cache: 'no-store' })
            .then(function(r){ return r.json(); })
            .then(function(j){
                if (!j || j.auth === false) return;
                var c = parseInt(j.count || 0, 10);
                badge.setAttribute('data-count', c);
                badge.textContent = c;
                badge.style.display = c > 0 ? '' : 'none';
                if (c > lastCount) {
                    var diff = c - lastCount;
                    ensureToast(diff + ' new incoming document' + (diff>1?'s':''));
                    if (reloadOnIncrease) {
                        setTimeout(function(){ location.reload(); }, 1200);
                    }
                }
                lastCount = c;
            })
            .catch(function(){});
    }
    setInterval(tick, 8000);
})();
</script>

</body>
</html>
