<?php
/**
 * index.php — Redirect to login or dashboard.
 */
require_once __DIR__ . '/config/db.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . app_url('pages/dashboard.php'));
} else {
    header('Location: ' . app_url('login.php'));
}
exit;
