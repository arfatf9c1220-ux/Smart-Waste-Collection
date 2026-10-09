<?php
/**
 * api/requests/cancel.php   POST  (citizen or admin)
 * Body: { id, reason }
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

try {
    $uid  = api_require_login();
    $role = current_role();
    api_require_csrf();

    $id     = (int) jinput('id', 0);
    $reason = clean_str(jinput('reason', ''), 255);

    $req = db_row('SELECT * FROM waste_requests WHERE id = ?', [$id]);
    if (!$req) {
        json_error('Request not found.', 404);
    }

    if ($role === 'citizen') {
        $c = current_citizen();
        if ((int) ($c['id'] ?? 0) !== (int) $req['citizen_id']) {
            json_error('You can only cancel your own requests.', 403);
        }
    } elseif ($role !== 'admin') {
        json_error('Not allowed.', 403);
    }

    if (in_array($req['status'], ['collected', 'verified'], true)) {
        json_error('This request is already completed and cannot be cancelled.', 422);
    }

    db_query(
        'UPDATE waste_requests SET status = "cancelled", reject_reason = ?, updated_at = NOW() WHERE id = ?',
        [$reason ?: 'Cancelled by ' . $role, $id]
    );
    db_query('UPDATE assignments SET status = "cancelled" WHERE request_id = ?', [$id]);

    // Free up the worker slot.
    if ($req['assigned_worker_id']) {
        release_worker_load((int) $req['assigned_worker_id']);
    }

    log_activity('cancel', 'waste_request', $id, "Request #$id cancelled by $role: $reason");

    json_success('Request cancelled.', ['id' => $id, 'status' => 'cancelled']);
} catch (Throwable $e) {
    api_handle_exception($e);
}
