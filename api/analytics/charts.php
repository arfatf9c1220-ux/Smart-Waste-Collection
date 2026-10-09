<?php
/**
 * api/analytics/charts.php   GET ?days=30   (admin)
 * Everything Chart.js needs, read live from MySQL.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/priority.php';

api_require_role('admin');

$days = max(7, min(180, (int) ($_GET['days'] ?? 30)));
if ($days <= 0) $days = 30;

$since = date('Y-m-d 00:00:00', strtotime("-" . ($days - 1) . " days"));

// --- 1. Line chart: requests per day -----------------------------------------
$perDay = db_all(
    'SELECT DATE(created_at) AS d, COUNT(*) AS c,
            SUM(status IN ("verified","collected")) AS done
       FROM waste_requests
      WHERE created_at >= ?
      GROUP BY DATE(created_at)
      ORDER BY d',
    [$since]
);
$byDate = [];
foreach ($perDay as $row) {
    $byDate[$row['d']] = ['total' => (int) $row['c'], 'done' => (int) $row['done']];
}
// Fill missing days so the line is continuous.
$labels = $totals = $completed = [];
for ($i = $days - 1; $i >= 0; $i--) {
    $d       = date('Y-m-d', strtotime("-$i days"));
    $labels[] = date('j M', strtotime($d));
    $totals[] = $byDate[$d]['total'] ?? 0;
    $completed[] = $byDate[$d]['done'] ?? 0;
}

// --- 2. Doughnut: waste categories -------------------------------------------
$byType = db_all(
    'SELECT waste_type, COUNT(*) AS c FROM waste_requests
      WHERE created_at >= ? GROUP BY waste_type ORDER BY c DESC',
    [$since]
);
$typeLabels = $typeData = $typeColors = [];
foreach (waste_options() as $key => $label) {
    $c = 0;
    foreach ($byType as $row) {
        if ($row['waste_type'] === $key) $c = (int) $row['c'];
    }
    if ($c > 0) {
        $typeLabels[] = $label;
        $typeData[]   = $c;
        $typeColors[] = waste_meta($key)['color'];
    }
}

// --- 3. Bar: requests by priority level --------------------------------------
$byLevel = db_all(
    'SELECT priority_level, COUNT(*) AS c FROM waste_requests
      WHERE created_at >= ? GROUP BY priority_level',
    [$since]
);
$levelMap = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
foreach ($byLevel as $row) {
    $levelMap[$row['priority_level']] = (int) $row['c'];
}

// --- 4. Bar: worker performance ----------------------------------------------
$workers = db_all(
    'SELECT w.id, w.worker_code, u.full_name,
            SUM(r.status IN ("verified","collected"))  AS completed,
            COUNT(r.id)                                AS assigned
       FROM workers w
       JOIN users u ON u.id = w.user_id
       LEFT JOIN waste_requests r ON r.assigned_worker_id = w.id
      GROUP BY w.id, w.worker_code, u.full_name
      ORDER BY completed DESC'
);
$workerLabels = $workerData = $workerColors = [];
foreach ($workers as $w) {
    if ((int) $w['assigned'] === 0) continue;
    $workerLabels[] = $w['worker_code'];
    $workerData[]   = (int) $w['completed'];
    $workerColors[] = '#16a34a';
}

// --- 5. Completed vs pending doughnut ----------------------------------------
$statusDone = (int) db_value('SELECT COUNT(*) FROM waste_requests WHERE status IN ("verified","collected")', [], 0);
$pending    = (int) db_value('SELECT COUNT(*) FROM waste_requests WHERE status IN ("pending","prioritized")', [], 0);
$active     = (int) db_value('SELECT COUNT(*) FROM waste_requests WHERE status IN ("assigned","scheduled","in_progress")', [], 0);

// --- 6. Most reported locations (grouped by landmark) ------------------------
$locations = db_all(
    'SELECT COALESCE(NULLIF(landmark, ""), address) AS place,
            COUNT(*) AS reports,
            MAX(priority_score) AS peak,
            AVG(priority_score) AS avg_score,
            SUM(status IN ("verified","collected")) AS resolved
       FROM waste_requests
      WHERE created_at >= ?
      GROUP BY place
      ORDER BY reports DESC
      LIMIT 8',
    [$since]
);

// --- 7. Response + collection timings -----------------------------------------
$response = (float) db_value(
    'SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, assigned_at))
       FROM waste_requests WHERE assigned_at IS NOT NULL AND created_at >= ?',
    [$since], 0);
$collectTime = (float) db_value(
    'SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, collected_at))
       FROM waste_requests WHERE collected_at IS NOT NULL AND created_at >= ?',
    [$since], 0);
$verifyTime = (float) db_value(
    'SELECT AVG(TIMESTAMPDIFF(MINUTE, collected_at, verified_at))
       FROM waste_requests WHERE verified_at IS NOT NULL AND collected_at >= ?',
    [$since], 0);

// --- 8. Weekly volume vs target (small bar) ----------------------------------
$weekday = db_all(
    'SELECT DAYNAME(created_at) AS dow, COUNT(*) AS c
       FROM waste_requests WHERE created_at >= ?
      GROUP BY DAYOFWEEK(created_at) ORDER BY DAYOFWEEK(created_at)',
    [$since]
);

json_success('Analytics loaded.', [
    'range' => ['days' => $days, 'since' => $since],
    'over_time' => ['labels' => $labels, 'total' => $totals, 'completed' => $completed],
    'by_waste_type' => ['labels' => $typeLabels, 'data' => $typeData, 'colors' => $typeColors],
    'by_priority' => [
        'labels' => ['Critical', 'High', 'Medium', 'Low'],
        'data'   => [$levelMap['critical'], $levelMap['high'], $levelMap['medium'], $levelMap['low']],
        'colors' => ['#dc3545', '#fd7e14', '#ffc107', '#198754'],
    ],
    'by_worker' => ['labels' => $workerLabels, 'data' => $workerData, 'colors' => $workerColors],
    'status_split' => [
        'labels' => ['Completed', 'In progress', 'Pending'],
        'data'   => [$statusDone, $active, $pending],
        'colors' => ['#198754', '#0d6efd', '#ffc107'],
    ],
    'locations' => array_map(static fn(array $l): array => [
        'place'    => $l['place'],
        'reports'  => (int) $l['reports'],
        'peak'     => (int) $l['peak'],
        'avg_score'=> round((float) $l['avg_score'], 1),
        'resolved' => (int) $l['resolved'],
    ], $locations),
    'timings' => [
        'avg_response_min'   => (int) round($response),
        'avg_response_label' => fmt_duration((int) round($response)),
        'avg_collection_min' => (int) round($collectTime),
        'avg_collection_label' => fmt_duration((int) round($collectTime)),
        'avg_verification_min'=> (int) round($verifyTime),
        'avg_verification_label' => fmt_duration((int) round($verifyTime)),
    ],
    'weekday' => array_map(static fn(array $w): array => [
        'day' => $w['dow'], 'count' => (int) $w['c'],
    ], $weekday),
]);
