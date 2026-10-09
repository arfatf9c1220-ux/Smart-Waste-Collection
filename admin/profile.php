<?php
/**
 * admin/profile.php  —  officer account + system snapshot
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$user = current_user();

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
        redirect('/admin/profile.php');
    }
}

$stats = [
    'requests'  => (int) db_value('SELECT COUNT(*) FROM waste_requests', [], 0),
    'verified'  => (int) db_value('SELECT COUNT(*) FROM waste_requests WHERE status = "verified"', [], 0),
    'workers'   => (int) db_value('SELECT COUNT(*) FROM workers WHERE status <> "removed"', [], 0),
    'citizens'  => (int) db_value('SELECT COUNT(*) FROM citizens', [], 0),
    'plans'     => (int) db_value('SELECT COUNT(*) FROM collection_plans', [], 0),
    'hotspots'  => (int) db_value('SELECT COUNT(*) FROM hotspots', [], 0),
    'images'    => (int) db_value('SELECT COUNT(*) FROM request_images', [], 0),
    'logs'      => (int) db_value('SELECT COUNT(*) FROM activity_logs', [], 0),
];

$pageTitle    = 'My Profile';
$pageSubtitle = 'Officer account and system snapshot';
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
            <div class="col-12">
              <label class="form-label fw-600">Role</label>
              <input type="text" class="form-control mono" value="Municipal Authority (admin)" disabled>
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
      <a href="<?= e(APP_URL) ?>/logout.php" class="btn btn-link text-danger text-decoration-none">
        Log out instead
      </a>
    </form>
  </div>

  <div class="col-lg-7">
    <div class="card card-soft mb-3">
      <div class="card-body">
        <h5 class="section-title">System snapshot</h5>
        <div class="row text-center g-2">
          <?php foreach ([
              ['Requests', $stats['requests'], 'bi-inbox', 'text-primary'],
              ['Verified', $stats['verified'], 'bi-patch-check', 'text-success'],
              ['Crews', $stats['workers'], 'bi-truck', 'text-warning'],
              ['Citizens', $stats['citizens'], 'bi-people', 'text-info'],
              ['Plans', $stats['plans'], 'bi-signpost-split', 'text-primary'],
              ['Hotspots', $stats['hotspots'], 'bi-fire', 'text-danger'],
              ['Photos', $stats['images'], 'bi-camera', 'text-info'],
              ['Log rows', $stats['logs'], 'bi-clock-history', 'text-muted'],
          ] as [$label, $value, $icon, $color]): ?>
            <div class="col-6 col-md-3">
              <div class="kpi p-2 mb-2">
                <div class="kpi-label" style="font-size:.68rem"><?= e($label) ?></div>
                <div class="kpi-value <?= $color ?>" style="font-size:1.35rem"><?= (int) $value ?></div>
                <div class="kpi-foot" style="font-size:.66rem"><i class="bi <?= e($icon) ?>"></i></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="card card-soft">
      <div class="card-body">
        <h5 class="section-title">Engines &amp; rules</h5>
        <dl class="row mb-0" style="font-size:.84rem">
          <dt class="col-sm-5 text-muted fw-normal">Priority weights</dt>
          <dd class="col-sm-7">severity 30 · quantity 20 · location risk 20 · age 15 · repeats 15</dd>

          <dt class="col-sm-5 text-muted fw-normal">Priority bands</dt>
          <dd class="col-sm-7">Low 0–30 · Medium 31–60 · High 61–80 · Critical 81–100</dd>

          <dt class="col-sm-5 text-muted fw-normal">Recommendation weights</dt>
          <dd class="col-sm-7">distance 40 · availability 25 · workload 20 · equipment 15</dd>

          <dt class="col-sm-5 text-muted fw-normal">Hotspot window</dt>
          <dd class="col-sm-7">last <?= (int) HOTSPOT_WINDOW_DAYS ?> days within <?= (int) HOTSPOT_RADIUS_M ?> m</dd>

          <dt class="col-sm-5 text-muted fw-normal">Verification rule</dt>
          <dd class="col-sm-7">BEFORE + AFTER proof photos are mandatory before a pickup can close</dd>

          <dt class="col-sm-5 text-muted fw-normal">Upload rules</dt>
          <dd class="col-sm-7">JPEG only, max <?= (int) round(MAX_UPLOAD_BYTES / 1048576) ?> MB, random file names</dd>
        </dl>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php';
