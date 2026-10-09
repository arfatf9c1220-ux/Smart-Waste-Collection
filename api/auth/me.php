<?php
/**
 * api/auth/me.php          GET
 * Returns the session user (used to check a session is still alive).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

if (!is_logged_in()) {
    json_error('Not logged in.', 401);
}

$user = current_user();
json_success('Authenticated', [
    'user' => [
        'id'    => (int) $user['id'],
        'name'  => $user['full_name'],
        'email' => $user['email'],
        'phone' => $user['phone'],
        'role'  => $user['role'],
    ],
    'redirect' => dashboard_for_role($user['role']),
    // The SPA sends this back as X-CSRF-Token on every state-changing call.
    'csrf_token' => csrf_token(),
]);
