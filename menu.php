<?php
session_start();
if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}
include "config.php";

$currentPage = basename($_SERVER['PHP_SELF']);

// List of available preferences
$all_prefs = ['Spicy','Noodles','Soup','Salad','Seafood','Drinks','Veggie','Curry','Pork','Sweet','Chicken','Rice'];

// Fetch categories with their menu items
$categoriesResult = $conn->query("SELECT * FROM categories ORDER BY name ASC");
$menuByCategory = [];
$categories = [];
$friedRiceId = $noodlesId = null;

while ($cat = $categoriesResult->fetch_assoc()) {
    $catId = (int) $cat['id'];
    $catName = htmlspecialchars($cat['name']);

    // Prepare statement to avoid SQL injection and exclude archived items
    $stmt = $conn->prepare("
        SELECT *
        FROM menu_items
        WHERE category = ? AND (archived IS NULL OR archived = 0)
        ORDER BY name ASC
    ");
    $stmt->bind_param("i", $catId);
    $stmt->execute();
    $itemsResult = $stmt->get_result();
    $items = $itemsResult->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $menuByCategory[$catId] = [
        'name'  => $catName,
        'image' => $cat['image'] ?? '',
        'items' => $items
    ];
    $categories[$catId] = $catName;

    if (strtolower($catName) === 'fried rice') $friedRiceId = $catId;
    if (strtolower($catName) === 'noodles') $noodlesId = $catId;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Menu Management</title>
<link rel="stylesheet" href="index.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
.menu-image { width: 90px; height: auto; border-radius: 6px; }
.pref-tag { display:inline-block; background:#ffe0b2; color:#ff6600; border-radius:12px; padding:2px 6px; margin:4px; font-size:0.85rem; }
.modal { display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background-color: rgba(0,0,0,0.6); justify-content:center; align-items:center; }
.modal-content { background-color:#fff; padding:20px; border-radius:8px; max-width:500px; width:90%; position:relative; box-shadow:0 2px 8px rgba(0,0,0,0.3); }
.close-btn { position:absolute; top:10px; right:10px; cursor:pointer; font-size:20px; }
.pref-checkbox { display:inline-block; margin:4px; }
.pref-checkbox input[type="checkbox"] { display:none; }
.pref-checkbox span { display:inline-block; padding:4px 10px; border-radius:12px; background-color:#ffe0b2; color:#ff6600; cursor:pointer; font-size:0.85rem; transition:0.2s; }
.pref-checkbox input[type="checkbox"]:checked + span { background-color:#ff6600; color:#fff; }
.menu-category { margin-bottom:40px; }
.category-header { display:flex; align-items:center; justify-content:space-between; }
.category-header h2 { margin:2; }
.category-header a.edit-cat { font-size:16px; color:#ff6600; text-decoration:none; margin-left:10px; }
/* Edit Category Modal Styling */
#editCategoryModal .modal-content {
    max-width: 500px;
    margin: 100px auto;
    padding: 20px 30px;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
    position: relative;
    display: flex;
    flex-direction: column;
}

#editCategoryModal .modal-content h2 {
    margin-bottom: 20px;
    font-size: 1.5rem;
    color: #333;
}

#editCategoryModal .modal-content label {
    display: block;
    margin-top: 12px;
    font-weight: bold;
    color: #555;
}

#editCategoryModal .modal-content input[type="text"],
#editCategoryModal .modal-content input[type="file"] {
    width: 100%;
    padding: 8px;
    margin-top: 6px;
    border-radius: 4px;
    border: 1px solid #ccc;
}

#editCategoryModal .modal-content button {
    margin-top: 20px;
    padding: 10px 20px;
    background: #ff6600;
    color: #fff;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    transition: 0.2s;
}
#editCategoryModal .modal-content button:hover {
    background: #e65c00;
}

#editCategoryModal .modal-content .current-image {
    margin-top: 10px;
    max-width: 120px;
    border-radius: 6px;
    border: 1px solid #ddd;
}

