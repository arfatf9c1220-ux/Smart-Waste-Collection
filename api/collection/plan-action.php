<?php
/**
 * api/collection/plan-action.php   POST  (admin)
 * Body: { id, action: start|complete|cancel|assign, notes? }
 *
 * start    -> plan becomes active, first stop is unlocked, requests -> scheduled
 * complete -> plan finished
 * cancel   -> plan cancelled, its requests go back to "prioritized" so they
 *             can be put on another plan
 * assign   -> move the plan to a different worker/team
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

try {
    $adminId = api_require_role('admin');
    api_require_csrf();

    $id     = (int) jinput('id', 0);
    $action = (string) jinput('action', '');

    $plan = db_row('SELECT * FROM collection_plans WHERE id = ?', [$id]);
    if (!$plan) {
        json_error('Collection plan not found.', 404);
    }

    switch ($action) {
        // ------------------------------------------------------------- START
        case 'start':
            if ($plan['status'] === 'active') {
                json_error('This plan is already running.', 422);
            }
            if ($plan['status'] === 'cancelled' || $plan['status'] === 'completed') {
                json_error('This plan is closed and cannot be started.', 422);
            }
            db_query('UPDATE collection_plans SET status = "active", started_at = COALESCE(started_at, NOW()) WHERE id = ?', [$id]);

            // Unlock the requests: workers can now act on them.
            db_query(
                'UPDATE waste_requests r
                   JOIN collection_plan_stops s ON s.request_id = r.id
                    SET r.status = "scheduled", r.updated_at = NOW()
                  WHERE s.plan_id = ? AND r.status IN ("pending","prioritized","assigned")',
                [$id]
            );

            // Worker goes on the road.
            if ($plan['worker_id']) {
                db_query('UPDATE workers SET status = "busy", availability = "busy", last_seen_at = NOW() WHERE id = ?', [$plan['worker_id']]);
            }

            $wUser = worker_user_id($plan['worker_id'] !== null ? (int) $plan['worker_id'] : null);
            if ($wUser) {
                notify($wUser, 'Route started: ' . $plan['plan_code'],
                    $plan['total_stops'] . ' stops · ' . $plan['total_distance_km'] . ' km. Tap START ROUTE to begin.',
                    'plan', '/worker/mission.php?plan=' . $id);
            }
            log_activity('plan_start', 'collection_plan', $id, "Plan {$plan['plan_code']} started");
            json_success('Plan started. The worker has been notified.', ['id' => $id, 'status' => 'active']);
            break;

        // ---------------------------------------------------------- COMPLETE
        case 'complete':
            $open = (int) db_value(
                'SELECT COUNT(*) FROM collection_plan_stops WHERE plan_id = ? AND status <> "collected"',
                [$id], 0);
            if ($open > 0 && !jinput('force')) {
                json_error("$open stop(s) are still not collected. Finish them or use Force complete.", 422);
            }
            db_query('UPDATE collection_plans SET status = "completed", completed_at = NOW() WHERE id = ?', [$id]);
            db_query(
                'UPDATE waste_requests
                    SET status = "collected", collected_at = COALESCE(collected_at, NOW()), updated_at = NOW()
                  WHERE collection_plan_id = ?
                    AND status IN ("scheduled","in_progress","assigned")',
                [$id]
            );
            if ($plan['worker_id']) {
                db_query('UPDATE workers SET status = "available", availability = "available",
                                   current_load = 0, last_seen_at = NOW() WHERE id = ?', [$plan['worker_id']]);
            }
            log_activity('plan_complete', 'collection_plan', $id, "Plan {$plan['plan_code']} completed");
            json_success('Plan completed.', ['id' => $id, 'status' => 'completed']);
            break;

        // ----------------------------------------------------------- CANCEL
        case 'cancel':
            if ($plan['status'] === 'completed') {
                json_error('A completed plan cannot be cancelled.', 422);
            }
            db_query('UPDATE collection_plans SET status = "cancelled" WHERE id = ?', [$id]);
            db_query(
                'UPDATE waste_requests
                    SET status = IF(priority_score >= 61, "prioritized", "pending"),
                        collection_plan_id = NULL, plan_stop_number = NULL, updated_at = NOW()
                  WHERE collection_plan_id = ?
                    AND status IN ("scheduled","in_progress","assigned")',
                [$id]
            );
            if ($plan['worker_id']) {
                release_worker_load((int) $plan['worker_id'], (int) $plan['total_stops']);
            }
            log_activity('plan_cancel', 'collection_plan', $id, "Plan {$plan['plan_code']} cancelled");
            json_success('Plan cancelled. Its requests are back in the pending queue.', ['id' => $id, 'status' => 'cancelled']);
            break;

        // ----------------------------------------------------------- ASSIGN
        case 'assign':
            $workerId = (int) jinput('worker_id', 0);
            $worker   = db_row('SELECT w.*, u.full_name FROM workers w JOIN users u ON u.id = w.user_id WHERE w.id = ?', [$workerId]);
            if (!$worker) {
                json_error('Worker not found.', 404);
            }
            db_query(
                'UPDATE collection_plans SET worker_id = ?, team_id = ?, vehicle_id = ?, status = "assigned" WHERE id = ?',
                [$workerId, $worker['team_id'], $worker['vehicle_id'], $id]
            );
            db_query(
                'UPDATE waste_requests r
                   JOIN collection_plan_stops s ON s.request_id = r.id
                    SET r.assigned_worker_id = ?, r.assigned_team_id = ?, r.vehicle_id = ?
                  WHERE s.plan_id = ?',
                [$workerId, $worker['team_id'], $worker['vehicle_id'], $id]
            );
            notify((int) $worker['user_id'], 'Plan ' . $plan['plan_code'] . ' assigned to you',
                $plan['total_stops'] . ' stops are waiting.', 'plan', '/worker/mission.php?plan=' . $id);
            log_activity('plan_assign', 'collection_plan', $id,
                "Plan {$plan['plan_code']} assigned to " . $worker['full_name']);
            json_success('Plan assigned to ' . $worker['full_name'] . '.', ['id' => $id, 'worker_id' => $workerId]);
            break;

        default:
            json_error('Unknown action.', 422);
    }
} catch (Throwable $e) {
    api_handle_exception($e);
}
