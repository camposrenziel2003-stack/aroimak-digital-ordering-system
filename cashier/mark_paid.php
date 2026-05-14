<?php
include "../config.php";
session_start();

$loggerPath = __DIR__ . '/../includes/activity_logger.php';
if (is_readable($loggerPath)) require_once $loggerPath;

$data = json_decode(file_get_contents("php://input"), true);

$order_group_id = $data['order_group_id'] ?? null;

if ($order_group_id) {
    // Update ALL orders in the group as paid
    $stmt = $conn->prepare("UPDATE orders SET paid=1 WHERE order_group_id=?");
    $stmt->bind_param("s", $order_group_id);
    $stmt->execute();
    $stmt->close();

    // Log paid
    $staff_id = $_SESSION['staff_id'] ?? $_SESSION['admin_id'] ?? 0;
    $staff_username = $_SESSION['username'] ?? ($_SESSION['staff_username'] ?? 'Unknown');
    $role = $_SESSION['role'] ?? 'cashier';

    $s = $conn->prepare("SELECT id FROM orders WHERE order_group_id = ? LIMIT 1");
    if ($s) {
        $s->bind_param("s", $order_group_id);
        $s->execute();
        $r = $s->get_result()->fetch_assoc();
        $numericId = $r['id'] ?? 0;
        $s->close();
        if (function_exists('logOrderActivity')) {
            logOrderActivity($conn, (int)$numericId, $staff_id, $staff_username, $role, 'paid');
        }
    }

    echo json_encode(['success'=>true]);
} else {
    echo json_encode(['success'=>false, 'message'=>'Missing order_group_id']);
}
?>