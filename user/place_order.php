<?php
// place_order.php
session_start();
include "config.php";

header('Content-Type: application/json');
ini_set('display_errors', '0');
error_reporting(E_ALL);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    // fallback: accept form POST
    if (!$data && !empty($_POST)) {
        $data = $_POST;
    }

    $customerName = trim($data['customer_name'] ?? '');
    $tableNumber  = trim($data['table_number'] ?? '');
    $paymentMethod = trim($data['payment_method'] ?? 'Cash'); // default Cash

    // optional normalization / whitelist
    $allowedPayments = ['Cash', 'BPI QR', 'Gcash QR'];
    if (!in_array($paymentMethod, $allowedPayments, true)) {
        $paymentMethod = 'Cash';
    }

    // cart may come from payload or session
    $cart = $data['cart'] ?? ($_SESSION['cart'] ?? []);

    // Optional: add_to indicates appending to an existing order_group_id
    $addTo = trim($data['add_to'] ?? '');

    if (!$customerName) {
        throw new Exception('Missing customer_name.');
    }
    if (empty($cart) || !is_array($cart)) {
        throw new Exception('Cart is empty or invalid.');
    }

    // spice label -> index map
    $spiceMap = [
        'No Spice' => 0, 'NoSpice' => 0, 'none' => 0, '0' => 0,
        'Light' => 1, '1' => 1,
        'Moderate' => 2, '2' => 2,
        'Spicy' => 3, '3' => 3,
        'Extra' => 4, '4' => 4
    ];

    // ---------- TRANSACTION ----------
    $conn->begin_transaction();

    // If appending to existing order, lock and validate it
    $isAppending = false;
    $existingOrder = null;
    if (!empty($addTo)) {
        // Lock the selected order row for update
        $sel = $conn->prepare("SELECT order_group_id, total_price, item_name, quantity, status, paid, payment_method, allergens, allergen_note FROM orders WHERE order_group_id = ? LIMIT 1 FOR UPDATE");
        if (!$sel) throw new Exception("DB error preparing select existing order: " . $conn->error);
        $sel->bind_param("s", $addTo);
        $sel->execute();
        $res = $sel->get_result();
        $existingOrder = $res->fetch_assoc();
        $sel->close();

        if (!$existingOrder) {
            throw new Exception("Target order to append not found: " . htmlspecialchars($addTo));
        }

        $statusLower = strtolower($existingOrder['status'] ?? '');
        $paidFlag = intval($existingOrder['paid'] ?? 0);

        // Allowed when status is NOT cancelled/closed, AND either not completed OR completed but not paid
        if (in_array($statusLower, ['cancelled', 'closed'])) {
            throw new Exception("Cannot append to order in status: " . $existingOrder['status']);
        }
        if ($statusLower === 'completed' && $paidFlag === 1) {
            throw new Exception("Cannot append to an already paid/completed order.");
        }

        $isAppending = true;
        // We'll use this order_group_id for inserted items and final response
        $orderGroupId = $addTo;
    } else {
        // create new unique order group id
        $orderGroupId = uniqid('ORD-');
    }

    // ---------- STOCK CHECK ----------
    // For each menu item in cart, check current stock
    foreach ($cart as $cItem) {
        $name  = $cItem['name'] ?? '';
        $qty   = (int)($cItem['quantity'] ?? $cItem['qty'] ?? 1);

        $itemId = (int)($cItem['id'] ?? 0);
        if (empty($itemId)) {
            throw new Exception("Missing item ID for '{$name}'.");
        }
        $stockRow = $conn->prepare("SELECT stock FROM menu_items WHERE id=? LIMIT 1");
        if (!$stockRow) throw new Exception("DB error preparing stock select: " . $conn->error);
        $stockRow->bind_param("i", $itemId);
        $stockRow->execute();
        $stockRow->bind_result($currentStock);
        if (!$stockRow->fetch()) {
            $stockRow->close();
            throw new Exception("Menu item not found for ID {$itemId} ('{$name}').");
        }
        $stockRow->close();
        if ($qty > $currentStock) {
            throw new Exception("Not enough stock for '{$name}'. Only {$currentStock} left, but you requested {$qty}.");
        }
    }
        // When appending additional items we must NOT update `orders.updated_at` because
        // that timestamp is used to enforce the customer's cancel window. Updating it here
        // would reset the timer and incorrectly allow cancellation again after additions.
        $upd = $conn->prepare("\n            UPDATE orders\n            SET item_name = ?, quantity = ?, total_price = ?, allergens = ?, allergen_note = ?\n            WHERE order_group_id = ?\n        ");
    // ---------- STOCK UPDATE ----------
    // After passed checks, decrement stock in DB
    foreach ($cart as $cItem) {
        $itemId = (int)($cItem['id'] ?? 0);
        $qty = (int)($cItem['quantity'] ?? $cItem['qty'] ?? 1);
        if ($itemId > 0 && $qty > 0) {
            $stmt = $conn->prepare("UPDATE menu_items SET stock = stock - ? WHERE id = ? AND stock >= ?");
            if (!$stmt) throw new Exception("DB error preparing stock update: " . $conn->error);
            $stmt->bind_param("iii", $qty, $itemId, $qty);
            $stmt->execute();
            if ($stmt->affected_rows === 0) {
                $stmt->close();
                throw new Exception("Failed to decrement stock for item ID {$itemId}. It may have been sold out.");
            }
            $stmt->close();
        }
    }

    // ---------- INSERT INTO order_items ----------
    $stmtItem = $conn->prepare("
        INSERT INTO order_items 
        (order_group_id, item_name, quantity, price, total, spice_level, allergens, allergen_note, serving_size)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmtItem) {
        throw new Exception('Failed to prepare order_items statement: ' . $conn->error);
    }

    $grandTotal = 0.0;
    $totalQty = 0;
    $itemsList = [];
    $allergensSummary = [];
    $allergenNotesSummary = [];

    foreach ($cart as $cItem) {
        $name  = $cItem['name'] ?? '';
        $qty   = (int)($cItem['quantity'] ?? $cItem['qty'] ?? 1);
        $price = (float)($cItem['price'] ?? 0.0);

        $itemAllergens    = trim($cItem['allergens'] ?? '');
        $itemAllergenNote = trim($cItem['allergen_note'] ?? '');
        $servingSize      = trim($cItem['selected_type'] ?? '');

        $spiceRaw = $cItem['spice'] ?? 0;
        if (is_numeric($spiceRaw)) {
            $spiceIdx = (int)$spiceRaw;
        } else {
            $spiceTrim = trim((string)$spiceRaw);
            $spiceIdx = $spiceMap[$spiceTrim] ?? 0;
        }
        $spiceIdx = max(0, min(4, $spiceIdx));

        $lineTotal = $price * $qty;
        $grandTotal += $lineTotal;
        $totalQty += $qty;

        $displayName = "{$qty}x {$name}";
        if ($servingSize)   $displayName .= " [Size: " . ucfirst($servingSize) . "]";
        if ($spiceIdx !== "" && $spiceIdx !== null) $displayName .= " [Spice: $spiceIdx]";
        if ($itemAllergens) $displayName .= " [Allergens: $itemAllergens]";
        if ($itemAllergenNote) $displayName .= " [Note: $itemAllergenNote]";
        $itemsList[] = $displayName;
        if ($itemAllergens) $allergensSummary[] = $itemAllergens;
        if ($itemAllergenNote) $allergenNotesSummary[] = $itemAllergenNote;

        // insert into order_items
        $stmtItem->bind_param(
            'ssiddisss',
            $orderGroupId,
            $name,
            $qty,
            $price,
            $lineTotal,
            $spiceIdx,
            $itemAllergens,
            $itemAllergenNote,
            $servingSize
        );
        $stmtItem->execute();
    }

    $stmtItem->close();

    $itemsStr         = implode(', ', $itemsList);
    $allergensJoined  = implode('; ', array_unique($allergensSummary));
    $notesJoined      = implode('; ', array_unique($allergenNotesSummary));

    if ($isAppending) {
        // Merge with existing order row
        $existingItemsStr = trim($existingOrder['item_name'] ?? '');
        $existingTotal = (float)($existingOrder['total_price'] ?? 0.0);
        $existingQty = (int)($existingOrder['quantity'] ?? 0);
        $existingAllergens = trim($existingOrder['allergens'] ?? '');
        $existingNotes = trim($existingOrder['allergen_note'] ?? '');

        $newItemsStr = $existingItemsStr !== '' ? $existingItemsStr . ', ' . $itemsStr : $itemsStr;
        $newTotal = $existingTotal + $grandTotal;
        $newQty = $existingQty + $totalQty;

        // Merge allergens/notes uniquely
        $mergedAllergensArr = array_filter(array_map('trim', array_merge(
            $existingAllergens === '' ? [] : explode(';', $existingAllergens),
            $allergensSummary
        )));
        $mergedAllergensArr = array_unique($mergedAllergensArr);
        $mergedAllergens = implode('; ', $mergedAllergensArr);

        $mergedNotesArr = array_filter(array_map('trim', array_merge(
            $existingNotes === '' ? [] : explode(';', $existingNotes),
            $allergenNotesSummary
        )));
        $mergedNotesArr = array_unique($mergedNotesArr);
        $mergedNotes = implode('; ', $mergedNotesArr);

        $upd = $conn->prepare("
            UPDATE orders
            SET item_name = ?, quantity = ?, total_price = ?, allergens = ?, allergen_note = ?, updated_at = NOW()
            WHERE order_group_id = ?
        ");
        if (!$upd) throw new Exception("Failed to prepare update orders statement: " . $conn->error);
        $upd->bind_param("sidsss", $newItemsStr, $newQty, $newTotal, $mergedAllergens, $mergedNotes, $orderGroupId);
        $upd->execute();
        $upd->close();

        $responseMessage = "Items appended to existing order.";
    } else {
        // Insert new order row
        $stmt = $conn->prepare("
            INSERT INTO orders 
            (order_group_id, customer_name, table_number, item_name, quantity, total_price, payment_method, status, created_at, allergens, allergen_note) 
            VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending', NOW(), ?, ?)
        ");
        if (!$stmt) {
            throw new Exception('Failed to prepare orders statement: ' . $conn->error);
        }

        $stmt->bind_param(
            'sssidssss', // types for: orderGroupId, customerName, tableNumber, itemsStr, totalQty, grandTotal, paymentMethod, allergensJoined, notesJoined
            $orderGroupId,
            $customerName,
            $tableNumber,
            $itemsStr,
            $totalQty,
            $grandTotal,
            $paymentMethod,
            $allergensJoined,
            $notesJoined
        );
        $stmt->execute();
        $stmt->close();

        $responseMessage = "Order placed successfully.";
    }

    $conn->commit();

    // clear session cart
    $_SESSION['cart'] = [];

    echo json_encode([
        'status'   => 'success',
        'order_id' => $orderGroupId,
        'total'    => $grandTotal,
        'qty'      => $totalQty,
        'appended' => $isAppending ? 1 : 0,
        'message'  => $responseMessage
    ]);
    exit;
} catch (Exception $e) {
    if (isset($conn)) {
        @$conn->rollback();
    }
    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage()
    ]);
    exit;
}
?>