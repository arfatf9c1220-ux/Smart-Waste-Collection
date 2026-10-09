<?php
/**
 * api/hotspots/list.php   GET ?risk=high|medium|low   (admin)
 * Predicted hotspots produced by includes/hotspots.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/hotspots.php';

api_require_role('admin');

$risk = clean_str($_GET['risk'] ?? '', 10);
$rows = get_hotspots($risk !== '' ? $risk : null);

$out = array_map(static fn(array $h): array => [
    'id'           => (int) $h['id'],
    'area_name'    => $h['area_name'],
    'latitude'     => (float) $h['latitude'],
    'longitude'    => (float) $h['longitude'],
    'radius_m'     => (int) $h['radius_m'],
    'report_count' => (int) $h['report_count'],
    'open_count'   => (int) $h['open_count'],
    'live_reports' => (int) ($h['live_reports'] ?? 0),
    'avg_recurrence_days' => (float) $h['avg_recurrence_days'],
    'hotspot_score'=> (int) $h['hotspot_score'],
    'risk_level'   => $h['risk_level'],
    'color'        => hotspot_color($h['risk_level']),
    'dominant_waste_type' => $h['dominant_waste_type'],
    'dominant_waste_label' => waste_meta((string) $h['dominant_waste_type'])['label'],
    'trend'        => $h['trend'],
    'window_days'  => (int) $h['window_days'],
    'first_report_at' => $h['first_report_at'],
    'last_report_at'  => $h['last_report_at'],
], $rows);

$counts = ['high' => 0, 'medium' => 0, 'low' => 0];
foreach ($out as $h) {
    $counts[$h['risk_level']]++;
}

json_success('Loaded ' . count($out) . ' hotspot(s).', [
    'hotspots' => $out,
    'counts'   => $counts,
    'window_days' => HOTSPOT_WINDOW_DAYS,
    'radius_m'    => HOTSPOT_RADIUS_M,
]);
