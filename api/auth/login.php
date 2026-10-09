<?php
/**
 * api/auth/login.php        POST
 * Body (JSON or form): { email, password }
 * Authenticates a user and starts a PHP session.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

if (request_method() !== 'POST') {
    header('Allow: POST');
    json_error('Method not allowed. Use POST.', 405);
}

$payload  = json_input();
$email    = clean_str($payload['email'] ?? '', 150);
$password = (string) ($payload['password'] ?? '');

if ($email === '' || $password === '') {
    json_error('Email and password are required.', 422);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_error('Please enter a valid email address.', 422);
}

if (!attempt_login($email, $password)) {
    // Deliberately vague message: never reveal whether the email exists.
    json_error('Invalid email or password.', 401);
}

$user  = current_user();
$extra = [];
if ($user['role'] === 'worker') {
    $extra['worker'] = current_worker();
}

json_success('Welcome back, ' . $user['full_name'] . '!', [
    'user' => [
        'id'         => (int) $user['id'],
        'name'       => $user['full_name'],
        'email'      => $user['email'],
        'phone'      => $user['phone'],
        'role'       => $user['role'],
    ],
    'redirect' => dashboard_for_role($user['role']),
    // The client stores this and sends it back as X-CSRF-Token.
    'csrf_token' => csrf_token(),
] + $extra);
