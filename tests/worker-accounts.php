<?php
declare(strict_types=1);
/**
 * tests/worker-accounts.php
 *
 * Verifies that EVERY worker has an individual, working login account and that
 * an admin can create / deactivate / activate / reset those accounts.
 *
 * Part 1 (no server): database-level invariants + idempotent provisioning.
 * Part 2 (server):    admin drives the worker-accounts API; a worker whose
 *                     password was reset can sign in with the new password.
 *                     The original password hash is restored afterwards so the
 *                     demo data is left exactly as it was found.
 *
 * Usage: php tests/worker-accounts.php [base-url]
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/worker_accounts.php';

$BASE = rtrim($argv[1] ?? 'http://localhost/waste-php', '/');

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $extra = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; printf("  [OK]   %-56s %s\n", $label, $extra); }
    else     { $fail++; printf("  [FAIL] %-56s %s\n", $label, $extra); }
}

echo "Worker login accounts\n" . str_repeat('=', 74) . "\n";

// ======================================================= part 1: database ===
echo "\n-- every worker has a login (database) --\n";

$first  = provision_worker_accounts(null, false);
$second = provision_worker_accounts(null, false);

check('provisioning is idempotent (2nd run creates nothing)', $second['counts']['created'] === 0,
      'created=' . $second['counts']['created']);
check('no eligible worker is left without a login', $second['counts']['no_account'] === 0,
      'no_account=' . $second['counts']['no_account']);
check('no worker needs manual attention', $second['counts']['needs_attention'] === 0,
      'needs_attention=' . $second['counts']['needs_attention']);

$workers = db_all('SELECT id, user_id, worker_code FROM workers WHERE status <> "removed" ORDER BY id');
check('there is at least one worker to check', count($workers) > 0, count($workers) . ' worker(s)');

$emails   = [];
$unlinked = 0;
foreach ($workers as $w) {
    $u = db_row('SELECT id, email, role, status FROM users WHERE id = ?', [(int) $w['user_id']]);
    if (!$u || $u['role'] !== 'worker') { $unlinked++; continue; }
    $emails[] = strtolower($u['email']);
}
check('every worker is linked to a worker-role user', $unlinked === 0, "$unlinked unlinked");
check('worker login emails are unique', count($emails) === count(array_unique($emails)),
      count($emails) . ' accounts');

$states = array_map(fn($w) => worker_account_state($w)['key'], $workers);
check('no worker is in the "no_account" state', !in_array('no_account', $states, true),
      implode(',', array_count_values($states)));

// ==================================================== part 2: HTTP / API ===
echo "\n-- admin manages worker accounts (live API) --\n";

$jar = tempnam(sys_get_temp_dir(), 'swjar');
register_shutdown_function(function () use (&$jar) { if ($jar && file_exists($jar)) @unlink($jar); });

function req(string $method, string $path, array $body = [], bool $json = true): array
{
    global $BASE, $jar;
    $url = $BASE . $path;
    $ch  = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => false,
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POSTFIELDS] = $json ? json_encode($body) : $body;
        $opts[CURLOPT_HTTPHEADER] = $json ? ['Content-Type: application/json'] : [];
        $opts[CURLOPT_HTTPHEADER][] = 'X-CSRF-Token: ' . csrf();
    } else {
        $opts[CURLOPT_HTTPHEADER] = ['Accept: application/json'];
    }
    curl_setopt_array($ch, $opts);
    $raw  = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'json' => json_decode($raw, true), 'raw' => $raw];
}

function csrf(bool $refresh = false): string
{
    static $token = null;
    if ($token !== null && !$refresh) return $token;
    $me = req('GET', '/api/auth/me.php');
    $token = $me['json']['data']['csrf_token'] ?? '';
    return $token;
}

function freshSession(): void
{
    global $jar;
    if (file_exists($jar)) unlink($jar);
    csrf(true);
}

/** Log in and refresh the CSRF token for the now-authenticated session. */
function loginAs(string $email, string $password): array
{
    $r = req('POST', '/api/auth/login.php', ['email' => $email, 'password' => $password]);
    csrf(true);
    return $r;
}

