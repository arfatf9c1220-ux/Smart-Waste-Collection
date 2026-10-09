<?php
/**
 * admin/requests.php  —  every request in the city, with filters
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/priority.php';
require_role('admin');

$status  = clean_str($_GET['status'] ?? '', 20);
$level   = clean_str($_GET['priority'] ?? '', 10);
$waste   = clean_str($_GET['waste_type'] ?? '', 20);
$search  = clean_str($_GET['q'] ?? '', 80);
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

$where = [];
$params = [];
if ($status !== '') { $where[] = 'r.status = ?';           $params[] = $status; }
if ($level  !== '') { $where[] = 'r.priority_level = ?';   $params[] = $level; }
if ($waste  !== '') { $where[] = 'r.waste_type = ?';      $params[] = $waste; }
if ($search !== '') {
    $where[] = '(r.address LIKE ? OR r.landmark LIKE ? OR r.request_code LIKE ? OR r.description LIKE ? OR u.full_name LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int) db_value("SELECT COUNT(*) FROM waste_requests r LEFT JOIN users u ON u.id = r.user_id $sqlWhere",
                        $params, 0);
$pages = max(1, (int) ceil($total / $perPage));
$page  = min($page, $pages);
$offset = ($page - 1) * $perPage;

$rows = db_all(
    "SELECT r.*, u.full_name AS citizen_name, u.phone AS citizen_phone,
            wu.full_name AS worker_name, w.worker_code, t.team_name,
            v.vehicle_number, p.plan_code, r.plan_stop_number
       FROM waste_requests r
       LEFT JOIN users u           ON u.id = r.user_id
       LEFT JOIN workers w         ON w.id = r.assigned_worker_id
       LEFT JOIN users wu          ON wu.id = w.user_id
       LEFT JOIN teams t           ON t.id = r.assigned_team_id
       LEFT JOIN vehicles v        ON v.id = r.vehicle_id
       LEFT JOIN collection_plans p ON p.id = r.collection_plan_id
       $sqlWhere
      ORDER BY r.priority_score DESC, r.created_at DESC
      LIMIT $perPage OFFSET $offset",
    $params
);

$counts = [];
foreach (db_all('SELECT status, COUNT(*) c FROM waste_requests GROUP BY status') as $row) {
    $counts[$row['status']] = (int) $row['c'];
}

$statusTabs = [
    ''         => 'All (' . array_sum($counts) . ')',
    'pending'  => 'Pending (' . ($counts['pending'] ?? 0) . ')',
    'prioritized' => 'Prioritised (' . ($counts['prioritized'] ?? 0) . ')',
    'assigned' => 'Assigned (' . ($counts['assigned'] ?? 0) . ')',
    'scheduled' => 'Scheduled (' . ($counts['scheduled'] ?? 0) . ')',
    'in_progress' => 'In progress (' . ($counts['in_progress'] ?? 0) . ')',
    'collected' => 'To verify (' . ($counts['collected'] ?? 0) . ')',
    'verified' => 'Verified (' . ($counts['verified'] ?? 0) . ')',
    'cancelled' => 'Closed (' . ($counts['cancelled'] ?? 0) . ')',
];

$qs = static function (array $over = []) use ($status, $level, $waste, $search): string {
    $p = array_filter(array_merge(
        ['status' => $status, 'priority' => $level, 'waste_type' => $waste, 'q' => $search], $over
    ), static fn($v) => $v !== '' && $v !== null);
    return $p ? '?' . http_build_query($p) : '';
};

$pageTitle    = 'All Requests';
$pageSubtitle = $total . ' request(s) match your filters';
$activeNav    = 'requests';

require __DIR__ . '/../includes/header.php';
?>

<div class="card card-soft mb-3">
  <div class="card-body">
    <ul class="nav nav-pills flex-wrap gap-1 mb-3">
      <?php foreach ($statusTabs as $key => $label): ?>
        <li class="nav-item">
          <a class="nav-link py-1 px-3 <?= $status === $key ? 'active' : '' ?>" style="font-size:.8rem"
             href="<?= e(APP_URL . '/admin/requests.php' . $qs(['status' => $key, 'page' => null])) ?>">
            <?= e($label) ?>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>

    <form class="row g-2 align-items-end" method="get">
      <input type="hidden" name="status" value="<?= e($status) ?>">
      <div class="col-md-3">
        <label class="form-label mb-1 text-muted" style="font-size:.74rem">Priority</label>
        <select name="priority" class="form-select form-select-sm">
          <option value="">Any priority</option>
          <?php foreach (['critical', 'high', 'medium', 'low'] as $lv): ?>
            <option value="<?= $lv ?>" <?= $level === $lv ? 'selected' : '' ?>><?= ucfirst($lv) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label mb-1 text-muted" style="font-size:.74rem">Waste category</label>
        <select name="waste_type" class="form-select form-select-sm">
          <option value="">Any category</option>
          <?php foreach (waste_options() as $key => $label): ?>
            <option value="<?= $key ?>" <?= $waste === $key ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label mb-1 text-muted" style="font-size:.74rem">Search</label>
        <input type="search" name="q" class="form-control form-control-sm"
               placeholder="Address, landmark, ID, citizen…" value="<?= e($search) ?>">
      </div>
      <div class="col-md-2 d-flex gap-2">
        <button class="btn btn-sm btn-success w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
        <a href="<?= e(APP_URL) ?>/admin/requests.php" class="btn btn-sm btn-light border">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card card-soft">
  <div class="card-body">
    <?php if (!$rows): ?>
      <div class="empty-state">
        <i class="bi bi-search"></i>
        <p class="mb-0 fw-600">No requests match these filters.</p>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table align-middle sw-table mb-0">
          <thead>
            <tr>
              <th>ID</th><th>Waste</th><th>Location</th><th>Citizen</th><th>Team</th>
              <th>Priority</th><th>Status</th><th>Reported</th><th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r):
                $w = waste_meta($r['waste_type']); ?>
              <tr>
                <td class="fw-600">#<?= (int) $r['id'] ?><br>
                  <span class="text-muted" style="font-size:.7rem"><?= e($r['request_code']) ?></span></td>
                <td>
                  <i class="bi bi-<?= e($w['icon']) ?> me-1" style="color:<?= e($w['color']) ?>"></i><?= e($w['label']) ?>
                  <div class="text-muted" style="font-size:.72rem"><?= e(quantity_options()[$r['quantity']] ?? $r['quantity']) ?></div>
                </td>
                <td style="max-width:240px">
                  <div class="text-truncate"><?= e($r['address']) ?></div>
                  <div class="text-muted text-truncate" style="font-size:.72rem"><?= e($r['landmark'] ?: '—') ?></div>
                </td>
                <td class="text-truncate" style="max-width:150px">
                  <?= e($r['citizen_name'] ?? '—') ?>
                  <?php if ($r['citizen_phone']): ?>
                    <div class="text-muted" style="font-size:.72rem"><?= e($r['citizen_phone']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="text-truncate" style="max-width:150px">
                  <?php if ($r['worker_name']): ?>
                    <?= e($r['worker_name']) ?>
                    <div class="text-muted" style="font-size:.72rem">
                      <?= e($r['team_name'] ?: '') ?><?= $r['plan_code'] ? ' · ' . e($r['plan_code']) : '' ?>
                    </div>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td><?= priority_badge($r['priority_level'], (int) $r['priority_score']) ?></td>
                <td><?= status_badge($r['status']) ?></td>
                <td class="text-muted" style="font-size:.78rem"><?= e(time_ago($r['created_at'])) ?></td>
                <td class="text-end text-nowrap">
                  <?php if (in_array($r['status'], ['pending', 'prioritized'], true)): ?>
                    <a href="<?= e(APP_URL) ?>/admin/assignments.php?request_id=<?= (int) $r['id'] ?>#pick"
                       class="btn btn-sm btn-success" title="Assign a team">Assign</a>
                  <?php endif; ?>
                  <a href="<?= e(APP_URL) ?>/admin/request.php?id=<?= (int) $r['id'] ?>"
                     class="btn btn-sm btn-light border" title="Open details">
                    <i class="bi bi-eye"></i>
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($pages > 1): ?>
        <nav class="mt-3">
          <ul class="pagination pagination-sm justify-content-center mb-0">
            <?php for ($i = 1; $i <= $pages; $i++): ?>
              <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link" href="<?= e(APP_URL . '/admin/requests.php' . $qs(['page' => $i])) ?>"><?= $i ?></a>
              </li>
            <?php endfor; ?>
          </ul>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php';
