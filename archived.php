<?php
session_start();
if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}
include "config.php";

// Try to include CSRF helper if it exists (optional)
$csrf_available = false;
if (file_exists(__DIR__ . '/csrf.php')) {
    include_once __DIR__ . '/csrf.php';
    if (function_exists('csrf_get_token')) $csrf_available = true;
}

$currentPage = basename($_SERVER['PHP_SELF']);
$tab = $_GET['tab'] ?? 'menu'; // 'menu' or 'orders'

// Fetch archived menu items grouped by category
$menuByCategory = [];
$categoriesResult = $conn->query("SELECT * FROM categories ORDER BY name ASC");
while ($cat = $categoriesResult->fetch_assoc()) {
    $catId = (int)$cat['id'];
    $catName = htmlspecialchars($cat['name']);
    $itemsResult = $conn->query("SELECT * FROM menu_items WHERE category = $catId AND archived = 1 ORDER BY name ASC");
    $items = $itemsResult->fetch_all(MYSQLI_ASSOC);
    if (count($items) > 0) {
        $menuByCategory[$catId] = [
            'name' => $catName,
            'image' => $cat['image'] ?? '',
            'items' => $items
        ];
    }
}

// Fetch archived orders
$orderResult = $conn->query("SELECT * FROM orders WHERE archived = 1 ORDER BY id DESC");

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Archived — Admin</title>
<link rel="stylesheet" href="index.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
/* Basic styles consistent with existing pages */
.page-header { display:flex; align-items:center; justify-content:space-between; padding:12px 18px; }
.archive-tabs { display:inline-flex; background:#fff3e0; border-radius:8px; padding:4px; gap:6px; }
.archive-tab { display:inline-block; padding:8px 14px; border-radius:6px; text-decoration:none; color:#6f4b00; font-weight:600; }
.archive-tab.active { background:#ff6600; color:#fff; }
.container { padding:16px; }
.menu-image { width:90px; height:auto; border-radius:6px; }
.pref-tag { display:inline-block; background:#ffe0b2; color:#ff6600; border-radius:12px; padding:2px 6px; margin:4px; font-size:0.85rem; }
.table { width:100%; border-collapse:collapse; margin-top:12px; }
.table th, .table td { padding:8px; border:1px solid #e6e6e6; text-align:left; vertical-align:middle; }
.action-btn { padding:6px 10px; border-radius:6px; text-decoration:none; display:inline-block; margin-left:6px; cursor:pointer; border:none; }
.unarchive-btn { background:#5cb85c; color:#fff; }
.delete-link { background:#f8d7da; color:#d9534f; padding:6px 10px; border-radius:6px; text-decoration:none; }
.empty-message { color:#777; padding:12px; text-align:center; }
.header-right .profile-pic { width:36px; height:36px; border-radius:50%; object-fit:cover; }
</style>
</head>
<body>

<!-- Header (reuse similar structure) -->
<header class="header">
    <div class="header-left">
        <h1>Archived</h1>
    </div>
    <div class="header-right">
        <div class="profile-menu" onclick="document.getElementById('menuContent').classList.toggle('show')">
            <img id="headerProfilePic" src="uploads/<?= htmlspecialchars($_SESSION['profile_pic'] ?? 'default.png') ?>" 
             alt="Admin" class="profile-pic">
            <div class="menu-content" id="menuContent" style="display:none;">
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

<!-- Sidebar -->
<div class="sidebar">
    <img src="logo.png" class="logo" alt="Logo">
    <div class="sidebar-scroll">
        <nav>
            <a href="index.php">
                <i class="fa-solid fa-chart-line"></i> Dashboard
            </a>
            <a href="order.php">
                <i class="fa-solid fa-receipt"></i> Orders
            </a>
            <a href="popular_dishes.php" class="popular-link<?php if(basename($_SERVER['PHP_SELF'])=='popular_dishes.php') echo ' active'; ?>">
        <i class="fa-solid fa-fire"></i> Popular Dishes
    </a>
            <a href="menu.php">
                <i class="fa-solid fa-utensils"></i> Menu Availability
            </a>

            <a href="add_item.php">
                <i class="fa-solid fa-plus-circle"></i> Add Item
            </a>
            <a href="promo.php">
                <i class="fa-solid fa-bullhorn"></i> Add Promo
            </a>
                <a href="feedback.php">
            <i class="fa-solid fa-star"></i> Customer Feedback
        </a>
            <!-- Assign Table - Added under Promo -->
            <a href="assign_table.php">
                <i class="fa-solid fa-tablet-screen-button"></i> Assign Table
            </a>
        <a href="assign_roles.php">
            <i class="fa-solid fa-user-gear"></i> Assign Roles
        </a>
        <a href="order_log.php">
                <i class="fa-solid fa-list-check"></i> Order Activity Log
            </a>
        <a href="archived.php" class="active">
        <i class="fa-solid fa-box-archive"></i> Archived
        </a>
        </nav>
    </div>
</div>

<!-- Small tabs to switch between archived menu and archived orders -->
<div class="container">
  <div class="page-header">
    <div>
      <div class="archive-tabs" role="tablist" aria-label="Archived tabs">
        <a href="archived.php?tab=orders" class="archive-tab <?= $tab === 'orders' ? 'active' : '' ?>">Archived Orders</a>
        <a href="archived.php?tab=menu" class="archive-tab <?= $tab === 'menu' ? 'active' : '' ?>">Archived Menu</a>
      </div>
    </div>
    <div>
    </div>
  </div>

  <?php if ($tab === 'menu'): ?>
    <!-- Archived Menu Items -->
    <?php if (empty($menuByCategory)): ?>
      <div class="empty-message">No archived menu items.</div>
    <?php else: ?>
      <?php foreach ($menuByCategory as $catId => $catData): ?>
        <section style="margin-bottom:28px;">
          <h2 style="margin:8px 0;"><?= $catData['name'] ?></h2>
          <table class="table">
            <thead>
              <tr>
                <th>Image</th>
                <th>Name</th>
                <th>Description</th>
                <th>Price</th>
                <th>Preferences</th>
                <th>Pronounce</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($catData['items'] as $item): ?>
                <tr>
                  <td>
                    <?php if (!empty($item['image'])): ?>
                      <img src="uploads/<?= htmlspecialchars($item['image']) ?>" class="menu-image" alt="">
                    <?php else: ?>
                      <span style="color:#aaa;">No Image</span>
                    <?php endif; ?>
                  </td>
                  <td><?= htmlspecialchars($item['name']) ?></td>
                  <td><?= htmlspecialchars($item['description']) ?></td>
                  <td>
                    <?php
                      if (isset($item['price'])) {
                          echo '₱' . number_format((float)$item['price'], 2);
                      } else {
                          $ps = $item['price_solo'] ?? null;
                          $psh = $item['price_sharing'] ?? null;
                          if ($ps !== null || $psh !== null) {
                              echo 'Solo: ' . ($ps !== null ? '₱' . number_format((float)$ps, 2) : '-') . '<br>';
                              echo 'Sharing: ' . ($psh !== null ? '₱' . number_format((float)$psh, 2) : '-');
                          } else {
                              echo '-';
                          }
                      }
                    ?>
                  </td>
                  <td>
                    <?php
                      $prefs = explode(',', $item['preferences'] ?? '');
                      foreach ($prefs as $p) if ($p) echo '<span class="pref-tag">' . htmlspecialchars($p) . '</span>';
                    ?>
                  </td>
                  <td>
                    <?php if (!empty($item['pronunciation_file']) && file_exists('uploads/pronunciations/' . $item['pronunciation_file'])): ?>
                        <audio controls style="width:140px;">
                            <source src="uploads/pronunciations/<?= htmlspecialchars($item['pronunciation_file']) ?>" type="audio/mpeg">
                        </audio>
                    <?php else: ?>
                        <button type="button" onclick="speak('<?= htmlspecialchars(addslashes($item['name'])) ?>')">🔊</button>
                    <?php endif; ?>
                  </td>
                  <td>
                    <form method="post" action="unarchive_item.php" style="display:inline;">
                      <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                      <input type="hidden" name="redirect" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                      <?php if ($csrf_available): ?>
                        <input type="hidden" name="csrf_token" value="<?= csrf_get_token() ?>">
                      <?php endif; ?>
                      <button type="submit" class="action-btn unarchive-btn">Unarchive</button>
                    </form>

                    <a href="delete_item.php?id=<?= (int)$item['id'] ?>&redirect=archived.php?tab=menu" class="delete-link" onclick="return confirm('Permanently delete <?= htmlspecialchars(addslashes($item['name'])) ?>?')">Delete</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </section>
      <?php endforeach; ?>
    <?php endif; ?>

  <?php else: ?>
    <!-- Archived Orders -->
    <?php if ($orderResult->num_rows === 0): ?>
      <div class="empty-message">No archived orders.</div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th>Order ID</th>
            <th>Table #</th>
            <th>Customer</th>
            <th>Item(s)</th>
            <th>Qty</th>
            <th>Total</th>
            <th>Status</th>
            <th>Date</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php while ($order = $orderResult->fetch_assoc()): ?>
            <tr>
              <td><?= (int)$order['id'] ?></td>
              <td><?= htmlspecialchars($order['table_number']) ?></td>
              <td><?= htmlspecialchars($order['customer_name']) ?></td>
              <td><?= htmlspecialchars($order['item_name']) ?></td>
              <td><?= (int)$order['quantity'] ?></td>
              <td>₱<?= number_format((float)$order['total_price'], 2) ?></td>
              <td><?= htmlspecialchars($order['status']) ?></td>
              <td><?= htmlspecialchars($order['created_at']) ?></td>
              <td>
                <a href="javascript:void(0);" class="action-btn" onclick='openDetailsModal(<?= json_encode($order) ?>)'>View</a>

                <form method="post" action="unarchive_order.php" style="display:inline;">
                  <input type="hidden" name="id" value="<?= (int)$order['id'] ?>">
                  <input type="hidden" name="redirect" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                  <?php if ($csrf_available): ?>
                    <input type="hidden" name="csrf_token" value="<?= csrf_get_token() ?>">
                  <?php endif; ?>
                  <button type="submit" class="action-btn unarchive-btn">Unarchive</button>
                </form>

                <a href="delete_order.php?id=<?= (int)$order['id'] ?>" class="delete-link" onclick="return confirm('Permanently delete order #<?= (int)$order['id'] ?>?')">Delete</a>
              </td>
            </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?php endif; ?>
</div>

<!-- Details modal (reused for orders) -->
<div id="detailsModal" class="modal" style="display:none;">
  <div class="modal-content" style="max-width:600px;">
    <span class="close-btn" onclick="closeModal('detailsModal')">&times;</span>
    <div id="detailsContent"></div>
  </div>
</div>

<!-- Basic profile overlay markup expected by other pages -->
<div id="profileOverlay" class="profile-overlay" onclick="closeProfile(event)" style="display:none;">
  <div class="profile-card" onclick="event.stopPropagation()">
    <button class="close-btn" onclick="closeProfile()">✖</button>
    <div id="profileContent"><p style="text-align:center;">Loading...</p></div>
  </div>
</div>

<script>
function speak(text){
    let voices = speechSynthesis.getVoices();
    let thaiMale = voices.find(v=>v.lang.startsWith('th') && v.name.toLowerCase().includes('male'));
    if(!thaiMale) thaiMale = voices.find(v=>v.lang.startsWith('th')) || voices[0];
    const utterance = new SpeechSynthesisUtterance(text);
    utterance.voice = thaiMale;
    utterance.rate = 0.9; utterance.pitch = 1;
    speechSynthesis.speak(utterance);
}

function openDetailsModal(order) {
  function escapeHtml(str) {
    return String(str).replace(/[&<>"'`=\/]/g, function (s) {
      return ({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;","/":"&#x2F;","`":"&#x60;","=":"&#x3D;"} )[s];
    });
  }
  const content = `
    <p><strong>Order ID:</strong> ${escapeHtml(order.id)}</p>
    <p><strong>Table #:</strong> ${escapeHtml(order.table_number || '')}</p>
    <p><strong>Customer:</strong> ${escapeHtml(order.customer_name || '')}</p>
    <p><strong>Items:</strong><br>${escapeHtml(order.item_name || '')}</p>
    <p><strong>Total:</strong> ₱${parseFloat(order.total_price || 0).toFixed(2)}</p>
    <p><strong>Status:</strong> ${escapeHtml(order.status || '')}</p>
    <p><strong>Created At:</strong> ${escapeHtml(order.created_at || '')}</p>
  `;
  document.getElementById('detailsContent').innerHTML = content;
  document.getElementById('detailsModal').style.display = 'flex';
}
function closeModal(id){ document.getElementById(id).style.display = 'none'; }

function openProfileModal(){
  const overlay = document.getElementById("profileOverlay");
  const content = document.getElementById("profileContent");
  overlay.style.display = "flex";
  content.innerHTML = '<p style="text-align:center;">Loading...</p>';
  fetch('profile.php', { credentials: 'same-origin' })
    .then(r=>r.text()).then(html=>content.innerHTML=html).catch(()=>content.innerHTML='<p style="color:red;">Failed to load profile.</p>');
}
function closeProfile(e){ if (!e || e.target.id === 'profileOverlay') document.getElementById('profileOverlay').style.display = 'none'; }
</script>
</body>
</html>