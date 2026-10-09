<?php
/**
 * citizen/report.php  —  the citizen waste report form
 * Submits via POST straight to the page (no JS required), then redirects to
 * the tracking screen. Everything is re-scored live by the priority engine.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/priority.php';
require_once __DIR__ . '/../includes/upload.php';
require_role('citizen');

$citizen = current_citizen() ?? [];
$errors  = [];

$old = [
    'waste_type'  => 'general',
    'quantity'    => 'medium',
    'severity'    => 'normal',
    'address'     => (string) ($citizen['address'] ?? ''),
    'landmark'    => '',
    'description' => '',
];
$lat = $citizen['latitude']  ?? null;
$lng = $citizen['longitude'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($old) as $k) {
        $old[$k] = clean_str(input($k, ''), 255);
    }
    $lat = input_float('latitude');
    $lng = input_float('longitude');
    $severity = clean_str((string) input('severity', 'normal'), 12);

    if (!array_key_exists($old['waste_type'], waste_options()))   $errors[] = 'Please choose a waste category.';
    if (!array_key_exists($old['quantity'], quantity_options())) $errors[] = 'Please estimate the quantity.';
    if ($old['address'] === '' && $old['landmark'] === '')       $errors[] = 'Give an address or a nearby landmark.';
    if ($lat === null || $lng === null || !valid_coords($lat, $lng)) $errors[] = 'Drop a pin on the map so our team can find it.';
    // --- the photo is validated up front so a bad file never reaches the DB
    $photoFile = null;
    foreach (['photo', 'photo_camera', 'photo_gallery'] as $field) {
        $candidate = $_FILES[$field] ?? null;
        if (is_array($candidate) && ($candidate['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $photoFile = $candidate;
            break;
        }
    }
    $hasPhoto = $photoFile !== null;
    if (!$hasPhoto) {
        $errors[] = 'Please take or choose a JPEG photo of the waste.';
    } else {
        try {
            validate_uploaded_image($photoFile);
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (!$errors) {
        // Score + persist, then attach the photo.
        $priority = calculate_priority([
            'waste_type'   => $old['waste_type'],
            'quantity'     => $old['quantity'],
            'severity'     => $severity,
            'latitude'     => $lat,
            'longitude'    => $lng,
            'address'      => $old['address'],
            'landmark'     => $old['landmark'],
            'created_at'   => date('Y-m-d H:i:s'),
        ]);

        db()->beginTransaction();
        try {
            $nextId = (int) db_value('SELECT COALESCE(MAX(id),0) FROM waste_requests', [], 0) + 1;
            $code   = 'WR-' . date('Ymd') . '-' . str_pad((string) $nextId, 5, '0', STR_PAD_LEFT);

            $requestId = db_insert(
                'INSERT INTO waste_requests
                    (request_code, citizen_id, user_id, waste_type, quantity, description,
                     address, landmark, latitude, longitude, status,
                     priority_score, priority_level, priority_factors, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [
                    $code,
                    $citizen['id'] ?? null,
                    current_user_id(),
                    $old['waste_type'],
                    $old['quantity'],
                    $old['description'] ?: null,
                    $old['address'] ?: $old['landmark'],
                    $old['landmark'] ?: null,
                    $lat,
                    $lng,
                    $priority['score'] >= 61 ? 'prioritized' : 'pending',
                    $priority['score'],
                    $priority['level'],
                    json_encode($priority),
                ]
            );

            $imagePath = null;
            if ($hasPhoto) {
                $imagePath = save_uploaded_image($photoFile, 'waste');
                db_query('UPDATE waste_requests SET image_path = ? WHERE id = ?', [$imagePath, $requestId]);
                record_image($requestId, 'waste', $imagePath, null, null, $lat, $lng);
            }

            db()->commit();
        } catch (RuntimeException $e) {
            db()->rollBack();
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            db()->rollBack();
            $errors[] = 'We could not save your report. Please try again.';
        }

        if (!$errors) {
            log_activity('create_request', 'waste_request', $requestId,
                "Citizen reported {$old['waste_type']} waste (priority {$priority['score']}/100)");

            foreach (db_all('SELECT id FROM users WHERE role = "admin"') as $a) {
                notify((int) $a['id'], 'New waste report #' . $requestId,
                    waste_meta($old['waste_type'])['label'] . ' at ' . ($old['address'] ?: $old['landmark']) .
                    ' — scored ' . $priority['score'] . '/100',
                    $priority['score'] >= 61 ? 'danger' : 'info',
                    '/admin/requests.php?id=' . $requestId);
            }

            $_SESSION['flash'] = 'Report submitted! Your request scored ' . $priority['score'] . '/100 (' .
                strtoupper($priority['level']) . ' priority).';
            redirect('/citizen/track.php?id=' . $requestId);
        }
    }
}

$pageTitle    = 'Report Waste';
$pageSubtitle = 'Photo + map pin = fastest pickup';
$activeNav    = 'report';

require __DIR__ . '/../includes/header.php';
?>

<?php if ($errors): ?>
  <div class="alert alert-danger border-0" style="border-radius:12px">
    <div class="fw-600 mb-1"><i class="bi bi-exclamation-octagon-fill me-2"></i>Please fix the following:</div>
    <ul class="mb-0 ps-4" style="font-size:.88rem">
      <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" id="reportForm">
  <input type="hidden" name="latitude"  id="latitude"  value="<?= e($lat ?? '') ?>">
  <input type="hidden" name="longitude" id="longitude" value="<?= e($lng ?? '') ?>">

  <div class="row g-3">
    <!-- ------------------------------------------------------------- form -->
    <div class="col-lg-7">
      <div class="card card-soft">
        <div class="card-body">
          <h5 class="section-title">What did you find?</h5>

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-600">Waste category *</label>
              <select name="waste_type" class="form-select" id="wasteType" required>
                <?php foreach (waste_options() as $key => $label):
                    $m = waste_meta($key); ?>
                  <option value="<?= e($key) ?>" <?= $old['waste_type'] === $key ? 'selected' : '' ?>>
                    <?= e($label) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label fw-600">Estimated quantity *</label>
              <select name="quantity" class="form-select" id="quantity" required>
                <?php foreach (quantity_options() as $key => $label): ?>
                  <option value="<?= e($key) ?>" <?= $old['quantity'] === $key ? 'selected' : '' ?>>
                    <?= e($label) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12">
              <label class="form-label fw-600">How severe is it?</label>
              <div class="d-flex gap-2 flex-wrap">
                <?php foreach ([['low','Low','Not urgent'],['normal','Normal','Everyday pile'],['high','High','Blocking or overflowing'],['critical','Critical','Spreading / health risk']] as $i => [$val,$label,$hint]): ?>
                  <input type="radio" class="btn-check" name="severity" id="sev<?= $val ?>"
                         value="<?= e($val) ?>" <?= $old['severity'] === $val ? 'checked' : '' ?>>
                  <label class="btn btn-outline-secondary" for="sev<?= e($val) ?>" style="font-size:.8rem">
                    <?= e($label) ?><span class="d-none d-md-inline text-muted"> · <?= e($hint) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="col-md-7">
              <label class="form-label fw-600">Address</label>
              <input type="text" name="address" class="form-control" value="<?= e($old['address']) ?>"
                     placeholder="Flat / street, building name">
            </div>
            <div class="col-md-5">
              <label class="form-label fw-600">Nearest landmark</label>
              <input type="text" name="landmark" class="form-control" value="<?= e($old['landmark']) ?>"
                     placeholder="e.g. Near the temple">
            </div>

            <div class="col-12">
              <label class="form-label fw-600">Anything else we should know?</label>
              <textarea name="description" rows="3" class="form-control" maxlength="1000"
                        placeholder="Bags overflowing, next to a school entrance, needs a special vehicle…"><?= e($old['description']) ?></textarea>
            </div>

            <div class="col-12">
              <label class="form-label fw-600" id="photoFieldLabel">Photo of the waste *</label>
              <div class="upload-drop d-block" id="photoDrop">

                <div id="photoEmpty">
                  <i class="bi bi-camera fs-2 text-success"></i>
                  <div class="fw-600 mt-1" id="photoLabel">Add a JPEG photo</div>
                  <div class="text-muted mb-2" style="font-size:.78rem">JPEG only · max 2 MB · min 100x100 px</div>
                  <div class="d-flex justify-content-center gap-2 flex-wrap">
                    <button type="button" class="btn btn-sm btn-outline-success" id="btnTakePhoto">
                      <i class="bi bi-camera me-1"></i>Take photo
                    </button>
                    <label for="photoInput" class="btn btn-sm btn-outline-secondary mb-0">
                      <i class="bi bi-image me-1"></i>Choose JPEG
                    </label>
                  </div>
                </div>

                <div id="photoPreviewBox" class="photo-preview d-none">
                  <img id="photoPreviewImg" class="photo-preview-img" alt="Photo of the waste">
                  <div class="photo-preview-meta">
                    <div class="fw-600 text-truncate" id="photoMetaName">&nbsp;</div>
                    <div class="text-muted" id="photoMetaInfo" style="font-size:.76rem">&nbsp;</div>
                    <div class="d-flex gap-2 flex-wrap mt-2">
                      <button type="button" class="btn btn-sm btn-outline-success" id="btnRetake">
                        <i class="bi bi-camera me-1"></i>Retake
                      </button>
                      <label for="photoInput" class="btn btn-sm btn-outline-secondary mb-0">
                        <i class="bi bi-arrow-left-right me-1"></i>Change photo
                      </label>
                      <button type="button" class="btn btn-sm btn-outline-danger" id="btnRemovePhoto">
                        <i class="bi bi-trash me-1"></i>Remove
                      </button>
                    </div>
                  </div>
                </div>

              </div>
              <input type="file" name="photo" id="photoInput" accept="image/jpeg,.jpg,.jpeg" class="d-none">
              <div class="text-danger mt-1" style="font-size:.78rem" id="photoError" role="alert" aria-live="polite"></div>
            </div>
          </div>

          <div class="d-flex gap-2 mt-4">
            <button class="btn btn-success px-4" id="reportSubmit" type="submit">
              <i class="bi bi-send me-2"></i>Submit report
            </button>
            <a href="<?= e(APP_URL) ?>/citizen/dashboard.php" class="btn btn-light border">Cancel</a>
          </div>
        </div>
      </div>
    </div>

    <!-- -------------------------------------------------------------- map -->
    <div class="col-lg-5">
      <div class="card card-soft mb-3">
        <div class="card-body">
          <h5 class="section-title">Where is it?</h5>
          <p class="text-muted" style="font-size:.84rem">Click the map to drop a pin, or use your GPS.</p>
          <div class="map-shell" style="height:300px">
            <div id="reportMap" style="height:100%"></div>
          </div>
          <div class="d-flex gap-2 mt-2">
            <button type="button" class="btn btn-sm btn-outline-success" id="useGps">
              <i class="bi bi-crosshair me-1"></i>Use my location</button>
            <span class="text-muted align-self-center" id="coordsHint" style="font-size:.78rem">
              No pin dropped yet.
            </span>
          </div>
        </div>
      </div>

      <!-- live priority preview -->
      <div class="card card-soft">
        <div class="card-body">
          <h5 class="section-title">Live priority preview</h5>
          <p class="text-muted mb-3" style="font-size:.82rem">
            Same engine the server uses — update the form to see the score move.
          </p>
          <div class="d-flex align-items-center gap-3">
            <div class="score-ring">
              <svg width="92" height="92">
                <circle cx="46" cy="46" r="40" stroke="#eef3f1" stroke-width="9" fill="none"/>
                <circle id="ringArc" cx="46" cy="46" r="40" stroke="#16a34a" stroke-width="9"
                        fill="none" stroke-linecap="round"
                        stroke-dasharray="251.3" stroke-dashoffset="251.3"/>
              </svg>
              <div class="score-text">
                <div class="n" id="previewScore">0</div>
                <div class="d">of 100</div>
              </div>
            </div>
            <div>
              <div id="previewLevel" class="pill bg-secondary-subtle text-secondary">Low</div>
              <div class="text-muted mt-2" id="previewWhy" style="font-size:.78rem;max-width:180px">
                Fill the form to see why.
              </div>
            </div>
          </div>
          <div id="previewFactors" class="mt-3"></div>
        </div>
      </div>
    </div>
  </div>
</form>

<!-- ===================================================== camera capture ==== -->
<div class="modal fade" id="cameraModal" tabindex="-1" aria-labelledby="cameraModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title fw-600" id="cameraModalLabel">
          <i class="bi bi-camera me-2 text-success"></i>Take a photo
        </h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-2">
        <div class="camera-frame">
          <video id="cameraVideo" playsinline autoplay muted></video>
          <canvas id="cameraCanvas" class="d-none"></canvas>
          <div class="camera-status" id="cameraStatus">Starting camera…</div>
        </div>
        <div class="camera-error d-none" id="cameraError">
          <i class="bi bi-exclamation-triangle me-1"></i><span id="cameraErrorText"></span>
        </div>
      </div>
      <div class="modal-footer py-2 justify-content-center gap-2 flex-nowrap">
        <button type="button" class="btn btn-sm btn-light border" data-bs-dismiss="modal" id="cameraCancel">
          Cancel
        </button>
        <button type="button" class="btn btn-sm btn-success" id="cameraCapture">
          <i class="bi bi-camera me-1"></i>Capture Photo
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary d-none" id="cameraFallback">
          <i class="bi bi-image me-1"></i>Choose JPEG instead
        </button>
      </div>
    </div>
  </div>
</div>

<?php
$extraJs = [
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
    APP_URL . '/assets/js/map.js',
];

$inlineJs = <<<'JS'

(function () {
  /* ------------------------------------------------------------ map pin -- */
  let map = null;
  let marker = null;

  try {
    map = L.map('reportMap').setView([19.0760, 72.8777], 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19, attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);
  } catch (e) { map = null; }

  const latEl = document.getElementById('latitude');
  const lngEl = document.getElementById('longitude');
  const hint  = document.getElementById('coordsHint');

  function place(lat, lng) {
    if (map && typeof pinIcon === 'function') {
      if (marker) marker.setLatLng([lat, lng]);
      else marker = L.marker([lat, lng], { icon: pinIcon('#16a34a', ''), keyboard: false }).addTo(map);
    }
    latEl.value = lat.toFixed(7);
    lngEl.value = lng.toFixed(7);
    hint.innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>Pinned at ' +
                    lat.toFixed(5) + ', ' + lng.toFixed(5) + '</span>';
    preview();
  }
  if (map) map.on('click', e => place(e.latlng.lat, e.latlng.lng));

  document.getElementById('useGps').addEventListener('click', function () {
    if (!navigator.geolocation) { hint.textContent = 'Geolocation is unavailable — click the map.'; return; }
    hint.textContent = 'Locating…';
    navigator.geolocation.getCurrentPosition(
      function (pos) { place(pos.coords.latitude, pos.coords.longitude); if (map) map.setView([pos.coords.latitude, pos.coords.longitude], 16); },
      function () { hint.textContent = 'GPS denied — click the map instead.'; }
    );
  });

  if (map && latEl.value && lngEl.value) {
    marker = L.marker([parseFloat(latEl.value), parseFloat(lngEl.value)],
                      { icon: pinIcon('#16a34a', ''), keyboard: false }).addTo(map);
  }

  /* --------------------------------------------------------- photo input -- */
  const MAX_PHOTO_BYTES = 2 * 1024 * 1024;
  const MIN_PHOTO_EDGE  = 100;

  const drop        = document.getElementById('photoDrop');
  const photoInput  = document.getElementById('photoInput');
  const photoEmpty  = document.getElementById('photoEmpty');
  const photoBox    = document.getElementById('photoPreviewBox');
  const photoImg    = document.getElementById('photoPreviewImg');
  const photoNameEl = document.getElementById('photoMetaName');
  const photoInfoEl = document.getElementById('photoMetaInfo');
  const photoError  = document.getElementById('photoError');

  let photoFile = null;
  let photoUrl  = null;

  function toast(msg, type) {
    if (window.showToast) window.showToast(msg, type || 'warning');
    else if (msg) alert(msg);
  }

  function showPhotoError(msg) {
    photoError.textContent = msg || '';
    if (msg) toast(msg, 'warning');
  }

  function fmtSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1048576).toFixed(2) + ' MB';
  }

  /* --- file sniffing ---------------------------------------------------- */
  function readHead(file) {
    return new Promise(function (resolve) {
      const fr = new FileReader();
      fr.onload  = function () { resolve(new Uint8Array(fr.result)); };
      fr.onerror = function () { resolve(new Uint8Array(0)); };
      fr.readAsArrayBuffer(file.slice(0, 4));
    });
  }

  function loadDimensions(file) {
    return new Promise(function (resolve, reject) {
      const fallback = function () {
        const url = URL.createObjectURL(file);
        const img = new Image();
        img.onload = function () {
          URL.revokeObjectURL(url);
          resolve({ width: img.naturalWidth, height: img.naturalHeight });
        };
        img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('decode')); };
        img.src = url;
      };
      if (typeof createImageBitmap === 'function') {
        createImageBitmap(file).then(function (bmp) {
          const size = { width: bmp.width, height: bmp.height };
          if (bmp.close) bmp.close();
          resolve(size);
        }).catch(fallback);
      } else {
        fallback();
      }
    });
  }

  /** Rejects anything that is not a real JPEG, over 2 MB or under 100x100. */
  async function validatePhotoFile(file) {
    const mime = (file.type || '').toLowerCase();
    const name = (file.name || '').toLowerCase();
    const jpegType = mime === 'image/jpeg' || mime === 'image/jpg' || mime === 'image/pjpeg';
    const jpegExt  = /\.(jpe?g)$/.test(name);

    if (!jpegType && !jpegExt && mime !== '') throw new Error('Please select a JPG or JPEG image.');
    if (file.size > MAX_PHOTO_BYTES)          throw new Error('Image size must be less than 2 MB.');

    // The extension and the MIME type are both optional/forgeable — the first
    // three bytes (FF D8 FF) are what actually proves the file is a JPEG.
    const head = await readHead(file);
    if (!(head.length >= 3 && head[0] === 0xff && head[1] === 0xd8 && head[2] === 0xff)) {
      throw new Error('Please select a JPG or JPEG image.');
    }

    let size;
    try {
      size = await loadDimensions(file);
    } catch (e) {
      throw new Error('Unable to process the image. Please try again.');
    }
    if (size.width < MIN_PHOTO_EDGE || size.height < MIN_PHOTO_EDGE) {
      throw new Error('Image must be at least ' + MIN_PHOTO_EDGE + ' × ' + MIN_PHOTO_EDGE + ' pixels.');
    }
    return size;
  }

  /* --- selection / preview --------------------------------------------- */
  function syncInput() {
    try {
      const dt = new DataTransfer();
      dt.items.add(photoFile);
      photoInput.files = dt.files;
    } catch (e) {
      photoInput.value = '';
      return false;
    }
    return true;
  }

  function clearPhoto() {
    photoFile = null;
    photoInput.value = '';
    if (photoUrl) { URL.revokeObjectURL(photoUrl); photoUrl = null; }
    photoImg.removeAttribute('src');
    photoBox.classList.add('d-none');
    photoEmpty.classList.remove('d-none');
    drop.classList.remove('has-file');
    photoError.textContent = '';
  }

  function commitPhoto(file, size) {
    photoFile = file;
    photoError.textContent = '';
    if (photoUrl) URL.revokeObjectURL(photoUrl);
    photoUrl = URL.createObjectURL(file);
    photoImg.src = photoUrl;
    photoNameEl.textContent = file.name || 'waste_photo.jpg';
    photoInfoEl.textContent = 'Size: ' + fmtSize(file.size) + '  ·  Dimensions: ' +
                              size.width + ' × ' + size.height;
    photoEmpty.classList.add('d-none');
    photoBox.classList.remove('d-none');
    drop.classList.add('has-file');

    if (!syncInput()) {
      clearPhoto();
      showPhotoError('Unable to process the image. Please try again.');
    }
  }

  async function acceptPhoto(file) {
    if (!file) return;
    let size;
    try {
      size = await validatePhotoFile(file);
    } catch (e) {
      photoInput.value = '';
      if (photoFile) syncInput();          // keep the previously selected photo
      showPhotoError(e.message);
      return;
    }
    commitPhoto(file, size);
  }

  photoInput.addEventListener('change', function () {
    acceptPhoto(photoInput.files && photoInput.files[0]);
  });

  document.getElementById('btnRemovePhoto').addEventListener('click', clearPhoto);

  ['dragenter', 'dragover'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('drag'); });
  });
  ['dragleave', 'dragend'].forEach(function (ev) {
    drop.addEventListener(ev, function () { drop.classList.remove('drag'); });
  });
  drop.addEventListener('drop', function (e) {
    e.preventDefault();
    drop.classList.remove('drag');
    const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
    if (f) acceptPhoto(f);
  });

  drop.addEventListener('click', function (e) {
    if (photoBox.classList.contains('d-none') && !e.target.closest('button, label')) {
      photoInput.click();
    }
  });

  /* ------------------------------------------------------ live camera ---- */
  const camEl       = document.getElementById('cameraModal');
  const camVideo    = document.getElementById('cameraVideo');
  const camCanvas   = document.getElementById('cameraCanvas');
  const camStatus   = document.getElementById('cameraStatus');
  const camError    = document.getElementById('cameraError');
  const camErrorTx  = document.getElementById('cameraErrorText');
  const camCapture  = document.getElementById('cameraCapture');
  const camFallback = document.getElementById('cameraFallback');
  const camFrame    = camEl.querySelector('.camera-frame');
  let camStream = null;
  const camModal = (window.bootstrap && bootstrap.Modal)
    ? bootstrap.Modal.getOrCreateInstance(camEl) : null;

  function camShowError(msg, offerFallback) {
    camFrame.classList.add('d-none');
    camErrorTx.textContent = msg;
    camError.classList.remove('d-none');
    camCapture.classList.add('d-none');
    camFallback.classList.toggle('d-none', !offerFallback);
  }

  function camReset() {
    camFrame.classList.remove('d-none');
    camError.classList.add('d-none');
    camFallback.classList.add('d-none');
    camCapture.classList.remove('d-none');
    camStatus.classList.remove('d-none');
    camStatus.textContent = 'Starting camera…';
  }

  function stopCamera() {
    if (camStream) {
      camStream.getTracks().forEach(function (t) { try { t.stop(); } catch (e) {} });
      camStream = null;
    }
    camVideo.srcObject = null;
  }

  async function startCamera() {
    camReset();
    stopCamera();

    if (!window.isSecureContext &&
        ['localhost', '127.0.0.1'].indexOf(location.hostname) === -1) {
      camShowError('Camera access needs a secure (HTTPS) connection. Please choose a JPEG instead.', true);
      return;
    }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      camShowError('Camera is not available on this device.', true);
      return;
    }

    // Rear camera first, then progressively looser requests so any device
    // with a webcam can still open.
    const attempts = [
      { video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1080 } }, audio: false },
      { video: { facingMode: 'environment' }, audio: false },
      { video: { facingMode: 'user' }, audio: false },
      { video: true, audio: false }
    ];

    let lastError = null;
    for (let i = 0; i < attempts.length; i++) {
      try {
        camStream = await navigator.mediaDevices.getUserMedia(attempts[i]);
        break;
      } catch (err) {
        lastError = err;
        const name = err && err.name;
        if (name === 'NotAllowedError' || name === 'PermissionDeniedError' ||
            name === 'NotFoundError' || name === 'DevicesNotFoundError') {
          break;
        }
      }
    }

    if (!camStream) {
      const name = lastError && lastError.name;
      const denied = name === 'NotAllowedError' || name === 'PermissionDeniedError' || name === 'SecurityError';
      camShowError(denied ? 'Please allow camera access to take a photo.'
                          : 'Camera is not available on this device.', true);
      return;
    }

    camVideo.srcObject = camStream;
    try { await camVideo.play(); } catch (e) {}
    camStatus.classList.add('d-none');
  }

  function openCamera() {
    if (camModal) camModal.show();
    else camShowError('Camera is not available on this device.', true);
  }

  camEl.addEventListener('shown.bs.modal', startCamera);
  camEl.addEventListener('hidden.bs.modal', stopCamera);

  document.getElementById('btnTakePhoto').addEventListener('click', openCamera);
  document.getElementById('btnRetake').addEventListener('click', openCamera);

  camFallback.addEventListener('click', function () {
    if (camModal) camModal.hide();
    photoInput.removeAttribute('capture');
    photoInput.click();
  });

  camCapture.addEventListener('click', function () {
    const w = camVideo.videoWidth;
    const h = camVideo.videoHeight;
    if (!camStream || !w || !h) {
      camStatus.textContent = 'Camera is still starting — one moment…';
      camStatus.classList.remove('d-none');
      return;
    }

    const scale = Math.min(1, 1600 / Math.max(w, h));
    camCanvas.width  = Math.max(1, Math.round(w * scale));
    camCanvas.height = Math.max(1, Math.round(h * scale));
    camCanvas.getContext('2d').drawImage(camVideo, 0, 0, camCanvas.width, camCanvas.height);

    const now = new Date();
    const pad = function (n) { return String(n).length < 2 ? '0' + n : String(n); };
    const fname = 'waste_' + now.getFullYear() + pad(now.getMonth() + 1) + pad(now.getDate()) +
                  '_' + pad(now.getHours()) + pad(now.getMinutes()) + pad(now.getSeconds()) + '.jpg';

    stopCamera();
    if (camModal) camModal.hide();

    camCanvas.toBlob(function (blob) {
      if (!blob) { showPhotoError('Unable to process the image. Please try again.'); return; }
      acceptPhoto(new File([blob], fname, { type: 'image/jpeg', lastModified: Date.now() }));
    }, 'image/jpeg', 0.9);
  });

  /* ------------------------------------------------------- form submit --- */
  const reportForm = document.getElementById('reportForm');
  const submitBtn  = document.getElementById('reportSubmit');
  reportForm.addEventListener('submit', function (ev) {
    if (submitBtn.dataset.busy === '1') { ev.preventDefault(); return; }
    if (!photoFile || !photoInput.files.length) {
      ev.preventDefault();
      showPhotoError('Please take or choose a JPEG photo of the waste.');
      drop.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }
    submitBtn.dataset.busy = '1';
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Submitting…';
  });

  /* ------------------------------------------------- live priority preview -- */
  /* Mirrors includes/priority.php so the citizen sees the score before submitting. */
  const SEVERITY = { low: 5, normal: 12, high: 20, critical: 25 };
  const QTY      = { small: 6, medium: 12, large: 18, bulk: 20 };
  const TYPE     = {
    general: 12, plastic: 12, organic: 14, e_waste: 22,
    construction: 20, hazardous: 25, other: 10
  };
  const SENSITIVE = ['hospital', 'school', 'market', 'temple', 'water', 'bus', 'rail', 'bridge'];
  const LEVELS = [
    ['critical', 81, 'Critical', 'danger'],
    ['high',     61, 'High',     'warning'],
    ['medium',   31, 'Medium',   'warning'],
    ['low',       0, 'Low',      'success']
  ];

  function preview() {
    const type  = document.getElementById('wasteType').value;
    const qty   = document.getElementById('quantity').value;
    const sevEl = document.querySelector('input[name=severity]:checked');
    const sev   = sevEl ? sevEl.value : 'normal';
    const text  = (document.querySelector('[name=landmark]').value + ' ' +
                   document.querySelector('[name=address]').value).toLowerCase();

    const severity = SEVERITY[sev] || 12;
    const quantity = QTY[qty] || 12;
    const location = SENSITIVE.reduce((acc, k) => acc + (text.includes(k) ? 5 : 0), 0);
    const age      = 0;
    const repeat   = 0;

    const score = Math.max(0, Math.min(100, severity + quantity + location + age + repeat));
    const level = LEVELS.find(l => score >= l[1])[0];
    const meta  = LEVELS.find(l => l[0] === level);

    document.getElementById('previewScore').textContent = score;
    const badge = document.getElementById('previewLevel');
    badge.className = 'pill bg-' + (level === 'medium' ? 'warning' : meta[3]) + '-subtle text-' +
                      (level === 'high' ? 'warning' : meta[3]);
    badge.textContent = meta[2] + ' priority';
    document.getElementById('previewWhy').textContent =
      severity + ' severity + ' + quantity + ' quantity + ' + location + ' location risk';

    const arc = document.getElementById('ringArc');
    arc.style.strokeDashoffset = String(251.3 - (251.3 * score / 100));
    arc.setAttribute('stroke', { critical: '#dc3545', high: '#fd7e14', medium: '#ffc107', low: '#16a34a' }[level]);

    document.getElementById('previewFactors').innerHTML = [
      ['Severity', severity, 30], ['Quantity', quantity, 20], ['Location risk', location, 20],
      ['Age', age, 15], ['Repeat reports', repeat, 15]
    ].map(function (f) {
      const pct = Math.round(f[1] / f[2] * 100);
      return '<div class="mb-2"><div class="d-flex justify-content-between" style="font-size:.76rem">' +
             '<span>' + f[0] + '</span><span class="text-muted">' + f[1] + '/' + f[2] + '</span></div>' +
             '<div class="factor-bar mt-1"><span style="width:' + pct + '%"></span></div></div>';
    }).join('');
  }

  ['wasteType', 'quantity'].forEach(id => document.getElementById(id).addEventListener('change', preview));
  document.querySelectorAll('input[name=severity]').forEach(r => r.addEventListener('change', preview));
  document.querySelector('[name=landmark]').addEventListener('input', preview);
  document.querySelector('[name=address]').addEventListener('input', preview);
  preview();
})();

JS;

require __DIR__ . '/../includes/footer.php';
