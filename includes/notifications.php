<?php
/**
 * includes/notifications.php — Order workflow notifications.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/mailer.php';

function notifyAdminNewOrder(array $order, string $creatorName): void
{
    notifyAdmins(
        '[ProcureERP] Order #' . (int)($order['id'] ?? 0) . ' – Action Required',
        buildOrderEmailBody($order, 'A new Draft order was created by ' . $creatorName . '. Please review the order details.'),
        buildOrderEmailText($order, 'A new Draft order was created by ' . $creatorName . '. Please review the order details.')
    );
}

function notifyAdminOrderRequested(array $order, string $creatorName): void
{
    notifyAdmins(
        '[ProcureERP] Order #' . (int)($order['id'] ?? 0) . ' – Action Required',
        buildOrderEmailBody($order, $creatorName . ' submitted the PO and requested approval. Please mark the order as Ordered once reviewed.'),
        buildOrderEmailText($order, $creatorName . ' submitted the PO and requested approval. Please mark the order as Ordered once reviewed.')
    );
}

function notifyEmployeeOrderPlaced(array $order, string $employeeEmail): void
{
    notifyEmployee(
        $employeeEmail,
        '[ProcureERP] Order #' . (int)($order['id'] ?? 0) . ' – Update',
        buildOrderEmailBody($order, 'Your order has been marked as Ordered by admin. Next, admin will add tracking and invoice details.'),
        buildOrderEmailText($order, 'Your order has been marked as Ordered by admin. Next, admin will add tracking and invoice details.')
    );
}

function notifyEmployeeInTransit(array $order, string $employeeEmail, string $trackingNumber): void
{
    notifyEmployee(
        $employeeEmail,
        '[ProcureERP] Order #' . (int)($order['id'] ?? 0) . ' – Update',
        buildOrderEmailBody($order, 'Your order is now In Transit (USA). Tracking number: ' . $trackingNumber . '.'),
        buildOrderEmailText($order, 'Your order is now In Transit (USA). Tracking number: ' . $trackingNumber . '.')
    );
}

function notifyAdminAtForwarder(array $order, string $creatorName): void
{
    notifyAdmins(
        '[ProcureERP] Order #' . (int)($order['id'] ?? 0) . ' – Action Required',
        buildOrderEmailBody($order, $creatorName . ' marked the order as At Forwarder. Monitor for Ship-Out request.'),
        buildOrderEmailText($order, $creatorName . ' marked the order as At Forwarder. Monitor for Ship-Out request.')
    );
}

function notifyAdminShipOutRequested(array $order, string $creatorName): void
{
    notifyAdmins(
        '[ProcureERP] Order #' . (int)($order['id'] ?? 0) . ' – Action Required',
        buildOrderEmailBody($order, $creatorName . ' requested Ship-Out. Please coordinate the shipment to the local shop.'),
        buildOrderEmailText($order, $creatorName . ' requested Ship-Out. Please coordinate the shipment to the local shop.')
    );
}

function notifyAdmins(string $subject, string $bodyHtml, string $bodyText): void
{
    foreach (getAdminEmails() as $email) {
        sendMail($email, $subject, $bodyHtml, $bodyText);
    }
}

function notifyEmployee(string $email, string $subject, string $bodyHtml, string $bodyText): void
{
    $email = trim($email);
    if ($email === '') {
        return;
    }
    sendMail($email, $subject, $bodyHtml, $bodyText);
}

function getAdminEmails(): array
{
    try {
        $pdo = getPDO();
        $stmt = $pdo->query("SELECT email FROM users WHERE role='admin' AND email IS NOT NULL AND email != ''");
        $emails = [];
        foreach (($stmt ? $stmt->fetchAll() : []) as $row) {
            $email = trim((string)($row['email'] ?? ''));
            if ($email !== '') {
                $emails[] = $email;
            }
        }
        return array_values(array_unique($emails));
    } catch (Throwable $e) {
        error_log('getAdminEmails failed: ' . $e->getMessage());
        return [];
    }
}

function buildOrderEmailBody(array $order, string $note): string
{
    $id      = (int)($order['id'] ?? 0);
    $product = htmlspecialchars((string)($order['product_name'] ?? '—'));
    $brand   = htmlspecialchars((string)($order['brand_name'] ?? '—'));
    $status  = htmlspecialchars((string)($order['status'] ?? '—'));
    $note    = htmlspecialchars($note);

    return '<p>Hello,</p>'
        . '<p><strong>Order Update</strong></p>'
        . '<ul style="padding-left:18px;margin:12px 0;">'
        . '<li><strong>Order #:</strong> ' . $id . '</li>'
        . '<li><strong>Product:</strong> ' . $product . '</li>'
        . '<li><strong>Brand:</strong> ' . $brand . '</li>'
        . '<li><strong>Status:</strong> ' . $status . '</li>'
        . '</ul>'
        . '<p><strong>Next Action:</strong> ' . $note . '</p>';
}

function buildOrderEmailText(array $order, string $note): string
{
    return "Order Update\n"
        . 'Order #: ' . (int)($order['id'] ?? 0) . "\n"
        . 'Product: ' . (string)($order['product_name'] ?? '—') . "\n"
        . 'Brand: ' . (string)($order['brand_name'] ?? '—') . "\n"
        . 'Status: ' . (string)($order['status'] ?? '—') . "\n\n"
        . 'Next Action: ' . $note;
}
