<?php
session_start();
include "../config.php";

// ✅ Check if logged in & role = cashier
if (!isset($_SESSION['staff_id']) || $_SESSION['role'] !== 'cashier') {
    $showLoginModal = true;
} else {
    $showLoginModal = false;
}

// ✅ Handle login POST
$loginError = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_submit'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === "" || $password === "") {
        $loginError = "Please enter username and password.";
    } else {
        $stmt = $conn->prepare("SELECT id, username, password_hash, role FROM staff_accounts WHERE username=? LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($row = $res->fetch_assoc()) {
            if (password_verify($password, $row['password_hash'])) {
                if ($row['role'] === 'cashier') {
                    $_SESSION['staff_id'] = $row['id'];
                    $_SESSION['role'] = $row['role'];
                    $_SESSION['username'] = $row['username'];

                    // ✅ Update last_login
                    $upd = $conn->prepare("UPDATE staff_accounts SET last_login=NOW() WHERE id=?");
                    $upd->bind_param("i", $row['id']);
                    $upd->execute();

                    header("Location: index.php");
                    exit;
                } else {
                    $loginError = "Access denied: only cashier staff allowed.";
                }
            } else {
                $loginError = "Invalid password.";
            }
        } else {
            $loginError = "User not found.";
        }
        $stmt->close();
    }
}

// Fetch categories and items
$categoriesResult = $conn->query("SELECT * FROM categories ORDER BY name ASC");
$menuByCategory = [];
while ($cat = $categoriesResult->fetch_assoc()) {
    $catId = $cat['id'];
    $itemsResult = $conn->query("SELECT * FROM menu_items WHERE category=$catId ORDER BY name ASC");
    $menuByCategory[$cat['name']] = $itemsResult->fetch_all(MYSQLI_ASSOC);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Cashier POS</title>
<link rel="stylesheet" href="index.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
 /* === Login Modal === */
.modal-content2 {
  background: #fff;
  padding: 30px 25px;
  border-radius: 12px;
  width: 360px;        /* ✅ fixed width */
  max-width: 95%;      /* ✅ safe on mobile */
  box-shadow: 0 12px 28px rgba(0,0,0,0.35);
  text-align: center;
  animation: slideUp 0.3s ease;
}

  .modal-content2 h2 {
    margin: 0 0 20px;
    font-size: 22px;
    font-weight: bold;
    color: #333;
  }
.modal-content2 .input-group {
  position: relative;
  margin-bottom: 15px;
  width: 100%;
}

.modal-content2 .input-group i {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  color: #888;
  font-size: 16px;
  pointer-events: none;
}

.modal-content2 input {
  width: 100%;
  padding: 12px 12px 12px 38px;
  border: 1px solid #ccc;
  border-radius: 8px;
  font-size: 15px;
  transition: 0.3s;
  box-sizing: border-box;
  display: block;
}

.modal-content2 input:focus {
    border-color: #ff6600;
    outline: none;
    box-shadow: 0 0 6px rgba(255,102,0,0.3);
}
.btn-login {
    width: 100%;
    padding: 12px;
    background: #ff6600;
    border: none;
    border-radius: 8px;
    color: #fff;
    font-weight: bold;
    font-size: 15px;
    cursor: pointer;
    transition: 0.3s;
}
.btn-login:hover {
    background: #e05500;
}
.error {
    color: #d32f2f;
    font-size: 14px;
    margin-bottom: 12px;
}

@keyframes slideUp {
  from { transform: translateY(30px); opacity: 0; }
  to { transform: translateY(0); opacity: 1; }
}

.logout-btn {
  display: inline-block;
  padding: 6px 14px;
  background: #e05500;
  color: #fff;
  font-weight: bold;
  border-radius: 8px;
  text-decoration: none;
  font-size: 14px;
  transition: all 0.3s ease;
  border: 2px solid transparent;
  cursor: pointer;
  margin-left: 15px;
}

.logout-btn:hover {
  background: #fff;
  color: #e05500;
  border: 2px solid #e05500;
  box-shadow: 0 4px 12px rgba(224, 85, 0, 0.3);
}

/* Scrollbars */
::-webkit-scrollbar {
  width: 12px;
  height: 12px;
}
::-webkit-scrollbar-track {
  background: #f0f0f0;
  border-radius: 10px;
}
::-webkit-scrollbar-thumb {
  background-color: #ff6600;
  border-radius: 10px;
  border: 3px solid #f0f0f0;
  transition: background 0.3s;
}
::-webkit-scrollbar-thumb:hover {
  background-color: #e05500;
}
* {
  scrollbar-width: thin;
  scrollbar-color: #5ee3fbff #f0f0f0;
}
/* MODERN MODAL STYLES */
#itemModal,
#servingSizeModal {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.4);
  z-index: 9999;
  align-items: center;
  justify-content: center;
  overflow-y: auto;
  padding: 20px;
  box-sizing: border-box;
}
.btn-pay {
  background: #2196f3;
  color: #fff;
  border: none;
  border-radius: 7px;
  padding: 7px 26px;
  font-size: 1.07rem;
  font-weight: 500;
  cursor: pointer;
  margin-left: 6px;
  box-shadow: 0 2px 6px #0001;
  transition: background 0.2s;
}
.btn-pay:active,
.btn-pay:hover {
  background: #1976d2;
}

.modern-modal {
  background: #fff9ee;
  border-radius: 22px;
  width: 100%;
  max-width: 380px;
  box-shadow: 0 2px 24px #0002;
  position: relative;
  text-align: center;
  font-family: inherit;
  display: flex;
  flex-direction: column;
  max-height: 90vh;
  overflow-y: auto;
  padding: 28px 22px 22px 22px;
}

@media (max-width: 480px) {
  .modern-modal {
    border-radius: 16px;
    padding: 22px 18px 18px 18px;
    max-width: 95vw;
  }

  .modal-img img {
    width: 110px;
    height: 110px;
  }

  .modal-title {
    font-size: 1.1rem;
  }

  .add-cart-btn,
  .back-btn {
    flex: 1;
    font-size: 1rem;
    padding: 10px;
  }

  textarea#modalNotesInput {
    font-size: 0.9rem;
  }
}

.modal-close {
  position: absolute;
  top: 11px;
  right: 20px;
  font-size: 23px;
  color: #b52;
  cursor: pointer;
  font-weight: bold;
}

.modal-img img {
  width: 140px;
  height: 140px;
  object-fit: cover;
  border-radius: 50%;
  border: 3px solid #e0c799;
  margin-bottom: 8px;
}

.modal-title {
  margin: 8px 0 7px 0;
  font-size: 1.28rem;
  font-weight: bold;
  color: #333;
}

.modal-price {
  font-size: 1.08rem;
  font-weight: bold;
  color: #ff6600;
  margin-bottom: 7px;
}

.modal-qty-row {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  margin-bottom: 8px;
}

.qty-btn {
  background: #ffc107;
  color: #333;
  border: none;
  border-radius: 5px;
  font-size: 1.18rem;
  font-weight: bold;
  padding: 5px 14px;
  cursor: pointer;
  transition: background 0.2s;
}

.qty-btn:active {
  background: #e0a800;
}

.qty-value {
  font-size: 1.15rem;
  font-weight: bold;
  min-width: 28px;
  text-align: center;
}

.modal-desc {
  font-size: 0.98rem;
  margin: 12px 0 7px 0;
  color: #444;
  text-align: center;
  line-height: 1.5;
}

.modal-spice-row {
  margin: 13px 0 9px 0;
  text-align: center;
}

.spice-label {
  display: block;
  margin-bottom: 2px;
  font-weight: 500;
  color: #d72c0d;
  font-size: 1.01rem;
}

.spice-slider {
  width: 92%;
  margin: 0 0 3px 0;
  accent-color: #e31b0c;
}

.spice-labels {
  display: flex;
  justify-content: space-between;
  font-size: 0.95rem;
  margin-top: 1px;
  margin-bottom: 4px;
  color: #d72c0d;
}

.spice-labels span {
  flex: 1;
  text-align: center;
}

.modal-total {
  margin: 13px 0 7px 0;
  font-size: 1.10rem;
  font-weight: bold;
  color: #ff6600;
}

.modal-actions {
  margin-top: 5px;
  display: flex;
  gap: 10px;
  justify-content: center;
}

.add-cart-btn {
  background: #ffc107;
  color: #222;
  font-weight: 500;
  font-size: 1.07rem;
  border: none;
  border-radius: 7px;
  padding: 7px 26px;
  cursor: pointer;
  box-shadow: 0 2px 6px #0001;
  transition: background 0.2s;
}

.add-cart-btn:active {
  background: #e0a800;
}

.back-btn {
  background: #fff;
  color: #222;
  border: 1.3px solid #ccc;
  border-radius: 7px;
  padding: 7px 26px;
  font-size: 1.07rem;
  cursor: pointer;
  font-weight: 500;
}

