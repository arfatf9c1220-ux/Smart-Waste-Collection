<?php
declare(strict_types=1);
/**
 * tests/check-includes.php
 *
 * Static dependency check: every PHP file under api/, admin/, worker/,
 * citizen/ and the public root must require the helper file that defines each
 * helper function it calls. Catches the classic "include path is one level
 * too shallow" bug without needing a web server.
 *
 * Usage: php tests/check-includes.php
 */

$ROOT = dirname(__DIR__);

/** function => file that defines it */
$defs = [
    // includes/functions.php
    'image_url' => 'functions', 'waste_meta' => 'functions', 'status_meta' => 'functions',
    'priority_meta' => 'functions', 'haversine_km' => 'functions',
    'estimate_drive_minutes' => 'functions', 'within_radius' => 'functions',
    'log_activity' => 'functions', 'notify' => 'functions',
    'quantity_options' => 'priority', 'fmt_duration' => 'functions',
    // includes/auth.php
    'current_citizen' => 'auth', 'current_worker' => 'auth', 'current_user' => 'auth',
    'api_require_role' => 'auth', 'api_require_login' => 'auth',
    'api_require_csrf' => 'auth', 'api_require_post' => 'auth',
    'api_handle_exception' => 'auth', 'logout_user' => 'auth',
    'require_role' => 'auth', 'require_login' => 'auth',
    // includes/hotspots.php
    'get_hotspots' => 'hotspots', 'recompute_hotspots' => 'hotspots',
    'hotspot_color' => 'hotspots', 'infer_area_name' => 'hotspots',
    // includes/upload.php
    'handle_upload' => 'upload', 'record_image' => 'upload', 'save_uploaded_image' => 'upload',
    // includes/priority.php
    'calculate_priority' => 'priority', 'recalculate_all_priorities' => 'priority',
    // includes/recommend.php
    'recommend_workers' => 'recommend',
    // includes/planner.php
    'build_route' => 'planner', 'create_collection_plan' => 'planner',
];

/** every file gets these */
$always = ['functions' => 1, 'database' => 1, 'config' => 1];

/** files that must require auth.php */
$needsAuth = ['api', 'admin', 'worker', 'citizen'];

function walk(string $dir): array
{
    if (!is_dir($dir)) return [];
    $out = [];
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        $out = is_dir($p) ? array_merge($out, walk($p)) : [$p];
    }
    return $out;
}

$targets = [];
foreach (['api', 'admin', 'worker', 'citizen'] as $dir) {
    $targets = array_merge($targets, walk($ROOT . '/' . $dir));
}
foreach (glob($ROOT . '/*.php') ?: [] as $f) {
    $targets[] = $f;
}

$problems   = 0;
$noAuth     = 0;
$checked    = 0;

foreach ($targets as $file) {
    $rel = str_replace('\\', '/', substr($file, strlen($ROOT) + 1));
    if (substr($file, -4) !== '.php') continue;
    $checked++;

    $src  = file_get_contents($file);
    $have = $always;
    if (preg_match_all('#includes/([a-z_]+)\.php#', $src, $m)) {
        foreach (array_unique($m[1]) as $h) $have[$h] = 1;
    }

    // auth.php requirement for role/API directories
    $top  = explode('/', $rel)[0];
    if (in_array($top, $needsAuth, true) && strpos($src, 'includes/auth.php') === false) {
        echo "NO AUTH  $rel\n";
        $noAuth++;
    }

    foreach ($defs as $fn => $provider) {
        // ignore the definition site itself
        if (preg_match('/(?<![\w$>])\b' . preg_quote($fn, '/') . '\s*\(/', $src)
            && !isset($have[$provider])) {
            echo "MISSING  $rel  $fn() -> includes/$provider.php\n";
            $problems++;
        }
    }
}

echo str_repeat('-', 60) . "\n";
echo "checked $checked php files\n";
if ($problems === 0) echo "OK: all includes satisfied\n";
else                  echo "problems: $problems\n";
if ($noAuth) echo "missing auth: $noAuth\n";

exit(($problems || $noAuth) ? 1 : 0);
