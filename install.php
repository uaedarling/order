<?php
/**
 * install.php — One-time installer.
 * Creates the database, tables, seeds brands, and creates default users.
 * Also writes config/db.php with the supplied credentials.
 */

$message    = '';
$msgType    = '';
$installed  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbName = trim($_POST['db_name'] ?? 'order_management');
    $dbUser = trim($_POST['db_user'] ?? 'root');
    $dbPass = $_POST['db_pass'] ?? '';
    $reseed = !empty($_POST['reseed']);

    try {
        // Connect without specifying a database first
        $dsn = "mysql:host=$dbHost;charset=utf8mb4";
        $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        // Create DB
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `$dbName`");

        // Check if already installed
        $exists = $pdo->query("SHOW TABLES LIKE 'orders'")->fetch();

        if ($exists && !$reseed) {
            $installed = true;
            $msgType   = 'warning';
            $message   = 'Tables already exist. Check the box below and re-submit to re-seed data.';
        } else {
            // Create tables
            $pdo->exec("CREATE TABLE IF NOT EXISTS brands (
                id               INT AUTO_INCREMENT PRIMARY KEY,
                name             VARCHAR(255) NOT NULL,
                discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0
            )");

            $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
                id           INT AUTO_INCREMENT PRIMARY KEY,
                brand_id     INT NOT NULL,
                part_number  VARCHAR(255) NOT NULL,
                link         TEXT,
                price_usd    DECIMAL(10,2) NOT NULL,
                weight       DECIMAL(8,3) NOT NULL,
                l            DECIMAL(8,2) NOT NULL,
                w            DECIMAL(8,2) NOT NULL,
                h            DECIMAL(8,2) NOT NULL,
                cost_aed     DECIMAL(10,2),
                agreed_price DECIMAL(10,2),
                po_path      VARCHAR(500),
                tracking_no  VARCHAR(255),
                status       ENUM('Pending','Ordered') NOT NULL DEFAULT 'Pending',
                created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (brand_id) REFERENCES brands(id)
            )");

            $pdo->exec("CREATE TABLE IF NOT EXISTS users (
                id            INT AUTO_INCREMENT PRIMARY KEY,
                username      VARCHAR(100) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                role          ENUM('admin','employee') NOT NULL DEFAULT 'employee',
                created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
            )");

            // Seed brands (replace existing)
            $pdo->exec("DELETE FROM brands");
            $pdo->exec("ALTER TABLE brands AUTO_INCREMENT = 1");
            $brands = [
                ['Apple',   5.00],
                ['Samsung', 8.00],
                ['Sony',   10.00],
                ['LG',      7.50],
                ['Dell',    6.00],
            ];
            $stmt = $pdo->prepare("INSERT INTO brands (name, discount_percent) VALUES (?, ?)");
            foreach ($brands as [$name, $disc]) {
                $stmt->execute([$name, $disc]);
            }

            // Seed default users (upsert)
            $users = [
                ['admin',    'admin123',  'admin'],
                ['employee', 'emp123',    'employee'],
            ];
            $uStmt = $pdo->prepare("INSERT INTO users (username, password_hash, role)
                                    VALUES (?, ?, ?)
                                    ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = VALUES(role)");
            foreach ($users as [$uname, $upass, $urole]) {
                $uStmt->execute([$uname, password_hash($upass, PASSWORD_DEFAULT), $urole]);
            }

            // Write config/db.php using var_export for safe string escaping
            $configDir = __DIR__ . '/config';
            if (!is_dir($configDir)) {
                mkdir($configDir, 0755, true);
            }
            $hostExported = var_export($dbHost, true);
            $dbExported   = var_export($dbName, true);
            $userExported = var_export($dbUser, true);
            $passExported = var_export($dbPass, true);
            $configContent = <<<PHP
<?php
\$host = $hostExported;
\$db   = $dbExported;
\$user = $userExported;
\$pass = $passExported;

try {
    \$pdo = new PDO("mysql:host={\$host};dbname={\$db};charset=utf8mb4", \$user, \$pass);
    \$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    \$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException \$e) {
    die('Database connection failed: ' . \$e->getMessage());
}
PHP;
            file_put_contents($configDir . '/db.php', $configContent);

            $installed = true;
            $msgType   = 'success';
            $message   = 'Installation complete! Database, tables, and default users have been created.';
        }
    } catch (PDOException $e) {
        $msgType = 'danger';
        $message = 'Error: ' . htmlspecialchars($e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Installer — Special Order Management System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <style>
        body { background: #f0f2f5; }
        .installer-card { max-width: 560px; margin: 60px auto; }
    </style>
</head>
<body>
<div class="container">
    <div class="installer-card">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white text-center py-3">
                <h4 class="mb-0">⚙️ System Installer</h4>
                <small>Special Order Management System</small>
            </div>
            <div class="card-body p-4">

                <?php if ($message): ?>
                    <div class="alert alert-<?= htmlspecialchars($msgType) ?> alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($installed && $msgType === 'success'): ?>
                    <div class="alert alert-info">
                        <strong>Default Credentials:</strong><br>
                        Admin &mdash; username: <code>admin</code> / password: <code>admin123</code><br>
                        Employee &mdash; username: <code>employee</code> / password: <code>emp123</code>
                    </div>
                    <div class="d-grid mt-3">
                        <a href="/login.php" class="btn btn-success btn-lg">🔐 Go to Login</a>
                    </div>
                <?php endif; ?>

                <?php if (!$installed || $msgType !== 'success'): ?>
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Database Host</label>
                        <input type="text" name="db_host" class="form-control"
                               value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Database Name</label>
                        <input type="text" name="db_name" class="form-control"
                               value="<?= htmlspecialchars($_POST['db_name'] ?? 'order_management') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Database User</label>
                        <input type="text" name="db_user" class="form-control"
                               value="<?= htmlspecialchars($_POST['db_user'] ?? 'root') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Database Password</label>
                        <input type="password" name="db_pass" class="form-control"
                               value="<?= htmlspecialchars($_POST['db_pass'] ?? '') ?>">
                    </div>

                    <?php if ($msgType === 'warning'): ?>
                    <div class="mb-3 form-check">
                        <input class="form-check-input" type="checkbox" name="reseed" id="reseed" value="1">
                        <label class="form-check-label" for="reseed">
                            Re-seed brands and default users (will overwrite existing seed data)
                        </label>
                    </div>
                    <?php endif; ?>

                    <div class="d-grid mt-4">
                        <button type="submit" class="btn btn-primary btn-lg">
                            🚀 Run Installer
                        </button>
                    </div>
                </form>
                <?php endif; ?>

            </div>
        </div>
        <p class="text-center text-muted small mt-3">Run this once. Remove or restrict access after installation.</p>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
