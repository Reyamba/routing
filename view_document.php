<?php
session_start();
if (!isset($_SESSION['username'])) {
    header('Location: index.php');
    exit;
}

// File viewing/downloading is no longer supported — uploads have been
// removed system-wide. Redirect users back to incoming with a notice.
header('Location: incoming.php?status=files_disabled');
exit;
