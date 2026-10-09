<?php
/**
 * includes/auth.php
 * ------------------------------------------------------------------
 * Session based authentication + role based authorisation.
 *
 *  - login_user()          : starts a session for a verified user
 *  - require_login()       : must be logged in
 *  - require_role([...])   : must be logged in as one of the roles
 *  - current_user()        : the users row for the session
 *  - api_require_*()       : JSON flavoured guards for the api/ folder
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

// ---------------------------------------------------------------------------
// SESSION BOOTSTRAP
// ---------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    // Harden the session cookie a little.
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** Route a role to its dashboard. */
function dashboard_for_role(string $role): string
{
    return [
        'citizen' => '/citizen/dashboard.php',
        'admin'   => '/admin/dashboard.php',
        'worker'  => '/worker/dashboard.php',
    ][$role] ?? '/login.php';
}

// ---------------------------------------------------------------------------
// CSRF PROTECTION
// A random token is stored in the session; every state changing request
// (POST form or JSON API call) must send it back.
// ---------------------------------------------------------------------------

/** Returns (and lazily creates) the CSRF token for this session. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** True when the submitted token matches the session token. */
function csrf_valid(?string $token): bool
{
    return !empty($_SESSION['csrf_token'])
        && is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}

/** Hidden input you can drop inside any HTML form. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** API flavour: stops with 403 JSON when the token is missing or wrong. */
function api_require_csrf(): void
{
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? (json_input()['csrf_token'] ?? null);
    if (!csrf_valid(is_string($token) ? $token : null)) {
        json_error('Invalid or missing CSRF token. Please reload the page.', 403);
    }
}

// ---------------------------------------------------------------------------
// AUTHENTICATION
// ---------------------------------------------------------------------------

/**
 * Verifies email + password and starts the session.
 * Passwords are stored as bcrypt hashes created with password_hash().
 */
function attempt_login(string $email, string $password): bool
{
    $user = db_row('SELECT * FROM users WHERE email = ? AND status = "active" LIMIT 1', [$email]);

    // password_verify() compares the plain input against the stored hash.
    // We call it even when the user is missing to keep timing similar.
    $hash = $user['password_hash'] ?? '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidin';
    $ok = password_verify($password, $hash) && $user !== null;

    if (!$ok) {
        return false;
    }

    // Transparently upgrade the hash if PHP's default algorithm improved.
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        db_query('UPDATE users SET password_hash = ? WHERE id = ?', [
            password_hash($password, PASSWORD_DEFAULT), $user['id'],
        ]);
    }

    session_regenerate_id(true);           // prevent session fixation
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['role']     = $user['role'];
    $_SESSION['name']     = $user['full_name'];
    $_SESSION['email']    = $user['email'];

    // Remember the browser "last seen" time for admin activity views.
    db_query('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$user['id']]);
    log_activity('login', 'user', (int) $user['id'], $user['full_name'] . ' signed in');

    // A worker session also refreshes the worker status to available.
    if ($user['role'] === 'worker') {
        db_query('UPDATE workers SET status = "available", availability = "available", last_seen_at = NOW() WHERE user_id = ?', [$user['id']]);
    }

    return true;
}

/** Log the current user out and destroy the session. */
function logout_user(): void
{
    if (current_user_id()) {
        log_activity('logout', 'user', current_user_id(), $_SESSION['name'] . ' signed out');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// ---------------------------------------------------------------------------
// CURRENT USER HELPERS
// ---------------------------------------------------------------------------

function current_user_id(): ?int
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

function current_role(): ?string
{
    return $_SESSION['role'] ?? null;
}

function is_logged_in(): bool
{
    return current_user_id() !== null;
}

/** Returns the full users row of the session (cached per request). */
function current_user(): ?array
{
    static $cache = null;
    static $cachedId = -1;

    $id = current_user_id();
    if ($id === null) {
        return null;
    }
    if ($cache !== null && $cachedId === $id) {
        return $cache;
    }

    $cache = db_row('SELECT * FROM users WHERE id = ? AND status = "active"', [$id]);
    $cachedId = $id;

    if ($cache === null) {
        // Session points at a deleted/disabled account -> force logout.
        logout_user();
        return null;
    }
    return $cache;
}

/** Row from the citizens table for the logged-in citizen. */
function current_citizen(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $id = current_user_id();
    if (!$id) {
        return null;
    }
    $cache = db_row('SELECT * FROM citizens WHERE user_id = ? LIMIT 1', [$id]);
    return $cache;
}

/** Row from the workers table for the logged-in worker. */
function current_worker(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $id = current_user_id();
    if (!$id) {
        return null;
    }
    $cache = db_row(
        'SELECT w.*, t.team_name, t.waste_types AS team_waste_types, v.vehicle_number, v.vehicle_type
           FROM workers w
           LEFT JOIN teams   t ON t.id = w.team_id
           LEFT JOIN vehicles v ON v.id = w.vehicle_id
          WHERE w.user_id = ? LIMIT 1',
        [$id]
    );
    return $cache;
}

// ---------------------------------------------------------------------------
// PAGE GUARDS (HTML pages)
// ---------------------------------------------------------------------------

function require_login(): void
{
    if (!is_logged_in()) {
        $_SESSION['flash'] = 'Please log in to continue.';
        redirect('/login.php');
    }
}

function require_role(string|array $roles): void
{
    require_login();
    $roles = (array) $roles;
    if (!in_array(current_role(), $roles, true)) {
        $_SESSION['flash'] = 'You do not have permission to open that page.';
        redirect(dashboard_for_role(current_role() ?? 'citizen'));
    }
}

// ---------------------------------------------------------------------------
// API GUARDS (JSON responses)
// ---------------------------------------------------------------------------

/** API guard: must be logged in, otherwise replies 401 JSON. */
function api_require_login(): int
{
    if (!is_logged_in()) {
        json_error('Authentication required. Please log in.', 401);
    }
    return current_user_id();
}

/** API guard: must be one of the given roles, otherwise replies 403 JSON. */
function api_require_role(string|array $roles): int
{
    $uid = api_require_login();
    $roles = (array) $roles;
    if (!in_array(current_role(), $roles, true)) {
        json_error('You are not allowed to perform this action.', 403);
    }
    return $uid;
}

/** Only accept POST / PUT / PATCH for state changing endpoints. */
function api_require_post(): void
{
    if (request_method() !== 'POST') {
        header('Allow: POST');
        json_error('Method not allowed. Use POST.', 405);
    }
}

/** Converts a Throwable into a JSON error response. */
function api_handle_exception(Throwable $e): void
{
    error_log('[API] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $msg = APP_DEBUG ? $e->getMessage() : 'Something went wrong. Please try again.';
    json_error($msg, 500);
}
