<?php
session_start();

$VALID_USER = 'ntamod';
$VALID_PASS = '2005';

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

if (!isset($_SESSION['auth_user'])) {
    header("Location: login.php");
    exit;
}
?>
