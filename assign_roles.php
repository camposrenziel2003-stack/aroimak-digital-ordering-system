<?php
session_start();
include "config.php";
// ✅ Only admin can access
if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    header("Location: login.php");
    exit;
}

// ✅ CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ✅ Helpers
function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function secure_hash($p) {
    return defined('PASSWORD_ARGON2ID') ? password_hash($p, PASSWORD_ARGON2ID) : password_hash($p, PASSWORD_DEFAULT);
}

// 🔑 Encryption settings
define("SECRET_KEY", "Your32CharEncryptionKeyGoesHere1234");
define("SECRET_IV", "1234567890123456");

function encryptPassword($plain) {
    return base64_encode(openssl_encrypt($plain, "AES-256-CBC", SECRET_KEY, 0, SECRET_IV));
}

$errors = [];
$success = "";

// ✅ Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $errors[] = "⚠️ Security check failed (invalid CSRF token). Please try again.";
    } else {
        $action = $_POST['action'] ?? '';

        // ➕ Create staff
        if ($action === 'create') {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $role     = $_POST['role'] ?? '';

            if ($username === '' || strlen($username) < 3) $errors[] = "Username must be at least 3 characters long.";
            if (!preg_match('/^\d{6}$/', $password)) $errors[] = "Password must be exactly 6 digits.";
            if (!in_array($role, ['cashier','kitchen'])) $errors[] = "Please choose a valid role.";

            // Unique username
            if (empty($errors)) {
                $stmt = $conn->prepare("SELECT id FROM staff_accounts WHERE username=?");
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $stmt->store_result();
                if ($stmt->num_rows > 0) $errors[] = "🚫 Username <b>$username</b> is already taken.";
                $stmt->close();
            }

            // Insert if no errors
            if (empty($errors)) {
                $pw_hash = secure_hash($password);
                $enc_pw  = encryptPassword($password);
                $created_by = $_SESSION['admin_id'] ?? 0;

                $stmt = $conn->prepare("INSERT INTO staff_accounts (username,password_hash,passkey_enc,role,created_by,created_at) VALUES (?,?,?,?,?,NOW())");
                $stmt->bind_param("ssssi", $username, $pw_hash, $enc_pw, $role, $created_by);
                if ($stmt->execute()) {
                    $success = "✅ Staff account for <b>$username</b> was successfully created.";
                } else {
                    $errors[] = "❌ Failed to create staff account. Please try again.";
                }
                $stmt->close();
            }
        }

        // ✏️ Edit password
        elseif ($action === 'edit') {
            $id = intval($_POST['id'] ?? 0);
            $new_pw = $_POST['new_password'] ?? '';
            $admin_pass = $_POST['admin_pass'] ?? '';

            if (!preg_match('/^\d{6}$/', $new_pw)) {
                $errors[] = "Password must be exactly 6 digits.";
            } else {
                // Check admin password
                $stmt = $conn->prepare("SELECT password FROM admins WHERE id=? LIMIT 1");
                $stmt->bind_param("i", $_SESSION['admin_id']);
                $stmt->execute();
                $stmt->bind_result($admin_hash);
                if ($stmt->fetch() && password_verify($admin_pass, $admin_hash)) {
                    $stmt->close();

                    // Update staff password
                    $pw_hash = secure_hash($new_pw);
                    $enc_pw  = encryptPassword($new_pw);

                    $stmt = $conn->prepare("UPDATE staff_accounts SET password_hash=?, passkey_enc=? WHERE id=?");
                    $stmt->bind_param("ssi", $pw_hash, $enc_pw, $id);
                    if ($stmt->execute()) {
                        $success = "✏️ Password for staff ID <b>$id</b> updated successfully.";
                    } else {
                        $errors[] = "❌ Failed to update password.";
                    }
                    $stmt->close();
                } else {
                    $errors[] = "❌ Invalid admin password.";
                    $stmt->close();
                }
            }
        }

// 🗑️ Delete account (with admin password check)
elseif ($action === 'delete') {
    $id = intval($_POST['id'] ?? 0);
    $admin_pass = $_POST['admin_pass'] ?? '';

    // Check admin password
    $stmt = $conn->prepare("SELECT password FROM admins WHERE id=? LIMIT 1");
    $stmt->bind_param("i", $_SESSION['admin_id']);
    $stmt->execute();
    $stmt->bind_result($admin_hash);
    if ($stmt->fetch() && password_verify($admin_pass, $admin_hash)) {
        $stmt->close();

        // Proceed with delete
        $stmt = $conn->prepare("DELETE FROM staff_accounts WHERE id=?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $success = "🗑️ Staff account ID <b>$id</b> was deleted.";
        } else {
            $errors[] = "❌ Failed to delete staff account.";
        }
        $stmt->close();
    } else {
        $errors[] = "❌ Invalid admin password. Deletion not allowed.";
        $stmt->close();
    }
}
    }
}

