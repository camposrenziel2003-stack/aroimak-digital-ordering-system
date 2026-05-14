<?php
include "config.php";

$dish = isset($_GET['dish']) ? $_GET['dish'] : '';
$period = isset($_GET['period']) ? $_GET['period'] : 'all';

$data = [];

if ($dish) {
    // Build WHERE conditions
    $where = ["order_items.item_name = '" . $conn->real_escape_string($dish) . "'"];
    $join = "";
    if ($period != 'all') {
        $join = "INNER JOIN orders ON order_items.order_group_id = orders.order_group_id";
        if ($period == 'day') {
            $where[] = "DATE(orders.created_at) = CURDATE()";
        } elseif ($period == 'week') {
            $where[] = "YEARWEEK(orders.created_at, 1) = YEARWEEK(CURDATE(), 1)";
        } elseif ($period == 'month') {
            $where[] = "YEAR(orders.created_at) = YEAR(CURDATE()) AND MONTH(orders.created_at) = MONTH(CURDATE())";
        }
    }

    $whereSQL = (count($where) > 0) ? ("WHERE " . implode(" AND ", $where)) : "";

    // Use orders.created_at if joined, otherwise order_items.created_at
    $selectCreatedAt = ($period != 'all') ? "orders.created_at" : "order_items.created_at";

    $sql = "
        SELECT order_items.id AS order_item_id,
               order_items.order_group_id,
               order_items.quantity,
               $selectCreatedAt AS created_at
        FROM order_items
        $join
        $whereSQL
        ORDER BY $selectCreatedAt DESC
        LIMIT 15
    ";

    $res = $conn->query($sql);
    while ($row = $res->fetch_assoc()) {
        $data[] = [
            'order_item_id'   => $row['order_item_id'],
            'order_group_id'  => $row['order_group_id'],
            'quantity'        => $row['quantity'],
            'date'            => date('Y-m-d H:i:s', strtotime($row['created_at']))
        ];
    }
}

header('Content-Type: application/json');
echo json_encode($data);