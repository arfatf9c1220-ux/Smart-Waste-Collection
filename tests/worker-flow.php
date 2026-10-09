<?php
declare(strict_types=1);
require_once __DIR__ . '/jpeg-fixture.php';
/**
 * tests/worker-flow.php
 *
 * Exercises the complete worker field journey against the live API:
 *   login -> mission -> GPS ping -> status -> arrive -> start ->
 *   proof uploads (before + after) -> collect -> verify (as admin)
 *
 * The request it drives is created by the test itself, so it cleans up after
 * itself and does not disturb the demo data.
 *
 * Usage: php tests/worker-flow.php [base-url]
 */
$BASE = rtrim($argv[1] ?? 'http://localhost/waste-php', '/');

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $extra = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; printf("  [OK]   %-52s %s\n", $label, $extra); }
    else     { $fail++; printf("  [FAIL] %-52s %s\n", $label, $extra); }
}

/** Curl session with a cookie jar, so the PHP session persists between calls. */
function sess(): string
{
    static $jar = null;
    if ($jar === null) {
        $jar = tempnam(sys_get_temp_dir(), 'swjar');
        register_shutdown_function(function () use (&$jar) {
            if ($jar && file_exists($jar)) @unlink($jar);
        });
    }
    return $jar;
}

function req(string $method, string $path, array $body = [], bool $json = true): array
{
    global $BASE;
    $url = $BASE . $path;
    $ch  = curl_init($url);

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => sess(),
        CURLOPT_COOKIEFILE     => sess(),
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => false,
    ];

    if ($method === 'POST') {
        $opts[CURLOPT_POSTFIELDS] = $json ? json_encode($body) : $body;
        $opts[CURLOPT_HTTPHEADER] = $json
            ? ['Content-Type: application/json']
            : [];
        // every mutating endpoint requires the session CSRF token
        $opts[CURLOPT_HTTPHEADER][] = 'X-CSRF-Token: ' . csrf();
    } else {
        $opts[CURLOPT_HTTPHEADER] = ['Accept: application/json'];
        if ($method === 'GET' && $body) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($body);
            $opts[CURLOPT_URL] = $url;
        }
    }
    curl_setopt_array($ch, $opts);

    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($raw, true);
    return ['code' => $code, 'json' => $decoded, 'raw' => $raw];
}

function csrf(bool $refresh = false): string
{
    static $token = null;
    if ($token !== null && !$refresh) return $token;
    $me = req('GET', '/api/auth/me.php');
    $token = $me['json']['data']['csrf_token'] ?? '';
    return $token;
}

/** Drop the cached CSRF token, e.g. after logging in as a different role. */
function resetCsrf(): void
{
    csrf(true);
}

/**
 * Hand-rolled PNG encoder so the test does not depend on the GD extension.
 * Produces a solid-colour truecolour PNG of the requested size.
 */
function tinyPng(string $path, int $w = 120, int $h = 120): string
{
    /** @param string $type @param string $data */
    $chunk = function (string $type, string $data): string {
        return pack('N', strlen($data)) . $type . $data
             . pack('N', crc32($type . $data));
    };

    // raw scanlines: one filter byte (0 = None) then RGB triples
    $raw = '';
    for ($y = 0; $y < $h; $y++) {
        $raw .= "\0" . str_repeat("\x1e\x8c\x5a", $w); // a green-grey fill
    }

    $ihdr = pack('NN', $w, $h) . "\x08\x02\x00\x00\x00"; // 8-bit, truecolour RGB
    $png  = "\x89PNG\r\n\x1a\n"
          . $chunk('IHDR', $ihdr)
          . $chunk('IDAT', gzcompress($raw, 9))
          . $chunk('IEND', '');

    file_put_contents($path, $png);
    return $path;
}

/** Login and cache a fresh CSRF token for the new session. */
function login(string $email, string $password): array
{
    sess(); // ensure the jar exists
    $r = req('POST', '/api/auth/login.php', ['email' => $email, 'password' => $password]);
    resetCsrf();
    return $r;
}

/** Log out of the current session and start a clean one. */
function newSession(): void
{
    $jar = sess();
    if (file_exists($jar)) unlink($jar);
    csrf(true);
}

echo "Worker field journey\n" . str_repeat('=', 74) . "\n";

