<?php
/**
 * tests/contract.php — verifies that every JS helper / CSS class / icon
 * referenced by the page templates actually exists in the assets.
 * Static reference check so missing helpers are caught before the browser.
 */
$root = dirname(__DIR__);
$app  = file_get_contents($root . '/assets/js/app.js');
$map  = file_get_contents($root . '/assets/js/map.js');
$css  = file_get_contents($root . '/assets/css/style.css');
$prio = file_get_contents($root . '/includes/priority.php');

$fail = 0;

/** true when $fn is defined anywhere in $src (function decl or object literal) */
function jsDefined(string $fn, string $src): bool
{
    return (bool) (
        preg_match('/function\s+' . preg_quote($fn, '/') . '\s*\(/', $src)
        || preg_match('/(?:const|let|var)\s+' . preg_quote($fn, '/') . '\s*=/', $src)
        || preg_match('/\b' . preg_quote($fn, '/') . '\s*[:=]\s*(async\s+)?(function|\()/', $src)
        || preg_match('/window\.' . preg_quote($fn, '/') . '\s*=/', $src)
    );
}

/** true when php function $fn exists (app bootstrap is loaded in CLI mode) */
function phpDefined(string $fn): bool
{
    global $root;
    return function_exists($fn);
}

// Bootstrap the real includes BEFORE any output so session_start() stays quiet.
if (PHP_SAPI === 'cli') {
    $_SERVER['HTTP_HOST']    = 'localhost';
    $_SERVER['REQUEST_URI']  = '/waste-php/';
    $_SERVER['REQUEST_METHOD'] = 'GET';
}
require_once $root . '/includes/auth.php';
require_once $root . '/includes/priority.php';

/** helpers that pages call from JavaScript */
$jsFns = [
    'apiGet' => $app, 'apiPost' => $app, 'showToast' => $app, 'escapeHtml' => $app,
    'fmtDuration' => $app, 'renderInto' => $app, 'priorityBadge' => $app,
    'createMap' => $map, 'pinIcon' => $map, 'workerIcon' => $map, 'drawHotspots' => $map,
    'drawWorkers' => $map, 'drawRoute' => $map,
];
echo "JS helpers\n" . str_repeat('-', 46) . "\n";
foreach ($jsFns as $fn => $src) {
    $ok = jsDefined($fn, $src);
    printf("  %-14s %s\n", $fn . '()', $ok ? 'OK' : 'MISSING');
    if (!$ok) $fail++;
}

/** globals the inline scripts rely on */
echo "\nGlobals\n" . str_repeat('-', 46) . "\n";
foreach (['APP_URL', 'CSRF'] as $g) {
    $ok = strpos($app, $g) !== false;
    printf("  window.%-10s %s\n", $g, $ok ? 'OK' : 'MISSING');
    if (!$ok) $fail++;
}

/** css classes used by the role pages */
$classes = [
    'card-soft', 'section-title', 'kpi', 'kpi-label', 'kpi-value', 'kpi-foot', 'kpi-icon',
    'empty-state', 'pill', 'stop-row', 'stop-num', 'queue-item', 'gallery-img',
    'before-after', 'ba-label', 'progress-thin', 'img-thumb-sm', 'map-shell', 'legend-box',
    'swatch', 'mono', 'min-w-0', 'icon-tile', 'badge-soft', 'table-glass', 'nav-pills',
];
echo "\nCSS classes\n" . str_repeat('-', 46) . "\n";
foreach ($classes as $c) {
    $ok = strpos($css, '.' . $c) !== false;
    printf("  .%-18s %s\n", $c, $ok ? 'OK' : 'MISSING');
    if (!$ok) $fail++;
}

/** php helpers every page calls */
echo "\nPHP helpers\n" . str_repeat('-', 46) . "\n";
$phpFns = [
    'priority_badge', 'status_badge', 'waste_meta', 'quantity_options',
    'fmt_dt', 'fmt_duration', 'time_ago', 'image_url', 'db_row', 'db_all', 'db_value',
];
foreach ($phpFns as $fn) {
    $ok = phpDefined($fn);
    printf("  %-18s() %s\n", $fn, $ok ? 'OK' : 'MISSING');
    if (!$ok) $fail++;
}

echo "\n" . str_repeat('=', 46) . "\n";
echo 'contract FAIL: ' . $fail . "\n";
exit($fail > 0 ? 1 : 0);
