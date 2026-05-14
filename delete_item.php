<?php
session_start();
if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

require "config.php";

// Get the id safely
if (!isset($_GET['id']) || !ctype_digit($_GET['id'])) {
    header("Location: index.php?err=invalid_id");
    exit;
}
$id = (int) $_GET['id'];

// Determine where to redirect after deletion
$redirect = isset($_GET['redirect']) ? $_GET['redirect'] : 'index.php';

// Get image name first (to delete from uploads folder)
$stmt = $conn->prepare("SELECT image FROM menu_items WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$stmt->bind_result($image);
$found = $stmt->fetch();
$stmt->close();

if (!$found) {
    header("Location: $redirect?err=not_found");
    exit;
}

// Delete item from database
$stmt = $conn->prepare("DELETE FROM menu_items WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$stmt->close();

// Delete image file if it exists
if (!empty($image)) {
    $filePath = __DIR__ . "/uploads/" . $image;
    if (file_exists($filePath)) {
        unlink($filePath);
    }
}

// Redirect back to the page with a success message
header("Location: $redirect?msg=deleted");
exit;
?>
