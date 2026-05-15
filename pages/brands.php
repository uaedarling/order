<?php
/**
 * pages/brands.php — Admin: CRUD for brands/discounts.
 */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
requireAdmin();

$pdo = getPDO();

// ── Actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name        = trim($_POST['name'] ?? '');
        $dealerEmail = trim($_POST['dealer_email'] ?? '');
        $discount    = (float)($_POST['discount_percent'] ?? 0);
        if ($name === '') {
            setFlash('error', 'Brand name is required.');
        } elseif ($discount < 0 || $discount > 100) {
            setFlash('error', 'Discount must be between 0 and 100.');
        } else {
            $pdo->prepare('INSERT INTO brands (name, dealer_email, discount_percent) VALUES (?, ?, ?)')
                ->execute([$name, $dealerEmail ?: null, $discount]);
            setFlash('success', "Brand \"$name\" added.");
        }

    } elseif ($action === 'edit') {
        $bid         = (int)($_POST['id'] ?? 0);
        $name        = trim($_POST['name'] ?? '');
        $dealerEmail = trim($_POST['dealer_email'] ?? '');
        $discount    = (float)($_POST['discount_percent'] ?? 0);
        if ($name === '' || $bid <= 0) {
            setFlash('error', 'Invalid input.');
        } else {
            $pdo->prepare('UPDATE brands SET name=?, dealer_email=?, discount_percent=? WHERE id=?')
                ->execute([$name, $dealerEmail ?: null, $discount, $bid]);
            setFlash('success', 'Brand updated.');
        }

    } elseif ($action === 'delete') {
        $bid = (int)($_POST['id'] ?? 0);
        // Check if brand has orders
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE brand_id=?');
        $cnt->execute([$bid]);
        if ((int)$cnt->fetchColumn() > 0) {
            setFlash('error', 'Cannot delete brand with existing orders.');
        } else {
            $pdo->prepare('DELETE FROM brands WHERE id=?')->execute([$bid]);
            setFlash('success', 'Brand deleted.');
        }
    }

    header('Location: ' . app_url('pages/brands.php'));
    exit;
}

$brands = $pdo->query('SELECT * FROM brands ORDER BY name')->fetchAll();

$pageTitle = 'Brands';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-3xl mx-auto space-y-6">

  <!-- Add brand -->
  <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
    <h3 class="font-semibold text-gray-700 mb-4 flex items-center gap-2">
      <i data-lucide="plus-circle" class="w-5 h-5 text-indigo-600"></i>
      Add Brand
    </h3>
    <form method="POST" class="flex flex-wrap gap-3 items-end">
      <input type="hidden" name="action" value="add">
      <div class="flex-1 min-w-[180px]">
        <label class="block text-sm font-medium text-gray-700 mb-1">Brand Name *</label>
        <input type="text" name="name" required
               class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
               placeholder="e.g. Apple">
      </div>
      <div class="flex-1 min-w-[180px]">
        <label class="block text-sm font-medium text-gray-700 mb-1">Dealer Email</label>
        <input type="email" name="dealer_email"
               class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
               placeholder="e.g. dealer@apple.com">
      </div>
      <div class="w-36">
        <label class="block text-sm font-medium text-gray-700 mb-1">Discount %</label>
        <input type="number" name="discount_percent" step="0.01" min="0" max="100" value="0"
               class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none">
      </div>
      <button type="submit"
              class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-lg transition-colors">
        Add
      </button>
    </form>
  </div>

  <!-- Brands table -->
  <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-100">
      <h3 class="font-semibold text-gray-700">All Brands (<?= count($brands) ?>)</h3>
    </div>

    <?php if (empty($brands)): ?>
    <div class="text-center py-10 text-gray-400">
      <i data-lucide="building-2" class="w-10 h-10 mx-auto mb-2"></i>
      <p>No brands yet. Add one above.</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
      <table class="min-w-full divide-y divide-gray-100 text-sm">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-6 py-3 text-left font-semibold text-gray-600">#</th>
            <th class="px-6 py-3 text-left font-semibold text-gray-600">Name</th>
            <th class="px-6 py-3 text-left font-semibold text-gray-600">Dealer Email</th>
            <th class="px-6 py-3 text-left font-semibold text-gray-600">Discount %</th>
            <th class="px-6 py-3 text-left font-semibold text-gray-600">Created</th>
            <th class="px-6 py-3"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($brands as $b): ?>
          <tr id="row-<?= (int)$b['id'] ?>" class="hover:bg-gray-50 transition-colors">
            <td class="px-6 py-3 text-gray-500"><?= (int)$b['id'] ?></td>
            <td class="px-6 py-3">
              <span id="name-display-<?= $b['id'] ?>" class="font-medium text-gray-800"><?= htmlspecialchars($b['name']) ?></span>
            </td>
            <td class="px-6 py-3 text-gray-600">
              <?= $b['dealer_email'] ? htmlspecialchars($b['dealer_email']) : '—' ?>
            </td>
            <td class="px-6 py-3">
              <span id="disc-display-<?= $b['id'] ?>"><?= number_format((float)$b['discount_percent'], 2) ?>%</span>
            </td>
            <td class="px-6 py-3 text-gray-500"><?= date('d M Y', strtotime($b['created_at'])) ?></td>
            <td class="px-6 py-3">
              <div class="flex items-center gap-2">
                <button onclick="startEdit(<?= (int)$b['id'] ?>, <?= htmlspecialchars(json_encode($b['name'])) ?>, <?= (float)$b['discount_percent'] ?>, <?= htmlspecialchars(json_encode($b['dealer_email'] ?? '')) ?>)"
                        class="p-1.5 text-indigo-600 hover:bg-indigo-50 rounded transition-colors">
                  <i data-lucide="pencil" class="w-4 h-4"></i>
                </button>
                <form method="POST" onsubmit="return confirm('Delete this brand?');" class="inline">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                  <button type="submit" class="p-1.5 text-red-500 hover:bg-red-50 rounded transition-colors">
                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                  </button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

</div>

<!-- Edit modal -->
<div id="edit-modal" class="fixed inset-0 z-50 hidden bg-black/40 flex items-center justify-center p-4">
  <div class="bg-white rounded-xl shadow-xl w-full max-w-md p-6">
    <h3 class="font-semibold text-gray-800 mb-4">Edit Brand</h3>
    <form method="POST" class="space-y-4">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" id="edit-id">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Brand Name *</label>
        <input type="text" name="name" id="edit-name" required
               class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Dealer Email</label>
        <input type="email" name="dealer_email" id="edit-dealer-email"
               class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
               placeholder="e.g. dealer@apple.com">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Discount %</label>
        <input type="number" name="discount_percent" id="edit-discount" step="0.01" min="0" max="100"
               class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none">
      </div>
      <div class="flex gap-3">
        <button type="button" onclick="closeModal()"
                class="flex-1 py-2.5 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors">
          Cancel
        </button>
        <button type="submit"
                class="flex-1 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-lg transition-colors">
          Save
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function startEdit(id, name, discount, dealerEmail) {
  document.getElementById('edit-id').value           = id;
  document.getElementById('edit-name').value         = name;
  document.getElementById('edit-discount').value     = discount;
  document.getElementById('edit-dealer-email').value = dealerEmail || '';
  document.getElementById('edit-modal').classList.remove('hidden');
}
function closeModal() {
  document.getElementById('edit-modal').classList.add('hidden');
}
document.getElementById('edit-modal').addEventListener('click', function(e) {
  if (e.target === this) closeModal();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