// ✅ Fetch accounts
$accounts = [];
$res = $conn->query("SELECT id,username,role,created_at,last_login FROM staff_accounts ORDER BY role,username");
if ($res) while ($r = $res->fetch_assoc()) $accounts[] = $r;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<link rel="stylesheet" href="index.css">
<title>Staff Accounts</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
body-role { font-family:'Inter', sans-serif; background:#eef2f7; margin:0; color:#333; }
.container-role { margin-left:230px; margin-top:100px; padding:30px; max-width:1200px; }
.card-role { background:#fff; border-radius:15px; padding:30px; margin-bottom:30px; box-shadow:0 8px 20px rgba(0,0,0,0.08); }
h2 { margin-top:0; color:#222; }

.input,
select {
  width: 100%;
  padding: 12px 14px;   /* more comfortable vertical + horizontal padding */
  margin-top: 8px;
  margin-bottom: 18px;
  border: 1px solid #ccc;
  border-radius: 8px;
  font-size: 15px;
  box-sizing: border-box;
  transition: border-color 0.2s, box-shadow 0.2s;
}

.input:focus,
select:focus {
  border-color: #ff6600;
  outline: none;
  box-shadow: 0 0 0 2px rgba(255, 102, 0, 0.2);
}
.btnr { padding:8px 16px; border:none; border-radius:6px; cursor:pointer; font-size:14px; }
.btnr-primary { background:#ff6600; color:#fff; }
.btnr-primary:hover { background:#125aa0; }
.btnr-secondary { background:#555; color:#fff; }
.btnr-secondary:hover { background:#333; }
.btnr-danger { background:#d32f2f; color:#fff; }
.btnr-danger:hover { background:#a52828; }

.table-role { width:100%; border-collapse:collapse; background:#fff; border-radius:8px; overflow:hidden; }
.table-role th, .table-role td { padding:12px 16px; border-bottom:1px solid #f0f0f0; text-align:left; }
.table-role th { background:#fafafa; font-weight:600; }

.role-tag { padding:5px 10px; border-radius:8px; color:#fff; font-size:13px; }
.role-cashier { background:#1976d2; }
.role-kitchen { background:#6a1b9a; }

.success-box, .error-box {
  padding:12px 16px; margin-bottom:20px; border-radius:8px; font-weight:500;
}
.success-box { background:#e8f5e9; color:#2e7d32; border:1px solid #c8e6c9; }
.error-box { background:#fdecea; color:#c62828; border:1px solid #f5c6cb; }
.highlight { font-weight:bold; color:#d32f2f; }

.modal {
  display: none;
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(0,0,0,0.6);
  justify-content: center;
  align-items: center;
  z-index: 9999; /* higher than header */
}

.modal-content { background:#fff; padding:25px; border-radius:12px; width:360px; max-width:90%; box-shadow:0 8px 20px rgba(0,0,0,0.2); text-align:center; }
.modal-content h3 { margin-top:0; margin-bottom:15px; }
.modal-content button { margin:10px 5px 0; }
/* Firefox */
* {
  scrollbar-width: thin;             /* thin scrollbar */
  scrollbar-color: #f5cea2ff #f0f0f0; /* thumb color | track color */
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
<!-- Header -->
<header class="header">
    <div class="header-left">
      <h1>Staff Accounts</h1>
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
            <a href="assign_table.php" >
                <i class="fa-solid fa-tablet-screen-button"></i> Assign Table
            </a>
        <a href="assign_roles.php" class="active">
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
<div class="container-role">

<?php if ($errors): ?>
<div class="error-box"><ul><?php foreach($errors as $e): ?><li><?= $e ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if ($success): ?>
<div class="success-box"><?= $success ?></div>
<?php endif; ?>

<div class="card-role">
<h2><i class="fa fa-user-plus"></i> Create Staff Account</h2>
<form method="post">
<input type="hidden" name="csrf_token" value="<?=$csrf_token?>">
<input type="hidden" name="action" value="create">
<label><i class="fa fa-user"></i> Username</label>
<input class="input" name="username" required>

<label><i class="fa fa-key"></i> Password (6 digits)</label>
<input class="input" type="text" name="password" required pattern="\d{6}" maxlength="6">

<label><i class="fa fa-user-tag"></i> Role</label>
<select name="role" class="input" required>
  <option value="cashier">Cashier</option>
  <option value="kitchen">Kitchen</option>
</select>

<label><i class="fa fa-lock"></i> Confirm Admin Password</label>
<input type="password" name="admin_pass" required class="input">

<button class="btnr btnr-primary"><i class="fa fa-plus"></i> Create</button>

</form>
</div>

<div class="card">
<h2><i class="fa fa-users"></i> Existing Accounts</h2>
<table class="table-role">
<thead>
<tr><th>Username</th><th>Role</th><th>Created</th><th>Last Login</th><th>Actions</th></tr>
</thead>
<tbody>
<?php if(!$accounts): ?>
<tr><td colspan="5">No accounts found.</td></tr>
<?php else: foreach($accounts as $a): ?>
<tr>
<td><?=h($a['username'])?></td>
<td><span class="role-tag <?=$a['role']==='cashier'?'role-cashier':'role-kitchen'?>"><?=h($a['role'])?></span></td>
<td><?= $a['created_at'] ? date("F j, Y", strtotime($a['created_at'])) : '—' ?></td>
<td><?= $a['last_login'] ? date("F j, Y", strtotime($a['last_login'])) : 'Never' ?>
</td>
<td>
<button class="btnr btnr-primary" onclick="showEditModal(<?= $a['id'] ?>)">
  <i class="fa fa-edit"></i> Edit
</button>

<button class="btnr btnr-secondary" onclick="showPasswordModal(<?= $a['id'] ?>)">
  <i class="fa fa-eye"></i> View
</button>

<button class="btnr btnr-danger" onclick="showDeleteModal(<?= $a['id'] ?>)">
  <i class="fa fa-trash"></i> Delete
</button>

</td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>
</div>

<!-- Edit Password Modal -->
<div class="modal" id="edit-modal">
  <div class="modal-content">
    <h3><i class="fa fa-edit"></i> Edit Staff Password</h3>
    <form method="post" id="edit-form">
      <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" id="edit-staff-id">

      <label><i class="fa fa-key"></i> New Password (6 digits)</label>
      <input type="text" name="new_password" pattern="\d{6}" maxlength="6" required class="input">

      <label><i class="fa fa-lock"></i> Confirm Admin Password</label>
      <input type="password" name="admin_pass" required class="input">

      <button type="submit" class="btnr btnr-primary"><i class="fa fa-save"></i> Save</button>
      <button type="button" class="btnr btnr-secondary" onclick="closeEditModal()"><i class="fa fa-times"></i> Cancel</button>
    </form>
  </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal" id="delete-modal">
  <div class="modal-content">
    <h3><i class="fa fa-exclamation-triangle" style="color:#d32f2f;"></i> Confirm Delete</h3>
    <p>Are you sure you want to delete this staff account?</p>

    <form method="post" id="delete-form">
      <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" id="delete-staff-id">

      <label><i class="fa fa-lock"></i> Admin Password</label>
      <input type="password" name="admin_pass" required class="input">

      <button type="submit" class="btnr btnr-danger"><i class="fa fa-trash"></i> Yes, Delete</button>
      <button type="button" class="btnr btnr-secondary" onclick="closeDeleteModal()"><i class="fa fa-times"></i> Cancel</button>
    </form>
  </div>
</div>


<!-- Modal -->
<div class="modal" id="password-modal">
  <div class="modal-content">
    <h3><i class="fa fa-lock"></i> Admin Authentication</h3>
    <div id="password-step">
      <input type="password" id="admin-pass" placeholder="Enter Admin Password" class="input">
      <button class="btnr btnr-primary" onclick="authenticateAdmin()"><i class="fa fa-check"></i> Submit</button>
      <button class="btnr btnr-secondary" onclick="closeModal()"><i class="fa fa-times"></i> Cancel</button>
    </div>
    <div id="password-result" style="display:none;">
      <p><i class="fa fa-key"></i> Staff Password:</p>
      <div style="background:#f1f8e9; border:1px solid #c5e1a5; padding:12px; border-radius:8px; font-size:18px; font-weight:bold; color:#2e7d32;" id="staff-password"></div>
      <button class="btnr btnr-secondary" onclick="closeModal()" style="margin-top:15px;"><i class="fa fa-times"></i> Close</button>
    </div>
  </div>
</div>
</div>

<script>
let currentStaffId = null;

function showPasswordModal(staffId) {
    currentStaffId = staffId;
    document.getElementById('password-modal').style.display = 'flex';
    document.getElementById('admin-pass').value = '';
    document.getElementById('password-step').style.display = 'block';
    document.getElementById('password-result').style.display = 'none';
}

function closeModal() {
    document.getElementById('password-modal').style.display = 'none';
}

function authenticateAdmin() {
    const adminPass = document.getElementById('admin-pass').value.trim();
    if (!adminPass) { alert("⚠️ Please enter your admin password."); return; }

    fetch("view_password.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
            staff_id: currentStaffId,
            admin_pass: adminPass,
            csrf_token: "<?= $csrf_token ?>"
        })
    }).then(res => res.json()).then(data => {
        if (data.success) {
            document.getElementById('staff-password').innerText = data.password;
            document.getElementById('password-step').style.display = 'none';
            document.getElementById('password-result').style.display = 'block';
        } else {
            alert("❌ " + data.error);
        }
    }).catch(() => alert("❌ Request failed. Please try again."));
}

function showEditModal(staffId) {
    document.getElementById('edit-staff-id').value = staffId;
    document.getElementById('edit-modal').style.display = 'flex';
}
function closeEditModal() {
    document.getElementById('edit-modal').style.display = 'none';
}

function showDeleteModal(staffId) {
    document.getElementById('delete-staff-id').value = staffId;
    document.getElementById('delete-modal').style.display = 'flex';
}

function closeDeleteModal() {
    document.getElementById('delete-modal').style.display = 'none';
}

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


