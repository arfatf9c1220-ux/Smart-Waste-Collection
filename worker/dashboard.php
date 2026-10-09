<?php
/**
 * worker/dashboard.php  —  "today's mission" home for a collection crew
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/priority.php';
require_role('worker');

$worker = current_worker();
if (!$worker) {
    $_SESSION['flash'] = 'No worker profile is linked to this account yet.';
    redirect('/logout.php');
}
$wid = (int) $worker['id'];

$team = db_row(
    'SELECT t.team_name, t.waste_types AS team_waste_types
       FROM workers w LEFT JOIN teams t ON t.id = w.team_id WHERE w.id = ?',
    [$wid]
);
$vehicle = db_row('SELECT * FROM vehicles WHERE id = ?', [$worker['vehicle_id']]);

$plan = db_row(
    'SELECT p.*, t.team_name, v.vehicle_number
       FROM collection_plans p
       LEFT JOIN teams t    ON t.id = p.team_id
       LEFT JOIN vehicles v ON v.id = p.vehicle_id
      WHERE p.worker_id = ? AND p.status IN ("active","assigned","draft")
      ORDER BY FIELD(p.status,"active","assigned","draft"), p.plan_date DESC, p.id DESC
      LIMIT 1',
    [$wid]
);

$mission = db_all(
    'SELECT r.*, p.plan_code, p.status AS plan_status
       FROM waste_requests r
       LEFT JOIN collection_plans p ON p.id = r.collection_plan_id
      WHERE r.assigned_worker_id = ?
        AND r.status IN ("assigned","scheduled","in_progress","collected")
      ORDER BY r.priority_score DESC',
    [$wid]
);

$next = null;
foreach ($mission as $r) {
    if (in_array($r['status'], ['assigned', 'scheduled', 'in_progress'], true)) {
        $next = $r;
        break;
    }
}

$collectedToday = (int) db_value(
    'SELECT COUNT(*) FROM waste_requests
      WHERE assigned_worker_id = ? AND DATE(collected_at) = CURDATE()',
    [$wid], 0);
$openCount = count(array_filter($mission, fn($r) => in_array($r['status'], ['assigned','scheduled','in_progress'], true)));
$toVerify  = count(array_filter($mission, fn($r) => $r['status'] === 'collected'));
$highCount = count(array_filter($mission, fn($r) => (int) $r['priority_score'] >= 61));

$statusColor = ['available' => 'success', 'busy' => 'warning', 'offline' => 'secondary'];

$pageTitle    = "Today's Mission";
$pageSubtitle = $team['team_name'] ?: 'Collection crew' . ($vehicle ? ' · ' . $vehicle['vehicle_number'] : '');
$activeNav    = 'mission';
$extraJs      = ['https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'];

require __DIR__ . '/../includes/header.php';
?>

<div class="row g-3 mb-3">
  <div class="col-6 col-xl-3">
    <div class="kpi">
      <div class="kpi-label">Open Pickups</div>
      <div class="kpi-value text-primary"><?= $openCount ?></div>
      <div class="kpi-foot"><i class="bi bi-list-check me-1"></i>assigned to you</div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="kpi">
      <div class="kpi-label">High Priority</div>
      <div class="kpi-value text-danger"><?= $highCount ?></div>
      <div class="kpi-foot"><i class="bi bi-exclamation-triangle me-1"></i>score 61 or more</div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="kpi">
      <div class="kpi-label">Collected Today</div>
      <div class="kpi-value text-success"><?= $collectedToday ?></div>
      <div class="kpi-foot"><i class="bi bi-check2-circle me-1"></i><?= (int) $worker['total_collected'] ?> all time</div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="kpi">
      <div class="kpi-label">Proof To Upload</div>
      <div class="kpi-value text-warning"><?= $toVerify ?></div>
      <div class="kpi-foot"><i class="bi bi-camera me-1"></i>before / after photos</div>
    </div>
  </div>
</div>

<div class="row g-3">
  <!-- ------------------------------------------------------- next stop -->
  <div class="col-xl-5">
    <div class="card card-soft mb-3">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h5 class="section-title mb-0">Next pickup</h5>
          <a href="<?= e(APP_URL) ?>/worker/mission.php" class="btn btn-sm btn-light border">
            <i class="bi bi-map me-1"></i>Route
          </a>
        </div>

        <?php if (!$next):
            $w = waste_meta('general'); ?>
          <div class="empty-state">
            <i class="bi bi-cup-hot"></i>
            <p class="mb-1 fw-600">Nothing assigned right now</p>
            <p class="text-muted mb-0" style="font-size:.86rem">
              Stay online — the control room will push work to you automatically.
            </p>
          </div>
        <?php else:
            $w = waste_meta($next['waste_type']); ?>
          <div class="d-flex align-items-start gap-2 mb-2">
            <span class="kpi-icon"><i class="bi bi-<?= e($w['icon']) ?>" style="color:<?= e($w['color']) ?>"></i></span>
            <div class="flex-grow-1 min-w-0">
              <div class="fw-bold">#<?= (int) $next['id'] ?> · <?= e($w['label']) ?></div>
              <div class="text-muted" style="font-size:.8rem">
                <?= e($next['address']) ?><?= $next['landmark'] ? ' · near ' . e($next['landmark']) : '' ?>
              </div>
            </div>
            <?= priority_badge($next['priority_level'], (int) $next['priority_score']) ?>
          </div>

          <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
            <?= status_badge($next['status']) ?>
            <span class="pill bg-secondary-subtle text-secondary">
              <?= e(quantity_options()[$next['quantity']] ?? $next['quantity']) ?>
            </span>
            <?php if ($next['estimated_visit_time']): ?>
              <span class="pill bg-info-subtle text-info">
                <i class="bi bi-clock me-1"></i><?= e(fmt_dt($next['estimated_visit_time'])) ?>
              </span>
            <?php endif; ?>
            <?php if ($next['plan_code']): ?>
              <span class="pill bg-primary-subtle text-primary"><?= e($next['plan_code']) ?></span>
            <?php endif; ?>
          </div>

          <div class="d-flex gap-2">
            <a class="btn btn-success flex-grow-1" href="<?= e(APP_URL) ?>/worker/requests.php?focus=<?= (int) $next['id'] ?>">
              <i class="bi bi-play-circle me-1"></i>Start this pickup
            </a>
            <a class="btn btn-light border" target="_blank"
               href="https://www.google.com/maps/dir/?api=1&destination=<?= e($next['latitude']) ?>,<?= e($next['longitude']) ?>">
              <i class="bi bi-sign-turn-right"></i>
            </a>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card card-soft">
      <div class="card-body">
        <h5 class="section-title">My status</h5>
        <div class="d-flex flex-wrap gap-2 mb-3">
          <?php foreach (['available' => 'Available', 'busy' => 'On a job', 'offline' => 'Offline'] as $key => $label): ?>
            <button class="btn btn-sm <?= $worker['status'] === $key ? 'btn-success' : 'btn-light border' ?> js-status"
                    data-status="<?= $key ?>"><?= e($label) ?></button>
          <?php endforeach; ?>
        </div>
        <dl class="row mb-0" style="font-size:.84rem">
          <dt class="col-5 text-muted fw-normal">Worker code</dt>
          <dd class="col-7"><?= e($worker['worker_code']) ?></dd>
          <dt class="col-5 text-muted fw-normal">Team</dt>
          <dd class="col-7"><?= e($team['team_name'] ?: '—') ?></dd>
          <dt class="col-5 text-muted fw-normal">Vehicle</dt>
          <dd class="col-7"><?= e($vehicle['vehicle_number'] ?? '—') ?>
            <?= $vehicle ? '(' . (int) $vehicle['capacity_kg'] . ' kg)' : '' ?></dd>
          <dt class="col-5 text-muted fw-normal">Current load</dt>
          <dd class="col-7"><?= (int) $worker['current_load'] ?>/<?= (int) $worker['max_load'] ?></dd>
          <dt class="col-5 text-muted fw-normal">Rating</dt>
          <dd class="col-7">
            <?php if ((float) $worker['rating'] > 0): ?>
              <span class="text-warning"><i class="bi bi-star-fill"></i> <?= round((float) $worker['rating'], 1) ?></span>
            <?php else: ?><span class="text-muted">not rated yet</span><?php endif; ?>
          </dd>
        </dl>
      </div>
    </div>
  </div>

  <!-- ------------------------------------------------------ mission list -->
  <div class="col-xl-7">
    <div class="card card-soft mb-3">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h5 class="section-title mb-0">My pickups (<?= count($mission) ?>)</h5>
          <a href="<?= e(APP_URL) ?>/worker/requests.php" class="btn btn-sm btn-light border">Open list</a>
        </div>

        <?php if (!$mission): ?>
          <div class="empty-state">
            <i class="bi bi-inbox"></i>
            <p class="mb-0 fw-600">No pickups assigned yet.</p>
          </div>
        <?php else: foreach ($mission as $r):
            $w = waste_meta($r['waste_type']); ?>
          <div class="stop-row <?= in_array($r['status'], ['collected','verified'], true) ? 'done' : '' ?>">
            <span class="stop-num"><?= in_array($r['status'], ['collected','verified'], true) ? '<i class="bi bi-check"></i>' : (int) $r['id'] ?></span>
            <div class="flex-grow-1 min-w-0">
              <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="fw-600" style="font-size:.88rem"><?= e($r['address']) ?></span>
                <?= status_badge($r['status']) ?>
              </div>
              <div class="text-muted" style="font-size:.76rem">
                <i class="bi bi-<?= e($w['icon']) ?>" style="color:<?= e($w['color']) ?>"></i>
                <?= e($w['label']) ?> ·
                <?= e(quantity_options()[$r['quantity']] ?? $r['quantity']) ?> ·
                <?= e($r['landmark'] ?: 'no landmark') ?>
              </div>
            </div>
            <div class="text-end text-nowrap">
              <?= priority_badge($r['priority_level'], (int) $r['priority_score']) ?>
              <div class="mt-1">
                <a class="btn btn-sm btn-outline-success" href="<?= e(APP_URL) ?>/worker/requests.php?focus=<?= (int) $r['id'] ?>">
                  <?= in_array($r['status'], ['collected','verified'], true) ? 'View' : 'Work' ?>
                </a>
              </div>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <?php if ($plan): ?>
      <div class="card card-soft">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h5 class="section-title mb-0">Route plan <?= e($plan['plan_code']) ?></h5>
            <a href="<?= e(APP_URL) ?>/worker/mission.php" class="btn btn-sm btn-success">
              <i class="bi bi-map me-1"></i>Open route
            </a>
          </div>
          <div class="row text-center g-2">
            <div class="col-4">
              <div class="kpi-value"><?= (int) $plan['total_stops'] ?></div>
              <div class="text-muted" style="font-size:.74rem">stops</div>
            </div>
            <div class="col-4">
              <div class="kpi-value text-success"><?= round((float) $plan['total_distance_km'], 1) ?></div>
              <div class="text-muted" style="font-size:.74rem">km</div>
            </div>
            <div class="col-4">
              <div class="kpi-value text-info"><?= e(fmt_duration((int) $plan['estimated_duration_min'])) ?></div>
              <div class="text-muted" style="font-size:.74rem">planned</div>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php
$inlineJs = <<<'JS'
document.querySelectorAll('.js-status').forEach(function (btn) {
  btn.addEventListener('click', async function () {
    btn.disabled = true;
    const res = await apiPost('workers/status.php', { status: btn.dataset.status });
    if (res) setTimeout(() => window.location.reload(), 800);
    else btn.disabled = false;
  });
});

/* Keep the control room informed of the crew's live position. */
function ping() {
  if (!navigator.geolocation) return;
  navigator.geolocation.getCurrentPosition(function (pos) {
    apiPost('workers/update-location.php', {
      lat: pos.coords.latitude,
      lng: pos.coords.longitude,
      accuracy: pos.coords.accuracy,
      speed: pos.coords.speed
    });
  }, function () { /* permission denied — silent */ }, { enableHighAccuracy: true, timeout: 8000 });
}
ping();
setInterval(ping, 120000);
JS;

require __DIR__ . '/../includes/footer.php';
