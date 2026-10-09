<?php
/**
 * admin/dashboard.php  —  municipal command centre
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/priority.php';
require_role('admin');

$pageTitle    = 'Operations Dashboard';
$pageSubtitle = 'Live city-wide waste operations at a glance';
$activeNav    = 'overview';

$extraJs = [
    'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
    APP_URL . '/assets/js/map.js',
];

/* ------------------------------------------------- server-rendered queues -- */
$queue = db_all(
    'SELECT r.*, u.full_name AS citizen_name
       FROM waste_requests r
       LEFT JOIN users u ON u.id = r.user_id
      WHERE r.status IN ("pending","prioritized")
      ORDER BY r.priority_score DESC, r.created_at ASC
      LIMIT 6'
);

$awaitingVerify = (int) db_value('SELECT COUNT(*) FROM waste_requests WHERE status = "collected"', [], 0);
$openTotal      = (int) db_value(
    'SELECT COUNT(*) FROM waste_requests WHERE status NOT IN ("verified","cancelled")', [], 0);

$hotspots = db_all('SELECT * FROM hotspots ORDER BY hotspot_score DESC LIMIT 5');

require __DIR__ . '/../includes/header.php';
?>

<!-- ------------------------------------------------------------- KPI row -->
<div class="row g-3 mb-3">
  <div class="col-6 col-xl-3">
    <div class="kpi">
      <div class="kpi-label">Total Requests</div>
      <div class="kpi-value" data-kpi="total_requests">–</div>
      <div class="kpi-foot"><i class="bi bi-inbox me-1"></i><span data-kpi="today">–</span> today</div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="kpi">
      <div class="kpi-label">Pending Queue</div>
      <div class="kpi-value text-warning" data-kpi="pending_requests">–</div>
      <div class="kpi-foot"><i class="bi bi-hourglass-split me-1"></i>awaiting assignment</div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="kpi">
      <div class="kpi-label">High / Critical</div>
      <div class="kpi-value text-danger" data-kpi="high_priority">–</div>
      <div class="kpi-foot"><i class="bi bi-exclamation-triangle me-1"></i>urgent attention</div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="kpi">
      <div class="kpi-label">Teams Available</div>
      <div class="kpi-value text-success"><span data-kpi="active_teams">–</span><span class="fs-6 text-muted">/<span data-kpi="total_teams">–</span></span></div>
      <div class="kpi-foot"><i class="bi bi-truck me-1"></i><span data-kpi="busy_teams">–</span> on route</div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-xl-3">
    <div class="kpi">
      <div class="kpi-label">Awaiting Verification</div>
      <div class="kpi-value text-primary"><?= $awaitingVerify ?></div>
      <div class="kpi-foot"><i class="bi bi-patch-check me-1"></i>proof photos to review</div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="kpi">
      <div class="kpi-label">Completion Rate</div>
      <div class="kpi-value" data-kpi="completion_rate">–</div>
      <div class="kpi-foot"><i class="bi bi-pie-chart me-1"></i>collected or verified</div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="kpi">
      <div class="kpi-label">Avg Response</div>
      <div class="kpi-value text-info" data-kpi="avg_response_label">–</div>
      <div class="kpi-foot"><i class="bi bi-stopwatch me-1"></i>report &rarr; team assigned</div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="kpi">
      <div class="kpi-label">Avg Collection</div>
      <div class="kpi-value text-info" data-kpi="avg_collection_label">–</div>
      <div class="kpi-foot"><i class="bi bi-clock-history me-1"></i>report &rarr; collected</div>
    </div>
  </div>
</div>

