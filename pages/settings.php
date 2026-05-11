<?php
/**
 * pages/settings.php — Admin: edit USD rate + SNS anchor prices.
 */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/mailer.php';
requireAdmin();

$pdo = getPDO();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    $saveSmtpSettings = static function (PDO $pdo, array $values): void {
        $stmt = $pdo->prepare("INSERT INTO settings (`key`, value) VALUES (?, ?)
                               ON DUPLICATE KEY UPDATE value = VALUES(value)");
        foreach ($values as $key => $value) {
            $stmt->execute([$key, $value]);
        }
    };

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
    } elseif ($action === 'save_smtp' || $action === 'test_smtp') {
        $smtpEnabled = isset($_POST['smtp_enabled']) ? '1' : '0';
        $smtpHost    = trim($_POST['smtp_host'] ?? '');
        $smtpPort    = (int)($_POST['smtp_port'] ?? 587);
        $smtpEnc     = strtolower(trim($_POST['smtp_encryption'] ?? 'tls'));
        $smtpUser    = trim($_POST['smtp_user'] ?? '');
        $smtpPass    = (string)($_POST['smtp_pass'] ?? '');
        $smtpFrom    = trim($_POST['smtp_from_name'] ?? 'ProcureERP');
        $smtpFromEml = trim($_POST['smtp_from_email'] ?? '');

        if ($smtpPort <= 0 || $smtpPort > 65535) {
            setFlash('error', 'SMTP port must be between 1 and 65535.');
            header('Location: ' . app_url('pages/settings.php'));
            exit;
        }
        if (!in_array($smtpEnc, ['tls', 'ssl', 'none'], true)) {
            $smtpEnc = 'tls';
        }
        if ($smtpFrom === '') {
            $smtpFrom = 'ProcureERP';
        }
        if ($smtpFromEml !== '' && !filter_var($smtpFromEml, FILTER_VALIDATE_EMAIL)) {
            setFlash('error', 'From Email must be a valid email address.');
            header('Location: ' . app_url('pages/settings.php'));
            exit;
        }

        $saveSmtpSettings($pdo, [
            'smtp_enabled' => $smtpEnabled,
            'smtp_host' => $smtpHost,
            'smtp_port' => (string)$smtpPort,
            'smtp_encryption' => $smtpEnc,
            'smtp_user' => $smtpUser,
            'smtp_pass' => $smtpPass,
            'smtp_from_name' => $smtpFrom,
            'smtp_from_email' => $smtpFromEml,
        ]);

        if ($action === 'save_smtp') {
            setFlash('success', 'SMTP settings updated.');
        } else {
            $meStmt = $pdo->prepare('SELECT email FROM users WHERE id = ?');
            $meStmt->execute([currentUser()['id']]);
            $adminEmail = trim((string)($meStmt->fetchColumn() ?: ''));

            if ($adminEmail === '') {
                setFlash('error', 'Your account has no email configured. Add your email in User Management first.');
            } else {
                $ok = sendMail(
                    $adminEmail,
                    '[ProcureERP] SMTP Test Email',
                    '<p>This is a test email from ProcureERP SMTP settings.</p><p>If you received this, SMTP is working.</p>',
                    "This is a test email from ProcureERP SMTP settings.\nIf you received this, SMTP is working."
                );
                setFlash($ok ? 'success' : 'error', $ok ? 'Test email sent successfully.' : 'Failed to send test email. Check SMTP settings.');
            }
        }
    }

    header('Location: ' . app_url('pages/settings.php'));
    exit;
}

