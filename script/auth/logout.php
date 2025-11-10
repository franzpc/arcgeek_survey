<?php
define('ARCGEEK_SURVEY', true);
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../');
    exit();
}

if (isset($_SESSION['user_id'])) {
    $user_email = $_SESSION['user_email'] ?? 'unknown';
    error_log("User logged out: " . $user_email);
}

$_SESSION = array();

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

header('Location: ../');
exit();
?>