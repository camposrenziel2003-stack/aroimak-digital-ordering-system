<?php
header('Content-Type: application/json');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include "../config.php";

// load logger (expects ../includes/activity_logger.php)
$loggerPath = __DIR__ . '/../includes/activity_logger.php';
if (is_readable($loggerPath)) require_once $loggerPath;

// Helper: get numeric order id from order_group_id
function getNumericOrderId($conn, $order_group_id) {
    $stmt = $conn->prepare("SELECT id FROM orders WHERE order_group_id = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param("s", $order_group_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();
    return $row['id'] ?? null;
}

// Get JSON payload
$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);
if (!$data) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid input",
        "php_error" => error_get_last(),
        "raw_input" => $rawInput
    ]);
    exit;
}

// Extract order fields
$customerName = $data["customerName"] ?? '';
$tableNumber  = $data["tableNumber"] ?? '';
$cart         = $data["cart"] ?? [];
$allergens    = $data["allergens"] ?? [];
$allergenNote = $data["allergenNote"] ?? '';
$status = $data["status"] ?? 'Pending';
$takeout      = isset($data["takeOut"]) ? (int)$data["takeOut"] : (isset($data["takeout"]) ? (int)$data["takeout"] : 0);
$editingOrderGroupId = $data["editingOrderGroupId"] ?? '';
$paid         = isset($data['paid']) ? (int)$data['paid'] : 0;
$discountPercent = isset($data['discountPercent']) ? (float)$data['discountPercent'] : 0.0;

// Basic validation
if (empty($customerName) || (empty($tableNumber) && !$takeout) || empty($cart)) {
    echo json_encode(["success" => false, "message" => "Missing required fields", "php_error" => error_get_last()]);
    exit;
}

$allergensStr = is_array($allergens) ? implode(", ", $allergens) : $allergens;

// STEP 1 — If editingOrderGroupId is NOT set, check for today's unpaid order for this table/customer
if (empty($editingOrderGroupId)) {
    $stmtFind = $conn->prepare("SELECT order_group_id FROM orders WHERE table_number = ? AND customer_name = ? AND paid = 0 AND DATE(created_at) = CURDATE() LIMIT 1");
    $stmtFind->bind_param("ss", $tableNumber, $customerName);
    $stmtFind->execute();
    $resFind = $stmtFind->get_result();
    if ($row = $resFind->fetch_assoc()) {
        $editingOrderGroupId = $row['order_group_id'];
        $orderGroupId = $editingOrderGroupId;
    } else {
        $orderGroupId = uniqid("ORD-");
    }
    $stmtFind->close();
} else {
    $orderGroupId = $editingOrderGroupId;
}

// Fetch current status for this order group to prevent improper status transitions
$currentStatus = null;
if (!empty($editingOrderGroupId)) {
    $stmtStatus = $conn->prepare("SELECT status FROM orders WHERE order_group_id = ? LIMIT 1");
    $stmtStatus->bind_param("s", $editingOrderGroupId);
    $stmtStatus->execute();
    $resStatus = $stmtStatus->get_result();
    if ($rowStatus = $resStatus->fetch_assoc()) {
        $currentStatus = $rowStatus['status'];
    }
    $stmtStatus->close();
}


// Calculate item list, total price, and quantity
$totalPriceAll = 0;
$itemsList = [];
$totalQty = 0;

foreach ($cart as $item) {
    $itemName   = $item["name"];
    $quantity   = (int)$item["qty"];
    $price      = (float)$item["price"];
    $totalPrice = $price * $quantity;
    $spiceLevel = isset($item["spiceValue"]) ? $item["spiceValue"] : (isset($item["spice_level"]) ? $item["spice_level"] : 0);
    $servingSize = isset($item["servingSize"]) ? $item["servingSize"] : (isset($item["serving_size"]) ? $item["serving_size"] : "");
    $itemAllergens = '';
    if (isset($item["allergens"])) {
        $itemAllergens = is_array($item["allergens"]) ? implode(',', $item["allergens"]) : (string)$item["allergens"];
    }
    $itemAllergenNote = '';
    if (isset($item["notes"])) {
        $itemAllergenNote = (string)$item["notes"];
    } elseif (isset($item["allergen_note"])) {
        $itemAllergenNote = (string)$item["allergen_note"];
    }
    $totalPriceAll += $totalPrice;
    $listItem = $quantity . "x " . $itemName;
    if ($spiceLevel !== '' && $spiceLevel !== null) $listItem .= " [Spice: $spiceLevel]";
    if ($servingSize) $listItem .= " [Size: $servingSize]";
    if ($itemAllergens) $listItem .= " [Allergens: $itemAllergens]";
    if ($itemAllergenNote) $listItem .= " [Notes: $itemAllergenNote]";
    $itemsList[] = $listItem;
    $totalQty += $quantity;
}

