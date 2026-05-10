<?php
/**
 * pages/new_order.php — New inquiry form (mobile-first).
 * Calculates server-side on submit → saves as Draft → redirects to order_detail.
 */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/calc.php';
requireLogin();

$pdo    = getPDO();
$errors = [];

// Load brands for dropdown
$brands = $pdo->query('SELECT id, name, discount_percent FROM brands ORDER BY name')->fetchAll();

// Load settings
$usdToAed = (float)($pdo->query("SELECT value FROM settings WHERE `key`='usd_to_aed'")->fetchColumn() ?: USD_TO_AED);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $brandId  = (int)($_POST['brand_id']      ?? 0);
    $product  = trim($_POST['product_name']   ?? '');
    $priceUSD = (float)($_POST['price_usd']   ?? 0);
    $weight   = (float)($_POST['weight_kg']   ?? 0);
    $l        = (float)($_POST['dim_l']       ?? 0);
    $w        = (float)($_POST['dim_w']       ?? 0);
    $h        = (float)($_POST['dim_h']       ?? 0);
    $carrier  = $_POST['carrier'] ?? '';
    $notes    = trim($_POST['notes']          ?? '');

    // Validate
    if (!$brandId)  $errors[] = 'Please select a brand.';
    if ($priceUSD <= 0) $errors[] = 'Price must be greater than 0.';
    if ($weight <= 0)   $errors[] = 'Weight must be greater than 0.';
    if ($l <= 0 || $w <= 0 || $h <= 0) $errors[] = 'All dimensions are required and must be > 0.';
    if (!in_array($carrier, ['SelfShip PRO', 'Shop&Ship'])) $errors[] = 'Please select a carrier.';

    if (empty($errors)) {
        // Get brand discount
        $brandStmt = $pdo->prepare('SELECT discount_percent FROM brands WHERE id = ?');
        $brandStmt->execute([$brandId]);
        $brand = $brandStmt->fetch();
        if (!$brand) { $errors[] = 'Brand not found.'; }
    }

    if (empty($errors)) {
        $discountPct = (float)$brand['discount_percent'];

        // Load SNS anchors from settings
        $snsJson    = $pdo->query("SELECT value FROM settings WHERE `key`='sns_anchors'")->fetchColumn();
        $snsAnchors = $snsJson ? json_decode($snsJson, true) : null;

        $results = computeFullResults($priceUSD, $discountPct, $weight, $l, $w, $h, $snsAnchors);

        // Validate carrier eligibility
        if ($carrier === 'SelfShip PRO' && !$results['selfShip']['eligible']) {
            $errors[] = 'SelfShip PRO is not eligible: ' . implode(', ', $results['selfShip']['reasons']);
        }

        if (empty($errors)) {
            if ($carrier === 'SelfShip PRO') {
                $shippingAED = $results['selfShip']['shippingAED'];
                $vatAed      = $results['selfShip']['vat'];
                $customsAed  = $results['selfShip']['customs'];
                $totalAed    = $results['selfShip']['total'];
            } else {
                $shippingAED = $results['shopAndShip']['shippingAED'];
                $vatAed      = $results['shopAndShip']['vat'];
                $customsAed  = $results['shopAndShip']['customs'];
                $totalAed    = $results['shopAndShip']['total'];
            }

            $stmt = $pdo->prepare("
                INSERT INTO orders
                    (brand_id, product_name, price_usd, discount_percent, discounted_price_aed,
                     weight_kg, dim_length, dim_width, dim_height, carrier,
                     shipping_aed, vat_aed, customs_aed, total_aed,
                     status, notes, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'Draft',?,?)
            ");
            $stmt->execute([
                $brandId, $product, $priceUSD, $discountPct, $results['discountedPriceAED'],
                $weight, $l, $w, $h, $carrier,
                $shippingAED, $vatAed, $customsAed, $totalAed,
                $notes, currentUser()['id'],
            ]);
            $orderId = $pdo->lastInsertId();
            setFlash('success', 'Order #' . $orderId . ' created as Draft.');
            header('Location: /pages/order_detail.php?id=' . $orderId);
            exit;
        }
    }
}

