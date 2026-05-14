<?php
// update_batch_status.php (robust diagnostic version)
session_start();
include "../config.php";
header('Content-Type: application/json');

$response = ['success' => false, 'attempts' => [], 'total_updated' => 0];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['error'] = 'Invalid method';
    echo json_encode($response);
    exit;
}

$order_group_id = $_POST['order_group_id'] ?? '';
$batch_key = $_POST['batch_key'] ?? ''; // expected YYYYmmddHHMMSS
$action = $_POST['action'] ?? 'ready';

// --- HANDLE the 'active' batch_key BEFORE the validation! ---
if ($batch_key === 'active') {
    // Mark all unresolved additional/initial items for this order group as prepared
    $stmt = $conn->prepare("
        UPDATE order_items
        SET item_status = 'prepared'
        WHERE order_group_id = ?
          AND (item_status IS NULL OR item_status = '' OR LOWER(item_status) IN ('new','preparing','pending'))
          AND (LOWER(added_type) IN ('additional', 'initial') OR added_type IS NULL OR added_type = '')
    ");
    if ($stmt) {
        $stmt->bind_param('s', $order_group_id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        $response['attempts'][] = ['method' => 'active-catchall', 'updated' => $affected];
        $response['total_updated'] += max(0, $affected);
    } else {
        $response['attempts'][] = ['method' => 'active-catchall', 'error' => $conn->error];
    }
    $response['success'] = true;
    $response['updated'] = $response['total_updated'];
    echo json_encode($response);
    exit;
}

// --- Now validate ONLY non-'active' batch_key ---
if (empty($order_group_id) || !preg_match('/^\d{14}$/', $batch_key)) {
    $response['error'] = 'Missing or invalid parameters';
    echo json_encode($response);
    exit;
}

$dt = DateTime::createFromFormat('YmdHis', $batch_key);
if (!$dt) {
    $response['error'] = 'Invalid batch key';
    echo json_encode($response);
    exit;
}

try {
    if ($action !== 'ready') {
        $response['error'] = 'Unknown action';
        echo json_encode($response);
        exit;
    }

    // 1) Try exact sent_to_kitchen match
    $stmt = $conn->prepare("
        UPDATE order_items
        SET item_status = 'prepared'
        WHERE order_group_id = ?
          AND DATE_FORMAT(sent_to_kitchen,'%Y%m%d%H%i%S') = ?
          AND (item_status IS NULL OR item_status = '' OR LOWER(item_status) = 'new')
    ");
    if ($stmt) {
        $stmt->bind_param('ss', $order_group_id, $batch_key);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        $response['attempts'][] = ['method' => 'sent_to_kitchen_match', 'updated' => $affected];
        $response['total_updated'] += max(0, $affected);
    } else {
        $response['attempts'][] = ['method' => 'sent_to_kitchen_match', 'error' => $conn->error];
    }

    // 2) Fallback: created_at +/- 3 seconds window around batch timestamp
    if ($response['total_updated'] === 0) {
        $startDt = clone $dt;
        $start = $startDt->modify('-3 seconds')->format('Y-m-d H:i:s');
        $end = $dt->modify('+3 seconds')->format('Y-m-d H:i:s');

        $stmt2 = $conn->prepare("
            UPDATE order_items
            SET item_status = 'prepared'
            WHERE order_group_id = ?
              AND created_at BETWEEN ? AND ?
              AND (item_status IS NULL OR item_status = '' OR LOWER(item_status) = 'new')
        ");
        if ($stmt2) {
            $stmt2->bind_param('sss', $order_group_id, $start, $end);
            $stmt2->execute();
            $affected2 = $stmt2->affected_rows;
            $stmt2->close();
            $response['attempts'][] = ['method' => 'created_at_window', 'start' => $start, 'end' => $end, 'updated' => $affected2];
            $response['total_updated'] += max(0, $affected2);
        } else {
            $response['attempts'][] = ['method' => 'created_at_window', 'error' => $conn->error];
        }
    }

    // 3) Aggressive fallback: mark any additional/new items for that group as ready (catch-all)
    if ($response['total_updated'] === 0) {
        $stmt3 = $conn->prepare("
            UPDATE order_items
            SET item_status = 'prepared'
            WHERE order_group_id = ?
              AND (item_status IS NULL OR item_status = '' OR LOWER(item_status) = 'new')
              AND (LOWER(added_type) = 'additional' OR added_type IS NULL OR added_type = '')
        ");
        if ($stmt3) {
            $stmt3->bind_param('s', $order_group_id);
            $stmt3->execute();
            $affected3 = $stmt3->affected_rows;
            $stmt3->close();
            $response['attempts'][] = ['method' => 'aggressive_group_additional', 'updated' => $affected3];
            $response['total_updated'] += max(0, $affected3);
        } else {
            $response['attempts'][] = ['method' => 'aggressive_group_additional', 'error' => $conn->error];
        }
    }

    $response['success'] = true;
    $response['updated'] = $response['total_updated'];

} catch (Throwable $e) {
    $response['error'] = 'Exception: ' . $e->getMessage();
}

echo json_encode($response);
exit;
?>