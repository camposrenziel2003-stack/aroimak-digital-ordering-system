<?php
// review_add_to_order.php - Para sa pagdagdag ng items sa existing order
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
include "config.php";
include "access_check.php";

$orderGroupId = $_SESSION['add_to_order_group_id'] ?? '';
$cart = $_SESSION['cart'] ?? [];

// 🚫 I-redirect kung walang order ID o walang laman ang cart
if (empty($orderGroupId) || empty($cart)) {
    header("Location: kiosk_home.php");
    exit;
}

// 1. Fetch Existing Order Details (include numeric id)
$customerName = '';
$paymentMethod = '';
$tableNumber = '';
$existingOrder = null;
$orderId = ''; // numeric DB id

$stmt = $conn->prepare("SELECT id, customer_name, payment_method, table_number, paid FROM orders WHERE order_group_id = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param("s", $orderGroupId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $existingOrder = $result->fetch_assoc();
        $orderId = $existingOrder['id'] ?? '';
        $customerName = $existingOrder['customer_name'] ?? 'N/A';
        $paymentMethod = $existingOrder['payment_method'] ?? 'N/A';
        $tableNumber = $existingOrder['table_number'] ?? 'N/A';
        $isPaid = (isset($existingOrder['paid']) && intval($existingOrder['paid']) === 1);
    } else {
        $isPaid = false;
    }
    $stmt->close();
} else {
    $isPaid = false;
}

