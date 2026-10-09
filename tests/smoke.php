<?php
/**
 * tests/smoke.php  —  end-to-end API smoke test
 *
 * Usage:  php tests/smoke.php [base-url]
 * Default base-url: http://localhost/waste-php
 *
 * Drives the real HTTP API with a cookie jar per role:
 *   admin  -> stats, map, activity, analytics, hotspots, suggest, plan build
 *   worker -> mission, location ping, arrive / before / after / collect
 *   citizen-> create request, track, list, cancel
 * Then verifies the collected request can be verified by the admin.
 *
 * Exit code 0 = everything passed.
 */

declare(strict_types=1);

require_once __DIR__ . '/jpeg-fixture.php';

$BASE = rtrim($argv[1] ?? 'http://localhost/waste-php', '/');
$PASS = 0;
$FAIL = 0;
$JARS = [];

function ok(string $label): void
{
    global $PASS;
    $PASS++;
    echo "  [OK]   $label\n";
}

function bad(string $label, string $why): void
{
    global $FAIL;
    $FAIL++;
    echo "  [FAIL] $label\n         -> $why\n";
}

function section(string $title): void
{
    echo "\n== $title ==\n";
}

/**
 * @return array{code:int, body:array, raw:string}
 */
function call(string $method, string $path, ?array $body = null, string $as = 'anon', bool $csrf = true): array
{
    global $BASE, $JARS;

    $url = $BASE . '/' . ltrim($path, '/');
    $ch  = curl_init($url);

    $headers = ['Accept: application/json'];
    if (isset($JARS[$as]) && $JARS[$as]) {
        $headers[] = 'Cookie: PHPSESSID=' . $JARS[$as];
    }
    if ($csrf && !empty($GLOBALS['CSRF'][$as])) {
        $headers[] = 'X-CSRF-Token: ' . $GLOBALS['CSRF'][$as];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    if ($method === 'POST' || $method === 'PUT' || $method === 'DELETE') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body ?? []));
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $raw    = (string) curl_exec($ch);
    $code   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $bodyRaw = substr($raw, $hsize);
    curl_close($ch);

    // Track the session cookie for later calls. The login handler regenerates
    // the session id, so take the LAST PHPSESSID in the header block.
    if (preg_match_all('/Set-Cookie:\s*PHPSESSID=([^;]+)/i', $raw, $m) && !empty($m[1])) {
        $JARS[$as] = end($m[1]);
    }

    $json = json_decode($bodyRaw, true);
    return ['code' => $code, 'body' => is_array($json) ? $json : [], 'raw' => $bodyRaw];
}

/** Assert a call succeeded and hand the payload back for further assertions. */
function expect(string $label, array $res, bool $expectFail = false): array
{
    $success = ($res['code'] >= 200 && $res['code'] < 300 && ($res['body']['success'] ?? false));

    if ($expectFail) {
        if ($res['code'] >= 400) {
            ok("$label correctly rejected (" . $res['code'] . ')');
            return $res;
        }
        bad($label, 'expected a 4xx error, got ' . $res['code']);
        return $res;
    }

    if (!$success) {
        $msg = $res['body']['message'] ?? substr(preg_replace('/\s+/', ' ', $res['raw']), 0, 220);
        bad($label, "HTTP {$res['code']}: $msg");
        return $res;
    }

    ok($label);
    return $res;
}

function must(string $label, bool $cond, string $why = ''): bool
{
    if ($cond) {
        ok($label);
        return true;
    }
    bad($label, $why);
    return false;
}

$GLOBALS['CSRF'] = [];

// ---------------------------------------------------------------- reachability
section('Server reachability');
$home = call('GET', 'index.php');
must('landing page responds 200', $home['code'] === 200, 'HTTP ' . $home['code']);

$pub = expect('GET api/public/overview.php (anonymous)', call('GET', 'api/public/overview.php'));
if ($pub['body']) {
    must('overview returns totals', ($pub['body']['data']['totals']['requests'] ?? 0) > 0);
    must('overview returns hotspot circles', count($pub['body']['data']['hotspots'] ?? []) > 0);
}
expect('GET api/admin/stats.php while logged out is blocked', call('GET', 'api/admin/stats.php'), true);

// --------------------------------------------------------------------- logins
section('Authentication');

$adminLogin = expect('POST api/auth/login.php (admin)',
    call('POST', 'api/auth/login.php', ['email' => 'admin@smartwaste.gov', 'password' => 'admin@123'], 'admin'));
