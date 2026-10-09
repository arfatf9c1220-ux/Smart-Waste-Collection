<?php
/**
 * citizen/dashboard.php  —  citizen home
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/priority.php';
require_role('citizen');

$citizen = current_citizen() ?? [];
$cid     = (int) ($citizen['id'] ?? 0);

$pageTitle    = 'Citizen Dashboard';
$pageSubtitle = 'Report waste, track pickups and rate our service';
$activeNav    = 'home';

$mine = db_all(
    'SELECT * FROM waste_requests WHERE citizen_id = ? ORDER BY created_at DESC',
    [$cid]
);

$counts = ['total' => count($mine), 'open' => 0, 'collected' => 0, 'verified' => 0, 'rejected' => 0];
foreach ($mine as $r) {
    if ($r['status'] === 'collected')  $counts['collected']++;
    if ($r['status'] === 'verified')   $counts['verified']++;
    if ($r['status'] === 'cancelled')  $counts['rejected']++;
    if (!in_array($r['status'], ['collected', 'verified', 'cancelled'], true)) $counts['open']++;
}

$recent = array_slice($mine, 0, 5);

// Impact: verified pickups and the waste diverted from the wrong bin.
$impactKg   = array_reduce($mine, fn($c, $r) => $c + (float) ($r['quantity'] === 'bulk' ? 90 : ($r['quantity'] === 'large' ? 45 : ($r['quantity'] === 'medium' ? 18 : 8))), 0.0);
$ecoPoints  = count(array_filter($mine, fn($r) => $r['status'] === 'verified')) * 25;

$extraJs = ['https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js'];

require __DIR__ . '/../includes/header.php';
?>

<!-- ------------------------------------------------------------- KPI row -->
<div class="row g-3 mb-4">
  <div class="col-6 col-xl-3">
    <div class="kpi">
      <div class="kpi-label">My Reports</div>
      <div class="kpi-value"><?= $counts['total'] ?></div>
      <div class="kpi-foot"><i class="bi bi-list-ul me-1"></i>All time</div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="kpi">
      <div class="kpi-label">In Progress</div>
      <div class="kpi-value text-primary"><?= $counts['open'] ?></div>
      <div class="kpi-foot"><i class="bi bi-hourglass-split me-1"></i>Awaiting collection</div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="kpi">
      <div class="kpi-label">Collected</div>
      <div class="kpi-value text-success"><?= $counts['collected'] + $counts['verified'] ?></div>
      <div class="kpi-foot"><i class="bi bi-check2-circle me-1"></i><?= $counts['verified'] ?> verified</div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="kpi">
      <div class="kpi-label">Eco Points</div>
      <div class="kpi-value text-warning"><?= $ecoPoints ?></div>
      <div class="kpi-foot"><i class="bi bi-stars me-1"></i>Keep your city clean</div>
    </div>
  </div>
</div>

<div class="row g-3">
  <!-- ------------------------------------------------------- quick report -->
  <div class="col-lg-4">
    <div class="card card-soft h-100">
      <div class="card-body">
        <h5 class="section-title">Spotted waste?</h5>
        <p class="text-muted" style="font-size:.88rem">
          Send a report with a photo and a map pin — our team is notified instantly
          and the request is scored automatically.
        </p>
        <a href="<?= e(APP_URL) ?>/citizen/report.php" class="btn btn-success w-100 py-2 mb-2">
          <i class="bi bi-megaphone me-2"></i>Report Waste Now
        </a>
        <a href="<?= e(APP_URL) ?>/citizen/requests.php" class="btn btn-light border w-100">
          <i class="bi bi-list-ul me-2"></i>View My Requests
        </a>

        <hr>
        <div class="d-flex justify-content-between align-items-center mb-1">
          <span class="fw-600" style="font-size:.85rem">Your contribution</span>
          <span class="pill bg-success-subtle text-success"><?= round($impactKg) ?> kg logged</span>
        </div>
        <div class="progress" style="height:8px">
          <div class="progress-bar bg-success" role="progressbar" style="width:<?= min(100, $ecoPoints) ?>%"
               aria-valuenow="<?= min(100, $ecoPoints) ?>" aria-valuemin="0" aria-valuemax="100"></div>
        </div>
        <p class="text-muted mt-2 mb-0" style="font-size:.78rem">
          25 eco points per verified collection. Verified reports unlock priority review.
        </p>
      </div>
    </div>
  </div>

  <!-- ------------------------------------------------------ recent requests -->
  <div class="col-lg-8">
    <div class="card card-soft h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h5 class="section-title mb-0">Recent Requests</h5>
          <a href="<?= e(APP_URL) ?>/citizen/requests.php" class="btn btn-sm btn-light border">View all</a>
        </div>

        <?php if (!$recent): ?>
          <div class="empty-state">
            <i class="bi bi-inbox"></i>
            <p class="mb-1 fw-600">No reports yet</p>
            <p class="text-muted mb-3" style="font-size:.86rem">When you spot waste, report it in under a minute.</p>
            <a href="<?= e(APP_URL) ?>/citizen/report.php" class="btn btn-success btn-sm">
              <i class="bi bi-plus-lg me-1"></i>Create your first report
            </a>
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table align-middle sw-table mb-0">
              <thead>
                <tr>
                  <th>Request</th><th>Waste</th><th>Priority</th><th>Status</th><th>Reported</th><th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($recent as $r):
                    $w = waste_meta($r['waste_type']); ?>
                  <tr>
                    <td class="fw-600">#<?= (int) $r['id'] ?><br><span class="text-muted" style="font-size:.72rem"><?= e($r['request_code']) ?></span></td>
                    <td><i class="bi bi-<?= e($w['icon']) ?> me-1" style="color:<?= e($w['color']) ?>"></i><?= e($w['label']) ?></td>
                    <td><?= priority_badge($r['priority_level'], (int) $r['priority_score']) ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td class="text-muted" style="font-size:.8rem"><?= e(time_ago($r['created_at'])) ?></td>
                    <td class="text-end">
                      <a href="<?= e(APP_URL) ?>/citizen/track.php?id=<?= (int) $r['id'] ?>" class="btn btn-sm btn-outline-success">
                        <i class="bi bi-geo-alt me-1"></i>Track
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
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php';
