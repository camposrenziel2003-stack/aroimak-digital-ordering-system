<?php
session_start();
include "config.php";
include "access_check.php";

// Cancel window (seconds) - reuse same constant as other pages
$CANCEL_WINDOW_SECONDS = 180;

// =================================================================
// AJAX POST Request Handler for Cancellation (mirror summary_orders.php)
// =================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_order') {
    header('Content-Type: application/json; charset=utf-8');

    $orderGroupId = $_POST['order_group_id'] ?? '';

    // Fetch status and authoritative timestamp
    $stmt = $conn->prepare(
        "SELECT status, updated_at, created_at, UNIX_TIMESTAMP(COALESCE(updated_at, created_at)) AS updated_unix, paid
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
        $newStatus = 'Canceled';
        $updateStmt = $conn->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE order_group_id = ?");
        $updateStmt->bind_param("ss", $newStatus, $orderGroupId);
        if ($updateStmt->execute()) {
            $_SESSION['user_canceled_order_' . $orderGroupId] = true;
            $allow_additional = false;
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

$orderGroupId = $_GET['order_number'] ?? '';
$error = '';
$orderData = null;
$orderStatuses = [];
$displayStatus = '';
$queueNo = '';
$isCancellable = false;
$cancelExplain = '';

if (!$orderGroupId) {
    $error = "Invalid order reference.";
} else {
    // Get order status timeline (might be used elsewhere)
    $stmt = $conn->prepare("SELECT status, updated_at, created_at FROM orders WHERE order_group_id = ? ORDER BY created_at ASC");
    $stmt->bind_param("s", $orderGroupId);
    $stmt->execute();
    $result = $stmt->get_result();
    $orderStatuses = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (!$orderStatuses) {
        $error = "Order not found.";
    } else {
        $latest = end($orderStatuses);
        $status = strtolower($latest['status']);
        $updatedAt = isset($latest['updated_at']) ? strtotime($latest['updated_at']) : time();
        $displayStatus = (preg_match('/^(completed|served)/i', $status)) ? "Serving Now" : $latest['status'];

        // Get main order details
        $stmt2 = $conn->prepare("SELECT id, customer_name, table_number, created_at, queue_number, payment_method, status, updated_at, paid FROM orders WHERE order_group_id = ? LIMIT 1");
        $stmt2->bind_param("s", $orderGroupId);
        $stmt2->execute();
        $res = $stmt2->get_result();
        $orderData = $res->fetch_assoc();
        $stmt2->close();

        if ($orderData) {
            $orderId = $orderData['id'];
            $customerName = $orderData['customer_name'];
            $tableNumber = $orderData['table_number'];
            $createdAt = date("F j, Y - g:iA", strtotime($orderData['created_at']));
            $paymentMethod = $orderData['payment_method'] ?? '';
            $queueNo = !empty($orderData['queue_number']) ? $orderData['queue_number'] : "-";
            $status = strtolower($orderData['status']);
            $updatedAt_dt = isset($orderData['updated_at']) ? strtotime($orderData['updated_at']) : time();
            $now = time();

            // New: Payment status boolean for UI
            $isPaid = (isset($orderData['paid']) && intval($orderData['paid']) === 1);

            // Cancel logic
            if ($status === 'pending' || $status === 'not send to kitchen') {
                $isCancellable = true;
                $cancelExplain = "";
            } elseif ($status === 'preparing') {
                $elapsed = $now - $updatedAt_dt;
                if ($elapsed < 180) {
                    $isCancellable = true;
                    $cancelExplain = "Can only cancel within 3 minutes of Preparing.";
                } else {
                    $isCancellable = false;
                    $cancelExplain = "Too late: Preparing more than 3 minutes.";
                }
            } else {
                $isCancellable = false;
                if ($status === 'canceled' || $status === 'cancelled') {
                    $cancelExplain = "Order already cancelled.";
                } else {
                    $cancelExplain = "Cannot cancel, order is being prepared or completed.";
                }
            }

            // Determine whether Additional Order is allowed
            // CHANGE: do not block 'completed'/'served' if unpaid — only block when paid or canceled/closed
            $lowStatus = strtolower($orderData['status'] ?? '');
            $allowAdditional = (!$isPaid) && !in_array($lowStatus, ['canceled', 'cancelled', 'closed']);
            $additionalTitle = $isPaid ? 'This order has already been paid; you cannot add items.' : 'Add items to this unpaid order';

            // Only calculate queue number if active
            $shouldHideQueue = (
                strpos($status, 'canceled') !== false ||
                strpos($status, 'cancelled') !== false ||
                strpos($status, 'completed') !== false ||
                strpos($status, 'served') !== false
            );
            if ($queueNo === "-" && !$shouldHideQueue) {
                $today = date('Y-m-d');
                $stmt3 = $conn->prepare(
                    "SELECT COUNT(*) AS ahead_in_queue
                     FROM orders
                     WHERE DATE(created_at) = ?
                     AND created_at < ?
                     AND LOWER(status) NOT LIKE '%completed%'
                     AND LOWER(status) NOT LIKE '%served%'
                     AND LOWER(status) NOT LIKE '%canceled%'
                     AND LOWER(status) NOT LIKE '%cancelled%'"
                );
                $stmt3->bind_param("ss", $today, $orderData['created_at']);
                $stmt3->execute();
                $priorRes = $stmt3->get_result();
                $aheadCount = $priorRes ? $priorRes->fetch_assoc()['ahead_in_queue'] : 0;
                $stmt3->close();

                $currentQueuePosition = $aheadCount + 1;
                $queueNo = "{$currentQueuePosition}";
            }
        } else {
            $error = "Order details not found.";
        }
    }
}

$isCanceled = !empty($orderData) && in_array(strtolower(trim($orderData['status'])), ['canceled', 'cancelled']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<link rel="stylesheet" href="images/main-bg.png">
<meta charset="UTF-8">
<title>Order Update</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
body, html {
    margin: 0;
    padding: 0;
    width: 100%;
    height: 100%;
    font-family: 'Segoe UI', Tahoma, sans-serif;
    background: linear-gradient(135deg, #FF8A65, #FFD180, #FF7043);
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
}
body::before {
    content: "";
    position: absolute;
    top: 0; left: 0;
    width: 100%; height: 100%;
    background: url('images/main-bg.png') no-repeat center center;
    background-size: cover;
    opacity: 0.15;
    pointer-events: none;
    z-index: 0;
}
.container {
    position: relative;
    z-index: 1;
    width: 95%;
    max-width: 600px;
    display: flex;
    flex-direction: column;
    gap: 20px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
}
.card {
    background: white;
    border-radius: 15px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
    padding: 25px 30px;
    display: flex;
    flex-direction: column;
    align-items: center;
}
.header img {
    height: 80px;
    max-width: 100%;
    object-fit: contain;
    margin-bottom: 10px;
}
.header h1 {
    font-size: clamp(22px, 5vw, 36px);
    font-weight: bold;
    color: #FF5722;
    margin: 0 0 15px 0;
}
.order-info {
    font-size: clamp(18px, 4vw, 26px);
    font-weight: bold;
    margin-bottom: 10px;
    text-align: center;
}
.details {
    font-size: clamp(14px, 3.5vw, 20px);
    color: #555;
    line-height: 1.8;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
}
.details span { margin: 3px 0; }
.status-badge {
    padding: 14px 28px;
    border-radius: 35px;
    font-size: clamp(16px, 4vw, 24px);
    font-weight: 700;
    color: #fff;
    margin-top: 15px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    transition: all 0.3s ease;
    display: inline-block;
}
.status-pending { background: #ffde7bff; color: #333; }
.status-preparing { background: #2196F3; }
.status-serving { background: #4CAF50; }
.status-canceled { background: #F44336; }
.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    justify-content: center;
    margin-top: 10px;
}
.btn {
    flex: 1 1 100%;
    max-width: 350px;
    display: flex;
    align-items: center;
    gap: 12px;
    border: 2px solid #FF5722;
    border-radius: 15px;
    padding: 14px;
    font-size: clamp(14px, 3.5vw, 18px);
    font-weight: 600;
    background: white;
    color: #FF5722;
    cursor: pointer;
    justify-content: center;
    transition: all 0.2s ease-in-out;
}
.btn i { font-size: 18px; }
.btn:hover { background: #FF5722; color: white; }
@media (min-width: 600px) {
    .actions { flex-direction: row; }
    .btn { flex: 1 1 auto; }
}
/* General modal overlay styles for all modals, including cancelModal */
.modal-overlay {
    display: none; /* Hidden by default */
    position: fixed;
    top: 0; left: 0;
    width: 100vw; height: 100vh;
    background: rgba(38,36,36,0.44);
    justify-content: center;
    align-items: center;
    z-index: 10000;
    transition: background 0.2s;
}
.modal-overlay.active {
    display: flex !important;
    animation: modalFadeIn 0.22s;
}
@keyframes modalFadeIn {
    from { opacity:0; }
    to { opacity:1; }
}
.modal-content {
    background: #fffbe9;
    border-radius: 20px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.18);
    text-align: center;
    max-width: 420px;
    width: 95vw;
    position: relative;
    animation: modalPopIn 0.18s;
    padding: 32px 20px 28px 20px;
    margin: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}
@keyframes modalPopIn {
    from { opacity:0; transform: translateY(20px) scale(0.96);}
    to { opacity:1; transform: translateY(0) scale(1);}
}
.close-btn {
    position: absolute;
    top: 14px;
    right: 16px;
    background: #FF5722;
    color: #fff;
    border: none;
    border-radius: 7px;
    padding: 6px 16px;
    font-weight: bold;
    cursor: pointer;
    font-size: 15px;
    box-shadow: 0 2px 12px rgba(255,87,34,0.09);
    transition: background 0.15s, transform 0.15s;
    z-index: 80;
}
.close-btn:hover {
    background: #FF9800;
    transform: scale(1.06);
}

/* Cancel-specific styles (colors etc) */
#cancelModal .modal-content {
    background: linear-gradient(135deg, #fff8f8, #ffecec);
    border-radius: 16px;
    box-shadow: 0 12px 40px rgba(0,0,0,0.26);
    max-width: 400px;
    width: 90vw;
    padding: 32px 22px;
    text-align: center;
    display: flex;
    flex-direction: column;
    align-items: center;
}
#cancelModal h2 {
    margin: 12px 0 14px 0;
    color: #b71c1c;
    font-size: 1.33rem;
    font-weight: 800;
    letter-spacing: 0.03em;
    text-shadow: 0 2px 8px #d32f2f22;
}
#cancelMessage {
    margin: 8px 0 18px 0;
    color: #333;
    font-size: 1.12rem;
    word-break: break-word;
    line-height: 1.57;
}
.cancel-actions {
    display: flex;
    gap: 18px;
    justify-content: center;
    margin-top: 10px;
    flex-wrap: wrap;
}
.cancel-actions .btn-home {
    background: linear-gradient(135deg, #FF9800 80%, #FF5722 100%);
    color: #fff;
    border: none;
    border-radius: 13px;
    font-weight: bold;
    font-size: 1rem;
    padding: 12px 25px;
    cursor: pointer;
    transition: background 0.18s, color 0.18s, transform 0.16s;
    box-shadow: 0 2px 12px rgba(255,87,34,0.09);
    outline: none;
}
.cancel-actions .btn-home:hover, .cancel-actions .btn-home:focus {
    background: linear-gradient(135deg, #FF5722 80%, #FF9800 100%);
    transform: scale(1.04);
}
@media (max-width: 500px) {
    #cancelModal .modal-content {
        max-width: 98vw;
        padding: 18px 7vw 16px 7vw;
        border-radius: 12px;
    }
    .cancel-actions .btn-home {
        font-size: 0.98rem;
        padding: 9px 6vw;
    }
}
#cancelConfirmationModal .modal-content {
    background: linear-gradient(180deg, #fff8f8 0%, #fff4f4 100%);
    border-radius: 14px;
    box-shadow: 0 14px 40px rgba(0,0,0,0.12);
    max-width: 480px;
    width: 90vw;
    padding: 32px 34px;
    text-align: center;
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    animation: cancelIn 0.28s;
    overflow: hidden;
}

#cancelConfirmationModal .icon {
    width: 36px;
    height: 36px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #FF9800;
    background: #FFE0B2;
    border-radius: 50%;
    font-size: 2em;
    margin: 0 auto 12px auto;
}

#cancelConfirmationModal h2 {
    margin: 0 0 18px 0;
    font-size: 1.8rem;
    font-weight: 800;
    color: #b71c1c;
    letter-spacing: -0.02em;
}

#cancelConfirmationModal p,
#cancelConfirmationModal .message,
#cancelConfirmationModal > div {
    font-size: 1.5rem;
    color: #433333;
    margin-bottom: 18px;
    margin-top: 3px;
    font-weight: 500;
    letter-spacing: 0.01em;
}

#cancelConfirmationModal .modal-content > div:last-child,
#cancelConfirmationModal .actions {
    margin-top: 22px;
    display: flex;
    gap: 16px;
    justify-content: center;
    flex-wrap: wrap;
}

/* Button styles adopted from summary file */
#cancelConfirmationModal .btn {
    padding: 12px 24px;
    border-radius: 14px;
    font-weight: 700;
    font-size: 1rem;
    border: none;
    transition: background 0.18s, color 0.18s, transform 0.16s;
    box-shadow: 0 8px 18px rgba(244,67,54,0.22), 0 2px 10px rgba(229,115,115,0.12);
    cursor: pointer;
    outline: none;
}
#cancelConfirmationModal .btn-cancel {
    background: linear-gradient(135deg, #f44336, #e57373);
    color: #fff;
}
#cancelConfirmationModal .btn-cancel:hover:not(:disabled), #cancelConfirmationModal .btn-cancel:focus {
    background: linear-gradient(135deg, #e57373, #f44336);
    transform: scale(1.08);
}
#cancelConfirmationModal .btn-white {
    background: #fff;
    color: #FF9800;
    border: 2px solid #FFD54F;
}
#cancelConfirmationModal .btn-white:hover:not(:disabled),
#cancelConfirmationModal .btn-white:focus {
    background: linear-gradient(135deg, #FFD54F, #FF9800);
    color: #fff;
    transform: scale(1.08);
}

