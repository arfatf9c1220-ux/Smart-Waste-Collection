<?php
/**
 * api/workers/status.php   POST  (worker)
 * Body: { status: available|busy|offline }
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

try {
    $worker = current_worker();
    if (!$worker) {
        json_error('Worker profile not found for this account.', 403);
    }
    api_require_csrf();

    $status = (string) jinput('status', '');
    if (!in_array($status, ['available', 'busy', 'offline'], true)) {
        json_error('Status must be available, busy or offline.', 422);
    }

    db_query(
        'UPDATE workers SET status = ?, availability = ?, last_seen_at = NOW(), updated_at = NOW() WHERE id = ?',
        [$status, $status, $worker['id']]
    );

    json_success('You are now marked as ' . $status . '.', [
        'worker_id' => (int) $worker['id'],
        'status'    => $status,
    ]);
} catch (Throwable $e) {
    api_handle_exception($e);
}
