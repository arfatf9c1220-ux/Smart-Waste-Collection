<?php
/**
 * api/public/overview.php   GET
 * Anonymous, read-only snapshot used by the landing-page map.
 * Deliberately small: no addresses, no citizen names, only counts + coords.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/hotspots.php';

try {
    $risk = clean_str($_GET['risk'] ?? '', 10);

    $where  = 'r.status NOT IN ("cancelled") AND r.latitude IS NOT NULL AND r.is_rejected = 0';
    $params = [];
    if ($risk !== '') {
        $where .= ' AND r.priority_level = ?';
        $params[] = $risk;
    }

    $requests = db_all(
        "SELECT r.id, r.waste_type, r.quantity, r.status, r.priority_score,
                r.priority_level, r.latitude, r.longitude, r.created_at
           FROM waste_requests r
          WHERE $where
          ORDER BY r.priority_score DESC
          LIMIT 250",
        $params
    );

    $hotspots = get_hotspots();

    json_success('Public overview loaded.', [
        'totals' => [
            'requests'    => (int) db_value('SELECT COUNT(*) FROM waste_requests WHERE is_rejected = 0', [], 0),
            'collected'   => (int) db_value('SELECT COUNT(*) FROM waste_requests WHERE status IN ("collected","verified")', [], 0),
            'in_progress' => (int) db_value('SELECT COUNT(*) FROM waste_requests WHERE status IN ("assigned","scheduled","in_progress")', [], 0),
            'teams'       => (int) db_value('SELECT COUNT(*) FROM workers WHERE status <> "removed"', [], 0),
            'hotspots'    => count($hotspots),
        ],
        'requests' => array_map(static fn(array $r): array => [
            'id'         => (int) $r['id'],
            'waste_type' => $r['waste_type'],
            'waste_label'=> waste_meta($r['waste_type'])['label'],
            'quantity'   => $r['quantity'],
            'status'     => $r['status'],
            'status_label'=> status_meta($r['status'])['label'],
            'priority_score' => (int) $r['priority_score'],
            'priority_level' => $r['priority_level'],
            'latitude'   => (float) $r['latitude'],
            'longitude'  => (float) $r['longitude'],
            'created_at' => $r['created_at'],
        ], $requests),
        'hotspots' => array_map(static fn(array $h): array => [
            'id'        => (int) $h['id'],
            'area_name' => $h['area_name'],
            'latitude'  => (float) $h['latitude'],
            'longitude' => (float) $h['longitude'],
            'radius_m'  => (int) $h['radius_m'],
            'report_count' => (int) $h['report_count'],
            'avg_recurrence_days' => (float) $h['avg_recurrence_days'],
            'hotspot_score' => (int) $h['hotspot_score'],
            'risk_level' => $h['risk_level'],
        ], $hotspots),
    ]);
} catch (Throwable $e) {
    api_handle_exception($e);
}
