<?php
/**
 * api/citizen/feedback.php   POST  (citizen)
 * Body: { id, rating, comment }
 * Citizen closes the loop by rating the collection.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

try {
    $uid = api_require_login();
    api_require_csrf();

    $id      = (int) jinput('id', 0);
    $rating  = (int) jinput('rating', 0);
    $comment = clean_str((string) jinput('comment', ''), 255);

    $citizen = current_citizen();
    $req = db_row('SELECT * FROM waste_requests WHERE id = ?', [$id]);
    if (!$req) {
        json_error('Request not found.', 404);
    }
    if ((int) ($citizen['id'] ?? 0) !== (int) $req['citizen_id']) {
        json_error('You can only rate your own request.', 403);
    }
    if ($rating < 1 || $rating > 5) {
        json_error('Please pick a rating between 1 and 5 stars.', 422);
    }

    db_query(
        'UPDATE waste_requests SET citizen_rating = ?, citizen_feedback = ?, updated_at = NOW() WHERE id = ?',
        [$rating, $comment ?: null, $id]
    );

    log_activity('rate', 'waste_request', $id, "Citizen rated request #$id as $rating/5");

    json_success('Thanks for your feedback!', ['id' => $id, 'rating' => $rating]);
} catch (Throwable $e) {
    api_handle_exception($e);
}
