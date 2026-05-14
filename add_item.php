<?php
session_start();
if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}
include "config.php";

// List of preferences
$all_prefs = ['Spicy', 'Noodles', 'Soup', 'Salad', 'Seafood', 'Drinks', 'Veggie', 'Curry', 'Pork', 'Sweet', 'Chicken', 'Rice', 'Beef'];

// Fetch categories and build ID map for JS
$categoriesArr = [];
$categoriesRes = $conn->query("SELECT * FROM categories ORDER BY name ASC");
$friedRiceId = $noodlesId = null;
while ($cat = $categoriesRes->fetch_assoc()) {
    $categoriesArr[] = $cat;
    if ($cat['name'] === "Fried Rice") $friedRiceId = $cat['id'];
    if ($cat['name'] === "Noodles") $noodlesId = $cat['id'];
}

// Flash messages
$message = "";
$success = "";

if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name        = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;
    $preferences = isset($_POST['preferences']) ? $_POST['preferences'] : [];

    // Prices
    $price       = isset($_POST['price']) ? floatval($_POST['price']) : 0;
    $price_solo  = isset($_POST['price_solo']) ? floatval($_POST['price_solo']) : null;
    $price_sharing = isset($_POST['price_sharing']) ? floatval($_POST['price_sharing']) : null;

    // Portion size
    $portion_size = trim($_POST['portion_size'] ?? '');

    // Only save portion_size if category is Fried Rice or Noodles:
    if (!($category_id == $friedRiceId || $category_id == $noodlesId)) {
        $portion_size = null;
        $price_solo = null;
        $price_sharing = null;
    } else {
        if ($portion_size === "") $portion_size = null;
    }

    // Basic validation
    if ($name === '' || $description === '' || $category_id <= 0 ||
        ($category_id == $friedRiceId || $category_id == $noodlesId
            ? ($price_solo <= 0 || $price_sharing <= 0)
            : $price <= 0)) {
        $message = "⚠ Please fill in all fields correctly.";
    } else {
        // Image handling
        $image = "";
        if (isset($_FILES['image']) && is_array($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $allowedExt  = ['jpg','jpeg','png','gif'];
                $maxSize     = 2 * 1024 * 1024; // 2MB
                $origName    = $_FILES['image']['name'];
                $tmpPath     = $_FILES['image']['tmp_name'];
                $size        = $_FILES['image']['size'];
                $ext         = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

                // Extra MIME check
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $tmpPath);
                finfo_close($finfo);
                $allowedMime = ['image/jpeg','image/png','image/gif'];

                if (!in_array($ext, $allowedExt) || !in_array($mime, $allowedMime)) {
                    $message = "⚠ Invalid image type. Allowed: JPG, PNG, GIF.";
                } elseif ($size > $maxSize) {
                    $message = "⚠ Image size must be under 2MB.";
                } else {
                    $targetDir = "uploads/";
                    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
                    $image = time() . "_" . bin2hex(random_bytes(4)) . "." . $ext;
                    $targetFile = $targetDir . $image;
                    if (!move_uploaded_file($tmpPath, $targetFile)) $message = "⚠ Failed to upload image.";
                }
            } else $message = "⚠ Image upload error. Please try again.";
        }

       
        // Insert into DB
        if ($message === "") {
            $prefs_str = implode(",", $preferences);

            // Prepare all variables for binding
            if ($category_id == $friedRiceId || $category_id == $noodlesId) {
                // Fried Rice/Noodles: use solo/sharing/portion_size, set price to null
                $bind_price = null;
                $bind_price_solo = $price_solo;
                $bind_price_sharing = $price_sharing;
                $bind_portion_size = $portion_size;
            } else {
                // Other categories: use price only, set solo/sharing/portion_size to null
                $bind_price = $price;
                $bind_price_solo = null;
                $bind_price_sharing = null;
                $bind_portion_size = null;
            }

            $stmt = $conn->prepare("INSERT INTO menu_items (name, description, price, price_solo, price_sharing, category, image, preferences, pronunciation_file, portion_size) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param(
                "ssddisssss",
                $name,
                $description,
                $bind_price,
                $bind_price_solo,
                $bind_price_sharing,
                $category_id,
                $image,
                $prefs_str,
                $pronunciationFile,
                $bind_portion_size
            );

            if ($stmt->execute()) {
                $_SESSION['success'] = "✅ Item added successfully!";
                header("Location: add_item.php");
                exit;
            } else $message = "⚠ Database error: " . $stmt->error;
            $stmt->close();
        }
    }
}

