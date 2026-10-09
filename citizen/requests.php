<?php
/**
 * citizen/requests.php  —  list of the signed-in citizen's own requests
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/priority.php';
require_role('citizen');

$citizen = current_citizen() ?? [];
$cid     = (int) ($citizen['id'] ?? 0);

$filter = clean_str($_GET['status'] ?? 'all', 15);
$q      = clean_str($_GET['q'] ?? '', 80);

$where  = 'citizen_id = ?';
$params = [$cid];

if ($filter === 'open') {
    $where .= ' AND status NOT IN ("collected","verified","cancelled")';
} elseif (in_array($filter, ['pending', 'prioritized', 'assigned', 'scheduled',
                            'in_progress', 'collected', 'verified', 'cancelled'], true)) {
    $where .= ' AND status = ?';
    $params[] = $filter;
}
if ($q !== '') {
    $where .= ' AND (address LIKE ? OR landmark LIKE ? OR request_code LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}

$rows = db_all(
    "SELECT * FROM waste_requests WHERE $where ORDER BY created_at DESC",
    $params
);

$tabs = [
    'all'   => 'All',
    'open'  => 'Open',
    'collected' => 'Collected',
    'verified'  => 'Verified',
];

$pageTitle    = 'My Requests';
$pageSubtitle = count($rows) . ' request(s) found';
$activeNav    = 'reqs';

require __DIR__ . '/../includes/header.php';
?>

<div class="card card-soft mb-3">
  <div class="card-body d-flex flex-wrap gap-2 align-items-center justify-content-between">
    <ul class="nav nav-pills gap-1 mb-0">
      <?php foreach ($tabs as $key => $label): ?>
        <li class="nav-item">
          <a class="nav-link py-1 px-3 <?= $filter === $key ? 'active' : '' ?>"
             style="font-size:.82rem"
             href="<?= e(APP_URL . '/citizen/requests.php?status=' . $key . ($q ? '&q=' . urlencode($q) : '')) ?>">
            <?= e($label) ?>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>

    <form class="d-flex gap-2" method="get">
      <input type="hidden" name="status" value="<?= e($filter) ?>">
      <input type="search" name="q" class="form-control form-control-sm" style="min-width:210px"
             placeholder="Search address or ID" value="<?= e($q) ?>">
      <button class="btn btn-sm btn-outline-success"><i class="bi bi-search"></i></button>
    </form>
  </div>
</div>

<div class="card card-soft">
  <div class="card-body">
    <?php if (!$rows): ?>
      <div class="empty-state">
        <i class="bi bi-inbox"></i>
        <p class="mb-1 fw-600">Nothing here yet</p>
        <p class="text-muted mb-3" style="font-size:.86rem">
          <?= $q ? 'No request matches your search.' : 'You have not reported any waste yet.' ?>
        </p>
        <a href="<?= e(APP_URL) ?>/citizen/report.php" class="btn btn-success btn-sm">
          <i class="bi bi-megaphone me-1"></i>Report waste
        </a>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table align-middle sw-table table-clickable mb-0">
          <thead>
            <tr>
              <th>ID</th><th>Waste</th><th>Location</th><th>Qty</th>
              <th>Priority</th><th>Status</th><th>Reported</th><th class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r):
                $w = waste_meta($r['waste_type']); ?>
              <tr onclick="window.location.href='<?= e(APP_URL) ?>/citizen/track.php?id=<?= (int) $r['id'] ?>'">
                <td class="fw-600">#<?= (int) $r['id'] ?><br>
                  <span class="text-muted" style="font-size:.7rem"><?= e($r['request_code']) ?></span></td>
                <td><i class="bi bi-<?= e($w['icon']) ?> me-1" style="color:<?= e($w['color']) ?>"></i><?= e($w['label']) ?></td>
                <td>
                  <?= e($r['address']) ?>
                  <?php if ($r['landmark']): ?>
                    <br><span class="text-muted" style="font-size:.74rem">near <?= e($r['landmark']) ?></span>
                  <?php endif; ?>
                </td>
                <td class="text-capitalize"><?= e(quantity_options()[$r['quantity']] ?? $r['quantity']) ?></td>
                <td><?= priority_badge($r['priority_level'], (int) $r['priority_score']) ?></td>
                <td><?= status_badge($r['status']) ?></td>
                <td class="text-muted" style="font-size:.8rem"><?= e(fmt_dt($r['created_at'])) ?></td>
                <td class="text-end">
                  <a href="<?= e(APP_URL) ?>/citizen/track.php?id=<?= (int) $r['id'] ?>" class="btn btn-sm btn-outline-success">
                    <i class="bi bi-eye me-1"></i>Track
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php';