/* Responsive: Modal enhancement for small screens */
@media (max-width: 500px) {
    #cancelConfirmationModal .modal-content {
        max-width: 97vw;
        padding: 16px 2vw 16px 2vw;
        border-radius: 11px;
    }
    #cancelConfirmationModal .btn {
        font-size: 0.96em;
        padding: 10px 5vw;
    }
    #cancelConfirmationModal h2 {
        font-size: 1.1rem;
    }
    #cancelConfirmationModal .icon {
        font-size: 1.4em;
    }
}

@keyframes cancelIn {
  from { transform: translateY(8px) scale(.995); opacity: 0; }
  to  { transform: translateY(0) scale(1); opacity: 1; }
}

/* ======= Popups ======= */
#popupModal { 
    display: none; 
    position: fixed; 
    top: 50%; 
    left: 50%; 
    transform: translate(-50%, -50%);
    background: linear-gradient(135deg, #fffbe9 80%, #ffe4c2 100%);
    padding: 30px 40px; 
    border-radius: 15px; 
    box-shadow: 0 8px 25px rgba(0,0,0,0.35);
    text-align: center; 
    z-index: 10000; 
    max-width: 90%; 
    width: 400px; 
    animation: fadeIn 0.3s ease-out; 
}

#popupModal button {
    padding: 12px 24px;                  /* slightly larger for better clickability */
    border: none;
    border-radius: 12px;
    background: #FF5722;
    color: white;
    cursor: pointer;
    font-weight: bold;
    font-size: 1rem;
    transition: all 0.25s ease-in-out;   /* smooth transition for hover */
    box-shadow: 0 4px 12px rgba(0,0,0,0.2); /* subtle shadow */
}

#popupModal button:hover {
    background: #FF7043;                 /* slightly lighter/warmer shade */
    transform: scale(1.05);              /* subtle scale-up effect */
    box-shadow: 0 6px 20px rgba(0,0,0,0.3); /* deeper shadow for pop */
}

#popupIcon {
    font-size: 48px;                     /* slightly larger for emphasis */
    margin-bottom: 15px;
    color: #FF5722;
}

#popupMessage {
    font-size: 18px;
    font-weight: 600;
    margin-bottom: 20px;
    color: #333;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translate(-50%, -45%);
    }
    to {
        opacity: 1;
        transform: translate(-50%, -50%);
    }
}


/* ======= Feedback Modal ======= */
#feedbackModal {
    display: none;
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%) scale(0.9);
    width: 90%;
    max-width: 450px;
    background: linear-gradient(135deg, #FFB74D, #FF7043);
    padding: 30px 25px;
    border-radius: 20px;
    box-shadow: 0 12px 40px rgba(0,0,0,0.4);
    flex-direction: column;
    align-items: center;
    text-align: center;
    z-index: 1000;
    animation: modalIn 0.3s forwards;
}
@keyframes modalIn { 0% { opacity: 0; transform: translate(-50%, -50%) scale(0.8); } 100% { opacity: 1; transform: translate(-50%, -50%) scale(1); } }
.feedback-container { width: 100%; display: flex; flex-direction: column; align-items: center; }
.feedback-header { font-size: 1.5rem; font-weight: 700; color: #FFFDE7; margin-bottom: 20px; text-shadow: 0 1px 3px rgba(0,0,0,0.3); }
.feedback-stars { display: flex; gap: 12px; justify-content: center; margin-bottom: 15px; }
.star { font-size: 2.5rem; color: rgba(255,255,255,0.7); cursor: pointer; text-shadow: 0 1px 3px rgba(0,0,0,0.2); transition: transform 0.2s, color 0.2s; }
.star.active { color: #FFD600; transform: scale(1.3); text-shadow: 0 2px 5px rgba(0,0,0,0.5); }
.feedback-emojis { display: flex; gap: 15px; justify-content: space-between; width: 100%; padding: 0 15px; margin-bottom: 20px; }
.emoji { font-size: 2rem; cursor: pointer; opacity: 0.6; transition: transform 0.3s, opacity 0.3s; }
.emoji.active { opacity: 1; transform: scale(1.4) rotate(-10deg); }
#feedbackComment { width: 100%; min-height: 80px; padding: 12px; border-radius: 12px; resize: vertical; font-size: 16px; background: rgba(255, 255, 255, 0.95); box-shadow: inset 0 1px 4px rgba(0,0,0,0.15); border: none; margin-bottom: 15px; }
.feedback-buttons { display: flex; justify-content: space-between; width: 100%; gap: 12px; }
.feedback-buttons button { flex: 1; padding: 14px 0; border-radius: 12px; font-weight: 700; font-size: 1rem; cursor: pointer; border: none; transition: transform 0.2s, background 0.2s; }
.feedback-buttons button:first-child { background: #FFEB3B; color: #FF5722; }
.feedback-buttons button:first-child:hover { background: #FFD600; transform: scale(1.05); }
.feedback-buttons button:last-child { background: #FFF; color: #FF5722; }
.feedback-buttons button:last-child:hover { background: #FFE0B2; transform: scale(1.05); }

/* ======= Enhanced Feedback Popup ======= */
#feedbackPopup { display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%) scale(0.8); background: linear-gradient(135deg, #FF7043, #FFB74D); color: white; padding: 30px 25px; border-radius: 20px; box-shadow: 0 12px 40px rgba(0,0,0,0.4); text-align: center; z-index: 10000; max-width: 400px; width: 90%; font-family: 'Arial', sans-serif; animation: popupIn 0.4s forwards; }
#feedbackPopup .emoji { font-size: 3rem; margin-bottom: 15px; }
#feedbackPopup .message { font-size: 1.2rem; font-weight: 700; margin-bottom: 20px; text-shadow: 0 1px 3px rgba(0,0,0,0.3); }
#feedbackPopup button { padding: 12px 25px; font-weight: bold; font-size: 1rem; border: none; border-radius: 12px; background: #FFFDE7; color: #FF5722; cursor: pointer; transition: transform 0.2s, background 0.2s; }
#feedbackPopup button:hover { background: #FFD600; transform: scale(1.05); }
@keyframes popupIn { 0% { opacity: 0; transform: translate(-50%, -50%) scale(0.7); } 100% { opacity: 1; transform: translate(-50%, -50%) scale(1); } }
@keyframes popupOut { 0% { opacity: 1; transform: translate(-50%, -50%) scale(1); } 100% { opacity: 0; transform: translate(-50%, -50%) scale(0.7); } }

#summaryModal {
    display: none;
    position: fixed;
    top: 0; left: 0;
    width: 100vw; height: 100vh;
    background: rgba(38, 36, 36, 0.45);
    z-index: 20000;
    justify-content: center;
    align-items: center;
    scrollbar-width: thin;
  scrollbar-color: #fff #fff;
}
#summaryContent {
    background: #fff;
    max-width: 600px;
    width: 100vw;
    max-height: 100vh;
    min-height: 220px;
    border-radius: 25px;
    box-shadow: 0 10px 32px rgba(0,0,0,0.18);
    padding: 0;
    position: relative;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    align-items: stretch;
}
#summaryContent button {
    position: absolute;
    top: 16px;
    right: 18px;
    background: #FF5722;
    color: #fff;
    border: none;
    border-radius: 7px;
    padding: 7px 18px;
    font-weight: bold;
    cursor: pointer;
    font-size: 16px;
    box-shadow: 0 2px 12px rgba(255,87,34,0.09);
    transition: background 0.15s, transform 0.15s;
    z-index: 80;
}
#summaryContent button:hover {
    background: #FF9800;
    transform: scale(1.06);
}
#summaryInner {
    width: 100%;
    height: 100%;
    overflow-y: auto;
    margin: 0;
    padding: 26px 18px 18px 18px;
    box-sizing: border-box;
}
#summaryInner .container {
    margin: 0 !important;
    box-shadow: none !important;
    border-radius: 0 !important;
    padding: 0 !important;
    background: none !important;
}
#summaryInner .header {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    margin-bottom: 3px;
}
#summaryInner .header img {
    height: 40px !important;
    margin-right: 0 !important;
    margin-bottom: 0 !important;
}
#summaryInner .header h1 {
    font-size: 1.35em;
    color: #E64A19;
    font-weight: bold;
    margin: 0;
    letter-spacing: 0.02em;
}
#summaryInner .divider {
    border-bottom: 2px solid #FF9800;
    margin: 6px 0 10px 0;
}
#summaryInner h2,
#summaryInner h3 {
    color: #FF9800;
    margin-bottom: 8px;
    margin-top: 0;
    text-align: center;
    font-weight: 700;
    letter-spacing: 0.03em;
}
#summaryInner p {
    font-size: 15px;
    color: #201606ff;
    margin: 2px 0 2px 0;
    /* You can also add line-height: 1.4; for better vertical fit */
    line-height: 1.4;
}
#summaryInner .items {
    margin-top: 5px;
}
#summaryInner .item {
    background: #fffde6;
    border-radius: 9px;
    box-shadow: 0 1px 6px rgba(255, 152, 0, 0.06);
    margin-bottom: 8px;
    padding: 8px 10px;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    border-bottom: 1px solid #ffe0b2;
}
#summaryInner .item-details {
    flex: 1 1 70%;
    padding-right: 6px;
    font-size: 14px;
    color: #6d4c41;
    line-height: 1.5;
}
#summaryInner .item-price {
    flex: 0 0 75px;
    text-align: right;
    font-weight: bold;
    color: #E64A19;
    font-size: 15px;
    letter-spacing: 0.02em;
    margin-top: 2px;
}
#summaryInner .total {
    text-align:right;
    font-weight:bold;
    margin:14px 0 6px 0;
    font-size:18px;
    color: #E64A19;
}
#summaryInner .note {
    color: #B71C1C;
    font-size: 13px;
    text-align:center;
    margin-top:8px;
}
#summaryInner strong {
    color: #FF9800;
    font-weight: 600;
}
#summaryInner .buttons {
    display: none !important;
}
@media (max-width: 700px) {
    #summaryContent {
        max-width: 99vw;
        width: 99vw;
        max-height: 85vh;
        border-radius: 16px;
    }
    #summaryContent button {
        top: 8px;
        right: 10px;
        padding: 7px 13px;
        font-size: 15px;
    }
    #summaryInner {
        padding: 13px 4vw 10px 4vw;
    }
}