.back-btn:active {
  background: #f5f5f5;
}
.allergen-tag {
    display: inline-block;
    background: #e7f3ff;
    color: #005891;
    border-radius: 12px;
    padding: 2px 10px;
    font-size: 13px;
    margin: 0 4px 4px 0;
    border: 1px solid #a5c8e7;
    font-weight: 500;
    cursor: pointer;
    user-select: none;
}
.allergen-tag input[type=checkbox] {
    margin-right: 4px;
    vertical-align: middle;
}
.cart-allergen-tag {
    display: inline-block;
    background: #e7f3ff;
    color: #005891;
    border-radius: 12px;
    padding: 2px 10px;
    font-size: 12px;
    margin: 0 2px 2px 0;
    border: 1px solid #a5c8e7;
    font-weight: 500;
}
.cart-notes {
    background: #f6f6f6;
    border-radius: 6px;
    padding: 4px 8px;
    border: 1px solid #ddd;
    font-size: 12px;
    margin: 2px 0 0 0;
    color: #444;
}
.notification-btn {
    font-size: 1.2rem;
    color: #ff9800;
    margin-left: 18px;
    transition: color 0.2s;
}
.notification-btn:hover {
    color: #d72c0d;
}
/*NOtif*/
.modal {
  display: none;
  position: fixed;
  z-index: 10000;
  left: 0; top: 0;
  width: 100%; height: 100%;
  background: rgba(0,0,0,0.6);
}
.modal-content {
  background: #fff;
  margin: 8% auto;
  padding: 20px;
  border-radius: 10px;
  width: 95%;
  max-width: 800px;
  box-shadow: 0 6px 20px rgba(0,0,0,0.3);
}
.close-btn {
  float: right;
  font-size: 24px;
  font-weight: bold;
  cursor: pointer;
}
table {
  width: 100%;
  border-collapse: collapse;
  margin-top: 15px;
}
th, td {
  border: 1px solid #ddd;
  padding: 10px;
  text-align: center;
}
th {
  background: #ff5722;
  color: #fff;
}
.status-pending { color: #ff9800; font-weight: bold; }
.status-ack { color: #2196f3; font-weight: bold; }
.status-done { color: #4caf50; font-weight: bold; }
.btn-ack { background: #2196f3; color: white; border: none; padding: 6px 10px; cursor: pointer; border-radius: 5px; }
.btn-done { background: #4caf50; color: white; border: none; padding: 6px 10px; cursor: pointer; border-radius: 5px; }
.category-tabs {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-bottom: 18px;
}
.category-btn {
    background: #2196f3;
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 16px 0;
    font-size: 1.07rem;
    font-weight: 500;
    cursor: pointer;
    box-shadow: 0 2px 6px #0001;
    transition: background 0.2s;
}
.category-btn.active,
.category-btn:hover {
    background: #2ecc40;
    color: #fff;
}
/* Scrollable Customer Requests Modal */
#requestsModalContent {
  max-height: 80vh; /* modal box max height */
  overflow-y: auto; /* scroll modal vertically */
  min-width: 340px;
}

.requests-table-wrapper {
  max-height: 65vh; /* table area max height */
  overflow-y: auto; /* scroll table vertically */
  width: 100%;
}

/* Make the table header sticky for long lists */
.requests-table-wrapper th {
  position: sticky;
  top: 0;
  background: #ff5722;
  color: #fff;
  z-index: 2;
}

/* Responsive: reduce max-height on small screens */
@media (max-width: 600px) {
  #requestsModalContent {
    max-height: 90vh;
    min-width: 0;
  }
  .requests-table-wrapper {
    max-height: 70vh;
  }
}
.cash-confirm-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.45);
  display: none;
  align-items: center;
  justify-content: center;
  z-index: 12000;
  padding: 20px;
  box-sizing: border-box;
}
.cash-confirm-overlay.visible { display: flex; }

/* Use the same light/cream modal look as the rest of cashier UI */
.cash-confirm {
  width: 520px;
  max-width: calc(100% - 40px);
  background: #fff9ee; /* same as .modern-modal */
  border-radius: 22px;
  color: #222;
  box-shadow: 0 12px 32px rgba(15,15,15,0.18);
  overflow: hidden;
  font-family: inherit;
  border: 1px solid rgba(0,0,0,0.06);
  display: flex;
  flex-direction: column;
}

/* header */
.cash-confirm .cf-header {
  padding: 14px 18px;
  background: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(0,0,0,0.01));
  display:flex;
  align-items:center;
  gap:12px;
}
.cash-confirm .cf-title {
  font-weight:700;
  font-size:16px;
  color:#333;
  letter-spacing: 0.1px;
}

/* body text uses same subdued dark text as page */
.cash-confirm .cf-body {
  padding: 18px;
  font-size: 14px;
  color: #333;
  line-height:1.5;
  background: transparent;
  border-top: 1px solid rgba(0,0,0,0.03);
  border-bottom: 1px solid rgba(0,0,0,0.03);
}

/* actions - align with other modals (orange primary, bordered secondary) */
.cash-confirm .cf-actions {
  display:flex;
  gap:12px;
  justify-content:flex-end;
  padding: 14px 18px;
  background: transparent;
}

/* Make existing add-cart-btn/back-btn look good inside confirm */
.cash-confirm .add-cart-btn {
  background: linear-gradient(180deg,#ffc107,#e0a800);
  color: #222;
  padding: 8px 20px;
  border-radius: 10px;
  font-weight: 700;
  box-shadow: 0 6px 18px rgba(224,136,18,0.12);
  border: 1px solid rgba(0,0,0,0.05);
  cursor: pointer;
}
.cash-confirm .add-cart-btn:active { transform: translateY(1px); }

.cash-confirm .back-btn {
  background: #fff;
  color: #222;
  border: 1.2px solid #d1d1d1;
  padding: 8px 18px;
  border-radius: 10px;
  cursor: pointer;
}

/* Small screens */
@media (max-width: 520px) {
  .cash-confirm { border-radius: 14px; padding: 0; }
  .cash-confirm .cf-body { padding: 14px; }
  .cash-confirm .cf-actions { padding: 12px; }
}
</style>
</head>
<body>

<?php if ($showLoginModal): ?>
<div class="modal" style="display:flex;align-items:center;justify-content:center;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;">
    <div class="modal-content2">
      <h2>Cashier Login</h2>
      <?php if ($loginError): ?>
        <div class="error"><?=htmlspecialchars($loginError)?></div>
      <?php endif; ?>
      <form method="post">
        <div class="input-group">
          <i class="fa fa-user"></i>
          <input type="text" name="username" placeholder="Username" required>
        </div>
        <div class="input-group">
          <i class="fa fa-lock"></i>
          <input type="password" name="password" placeholder="Passkey" required>
        </div>
        <button class="btn-login" type="submit" name="login_submit">Login</button>
      </form>
    </div>
</div>
<?php endif; ?>
</div>

<div class="header">
    <div class="header-left">
        <h1>It's A Thai Cashier POS</h1>
<!-- Notification Bell -->
<span class="notification-btn" style="position:relative;display:inline-block;cursor:pointer;">
  <i class="fa-solid fa-bell fa-2x"></i>
  <span id="notifBadge" style="position:absolute;top:-6px;right:-8px;background:#d72c0d;color:#fff;font-size:11px;padding:2px 6px;border-radius:10px;display:none;">0</span>
</span>

    </div>
    <div class="header-right">
        <span id="datetime"></span>
          <a href="logout.php" class="logout-btn">Logout</a>
    </div>
</div>

<div class="main-layout">
    <div class="menu-container" style="flex:1;">
       <!-- Replace your existing category-tabs code block with this: -->
<div class="category-tabs" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 18px;">
<?php
    // Desired category order
    $categoryOrder = [
        "Starters", "Ala Carte", "Salad",
        "Curry", "Fried Rice", "Noodles",
        "Grilled", "Soup", "Dessert",
        "Drinks", "Add-ons"
    ];
    // Only show categories that exist in menuByCategory
    foreach ($categoryOrder as $catName) {
        if (isset($menuByCategory[$catName])) {
            echo '<button class="category-btn" onclick="showCategory(\''.htmlspecialchars($catName).'\', this)">'.htmlspecialchars($catName).'</button>';
        } else {
            // Optionally show an empty slot if you want strict grid, or skip this line to condense
            // echo '<div style="visibility:hidden;"></div>';
        }
    }
?>
</div>
        <div id="menuContainer">
    <?php foreach($menuByCategory as $catName => $items): ?>
    <div class="category-items" id="cat-<?= htmlspecialchars($catName) ?>" style="display:none;">
        <h2><?= htmlspecialchars($catName) ?></h2>
        <div class="items-grid">
            <?php foreach($items as $item): ?>
              <?php
                $isFriedRiceOrNoodles = ($catName === "Fried Rice" || $catName === "Noodles");
                $soloDisabled = $isFriedRiceOrNoodles && (floatval($item['price_solo']) == 0);
                $sharingDisabled = $isFriedRiceOrNoodles && (floatval($item['price_sharing']) == 0);
                $mainDisabled = !$isFriedRiceOrNoodles && (floatval($item['price']) == 0);

                // FIX: consider any positive stock as available (previous code checked == 1)
                $available = ((int)($item['stock'] ?? 0) > 0);
                // class for out-of-stock appearance
                $outClass = ((int)($item['stock'] ?? 0) <= 0) ? 'out-of-stock' : '';
                // preserve existing price-based disabling logic
                $priceDisabledAttr = ($isFriedRiceOrNoodles ? ($soloDisabled && $sharingDisabled ? "disabled" : "") : ($mainDisabled ? "disabled" : ""));
              ?>
            <div class="menu-item <?= $outClass ?>">
                <?php if(!empty($item['image'])): ?>
                <img src="../uploads/<?= htmlspecialchars($item['image']) ?>" alt="<?= htmlspecialchars($item['name']) ?>">
                <?php endif; ?>
                <h3><?= htmlspecialchars($item['name']) ?></h3>
                <p>
                <?php if ($isFriedRiceOrNoodles): ?>
                    Solo: ₱<?= number_format($item['price_solo'] ?? 0,2) ?> <br>
                    Sharing: ₱<?= number_format($item['price_sharing'] ?? 0,2) ?>
                <?php else: ?>
                    ₱<?= number_format($item['price'] ?? 0,2) ?>
                <?php endif; ?>
                </p>
                <?php if(!empty($item['portion_size'])): ?>
                    <p><?= htmlspecialchars($item['portion_size']) ?></p>
                <?php endif; ?>

                <?php if($available): ?>
                <button
                    class="add-btn"
                    data-id="<?= $item['id'] ?>"
                    data-name="<?= htmlspecialchars($item['name']) ?>"
                    data-image="<?= htmlspecialchars($item['image']) ?>"
                    data-description="<?= htmlspecialchars($item['description']) ?>"
                    data-price="<?= $item['price'] ?? 0 ?>"
                    data-price-solo="<?= $item['price_solo'] ?? 0 ?>"
                    data-price-sharing="<?= $item['price_sharing'] ?? 0 ?>"
                    data-allergens="<?= htmlspecialchars($item['preferences'] ?? '') ?>"
                    data-notes="<?= htmlspecialchars($item['notes'] ?? '') ?>"
                    onclick="addToCart(
                        this.getAttribute('data-id'),
                        this.getAttribute('data-name'),
                        parseFloat(this.getAttribute('data-price')),
                        parseFloat(this.getAttribute('data-price-solo')),
                        parseFloat(this.getAttribute('data-price-sharing')),
                        this
                    )"
                    <?= ($priceDisabledAttr === "disabled" ? "disabled" : "") ?>
                >
                    Add
                </button>
                <?php else: ?>
                <p style="color:#d9534f; font-weight:bold;">Unavailable at the moment</p>
                <button disabled>Unavailable</button>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
</div>
    <div class="dashboard-grid">
        <div class="grid-item pending" id="incomingOrders">
            <h2>Incoming Orders</h2>
        </div>
        
        <div class="grid-item status" id="orderStatus">
            <h2>Order Status</h2>
        </div>
    </div>

    <div class="cart-container-fixed" id="cartContainer">
    <div class="cart-header">
        <h2><i class="fa-solid fa-cart-shopping"></i> Cart</h2>

        <label class="takeout-toggle">
            <input type="checkbox" id="takeOutCheckbox">
            <i class="fa-solid fa-bag-shopping"></i> Take-Out
        </label>
        <input type="hidden" id="editingOrderGroupId">
    </div>
        
        <div class="cart-info">
            <label>Table No:</label>
            <input type="number" id="tableNumber" placeholder="Enter table no." min="1" max="15" oninput="if(parseInt(this.value) > 15) this.value = 15;">
            <label>Customer Name:</label>
            <input type="text" id="customerName" placeholder="Enter customer name" maxlength="10" pattern="[a-zA-Z\s]+" oninput="this.value = this.value.replace(/[^a-zA-Z\s]/g, '')">

           
        <div id="cartItems"></div>
        <div id="cartTotal" style="margin-top:10px; font-weight:bold;">Total: ₱0.00</div>

        <div style="margin-top:10px; display:flex; gap:10px;">
   
    <button id="payBtn" onclick="openPayModal()" style="flex:1; padding:8px 12px; border:none; background:#2196f3; color:#fff; border-radius:5px; cursor:pointer;">
        Pay
    </button>
