<?php
session_start();
header('Content-Type: application/json');
// Add before normal update logic (near top)
if (isset($_POST['clear_cart']) && $_POST['clear_cart'] == '1') {
    $_SESSION['cart'] = [];
    echo json_encode(["success"=>true]);
    exit;
}
$in = file_get_contents("php://input");
$json = json_decode($in,true);
if (is_array($json) && isset($json['bulk_update'])) {
    // replace cart with provided data
    $_SESSION['cart'] = $json['cart'] ?? [];
    echo json_encode(["success"=>true]);
    exit;
}
// Validate request
if (!isset($_POST['id'], $_POST['quantity'])) {
    echo json_encode(["success" => false, "msg" => "Invalid request (missing id/quantity)"]);
    exit;
}

$id = $_POST['id'];
$newQty = (int)$_POST['quantity'];

// Exists in cart?
if (!isset($_SESSION['cart'][$id])) {
    echo json_encode(["success" => false, "msg" => "Item not found in cart"]);
    exit;
}

// Stock check
$item = $_SESSION['cart'][$id];
$stock = isset($item['stock']) ? intval($item['stock']) : 1000;

// Clamp requested quantity to stock (and floor 0)
if ($stock <= 0) {
    // Out of stock, cannot update quantity!
    echo json_encode(["success" => false, "msg" => "This item is out of stock, you cannot add more."]);
    exit;
}
if ($newQty > $stock) {
    echo json_encode(["success" => false, "msg" => "This item exceeds its available stock. Only $stock left."]);
    exit;
}
if ($newQty < 0) $newQty = 0;

// Remove from cart if quantity is zero
if ($newQty === 0) {
    unset($_SESSION['cart'][$id]);
    $newQtyResp = 0;
} else {
    $_SESSION['cart'][$id]['quantity'] = $newQty;
    $newQtyResp = $newQty;
}

// Recalculate total
$newTotal = 0;
if (!empty($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $citem) {
        $newTotal += ($citem['price'] ?? 0) * ($citem['quantity'] ?? 1);
    }
}

echo json_encode([
    "success" => true,
    "newQty"   => $newQtyResp,
    "newTotal" => $newTotal
]);