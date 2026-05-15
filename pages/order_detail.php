<?php
/**
 * pages/order_detail.php — Full order detail + state-machine actions.
 */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/calc.php';
require_once __DIR__ . '/../includes/notifications.php';
requireLogin();

$pdo  = getPDO();
$user = currentUser();
$id   = (int)($_GET['id'] ?? 0);

// Fetch order
$stmt = $pdo->prepare("
    SELECT o.*, b.name AS brand_name, b.discount_percent AS brand_discount,
           u.username AS created_by_name
    FROM orders o
    LEFT JOIN brands b ON b.id = o.brand_id
    LEFT JOIN users  u ON u.id = o.created_by
    WHERE o.id = ?
");
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order) {
    setFlash('error', 'Order not found.');
    header('Location: ' . app_url('pages/dashboard.php'));
    exit;
}

$creatorEmailStmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$creatorEmailStmt->execute([$order['created_by']]);
$creatorEmail = (string)($creatorEmailStmt->fetchColumn() ?: '');

// Non-admin employees can only view their own orders
if (!isAdmin() && (int)$order['created_by'] !== $user['id']) {
    http_response_code(403);
    die('Access denied.');
}

// ── State-machine transitions ─────────────────────────────────────────────
$ALLOWED_UPLOAD_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];

function handleUpload(string $field, string $prefix): ?string
{
    global $ALLOWED_UPLOAD_TYPES;
    if (empty($_FILES[$field]['tmp_name'])) return null;
    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK) return null;

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $ALLOWED_UPLOAD_TYPES, true)) {
        throw new RuntimeException('Invalid file type. Only PDF, JPEG, PNG, GIF, and WebP allowed.');
    }
    $ext  = match($mime) {
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/gif'       => 'gif',
        'image/webp'      => 'webp',
        default           => 'bin',
    };
    $dest = __DIR__ . '/../uploads/' . $prefix . '_' . uniqid() . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Failed to save uploaded file.');
    }
    return basename($dest);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {

            // Draft → Requested (Employee)
            case 'request':
                if ($order['status'] !== 'Draft') throw new RuntimeException('Invalid transition.');
                $path = handleUpload('customer_po', 'po');
                if (!$path) throw new RuntimeException('Customer PO upload is required.');
                $pdo->prepare("UPDATE orders SET status='Requested', customer_po_path=? WHERE id=?")
                    ->execute([$path, $id]);
                $notifyOrder = $order;
                $notifyOrder['status'] = 'Requested';
                notifyAdminOrderRequested($notifyOrder, $user['username']);
                setFlash('success', 'Order submitted. Waiting for admin to process.');
                break;

            // Requested → Email Sent to Dealer (Admin)
            case 'email_sent':
                requireAdmin();
                if ($order['status'] !== 'Requested') throw new RuntimeException('Invalid transition.');
                $pdo->prepare("UPDATE orders SET status='Email Sent to Dealer' WHERE id=?")
                    ->execute([$id]);
                $notifyOrder = $order;
                $notifyOrder['status'] = 'Email Sent to Dealer';
                notifyEmployeeEmailSentToDealer($notifyOrder, $creatorEmail);
                setFlash('success', 'Email marked as sent to dealer.');
                break;

            // Email Sent to Dealer → Payment Done (Employee)
            case 'payment_done':
                if ($order['status'] !== 'Email Sent to Dealer') throw new RuntimeException('Invalid transition.');
                $pdo->prepare("UPDATE orders SET status='Payment Done' WHERE id=?")
                    ->execute([$id]);
                $notifyOrder = $order;
                $notifyOrder['status'] = 'Payment Done';
                notifyAdminPaymentDone($notifyOrder, (string)($order['created_by_name'] ?? 'Unknown Employee'));
                setFlash('success', 'Payment marked as done.');
                break;

            // Payment Done → In Transit (USA) (Admin)
            case 'mark_in_transit':
                requireAdmin();
                if ($order['status'] !== 'Payment Done') throw new RuntimeException('Invalid transition.');
                $tracking = trim($_POST['tracking_number'] ?? '');
                if (!$tracking) throw new RuntimeException('Tracking number is required.');
                $path = handleUpload('supplier_invoice', 'inv');
                if (!$path) throw new RuntimeException('Supplier Invoice upload is required.');
                $pdo->prepare("UPDATE orders SET status='In Transit (USA)', tracking_number=?, supplier_invoice_path=? WHERE id=?")
                    ->execute([$tracking, $path, $id]);
                $notifyOrder = $order;
                $notifyOrder['status'] = 'In Transit (USA)';
                notifyEmployeeInTransit($notifyOrder, $creatorEmail, $tracking);
                setFlash('success', 'Order marked as In Transit (USA).');
                break;

            // In Transit (USA) → At Forwarder (Employee)
            case 'mark_arrived':
                if ($order['status'] !== 'In Transit (USA)') throw new RuntimeException('Invalid transition.');
                $path = handleUpload('forwarder_doc', 'fwd');
                if (!$path) throw new RuntimeException('Forwarder document upload is required.');
                $pdo->prepare("UPDATE orders SET status='At Forwarder', forwarder_doc_path=? WHERE id=?")
                    ->execute([$path, $id]);
                $notifyOrder = $order;
                $notifyOrder['status'] = 'At Forwarder';
                notifyAdminAtForwarder($notifyOrder, (string)($order['created_by_name'] ?? 'Unknown Employee'));
                setFlash('success', 'Order marked as At Forwarder.');
                break;

            // At Forwarder → Ship-Out Requested (Employee)
            case 'request_shipout':
                if ($order['status'] !== 'At Forwarder') throw new RuntimeException('Invalid transition.');
                $pdo->prepare("UPDATE orders SET status='Ship-Out Requested' WHERE id=?")
                    ->execute([$id]);
                $notifyOrder = $order;
                $notifyOrder['status'] = 'Ship-Out Requested';
                notifyAdminShipOutRequested($notifyOrder, (string)($order['created_by_name'] ?? 'Unknown Employee'));
                setFlash('success', 'Ship-Out requested. Admin has been notified.');
                break;

            default:
                throw new RuntimeException('Unknown action.');
        }
    } catch (RuntimeException $e) {
        setFlash('error', $e->getMessage());
    }
    header('Location: ' . app_url('pages/order_detail.php') . '?id=' . $id);
    exit;
}

