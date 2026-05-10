<?php
/**
 * pages/estimator.php — Standalone shipping estimator (no order saved).
 */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/calc.php';
requireLogin();

$pdo = getPDO();

// Load settings
$usdToAed = (float)($pdo->query("SELECT value FROM settings WHERE `key`='usd_to_aed'")->fetchColumn() ?: USD_TO_AED);
$snsJson  = $pdo->query("SELECT value FROM settings WHERE `key`='sns_anchors'")->fetchColumn();
$snsAnchors = $snsJson ? json_decode($snsJson, true) : null;

// Load brands for discount selection
$brands = $pdo->query('SELECT id, name, discount_percent FROM brands ORDER BY name')->fetchAll();

$results  = null;
$errors   = [];
$posted   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = $_POST;
    $brandId   = (int)($_POST['brand_id']   ?? 0);
    $priceUSD  = (float)($_POST['price_usd']  ?? 0);
    $weight    = (float)($_POST['weight_kg']  ?? 0);
    $l         = (float)($_POST['dim_l']      ?? 0);
    $w         = (float)($_POST['dim_w']      ?? 0);
    $h         = (float)($_POST['dim_h']      ?? 0);

    if ($priceUSD <= 0) $errors[] = 'Price must be > 0.';
    if ($weight <= 0)   $errors[] = 'Weight must be > 0.';
    if ($l <= 0 || $w <= 0 || $h <= 0) $errors[] = 'All dimensions are required.';

    if (empty($errors)) {
        $discountPct = 0.0;
        if ($brandId) {
            $bs = $pdo->prepare('SELECT discount_percent FROM brands WHERE id=?');
            $bs->execute([$brandId]);
            $br = $bs->fetch();
            if ($br) $discountPct = (float)$br['discount_percent'];
        }
        $results = computeFullResults($priceUSD, $discountPct, $weight, $l, $w, $h, $snsAnchors);
    }
}

function fmt(float $n): string { return number_format($n, 2); }