</div>
    </div>
</div>

<!-- Modern Item Modal -->
<div id="itemModal" style="display:none; position:fixed; left:0; top:0; right:0; bottom:0; background:rgba(0,0,0,0.4); z-index:9999; align-items:center; justify-content:center;">
  <div id="itemModalContent" class="modern-modal">
    <span class="modal-close" onclick="closeItemModal()">&times;</span>
    <div id="modalImage" class="modal-img"></div>
    <h2 id="modalName" class="modal-title"></h2>
    <div id="modalServingSizeRow" style="margin-bottom:10px;"></div>
    <div id="modalPrice" class="modal-price"></div>
    <div class="modal-qty-row">
      <button class="qty-btn" onclick="changeModalQty(-1)">&#8211;</button>
      <span id="modalQty" class="qty-value">1</span>
      <button class="qty-btn" onclick="changeModalQty(1)">+</button>
    </div>
    <p id="modalDescription" class="modal-desc"></p>

    <div id="spiceSection" class="modal-spice-row">
      <label for="modalSpiceSlider" class="spice-label">What level of spice do you want?</label>
      <input type="range" min="0" max="4" value="0" id="modalSpiceSlider" class="spice-slider" onchange="updateSpiceLabel()">
      <div class="spice-labels">
        <span>No Spice</span>
        <span>Light</span>
        <span>Moderate</span>
        <span>Spicy</span>
        <span>Extra</span>
      </div>
    </div>
    
    <div id="modalAllergens" style="margin:8px 0; text-align:left;">
      <label style="font-weight:bold; color:#005891; margin-right:5px;">Allergens:</label>
      <div id="allergenCheckboxes"></div>
    </div>

    <div id="modalNotes" style="margin:4px 0; text-align:left;">
      <label for="modalNotesInput">Note:</label>
      <textarea id="modalNotesInput" rows="2" placeholder="Enter any notes here..."></textarea>
    </div>

    <div id="modalTotal" class="modal-total"></div>
    <div class="modal-actions">
      <button class="add-cart-btn" onclick="confirmModalAdd()">🛒 Add to Cart</button>
      <button class="back-btn" onclick="closeItemModal()">Back</button>
    </div>
  </div>
</div>

<!-- Notif Modal -->
<div id="requestsModal" class="modal">
  <div class="modal-content" id="requestsModalContent">
    <span class="close-btn" onclick="closeModal()">&times;</span>
    <h2 style="display:flex;align-items:center;gap:8px;">
      <i class="fa-solid fa-bullhorn" style="color:#ff9800;"></i>
      Customer Requests
    </h2>
    <div class="requests-table-wrapper">
      <table id="requestsTable">
        <thead>
          <tr>
            <th>Order #</th>
            <th>Table</th>
            <th>Customer</th>
            <th>Request Type</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
</div>
<div id="payModal" style="display:none; position:fixed; left:0; top:0; right:0; bottom:0; background:rgba(0,0,0,0.4); z-index:99999; align-items:center; justify-content:center;">
  <div style="background:#fff; border-radius:16px; max-width:380px; margin:60px auto; padding:20px 18px; box-shadow:0 2px 24px #0002; text-align:center;">
    <h2>Payment</h2>

    <div style="margin:8px 0;"><strong>Total: <span id="payModalTotal">₱0.00</span></strong></div>

    <!-- Payment method selection -->
    <div class="payment-methods" id="paymentMethods" style="margin-bottom:12px;">
        <div class="payment-method active" data-method="cash" onclick="selectPaymentMethod('cash', this)">
            <i class="fa-solid fa-money-bill-wave"></i> Cash
        </div>
       <div class="payment-method" data-method="gcash" onclick="selectPaymentMethod('gcash', this)">
            <i class="fa-brands fa-g"></i> GCash QR
        </div>
        <div class="payment-method" data-method="bpi" onclick="selectPaymentMethod('bpi', this)">
            <i class="fa-solid fa-qrcode"></i> BPI QR
        </div>
    </div>

    <!-- QR box for QR payments -->
    <div class="qr-box" id="qrBox" style="display:none; margin-bottom:10px;">
        <img id="qrImage" src="" alt="QR Code" style="width:200px;height:200px;object-fit:contain;border-radius:8px;border:1px solid #eee;background:#fff;">
        <div style="margin-top:8px; font-size:0.95rem; color:#555;" id="qrCaption">Scan to pay</div>
    </div>

    <!-- Cash UI -->
    <div id="cashSection" style="display:block; margin-bottom:8px;">
        <div style="margin-bottom:8px;">
            <label>Amount Given: </label>
            <input type="number" id="payModalGiven" min="0" step="0.01" style="width:120px;" oninput="updateChange()">
        </div>
        <div><strong>Change: <span id="payModalChange">₱0.00</span></strong></div>
    </div>

    <div style="margin-top:12px; display:flex; gap:8px; justify-content:center;">
      <button id="discountBtn" onclick="toggleDiscount()" style="background:#ff9800; color:#fff; border:none; border-radius:6px; padding:8px 12px; font-size:0.95rem; font-weight:600;">Apply 20% Discount</button>
      <button id="confirmPayBtn" onclick="confirmPayment()" style="background:#28a745; color:#fff; border:none; border-radius:6px; padding:8px 22px; font-size:1rem; font-weight:500;">Confirm</button>
      <button onclick="closePayModal()" style="background:#eee; color:#333; border:none; border-radius:6px; padding:8px 22px; font-size:1rem; font-weight:500; margin-left:8px;">Cancel</button>
    </div>
  </div>
</div>
<!-- Confirm Modal (paste into index.php, with other modals, before </body>) -->
<div id="cashConfirmOverlay" class="cash-confirm-overlay" aria-hidden="true" role="dialog" aria-modal="true">
  <div class="cash-confirm" role="document" aria-labelledby="cfTitle" aria-describedby="cfMessage">
    <div class="cf-header">
      <div id="cfTitle" class="cf-title">Confirm</div>
    </div>

    <div id="cfMessage" class="cf-body">
      Are you sure?
    </div>

    <div class="cf-actions">
      <!-- Use the same button classes used elsewhere so UI is consistent -->
      <button id="cfCancelBtn" class="back-btn" type="button">Keep</button>
      <button id="cfOkBtn" class="add-cart-btn" type="button">Send</button>
    </div>
  </div>
</div>
<script>
    let isPaid = false; // Track if current order is paid

// Discount configuration
let discountApplied = false;
const DISCOUNT_PERCENT = 20;

function toggleDiscount() {
    discountApplied = !discountApplied;
    const btn = document.getElementById('discountBtn');
    if (discountApplied) {
        btn.textContent = `20% Discount ✓`;
        btn.style.background = '#c66';
    } else {
        btn.textContent = `Apply 20% Discount`;
        btn.style.background = '#ff9800';
    }
    // refresh displayed totals in modal
    updatePayModalTotals();
}

function updatePayModalTotals() {
    let total = 0;
    if (window.currentPayOrderGroupId) {
        total = window.currentPayOrderTotal || 0;
    } else {
        cart.forEach(item => total += item.price * item.qty);
    }
    let displayedTotal = total;
    if (discountApplied) {
        displayedTotal = +(displayedTotal * (1 - DISCOUNT_PERCENT/100)).toFixed(2);
    }
    document.getElementById('payModalTotal').textContent = '₱' + displayedTotal.toFixed(2);
    updateChange();
}

 function confirmPayment() {
    let total;
    let orderGroupId = window.currentPayOrderGroupId || null;
    let given = parseFloat(document.getElementById('payModalGiven').value) || 0;

    if (orderGroupId) {
        // Order status (existing order)
        total = window.currentPayOrderTotal || 0;
        if (discountApplied) {
            total = +(total * (1 - DISCOUNT_PERCENT/100)).toFixed(2);
        }

        // === Prevent underpayment ===
        if (given < total) {
             showToast("Amount given is insufficient!");
            return;
        }

        fetch('pay_order.php', {
            method: 'POST',
            headers: {"Content-Type": "application/json"},
            body: JSON.stringify({ order_group_id: orderGroupId, discount_percent: discountApplied ? DISCOUNT_PERCENT : 0 })
        })
        .then(res => res.json())
        .then(data => {
            let finalTotal = total;
            // backend may return discounted_total; prefer it if provided
            if (data && data.discounted_total !== undefined) {
                finalTotal = parseFloat(data.discounted_total) || finalTotal;
            }
            let change = given - finalTotal;
            if (isNaN(change) || change < 0) change = 0;
            showToast("Payment successful. Change: ₱" + change.toFixed(2));
            loadOrders();
        })
        .catch(err => {
            alert("Error during payment: " + err);
        });
        window.currentPayOrderGroupId = null;
        window.currentPayOrderTotal = null;
        discountApplied = false;
        closePayModal();
        return;
    } else {
        // Cart payment flow (new order or resumed order from cart)
        total = 0;
        cart.forEach(item => total += item.price * item.qty);

        // apply discount if requested
        let discountedTotal = total;
        if (discountApplied) discountedTotal = +(discountedTotal * (1 - DISCOUNT_PERCENT/100)).toFixed(2);

        // === Prevent underpayment ===
        if (given < discountedTotal) {
            showToast("Amount given is insufficient!");
            return;
        }

        const tableNumber = document.getElementById("tableNumber").value.trim();
        const customerName = document.getElementById("customerName").value.trim();
        const takeOut = document.getElementById("takeOutCheckbox").checked ? 1 : 0;
        const editingOrderGroupId = document.getElementById("editingOrderGroupId").value;

        if (!takeOut && (!tableNumber || !customerName)) {
    showToast("Please enter table number and customer name.");
    enableAddButtons();
    return;
}
        if (cart.length === 0) {
             showToast("Cart is empty.");
            enableAddButtons();
            return;
        }
        setDefaultSpiceLevels();

        // If resuming an order, mark it as paid and status as 'Paid' (backend will set 'Preparing')
        let paymentStatus = "Paid";
        let paidFlag = 1;

        let payload = {
            tableNumber,
            customerName,
            cart,
            takeOut,
            status: paymentStatus, // status = 'Paid'
            paid: paidFlag,        // set paid=1
            editingOrderGroupId,
            discountPercent: discountApplied ? DISCOUNT_PERCENT : 0
        };

        fetch("place_order.php", {
            method: "POST",
            headers: {"Content-Type": "application/json"},
            body: JSON.stringify(payload)
        })
        .then(res => res.json().catch(() => null))
        .then(data => {
            closePayModal();
            let change = given - discountedTotal;
            if (isNaN(change) || change < 0) change = 0;
            if (!data) {
                 showToast("Server error or invalid JSON from place_order.php!");
                enableAddButtons();
                return;
            }
            if (data.success) {
                showToast("Payment successful. Change: ₱" + change.toFixed(2));
                loadOrders();
                resetCart();
            } else {
                 showToast("Failed to place order: " + data.message);
                enableAddButtons();
            }
        })
        .catch(err => {
             showToast("Error during fetch: " + err);
            enableAddButtons();
        });
    }
}

