<?php
session_start();
if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

include "config.php";
$lowStockMenuItems = [];
$lowStockResult = $conn->query("
    SELECT name, price, price_solo, price_sharing, category, image, stock
    FROM menu_items
    WHERE (archived IS NULL OR archived = 0)
      AND stock > 0
    ORDER BY stock ASC, name ASC
    LIMIT 5 
");
while ($row = $lowStockResult->fetch_assoc()) $lowStockMenuItems[] = $row;

// ================== Stats & Previews ===================
$todayRes = $conn->query("SELECT COUNT(*) total_orders, SUM(total_price) total_revenue FROM orders WHERE DATE(created_at) = CURDATE() AND status != 'Canceled' AND (archived IS NULL OR archived = 0)");
$todaySum = $todayRes->fetch_assoc();
$orders_today = $todaySum['total_orders'] ?? 0;
$revenue_today = $todaySum['total_revenue'] ?? 0;

$popularDishesResult = $conn->query("
    SELECT order_items.item_name, SUM(order_items.quantity) AS total_qty, menu_items.image
    FROM order_items
    LEFT JOIN menu_items ON order_items.item_name = menu_items.name
    GROUP BY order_items.item_name
    ORDER BY total_qty DESC
    LIMIT 3
");
$popularDishes = [];
while ($row = $popularDishesResult->fetch_assoc()) $popularDishes[] = $row;

// Activity log preview
$activityResult = $conn->query("SELECT * FROM order_activity_log WHERE role = 'cashier' ORDER BY created_at DESC LIMIT 10");
$activities = [];
if ($activityResult && $activityResult->num_rows > 0) {
    while ($row = $activityResult->fetch_assoc()) $activities[] = $row;
}

// Menu
$menuResult = $conn->query("
    SELECT menu_items.*, categories.name AS category_name 
    FROM menu_items 
    LEFT JOIN categories ON menu_items.category = categories.id
");
$menuItems = [];
if ($menuResult->num_rows > 0) {
    while ($row = $menuResult->fetch_assoc()) $menuItems[] = $row;
}

// Recent feedback, last 3
$feedbackResult = $conn->query(
    "SELECT o.customer_name, f.rating, f.comment, f.created_at 
        FROM feedback f 
        LEFT JOIN orders o ON f.order_group_id = o.order_group_id 
        ORDER BY f.created_at DESC LIMIT 3"
);
$recentFeedbacks = [];
while ($fb = $feedbackResult->fetch_assoc()) $recentFeedbacks[] = $fb;

// Cur day's live orders preview
$orderPreview = [];
$ordPrevRes = $conn->query("SELECT * FROM orders WHERE status NOT IN ('Served','Canceled') AND (archived IS NULL OR archived = 0) AND DATE(created_at) = CURDATE() ORDER BY id DESC LIMIT 5");
while ($row = $ordPrevRes->fetch_assoc()) $orderPreview[] = $row;

// Fetch admin info
$adminId = $_SESSION['admin']; 
$stmt = $conn->prepare("SELECT username, profile_pic FROM admins WHERE id=?");
$stmt->bind_param("i", $adminId);
$stmt->execute();
$result = $stmt->get_result();
$admin = $result->fetch_assoc();
$profilePic = !empty($admin['profile_pic']) ? $admin['profile_pic'] : 'default.png';
$username = $admin ? htmlspecialchars($admin['username']) : 'Admin';

// For mini charts: orders/revenue last 7d
$chartLabels = $chartOrderCounts = $chartRevenue = [];
for($i=6;$i>=0;$i--) {
    $d = date('Y-m-d',strtotime("-$i days"));
    $chartLabels[] = date('M d',strtotime($d));
    $resA = $conn->query("SELECT COUNT(*) as cnt, COALESCE(SUM(total_price),0) as rev FROM orders WHERE DATE(created_at)='$d' AND status !='Canceled' AND (archived IS NULL OR archived = 0)");
    $r = $resA->fetch_assoc(); $chartOrderCounts[] = (int)$r['cnt']; $chartRevenue[] = (float)$r['rev'];
}

// Feedback summary
$feedbackStats = [
    'total' => 0,
    'avg'   => 0,
    'counts' => [1=>0,2=>0,3=>0,4=>0,5=>0]
];
$res = $conn->query("SELECT rating, COUNT(*) as c FROM feedback GROUP BY rating");
$totalRatings = 0; $sumRatings = 0;
while ($r = $res->fetch_assoc()) {
    $feedbackStats['counts'][$r['rating']] = $r['c'];
    $totalRatings += $r['c'];
    $sumRatings   += $r['rating'] * $r['c'];
}
$feedbackStats['total'] = $totalRatings;
$feedbackStats['avg']   = $totalRatings ? round($sumRatings / $totalRatings, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin Dashboard</title>
<link rel="stylesheet" href="index.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
/* Dashboard Responsive Cards & Mini Charts */
.dashboard-main-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px,1fr));
    gap: 32px 28px;
    margin-left: 230px;
    margin-top: 97px;
    padding: 28px 40px 28px 24px;
    max-width: 1850px;
    box-sizing: border-box;
}
@media (max-width:1200px) { .dashboard-main-grid { grid-template-columns: 1fr; padding:18px 8vw; } }
.stat-cards-row { display: flex; gap: 25px; margin-bottom: 24px; flex-wrap: wrap; }
.stat-card {
    flex:1 1 190px; min-width:200px; border-radius: 18px;
    background: linear-gradient(120deg,#faf7f4 75%,#fffde6 100%);
    box-shadow:0 5px 18px #f0c85f19; display: flex; align-items: center;
    padding: 22px 34px 20px 22px; position: relative; margin-bottom: 5px; gap: 14px;
}
.stat-card .icon { font-size: 2.35em; flex-shrink: 0; padding:8px; border-radius:9px;}
.stat-card.orders .icon { background: #ffe19d; color: #e67e22;}
.stat-card.revenue .icon { background: #c5ffc8; color:#29b032;}
.stat-card.dishes .icon { background:#ffd8e1;color:#ee285d;}
.stat-card.feedback .icon { background:#ffe0b2; color:#ff6600;}
.stat-card h3 { margin: 0 0 6px 0; color: #ae7702; font-size: 1em; font-weight: 600; letter-spacing: .5px;}
.stat-card .number { font-size: 2.1em; font-weight: 700; color: #223; letter-spacing: -0.5px;}
.summary-card { background: #fff;border-radius: 17px;box-shadow: 0 3px 18px #e67d2218;padding: 28px 22px 22px; margin-bottom: 18px; min-height: 265px; display: flex;flex-direction: column;}
.summary-card h2 { color: #e67e22; font-size: 1.22em; font-weight: bold; margin-bottom: 10px;}
.popular-dishes-minirow {display: flex;gap: 15px;margin-top: 6px;}
.popular-mini-card {background: #fff9f3;border-radius: 13px;min-width: 0; min-height: 0;box-shadow: 0 2px 12px #e67e2240;display: flex;align-items: center;gap: 10px;flex: 1 1 0;overflow: hidden;}
.popular-mini-card img {width: 48px; height: 48px; border-radius: 12px; background: #fff; object-fit: cover; border: 1.5px solid #fed7b5;}
.popular-mini-card .mini-info {flex:1;margin-left: 5px;}
.popular-mini-card .mini-rank { font-weight:bold;color:#e67e22;font-size:1.1em;}
.popular-mini-card .mini-name { font-weight:600; color: #555;}
.popular-mini-card .mini-sales { font-size:0.97em; color:#b36d0d;}
.popular-dishes-footer {margin-top:10px;text-align:right;}
.popular-dishes-footer a {background:#ff6600;color:#fff; padding:7px 18px; border-radius:8px; font-weight:500; text-decoration:none; font-size:0.98em;transition:background .13s;}
.popular-dishes-footer a:hover {background:#d95f00;}
.feedback-cardbox {display: flex;flex-direction:column;gap:12px;}
.feedback-row {background: #fffbe9; border-radius: 9px;padding:13px 17px;margin-bottom:1px; box-shadow: 0 2px 10px #e67e2212; display: flex;align-items:flex-start;gap:10px;}
.feedback-stars { color:#f9b120; margin-right:7px; font-size:1em;}
.feedback-meta { color:#666; font-size:0.97em; margin-bottom:2px;}
.feedback-comment { color:#333; font-style:italic;}
.feedback-no { color:#bbb;padding:9px;font-style:italic;font-size:1em;}
.orders-prev-table { width:100%; border-collapse:collapse;}
.orders-prev-table th, .orders-prev-table td {padding:7px 10px; border:none;font-size:0.97em;}
.orders-prev-table th { font-weight:600; color:#e67e22;background:#fffbe9;}
.orders-prev-table tr:not(:last-child) td { border-bottom:1px solid #f7eae0;}
.orders-prev-table td { color:#333; max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.orders-prev-footer {margin-top:7px;}
.orders-prev-footer a {text-decoration:none;padding:6px 15px;background:#09be5b;color:#fff; border-radius:7px;font-weight:500;font-size:0.97em;transition:background .11s;}
.orders-prev-footer a:hover {background:#248a4a;}
.latest-act-list { margin:0; padding-left:1em;}
.latest-act-list li { margin-bottom:4px; color:#676;}
.latest-act-meta { color:#b37e19;font-size:0.93em;}
.mini-chart { width:50%; min-height:130px;}
.mini-charts-section { display:flex;gap:18px;}
.low-stock-highlight {color: #cc4700; font-weight:bold;}
@media (max-width: 900px) { .dashboard-main-grid { margin-left:66px; padding:15px; }}
.loader { display:inline-block; width:18px;height:18px;border:3px solid #ffc96b;border-radius:50%;border-top:3px solid #e67e22;animation:spin 1s linear infinite;}
@keyframes spin { 0% { transform:rotate(0deg);} 100% { transform:rotate(360deg);} }
.popular-summary-card {
    box-shadow: 0 4px 18px #fae0c04e!important;
    background: linear-gradient(130deg, #fffbe9 50%, #fff7f0 100%)!important;
}

.popdish-minirow {
    display: flex;
    gap: 18px;
    margin: 14px 0 0 0;
    justify-content: flex-start;
}

.popdish-mini-card {
    background: #fffbf8;
    border-radius: 12px;
    box-shadow: 0 1.5px 8px #e67e220e;
    display: flex;
    align-items: center;
    padding: 10px 15px;
    gap: 11px;
    min-width: 0;
    flex: 1 1 0;
    position: relative;
    transition: box-shadow 0.15s, transform 0.13s;
    border: 1.5px solid #ffefd6;
}
.popdish-mini-card:hover, .popdish-mini-card:focus-within {
    box-shadow: 0 4px 18px #e67e2240;
    transform: translateY(-2px) scale(1.03);
    z-index:2;
}
.popdish-mini-card img {
    width: 60px;
    height: 60px;
    border-radius: 13px;
    object-fit: cover;
    background: #fff;
    border: 2px solid #ffdca8;
    box-shadow: 0 1px 5px #e67e2255;
}
.popdish-info { flex: 1; overflow: hidden; }
.popdish-rank-box {
    font-size: 1.4em;
    font-weight: bold;
    color: #ff872b;
    background: #fff9ef;
    border-radius: 50%;
    width: 34px; height: 34px;
    display:flex;align-items:center;justify-content:center;
    margin-right:7px;
    border: 1.5px solid #ffe1b0;
    box-shadow:0 2px 5px #fff4da40;
}
.popdish-name {
    font-weight: 600;
    font-size: 1.09em;
    color: #e67e22;
    text-overflow: ellipsis;
    overflow: hidden;
    white-space: nowrap;
}
.popdish-count {
    color: #a67b22;
    font-size: 0.98em;
    margin-top: 2px;
}
@media (max-width:700px){
  .popdish-minirow { flex-direction: column; gap: 9px;}
  .popdish-mini-card { flex:1 1 99%; }
}
</style>
</head>
<body>

<!-- Sidebar (keep your sidebar structure) -->
<div class="sidebar">
  <img src="logo.png" class="logo" alt="Logo">
  <div class="sidebar-scroll">
    <nav>
        <a href="index.php" class="active"><i class="fa-solid fa-chart-line"></i> Dashboard</a>
        <a href="order.php"><i class="fa-solid fa-receipt"></i> Orders</a>
        <a href="popular_dishes.php" class="popular-link<?php if(basename($_SERVER['PHP_SELF'])=='popular_dishes.php') echo ' active'; ?>"><i class="fa-solid fa-fire"></i> Popular Dishes</a>
        <a href="menu.php"><i class="fa-solid fa-utensils"></i> Menu Availability</a>
        <a href="add_item.php"><i class="fa-solid fa-plus-circle"></i> Add Item</a>
        <a href="promo.php"><i class="fa-solid fa-bullhorn"></i> Add Promo</a>
        <a href="feedback.php"><i class="fa-solid fa-star"></i> Customer Feedback</a>
        <a href="assign_table.php"><i class="fa-solid fa-tablet-screen-button"></i> Assign Table</a>
        <a href="assign_roles.php"><i class="fa-solid fa-user-gear"></i> Assign Roles</a>
        <a href="order_log.php"><i class="fa-solid fa-list-check"></i> Order Activity Log</a>
        <a href="archived.php"><i class="fa-solid fa-box-archive"></i> Archived</a>
    </nav>
  </div>
</div>

<!-- Header -->
<header class="header">
    <div class="header-left">
      <h1>Admin Dashboard</h1>
    </div>
    <div class="header-right">
        <div class="profile-menu" onclick="toggleMenu()">
            <img id="headerProfilePic" src="uploads/<?= htmlspecialchars($profilePic) ?>" alt="Admin" class="profile-pic">
            <div class="menu-content" id="menuContent">
                <a href="#" onclick="openProfileModal(); return false;">
                    <i class="fa-solid fa-pen"></i> Edit Profile
                </a>
                <a href="logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
            </div>
        </div>
        <a href="cashier/index.php" class="cashier-btn">
            <i class="fa-solid fa-cash-register"></i> Cashier View
        </a>
    </div>
</header>

<!-- Floating Profile Modal -->
<div id="profileOverlay" class="profile-overlay" onclick="closeProfile(event)" style="display:none;">
  <div class="profile-card" onclick="event.stopPropagation()">
    <button class="close-btn" onclick="closeProfile()">✖</button>
    <div id="profileContent"><p style="text-align:center;">Loading...</p></div>
  </div>
</div>

<!-- =======[ Main Dashboard Content ]======= -->
<div class="dashboard-main-grid">
    <div style="grid-column:1/-1;">
        <div class="stat-cards-row">
            <div class="stat-card orders"><span class="icon"><i class="fa-solid fa-clipboard-list"></i></span>
                <div><div class="number" id="ordersTodayCount"><?= $orders_today ?></div><h3>Orders Today</h3></div>
            </div>
            <div class="stat-card revenue"><span class="icon"><i class="fa-solid fa-coins"></i></span>
                <div><div class="number" id="revenueTodayCount">₱<?= number_format($revenue_today,2) ?></div><h3>Revenue Today</h3></div>
            </div>
            <div class="stat-card dishes"><span class="icon"><i class="fa-solid fa-utensils"></i></span>
                <div><div class="number"><?= count($menuItems) ?></div><h3>Total Menu Items</h3></div>
            </div>
            <div class="stat-card feedback"><span class="icon"><i class="fa-solid fa-star"></i></span>
                <div><div class="number"><?= $feedbackStats['avg'] ?>/5</div><h3>Feedback (Avg)</h3></div>
            </div>
        </div>
    <!-- Popular Dishes -->
<div class="summary-card popular-summary-card" style="min-width:330px;">
    <h2 style="margin-bottom:12px;"><i class="fa-solid fa-fire" style="color:#ff6600;margin-right:9px;"></i>Popular Dishes</h2>
    <?php if ($popularDishes): ?>
    <div class="popdish-minirow">
        <?php foreach ($popularDishes as $i => $dish): ?>
        <div class="popdish-mini-card">
            <div class="popdish-rank-box">#<?= ($i+1) ?></div>
            <img src="<?= !empty($dish['image']) ? 'uploads/' . htmlspecialchars($dish['image']) : 'default-dish.png' ?>" alt="<?= htmlspecialchars($dish['item_name']) ?>">
            <div class="popdish-info">
                <div class="popdish-name"><?= htmlspecialchars($dish['item_name']) ?></div>
                <div class="popdish-count"><i class="fa-solid fa-chart-bar"></i> <?= (int)$dish['total_qty'] ?> ordered</div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
        <div class="popular-mini-card"><em>No data yet</em></div>
    <?php endif; ?>
    <div class="popular-dishes-footer"><a href="popular_dishes.php">View All &gt;</a></div>
</div>

<!-- Current Orders: Move this block immediately AFTER the Popular Dishes card -->
<div class="summary-card" style="min-width:340px;">
    <h2>Current Orders</h2>
    <table class="orders-prev-table">
        <tr>
            <th>#</th>
            <th>Table</th>
            <th>Customer</th>
            <th>Item(s)</th>
            <th>Status</th>
        </tr>
        <?php foreach($orderPreview as $o): ?>
        <tr>
            <td><?= $o['id'] ?></td>
            <td><?= htmlspecialchars($o['table_number']) ?></td>
            <td><?= htmlspecialchars($o['customer_name']) ?></td>
            <td><?= htmlspecialchars($o['item_name']) ?></td>
            <td><?= htmlspecialchars($o['status']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$orderPreview): ?>
        <tr><td colspan="5"><span style="color:#bbb;">No active orders</span></td></tr>
        <?php endif; ?>
    </table>
    <div class="orders-prev-footer"><a href="order.php">View All Orders</a></div>
</div>
<!-- Low Stock Menu Items -->
<div class="summary-card" style="min-width:340px;">
    <div style="display:flex;align-items:center;justify-content:space-between;">
        <h2 style="margin-bottom:0;">
            <i class="fa-solid fa-triangle-exclamation" style="color:#cc4700;margin-right:8px;"></i>
            Menu Items with Low Stock
        </h2>
        <a href="menu.php" class="low-stock-btn" style="background:#ff6600;color:#fff;padding:7px 18px;border-radius:8px;font-weight:500;text-decoration:none;font-size:0.98em;transition:background .13s;display:inline-block;margin-left:10px;">
            Go to Menu
        </a>
    </div>
    <table class="orders-prev-table" style="margin-top:14px;">
        <tr>
            <th>Image</th>
            <th>Name</th>
            <th>Price</th>
            <th>Stock</th>
        </tr>
        <?php if ($lowStockMenuItems): ?>
            <?php foreach($lowStockMenuItems as $item): ?>
            <tr>
                <td>
                  <?php if (!empty($item['image'])): ?>
                    <img src="uploads/<?= htmlspecialchars($item['image']) ?>" alt="<?= htmlspecialchars($item['name']) ?>" style="width:34px;height:34px;border-radius:6px;object-fit:cover;">
                  <?php else: ?>
                    <span style="color:#ccc;">—</span>
                  <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($item['name']) ?></td>
                <td>
                  <?php
                  if (isset($item['price_solo']) && $item['price_solo'] != '') {
                      echo 'Solo: ₱'.number_format($item['price_solo'],2).'<br>Sharing: ₱'.number_format($item['price_sharing'],2);
                  } else {
                      echo '₱'.number_format($item['price'],2);
                  }
                  ?>
                </td>
                <td>
                    <span style="color:#cc4700;font-weight:700;">
                      <?= (int)$item['stock'] ?>
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr><td colspan="4" style="text-align:center;">No low stock items.</td></tr>
        <?php endif; ?>
    </table>
</div>
    <!-- Mini charts: Orders & Revenue trend (last 7 days) -->
    <div class="mini-charts-section" style="margin-bottom:30px;">
        <div class="summary-card" style="flex:1;">
            <h2 style="margin-bottom:11px;font-weight:600;">Orders Trend (7 Days)</h2>
            <canvas id="ordersMiniChart" class="mini-chart"></canvas>
        </div>
        <div class="summary-card" style="flex:1;">
            <h2 style="margin-bottom:11px;font-weight:600;">Revenue Trend (7 Days)</h2>
            <canvas id="revenueMiniChart" class="mini-chart"></canvas>
        </div>
    </div>
</div>
    
<!-- Chart scripts and stat animation -->
<script>
function animateCount(id, endValue, prefix="") {
    const el = document.getElementById(id);
    if (!el) return;
    let start = 0;
    endValue = typeof endValue === "number" ? endValue : parseFloat(endValue.replace(/[₱,]/g,''));
    if (isNaN(endValue)) { el.textContent = prefix + endValue; return; }
    let step = endValue/34; let cur = 0;
    function frame() {
        cur += step;
        if (cur >= endValue) { el.textContent = prefix+endValue.toLocaleString(undefined,{maximumFractionDigits:2}); }
        else {
            el.textContent = prefix+cur.toLocaleString(undefined,{maximumFractionDigits:2});
            requestAnimationFrame(frame);
        }
    }
    frame();
}
window.addEventListener("DOMContentLoaded", ()=>{
    animateCount('ordersTodayCount', <?= (int)$orders_today ?>, "");
    animateCount('revenueTodayCount', <?= (float)$revenue_today ?>, "₱");
});

// Chart mini trends
new Chart(document.getElementById('ordersMiniChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($chartLabels) ?>,
        datasets:[{
            label: 'Orders',
            data: <?= json_encode($chartOrderCounts) ?>,
            borderColor: '#e67e22', backgroundColor:'rgba(230,126,34,0.18)', fill: true, tension:0.45,
            pointRadius:3,pointBackgroundColor:"#e67e22"
        }]
    },
    options: {scales:{y:{beginAtZero:true}}, plugins:{legend:{display:false}}}
});
new Chart(document.getElementById('revenueMiniChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($chartLabels) ?>,
        datasets:[{
            label: 'Revenue', data: <?= json_encode($chartRevenue) ?>,
            borderColor:'#09be5b', backgroundColor:'rgba(9,190,91,0.14)', fill:true, tension:0.4,
            pointRadius:3,pointBackgroundColor:"#09be5b"
        }]
    },
    options: {scales:{y:{beginAtZero:true}}, plugins:{legend:{display:false}}}
});

// Profile modal, menu, etc (reuse your profile js/toggles here)
function toggleMenu() { document.getElementById("menuContent").classList.toggle("show"); }
document.addEventListener("click", function(event) {
  const profileMenu = document.querySelector(".profile-menu");
  const menuContent = document.getElementById("menuContent");
  if (profileMenu && menuContent && !profileMenu.contains(event.target)) menuContent.style.display = "none";
});
function openProfileModal() {
  const overlay = document.getElementById("profileOverlay");
  const content = document.getElementById("profileContent");
  overlay.style.display = "flex";
  content.innerHTML = '<p style="text-align:center;">Loading...</p>';
  fetch('profile.php', { credentials: 'same-origin' })
    .then(res => { if (!res.ok) throw new Error('Network response not ok'); return res.text(); })
    .then(html => { content.innerHTML = html; /* attachForm logic if needed*/ })
    .catch(err => {
      content.innerHTML = '<p style="color:red;text-align:center;">Failed to load profile.</p>';
    });
}
function closeProfile(e) { if (!e || e.target.id === 'profileOverlay') document.getElementById('profileOverlay').style.display = 'none'; }
</script>
</body>
</html>