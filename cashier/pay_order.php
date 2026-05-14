<?php
include "../config.php";
header("Content-Type: application/json");
session_start();

$loggerPath = __DIR__ . '/../includes/activity_logger.php';
if (is_readable($loggerPath)) require_once $loggerPath;

$data = json_decode(file_get_contents("php://input"), true);
$orderGroupId = $data["order_group_id"] ?? null;
$discountPercent = isset($data["discount_percent"]) ? (float)$data["discount_percent"] : 0;

if (!$orderGroupId) {
    echo json_encode(["success" => false, "message" => "Missing order_group_id"]);
    exit;
}

// Apply discount to total_price (if provided)
if ($discountPercent > 0) {
    // compute factor
    $factor = 1 - ($discountPercent / 100);
    $stmtDisc = $conn->prepare("UPDATE orders SET total_price = ROUND(total_price * ?, 2) WHERE order_group_id = ?");
    if ($stmtDisc) {
        $stmtDisc->bind_param("ds", $factor, $orderGroupId);
        $stmtDisc->execute();
        $stmtDisc->close();
    }
}

$stmt = $conn->prepare("UPDATE orders SET paid = 1 WHERE order_group_id = ? AND paid = 0");
$stmt->bind_param("s", $orderGroupId);
$success = $stmt->execute();
$stmt->close();

if ($success) {
    // Also update status to 'Preparing' if it was 'Pending'
    $stmt2 = $conn->prepare("UPDATE orders SET status = 'Preparing' WHERE order_group_id = ? AND status = 'Pending'");
    $stmt2->bind_param("s", $orderGroupId);
    $stmt2->execute();
    $stmt2->close();

    // Log the paid event
    $staff_id = $_SESSION['staff_id'] ?? $_SESSION['admin_id'] ?? 0;
    $staff_username = $_SESSION['username'] ?? ($_SESSION['staff_username'] ?? 'Unknown');
    $role = $_SESSION['role'] ?? 'cashier';

    // get numeric id and current total
    $s = $conn->prepare("SELECT id, total_price FROM orders WHERE order_group_id = ? LIMIT 1");
    if ($s) {
        $s->bind_param("s", $orderGroupId);
        $s->execute();
        $r = $s->get_result()->fetch_assoc();
        $numericId = $r['id'] ?? 0;
        $currentTotal = isset($r['total_price']) ? (float)$r['total_price'] : null;
        $s->close();
        if (function_exists('logOrderActivity')) {
            logOrderActivity($conn, (int)$numericId, $staff_id, $staff_username, $role, 'paid');
        }
    }
} else {
    // If no row updated (maybe already paid), still try to fetch current total
    $s = $conn->prepare("SELECT total_price FROM orders WHERE order_group_id = ? LIMIT 1");
    if ($s) {
        $s->bind_param("s", $orderGroupId);
        $s->execute();
        $r = $s->get_result()->fetch_assoc();
        $currentTotal = isset($r['total_price']) ? (float)$r['total_price'] : null;
        $s->close();
    } else {
        $currentTotal = null;
    }
}

$conn->close();
echo json_encode(["success" => (bool)$success, "discounted_total" => $currentTotal ?? null]);
?>