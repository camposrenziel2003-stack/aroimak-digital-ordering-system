<?php
// Server-Sent Events endpoint to push order status + payment updates in real-time.
// Usage: status_sse.php?id={ORDER_GROUP_ID}
set_time_limit(0);
ignore_user_abort(false);
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no'); // If using nginx, try to disable buffering

include "config.php";

$orderGroupId = $_GET['id'] ?? '';
if (!$orderGroupId) {
    echo "event: error\n";
    echo 'data: {"status":"error","message":"Missing order ID"}' . "\n\n";
    flush();
    exit;
}

$lastPayload = null;
$heartbeatInterval = 15; // seconds
$checkInterval = 2; // seconds
$elapsedSinceHeartbeat = 0;

while (!connection_aborted()) {
    // fetch latest relevant fields
    $stmt = $conn->prepare("SELECT status, updated_at, paid, payment_method, cancel_reason FROM orders WHERE order_group_id = ? LIMIT 1");
    $stmt->bind_param("s", $orderGroupId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if ($row) {
        $payload = [
            'status' => 'success',
            'order_status' => $row['status'],
            'updated_at' => $row['updated_at'],
            'paid' => isset($row['paid']) ? (int)$row['paid'] : 0,
            'payment_method' => $row['payment_method'] ?? '',
            'cancel_reason' => $row['cancel_reason'] ?? null
        ];
        $json = json_encode($payload);

        // only send if changed to reduce traffic
        if ($json !== $lastPayload) {
            echo "event: update\n";
            echo "data: {$json}\n\n";
            $lastPayload = $json;
            // flush immediately
            @ob_flush();
            @flush();
            $elapsedSinceHeartbeat = 0;
        }
    } else {
        // order not found — send one error event and break
        echo "event: error\n";
        echo 'data: ' . json_encode(['status'=>'error','message'=>'Order not found']) . "\n\n";
        @ob_flush();
        @flush();
        break;
    }

    // heartbeat if nothing changed for a while to keep connection alive
    sleep($checkInterval);
    $elapsedSinceHeartbeat += $checkInterval;
    if ($elapsedSinceHeartbeat >= $heartbeatInterval) {
        echo "event: ping\n";
        echo 'data: {"ts":' . time() . "}\n\n";
        @ob_flush();
        @flush();
        $elapsedSinceHeartbeat = 0;
    }
}
$conn->close();