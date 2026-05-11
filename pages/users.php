<?php
/**
 * pages/users.php — Admin: create and manage employee/admin accounts.
 */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
requireAdmin();

$pdo    = getPDO();
$selfId = currentUser()['id'];

// ── Actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = $_POST['role'] ?? 'employee';

        if ($username === '') {
            setFlash('error', 'Username is required.');
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash('error', 'Please enter a valid email address.');
        } elseif (strlen($password) < 6) {
            setFlash('error', 'Password must be at least 6 characters.');
        } elseif (!in_array($role, ['admin', 'employee'], true)) {
            setFlash('error', 'Invalid role selected.');
        } else {
            try {
                $pdo->prepare('INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)')
                    ->execute([$username, $email ?: null, password_hash($password, PASSWORD_DEFAULT), $role]);
                setFlash('success', "User \"" . htmlspecialchars($username) . "\" created successfully.");
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    setFlash('error', 'Username already exists. Please choose a different username.');
                } else {
                    setFlash('error', 'Database error. Please try again.');
                }
            }
        }

    } elseif ($action === 'edit') {
        $uid      = (int)($_POST['id'] ?? 0);
        $email    = trim($_POST['email'] ?? '');
        $role     = $_POST['role'] ?? '';
        $password = $_POST['password'] ?? '';

        if ($uid <= 0 || !in_array($role, ['admin', 'employee'], true)) {
            setFlash('error', 'Invalid input.');
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash('error', 'Please enter a valid email address.');
        } else {
            if ($password !== '') {
                if (strlen($password) < 6) {
                    setFlash('error', 'New password must be at least 6 characters.');
                    header('Location: ' . app_url('pages/users.php'));
                    exit;
                }
                $pdo->prepare('UPDATE users SET email=?, role=?, password_hash=? WHERE id=?')
                    ->execute([$email ?: null, $role, password_hash($password, PASSWORD_DEFAULT), $uid]);
            } else {
                $pdo->prepare('UPDATE users SET email=?, role=? WHERE id=?')
                    ->execute([$email ?: null, $role, $uid]);
            }
            setFlash('success', 'User updated successfully.');
        }

    } elseif ($action === 'delete') {
        $uid = (int)($_POST['id'] ?? 0);

        if ($uid === $selfId) {
            setFlash('error', 'You cannot delete your own account.');
        } else {
            $orderCountStmt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE created_by = ?');
            $orderCountStmt->execute([$uid]);
            if ((int)$orderCountStmt->fetchColumn() > 0) {
                setFlash('error', 'Cannot delete a user who has existing orders.');
            } else {
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
                setFlash('success', 'User deleted.');
            }
        }
    }

    header('Location: ' . app_url('pages/users.php'));
    exit;
}

$users = $pdo->query(
    'SELECT id, username, email, role, created_at FROM users ORDER BY role DESC, username ASC'
)->fetchAll();