/* ======= Order Again Confirmation Modal ======= */
#orderAgainModal {
    display: none;
    position: fixed;
    top: 0; left: 0;
    width: 100vw; height: 100vh;
    z-index: 10001;
    background: rgba(38,36,36,0.48);
    justify-content: center;
    align-items: center;
    transition: background 0.2s;
}
#orderAgainModal.active {
    display: flex;
    animation: fadeInOrderAgain 0.25s;
}
@keyframes fadeInOrderAgain {
    from { background: rgba(38,36,36,0.0); }
    to { background: rgba(38,36,36,0.48); }
}
.order-again-modal-content {
    background: linear-gradient(135deg, #fffbe9 80%, #ffe4c2 100%);
    border-radius: 20px;
    padding: 38px 32px 32px 32px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.18);
    text-align: center;
    max-width: 400px;
    width: 90vw;
    position: relative;
    animation: modalPopIn 0.28s;
}
@keyframes modalPopIn {
    from { opacity: 0; transform: translateY(40px) scale(0.96);}
    to { opacity: 1; transform: translateY(0) scale(1);}
}
.order-again-modal-message {
    font-size: 1.1rem;
    color: #333;
    margin-bottom: 28px;
    font-weight: 500;
}
.order-again-modal-message span {
    font-size: 0.98em;
    color: #666;
    display: block;
    margin-top: 7px;
    font-weight: 400;
    letter-spacing: 0.01em;
}
.order-again-modal-actions {
    display: flex;
    gap: 14px;
    justify-content: center;
    margin-top: 6px;
}
.order-again-btn {
    padding: 12px 28px;
    border-radius: 13px;
    border: none;
    font-weight: bold;
    font-size: 1rem;
    cursor: pointer;
    transition: background 0.18s, color 0.18s, transform 0.16s;
    box-shadow: 0 2px 12px rgba(255,87,34,0.09);
    outline: none;
}
.order-again-yes {
    background: linear-gradient(135deg, #FF5722 70%, #FF9800 100%);
    color: #fff;
}
.order-again-yes:hover, .order-again-yes:focus {
    background: linear-gradient(135deg, #FF9800 70%, #FF5722 100%);
    filter: brightness(1.1);
    transform: scale(1.04);
    outline: none;
}
.order-again-cancel {
    background: #FFE0B2;
    color: #FF5722;
}
.order-again-cancel:hover, .order-again-cancel:focus {
    background: #FFD180;
    color: #E64A19;
    transform: scale(1.04);
    outline: none;
}
@media (max-width: 450px) {
    .order-again-modal-content {
        max-width: 98vw;
        padding: 24px 6vw 21px 6vw;
        border-radius: 13px;
    }
    .order-again-btn {
        font-size: 0.95rem;
        padding: 11px 5vw;
    }
}
 /* ======= CANCEL MODAL (Overlay) ======= */
/* CANCEL MODAL fixed for overlay and stacking */
#cancelModal {
    display: none;
    position: fixed;
    left: 0; top: 0;
    width: 100vw; height: 100vh;
    background: rgba(38,36,36,0.6);
    z-index: 10000 !important;
    justify-content: center;
    align-items: center;
    padding: 0;
}

.cancel-modal-content {
    background: linear-gradient(135deg, #fff8f8, #ffecec);
    border-radius: 16px;
    box-shadow: 0 12px 40px rgba(0,0,0,0.26);
    max-width: 400px;
    width: 90vw;
    padding: 32px 22px;
    text-align: center;
    animation: cancelIn 0.28s;
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
}
#cancelModal h2 {
    margin: 12px 0 14px 0;
    color: #b71c1c;
    font-size: 1.33rem;
    font-weight: 800;
    letter-spacing: 0.03em;
    text-shadow: 0 2px 8px #d32f2f22;
}
#cancelMessage {
    margin: 8px 0 18px 0;
    color: #333;
    font-size: 1.12rem;
    word-break: break-word;
    line-height: 1.57;
}
.cancel-actions {
    display: flex;
    gap: 18px;
    justify-content: center;
    margin-top: 10px;
    flex-wrap: wrap;
}
.cancel-actions .btn-home {
    background: linear-gradient(135deg, #FF9800 80%, #FF5722 100%);
    color: #fff;
}
.cancel-actions .btn-home:hover, .cancel-actions .btn-home:focus {
    background: linear-gradient(135deg, #FF5722 80%, #FF9800 100%);
    transform: scale(1.04);
}
/* Make sure CancelModal always shows above everything! */
#cancelModal[style*="display: flex"] {
    display: flex !important;
    z-index: 10000 !important;
}
@keyframes cancelIn {
    from { opacity: 0; transform: translateY(18px) scale(0.96); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}
@media (max-width: 500px) {
    .cancel-modal-content {
        max-width: 98vw;
        padding: 18px 7vw 16px 7vw;
        border-radius: 12px;
    }
    .cancel-actions .btn-home {
        font-size: 0.98rem;
        padding: 9px 6vw;
    }
}

/* ======= MINI GAMES BUTTON STYLES ======= */
.games-section-title {
    text-align: center;
    font-size: 2.2rem;
    font-weight: 700;
    color: #ffffffff;
    margin-top: 0;
    margin-bottom: 22px;
    letter-spacing: 0.01em;
    font-family: inherit;
}
.games-section-row {
    display: flex;
    flex-direction: row;
    justify-content: center;
    gap: 28px;
    margin-bottom: 12px;
}
.game-btn-modern {
    background: #fff;
    color: #FF6700;
    border: 2px solid #FF6700;
    border-radius: 20px;
    font-size: 1rem;
    font-weight: 600;
    padding: 10px 0 8px 0;
    width: 120px;
    min-width: 0;
    min-height: 60px;
    max-width: 140px;
    text-align: center;
    transition: background 0.16s, color 0.16s, box-shadow 0.16s;
    outline: none;
    box-shadow: none;
    cursor: pointer;
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    margin: 0 4px;
}
.game-btn-modern i {
    font-size: 1.3em;
    margin-bottom: 3px;
    color: #FF6700;
    transition: color 0.16s;
}
.game-btn-modern:hover, .game-btn-modern:focus {
    background: #FF6700;
    color: #fff;
    box-shadow: 0 2px 10px rgba(255,103,0,0.10);
}
.game-btn-modern:hover i, .game-btn-modern:focus i {
    color: #fff;
}
.games-section-row {
    display: flex;
    flex-direction: row;
    justify-content: center;
    gap: 12px;
    margin-bottom: 12px;
}
@media (max-width: 750px) {
    .games-section-row {
        flex-direction: column;
        align-items: center;
        gap: 12px;
    }
    .game-btn-modern {
        width: 70vw;
        max-width: 210px;
    }
}
.mini-game-modal {
    display: none;
    position: fixed;
    top: 0; left: 0;
    width: 100vw; height: 100vh;
    z-index: 9999;
    background: rgba(38,36,36,0.44);
    justify-content: center;
    align-items: center;
    transition: background 0.2s;
}
.mini-game-modal[style*="display: flex"] {
    display: flex !important;
}

.mini-game-modal-content {
    background: #fffbe9;
    border-radius: 20px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.18);
    text-align: center;
    max-width: 420px;
    width: 95vw;
    position: relative;
    animation: modalPopIn 0.18s;
    padding: 32px 20px 28px 20px;
    margin: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}
@keyframes modalPopIn {
    from { opacity: 0; transform: translateY(20px) scale(0.96);}
    to { opacity: 1; transform: translateY(0) scale(1);}
}
.mini-game-modal-content .close-btn {
    position: absolute;
    top: 14px;
    right: 16px;
    background: #FF5722;
    color: #fff;
    border: none;
    border-radius: 7px;
    padding: 6px 16px;
    font-weight: bold;
    cursor: pointer;
    font-size: 15px;
    box-shadow: 0 2px 12px rgba(255,87,34,0.09);
    transition: background 0.15s, transform 0.15s;
    z-index: 80;
}
.mini-game-modal-content .close-btn:hover {
    background: #FF9800;
    transform: scale(1.06);
}
@media (max-width: 650px) {
    .mini-game-modal-content {
        max-width: 98vw;
        padding: 20px 3vw 18px 3vw;
        border-radius: 13px;
    }
}
.mini-game-modal-content .close-btn {
    font-size: 14px;
    padding: 6px 10px;
}
.order-info {
    font-size: clamp(18px, 4vw, 26px);
    font-weight: bold;
    margin-bottom: 10px;
    text-align: center;
    display: flex;
    flex-wrap: wrap;
    gap: 18px;
    align-items: center;
    justify-content: center;
}
.order-info .order-id {
    background: #FFE0B2;
    padding: 7px 17px;
    border-radius: 16px;
    color: #FF8A65;
    font-size: 0.98em;
    font-weight: 700;
    margin: 0 2px;
}
.order-info .queue-no {
    background: #FFF9C4;
    padding: 7px 17px;
    border-radius: 16px;
    color: #2979FF;
    font-size: 0.97em;
    font-weight: 700;
    margin: 0 2px;
    border: 1.5px solid #FFD54F;
    letter-spacing: 0.04em;
}
@media (max-width:450px) {
    .order-info {
        flex-direction: column;
        gap: 6px;
        font-size: 1em;
    }
    .order-info .order-id, .order-info .queue-no {
        padding: 5px 11px;
        font-size: 0.95em;
    }
}
</style>
</head>
<body>

<script>
    function fetchStatus() {
  if (!ORDER_GROUP_ID) return;

  fetch("get_order_status.php?id=" + encodeURIComponent(ORDER_GROUP_ID), { cache: "no-store" })
    .then(res => res.json())
    .then(data => {
      if (!data || data.status !== "success") return;

      let statusText = (data.order_status || '').trim();
      let paidFlag = parseInt(data.paid || 0);
      let display = statusText;
      const low = statusText.toLowerCase();
      if (low === "completed" || low === "served") display = "Serving Now";

      // update status badge
      updateBadge(display, statusText);

          
      // update payment UI
      const paySpan = document.getElementById('paymentStatusSpan');
      const addBtn = document.getElementById('additionalOrderBtn');
      if (paySpan) {
        if (paidFlag === 1) {
          paySpan.textContent = 'Paid';
          paySpan.style.color = '#388e3c';
        } else {
          paySpan.textContent = 'Unpaid';
          paySpan.style.color = '#d35400';
        }
      }

      // enable/disable Additional Order button based on paid + terminal states
      const lowStatus = (data.order_status || '').toLowerCase();
      const disallowed = (paidFlag === 1) || ['canceled','cancelled','closed','completed','served'].includes(lowStatus);
      if (addBtn) {
        if (disallowed) {
          addBtn.disabled = true;
          addBtn.style.opacity = ".45";
          addBtn.style.cursor = "not-allowed";
          addBtn.title = paidFlag === 1 ? 'This order has already been paid; you cannot add items.' : 'You can only add items to active, unpaid orders.';
        } else {
          addBtn.disabled = false;
          addBtn.style.opacity = "";
          addBtn.style.cursor = "";
          addBtn.title = 'Add items to this unpaid order';
        }
      }

      // Show "Order Again" button when both Cancel and Add New Order are disabled (paid + completed/served)
      if (paidFlag === 1 && (lowStatus === 'completed' || lowStatus === 'served')) {
        const orderAgainBtn = document.getElementById('orderAgainBtn');
        if (orderAgainBtn) {
          orderAgainBtn.style.display = 'flex';
        }
      }

      // Show feedback modal for "Serving Now" once
      if (display === "Serving Now" && !servingNowDetected && !localStorage.getItem("feedback_given_" + ORDER_GROUP_ID)) {
        servingNowDetected = true;
        setTimeout(() => {
          if (!feedbackModalShown && !localStorage.getItem("feedback_given_" + ORDER_GROUP_ID)) {
            if (typeof showFeedbackModal === "function") showFeedbackModal();
            feedbackModalShown = true;
          }
        }, 8000);
      }

      // Detect cancellation by checking if status contains "cancel"
      const normalized = low.trim();
      if ((normalized === 'canceled' || normalized === 'cancelled') && !cancelDetected) {
        cancelDetected = true;
        if (statusIntervalId) {
          clearInterval(statusIntervalId);
          statusIntervalId = null;
        }
        const reason = (data.cancel_reason && typeof data.cancel_reason === 'string') ? data.cancel_reason : null;
        showCancelModal(reason);
      }
    })
    .catch(err => {
      console.error("fetchStatus error:", err);
    });
}
let userRating = 0;
let feedbackModalShown = false;
let servingNowDetected = false;
let orderAgainButtonShownTime = null; // Track when Order Again button was shown
const feedbackKey = "feedback_given_" + "<?= htmlspecialchars($orderGroupId) ?>";
function feedbackAlreadyGiven() { return localStorage.getItem(feedbackKey) === "yes"; }
function markFeedbackGiven() { localStorage.setItem(feedbackKey, "yes"); }
function showFeedbackModal() { if (!feedbackAlreadyGiven()) document.getElementById('feedbackModal').style.display = 'flex'; }
function closeFeedbackModal() { document.getElementById('feedbackModal').style.display = 'none'; }
function showFeedbackPopup(message="Thank you for your feedback!", emoji="🎉") {
    const popup = document.getElementById('feedbackPopup');
    popup.querySelector('.message').textContent = message;
    popup.querySelector('.emoji').textContent = emoji;
    popup.style.display = 'block';
}
function closeFeedbackPopup() {
    const popup = document.getElementById('feedbackPopup');
    popup.style.animation = "popupOut 0.3s forwards";
    setTimeout(() => {
        popup.style.display='none';
        popup.style.animation = "popupIn 0.4s forwards";
    }, 300);
    markFeedbackGiven();
}
let cancelDetected = false;
let statusIntervalId = null;

function escapeHtml(str) {
  return String(str).replace(/[&<>"'`=\/]/g, function (s) {
    return ({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;","/":"&#x2F;","`":"&#x60;","=":"&#x3D;"} )[s];
  });
}

//CANCEL MODAL
function showModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.add('active');
        modal.setAttribute('aria-modal', 'true');
        document.body.classList.add('modal-open');
        // Optionally, focus the first .close-btn for accessibility
        const closeBtn = modal.querySelector('.close-btn');
        if (closeBtn) closeBtn.focus();
    }
}
function hideModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.remove('active');
        modal.removeAttribute('aria-modal');
        document.body.classList.remove('modal-open');
    }
}
// Show/hide cancel modal with custom message if provided
function showCancelModal(msg) {
    var modal = document.getElementById('cancelModal');
    var msgEl = document.getElementById('cancelMessage');
    if (!modal || !msgEl) return;
    if (msg) msgEl.innerHTML = msg;
    showModal('cancelModal');
}
function cancelGoHome() { window.location.href = "kiosk_home.php"; }
// Confirm/cancel logic
function showCancelConfirmationModal() { showModal('cancelConfirmationModal'); }
function closeCancelConfirmationModal() { hideModal('cancelConfirmationModal'); }
function showErrorModal(msg) {
    var modal = document.getElementById('errorModal');
    var msgEl = document.getElementById('errorModalMsg');
    if(!modal || !msgEl) return;
    msgEl.innerHTML = msg;
    showModal('errorModal');
}
function closeErrorModal() { hideModal('errorModal'); }

