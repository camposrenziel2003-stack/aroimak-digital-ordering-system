<?php
session_start();
include "config.php";
// Handle optional filters
$ratingFilter = $_GET['rating'] ?? '';
$search = $_GET['search'] ?? '';

// --- Analytics ---
$stats = [
    'total' => 0,
    'avg'   => 0,
    'counts' => [1=>0,2=>0,3=>0,4=>0,5=>0]
];

$res = $conn->query("SELECT rating, COUNT(*) as c FROM feedback GROUP BY rating");
$totalRatings = 0; $sumRatings = 0;
while ($r = $res->fetch_assoc()) {
    $stats['counts'][$r['rating']] = $r['c'];
    $totalRatings += $r['c'];
    $sumRatings   += $r['rating'] * $r['c'];
}
$stats['total'] = $totalRatings;
$stats['avg']   = $totalRatings ? round($sumRatings / $totalRatings, 1) : 0;

// --- Main Query ---
$sql = "SELECT f.id, f.order_group_id, f.rating, f.comment, f.created_at, 
               o.customer_name
        FROM feedback f
        LEFT JOIN orders o ON f.order_group_id = o.order_group_id
        WHERE 1=1";

if ($ratingFilter !== '') {
    $sql .= " AND f.rating = " . intval($ratingFilter);
}
if (!empty($search)) {
    $safeSearch = $conn->real_escape_string($search);
    $sql .= " AND (o.customer_name LIKE '%$safeSearch%' 
              OR f.comment LIKE '%$safeSearch%' 
              OR f.order_group_id LIKE '%$safeSearch%')";
}

$sql .= " ORDER BY f.created_at DESC";
$result = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Customer Feedback</title>
  <link rel="stylesheet" href="index.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    .feedback-page {
      margin-left: 220px;
      margin-top: 100px;
      padding: 20px;
    }
    .stats-card {
      display: flex;
      gap: 20px;
      flex-wrap: wrap;
      margin-bottom: 20px;
    }
    .stat-box {
      flex: 1;
      min-width: 200px;
      background: #fff;
      border-radius: 12px;
      padding: 15px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.07);
      text-align: center;
    }
    .stat-box h2 {
      margin: 0;
      font-size: 2rem;
      color: #ff6600;
    }
    .stat-box p {
      margin: 5px 0 0;
      font-size: 0.9rem;
      color: #666;
    }
    .feedback-card {
      background: #fff;
      padding: 20px;
      border-radius: 12px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.07);
      overflow-x: auto;
    }
    .feedback-card table {
      width: 100%;
      border-collapse: collapse;
    }
    .feedback-card th, .feedback-card td {
      border: 1px solid #ddd;
      padding: 10px;
      text-align: left;
      vertical-align: top;
    }
    .feedback-card th {
      background: #ffe0b2;
      color: #ff6600;
    }
    .rating i {
      color: #ffb400;
    }
    .filter-bar {
      margin-bottom: 15px;
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }
    .filter-bar a {
      padding: 6px 12px;
      border-radius: 6px;
      text-decoration: none;
      background: #f2f2f2;
      color: #333;
      border: 1px solid #ccc;
    }
    .filter-bar a.active {
      background: #ff6600;
      color: #fff;
      border: none;
    }
    .search-box {
      margin-left: auto;
    }
    .search-box input {
      padding: 6px 10px;
      border-radius: 6px;
      border: 1px solid #ccc;
    }

    /* Sidebar Scrolling */
    .sidebar-scroll {
      height: calc(100vh - 120px);
      overflow-y: auto;
      overflow-x: hidden;
    }

    .sidebar-scroll::-webkit-scrollbar {
      width: 8px;
    }

    .sidebar-scroll::-webkit-scrollbar-track {
      background: transparent;
    }

    .sidebar-scroll::-webkit-scrollbar-thumb {
      background: #d4a574;
      border-radius: 4px;
    }

    .sidebar-scroll::-webkit-scrollbar-thumb:hover {
      background: #c5945e;
    }

    /* Firefox */
* {
  scrollbar-width: thin;             /* thin scrollbar */
  scrollbar-color: #f5cea2ff #f0f0f0; /* thumb color | track color */
}
  </style>
</head>
<body>

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
                <a href="feedback.php"  class="active">
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
             <a href="archived.php">
        <i class="fa-solid fa-box-archive"></i> Archived
        </a>
        </nav>
    </div>
</div>

<!-- Header -->
<header class="header">
    <div class="header-left">
        <h1>Customers Dine Feedback</h1>
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
        <a href="cashier/index.php" class="cashier-btn"><i class="fa-solid fa-cash-register"></i> Cashier View</a>
    </div>
</header>
<!-- Floating Profile Modal -->
<div id="profileOverlay" class="profile-overlay" onclick="closeProfile(event)" style="display:none;">
  <div class="profile-card" onclick="event.stopPropagation()">
    <button class="close-btn" onclick="closeProfile()">✖</button>
    <div id="profileContent">
      <!-- Profile content will load here -->
      <p style="text-align:center;">Loading...</p>
    </div>
  </div>
</div>

