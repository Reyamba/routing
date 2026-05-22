<?php
session_start();
if (!isset($_SESSION['username'])) {
    header('Location: index.php');
    exit;
}
require_once __DIR__ . '/db_helpers.php';
ensure_schema($conn);

// Ensure submitted_via column exists
$colCheck = $conn->query("SHOW COLUMNS FROM documents LIKE 'submitted_via'");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE documents ADD COLUMN submitted_via VARCHAR(64) NULL");
}

$docId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$stmt = $conn->prepare('SELECT * FROM documents WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $docId);
$stmt->execute();
$doc = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$doc) {
    header('Location: dashboard.php');
    exit;
}

// Auto-mark this document as read when viewing its history
$readCheck = $conn->query("SHOW COLUMNS FROM documents LIKE 'is_read'");
if ($readCheck && $readCheck->num_rows > 0 && intval($doc['is_read'] ?? 0) === 0) {
    $markStmt = $conn->prepare('UPDATE documents SET is_read = 1 WHERE id = ?');
    $markStmt->bind_param('i', $docId);
    $markStmt->execute();
    $markStmt->close();
    $doc['is_read'] = 1;
}

$allowedReturnPages = ['dashboard.php', 'incoming.php', 'outgoing.php'];
$returnTo = isset($_GET['return_to']) ? basename($_GET['return_to']) : '';
if (!in_array($returnTo, $allowedReturnPages, true)) {
    $returnTo = '';
}
if (!$returnTo && !empty($_SERVER['HTTP_REFERER'])) {
    $refPath = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH);
    $refBase = basename($refPath);
    if (in_array($refBase, $allowedReturnPages, true)) {
        $returnTo = $refBase;
    }
}
if (!$returnTo) {
    $returnTo = 'dashboard.php';
}

$ids = [intval($doc['id'])];

$find = $conn->prepare("SELECT id FROM documents WHERE title = ?");
$t = $doc['title'];
$find->bind_param('s', $t);
$find->execute();
$res = $find->get_result();
while ($row = $res->fetch_assoc()) { $ids[] = intval($row['id']); }
$find->close();

$hasParent = false;
$colCheck = $conn->query("SHOW COLUMNS FROM documents LIKE 'parent_id'");
if ($colCheck && $colCheck->num_rows > 0) { $hasParent = true; }
if ($hasParent) {
    $seen = [];
    $queue = $ids;
    while (!empty($queue)) {
        $cur = array_shift($queue);
        if (isset($seen[$cur])) continue;
        $seen[$cur] = true;
        $stmtP = $conn->prepare("SELECT id, parent_id FROM documents WHERE id = ? OR parent_id = ?");
        $stmtP->bind_param('ii', $cur, $cur);
        $stmtP->execute();
        $rp = $stmtP->get_result();
        while ($row = $rp->fetch_assoc()) {
            $rid = intval($row['id']);
            $ids[] = $rid;
            if (!isset($seen[$rid])) $queue[] = $rid;
            if (!empty($row['parent_id'])) {
                $pid = intval($row['parent_id']);
                $ids[] = $pid;
                if (!isset($seen[$pid])) $queue[] = $pid;
            }
        }
        $stmtP->close();
    }
}

