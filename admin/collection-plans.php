<?php
/**
 * admin/collection-plans.php  —  route planning desk
 * Pick a team → the engine suggests stops → tick and publish the plan.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/priority.php';
require_role('admin');

$plans = db_all(
    'SELECT p.*, u.full_name AS worker_name, w.worker_code, t.team_name, v.vehicle_number,
            (SELECT COUNT(*) FROM collection_plan_stops s
              WHERE s.plan_id = p.id AND s.status = "collected") AS stops_done
       FROM collection_plans p
       LEFT JOIN workers w  ON w.id = p.worker_id
       LEFT JOIN users u    ON u.id = w.user_id
       LEFT JOIN teams t    ON t.id = p.team_id
       LEFT JOIN vehicles v ON v.id = p.vehicle_id
      ORDER BY p.plan_date DESC, p.id DESC
      LIMIT 60'
);

$workers = db_all(
    'SELECT w.id, w.worker_code, w.status, w.current_load, w.max_load, w.latitude, w.longitude,
            u.full_name, t.team_name, t.waste_types AS team_waste_types,
            v.vehicle_number, v.vehicle_type, v.capacity_kg
       FROM workers w
       JOIN users u         ON u.id = w.user_id
       LEFT JOIN teams t    ON t.id = w.team_id
       LEFT JOIN vehicles v ON v.id = w.vehicle_id
      WHERE w.status <> "removed"
      ORDER BY w.status = "available" DESC, w.current_load ASC'
);

$planStatusColor = [
    'draft' => 'secondary', 'active' => 'success', 'assigned' => 'info',
    'completed' => 'primary', 'cancelled' => 'danger',
];

$pageTitle    = 'Collection Plans';
$pageSubtitle = 'Optimised multi-stop routes per team and vehicle';
$activeNav    = 'plans';

require __DIR__ . '/../includes/header.php';
?>

<div class="row g-3">
  <!-- ===================================================== plan builder -->
  <div class="col-xl-5">
    <div class="card card-soft" id="builder">
      <div class="card-body">
        <h5 class="section-title">Generate a route plan</h5>
        <p class="text-muted" style="font-size:.82rem">
          Step 1 — choose the team. Step 2 — the optimiser suggests compatible pickups in the
          best travel order. Step 3 — publish; every stop is assigned automatically.
        </p>

        <label class="form-label fw-600" style="font-size:.82rem">1 · Team</label>
        <select class="form-select form-select-sm mb-2" id="selWorker">
          <option value="">Choose a collection team…</option>
          <?php foreach ($workers as $w): ?>
            <option value="<?= (int) $w['id'] ?>"
                    data-wtypes="<?= e($w['team_waste_types'] ?? '') ?>">
              <?= e($w['full_name']) ?> (<?= e($w['worker_code']) ?>) —
              <?= e($w['team_name'] ?: 'no team') ?><?= $w['vehicle_number'] ? ' · ' . e($w['vehicle_number']) : '' ?>
              [<?= e($w['status']) ?>, load <?= (int) $w['current_load'] ?>/<?= (int) $w['max_load'] ?>]
            </option>
          <?php endforeach; ?>
        </select>

        <label class="form-label fw-600" style="font-size:.82rem">2 · Plan date</label>
        <input type="date" class="form-control form-control-sm mb-3" id="planDate" value="<?= date('Y-m-d') ?>">

        <div id="suggestBox">
          <div class="empty-state">
            <i class="bi bi-signpost-split"></i>
            <p class="mb-1 fw-600">No team selected</p>
            <p class="text-muted mb-0" style="font-size:.84rem">
              The engine filters by equipment compatibility and orders stops to minimise travel distance.
            </p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ========================================================= plan list -->
  <div class="col-xl-7">
    <div class="card card-soft">
      <div class="card-body">
        <h5 class="section-title">All plans (<?= count($plans) ?>)</h5>

        <?php if (!$plans): ?>
          <div class="empty-state">
            <i class="bi bi-signpost-split"></i>
            <p class="mb-1 fw-600">No plans yet</p>
            <p class="text-muted mb-0" style="font-size:.86rem">
              Generate one on the left — the optimiser handles the routing.
            </p>
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table align-middle sw-table mb-0">
              <thead>
                <tr><th>Plan</th><th>Team</th><th>Stops</th><th>Distance</th><th>Duration</th><th>Status</th><th class="text-end"></th></tr>
              </thead>
              <tbody>
                <?php foreach ($plans as $p):
                    $done = (int) $p['stops_done'];
                    $pct  = $p['total_stops'] ? round($done / (int) $p['total_stops'] * 100) : 0;
                    $col  = $planStatusColor[$p['status']] ?? 'secondary'; ?>
                  <tr class="clickable-row" onclick="window.location.href='<?= e(APP_URL) ?>/admin/collection-plan.php?id=<?= (int) $p['id'] ?>'">
                    <td class="fw-600"><?= e($p['plan_code']) ?>
                      <div class="text-muted" style="font-size:.72rem"><?= e(fmt_dt($p['plan_date'] . ' 00:00:00')) ?></div></td>
                    <td><?= e($p['worker_name'] ?? '—') ?>
                      <div class="text-muted" style="font-size:.72rem"><?= e($p['team_name'] ?: '') ?><?= $p['vehicle_number'] ? ' · ' . e($p['vehicle_number']) : '' ?></div></td>
                    <td style="min-width:110px">
                      <div class="d-flex justify-content-between" style="font-size:.74rem">
                        <span><?= $done ?>/<?= (int) $p['total_stops'] ?></span>
                        <span class="text-muted"><?= $pct ?>%</span>
                      </div>
                      <div class="progress progress-thin">
                        <div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div>
                      </div>
                    </td>
                    <td style="font-size:.8rem"><?= round((float) $p['total_distance_km'], 1) ?> km</td>
                    <td style="font-size:.8rem"><?= e(fmt_duration((int) $p['estimated_duration_min'])) ?></td>
                    <td><span class="pill bg-<?= $col ?>-subtle text-<?= $col ?>"><?= e(ucfirst($p['status'])) ?></span></td>
                    <td class="text-end">
                      <a href="<?= e(APP_URL) ?>/admin/collection-plan.php?id=<?= (int) $p['id'] ?>"
                         class="btn btn-sm btn-light border"><i class="bi bi-eye"></i></a>
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
</div>

<?php
$inlineJs = <<<'JS'
(async function () {
  const sel   = document.getElementById('selWorker');
  const box   = document.getElementById('suggestBox');
  const dateEl = document.getElementById('planDate');
  let suggested = [];

  async function loadSuggestion() {
    const workerId = sel.value;
    if (!workerId) return;
    renderInto(box, '<div class="text-center py-4"><div class="spinner-border spinner-border-sm text-success"></div> Optimising route…</div>');

    const res = await apiGet('collection/suggest.php', { worker_id: workerId, max: 10 });
    if (!res) { renderInto(box, ''); return; }
    suggested = res.data.candidates;
    const w = res.data.worker;

    const skipped = (res.data.skipped || []).map(s =>
      '<li class="text-muted">' + escapeHtml(s.address) + ' — ' + escapeHtml(s.reason) + '</li>').join('');

    const rows = suggested.map(c => {
      const order = (res.data.preview.find(p => p.request_id === c.id) || {}).stop_number;
      return '<div class="d-flex align-items-start gap-2 py-2 border-bottom">' +
        '<input class="form-check-input mt-1 js-stop" type="checkbox" value="' + c.id + '" checked>' +
        '<div class="flex-grow-1 min-w-0">' +
          '<div class="fw-600" style="font-size:.86rem">#' + c.id + ' · ' + escapeHtml(c.waste_label) +
            (order ? ' <span class="pill bg-primary-subtle text-primary ms-1">stop ' + order + '</span>' : '') +
          '</div>' +
          '<div class="text-muted" style="font-size:.76rem">' + escapeHtml(c.address) + '</div>' +
          '<div class="text-muted" style="font-size:.72rem">' +
            'P' + c.priority_score + ' · ' + c.distance_km + ' km · waiting ' + c.age_hours + ' h</div>' +
        '</div>' +
        priorityBadge(c.priority_level, c.priority_score) +
      '</div>';
    }).join('');

    renderInto(box,
      '<div class="alert alert-light border mb-3" style="border-radius:12px;font-size:.82rem">' +
        '<strong>' + escapeHtml(w.team_name || 'Team') + '</strong>' +
        (w.vehicle ? ' · ' + escapeHtml(w.vehicle) : '') +
        ' — engine picked <strong>' + suggested.length + '</strong> stop(s), ' +
        res.data.preview_distance_km + ' km, ~' + fmtDuration(res.data.preview_duration_min) + '.' +
        (skipped ? '<details class="mt-1"><summary class="text-muted" style="cursor:pointer">' +
          (res.data.skipped.length) + ' skipped request(s)</summary><ul class="ps-3 mb-0 mt-1">' + skipped + '</ul></details>' : '') +
      '</div>' +
      (suggested.length
        ? '<div id="stopList">' + rows + '</div>' +
          '<div class="d-flex gap-2 mt-3">' +
            '<button class="btn btn-sm btn-success flex-grow-1" id="btnCreate"><i class="bi bi-magic me-1"></i>Publish plan</button>' +
            '<button class="btn btn-sm btn-light border" id="btnToggleAll">Toggle all</button>' +
          '</div>'
        : '<div class="empty-state"><i class="bi bi-inbox"></i><p class="mb-0 fw-600">No compatible pickups for this team right now.</p></div>'));

    const toggle = document.getElementById('btnToggleAll');
    if (toggle) toggle.addEventListener('click', function () {
      const boxes = document.querySelectorAll('.js-stop');
      const allOn = Array.from(boxes).every(b => b.checked);
      boxes.forEach(b => { b.checked = !allOn; });
    });

    const create = document.getElementById('btnCreate');
    if (create) create.addEventListener('click', async function () {
      const ids = Array.from(document.querySelectorAll('.js-stop:checked')).map(b => parseInt(b.value, 10));
      if (!ids.length) { showToast('Tick at least one stop.', 'warning'); return; }
      create.disabled = true;
      const res2 = await apiPost('collection/create-plan.php', {
        worker_id: parseInt(sel.value, 10),
        request_ids: ids,
        plan_date: dateEl.value,
        notes: 'Generated from the optimiser on ' + dateEl.value
      });
      if (res2) setTimeout(() => window.location.href = window.APP_URL + '/admin/collection-plan.php?id=' +
          res2.data.plan_id, 900);
      else create.disabled = false;
    });
  }

  sel.addEventListener('change', loadSuggestion);
})();
JS;

require __DIR__ . '/../includes/footer.php';
