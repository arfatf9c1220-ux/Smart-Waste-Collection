<?php
/**
 * includes/hotspots.php
 * ------------------------------------------------------------------
 * PREDICTED HOTSPOTS ENGINE (rule based, no ML required)
 *
 * Idea: an area that keeps receiving waste complaints will keep
 * receiving them. So we cluster historical requests geographically and
 * score each cluster by how often / how regularly it reports.
 *
 * For every request in the look-back window we build a cluster:
 *
 *   1. Cluster by proximity - a new request joins an existing cluster when
 *      it falls within HOTSPOT_RADIUS_M of the cluster centre.
 *   2. Report count       -> more reports, higher score
 *   3. Recurrence         -> reports arriving every 2 days are worse than
 *                            3 reports arriving once a month
 *   4. Severity mix       -> hazardous / e-waste clusters are riskier
 *
 *   hotspot_score = count_score(0-45) + recurrence_score(0-30)
 *                   + severity_score(0-25)          => 0-100
 *
 *   >= 65 = HIGH risk      35-64 = MEDIUM risk      < 35 = LOW risk
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

/** Radius check in metres used when assigning a request to a cluster. */
function within_radius(float $lat1, float $lng1, float $lat2, float $lng2, int $radiusM): bool
{
    return (haversine_km($lat1, $lng1, $lat2, $lng2) * 1000) <= $radiusM;
}

/** Risk level from a 0-100 hotspot score. */
function hotspot_risk_level(int $score): string
{
    if ($score >= 65) return 'high';
    if ($score >= 35) return 'medium';
    return 'low';
}

/** Most frequent non-empty fragment of the addresses in a cluster. */
function infer_area_name(array $cluster): string
{
    // 1) Prefer the most common landmark
    $landmarks = [];
    foreach ($cluster as $r) {
        $l = trim((string) ($r['landmark'] ?? ''));
        if ($l !== '') {
            $key = mb_strtolower(preg_replace('/[^a-z0-9 ]/i', '', $l));
            if ($key !== '') {
                $landmarks[$key] = ($landmarks[$key] ?? 0) + 1;
            }
        }
    }
    if ($landmarks) {
        arsort($landmarks);
        $top = array_key_first($landmarks);
        // Return the original casing of the most common landmark.
        foreach ($cluster as $r) {
            if (mb_strtolower(trim((string) $r['landmark'])) === $top) {
                return trim((string) $r['landmark']);
            }
        }
    }

    // 2) Fall back to the most common street fragment from the address
    $streets = [];
    foreach ($cluster as $r) {
        $a = (string) ($r['address'] ?? '');
        if (preg_match_all('/[A-Za-z][A-Za-z ]*(?:Road|Road\b|Street|Street\b|Nagar|Nagar\b|Market|Market\b|Colony|Colony\b|Chowk|Chowk\b|Gali|Gali\b)/i', $a, $m)) {
            foreach ($m[0] as $s) {
                $s = trim($s);
                $streets[mb_strtolower($s)] = ($streets[mb_strtolower($s)] ?? 0) + 1;
            }
        }
    }
    if ($streets) {
        arsort($streets);
        $topStreet = array_key_first($streets);
        foreach ($cluster as $r) {
            if (str_contains(mb_strtolower((string) $r['address']), $topStreet)) {
                return ucfirst($topStreet);
            }
        }
    }

    // 3) Last resort: coordinates
    return 'Zone ' . number_format((float) $cluster[0]['latitude'], 4) . ', ' . number_format((float) $cluster[0]['longitude'], 4);
}

/**
 * Rebuild the hotspots table from the request history.
 *
 * @param int $windowDays How far back to look (default HOTSPOT_WINDOW_DAYS)
 * @return array Stats about the run + the fresh hotspot rows.
 */
