<?php
/**
 * admin/workers.php  —  workforce, teams and vehicles
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/worker_accounts.php';
require_role('admin');

// Includes login-account state per worker (never hides a worker without an account).
$workers = workers_with_account_state();

$teams = db_all(
    'SELECT t.*, COUNT(w.id) AS members
       FROM teams t
       LEFT JOIN workers w ON w.team_id = t.id AND w.status <> "removed"
      GROUP BY t.id
      ORDER BY t.team_name'
);

$vehicles = db_all(
    'SELECT v.*, t.team_name
       FROM vehicles v
       LEFT JOIN teams t ON t.id = v.team_id
      ORDER BY v.vehicle_number'
);

$statusColor = ['available' => 'success', 'busy' => 'warning', 'offline' => 'secondary', 'on_leave' => 'info'];

$acctCounts = ['active' => 0, 'awaiting_setup' => 0, 'inactive' => 0, 'no_account' => 0];
foreach ($workers as $w) {
    $acctCounts[$w['account']['key']] = ($acctCounts[$w['account']['key']] ?? 0) + 1;
}
$withoutLogin = $acctCounts['no_account'];
$notReady     = $acctCounts['no_account'] + $acctCounts['awaiting_setup'] + $acctCounts['inactive'];

$pageTitle    = 'Workers & Teams';
$pageSubtitle = count($workers) . ' active team(s) · ' . count($teams) . ' crew(s) · ' . count($vehicles) . ' vehicle(s)';
$activeNav    = 'workers';

require __DIR__ . '/../includes/header.php';
?>

<div class="row g-3 mb-3">
  <?php
  $kpis = [
      ['Available now', count(array_filter($workers, fn($w) => $w['status'] === 'available')), 'bi-check-circle', 'success'],
      ['On route',       count(array_filter($workers, fn($w) => $w['status'] === 'busy')),        'bi-truck', 'warning'],
      ['Open pickups',   array_sum(array_map(fn($w) => (int) $w['open_load'], $workers)),           'bi-list-check', 'primary'],
      ['Collected total',array_sum(array_map(fn($w) => (int) $w['done_count'], $workers)),          'bi-patch-check', 'success'],
  ];
  foreach ($kpis as [$label, $value, $icon, $color]): ?>
    <div class="col-6 col-xl-3">
      <div class="kpi">
        <div class="kpi-label"><?= e($label) ?></div>
        <div class="kpi-value text-<?= $color ?>"><?= (int) $value ?></div>
        <div class="kpi-foot"><i class="bi <?= e($icon) ?> me-1"></i>live from the field</div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card card-soft mb-3 border-0" style="background:linear-gradient(180deg,#f2fbf6,#ffffff)">
  <div class="card-body d-flex flex-wrap gap-3 align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-3">
      <span class="kpi-icon"><i class="bi bi-person-badge"></i></span>
      <div>
        <div class="fw-600">Worker logins</div>
        <div class="text-muted" style="font-size:.8rem">
          <span class="text-success fw-600"><?= (int) $acctCounts['active'] ?></span> active ·
          <span class="text-warning fw-600"><?= (int) $acctCounts['awaiting_setup'] ?></span> awaiting setup ·
          <span class="text-secondary fw-600"><?= (int) $acctCounts['inactive'] ?></span> inactive ·
          <span class="text-danger fw-600"><?= (int) $acctCounts['no_account'] ?></span> without login
        </div>
      </div>
    </div>
    <div class="d-flex gap-2">
      <button type="button" class="btn btn-success fw-600" id="provisionAllBtn">
        <i class="bi bi-magic me-2"></i>Enable logins for all workers
      </button>
      <button type="button" class="btn btn-outline-secondary" id="showSetupLinksBtn" <?= $notReady ? '' : 'disabled' ?>>
        <i class="bi bi-link-45deg me-1"></i>View pending links
      </button>
    </div>
  </div>
</div>

<div class="card card-soft mb-3">
  <div class="card-body">
    <h5 class="section-title">Field teams</h5>
    <div class="table-responsive">
      <table class="table align-middle sw-table mb-0">
        <thead>
          <tr><th>Worker</th><th>Team</th><th>Vehicle</th><th>Status</th><th>Load</th>
              <th>Performance</th><th>Rating</th><th>Contact</th><th>Login</th><th>Last fix</th><th class="text-end"></th></tr>
        </thead>
        <tbody>
          <?php foreach ($workers as $w):
              $pct = $w['max_load'] ? round($w['current_load'] / $w['max_load'] * 100) : 0;
              $col = $statusColor[$w['status']] ?? 'secondary';
              $dispName = $w['full_name'] ?: ('Worker ' . $w['worker_code']);
              $acct = $w['account']; ?>
            <tr>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <span class="avatar-circle"><?= e(mb_strtoupper(mb_substr($dispName, 0, 1))) ?></span>
                  <div>
                    <div class="fw-600" style="font-size:.86rem"><?= e($dispName) ?></div>
                    <div class="text-muted" style="font-size:.72rem"><?= e($w['worker_code']) ?></div>
                  </div>
                </div>
              </td>
              <td style="font-size:.82rem"><?= e($w['team_name'] ?: '—') ?>
                <div class="text-muted text-truncate" style="font-size:.72rem;max-width:150px">
                  <?= e(str_replace(',', ', ', $w['team_waste_types'] ?: 'general')) ?>
                </div>
              </td>
              <td style="font-size:.8rem"><?= e($w['vehicle_number'] ?: '—') ?>
                <div class="text-muted" style="font-size:.72rem">
                  <?= e($w['vehicle_type'] ?: '') ?><?= $w['capacity_kg'] ? ' · ' . (int) $w['capacity_kg'] . 'kg' : '' ?>
                </div>
              </td>
              <td><span class="pill bg-<?= $col ?>-subtle text-<?= $col ?>"><?= e(ucfirst($w['status'])) ?></span></td>
              <td style="min-width:120px">
                <div class="d-flex justify-content-between" style="font-size:.74rem">
                  <span><?= (int) $w['current_load'] ?>/<?= (int) $w['max_load'] ?></span>
                  <span class="text-muted"><?= $pct ?>%</span>
                </div>
                <div class="progress progress-thin">
                  <div class="progress-bar <?= $pct >= 90 ? 'bg-danger' : ($pct >= 60 ? 'bg-warning' : 'bg-success') ?>"
                       style="width:<?= min(100, $pct) ?>%"></div>
                </div>
                <div class="text-muted" style="font-size:.7rem"><?= (int) $w['open_load'] ?> open pickup(s)</div>
              </td>
              <td style="font-size:.82rem">
                <span class="text-success fw-600"><?= (int) $w['done_count'] ?></span> collected
                <div class="text-muted" style="font-size:.7rem"><?= (int) $w['total_collected'] ?> all time</div>
              </td>
              <td style="font-size:.82rem">
                <?php if ($w['avg_rating']): ?>
                  <span class="text-warning"><i class="bi bi-star-fill"></i> <?= round((float) $w['avg_rating'], 1) ?></span>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td style="font-size:.78rem">
                <?= e($w['phone'] ?: '—') ?>
                <div class="text-muted text-truncate" style="font-size:.7rem;max-width:140px"><?= e($w['email'] ?: '—') ?></div>
              </td>
              <td style="font-size:.76rem">
                <span class="pill bg-<?= e($acct['color']) ?>-subtle text-<?= e($acct['color']) ?>"><?= e($acct['label']) ?></span>
                <div class="text-muted text-truncate" style="font-size:.7rem;max-width:160px"
                     title="<?= e($acct['reason'] ?: (string) $acct['email']) ?>">
                  <?= e($acct['email'] ?: ($acct['reason'] ?: '—')) ?>
                </div>
                <div class="d-flex flex-wrap gap-1 mt-1">
                  <?php if ($acct['key'] === 'no_account'): ?>
                    <button class="btn btn-sm btn-outline-success js-acct" data-action="create" data-worker="<?= (int) $w['id'] ?>">
                      <i class="bi bi-person-plus"></i> Create
                    </button>
                  <?php elseif ($acct['key'] === 'inactive'): ?>
                    <button class="btn btn-sm btn-outline-success js-acct" data-action="activate" data-worker="<?= (int) $w['id'] ?>">
                      <i class="bi bi-unlock"></i> Activate
                    </button>
                    <button class="btn btn-sm btn-outline-primary js-acct" data-action="reset" data-worker="<?= (int) $w['id'] ?>">
                      <i class="bi bi-link-45deg"></i> Reset
                    </button>
                  <?php else: ?>
                    <button class="btn btn-sm btn-outline-primary js-acct" data-action="reset" data-worker="<?= (int) $w['id'] ?>">
                      <i class="bi bi-link-45deg"></i> Reset
                    </button>
                    <button class="btn btn-sm btn-outline-secondary js-acct" data-action="deactivate" data-worker="<?= (int) $w['id'] ?>">
                      <i class="bi bi-slash-circle"></i> Disable
                    </button>
                  <?php endif; ?>
                </div>
              </td>
              <td class="text-muted" style="font-size:.74rem">
                <?= $w['latitude']
                    ? number_format((float) $w['latitude'], 4) . '<br>' . number_format((float) $w['longitude'], 4)
                    : 'no fix' ?>
              </td>
              <td class="text-end">
                <a href="<?= e(APP_URL) ?>/admin/assignments.php" class="btn btn-sm btn-light border" title="Assign work">
                  <i class="bi bi-person-gear"></i>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card card-soft h-100">
      <div class="card-body">
        <h5 class="section-title">Crews</h5>
        <?php foreach ($teams as $t): ?>
          <div class="d-flex align-items-center gap-2 py-2 border-bottom">
            <span class="kpi-icon"><i class="bi bi-people"></i></span>
            <div class="flex-grow-1 min-w-0">
              <div class="fw-600" style="font-size:.86rem"><?= e($t['team_name']) ?>
                <span class="text-muted fw-normal">· <?= e($t['team_code']) ?></span></div>
              <div class="text-muted text-truncate" style="font-size:.74rem">
                Handles: <?= e(str_replace(',', ', ', $t['waste_types'] ?: 'general')) ?>
              </div>
            </div>
            <span class="pill bg-secondary-subtle text-secondary"><?= (int) $t['members'] ?> member(s)</span>
            <span class="pill bg-light border text-muted">max <?= (int) $t['max_load'] ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card card-soft h-100">
      <div class="card-body">
        <h5 class="section-title">Fleet</h5>
        <?php foreach ($vehicles as $v):
            $vcol = ['available' => 'success', 'in_use' => 'warning', 'maintenance' => 'danger'][$v['vehicle_status']] ?? 'secondary'; ?>
          <div class="d-flex align-items-center gap-2 py-2 border-bottom">
            <span class="kpi-icon"><i class="bi bi-truck"></i></span>
            <div class="flex-grow-1 min-w-0">
              <div class="fw-600" style="font-size:.86rem"><?= e($v['vehicle_number']) ?></div>
              <div class="text-muted" style="font-size:.74rem">
                <?= e($v['vehicle_type']) ?> · <?= (int) $v['capacity_kg'] ?> kg ·
                <?= e($v['team_name'] ?: 'unassigned') ?>
              </div>
            </div>
            <span class="pill bg-<?= $vcol ?>-subtle text-<?= $vcol ?>"><?= e(ucfirst(str_replace('_', ' ', $v['vehicle_status']))) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="linksModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content" style="border-radius:16px;border:0">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-link-45deg me-2"></i>One-time password setup links</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="linksModalBody">
        <p class="text-muted mb-0">Nothing yet — create or reset a worker login first.</p>
      </div>
      <div class="modal-footer">
        <span class="text-muted me-auto" style="font-size:.78rem">
          <i class="bi bi-info-circle me-1"></i>Links are shown once and expire automatically. The worker sets their own password.
        </span>
        <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<?php
$inlineJs = <<<'JS'
(function () {
  const modalBody = document.getElementById('linksModalBody');
  let lastLinks = [];

  function renderLinks(items, note) {
    lastLinks = items || [];
    if (!lastLinks.length) {
      modalBody.innerHTML = '<p class="text-muted mb-0">' + escapeHtml(note || 'No new links to show.') + '</p>';
      return;
    }
    modalBody.innerHTML = lastLinks.map(function (l) {
      return '<div class="border rounded-3 p-3 mb-2">'
        + '<div class="d-flex justify-content-between align-items-center gap-2">'
        + '<div><div class="fw-600">' + escapeHtml(l.title) + '</div>'
        + '<div class="text-muted" style="font-size:.78rem">' + escapeHtml(l.subtitle || '') + '</div></div>'
        + '<button class="btn btn-sm btn-outline-success" data-copy="' + escapeHtml(l.url) + '">'
        + '<i class="bi bi-clipboard"></i> Copy link</button></div>'
        + '<div class="mt-2"><code style="font-size:.72rem;word-break:break-all">' + escapeHtml(l.url) + '</code></div>'
        + '</div>';
    }).join('');
  }

  document.addEventListener('click', function (ev) {
    const copyBtn = ev.target.closest('[data-copy]');
    if (copyBtn) {
      const url = copyBtn.getAttribute('data-copy');
      if (navigator.clipboard) {
        navigator.clipboard.writeText(url).then(function () {
          showToast('Setup link copied to clipboard.', 'success');
        });
      } else {
        window.prompt('Copy this setup link:', url);
      }
    }
  });

  async function runAction(action, workerId) {
    const res = await apiPost('admin/worker-accounts.php', { action: action, worker_id: workerId });
    if (!res) return;
    showToast(res.message || 'Done.', 'success');

    if (res.data && res.data.setup_url) {
      renderLinks([{
        title: 'Worker login ready',
        subtitle: res.data.email,
        url: res.data.setup_url
      }]);
      openModal('linksModal');
    }
    setTimeout(function () { window.location.reload(); }, res.data && res.data.setup_url ? 4000 : 700);
  }

  document.querySelectorAll('.js-acct').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const action = btn.getAttribute('data-action');
      const workerId = parseInt(btn.getAttribute('data-worker'), 10);
      const labels = { create: 'Create login', activate: 'Activate login', deactivate: 'Disable login', reset: 'Create reset link' };
      if ((action === 'deactivate') && !window.confirm('Disable this worker login? Their work history stays intact.')) return;
      btn.disabled = true;
      runAction(action, workerId);
    });
  });

  const provisionBtn = document.getElementById('provisionAllBtn');
  if (provisionBtn) {
    provisionBtn.addEventListener('click', async function () {
      provisionBtn.disabled = true;
      const res = await apiPost('admin/worker-accounts.php', { action: 'provision' });
      provisionBtn.disabled = false;
      if (!res) return;
      showToast(res.message || 'Provisioning complete.', 'success');
      const created = ((res.data && res.data.items) || []).filter(function (i) { return i.setup_url; });
      renderLinks(created.map(function (i) {
        return { title: 'Worker ' + i.worker_code, subtitle: i.email, url: i.setup_url };
      }), res.message);
      openModal('linksModal');
    });
  }

  const showLinksBtn = document.getElementById('showSetupLinksBtn');
  if (showLinksBtn) {
    showLinksBtn.addEventListener('click', function () {
      renderLinks(lastLinks, 'No new links yet. Use “Enable logins for all workers” to create them.');
      openModal('linksModal');
    });
  }
})();
JS;

require __DIR__ . '/../includes/footer.php';
