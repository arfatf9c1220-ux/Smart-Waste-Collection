<?php
/**
 * database/finalize.php
 * ------------------------------------------------------------------
 * Runs AFTER schema.sql + seed.sql. It fills in everything that must be
 * computed by the engines rather than typed in by hand:
 *
 *   1. priority_score / priority_level / priority_factors for every request
 *   2. predicted hotspots (rule based clustering of the request history)
 *   3. derived counters: citizens.total_reports, workers.current_load
 *
 * Usage:
 *     php database/finalize.php        (command line)
 *  or open  http://localhost/waste-php/database/finalize.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/priority.php';
require_once __DIR__ . '/../includes/hotspots.php';

echo "Rebuilding priority scores...\n";
$ids = db_all('SELECT id FROM waste_requests');
$distribution = [];
foreach ($ids as $r) {
    $p = refresh_request_priority((int) $r['id']);
    $distribution[$p['level']] = ($distribution[$p['level']] ?? 0) + 1;
}
printf("  %d request(s) scored. Distribution: %s\n",
    count($ids),
    implode(', ', array_map(fn($k, $v) => "$k=$v", array_keys($distribution), $distribution))
);

echo "Predicting hotspots...\n";
$hot = recompute_hotspots(HOTSPOT_WINDOW_DAYS);
printf("  %d hotspot(s) detected.\n", $hot['clusters']);
foreach (array_slice($hot['hotspots'], 0, 6) as $h) {
    printf("   - %-32s reports=%-3d every=%-5s score=%-3d risk=%s\n",
        $h['area_name'], $h['report_count'], $h['avg_recurrence_days'] . 'd', $h['hotspot_score'], $h['risk_level']);
}

echo "Refreshing counters...\n";
db_query('UPDATE citizens c SET total_reports = (
            SELECT COUNT(*) FROM waste_requests r WHERE r.citizen_id = c.id)');
db_query('UPDATE workers w SET current_load = (
            SELECT COUNT(*) FROM waste_requests r
             WHERE r.assigned_worker_id = w.id
               AND r.status IN ("assigned","scheduled","in_progress"))');
db_query('UPDATE workers w SET total_collected = (
            SELECT COUNT(*) FROM waste_requests r
             WHERE r.assigned_worker_id = w.id
               AND r.status IN ("collected","verified"))');
echo "  done.\n";

// Also drop a .htaccess so the folder is not browsable on a live server.
$ht = __DIR__ . '/.htaccess';
if (!file_exists($ht)) {
    file_put_contents($ht, "Require all denied\nDeny from all\n");
}
echo "\nAll set. Open http://localhost/waste-php/ to log in.\n";
