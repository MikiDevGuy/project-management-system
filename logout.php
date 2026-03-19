<?php
session_start();
session_unset(); // Clear all session variables
session_destroy(); // End the session

// Delete cookies
setcookie('user_id', '', time() - 10);
setcookie('username', '', time() - 10);
setcookie('system_role', '', time() - 10);

// Redirect to login page
header("Location: login.php");
exit;
?>