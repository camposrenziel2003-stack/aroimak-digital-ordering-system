<?php
include "../config.php";
header('Content-Type: application/json');
date_default_timezone_set('Asia/Manila');

try {
    $date = isset($_GET['date']) && $_GET['date'] !== '' ? $_GET['date'] : date('Y-m-d');
    $sql = "SELECT * FROM orders WHERE DATE(created_at) = ? AND (archived IS NULL OR archived = 0) ORDER BY created_at ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $result = $stmt->get_result();

    if (!$result) {
        throw new Exception("Query failed: " . $conn->error);
    }

    $orders = [];
    $additions = [];
    $hasOrderItems = false;
    $res = $conn->query("SHOW TABLES LIKE 'order_items'");
    if ($res && $res->num_rows > 0) $hasOrderItems = true;

    $stmtItems = null;
    if ($hasOrderItems) {
        // include created_at and sent_to_kitchen from order_items so we can detect additions and unsent ones
        $stmtItems = $conn->prepare("
            SELECT 
                id,
                item_name, 
                quantity, 
                price, 
                total,
                spice_level,
                serving_size,
                allergens,
                allergen_note,
                created_at,
                sent_to_kitchen
            FROM order_items
            WHERE order_group_id = ?
            ORDER BY created_at ASC, id ASC
        ");
    }

    // --- Deduplicate by order_group_id ---
    $orderMap = [];
    while ($row = $result->fetch_assoc()) {
        $orderGroupId = $row['order_group_id'];
        // Only keep the latest order per order_group_id (if ever there are duplicates)
        $orderMap[$orderGroupId] = $row;
    }

    foreach ($orderMap as $orderGroupId => $row) {
        $row['payment_status'] = ($row['paid'] == 1) ? 'Paid' : 'Unpaid';

        $items = [];
        $unsentAdditionalItems = [];
        $orderCreatedAt = isset($row['created_at']) ? strtotime($row['created_at']) : null;
        // IMPORTANT: only consider "has_additional" true when there are additional items that are NOT sent yet.
        $orderHasUnsentAdditional = false;

        if ($hasOrderItems && $stmtItems) {
            $stmtItems->bind_param("s", $orderGroupId);
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
                $ir['created_at'] = $ir['created_at'] ?? null;
                $ir['sent_to_kitchen'] = $ir['sent_to_kitchen'] ?? null;

                // Determine if this item was added after the original order creation
                if ($orderCreatedAt !== null && !empty($ir['created_at'])) {
                    $itemCreated = strtotime($ir['created_at']);
                    $ir['is_additional'] = ($itemCreated > $orderCreatedAt) ? true : false;
                } else {
                    $ir['is_additional'] = false;
                }

                // If item is additional AND not yet sent_to_kitchen, collect for addition card
                if ($ir['is_additional'] && ($ir['sent_to_kitchen'] === null)) {
                    // copy necessary fields (we'll show them in the Additional card)
                    $unsentAdditionalItems[] = [
                        'id' => $ir['id'],
                        'item_name' => $ir['item_name'],
                        'quantity' => $ir['quantity'],
                        'price' => $ir['price'],
                        'total' => $ir['total'],
                        'spice_level' => $ir['spice_level'],
                        'serving_size' => $ir['serving_size'],
                        'allergens' => $ir['allergens'],
                        'allergen_note' => $ir['allergen_note'],
                        'created_at' => $ir['created_at']
                    ];
                    $orderHasUnsentAdditional = true;
                }

                $items[] = $ir;
            }
            $stmtItems->free_result();
        } else {
            // fallback: parse from item_name (old data)
            if (!empty($row['item_name'])) {
                $parts = array_map('trim', explode(',', $row['item_name']));
                foreach ($parts as $p) {
                    if ($p === '') continue;
                    if (preg_match('/^(\d+)\s*x\s*(.+)$/i', $p, $m)) {
                        $qty = (int)$m[1];
                        $items[] = [
                            'item_name' => trim($m[2]),
                            'quantity' => $qty,
                            'price' => 0,
                            'total' => 0,
                            'spice_level' => "",
                            'serving_size' => "",
                            'allergens' => "",
                            'allergen_note' => "",
                            'created_at' => null,
                            'is_additional' => false
                        ];
                    } else {
                        $items[] = [
                            'item_name' => $p,
                            'quantity' => 1,
                            'price' => 0,
                            'total' => 0,
                            'spice_level' => "",
                            'serving_size' => "",
                            'allergens' => "",
                            'allergen_note' => "",
                            'created_at' => null,
                            'is_additional' => false
                        ];
                    }
                }
            }
        }

        $row['table_number']   = $row['table_number'] ?? null;
        $row['customer_name']  = $row['customer_name'] ?? null;
        $row['status']         = $row['status'] ?? null;
        $row['allergens']      = $row['allergens'] ?? null;
        $row['spice_level']    = $row['spice_level'] ?? null;
        $row['allergen_note']  = $row['allergen_note'] ?? null;
        $row['order_type']     = $row['order_type'] ?? null;
        $row['total_qty']      = isset($row['total_qty']) ? (int)$row['total_qty'] : null;
        $row['total_price']    = isset($row['total_price']) ? (float)$row['total_price'] : null;
        $row['payment_method'] = $row['payment_method'] ?? null;
        $row['created_at']     = $row['created_at'] ?? null;
        $row['take_out']       = isset($row['take_out']) ? (int)$row['take_out'] : 0;

        // mark whether this order includes UNSENT additional items
        $row['has_additional'] = $orderHasUnsentAdditional ? true : false;

        $row['items'] = $items;
        $orders[] = $row;

        // If there are unsent addition items, create a synthetic "Additional Order" card entry
        if (!empty($unsentAdditionalItems)) {
            // determine created_at for the addition card (earliest unsent item created_at)
            usort($unsentAdditionalItems, function($a, $b){
                return strtotime($a['created_at']) <=> strtotime($b['created_at']);
            });
            $addition = [
                'addition_id' => $orderGroupId . '-add-' . strtotime($unsentAdditionalItems[0]['created_at']),
                'order_group_id' => $orderGroupId,
                'table_number' => $row['table_number'],
                'customer_name' => $row['customer_name'],
                'placed_at' => $unsentAdditionalItems[0]['created_at'],
                'items' => $unsentAdditionalItems,
                'count' => count($unsentAdditionalItems)
            ];
            $additions[] = $addition;
        }
    }

    if ($stmtItems) $stmtItems->close();

    echo json_encode(["success" => true, "data" => $orders, "additions" => $additions]);
    exit;

} catch (Exception $e) {
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
    exit;
}