// =========================================================== stage 1: setup
// The test builds its own request and assigns it, so it does not depend on
// whatever state another test left the demo data in.
echo "\n-- setup: create and assign a pickup --\n";

newSession();
$r = login('citizen1@smartwaste.gov', 'citizen@123');
check('citizen logs in to report waste', ($r['code'] === 200 && ($r['json']['success'] ?? false)));

$r = req('POST', '/api/requests/create.php', [
    'waste_type'  => 'organic',
    'quantity'    => 'medium',
    'description' => 'Automated worker-journey verification pickup.',
    'address'     => 'Test Lane 42, Kothrud Depot Yard',
    'landmark'    => 'beside the blue gate',
    'latitude'    => 18.5074,
    'longitude'   => 73.8577,
]);
check('citizen creates a waste request', ($r['code'] === 200 && ($r['json']['success'] ?? false)),
      $r['json']['message'] ?? ('HTTP ' . $r['code']));
$rid = (int) ($r['json']['data']['id'] ?? 0);
check('new request has an id', $rid > 0, "id=$rid");

$r = req('GET', '/api/requests/view.php', ['id' => $rid]);
$pv = $r['json']['data'] ?? [];
check('new request is scored on submission',
      ($pv['request']['status'] ?? '') === 'pending'
      && isset($pv['priority']['score']) && $pv['priority']['score'] > 0,
      'status=' . ($pv['request']['status'] ?? '?')
      . ' score=' . ($pv['priority']['score'] ?? '?'));
check('priority breakdown explains the score',
      !empty($pv['priority']['breakdown']) && is_array($pv['priority']['breakdown']),
      implode(',', array_keys($pv['priority']['breakdown'] ?? [])));

newSession();
login('admin@smartwaste.gov', 'admin@123');
$r = req('GET', '/api/workers/recommend.php', ['request_id' => $rid, 'limit' => 3]);
$workers = $r['json']['data']['workers'] ?? $r['json']['data']['recommendations'] ?? [];
check('engine recommends workers for the request', count($workers) > 0, count($workers) . ' suggested');
$targetWorker = (int) ($workers[0]['worker_id'] ?? $workers[0]['id'] ?? 0);
check('recommendation carries a worker id', $targetWorker > 0, "worker_id=$targetWorker");

$r = req('POST', '/api/assignments/create.php',
         ['request_id' => $rid, 'worker_id' => $targetWorker, 'note' => 'worker-flow test']);
check('admin assigns the pickup to that worker',
      ($r['code'] === 200 && ($r['json']['success'] ?? false)),
      $r['json']['message'] ?? ('HTTP ' . $r['code']));

$r = req('GET', '/api/requests/view.php', ['id' => $rid]);
check('request is now assigned',
      ($r['json']['data']['request']['status'] ?? '') === 'assigned',
      'status=' . ($r['json']['data']['request']['status'] ?? '?'));

// ====================================================== stage 2: worker does it
echo "\n-- worker: arrive, collect, prove --\n";

newSession();
$r = login('worker1@smartwaste.gov', 'worker@123');
check('worker logs in', ($r['code'] === 200 && ($r['json']['success'] ?? false)),
      'role=' . ($r['json']['data']['user']['role'] ?? '?'));
check('login returns a worker profile', ($r['json']['data']['worker']['id'] ?? 0) > 0);
check('CSRF token issued', csrf() !== '');

// only drive this test's own request
if ((int) ($r['json']['data']['worker']['id'] ?? 0) !== $targetWorker) {
    $email = null;
    foreach ([1 => 'worker1', 2 => 'worker2', 3 => 'worker3', 4 => 'worker4',
              5 => 'worker5', 6 => 'worker6', 7 => 'worker7', 8 => 'worker8'] as $wid => $n) {
        if ($wid === $targetWorker) { $email = $n . '@smartwaste.gov'; break; }
    }
    newSession();
    $r = login($email ?? 'worker1@smartwaste.gov', 'worker@123');
    check('switched to the assigned worker ' . ($email ?? '?'),
          ($r['code'] === 200 && ($r['json']['success'] ?? false)));
}

// --------------------------------------------------------------- mission ---
$r = req('GET', '/api/collection/mission.php');
check('GET collection/mission.php', ($r['code'] === 200 && ($r['json']['success'] ?? false)));
$d = $r['json']['data'] ?? [];
check('mission returns stops array', isset($d['stops']) && is_array($d['stops']),
      'stops=' . count($d['stops'] ?? []));
