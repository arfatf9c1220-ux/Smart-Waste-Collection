<?php
/**
 * citizen/profile.php  —  account + address book + rating history
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/priority.php';
require_role('citizen');

$user    = current_user();
$citizen = current_citizen() ?? [];

$errors = [];
$saved  = isset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = clean_str(input('full_name', ''), 120);
    $phone    = clean_str(input('phone', ''), 20);
    $address  = clean_str(input('address', ''), 255);
    $locality = clean_str(input('locality', ''), 120);
    $lat      = input_float('latitude');
    $lng      = input_float('longitude');
    $pw       = (string) input('new_password', '');
    $pw2      = (string) input('confirm_password', '');

    if (mb_strlen($name) < 3)                                       $errors[] = 'Full name must be at least 3 characters.';
    if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) $errors[] = 'Please enter a valid phone number.';
    if ($address === '')                                            $errors[] = 'Address cannot be empty.';
    if ($pw !== '' && mb_strlen($pw) < 6)                           $errors[] = 'New password must be at least 6 characters.';
    if ($pw !== $pw2)                                               $errors[] = 'The two passwords do not match.';

    if (!$errors) {
        db_query('UPDATE users SET full_name = ?, phone = ? WHERE id = ?',
                 [$name, $phone ?: null, $user['id']]);
        db_query('UPDATE citizens SET address = ?, locality = ?, latitude = ?, longitude = ? WHERE user_id = ?',
                 [$address, $locality ?: null, $lat, $lng, $user['id']]);
        $_SESSION['name'] = $name;

        if ($pw !== '') {
            db_query('UPDATE users SET password_hash = ? WHERE id = ?',
                     [password_hash($pw, PASSWORD_DEFAULT), $user['id']]);
            log_activity('password_change', 'user', (int) $user['id'], $name . ' changed their password');
        }

        log_activity('profile_update', 'user', (int) $user['id'], $name . ' updated their profile');
        $_SESSION['flash'] = 'Profile updated successfully.';
        redirect('/citizen/profile.php');
    }
}

$myRequests = db_all('SELECT * FROM waste_requests WHERE citizen_id = ? ORDER BY created_at DESC',
                     [(int) ($citizen['id'] ?? 0)]);
$rated = array_filter($myRequests, fn($r) => $r['citizen_rating'] !== null);
$avgRating = $rated ? round(array_sum(array_map(fn($r) => (int) $r['citizen_rating'], $rated)) / count($rated), 1) : 0;

$pageTitle    = 'My Profile';
$pageSubtitle = 'Account, address and service history';
$activeNav    = 'profile';
$extraJs      = ['https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'];

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
  <div class="col-lg-8">
    <form method="post">
      <input type="hidden" name="latitude"  id="latitude"  value="<?= e($citizen['latitude'] ?? '') ?>">
      <input type="hidden" name="longitude" id="longitude" value="<?= e($citizen['longitude'] ?? '') ?>">

      <div class="card card-soft mb-3">
        <div class="card-body">
          <h5 class="section-title">Account details</h5>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-600">Full name</label>
              <input type="text" name="full_name" class="form-control" required value="<?= e($user['full_name']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600">Email</label>
              <input type="email" class="form-control" value="<?= e($user['email']) ?>" disabled>
              <div class="form-text">Email cannot be changed.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600">Phone</label>
              <input type="tel" name="phone" class="form-control" value="<?= e($user['phone'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600">Citizen ID</label>
              <input type="text" class="form-control mono" value="<?= e($citizen['citizen_code'] ?? '—') ?>" disabled>
            </div>
          </div>
        </div>
      </div>

      <div class="card card-soft mb-3">
        <div class="card-body">
          <h5 class="section-title">Default pickup address</h5>
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label fw-600">Address</label>
              <input type="text" name="address" class="form-control" required value="<?= e($citizen['address'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-600">Locality</label>
              <input type="text" name="locality" class="form-control" value="<?= e($citizen['locality'] ?? '') ?>">
            </div>
            <div class="col-12">
              <div id="profileMap" style="height:200px;border-radius:14px;border:1px solid var(--sw-line)"
                   data-lat="<?= e($citizen['latitude'] ?? '') ?>" data-lng="<?= e($citizen['longitude'] ?? '') ?>"></div>
              <p class="text-muted mt-2 mb-0" style="font-size:.78rem">Click the map to update your home pin.</p>
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
              <label class="form-label fw-600">Confirm password</label>
              <input type="password" name="confirm_password" class="form-control" minlength="6" autocomplete="new-password">
            </div>
          </div>
          <p class="form-text mb-0">Leave both blank to keep your current password.</p>
        </div>
      </div>

      <button class="btn btn-success px-4"><i class="bi bi-save me-2"></i>Save changes</button>
    </form>
  </div>

  <div class="col-lg-4">
    <div class="card card-soft mb-3">
      <div class="card-body">
        <h5 class="section-title">Your impact</h5>
        <div class="row text-center g-2">
          <div class="col-4">
            <div class="kpi-value"><?= count($myRequests) ?></div>
            <div class="text-muted" style="font-size:.74rem">Reports</div>
          </div>
          <div class="col-4">
            <div class="kpi-value text-success">
              <?= count(array_filter($myRequests, fn($r) => in_array($r['status'], ['collected','verified'], true))) ?>
            </div>
            <div class="text-muted" style="font-size:.74rem">Collected</div>
          </div>
          <div class="col-4">
            <div class="kpi-value text-warning"><?= $avgRating ?: '—' ?></div>
            <div class="text-muted" style="font-size:.74rem">Avg rating</div>
          </div>
        </div>
      </div>
    </div>

    <?php if ($rated): ?>
      <div class="card card-soft">
        <div class="card-body">
          <h5 class="section-title">Ratings given</h5>
          <?php foreach (array_slice($rated, 0, 6) as $r): ?>
            <div class="d-flex align-items-center gap-2 py-1" style="font-size:.82rem">
              <img src="<?= e(image_url($r['after_image'] ?: $r['image_path'])) ?>" class="img-thumb-sm" alt="">
              <div class="flex-grow-1">
                <div>#<?= (int) $r['id'] ?> &middot; <?= e(waste_meta($r['waste_type'])['label']) ?></div>
                <div class="text-muted" style="font-size:.72rem"><?= e($r['citizen_feedback'] ?: 'No comment') ?></div>
              </div>
              <span class="text-warning">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                  <i class="bi bi-star<?= $i <= (int) $r['citizen_rating'] ? '-fill' : '' ?>"></i>
                <?php endfor; ?>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php
$inlineJs = <<<'JS'
(function () {
  const el = document.getElementById('profileMap');
  const lat0 = parseFloat(el.dataset.lat) || 19.0760;
  const lng0 = parseFloat(el.dataset.lng) || 72.8777;
  const map = L.map('profileMap').setView([lat0, lng0], 14);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19, attribution: '&copy; OpenStreetMap contributors'
  }).addTo(map);

  const latEl = document.getElementById('latitude');
  const lngEl = document.getElementById('longitude');
  let marker = null;
  if (!isNaN(lat0) && !isNaN(lng0)) {
    marker = L.marker([lat0, lng0]).addTo(map);
  }
  map.on('click', function (e) {
    if (marker) marker.setLatLng(e.latlng); else marker = L.marker(e.latlng).addTo(map);
    latEl.value = e.latlng.lat.toFixed(7);
    lngEl.value = e.latlng.lng.toFixed(7);
  });
})();
JS;

require __DIR__ . '/../includes/footer.php';