#editCategoryModal .close-btn {
    position: absolute;
    top: 12px;
    right: 15px;
    font-size: 22px;
    font-weight: bold;
    color: #333;
    cursor: pointer;
    transition: 0.2s;
}
#editCategoryModal .close-btn:hover {
    color: #ff6600;
}

/* Optional: overlay flex centering */
#editCategoryModal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0; top: 0;
    width: 100%; height: 100%;
    background-color: rgba(0,0,0,0.5);
    justify-content: center;
    align-items: center;
}

@media screen and (max-width: 600px){
    #editCategoryModal .modal-content {
        width: 90%;
        margin: 50px auto;
        padding: 15px;
    }
}
/* Edit Stock Modal styling */
#stockModal .modal-content {
    max-width: 400px;
    margin: 100px auto;
    padding: 20px 30px;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
    position: relative;
    display: flex;
    flex-direction: column;
}

#stockModal .modal-content h2 {
    margin-bottom: 20px;
    font-size: 1.5rem;
    color: #333;
}

#stockModal .modal-content label {
    display: block;
    margin-top: 12px;
    font-weight: bold;
    color: #555;
}

#stockModal .modal-content input[type="text"],
#stockModal .modal-content select {
    width: 100%;
    padding: 8px;
    margin-top: 6px;
    border-radius: 4px;
    border: 1px solid #ccc;
}

#stockModal .modal-content button {
    margin-top: 20px;
    padding: 10px 20px;
    background: #28a745;
    color: #fff;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    transition: 0.2s;
}
#stockModal .modal-content button:hover {
    background: #218838;
}
/* Action links styling */
.edit-link, .stock-link, .archive-link {
    text-decoration: none;
    font-weight: 500;
}

.edit-link, .stock-link {
    color: #ff6600;
}
.edit-link:hover, .stock-link:hover {
    color: #e65c00;
}

.archive-link {
    color: red;
}
.archive-link:hover {
    color: darkred;
}
/* Action buttons */
.action-btn {
    display: inline-block;
    padding: 5px 12px;
    gap:5px;
    border-radius: 16px;
    font-size: 0.85rem;
    font-weight: 500;
    text-decoration: none;
    transition: background 0.2s ease;
    margin: 0 2px;
}

.edit-link, .stock-link {
    background: #4cd267ff;
    color: #002f08ff;
}
.edit-link:hover, .stock-link:hover {
    background: #ffcc80;
    color: #e65c00;
}

