<?php
// logout.php - Abmeldung und Löschung des Remember-Tokens
require_once __DIR__ . '/config.php';

if (is_logged_in()) {
    log_activity('Abmeldung', 'Benutzer hat sich aktiv abgemeldet');
}

clear_remember_token();

$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();
header('Location: login.php');
exit;
