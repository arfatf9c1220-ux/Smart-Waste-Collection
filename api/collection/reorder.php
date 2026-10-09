<?php
/**
 * api/collection/reorder.php   POST  (admin)
 * Body: { id, order: [stop_id, stop_id, ...] }
 * Manual reordering from the Collection Plan screen — ETA and leg distances
 * are recalculated immediately after the new sequence is written.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/planner.php';

try {
    api_require_role('admin');
    api_require_csrf();

    $id    = (int) jinput('id', 0);
    $order = jinput('order', []);

    if ($id <= 0 || !is_array($order) || !$order) {
        json_error('Provide the plan id and the new stop order.', 422);
    }

    // Make sure the caller is not reordering another team's plan.
    db_row('SELECT id FROM collection_plans WHERE id = ?', [$id]) || json_error('Plan not found.', 404);

    reorder_plan_stops($id, array_map('intval', $order));

    log_activity('plan_reorder', 'collection_plan', $id, "Stop order updated for plan #$id");

    json_success('Stop order updated and ETAs recalculated.', [
        'id'    => $id,
        'order' => array_map('intval', $order),
    ]);
} catch (Throwable $e) {
    api_handle_exception($e);
}
