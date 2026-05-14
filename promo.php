<?php
session_start();
if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}
include "config.php";
// Handle image upload
$uploadError = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['promo_images'])) {
    $uploadedImages = $_FILES['promo_images'];
    $imageCount = count(array_filter($uploadedImages['name']));

    $existingCountResult = $conn->query("SELECT COUNT(*) as cnt FROM promo_images");
    $existingRow = $existingCountResult->fetch_assoc();
    $existingCount = (int)$existingRow['cnt'];

    $maxTotal = 5;

    if ($existingCount >= $maxTotal) {
        $uploadError = "You already have the maximum of $maxTotal promo images. Delete one before uploading new ones.";
    } elseif ($imageCount < 1) {
        $uploadError = "Please select at least 1 image.";
    } elseif ($existingCount + $imageCount > $maxTotal) {
        $uploadError = "You can only upload ".($maxTotal - $existingCount)." more images.";
    } else {
        $promoDir = "uploads/promo/";
        if (!is_dir($promoDir)) mkdir($promoDir, 0777, true);

        for ($i = 0; $i < $imageCount; $i++) {
            if ($uploadedImages['error'][$i] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($uploadedImages['name'][$i], PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) continue;

                $newName = uniqid("promo_", true) . "." . $ext;
                move_uploaded_file($uploadedImages['tmp_name'][$i], $promoDir . $newName);

                $stmt = $conn->prepare("INSERT INTO promo_images (file_name) VALUES (?)");
                $stmt->bind_param("s", $newName);
                $stmt->execute();
            }
        }
    }
}

// Handle deletion
if (isset($_GET['delete'])) {
    $fileToDelete = $_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM promo_images WHERE file_name = ?");
    $stmt->bind_param("s", $fileToDelete);
    if ($stmt->execute()) {
        @unlink("uploads/promo/" . $fileToDelete);
    }
    header("Location: promo.php");
    exit;
}