// Fetch categories for form
$categories = $conn->query("SELECT * FROM categories ORDER BY name ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Add Menu Item</title>
<link rel="stylesheet" href="index.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
/* Alerts */
.alert { 
  padding: 12px; 
  margin-bottom: 15px; 
  border-radius: 8px; 
  font-size: 14px; 
}
.alert.success { 
  background: #e0f7e9; 
  color: #2e7d32; 
  border: 1px solid #b2dfbd; 
}
.alert.error { 
  background: #fde0e0; 
  color: #c62828; 
  border: 1px solid #f5b5b5; 
}

/* Container & Card */
.container { 
  display: flex; 
  justify-content: center; 
  padding: 20px; 
  max-width: 1000px;
}
.card { 
  background: #fff; 
  border-radius: 12px; 
  padding: 20px; 
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1); 
}

/* Form */
form label { 
  font-weight: 600; 
  display: block; 
  margin-bottom: 8px; 
  color: #333; 
}
form input, 
form textarea, 
form select { 
  width: 100%; 
  padding: 10px 12px; 
  border: 1px solid #ccc; 
  border-radius: 10px; 
  font-size: 0.95rem; 
  margin-bottom: 15px; 
  box-sizing: border-box;
}
form textarea { 
  resize: vertical; 
  min-height: 80px; 
}

/* Form actions */
.form-actions { 
  display: flex; 
  flex-wrap: wrap; 
  gap: 10px; 
  margin-top: 10px; 
}
.btn-primary, .btn-secondary { 
  padding: 10px 16px; 
  border-radius: 15px; 
  font-weight: 600; 
  cursor: pointer; 
  text-align: center;
}
.btn-primary { 
  background-color: #ff6600; 
  color: #fff; 
  border: none; 
  flex: 1; 
}
.btn-primary:hover { 
  background-color: #e65c00; 
}
.btn-secondary { 
  background: #6c757d; 
  color: #fff; 
  text-decoration: none; 
  display: inline-block; 
  flex: 1; 
}
.btn-secondary:hover { 
  background: #565e64; 
}

/* Preferences */
.preferences-container { 
  display: flex; 
  flex-wrap: wrap; 
  gap: 8px; 
  margin-bottom: 15px; 
}
.pref-tag { 
  display: inline-flex; 
  align-items: center; 
  background:#ffe0b2; 
  color:#ff6600; 
  border-radius:15px; 
  padding:4px 10px; 
  font-size:0.9rem; 
  cursor:pointer; 
  transition:0.2s; 
}
.pref-tag input { 
  margin-right:6px; 
}
.pref-tag:hover { 
  background:#ffc07f; 
}

/* Image preview */
.preview { 
  margin-top: 6px; 
  max-width: 50%; 
  height: auto; 
  border: 1px solid #eee; 
  border-radius: 8px; 
  display: none; 
}