.archive-link {
    background: #f8d7da;
    color: #d9534f;
}
.delete-link:hover {
    background: #f5c6cb;
    color: #c82333;
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
/* Add to your existing <style> */
.unavailable-row {
  background: #f5f5f5;      /* light grey background */
  color: #6c6c6c;           /* muted text */
}
.unavailable-row td {
  opacity: 0.9;
}
.unavailable-row .action-btn {
  opacity: 0.5;
  pointer-events: none;     /* disable action buttons when unavailable (optional) */
}
.unavailable-badge {
  background:#f8d7da;
  color:#7a1f1f;
  padding:4px 8px;
  border-radius:12px;
  font-weight:600;
  display:inline-block;
}
.available-badge {
  background:#dff0d8;
  color:#2f6627;
  padding:4px 8px;
  border-radius:12px;
  font-weight:600;
  display:inline-block;
}

</style>
</head>
<body>

<!-- Header -->
<header class="header">
    <div class="header-left">
        <h1>Menu Availability</h1>
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
            <a href="order.php">
                <i class="fa-solid fa-receipt"></i> Orders
            </a>
            <a href="popular_dishes.php" class="popular-link<?php if(basename($_SERVER['PHP_SELF'])=='popular_dishes.php') echo ' active'; ?>">
        <i class="fa-solid fa-fire"></i> Popular Dishes
    </a>
            <a href="menu.php"  class="active">
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

<div class="menu-page">
    <?php foreach($menuByCategory as $catId => $catData): ?>
    <div class="menu-category">
        <div class="category-header">
            <h2><?= $catData['name'] ?></h2>
            <a href="javascript:void(0);" class="edit-cat" onclick='openEditCategoryModal(<?= json_encode(['id'=>$catId,'name'=>$catData['name'],'image'=>$catData['image']]) ?>)'>
                <i class="fa-solid fa-pen"></i> Edit Category
            </a>
        </div>
        <div class="menu-card">
            <table>
                <tr>
                    <th>Image</th>
                    <th>Name</th>
                    <th>Description</th>
                    <?php if (strtolower($catData['name']) === 'fried rice' || strtolower($catData['name']) === 'noodles'): ?>
                        <th>Price (Solo)</th>
                        <th>Price (Sharing)</th>
                    <?php else: ?>
                        <th>Price</th>
                    <?php endif; ?>
                    <th>Preferences</th>
                    <th>Stock</th>
                    <th>Actions</th>
                </tr>
                <?php if(count($catData['items']) > 0): ?>
                    <?php foreach($catData['items'] as $item): ?>
                    <tr>
                        <td>
                            <?php if(!empty($item['image'])): ?>
                                <img src="uploads/<?= htmlspecialchars($item['image']) ?>" class="menu-image">
                            <?php else: ?>
                                <span style="color:#aaa;">No Image</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($item['name']) ?></td>
                        <td><?= htmlspecialchars($item['description']) ?></td>
                        <?php if (strtolower($catData['name']) === 'fried rice' || strtolower($catData['name']) === 'noodles'): ?>
                            <td>
                                <?= $item['price_solo'] !== null ? ('₱' . number_format($item['price_solo'],2)) : "-" ?>
                            </td>
                            <td>
                                <?= $item['price_sharing'] !== null ? ('₱' . number_format($item['price_sharing'],2)) : "-" ?>
                            </td>
                        <?php else: ?>
                            <td>₱<?= number_format($item['price'],2) ?></td>
                        <?php endif; ?>
                        <td>
                            <?php 
                            $prefs = explode(',', $item['preferences'] ?? '');
                            foreach($prefs as $p) if($p) echo '<span class="pref-tag">'.htmlspecialchars($p).'</span>';
                            ?>
                        </td>
                        <td>
                            <?php
                            // Determine stock & availability safely
                            $stock = isset($item['stock']) ? (int)$item['stock'] : null;
                            // If there's an `available` column use it; otherwise infer from stock
                            $available = null;
                            if (isset($item['available'])) {
                                $available = (int)$item['available'];
                            } elseif ($stock !== null) {
                                $available = $stock > 0 ? 1 : 0;
                            }

                            if ($stock === null) {
                                // no stock column present / value unknown
                                echo '<span style="color:#888;">—</span>';
                            } else {
                                if ($available === 1) {
                                    // available
                                    echo ' <span style="margin-left:8px;color:#333;font-weight:600;">' . $stock . '</span>';
                                } else {
                                    // unavailable (stock = 0)
                                    echo ' <span style="margin-left:8px;color:#333;font-weight:600;">' . $stock . '</span>';
                                }
                            }
                            ?>
                        </td>
                        <td>
                            <a href="javascript:void(0);" class="action-btn edit-link" onclick='openEditModal(<?= htmlspecialchars(json_encode($item), ENT_QUOTES, 'UTF-8') ?>)'>Edit</a>
                            <a href="javascript:void(0);" class="action-btn stock-link" onclick='openStockModal(<?= htmlspecialchars(json_encode($item), ENT_QUOTES, 'UTF-8') ?>)'>Edit Stock</a>
                            <a href="javascript:void(0);" class="action-btn archive-link"
   <a onclick='openArchiveModal(<?= (int)$item['id'] ?>, <?= json_encode($item['name']) ?>)'>Archive</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="8" class="empty-message">No items in this category.</td></tr>
                <?php endif; ?>
            </table>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Edit Item Modal -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <span class="close-btn" onclick="closeEditModal()">&times;</span>
        <h2>Edit Menu Item</h2>
        <form id="editForm" method="POST" enctype="multipart/form-data" action="update_item.php">
            <input type="hidden" name="id" id="itemId">
            <label>Item Name:</label>
            <input type="text" name="name" id="itemName" required>
            <label>Description:</label>
            <textarea name="description" id="itemDesc"></textarea>
            <!-- Price fields: dynamic -->
            <div id="editPriceRow">
                <label>Price (₱):</label>
                <input type="number" step="0.01" name="price" id="itemPrice">
            </div>
            <div id="editSoloSharingPrices" style="display:none;">
                <label>Price for Solo (₱):</label>
                <input type="number" step="0.01" name="price_solo" id="itemPriceSolo">
                <label>Price for Sharing (₱):</label>
                <input type="number" step="0.01" name="price_sharing" id="itemPriceSharing">
            </div>
            <label>Category:</label>
            <select name="category_id" id="itemCategory" required>
                <?php foreach($categories as $id=>$name): ?>
                    <option value="<?= $id ?>"><?= $name ?></option>
                <?php endforeach; ?>
            </select>
            <label>Preferences:</label><br>
            <div id="preferencesContainer">
                <?php foreach($all_prefs as $pref): ?>
                    <label class="pref-checkbox">
                        <input type="checkbox" name="preferences[]" value="<?= $pref ?>">
                        <span><?= $pref ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <label>Current Pronunciation:</label><br>
            <audio id="currentPronunciation" controls style="width:150px; display:none;">
                <source src="" type="audio/mpeg">
            </audio><br><br>
            <label>Upload New Pronunciation (MP3/WAV/M4A):</label>
            <input type="file" name="pronunciation_file" accept="audio/*"><br><br>
            <label>Current Image:</label><br>
            <img id="currentImage" src="" width="120"><br><br>
            <label>Upload New Image:</label>
            <input type="file" name="image" accept="image/*"><br><br>
            <input type="hidden" name="redirect" value="<?= $currentPage ?>">
            <button type="submit">Update Item</button>
        </form>
    </div>
</div>
<!-- Edit Category Modal -->
<div id="editCategoryModal" class="modal">
    <div class="modal-content">
        <span class="close-btn" onclick="closeEditCategoryModal()">&times;</span>
        <h2>Edit Category</h2>
        <form id="editCategoryForm" method="POST" enctype="multipart/form-data" action="manage_categories.php">
            <input type="hidden" name="id" id="categoryId">
            <label>Category Name:</label>
            <input type="text" name="name" id="categoryName" required>

            <label>Current Image:</label>
            <img id="categoryImage" src="" class="current-image"><br><br>

            <label>Upload New Image:</label>
            <input type="file" name="image" accept="image/*"><br><br>

            <button type="submit">Update Category</button>
        </form>
    </div>
</div>

<!-- Archive confirmation modal (replace previous #deleteModal block) -->
<div id="deleteModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="deleteModalTitle" aria-describedby="deleteModalDesc">
  <div class="modal-content">
    <button class="close-btn" type="button" aria-label="Close" id="cancelArchiveBtn">&times;</button>

    <h2 id="deleteModalTitle">Confirm Archive</h2>
    <p id="deleteModalDesc">Are you sure you want to archive this item?</p>

    <div style="margin-top:20px;">
      <button id="confirmArchiveBtn" type="button" style="background-color:#ff9f0a;color:white;padding:8px 16px;border:none;border-radius:4px;">
        <i class="fa-solid fa-box-archive" aria-hidden="true"></i> Archive
      </button>

      <button id="cancelArchiveBtn2" type="button" style="margin-left:10px;padding:8px 16px;border-radius:4px;">
        Cancel
      </button>
    </div>
  </div>
</div>
<!-- Edit Stock Modal -->
<div id="stockModal" class="modal">
  <div class="modal-content">
    <span class="close-btn" onclick="closeStockModal()">&times;</span>
    <h2>Update Dish Stock</h2>
    <form id="stockForm" method="POST" action="update_stock.php">
      <input type="hidden" name="id" id="stockItemId">

      <label>Dish:</label>
      <input type="text" id="stockItemName" disabled style="width:100%; padding:6px; margin-bottom:10px;">

      <label>Stock (0 = Unavailable, max 100):</label>
      <input type="number" name="stock" id="stockValue" min="0" max="100" step="1"
             style="width:100%; padding:6px; margin-bottom:10px;" required>

      <small style="display:block; color:#666; margin-bottom:8px;">
        Enter a whole number between 0 and 100. Setting stock to 0 marks this dish unavailable.
      </small>

      <input type="hidden" name="redirect" value="<?= htmlspecialchars($currentPage) ?>">
      <button type="submit" id="saveStockBtn" style="margin-top:15px; background:#28a745; color:#fff; padding:8px 16px; border:none; border-radius:4px;">Save</button>
    </form>
  </div>
</div>


            <input type="hidden" name="redirect" value="<?= $currentPage ?>">
            <button type="submit" style="margin-top:15px; background:#28a745; color:#fff; padding:8px 16px; border:none; border-radius:4px;">Save</button>
        </form>
    </div>
</div>

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
function speak(text){
    let voices = speechSynthesis.getVoices();
    let thaiMale = voices.find(v=>v.lang.startsWith('th') && v.name.toLowerCase().includes('male'));
    if(!thaiMale) thaiMale = voices.find(v=>v.lang.startsWith('th')) || voices[0];
    const utterance = new SpeechSynthesisUtterance(text);
    utterance.voice = thaiMale;
    utterance.rate = 0.9; utterance.pitch = 1;
    speechSynthesis.speak(utterance);
}
speechSynthesis.onvoiceschanged = ()=>{ console.log("Voices loaded"); };

function openEditModal(item){
    document.getElementById('editModal').style.display='flex';
    document.getElementById('itemId').value=item.id;
    document.getElementById('itemName').value=item.name;
    document.getElementById('itemDesc').value=item.description;
    document.getElementById('itemCategory').value=item.category;
    document.getElementById('currentImage').src = item.image ? 'uploads/'+item.image : '';

    
      // Preferences
    const prefs = (item.preferences || '').split(',');
    document.querySelectorAll('#preferencesContainer input[type=checkbox]').forEach(cb=>{
        cb.checked = prefs.includes(cb.value);
    });

    // Pronunciation
    const audioEl = document.getElementById('currentPronunciation');
    if(item.pronunciation_file && item.pronunciation_file !== ''){
        audioEl.style.display = 'inline-block';
        audioEl.querySelector('source').src = 'uploads/pronunciations/' + item.pronunciation_file;
        audioEl.load();
    } else {
        audioEl.style.display = 'none';
    }

       // Price fields
    showPriceFields(item.category, item.price, item.price_solo, item.price_sharing);

    // Portion Size field
    showPortionSizeField(item.category, item.portion_size);
}

const friedRiceId = <?= json_encode($friedRiceId) ?>;
const noodlesId = <?= json_encode($noodlesId) ?>;

// Show/hide portion_size field
function showPortionSizeField(categoryId, portionValue) {
    const row = document.getElementById('editPortionSizeRow');
    const field = document.getElementById('editPortionSize');
    if (parseInt(categoryId) === parseInt(friedRiceId) || parseInt(categoryId) === parseInt(noodlesId)) {
        row.style.display = '';
        if (portionValue !== undefined) field.value = portionValue || "";
    } else {
        row.style.display = 'none';
        field.value = "";
    }
}

// Also update when category is changed in modal
document.getElementById('itemCategory').addEventListener('change', function() {
    showPriceFields(this.value);
    showPortionSizeField(this.value);
});

function closeEditModal(){ document.getElementById('editModal').style.display='none'; }


// Edit Category Modal functions
function openEditCategoryModal(cat){
    document.getElementById('editCategoryModal').style.display='flex';
    document.getElementById('categoryId').value = cat.id;
    document.getElementById('categoryName').value = cat.name;
    document.getElementById('categoryImage').src = cat.image ? 'uploads/' + cat.image : '';
}
function closeEditCategoryModal(){ document.getElementById('editCategoryModal').style.display='none'; }


<!-- Replace this JS fragment in menu.php (remove the stray if(event...) line and add stockModal to the global click handler) -->
/* Close stock modal */
function closeStockModal() {
  const stockModal = document.getElementById('stockModal');
  if (stockModal) stockModal.style.display = 'none';
}

/* Open archive confirmation modal for an item */
let archiveUrl = '';

function openArchiveModal(itemId, itemName) {
  const redirect = encodeURIComponent('<?= $currentPage ?>');
  archiveUrl = 'archive_item.php?id=' + encodeURIComponent(itemId) + '&redirect=' + redirect;

  const msgEl = document.getElementById('deleteMessage');
  if (msgEl) msgEl.innerText = `Are you sure you want to archive "${itemName}"?`;

  const modal = document.getElementById('deleteModal');
  if (modal) modal.style.display = 'flex';
}

/* Close archive modal */
function closeArchiveModal() {
  archiveUrl = '';
  const modal = document.getElementById('deleteModal');
  if (modal) modal.style.display = 'none';
}

/* Confirm archive action (navigates to archive endpoint).
   If you prefer a POST/ajax flow for safety, I can provide that instead. */
function confirmArchive() {
  if (!archiveUrl) return;
  window.location.href = archiveUrl;
}

/* Open stock modal and populate fields */
function openStockModal(item) {
  const modal = document.getElementById('stockModal');
  if (!modal) return;

  // populate fields
  document.getElementById('stockItemId').value = item.id;
  document.getElementById('stockItemName').value = item.name || '';
  // if backend uses `stock` as column, ensure item.stock is numeric; otherwise default to 0
  const stockVal = (typeof item.stock !== 'undefined' && item.stock !== null) ? parseInt(item.stock, 10) : 0;
  document.getElementById('stockValue').value = isNaN(stockVal) ? 0 : Math.max(0, Math.min(100, stockVal));

  modal.style.display = 'flex';

  // focus the stock input for convenience
  const input = document.getElementById('stockValue');
  if (input) input.focus();
}

function closeStockModal() {
  const modal = document.getElementById('stockModal');
  if (!modal) return;
  modal.style.display = 'none';
  // clear form (optional)
  document.getElementById('stockForm').reset();
}

// Client-side validation: clamp value to [0,100] on submit and prevent accidental text
document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('stockForm');
  if (!form) return;

  form.addEventListener('submit', function (e) {
    const input = document.getElementById('stockValue');
    if (!input) return;
    let val = parseInt(input.value, 10);
    if (isNaN(val)) val = 0;
    if (val < 0) val = 0;
    if (val > 100) val = 100;
    input.value = val;

    // If you want a confirmation when setting to 0, uncomment below:
    // if (val === 0 && !confirm('Setting stock to 0 will mark this item unavailable. Proceed?')) {
    //   e.preventDefault();
    //   return;
    // }

    // allow the form to submit to update_stock.php, which handles setting availability
  });

  // Optional: ensure number input does not accept paste of invalid chars
  const num = document.getElementById('stockValue');
  if (num) {
    num.addEventListener('input', () => {
      let v = parseInt(num.value, 10);
      if (isNaN(v)) return;
      if (v < 0) num.value = 0;
      if (v > 100) num.value = 100;
    });
  }
});