// Reload order after any changes
$stmt->execute([$id]);
$order = $stmt->fetch();

// Fetch dealer email from brand (used for Gmail compose URL)
$brandStmt = $pdo->prepare("SELECT dealer_email FROM brands WHERE id = ?");
$brandStmt->execute([$order['brand_id']]);
$dealerEmail = $brandStmt->fetchColumn() ?: '';

// Build Gmail compose URL
$poUrl   = app_url('uploads/' . $order['customer_po_path']);
$subject = urlencode('[PO] Order #' . $order['id'] . ' – ' . ($order['brand_name'] ?? '') . ' – ' . ($order['product_name'] ?? ''));
$body    = urlencode(
    "Dear Dealer,\n\n" .
    "Please find the Purchase Order for the following item:\n\n" .
    "Order #: " . $order['id'] . "\n" .
    "Product: " . ($order['product_name'] ?? '') . "\n" .
    "Brand: " . ($order['brand_name'] ?? '') . "\n" .
    "Total: AED " . number_format((float)$order['total_aed'], 2) . "\n\n" .
    "📎 Download PO: " . $poUrl . "\n\n" .
    "(Please download and attach the PO file from the link above)\n\n" .
    "Best regards"
);
$gmailUrl = "https://mail.google.com/mail/?view=cm" .
    ($dealerEmail ? "&to=" . urlencode($dealerEmail) : "") .
    "&su=" . $subject .
    "&body=" . $body;

