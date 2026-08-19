<?php
require dirname(__DIR__, 2) . '/config.php';

$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

// Start a fresh session just for the flash message
session_start();
flash('info', 'You have been logged out successfully.');
redirect('/auth/login.php');
