<?php
/**
 * api/collection/suggest.php   GET ?worker_id=3&max=8  (admin)
 *
 * The "Generate Plan" screen calls this: it returns the requests that this
 * worker's team is actually able to service, already ordered by the engine,
 * so the admin can tick the stops before creating the plan.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/planner.php';
require_once __DIR__ . '/../../includes/recommend.php';

api_require_role('admin');

$workerId = (int) ($_GET['worker_id'] ?? 0);
$max      = max(1, min(30, (int) ($_GET['max'] ?? 8)));

if ($workerId <= 0) {
    json_error('Missing worker_id.', 422);
}

$worker = db_row(
    'SELECT w.*, t.team_name, t.waste_types AS team_waste_types, v.vehicle_number, v.vehicle_type
       FROM workers w
       LEFT JOIN teams t    ON t.id = w.team_id
       LEFT JOIN vehicles v ON v.id = w.vehicle_id
      WHERE w.id = ?',
    [$workerId]
);
if (!$worker) {
    json_error('Worker not found.', 404);
}

$suggestion = suggest_requests_for_plan($workerId, $max);

// The engine also returns the optimised preview order.
$start = [
    'lat' => $worker['latitude'] !== null ? (float) $worker['latitude'] : DEFAULT_MAP_LAT,
    'lng' => $worker['longitude'] !== null ? (float) $worker['longitude'] : DEFAULT_MAP_LNG,
];
$preview = $suggestion['requests'] ? optimise_route($suggestion['requests'], $start) : [];

json_success('Suggested stops ready.', [
    'worker' => [
        'id'          => (int) $worker['id'],
        'code'        => $worker['worker_code'],
        'team_name'   => $worker['team_name'],
        'team_waste_types' => $worker['team_waste_types'],
        'vehicle'     => $worker['vehicle_number'],
        'vehicle_type'=> $worker['vehicle_type'],
        'latitude'    => $worker['latitude'] !== null ? (float) $worker['latitude'] : null,
        'longitude'   => $worker['longitude'] !== null ? (float) $worker['longitude'] : null,
        'current_load'=> (int) $worker['current_load'],
        'max_load'    => (int) $worker['max_load'],
        'status'      => $worker['status'],
    ],
    'candidates' => array_map(static fn(array $r): array => [
        'id'            => (int) $r['id'],
        'code'          => $r['request_code'],
        'waste_type'    => $r['waste_type'],
        'waste_label'   => waste_meta($r['waste_type'])['label'],
        'quantity'      => $r['quantity'],
        'address'       => $r['address'],
        'landmark'      => $r['landmark'],
        'latitude'      => (float) $r['latitude'],
        'longitude'     => (float) $r['longitude'],
        'status'        => $r['status'],
        'priority_score'=> (int) $r['priority_score'],
        'priority_level'=> $r['priority_level'],
        'age_hours'     => round((time() - strtotime($r['created_at'])) / 3600),
        'created_at'    => $r['created_at'],
        'distance_km'   => $r['latitude'] !== null && $worker['latitude'] !== null
                            ? round(haversine_km((float) $worker['latitude'], (float) $worker['longitude'],
                                                 (float) $r['latitude'], (float) $r['longitude']), 2)
                            : null,
    ], $suggestion['requests']),
    'skipped' => array_map(static fn(array $r): array => [
        'id'            => (int) $r['id'],
        'waste_label'   => waste_meta($r['waste_type'])['label'],
        'address'       => $r['address'],
        'priority_score'=> (int) $r['priority_score'],
        'reason'        => !team_handles_type($worker['team_waste_types'], (string) $r['waste_type'])
                            ? 'Team not equipped for this category'
                            : 'Over the per-plan stop limit',
    ], array_slice($suggestion['skipped'], 0, 10)),
    'preview' => array_map(static fn(array $s): array => [
        'request_id'   => (int) $s['request']['id'],
        'stop_number'  => $s['stop_number'],
        'distance_from_prev_km' => $s['distance_from_prev_km'],
        'eta'          => $s['eta'],
        'travel_minutes' => $s['travel_minutes'],
        'priority_score' => (int) $s['request']['priority_score'],
        'address'      => $s['request']['address'],
    ], $preview),
    'preview_distance_km' => plan_distance_km($preview),
    'preview_duration_min' => plan_duration_minutes($preview),
]);
