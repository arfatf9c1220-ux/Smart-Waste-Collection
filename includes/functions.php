<?php
/**
 * includes/functions.php
 * ------------------------------------------------------------------
 * Small reusable helper functions used across the whole project.
 * Loaded automatically by every page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// ---------------------------------------------------------------------------
// OUTPUT / SECURITY HELPERS
// ---------------------------------------------------------------------------

/**
 * XSS protection: escape a value before printing it inside HTML.
 * Every dynamic value printed in a page should go through this.
 */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Send a JSON response and stop the script. */
function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Success JSON envelope. */
function json_success(string $message, array $data = []): void
{
    json_response(['success' => true, 'message' => $message, 'data' => $data]);
}

/** Error JSON envelope. */
function json_error(string $message, int $status = 400, array $extra = []): void
{
    json_response(array_merge(['success' => false, 'message' => $message], $extra), $status);
}

/** Redirect helper — every internal path is prefixed with APP_URL. */
function redirect(string $path): void
{
    if (preg_match('#^https?://#i', $path)) {
        header('Location: ' . $path);
        exit;
    }
    header('Location: ' . APP_URL . '/' . ltrim($path, '/'));
    exit;
}

/** Current request method (uppercase). */
function request_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

/**
 * Read an input value from POST or GET.
 * $default is used when the key does not exist.
 */
function input(string $key, $default = null)
{
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

/** Cast helper: input('page', 1) with int type. */
function input_int(string $key, int $default = 0): int
{
    $v = input($key, null);
    return $v === null || $v === '' ? $default : (int) $v;
}

/** Cast helper for floats (latitude / longitude). */
function input_float(string $key, ?float $default = null): ?float
{
    $v = input($key, null);
    if ($v === null || $v === '') {
        return $default;
    }
    return is_numeric($v) ? (float) $v : $default;
}

/**
 * Read the JSON body sent by fetch() (application/json).
 * Falls back to $_POST for regular form posts.
 */
function json_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return $_POST;
}

/** Read a field from the JSON body. */
function jinput(string $key, $default = null)
{
    static $body = null;
    if ($body === null) {
        $body = json_input();
    }
    return $body[$key] ?? $default;
}

/** Validate a latitude / longitude pair coming from the browser. */
function valid_coords($lat, $lng): bool
{
    if (!is_numeric($lat) || !is_numeric($lng)) {
        return false;
    }
    $lat = (float) $lat;
    $lng = (float) $lng;
    return $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180;
}

/** Short string trim with a hard length cap (defence in depth). */
function clean_str($value, int $max = 255): string
{
    $value = trim(strip_tags((string) $value));
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max);
    }
    return substr($value, 0, $max);
}

// ---------------------------------------------------------------------------
// DOMAIN CONSTANTS
// ---------------------------------------------------------------------------

/** Human labels + icons for request statuses. */
function status_meta(string $status): array
{
    return [
        'pending'     => ['label' => 'Pending',      'color' => 'secondary', 'icon' => 'hourglass-split'],
        'prioritized' => ['label' => 'Prioritized',  'color' => 'warning',   'icon' => 'lightning-charge'],
        'assigned'    => ['label' => 'Assigned',     'color' => 'info',      'icon' => 'person-check'],
        'scheduled'   => ['label' => 'Scheduled',    'color' => 'primary',   'icon' => 'calendar-check'],
        'in_progress' => ['label' => 'In Progress',  'color' => 'dark',      'icon' => 'truck'],
        'collected'   => ['label' => 'Collected',    'color' => 'success',   'icon' => 'check2-circle'],
        'verified'    => ['label' => 'Verified',     'color' => 'success',   'icon' => 'patch-check'],
        'cancelled'   => ['label' => 'Cancelled',    'color' => 'danger',    'icon' => 'x-circle'],
    ][$status] ?? ['label' => ucfirst($status), 'color' => 'secondary', 'icon' => 'question-circle'];
}

/** Ready made status badge HTML. */
function status_badge(string $status): string
{
    $m = status_meta($status);
    return '<span class="badge text-bg-' . $m['color'] . ' status-badge">'
        . '<i class="bi bi-' . $m['icon'] . ' me-1"></i>' . e($m['label']) . '</span>';
}

/** Priority level -> label, colour, marker colour. */
function priority_meta(string $level): array
{
    return [
        'critical' => ['label' => 'Critical', 'color' => 'danger',  'hex' => '#dc3545'],
        'high'     => ['label' => 'High',     'color' => 'warning', 'hex' => '#fd7e14'],
        'medium'   => ['label' => 'Medium',   'color' => 'warning', 'hex' => '#ffc107'],
        'low'      => ['label' => 'Low',      'color' => 'success', 'hex' => '#198754'],
    ][$level] ?? ['label' => ucfirst($level), 'color' => 'secondary', 'hex' => '#6c757d'];
}

/** Ready made priority badge HTML. */
function priority_badge(string $level, ?int $score = null): string
{
    $m = priority_meta($level);
    $text = $score !== null ? $m['label'] . ' &middot; ' . $score . '/100' : $m['label'];
    return '<span class="badge priority-badge bg-' . $m['color'] . '-subtle text-' . $m['color'] . '">'
        . '<i class="bi bi-graph-up-arrow me-1"></i>' . $text . '</span>';
}

