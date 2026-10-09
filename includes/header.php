<?php
/**
 * includes/header.php
 * ------------------------------------------------------------------
 * Opens the HTML document, renders the dark sidebar and the top bar.
 *
 * A page sets these variables BEFORE including this file:
 *   $pageTitle    string  browser + <h1> title
 *   $pageSubtitle string  grey sub line under the title
 *   $activeNav    string  sidebar key to highlight
 *   $extraCss     array   extra <link> files (e.g. Leaflet)
 *   $bodyClass    string  optional <body> class
 */

require_once __DIR__ . '/auth.php';

$pageTitle    = $pageTitle    ?? APP_NAME;
$pageSubtitle = $pageSubtitle ?? '';
$activeNav    = $activeNav    ?? '';
$extraCss     = $extraCss     ?? [];
$bodyClass    = $bodyClass    ?? '';
$user         = current_user();
$role         = current_role();

// Unread notification count for the bell badge.
$unread = 0;
if ($user) {
    $unread = (int) db_value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [$user['id']], 0);
}

/**
 * Sidebar navigation per role.
 * 'key' => [icon, label, url, optional badge-count SQL]
 */
function nav_items(string $role): array
{
    if ($role === 'admin') {
        return [
            'overview' => ['bi-speedometer2', 'Dashboard',       '/admin/dashboard.php'],
            'requests' => ['bi-inbox',      'All Requests',    '/admin/requests.php'],
            'assign'   => ['bi-person-gear', 'Assignments',    '/admin/assignments.php'],
            'workers'  => ['bi-people',     'Workers & Teams', '/admin/workers.php'],
            'plans'    => ['bi-signpost-split', 'Collection Plans', '/admin/collection-plans.php'],
            'verify'   => ['bi-patch-check', 'Verify Proofs',  '/admin/verify.php'],
            'map'      => ['bi-map',        'Live Map',        '/admin/map.php'],
            'hotspots' => ['bi-fire',        'Predicted Hotspots', '/admin/hotspots.php'],
            'analytics'=> ['bi-bar-chart-line', 'Analytics',  '/admin/analytics.php'],
            'activity' => ['bi-clock-history', 'Activity Log', '/admin/activity.php'],
            'profile'  => ['bi-person-circle', 'My Profile', '/admin/profile.php'],
        ];
    }
    if ($role === 'worker') {
        return [
            'mission'  => ['bi-sign-turn-right', "Today's Mission", '/worker/dashboard.php'],
            'route'    => ['bi-map',       'My Route',        '/worker/mission.php'],
            'requests' => ['bi-list-check', 'My Pickups',      '/worker/requests.php'],
            'profile'  => ['bi-person-circle', 'Profile',       '/worker/profile.php'],
        ];
    }
    return [
        'home'    => ['bi-house-door',   'Dashboard',    '/citizen/dashboard.php'],
        'report'  => ['bi-megaphone',    'Report Waste', '/citizen/report.php'],
        'reqs'    => ['bi-list-ul',      'My Requests',  '/citizen/requests.php'],
        'track'   => ['bi-geo-alt',      'Track on Map', '/citizen/track.php'],
        'profile' => ['bi-person-circle','My Profile',   '/citizen/profile.php'],
    ];
}

$NAV = nav_items($role ?? 'citizen');

/** Optional counter badge next to a nav item. */
function nav_badge(string $key): ?int
{
    if ($key === 'requests' && current_role() === 'admin') {
        return (int) db_value('SELECT COUNT(*) FROM waste_requests WHERE status IN ("pending","prioritized")', [], 0);
    }
    if ($key === 'verify' && current_role() === 'admin') {
        return (int) db_value('SELECT COUNT(*) FROM waste_requests WHERE status = "collected"', [], 0);
    }
    if ($key === 'requests' && current_role() === 'worker') {
        $w = current_worker();
        if (!$w) return null;
        return (int) db_value(
            'SELECT COUNT(*) FROM waste_requests
              WHERE assigned_worker_id = ? AND status IN ("assigned","scheduled","in_progress")',
            [$w['id']], 0);
    }
    return null;
}

$roleLabel = ['admin' => 'Municipal Authority', 'worker' => 'Collection Team', 'citizen' => 'Citizen'][$role ?? 'citizen'] ?? 'Guest';
$profileUrl = ['admin' => '/admin/profile.php', 'worker' => '/worker/profile.php',
               'citizen' => '/citizen/profile.php'][$role ?? 'citizen'] ?? '/login.php';
$initials = function_exists('mb_substr')
    ? mb_strtoupper(mb_substr($user['full_name'] ?? 'G', 0, 1) . mb_substr(explode(' ', $user['full_name'] ?? 'U')[1] ?? '', 0, 1))
    : strtoupper(substr($user['full_name'] ?? 'G', 0, 2));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="Smart Waste Collection Optimizer — municipal waste pickup intelligence">
