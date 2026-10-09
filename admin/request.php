<?php
/**
 * admin/request.php  —  full detail of one request + every admin action
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/priority.php';
require_role('admin');

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    $_SESSION['flash'] = 'Pick a request from the list first.';
    redirect('/admin/requests.php');
}

$row = db_row(
    'SELECT r.*, u.full_name AS citizen_name, u.email AS citizen_email, u.phone AS citizen_phone,
            wu.full_name AS worker_name, w.worker_code, w.phone AS worker_phone,
            t.team_name, v.vehicle_number, v.vehicle_type,
            p.plan_code, p.status AS plan_status, p.id AS plan_id
       FROM waste_requests r
       LEFT JOIN users u            ON u.id = r.user_id
       LEFT JOIN workers w          ON w.id = r.assigned_worker_id
       LEFT JOIN users wu           ON wu.id = w.user_id
       LEFT JOIN teams t            ON t.id = r.assigned_team_id
       LEFT JOIN vehicles v         ON v.id = r.vehicle_id
       LEFT JOIN collection_plans p ON p.id = r.collection_plan_id
      WHERE r.id = ?',
    [$id]
);

if (!$row) {
    $_SESSION['flash'] = 'That request no longer exists.';
    redirect('/admin/requests.php');
}

$w        = waste_meta($row['waste_type']);
$priority = calculate_priority($row);
$meta     = status_meta($row['status']);

$images = [];
foreach (db_all('SELECT * FROM request_images WHERE request_id = ? ORDER BY id', [$id]) as $img) {
    $images[$img['image_type']][] = image_url($img['file_path']);
}
if (!$images['before'] && $row['before_image']) $images['before'] = [image_url($row['before_image'])];
if (!$images['after']  && $row['after_image'])  $images['after']  = [image_url($row['after_image'])];

$history = db_all(
    'SELECT a.*, wu.full_name AS worker_name, w.worker_code, t.team_name
       FROM assignments a
       LEFT JOIN workers w  ON w.id = a.worker_id
       LEFT JOIN users wu   ON wu.id = w.user_id
       LEFT JOIN teams t    ON t.id = a.team_id
      WHERE a.request_id = ? ORDER BY a.id DESC',
    [$id]
);

$timeline = [];
$order = ['pending' => 0, 'prioritized' => 1, 'assigned' => 2, 'scheduled' => 3,
          'in_progress' => 4, 'collected' => 5, 'verified' => 6];
$labels = [
    'pending'     => ['Request received', 'bi-inbox'],
    'prioritized' => ['Priority analysed', 'bi-graph-up-arrow'],
    'assigned'    => ['Team assigned', 'bi-person-check'],
    'scheduled'   => ['Added to route', 'bi-signpost-split'],
    'in_progress' => ['Collection started', 'bi-truck'],
    'collected'   => ['Collected + proof', 'bi-camera-fill'],
    'verified'    => ['Verified', 'bi-patch-check'],
];
$stamps = [
    'pending' => $row['created_at'], 'prioritized' => $row['created_at'],
    'assigned' => $row['assigned_at'], 'scheduled' => $row['scheduled_at'],
    'in_progress' => $row['started_at'], 'collected' => $row['collected_at'],
    'verified' => $row['verified_at'],
];
$pos = $order[$row['status']] ?? -1;
foreach ($order as $key => $i) {
    $timeline[] = [
        'label'   => $labels[$key][0],
        'icon'    => $labels[$key][1],
        'done'    => $i < $pos,
        'current' => $i === $pos,
        'at'      => $stamps[$key] ?: null,
    ];
}

$pageTitle    = 'Request #' . $id;
$pageSubtitle = $w['label'] . ' · ' . $row['request_code'];
$activeNav    = 'requests';
$extraJs      = ['https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', APP_URL . '/assets/js/map.js'];

require __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap gap-2 mb-3">
  <a href="<?= e(APP_URL) ?>/admin/requests.php" class="btn btn-sm btn-light border">
    <i class="bi bi-arrow-left me-1"></i>Back to list
  </a>
  <?php if ($row['plan_id']): ?>
    <a href="<?= e(APP_URL) ?>/admin/collection-plan.php?id=<?= (int) $row['plan_id'] ?>" class="btn btn-sm btn-light border">
      <i class="bi bi-signpost-split me-1"></i><?= e($row['plan_code']) ?>
    </a>
  <?php endif; ?>
  <div class="ms-auto d-flex flex-wrap gap-2">
    <?php if (in_array($row['status'], ['pending', 'prioritized', 'assigned', 'scheduled'], true)): ?>
      <a href="<?= e(APP_URL) ?>/admin/assignments.php?request_id=<?= $id ?>#pick" class="btn btn-sm btn-success">
        <i class="bi bi-person-gear me-1"></i>Assign / reassign
      </a>
    <?php endif; ?>
    <?php if ($row['status'] === 'collected'): ?>
      <a href="<?= e(APP_URL) ?>/admin/verify.php?focus=<?= $id ?>" class="btn btn-sm btn-primary">
        <i class="bi bi-patch-check me-1"></i>Review proof
      </a>
    <?php endif; ?>
  </div>
</div>

<div class="row g-3">
  <!-- ------------------------------------------------------------ detail -->
  <div class="col-lg-8">
    <div class="card card-soft mb-3">
      <div class="card-body">
        <div class="d-flex flex-wrap gap-3 align-items-start justify-content-between">
          <div>
            <div class="d-flex align-items-center gap-2 mb-1">
              <span class="kpi-icon"><i class="bi bi-<?= e($w['icon']) ?>" style="color:<?= e($w['color']) ?>"></i></span>
              <div>
                <div class="fw-bold fs-5"><?= e($w['label']) ?> &middot; <?= e(quantity_options()[$row['quantity']] ?? $row['quantity']) ?></div>
                <div class="text-muted" style="font-size:.8rem"><?= e($row['request_code']) ?> · reported <?= e(fmt_dt($row['created_at'])) ?></div>
              </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
              <?= status_badge($row['status']) ?>
              <?= priority_badge($row['priority_level'], (int) $row['priority_score']) ?>
              <?php if ($row['plan_code']): ?>
                <span class="pill bg-primary-subtle text-primary"><i class="bi bi-signpost-split me-1"></i><?= e($row['plan_code']) ?></span>
              <?php endif; ?>
            </div>
          </div>
          <div class="text-end">
            <div class="text-muted" style="font-size:.74rem">PRIORITY SCORE</div>
            <div class="display-6 fw-bold <?= $priority['level'] === 'critical' || $priority['level'] === 'high' ? 'text-danger' : 'text-success' ?>">
              <?= (int) $priority['score'] ?><span class="fs-5 text-muted">/100</span>
            </div>
            <button class="btn btn-sm btn-light border" data-bs-toggle="collapse" data-bs-target="#prioBox">
              <i class="bi bi-info-circle me-1"></i>Breakdown
            </button>
          </div>
        </div>

        <div class="collapse mt-3" id="prioBox">
          <?php
          $FACTORS = [
              ['severity',      'Waste severity',      'Hazardous / e-waste weigh more than general waste'],
              ['quantity',      'Reported quantity',   'Bulk and large piles need bigger vehicles'],
              ['location_risk', 'Location sensitivity','Hospitals, schools and food markets score higher'],
              ['age',           'Waiting time',        'Every hour a report waits adds to the score'],
              ['repeat',        'Repeat reporting',    'Other reports within 1.2 km in 30 days'],
          ];
          foreach ($FACTORS as [$key, $label, $hint]):
              $got  = (int) ($priority['breakdown'][$key] ?? 0);
              $max  = (int) ($priority['breakdown'][$key . '_max'] ?? 1);
              $pct  = $max ? round($got / $max * 100) : 0; ?>
            <div class="mb-2">
              <div class="d-flex justify-content-between" style="font-size:.82rem">
                <span><?= e($label) ?> <span class="text-muted" style="font-size:.74rem">— <?= e($hint) ?></span></span>
                <span class="fw-600"><?= $got ?>/<?= $max ?></span>
              </div>
              <div class="progress progress-thin">
                <div class="progress-bar <?= $pct >= 70 ? 'bg-danger' : ($pct >= 40 ? 'bg-warning' : 'bg-success') ?>"
                     style="width:<?= $pct ?>%"></div>
              </div>
            </div>
          <?php endforeach; ?>
          <div class="fw-600 mt-2" style="font-size:.88rem">Total: <?= (int) $priority['score'] ?>/100 &rarr; <?= e(ucfirst($priority['level'])) ?></div>
        </div>

        <hr>
        <div class="row g-3">
          <div class="col-md-6">
            <div class="text-muted" style="font-size:.76rem">LOCATION</div>
            <div><i class="bi bi-geo-alt text-success me-1"></i><?= e($row['address']) ?></div>
            <?php if ($row['landmark']): ?>
              <div class="text-muted" style="font-size:.8rem">Near <?= e($row['landmark']) ?></div>
            <?php endif; ?>
            <div class="mono text-muted" style="font-size:.76rem">
              <?= e($row['latitude']) ?>, <?= e($row['longitude']) ?>
            </div>
          </div>
          <div class="col-md-6">
            <div class="text-muted" style="font-size:.76rem">CITIZEN</div>
            <div><?= e($row['citizen_name'] ?? '—') ?></div>
            <div class="text-muted" style="font-size:.8rem">
              <?= e($row['citizen_phone'] ?: 'no phone') ?> · <?= e($row['citizen_email'] ?: '') ?>
            </div>
          </div>
          <?php if ($row['description']): ?>
            <div class="col-12">
              <div class="text-muted" style="font-size:.76rem">DESCRIPTION</div>
              <div style="font-size:.88rem"><?= nl2br(e($row['description'])) ?></div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- photos -->
    <div class="card card-soft mb-3">
      <div class="card-body">
        <h5 class="section-title">Photo evidence</h5>
        <div class="row g-3">
          <?php
          $blocks = [
              'waste'  => ['Citizen report', 'bi-megaphone'],
              'before' => ['Before collection', 'bi-camera'],
              'after'  => ['After collection', 'bi-camera-fill'],
          ];
          foreach ($blocks as $type => [$label, $icon]): ?>
            <div class="col-md-4">
              <div class="before-after">
                <?php if (!empty($images[$type])): ?>
                  <a href="<?= e($images[$type][0]) ?>" target="_blank">
                    <img src="<?= e($images[$type][0]) ?>" class="gallery-img" alt="<?= e($label) ?>">
                  </a>
                <?php else: ?>
                  <div class="gallery-img d-flex flex-column align-items-center justify-content-center text-muted">
                    <i class="bi <?= e($icon) ?> fs-2 opacity-50"></i>
                    <span style="font-size:.74rem">Not uploaded</span>
                  </div>
                <?php endif; ?>
                <span class="ba-label"><?= e(strtoupper($label)) ?></span>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <?php if (empty($images['before']) || empty($images['after'])): ?>
          <div class="alert alert-warning border-0 mt-3 mb-0" style="border-radius:12px;font-size:.84rem">
            <i class="bi bi-exclamation-triangle me-1"></i>
            Verification is blocked until both the BEFORE and AFTER photos exist.
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- timeline -->
    <div class="card card-soft">
      <div class="card-body">
        <h5 class="section-title">Progress timeline</h5>
        <div class="timeline">
          <?php foreach ($timeline as $t): ?>
            <div class="tl-item <?= $t['current'] ? 'current' : ($t['done'] ? 'done' : '') ?>">
              <div class="d-flex align-items-center gap-2">
                <i class="bi bi-<?= e($t['icon']) ?> <?= $t['done'] ? 'text-success' : ($t['current'] ? 'text-warning' : 'text-muted') ?>"></i>
                <span class="fw-600" style="font-size:.88rem"><?= e($t['label']) ?></span>
                <?php if ($t['at']): ?>
                  <span class="text-muted ms-auto" style="font-size:.74rem"><?= e(fmt_dt($t['at'])) ?></span>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <?php if ($history): ?>
          <hr>
          <h6 class="section-title">Assignment history</h6>
          <?php foreach ($history as $h): ?>
            <div class="d-flex flex-wrap align-items-center gap-2 py-1" style="font-size:.82rem">
              <i class="bi bi-person-check text-success"></i>
              <strong><?= e($h['worker_name'] ?? '—') ?></strong>
              <span class="text-muted"><?= e($h['worker_code'] ?? '') ?> · <?= e($h['team_name'] ?: 'no team') ?></span>
              <?php if ($h['match_score']): ?>
                <span class="pill bg-success-subtle text-success">match <?= round((float) $h['match_score'], 1) ?></span>
              <?php endif; ?>
              <?php if ($h['distance_km']): ?>
                <span class="text-muted"><?= round((float) $h['distance_km'], 2) ?> km away</span>
              <?php endif; ?>
              <span class="ms-auto text-muted" style="font-size:.74rem"><?= e(fmt_dt($h['assigned_at'])) ?></span>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- -------------------------------------------------------------- side -->
  <div class="col-lg-4">
    <div class="card card-soft mb-3">
      <div class="card-body">
        <h5 class="section-title">Location</h5>
        <div class="map-shell" style="height:200px">
          <div id="detailMap" style="height:100%"
               data-lat="<?= e($row['latitude']) ?>" data-lng="<?= e($row['longitude']) ?>"
               data-rid="<?= $id ?>"
               data-score="<?= (int) $priority['score'] ?>"></div>
        </div>
      </div>
    </div>

    <div class="card card-soft mb-3">
      <div class="card-body">
        <h5 class="section-title">Team on the job</h5>
        <?php if ($row['worker_name']): ?>
          <dl class="row mb-0" style="font-size:.84rem">
            <dt class="col-4 text-muted fw-normal">Worker</dt>
            <dd class="col-8"><?= e($row['worker_name']) ?></dd>
            <dt class="col-4 text-muted fw-normal">Code</dt>
            <dd class="col-8"><?= e($row['worker_code']) ?></dd>
            <dt class="col-4 text-muted fw-normal">Team</dt>
            <dd class="col-8"><?= e($row['team_name'] ?: '—') ?></dd>
            <dt class="col-4 text-muted fw-normal">Vehicle</dt>
            <dd class="col-8"><?= e($row['vehicle_number'] ?: '—') ?><?= $row['vehicle_type'] ? ' (' . e($row['vehicle_type']) . ')' : '' ?></dd>
            <dt class="col-4 text-muted fw-normal">Phone</dt>
            <dd class="col-8"><?= e($row['worker_phone'] ?: '—') ?></dd>
            <dt class="col-4 text-muted fw-normal">ETA</dt>
            <dd class="col-8"><?= e(fmt_dt($row['estimated_visit_time'])) ?></dd>
          </dl>
        <?php else: ?>
          <p class="text-muted mb-0" style="font-size:.86rem">No team assigned yet.</p>
        <?php endif; ?>
      </div>
    </div>

    <!-- admin actions -->
    <div class="card card-soft mb-3">
      <div class="card-body">
        <h5 class="section-title">Admin actions</h5>

        <?php if (in_array($row['status'], ['pending', 'prioritized', 'assigned', 'scheduled'], true)): ?>
          <button class="btn btn-sm btn-success w-100 mb-2" id="btnAssign">
            <i class="bi bi-person-gear me-1"></i>Assign a team
          </button>
        <?php endif; ?>

        <?php if ($row['status'] === 'collected'): ?>
          <button class="btn btn-sm btn-primary w-100 mb-2" id="btnVerify" data-id="<?= $id ?>">
            <i class="bi bi-patch-check me-1"></i>Approve proof
          </button>
          <button class="btn btn-sm btn-outline-danger w-100 mb-2" id="btnRejectProof" data-id="<?= $id ?>">
            <i class="bi bi-patch-exclamation me-1"></i>Reject proof
          </button>
        <?php endif; ?>

        <div class="dropdown mb-2">
          <button class="btn btn-sm btn-light border w-100 dropdown-toggle" data-bs-toggle="dropdown">
            <i class="bi bi-arrow-repeat me-1"></i>Force status
          </button>
          <ul class="dropdown-menu w-100">
            <?php foreach (['pending', 'prioritized', 'assigned', 'scheduled', 'in_progress', 'cancelled'] as $st): ?>
              <li><a class="dropdown-item js-set-status" href="#" data-id="<?= $id ?>" data-status="<?= $st ?>">
                <?= e(status_meta($st)['label']) ?></a></li>
            <?php endforeach; ?>
          </ul>
        </div>

        <button class="btn btn-sm btn-outline-secondary w-100 mb-2" id="btnPriority" data-id="<?= $id ?>">
          <i class="bi bi-graph-up-arrow me-1"></i>Override priority
        </button>

        <?php if (!in_array($row['status'], ['verified', 'cancelled'], true)): ?>
          <button class="btn btn-sm btn-outline-danger w-100" id="btnCancel" data-id="<?= $id ?>">
            <i class="bi bi-slash-circle me-1"></i>Reject as invalid
          </button>
        <?php endif; ?>

        <?php if ($row['citizen_rating']): ?>
          <hr>
          <div class="text-muted" style="font-size:.76rem">CITIZEN RATING</div>
          <div class="text-warning">
            <?php for ($i = 1; $i <= 5; $i++): ?>
              <i class="bi bi-star<?= $i <= (int) $row['citizen_rating'] ? '-fill' : '' ?>"></i>
            <?php endfor; ?>
          </div>
          <?php if ($row['citizen_feedback']): ?>
            <div class="text-muted" style="font-size:.8rem">“<?= e($row['citizen_feedback']) ?>”</div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php
$inlineJs = <<<'JS'
(function () {
  /* --------------------------------------------------------------- map --- */
  const el  = document.getElementById('detailMap');
  const lat = parseFloat(el.dataset.lat), lng = parseFloat(el.dataset.lng);
  const map = createMap('detailMap', { lat: lat, lng: lng, zoom: 16 });
  L.marker([lat, lng], { icon: pinIcon('#16a34a', '#' + el.dataset.rid) }).addTo(map);

  const on = (sel, evt, fn) => { const n = document.querySelector(sel); if (n) n.addEventListener(evt, fn); };

  /* ------------------------------------------------------------ actions --- */
  on('#btnAssign', 'click', function () {
    window.location.href = window.APP_URL + '/admin/assignments.php?request_id=' + el.dataset.rid + '#pick';
  });

  on('#btnVerify', 'click', async function () {
    if (!confirm('Approve these proof photos and close the request?')) return;
    const res = await apiPost('admin/verify.php', { id: el.dataset.rid, decision: 'approve' });
    if (res) setTimeout(() => window.location.reload(), 900);
  });

  on('#btnRejectProof', 'click', async function () {
    const note = prompt('Why is the proof not acceptable? (sent back to the collection team)');
    if (note === null) return;
    const res = await apiPost('admin/verify.php', { id: el.dataset.rid, decision: 'reject', note: note });
    if (res) setTimeout(() => window.location.reload(), 900);
  });

  document.querySelectorAll('.js-set-status').forEach(function (a) {
    a.addEventListener('click', async function (ev) {
      ev.preventDefault();
      if (!confirm('Change status to ' + a.dataset.status + '?')) return;
      const res = await apiPost('admin/set-status.php', { id: a.dataset.id, status: a.dataset.status });
      if (res) setTimeout(() => window.location.reload(), 900);
    });
  });

  on('#btnPriority', 'click', function () {
    const current = parseInt(el.dataset.score, 10);
    const score = prompt('New priority score (0-100). Current: ' + current, current);
    if (score === null) return;
    const n = parseInt(score, 10);
    if (isNaN(n) || n < 0 || n > 100) { showToast('Score must be between 0 and 100.', 'danger'); return; }
    const level = priorityLevel(n);
    apiPost('admin/priority.php', { id: el.dataset.rid, score: n, level: level })
      .then(res => { if (res) setTimeout(() => window.location.reload(), 900); });
  });

  on('#btnCancel', 'click', async function () {
    const reason = prompt('Reason for rejecting this report as invalid:');
    if (reason === null) return;
    const res = await apiPost('admin/reject.php', { id: el.dataset.rid, reason: reason });
    if (res) setTimeout(() => window.location.reload(), 900);
  });
})();
JS;

require __DIR__ . '/../includes/footer.php';
