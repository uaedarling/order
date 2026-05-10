<?php
/**
 * install.php — One-time setup: creates tables and default admin user.
 * Visit this page once, then delete or restrict it.
 */

// ── Safety: block re-run if lock file exists ──────────────────────────────
$lockFile = __DIR__ . '/install.lock';
if (file_exists($lockFile)) {
    die('<h2 style="font-family:sans-serif;color:#b91c1c">Already installed.<br>Delete <code>install.lock</code> to re-run.</h2>');
}

$host = $_ENV['DB_HOST'] ?? 'localhost';
$db   = $_ENV['DB_NAME'] ?? 'order_erp';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASS'] ?? '';

try {
    // Connect without selecting a database first so we can create it
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$db`");

    // Run schema
    $sql = file_get_contents(__DIR__ . '/schema.sql');
    $pdo->exec($sql);

    // Create default admin (password: admin123)
    $hash = password_hash('admin123', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES ('admin', ?, 'admin')
                           ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = 'admin'");
    $stmt->execute([$hash]);

    // Create lock file
    file_put_contents($lockFile, date('Y-m-d H:i:s'));

    echo '<!DOCTYPE html><html><head><meta charset="utf-8">
<title>Install Complete</title>
<style>body{font-family:sans-serif;padding:2rem;max-width:600px;margin:auto}
.ok{color:#16a34a}.warn{color:#b45309;background:#fef9c3;padding:1rem;border-radius:.5rem;margin-top:1rem}
code{background:#f3f4f6;padding:.1rem .3rem;border-radius:.25rem}</style></head><body>
<h1 class="ok">✅ Installation Complete</h1>
<p>Database <strong>' . htmlspecialchars($db) . '</strong> created and schema applied.</p>
<p>Default admin credentials:<br>
   Username: <code>admin</code><br>
   Password: <code>admin123</code></p>
<div class="warn">⚠️ <strong>Security warning:</strong> Change the admin password immediately after logging in.
An <code>install.lock</code> file has been created to prevent re-installation.</div>
<p><a href="/login.php">→ Go to Login</a></p>
</body></html>';
} catch (PDOException $e) {
    http_response_code(500);
    echo '<h2 style="font-family:sans-serif;color:#b91c1c">Installation failed</h2><pre>'
        . htmlspecialchars($e->getMessage()) . '</pre>';
}
