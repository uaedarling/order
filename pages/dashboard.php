<?php
/**
 * pages/dashboard.php — Order list with sidebar filters.
 */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
requireLogin();

$pdo    = getPDO();
$user   = currentUser();
$filter = $_GET['filter'] ?? 'all';

// Build WHERE clause
$params = [];
$where  = '';

if ($filter === 'pending') {
    if (isAdmin()) {
        $where = "WHERE o.status IN ('Requested','Ordered','Ship-Out Requested')";
    } else {
        $where = "WHERE o.status IN ('Draft','In Transit (USA)','At Forwarder') AND o.created_by = ?";
        $params[] = $user['id'];
    }
} elseif ($filter === 'archive') {
    $where = "WHERE o.status IN ('Ordered','In Transit (USA)','At Forwarder','Ship-Out Requested')";
} elseif ($filter === 'ship_out') {
    $where = "WHERE o.status = 'Ship-Out Requested'";
} elseif (!isAdmin()) {
    // Employees only see their own orders by default
    $where = "WHERE o.created_by = ?";
    $params[] = $user['id'];
}

$orders = $pdo->prepare("
    SELECT o.*, b.name AS brand_name, u.username AS created_by_name
    FROM orders o
    LEFT JOIN brands b ON b.id = o.brand_id
    LEFT JOIN users  u ON u.id = o.created_by
    $where
    ORDER BY o.updated_at DESC
");
$orders->execute($params);
$orders = $orders->fetchAll();

// Status badge colours
function statusBadge(string $status): string
{
    $map = [
        'Draft'               => 'bg-gray-100 text-gray-700',
        'Requested'           => 'bg-yellow-100 text-yellow-800',
        'Ordered'             => 'bg-blue-100 text-blue-800',
        'In Transit (USA)'    => 'bg-orange-100 text-orange-800',
        'At Forwarder'        => 'bg-purple-100 text-purple-800',
        'Ship-Out Requested'  => 'bg-red-100 text-red-800',
    ];
    $cls = $map[$status] ?? 'bg-gray-100 text-gray-700';
    return "<span class=\"inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium $cls\">"
         . htmlspecialchars($status) . "</span>";
}

$pageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex gap-6">
  <!-- Sidebar filter -->
  <aside class="hidden sm:block w-44 flex-shrink-0">
    <nav class="space-y-1">
      <?php
      $links = [
        ['filter' => 'all',     'icon' => 'list',        'label' => 'All Orders'],
        ['filter' => 'pending', 'icon' => 'clock',       'label' => 'Pending Action'],
        ['filter' => 'archive', 'icon' => 'archive',     'label' => 'Archive'],
      ];
      foreach ($links as $l):
        $active = $filter === $l['filter'];
        $cls = $active
          ? 'flex items-center gap-2 px-3 py-2 rounded-lg bg-indigo-50 text-indigo-700 font-medium text-sm'
          : 'flex items-center gap-2 px-3 py-2 rounded-lg text-gray-600 hover:bg-gray-100 text-sm';
      ?>
      <a href="?filter=<?= $l['filter'] ?>" class="<?= $cls ?>">
        <i data-lucide="<?= $l['icon'] ?>" class="w-4 h-4"></i>
        <?= $l['label'] ?>
      </a>
      <?php endforeach; ?>
    </nav>
  </aside>

  <!-- Table -->
  <div class="flex-1 min-w-0">
    <!-- Mobile filter pills -->
    <div class="flex gap-2 mb-4 sm:hidden overflow-x-auto pb-1">
      <a href="?filter=all"     class="px-3 py-1 rounded-full text-sm <?= $filter==='all'     ? 'bg-indigo-600 text-white' : 'bg-white border text-gray-600' ?>">All</a>
      <a href="?filter=pending" class="px-3 py-1 rounded-full text-sm <?= $filter==='pending' ? 'bg-indigo-600 text-white' : 'bg-white border text-gray-600' ?>">Pending</a>
      <a href="?filter=archive" class="px-3 py-1 rounded-full text-sm <?= $filter==='archive' ? 'bg-indigo-600 text-white' : 'bg-white border text-gray-600' ?>">Archive</a>
    </div>

    <div class="flex items-center justify-between mb-4">
      <p class="text-sm text-gray-500"><?= count($orders) ?> order<?= count($orders) !== 1 ? 's' : '' ?></p>
      <a href="/pages/new_order.php"
         class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg transition-colors">
        <i data-lucide="plus" class="w-4 h-4"></i> New Order
      </a>
    </div>

    <?php if (empty($orders)): ?>
    <div class="text-center py-16 text-gray-400">
      <i data-lucide="inbox" class="w-12 h-12 mx-auto mb-3"></i>
      <p class="text-lg">No orders found</p>
      <a href="/pages/new_order.php" class="mt-3 inline-block text-indigo-600 hover:underline text-sm">Create your first order →</a>
    </div>
    <?php else: ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-4 py-3 text-left font-semibold text-gray-600">#</th>
              <th class="px-4 py-3 text-left font-semibold text-gray-600">Brand</th>
              <th class="px-4 py-3 text-left font-semibold text-gray-600">Product</th>
              <th class="px-4 py-3 text-left font-semibold text-gray-600">Carrier</th>
              <th class="px-4 py-3 text-right font-semibold text-gray-600">Total AED</th>
              <th class="px-4 py-3 text-left font-semibold text-gray-600">Status</th>
              <th class="px-4 py-3 text-left font-semibold text-gray-600">Date</th>
              <th class="px-4 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <?php foreach ($orders as $o): ?>
            <tr class="hover:bg-gray-50 transition-colors">
              <td class="px-4 py-3 font-mono text-gray-500"><?= (int)$o['id'] ?></td>
              <td class="px-4 py-3 font-medium text-gray-800"><?= htmlspecialchars($o['brand_name'] ?? '—') ?></td>
              <td class="px-4 py-3 text-gray-600 max-w-xs truncate"><?= htmlspecialchars($o['product_name'] ?? '—') ?></td>
              <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($o['carrier']) ?></td>
              <td class="px-4 py-3 text-right font-semibold text-gray-800"><?= number_format((float)$o['total_aed'], 2) ?></td>
              <td class="px-4 py-3"><?= statusBadge($o['status']) ?></td>
              <td class="px-4 py-3 text-gray-500 whitespace-nowrap"><?= date('d M Y', strtotime($o['created_at'])) ?></td>
              <td class="px-4 py-3">
                <a href="/pages/order_detail.php?id=<?= (int)$o['id'] ?>"
                   class="text-indigo-600 hover:text-indigo-800 font-medium">View</a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
