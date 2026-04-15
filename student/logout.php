<?php
session_start();

$_SESSION = [];
session_unset();

$cookieOptions = [
    'expires' => time() - 3600,
    'path' => '/',
    'domain' => '',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax'
];

setcookie(session_name(), '', $cookieOptions);
setcookie('rserves_remember_email', '', $cookieOptions);

session_destroy();

header("Location: ../home2.php");
exit;
?>