$itemsStr = implode(", ", $itemsList);

// Apply discount (if any) to the computed total before saving / updating
if ($discountPercent > 0) {
    $factor = 1 - ($discountPercent / 100);
    $totalPriceAll = round($totalPriceAll * $factor, 2);
}

// ---------- MAIN LOGIC ----------
$staff_id = $_SESSION['staff_id'] ?? $_SESSION['admin_id'] ?? 0;
$staff_username = $_SESSION['username'] ?? ($_SESSION['staff_username'] ?? 'Unknown');
$role = $_SESSION['role'] ?? 'cashier';

if (!empty($editingOrderGroupId)) {
    // Fetch original table_number and customer_name for this order group
    $stmtOrig = $conn->prepare("SELECT table_number, customer_name FROM orders WHERE order_group_id = ? LIMIT 1");
    $stmtOrig->bind_param("s", $editingOrderGroupId);
    $stmtOrig->execute();
    $origRes = $stmtOrig->get_result();
    $origRow = $origRes->fetch_assoc();
    $stmtOrig->close();
    $customerName = $origRow['customer_name'];
    $tableNumber = $origRow['table_number'];

    // Only allow update if current status is Pending or On Hold
    if ($currentStatus !== null && !in_array($currentStatus, ["Pending", "On Hold"])) {
        $conn->close();
        echo json_encode(["success" => false, "message" => "Cannot update, order already Preparing/Completed/Canceled."]);
        exit;
    }

    // Remove old items for this order group
    $stmtDelItems = $conn->prepare("DELETE FROM order_items WHERE order_group_id = ?");
    $stmtDelItems->bind_param("s", $editingOrderGroupId);
    $stmtDelItems->execute();
    $stmtDelItems->close();

    // Insert new items
    foreach ($cart as $item) {
        $itemName   = $item["name"];
        $quantity   = (int)$item["qty"];
        $price      = (float)$item["price"];
        $totalPrice = $price * $quantity;
        $spiceLevel = isset($item["spiceValue"]) ? $item["spiceValue"] : (isset($item["spice_level"]) ? $item["spice_level"] : 0);
        $servingSize = isset($item["servingSize"]) ? $item["servingSize"] : (isset($item["serving_size"]) ? $item["serving_size"] : "");
        $itemAllergens = '';
        if (isset($item["allergens"])) {
            $itemAllergens = is_array($item["allergens"]) ? implode(',', $item["allergens"]) : (string)$item["allergens"];
        }
        $itemAllergenNote = '';
        if (isset($item["notes"])) {
            $itemAllergenNote = (string)$item["notes"];
        } elseif (isset($item["allergen_note"])) {
            $itemAllergenNote = (string)$item["allergen_note"];
        }

        $stmtItem = $conn->prepare(
            "INSERT INTO order_items (order_group_id, item_name, quantity, price, total, spice_level, serving_size, allergens, allergen_note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmtItem) {
            echo json_encode(["success" => false, "message" => "SQL error: " . $conn->error, "php_error" => error_get_last()]);
            exit;
        }
        $stmtItem->bind_param("ssiddssss",
            $editingOrderGroupId, $itemName, $quantity, $price, $totalPrice,
            $spiceLevel, $servingSize, $itemAllergens, $itemAllergenNote
        );
        $stmtItem->execute();
        $stmtItem->close();
    }

    if ($paid == 1) $status = "Preparing";

    // Update the order row
    $sql = "UPDATE orders SET 
        customer_name = ?, 
        table_number = ?, 
        item_name = ?, 
        quantity = ?, 
        total_price = ?, 
        status = ?, 
        allergens = ?, 
        allergen_note = ?, 
        takeout = ?,
        paid = ?
        WHERE order_group_id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(["success" => false, "message" => "SQL error: " . $conn->error, "php_error" => error_get_last()]);
        exit;
    }
    $stmt->bind_param(
        "ssssisssisi",
        $customerName,
        $tableNumber,
        $itemsStr,
        $totalQty,
        $totalPriceAll,
        $status,
        $allergensStr,
        $allergenNote,
        $takeout,
        $paid,
        $editingOrderGroupId
    );
    if (!$stmt->execute()) {
        if ($conn->errno == 1062) { // Duplicate entry error code
            echo json_encode([
                "success" => false,
                "message" => "Duplicate order detected. Please check your order details."
            ]);
            exit;
        } else {
            echo json_encode([
                "success" => false,
                "message" => "SQL error: " . $conn->error,
                "php_error" => error_get_last()
            ]);
            exit;
        }
    }
    $stmt->close();

    // log update event (find numeric id)
    if (function_exists('logOrderActivity')) {
        $numericId = getNumericOrderId($conn, $editingOrderGroupId);
        // fall back to 0 if not found
        $numericId = $numericId ? (int)$numericId : 0;
        logOrderActivity($conn, $numericId, $staff_id, $staff_username, $role, 'updated');
        if ($paid == 1) {
            logOrderActivity($conn, $numericId, $staff_id, $staff_username, $role, 'paid');
        }
    }

    $conn->close();
    echo json_encode([
        "success"   => true,
        "group_id"  => $editingOrderGroupId,
        "items"     => $itemsStr ?? null,
        "total_qty" => $totalQty ?? null,
        "total"     => $totalPriceAll ?? null,
        "status"    => $status,
        "allergens" => $allergensStr ?? null,
        "note"      => $allergenNote ?? null,
        "takeout"   => $takeout ?? null,
        "php_error" => error_get_last()
    ]);
    exit;
}

