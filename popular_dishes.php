<?php
session_start();
if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}
include "config.php";
$friedRiceId = isset($friedRiceId) ? $friedRiceId : null;
$noodlesId = isset($noodlesId) ? $noodlesId : null;

// --- CATEGORY FILTER ---
$category = isset($_GET['category']) ? $_GET['category'] : 'all';

// --- PERIOD FILTER ---
$period = isset($_GET['period']) ? $_GET['period'] : 'all';

// --- CATEGORY OPTIONS ---
$categoryOptions = [];
$catResult = $conn->query("SELECT id, name FROM categories ORDER BY name ASC");
while ($cat = $catResult->fetch_assoc()) {
    $categoryOptions[$cat['id']] = $cat['name'];
}

// --- BUILD MAIN QUERY ---
$where = [];
$group = "GROUP BY order_items.item_name";
$dateCondition = "";
$categoryCondition = "";

// Set up join and SELECT fields conditionally
if ($period != 'all') {
    $join = "INNER JOIN orders ON order_items.order_group_id = orders.order_group_id";
    $selectLastOrdered = ", MAX(orders.created_at) AS last_ordered";
    if ($period == 'day') {
        $dateCondition = "DATE(orders.created_at) = CURDATE()";
    } elseif ($period == 'week') {
        $dateCondition = "YEARWEEK(orders.created_at, 1) = YEARWEEK(CURDATE(), 1)";
    } elseif ($period == 'month') {
        $dateCondition = "YEAR(orders.created_at) = YEAR(CURDATE()) AND MONTH(orders.created_at) = MONTH(CURDATE())";
    }
    if ($dateCondition) $where[] = $dateCondition;
} else {
    $join = "";
    $selectLastOrdered = "";
}

if ($category != 'all') {
    $categoryCondition = "menu_items.category = " . intval($category);
    $where[] = $categoryCondition;
}

// --- EXCLUDE DRINKS ---
$drinksCatId = null;
$drinksCatRes = $conn->query("SELECT id FROM categories WHERE name = 'Drinks' LIMIT 1");
if ($drinksCatRes && $drinksRow = $drinksCatRes->fetch_assoc()) {
    $drinksCatId = $drinksRow['id'];
    if ($drinksCatId !== null) {
        $where[] = "menu_items.category != " . intval($drinksCatId);
    }
}

$whereSQL = (count($where) > 0) ? ("WHERE " . implode(" AND ", $where)) : "";

