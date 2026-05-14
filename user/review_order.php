<?php
// --- Error reporting ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include "config.php";
include "access_check.php";

// --- Load Cart ---
$cart = $_SESSION['cart'] ?? [];

// --- Find Table Number ---
$device_ip = $_SERVER['REMOTE_ADDR'];
$table_number = '';
if (isset($conn)) {
    $stmt = $conn->prepare("SELECT table_number FROM tablets WHERE ip_address = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $device_ip);
        $stmt->execute();
        $stmt->bind_result($found_table_number);
        if ($stmt->fetch()) {
            $table_number = $found_table_number;
        }
        $stmt->close();
    }
}

// --- Fetch live stock info for all items in cart ---
$productIds = [];
foreach ($cart as $item) {
    if (isset($item['id'])) $productIds[] = (int)$item['id'];
}
$productStocks = [];
if ($productIds) {
    $inList = implode(',', array_unique($productIds));
    $query = "SELECT id, stock FROM menu_items WHERE id IN ($inList)";
    $result = $conn->query($query);
    while ($row = $result->fetch_assoc()) {
        $productStocks[(int)$row['id']] = (int)$row['stock'];
    }
}

// --- Cart summary ---
$jsCart = [];
$total = 0;
foreach ($cart as $id => $item) {
    $selectedType = strtolower($item['selected_type'] ?? 'solo');
    $displayPrice = ($selectedType === "sharing")
        ? floatval($item['price_sharing'] ?? $item['price'] ?? 0)
        : floatval($item['price_solo'] ?? $item['price'] ?? 0);

    // Stock from DB (default 100 if not found)
    $itemId = isset($item['id']) ? (int)$item['id'] : 0;
    $stock = isset($productStocks[$itemId]) ? $productStocks[$itemId] : (isset($item['stock']) ? intval($item['stock']) : 100);

    $quantity = intval($item['quantity'] ?? 1);

    // --- Clamp quantity in session to stock limit ---
    if ($stock <= 0) {
        $quantity = 0;
        $_SESSION['cart'][$id]['quantity'] = 0;
    } elseif ($quantity > $stock) {
        $quantity = $stock;
        $_SESSION['cart'][$id]['quantity'] = $stock;
    }

    $total += $displayPrice * $quantity;
    $jsCart[] = [
        'id'            => $id,
        'name'          => $item['name'] ?? '',
        'price'         => $displayPrice,
        'quantity'      => $quantity,
        'stock'         => $stock,
        'spice'         => $item['spice'] ?? 'No Spice',
        'allergens'     => $item['allergens'] ?? '',
        'allergen_note' => $item['allergen_note'] ?? '',
        'selected_type' => $selectedType,
        'addons'        => $item['addons'] ?? []
    ];
    if (isset($_SESSION['cart'][$id])) {
        $_SESSION['cart'][$id]['price'] = $displayPrice;
        $_SESSION['cart'][$id]['selected_type'] = $selectedType;
        $_SESSION['cart'][$id]['quantity'] = $quantity;
        $_SESSION['cart'][$id]['stock'] = $stock; // keep updated for next page if needed
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Review of Orders</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    body {
    margin: 0;
    font-family: 'Segoe UI', Tahoma, sans-serif;
    background: #FFF3E0;
    display: flex;
    justify-content: center;
    min-height: 100vh;
  }
  .container-wrapper {
    display: flex;
    flex-direction: column;
    justify-content: center;
    width: 100%;
    max-width: 600px;
    margin: 20px auto;
    box-sizing: border-box;
  }
  .container {
    background: #fff;
    border-radius: 20px;
    padding: 40px 20px;
    box-shadow: 15px 20px 15px rgba(0,0,0,0.1);
    width: 100%;
    box-sizing: border-box;
  }
  .header { display: flex; align-items: center; gap: 16px; margin-bottom: 20px; }
  .header img { height: 60px; }
  .header h1 { color:#FF9800; font-size:32px; margin:0; display:flex; align-items:center; gap:12px; }
  .divider { height: 4px; background: orange; margin: 20px 0; }
  .cart-item { display: flex; align-items: center; justify-content: space-between; background:#fafafa; border-radius: 12px; padding: 12px; margin-bottom: 12px; box-shadow: 0 2px 6px rgba(0,0,0,0.1); }
  .cart-item img { width:70px; height:70px; object-fit:cover; border-radius:12px; margin-right:16px; }
  .item-info { flex:1; }
  .item-info h3 { margin:0; font-size:18px; font-weight:bold; }
  .item-info p { margin:4px 0; color:#666; }
  .qty-controls { display: flex; align-items: center; gap: 10px; }
  .qty-controls button { background: none; border:none; cursor: pointer; font-size: 20px; color:orange; }
  .remove-btn { color:red; cursor:pointer; background:none; border:none; }
  .total { text-align:right; font-size:22px; font-weight:bold; margin:20px 0; }
  .field { margin-bottom:16px; }
  .field label { display:block; margin-bottom:6px; font-weight:600; }
  .field input, .field textarea { width:97%; padding:10px; border:1px solid #ccc; border-radius:8px; }
  .actions { display:flex; justify-content:space-between; margin-top:30px; }
  .btn {
    padding: 12px 24px;
    border: none;
    border-radius: 18px;
    font-size: 16px;
    cursor: pointer;
    font-weight: bold;
    background: linear-gradient(135deg, #FF9800, #FFD54F);
    color: #fff;
    box-shadow: 0 8px 18px rgba(255,152,0,0.18), 0 2px 10px rgba(255,193,7,0.08);
    transition: background 0.22s, box-shadow 0.22s, transform 0.18s;
  }
  .btn:hover,
  .modal-content .btn:hover {
    background: linear-gradient(135deg, #FFD54F, #FF9800);
    transform: scale(1.08);
    box-shadow: 0 10px 22px rgba(255,152,0,0.22);
  }
  .btn-back {
    background: linear-gradient(135deg, #FFD54F, #aaa);
    color: white;
    font-weight: bold;
  }
  .btn-back:hover {
    background: linear-gradient(135deg, #f2e7c4ff, #FF9800);
    transform: scale(1.06);
    box-shadow: 0 10px 22px rgba(255,152,0,0.18);
  }
  .btn-submit {
    background: linear-gradient(135deg, #FF9800, #FFD54F);
    color: white;
    font-weight: bold;
  }
  .btn-submit:hover {
    background: linear-gradient(135deg, #FFD54F, #FF9800);
    transform: scale(1.08);
    box-shadow: 0 10px 22px rgba(255,152,0,0.22);
  }
  .modal-content .btn {
    color: #fff;
    padding: 12px 24px;
    border: none;
    border-radius: 25px;
    cursor: pointer;
    font-size: 16px;
    font-weight: bold;
    box-shadow: 0 8px 18px rgba(255,152,0,0.22), 0 2px 10px rgba(255,193,7,0.12);
    transition: background 0.22s, box-shadow 0.22s, transform 0.18s;
  }
  .modal-content .btn:hover {
    background: linear-gradient(135deg, #FFD54F, #FF9800);
    transform: scale(1.08);
    box-shadow: 0 10px 22px rgba(255,152,0,0.22);
  }
  .price-pill {
    display:inline-block;
    padding:3px 8px;
    border:1px solid orange;
    border-radius:8px;
    font-size:13px;
    margin-right:6px;
    background:#fffaf0;
  }
  .modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0; top: 0;
    width: 100%; height: 100%;
    background: rgba(0,0,0,0.4);
    justify-content: center;
    align-items: center;
  }
  .modal-content {
    background: #fff;
    border-radius: 16px;
    padding: 30px 40px;
    text-align: center;
    max-width: 400px;
    width: 90%;
    box-shadow: 0 8px 20px rgba(0,0,0,0.2);
    animation: scaleIn 0.3s ease-out;
  }
  .modal-content .icon {
    width: 70px;
    height: 70px;
    background: #E8F9EE;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 20px;
  }
  .modal-content .icon svg {
    width: 40px;
    height: 40px;
    stroke: #4CAF50;
    stroke-width: 3;
    fill: none;
  }
  .modal-content h2 {
    margin: 0 0 10px;
    font-size: 24px;
    color: #333;
  }
  .modal-content p {
    margin: 0 0 20px;
    color: #666;
  }
  .modal-content .btn {
    background: #ffa467ff;
    color: #fff;
    padding: 12px 24px;
    border: none;
    border-radius: 25px;
    cursor: pointer;
    font-size: 16px;
    font-weight: bold;
    transition: background 0.2s;
  }
  .modal-content .btn:hover {
    background: #ff6600;
  }
  @keyframes scaleIn {
    from { transform: scale(0.8); opacity: 0; }
    to { transform: scale(1); opacity: 1; }
  }
  .eating-anim-box {
    width: 140px;
    height: 120px;
    background: none;
    box-shadow: none;
    margin: 0 auto 20px;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .eating-anim {
    width: 140px;
    height: 120px;
    display: block;
  }
  .chopsticks-group {
    transform-origin: 122px 92px;
    animation: chopsticksEat 1.6s cubic-bezier(.7,1.5,.5,1) infinite;
  }
  @keyframes chopsticksEat {
    0%, 52% { transform: rotate(-21deg) translate(0,0);}
    56% { transform: rotate(-8deg) translate(-18px, -30px);}
    68% { transform: rotate(-8deg) translate(-18px, -30px);}
    75%, 100% { transform: rotate(-21deg) translate(0,0);}
  }
  .food-item {
    animation: foodMove 1.6s cubic-bezier(.7,1.5,.5,1) infinite;
    transform-origin: 122px 92px;
  }
  @keyframes foodMove {
    0%, 52% { transform: translate(0,0);}
    56% { transform: translate(-18px, -30px);}
    68% { transform: translate(-18px, -30px);}
    75%, 100% { transform: translate(0,0);}
  }
  .mouth-group .mouth-path {
    transform-origin: 80px 88px;
    animation: mouthOpen 1.6s cubic-bezier(.7,1.5,.5,1) infinite;
  }
  @keyframes mouthOpen {
    0%, 51% { d: path("M65 80 Q80 93 95 80 Q80 106 65 80"); }
    57%, 67% { d: path("M65 80 Q80 110 95 80 Q80 106 65 80"); }
    75%, 100% { d: path("M65 80 Q80 93 95 80 Q80 106 65 80"); }
  }
  .mouth-group .mouth-path {
    transition: transform 0.16s;
  }
  @media (prefers-reduced-motion: reduce) {
    .chopsticks-group, .food-item, .mouth-group .mouth-path { animation: none !important; }
  }
  .floating-decor {
    position: fixed;
    opacity: 0.85;
    pointer-events: none;
    z-index: -1;
  }
  .decor-1 {
    bottom: 40px;
    left: 40px;
    width: 120px;
    animation: floatYmed 8s ease-in-out infinite;
  }
  .decor-2 {
    top: 50px;
    right: 50px;
    width: 90px;
    animation: floatXmed 7s ease-in-out infinite;
  }
  .decor-3 {
    bottom: 120px;
    left: 50%;
    transform: translateX(-50%);
    width: 110px;
    animation: floatXYmed 9s ease-in-out infinite;
  }
  .decor-4 {
    top: 120px;
    left: 200px;
    width: 95px;
    animation: floatYmed 7s ease-in-out infinite;
  }
  .decor-5 {
    bottom: 220px;
    right: 120px;
    width: 100px;
    animation: floatXmed 8s ease-in-out infinite;
  }
  .decor-6 {
    top: 200px;
    left: 65%;
    width: 105px;
    animation: floatXYmed 10s ease-in-out infinite;
  }
  @keyframes floatYmed {
    0%,100% { transform: translateY(0); }
    50%     { transform: translateY(-8px); }
  }
  @keyframes floatXmed {
    0%,100% { transform: translateX(0); }
    50%     { transform: translateX(10px); }
  }
  @keyframes floatXYmed {
    0%,100% { transform: translate(0,0); }
    50%     { transform: translate(-24px,32px); }
  }
  .table-label {
    display: block;
    font-size: 17px;
    font-weight: bold;
    color: #333;
    background: #f5f5f5;
    border-radius: 8px;
    padding: 10px 12px;
    margin-top: 6px;
    margin-bottom: 10px;
    border: 1px solid #FFD54F;
    width: fit-content;
  }
      #assistanceBtn {
      background: linear-gradient(135deg, #ff8c4eff, #ff6e3eff);
      color: white;
      border-radius: 50%;
      width: 50px;
      height: 50px;
      margin-left: 16px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 27px;
      border: none;
      box-shadow: 0 6px 16px rgba(230,74,25,0.16), 0 2px 8px rgba(255,152,0,0.13);
      cursor: pointer;
    }
    #assistanceBtn:hover {
      background: linear-gradient(135deg, #FFD54F, #FF9800);
      color: #fff;
      transform: scale(1.08);
    }
    .footer-actions {
      display: flex;
      justify-content: flex-end;
      align-items: center;
      padding: 18px 0 4px 4px;
      gap: 12px;
    }
  /* Payment options styles */
.payment-options input[type="radio"] {
  /* Hide the native radio input */
  position: absolute;
  opacity: 0;
  width: 0;
  height: 0;
}

.payment-options label {
  position: relative;
  padding-left: 32px;
  margin-right: 18px;
  font-weight: 600;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  transition: color 0.2s;
}

.payment-options label::before {
  content: '';
  display: inline-block;
  position: absolute;
  left: 0;
  top: 50%;
  transform: translateY(-50%);
  width: 22px;
  height: 22px;
  border-radius: 50%;
  border: 2px solid #FF9800;
  background: #fff;
  box-shadow: 0 1px 2px rgba(255,152,0,0.08);
  transition: border-color 0.22s;
}

.payment-options input[type="radio"]:checked + label::before {
  border-color: #FFD54F;
  background: linear-gradient(135deg, #FFD54F 70%, #FF9800 100%);
}

.payment-options label::after {
  content: '';
  position: absolute;
  left: 6px;
  top: 50%;
  transform: translateY(-50%) scale(0);
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: #FF9800;
  transition: transform 0.18s, background 0.18s;
  pointer-events: none;
}

.payment-options input[type="radio"]:focus + label::before {
  outline: 2px solid #FFD54F;
  outline-offset: 1px;
}

/* Accessibility: Provide visible focus */
.payment-options input[type="radio"]:focus + label {
  color: #FF9800;
}
  </style>
</head>
<body>
  
      <!-- Floating Decorations ... unchanged ... -->
  <div>
    <!-- Floating Decorations ... unchanged ... -->
    <img src="images/decor-noodles.png" class="floating-decor decor-1" alt="Decorative Noodles">
    <img src="images/decor-noodles.png" class="floating-decor decor-2" alt="Decorative Noodles">
    <img src="images/decor-dessert.png" class="floating-decor decor-3" alt="Decorative Dessert">
    <img src="images/decor-noodles.png" class="floating-decor decor-4" alt="Decorative Noodles">
    <img src="images/decor-noodles.png" class="floating-decor decor-5" alt="Decorative Noodles">
    <img src="images/decor-dessert.png" class="floating-decor decor-6" alt="Decorative Dessert">
  </div>

  <div class="container-wrapper">
    <div class="container">
      <div class="header">
        <img src="images/logo.png" alt="Logo">
        <h1>Review of Orders</h1>
      </div>
      <div class="divider"></div>
      <?php if (empty($cart)): ?>
        <p style="text-align:center;font-size:20px;">Your cart is empty.</p>
      <?php else: ?>
        <?php foreach ($cart as $id => $item):
          $itemId = isset($item['id']) ? (int)$item['id'] : 0;
          $imagePath = !empty($item['image']) ? "/thai_digital/uploads/" . rawurlencode($item['image']) : "assets/img/placeholder.png";
          $selectedType = strtolower($item['selected_type'] ?? 'solo');
          $stock        = isset($productStocks[$itemId]) ? $productStocks[$itemId] : (isset($item['stock']) ? intval($item['stock']) : 1000);
          $quantity     = isset($item['quantity']) ? intval($item['quantity']) : 1;
          if ($stock <= 0) $quantity = 0;
          if ($quantity > $stock) $quantity = $stock;
          $displayPrice = ($selectedType === "sharing") ? floatval($item['price_sharing'] ?? $item['price'] ?? 0) : floatval($item['price_solo'] ?? $item['price'] ?? 0);
          $cat = strtolower($item['category_name'] ?? '');
        ?>
        <div class="cart-item" data-id="<?= htmlspecialchars($id) ?>">
          <img src="<?= htmlspecialchars($imagePath) ?>" alt="<?= htmlspecialchars($item['name'] ?? '') ?>">
          <div class="item-info">
            <h3><?= htmlspecialchars($item['name'] ?? '') ?></h3>
            <p>
              <?php if (in_array($cat, ['noodles', 'fried rice'])): ?>
                  <span class="price-pill"><?= ucfirst($selectedType) ?></span>
              <?php endif; ?>
              ₱<?= number_format($displayPrice, 2) ?>
            </p>
            <?php if (!in_array($cat, ['add-ons', 'drinks', 'dessert'])): ?>
                <p style="color:red;">
                  Spice Level: <?= htmlspecialchars($item['spice'] ?? 'No Spice') ?>
                </p>
                <?php if (!empty($item['allergens'])): ?>
                  <p style="color:darkblue;">Allergens: <?= htmlspecialchars($item['allergens']) ?></p>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (!empty($item['allergen_note'])): ?>
              <p style="color:darkgreen;">Note: <?= htmlspecialchars($item['allergen_note']) ?></p>
            <?php endif; ?>
            <?php if ($stock == 0): ?>
              <p style="color:#ad0909;font-weight:bold;">Out of stock</p>
            <?php elseif ($quantity == 0): ?>
              <p style="color:#ad0909;font-weight:bold;">Unavailable!</p>
            <?php elseif ($stock <= 10): ?>
              <p style="color:#FFD54F;"> <?= $stock ?> serving available</p>
            <?php elseif ($stock < $quantity): ?>
              <p style="color:#ad0909;font-weight:bold;">Limited: Only <?= $stock ?> available!</p>
            <?php endif; ?>
          </div>
          <div class="qty-controls">
            <button class="qty-btn"
  onclick="updateQty('<?= rawurlencode($id) ?>', -1)"
  <?= ($stock <= 0 || $quantity <= 0 || $stock === 1) ? 'disabled' : '' ?>
>-</button>
            <span id="qty-<?= $id ?>"><?= ($stock <= 0 ? 0 : $quantity) ?></span>
            <button class="qty-btn"
              onclick="updateQty('<?= rawurlencode($id) ?>', 1)"
              <?= ($stock <= 0 || $quantity >= $stock) ? 'disabled' : '' ?>
            >+</button>
            <button class="remove-btn" onclick="removeItem('<?= rawurlencode($id) ?>')">Remove</button>
            <?php if ($stock <= 0 || $quantity == 0): ?>
              <span style="color:#ad0909;font-weight:bold;margin-left:12px;">Unavailable</span>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
        <div class="total">Total: ₱<?= number_format($total, 2) ?></div>
      <?php endif; ?>

        <!-- Ask for Assistance Modal -->
  <div id="assistModal" class="modal">
    <div class="modal-content" style="color:#E64A19;">
      <p>Do you want to request staff assistance?</p>
      <button type="button" class="btn" id="confirmAssistance"
        style="background: linear-gradient(135deg, #FF9800, #FFD54F); color: white; margin-right: 12px; border-radius: 18px; font-weight: bold;">
        Yes, Call Staff
      </button>
      <button type="button" class="btn" id="cancelAssistance"
        style="background: #aaa; color: white; font-weight: bold; border-radius: 18px;">
        Cancel
      </button>
    </div>
  </div>
  <!-- Assistance Success Modal -->
  <div id="assistSuccessModal" class="modal">
    <div class="modal-content" style="color:green;">
      <p>Staff assistance requested! They'll arrive at your table soon.</p>
      <button type="button" class="btn" id="assistSuccessOK"
        style="background: linear-gradient(135deg, #FF9800, #FFD54F); color: white; border-radius: 18px;">
        OK
      </button>
    </div>
  </div>
      <!-- Remove Confirmation Modal -->
      <div id="removeConfirmModal" class="modal">
        <div class="modal-content">
          <h2>Remove Item?</h2>
          <p>Are you sure you want to remove this item from your cart?</p>
          <button id="removeConfirmYes" class="btn" style="background:#E53935;color:#fff;">Yes, Remove</button>
          <button id="removeConfirmNo" class="btn" style="background:#aaa;margin-left:10px;">Cancel</button>
        </div>
      </div>
      <!-- Error Modal -->
      <div id="errorModal" class="modal">
        <div class="modal-content">
          <div class="icon">
            <svg viewBox="0 0 24 24">
              <path d="M12 2L1 21h22L12 2zm0 14v2m0-8v4" stroke="#E53935" stroke-width="3" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </div>
          <p id="errorModalMsg"></p>
          <button id="errorModalBtn" class="btn">OK</button>
        </div>
      </div>
      <!-- Customer Info -->
      <div class="field">
        <label for="customer_name">Your name for the order</label>
        <input type="text" id="customer_name" placeholder="Enter your name/nickname" minlength="2" maxlength="12" pattern="[A-Za-z\s]{2,12}" required>
      </div>
      <div class="field">
  <label for="payment_method">Payment Option</label>
<div class="payment-options">
  <input type="radio" id="payment_cash" name="payment" value="Cash" checked>
  <label for="payment_cash">Cash</label>
  <input type="radio" id="payment_bpi" name="payment" value="BPI QR">
  <label for="payment_bpi">BPI QR</label>
  <input type="radio" id="payment_gcash" name="payment" value="Gcash QR">
  <label for="payment_gcash">Gcash QR</label>
</div>
</div>
      <div class="field">
        <label for="table_number">Your Table Number:</label>
        <span class="table-label" id="table_number"><?= htmlspecialchars($table_number) ?></span>
      </div>
            <div class="actions">
        <button class="btn btn-back" onclick="history.back()">
          <i class="fa fa-arrow-left"></i> Back
        </button>
        <!-- Buttons grouped/side-by-side! -->
        <div style="display:inline-flex; gap:12px;">
          <button id="submitBtn" class="btn btn-submit" onclick="submitOrder()">
            <i class="fa fa-check-circle"></i> Complete Order
          </button>
          <button type="button" id="assistanceBtn" title="Ask for Assistance">
            <i class="fa fa-hand-paper"></i>
          </button>
        </div>
      </div>

      </div>
    </div>
  </div>
  <!-- Name Required Modal -->
  <div id="nameModal" class="modal">
    <div class="modal-content">
      <div class="icon">
        <svg viewBox="0 0 24 24">
          <path d="M12 2L1 21h22L12 2zm0 14v2m0-8v4" stroke="#FF9800" stroke-width="3" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </div>
      <p id="nameModalMsg">Please enter your name so we can serve you properly.</p>
      <button id="nameModalBtn" class="btn">OK</button>
    </div>
  </div>
  <!-- Confirm Order Modal -->
  <div id="confirmModal" class="modal">
    <div class="modal-content">
      <h2>Do you want to place your order now?</h2>
      <button id="confirmYes" class="btn">Yes, Place Order</button>
      <button id="confirmNo" class="btn" style="background:#aaa;margin-left:10px;">Cancel</button>
    </div>
  </div>
<!-- Success Modal with Animated Eating Icon -->
<div id="successModal" class="modal">
  <div class="modal-content">
    <div class="icon eating-anim-box">
      <svg class="eating-anim" viewBox="0 0 160 120" width="140" height="120" fill="none">
        <ellipse cx="80" cy="60" rx="54" ry="54" fill="#FFECB3" stroke="#f39c12" stroke-width="3"/>
        <ellipse cx="60" cy="58" rx="6" ry="8" fill="#e65100"/>
        <ellipse cx="100" cy="58" rx="6" ry="8" fill="#e65100"/>
        <g class="mouth-group"><path class="mouth-path" d="M65 80 Q80 93 95 80 Q80 106 65 80" fill="#e65100"/></g>
        <g class="chopsticks-group">
          <rect x="110" y="25" width="5" height="48" rx="2.5" fill="#B8832F" transform="rotate(14 112.5 49)"/>
          <rect x="118" y="28" width="5" height="50" rx="2.5" fill="#FFD54F" transform="rotate(16 120.5 53)"/>
          <ellipse class="food-item" cx="122" cy="92" rx="10" ry="7" fill="#FFB74D" stroke="#e65100" stroke-width="2"/>
        </g>
      </svg>
    </div>
    <h2>Order Placed!</h2>
    <p>Your order has been successfully submitted.</p>
    <button id="nextBtn" class="btn">View Order Summary</button>
  </div>
</div>
  <!-- Cart Empty Modal -->
  <div id="cartEmptyModal" class="modal">
    <div class="modal-content">
      <div class="icon">
        <svg viewBox="0 0 24 24">
          <path d="M12 2L1 21h22L12 2zm0 14v2m0-8v4" stroke="#FF9800" stroke-width="3" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </div>
      <p>Your cart is empty. Please add items before placing the order.</p>
      <button id="cartEmptyBtn" class="btn">OK</button>
    </div>
  </div>
  <script>
  const CART = <?= json_encode($jsCart, JSON_UNESCAPED_UNICODE) ?>;
  // Read add_to from server-side GET (if present) and persist to localStorage so it is included when placing the order.
  const ADD_TO_FROM_SERVER = <?= json_encode($_GET['add_to'] ?? '') ?> || '';
  try {
    if (ADD_TO_FROM_SERVER) {
      localStorage.setItem('add_to_order_group', ADD_TO_FROM_SERVER);
    }
  } catch (e) { /* ignore localStorage errors */ }
  const ADD_TO = ADD_TO_FROM_SERVER || (localStorage.getItem ? (localStorage.getItem('add_to_order_group') || '') : '');

  let removeItemId = null;

  function removeItem(id) {
    removeItemId = id;
    document.getElementById('removeConfirmModal').style.display = 'flex';
  }

  document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('removeConfirmModal');
    const yesBtn = document.getElementById('removeConfirmYes');
    const noBtn = document.getElementById('removeConfirmNo');
    yesBtn.onclick = function() {
      fetch('remove_from_cart.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'id=' + encodeURIComponent(removeItemId)
      }).then(() => location.reload());
      modal.style.display = 'none';
    };
    noBtn.onclick = function() {
      removeItemId = null;
      modal.style.display = 'none';
    };
    localStorage.setItem('cart_updated', Date.now());
    document.getElementById('errorModalBtn').onclick = function() {
      document.getElementById('errorModal').style.display = 'none';
    };
    document.querySelectorAll('.modal').forEach(function(modalDiv) {
      modalDiv.addEventListener('mousedown', function(e) {
        if (e.target === modalDiv) {
          modalDiv.style.display = 'none';
        }
      });
    });
  });

  function showErrorModal(msg) {
    document.getElementById('errorModalMsg').textContent = msg;
    document.getElementById('errorModal').style.display = 'flex';
  }

  function updateQty(id, change) {
    let item = CART.find(i => i.id == id);
    if (!item) return;

    // Calculate new desired quantity
    let newQty = item.quantity + change;

    // Prevent adding past available stock, and show error if needed
    if (change > 0 && item.quantity >= item.stock) {
      showErrorModal("Stock limit reached. Cannot add more.");
      return;
    }
    if (newQty > item.stock) {
      showErrorModal("This item exceeds its stock: Only " + item.stock + " left.");
      return;
    }
    if (item.stock <= 0) {
      showErrorModal("This item is currently unavailable (out of stock).");
      return;
    }
    if (newQty < 0) newQty = 0; // Prevent negative

    if (newQty === 0) {
      let result = confirm("Do you want to remove this item from your cart?");
      if (!result) return;
    }

    fetch('update_cart.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: 'id=' + encodeURIComponent(id) + '&quantity=' + newQty
    })
    .then(res => res.json())
    .then(data => {
      if (data.success) location.reload();
      else showErrorModal("Error updating item: " + (data.msg ?? "Unknown error"));
    });
  }

  function isValidName(name) {
    return /^[A-Za-z\s]{2,12}$/.test(name);
  }

  async function submitOrder() {
    const nameInput = document.getElementById('customer_name');
    const name = nameInput.value.trim();
    const table = document.getElementById('table_number').textContent.trim();
// Get selected payment option
const paymentSelected = document.querySelector('input[name="payment"]:checked');
const payment = paymentSelected ? paymentSelected.value : null;
if (!payment) {
  showPaymentModal("Please select a payment option (Cash, BPI QR, or Gcash QR).");
  return;
}
    if (!name) {
      showNameModal("Please enter your name so we can serve you properly.");
      return;
    }
    if (!isValidName(name)) {
      showNameModal("Name must be 2-12 characters, letters and spaces only.");
      return;
    }

    if (!Array.isArray(CART) || CART.length === 0) {
      document.getElementById('cartEmptyModal').style.display = 'flex';
      document.getElementById('cartEmptyBtn').onclick = () => {
        document.getElementById('cartEmptyModal').style.display = 'none';
      };
      return;
    }

    let hasUnavailable = CART.some(item => item.stock <= 0 || item.quantity <= 0);
    if (hasUnavailable) {
      showErrorModal("Your cart contains unavailable item(s). Please remove or update them.");
      return;
    }

    let overStockedItem = CART.find(item => item.quantity > item.stock);
    if (overStockedItem) {
      showErrorModal("Your selection for \"" + overStockedItem.name + "\" exceeds its stock: Only " + overStockedItem.stock + " left.");
      return;
    }

    const confirmModal = document.getElementById('confirmModal');
    const confirmYes = document.getElementById('confirmYes');
    const confirmNo = document.getElementById('confirmNo');

    confirmModal.style.display = "flex";
    confirmNo.onclick = () => {confirmModal.style.display = "none";};
    confirmYes.onclick = async () => {
      confirmModal.style.display = "none";
      const submitBtn = document.getElementById('submitBtn');
      submitBtn.disabled = true;
      submitBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Placing Order...';
      const payload = {
        customer_name: name,
        table_number: table,
         payment_method: payment,
        // include add_to only when present so place_order.php will append to existing order
        ...(ADD_TO ? { add_to: ADD_TO } : {}),
        cart: CART.map(i => ({
          ...i,
          quantity: Math.min(i.quantity, i.stock)
        }))
      };
      try {
        const res = await fetch('place_order.php', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify(payload)
        });
        const text = await res.text();
        let data;
        try {
          data = JSON.parse(text);
        } catch {
          showErrorModal("Server returned an invalid response.");
          submitBtn.disabled = false;
          submitBtn.innerHTML = '<i class="fa fa-check-circle"></i> Complete Order';
          return;
        }
        if (data.status === 'success' && data.order_id) {
          // If we appended (or used ADD_TO), clear persisted add_to to avoid accidental reuse
          try {
            if (ADD_TO || data.appended) localStorage.removeItem('add_to_order_group');
          } catch (e) { /* ignore */ }

          const modal = document.getElementById("successModal");
          const nextBtn = document.getElementById("nextBtn");
          modal.style.display = "flex";
          nextBtn.onclick = () => {
            window.location.href = 'summary_orders.php?id=' + encodeURIComponent(data.order_id);
          };
        } else {
          showErrorModal("Failed: " + (data.message ?? data.msg ?? "Unknown error"));
        }
      } catch (err) {
        showErrorModal("Error placing order: " + err);
      }
      submitBtn.disabled = false;
      submitBtn.innerHTML = '<i class="fa fa-check-circle"></i> Complete Order';
    };
  }

  function showNameModal(msg) {
    const nameModal = document.getElementById('nameModal');
    const nameModalBtn = document.getElementById('nameModalBtn');
    document.getElementById('nameModalMsg').textContent = msg;
    nameModal.style.display = "flex";
    nameModalBtn.onclick = () => {
      nameModal.style.display = "none";
      document.getElementById('customer_name').focus();
    };
  }
function showPaymentModal(msg) {
  // reuse the existing nameModal for a simple prompt
  const nameModal = document.getElementById('nameModal');
  const nameModalBtn = document.getElementById('nameModalBtn');
  document.getElementById('nameModalMsg').textContent = msg;
  nameModal.style.display = "flex";
  nameModalBtn.onclick = () => {
    nameModal.style.display = "none";
    // focus first payment radio
    const firstRadio = document.querySelector('input[name="payment"]');
    if (firstRadio) firstRadio.focus();
  };
}
  //Assistance

  document.addEventListener('DOMContentLoaded', function() {
    // Assistance button logic
    const assistanceBtn = document.getElementById("assistanceBtn");
    const assistModal = document.getElementById("assistModal");
    const confirmAssistance = document.getElementById("confirmAssistance");
    const cancelAssistance = document.getElementById("cancelAssistance");
    const assistSuccessModal = document.getElementById("assistSuccessModal");
    const assistSuccessOK = document.getElementById("assistSuccessOK");

    // Get table_number from PHP variable in page
    const tableNumberElem = document.getElementById('table_number');
    const table_number = tableNumberElem ? tableNumberElem.textContent.trim() : "";

    // Get name from "customer_name" if available
    const customerNameInput = document.getElementById('customer_name');
    function getCustomerName() {
      return customerNameInput ? customerNameInput.value.trim() : "";
    }

    assistanceBtn.onclick = function() {
      assistModal.style.display = "flex";
    };
    cancelAssistance.onclick = function() {
      assistModal.style.display = "none";
    };
    confirmAssistance.onclick = function() {
      assistModal.style.display = "none";
      // Compose payload using device table number and customer name
      const payload = {
        order_group_id: "",
        table_number: table_number,
        customer_name: getCustomerName(),
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
  });
  </script>
</body>
</html>