<?php
/**
 * logout.php — Destroy session and redirect to login.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION = [];
session_destroy();
header('Location: /login.php');
exit;
