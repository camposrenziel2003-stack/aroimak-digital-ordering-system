<?php
session_start();
include "config.php";
// Calculate the cart total using the SAME logic as in kiosk_home.php, respecting selected_type!
$cartTotal = 0;
foreach ($_SESSION['cart'] as $c) {
    $dishId = $c['id'];
    $quantity = $c['quantity'];
    $selectedType = strtolower($c['selected_type'] ?? '');
    $res = $conn->query("
        SELECT mi.price, mi.price_solo, mi.price_sharing, c.name AS category_name
        FROM menu_items mi
        LEFT JOIN categories c ON mi.category = c.id
        WHERE mi.id = $dishId
    ");
    if ($res && $row = $res->fetch_assoc()) {
        $price = $row['price'];
        if (in_array(strtolower($row['category_name']), ['fried rice', 'noodles'])) {
            if ($selectedType === 'sharing' && !empty($row['price_sharing']) && $row['price_sharing'] > 0) {
                $price = $row['price_sharing'];
            } elseif ($selectedType === 'solo' && !empty($row['price_solo']) && $row['price_solo'] > 0) {
                $price = $row['price_solo'];
            }
        }
        $cartTotal += $price * $quantity;
    }
}
echo json_encode(['total' => $cartTotal]);
?>