$ids = array_values(array_unique(array_map('intval', $ids)));
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$types = str_repeat('i', count($ids));
$sql = "SELECT * FROM document_history WHERE document_id IN ($placeholders) ORDER BY created_at ASC, id ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$ids);
$stmt->execute();
$history = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Document History</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
<style>
:root { --primary-color:#0012b0; --bg:#f9fafb; --text:#111827; --muted:#6b7280; --border:#e5e7eb; }
* { box-sizing:border-box; margin:0; padding:0; font-family:'Inter',sans-serif; }
body { background:var(--bg); color:var(--text); padding:32px; }
.container { max-width:880px; margin:0 auto; }
h1 { font-size:1.6rem; margin-bottom:8px; }
.sub { color:var(--muted); margin-bottom:24px; }
.card { background:white; border-radius:14px; padding:24px; box-shadow:0 1px 3px rgba(0,0,0,.06); }
.meta { display:grid; grid-template-columns:repeat(2,1fr); gap:12px; margin-bottom:24px; }
.meta div span { display:block; font-size:.75rem; color:var(--muted); text-transform:uppercase; letter-spacing:.05em; }
.meta div strong { font-weight:600; }
.timeline { position:relative; padding-left:28px; }
.timeline::before { content:''; position:absolute; left:10px; top:6px; bottom:6px; width:2px; background:var(--border); }
.event { position:relative; padding:12px 0 18px; }
.event::before { content:''; position:absolute; left:-22px; top:18px; width:14px; height:14px; border-radius:50%; background:var(--primary-color); border:3px solid white; box-shadow:0 0 0 1px var(--primary-color); }
.event h4 { font-size:.95rem; margin-bottom:4px; }
.event p { color:var(--muted); font-size:.85rem; }
.event time { color:var(--muted); font-size:.78rem; }
.back { display:inline-flex; align-items:center; gap:8px; color:var(--primary-color); text-decoration:none; margin-bottom:16px; font-weight:600; }
.badge-via { display:inline-block; padding:3px 10px; border-radius:999px; font-size:.78rem; font-weight:800; background:#eef2ff; color:#3730a3; }
</style>
</head>
<body>
<div class="container">
  <a class="back" href="<?php echo htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-arrow-left"></i> Back</a>
  <div class="card">
    <h1><?php echo htmlspecialchars($doc['title']); ?></h1>
    <div class="sub">Document #<?php echo intval($doc['id']); ?> — current state: <strong><?php echo htmlspecialchars($doc['route_state']); ?></strong></div>
    <div class="meta">
      <div><span>Sender</span><strong><?php echo htmlspecialchars($doc['sender_name'] ?? '—'); ?></strong></div>
      <div><span>Receiver</span><strong><?php echo htmlspecialchars($doc['receiver_name'] ?? '—'); ?></strong></div>
      <div><span>Status</span><strong><?php echo htmlspecialchars($doc['status']); ?></strong></div>
      <div><span>Submitted Via</span><strong><?php echo !empty($doc['submitted_via']) ? '<span class="badge-via">'.htmlspecialchars($doc['submitted_via']).'</span>' : '—'; ?></strong></div>
      <div><span>Received At</span><strong><?php echo htmlspecialchars($doc['date_time_receiving'] ?? '—'); ?></strong></div>
    </div>
    <h3 style="margin-bottom:12px;">Routing history</h3>
    <div class="timeline">
      <?php if ($history && $history->num_rows > 0): ?>
        <?php
        $iconMap = [
          'created'   => 'fa-file-circle-plus',
          'sent'      => 'fa-paper-plane',
          'forwarded' => 'fa-share',
          'forward'   => 'fa-share',
          'received'  => 'fa-inbox',
          'returned'  => 'fa-rotate-left',
          'return'    => 'fa-rotate-left',
          'edited'    => 'fa-pen',
          'remarksupdated' => 'fa-comment-dots',
        ];
        while ($h = $history->fetch_assoc()):
          $actKey = strtolower(str_replace([' ', '_'], '', $h['action']));
          // Skip legacy file-replacement events since file uploads are removed
          if ($actKey === 'filereplaced') continue;
          $icon = $iconMap[$actKey] ?? 'fa-circle-info';
        ?>
          <?php
            $noteText = $h['note'] ?? ($h['notes'] ?? '');
            $actorText = $h['actor_name'] ?? ($h['actor'] ?? '—');
          ?>
          <div class="event">
            <h4><i class="fas <?php echo $icon; ?>" style="color:var(--primary-color);margin-right:6px;"></i>
              <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $h['action']))); ?>
            </h4>
            <?php if (!empty($noteText)): ?><p><?php echo htmlspecialchars($noteText); ?></p><?php endif; ?>
            <time>By <strong><?php echo htmlspecialchars($actorText); ?></strong> · <?php echo htmlspecialchars($h['created_at']); ?></time>
          </div>
        <?php endwhile; ?>
      <?php else: ?>
        <p style="color:var(--muted);">No history yet.</p>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
