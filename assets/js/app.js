/* =============================================================================
   Smart Waste Collection Optimizer  ::  shared front-end helpers
   Loaded on every dashboard page. No framework, just plain JS + fetch().
   ========================================================================== */
(function () {
  'use strict';

  /* ------------------------------------------------------------- toasts --- */
  const ToastEl = () => document.getElementById('liveToast');

  window.showToast = function (message, type = 'success', title = null) {
    const el = ToastEl();
    if (!el) { alert(message); return; }
    const badge = { success: 'bi-check-circle-fill', danger: 'bi-exclamation-octagon-fill',
                    warning: 'bi-exclamation-triangle-fill', info: 'bi-info-circle-fill' }[type] || 'bi-bell-fill';
    const color = { success: 'text-success', danger: 'text-danger',
                    warning: 'text-warning', info: 'text-primary' }[type] || 'text-success';

    el.querySelector('.toast-header i').className = 'bi ' + badge + ' me-2 ' + color;
    document.getElementById('toastTitle').textContent =
      title || { success: 'Success', danger: 'Oops', warning: 'Heads up', info: 'Info' }[type] || 'Done';
    document.getElementById('toastBody').textContent = message;

    bootstrap.Toast.getOrCreateInstance(el, { delay: 3800 }).show();
  };

  /* ------------------------------------------------------- fetch helpers -- */
  /**
   * POST JSON to an API endpoint.
   * Always sends the CSRF token and parses the {success,message,data} envelope.
   */
  window.apiPost = async function (path, payload = {}) {
    try {
      const res = await fetch(window.APP_URL + '/api/' + path, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': window.CSRF || ''
        },
        body: JSON.stringify(Object.assign({ csrf_token: window.CSRF || '' }, payload))
      });
      const json = await res.json().catch(() => ({ success: false, message: 'Unexpected server response.' }));
      if (!json.success) {
        showToast(json.message || 'Request failed.', 'danger');
        return null;
      }
      return json;
    } catch (err) {
      showToast('Network error: ' + err.message, 'danger');
      return null;
    }
  };

  /** GET JSON from an API endpoint. */
  window.apiGet = async function (path, params = {}) {
    const qs = new URLSearchParams(params).toString();
    try {
      const res = await fetch(window.APP_URL + '/api/' + path + (qs ? '?' + qs : ''), {
        headers: { 'Accept': 'application/json' }
      });
      const json = await res.json().catch(() => ({ success: false, message: 'Unexpected server response.' }));
      if (!json.success) {
        showToast(json.message || 'Request failed.', 'danger');
        return null;
      }
      return json;
    } catch (err) {
      showToast('Network error: ' + err.message, 'danger');
      return null;
    }
  };

  /* ------------------------------------------------------------ helpers --- */
  window.escapeHtml = function (str) {
    const d = document.createElement('div');
    d.textContent = str == null ? '' : String(str);
    return d.innerHTML;
  };

  window.fmtDateTime = function (iso) {
    if (!iso) return '—';
    const d = new Date(iso.replace(' ', 'T'));
    if (isNaN(d)) return iso;
    return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) +
           ' · ' + d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
  };

  /** Minutes -> "2h 15m" */
  window.fmtDuration = function (min) {
    if (min === null || min === undefined) return '—';
    if (min < 60) return min + 'm';
    const h = Math.floor(min / 60), m = min % 60;
    return m ? h + 'h ' + m + 'm' : h + 'h';
  };

  /** Priority level from a 0-100 score (mirrors includes/priority.php). */
  window.priorityLevel = function (score) {
    if (score >= 81) return 'critical';
    if (score >= 61) return 'high';
    if (score >= 31) return 'medium';
    return 'low';
  };

  /** Bootstrap badge markup for a priority level. */
  window.priorityBadge = function (level, score) {
    const m = {
      critical: ['Critical', 'danger'], high: ['High', 'warning'],
      medium:   ['Medium', 'warning'], low:    ['Low', 'success']
    }[level] || ['—', 'secondary'];
    return '<span class="badge bg-' + m[1] + '-subtle text-' + (level === 'high' ? 'warning' : m[1]) +
           '"><i class="bi bi-graph-up-arrow me-1"></i>' + m[0] +
           (score != null ? ' · ' + score + '/100' : '') + '</span>';
  };

  /* ------------------------------------------------------------- modals --- */
  /** Show a Bootstrap modal by element id. */
  window.openModal = function (id) {
    const el = document.getElementById(id);
    if (el) bootstrap.Modal.getOrCreateInstance(el).show();
  };
  window.closeModal = function (id) {
    const el = document.getElementById(id);
    if (el) bootstrap.Modal.getOrCreateInstance(el).hide();
  };

  /** Replace a container's HTML from an API response and re-bind handlers. */
  window.renderInto = function (selector, html) {
    const el = typeof selector === 'string' ? document.querySelector(selector) : selector;
    if (el) el.innerHTML = html;
  };

  /* ------------------------------------------------------ sidebar mobile -- */
  document.addEventListener('DOMContentLoaded', function () {
    const sidebar  = document.getElementById('appSidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    const toggle   = document.getElementById('sidebarToggle');
    if (!sidebar) return;

    function close() {
      sidebar.classList.remove('show');
      backdrop && backdrop.classList.remove('show');
    }
    toggle && toggle.addEventListener('click', function () {
      sidebar.classList.toggle('show');
      backdrop && backdrop.classList.toggle('show');
    });
    backdrop && backdrop.addEventListener('click', close);
    sidebar.querySelectorAll('a.nav-link').forEach(a => a.addEventListener('click', close));
  });

  /* ------------------------------------------------- auto-dismiss alerts -- */
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.alert-auto-dismiss').forEach(function (el) {
      setTimeout(() => {
        if (window.bootstrap) bootstrap.Alert.getOrCreateInstance(el).close();
      }, 6000);
    });
  });
})();
