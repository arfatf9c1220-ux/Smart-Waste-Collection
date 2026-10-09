<?php
/**
 * login.php  —  sign in for all three roles
 */
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect(dashboard_for_role(current_role()));
}

$error    = '';
$email    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = clean_str(input('email', ''), 150);
    $password = (string) input('password', '');

    if ($email === '' || $password === '') {
        $error = 'Please enter your email address and password.';
    } elseif (attempt_login($email, $password)) {
        redirect(dashboard_for_role(current_role()));
    } else {
        $error = 'Invalid email or password. Please try again.';
    }
}

$demoAccounts = [];
$demoWorkers = [];
if (APP_DEBUG) {
  $demoAccounts = [
    ['admin@smartwaste.gov', 'admin@123', 'Municipal Authority', 'bi-building-gear', 'Dashboard · assignments · plans · analytics'],
    ['citizen1@smartwaste.gov', 'citizen@123', 'Citizen', 'bi-person', 'Report waste · track · rate'],
  ];

  try {
    $workers = db_all(
      'SELECT u.full_name, u.email, u.password_hash, u.password_reset_token
         FROM workers w
         JOIN users u ON u.id = w.user_id
        WHERE w.status <> "removed" AND u.role = "worker" AND u.status = "active"
        ORDER BY u.full_name'
    );
    foreach ($workers as $worker) {
      if (empty($worker['password_reset_token'])
        && password_verify('worker@123', (string) $worker['password_hash'])) {
        $demoWorkers[] = $worker;
      }
    }
  } catch (Throwable $e) {
    $demoWorkers = [];
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Log in &middot; <?= e(APP_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= e(APP_URL) ?>/assets/css/style.css" rel="stylesheet">
<style>
  .auth-wrap { min-height: 100vh; display: flex; }
  .auth-side {
    flex: 1 1 46%;
    background:
      radial-gradient(900px 500px at 20% 15%, rgba(34,197,94,.35), transparent 60%),
      linear-gradient(150deg, #06231a 0%, #0b3a2a 55%, #065f46 100%);
    color: #d9ece3; padding: 3rem; display: flex; flex-direction: column; justify-content: space-between;
  }
  .auth-side h2 { color: #fff; letter-spacing: -.02em; }
  .auth-form { flex: 1 1 54%; display: flex; align-items: center; justify-content: center; padding: 2rem; background: #f7faf9; }
  .auth-card { width: 100%; max-width: 430px; }
  .demo-row { border: 1px solid var(--sw-line); border-radius: 12px; padding: .6rem .75rem; cursor: pointer; transition: .15s; }
  .demo-row:hover { border-color: var(--sw-green); background: #f2fbf6; }
  @media (max-width: 900px) { .auth-side { display: none; } }
</style>
</head>
<body>

<div class="auth-wrap">
  <!-- ---------------------------------------------------------- left panel -->
  <div class="auth-side">
    <div>
      <div class="d-flex align-items-center gap-2 mb-4">
        <span class="brand-mark" style="width:40px;height:40px;border-radius:12px;display:inline-grid;place-items:center;background:linear-gradient(135deg,#22c55e,#15803d);color:#fff;font-size:1.1rem">
          <i class="bi bi-recycle"></i>
        </span>
        <div>
          <div class="fw-bold text-white" style="letter-spacing:-.02em"><?= e(APP_SHORT) ?></div>
          <div style="font-size:.74rem"><?= e(APP_CITY) ?> Smart City</div>
        </div>
      </div>
      <h2 class="fw-bold mb-3" style="font-size:2.1rem;line-height:1.15">
        From waste complaint<br>to verified collection.</h2>
      <p class="mb-4" style="max-width:430px">
        Priority scoring, smart team assignment, optimised routes and photo proof —
        every step of the sanitation chain in one place.
      </p>
      <div class="d-flex flex-column gap-2" style="max-width:430px">
        <div class="d-flex gap-2 align-items-start"><i class="bi bi-graph-up-arrow text-success"></i><span>Requests scored 0-100 with a transparent breakdown</span></div>
        <div class="d-flex gap-2 align-items-start"><i class="bi bi-person-gear text-success"></i><span>Best-fit team ranked on distance, load and capability</span></div>
        <div class="d-flex gap-2 align-items-start"><i class="bi bi-signpost-split text-success"></i><span>Stops sequenced into an optimised daily route</span></div>
        <div class="d-flex gap-2 align-items-start"><i class="bi bi-camera text-success"></i><span>Before/after proof enforced before any pickup is closed</span></div>
      </div>
    </div>
    <div style="font-size:.78rem">&copy; <?= date('Y') ?> <?= e(APP_NAME) ?> &middot; Built with PHP 8, MySQL, Bootstrap 5, Leaflet &amp; Chart.js</div>
  </div>

  <!-- --------------------------------------------------------- right panel -->
  <div class="auth-form">
    <div class="auth-card">
      <div class="d-lg-none d-flex align-items-center gap-2 mb-4">
        <span class="brand-mark" style="width:36px;height:36px;border-radius:10px;display:inline-grid;place-items:center;background:linear-gradient(135deg,#22c55e,#15803d);color:#fff"><i class="bi bi-recycle"></i></span>
        <span class="fw-bold"><?= e(APP_SHORT) ?></span>
      </div>

      <h3 class="fw-bold mb-1" style="letter-spacing:-.02em">Welcome back</h3>
      <p class="text-muted mb-4" style="font-size:.9rem">Sign in to your <?= e(APP_NAME) ?> workspace.</p>

      <?php if ($error): ?>
        <div class="alert alert-danger border-0 d-flex gap-2 align-items-center" style="border-radius:12px">
          <i class="bi bi-exclamation-octagon-fill"></i><?= e($error) ?>
        </div>
      <?php endif; ?>

      <form method="post" autocomplete="on">
        <div class="mb-3">
          <label class="form-label fw-600" for="email">Email address</label>
          <div class="input-group">
            <span class="input-group-text bg-white border-end-0"><i class="bi bi-envelope text-muted"></i></span>
            <input type="email" class="form-control border-start-0" id="email" name="email"
                   value="<?= e($email) ?>" required autofocus
                   placeholder="you@example.com">
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label fw-600" for="password">Password</label>
          <div class="input-group">
            <span class="input-group-text bg-white border-end-0"><i class="bi bi-lock text-muted"></i></span>
            <input type="password" class="form-control border-start-0" id="password" name="password"
                   required placeholder="••••••••">
          </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-3" style="font-size:.85rem">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="remember" checked>
            <label class="form-check-label" for="remember">Keep me signed in</label>
          </div>
          <span class="text-muted">Forgot password? Ask your officer.</span>
        </div>

        <button class="btn btn-success w-100 py-2 fw-600">
          <i class="bi bi-box-arrow-in-right me-2"></i>Sign in
        </button>
      </form>

      <p class="text-center text-muted mt-3 mb-4" style="font-size:.88rem">
        New to the system? <a href="<?= e(APP_URL) ?>/register.php" class="fw-600 text-success">Create a citizen account</a>
      </p>

      <?php if ($demoAccounts || $demoWorkers): ?>
        <div class="text-center text-muted mb-2" style="font-size:.78rem">ONE-CLICK DEMO LOGIN</div>
        <?php foreach ($demoAccounts as [$mail, $pass, $role, $icon, $desc]): ?>
          <div class="demo-row mb-2 d-flex align-items-center gap-2" data-email="<?= e($mail) ?>" data-pass="<?= e($pass) ?>">
            <i class="bi <?= e($icon) ?> text-success"></i>
            <div class="flex-grow-1">
              <div class="fw-600" style="font-size:.86rem"><?= e($role) ?></div>
              <div class="text-muted" style="font-size:.74rem"><?= e($desc) ?></div>
            </div>
            <i class="bi bi-arrow-right text-muted"></i>
          </div>
        <?php endforeach; ?>

        <?php if ($demoWorkers): ?>
          <div class="mb-2">
            <label class="form-label fw-600 mb-1" for="demoWorker">Choose a worker</label>
            <div class="input-group">
              <select class="form-select" id="demoWorker">
                <option value="">Select worker…</option>
                <?php foreach ($demoWorkers as $worker): ?>
                  <option value="<?= e($worker['email']) ?>"><?= e($worker['full_name']) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-outline-success" type="button" id="demoWorkerLogin" disabled>
                <i class="bi bi-truck me-1"></i>Sign in
              </button>
            </div>
          </div>
        <?php endif; ?>
      <?php endif; ?>

      <p class="text-center mt-3 mb-0" style="font-size:.78rem">
        <a href="<?= e(APP_URL) ?>/index.php" class="text-muted"><i class="bi bi-arrow-left me-1"></i>Back to home page</a>
      </p>
    </div>
  </div>
</div>

<script>
  // One-click demo fill.
  document.querySelectorAll('.demo-row').forEach(function (row) {
    row.addEventListener('click', function () {
      document.getElementById('email').value    = row.dataset.email;
      document.getElementById('password').value = row.dataset.pass;
      document.querySelector('form button[type=submit], form button').click();
    });
  });

  const workerSelect = document.getElementById('demoWorker');
  const workerLogin = document.getElementById('demoWorkerLogin');
  if (workerSelect && workerLogin) {
    workerSelect.addEventListener('change', function () {
      workerLogin.disabled = !workerSelect.value;
    });
    workerLogin.addEventListener('click', function () {
      if (!workerSelect.value) return;
      document.getElementById('email').value = workerSelect.value;
      document.getElementById('password').value = 'worker@123';
      document.querySelector('form').requestSubmit();
    });
  }
</script>
</body>
</html>
