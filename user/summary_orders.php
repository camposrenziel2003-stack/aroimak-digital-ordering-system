<?php
session_start();
include "config.php";
include "access_check.php";

$addToOrderGroupId = $_GET['add_to'] ?? '';
if ($addToOrderGroupId !== '') {
    $_SESSION['add_to_order_group_id'] = $addToOrderGroupId;
} else {
    unset($_SESSION['add_to_order_group_id']);
}

// Cancel window (seconds) - adjust if you want 60 or 180
$CANCEL_WINDOW_SECONDS = 180;

// =================================================================
// AJAX POST Request Handler for Cancellation
// =================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_order') {
    header('Content-Type: application/json; charset=utf-8');

    $orderGroupId = $_POST['order_group_id'] ?? '';

    // Fetch status and authoritative timestamp (use COALESCE to avoid NULL updated_at)
    $stmt = $conn->prepare(
        "SELECT status, updated_at, created_at, 
                UNIX_TIMESTAMP(COALESCE(updated_at, created_at)) AS updated_unix,
                paid
         FROM orders WHERE order_group_id = ? LIMIT 1"
    );
    $stmt->bind_param("s", $orderGroupId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Order not found.']);
        exit;
    }

    $currentStatus = strtolower($order['status'] ?? '');
    $updatedAtUnix = isset($order['updated_unix']) && $order['updated_unix'] !== null ? (int)$order['updated_unix'] : time();
    $now = time();
    $cancellable = false;
    $cancelReason = '';

    if (in_array($currentStatus, ['pending', 'not send to kitchen'])) {
        $cancellable = true;
    } elseif ($currentStatus === 'preparing') {
      $elapsed = $now - $updatedAtUnix;
      if ($elapsed < $CANCEL_WINDOW_SECONDS) {
        $cancellable = true;
      } else {
        // Human-friendly window description (minutes if whole minutes, otherwise seconds)
        if ($CANCEL_WINDOW_SECONDS % 60 === 0) {
          $mins = intval($CANCEL_WINDOW_SECONDS / 60);
          $timeLabel = $mins . ' minutes';
        } else {
          $timeLabel = $CANCEL_WINDOW_SECONDS . ' seconds';
        }
        $cancelReason = 'Order has been Preparing for more than ' . $timeLabel . ', cancellation not allowed.';
      }
    } else {
        $cancelReason = 'Cannot cancel order in status: ' . $order['status'];
    }

    if ($cancellable) {
        // Always set consistent "Canceled" status and update timestamp
        $newStatus = 'Canceled';
        $updateStmt = $conn->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE order_group_id = ?");
        $updateStmt->bind_param("ss", $newStatus, $orderGroupId);
        if ($updateStmt->execute()) {
            // Mark that THIS user (session) cancelled the order so additional-order button will be disabled only in that case
            $_SESSION['user_canceled_order_' . $orderGroupId] = true;

            // After canceling (by user), additional orders shouldn't be allowed for this session
            $allow_additional = false;

          // Also remove any unsent "additional" items (created after the order created_at and not sent to kitchen)
          $s3 = $conn->prepare("SELECT created_at FROM orders WHERE order_group_id = ? LIMIT 1");
          if ($s3) {
            $s3->bind_param("s", $orderGroupId);
            $s3->execute();
            $r3 = $s3->get_result()->fetch_assoc();
            $s3->close();
            if ($r3 && !empty($r3['created_at'])) {
              $orderCreatedAt2 = $r3['created_at'];
              $delStmt = $conn->prepare(
                "DELETE oi FROM order_items oi
                 WHERE oi.order_group_id = ?
                   AND oi.created_at > ?
                   AND (oi.sent_to_kitchen IS NULL)"
              );
              if ($delStmt) {
                $delStmt->bind_param("ss", $orderGroupId, $orderCreatedAt2);
                $delStmt->execute();
                $deleted_count = $delStmt->affected_rows;
                $delStmt->close();
              }
            }
          }

            echo json_encode([
                'status' => 'success',
                'message' => 'Order cancellation successful.',
                'new_status' => $newStatus,
                'cancellable' => false,
                'cancel_reason' => 'Already cancelled.',
                'allow_additional' => $allow_additional
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Database error during update execution.']);
        }
        $updateStmt->close();
    } else {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => $cancelReason ?: 'Order cannot be cancelled.']);
    }

    $conn->close();
    exit;
}
// =================================================================
// END AJAX Handler
// =================================================================

// -- Get order_group_id from URL --
$orderGroupId = $_GET['id'] ?? '';
if (!$orderGroupId) {
    echo "<p>Invalid order reference.</p>";
    exit;
}

// -- Fetch order details (use COALESCE so updated_unix is meaningful) --
$stmt = $conn->prepare(
    "SELECT id, order_group_id, customer_name, table_number, total_price, status, created_at, updated_at,
            UNIX_TIMESTAMP(COALESCE(updated_at, created_at)) AS updated_unix, payment_method, paid
     FROM orders WHERE order_group_id = ? LIMIT 1"
);
$stmt->bind_param("s", $orderGroupId);
$stmt->execute();
$orderResult = $stmt->get_result();
$order = $orderResult->fetch_assoc();
$stmt->close();

if (!$order) {
    echo "<p>Order not found.</p>";
    exit;
}

// -- Cancel button logic (server-rendered initial) --
$orderStatus = strtolower($order['status']);
$updatedAtUnix = isset($order['updated_unix']) && $order['updated_unix'] !== null ? (int)$order['updated_unix'] : (isset($order['updated_at']) ? strtotime($order['updated_at']) : time());
$now = time();
$canCancel = false;
$cancelExplain = '';

