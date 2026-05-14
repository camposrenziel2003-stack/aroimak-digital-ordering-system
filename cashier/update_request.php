<?php
// cashier/update_request.php
header('Content-Type: application/json; charset=utf-8');
session_start();

include "../config.php"; // config is in project root (adjust if different)

// ✅ Method check
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$status = isset($_POST['status']) ? trim($_POST['status']) : '';

// ✅ Normalize status input (capitalize first letter)
$status = ucfirst(strtolower($status));

// ✅ Only allow these values
$allowed = ['Pending', 'Acknowledged', 'Completed'];
if ($id <= 0 || $status === '' || !in_array($status, $allowed)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid id or status']);
    exit;
}

// ✅ Update request in DB
$stmt = $conn->prepare("UPDATE order_requests SET status = ? WHERE id = ?");
if (!$stmt) {
    echo json_encode(['status' => 'error', 'message' => 'Prepare failed: ' . $conn->error]);
    exit;
}
$stmt->bind_param('si', $status, $id);

if ($stmt->execute()) {
    echo json_encode(['status' => 'success', 'newStatus' => $status]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'DB error: ' . $stmt->error]);
}
$stmt->close();
$conn->close();
