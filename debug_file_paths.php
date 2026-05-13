<?php
$c = new mysqli('localhost', 'root', '', 'routing');
if ($c->connect_error) {
    echo "connection failed: " . $c->connect_error;
    exit;
}
$q = "SELECT id, route_state, file_path, doc_name, receiver_name, sender_name, title FROM documents WHERE route_state IN ('Incoming','Outgoing','Pending') ORDER BY id DESC LIMIT 20";
$r = $c->query($q);
if (!$r) {
    echo "query failed: " . $c->error;
    exit;
}
while ($row = $r->fetch_assoc()) {
    echo json_encode($row) . "\n";
}
$c->close();
?>