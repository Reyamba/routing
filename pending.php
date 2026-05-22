<?php
session_start();
if (!isset($_SESSION['username'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/config.php';
$statusMessage = '';
$statusType = 'success';
if (isset($_GET['status'])) {
    switch ($_GET['status']) {
        case 'upload_success': $statusMessage = 'Document submitted to pending successfully.'; break;
        case 'send_success':   $statusMessage = 'Document sent successfully.'; break;
        case 'update_success': $statusMessage = 'Pending document updated successfully.'; break;
        case 'upload_error':   $statusMessage = 'Submission failed. Please try again.'; $statusType = 'danger'; break;
        case 'send_error':     $statusMessage = 'Unable to send document. Please try again.'; $statusType = 'danger'; break;
        case 'update_error':   $statusMessage = 'Unable to update document. Please try again.'; $statusType = 'danger'; break;
    }
}

$result = $conn->query("SHOW COLUMNS FROM documents LIKE 'route_state'");
if ($result && $result->num_rows === 0) {
    $conn->query("ALTER TABLE documents ADD COLUMN route_state VARCHAR(32) NOT NULL DEFAULT 'Pending'");
}
$result = $conn->query("SHOW COLUMNS FROM documents LIKE 'submitted_via'");
if ($result && $result->num_rows === 0) {
    $conn->query("ALTER TABLE documents ADD COLUMN submitted_via VARCHAR(64) NULL");
}

$currentUser = $_SESSION['username'];
$stmt = $conn->prepare("SELECT * FROM documents WHERE route_state = 'Pending' AND owner = ? ORDER BY created_at DESC");
$stmt->bind_param('s', $currentUser);
$stmt->execute();
$pendingDocuments = $stmt->get_result();
$stmt->close();
$usersResult = $conn->query("SELECT username FROM users ORDER BY username ASC");
$users = [];
if ($usersResult && $usersResult->num_rows > 0) {
    while ($user = $usersResult->fetch_assoc()) { $users[] = $user['username']; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Documents</title>
    <link rel="shortcut icon" type="image/x-icon" href="logo.png" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary-color:#0012b0; --bg-light:#f9fafb; --text-main:#111827; --text-muted:#6b7280; --border-color:#e5e7eb; --success-color:#10b981; --danger-color:#ef4444; }
        * { box-sizing:border-box; margin:0; padding:0; font-family:'Inter',sans-serif; }
        body { background:var(--bg-light); color:var(--text-main); min-height:100vh; }
        .dashboard-container { display:flex; min-height:100vh; }
        .sidebar { width:260px; background:white; border-right:1px solid var(--border-color); padding:24px; display:flex; flex-direction:column; position:fixed; height:100vh; left:0; top:0; overflow-y:auto; }
        .logo-section { display:flex; align-items:center; gap:12px; margin-bottom:32px; font-weight:700; color:var(--primary-color); font-size:1.1rem; }
        .sidebar-menu { display:flex; flex-direction:column; gap:8px; }
        .menu-item { display:flex; align-items:center; gap:12px; padding:12px 16px; border-radius:8px; text-decoration:none; color:var(--text-muted); font-weight:500; }
        .menu-item.active, .menu-item:hover { background:rgba(0,18,176,.08); color:var(--primary-color); }
        .main-content { margin-left:260px; flex:1; padding:32px; }
        .header { display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; }
        .header h1 { font-size:1.75rem; }
        .action-btn { display:inline-flex; align-items:center; gap:8px; padding:12px 18px; border-radius:8px; background:var(--primary-color); color:white; border:none; cursor:pointer; text-decoration:none; }
        .page-alert { margin-bottom:20px; padding:14px 18px; border-radius:12px; font-weight:600; }
        .page-alert.success { background:#ecfdf5; color:#065f46; border:1px solid #d1fae5; }
        .page-alert.danger { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
        .content-card { background:white; border-radius:16px; box-shadow:0 1px 3px rgba(0,0,0,.08); padding:24px; }
        table { width:100%; border-collapse:collapse; }
        th, td { padding:14px 16px; border-bottom:1px solid var(--border-color); text-align:left; }
        th { font-size:.82rem; text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted); }
        .icon-btn { background:transparent; border:none; cursor:pointer; padding:6px; font-size:1rem; }
        .icon-btn.send { color: #16a34a; }
        .modal { display:none; position:fixed; inset:0; background:rgba(15,23,42,.5); justify-content:center; align-items:center; padding:24px; z-index:1000; }
        .modal-content { width:100%; max-width:680px; background:white; border-radius:18px; padding:24px; }
        .modal-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; }
        .modal-close { border:none; background:transparent; font-size:1.25rem; cursor:pointer; color:var(--text-muted); }
        .form-group { display:flex; flex-direction:column; gap:8px; margin-bottom:16px; }
        .form-row { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:18px; }
        .form-group label { font-weight:600; color:#475569; }
        .form-group input, .form-group select, .form-group textarea { border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px; font-size:.95rem; }
        .form-group textarea { min-height:100px; resize:vertical; }
        .form-actions { display:flex; justify-content:flex-end; gap:12px; margin-top:12px; }
        .form-actions button { border:none; border-radius:10px; padding:12px 18px; cursor:pointer; font-weight:700; }
        .btn-cancel { background:#f3f4f6; color:#374151; }
        .btn-submit { background:var(--primary-color); color:white; }
        .empty-state { padding:44px; text-align:center; color:var(--text-muted); }
        .toast { position:fixed; top:16px; right:16px; padding:12px 16px; border-radius:10px; color:white; font-weight:600; z-index:2000; }
        .toast.success { background:var(--success-color); } .toast.error { background:var(--danger-color); }
        .badge-via { display:inline-block; padding:4px 10px; border-radius:999px; font-size:.78rem; font-weight:600; background:#eef2ff; color:#3730a3; }
    </style>
</head>
<body>
<div class="dashboard-container">
    <div class="sidebar">
        <div class="logo-section"><i class="fas fa-file-alt"></i><span>PSA ROUTING</span></div>
        <nav class="sidebar-menu">
            <a href="dashboard.php" class="menu-item"><i class="fas fa-home"></i><span>Dashboard</span></a>
            <a href="incoming.php" class="menu-item"><i class="fas fa-arrow-down"></i><span>Incoming</span></a>
            <a href="outgoing.php" class="menu-item"><i class="fas fa-arrow-up"></i><span>Outgoing</span></a>
            <a href="pending.php" class="menu-item active"><i class="fas fa-clock"></i><span>Pending</span></a>
        </nav>
    </div>
    <main class="main-content">
        <div class="header">
            <h1>Pending Documents</h1>
            <button type="button" class="action-btn" onclick="openUploadModal()">
                <i class="fas fa-plus"></i> New Document
            </button>
        </div>
        <?php if ($statusMessage): ?>
            <div class="page-alert <?php echo $statusType; ?>"><?php echo htmlspecialchars($statusMessage); ?></div>
        <?php endif; ?>
        <div class="content-card">
            <?php if ($pendingDocuments && $pendingDocuments->num_rows > 0): ?>
                <table id="documentsTable">
                    <thead><tr>
                        <th>Title</th><th>Status</th><th>Date</th><th>Submitted Via</th><th>Remarks</th><th>Route State</th><th>Send</th>
                    </tr></thead>
                    <tbody>
                    <?php while ($row = $pendingDocuments->fetch_assoc()):
                        $rowJson = htmlspecialchars(json_encode([
                            'id' => intval($row['id']),
                            'title' => $row['title'],
                            'status' => $row['status'],
                            'receiver_name' => $row['receiver_name'] ?? '',
                            'remarks' => $row['remarks'] ?? '',
                            'submitted_via' => $row['submitted_via'] ?? '',
                        ]), ENT_QUOTES, 'UTF-8');
                        $via = $row['submitted_via'] ?? '';
                    ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['title']); ?></td>
                            <td><?php echo htmlspecialchars($row['status']); ?></td>
                            <td><?php echo htmlspecialchars($row['created_at']); ?></td>
                            <td><?php echo $via ? '<span class="badge-via">'.htmlspecialchars($via).'</span>' : '<span style="color:#6b7280;font-size:.85rem;">—</span>'; ?></td>
                            <td><?php echo htmlspecialchars($row['remarks']); ?></td>
                            <td><?php echo htmlspecialchars($row['route_state'] ?? 'Pending'); ?></td>
                            <td>
                                <button type="button" class="icon-btn send" title="Send" data-doc='<?php echo $rowJson; ?>' onclick="openSendModal(this)"><i class="fas fa-paper-plane"></i></button>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state"><i class="fas fa-clock" style="font-size:3rem; margin-bottom:16px;"></i><p>No pending documents yet.</p></div>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- Send Modal -->
<div id="sendModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Send Document</h3>
            <button class="modal-close" onclick="closeSendModal()"><i class="fas fa-times"></i></button>
        </div>
        <form id="sendForm">
            <input type="hidden" id="sendDocId" name="doc_id">
            <div class="form-group">
                <label for="sendTitle">Document Title</label>
                <input type="text" id="sendTitle" name="title" required>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="sendStatus">Status</label>
                    <select id="sendStatus" name="status" required>
                        <option value="" disabled>Select status</option>
                        <option value="For Appropriate Action">For Appropriate Action</option>
                        <option value="For Comments">For Comments</option>
                        <option value="For Consideration">For Consideration</option>
                        <option value="For Initial/Signature">For Initial/Signature</option>
                        <option value="For Information/File">For Information/File</option>
                        <option value="For Drafting of Reply">For Drafting of Reply</option>
                        <option value="Specify">Specify</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="sendReceiverName">Receiver</label>
                    <select id="sendReceiverName" name="receiver_name" required>
                        <option value="" disabled selected>Select registered receiver</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?php echo htmlspecialchars($u); ?>"><?php echo htmlspecialchars($u); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label for="sendRemarks">Remarks</label>
                <textarea id="sendRemarks" name="remarks"></textarea>
            </div>
            <div class="form-actions">
                <button type="button" class="btn-cancel" onclick="closeSendModal()">Cancel</button>
                <button type="submit" class="btn-submit">Send Document</button>
            </div>
        </form>
    </div>
</div>

<!-- New Document Modal (no file upload) -->
<div id="uploadModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>New Document</h3>
            <button class="modal-close" onclick="closeUploadModal()"><i class="fas fa-times"></i></button>
        </div>
        <form id="uploadForm">
            <div class="form-group">
                <label for="upTitle">Title</label>
                <input type="text" id="upTitle" name="title" required placeholder="Document title">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="upSubmittedVia">Submitted Via</label>
                    <select id="upSubmittedVia" name="submitted_via" required>
                        <option value="" disabled selected>Select submission channel</option>
                        <option value="Official PSA Email">Official PSA Email</option>
                        <option value="Hardcopy">Hardcopy</option>
                        <option value="Facebook Messenger">Facebook Messenger</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="upStatus">Status</label>
                    <select id="upStatus" name="status">
                        <option value="For Appropriate Action">For Appropriate Action</option>
                        <option value="For Comments">For Comments</option>
                        <option value="For Consideration">For Consideration</option>
                        <option value="For Initial/Signature">For Initial/Signature</option>
                        <option value="For Information/File">For Information/File</option>
                        <option value="For Drafting of Reply">For Drafting of Reply</option>
                        <option value="Specify">Specify</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label for="upReceiver">Receiver (optional)</label>
                <select id="upReceiver" name="receiver">
                    <option value="">— None —</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?php echo htmlspecialchars($u); ?>"><?php echo htmlspecialchars($u); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="upRemarks">Remarks</label>
                <textarea id="upRemarks" name="remarks"></textarea>
            </div>
            <div class="form-actions">
                <button type="button" class="btn-cancel" onclick="closeUploadModal()">Cancel</button>
                <button type="submit" class="btn-submit">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
function openSendModal(btn) {
    const data = JSON.parse(btn.getAttribute('data-doc'));
    document.getElementById('sendDocId').value = data.id;
    document.getElementById('sendTitle').value = data.title || '';
    document.getElementById('sendStatus').value = data.status || '';
    document.getElementById('sendReceiverName').value = data.receiver_name || '';
    document.getElementById('sendRemarks').value = data.remarks || '';
    document.getElementById('sendModal').style.display = 'flex';
}
function closeSendModal() { document.getElementById('sendModal').style.display = 'none'; }
function showToast(msg, kind) {
    const t = document.createElement('div');
    t.className = 'toast ' + (kind || 'success'); t.textContent = msg;
    document.body.appendChild(t); setTimeout(() => t.remove(), 2500);
}
document.getElementById('sendForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    if (!fd.get('doc_id') || !fd.get('receiver_name') || !fd.get('title') || !fd.get('status')) {
        showToast('Please fill in title, status, and receiver.', 'error');
        return;
    }
    try {
        const r = await fetch('send_document.php', { method: 'POST', body: fd });
        const j = await r.json();
        if (j.success) {
            closeSendModal();
            window.location.href = 'pending.php?status=send_success';
        } else {
            showToast(j.message || 'Send failed', 'error');
        }
    } catch (err) {
        showToast('Network error', 'error');
    }
});

function openUploadModal() { document.getElementById('uploadModal').style.display = 'flex'; }
function closeUploadModal() { document.getElementById('uploadModal').style.display = 'none'; }

document.getElementById('uploadForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    if (!fd.get('title') || !fd.get('submitted_via')) {
        showToast('Title and Submitted Via are required.', 'error');
        return;
    }
    try {
        const r = await fetch('upload_documents.php', { method: 'POST', body: fd });
        const j = await r.json();
        if (j.success) {
            closeUploadModal();
            window.location.href = 'pending.php?status=upload_success';
        } else {
            showToast(j.message || 'Save failed', 'error');
        }
    } catch (err) {
        showToast('Network error', 'error');
    }
});
</script>
</body>
</html>
