<?php
/**
 * register.php  —  citizen self-registration
 */
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect(dashboard_for_role(current_role()));
}

$errors = [];
$old = ['full_name' => '', 'email' => '', 'phone' => '', 'address' => '', 'locality' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($old) as $k) {
        $old[$k] = clean_str(input($k, ''), 255);
    }
    $password = (string) input('password', '');
    $confirm  = (string) input('password_confirm', '');
    $lat      = input_float('latitude');
    $lng      = input_float('longitude');

    if (mb_strlen($old['full_name']) < 3)                          $errors[] = 'Full name must be at least 3 characters.';
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL))          $errors[] = 'Please enter a valid email address.';
    if ($old['phone'] !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $old['phone'])) $errors[] = 'Please enter a valid phone number.';
    if (mb_strlen($password) < 6)                                   $errors[] = 'Password must be at least 6 characters.';
    if ($password !== $confirm)                                     $errors[] = 'The two passwords do not match.';
    if ($old['address'] === '')                                     $errors[] = 'Please enter your address.';
    if ($lat !== null && $lng !== null && !valid_coords($lat, $lng)) $errors[] = 'Invalid map coordinates.';
    if (!$errors && db_value('SELECT id FROM users WHERE email = ?', [$old['email']])) {
        $errors[] = 'An account with that email already exists. Please log in instead.';
    }

    if (!$errors) {
        try {
            db()->beginTransaction();
            $userId = db_insert(
                'INSERT INTO users (full_name, email, phone, password_hash, role, status)
                 VALUES (?, ?, ?, ?, "citizen", "active")',
                [$old['full_name'], $old['email'], $old['phone'] ?: null,
                 password_hash($password, PASSWORD_DEFAULT)]
            );
            $nextId = (int) db_value('SELECT COALESCE(MAX(id),0) FROM citizens', [], 0) + 1;
            db_query(
                'INSERT INTO citizens (user_id, citizen_code, address, city, locality, latitude, longitude)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$userId, 'CIT-' . (1000 + $nextId), $old['address'], APP_CITY, $old['locality'] ?: null, $lat, $lng]
            );
            db()->commit();

            log_activity('register', 'user', $userId, $old['full_name'] . ' created a citizen account');
            $_SESSION['user_id'] = $userId;
            $_SESSION['role']     = 'citizen';
            $_SESSION['name']     = $old['full_name'];
            $_SESSION['email']    = $old['email'];
            session_regenerate_id(true);
            $_SESSION['flash'] = 'Welcome to ' . APP_SHORT . ', ' . $old['full_name'] . '! Your citizen ID is CIT-' . (1000 + $nextId) . '.';
            redirect('/citizen/dashboard.php');
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            $errors[] = 'Could not create the account right now. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Create account &middot; <?= e(APP_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" rel="stylesheet">
<link href="<?= e(APP_URL) ?>/assets/css/style.css" rel="stylesheet">
<style>
  .reg-wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 2rem 1rem;
              background: radial-gradient(900px 500px at 80% 0%, rgba(34,197,94,.16), transparent 60%), #f4f8f6; }
  .reg-card { width: 100%; max-width: 880px; }
</style>
</head>
<body>

<div class="reg-wrap">
  <div class="reg-card">
    <div class="text-center mb-4">
      <a href="<?= e(APP_URL) ?>/index.php" class="d-inline-flex align-items-center gap-2 text-decoration-none">
        <span class="brand-mark" style="width:40px;height:40px;border-radius:12px;display:inline-grid;place-items:center;background:linear-gradient(135deg,#22c55e,#15803d);color:#fff;font-size:1.1rem"><i class="bi bi-recycle"></i></span>
        <span class="fw-bold" style="color:var(--sw-ink);letter-spacing:-.02em"><?= e(APP_SHORT) ?></span>
      </a>
      <h2 class="fw-bold mt-3 mb-1" style="letter-spacing:-.02em">Create your citizen account</h2>
      <p class="text-muted mb-0" style="font-size:.9rem">Register once, then report waste and track every pickup.</p>
    </div>

    <?php if ($errors): ?>
      <div class="alert alert-danger border-0" style="border-radius:12px">
        <div class="fw-600 mb-1"><i class="bi bi-exclamation-octagon-fill me-2"></i>Please fix the following:</div>
        <ul class="mb-0 ps-4" style="font-size:.88rem">
          <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="post" class="card card-soft p-3 p-md-4">
      <input type="hidden" name="latitude"  id="latitude"  value="<?= e(input('latitude', '') ?? '') ?>">
      <input type="hidden" name="longitude" id="longitude" value="<?= e(input('longitude', '') ?? '') ?>">

      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label fw-600">Full name *</label>
          <input type="text" name="full_name" class="form-control" required
                 value="<?= e($old['full_name']) ?>" placeholder="e.g. Aarav Sharma">
        </div>
        <div class="col-md-6">
          <label class="form-label fw-600">Email address *</label>
          <input type="email" name="email" class="form-control" required
                 value="<?= e($old['email']) ?>" placeholder="you@example.com">
        </div>

        <div class="col-md-6">
          <label class="form-label fw-600">Phone number</label>
          <input type="tel" name="phone" class="form-control" value="<?= e($old['phone']) ?>"
                 placeholder="+91 98765 43210">
        </div>
        <div class="col-md-6">
          <label class="form-label fw-600">Locality / area</label>
          <input type="text" name="locality" class="form-control" value="<?= e($old['locality']) ?>"
                 placeholder="e.g. Baner">
        </div>

        <div class="col-12">
          <label class="form-label fw-600">Address *</label>
          <input type="text" name="address" class="form-control" required
                 value="<?= e($old['address']) ?>" placeholder="Flat / street, building name">
        </div>

        <div class="col-md-6">
          <label class="form-label fw-600">Password *</label>
          <input type="password" name="password" class="form-control" required minlength="6"
                 placeholder="At least 6 characters">
        </div>
        <div class="col-md-6">
          <label class="form-label fw-600">Confirm password *</label>
          <input type="password" name="password_confirm" class="form-control" required minlength="6"
                 placeholder="Repeat the password">
        </div>

        <div class="col-12">
          <label class="form-label fw-600 d-flex align-items-center gap-2">
            <i class="bi bi-geo-alt text-success"></i> Your location on the map
            <span class="text-muted fw-normal" style="font-size:.8rem">(used for faster pickups)</span>
          </label>
          <div id="regMap" style="height:240px;border-radius:14px;border:1px solid var(--sw-line)"></div>
          <div class="d-flex flex-wrap gap-2 align-items-center mt-2">
            <button type="button" class="btn btn-sm btn-outline-success" id="useGps">
              <i class="bi bi-crosshair me-1"></i>Use my current location
            </button>
            <span class="text-muted" id="coordsHint" style="font-size:.8rem">
              Click on the map to drop your pin.
            </span>
          </div>
        </div>

        <div class="col-12 d-flex flex-wrap gap-2 align-items-center justify-content-between pt-2"
             style="border-top:1px solid var(--sw-line)">
          <span class="text-muted" style="font-size:.8rem">
            <i class="bi bi-shield-check me-1 text-success"></i>We never store your password in plain text.
          </span>
          <div class="d-flex gap-2">
            <a href="<?= e(APP_URL) ?>/login.php" class="btn btn-light border">I already have an account</a>
            <button class="btn btn-success px-4"><i class="bi bi-person-plus me-2"></i>Create account</button>
          </div>
        </div>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(function () {
  const map = L.map('regMap').setView([19.0760, 72.8777], 12);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19, attribution: '&copy; OpenStreetMap contributors'
  }).addTo(map);

  const latEl = document.getElementById('latitude');
  const lngEl = document.getElementById('longitude');
  const hint  = document.getElementById('coordsHint');
  let marker  = null;

  function place(lat, lng) {
    if (marker) marker.setLatLng([lat, lng]); else marker = L.marker([lat, lng]).addTo(map);
    latEl.value = lat.toFixed(7);
    lngEl.value = lng.toFixed(7);
    hint.innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>Pinned at ' +
                    lat.toFixed(5) + ', ' + lng.toFixed(5) + '</span>';
  }

  map.on('click', e => place(e.latlng.lat, e.latlng.lng));

  document.getElementById('useGps').addEventListener('click', function () {
    if (!navigator.geolocation) { hint.textContent = 'Geolocation is not supported by this browser.'; return; }
    hint.textContent = 'Locating…';
    navigator.geolocation.getCurrentPosition(
      function (pos) { place(pos.coords.latitude, pos.coords.longitude); map.setView([pos.coords.latitude, pos.coords.longitude], 15); },
      function () { hint.textContent = 'Could not read your GPS position — click the map instead.'; }
    );
  });

  if (latEl.value && lngEl.value) place(parseFloat(latEl.value), parseFloat(lngEl.value));
})();
</script>
</body>
</html>
