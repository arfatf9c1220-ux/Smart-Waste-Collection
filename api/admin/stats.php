<?php
/**
 * api/admin/stats.php   GET   (admin)
 * The headline KPI tiles of the admin dashboard, read live from MySQL.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/hotspots.php';

api_require_role('admin');

$since30 = date('Y-m-d 00:00:00', strtotime('-29 days'));
$since7  = date('Y-m-d 00:00:00', strtotime('-6 days'));

$total    = (int) db_value('SELECT COUNT(*) FROM waste_requests', [], 0);
$pending  = (int) db_value('SELECT COUNT(*) FROM waste_requests WHERE status IN ("pending","prioritized")', [], 0);
$highPri  = (int) db_value('SELECT COUNT(*) FROM waste_requests WHERE status NOT IN ("verified","cancelled") AND priority_level IN ("high","critical")', [], 0);
$assigned = (int) db_value('SELECT COUNT(*) FROM waste_requests WHERE status IN ("assigned","scheduled","in_progress")', [], 0);
$done     = (int) db_value('SELECT COUNT(*) FROM waste_requests WHERE status IN ("collected","verified")', [], 0);
$awaiting = (int) db_value('SELECT COUNT(*) FROM waste_requests WHERE status = "collected"', [], 0);
$teams    = (int) db_value('SELECT COUNT(*) FROM workers WHERE status <> "removed"', [], 0);
$activeTeams = (int) db_value('SELECT COUNT(*) FROM workers WHERE status = "available"', [], 0);
$busyTeams   = (int) db_value('SELECT COUNT(*) FROM workers WHERE status = "busy"', [], 0);
$today     = (int) db_value('SELECT COUNT(*) FROM waste_requests WHERE DATE(created_at) = CURDATE()', [], 0);
$thisWeek  = (int) db_value('SELECT COUNT(*) FROM waste_requests WHERE created_at >= ?', [$since7], 0);
$lastWeek  = (int) db_value('SELECT COUNT(*) FROM waste_requests WHERE created_at >= ? AND created_at < ?',
                 [date('Y-m-d 00:00:00', strtotime('-13 days')), $since7], 0);

// Average collection time: created -> collected (verified work only).
$avgCollect = (float) db_value(
    'SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, collected_at))
       FROM waste_requests WHERE collected_at IS NOT NULL AND created_at >= ?', [$since30], 0);
$avgResponse = (float) db_value(
    'SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, assigned_at))
       FROM waste_requests WHERE assigned_at IS NOT NULL AND created_at >= ?', [$since30], 0);

$hotspots = db_all('SELECT * FROM hotspots ORDER BY hotspot_score DESC LIMIT 5');

json_success('Dashboard statistics loaded.', [
    'kpi' => [
        'total_requests'   => $total,
        'pending_requests' => $pending,
        'high_priority'    => $highPri,
        'assigned_requests'=> $assigned,
        'active_teams'     => $activeTeams,
        'total_teams'      => $teams,
        'busy_teams'       => $busyTeams,
        'completed'        => $done,
        'awaiting_verification' => $awaiting,
        'today'            => $today,
        'this_week'        => $thisWeek,
        'last_week'        => $lastWeek,
        'completion_rate'  => $total ? round($done / $total * 100) : 0,
        'avg_collection_min' => (int) round($avgCollect),
        'avg_collection_label' => fmt_duration((int) round($avgCollect)),
        'avg_response_min' => (int) round($avgResponse),
        'avg_response_label' => fmt_duration((int) round($avgResponse)),
    ],
    'priority_mix' => db_all(
        'SELECT priority_level, COUNT(*) AS c FROM waste_requests
          WHERE status NOT IN ("verified","cancelled")
          GROUP BY priority_level'
    ),
    'status_mix' => db_all(
        'SELECT status, COUNT(*) AS c FROM waste_requests GROUP BY status'
    ),
    'hotspots' => array_map(static fn(array $h): array => [
        'area_name'   => $h['area_name'],
        'latitude'    => (float) $h['latitude'],
        'longitude'   => (float) $h['longitude'],
        'report_count'=> (int) $h['report_count'],
        'avg_recurrence_days' => (float) $h['avg_recurrence_days'],
        'hotspot_score' => (int) $h['hotspot_score'],
        'risk_level'  => $h['risk_level'],
        'color'       => hotspot_color($h['risk_level']),
    ], $hotspots),
]);
