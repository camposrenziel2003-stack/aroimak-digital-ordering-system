<?php
// cancel_additional.php
// Delete unsent additional items for an order_group_id (created after order.created_at and sent_to_kitchen IS NULL)
// Accepts JSON POST { "order_group_id": "ORD-..." }
// Returns { success: true }

session_start();
header('Content-Type: application/json');
include "../config.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$orderGroupId = $data['order_group_id'] ?? null;

if (!$orderGroupId) {
    echo json_encode(['success' => false, 'message' => 'Missing order_group_id']);
    exit;
}

try {
    $s = $conn->prepare("SELECT created_at FROM orders WHERE order_group_id = ? LIMIT 1");
    $s->bind_param("s", $orderGroupId);
    $s->execute();
    $r = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$r) {
        echo json_encode(['success' => false, 'message' => 'Order not found']);
        exit;
    }
    $orderCreatedAt = $r['created_at'];

    $stmt = $conn->prepare("
        DELETE oi FROM order_items oi
        WHERE oi.order_group_id = ?
          AND oi.created_at > ?
          AND (oi.sent_to_kitchen IS NULL)
    ");
    if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
    $stmt->bind_param("ss", $orderGroupId, $orderCreatedAt);
    $ok = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    echo json_encode(['success' => (bool)$ok, 'deleted' => $affected]);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}
?>