// Load current values
$usdToAed = $pdo->query("SELECT value FROM settings WHERE `key`='usd_to_aed'")->fetchColumn() ?: '3.6725';
$snsJson  = $pdo->query("SELECT value FROM settings WHERE `key`='sns_anchors'")->fetchColumn();
$anchors  = $snsJson ? json_decode($snsJson, true) : [];
$smtpEnabled   = $pdo->query("SELECT value FROM settings WHERE `key`='smtp_enabled'")->fetchColumn() ?: '0';
$smtpHost      = $pdo->query("SELECT value FROM settings WHERE `key`='smtp_host'")->fetchColumn() ?: '';
$smtpPort      = $pdo->query("SELECT value FROM settings WHERE `key`='smtp_port'")->fetchColumn() ?: '587';
$smtpEnc       = $pdo->query("SELECT value FROM settings WHERE `key`='smtp_encryption'")->fetchColumn() ?: 'tls';
$smtpUser      = $pdo->query("SELECT value FROM settings WHERE `key`='smtp_user'")->fetchColumn() ?: '';
$smtpPass      = $pdo->query("SELECT value FROM settings WHERE `key`='smtp_pass'")->fetchColumn() ?: '';
$smtpFromName  = $pdo->query("SELECT value FROM settings WHERE `key`='smtp_from_name'")->fetchColumn() ?: 'ProcureERP';
$smtpFromEmail = $pdo->query("SELECT value FROM settings WHERE `key`='smtp_from_email'")->fetchColumn() ?: '';

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

  <!-- SMTP Configuration -->
  <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
    <h3 class="font-semibold text-gray-700 mb-4 flex items-center gap-2">
      <i data-lucide="mail" class="w-5 h-5 text-indigo-600"></i>
      SMTP Configuration
    </h3>

    <form method="POST" class="space-y-4">
      <div class="flex items-center justify-between rounded-lg border border-gray-200 p-3">
        <div>
          <p class="font-medium text-gray-800">Enable SMTP</p>
          <p class="text-xs text-gray-500">Use SMTP instead of PHP mail().</p>
        </div>
        <label class="inline-flex items-center cursor-pointer">
          <input type="checkbox" name="smtp_enabled" value="1" class="sr-only peer" <?= $smtpEnabled === '1' ? 'checked' : '' ?>>
          <span class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:bg-indigo-600 relative after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:after:translate-x-full"></span>
        </label>
      </div>

      <div class="grid sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">SMTP Host</label>
          <input type="text" name="smtp_host" value="<?= htmlspecialchars($smtpHost) ?>" placeholder="smtp.gmail.com"
                 class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">SMTP Port</label>
          <input type="number" name="smtp_port" value="<?= htmlspecialchars($smtpPort) ?>" min="1" max="65535"
                 class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none">
        </div>
      </div>

      <div class="grid sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Encryption</label>
          <select name="smtp_encryption"
                  class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none bg-white">
            <option value="tls" <?= $smtpEnc === 'tls' ? 'selected' : '' ?>>tls</option>
            <option value="ssl" <?= $smtpEnc === 'ssl' ? 'selected' : '' ?>>ssl</option>
            <option value="none" <?= $smtpEnc === 'none' ? 'selected' : '' ?>>none</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Username</label>
          <input type="text" name="smtp_user" value="<?= htmlspecialchars($smtpUser) ?>" placeholder="mailer@company.com"
                 class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none">
        </div>
      </div>

      <div class="grid sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
          <div class="relative">
            <input type="password" id="smtp-pass" name="smtp_pass" value="<?= htmlspecialchars($smtpPass) ?>"
                   class="w-full px-3 py-2.5 pr-11 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none">
            <button type="button" onclick="toggleSmtpPassword()" class="absolute inset-y-0 right-0 px-3 text-gray-500 hover:text-gray-700" aria-label="Toggle password visibility">
              <i data-lucide="eye" id="smtp-pass-eye-open" class="w-4 h-4"></i>
              <i data-lucide="eye-off" id="smtp-pass-eye-closed" class="w-4 h-4 hidden"></i>
            </button>
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">From Name</label>
          <input type="text" name="smtp_from_name" value="<?= htmlspecialchars($smtpFromName) ?>"
                 class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none">
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">From Email</label>
        <input type="text" name="smtp_from_email" value="<?= htmlspecialchars($smtpFromEmail) ?>" placeholder="no-reply@company.com"
               class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none">
      </div>

      <div class="flex flex-wrap gap-3">
        <button type="submit" name="action" value="test_smtp"
                class="px-5 py-2.5 bg-slate-100 text-slate-700 hover:bg-slate-200 font-medium rounded-lg transition-colors">
          Send Test Email
        </button>
        <button type="submit" name="action" value="save_smtp"
                class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-lg transition-colors">
          Save SMTP Settings
        </button>
      </div>
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

function toggleSmtpPassword() {
  const input = document.getElementById('smtp-pass');
  const eyeOpen = document.getElementById('smtp-pass-eye-open');
  const eyeClosed = document.getElementById('smtp-pass-eye-closed');
  if (!input || !eyeOpen || !eyeClosed) return;
  if (input.type === 'password') {
    input.type = 'text';
    eyeOpen.classList.add('hidden');
    eyeClosed.classList.remove('hidden');
  } else {
    input.type = 'password';
    eyeClosed.classList.add('hidden');
    eyeOpen.classList.remove('hidden');
  }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
