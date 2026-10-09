<?php
/**
 * includes/worker_accounts.php
 * ------------------------------------------------------------------
 * WORKER LOGIN-ACCOUNT LIFECYCLE
 *
 * Everything here is database driven: it never hardcodes a worker id, a name
 * or a password. A worker row is linked 1:1 to a `users` row (workers.user_id)
 * and this module is the single place that:
 *
 *   - reports the account state of any worker (active / inactive /
 *     awaiting setup / no account),
 *   - provisions a login account for a worker that is missing one,
 *   - issues one-time setup / password-reset links (stored only as a sha256
 *     hash, never in plain text),
 *   - lets a worker set their own password with password_hash().
 *
 * Passwords are never generated for the worker to remember: a fresh account
 * gets an unusable random hash and the worker sets their own password through
 * the one-time link.
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

/** How long a one-time setup / reset link stays valid. */
const WORKER_SETUP_TTL_HOURS = 72;

/** Minimum length for a worker-chosen password. */
const WORKER_PASSWORD_MIN = 8;

/**
 * Describe a worker's login-account state.
 *
 * @param array $worker A workers row (only `id` and `user_id` are required).
 * @return array{key:string,label:string,color:string,email:?string,reason:?string}
 */
function worker_account_state(array $worker): array
{
    $uid = (int) ($worker['user_id'] ?? 0);
    $u = $uid
        ? db_row('SELECT id, email, role, status, password_reset_token, password_reset_expires
                    FROM users WHERE id = ?', [$uid])
        : null;

    if (!$u || $u['role'] !== 'worker') {
        return [
            'key' => 'no_account', 'label' => 'No login', 'color' => 'danger',
            'email' => $u['email'] ?? null,
            'reason' => 'No worker login account is linked to this crew member.',
        ];
    }

    if ($u['status'] !== 'active') {
        return [
            'key' => 'inactive', 'label' => 'Inactive', 'color' => 'secondary',
            'email' => $u['email'],
            'reason' => 'The login account is deactivated.',
        ];
    }

    if (!empty($u['password_reset_token'])
        && ($u['password_reset_expires'] === null || strtotime((string) $u['password_reset_expires']) > time())) {
        return [
            'key' => 'awaiting_setup', 'label' => 'Awaiting setup', 'color' => 'warning',
            'email' => $u['email'],
            'reason' => 'A one-time setup link is pending — the worker has not set a password yet.',
        ];
    }

    return [
        'key' => 'active', 'label' => 'Active', 'color' => 'success',
        'email' => $u['email'], 'reason' => null,
    ];
}

/**
 * Every non-removed worker with its account state, for the admin screen.
 *
 * @return array<int,array>
 */
function workers_with_account_state(): array
{
    $rows = db_all(
        'SELECT w.*, u.full_name, u.email, u.phone AS user_phone, u.status AS user_status,
                t.team_name, t.waste_types AS team_waste_types,
                v.vehicle_number, v.vehicle_type, v.capacity_kg,
                (SELECT COUNT(*) FROM waste_requests r
                  WHERE r.assigned_worker_id = w.id
                    AND r.status IN ("assigned","scheduled","in_progress")) AS open_load,
                (SELECT COUNT(*) FROM waste_requests r
                  WHERE r.assigned_worker_id = w.id
                    AND r.status IN ("collected","verified")) AS done_count,
                (SELECT AVG(citizen_rating) FROM waste_requests r
                  WHERE r.assigned_worker_id = w.id AND r.citizen_rating IS NOT NULL) AS avg_rating
           FROM workers w
           LEFT JOIN users u      ON u.id = w.user_id
           LEFT JOIN teams t      ON t.id = w.team_id
           LEFT JOIN vehicles v   ON v.id = w.vehicle_id
          WHERE w.status <> "removed"
          ORDER BY FIELD(w.status,"available","busy","offline"), w.current_load, u.full_name'
    );

    foreach ($rows as &$w) {
        $w['account'] = worker_account_state($w);
    }
    unset($w);

    return $rows;
}

/** Suggest a stable, unique-looking login email for a worker with no account. */
function suggest_worker_email(string $workerCode): string
{
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $workerCode) ?? 'worker');
    $slug = trim($slug, '-') ?: 'worker';
    return $slug . '@smartwaste.gov';
}

/** Return $email, or the first free "email-2", "email-3", ... variant. */
function unique_worker_email(string $email): string
{
    if (!db_value('SELECT id FROM users WHERE email = ?', [$email])) {
        return $email;
    }
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, 'smartwaste.gov');
    for ($i = 2; $i < 500; $i++) {
        $candidate = $local . '-' . $i . '@' . $domain;
        if (!db_value('SELECT id FROM users WHERE email = ?', [$candidate])) {
            return $candidate;
        }
    }
    // Extremely unlikely; fall back to a random suffix.
    return $local . '-' . bin2hex(random_bytes(4)) . '@' . $domain;
}

