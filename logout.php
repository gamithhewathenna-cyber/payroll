<?php
require_once 'config.php';
// Clear remember me cookie and token
if (isset($_COOKIE['remember_token'])) {
    $db = getDB();
    if (isset($_SESSION['user_id'])) {
        $db->prepare("UPDATE users SET remember_token=NULL, token_expires=NULL WHERE id=?")->execute([$_SESSION['user_id']]);
    }
    setcookie('remember_token', '', time()-3600, '/');
}
session_destroy();
header('Location: ' . SITE_URL . '/index.php');
exit;
