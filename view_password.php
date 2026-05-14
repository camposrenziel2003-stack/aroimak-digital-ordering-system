<?php
session_start();
include "config.php";
header('Content-Type: application/json');

// ✅ Only admin session
if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    echo json_encode(["success" => false, "error" => "Unauthorized"]);
    exit;
}

// ✅ Input
$data = json_decode(file_get_contents("php://input"), true);
$staff_id   = intval($data['staff_id'] ?? 0);
$admin_pass = $data['admin_pass'] ?? '';
$csrf_token = $data['csrf_token'] ?? '';

// ✅ CSRF
if (!hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    echo json_encode(["success" => false, "error" => "Invalid CSRF token"]);
    exit;
}

// ✅ Verify admin password
$admin_id = $_SESSION['admin_id'] ?? 0;
$stmt = $conn->prepare("SELECT password FROM admins WHERE id=?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$stmt->bind_result($admin_hash);
if (!$stmt->fetch()) {
    echo json_encode(["success" => false, "error" => "Admin not found"]);
    exit;
}
$stmt->close();

if (!password_verify($admin_pass, $admin_hash)) {
    echo json_encode(["success" => false, "error" => "Invalid admin password"]);
    exit;
}

// ✅ Get staff encrypted passkey
$stmt = $conn->prepare("SELECT passkey_enc FROM staff_accounts WHERE id=?");
$stmt->bind_param("i", $staff_id);
$stmt->execute();
$stmt->bind_result($enc);
if (!$stmt->fetch()) {
    echo json_encode(["success" => false, "error" => "Staff not found"]);
    exit;
}
$stmt->close();

// ✅ Decrypt
define("SECRET_KEY", "Your32CharEncryptionKeyGoesHere1234");
define("SECRET_IV", "1234567890123456");

$password = openssl_decrypt(base64_decode($enc), "AES-256-CBC", SECRET_KEY, 0, SECRET_IV);

echo json_encode(["success" => true, "password" => $password]);
