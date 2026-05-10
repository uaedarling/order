<?php
/**
 * login.php — Login form.
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/config/db.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . app_url('pages/dashboard.php'));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter username and password.';
    } else {
        try {
            $pdo  = getPDO();
            $stmt = $pdo->prepare('SELECT id, username, password_hash, role FROM users WHERE username = ?');
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user_id']  = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role']     = $user['role'];
                header('Location: ' . app_url('pages/dashboard.php'));
                exit;
            } else {
                $error = 'Invalid username or password.';
            }
        } catch (PDOException $e) {
            $error = 'Database error. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login — ProcureERP</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="h-full bg-gradient-to-br from-indigo-900 to-indigo-700 flex items-center justify-center p-4">

<div class="w-full max-w-md">
  <div class="text-center mb-8">
    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-white/10 mb-4">
      <i data-lucide="package" class="w-8 h-8 text-white"></i>
    </div>
    <h1 class="text-3xl font-bold text-white">ProcureERP</h1>
    <p class="text-indigo-300 mt-1">Procurement &amp; Logistics</p>
  </div>

  <div class="bg-white rounded-2xl shadow-xl p-8">
    <h2 class="text-xl font-semibold text-gray-800 mb-6">Sign in to your account</h2>

    <?php if ($error): ?>
    <div class="mb-4 px-4 py-3 bg-red-50 border border-red-200 text-red-700 rounded-lg flex items-center gap-2">
      <i data-lucide="alert-circle" class="w-4 h-4 flex-shrink-0"></i>
      <span><?= htmlspecialchars($error) ?></span>
    </div>
    <?php endif; ?>

    <form method="POST" action="<?= htmlspecialchars(app_url('login.php')) ?>" class="space-y-5">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1" for="username">Username</label>
        <div class="relative">
          <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
            <i data-lucide="user" class="w-4 h-4 text-gray-400"></i>
          </div>
          <input type="text" id="username" name="username"
                 value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                 class="block w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                 placeholder="Enter username" autofocus autocomplete="username" required>
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1" for="password">Password</label>
        <div class="relative">
          <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
            <i data-lucide="lock" class="w-4 h-4 text-gray-400"></i>
          </div>
          <input type="password" id="password" name="password"
                 class="block w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                 placeholder="Enter password" autocomplete="current-password" required>
        </div>
      </div>

      <button type="submit"
              class="w-full py-2.5 px-4 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-lg transition-colors">
        Sign In
      </button>
    </form>
  </div>
</div>

<script>lucide.createIcons();</script>
</body>
</html>
