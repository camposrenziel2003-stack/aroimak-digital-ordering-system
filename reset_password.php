<?php
session_start();
include "config.php";
// Force PHP timezone
date_default_timezone_set('Asia/Manila');
$conn->query("SET time_zone = '+08:00'");

if (!isset($_GET['token'])) {
    die("❌ Invalid link.");
}

$token = $_GET['token'];

// Check if token exists and is not expired
$stmt = $conn->prepare("SELECT * FROM admins WHERE reset_token=? AND reset_expires > NOW() LIMIT 1");
$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("❌ This link is invalid or has expired.");
}

$admin = $result->fetch_assoc();
$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $password = $_POST['password'];
    $confirm  = $_POST['confirm_password'];

    if ($password !== $confirm) {
        $error = "❌ Passwords do not match!";
    } elseif (strlen($password) < 6) {
        $error = "❌ Password must be at least 6 characters.";
    } else {
        $new_password = password_hash($password, PASSWORD_DEFAULT);

        // Update password & clear reset token
        $update = $conn->prepare("UPDATE admins SET password=?, reset_token=NULL, reset_expires=NULL WHERE id=?");
        $update->bind_param("si", $new_password, $admin['id']);
        $update->execute();

        $_SESSION['force_change'] = true;
        $_SESSION['reset_success'] = "✅ Your password has been reset. Please log in.";
        header("Location: login.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Reset Password</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600&display=swap" rel="stylesheet">
<style>
body {
  margin: 0;
  font-family: 'Sarabun', sans-serif;
  background: url('your-bg.jpg') no-repeat center center fixed; /* replace with your bg */
  background-size: cover;
}
.modal-overlay {
  position: fixed; top:0; left:0;
  width:100%; height:100%;
  background: linear-gradient(rgba(0,0,0,0.6), rgba(178,34,34,0.6));
  display:flex; justify-content:center; align-items:center;
}
.modal-box {
  background:#fff; padding:30px 40px; border-radius:14px;
  width:100%; max-width:400px;
  box-shadow:0 8px 25px rgba(0,0,0,0.4);
  border:3px solid #d4af37;
  text-align:center;
  animation:floatIn 0.6s ease forwards;
  opacity:0; transform:scale(0.9);
}
@keyframes floatIn {
  0% {opacity:0; transform:scale(0.85) translateY(30px);}
  60% {opacity:1; transform:scale(1.05) translateY(-5px);}
  100% {opacity:1; transform:scale(1) translateY(0);}
}
h2 {margin-bottom:20px; color:#b22222; font-weight:600;}
.input-group {
  position:relative; margin-bottom:18px;
}
.input-group input {
  width:80%; padding:12px 40px;
  border:1px solid #ccc; border-radius:8px;
  font-size:1rem; transition:0.3s;
}
.input-group input:focus {
  border-color:#d4af37;
  box-shadow:0 0 5px rgba(212,175,55,0.6);
  outline:none;
}
.input-group .left-icon {
  position:absolute; left:12px; top:50%; transform:translateY(-50%);
  color:#999;
}
.input-group .toggle-password {
  position:absolute; right:12px; top:50%; transform:translateY(-50%);
  color:#999; cursor:pointer;
}
button {
  width:100%; padding:12px;
  border:none; border-radius:8px;
  background:linear-gradient(135deg, #b22222, #d4af37);
  color:#fff; font-size:1rem; font-weight:bold;
  cursor:pointer; transition:0.3s;
}
button:hover {
  transform:translateY(-2px);
  box-shadow:0 4px 12px rgba(178,34,34,0.4);
}
.error {color:#b22222; margin-bottom:10px; font-weight:600;}
</style>
</head>
<body>
<div class="modal-overlay">
  <div class="modal-box">
    <img src="logo.png" alt="It's A Thai" style="width:120px; margin-bottom:10px;">
    <h2><i class="fa-solid fa-lock"></i> Reset Password</h2>

    <?php if (!empty($error)): ?>
      <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="POST">
      <div class="input-group">
        <i class="fa-solid fa-lock left-icon"></i>
        <input type="password" name="password" id="password" placeholder="New password" required>
        <i class="fa-solid fa-eye toggle-password" onclick="togglePassword('password', this)"></i>
      </div>
      <div class="input-group">
        <i class="fa-solid fa-lock left-icon"></i>
        <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm password" required>
        <i class="fa-solid fa-eye toggle-password" onclick="togglePassword('confirm_password', this)"></i>
      </div>
      <button type="submit"><i class="fa-solid fa-rotate-right"></i> Update Password</button>
    </form>
  </div>
</div>

<script>
function togglePassword(fieldId, icon) {
  const field = document.getElementById(fieldId);
  if (field.type === "password") {
    field.type = "text";
    icon.classList.replace("fa-eye", "fa-eye-slash");
  } else {
    field.type = "password";
    icon.classList.replace("fa-eye-slash", "fa-eye");
  }
}
</script>
</body>
</html>
