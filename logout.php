<?php
//
// logout.php
// This script handles the user logout process by destroying the session.
//

// Start the session to be able to access session variables.
session_start();

// Unset all of the session variables.
$_SESSION = array();

// Destroy the session.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

// Redirect to the login page.
header("Location: index.php?page=login");
exit;
?>