<div class="feedback-page">

  <!-- Analytics -->
  <div class="stats-card">
    <div class="stat-box">
      <h2>
        <?php for ($i=1;$i<=5;$i++): ?>
          <i class="fa<?= $i <= round($stats['avg']) ? 's' : 'r' ?> fa-star"></i>
        <?php endfor; ?>
        <?= $stats['avg'] ?>/5
      </h2>
      <p>Average Rating</p>
    </div>
    <div class="stat-box">
      <h2><?= $stats['total'] ?></h2>
      <p>Total Feedback</p>
    </div>
    <?php for ($i=5;$i>=1;$i--): ?>
    <div class="stat-box">
      <h2><?= $stats['counts'][$i] ?></h2>
      <p><?= $i ?>★ Feedback</p>
    </div>
    <?php endfor; ?>
  </div>

  <!-- Filter Bar -->
  <div class="filter-bar">
    <a href="feedback.php" class="<?= $ratingFilter==''?'active':'' ?>">All (<?= $stats['total'] ?>)</a>
    <?php for ($i=5;$i>=1;$i--): ?>
      <a href="feedback.php?rating=<?= $i ?>" 
         class="<?= $ratingFilter==$i?'active':'' ?>">
         <?= $i ?>★ (<?= $stats['counts'][$i] ?>)
      </a>
    <?php endfor; ?>
    <div class="search-box">
      <form method="get">
        <?php if ($ratingFilter): ?>
          <input type="hidden" name="rating" value="<?= $ratingFilter ?>">
        <?php endif; ?>
        <input type="text" name="search" placeholder="Search..." value="<?= htmlspecialchars($search) ?>">
      </form>
    </div>
  </div>

  <div class="feedback-card">
    <?php if ($result->num_rows > 0): ?>
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Order #</th>
            <th>Customer</th>
            <th>Rating</th>
            <th>Comment</th>
            <th>Submitted At</th>
          </tr>
        </thead>
        <tbody>
          <?php while ($row = $result->fetch_assoc()): ?>
            <tr>
              <td><?= htmlspecialchars($row['id']) ?></td>
              <td><?= htmlspecialchars($row['order_group_id']) ?></td>
              <td><?= htmlspecialchars($row['customer_name'] ?? 'N/A') ?></td>
              <td class="rating">
                <?php for ($i=1;$i<=5;$i++): ?>
                  <i class="fa<?= $i <= $row['rating'] ? 's' : 'r' ?> fa-star"></i>
                <?php endfor; ?>
                (<?= $row['rating'] ?>/5)
              </td>
              <td><?= nl2br(htmlspecialchars($row['comment'])) ?></td>
              <td>
  <?php 
    if (!empty($row['created_at'])) {
        $dt = new DateTime($row['created_at']);
        echo htmlspecialchars($dt->format("F j, Y - g:ia"));
    } else {
        echo "N/A";
    }
  ?>
</td>

            </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p>No feedback submitted yet.</p>
    <?php endif; ?>
  </div>
</div>
</body>
<script>
// Initialize modal state & attach form handlers
function initProfileModal() {
  const form = document.getElementById("usernameForm");
  const display = document.getElementById("usernameDisplay");
  const editIcon = document.getElementById("editIcon");

  if (form) form.classList.add("hidden");
  if (display) display.style.display = "";
  if (editIcon) editIcon.style.display = "inline-block";

  attachProfileFormHandlers();
}

// Attach handlers to forms inside modal (AJAX submit to profile.php)
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
        const res = await fetch('profile.php', {
          method: 'POST',
          body: fd,
          credentials: 'same-origin'
        });

        if (!res.ok) throw new Error('Network error');
        const text = await res.text();

        content.innerHTML = text;
        initProfileModal();

        // Update header pic & username
        const parser = new DOMParser();
        const doc = parser.parseFromString(text, 'text/html');

        const newImg = doc.querySelector('.profile-avatar');
        if (newImg) {
          const headerPic = document.getElementById('headerProfilePic');
          if (headerPic) {
            headerPic.src = newImg.getAttribute('src').split('?')[0] + '?v=' + Date.now();
          }
        }

        const usernameEl = doc.querySelector('#usernameDisplay');
        if (usernameEl) {
          const headerUsername = document.getElementById('headerUsername');
          if (headerUsername) headerUsername.textContent = usernameEl.textContent;
        }

      } catch (err) {
        console.error('Profile update failed:', err);
        alert('Failed to submit profile form.');
      } finally {
        cloned.querySelectorAll('button[type="submit"]').forEach(b => b.disabled = false);
      }
    });
  });
}

// Open modal and load profile.php
function openProfileModal() {
  const overlay = document.getElementById("profileOverlay");
  const content = document.getElementById("profileContent");
  overlay.style.display = "flex";
  content.innerHTML = '<p style="text-align:center;">Loading...</p>';

  fetch('profile.php', { credentials: 'same-origin' })
    .then(res => res.text())
    .then(html => {
      content.innerHTML = html;
      initProfileModal();
    })
    .catch(err => {
      console.error('Failed to load profile.php:', err);
      content.innerHTML = '<p style="color:red;text-align:center;">Failed to load profile.</p>';
    });
}

// Close modal
function closeProfile(e) {
  if (!e || e.target.id === 'profileOverlay') {
    document.getElementById('profileOverlay').style.display = 'none';
  }
}

// Toggle username edit form
function toggleEdit() {
  const display = document.getElementById("usernameDisplay");
  const editIcon = document.getElementById("editIcon");
  const form = document.getElementById("usernameForm");
  if (!form || !display || !editIcon) return;

  if (form.classList.contains("hidden")) {
    display.style.display = "none";
    editIcon.style.display = "none";
    form.classList.remove("hidden");
  } else {
    display.style.display = "";
    editIcon.style.display = "inline-block";
    form.classList.add("hidden");
  }
}

// Profile dropdown menu
function toggleMenu() {
  const menu = document.getElementById("menuContent");
  menu.style.display = (menu.style.display === "block") ? "none" : "block";
}

// Close menu if clicked outside
document.addEventListener("click", function(event) {
  const profileMenu = document.querySelector(".profile-menu");
  const menuContent = document.getElementById("menuContent");
  if (!profileMenu.contains(event.target)) {
    menuContent.style.display = "none";
  }
});
</script>
</html>
<?php $conn->close(); ?>
