<?php
/**
 * worker/requests.php  —  the field work screen
 * Arrive -> start -> upload BEFORE + AFTER proof -> mark collected.
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

$filter = clean_str($_GET['status'] ?? 'open', 20);

$where = ['r.assigned_worker_id = ?'];
$params = [$wid];
if ($filter === 'open') {
    $where[] = 'r.status IN ("assigned","scheduled","in_progress")';
} elseif ($filter === 'collected') {
    $where[] = 'r.status = "collected"';
} elseif ($filter === 'done') {
    $where[] = 'r.status IN ("collected","verified")';
} elseif (in_array($filter, ['assigned', 'scheduled', 'in_progress', 'verified'], true)) {
    $where[] = 'r.status = ?';
    $params[] = $filter;
}

$rows = db_all(
    'SELECT r.*, u.full_name AS citizen_name, u.phone AS citizen_phone,
            p.plan_code, r.plan_stop_number
       FROM waste_requests r
       LEFT JOIN users u            ON u.id = r.user_id
       LEFT JOIN collection_plans p ON p.id = r.collection_plan_id
      WHERE ' . implode(' AND ', $where) . '
      ORDER BY r.priority_score DESC',
    $params
);

$focus = (int) ($_GET['focus'] ?? 0);
if ($focus <= 0 && $rows) {
    foreach ($rows as $r) {
        if (in_array($r['status'], ['assigned', 'scheduled', 'in_progress'], true)) {
            $focus = (int) $r['id'];
            break;
        }
    }
    $focus = $focus ?: (int) $rows[0]['id'];
}

$focusRow = $focus > 0
    ? db_row('SELECT r.*, u.full_name AS citizen_name, u.phone AS citizen_phone
                FROM waste_requests r LEFT JOIN users u ON u.id = r.user_id
               WHERE r.id = ? AND r.assigned_worker_id = ?', [$focus, $wid])
    : null;

$counts = [
    'open'      => (int) db_value('SELECT COUNT(*) FROM waste_requests WHERE assigned_worker_id = ? AND status IN ("assigned","scheduled","in_progress")', [$wid], 0),
    'collected' => (int) db_value('SELECT COUNT(*) FROM waste_requests WHERE assigned_worker_id = ? AND status = "collected"', [$wid], 0),
    'verified'  => (int) db_value('SELECT COUNT(*) FROM waste_requests WHERE assigned_worker_id = ? AND status = "verified"', [$wid], 0),
];

$pageTitle    = 'My Pickups';
$pageSubtitle = $counts['open'] . ' open · ' . $counts['collected'] . ' awaiting verification · ' . $counts['verified'] . ' verified';
$activeNav    = 'requests';
$extraJs      = ['https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'];

require __DIR__ . '/../includes/header.php';
?>

<div class="row g-3">
  <!-- ====================================================== job detail -->
  <div class="col-xl-7">
    <?php if (!$focusRow): ?>
      <div class="card card-soft">
        <div class="card-body">
          <div class="empty-state">
            <i class="bi bi-cup-hot"></i>
            <p class="mb-1 fw-600">Nothing to work on</p>
            <p class="text-muted mb-0" style="font-size:.86rem">
              Pickups assigned to you will appear here.
            </p>
          </div>
        </div>
      </div>
    <?php else:
        $f  = $focusRow;
        $fw = waste_meta($f['waste_type']);
        $canArrive  = in_array($f['status'], ['assigned', 'scheduled'], true);
        $canStart   = $f['status'] === 'in_progress' && !$f['started_at'];
        $canUpload  = in_array($f['status'], ['assigned', 'scheduled', 'in_progress'], true);
        $canCollect = $f['status'] === 'in_progress';
        $hasBoth    = $f['before_image'] && $f['after_image']; ?>
      <div class="card card-soft mb-3" id="jobCard"
           data-id="<?= (int) $f['id'] ?>"
           data-status="<?= e($f['status']) ?>"
           data-before="<?= $f['before_image'] ? '1' : '0' ?>"
           data-after="<?= $f['after_image'] ? '1' : '0' ?>">
        <div class="card-body">
          <div class="d-flex flex-wrap align-items-start gap-2 mb-2">
            <span class="kpi-icon"><i class="bi bi-<?= e($fw['icon']) ?>" style="color:<?= e($fw['color']) ?>"></i></span>
            <div class="flex-grow-1 min-w-0">
              <div class="fw-bold fs-5">#<?= (int) $f['id'] ?> · <?= e($fw['label']) ?></div>
              <div class="text-muted" style="font-size:.82rem">
                <?= e($f['request_code']) ?> · <?= e(quantity_options()[$f['quantity']] ?? $f['quantity']) ?>
              </div>
            </div>
            <?= priority_badge($f['priority_level'], (int) $f['priority_score']) ?>
          </div>

          <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
            <?= status_badge($f['status']) ?>
            <?php if ($f['plan_code']): ?>
              <span class="pill bg-primary-subtle text-primary">
                <?= e($f['plan_code']) ?><?= $f['plan_stop_number'] ? ' · stop ' . (int) $f['plan_stop_number'] : '' ?>
              </span>
            <?php endif; ?>
            <?php if ($f['estimated_visit_time']): ?>
              <span class="pill bg-info-subtle text-info">
                <i class="bi bi-clock me-1"></i><?= e(fmt_dt($f['estimated_visit_time'])) ?>
              </span>
            <?php endif; ?>
          </div>

          <div class="mb-3">
            <div class="text-muted" style="font-size:.76rem">LOCATION</div>
            <div style="font-size:.92rem"><i class="bi bi-geo-alt text-success me-1"></i><?= e($f['address']) ?></div>
            <?php if ($f['landmark']): ?>
              <div class="text-muted" style="font-size:.8rem">Near <?= e($f['landmark']) ?></div>
            <?php endif; ?>
            <div class="mono text-muted" style="font-size:.74rem"><?= e($f['latitude']) ?>, <?= e($f['longitude']) ?></div>
            <div class="d-flex gap-2 mt-2">
              <a class="btn btn-sm btn-success" target="_blank"
                 href="https://www.google.com/maps/dir/?api=1&destination=<?= e($f['latitude']) ?>,<?= e($f['longitude']) ?>">
                <i class="bi bi-sign-turn-right me-1"></i>Navigate
              </a>
              <?php if ($f['citizen_phone']): ?>
                <a class="btn btn-sm btn-light border" href="tel:<?= e($f['citizen_phone']) ?>">
                  <i class="bi bi-telephone me-1"></i><?= e($f['citizen_name'] ?: 'Citizen') ?>
                </a>
              <?php endif; ?>
            </div>
          </div>

          <?php if ($f['description']): ?>
            <div class="alert alert-light border mb-3" style="border-radius:12px;font-size:.86rem">
              <i class="bi bi-chat-left-text me-1"></i><?= nl2br(e($f['description'])) ?>
            </div>
          <?php endif; ?>

          <!-- step 1 / 2 -->
          <div class="row g-2 mb-3">
            <div class="col-md-6">
              <button class="btn w-100 js-step <?= $canArrive ? 'btn-success' : 'btn-light border' ?>"
                      data-action="arrive" <?= $canArrive ? '' : 'disabled' ?>>
                <i class="bi bi-geo-alt-fill me-1"></i>1 · I have arrived
              </button>
              <div class="text-muted text-center mt-1" style="font-size:.72rem">
                <?= $f['arrived_at'] ? 'Arrived ' . e(time_ago($f['arrived_at'])) : 'Marks the GPS arrival time' ?>
              </div>
            </div>
            <div class="col-md-6">
              <button class="btn w-100 js-step <?= $canStart ? 'btn-primary' : 'btn-light border' ?>"
                      data-action="start" <?= $canStart ? '' : 'disabled' ?>>
                <i class="bi bi-play-fill me-1"></i>2 · Start collecting
              </button>
              <div class="text-muted text-center mt-1" style="font-size:.72rem">
                <?= $f['started_at'] ? 'Started ' . e(time_ago($f['started_at'])) : 'Starts the collection timer' ?>
              </div>
            </div>
          </div>

          <!-- proof -->
          <h6 class="section-title">3 · Proof photos (both required)</h6>
          <div class="row g-3 mb-3">
            <?php foreach ([['before', 'Before'], ['after', 'After']] as [$type, $label]): ?>
              <div class="col-md-6">
                <div class="before-after">
                  <?php if ($f[$type . '_image']): ?>
                    <img src="<?= e(image_url($f[$type . '_image'])) ?>" class="gallery-img" id="img<?= $type ?>">
                    <span class="ba-label"><?= e(strtoupper($label)) ?> ✓</span>
                  <?php else: ?>
                    <div class="gallery-img d-flex flex-column align-items-center justify-content-center text-muted" id="img<?= $type ?>">
                      <i class="bi bi-camera fs-2 opacity-50"></i>
                      <span style="font-size:.72rem">Not uploaded</span>
                    </div>
                    <span class="ba-label"><?= e(strtoupper($label)) ?></span>
                  <?php endif; ?>
                </div>

                <label class="btn btn-sm w-100 mt-2 <?= $canUpload ? 'btn-outline-success' : 'btn-light border' ?> js-upload-btn"
                       data-type="<?= $type ?>">
                  <i class="bi bi-upload me-1"></i><?= $f[$type . '_image'] ? 'Replace ' . strtolower($label) : 'Upload ' . strtolower($label) ?>
                  <input type="file" accept=".jpg,.jpeg,image/jpeg" capture="environment" class="d-none js-file" data-type="<?= $type ?>">
                </label>
              </div>
            <?php endforeach; ?>
          </div>

          <!-- citizen photo for comparison -->
          <?php if ($f['image_path']): ?>
            <div class="d-flex align-items-center gap-2 mb-3">
              <img src="<?= e(image_url($f['image_path'])) ?>" class="img-thumb-sm" alt="Citizen photo">
              <div class="text-muted" style="font-size:.78rem">
                This is what the citizen reported — use it to confirm the right spot and quantity.
              </div>
            </div>
          <?php endif; ?>

          <button class="btn btn-lg w-100 js-step <?= ($canCollect && $hasBoth) ? 'btn-success' : 'btn-light border' ?>"
                  data-action="collect" <?= ($canCollect && $hasBoth) ? '' : 'disabled' ?>>
            <i class="bi bi-check2-circle me-1"></i>4 · Mark collected
          </button>
          <?php if ($canCollect && !$hasBoth): ?>
            <div class="text-danger text-center mt-2" style="font-size:.8rem">
              <i class="bi bi-exclamation-triangle me-1"></i>
              Upload both the BEFORE and AFTER photo before marking this pickup collected.
            </div>
          <?php endif; ?>

          <?php if (in_array($f['status'], ['collected', 'verified'], true)): ?>
            <div class="alert alert-success border-0 mt-3 mb-0" style="border-radius:12px;font-size:.86rem">
              <i class="bi bi-check2-circle me-1"></i>
              Collected <?= e(time_ago($f['collected_at'])) ?> — waiting for officer verification.
              <?php if ($f['verified_at']): ?>
                Verified <?= e(time_ago($f['verified_at'])) ?>.
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <!-- ========================================================== list -->
  <div class="col-xl-5">
    <div class="card card-soft">
      <div class="card-body">
        <ul class="nav nav-pills gap-1 mb-3">
          <?php foreach ([
              'open'      => 'Open (' . $counts['open'] . ')',
              'collected' => 'To verify (' . $counts['collected'] . ')',
              'done'      => 'Completed',
              'all'       => 'Everything',
          ] as $key => $label): ?>
            <li class="nav-item">
              <a class="nav-link py-1 px-3 <?= $filter === $key ? 'active' : '' ?>" style="font-size:.8rem"
                 href="<?= e(APP_URL . '/worker/requests.php?status=' . $key) ?>"><?= e($label) ?></a>
            </li>
          <?php endforeach; ?>
        </ul>

        <?php if (!$rows): ?>
          <div class="empty-state">
            <i class="bi bi-inbox"></i>
            <p class="mb-0 fw-600">No pickups in this view.</p>
          </div>
        <?php else: foreach ($rows as $r):
            $w = waste_meta($r['waste_type']);
            $isFocus = $focusRow && (int) $focusRow['id'] === (int) $r['id']; ?>
          <div class="queue-item <?= $isFocus ? 'active' : '' ?>"
               onclick="window.location.href='<?= e(APP_URL) ?>/worker/requests.php?status=<?= e($filter) ?>&focus=<?= (int) $r['id'] ?>'">
            <div class="d-flex justify-content-between align-items-start gap-2">
              <div class="min-w-0">
                <div class="fw-600" style="font-size:.86rem">
                  #<?= (int) $r['id'] ?> · <?= e($w['label']) ?>
                </div>
                <div class="text-muted text-truncate" style="font-size:.76rem"><?= e($r['address']) ?></div>
                <div class="text-muted" style="font-size:.72rem">
                  <?= e($r['citizen_name'] ?? '—') ?> · <?= e(time_ago($r['created_at'])) ?>
                </div>
              </div>
              <div class="text-end">
                <?= status_badge($r['status']) ?>
                <div class="mt-1"><?= priority_badge($r['priority_level'], (int) $r['priority_score']) ?></div>
              </div>
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
  const card = document.getElementById('jobCard');
  if (!card) return;
  const id = parseInt(card.dataset.id, 10);

  /* --------------------------------------------------- arrive / start / collect */
  document.querySelectorAll('.js-step').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      const action = btn.dataset.action;
      if (action === 'collect') {
        if (!confirm('Mark this pickup as collected? Both proof photos are already uploaded.')) return;
      }
      btn.disabled = true;
      const res = await apiPost('requests/update-status.php', { id: id, action: action });
      if (res) setTimeout(() => window.location.reload(), 800);
      else btn.disabled = false;
    });
  });

  /* ------------------------------------------------------------ proof upload */
  function upload(type, file) {
    if (!file) return;
    const body = new FormData();
    body.append('id', id);
    body.append('type', type);
    body.append('image', file);
    body.append('csrf_token', window.CSRF);

    navigator.geolocation && navigator.geolocation.getCurrentPosition(function (p) {
      body.append('lat', p.coords.latitude);
      body.append('lng', p.coords.longitude);
      doUpload(body);
    }, function () { doUpload(body); }, { timeout: 6000 });

    function doUpload(payload) {
      showToast('Uploading ' + type.toUpperCase() + ' photo…', 'info');
      fetch(window.APP_URL + '/api/requests/upload.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': window.CSRF },
        body: payload
      })
        .then(function (r) { return r.json(); })
        .then(function (json) {
          if (!json.success) { showToast(json.message || 'Upload failed.', 'danger'); return; }
          showToast(json.message, 'success');
          setTimeout(() => window.location.reload(), 800);
        })
        .catch(function (err) { showToast('Upload failed: ' + err.message, 'danger'); });
    }
  }

  function isAllowedJpeg(file) {
    if (!file) return false;
    const mime = (file.type || '').toLowerCase();
    const name = (file.name || '').toLowerCase();
    return mime === 'image/jpeg' || mime === 'image/jpg' || mime === 'image/pjpeg' || /\.(jpe?g)$/i.test(name);
  }

  document.querySelectorAll('.js-file').forEach(function (input) {
    input.addEventListener('change', function () {
      const file = input.files && input.files[0];
      if (!file) return;
      if (!isAllowedJpeg(file)) {
        input.value = '';
        showToast('Only JPG/JPEG photos are accepted for proof uploads.', 'warning');
        return;
      }
      upload(input.dataset.type, file);
    });
  });
})();
JS;

require __DIR__ . '/../includes/footer.php';
