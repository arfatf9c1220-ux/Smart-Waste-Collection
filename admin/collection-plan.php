<?php
/**
 * admin/collection-plan.php  —  one plan: stops, route map and plan actions
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/priority.php';
require_role('admin');

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    $_SESSION['flash'] = 'Choose a plan from the list.';
    redirect('/admin/collection-plans.php');
}

$plan = db_row(
    'SELECT p.*, u.full_name AS worker_name, w.worker_code, w.phone AS worker_phone,
            w.status AS worker_status, w.latitude AS worker_lat, w.longitude AS worker_lng,
            t.team_name, t.waste_types AS team_waste_types,
            v.vehicle_number, v.vehicle_type, v.capacity_kg
       FROM collection_plans p
       LEFT JOIN workers w  ON w.id = p.worker_id
       LEFT JOIN users u    ON u.id = w.user_id
       LEFT JOIN teams t    ON t.id = p.team_id
       LEFT JOIN vehicles v ON v.id = p.vehicle_id
      WHERE p.id = ?',
    [$id]
);

if (!$plan) {
    $_SESSION['flash'] = 'That plan no longer exists.';
    redirect('/admin/collection-plans.php');
}

$stops = db_all(
    'SELECT s.*, r.request_code, r.waste_type, r.quantity, r.address, r.landmark,
            r.latitude, r.longitude, r.priority_score, r.priority_level,
            r.status AS request_status, r.image_path, r.before_image, r.after_image,
            u.full_name AS citizen_name
       FROM collection_plan_stops s
       JOIN waste_requests r ON r.id = s.request_id
       LEFT JOIN users u     ON u.id = r.user_id
      WHERE s.plan_id = ?
      ORDER BY s.sequence_order',
    [$id]
);

$done  = count(array_filter($stops, fn($s) => $s['status'] === 'collected'));
$pct   = $stops ? round($done / count($stops) * 100) : 0;

$otherWorkers = db_all(
    'SELECT w.id, u.full_name, w.worker_code, t.team_name
       FROM workers w
       JOIN users u      ON u.id = w.user_id
       LEFT JOIN teams t ON t.id = w.team_id
      WHERE w.status <> "removed" AND w.id <> ?
      ORDER BY u.full_name',
    [$plan['worker_id']]
);

$pageTitle    = 'Plan ' . $plan['plan_code'];
$pageSubtitle = $plan['team_name'] . ' · ' . $plan['worker_name'];
$activeNav    = 'plans';
$extraJs      = ['https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', APP_URL . '/assets/js/map.js'];

require __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap gap-2 mb-3">
  <a href="<?= e(APP_URL) ?>/admin/collection-plans.php" class="btn btn-sm btn-light border">
    <i class="bi bi-arrow-left me-1"></i>All plans
  </a>
  <div class="ms-auto d-flex flex-wrap gap-2">
    <?php if ($plan['status'] === 'assigned' || $plan['status'] === 'draft'): ?>
      <button class="btn btn-sm btn-success js-action" data-action="start">
        <i class="bi bi-play-fill me-1"></i>Start plan
      </button>
    <?php endif; ?>
    <?php if ($plan['status'] === 'active'): ?>
      <button class="btn btn-sm btn-primary js-action" data-action="complete">
        <i class="bi bi-check2-circle me-1"></i>Complete plan
      </button>
    <?php endif; ?>
    <?php if (!in_array($plan['status'], ['completed', 'cancelled'], true)): ?>
      <div class="dropdown">
        <button class="btn btn-sm btn-light border dropdown-toggle" data-bs-toggle="dropdown">
          <i class="bi bi-person-gear me-1"></i>Reassign team
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <?php foreach ($otherWorkers as $w): ?>
            <li><a class="dropdown-item js-assign-plan" href="#" data-worker="<?= (int) $w['id'] ?>">
              <?= e($w['full_name']) ?> <span class="text-muted"><?= e($w['team_name'] ?: '') ?></span>
            </a></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <button class="btn btn-sm btn-outline-danger js-action" data-action="cancel">
        <i class="bi bi-x-circle me-1"></i>Cancel
      </button>
    <?php endif; ?>
    <button class="btn btn-sm btn-light border" onclick="window.print()">
      <i class="bi bi-printer me-1"></i>Print
    </button>
  </div>
</div>

<div class="row g-3">
  <!-- ------------------------------------------------------- plan header -->
  <div class="col-xl-8">
    <div class="card card-soft mb-3">
      <div class="card-body">
        <div class="row g-3 align-items-center">
          <div class="col-md-5">
            <div class="d-flex align-items-center gap-2 mb-1">
              <span class="kpi-icon"><i class="bi bi-signpost-split"></i></span>
              <div>
                <div class="fw-bold"><?= e($plan['plan_code']) ?></div>
                <div class="text-muted" style="font-size:.78rem"><?= e(fmt_dt($plan['plan_date'] . ' 00:00:00')) ?></div>
              </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
              <?php
              $col = ['draft' => 'secondary', 'assigned' => 'info', 'active' => 'success',
                      'completed' => 'primary', 'cancelled' => 'danger'][$plan['status']] ?? 'secondary'; ?>
              <span class="pill bg-<?= $col ?>-subtle text-<?= $col ?>"><?= e(ucfirst($plan['status'])) ?></span>
              <span class="pill bg-secondary-subtle text-secondary">
                <?= e($plan['team_name'] ?: 'No team') ?>
              </span>
              <?php if ($plan['vehicle_number']): ?>
                <span class="pill bg-secondary-subtle text-secondary">
                  <i class="bi bi-truck me-1"></i><?= e($plan['vehicle_number']) ?>
                </span>
              <?php endif; ?>
            </div>
          </div>

          <div class="col-md-7">
            <div class="row text-center g-2">
              <div class="col-4">
                <div class="kpi-value"><?= count($stops) ?></div>
                <div class="text-muted" style="font-size:.74rem">Stops</div>
              </div>
              <div class="col-4">
                <div class="kpi-value text-success"><?= round((float) $plan['total_distance_km'], 1) ?></div>
                <div class="text-muted" style="font-size:.74rem">km planned</div>
              </div>
              <div class="col-4">
                <div class="kpi-value text-info"><?= e(fmt_duration((int) $plan['estimated_duration_min'])) ?></div>
                <div class="text-muted" style="font-size:.74rem">estimated</div>
              </div>
            </div>
            <div class="progress progress-thin mt-2">
              <div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div>
            </div>
            <div class="d-flex justify-content-between text-muted mt-1" style="font-size:.72rem">
              <span><?= $done ?> collected</span><span><?= $pct ?>% complete</span>
            </div>
          </div>
        </div>

        <?php if ($plan['notes']): ?>
          <hr>
          <div class="text-muted" style="font-size:.84rem"><i class="bi bi-sticky me-1"></i><?= e($plan['notes']) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- stops -->
    <div class="card card-soft">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h5 class="section-title mb-0">Optimised stop order</h5>
          <span class="text-muted" style="font-size:.78rem">
            <i class="bi bi-arrow-down-up me-1"></i>use the arrows to override the optimiser
          </span>
        </div>

        <?php if (!$stops): ?>
          <p class="text-muted mb-0" style="font-size:.86rem">This plan has no stops.</p>
        <?php else: foreach ($stops as $i => $s):
            $w = waste_meta($s['waste_type']);
            $isDone = $s['status'] === 'collected' || $s['request_status'] === 'collected' || $s['request_status'] === 'verified';
            $isNext = !$isDone && $i === 0; ?>
          <div class="stop-row <?= $isDone ? 'done' : ($isNext ? 'next' : '') ?>"
               data-stop="<?= (int) $s['id'] ?>"
               data-lat="<?= e($s['latitude']) ?>" data-lng="<?= e($s['longitude']) ?>">
            <span class="stop-num"><?= $isDone ? '<i class="bi bi-check"></i>' : (int) $s['stop_number'] ?></span>
            <div class="flex-grow-1 min-w-0">
              <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="fw-600" style="font-size:.88rem"><?= e($s['address']) ?></span>
                <?= priority_badge($s['priority_level'], (int) $s['priority_score']) ?>
                <?= status_badge($s['request_status']) ?>
              </div>
              <div class="text-muted" style="font-size:.78rem">
                <i class="bi bi-<?= e($w['icon']) ?>" style="color:<?= e($w['color']) ?>"></i>
                <?= e($w['label']) ?> ·
                <?= e(quantity_options()[$s['quantity']] ?? $s['quantity']) ?> ·
                leg <?= round((float) $s['distance_from_prev_km'], 2) ?> km ·
                <?= e(fmt_duration((int) $s['travel_minutes'])) ?> drive ·
                ETA <?= e($s['eta'] ? date('H:i', strtotime($s['eta'])) : '—') ?>
              </div>
              <div class="text-muted" style="font-size:.72rem">
                Request #<?= (int) $s['request_id'] ?> · <?= e($s['citizen_name'] ?? '—') ?>
                <?php if ($s['landmark']): ?> · near <?= e($s['landmark']) ?><?php endif; ?>
              </div>
              <div class="d-flex gap-2 mt-1">
                <?php if ($s['before_image'] || $s['after_image']): ?>
                  <span class="text-muted" style="font-size:.72rem">
                    <i class="bi bi-camera me-1"></i>proof:
                    <?= $s['before_image'] ? 'before' : '—' ?> / <?= $s['after_image'] ? 'after' : '—' ?>
                  </span>
                <?php endif; ?>
                <a href="<?= e(APP_URL) ?>/admin/request.php?id=<?= (int) $s['request_id'] ?>"
                   class="small" style="font-size:.74rem">open detail</a>
              </div>
            </div>
            <div class="text-end text-nowrap">
              <div class="btn-group btn-group-sm">
                <button class="btn btn-light border js-move" data-dir="-1" title="Move up">
                  <i class="bi bi-chevron-up"></i>
                </button>
                <button class="btn btn-light border js-move" data-dir="1" title="Move down">
                  <i class="bi bi-chevron-down"></i>
                </button>
              </div>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <!-- ------------------------------------------------------------- side -->
  <div class="col-xl-4">
    <div class="card card-soft mb-3">
      <div class="card-body">
        <h5 class="section-title">Route</h5>
        <div class="map-shell" style="height:320px">
          <div id="planMap" style="height:100%" data-plan="<?= $id ?>"></div>
        </div>
        <div class="text-muted mt-2" style="font-size:.76rem">
          Numbered stops follow the optimised travel order. Reordering recalculates the ETAs.
        </div>
      </div>
    </div>

    <div class="card card-soft">
      <div class="card-body">
        <h5 class="section-title">Team &amp; vehicle</h5>
        <?php if ($plan['worker_name']): ?>
          <div class="d-flex align-items-center gap-2 mb-2">
            <span class="avatar-circle"><?= e(mb_strtoupper(mb_substr($plan['worker_name'], 0, 1))) ?></span>
            <div>
              <div class="fw-600" style="font-size:.88rem"><?= e($plan['worker_name']) ?></div>
              <div class="text-muted" style="font-size:.74rem"><?= e($plan['worker_code']) ?></div>
            </div>
            <span class="ms-auto pill bg-<?= $plan['worker_status'] === 'available' ? 'success' : 'warning' ?>-subtle
                         text-<?= $plan['worker_status'] === 'available' ? 'success' : 'warning' ?>">
              <?= e(ucfirst((string) $plan['worker_status'])) ?>
            </span>
          </div>
          <dl class="row mb-0" style="font-size:.84rem">
            <dt class="col-4 text-muted fw-normal">Team</dt>
            <dd class="col-8"><?= e($plan['team_name'] ?: '—') ?></dd>
            <dt class="col-4 text-muted fw-normal">Handles</dt>
            <dd class="col-8" style="font-size:.78rem"><?= e(str_replace(',', ', ', $plan['team_waste_types'] ?: 'general')) ?></dd>
            <dt class="col-4 text-muted fw-normal">Vehicle</dt>
            <dd class="col-8"><?= e($plan['vehicle_number'] ?: '—') ?>
              <?= $plan['capacity_kg'] ? ' <span class="text-muted">(' . (int) $plan['capacity_kg'] . ' kg)</span>' : '' ?></dd>
            <dt class="col-4 text-muted fw-normal">Phone</dt>
            <dd class="col-8"><?= e($plan['worker_phone'] ?: '—') ?></dd>
            <dt class="col-4 text-muted fw-normal">Started</dt>
            <dd class="col-8"><?= e(fmt_dt($plan['started_at'])) ?></dd>
            <dt class="col-4 text-muted fw-normal">Finished</dt>
            <dd class="col-8"><?= e(fmt_dt($plan['completed_at'])) ?></dd>
          </dl>
        <?php else: ?>
          <p class="text-muted mb-0" style="font-size:.86rem">No team assigned.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php
$inlineJs = <<<'JS'
(function () {
  /* --------------------------------------------------------- plan map --- */
  const rows = document.querySelectorAll('.stop-row');
  const pts  = Array.from(rows).map(function (row, i) {
    return { latitude: row.dataset.lat, longitude: row.dataset.lng, stop: i + 1 };
  }).filter(p => p.latitude);

  const map = createMap('planMap', { zoom: 12 });
  if (pts.length) {
    drawRoute(map, pts, '#16a34a');
    pts.forEach(function (p) {
      L.marker([parseFloat(p.latitude), parseFloat(p.longitude)], { icon: pinIcon('#0d6efd', p.stop) })
        .addTo(map);
    });
    map.fitBounds(pts.map(p => [parseFloat(p.latitude), parseFloat(p.longitude)]), { padding: [40, 40] });
  }

  /* ---------------------------------------------------- plan actions --- */
  const planId = parseInt(document.getElementById('planMap').dataset.plan, 10);

  document.querySelectorAll('.js-action').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      const action = btn.dataset.action;
      let force = false;
      if (action === 'complete') {
        if (!confirm('Mark this plan complete? Stops that are still open need "force".')) {
          force = true;
        }
      } else if (!confirm('Action: ' + action + '?')) return;

      btn.disabled = true;
      const res = await apiPost('collection/plan-action.php', { id: planId, action: action, force: force });
      if (res) setTimeout(() => window.location.reload(), 900);
      else btn.disabled = false;
    });
  });

  document.querySelectorAll('.js-assign-plan').forEach(function (a) {
    a.addEventListener('click', async function (ev) {
      ev.preventDefault();
      const res = await apiPost('collection/plan-action.php', {
        id: planId, action: 'assign', worker_id: a.dataset.worker
      });
      if (res) setTimeout(() => window.location.reload(), 900);
    });
  });

  /* -------------------------------------------------------- reordering --- */
  document.querySelectorAll('.js-move').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const row = btn.closest('.stop-row');
      const dir  = parseInt(btn.dataset.dir, 10);
      const sib  = dir === -1 ? row.previousElementSibling : row.nextElementSibling;
      if (!sib || !sib.dataset.stop) return;
      if (dir === -1) row.parentNode.insertBefore(row, sib);
      else sib.parentNode.insertBefore(sib, row);

      const order = Array.from(document.querySelectorAll('.stop-row'))
        .map(r => parseInt(r.dataset.stop, 10));
      apiPost('collection/reorder.php', { id: planId, order: order })
        .then(res => { if (res) showToast('Stop order saved, ETAs recalculated.', 'success'); });
    });
  });
})();
JS;

require __DIR__ . '/../includes/footer.php';
