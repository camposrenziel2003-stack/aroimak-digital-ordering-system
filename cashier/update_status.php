<?php
include "../config.php";
header("Content-Type: application/json");
session_start();

// load logger
$loggerPath = __DIR__ . '/../includes/activity_logger.php';
if (is_readable($loggerPath)) require_once $loggerPath;

$data = json_decode(file_get_contents("php://input"), true);

$orderGroupId = $data["order_group_id"] ?? null;
$newStatus = $data["status"] ?? null;

if (!$orderGroupId || !$newStatus) {
    echo json_encode(["success" => false, "message" => "Missing order_group_id or status"]);
    exit;
}

// Get current status
$stmt = $conn->prepare("SELECT status FROM orders WHERE order_group_id = ?");
$stmt->bind_param("s", $orderGroupId);
$stmt->execute();
$res = $stmt->get_result();
$current = $res->fetch_assoc();
$stmt->close();

if (!$current) {
    echo json_encode(["success" => false, "message" => "Order not found"]);
    exit;
}

$currentStatus = $current['status'];

// Prevent moving from "Preparing", "Completed", "Canceled" etc back to On Hold
if (
    in_array($currentStatus, ['Preparing', 'Completed', 'Canceled'])
    && $newStatus === 'On Hold'
) {
    echo json_encode([
        "success" => false,
        "message" => "Cannot move order back to On Hold once it is Preparing or beyond."
    ]);
    exit;
}

// Proceed with update
$stmt = $conn->prepare("UPDATE orders SET status = ? WHERE order_group_id = ?");
$stmt->bind_param("ss", $newStatus, $orderGroupId);
$success = $stmt->execute();
$stmt->close();

// Log status change
$staff_id = $_SESSION['staff_id'] ?? $_SESSION['admin_id'] ?? 0;
$staff_username = $_SESSION['username'] ?? ($_SESSION['staff_username'] ?? 'Unknown');
$role = $_SESSION['role'] ?? 'kitchen';

// fetch numeric id
$numericId = null;
if (function_exists('getNumericOrderId')) {
    $numericId = getNumericOrderId($conn, $orderGroupId);
} else {
    // fallback local implementation
    $s = $conn->prepare("SELECT id FROM orders WHERE order_group_id = ? LIMIT 1");
    if ($s) {
        $s->bind_param("s", $orderGroupId);
        $s->execute();
        $r = $s->get_result()->fetch_assoc();
        $numericId = $r['id'] ?? null;
        $s->close();
    }
}
$numericId = $numericId ? (int)$numericId : 0;

if ($success && function_exists('logOrderActivity')) {
    // store the new status as the action so you can see status transitions
    $action = 'status_changed_to_' . $newStatus;
    logOrderActivity($conn, $numericId, $staff_id, $staff_username, $role, $action);
}

// If the new status is Canceled, also remove any unsent "additional" items
if ($success && strtolower($newStatus) === 'canceled') {
    // Fetch order created_at for comparison
    $s2 = $conn->prepare("SELECT created_at FROM orders WHERE order_group_id = ? LIMIT 1");
    if ($s2) {
        $s2->bind_param("s", $orderGroupId);
        $s2->execute();
        $r2 = $s2->get_result()->fetch_assoc();
        $s2->close();
        if ($r2 && !empty($r2['created_at'])) {
            $orderCreatedAt = $r2['created_at'];

            $del = $conn->prepare(
                "DELETE oi FROM order_items oi
                 WHERE oi.order_group_id = ?
                   AND oi.created_at > ?
                   AND (oi.sent_to_kitchen IS NULL)"
            );
            if ($del) {
                $del->bind_param("ss", $orderGroupId, $orderCreatedAt);
                $del->execute();
                $deleted = $del->affected_rows;
                $del->close();

                if ($deleted > 0 && function_exists('logOrderActivity')) {
                    logOrderActivity($conn, $numericId, $staff_id, $staff_username, $role, 'additional_auto_cancelled');
                }
            }
        }
    }

    // Also set as archived so it doesn't appear in active dashboards
    $archiveStmt = $conn->prepare("UPDATE orders SET archived = 1 WHERE order_group_id = ?");
    if ($archiveStmt) {
        $archiveStmt->bind_param("s", $orderGroupId);
        $archiveStmt->execute();
        $archiveStmt->close();
    }

    if ($success && function_exists('logOrderActivity')) {
        logOrderActivity($conn, $numericId, $staff_id, $staff_username, $role, 'order_archived_on_cancel');
    }
}

echo json_encode(["success" => $success]);
?>