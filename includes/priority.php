<?php
/**
 * includes/priority.php
 * ------------------------------------------------------------------
 * PRIORITY INTELLIGENCE ENGINE
 *
 * The priority of a request is NOT typed in by a human. It is computed
 * from five weighted factors and normalised to a 0-100 score:
 *
 *   Severity Score        (max 30)  how dangerous / unpleasant the waste is
 *   Quantity Score        (max 20)  how much waste is reported
 *   Location Risk Score   (max 20)  how sensitive the location is
 *   Request Age Score     (max 15)  how long it has been waiting
 *   Repeat Report Score   (max 15)  how often this area already complains
 *                                          --------------------------
 *                                          TOTAL 0 - 100
 *
 *   0-30  Low      31-60  Medium      61-80  High      81-100  Critical
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

/** Weight of each waste category (severity factor). */
function waste_severity_weight(string $wasteType): int
{
    return [
        'hazardous'    => 30,  // chemicals, batteries, medical waste -> public health risk
        'e_waste'      => 26,  // toxic if dumped, valuable if recycled
        'construction' => 22,  // blocks roads and drains
        'organic'      => 18,  // decays fast, breeds flies / mosquitoes
        'other'        => 14,
        'plastic'      => 14,  // chokes drains, blocks water flow
        'general'      => 10,
    ][$wasteType] ?? 12;
}

/** Weight of the reported quantity. */
function quantity_weight(string $quantity): int
{
    return [
        'small'  => 5,   // a bag
        'medium' => 10,  // a trolley / few bags
        'large'  => 16,  // heap on the road
        'bulk'   => 20,  // truck-load, needs a tipper
    ][$quantity] ?? 8;
}

/**
 * Keywords that make a location high risk. Matched against the address
 * and the landmark typed by the citizen.
 * keyword => [risk points, human label]
 */
function location_risk_keywords(): array
{
    return [
        'hospital'      => [10, 'Hospital / medical area'],
        'clinic'        => [10, 'Clinic'],
        'school'        => [9,  'School / college campus'],
        'college'       => [8,  'College campus'],
        'market'        => [8,  'Market area'],
        'mandi'         => [8,  'Market yard'],
        'bus stop'      => [7,  'Bus stop / transit point'],
        'railway'       => [7,  'Railway station'],
        'station'       => [6,  'Public transport station'],
        'temple'        => [6,  'Place of worship'],
        'mosque'        => [6,  'Place of worship'],
        'water'         => [6,  'Water supply point'],
        'river'         => [6,  'Water body'],
        'nala'          => [5,  'Drain / nala'],
        'road'          => [4,  'Main road / junction'],
        'junction'      => [5,  'Road junction'],
        'crossing'      => [5,  'Pedestrian crossing'],
        'park'          => [3,  'Public park'],
        'gate'          => [2,  'Building gate / entrance'],
        'bridge'        => [4,  'Bridge'],
    ];
}

/** Wording for each quantity option (used in the report form + tooltips). */
function quantity_options(): array
{
    return [
        'small'  => 'Small (1-2 bags)',
        'medium' => 'Medium (cart / few bags)',
        'large'  => 'Large (heap on road)',
        'bulk'   => 'Bulk / truck load',
    ];
}

/** All selectable waste categories. */
function waste_options(): array
{
    return [
        'general'      => 'General Waste',
        'plastic'      => 'Plastic',
        'organic'      => 'Organic Waste',
        'e_waste'      => 'E-Waste',
        'construction' => 'Construction Waste',
        'hazardous'    => 'Hazardous Waste',
        'other'        => 'Other',
    ];
}

/** Turn a numeric score into one of the four priority levels. */
function priority_level_for(int $score): string
{
    if ($score >= 81) return 'critical';
    if ($score >= 61) return 'high';
    if ($score >= 31) return 'medium';
    return 'low';
}

/**
 * Count how many other requests were filed near this location recently.
 * Uses a bounding-box pre-filter (fast) + Haversine re-check (accurate).
 *
 * @return array{count:int, days:float}
 */
function nearby_report_stats(float $lat, float $lng, ?int $excludeId = null, int $windowDays = 30): array
{
    if ($lat === 0.0 && $lng === 0.0) {
        return ['count' => 0, 'days' => 0.0];
    }

    // 1 degree latitude ~= 111 km. A 0.02 deg box is safely wider than our
    // 1.2 km hotspot radius, so nothing is missed.
    $sql = 'SELECT latitude, longitude, created_at
              FROM waste_requests
             WHERE latitude BETWEEN ? AND ?
               AND longitude BETWEEN ? AND ?
               AND status <> "cancelled"
               AND created_at >= (NOW() - INTERVAL ? DAY)';
    $params = [$lat - 0.02, $lat + 0.02, $lng - 0.02, $lng + 0.02, $windowDays];

    if ($excludeId) {
        $sql .= ' AND id <> ?';
        $params[] = $excludeId;
    }

    $rows = db_all($sql, $params);

    $count  = 0;
    $recent = [];
    foreach ($rows as $r) {
        $d = haversine_km($lat, $lng, (float) $r['latitude'], (float) $r['longitude']);
        if ($d <= 1.2) {
            $count++;
            $recent[] = strtotime($r['created_at']);
        }
    }

    // Average days between consecutive reports in the area.
    sort($recent);
    $gaps = [];
    for ($i = 1; $i < count($recent); $i++) {
        $gaps[] = ($recent[$i] - $recent[$i - 1]) / 86400;
    }
    $avgDays = $gaps ? array_sum($gaps) / count($gaps) : 0.0;

    return ['count' => $count, 'days' => round($avgDays, 1)];
}

