<?php
/**
 * tests/pages.php  —  renders every page for every role and fails on any
 * PHP warning / fatal error in the output.
 *
 * Usage:  php tests/pages.php [base-url]
 * Default base-url: http://localhost/waste-php
 */

declare(strict_types=1);

$BASE = rtrim($argv[1] ?? 'http://localhost/waste-php', '/');

$USERS = [
    'admin'   => ['admin@smartwaste.gov', 'admin@123'],
    'worker'  => ['worker1@smartwaste.gov', 'worker@123'],
    'citizen' => ['citizen1@smartwaste.gov', 'citizen@123'],
];

$PAGES = [
    'anonymous' => [
        '/index.php', '/login.php', '/register.php',
    ],
    'admin' => [
        '/admin/dashboard.php',
        '/admin/requests.php',
        '/admin/requests.php?status=collected',
        '/admin/requests.php?priority=critical&waste_type=plastic',
        '/admin/request.php?id=1',
        '/admin/assignments.php',
        '/admin/assignments.php?request_id=1',
        '/admin/workers.php',
        '/admin/collection-plans.php',
        '/admin/collection-plan.php?id=1',
        '/admin/verify.php',
        '/admin/verify.php?focus=1',
        '/admin/map.php',
        '/admin/map.php?status=verified&hotspots=0&workers=0',
        '/admin/hotspots.php',
        '/admin/hotspots.php?risk=high',
        '/admin/analytics.php',
        '/admin/analytics.php?days=7',
        '/admin/activity.php',
        '/admin/activity.php?action=login&role=admin',
        '/admin/profile.php',
    ],
    'worker' => [
        '/worker/dashboard.php',
        '/worker/mission.php',
        '/worker/requests.php',
        '/worker/requests.php?status=collected',
        '/worker/requests.php?status=done',
        '/worker/requests.php?status=all',
        '/worker/requests.php?focus=1',
        '/worker/profile.php',
    ],
    'citizen' => [
        '/citizen/dashboard.php',
        '/citizen/report.php',
        '/citizen/requests.php',
        '/citizen/track.php',
        '/citizen/profile.php',
    ],
];

$sid  = [];
$pass = 0;
$fail = 0;

/** Log in and remember the session cookie. */
function login(string $role, array $creds, string $base): bool
{
    global $sid;

    $ch = curl_init($base . '/api/auth/login.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['email' => $creds[0], 'password' => $creds[1]]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $raw  = (string) curl_exec($ch);
    $hs   = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // The login handler regenerates the session id, so the response carries two
    // PHPSESSID cookies — the last one is the live session.
    if (preg_match_all('/PHPSESSID=([^;]+)/', substr($raw, 0, $hs), $m) && !empty($m[1])) {
        $sid[$role] = end($m[1]);
    }
    return $code === 200;
}

/** Fetch a page with the role session attached. */
function fetchPage(string $path, string $base, ?string $session): array
{
    $ch = curl_init($base . $path);
    $headers = ['Accept: text/html'];
    if ($session) {
        $headers[] = 'Cookie: PHPSESSID=' . $session;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $body];
}

/** Any PHP diagnostic in the rendered page is a failure. */
function phpErrors(string $html): array
{
    $bad = [];
    $needles = ['Fatal error', 'Parse error', 'Warning:', 'Notice:', 'Deprecated:',
                'Uncaught', 'Call to undefined', 'Call to a member function'];
    foreach ($needles as $needle) {
        if (stripos($html, $needle) !== false) {
            $pos  = stripos($html, $needle);
            $bad[] = $needle . ' → ' . trim(preg_replace('/\s+/', ' ', strip_tags(substr($html, max(0, $pos - 40), 260))));
        }
    }
    return $bad;
}

echo "Rendering every page for every role\n";
echo str_repeat('=', 66) . "\n";

foreach ($PAGES as $role => $paths) {
    echo "\n[$role]\n";

    $session = null;
    if ($role !== 'anonymous') {
        if (!login($role, $USERS[$role], $BASE)) {
            echo "  [FAIL] could not log in as $role\n";
            $fail++;
            continue;
        }
        $session = $sid[$role] ?? null;
    }

    foreach ($paths as $path) {
        $res = fetchPage($path, $BASE, $session);

        if ($res['code'] !== 200) {
            echo "  [FAIL] $path -> HTTP {$res['code']}\n";
            $fail++;
            continue;
        }

        $errors = phpErrors($res['body']);
        if ($errors) {
            echo "  [FAIL] $path rendered with PHP diagnostics\n";
            foreach ($errors as $err) {
                echo "         $err\n";
            }
            $fail++;
            continue;
        }

        if (strpos($res['body'], '</html>') === false) {
            echo "  [FAIL] $path produced a truncated document\n";
            $fail++;
            continue;
        }

        printf("  [OK]   %-32s %6d bytes\n", $path, strlen($res['body']));
        $pass++;
    }
}

echo "\n" . str_repeat('-', 66) . "\n";
echo "pages PASS: $pass   FAIL: $fail\n";
exit($fail > 0 ? 1 : 0);
