<?php
/**
 * api/admin/map-data.php   GET  (admin)
 * Everything the Live Map needs in a single round trip:
 * requests, workers, hotspots and the active collection routes.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/hotspots.php';

api_require_role('admin');

$requests = db_all(
    'SELECT r.id, r.request_code, r.waste_type, r.quantity, r.status,
            r.priority_score, r.priority_level, r.address, r.landmark,
            r.latitude, r.longitude, r.created_at, r.is_rejected,
            u.full_name AS citizen_name, wu.full_name AS worker_name, t.team_name
       FROM waste_requests r
       LEFT JOIN users u      ON u.id = r.user_id
       LEFT JOIN workers w    ON w.id = r.assigned_worker_id
       LEFT JOIN users wu     ON wu.id = w.user_id
       LEFT JOIN teams t      ON t.id = r.assigned_team_id
      WHERE r.latitude IS NOT NULL
        AND r.status <> "cancelled"
      ORDER BY r.priority_score DESC
      LIMIT 500'
);

$workers = db_all(
    'SELECT w.id, w.latitude, w.longitude, w.status, w.availability,
            w.current_load, w.max_load, w.last_seen_at,
            u.full_name, t.team_name, v.vehicle_number
       FROM workers w
       JOIN users u         ON u.id = w.user_id
       LEFT JOIN teams t    ON t.id = w.team_id
       LEFT JOIN vehicles v ON v.id = w.vehicle_id
      WHERE w.status <> "removed" AND w.latitude IS NOT NULL'
);

// Active routes: worker current position -> ordered stops.
$routes = [];
foreach (db_all('SELECT * FROM collection_plans WHERE status IN ("active","assigned") ORDER BY id DESC LIMIT 10') as $plan) {
    $stops = db_all(
        'SELECT s.request_id, s.stop_number, r.latitude, r.longitude, r.address
           FROM collection_plan_stops s
           JOIN waste_requests r ON r.id = s.request_id
          WHERE s.plan_id = ? ORDER BY s.sequence_order',
        [$plan['id']]
    );
    if (count($stops) < 2) {
        continue;
    }
    $routes[] = [
        'plan_id' => (int) $plan['id'],
        'code'    => $plan['plan_code'],
        'status'  => $plan['status'],
        'worker_id' => $plan['worker_id'] !== null ? (int) $plan['worker_id'] : null,
        'points'  => array_map(static fn(array $s): array => [
            'request_id' => (int) $s['request_id'],
            'stop'  => (int) $s['stop_number'],
            'latitude'  => (float) $s['latitude'],
            'longitude' => (float) $s['longitude'],
            'address'   => $s['address'],
        ], $stops),
    ];
}

json_success('Map data loaded.', [
    'requests' => array_map(static fn(array $r): array => [
        'id'         => (int) $r['id'],
        'request_code' => $r['request_code'],
        'waste_type' => $r['waste_type'],
        'quantity'   => $r['quantity'],
        'status'     => $r['status'],
        'priority_score' => (int) $r['priority_score'],
        'priority_level' => $r['priority_level'],
        'address'    => $r['address'],
        'landmark'   => $r['landmark'],
        'latitude'   => (float) $r['latitude'],
        'longitude'  => (float) $r['longitude'],
        'created_at' => $r['created_at'],
        'citizen_name' => $r['citizen_name'],
        'worker_name' => $r['worker_name'],
        'team_name'  => $r['team_name'],
        'link'       => window_safe_link($r['id']),
    ], $requests),
    'workers'  => $workers,
    'hotspots' => array_map(static fn(array $h): array => [
        'id'        => (int) $h['id'],
        'area_name' => $h['area_name'],
        'latitude'  => (float) $h['latitude'],
        'longitude' => (float) $h['longitude'],
        'radius_m'  => (int) $h['radius_m'],
        'report_count' => (int) $h['report_count'],
        'avg_recurrence_days' => (float) $h['avg_recurrence_days'],
        'hotspot_score' => (int) $h['hotspot_score'],
        'risk_level' => $h['risk_level'],
    ], get_hotspots()),
    'routes'   => $routes,
]);

/** Detail link injected into the marker popup. */
function window_safe_link(int $id): string
{
    return '/admin/requests.php?id=' . $id;
}