if ($orderStatus === 'pending' || $orderStatus === 'not send to kitchen') {
    $canCancel = true;
} elseif ($orderStatus === 'preparing') {
    $elapsed = $now - $updatedAtUnix;
    if ($elapsed < $CANCEL_WINDOW_SECONDS) {
        $canCancel = true;
        $cancelExplain = "Can only cancel within {$CANCEL_WINDOW_SECONDS} seconds of Preparing.";
    } else {
    if ($CANCEL_WINDOW_SECONDS % 60 === 0) {
      $mins = intval($CANCEL_WINDOW_SECONDS / 60);
      $timeLabel = $mins . ' minutes';
    } else {
      $timeLabel = $CANCEL_WINDOW_SECONDS . ' seconds';
    }
    $cancelExplain = "Preparing more than {$timeLabel}: Too late to cancel.";
    }
} else {
    $cancelExplain = "Cannot cancel at this stage.";
}
$isCancellable = $canCancel;

// Determine if Additional Order is allowed: only when order is NOT paid
// IMPORTANT: per request, additional-order button should be disabled only when the user themself cancelled the order.
// We store that information in session when the user performs a cancel via this page (see POST handler above).
$paidFlag = isset($order['paid']) && intval($order['paid']) === 1;
$userCanceledSession = !empty($_SESSION['user_canceled_order_' . $orderGroupId]);
$allowAdditional = (!$paidFlag) && !$userCanceledSession;

// Defensive default for $additionalTitle — ensure it's always defined
if (!isset($additionalTitle)) {
    if (!empty($paidFlag) && $paidFlag) {
        $additionalTitle = 'This order has already been paid; you cannot add items.';
    } elseif ($userCanceledSession) {
        $additionalTitle = 'You cancelled this order; you cannot add items.';
    } elseif (isset($allowAdditional) && !$allowAdditional) {
        $additionalTitle = 'You can only add items to active, unpaid orders (orders that are not canceled/closed).';
    } else {
        $additionalTitle = 'Add items to this unpaid order';
    }
}
// -- Format other data --
$createdAt = date("F j, Y - g:ia", strtotime($order['created_at']));
$paymentLabel = '';
if (!empty($order['payment_method'])) {
    $paymentLabel = $order['payment_method'];
} elseif (isset($order['paid']) && intval($order['paid']) === 1) {
    $paymentLabel = 'Paid';
} else {
    $paymentLabel = 'Unpaid';
}

$paymentStatus = (isset($order['paid']) && intval($order['paid']) === 1) ? 'Paid' : 'Unpaid';

