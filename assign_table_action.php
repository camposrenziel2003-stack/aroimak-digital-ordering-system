<?php
session_start();
if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}
include "config.php";
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $tablet_id = intval($_POST['tablet_id']);
    $table_number = intval($_POST['table_number']);

    // Update the tablet's table number
    $stmt = $conn->prepare("UPDATE tablets SET table_number=? WHERE id=?");
    $stmt->bind_param("ii", $table_number, $tablet_id);

    if ($stmt->execute()) {
        header("Location: assign_table.php?success=1");
        exit;
    } else {
        header("Location: assign_table.php?error=1");
        exit;
    }
}
header("Location: assign_table.php");
exit;