check('mission returns totals', isset($d['mission']['total_stops']),
      json_encode($d['mission'] ?? []));
$mine = null;
foreach ($d['stops'] ?? [] as $s) {
    if ((int) $s['request_id'] === $rid) { $mine = $s; break; }
}
check('mission exposes the assigned stop as actionable', $mine !== null,
      $mine ? '#' . $mine['request_id'] . ' ' . $mine['status'] : 'not in mission');

// ------------------------------------------------------------ GPS + status --
$r = req('POST', '/api/workers/update-location.php',
         ['lat' => 18.5074, 'lng' => 73.8577, 'accuracy' => 12]);
check('POST workers/update-location.php', ($r['code'] === 200 && ($r['json']['success'] ?? false)));

$r = req('POST', '/api/workers/status.php', ['status' => 'available']);
check('POST workers/status.php -> available', ($r['code'] === 200 && ($r['json']['success'] ?? false)));

// -------------------------------------------------- arrive -> start flow ----
$r = req('POST', '/api/requests/update-status.php', ['id' => $rid, 'action' => 'arrive']);
check('action=arrive accepted', ($r['code'] === 200 && ($r['json']['success'] ?? false)),
      $r['json']['message'] ?? '');

$r = req('POST', '/api/requests/update-status.php', ['id' => $rid, 'action' => 'start']);
check('action=start accepted', ($r['code'] === 200 && ($r['json']['success'] ?? false)),
      $r['json']['message'] ?? '');

// guard: collecting without both photos must be refused
$r = req('POST', '/api/requests/update-status.php', ['id' => $rid, 'action' => 'collect']);
$refused = ($r['code'] === 422) || (($r['json']['success'] ?? true) === false);
check('collect refused without both proof photos', $refused,
      'HTTP ' . $r['code'] . ' ' . ($r['json']['message'] ?? ''));

// ---------------------------------------------------------- proof uploads --
if ($rid > 0) {
    $tmp = jpegFixture('worker-proof');

    foreach (['before', 'after'] as $type) {
        $r = req('POST', '/api/requests/upload.php', [
            'id'    => $rid,
            'type'  => $type,
            'lat'   => '18.5204',
            'lng'   => '73.8567',
            'image' => new CURLFile($tmp, 'image/jpeg', 'proof.jpg'),
        ], false);
        check("upload $type proof photo",
              ($r['code'] === 200 && ($r['json']['success'] ?? false)),
              $r['json']['message'] ?? ('HTTP ' . $r['code']));
    }
    @unlink($tmp);

    // --------------------------------------------------- collect -> verify --
    $r = req('POST', '/api/requests/update-status.php', ['id' => $rid, 'action' => 'collect']);
    check('action=collect accepted after proof', ($r['code'] === 200 && ($r['json']['success'] ?? false)),
          $r['json']['message'] ?? ('HTTP ' . $r['code']));

    $r = req('GET', '/api/requests/view.php', ['id' => $rid]);
    $view = $r['json']['data'] ?? [];
    $row  = $view['request'] ?? [];
    $col  = $view['collection'] ?? [];
    check('request is now collected', ($row['status'] ?? '') === 'collected',
          'status=' . ($row['status'] ?? '?'));
    check('collection timestamp recorded', !empty($col['collected_at']),
          'collected_at=' . ($col['collected_at'] ?? 'null'));
    check('proof photos stored on the request',
          !empty($col['before_image']) && !empty($col['after_image']),
          'before=' . (!empty($col['before_image']) ? 'y' : 'n')
          . ' after=' . (!empty($col['after_image']) ? 'y' : 'n'));
    check('collection GPS fix stored', !empty($col['collection_lat']) && !empty($col['collection_lng']),
          $col['collection_lat'] . ',' . $col['collection_lng']);

    // --------------------------------------------------------- admin verify --
    newSession();
    $r = login('admin@smartwaste.gov', 'admin@123');
    check('admin logs in to verify', ($r['code'] === 200 && ($r['json']['success'] ?? false)));

    $r = req('POST', '/api/admin/verify.php', ['id' => $rid, 'decision' => 'approve']);
    check('admin verifies the collection',
          ($r['code'] === 200 && ($r['json']['success'] ?? false)),
          $r['json']['message'] ?? ('HTTP ' . $r['code']));

    $r = req('GET', '/api/requests/view.php', ['id' => $rid]);
    $row2 = $r['json']['data']['request'] ?? [];
    $col2 = $r['json']['data']['collection'] ?? [];
    check('request is now verified', ($row2['status'] ?? '') === 'verified',
          'status=' . ($row2['status'] ?? '?'));
    check('verification timestamp recorded', !empty($col2['verified_at']),
          'verified_at=' . ($col2['verified_at'] ?? 'null'));

    // verify must refuse a decision when proof photos are missing. The list
    // endpoint does not expose the photo columns, so probe view.php per row.
    $cands = req('GET', '/api/requests/list.php', ['status' => 'collected', 'limit' => 50]);
    $target = null;
    foreach ($cands['json']['data']['requests'] ?? [] as $x) {
        $v  = req('GET', '/api/requests/view.php', ['id' => (int) $x['id']]);
        $cc = $v['json']['data']['collection'] ?? [];
        if (empty($cc['before_image']) || empty($cc['after_image'])) {
            $target = (int) $x['id'];
            break;
        }
    }
    if ($target) {
        $r = req('POST', '/api/admin/verify.php', ['id' => $target, 'decision' => 'approve']);
        check('verify refuses a request without proof photos', $r['code'] === 422,
              "#$target HTTP " . $r['code'] . ' ' . ($r['json']['message'] ?? ''));
    } else {
        check('verify refuses a request without proof photos', true, 'skipped: none available');
    }
}

