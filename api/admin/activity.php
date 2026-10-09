<?php
/**
 * api/admin/activity.php   GET ?limit=50   (admin)
 * Recent audit trail from activity_logs.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

api_require_role('admin');

$limit  = max(5, min(200, (int) ($_GET['limit'] ?? 50)));
$filter = clean_str($_GET['action'] ?? '', 40);

$sql = 'SELECT l.*, u.full_name, u.role FROM activity_logs l
        LEFT JOIN users u ON u.id = l.user_id';
$params = [];
if ($filter !== '') {
    $sql .= ' WHERE l.action = ?';
    $params[] = $filter;
}
$sql .= ' ORDER BY l.id DESC LIMIT ' . $limit;

$rows = db_all($sql, $params);

$ICON = [
    'login' => 'bi-box-arrow-in-right', 'logout' => 'bi-box-arrow-right',
    'register' => 'bi-person-plus', 'create_request' => 'bi-megaphone',
    'assign' => 'bi-person-check', 'plan_created' => 'bi-signpost-split',
    'plan_start' => 'bi-play-circle', 'plan_complete' => 'bi-check2-circle',
    'plan_cancel' => 'bi-x-circle', 'plan_assign' => 'bi-person-badge',
    'plan_reorder' => 'bi-arrow-down-up', 'collect' => 'bi-truck',
    'start_collection' => 'bi-play-fill', 'arrive' => 'bi-geo-alt-fill',
    'verify' => 'bi-patch-check', 'verify_reject' => 'bi-patch-exclamation',
    'reject' => 'bi-slash-circle', 'priority_override' => 'bi-graph-up-arrow',
    'status_change' => 'bi-arrow-repeat', 'cancel' => 'bi-x-circle',
    'hotspot_recompute' => 'bi-fire',
    'upload_before' => 'bi-camera', 'upload_after' => 'bi-camera-fill',
    'upload_waste' => 'bi-image',
];
$COLOR = [
    'verify' => 'success', 'collect' => 'success', 'plan_complete' => 'success',
    'reject' => 'danger', 'verify_reject' => 'danger', 'cancel' => 'danger', 'plan_cancel' => 'danger',
    'assign' => 'primary', 'plan_created' => 'primary', 'create_request' => 'info',
    'hotspot_recompute' => 'warning', 'priority_override' => 'warning',
];

json_success('Activity loaded.', [
    'logs' => array_map(static fn(array $l): array => [
        'id'          => (int) $l['id'],
        'action'      => $l['action'],
        'action_label'=> ucwords(str_replace('_', ' ', (string) $l['action'])),
        'icon'        => $ICON[$l['action']] ?? 'bi-dot',
        'color'       => $COLOR[$l['action']] ?? 'secondary',
        'entity_type' => $l['entity_type'],
        'entity_id'   => $l['entity_id'],
        'description' => $l['description'],
        'actor'       => $l['full_name'] ?? 'System',
        'role'        => $l['actor_role'],
        'created_at'  => $l['created_at'],
    ], $rows),
]);
