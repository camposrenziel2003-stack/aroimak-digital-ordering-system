<?php
session_start();
if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}
include "config.php";

// Archive order via GET for consistency with current delete links.
// Usage: archive_order.php?id=45&redirect=order.php
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$redirect = isset($_GET['redirect']) ? $_GET['redirect'] : 'order.php';

if ($id > 0) {
    if ($stmt = $conn->prepare("UPDATE orders SET archived = 1 WHERE id = ?")) {
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
    } else {
        // fallback
        $conn->query("UPDATE orders SET archived = 1 WHERE id = " . $id);
    }
}

header("Location: " . $redirect);
exit;
?>