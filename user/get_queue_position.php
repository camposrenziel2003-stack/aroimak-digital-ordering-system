<?php
session_start();
include "config.php";

$orderGroupId = $_GET['order_number'] ?? '';

if (!$orderGroupId) {
    echo json_encode(['success' => false, 'message' => 'Missing order reference.']);
    exit;
}

// Get created_at and status for the order
$stmt = $conn->prepare("SELECT created_at, status, queue_number FROM orders WHERE order_group_id = ? LIMIT 1");
$stmt->bind_param("s", $orderGroupId);
$stmt->execute();
$res = $stmt->get_result();
$order = $res->fetch_assoc();
$stmt->close();

if (!$order) {
    echo json_encode(['success' => false, 'message' => 'Order not found.']);
    exit;
}

$orderCreatedAt = $order['created_at'];
$orderStatus = strtolower($order['status']);
$queueNumber = $order['queue_number'];
$today = date('Y-m-d');

// If this order is canceled, completed, or served, remove from queue by hiding queue number
if (
    strpos($orderStatus, 'canceled') !== false ||
    strpos($orderStatus, 'cancelled') !== false ||
    strpos($orderStatus, 'completed') !== false ||
    strpos($orderStatus, 'served') !== false
) {
    echo json_encode([
        'success' => true,
        'queue_position' => "-",
        'status' => $order['status']
    ]);
    exit;
}

// Calculate queue position among active orders ONLY
$stmt2 = $conn->prepare(
    "SELECT COUNT(*) AS ahead_in_queue
     FROM orders
     WHERE DATE(created_at) = ?
     AND created_at < ?
     AND LOWER(status) NOT LIKE '%completed%'
     AND LOWER(status) NOT LIKE '%served%'
     AND LOWER(status) NOT LIKE '%canceled%'
     AND LOWER(status) NOT LIKE '%cancelled%'"
);
$stmt2->bind_param("ss", $today, $orderCreatedAt);
$stmt2->execute();
$res2 = $stmt2->get_result();
$data = $res2->fetch_assoc();
$stmt2->close();

$currentQueuePosition = isset($data['ahead_in_queue']) ? intval($data['ahead_in_queue']) + 1 : 1;

// Active order: show decreasing queue position!
echo json_encode([
    'success' => true,
    'queue_position' => $currentQueuePosition,
    'status' => $order['status']
]);
?>