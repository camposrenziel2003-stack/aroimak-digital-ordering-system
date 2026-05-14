<?php
// (UPDATED) unarchive_order.php - small improvement to preserve redirect & debug handling
session_start();
if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}
include "config.php";

$id = null;
if (isset($_POST['id'])) $id = (int)$_POST['id'];
elseif (isset($_GET['id'])) $id = (int)$_GET['id'];

$redirect = $_POST['redirect'] ?? ($_GET['redirect'] ?? 'order.php');
$debug = isset($_GET['debug']) && $_GET['debug'] == '1';

if (!$id || $id <= 0) {
    $msg = "Invalid id for unarchive_order: " . var_export($id, true);
    error_log($msg);
    if ($debug) {
        echo $msg;
        exit;
    }
    header("Location: " . $redirect . (strpos($redirect, '?') === false ? '?' : '&') . "unarchive_status=invalid_id");
    exit;
}

$stmt = $conn->prepare("UPDATE orders SET archived = 0, archived_at = NULL WHERE id = ?");
if (!$stmt) {
    $err = "Prepare failed: " . $conn->error;
    error_log($err);
    if ($debug) { echo $err; exit; }
    header("Location: " . $redirect . (strpos($redirect, '?') === false ? '?' : '&') . "unarchive_status=error");
    exit;
}

$stmt->bind_param("i", $id);
if (!$stmt->execute()) {
    $err = "Execute failed: " . $stmt->error;
    error_log($err);
    if ($debug) { echo $err; $stmt->close(); exit; }
    $stmt->close();
    header("Location: " . $redirect . (strpos($redirect, '?') === false ? '?' : '&') . "unarchive_status=error");
    exit;
}

$affected = $stmt->affected_rows;
$stmt->close();

if ($debug) {
    echo "Unarchive order id={$id} affected_rows={$affected}";
    exit;
}

if ($affected > 0) {
    header("Location: " . $redirect . (strpos($redirect, '?') === false ? '?' : '&') . "unarchive_status=ok");
} else {
    header("Location: " . $redirect . (strpos($redirect, '?') === false ? '?' : '&') . "unarchive_status=none");
}
exit;
?>