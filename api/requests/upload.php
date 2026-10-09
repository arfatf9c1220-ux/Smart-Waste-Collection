<?php
/**
 * api/requests/upload.php   POST  (multipart/form-data)
 * Fields: id, type (before|after|waste), image (file), lat, lng
 *
 * Used by the citizen report form (waste) and by the worker proof uploads
 * (before / after). The stored path is written back onto waste_requests so
 * the verification rule can check both files exist.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/upload.php';

try {
    $uid  = api_require_login();
    $role = current_role();
    api_require_csrf();

    $id   = (int) ($_POST['id'] ?? 0);
    $type = clean_str($_POST['type'] ?? 'waste', 10);

    if ($id <= 0) {
        json_error('Missing request id.', 422);
    }
    if (!in_array($type, ['waste', 'before', 'after'], true)) {
        json_error('Unknown image type.', 422);
    }

    $req = db_row('SELECT * FROM waste_requests WHERE id = ?', [$id]);
    if (!$req) {
        json_error('Request not found.', 404);
    }

    // --- authorisation ------------------------------------------------------
    if ($role === 'citizen') {
        $c = current_citizen();
        if ((int) ($c['id'] ?? 0) !== (int) $req['citizen_id']) {
            json_error('You can only add photos to your own request.', 403);
        }
        // The before/after images are the collection proof an officer verifies.
        // A citizen must never be able to write them, otherwise they could
        // forge evidence that their own pickup was completed correctly.
        if ($type !== 'waste') {
            json_error('You can only add photos of the waste itself.', 403);
        }
        if (in_array($req['status'], ['collected', 'verified'], true)) {
            json_error('This pickup is already closed, so it cannot be changed.', 422);
        }
    } elseif ($role === 'worker') {
        $w = current_worker();
        if ((int) ($w['id'] ?? 0) !== (int) $req['assigned_worker_id']) {
            json_error('This pickup is not assigned to you.', 403);
        }
        // Workers may only upload the collection proof photos.
        if ($type === 'waste') {
            json_error('Workers can only upload before / after proof photos.', 403);
        }
    } elseif ($role !== 'admin') {
        json_error('Not allowed.', 403);
    }

    if (empty($_FILES['image'])) {
        json_error('No image was uploaded.', 422);
    }

    $path = save_uploaded_image($_FILES['image'], $type);

    $lat = isset($_POST['lat']) && $_POST['lat'] !== '' ? (float) $_POST['lat'] : null;
    $lng = isset($_POST['lng']) && $_POST['lng'] !== '' ? (float) $_POST['lng'] : null;

    record_image($id, $type, $path, mime_content_type(
        dirname(__DIR__, 2) . '/' . $path
    ) ?: null, (int) ($_FILES['image']['size'] ?? 0), $lat, $lng);

    // Mirror the path onto the request row (used by the "proof required" rule).
    if ($type === 'waste') {
        db_query('UPDATE waste_requests SET image_path = ? WHERE id = ?', [$path, $id]);
    } elseif ($type === 'before') {
        db_query('UPDATE waste_requests SET before_image = ?, updated_at = NOW() WHERE id = ?', [$path, $id]);
    } elseif ($type === 'after') {
        db_query('UPDATE waste_requests SET after_image = ?, updated_at = NOW() WHERE id = ?', [$path, $id]);
    }

    log_activity('upload_' . $type, 'waste_request', $id,
        strtoupper($type) . ' photo uploaded for request #' . $id);

    json_success(strtoupper($type) . ' photo uploaded.', [
        'id'       => $id,
        'type'     => $type,
        'url'      => image_url($path),
        'path'     => $path,
        'has_before' => (bool) db_value('SELECT before_image FROM waste_requests WHERE id = ?', [$id]),
        'has_after'  => (bool) db_value('SELECT after_image FROM waste_requests WHERE id = ?', [$id]),
    ]);
} catch (Throwable $e) {
    api_handle_exception($e);
}
