<?php
session_start();
include "config.php";

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

$passkey = $_POST['passkey'] ?? '';
if (!preg_match('/^[0-9]{6}$/', $passkey)) {
    echo json_encode(['success' => false, 'error' => 'Invalid passkey format']);
    exit;
}

// ✅ same secret key used in assign_roles.php
define('PASSKEY_SECRET', 'your-very-strong-secret-key-32bytes!');

function decrypt_passkey($enc) {
    $data = base64_decode($enc);
    $iv = substr($data, 0, 16);
    $ciphertext = substr($data, 16);
    return openssl_decrypt($ciphertext, 'AES-256-CBC', PASSKEY_SECRET, OPENSSL_RAW_DATA, $iv);
}

// 🔍 find staff account with this passkey
$stmt = $conn->prepare("SELECT id, username, role, passkey_enc FROM staff_accounts WHERE role = 'kitchen'");
$stmt->execute();
$result = $stmt->get_result();

$valid = false;
$user = null;

while ($row = $result->fetch_assoc()) {
    $decrypted = decrypt_passkey($row['passkey_enc']);
    if ($decrypted === $passkey) {
        $valid = true;
        $user = $row;
        break;
    }
}

if ($valid) {
    $_SESSION['kds_logged_in'] = true;
    $_SESSION['kds_user'] = $user['username'];
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid passkey']);
}
if (password_verify($password, $row['password_hash'])) {
if ($row['role'] === 'kitchen') {
    $_SESSION['staff_id'] = $row['id'];
    $_SESSION['role'] = $row['role'];
    $_SESSION['username'] = $username;

// ✅ Set login success message
$_SESSION['login_success'] = "Log in successful!";

// ✅ Update last_login
$upd = $conn->prepare("UPDATE staff_accounts SET last_login=NOW() WHERE id=?");
$upd->bind_param("i", $row['id']);
$upd->execute();

// Redirect back to index.php
header("Location: index.php");
exit;
}}