// Load promo images
$promoImages = [];
$result = $conn->query("SELECT * FROM promo_images ORDER BY uploaded_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $promoImages[] = $row['file_name'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Promo Management</title>
<link rel="stylesheet" href="index.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
/* Container */
.container { margin-left: 230px; padding: 20px; }
.promo-card { background: #fff; border-radius: 8px; box-shadow: 0 2px 8px #0001; padding: 28px 32px; margin: 20px auto; max-width: 1200px; }
.promo-title { font-size: 2rem; font-weight: bold; color: #ff6600; margin-bottom: 12px; }
.promo-form label { font-weight: 500; color: #333; margin-bottom: 4px; display: block; }
.promo-form input[type="file"] { margin-bottom: 16px; }
.promo-form .note { color: #888; font-size: 0.96em; margin-bottom: 18px; }
.promo-form button { background: #28a745; color: #fff; border: none; padding: 10px 26px; font-size: 1.1rem; border-radius: 6px; margin-top: 10px; cursor: pointer; transition: background 0.2s; }
.promo-form button:hover { background: #218838; }
.promo-error { color: #d9534f; font-weight: 500; margin-bottom: 14px; }
.promo-preview { margin-top: 28px; background: #f9f9fa; border-radius: 8px; padding: 18px; box-shadow: 0 2px 8px #0001; text-align: center; }

/* Carousel */
.carousel-container { position: relative; width: 100%; max-width: 1200px; margin: 0 auto; overflow: hidden; }
.carousel-images { display: flex; transition: transform 0.4s cubic-bezier(.6,0,.4,1); width: 100%; }
.carousel-item { position: relative; flex-shrink: 0; width: 100%; }
.carousel-images img { width: 100%; height: 400px; object-fit: cover; border-radius: 8px; }
.delete-btn { position: absolute; bottom: 10px; right: 10px; background: rgba(255,0,0,0.8); color:#fff; border:none; padding:6px 10px; border-radius:4px; cursor:pointer; font-size:0.9rem; }
.delete-btn:hover { background: rgba(255,0,0,1); }
.carousel-controls { margin-top: 10px; text-align: center; }
.carousel-btn { background: #ff6600; color: #fff; border: none; border-radius: 50%; width: 34px; height: 34px; font-size: 1.4rem; margin: 0 8px; cursor: pointer; transition: background 0.2s; }
.carousel-btn:disabled { background: #ffe0b2; color: #ff6600; cursor: not-allowed; }
.carousel-indicator { margin-top: 8px; font-size: 1.05em; color: #666; }

/* Delete Confirmation Modal */
#deleteModal {
    display: none; /* Initially hidden */
    position: fixed;
    top: 0; left: 0;
    width: 100%; height: 100%;
    background: rgba(0,0,0,0.6);
    justify-content: center;
    align-items: center;
    z-index: 10000;
}

#deleteModal .modal-content {
    background: #fff;
    border-radius: 12px;
    padding: 30px 25px;
    max-width: 400px;
    width: 100%;
    text-align: left;
    box-shadow: 0 8px 24px rgba(0,0,0,0.2);
    position: relative;
}

#deleteModal .modal-content h2 {
    margin: 0 0 15px;
    font-size: 1.2rem;
    color: #333;
}

#deleteModal .close-modal {
    position: absolute;
    top: 12px;
    right: 12px;
    font-size: 1.2rem;
    background: transparent;
    border: none;
    cursor: pointer;
    color: #666;
}

#deleteModal .modal-buttons {
    display: flex;
    justify-content: center;
    gap: 15px;
}

#deleteModal .btn {
    padding: 10px 30px;
    border-radius: 6px;
    width: 100%;
    font-weight: 500;
    font-size: 0.95rem;
    border: none;
    cursor: pointer;
    transition: 0.2s;
}

#deleteModal .btn-delete { background-color: #ff4d4f; color: #fff; }
#deleteModal .btn-delete:hover { background-color: #d9363e; }
#deleteModal .btn-cancel { background-color: #6c757d; color: #fff; }
#deleteModal .btn-cancel:hover { background-color: #5a6268; }

/* Scrollbar */
* { scrollbar-width: thin; scrollbar-color: #f5cea2ff #f0f0f0; }

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
            <a href="popular_dishes.php" class="popular-link<?php if(basename($_SERVER['PHP_SELF'])=='popular_dishes.php') echo ' active'; ?>"><i class="fa-solid fa-fire"></i> Popular Dishes</a>
            <a href="menu.php"><i class="fa-solid fa-utensils"></i> Menu Availability</a>
            <a href="add_item.php"><i class="fa-solid fa-plus-circle"></i> Add Item</a>
            <a href="promo.php"  class="active"><i class="fa-solid fa-bullhorn"></i> Add Promo</a>
            <a href="feedback.php"><i class="fa-solid fa-star"></i> Customer Feedback</a>
            <a href="assign_table.php"><i class="fa-solid fa-tablet-screen-button"></i> Assign Table</a>
            <a href="assign_roles.php"><i class="fa-solid fa-user-gear"></i> Assign Roles</a>
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
        <h1>Promo Management</h1>
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
    <div class="promo-card">
        <div class="promo-title">Promo Images</div>
        <form method="post" enctype="multipart/form-data" class="promo-form">
            <label for="promo_images">Upload 3-5 Promo Images:</label>
            <input type="file" name="promo_images[]" id="promo_images" accept="image/*" multiple required>
            <div class="note">Accepted formats: JPG, PNG, GIF, WEBP. Minimum 3 images, maximum 5 images.</div>
            <?php if ($uploadError): ?><div class="promo-error"><?= htmlspecialchars($uploadError) ?></div><?php endif; ?>
            <button type="submit"><i class="fa fa-upload"></i> Upload</button>
        </form>

        <div class="promo-preview">
            <h3>Image Preview</h3>
            <?php if (count($promoImages) > 0): ?>
                <div class="carousel-container">
                    <div class="carousel-images" id="carouselImages">
                        <?php foreach ($promoImages as $img): ?>
                        <div class="carousel-item">
                            <img src="uploads/promo/<?= htmlspecialchars($img) ?>" alt="Promo Image">
                            <button class="delete-btn" data-id="<?= htmlspecialchars($img) ?>">Delete</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="carousel-controls">
                        <button class="carousel-btn" id="prevBtn">&lt;</button>
                        <button class="carousel-btn" id="nextBtn">&gt;</button>
                    </div>
                    <div class="carousel-indicator" id="carouselIndicator"></div>
                </div>
            <?php else: ?>
                <p style="color:#aaa;">No promo images uploaded yet.</p>
            <?php endif; ?>
        </div>
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
<!-- Delete Modal -->
<div id="deleteModal">
    <div class="modal-content">
        <button class="close-modal">&times;</button>
        <h2>Delete Carousel Image</h2>
        <p>Are you sure you want to delete this image?</p>
        <div class="modal-buttons">
            <button id="confirmDelete" class="btn btn-delete">
                <i class="fa-solid fa-trash"></i> Delete
            </button>
            <button id="cancelDelete" class="btn btn-cancel">Cancel</button>
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
// Carousel
document.addEventListener("DOMContentLoaded", function() {
    const images = document.querySelectorAll("#carouselImages img");
    const carousel = document.getElementById("carouselImages");
    const prevBtn = document.getElementById("prevBtn");
    const nextBtn = document.getElementById("nextBtn");
    const indicator = document.getElementById("carouselIndicator");
    let currentIdx = 0;

    function updateCarousel() {
        if (!carousel || images.length === 0) return;
        const width = images[0].clientWidth;
        carousel.style.transform = `translateX(-${currentIdx * width}px)`;
        indicator.textContent = `Image ${currentIdx+1} of ${images.length}`;
        prevBtn.disabled = currentIdx === 0;
        nextBtn.disabled = currentIdx === images.length - 1;
    }
    if (prevBtn && nextBtn && images.length > 0) {
        prevBtn.onclick = () => { if (currentIdx > 0) currentIdx--; updateCarousel(); };
        nextBtn.onclick = () => { if (currentIdx < images.length - 1) currentIdx++; updateCarousel(); };
        window.addEventListener("resize", updateCarousel);
        updateCarousel();
    }

    // Delete Modal
    const deleteModal = document.getElementById('deleteModal');
    let currentDeleteId = null;

    document.querySelectorAll('.delete-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            currentDeleteId = btn.dataset.id;
            deleteModal.style.display = 'flex';
        });
    });

    document.getElementById('confirmDelete').onclick = () => {
        if(currentDeleteId) window.location.href = '?delete=' + encodeURIComponent(currentDeleteId);
    };

    document.getElementById('cancelDelete').onclick = () => { deleteModal.style.display = 'none'; currentDeleteId = null; };

    // Close modal when clicking overlay or X
    deleteModal.querySelector('.close-modal').onclick = () => { deleteModal.style.display = 'none'; currentDeleteId = null; };
    window.onclick = (event) => { if (event.target == deleteModal) { deleteModal.style.display = 'none'; currentDeleteId = null; } };
});
</script>
</body>
</html>
