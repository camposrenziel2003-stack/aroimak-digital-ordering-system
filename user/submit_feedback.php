<?php
header('Content-Type: application/json');
include "config.php";

// Read JSON input
$data = json_decode(file_get_contents('php://input'), true);

$order_group_id = $data['order_number'] ?? '';
$rating         = $data['rating'] ?? '';
$comment        = $data['comment'] ?? '';

if (!$order_group_id || !$rating) {
    echo json_encode(['status'=>'error','message'=>'Order ID and rating are required.']);
    exit;
}

// Fetch customer_name from orders
$stmt = $conn->prepare("SELECT customer_name FROM orders WHERE order_group_id = ? LIMIT 1");
$stmt->bind_param("s", $order_group_id);
$stmt->execute();
$res = $stmt->get_result();
$order = $res->fetch_assoc();
$stmt->close();

$customer_name = $order['customer_name'] ?? 'Guest'; // default if not found

// Insert feedback into DB
$stmt = $conn->prepare("
    INSERT INTO feedback (order_group_id, rating, comment, customer_name, created_at)
    VALUES (?, ?, ?, ?, NOW())
");
$stmt->bind_param("siss", $order_group_id, $rating, $comment, $customer_name);

if ($stmt->execute()) {
    echo json_encode(['status'=>'success','message'=>'Feedback submitted.']);
} else {
    echo json_encode(['status'=>'error','message'=>'Failed to submit feedback.']);
}

$stmt->close();
$conn->close();
