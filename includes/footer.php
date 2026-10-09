</main>

    <footer class="px-3 py-3 text-muted d-flex flex-wrap gap-2 justify-content-between"
            style="font-size:.76rem;border-top:1px solid var(--sw-line);background:#fff">
      <span>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?> &middot; <?= e(APP_CITY) ?> Municipal Corporation</span>
      <span>Leaflet &amp; OpenStreetMap &middot; Chart.js &middot; Bootstrap 5</span>
    </footer>
  </div><!-- /.app-main -->
</div><!-- /.app-shell -->

<!-- Toast notifications (used by every fetch() call in assets/js/app.js) -->
<div class="toast-container position-fixed bottom-0 end-0 p-3">
  <div id="liveToast" class="toast border-0 shadow" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="toast-header">
      <i class="bi bi-bell-fill me-2 text-success"></i>
      <strong class="me-auto" id="toastTitle">Done</strong>
      <button type="button" class="btn-close" data-bs-dismiss="toast"></button>
    </div>
    <div class="toast-body" id="toastBody">Saved successfully.</div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php foreach (($extraJs ?? []) as $js): ?>
<script src="<?= e($js) ?>"></script>
<?php endforeach; ?>
<script>
  // Absolute base URL of the project, handy for fetch() calls in every page.
  window.APP_URL = <?= json_encode(APP_URL) ?>;
  window.CSRF    = <?= json_encode(csrf_token()) ?>;
</script>
<script src="<?= e(APP_URL) ?>/assets/js/app.js"></script>
<?php foreach (($pageJs ?? []) as $js): ?>
<script src="<?= e($js) ?>"></script>
<?php endforeach; ?>
<?php if (!empty($inlineJs)): ?>
<script><?= $inlineJs ?></script>
<?php endif; ?>
</body>
</html>
