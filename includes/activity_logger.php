<?php
// includes/activity_logger.php
// Small helper to log order activity into an existing `order_activity_log` table.
//
// Expected table columns (based on your screenshot):
// id, order_id, staff_id, staff_username, role, action, created_at
//
// Usage:
//   require_once __DIR__ . '/activity_logger.php';
//   logOrderActivity($conn, $order_id, $staff_id, $staff_username, $role, $action);
//
// Returns: true on success, false on failure.

if (!function_exists('logOrderActivity')) {
    function logOrderActivity($conn, $order_id = null, $staff_id = null, $staff_username = null, $role = null, $action = null) {
        if (!$conn) return false;

        // Normalize values
        $order_id_val = $order_id !== null ? (int)$order_id : null;
        $staff_id_val = $staff_id !== null ? (int)$staff_id : null;
        $staff_username_val = $staff_username !== null ? (string)$staff_username : '';
        $role_val = $role !== null ? (string)$role : '';
        $action_val = $action !== null ? (string)$action : '';

        // Prepare statement
        // We explicitly insert into the columns shown in your table screenshot.
        $sql = "INSERT INTO order_activity_log
                (order_id, staff_id, staff_username, role, action, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log('activity_logger prepare error: ' . ($conn->error ?? 'unknown'));
            return false;
        }

        // Bind parameters: order_id, staff_id as INT; the rest as strings
        // Use empty string for username/role/action if not provided
        $oid = $order_id_val !== null ? $order_id_val : 0;
        $sid = $staff_id_val !== null ? $staff_id_val : 0;

        if (!$stmt->bind_param('iisss', $oid, $sid, $staff_username_val, $role_val, $action_val)) {
            error_log('activity_logger bind_param error');
            $stmt->close();
            return false;
        }

        $ok = $stmt->execute();
        if (!$ok) {
            error_log('activity_logger execute error: ' . $stmt->error);
        }
        $stmt->close();
        return (bool)$ok;
    }
}

if (!function_exists('getRecentOrderActivities')) {
    function getRecentOrderActivities($conn, $limit = 50) {
        if (!$conn) return [];
        $limit = (int)$limit;
        if ($limit <= 0) $limit = 50;

        $sql = "SELECT id, order_id, staff_id, staff_username, role, action, created_at
                FROM order_activity_log
                ORDER BY created_at DESC
                LIMIT ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return [];
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
        $stmt->close();
        return $rows;
    }
}
?>