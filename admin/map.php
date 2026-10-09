<?php
/**
 * admin/map.php  —  live city map: requests, crews, hotspots, active routes
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$filterStatus = clean_str($_GET['status'] ?? 'open', 20);
$showHotspots = ($_GET['hotspots'] ?? '1') !== '0';
$showWorkers  = ($_GET['workers'] ?? '1') !== '0';

$pageTitle    = 'Live Operations Map';
$pageSubtitle = 'Requests, crews, predicted hotspots and active routes';
$activeNav    = 'map';
$extraJs      = ['https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', APP_URL . '/assets/js/map.js'];

require __DIR__ . '/../includes/header.php';
?>

<div class="card card-soft">
  <div class="card-body p-2">
    <div class="d-flex flex-wrap gap-2 align-items-center px-2 py-2">
      <div class="btn-group btn-group-sm" role="group" aria-label="Status filter">
        <?php foreach ([
            'open'      => 'Open only',
            'pending'   => 'Pending',
            'assigned'  => 'Assigned',
            'in_progress' => 'In progress',
            'collected' => 'Collected',
            'verified'  => 'Verified',
            ''          => 'Everything',
        ] as $key => $label): ?>
          <a class="btn btn-<?= $filterStatus === $key ? 'success' : 'light border' ?>"
             href="<?= e(APP_URL . '/admin/map.php?status=' . urlencode($key)
                        . '&hotspots=' . ($showHotspots ? '1' : '0')
                        . '&workers=' . ($showWorkers ? '1' : '0')) ?>">
            <?= e($label) ?>
          </a>
        <?php endforeach; ?>
      </div>

      <div class="form-check form-switch ms-2">
        <input class="form-check-input" type="checkbox" role="switch" id="swHotspots" <?= $showHotspots ? 'checked' : '' ?>>
        <label class="form-check-label" for="swHotspots" style="font-size:.84rem">Hotspots</label>
      </div>
      <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" role="switch" id="swWorkers" <?= $showWorkers ? 'checked' : '' ?>>
        <label class="form-check-label" for="swWorkers" style="font-size:.84rem">Crews</label>
      </div>

      <div class="ms-auto d-flex align-items-center gap-2">
        <span class="text-muted" style="font-size:.78rem" id="mapCount">Loading…</span>
        <button class="btn btn-sm btn-light border" id="btnRefresh">
          <i class="bi bi-arrow-clockwise me-1"></i>Refresh
        </button>
      </div>
    </div>

    <div class="map-shell" style="height:calc(100vh - 250px);min-height:480px">
      <div id="liveMap" style="height:100%" data-status="<?= e($filterStatus) ?>"
           data-hotspots="<?= $showHotspots ? '1' : '0' ?>" data-workers="<?= $showWorkers ? '1' : '0' ?>"></div>

      <div class="legend-box">
        <div class="fw-bold mb-1"><i class="bi bi-map me-1"></i>Legend</div>
        <div class="d-flex align-items-center gap-2 mb-1"><span class="swatch" style="background:#dc3545"></span><span>Critical (81+)</span></div>
        <div class="d-flex align-items-center gap-2 mb-1"><span class="swatch" style="background:#fd7e14"></span><span>High (61–80)</span></div>
        <div class="d-flex align-items-center gap-2 mb-1"><span class="swatch" style="background:#ffc107"></span><span>Medium (31–60)</span></div>
        <div class="d-flex align-items-center gap-2 mb-1"><span class="swatch" style="background:#198754"></span><span>Low / completed</span></div>
        <div class="d-flex align-items-center gap-2 mb-1"><span class="swatch" style="background:#0d6efd"></span><span>Collection crew</span></div>
        <div class="d-flex align-items-center gap-2"><span class="swatch" style="background:#6f42c1"></span><span>Predicted hotspot</span></div>
      </div>
    </div>
  </div>
</div>

<?php
$inlineJs = <<<'JS'
(function () {
  const host  = document.getElementById('liveMap');
  const map   = createMap('liveMap', { zoom: 12 });
  const state = {
    status:   host.dataset.status,
    hotspots: host.dataset.hotspots === '1',
    workers:  host.dataset.workers === '1',
    all: null
  };
  let first = true;

  const COLORS = ['#16a34a', '#0d6efd', '#6f42c1', '#fd7e14', '#0dcaf0'];

  async function load(fit) {
    const res = await apiGet('admin/map-data.php');
    if (!res) return;
    state.all = res.data;

    let requests = res.data.requests;
    if (state.status === 'open') {
      requests = requests.filter(r => ['collected', 'verified'].indexOf(r.status) === -1);
    } else if (state.status) {
      requests = requests.filter(r => r.status === state.status);
    }

    drawRequests(map, requests, {
      fit: !!fit && first,
      linkPrefix: window.APP_URL + '/admin/request.php?id='
    });
    if (state.workers) drawWorkers(map, res.data.workers);
    if (state.hotspots) drawHotspots(map, res.data.hotspots);

    map.swRoutes.clearLayers();
    (res.data.routes || []).forEach(function (route, i) {
      const worker = (res.data.workers || []).find(w => w.id === route.worker_id);
      const pts = [];
      if (worker && worker.latitude != null) pts.push({ latitude: worker.latitude, longitude: worker.longitude });
      drawRoute(map, pts.concat(route.points), COLORS[i % COLORS.length]);
    });

    if (first && requests.length) {
      first = false;
    }

    const crews = (res.data.workers || []).filter(w => state.workers).length;
    const spots = (res.data.hotspots || []).length;
    document.getElementById('mapCount').textContent =
      requests.length + ' request(s) · ' + crews + ' crew(s) · ' + spots + ' hotspot(s) · ' +
      (res.data.routes || []).length + ' active route(s)';
  }

  document.getElementById('btnRefresh').addEventListener('click', function () { load(true); });
  document.getElementById('swHotspots').addEventListener('change', function (e) {
    state.hotspots = e.target.checked; load(false);
  });
  document.getElementById('swWorkers').addEventListener('change', function (e) {
    state.workers = e.target.checked; load(false);
  });

  load(true);
  setInterval(function () { load(false); }, 45000);
})();
JS;

require __DIR__ . '/../includes/footer.php';
