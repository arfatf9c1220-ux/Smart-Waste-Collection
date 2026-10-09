/* =============================================================================
   Smart Waste Collection Optimizer  ::  Leaflet + OpenStreetMap helpers
   No Google Maps, no API key. Everything here is reusable across
   admin/map.php, admin/hotspots.php, citizen/track.php, worker/mission.php.
   ========================================================================== */
(function () {
  'use strict';

  const OSM = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
  const ATTRIB = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';

  /** Marker colours: red critical / orange high / yellow medium / green low / blue done. */
  const COLORS = {
    critical: '#dc3545', high: '#fd7e14', medium: '#ffc107',
    low: '#198754', completed: '#0d6efd', pending: '#6c757d'
  };
  const HOTSPOT_COLORS = { high: '#dc3545', medium: '#fd7e14', low: '#ffc107' };

  window.SWCOLORS = COLORS;

  window.markerColor = function (req) {
    if (req.status === 'collected' || req.status === 'verified') return COLORS.completed;
    const s = parseInt(req.priority_score || 0, 10);
    if (s >= 81) return COLORS.critical;
    if (s >= 61) return COLORS.high;
    if (s >= 31) return COLORS.medium;
    return COLORS.low;
  };

  /** Small teardrop divIcon (CSS lives in assets/css/style.css). */
  window.pinIcon = function (color, text, extraClass) {
    return L.divIcon({
      className: '',
      html: '<div class="map-pin ' + (extraClass || '') + '" style="background:' + color + '">' +
            '<span>' + (text == null ? '' : text) + '</span></div>',
      iconSize: [30, 30], iconAnchor: [15, 28], popupAnchor: [0, -26]
    });
  };

  /** Circular icon for hotspots (dashed border = predictive, not a real pickup). */
  window.hotspotIcon = function (color, score) {
    return L.divIcon({
      className: '',
      html: '<div class="map-pin hotspot" style="background:' + color + '33;border-color:' + color + '">' +
            '<span style="color:' + color + '">' + score + '</span></div>',
      iconSize: [40, 40], iconAnchor: [20, 20], popupAnchor: [0, -20]
    });
  };

  /** Square icon for vehicles / workers. */
  window.workerIcon = function (color, text) {
    return L.divIcon({
      className: '',
      html: '<div class="map-pin worker" style="background:' + color + '"><span>' + text + '</span></div>',
      iconSize: [32, 32], iconAnchor: [16, 16], popupAnchor: [0, -16]
    });
  };

  /** Create a base map. Options: {center, zoom, el} */
  window.createMap = function (elId, opts) {
    opts = opts || {};
    const map = L.map(elId, { zoomControl: true, scrollWheelZoom: true })
      .setView([opts.lat || 19.0760, opts.lng || 72.8777], opts.zoom || 12);

    L.tileLayer(OSM, { maxZoom: 19, attribution: ATTRIB }).addTo(map);

    // A layer group keeps markers/lines easy to clear on refresh.
    map.swMarkers   = L.layerGroup().addTo(map);
    map.swHotspots  = L.layerGroup().addTo(map);
    map.swWorkers   = L.layerGroup().addTo(map);
    map.swRoutes    = L.layerGroup().addTo(map);

    // Tell Leaflet to re-measure once the sidebar / tab changes size.
    setTimeout(() => map.invalidateSize(), 250);
    return map;
  };

  const STATUS_LABEL = {
    pending: 'Pending', prioritized: 'Prioritized', assigned: 'Assigned',
    scheduled: 'Scheduled', in_progress: 'In Progress', collected: 'Collected',
    verified: 'Verified', cancelled: 'Cancelled'
  };
  const WASTE_LABEL = {
    general: 'General Waste', plastic: 'Plastic', organic: 'Organic Waste',
    e_waste: 'E-Waste', construction: 'Construction Waste',
    hazardous: 'Hazardous Waste', other: 'Other'
  };

  /** Popup body for a request marker. */
  window.requestPopup = function (r) {
    const color = window.markerColor(r);
    const created = r.created_at ? new Date(String(r.created_at).replace(' ', 'T'))
      .toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—';
    const statusColor = (r.status === 'verified' || r.status === 'collected') ? 'success'
      : (r.status === 'cancelled' ? 'danger' : 'primary');

    return '' +
      '<div style="min-width:236px">' +
        '<div class="d-flex justify-content-between align-items-start gap-2 mb-2">' +
          '<strong style="font-size:.95rem">#' + r.id + ' &middot; ' + (WASTE_LABEL[r.waste_type] || r.waste_type) + '</strong>' +
          '<span class="badge bg-' + color + '-subtle text-' + color.replace('#', '') + '" ' +
            'style="color:' + color + '">P' + (r.priority_score || 0) + '</span>' +
        '</div>' +
        '<div class="d-flex flex-wrap gap-1 mb-2">' +
          '<span class="badge bg-' + statusColor + '-subtle text-' + statusColor + '">' +
            (STATUS_LABEL[r.status] || r.status) + '</span>' +
          (r.quantity ? '<span class="badge bg-secondary-subtle text-secondary">' + r.quantity + '</span>' : '') +
        '</div>' +
        '<table class="table table-sm mb-2" style="font-size:.78rem">' +
          '<tr><td class="text-muted border-0 py-1 pe-2">Location</td>' +
              '<td class="border-0 py-1">' + window.escapeHtml(r.address || '-') + '</td></tr>' +
          (r.landmark ? '<tr><td class="text-muted border-0 py-1 pe-2">Landmark</td>' +
              '<td class="border-0 py-1">' + window.escapeHtml(r.landmark) + '</td></tr>' : '') +
          '<tr><td class="text-muted border-0 py-1 pe-2">Worker</td>' +
              '<td class="border-0 py-1">' + window.escapeHtml(r.worker_name || 'Not assigned yet') + '</td></tr>' +
          '<tr><td class="text-muted border-0 py-1 pe-2">Created</td>' +
              '<td class="border-0 py-1">' + created + '</td></tr>' +
          '<tr><td class="text-muted border-0 py-1 pe-2">Coords</td>' +
              '<td class="border-0 py-1 mono">' + r.latitude + ', ' + r.longitude + '</td></tr>' +
        '</table>' +
        (r.link ? '<a class="btn btn-sm btn-success w-100" href="' + r.link + '">' +
                  '<i class="bi bi-arrow-right-circle me-1"></i>Open full details</a>' : '') +
      '</div>';
  };

  /** Draw every request on the map. */
  window.drawRequests = function (map, requests, opts) {
    opts = opts || {};
    map.swMarkers.clearLayers();
    const bounds = [];

    requests.forEach(function (r) {
      if (r.latitude == null || r.longitude == null) return;
      const color = window.markerColor(r);
      const m = L.marker([parseFloat(r.latitude), parseFloat(r.longitude)], {
        icon: window.pinIcon(color, opts.labels === false ? '' : r.id),
        title: '#' + r.id + ' ' + (WASTE_LABEL[r.waste_type] || r.waste_type)
      }).bindPopup(window.requestPopup(Object.assign({}, r, {
        link: opts.linkPrefix ? opts.linkPrefix + r.id + (opts.linkSuffix || '') : null
      })));

      if (opts.filter) {
        m.on('click', function () { if (opts.filter) opts.filter(r); });
      }
      m.addTo(map.swMarkers);
      bounds.push([parseFloat(r.latitude), parseFloat(r.longitude)]);
    });

    if (opts.fit !== false && bounds.length) {
      map.fitBounds(bounds, { padding: [45, 45], maxZoom: 15 });
    }
    return map;
  };

  /** Draw predicted hotspot circles. */
  window.drawHotspots = function (map, hotspots) {
    map.swHotspots.clearLayers();
    (hotspots || []).forEach(function (h) {
      const color = HOTSPOT_COLORS[h.risk_level] || '#6c757d';
      const r = h.radius_m || 700;
      L.circle([parseFloat(h.latitude), parseFloat(h.longitude)], {
        radius: r, color: color, weight: 2, opacity: .55,
        fillColor: color, fillOpacity: .10, dashArray: '6 5'
      }).bindPopup(
        '<div style="min-width:210px"><strong>' + window.escapeHtml(h.area_name) + '</strong><br>' +
        '<span class="badge bg-' + color + '-subtle mt-1" style="color:' + color + '">' +
          h.risk_level.toUpperCase() + ' RISK</span> ' +
          '<span class="badge bg-secondary-subtle text-secondary">score ' + h.hotspot_score + '</span>' +
        '<table class="table table-sm mt-2 mb-0" style="font-size:.78rem">' +
        '<tr><td class="border-0 py-1 text-muted">Previous reports</td><td class="border-0 py-1">' + h.report_count + '</td></tr>' +
        '<tr><td class="border-0 py-1 text-muted">Avg recurrence</td><td class="border-0 py-1">' + h.avg_recurrence_days + ' days</td></tr>' +
        '<tr><td class="border-0 py-1 text-muted">Still open</td><td class="border-0 py-1">' + h.open_count + '</td></tr>' +
        '</table></div>'
      ).addTo(map.swHotspots);

      L.marker([parseFloat(h.latitude), parseFloat(h.longitude)], {
        icon: window.hotspotIcon(color, h.hotspot_score)
      }).addTo(map.swHotspots);
    });
    return map;
  };

  /** Draw worker / vehicle positions. */
  window.drawWorkers = function (map, workers) {
    map.swWorkers.clearLayers();
    const colors = { available: '#16a34a', busy: '#fd7e14', offline: '#6c757d' };
    (workers || []).forEach(function (w) {
      if (w.latitude == null || w.longitude == null) return;
      L.marker([parseFloat(w.latitude), parseFloat(w.longitude)], {
        icon: window.workerIcon(colors[w.status] || '#6c757d', '<i class="bi bi-truck"></i>'),
        title: w.full_name
      }).bindPopup(
        '<div style="min-width:190px"><strong>' + window.escapeHtml(w.full_name || w.name || '') + '</strong><br>' +
        '<span class="text-muted" style="font-size:.78rem">' + window.escapeHtml(w.team_name || 'Unassigned') + '</span><br>' +
        '<span class="badge bg-' + (w.status === 'available' ? 'success' : w.status === 'busy' ? 'warning' : 'secondary') +
        '-subtle mt-1">' + w.status + '</span> ' +
        '<span class="badge bg-secondary-subtle text-secondary">' + (w.current_load || 0) + '/' + (w.max_load || 0) + ' load</span>' +
        '<table class="table table-sm mt-2 mb-0" style="font-size:.78rem">' +
        '<tr><td class="border-0 py-1 text-muted">Vehicle</td><td class="border-0 py-1">' +
          window.escapeHtml(w.vehicle_number || '—') + '</td></tr>' +
        '<tr><td class="border-0 py-1 text-muted">Last seen</td><td class="border-0 py-1">' +
          window.escapeHtml(w.last_seen_at || '—') + '</td></tr>' +
        '</table></div>'
      ).addTo(map.swWorkers);
    });
    return map;
  };

  /** Draw a collection route as a numbered polyline. */
  window.drawRoute = function (map, points, color) {
    map.swRoutes.clearLayers();
    if (!points || points.length < 2) return map;

    const latlngs = points.map(p => [parseFloat(p.latitude), parseFloat(p.longitude)]);
    L.polyline(latlngs, { color: color || '#16a34a', weight: 4, opacity: .8, dashArray: '10 6' })
      .addTo(map.swRoutes);

    latlngs.forEach((p, i) => {
      L.circleMarker(p, {
        radius: 11, color: '#fff', weight: 3,
        fillColor: color || '#16a34a', fillOpacity: 1
      }).bindTooltip('STOP ' + (i + 1), { permanent: true, direction: 'center',
        className: 'stop-tooltip', offset: [0, 0] }).addTo(map.swRoutes);
    });
    return map;
  };

  /** Small floating legend, useful on every map page. */
  window.mapLegend = function (items) {
    return '<div class="legend-box"><div class="fw-bold mb-1">' +
      '<i class="bi bi-map me-1"></i>Legend</div>' +
      items.map(i =>
        '<div class="d-flex align-items-center gap-2 mb-1"><span class="swatch" style="background:' + i.color + '"></span>' +
        '<span>' + i.label + '</span></div>').join('') + '</div>';
  };
})();