$pageTitle = 'New Order';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-2xl mx-auto">
  <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
    <h2 class="text-lg font-semibold text-gray-800 mb-6 flex items-center gap-2">
      <i data-lucide="plus-circle" class="w-5 h-5 text-indigo-600"></i>
      New Order Inquiry
    </h2>

    <?php if (!empty($errors)): ?>
    <div class="mb-5 p-4 bg-red-50 border border-red-200 rounded-lg">
      <ul class="list-disc list-inside text-sm text-red-700 space-y-1">
        <?php foreach ($errors as $e): ?>
        <li><?= htmlspecialchars($e) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <form method="POST" action="" class="space-y-5">

      <!-- Brand -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1" for="brand_id">Brand *</label>
        <select id="brand_id" name="brand_id" required
                class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none bg-white"
                onchange="loadDiscount(this.value)">
          <option value="">— Select Brand —</option>
          <?php foreach ($brands as $b): ?>
          <option value="<?= (int)$b['id'] ?>"
                  data-discount="<?= (float)$b['discount_percent'] ?>"
                  <?= (isset($_POST['brand_id']) && (int)$_POST['brand_id'] === (int)$b['id']) ? 'selected' : '' ?>>
            <?= htmlspecialchars($b['name']) ?>
            (<?= number_format((float)$b['discount_percent'], 1) ?>% off)
          </option>
          <?php endforeach; ?>
        </select>
        <p id="discount-info" class="mt-1 text-xs text-green-600 hidden"></p>
      </div>

      <!-- Product Name -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1" for="product_name">Product Name</label>
        <input type="text" id="product_name" name="product_name"
               value="<?= htmlspecialchars($_POST['product_name'] ?? '') ?>"
               class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
               placeholder="e.g. iPhone 15 Pro Max 256GB">
      </div>

      <!-- Price -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1" for="price_usd">Price (USD) *</label>
        <div class="relative">
          <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
            <span class="text-gray-400 text-sm">$</span>
          </div>
          <input type="number" id="price_usd" name="price_usd" step="0.01" min="0.01" required
                 value="<?= htmlspecialchars($_POST['price_usd'] ?? '') ?>"
                 class="w-full pl-7 pr-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                 placeholder="0.00">
        </div>
      </div>

      <!-- Weight -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1" for="weight_kg">Weight (kg) *</label>
        <input type="number" id="weight_kg" name="weight_kg" step="0.01" min="0.01" required
               value="<?= htmlspecialchars($_POST['weight_kg'] ?? '') ?>"
               class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
               placeholder="0.00">
      </div>

      <!-- Dimensions -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Dimensions (cm) — L × W × H *</label>
        <div class="grid grid-cols-3 gap-3">
          <input type="number" name="dim_l" step="0.1" min="0.1" required
                 value="<?= htmlspecialchars($_POST['dim_l'] ?? '') ?>"
                 class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                 placeholder="L">
          <input type="number" name="dim_w" step="0.1" min="0.1" required
                 value="<?= htmlspecialchars($_POST['dim_w'] ?? '') ?>"
                 class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                 placeholder="W">
          <input type="number" name="dim_h" step="0.1" min="0.1" required
                 value="<?= htmlspecialchars($_POST['dim_h'] ?? '') ?>"
                 class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                 placeholder="H">
        </div>
      </div>

      <!-- Carrier -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Carrier *</label>
        <div class="grid grid-cols-2 gap-3">
          <label class="flex items-center gap-3 p-3 border border-gray-300 rounded-lg cursor-pointer hover:border-indigo-400 has-[:checked]:border-indigo-600 has-[:checked]:bg-indigo-50 transition-colors">
            <input type="radio" name="carrier" value="SelfShip PRO"
                   <?= (($_POST['carrier'] ?? '') === 'SelfShip PRO') ? 'checked' : '' ?> class="text-indigo-600">
            <div>
              <div class="font-medium text-sm text-gray-800">SelfShip PRO</div>
              <div class="text-xs text-gray-500">Volume weight</div>
            </div>
          </label>
          <label class="flex items-center gap-3 p-3 border border-gray-300 rounded-lg cursor-pointer hover:border-indigo-400 has-[:checked]:border-indigo-600 has-[:checked]:bg-indigo-50 transition-colors">
            <input type="radio" name="carrier" value="Shop&Ship"
                   <?= (($_POST['carrier'] ?? '') === 'Shop&Ship') ? 'checked' : '' ?> class="text-indigo-600">
            <div>
              <div class="font-medium text-sm text-gray-800">Shop&amp;Ship</div>
              <div class="text-xs text-gray-500">Actual weight</div>
            </div>
          </label>
        </div>
      </div>

      <!-- Notes -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1" for="notes">Notes (optional)</label>
        <textarea id="notes" name="notes" rows="3"
                  class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none resize-none"
                  placeholder="Any special instructions..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
      </div>

      <div class="flex gap-3 pt-2">
        <a href="/pages/dashboard.php"
           class="flex-1 text-center py-2.5 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors font-medium">
          Cancel
        </a>
        <button type="submit"
                class="flex-1 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-lg transition-colors">
          Create Draft
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function loadDiscount(brandId) {
  const select = document.getElementById('brand_id');
  const opt    = select.querySelector(`option[value="${brandId}"]`);
  const info   = document.getElementById('discount-info');
  if (opt && brandId) {
    const pct = parseFloat(opt.dataset.discount || 0);
    info.textContent = `Brand discount: ${pct.toFixed(1)}% applied to price`;
    info.classList.remove('hidden');
  } else {
    info.classList.add('hidden');
  }
}
// Init on page load if brand pre-selected
document.addEventListener('DOMContentLoaded', () => {
  const sel = document.getElementById('brand_id');
  if (sel.value) loadDiscount(sel.value);
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