// Recompute both carriers for comparison
$usdToAed   = (float)($pdo->query("SELECT value FROM settings WHERE `key`='usd_to_aed'")->fetchColumn() ?: USD_TO_AED);
$snsJson    = $pdo->query("SELECT value FROM settings WHERE `key`='sns_anchors'")->fetchColumn();
$snsAnchors = $snsJson ? json_decode($snsJson, true) : null;
$results    = computeFullResults(
    (float)$order['price_usd'],
    (float)$order['discount_percent'],
    (float)$order['weight_kg'],
    (float)$order['dim_length'],
    (float)$order['dim_width'],
    (float)$order['dim_height'],
    $snsAnchors,
    $usdToAed
);

// Status badge
function statusBadge(string $status): string
{
    $map = [
        'Draft'               => 'bg-gray-100 text-gray-700',
        'Requested'           => 'bg-yellow-100 text-yellow-800',
        'Email Sent to Dealer'=> 'bg-teal-100 text-teal-800',
        'Payment Done'        => 'bg-green-100 text-green-800',
        'In Transit (USA)'    => 'bg-orange-100 text-orange-800',
        'At Forwarder'        => 'bg-purple-100 text-purple-800',
        'Ship-Out Requested'  => 'bg-red-100 text-red-800',
    ];
    $cls = $map[$status] ?? 'bg-gray-100 text-gray-700';
    return "<span class=\"inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold $cls\">"
         . htmlspecialchars($status) . "</span>";
}

function fmt(float $n): string { return number_format($n, 2); }

