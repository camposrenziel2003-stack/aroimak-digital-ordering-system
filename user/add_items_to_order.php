<?php
// add_items_to_order.php - Cleaned/Fixed version
session_start();
header('Content-Type: application/json');
include "config.php";
include "access_check.php";

// Helper: normalize spice textual or numeric to integer index (0..4)
function map_spice_to_index($v) {
    if (is_numeric($v)) return (int)$v;
    $v = strtolower(trim((string)$v));
    $map = [
        'no spice' => 0, 'none' => 0, 'no' => 0,
        'light' => 1,
        'moderate' => 2, 'medium' => 2,
        'spicy' => 3,
        'extra' => 4, 'extra spicy' => 4
    ];
    return $map[$v] ?? 0;
}

// Parse incoming payload (support raw JSON body or form POST)
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = $_POST ?? [];

// Validate action
$action = $data['action'] ?? ($_POST['action'] ?? '');
if ($action !== 'append_items') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid action.']);
    exit;
}

// Get orderGroupId (support both sources)
$orderGroupId = $data['order_group_id'] ?? ($_POST['order_group_id'] ?? '');
if (!$orderGroupId) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing order_group_id.']);
    exit;
}

// Get cart: prefer payload cart, fallback to form POST cart, fallback to session cart
$cart = [];
if (isset($data['cart'])) {
    if (is_string($data['cart'])) {
        $decoded = json_decode($data['cart'], true);
        if (is_array($decoded)) $cart = $decoded;
    } elseif (is_array($data['cart'])) {
        $cart = $data['cart'];
    }
} elseif (isset($_POST['cart'])) {
    $decoded = json_decode($_POST['cart'], true);
    if (is_array($decoded)) $cart = $decoded;
}
if (empty($cart) && isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    $cart = $_SESSION['cart'];
}

