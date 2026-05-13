<?php
require 'config.php';
$hash = password_hash('test', PASSWORD_DEFAULT);
$conn->query("INSERT INTO users (username, password) VALUES ('testuser', '$hash')");
echo "User testuser created with password 'test'\n";
?>