$pageTitle = 'Order #' . $id;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-4xl mx-auto space-y-6">

  <!-- Header row -->
  <div class="flex flex-wrap items-center justify-between gap-3">
    <div class="flex items-center gap-3">
      <a href="<?= app_url('pages/dashboard.php') ?>" class="text-gray-400 hover:text-gray-600">
        <i data-lucide="arrow-left" class="w-5 h-5"></i>
      </a>
      <h2 class="text-xl font-bold text-gray-800">Order #<?= $id ?></h2>
      <?= statusBadge($order['status']) ?>
    </div>
    <div class="text-sm text-gray-500">
      Created <?= date('d M Y H:i', strtotime($order['created_at'])) ?>
      by <?= htmlspecialchars($order['created_by_name'] ?? '—') ?>
    </div>
  </div>

  <!-- ── Order info card ─────────────────────────────────────────────── -->
  <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
    <h3 class="font-semibold text-gray-700 mb-4">Order Information</h3>
    <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
      <div>
        <dt class="text-gray-500">Brand</dt>
        <dd class="font-medium text-gray-800 mt-0.5"><?= htmlspecialchars($order['brand_name'] ?? '—') ?></dd>
      </div>
      <div>
        <dt class="text-gray-500">Product</dt>
        <dd class="font-medium text-gray-800 mt-0.5"><?= htmlspecialchars($order['product_name'] ?: '—') ?></dd>
      </div>
      <div>
        <dt class="text-gray-500">Price (USD)</dt>
        <dd class="font-medium text-gray-800 mt-0.5">$<?= fmt((float)$order['price_usd']) ?></dd>
      </div>
      <div>
        <dt class="text-gray-500">Discount</dt>
        <dd class="font-medium text-gray-800 mt-0.5"><?= fmt((float)$order['discount_percent']) ?>%</dd>
      </div>
      <div>
        <dt class="text-gray-500">Discounted Price</dt>
        <dd class="font-medium text-gray-800 mt-0.5">AED <?= fmt((float)$order['discounted_price_aed']) ?></dd>
      </div>
      <div>
        <dt class="text-gray-500">Carrier</dt>
        <dd class="font-medium text-gray-800 mt-0.5"><?= htmlspecialchars($order['carrier']) ?></dd>
      </div>
      <div>
        <dt class="text-gray-500">Weight</dt>
        <dd class="font-medium text-gray-800 mt-0.5"><?= fmt((float)$order['weight_kg']) ?> kg</dd>
      </div>
      <div>
        <dt class="text-gray-500">Dimensions</dt>
        <dd class="font-medium text-gray-800 mt-0.5">
          <?= fmt((float)$order['dim_length']) ?> ×
          <?= fmt((float)$order['dim_width']) ?> ×
          <?= fmt((float)$order['dim_height']) ?> cm
        </dd>
      </div>
      <?php if ($order['tracking_number']): ?>
      <div>
        <dt class="text-gray-500">Tracking</dt>
        <dd class="font-medium text-gray-800 mt-0.5"><?= htmlspecialchars($order['tracking_number']) ?></dd>
      </div>
      <?php endif; ?>
    </div>
    <?php if ($order['notes']): ?>
    <div class="mt-4 p-3 bg-gray-50 rounded-lg text-sm text-gray-700">
      <span class="font-medium">Notes:</span> <?= htmlspecialchars($order['notes']) ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── Calculation breakdown (both carriers) ──────────────────────── -->
  <div class="grid sm:grid-cols-2 gap-4">

    <!-- SelfShip PRO -->
    <div class="bg-white rounded-xl shadow-sm border <?= $order['carrier'] === 'SelfShip PRO' ? 'border-indigo-400 ring-2 ring-indigo-300' : 'border-gray-200' ?> p-5">
      <div class="flex items-center justify-between mb-3">
        <h3 class="font-semibold text-gray-700">SelfShip PRO</h3>
        <?php if ($order['carrier'] === 'SelfShip PRO'): ?>
        <span class="text-xs bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded-full font-medium">Selected</span>
        <?php endif; ?>
      </div>

      <?php if (!$results['selfShip']['eligible']): ?>
      <div class="text-sm text-red-600 bg-red-50 p-2 rounded">
        Not eligible: <?= htmlspecialchars(implode(', ', $results['selfShip']['reasons'])) ?>
      </div>
      <?php else: ?>
      <dl class="space-y-1.5 text-sm">
        <div class="flex justify-between"><dt class="text-gray-500">Weight used</dt><dd class="font-medium"><?= $results['weights']['usedTypeSelf'] ?> (<?= fmt($results['weights']['chargeableSelfKg']) ?> kg)</dd></div>
        <div class="flex justify-between"><dt class="text-gray-500">Volumetric kg</dt><dd><?= fmt($results['weights']['volumetricKg']) ?></dd></div>
        <div class="flex justify-between"><dt class="text-gray-500">Shipping</dt><dd>AED <?= fmt($results['selfShip']['shippingAED']) ?></dd></div>
        <div class="flex justify-between"><dt class="text-gray-500">VAT (5%)</dt><dd>AED <?= fmt($results['selfShip']['vat']) ?></dd></div>
        <div class="flex justify-between"><dt class="text-gray-500">Customs</dt><dd>AED <?= fmt($results['selfShip']['customs']) ?></dd></div>
        <div class="flex justify-between pt-1 border-t border-gray-100"><dt class="font-semibold text-gray-800">Total</dt><dd class="font-bold text-gray-900">AED <?= fmt($results['selfShip']['total']) ?></dd></div>
      </dl>
      <?php endif; ?>
    </div>

    <!-- Shop&Ship -->
    <div class="bg-white rounded-xl shadow-sm border <?= $order['carrier'] === 'Shop&Ship' ? 'border-indigo-400 ring-2 ring-indigo-300' : 'border-gray-200' ?> p-5">
      <div class="flex items-center justify-between mb-3">
        <h3 class="font-semibold text-gray-700">Shop&amp;Ship</h3>
        <?php if ($order['carrier'] === 'Shop&Ship'): ?>
        <span class="text-xs bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded-full font-medium">Selected</span>
        <?php endif; ?>
      </div>
      <dl class="space-y-1.5 text-sm">
        <div class="flex justify-between"><dt class="text-gray-500">Chargeable kg</dt><dd><?= fmt($results['weights']['chargeableSnsKg']) ?></dd></div>
        <div class="flex justify-between"><dt class="text-gray-500">Shipping</dt><dd>AED <?= fmt($results['shopAndShip']['shippingAED']) ?></dd></div>
        <div class="flex justify-between"><dt class="text-gray-500">VAT (5%)</dt><dd>AED <?= fmt($results['shopAndShip']['vat']) ?></dd></div>
        <div class="flex justify-between"><dt class="text-gray-500">Customs</dt><dd>AED <?= fmt($results['shopAndShip']['customs']) ?></dd></div>
        <div class="flex justify-between pt-1 border-t border-gray-100"><dt class="font-semibold text-gray-800">Total</dt><dd class="font-bold text-gray-900">AED <?= fmt($results['shopAndShip']['total']) ?></dd></div>
      </dl>
    </div>
  </div>

  <!-- ── Documents ──────────────────────────────────────────────────── -->
  <?php
  $docs = [
    ['label' => 'Customer PO',       'path' => $order['customer_po_path']],
    ['label' => 'Supplier Invoice',  'path' => $order['supplier_invoice_path']],
    ['label' => 'Forwarder Doc',     'path' => $order['forwarder_doc_path']],
  ];
  $hasDocs = array_filter($docs, fn($d) => $d['path']);
  if ($hasDocs):
  ?>
  <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
    <h3 class="font-semibold text-gray-700 mb-3">Documents</h3>
    <div class="flex flex-wrap gap-3">
      <?php foreach ($docs as $doc): ?>
      <?php if ($doc['path']): ?>
      <a href="<?= app_url('uploads/' . htmlspecialchars($doc['path'])) ?>" target="_blank"
         class="inline-flex items-center gap-2 px-3 py-2 bg-indigo-50 text-indigo-700 rounded-lg text-sm hover:bg-indigo-100 transition-colors">
        <i data-lucide="file" class="w-4 h-4"></i>
        <?= htmlspecialchars($doc['label']) ?>
      </a>
      <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── State-machine actions ──────────────────────────────────────── -->
  <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
    <h3 class="font-semibold text-gray-700 mb-4">Actions</h3>

    <?php $status = $order['status']; ?>

    <!-- Draft → Requested (Employee) -->
    <?php if ($status === 'Draft' && (!isAdmin() || (int)$order['created_by'] === $user['id'])): ?>
    <form method="POST" enctype="multipart/form-data" class="space-y-3">
      <input type="hidden" name="action" value="request">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Upload Customer PO (PDF/image) *</label>
        <input type="file" name="customer_po" accept=".pdf,.jpg,.jpeg,.png" required
               class="block w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
      </div>
      <button type="submit"
              class="px-6 py-2.5 bg-yellow-500 hover:bg-yellow-600 text-white font-semibold rounded-lg transition-colors">
        Submit Request
      </button>
    </form>

    <!-- Requested → Email Sent to Dealer (Admin) -->
    <?php elseif ($status === 'Requested' && isAdmin()): ?>
    <div class="space-y-4">
      <!-- Info banner -->
      <div class="flex items-start gap-3 p-4 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-800">
        <i data-lucide="info" class="w-5 h-5 flex-shrink-0 mt-0.5 text-blue-500"></i>
        <div>
          <p class="font-semibold mb-1">Step 1 — Send PO to Dealer</p>
          <p>Click the button below to open Gmail with the order details pre-filled. Download the PO from the link in the email body, attach it, then send.</p>
        </div>
      </div>

      <!-- Gmail button -->
      <a href="<?= htmlspecialchars($gmailUrl) ?>" target="_blank"
         class="inline-flex items-center gap-2 px-5 py-2.5 bg-white border-2 border-red-400 text-red-600 font-semibold rounded-lg hover:bg-red-50 transition-colors w-full sm:w-auto justify-center sm:justify-start">
        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M24 5.457v13.909c0 .904-.732 1.636-1.636 1.636h-3.819V11.73L12 16.64l-6.545-4.91v9.273H1.636A1.636 1.636 0 0 1 0 19.366V5.457c0-2.023 2.309-3.178 3.927-1.964L5.455 4.64 12 9.548l6.545-4.910 1.528-1.145C21.69 2.28 24 3.434 24 5.457z" fill="#EA4335"/>
        </svg>
        Open Gmail to Email Dealer
      </a>

      <!-- Step 2 -->
      <div class="border-t border-gray-200 pt-4">
        <p class="text-sm text-gray-600 mb-3">
          <strong>Step 2 —</strong> After sending the email to the dealer, click below to update the order status.
        </p>
        <form method="POST">
          <input type="hidden" name="action" value="email_sent">
          <button type="submit"
                  class="px-6 py-2.5 bg-teal-600 hover:bg-teal-700 text-white font-semibold rounded-lg transition-colors flex items-center gap-2">
            <i data-lucide="check" class="w-4 h-4"></i>
            Mark Email Sent to Dealer
          </button>
        </form>
      </div>
    </div>

    <!-- Email Sent to Dealer → Payment Done (Employee) -->
    <?php elseif ($status === 'Email Sent to Dealer' && !isAdmin()): ?>
    <div class="space-y-3">
      <div class="flex items-start gap-3 p-4 bg-teal-50 border border-teal-200 rounded-lg text-sm text-teal-800">
        <i data-lucide="mail-check" class="w-5 h-5 flex-shrink-0 mt-0.5 text-teal-600"></i>
        <p>The admin has sent the PO to the dealer. Once you have completed the payment, click below to proceed.</p>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="payment_done">
        <button type="submit"
                class="px-6 py-2.5 bg-green-600 hover:bg-green-700 text-white font-semibold rounded-lg transition-colors flex items-center gap-2">
          <i data-lucide="credit-card" class="w-4 h-4"></i>
          Mark Payment Done
        </button>
      </form>
    </div>

    <!-- Payment Done → In Transit (USA) (Admin) -->
    <?php elseif ($status === 'Payment Done' && isAdmin()): ?>
    <form method="POST" enctype="multipart/form-data" class="space-y-3">
      <input type="hidden" name="action" value="mark_in_transit">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Tracking Number *</label>
        <input type="text" name="tracking_number" required
               class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
               placeholder="e.g. 1Z999AA10123456784">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Upload Supplier Invoice (PDF) *</label>
        <input type="file" name="supplier_invoice" accept=".pdf,.jpg,.jpeg,.png" required
               class="block w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
      </div>
      <button type="submit"
              class="px-6 py-2.5 bg-orange-500 hover:bg-orange-600 text-white font-semibold rounded-lg transition-colors">
        Mark In Transit (USA)
      </button>
    </form>

    <!-- In Transit (USA) → At Forwarder (Employee) -->
    <?php elseif ($status === 'In Transit (USA)' && !isAdmin()): ?>
    <form method="POST" enctype="multipart/form-data" class="space-y-3">
      <input type="hidden" name="action" value="mark_arrived">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Upload Forwarder Document *</label>
        <input type="file" name="forwarder_doc" accept=".pdf,.jpg,.jpeg,.png" required
               class="block w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
      </div>
      <button type="submit"
              class="px-6 py-2.5 bg-purple-600 hover:bg-purple-700 text-white font-semibold rounded-lg transition-colors">
        Mark Arrived at Forwarder
      </button>
    </form>

    <!-- At Forwarder → Ship-Out Requested (Employee) -->
    <?php elseif ($status === 'At Forwarder' && !isAdmin()): ?>
    <form method="POST" class="space-y-3">
      <input type="hidden" name="action" value="request_shipout">
      <p class="text-sm text-gray-600">Notify admin that this shipment is ready to ship out.</p>
      <button type="submit"
              class="px-6 py-2.5 bg-red-600 hover:bg-red-700 text-white font-semibold rounded-lg transition-colors">
        Request Ship-Out
      </button>
    </form>

    <?php else: ?>

    <?php if ($status === 'Draft'): ?>
    <!-- Admin viewing a Draft order owned by someone else -->
    <div class="flex items-start gap-3 p-4 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-800">
      <i data-lucide="clock" class="w-5 h-5 flex-shrink-0 mt-0.5 text-amber-500"></i>
      <p>Waiting for <strong><?= htmlspecialchars($order['created_by_name'] ?? 'the employee') ?></strong>
         to attach the Customer PO and submit this request.</p>
    </div>

    <?php elseif ($status === 'Requested'): ?>
    <!-- Employee waiting for admin to send email to dealer -->
    <div class="flex items-start gap-3 p-4 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-800">
      <i data-lucide="clock" class="w-5 h-5 flex-shrink-0 mt-0.5 text-amber-500"></i>
      <p>Your request has been submitted with the Customer PO.
         Waiting for admin to send the PO email to the dealer.</p>
    </div>

    <?php elseif ($status === 'Email Sent to Dealer'): ?>
    <!-- Admin waiting for employee to confirm payment -->
    <div class="flex items-start gap-3 p-4 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-800">
      <i data-lucide="clock" class="w-5 h-5 flex-shrink-0 mt-0.5 text-amber-500"></i>
      <p>Email has been sent to the dealer. Waiting for the employee to confirm payment.</p>
    </div>

    <?php elseif ($status === 'Payment Done'): ?>
    <!-- Employee waiting for admin to enter tracking / invoice -->
    <div class="flex items-start gap-3 p-4 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-800">
      <i data-lucide="clock" class="w-5 h-5 flex-shrink-0 mt-0.5 text-amber-500"></i>
      <p>Payment confirmed. Waiting for admin to enter tracking number and upload invoice.</p>
    </div>

    <?php elseif ($status === 'In Transit (USA)'): ?>
    <!-- Admin waiting for employee to mark arrived -->
    <div class="flex items-start gap-3 p-4 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-800">
      <i data-lucide="clock" class="w-5 h-5 flex-shrink-0 mt-0.5 text-amber-500"></i>
      <p>Shipment is in transit. Waiting for the employee to upload the forwarder document
         and mark the package as <strong>Arrived at Forwarder</strong>.</p>
    </div>

    <?php elseif ($status === 'At Forwarder'): ?>
    <!-- Admin waiting for employee to request ship-out -->
    <div class="flex items-start gap-3 p-4 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-800">
      <i data-lucide="clock" class="w-5 h-5 flex-shrink-0 mt-0.5 text-amber-500"></i>
      <p>Package is at the forwarder. Waiting for the employee to request
         <strong>Ship-Out</strong> when the shipment is ready to move to the local shop.</p>
    </div>

    <?php elseif ($status === 'Ship-Out Requested'): ?>
    <?php if (isAdmin()): ?>
    <!-- Admin: ship-out requested, coordinate delivery -->
    <div class="flex items-start gap-3 p-4 bg-red-50 border border-red-200 rounded-lg text-sm text-red-800">
      <i data-lucide="truck" class="w-5 h-5 flex-shrink-0 mt-0.5 text-red-500"></i>
      <p><strong>Ship-Out Requested</strong> — The employee has requested this shipment be
         sent to the local shop. Please coordinate and arrange delivery.</p>
    </div>
    <?php else: ?>
    <!-- Employee: awaiting final delivery -->
    <div class="flex items-start gap-3 p-4 bg-green-50 border border-green-200 rounded-lg text-sm text-green-800">
      <i data-lucide="check-circle" class="w-5 h-5 flex-shrink-0 mt-0.5 text-green-500"></i>
      <p>Ship-Out has been requested. Admin has been notified and will coordinate
         delivery to the local shop.</p>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <p class="text-sm text-gray-500 italic">No actions available for this status.</p>
    <?php endif; ?>

    <?php endif; ?>
  </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
