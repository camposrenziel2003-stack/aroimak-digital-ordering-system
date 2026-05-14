<?php
session_start();
include "../config.php";


// ✅ Check if logged in & role = kitchen
if (!isset($_SESSION['staff_id']) || $_SESSION['role'] !== 'kitchen') {
    $showLoginModal = true;
} else {
    $showLoginModal = false;
}
// Fetch categories and items for JS category detection
$categoriesResult = $conn->query("SELECT * FROM categories ORDER BY name ASC");
$menuByCategory = [];
while ($cat = $categoriesResult->fetch_assoc()) {
    $catId = $cat['id'];
    $itemsResult = $conn->query("SELECT * FROM menu_items WHERE category=$catId ORDER BY name ASC");
    $menuByCategory[$cat['name']] = $itemsResult->fetch_all(MYSQLI_ASSOC);
}
// ✅ Handle login POST
$loginError = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_submit'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === "" || $password === "") {
        $loginError = "Please enter username and password.";
    } else {
        $stmt = $conn->prepare("SELECT id, password_hash, role FROM staff_accounts WHERE username=? LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            if (password_verify($password, $row['password_hash'])) {
                if ($row['role'] === 'kitchen') {
                    $_SESSION['staff_id'] = $row['id'];
                    $_SESSION['role'] = $row['role'];
                    $_SESSION['username'] = $username;

                    // ✅ Update last_login
                    $upd = $conn->prepare("UPDATE staff_accounts SET last_login=NOW() WHERE id=?");
                    $upd->bind_param("i", $row['id']);
                    $upd->execute();

                    header("Location: index.php");
                    exit;
                } else {
                    $loginError = "Access denied: only kitchen staff allowed.";
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Kitchen Display</title>
  <link rel="stylesheet" href="index.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">      
  <style>
  /* === Login Modal === */
  .modal-content {
    background: #fff;
    padding: 30px 25px;
    border-radius: 12px;
    width: 360px;
    max-width: 95%;
    box-shadow: 0 12px 28px rgba(0,0,0,0.35);
    text-align: center;
    animation: slideUp 0.3s ease;
  }
  .modal-content h2 {
    margin: 0 0 20px;
    font-size: 22px;
    font-weight: bold;
    color: #333;
  }
.modal-content .input-group {
  position: relative;
  margin-bottom: 15px;
  width: 100%;
}

.modal-content .input-group i {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  color: #888;
  font-size: 16px;
  pointer-events: none;
}

.modal-content input {
  width: 100%;
  padding: 12px 12px 12px 38px; /* space for the icon */
  border: 1px solid #ccc;
  border-radius: 8px;
  font-size: 15px;
  transition: 0.3s;
  box-sizing: border-box;  /* ✅ prevents overflow */
  display: block;
}

  .modal-content input:focus {
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

  @keyframes fadeIn {
    from { background: rgba(0,0,0,0); }
    to { background: rgba(0,0,0,0.75); }
  }
  @keyframes slideUp {
    from { transform: translateY(30px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
  }

.logout-btn {
  position: absolute;
  top: 50%;
  right: 60px;
  transform: translateY(-50%);
  display: inline-block;
  padding: 5px 12px;
  background: #e05500;
  color: #fff;
  font-weight: bold;
  border-radius: 8px;
  text-decoration: none;
  font-size: 14px;
  transition: all 0.3s ease;
  border: 2px solid transparent;
  cursor: pointer;
}

.logout-btn:hover {
  background: #fff;
  color: #e05500;
  border: 2px solid #e05500;
  box-shadow: 0 4px 12px rgba(224, 85, 0, 0.3);
}

.logout-btn:active {
  transform: translateY(-50%) scale(0.97);
}
/* Webkit Browsers: Chrome, Edge, Safari */
::-webkit-scrollbar {
  width: 12px;               /* width of the scrollbar */
  height: 12px;              /* height if horizontal scrollbar appears */
}

::-webkit-scrollbar-track {
  background: #f0f0f0;       /* track color */
  border-radius: 10px;
}

::-webkit-scrollbar-thumb {
  background-color: #ff6600; /* thumb color */
  border-radius: 10px;
  border: 3px solid #f0f0f0; /* padding around thumb */
  transition: background 0.3s;
}

::-webkit-scrollbar-thumb:hover {
  background-color: #e05500; /* darken on hover */
}

/* Firefox */
* {
  scrollbar-width: thin;             /* thin scrollbar */
  scrollbar-color: #ff6600 #f0f0f0; /* thumb color | track color */
}
#kcMessage {
  color: #ffffffff !important;          /* dark teal/blue */
  font-weight: 600;
  font-size: 15px;
  line-height: 1.6;
}

/* Strong, full-screen overlay + centered popup modal
   Paste at the end of index.css (replace any earlier kitchen-confirm rules) */
.kitchen-confirm-overlay {
  position: fixed !important;
  top: 0 !important;
  left: 0 !important;
  right: 0 !important;
  bottom: 0 !important;
  display: none !important;
  align-items: center !important;
  justify-content: center !important;
  z-index: 2147483646 !important; /* very high to ensure on top */
  padding: 28px !important;
  box-sizing: border-box !important;
  pointer-events: none;
}
/* visible state shows overlay and allows pointer events */
.kitchen-confirm-overlay.visible {
  display: flex !important;
  pointer-events: auto !important;
  -webkit-backdrop-filter: blur(1px);
  backdrop-filter: blur(1px);
}

/* modal card: centered and hidden until overlay.visible */
.kitchen-confirm {
  width: 640px;
  max-width: calc(100% - 56px);
  margin: 0 auto;
  transform: translateY(8px);
  opacity: 0;
  transition: transform 180ms cubic-bezier(.2,.9,.2,1), opacity 180ms linear;
  will-change: transform, opacity;
  pointer-events: auto;
  /* dark card background (override previous orange) */
  background: #ffb34fff !important;
  border-radius: 14px !important;
  color: #e6eef8 !important;
  box-shadow: 0 38px 80px rgba(2,6,23,0.6), inset 0 1px 0 rgba(255,255,255,0.02) !important;
  overflow: hidden;
  border: 1px solid rgba(255,255,255,0.03) !important;
  display: flex;
  flex-direction: column;
}

/* when overlay visible, animate the card in */
.kitchen-confirm-overlay.visible .kitchen-confirm {
  transform: translateY(0);
  opacity: 1;
}

/* header / divider / body / actions keep previous stylings (ensure they exist) */
.kitchen-confirm .kc-header { padding: 18px 22px 16px 22px; background: linear-gradient(180deg, rgba(255,255,255,0.02), transparent); display:flex; align-items:center; }
.kitchen-confirm .kc-title { font-weight:800; font-size:16px; color:#f3f7fb; letter-spacing:0.2px; }
.kitchen-confirm .kc-divider { height: 1px; background: linear-gradient(90deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01)); }
.kitchen-confirm .kc-body { padding: 22px; font-size: 14px; color: #cbd6e6; line-height:1.6; min-height: 84px; }
.kitchen-confirm .kc-actions { display:flex; gap:14px; justify-content:flex-end; padding: 18px 22px 22px 22px; }

/* Keep your colorful button styles — example (ensure these are present AFTER older rules) */
.kc-btn { cursor:pointer; border:none; padding:10px 22px; border-radius:999px; font-weight:800; font-size:14px; transition:transform .12s ease, box-shadow .12s ease, filter .12s ease; outline:none; }
.kc-btn-secondary { background: linear-gradient(180deg,#6eb8f5ff 0%, #50aefaff 100%) !important; color:#fff !important; box-shadow: 0 12px 36px rgba(155,89,182,0.20), inset 0 -3px 8px rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.06) !important; padding:8px 18px; font-weight:700; }
.kc-btn-secondary:hover { transform: translateY(-3px); filter: brightness(1.05); box-shadow: 0 18px 46px rgba(155,89,182,0.28), inset 0 -4px 10px rgba(255,255,255,0.06); }
.kc-btn-primary { background: linear-gradient(180deg,#9dee68ff 0%, #89f044ff 100%) !important; color:#fff !important; box-shadow:0 16px 48px rgba(58,123,213,0.22), inset 0 -4px 12px rgba(255,255,255,0.6); border:1px solid rgba(255,255,255,0.14) !important; padding:10px 22px; font-weight:800; }
.kc-btn-primary:hover { transform: translateY(-3px); filter: brightness(1.03); box-shadow: 0 20px 60px rgba(58,123,213,0.30), inset 0 -5px 14px rgba(255,255,255,0.7); }

/* responsive tweaks */
@media (max-width: 760px) {
  .kitchen-confirm { width: 95% !important; border-radius: 12px; }
  .kc-body { padding: 16px; }
  .kc-actions { padding: 12px 16px; gap:10px; }
  .kc-btn-primary { padding: 8px 18px; }
}
  </style>
</head>
<body>
  
<?php if ($showLoginModal): ?>
<div class="modal" style="display:flex;align-items:center;justify-content:center;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;">
    <div class="modal-content">
      <h2>Kitchen Login</h2>
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


  <!-- KDS content -->
  <div class="orders-container <?= $showLoginModal ? 'blurred' : '' ?>" id="ordersContainer"></div>
  <div class="header">
  Kitchen Display
<a href="logout.php" class="logout-btn">Logout</a>
    
</div>
<!-- Kitchen Confirm Modal (replace existing kitchenConfirmOverlay block) -->
<div id="kitchenConfirmOverlay" class="kitchen-confirm-overlay" aria-hidden="true" role="dialog" aria-modal="true">
  <div class="kitchen-confirm" role="document" aria-labelledby="kcTitle" aria-describedby="kcMessage">
    <div class="kc-header">
      <div id="kcTitle" class="kc-title">Mark Additional Batch</div>
    </div>

    <div class="kc-divider" aria-hidden="true"></div>

    <div id="kcMessage" class="kc-body">
      Mark this additional batch as Ready to Serve?
    </div>

    <div class="kc-actions">
      <button id="kcCancelBtn" class="kc-btn kc-btn-secondary" type="button">No</button>
      <button id="kcOkBtn" class="kc-btn kc-btn-primary" type="button">Yes, Ready</button>
    </div>
  </div>
</div>
<script>
/* Client-side KDS script
   - updateOrderStatus now sends credentials so PHP receives the session cookie
   - loadOrders periodically fetches latest orders
   - menuByCategory / itemNameToCategory used to decide what to show per item
*/
const menuByCategory = <?php echo json_encode($menuByCategory); ?> || {};
const itemNameToCategory = {};
for (const cat in menuByCategory) {
    (menuByCategory[cat] || []).forEach(mi => {
        itemNameToCategory[mi.name] = cat;
    });
}

function formatElapsedTime(startTime) {
  const now = Date.now();
  const diffMs = now - startTime;
  const diffSec = Math.floor(diffMs / 1000);
  const hours = Math.floor(diffSec / 3600);
  const mins = Math.floor((diffSec % 3600) / 60);
  const secs = diffSec % 60;
  const formattedMins = mins.toString().padStart(2, '0');
  const formattedSecs = secs.toString().padStart(2, '0');
  if (hours > 0) {
    return `${hours}h:${formattedMins}m:${formattedSecs}s ago`;
  } else {
    return `${mins}m:${formattedSecs}s ago`;
  }
}

function safeEncode(val) {
  return encodeURIComponent(String(val ?? ''));
}
function safeDecode(val) {
  try { return decodeURIComponent(String(val)); }
  catch (e) { return String(val); }
}

function createOrderHtml(order) {
  let status = order.status;
  let buttonsHtml = '';

  // For additional batches: DO NOT use inline onclick (can cause encoding confusion).
  if (status === 'Preparing') {
    if (order.is_additional_batch) {
      buttonsHtml = `
        <div class="status-btns">
          <button class="btn ready-to-serve-btn ready-add-btn"
            data-group-id="${safeEncode(order.order_group_id)}"
            data-card-key="${safeEncode(order.card_key)}">
            Ready to Serve
          </button>
        </div>`;
    } else {
      // Main orders can keep inline call or data attrs. We'll keep the existing simple call:
      buttonsHtml = `
        <div class="status-btns">
          <button class="btn ready-to-serve-btn" onclick="updateOrderStatus(${order.order_id}, 'Completed')">
            Ready to Serve
          </button>
        </div>`;
    }
  }

  let allergenHtml = '';
  if (order.allergens || order.allergen_note) {
    allergenHtml = `
      <div class="allergen-box">
        <strong>⚠ Allergens:</strong> ${order.allergens ? order.allergens : "None"}
        ${order.allergen_note ? `<br><em>Note: ${order.allergen_note}</em>` : ""}
      </div>`;
  }

  let isTakeout = order.takeout === 1;
  let orderTypeClass = isTakeout ? "takeout" : "dinein";
  let orderTypeText = isTakeout ? "Take-out" : "Dine-in";

  let extraBadge = '';
  if (order.is_additional_batch) {
    extraBadge = `<span style="color:#b52;font-weight:700;margin-left:8px;">(Additional Order)</span>`;
  }

  const safeCardId = String(order.card_key).replace(/[^a-zA-Z0-9_\-]/g, '_');
  let placedTime = new Date(order.created_at || order.created_at).getTime();
  let elapsed = formatElapsedTime(placedTime);
  let elapsedHtml = '';
  if (status !== 'Completed') {
    elapsedHtml = `<div class="elapsed-time">⏱ ${elapsed}</div>`;
  }

  // Items
  let itemsHtml = '<ul style="padding-left:16px;margin:8px 0;">';
  if (order.items && Array.isArray(order.items)) {
    itemsHtml += order.items.map(item => {
      let cat = itemNameToCategory[item.item_name] || "";
      let qty = item.quantity ? item.quantity : 1;
      let priceValue = '';
      if (item.price !== undefined && item.price !== null && item.price !== '') {
        let numPrice = Number(item.price);
        priceValue = !isNaN(numPrice) ? numPrice.toFixed(2) : item.price;
      }
      let nameLine = `<strong>${qty}x ${item.item_name}</strong>`;
      if (item.is_additional) {
        nameLine += ' <span style="color:green;font-weight:700;margin-left:6px;">(ADDITIONAL ORDER)</span>';
      }

      let extraFields = '';
      if (cat !== "Dessert" && cat !== "Drinks") {
        let spiceText = item.spice_text ? item.spice_text : (() => {
          let spiceLabels = ['No Spice', 'Light', 'Moderate', 'Spicy', 'Extra'];
          let spiceNum = parseInt(item.spice_level);
          return (isNaN(spiceNum) || spiceNum < 0 || spiceNum > 4) ? 'No Spice' : spiceLabels[spiceNum] || 'No Spice';
        })();
        extraFields += `<div style='color:#d72c0d;'>Spice Level: ${spiceText}</div>`;
        let allergenInfo = `<div style='color:#1976d2;'>Allergens: ${item.allergens ? item.allergens : 'None'}</div>`;
        let noteInfo = `<div style='color:#388e3c;'>Notes: ${item.allergen_note ? item.allergen_note : ''}</div>`;
        extraFields += `<div>${allergenInfo}${noteInfo}</div>`;
      }
      return `
        <li style='margin-bottom:10px;'>
          ${nameLine} <span style='font-weight:normal;'>₱${priceValue}</span>
          ${extraFields}
        </li>`;
    }).join('');
  } else {
    itemsHtml += '<li>No items</li>';
  }
  itemsHtml += '</ul>';

  return `
    <div class="order" id="order-${safeCardId}">
      <div class="order-header ${orderTypeClass}">
        ${orderTypeText} | Order #${order.order_id} ${extraBadge}
      </div>
      <div class="order-body">
        <strong>Table ${order.table_number} - ${order.customer_name}</strong>
        ${itemsHtml}
        ${allergenHtml}
        <span class="status-text status-${(status || '').toLowerCase()}">Status: ${status}</span>
        ${elapsedHtml}
        ${buttonsHtml}
      </div>
    </div>`;
}

// === STATUS UPDATE for parent orders ===
function updateOrderStatus(orderId, newStatus) {
  const formData = new FormData();
  formData.append('orderId', orderId);
  formData.append('newStatus', newStatus);

  fetch('update_status.php', {
    method: 'POST',
    body: formData,
    credentials: 'same-origin'
  })
  .then(response => response.json())
  .then(data => {
    if (data.success) {
      loadOrders();
    } else {
      console.error('Failed to update status:', data.error);
      alert('Error: ' + data.error);
    }
  })
  .catch(error => console.error('Error:', error));
}
function showKitchenConfirmModal(message, title = "Confirm", okText = "OK", cancelText = "Cancel") {
  return new Promise((resolve) => {
    const overlay = document.getElementById('kitchenConfirmOverlay');
    const msgEl = document.getElementById('kcMessage');
    const titleEl = document.getElementById('kcTitle');
    const okBtn = document.getElementById('kcOkBtn');
    const cancelBtn = document.getElementById('kcCancelBtn');

    if (!overlay || !okBtn || !cancelBtn || !msgEl || !titleEl) {
      // fallback to native confirm if modal missing
      resolve(confirm(message));
      return;
    }

    titleEl.textContent = title;
    msgEl.innerHTML = message;
    okBtn.textContent = okText;
    cancelBtn.textContent = cancelText;

    overlay.classList.add('visible');
    overlay.setAttribute('aria-hidden', 'false');
    document.body.classList.add('kc-modal-open');
    okBtn.focus();

    const cleanup = () => {
      overlay.classList.remove('visible');
      overlay.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('kc-modal-open');
      okBtn.removeEventListener('click', onOk);
      cancelBtn.removeEventListener('click', onCancel);
      document.removeEventListener('keydown', onKey);
      overlay.removeEventListener('click', onOverlayClick);
    };

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
    overlay.addEventListener('click', onOverlayClick);
  });
}
// Robust readyAdditionalBatch
async function readyAdditionalBatch(orderGroupId, cardKey) {
  // Show modal instead of native confirm
  const message = "Mark this additional batch as Ready to Serve?";
  const ok = await showKitchenConfirmModal(message, "Mark Additional Batch", "Yes, Ready", "No");
  if (!ok) return;

  // The rest of the original function logic follows (keeps decoding/validation)
  const groupRaw = String(orderGroupId || '');
  const cardRaw  = String(cardKey || '');

  const group = safeDecode(groupRaw);
  const card  = safeDecode(cardRaw);

  // Try to find any 14-digit timestamp anywhere in card or group (be liberal)
  let batch_key = null;
  let m = (card || '').match(/(\d{14})/);
  if (m) batch_key = m[1];
  if (!batch_key) {
    m = (group || '').match(/(\d{14})/);
    if (m) batch_key = m[1];
  }

  // If still not found, handle "::add::active" logic
  if (!batch_key && (card || '').includes('::add::active')) {
    batch_key = 'active';
  }

  if (!batch_key) {
    // Using same UI feedback as before (native alert is fine) — you can replace with a toast if you have one
    alert('Failed to determine batch key for this additional batch. See console for details.');
    console.error('readyAdditionalBatch: cannot extract batch_key from', { group, card });
    return;
  }

  // Send POST to update_batch_status.php
  const fd = new FormData();
  fd.append('order_group_id', group);
  fd.append('batch_key', batch_key);
  fd.append('action', 'ready');

  try {
    const res = await fetch('update_batch_status.php', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    });
    const text = await res.text();
    let json;
    try { json = JSON.parse(text); } catch (e) {
      console.error('update_batch_status.php returned non-json:', text);
      alert('Server error: invalid response. See console.');
      return;
    }
    if (json && json.success) {
      loadOrders();
    } else {
      console.error('Batch update failed', json);
      alert('Failed to mark batch ready: ' + (json.error || json.message || 'Unknown'));
    }
  } catch (err) {
    console.error('Network error while calling update_batch_status.php', err);
    alert('Network error: ' + err);
  }
}

// Event delegation for Ready to Serve buttons (works for dynamically created buttons)
document.addEventListener('DOMContentLoaded', () => {
  const container = document.getElementById("ordersContainer");
  if (container) {
    container.addEventListener('click', (e) => {
      const btn = e.target.closest && e.target.closest('.ready-add-btn');
      if (!btn) return;
      const rawGroup = btn.getAttribute('data-group-id');
      const rawCard  = btn.getAttribute('data-card-key');
      const group = rawGroup ? rawGroup : null;
      const card  = rawCard  ? rawCard  : null;
      if (!group || !card) {
        console.error('ready-add-btn missing data attributes', { rawGroup, rawCard });
        alert('Internal error: missing batch data. Check console.');
        return;
      }
      // Use safeDecode inside readyAdditionalBatch so we pass the encoded attributes as-is
      readyAdditionalBatch(group, card);
    });
  }

  // initial load + polling
  loadOrders();
  const incomingBtn = document.querySelector("footer .status-btn:nth-child(1)");
  if (incomingBtn) incomingBtn.classList.add("active");
  setInterval(loadOrders, 2000);
});

// === LOAD / RENDER ===
let activeFilter = "Incoming";
let allOrders = [];

function loadOrders() {
  fetch("fetch_orders.php", { credentials: 'same-origin' })
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        allOrders = data.data;
        renderOrders();
      } else {
        console.error("Failed to fetch orders:", data.error);
      }
    })
    .catch(err => console.error("Error loading orders:", err));
}

function renderOrders() {
    const container = document.getElementById("ordersContainer");
    container.innerHTML = "";

    let filtered = allOrders.filter(order => {
        if (activeFilter === "Incoming") {
            return order.status === "Pending" || order.status === "Preparing";
        }
        if (activeFilter === "All") return true;
        if (order.status === activeFilter) return true;
        if (activeFilter === "Dine-in" && !order.takeout) return true;
        if (activeFilter === "Take-out" && order.takeout) return true;
        return false;
    });

    // build HTML string once, faster
    let html = '';
    filtered.forEach(order => {
        html += createOrderHtml(order);
    });
    container.innerHTML = html;
}

function filterOrders(filter) {
    activeFilter = filter;
    renderOrders();

    document.querySelectorAll("footer .status-btn").forEach(btn => btn.classList.remove("active"));
    const activeBtn = Array.from(document.querySelectorAll("footer .status-btn"))
        .find(btn => btn.textContent.toLowerCase().includes(filter.toLowerCase()));
    if (activeBtn) activeBtn.classList.add("active");
}
</script>

<script>
if (<?= $showLoginModal ? 'true' : 'false' ?>) {
    document.body.style.overflow = 'hidden';  // block scrolling
} else {
    document.body.style.overflow = '';        // allow scrolling if logged in
}
</script>

</body>
</html>
