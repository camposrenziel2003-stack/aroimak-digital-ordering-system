<?php
session_start();
include "config.php";
include "access_check.php";

// ✅ Initialize cart
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}
$isAddToOrder = !empty($_SESSION['add_to_order_group_id']);
// AJAX/ACTION: Add to cart, DO NOT DECREMENT STOCK in menu_items!
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_add_to_cart'])) {
    header('Content-Type: application/json');
    $itemId = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
    $spiceIndex = (int)($_POST['spice'] ?? 0);
    $spiceLevels = ["No Spice", "Light", "Moderate", "Spicy", "Extra"];
    $spiceText = $spiceLevels[$spiceIndex] ?? "No Spice";
    $size = $_POST['size'] ?? 'solo';
    $quantity = max(1, (int)$_POST['quantity']);

    // Get current stock
    $stmt = $conn->prepare(
        "SELECT mi.id, mi.name, mi.price, mi.price_solo, mi.price_sharing, mi.image, mi.pronunciation_file, mi.stock, c.name AS category_name
         FROM menu_items mi LEFT JOIN categories c ON mi.category = c.id WHERE mi.id = ?"
    );
    $stmt->bind_param("i", $itemId);
    $stmt->execute();
    $result = $stmt->get_result();
    $item = $result->fetch_assoc();
    $stmt->close();
    $stock = isset($item['stock']) ? (int)$item['stock'] : null;

    // Sum all units of this item (id) in cart, regardless of options
    $currentInCart = 0;
    foreach ($_SESSION['cart'] as $ci) {
        if ($ci['id'] == $itemId) {
            $currentInCart += (int)$ci['quantity'];
        }
    }
    // Block add if would exceed available stock
    if ($stock === null || ($quantity + $currentInCart) > $stock) {
        $remaining = max(0, $stock - $currentInCart);
        $itemName = isset($item['name']) ? $item['name'] : "this item";
        echo json_encode([
            "success" => false,
            "message" => "Sorry, only <b>{$remaining}</b> serving left for <b>{$itemName}</b>. You already have <b>{$currentInCart}</b> in your cart."
        ]);
        exit;
    }

    if ($item) {
        $categoryName = strtolower($item['category_name'] ?? '');
        $isSpecialCat = in_array($categoryName, ['fried rice', 'noodles']);
        $priceSolo = $item['price_solo'] ?? null;
        $priceSharing = $item['price_sharing'] ?? null;
        $price = $item['price'] ?? 0;
        $itemImage = $item['image'] ?? '';

        // Allergen support
        $allergenArr = isset($_POST['allergens']) ? $_POST['allergens'] : [];
        sort($allergenArr);
        $allergens = implode(",", $allergenArr);
        $allergen_note = substr(trim($_POST['allergen_note'] ?? ""), 0, 30);

        if ($isSpecialCat) {
            if ($size === 'sharing' && !empty($priceSharing)) {
                $selectedPrice = (float) $priceSharing;
            } else {
                $size = 'solo';
                $selectedPrice = (float) $priceSolo;
            }
        } else {
            $selectedPrice = (float) $price;
        }

        $cartKey = $itemId . '_' . $spiceIndex . '_' . $size . '_' . md5($allergens . "|" . $allergen_note);

        if (isset($_SESSION['cart'][$cartKey])) {
            $_SESSION['cart'][$cartKey]['quantity'] += $quantity;
        } else {
            $_SESSION['cart'][$cartKey] = [
                'id'            => $item['id'],
                'name'          => $item['name'],
                'image'         => $itemImage,
                'quantity'      => $quantity,
                'spice'         => $spiceText,
                'allergens'     => $allergens,
                'allergen_note' => $allergen_note,
                'category_name' => $item['category_name'],
                'price'         => $selectedPrice
            ];
            if ($isSpecialCat) {
                $_SESSION['cart'][$cartKey]['price_solo']    = $priceSolo;
                $_SESSION['cart'][$cartKey]['price_sharing'] = $priceSharing;
                $_SESSION['cart'][$cartKey]['selected_type'] = $size;
            }
        }
        // NO STOCK UPDATE HERE, ONLY ADD TO CART

        // Count update
        $cartCount = 0;
        foreach ($_SESSION['cart'] as $ci) {
            $cartCount += (int)($ci['quantity'] ?? 0);
        }
        echo json_encode([
            "success" => true,
            "cartCount" => $cartCount,
            "message" => "{$item['name']} added to cart!"
        ]);
        exit;
    }
    echo json_encode(["success" => false, "message" => "Item not found"]);
    exit;
}

// ✅ Detect referrer (default = reco.php)
$ref = $_POST['ref'] ?? ($_GET['ref'] ?? 'reco.php');

