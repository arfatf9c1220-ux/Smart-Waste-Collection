<?php
/**
 * api/collection/create-plan.php   POST  (admin)
 * Body: { worker_id, request_ids: [3,7,9], plan_date, notes }
 *
 * Runs the route optimisation engine, writes collection_plans +
 * collection_plan_stops and flips each request to "scheduled".
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/planner.php';

try {
    $adminId = api_require_role('admin');
    api_require_csrf();

    $workerId = (int) jinput('worker_id', 0);
    $ids      = jinput('request_ids', []);
    $planDate = clean_str(jinput('plan_date', date('Y-m-d')), 10);
    $notes    = clean_str(jinput('notes', ''), 255);

    if ($workerId <= 0) {
        json_error('Choose the team / worker that will run this plan.', 422);
    }
    if (!is_array($ids) || !$ids) {
        json_error('Select at least one request for the plan.', 422);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $planDate)) {
        $planDate = date('Y-m-d');
    }

    $ids = array_values(array_unique(array_map('intval', $ids)));
    $ids = array_values(array_filter($ids, fn($v) => $v > 0));

    $result = create_collection_plan($workerId, $ids, $planDate, $adminId);

    if ($notes !== '') {
        db_query('UPDATE collection_plans SET notes = ? WHERE id = ?', [$notes, $result['plan_id']]);
    }

    $worker = db_row('SELECT * FROM workers WHERE id = ?', [$workerId]);

    json_success('Plan ' . $result['plan_code'] . ' generated with ' . count($result['stops']) . ' stops.', [
        'plan_id'   => $result['plan_id'],
        'plan_code' => $result['plan_code'],
        'worker_id' => $workerId,
        'worker_name' => $worker ? db_value('SELECT full_name FROM users WHERE id = ?', [$worker['user_id']]) : null,
        'stops'     => count($result['stops']),
        'distance_km'=> $result['distance'],
        'duration_min' => $result['duration'],
        'sequence'  => array_map(static fn(array $s): array => [
            'stop'    => $s['stop_number'],
            'request' => (int) $s['request']['id'],
            'code'    => $s['request']['request_code'],
            'priority'=> (int) $s['request']['priority_score'],
            'address' => $s['request']['address'],
            'eta'     => $s['eta'],
            'leg_km'  => $s['distance_from_prev_km'],
        ], $result['stops']),
    ]);
} catch (Throwable $e) {
    api_handle_exception($e);
}