$GLOBALS['CSRF']['admin'] = csrfFrom($adminLogin);

$workerLogin = expect('POST api/auth/login.php (worker)',
    call('POST', 'api/auth/login.php', ['email' => 'worker1@smartwaste.gov', 'password' => 'worker@123'], 'worker'));
$GLOBALS['CSRF']['worker'] = csrfFrom($workerLogin);

$citizenLogin = expect('POST api/auth/login.php (citizen)',
    call('POST', 'api/auth/login.php', ['email' => 'citizen1@smartwaste.gov', 'password' => 'citizen@123'], 'citizen'));
$GLOBALS['CSRF']['citizen'] = csrfFrom($citizenLogin);

expect('POST api/auth/login.php with a wrong password is rejected',
    call('POST', 'api/auth/login.php', ['email' => 'admin@smartwaste.gov', 'password' => 'nope'], 'x'), true);

expect('POST api/requests/create.php without a CSRF token is rejected',
    call('POST', 'api/requests/create.php', ['waste_type' => 'plastic'], 'admin', false), true);

foreach (['admin' => 'admin', 'worker' => 'worker', 'citizen' => 'citizen'] as $as => $label) {
    expect("GET api/auth/me.php ($label)", call('GET', 'api/auth/me.php', null, $as));
}

// --------------------------------------------------------- role guard matrix
section('Role guards');
expect('citizen cannot read admin stats', call('GET', 'api/admin/stats.php', null, 'citizen'), true);
expect('worker cannot read admin stats',  call('GET', 'api/admin/stats.php', null, 'worker'), true);
expect('citizen cannot see the team list',  call('GET', 'api/workers/list.php', null, 'citizen'), true);

// ---------------------------------------------------------------- admin reads
section('Admin read endpoints');
$stats = expect('GET api/admin/stats.php', call('GET', 'api/admin/stats.php', null, 'admin'));
if ($stats['body']) {
    $kpi = $stats['body']['data']['kpi'];
    must('stats exposes the KPI block', isset($kpi['total_requests'], $kpi['awaiting_verification']));
}
expect('GET api/admin/map-data.php',  call('GET', 'api/admin/map-data.php', null, 'admin'));
expect('GET api/admin/activity.php',  call('GET', 'api/admin/activity.php?limit=5', null, 'admin'));
expect('GET api/hotspots/list.php',   call('GET', 'api/hotspots/list.php', null, 'admin'));
expect('GET api/analytics/charts.php?days=30', call('GET', 'api/analytics/charts.php?days=30', null, 'admin'));
$charts = call('GET', 'api/analytics/charts.php?days=30', null, 'admin');
if ($charts['body']) {
    $ot = $charts['body']['data']['over_time'];
    must('charts over_time.completed is an array', is_array($ot['completed'] ?? null));
    must('charts over_time has 30 points', count($ot['labels'] ?? []) === 30);
}
$reqs = expect('GET api/requests/list.php?status=all&limit=5', call('GET', 'api/requests/list.php?status=all&limit=5', null, 'admin'));
$openRequests = $reqs['body']['data']['requests'] ?? [];

$reqView = expect('GET api/requests/view.php?id=' . ($openRequests[0]['id'] ?? 1),
    call('GET', 'api/requests/view.php?id=' . ($openRequests[0]['id'] ?? 1), null, 'admin'));
$reqId  = (int) ($reqView['body']['data']['request']['id'] ?? 0);
must('request detail carries a priority breakdown', isset($reqView['body']['data']['priority']['breakdown']));

expect('GET api/workers/list.php', call('GET', 'api/workers/list.php', null, 'admin'));
expect('GET api/assignments/list.php', call('GET', 'api/assignments/list.php?status=all', null, 'admin'));
expect('GET api/collection/plans.php', call('GET', 'api/collection/plans.php', null, 'admin'));
expect('GET api/collection/suggest.php?worker_id=1', call('GET', 'api/collection/suggest.php?worker_id=1', null, 'admin'));

// ------------------------------------------------------ citizen creates a case
section('Citizen reports waste');
$new = expect('POST api/requests/create.php', call('POST', 'api/requests/create.php', [
    'waste_type' => 'plastic',
    'quantity'   => 'medium',
    'address'    => 'Smoke Test Lane, Baner',
    'landmark'   => 'Near the temple',
    'description'=> 'Created by tests/smoke.php',
    'latitude'   => 18.5642,
    'longitude'  => 73.7769,
    'severity'   => 'high',
], 'citizen'));
$newId = (int) ($new['body']['data']['id'] ?? 0);
must('new request got an id', $newId > 0);
must('new request was scored automatically', ($new['body']['data']['priority']['score'] ?? 0) > 0,
    'score was ' . ($new['body']['data']['priority']['score'] ?? 'null'));
