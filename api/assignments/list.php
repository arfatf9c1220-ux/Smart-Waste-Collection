<?php
/**
 * api/assignments/list.php   GET  (admin)
 * ?worker_id=3  -> only that worker,  otherwise everything
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

api_require_role('admin');

$workerId = (int) ($_GET['worker_id'] ?? 0);

$sql = 'SELECT a.*, r.request_code, r.waste_type, r.status AS request_status,
               r.priority_score, r.address, r.latitude, r.longitude,
               u.full_name AS worker_name, w.worker_code,
               t.team_name, v.vehicle_number,
               au.full_name AS assigned_by_name
          FROM assignments a
          JOIN waste_requests r ON r.id = a.request_id
          JOIN workers w       ON w.id = a.worker_id
          JOIN users u         ON u.id = w.user_id
          LEFT JOIN teams t    ON t.id = a.team_id
          LEFT JOIN vehicles v ON v.id = a.vehicle_id
          LEFT JOIN users au   ON au.id = a.assigned_by
         ';
$params = [];
if ($workerId > 0) {
    $sql .= ' WHERE a.worker_id = ?';
    $params[] = $workerId;
}
$sql .= ' ORDER BY a.assigned_at DESC LIMIT 200';

$rows = db_all($sql, $params);

json_success('Loaded ' . count($rows) . ' assignment(s).', [
    'assignments' => array_map(static fn(array $a): array => [
        'id'          => (int) $a['id'],
        'request_id'  => (int) $a['request_id'],
        'request_code'=> $a['request_code'],
        'waste_type'  => $a['waste_type'],
        'waste_label' => waste_meta($a['waste_type'])['label'],
        'request_status' => $a['request_status'],
        'priority_score' => (int) $a['priority_score'],
        'address'     => $a['address'],
        'latitude'    => $a['latitude'] !== null ? (float) $a['latitude'] : null,
        'longitude'   => $a['longitude'] !== null ? (float) $a['longitude'] : null,
        'worker_id'   => (int) $a['worker_id'],
        'worker_name' => $a['worker_name'],
        'worker_code' => $a['worker_code'],
        'team_name'   => $a['team_name'],
        'vehicle'     => $a['vehicle_number'],
        'distance_km' => $a['distance_km'] !== null ? (float) $a['distance_km'] : null,
        'match_score' => $a['match_score'] !== null ? (float) $a['match_score'] : null,
        'reason'      => $a['reason'],
        'status'      => $a['status'],
        'assigned_by' => $a['assigned_by_name'],
        'assigned_at' => $a['assigned_at'],
    ], $rows),
]);
