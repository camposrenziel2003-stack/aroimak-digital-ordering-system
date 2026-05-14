<?php 
session_start();
include "config.php";
include "access_check.php";
if (isset($_GET['add_to']) && $_GET['add_to'] !== '') {
    $_SESSION['add_to_order_group_id'] = $_GET['add_to'];
}
// Remove the else/unset part!
// 🛒 Initialize cart
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}


// 🛒 Clear cart if "Start Over" pressed
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear') { 
    $_SESSION['cart'] = []; 
    // Tiyakin na clear din ang add_to order group ID
    unset($_SESSION['add_to_order_group_id']); 
    header("Location: kiosk_home.php?ref=kiosk_home.php"); 
    exit;
}

$cartIsEmpty = count($_SESSION['cart']) === 0;



// 🛒 Calculate total & fetch cart items with latest price
$cartTotal = 0;
$cartItems = [];
foreach ($_SESSION['cart'] as $c) {
    $dishId = $c['id'];
    $quantity = $c['quantity'];
    $selectedType = strtolower($c['selected_type'] ?? '');
    $res = $conn->query("
        SELECT mi.name, mi.price, mi.price_solo, mi.price_sharing, c.name AS category_name
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

        $cartItems[] = [
            'name' => $row['name'],
            'price' => $price,
            'quantity' => $quantity,
            'subtotal' => $price * $quantity
        ];
        $cartTotal += $price * $quantity;
    }
}

$currentCategoryId = isset($_GET['category']) ? (int)$_GET['category'] : 0;

// Fetch categories
$categories = [];
$res = $conn->query("SELECT id, name, image FROM categories");
while ($row = $res->fetch_assoc()) {
    $categories[] = $row;
}