try {
    if (empty($cart)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Cart is empty.']);
        exit;
    }

    // Start transaction
    $conn->begin_transaction();

    // Lock order row for update and validate
    $oStmt = $conn->prepare("SELECT id, total_price, item_name, quantity, paid, status FROM orders WHERE order_group_id = ? LIMIT 1 FOR UPDATE");
    if (!$oStmt) throw new Exception('Prepare order select failed: ' . $conn->error);
    $oStmt->bind_param("s", $orderGroupId);
    $oStmt->execute();
    $ordRes = $oStmt->get_result();
    $order = $ordRes->fetch_assoc();
    $oStmt->close();

    if (!$order) {
        $conn->rollback();
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Order not found.']);
        exit;
    }
    if (intval($order['paid']) === 1) {
        $conn->rollback();
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Cannot add items to a paid order.']);
        exit;
    }
    $lockedStatuses = ['cancelled', 'canceled', 'closed'];
    if (in_array(strtolower($order['status']), $lockedStatuses, true)) {
        $conn->rollback();
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Cannot add items to order in current status: ' . $order['status']]);
        exit;
    }

    // Prepare insert for order_items
    $insStmt = $conn->prepare("
        INSERT INTO order_items 
            (order_group_id, item_name, quantity, price, total, spice_level, serving_size, allergens, allergen_note, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    if (!$insStmt) throw new Exception('Prepare insert failed: ' . $conn->error);

    $sumAdded = 0.0;
    $qtyAdded = 0;
    $newItemsNames = [];

    foreach ($cart as $it) {
        $menu_id = isset($it['id']) ? intval($it['id']) : 0;
        $item_name = trim($it['item_name'] ?? ($it['name'] ?? ''));
        if ($item_name === '') continue;
        $quantity = max(0, intval($it['quantity'] ?? 1));
        if ($quantity <= 0) continue;
        $price = floatval($it['price'] ?? 0.0);
        $total = floatval($it['total'] ?? ($price * $quantity));
        $rawSpice = $it['spice_level'] ?? ($it['spice'] ?? 0);
        $spice_level = map_spice_to_index($rawSpice);
        $allergens = $it['allergens'] ?? null;
        $allergen_note = $it['allergen_note'] ?? null;
        $serving_size = $it['serving_size'] ?? ($it['selected_type'] ?? null);

        // If menu id provided, check & decrement stock (with FOR UPDATE above)
        if ($menu_id > 0) {
            $sStmt = $conn->prepare("SELECT stock FROM menu_items WHERE id = ? LIMIT 1 FOR UPDATE");
            if (!$sStmt) throw new Exception('Stock select prepare failed: ' . $conn->error);
            $sStmt->bind_param("i", $menu_id);
            $sStmt->execute();
            $sRes = $sStmt->get_result();
            $row = $sRes->fetch_assoc();
            $sStmt->close();
            if (!$row) throw new Exception("Menu item not found (id={$menu_id})");
            $currentStock = intval($row['stock']);
            if ($quantity > $currentStock) {
                throw new Exception("Not enough stock for {$item_name}. Only {$currentStock} left.");
            }
            $uStmt = $conn->prepare("UPDATE menu_items SET stock = stock - ? WHERE id = ? AND stock >= ?");
            if (!$uStmt) throw new Exception('Stock update prepare failed: ' . $conn->error);
            $uStmt->bind_param("iii", $quantity, $menu_id, $quantity);
            $uStmt->execute();
            if ($uStmt->affected_rows === 0) {
                $uStmt->close();
                throw new Exception("Failed to decrement stock for item id {$menu_id} (possible race).");
            }
            $uStmt->close();
        }

        // Insert into order_items (bind spice_level as integer)
        $insStmt->bind_param(
            "ssiddisss",
            $orderGroupId,
            $item_name,
            $quantity,
            $price,
            $total,
            $spice_level,
            $serving_size,
            $allergens,
            $allergen_note
        );
        $insStmt->execute();
        if ($insStmt->error) throw new Exception('Insert order_item failed: ' . $insStmt->error);

        $sumAdded += $total;
        $qtyAdded += $quantity;
        $newItemsNames[] = "{$quantity}x {$item_name}";
    }

    $insStmt->close();

    if ($sumAdded <= 0) {
        $conn->rollback();
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'No valid items to add.']);
        exit;
    }

    // Update orders table: total_price, item_name concat, quantity add
    $existingTotal = floatval($order['total_price'] ?? 0.0);
    $newTotal = $existingTotal + $sumAdded;
    $existingItemsStr = trim($order['item_name'] ?? '');
    $appendStr = implode(', ', $newItemsNames);
    $newItemsField = $existingItemsStr ? ($existingItemsStr . ', ' . $appendStr) : $appendStr;
    $newQty = intval($order['quantity'] ?? 0) + $qtyAdded;

    // When appending additional items, DO NOT update `updated_at` because that
    // timestamp is used to enforce the customer's cancel window. Updating it here
    // would reset the timer and could re-enable cancellation after it already expired.
    $uOrder = $conn->prepare("UPDATE orders SET total_price = ?, item_name = ?, quantity = ? WHERE order_group_id = ?");
    if (!$uOrder) throw new Exception('Prepare update order failed: ' . $conn->error);
    // bind types: d (double), s (string), i (int), s (string)
    $uOrder->bind_param("dsis", $newTotal, $newItemsField, $newQty, $orderGroupId);
    $uOrder->execute();
    if ($uOrder->error) throw new Exception('Update orders failed: ' . $uOrder->error);
    $uOrder->close();

    // Commit
    $conn->commit();

    // Clear cart in session (kiosk flow expects cart cleared after submit)
    $_SESSION['cart'] = [];

    echo json_encode(['status' => 'success', 'message' => 'Items appended to order.', 'new_total' => number_format($newTotal, 2)]);
    exit;
} catch (Exception $e) {
    if (isset($conn) && $conn->connect_errno === 0) {
        $conn->rollback();
    }
    error_log('add_items_to_order error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
?>