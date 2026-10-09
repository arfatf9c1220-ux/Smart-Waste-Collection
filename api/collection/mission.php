<?php
/**
 * api/collection/mission.php   GET   (worker)
 * "TODAY'S COLLECTION MISSION": the active plan (if any) plus every loose
 * pickup assigned straight to this worker, with mission totals.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

$worker = current_worker();
if (!$worker) {
    json_error('Worker profile not found for this account.', 403);
}
$wid = (int) $worker['id'];

$plan = db_row(
    'SELECT p.*, t.team_name, v.vehicle_number, v.vehicle_type
       FROM collection_plans p
       LEFT JOIN teams t    ON t.id = p.team_id
       LEFT JOIN vehicles v ON v.id = p.vehicle_id
      WHERE p.worker_id = ? AND p.status IN ("active","assigned","draft")
      ORDER BY FIELD(p.status,"active","assigned","draft"), p.plan_date DESC, p.id DESC
      LIMIT 1',
    [$wid]
);

$stops = [];
if ($plan) {
    $rows = db_all(
        'SELECT s.id AS stop_id, s.stop_number, s.eta, s.distance_from_prev_km,
                s.travel_minutes, s.status AS stop_status, s.arrived_at, s.completed_at,
                r.id AS request_id, r.request_code, r.waste_type, r.quantity,
                r.address, r.landmark, r.description, r.latitude, r.longitude,
                r.priority_score, r.priority_level, r.status AS request_status,
                r.image_path, r.before_image, r.after_image
           FROM collection_plan_stops s
           JOIN waste_requests r ON r.id = s.request_id
          WHERE s.plan_id = ? ORDER BY s.sequence_order',
        [$plan['id']]
    );
    $rows = array_map(static fn(array $s): array => [
        'stop_id'      => (int) $s['stop_id'],
        'stop_number'  => (int) $s['stop_number'],
        'request_id'   => (int) $s['request_id'],
        'request_code' => $s['request_code'],
        'waste_type'   => $s['waste_type'],
        'waste_label'  => waste_meta($s['waste_type'])['label'],
        'waste_icon'   => waste_meta($s['waste_type'])['icon'],
        'quantity'     => $s['quantity'],
        'address'      => $s['address'],
        'landmark'     => $s['landmark'],
        'description'  => $s['description'],
        'latitude'     => $s['latitude'] !== null ? (float) $s['latitude'] : null,
        'longitude'    => $s['longitude'] !== null ? (float) $s['longitude'] : null,
        'priority_score'=> (int) $s['priority_score'],
        'priority_level'=> $s['priority_level'],
        'status'       => $s['request_status'],
        'stop_status'  => $s['stop_status'],
        'eta'          => $s['eta'],
        'leg_km'       => (float) $s['distance_from_prev_km'],
        'travel_minutes' => (int) $s['travel_minutes'],
        'arrived_at'   => $s['arrived_at'],
        'completed_at' => $s['completed_at'],
        'before_image' => image_url($s['before_image']),
        'after_image'  => image_url($s['after_image']),
        'image'        => image_url($s['image_path']),
        'in_plan'      => true,
    ], $rows);

    // Distances from the worker's live position to the next stop.
    $here = ['lat' => $worker['latitude'] !== null ? (float) $worker['latitude'] : null,
             'lng' => $worker['longitude'] !== null ? (float) $worker['longitude'] : null];
    foreach ($rows as $i => &$s) {
        $s['distance_from_worker_km'] = ($here['lat'] !== null && $s['latitude'] !== null)
            ? round(haversine_km($here['lat'], $here['lng'], $s['latitude'], $s['longitude']), 2)
            : null;
    }
    unset($s);
    $stops = $rows;
}

// Loose pickups (assigned but not part of a plan).
$loose = db_all(
    'SELECT r.* FROM waste_requests r
      WHERE r.assigned_worker_id = ? AND r.collection_plan_id IS NULL
        AND r.status IN ("assigned","in_progress")
      ORDER BY r.priority_score DESC',
    [$wid]
);
$loose = array_map(static fn(array $r): array => [
    'stop_id'      => null,
    'stop_number'  => count($stops) + 1,
    'request_id'   => (int) $r['id'],
    'request_code' => $r['request_code'],
    'waste_type'   => $r['waste_type'],
    'waste_label'  => waste_meta($r['waste_type'])['label'],
    'waste_icon'   => waste_meta($r['waste_type'])['icon'],
    'quantity'     => $r['quantity'],
    'address'      => $r['address'],
    'landmark'     => $r['landmark'],
    'description'  => $r['description'],
    'latitude'     => $r['latitude'] !== null ? (float) $r['latitude'] : null,
    'longitude'    => $r['longitude'] !== null ? (float) $r['longitude'] : null,
    'priority_score'=> (int) $r['priority_score'],
    'priority_level'=> $r['priority_level'],
    'status'       => $r['status'],
    'stop_status'  => $r['status'],
    'eta'          => $r['estimated_visit_time'],
    'leg_km'       => 0,
    'before_image' => image_url($r['before_image']),
    'after_image'  => image_url($r['after_image']),
    'image'        => image_url($r['image_path']),
    'in_plan'      => false,
], $loose);

$all = array_merge($stops, $loose);
$doneCount = count(array_filter($all, fn($s) => in_array($s['status'], ['collected', 'verified'], true)));
$next = null;
foreach ($all as $s) {
    if (!in_array($s['status'], ['collected', 'verified', 'cancelled'], true)) {
        $next = $s;
        break;
    }
}

$todayCollected = (int) db_value(
    'SELECT COUNT(*) FROM waste_requests WHERE assigned_worker_id = ? AND DATE(collected_at) = CURDATE()',
    [$wid], 0);

json_success('Mission loaded.', [
    'worker' => [
        'id'            => $wid,
        'code'          => $worker['worker_code'],
        'name'          => db_value('SELECT full_name FROM users WHERE id = ?', [$worker['user_id']]),
        'team_name'     => $worker['team_name'],
        'vehicle'       => $worker['vehicle_number'],
        'vehicle_type'  => $worker['vehicle_type'],
        'phone'         => $worker['phone'],
        'status'        => $worker['status'],
        'availability'  => $worker['availability'],
        'current_load'  => (int) $worker['current_load'],
        'max_load'      => (int) $worker['max_load'],
        'latitude'      => $worker['latitude'] !== null ? (float) $worker['latitude'] : null,
        'longitude'     => $worker['longitude'] !== null ? (float) $worker['longitude'] : null,
        'last_seen_at'  => $worker['last_seen_at'],
        'today_collected' => $todayCollected,
        'total_collected' => (int) $worker['total_collected'],
        'rating'        => (float) $worker['rating'],
    ],
    'plan' => $plan ? [
        'id'          => (int) $plan['id'],
        'plan_code'   => $plan['plan_code'],
        'status'      => $plan['status'],
        'plan_date'   => $plan['plan_date'],
        'team_name'   => $plan['team_name'],
        'vehicle'     => $plan['vehicle_number'],
        'total_stops' => (int) $plan['total_stops'],
        'total_distance_km' => (float) $plan['total_distance_km'],
        'estimated_duration_min' => (int) $plan['estimated_duration_min'],
        'duration_label' => fmt_duration((int) $plan['estimated_duration_min']),
        'started_at'  => $plan['started_at'],
        'notes'       => $plan['notes'],
    ] : null,
    'mission' => [
        'total_stops'   => count($all),
        'done_stops'    => $doneCount,
        'distance_km'   => $plan ? (float) $plan['total_distance_km'] : round(array_sum(array_column($loose, 'leg_km')), 2),
        'duration_min'  => $plan ? (int) $plan['estimated_duration_min'] : count($all) * 25,
        'duration_label'=> $plan ? fmt_duration((int) $plan['estimated_duration_min']) : fmt_duration(count($all) * 25),
        'progress'      => $all ? (int) round($doneCount / count($all) * 100) : 0,
        'high_priority' => count(array_filter($all, fn($s) => (int) $s['priority_score'] >= 61)),
    ],
    'stops'   => $all,
    'next'    => $next,
]);
