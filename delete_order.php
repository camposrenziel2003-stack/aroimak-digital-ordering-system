<?php
session_start();
if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

include "config.php";
if (isset($_GET['id'])) {
    $id = intval($_GET['id']);

    // Prepare delete query
    $stmt = $conn->prepare("DELETE FROM orders WHERE id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        $_SESSION['message'] = "Order deleted successfully.";
        $_SESSION['msg_type'] = "success";
    } else {
        $_SESSION['message'] = "Error deleting order.";
        $_SESSION['msg_type'] = "error";
    }

    $stmt->close();
}

// Redirect back to orders page
header("Location: order.php");
exit;