function recompute_hotspots(int $windowDays = HOTSPOT_WINDOW_DAYS): array
{
    $rows = db_all(
        'SELECT id, latitude, longitude, address, landmark, waste_type, status, created_at
           FROM waste_requests
          WHERE status <> "cancelled"
            AND is_rejected = 0
            AND created_at >= (NOW() - INTERVAL ? DAY)
            AND latitude IS NOT NULL
          ORDER BY created_at ASC',
        [$windowDays]
    );

    // --- 1. Cluster ---------------------------------------------------------
    $clusters = [];
    foreach ($rows as $r) {
        $lat = (float) $r['latitude'];
        $lng = (float) $r['longitude'];

        $matched = null;
        foreach ($clusters as $i => $c) {
            if (within_radius($c['lat'], $c['lng'], $lat, $lng, HOTSPOT_RADIUS_M)) {
                $matched = $i;
                break;
            }
        }

        if ($matched === null) {
            $clusters[] = [
                'lat' => $lat, 'lng' => $lng,
                'ids' => [$r], 'types' => [],
            ];
        } else {
            $clusters[$matched]['ids'][] = $r;
            $clusters[$matched]['types'][(string) $r['waste_type']] =
                ($clusters[$matched]['types'][(string) $r['waste_type']] ?? 0) + 1;
            // Keep a running average centre.
            $n = count($clusters[$matched]['ids']);
            $clusters[$matched]['lat'] = (($clusters[$matched]['lat'] * ($n - 1)) + $lat) / $n;
            $clusters[$matched]['lng'] = (($clusters[$matched]['lng'] * ($n - 1)) + $lng) / $n;
        }
    }

    // --- 2. Score each cluster ---------------------------------------------
    $result = [];
    foreach ($clusters as $c) {
        $ids   = $c['ids'];
        $count = count($ids);

        // A single report in the window is not a hotspot.
        if ($count < 2) {
            continue;
        }

        // --- Recurrence: average gap in days between reports ----------------
        $times = array_map(fn($r) => strtotime($r['created_at']), $ids);
        sort($times);
        $gaps = [];
        for ($i = 1; $i < count($times); $i++) {
            $gaps[] = max(0.5, ($times[$i] - $times[$i - 1]) / 86400);
        }
        $avgDays = $gaps ? array_sum($gaps) / count($gaps) : 30.0;

        // reports per 30 days, normalised against 10 reports / 30 days
        $countScore = (int) min(45, round($count * 4.5));

        // recurrence: <=1 day => 30 pts, 30 days => 0 pts
        $recurrenceScore = (int) max(0, min(30, round(30 - ($avgDays / 30 * 30))));

        // severity mix
        $sevScore = 0;
        foreach ($ids as $r) {
            $sevScore += waste_severity_weight((string) $r['waste_type']);
        }
        $avgSeverity = $sevScore / $count;                 // 0-30
        $severityScore = (int) min(25, round($avgSeverity / 30 * 25));

        // unsolved reports make a hotspot worse
        $openCount = count(array_filter($ids, fn($r) => !in_array($r['status'], ['verified', 'collected'], true)));
        $openBonus = min(10, (int) round($openCount / $count * 10));

        $score = min(100, $countScore + $recurrenceScore + $severityScore + $openBonus);
        $risk  = hotspot_risk_level($score);

        // Trend: is the area getting worse?
        $trend = $openCount > 0 ? 'rising' : 'stable';

        $result[] = [
            'area_name'            => infer_area_name($ids),
            'latitude'             => round($c['lat'], 6),
            'longitude'            => round($c['lng'], 6),
            'radius_m'             => HOTSPOT_RADIUS_M,
            'report_count'         => $count,
            'open_count'           => $openCount,
            'avg_recurrence_days'  => round($avgDays, 1),
            'hotspot_score'        => $score,
            'risk_level'           => $risk,
            'dominant_waste_type'  => array_key_first($c['types'] ?: ['other' => 1]),
            'trend'                => $trend,
            'window_days'          => $windowDays,
            'first_report_at'      => date('Y-m-d H:i:s', $times[0]),
            'last_report_at'       => date('Y-m-d H:i:s', $times[count($times) - 1]),
        ];
    }

    // Highest score first.
    usort($result, fn($a, $b) => $b['hotspot_score'] <=> $a['hotspot_score']);

    // --- 3. Persist ---------------------------------------------------------
    db_query('DELETE FROM hotspots');

    foreach ($result as $h) {
        db_query(
            'INSERT INTO hotspots
                (area_name, latitude, longitude, radius_m, report_count, open_count,
                 avg_recurrence_days, hotspot_score, risk_level, dominant_waste_type,
                 trend, window_days, first_report_at, last_report_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?, NOW())',
            [
                $h['area_name'], $h['latitude'], $h['longitude'], $h['radius_m'],
                $h['report_count'], $h['open_count'], $h['avg_recurrence_days'],
                $h['hotspot_score'], $h['risk_level'], $h['dominant_waste_type'],
                $h['trend'], $h['window_days'], $h['first_report_at'], $h['last_report_at'],
            ]
        );
    }

    log_activity('hotspot_recompute', 'hotspot', null,
        'Hotspot engine rebuilt ' . count($result) . ' cluster(s) over ' . $windowDays . ' days');

    return [
        'clusters' => count($result),
        'hotspots' => $result,
    ];
}

/** Read the stored hotspots, highest risk first. */
function get_hotspots(?string $risk = null): array
{
    if ($risk) {
        return db_all(
            'SELECT h.*,
                    (SELECT COUNT(*) FROM waste_requests r
                      WHERE r.status <> "cancelled" AND r.is_rejected = 0
                        AND r.created_at >= (NOW() - INTERVAL h.window_days DAY)
                        AND (r.latitude - h.latitude) BETWEEN -0.01 AND 0.01
                        AND (r.longitude - h.longitude) BETWEEN -0.01 AND 0.01) AS live_reports
               FROM hotspots h
              WHERE h.risk_level = ?
              ORDER BY h.hotspot_score DESC',
            [$risk]
        );
    }

    return db_all(
        'SELECT h.*,
                (SELECT COUNT(*) FROM waste_requests r
                  WHERE r.status <> "cancelled" AND r.is_rejected = 0
                    AND r.created_at >= (NOW() - INTERVAL h.window_days DAY)
                    AND (r.latitude - h.latitude) BETWEEN -0.01 AND 0.01
                    AND (r.longitude - h.longitude) BETWEEN -0.01 AND 0.01) AS live_reports
           FROM hotspots h
          ORDER BY h.hotspot_score DESC'
    );
}

/** Colour for a hotspot marker. */
function hotspot_color(string $risk): string
{
    return ['high' => '#dc3545', 'medium' => '#fd7e14', 'low' => '#ffc107'][$risk] ?? '#6c757d';
}
