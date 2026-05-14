<?php
include "config.php";
header('Content-Type: application/json');
$response = ["success" => false];

try {
  $sql = "SELECT * FROM orders WHERE status != 'Served' AND DATE(created_at) = CURDATE() ORDER BY created_at DESC";
    $result = $conn->query($sql);

    if ($result) {
        $orders = [];
        // Prepare statement for fetching order items with category name
        $stmtItems = $conn->prepare("
            SELECT 
                order_items.item_name, 
                order_items.quantity, 
                order_items.price, 
                order_items.total,
                order_items.spice_level,
                order_items.serving_size,
                order_items.allergens,
                order_items.allergen_note,
                categories.name AS category_name
            FROM order_items
            LEFT JOIN menu_items ON order_items.item_name = menu_items.name
            LEFT JOIN categories ON menu_items.category = categories.id
            WHERE order_items.order_group_id = ?
        ");

        while ($row = $result->fetch_assoc()) {
            $row['payment_status'] = ($row['paid'] == 1) ? 'Paid' : 'Unpaid';

            // Fetch items for this order
            $items = [];
            if ($stmtItems) {
                $stmtItems->bind_param("s", $row['order_group_id']);
                $stmtItems->execute();
                $itemsRes = $stmtItems->get_result();
                while ($ir = $itemsRes->fetch_assoc()) {
                    $ir['quantity'] = (int)$ir['quantity'];
                    $ir['price'] = (float)$ir['price'];
                    $ir['total'] = (float)$ir['total'];
                    $ir['spice_level'] = $ir['spice_level'] ?? "";
                    $ir['serving_size'] = $ir['serving_size'] ?? "";
                    $ir['allergens'] = $ir['allergens'] ?? "";
                    $ir['allergen_note'] = $ir['allergen_note'] ?? "";
                    $ir['category_name'] = $ir['category_name'] ?? "";
                    $items[] = $ir;
                }
                $stmtItems->free_result();
            }
            $row['items'] = $items;
            $orders[] = $row;
        }
        if ($stmtItems) $stmtItems->close();
        $response["success"] = true;
        $response["data"] = $orders;
    } else {
        throw new Exception("Error fetching orders: " . $conn->error);
    }
} catch (Exception $e) {
    $response["error"] = $e->getMessage();
}

echo json_encode($response);
exit;
?>