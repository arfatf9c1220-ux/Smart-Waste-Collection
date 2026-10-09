<?php
/**
 * worker/profile.php  —  crew account, status and performance
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('worker');

$user   = current_user();
$worker = current_worker();

if (!$worker) {
    $_SESSION['flash'] = 'No worker profile is linked to this account yet.';
    redirect('/logout.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = clean_str(input('full_name', ''), 120);
    $phone = clean_str(input('phone', ''), 20);
    $pw    = (string) input('new_password', '');
    $pw2   = (string) input('confirm_password', '');

    if (mb_strlen($name) < 3) $errors[] = 'Full name must be at least 3 characters.';
    if ($pw !== '' && mb_strlen($pw) < 6) $errors[] = 'New password must be at least 6 characters.';
    if ($pw !== $pw2) $errors[] = 'The two passwords do not match.';

    if (!$errors) {
        db_query('UPDATE users SET full_name = ?, phone = ? WHERE id = ?',
                 [$name, $phone ?: null, $user['id']]);
        $_SESSION['name'] = $name;

        if ($pw !== '') {
            db_query('UPDATE users SET password_hash = ? WHERE id = ?',
                     [password_hash($pw, PASSWORD_DEFAULT), $user['id']]);
            log_activity('profile_update', 'user', (int) $user['id'], $name . ' changed their password');
        }

        log_activity('profile_update', 'user', (int) $user['id'], $name . ' updated their profile');
        $_SESSION['flash'] = 'Profile updated successfully.';
        redirect('/worker/profile.php');
    }
}

$team    = db_row('SELECT * FROM teams WHERE id = ?', [$worker['team_id']]);
$vehicle = db_row('SELECT * FROM vehicles WHERE id = ?', [$worker['vehicle_id']]);

$stats = [
    'total'     => (int) db_value('SELECT COUNT(*) FROM waste_requests WHERE assigned_worker_id = ?', [$worker['id']], 0),
    'verified'  => (int) db_value('SELECT COUNT(*) FROM waste_requests WHERE assigned_worker_id = ? AND status = "verified"', [$worker['id']], 0),
    'open'      => (int) db_value('SELECT COUNT(*) FROM waste_requests WHERE assigned_worker_id = ? AND status IN ("assigned","scheduled","in_progress")', [$worker['id']], 0),
    'this_week' => (int) db_value('SELECT COUNT(*) FROM waste_requests WHERE assigned_worker_id = ? AND collected_at >= ?',
                     [$worker['id'], date('Y-m-d 00:00:00', strtotime('-6 days'))], 0),
];

$avgRating = (float) db_value(
    'SELECT AVG(citizen_rating) FROM waste_requests WHERE assigned_worker_id = ? AND citizen_rating IS NOT NULL',
    [$worker['id']], 0);

$recent = db_all(
    'SELECT id, request_code, waste_type, status, priority_level, priority_score,
            address, collected_at, verified_at, citizen_rating, citizen_feedback
       FROM waste_requests
      WHERE assigned_worker_id = ? AND collected_at IS NOT NULL
      ORDER BY collected_at DESC
      LIMIT 8',
    [$worker['id']]
);

$trail = db_all(
    'SELECT * FROM worker_locations WHERE worker_id = ? ORDER BY recorded_at DESC LIMIT 10',
    [$worker['id']]
);

$statusColor = ['available' => 'success', 'busy' => 'warning', 'offline' => 'secondary'];

$pageTitle    = 'My Profile';
$pageSubtitle = 'Crew account, status and performance';
$activeNav    = 'profile';

require __DIR__ . '/../includes/header.php';
?>

<?php if ($errors): ?>
  <div class="alert alert-danger border-0" style="border-radius:12px">
    <ul class="mb-0 ps-4" style="font-size:.88rem">
      <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <?php foreach ([
      ['Pickups handled', $stats['total'], 'bi-inbox', 'text-primary'],
      ['Verified', $stats['verified'], 'bi-patch-check', 'text-success'],
      ['Open now', $stats['open'], 'bi-hourglass-split', 'text-warning'],
      ['Citizen rating', $avgRating ? round($avgRating, 1) : '—', 'bi-star-fill', 'text-warning'],
  ] as [$label, $value, $icon, $color]): ?>
    <div class="col-6 col-xl-3">
      <div class="kpi">
        <div class="kpi-label"><?= e($label) ?></div>
        <div class="kpi-value <?= $color ?>"><?= is_string($value) ? e($value) : (int) $value ?></div>
        <div class="kpi-foot"><i class="bi <?= e($icon) ?> me-1"></i>this crew</div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="row g-3">
  <div class="col-lg-5">
    <form method="post">
      <div class="card card-soft mb-3">
        <div class="card-body">
          <h5 class="section-title">Account</h5>
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-600">Full name</label>
              <input type="text" name="full_name" class="form-control" required value="<?= e($user['full_name']) ?>">
            </div>
            <div class="col-12">
              <label class="form-label fw-600">Email</label>
              <input type="email" class="form-control" value="<?= e($user['email']) ?>" disabled>
            </div>
            <div class="col-12">
              <label class="form-label fw-600">Phone</label>
              <input type="tel" name="phone" class="form-control" value="<?= e($user['phone'] ?? '') ?>">
            </div>
            <div class="col-6">
              <label class="form-label fw-600">Worker code</label>
              <input type="text" class="form-control mono" value="<?= e($worker['worker_code']) ?>" disabled>
            </div>
            <div class="col-6">
              <label class="form-label fw-600">Status</label>
              <input type="text" class="form-control mono" value="<?= e(ucfirst($worker['status'])) ?>" disabled>
            </div>
          </div>
        </div>
      </div>

      <div class="card card-soft mb-3">
        <div class="card-body">
          <h5 class="section-title">Change password</h5>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-600">New password</label>
              <input type="password" name="new_password" class="form-control" minlength="6" autocomplete="new-password">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600">Confirm</label>
              <input type="password" name="confirm_password" class="form-control" minlength="6" autocomplete="new-password">
            </div>
          </div>
          <p class="form-text mb-0">Leave blank to keep your current password.</p>
        </div>
      </div>

      <button class="btn btn-success px-4"><i class="bi bi-save me-2"></i>Save changes</button>
      <a href="<?= e(APP_URL) ?>/logout.php" class="btn btn-link text-danger text-decoration-none">Log out instead</a>
    </form>
  </div>

  <div class="col-lg-7">
    <div class="card card-soft mb-3">
      <div class="card-body">
        <h5 class="section-title">Crew &amp; vehicle</h5>
        <div class="row g-3">
          <div class="col-sm-6">
            <div class="text-muted" style="font-size:.74rem">TEAM</div>
            <div class="fw-600"><?= e($team['team_name'] ?? '—') ?></div>
            <div class="text-muted" style="font-size:.78rem">
              Handles: <?= e(str_replace(',', ', ', $team['waste_types'] ?? 'general')) ?>
            </div>
          </div>
          <div class="col-sm-6">
            <div class="text-muted" style="font-size:.74rem">VEHICLE</div>
            <div class="fw-600"><?= e($vehicle['vehicle_number'] ?? '—') ?></div>
            <div class="text-muted" style="font-size:.78rem">
              <?= e($vehicle['vehicle_type'] ?? '') ?>
              <?= $vehicle ? '· ' . (int) $vehicle['capacity_kg'] . ' kg capacity' : '' ?>
            </div>
          </div>
          <div class="col-sm-6">
            <div class="text-muted" style="font-size:.74rem">LOAD</div>
            <div class="fw-600"><?= (int) $worker['current_load'] ?>/<?= (int) $worker['max_load'] ?></div>
            <div class="progress progress-thin mt-1" style="max-width:160px">
              <div class="progress-bar bg-success"
                   style="width:<?= $worker['max_load'] ? min(100, round((int) $worker['current_load'] / (int) $worker['max_load'] * 100)) : 0 ?>%"></div>
            </div>
          </div>
          <div class="col-sm-6">
            <div class="text-muted" style="font-size:.74rem">SKILLS</div>
            <div class="text-truncate" style="font-size:.84rem"><?= e($worker['skills'] ?: '—') ?></div>
          </div>
        </div>

        <hr>
        <h6 class="section-title">Availability</h6>
        <div class="d-flex flex-wrap gap-2">
          <?php foreach (['available' => 'Available', 'busy' => 'On a job', 'offline' => 'Offline'] as $key => $label):
              $col = $statusColor[$key]; ?>
            <button class="btn btn-sm <?= $worker['status'] === $key ? 'btn-' . $col : 'btn-light border' ?> js-status"
                    data-status="<?= $key ?>"><?= e($label) ?></button>
          <?php endforeach; ?>
        </div>
        <button class="btn btn-sm btn-outline-success mt-2" id="btnPing">
          <i class="bi bi-geo-alt me-1"></i>Share my live position
        </button>
        <span class="text-muted ms-2" style="font-size:.76rem">
          Last fix: <?= e($worker['last_seen_at'] ? fmt_dt($worker['last_seen_at']) : 'never') ?>
        </span>
      </div>
    </div>

    <div class="card card-soft">
      <div class="card-body">
        <h5 class="section-title">Recent pickups</h5>
        <?php if (!$recent): ?>
          <p class="text-muted mb-0" style="font-size:.85rem">No completed pickups yet.</p>
        <?php else: foreach ($recent as $r):
            $w = waste_meta($r['waste_type']); ?>
          <div class="d-flex align-items-center gap-2 py-2 border-bottom">
            <div class="flex-grow-1 min-w-0">
              <div class="fw-600 text-truncate" style="font-size:.84rem">
                #<?= (int) $r['id'] ?> · <?= e($w['label']) ?>
              </div>
              <div class="text-muted text-truncate" style="font-size:.74rem">
                <?= e($r['address']) ?> · collected <?= e(time_ago($r['collected_at'])) ?>
                <?php if ($r['citizen_feedback']): ?> · “<?= e($r['citizen_feedback']) ?>”<?php endif; ?>
              </div>
            </div>
            <?php if ($r['citizen_rating']): ?>
              <span class="text-warning text-nowrap">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                  <i class="bi bi-star<?= $i <= (int) $r['citizen_rating'] ? '-fill' : '' ?>"></i>
                <?php endfor; ?>
              </span>
            <?php endif; ?>
            <?= status_badge($r['status']) ?>
          </div>
        <?php endforeach; endif; ?>

        <?php if ($trail): ?>
          <hr>
          <h6 class="section-title">GPS trail (last <?= count($trail) ?> fixes)</h6>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" style="font-size:.76rem">
              <thead><tr><th>Time</th><th>Coordinates</th><th>Status</th></tr></thead>
              <tbody>
                <?php foreach ($trail as $t): ?>
                  <tr>
                    <td><?= e(fmt_dt($t['recorded_at'])) ?></td>
                    <td class="mono"><?= e(number_format((float) $t['latitude'], 5)) ?>, <?= e(number_format((float) $t['longitude'], 5)) ?></td>
                    <td><?= e(ucfirst((string) $t['status'])) ?></td>
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
document.querySelectorAll('.js-status').forEach(function (btn) {
  btn.addEventListener('click', async function () {
    btn.disabled = true;
    const res = await apiPost('workers/status.php', { status: btn.dataset.status });
    if (res) setTimeout(() => window.location.reload(), 800);
    else btn.disabled = false;
  });
});

document.getElementById('btnPing').addEventListener('click', function () {
  if (!navigator.geolocation) { showToast('GPS is not available in this browser.', 'warning'); return; }
  this.disabled = true;
  navigator.geolocation.getCurrentPosition(async function (p) {
    const res = await apiPost('workers/update-location.php', {
      lat: p.coords.latitude, lng: p.coords.longitude, accuracy: p.coords.accuracy
    });
    showToast(res ? 'Position shared with the control room.' : 'Could not share your position.',
              res ? 'success' : 'danger');
    document.getElementById('btnPing').disabled = false;
  }, function () {
    showToast('Location permission denied.', 'warning');
    document.getElementById('btnPing').disabled = false;
  }, { enableHighAccuracy: true, timeout: 8000 });
});
JS;

require __DIR__ . '/../includes/footer.php';
