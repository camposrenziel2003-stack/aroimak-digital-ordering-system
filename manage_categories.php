<?php
session_start();
if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}
include "config.php";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $catId = intval($_POST['id']);
    $name = trim($_POST['name']);
    $imageName = '';

    // Fetch current image
    $result = $conn->query("SELECT image FROM categories WHERE id=$catId");
    $row = $result->fetch_assoc();
    $currentImage = $row['image'] ?? '';

    // Handle file upload if provided
    if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
        $allowedExt = ['jpg','jpeg','png','gif'];
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt)) {
            die("Invalid image type. Allowed: jpg, jpeg, png, gif");
        }

        // Unique filename
        $imageName = 'cat_'.$catId.'_'.time().'.'.$ext;
        $target = 'uploads/'.$imageName;

        if (move_uploaded_file($_FILES['image']['tmp_name'], $target)) {
            // Delete old image if exists
            if ($currentImage && file_exists('uploads/'.$currentImage)) {
                unlink('uploads/'.$currentImage);
            }
        } else {
            die("Failed to upload new image.");
        }
    } else {
        // Keep current image if no new image uploaded
        $imageName = $currentImage;
    }

    // Update database
    $stmt = $conn->prepare("UPDATE categories SET name=?, image=? WHERE id=?");
    $stmt->bind_param("ssi", $name, $imageName, $catId);
    if ($stmt->execute()) {
        // Redirect back to menu.php
        header("Location: menu.php");
        exit;
    } else {
        die("Failed to update category: ".$conn->error);
    }
} else {
    die("Invalid request method.");
}
?>
