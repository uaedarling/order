<?php
/**
 * config/auth.php — Session helpers.
 */

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
    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        include __DIR__ . '/../includes/header.php';
        echo '<div class="flex items-center justify-center h-64">
              <div class="text-center">
                <p class="text-5xl font-bold text-red-600">403</p>
                <p class="mt-2 text-xl text-gray-600">Access Denied</p>
                <a href="/pages/dashboard.php" class="mt-4 inline-block px-4 py-2 bg-gray-600 text-white rounded">Go Back</a>
              </div></div>';
        include __DIR__ . '/../includes/footer.php';
        exit;
    }
}

function currentUser(): array
{
    return [
        'id'       => $_SESSION['user_id'] ?? 0,
        'username' => $_SESSION['username'] ?? '',
        'role'     => $_SESSION['role'] ?? 'employee',
    ];
}

function isAdmin(): bool
{
    return ($_SESSION['role'] ?? '') === 'admin';
}

function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array
{
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}