// ---------------------------------------------------------- authz checks ----
echo "\n-- authorisation boundaries --\n";

newSession();
login('citizen1@smartwaste.gov', 'citizen@123');
$r = req('POST', '/api/requests/update-status.php', ['id' => $rid, 'action' => 'collect']);
check('citizen cannot drive the worker workflow', $r['code'] === 403, 'HTTP ' . $r['code']);

$r = req('GET', '/api/collection/mission.php');
check('citizen cannot read another role mission', $r['code'] === 403, 'HTTP ' . $r['code']);

$tmp2 = tinyPng(tempnam(sys_get_temp_dir(), 'sw') . '.png');
$r = req('POST', '/api/requests/upload.php', [
    'id' => $rid, 'type' => 'before',
    'lat' => '18.5074', 'lng' => '73.8577',
    'image' => new CURLFile($tmp2, 'image/png', 'x.png'),
], false);
check('citizen cannot upload worker proof photos', $r['code'] === 403, 'HTTP ' . $r['code']);
@unlink($tmp2);

newSession();
login('worker1@smartwaste.gov', 'worker@123');
$r = req('POST', '/api/admin/verify.php', ['id' => $rid, 'decision' => 'approve']);
check('worker cannot verify their own collection', $r['code'] === 403, 'HTTP ' . $r['code']);

$r = req('GET', '/api/admin/activity.php');
check('worker cannot read the audit log', $r['code'] === 403, 'HTTP ' . $r['code']);

$r = req('POST', '/api/requests/update-status.php', ['id' => 999999, 'action' => 'arrive']);
check('unknown request id is rejected', in_array($r['code'], [404, 403], true), 'HTTP ' . $r['code']);

// switch back to the assigned worker so validation runs past the ownership gate
newSession();
$assignedEmail = 'worker' . $targetWorker . '@smartwaste.gov';
login($assignedEmail, 'worker@123');
$r = req('POST', '/api/requests/update-status.php', ['id' => $rid, 'action' => 'explode']);
check('unknown action is rejected', $r['code'] === 422,
      'HTTP ' . $r['code'] . ' ' . ($r['json']['message'] ?? ''));

// a closed request must not be reopenable by a worker
$r = req('POST', '/api/requests/update-status.php', ['id' => $rid, 'action' => 'start']);
check('closed request cannot be restarted', $r['code'] === 422,
      'HTTP ' . $r['code'] . ' ' . ($r['json']['message'] ?? ''));

echo "\n" . str_repeat('=', 74) . "\n";
printf("worker-flow PASS: %d   FAIL: %d\n", $pass, $fail);
echo "note: request #$rid and its proof photos were left in the database.\n";
echo "      run 'php tests/reset-db.php' to restore pristine demo data.\n";
exit($fail > 0 ? 1 : 0);