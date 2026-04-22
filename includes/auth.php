<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function requireLogin(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: /login.php');
        exit;
    }
}

function requireAdmin(): void
{
    requireLogin();
    if ($_SESSION['role'] !== 'admin') {
        http_response_code(403);
        die('<!DOCTYPE html><html><head><title>Access Denied</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head><body class="d-flex align-items-center justify-content-center vh-100">
<div class="text-center"><h2 class="text-danger">403 — Access Denied</h2>
<a href="/index.php" class="btn btn-secondary mt-3">Go Back</a></div></body></html>');
    }
}

function isAdmin(): bool
{
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}
