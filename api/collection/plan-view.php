<?php
/**
 * api/collection/plan-view.php   GET ?id=4   (admin | worker owner)
 * Full detail of one collection plan: header stats + ordered stops.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/priority.php';

$uid  = api_require_login();
$role = current_role();
$id   = (int) ($_GET['id'] ?? 0);

$plan = db_row(
    'SELECT p.*, u.full_name AS worker_name, w.worker_code, w.phone AS worker_phone,
            t.team_name, v.vehicle_number, v.vehicle_type,
            wl.latitude AS worker_lat, wl.longitude AS worker_lng, wl.status AS worker_status
       FROM collection_plans p
       LEFT JOIN workers w  ON w.id = p.worker_id
       LEFT JOIN users u    ON u.id = w.user_id
       LEFT JOIN teams t    ON t.id = p.team_id
       LEFT JOIN vehicles v ON v.id = p.vehicle_id
       LEFT JOIN worker_locations wl ON wl.id = (
              SELECT id FROM worker_locations
               WHERE worker_id = p.worker_id
               ORDER BY recorded_at DESC, id DESC LIMIT 1)
      WHERE p.id = ?',
    [$id]
);

if (!$plan) {
    json_error('Collection plan not found.', 404);
}

if ($role === 'worker') {
    $worker = current_worker();
    if ((int) ($worker['id'] ?? 0) !== (int) $plan['worker_id']) {
        json_error('This plan belongs to another team.', 403);
    }
}

$stops = db_all(
    'SELECT s.*, r.request_code, r.waste_type, r.quantity, r.address, r.landmark, r.description,
            r.latitude, r.longitude, r.priority_score, r.priority_level,
            r.status AS request_status, r.image_path, r.before_image, r.after_image,
            u.full_name AS citizen_name
       FROM collection_plan_stops s
       JOIN waste_requests r ON r.id = s.request_id
       LEFT JOIN users u     ON u.id = r.user_id
      WHERE s.plan_id = ?
      ORDER BY s.sequence_order',
    [$id]
);

$stops = array_map(static fn(array $s): array => [
    'stop_id'     => (int) $s['id'],
    'request_id'  => (int) $s['request_id'],
    'request_code'=> $s['request_code'],
    'stop_number' => (int) $s['stop_number'],
    'waste_type'  => $s['waste_type'],
    'waste_label' => waste_meta($s['waste_type'])['label'],
    'waste_icon'  => waste_meta($s['waste_type'])['icon'],
    'quantity'    => quantity_options()[$s['quantity']] ?? ucfirst((string) $s['quantity']),
    'address'     => $s['address'],
    'landmark'    => $s['landmark'],
    'description' => $s['description'],
    'latitude'    => (float) $s['latitude'],
    'longitude'   => (float) $s['longitude'],
    'priority_score' => (int) $s['priority_score'],
    'priority_level' => $s['priority_level'],
    'request_status' => $s['request_status'],
    'status'      => $s['status'],
    'eta'         => $s['eta'],
    'distance_from_prev_km' => (float) $s['distance_from_prev_km'],
    'travel_minutes' => (int) $s['travel_minutes'],
    'arrived_at'  => $s['arrived_at'],
    'completed_at'=> $s['completed_at'],
    'citizen_name'=> $s['citizen_name'],
    'image'       => image_url($s['image_path']),
    'before_image'=> image_url($s['before_image']),
    'after_image' => image_url($s['after_image']),
], $stops);

$done = count(array_filter($stops, fn($s) => $s['status'] === 'collected'));
$progress = $stops ? round($done / count($stops) * 100) : 0;

json_success('Plan loaded.', [
    'plan' => [
        'id'             => (int) $plan['id'],
        'plan_code'      => $plan['plan_code'],
        'status'         => $plan['status'],
        'plan_date'      => $plan['plan_date'],
        'worker_id'      => $plan['worker_id'] !== null ? (int) $plan['worker_id'] : null,
        'worker_name'    => $plan['worker_name'],
        'worker_code'    => $plan['worker_code'],
        'worker_phone'   => $plan['worker_phone'],
        'worker_status'  => $plan['worker_status'],
        'worker_lat'     => $plan['worker_lat'] !== null ? (float) $plan['worker_lat'] : null,
        'worker_lng'     => $plan['worker_lng'] !== null ? (float) $plan['worker_lng'] : null,
        'team_name'      => $plan['team_name'],
        'vehicle'        => $plan['vehicle_number'],
        'vehicle_type'   => $plan['vehicle_type'],
        'total_stops'    => (int) $plan['total_stops'],
        'total_distance_km' => (float) $plan['total_distance_km'],
        'estimated_duration_min' => (int) $plan['estimated_duration_min'],
        'duration_label' => fmt_duration((int) $plan['estimated_duration_min']),
        'notes'          => $plan['notes'],
        'started_at'     => $plan['started_at'],
        'completed_at'   => $plan['completed_at'],
        'created_at'     => $plan['created_at'],
        'stops_done'     => $done,
        'progress'       => $progress,
    ],
    'stops' => $stops,
]);
