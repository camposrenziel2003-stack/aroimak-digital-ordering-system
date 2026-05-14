<?php
session_start();
include "config.php";

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if ($username === "" || $password === "") {
    echo json_encode(['success' => false, 'error' => 'Please enter username and password.']);
    exit;
}

$stmt = $conn->prepare("SELECT id, username, password_hash, role FROM staff_accounts WHERE username=? LIMIT 1");
$stmt->bind_param("s", $username);
$stmt->execute();
$res = $stmt->get_result();

if ($row = $res->fetch_assoc()) {
    if (password_verify($password, $row['password_hash'])) {
        if ($row['role'] === 'cashier') {
            // ✅ Set session
            $_SESSION['staff_id'] = $row['id'];
            $_SESSION['role'] = $row['role'];
            $_SESSION['username'] = $row['username'];

            // ✅ Update last_login
            $upd = $conn->prepare("UPDATE staff_accounts SET last_login=NOW() WHERE id=?");
            $upd->bind_param("i", $row['id']);
            $upd->execute();

            echo json_encode(['success' => true]);
            exit;
        } else {
            echo json_encode(['success' => false, 'error' => 'Access denied: only cashier staff allowed.']);
            exit;
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid password.']);
        exit;
    }
} else {
    echo json_encode(['success' => false, 'error' => 'User not found.']);
    exit;
}
