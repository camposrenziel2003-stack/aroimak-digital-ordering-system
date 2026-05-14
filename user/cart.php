<?php
session_start();
include "config.php"; // Make sure $conn = new mysqli(...) is set

// --- Initialize cart session if not exists ---
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = []; // Each item: ['id'=>..., 'quantity'=>...]
}

// --- Handle item removal ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_id'])) {
    $removeId = intval($_POST['remove_id']);
    foreach ($_SESSION['cart'] as $key => $item) {
        if ($item['id'] === $removeId) {
            unset($_SESSION['cart'][$key]);
            break;
        }
    }
    $_SESSION['cart'] = array_values($_SESSION['cart']);
    header("Location: cart.php");
    exit;
}

// --- Fetch cart items ---
$cart = $_SESSION['cart'];
$cartItems = [];
$totalPrice = 0;

if (!empty($cart)) {
    $ids = array_column($cart, 'id');
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("SELECT * FROM menu_items WHERE id IN ($placeholders)");
        $types = str_repeat('i', count($ids));
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            foreach ($cart as $c) {
                if ($c['id'] == $row['id']) {
                    $row['quantity'] = $c['quantity'];
                    break;
                }
            }
            $cartItems[] = $row;
            $totalPrice += $row['price'] * $row['quantity'];
        }
    }
}

// --- Allergens ---
$allergens = ['Egg', 'Soy', 'Dairy', 'Nuts', 'Corn', 'Seafood', 'Sugar'];

// --- Form submit ---
$error = '';
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['customerName'])) {
    $customerName = trim($_POST['customerName']);
    $selectedAllergens = $_POST['allergens'] ?? [];
    $allergenNote = trim($_POST['allergenNote']);

    if ($customerName === "") {
        $error = "Please enter your name to continue.";
    } elseif (empty($cartItems)) {
        $error = "Your cart is empty. Add items before completing order.";
    } else {
        $orderNumber = rand(1000, 9999);
        header("Location: SummaryOfOrdersScreen.php?customerName=" . urlencode($customerName) .
            "&allergens=" . urlencode(implode(", ", $selectedAllergens)) .
            "&note=" . urlencode($allergenNote) .
            "&orderNumber=$orderNumber");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Review of Orders</title>
<style>
body { background:#FFF3E0; font-family: Arial,sans-serif; margin:0; padding:0; }
.container { max-width:1000px; margin:20px auto; background:#fff; padding:32px; border-radius:24px; box-shadow:0 4px 16px rgba(0,0,0,0.1);}
.header { display:flex; align-items:center; margin-bottom:20px;}
.header img { height:100px; width:100px;}
.header h1 { margin-left:20px; font-size:36px; font-weight:bold; letter-spacing:1.2px;}
.cart-item { display:flex; align-items:center; border-bottom:1px solid #ddd; padding:12px 0;}
.cart-item img { width:80px; height:80px; border-radius:12px; object-fit:cover; margin-right:20px;}
.cart-item h3 { flex:1; font-size:20px; margin:0;}
.cart-item .price { font-size:18px; color:#666; margin-right:20px;}
.cart-item .qty { margin-right:20px;}
.cart-item form { margin:0; }
.total { text-align:right; font-size:24px; font-weight:bold; margin-top:20px;}
.form-section { margin-top:30px;}
.form-section label { display:block; font-weight:bold; margin-bottom:8px; font-size:18px;}
.form-section input, .form-section textarea { width:100%; padding:10px; font-size:16px; margin-bottom:20px; border:1px solid #ccc; border-radius:6px;}
.allergens { display:flex; flex-wrap:wrap; gap:12px;}
.allergens label { background:#FFE0B2; padding:8px 12px; border-radius:12px; border:1px solid #FFB74D; cursor:pointer;}
.buttons { display:flex; justify-content:space-between; margin-top:30px;}
.btn { padding:14px 30px; font-size:18px; border:none; border-radius:8px; cursor:pointer;}
.btn-back { background:#aaa; color:white;}
.btn-submit { background:orange; color:white; font-weight:bold;}
.error { color:red; margin-bottom:20px; font-size:18px;}
</style>
</head>
<body>
<div class="container">
    <!-- Header -->
    <div class="header">
        <img src="imgages/logo.png" alt="Logo">
        <h1>REVIEW OF ORDERS</h1>
    </div>
    <hr style="border:2px solid orange;">
    
    <?php if (!empty($error)) echo "<div class='error'>$error</div>"; ?>
    
    <!-- Cart Items -->
    <?php if (empty($cartItems)): ?>
        <p>Your cart is empty.</p>
    <?php else: ?>
        <?php foreach ($cartItems as $item): ?>
            <div class="cart-item">
                <img src="<?= $item['image'] ?>" alt="<?= $item['name'] ?>">
                <h3><?= $item['name'] ?></h3>
                <div class="price">₱<?= number_format($item['price'],2) ?></div>
                <div class="qty">x <?= $item['quantity'] ?></div>
                <form method="POST">
                    <input type="hidden" name="remove_id" value="<?= $item['id'] ?>">
                    <button type="submit">Remove</button>
                </form>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    
    <!-- Total -->
    <div class="total">Total: ₱<?= number_format($totalPrice,2) ?></div>
    
    <!-- Form -->
    <form method="POST">
        <div class="form-section">
            <label>Customer Name:</label>
            <input type="text" name="customerName" placeholder="Enter your full name">
        </div>
        <div class="form-section">
            <label>Our food may contain allergens. Choose any allergens:</label>
            <div class="allergens">
                <?php foreach ($allergens as $a): ?>
                    <label>
                        <input type="checkbox" name="allergens[]" value="<?= $a ?>"> <?= $a ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="form-section">
            <label>Other allergen notes:</label>
            <textarea name="allergenNote" rows="3" placeholder="Enter other allergens here..."></textarea>
        </div>
        <!-- Buttons -->
        <div class="buttons">
            <button type="button" class="btn btn-back" onclick="window.history.back()">Back</button>
            <button type="submit" class="btn btn-submit">Complete Order</button>
        </div>
    </form>
</div>
</body>
</html>
