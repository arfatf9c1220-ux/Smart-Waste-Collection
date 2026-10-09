<?php
/**
 * admin/hotspots.php  —  predictive hotspots from the rule-based engine
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/hotspots.php';
require_role('admin');

$risk = clean_str($_GET['risk'] ?? '', 10);

$hotspots = get_hotspots($risk !== '' ? $risk : null);

$counts = ['high' => 0, 'medium' => 0, 'low' => 0];
foreach ($hotspots as $h) {
    $counts[$h['risk_level']] = ($counts[$h['risk_level']] ?? 0) + 1;
}

// Frequent places straight from the request history (context for the engine).
$topPlaces = db_all(
    'SELECT COALESCE(NULLIF(landmark, ""), address) AS place,
            COUNT(*) AS reports,
            SUM(status IN ("verified","collected")) AS resolved,
            SUM(status IN ("pending","prioritized","assigned","scheduled","in_progress")) AS still_open,
            AVG(priority_score) AS avg_score,
            MAX(created_at) AS last_seen,
            MIN(created_at) AS first_seen
       FROM waste_requests
      GROUP BY place
      HAVING reports >= 2
      ORDER BY reports DESC, avg_score DESC
      LIMIT 10'
);

$pageTitle    = 'Predicted Hotspots';
$pageSubtitle = 'Areas where waste keeps coming back — rule based prediction';
$activeNav    = 'hotspots';
$extraJs      = ['https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', APP_URL . '/assets/js/map.js'];

require __DIR__ . '/../includes/header.php';
?>

<div class="row g-3 mb-3">
  <div class="col-6 col-xl-3">
    <div class="kpi">
      <div class="kpi-label">High Risk Areas</div>
      <div class="kpi-value text-danger"><?= $counts['high'] ?></div>
      <div class="kpi-foot"><i class="bi bi-fire me-1"></i>act this week</div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="kpi">
      <div class="kpi-label">Medium Risk</div>
      <div class="kpi-value text-warning"><?= $counts['medium'] ?></div>
      <div class="kpi-foot"><i class="bi bi-exclamation-triangle me-1"></i>schedule extra pickups</div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="kpi">
      <div class="kpi-label">Low Risk</div>
      <div class="kpi-value text-success"><?= $counts['low'] ?></div>
      <div class="kpi-foot"><i class="bi bi-check-circle me-1"></i>under control</div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="kpi">
      <div class="kpi-label">Analysis Window</div>
      <div class="kpi-value"><?= (int) HOTSPOT_WINDOW_DAYS ?>d</div>
      <div class="kpi-foot"><i class="bi bi-calendar-range me-1"></i><?= (int) HOTSPOT_RADIUS_M ?> m radius</div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-xl-7">
    <div class="card card-soft mb-3">
      <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
          <h5 class="section-title mb-0">Hotspot map</h5>
          <div class="btn-group btn-group-sm">
            <?php foreach (['' => 'All', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'] as $key => $label): ?>
              <a class="btn btn-<?= $risk === $key ? 'success' : 'light border' ?>"
                 href="<?= e(APP_URL . '/admin/hotspots.php' . ($key ? '?risk=' . $key : '')) ?>"><?= $label ?></a>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="map-shell" style="height:380px">
          <div id="hotspotMap" style="height:100%" data-risk="<?= e($risk) ?>"></div>
        </div>
      </div>
    </div>

    <div class="card card-soft">
      <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
          <h5 class="section-title mb-0">Most reported places</h5>
          <button class="btn btn-sm btn-success" id="btnRecompute">
            <i class="bi bi-cpu me-1"></i>Recompute prediction
          </button>
        </div>

        <div class="table-responsive">
          <table class="table align-middle sw-table mb-0">
            <thead>
              <tr><th>Place</th><th>Reports</th><th>Still open</th><th>Resolved</th><th>Avg score</th><th>Last seen</th></tr>
            </thead>
            <tbody>
              <?php if (!$topPlaces): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">Not enough history yet.</td></tr>
              <?php else: foreach ($topPlaces as $p): ?>
                <tr>
                  <td class="fw-600" style="font-size:.86rem"><?= e($p['place']) ?></td>
                  <td><span class="pill bg-warning-subtle text-warning"><?= (int) $p['reports'] ?></span></td>
                  <td><?= (int) $p['still_open'] ?></td>
                  <td class="text-success"><?= (int) $p['resolved'] ?></td>
                  <td><?= round((float) $p['avg_score'], 1) ?> / 100</td>
                  <td class="text-muted" style="font-size:.78rem"><?= e(time_ago($p['last_seen'])) ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="col-xl-5">
    <div class="card card-soft">
      <div class="card-body">
        <h5 class="section-title">Detected areas (<?= count($hotspots) ?>)</h5>

        <?php if (!$hotspots): ?>
          <div class="empty-state">
            <i class="bi bi-emoji-smile"></i>
            <p class="mb-1 fw-600">No hotspot pattern detected</p>
            <p class="text-muted mb-0" style="font-size:.86rem">
              The engine needs repeated reports in one area before it flags a risk zone.
            </p>
          </div>
        <?php else: foreach ($hotspots as $h):
            $col = ['high' => 'danger', 'medium' => 'warning', 'low' => 'success'][$h['risk_level']] ?? 'secondary'; ?>
          <div class="py-2 border-bottom">
            <div class="d-flex align-items-center gap-2">
              <span class="kpi-icon bg-<?= $col ?>-subtle"><i class="bi bi-fire text-<?= $col ?>"></i></span>
              <div class="flex-grow-1 min-w-0">
                <div class="fw-600 text-truncate" style="font-size:.88rem"><?= e($h['area_name']) ?></div>
                <div class="text-muted" style="font-size:.74rem">
                  <?= e(waste_meta((string) $h['dominant_waste_type'])['label']) ?> ·
                  <?= (int) $h['report_count'] ?> previous reports ·
                  every <?= round((float) $h['avg_recurrence_days'], 1) ?> days
                </div>
              </div>
              <div class="text-end">
                <div class="fw-bold text-<?= $col ?>"><?= (int) $h['hotspot_score'] ?></div>
                <span class="pill bg-<?= $col ?>-subtle text-<?= $col ?>"><?= e(ucfirst($h['risk_level'])) ?></span>
              </div>
            </div>

            <div class="progress progress-thin mt-2">
              <div class="progress-bar bg-<?= $col ?>" style="width:<?= min(100, (int) $h['hotspot_score']) ?>%"></div>
            </div>

            <div class="d-flex flex-wrap gap-3 text-muted mt-1" style="font-size:.74rem">
              <span><i class="bi bi-hourglass-split me-1"></i><?= (int) ($h['open_count'] ?? 0) ?> still open</span>
              <span><i class="bi bi-graph-up me-1"></i><?= e($h['trend']) ?> trend</span>
              <span><i class="bi bi-clock me-1"></i>last <?= e(time_ago($h['last_report_at'])) ?></span>
              <a class="ms-auto" target="_blank"
                 href="https://www.openstreetmap.org/?mlat=<?= e($h['latitude']) ?>&mlon=<?= e($h['longitude']) ?>#map=16/<?= e($h['latitude']) ?>/<?= e($h['longitude']) ?>">
                Open map
              </a>
            </div>

            <div class="alert alert-light border mt-2 mb-0" style="border-radius:10px;font-size:.78rem">
              <i class="bi bi-lightbulb me-1 text-warning"></i>
              <?php if ($h['risk_level'] === 'high'): ?>
                Add this area to the next plan as a priority stop and consider a permanent bin.
              <?php else: ?>
                Keep the existing pickup cadence and re-check after the next few reports.
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
</div>

<?php
$inlineJs = <<<'JS'
(function () {
  const map = createMap('hotspotMap', { zoom: 12 });
  const risk = document.getElementById('hotspotMap').dataset.risk;

  apiGet('hotspots/list.php', risk ? { risk: risk } : {}).then(function (res) {
    if (!res) return;
    const all = res.data.hotspots;

    /* Real reports as context, so an admin sees why the area is risky. */
    apiGet('requests/list.php', { limit: 200 }).then(function (rq) {
      if (rq) drawRequests(map, rq.data.requests, { fit: false, labels: false });
      drawHotspots(map, all);
      if (all.length) {
        map.fitBounds(all.map(h => [parseFloat(h.latitude), parseFloat(h.longitude)]), { padding: [50, 50] });
      }
    });
  });

  document.getElementById('btnRecompute').addEventListener('click', async function () {
    this.disabled = true;
    const res = await apiPost('hotspots/recompute.php', {});
    if (res) setTimeout(() => window.location.reload(), 1000);
    else this.disabled = false;
  });
})();
JS;

require __DIR__ . '/../includes/footer.php';
