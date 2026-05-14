<?php
session_start();
if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

include "config.php";
// Find Fried Rice and Noodles category IDs
$friedRiceId = $noodlesId = null;
$catRes = $conn->query("SELECT id, name FROM categories");
while ($catRow = $catRes->fetch_assoc()) {
    if (strtolower($catRow['name']) === "fried rice") $friedRiceId = (int)$catRow['id'];
    if (strtolower($catRow['name']) === "noodles") $noodlesId = (int)$catRow['id'];
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['id'])) {
    $id = (int) $_POST['id'];
    $name = $_POST['name'];
    $desc = $_POST['description'];
    $cat_id = (int) $_POST['category_id'];
    $stock = (int) ($_POST['stock'] ?? 1); // fallback if stock not present
    $preferences = isset($_POST['preferences']) ? implode(',', $_POST['preferences']) : '';

    // Prices
    $price = isset($_POST['price']) ? floatval($_POST['price']) : null;
    $price_solo = isset($_POST['price_solo']) ? floatval($_POST['price_solo']) : null;
    $price_sharing = isset($_POST['price_sharing']) ? floatval($_POST['price_sharing']) : null;

    // Portion size logic
    $portion_size = trim($_POST['portion_size'] ?? '');
    if ($cat_id == $friedRiceId || $cat_id == $noodlesId) {
        if (!in_array($portion_size, ['Solo', 'Sharing'])) $portion_size = '';
    } else {
        $portion_size = '';
        $price_solo = null;
        $price_sharing = null;
    }

    // Fetch current item data
    $stmt = $conn->prepare("SELECT image, pronunciation_file FROM menu_items WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $currentItem = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $newImage = $currentItem['image'];
    $newPronFile = $currentItem['pronunciation_file'];

    // Handle image upload
    if (!empty($_FILES['image']['name'])) {
        $targetDir = "uploads/";
        $fileName = time() . "_" . basename($_FILES["image"]["name"]);
        $targetFile = $targetDir . $fileName;
        $fileType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));

        $allowedTypes = ["jpg", "jpeg", "png", "gif"];
        if (in_array($fileType, $allowedTypes)) {
            if (move_uploaded_file($_FILES["image"]["tmp_name"], $targetFile)) {
                if (!empty($currentItem['image']) && file_exists("uploads/" . $currentItem['image'])) {
                    unlink("uploads/" . $currentItem['image']);
                }
                $newImage = $fileName;
            }
        }
    }

    // Handle pronunciation file upload
    if (!empty($_FILES['pronunciation_file']['name'])) {
        $pronDir = "uploads/pronunciations/";
        if (!is_dir($pronDir)) mkdir($pronDir, 0755, true);
        $pronFileName = time() . "_" . basename($_FILES['pronunciation_file']['name']);
        $pronTarget = $pronDir . $pronFileName;

        $allowedPronTypes = ["mp3", "wav", "m4a", "ogg"];
        $pronExt = strtolower(pathinfo($pronTarget, PATHINFO_EXTENSION));

        if (in_array($pronExt, $allowedPronTypes)) {
            if (move_uploaded_file($_FILES['pronunciation_file']['tmp_name'], $pronTarget)) {
                if (!empty($currentItem['pronunciation_file']) && file_exists($pronDir . $currentItem['pronunciation_file'])) {
                    unlink($pronDir . $currentItem['pronunciation_file']);
                }
                $newPronFile = $pronFileName;
            }
        }
    }

    // Update database including price logic
   // Update database including price logic
if ($cat_id == $friedRiceId || $cat_id == $noodlesId) {
    // Update solo/sharing prices, set price=NULL
    $stmt = $conn->prepare("UPDATE menu_items SET name=?, description=?, price_solo=?, price_sharing=?, category=?, image=?, stock=?, preferences=?, pronunciation_file=?, portion_size=? WHERE id=?");
    $stmt->bind_param("ssddisisssi", $name, $desc, $price_solo, $price_sharing, $cat_id, $newImage, $stock, $preferences, $newPronFile, $portion_size, $id);
} else {
    // Update regular price, solo/sharing=NULL
    $stmt = $conn->prepare("UPDATE menu_items SET name=?, description=?, price=?, category=?, image=?, stock=?, preferences=?, pronunciation_file=? WHERE id=?");
    $stmt->bind_param("ssdisissi", $name, $desc, $price, $cat_id, $newImage, $stock, $preferences, $newPronFile, $id);
}
$stmt->execute();
$stmt->close();

    // Redirect back to menu page
    $redirect = isset($_POST['redirect']) ? $_POST['redirect'] : 'index.php';
    header("Location: $redirect?msg=updated");
    exit;
}
?>