// Clicking outside the modal-content closes modals only for overlays (NOT for popups)
['cancelModal','errorModal','cancelConfirmationModal','orderAgainModal','thaiQuizModal','factFictionModal','catchFoodModal'].forEach(function(id){
    document.getElementById(id)?.addEventListener('mousedown', function(event) {
        if(event.target === this) hideModal(id);
    });
});

document.addEventListener('DOMContentLoaded', function() {
    const triggerCancelModalBtn = document.getElementById('triggerCancelModalBtn');
    const cancelOrderYes = document.getElementById('cancelOrderYes');
    const cancelOrderNo = document.getElementById('cancelOrderNo');
    if (triggerCancelModalBtn) {
        triggerCancelModalBtn.addEventListener('click', function(e) {
            if (triggerCancelModalBtn.disabled) {
                showErrorModal(triggerCancelModalBtn.title || 'You cannot cancel this order at this stage.');
                return;
            }
            showCancelConfirmationModal();
        });
    }
    if (cancelOrderNo) {
        cancelOrderNo.addEventListener('click', function() {
            closeCancelConfirmationModal();
        });
    }
    if (cancelOrderYes) {
            cancelOrderYes.addEventListener('click', function() {
            closeCancelConfirmationModal();
            if(triggerCancelModalBtn) triggerCancelModalBtn.disabled = true;
            fetch('order_status.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'order_group_id=' + encodeURIComponent(<?= json_encode($orderGroupId) ?>) + '&action=cancel_order'
            })
            .then(res => res.json().catch(()=>null))
            .then(data => {
                if(data && data.status === 'success') {
                    // stop polling and mark cancel detected
                    try { if (statusIntervalId) { clearInterval(statusIntervalId); statusIntervalId = null; } } catch(e){}
                    cancelDetected = true;
                    // disable cancel button and additional order
                    try {
                        const cb = document.getElementById('triggerCancelModalBtn');
                        if (cb) {
                            cb.disabled = true;
                            cb.title = data.cancel_reason || 'Already cancelled.';
                            cb.style.opacity = '0.65';
                            cb.style.cursor = 'not-allowed';
                        }
                        const addBtn = document.getElementById('additionalOrderBtn');
                        if (addBtn) {
                            addBtn.disabled = true;
                            addBtn.style.opacity = '0.45';
                            addBtn.style.cursor = 'not-allowed';
                            addBtn.title = 'Cannot add items to cancelled orders.';
                        }
                    } catch(e){/*ignore*/}
                    // show cancel modal with staff message
                    showCancelModal('Your cancellation request has been submitted. The order status has been updated. Please proceed to cashier if needed.');
                } else {
                    let msg = data ? (data.message || 'Unknown error.') : 'The server did not respond as expected. Please reload.';
                    showErrorModal('Cancellation failed: ' + msg);
                    if (triggerCancelModalBtn) triggerCancelModalBtn.disabled = false;
                }
            }).catch((err)=>{
                showErrorModal('An unexpected error occurred during cancellation. Please reload or contact staff.');
                if (triggerCancelModalBtn) triggerCancelModalBtn.disabled = false;
            });
        });
    }
});

// The rest of your modal show/hide can use showModal/hideModal for other modals.
function confirmOrderAgain() { showModal('orderAgainModal'); }
function proceedOrderAgain() {
    hideModal('orderAgainModal');
    setTimeout(function(){ window.location.href = 'kiosk_home.php'; }, 150);
}

// Update the status badge safely
function updateBadge(displayText, rawStatus) {
  const badge = document.getElementById("live-status");
  if (!badge) return;
  badge.textContent = (displayText || rawStatus || '').toUpperCase();
  badge.className = "status-badge";
  const norm = (displayText || rawStatus || '').toLowerCase();
  if (norm.indexOf('pending') !== -1) badge.classList.add("status-pending");
  else if (norm.indexOf('prepar') !== -1) badge.classList.add("status-preparing");
  else if (norm.indexOf('serv') !== -1 || norm === 'serving now') badge.classList.add("status-serving");
  else if (norm.indexOf('cancel') !== -1) badge.classList.add("status-canceled");
}

// Primary polling function (defensive & robust)
function fetchStatus() {
  if (!ORDER_GROUP_ID) return;

  fetch("get_order_status.php?id=" + encodeURIComponent(ORDER_GROUP_ID), { cache: "no-store" })
    .then(res => res.json())
    .then(data => {
      if (!data) return;
      if (data.status !== "success") {
        // optional: show an error or just return
        return;
      }

      let statusText = (data.order_status || '').trim();
      let paidFlag = parseInt(data.paid || 0);
      // normalize display for "Completed" / "Served"
      let display = statusText;
      const low = statusText.toLowerCase();
      if (low === "completed" || low === "served") display = "Serving Now";

      updateBadge(display, statusText);

      // Show "Order Again" button when both Cancel and Add New Order are disabled (paid + completed/served)
      if (paidFlag === 1 && (low === 'completed' || low === 'served')) {
        const orderAgainBtn = document.getElementById('orderAgainBtn');
        if (orderAgainBtn) {
          // If button is not already visible, show it and schedule feedback
          if (orderAgainBtn.style.display === 'none' || orderAgainBtn.style.display === '') {
            orderAgainBtn.style.display = 'flex';
            orderAgainButtonShownTime = Date.now(); // Record when button was shown
            
            // Show feedback modal 30 seconds after "Order Again" button appears
            setTimeout(() => {
              if (!feedbackModalShown && !feedbackAlreadyGiven()) {
                if (typeof showFeedbackModal === "function") {
                  showFeedbackModal();
                  feedbackModalShown = true;
                }
              }
            }, 30000);
          }
        }
      }

      // Show feedback modal for "Serving Now" once (kept for backward compatibility but now secondary to Order Again logic)
      if (display === "Serving Now" && !servingNowDetected && !localStorage.getItem("feedback_given_" + ORDER_GROUP_ID)) {
        servingNowDetected = true;
        // Feedback will now be shown 30 seconds after "Order Again" button appears instead of here
      }

      // Detect cancellation by checking if status contains "cancel"
      const normalized = low.trim();
if (
  (normalized === 'canceled' || normalized === 'cancelled') &&
  !cancelDetected
) {
        cancelDetected = true;
        // stop polling to avoid repeated popups
        if (statusIntervalId) {
          clearInterval(statusIntervalId);
          statusIntervalId = null;
        }
        // pass the cancel reason if backend provided one
        const reason = (data.cancel_reason && typeof data.cancel_reason === 'string') ? data.cancel_reason : null;
        showCancelModal(reason);
      }
    })
    .catch(err => {
      // log for debugging but do not break polling
      console.error("fetchStatus error:", err);
    });
}

function startStatusPolling() {
  // clear existing interval if any
  if (statusIntervalId) {
    clearInterval(statusIntervalId);
    statusIntervalId = null;
  }
  // run immediately then set interval
  fetchStatus();
  statusIntervalId = setInterval(fetchStatus, 1000);
}

// Start polling after DOM loaded so elements exist
if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", startStatusPolling, { once: true });
} else {
  startStatusPolling();
}

// Auto-disable Cancel button if status is "preparing" for >=3 min or if status is "completed"/"served"
function checkCancelAutoDisable() {
    const cancelBtn = document.getElementById('triggerCancelModalBtn');
    if (!cancelBtn || cancelBtn.disabled) return;

    fetch("get_order_status.php?id=" + encodeURIComponent(ORDER_GROUP_ID), { cache: "no-store" })
        .then(res => res.json())
        .then(data => {
            if (!data || data.status !== "success") return;
            const status = (data.order_status || '').trim().toLowerCase();

            // Disable cancel button if status is "completed" or "served" (Serving Now)
            if (status === "completed" || status === "served") {
                cancelBtn.disabled = true;
                cancelBtn.style.opacity = "0.65";
                cancelBtn.style.cursor = "not-allowed";
                cancelBtn.style.background = "#ffe9e6";
                cancelBtn.style.borderColor = "#ffa896";
                cancelBtn.style.color = "#ad3f27";
                cancelBtn.title = "Cannot cancel order while serving.";
            } else if (status === "preparing") {
                const updatedAt = data.updated_at; // Expect ISO format or UNIX timestamp
                let updatedAtDate;
                if (/^\d+$/.test(updatedAt)) {
                    updatedAtDate = new Date(Number(updatedAt) * 1000); // UNIX timestamp
                } else {
                    updatedAtDate = new Date(updatedAt); // ISO format
                }
                const now = new Date();
                const diffSeconds = (now - updatedAtDate) / 1000;
                if (diffSeconds >= 180) {
                    cancelBtn.disabled = true;
                    cancelBtn.style.opacity = "0.65";
                    cancelBtn.style.cursor = "not-allowed";
                    cancelBtn.style.background = "#ffe9e6";
                    cancelBtn.style.borderColor = "#ffa896";
                    cancelBtn.style.color = "#ad3f27";
                    cancelBtn.title = "Too late: Preparing more than 3 minutes.";
                }
            }
        })
        .catch(() => { /* silent fail */ });
}

