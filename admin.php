<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/db.php';

requireAdmin();

$successMsg = '';
$errorMsg   = '';

// Handle tracking number save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['save_tracking'])) {
    $orderId    = (int)($_POST['order_id'] ?? 0);
    $trackingNo = trim($_POST['tracking_no'] ?? '');

    if ($orderId > 0) {
        $newStatus = $trackingNo !== '' ? 'Ordered' : 'Pending';
        $stmt = $pdo->prepare("UPDATE orders SET tracking_no = ?, status = ? WHERE id = ?");
        $stmt->execute([$trackingNo ?: null, $newStatus, $orderId]);
        $successMsg = "Order #$orderId updated successfully.";
    }
}

// Counts
$totalCount   = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'Pending'")->fetchColumn();
$orderedCount = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'Ordered'")->fetchColumn();

// Fetch orders
$allOrders     = $pdo->query("SELECT o.*, b.name AS brand_name FROM orders o JOIN brands b ON o.brand_id = b.id ORDER BY o.created_at DESC")->fetchAll();
$pendingOrders = array_filter($allOrders, fn($o) => $o['status'] === 'Pending');

$activeTab = $_GET['tab'] ?? 'pending';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Dashboard — Special Order System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <style>
        body { background: #f8f9fa; }
        .stat-card { border-left: 4px solid; }
        .stat-card.total { border-left-color: #0d6efd; }
        .stat-card.pending { border-left-color: #ffc107; }
        .stat-card.ordered { border-left-color: #198754; }
        .tracking-form { display: flex; gap: .5rem; min-width: 200px; }
        .tracking-form input { flex: 1; }
        th { white-space: nowrap; }
        .table td { vertical-align: middle; }
    </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-dark bg-dark mb-4">
    <div class="container-fluid">
        <a class="navbar-brand" href="/admin.php">🔧 Admin Dashboard</a>
        <div class="d-flex align-items-center gap-3">
            <a class="btn btn-outline-light btn-sm" href="/index.php">+ New Order</a>
            <span class="text-light">
                <?= htmlspecialchars($_SESSION['username']) ?>
                <span class="badge bg-warning text-dark ms-1">admin</span>
            </span>
            <a class="btn btn-outline-danger btn-sm" href="/logout.php">Logout</a>
        </div>
    </div>
</nav>

<div class="container-fluid px-4 pb-5">

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

    <!-- Stats Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card stat-card total shadow-sm">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small">Total Orders</div>
                            <div class="fs-3 fw-bold text-primary"><?= $totalCount ?></div>
                        </div>
                        <div class="fs-2">📋</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card stat-card pending shadow-sm">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small">Pending</div>
                            <div class="fs-3 fw-bold text-warning"><?= $pendingCount ?></div>
                        </div>
                        <div class="fs-2">⏳</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card stat-card ordered shadow-sm">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small">Ordered</div>
                            <div class="fs-3 fw-bold text-success"><?= $orderedCount ?></div>
                        </div>
                        <div class="fs-2">✅</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-3" id="orderTabs">
        <li class="nav-item">
            <a class="nav-link <?= $activeTab !== 'all' ? 'active' : '' ?>"
               href="?tab=pending">
                Pending Orders
                <span class="badge bg-warning text-dark ms-1"><?= $pendingCount ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'all' ? 'active' : '' ?>"
               href="?tab=all">
                All Orders
                <span class="badge bg-secondary ms-1"><?= $totalCount ?></span>
            </a>
        </li>
    </ul>

    <?php
    $displayOrders = $activeTab === 'all' ? $allOrders : $pendingOrders;
    ?>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <?php if (empty($displayOrders)): ?>
                <div class="text-center py-5 text-muted">
                    <p class="fs-5">No orders found.</p>
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>ID</th>
                            <th>Brand</th>
                            <th>Part No.</th>
                            <th>Link</th>
                            <th>Price USD</th>
                            <th>Weight kg</th>
                            <th>Cost AED</th>
                            <th>Agreed AED</th>
                            <th>PO File</th>
                            <th>Tracking No.</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($displayOrders as $order): ?>
                        <tr>
                            <td><strong>#<?= $order['id'] ?></strong></td>
                            <td><?= htmlspecialchars($order['brand_name']) ?></td>
                            <td><?= htmlspecialchars($order['part_number']) ?></td>
                            <td>
                                <?php if ($order['link']): ?>
                                    <a href="<?= htmlspecialchars($order['link']) ?>" target="_blank" rel="noopener noreferrer">
                                        <small>View</small>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>$<?= number_format($order['price_usd'], 2) ?></td>
                            <td><?= number_format($order['weight'], 3) ?></td>
                            <td>
                                <?= $order['cost_aed'] !== null
                                    ? 'AED ' . number_format($order['cost_aed'], 2)
                                    : '<span class="text-muted">—</span>' ?>
                            </td>
                            <td>
                                <?= $order['agreed_price'] !== null
                                    ? 'AED ' . number_format($order['agreed_price'], 2)
                                    : '<span class="text-muted">—</span>' ?>
                            </td>
                            <td>
                                <?php if ($order['po_path']): ?>
                                    <a href="/<?= htmlspecialchars($order['po_path']) ?>" target="_blank" rel="noopener noreferrer"
                                       class="btn btn-outline-secondary btn-sm">View</a>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="POST" class="tracking-form">
                                    <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                    <input type="text" name="tracking_no" class="form-control form-control-sm"
                                           value="<?= htmlspecialchars($order['tracking_no'] ?? '') ?>"
                                           placeholder="Enter tracking #">
                                    <button type="submit" name="save_tracking" value="1"
                                            class="btn btn-sm btn-primary">Save</button>
                                </form>
                            </td>
                            <td>
                                <?php if ($order['status'] === 'Ordered'): ?>
                                    <span class="badge bg-success">Ordered</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">Pending</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small class="text-muted"><?= htmlspecialchars($order['created_at']) ?></small>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
