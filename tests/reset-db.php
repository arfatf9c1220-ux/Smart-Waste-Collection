<?php
declare(strict_types=1);
/**
 * tests/reset-db.php — rebuild the demo database from schema.sql + seed.sql
 * and then recompute priorities / hotspots / counters.
 *
 * Safe to run any time: it drops and recreates every table in the schema, so
 * all demo data is restored to its pristine post-finalize state.
 *
 * Usage: php tests/reset-db.php
 */
$root = dirname(__DIR__);
require_once $root . '/config/config.php';
require_once $root . '/config/database.php';

echo "Rebuilding smart_waste_optimizer\n" . str_repeat('=', 46) . "\n";

/** Load and run a .sql file, reporting the first failure. */
function run_sql_file(string $path, string $label): void
{
    if (!is_readable($path)) {
        echo "  $label  FAILED (unreadable: $path)\n";
        exit(1);
    }
    $errors = db_run_script(file_get_contents($path));
    if ($errors) {
        echo "  $label  FAILED\n";
        foreach (array_slice($errors, 0, 8) as $e) echo "     $e\n";
        exit(1);
    }
    printf("  %-8s ok\n", $label);
}

// --- schema + seed ----------------------------------------------------------
run_sql_file($root . '/database/schema.sql', 'schema');
run_sql_file($root . '/database/seed.sql',   'seed');

// --- finalize (priorities, hotspots, counters) ------------------------------
$out = [];
$rc  = 0;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/database/finalize.php') . ' 2>&1', $out, $rc);
foreach ($out as $line) echo '  ' . $line . "\n";
if ($rc !== 0) {
    echo "finalize FAILED\n";
    exit(1);
}

echo str_repeat('=', 46) . "\n";

// --- verify ----------------------------------------------------------------
$checks = [
    'users'            => 'SELECT COUNT(*) FROM users',
    'citizens'         => 'SELECT COUNT(*) FROM citizens',
    'workers'          => 'SELECT COUNT(*) FROM workers',
    'teams'            => 'SELECT COUNT(*) FROM teams',
    'vehicles'         => 'SELECT COUNT(*) FROM vehicles',
    'waste_requests'   => 'SELECT COUNT(*) FROM waste_requests',
    'collection_plans' => 'SELECT COUNT(*) FROM collection_plans',
    'hotspots'         => 'SELECT COUNT(*) FROM hotspots',
    'notifications'    => 'SELECT COUNT(*) FROM notifications',
];
foreach ($checks as $label => $query) {
    printf("  %-18s %s\n", $label, db_value($query));
}

echo "\npriority distribution\n";
foreach (db_all('SELECT priority_level, COUNT(*) n FROM waste_requests GROUP BY priority_level ORDER BY FIELD(priority_level,"critical","high","medium","low")') as $row) {
    printf("  %-10s %d\n", $row['priority_level'], $row['n']);
}

echo "\nrequest status distribution\n";
foreach (db_all('SELECT status, COUNT(*) n FROM waste_requests GROUP BY status ORDER BY n DESC') as $row) {
    printf("  %-12s %d\n", $row['status'], $row['n']);
}

echo "\nDemo accounts\n";
foreach (db_all('SELECT email, role FROM users ORDER BY FIELD(role,"admin","worker","citizen"), email LIMIT 6') as $u) {
    printf("  %-28s %s\n", $u['email'], $u['role']);
}

echo "\nDatabase reset complete.\n";