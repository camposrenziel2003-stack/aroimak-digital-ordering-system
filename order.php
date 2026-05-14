<?php
session_start();
if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

include "config.php";
function getOrderItems($conn, $orderGroupId) {
    $stmt = $conn->prepare("SELECT item_name, quantity, price, spice_level, serving_size, allergens, allergen_note FROM order_items WHERE order_group_id = ?");
    $stmt->bind_param("s", $orderGroupId);
    $stmt->execute();
    $result = $stmt->get_result();
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    return $items;
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

// ---------------------- FILTER HANDLING ----------------------
$whereClause = "WHERE DATE(created_at) = CURDATE()"; // default: today's orders
$activeFilterLabel = "Today's Orders";
$totalRevenue = 0;
$orderCount = 0;

if (isset($_GET['filter'])) {
    if ($_GET['filter'] === 'all') {
        $whereClause = ""; // all orders
        $activeFilterLabel = "All Orders";
    } elseif ($_GET['filter'] === 'custom' && !empty($_GET['from']) && !empty($_GET['to'])) {
        $from = $conn->real_escape_string($_GET['from']);
        $to = $conn->real_escape_string($_GET['to']);
        $whereClause = "WHERE DATE(created_at) BETWEEN '$from' AND '$to'";
        $activeFilterLabel = "Orders from $from to $to";
    } elseif ($_GET['filter'] === 'today') {
        $whereClause = "WHERE DATE(created_at) = CURDATE()";
        $activeFilterLabel = "Today's Orders";
    }
}

// ---------------------- SEARCH HANDLING (always applies on top of filter) ----------------------
$searchQuery = "";
if (isset($_GET['search']) && trim($_GET['search']) !== "") {
    $search = $conn->real_escape_string($_GET['search']);
    if ($whereClause === "") {
        $whereClause = "WHERE ";
    } else {
        $whereClause .= " AND ";
    }
    // Add search conditions for table_number, customer_name, item_name, status, payment_method
    $whereClause .= "(table_number LIKE '%$search%' OR customer_name LIKE '%$search%' OR item_name LIKE '%$search%' OR status LIKE '%$search%' OR payment_method LIKE '%$search%')";
    $activeFilterLabel .= " (Search: \"$search\")";
    $searchQuery = $search;
}

// Ensure archived exclusion is applied after $whereClause is built
$showArchived = isset($_GET['show_archived']) && $_GET['show_archived'] === '1';
if (!$showArchived) {
    $archCond = "(archived IS NULL OR archived = 0)";
    if (!isset($whereClause) || trim($whereClause) === '') {
        $whereClause = "WHERE $archCond";
    } else {
        $whereClause .= " AND $archCond";
    }
}

// Get total revenue and order count for filtered
$revenueSql = "SELECT SUM(total_price) AS total_rev, COUNT(*) AS total_orders 
               FROM orders 
               " . 
               ($whereClause ? "$whereClause AND" : "WHERE") . 
               " status != 'Canceled'";

$revenueRow = $conn->query($revenueSql)->fetch_assoc();
$totalRevenue = $revenueRow['total_rev'] ?? 0;
$orderCount = $revenueRow['total_orders'] ?? 0;

// now run the listing query (this must be the one used in the table)
$orderResult = $conn->query("SELECT * FROM orders $whereClause AND status != 'Canceled' AND (archived IS NULL OR archived = 0) ORDER BY id DESC");


// Handle print view
if (isset($_GET['print'])) {
    $filterQuery = "1=1";
    $reportTitle = "All Orders";
    $dateNow = date("F d, Y");

    if ($_GET['filter'] == 'today') {
        $filterQuery = "DATE(created_at) = CURDATE()";
        $reportTitle = "Daily Sales Report - $dateNow";
    } elseif ($_GET['filter'] == 'custom' && !empty($_GET['from']) && !empty($_GET['to'])) {
        $from = $_GET['from'];
        $to = $_GET['to'];
        $filterQuery = "DATE(created_at) BETWEEN '$from' AND '$to'";
        $reportTitle = "Sales Report ($from to $to)";
    }
    if (isset($_GET['search']) && trim($_GET['search']) !== "") {
        $search = $conn->real_escape_string($_GET['search']);
        $filterQuery .= " AND (table_number LIKE '%$search%' OR customer_name LIKE '%$search%' OR item_name LIKE '%$search%')";
    }

    // exclude archived in print unless explicitly requested
    if (!isset($_GET['show_archived']) || $_GET['show_archived'] !== '1') {
        $filterQuery .= " AND (archived IS NULL OR archived = 0)";
    }

$totalIncome = $conn->query("SELECT SUM(total_price) AS income 
                             FROM orders 
                             WHERE $filterQuery AND status != 'Canceled'")
                    ->fetch_assoc()['income'] ?? 0;

$totalOrders = $conn->query("SELECT COUNT(*) AS cnt 
                             FROM orders 
                             WHERE $filterQuery AND status != 'Canceled'")
                    ->fetch_assoc()['cnt'] ?? 0;

$printResult = $conn->query("SELECT * 
                             FROM orders 
                             WHERE $filterQuery AND status != 'Canceled' 
                             ORDER BY id DESC");


    echo "<html><head><title>Printable Sales Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin:40px; }
        h1 { text-align:center; color:#ff6600; margin-bottom:5px; }
        h3 { text-align:center; margin-top:0; color:#666; }
        .summary { margin-top:20px; padding:15px; background:#f9f9f9; border:1px solid #ddd; border-radius:8px; }
        .summary span { display:block; margin:5px 0; font-size:16px; }
        .summary .total { font-size:20px; font-weight:bold; color:#2e7d32; }
        table { width:100%; border-collapse:collapse; margin-top:20px; }
        th, td { border:1px solid #ccc; padding:8px; text-align:left; font-size:14px; }
        th { background:#ffe0b2; color:#ff6600; }
        .footer { margin-top:40px; text-align:center; font-size:12px; color:#888; }
    </style></head><body>";

    echo "<h1>Sales Report</h1>";
    echo "<h3>$reportTitle</h3>";
    echo "<p><strong>Generated on:</strong> $dateNow</p>";

    echo "<div class='summary'>
            <span><strong>Total Orders:</strong> $totalOrders</span>
            <span class='total'>Total Sales: ₱".number_format($totalIncome,2)."</span>
          </div>";

    echo "<table>
            <thead>
                <tr>
                    <th>Order ID</th>
                    <th>Table #</th>
                    <th>Customer</th>
                    <th>Item</th>
                    <th>Qty</th>
                    <th>Total Price</th>
                    <th>Status</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>";
    while ($row = $printResult->fetch_assoc()) {
        echo "<tr>
            <td>{$row['id']}</td>
            <td>{$row['table_number']}</td>
            <td>{$row['customer_name']}</td>
            <td class='table-items-col'>{$row['item_name']}</td>
            <td>{$row['quantity']}</td>
            <td>₱".number_format($row['total_price'],2)."</td>
            <td>{$row['status']}</td>
            <td>{$row['created_at']}</td>
        </tr>";
    }
    echo "</tbody></table>";

    echo "<div class='footer'>This is a system-generated report.</div>";

    echo "<script>window.print()</script>";
    echo "</body></html>";
    exit;
}


// ---------------------- ORDERS + ANALYTICS ----------------------
// Ensure archived exclusion is applied after $whereClause is built
$showArchived = isset($_GET['show_archived']) && $_GET['show_archived'] === '1';
if (! $showArchived) {
    $archCond = "(archived IS NULL OR archived = 0)";
    if (!isset($whereClause) || trim($whereClause) === '') {
        $whereClause = "WHERE $archCond";
    } else {
        $whereClause .= " AND $archCond";
    }
}

// now run the listing query (this must be the one used in the table)
$orderResult = $conn->query("SELECT * FROM orders $whereClause ORDER BY id DESC");

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

// DAILY ORDERS (LAST 7 DAYS)
$dailyLabels = [];
$dailyCounts = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date("Y-m-d", strtotime("-$i days"));
    $label = date("M d", strtotime($date));
    $dailyLabels[] = $label;

    $result = $conn->query("SELECT COUNT(*) AS total FROM orders WHERE DATE(created_at) = '$date'");
    $dailyCounts[] = $result->fetch_assoc()['total'];
}

// WEEKLY ORDERS (LAST 4 WEEKS)
$weeklyLabels = ["Week 1", "Week 2", "Week 3", "Week 4"];
$weeklyCounts = [];
for ($i = 3; $i >= 0; $i--) {
    $start = date("Y-m-d", strtotime("-$i week Monday"));
    $end   = date("Y-m-d", strtotime("-$i week Sunday"));

    $result = $conn->query("
        SELECT COUNT(*) AS total 
        FROM orders 
        WHERE DATE(created_at) BETWEEN '$start' AND '$end'
    ");
    $weeklyCounts[] = $result->fetch_assoc()['total'];
}

// MONTHLY ORDERS (LAST 6 MONTHS)
$monthlyLabels = [];
$monthlyCounts = [];

for ($i = 5; $i >= 0; $i--) {
    $monthLabel = date("M", strtotime("-$i months"));
    $monthNum = date("m", strtotime("-$i months"));
    $yearNum = date("Y", strtotime("-$i months"));

    $monthlyLabels[] = $monthLabel;

    $result = $conn->query("
        SELECT COUNT(*) AS total 
        FROM orders
        WHERE MONTH(created_at) = $monthNum
        AND YEAR(created_at) = $yearNum
    ");
    $monthlyCounts[] = $result->fetch_assoc()['total'];
}


// Chart data (last 7 days)
$chartLabels = [];
$chartData = [];
for ($i=6; $i>=0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $chartLabels[] = date('M d', strtotime($date));
    $count = $conn->query("SELECT COUNT(*) AS total FROM orders WHERE DATE(created_at)='$date'")->fetch_assoc()['total'];
    $chartData[] = $count;
}
// Handle download CSV
if (isset($_GET['download'])) {
    $filterQuery = "1=1";
    $reportTitle = "All Orders";
    $dateNow = date("F d, Y");

    if ($_GET['filter'] == 'today') {
        $filterQuery = "DATE(created_at) = CURDATE()";
        $reportTitle = "Daily Sales Report - $dateNow";
    } elseif ($_GET['filter'] == 'custom' && !empty($_GET['from']) && !empty($_GET['to'])) {
        $from = $_GET['from'];
        $to = $_GET['to'];
        $reportTitle = "Sales Report ($from to $to)";
    }
    if (isset($_GET['search']) && trim($_GET['search']) !== "") {
        $search = $conn->real_escape_string($_GET['search']);
        $filterQuery .= " AND (table_number LIKE '%$search%' OR customer_name LIKE '%$search%' OR item_name LIKE '%$search%')";
    }

$totalIncome = $conn->query("SELECT SUM(total_price) AS income 
                             FROM orders 
                             WHERE $filterQuery AND status != 'Canceled'")
                    ->fetch_assoc()['income'] ?? 0;

$totalOrders = $conn->query("SELECT COUNT(*) AS cnt 
                             FROM orders 
                             WHERE $filterQuery AND status != 'Canceled'")
                    ->fetch_assoc()['cnt'] ?? 0;

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=sales_report.csv');
    $output = fopen('php://output', 'w');

    // Report Header
    fputcsv($output, ["Sales Report"]);
    fputcsv($output, [$reportTitle]);
    fputcsv($output, ["Generated on:", $dateNow]);
    fputcsv($output, ["Total Orders:", $totalOrders]);
    fputcsv($output, ["Total Sales:", "₱".number_format($totalIncome,2)]);
    fputcsv($output, []); // blank line

    // Table Header
    fputcsv($output, ['Order ID','Table #','Customer','Item','Qty','Total Price','Status','Created At']);

    // Table Data
$downloadResult = $conn->query("SELECT * 
                                FROM orders 
                                WHERE $filterQuery AND status != 'Canceled' 
                                ORDER BY id DESC");
                                
    while ($row = $downloadResult->fetch_assoc()) {
        fputcsv($output, [
            $row['id'],
            $row['table_number'],
            $row['customer_name'],
            $row['item_name'],
            $row['quantity'],
            number_format($row['total_price'],2),
            $row['status'],
            $row['created_at']
        ]);
    }

    fclose($output);
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Orders Dashboard</title>
<link rel="stylesheet" href="index.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root {
  --sidebar-width: 210px;
  --container-max-width: 1500px;
  --main-background: #f7f5f2;
}

body {
    margin: 0;
    background: var(--main-background);
    font-family: 'Segoe UI', Arial, sans-serif;
    color: #403102;
}

/* CHARTS ROW CONTAINER */
.chart-row {
    display: flex;
    justify-content: flex-start;
    gap: 24px;
    margin-left: var(--sidebar-width);
    margin-right: auto;
    padding: 24px 30px 0 30px;
    max-width: var(--container-max-width);
    box-sizing: border-box;
    margin-top: 70px;
}
.chart-card {
    background: #fff;
    border: 1.5px solid #ffe0b2;
    border-radius: 16px;
    box-shadow: none;
    width: 46%;
    padding: 20px 18px 18px 20px;
    box-sizing: border-box;
    min-width: 290px;
}
.chart-title {
    font-size: 15px;
    font-weight: 600;
    margin-bottom: 8px;
    padding-bottom: 6px;
    border-bottom: 1px solid #efe3d2;
    display: flex;
    align-items: center;
    gap: 7px;
    color: #c57d05;
}
.chart-title i {
    font-size: 16px;
}
.chart-card canvas {
    width: 100% !important;
    max-width: 100% !important;
    margin-top: 10px;
}

@media (max-width:900px) {
    .header, .chart-row, .orders-card, .orders-container {
        max-width: 99vw !important;
        margin-left: var(--sidebar-width) !important;
        box-sizing: border-box;
    }
    .chart-row {
        flex-direction: column;
        gap: 13px;
    }
    .chart-card {
        width: 98%;
        min-width: 0;
    }
}

/* FILTER/SEARCH/SUMMARY + TABLE MAIN CONTENT CONTAINER */
.orders-container {
    max-width: var(--container-max-width);
    margin: 0 auto;
    background: transparent;
    margin-left: var(--sidebar-width);
    box-sizing: border-box;
    padding: 0 0px;
}

.orders-card {
  max-width: var(--container-max-width);
  margin: 32px auto;
  background: #fff;
  border-radius: 13px;
  box-shadow: none;
  border: 1.5px solid #ffe0b2;
  padding: 28px 24px 16px 24px;
  position: relative;
  z-index: 2;
  margin-left: var(--sidebar-width);
  box-sizing: border-box;
}

.orders-header {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 16px 20px;
  margin-bottom: 0px;
}
.orders-summary-row {
    margin: 10px 0 20px 0;
    padding: 14px 20px 12px 20px;
    border-radius: 12px;
    background: linear-gradient(90deg, #ffead2 80%, #fffde6 100%);
    font-size: 1.13em;
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    border: 1.2px solid #ff66002d;
    box-sizing: border-box;
}
.orders-summary-row .total-title,
.orders-summary-row .total-revenue,
.orders-summary-row .order-count,
.orders-summary-row .active-filter {
  margin: 0;
}
.orders-summary-row .total-title {
  font-weight: bold;
  color: #4d2e00;
  letter-spacing: 0.02em;
  margin-right: 16px;
}
.orders-summary-row .total-revenue {
  color: #2196f3;
  font-weight: bold;
  font-size: 1.14em;
  padding-right: 20px;
}
.orders-summary-row .order-count {
  color: #ff9800;
  font-weight: bold;
  font-size: 1.07em;
  margin-left: 24px;
}
.orders-summary-row .active-filter {
  color: #a15e0e;
  background: #fffbe9;
  border-radius: 7px;
  padding: 4px 13px;
  font-size: 0.97em;
  margin-left: 18px;
  font-weight: 500;
  box-shadow: none;
}
@media (max-width: 650px) {
    .orders-summary-row {
        flex-direction: column;
        gap: 6px;
        font-size: .98em;
        border-radius: 9px;
        padding: 9px 12px 8px 12px;
    }
    .orders-summary-row .total-revenue,
    .orders-summary-row .order-count,
    .orders-summary-row .active-filter {
        margin-left: 0;
        padding-right: 0;
    }
    .orders-card {
        padding: 13px 7vw 12px 7vw;
        max-width:97vw;
        margin-left: var(--sidebar-width);
    }
    .orders-container {
        padding:0 7vw;
        margin-left: var(--sidebar-width);
    }
}

/* Search Bar Styles */
.search-bar-container {
  display: flex;
  align-items: center;
  margin-bottom: 8px;
  margin-top: 8px;
  flex-shrink: 0;
  width: 100%;
}
.search-bar-container input[type="text"] {
  padding: 8px 14px;
  border: 1.4px solid #e0a96d;
  border-radius: 6px;
  font-size: 1rem;
  outline: none;
  flex: 1;
  margin-right: 8px;
  transition: border .18s;
  background: #fff;
}
.search-bar-container input[type="text"]:focus {
  border-color: #ff6600;
}
.search-bar-container button {
  background: #ff6600;
  color: #fff;
  border: none;
  padding: 9px 20px;
  border-radius: 6px;
  font-size: 1rem;
  cursor: pointer;
  transition: background .2s;
}
.search-bar-container button:hover {
  background: #e65c00;
}

/* Filter Form Styles */
.filter-form {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 12px;
  background: #fffbe9;
  border-radius: 9px;
  padding: 8px 15px;
  box-sizing: border-box;
  border: 1px solid #ffe0b2;
}
.filter-form label {
  font-size: 1.05em;
  color: #ad7500;
  margin-right: 5px;
  font-weight: 500;
}
.filter-form select {
  border-radius: 7px;
  border: 1.2px solid #ffd180;
  padding: 8px 12px;
  background: #fff;
  font-size: 1em;
  font-weight: 500;
  color: #ad7500;
  transition: border .15s;
  outline: none;
}
.filter-form select:focus {
  border-color: #ff9800;
}
#datePickers {
  display: inline-flex;
  gap: 6px;
  align-items: center;
}
#datePickers input[type="date"] {
  background: #fff;
  border: 1.2px solid #ffd180;
  border-radius: 7px;
  padding: 7px 10px;
  font-size: 0.98em;
  font-weight: 500;
  color: #ad7500;
  outline: none;
  transition: border .14s;
}
#datePickers input[type="date"]:focus {
  border-color: #ff9800;
  box-shadow: none;
}
.filter-form button {
  background: linear-gradient(93deg, #ffc25e 87%, #ffe0a8 100%);
  color: #fff;
  border: none;
  padding: 8px 15px;
  border-radius: 7px;
  font-size: 1em;
  font-weight: 600;
  cursor: pointer;
  box-shadow: none;
  transition: background .17s, color .17s;
  margin-left: 5px;
}
.filter-form button:hover {
  background: linear-gradient(93deg,#ff9800 87%, #ffe0a8 100%);
}
.filter-form a {
  font-size: 0.97em;
  margin-left: 5px;
  background: #fff9e6;
  color: #d77500;
  border-radius: 7px;
  padding: 7px 13px;
  text-decoration: none;
  box-shadow: none;
  border: 1px solid #ffe0b2;
  transition: background .13s, color .13s;
}
.filter-form a:hover {
  background: #ffd180;
  color: #fff;
}

/* Orders Table */
.orders-card > .orders-header {
    margin-bottom: 0;
}
.orders-container .orders-card { box-shadow: none; border-radius: 0; padding: 0; background: transparent; }
#ordersTable {
    border-radius: 12px;
    overflow: hidden;
    box-shadow: none;
    margin-top:0px;
    width: 100%;
    background: #fff;
    border: 1.5px solid #ffe0b2;
    table-layout: auto;
    min-width: 800px;
}
#ordersTable th {
    color: #d77500;
    font-weight: 600;
    font-size: 1.03em;
    border-bottom: 1.8px solid #ffd180;
}
#ordersTable tr:hover {
    background: #f7ebd6 !important;
    transition: background 0.13s;
}
#ordersTable td, #ordersTable th {
    padding: 10px 13px;
    border-bottom: 1.4px solid #ffe0b2;
    max-width: 180px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.status-badge {
    border-radius: 8px;
    padding: 5px 13px;
    font-size: 0.99em;
    font-weight: bold;
    color: #333;
}
.status-badge.completed { color: #4CAF50;}
.status-badge.pending { color: #ff8300; }
.status-badge.canceled { color: #f44336;}
.status-badge.preparing { color: #1976d2;}
.edit-link, .delete-link {
    margin-right: 8px;
    font-weight: bold;
    text-decoration: underline;
    color: #c15c00;
    cursor: pointer;
}
.edit-link:hover, .delete-link:hover { color: #c15c00; }

@media (max-width: 900px) {
    #ordersTable, .orders-summary-row, .orders-card, .orders-container, .chart-row {
        max-width: calc(100vw - var(--sidebar-width) - 20px) !important;
        min-width: 98vw;
    }
    .orders-card, .orders-container, .chart-row {
        padding: 0 7vw;
        margin-left: var(--sidebar-width);
    }
}
@media (max-width: 650px) {
    #ordersTable td, #ordersTable th { padding: 8px 7px; font-size:0.95em; }
    .orders-summary-row { padding:8px 3vw 6px 3vw; }
    .orders-container, .orders-card { max-width:98vw; min-width:0; box-sizing:border-box; }
}

#deleteModal .modal-buttons { display: flex; justify-content: flex-end; gap: 10px; margin-top: 22px;}
#deleteModal .modal-buttons button, #deleteModal .modal-buttons a.btn-archive {
    padding: 8px 83px;
    border-radius: 6px;
    font-weight: 500;
    font-size: 0.97rem;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
    text-decoration: none;
    color: #fff;
    transition: 0.2s;
}
#deleteModal .btn-archive { background-color: #ff4d4f; }
#deleteModal .btn-archive:hover { background-color: #d9363e; }
#deleteModal .btn-cancel { background-color: #6c757d;}
#deleteModal .btn-cancel:hover {background-color: #5a6268;}
.modal-close {
    position: absolute;
    top: 14px;
    right: 20px;
    font-size: 1.4em;
    color: #d77500;
    cursor: pointer;
    border:none;
    background:transparent;
}
.modal-header {
    font-size: 1.42em;
    font-weight: 700;
    color: #d77500;
    margin-bottom: 16px;
    text-align: left;
}

.empty-message {
    text-align: center;
    padding: 26px 0;
    font-size: 1.1em;
    color: #a77d34;
}
</style>
</head>
<body>
<!-- Header -->
<header class="header">
    <div class="header-left">
      <h1>Orders</h1>
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
      <!-- Profile content will load here -->
      <p style="text-align:center;">Loading...</p>
    </div>
  </div>
</div>

<!-- Sidebar -->
<div class="sidebar">
    <img src="logo.png" class="logo" alt="Logo">
    <div class="sidebar-scroll">
        <nav>
            <a href="index.php">
                <i class="fa-solid fa-chart-line"></i> Dashboard
            </a>
            <a href="order.php"  class="active">
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

<!-- Analytics (card position preserved above) -->
<div class="chart-row">

    <div class="chart-card">
        <div class="chart-title">
            <i class="fa fa-calendar-day"></i> Per Day Orders
        </div>
        <canvas id="dailyChart"></canvas>
    </div>

    <div class="chart-card">
        <div class="chart-title">
            <i class="fa fa-calendar-alt"></i> Monthly Orders
        </div>
        <canvas id="monthlyChart"></canvas>
    </div>

</div>

<!-- Filter Form -->
<div class="orders-card">
    <div class="orders-header">
        <h2>Orders</h2>

        <!-- SEARCH BAR + FILTER -->
        <form method="get" id="searchForm" class="search-bar-container" autocomplete="off" onsubmit="return true;">
            <input type="hidden" name="filter" value="<?= htmlspecialchars($_GET['filter'] ?? '') ?>">
            <input type="hidden" name="from" value="<?= htmlspecialchars($_GET['from'] ?? '') ?>">
            <input type="hidden" name="to" value="<?= htmlspecialchars($_GET['to'] ?? '') ?>">
            <input type="text" id="orderSearchInput" name="search" placeholder="Search orders, customers, status..." value="<?= htmlspecialchars($searchQuery) ?>">
            <button type="submit"><i class="fa fa-search"></i> Search</button>
        </form>
        <form method="get" class="filter-form" style="gap:5px;">
            <label>Show:</label>
            <select name="filter" onchange="this.form.submit()">
                <option value="today" <?= ($_GET['filter'] ?? '')=='today'?'selected':'' ?>>Today</option>
                <option value="all" <?= ($_GET['filter'] ?? '')=='all'?'selected':'' ?>>All Orders</option>
                <option value="custom" <?= ($_GET['filter'] ?? '')=='custom'?'selected':'' ?>>Custom Range</option>
            </select>
            <span id="datePickers" style="display:<?= ($_GET['filter'] ?? '')=='custom' ? 'inline' : 'none' ?>;">
                <input type="date" name="from" value="<?= $_GET['from'] ?? '' ?>" onchange="this.form.submit()">
                <input type="date" name="to" value="<?= $_GET['to'] ?? '' ?>" onchange="this.form.submit()">
            </span>
            <button type="submit">Apply</button>
            <a href="order.php?print=1&filter=<?= $_GET['filter'] ?? '' ?>&from=<?= $_GET['from'] ?? '' ?>&to=<?= $_GET['to'] ?? '' ?>&search=<?= htmlspecialchars($searchQuery) ?>" target="_blank">🖨 Print</a>
            <a href="order.php?download=1&filter=<?= $_GET['filter'] ?? '' ?>&from=<?= $_GET['from'] ?? '' ?>&to=<?= $_GET['to'] ?? '' ?>&search=<?= htmlspecialchars($searchQuery) ?>">⬇ Download</a>
        </form>
    </div>
    <!-- ENHANCED SUMMARY ROW -->
    <div class="orders-summary-row">
        <div class="total-title">
            <span><i class="fa fa-wallet"></i> Total Revenue:</span>
            <span class="total-revenue">₱<?= number_format($totalRevenue, 2) ?></span>
        </div>
        <div class="order-count">
            <i class="fa fa-list-alt"></i> Orders: <?= $orderCount ?>
        </div>
        <span class="active-filter"><?= htmlspecialchars($activeFilterLabel) ?></span>
    </div>
</div>

<!-- Orders Table -->
    <div class="orders-card">
        <table id="ordersTable">
            <thead>
                <tr>
                    <th>Table #</th>
                    <th>Customer Name</th>
                    <th>Menu Items</th>
                    <th>Qty</th>
                    <th>Total Price</th>
                    <th>Payment</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="ordersTableBody">
            <?php if ($orderResult->num_rows > 0): ?>
                <?php while ($order = $orderResult->fetch_assoc()): ?>
                <tr>
                    <td><?= htmlspecialchars($order['table_number']) ?></td>
                    <td><?= htmlspecialchars($order['customer_name']) ?></td>
                    <td><?= htmlspecialchars($order['item_name']) ?></td>
                    <td><?= $order['quantity'] ?></td>
                    <td>₱<?= number_format($order['total_price'], 2) ?></td>
                    <td><?= htmlspecialchars($order['payment_method'] ?? '') ?></td>
                    <td>
                        <span class="status-badge 
                        <?= strtolower($order['status'])=='pending' ? 'pending' : 
                            (strtolower($order['status'])=='completed' ? 'completed' : 
                            (strtolower($order['status'])=='canceled' ? 'canceled' : 
                            (strtolower($order['status'])=='preparing' ? 'preparing' : ''))) ?>"> 
                            <?= htmlspecialchars($order['status']) ?>
                        </span>
                    </td>
                    <td>
                        <?php $items = getOrderItems($conn, $order['order_group_id']); ?>
<a href="javascript:void(0);" class="edit-link" 
   onclick='openDetailsModal(<?= json_encode($order) ?>, <?= json_encode($items) ?>)'>View Details</a>
                        <a href="javascript:void(0);" class="delete-link" onclick='openArchiveModal(<?= $order['id'] ?>)'>Archive</a>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="8" class="empty-message">No orders found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- View Details Modal -->
<div id="detailsModal" class="modal">
  <div class="modal-content">
    <span class="modal-close" onclick="closeModal('detailsModal')">&times;</span>
    <div class="modal-header">Order Details</div>
    <div id="detailsContent"></div>
  </div>
</div>

<!-- Arkiv Confirmation Modal -->
<div id="deleteModal" class="modal">
  <div class="modal-content">
    <span class="modal-close" onclick="closeModal('deleteModal')">&times;</span>
    <h2>Confirm Archive</h2>
    <p>Are you sure you want to archive this order? It will be hidden from the active orders list but can be restored later.</p>
    <div class="modal-buttons">
      <a id="confirmArchiveBtn" href="#" class="btn-archive">
        <i class="fa-solid fa-box-archive"></i> Archive
      </a>
      <button class="btn btn-cancel" onclick="closeModal('deleteModal')">Cancel</button>
    </div>
  </div>
</div>

<script>
function toggleDatePickers() {
  var filter = document.querySelector('select[name="filter"]').value;
  var datePickers = document.getElementById('datePickers');
  if (filter === 'custom') {
    datePickers.style.display = 'inline';
  } else {
    datePickers.style.display = 'none';
  }
}
document.addEventListener('DOMContentLoaded', function() { toggleDatePickers(); });

function openDetailsModal(order, items) {
  const spiceMap = { 1:"Light", 2:"Moderate", 3:"Spicy", 4:"Extra" };
  function escapeHtml(str) {
    return String(str).replace(/[&<>"'`=\/]/g, function(s) {
      return {"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;","/":"&#x2F;","`":"&#x60;","=":"&#x3D;"}[s];
    });
  }
  let itemsHtml = "";
  if (Array.isArray(items) && items.length > 0) {
    for (const item of items) {
      let itemLine = `<div style="margin-bottom:20px;">`;
      itemLine += `<div style="font-weight:bold;font-size:1.2em;">${item.quantity}x ${escapeHtml(item.item_name)}`;
      if (item.serving_size && item.serving_size.toLowerCase() === "sharing") {
        itemLine += ` <span style="color:#2ecc71;font-weight:600;">(Sharing)</span>`;
      }
      itemLine += `</div>`;
      if (item.price) { itemLine += `<div style="margin-bottom:2px;">₱${parseFloat(item.price).toFixed(2)}</div>`; }
      if (item.spice_level && item.spice_level!="NULL" && item.spice_level!="") {
        itemLine += `<div style="color:#de4848;">Spice Level: ${spiceMap[item.spice_level]||escapeHtml(item.spice_level)}</div>`;
      }
      if (item.allergens && item.allergens.trim() != "") {
        itemLine += `<div style="color:#337ab7;">Allergens: ${escapeHtml(item.allergens)}</div>`;
      }
      if (item.allergen_note && item.allergen_note.trim() != "") {
        itemLine += `<div style="color:#3d9356;">Notes: ${escapeHtml(item.allergen_note)}</div>`;
      }
      itemLine += `</div>`;
      itemsHtml += itemLine;
    }
  } else {
    itemsHtml += `<div style="font-weight:bold;font-size:1.2em;">${order.quantity}x ${escapeHtml(order.item_name)}</div>`;
  }
  let paymentMethodHtml = '';
  if(order.payment_method) {
    paymentMethodHtml = `<p><strong>Payment Method:</strong> ${escapeHtml(order.payment_method)}</p>`;
  } else {
    paymentMethodHtml = `<p><strong>Payment Method:</strong> <span style="color:#888;">(None)</span></p>`;
  }
  const content = `
    <p><strong>Order ID:</strong> ${order.id}</p>
    <p><strong>Table #:</strong> ${order.table_number}</p>
    <p><strong>Customer:</strong> ${order.customer_name}</p>
    ${paymentMethodHtml}
    <div style="margin: 18px 0 8px 0;"><strong>Items:</strong></div>
    ${itemsHtml}
    <p><strong>Total Price:</strong> ₱${parseFloat(order.total_price).toFixed(2)}</p>
    <p><strong>Status:</strong> ${order.status}</p>
    <p><strong>Created At:</strong> ${order.created_at}</p>
  `;
  document.getElementById("detailsContent").innerHTML = content;
  document.getElementById("detailsModal").style.display = "block";
}
function openArchiveModal(orderId) {
  var btn = document.getElementById("confirmArchiveBtn");
  if (btn) btn.href = "archive_order.php?id=" + encodeURIComponent(orderId);
  var modal = document.getElementById("deleteModal");
  if (modal) modal.style.display = "block";
}
function closeModal(id) {
  document.getElementById(id).style.display = "none";
}
</script>

<script>
// SEARCH BAR "LIVE" FILTER
document.addEventListener('DOMContentLoaded', function () {
  const searchInput = document.getElementById('orderSearchInput');
  const tableBody = document.getElementById('ordersTableBody');
  if (!searchInput || !tableBody) return;
  const allRows = Array.from(tableBody.querySelectorAll('tr'));
  const noOrdersRow = allRows.find(row => row.querySelector('.empty-message'));
  searchInput.addEventListener('input', function () {
    const value = searchInput.value.trim().toLowerCase();
    let shown = 0;
    allRows.forEach(row => {
      if (row === noOrdersRow) { row.style.display = 'none'; return; }
      const cells = row.querySelectorAll('td');
      const searchText = Array.from(cells).slice(0, 3).map(td => td.textContent.toLowerCase()).join(' ');
      if (searchText.includes(value)) { row.style.display = ''; shown++; } else { row.style.display = 'none'; }
    });
    if (noOrdersRow) { noOrdersRow.style.display = shown === 0 ? '' : 'none'; }
  });
});
</script>

<script>
//Profile
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
          if (headerPic) { headerPic.src = newImg.getAttribute('src').split('?')[0] + '?v=' + Date.now(); }
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
function closeProfile(e) { if (!e || e.target.id === 'profileOverlay') { document.getElementById('profileOverlay').style.display = 'none'; } }
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

<script>
    // DAILY BAR CHART
    new Chart(document.getElementById('dailyChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($dailyLabels) ?>,
            datasets: [{
                label: "Per Day Orders",
                data: <?= json_encode($dailyCounts) ?>,
                backgroundColor: 'rgba(30,144,255,0.5)',
                borderColor: 'rgba(30,144,255,1)',
                borderWidth: 2
            }]
        }
    });

    // MONTHLY BAR CHART
    new Chart(document.getElementById('monthlyChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($monthlyLabels) ?>,
            datasets: [{
                label: "Per Month Orders",
                data: <?= json_encode($monthlyCounts) ?>,
                backgroundColor: 'rgba(255,165,0,0.5)',
                borderColor: 'rgba(255,165,0,1)',
                borderWidth: 2
            }]
        }
    });
</script>
</body>
</html>