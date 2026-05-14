<?php
session_start();
include "config.php";
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT * FROM admins WHERE username=? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $admin = $result->fetch_assoc();

        $valid = false;

        if (password_verify($password, $admin['password'])) {
            $valid = true;
        } elseif ($admin['password'] === md5($password)) {
            $valid = true;
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $update = $conn->prepare("UPDATE admins SET password=? WHERE id=?");
            $update->bind_param("si", $newHash, $admin['id']);
            $update->execute();
        }

        if ($valid) {
            $_SESSION['admin'] = true;
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['username'] = $admin['username'];
            $_SESSION['profile_pic'] = !empty($admin['profile_pic']) ? $admin['profile_pic'] : 'default.png';

            header("Location: index.php");
            exit;
        } else {
            $error = "Invalid credentials!";
        }
    } else {
        $error = "Invalid credentials!";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>It's A Thai | Admin Login</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600&display=swap" rel="stylesheet">
<style>
body {
  margin: 0;
  font-family: 'Sarabun', sans-serif;
  background: url('bg.png') no-repeat center center fixed; /* ✅ Replace with your bg */
  background-size: cover;
}

/* Overlay */
.modal-overlay {
  position: fixed;
  top: 0; left: 0;
  width: 100%; height: 100%;
  background: linear-gradient(rgba(0,0,0,0.6), rgba(178,34,34,0.6));
  display: flex;
  justify-content: center;
  align-items: center;
}

/* Modal box */
.modal-box {
  background: rgba(255,255,255,0.95); /* slightly transparent */
  padding: 30px 40px;
  border-radius: 14px;
  width: 100%;
  max-width: 380px;
  box-shadow: 0 8px 25px rgba(0,0,0,0.4);
  text-align: center;
  animation: floatIn 0.6s ease forwards;
  border: 3px solid #d4af37;
  position: relative;
  transform: scale(0.85);
  opacity: 0;
}

/* Floating scale-up animation */
@keyframes floatIn {
  0% { opacity: 0; transform: scale(0.85) translateY(30px); }
  60% { opacity: 1; transform: scale(1.05) translateY(-5px); }
  100% { opacity: 1; transform: scale(1) translateY(0); }
}

/* Logo */
.modal-box img.logo {
  width: 130px;
  margin-bottom: 15px;
}

/* Heading */
.modal-box h2 {
  margin: 10px 0 20px;
  font-size: 1.6rem;
  color: #b22222;
}

/* Input group with icons */
.input-group {
  position: relative;
  margin-bottom: 15px;
}
.input-group i.fa-user,
.input-group i.fa-lock {
  position: absolute;
  top: 50%;
  left: 12px;
  transform: translateY(-50%);
  color: #b22222;
  font-size: 16px;
}
.input-group input {
  width: 80%;
  padding: 12px 40px 12px 38px; /* space for left + right icons */
  border-radius: 8px;
  border: 1px solid #ccc;
  font-size: 1rem;
  transition: 0.3s;
}
.input-group input:focus {
  border-color: #d4af37;
  outline: none;
  box-shadow: 0 0 5px rgba(212,175,55,0.6);
}

/* Eye icon (toggle password) */
.input-group .toggle-password {
  position: absolute;
  top: 50%;
  right: 12px;
  transform: translateY(-50%);
  cursor: pointer;
  color: #555;
  font-size: 16px;
}

/* Button */
.modal-box button {
  width: 100%;
  padding: 12px;
  border: none;
  border-radius: 8px;
  background: linear-gradient(135deg, #b22222, #d4af37);
  color: #fff;
  font-size: 1rem;
  font-weight: bold;
  cursor: pointer;
  transition: 0.3s;
}
.modal-box button:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(178,34,34,0.4);
}

/* Error + Success */
.modal-box .error {
  color: #b22222;
  margin-bottom: 10px;
}
.success {
  color: #2e7d32;
  margin-bottom: 10px;
}

/* Forgot Password */
.modal-box a {
  display: block;
  margin-top: 15px;
  color: #b22222;
  text-decoration: none;
  font-size: 0.9rem;
}
.modal-box a:hover {
  text-decoration: underline;
}
</style>
</head>
<body>
<div class="modal-overlay">
  <div class="modal-box">
    <img src="logo.png" alt="It's A Thai Logo" class="logo">

    <h2><i class="fa-solid fa-user-shield"></i> Admin Login</h2>

    <?php if (!empty($_SESSION['reset_success'])): ?>
      <p class="success"><?= $_SESSION['reset_success']; unset($_SESSION['reset_success']); ?></p>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
      <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="POST">
      <div class="input-group">
        <i class="fa-solid fa-user"></i>
        <input type="text" name="username" placeholder="Username" required>
      </div>
      <div class="input-group">
        <i class="fa-solid fa-lock"></i>
        <input type="password" id="password" name="password" placeholder="Password" required>
        <i class="fa-solid fa-eye toggle-password" id="togglePassword"></i>
      </div>
      <button type="submit" name="login"><i class="fa-solid fa-right-to-bracket"></i> Login</button>
    </form>

    <a href="forgot_password.php">Forgot Password?</a>
  </div>
</div>

<script>
// Toggle password visibility
const togglePassword = document.querySelector("#togglePassword");
const passwordInput = document.querySelector("#password");

togglePassword.addEventListener("click", function () {
  const type = passwordInput.getAttribute("type") === "password" ? "text" : "password";
  passwordInput.setAttribute("type", type);
  this.classList.toggle("fa-eye-slash");
});
</script>
</body>
</html>