// If editingOrderGroupId is empty (no open order found), INSERT as new order
if (empty($editingOrderGroupId)) {
    if ($paid == 1) {
        $insertStatus = 'Preparing';
    } else if ($status === 'On Hold') {
        $insertStatus = 'On Hold';
    } else {
        $insertStatus = $status;
    }
    $sql = "INSERT INTO orders 
    (order_group_id, customer_name, table_number, item_name, quantity, total_price, status, allergens, allergen_note, takeout, paid, created_at) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
$stmt = $conn->prepare($sql);
$stmt->bind_param(
    "ssssissssii",
    $orderGroupId,
    $customerName,
    $tableNumber,
    $itemsStr,
    $totalQty,
    $totalPriceAll,
    $insertStatus,
    $allergensStr,
    $allergenNote,
    $takeout,
    $paid
);
    if (!$stmt->execute()) {
        if ($conn->errno == 1062) { // Duplicate entry error code
            echo json_encode([
                "success" => false,
                "message" => "Duplicate order detected. Please check your order details."
            ]);
            exit;
        } else {
            echo json_encode([
                "success" => false,
                "message" => "SQL error: " . $conn->error,
                "php_error" => error_get_last()
            ]);
            exit;
        }
    }

    // capture numeric id for logging
    $insertedNumericId = $conn->insert_id;
    $stmt->close();

    // Insert the items for the group
    foreach ($cart as $item) {
        $itemName   = $item["name"];
        $quantity   = (int)$item["qty"];
        $price      = (float)$item["price"];
        $totalPrice = $price * $quantity;
        $spiceLevel = isset($item["spiceValue"]) ? $item["spiceValue"] : (isset($item["spice_level"]) ? $item["spice_level"] : 0);
        $servingSize = isset($item["servingSize"]) ? $item["servingSize"] : (isset($item["serving_size"]) ? $item["serving_size"] : "");
        $itemAllergens = '';
        if (isset($item["allergens"])) {
            $itemAllergens = is_array($item["allergens"]) ? implode(',', $item["allergens"]) : (string)$item["allergens"];
        }
        $itemAllergenNote = '';
        if (isset($item["notes"])) {
            $itemAllergenNote = (string)$item["notes"];
        } elseif (isset($item["allergen_note"])) {
            $itemAllergenNote = (string)$item["allergen_note"];
        }

        $stmtItem = $conn->prepare(
            "INSERT INTO order_items (order_group_id, item_name, quantity, price, total, spice_level, serving_size, allergens, allergen_note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmtItem) {
            echo json_encode(["success" => false, "message" => "SQL error: " . $conn->error, "php_error" => error_get_last()]);
            exit;
        }
        $stmtItem->bind_param("ssiddssss",
            $orderGroupId, $itemName, $quantity, $price, $totalPrice,
            $spiceLevel, $servingSize, $itemAllergens, $itemAllergenNote
        );
        $stmtItem->execute();
        $stmtItem->close();
    }

    // Logging: created (+ paid if applicable)
    if (function_exists('logOrderActivity')) {
        $numeric = $insertedNumericId ? (int)$insertedNumericId : 0;
        logOrderActivity($conn, $numeric, $staff_id, $staff_username, $role, 'created');
        if ($paid == 1) {
            logOrderActivity($conn, $numeric, $staff_id, $staff_username, $role, 'paid');
        }
    }

    $conn->close();
    echo json_encode([
        "success"   => true,
        "group_id"  => $orderGroupId ?? null,
        "items"     => $itemsStr ?? null,
        "total_qty" => $totalQty ?? null,
        "total"     => $totalPriceAll ?? null,
        "status"    => $insertStatus ?? $status,
        "allergens" => $allergensStr ?? null,
        "note"      => $allergenNote ?? null,
        "takeout"   => $takeout ?? null,
        "php_error" => error_get_last()
    ]);
    exit;
}
?>