<?php
/**
 * pages/settings.php — Admin: edit USD rate + SNS anchor prices.
 */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
requireAdmin();

$pdo = getPDO();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_rate') {
        $rate = (float)($_POST['usd_to_aed'] ?? 0);
        if ($rate <= 0) {
            setFlash('error', 'Exchange rate must be positive.');
        } else {
            $pdo->prepare("INSERT INTO settings (`key`, value) VALUES ('usd_to_aed', ?)
                           ON DUPLICATE KEY UPDATE value = ?")
                ->execute([$rate, $rate]);
            setFlash('success', 'Exchange rate updated.');
        }

    } elseif ($action === 'save_anchors') {
        $us = $_POST['u'] ?? [];
        $ps = $_POST['p'] ?? [];
        $anchors = [];
        foreach ($us as $i => $u) {
            $u = (int)$u;
            $p = (float)($ps[$i] ?? 0);
            if ($u > 0 && $p > 0) {
                $anchors[] = ['u' => $u, 'p' => $p];
            }
        }
        if (empty($anchors)) {
            setFlash('error', 'At least one anchor row is required.');
        } else {
            usort($anchors, fn($a, $b) => $a['u'] <=> $b['u']);
            $json = json_encode($anchors);
            $pdo->prepare("INSERT INTO settings (`key`, value) VALUES ('sns_anchors', ?)
                           ON DUPLICATE KEY UPDATE value = ?")
                ->execute([$json, $json]);
            setFlash('success', 'SNS anchors updated.');
        }
    }

    header('Location: ' . app_url('pages/settings.php'));
    exit;
}

// Load current values
$usdToAed = $pdo->query("SELECT value FROM settings WHERE `key`='usd_to_aed'")->fetchColumn() ?: '3.699';
$snsJson  = $pdo->query("SELECT value FROM settings WHERE `key`='sns_anchors'")->fetchColumn();
$anchors  = $snsJson ? json_decode($snsJson, true) : [];

$pageTitle = 'Settings';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-3xl mx-auto space-y-6">

  <!-- Exchange rate -->
  <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
    <h3 class="font-semibold text-gray-700 mb-4 flex items-center gap-2">
      <i data-lucide="dollar-sign" class="w-5 h-5 text-indigo-600"></i>
      USD → AED Exchange Rate
    </h3>
    <form method="POST" class="flex flex-wrap gap-3 items-end">
      <input type="hidden" name="action" value="save_rate">
      <div class="flex-1 min-w-[200px]">
        <label class="block text-sm font-medium text-gray-700 mb-1">Rate (1 USD = ? AED)</label>
        <input type="number" name="usd_to_aed" step="0.0001" min="0.0001"
               value="<?= htmlspecialchars($usdToAed) ?>"
               class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none">
      </div>
      <button type="submit"
              class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-lg transition-colors">
        Save Rate
      </button>
    </form>
  </div>

  <!-- SNS Anchors -->
  <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
    <div class="flex items-center justify-between mb-4">
      <h3 class="font-semibold text-gray-700 flex items-center gap-2">
        <i data-lucide="table" class="w-5 h-5 text-indigo-600"></i>
        Shop&amp;Ship Anchor Prices
      </h3>
      <button onclick="addRow()" type="button"
              class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-indigo-50 text-indigo-700 rounded-lg text-sm hover:bg-indigo-100 transition-colors">
        <i data-lucide="plus" class="w-3.5 h-3.5"></i> Add Row
      </button>
    </div>

    <form method="POST">
      <input type="hidden" name="action" value="save_anchors">
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm mb-4" id="anchors-table">
          <thead>
            <tr class="border-b border-gray-200">
              <th class="pb-2 text-left font-semibold text-gray-600">Units (ceil kg×10)</th>
              <th class="pb-2 text-left font-semibold text-gray-600 pl-4">Price (AED)</th>
              <th class="pb-2"></th>
            </tr>
          </thead>
          <tbody id="anchors-body">
            <?php foreach ($anchors as $a): ?>
            <tr class="border-b border-gray-50">
              <td class="py-1.5">
                <input type="number" name="u[]" value="<?= (int)$a['u'] ?>" min="1" required
                       class="w-28 px-2 py-1.5 border border-gray-300 rounded focus:ring-1 focus:ring-indigo-400 outline-none">
              </td>
              <td class="py-1.5 pl-4">
                <input type="number" name="p[]" value="<?= (float)$a['p'] ?>" step="0.01" min="0" required
                       class="w-28 px-2 py-1.5 border border-gray-300 rounded focus:ring-1 focus:ring-indigo-400 outline-none">
              </td>
              <td class="py-1.5 pl-2">
                <button type="button" onclick="this.closest('tr').remove()"
                        class="p-1 text-red-400 hover:text-red-600 transition-colors">
                  <i data-lucide="x" class="w-4 h-4"></i>
                </button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <button type="submit"
              class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-lg transition-colors">
        Save Anchors
      </button>
    </form>
  </div>

</div>

<script>
function addRow() {
  const tbody = document.getElementById('anchors-body');
  const tr = document.createElement('tr');
  tr.className = 'border-b border-gray-50';
  tr.innerHTML = `
    <td class="py-1.5">
      <input type="number" name="u[]" placeholder="Units" min="1" required
             class="w-28 px-2 py-1.5 border border-gray-300 rounded focus:ring-1 focus:ring-indigo-400 outline-none">
    </td>
    <td class="py-1.5 pl-4">
      <input type="number" name="p[]" placeholder="Price" step="0.01" min="0" required
             class="w-28 px-2 py-1.5 border border-gray-300 rounded focus:ring-1 focus:ring-indigo-400 outline-none">
    </td>
    <td class="py-1.5 pl-2">
      <button type="button" onclick="this.closest('tr').remove()"
              class="p-1 text-red-400 hover:text-red-600 transition-colors">
        <i data-lucide="x" class="w-4 h-4"></i>
      </button>
    </td>`;
  tbody.appendChild(tr);
  lucide.createIcons();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
