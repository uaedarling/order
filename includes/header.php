<?php
/**
 * includes/header.php — HTML head + Tailwind CDN + Lucide CDN + sidebar nav.
 * Expects $pageTitle to be set before including.
 */

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
$user  = currentUser();
$flash = getFlash();

// Current page for active nav highlight
$currentPage = basename($_SERVER['PHP_SELF']);
$currentDir  = basename(dirname($_SERVER['PHP_SELF']));

function navLink(string $href, string $icon, string $label, bool $active): string
{
    $base   = $active
        ? 'flex items-center gap-3 px-4 py-2.5 rounded-lg bg-indigo-700 text-white font-medium'
        : 'flex items-center gap-3 px-4 py-2.5 rounded-lg text-indigo-100 hover:bg-indigo-700 hover:text-white transition-colors';
    return "<a href=\"$href\" class=\"$base\"><i data-lucide=\"$icon\" class=\"w-4 h-4\"></i>$label</a>";
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle ?? 'ERP System') ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/lucide@latest"></script>
<style>
  [x-cloak] { display: none !important; }
  .sidebar-transition { transition: transform 0.25s ease; }
</style>
</head>
<body class="h-full bg-gray-50 font-sans">

<div class="flex h-full min-h-screen">

  <aside id="sidebar"
         class="sidebar-transition fixed inset-y-0 left-0 z-40 w-64 bg-indigo-800 flex flex-col
                -translate-x-full lg:translate-x-0 lg:static lg:inset-auto">

    <div class="flex items-center gap-2 h-16 px-6 border-b border-indigo-700">
      <i data-lucide="package" class="w-6 h-6 text-indigo-200"></i>
      <span class="text-white font-bold text-lg tracking-tight">ProcureERP</span>
    </div>

    <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-1">
      <?= navLink(app_url('pages/dashboard.php'), 'layout-dashboard', 'Dashboard',
            $currentPage === 'dashboard.php') ?>
      <?= navLink(app_url('pages/new_order.php'), 'plus-circle', 'New Order',
            $currentPage === 'new_order.php') ?>
      <?= navLink(app_url('pages/estimator.php'), 'calculator', 'Estimator',
            $currentPage === 'estimator.php') ?>
      <?php if (isAdmin()): ?>
      <div class="pt-3 pb-1 px-4 text-xs font-semibold uppercase tracking-wider text-indigo-400">Admin</div>
      <?= navLink(app_url('pages/brands.php'), 'building-2', 'Brands',
            $currentPage === 'brands.php') ?>
      <?= navLink(app_url('pages/settings.php'), 'settings', 'Settings',
            $currentPage === 'settings.php') ?>
      <?php endif; ?>
    </nav>

    <div class="px-4 py-4 border-t border-indigo-700">
      <div class="flex items-center gap-3">
        <div class="w-8 h-8 rounded-full bg-indigo-600 flex items-center justify-center">
          <i data-lucide="user" class="w-4 h-4 text-indigo-200"></i>
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium text-white truncate"><?= htmlspecialchars($user['username']) ?></p>
          <p class="text-xs text-indigo-300 capitalize"><?= htmlspecialchars($user['role']) ?></p>
        </div>
        <a href="<?= htmlspecialchars(app_url('logout.php')) ?>" title="Sign out">
          <i data-lucide="log-out" class="w-4 h-4 text-indigo-300 hover:text-white transition-colors"></i>
        </a>
      </div>
    </div>
  </aside>

  <div id="sidebar-overlay"
       class="fixed inset-0 z-30 bg-black/40 hidden lg:hidden"
       onclick="toggleSidebar()"></div>

  <div class="flex-1 flex flex-col min-w-0">

    <header class="h-16 bg-white border-b border-gray-200 flex items-center gap-4 px-4 lg:px-6 flex-shrink-0">
      <button onclick="toggleSidebar()" class="lg:hidden p-2 rounded-md text-gray-500 hover:bg-gray-100">
        <i data-lucide="menu" class="w-5 h-5"></i>
      </button>
      <h1 class="text-lg font-semibold text-gray-800 flex-1"><?= htmlspecialchars($pageTitle ?? '') ?></h1>
      <?php if (isAdmin()): ?>
      <?php
        try {
            $pdo  = getPDO();
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE status = ?");
            $stmt->execute(['Ship-Out Requested']);
            $n = $stmt->fetchColumn();
        } catch (Throwable) { $n = 0; }
        if ($n > 0):
      ?>
      <a href="<?= htmlspecialchars(app_url('pages/dashboard.php?filter=ship_out')) ?>" class="flex items-center gap-1 px-2 py-1 bg-red-100 text-red-700 rounded-full text-xs font-semibold">
        <i data-lucide="bell" class="w-3 h-3"></i> <?= (int)$n ?> Ship-Out
      </a>
      <?php endif; endif; ?>
    </header>

    <?php if ($flash): ?>
    <?php $c = $flash['type'] === 'success'
          ? 'bg-green-50 border-green-400 text-green-800'
          : 'bg-red-50 border-red-400 text-red-800'; ?>
    <div class="mx-4 lg:mx-6 mt-4 px-4 py-3 border-l-4 rounded <?= $c ?> flex items-start gap-2">
      <i data-lucide="<?= $flash['type'] === 'success' ? 'check-circle' : 'alert-circle' ?>" class="w-5 h-5 flex-shrink-0 mt-0.5"></i>
      <p><?= htmlspecialchars($flash['message']) ?></p>
    </div>
    <?php endif; ?>

    <main class="flex-1 p-4 lg:p-6 overflow-auto">