/* Responsive tweaks */
@media (max-width: 768px) {
  .card { 
    padding: 16px; 
  }
  .form-actions { 
    flex-direction: column; 
  }
  .btn-primary, .btn-secondary { 
    width: 100%; 
  }
  .header h1 { 
    font-size: 1.25rem; 
  }
}
@media (max-width: 480px) {
  body { 
    font-size: 14px; 
  }
  form input, form textarea, form select { 
    font-size: 0.9rem; 
  }
}
/* Alerts, card, form, preferences, previews (same as your existing CSS) */
.preview { margin-top:6px; max-width:50%; height:auto; border:1px solid #eee; border-radius:8px; display:none; }
audio { display:block; margin-top:6px; }

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

<!-- Header -->
<header class="header">
    <div class="header-left">
      <h1>Add Item</h1>
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
            <a href="menu.php">
                <i class="fa-solid fa-utensils"></i> Menu Availability
            </a>

            <a href="add_item.php"  class="active">
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

<div class="container">
  <div class="card" style="max-width:680px;">
    <h2>Add New Menu</h2>
    <?php if ($success) echo "<div class='alert success'>$success</div>"; ?>
    <?php if ($message) echo "<div class='alert error'>$message</div>"; ?>

    <form method="post" enctype="multipart/form-data">
      <label for="name">Name:</label>
      <input id="name" type="text" name="name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>

      <label for="description">Description:</label>
      <textarea id="description" name="description" required><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>

      <!-- Standard price (hidden for Fried Rice/Noodles) -->
      <div id="priceRow">
        <label for="price">Price (₱):</label>
        <input id="price" type="number" step="0.01" name="price" value="<?= htmlspecialchars($_POST['price'] ?? '') ?>">
      </div>

      <!-- Solo/Sharing prices (shown only for Fried Rice/Noodles) -->
      <div id="soloSharingPrices" style="display:none;">
        <label for="price_solo">Price for Solo (₱):</label>
        <input type="number" step="0.01" name="price_solo" id="price_solo" value="<?= htmlspecialchars($_POST['price_solo'] ?? '') ?>">
        <label for="price_sharing">Price for Sharing (₱):</label>
        <input type="number" step="0.01" name="price_sharing" id="price_sharing" value="<?= htmlspecialchars($_POST['price_sharing'] ?? '') ?>">
      </div>

      <label for="category_id">Category:</label>
      <select id="category_id" name="category_id" required>
        <option value="">-- Select Category --</option>
        <?php foreach ($categoriesArr as $cat): ?>
          <option value="<?= $cat['id'] ?>" <?= (isset($_POST['category_id']) && (int)$_POST['category_id'] === (int)$cat['id']) ? 'selected' : '' ?>>
            <?= htmlspecialchars($cat['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <label>Preferences:</label>
      <div class="preferences-container" style="align-items:center;gap:12px;">
        <?php foreach ($all_prefs as $pref): ?>
          <label class="pref-tag">
            <input type="checkbox" name="preferences[]" value="<?= $pref ?>" <?= (isset($_POST['preferences']) && in_array($pref, $_POST['preferences'])) ? 'checked' : '' ?>> <?= $pref ?>
          </label>
        <?php endforeach; ?>
      </div>

      <!-- Image -->
      <label for="imageInput">Image:</label>
      <input type="file" name="image" id="imageInput" accept="image/*">
      <img id="previewImg" class="preview" alt="Preview">


      <div class="form-actions">
        <button type="submit" class="btn-primary">➕ Add Item</button>
        <a href="index.php" class="btn-secondary">⬅ Back to Dashboard</a>
      </div>
    </form>
  </div>
</div>


<script>
const imageInput = document.getElementById('imageInput');
const previewImg = document.getElementById('previewImg');
imageInput.addEventListener('change', e => {
    const [file] = e.target.files || [];
    if(file) { previewImg.src = URL.createObjectURL(file); previewImg.style.display='block'; }
    else { previewImg.src=''; previewImg.style.display='none'; }
});
const pronunciationInput = document.getElementById('pronunciation_file');
const audioPreview = document.getElementById('audioPreview');
pronunciationInput.addEventListener('change', e => {
    const [file] = e.target.files || [];
    if(file) { audioPreview.src = URL.createObjectURL(file); audioPreview.style.display='block'; }
    else { audioPreview.src=''; audioPreview.style.display='none'; }
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
        const res = await fetch('profile.php', {
          method: 'POST',
          body: fd,
          credentials: 'same-origin'
        });

        if (!res.ok) throw new Error('Network response not ok');

        const text = await res.text();

        content.innerHTML = text;

        initProfileModal();

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
const friedRiceId = <?= $friedRiceId ?: 'null' ?>;
const noodlesId = <?= $noodlesId ?: 'null' ?>;
const categorySelect = document.getElementById('category_id');
const soloSharingPrices = document.getElementById('soloSharingPrices');
const priceRow = document.getElementById('priceRow');
const portionSizeRow = document.getElementById('portionSizeRow');

function updatePortionFields() {
  const val = parseInt(categorySelect.value);
  if (val === friedRiceId || val === noodlesId) {
    soloSharingPrices.style.display = "";
    priceRow.style.display = "none";
    portionSizeRow.style.display = "";
  } else {
    soloSharingPrices.style.display = "none";
    priceRow.style.display = "";
    portionSizeRow.style.display = "none";
    document.getElementById('portion_size').value = "";
  }
}
categorySelect.addEventListener('change', updatePortionFields);
updatePortionFields();

function updatePortionSizeVisibility() {
  const val = parseInt(categorySelect.value);
  if (val === friedRiceId || val === noodlesId) {
    portionSizeRow.style.display = "";
  } else {
    portionSizeRow.style.display = "none";
    document.getElementById('portion_size').value = "";
  }
}
categorySelect.addEventListener('change', updatePortionSizeVisibility);
updatePortionSizeVisibility();

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
function toggleMenu() {
  const menu = document.getElementById("menuContent");
  if (menu.style.display === "block") {
    menu.style.display = "none";
  } else {
    menu.style.display = "block";
  }
}
document.addEventListener("click", function(event) {
  const profileMenu = document.querySelector(".profile-menu");
  const menuContent = document.getElementById("menuContent");

  if (!profileMenu.contains(event.target)) {
    menuContent.style.display = "none";
  }
});
</script>

</body>
</html>