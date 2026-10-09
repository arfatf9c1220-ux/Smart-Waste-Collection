<?php
/**
 * api/requests/update-status.php   POST  (worker only)
 * Body: { id, action }
 *   action = arrive  -> ARRIVED           (status becomes in_progress, plan stop arrived)
 *   action = start   -> START COLLECTION  (status stays in_progress, started_at set)
 *   action = collect -> MARK COLLECTED    (requires BEFORE + AFTER proof photos)
 *
 * The "collect" action is the heart of the before/after verification rule:
 * it refuses to run unless both proof photos exist.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/upload.php';

$worker = null;
try {
    $worker = current_worker();
    if (!$worker) {
        json_error('Worker profile not found for this account.', 403);
    }
    api_require_csrf();

    $id     = (int) jinput('id', 0);
    $action = (string) jinput('action', '');

    if ($id <= 0) {
        json_error('Missing request id.', 422);
    }

    $req = db_row('SELECT * FROM waste_requests WHERE id = ?', [$id]);
    if (!$req) {
        json_error('Request not found.', 404);
    }
    if ((int) $req['assigned_worker_id'] !== (int) $worker['id']) {
        json_error('This pickup is not assigned to you.', 403);
    }

    $planStop = $req['collection_plan_id']
        ? db_row('SELECT * FROM collection_plan_stops WHERE plan_id = ? AND request_id = ?',
                  [$req['collection_plan_id'], $id])
        : null;

    $message = '';
    $newStatus = $req['status'];

    switch ($action) {
        // ---------------------------------------------------------- ARRIVED
        case 'arrive':
            if (in_array($req['status'], ['collected', 'verified', 'cancelled'], true)) {
                json_error('This request is already closed.', 422);
            }
            $newStatus = 'in_progress';
            db_query(
                'UPDATE waste_requests
                    SET status = "in_progress", arrived_at = NOW(), started_at = COALESCE(started_at, NOW()),
                        collection_lat = ?, collection_lng = ?, updated_at = NOW()
                  WHERE id = ?',
                [
                    jinput('lat') !== null && valid_coords(jinput('lat'), jinput('lng'))
                        ? (float) jinput('lat') : $req['latitude'],
                    jinput('lat') !== null && valid_coords(jinput('lat'), jinput('lng'))
                        ? (float) jinput('lng') : $req['longitude'],
                    $id,
                ]
            );
            if ($planStop) {
                db_query('UPDATE collection_plan_stops SET status = "arrived", arrived_at = NOW() WHERE id = ?', [$planStop['id']]);
            }
            db_query(
                'UPDATE assignments SET status = "on_the_way", accepted_at = COALESCE(accepted_at, NOW())
                  WHERE request_id = ? AND worker_id = ?',
                [$id, $worker['id']]
            );
            log_activity('arrive', 'waste_request', $id, "Worker arrived for request #$id");
            $message = 'Arrival recorded at the pickup point.';
            break;

        // -------------------------------------------------- START COLLECTION
        case 'start':
            if (in_array($req['status'], ['collected', 'verified', 'cancelled'], true)) {
                json_error('This request is already closed.', 422);
            }
            $newStatus = 'in_progress';
            db_query(
                'UPDATE waste_requests
                    SET status = "in_progress", started_at = COALESCE(started_at, NOW()),
                        arrived_at = COALESCE(arrived_at, NOW()), updated_at = NOW()
                  WHERE id = ?',
                [$id]
            );
            if ($planStop) {
                db_query('UPDATE collection_plan_stops SET status = "in_progress", arrived_at = COALESCE(arrived_at, NOW()) WHERE id = ?', [$planStop['id']]);
            }
            log_activity('start_collection', 'waste_request', $id, "Worker started collection on request #$id");
            $message = 'Collection started.';
            break;

        // --------------------------------------------------- MARK COLLECTED
        case 'collect':
            // Hard rule: a worker cannot mark waste collected without proof.
            if (!$req['before_image']) {
                json_error('Upload a BEFORE photo before marking the pickup as collected.', 422);
            }
            if (!$req['after_image']) {
                json_error('Upload an AFTER photo before marking the pickup as collected.', 422);
            }
            $newStatus = 'collected';
            db_query(
                'UPDATE waste_requests
                    SET status = "collected", collected_at = NOW(),
                        collection_lat = COALESCE(?, collection_lat),
                        collection_lng = COALESCE(?, collection_lng),
                        updated_at = NOW()
                  WHERE id = ?',
                [
                    jinput('lat') !== null && valid_coords(jinput('lat'), jinput('lng')) ? (float) jinput('lat') : null,
                    jinput('lat') !== null && valid_coords(jinput('lat'), jinput('lng')) ? (float) jinput('lng') : null,
                    $id,
                ]
            );
            if ($planStop) {
                db_query('UPDATE collection_plan_stops SET status = "collected", completed_at = NOW() WHERE id = ?', [$planStop['id']]);
            }
            db_query('UPDATE assignments SET status = "completed", completed_at = NOW() WHERE request_id = ? AND worker_id = ?', [$id, $worker['id']]);
            db_query('UPDATE workers SET total_collected = total_collected + 1 WHERE id = ?', [$worker['id']]);
            log_activity('collect', 'waste_request', $id, "Worker collected request #$id with before/after proof");
            $message = 'Pickup completed. An officer will verify the proof photos shortly.';
            break;

        default:
            json_error('Unknown action: ' . htmlspecialchars($action), 422);
    }

    // Notify the citizen + the municipal desk.
    if ($req['user_id']) {
        notify((int) $req['user_id'],
            'Request #' . $id . ' — ' . status_meta($newStatus)['label'],
            $message,
            $newStatus === 'collected' ? 'success' : 'info',
            '/citizen/track.php?id=' . $id);
    }
    if ($action === 'collect') {
        foreach (db_all('SELECT id FROM users WHERE role = "admin"') as $a) {
            notify((int) $a['id'], 'Proof ready to verify #' . $id,
                'Before/after photos uploaded by ' . $worker['worker_code'],
                'warning', '/admin/verify.php?id=' . $id);
        }
    }

    json_success($message, [
        'id'         => $id,
        'status'     => $newStatus,
        'action'     => $action,
        'plan_stop'  => $planStop['status'] ?? null,
    ]);
} catch (Throwable $e) {
    api_handle_exception($e);
}