// Fetch menu items
$menuItems = [];
$res = $conn->query("
    SELECT mi.*, c.id AS category_id, c.name AS category_name
    FROM menu_items mi
    LEFT JOIN categories c ON mi.category = c.id
    ORDER BY mi.name ASC
");
while ($row = $res->fetch_assoc()) {
    $menuItems[] = $row;
}

// --- Get the Top 10 Most Popular Dishes, Excluding Drinks (Same as admin's panel) ---
$drinksCatId = null;
$resDrinksCat = $conn->query("SELECT id FROM categories WHERE name = 'Drinks' LIMIT 1");
if ($resDrinksCat && $drinksRow = $resDrinksCat->fetch_assoc()) {
    $drinksCatId = $drinksRow['id'];
}

$topDishes = [];
if ($drinksCatId !== null) {
    $popResult = $conn->query("
        SELECT order_items.item_name
        FROM order_items
        LEFT JOIN menu_items ON order_items.item_name = menu_items.name
        WHERE menu_items.category != $drinksCatId
        GROUP BY order_items.item_name
        ORDER BY SUM(order_items.quantity) DESC
        LIMIT 10
    ");
} else {
    $popResult = $conn->query("
        SELECT order_items.item_name
        FROM order_items
        GROUP BY order_items.item_name
        ORDER BY SUM(order_items.quantity) DESC
        LIMIT 10
    ");
}
while ($popRow = $popResult->fetch_assoc()) {
    $topDishes[] = $popRow['item_name'];
}

// --- Sort menu items: Admin Top 10 First (matching order), then the rest, available first in each group ---
$topMenu = [];
$otherMenu = [];

foreach ($menuItems as $item) {
    $pos = array_search($item['name'], $topDishes);
    if ($pos !== false && $pos !== null) {
        $topMenu[$pos] = $item; // keep sort index
    } else {
        $otherMenu[] = $item;
    }
}
ksort($topMenu); // preserves admin popular order

// available/unavailable split
$availTopMenu = [];
$unavailTopMenu = [];
foreach ($topMenu as $item) {
    if ((int)$item['stock'] > 0) $availTopMenu[] = $item;
    else $unavailTopMenu[] = $item;
}
$availOtherMenu = [];
$unavailOtherMenu = [];
foreach ($otherMenu as $item) {
    if ((int)$item['stock'] > 0) $availOtherMenu[] = $item;
    else $unavailOtherMenu[] = $item;
}

$displayMenuItems = array_merge($availTopMenu, $availOtherMenu, $unavailTopMenu, $unavailOtherMenu);

// Fetch promo images
$promoImages = [];
$res = $conn->query("SELECT file_name FROM promo_images ORDER BY id DESC");
while ($row = $res->fetch_assoc()) {
    $promoImages[] = "/thai_digital/uploads/promo/" . rawurlencode($row['file_name']); 
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Kiosk Menu</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
body {
    margin: 0;
    font-family: 'Segoe UI', Tahoma, sans-serif;
    background-color: #FAE9D1;
    height: 100vh;
    width: 100vw;
    box-sizing: border-box;
    /* For the fixed footer */
    padding-bottom: 90px;
}
.top-banner {
    grid-column: 1 / span 2;
    grid-row: 1;
    z-index: 1000;
    height: 200px;
    overflow: hidden;
}
.carousel { position: relative; width: 100%; height: 100%; }
.carousel img {
    width: 100%; height: 200px; object-fit: cover;
    position: absolute; top: 0; left: 0;
    opacity: 0; transition: opacity 1s ease-in-out;
}
.carousel img.active { opacity: 1; }

/* === NEW SEARCH BAR STYLES === */
.search-container {
    width: 100%;
    background-color: #FAE9D1;
    padding: 9px 0;
    display: flex;
    justify-content: center;
    align-items: center;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}
#searchBar {
    width: 45%;
    max-width: 600px;
    padding: 15px 25px 15px 50px;
    font-size: 20px;
    border: 2px solid #FF9800;
    border-radius: 30px;
    outline: none;
    box-shadow: 0 4px 10px rgba(255, 140, 0, 0.1);
    transition: box-shadow 0.2s, border-color 0.2s;
    background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="%23FF9800" d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>') no-repeat 15px center;
    background-size: 24px 24px;
}
#searchBar:focus {
    border-color: #E65100;
    box-shadow: 0 6px 15px rgba(255, 140, 0, 0.2);
}
/* END NEW SEARCH BAR STYLES */

.container {
    display: flex;
    flex-direction: row;
    width: 100%;
    background: none;
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    /* Adjusted min-height for search bar */
    min-height: calc(100vh - 200px - 80px - 60px); 
}

.sidebar {
    width: 240px;
    /* Adjusted height for search bar */
    height: calc(100vh - 200px - 80px - 60px);
    overflow-y: auto;
    background: #FAE9D1;
    border-right: 2px solid #eee;
    padding: 30px 30px;
    box-sizing: border-box;
}
/* Sidebar - bigger for tablets */
@media (min-width: 700px) and (max-width: 1100px) {
    .sidebar {
        width: 190px;
        min-width: 190px;
        max-width: 220px;
        padding: 24px 10px;
    }
    .sidebar .logo img {
        height: 60px;
    }
    .category {
        padding: 9px 12px 9px 14px;
        font-size: 17px;
    }
}

/* Make menu cards narrower for iPad Air/Pro/Mini */
@media (min-width: 700px) and (max-width: 1100px) {
    .menu {
        grid-template-columns: repeat(2, minmax(150px, 1fr));
        gap: 18px;
        padding-left: 8px;
        padding-right: 8px;
    }
    .menu-item {
        max-width: 220px;
        min-width: 0;
        height: 370px !important;
        min-height: 370px !important;
        max-height: 370px !important;
        font-size: 16px;
    }
    .menu-item img {
        height: 150px !important;
        min-height: 120px !important;
        max-height: 170px !important;
        aspect-ratio: 4/3;
        object-fit: contain !important;
    }
}
.sidebar .logo { text-align: center; margin-bottom: 15px; }
.sidebar .logo img { height: 70px; }
.sidebar h2 { text-align: center; margin: 5px 0 15px; font-size: 100px; font-weight: bold; color: #391a0dff; }

.category { 
    display: flex; 
    align-items: center; 
    background: white; 
    margin: 10px 0px 10px 12px;
    padding: 10px 18px 10px 22px;
    border-radius: 20px; 
    box-shadow: 0 3px 14px 1px rgba(255, 140, 0, 0.12), 0 2px 8px rgba(0,0,0,0.11); 
    cursor: pointer; 
    transition: box-shadow 0.18s, background 0.2s, color 0.18s;
}
.category:hover { 
    background: #ffe0b2;
    box-shadow: 0 8px 28px 2px rgba(255, 140, 0, 0.17), 0 4px 12px rgba(0,0,0,0.15);
}
.category.active {
    background: orange;
    color: white;
    box-shadow: 0 8px 32px rgba(255, 140, 0, 0.20), 0 5px 16px rgba(0,0,0,0.18);
}
.category.active span {
    color: white;
}
.category img { height: 42px; width: 50px; margin-right: 12px; object-fit: contain; }
.category span { font-size: 18px; font-weight: bold; color: #391a0dff; }

@media (max-width: 767px) {
    .category {
        margin: 8px 0 8px 7px;
        padding: 8px 13px 8px 15px;
    }
    .sidebar {
        width: 100%;
        height: auto;
        border-right: none;
        border-bottom: 2px solid #eee;
        padding: 10px 3px;
    }
}

/* ====== MENU GRID ====== */
.menu {
    flex: 1 1 0;
    margin-top: 20px;
    display: grid;
    grid-template-columns: repeat(2, 1fr); /* Always 2 columns by default */
    gap: 25px;
    padding-left: 25px;
    padding-right: 15px;
    box-sizing: border-box;
    /* Adjusted height for search bar */
    height: calc(100vh - 200px - 80px - 40px - 60px); 
    overflow-y: auto;
    overflow-x: hidden;
    justify-content: start;
    align-content: start;
}

/* Always 2 columns in portrait, 3 in landscape */
.menu {
    display: grid;
    grid-template-columns: repeat(2, minmax(280px, 1fr));
    gap: 28px;
    padding: 2vw;
    box-sizing: border-box;
    width: 100%;
}

@media (orientation: landscape) and (min-width: 900px) {
    .menu {
        grid-template-columns: repeat(3, minmax(280px, 1fr));
    }
}

/* === iPad Pro/Mini/Air Portrait: fix card and image height === */
@media (min-width: 700px) and (max-width: 1400px) and (orientation: portrait) {
    .menu-item {
        height: 420px !important;
        min-height: 420px !important;
        max-height: 420px !important;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        justify-content: flex-start;
    }
    .menu-item img {
        height: 250px !important;
        min-height: 250px !important;
        max-height: 250px !important;
        width: 100% !important;
        aspect-ratio: 4/3;
        object-fit: contain !important;
        border-radius: 20px;
        margin-bottom: 0px;
        background: #fffce8;
        border: 2px solid #fff7e0;
        box-shadow: 0 4px 16px rgba(255,140,0,0.13);
        display: block;
    }
}

/* Fallback for other screens */
.menu-item {
    background: #fff;
    border-radius: 32px;
    box-shadow:
        0 8px 32px rgba(255, 140, 0, 0.10),
        0 2px 4px rgba(0,0,0,0.09),
        0 1.5px 5px 0px rgba(255, 109, 0, 0.13);
    padding: 18px 14px 14px 14px;
    text-align: center;
    align-items: center;
    cursor: pointer;
    transition: box-shadow 0.2s, transform 0.15s;
    min-width: 0;
    width: 100%;
    max-width: 420px;
    margin: 0 auto;
    box-sizing: border-box;
    min-height: 370px;
    height: 420px;
    overflow: hidden;
}

.menu-item img {
    width: 100%;
    max-width: 340px;
    height: 270px;
    min-height: 120px;
    aspect-ratio: 4/3;
    object-fit: contain;
    object-position: center center;
    border-radius: 20px;
    background: #fffce8;
    border: 2px solid #fff7e0;
    box-shadow: 0 4px 16px rgba(255,140,0,0.13);
    margin-bottom: 8px;
    display: block;
}


/* ====== ENHANCED MENU CARD ====== */
.menu-item {
    background: #fff;
    border-radius: 32px;
    box-shadow: 
        0 8px 32px rgba(255, 140, 0, 0.10),
        0 2px 4px rgba(0,0,0,0.09),
        0 1.5px 5px 0px rgba(255, 109, 0, 0.13);
    padding: 18px 14px 14px 14px;
    text-align: center;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: flex-start;
    cursor: pointer;
    transition: box-shadow 0.2s, transform 0.15s;
    height: 390px;
    min-width: 0;
    max-width: 370px;
    width: 100%;
    margin: 0 auto;
    box-sizing: border-box;
}
.menu-item img {
    width: 100%;
    max-width: 340px;
    height: 235px;
    min-height: 100px;
    border-radius: 20px;
    object-fit: cover;
    object-position: center center;
    box-shadow: 0 4px 16px rgba(255, 140, 0, 0.13);
    background: #fffce8;
    border: 2px solid #fff7e0;
    margin-bottom: 0px;
}
.menu-item:hover { 
    transform: scale(1.045) translateY(-12px);
    box-shadow:
        0 20px 48px 0 rgba(255, 140, 0, 0.18),
        0 6px 30px rgba(255,186,58,0.24),
        0 0 0 4px #ff9800aa;
    z-index: 3;
    border: 2.5px solid #FF9800;
    background: linear-gradient(110deg, #fff9e6 85%, #ffd54f 100%);
}
.menu-item h3 { 
    font-size: 22px; 
    font-weight: bold; 
    color: #391a0dff;
    letter-spacing: 0.5px;
    text-shadow: 0 1px 0 #fff8e3;
}
.menu-item .price { 
    font-size: 19px;
    font-weight: 700; 
    color: #FF3D00;
    margin-bottom: 0px;
    letter-spacing: 0.2px;
    text-shadow: 0 1px 0 #fff7e1;
}
.unavailable { opacity: 0.5; cursor: not-allowed; }
.unavailable:hover { background: white !important; }
.unavailable .price { color: red; font-weight: bold; }
/* Keep the rest of your footer/modal CSS unchanged */

#pageTitle {
    text-align: center;
    margin: 10px 0 15px;
    font-size: 18px;
    font-weight: bold;
    text-transform: uppercase;
    color: #333;
}
.sidebar,
.menu {
    scrollbar-width: thin;
    scrollbar-color: #FAE9D1 #FAE9D1;
}

.footer {
    position: fixed;
    left: 0;
    bottom: 0;
    width: 100%;
    background: #FFF3E0;
    box-shadow: 0 -2px 6px rgba(0,0,0,0.2);
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 30px;
    height: 80px;
    padding: 0 12px;
    z-index: 1001;
}
.footer .total {
    font-size: 30px; font-weight: bold;
    color:#E64A19;
    display: flex; align-items: center; gap: 10px;
    background: linear-gradient(135deg, #fffbe9 40%, #ffd582ff 100%);
        border: 2px solid #ffd54f;
        border-radius: 30px;
        padding: 5px 20px;
}
.footer .total i {
    font-size: 26px;
    background: linear-gradient(135deg, #ff9800, #ff6d00);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}
.footer .actions { display: flex; gap: 30px; }

.checkout-btn {
    padding: 13px 25px;
    font-size: 30px;
    font-weight: bold;
    border-radius: 30px;
    cursor: pointer;
    transition: box-shadow 0.18s, background 0.22s, transform 0.16s;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 20px;
    border: none;
    margin: 0 2px;
    box-shadow: 0 3px 14px 1px rgba(255, 140, 0, 0.14), 0 2px 8px rgba(0,0,0,0.11);
}
.clear-btn{
    margin-right: 30px;
    padding: 13px 20px;
    background: #fffbe9;
    color: #ff9800;
    border: 2px solid #ffd54f;
    font-size: 20px;
    border-radius: 50px;
    font-weight: 500;
    box-shadow: none;
    opacity: 0.85;
    filter: grayscale(0.22);
    transition: background 0.18s, color 0.18s, opacity 0.18s, filter 0.18s;
    box-shadow: 0 3px 14px 1px rgba(255, 140, 0, 0.14), 0 2px 8px rgba(0,0,0,0.11);
}
.checkout-btn {
    background: linear-gradient(135deg, #ff9800, #ff6d00);
    color: white;
    box-shadow: 0 7px 24px rgba(255, 140, 0, 0.15), 0 2px 7px rgba(0,0,0,0.13);
    font-size: 30px;
}

.checkout-btn:hover, .checkout-btn:focus {
    background: linear-gradient(135deg, #ffb74d, #ff9100);
    transform: scale(1.08) translateY(-2px);
    box-shadow: 0 16px 32px rgba(255, 140, 0, 0.22), 0 2px 12px rgba(0,0,0,0.18);
}

.checkout-btn:active {
    transform: scale(0.96);
}

.clear-btn {
    background: linear-gradient(135deg, #fffbe9 40%, #ffd582ff 100%);
    color: #ff9100;
    border: 2px solid #ffd54f;
    font-size: 25px;
}

.clear-btn:hover, .clear-btn:focus {
    background: #fff6e3;
    color: #e64a19;
    border-color: #FF9800;
    transform: scale(1.07) translateY(-2px);
    box-shadow: 0 12px 28px rgba(255, 140, 0, 0.17), 0 2px 8px rgba(0,0,0,0.13);
}

.clear-btn:active {
    transform: scale(0.96);
}

.checkout-btn:disabled,
.checkout-btn[disabled],
.clear-btn:disabled,
.clear-btn[disabled] {
    opacity: 0.5;
    cursor: not-allowed;
    pointer-events: none;
    box-shadow: none;
}

#startOverBtn {
    padding: 13px 20px;
    background: linear-gradient(135deg, #fffbe9 40%, #ffd582ff 100%);
    color: #ff9800;
    border: 2px solid #ffd54f;
    font-size: 20px;
    border-radius: 50px;
    font-weight: 500;
    box-shadow: none;
    opacity: 0.85;
    filter: grayscale(0.22);
    transition: background 0.18s, color 0.18s, opacity 0.18s, filter 0.18s;
    box-shadow: 0 3px 14px 1px rgba(255, 140, 0, 0.14), 0 2px 8px rgba(0,0,0,0.11);
}
#startOverBtn:hover, #startOverBtn:focus {
    background: #fffde7;
    color: #e65100;
    opacity: 1;
    filter: grayscale(0);
    border-color: #FF9800;
}
#clearCartModal .btn,
#clearCartModal .clear-btn {
    padding: 17px 35px;
    font-size: 18px;
    font-weight: bold;
    border-radius: 22px;
    margin: 0 6px;
    box-shadow: 0 6px 20px rgba(255,190,40,0.13), 0 2px 8px rgba(0,0,0,0.08);
    transition: box-shadow 0.18s, background 0.18s, color 0.17s, transform 0.14s;
    border: none;
}
#clearCartModal .btn:hover,
#clearCartModal .clear-btn:hover {
    background: #fffbe9;
    color: #ff9100;
    box-shadow: 0 14px 28px rgba(255,190,40,0.17), 0 4px 12px rgba(0,0,0,0.11);
    transform: scale(1.05) translateY(-2px);
}
#clearCartModal #confirmClear {
    background: #E53935;
    color: #fff;
}
#clearCartModal #confirmClear:hover {
    background: #D32F2F;
    color: #fff;
}
#clearCartModal #cancelClear {
    background: #faf6e9ff;
    color: #d2511e;
    box-shadow: 0 14px 28px rgba(255,190,40,0.17), 0 4px 12px rgba(0,0,0,0.11);
}
#clearCartModal #cancelClear:hover {
    background: #ffe082;
    color: #e64a19;
}

/* Modal buttons use the same enhanced style */
.modal-content .btn, .modal-content .clear-btn {
    padding: 16px 34px;
    border-radius: 20px;
    font-size: 17px;
    font-weight: bold;
    box-shadow: 0 2px 8px rgba(255,152,0,0.09);
}
@media (max-width: 1200px) {
    .footer {
        gap: 10px;
        padding: 0 3px;
    }
    .total{
        padding:12px 34px;
    }
    .checkout-btn, .clear-btn {
        font-size: 20px;
        padding: 12px 34px;
    }
    .modal-content .btn, .modal-content .clear-btn {
        font-size: 16px;
        padding: 13px 20px;
    }
}
@media (max-width: 767px) {
    .footer {
        gap: 9px;
        padding: 0 1px;
        height: auto;
        flex-wrap: wrap;
    }
    .checkout-btn, .clear-btn {
        font-size: 15px;
        padding: 10px 9vw;
        border-radius: 16px;
        gap: 7px;
    }
    .modal-content .btn, .modal-content .clear-btn {
        font-size: 14px;
        padding: 10px 10px;
        border-radius: 14px;
    }
}

#pageTitle {
    text-align: center;
    margin: 10px 0 15px;
    font-size: 18px;
    font-weight: bold;
    text-transform: uppercase;
    color: #333;
}
.sidebar,
.menu {
    scrollbar-width: thin;
    scrollbar-color: #FAE9D1 #FAE9D1;
}
.modal {
    display: none; 
    position: fixed;
    z-index: 2000;
    left: 0; top: 0;
    width: 100%; height: 100%;
    background: rgba(0,0,0,0.4);
    justify-content: center;
    align-items: center;
}
.modal-content {
    background: #fff;
    border-radius: 16px;
    padding: 30px 25px;
    text-align: center;
    max-width: 360px;
    width: 100%;
    box-shadow: 0 8px 20px rgba(0,0,0,0.2);
    animation: scaleIn 0.3s ease-out;
}
.modal-content h2 { margin:0 0 30px; font-size:25px; color:#333; }
@keyframes scaleIn {
    from { transform: scale(0.8); opacity:0; }
    to { transform: scale(1); opacity:1; }
}
.top-banner.grayed {
    filter:brightness(0.75);
    transition: filter 0.2s;
    pointer-events: none;
}
    </style>
</head>
<body>

<div class="top-banner">
    <div class="carousel" id="carousel">
        <?php if (count($promoImages) > 0): ?>
            <?php foreach ($promoImages as $i => $img): ?>
                <img src="<?= $img ?>" class="<?= $i === 0 ? 'active' : '' ?>" alt="Promo">
            <?php endforeach; ?>
        <?php else: ?>
            <img src="images/summer 2.png" class="active" alt="Default Banner">
        <?php endif; ?>
    </div>
</div>

<div class="search-container">
    <input type="text" id="searchBar" placeholder="Search for a dish...">
</div>
<div class="container">
    <div class="sidebar">
        <div class="logo"><img src="images/logo.png" alt="Logo"></div>
        <div id="pageTitle">HOME</div>

        <div class="category" data-id="0" onclick="selectCategory(0)">
            <img src="images/all_5622900.png" alt="All">
            <span>All</span>
        </div>
        <?php foreach ($categories as $cat): 
            $catImg = !empty($cat['image']) ? "/thai_digital/uploads/" . rawurlencode($cat['image']) : "assets/img/placeholder.png";?>
            <div class="category" data-id="<?= (int)$cat['id'] ?>" onclick="selectCategory(<?= (int)$cat['id'] ?>)">
                <img src="<?= $catImg ?>" alt="<?= htmlspecialchars($cat['name']) ?>">
                <span><?= htmlspecialchars($cat['name']) ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="menu" id="menu-container">
    <?php foreach ($displayMenuItems as $item):
        $itemImg = !empty($item['image']) ? "/thai_digital/uploads/" . rawurlencode($item['image']) : "assets/img/placeholder.png";
        $stock = (int)($item['stock'] ?? 0);
        $isAvailable = $stock > 0;
        ?>
            <div class="menu-item <?= $isAvailable ? '' : 'unavailable' ?>" 
                data-category="<?= (int)$item['category_id'] ?>"
                data-name="<?= htmlspecialchars(strtolower($item['name'])) ?>"
                <?php if ($isAvailable): ?>
                    onclick="window.location.href='menu_item_detail.php?id=<?= (int)$item['id'] ?>&ref=kiosk_home.php?category=<?= $currentCategoryId ?>'"
                <?php endif; ?>>
                <img src="<?= $itemImg ?>" alt="<?= htmlspecialchars($item['name']) ?>">
                <h3>
        <?= htmlspecialchars($item['name']) ?>
        <?php
        $catName = strtolower($item['category_name']);
        if (in_array($item['name'], $topDishes) && $catName !== 'drinks'): ?>
            <span style="font-size: 1.5em; color: #FF5722;" title="Popular Dish">&#x1F525;</span>
        <?php endif; ?>
    </h3>
    <?php
    $priceDisplay = "";
    if (in_array(strtolower($item['category_name']), ['fried rice', 'noodles'])) {
        $parts = [];
        if (!empty($item['price_solo']) && $item['price_solo'] > 0) {
            $parts[] = "₱" . number_format((float)$item['price_solo'], 2) . " (Solo)";
        }
        if (!empty($item['price_sharing']) && $item['price_sharing'] > 0) {
            $parts[] = "₱" . number_format((float)$item['price_sharing'], 2) . " (Sharing)";
        }
        $priceDisplay = implode(" / ", $parts);
    } else {
        $priceDisplay = "₱" . number_format((float)$item['price'], 2);
    }
    ?>
    <div class="price">
        <?= $isAvailable ? $priceDisplay : "Unavailable at the moment" ?>
    </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<div id="inactivityPopup" style="
    display:none;
    position:fixed;
    top:50%;
    left:50%;
    transform:translate(-50%, -50%);
    background:#fffbe9;
    color:#d2511e;
    border:2px solid #FF9800;
    border-radius:22px;
    box-shadow:0 6px 24px rgba(255,140,0,0.11);
    padding:32px 40px;
    font-size:28px;
    font-weight:700;
    z-index:3000;
    text-align:center;
">
    <div>
        Session expired due to inactivity.<br>
        Returning to home screen in <span id="countdownNum">10</span> seconds...
    </div>
    <button id="cancelInactivityBtn" style="
        margin-top:18px;
        padding:10px 32px;
        font-size:22px;
        background:linear-gradient(135deg,#FFD54F,#FF9800);
        color:#fff;
        border:none;
        border-radius:12px;
        box-shadow:0 2px 10px rgba(255,140,0,0.07);
        cursor:pointer;
        font-weight:600;
    ">Cancel</button>
</div>

<div class="footer">
<form method="post" id="clearCartForm" style="margin:0; display: inline;">
    <input type="hidden" name="action" value="clear">
    <button type="button" class="clear-btn" id="startOverBtn" style="margin-right:8px;">Start Over</button>
    <button type="button" class="clear-btn" id="clearCartBtn" <?= $cartIsEmpty ? 'disabled style="opacity:0.5;cursor:not-allowed;"' : '' ?> >
        Clear Cart
    </button>
</form>

<div id="clearCartModal" class="modal">
    <div class="modal-content">
        <h2>Are you sure you want to remove all items from your cart?</h2>
        <button id="confirmClear" class="btn" >Yes, Clear</button>
        <button id="cancelClear" class="btn" >Cancel</button>
    </div>
</div>

<div id="startOverModal" class="modal">
    <div class="modal-content">
        <h2>
            Going back to the main page will clear your current cart and reset your session.<br>
            Are you sure you want to start over?
        </h2>
        <button id="confirmStartOver" class="btn clear-btn" type="button">Yes, Start Over</button>
        <button id="cancelStart" class="btn clear-btn" type="button">Cancel</button>
    </div>
</div>

<div class="total">
    <i class="fas fa-shopping-cart"></i>
    PHP <?= number_format($cartTotal, 2) ?>
</div>
<div class="actions">
    <div class="footer-right">
<?php
$cartReviewPage = !empty($_SESSION['add_to_order_group_id']) ? 'review_add_to_order.php' : 'review_order.php';
?>
<button 
    class="checkout-btn" 
    onclick="window.location.href='<?= $cartReviewPage ?>?ref=kiosk_home.php?category=<?= $currentCategoryId ?>'"
    <?= $cartIsEmpty ? 'disabled style="opacity:0.5;cursor:not-allowed;"' : '' ?>
>
    View Cart
</button>
</div>
<script>
const clearCartBtn = document.getElementById("clearCartBtn");
const clearCartModal = document.getElementById("clearCartModal");
const confirmClear = document.getElementById("confirmClear");
const cancelClear = document.getElementById("cancelClear");
const clearCartForm = document.getElementById("clearCartForm");
const startOverModal = document.getElementById("startOverModal");
const searchBar = document.getElementById('searchBar');
const menuItems = document.querySelectorAll('.menu-item');
const categories = document.querySelectorAll('.category');
const allCategory = document.querySelector('.category[data-id="0"]');

let currentActiveCategory = <?= $currentCategoryId ?>; // Track active category ID

// Show Clear Cart modal
clearCartBtn.addEventListener("click", () => {
    if (<?= $cartIsEmpty ? 'true' : 'false' ?>) return;
    clearCartModal.style.display = "flex";
});

// Cancel
cancelClear.addEventListener("click", () => {
    clearCartModal.style.display = "none";
});

// Confirm → submit form
confirmClear.addEventListener("click", () => {
    clearCartForm.submit();
});

// Show Start Over modal
document.getElementById("startOverBtn").onclick = function() {
    document.getElementById("startOverModal").style.display = "flex";
    document.querySelector('.top-banner').classList.add('grayed');
};
// Hide modal on cancel
document.getElementById("cancelStart").onclick = function() {
    document.getElementById("startOverModal").style.display = "none";
    document.querySelector('.top-banner').classList.remove('grayed');
};
// Confirm Start Over
document.getElementById("confirmStartOver").onclick = function() {
    document.body.style.opacity = "0";
    setTimeout(function() {
        window.location.href = 'main.php';
    }, 10);
};

// Simple JS carousel
let currentSlide = 0;
const slides = document.querySelectorAll('#carousel img');
if (slides.length > 1) {
    setInterval(() => {
        slides[currentSlide].classList.remove('active');
        currentSlide = (currentSlide + 1) % slides.length;
        slides[currentSlide].classList.add('active');
    }, 4000);
}

window.onload = function() {
    // Get category from URL
    const urlParams = new URLSearchParams(window.location.search);
    const catId = urlParams.get('category') || '0';
    currentActiveCategory = parseInt(catId);

    // Find sidebar category element
    const activeCat = document.querySelector('.category[data-id="' + catId + '"]');
    if (activeCat) {
        filterCategory(parseInt(catId), activeCat);
    } else {
        filterCategory(0, allCategory);
    }
};

function selectCategory(catId) {
    // Kapag pumili ng category, i-clear ang search bar at i-redirect
    searchBar.value = '';
    const url = new URL(window.location);
    url.searchParams.set('category', catId);
    window.location.href = url.toString();
}

function filterCategory(catId, el) {
    currentActiveCategory = catId;
    const items = document.querySelectorAll('.menu-item');
    const searchTerm = searchBar.value.toLowerCase();

    items.forEach(item => {
        const itemCatId = parseInt(item.dataset.category);
        const itemName = item.dataset.name;
        
        const matchesCategory = catId === 0 || itemCatId === catId;
        const matchesSearch = itemName.includes(searchTerm);

        if (matchesCategory && matchesSearch) {
            item.style.display = '';
        } else {
            item.style.display = 'none';
        }
    });

    categories.forEach(cat => {
        cat.classList.remove('active');
    });
    if (el) {
        el.classList.add('active');
        const titleEl = document.getElementById('pageTitle');
        titleEl.textContent = el.querySelector('span').textContent.toUpperCase();
    }
}

// === NEW LIVE SEARCH LOGIC ===
searchBar.addEventListener('input', () => {
    // Mag-switch sa 'All' category kapag may nag-search
    if (searchBar.value.trim() !== '' && currentActiveCategory !== 0) {
        // Gawing active ang 'All' category pero HINDI magre-redirect
        categories.forEach(cat => cat.classList.remove('active'));
        allCategory.classList.add('active');
        document.getElementById('pageTitle').textContent = allCategory.querySelector('span').textContent.toUpperCase();
        // Set category ID to 0 for filtering purposes
        currentActiveCategory = 0;
    } else if (searchBar.value.trim() === '' && currentActiveCategory === 0) {
        // Kapag na-clear ang search bar, ibalik ang active category sa kung ano man ang nasa URL
        const urlParams = new URLSearchParams(window.location.search);
        const urlCatId = parseInt(urlParams.get('category') || '0');
        const activeCat = document.querySelector('.category[data-id="' + urlCatId + '"]');
        if (activeCat) {
            categories.forEach(cat => cat.classList.remove('active'));
            activeCat.classList.add('active');
            document.getElementById('pageTitle').textContent = activeCat.querySelector('span').textContent.toUpperCase();
            currentActiveCategory = urlCatId;
        }
    }
    
    // I-filter ang menu items based sa search bar value at sa current active category
    filterCategory(currentActiveCategory, document.querySelector('.category[data-id="' + currentActiveCategory + '"]'));
});

// Update filter on load to account for URL category
window.addEventListener('load', () => {
    const activeCatElement = document.querySelector('.category.active');
    filterCategory(currentActiveCategory, activeCatElement);
});
// === END NEW LIVE SEARCH LOGIC ===

</script>
<script>
setInterval(function() {
    fetch('get_cart_total.php')
        .then(response => response.json())
        .then(data => {
            if (typeof data.total !== "undefined") {
                document.querySelector('.footer .total').innerHTML = 
                    '<i class="fas fa-shopping-cart"></i> PHP ' + Number(data.total).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            }
        });
}, 1000);

window.addEventListener('storage', function(e) {
    if (e.key === 'cart_updated') {
        fetch('get_cart_total.php')
            .then(response => response.json())
            .then(data => {
                document.querySelector('.footer .total').innerHTML = 
                    '<i class="fas fa-shopping-cart"></i> PHP ' + Number(data.total).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            });
    }
});

const INACTIVITY_LIMIT = 60 * 60 * 1000; // 1 hour
const COUNTDOWN_START = 10; // seconds
let inactivityTimer, countdownTimer, countdownNum = COUNTDOWN_START;

function showInactivityPopup() {
    const popup = document.getElementById('inactivityPopup');
    const countdownSpan = document.getElementById('countdownNum');
    if (popup && countdownSpan) {
        popup.style.display = 'block';
        countdownNum = COUNTDOWN_START;
        countdownSpan.textContent = countdownNum;
        countdownTimer = setInterval(() => {
            countdownNum--;
            countdownSpan.textContent = countdownNum;
            if (countdownNum <= 0) {
                clearInterval(countdownTimer);
                window.location.href = 'main.php'; // change to your start page
            }
        }, 1000);
    }
}

// Cancel button logic
document.getElementById("cancelInactivityBtn").onclick = function() {
    document.getElementById("inactivityPopup").style.display = "none";
    clearInterval(countdownTimer);
    resetInactivityTimer();
};

function resetInactivityTimer() {
    clearTimeout(inactivityTimer);
    clearInterval(countdownTimer);
    document.getElementById("inactivityPopup").style.display = "none";
    inactivityTimer = setTimeout(showInactivityPopup, INACTIVITY_LIMIT);
}

['mousemove', 'mousedown', 'touchstart', 'keydown', 'scroll'].forEach(event => {
    window.addEventListener(event, resetInactivityTimer, {passive:true});
});

resetInactivityTimer();
</script>
</body>
</html>