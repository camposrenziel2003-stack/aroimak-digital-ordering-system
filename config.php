<?php
$host = "localhost";
$user = "root";
$pass = "";
$db   = "digital_menu";
date_default_timezone_set('Asia/Manila');

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
