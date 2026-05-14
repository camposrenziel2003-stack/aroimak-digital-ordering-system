<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Accept");

include 'config.php';
header('Content-Type: application/json');

$json_data = file_get_contents('php://input');
$data = json_decode($json_data, true);

if ($data === null) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON data']);
    exit;
}

// Extract order data
$customer_name = !empty($data['customer_name']) ? $data['customer_name'] : 'Guest';
$total_amount  = isset($data['total_amount']) ? floatval($data['total_amount']) : 0;
$items         = isset($data['items']) ? $data['items'] : [];
$allergens     = isset($data['allergens']) ? $data['allergens'] : '';
$allergen_note = isset($data['allergen_note']) ? $data['allergen_note'] : '';
$table_number  = isset($data['table_number']) && $data['table_number'] !== '' 
                    ? intval($data['table_number']) 
                    : 0; // default to 0 if not provided

if (empty($items)) {
    echo json_encode(['status' => 'error', 'message' => 'Order is empty']);
    exit;
}

try {
    // Generate unique group id para lahat ng items sa order magka-group
    $order_group_id = uniqid("ORD_");

    // Prepare statement (1 row per item)
    $stmt = $conn->prepare("
        INSERT INTO orders 
        (order_group_id, customer_name, table_number, item_name, quantity, total_price, status, created_at, allergens, allergen_note) 
        VALUES (?, ?, ?, ?, ?, ?, 'Pending', NOW(), ?, ?)
    ");

    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    foreach ($items as $item) {
        $item_name = $item['name'];
        $quantity  = intval($item['quantity']);
        $price     = floatval($item['price']); // per-item price

        // ✅ Correct binding (8 parameters, proper types)
        $stmt->bind_param(
            "ssisdsss",
            $order_group_id,   // s
            $customer_name,    // s
            $table_number,     // i
            $item_name,        // s
            $quantity,         // d (pero int kaya pwede rin)
            $price,            // s
            $allergens,        // s
            $allergen_note     // s
        );

        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Order placed successfully',
        'order_group_id' => $order_group_id,
        'customer_name' => $customer_name,
        'total_amount' => $total_amount,
        'created_at' => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    error_log("Order insert failed: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Failed to place order']);
} finally {
    $conn->close();
}
?>
