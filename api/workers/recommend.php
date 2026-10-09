<?php
/**
 * api/workers/recommend.php   GET ?request_id=12  (admin)
 *
 * Returns the RECOMMENDED TEAM ranking for a request. Scores every worker on
 * distance, availability, workload and waste-type compatibility (see
 * includes/recommend.php) and returns the top N with the full breakdown.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/recommend.php';
require_once __DIR__ . '/../../includes/priority.php';

api_require_role('admin');

$requestId = (int) ($_GET['request_id'] ?? 0);
$limit     = max(1, min(10, (int) ($_GET['limit'] ?? 5)));

if ($requestId <= 0) {
    json_error('Missing request_id.', 422);
}

$request = db_row('SELECT * FROM waste_requests WHERE id = ?', [$requestId]);
if (!$request) {
    json_error('Request not found.', 404);
}

$result   = recommend_workers_with_excluded($request, $limit);
$eligible = $result['eligible'];
$excluded = $result['excluded'];

/** Shape a worker row for the dashboard (eligible and excluded share a shape). */
$mapRow = static function (array $w): array {
    return [
        'worker_id'   => (int) $w['id'],
        'name'        => $w['full_name'],
        'code'        => $w['worker_code'],
        'phone'       => $w['phone'],
        'team_name'   => $w['team_name'],
        'team_id'     => $w['team_id'] !== null ? (int) $w['team_id'] : null,
        'vehicle'     => $w['vehicle_number'],
        'vehicle_type'=> $w['vehicle_type'],
        'distance_km' => $w['distance_km'],
        'eta_minutes' => $w['eta_minutes'],
        'availability'=> $w['availability'],
        'status'      => $w['status'],
        'current_load'=> (int) $w['current_load'],
        'max_load'    => (int) $w['max_load'],
        'score'       => (int) $w['score'],
        'badge'       => $w['badge'],
        'eligible'    => (bool) ($w['eligible'] ?? false),
        'reason'      => $w['reason'] ?? '',
        'ineligible_reasons' => $w['ineligible_reasons'] ?? [],
        'compat_note' => $w['compat_note'],
        'compatibility_note' => $w['compat_note'],
        // Flat list so the UI can render "why this team?" without reshaping.
        'breakdown_list' => $w['breakdown'],
        'breakdown'   => [
            'distance'     => (int) $w['distance_score'],
            'availability' => (int) $w['availability_score'],
            'workload'     => (int) $w['workload_score'],
            'compatibility'=> (int) $w['compat_score'],
        ],
    ];
};

$out = array_map($mapRow, $eligible);

// Highlight the single best eligible option and explain the pick.
if ($out) {
    $out[0]['is_recommended']  = true;
    $out[0]['why_recommended'] = 'Top eligible team — ' . $out[0]['reason'] . '.';
}

$excludedOut = array_map($mapRow, $excluded);

$escalation = null;
if (!$out) {
    $escalation = 'No eligible team can service '
        . waste_meta((string) $request['waste_type'])['label']
        . '. Escalate for manual review, or add a qualified team with the required equipment and free capacity.';
}

// Also return the live priority so the admin sees urgency next to the team.
$priority = calculate_priority($request);

json_success('Recommendations ready.', [
    'request' => [
        'id'            => (int) $request['id'],
        'waste_type'    => $request['waste_type'],
        'quantity'      => $request['quantity'],
        'address'       => $request['address'],
        'priority_score'=> (int) $request['priority_score'],
        'priority_level'=> $request['priority_level'],
        'already_assigned_to' => $request['assigned_worker_id'],
    ],
    'priority' => ['score' => $priority['score'], 'level' => $priority['level']],
    'eligibility' => [
        'eligible_count'   => (int) $result['eligible_total'],
        'ineligible_count' => count($excludedOut),
        'escalation'       => $escalation,
    ],
    'recommendations' => $out,
    'ineligible'      => $excludedOut,
]);
