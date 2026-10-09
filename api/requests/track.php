<?php
/**
 * api/requests/track.php   GET ?id=12
 * Lightweight endpoint used by the live map / tracking widgets.
 * A citizen can only track their own requests.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

$uid  = api_require_login();
$role = current_role();
$id   = (int) ($_GET['id'] ?? 0);

$req = db_row(
    'SELECT r.*, wu.full_name AS worker_name, t.team_name, v.vehicle_number
       FROM waste_requests r
       LEFT JOIN workers w  ON w.id = r.assigned_worker_id
       LEFT JOIN users wu   ON wu.id = w.user_id
       LEFT JOIN teams t    ON t.id = r.assigned_team_id
       LEFT JOIN vehicles v ON v.id = r.vehicle_id
      WHERE r.id = ?',
    [$id]
);

if (!$req) {
    json_error('Request not found.', 404);
}

if ($role === 'citizen') {
    $c = current_citizen();
    if ((int) ($c['id'] ?? 0) !== (int) $req['citizen_id']) {
        json_error('You can only track your own requests.', 403);
    }
}
if ($role === 'worker') {
    $wk = current_worker();
    if ((int) ($wk['id'] ?? 0) !== (int) $req['assigned_worker_id']) {
        json_error('This pickup is not assigned to you.', 403);
    }
}

$w = waste_meta($req['waste_type']);

// --- Status timeline ------------------------------------------------------
// Built from the real timestamps so the citizen sees history, not fiction.
$STEPS = [
    ['pending',     'Request Submitted',  'We received your report and the photo.',
                     'bi-send-check',     'active'],
    ['prioritized', 'Priority Analysed',  'Our engine scored the request out of 100.',
                     'bi-graph-up-arrow',  'pending'],
    ['assigned',    'Team Assigned',      'A collection team has been allocated.',
                     'bi-person-check',    'pending'],
    ['scheduled',   'Added To Route',     'The stop is part of an optimised daily route.',
                     'bi-signpost-split',  'pending'],
    ['in_progress', 'Collection Started', 'The team is on site and collecting.',
                     'bi-truck',           'pending'],
    ['collected',   'Collected',          'Before/after proof photos uploaded for review.',
                     'bi-camera-fill',     'pending'],
    ['verified',    'Verified Complete',  'An officer approved the proof photos.',
                     'bi-patch-check',     'pending'],
];

$stamps = [
    'pending'     => $req['created_at'],
    'prioritized' => $req['created_at'],
    'assigned'    => $req['assigned_at'],
    'scheduled'   => $req['scheduled_at'],
    'in_progress' => $req['started_at'] ?: $req['arrived_at'],
    'collected'   => $req['collected_at'],
    'verified'    => $req['verified_at'],
];

$statusOrder = array_keys($stamps);
$currentPos  = array_search($req['status'], $statusOrder, true);
$currentPos  = $currentPos === false ? 0 : (int) $currentPos;
$timeline = [];

foreach ($STEPS as $i => [$key, $title, $desc, $icon, $_state]) {
    $stamp = $stamps[$key] ?? null;
    if ($i < $currentPos) {
        $state = 'done';
    } elseif ($i === $currentPos) {
        $state = $req['status'] === 'cancelled' ? 'done' : 'active';
    } else {
        $state = 'pending';
    }
    $timeline[] = [
        'status'     => $key,
        'title'      => $title,
        'description'=> $desc,
        'icon'       => $icon,
        'state'      => $state,
        'at'         => $stamp ? fmt_dt($stamp) : null,
        'timestamp'  => $stamp,
    ];
}

$nextStep = null;
if ($req['status'] !== 'cancelled') {
    foreach ($timeline as $i => $step) {
        if ($step['status'] === $req['status']) {
            $nextStep = $timeline[$i + 1] ?? null;
            break;
        }
    }
}

json_success('Tracking data loaded.', [
    'request' => [
        'id'         => (int) $req['id'],
        'code'       => $req['request_code'],
        'waste_type' => $req['waste_type'],
        'waste_label'=> $w['label'],
        'waste_icon' => $w['icon'],
        'address'    => $req['address'],
        'landmark'   => $req['landmark'],
        'latitude'   => $req['latitude'] !== null ? (float) $req['latitude'] : null,
        'longitude'  => $req['longitude'] !== null ? (float) $req['longitude'] : null,
        'status'     => $req['status'],
        'status_label' => status_meta($req['status'])['label'],
        'priority_score' => (int) $req['priority_score'],
        'priority_level' => $req['priority_level'],
        'worker_name' => $req['worker_name'],
        'team_name'   => $req['team_name'],
        'vehicle'     => $req['vehicle_number'],
        'estimated_visit_time' => $req['estimated_visit_time'],
        'collected_at' => $req['collected_at'],
        'verified_at'  => $req['verified_at'],
        'created_at'   => $req['created_at'],
        'before_image' => image_url($req['before_image']),
        'after_image'  => image_url($req['after_image']),
        'waste_image'  => image_url($req['image_path']),
    ],
    'timeline'   => $timeline,
    'next_step'  => $nextStep,
    'cancelled'  => $req['status'] === 'cancelled',
    'reject_reason' => $req['reject_reason'],
    'rating'     => $req['citizen_rating'] !== null ? (int) $req['citizen_rating'] : null,
    'feedback'   => $req['citizen_feedback'],
]);
