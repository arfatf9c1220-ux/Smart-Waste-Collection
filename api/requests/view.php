<?php
/**
 * api/requests/view.php    GET ?id=12
 * Everything the tracking / detail screens need for one request:
 * request row, images, live priority breakdown, assignment, plan stop
 * and the status timeline.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/priority.php';

$uid  = api_require_login();
$role = current_role();
$id   = (int) ($_GET['id'] ?? 0);

if ($id <= 0) {
    json_error('Missing request id.', 422);
}

$row = db_row(
    'SELECT r.*,
            u.full_name  AS citizen_name,
            u.email      AS citizen_email,
            u.phone      AS citizen_phone,
            wu.full_name AS worker_name,
            w.worker_code, w.phone AS worker_phone,
            t.team_name, t.waste_types AS team_waste_types,
            v.vehicle_number, v.vehicle_type,
            p.plan_code, p.status AS plan_status, p.plan_date,
            s.stop_number, s.eta AS stop_eta, s.distance_from_prev_km, s.status AS stop_status
       FROM waste_requests r
       LEFT JOIN users u               ON u.id = r.user_id
       LEFT JOIN workers w             ON w.id = r.assigned_worker_id
       LEFT JOIN users wu              ON wu.id = w.user_id
       LEFT JOIN teams t               ON t.id = r.assigned_team_id
       LEFT JOIN vehicles v            ON v.id = r.vehicle_id
       LEFT JOIN collection_plans p    ON p.id = r.collection_plan_id
       LEFT JOIN collection_plan_stops s ON s.plan_id = r.collection_plan_id AND s.request_id = r.id
      WHERE r.id = ?',
    [$id]
);

if (!$row) {
    json_error('Request not found.', 404);
}

// --- authorisation: citizens only see their own ----------------------------
$citizen = $role === 'citizen' ? current_citizen() : null;
if ($role === 'citizen' && (int) ($citizen['id'] ?? 0) !== (int) $row['citizen_id']) {
    json_error('You can only view your own requests.', 403);
}
if ($role === 'worker') {
    $worker = current_worker();
    if ((int) ($worker['id'] ?? 0) !== (int) $row['assigned_worker_id']) {
        json_error('This pickup is not assigned to you.', 403);
    }
}

// --- live priority breakdown ------------------------------------------------
$priority = calculate_priority($row);

// --- images -----------------------------------------------------------------
$images = db_all('SELECT * FROM request_images WHERE request_id = ? ORDER BY id', [$id]);
$gallery = ['waste' => [], 'before' => [], 'after' => []];
foreach ($images as $img) {
    $gallery[$img['image_type']][] = [
        'id'     => (int) $img['id'],
        'url'    => image_url($img['file_path']),
        'by'     => $img['uploaded_by'],
        'at'     => $img['uploaded_at'],
        'lat'    => $img['captured_lat'] !== null ? (float) $img['captured_lat'] : null,
        'lng'    => $img['captured_lng'] !== null ? (float) $img['captured_lng'] : null,
    ];
}

// Fall back to the columns on waste_requests if request_images is empty.
if (!$gallery['before'] && $row['before_image']) {
    $gallery['before'][] = ['id' => 0, 'url' => image_url($row['before_image']), 'by' => null, 'at' => $row['arrived_at'], 'lat' => null, 'lng' => null];
}
if (!$gallery['after'] && $row['after_image']) {
    $gallery['after'][] = ['id' => 0, 'url' => image_url($row['after_image']), 'by' => null, 'at' => $row['collected_at'], 'lat' => null, 'lng' => null];
}

// --- assignment history -----------------------------------------------------
$assignments = db_all(
    'SELECT a.*, wu.full_name AS worker_name, w.worker_code, t.team_name
       FROM assignments a
       LEFT JOIN workers w  ON w.id = a.worker_id
       LEFT JOIN users wu   ON wu.id = w.user_id
       LEFT JOIN teams t    ON t.id = a.team_id
      WHERE a.request_id = ? ORDER BY a.id DESC',
    [$id]
);

// --- status timeline --------------------------------------------------------
$timeline = [];
$steps = [
    ['pending',     'pending',     'Request received'],
    ['prioritized', 'prioritized', 'Priority analysed'],
    ['assigned',    'assigned',    'Worker assigned'],
    ['scheduled',   'scheduled',   'Added to collection plan'],
    ['in_progress', 'in_progress', 'Collection in progress'],
    ['collected',   'collected',   'Collected (proof uploaded)'],
    ['verified',    'verified',    'Verified by authority'],
];
$order = ['pending' => 0, 'prioritized' => 1, 'assigned' => 2, 'scheduled' => 3, 'in_progress' => 4, 'collected' => 5, 'verified' => 6];
$currentPos = $order[$row['status']] ?? -1;
$stamps = [
    'pending'     => $row['created_at'],
    'prioritized' => $row['created_at'],
    'assigned'    => $row['assigned_at'],
    'scheduled'   => $row['scheduled_at'],
    'in_progress' => $row['started_at'],
    'collected'   => $row['collected_at'],
    'verified'    => $row['verified_at'],
];
foreach ($steps as [$key, $_unused, $label]) {
    $pos = $order[$key];
    $timeline[] = [
        'key'       => $key,
        'label'     => $label,
        'done'      => $pos < $currentPos,
        'current'   => $pos === $currentPos,
        'at'        => $stamps[$key] ?: null,
    ];
}

$w = waste_meta($row['waste_type']);

json_success('Request loaded.', [
    'request' => [
        'id'            => (int) $row['id'],
        'code'          => $row['request_code'],
        'waste_type'    => $row['waste_type'],
        'waste_label'   => $w['label'],
        'waste_icon'    => $w['icon'],
        'waste_color'   => $w['color'],
        'quantity'      => $row['quantity'],
        'quantity_label'=> quantity_options()[$row['quantity']] ?? $row['quantity'],
        'description'   => $row['description'],
        'address'       => $row['address'],
        'landmark'      => $row['landmark'],
        'latitude'      => $row['latitude'] !== null ? (float) $row['latitude'] : null,
        'longitude'     => $row['longitude'] !== null ? (float) $row['longitude'] : null,
        'status'        => $row['status'],
        'status_label'  => status_meta($row['status'])['label'],
        'priority_score'=> (int) $row['priority_score'],
        'priority_level'=> $row['priority_level'],
        'citizen_name'  => $row['citizen_name'],
        'citizen_phone' => $row['citizen_phone'],
        'created_at'    => $row['created_at'],
        'is_rejected'   => (int) $row['is_rejected'] === 1,
        'reject_reason' => $row['reject_reason'],
    ],
    'priority' => [
        'score'     => $priority['score'],
        'level'     => $priority['level'],
        'breakdown' => $priority['breakdown'],
        'reasons'   => $priority['reasons'],
    ],
    'assignment' => [
        'worker_id'       => $row['assigned_worker_id'] !== null ? (int) $row['assigned_worker_id'] : null,
        'worker_name'     => $row['worker_name'],
        'worker_code'     => $row['worker_code'],
        'worker_phone'    => $row['worker_phone'],
        'team_id'         => $row['assigned_team_id'] !== null ? (int) $row['assigned_team_id'] : null,
        'team_name'       => $row['team_name'],
        'vehicle_number'  => $row['vehicle_number'],
        'vehicle_type'    => $row['vehicle_type'],
        'estimated_visit_time' => $row['estimated_visit_time'],
        'history'         => $assignments,
    ],
    'plan' => $row['collection_plan_id'] ? [
        'id'        => (int) $row['collection_plan_id'],
        'code'      => $row['plan_code'],
        'status'    => $row['plan_status'],
        'date'      => $row['plan_date'],
        'stop_number'=> $row['stop_number'],
        'eta'       => $row['stop_eta'],
        'distance_from_prev_km' => $row['distance_from_prev_km'],
        'stop_status' => $row['stop_status'],
    ] : null,
    'collection' => [
        'before_image'  => image_url($row['before_image']),
        'after_image'   => image_url($row['after_image']),
        'collection_lat'=> $row['collection_lat'] !== null ? (float) $row['collection_lat'] : null,
        'collection_lng'=> $row['collection_lng'] !== null ? (float) $row['collection_lng'] : null,
        'arrived_at'    => $row['arrived_at'],
        'started_at'    => $row['started_at'],
        'collected_at'  => $row['collected_at'],
        'verified_at'   => $row['verified_at'],
    ],
    'images'  => $gallery,
    'timeline'=> $timeline,
]);
