<?php
// send_additional.php
// Mark unsent additional items for an order_group_id as sent_to_kitchen = NOW()
// Accepts JSON POST { "order_group_id": "ORD-..." }
// Returns { success: true } on success.

session_start();
header('Content-Type: application/json');
include "../config.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
$orderGroupId = $data['order_group_id'] ?? null;

if (!$orderGroupId) {
    echo json_encode(['success' => false, 'message' => 'Missing order_group_id']);
    exit;
}

try {
    // We will mark as sent any order_items that are:
    // - belong to this order_group_id
    // - were created after the order.created_at (i.e. additional)
    // - and sent_to_kitchen IS NULL (unsent)
    // We must update in a safe way: use prepared statements and a JOIN.

    // Fetch order created_at for comparison
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

    // Update the matching items
    $stmt = $conn->prepare("
        UPDATE order_items oi
        JOIN orders o ON o.order_group_id = oi.order_group_id
        SET oi.sent_to_kitchen = NOW()
        WHERE oi.order_group_id = ?
          AND oi.created_at > ?
          AND (oi.sent_to_kitchen IS NULL)
    ");
    if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
    $stmt->bind_param("ss", $orderGroupId, $orderCreatedAt);
    $ok = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    // Optionally: log activity (if you have activity logger)
    $loggerPath = __DIR__ . '/../includes/activity_logger.php';
    if (is_readable($loggerPath)) {
        require_once $loggerPath;
        if (function_exists('getNumericOrderId')) {
            $numeric = getNumericOrderId($conn, $orderGroupId) ?: 0;
        } else {
            $s2 = $conn->prepare("SELECT id FROM orders WHERE order_group_id = ? LIMIT 1");
            $s2->bind_param("s", $orderGroupId);
            $s2->execute();
            $r2 = $s2->get_result()->fetch_assoc();
            $numeric = $r2['id'] ?? 0;
            $s2->close();
        }
        $staff_id = $_SESSION['staff_id'] ?? $_SESSION['admin_id'] ?? 0;
        $staff_username = $_SESSION['username'] ?? ($_SESSION['staff_username'] ?? 'Unknown');
        $role = $_SESSION['role'] ?? 'cashier';
        if (function_exists('logOrderActivity')) {
            logOrderActivity($conn, (int)$numeric, $staff_id, $staff_username, $role, 'additional_sent_to_kitchen');
        }
    }

    echo json_encode(['success' => (bool)$ok, 'affected' => $affected]);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}
?>