<?php
/**
 * api/assignments/create.php   POST  (admin)
 * Body: { request_id, worker_id, note, match_score }
 *
 * Assigns a request to a worker/team, keeps the audit trail in assignments,
 * bumps the worker load and notifies both the worker and the citizen.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/recommend.php';

try {
    $adminId = api_require_role('admin');
    api_require_csrf();

    $requestId = (int) jinput('request_id', 0);
    $workerId  = (int) jinput('worker_id', 0);
    $note      = clean_str(jinput('note', ''), 255);
    $matchScore = jinput('match_score');

    if ($requestId <= 0 || $workerId <= 0) {
        json_error('Both request_id and worker_id are required.', 422);
    }

    $req = db_row('SELECT * FROM waste_requests WHERE id = ?', [$requestId]);
    if (!$req) {
        json_error('Request not found.', 404);
    }
    if (in_array($req['status'], ['collected', 'verified', 'cancelled'], true)) {
        json_error('This request is already closed.', 422);
    }

    // Worker must exist; the full row is re-read under lock inside the transaction.
    if (!db_value('SELECT 1 FROM workers WHERE id = ? AND status <> "removed"', [$workerId])) {
        json_error('Worker not found.', 404);
    }

    try {
        db()->beginTransaction();

        // Lock the request row so two admins cannot assign the same request at
        // the same time (prevents duplicate/concurrent assignments).
        $req = db_row('SELECT * FROM waste_requests WHERE id = ? FOR UPDATE', [$requestId]);
        if (!$req) {
            db()->rollBack();
            json_error('Request not found.', 404);
        }
        if (in_array($req['status'], ['collected', 'verified', 'cancelled'], true)) {
            db()->rollBack();
            json_error('This request is already closed.', 422);
        }

        // Lock the worker slot before scoring capacity / eligibility.
        if (!db_row('SELECT id FROM workers WHERE id = ? AND status <> "removed" FOR UPDATE', [$workerId])) {
            db()->rollBack();
            json_error('Worker not found.', 404);
        }

        $worker = db_row(
            'SELECT w.*, u.full_name, u.id AS wuser, t.team_name,
                    t.waste_types AS team_waste_types, t.status AS team_status,
                    v.vehicle_number, v.vehicle_type, v.vehicle_status
               FROM workers w
               JOIN users u         ON u.id = w.user_id
               LEFT JOIN teams t    ON t.id = w.team_id
               LEFT JOIN vehicles v ON v.id = w.vehicle_id
              WHERE w.id = ?',
            [$workerId]
        );

        // --- HARD ELIGIBILITY GATE (authoritative, server-side) -----------
        // Equipment compatibility, availability, capacity and service
        // constraints are enforced here: an ineligible team can never be
        // assigned even if a crafted request or stale UI asks for it.
        $gate = evaluate_worker_eligibility($worker, $req);
        if (!$gate['eligible']) {
            db()->rollBack();
            json_error(
                'This team is not eligible for this request: ' . implode('; ', $gate['reasons']) . '.',
                422,
                ['ineligible_reasons' => $gate['reasons']]
            );
        }

        // Distance from the worker to the pickup (for the audit row + ETA).
        $distance = null;
        if ($worker['latitude'] !== null && $worker['longitude'] !== null
            && $req['latitude'] !== null && $req['longitude'] !== null) {
            $distance = round(haversine_km(
                (float) $worker['latitude'], (float) $worker['longitude'],
                (float) $req['latitude'], (float) $req['longitude']
            ), 2);
        }
        $etaMinutes = $distance !== null ? estimate_drive_minutes($distance) : 60;
        $visitAt    = date('Y-m-d H:i:s', time() + $etaMinutes * 60);

        // Close any previous open assignment for this request.
        db_query(
            'UPDATE assignments SET status = "cancelled"
              WHERE request_id = ? AND status IN ("assigned","accepted","on_the_way")',
            [$requestId]
        );

        // If it was assigned to somebody else, free that worker slot.
        if ($req['assigned_worker_id'] && (int) $req['assigned_worker_id'] !== $workerId) {
            release_worker_load((int) $req['assigned_worker_id']);
        } else {
            bump_worker_load($workerId, 1);
        }

        db_query(
            'INSERT INTO assignments (request_id, worker_id, team_id, vehicle_id, assigned_by,
                                      distance_km, match_score, reason, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "assigned")',
            [
                $requestId, $workerId, $worker['team_id'], $worker['vehicle_id'], $adminId,
                $distance,
                is_numeric($matchScore) ? (float) $matchScore : null,
                $note ?: 'Assigned from the admin dashboard',
            ]
        );

        db_query(
            'UPDATE waste_requests
                SET assigned_worker_id = ?, assigned_team_id = ?, vehicle_id = ?,
                    status = "assigned", estimated_visit_time = ?, updated_at = NOW()
              WHERE id = ?',
            [$workerId, $worker['team_id'], $worker['vehicle_id'], $visitAt, $requestId]
        );

        // Requests above 60 points are flagged as "prioritized" for the board.
        if ((int) $req['priority_score'] >= 61 && $req['status'] === 'pending') {
            db_query('UPDATE waste_requests SET status = "assigned" WHERE id = ?', [$requestId]);
        }

        db()->commit();
    } catch (Throwable $inner) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $inner;
    }

    // Notifications
    notify((int) $worker['wuser'], 'New pickup assigned #' . $requestId,
        waste_meta($req['waste_type'])['label'] . ' at ' . $req['address'] .
        ' â€” priority ' . $req['priority_score'] . '/100',
        'warning', '/worker/mission.php?id=' . $requestId);

    if ($req['user_id']) {
        notify((int) $req['user_id'], 'A collection team is on the way',
            $worker['full_name'] . ' (' . ($worker['team_name'] ?? 'Sanitation') . ') will visit on ' . fmt_dt($visitAt),
            'success', '/citizen/track.php?id=' . $requestId);
    }

    log_activity('assign', 'waste_request', $requestId,
        "Request #$requestId assigned to " . $worker['full_name'] .
        ' (' . ($worker['team_name'] ?? 'no team') . ')' . ($distance !== null ? ', ' . $distance . ' km away' : ''));

    json_success('Assigned to ' . $worker['full_name'] . '.', [
        'request_id'   => $requestId,
        'worker_id'    => $workerId,
        'worker_name'  => $worker['full_name'],
        'team_name'    => $worker['team_name'],
        'distance_km'  => $distance,
        'eta_minutes'  => $etaMinutes,
        'estimated_visit_time' => $visitAt,
    ]);
} catch (Throwable $e) {
    api_handle_exception($e);
}
