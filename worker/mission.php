<?php
/**
 * worker/mission.php  —  the optimised route for today, stop by stop
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/priority.php';
require_role('worker');

$worker = current_worker();
if (!$worker) {
    $_SESSION['flash'] = 'No worker profile is linked to this account yet.';
    redirect('/logout.php');
}

$pageTitle    = 'My Route';
$pageSubtitle = 'Stops in optimised travel order';
$activeNav    = 'route';
$extraJs      = ['https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', APP_URL . '/assets/js/map.js'];

require __DIR__ . '/../includes/header.php';
?>

<div class="row g-3">
  <div class="col-xl-5">
    <div class="card card-soft" id="missionCard">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h5 class="section-title mb-0" id="missionTitle">Loading mission…</h5>
          <button class="btn btn-sm btn-light border" id="btnReload">
            <i class="bi bi-arrow-clockwise"></i>
          </button>
        </div>
        <div id="missionBody">
          <div class="text-center py-4"><div class="spinner-border spinner-border-sm text-success"></div></div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-xl-7">
    <div class="card card-soft">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h5 class="section-title mb-0">Route map</h5>
          <span class="text-muted" style="font-size:.78rem">green = your live position</span>
        </div>
        <div class="map-shell" style="height:calc(100vh - 230px);min-height:420px">
          <div id="routeMap" style="height:100%"></div>
          <div class="legend-box">
            <div class="fw-bold mb-1"><i class="bi bi-signpost-split me-1"></i>Legend</div>
            <div class="d-flex align-items-center gap-2 mb-1">
              <span class="swatch" style="background:#0d6efd"></span><span>Route line</span>
            </div>
            <div class="d-flex align-items-center gap-2 mb-1">
              <span class="swatch" style="background:#ffc107"></span><span>Next stop</span>
            </div>
            <div class="d-flex align-items-center gap-2 mb-1">
              <span class="swatch" style="background:#198754"></span><span>Completed</span>
            </div>
            <div class="d-flex align-items-center gap-2">
              <span class="swatch" style="background:#16a34a"></span><span>Your position</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php
$inlineJs = <<<'JS'
(function () {
  const map = createMap('routeMap', { zoom: 12 });
  let meMarker = null;

  function me() {
    return new Promise(function (resolve) {
      if (!navigator.geolocation) return resolve(null);
      navigator.geolocation.getCurrentPosition(function (p) {
        resolve({ lat: p.coords.latitude, lng: p.coords.longitude });
      }, function () { resolve(null); }, { enableHighAccuracy: true, timeout: 8000 });
    });
  }

  async function load() {
    const res = await apiGet('collection/mission.php');
    const body = document.getElementById('missionBody');
    if (!res) { renderInto(body, '<p class="text-muted">Could not load your mission.</p>'); return; }

    const d = res.data, stops = d.stops || [], m = d.mission;
    document.getElementById('missionTitle').innerHTML =
      (d.plan ? 'Plan ' + escapeHtml(d.plan.plan_code) : 'Loose pickups') +
      (d.plan ? ' <span class="pill bg-primary-subtle text-primary ms-1">' +
        escapeHtml(d.plan.status) + '</span>' : '');

    if (!stops.length) {
      renderInto(body,
        '<div class="empty-state"><i class="bi bi-cup-hot"></i>' +
        '<p class="mb-1 fw-600">No work assigned yet</p>' +
        '<p class="text-muted mb-0" style="font-size:.86rem">Stay online and the control room will assign you a route.</p></div>');
      return;
    }

    renderInto(body,
      '<div class="row text-center g-2 mb-3">' +
        '<div class="col-4"><div class="kpi-value">' + m.done_stops + '/' + m.total_stops + '</div>' +
          '<div class="text-muted" style="font-size:.72rem">stops done</div></div>' +
        '<div class="col-4"><div class="kpi-value text-success">' + m.distance_km + '</div>' +
          '<div class="text-muted" style="font-size:.72rem">km planned</div></div>' +
        '<div class="col-4"><div class="kpi-value text-info">' + fmtDuration(m.duration_min) + '</div>' +
          '<div class="text-muted" style="font-size:.72rem">estimated</div></div>' +
      '</div>' +
      '<div class="progress progress-thin mb-3"><div class="progress-bar bg-success" style="width:' + m.progress + '%"></div></div>' +
      stops.map(function (s) {
        const done = ['collected', 'verified'].indexOf(s.status) !== -1;
        const next = !done && d.next && s.request_id === d.next.request_id;
        return '<div class="stop-row ' + (done ? 'done' : (next ? 'next' : '')) + '" data-lat="' + s.latitude +
               '" data-lng="' + s.longitude + '" data-stop="' + s.stop_number + '">' +
          '<span class="stop-num">' + (done ? '<i class="bi bi-check"></i>' : s.stop_number) + '</span>' +
          '<div class="flex-grow-1 min-w-0">' +
            '<div class="fw-600" style="font-size:.88rem">' + escapeHtml(s.address) + '</div>' +
            '<div class="text-muted" style="font-size:.76rem">' +
              escapeHtml(s.waste_label) + ' · ' + escapeHtml(s.quantity) +
              (s.landmark ? ' · near ' + escapeHtml(s.landmark) : '') +
              (s.leg_km ? ' · ' + s.leg_km + ' km leg' : '') +
              (s.eta ? ' · ETA ' + escapeHtml(String(s.eta).slice(11, 16)) : '') +
            '</div>' +
            '<div class="mt-1 d-flex gap-2 align-items-center">' +
              priorityBadge(s.priority_level, s.priority_score) +
              (s.in_plan ? '<span class="pill bg-secondary-subtle text-secondary">route stop</span>' : '') +
            '</div>' +
          '</div>' +
          '<div class="text-end">' +
            '<a class="btn btn-sm ' + (done ? 'btn-light border' : 'btn-success') + '" href="' +
              window.APP_URL + '/worker/requests.php?focus=' + s.request_id + '">' +
              (done ? 'View' : 'Work') + '</a>' +
            '<a class="btn btn-sm btn-light border mt-1" target="_blank" href="' +
              'https://www.google.com/maps/dir/?api=1&destination=' + s.latitude + ',' + s.longitude + '">' +
              '<i class="bi bi-sign-turn-right"></i></a>' +
          '</div>' +
        '</div>';
      }).join(''));

    /* ------------------------------------------------------------- map --- */
    const pts = Array.from(document.querySelectorAll('#missionBody .stop-row'))
      .map(function (r) {
        return { lat: r.dataset.lat, lng: r.dataset.lng, stop: r.dataset.stop,
                 done: r.classList.contains('done'), next: r.classList.contains('next') };
      })
      .filter(p => p.lat);

    map.swMarkers.clearLayers();
    map.swRoutes.clearLayers();
    if (pts.length > 1) {
      L.polyline(pts.map(p => [parseFloat(p.lat), parseFloat(p.lng)]),
        { color: '#0d6efd', weight: 4, opacity: .8, dashArray: '10 6' }).addTo(map.swRoutes);
    }
    pts.forEach(function (p) {
      L.marker([parseFloat(p.lat), parseFloat(p.lng)], {
        icon: pinIcon(p.done ? '#198754' : (p.next ? '#ffc107' : '#0d6efd'), p.stop)
      }).bindPopup('<strong>Stop ' + p.stop + '</strong>').addTo(map.swMarkers);
    });

    const here = await me();
    if (here) {
      if (meMarker) meMarker.setLatLng([here.lat, here.lng]);
      else meMarker = L.marker([here.lat, here.lng], { icon: workerIcon('#16a34a', '<i class="bi bi-truck"></i>') }).addTo(map);
      if (pts.length) {
        map.fitBounds(pts.map(p => [parseFloat(p.lat), parseFloat(p.lng)]).concat([[here.lat, here.lng]]),
          { padding: [45, 45] });
      } else map.setView([here.lat, here.lng], 13);
    } else if (pts.length) {
      map.fitBounds(pts.map(p => [parseFloat(p.lat), parseFloat(p.lng)]), { padding: [45, 45] });
    }
  }

  document.getElementById('btnReload').addEventListener('click', load);
  load();
  setInterval(load, 90000);
})();
JS;

require __DIR__ . '/../includes/footer.php';
