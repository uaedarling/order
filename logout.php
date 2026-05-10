<?php
/**
 * logout.php — Destroy session and redirect to login.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
session_unset();
session_regenerate_id(true);
session_destroy();
header('Location: /login.php');
exit;
