<?php
/**
 * citizen/track.php  —  live tracking for one request
 * ?id=12            (defaults to the most recent request of the citizen)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/priority.php';
require_role('citizen');

$citizen = current_citizen() ?? [];
$cid     = (int) ($citizen['id'] ?? 0);
$id      = (int) ($_GET['id'] ?? 0);

$row = $id > 0
    ? db_row('SELECT * FROM waste_requests WHERE id = ? AND citizen_id = ?', [$id, $cid])
    : db_row('SELECT * FROM waste_requests WHERE citizen_id = ? ORDER BY created_at DESC LIMIT 1', [$cid]);

if (!$row) {
    $_SESSION['flash'] = 'Report waste first — then you can track it here.';
    redirect('/citizen/report.php');
}
$id = (int) $row['id'];

$w    = waste_meta($row['waste_type']);
$meta = status_meta($row['status']);

// Timeline straight from the real timestamps.
$STAMPS = [
    'pending'     => ['Request Submitted',  'We received your report and photo.',    'bi-send-check',     $row['created_at']],
    'prioritized' => ['Priority Analysed',  'The engine scored your report out of 100.', 'bi-graph-up-arrow', $row['created_at']],
    'assigned'    => ['Team Assigned',      'A collection team was allocated.',     'bi-person-check',    $row['assigned_at']],
    'scheduled'   => ['Added To Route',     'The stop is part of today’s optimised route.', 'bi-signpost-split', $row['scheduled_at']],
    'in_progress' => ['Collection Started', 'The team is on site and collecting.',   'bi-truck',           $row['started_at'] ?: $row['arrived_at']],
    'collected'   => ['Collected',          'Before/after proof uploaded for review.', 'bi-camera-fill',  $row['collected_at']],
    'verified'    => ['Verified Complete',  'An officer approved the proof photos.', 'bi-patch-check',    $row['verified_at']],
];
$ORDER  = array_keys($STAMPS);
$pos    = array_search($row['status'], $ORDER, true);
$pos    = $pos === false ? 0 : (int) $pos;
$steps  = [];
foreach ($ORDER as $i => $key) {
    [$title, $desc, $icon, $stamp] = $STAMPS[$key];
    $steps[] = [
        'status'  => $key,
        'title'   => $title,
        'desc'    => $desc,
        'icon'    => $icon,
        'at'      => $stamp,
        'state'   => $i < $pos ? 'done' : ($i === $pos ? 'current' : ''),
    ];
}
$nextStep = null;
if ($row['status'] !== 'cancelled' && isset($ORDER[$pos + 1])) {
    $nextStep = $STAMPS[$ORDER[$pos + 1]][0] ?? null;
}

// Worker + vehicle of the assigned team.
$crew = db_row(
    'SELECT w.worker_code, w.phone, w.latitude, w.longitude, w.status AS worker_status,
            u.full_name, t.team_name, v.vehicle_number, v.vehicle_type
       FROM workers w
       JOIN users u         ON u.id = w.user_id
       LEFT JOIN teams t    ON t.id = w.team_id
       LEFT JOIN vehicles v ON v.id = w.vehicle_id
      WHERE w.id = ?',
    [$row['assigned_worker_id']]
);

$progress = (int) round(($pos + 1) / count($ORDER) * 100);

$pageTitle    = 'Track Request #' . $id;
$pageSubtitle = $w['label'] . ' at ' . $row['address'];
$activeNav    = 'track';
$extraJs      = ['https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', APP_URL . '/assets/js/map.js'];

require __DIR__ . '/../includes/header.php';
?>

<div class="row g-3">
  <!-- ------------------------------------------------------------- status -->
  <div class="col-lg-7">
    <div class="card card-soft mb-3">
      <div class="card-body">
        <div class="d-flex flex-wrap gap-3 align-items-center justify-content-between">
          <div>
            <div class="d-flex align-items-center gap-2 mb-1">
              <span class="kpi-icon"><i class="bi bi-<?= e($w['icon']) ?>" style="color:<?= e($w['color']) ?>"></i></span>
              <div>
                <div class="fw-bold"><?= e($w['label']) ?> &middot; <?= e(quantity_options()[$row['quantity']] ?? $row['quantity']) ?></div>
                <div class="text-muted" style="font-size:.78rem"><?= e($row['request_code']) ?></div>
              </div>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
              <?= status_badge($row['status']) ?>
              <?= priority_badge($row['priority_level'], (int) $row['priority_score']) ?>
            </div>
          </div>

          <div class="text-end">
            <?php if ($nextStep): ?>
              <div class="text-muted" style="font-size:.76rem">NEXT STEP</div>
              <div class="fw-600"><?= e($nextStep) ?></div>
            <?php elseif ($row['status'] === 'verified'): ?>
              <div class="text-success fw-600"><i class="bi bi-patch-check-fill me-1"></i>All done — thank you!</div>
            <?php endif; ?>
            <?php if ($row['estimated_visit_time'] && in_array($row['status'], ['assigned','scheduled'], true)): ?>
              <div class="text-muted" style="font-size:.78rem">Expected visit <?= e(fmt_dt($row['estimated_visit_time'])) ?></div>
            <?php endif; ?>
          </div>
        </div>

        <div class="progress progress-thin mt-3">
          <div class="progress-bar bg-success" role="progressbar" style="width:<?= $progress ?>%"
               aria-valuenow="<?= $progress ?>" aria-valuemin="0" aria-valuemax="100"></div>
        </div>
        <div class="d-flex justify-content-between text-muted mt-1" style="font-size:.72rem">
          <span>Reported <?= e(time_ago($row['created_at'])) ?></span>
          <span><?= $progress ?>% through the pipeline</span>
        </div>

        <?php if ($row['is_rejected'] || $row['status'] === 'cancelled'): ?>
          <div class="alert alert-warning border-0 mt-3 mb-0" style="border-radius:12px;font-size:.86rem">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <strong>Closed:</strong> <?= e($row['reject_reason'] ?: 'This request was cancelled.') ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- timeline -->
    <div class="card card-soft mb-3">
      <div class="card-body">
        <h5 class="section-title">Progress</h5>
        <div class="timeline">
          <?php foreach ($steps as $s): ?>
            <div class="tl-item <?= e($s['state']) ?>">
              <div class="d-flex align-items-center gap-2">
                <i class="bi bi-<?= e($s['icon']) ?> <?= $s['state'] === 'current' ? 'text-warning' : ($s['state'] === 'done' ? 'text-success' : 'text-muted') ?>"></i>
                <span class="fw-600" style="font-size:.88rem"><?= e($s['title']) ?></span>
                <?php if ($s['at']): ?>
                  <span class="text-muted ms-auto" style="font-size:.74rem"><?= e(fmt_dt($s['at'])) ?></span>
                <?php endif; ?>
              </div>
              <div class="text-muted" style="font-size:.78rem"><?= e($s['desc']) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- photos -->
    <div class="card card-soft">
      <div class="card-body">
        <h5 class="section-title">Photos</h5>
        <div class="row g-2">
          <div class="col-md-4">
            <div class="before-after">
              <?php if ($row['image_path']): ?>
                <img src="<?= e(image_url($row['image_path'])) ?>" class="gallery-img" alt="Reported waste">
                <span class="ba-label">YOU REPORTED</span>
              <?php else: ?>
                <div class="gallery-img d-flex align-items-center justify-content-center text-muted">
                  <i class="bi bi-image fs-3 opacity-50"></i>
                </div>
              <?php endif; ?>
            </div>
          </div>
          <div class="col-md-4">
            <div class="before-after">
              <?php if ($row['before_image']): ?>
                <img src="<?= e(image_url($row['before_image'])) ?>" class="gallery-img" alt="Before collection">
                <span class="ba-label">BEFORE</span>
              <?php else: ?>
                <div class="gallery-img d-flex align-items-center justify-content-center text-muted">
                  <i class="bi bi-camera fs-3 opacity-50"></i>
                </div>
              <?php endif; ?>
            </div>
          </div>
          <div class="col-md-4">
            <div class="before-after">
              <?php if ($row['after_image']): ?>
                <img src="<?= e(image_url($row['after_image'])) ?>" class="gallery-img" alt="After collection">
                <span class="ba-label">AFTER</span>
              <?php else: ?>
                <div class="gallery-img d-flex align-items-center justify-content-center text-muted">
                  <i class="bi bi-camera fs-3 opacity-50"></i>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <p class="text-muted mt-2 mb-0" style="font-size:.76rem">
          Collection cannot be marked complete until both the BEFORE and AFTER photos exist.
        </p>
      </div>
    </div>
  </div>

  <!-- --------------------------------------------------------------- side -->
  <div class="col-lg-5">
    <div class="card card-soft mb-3">
      <div class="card-body">
        <h5 class="section-title">Pickup location</h5>
        <div class="map-shell" style="height:260px">
          <div id="trackMap" style="height:100%"
               data-lat="<?= e($row['latitude']) ?>" data-lng="<?= e($row['longitude']) ?>"
               data-rid="<?= $id ?>"
               data-waste="<?= e($row['waste_type']) ?>"
               data-status="<?= e($row['status']) ?>"
               data-score="<?= (int) $row['priority_score'] ?>"
               data-address="<?= e($row['address']) ?>"></div>
        </div>
        <div class="d-flex justify-content-between align-items-start mt-2">
          <div style="font-size:.86rem">
            <i class="bi bi-geo-alt text-success me-1"></i><?= e($row['address']) ?>
            <?php if ($row['landmark']): ?>
              <div class="text-muted" style="font-size:.76rem">Near <?= e($row['landmark']) ?></div>
            <?php endif; ?>
          </div>
          <a class="btn btn-sm btn-outline-success"
             target="_blank"
             href="https://www.openstreetmap.org/?mlat=<?= e($row['latitude']) ?>&mlon=<?= e($row['longitude']) ?>#map=17/<?= e($row['latitude']) ?>/<?= e($row['longitude']) ?>">
            Open map
          </a>
        </div>
      </div>
    </div>

    <?php if ($crew): ?>
      <div class="card card-soft mb-3">
        <div class="card-body">
          <h5 class="section-title">Assigned team</h5>
          <div class="d-flex align-items-center gap-3">
            <span class="kpi-icon"><i class="bi bi-person-badge"></i></span>
            <div class="flex-grow-1">
              <div class="fw-600"><?= e($crew['full_name']) ?> <span class="text-muted fw-normal">· <?= e($crew['worker_code']) ?></span></div>
              <div class="text-muted" style="font-size:.78rem">
                <?= e($crew['team_name'] ?: 'Sanitation') ?>
                <?php if ($crew['vehicle_number']): ?>
                  &middot; <?= e($crew['vehicle_number']) ?><?= $crew['vehicle_type'] ? ' (' . e($crew['vehicle_type']) . ')' : '' ?>
                <?php endif; ?>
              </div>
            </div>
            <span class="pill bg-<?= $crew['worker_status'] === 'available' ? 'success' : 'warning' ?>-subtle
                         text-<?= $crew['worker_status'] === 'available' ? 'success' : 'warning' ?>">
              <?= e(ucfirst($crew['worker_status'])) ?>
            </span>
          </div>
          <?php if ($crew['phone']): ?>
            <a class="btn btn-sm btn-outline-success w-100 mt-3" href="tel:<?= e($crew['phone']) ?>">
              <i class="bi bi-telephone me-1"></i>Call <?= e($crew['full_name']) ?>
            </a>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="card card-soft">
      <div class="card-body">
        <h5 class="section-title">Your report</h5>
        <dl class="row mb-0" style="font-size:.84rem">
          <dt class="col-5 text-muted fw-normal">Category</dt>
          <dd class="col-7"><?= e($w['label']) ?></dd>

          <dt class="col-5 text-muted fw-normal">Quantity</dt>
          <dd class="col-7 text-capitalize"><?= e(quantity_options()[$row['quantity']] ?? $row['quantity']) ?></dd>

          <dt class="col-5 text-muted fw-normal">Reported</dt>
          <dd class="col-7"><?= e(fmt_dt($row['created_at'])) ?></dd>

          <dt class="col-5 text-muted fw-normal">Collected</dt>
          <dd class="col-7"><?= e(fmt_dt($row['collected_at'])) ?></dd>

          <dt class="col-5 text-muted fw-normal">Verified</dt>
          <dd class="col-7"><?= e(fmt_dt($row['verified_at'])) ?></dd>
        </dl>

        <?php if ($row['description']): ?>
          <hr>
          <div class="text-muted" style="font-size:.82rem"><?= nl2br(e($row['description'])) ?></div>
        <?php endif; ?>

        <hr>
        <div class="d-flex gap-2">
          <a href="<?= e(APP_URL) ?>/citizen/requests.php" class="btn btn-sm btn-light border">All my requests</a>
          <a href="<?= e(APP_URL) ?>/citizen/report.php" class="btn btn-sm btn-success">
            <i class="bi bi-plus-lg me-1"></i>Report more</a>
        </div>
      </div>
    </div>
  </div>
</div>

<?php
$inlineJs = <<<'JS'
(function () {
  const el   = document.getElementById('trackMap');
  const lat  = parseFloat(el.dataset.lat);
  const lng  = parseFloat(el.dataset.lng);
  const map  = createMap('trackMap', { lat: lat, lng: lng, zoom: 16 });
  L.marker([lat, lng], { icon: pinIcon('#16a34a', '#' + el.dataset.rid) })
    .addTo(map)
    .bindPopup(requestPopup({
      id: parseInt(el.dataset.rid, 10),
      waste_type: el.dataset.waste,
      status: el.dataset.status,
      priority_score: parseInt(el.dataset.score, 10),
      address: el.dataset.address
    }))
    .openPopup();
})();
JS;

require __DIR__ . '/../includes/footer.php';