/**
 * MAIN FUNCTION — calculate the full priority breakdown for a request.
 *
 * @param array $r Request row (needs id, waste_type, quantity, address,
 *                 landmark, latitude, longitude, created_at)
 * @return array{score:int, level:string, breakdown:array, reasons:array}
 */
function calculate_priority(array $r): array
{
    $wasteType = (string) ($r['waste_type'] ?? 'general');
    $quantity  = (string) ($r['quantity'] ?? 'small');
    $lat       = (float) ($r['latitude'] ?? 0);
    $lng       = (float) ($r['longitude'] ?? 0);
    $text      = strtolower(trim(($r['address'] ?? '') . ' ' . ($r['landmark'] ?? '')));

    // --- 1. Severity (max 30) ------------------------------------------------
    $severity = waste_severity_weight($wasteType);

    // --- 2. Quantity (max 20) ------------------------------------------------
    $quantityScore = quantity_weight($quantity);

    // --- 3. Location risk (max 20) -------------------------------------------
    $locationScore = 0;
    $locationHits  = [];
    foreach (location_risk_keywords() as $keyword => [$points, $label]) {
        if (str_contains($text, $keyword)) {
            $locationScore += $points;
            $locationHits[] = $label;
        }
    }
    // A busy area (many reports already logged) is itself a risk factor.
    $nearby     = nearby_report_stats($lat, $lng, isset($r['id']) ? (int) $r['id'] : null);
    $densityAdd = min(5, (int) floor($nearby['count'] / 2));  // +5 when 10+ nearby
    $locationScore = min(20, $locationScore + $densityAdd);
    if ($densityAdd > 0) {
        $locationHits[] = 'High reporting density in this area';
    }

    // --- 4. Age of request (max 15) ------------------------------------------
    $ageHours = 0;
    if (!empty($r['created_at'])) {
        $ageHours = max(0, (time() - strtotime($r['created_at'])) / 3600);
    }
    // ramps up quickly: 0h=0, 12h=5, 24h=9, 48h=13, 72h+=15
    $ageScore = (int) min(15, round($ageHours / 72 * 15));

    // --- 5. Repeat reports nearby (max 15) -----------------------------------
    $repeatScore = min(15, $nearby['count'] * 3);

    // --- Combine + clamp to 0-100 --------------------------------------------
    $raw = $severity + $quantityScore + $locationScore + $ageScore + $repeatScore;
    $score = (int) max(0, min(100, $raw));

    // Human readable reasons, used in the admin "why this priority" tooltip.
    $reasons = [];
    $w = waste_meta($wasteType);
    $reasons[] = ['icon' => $w['icon'], 'text' => $w['label'] . ' carries a severity weight of ' . $severity . '/30'];
    $qtyLabel = quantity_options()[$quantity] ?? $quantity;
    $reasons[] = ['icon' => 'boxes', 'text' => 'Reported quantity is ' . $qtyLabel . ' (' . $quantityScore . '/20)'];
    foreach (array_slice(array_unique($locationHits), 0, 3) as $hit) {
        $reasons[] = ['icon' => 'geo-alt', 'text' => 'Sensitive location: ' . $hit];
    }
    $reasons[] = ['icon' => 'clock-history', 'text' => 'Request has been open for ' . round($ageHours) . ' hour(s) (' . $ageScore . '/15)'];
    $reasons[] = ['icon' => 'repeat', 'text' => $nearby['count'] . ' other report(s) logged within 1.2 km in the last 30 days (' . $repeatScore . '/15)'];

    return [
        'score'     => $score,
        'level'     => priority_level_for($score),
        'breakdown' => [
            'severity'       => $severity,
            'severity_max'   => 30,
            'quantity'       => $quantityScore,
            'quantity_max'   => 20,
            'location_risk'  => $locationScore,
            'location_max'   => 20,
            'age'            => $ageScore,
            'age_max'        => 15,
            'repeat'         => $repeatScore,
            'repeat_max'     => 15,
        ],
        'reasons'   => $reasons,
        'nearby'    => $nearby,
    ];
}

/**
 * Recalculate and persist the priority of a request.
 * Call this whenever a request changes in a way that affects its score.
 */
function refresh_request_priority(int $requestId): array
{
    $row = db_row('SELECT * FROM waste_requests WHERE id = ?', [$requestId]);
    if (!$row) {
        return ['score' => 0, 'level' => 'low', 'breakdown' => [], 'reasons' => []];
    }

    $p     = calculate_priority($row);
    $level = $p['level'];

    db_query(
        'UPDATE waste_requests
            SET priority_score = ?, priority_level = ?,
                priority_factors = ?, updated_at = NOW()
          WHERE id = ?',
        [
            $p['score'],
            $level,
            json_encode($p['breakdown']),
            $requestId,
        ]
    );

    return $p;
}

/**
 * Priority score is recalculated automatically on a schedule so that the
 * "age" component keeps increasing even if nobody opens the request.
 * This file is called from api/admin/priority-recalculate.php (and via cron).
 */
function recalculate_all_priorities(): int
{
    $ids = db_all('SELECT id FROM waste_requests WHERE status NOT IN ("verified","cancelled")');
    foreach ($ids as $row) {
        refresh_request_priority((int) $row['id']);
    }
    return count($ids);
}