// --- GET TOTAL ORDERS FOR PERCENTAGE ---
// Build join for total orders (category filter needs menu_items join)
$totalOrdersJoin = "LEFT JOIN menu_items ON order_items.item_name = menu_items.name";
if ($period != 'all') {
    $totalOrdersJoin .= " INNER JOIN orders ON order_items.order_group_id = orders.order_group_id";
}
$totalOrdersRes = $conn->query("
    SELECT SUM(order_items.quantity) AS total_orders
    FROM order_items
    $totalOrdersJoin
    $whereSQL
");
$totalOrdersRow = $totalOrdersRes->fetch_assoc();
$totalOrders = $totalOrdersRow ? (int)$totalOrdersRow['total_orders'] : 0;

// --- MAIN DATA QUERY ---
$result = $conn->query("
   SELECT order_items.item_name,
       SUM(order_items.quantity) AS total_qty,
       SUM(order_items.quantity * order_items.price) AS total_revenue
       $selectLastOrdered,
       menu_items.image
   FROM order_items
   LEFT JOIN menu_items ON order_items.item_name = menu_items.name
   $join
   $whereSQL
   $group
   ORDER BY total_qty DESC
   LIMIT 10
");

// --- STOCK/AVAILABILITY MAP ---
$stockMap = [];
$menuRes = $conn->query("SELECT name, stock FROM menu_items");
while ($row = $menuRes->fetch_assoc()) {
    $stockMap[$row['name']] = $row['stock'];
}

// --- PERIOD COMPARISON (change vs previous period) ---
function getPreviousPeriodCondition($period) {
    if ($period == 'day') {
        return "DATE(orders.created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
    } elseif ($period == 'week') {
        return "YEARWEEK(orders.created_at, 1) = YEARWEEK(DATE_SUB(CURDATE(), INTERVAL 1 WEEK), 1)";
    } elseif ($period == 'month') {
        return "YEAR(orders.created_at) = YEAR(CURDATE()) AND MONTH(orders.created_at) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))";
    }
    return "";
}

$prevPeriodData = [];
if ($period != 'all') {
    $prevWhere = [];
    $prevDateCondition = getPreviousPeriodCondition($period);
    if ($prevDateCondition) $prevWhere[] = $prevDateCondition;
    if ($category != 'all') $prevWhere[] = $categoryCondition;
    if ($drinksCatId !== null) $prevWhere[] = "menu_items.category != " . intval($drinksCatId);

    $prevWhereSQL = (count($prevWhere) > 0) ? ("WHERE " . implode(" AND ", $prevWhere)) : "";

    $prevJoin = $join;
    if ($category != 'all' || $drinksCatId !== null) {
        $prevJoin .= " LEFT JOIN menu_items ON order_items.item_name = menu_items.name";
    }

    $prevRes = $conn->query("
        SELECT order_items.item_name, SUM(order_items.quantity) AS total_qty
        FROM order_items
        $prevJoin
        $prevWhereSQL
        $group
    ");
    while ($row = $prevRes->fetch_assoc()) {
        $prevPeriodData[$row['item_name']] = (int)$row['total_qty'];
    }
}

// --- Build hidden table rows as you build card grid ---
$tableRows = '';
$rank = 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Popular Dishes</title>
    <link rel="stylesheet" href="index.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .popular-filter-form, .category-filter-form  { display: inline-block; margin-right: 16px; }
        .export-btn, .print-btn {
            margin-bottom: 10px;
            float: right;
            background: #fff;
            border: 1.5px solid #e67e22;
            color: #e67e22;
            padding: 7px 18px 7px 14px;
            border-radius: 8px;
            font-size: 1.05em;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            box-shadow: 0 2px 8px rgba(230,126,34,0.07);
            transition: background 0.18s, color 0.18s, border-color 0.18s;
        }
        .export-btn:hover, .print-btn:hover {
            background: #e67e22;
            color: #fff;
            border-color: #e67e22;
        }
        .export-btn:active, .print-btn:active {
            background: #d35400;
            color: #fff;
            border-color: #d35400;
        }
        .dish-link {
        color: #e67e22;
        font-weight: 500;
        cursor: pointer;
        text-decoration: none !important; /* Remove underline */
        transition: color 0.15s;
        border-bottom: none;
        outline: none;
    }
    .dish-link:hover, .dish-link:focus {
        color: #d35400;
        /* Optional: add a subtle background or shadow on hover for click feedback */
        background: #fbeee2;
        border-radius: 6px;
    } .trend-up { color: green; font-weight: bold; }
        .trend-down { color: red; font-weight: bold; }
        .trend-same { color: gray; }
        .dish-popup { display:none; position:fixed; left:50%; top:20%; transform:translate(-50%,0); background:#fff; border:2px solid #e67e22; padding:16px; z-index:1000; min-width:300px;}
        .dish-popup-close { float:right; cursor:pointer; }

        select.custom-dropdown {
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background-color: #fff;
            border: 1.5px solid #e67e22;
            border-radius: 8px;
            padding: 7px 35px 7px 14px;
            font-size: 1.08em;
            color: #333;
            font-weight: 500;
            box-shadow: 0 2px 8px rgba(230,126,34,0.07);
            transition: border-color 0.2s, box-shadow 0.2s;
            outline: none;
            cursor: pointer;
            position: relative;
        }
        select.custom-dropdown:focus {
            border-color: #d35400;
            box-shadow: 0 0 0 2px #ffe5d0;
        }
        select.custom-dropdown {
            background-image:
              linear-gradient(45deg, transparent 49%, #e67e22 51%),
              linear-gradient(-45deg, transparent 49%, #e67e22 51%);
            background-position:
              calc(100% - 22px) calc(1em + 2px),
              calc(100% - 16px) calc(1em + 2px);
            background-size: 6px 6px;
            background-repeat: no-repeat;
        }
        select.custom-dropdown::-ms-expand {
            display: none;
        }
        .popular-filter-form label, .category-filter-form label {
            margin-right: 7px;
            font-weight: bold;
            font-size: 1.08em;
        }
        @media (max-width: 600px) {
            .popular-filter-form, .category-filter-form { display: block; margin-bottom: 10px; }
            .export-btn, .print-btn { float: none; margin: 8px 0; width: 100%; }
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
           <a href="archived.php">
        <i class="fa-solid fa-box-archive"></i> Archived
        </a>
        </nav>
    </div>
</div>

<!-- Header -->
<header class="header">
    <div class="header-left">
        <h1>Popular Dishes</h1>
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
<div class="container">
    <div class="card">
        <h2>Popular Dishes</h2>
       <form method="get" class="popular-filter-form" style="margin-bottom:20px;">
    <label for="period"><strong>Show By:</strong></label>
    <select name="period" id="period" class="custom-dropdown" onchange="this.form.submit()">
        <option value="all" <?= ($period=='all')?'selected':''; ?>>All Time</option>
        <option value="day" <?= ($period=='day')?'selected':''; ?>>Today</option>
        <option value="week" <?= ($period=='week')?'selected':''; ?>>This Week</option>
        <option value="month" <?= ($period=='month')?'selected':''; ?>>This Month</option>
    </select>
    <label for="category" style="margin-left:18px;"><strong>Category:</strong></label>
    <select name="category" id="category" class="custom-dropdown" onchange="this.form.submit()">
        <option value="all" <?= ($category=='all')?'selected':''; ?>>All</option>
        <?php foreach ($categoryOptions as $id=>$name): ?>
            <option value="<?= $id ?>" <?= ($category==$id)?'selected':''; ?>><?= htmlspecialchars($name) ?></option>
        <?php endforeach; ?>
    </select>
</form>
        <div style="overflow: auto; margin-top: 10px;">
            <button class="print-btn" onclick="printTable()"><i class="fa-solid fa-print"></i> Print</button>
            <button class="export-btn" onclick="exportTableToCSV('popular_dishes.csv')"><i class="fa-solid fa-download"></i> Export CSV</button>
        </div>
       <div class="popular-card-grid">
<?php
while ($row = $result->fetch_assoc()):
    $qty = (int)$row['total_qty'];
    $revenue = $row['total_revenue'] ? number_format($row['total_revenue'],2) : '0.00';
    $stock = array_key_exists($row['item_name'], $stockMap) ? $stockMap[$row['item_name']] : 'N/A';
    $status = ($stock>0)
        ? '<span class="available">Available</span>'
        : '<span class="out-of-stock">Out of Stock</span>';
    $plainStatus = ($stock>0) ? 'Available' : 'Out of Stock';
    $prevQty = ($period!='all' && isset($prevPeriodData[$row['item_name']])) ? $prevPeriodData[$row['item_name']] : 0;
    $trendDiff = $qty - $prevQty;
    $trendClass = $trendDiff > 0 ? 'trend-up' : ($trendDiff < 0 ? 'trend-down' : 'trend-same');
    $image = !empty($row['image']) ? 'uploads/' . htmlspecialchars($row['image']) : 'default-dish.png';
    // Last ordered logic
    if ($period != 'all' && isset($row['last_ordered'])) {
        $lastOrdered = date('Y-m-d H:i:s', strtotime($row['last_ordered']));
    } else {
        $lastOrderedRes = $conn->query("SELECT MAX(orders.created_at) AS last_ordered
            FROM order_items
            INNER JOIN orders ON order_items.order_group_id = orders.order_group_id
            WHERE order_items.item_name = '" . $conn->real_escape_string($row['item_name']) . "'");
        $lastOrderedRow = $lastOrderedRes->fetch_assoc();
        $lastOrdered = $lastOrderedRow && $lastOrderedRow['last_ordered'] ? date('Y-m-d H:i:s', strtotime($lastOrderedRow['last_ordered'])) : 'N/A';
    }
    // --- Build the hidden table row ---
    $tableRows .= '<tr>';
    $tableRows .= '<td>' . $rank . '</td>';
    $tableRows .= '<td>' . htmlspecialchars($row['item_name']) . '</td>';
    $tableRows .= '<td>' . $qty . '</td>';
    $tableRows .= '<td>₱' . $revenue . '</td>';
    $tableRows .= '<td>' . $lastOrdered . '</td>';
    $tableRows .= '<td>' . $plainStatus . '</td>';
    $tableRows .= '</tr>';
?>
    <div class="popular-card">
        <div class="popular-card-rank">#<?= $rank ?></div>
        <div class="popular-card-img">
            <img src="<?= $image ?>" alt="<?= htmlspecialchars($row['item_name']) ?>"
                 onerror="this.src='default-dish.png'" />
        </div>
        <div class="popular-card-name">
            <?= htmlspecialchars($row['item_name']) ?>
        </div>
        <div class="popular-card-qty"><b>Total Ordered:</b> <?= $qty ?></div>
        <div class="popular-card-revenue"><b>Total Revenue:</b> ₱<?= $revenue ?></div>
        <div class="popular-card-last"><b>Last Ordered:</b> <?= $lastOrdered ?></div>
        <div class="popular-card-stock"><b>Status:</b> <?= $status ?></div>
        
    </div>
<?php $rank++; endwhile; ?>
<?php if ($rank === 1): ?>
    <div>No orders yet.</div>
<?php endif; ?>
</div>
<!-- Hidden table for CSV export and print -->
<table id="popularTable" style="display:none;">
    <thead>
        <tr>
            <th>Rank</th>
            <th>Menu Name</th>
            <th>Total Ordered</th>
            <th>Total Revenue</th>
            <th>Last Ordered</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
        <?= $tableRows ?>
    </tbody>
</table>
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

<script>
        function toggleMenu() {
    var menu = document.getElementById('menuContent');
    if (!menu) return;
    // Toggle the menu visibility
    if (menu.style.display === 'block') {
        menu.style.display = 'none';
    } else {
        menu.style.display = 'block';
    }
}

// Hide the menu if clicking outside the profile menu
document.addEventListener('click', function(event) {
    var menu = document.getElementById('menuContent');
    var profileMenu = document.querySelector('.profile-menu');
    if (menu && profileMenu && !profileMenu.contains(event.target)) {
        menu.style.display = 'none';
    }
});
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

/* Close modal (background click or close btn) */
function closeProfile(e) {
  if (!e || e.target.id === 'profileOverlay') {
    document.getElementById('profileOverlay').style.display = 'none';
  }
}

/* toggle edit (username) - kept global for inline onclick */
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
        const res = await fetch('profile.php', {
          method: 'POST',
          body: fd,
          credentials: 'same-origin'
        });

        if (!res.ok) throw new Error('Network response not ok');

        const text = await res.text();
        content.innerHTML = text;
        initProfileModal();

        // Update header picture and username from the returned fragment
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
        console.error('Profile form submission failed:', err);
        alert('Failed to submit profile form. See console for details.');
      } finally {
        cloned.querySelectorAll('button[type="submit"]').forEach(b => b.disabled = false);
      }
    });
  });
}
function exportTableToCSV(filename) {
    var csv = [];
    var table = document.getElementById("popularTable");
    var rows = table.querySelectorAll("tr");
    for (var i = 0; i < rows.length; i++) {
        var row = [], cols = rows[i].querySelectorAll("td, th");
        for (var j = 0; j < cols.length; j++) {
            // Wrap each cell value in quotes for CSV safety
            row.push('"' + cols[j].innerText.replace(/"/g, '""') + '"');
        }
        csv.push(row.join(","));
    }
    var csvFile = new Blob([csv.join("\n")], {type: "text/csv"});
    var downloadLink = document.createElement("a");
    downloadLink.download = filename;
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = "none";
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}

function printTable() {
    // Gather data from the hidden export table
    var table = document.getElementById("popularTable");
    var rows = table.querySelectorAll("tr");
    if (rows.length < 2) {
        alert("No data to print.");
        return;
    }

    // Prepare summary data
    var totalDishes = rows.length - 1;
    var totalRevenue = 0;
    for (var i = 1; i < rows.length; i++) {
        var revenue = rows[i].cells[3].innerText.replace(/[₱,]/g, '');
        totalRevenue += parseFloat(revenue) || 0;
    }

    function formatPeso(num) {
        return '₱' + num.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    // Get current date string
    var now = new Date();
    var dateStr = now.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });

    // Filter summary
    var periodText = document.getElementById('period') ? document.getElementById('period').selectedOptions[0].text : '';
    var categoryText = document.getElementById('category') ? document.getElementById('category').selectedOptions[0].text : '';
    var reportTitle = "Popular Dishes";
    if ((periodText && periodText != 'All Time') || (categoryText && categoryText != 'All')) {
        reportTitle += " – ";
        if (periodText && periodText != 'All Time') reportTitle += periodText;
        if (categoryText && categoryText != 'All') reportTitle += (periodText != 'All Time' ? ", " : "") + categoryText;
    }

    // Build table HTML
    var printTable = `<table class="print-table"><thead><tr>`;
    for (var j = 0; j < rows[0].cells.length; j++) {
        printTable += `<th>${rows[0].cells[j].innerText}</th>`;
    }
    printTable += `</tr></thead><tbody>`;
    for (var i = 1; i < rows.length; i++) {
        printTable += `<tr>`;
        for (var j = 0; j < rows[i].cells.length; j++) {
            printTable += `<td>${rows[i].cells[j].innerText}</td>`;
        }
        printTable += `</tr>`;
    }
    printTable += `</tbody></table>`;

    var printContent = `
    <html>
    <head>
    <title>Printable Popular Dishes</title>
    <style>
    body { font-family: Arial, sans-serif; margin:40px; }
    h1 { text-align:center; color:#ff6600; margin-bottom:5px; font-size:2.2em;}
    h3 { text-align:center; margin-top:0; color:#666; }
    .summary { margin:18px auto 22px auto; padding:15px; background:#f9f9f9; border:1px solid #ddd; border-radius:8px; max-width:400px; }
    .summary span { display:block; margin:5px 0; font-size:16px; }
    .summary .total { font-size:20px; font-weight:bold; color:#2e7d32; }
    table.print-table { width:100%; border-collapse:collapse; margin-top:18px; }
    table.print-table th, table.print-table td { border:1.5px solid #ccc; padding:8px 12px; text-align:left; font-size:14px; }
    table.print-table th { background:#ffe0b2; color:#ff6600; }
    table.print-table tr:nth-child(even) td { background: #f9f9f9; }
    .footer { margin-top:40px; text-align:center; font-size:12px; color:#888; }
    @media print {
        body { margin:0 !important; }
        .footer { margin-top:32px; }
    }
    </style>
    </head>
    <body>
    <div style="display:flex;justify-content:space-between;color:#888;font-size:0.97em;margin-bottom:8px;">
        <span>${now.toLocaleString()}</span>
        <span>Printable Popular Dishes</span>
    </div>
    <h1>${reportTitle}</h1>
    <h3>${periodText && periodText != 'All Time' ? periodText : 'All Time'}${categoryText && categoryText != 'All' ? ' • ' + categoryText : ''}</h3>
    <p><strong>Generated on:</strong> ${dateStr}</p>
    <div class='summary'>
        <span><strong>Total Dishes:</strong> ${totalDishes}</span>
        <span class='total'>Total Revenue: ${formatPeso(totalRevenue)}</span>
    </div>
    ${printTable}
    <div class='footer'>This is a system-generated report.</div>
    </body>
    </html>
    `;

    var win = window.open('', '_blank');
    win.document.write(printContent);
    win.document.close();
    win.focus();
    setTimeout(function() {
        win.print();
        win.close();
    }, 400);
}

const friedRiceId = <?= json_encode($friedRiceId) ?>;
const noodlesId = <?= json_encode($noodlesId) ?>;
const categorySelect = document.getElementById('category');
const soloSharingPrices = document.getElementById('soloSharingPrices');
const priceRow = document.getElementById('priceRow');
const portionSizeRow = document.getElementById('portionSizeRow');

function updatePortionFields() {
  const val = parseInt(categorySelect.value);

  if (soloSharingPrices && priceRow && portionSizeRow) { // Check all exist
    if (val === friedRiceId || val === noodlesId) {
      soloSharingPrices.style.display = "";
      priceRow.style.display = "none";
      portionSizeRow.style.display = "";
    } else {
      soloSharingPrices.style.display = "none";
      priceRow.style.display = "";
      portionSizeRow.style.display = "none";
      const portionInput = document.getElementById('portion_size');
      if (portionInput) portionInput.value = "";
    }
  }
}
categorySelect.addEventListener('change', updatePortionFields);
updatePortionFields(); // Initial on page load

categorySelect.addEventListener('change', updatePortionSizeVisibility);
updatePortionSizeVisibility(); // Call on page load

/* Open modal and load profile.php into it */
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

/* Close modal (background click or close btn) */
function closeProfile(e) {
  if (!e || e.target.id === 'profileOverlay') {
    document.getElementById('profileOverlay').style.display = 'none';
  }
}

/* toggle edit (username) - kept global for inline onclick */
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

</script>
</body>
</html>