// --- admin session ---------------------------------------------------------
freshSession();
$r = loginAs('admin@smartwaste.gov', 'admin@123');
check('admin logs in', ($r['code'] === 200 && ($r['json']['success'] ?? false)),
      $r['json']['message'] ?? ('HTTP ' . $r['code']));

$r = req('POST', '/api/admin/worker-accounts.php', ['action' => 'provision']);
check('admin provisions all worker accounts', ($r['code'] === 200 && ($r['json']['success'] ?? false)),
      $r['json']['message'] ?? ('HTTP ' . $r['code']));
check('provision reports no worker needs attention',
      (int) (($r['json']['data']['counts']['needs_attention'] ?? -1)) === 0,
      'needs_attention=' . ($r['json']['data']['counts']['needs_attention'] ?? '?'));

// --- pick a worker ---------------------------------------------------------
$target = $workers[0];
$targetUser = db_row('SELECT * FROM users WHERE id = ?', [(int) $target['user_id']]);
$origHash = (string) $targetUser['password_hash'];
$wid      = (int) $target['id'];

check('pick a worker to exercise', $wid > 0, 'worker #' . $wid . ' / ' . $targetUser['email']);

// --- deactivate then activate ---------------------------------------------
$r = req('POST', '/api/admin/worker-accounts.php', ['action' => 'deactivate', 'worker_id' => $wid]);
check('admin deactivates the worker login', ($r['code'] === 200 && ($r['json']['success'] ?? false)),
      $r['json']['message'] ?? ('HTTP ' . $r['code']));

freshSession();
$r = req('POST', '/api/auth/login.php', ['email' => $targetUser['email'], 'password' => 'worker@123']);
check('a deactivated account cannot sign in', !($r['json']['success'] ?? false),
      'HTTP ' . $r['code']);

freshSession();
loginAs('admin@smartwaste.gov', 'admin@123');
$r = req('POST', '/api/admin/worker-accounts.php', ['action' => 'activate', 'worker_id' => $wid]);
check('admin re-activates the worker login', ($r['code'] === 200 && ($r['json']['success'] ?? false)),
      $r['json']['message'] ?? ('HTTP ' . $r['code']));

// --- reset link -> worker sets their own password --------------------------
$r = req('POST', '/api/admin/worker-accounts.php', ['action' => 'reset', 'worker_id' => $wid]);
check('admin issues a one-time reset link', ($r['code'] === 200 && ($r['json']['success'] ?? false)),
      $r['json']['message'] ?? ('HTTP ' . $r['code']));
$setupUrl = (string) ($r['json']['data']['setup_url'] ?? '');
check('reset link points at set-password.php', strpos($setupUrl, 'set-password.php?token=') !== false);

parse_str((string) parse_url($setupUrl, PHP_URL_QUERY), $q);
$token = (string) ($q['token'] ?? '');
check('reset link carries a token', $token !== '' && strlen($token) >= 32, 'len=' . strlen($token));

$newPassword = 'FieldCrew!2026';
$r = req('POST', '/set-password.php', ['token' => $token, 'password' => $newPassword, 'confirm' => $newPassword], false);
check('set-password.php accepts the token', $r['code'] === 200 && strpos($r['raw'], 'Password set') !== false,
      'HTTP ' . $r['code']);

$after = db_row('SELECT password_hash, password_reset_token FROM users WHERE id = ?', [(int) $targetUser['id']]);
check('new password hash stored and token cleared',
      password_verify($newPassword, (string) $after['password_hash']) && $after['password_reset_token'] === null);

freshSession();
$r = req('POST', '/api/auth/login.php', ['email' => $targetUser['email'], 'password' => $newPassword]);
check('worker signs in with the password they chose', ($r['code'] === 200 && ($r['json']['success'] ?? false)),
      $r['json']['message'] ?? ('HTTP ' . $r['code']));

// --- restore demo state ----------------------------------------------------
db_query('UPDATE users SET password_hash = ?, password_reset_token = NULL, password_reset_expires = NULL, status = "active" WHERE id = ?',
    [$origHash, (int) $targetUser['id']]);
$restored = db_row('SELECT password_hash, status FROM users WHERE id = ?', [(int) $targetUser['id']]);
check('original password restored for the demo data',
      password_verify('worker@123', (string) $restored['password_hash']) && $restored['status'] === 'active');

echo "\n" . str_repeat('-', 74) . "\n";
printf("  %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
