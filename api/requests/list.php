<?php
/**
 * api/requests/list.php    GET
 * Query: status, priority, waste_type, q, page, per_page, limit, mine
 *
 * Role aware:
 *   citizen -> only their own requests
 *   worker  -> only requests assigned to them (+ their plan stops)
 *   admin   -> everything, with filters
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/priority.php';

$uid  = api_require_login();
$role = current_role();

$status  = clean_str($_GET['status'] ?? '', 20);
$level   = clean_str($_GET['priority'] ?? '', 10);
$waste   = clean_str($_GET['waste_type'] ?? '', 20);
$search  = clean_str($_GET['q'] ?? '', 80);
$limit   = max(1, min(200, (int) ($_GET['limit'] ?? 50)));
$offset  = max(0, (int) ($_GET['offset'] ?? 0));

$where  = [];
$params = [];

// --- role scoping -----------------------------------------------------------
if ($role === 'citizen') {
    $citizen = current_citizen();
    $where[] = 'r.citizen_id = ?';
    $params[] = $citizen['id'] ?? 0;
} elseif ($role === 'worker') {
    $worker = current_worker();
    $where[] = '(r.assigned_worker_id = ? OR r.collection_plan_id IN (SELECT id FROM collection_plans WHERE worker_id = ?))';
    $params[] = $worker['id'] ?? 0;
    $params[] = $worker['id'] ?? 0;
}

// --- filters ----------------------------------------------------------------
if ($status !== '') { $where[] = 'r.status = ?'; $params[] = $status; }
if ($level !== '')  { $where[] = 'r.priority_level = ?'; $params[] = $level; }
if ($waste !== '')  { $where[] = 'r.waste_type = ?'; $params[] = $waste; }
if ($search !== '') {
    $where[] = '(r.address LIKE ? OR r.landmark LIKE ? OR r.request_code LIKE ? OR u.full_name LIKE ? OR r.description LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$rows = db_all(
    "SELECT r.*,
            u.full_name  AS citizen_name,
            u.phone      AS citizen_phone,
            wu.full_name AS worker_name,
            w.worker_code,
            t.team_name,
            v.vehicle_number,
            p.plan_code,
            i.file_path  AS image_path
       FROM waste_requests r
       LEFT JOIN users u              ON u.id = r.user_id
       LEFT JOIN workers w            ON w.id = r.assigned_worker_id
       LEFT JOIN users wu             ON wu.id = w.user_id
       LEFT JOIN teams t              ON t.id = r.assigned_team_id
       LEFT JOIN vehicles v           ON v.id = r.vehicle_id
       LEFT JOIN collection_plans p   ON p.id = r.collection_plan_id
       LEFT JOIN request_images i     ON i.request_id = r.id AND i.image_type = 'waste'
       $sqlWhere
      ORDER BY r.priority_score DESC, r.created_at DESC
      LIMIT $limit OFFSET $offset",
    $params
);

// Same filter without LIMIT so the UI can show "showing X of Y".
$total = (int) db_value("SELECT COUNT(*) FROM waste_requests r $sqlWhere", $params, 0);

$out = array_map(static function (array $r): array {
    $w = waste_meta($r['waste_type']);
    return [
        'id'           => (int) $r['id'],
        'code'         => $r['request_code'],
        'waste_type'   => $r['waste_type'],
        'waste_label'  => $w['label'],
        'waste_icon'   => $w['icon'],
        'waste_color'  => $w['color'],
        'quantity'     => $r['quantity'],
        'quantity_label'=> quantity_options()[$r['quantity']] ?? $r['quantity'],
        'description'  => $r['description'],
        'address'      => $r['address'],
        'landmark'     => $r['landmark'],
        'latitude'     => $r['latitude'] !== null ? (float) $r['latitude'] : null,
        'longitude'    => $r['longitude'] !== null ? (float) $r['longitude'] : null,
        'status'       => $r['status'],
        'status_label' => status_meta($r['status'])['label'],
        'priority_score'=> (int) $r['priority_score'],
        'priority_level'=> $r['priority_level'],
        'citizen_name' => $r['citizen_name'],
        'citizen_phone'=> $r['citizen_phone'],
        'worker_name'  => $r['worker_name'],
        'worker_code'  => $r['worker_code'],
        'team_name'    => $r['team_name'],
        'vehicle'      => $r['vehicle_number'],
        'plan_code'    => $r['plan_code'],
        'plan_stop'    => $r['plan_stop_number'],
        'estimated_visit_time' => $r['estimated_visit_time'],
        'created_at'   => $r['created_at'],
        'collected_at' => $r['collected_at'],
        'verified_at'  => $r['verified_at'],
        'image'        => image_url($r['image_path']),
    ];
}, $rows);

json_success('Loaded ' . count($out) . ' request(s).', [
    'requests' => $out,
    'total'    => $total,
    'limit'    => $limit,
    'offset'   => $offset,
]);
