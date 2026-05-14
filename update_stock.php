<?php
session_start();
if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}
include "config.php";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = intval($_POST['id']); 
    $stock = intval($_POST['stock']); 
    $redirect = $_POST['redirect'] ?? 'menu.php';

    // ✅ Update only stock column
    $stmt = $conn->prepare("UPDATE menu_items SET stock=? WHERE id=?");
    $stmt->bind_param("ii", $stock, $id);
    $stmt->execute();
    $stmt->close();

    header("Location: " . $redirect);
    exit;
}
