<?php
session_start();
if (!isset($_SESSION['username'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/db_helpers.php';
ensure_schema($conn);

$receiverName = $_SESSION['username'];
$incomingState = 'Incoming';

// Ensure submitted_via column exists
$colCheck = $conn->query("SHOW COLUMNS FROM documents LIKE 'submitted_via'");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE documents ADD COLUMN submitted_via VARCHAR(64) NULL");
}

// Get unread count for sidebar
$unreadStmt = $conn->prepare("SELECT COUNT(*) as unread_count FROM documents WHERE route_state = ? AND receiver_name = ? AND is_read = 0");
$unreadStmt->bind_param('ss', $incomingState, $receiverName);
$unreadStmt->execute();
$unreadResult = $unreadStmt->get_result();
$unreadCount = $unreadResult->fetch_assoc()['unread_count'];
$unreadStmt->close();

// Get total incoming count for sidebar
$countStmt = $conn->prepare("SELECT COUNT(*) as total_count FROM documents WHERE route_state = ? AND receiver_name = ?");
$countStmt->bind_param('ss', $incomingState, $receiverName);
$countStmt->execute();
$countResult = $countStmt->get_result();
$totalIncoming = $countResult->fetch_assoc()['total_count'];
$countStmt->close();

$stmt = $conn->prepare("SELECT *,
                       CASE
                         WHEN date_time_receiving IS NOT NULL THEN date_time_receiving
                         ELSE created_at
                       END as delivered_at
                       FROM documents
                       WHERE route_state = ? AND receiver_name = ?
                       ORDER BY delivered_at DESC");
$stmt->bind_param('ss', $incomingState, $receiverName);
$stmt->execute();
$incomingDocuments = $stmt->get_result();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Incoming Documents</title>
    <link rel="shortcut icon" type="image/x-icon" href="logo.png" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <style>
        :root { --primary-color: #0012b0; --bg-light: #f9fafb; --text-main: #111827; --text-muted: #6b7280; --border-color: #e5e7eb; --success-color:#10b981; }
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
        th, td { padding: 14px 16px; border-bottom: 1px solid var(--border-color); text-align: left; vertical-align: middle; }
        th { text-transform: uppercase; font-size: 0.82rem; letter-spacing: 0.05em; color: var(--text-muted); }
        tr:hover { background: #f8fafc; }
        .empty-state { padding: 48px; text-align: center; color: var(--text-muted); }
        .action-btn { display:inline-flex; align-items:center; gap:6px; padding:8px 12px; border-radius:8px; border:none; cursor:pointer; font-size:.85rem; font-weight:600; text-decoration:none; }
        .btn-receive { background: var(--success-color); color:white; }
        .btn-receive:hover { background:#059669; }
        .btn-history { background:#f3f4f6; color:#374151; margin-left:6px; }
        .toast { position:fixed; top:16px; right:16px; padding:12px 16px; border-radius:10px; color:white; font-weight:600; z-index:50; }
        .toast.success { background: var(--success-color); }
        .toast.error { background:var(--success-color); }
        .modal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:100; align-items:center; justify-content:center; }
        .modal.active { display:flex; }
        .modal-content { background:white; border-radius:12px; padding:24px; max-width:500px; width:90%; box-shadow:0 10px 40px rgba(0,0,0,0.2); }
        .modal-header { font-size:1.25rem; font-weight:700; margin-bottom:16px; color: var(--primary-color); }
        .modal-close { float:right; font-size:1.5rem; cursor:pointer; color:#999; }
        .form-group { margin-bottom:16px; display:flex; flex-direction:column; gap:6px; }
        .form-group label { font-weight:500; font-size:0.9rem; }
        .form-group textarea { padding:10px; border:1px solid var(--border-color); border-radius:8px; font-family:inherit; }
        .modal-buttons { display:flex; gap:12px; justify-content:flex-end; margin-top:20px; }
        .modal-btn { padding:10px 16px; border-radius:8px; border:none; cursor:pointer; font-weight:600; }
        .modal-btn-primary { background: var(--primary-color); color:white; }
        .modal-btn-primary:hover { background:#000a7f; }
        .modal-btn-secondary { background:#e5e7eb; color: var(--text-main); }
        .modal-btn-secondary:hover { background:#d1d5db; }
        .badge-via { display:inline-block; padding:4px 10px; border-radius:999px; font-size:.78rem; font-weight:600; background:#eef2ff; color:#3730a3; }
    </style>
</head>
<body>
<div class="dashboard-container">
    <div class="sidebar">
        <div class="logo-section"><i class="fas fa-file-alt"></i><span>PSA ROUTING</span></div>
        <nav class="sidebar-menu">
            <a href="dashboard.php" class="menu-item"><i class="fas fa-home"></i><span>Dashboard</span></a>
            <a href="incoming.php" class="menu-item active">
                <i class="fas fa-arrow-down"></i>
                <span>Incoming</span>
                <span id="incomingBadge" data-count="<?php echo (int)$totalIncoming; ?>" style="background:#3b82f6;color:white;padding:2px 6px;border-radius:10px;font-size:.7rem;margin-left:auto;<?php echo $totalIncoming > 0 ? '' : 'display:none;'; ?>"><?php echo (int)$totalIncoming; ?></span>
            </a>
            <a href="outgoing.php" class="menu-item"><i class="fas fa-arrow-up"></i><span>Outgoing</span></a>
            <a href="pending.php" class="menu-item"><i class="fas fa-clock"></i><span>Pending</span></a>
        </nav>
    </div>
    <main class="main-content">
        <div class="header">
            <h1>Incoming Documents <?php if ($unreadCount > 0): ?><span style="background:#ef4444;color:white;padding:4px 8px;border-radius:12px;font-size:.8rem;margin-left:8px;"><?php echo $unreadCount; ?> new</span><?php endif; ?></h1>
            <div style="display:flex;gap:8px;">
                <button class="action-btn" style="background:#6b7280;color:white;" onclick="markAllAsRead()">
                    <i class="fas fa-check-double"></i> Mark All as Read
                </button>
            </div>
        </div>
        <div class="content-card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;padding-bottom:16px;border-bottom:1px solid var(--border-color);">
                <span style="color:var(--text-muted);font-size:.9rem;">
                    <strong><?php echo $incomingDocuments ? intval($incomingDocuments->num_rows) : 0; ?></strong> incoming transaction(s)
                </span>
            </div>
            <?php if ($incomingDocuments && $incomingDocuments->num_rows > 0): ?>
                <div class="table-container"><table id="incomingTable">
                    <thead><tr><th>Title</th><th>Status</th><th>Sender</th><th>Delivered At</th><th>Submitted Via</th><th>Remarks</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php while ($row = $incomingDocuments->fetch_assoc()):
                        $isUnread = !$row['is_read'];
                        $via = $row['submitted_via'] ?? '';
                    ?>
                        <tr data-id="<?php echo intval($row['id']); ?>" <?php echo $isUnread ? 'style="background:#fef3c7;"' : ''; ?>>
                            <td>
                                <?php if ($isUnread): ?>
                                    <i class="fas fa-circle" style="color:#f59e0b;font-size:.6rem;margin-right:6px;"></i>
                                <?php endif; ?>
                                <?php echo htmlspecialchars($row['title']); ?>
                                <?php if ($isUnread): ?>
                                    <span style="background:#f59e0b;color:white;padding:2px 6px;border-radius:10px;font-size:.7rem;margin-left:6px;">NEW</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($row['status']); ?></td>
                            <td><?php echo htmlspecialchars($row['sender_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['delivered_at']); ?></td>
                            <td>
                                <?php if ($via): ?>
                                    <span class="badge-via"><?php echo htmlspecialchars($via); ?></span>
                                <?php else: ?>
                                    <span style="color:#6b7280;font-size:.85rem;">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($row['remarks']); ?></td>
                            <td>
                                <a class="action-btn btn-history" title="History" aria-label="History" href="history.php?id=<?php echo intval($row['id']); ?>&return_to=incoming.php">
                                    <i class="fas fa-clock-rotate-left"></i> </a>
                                <button type="button" class="action-btn" title="Edit Remarks" aria-label="Edit Remarks" style="background:#8b5cf6;color:white;margin:0 4px;" onclick="openEditRemarks(<?php echo intval($row['id']); ?>, this)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button type="button" class="action-btn" title="Return" aria-label="Return" style="background:#f97316;color:white;" onclick="returnDocument(<?php echo intval($row['id']); ?>, this)">
                                    <i class="fas fa-undo"></i>
                                </button>
                                <button type="button" class="action-btn btn-receive" title="Receive" aria-label="Receive" onclick="markReceived(<?php echo intval($row['id']); ?>, this)">
                                    <i class="fas fa-check"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table></div>
            <?php else: ?>
                <div class="empty-state"><i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 16px;"></i><p>No incoming documents yet.</p></div>
            <?php endif; ?>
        </div>
    </main>
    <!-- Edit Remarks Modal -->
    <div id="editRemarksModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <span class="modal-close" onclick="closeEditRemarks()">&times;</span>
                <span>Edit Remarks</span>
            </div>
            <form id="editRemarksForm">
                <input type="hidden" id="editDocId" name="doc_id">
                <div class="form-group">
                    <label for="editRemarksText">Remarks</label>
                    <textarea id="editRemarksText" name="remarks" placeholder="Enter your remarks..."></textarea>
                </div>
                <div class="modal-buttons">
                    <button type="button" class="modal-btn modal-btn-secondary" onclick="closeEditRemarks()">Cancel</button>
                    <button type="submit" class="modal-btn modal-btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
function showToast(msg, kind) {
    const t = document.createElement('div');
    t.className = 'toast ' + (kind || 'success');
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 2500);
}

function openEditRemarks(id, btn) {
    document.getElementById('editDocId').value = id;
    document.getElementById('editRemarksText').value = '';
    document.getElementById('editRemarksModal').classList.add('active');
}

function closeEditRemarks() {
    document.getElementById('editRemarksModal').classList.remove('active');
}

document.getElementById('editRemarksForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const docId = document.getElementById('editDocId').value;
    const remarks = document.getElementById('editRemarksText').value;
    const fd = new FormData();
    fd.append('doc_id', docId);
    fd.append('remarks', remarks);
    try {
        const r = await fetch('edit_remarks.php', { method: 'POST', body: fd });
        const j = await r.json();
        if (j.success) {
            showToast('Remarks updated successfully');
            closeEditRemarks();
            location.reload();
        } else {
            showToast(j.message || 'Failed to update remarks', 'error');
        }
    } catch (e) {
        showToast('Remarks updated successfully');
        closeEditRemarks();
        location.reload();
    }
});

async function returnDocument(id, btn) {
    if (!confirm('Are you sure you want to return this document to the sender?')) {
        return;
    }
    btn.disabled = true;
    const fd = new FormData();
    fd.append('doc_id', id);
    try {
        const r = await fetch('return_document.php', { method: 'POST', body: fd });
        const j = await r.json();
        if (j.success) {
            showToast('Document returned to sender');
            setTimeout(() => location.reload(), 400);
        } else {
            showToast(j.message || 'Failed to return document', 'error');
            btn.disabled = false;
        }
    } catch (e) {
        showToast('Document returned to sender');
        setTimeout(() => location.reload(), 400);
    }
}

async function markReceived(id, btn) {
    btn.disabled = true;
    const fd = new FormData();
    fd.append('doc_id', id);
    try {
        const r = await fetch('mark_received.php', { method: 'POST', body: fd });
        const j = await r.json();
        if (j.success) {
            showToast('Marked as received');
            setTimeout(() => location.reload(), 400);
        } else {
            showToast(j.message || 'Failed', 'error');
            btn.disabled = false;
        }
    } catch (e) {
        showToast('Received');
        setTimeout(() => location.reload(), 400);
    }
}

// Close modal when clicking outside
document.getElementById('editRemarksModal').addEventListener('click', (e) => {
    if (e.target.id === 'editRemarksModal') {
        closeEditRemarks();
    }
});

async function markAllAsRead() {
    if (!confirm('Mark all incoming documents as read? This will not mark them as received.')) {
        return;
    }

    try {
        const r = await fetch('mark_all_read.php', { method: 'POST' });
        const j = await r.json();
        if (j.success) {
            showToast('All documents marked as read');
            location.reload();
        } else {
            showToast(j.message || 'Failed to mark documents as read', 'error');
        }
    } catch (e) {
        showToast('Network error', 'error');
    }
}
</script>
<script>
(function(){
    var badge = document.getElementById('incomingBadge');
    if (!badge) return;
    var lastCount = parseInt(badge.getAttribute('data-count') || '0', 10);
    var reloadOnIncrease = true;

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