function disableAddButtons() {
    document.querySelectorAll('.add-btn').forEach(btn => {
        btn.disabled = true;
    });
}
function enableAddButtons() {
    document.querySelectorAll('.add-btn').forEach(btn => {
        btn.disabled = false;
    });
}
const bell = document.querySelector('.notification-btn');
// state
let lastPendingCount = 0;   // last known pending count from server
let badgeCleared = false;   // true once cashier opened the modal (badge cleared)
let modalIsOpen = false;
let modalAutoRefreshInterval = null;

// safe guard: make sure bell exists
if (bell) {
    bell.addEventListener('click', () => {
        openModal();
    });
} else {
    console.warn('Notification bell element not found (.notification-btn).');
}

// Fetch pending + acknowledged requests (server returns requests + pendingCount)
function fetchRequestsData() {
    return fetch('get_requests.php', { cache: 'no-store' })
        .then(res => res.json())
        .catch(err => {
            console.error('get_requests fetch error:', err);
            return { status: 'error', requests: [], pendingCount: 0 };
        });
}



// 1. Load lastSeenIds from localStorage (persisted across reloads)
let lastSeenIds = new Set(JSON.parse(localStorage.getItem('lastSeenNotifIds') || '[]'));
function mapRequestTypeLabel(rt) {
    if (!rt) return rt;
    // convert the internal request type to the label shown to cashier
    if (rt === 'Printed Receipt') return 'Ready to Pay';
    return rt;
}
function updateNotifBadge() {
    fetch('get_requests.php')
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                const requests = data.requests || [];
                const count = requests.length;

                // --- Badge update ---
                if (count > 0) {
                    notifBadge.style.display = 'inline-block';
                    notifBadge.textContent = count;
                } else {
                    notifBadge.style.display = 'none';
                }

                // --- Toast only once per request until done ---
                let updatedSeenIds = new Set(lastSeenIds);
                requests.forEach(r => {
                    if (!lastSeenIds.has(r.id)) {
                        showToast(`Table ${r.table_number} - ${mapRequestTypeLabel(r.request_type)}`);
                        updatedSeenIds.add(r.id);
                    }
                });
                // Remove IDs of requests that are no longer in the list (i.e. Completed)
                const currentIds = requests.map(r => r.id);
                for (let id of updatedSeenIds) {
                    if (!currentIds.includes(id)) {
                        updatedSeenIds.delete(id);
                    }
                }
                lastSeenIds = updatedSeenIds;
                localStorage.setItem('lastSeenNotifIds', JSON.stringify(Array.from(lastSeenIds)));
            }
        })
        .catch(err => console.error('Error fetching notifications:', err));
}

function showToast(msg){
    let container = document.getElementById('toast-stack-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-stack-container';
        Object.assign(container.style, {
            position: "fixed",
            left: "50%",
            top: "10px",
            transform: "translateX(-50%)",
            display: "flex",
            flexDirection: "column",
            alignItems: "center",
            zIndex: 99999,
            pointerEvents: "none"
        });
        document.body.appendChild(container);
    }
    const toast = document.createElement('div');
    toast.textContent = msg;
    Object.assign(toast.style, {
        marginTop: "5px",
        background: "linear-gradient(135deg, #ff5722, #ff9800)",
        color: "#fff",
        padding: "12px 22px",
        borderRadius: "10px",
        fontSize: "16px",
        fontWeight: "bold",
        letterSpacing: "0.5px",
        boxShadow: "0 6px 12px rgba(0,0,0,0.19)",
        opacity: 0,
        transition: "all 0.4s cubic-bezier(.77,0,.175,1)",
        whiteSpace: "nowrap",
        pointerEvents: "none"
    });
    container.appendChild(toast);
    setTimeout(()=> {
        toast.style.opacity = 1;
        toast.style.transform = "translateY(0)";
    }, 50);
    setTimeout(()=> {
        toast.style.opacity = 0;
        toast.style.transform = "translateY(-20px)";
        setTimeout(()=> toast.remove(), 400);
    }, 8000);
}

// --- Initial and periodic call ---
updateNotifBadge();
setInterval(updateNotifBadge, 10000);