// -- Fetch items (include created_at so we can mark additions)
$itemStmt = $conn->prepare("SELECT item_name, quantity, price, total, spice_level, allergens, allergen_note, serving_size, created_at
                             FROM order_items WHERE order_group_id = ?");
$itemStmt->bind_param("s", $orderGroupId);
$itemStmt->execute();
$itemResult = $itemStmt->get_result();
$items = $itemResult->fetch_all(MYSQLI_ASSOC);
$itemStmt->close();

$spiceLabels = [
    0 => 'No Spice',
    1 => 'Light',
    2 => 'Moderate',
    3 => 'Spicy',
    4 => 'Extra'
];
$isCanceled = (strpos(strtolower($order['status']), 'cancel') !== false);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Summary of Orders</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
/* =================================================================
  Pangkalahatang CSS Styles (Mula sa Unang Code Block)
================================================================= */
body {
    background-color: #FFF3E0;
    font-family: 'Segoe UI', Tahoma, sans-serif;
    margin: 0;
    padding: 0;
    display: flex;       
    justify-content: center;
    min-height: 100vh;
}

.container-wrapper {
    display: flex;
    flex-direction: column;
    justify-content: center;
    width: 100%;
    max-width: 680px; /* Ibinabalik sa 680px based sa second code, though 600px ang original */
    margin: 40px auto; 
}

.container {
    background: #fff;
    border-radius: 25px;
    padding: 20px;
    box-shadow: 15px 20px 15px rgba(0,0,0,0.1);
    box-sizing: border-box;
    width: 100%;
}


.header { display:flex; align-items:center; margin-bottom:20px; flex-wrap: wrap; }
.header img { height: 50px; margin-right:12px; }
.header h1 { color:#FF9800; font-size:22px; margin:0; }
.divider { border-bottom: 2px solid orange; margin: 15px 0; }
.items { margin-top:15px; }
.item { display:flex; justify-content:space-between; margin-bottom:10px; padding:8px; border-bottom:1px solid #eee; flex-wrap: wrap; }
.item-details { font-size:14px; flex: 1 1 70%; }
.item-price { flex: 1 1 25%; text-align: right; font-weight: bold; }
.item-details p { margin: 2px 0; font-size: 13px; }
.additional-badge { color: #2e7d32; font-weight: 700; margin-right:6px; }
.buttons { margin-top:20px; text-align:center; display:flex; flex-wrap:wrap; justify-content:center; gap:10px; }
.btn {
  padding: 12px 24px;
  border: none;
  border-radius: 18px;
  font-size: 16px;
  cursor: pointer;
  font-weight: bold;
  background: linear-gradient(135deg, #FF9800, #FFD54F);
  color: #fff;
  box-shadow: 0 8px 18px rgba(255,152,0,0.19), 0 2px 10px rgba(255,193,7,0.09);
  transition: background 0.22s, box-shadow 0.22s, transform 0.18s;
  display: inline-flex;
  align-items: center;
  gap: 7px;
}
.btn:hover:not(:disabled) {
  background: linear-gradient(135deg, #FFD54F, #FF9800);
  transform: scale(1.08);
  box-shadow: 0 10px 22px rgba(255,152,0,0.23);
}
/* Ensure :hover works for non-disabled buttons in the second structure */
.btn:hover {
  background: linear-gradient(135deg, #FFD54F, #FF9800);
  transform: scale(1.08);
  box-shadow: 0 10px 22px rgba(255,152,0,0.23);
}


.btn-orange {
  background: linear-gradient(135deg, #FF9800, #FFD54F);
  color: #fff;
  border: none;
  font-weight: bold;
  box-shadow: 0 8px 18px rgba(255,152,0,0.22), 0 2px 10px rgba(255,193,7,0.12);
}
.btn-orange:hover:not(:disabled) {
  background: linear-gradient(135deg, #FFD54F, #FF9800);
  transform: scale(1.08);
  box-shadow: 0 10px 22px rgba(255,152,0,0.23);
}

.btn-white {
  background: #fff;
  color: #FF9800;
  border: 2px solid #FFD54F;
  font-weight: bold;
  box-shadow: 0 8px 18px rgba(255,152,0,0.22), 0 2px 10px rgba(255,193,7,0.10);
}
.btn-white:hover:not(:disabled) {
  background: linear-gradient(135deg, #FFD54F, #FF9800);
  color: #fff;
  transform: scale(1.08);
  box-shadow: 0 10px 22px rgba(255,152,0,0.23);
}

/* 🚨 NEW: Cancel button and disabled style */
.btn-cancel {
  background: linear-gradient(135deg, #f44336, #e57373); /* Red gradient */
  color: #fff;
  box-shadow: 0 8px 18px rgba(244,67,54,0.22), 0 2px 10px rgba(229,115,115,0.12);
}
.btn-cancel:hover:not(:disabled) {
  background: linear-gradient(135deg, #e57373, #f44336);
  transform: scale(1.08);
  box-shadow: 0 10px 22px rgba(244,67,54,0.23);
}

/* Disabled state */
.btn:disabled, .btn:disabled:hover {
  background: #ccc;
  cursor: not-allowed;
  transform: none;
  box-shadow: none;
  opacity: 0.6;
}

/* Modal buttons */
.modal-content .btn {
  background: linear-gradient(135deg, #FF9800, #FFD54F);
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
.total { text-align:right; font-weight:bold; margin-top:15px; font-size:18px; }
.note { color:red; font-size:12px; margin-top:8px; }

/* ✅ Responsive adjustments */
@media (max-width: 600px) {
  .container {
    width: 95%; 
    margin: 15px auto; 
    padding: 15px; 
  }
  .header {
    flex-direction: column;
    align-items: center;
    text-align: center;
  }
  .header img {
    margin: 0 0 10px 0;
  }
  .item {
    flex-direction: column;
    align-items: flex-start;
  }
  .item-price {
    text-align: left;
    margin-top: 5px;
  }
}
/* Modal styles included for cancel modal */
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
  padding: 30px 40px;
  text-align: center;
  max-width: 480px;
  width: 90%;
  box-shadow: 0 8px 20px rgba(0,0,0,0.2);
  animation: scaleIn 0.3s ease-out;
}
.modal-content .icon {
  width: 70px;
  height: 70px;
  background: #FFF3E0;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 20px;
}
.modal-content h2 { margin: 0 0 10px; font-size: 22px; color: #333; }
.modal-content p { margin: 0 0 20px; color: #666; font-size: 15px; }
@keyframes scaleIn { from { transform: scale(0.8); opacity: 0; } to { transform: scale(1); opacity: 1; } }
/* ======= CANCEL MODAL (for staff/polling cancellation) ======= */
#cancelModalOverlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.18);
  z-index: 16000;
  justify-content: center;
  align-items: center;
  padding: 20px;
  -webkit-tap-highlight-color: transparent;
}
#cancelModalOverlay.show { display: flex; }

#cancelModal {
  width: 90%;
  max-width: 480px;
  background: linear-gradient(180deg, #fff8f8 0%, #fff4f4 100%);
  border-radius: 14px;
  box-shadow: 0 14px 40px rgba(0,0,0,0.12);
  padding: 28px 34px;
  text-align: center;
  animation: cancelIn 0.28s ease-out;
  overflow: hidden;
}

/* Layout: centered content */
.cancel-body {
  display: flex;
  flex-direction: column;
  gap: 14px;
  align-items: center;
}

/* Headline with icon */
.cancel-headline {
  display: flex;
  gap: 12px;
  align-items: center;
  justify-content: center;
  flex-wrap: nowrap;
}
.cancel-headline .icon {
  width: 36px;
  height: 36px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: #b71c1c;
}
.cancel-headline h2 {
  margin: 0;
  font-size: 1.35rem;
  font-weight: 800;
  color: #b71c1c;
  letter-spacing: -0.02em;
}

/* Message */
.cancel-message {
  max-width: 640px;
  color: #222;
  font-size: 1rem;
  line-height: 1.5;
  margin: 0 auto;
  padding: 0 8px;
}

/* Action button centered and styled */
.cancel-action-wrap {
  margin-top: 6px;
  display:flex;
  justify-content:center;
  align-items:center;
  border: none;
}
.btn-order-again {
  background: linear-gradient(180deg,#ffb347,#ff9f00);
  color: #fff;
  padding: 10px 15px;
  border-radius: 14px;
  font-weight: 700;
  font-size: 1rem;
  display: inline-flex;
  gap: 10px;
  cursor: pointer;
}
.btn-order-again:active { transform: translateY(1px); }
.btn-order-again .fa-home { font-size: 1.05rem; }

/* Small screens: keep readable */
@media (max-width: 520px) {
  #cancelModal { padding: 20px; border-radius:12px; }
  .cancel-headline h2 { font-size: 1.05rem; }
  .cancel-message { font-size: 0.95rem; }
  .btn-order-again { padding:10px 16px; border-radius:12px; font-size:0.95rem; }
}

@keyframes cancelIn {
  from { transform: translateY(8px) scale(.995); opacity: 0; }
  to  { transform: translateY(0) scale(1); opacity: 1; }
}

/* Body lock while modal open */
body.modal-open { overflow: hidden; }
/* Error Modal Styles */
#errorModal {
  display: none;
  position: fixed;
  z-index: 9999;
  left: 0; top: 0;
  width: 100%; height: 100%;
  background: rgba(230,43,43,0.09);
  justify-content: center;
  align-items: center;
}
#errorModal .modal-content {
  background: #fff;
  border-radius: 18px;
  box-shadow: 0 12px 28px #d32f2f38;
  padding: 28px 30px;
  text-align: center;
  animation: scaleIn 0.3s ease-out;
}
#errorModal button {
  min-width: 110px;
}
#errorModal h2 { color: #d32f2f; }
#errorModal .icon { margin:0 auto 16px auto; }
#cancelConfirmationModal {
  z-index: 17050; /* greater than cancelModalOverlay (16000) */
  position: fixed; /* ensure positioned for z-index to take effect */
}

/* Make overlay inert when hidden, and only accept pointer-events when visible */
#cancelModalOverlay {
  pointer-events: none; /* do not catch clicks while hidden */
}
#cancelModalOverlay.show {
  pointer-events: auto;  /* allow interaction when explicitly shown */
}
</style>
</head>
<body>
    <div>
        <img src="images/decor-noodles.png" class="floating-decor decor-1" alt="Decorative Noodles" style="display:none;">
    </div>
<div class="container-wrapper">
<div class="container">
    <div class="header">
        <img src="images/logo.png" alt="Logo">
        <h1>SUMMARY OF ORDERS</h1>
    </div>
    <div class="divider"></div>
    <h2 style="text-align:center;">Digital Receipt</h2>

    <p><strong>Placed Order At:</strong> <?= htmlspecialchars($createdAt) ?></p>
    <p><strong>Status:</strong> <span id="orderStatusSpan" style="color:orange;"><?= htmlspecialchars($order['status']) ?></span></p>
    <p><strong>Order #:</strong> <?= htmlspecialchars($order['id']) ?></p>
    <p><strong>Table #:</strong> <?= htmlspecialchars($order['table_number']) ?: '-' ?></p>
    <p><strong>Customer Name:</strong> <?= htmlspecialchars($order['customer_name']) ?></p>
    <p><strong>Payment Method:</strong> <?= htmlspecialchars($paymentLabel) ?></p>
    <p><strong>Payment Status:</strong> <span id="paymentStatusSpan" style="color:<?= $paymentStatus === 'Paid' ? '#388e3c' : '#d35400' ?>; font-weight:700;"><?= htmlspecialchars($paymentStatus) ?></span></p>
    
    <div class="items">
        <h3>Ordered Items</h3>
        <?php if (empty($items)): ?>
            <p>No items found.</p>
        <?php else: ?>
           <?php foreach($items as $item): ?>
<?php
  // Determine if this item is an "additional" (created after the order row)
  $is_additional_item = false;
  if (!empty($item['created_at']) && !empty($order['created_at'])) {
    $is_additional_item = (strtotime($item['created_at']) > strtotime($order['created_at']));
  }
  // We'll render the item name safely (html-escaped) and render a separate
  // green '(ADDITIONAL)' badge for additional items to avoid escaping HTML.
?>
<div class="item">
  <div class="item-details">
    <?php
      $safe_name = htmlspecialchars($item['item_name']);
      if ($is_additional_item) {
        echo '<strong><span class="additional-badge">(ADDITIONAL)</span> ' . $safe_name . '</strong><br>';
      } else {
        echo '<strong>' . $safe_name . '</strong><br>';
      }
    ?>
    ₱<?= number_format($item['price'],2) ?> × <?= (int)$item['quantity'] ?>  

    <?php if (!empty($item['serving_size'])): ?>
      <p style="color:purple;">Serving: <?= htmlspecialchars(ucfirst($item['serving_size'])) ?></p>
    <?php endif; ?>

    <p style="color:red;">
      Spice Level: <?= htmlspecialchars($spiceLabels[(int)($item['spice_level'] ?? 0)] ?? 'No Spice') ?>
    </p>

    <?php if (!empty($item['allergens'])): ?>
      <p style="color:darkblue;">Allergens: <?= htmlspecialchars($item['allergens']) ?></p>
    <?php endif; ?>

    <?php if (!empty(trim($item['allergen_note']))): ?>
      <p style="color:darkgreen;">Note: <?= htmlspecialchars($item['allergen_note']) ?></p>
    <?php endif; ?>
  </div>
  <div class="item-price">₱<?= number_format($item['total'],2) ?></div>
</div>
<?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="total">
          SUB TOTAL: ₱<?= number_format($order['total_price'],2) ?>
    </div>
    <p class="note">* This is not an official receipt *</p>

    <div class="buttons">
        <form action="order_status.php" method="get">
            <input type="hidden" name="order_number" value="<?= htmlspecialchars($orderGroupId) ?>">
            <button type="submit" class="btn btn-orange">
                <i class="fas fa-utensils"></i> Track Order
            </button>
        </form>
        
        <button 
          type="button" 
          class="btn btn-cancel" 
          id="triggerCancelModalBtn"
          <?= $isCancellable ? '' : 'disabled' ?>
          title="<?= $isCancellable ? 'Request Order Cancellation' : htmlspecialchars($cancelExplain) ?>"
        >
            <i class="fas fa-times-circle"></i> Cancel Order
        </button>

<button 
  type="button" 
  class="btn btn-white" 
  id="additionalOrderBtn" 
  aria-haspopup="dialog" 
  aria-controls="additionalOrderModal"
  <?= $allowAdditional ? '' : ('disabled style="opacity:.45;cursor:not-allowed;" title="'.htmlspecialchars($additionalTitle).'"') ?>
>
    <i class="fas fa-plus-circle"></i> Additional Order
</button>
<?php if(!$allowAdditional): ?>
    <div style="color:#d32f2f;margin:6px 0 0 0;font-size:1em;">
      <?= htmlspecialchars($additionalTitle) ?>
    </div>
<?php endif; ?>
    </div>
</div>
</div>

<div id="additionalOrderModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="additionalTitle" aria-describedby="additionalDesc">
  <div class="modal-content">
    <div class="icon">
      <svg viewBox="0 0 24 24" width="36" height="36" aria-hidden="true">
        <path d="M12 2L1 21h22L12 2zm0 14v2m0-8v4" stroke="#FF9800" stroke-width="3" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    </div>
    <p id="additionalDesc">
      Add more menu items to your existing order. The new items will be appended to this order.</strong>
    </p>
    <div style="display:flex; justify-content:center; gap:10px; margin-top:18px;">
      <button id="additionalOrderYes" class="btn" style="background:orange;color:#fff;">
        <i class="fas fa-plus"></i> Add Item(s)
      </button>
      <button id="additionalOrderNo" class="btn" style="background:#aaa;">
        <i class="fas fa-times"></i> Cancel
      </button>
    </div>
  </div>
</div>

<div id="cancelConfirmationModal" class="modal">
    <div class="modal-content">
        <div class="icon" style="background:#FFE0B2; color:#FF9800;">
            <i class="fas fa-exclamation-triangle fa-2x"></i>
        </div>
        <h2>Confirm Cancellation</h2>
        <p>Are you sure you want to cancel this order?</p>
        <button id="cancelOrderYes" class="btn btn-cancel" style="margin-right:10px;">
            <i class="fas fa-check"></i> Yes, Cancel
        </button>
        <button id="cancelOrderNo" class="btn btn-white">
            <i class="fas fa-times"></i> Do Not Cancel
        </button>
    </div>
</div>
<div id="cancelModalOverlay" aria-hidden="true">
  <div id="cancelModal" role="dialog" aria-modal="true" aria-labelledby="cancelTitle" aria-describedby="cancelMessage">
    <div class="cancel-body">
      <div class="cancel-headline" aria-hidden="true">
        <div class="icon" aria-hidden="true">
          <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#b71c1c" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true">
            <circle cx="12" cy="12" r="9"></circle>
            <path d="M8 8l8 8"></path>
          </svg>
        </div>
        <h2 id="cancelTitle">Order Cancelled</h2>
      </div>

      <div id="cancelMessage" class="cancel-message">
        We are sorry for the inconvenience. Please proceed to cashier if you did not cancel this order. Thanks for understanding.
      </div>

      <div class="cancel-action-wrap">
        <button id="btnOrderAgain" class="btn-order-again" type="button" aria-label="Order Again">
          <i class="fa-solid fa-house" aria-hidden="true"></i>
          <span>Order Again</span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Error message modal -->
<div id="errorModal" class="modal" tabindex="-1" style="display:none;">
  <div class="modal-content" style="max-width:420px;">
    <div class="icon" style="background:#ffebee;color:#d32f2f;">
      <i class="fa fa-exclamation-circle" style="font-size:38px;"></i>
    </div>
    <h2 style="color:#d32f2f;margin-top:10px;margin-bottom:14px;">Action Not Allowed</h2>
    <p id="errorModalMsg" style="font-size:1.08em;color:#434343;"></p>
    <button id="closeErrorModalBtn" class="btn btn-cancel" type="button" style="margin-top:18px;background:linear-gradient(135deg,#d32f2f,#e57373);">
      <i class="fa fa-times"></i> Close
    </button>
  </div>
</div>

<script>
  window.showCancelModal = window.showCancelModal || function(reason) {
  try {
    const overlay = document.getElementById('cancelModalOverlay');
    const msgEl = document.getElementById('cancelMessage');
    if (msgEl && typeof reason === 'string') msgEl.textContent = reason;
    if (overlay) {
      overlay.classList.add('show');
      overlay.setAttribute('aria-hidden', 'false');
      document.body.classList.add('modal-open');
    } else {
      if (reason) alert(reason);
    }
  } catch (e) {
    console.error('showCancelModal fallback error', e);
  }
};
document.addEventListener('DOMContentLoaded', function () {
  const orderAgainBtn = document.getElementById('btnOrderAgain');
  if (!orderAgainBtn) return;

  orderAgainBtn.addEventListener('click', function (e) {
    // Optional: close overlay before redirecting (not required)
    try {
      const overlay = document.getElementById('cancelModalOverlay');
      if (overlay) {
        overlay.classList.remove('show');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');
      }
    } catch (err) { /* ignore */ }

    // Redirect to kiosk_home (adjust path if kiosk_home.php is in a different folder)
    window.location.href = 'kiosk_home.php';
  });
});
window.closeCancelModal = window.closeCancelModal || function() {
  try {
    const overlay = document.getElementById('cancelModalOverlay');
    if (overlay) {
      overlay.classList.remove('show');
      overlay.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('modal-open');
    }
  } catch (e) {
    console.error('closeCancelModal fallback error', e);
  }
};
<?php if ($isCanceled): ?>
document.addEventListener('DOMContentLoaded', function() {
  if (typeof window.showCancelModal === 'function') {
    window.showCancelModal('Your order has been cancelled. Please proceed to cashier if you did not cancel this order. Thanks for understanding.');
  } else {
    console.warn('showCancelModal not available; skipping immediately showing overlay.');
  }
});
<?php endif; ?>

(function () {
  const ORDER_GROUP_ID = <?= json_encode($orderGroupId) ?>;
  const CANCEL_WINDOW_SECONDS = <?= json_encode($CANCEL_WINDOW_SECONDS) ?>;
  // Server-side timestamps to allow immediate enforcement on page load
  const SERVER_UPDATED_AT_UNIX = <?= json_encode($updatedAtUnix) ?>;
  const SERVER_NOW_UNIX = <?= json_encode($now) ?>;

  // UI refs
  let triggerCancelModalBtn;
  let cancelConfirmationModal;
  let cancelOrderYes;
  let cancelOrderNo;
  let additionalOrderBtn;
  let additionalOrderModal;
  let additionalOrderYes;
  let additionalOrderNo;
  let errorModal;
  let errorModalMsg;
  let closeErrorModalBtn;
  let cancelModalOverlay;
  let statusPollId = null;
  let cancelShown = false;
  let autoDisableTimer = null;
  let lastKnownStatus = (<?= json_encode($order['status'] ?? '') ?> || '').toLowerCase();

  function disableAdditionalOrder(reason) {
    if (!additionalOrderBtn) return;
    additionalOrderBtn.disabled = true;
    additionalOrderBtn.style.opacity = '0.45';
    additionalOrderBtn.style.cursor = 'not-allowed';
    additionalOrderBtn.setAttribute('title', reason || 'You cannot add items to this order.');
  }
  function enableAdditionalOrder() {
    if (!additionalOrderBtn) return;
    additionalOrderBtn.disabled = false;
    additionalOrderBtn.style.opacity = '';
    additionalOrderBtn.style.cursor = '';
    additionalOrderBtn.removeAttribute('title');
  }

  function updateStatusDisplay(newStatus, data) {
    const statusSpan = document.getElementById('orderStatusSpan');
    if (!statusSpan) return;
    statusSpan.textContent = newStatus || '';

    const low = (newStatus || '').toLowerCase();
    if (low.includes('cancel')) {
      statusSpan.style.color = 'red';
    } else if (low.includes('pending') || low.includes('not send')) {
      statusSpan.style.color = 'orange';
    } else if (low.includes('prepar') || low.includes('send to kitchen') || low.includes('to kitchen')) {
      statusSpan.style.color = 'orange';
    } else {
      statusSpan.style.color = 'green';
    }

    const cancelBtn = document.getElementById('triggerCancelModalBtn');
    if (!cancelBtn) return;

    // If server returned authoritative cancellable, use it
    if (data && typeof data.cancellable !== 'undefined') {
      if (data.cancellable) {
        cancelBtn.disabled = false;
        cancelBtn.title = 'Request Order Cancellation';
        cancelBtn.style.opacity = '';
        cancelBtn.style.cursor = '';
      } else {
        cancelBtn.disabled = true;
        cancelBtn.title = data.cancel_reason || 'Too late to cancel.';
        cancelBtn.style.opacity = '0.65';
        cancelBtn.style.cursor = 'not-allowed';
      }
    }

    // Additional order: prefer server-provided flag
    if (data && typeof data.allow_additional !== 'undefined') {
      if (!data.allow_additional) disableAdditionalOrder('Cannot add items to cancelled/closed orders.');
      else enableAdditionalOrder();
    }
  }

  // Cancel modal open/close helpers
  function openConfirmModal() {
    if (!cancelConfirmationModal) return;
    cancelConfirmationModal.style.display = 'flex';
    cancelConfirmationModal.setAttribute('aria-hidden','false');
    if (cancelModalOverlay) cancelModalOverlay.style.pointerEvents = 'none';
    document.body.classList.add('modal-open');
  }
  function closeConfirmModal() {
    if (!cancelConfirmationModal) return;
    cancelConfirmationModal.style.display = 'none';
    cancelConfirmationModal.setAttribute('aria-hidden','true');
    if (cancelModalOverlay) cancelModalOverlay.style.pointerEvents = '';
    document.body.classList.remove('modal-open');
  }

  // Attach handlers on DOM ready
  document.addEventListener('DOMContentLoaded', function () {
    triggerCancelModalBtn = document.getElementById('triggerCancelModalBtn');
    cancelConfirmationModal = document.getElementById('cancelConfirmationModal');
    cancelOrderYes = document.getElementById('cancelOrderYes');
    cancelOrderNo = document.getElementById('cancelOrderNo');
    additionalOrderBtn = document.getElementById('additionalOrderBtn');
    additionalOrderModal = document.getElementById('additionalOrderModal');
    additionalOrderYes = document.getElementById('additionalOrderYes');
    additionalOrderNo = document.getElementById('additionalOrderNo');
    errorModal = document.getElementById('errorModal');
    errorModalMsg = document.getElementById('errorModalMsg');
    closeErrorModalBtn = document.getElementById('closeErrorModalBtn');
    cancelModalOverlay = document.getElementById('cancelModalOverlay');

    if (triggerCancelModalBtn) {
      triggerCancelModalBtn.addEventListener('click', function () {
        if (this.disabled) {
          alert(this.title || 'Cannot cancel, order is being prepared.');
          return;
        }
        // Re-check server authoritatively before showing modal
        fetch('get_order_status.php?id=' + encodeURIComponent(ORDER_GROUP_ID), { cache: 'no-store' })
          .then(r => r.json().catch(()=>null))
          .then(data => {
            if (!data || data.status !== 'success') {
              alert('Unable to verify order status. Please try again.');
              return;
            }
            if (data.cancellable) {
              openConfirmModal();
            } else {
              // show reason in an error modal if provided
              if (data.cancel_reason) {
                if (errorModal && errorModalMsg) {
                  errorModalMsg.textContent = data.cancel_reason;
                  errorModal.style.display = 'flex';
                } else {
                  alert(data.cancel_reason);
                }
              } else {
                alert('Order can no longer be cancelled.');
              }
            }
          })
          .catch(err => {
            console.error('check before cancel failed', err);
            alert('Network error while verifying cancellation. Please try again.');
          });
      });
    }

    // Enforce initial cancel state on page load (handles case where Preparing window already expired)
    function enforceInitialCancelState() {
      try {
        const cancelBtn = document.getElementById('triggerCancelModalBtn');
        if (!cancelBtn) return;
        const st = (lastKnownStatus || '').toLowerCase();
        // Only relevant when order is preparing
        if (st.includes('prepar')) {
          const updated = Number(SERVER_UPDATED_AT_UNIX) || null;
          const serverNow = Number(SERVER_NOW_UNIX) || Math.floor(Date.now()/1000);
          if (updated) {
            const elapsed = serverNow - updated;
            if (elapsed >= Number(CANCEL_WINDOW_SECONDS)) {
              cancelBtn.disabled = true;
              cancelBtn.title = 'Too late: Preparing more than ' + CANCEL_WINDOW_SECONDS + ' seconds.';
              cancelBtn.style.opacity = '0.65';
              cancelBtn.style.cursor = 'not-allowed';
              // do not disable Additional Order here; Additional remains allowed unless the user cancelled or order is closed/paid
            } else {
              // schedule auto-disable for remaining time
              scheduleAutoDisableForPreparing(updated, lastKnownStatus, serverNow);
            }
          }
        }
      } catch (e) { console.error('enforceInitialCancelState error', e); }
    }

    if (cancelOrderNo) cancelOrderNo.addEventListener('click', closeConfirmModal);

    if (cancelOrderYes) {
      cancelOrderYes.addEventListener('click', function () {
        closeConfirmModal();
        if (triggerCancelModalBtn) triggerCancelModalBtn.disabled = true;

        fetch('summary_orders.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'order_group_id=' + encodeURIComponent(ORDER_GROUP_ID) + '&action=cancel_order'
        })
        .then(res => res.json().catch(()=>null))
        .then(data => {
          if (data && data.status === 'success') {
            // stop polling and clear timers
            if (statusPollId) { clearInterval(statusPollId); statusPollId = null; }
            if (autoDisableTimer) { clearTimeout(autoDisableTimer); autoDisableTimer = null; }

            // Update UI: use returned flags so additional-order is disabled too
            updateStatusDisplay(data.new_status, { cancellable: false, cancel_reason: data.cancel_reason || 'Already cancelled.', allow_additional: data.allow_additional === false ? false : false });

            // Explicitly disable additional order for clarity
            disableAdditionalOrder('Cannot add items to cancelled orders.');

            // Show staff cancel modal
            window.showCancelModal('Your cancellation request has been submitted. The order status has been updated. Please proceed to cashier if needed.');
          } else {
            const msg = data ? (data.message || 'Unknown error.') : 'No response from server.';
            if (errorModal && errorModalMsg) {
              errorModalMsg.textContent = 'Cancellation failed: ' + msg;
              errorModal.style.display = 'flex';
            } else {
              alert('Cancellation failed: ' + msg);
            }
            if (triggerCancelModalBtn) triggerCancelModalBtn.disabled = false;
          }
        })
        .catch(err => {
          console.error('Cancellation POST failed', err);
          alert('An unexpected error occurred during cancellation. Please reload or ask staff for help.');
          if (triggerCancelModalBtn) triggerCancelModalBtn.disabled = false;
        });
      });
    }

    // Additional order modal handlers
    if (additionalOrderBtn) {
      additionalOrderBtn.addEventListener('click', function () {
        if (additionalOrderModal) additionalOrderModal.style.display = 'flex';
      });
    }
    if (additionalOrderNo) additionalOrderNo.addEventListener('click', function () {
      if (additionalOrderModal) additionalOrderModal.style.display = 'none';
    });
    if (additionalOrderYes) {
      additionalOrderYes.addEventListener('click', function () {
        window.location.href = 'kiosk_home.php?add_to=' + encodeURIComponent(ORDER_GROUP_ID);
      });
    }

    // Error modal close helpers: wire up close button, click-outside, and ESC key
    function closeErrorModal() {
      try {
        if (!errorModal) return;
        errorModal.style.display = 'none';
        // clear any message text for next show
        if (errorModalMsg) errorModalMsg.textContent = '';
      } catch (e) { console.error('closeErrorModal error', e); }
    }

    if (closeErrorModalBtn) {
      closeErrorModalBtn.addEventListener('click', function () {
        closeErrorModal();
      });
    }

    // Allow clicking outside the modal-content to dismiss
    if (errorModal) {
      errorModal.addEventListener('click', function (ev) {
        if (ev.target === errorModal) closeErrorModal();
      });
    }

    // Allow Escape key to close error modal
    document.addEventListener('keydown', function (ev) {
      if ((ev.key === 'Escape' || ev.key === 'Esc') && errorModal && errorModal.style.display === 'flex') {
        closeErrorModal();
      }
    });

    // start polling and initial checks
    enforceInitialCancelState();
    startStatusPolling();
    checkCancelAutoDisable();
    setInterval(checkCancelAutoDisable, 2000);
  }); // DOMContentLoaded

  // Polling and server-driven disable logic
  function checkOrderStatusOnce() {
    if (!ORDER_GROUP_ID) return;
    fetch('get_order_status.php?id=' + encodeURIComponent(ORDER_GROUP_ID), { cache: 'no-store' })
      .then(r => r.json())
      .then(data => {
        if (!data || data.status !== 'success') return;
        const status = (data.order_status || '').trim();
        updateStatusDisplay(status, data);

        const low = (status || '').toLowerCase();

        // schedule auto-disable when preparing (idempotent)
        const updatedAtUnix = (typeof data.updated_at_unix !== 'undefined' && data.updated_at_unix) ? Number(data.updated_at_unix) : null;
        const serverNowUnix = (typeof data.server_now_unix !== 'undefined' && data.server_now_unix) ? Number(data.server_now_unix) : null;
        if (!lastKnownStatus.includes('prepar') && low.includes('prepar')) {
          scheduleAutoDisableForPreparing(updatedAtUnix, data.order_status, serverNowUnix);
        } else if (low.includes('prepar')) {
          scheduleAutoDisableForPreparing(updatedAtUnix, data.order_status, serverNowUnix);
        } else {
          if (autoDisableTimer) { clearTimeout(autoDisableTimer); autoDisableTimer = null; }
        }
        lastKnownStatus = low;

        // staff/system cancelled -> show overlay
        if (low.indexOf('cancel') !== -1 && !cancelShown) {
          cancelShown = true;
          const reason = (data.cancel_reason && typeof data.cancel_reason === 'string') ? data.cancel_reason : null;
          window.showCancelModal(reason);
          if (statusPollId) { clearInterval(statusPollId); statusPollId = null; }
        }
      })
      .catch(err => {
        console.error('status check failed', err);
      });
  }

  function startStatusPolling() {
    checkOrderStatusOnce();
    if (!statusPollId) statusPollId = setInterval(checkOrderStatusOnce, 2000);
  }

  function checkCancelAutoDisable() {
    if (!ORDER_GROUP_ID) return;
    const cancelBtn = document.getElementById('triggerCancelModalBtn');
    if (!cancelBtn) return;

    fetch('get_order_status.php?id=' + encodeURIComponent(ORDER_GROUP_ID), { cache: 'no-store' })
      .then(res => res.json())
      .then(data => {
        if (!data || data.status !== 'success') return;
        // Server authoritative flag
        if (typeof data.cancellable !== 'undefined') {
          if (!data.cancellable) {
            cancelBtn.disabled = true;
            cancelBtn.style.opacity = '0.65';
            cancelBtn.style.cursor = 'not-allowed';
            cancelBtn.title = data.cancel_reason || 'Too late to cancel';
            // Only disable Additional if server explicitly disallows it
            if (typeof data.allow_additional !== 'undefined' && !data.allow_additional) {
              disableAdditionalOrder('Cannot add items to cancelled/closed orders.');
            }
          } else {
            cancelBtn.disabled = false;
            cancelBtn.style.opacity = '';
            cancelBtn.style.cursor = '';
            cancelBtn.title = 'Request Order Cancellation';
            // If server allows additional, enable it
            if (typeof data.allow_additional !== 'undefined' && data.allow_additional) enableAdditionalOrder();
          }
          return;
        }
      })
      .catch(err => {
        console.error('checkCancelAutoDisable error', err);
      });
  }

  // schedule helper (idempotent)
  function scheduleAutoDisableForPreparing(updatedAtUnix, status, serverNowUnix) {
    if (autoDisableTimer) { clearTimeout(autoDisableTimer); autoDisableTimer = null; }
    if (!updatedAtUnix) return;
    const st = (status || '').toLowerCase();
    if (!st.includes('prepar')) return;

    let elapsed;
    if (typeof serverNowUnix !== 'undefined' && serverNowUnix !== null) {
      elapsed = Number(serverNowUnix) - Number(updatedAtUnix);
    } else {
      elapsed = Math.floor(Date.now()/1000) - Number(updatedAtUnix);
    }
    const remaining = Number(CANCEL_WINDOW_SECONDS) - elapsed;
    const cancelBtn = document.getElementById('triggerCancelModalBtn');
    if (!cancelBtn) return;
    if (remaining <= 0) {
      cancelBtn.disabled = true;
      cancelBtn.title = 'Too late: Preparing more than ' + CANCEL_WINDOW_SECONDS + ' seconds.';
      cancelBtn.style.opacity = '0.65';
      cancelBtn.style.cursor = 'not-allowed';
      return;
    }
    autoDisableTimer = setTimeout(() => {
      cancelBtn.disabled = true;
      cancelBtn.title = 'Too late: Preparing more than ' + CANCEL_WINDOW_SECONDS + ' seconds.';
      cancelBtn.style.opacity = '0.65';
      cancelBtn.style.cursor = 'not-allowed';
      autoDisableTimer = null;
    }, (remaining * 1000) + 100);
  }

  // Expose for legacy inline use
  window.updateStatusDisplay = updateStatusDisplay;
  window.disableAdditionalOrder = disableAdditionalOrder;
  window.enableAdditionalOrder = enableAdditionalOrder;

})(); // IIFE
</script>
</body>

</html>