must('new request has a priority breakdown', count($new['body']['data']['priority']['breakdown'] ?? []) > 0);

expect('POST api/requests/create.php rejects a bad quantity',
    call('POST', 'api/requests/create.php', ['waste_type' => 'plastic', 'quantity' => 'gigantic', 'address' => 'x', 'latitude' => 18.5, 'longitude' => 73.8], 'citizen'), true);

$track = expect("GET api/requests/track.php?id=$newId", call('GET', "api/requests/track.php?id=$newId", null, 'citizen'));
must('tracking timeline exists', count($track['body']['data']['timeline'] ?? []) > 0);

expect('GET api/requests/list.php (own requests)', call('GET', 'api/requests/list.php', null, 'citizen'));

// ------------------------------------------------------ admin assigns + plans
section('Admin assignment and planning');
// Assign to *the* worker that is logged in, so the field-team half of this
// test can actually collect the pickup.
$me = call('GET', 'api/collection/mission.php', null, 'worker');
$workerId = (int) ($me['body']['data']['worker']['id'] ?? 0);
must('mission exposes the logged-in worker id', $workerId > 0);

expect('GET api/workers/list.php', call('GET', 'api/workers/list.php', null, 'admin'));

$reco = expect("GET api/workers/recommend.php?request_id=$newId",
    call('GET', "api/workers/recommend.php?request_id=$newId", null, 'admin'));
$recommended = $reco['body']['data']['recommendations'][0]['worker_id'] ?? 0;
must('recommendation engine ranked workers', $recommended > 0);
must('recommendation explains the score', ($reco['body']['data']['recommendations'][0]['compatibility_note'] ?? '') !== '');
must('every recommendation carries a score breakdown',
    count($reco['body']['data']['recommendations'][0]['breakdown_list'] ?? []) === 4);

$assign = expect('POST api/assignments/create.php', call('POST', 'api/assignments/create.php', [
    'request_id' => $newId,
    'worker_id'  => $workerId,
    'reason'     => 'Smoke test assignment',
], 'admin'));
must('assignment linked the request to the team',
    (int) ($assign['body']['data']['worker_id'] ?? 0) === $workerId);
must('assignment returns an ETA', ($assign['body']['data']['estimated_visit_time'] ?? '') !== '');

$plan = expect('POST api/collection/create-plan.php', call('POST', 'api/collection/create-plan.php', [
    'worker_id'   => $workerId,
    'request_ids' => [$newId],
    'plan_date'   => date('Y-m-d'),
], 'admin'));
$planId = (int) ($plan['body']['data']['plan_id'] ?? 0);
must('plan was created', $planId > 0);
must('plan optimised at least one stop', ($plan['body']['data']['stops'] ?? 0) > 0);
must('plan sequence includes the new request',
    in_array($newId, array_column($plan['body']['data']['sequence'] ?? [], 'request'), true));

$planView = expect("GET api/collection/plan-view.php?id=$planId", call('GET', "api/collection/plan-view.php?id=$planId", null, 'admin'));
must('plan view returns stops', count($planView['body']['data']['stops'] ?? []) > 0);

// the plan builder must have written the worker link, otherwise the field
// team's status updates would be rejected by the authorisation check
$afterPlan = expect("GET api/requests/view.php?id=$newId", call('GET', "api/requests/view.php?id=$newId", null, 'admin'));
must('plan assigned the request to the field team',
    (int) ($afterPlan['body']['data']['assignment']['worker_id'] ?? 0) === $workerId);

// ------------------------------------------------------------ worker workflow
section('Worker collection with photo proof');
expect('POST api/workers/update-location.php', call('POST', 'api/workers/update-location.php', [
    'lat' => 18.5640, 'lng' => 73.7771, 'accuracy' => 12, 'status' => 'available',
], 'worker'));

$mission = expect('GET api/collection/mission.php', call('GET', 'api/collection/mission.php', null, 'worker'));
must('mission payload has a stops array', isset($mission['body']['data']['stops']));
must('mission payload has mission totals', isset($mission['body']['data']['mission']['total_stops']));

