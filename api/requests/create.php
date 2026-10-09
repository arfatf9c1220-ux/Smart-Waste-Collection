<?php
/**
 * api/requests/create.php   POST
 * Body: waste_type, quantity, description, address, landmark,
 *       latitude, longitude, image (base64 or multipart file)
 *
 * Creates the request, stores the citizen photo, then immediately runs the
 * priority engine so the request is born with a score.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/priority.php';
require_once __DIR__ . '/../../includes/upload.php';

$uid = api_require_login();
api_require_csrf();

if (request_method() !== 'POST') {
    header('Allow: POST');
    json_error('Method not allowed. Use POST.', 405);
}

// Accept both JSON and multipart/form-data.
if (!empty($_FILES['image'])) {
    $payload = $_POST;
} else {
    $payload = json_input();
}
$get = static fn(string $k, $default = '') => $payload[$k] ?? $default;

$wasteType   = clean_str($get('waste_type'), 20);
$quantity    = clean_str($get('quantity'), 10);
$description = clean_str($get('description'), 1000);
$address     = clean_str($get('address'), 255);
$landmark    = clean_str($get('landmark'), 160);
$lat         = isset($payload['latitude'])  && $payload['latitude']  !== '' ? (float) $payload['latitude']  : null;
$lng         = isset($payload['longitude']) && $payload['longitude'] !== '' ? (float) $payload['longitude'] : null;

// --- validation -------------------------------------------------------------
$errors = [];
if (!array_key_exists($wasteType, waste_options()))      $errors[] = 'Please choose a valid waste category.';
if (!array_key_exists($quantity, quantity_options()))   $errors[] = 'Please choose an estimated quantity.';
if (mb_strlen($address) < 8)                            $errors[] = 'Please enter a clear address (min 8 characters).';
if ($description === '' && $landmark === '')             $errors[] = 'Please describe the problem or add a landmark.';
if ($lat === null || $lng === null)                      $errors[] = 'Please pick the waste location on the map or use GPS.';
elseif (!valid_coords($lat, $lng))                       $errors[] = 'Those GPS coordinates are not valid.';
if ($errors) {
    json_error(implode(' ', $errors), 422);
}

$citizen = current_citizen();
if (!$citizen) {
    json_error('Citizen profile not found for this account.', 403);
}

// --- optional citizen photo -------------------------------------------------
$imagePath = null;
try {
    if (!empty($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $imagePath = save_uploaded_image($_FILES['image'], 'waste');
    } elseif (!empty($get('image_base64'))) {
        // JSON upload: a data URL like "data:image/jpeg;base64,...."
        $imagePath = save_base64_image((string) $get('image_base64'), 'waste');
    }
} catch (RuntimeException $ex) {
    json_error($ex->getMessage(), 422);
}

try {
    db()->beginTransaction();

    // Human friendly code: WR-YYYY-000123
    $newId = db_insert(
        'INSERT INTO waste_requests
            (request_code, citizen_id, user_id, waste_type, quantity, description,
             address, landmark, latitude, longitude, image_path, status,
             priority_score, priority_level, priority_factors, gps_accuracy_m)
         VALUES ("TEMP", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending", 0, "low", NULL, ?)',
        [
            $citizen['id'],
            $uid,
            $wasteType,
            $quantity,
            $description ?: null,
            $address,
            $landmark ?: null,
            $lat,
            $lng,
            $imagePath,
            isset($payload['gps_accuracy']) && $payload['gps_accuracy'] !== '' ? (float) $payload['gps_accuracy'] : null,
        ]
    );

    $code = 'WR-' . date('Y') . '-' . str_pad((string) $newId, 6, '0', STR_PAD_LEFT);
    db_query('UPDATE waste_requests SET request_code = ? WHERE id = ?', [$code, $newId]);

    if ($imagePath) {
        record_image($newId, 'waste', $imagePath);
    }

    db()->commit();
} catch (Throwable $e) {
    db()->rollBack();
    api_handle_exception($e);
}

/** Saves a "data:image/...;base64,...." string coming from the browser. */
function save_base64_image(string $dataUrl, string $type): string
{
    if (!preg_match('/^data:(image\/jpeg);base64,(.+)$/is', $dataUrl, $m)) {
        throw new RuntimeException('Only JPEG (.jpg or .jpeg) photos are allowed.');
    }
    $mime      = strtolower($m[1]);
    $binary    = base64_decode(strtr(trim($m[2]), ' ', '+'), true);
    if ($binary === false || $binary === '') {
        throw new RuntimeException('Could not decode the uploaded image.');
    }
    if (strlen($binary) > MAX_UPLOAD_BYTES) {
        throw new RuntimeException('Image is larger than ' . (MAX_UPLOAD_BYTES / 1048576) . ' MB.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $real  = $finfo->buffer($binary);
    if (!in_array($real, ALLOWED_IMAGE_MIMES, true) || $real !== $mime) {
        throw new RuntimeException('Only real JPEG photos are allowed.');
    }
    $info = @getimagesizefromstring($binary);
    if ($info === false || $info[0] < 100 || $info[1] < 100) {
        throw new RuntimeException('Image is too small or not a valid image.');
    }

    $ext  = 'jpg';
    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dir  = dirname(__DIR__, 2) . '/' . UPLOAD_ROOT . '/' . upload_folder_for($type);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($dir . '/' . $name, $binary);
    return UPLOAD_ROOT . '/' . upload_folder_for($type) . '/' . $name;
}

// ---------------------------------------------------------------------------
// PRIORITY INTELLIGENCE runs the moment the request is created.
// ---------------------------------------------------------------------------
$priority = refresh_request_priority($newId);

db_query('UPDATE citizens SET total_reports = total_reports + 1 WHERE id = ?', [$citizen['id']]);

log_activity('create_request', 'waste_request', $newId,
    'Citizen ' . $uid . ' reported ' . $wasteType . ' at ' . $address);

// Tell the municipal desk that a new request is waiting.
foreach (db_all('SELECT id FROM users WHERE role = "admin"') as $a) {
    notify((int) $a['id'], 'New waste report #' . $newId,
        waste_meta($wasteType)['label'] . ' reported at ' . $address,
        'request', '/admin/requests.php?id=' . $newId);
}

json_success('Request submitted. Our team has been notified.', [
    'id'          => $newId,
    'request_code'=> $code,
    'status'      => 'pending',
    'priority'    => [
        'score'     => $priority['score'],
        'level'     => $priority['level'],
        'breakdown' => $priority['breakdown'],
        'reasons'   => $priority['reasons'],
    ],
    'redirect'    => '/citizen/track.php?id=' . $newId,
]);
