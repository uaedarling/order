<?php
/**
 * pages/pipeline.php — Visual order pipeline (Kanban board).
 */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
requireLogin();

$pdo  = getPDO();
$user = currentUser();

$stages = [
    ['status' => 'Draft',                'label' => 'Draft',                'header' => 'bg-gray-100 text-gray-700 border-gray-200'],
    ['status' => 'Requested',            'label' => 'Requested',            'header' => 'bg-yellow-100 text-yellow-800 border-yellow-200'],
    ['status' => 'Email Sent to Dealer', 'label' => 'Email Sent to Dealer', 'header' => 'bg-teal-100 text-teal-800 border-teal-200'],
    ['status' => 'Payment Done',         'label' => 'Payment Done',         'header' => 'bg-green-100 text-green-800 border-green-200'],
    ['status' => 'In Transit (USA)',     'label' => 'In Transit USA',       'header' => 'bg-orange-100 text-orange-800 border-orange-200'],
    ['status' => 'At Forwarder',         'label' => 'At Forwarder',         'header' => 'bg-purple-100 text-purple-800 border-purple-200'],
    ['status' => 'Ship-Out Requested',   'label' => 'Ship-Out Requested',   'header' => 'bg-red-100 text-red-800 border-red-200'],
];

$params = [];
if (isAdmin()) {
    $stmt = $pdo->prepare("
        SELECT o.id, o.status, o.product_name, o.total_aed, b.name AS brand_name
        FROM orders o
        LEFT JOIN brands b ON b.id = o.brand_id
        ORDER BY o.updated_at DESC
    ");
} else {
    $stmt = $pdo->prepare("
        SELECT o.id, o.status, o.product_name, o.total_aed, b.name AS brand_name
        FROM orders o
        LEFT JOIN brands b ON b.id = o.brand_id
        WHERE o.created_by = ?
        ORDER BY o.updated_at DESC
    ");
    $params[] = $user['id'];
}
$stmt->execute($params);
$orders = $stmt->fetchAll();

$ordersByStage = [];
foreach ($stages as $stage) {
    $ordersByStage[$stage['status']] = [];
}
foreach ($orders as $order) {
    if (isset($ordersByStage[$order['status']])) {
        $ordersByStage[$order['status']][] = $order;
    }
}

$pageTitle = 'Pipeline';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-4">
  <div class="flex items-center justify-between gap-3">
    <p class="text-sm text-gray-500">
      Visual pipeline view of <?= count($orders) ?> order<?= count($orders) !== 1 ? 's' : '' ?>
    </p>
    <a href="<?= app_url('pages/new_order.php') ?>"
       class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg transition-colors">
      <i data-lucide="plus" class="w-4 h-4"></i> New Order
    </a>
  </div>

  <div class="flex flex-col lg:flex-row lg:overflow-x-auto gap-4 pb-2">
    <?php foreach ($stages as $stage): ?>
    <?php $stageOrders = $ordersByStage[$stage['status']]; ?>
    <section class="bg-white rounded-xl border border-gray-200 shadow-sm lg:min-w-[18rem] lg:w-[18rem] flex-shrink-0">
      <div class="px-4 py-3 border-b rounded-t-xl <?= $stage['header'] ?>">
        <div class="flex items-center justify-between gap-2">
          <h2 class="font-semibold text-sm"><?= htmlspecialchars($stage['label']) ?></h2>
          <span class="inline-flex items-center justify-center min-w-6 h-6 px-2 rounded-full text-xs font-semibold bg-white/70">
            <?= count($stageOrders) ?>
          </span>
        </div>
      </div>

      <div class="p-3 space-y-3 max-h-[60vh] overflow-y-auto">
        <?php if (empty($stageOrders)): ?>
        <div class="text-xs text-gray-400 border border-dashed border-gray-200 rounded-lg p-3 text-center">
          No orders
        </div>
        <?php else: ?>
        <?php foreach ($stageOrders as $o): ?>
        <article class="border border-gray-200 rounded-lg p-3 bg-gray-50/60">
          <p class="text-xs font-mono text-gray-500 mb-1">#<?= (int)$o['id'] ?></p>
          <p class="text-sm font-semibold text-gray-800 truncate"><?= htmlspecialchars($o['brand_name'] ?? '—') ?></p>
          <p class="text-sm text-gray-600 truncate"><?= htmlspecialchars($o['product_name'] ?: '—') ?></p>
          <div class="mt-2 flex items-center justify-between gap-2">
            <p class="text-xs font-semibold text-gray-700">AED <?= number_format((float)$o['total_aed'], 2) ?></p>
            <a href="<?= app_url('pages/order_detail.php') ?>?id=<?= (int)$o['id'] ?>"
               class="text-xs font-medium text-indigo-600 hover:text-indigo-800">View →</a>
          </div>
        </article>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </section>
    <?php endforeach; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
