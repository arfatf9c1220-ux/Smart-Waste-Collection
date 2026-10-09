<?php
/**
 * api/workers/update-location.php   POST  (worker)
 * Body: { lat, lng, accuracy, speed, heading, status }
 * Writes a GPS breadcrumb into worker_locations and moves the "live" position
 * on the workers row so the admin map and the recommender see it immediately.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

try {
    $worker = current_worker();
    if (!$worker) {
        json_error('Worker profile not found for this account.', 403);
    }
    api_require_csrf();

    $lat      = jinput('lat');
    $lng      = jinput('lng');
    $accuracy = jinput('accuracy');
    $speed    = jinput('speed');
    $heading  = jinput('heading');
    $status   = (string) jinput('status', $worker['status']);

    if (!valid_coords($lat, $lng)) {
        json_error('Invalid GPS coordinates.', 422);
    }
    if (!in_array($status, ['available', 'busy', 'offline'], true)) {
        $status = 'available';
    }

    db_query(
        'INSERT INTO worker_locations (worker_id, latitude, longitude, accuracy_m, speed_kph, heading, status, source)
         VALUES (?, ?, ?, ?, ?, ?, ?, "gps")',
        [
            $worker['id'],
            (float) $lat,
            (float) $lng,
            is_numeric($accuracy) ? (float) $accuracy : null,
            is_numeric($speed)    ? (float) $speed    : null,
            is_numeric($heading)  ? (float) $heading  : null,
            $status,
        ]
    );

    db_query(
        'UPDATE workers
            SET latitude = ?, longitude = ?, status = ?, availability = ?, last_seen_at = NOW(), updated_at = NOW()
          WHERE id = ?',
        [(float) $lat, (float) $lng, $status, $status, $worker['id']]
    );

    // Keep the trail to the last 60 fixes per worker.
    db_query(
        'DELETE FROM worker_locations
          WHERE worker_id = ? AND id NOT IN (
                SELECT * FROM (
                    SELECT id FROM worker_locations
                     WHERE worker_id = ?
                     ORDER BY recorded_at DESC, id DESC LIMIT 60
                ) AS keep_rows
          )',
        [$worker['id'], $worker['id']]
    );

    json_success('Location updated.', [
        'worker_id' => (int) $worker['id'],
        'latitude'  => (float) $lat,
        'longitude' => (float) $lng,
        'status'    => $status,
    ]);
} catch (Throwable $e) {
    api_handle_exception($e);
}
