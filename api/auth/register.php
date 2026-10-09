<?php
/**
 * api/auth/register.php     POST
 * Body (JSON or form): { full_name, email, phone, password, password_confirm,
 *                        address, locality, latitude, longitude }
 * Creates a users row (role = citizen) + the matching citizens row.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

if (request_method() !== 'POST') {
    header('Allow: POST');
    json_error('Method not allowed. Use POST.', 405);
}

$p        = json_input();
$name     = clean_str($p['full_name'] ?? '', 120);
$email    = clean_str($p['email'] ?? '', 150);
$phone    = clean_str($p['phone'] ?? '', 20);
$password = (string) ($p['password'] ?? '');
$confirm  = (string) ($p['password_confirm'] ?? '');
$address  = clean_str($p['address'] ?? '', 255);
$locality = clean_str($p['locality'] ?? '', 120);
$lat      = isset($p['latitude']) && $p['latitude'] !== '' && $p['latitude'] !== null ? (float) $p['latitude'] : null;
$lng      = isset($p['longitude']) && $p['longitude'] !== '' && $p['longitude'] !== null ? (float) $p['longitude'] : null;

$errors = [];
if (mb_strlen($name) < 3)                                    $errors[] = 'Full name must be at least 3 characters.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL))             $errors[] = 'Please enter a valid email address.';
if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) $errors[] = 'Please enter a valid phone number.';
if (mb_strlen($password) < 6)                                $errors[] = 'Password must be at least 6 characters.';
if ($password !== $confirm)                                  $errors[] = 'The two passwords do not match.';
if ($address === '')                                         $errors[] = 'Please enter your address.';
if ($lat !== null && $lng !== null && !valid_coords($lat, $lng)) $errors[] = 'Invalid map coordinates.';

if ($errors) {
    json_error(implode(' ', $errors), 422);
}

// Email must be unique (the UNIQUE index would throw otherwise).
if (db_value('SELECT id FROM users WHERE email = ?', [$email])) {
    json_error('An account with that email already exists. Please log in.', 409);
}

try {
    db()->beginTransaction();

    // --- users row ---------------------------------------------------------
    $userId = db_insert(
        'INSERT INTO users (full_name, email, phone, password_hash, role, status)
         VALUES (?, ?, ?, ?, "citizen", "active")',
        [
            $name,
            $email,
            $phone ?: null,
            // Plain text is NEVER stored. password_hash() returns a bcrypt hash.
            password_hash($password, PASSWORD_DEFAULT),
        ]
    );

    // --- citizens profile row ---------------------------------------------
    $nextId = (int) db_value('SELECT COALESCE(MAX(id),0) FROM citizens', [], 0) + 1;
    db_query(
        'INSERT INTO citizens (user_id, citizen_code, address, city, locality, latitude, longitude)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$userId, 'CIT-' . (1000 + $nextId), $address, APP_CITY, $locality ?: null, $lat, $lng]
    );

    db()->commit();
} catch (Throwable $e) {
    db()->rollBack();
    api_handle_exception($e);
}

log_activity('register', 'user', $userId, $name . ' created a citizen account');

// Log the new citizen straight in.
$_SESSION['user_id'] = $userId;
$_SESSION['role']     = 'citizen';
$_SESSION['name']     = $name;
$_SESSION['email']    = $email;
session_regenerate_id(true);

json_success('Account created. Welcome to ' . APP_SHORT . '!', [
    'redirect' => dashboard_for_role('citizen'),
    'citizen_code' => 'CIT-' . (1000 + $nextId),
    'csrf_token' => csrf_token(),
]);