<div class="row g-3">
  <!-- ------------------------------------------------- priority breakdown -->
  <div class="col-xl-4">
    <div class="card card-soft h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h5 class="section-title mb-0">Open load by priority</h5>
          <span class="pill bg-secondary-subtle text-secondary"><?= $openTotal ?> open</span>
        </div>
        <div style="height:220px"><canvas id="priorityChart"></canvas></div>
        <hr>
        <h6 class="section-title">Status mix</h6>
        <div style="height:150px"><canvas id="statusChart"></canvas></div>
      </div>
    </div>
  </div>

  <!-- ------------------------------------------------------- action queue -->
  <div class="col-xl-8">
    <div class="card card-soft h-100">
      <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
          <h5 class="section-title mb-0">Needs a team &mdash; highest priority first</h5>
          <div class="d-flex gap-2">
            <a href="<?= e(APP_URL) ?>/admin/assignments.php" class="btn btn-sm btn-success">
              <i class="bi bi-person-gear me-1"></i>Assign
            </a>
            <a href="<?= e(APP_URL) ?>/admin/requests.php" class="btn btn-sm btn-light border">All requests</a>
          </div>
        </div>

        <?php if (!$queue): ?>
          <div class="empty-state">
            <i class="bi bi-check2-circle"></i>
            <p class="mb-0 fw-600">Queue is clear — every report has a team.</p>
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table align-middle sw-table mb-0">
              <thead>
                <tr><th>#</th><th>Waste</th><th>Location</th><th>Citizen</th>
                    <th>Priority</th><th>Waiting</th><th class="text-end">Action</th></tr>
              </thead>
              <tbody>
                <?php foreach ($queue as $r):
                    $w = waste_meta($r['waste_type']); ?>
                  <tr>
                    <td class="fw-600"><?= (int) $r['id'] ?></td>
                    <td><i class="bi bi-<?= e($w['icon']) ?> me-1" style="color:<?= e($w['color']) ?>"></i><?= e($w['label']) ?></td>
                    <td style="max-width:230px">
                      <div class="text-truncate"><?= e($r['address']) ?></div>
                      <div class="text-muted text-truncate" style="font-size:.72rem"><?= e($r['landmark'] ?: '—') ?></div>
                    </td>
                    <td class="text-truncate" style="max-width:140px"><?= e($r['citizen_name'] ?? '—') ?></td>
                    <td><?= priority_badge($r['priority_level'], (int) $r['priority_score']) ?></td>
                    <td class="text-muted" style="font-size:.78rem"><?= e(time_ago($r['created_at'])) ?></td>
                    <td class="text-end">
                      <a href="<?= e(APP_URL) ?>/admin/assignments.php?request_id=<?= (int) $r['id'] ?>#pick"
                         class="btn btn-sm btn-success">Assign</a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ------------------------------------------------------------- map -->
  <div class="col-xl-7">
    <div class="card card-soft h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h5 class="section-title mb-0">City overview</h5>
          <a href="<?= e(APP_URL) ?>/admin/map.php" class="btn btn-sm btn-light border">
            <i class="bi bi-fullscreen me-1"></i>Open live map
          </a>
        </div>
        <div class="map-shell" style="height:330px">
          <div id="overviewMap" style="height:100%"></div>
          <div class="legend-box">
            <div class="fw-bold mb-1"><i class="bi bi-map me-1"></i>Legend</div>
            <div class="d-flex align-items-center gap-2 mb-1">
              <span class="swatch" style="background:#dc3545"></span><span>Critical / High</span>
            </div>
            <div class="d-flex align-items-center gap-2 mb-1">
              <span class="swatch" style="background:#198754"></span><span>Collected / Verified</span>
            </div>
            <div class="d-flex align-items-center gap-2">
              <span class="swatch" style="background:#fd7e14"></span><span>Predicted hotspot</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ---------------------------------------------------------- hotspots -->
  <div class="col-xl-5">
    <div class="card card-soft h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h5 class="section-title mb-0">Predicted hotspots</h5>
          <a href="<?= e(APP_URL) ?>/admin/hotspots.php" class="btn btn-sm btn-light border">Details</a>
        </div>

        <?php if (!$hotspots): ?>
          <p class="text-muted mb-0" style="font-size:.86rem">
            No hotspot pattern detected yet. Recompute after a few days of reports.
          </p>
        <?php else: foreach ($hotspots as $h):
            $risk = ['high' => 'danger', 'medium' => 'warning', 'low' => 'success'][$h['risk_level']] ?? 'secondary'; ?>
          <div class="d-flex align-items-start gap-2 py-2 border-bottom">
            <span class="kpi-icon bg-<?= $risk ?>-subtle"><i class="bi bi-fire text-<?= $risk ?>"></i></span>
            <div class="flex-grow-1 min-w-0">
              <div class="fw-600 text-truncate" style="font-size:.88rem"><?= e($h['area_name']) ?></div>
              <div class="text-muted" style="font-size:.74rem">
                <?= (int) $h['report_count'] ?> reports &middot; every <?= round((float) $h['avg_recurrence_days'], 1) ?> days
              </div>
            </div>
            <span class="pill bg-<?= $risk ?>-subtle text-<?= $risk ?>"><?= e(ucfirst($h['risk_level'])) ?></span>
            <span class="fw-600" style="font-size:.86rem"><?= (int) $h['hotspot_score'] ?></span>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
