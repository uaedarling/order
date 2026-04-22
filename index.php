<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$successMsg = '';
$errorMsg   = '';

// Handle order submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['submit_order'])) {
    $brandId    = (int)($_POST['brand_id'] ?? 0);
    $partNumber = trim($_POST['part_number'] ?? '');
    $link       = trim($_POST['link'] ?? '');
    $priceUSD   = (float)($_POST['price_usd'] ?? 0);
    $weight     = (float)($_POST['weight'] ?? 0);
    $l          = (float)($_POST['l'] ?? 0);
    $w          = (float)($_POST['w'] ?? 0);
    $h          = (float)($_POST['h'] ?? 0);
    $agreedPrice = (float)($_POST['agreed_price'] ?? 0);

    if (!$brandId || $partNumber === '' || $priceUSD <= 0 || $weight <= 0 || $l <= 0 || $w <= 0 || $h <= 0) {
        $errorMsg = 'Please fill in all required fields with valid values.';
    } else {
        // Get brand discount
        $bStmt = $pdo->prepare("SELECT discount_percent FROM brands WHERE id = ?");
        $bStmt->execute([$brandId]);
        $brand = $bStmt->fetch();

        if (!$brand) {
            $errorMsg = 'Invalid brand selected.';
        } else {
            $results  = computeFullResults($priceUSD, (float)$brand['discount_percent'], $weight, $l, $w, $h);
            $costAed  = $results['cheapest'] === 'selfShip'
                ? $results['selfShip']['total']
                : $results['shopAndShip']['total'];

            // Handle file upload
            $poPath = null;
            if (!empty($_FILES['po_file']['name'])) {
                $allowedExts  = ['jpg','jpeg','png','gif','pdf'];
                $allowedMimes = ['image/jpeg','image/png','image/gif','application/pdf'];
                $ext          = strtolower(pathinfo($_FILES['po_file']['name'], PATHINFO_EXTENSION));
                $uploadDir    = __DIR__ . '/uploads/';

                if ($_FILES['po_file']['error'] !== UPLOAD_ERR_OK) {
                    $errorMsg = 'File upload error.';
                } elseif (!in_array($ext, $allowedExts, true)) {
                    $errorMsg = 'Invalid file type. Allowed: JPG, PNG, GIF, PDF.';
                } elseif ($_FILES['po_file']['size'] > 10 * 1024 * 1024) {
                    $errorMsg = 'File size must not exceed 10 MB.';
                } else {
                    // Validate MIME type from file content (not just extension)
                    $finfo    = new finfo(FILEINFO_MIME_TYPE);
                    $mimeType = $finfo->file($_FILES['po_file']['tmp_name']);
                    if (!in_array($mimeType, $allowedMimes, true)) {
                        $errorMsg = 'Invalid file content. Only JPG, PNG, GIF, and PDF files are accepted.';
                    } else {
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0750, true);
                        }
                        $filename = uniqid('po_', true) . '.' . $ext;
                        if (move_uploaded_file($_FILES['po_file']['tmp_name'], $uploadDir . $filename)) {
                            $poPath = 'uploads/' . $filename;
                        } else {
                            $errorMsg = 'Failed to save uploaded file.';
                        }
                    }
                }
            }

            if ($errorMsg === '') {
                $stmt = $pdo->prepare("INSERT INTO orders
                    (brand_id, part_number, link, price_usd, weight, l, w, h, cost_aed, agreed_price, po_path, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')");
                $stmt->execute([
                    $brandId, $partNumber, $link ?: null, $priceUSD,
                    $weight, $l, $w, $h,
                    round($costAed, 2), $agreedPrice > 0 ? round($agreedPrice, 2) : null,
                    $poPath,
                ]);
                $orderId    = $pdo->lastInsertId();
                $successMsg = "Order #$orderId saved successfully!";
            }
        }
    }
}

