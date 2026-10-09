<?php
/**
 * api/admin/verify.php   POST  (admin)
 * Body: { id, decision: approve|reject, note }
 *
 * approve -> status becomes "verified" (the citizen finally sees it as done)
 * reject  -> status falls back to "in_progress" so the team must re-upload
 *           proper before/after proof
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

try {
    $adminId = api_require_role('admin');
    api_require_csrf();

    $id       = (int) jinput('id', 0);
    $decision = clean_str((string) jinput('decision', ''), 10);
    $note     = clean_str((string) jinput('note', ''), 255);

    if (!in_array($decision, ['approve', 'reject'], true)) {
        json_error('Decision must be approve or reject.', 422);
    }

    $req = db_row('SELECT * FROM waste_requests WHERE id = ?', [$id]);
    if (!$req) {
        json_error('Request not found.', 404);
    }

    // --- Proof must exist before anything can be verified -------------------
    if (!$req['before_image'] || !$req['after_image']) {
        json_error('This pickup has no before/after proof photos yet.', 422);
    }

    if ($decision === 'approve') {
        db_query(
            'UPDATE waste_requests
                SET status = "verified", verified_at = NOW(), verified_by = ?, updated_at = NOW()
              WHERE id = ?',
            [$adminId, $id]
        );
        if ($req['assigned_worker_id']) {
            release_worker_load((int) $req['assigned_worker_id']);
        }
        log_activity('verify', 'waste_request', $id,
            'Request #' . $id . ' verified after before/after review' . ($note ? ' â€” ' . $note : ''));

        if ($req['user_id']) {
            notify((int) $req['user_id'], 'Request #' . $id . ' verified ðŸŽ‰',
                'Thank you! The collection at ' . $req['address'] . ' has been verified complete.',
                'success', '/citizen/track.php?id=' . $id);
        }

        json_success('Collection verified and closed.', ['id' => $id, 'status' => 'verified']);
    }

    // --- reject: send it back for better proof -----------------------------
    db_query(
        'UPDATE waste_requests
            SET status = "in_progress", is_rejected = 0,
                reject_reason = ?, collected_at = NULL, updated_at = NOW()
          WHERE id = ?',
        [$note ?: 'Proof rejected â€” please re-upload clear before/after photos', $id]
    );

    if ($planStop = db_row('SELECT id FROM collection_plan_stops WHERE request_id = ? AND status = "collected" ORDER BY id DESC LIMIT 1', [$id])) {
        db_query('UPDATE collection_plan_stops SET status = "in_progress", completed_at = NULL WHERE id = ?', [$planStop['id']]);
    }

    if ($req['user_id']) {
        notify((int) $req['user_id'], 'Request #' . $id . ' needs attention',
            $note ?: 'Our team is re-checking this collection.', 'warning', '/citizen/track.php?id=' . $id);
    }

    log_activity('verify_reject', 'waste_request', $id,
        'Proof rejected for request #' . $id . ' â€” ' . ($note ?: 'no note'));

    json_success('Proof rejected. The request is back with the collection team.', [
        'id' => $id, 'status' => 'in_progress',
    ]);
} catch (Throwable $e) {
    api_handle_exception($e);
}
