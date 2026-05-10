<?php
/**
 * config/db.php — PDO connection singleton.
 * Uses environment variables when available, with support for .env-style fallback.
 */

function loadEnvFile(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }

        [$name, $value] = array_map('trim', explode('=', $line, 2));
        $value = trim($value, "\"'");

        if ($name !== '' && getenv($name) === false && !array_key_exists($name, $_ENV)) {
            $_ENV[$name] = $value;
            putenv($name . '=' . $value);
        }
    }
}

loadEnvFile(__DIR__ . '/../.env');

function env(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return (string)$value;
}

function getAppBaseUrl(): string
{
    static $base = null;
    if ($base !== null) return $base;

    // config/db.php lives at [app_root]/config/db.php, so dirname(__DIR__) is the app root on disk.
    $appRootFs = str_replace('\\', '/', (string)(realpath(dirname(__DIR__)) ?: dirname(__DIR__)));
    $scriptFs  = str_replace('\\', '/', (string)(realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: ($_SERVER['SCRIPT_FILENAME'] ?? '')));
    $scriptUrl = str_replace('\\', '/', $_SERVER['SCRIPT_NAME']     ?? '');

    if ($scriptFs !== '' && $scriptUrl !== '' && str_starts_with($scriptFs, $appRootFs . '/')) {
        // Path of the script relative to the app root (e.g. "pages/dashboard.php")
        $relScript = ltrim(substr($scriptFs, strlen($appRootFs)), '/');
        // Strip that suffix from the URL to obtain the app base URL
        $base = rtrim(substr($scriptUrl, 0, strlen($scriptUrl) - strlen($relScript)), '/');
    } else {
        // Fallback: use the script's URL directory
        $base = rtrim(str_replace('\\', '/', dirname($scriptUrl)), '/');
        if ($base === '/') $base = '';
    }

    return $base;
}

function app_url(string $path = ''): string
{
    $base = getAppBaseUrl();
    $path = '/' . ltrim($path, '/');
    return $base . $path;
}

function getPDO(): PDO
{
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $host = env('DB_HOST', 'localhost');
    $db   = env('DB_NAME', 'order_erp');
    $user = env('DB_USER');
    $pass = env('DB_PASS', '');

    if (!$user) {
        throw new RuntimeException('Database credentials are missing. Set DB_USER (and ideally DB_HOST, DB_NAME, DB_PASS) in .env or server config.');
    }

    $pdo = new PDO(
        "mysql:host=$host;dbname=$db;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    return $pdo;
}
