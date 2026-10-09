<?php
/**
 * admin/activity.php  —  audit trail of everything that happened in the system
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$filter = clean_str($_GET['action'] ?? '', 40);
$role   = clean_str($_GET['role'] ?? '', 15);

$where = [];
$params = [];
if ($filter !== '') { $where[] = 'l.action = ?';    $params[] = $filter; }
if ($role   !== '') { $where[] = 'l.actor_role = ?'; $params[] = $role; }
$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$logs = db_all(
    "SELECT l.*, u.full_name
       FROM activity_logs l
       LEFT JOIN users u ON u.id = l.user_id
       $sqlWhere
      ORDER BY l.id DESC
      LIMIT 200",
    $params
);

$actions = db_all('SELECT DISTINCT action FROM activity_logs ORDER BY action');
$counts  = [
    'all'   => (int) db_value('SELECT COUNT(*) FROM activity_logs', [], 0),
    'today' => (int) db_value('SELECT COUNT(*) FROM activity_logs WHERE DATE(created_at) = CURDATE()', [], 0),
    'admins' => (int) db_value('SELECT COUNT(*) FROM activity_logs WHERE actor_role = "admin"', [], 0),
    'workers' => (int) db_value('SELECT COUNT(*) FROM activity_logs WHERE actor_role = "worker"', [], 0),
];

$ICON = [
    'login' => 'bi-box-arrow-in-right', 'logout' => 'bi-box-arrow-right',
    'register' => 'bi-person-plus', 'create_request' => 'bi-megaphone',
    'assign' => 'bi-person-check', 'plan_created' => 'bi-signpost-split',
    'plan_start' => 'bi-play-circle', 'plan_complete' => 'bi-check2-circle',
    'plan_cancel' => 'bi-x-circle', 'plan_assign' => 'bi-person-badge',
    'plan_reorder' => 'bi-arrow-down-up', 'collect' => 'bi-truck',
    'start_collection' => 'bi-play-fill', 'arrive' => 'bi-geo-alt-fill',
    'verify' => 'bi-patch-check', 'verify_reject' => 'bi-patch-exclamation',
    'reject' => 'bi-slash-circle', 'priority_override' => 'bi-graph-up-arrow',
    'status_change' => 'bi-arrow-repeat', 'cancel' => 'bi-x-circle',
    'hotspot_recompute' => 'bi-fire', 'upload_before' => 'bi-camera',
    'upload_after' => 'bi-camera-fill', 'upload_waste' => 'bi-image',
];
$COLOR = [
    'verify' => 'success', 'collect' => 'success', 'plan_complete' => 'success',
    'reject' => 'danger', 'verify_reject' => 'danger', 'cancel' => 'danger', 'plan_cancel' => 'danger',
    'assign' => 'primary', 'plan_created' => 'primary', 'create_request' => 'info',
    'hotspot_recompute' => 'warning', 'priority_override' => 'warning',
];

$pageTitle    = 'Activity Log';
$pageSubtitle = 'Audit trail — ' . count($logs) . ' most recent events';
$activeNav    = 'activity';

require __DIR__ . '/../includes/header.php';
?>

<div class="row g-3 mb-3">
  <?php foreach ([
      ['Events logged', $counts['all'], 'bi-clock-history', 'text-success'],
      ['Today', $counts['today'], 'bi-calendar-check', 'text-primary'],
      ['By officers', $counts['admins'], 'bi-shield-check', 'text-warning'],
      ['By crews', $counts['workers'], 'bi-truck', 'text-info'],
  ] as [$label, $value, $icon, $color]): ?>
    <div class="col-6 col-xl-3">
      <div class="kpi">
        <div class="kpi-label"><?= e($label) ?></div>
        <div class="kpi-value <?= $color ?>"><?= (int) $value ?></div>
        <div class="kpi-foot"><i class="bi <?= e($icon) ?> me-1"></i>from activity_logs</div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card card-soft">
  <div class="card-body">
    <form class="row g-2 align-items-end mb-3" method="get">
      <div class="col-md-4">
        <label class="form-label mb-1 text-muted" style="font-size:.74rem">Action</label>
        <select name="action" class="form-select form-select-sm">
          <option value="">Any action</option>
          <?php foreach ($actions as $a): ?>
            <option value="<?= e($a['action']) ?>" <?= $filter === $a['action'] ? 'selected' : '' ?>>
              <?= e(ucwords(str_replace('_', ' ', $a['action']))) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label mb-1 text-muted" style="font-size:.74rem">Actor role</label>
        <select name="role" class="form-select form-select-sm">
          <option value="">Any role</option>
          <?php foreach (['admin', 'worker', 'citizen', 'system'] as $r): ?>
            <option value="<?= $r ?>" <?= $role === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2 d-flex gap-2">
        <button class="btn btn-sm btn-success w-100"><i class="bi bi-funnel me-1"></i>Apply</button>
        <a href="<?= e(APP_URL) ?>/admin/activity.php" class="btn btn-sm btn-light border">Reset</a>
      </div>
    </form>

    <?php if (!$logs): ?>
      <div class="empty-state">
        <i class="bi bi-clock-history"></i>
        <p class="mb-0 fw-600">No events match these filters.</p>
      </div>
    <?php else: ?>
      <div class="timeline">
        <?php foreach ($logs as $l):
            $icon  = $ICON[$l['action']] ?? 'bi-dot';
            $color = $COLOR[$l['action']] ?? 'secondary';
            $roleColor = ['admin' => 'primary', 'worker' => 'success', 'citizen' => 'info'][$l['actor_role']] ?? 'secondary'; ?>
          <div class="tl-item done">
            <div class="d-flex flex-wrap align-items-center gap-2">
              <span class="kpi-icon bg-<?= $color ?>-subtle"><i class="bi <?= e($icon) ?> text-<?= $color ?>"></i></span>
              <div class="flex-grow-1 min-w-0">
                <div class="fw-600" style="font-size:.86rem"><?= e($l['description'] ?: ucwords(str_replace('_', ' ', $l['action']))) ?></div>
                <div class="text-muted" style="font-size:.74rem">
                  <?= e($l['full_name'] ?: 'System') ?>
                  <span class="pill bg-<?= $roleColor ?>-subtle text-<?= $roleColor ?> ms-1"><?= e($l['actor_role'] ?: 'system') ?></span>
                  <?php if ($l['entity_type']): ?>
                    &middot; <?= e($l['entity_type']) ?><?= $l['entity_id'] ? ' #' . (int) $l['entity_id'] : '' ?>
                  <?php endif; ?>
                  <?php if ($l['ip_address']): ?>
                    &middot; <?= e($l['ip_address']) ?>
                  <?php endif; ?>
                </div>
              </div>
              <span class="text-muted text-nowrap" style="font-size:.74rem"><?= e(time_ago($l['created_at'])) ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php';
