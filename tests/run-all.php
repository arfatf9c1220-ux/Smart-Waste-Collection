<?php
declare(strict_types=1);
/**
 * tests/run-all.php — runs the whole verification suite in order and prints a
 * single summary. Static checks run without a server; the HTTP checks need the
 * app to be reachable.
 *
 * Usage: php tests/run-all.php [base-url]
 */
$BASE = rtrim($argv[1] ?? 'http://localhost/waste-php', '/');
$root = dirname(__DIR__);
$php  = escapeshellarg(PHP_BINARY);

function head(string $t): void
{
    echo "\n" . $t . "\n" . str_repeat('-', 74) . "\n";
}

function step(string $label, string $cmd, bool $needsServer = false): int
{
    global $BASE;
    $full = $needsServer ? str_replace('%BASE%', escapeshellarg($BASE), $cmd) : $cmd;
    passthru($full, $rc);
    printf("  => %-34s %s\n", $label, $rc === 0 ? 'PASS' : "FAIL (exit $rc)");
    return $rc === 0 ? 0 : 1;
}

echo "Smart Waste Collection Optimizer — verification suite\n";
echo "base url: $BASE\n";

$failures = 0;
$steps = [
    ['php syntax + close-tag/BOM lint', "$php " . escapeshellarg("$root/tests/lint-all.php"),          false],
    ['include dependency check',        "$php " . escapeshellarg("$root/tests/check-includes.php"),    false],
    ['js/css/php contract check',       "$php " . escapeshellarg("$root/tests/contract.php"),         false],
    ['api smoke test',                  "$php " . escapeshellarg("$root/tests/smoke.php") . ' %BASE%', true],
    ['worker field journey',            "$php " . escapeshellarg("$root/tests/worker-flow.php") . ' %BASE%', true],
    ['worker login accounts',           "$php " . escapeshellarg("$root/tests/worker-accounts.php") . ' %BASE%', true],
    ['every page for every role',       "$php " . escapeshellarg("$root/tests/pages.php") . ' %BASE%', true],
];

foreach ($steps as [$label, $cmd, $needsServer]) {
    head($label);
    $failures += step($label, $cmd, $needsServer);
}

head('SUMMARY');
if ($failures === 0) {
    echo "  ALL CHECKS PASSED\n";
    echo "\n  Next: reset the demo data with  php tests/reset-db.php\n";
    exit(0);
}
echo "  $failures step(s) failed\n";
exit(1);