<?php
header("Content-Type: application/json");
include "../config.php"; // DB connection

$data = json_decode(file_get_contents("php://input"), true);

if (!$data || empty($data["order_group_id"])) {
    echo json_encode(["success" => false, "message" => "Missing order_group_id"]);
    exit;
}

$orderGroupId = $data["order_group_id"];

// Delete items first (if order_items table exists)
$conn->query("DELETE FROM order_items WHERE order_group_id = '" . $conn->real_escape_string($orderGroupId) . "'");

// Delete order group
$stmt = $conn->prepare("DELETE FROM orders WHERE order_group_id = ?");
if (!$stmt) {
    echo json_encode(["success" => false, "message" => "SQL error: " . $conn->error]);
    exit;
}

$stmt->bind_param("s", $orderGroupId);
$success = $stmt->execute();
$stmt->close();

echo json_encode([
    "success" => $success
]);
?>