expect('POST api/requests/update-status.php (action=arrive)', call('POST', 'api/requests/update-status.php', [
    'id' => $newId, 'action' => 'arrive', 'lat' => 18.5642, 'lng' => 73.7769,
], 'worker'));

expect('POST api/requests/update-status.php (action=start)', call('POST', 'api/requests/update-status.php', [
    'id' => $newId, 'action' => 'start',
], 'worker'));

// proof is mandatory: collecting before the photos exist must fail
expect('POST api/requests/update-status.php (action=collect) without photos is rejected',
    call('POST', 'api/requests/update-status.php', ['id' => $newId, 'action' => 'collect'], 'worker'), true);

$png = pngFixture('before');
expect('POST api/requests/upload.php rejects PNG', uploadFixture($newId, 'before', $png, 'worker'), true);
$beforeJpg = jpegFixture('before');
$afterJpg  = jpegFixture('after');
expect('POST api/requests/upload.php accepts JPEG before photo', uploadFixture($newId, 'before', $beforeJpg, 'worker'));
expect('POST api/requests/upload.php accepts JPEG after photo', uploadFixture($newId, 'after', $afterJpg, 'worker'));
@unlink($beforeJpg);
@unlink($afterJpg);
expect('POST api/requests/update-status.php (action=collect)', call('POST', 'api/requests/update-status.php', [
    'id' => $newId, 'action' => 'collect', 'lat' => 18.5642, 'lng' => 73.7769,
], 'worker'));

expect('worker cannot touch a pickup assigned to another team',
    call('POST', 'api/requests/update-status.php', ['id' => 1, 'action' => 'arrive'], 'worker'), true);

// -------------------------------------------------------- admin verification
section('Admin verification');
$awaiting = expect('GET api/requests/list.php?status=collected', call('GET', 'api/requests/list.php?status=collected', null, 'admin'));
must('collected queue is not empty', count($awaiting['body']['data']['requests'] ?? []) > 0);

// The smoke-test request is the one we know has proof and our worker, so the
// reject -> re-collect -> approve loop exercises the real business rule.
$detail = expect("GET api/requests/view.php?id=$newId", call('GET', "api/requests/view.php?id=$newId", null, 'admin'));
must('collected request shows both proof photos',
    count($detail['body']['data']['images']['before'] ?? []) > 0
    && count($detail['body']['data']['images']['after'] ?? []) > 0);

// The guard that matters: verification is impossible without proof photos.
$guard = expect('POST api/requests/create.php (second throwaway request)', call('POST', 'api/requests/create.php', [
    'waste_type' => 'general', 'quantity' => 'small',
    'address'    => 'Proof guard lane', 'landmark' => 'Next to the water tank',
    'latitude' => 18.5700, 'longitude' => 73.7800,
], 'citizen'));
$guardId = (int) ($guard['body']['data']['id'] ?? 0);
expect('POST api/admin/verify.php without proof photos is rejected',
    call('POST', 'api/admin/verify.php', ['id' => $guardId, 'decision' => 'approve'], 'admin'), true);
expect('POST api/requests/cancel.php (cleanup throwaway)',
    call('POST', 'api/requests/cancel.php', ['id' => $guardId, 'reason' => 'Smoke test cleanup'], 'citizen'));

expect('POST api/admin/verify.php (reject bad proof)', call('POST', 'api/admin/verify.php', [
    'id' => $newId, 'decision' => 'reject', 'note' => 'Smoke test: retake the after photo',
], 'admin'));
expect('POST api/requests/update-status.php (re-collected after rejection)', call('POST', 'api/requests/update-status.php', [
    'id' => $newId, 'action' => 'collect',
], 'worker'));
expect('POST api/admin/verify.php (approve)', call('POST', 'api/admin/verify.php', [
    'id' => $newId, 'decision' => 'approve', 'note' => 'Smoke test approved',
], 'admin'));

$done = expect("GET api/requests/view.php?id=$newId", call('GET', "api/requests/view.php?id=$newId", null, 'admin'));
must('verified request reached the "verified" status',
    ($done['body']['data']['request']['status'] ?? '') === 'verified',
    'status is ' . ($done['body']['data']['request']['status'] ?? 'unknown'));

$load = expect('GET api/collection/mission.php', call('GET', 'api/collection/mission.php', null, 'worker'));
$stopStatus = '';
foreach ($load['body']['data']['stops'] ?? [] as $s) {
    if ((int) $s['request_id'] === $newId) {
        $stopStatus = (string) $s['status'];
    }
}
must('the mission marks the verified pickup as finished', $stopStatus === 'verified', "stop status is '$stopStatus'");