$cartTotal = 0;
$cartItems = [];
foreach ($cart as $c) {
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
        $itemTotal = $price * $quantity;
        $cartTotal += $itemTotal;
        $cartItems[] = [
            'name' => $row['name'],
            'quantity' => $quantity,
            'price' => $price,
            'total' => $itemTotal,
            'allergens' => $c['allergens'] ?? '',
            'allergen_note' => $c['allergen_note'] ?? '',
            'spice' => $c['spice'] ?? 'No Spice' // <-- ADDED: include spice level
        ];
    }
}
$cartIsEmpty = count($cartItems) === 0;
$totalPrice = $cartTotal;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add to Existing Order</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        html, body {
            height: 100%;
            margin: 0;
        }
        body {
            background: #FFF3E0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center; /* center vertically and horizontally */
            padding: 20px; /* small padding for mobile */
            box-sizing: border-box;
        }
        /* Make wrapper fill available space but keep content centered */
        .container-wrapper {
            width: 100%;
            max-width: 760px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            margin: 0 auto;
            min-height: calc(100vh - 40px); /* ensure vertical centering space */
            box-sizing: border-box;
        }
        .card {
            background: #fff;
            border-radius: 24px;
            padding: 30px 32px 32px 32px;
            box-shadow: 0 8px 30px rgba(255,152,0,0.11), 0 2px 8px rgba(255,193,7,0.09);
            width: 100%;
            box-sizing: border-box;
            max-height: calc(100vh - 80px); /* avoid overflowing viewport */
            overflow-y: auto;
        }
        .order-title {
            font-size: 1.4em;
            color: #e65100;
            font-weight: 900;
            margin: 0 0 8px 0;
        }
        .info-note {
            font-size: 1.07em;
            color: #833453;
            background: #FFFDE7;
            border-radius: 11px;
            padding: 10px 18px;
            margin-bottom: 16px;
            border: 1.5px solid #FFD54F;
        }
        .existing-details {
            background: #FFFCF7;
            border: 1.5px solid #FFD54F;
            border-radius: 13px;
            margin-bottom: 18px;
            padding: 18px 20px 14px 20px;
        }
        .existing-details h2 {
            color: #FF9800;
            font-size: 1.17em;
            font-weight: bold;
            margin: 0 0 5px 0;
        }
        .existing-details p {
            margin: 6px 0 2px 0;
            font-size: 1.01em;
            color: #593e19;
        }
        .existing-details strong {
            color: #E65100;
        }
        hr {
            border: none;
            border-top: 2px solid #FF9800;
            margin: 13px 0 15px 0;
        }
        .cart-section-title {
            color: #E65100;
            margin: 0 0 10px 0;
            font-size: 1.13em;
            font-weight: 700;
            text-align: center;
        }
        .cart-items {
            margin-bottom: 12px;
        }
        .cart-item {
            display: flex;
            background: #FFF8E1;
            border-radius: 10px;
            box-shadow: 0 1.5px 5px rgba(255,193,7,0.10);
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
            padding: 13px 18px;
            font-size: 1.05em;
        }
        .cart-item-info {
            color: #5D4037;
            font-weight: 600;
            letter-spacing: 0.01em;
        }
        .cart-item-price {
            font-weight: 800;
            color: #F57C00;
            font-size: 1.02em;
        }
        .total-line {
            text-align: right;
            color: #E64A19;
            font-size: 1.17em;
            font-weight: bold;
            margin: 10px 0 0 0;
        }
        .buttons {
            margin-top: 22px;
            display: flex;
            gap: 12px;
            justify-content: center; /* center the action buttons */
            flex-wrap: wrap;
        }
        .btn-action, .btn-submit {
            background: linear-gradient(135deg, #FF9800, #FFD54F);
            color: #fff;
            border: none;
            border-radius: 13px;
            font-weight: 700;
            font-size: 1.05em;
            padding: 11px 16px;
            box-shadow: 0 4px 18px rgba(255,152,0,0.09);
            cursor: pointer;
            transition: background 0.18s, box-shadow 0.15s, transform 0.13s;
            outline: none;
            letter-spacing: 0.03em;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .btn-action {
            background: #FFFCF7;
            color: #FF9800;
            border: 2px solid #FFD54F;
            font-weight: 700;
        }
        .btn-action:hover {
            background: #FFEBB5;
            color: #E65100;
            transform: scale(1.03);
        }
        .btn-submit:hover {
            background: linear-gradient(135deg, #FFD54F, #FF9800);
            color:#fff;
            transform: scale(1.03);
        }

        /* Modal Styles */
        .modal-overlay {
            position: fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(20,6,2,0.31);
            z-index:2000; display:none; align-items:center; justify-content:center;
        }
        .modal-box {
            background:white; border-radius:18px;
            box-shadow:0 8px 36px rgba(0,0,0,0.16);
            padding:30px 23px 24px 23px; min-width:300px; max-width:95vw; text-align:center;
        }
        .modal-box h2 { color:#FF9800; font-size:1.1em; margin-top:0; }
        .modal-btns { display:flex; gap:11px; margin-top:20px; justify-content:center; }
        .modal-btns button { min-width:110px }
        @media (max-width: 600px) {
            .card {
                padding: 18px;
                border-radius: 12px;
            }
            .container-wrapper {
                min-height: auto;
            }
            .cart-item {
                flex-direction: column;
                padding: 10px 12px;
                font-size: 1em;
            }
            .cart-item-price {
                align-self: flex-start;
            }
            .total-line {
                font-size: 1em;
            }
            .btn-action, .btn-submit {
                font-size: 0.98em;
                padding: 10px 12px;
                border-radius: 10px;
            }
            .buttons {
                gap: 10px;
            }
            .modal-box { padding:14px; min-width: 86vw; }
            .modal-btns { flex-direction:column; gap:8px; }
        }
.modal-success {background:white; border-radius:18px;box-shadow:0 8px 36px rgba(0,0,0,0.16);padding:38px 25px 32px 25px; min-width:260px; max-width:90vw; text-align:center;}
.success-icon {font-size: 2.9em; color: #43b942; margin-bottom: 15px;}
    </style>
</head>
<body>
    <div class="container-wrapper">
    <div class="card" role="main" aria-labelledby="orderTitle">
        <div class="order-title" id="orderTitle">
            <i class="fas fa-plus-circle" style="color:#FF9800;margin-right:6px"></i>
            Add to Order: <span style="font-weight:600;">#<?= htmlspecialchars($orderId ?: $orderGroupId) ?></span>
        </div>
        <div class="info-note" role="note">
            Your new items will be <b>ADDED</b> to the existing order details below.
        </div>
        <div class="existing-details" aria-live="polite">
            <h2>
                <i class="fas fa-user" style="color:#FF9800;margin-right:6px"></i>
                Existing Order Information (Cannot be changed)
            </h2>
            <p><strong>Customer Name:</strong> <?= htmlspecialchars($customerName) ?></p>
            <p><strong>Table/Order Group ID:</strong> <?= htmlspecialchars($tableNumber ? $tableNumber : $orderGroupId) ?></p>
            <p><strong>Payment Method:</strong> <?= htmlspecialchars($paymentMethod) ?></p>
            <p><strong>Payment Status:</strong> <span style="color:<?= ($isPaid ? '#388e3c' : '#d35400') ?>; font-weight:700;"><?= ($isPaid ? 'Paid' : 'Unpaid') ?></span></p>
        </div>
        <!-- Edit Cart Modal -->
<div id="editCartModal" class="modal-overlay" style="display:none;">
  <div class="modal-box" style="max-width:410px;padding:23px 13px 19px 13px;">
    <h2 style="color:#FF9800;margin-top:0;font-weight:bold;"><i class="fa fa-edit"></i> Edit New Items</h2>
    <div id="editCartItems" style="margin-bottom:14px;"></div>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px;">
      <button class="btn-action" id="editCartCancelBtn" style="flex:1;margin-right:6px;">Cancel</button>
      <button class="btn-submit" id="editCartSaveBtn" style="flex:1;margin-left:6px;"><i class="fa fa-save"></i> Save Changes</button>
    </div>
  </div>
</div>
<style>
.edit-cart-row {display:flex;align-items:center;justify-content:space-between;margin-bottom:9px;border-bottom:1px solid #ffe0b2;padding:7px 0 8px;}
.edit-cart-name {flex:1 1 160px;font-weight:650;color:#813013;}
.edit-cart-controls {display:flex;align-items:center;gap:7px;}
.edit-cart-qtyBtn {padding:2px 11px;font-size:17px;line-height:1.2;background:#ffd54f;color:#993800;border:none;border-radius:5px;cursor:pointer;}
.edit-cart-qtyInput {width:38px;text-align:center;border-radius:6px;border:1.4px solid #ffaa1a;}
.edit-cart-remove {background:#ffebee;border:none;color:#d32f2f;font-size:1.03em;padding:2.7px 10px;border-radius:7px;cursor:pointer;}
</style>
        <hr>
        <div class="cart-section-title">
            
        </div>
        <?php if ($cartIsEmpty): ?>
            <p style="text-align:center;">No new items in cart to add.</p>
        <?php else: ?>
            <div class="cart-items" aria-live="polite">
                <?php foreach ($cartItems as $item): ?>
    <div class="cart-item">
        <div class="cart-item-info">
            <?= htmlspecialchars($item['name']) ?>
            <span style="color: #FF9800; margin-left:7px">× <?= $item['quantity'] ?></span>
            <?php if (!empty($item['allergens'])): ?>
                <div style="color: #1565c0; font-size:0.97em;">
                    <b>Allergens:</b> <?= htmlspecialchars($item['allergens']) ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($item['spice'])): ?>
                <div style="color: #d84315; font-size:0.97em;">
                    <b>Spice Level:</b> <?= htmlspecialchars($item['spice']) ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($item['allergen_note'])): ?>
                <div style="color: #388e3c; font-size:0.96em;">
                    <b>Note:</b> <?= htmlspecialchars($item['allergen_note']) ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="cart-item-price">
            ₱<?= number_format($item['total'], 2) ?>
        </div>
    </div>
<?php endforeach; ?>
            </div>
            <div class="total-line">
                Total for these <span style="color:#FF9800"><b>NEW</b></span> items: ₱<?= number_format($totalPrice, 2) ?>
            </div>
            
            <div class="buttons" role="group" aria-label="Cart actions">
              <button
                  type="button"
                  class="btn-action"
                  onclick="window.location.href='summary_orders.php?id=<?= rawurlencode($orderGroupId) ?>'"
                >
                  <i class="fas fa-arrow-left"></i> Back
                </button>
                <button type="button" class="btn-action" onclick="openEditCartModal()">
                    <i class="fas fa-edit"></i> Edit New Items
                </button>

                

                <form id="addToOrderForm" action="add_items_to_order.php" method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="append_items">
                    <input type="hidden" name="order_group_id" value="<?= htmlspecialchars($orderGroupId) ?>">
                    <input type="hidden" name="cart" value='<?= htmlspecialchars(json_encode($_SESSION['cart']), ENT_QUOTES, "UTF-8") ?>'>
                    <button
                      type="button"
                      class="btn-submit"
                      id="showConfirmModalBtn"
                      <?= $isPaid ? 'disabled title="This order is already paid; you cannot add items."' : '' ?>
                    >
                      <i class="fas fa-plus"></i> CONFIRM & ADD TO ORDER
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>
    </div>

    <!-- Popup modal for order confirmation -->
    <div id="confirmModal" class="modal-overlay">
      <div class="modal-box">
        <h2><i class="fas fa-question-circle" style="color:#FF9800;"></i> Confirm Add Items</h2>
        <p style="margin:15px 0 10px 0;">Are you sure you want to <b>add these items to your current order?</b></p>
        <div class="modal-btns">
          <button class="btn-submit" id="modalConfirmBtn">
            <i class="fas fa-check"></i> Yes, Add Items
          </button>
          <button class="btn-action" id="modalCancelBtn" style="background:#eee;color:#FF9800;">
            <i class="fas fa-times"></i> Cancel
          </button>
        </div>
      </div>
    </div>
<div id="successModal" class="modal-overlay" style="display:none;">
  <div class="modal-success">
    <div class="success-icon"><i class="fas fa-check-circle"></i></div>
    <h2 style="color:#43b942;margin:7px 0 2px;">Items Added!</h2>
    <p style="margin:13px 0 24px;">Your new order items have been added.<br>Redirecting to summary...</p>
    <button id="successToSummaryBtn" class="btn-submit" style="background:#43b942;">
        <i class="fas fa-clipboard-list"></i> View Order Summary Now
    </button>
  </div>
</div>
    <script>

document.addEventListener('DOMContentLoaded', function () {
  const modal = document.getElementById('confirmModal');
  const showBtn = document.getElementById('showConfirmModalBtn');
  const cancelBtn = document.getElementById('modalCancelBtn');
  const confirmBtn = document.getElementById('modalConfirmBtn');
  const form = document.getElementById('addToOrderForm');
  const successModal = document.getElementById('successModal');
  const successToSummaryBtn = document.getElementById('successToSummaryBtn');
  const orderGroupId = "<?= htmlspecialchars($orderGroupId ?? '') ?>";

  function showModal() { modal.style.display = 'flex'; }
  function hideModal() { modal.style.display = 'none'; }
  function showSuccessModal() { successModal.style.display = 'flex'; }
  function hideSuccessModal() { successModal.style.display = 'none'; }

  if (showBtn) showBtn.addEventListener('click', function (e) {
    e.preventDefault();
    // Guard: if button disabled (paid), inform user
    if (showBtn.disabled) {
      alert('Cannot add items: this order is already paid.');
      return;
    }
    showModal();
  });
  if (cancelBtn) cancelBtn.addEventListener('click', hideModal);

  if (confirmBtn) confirmBtn.addEventListener('click', function () {
    hideModal();

    // AJAX POST
    const formData = new FormData(form);
    confirmBtn.disabled = true;
    confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding...';

    fetch('add_items_to_order.php', {
      method: 'POST',
      body: formData,
    })
      .then(res => res.json())
      .then(data => {
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = '<i class="fas fa-check"></i> Yes, Add Items';
        if (data.status === 'success') {
          // Show success modal, then redirect
          showSuccessModal();
          setTimeout(() => {
            window.location.href = 'summary_orders.php?id=' + encodeURIComponent(orderGroupId);
          }, 1700);
        } else {
          alert('Failed to add items: ' + (data.message || 'Unknown error'));
        }
      })
      .catch(err => {
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = '<i class="fas fa-check"></i> Yes, Add Items';
        alert('Network error, contact staff!\n' + err);
      });
  });

  // Success modal: click button to go immediately
  if (successToSummaryBtn) {
    successToSummaryBtn.addEventListener('click', function () {
      window.location.href = 'summary_orders.php?id=' + encodeURIComponent(orderGroupId);
    });
  }

  // Click outside of modal closes
  modal.addEventListener('click', function (e) { if (e.target === modal) hideModal(); });
  successModal.addEventListener('click', function (e) { if (e.target === successModal) hideSuccessModal(); });
});
    
    
// -- Cart modal logic --
const editModal = document.getElementById('editCartModal');
const editCartItemsDiv = document.getElementById('editCartItems');
let editCartData = JSON.parse('<?= json_encode($_SESSION['cart']) ?>') || {};

function renderEditCart() {
  editCartItemsDiv.innerHTML = '';
  const itemKeys = Object.keys(editCartData);
  if (itemKeys.length === 0) {
    editCartItemsDiv.innerHTML = "<p style='color:#b71c1c'>No items in cart.</p>";
    return;
  }
  itemKeys.forEach(key => {
    const item = editCartData[key];
    editCartItemsDiv.innerHTML += `
      <div class="edit-cart-row" data-key="${key}">
        <div class="edit-cart-name">${item.name}</div>
        <div class="edit-cart-controls">
          <button class="edit-cart-qtyBtn" onclick="changeEditQty('${key}', -1)">−</button>
          <input type="number" class="edit-cart-qtyInput" min="1" max="50" value="${item.quantity}" onchange="directEditQty('${key}', this.value)">
          <button class="edit-cart-qtyBtn" onclick="changeEditQty('${key}', 1)">+</button>
          <button class="edit-cart-remove" title="Remove" onclick="removeEditItem('${key}')"><i class="fa fa-times"></i></button>
        </div>
      </div>
    `;
  });
}
window.openEditCartModal = function() {
  renderEditCart();
  editModal.style.display = 'flex';
  document.body.style.overflow = 'hidden';
};
document.getElementById('editCartCancelBtn').onclick = function() {
  editModal.style.display = 'none';
  document.body.style.overflow = '';
};
function changeEditQty(key, delta) {
  if (!editCartData[key]) return;
  let q = parseInt(editCartData[key].quantity) + delta;
  if (q < 1) q = 1;
  if (q > 50) q = 50;
  editCartData[key].quantity = q;
  renderEditCart();
}
function directEditQty(key, val) {
  if (!editCartData[key]) return;
  let q = Math.max(1, Math.min(50, parseInt(val) || 1));
  editCartData[key].quantity = q;
  renderEditCart();
}
function removeEditItem(key) {
  delete editCartData[key];
  renderEditCart();
}

// -- Save: AJAX POST to update sesssion cart, then reload page --
document.getElementById('editCartSaveBtn').onclick = function() {
  // If all items removed, clear cart
  if (Object.keys(editCartData).length === 0) {
    if (confirm("Your cart will be empty. Return to menu?")) {
      // AJAX/POST to clear, then reload page
      fetch('update_cart.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'clear_cart=1'
      }).then(()=>{ window.location.href = 'kiosk_home.php'; });
    }
    return;
  }
  // Otherwise, update session cart (AJAX)
  fetch('update_cart.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({cart: editCartData, bulk_update: 1})
  })
  .then(res=>res.json())
  .then(data=>{
    if (data && data.success) window.location.reload();
    else alert('Update failed, please try again.');
  }).catch(()=>{ alert('Error updating cart.') });
};
</script>
</body>
</html>