/**
 * Ensure a worker has a linked, active worker login account.
 *
 * For an existing account nothing is changed. For a missing/mismatched link a
 * new `users` row is created with an UNUSABLE random password (the worker sets
 * their own via a setup link). Optionally issues a one-time setup token.
 *
 * @return array{worker_id:int,user_id:int,email:string,created:bool,token:?string}
 */
function ensure_worker_account(int $workerId, ?string $email = null, ?int $createdBy = null, bool $issueSetupLink = true): array
{
    $worker = db_row('SELECT * FROM workers WHERE id = ?', [$workerId]);
    if (!$worker) {
        throw new RuntimeException("Worker #$workerId not found.");
    }

    $user = $worker['user_id']
        ? db_row('SELECT * FROM users WHERE id = ?', [(int) $worker['user_id']])
        : null;

    $created = false;

    if (!$user || $user['role'] !== 'worker') {
        $email = ($email !== null && $email !== '') ? strtolower(trim($email)) : suggest_worker_email((string) $worker['worker_code']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid email address is required to create the login.');
        }
        $email = unique_worker_email($email);

        // An unusable random password: nobody can sign in with this until the
        // worker sets their own password through the one-time link.
        $placeholder = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

        try {
            db()->beginTransaction();

            $newId = db_insert(
                'INSERT INTO users (full_name, email, phone, password_hash, role, status, created_at)
                 VALUES (?, ?, ?, ?, "worker", "active", NOW())',
                [
                    'Worker ' . $worker['worker_code'],
                    $email,
                    $worker['phone'] ?: null,
                    $placeholder,
                ]
            );
            db_query('UPDATE workers SET user_id = ? WHERE id = ?', [$newId, $workerId]);
            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            throw $e;
        }

        $user = db_row('SELECT * FROM users WHERE id = ?', [$newId]);
        $created = true;

        log_activity('worker_account_created', 'user', (int) $user['id'],
            'Login account created for worker #' . $workerId . ' (' . $worker['worker_code'] . ')');
    }

    $token = null;
    if ($created && $issueSetupLink) {
        $token = generate_setup_token((int) $user['id'], WORKER_SETUP_TTL_HOURS);
    }

    return [
        'worker_id' => $workerId,
        'user_id'   => (int) $user['id'],
        'email'     => $user['email'],
        'created'   => $created,
        'token'     => $token,
    ];
}

/**
 * Create a one-time setup/reset token for a user. Returns the PLAIN token
 * exactly once; only its sha256 hash is stored.
 */
function generate_setup_token(int $userId, int $ttlHours = WORKER_SETUP_TTL_HOURS): string
{
    $token = bin2hex(random_bytes(32));
    db_query(
        'UPDATE users SET password_reset_token = ?, password_reset_expires = ? WHERE id = ?',
        [hash('sha256', $token), date('Y-m-d H:i:s', time() + $ttlHours * 3600), $userId]
    );
    return $token;
}

/** Resolve a still-valid setup token to the user + worker it belongs to. */
function user_for_setup_token(string $token): ?array
{
    $token = trim($token);
    if ($token === '') {
        return null;
    }
    $u = db_row(
        'SELECT id, full_name, email, role, status, password_reset_expires
           FROM users WHERE password_reset_token = ? LIMIT 1',
        [hash('sha256', $token)]
    );
    if (!$u) {
        return null;
    }
    if ($u['password_reset_expires'] !== null && strtotime((string) $u['password_reset_expires']) < time()) {
        return null;
    }
    $u['worker'] = db_row('SELECT id, worker_code FROM workers WHERE user_id = ? LIMIT 1', [(int) $u['id']]);
    return $u;
}

/**
 * Consume a setup token and set the worker's own password.
 *
 * @return array{ok:bool,message:string,user_id?:int}
 */
function consume_setup_token(string $token, string $password): array
{
    $token = trim($token);
    if ($token === '' || strlen($token) < 32) {
        return ['ok' => false, 'message' => 'This setup link is invalid.'];
    }
    if (strlen($password) < WORKER_PASSWORD_MIN) {
        return ['ok' => false, 'message' => 'Password must be at least ' . WORKER_PASSWORD_MIN . ' characters.'];
    }

    $u = db_row('SELECT id, password_reset_expires FROM users WHERE password_reset_token = ? LIMIT 1',
        [hash('sha256', $token)]);
    if (!$u) {
        return ['ok' => false, 'message' => 'This setup link is invalid or has already been used.'];
    }
    if ($u['password_reset_expires'] !== null && strtotime((string) $u['password_reset_expires']) < time()) {
        return ['ok' => false, 'message' => 'This setup link has expired. Ask your officer for a new one.'];
    }

    db_query(
        'UPDATE users
            SET password_hash = ?, password_reset_token = NULL, password_reset_expires = NULL, status = "active"
          WHERE id = ?',
        [password_hash($password, PASSWORD_DEFAULT), (int) $u['id']]
    );

    log_activity('worker_password_set', 'user', (int) $u['id'],
        'Worker set their own password through a one-time setup link');

    return ['ok' => true, 'user_id' => (int) $u['id'], 'message' => 'Your password is set. You can sign in now.'];
}

