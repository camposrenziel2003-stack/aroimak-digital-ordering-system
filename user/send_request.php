<?php
session_start();
include "config.php";

header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);

$order_group_id = $data['order_group_id'] ?? "";
$request_type   = $data['request_type'] ?? "";
$customer_name  = $data['customer_name'] ?? "";
$assistance_request = $data['assistance_request'] ?? "";

// Validate basic fields
if (empty($request_type)) {
    echo json_encode(["status" => "error", "message" => "Missing request type"]);
    exit;
}

$table_number = "";

// If order_group_id is given and not empty, try to fetch info from orders table
if (!empty($order_group_id)) {
    $stmt = $conn->prepare("SELECT customer_name, table_number FROM orders WHERE order_group_id = ? LIMIT 1");
    $stmt->bind_param("s", $order_group_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $order = $res->fetch_assoc();
    $stmt->close();

    if ($order) {
        $customer_name = $order['customer_name'];
        $table_number  = $order['table_number'];
    }
}

// If table_number still not found, get it from tablets table via device IP
if (empty($table_number)) {
    $device_ip = $_SERVER['REMOTE_ADDR'];
    $stmt2 = $conn->prepare("SELECT table_number FROM tablets WHERE ip_address = ? LIMIT 1");
    $stmt2->bind_param("s", $device_ip);
    $stmt2->execute();
    $stmt2->bind_result($found_table_number);
    if ($stmt2->fetch()) {
        $table_number = $found_table_number;
    }
    $stmt2->close();
}

if (empty($table_number)) {
    echo json_encode(["status" => "error", "message" => "Unable to identify table number"]);
    $conn->close();
    exit;
}

// Insert into order_requests (with assistance_request if present)
$stmtInsert = $conn->prepare("
    INSERT INTO order_requests (order_group_id, table_number, customer_name, request_type, status, created_at, assistance_request)
    VALUES (?, ?, ?, ?, 'Pending', NOW(), ?)
");
$stmtInsert->bind_param("sssss", $order_group_id, $table_number, $customer_name, $request_type, $assistance_request);

if ($stmtInsert->execute()) {
    echo json_encode(["status" => "success", "message" => "Request submitted"]);
} else {
    echo json_encode(["status" => "error", "message" => "Failed to save request"]);
}
$stmtInsert->close();
$conn->close();