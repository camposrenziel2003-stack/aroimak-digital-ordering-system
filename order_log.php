<?php 
session_start(); 
if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}
include "config.php";
// --- AJAX search handler (returns JSON) ---
if (isset($_GET['action']) && $_GET['action'] === 'search') {
    $q = isset($_GET['query']) ? trim($_GET['query']) : '';
    $like = "%{$q}%";

    // Prepared statement to avoid injection
    $stmt = $conn->prepare(
        "SELECT id, order_id, staff_id, staff_username, role, action, created_at
         FROM order_activity_log
         WHERE role = 'cashier'
           AND (staff_username LIKE ? OR staff_id LIKE ? OR action LIKE ?)
         ORDER BY created_at DESC
         LIMIT 500"
    );
    $stmt->bind_param("sss", $like, $like, $like);
    $stmt->execute();
    $result = $stmt->get_result();

    $out = ['activities' => []];
    while ($row = $result->fetch_assoc()) {
        // cast or sanitize as needed; return raw values for client-side escaping
        $out['activities'][] = $row;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($out);
    exit;
}

// Fetch admin info for profile picture
$adminId = $_SESSION['admin'];
$adminQuery = $conn->prepare("SELECT username, profile_pic FROM admins WHERE id = ?");
$adminQuery->bind_param("i", $adminId);
$adminQuery->execute();
$adminResult = $adminQuery->get_result();
$admin = $adminResult->fetch_assoc();

if (!$admin) {
    $profilePic = 'default.png';
    $username = 'Admin';
} else {
    $profilePic = !empty($admin['profile_pic']) ? $admin['profile_pic'] : 'default.png';
    $username = htmlspecialchars($admin['username']);
}

// Fetch categories for modal dropdown
$categoriesResult = $conn->query("SELECT * FROM categories ORDER BY name ASC");
$categories = [];
while ($cat = $categoriesResult->fetch_assoc()) {
    $categories[$cat['id']] = htmlspecialchars($cat['name']);
}

// Fetch only the last activity for cashier
$cashierLast = null;
$res = $conn->query("SELECT * FROM order_activity_log WHERE role='cashier' ORDER BY created_at DESC LIMIT 1");
if ($res && $res->num_rows > 0) {
    $cashierLast = $res->fetch_assoc();
}

// Fetch activity log - only cashier rows
$activityResult = $conn->query("SELECT * FROM order_activity_log WHERE role='cashier' ORDER BY created_at DESC");
$activities = [];
if ($activityResult && $activityResult->num_rows > 0) {
    while ($row = $activityResult->fetch_assoc()) {
        $activities[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Order Activity Log</title>
<link rel="stylesheet" href="index.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
.page-wrapper { margin-left: 220px; padding: 110px 24px 24px 24px; box-sizing: border-box; }
.order-log-card { background:#fff; padding:18px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.06); }
.order-log-table { width:100%; border-collapse:collapse; }
.order-log-table th, .order-log-table td { border:1px solid #eaeaea; padding:10px; vertical-align:top; text-align:left; }
.order-log-table th { background:#ffeede; color:#ff6600; }
.small-meta { font-size:0.92em; color:#666; }
.summary-label { font-size:1.15em; color:#21a049; font-weight:bold; margin-bottom:12px; }
/* make the input flexible so it fills available space and matches button height */
#searchInput {
  flex: 1 1 auto;
  min-width: 120px;
  padding: 10px 14px;
  height: 44px;
  border: 1px solid #e6b57a;
  border-radius: 10px;
  font-size: 15px;
  color: #333;
  background: #fff;
  outline: none;
  transition: box-shadow 0.12s ease, border-color 0.12s ease;
  -webkit-appearance: none;
  appearance: none;
  box-sizing: border-box;
}

/* placeholder and focus states */
#searchInput::placeholder { color: #b0b0b0; }
#searchInput:focus {
  border-color: #ff6600;
  box-shadow: 0 4px 10px rgba(255,102,0,0.08);
}

/* style the Clear button to visually match the design and the input height */
#searchBtn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  background: transparent;
  color: #ff6600;
  border: 2px solid #ff6600;
  padding: 8px 14px;
  height: 44px;
  border-radius: 10px;
  font-weight: 600;
  cursor: pointer;
  font-size: 15px;
  box-shadow: 0 2px 6px rgba(255,102,0,0.06);
  transition: background 0.12s ease, transform 0.06s ease, box-shadow 0.12s ease;
  white-space: nowrap;
  line-height: 1;
}

/* hover / active */
#searchBtn:hover { background: #ff6600; color: #fff; box-shadow: 0 6px 18px rgba(255,122,47,0.12); }
#searchBtn:active { transform: translateY(1px); }


/* responsive adjustments */
@media (max-width: 600px) {
  .search-bar { gap: 8px; flex-wrap: nowrap; }
  #searchInput { min-width: 0; flex-basis: 0; height: 40px; padding: 8px 10px; font-size: 14px; }
  #searchBtn { height: 40px; padding: 6px 10px; font-size: 14px; }
}

</style>
</head>
<body>

<!-- Sidebar -->
<div class="sidebar">
  <img src="logo.png" class="logo" alt="Logo">
  <div class="sidebar-scroll">
    <nav>
        <a href="index.php"><i class="fa-solid fa-chart-line"></i> Dashboard</a>
        <a href="order.php"><i class="fa-solid fa-receipt"></i> Orders</a>
        <a href="popular_dishes.php"><i class="fa-solid fa-fire"></i> Popular Dishes</a>
        <a href="menu.php"><i class="fa-solid fa-utensils"></i> Menu Availability</a>
        <a href="add_item.php"><i class="fa-solid fa-plus-circle"></i> Add Item</a>
        <a href="promo.php"><i class="fa-solid fa-bullhorn"></i> Add Promo</a>
        <a href="feedback.php"><i class="fa-solid fa-star"></i> Customer Feedback</a>
        <a href="assign_table.php"><i class="fa-solid fa-tablet-screen-button"></i> Assign Table</a>
        <a href="assign_roles.php"><i class="fa-solid fa-user-gear"></i> Assign Roles</a>
        <a href="order_log.php" class="active"><i class="fa-solid fa-list-check"></i> Order Activity Log</a>
           <a href="archived.php">
        <i class="fa-solid fa-box-archive"></i> Archived
        </a>
    </nav>
  </div>
</div>

<!-- Header -->
<header class="header">
    <div class="header-left">
      <h1>Order Activity Log</h1>
    </div>
    <div class="header-right">
        <div class="profile-menu" onclick="toggleMenu()">
            <img id="headerProfilePic" src="uploads/<?= htmlspecialchars($_SESSION['profile_pic']) ?>" 
             alt="Admin" class="profile-pic">
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
    <div id="profileContent">
      <p style="text-align:center;">Loading...</p>
    </div>
  </div>
</div>

<div class="page-wrapper">
  <div class="order-log-card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
      <h2 style="margin:0; color:#ff6600;">Order Activity Log</h2>
      <div class="small-meta">Showing latest activities (newest first) — cashier only</div>
    </div>
<div class="search-bar">
  <input id="searchInput" type="search" placeholder="Search by staff username..." aria-label="Search by staff username">
  <button id="searchBtn" type="button">Search</button>
</div>
    <!-- Last Activity (Cashier) -->
    <div class="summary-label">Last Activity (Cashier)</div>
    <ul style="font-size: 1em; color: #222; margin-bottom:20px;">
      <?php if ($cashierLast): ?>
        <li style="margin-bottom:8px;">
          <b>Cashier</b>:<br>
          Staff ID: <strong><?= htmlspecialchars($cashierLast['staff_id']) ?></strong><br>
          Username: <strong><?= htmlspecialchars($cashierLast['staff_username']) ?></strong><br>
          Action: <i><?= htmlspecialchars($cashierLast['action']) ?></i><br>
          <?= htmlspecialchars($cashierLast['created_at']) ?>
        </li>
      <?php else: ?>
        <li>No recent activity for cashier.</li>
      <?php endif; ?>
    </ul>

    <table class="order-log-table">
      <thead>
        <tr>
          <th>Order ID</th>
          <th>Staff ID</th>
          <th>Staff Username</th>
          <th>Action</th>
          <th>Date Created</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($activities)): ?>
          <tr><td colspan="7" style="text-align:center; color:#888; padding:18px;">No activities found.</td></tr>
        <?php else: ?>
          <?php foreach ($activities as $a): ?>
            <tr>
              <td><?= htmlspecialchars($a['order_id']) ?></td>
              <td><?= htmlspecialchars($a['staff_id']) ?></td>
              <td><?= htmlspecialchars($a['staff_username']) ?></td>
              <td><?= htmlspecialchars($a['action']) ?></td>
              <td><?= htmlspecialchars($a['created_at']) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
// Toggle profile dropdown menu
function toggleMenu() {
    var menu = document.getElementById('menuContent');
    if (!menu) return;
    menu.style.display = (menu.style.display === 'block') ? 'none' : 'block';
}

// Hide the menu if clicking outside
document.addEventListener('click', function(event) {
    var menu = document.getElementById('menuContent');
    var profileMenu = document.querySelector('.profile-menu');
    if (menu && profileMenu && !profileMenu.contains(event.target)) {
        menu.style.display = 'none';
    }
});

// Profile modal logic
function openProfileModal() {
  const overlay = document.getElementById("profileOverlay");
  const content = document.getElementById("profileContent");
  overlay.style.display = "flex";
  content.innerHTML = '<p style="text-align:center;">Loading...</p>';

  fetch('profile.php', { credentials: 'same-origin' })
    .then(res => {
      if (!res.ok) throw new Error('Network response not ok');
      return res.text();
    })
    .then(html => {
      content.innerHTML = html;
      initProfileModal();
    })
    .catch(err => {
      console.error('Failed to load profile.php:', err);
      content.innerHTML = '<p style="color:red;text-align:center;">Failed to load profile.</p>';
    });
}

function closeProfile(e) {
  if (!e || e.target.id === 'profileOverlay') {
    document.getElementById('profileOverlay').style.display = 'none';
  }
}

function initProfileModal() {
  const form = document.getElementById("usernameForm");
  const display = document.getElementById("usernameDisplay");
  const editIcon = document.getElementById("editIcon");
  if (form) form.classList.add("hidden");
  if (display) display.style.display = "";
  if (editIcon) editIcon.style.display = "inline-block";
  attachProfileFormHandlers();
}

function attachProfileFormHandlers() {
  const content = document.getElementById("profileContent");
  if (!content) return;

  content.querySelectorAll('form').forEach(form => {
    const cloned = form.cloneNode(true);
    form.parentNode.replaceChild(cloned, form);

    cloned.addEventListener('submit', async function(e) {
      e.preventDefault();
      const fd = new FormData(cloned);
      cloned.querySelectorAll('button[type="submit"]').forEach(b => b.disabled = true);

      try {
        const res = await fetch('profile.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        if (!res.ok) throw new Error('Network response not ok');
        const text = await res.text();
        content.innerHTML = text;
        initProfileModal();

        const parser = new DOMParser();
        const doc = parser.parseFromString(text, 'text/html');
        const newImg = doc.querySelector('.profile-avatar');
        if (newImg) {
          const headerPic = document.getElementById('headerProfilePic');
          if (headerPic) headerPic.src = newImg.getAttribute('src').split('?')[0] + '?v=' + Date.now();
        }

      } catch (err) {
        console.error('Profile form submission failed:', err);
        alert('Failed to submit profile form.');
      } finally {
        cloned.querySelectorAll('button[type="submit"]').forEach(b => b.disabled = false);
      }
    });
  });
}
(function(){
  const input = document.getElementById('searchInput');
  const searchBtn = document.getElementById('searchBtn');
    const tbody = document.getElementById('activitiesTbody');      // must exist in your table
    const lastList = document.getElementById('cashierLastList');  // optional: updates last activity block
    const DEBOUNCE_MS = 300;
    let timeout = null;

  function escapeHtml(str){
    if (str === null || str === undefined) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function renderRows(rows){
    if (!rows || rows.length === 0) {
      tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; color:#888; padding:18px;">No activities found.</td></tr>';
      if (lastList) lastList.innerHTML = '<li>No matching activity for cashier.</li>';
      return;
    }

    tbody.innerHTML = rows.map(a => (
      '<tr>' +
        '<td>' + (parseInt(a.id) || 0) + '</td>' +
        '<td>' + escapeHtml(a.order_id) + '</td>' +
        '<td>' + escapeHtml(a.staff_id) + '</td>' +
        '<td>' + escapeHtml(a.staff_username) + '</td>' +
        '<td>' + escapeHtml(a.role) + '</td>' +
        '<td>' + escapeHtml(a.action) + '</td>' +
        '<td>' + escapeHtml(a.created_at) + '</td>' +
      '</tr>'
    )).join('');

   
  }

  async function performSearch(q){
    try {
      const url = 'order_log.php?action=search&query=' + encodeURIComponent(q || '');
      const res = await fetch(url, { credentials: 'same-origin' });
      if (!res.ok) throw new Error('Network response not ok');
      const json = await res.json();
      renderRows(Array.isArray(json.activities) ? json.activities : []);
    } catch (err) {
      console.error('Search error', err);
    }
  }

  function scheduleSearch(){
    const q = input.value.trim();
    if (timeout) clearTimeout(timeout);
    timeout = setTimeout(() => performSearch(q), DEBOUNCE_MS);
  }

  if (input) {
    input.addEventListener('input', scheduleSearch);
    input.addEventListener('search', scheduleSearch);
  }
  // Search button triggers an immediate search using current input value
  if (searchBtn) {
    searchBtn.addEventListener('click', function(){
      scheduleSearch();
      input.focus();
    });
  }
})();
</script>
</body>
</html>