// Poll every X seconds
setInterval(checkCancelAutoDisable, 10 * 1000); // Every 10 seconds

document.addEventListener('DOMContentLoaded', checkCancelAutoDisable);

// Feedback Modal logic (stars & emojis)
document.addEventListener('DOMContentLoaded', () => {
    const stars = document.querySelectorAll('.star');
    const emojis = document.querySelectorAll('.emoji');
    function updateFeedbackDisplay(rating){
        stars.forEach(s=>{ s.classList.toggle('active', parseInt(s.dataset.rating)<=rating); });
        emojis.forEach(e=>{ e.classList.toggle('active', parseInt(e.dataset.rating)===rating); });
    }
    stars.forEach(star=>{ star.addEventListener('click',()=>{ userRating=parseInt(star.dataset.rating); updateFeedbackDisplay(userRating); }); });
    emojis.forEach(emoji=>{ emoji.addEventListener('click',()=>{ userRating=parseInt(emoji.dataset.rating); updateFeedbackDisplay(userRating); }); });
});


// Submit Feedback (remain on page, show thank you popup, and remember)
function submitFeedback() {
    const comment = document.getElementById('feedbackComment').value;
    if(userRating===0){ alert("Please select a rating."); return; }

    fetch('submit_feedback.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({
            order_number:"<?= htmlspecialchars($orderGroupId) ?>",
            rating: userRating,
            comment: comment,
            customer_name: "<?= htmlspecialchars($customerName ?? '') ?>"
        })
    })
    .then(res => res.json())
    .then(data => {
        if(data.status==='success'){
            closeFeedbackModal();
            showFeedbackPopup();
            // markFeedbackGiven() runs after OK is clicked!
        } else {
            alert("Error: " + data.message);
        }
    })
    .catch(err => {
        console.error("Fetch error:", err);
        alert("An error occurred while submitting feedback.");
    });
}

// Popups and assistance logic
function showPopup(message, type="info") {
    const modal = document.getElementById("popupModal");
    const msg = document.getElementById("popupMessage");
    const icon = document.getElementById("popupIcon");
    msg.textContent = message;
    if(type==="success"){ modal.style.border="2px solid #4CAF50"; }
    else if(type==="error"){ modal.style.border="2px solid #F44336"; }
    else{ modal.style.border="2px solid #FF9800"; }
    modal.style.display="block";
    // Disable all request/action buttons
    document.querySelectorAll('.card.actions button').forEach(btn => {
        btn.classList.add('disabled-by-popup');
        btn.disabled = true;
        btn.style.opacity = "0.6";
        btn.style.cursor = "not-allowed";
    });
}
function closePopup() {
    const modal = document.getElementById("popupModal");
    modal.classList.add("fadeOut");
    setTimeout(function() {
        modal.style.display = "none";
        modal.classList.remove("fadeOut");
        // Re-enable all request/action buttons
        document.querySelectorAll('.card.actions button').forEach(btn => {
            btn.classList.remove('disabled-by-popup');
            btn.disabled = false;
            btn.style.opacity = "";
            btn.style.cursor = "";
        });
    }, 180);
}
function sendRequest(type) {
    // Prevent execution if popup disables buttons
    if (event && event.target && event.target.classList.contains('disabled-by-popup')) return;
    fetch("send_request.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
            order_group_id: "<?= htmlspecialchars($orderGroupId) ?>",
            request_type: type
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === "success") {
            showPopup(type + " request sent successfully!", "success");
            if (type === "Printed Receipt") {
                // Show the summary modal after request is sent
                loadSummaryOfOrders();
            }
        } else {
            showPopup("Error: " + data.message, "error");
        }
    })
    .catch(err => {
        showPopup("Request failed. Please try again.", "error");
        console.error(err);
    });
}
// confirm oder again
function confirmOrderAgain() {
    document.getElementById('orderAgainModal').classList.add('active');
}
function closeOrderAgainModal() {
    document.getElementById('orderAgainModal').classList.remove('active');
}
function proceedOrderAgain() {
    // Optionally add a fade out effect
    document.getElementById('orderAgainModal').classList.remove('active');
    setTimeout(function(){ window.location.href = 'kiosk_home.php'; }, 150);
}
/* ========== MINI GAMES LOGIC ========== */

// 1. Thai Vocabulary Quiz
const thaiQuizData = [
    {q: "What does 'Sawadee' (สวัสดี) mean?", a: "Hello", options: ["Spicy", "Hello", "Thank you", "Goodbye"]},
    {q: "What does 'Aroi' (อร่อย) mean?", a: "Delicious", options: ["Hot", "Rice", "Delicious", "Drink"]},
    {q: "What does 'Khop Khun' (ขอบคุณ) mean?", a: "Thank you", options: ["Yes", "No", "Thank you", "Sorry"]},
    {q: "What's 'water' in Thai?", a: "Nam", options: ["Som", "Nam", "Gai", "Moo"]},
    {q: "What does 'Mai Pen Rai' (ไม่เป็นไร) mean?", a: "Never mind", options: ["Never mind", "Spicy", "Delicious", "Very good"]},
{ q: "Which crispy seaweed snack is popular in Thailand?", a: "Tao Kae Noi", options: ["Tao Kae Noi", "Pocky", "Lay's", "Pretz"] },
  { q: "What is 'Kanom Buang'?", a: "Thai crepe", options: ["Thai crepe", "Rice cracker", "Candy", "Fried dough"] },
  { q: "Which fruit is commonly dried and sold as a Thai snack?", a: "Mango", options: ["Mango", "Apple", "Pear", "Guava"] },
  { q: "What is 'Look Choop' made of?", a: "Mung beans", options: ["Mung beans", "Rice", "Coconut", "Cassava"] },
  { q: "Which Thai snack looks like small tacos?", a: "Kanom Buang", options: ["Kanom Buang", "Khao Lam", "Tako", "Thong Yod"] },
  { q: "What is 'Khao Lam' cooked in?", a: "Bamboo tube", options: ["Banana leaf", "Bamboo tube", "Clay pot", "Coconut shell"] },
  { q: "Which Thai street snack is made from grilled pork skewers?", a: "Moo Ping", options: ["Moo Ping", "Satay", "Sai Oua", "Tod Man"] },
  { q: "What is 'Tod Man Pla'?", a: "Fish cakes", options: ["Fish cakes", "Fried chicken", "Spicy noodles", "Dumplings"] },
  { q: "Which Thai dessert is rolled in thin pancakes?", a: "Roti Sai Mai", options: ["Roti Sai Mai", "Khao Niew Mamuang", "Tako", "Thong Yip"] },
  { q: "Which city is known for its floating markets?", a: "Bangkok", options: ["Bangkok", "Chiang Mai", "Ayutthaya", "Phuket"] },
  { q: "Thailand is known for producing which natural product?", a: "Rubber", options: ["Rubber", "Cotton", "Steel", "Gold"] },
  { q: "Which famous beach was featured in the movie 'The Beach'?", a: "Maya Bay", options: ["Maya Bay", "Patong", "Railay", "Jomtien"] },
  { q: "Which traditional Thai silk is world-renowned?", a: "Jim Thompson Silk", options: ["Jim Thompson Silk", "Bangkok Weave", "Chiang Silk", "Siam Cotton"] },
  { q: "What is Thailand's main source of tourism income?", a: "Beaches", options: ["Food", "Beaches", "Temples", "Shopping"] },
  { q: "What gesture is used to greet people in Thailand?", a: "Wai", options: ["Handshake", "Bow", "Wai", "Wave"] },

];
let thaiQuizCurrent = 0, thaiQuizScore = 0;
function openThaiQuiz(){
    hideAllGameModals();
    thaiQuizCurrent = 0; thaiQuizScore = 0;
    renderThaiQuizQ();
    document.getElementById('thaiQuizModal').style.display = 'flex';
}
function closeThaiQuiz(){
    document.getElementById('thaiQuizModal').style.display = 'none';
}
function renderThaiQuizQ(){
    const d = thaiQuizData[thaiQuizCurrent];
    let html = `<h2>Thai Language Quiz</h2>
        <div style="font-size:1.15em; margin-bottom:10px;">Q${thaiQuizCurrent+1}: ${d.q}</div>
        <div id="thaiQuizOptions">`;
    d.options.forEach(opt=>{
        html += `<button onclick="thaiQuizAnswer('${opt.replace(/'/g,"\\'")}')" style="margin:7px 0; width:90%; padding:9px; border-radius:8px; border:none; background:#FFD600;color:#333;font-weight:700;font-size:1em;cursor:pointer;">${opt}</button><br>`;
    });
    html += `</div><div style="margin-top:10px;font-size:0.98em;color:#E64A19;">${thaiQuizCurrent+1} / ${thaiQuizData.length}</div>`;
    document.getElementById('thaiQuizContent').innerHTML = html;
}
function thaiQuizAnswer(sel){
    const d = thaiQuizData[thaiQuizCurrent];
    let correct = sel === d.a;
    if(correct) thaiQuizScore++;
    document.getElementById('thaiQuizOptions').innerHTML = `<div style="font-size:1.13em; margin:12px 0; color:${correct?'#4CAF50':'#F44336'};">${correct?'Correct!':'Incorrect.'} <b>${d.a}</b> is the answer.</div>`;
    setTimeout(()=>{
        thaiQuizCurrent++;
        if(thaiQuizCurrent < thaiQuizData.length){
            renderThaiQuizQ();
        } else {
            document.getElementById('thaiQuizContent').innerHTML = `<h2>Thai Language Quiz</h2>
                <div style="margin:15px 0;font-size:1.13em;">You scored <b>${thaiQuizScore}</b> out of <b>${thaiQuizData.length}</b>!</div>
                <button onclick="openThaiQuiz()" style="background:#FF9800;color:#fff;border:none;padding:11px 19px;border-radius:10px;font-weight:700;font-size:1em;cursor:pointer;">Play Again</button>`;
        }
    }, 1250);
}