<title><?= e($pageTitle) ?> &middot; <?= e(APP_SHORT) ?></title>

<!-- Bootstrap 5 + Bootstrap Icons + Leaflet (OpenStreetMap, no paid API) -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" rel="stylesheet">
<?php foreach ($extraCss as $css): ?>
<link href="<?= e($css) ?>" rel="stylesheet">
<?php endforeach; ?>
<link href="<?= APP_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body class="<?= e($bodyClass) ?>">

<div class="app-shell">

  <!-- ======================================================== SIDEBAR ===== -->
  <aside class="sidebar" id="appSidebar">
    <div class="sidebar-brand">
      <div class="logo"><i class="bi bi-recycle"></i></div>
      <div>
        <div class="brand-name"><?= e(APP_SHORT) ?></div>
        <div class="brand-sub"><?= e(APP_CITY) ?></div>
      </div>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-label"><?= e($roleLabel) ?></div>
      <?php foreach ($NAV as $key => [$icon, $label, $url]):
        $badge = nav_badge($key); ?>
        <a class="nav-link <?= $activeNav === $key ? 'active' : '' ?>" href="<?= e(APP_URL . $url) ?>">
          <i class="bi bi-<?= e($icon) ?>"></i><span><?= e($label) ?></span>
          <?php if ($badge): ?><span class="count"><?= $badge ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
      <i class="bi bi-shield-check me-1"></i>Session secured &middot; v1.0
    </div>
  </aside>
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

  <!-- ========================================================= MAIN ===== -->
  <div class="app-main">
    <header class="topbar">
      <button class="hamburger" id="sidebarToggle" aria-label="Toggle menu"><i class="bi bi-list"></i></button>

      <div class="flex-grow-1 min-w-0">
        <h1 class="page-title text-truncate"><?= e($pageTitle) ?></h1>
        <?php if ($pageSubtitle): ?>
          <p class="page-sub text-truncate"><?= e($pageSubtitle) ?></p>
        <?php endif; ?>
      </div>

      <div class="d-flex align-items-center gap-2">
        <span class="d-none d-md-inline pill bg-success-subtle text-success">
          <span class="status-dot bg-success"></span> System online
        </span>

        <div class="dropdown">
          <button class="btn btn-light border position-relative px-2" data-bs-toggle="dropdown" aria-label="Notifications">
            <i class="bi bi-bell"></i>
            <?php if ($unread > 0): ?>
              <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:.6rem">
                <?= $unread > 9 ? '9+' : $unread ?>
              </span>
            <?php endif; ?>
          </button>
          <div class="dropdown-menu dropdown-menu-end shadow border-0" style="width:340px">
            <h6 class="dropdown-header">Notifications</h6>
            <?php
            $notifs = $user ? db_all('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 6', [$user['id']]) : [];
            if (!$notifs): ?>
              <div class="px-3 py-3 text-muted small">No notifications yet.</div>
            <?php else: foreach ($notifs as $n): ?>
              <a class="dropdown-item small py-2" href="<?= e($n['link'] ? APP_URL . $n['link'] : '#') ?>">
                <i class="bi bi-<?= $n['is_read'] ? 'bell' : 'bell-fill' ?> me-2 text-<?= e($n['type'] === 'danger' ? 'danger' : ($n['type'] === 'success' ? 'success' : 'primary')) ?>"></i>
                <strong><?= e($n['title']) ?></strong><br>
                <span class="text-muted"><?= e($n['message']) ?></span>
              </a>
            <?php endforeach; endif; ?>
          </div>
        </div>

        <div class="dropdown">
          <button class="btn btn-light border d-flex align-items-center gap-2" data-bs-toggle="dropdown">
            <span class="avatar-circle"><?= e($initials) ?></span>
            <span class="d-none d-sm-inline text-start lh-sm">
              <span class="d-block fw-600" style="font-size:.84rem"><?= e($user['full_name'] ?? 'Guest') ?></span>
              <span class="d-block text-muted" style="font-size:.7rem"><?= e($roleLabel) ?></span>
            </span>
            <i class="bi bi-chevron-down small text-muted"></i>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow border-0">
            <li><a class="dropdown-item" href="<?= e(APP_URL . $profileUrl) ?>">
              <i class="bi bi-person me-2"></i>My profile</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="<?= e(APP_URL . '/logout.php') ?>">
              <i class="bi bi-box-arrow-right me-2"></i>Log out</a></li>
          </ul>
        </div>
      </div>
    </header>

    <main class="content">
      <?php if (!empty($_SESSION['flash'])): ?>
        <div class="alert alert-warning border-0 shadow-sm d-flex align-items-center gap-2 py-2 mb-3" style="border-radius:12px">
          <i class="bi bi-info-circle"></i><?= e($_SESSION['flash']) ?>
          <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash']); ?>
      <?php endif;