// Populate modal table and also update badge state (hide when opened)
function loadRequests() {
    fetchRequestsData().then(data => {
        if (data.status !== 'success') return;

        const requests = data.requests || [];
        const tbody = document.querySelector("#requestsTable tbody");
        if (!tbody) return;

        tbody.innerHTML = "";
        requests.forEach(r => {
            const statusClass = (r.status === 'Pending') ? 'status-pending' :
                                (r.status === 'Acknowledged') ? 'status-ack' : 'status-done';

            const ackButton = (r.status === 'Pending') ? `<button class="btn-ack" onclick="updateRequest(${r.id}, 'Acknowledged')">Acknowledge</button>` : '';
            const doneButton = (r.status !== 'Completed') ? `<button class="btn-done" onclick="updateRequest(${r.id}, 'Completed')">Mark Done</button>` : '';

            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${escapeHtml(r.order_group_id || '-') }</td>
                <td>${escapeHtml(r.table_number || '-') }</td>
                <td>${escapeHtml(r.customer_name || '-') }</td>
                <td>${escapeHtml(mapRequestTypeLabel(r.request_type || '-') ) }</td>
                <td class="${statusClass}">${escapeHtml(r.status)}</td>
                <td>${ackButton} ${doneButton}</td>
            `;
            tbody.appendChild(row);
        });

        // When modal is opened, hide the badge and mark it cleared
        // so it only reappears if new pending items arrive.
        notifBadge.style.display = 'none';
        badgeCleared = true;
        lastPendingCount = Number(data.pendingCount || 0);
    });
}

// call update endpoint to change status
function updateRequest(id, newStatus) {
    // disable buttons quickly to prevent double-click spam
    // (we can't easily target the specific button here, but server-side handles idempotency)
    fetch("update_request.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: "id=" + encodeURIComponent(id) + "&status=" + encodeURIComponent(newStatus)
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            // refresh modal contents and badge
            if (modalIsOpen) loadRequests();
            updateNotifBadge();
        } else {
             showToast("Failed to update request: " + (data.message || 'Unknown error'));
            console.error('update_request error:', data);
        }
    })
    .catch(err => {
        console.error('update_request fetch error:', err);
    });
}

// modal controls
function openModal() {
    const m = document.getElementById("requestsModal");
    if (!m) { console.warn('Modal element #requestsModal not found'); return; }
    m.style.display = "block";
    modalIsOpen = true;
    loadRequests();

    // start auto-refresh for modal while open (every 8s)
    if (modalAutoRefreshInterval) clearInterval(modalAutoRefreshInterval);
    modalAutoRefreshInterval = setInterval(() => {
        if (modalIsOpen) loadRequests();
    }, 8000);
}

function closeModal() {
    const m = document.getElementById("requestsModal");
    if (!m) return;
    m.style.display = "none";
    modalIsOpen = false;

    if (modalAutoRefreshInterval) {
        clearInterval(modalAutoRefreshInterval);
        modalAutoRefreshInterval = null;
    }

    // keep badge hidden until a new pending count > lastPendingCount appears
    // (badgeCleared already true)
}

// escape helper to avoid injecting HTML
function escapeHtml(s) {
    return String(s || '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');
}

// initial run + periodic badge refresh
updateNotifBadge();
setInterval(updateNotifBadge, 20000);
/* Payment helpers: selected method, QR placeholders, method selector, totals, modal open/close, change calculation, confirmPayment */
let selectedPaymentMethod = 'cash'; // 'cash' | 'gcash' | 'bpi'

/* Optional embedded placeholder QR images (data URIs). Replace with real image URLs if available. */
const QR_PLACEHOLDERS = {
  gcash: 'data:image/svg+xml;utf8,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="280" height="280"><rect fill="#fff" width="100%" height="100%"/><g fill="#111"><rect x="20" y="20" width="60" height="60"/><rect x="100" y="20" width="40" height="40"/><rect x="160" y="20" width="80" height="10"/><text x="50%" y="90%" font-size="18" text-anchor="middle" fill="#111" font-family="Arial">GCash QR - Scan to Pay</text></g></svg>'),
  bpi:   'data:image/svg+xml;utf8,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="280" height="280"><rect fill="#fff" width="100%" height="100%"/><g fill="#111"><rect x="20" y="20" width="40" height="40"/><rect x="80" y="20" width="80" height="80"/><rect x="180" y="20" width="40" height="40"/><text x="50%" y="90%" font-size="18" text-anchor="middle" fill="#111" font-family="Arial">BPI QR - Scan to Pay</text></g></svg>')
};

/* Called when user selects payment method tabs */
function selectPaymentMethod(method, el) {
    selectedPaymentMethod = method;
    document.querySelectorAll('.payment-method').forEach(d=>d.classList.remove('active'));
    if (el) el.classList.add('active');

    const qrBox = document.getElementById('qrBox');
    const cashSection = document.getElementById('cashSection');
    const qrImage = document.getElementById('qrImage');
    const qrCaption = document.getElementById('qrCaption');

    if (method === 'cash') {
        qrBox.style.display = 'none';
        cashSection.style.display = 'block';
    } else {
        qrBox.style.display = 'block';
        cashSection.style.display = 'none';
        if (method === 'gcash') {
            qrImage.src = QR_PLACEHOLDERS.gcash;
            qrCaption.textContent = 'Scan GCash QR to pay';
        } else if (method === 'bpi') {
            qrImage.src = QR_PLACEHOLDERS.bpi;
            qrCaption.textContent = 'Scan BPI QR to pay';
        }
    }

    // Recompute totals / change shown
    updatePayModalTotals();
}

/* Update displayed total (called whenever discount changes or modal opens) */
function updatePayModalTotals() {
    let total = 0;
    if (window.currentPayOrderGroupId) {
        total = window.currentPayOrderTotal || 0;
    } else {
        cart.forEach(item => total += item.price * item.qty);
    }
    let displayedTotal = total;
    if (discountApplied) displayedTotal = +(displayedTotal * (1 - DISCOUNT_PERCENT/100)).toFixed(2);
    document.getElementById('payModalTotal').textContent = '₱' + displayedTotal.toFixed(2);
    updateChange();
}

/* Open modal (default to Cash) */
function openPayModal() {
    updatePayModalTotals();
    document.getElementById('payModalGiven').value = '';
    document.getElementById('payModalChange').textContent = '₱0.00';
    document.getElementById('payModal').style.display = 'flex';
    document.getElementById('payBtn').disabled = true;

    // default to cash selection
    const cashTab = document.querySelector('.payment-method[data-method="cash"]');
    selectPaymentMethod('cash', cashTab);
}

/* Close modal and reset states */
function closePayModal() {
    document.getElementById('payModal').style.display = 'none';
    document.getElementById('payBtn').disabled = false;
    window.currentPayOrderGroupId = null;
    window.currentPayOrderTotal = null;
    discountApplied = false;
    // Reset discount button text/style if you have it
    const btn = document.getElementById('discountBtn');
    if (btn) { btn.textContent = 'Apply 20% Discount'; btn.style.background = '#ff9800'; }
    // reset payment method to cash
    const cashTab = document.querySelector('.payment-method[data-method="cash"]');
    selectPaymentMethod('cash', cashTab);
}

/* Update computed change (for cash) — for QR methods change shown as 0. */
function updateChange() {
        let total;
    if (window.currentPayOrderGroupId) {
        total = window.currentPayOrderTotal || 0;
    } else {
        total = 0;
        cart.forEach(item => total += item.price * item.qty);
    }
    if (discountApplied) total = +(total * (1 - DISCOUNT_PERCENT/100)).toFixed(2);

    // For QR, we don't compute change (payment is handled off-app), set to 0
    if (selectedPaymentMethod === 'gcash' || selectedPaymentMethod === 'bpi') {
        document.getElementById('payModalChange').textContent = '₱0.00';
        return;
    }

    let given = parseFloat(document.getElementById('payModalGiven').value) || 0;
    let change = given - total;
    document.getElementById('payModalChange').textContent = '₱' + (change >= 0 ? change.toFixed(2) : '0.00');
}

    let cart = [];
let currentModalItem = null;
let currentModalItemId = null; // New variable to store the item ID
let currentServingSize = ''; // 'Solo' or 'Sharing' for Fried Rice/Noodles

// Example global lists (make sure these are defined in your real code)
 const ALLERGEN_LIST = ["Egg", "Soy", "Dairy", "Nuts", "Corn", "Seafood", "Sugar"];
const SPICE_LEVEL_WORDS = ["No Spice", "Light", "Moderate", "Spicy", "Extra"];
const SERVING_SIZE_CATEGORIES = ["Fried Rice", "Noodles"];
const NO_SPICE_CATEGORIES = ["Dessert","Drinks","Salad","Add-ons"];
const itemCategoryMap = <?php
   $map = [];
   foreach($menuByCategory as $catName => $items) {
       foreach ($items as $item) {
           $map[$item['id']] = $catName;
       }
   }
   echo json_encode($map);
?>;
const menuByCategory = <?php echo json_encode($menuByCategory); ?>;

// Build itemNameToCategory for Dessert/Drinks detection
const itemNameToCategory = {};
for (const cat in menuByCategory) {
    (menuByCategory[cat] || []).forEach(mi => {
        itemNameToCategory[mi.name] = cat;
    });
}
// ====== MODAL ======
 function showItemModal(btn) {
  const id = btn.getAttribute('data-id');
  const name = btn.getAttribute('data-name');
  const img = btn.getAttribute('data-image');
  const desc = btn.getAttribute('data-description');
  const price = parseFloat(btn.getAttribute('data-price')) || 0;
  const priceSolo = parseFloat(btn.getAttribute('data-price-solo')) || 0;
  const priceSharing = parseFloat(btn.getAttribute('data-price-sharing')) || 0;
  const allergens = (btn.getAttribute('data-allergens') || '').split(',');
  const category = itemCategoryMap[id];

  currentModalItem = { id, name, img, desc, price, priceSolo, priceSharing, category };
  currentModalItemId = id;
  currentServingSize = '';

  document.getElementById('modalImage').innerHTML = img ? `<img src="../uploads/${img}" style="width:130px;height:130px;border-radius:10px;">` : '';
  document.getElementById('modalName').textContent = name;
  document.getElementById('modalDescription').textContent = desc || '';
  document.getElementById('modalQty').textContent = 1;
  document.getElementById('modalSpiceSlider').value = 0;
  document.getElementById('modalNotesInput').value = '';

  // Serving size selector for Fried Rice / Noodles
  if (SERVING_SIZE_CATEGORIES.includes(category)) {
    let servingRow = '';
    servingRow += `<button type="button" id="modalSoloBtn" class="add-cart-btn" style="margin-right:8px;padding:6px 18px;${priceSolo ? '' : 'background:#eee; color:#888; cursor:not-allowed;'}" ${priceSolo ? '' : 'disabled'} onclick="selectServingSize('Solo')">Solo<br>₱${priceSolo.toFixed(2)}</button>`;
    servingRow += `<button type="button" id="modalSharingBtn" class="add-cart-btn" style="padding:6px 18px;${priceSharing ? '' : 'background:#eee; color:#888; cursor:not-allowed;'}" ${priceSharing ? '' : 'disabled'} onclick="selectServingSize('Sharing')">Sharing<br>₱${priceSharing.toFixed(2)}</button>`;
    document.getElementById('modalServingSizeRow').innerHTML = servingRow;
    // Set default selection
    if (priceSolo) {
      selectServingSize('Solo');
    } else if (priceSharing) {
      selectServingSize('Sharing');
    } else {
      currentServingSize = '';
    }
    // Set initial quantity and total
    document.getElementById('modalQty').textContent = 1;
    let price = currentServingSize === 'Solo'
        ? (parseFloat(currentModalItem.priceSolo) || 0)
        : (parseFloat(currentModalItem.priceSharing) || 0);
    document.getElementById('modalPrice').textContent = `₱${price.toFixed(2)}`;
    document.getElementById('modalTotal').textContent = `Total: ₱${price.toFixed(2)}`;
  } else {
    document.getElementById('modalServingSizeRow').innerHTML = '';
    document.getElementById('modalPrice').textContent = `₱${price.toFixed(2)}`;
    document.getElementById('modalTotal').textContent = `Total: ₱${price.toFixed(2)}`;
  }

    // Hide allergens and notes for Drinks and Dessert
    if (category === 'Drinks' || category === 'Dessert') {
        document.getElementById('modalAllergens').style.display = 'none';
        document.getElementById('modalNotes').style.display = 'none';
    } else {
        document.getElementById('modalAllergens').style.display = 'block';
        document.getElementById('modalNotes').style.display = 'block';
        const checkWrap = document.getElementById('allergenCheckboxes');
        checkWrap.innerHTML = '';
        ALLERGEN_LIST.forEach(a => {
            const checked = allergens.includes(a) ? 'checked' : '';
            checkWrap.innerHTML += `
                <label class="allergen-tag">
                    <input type="checkbox" value="${a}" ${checked}> ${a}
                </label>`;
        });
    }

  // Show/hide spice slider
  if (NO_SPICE_CATEGORIES.includes(category)) {
    document.getElementById('spiceSection').style.display = 'none';
  } else {
    document.getElementById('spiceSection').style.display = 'block';
  }

  document.getElementById('itemModal').style.display = 'flex';
}
// Called when Solo/Sharing is selected
function selectServingSize(size) {
    currentServingSize = size;
    // Highlight selected button
    if (size === 'Solo') {
        document.getElementById('modalSoloBtn').style.background = '#ffc107';
        document.getElementById('modalSoloBtn').style.color = '#222';
        document.getElementById('modalSharingBtn').style.background = '#fff';
        document.getElementById('modalSharingBtn').style.color = '#222';
    } else {
        document.getElementById('modalSharingBtn').style.background = '#ffc107';
        document.getElementById('modalSharingBtn').style.color = '#222';
        document.getElementById('modalSoloBtn').style.background = '#fff';
        document.getElementById('modalSoloBtn').style.color = '#222';
    }
    // Update price and total
    let price = size === 'Solo'
        ? (parseFloat(currentModalItem.priceSolo) || 0)
        : (parseFloat(currentModalItem.priceSharing) || 0);
    document.getElementById('modalPrice').textContent = `₱${price.toFixed(2)}`;
    let qty = parseInt(document.getElementById('modalQty').textContent);
    document.getElementById('modalTotal').textContent = `Total: ₱${(price * qty).toFixed(2)}`;
}
// Quantity change
function changeModalQty(amount) {
    const qtySpan = document.getElementById('modalQty');
    let qty = parseInt(qtySpan.textContent);
    qty = Math.max(1, qty + amount); // Never go below 1
    qtySpan.textContent = qty;

    // Determine price based on serving size for Fried Rice/Noodles
    let price = currentModalItem.price;
    if (SERVING_SIZE_CATEGORIES.includes(currentModalItem.category)) {
        price = currentServingSize === 'Solo'
            ? (parseFloat(currentModalItem.priceSolo) || 0)
            : (parseFloat(currentModalItem.priceSharing) || 0);
        document.getElementById('modalPrice').textContent = `₱${price.toFixed(2)}`;
    }
    document.getElementById('modalTotal').textContent = `Total: ₱${(price * qty).toFixed(2)}`;
}

   function closeItemModal() {
       document.getElementById('itemModal').style.display = 'none';
       currentModalItem = null;
   }

// ====== ADD TO CART ======
function confirmModalAdd() {
  if (!currentModalItem) return;

  let price = currentModalItem.price;
  let servingSize = undefined;
  // For Fried Rice/Noodles, require serving size and use its price
  if (SERVING_SIZE_CATEGORIES.includes(currentModalItem.category)) {
    if (!currentServingSize) {
       showToast("Please select Solo or Sharing.");
      return;
    }
    servingSize = currentServingSize;
    price = currentServingSize === 'Solo'
      ? (parseFloat(currentModalItem.priceSolo) || 0)
      : (parseFloat(currentModalItem.priceSharing) || 0);
  }

  const qty = parseInt(document.getElementById('modalQty').textContent);
  const notes = document.getElementById('modalNotesInput').value.trim();
    // Get the actual selected spice level from the slider
    const spiceValue = parseInt(document.getElementById('modalSpiceSlider').value);
    const spiceLabel = SPICE_LEVEL_WORDS[spiceValue] || "No Spice";
  const allergenEls = document.querySelectorAll('#allergenCheckboxes input:checked');
  const allergens = Array.from(allergenEls).map(el => el.value);

    // Deduplicate by id, servingSize, spiceValue, allergens, notes
    const existing = cart.find(item =>
        item.id === currentModalItem.id &&
        item.servingSize === servingSize &&
        (item.spiceValue || 0) === (spiceValue || 0) &&
        JSON.stringify(item.allergens || []) === JSON.stringify(allergens || []) &&
        (item.notes || '') === (notes || '')
    );
    if (existing) {
        existing.qty += qty;
    } else {
        cart.push({
            id: currentModalItem.id,
            name: currentModalItem.name,
            price: price,
            qty: qty,
            spiceLevel: spiceLabel,
            spiceValue: spiceValue,
            notes: notes,
            allergens: allergens,
            category: currentModalItem.category,
            servingSize: servingSize
        });
    }

  renderCart();
  closeItemModal();
}

// Update addToCart to always open item modal
function addToCart(id, name, price, priceSolo, priceSharing, btnRef) {
     if (isPaid) {
         showToast("This order is already paid. You cannot add more items.");
        return;
    }
  showItemModal(btnRef);
}

// ====== CART UI ======
function renderCart() {
       const cartDiv = document.getElementById('cartItems');
       cartDiv.innerHTML = '';
       let total = 0;

       cart.forEach((item) => {
           const lineTotal = item.price * item.qty;
           total += lineTotal;

           cartDiv.innerHTML += `
               <div class="cart-line">
                   <strong>${item.name}</strong> x${item.qty} - ₱${lineTotal.toFixed(2)}<br>
                   ${item.spiceLevel ? `<span class="cart-spice">🌶 ${item.spiceLevel}</span>` : ''}
                   ${item.allergens?.length ? `<div>${item.allergens.map(a=>`<span class="cart-allergen-tag">${a}</span>`).join(' ')}</div>` : ''}
                   ${item.notes ? `<div class="cart-notes">${item.notes}</div>` : ''}
               </div>
           `;
       });

       document.getElementById('cartTotal').textContent = `Total: ₱${total.toFixed(2)}`;
   }



function showCategory(catName, btn) {
    document.querySelectorAll(".category-items").forEach(div => {
        div.style.display = "none";
    });
    document.querySelectorAll(".category-btn").forEach(b => b.classList.remove("active"));
    const target = document.getElementById("cat-" + catName);
    if (target) {
        target.style.display = "block";
    }
    btn.classList.add("active");
}


function addToCart(id, name, price, priceSolo, priceSharing, btnRef) {
    const cat = itemCategoryMap[id];
   
    showItemModal(btnRef);
}


// Core cart add (used by both direct and modal)
function addCartItem(id, name, price, category, servingSize) {
    if (parseFloat(price) === 0) {
         showToast("This serving size is not available for order.");
        return;
    }
    
    const existing = cart.find(item => item.id === id && item.servingSize === servingSize);
    if (existing) {
        existing.qty++;
    } else {
        let spiceLevel = "";
        if (NO_SPICE_CATEGORIES.indexOf(category) === -1) {
            spiceLevel = "";
        }
        cart.push({id, name, price, qty: 1, spiceLevel, category, servingSize});
    }
    renderCart();
}

function setDefaultSpiceLevels() {
    cart.forEach(item => {
        if (NO_SPICE_CATEGORIES.indexOf(item.category) === -1) {
            if (!item.spiceLevel || item.spiceLevel === "") {
                item.spiceLevel = "0 - No Spice";
            }
        }
    });
}
function renderCart() {
    const cartItemsDiv = document.getElementById("cartItems");
    cartItemsDiv.innerHTML = "";
    let total = 0;

    cart.forEach((item, index) => {
        total += item.price * item.qty;

     

        // Show serving size for Fried Rice & Noodles
         let sizeInfo = "";
        if (SERVING_SIZE_CATEGORIES.includes(item.category)) {
            sizeInfo = `<span style="color:#298;">[${item.servingSize}]</span>`;
        }

        cartItemsDiv.innerHTML += `
    <div class="cart-item">
        <span class="cart-item-name">${item.name} - ₱${item.price.toFixed(2)}</span>
        ${sizeInfo}
        
    </div>
`;
    });

      document.getElementById("cartTotal").textContent = "Total: ₱" + total.toFixed(2);
}
function updateSpiceLevel(index, value) {
    cart[index].spiceLevel = value;
}

function changeQty(index, delta) {
    cart[index].qty += delta;
    if (cart[index].qty <= 0) cart.splice(index, 1);
    renderCart();
}

function removeItem(index) {
    cart.splice(index, 1);
    renderCart();
}

function createOrderHtml(order) {
    let status = (order.status || "").trim();
    let statusClass = status.toLowerCase().replace(/\s+/g, "");
    let buttonsHtml = '';
    let dineType = (order.takeout == 1 || order.take_out == 1) ? "Take-Out" : "Dine-in";

    // Title line: do NOT change the main order title based on order.has_additional.
    // The "Additional Order" label must only come from the separate synthetic addition cards.
    let titleLine = `Table ${order.table_number} - ${order.customer_name} (${dineType})`;

    // Payment status display
    let paymentStatusHtml = '';
    if ('payment_status' in order) {
        if (order.payment_status === 'Paid' || order.paid == 1) {
            paymentStatusHtml = `<span style="color:green;font-weight:bold;">Paid</span>`;
        } else {
            paymentStatusHtml = `<span style="color:red;font-weight:bold;">Unpaid</span>`;
        }
    }

    // Pay button (only if unpaid and not Pending/On Hold)
    let payButtonHtml = '';
    if ((order.payment_status !== 'Paid' && order.paid != 1) && status !== "Pending" && status !== "On Hold" &&
        status !== "Canceled" ) {
        payButtonHtml = `<button class="btn-pay" onclick="openOrderPayModal('${order.order_group_id}')">Pay</button>`;
    }

    // === INCOMING ORDERS (Send to Kitchen & Cancel) ===
    // Show Send/Cancel only when the main order itself is Pending (or On Hold if you want),
    // NOT just because it has additions. Additional items are shown as separate "Additional" cards
    // in the Incoming column and those cards already have their own Send/Cancel buttons.
    const isPaid = (order.payment_status === 'Paid' || order.paid == 1);
    if (!isPaid && statusClass === 'pending') {
        buttonsHtml = `
            <div class="status-btns">
                <button class="btn-send" onclick="updateStatus('${order.order_group_id}', 'Preparing')">Send to Kitchen</button>
                <button class="btn-cancel" onclick="updateStatus('${order.order_group_id}', 'Canceled')">Cancel</button>
            </div>
        `;
    }

    // === ORDER STATUS area: keep existing View / Pay controls for non-Pending/On Hold ===
    if (status !== "Pending" && status !== "On Hold") {
        buttonsHtml += `
            <div class="status-btns">
                <button class="btn-view" onclick="viewOrder('${order.order_group_id}')">
                    <i class="fa fa-eye"></i> View Details
                </button>
                ${payButtonHtml}
                <div style="margin-top:6px;">Payment: ${paymentStatusHtml}</div>
            </div>
        `;
    } else {
        // if pending/on-hold but already paid, still show view & payment state
        if (isPaid) {
            buttonsHtml += `
                <div class="status-btns">
                    <button class="btn-view" onclick="viewOrder('${order.order_group_id}')">
                        <i class="fa fa-eye"></i> View Details
                    </button>
                    <div style="margin-top:6px;">Payment: ${paymentStatusHtml}</div>
                </div>
            `;
        }
    }

    return `
        <div class="order" id="order-${order.order_group_id}">
            <strong>${titleLine}</strong><br>
            <span class="status-text status-${statusClass}">Status: ${status}</span><br>
            <small>Placed: ${order.created_at}</small>
            ${buttonsHtml}
        </div>
    `;
}
function openOrderPayModal(orderGroupId) {
    const order = allOrders.find(o => o.order_group_id === orderGroupId);
    if (!order) {
         showToast("Order not found!");
        return;
    }
    document.getElementById('payModalTotal').textContent = '₱' + (order.total_price ? order.total_price.toFixed(2) : "0.00");
    document.getElementById('payModalGiven').value = '';
    document.getElementById('payModalChange').textContent = '₱0.00';
    document.getElementById('payModal').style.display = 'flex';
    window.currentPayOrderGroupId = orderGroupId;
    window.currentPayOrderTotal = order.total_price || 0;
}
function payOrder(orderGroupId) {
    // Optionally show a payment modal here and collect payment, etc.
    // For simplicity, just mark as paid:
    fetch('pay_order.php', {
        method: 'POST',
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ order_group_id: orderGroupId })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showToast("Order marked as paid!");
            loadOrders();
        } else {
             showToast("Failed to mark as paid: " + (data.message || "Unknown error"));
        }
    })
    .catch(err => {
         showToast("Error during payment: " + err);
    });
}
function createAdditionalHtml(addition) {
    // addition: { addition_id, order_group_id, table_number, customer_name, placed_at, items[], count }
    const dineType = "Dine-in"; // or compute if you pass takeout flag to additions
    let title = `Additional Order for Table ${addition.table_number} - ${addition.customer_name} (${dineType})`;
    let itemsHtml = '<ul style="padding-left:16px;margin:8px 0;">';
    addition.items.forEach(item => {
        const priceValue = (item.price !== undefined && item.price !== null) ? Number(item.price).toFixed(2) : '-';
        itemsHtml += `<li><strong>${item.quantity}x ${item.item_name}</strong> <span style="font-weight:normal;">₱${priceValue}</span></li>`;
    });
    itemsHtml += '</ul>';

    // Buttons: Send to Kitchen -> send_additional.php ; Cancel -> cancel_additional.php
    const buttons = `
        <div class="status-btns">
            <button class="btn-send" onclick="sendAdditional('${addition.order_group_id}')">Send to Kitchen</button>
            <button class="btn-cancel" onclick="cancelAdditional('${addition.order_group_id}')">Cancel</button>
        </div>
    `;

    return `
        <div class="order addition" id="addition-${addition.addition_id}">
            <strong>${title}</strong><br>
            <small>Added: ${addition.placed_at}</small>
            ${itemsHtml}
            ${buttons}
        </div>
    `;
}
function showConfirmModal(message, title = "Confirm", okText = "OK", cancelText = "Cancel") {
  return new Promise((resolve) => {
    const overlay = document.getElementById('cashConfirmOverlay');
    const msgEl = document.getElementById('cfMessage');
    const titleEl = document.getElementById('cfTitle');
    const okBtn = document.getElementById('cfOkBtn');
    const cancelBtn = document.getElementById('cfCancelBtn');

    // set content
    titleEl.textContent = title;
    msgEl.innerHTML = message;
    okBtn.textContent = okText;
    cancelBtn.textContent = cancelText;

    // show modal
    overlay.classList.add('visible');
    overlay.setAttribute('aria-hidden', 'false');
    document.body.classList.add('cf-modal-open');
    okBtn.focus();

    // handlers
    function cleanup() {
      overlay.classList.remove('visible');
      overlay.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('cf-modal-open');
      okBtn.removeEventListener('click', onOk);
      cancelBtn.removeEventListener('click', onCancel);
      document.removeEventListener('keydown', onKey);
      overlay.removeEventListener('click', onOverlayClick);
    }
    function onOk(e) {
      if (e) e.preventDefault();
      cleanup();
      resolve(true);
    }
    function onCancel(e) {
      if (e) e.preventDefault();
      cleanup();
      resolve(false);
    }
    function onKey(ev) {
      if (ev.key === 'Escape') onCancel(ev);
      else if (ev.key === 'Enter' && document.activeElement === okBtn) onOk(ev);
    }
    function onOverlayClick(evt) {
      if (evt.target === overlay) onCancel(evt);
    }

    okBtn.addEventListener('click', onOk);
    cancelBtn.addEventListener('click', onCancel);
    document.addEventListener('keydown', onKey);
    overlay.addEventListener('click', onOverlayClick, { once: false });
  });
}

async function sendAdditional(orderGroupId) {
  const ok = await showConfirmModal(
    "Send this Additional Order to Kitchen?\n\nThis will mark the added items as sent to the kitchen.",
    "Send Additional Order",
    "Send",
    "Keep"
  );
  if (!ok) return;

  try {
    const res = await fetch('send_additional.php', {
      method: 'POST',
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ order_group_id: orderGroupId })
    });
    const data = await res.json();
    if (data && data.success) {
      // show toast if you have a toast helper (or use alert)
      if (typeof showToast === 'function') showToast("Additional items sent to kitchen.");
      loadOrders && loadOrders();
    } else {
      if (typeof showToast === 'function') showToast("Failed to send additional: " + (data.message || "Unknown error"));
      console.error('send_additional failed', data);
    }
  } catch (err) {
    if (typeof showToast === 'function') showToast("Error sending additional: " + err);
    console.error(err);
  }
}

// Replace existing cancelAdditional() with this (async)
async function cancelAdditional(orderGroupId) {
  const ok = await showConfirmModal(
    "Cancel this Additional Order? This will remove the added items from the order and cannot be undone.",
    "Cancel Additional Order",
    "Yes, Cancel",
    "No, Keep"
  );
  if (!ok) return;

  try {
    const res = await fetch('cancel_additional.php', {
      method: 'POST',
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ order_group_id: orderGroupId })
    });
    const data = await res.json();
    if (data && data.success) {
      if (typeof showToast === 'function') showToast("Additional items canceled.");
      loadOrders && loadOrders();
    } else {
      if (typeof showToast === 'function') showToast("Failed to cancel additional: " + (data.message || "Unknown error"));
      console.error('cancel_additional failed', data);
    }
  } catch (err) {
    if (typeof showToast === 'function') showToast("Error canceling additional: " + err);
    console.error(err);
  }
}

function loadOrders() {
    const today = new Date().toLocaleDateString('en-CA');
    const url = `fetch_orders.php?date=${today}`;
    fetch(url)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                allOrders = data.data || [];
                const additions = data.additions || [];
                const incomingDiv = document.getElementById("incomingOrders");
                const statusDiv = document.getElementById("orderStatus");
                const editingGroupId = document.getElementById("editingOrderGroupId").value;

                incomingDiv.innerHTML = "<h2>Incoming Orders</h2>";
                statusDiv.innerHTML = "<h2>Order Status</h2>";

                const shownGroupIds = new Set();

                // First render additional cards (they are separate Incoming cards)
                additions.forEach(add => {
                    // skip if editing/resuming this order
                    if (editingGroupId && add.order_group_id === editingGroupId) return;
                    incomingDiv.innerHTML += createAdditionalHtml(add);
                    shownGroupIds.add(add.order_group_id); // mark group as shown as addition (prevent duplicate if desired)
                });

                // Then render main orders (existing logic)
                allOrders.forEach(order => {
                    const status = (order.status || "").trim();
                    const isCompleted = (status.toLowerCase() === "completed");
                    const isPaid = (order.payment_status === "Paid" || order.paid == 1);

                    // Skip the order if currently being edited/resumed
                    if (editingGroupId && order.order_group_id === editingGroupId) {
                        return;
                    }

                    // Skip duplicate (if we already showed an addition for it and you prefer to hide the main order in Incoming)
                    // Note: we still show the main order in Order Status area per normal logic.
                    // Remove this check if you want the main order card to still appear.
                    // if (shownGroupIds.has(order.order_group_id)) return;

                    // Only remove from UI if BOTH completed and paid
                    if (isCompleted && isPaid) {
                        return;
                    }

                    // Show in Incoming Orders if:
                    // - unpaid AND (Pending or On Hold)
                    // - we do not treat has_additional here because additions are handled separately
                    if (!isPaid && (status === "Pending" || status === "On Hold")) {
                        incomingDiv.innerHTML += createOrderHtml(order);
                    } else {
                        statusDiv.innerHTML += createOrderHtml(order);
                    }
                });
            } else {
                document.getElementById("incomingOrders").innerHTML = "<h2>Incoming Orders</h2><p>No orders found for today.</p>";
                document.getElementById("orderStatus").innerHTML = "<h2>Order Status</h2><p>No orders found for today.</p>";
            }
        })
        .catch(err => {
            document.getElementById("incomingOrders").innerHTML = "<h2>Incoming Orders</h2><p>Error loading orders.</p>";
            document.getElementById("orderStatus").innerHTML = "<h2>Order Status</h2><p>Error loading orders.</p>";
            console.error("Error loading orders:", err);
        });
}
addEventListener("DOMContentLoaded", () => {
    loadOrders();
    updateDateTime();
    setInterval(loadOrders, 5000);
    setInterval(updateDateTime, 1000);

    // Show first category by default
    const firstCategoryBtn = document.querySelector(".category-btn");
    if (firstCategoryBtn) {
        firstCategoryBtn.click();
    }
});
function updateDateTime() {
    const now = new Date();
    const options = {
        timeZone: 'Asia/Manila',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
    };
    const dateTimeString = now.toLocaleString('en-US', options);
    document.getElementById("datetime").textContent = dateTimeString;
}
function resumeOrder(groupId) {
    const order = allOrders.find(o => o.order_group_id === groupId);
    if (!order) return;

    cart = [];
    document.getElementById("editingOrderGroupId").value = groupId;

    // fallback order-level values (if needed)
    const orderAllergens = order.allergens ? (typeof order.allergens === 'string' ? order.allergens.split(',') : order.allergens) : [];
    const orderAllergenNote = order.allergen_note || "";
    const orderSpiceLevel = order.spice_level !== undefined ? parseInt(order.spice_level) : 0;
    const orderServingSize = order.serving_size || "";

    if (Array.isArray(order.items)) {
        order.items.forEach(item => {
            cart.push({
                id: item.id || item.item_id || null,
                name: item.item_name,
                price: parseFloat(item.price) || 0,
                qty: parseInt(item.quantity) || 1,
                // Use item value, fallback to order value if missing
                spiceLevel: (typeof item.spice_level !== 'undefined' && item.spice_level !== null && item.spice_level !== "")
                    ? SPICE_LEVEL_WORDS[item.spice_level]
                    : SPICE_LEVEL_WORDS[orderSpiceLevel],
                spiceValue: (typeof item.spice_level !== 'undefined' && item.spice_level !== null && item.spice_level !== "")
                    ? parseInt(item.spice_level)
                    : orderSpiceLevel,
                category: itemCategoryMap[item.id || item.item_id] || "",
                servingSize: item.serving_size !== undefined && item.serving_size !== null && item.serving_size !== ""
                    ? item.serving_size
                    : orderServingSize,
                allergens: item.allergens 
                    ? (typeof item.allergens === 'string' ? item.allergens.split(',').map(a=>a.trim()) : item.allergens) 
                    : orderAllergens,
                notes: item.allergen_note !== undefined && item.allergen_note !== null && item.allergen_note !== ""
                    ? item.allergen_note
                    : orderAllergenNote
            });
        });
    }
    if (order.paid == 1 || order.payment_status === "Paid") {
    isPaid = true;
    document.getElementById('payBtn').disabled = true;
    document.querySelectorAll('.add-btn').forEach(btn => btn.disabled = true);
} else {
    isPaid = false;
    document.getElementById('payBtn').disabled = false;
    document.querySelectorAll('.add-btn').forEach(btn => btn.disabled = false);
}
    renderCart();

    document.getElementById("tableNumber").value = order.table_number || '';
    document.getElementById("customerName").value = order.customer_name || '';
    document.getElementById("takeOutCheckbox").checked = order.takeout == 1;

    const orderDiv = document.getElementById("order-" + groupId);
    if (orderDiv) orderDiv.remove();
}

// === ACTION BUTTONS: UPDATE STATUS, DELETE, VIEW DETAILS ===

function updateStatus(orderGroupId, newStatus) {
    fetch('update_status.php', {
        method: 'POST',
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ order_group_id: orderGroupId, status: newStatus })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            loadOrders();
        } else {
             showToast("Failed to update status: " + (data.message || "Unknown error"));
        }
    })
    .catch(err => {
         showToast("Error updating status: " + err);
    });
}

function deleteOrder(orderGroupId) {
    if (!confirm("Are you sure you want to delete this order?")) return;
    fetch('delete_order.php', {
        method: 'POST',
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ order_group_id: orderGroupId })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            loadOrders();
        } else {
             showToast("Failed to delete order: " + (data.message || "Unknown error"));
        }
    })
    .catch(err => {
         showToast("Error deleting order: " + err);
    });
}

function viewOrder(orderGroupId) {
    const order = allOrders.find(o => o.order_group_id === orderGroupId);
    if (!order) {
        showToast("Order not found.");
        return;
    }

    // Compute sum of item totals (prefer item.total if present)
    let sumItems = 0;
    const itemsHtml = (order.items || []).map(i => {
        let itemTotal = 0;
        if (typeof i.total !== 'undefined' && i.total !== null) {
            itemTotal = Number(i.total);
        } else if (typeof i.price !== 'undefined' && typeof i.quantity !== 'undefined') {
            itemTotal = Number(i.price) * Number(i.quantity);
        } else {
            itemTotal = 0;
        }
        sumItems += itemTotal;

        let cat = i.category || itemCategoryMap[i.id || i.item_id] || itemNameToCategory[i.item_name] || "";
        let line = `<tr>
            <td style="text-align:center;">${i.quantity}</td>
            <td>
                ${i.item_name}
        `;
        if (cat !== "Dessert" && cat !== "Drinks") {
            if (i.serving_size) {
                line += ` <span style="color:#298;">[${i.serving_size}]</span>`;
            }
            if (
                i.spice_level !== undefined &&
                i.spice_level !== null &&
                i.spice_level !== "" &&
                SPICE_LEVEL_WORDS[i.spice_level]
            ) {
                line += ` <span style="color:#b52;">[${SPICE_LEVEL_WORDS[i.spice_level]}]</span>`;
            }
            if (i.allergens && i.allergens.length > 0) {
                line += `<div style="color:#005891;font-size:11px;">Allergens: ${Array.isArray(i.allergens) ? i.allergens.join(", ") : i.allergens}</div>`;
            }
            if (i.allergen_note && i.allergen_note.trim() !== "") {
                line += `<div style="color:#388e3c;font-size:11px;">Note: ${i.allergen_note}</div>`;
            }
        }
        line += `</td>
            <td style="text-align:right;">${itemTotal ? "₱" + itemTotal.toFixed(2) : "-"}</td>
        </tr>`;
        return line;
    }).join("");

    const orderTotal = Number(order.total_price || 0);
    let discountHtml = '';
    // If sumItems > orderTotal, infer a discount/adjustment was applied
    if (sumItems > orderTotal + 0.005) {
        const discountAmount = +(sumItems - orderTotal).toFixed(2);
        const discountPercent = sumItems > 0 ? +((discountAmount / sumItems) * 100).toFixed(2) : 0;
        discountHtml = `
            <p style="margin-top:6px; text-align:right; color:#c62828;">
                <strong>Discount:</strong> ${discountPercent}% (-₱${discountAmount.toFixed(2)})
            </p>
            <p style="margin-top:4px; text-align:right;">
                <strong>Original Total:</strong> ₱${sumItems.toFixed(2)}
            </p>
        `;
    }

    const modalHtml = `
        <div class="receipt-modal" id="receiptModal">
            <div class="receipt-content" style="max-width:500px; margin:0 auto; border:1px solid #ccc; padding:15px; border-radius:8px; background:#fff;">
                <h2>Order Details</h2>
                <p><strong>Order ID:</strong> ${order.id ?? order.order_group_id}</p>
                <p><strong>Table:</strong> ${order.table_number ?? "-"}</p>
                <p><strong>Customer:</strong> ${order.customer_name ?? "-"}</p>
                <p><strong>Date:</strong> ${order.created_at ?? "-"}</p>
                <p><strong>Payment:</strong> ${order.payment_method ?? (order.payment_status ?? "-")}</p>

                <h3>Items</h3>
                <table border="1" width="100%" style="border-collapse:collapse;">
                    <tr>
                        <th>Qty</th>
                        <th>Item</th>
                        <th>Total</th>
                    </tr>
                    ${itemsHtml}
                </table>

                ${discountHtml}

                <p style="margin-top:10px; text-align:right;">
                    <strong>Total Price:</strong> ₱${orderTotal.toFixed(2)}
                </p>

                <div style="margin-top:15px; text-align:center;">
                    <button onclick="printReceipt('${order.order_group_id}')">Print Receipt</button>
                    <button onclick="closeReceipt()">Close</button>
                </div>
            </div>
        </div>
    `;
    closeReceipt();
    document.body.insertAdjacentHTML("beforeend", modalHtml);
}("beforeend", modalHtml);


function closeReceipt() {
    const modal = document.getElementById("receiptModal");
    if (modal) modal.remove();
}

function printReceipt(groupId) {
    const order = allOrders.find(o => o.order_group_id === groupId);
    if (!order) return;

    // compute sum of items to detect discount
    let sumItems = 0;
    const itemsHtml = (order.items || []).map(i => {
        let itemTotal = 0;
        if (typeof i.total !== 'undefined' && i.total !== null) {
            itemTotal = Number(i.total);
        } else if (typeof i.price !== 'undefined' && typeof i.quantity !== 'undefined') {
            itemTotal = Number(i.price) * Number(i.quantity);
        } else {
            itemTotal = 0;
        }
        sumItems += itemTotal;

        let cat = i.category || itemCategoryMap[i.id || i.item_id] || itemNameToCategory[i.item_name] || "";
        let line = `<tr>
            <td style="text-align:center;">${i.quantity}</td>
            <td>
                ${i.item_name}
        `;
        if (cat !== "Dessert" && cat !== "Drinks") {
            if (i.serving_size) {
                line += ` <span style="color:#298;">[${i.serving_size}]</span>`;
            }
            if (
                i.spice_level !== undefined &&
                i.spice_level !== null &&
                i.spice_level !== "" &&
                SPICE_LEVEL_WORDS[i.spice_level]
            ) {
                line += ` <span style="color:#b52;">[${SPICE_LEVEL_WORDS[i.spice_level]}]</span>`;
            }
            if (i.allergens && i.allergens.length > 0) {
                line += `<div style="color:#005891;font-size:11px;">Allergens: ${Array.isArray(i.allergens) ? i.allergens.join(", ") : i.allergens}</div>`;
            }
            if (i.allergen_note && i.allergen_note.trim() !== "") {
                line += `<div style="color:#388e3c;font-size:11px;">Note: ${i.allergen_note}</div>`;
            }
        }
        line += `</td>
            <td style="text-align:right;">${itemTotal ? "₱" + itemTotal.toFixed(2) : "-"}</td>
        </tr>`;
        return line;
    }).join("");

    const orderTotal = Number(order.total_price || 0);
    let discountSection = '';
    if (sumItems > orderTotal + 0.005) {
        const discountAmount = +(sumItems - orderTotal).toFixed(2);
        const discountPercent = sumItems > 0 ? +((discountAmount / sumItems) * 100).toFixed(2) : 0;
        discountSection = `
            <tr>
                <td></td>
                <td style="text-align:right;">Discount (${discountPercent}%):</td>
                <td style="text-align:right;">-₱${discountAmount.toFixed(2)}</td>
            </tr>
            <tr>
                <td></td>
                <td style="text-align:right;">Original Total:</td>
                <td style="text-align:right;">₱${sumItems.toFixed(2)}</td>
            </tr>
        `;
    }

    const receiptHtml = `
        <div style="font-family: monospace; max-width:280px; margin:0 auto; text-align:center;">
            <h2 style="margin:0; font-size:18px;">It's A Thai</h2>
            <p style="margin:2px 0;">2nd Floor Creek Side, Halang Calamba City, Laguna</p>
            <p style="margin:2px 0;">Phone: +63 912 345 6789</p>
            <hr>
                          <p style="text-align:left; font-size:12px;">
                    <strong>Order #:</strong> ${order.id ?? order.order_group_id}<br>
                    <strong>Table:</strong> ${order.table_number ?? "-"}<br>
                    <strong>Customer:</strong> ${order.customer_name ?? "-"}<br>
                    <strong>Date:</strong> ${order.created_at ?? "-"}<br>
                    <strong>Payment Method:</strong> ${order.payment_method ?? (order.payment_status ?? "-")}
                </p>
            <table width="100%" style="border-collapse:collapse; font-size:12px;">
                <tr>
                    <th style="text-align:center;">Qty</th>
                    <th style="text-align:left;">Item</th>
                    <th style="text-align:right;">Total</th>
                </tr>
                ${itemsHtml}
                ${discountSection}
            </table>
            <hr>
            <p style="text-align:right; font-size:14px;">
                <strong>Total: ₱${orderTotal.toFixed(2) ?? "0.00"}</strong>
            </p>
            <p style="margin-top:20px; font-size:12px;">
                Thank you for dining with us!<br>
                Come Again!
            </p>
        </div>
    `;
    const w = window.open("", "PrintReceipt", "width=400,height=600");
    w.document.write(receiptHtml);
    w.print();
    w.close();
}


function resetCart() {
    isPaid = false;
    cart = [];
    renderCart();
    document.getElementById("tableNumber").value = "";
    document.getElementById("customerName").value = "";
    document.getElementById("takeOutCheckbox").checked = false;
    document.getElementById("editingOrderGroupId").value = ""; // <--- clears editing state!
    isPaid = false;
    document.getElementById('payBtn').disabled = false;
}
// ==== MODERN MODAL LOGIC ====
let modalQty = 1;
let modalPrice = 0;
let modalSpice = 0;


// Add item to cart with allergens and notes
function addCartItem(id, name, price, category, servingSize, allergens = [], notes = '') {
    if (parseFloat(price) === 0) {
         showToast("This serving size is not available for order.");
        return;
    }
    // If allergens is a string (from old calls), convert to array
    if (typeof allergens === "string") {
        try { allergens = JSON.parse(allergens); } catch { allergens = []; }
    }
    // If notes is an array (should not), fix to string
    if (Array.isArray(notes)) notes = notes.join(' ');

    // Cart deduplication: now also match allergens, notes, spiceLevel, and spiceValue
    const existing = cart.find(item => 
        item.id === id &&
        item.servingSize === servingSize &&
        JSON.stringify(item.allergens || []) === JSON.stringify(allergens || []) &&
        (item.notes || '') === (notes || '') &&
        (item.spiceLevel || '') === (spiceLevel || '') &&
        (item.spiceValue || 0) === (spiceValue || 0)
    );
    if (existing) {
        existing.qty++;
    } else {
        cart.push({id, name, price, qty: 1, spiceLevel, spiceValue, category, servingSize, allergens, notes});
    }
    renderCart();
}

if (isPaid) {
    document.getElementById('payBtn').disabled = true;
    document.querySelectorAll('.add-btn').forEach(btn => btn.disabled = true);
} else {
    document.getElementById('payBtn').disabled = false;
    document.querySelectorAll('.add-btn').forEach(btn => btn.disabled = false);
}

// === Cart rendering with allergen tags and notes ===
function renderCart() {
    const cartItemsDiv = document.getElementById("cartItems");
    cartItemsDiv.innerHTML = "";
    let total = 0;

    cart.forEach((item, idx) => {
        const lineTotal = item.price * item.qty;
        total += lineTotal;
        // Image
        let imgHtml = item.img ? `<img src='../uploads/${item.img}' style='width:40px;height:40px;border-radius:8px;margin-right:8px;vertical-align:middle;'>` : "";
        // Name and serving size
        let nameHtml = `<strong>${item.name}</strong>`;
        if (SERVING_SIZE_CATEGORIES.includes(item.category) && item.servingSize) {
            nameHtml += ` <span style='color:#298;'>(${item.servingSize})</span>`;
        }
        // Price
        let priceHtml = `₱${item.price.toFixed(2)}`;
        // Spice level
        let spiceLabels = ["No Spice", "Light", "Moderate", "Spicy", "Extra"];
        let spiceHtml = "";
        if (NO_SPICE_CATEGORIES.indexOf(item.category) === -1) {
            let spiceNum = parseInt(item.spiceValue);
            let spiceText = (isNaN(spiceNum) || spiceNum < 0 || spiceNum > 4) ? "No Spice" : spiceLabels[spiceNum] || "No Spice";
            spiceHtml = `<div style='color:#d72c0d;font-weight:500;'>Spice Level: ${spiceText}</div>`;
        }
        // Allergens
        let allergenHtml = "";
        if (item.allergens && item.allergens.length > 0) {
            allergenHtml = `<div style='color:#005891;'>Allergens: ${item.allergens.join(", ")}</div>`;
        }
        // Note
        let noteHtml = "";
        if (item.notes && item.notes !== "") {
            noteHtml = `<div style='color:#388e3c;'>Note: ${item.notes}</div>`;
        }
        // Quantity controls and Remove
        let qtyControls = `
            <span style='margin:0 8px;'>
                <button onclick='changeQty(${idx},-1)' style='background:none;border:none;color:#d72c0d;font-size:18px;font-weight:bold;'>-</button>
                ${item.qty}
                <button onclick='changeQty(${idx},1)' style='background:none;border:none;color:#388e3c;font-size:18px;font-weight:bold;'>+</button>
                <span style='margin-left:12px;cursor:pointer;color:#d9534f;font-weight:500;' onclick='removeItem(${idx})'>Remove</span>
            </span>
        `;
        cartItemsDiv.innerHTML += `
            <div class='cart-line' style='background:#fff;border-radius:12px;padding:10px 16px;margin-bottom:10px;box-shadow:0 2px 8px #0001;display:flex;align-items:center;'>
                ${imgHtml}
                <div style='flex:1;'>
                    ${nameHtml}<br>
                    ${priceHtml}<br>
                    ${spiceHtml}
                    ${allergenHtml}
                    ${noteHtml}
                </div>
                ${qtyControls}
            </div>
        `;
    });
    document.getElementById('cartTotal').textContent = `Total: ₱${total.toFixed(2)}`;
}


</script>
</body>

</html>