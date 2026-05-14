<?php
// get_order_status.php
// Returns JSON:
// {
//   status: "success",
//   order_status: "...",
//   updated_at_unix: 170...,
//   server_now_unix: 170...,
//   cancellable: true|false,
//   cancel_reason: "...",
//   allow_additional: true|false,
//   paid: 0|1
// }

header('Content-Type: application/json; charset=utf-8');
session_start();

include "config.php";
include "access_check.php"; // optional: include if you need auth

$orderGroupId = $_GET['id'] ?? '';
if (!$orderGroupId) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing order id.']);
    exit;
}

$stmt = $conn->prepare("SELECT status, updated_at, UNIX_TIMESTAMP(updated_at) AS updated_unix, paid FROM orders WHERE order_group_id = ? LIMIT 1");
if (! $stmt) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database prepare failed.']);
    exit;
}
$stmt->bind_param("s", $orderGroupId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Order not found.']);
    exit;
}

// Normalize and compute cancellable using same rules as cancellation endpoint
$currentStatus = strtolower($order['status'] ?? '');
$updatedAtUnix = isset($order['updated_unix']) && $order['updated_unix'] !== null
    ? (int)$order['updated_unix']
    : (isset($order['updated_at']) ? strtotime($order['updated_at']) : time());
$now = time();

$cancellable = false;
$cancelReason = '';

if (in_array($currentStatus, ['pending', 'not send to kitchen'])) {
    $cancellable = true;
} elseif ($currentStatus === 'preparing') {
    $elapsed = $now - $updatedAtUnix;
    if ($elapsed < 60) {
        $cancellable = true;
    } else {
        $cancellable = false;
        $cancelReason = 'Preparing more than 3 minutes: cancellation not allowed.';
    }
} else {
    $cancellable = false;
    $cancelReason = 'Cannot cancel order in status: ' . $order['status'];
}

// Determine allow_additional: only when order is NOT paid and not cancelled/closed
$paidFlag = isset($order['paid']) && intval($order['paid']) === 1;
$allow_additional = (!$paidFlag) && !in_array($currentStatus, ['canceled', 'cancelled', 'closed']);

// Return JSON (authoritative)
$response = [
    'status' => 'success',
    'order_status' => $order['status'],
    'updated_at_unix' => $updatedAtUnix,
    'server_now_unix' => $now,
    'cancellable' => $cancellable,
    'cancel_reason' => $cancelReason,
    'allow_additional' => $allow_additional,
    'paid' => $paidFlag ? 1 : 0
];

echo json_encode($response);

$conn->close();
exit;