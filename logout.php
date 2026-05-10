<?php
/**
 * logout.php — Destroy session and redirect to login.
 */
require_once __DIR__ . '/config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
session_unset();
session_regenerate_id(true);
session_destroy();
header('Location: ' . app_url('login.php'));
exit;
