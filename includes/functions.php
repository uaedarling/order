<?php
/**
 * Calculation functions for the Special Order Management System.
 * Mirrors the JavaScript logic exactly.
 */

const USD_TO_AED = 3.699;

/**
 * Compute actual vs volumetric weight and chargeables for both methods.
 */
function computeWeights(float $actualKg, float $l, float $w, float $h): array
{
    $volumetricKg      = ($l * $w * $h) / 5000;
    $chargeableSelfKg  = (int) ceil(max($actualKg, $volumetricKg));
    $chargeableSnsKg   = ceil($actualKg * 10) / 10;

    return [
        'actualKg'         => $actualKg,
        'volumetricKg'     => $volumetricKg,
        'chargeableSelfKg' => $chargeableSelfKg,
        'chargeableSnsKg'  => $chargeableSnsKg,
    ];
}

/**
 * Check whether this shipment is eligible for SelfShip PRO.
 */
function checkSelfShipLimits(float $actualKg, float $l, float $w, float $h): array
{
    $reasons = [];
    if ($actualKg > 35) {
        $reasons[] = 'Weight exceeds 35 kg limit';
    }
    if ($l > 120) {
        $reasons[] = 'Length exceeds 120 cm limit';
    }
    if ($w > 120) {
        $reasons[] = 'Width exceeds 120 cm limit';
    }
    if ($h > 120) {
        $reasons[] = 'Height exceeds 120 cm limit';
    }
    return ['ok' => empty($reasons), 'reasons' => $reasons];
}

/**
 * Calculate SelfShip PRO shipping cost in USD.
 */
function calculatePROShippingUSD(int $chargeableKg): float
{
    $firstKg      = 7.23;
    $extraKgRate  = 4.85;

    if ($chargeableKg >= 15) {
        $extraKgRate *= 0.95;
    }
    if ($chargeableKg <= 1) {
        return $firstKg;
    }
    return $firstKg + ($chargeableKg - 1) * $extraKgRate;
}

/**
 * Shop&Ship anchor table (units = ceil(actualKg * 10)).
 */
function getSnsAnchors(): array
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

/**
 * Calculate Shop&Ship shipping cost in AED.
 */
function calculateShopAndShipAED(float $chargeableSnsKg): float
{
    $units   = (int) ceil($chargeableSnsKg * 10);
    $anchors = getSnsAnchors();

    if ($units <= 10) {
        return 15 + ($units - 1) * 5;
    }

    // Exact match
    foreach ($anchors as $anchor) {
        if ($anchor['u'] === $units) {
            return (float) $anchor['p'];
        }
    }

    // Linear interpolation between anchors
    $prev = $anchors[0];
    $next = $anchors[count($anchors) - 1];
    foreach ($anchors as $anchor) {
        if ($anchor['u'] <= $units) {
            $prev = $anchor;
        } else {
            $next = $anchor;
            break;
        }
    }

    // Avoid division by zero
    if ($next['u'] === $prev['u']) {
        return (float) $prev['p'];
    }

    $ratio = ($units - $prev['u']) / ($next['u'] - $prev['u']);
    return $prev['p'] + $ratio * ($next['p'] - $prev['p']);
}

/**
 * Build the full results array for both shipping methods.
 */
function computeFullResults(
    float $priceUSD,
    float $discountPercent,
    float $actualKg,
    float $l,
    float $w,
    float $h
): array {
    $weights = computeWeights($actualKg, $l, $w, $h);
    $selfLimits = checkSelfShipLimits($actualKg, $l, $w, $h);

    $discountAmountUSD    = $priceUSD * ($discountPercent / 100);
    $discountedPriceAED   = ($priceUSD - $discountAmountUSD) * USD_TO_AED;

    // --- SelfShip PRO ---
    $selfShip = ['eligible' => $selfLimits['ok'], 'reasons' => $selfLimits['reasons']];
    if ($selfLimits['ok']) {
        $shippingUSD     = calculatePROShippingUSD($weights['chargeableSelfKg']);
        $shippingAED     = $shippingUSD * USD_TO_AED;
        $vat             = ($discountedPriceAED + $shippingAED) * 0.05;
        $customs         = $discountedPriceAED > 1000 ? $discountedPriceAED * 0.05 : 0;
        $total           = $discountedPriceAED + $shippingAED + $vat + $customs;
        $selfShip += [
            'shippingUSD'  => $shippingUSD,
            'shippingAED'  => $shippingAED,
            'vat'          => $vat,
            'customs'      => $customs,
            'total'        => $total,
        ];
    }

    // --- Shop&Ship ---
    $snsShippingAED  = calculateShopAndShipAED($weights['chargeableSnsKg']);
    $snsVat          = ($discountedPriceAED + $snsShippingAED) * 0.05;
    $snsCustoms      = $discountedPriceAED > 1000 ? $discountedPriceAED * 0.05 : 0;
    $snsTotal        = $discountedPriceAED + $snsShippingAED + $snsVat + $snsCustoms;
    $shopAndShip = [
        'shippingAED' => $snsShippingAED,
        'vat'         => $snsVat,
        'customs'     => $snsCustoms,
        'total'       => $snsTotal,
    ];

    // Cheapest
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
