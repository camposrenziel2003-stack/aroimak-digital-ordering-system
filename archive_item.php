<?php
// archive_item.php
session_start();
if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}
include "config.php";

$id = isset($_REQUEST['id']) ? (int)$_REQUEST['id'] : 0; // accept GET or POST via REQUEST
$redirect = $_REQUEST['redirect'] ?? 'menu.php';
$debug = isset($_REQUEST['debug']) && $_REQUEST['debug'] === '1';

if (!$id) {
    if ($debug) { echo "Invalid id"; exit; }
    header("Location: " . $redirect . (strpos($redirect,'?')===false ? '?' : '&') . "archive_status=invalid_id");
    exit;
}

// We intentionally DO NOT set archived_by here (user requested "remove the archive by").
// Ensure the menu_items table has an `archived` TINYINT/BOOLEAN and `archived_at` DATETIME columns.

$stmt = $conn->prepare("UPDATE menu_items SET archived = 1, archived_at = NOW() WHERE id = ?");
if (!$stmt) {
    if ($debug) { echo "Prepare failed: ".$conn->error; exit; }
    header("Location: " . $redirect . (strpos($redirect,'?')===false ? '?' : '&') . "archive_status=error");
    exit;
}

$stmt->bind_param("i", $id);
$ok = $stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

if ($debug) {
    echo "Archive item id={$id} ok=" . ($ok ? '1' : '0') . " affected={$affected}";
    exit;
}

if ($affected > 0) {
    header("Location: " . $redirect . (strpos($redirect,'?')===false ? '?' : '&') . "archive_status=ok");
} else {
    header("Location: " . $redirect . (strpos($redirect,'?')===false ? '?' : '&') . "archive_status=none");
}
exit;
?>