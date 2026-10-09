<?php
/**
 * set-password.php
 * ---------------------------------------------------------------------------
 * Public, token-based password setup for worker login accounts.
 *
 * An admin generates a one-time link (api/admin/worker-accounts.php) and hands
 * it to the worker. The link carries a random token; only its sha256 hash is
 * stored in the database. Visiting the link lets the worker choose their OWN
 * password. The token is single-use and expires (see WORKER_SETUP_TTL_HOURS).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/worker_accounts.php';

$token = clean_str((string) input('token', ''), 128);
$user  = $token !== '' ? user_for_setup_token($token) : null;

$error = '';
$done  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string) input('password', '');
    $confirm  = (string) input('confirm', '');

    if (!$user) {
        $error = 'This setup link is invalid or has expired. Ask your officer for a new one.';
    } elseif (strlen($password) < WORKER_PASSWORD_MIN) {
        $error = 'Password must be at least ' . WORKER_PASSWORD_MIN . ' characters.';
    } elseif ($password !== $confirm) {
        $error = 'The two passwords do not match.';
    } else {
        $result = consume_setup_token($token, $password);
        if ($result['ok']) {
            $done = true;
        } else {
            $error = $result['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $done ? 'Password set' : 'Set your password' ?> &middot; <?= e(APP_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= e(APP_URL) ?>/assets/css/style.css" rel="stylesheet">
<style>
  .auth-wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 2rem; background: #f7faf9; }
  .auth-card { width: 100%; max-width: 440px; }
</style>
</head>
<body>

<div class="auth-wrap">
  <div class="auth-card">
    <div class="d-flex align-items-center gap-2 mb-4 justify-content-center">
      <span class="brand-mark" style="width:40px;height:40px;border-radius:12px;display:inline-grid;place-items:center;background:linear-gradient(135deg,#22c55e,#15803d);color:#fff;font-size:1.1rem">
        <i class="bi bi-recycle"></i>
      </span>
      <div>
        <div class="fw-bold" style="letter-spacing:-.02em"><?= e(APP_SHORT) ?></div>
        <div class="text-muted" style="font-size:.74rem"><?= e(APP_CITY) ?> Smart City</div>
      </div>
    </div>

    <div class="card border-0 shadow-sm" style="border-radius:16px">
      <div class="card-body p-4">

        <?php if ($done): ?>
          <div class="text-center">
            <i class="bi bi-check-circle-fill text-success" style="font-size:2.6rem"></i>
            <h4 class="fw-bold mt-3 mb-2">Password set</h4>
            <p class="text-muted mb-4">Your password is ready. You can now sign in with your email address.</p>
            <a href="<?= e(APP_URL) ?>/login.php" class="btn btn-success w-100 py-2 fw-600">
              <i class="bi bi-box-arrow-in-right me-2"></i>Go to sign in
            </a>
          </div>

        <?php elseif (!$user): ?>
          <div class="text-center">
            <i class="bi bi-exclamation-octagon-fill text-danger" style="font-size:2.6rem"></i>
            <h4 class="fw-bold mt-3 mb-2">Link not valid</h4>
            <p class="text-muted mb-4">
              <?= e($error ?: 'This setup link is invalid, expired or has already been used.') ?>
            </p>
            <a href="<?= e(APP_URL) ?>/login.php" class="btn btn-outline-secondary w-100 py-2 fw-600">
              <i class="bi bi-arrow-left me-1"></i>Back to sign in
            </a>
          </div>

        <?php else: ?>
          <h4 class="fw-bold mb-1" style="letter-spacing:-.02em">Set your password</h4>
          <p class="text-muted mb-4" style="font-size:.9rem">
            Setting the password for <strong><?= e($user['email']) ?></strong>
            <?php if (!empty($user['worker']['worker_code'])): ?>
              (worker <?= e($user['worker']['worker_code']) ?>)
            <?php endif; ?>.
          </p>

          <?php if ($error): ?>
            <div class="alert alert-danger border-0 d-flex gap-2 align-items-center" style="border-radius:12px">
              <i class="bi bi-exclamation-octagon-fill"></i><?= e($error) ?>
            </div>
          <?php endif; ?>

          <form method="post">
            <input type="hidden" name="token" value="<?= e($token) ?>">

            <div class="mb-3">
              <label class="form-label fw-600" for="password">New password</label>
              <div class="input-group">
                <span class="input-group-text bg-white border-end-0"><i class="bi bi-lock text-muted"></i></span>
                <input type="password" class="form-control border-start-0" id="password" name="password"
                       minlength="<?= WORKER_PASSWORD_MIN ?>" required autofocus placeholder="At least <?= WORKER_PASSWORD_MIN ?> characters">
              </div>
            </div>

            <div class="mb-4">
              <label class="form-label fw-600" for="confirm">Confirm password</label>
              <div class="input-group">
                <span class="input-group-text bg-white border-end-0"><i class="bi bi-lock-fill text-muted"></i></span>
                <input type="password" class="form-control border-start-0" id="confirm" name="confirm"
                       minlength="<?= WORKER_PASSWORD_MIN ?>" required placeholder="Re-enter the password">
              </div>
            </div>

            <button class="btn btn-success w-100 py-2 fw-600">
              <i class="bi bi-shield-check me-2"></i>Save password
            </button>
          </form>
        <?php endif; ?>

      </div>
    </div>

    <p class="text-center mt-3 mb-0" style="font-size:.78rem">
      <a href="<?= e(APP_URL) ?>/index.php" class="text-muted"><i class="bi bi-arrow-left me-1"></i>Back to home page</a>
    </p>
  </div>
</div>

</body>
</html>
