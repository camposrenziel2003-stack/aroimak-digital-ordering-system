<?php
// unarchive_item.php
session_start();
if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}
include "config.php";

$id = isset($_REQUEST['id']) ? (int)$_REQUEST['id'] : 0; // accept GET or POST via REQUEST
$redirect = $_REQUEST['redirect'] ?? 'menu.php';
$debug = isset($_REQUEST['debug']) && $_REQUEST['debug'] == '1';

if (!$id || $id <= 0) {
    $msg = "Invalid id for unarchive_item: " . var_export($id, true);
    error_log($msg);
    if ($debug) { echo $msg; exit; }
    header("Location: " . $redirect . (strpos($redirect, '?') === false ? '?' : '&') . "unarchive_status=invalid_id");
    exit;
}

// Remove archived_by handling as requested; only toggle archived and archived_at
$stmt = $conn->prepare("UPDATE menu_items SET archived = 0, archived_at = NULL WHERE id = ?");
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
    echo "Unarchive item id={$id} affected_rows={$affected}";
    exit;
}

if ($affected > 0) {
    header("Location: " . $redirect . (strpos($redirect, '?') === false ? '?' : '&') . "unarchive_status=ok");
} else {
    header("Location: " . $redirect . (strpos($redirect, '?') === false ? '?' : '&') . "unarchive_status=none");
}
exit;
?>