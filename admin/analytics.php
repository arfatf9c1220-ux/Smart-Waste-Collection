<?php
/**
 * admin/analytics.php  —  charts, timings and trends
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$days  = max(7, min(180, (int) ($_GET['days'] ?? 30)));
$range = [7 => '7 days', 30 => '30 days', 90 => '90 days', 180 => '6 months'];

$pageTitle    = 'Analytics';
$pageSubtitle = 'Waste volume, priority mix, crew performance and service speed';
$activeNav    = 'analytics';
$extraJs      = ['https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js'];
$bodyClass    = '';

require __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap gap-2 mb-3" data-days="<?= (int) $days ?>">
  <div class="btn-group btn-group-sm">
    <?php foreach ($range as $value => $label): ?>
      <a class="btn btn-<?= $days === $value ? 'success' : 'light border' ?>"
         href="<?= e(APP_URL . '/admin/analytics.php?days=' . $value) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
  <button class="btn btn-sm btn-light border" onclick="window.print()">
    <i class="bi bi-printer me-1"></i>Print report
  </button>
  <span class="text-muted ms-auto" style="font-size:.8rem">
    <i class="bi bi-info-circle me-1"></i>Every number is computed live from MySQL
  </span>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-xl-3">
    <div class="kpi">
      <div class="kpi-label">Avg Response</div>
      <div class="kpi-value text-info" id="kpiResponse">–</div>
      <div class="kpi-foot"><i class="bi bi-stopwatch me-1"></i>report &rarr; assigned</div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="kpi">
      <div class="kpi-label">Avg Collection</div>
      <div class="kpi-value text-info" id="kpiCollect">–</div>
      <div class="kpi-foot"><i class="bi bi-clock-history me-1"></i>report &rarr; collected</div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="kpi">
      <div class="kpi-label">Avg Verification</div>
      <div class="kpi-value text-success" id="kpiVerify">–</div>
      <div class="kpi-foot"><i class="bi bi-patch-check me-1"></i>collected &rarr; verified</div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="kpi">
      <div class="kpi-label">Reports in range</div>
      <div class="kpi-value" id="kpiTotal">–</div>
      <div class="kpi-foot"><i class="bi bi-calendar-range me-1"></i><?= (int) $days ?> day window</div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-xl-8">
    <div class="card card-soft h-100">
      <div class="card-body">
        <h5 class="section-title">Volume over time</h5>
        <div style="height:300px"><canvas id="chartVolume"></canvas></div>
      </div>
    </div>
  </div>
  <div class="col-xl-4">
    <div class="card card-soft h-100">
      <div class="card-body">
        <h5 class="section-title">Waste categories</h5>
        <div style="height:300px"><canvas id="chartWaste"></canvas></div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-xl-4">
    <div class="card card-soft h-100">
      <div class="card-body">
        <h5 class="section-title">Priority mix</h5>
        <div style="height:230px"><canvas id="chartPriority"></canvas></div>
      </div>
    </div>
  </div>
  <div class="col-xl-4">
    <div class="card card-soft h-100">
      <div class="card-body">
        <h5 class="section-title">Pipeline status</h5>
        <div style="height:230px"><canvas id="chartStatus"></canvas></div>
      </div>
    </div>
  </div>
  <div class="col-xl-4">
    <div class="card card-soft h-100">
      <div class="card-body">
        <h5 class="section-title">Crew output (completed)</h5>
        <div style="height:230px"><canvas id="chartWorkers"></canvas></div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-xl-7">
    <div class="card card-soft h-100">
      <div class="card-body">
        <h5 class="section-title">Where the waste is</h5>
        <div class="table-responsive">
          <table class="table align-middle sw-table mb-0">
            <thead>
              <tr><th>Place</th><th>Reports</th><th>Resolved</th><th>Avg score</th><th style="width:130px">Share</th></tr>
            </thead>
            <tbody id="locBody">
              <tr><td colspan="5" class="text-center text-muted py-4">
                <div class="spinner-border spinner-border-sm"></div>
              </td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
  <div class="col-xl-5">
    <div class="card card-soft h-100">
      <div class="card-body">
        <h5 class="section-title">Busiest days</h5>
        <div style="height:230px"><canvas id="chartWeekday"></canvas></div>
        <hr>
        <h6 class="section-title">How the engine works</h6>
        <ul class="text-muted mb-0" style="font-size:.8rem;padding-left:1.1rem">
          <li>Priority score = severity 30 + quantity 20 + location risk 20 + waiting age 15 + nearby reports 15.</li>
          <li>Teams are ranked on distance 40, availability 25, workload 20 and equipment 15.</li>
          <li>Stops are ordered by nearest-neighbour routing, then ETAs and distance are recalculated.</li>
          <li>A pickup can only close once BEFORE and AFTER proof photos exist and an officer approves.</li>
        </ul>
      </div>
    </div>
  </div>
</div>

<?php
$inlineJs = <<<'JS'
(async function () {
  const res = await apiGet('analytics/charts.php', { days: document.querySelector('[data-days]').dataset.days });
  if (!res) return;
  const d = res.data;

  Chart.defaults.font.family = 'inherit';
  Chart.defaults.color = '#5b6b66';

  const total = d.over_time.total.reduce((a, b) => a + b, 0);
  document.getElementById('kpiTotal').textContent = total;
  document.getElementById('kpiResponse').textContent = d.timings.avg_response_label;
  document.getElementById('kpiCollect').textContent = d.timings.avg_collection_label;
  document.getElementById('kpiVerify').textContent = d.timings.avg_verification_label;

  /* 1. volume over time */
  new Chart(document.getElementById('chartVolume'), {
    type: 'line',
    data: {
      labels: d.over_time.labels,
      datasets: [
        { label: 'Reported', data: d.over_time.total, borderColor: '#0d6efd',
          backgroundColor: 'rgba(13,110,253,.12)', fill: true, tension: .32, pointRadius: 2.5 },
        { label: 'Completed', data: d.over_time.completed, borderColor: '#16a34a',
          backgroundColor: 'rgba(22,163,74,.10)', fill: true, tension: .32, pointRadius: 2.5 }
      ]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: { legend: { labels: { boxWidth: 12 } } },
      scales: { y: { beginAtZero: true, grid: { color: '#eef1f0' } }, x: { grid: { display: false } } }
    }
  });

  /* 2. waste categories */
  new Chart(document.getElementById('chartWaste'), {
    type: 'doughnut',
    data: { labels: d.by_waste_type.labels,
            datasets: [{ data: d.by_waste_type.data, backgroundColor: d.by_waste_type.colors, borderWidth: 0 }] },
    options: { cutout: '58%', responsive: true, maintainAspectRatio: false,
               plugins: { legend: { position: 'bottom', labels: { boxWidth: 11, font: { size: 11 } } } } }
  });

  /* 3. priority mix */
  new Chart(document.getElementById('chartPriority'), {
    type: 'bar',
    data: { labels: d.by_priority.labels,
            datasets: [{ label: 'Requests', data: d.by_priority.data,
                         backgroundColor: d.by_priority.colors, borderRadius: 6 }] },
    options: { responsive: true, maintainAspectRatio: false,
               plugins: { legend: { display: false } },
               scales: { y: { beginAtZero: true, grid: { color: '#eef1f0' } }, x: { grid: { display: false } } } }
  });

  /* 4. pipeline status */
  new Chart(document.getElementById('chartStatus'), {
    type: 'doughnut',
    data: { labels: d.status_split.labels,
            datasets: [{ data: d.status_split.data, backgroundColor: d.status_split.colors, borderWidth: 0 }] },
    options: { cutout: '58%', responsive: true, maintainAspectRatio: false,
               plugins: { legend: { position: 'bottom', labels: { boxWidth: 11, font: { size: 11 } } } } }
  });

  /* 5. crew output */
  new Chart(document.getElementById('chartWorkers'), {
    type: 'bar',
    data: { labels: d.by_worker.labels,
            datasets: [{ label: 'Completed', data: d.by_worker.data,
                         backgroundColor: d.by_worker.colors, borderRadius: 6 }] },
    options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false,
               plugins: { legend: { display: false } },
               scales: { x: { beginAtZero: true, grid: { color: '#eef1f0' } }, y: { grid: { display: false } } } }
  });

  /* 6. busiest days */
  new Chart(document.getElementById('chartWeekday'), {
    type: 'bar',
    data: {
      labels: (d.weekday || []).map(w => String(w.day).slice(0, 3)),
      datasets: [{ label: 'Reports', data: (d.weekday || []).map(w => w.count),
                   backgroundColor: '#16a34a', borderRadius: 6 }]
    },
    options: { responsive: true, maintainAspectRatio: false,
               plugins: { legend: { display: false } },
               scales: { y: { beginAtZero: true, grid: { color: '#eef1f0' } }, x: { grid: { display: false } } } }
  });

  /* 7. location table */
  const maxReports = Math.max.apply(null, d.locations.map(l => l.reports).concat([1]));
  renderInto(document.getElementById('locBody'), d.locations.map(function (l) {
    const pct = Math.round(l.reports / maxReports * 100);
    return '<tr>' +
      '<td class="fw-600" style="font-size:.86rem">' + escapeHtml(l.place) + '</td>' +
      '<td>' + l.reports + '</td>' +
      '<td class="text-success">' + l.resolved + '</td>' +
      '<td>' + l.avg_score + '</td>' +
      '<td><div class="progress progress-thin"><div class="progress-bar bg-warning" style="width:' + pct + '%"></div></div>' +
        '<span class="text-muted" style="font-size:.7rem">' + pct + '%</span></td>' +
    '</tr>';
  }).join('') || '<tr><td colspan="5" class="text-center text-muted py-4">No data in this range.</td></tr>');
})();
JS;

require __DIR__ . '/../includes/footer.php';
