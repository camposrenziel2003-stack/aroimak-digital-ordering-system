<?php
session_start();
if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}
include "config.php";
// --- Variables ---
$maxTable = 15;
$success = "";
$error = "";

// --- Fetch tablets ---
$tabletResult = $conn->query("SELECT * FROM tablets ORDER BY table_number ASC");

// --- Get used table numbers ---
$usedTables = [];
$res = $conn->query("SELECT id, table_number FROM tablets WHERE table_number IS NOT NULL");
while ($row = $res->fetch_assoc()) {
    $usedTables[$row['id']] = intval($row['table_number']);
}

// --- Handle Add Device POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    if ($action === 'add') {
        $table_number = intval($_POST['table_number']);
        $ip_address = trim($_POST['ip_address']);

        if ($table_number < 1 || $table_number > $maxTable) {
            $error = "Table number must be between 1 and $maxTable!";
        } elseif (empty($ip_address)) {
            $error = "Device IP address is required!";
        } elseif (in_array($table_number, $usedTables)) {
            $error = "This table number is already assigned!";
        } else {
            $check = $conn->prepare("SELECT id FROM tablets WHERE ip_address=?");
            $check->bind_param("s", $ip_address);
            $check->execute();
            $result = $check->get_result();
            if ($result->num_rows > 0) {
                $error = "A device with this IP address already exists!";
            } else {
                $stmt = $conn->prepare("INSERT INTO tablets (table_number, ip_address, last_seen) VALUES (?, ?, NOW())");
                $stmt->bind_param("is", $table_number, $ip_address);
                if ($stmt->execute()) {
                    $success = "Device successfully added!";
                    $usedTables[] = $table_number;
                    $tabletResult = $conn->query("SELECT * FROM tablets ORDER BY table_number ASC"); // refresh table
                } else {
                    $error = "Failed to add device!";
                }
            }
        }
    } elseif ($action === 'edit') {
        $tablet_id = intval($_POST['tablet_id']);
        $new_table_number = intval($_POST['table_number']);
        $new_ip_address = trim($_POST['ip_address']);

        if ($new_table_number < 1 || $new_table_number > $maxTable) {
            $error = "Table number must be between 1 and $maxTable!";
        } elseif (in_array($new_table_number, $usedTables) && $usedTables[$tablet_id] != $new_table_number) {
            $error = "This table number is already assigned!";
        } else {
            $update = $conn->prepare("UPDATE tablets SET table_number=?, ip_address=? WHERE id=?");
            $update->bind_param("isi", $new_table_number, $new_ip_address, $tablet_id);
            if ($update->execute()) {
                $success = "Tablet updated successfully!";
                $usedTables[$tablet_id] = $new_table_number;
                $tabletResult = $conn->query("SELECT * FROM tablets ORDER BY table_number ASC"); // refresh table
            } else {
                $error = "Failed to update tablet!";
            }
        }
    } elseif ($action === 'delete') {
        $tabletId = intval($_POST['tablet_id']);
        $stmt = $conn->prepare("DELETE FROM tablets WHERE id = ?");
        $stmt->bind_param("i", $tabletId);
        if ($stmt->execute()) {
            $success = "Tablet deleted successfully!";
            unset($usedTables[$tabletId]);
            $tabletResult = $conn->query("SELECT * FROM tablets ORDER BY table_number ASC"); // refresh table
        } else {
            $error = "Failed to delete tablet!";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Assign Table</title>
<link rel="stylesheet" href="index.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
/* Card & table */
.card { max-width:1170px; margin:20px auto; padding:2em; background:#fff; border-radius:10px; box-shadow:0 4px 14px rgba(0,0,0,0.08); }
.menu-table { width:100%; border-collapse:collapse; }
.menu-table th, .menu-table td { padding:10px; border-bottom:1px solid #ddd; text-align:left; }
.empty-message { text-align:center; color:#888; }

.assign-device-btn-main, .edit-btn-main, .delete-btn, .cancel-btn { padding:6px 18px; border:none; border-radius:4px; cursor:pointer; color:#fff; }
.assign-device-btn-main { background:#10b981; margin-bottom:14px; }
.edit-btn-main { background:#2563eb; }
.delete-btn { background:#ef4444; }
.assign-device-btn-main:disabled, .edit-btn-main:disabled, .delete-btn:disabled { background:#aaa; cursor:not-allowed; }

/* Modal */
.modal { display:none; position:fixed; z-index:2000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); justify-content:center; align-items:center; }
.modal-content { background:#fff; border-radius:10px; padding:2em; max-width:480px; position:relative; }
.modal-content .close { position:absolute; top:10px; right:14px; font-size:22px; cursor:pointer; color:#555; }
.modal-content label { display:block; margin-bottom:6px; font-weight:bold; }
.modal-content input { width:96%; padding:8px; margin-bottom:14px; border-radius:4px; border:1px solid #ccc; }
.modal-content select { width:100%; padding:8px; margin-bottom:14px; border-radius:4px; border:1px solid #ccc; }
.modal-content .btn-group { display:flex; gap:10px; }
.modal-content .btn-group button, .modal-content .btn-group a { flex:1; }
.disabled-option { color:#888; }


/* --- Standard Modal Buttons --- */
.btn-modal {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;                /* spacing between icon & text */
    padding: 9px 22px;       /* consistent vertical & horizontal padding */
    font-size: 14px;
    font-weight: 500;
    border-radius: 4px;
    border: none;
    cursor: pointer;
    text-align: center;
    text-decoration: none;
    color: #fff;
    transition: background 0.2s ease;
}

/* Specific Modal Buttons */
.btn-modal-add { background-color: #10b981; }
.btn-modal-add:hover { background-color: #059669; }

.btn-modal-save { background-color: #2563eb; }
.btn-modal-save:hover { background-color: #1e40af; }

.btn-modal-delete { background-color: #ef4444; }
.btn-modal-delete:hover { background-color: #dc2626; }

.btn-modal-cancel { background-color: #888; }
.btn-modal-cancel:hover { background-color: #666; }

/* Disabled state */
.btn-modal:disabled {
    background-color: #ccc;
    cursor: not-allowed;
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

            <a href="add_item.php" >
                <i class="fa-solid fa-plus-circle"></i> Add Item
            </a>
            <a href="promo.php">
                <i class="fa-solid fa-bullhorn"></i> Add Promo
            </a>
                <a href="feedback.php">
            <i class="fa-solid fa-star"></i> Customer Feedback
        </a>
            <!-- Assign Table - Added under Promo -->
            <a href="assign_table.php"  class="active">
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
      <h1>Table Registration</h1>
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
<div id="profileOverlay" class="profile-overlay" onclick="closeProfile(event)">
  <div class="profile-card" onclick="event.stopPropagation()">
    <button class="close-btn" onclick="closeProfile()">✖</button>
    <div id="profileContent">
      <p style="text-align:center;">Loading...</p>
    </div>
  </div>
</div>

<div class="container">
  <div class="card">
    <h2>Registered Table Devices</h2>
    <?php if ($success): ?><p style="color:green;"><?= $success ?></p><?php endif; ?>
    <?php if ($error): ?><p style="color:red;"><?= $error ?></p><?php endif; ?>

    <button id="openDeviceModal" class="assign-device-btn-main">
        <i class="fa-solid fa-plus"></i> Add Device
    </button>

    <table class="menu-table">
        <tr>
            <th>Table Number</th>
            <th>Device IP Address</th>
            <th>Last Seen</th>
            <th>Actions</th>
        </tr>
        <?php if ($tabletResult->num_rows > 0): ?>
            <?php while ($tablet = $tabletResult->fetch_assoc()): ?>
                <tr>
                    <td><?= $tablet['table_number'] ?></td>
                    <td><?= htmlspecialchars($tablet['ip_address']) ?></td>
                     <td><?= !empty($tablet['last_seen']) ? date("F j, Y",strtotime($tablet['last_seen'])) : '<span style="color:#888;">Never</span>' ?></td>
                       <td>
    <div style="display: flex; gap: 8px;">
        <button class="edit-btn-main" onclick="openEditModal(<?= $tablet['id'] ?>, <?= $tablet['table_number'] ?>, '<?= htmlspecialchars($tablet['ip_address']) ?>')">
            <i class="fa fa-edit"></i> Edit
        </button>
        <button type="button" class="delete-btn" onclick="openDeleteModal(<?= $tablet['id'] ?>)">
            <i class="fa-solid fa-trash"></i> Delete
        </button>
    </div>
</td>
                </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr><td colspan="4" class="empty-message">No tablet devices registered.</td></tr>
        <?php endif; ?>
    </table>
</div>
</div>

<!-- Add/Edit Modal -->
<div id="deviceModal" class="modal">
    <div class="modal-content">
        <span class="close">&times;</span>
        <h2 id="modalTitle"><i class="fa-solid fa-tablet-screen-button"></i> Add Device</h2>
        <form id="deviceForm" method="post">
            <input type="hidden" name="tablet_id" id="edit_tablet_id">
            <input type="hidden" name="action" id="form_action" value="add">
            
            <label for="table_number">Table Number</label>
            <select name="table_number" id="edit_table_number" required></select>
            
            <label for="ip_address">Device IP Address</label>
            <input type="text" name="ip_address" id="edit_ip_address" required>
            
            <div class="btn-group">
                <button type="submit" class="btn-modal btn-modal-add" id="modalSubmitBtn">
                    <i class="fa-solid fa-plus"></i> Add Device
                </button>
                <a href="#" class="btn-modal btn-modal-cancel" onclick="closeModal();return false;">Cancel</a>
            </div>
        </form>
    </div>
</div>

<!-- Delete Modal -->
<div id="deleteModal" class="modal">
    <div class="modal-content">
        <span class="close" id="deleteClose">&times;</span>
        <h2>Delete Tablet Access</h2>
        <p>Are you sure you want to delete this tablet?</p>
        <form method="post">
            <input type="hidden" name="tablet_id" id="delete_tablet_id">
            <input type="hidden" name="action" value="delete">
            
            <div class="btn-group">
                <button type="submit" class="btn-modal btn-modal-delete">
                    <i class="fa-solid fa-trash"></i> Delete
                </button>
                <a href="#" class="btn-modal btn-modal-cancel" onclick="closeDeleteModal();return false;">Cancel</a>
            </div>
        </form>
    </div>
</div>


<script>
// --- Modal Controls ---
const deviceModal = document.getElementById("deviceModal");
const openDeviceModalBtn = document.getElementById("openDeviceModal");
const closeBtn = deviceModal.querySelector(".close");
const tableSelect = document.getElementById("edit_table_number");
const ipInput = document.getElementById("edit_ip_address");
const modalTitle = document.getElementById("modalTitle");
const modalSubmitBtn = document.getElementById("modalSubmitBtn");
const formActionInput = document.getElementById("form_action");
const editTabletIdInput = document.getElementById("edit_tablet_id");
const usedTables = <?= json_encode(array_values($usedTables)) ?>;

openDeviceModalBtn.addEventListener("click", () => openAddModal());
closeBtn.addEventListener("click", () => closeModal());
window.addEventListener("click", e => { if (e.target === deviceModal) closeModal(); });

function closeModal(){
    deviceModal.style.display="none";
    tableSelect.innerHTML = "";
    ipInput.value = "";
}

function openAddModal(){
    modalTitle.textContent = "Add Device";
    modalSubmitBtn.innerHTML = '<i class="fa-solid fa-plus"></i> Add Device';
    formActionInput.value = "add";
    editTabletIdInput.value = "";
    populateTableOptions();
    deviceModal.style.display = "flex";
}

function openEditModal(id, table, ip){
    modalTitle.textContent = "Edit Device";
    modalSubmitBtn.innerHTML = '<i class="fa-solid fa-save"></i> Save';
    formActionInput.value = "edit";
    editTabletIdInput.value = id;
    ipInput.value = ip;
    populateTableOptions(table);
    deviceModal.style.display = "flex";
}

function populateTableOptions(selected=0){
    tableSelect.innerHTML = "";
    for(let i=1;i<=<?= $maxTable ?>;i++){
        const opt = document.createElement("option");
        opt.value = i;
        opt.text = i;
        if(i==selected) opt.selected = true;
        if(usedTables.includes(i) && i!=selected){
            opt.disabled = true;
            opt.className="disabled-option";
        }
        tableSelect.appendChild(opt);
    }
}

// --- Delete Modal Controls ---
const deleteModal = document.getElementById("deleteModal");
const deleteClose = document.getElementById("deleteClose");
const deleteTabletIdInput = document.getElementById("delete_tablet_id");
deleteClose.addEventListener("click", () => closeDeleteModal());
window.addEventListener("click", e => { if (e.target === deleteModal) closeDeleteModal(); });
function openDeleteModal(tabletId){
    deleteTabletIdInput.value = tabletId;
    deleteModal.style.display = "flex";
}
function closeDeleteModal(){
    deleteTabletIdInput.value = "";
    deleteModal.style.display = "none";
}
</script>

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
</body>
</html>



