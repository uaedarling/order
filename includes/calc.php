<?php
/**
 * includes/calc.php — All calculation functions (PHP port of the JS logic).
 */

const USD_TO_AED = 3.699;

// ── Weight computation ────────────────────────────────────────────────────

function computeWeights(float $actualKg, float $l, float $w, float $h): array
{
    $volKg    = ($l * $w * $h) / 5000;
    $selfUsed = max($actualKg, $volKg);
    return [
        'actualKg'         => $actualKg,
        'volumetricKg'     => $volKg,
        'usedTypeSelf'     => $volKg > $actualKg ? 'Volumetric' : 'Actual',
        'chargeableSelfKg' => (int) ceil($selfUsed),
        'chargeableSnsKg'  => ceil($actualKg * 10) / 10,
    ];
}

// ── SelfShip PRO eligibility ──────────────────────────────────────────────

function checkSelfShipLimits(float $actualKg, float $l, float $w, float $h): array
{
    $reasons = [];
    if ($actualKg > 35)  $reasons[] = 'Weight exceeds 35 kg limit';
    if ($l > 120)        $reasons[] = 'Length exceeds 120 cm limit';
    if ($w > 120)        $reasons[] = 'Width exceeds 120 cm limit';
    if ($h > 120)        $reasons[] = 'Height exceeds 120 cm limit';
    return ['ok' => empty($reasons), 'reasons' => $reasons];
}

// ── SelfShip PRO cost ─────────────────────────────────────────────────────

function calculatePROShipping(float $weightKg): float
{
    $firstKg   = 7.23;
    $ratePerKg = 4.85;
    $bulkFactor = 0.95;
    if ($weightKg <= 1) return $firstKg;
    $extraKg = $weightKg - 1;
    $rate = $weightKg >= 15 ? $ratePerKg * $bulkFactor : $ratePerKg;
    return $firstKg + $extraKg * $rate;
}

// ── Shop&Ship anchor table ────────────────────────────────────────────────

function getDefaultSnsAnchors(): array
{
    return [
        ['u' => 10,  'p' => 60],
        ['u' => 12,  'p' => 70],
        ['u' => 20,  'p' => 110],
        ['u' => 30,  'p' => 160],
        ['u' => 50,  'p' => 232],
        ['u' => 58,  'p' => 268],
        ['u' => 100, 'p' => 412],
        ['u' => 150, 'p' => 592],
        ['u' => 200, 'p' => 772],
        ['u' => 300, 'p' => 1132],
        ['u' => 400, 'p' => 1492],
        ['u' => 500, 'p' => 1852],
    ];
}

function calculateShopAndShipAED(float $actualKg, ?array $anchors = null): float
{
    if ($anchors === null) $anchors = getDefaultSnsAnchors();
    $units = (int) ceil($actualKg * 10);
    if ($units <= 10) return 15 + ($units - 1) * 5;

    // Exact match
    foreach ($anchors as $a) {
        if ($a['u'] === $units) return (float) $a['p'];
    }

    // Interpolation
    $prev = $anchors[0];
    $next = end($anchors);
    foreach ($anchors as $a) {
        if ($a['u'] < $units) $prev = $a;
        if ($a['u'] > $units) { $next = $a; break; }
    }
    if ($next['u'] === $prev['u']) return (float) $prev['p'];
    $slope = ($next['p'] - $prev['p']) / ($next['u'] - $prev['u']);
    return round($prev['p'] + ($units - $prev['u']) * $slope);
}

// ── Full result computation ───────────────────────────────────────────────

function computeFullResults(
    float $priceUSD,
    float $discountPercent,
    float $actualKg,
    float $l,
    float $w,
    float $h,
    ?array $snsAnchors = null
): array {
    $weights    = computeWeights($actualKg, $l, $w, $h);
    $selfLimits = checkSelfShipLimits($actualKg, $l, $w, $h);

    $discountAmountUSD  = $priceUSD * ($discountPercent / 100);
    $discountedPriceAED = ($priceUSD - $discountAmountUSD) * USD_TO_AED;

    // SelfShip PRO
    $selfShip = ['eligible' => $selfLimits['ok'], 'reasons' => $selfLimits['reasons']];
    if ($selfLimits['ok']) {
        $shippingUSD = calculatePROShipping((float) $weights['chargeableSelfKg']);
        $shippingAED = $shippingUSD * USD_TO_AED;
        $vat         = ($discountedPriceAED + $shippingAED) * 0.05;
        $customs     = $discountedPriceAED > 1000 ? $discountedPriceAED * 0.05 : 0.0;
        $total       = $discountedPriceAED + $shippingAED + $vat + $customs;
        $selfShip   += [
            'shippingUSD' => $shippingUSD,
            'shippingAED' => $shippingAED,
            'vat'         => $vat,
            'customs'     => $customs,
            'total'       => $total,
        ];
    }

    // Shop&Ship
    $snsShippingAED = calculateShopAndShipAED($weights['chargeableSnsKg'], $snsAnchors);
    $snsVat         = ($discountedPriceAED + $snsShippingAED) * 0.05;
    $snsCustoms     = $discountedPriceAED > 1000 ? $discountedPriceAED * 0.05 : 0.0;
    $snsTotal       = $discountedPriceAED + $snsShippingAED + $snsVat + $snsCustoms;
    $shopAndShip    = [
        'shippingAED' => $snsShippingAED,
        'vat'         => $snsVat,
        'customs'     => $snsCustoms,
        'total'       => $snsTotal,
    ];

    $cheapest = 'shopAndShip';
    if ($selfLimits['ok'] && $selfShip['total'] <= $snsTotal) {
        $cheapest = 'selfShip';
    }

    return [
        'discountAmountUSD'  => $discountAmountUSD,
        'discountedPriceAED' => $discountedPriceAED,
        'weights'            => $weights,
        'selfShip'           => $selfShip,
        'shopAndShip'        => $shopAndShip,
        'cheapest'           => $cheapest,
    ];
}