// --- Thai Fact or Fiction Game ---
const factFictionData = [
    {q: "Thailand is the only Southeast Asian country never colonized by a European power.", a: true},
    {q: "The currency of Thailand is the Ringgit.", a: false},
    {q: "Songkran is a famous Thai water festival.", a: true},
    {q: "Bangkok is the northernmost city in Thailand.", a: false},
    {q: "Pad Thai is originally from China.", a: false},
    {q: "Thai iced tea is made with black tea, milk, and sugar.", a: true},
    {q: "Thailand is part of Africa.", a: false},
  {q: "Thailand has more than 1,400 islands.", a: true},
  {q: "Phuket is the largest island in Thailand.", a: true},
    {q: "The elephant is Thailand’s national animal.", a: true},
  {q: "White elephants are considered royal and sacred.", a: true},
    {q: "Thailand’s time zone is GMT+7.", a: true},
      {q: "Flooding can occur during the monsoon season in Thailand.", a: true},
  {q: "It’s polite to touch someone’s head to show respect in Thailand.", a: false},
    {q: "Monks in orange robes are common in Thailand.", a: true},
      {q: "Thai is a tonal language with five tones.", a: true},
  {q: "Changing tones can change a word’s meaning in Thai.", a: true},
    {q: "Thai currency is called the Baht.", a: true},
  {q: "The Thai currency is the Ringgit.", a: false},
    {q: "Thailand has a cold, snowy winter season.", a: false},
];
let factFictionIndex = 0, factFictionScore = 0;
function openFactFiction(){
    hideAllGameModals();
    factFictionIndex = 0; factFictionScore = 0;
    renderFactFictionQ();
    document.getElementById('factFictionModal').style.display = 'flex';
}
function closeFactFiction(){
    document.getElementById('factFictionModal').style.display = 'none';
}
function renderFactFictionQ(){
    const d = factFictionData[factFictionIndex];
    let html = `<h2>Thai Fact or Fiction</h2>
        <div style="font-size:1.15em; margin-bottom:16px;">${d.q}</div>
        <button onclick="factFictionAnswer(true)" style="background:#4CAF50;color:#fff;border:none;padding:10px 24px;border-radius:10px;font-weight:700;font-size:1em;cursor:pointer;margin:3px 9px;">Fact</button>
        <button onclick="factFictionAnswer(false)" style="background:#F44336;color:#fff;border:none;padding:10px 24px;border-radius:10px;font-weight:700;font-size:1em;cursor:pointer;margin:3px 9px;">Fiction</button>
        <div style="margin-top:12px;font-size:0.98em;color:#E64A19;">${factFictionIndex+1} / ${factFictionData.length}</div>`;
    document.getElementById('factFictionContent').innerHTML = html;
}
function factFictionAnswer(ans){
    const d = factFictionData[factFictionIndex];
    let correct = ans === d.a;
    if(correct) factFictionScore++;
    document.getElementById('factFictionContent').innerHTML += `<div style="font-size:1.13em; margin:12px 0; color:${correct?'#4CAF50':'#F44336'};">${correct?'Correct!':'Incorrect.'}</div>`;
    setTimeout(()=>{
        factFictionIndex++;
        if(factFictionIndex < factFictionData.length){
            renderFactFictionQ();
        } else {
            document.getElementById('factFictionContent').innerHTML = `<h2>Thai Fact or Fiction</h2>
                <div style="margin:15px 0;font-size:1.13em;">You scored <b>${factFictionScore}</b> out of <b>${factFictionData.length}</b>!</div>
                <button onclick="openFactFiction()" style="background:#FF9800;color:#fff;border:none;padding:11px 19px;border-radius:10px;font-weight:700;font-size:1em;cursor:pointer;">Play Again</button>`;
        }
    }, 1200);
}

// --- Catch the Thai Food Game ---
const thaiFoods = [
    {label:"🍜", name:"Pad Thai"},
    {label:"🍲", name:"Tom Yum"},
    {label:"🥗", name:"Som Tum"},
    {label:"🍛", name:"Green Curry"},
    {label:"🥭", name:"Mango Sticky Rice"},
];
const catchBomb = {label:"💣", name:"Bomb"};
let catchFoodInterval = null,
    catchFoodObjects = [],
    catchFoodPlateX = 120,

    catchFoodScore = 0,
    catchFoodGameOver = false;

function openCatchFoodGame() {
    hideAllGameModals();
    document.getElementById('catchFoodModal').style.display = 'flex';
    resetCatchFoodGame();
}
function stopCatchFoodGame() {
    if (catchFoodInterval) {
        clearInterval(catchFoodInterval);
        catchFoodInterval = null;
    }
}

function closeCatchFoodGame() {
    document.getElementById('catchFoodModal').style.display = 'none';
    stopCatchFoodGame();
    // Ensure area is interactive again when closing the modal
    const area = document.getElementById('catchFoodGameArea');
    if (area) area.style.pointerEvents = ''; // clear inline value so interactions work next open
}
function centerPlateInArea() {
    const area = document.getElementById('catchFoodGameArea');
    if (area) {
        const areaWidth = Math.max(0, area.clientWidth);
        // plate width is 60px (same as used in renderCatchFoodArea)
        catchFoodPlateX = Math.round(Math.max(0, (areaWidth - 60) / 2));
    } else {
        catchFoodPlateX = 120; // fallback
    }
}

// Update reset/start functions to center the plate (remove slider references)
function resetCatchFoodGame() {
    document.getElementById('catchFoodScore').textContent = '';
    document.getElementById('catchFoodStartBtnBox').style.display = '';
    catchFoodObjects = [];
    // center the plate if possible
    centerPlateInArea();
    catchFoodScore = 0;
    catchFoodGameOver = false;

    // Re-enable pointer interactions so the player can move the plate again
    const area = document.getElementById('catchFoodGameArea');
    if (area) {
        // either clear inline style or set to 'auto' to explicitly allow pointer events
        area.style.pointerEvents = ''; // restore default (or use 'auto')
    }

    renderCatchFoodArea(true);
    stopCatchFoodGame();
}

function startCatchFoodGame() {
    catchFoodObjects = [];
    // center the plate if possible
    centerPlateInArea();
    catchFoodScore = 0;
    catchFoodGameOver = false;

    // Re-enable pointer interactions before starting
    const area = document.getElementById('catchFoodGameArea');
    if (area) area.style.pointerEvents = ''; // or 'auto'

    document.getElementById('catchFoodScore').textContent = 'Score: 0';
    document.getElementById('catchFoodStartBtnBox').style.display = 'none';
    renderCatchFoodArea();
    if(catchFoodInterval) clearInterval(catchFoodInterval);
    catchFoodInterval = setInterval(catchFoodGameLoop, 40);
}

// Pointer-based dragging / tapping to move plate
document.addEventListener('DOMContentLoaded', () => {
    const area = document.getElementById('catchFoodGameArea');
    if (!area) return;

    // make the area visually indicate it's interactive
    area.style.cursor = 'pointer';
    area.style.touchAction = 'none'; // prevent scrolling while dragging on touch

    let dragging = false;
    let activePointerId = null;

    function updatePlateByClientX(clientX) {
        const rect = area.getBoundingClientRect();
        // center the plate under the pointer: plate width = 60
        let x = clientX - rect.left - 30;
        x = Math.round(x);
        const maxX = Math.max(0, rect.width - 60);
        if (x < 0) x = 0;
        if (x > maxX) x = maxX;
        catchFoodPlateX = x;
        // re-render plate position (don't clear falling objects)
        renderCatchFoodArea();
    }

    area.addEventListener('pointerdown', (e) => {
        dragging = true;
        activePointerId = e.pointerId;
        try { area.setPointerCapture(activePointerId); } catch (err) {}
        updatePlateByClientX(e.clientX);
    });

    area.addEventListener('pointermove', (e) => {
        if (!dragging || e.pointerId !== activePointerId) return;
        updatePlateByClientX(e.clientX);
    });

    function endDrag(e) {
        if (!dragging || e.pointerId !== activePointerId) return;
        dragging = false;
        try { area.releasePointerCapture(activePointerId); } catch (err) {}
        activePointerId = null;
    }
    area.addEventListener('pointerup', endDrag);
    area.addEventListener('pointercancel', endDrag);
    // also allow clicks/taps (pointerdown already moves the plate immediately)
});

function catchFoodGameLoop() {
    // Add new food/bomb occasionally
    if (Math.random() < 0.045) {
        let isBomb = Math.random() < 0.22; // 22% chance for bomb
        let obj = isBomb
            ? {...catchBomb, x: Math.floor(Math.random() * 270), y: -34, type: 'bomb'}
            : {...thaiFoods[Math.floor(Math.random() * thaiFoods.length)], x: Math.floor(Math.random() * 270), y: -34, type: 'food'};
        catchFoodObjects.push(obj);
    }

    // Move objects down
    for (let obj of catchFoodObjects) {
        obj.y += 7 + Math.floor(catchFoodScore / 5); // speed up with score
    }

    // Collision detection (rectangle overlap)
    const plateLeft = catchFoodPlateX;
    const plateRight = catchFoodPlateX + 60; // plate width
    const plateTop = 370;
    const plateBottom = plateTop + 20; // plate height

    for (let i = catchFoodObjects.length - 1; i >= 0; i--) {
        let obj = catchFoodObjects[i];
        // approximate object bounds (emoji size ~30x30)
        const objLeft = obj.x;
        const objRight = obj.x + 30;
        const objTop = obj.y;
        const objBottom = obj.y + 30;

        const isOverlapping = !(objRight < plateLeft || objLeft > plateRight || objBottom < plateTop || objTop > plateBottom);

        if (isOverlapping) {
            // If bomb hits plate -> immediate game over
            if (obj.type === 'bomb') {
                catchFoodGameOver = true;
                stopCatchFoodGame();

                // Disable pointer interactions so player can't move plate after game over
                const area = document.getElementById('catchFoodGameArea');
                if (area) area.style.pointerEvents = 'none';

                // Optionally remove the bomb from the array so it doesn't linger
                catchFoodObjects.splice(i, 1);

                // Render final state
                renderCatchFoodArea();

                // Show Game Over text and Start button
                document.getElementById('catchFoodScore').innerHTML = `<span style="color:#F44336;font-weight:700;">Game Over!</span> Final Score: ${catchFoodScore}`;
                document.getElementById('catchFoodStartBtnBox').style.display = '';

                return; // exit loop — game finished
            } else {
                // Normal food caught
                catchFoodScore++;
                document.getElementById('catchFoodScore').textContent = `Score: ${catchFoodScore}`;
                catchFoodObjects.splice(i, 1);
                continue;
            }
        }

        // Remove missed objects that went off screen
        if (obj.y > 400) {
            catchFoodObjects.splice(i, 1);
        }
    }

    // Re-render the game area each tick
    renderCatchFoodArea();
}

function renderCatchFoodArea(reset=false) {
    const area = document.getElementById('catchFoodGameArea');
    let html = '';
    if (reset) {
        // Render just the plate
        html = `<div style="position:absolute;left:${catchFoodPlateX}px;top:370px;width:60px;height:20px;background:#FFD600;border-radius:16px 16px 22px 22px/40px 40px 30px 30px;box-shadow:0 1px 5px #FF980080;display:flex;align-items:center;justify-content:center;font-size:2em;color:#E64A19;">🍽️</div>`;
    } else {
        // Render foods/bombs
        for (let obj of catchFoodObjects) {
            html += `<div style="position:absolute;left:${obj.x}px;top:${obj.y}px;font-size:2em;z-index:2;">${obj.label}</div>`;
        }
        // Render the plate
        html += `<div style="position:absolute;left:${catchFoodPlateX}px;top:370px;width:60px;height:20px;background:#FFD600;border-radius:16px 16px 22px 22px/40px 40px 30px 30px;box-shadow:0 1px 5px #FF980080;display:flex;align-items:center;justify-content:center;font-size:2em;color:#E64A19;">🍽️</div>`;
    }
    area.innerHTML = html;
}
// -------- Make sure modal show/hide functions are GLOBAL --------
function hideAllGameModals() {
    document.getElementById('thaiQuizModal').style.display = 'none';
    document.getElementById('factFictionModal').style.display = 'none';
    document.getElementById('catchFoodModal').style.display = 'none';
}

// QUEUING
function updateQueueNumberRealtime() {
    const queueSpan = document.querySelector('.queue-no');
    if (!queueSpan) return;
    const orderGroupId = "<?= htmlspecialchars($orderGroupId) ?>";

    fetch('get_queue_position.php?order_number=' + encodeURIComponent(orderGroupId), { cache: "no-store" })
        .then(res => res.json())
        .then(data => {
            // Check for success
            if (!data.success) return;
            // Hide the queue number if order is completed/served/serving now
            const status = (data.status ?? "").toLowerCase();
            if (["completed", "served", "serving now"].includes(status)) {
                queueSpan.style.display = "none"; // Hides the queue number
            } else if (typeof data.queue_position !== 'undefined') {
                queueSpan.style.display = ""; // Make sure it's visible
                queueSpan.textContent = "Queue #: " + data.queue_position;
            }
        })
        .catch(() => { /* silent fail */ });
}

document.addEventListener("DOMContentLoaded", function() {
    window.queuePollInterval = setInterval(updateQueueNumberRealtime, 2000);
    updateQueueNumberRealtime(); // Initial call
});

