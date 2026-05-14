<?php
include "../config.php";

$sql = "SELECT id, order_group_id, table_number, customer_name, request_type, status, created_at
        FROM order_requests
        WHERE status IN ('Pending', 'Acknowledged', 'pending', 'acknowledged')
        ORDER BY created_at ASC";

$result = $conn->query($sql);

$requests = [];
$pendingCount = 0;

while ($row = $result->fetch_assoc()) {
    $requests[] = $row;
    if (strtolower($row['status']) === 'pending') {
        $pendingCount++;
    }
}

echo json_encode([
    "status" => "success",
    "requests" => $requests,
    "pendingCount" => $pendingCount
]);
?>