/* Backdrop click handler (your existing centralized handler may already close modals).
   If not, clicking outside the modal closes it: */
window.addEventListener('click', function (event) {
  const stockModal = document.getElementById('stockModal');
  if (stockModal && event.target === stockModal) closeStockModal();
});

/* Close edit modal */
function closeEditModal() {
  const m = document.getElementById('editModal');
  if (m) m.style.display = 'none';
}

/* Close edit category modal */
function closeEditCategoryModal() {
  const m = document.getElementById('editCategoryModal');
  if (m) m.style.display = 'none';
}


/* Centralized overlay click handler to close modals when clicking backdrop */
window.addEventListener('click', function(event) {
  const editModal = document.getElementById('editModal');
  const deleteModal = document.getElementById('deleteModal');
  const editCategoryModal = document.getElementById('editCategoryModal');
  const stockModal = document.getElementById('stockModal');

  if (editModal && event.target === editModal) closeEditModal();
  if (deleteModal && event.target === deleteModal) closeArchiveModal();
  if (editCategoryModal && event.target === editCategoryModal) closeEditCategoryModal();
  if (stockModal && event.target === stockModal) closeStockModal();
});

// Hook up the modal buttons (run after your openArchiveModal() function is defined)
document.addEventListener('DOMContentLoaded', function() {
  const confirmBtn = document.getElementById('confirmArchiveBtn');
  const cancelBtn = document.getElementById('cancelArchiveBtn2');
  const closeX = document.querySelector('#deleteModal .close-btn');

  if (confirmBtn) confirmBtn.addEventListener('click', function(){ confirmArchive(); });
  if (cancelBtn) cancelBtn.addEventListener('click', function(){ closeArchiveModal(); });
  if (closeX) closeX.addEventListener('click', function(){ closeArchiveModal(); });

  // optional: close modal on ESC
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') {
      const m = document.getElementById('deleteModal');
      if (m && m.style.display === 'flex') closeArchiveModal();
    }
  });
});
// Stock Modal functions
function openStockModal(item){
    console.log('openStockModal', item);
    document.getElementById('stockModal').style.display='flex';
    document.getElementById('stockItemId').value=item.id;
    document.getElementById('stockItemName').value=item.name;
    document.getElementById('stockValue').value=item.stock;

}
function closeStockModal(){ 
    document.getElementById('stockModal').style.display='none'; 
}
if(event.target==document.getElementById('stockModal')) closeStockModal();

