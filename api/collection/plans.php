<?php
/**
 * api/collection/plans.php   GET  (admin | worker)
 * List of collection plans (optionally ?status=active or ?worker_id=).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

$uid  = api_require_login();
$role = current_role();

$status   = clean_str($_GET['status'] ?? '', 12);
$workerId = (int) ($_GET['worker_id'] ?? 0);

$where = [];
$params = [];

if ($role === 'worker') {
    $w = current_worker();
    $where[] = 'p.worker_id = ?';
    $params[] = $w['id'] ?? 0;
} elseif ($role !== 'admin') {
    json_error('Not allowed.', 403);
} elseif ($workerId > 0) {
    $where[] = 'p.worker_id = ?';
    $params[] = $workerId;
}

if ($status !== '') {
    $where[] = 'p.status = ?';
    $params[] = $status;
}

$sql = 'SELECT p.*, u.full_name AS worker_name, w.worker_code,
               t.team_name, v.vehicle_number,
               (SELECT COUNT(*) FROM collection_plan_stops s
                 WHERE s.plan_id = p.id AND s.status = "collected") AS stops_done
          FROM collection_plans p
          LEFT JOIN workers w  ON w.id = p.worker_id
          LEFT JOIN users u    ON u.id = w.user_id
          LEFT JOIN teams t    ON t.id = p.team_id
          LEFT JOIN vehicles v ON v.id = p.vehicle_id';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY p.plan_date DESC, p.id DESC LIMIT 100';

$rows = db_all($sql, $params);

json_success('Loaded ' . count($rows) . ' plan(s).', [
    'plans' => array_map(static function (array $p): array {
        $done = (int) $p['stops_done'];
        return [
            'id'          => (int) $p['id'],
            'plan_code'   => $p['plan_code'],
            'status'      => $p['status'],
            'plan_date'   => $p['plan_date'],
            'worker_id'   => $p['worker_id'] !== null ? (int) $p['worker_id'] : null,
            'worker_name' => $p['worker_name'],
            'worker_code' => $p['worker_code'],
            'team_name'   => $p['team_name'],
            'vehicle'     => $p['vehicle_number'],
            'total_stops' => (int) $p['total_stops'],
            'stops_done'  => $done,
            'progress'    => $p['total_stops'] ? (int) round($done / (int) $p['total_stops'] * 100) : 0,
            'total_distance_km' => (float) $p['total_distance_km'],
            'estimated_duration_min' => (int) $p['estimated_duration_min'],
            'duration_label' => fmt_duration((int) $p['estimated_duration_min']),
            'notes'       => $p['notes'],
            'started_at'  => $p['started_at'],
            'completed_at'=> $p['completed_at'],
            'created_at'  => $p['created_at'],
        ];
    }, $rows),
]);
