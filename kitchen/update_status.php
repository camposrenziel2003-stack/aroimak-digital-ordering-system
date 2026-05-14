<?php
session_start();
include "../config.php";
header('Content-Type: application/json');

// Load logger (expects file at ../includes/activity_logger.php)
$loggerPath = __DIR__ . '/../includes/activity_logger.php';
if (is_readable($loggerPath)) {
    require_once $loggerPath;
} else {
    error_log("activity_logger not found at: {$loggerPath}");
}

// Helper: check that logger function exists
$logger_available = function_exists('logOrderActivity');

$response = ["success" => false, "error" => ""];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Accept both form-data (orderId/newStatus) and JSON payload
    $orderId = 0;
    $newStatus = '';

    // Support POSTed form-data
    if (isset($_POST['orderId']) && isset($_POST['newStatus'])) {
        $orderId = intval($_POST['orderId']);
        $newStatus = $_POST['newStatus'];
    } else {
        // Try JSON body
        $body = json_decode(file_get_contents('php://input'), true);
        if (is_array($body)) {
            $orderId = isset($body['orderId']) ? intval($body['orderId']) : (isset($body['order_id']) ? intval($body['order_id']) : 0);
            $newStatus = $body['newStatus'] ?? ($body['status'] ?? '');
        }
    }

    if ($orderId > 0 && !empty($newStatus)) {
        // Prepare the SQL statement to prevent SQL injection
        $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
        if (!$stmt) {
            $response["error"] = "Prepare failed: " . $conn->error;
            error_log("update_status prepare failed: " . $conn->error);
            echo json_encode($response);
            exit;
        }
        $stmt->bind_param("si", $newStatus, $orderId);

        if ($stmt->execute()) {
            $response["success"] = true;

            // Fetch order_group_id for this order (used for downstream item updates)
            $ogid = null;
            try {
                $gstmt = $conn->prepare("SELECT order_group_id FROM orders WHERE id = ? LIMIT 1");
                if ($gstmt) {
                    $gstmt->bind_param("i", $orderId);
                    $gstmt->execute();
                    $gres = $gstmt->get_result();
                    if ($row = $gres->fetch_assoc()) {
                        $ogid = $row['order_group_id'];
                    }
                    $gstmt->close();
                } else {
                    error_log("update_status: prepare select order_group_id failed: " . $conn->error);
                }
            } catch (Throwable $e) {
                error_log("update_status: exception while selecting order_group_id: " . $e->getMessage());
            }

            // If parent order was marked Completed (Ready to Serve), mark related items as 'ready'
            $updated_items = 0;
            try {
                if (strtolower(trim($newStatus)) === 'completed') {
                    if ($ogid !== null) {
                        // Update order_items for this group which are still 'new' / empty / NULL -> set to 'ready'
                        $ustmt = $conn->prepare("
                            UPDATE order_items
                            SET item_status = 'ready'
                            WHERE order_group_id = ?
                              AND (item_status IS NULL OR item_status = '' OR LOWER(item_status) = 'new')
                        ");
                        if ($ustmt) {
                            $ustmt->bind_param("s", $ogid);
                            $ustmt->execute();
                            $updated_items = $ustmt->affected_rows;
                            $ustmt->close();
                        } else {
                            error_log("update_status: prepare update order_items failed: " . $conn->error);
                        }
                    } else {
                        error_log("update_status: could not find order_group_id for order id {$orderId}");
                    }
                }

                // If parent order was marked Canceled, mark related items as 'canceled' so KDS won't show them
                if (strpos(strtolower(trim($newStatus)), 'cancel') !== false) {
                    if ($ogid !== null) {
                        $cstmt = $conn->prepare("
                            UPDATE order_items
                            SET item_status = 'canceled'
                            WHERE order_group_id = ?
                              AND (item_status IS NULL OR item_status = '' OR LOWER(item_status) IN ('new','preparing','pending'))
                        ");
                        if ($cstmt) {
                            $cstmt->bind_param("s", $ogid);
                            $cstmt->execute();
                            $response['updated_items_on_cancel'] = $cstmt->affected_rows;
                            $cstmt->close();
                        } else {
                            error_log("update_status cancel: prepare failed: " . $conn->error);
                        }
                    } else {
                        error_log("update_status cancel: could not find order_group_id for order id {$orderId}");
                    }
                }
            } catch (Throwable $e) {
                error_log("update_status: exception while updating order_items: " . $e->getMessage());
            }

            // Try logging activity
            try {
                $staff_id = $_SESSION['staff_id'] ?? $_SESSION['admin_id'] ?? 0;
                $staff_username = $_SESSION['username'] ?? ($_SESSION['staff_username'] ?? 'Unknown');
                $role = $_SESSION['role'] ?? 'kitchen';
                // Action now uses "Order has been " + the preg_replace(...) per your request
                $action = 'Order has been ' . preg_replace('/\s+/', '_', trim($newStatus));

                if ($logger_available) {
                    $ok = logOrderActivity($conn, $orderId, $staff_id, $staff_username, $role, $action);
                    if (!$ok) {
                        error_log("logOrderActivity returned false for orderId={$orderId}, action={$action}");
                    }
                } else {
                    error_log("logOrderActivity() not available in update_status.php");
                }
            } catch (Throwable $e) {
                error_log("Exception while logging activity: " . $e->getMessage());
            }

            // include updated_items count in response for debugging
            if (!isset($response['updated_items']) || $response['updated_items'] === null) {
                $response['updated_items'] = $updated_items;
            }

        } else {
            $response["error"] = "Error updating record: " . $stmt->error;
            error_log("update_status execute failed: " . $stmt->error);
        }
        $stmt->close();
    } else {
        $response["error"] = "Invalid order ID or status.";
    }
} else {
    $response["error"] = "Invalid request method.";
}

echo json_encode($response);
$conn->close();
?>