$pageTitle = 'User Management';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-3xl mx-auto space-y-6">

  <!-- ── Create new user ─────────────────────────────────────────────── -->
  <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
    <h3 class="font-semibold text-gray-700 mb-4 flex items-center gap-2">
      <i data-lucide="user-plus" class="w-5 h-5 text-indigo-600"></i>
      Create New Account
    </h3>
    <form method="POST" class="space-y-4">
      <input type="hidden" name="action" value="add">
      <div class="grid sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Username *</label>
          <input type="text" name="username" required autocomplete="off"
                 class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                 placeholder="e.g. john.doe">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
          <input type="email" name="email" autocomplete="off"
                 class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                 placeholder="e.g. john@company.com">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Password * <span class="font-normal text-gray-400">(min 6 chars)</span></label>
          <input type="password" name="password" required minlength="6" autocomplete="new-password"
                 class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
                 placeholder="Minimum 6 characters">
        </div>
      </div>
      <div class="flex flex-wrap items-end gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Role *</label>
          <select name="role"
                  class="px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none bg-white">
            <option value="employee">Employee</option>
            <option value="admin">Admin</option>
          </select>
        </div>
        <button type="submit"
                class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-lg transition-colors">
          Create Account
        </button>
      </div>
    </form>
  </div>

  <!-- ── Users table ─────────────────────────────────────────────────── -->
  <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-100">
      <h3 class="font-semibold text-gray-700">All Accounts (<?= count($users) ?>)</h3>
    </div>

    <?php if (empty($users)): ?>
    <div class="text-center py-10 text-gray-400">
      <i data-lucide="users" class="w-10 h-10 mx-auto mb-2"></i>
      <p>No users found.</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
      <table class="min-w-full divide-y divide-gray-100 text-sm">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-6 py-3 text-left font-semibold text-gray-600">#</th>
            <th class="px-6 py-3 text-left font-semibold text-gray-600">Username</th>
            <th class="px-6 py-3 text-left font-semibold text-gray-600">Email</th>
            <th class="px-6 py-3 text-left font-semibold text-gray-600">Role</th>
            <th class="px-6 py-3 text-left font-semibold text-gray-600">Created</th>
            <th class="px-6 py-3"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($users as $u): ?>
          <tr class="hover:bg-gray-50 transition-colors">
            <td class="px-6 py-3 text-gray-500"><?= (int)$u['id'] ?></td>
            <td class="px-6 py-3">
              <span class="font-medium text-gray-800"><?= htmlspecialchars($u['username']) ?></span>
              <?php if ((int)$u['id'] === $selfId): ?>
              <span class="ml-2 text-xs bg-indigo-100 text-indigo-700 px-1.5 py-0.5 rounded">You</span>
              <?php endif; ?>
            </td>
            <td class="px-6 py-3 text-gray-600"><?= htmlspecialchars($u['email'] ?: '—') ?></td>
            <td class="px-6 py-3">
              <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold
                           <?= $u['role'] === 'admin' ? 'bg-indigo-100 text-indigo-700' : 'bg-green-100 text-green-700' ?>">
                <?= htmlspecialchars($u['role']) ?>
              </span>
            </td>
            <td class="px-6 py-3 text-gray-500"><?= date('d M Y', strtotime($u['created_at'])) ?></td>
            <td class="px-6 py-3">
              <div class="flex items-center gap-2">
                <button data-uid="<?= (int)$u['id'] ?>"
                        data-urole="<?= htmlspecialchars($u['role']) ?>"
                        data-uemail="<?= htmlspecialchars((string)($u['email'] ?? '')) ?>"
                        onclick="openEdit(this.dataset.uid, this.dataset.urole, this.dataset.uemail)"
                        class="p-1.5 text-indigo-600 hover:bg-indigo-50 rounded transition-colors" title="Edit">
                  <i data-lucide="pencil" class="w-4 h-4"></i>
                </button>
                <?php if ((int)$u['id'] !== $selfId): ?>
                <form method="POST"
                      data-username="<?= htmlspecialchars($u['username']) ?>"
                      class="inline delete-user-form">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <button type="submit" class="p-1.5 text-red-500 hover:bg-red-50 rounded transition-colors" title="Delete">
                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                  </button>
                </form>
                <?php else: ?>
                <span class="p-1.5 text-gray-300 cursor-not-allowed" title="Cannot delete your own account">
                  <i data-lucide="trash-2" class="w-4 h-4"></i>
                </span>
                <?php endif; ?>
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

<!-- ── Edit user modal ────────────────────────────────────────────────── -->
<div id="edit-modal" class="fixed inset-0 z-50 hidden bg-black/40 flex items-center justify-center p-4">
  <div class="bg-white rounded-xl shadow-xl w-full max-w-md p-6">
    <h3 class="font-semibold text-gray-800 mb-4">Edit Account</h3>
    <form method="POST" class="space-y-4">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" id="edit-id">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
        <input type="email" name="email" id="edit-email"
               class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
               placeholder="e.g. john@company.com">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Role</label>
        <select name="role" id="edit-role"
                class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none bg-white">
          <option value="employee">Employee</option>
          <option value="admin">Admin</option>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">
          New Password
          <span class="font-normal text-gray-400">(leave blank to keep current)</span>
        </label>
        <input type="password" name="password" minlength="6" autocomplete="new-password"
               class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
               placeholder="Leave blank to keep current password">
      </div>
      <div class="flex gap-3 pt-1">
        <button type="button" onclick="closeEditModal()"
                class="flex-1 py-2.5 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors">
          Cancel
        </button>
        <button type="submit"
                class="flex-1 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-lg transition-colors">
          Save Changes
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function openEdit(id, role, email) {
  document.getElementById('edit-id').value    = id;
  document.getElementById('edit-email').value = email || '';
  document.getElementById('edit-role').value  = role;
  document.getElementById('edit-modal').classList.remove('hidden');
}
function closeEditModal() {
  document.getElementById('edit-modal').classList.add('hidden');
}
document.getElementById('edit-modal').addEventListener('click', function (e) {
  if (e.target === this) closeEditModal();
});
document.querySelectorAll('.delete-user-form').forEach(function (form) {
  form.addEventListener('submit', function (e) {
    var name = this.getAttribute('data-username');
    if (!confirm('Delete user "' + name + '"? This cannot be undone.')) {
      e.preventDefault();
    }
  });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
