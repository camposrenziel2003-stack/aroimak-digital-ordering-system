<?php
include "../config.php";
header("Content-Type: application/json");

$groupId = $_GET["id"] ?? "";

if (!$groupId) {
    echo json_encode(["success" => false, "message" => "Missing order ID"]);
    exit;
}

// Kunin order info
$sqlOrder = "SELECT customer_name, table_number, allergens, allergen_note, spice_level, status, payment_method, total_price, created_at
             FROM orders WHERE order_group_id = ?";
$stmt = $conn->prepare($sqlOrder);
$stmt->bind_param("s", $groupId);
$stmt->execute();
$result = $stmt->get_result();
$order = $result->fetch_assoc();
$stmt->close();

if (!$order) {
    echo json_encode(["success" => false, "message" => "Order not found"]);
    exit;
}

// Kunin order items
$sqlItems = "SELECT item_name, quantity, price, total 
             FROM order_items WHERE order_group_id = ?";
$stmt = $conn->prepare($sqlItems);
$stmt->bind_param("s", $groupId);
$stmt->execute();
$result = $stmt->get_result();
$items = [];
while ($row = $result->fetch_assoc()) {
    $items[] = $row;
}
$stmt->close();

echo json_encode([
    "success" => true,
    "order" => $order,
    "items" => $items
]);
?>
