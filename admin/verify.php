<?php
/**
 * admin/verify.php  —  proof review desk (before/after comparison)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/priority.php';
require_role('admin');

$focus = (int) ($_GET['focus'] ?? 0);

$queue = db_all(
    'SELECT r.*, u.full_name AS citizen_name, u.phone AS citizen_phone,
            wu.full_name AS worker_name, w.worker_code, t.team_name,
            v.vehicle_number, p.plan_code
       FROM waste_requests r
       LEFT JOIN users u            ON u.id = r.user_id
       LEFT JOIN workers w          ON w.id = r.assigned_worker_id
       LEFT JOIN users wu           ON wu.id = w.user_id
       LEFT JOIN teams t            ON t.id = r.assigned_team_id
       LEFT JOIN vehicles v         ON v.id = r.vehicle_id
       LEFT JOIN collection_plans p ON p.id = r.collection_plan_id
      WHERE r.status = "collected"
      ORDER BY r.collected_at ASC
      LIMIT 40'
);

$verified = db_all(
    'SELECT r.*, u.full_name AS citizen_name, wu.full_name AS worker_name
       FROM waste_requests r
       LEFT JOIN users u  ON u.id = r.user_id
       LEFT JOIN workers w ON w.id = r.assigned_worker_id
       LEFT JOIN users wu ON wu.id = w.user_id
      WHERE r.status = "verified"
      ORDER BY r.verified_at DESC
      LIMIT 10'
);

$hasProof = static function (?string $before, ?string $after): bool {
    return $before !== null && $before !== '' && $after !== null && $after !== '';
};

$pageTitle    = 'Proof Verification';
$pageSubtitle = 'Compare before/after photos and close the loop with the citizen';
$activeNav    = 'verify';

require __DIR__ . '/../includes/header.php';
?>

<div class="row g-3">
  <div class="col-xl-8">
    <div class="card card-soft">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h5 class="section-title mb-0">Awaiting review (<?= count($queue) ?>)</h5>
          <span class="text-muted" style="font-size:.78rem">
            Oldest first &middot; a request cannot close without both proof photos
          </span>
        </div>

        <?php if (!$queue): ?>
          <div class="empty-state">
            <i class="bi bi-patch-check"></i>
            <p class="mb-1 fw-600">Verification queue is empty</p>
            <p class="text-muted mb-0" style="font-size:.86rem">
              Every collected pickup has been reviewed.
            </p>
          </div>
        <?php else: foreach ($queue as $r):
            $w  = waste_meta($r['waste_type']);
            $ok = $hasProof($r['before_image'], $r['after_image']); ?>
          <div class="card mb-3 <?= $focus === (int) $r['id'] ? 'border-success' : '' ?>"
               style="border-radius:14px" id="proof-<?= (int) $r['id'] ?>">
            <div class="card-body">
              <div class="d-flex flex-wrap align-items-start gap-2 mb-2">
                <span class="kpi-icon"><i class="bi bi-<?= e($w['icon']) ?>" style="color:<?= e($w['color']) ?>"></i></span>
                <div class="flex-grow-1 min-w-0">
                  <div class="fw-600">#<?= (int) $r['id'] ?> · <?= e($w['label']) ?>
                    <span class="text-muted fw-normal"><?= e(quantity_options()[$r['quantity']] ?? $r['quantity']) ?></span>
                  </div>
                  <div class="text-muted text-truncate" style="font-size:.8rem">
                    <?= e($r['address']) ?> <?= $r['landmark'] ? '· near ' . e($r['landmark']) : '' ?>
                  </div>
                  <div class="text-muted" style="font-size:.74rem">
                    Reported by <?= e($r['citizen_name'] ?? '—') ?> ·
                    collected by <?= e($r['worker_name'] ?? '—') ?>
                    (<?= e($r['worker_code'] ?? '') ?><?= $r['team_name'] ? ', ' . e($r['team_name']) : '' ?>) ·
                    <?= e(time_ago($r['collected_at'])) ?>
                    <?php if ($r['plan_code']): ?> · <?= e($r['plan_code']) ?><?php endif; ?>
                  </div>
                </div>
                <?= priority_badge($r['priority_level'], (int) $r['priority_score']) ?>
              </div>

              <!-- citizen photo + proof pair -->
              <div class="row g-3">
                <div class="col-md-3">
                  <div class="before-after">
                    <?php if ($r['image_path']): ?>
                      <img src="<?= e(image_url($r['image_path'])) ?>" class="gallery-img" alt="Citizen report">
                    <?php else: ?>
                      <div class="gallery-img d-flex align-items-center justify-content-center text-muted">
                        <i class="bi bi-image fs-3 opacity-50"></i>
                      </div>
                    <?php endif; ?>
                    <span class="ba-label">CITIZEN</span>
                  </div>
                </div>
                <div class="col-md-9">
                  <div class="proof-pair">
                    <div class="before-after">
                      <?php if ($r['before_image']): ?>
                        <a href="<?= e(image_url($r['before_image'])) ?>" target="_blank">
                          <img src="<?= e(image_url($r['before_image'])) ?>" class="gallery-img" alt="Before">
                        </a>
                      <?php else: ?>
                        <div class="gallery-img d-flex align-items-center justify-content-center text-muted">
                          <i class="bi bi-camera fs-3 opacity-50"></i>
                        </div>
                      <?php endif; ?>
                      <span class="ba-label">BEFORE</span>
                    </div>
                    <div class="before-after">
                      <?php if ($r['after_image']): ?>
                        <a href="<?= e(image_url($r['after_image'])) ?>" target="_blank">
                          <img src="<?= e(image_url($r['after_image'])) ?>" class="gallery-img" alt="After">
                        </a>
                      <?php else: ?>
                        <div class="gallery-img d-flex align-items-center justify-content-center text-muted">
                          <i class="bi bi-camera fs-3 opacity-50"></i>
                        </div>
                      <?php endif; ?>
                      <span class="ba-label">AFTER</span>
                    </div>
                  </div>

                  <?php if (!$ok): ?>
                    <div class="alert alert-danger border-0 mt-2 mb-0" style="border-radius:12px;font-size:.82rem">
                      <i class="bi bi-exclamation-octagon me-1"></i>
                      Missing proof photo — approval is blocked until the team re-uploads.
                    </div>
                  <?php endif; ?>

                  <div class="d-flex flex-wrap gap-2 mt-3">
                    <button class="btn btn-sm btn-success js-verify" data-id="<?= (int) $r['id'] ?>"
                            data-decision="approve" <?= $ok ? '' : 'disabled' ?>>
                      <i class="bi bi-patch-check me-1"></i>Approve &amp; close
                    </button>
                    <button class="btn btn-sm btn-outline-danger js-verify" data-id="<?= (int) $r['id'] ?>"
                            data-decision="reject">
                      <i class="bi bi-patch-exclamation me-1"></i>Reject proof
                    </button>
                    <?php if ($r['citizen_phone']): ?>
                      <a class="btn btn-sm btn-light border" href="tel:<?= e($r['citizen_phone']) ?>">
                        <i class="bi bi-telephone me-1"></i><?= e($r['citizen_phone']) ?>
                      </a>
                    <?php endif; ?>
                    <a class="btn btn-sm btn-light border" href="<?= e(APP_URL) ?>/admin/request.php?id=<?= (int) $r['id'] ?>">
                      Full detail
                    </a>
                  </div>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <div class="col-xl-4">
    <div class="card card-soft mb-3">
      <div class="card-body">
        <h5 class="section-title">How verification works</h5>
        <div class="timeline">
          <div class="tl-item done">
            <div class="fw-600" style="font-size:.86rem"><i class="bi bi-camera me-1 text-success"></i>Worker uploads proof</div>
            <div class="text-muted" style="font-size:.78rem">
              BEFORE on arrival and AFTER once collected. Both are mandatory.
            </div>
          </div>
          <div class="tl-item current">
            <div class="fw-600" style="font-size:.86rem"><i class="bi bi-search me-1 text-warning"></i>Officer reviews</div>
            <div class="text-muted" style="font-size:.78rem">
              Compare with the citizen photo and the description.
            </div>
          </div>
          <div class="tl-item">
            <div class="fw-600" style="font-size:.86rem"><i class="bi bi-patch-check me-1 text-muted"></i>Request closes</div>
            <div class="text-muted" style="font-size:.78rem">
              Citizen is notified and can rate the service.
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="card card-soft">
      <div class="card-body">
        <h5 class="section-title">Recently verified</h5>
        <?php if (!$verified): ?>
          <p class="text-muted mb-0" style="font-size:.85rem">Nothing verified yet.</p>
        <?php else: foreach ($verified as $r):
            $w = waste_meta($r['waste_type']); ?>
          <div class="d-flex align-items-center gap-2 py-2 border-bottom">
            <img src="<?= e(image_url($r['after_image'] ?: $r['image_path'])) ?>" class="img-thumb-sm" alt="">
            <div class="flex-grow-1 min-w-0">
              <div class="fw-600 text-truncate" style="font-size:.82rem">#<?= (int) $r['id'] ?> · <?= e($w['label']) ?></div>
              <div class="text-muted text-truncate" style="font-size:.72rem">
                <?= e($r['worker_name'] ?? '—') ?> · <?= e(time_ago($r['verified_at'])) ?>
              </div>
            </div>
            <?php if ($r['citizen_rating']): ?>
              <span class="text-warning">
                <i class="bi bi-star-fill"></i><?= (int) $r['citizen_rating'] ?>
              </span>
            <?php endif; ?>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
</div>

<?php
$inlineJs = <<<'JS'
document.querySelectorAll('.js-verify').forEach(function (btn) {
  btn.addEventListener('click', async function () {
    const decision = btn.dataset.decision;
    let note = '';
    if (decision === 'approve') {
      if (!confirm('Approve this proof and close request #' + btn.dataset.id + '?')) return;
    } else {
      note = prompt('What is wrong with the proof? The team will be asked to re-upload.');
      if (note === null) return;
      if (!note.trim()) { showToast('A short reason is required.', 'warning'); return; }
    }
    btn.disabled = true;
    const res = await apiPost('admin/verify.php', {
      id: parseInt(btn.dataset.id, 10), decision: decision, note: note
    });
    if (res) {
      const card = document.getElementById('proof-' + btn.dataset.id);
      if (card) card.remove();
    } else btn.disabled = false;
  });
});
JS;

require __DIR__ . '/../includes/footer.php';
