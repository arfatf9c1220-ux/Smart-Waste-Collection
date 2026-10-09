<?php
/**
 * api/admin/set-status.php   POST  (admin)
 * Body: { id, status }
 * Manual status override used when the board needs to correct the workflow.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

$ALLOWED = ['pending', 'prioritized', 'assigned', 'scheduled', 'in_progress', 'collected', 'verified', 'cancelled'];

try {
    api_require_role('admin');
    api_require_csrf();

    $id     = (int) jinput('id', 0);
    $status = clean_str((string) jinput('status', ''), 15);

    if (!in_array($status, $ALLOWED, true)) {
        json_error('Unknown status.', 422);
    }
    $req = db_row('SELECT * FROM waste_requests WHERE id = ?', [$id]);
    if (!$req) {
        json_error('Request not found.', 404);
    }

    $sets = ['status = ?'];
    $params = [$status];

    if ($status === 'collected' && !$req['collected_at']) {
        $sets[] = 'collected_at = NOW()';
    }
    if ($status === 'verified') {
        $sets[] = 'verified_at = NOW()';
        $sets[] = 'verified_by = ?';
        $params[] = current_user_id();
        // Verification is only honest when proof photos exist.
        if (!$req['before_image'] || !$req['after_image']) {
            json_error('Cannot verify: before and after photos are missing.', 422);
        }
    }
    if ($status === 'cancelled') {
        $sets[] = 'reject_reason = ?';
        $params[] = 'Cancelled by municipal authority';
    }

    $params[] = $id;
    db_query('UPDATE waste_requests SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);

    if ($req['user_id']) {
        notify((int) $req['user_id'], 'Request #' . $id . ' — ' . status_meta($status)['label'],
            'Your request status is now ' . status_meta($status)['label'] . '.',
            $status === 'verified' ? 'success' : 'info',
            '/citizen/track.php?id=' . $id);
    }

    log_activity('status_change', 'waste_request', $id,
        "Status changed {$req['status']} -> $status by admin");

    json_success('Status changed to ' . status_meta($status)['label'] . '.', ['id' => $id, 'status' => $status]);
} catch (Throwable $e) {
    api_handle_exception($e);
}