expect('POST api/admin/priority.php', call('POST', 'api/admin/priority.php', [
    'id' => $newId, 'level' => 'medium',
], 'admin'));

expect('POST api/requests/feedback.php', call('POST', 'api/requests/feedback.php', [
    'id' => $newId, 'rating' => 5, 'comment' => 'Great work',
], 'citizen'));

// ------------------------------------------------------------------- cleanup
section('Cleanup');
// The verification round trip may have closed the smoke-test request, in which
// case cancelling it is correctly refused — either outcome is a pass.
$cancel = call('POST', 'api/requests/cancel.php', [
    'id' => $newId, 'reason' => 'Smoke test cleanup',
], 'citizen');
if (in_array($cancel['code'], [200, 422], true)) {
    ok("cleanup cancel answered as expected (HTTP {$cancel['code']})");
} else {
    bad('cleanup cancel', 'HTTP ' . $cancel['code'] . ': ' . ($cancel['body']['message'] ?? ''));
}

expect('POST api/hotspots/recompute.php', call('POST', 'api/hotspots/recompute.php', ['window_days' => 30], 'admin'));
expect('POST api/auth/logout.php', call('POST', 'api/auth/logout.php', [], 'admin'));
expect('GET api/auth/me.php after logout is blocked', call('GET', 'api/auth/me.php', null, 'admin'), true);

// -------------------------------------------------------------------- summary
echo "\n" . str_repeat('-', 60) . "\n";
echo "PASS: $PASS   FAIL: $FAIL\n";
if ($FAIL > 0) {
    echo "Smoke test FAILED\n";
    exit(1);
}
echo "Smoke test passed.\n";
exit(0);

// ------------------------------------------------------------------ helpers

function csrfFrom(array $res): string
{
    return (string) ($res['body']['data']['csrf_token'] ?? '');
}

/**
 * A real PNG big enough to pass the upload validator.
 * Prefers the generated demo photos; falls back to synthesising one with a
 * minimal hand-rolled PNG encoder (no GD on this box).
 */
function pngFixture(string $label): string
{
    $candidates = glob(dirname(__DIR__) . '/uploads/' . ($label === 'before' ? 'before' : 'after') . '/*.png') ?: [];
    if ($candidates) {
        return $candidates[0];
    }
    return makePng(160, 120, $label === 'before' ? [180, 60, 60] : [60, 160, 90]);
}

/** Pure-PHP PNG writer — used only by the test suite. */
function makePng(int $w, int $h, array $rgb): string
{
    $raw = '';
    for ($y = 0; $y < $h; $y++) {
        $raw .= chr(0); // filter type: none
        for ($x = 0; $x < $w; $x++) {
            $shade = (int) (($x + $y) % 40) - 20;
            $raw  .= chr(max(0, min(255, $rgb[0] + $shade)))
                  . chr(max(0, min(255, $rgb[1] + $shade)))
                  . chr(max(0, min(255, $rgb[2] + $shade)));
        }
    }

    $chunk = static function (string $type, string $data): string {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    };

    $png = "\x89PNG\r\n\x1a\n";
    $png .= $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0));
    $png .= $chunk('IDAT', (string) gzcompress($raw, 6));
    $png .= $chunk('IEND', '');

    $path = sys_get_temp_dir() . '/sw_smoke_' . $w . 'x' . $h . '.png';
    file_put_contents($path, $png);
    return $path;
}

/** Multipart upload through the same endpoint the browser uses. */
function uploadFixture(int $requestId, string $type, string $file, string $as): array
{
    global $BASE, $JARS;

    $ch = curl_init($BASE . '/api/requests/upload.php');
    $headers = ['X-CSRF-Token: ' . ($GLOBALS['CSRF'][$as] ?? '')];
    if (isset($JARS[$as])) {
        $headers[] = 'Cookie: PHPSESSID=' . $JARS[$as];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'id'         => $requestId,
            'type'       => $type,
            'lat'        => 18.5642,
            'lng'        => 73.7769,
            'image'      => new CURLFile($file, mime_content_type($file) ?: 'application/octet-stream', basename($file)),
        ],
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ]);

    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($raw, true);
    return ['code' => $code, 'body' => is_array($json) ? $json : [], 'raw' => $raw];
}