// Fetch brands for dropdown
$brands = $pdo->query("SELECT id, name, discount_percent FROM brands ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>New Order — Special Order System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <style>
        body { background: #f8f9fa; }
        .result-card { border-left: 4px solid #0d6efd; }
        .result-card.cheapest { border-left-color: #198754; }
        .result-card.ineligible { border-left-color: #dc3545; }
        .badge-cheapest { font-size: .75rem; }
    </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand" href="/index.php">🛒 Special Order System</a>
        <div class="d-flex align-items-center gap-3">
            <?php if (isAdmin()): ?>
                <a class="btn btn-outline-light btn-sm" href="/admin.php">Admin Panel</a>
            <?php endif; ?>
            <span class="text-light">
                <?= htmlspecialchars($_SESSION['username']) ?>
                <span class="badge bg-<?= isAdmin() ? 'warning text-dark' : 'secondary' ?> ms-1">
                    <?= htmlspecialchars($_SESSION['role']) ?>
                </span>
            </span>
            <a class="btn btn-outline-danger btn-sm" href="/logout.php">Logout</a>
        </div>
    </div>
</nav>

<div class="container pb-5">

    <?php if ($successMsg): ?>
        <div class="alert alert-success alert-dismissible fade show">
            ✅ <?= htmlspecialchars($successMsg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($errorMsg): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            ❌ <?= htmlspecialchars($errorMsg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Order Form -->
        <div class="col-lg-5">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">📦 New Special Order</h5>
                </div>
                <div class="card-body">
                    <form id="orderForm" method="POST" enctype="multipart/form-data" novalidate>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Brand <span class="text-danger">*</span></label>
                            <select name="brand_id" id="brand_id" class="form-select" required>
                                <option value="">— Select brand —</option>
                                <?php foreach ($brands as $b): ?>
                                    <option value="<?= $b['id'] ?>"
                                        data-discount="<?= $b['discount_percent'] ?>"
                                        <?= (($_POST['brand_id'] ?? '') == $b['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($b['name']) ?>
                                        (<?= number_format($b['discount_percent'], 1) ?>% off)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Part Number <span class="text-danger">*</span></label>
                            <input type="text" name="part_number" id="part_number" class="form-control"
                                   value="<?= htmlspecialchars($_POST['part_number'] ?? '') ?>" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Product Link</label>
                            <input type="url" name="link" id="link" class="form-control"
                                   value="<?= htmlspecialchars($_POST['link'] ?? '') ?>"
                                   placeholder="https://...">
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Price (USD) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" name="price_usd" id="price_usd" class="form-control"
                                       min="0.01" step="0.01"
                                       value="<?= htmlspecialchars($_POST['price_usd'] ?? '') ?>" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Actual Weight (kg) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" name="weight" id="weight" class="form-control"
                                       min="0.001" step="0.001"
                                       value="<?= htmlspecialchars($_POST['weight'] ?? '') ?>" required>
                                <span class="input-group-text">kg</span>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Dimensions (cm) <span class="text-danger">*</span></label>
                            <div class="row g-2">
                                <div class="col-4">
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text">L</span>
                                        <input type="number" name="l" id="dim_l" class="form-control"
                                               min="0.01" step="0.01"
                                               value="<?= htmlspecialchars($_POST['l'] ?? '') ?>" required>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text">W</span>
                                        <input type="number" name="w" id="dim_w" class="form-control"
                                               min="0.01" step="0.01"
                                               value="<?= htmlspecialchars($_POST['w'] ?? '') ?>" required>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text">H</span>
                                        <input type="number" name="h" id="dim_h" class="form-control"
                                               min="0.01" step="0.01"
                                               value="<?= htmlspecialchars($_POST['h'] ?? '') ?>" required>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Submission section (shown once required fields are filled) -->
                        <div id="submissionSection" class="mt-4 pt-3 border-top d-none">
                            <h6 class="fw-semibold text-muted mb-3">Order Submission</h6>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Agreed Price (AED)</label>
                                <div class="input-group">
                                    <span class="input-group-text">AED</span>
                                    <input type="number" name="agreed_price" id="agreed_price" class="form-control"
                                           min="0" step="0.01"
                                           value="<?= htmlspecialchars($_POST['agreed_price'] ?? '') ?>">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Purchase Order (PO) File</label>
                                <input type="file" name="po_file" id="po_file" class="form-control"
                                       accept="image/*,.pdf">
                                <div class="form-text">JPG, PNG, GIF or PDF — max 10 MB</div>
                            </div>

                            <div class="d-grid">
                                <button type="submit" name="submit_order" value="1" class="btn btn-success btn-lg">
                                    💾 Save Order
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Live Calculation Results -->
        <div class="col-lg-7">
            <div id="calcResults">
                <div class="card shadow-sm text-center py-5 text-muted">
                    <div class="card-body">
                        <p class="fs-5">📊 Fill in the form to see live shipping calculations.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    const fields = ['brand_id','price_usd','weight','dim_l','dim_w','dim_h'];
    const requiredIds = ['brand_id','price_usd','weight','dim_l','dim_w','dim_h'];

    let debounceTimer = null;

    function allFilled() {
        return requiredIds.every(id => {
            const el = document.getElementById(id);
            return el && el.value.trim() !== '' && parseFloat(el.value) > 0;
        });
    }

    function toggleSubmission() {
        const sec = document.getElementById('submissionSection');
        if (allFilled()) {
            sec.classList.remove('d-none');
        } else {
            sec.classList.add('d-none');
        }
    }

    function fmt(n) {
        return parseFloat(n).toFixed(2);
    }

    function renderResults(data) {
        const cheapest = data.cheapest;
        const w = data.weights;
        const ss = data.selfShip;
        const sns = data.shopAndShip;

        let html = `
        <div class="card shadow-sm mb-3">
          <div class="card-header bg-secondary text-white">
            <h5 class="mb-0">📊 Shipping Calculation</h5>
          </div>
          <div class="card-body">
            <p class="mb-1"><strong>Discounted Price:</strong> AED ${fmt(data.discountedPriceAED)}</p>
            <div class="table-responsive mt-2">
              <table class="table table-sm table-bordered mb-0">
                <thead class="table-light">
                  <tr><th>Weight</th><th>Actual kg</th><th>Volumetric kg</th><th>Chargeable kg</th></tr>
                </thead>
                <tbody>
                  <tr>
                    <td>SelfShip PRO</td>
                    <td>${fmt(w.actualKg)}</td>
                    <td>${fmt(w.volumetricKg)}</td>
                    <td>${fmt(w.chargeableSelfKg)}</td>
                  </tr>
                  <tr>
                    <td>Shop&amp;Ship</td>
                    <td>${fmt(w.actualKg)}</td>
                    <td>—</td>
                    <td>${fmt(w.chargeableSnsKg)}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>`;

        // SelfShip card
        if (ss.eligible) {
            const isCheapest = cheapest === 'selfShip';
            html += `
        <div class="card shadow-sm mb-3 result-card${isCheapest ? ' cheapest' : ''}">
          <div class="card-header bg-${isCheapest ? 'success' : 'primary'} text-white d-flex justify-content-between align-items-center">
            <span>✈️ SelfShip PRO</span>
            ${isCheapest ? '<span class="badge bg-light text-success badge-cheapest">✓ Cheapest</span>' : ''}
          </div>
          <div class="card-body">
            <div class="row text-center">
              <div class="col-3"><div class="small text-muted">Shipping</div><strong>AED ${fmt(ss.shippingAED)}</strong></div>
              <div class="col-3"><div class="small text-muted">VAT 5%</div><strong>AED ${fmt(ss.vat)}</strong></div>
              <div class="col-3"><div class="small text-muted">Customs</div><strong>AED ${fmt(ss.customs)}</strong></div>
              <div class="col-3"><div class="small text-muted">Total</div><strong class="text-${isCheapest ? 'success' : 'primary'}">AED ${fmt(ss.total)}</strong></div>
            </div>
          </div>
        </div>`;
        } else {
            html += `
        <div class="card shadow-sm mb-3 result-card ineligible">
          <div class="card-header bg-danger text-white">✈️ SelfShip PRO — Not Eligible</div>
          <div class="card-body">
            <ul class="mb-0">
              ${ss.reasons.map(r => `<li>${r}</li>`).join('')}
            </ul>
          </div>
        </div>`;
        }

        // Shop&Ship card
        const snsIsCheapest = cheapest === 'shopAndShip';
        html += `
        <div class="card shadow-sm mb-3 result-card${snsIsCheapest ? ' cheapest' : ''}">
          <div class="card-header bg-${snsIsCheapest ? 'success' : 'info'} text-white d-flex justify-content-between align-items-center">
            <span>🏪 Shop&amp;Ship</span>
            ${snsIsCheapest ? '<span class="badge bg-light text-success badge-cheapest">✓ Cheapest</span>' : ''}
          </div>
          <div class="card-body">
            <div class="row text-center">
              <div class="col-3"><div class="small text-muted">Shipping</div><strong>AED ${fmt(sns.shippingAED)}</strong></div>
              <div class="col-3"><div class="small text-muted">VAT 5%</div><strong>AED ${fmt(sns.vat)}</strong></div>
              <div class="col-3"><div class="small text-muted">Customs</div><strong>AED ${fmt(sns.customs)}</strong></div>
              <div class="col-3"><div class="small text-muted">Total</div><strong class="text-${snsIsCheapest ? 'success' : 'info'}">AED ${fmt(sns.total)}</strong></div>
            </div>
          </div>
        </div>`;

        document.getElementById('calcResults').innerHTML = html;
    }

    function doCalculate() {
        const brandSelect = document.getElementById('brand_id');
        const brandId     = brandSelect.value;
        const priceUSD    = document.getElementById('price_usd').value;
        const weight      = document.getElementById('weight').value;
        const l           = document.getElementById('dim_l').value;
        const w           = document.getElementById('dim_w').value;
        const h           = document.getElementById('dim_h').value;

        if (!brandId || !priceUSD || !weight || !l || !w || !h) return;
        if (parseFloat(priceUSD) <= 0 || parseFloat(weight) <= 0) return;
        if (parseFloat(l) <= 0 || parseFloat(w) <= 0 || parseFloat(h) <= 0) return;

        const body = new URLSearchParams({brand_id: brandId, price_usd: priceUSD, weight, l, w, h});

        fetch('/calculate.php', {method: 'POST', body})
            .then(r => r.json())
            .then(data => { if (!data.error) renderResults(data); })
            .catch(() => {});
    }

    function onInput() {
        toggleSubmission();
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(doCalculate, 300);
    }

    ['brand_id','price_usd','weight','dim_l','dim_w','dim_h'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', onInput);
    });
})();
</script>
</body>
</html>
