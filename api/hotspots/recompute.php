<?php
/**
 * api/hotspots/recompute.php   POST  (admin)
 * Body: { window_days: 30 }
 * Re-runs the rule based prediction over the request history.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/hotspots.php';
require_once __DIR__ . '/../../includes/priority.php';

try {
    api_require_role('admin');
    api_require_csrf();

    $days = (int) jinput('window_days', HOTSPOT_WINDOW_DAYS);
    $days = max(7, min(365, $days ?: HOTSPOT_WINDOW_DAYS));

    $result = recompute_hotspots($days);

    // Also refresh the priority scores so the board stays consistent.
    $scored = recalculate_all_priorities();

    json_success('Hotspot engine rebuilt ' . count($result['hotspots']) . ' cluster(s) and rescored '
        . $scored . ' request(s).', [
            'window_days' => $days,
            'clusters'    => count($result['hotspots']),
            'rescored'    => $scored,
            'hotspots'    => array_map(static fn(array $h): array => [
                'area_name'   => $h['area_name'],
                'latitude'    => $h['latitude'],
                'longitude'   => $h['longitude'],
                'report_count'=> $h['report_count'],
                'avg_recurrence_days' => $h['avg_recurrence_days'],
                'hotspot_score'=> $h['hotspot_score'],
                'risk_level'  => $h['risk_level'],
            ], $result['hotspots']),
        ]);
} catch (Throwable $e) {
    api_handle_exception($e);
}
