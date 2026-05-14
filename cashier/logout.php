<?php
session_start();
session_unset();
session_destroy();

// redirect back to login modal
header("Location: index.php");
exit;
?>