/** Map marker colour for a priority level (or completed = blue). */
function marker_color(?int $score, string $status = 'pending'): string
{
    if (in_array($status, ['collected', 'verified'], true)) {
        return '#0d6efd'; // blue = completed
    }
    if ($score === null) {
        return '#6c757d';
    }
    if ($score >= 81) return '#dc3545'; // red
    if ($score >= 61) return '#fd7e14'; // orange
    if ($score >= 31) return '#ffc107'; // yellow
    return '#198754';                   // green
}

/** Icon + colour for each waste category. */
function waste_meta(string $type): array
{
    return [
        'general'      => ['label' => 'General Waste',        'icon' => 'trash3',              'color' => '#6c757d'],
        'plastic'      => ['label' => 'Plastic',              'icon' => 'cup-straw',           'color' => '#0dcaf0'],
        'organic'      => ['label' => 'Organic Waste',        'icon' => 'flower1',             'color' => '#198754'],
        'e_waste'      => ['label' => 'E-Waste',              'icon' => 'cpu',                 'color' => '#6f42c1'],
        'construction' => ['label' => 'Construction Waste',   'icon' => 'truck-flatbed',       'color' => '#a0522d'],
        'hazardous'    => ['label' => 'Hazardous Waste',      'icon' => 'exclamation-triangle', 'color' => '#dc3545'],
        'other'        => ['label' => 'Other',                'icon' => 'three-dots',          'color' => '#495057'],
    ][$type] ?? ['label' => ucfirst(str_replace('_', ' ', $type)), 'icon' => 'trash', 'color' => '#6c757d'];
}

/** Format a datetime for tables: "12 Mar 2026, 04:20 PM". */
function fmt_dt(?string $dt, string $format = 'd M Y, h:i A'): string
{
    if (!$dt || $dt === '0000-00-00 00:00:00') {
        return '—';
    }
    $ts = strtotime($dt);
    return $ts ? date($format, $ts) : '—';
}

/** Human "time ago" text. */
function time_ago(?string $dt): string
{
    if (!$dt) {
        return '—';
    }
    $diff = time() - strtotime($dt);
    if ($diff < 60)      return 'just now';
    if ($diff < 3600)    return floor($diff / 60) . ' min ago';
    if ($diff < 86400)   return floor($diff / 3600) . ' hr ago';
    if ($diff < 2592000) return floor($diff / 86400) . ' d ago';
    return fmt_dt($dt, 'd M Y');
}

/** Minutes -> "2h 15m". */
function fmt_duration(?int $minutes): string
{
    if ($minutes === null) {
        return '—';
    }
    if ($minutes < 60) {
        return $minutes . 'm';
    }
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    return $m > 0 ? "{$h}h {$m}m" : "{$h}h";
}

// ---------------------------------------------------------------------------
// GEO HELPERS
// ---------------------------------------------------------------------------

/**
 * Great-circle distance between two points in kilometres (Haversine).
 * Used by the recommendation engine and the route planner.
 */
function haversine_km(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthRadius = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

    return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/**
 * Frees up to $delta slots on a worker's load counter.
 * current_load is UNSIGNED, so `current_load - 1` on a zero counter overflows
 * in MariaDB — the value is cast to SIGNED before the arithmetic.
 */
function release_worker_load(int $workerId, int $delta = 1): void
{
    db_query(
        'UPDATE workers
            SET status       = IF(status = "busy" AND CAST(current_load AS SIGNED) - ? <= 0,
                                  "available", status),
                availability = IF(availability = "busy" AND CAST(current_load AS SIGNED) - ? <= 0,
                                  "available", availability),
                current_load = GREATEST(0, CAST(current_load AS SIGNED) - ?),
                updated_at   = NOW()
          WHERE id = ?',
        [$delta, $delta, $delta, $workerId]
    );
}

/** Public URL of a stored relative path (uploads/…). */
function image_url(?string $relativePath): ?string
{
    if (!$relativePath) {
        return null;
    }
    return APP_URL . '/' . ltrim($relativePath, '/');
}

/**
 * Rough drive-time estimate for an Indian city average of ~22 km/h
 * including stop-and-go traffic, plus 3 minutes of on-site work.
 */
function estimate_drive_minutes(float $km): int
{
    return (int) max(1, round(($km / 22.0) * 60));
}

// ---------------------------------------------------------------------------
// ACTIVITY LOG + NOTIFICATIONS
// ---------------------------------------------------------------------------

/** Record an action in activity_logs (used by every write operation). */
function log_activity(string $action, string $entityType, ?int $entityId, string $description): void
{
    try {
        db_query(
            'INSERT INTO activity_logs (user_id, actor_role, action, entity_type, entity_id, description, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                current_user_id(),
                $_SESSION['role'] ?? 'guest',
                $action,
                $entityType,
                $entityId,
                clean_str($description, 500),
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]
        );
    } catch (Throwable $e) {
        // Logging must never break the main flow.
        error_log('activity log failed: ' . $e->getMessage());
    }
}

/** Create a notification for a user. */
function notify(int $userId, string $title, string $message, string $type = 'info', ?string $link = null): void
{
    try {
        db_query(
            'INSERT INTO notifications (user_id, title, message, type, link) VALUES (?, ?, ?, ?, ?)',
            [$userId, clean_str($title, 120), clean_str($message, 255), $type, $link]
        );
    } catch (Throwable $e) {
        error_log('notification failed: ' . $e->getMessage());
    }
}

/** Resolve a user_id from a worker row id (or return null). */
function worker_user_id(?int $workerId): ?int
{
    if (!$workerId) {
        return null;
    }
    $uid = db_value('SELECT user_id FROM workers WHERE id = ?', [$workerId]);
    return $uid ? (int) $uid : null;
}
