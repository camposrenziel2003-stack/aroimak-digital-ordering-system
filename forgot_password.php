<?php
session_start();
include "config.php";
require 'src/PHPMailer.php';
require 'src/SMTP.php';
require 'src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Force PHP timezone
date_default_timezone_set('Asia/Manila');
$conn->query("SET time_zone = '+08:00'");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email']);

    $stmt = $conn->prepare("SELECT * FROM admins WHERE email=? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $admin = $result->fetch_assoc();

        // Generate token
        $token = bin2hex(random_bytes(32));
        $expires = date("Y-m-d H:i:s", strtotime("+1 hour"));

        $update = $conn->prepare("UPDATE admins SET reset_token=?, reset_expires=? WHERE email=?");
        $update->bind_param("sss", $token, $expires, $email);
        $update->execute();

        $resetLink = "http://localhost/thai_digital/reset_password.php?token=" . $token;

        // Send email
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'secuyamonica8@gmail.com';
            $mail->Password   = 'jzagvwguwjzjcpek';
            $mail->SMTPSecure = 'tls';
            $mail->Port       = 587;

            $mail->setFrom('itsathai.sys@gmail.com', 'It\'s A Thai - Admin Panel');
            $mail->addAddress($email, $admin['username']);

            // ✅ Embed logo (make sure logo.png exists in your project folder)
            $mail->AddEmbeddedImage(__DIR__ . "/logo.png", "thaiLogo");

            $mail->isHTML(true);
            $mail->Subject = 'Password Reset Request - It\'s A Thai';

            $mail->Body = '
              <div style="font-family:Arial,sans-serif; padding:20px; background:#f9f9f9; border-radius:8px;">
                <div style="text-align:center; margin-bottom:20px;">
                  <img src="cid:thaiLogo" alt="It\'s A Thai Logo" style="width:150px;">
                </div>
                <h2 style="color:#b22222; text-align:center;">Password Reset Request</h2>
                <p>Hello <b>' . htmlspecialchars($admin['username']) . '</b>,</p>
                <p>We received a request to reset your password for your <b>It\'s A Thai</b> admin account.</p>
                <p style="text-align:center; margin:20px 0;">
                  <a href="' . $resetLink . '" 
                     style="display:inline-block; padding:12px 20px; background:#b22222; color:#fff; 
                            text-decoration:none; border-radius:6px; font-weight:bold;">
                    Reset My Password
                  </a>
                </p>
                <p>If the button doesn\'t work, copy this link into your browser:</p>
                <p><a href="' . $resetLink . '">' . $resetLink . '</a></p>
                <hr style="margin:20px 0;">
                <p style="font-size:0.9rem; color:#555;">This link will expire in 1 hour. If you didn\'t request a reset, you can ignore this email.</p>
                <p style="text-align:center; font-size:0.85rem; color:#888;">© ' . date("Y") . ' It\'s A Thai Restaurant</p>
              </div>';

            $mail->send();
            $success = "A password reset link has been sent to your email.";
        } catch (Exception $e) {
            $error = "Mailer Error: {$mail->ErrorInfo}";
        }
    } else {
        $error = "Email not found!";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Forgot Password</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600&display=swap" rel="stylesheet">
<style>
body {
  margin: 0;
  font-family: 'Sarabun', sans-serif;
  background: url('your-bg.jpg') no-repeat center center fixed; /* Replace with Thai food background */
  background-size: cover;
}
.modal-overlay {
  position: fixed;
  top: 0; left: 0;
  width: 100%; height: 100%;
  background: linear-gradient(rgba(0,0,0,0.6), rgba(219, 93, 39, 0.6));
  display: flex;
  justify-content: center;
  align-items: center;
}
.modal-box {
  background: #fff;
  padding: 30px 40px;
  border-radius: 14px;
  width: 100%;
  max-width: 380px;
  box-shadow: 0 8px 25px rgba(0,0,0,0.4);
  text-align: center;
  border: 3px solid #d4af37;
  animation: floatIn 0.6s ease forwards;
  opacity: 0;
  transform: scale(0.85);
}
@keyframes floatIn {
  0% {opacity:0; transform:scale(0.85) translateY(30px);}
  60% {opacity:1; transform:scale(1.05) translateY(-5px);}
  100% {opacity:1; transform:scale(1) translateY(0);}
}
.modal-box h2 {
  margin: 0 0 20px;
  color: #b22222;
  font-size: 1.5rem;
}
.input-group {
  position: relative;
  margin-bottom: 15px;
}
.input-group input {
  width: 85%;
  padding: 12px 40px 12px 12px;
  border: 1px solid #ccc;
  border-radius: 8px;
  font-size: 1rem;
  transition: 0.3s;
}
.input-group input:focus {
  border-color: #d4af37;
  box-shadow: 0 0 5px rgba(212,175,55,0.6);
  outline: none;
}
.input-group i {
  position: absolute;
  right: 12px;
  top: 50%;
  transform: translateY(-50%);
  color: #aaa;
}
button {
  width: 98%;
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
button:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(178,34,34,0.4);
}
.error {color: #b22222; margin-bottom: 10px;}
.success {color: #2e7d32; margin-bottom: 10px;}
.modal-box a {
  display: block;
  margin-top: 15px;
  color: #b22222;
  font-size: 0.9rem;
  text-decoration: none;
}
.modal-box a:hover {text-decoration: underline;}
</style>
</head>
<body>
<div class="modal-overlay">
  <div class="modal-box">
    <img src="logo.png" alt="It's A Thai" style="width:120px; margin-bottom:10px;">
    <h2><i class="fa-solid fa-key"></i> Forgot Password</h2>

    <?php if (!empty($error)): ?>
      <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>
    <?php if (!empty($success)): ?>
      <p class="success"><?= $success ?></p>
    <?php endif; ?>

    <form method="POST">
      <div class="input-group">
        <input type="email" name="email" placeholder="Enter your email" required>
        <i class="fa-solid fa-envelope"></i>
      </div>
      <button type="submit"><i class="fa-solid fa-paper-plane"></i> Send Reset Link</button>
    </form>

    <a href="login.php"><i class="fa-solid fa-arrow-left"></i> Back to Login</a>
  </div>
</div>
</body>
</html>
