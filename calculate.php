<?php
/**
 * calculate.php — AJAX endpoint for live shipping calculation.
 * Accepts POST: brand_id, price_usd, weight, l, w, h
 * Returns JSON with full calculation results.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$brandId  = (int)($_POST['brand_id']  ?? 0);
$priceUSD = (float)($_POST['price_usd'] ?? 0);
$weight   = (float)($_POST['weight']   ?? 0);
$l        = (float)($_POST['l']        ?? 0);
$w        = (float)($_POST['w']        ?? 0);
$h        = (float)($_POST['h']        ?? 0);

if (!$brandId || $priceUSD <= 0 || $weight <= 0 || $l <= 0 || $w <= 0 || $h <= 0) {
    echo json_encode(['error' => 'Invalid or missing parameters']);
    exit;
}

$stmt = $pdo->prepare("SELECT discount_percent FROM brands WHERE id = ?");
$stmt->execute([$brandId]);
$brand = $stmt->fetch();

if (!$brand) {
    echo json_encode(['error' => 'Brand not found']);
    exit;
}

$results = computeFullResults($priceUSD, (float)$brand['discount_percent'], $weight, $l, $w, $h);

echo json_encode($results);