// Start polling every 2 seconds after page loads
document.addEventListener("DOMContentLoaded", function() {
    window.queuePollInterval = setInterval(updateQueueNumberRealtime, 2000);
    updateQueueNumberRealtime(); // Initial call
});
// ADDITIONAL ORDER BUTTON LOGIC (order_status.php)
const additionalOrderBtn = document.getElementById('additionalOrderBtn');
const additionalOrderModal = document.getElementById('additionalOrderModal');
const additionalOrderYes = document.getElementById('additionalOrderYes');
const additionalOrderNo = document.getElementById('additionalOrderNo');
const ORDER_GROUP_ID = <?= json_encode($orderGroupId) ?>;

if (additionalOrderBtn) {
    additionalOrderBtn.addEventListener('click', function() {
        additionalOrderModal.style.display = 'flex';
    });
}
if (additionalOrderNo) {
    additionalOrderNo.addEventListener('click', function() {
        additionalOrderModal.style.display = 'none';
    });
}
if (additionalOrderYes) {
    additionalOrderYes.addEventListener('click', function() {
        // Redirect to kiosk menu page with add_to query parameter
        window.location.href = 'kiosk_home.php?add_to=' + encodeURIComponent(ORDER_GROUP_ID);
    });
}

// For disabled: show alert
if (additionalOrderBtn && additionalOrderBtn.disabled) {
    additionalOrderBtn.addEventListener('click', function() {
        alert('You can only add items to active, unpaid orders.');
    });
}
// Cancelllllll

document.addEventListener("DOMContentLoaded", function() {
    const additionalOrderBtn = document.getElementById('additionalOrderBtn');
    const additionalOrderModal = document.getElementById('additionalOrderModal');
    const additionalOrderYes = document.getElementById('additionalOrderYes');
    const additionalOrderNo = document.getElementById('additionalOrderNo');
    const ORDER_GROUP_ID = "<?= htmlspecialchars($orderGroupId) ?>";

    if (additionalOrderBtn) {
        additionalOrderBtn.addEventListener('click', function() {
            if (this.disabled) {
                alert('You can only add items to active, unpaid orders.');
                return;
            }
            additionalOrderModal.style.display = 'flex';
            additionalOrderModal.focus();
        });
    }
    if (additionalOrderNo) {
        additionalOrderNo.addEventListener('click', function() {
            additionalOrderModal.style.display = 'none';
        });
    }
    if (additionalOrderYes) {
        additionalOrderYes.addEventListener('click', function() {
            window.location.href = 'kiosk_home.php?add_to=' + encodeURIComponent(ORDER_GROUP_ID);
        });
    }
    if (additionalOrderModal) {
        additionalOrderModal.addEventListener('mousedown', function(e){
            if (e.target === this) this.style.display = 'none';
        });
    }
});
(function () {
  // Require a global ORDER_GROUP_ID defined before this script is run.
  if (typeof ORDER_GROUP_ID === 'undefined' || !ORDER_GROUP_ID) return;

  // Prevent double-instantiation if script is loaded/executed more than once
  if (window.__ORDER_STATUS_SSE && window.__ORDER_STATUS_SSE.active) return;

  // Feature detect
  if (!("EventSource" in window)) {
    console.warn("SSE not supported in this browser — falling back to polling.");
    // If you have a polling implementation exposed, call it:
    if (typeof window.startOrderStatusPolling === 'function') {
      window.startOrderStatusPolling();
    }
    return;
  }

  const srcUrl = 'status_sse.php?id=' + encodeURIComponent(ORDER_GROUP_ID);
  let es = null;
  let reconnectTimer = null;
  let reconnectAttempts = 0;
  const maxBackoff = 30 * 1000; // 30s
  const baseBackoff = 1500; // 1.5s

  function backoffDelay(attempts) {
    // exponential backoff with jitter
    const exp = Math.min(maxBackoff, baseBackoff * Math.pow(1.8, attempts));
    return Math.round(exp * (0.75 + Math.random() * 0.5));
  }

  function closeES() {
    try {
      if (es) {
        try { es.close(); } catch (err) {}
        es = null;
      }
    } catch (err) {
      // ignore
    }
  }

  function initEventSource() {
    // clear any pending reconnect
    if (reconnectTimer) {
      clearTimeout(reconnectTimer);
      reconnectTimer = null;
    }

    // If already open, don't re-create
    if (es && es.readyState !== EventSource.CLOSED) return;

    try {
      es = new EventSource(srcUrl);
      window.__ORDER_STATUS_SSE = window.__ORDER_STATUS_SSE || {};
      window.__ORDER_STATUS_SSE.es = es;
      window.__ORDER_STATUS_SSE.active = true;
    } catch (err) {
      console.error('Failed to create EventSource', err);
      scheduleReconnect();
      return;
    }

    es.addEventListener('open', function () {
      reconnectAttempts = 0;
      console.info('SSE connected for order', ORDER_GROUP_ID);
    });

    es.addEventListener('ping', function (e) {
      // heartbeat; no-op (optionally use for diagnostics)
      // console.debug('SSE ping', e && e.data);
    });

    es.addEventListener('update', function (e) {
      try {
        const raw = (e && e.data) ? e.data : null;
        if (!raw) return;
        const data = JSON.parse(raw);
        if (!data || data.status !== 'success') return;

        const status = (data.order_status || '').trim();
        const paid = Number(data.paid || 0);

        // Payment status
        const paySpan = document.getElementById('paymentStatusSpan');
        if (paySpan) {
          paySpan.textContent = paid === 1 ? 'Paid' : 'Unpaid';
          paySpan.style.color = paid === 1 ? '#388e3c' : '#d35400';
        }

        // Additional Order button enable/disable
        const addBtn = document.getElementById('additionalOrderBtn');
        if (addBtn) {
          const lowStatus = (status || '').toLowerCase();
          const disallowed = (paid === 1) || ['canceled', 'cancelled', 'closed'].includes(lowStatus);
          addBtn.disabled = !!disallowed;
          addBtn.style.opacity = disallowed ? '.45' : '';
          addBtn.style.cursor = disallowed ? 'not-allowed' : '';
          addBtn.title = paid === 1 ? 'This order has already been paid; you cannot add items.' : 'Add items to this unpaid order';
        }

        // Update badge (reuse updateBadge if provided)
        if (typeof updateBadge === 'function') {
          let display = status;
          const low = status.toLowerCase();
          if (low === 'completed' || low === 'served') display = 'Serving Now';
          updateBadge(display, status);
        } else {
          const badge = document.getElementById('live-status');
          if (badge) badge.textContent = (status || '').toUpperCase();
        }

        // If cancelled, show cancel modal (if provided)
        const lowStatus = (status || '').toLowerCase();
        if ((lowStatus === 'canceled' || lowStatus === 'cancelled') && typeof showCancelModal === 'function') {
          try { showCancelModal(data.cancel_reason || null); } catch (err) { /* ignore */ }
        }
      } catch (err) {
        console.error('Error handling SSE update', err);
      }
    });

    es.addEventListener('error', function (err) {
      // Distinguish between transient errors and closed states
      try {
        if (!es) return;
        if (es.readyState === EventSource.CLOSED) {
          console.warn('SSE connection closed by server for order', ORDER_GROUP_ID);
        } else {
          console.error('SSE error for order', ORDER_GROUP_ID, err);
        }
      } catch (ex) {
        console.error('SSE error handling failed', ex);
      } finally {
        closeES();
        scheduleReconnect();
      }
    });
  }

  function scheduleReconnect() {
    reconnectAttempts++;
    const delay = backoffDelay(reconnectAttempts);
    if (reconnectTimer) clearTimeout(reconnectTimer);
    reconnectTimer = setTimeout(() => {
      reconnectTimer = null;
      initEventSource();
    }, delay);
  }

  // Expose controller functions so page-level code can stop/restart SSE
  window.stopOrderStatusSSE = function () {
    window.__ORDER_STATUS_SSE = window.__ORDER_STATUS_SSE || {};
    window.__ORDER_STATUS_SSE.active = false;
    if (reconnectTimer) {
      clearTimeout(reconnectTimer);
      reconnectTimer = null;
    }
    closeES();
  };

  window.startOrderStatusSSE = function () {
    window.__ORDER_STATUS_SSE = window.__ORDER_STATUS_SSE || {};
    window.__ORDER_STATUS_SSE.active = true;
    initEventSource();
  };

  // Start it
  initEventSource();
})();

/* Automatic payment-status poll (keeps payment status + add-order button in sync without refresh) */
(function(){
    // Use existing ORDER_GROUP_ID if defined, otherwise fall back to PHP value
    var ORDER_ID = (typeof ORDER_GROUP_ID !== 'undefined' && ORDER_GROUP_ID) ? ORDER_GROUP_ID : <?= json_encode($orderGroupId) ?>;

    if (!ORDER_ID) return;

    function updatePaymentUI(data) {
        if (!data) return;
        var paid = Number(data.paid || 0);
        var statusText = (data.order_status || '').toLowerCase();

        var paySpan = document.getElementById('paymentStatusSpan');
        if (paySpan) {
            paySpan.textContent = paid === 1 ? 'Paid' : 'Unpaid';
            paySpan.style.color = paid === 1 ? '#388e3c' : '#d35400';
        }

        var addBtn = document.getElementById('additionalOrderBtn');
        if (addBtn) {
            var disallowed = (paid === 1) || ['canceled','cancelled','closed'].includes(statusText);
            addBtn.disabled = !!disallowed;
            addBtn.style.opacity = disallowed ? '.45' : '';
            addBtn.style.cursor = disallowed ? 'not-allowed' : '';
            addBtn.title = paid === 1 ? 'This order has already been paid; you cannot add items.' : 'Add items to this unpaid order';
        }
    }

    function pollPaymentStatusOnce() {
        fetch('get_order_status.php?id=' + encodeURIComponent(ORDER_ID), { cache: 'no-store' })
            .then(function(res){ return res.json(); })
            .then(function(data){
                if (!data || data.status !== 'success') return;
                updatePaymentUI(data);
            })
            .catch(function(){ /* silent fail - will retry on next tick */ });
    }

    // Start polling after DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function(){
            pollPaymentStatusOnce();
            window.__paymentPollInterval = setInterval(pollPaymentStatusOnce, 3000);
        }, { once: true });
    } else {
        pollPaymentStatusOnce();
        window.__paymentPollInterval = setInterval(pollPaymentStatusOnce, 3000);
    }
})();
</script>
</head>
<body>

<div class="container">
<?php if(!empty($error)): ?>
    <div class="card"><span class="status-badge status-canceled"><?= htmlspecialchars($error) ?></span></div>
