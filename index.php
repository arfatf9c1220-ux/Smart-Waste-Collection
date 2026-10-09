<?php
/**
 * index.php  —  Landing page
 * Public marketing / "smart city" front page with a live look at the system.
 */
require_once __DIR__ . '/includes/auth.php';

// Already signed in? Go straight to the dashboard.
if (is_logged_in()) {
    redirect(dashboard_for_role(current_role()));
}

$stats = [
    'total'    => (int) db_value('SELECT COUNT(*) FROM waste_requests', [], 0),
    'done'     => (int) db_value('SELECT COUNT(*) FROM waste_requests WHERE status IN ("collected","verified")', [], 0),
    'hotspots' => (int) db_value('SELECT COUNT(*) FROM hotspots', [], 0),
    'teams'    => (int) db_value('SELECT COUNT(*) FROM workers WHERE status <> "removed"', [], 0),
];
$features = [
    ['bi-cpu',        'AI Priority',        'Every request is scored 0-100 from waste severity, quantity, location sensitivity, age and repeat reports.'],
    ['bi-person-gear', 'Smart Assignment',   'The system ranks every team on distance, availability, workload and waste-type capability.'],
    ['bi-signpost-split', 'Route Optimisation', 'A priority-seeded nearest-neighbour engine builds the collection sequence for the day.'],
    ['bi-map',        'Live Map',           'Requests, vehicles and predicted hotspots on an interactive OpenStreetMap view.'],
    ['bi-broadcast',  'Worker Tracking',    'Field teams report arrival, GPS location and photo proof from their phone.'],
    ['bi-bar-chart-line', 'Analytics',      'Daily volumes, category mix, worker performance and response times.'],
];
$steps = [
    ['bi-megaphone',        'Request',          'A citizen reports waste with a photo and GPS pin.'],
    ['bi-graph-up-arrow',   'Priority Analysis','The engine scores it 0-100 and explains why.'],
    ['bi-person-check',     'Smart Assignment','The best team is recommended by distance, load and capability.'],
    ['bi-signpost-split',   'Collection Plan',  'Stops are ordered into an optimised route for the day.'],
    ['bi-truck',            'Worker Pickup',    'Arrive, start collection, and upload before/after proof.'],
    ['bi-patch-check',      'Verified',         'An officer reviews the proof and closes the loop.'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(APP_NAME) ?> &middot; <?= e(APP_CITY) ?></title>
<meta name="description" content="Turn waste requests into intelligent collection plans. Priority scoring, smart worker assignment, route optimisation and proof-of-collection.">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<link href="<?= e(APP_URL) ?>/assets/css/style.css?v=<?= (int) filemtime(__DIR__ . '/assets/css/style.css') ?>" rel="stylesheet">
<style>
  .site-nav { background: rgba(7, 33, 26, .96); backdrop-filter: blur(10px); border-bottom: 1px solid rgba(255,255,255,.08); }
  .site-nav .navbar-brand { color: #fff; font-weight: 800; letter-spacing: -.02em; }
  .brand-mark { width: 34px; height: 34px; border-radius: 10px; display: inline-grid; place-items: center;
    background: linear-gradient(135deg,#22c55e,#15803d); color:#fff; margin-right:.5rem; }
  .site-nav .nav-link { color: #b9d3c8; font-weight: 500; }
  .site-nav .nav-link:hover { color:#fff; }
  .site-nav .navbar-toggler-icon { filter: brightness(0) invert(1); }
  html { scroll-behavior: smooth; scroll-padding-top: 5.5rem; }
  section { padding: 4.5rem 0; scroll-margin-top: 5rem; }
  .eyebrow { color: var(--sw-green); font-weight: 700; letter-spacing: .12em; text-transform: uppercase; font-size: .74rem; }
  .hero-map { position: relative; border-radius: 8px; overflow: hidden; border: 1px solid rgba(255,255,255,.2); box-shadow: 0 24px 60px rgba(0,0,0,.35); }
  #map.hero-map-canvas { height: 450px; }
  .map-data-label { position: absolute; z-index: 500; top: 14px; right: 14px; display: flex; align-items: center; gap: .65rem;
    padding: .6rem .75rem; border: 1px solid rgba(15, 40, 32, .12); border-radius: 6px; background: rgba(255,255,255,.95);
    box-shadow: 0 4px 14px rgba(16,40,32,.14); color: #17372b; font-size: .72rem; font-weight: 700; pointer-events: none; }
  .map-live-dot { width: 8px; height: 8px; border-radius: 50%; background: #16a34a; box-shadow: 0 0 0 3px #dcfce7; }
  .map-data-count { color: #61756b; border-left: 1px solid #dfe8e3; padding-left: .65rem; font-weight: 600; }
  .map-caption { color: #a4c4b6; font-size: .8rem; }
  footer.site { background: #07211a; color: #8fb3a4; padding: 2.5rem 0 1.5rem; font-size: .84rem; }
  footer.site a { color: #b9d3c8; }
  .demo-grid { background: #fbfdfc; }
  @media (max-width: 991.98px) {
    #map.hero-map-canvas { height: 390px; }
  }
  @media (max-width: 575.98px) {
    section { padding: 3.5rem 0; }
    #map.hero-map-canvas { height: 320px; }
    .map-data-label { top: 10px; right: 10px; padding: .5rem .6rem; font-size: .66rem; }
  }
</style>
</head>
<body>

<nav class="navbar navbar-expand-lg site-nav fixed-top py-2">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center" href="<?= e(APP_URL) ?>/index.php">
      <span class="brand-mark"><i class="bi bi-recycle"></i></span> <?= e(APP_SHORT) ?>
    </a>
    <button class="navbar-toggler border-0 text-white" type="button" aria-label="Toggle navigation" aria-expanded="false" data-bs-toggle="collapse" data-bs-target="#navMain">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navMain">
      <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
        <li class="nav-item"><a class="nav-link" href="#features">Features</a></li>
        <li class="nav-item"><a class="nav-link" href="#how">How it works</a></li>
        <li class="nav-item"><a class="nav-link" href="#live">Live map</a></li>
        <li class="nav-item"><a class="nav-link" href="#demo">Demo accounts</a></li>
        <li class="nav-item ms-lg-2">
          <a class="btn btn-sm btn-outline-light px-3" href="<?= e(APP_URL) ?>/login.php">Log in</a>
        </li>
        <li class="nav-item">
          <a class="btn btn-sm btn-success px-3" href="<?= e(APP_URL) ?>/register.php">Report Waste</a>
        </li>
      </ul>
    </div>
  </div>
</nav>

<!-- ============================================================== HERO -->
<header class="hero">
  <div class="container position-relative">
    <div class="row gx-4 gy-4 align-items-center">
      <div class="col-lg-6">
        <span class="hero-badge d-inline-flex align-items-center gap-2 mb-3">
          <i class="bi bi-stars"></i> <?= e(APP_CITY) ?> Smart City Initiative
        </span>
        <h1 class="mb-3">Smart waste<br>collection <span class="accent">for Mumbai.</span></h1>
        <p class="lead mb-4">
          Turn neighborhood reports into priority-led pickups, better crew assignments and shorter collection routes.
        </p>
        <div class="hero-actions d-flex flex-wrap gap-2 mb-4">
          <a class="btn btn-success btn-lg px-4" href="<?= e(APP_URL) ?>/register.php">
            <i class="bi bi-camera me-2"></i>Report a pickup</a>
          <a class="btn btn-outline-light btn-lg px-4" href="#live">
            <i class="bi bi-map me-2"></i>Explore live map</a>
        </div>

        <div class="row g-3">
          <div class="col-6 col-md-3 hero-stat">
            <div class="v"><?= $stats['total'] ?></div><div class="l">Requests tracked</div>
          </div>
          <div class="col-6 col-md-3 hero-stat">
            <div class="v"><?= $stats['done'] ?></div><div class="l">Collected</div>
          </div>
          <div class="col-6 col-md-3 hero-stat">
            <div class="v"><?= $stats['teams'] ?></div><div class="l">Field teams</div>
          </div>
          <div class="col-6 col-md-3 hero-stat">
            <div class="v"><?= $stats['hotspots'] ?></div><div class="l">Hotspots found</div>
          </div>
        </div>
      </div>

      <div class="col-lg-6" id="live">
        <div class="hero-map">
          <div class="map-data-label"><span class="map-live-dot"></span><span>LIVE CITY FEED</span>
            <span class="map-data-count"><?= $stats['hotspots'] ?> predicted zones</span></div>
          <div id="map" class="hero-map-canvas"></div>
        </div>
        <p class="map-caption mt-2 mb-0">
          <i class="bi bi-geo-alt me-1"></i>Greater Mumbai · Request locations and predicted collection hotspots
        </p>
      </div>
    </div>
  </div>
</header>

<!-- ========================================================== FEATURES -->
<section id="features">
  <div class="container">
    <div class="text-center mb-5">
      <div class="eyebrow">What the system does</div>
      <h2 class="fw-bold mt-2" style="letter-spacing:-.02em">Six engines working as one</h2>
      <p class="text-muted mb-0">From a citizen photo to a verified collection — nothing is a manual guess.</p>
    </div>
    <div class="row g-4">
      <?php foreach ($features as [$icon, $title, $text]): ?>
        <div class="col-md-6 col-lg-4">
          <div class="feature-card">
            <div class="ficon"><i class="bi <?= e($icon) ?>"></i></div>
            <h3 class="section-title mb-2"><?= e($title) ?></h3>
            <p class="text-muted mb-0" style="font-size:.87rem"><?= e($text) ?></p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ========================================================== HOW IT WORKS -->
<section id="how" style="background:#fbfdfc;border-top:1px solid var(--sw-line);border-bottom:1px solid var(--sw-line)">
  <div class="container">
    <div class="row gx-4 gy-4">
      <div class="col-lg-5">
        <div class="eyebrow">The workflow</div>
        <h2 class="fw-bold mt-2 mb-3" style="letter-spacing:-.02em">From complaint to closed loop</h2>
        <p class="text-muted">
          This is not a complaint register. Every request travels a measurable pipeline and
          the system refuses to mark work complete without photographic proof.
        </p>
        <div class="card card-soft p-3 mt-4">
          <div class="fw-bold mb-2"><i class="bi bi-calculator me-2 text-success"></i>Priority = 0-100</div>
          <div class="small text-muted mb-2">Weighted factors behind every score:</div>
          <div class="d-flex flex-wrap gap-1">
            <span class="pill bg-success-subtle text-success">Severity 30</span>
            <span class="pill bg-success-subtle text-success">Quantity 20</span>
            <span class="pill bg-success-subtle text-success">Location risk 20</span>
            <span class="pill bg-success-subtle text-success">Age 15</span>
            <span class="pill bg-success-subtle text-success">Repeat reports 15</span>
          </div>
          <hr>
          <div class="d-flex flex-wrap gap-1">
            <span class="pill bg-success-subtle text-success">0-30 Low</span>
            <span class="pill bg-warning-subtle text-warning">31-60 Medium</span>
            <span class="pill bg-warning-subtle text-warning">61-80 High</span>
            <span class="pill bg-danger-subtle text-danger">81-100 Critical</span>
          </div>
        </div>
      </div>

      <div class="col-lg-7">
        <?php foreach ($steps as $i => [$icon, $title, $text]): ?>
          <div class="workflow-step">
            <div class="wnum"><?= $i + 1 ?></div>
            <div class="d-flex align-items-center gap-2">
              <i class="bi <?= e($icon) ?> text-success"></i>
              <h3 class="section-title mb-0"><?= e($title) ?></h3>
            </div>
            <p class="text-muted mb-0 mt-1" style="font-size:.88rem"><?= e($text) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

<!-- ============================================================== DEMO -->
<section id="demo" class="demo-grid">
  <div class="container">
    <div class="text-center mb-4">
      <div class="eyebrow">Try it now</div>
      <h2 class="fw-bold mt-2 mb-2" style="letter-spacing:-.02em">Demo accounts</h2>
      <p class="text-muted">Every role has a ready-made dashboard with live data.</p>
    </div>
    <div class="row g-3 justify-content-center">
      <?php
      $demos = [
        ['bi-building-gear', 'Municipal Authority', 'admin@smartwaste.gov',    'admin@123',    'Dashboard, assignments, plans, hotspots, analytics'],
        ['bi-truck',         'Collection Worker',  'worker1@smartwaste.gov',  'worker@123',   'Mission, route, photo proof, GPS tracking'],
        ['bi-person',        'Citizen',            'citizen1@smartwaste.gov', 'citizen@123', 'Report waste, track status, rate service'],
      ];
      foreach ($demos as [$icon, $role, $mail, $pass, $desc]): ?>
        <div class="col-md-4">
          <a class="card card-soft p-3 h-100 hover-lift text-decoration-none" href="<?= e(APP_URL) ?>/login.php">
            <div class="d-flex align-items-center gap-2 mb-2">
              <span class="kpi-icon"><i class="bi <?= e($icon) ?>"></i></span>
              <div>
                <div class="fw-bold"><?= e($role) ?></div>
                <div class="text-muted" style="font-size:.78rem"><?= e($desc) ?></div>
              </div>
            </div>
            <div class="small">
              <div><span class="text-muted">Email</span> &middot; <code><?= e($mail) ?></code></div>
              <div><span class="text-muted">Password</span> &middot; <code><?= e($pass) ?></code></div>
            </div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<footer class="site">
  <div class="container">
    <div class="row g-3 align-items-center">
      <div class="col-md-6">
        <div class="fw-bold text-white mb-1"><?= e(APP_NAME) ?></div>
        <div><?= e(APP_CITY) ?> Municipal Corporation &middot; Built with PHP 8, MySQL, Bootstrap 5, Leaflet &amp; Chart.js</div>
      </div>
      <div class="col-md-6 text-md-end">
        <a href="<?= e(APP_URL) ?>/login.php">Log in</a> &middot;
        <a href="<?= e(APP_URL) ?>/register.php">Register as citizen</a> &middot;
        <a href="<?= e(APP_URL) ?>/database/finalize.php">Rebuild demo data</a>
      </div>
    </div>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>window.APP_URL = <?= json_encode(APP_URL) ?>;</script>
<script src="<?= e(APP_URL) ?>/assets/js/map.js"></script>
<?php if (!empty($_SESSION['flash'])): ?>
<script>
  window.addEventListener('load', function () {
    var box = document.createElement('div');
    box.className = 'position-fixed bottom-0 end-0 p-3';
    box.innerHTML = '<div class="alert alert-success border-0 shadow d-flex align-items-center gap-2">' +
                   '<i class="bi bi-check-circle-fill"></i>' +
                   <?= json_encode(e($_SESSION['flash'])) ?> + '</div>';
    document.body.appendChild(box);
    setTimeout(function () { box.remove(); }, 6000);
  });
</script>
<?php unset($_SESSION['flash']); endif; ?>
<script>
/* Landing page map: request pins + hotspot circles (read-only preview). */
(async function () {
  const map = L.map('map').setView([19.0760, 72.8777], 12);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap contributors'
  }).addTo(map);

  try {
    const res  = await fetch(window.APP_URL + '/api/public/overview.php');
    const json = await res.json();
    if (!json.success) return;

    const bounds = [];
    (json.data.hotspots || []).forEach(function (h) {
      const color = { high: '#dc3545', medium: '#fd7e14', low: '#ffc107' }[h.risk_level] || '#6c757d';
      L.circle([h.latitude, h.longitude], {
        radius: h.radius_m || 700, color: color, weight: 2, opacity: .6,
        fillColor: color, fillOpacity: .1, dashArray: '6 5'
      }).bindPopup('<strong>' + h.area_name + '</strong><br>' + h.report_count +
        ' reports · every ' + h.avg_recurrence_days + ' days<br><span style="color:' + color +
        '"><b>' + h.risk_level.toUpperCase() + ' RISK</b></span>').addTo(map);
      L.marker([h.latitude, h.longitude], { icon: hotspotIcon(color, h.hotspot_score) }).addTo(map);
      bounds.push([h.latitude, h.longitude]);
    });

    (json.data.requests || []).forEach(function (r) {
      const c = markerColor(r);
      L.marker([r.latitude, r.longitude], { icon: pinIcon(c, '') })
        .bindPopup('<strong>#' + r.id + '</strong> ' + r.waste_label +
          '<br>Priority ' + r.priority_score + '/100 (' + r.priority_level + ')' +
          '<br>' + r.status_label + '<br>' + r.address)
        .addTo(map);
      bounds.push([r.latitude, r.longitude]);
    });

    if (bounds.length) map.fitBounds(bounds, { padding: [30, 30], maxZoom: 14 });
  } catch (e) { /* the map still renders, the data layer is optional */ }
})();
</script>
</body>
</html>
