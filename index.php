<?php
/**
 * index.php — Redirect to login or dashboard.
 */
require_once __DIR__ . '/config/auth.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: /pages/dashboard.php');
} else {
    header('Location: /login.php');
}
exit;
