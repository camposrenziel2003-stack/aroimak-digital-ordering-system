<?php
include "../config.php";
error_log("Tablet requested table number, IP: $ip");
$stmt = $conn->prepare("SELECT table_number FROM tablets WHERE ip_address=?");
$stmt->bind_param("s", $ip);
$stmt->execute();
$stmt->bind_result($table_number);
$stmt->fetch();
$ip = $_SERVER['REMOTE_ADDR'];
echo json_encode(['ip' => $ip]);
exit;
?>