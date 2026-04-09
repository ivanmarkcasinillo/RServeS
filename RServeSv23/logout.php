<?php
session_start();

// Clear session
session_unset();
session_destroy();

// Redirect back to login page
header("Location: home2.php");
exit;
?>