/** Build the absolute one-time setup URL for a token. */
function worker_setup_url(string $token): string
{
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    return ($secure ? 'https' : 'http') . '://' . $host . APP_URL . '/set-password.php?token=' . urlencode($token);
}

/** Activate or deactivate a worker's login account. Records history, deletes nothing. */
function set_worker_account_active(int $workerId, bool $active, ?int $createdBy = null): array
{
    $user = db_row(
        'SELECT u.* FROM workers w JOIN users u ON u.id = w.user_id WHERE w.id = ?',
        [$workerId]
    );
    if (!$user) {
        throw new RuntimeException('This worker has no linked login account. Create one first.');
    }

    $status = $active ? 'active' : 'inactive';
    db_query('UPDATE users SET status = ? WHERE id = ?', [$status, (int) $user['id']]);

    log_activity($active ? 'worker_account_activated' : 'worker_account_deactivated', 'user', (int) $user['id'],
        'Login account for worker #' . $workerId . ' ' . ($active ? 'activated' : 'deactivated') . ' by admin');

    return ['worker_id' => $workerId, 'user_id' => (int) $user['id'], 'status' => $status];
}

/** Issue a fresh one-time setup/reset link for an existing account. */
function regenerate_worker_setup_link(int $workerId, ?int $createdBy = null): array
{
    $user = db_row(
        'SELECT u.* FROM workers w JOIN users u ON u.id = w.user_id WHERE w.id = ?',
        [$workerId]
    );
    if (!$user) {
        throw new RuntimeException('This worker has no linked login account. Create one first.');
    }

    $token = generate_setup_token((int) $user['id'], WORKER_SETUP_TTL_HOURS);

    log_activity('worker_reset_link', 'user', (int) $user['id'],
        'One-time password setup link issued for worker #' . $workerId . ' by admin');

    return [
        'worker_id'     => $workerId,
        'user_id'       => (int) $user['id'],
        'email'         => $user['email'],
        'token'         => $token,
        'expires_hours' => WORKER_SETUP_TTL_HOURS,
    ];
}

/**
 * Provision accounts for EVERY eligible worker (dynamic — no fixed ids).
 * Creates only what is missing and never touches existing credentials.
 * Emits a one-time setup token for each newly created account.
 *
 * @return array{counts:array,items:array}
 */
function provision_worker_accounts(?int $createdBy = null, bool $issueLinks = true): array
{
    $workers = db_all('SELECT id, user_id, worker_code FROM workers WHERE status <> "removed" ORDER BY id');

    $counts = [
        'eligible'        => 0,
        'active'          => 0,
        'awaiting_setup'  => 0,
        'inactive'        => 0,
        'no_account'      => 0,   // always 0 after a successful run (all created/attempted)
        'created'         => 0,
        'needs_attention' => 0,
    ];
    $items = [];

    foreach ($workers as $w) {
        $counts['eligible']++;
        $state = worker_account_state($w);

        if ($state['key'] === 'no_account') {
            try {
                $res = ensure_worker_account((int) $w['id'], null, $createdBy, $issueLinks);
                $counts['created']++;
                $items[] = [
                    'worker_id'   => (int) $w['id'],
                    'worker_code' => $w['worker_code'],
                    'state'       => 'created',
                    'email'       => $res['email'],
                    'token'       => $res['token'],
                ];
            } catch (Throwable $e) {
                $counts['needs_attention']++;
                $items[] = [
                    'worker_id'   => (int) $w['id'],
                    'worker_code' => $w['worker_code'],
                    'state'       => 'needs_attention',
                    'email'       => null,
                    'reason'      => $e->getMessage(),
                ];
            }
            continue;
        }

        $counts[$state['key'] === 'awaiting_setup' ? 'awaiting_setup' : $state['key']]++;
        $items[] = [
            'worker_id'   => (int) $w['id'],
            'worker_code' => $w['worker_code'],
            'state'       => $state['key'],
            'email'       => $state['email'],
            'reason'      => $state['reason'],
        ];
    }

    return ['counts' => $counts, 'items' => $items];
}
