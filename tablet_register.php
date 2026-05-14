<?php
include "../config.php";
$input = json_decode(file_get_contents("php://input"), true);
$ip = $input['ip_address'] ?? $_SERVER['REMOTE_ADDR'];

if ($ip) {
    // Insert or update tablet record
    $stmt = $conn->prepare("INSERT INTO tablets (ip_address, table_number) VALUES (?, 0) ON DUPLICATE KEY UPDATE last_seen=NOW()");
    $stmt->bind_param("s", $ip);
    $stmt->execute();
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'No IP']);
}
?>