</div>

<?php
$inlineJs = <<<'JS'
(async function () {
  /* ------------------------------------------------------------- KPIs --- */
  const stats = await apiGet('admin/stats.php');
  if (stats) {
    Object.entries(stats.data.kpi).forEach(([k, v]) => {
      document.querySelectorAll('[data-kpi="' + k + '"]').forEach(el => { el.textContent = v; });
    });
    document.querySelectorAll('[data-kpi="completion_rate"]').forEach(el => {
      el.textContent = stats.data.kpi.completion_rate + '%';
    });

    /* --------------------------------------------------------- charts --- */
    const pMap = { critical: 0, high: 0, medium: 0, low: 0 };
    stats.data.priority_mix.forEach(r => { pMap[r.priority_level] = parseInt(r.c, 10); });

    new Chart(document.getElementById('priorityChart'), {
      type: 'doughnut',
      data: {
        labels: ['Critical', 'High', 'Medium', 'Low'],
        datasets: [{
          data: [pMap.critical, pMap.high, pMap.medium, pMap.low],
          backgroundColor: ['#dc3545', '#fd7e14', '#ffc107', '#198754'],
          borderWidth: 0
        }]
      },
      options: {
        cutout: '62%', responsive: true, maintainAspectRatio: false,
        plugins: {
          legend: { position: 'right', labels: { boxWidth: 12, font: { size: 11 } } },
          tooltip: { callbacks: { label: c => ' ' + c.label + ': ' + c.raw + ' request(s)' } }
        }
      }
    });

    const sColors = {
      pending: '#ffc107', prioritized: '#fd7e14', assigned: '#0d6efd', scheduled: '#6f42c1',
      in_progress: '#0dcaf0', collected: '#198754', verified: '#0f5132', cancelled: '#6c757d'
    };
    const sLabels = [], sData = [], sCols = [];
    stats.data.status_mix.forEach(r => {
      sLabels.push(r.status.replace(/_/g, ' ').replace(/\b\w/g, m => m.toUpperCase()));
      sData.push(parseInt(r.c, 10));
      sCols.push(sColors[r.status] || '#6c757d');
    });

    new Chart(document.getElementById('statusChart'), {
      type: 'bar',
      data: { labels: sLabels, datasets: [{ data: sData, backgroundColor: sCols, borderRadius: 6 }] },
      options: {
        indexAxis: 'y', responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { x: { grid: { display: false } }, y: { grid: { display: false } } }
      }
    });
  }

  /* -------------------------------------------------------------- map --- */
  const map = createMap('overviewMap', { zoom: 12 });
  const data = await apiGet('admin/map-data.php');
  if (data) {
    drawRequests(map, data.data.requests, { linkPrefix: window.APP_URL + '/admin/request.php?id=' });
    drawHotspots(map, data.data.hotspots);
    drawWorkers(map, data.data.workers);
    (data.data.routes || []).forEach((r, i) => {
      drawRoute(map, [{ latitude: data.data.workers.find(w => w.id === r.worker_id)?.latitude,
                        longitude: data.data.workers.find(w => w.id === r.worker_id)?.longitude }]
        .concat(r.points).filter(p => p.latitude != null), ['#16a34a', '#0d6efd', '#6f42c1', '#fd7e14'][i % 4]);
    });
  }
})();
JS;

require __DIR__ . '/../includes/footer.php';
