<?php
/**
 * api/workers/list.php   GET  (admin)
 * All workers with team, vehicle, live workload and last GPS fix.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

api_require_role('admin');

$rows = db_all(
    'SELECT w.*,
            u.full_name, u.email,
            t.team_name, t.waste_types AS team_waste_types,
            v.vehicle_number, v.vehicle_type, v.capacity_kg,
            (SELECT COUNT(*) FROM waste_requests r
              WHERE r.assigned_worker_id = w.id
                AND r.status IN ("assigned","scheduled","in_progress")) AS open_load,
            (SELECT COUNT(*) FROM waste_requests r
              WHERE r.assigned_worker_id = w.id
                AND r.status IN ("collected","verified"))            AS done_count
       FROM workers w
       JOIN users u            ON u.id = w.user_id
       LEFT JOIN teams t       ON t.id = w.team_id
       LEFT JOIN vehicles v    ON v.id = w.vehicle_id
      WHERE w.status <> "removed"
      ORDER BY w.status = "available" DESC, w.current_load ASC, u.full_name'
);

$out = array_map(static function (array $w): array {
    return [
        'id'            => (int) $w['id'],
        'user_id'       => (int) $w['user_id'],
        'code'          => $w['worker_code'],
        'name'          => $w['full_name'],
        'email'         => $w['email'],
        'phone'         => $w['phone'],
        'team_id'       => $w['team_id'] !== null ? (int) $w['team_id'] : null,
        'team_name'     => $w['team_name'],
        'team_waste_types' => $w['team_waste_types'],
        'vehicle_id'    => $w['vehicle_id'] !== null ? (int) $w['vehicle_id'] : null,
        'vehicle_number'=> $w['vehicle_number'],
        'vehicle_type'  => $w['vehicle_type'],
        'latitude'      => $w['latitude'] !== null ? (float) $w['latitude'] : null,
        'longitude'     => $w['longitude'] !== null ? (float) $w['longitude'] : null,
        'availability'  => $w['availability'],
        'status'        => $w['status'],
        'current_load'  => (int) $w['current_load'],
        'max_load'      => (int) $w['max_load'],
        'open_load'     => (int) $w['open_load'],
        'done_count'    => (int) $w['done_count'],
        'rating'        => (float) $w['rating'],
        'skills'        => $w['skills'],
        'last_seen_at'  => $w['last_seen_at'],
    ];
}, $rows);

json_success('Loaded ' . count($out) . ' worker(s).', [
    'workers' => $out,
    'teams' => db_all('SELECT id, team_name, team_code, waste_types, max_load, status FROM teams ORDER BY team_name'),
    'vehicles' => db_all('SELECT id, vehicle_number, vehicle_type, capacity_kg, team_id, vehicle_status FROM vehicles ORDER BY vehicle_number'),
]);
