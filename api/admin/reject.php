<?php
/**
 * api/admin/reject.php   POST  (admin)
 * Body: { id, reason }
 * Marks a citizen report as invalid (spam, duplicate, not a waste issue).
 * The request disappears from the operational queues but stays in analytics.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

try {
    api_require_role('admin');
    api_require_csrf();

    $id     = (int) jinput('id', 0);
    $reason = clean_str((string) jinput('reason', ''), 255);

    if ($reason === '') {
        json_error('Please give a reason so the citizen understands.', 422);
    }

    $req = db_row('SELECT * FROM waste_requests WHERE id = ?', [$id]);
    if (!$req) {
        json_error('Request not found.', 404);
    }
    if (in_array($req['status'], ['collected', 'verified'], true)) {
        json_error('A completed request cannot be rejected.', 422);
    }

    db_query(
        'UPDATE waste_requests
            SET is_rejected = 1, reject_reason = ?, status = "cancelled", updated_at = NOW()
          WHERE id = ?',
        [$reason, $id]
    );
    db_query('UPDATE assignments SET status = "cancelled" WHERE request_id = ?', [$id]);

    if ($req['assigned_worker_id']) {
        release_worker_load((int) $req['assigned_worker_id']);
    }

    if ($req['user_id']) {
        notify((int) $req['user_id'], 'Request #' . $id . ' could not be accepted',
            $reason, 'danger', '/citizen/track.php?id=' . $id);
    }

    log_activity('reject', 'waste_request', $id, "Request #$id rejected as invalid: $reason");

    json_success('Request rejected as invalid.', ['id' => $id, 'status' => 'cancelled']);
} catch (Throwable $e) {
    api_handle_exception($e);
}