<?php else: ?>
    <div class="card header">
        <img src="images/logo.png" alt="App Logo">
        <h1>ORDER UPDATE</h1>
        <div class="order-info">
          <span class="order-id">Order #: <?= htmlspecialchars($orderId) ?></span>
          <span class="queue-no">Queue #: <?= htmlspecialchars($queueNo) ?></span>
        </div>
        <div class="details">
            <div style="display:flex;gap:14px;align-items:center;justify-content:center;">
                <span>Customer: <strong><?= htmlspecialchars($customerName) ?></strong></span>
                <span>Table #: <strong><?= htmlspecialchars($tableNumber) ?></strong></span>
            </div>
            <div style="margin-top:8px;">
                Payment Method: <strong><?= htmlspecialchars($paymentMethod) ?></strong>
            </div>
            <div style="margin-top:6px;">
                Payment Status: <strong id="paymentStatusSpan" style="color:<?= ($orderData && isset($orderData['paid']) && intval($orderData['paid'])===1) ? '#388e3c' : '#d35400' ?>;">
                    <?= ($orderData && isset($orderData['paid']) && intval($orderData['paid'])===1) ? 'Paid' : 'Unpaid' ?>
                </strong>
            </div>
            <span>Placed: <strong><?= $createdAt ?></strong></span>
        </div>
        <span id="live-status" class="status-badge status-pending"><?= strtoupper($displayStatus) ?></span>
    </div>
    <div class="card actions">
    <!-- Assistance -->
    <button class="btn" onclick="sendRequest('Assistance')">
        <i class="fa-solid fa-hand"></i> Ask for Assistance
    </button>
    <!-- Follow up -->
    <button class="btn" onclick="sendRequest('Ready to Pay')">
        <i class="fa-solid fa-clipboard-list"></i> Ready to Pay
    </button>
    <!-- Add new order (with modal) -->
    <?php
    $lowStatus = strtolower($orderData['status']);
    // Only disallow adding new items when the order is closed/cancelled OR
    // when the order is already paid AND completed/served (so the "Order Again"
    // flow should be used instead).
    $isPaid = isset($orderData['paid']) && intval($orderData['paid']) === 1;
    $notAllowed = in_array($lowStatus, ['canceled', 'cancelled', 'closed'])
        || ($isPaid && preg_match('/^(completed|served)/i', $orderData['status'] ?? ''));
    ?>
    <button class="btn btn-white" 
        id="additionalOrderBtn" 
        aria-haspopup="dialog" 
        aria-controls="additionalOrderModal"
        <?= $notAllowed ? 'disabled style="opacity:.45;cursor:not-allowed;" title="You can only add items to active, unpaid orders."' : '' ?>>
        <i class="fas fa-plus-circle"></i> Add New Order
    </button>
   
    
    <!-- Modal for confirmation -->
    <div id="additionalOrderModal" class="modal-overlay" tabindex="-1" style="display:none;">
        <div class="modal-content" style="max-width:410px;">
            <div class="icon" style="margin-bottom:15px;color:#FFA000;">
                <i class="fa fa-shopping-cart" style="font-size:34px;"></i>
            </div>
            <div>
                <p style="font-size:1.06em;">Add more menu items to your existing order.<br>The new items will be appended to this order.<br><b>This cannot change your name, table, or payment method.</b></p>
            </div>
            <div style="display:flex; justify-content:center; gap:10px; margin-top:18px;">
                <button id="additionalOrderYes" class="btn" style="background:orange;color:#fff;">
                    <i class="fas fa-plus"></i> Add Item(s)
                </button>
                <button id="additionalOrderNo" class="btn" style="background:#aaa;color:#fff;">
                    <i class="fas fa-times"></i> Cancel
                </button>
            </div>
        </div>
    </div>
    <!-- Cancel Order -->
    <?php
    $disableCancel = false;
    if (!$isCancellable) {
        $disableCancel = true;
    }
    else if (
        isset($orderData["status"]) &&
        strtolower($orderData["status"]) === "preparing" &&
        isset($orderData["updated_at"])
    ) {
        $updatedAt_dt = strtotime($orderData["updated_at"]);
        $now = time();
        if ($now - $updatedAt_dt >= 180) {
            $disableCancel = true;
        }
    }

    // Also ensure cancel is disabled when order is completed/served (consistent with Add New Order)
    if (isset($orderData['status']) && in_array(strtolower($orderData['status']), ['completed', 'served'])) {
        $disableCancel = true;
    }
    // Additionally, if the order is paid and completed/served, keep cancel disabled
    if ($isPaid && isset($orderData['status']) && preg_match('/^(completed|served)/i', $orderData['status'])) {
        $disableCancel = true;
    }
    ?>
    <button class="btn btn-cancel"
            id="triggerCancelModalBtn"
        <?= $disableCancel ? 'disabled style="opacity:0.65; cursor:not-allowed; background:#ffe9e6; border-color:#ffa896; color:#ad3f27;"' : '' ?>
        title="<?= $isCancellable ? 'Cancel order' : htmlspecialchars($cancelExplain) ?>">
        <i class="fa-solid fa-ban"></i> Cancel Order
    </button>
    <?php
    // Show "Order Again" when the order is PAID and status is Completed/Served
    $showOrderAgain = (isset($orderData['paid']) && intval($orderData['paid']) === 1)
        && preg_match('/^(completed|served)/i', ($orderData['status'] ?? ''));
    ?>
    <button class="btn" id="orderAgainBtn" onclick="confirmOrderAgain()" style="<?= $showOrderAgain ? '' : 'display:none;' ?>">
        <i class="fa-solid fa-rotate-right"></i> Order Again
    </button>
    <?php endif; ?>
</div>

<!-- MINI THAI GAMES SECTION! -->
    <div style="margin-top:36px;margin-bottom:20px; text-align:center;">
        <div class="games-section-title" style="font-size:2.1rem;font-weight:700;color:#1E1400;margin-bottom:18px;">
            Mini Thai Games
        </div>
        <div class="games-section-row" style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
            <button class="game-btn-modern" onclick="openThaiQuiz()" style="background:#fff;color:#FF6700;border:2px solid #FF6700;border-radius:20px;font-size:1rem;font-weight:600;padding:10px 0 8px 0;width:120px;min-width:0;min-height:60px;max-width:140px;text-align:center;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;margin:0 4px;cursor:pointer;">
                <i class="fa-solid fa-spell-check" style="font-size:1.3em;margin-bottom:3px;color:#FF6700;"></i>
                Thai Language Quiz
            </button>
            <button class="game-btn-modern" onclick="openFactFiction()" style="background:#fff;color:#FF6700;border:2px solid #FF6700;border-radius:20px;font-size:1rem;font-weight:600;padding:10px 0 8px 0;width:120px;min-width:0;min-height:60px;max-width:140px;text-align:center;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;margin:0 4px;cursor:pointer;">
                <i class="fa-solid fa-question" style="font-size:1.3em;margin-bottom:3px;color:#FF6700;"></i>
                Thai Fact or Fiction
            </button>
            <button class="game-btn-modern" onclick="openCatchFoodGame()" style="background:#fff;color:#FF6700;border:2px solid #FF6700;border-radius:20px;font-size:1rem;font-weight:600;padding:10px 0 8px 0;width:120px;min-width:0;min-height:60px;max-width:140px;text-align:center;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;margin:0 4px;cursor:pointer;">
                <i class="fa-solid fa-bowl-food" style="font-size:1.3em;margin-bottom:3px;color:#FF6700;"></i>
                Catch the Thai Food
            </button>
        </div>
    </div>
</div>
<!-- CANCEL MODAL: CONVERTED TO UNIFIED MODAL PATTERN -->
<div id="cancelModal" class="modal-overlay" tabindex="-1">
    <div class="modal-content">
        <h2 id="cancelTitle">
            <i class="fa-solid fa-ban"></i> Order Cancelled
        </h2>
        <div id="cancelMessage">Your order has been cancelled. We are sorry for the inconvenience. If you are experiencing some trouble, ask for assistance. Thank you</div>
        <div class="cancel-actions">
            <button class="btn-home" onclick="cancelGoHome()">
                <i class="fa-solid fa-house"></i> Go Home
            </button>
        </div>
    </div>
</div>

<div id="cancelConfirmationModal" class="modal-overlay" tabindex="-1">
    <div class="modal-content">
        <button class="close-btn" onclick="hideModal('cancelConfirmationModal')" aria-label="Close" title="Close">×</button>
        <h2> Confirm Cancellation</h2>
        <div>Are you sure you want to cancel this order?</div>
        <div style="margin-top:18px;">
            <button id="cancelOrderYes" class="btn btn-cancel" style="margin-right:10px;">
                <i class="fas fa-check"></i> Yes, Cancel
            </button>
            <button id="cancelOrderNo" class="btn btn-white">
                <i class="fas fa-times"></i> Do Not Cancel
            </button>
        </div>
    </div>
</div>

<div id="errorModal" class="modal-overlay" tabindex="-1">
    <div class="modal-content">
        <button class="close-btn" onclick="hideModal('errorModal')" aria-label="Close" title="Close">×</button>
        <h2 style="color:#F44336"><i class="fa fa-exclamation-circle"></i> Action Not Allowed</h2>
        <div id="errorModalMsg"></div>
    </div>
</div>

<!-- Generic popup -->
<div id="popupModal">
    <div id="popupIcon"></div>
    <div id="popupMessage"></div>
    <button onclick="closePopup()">OK</button>
</div>

<!-- Enhanced feedback popup -->
<div id="feedbackPopup">
    <div class="emoji">🎉</div>
    <div class="message">Thank you for your feedback!</div>
    <button onclick="closeFeedbackPopup()">OK</button>
</div>

<!-- Feedback modal -->
<div id="feedbackModal" class="feedback-modal">
    <div class="feedback-container">
        <div class="feedback-header">How was your experience today?</div>
        <div class="feedback-stars">
            <span class="star" data-rating="1">★</span>
            <span class="star" data-rating="2">★</span>
            <span class="star" data-rating="3">★</span>
            <span class="star" data-rating="4">★</span>
            <span class="star" data-rating="5">★</span>
        </div>
        <div class="feedback-emojis">
            <span class="emoji" data-rating="1">😡</span>
            <span class="emoji" data-rating="2">😞</span>
            <span class="emoji" data-rating="3">😐</span>
            <span class="emoji" data-rating="4">😊</span>
            <span class="emoji" data-rating="5">😍</span>
        </div>
        <textarea id="feedbackComment" placeholder="Additional comments..." maxlength="100"></textarea>
        <div class="feedback-buttons">
            <button onclick="submitFeedback()">Submit</button>
            <button onclick="closeFeedbackModal()">Cancel</button>
        </div>
    </div>
</div>

<!-- Order Again Confirmation Modal (double validation) -->
<div id="orderAgainModal" class="modal-overlay" tabindex="-1">
    <div class="modal-content order-again-modal-content" style="max-width:420px;">
        <button class="close-btn" onclick="closeOrderAgainModal()" aria-label="Close" title="Close">×</button>
        <h2 style="margin-top:6px;">Order Again</h2>
        <div class="order-again-modal-message" style="margin:10px 0 6px 0; font-size:1rem; color:#333;">
            This will start a new order (appended to your session). To proceed, please confirm below.
        </div>

        <div style="margin-top:12px; text-align:center; width:100%;">
            <label style="display:flex; align-items:center; justify-content:center; gap:10px; font-weight:600; color:#333;">
                <input type="checkbox" id="orderAgainConfirmChk" style="width:18px;height:18px;" onchange="document.getElementById('orderAgainConfirmBtn').disabled = !this.checked;">
                I confirm I want to place a new order.
            </label>
            <div style="font-size:0.9rem; color:#666; margin-top:8px;">This action will take you to the ordering screen.</div>
        </div>

        <div class="order-again-modal-actions" style="margin-top:18px; display:flex; gap:12px; justify-content:center;">
            <button class="btn btn-cancel" id="orderAgainConfirmBtn" onclick="proceedOrderAgain()" disabled style="padding:10px 18px;">
                <i class="fa-solid fa-check"></i> Confirm Order Again
            </button>
            <button class="btn btn-white" onclick="closeOrderAgainModal()" style="padding:10px 18px;">
                <i class="fa-solid fa-times"></i> Cancel
            </button>
        </div>
    </div>
</div>


<!-- Mini Games Modals -->
<div id="thaiQuizModal" class="mini-game-modal">
    <div class="mini-game-modal-content">
         <button class="close-btn" onclick="closeThaiQuiz()" aria-label="Close" title="Close">×</button>
        <div id="thaiQuizContent"></div>
    </div>
</div>
<div id="factFictionModal" class="mini-game-modal">
    <div class="mini-game-modal-content">
        <button class="close-btn" onclick="closeFactFiction()" aria-label="Close" title="Close">×</button>
        <div id="factFictionContent"></div>
    </div>
</div>
<div id="catchFoodModal" class="mini-game-modal">
    <div class="mini-game-modal-content" style="padding:12px 8px 16px 8px;max-width:340px;">
        <button class="close-btn" onclick="closeCatchFoodGame()" aria-label="Close" title="Close">×</button>
        <h2>Catch the Thai Food</h2>
        <div id="catchFoodGameArea" style="margin:0 auto;width:300px;height:400px;position:relative;background:#fffbe9;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px #ffc10780;"></div>
        <div style="margin:10px 0 0 0; font-size:1.18em;" id="catchFoodScore"></div>
        <div id="catchFoodStartBtnBox">
          <button onclick="startCatchFoodGame()" style="background:#FF9800;color:#fff;border:none;padding:11px 19px;border-radius:10px;font-weight:700;font-size:1em;cursor:pointer;">Start</button>
        </div>
    </div>
</div>

</body>
</html>