$pageTitle = 'Shipping Estimator';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-4xl mx-auto">
  <div class="grid lg:grid-cols-5 gap-6">

    <!-- Form -->
    <div class="lg:col-span-2">
      <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h2 class="font-semibold text-gray-800 mb-5 flex items-center gap-2">
          <i data-lucide="calculator" class="w-5 h-5 text-indigo-600"></i>
          Estimate Shipping
        </h2>

        <?php if (!empty($errors)): ?>
        <div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg">
          <ul class="list-disc list-inside text-sm text-red-700 space-y-1">
            <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">

          <!-- Brand (optional) -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Brand (optional, for discount)</label>
            <select name="brand_id"
                    class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none bg-white">
              <option value="">— No discount —</option>
              <?php foreach ($brands as $b): ?>
              <option value="<?= (int)$b['id'] ?>"
                      <?= (int)($posted['brand_id'] ?? 0) === (int)$b['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($b['name']) ?> (<?= number_format((float)$b['discount_percent'],1) ?>%)
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Price -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Price (USD) *</label>
            <div class="relative">
              <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm">$</span>
              <input type="number" name="price_usd" step="0.01" min="0.01" required
                     value="<?= htmlspecialchars($posted['price_usd'] ?? '') ?>"
                     class="w-full pl-7 pr-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                     placeholder="0.00">
            </div>
          </div>

          <!-- Weight -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Weight (kg) *</label>
            <input type="number" name="weight_kg" step="0.01" min="0.01" required
                   value="<?= htmlspecialchars($posted['weight_kg'] ?? '') ?>"
                   class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                   placeholder="0.00">
          </div>

          <!-- Dimensions -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Dimensions cm (L × W × H) *</label>
            <div class="grid grid-cols-3 gap-2">
              <input type="number" name="dim_l" step="0.1" min="0.1" required
                     value="<?= htmlspecialchars($posted['dim_l'] ?? '') ?>"
                     class="w-full px-2 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                     placeholder="L">
              <input type="number" name="dim_w" step="0.1" min="0.1" required
                     value="<?= htmlspecialchars($posted['dim_w'] ?? '') ?>"
                     class="w-full px-2 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                     placeholder="W">
              <input type="number" name="dim_h" step="0.1" min="0.1" required
                     value="<?= htmlspecialchars($posted['dim_h'] ?? '') ?>"
                     class="w-full px-2 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                     placeholder="H">
            </div>
          </div>

          <button type="submit"
                  class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-lg transition-colors">
            Calculate
          </button>
        </form>
      </div>
    </div>

    <!-- Results -->
    <div class="lg:col-span-3 space-y-4">
      <?php if ($results): ?>

      <!-- Summary pill -->
      <div class="bg-indigo-600 rounded-xl p-5 text-white">
        <p class="text-sm text-indigo-200 mb-1">Discounted Price (AED)</p>
        <p class="text-3xl font-bold">AED <?= fmt($results['discountedPriceAED']) ?></p>
        <p class="text-sm text-indigo-200 mt-1">
          Best option:
          <strong><?= $results['cheapest'] === 'selfShip' ? 'SelfShip PRO' : 'Shop&amp;Ship' ?></strong>
        </p>
      </div>

      <!-- Create Order shortcut -->
      <?php
        $orderParams = http_build_query([
            'brand_id'  => (int)($posted['brand_id']  ?? 0),
            'price_usd' => (float)($posted['price_usd'] ?? 0),
            'weight_kg' => (float)($posted['weight_kg'] ?? 0),
            'dim_l'     => (float)($posted['dim_l']     ?? 0),
            'dim_w'     => (float)($posted['dim_w']     ?? 0),
            'dim_h'     => (float)($posted['dim_h']     ?? 0),
        ]);
      ?>
      <a href="<?= app_url('pages/new_order.php') ?>?<?= $orderParams ?>"
         class="flex items-center justify-center gap-2 w-full py-2.5 bg-green-600 hover:bg-green-700 text-white font-semibold rounded-xl transition-colors">
        <i data-lucide="plus-circle" class="w-4 h-4"></i>
        Create Order from This Estimate
      </a>

      <!-- Weight table -->
      <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5">
        <h3 class="font-semibold text-gray-700 mb-3">Weight Details</h3>
        <dl class="grid grid-cols-2 gap-3 text-sm">
          <div><dt class="text-gray-500">Actual</dt><dd class="font-medium"><?= fmt($results['weights']['actualKg']) ?> kg</dd></div>
          <div><dt class="text-gray-500">Volumetric</dt><dd class="font-medium"><?= fmt($results['weights']['volumetricKg']) ?> kg</dd></div>
          <div><dt class="text-gray-500">SelfShip chargeable</dt><dd class="font-medium"><?= fmt($results['weights']['chargeableSelfKg']) ?> kg (<?= $results['weights']['usedTypeSelf'] ?>)</dd></div>
          <div><dt class="text-gray-500">Shop&amp;Ship chargeable</dt><dd class="font-medium"><?= fmt($results['weights']['chargeableSnsKg']) ?> kg</dd></div>
        </dl>
      </div>

      <!-- Side-by-side carriers -->
      <div class="grid sm:grid-cols-2 gap-4">

        <!-- SelfShip PRO -->
        <div class="bg-white rounded-xl shadow-sm border <?= ($results['cheapest'] === 'selfShip' && $results['selfShip']['eligible']) ? 'border-green-400 ring-2 ring-green-200' : 'border-gray-200' ?> p-5">
          <div class="flex items-center justify-between mb-3">
            <h3 class="font-semibold text-gray-700">SelfShip PRO</h3>
            <?php if ($results['cheapest'] === 'selfShip' && $results['selfShip']['eligible']): ?>
            <span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full font-medium">Cheapest</span>
            <?php endif; ?>
          </div>

          <?php if (!$results['selfShip']['eligible']): ?>
          <div class="text-sm text-red-600 bg-red-50 p-2 rounded">
            Not eligible: <?= htmlspecialchars(implode(', ', $results['selfShip']['reasons'])) ?>
          </div>
          <?php else: ?>
          <dl class="space-y-1.5 text-sm">
            <div class="flex justify-between"><dt class="text-gray-500">Shipping</dt><dd>AED <?= fmt($results['selfShip']['shippingAED']) ?></dd></div>
            <div class="flex justify-between"><dt class="text-gray-500">VAT (5%)</dt><dd>AED <?= fmt($results['selfShip']['vat']) ?></dd></div>
            <div class="flex justify-between"><dt class="text-gray-500">Customs</dt><dd>AED <?= fmt($results['selfShip']['customs']) ?></dd></div>
            <div class="flex justify-between pt-1 border-t border-gray-100 font-bold">
              <dt>Total</dt><dd class="text-gray-900">AED <?= fmt($results['selfShip']['total']) ?></dd>
            </div>
          </dl>
          <?php endif; ?>
        </div>

        <!-- Shop&Ship -->
        <div class="bg-white rounded-xl shadow-sm border <?= $results['cheapest'] === 'shopAndShip' ? 'border-green-400 ring-2 ring-green-200' : 'border-gray-200' ?> p-5">
          <div class="flex items-center justify-between mb-3">
            <h3 class="font-semibold text-gray-700">Shop&amp;Ship</h3>
            <?php if ($results['cheapest'] === 'shopAndShip'): ?>
            <span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full font-medium">Cheapest</span>
            <?php endif; ?>
          </div>
          <dl class="space-y-1.5 text-sm">
            <div class="flex justify-between"><dt class="text-gray-500">Shipping</dt><dd>AED <?= fmt($results['shopAndShip']['shippingAED']) ?></dd></div>
            <div class="flex justify-between"><dt class="text-gray-500">VAT (5%)</dt><dd>AED <?= fmt($results['shopAndShip']['vat']) ?></dd></div>
            <div class="flex justify-between"><dt class="text-gray-500">Customs</dt><dd>AED <?= fmt($results['shopAndShip']['customs']) ?></dd></div>
            <div class="flex justify-between pt-1 border-t border-gray-100 font-bold">
              <dt>Total</dt><dd class="text-gray-900">AED <?= fmt($results['shopAndShip']['total']) ?></dd>
            </div>
          </dl>
        </div>

      </div>

      <?php else: ?>
      <div class="flex items-center justify-center h-48 bg-white rounded-xl shadow-sm border border-gray-200 text-gray-400">
        <div class="text-center">
          <i data-lucide="calculator" class="w-10 h-10 mx-auto mb-2"></i>
          <p>Fill in the form and click Calculate</p>
        </div>
      </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