// ✅ Get item ID
$itemId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($itemId <= 0) {
    header("Location: $ref");
    exit;
}
// --- Calculate cart item count ---
$cartCount = 0;
foreach ($_SESSION['cart'] as $ci) {
    $cartCount += (int)($ci['quantity'] ?? 0);
}
// ✅ Fetch item details
$stmt = $conn->prepare("
    SELECT mi.id, mi.name, mi.price, mi.price_solo, mi.price_sharing, mi.description,
           mi.image, mi.pronunciation_file, mi.stock, c.name AS category_name
    FROM menu_items mi
    LEFT JOIN categories c ON mi.category = c.id
    WHERE mi.id = ?
");
$stmt->bind_param("i", $itemId);
$stmt->execute();
$result = $stmt->get_result();
$item = $result->fetch_assoc();
$stmt->close();

if (!$item) {
    header("Location: $ref");
    exit;
}

// ✅ Assign variables
$itemImg = !empty($item['image']) ? "/thai_digital/uploads/" . rawurlencode($item['image']) : "assets/img/placeholder.png";
$name = $item['name'];
$price = $item['price'];
$desc = $item['description'] ?? "No description available.";
$audioFile = $item['pronunciation_file'] ?? ''; // PRONUNCIATION FILE IS HERE
$spiceLevels = ["No Spice", "Light", "Moderate", "Spicy", "Extra"];

// SOLO SHARING LOGIC
$categoryName = strtolower($item['category_name']);
$isSpecialCat = in_array($categoryName, ['fried rice', 'noodles']);
$priceSolo = $item['price_solo'] ?? null;
$priceSharing = $item['price_sharing'] ?? null;

// STOCK LOGIC for button/display
$stock = isset($item['stock']) ? (int)$item['stock'] : 0;
$isAvailable = $stock > 0;

// ✅ Handle Add to Cart (FIXED): Normal POST
$successMessage = $errorMessage = "";
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['add_to_cart'])) {
    $quantity   = max(1, (int)$_POST['quantity']);
    $spiceIndex = (int)($_POST['spice'] ?? 0);
    $spiceText  = $spiceLevels[$spiceIndex] ?? "No Spice";
    $size = $_POST['size'] ?? 'solo';

    // Normalize allergens
    $allergenArr = isset($_POST['allergens']) ? $_POST['allergens'] : [];
    sort($allergenArr); // ensure stable order
    $allergens = implode(",", $allergenArr);
    $allergen_note = substr(trim($_POST['allergen_note'] ?? ""), 0, 30);

    // Check stock before add
    if ($stock === null || $stock < $quantity) {
        $errorMessage = "Only $stock available. Please select a valid quantity.";
    } else {
        // --- Calculate selected price ---
        if ($isSpecialCat) {
            if ($size === 'sharing' && !empty($priceSharing)) {
                $selectedPrice = (float)$priceSharing;
            } else {
                $size = 'solo';
                $selectedPrice = (float)$priceSolo;
            }
        } else {
            $selectedPrice = (float)$price;
        }

        // Cart key (normalized, stable)
        $cartKey = $itemId . '_' . $spiceIndex . '_' . $size . '_' . md5($allergens . "|" . $allergen_note);

        // Add/increment
        if (isset($_SESSION['cart'][$cartKey])) {
            $_SESSION['cart'][$cartKey]['quantity'] += $quantity;
        } else {
            $_SESSION['cart'][$cartKey] = [
                'id'            => $item['id'],
                'name'          => $item['name'],
                'image'         => $item['image'],
                'quantity'      => $quantity,
                'spice'         => $spiceText,
                'allergens'     => $allergens,
                'allergen_note' => $allergen_note,
                'category_name' => $item['category_name'],
                'price'         => $selectedPrice
            ];
            if ($isSpecialCat) {
                $_SESSION['cart'][$cartKey]['price_solo']    = $priceSolo;
                $_SESSION['cart'][$cartKey]['price_sharing'] = $priceSharing;
                $_SESSION['cart'][$cartKey]['selected_type'] = $size;
            }
        }
        // Decrement stock in database
        $updateStockStmt = $conn->prepare("UPDATE menu_items SET stock = stock - ? WHERE id = ? AND stock >= ?");
        $updateStockStmt->bind_param("iii", $quantity, $itemId, $quantity);
        $updateStockStmt->execute();
        $updateStockStmt->close();

        if ($isSpecialCat) {
            $successMessage = "{$item['name']} (" . ucfirst($size) . ") added to cart!";
        } else {
            $successMessage = "{$item['name']} added to cart!";
        }

        // 🚨 REDIRECT LOGIC: Only after DB/session changes
        if (!headers_sent()) {
            if (!empty($_SESSION['add_to_order_group_id'])) {
                header("Location: review_add_to_order.php");
            } else {
                header("Location: review_order.php");
            }
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars($name) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
html, body {
  margin: 0;
  padding: 0;
  height: 100%;
  font-size: clamp(13px, 1.6vw, 25px);
  background: #FFF3E0;
  font-family: 'Segoe UI', Tahoma, sans-serif;
  scroll-behavior: smooth;
  width: 100vw;
  overflow-x: hidden;
}
body {
  display: flex;
  flex-direction: column;
  min-height: 100vh;
  width: 100vw;
  overflow-x: hidden;
}
.header-bar {
  position: fixed;
  top: 0;
  left: 0;
  width: 100vw;
  background-color: #FFF3E0;
  padding: 20px 15px 15px 0;
  display: flex;
  justify-content: flex-start;
  align-items: center;
  z-index: 1000;
  box-shadow: 0 6px 24px rgba(230,74,25,0.16), 0 2px 12px rgba(255,152,0,0.13);
}
.header-bar a, .header-bar a:visited, .header-bar a:active, .header-bar a:focus {
  text-decoration: none !important;
}
.header-bar .back {
  margin-left: 35px;
  background: linear-gradient(135deg, #ff8c4eff, #ff6e3eff);
  color: #fff;
  border: none;
  border-radius: 16px;
  font-size: 18px;
  padding: 13px 18px;
  display: flex;
  align-items: center;
  gap: 7px;
  box-shadow: 0 6px 22px rgba(230,74,25,0.18), 0 2px 12px rgba(255,152,0,0.13);
  font-weight: 700;
  transition: background 0.22s, box-shadow 0.22s, transform 0.2s;
  text-decoration: none;
  
}
.header-bar .back:hover {
  background: linear-gradient(135deg, #FFD54F, #FF9800);
  transform: scale(1.07);
  box-shadow: 0 10px 28px rgba(255,152,0,0.22);
}

.container {
  width: 85vw;
  max-width: 100vw;
  min-width: 0;
  margin-top: 30px;
  margin-left:48px;
  flex: 1 0 auto;
  padding: 80px 0 90px 0;
  box-sizing: border-box;
  background: #FFF3E0;
  display: flex;
  justify-content: center;
  align-items: flex-start;
  position: relative;
  left: 0;
  right: 0;
}

/* Modified grid for new layout */
.menu-content-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  grid-template-rows: auto auto 1fr;
  grid-template-areas:
    "col1 col2"
    "row1 row2"
    "row3 row3";
  gap: 20px 60px;
  width: 100%;
  max-width: 900px;
  margin: 0 auto;
  padding: 24px 0;
}
.col1 { grid-area: col1; }
.col2 { grid-area: col2; }
.row1 { grid-area: row1; }
.row2 { grid-area: row2; }
.row3 { grid-area: row3; }

@media (max-width: 600px) {
    .menu-content-grid {
      gap: 14px 0;
    }
    .container {
      padding: 60px 0 88px 0;
    }
    .menu-content-grid {
      grid-template-columns: 1fr;
      grid-template-areas:
        "col1"
        "col2"
        "row1"
        "row2"
        "row3";
      gap: 14px 0;
      padding: 8px 2px;
      max-width: 100vw;
      width: 100vw;
    }
}

/* Tighter top margin for row1 and row2 */
.row1, .row2 {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 58px;
  margin-top: 0;  /* Remove any top margin */
  margin-bottom: 0;
}

.price-qty-row {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  width: 100%;
  gap: 8px;
}
.price-qty-row .price {
  margin-bottom: 4px;
}
.price-qty-row.no-price {
  flex-direction: row;
  justify-content: center;
  align-items: center;
  gap: 0;
}

/* Keep your original styling for child elements */
.image-square {
  width: 100%;
  max-width: 470px; /* Larger image */
  min-width: 250px;
  aspect-ratio: 1/1;
  border-radius: 32px;
  overflow: hidden;
  background: linear-gradient(135deg, #ffffffff 60%, #ffe0b2 100%);
  border: 4px solid #fff3e0;
  box-shadow:
    0 16px 44px rgba(255,152,0,0.16),
    0 4px 24px rgba(230,74,25,0.18),
    0 1.5px 12px rgba(0,0,0,0.12);
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 25px auto;
  position: relative;
}
.image-square img {
  width: 95%;
  height: 95%;
  object-fit: cover;
  border-radius: 20px;
  background: #fffce8;
  display: block;
  box-shadow: 0 8px 16px rgba(255,193,7,0.14);
}
.menu-title-row {
  margin-left: 10px;
  display: flex;
  align-items: center; /* Changed from flex-start to center for vertical alignment */
  justify-content: flex-start;
  gap: 5px;
  margin-bottom: 13px;
}
.menu-title-row h1 {
  font-size: clamp(25px,5.5vw,40px);
  font-weight: 700;
  color: #333;
  margin: 0 0 5px 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
}
.menu-title-text {
  font-size: clamp(23px,5vw,40px);
  font-weight: 700;
  color: #333;
  flex: 1;
  line-height: 1.15;
  word-break: break-word;
  display: flex;
  align-items: center;   /* Ensures vertical alignment with icon/buttons */
  min-height: 48px;      /* Optional: Forces height to match desc if needed */
}
.menu-desc {
  font-size: clamp(18px, 3.5vw, 20px);
  margin-left: 0px;
  color: #444;
  max-width: 400px;
  line-height: 1.38;
  box-shadow: 0 2px 12px rgba(255,152,0,0.07);
  border-radius: 10px;
  padding: 12px 18px;
  display: flex;
  align-items: center;   /* Aligns vertically with title row */
  min-height: 48px;      /* Optional: Match height with title row if needed */
}

.price {
  font-size: clamp(21px,5vw,30px);
  color: #E64A19;
  font-weight: 800;
  margin: 0 0 0 0;
  text-shadow: 0 3px 8px rgba(255,152,0,0.08);
}

.quantity-control {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 10px;
}
.quantity-control input {
  font-size: 26px;
  text-align: center;
  width: 50px;
  height: 50px;
  border: 2px solid orange;
  border-radius: 10px;
  box-sizing: border-box;
  background: #fffbe9;
  box-shadow: 0 2px 10px rgba(255,193,7,0.13);
}
.quantity-control button {
  background: linear-gradient(135deg, #FF9800, #FFD54F);
  color: white;
  font-size: 35px;
  width: 38px;
  height: 50px;
  border-radius: 10px;
  border: none;
  box-shadow: 0 10px 28px rgba(255,152,0,0.25), 0 4px 10px rgba(255,193,7,0.18);
  display: flex;
  align-items: center;
  justify-content: center;
  line-height: 1;
  font-weight: bold;
  transition: background 0.2s, box-shadow 0.22s, transform 0.18s;
}
.quantity-control button:hover {
  background: linear-gradient(135deg, #FFD54F, #FF9800);
  transform: scale(1.11);
  box-shadow: 0 14px 32px rgba(255,152,0,0.38);
}

.size-options {
  display: flex;
  gap: 20px;
  margin: 29px 0 5px 0;
  justify-content: center;
}
.size-option {
  border: 2px solid #FFD54F;
  border-radius: 12px;
  padding: 8px 23px;
  font-size: 17px;
  font-weight: 700;
  cursor: pointer;
  transition: 0.22s;
  flex: 0 0 130px;
  text-align: center;
  background: linear-gradient(135deg, #fffbe9 65%, #ffe082 100%);
  color: #FF9800;
  box-shadow: 0 8px 24px rgba(255,152,0,0.13), 0 2px 10px rgba(255,193,7,0.10);
  position: relative;
  outline: none;
}
.size-option span {
  display: block;
  color: #FF3D00;
  font-weight: bold;
  font-size: 25px;
  margin-top: 0px;
}
.size-option.active {
  border-color: #FF9800;
  background: linear-gradient(135deg, #fffbe7 90%, #FFD54F 100%);
  color: #333;
  box-shadow: 0 10px 28px rgba(255,152,0,0.22);
  transform: scale(1.07);
}
.size-options input[type="radio"] {
  appearance: none;
  width: 18px;
  height: 18px;
  border: 2px solid #FF9800;
  border-radius: 50%;
  outline: none;
  cursor: pointer;
  margin-right: 6px;
  position: relative;
}
.size-options input[type="radio"]:checked {
  background-color: #FF3D00;
  border-color: #FF9800;
}

.spice-header {
  text-align: center;
  color: darkred;
  margin-top: 30px;
  font-size: 25px;
}
.spice-container {
  margin: 14px 0;
  padding: clamp(10px, 1.5vw, 34px);
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 8px 18px rgba(230,74,25,0.12), 0 2px 10px rgba(255,193,7,0.08);
  border: 1px solid #FFD54F;
  width: 100%;
  max-width: 900px;
}
input[type=range] {
  margin-top: 15px;
  -webkit-appearance: none;
  width: 98%;
  height: 15px;
  border-radius: 6px;
  background: linear-gradient(to right, #90ee90, #ffff00, #ffa500, #ff0000);
  box-shadow: 0 4px 12px rgba(255,152,0,0.13);
}
input[type=range]::-webkit-slider-thumb {
  -webkit-appearance: none;
  appearance: none;
  width: 35px;
  height: 35px;
  border-radius: 65%;
  background: radial-gradient(circle at 40% 40%, #ff6666, #b30000);
  border: 2px solid #fff;
  box-shadow: 0 0 10px rgba(255, 0, 0, 0.7);
  cursor: pointer;
  transition: transform 0.2s ease, box-shadow 0.2s ease;
}
input[type=range]::-webkit-slider-thumb:hover {
  transform: scale(1.22);
  box-shadow: 0 0 15px rgba(255, 0, 0, 0.8);
}
input[type=range]::-moz-range-thumb {
  width: 30px;
  height: 30px;
  border-radius: 65%;
  background: radial-gradient(circle at 40% 40%, #ff6666, #b30000);
  border: 2px solid #fff;
  box-shadow: 0 0 10px rgba(255, 0, 0, 0.7);
  cursor: pointer;
  transition: transform 0.2s ease, box-shadow 0.2s ease;
}
input[type=range]::-moz-range-thumb:hover {
  transform: scale(1.14);
  box-shadow: 0 0 15px rgba(255, 0, 0, 0.8);
}

.spice-labels {
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
  margin-top: 10px;
  font-weight: bold;
  color: #444;
  font-size: clamp(12px, 4vw, 18px);
  position: relative;
}

.spice-label {
  display: flex;
  flex-direction: column;
  align-items: center;
  min-width: 48px;
  padding: 0 2px;
  position: relative;
  transition: color 0.2s;
}
.spice-label .spice-percent {
  font-size: 14px;
  margin-bottom: 2px;
  font-weight: 600;
  color: #FF9800;
  text-shadow: 0 2px 8px rgba(255,152,0,0.12);
  background: linear-gradient(90deg, #fffbe7 80%, #FFD54F 100%);
  border-radius: 14px;
  padding: 2px 7px;
  margin-top: 0;
  letter-spacing: 0.5px;
  box-shadow: 0 1px 5px rgba(255,193,7,0.10);
}
.spice-label .spice-name {
  font-weight: bold;
  color: #444;
  margin-top: 2px;
  font-size: clamp(12px, 4vw, 18px);
}
.spice-label.highlight .spice-name {
  color: darkred;
  text-shadow: 0 2px 8px rgba(255,152,0,0.10);
}
.spice-label.highlight .spice-percent {
  color: #fff;
  background: linear-gradient(135deg, #FF9800 80%, #FF3D00 100%);
  box-shadow: 0 3px 12px rgba(255,152,0,0.18);
}
.spice-label .chili-icon {
  font-size: 18px;
  margin-left: 3px;
  color: #FF3D00;
  vertical-align: middle;
  display: inline-block;
}

.spice-label.highlight .chili-icon {
  color: #FF3D00;
  filter: drop-shadow(0 0 3px #FF9800);
}
.allergen-section {
  margin-top: 20px;
  padding: 10px 20px;
  background: #fff;
  border-radius: 12px 12px 2px 2px;
  border: 1px solid #FFD54F;
  text-align: left;
  overflow: visible;
  box-shadow: 0 2px 9px rgba(255,152,0,0.09);
}
.allergen-heading {
  font-size: clamp(13px, 4vw, 18px);
  color: #333;
  margin: 2px 0 15px;
  line-height: 1.4;
}
.allergens {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  justify-content: flex-start;
}
.chip {
  padding: clamp(6px, 1.5vw, 10px) clamp(12px, 3vw, 18px);
  border-radius: 30px;
  background: #ffe0b2;
  cursor: pointer;
  font-size: clamp(12px, 3vw, 17px);
  font-weight: 700;
  transition: 0.2s;
  color: darkred;
  border: 1px solid #ff9800;
  box-shadow: 0 2px 7px rgba(255,193,7,0.13);
}
.chip:hover { background: #ffd180; }
.chip.selected {
  background: linear-gradient(135deg, #FF9800 80%, #FFD54F 100%);
  color: white;
  box-shadow: 0 5px 18px rgba(255,152,0,0.18);
}
.field textarea {
  width: 100%;
  min-height: 50px;
  max-height: 200px;
  padding: 5px 0px 10px 0px;
  border: 2px solid #ff9800;
  border-radius: 3px 3px 8px 8px;
  resize: vertical;
  background: #fff8e1;
  color: darkred;
  font-size: 15px;
  overflow-y: auto;
  margin-top: 10px;
  box-shadow: 0 2px 12px rgba(255,152,0,0.08);
}
button {
  font-size: clamp(14px, 2vw, 18px);
  padding: clamp(12px, 2vw, 16px) clamp(22px, 3vw, 30px);
  border-radius: 12px;
  border: none;
  cursor: pointer;
  transition: background 0.21s, box-shadow 0.21s, transform 0.19s;
  box-shadow: 0 8px 18px rgba(255,152,0,0.17), 0 4px 12px rgba(255,193,7,0.12);
  font-weight: 700;
}
button:hover { opacity: 0.92; background: linear-gradient(135deg, #FFD54F, #FF9800); transform: scale(1.06); }
button[type=submit], .cart-actions button {
  background: linear-gradient(135deg, #FF9800, #FFD54F);
  color: #fff;
  font-weight: bold;
  box-shadow: 0 10px 28px rgba(255,152,0,0.23), 0 4px 14px rgba(255,193,7,0.18);
}
.cart-actions {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 20px;
  margin-top: 0;
}
.cart-actions h2 {
  margin: 0;
  font-size: clamp(22px, 3.5vw, 30px);
  color: #E64A19;
  font-weight: bold;
  text-shadow: 0 3px 8px rgba(255,152,0,0.09);
}
.speaker-button {
  background: linear-gradient(135deg, #FFD54F 80%, #FF9800 100%);
  color: #FF9800;
  border: none;
  border-radius: 50%;
  font-size: 25px;
  width: 60px;
  height: 50px;
  display: flex;
  justify-content: center;
  align-items: center;
  cursor: pointer;
  margin-left: 6px;
  transition: all 0.3s ease;
  box-shadow: 0 8px 22px rgba(255,152,0,0.19), 0 2px 8px rgba(255,193,7,0.10);
}
.speaker-button:hover {
  background: linear-gradient(135deg, #FF9800, #FFD54F);
  color: #fff;
  transform: scale(1.18) rotate(8deg);
  box-shadow: 0 14px 32px rgba(255,152,0,0.38);
}
.footer-bar {
  position: fixed;
  bottom: 0;
  left: 0;
  width: 100vw;
  background-color: #FFF3E0;
  padding: 14px 14px 20px 14px;
  display: flex;
  justify-content: center;
  align-items: center;
  z-index: 1000;
  box-shadow: 0 -8px 24px rgba(255,152,0,0.17), 0 -2px 12px rgba(255,193,7,0.13);
}
.footer-bar .cart-actions {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 18px;
  flex-wrap: wrap;
}
.footer-bar .cart-actions h2 {
  margin: 0;
  font-size: clamp(19px, 4vw, 30px);
  color: #E64A19;
  font-weight: bold;
  text-shadow: 0 2px 8px rgba(255,152,0,0.09);
}
.footer-bar .cart-actions button {
  padding: 17px 22px;
  font-size: clamp(16px, 3vw, 20px);
  border-radius: 16px;
  border: none;
  background: linear-gradient(135deg, #FF9800, #FFD54F);
  color: #fff;
  font-weight: bold;
  box-shadow: 0 10px 28px rgba(255,152,0,0.23), 0 4px 14px rgba(255,193,7,0.18);
  cursor: pointer;
  transition: background 0.2s, box-shadow 0.22s, transform 0.19s;
}
.footer-bar .cart-actions button:hover {
  background: linear-gradient(135deg, #FFD54F, #FF9800);
  transform: scale(1.10);
  box-shadow: 0 14px 32px rgba(255,152,0,0.38);
}
.modal {
  display: none;
  position: fixed;
  z-index: 10000;
  left: 0;
  top: 0;
  width: 100%;
  height: 100%;
  background-color: rgba(0,0,0,0.4);
  justify-content: center;
  align-items: center;
}
.modal-content {
  background-color: #fff;
  color: #FF3D00;
  padding: 24px 36px;
  border-radius: 15px;
  font-size: 21px;
  font-weight: bold;
  text-align: center;
  animation: fadeInOut 2s ease;
  box-shadow: 0 14px 32px rgba(255,152,0,0.22), 0 4px 14px rgba(255,193,7,0.15);
}
@keyframes fadeInOut {
  0% { opacity: 0; transform: scale(0.95); }
  10% { opacity: 1; transform: scale(1); }
  90% { opacity: 1; transform: scale(1); }
  100% { opacity: 0; transform: scale(0.95); }
}
* {
  scrollbar-width: thin;
  scrollbar-color: #FFF3E0 #FFF3E0;
}
/* For Cat Drinks, Add-ons, Dessert Only*/
.centered-content {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 60vh; /* Adjust as needed */
  gap: 28px;
}
.centered-content .col1,
.centered-content .col2,
.centered-content .row1 {
  justify-content: center;
  align-items: center;
  width: 100%;
  display: flex;
}
.centered-content .col2 {
  flex-direction: column;
  align-items: center;
  text-align: center;
}
.centered-content .menu-title-row {
  justify-content: center;
}
.centered-content .menu-title-text {
  text-align: center;
  width: 100%;
}
.centered-content .menu-desc {
  text-align: center;
  margin-left: auto;
  margin-right: auto;
}
.header-bar-cart {
  position: absolute;
  top: 7px;
  right: 25px;
  z-index: 1100;
}

/* Cart Icon UI Match */
.cart-icon {
  background: linear-gradient( #FFD54F, #FF9800);
  border: none;
  cursor: pointer;
  font-size: 30px;   /* bigger cart icon */
  color: #fff;
  padding: 15px 15px; /* squircle effect: horizontal bigger */
  border-radius: 24px;
  box-shadow: 0 4px 18px 0 rgba(255,193,7,0.18), 0 1.5px 6px rgba(255,152,0,0.09);
  transition: background 0.2s, box-shadow 0.2s, transform 0.2s;
  position: relative;
  opacity: 1;
  outline: none;
}
.cart-icon:hover, .cart-icon:focus {
  background: linear-gradient(135deg,#ffa726 70%, #ffd54f 100%);
  transform: scale(1.06) translateY(-2px);
  box-shadow: 0 8px 18px rgba(255,193,7,0.22);
  opacity: 1;
}

/* Cart count "badge" matches UI (solid orange, white, rounded, clean) */
.cart-badge {
  position: absolute;
  top: 1px;
  right: 7px;
  background: #ff5722;
  color: #fff;
  font-size: 13px;
  font-family: 'Segoe UI', Arial, sans-serif;
  font-weight: bold;
  border-radius: 50%;
  padding: 1px 1px;
  min-width: 24px;
  height: 24px;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 2px 8px rgba(255,87,34,0.14);
  z-index: 12;
  opacity: 0.93;
}

/* Add minimal CSS for the pulse animation near your styles (place inside <style> if not present) */
.cart-pulse {
  animation: pulse-animation 0.6s ease-out;
}

@keyframes pulse-animation {
  0% {
    transform: scale(1);
  }
  50% {
    transform: scale(1.1);
  }
  100% {
    transform: scale(1);
  }
}
  </style>
<?php if (!empty($successMessage)): ?>
<div id="successModal" class="modal">
  <div class="modal-content" style="color:orange;">
    <p><?= htmlspecialchars($successMessage) ?></p>
  </div>
</div>
<script>
  const modal = document.getElementById("successModal");
  modal.style.display = "flex";
  setTimeout(() => { modal.style.display = "none"; }, 750);
</script>
<?php endif; ?>
<?php if (!empty($errorMessage)): ?>
<div id="errorModal" class="modal">
  <div class="modal-content" style="color:red;">
    <p><?= htmlspecialchars($errorMessage) ?></p>
  </div>
</div>
<script>
  const errModal = document.getElementById("errorModal");
  errModal.style.display = "flex";
  setTimeout(() => { errModal.style.display = "none"; }, 750);
</script>
<?php endif; ?>
</head>
<body>
  <header class="header-bar">
    <a href="<?= $ref ?>">
      <button type="button" class="back">
        <i class="fa fa-arrow-left"></i> Back
      </button>
      <div class="header-bar-cart">
  <form action="<?= $isAddToOrder ? 'review_add_to_order.php' : 'review_order.php' ?>" method="get">
    <input type="hidden" name="ref" value="menu_item_detail.php?id=<?= $itemId ?>">
    <button type="submit" class="cart-icon" title="View Cart">
      <i class="fas fa-shopping-cart"></i>
      <?php if ($cartCount > 0): ?>
        <span class="cart-badge"><?= $cartCount ?></span>
      <?php endif; ?>
    </button>
</form>
</div>
    </a>
  </header>
  <div class="container">
    <form method="POST" id="addToCartForm" style="width:100%;height:100%;">
        <!-- IMAGE -->
<?php
$isSimpleCat = in_array($categoryName, ['drinks', 'dessert', 'add-ons']);
?>
<?php if ($isSimpleCat): ?>
  <!-- Centered layout for drinks, dessert, add-ons -->
  <div class="centered-content">
    <div class="col1">
      <div class="image-square">
        <img src="<?= htmlspecialchars($itemImg) ?>" alt="<?= htmlspecialchars($item['name']) ?>">
      </div>
    </div>
    <div class="col2">
      <div class="menu-title-row">
        <div class="menu-title-text"><?= htmlspecialchars($name) ?></div>
        <button type="button" class="speaker-button" onclick="playAudio()">
          <i class="fas fa-volume-up"></i>
        </button>
      </div>
      <div class="menu-desc"><?= htmlspecialchars($desc) ?></div>
    </div>
    <div class="row1">
      <div class="price-qty-row">
        <div class="price">₱ <?= number_format($price, 2) ?></div>
        <div class="quantity-control">
          <button type="button" onclick="changeQty(-1)">−</button>
          <?php $maxQty = 50; /* force button + input limit to 50 */ ?>
          <input type="number" id="quantity" name="quantity" value="1" min="1" max="<?= $maxQty ?>" step="1" oninput="validateQty()" pattern="\d*">
          <button type="button" onclick="changeQty(1)">+</button>
        </div>
      </div>
    </div>
    <input type="hidden" name="ref" value="<?= htmlspecialchars($ref) ?>">
    <input type="hidden" id="selectedPrice" name="selected_price" value="<?= $price ?>">
    <input type="hidden" name="add_to_cart" value="1">
  </div>
<?php else: ?>
  <!-- Standard grid layout for other categories -->
  <div class="menu-content-grid">
    <!-- IMAGE -->
    <div class="col1">
      <div class="image-square">
        <img src="<?= htmlspecialchars($itemImg) ?>" alt="<?= htmlspecialchars($item['name']) ?>">
      </div>
    </div>
    <!-- MENU NAME & DESCRIPTION -->
    <div class="col2">
      <div class="menu-title-row">
        <div class="menu-title-text"><?= htmlspecialchars($name) ?></div>
        <button type="button" class="speaker-button" onclick="playAudio()">
          <i class="fas fa-volume-up"></i>
        </button>
      </div>
      <div class="menu-desc"><?= htmlspecialchars($desc) ?></div>
    </div>
    <!-- PRICE & QUANTITY -->
    <div class="row1">
      <div class="price-qty-row<?= $isSpecialCat ? ' no-price' : '' ?>">
        <?php if (!$isSpecialCat): ?>
          <div class="price">₱ <?= number_format($price, 2) ?></div>
        <?php endif; ?>
        <div class="quantity-control">
          <button type="button" onclick="changeQty(-1)">−</button>
          <?php $maxQty = 50; /* force button + input limit to 50 */ ?>
          <input type="number" id="quantity" name="quantity" value="1" min="1" max="<?= $maxQty ?>" step="1" oninput="validateQty()" pattern="\d*">
          <button type="button" onclick="changeQty(1)">+</button>
        </div>
      </div>
    </div>
    
    <!-- SOLO/SHARING -->
    <div class="row2">
      <?php if ($isSpecialCat): ?>
        <div class="size-options">
          <?php if (!empty($priceSolo) && $priceSolo > 0): ?>
            <label class="size-option active">
              <input type="radio" name="size" value="solo" checked onclick="setPrice(<?= (float)$priceSolo ?>)">
              Solo
              <span>₱<?= number_format((float)$priceSolo, 2) ?></span>
            </label>
          <?php endif; ?>
          <?php if (!empty($priceSharing) && $priceSharing > 0): ?>
            <label class="size-option">
              <input type="radio" name="size" value="sharing" onclick="setPrice(<?= (float)$priceSharing ?>)">
              Sharing
              <span>₱<?= number_format((float)$priceSharing, 2) ?></span>
            </label>
          <?php endif; ?>
        </div>
        
      <?php endif; ?>
      <input type="hidden" name="ref" value="<?= htmlspecialchars($ref) ?>">
      <input type="hidden" id="selectedPrice" name="selected_price" 
        value="<?php 
          if ($isSpecialCat) {
              if (!empty($priceSolo) && $priceSolo > 0) {
                  echo $priceSolo; 
              } elseif (!empty($priceSharing) && $priceSharing > 0) {
                  echo $priceSharing;
              } else {
                  echo 0;
              }
          } else {
              echo $price;
          }
        ?>">
      <input type="hidden" name="add_to_cart" value="1">
    </div>
    <!-- SPICE, ALLERGENS, NOTE -->
    <div class="row3">
      <?php if (!in_array(strtolower($categoryName), ['add-ons', 'drinks', 'dessert'])): ?>
        <h3 class="spice-header">What level of spice do you want?</h3>
        <div class="spice-container">
          <input type="range" id="spice" name="spice" min="0" max="4" value="0" step="1" oninput="updateSpiceLabel(this.value)">
          <div class="spice-labels">
  <div class="spice-label" id="spice-label-0">
    <span class="spice-percent">0%</span>
    <span class="spice-name">No Spice</span>
  </div>
  <div class="spice-label" id="spice-label-1">
    <span class="spice-percent">25%</span>
    <span class="spice-name">Light</span>
  </div>
  <div class="spice-label" id="spice-label-2">
    <span class="spice-percent">50%</span>
    <span class="spice-name">Moderate</span>
  </div>
  <div class="spice-label" id="spice-label-3">
    <span class="spice-percent">75%</span>
    <span class="spice-name">Spicy</span>
  </div>
  <div class="spice-label" id="spice-label-4">
    <span class="spice-percent">100%</span>
    <span class="spice-name">Extra</span>
  </div>
</div>
          <div class="allergen-section">
            <p class="allergen-heading"><b>Our food may contain allergens/intolerance ingredient. (Choose if applicable):</b></p>
            <div class="allergens">
              <?php foreach (['Egg','Soy','Dairy','Nuts','Corn','Seafood','Sugar'] as $allergen): ?>
                <div class="chip" data-allergen="<?= htmlspecialchars($allergen) ?>" onclick="toggleChip(this)">
                  <?= $allergen ?>
                </div>
              <?php endforeach; ?>
            </div>
            <div id="allergen-hidden"></div>
          </div>
        </div>
        <div class="spice-container" style="margin-top: 0; padding-top: 0; border-top: none; border-radius: 0 0 12px 12px;">
            <div class="field">
              <textarea 
  name="allergen_note" 
  id="allergen_note" 
  rows="3" 
  maxlength="30"
  placeholder="Enter other allergens/intolerance here..."
  class="allergen-textarea"
  oninput="this.value = this.value.slice(0,30)"></textarea>
            </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>
<footer class="footer-bar">
  <div class="cart-actions">
    <h2 id="total">PHP <?= number_format($isSimpleCat ? $priceSolo : $price, 2) ?></h2>
    <button type="button" id="addToCartBtn"><i class="fas fa-shopping-cart"></i> Add to Cart</button>
    <!-- Ask for Assistance Button/Icon -->
    <button type="button" id="assistanceBtn" title="Ask for Assistance"
      style="background:linear-gradient(135deg, #ff8c4eff, #ff6e3eff); color: white; border-radius:50%; width:55px; height:55px; margin-left:10px; display:flex; align-items:center; justify-content:center; font-size:29px;">
      <i class="fa fa-hand-paper"></i>
    </button>
  </div>
</footer>

<!-- Assistance Modal -->
<div id="assistModal" class="modal">
  <div class="modal-content" style="color:#E64A19;">
    <p>Do you want to request staff assistance?</p>
    <button type="button" class="btn" id="confirmAssistance" style="background: linear-gradient(135deg, #FF9800, #FFD54F); color: white; margin-right: 12px; border-radius: 18px; font-weight: bold;">Yes, Call Staff</button>
    <button type="button" class="btn" id="cancelAssistance" style="background: #aaa; color: white; font-weight: bold; border-radius: 18px;">Cancel</button>
  </div>
</div>
<!-- Success Modal -->
<div id="assistSuccessModal" class="modal">
  <div class="modal-content" style="color:green;">
    <p>Staff assistance requested! They'll arrive at your table soon.</p>
    <button type="button" class="btn" id="assistSuccessOK" style="background: linear-gradient(135deg, #FF9800, #FFD54F); color: white; border-radius: 18px;">OK</button>
  </div>
</div>
</form>
  </div>
  <div id="addToCartModal" class="modal">
    <div class="modal-content" style="color:#FF9800;">
      <p>Are you sure you want to add this item to your cart?</p>
      <button type="button" class="btn" id="confirmAddToCart" style="background:linear-gradient(135deg, #FF9800, #FFD54F);color:white;margin-right:12px;border-radius:18px; font-weight:bold;box-shadow:0 8px 18px rgba(255,152,0,0.17),0 4px 12px rgba(255,193,7,0.12);">Yes, Add</button>
      <button type="button" class="btn" id="cancelAddToCart" style="background:#aaa;color:white;font-weight:bold; border-radius:18px; box-shadow:0 4px 12px rgba(255,193,7,0.08);">Cancel</button>
    </div>
  </div>

  <script>
  // Elements for modal
  const addToCartBtn = document.getElementById("addToCartBtn");
  const addToCartModal = document.getElementById("addToCartModal");
  const confirmAddToCart = document.getElementById("confirmAddToCart");
  const cancelAddToCart = document.getElementById("cancelAddToCart");
  addToCartBtn.onclick = function() {
    addToCartModal.style.display = "flex";
  };
  cancelAddToCart.onclick = function() {
    addToCartModal.style.display = "none";
  };
  confirmAddToCart.onclick = function() {
    addToCartModal.style.display = "none";
    document.getElementById("addToCartForm").submit();
  };

  let price = <?= $isSpecialCat ? (float)$priceSolo : (float)$price ?>;
  function setPrice(newPrice) {
    price = newPrice;
    document.getElementById('selectedPrice').value = newPrice;
    updateTotal();
  }

  // HARD CAP for quantity (used by buttons & input validation)
 const AVAILABLE_STOCK = <?= json_encode($stock) ?>;
    const MAX_QTY = Math.min(50, AVAILABLE_STOCK);

    // Show enhanced modal for stock limit on error
    function showStockLimitModal(message, color='red') {
      let oldModal = document.getElementById('stockLimitModal');
      if (oldModal) oldModal.remove();
      let modalDiv = document.createElement('div');
      modalDiv.id = 'stockLimitModal';
      modalDiv.className = 'modal';
      modalDiv.style.display = 'flex';
      modalDiv.innerHTML =
        `<div class="modal-content" style="color:${color};">
          <p style="margin-top:14px;">${message}</p>
        </div>`;
      document.body.appendChild(modalDiv);
      setTimeout(() => { modalDiv.remove(); }, 1800);
    }

    function changeQty(delta) {
      const qtyInput = document.getElementById('quantity');
      let current = parseInt(qtyInput.value) || 1;
      let newVal = current + delta;

      if (AVAILABLE_STOCK <= 0) {
        qtyInput.value = 0;
        qtyInput.disabled = true;
        showStockLimitModal("Out of stock.", "red");
        return;
      }
      if (newVal < 1) newVal = 1;
      if (newVal > MAX_QTY) {
        showStockLimitModal(
          `Only <span style="color:red;font-weight:bold;">${MAX_QTY}</span> serving left for this dish. Sorry our ingredients is limited, cannot exceed available stock.`,
          "red"
        );
        newVal = MAX_QTY;
      }
      qtyInput.value = newVal;
      updateTotal();
      updateQtyButtons();
    }

    function validateQty() {
      const qtyInput = document.getElementById('quantity');
      qtyInput.value = qtyInput.value.replace(/[^\d]/g, '');
      let val = parseInt(qtyInput.value);
      if (AVAILABLE_STOCK <= 0) {
        qtyInput.value = 0;
        qtyInput.disabled = true;
        showStockLimitModal("Out of stock.", "red");
        return;
      }
      if (isNaN(val) || val < 1) {
        qtyInput.value = 1;
      } else if (val > MAX_QTY) {
        showStockLimitModal(
            `Only <span style="color:red;font-weight:bold;">${MAX_QTY}</span> serving left for this dish. Sorry our ingredients is limited, cannot exceed available stock.`,
          "red"
        );
        qtyInput.value = MAX_QTY;
      }
      updateTotal();
      updateQtyButtons();
    }

    function updateQtyButtons() {
      const qtyInput = document.getElementById('quantity');
      const minusBtn = qtyInput.previousElementSibling;
      const plusBtn = qtyInput.nextElementSibling;
      const val = parseInt(qtyInput.value) || 1;
      if (minusBtn) minusBtn.disabled = val <= 1 || AVAILABLE_STOCK <= 0;
      if (plusBtn) plusBtn.disabled = val >= MAX_QTY || AVAILABLE_STOCK <= 0;
      if (plusBtn) plusBtn.style.opacity = (val >= MAX_QTY || AVAILABLE_STOCK <= 0) ? '0.6' : '1';
      if (minusBtn) minusBtn.style.opacity = (val <= 1 || AVAILABLE_STOCK <= 0) ? '0.6' : '1';
      if (AVAILABLE_STOCK <= 0) {
        qtyInput.value = 0;
        qtyInput.disabled = true;
        if (plusBtn) plusBtn.disabled = true;
        if (minusBtn) minusBtn.disabled = true;
      } else {
        qtyInput.disabled = false;
      }
    }

    function initQtyControls() {
      const qty = document.getElementById('quantity');
      if (!qty) return;
      qty.addEventListener('input', validateQty);
      if (!qty.value || isNaN(parseInt(qty.value))) qty.value = (AVAILABLE_STOCK > 0 ? 1 : 0);
      if (parseInt(qty.value) > MAX_QTY) qty.value = MAX_QTY;
      updateQtyButtons();
      updateTotal();
    }
    initQtyControls();
    document.addEventListener('DOMContentLoaded', initQtyControls);

    function updateTotal() {
      let qty = parseInt(document.getElementById('quantity').value) || 1;
      let total = qty * price;
      document.getElementById('total').innerText = "PHP " + total.toFixed(2);
    }

    confirmAddToCart.onclick = function() {
      addToCartModal.style.display = "none";
      const form = document.getElementById("addToCartForm");
      const fd = new FormData(form);
      fd.append("ajax_add_to_cart", "1");
      fd.append("item_id", <?= json_encode($itemId) ?>);

      confirmAddToCart.disabled = true;
      confirmAddToCart.style.opacity = "0.15";

      fetch(window.location.pathname, {
        method: "POST",
        body: fd,
        credentials: "same-origin",
        headers: { 'Accept': 'application/json' }
      })
      .then(res => res.json())
      .then(data => {
        confirmAddToCart.disabled = false;
        confirmAddToCart.style.opacity = "";

        if (data.success) {
          updateCartCount(data.cartCount);
          const cartBtn = document.querySelector('.cart-icon');
          if (cartBtn) {
            cartBtn.classList.remove('cart-pulse');
            void cartBtn.offsetWidth;
            cartBtn.classList.add('cart-pulse');
            setTimeout(() => cartBtn.classList.remove('cart-pulse'), 800);
          }
          showCartSuccess(data.message || "Added to cart!");
        } else {
          showStockLimitModal(data.message || "Failed to add to cart", "red");
        }
      })
      .catch(() => {
        confirmAddToCart.disabled = false;
        confirmAddToCart.style.opacity = "";
        showStockLimitModal("AJAX error, try again!", "red");
      });
    };

// Update cart icon count in real time
function updateCartCount(cartCount) {
  let badge = document.querySelector('.cart-badge');
  if (!badge) {
    // Create badge if not exist
    let btn = document.querySelector('.cart-icon');
    badge = document.createElement('span');
    badge.className = 'cart-badge';
    btn.appendChild(badge);
  }
  badge.textContent = cartCount;
  if (parseInt(cartCount) > 0) {
    badge.style.display = "flex";
  } else {
    badge.style.display = "none";
  }
}

// Show temporary message for "Added to cart!"
function showCartSuccess(message) {
  let successDiv = document.createElement('div');
  successDiv.className = "modal";
  successDiv.style.display = "flex";
  successDiv.innerHTML =
    `<div class="modal-content" style="color:orange;">
      <p>${message}</p>
    </div>`;
  document.body.appendChild(successDiv);
  setTimeout(()=>{successDiv.remove();}, 900);
}

// PRONUNCIATION AUDIO SUPPORT
  function playAudio() {
    var audioFile = <?= json_encode($audioFile) ?>;
    var itemName = <?= json_encode($name) ?>;

    // 1. Prefer pre-recorded audio, guaranteed Thai
    if (audioFile && audioFile.trim() !== "") {
      const audio = new Audio("/thai_digital/uploads/" + audioFile);
      audio.oncanplaythrough = () => audio.play().catch(() => {
        alert("Cannot play Thai audio file.");
      });
      audio.onerror = () => {
        alert("Thai audio file could not be loaded. Please try again or contact support.");
      };
      audio.load();
      return;
    }

    // 2. Otherwise, fallback to Thai TTS ONLY IF SUPPORTED!
    let synth = window.speechSynthesis;
    function findThaiVoice(voices) {
      return voices.find(v => v.lang === "th-TH" && v.voiceURI && !v.default)
          || voices.find(v => v.lang === "th-TH" && v.voiceURI)
          || voices.find(v => v.lang.startsWith("th") && v.voiceURI);
    }

    function speakIfAvailable() {
      let voices = synth.getVoices();
      let thaiVoice = findThaiVoice(voices);

      if (!thaiVoice) {
        alert("Sorry, Thai pronunciation (speech) is not available on your device/browser.");
        return;
      }
      synth.cancel();
      let utter = new SpeechSynthesisUtterance(itemName);
      utter.voice = thaiVoice;
      utter.lang = thaiVoice.lang;
      synth.speak(utter);
    }

    if (!synth.getVoices().some(v => v.lang.startsWith('th'))) {
      synth.onvoiceschanged = () => speakIfAvailable();
      synth.getVoices();
    } else {
      speakIfAvailable();
    }
  }

  // Ask for assistance
  // Ask for Assistance Button JS
  const assistanceBtn = document.getElementById("assistanceBtn");
  const assistModal = document.getElementById("assistModal");
  const confirmAssistance = document.getElementById("confirmAssistance");
  const cancelAssistance = document.getElementById("cancelAssistance");
  const assistSuccessModal = document.getElementById("assistSuccessModal");
  const assistSuccessOK = document.getElementById("assistSuccessOK");

  assistanceBtn.onclick = function() {
    assistModal.style.display = "flex";
  };
  cancelAssistance.onclick = function() {
    assistModal.style.display = "none";
  };
  confirmAssistance.onclick = function() {
    assistModal.style.display = "none";
    // Compose payload (can adjust order_group_id as needed)
    const payload = {
      order_group_id: "",
      table_number: 1, // Replace with dynamic if available
      customer_name: "", // Replace with dynamic if available
      request_type: "Assistance",
      assistance_request: "Customer requested staff assistance via button"
    };
    fetch("send_request.php", {
      method: "POST",
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(data => {
      assistSuccessModal.style.display = "flex";
    })
    .catch(() => {
      alert("Network error, could not submit assistance request.");
    });
  };
  assistSuccessOK.onclick = function() {
    assistSuccessModal.style.display = "none";
  };

  // Close modal if clicked outside
  document.querySelectorAll('.modal').forEach(function(modalDiv) {
    modalDiv.addEventListener('mousedown', function(e) {
      if (e.target === modalDiv) {
        modalDiv.style.display = 'none';
      }
    });
  });
  function toggleChip(el) {
  el.classList.toggle('selected');
  updateAllergenInputs();
}

function updateAllergenInputs() {
  // Remove existing allergen hidden fields
  const container = document.getElementById('allergen-hidden');
  while (container.firstChild) container.removeChild(container.firstChild);
  // For each selected chip, add a hidden input
  document.querySelectorAll('.chip.selected').forEach(function(chip) {
    const val = chip.getAttribute('data-allergen');
    const hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = 'allergens[]';
    hidden.value = val;
    container.appendChild(hidden);
  });
}

// Ensure allergens update if user presses enter (on note, etc)
document.getElementById('addToCartForm').addEventListener('submit', function() {
  updateAllergenInputs();
});
  </script>
</body>
</html>