function toggleMenu() { document.getElementById("menuContent").classList.toggle("show"); }
</script>
<script>
//Profile
/* Initialize modal fragment state & attach form handlers */
function initProfileModal() {
  const form = document.getElementById("usernameForm");
  const display = document.getElementById("usernameDisplay");
  const editIcon = document.getElementById("editIcon");

  if (form) form.classList.add("hidden");
  if (display) display.style.display = "";
  if (editIcon) editIcon.style.display = "inline-block";

  attachProfileFormHandlers();
}

/* Attach handlers to forms inside modal; submits are done via fetch to profile.php */
function attachProfileFormHandlers() {
  const content = document.getElementById("profileContent");
  if (!content) return;

  // For every form in the modal, remove previous listeners (clone to remove)
  content.querySelectorAll('form').forEach(form => {
    const cloned = form.cloneNode(true);
    form.parentNode.replaceChild(cloned, form);

    cloned.addEventListener('submit', async function(e) {
      e.preventDefault();
      const fd = new FormData(cloned);

      // disable submit button(s)
      cloned.querySelectorAll('button[type="submit"]').forEach(b => b.disabled = true);

      try {
        const res = await fetch('profile.php', {
          method: 'POST',
          body: fd,
          credentials: 'same-origin'
        });

        if (!res.ok) throw new Error('Network response not ok');

        const text = await res.text();

        // replace modal content
        content.innerHTML = text;

        // re-init (attach handlers for newly injected forms)
        initProfileModal();

        // Update header picture and username from the returned fragment
        const parser = new DOMParser();
        const doc = parser.parseFromString(text, 'text/html');

        const newImg = doc.querySelector('.profile-avatar');
        if (newImg) {
          const headerPic = document.getElementById('headerProfilePic');
          if (headerPic) {
            // Replace header image src and cache-bust
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
// Show/hide price fields based on category
function showPriceFields(categoryId, price, priceSolo, priceSharing) {
    const soloSharingPrices = document.getElementById('editSoloSharingPrices');
    const priceRow = document.getElementById('editPriceRow');
    if (parseInt(categoryId) === parseInt(friedRiceId) || parseInt(categoryId) === parseInt(noodlesId)) {
        soloSharingPrices.style.display = '';
        priceRow.style.display = 'none';
        document.getElementById('itemPriceSolo').value = priceSolo || "";
        document.getElementById('itemPriceSharing').value = priceSharing || "";
    } else {
        soloSharingPrices.style.display = 'none';
        priceRow.style.display = '';
        document.getElementById('itemPrice').value = price || "";
        document.getElementById('itemPriceSolo').value = "";
        document.getElementById('itemPriceSharing').value = "";
    }
}
</script>
</body>
</html>