<?php
session_start();
include "config.php";

if (!isset($_SESSION['admin'])) {
    exit("Unauthorized");
}

$adminId = (int) $_SESSION['admin'];
$message = "";

// Fetch current admin info
$stmt = $conn->prepare("SELECT username, profile_pic, password FROM admins WHERE id=?");
$stmt->bind_param("i", $adminId);
$stmt->execute();
$result = $stmt->get_result();
$admin = $result->fetch_assoc();

if ($admin) {
    $username    = htmlspecialchars($admin['username']);
    $profilePic  = !empty($admin['profile_pic']) ? $admin['profile_pic'] : "default.png";
    $currentHash = $admin['password'];
} else {
    $username    = "Admin";
    $profilePic  = "default.png";
    $currentHash = null;
}

/* ========== Handle POST (single endpoint, action-based) ========== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // Upload profile picture
    if ($action === 'upload_pic' && isset($_FILES['profile_pic'])) {
        $file = $_FILES['profile_pic'];
        $allowed = ['jpg','jpeg','png','gif','webp'];
        $maxSize = 2 * 1024 * 1024; // 2MB

        if ($file['error'] !== 0) {
            $message = "❌ Error uploading file (code {$file['error']}).";
        } else {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $mime = @mime_content_type($file['tmp_name']) ?: '';

            if (!in_array($ext, $allowed) || strpos($mime, "image/") !== 0) {
                $message = "❌ Invalid file type.";
            } elseif ($file['size'] > $maxSize) {
                $message = "❌ File too large (max 2MB).";
            } else {
                $newName = "admin_{$adminId}_" . time() . "." . $ext;
                $uploadDir = __DIR__ . "/uploads/";
                $uploadPath = $uploadDir . $newName;

                if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

                if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                    if (!empty($admin['profile_pic']) && $admin['profile_pic'] !== "default.png") {
                        $oldPath = $uploadDir . $admin['profile_pic'];
                        if (file_exists($oldPath)) @unlink($oldPath);
                    }

                    $update = $conn->prepare("UPDATE admins SET profile_pic=? WHERE id=?");
                    $update->bind_param("si", $newName, $adminId);
                    $update->execute();

                    $profilePic = $newName;
                    $_SESSION['profile_pic'] = $newName;
                    $message = "✅ Profile picture updated!";
                } else {
                    $message = "❌ Upload failed.";
                }
            }
        }
    }

    // Change username
    if ($action === 'change_username' && isset($_POST['new_username'], $_POST['password_check'])) {
        $newUsername = trim($_POST['new_username']);
        $passwordCheck = $_POST['password_check'];

        if ($currentHash === null || !password_verify($passwordCheck, $currentHash)) {
            $message = "❌ Current password is incorrect.";
        } elseif (!preg_match("/^[a-zA-Z0-9_]{3,20}$/", $newUsername)) {
            $message = "❌ Username must be 3–20 characters (letters, numbers, underscore only).";
        } else {
            $check = $conn->prepare("SELECT id FROM admins WHERE username=? AND id!=?");
            $check->bind_param("si", $newUsername, $adminId);
            $check->execute();
            $check->store_result();

            if ($check->num_rows > 0) {
                $message = "❌ Username already taken.";
            } else {
                $update = $conn->prepare("UPDATE admins SET username=? WHERE id=?");
                $update->bind_param("si", $newUsername, $adminId);
                $update->execute();

                $username = htmlspecialchars($newUsername);
                $_SESSION['username'] = $newUsername;
                $message = "✅ Username updated!";
            }
        }
    }

    // Change password
    if ($action === 'change_password' && isset($_POST['old_password'], $_POST['new_password'], $_POST['confirm_password'])) {
        $oldPassword     = $_POST['old_password'];
        $newPassword     = $_POST['new_password'];
        $confirmPassword = $_POST['confirm_password'];

        if ($currentHash === null || !password_verify($oldPassword, $currentHash)) {
            $message = "❌ Old password is incorrect.";
        } elseif (!preg_match("/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[\W_]).{8,}$/", $newPassword)) {
            $message = "❌ Password must be at least 8 characters, include uppercase, lowercase, number, and special character.";
        } elseif ($newPassword !== $confirmPassword) {
            $message = "❌ Passwords do not match.";
        } else {
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $update = $conn->prepare("UPDATE admins SET password=? WHERE id=?");
            $update->bind_param("si", $newHash, $adminId);
            $update->execute();
            $message = "✅ Password updated!";
        }
    }

    // re-fetch updated info
    $stmt = $conn->prepare("SELECT username, profile_pic FROM admins WHERE id=?");
    $stmt->bind_param("i", $adminId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) {
        $username = htmlspecialchars($row['username']);
        $profilePic = !empty($row['profile_pic']) && file_exists(__DIR__ . "/uploads/" . $row['profile_pic']) ? $row['profile_pic'] : 'default.png';
        $_SESSION['profile_pic'] = $profilePic;
    }
}
?>

<div class="profile-wrapper">
  <h2 style="text-align:center; margin-bottom:10px; font-size: 25px; color:#333;">👤 Admin Profile</h2>

  <img src="uploads/<?= htmlspecialchars($profilePic) ?>?v=<?= time() ?>" alt="Profile Picture" class="profile-avatar">

  <div class="username-section">
    <h1 id="usernameDisplay"><?= $username ?></h1>
    <span class="edit-icon" id="editIcon" onclick="toggleEdit()">✏️</span>
  </div>

  <!-- Edit Username Form -->
  <form id="usernameForm" method="POST" class="hidden profile-form">
    <input type="hidden" name="action" value="change_username">
    <input type="text" name="new_username" value="<?= $username ?>" required>
    <input type="password" name="password_check" placeholder="Enter Current Password" required>
    <div class="btn-group">
      <button type="submit">Save</button>
      <button type="button" onclick="toggleEdit()">Cancel</button>
    </div>
  </form>

  <?php if ($message): ?>
    <p id="flashMessage" class="message <?= strpos($message,'❌')!==false ? 'error':'' ?>"><?= $message ?></p>
  <?php endif; ?>

  <!-- Upload Profile Picture -->
  <form id="uploadForm" method="POST" enctype="multipart/form-data" class="profile-form">
    <input type="hidden" name="action" value="upload_pic">
    <input type="file" name="profile_pic" accept="image/*" required>
    <button type="submit">Upload New Picture</button>
  </form>

  <hr>

  <!-- Change Password -->
  <form id="passwordForm" method="POST" class="profile-form">
    <input type="hidden" name="action" value="change_password">
    <input type="password" name="old_password" placeholder="Old Password" required>
    <input type="password" name="new_password" placeholder="New Password (min 8 chars)" required>
    <input type="password" name="confirm_password" placeholder="Confirm New Password" required>
    <button type="submit">Change Password</button>
  </form>
</div>

<style>
.message {
  padding: 10px;
  margin: 10px 0;
  border-radius: 6px;
  font-weight: bold;
  text-align: center;
}
.message.error {
  background: #ffe6e6;
  color: #cc0000;
}
.message:not(.error) {
  background: #e6ffe6;
  color: #009900;
}
.hidden { display:none; }
</style>

<script>
function toggleEdit() {
  document.getElementById("usernameDisplay").classList.toggle("hidden");
  document.getElementById("usernameForm").classList.toggle("hidden");
}

// Auto-hide flash messages after 2s
document.addEventListener("DOMContentLoaded", () => {
  const flash = document.getElementById("flashMessage");
  if (flash) {
    setTimeout(() => {
      flash.style.transition = "opacity 0.1s ease";
      flash.style.opacity = "0";
      setTimeout(() => flash.remove(), 500);
    }, 2000);
  }
});
</script>
