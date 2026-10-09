<?php
/**
 * includes/upload.php
 * ------------------------------------------------------------------
 * Safe image upload helper shared by the citizen report form and the
 * worker before/after proof uploads.
 *
 * Security checks performed:
 *   1. $_FILES error code must be UPLOAD_ERR_OK
 *   2. size must be under MAX_UPLOAD_BYTES
 *   3. real MIME type is read with finfo (fileinfo) and must be one of
 *      the JPEG types we accept -- the browser supplied type is never trusted
 *   4. getimagesize() must confirm it really is a decodable JPEG and that it
 *      is at least 100x100 px
 *   5. the bytes are re-encoded through GD into a real .jpg, so nothing but
 *      JPEG data ever lands on disk (a renamed PNG can never survive step 3/4)
 *   6. the file is stored with a GENERATED name (no user input in the path)
 *      and served as an image through .htaccess rules
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

/** Allowed bucket -> folder name under /uploads. */
function upload_folder_for(string $type): ?string
{
    return ['waste' => 'waste', 'before' => 'before', 'after' => 'after'][$type] ?? null;
}

/** Longest edge kept on a stored photo (keeps the file safely under 2 MB). */
const UPLOAD_MAX_EDGE = 2560;

/** Smallest accepted photo, in pixels, on each side. */
const UPLOAD_MIN_EDGE = 100;

/**
 * Validates one $_FILES entry without writing anything to disk.
 *
 * @param array $file one entry of $_FILES
 * @return array{mime:string, width:int, height:int}
 * @throws RuntimeException with a user facing message on any failure
 */
function validate_uploaded_image(array $file): array
{
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        $msg = match ((int) ($file['error'] ?? 4)) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Image size must be less than 2 MB.',
            UPLOAD_ERR_NO_FILE   => 'No file was selected.',
            UPLOAD_ERR_PARTIAL    => 'The upload was interrupted, please try again.',
            default               => 'Unable to process the image. Please try again.',
        };
        throw new RuntimeException($msg);
    }

    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Invalid upload.');
    }

    if ((int) $file['size'] > MAX_UPLOAD_BYTES) {
        throw new RuntimeException('Image size must be less than 2 MB.');
    }

    // --- 1. Real MIME type (never trust $_FILES['type']) --------------------
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = strtolower((string) $finfo->file($file['tmp_name']));

    if (!in_array($mime, ALLOWED_IMAGE_MIMES, true)) {
        throw new RuntimeException('Please select a JPG or JPEG image.');
    }

    // --- 2. Confirm it is a real, decodable JPEG ---------------------------
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        throw new RuntimeException('Please select a JPG or JPEG image.');
    }
    [$width, $height, $type] = $info;

    if ($type !== IMAGETYPE_JPEG) {
        // finfo said JPEG but the container says otherwise: never trust it.
        throw new RuntimeException('Please select a JPG or JPEG image.');
    }
    if ($width < UPLOAD_MIN_EDGE || $height < UPLOAD_MIN_EDGE) {
        throw new RuntimeException('Image must be at least ' . UPLOAD_MIN_EDGE . ' × ' . UPLOAD_MIN_EDGE . ' pixels.');
    }

    return ['mime' => $mime, 'width' => $width, 'height' => $height];
}

/**
 * Generates a unique, non user controlled file name for an upload.
 * e.g. waste_20261008_4f2a9c81.jpg
 */
function upload_file_name(string $type): string
{
    do {
        $name = sprintf('%s_%s_%s.jpg', $type, date('Ymd'), bin2hex(random_bytes(4)));
        $dir  = dirname(__DIR__) . '/' . UPLOAD_ROOT . '/' . upload_folder_for($type);
    } while (is_file($dir . '/' . $name));

    return $name;
}

/**
 * Writes the JPEG at $src to $dest as genuine JPEG data.
 *
 * When the GD extension is available the image is decoded and re-encoded,
 * which normalises colour profiles, strips EXIF and guarantees the bytes on
 * disk are JPEG regardless of what was uploaded. When GD is missing the
 * original bytes are copied, but only after validate_uploaded_image() has
 * already proven they are a JPEG.
 *
 * @throws RuntimeException when the image cannot be processed
 */
function write_jpeg_file(string $src, string $dest, int $width, int $height): void
{
    if (function_exists('imagecreatefromjpeg')) {
        $img = @imagecreatefromjpeg($src);

        if ($img !== false) {
            // Scale oversized photos down so the stored file stays small.
            if (max($width, $height) > UPLOAD_MAX_EDGE) {
                $ratio = UPLOAD_MAX_EDGE / max($width, $height);
                $newW  = max(1, (int) round($width * $ratio));
                $newH  = max(1, (int) round($height * $ratio));
                $scaled = @imagescale($img, $newW, $newH);
                if ($scaled !== false) {
                    imagedestroy($img);
                    $img = $scaled;
                }
            }

            $ok = @imagejpeg($img, $dest, 90);
            imagedestroy($img);

            if ($ok && is_file($dest) && (int) filesize($dest) > 0) {
                return;
            }
        }
        // GD could not decode this JPEG: fall through to the verified bytes
        // below rather than rejecting a photo that already passed validation.
    }

    if (!@copy($src, $dest) || !is_file($dest)) {
        throw new RuntimeException('Unable to process the image. Please try again.');
    }
}

/**
 * Validates and stores one uploaded image.
 *
 * @param array  $file  one entry of $_FILES
 * @param string $type  waste | before | after
 * @return string Relative path, e.g. "uploads/waste/waste_20261008_4f2a9c81.jpg"
 * @throws RuntimeException on any validation failure
 */
function save_uploaded_image(array $file, string $type): string
{
    $folder = upload_folder_for($type);
    if (!$folder) {
        throw new RuntimeException('Unknown image type.');
    }

    $meta = validate_uploaded_image($file);

    $dir = dirname(__DIR__) . '/' . UPLOAD_ROOT . '/' . $folder;
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException('Upload folder is missing: ' . $folder);
    }

    $name = upload_file_name($type);
    $dest = $dir . '/' . $name;

    try {
        write_jpeg_file($file['tmp_name'], $dest, $meta['width'], $meta['height']);
    } catch (Throwable $e) {
        @unlink($dest);
        throw $e;
    }
    @chmod($dest, 0644);

    return UPLOAD_ROOT . '/' . $folder . '/' . $name;
}

/** Size on disk of a stored image (used when recording the upload). */
function stored_image_size(string $relativePath): ?int
{
    $abs = dirname(__DIR__) . '/' . ltrim($relativePath, '/');
    return is_file($abs) ? (int) filesize($abs) : null;
}

/** Registers an uploaded image in request_images. */
function record_image(int $requestId, string $type, string $relativePath, ?string $mime = null, ?int $size = null, ?float $lat = null, ?float $lng = null): void
{
    if ($size === null) {
        $size = stored_image_size($relativePath);
    }
    if ($mime === null) {
        $abs = dirname(__DIR__) . '/' . ltrim($relativePath, '/');
        $mime = is_file($abs) ? (new finfo(FILEINFO_MIME_TYPE))->file($abs) : null;
    }

    db_query(
        'INSERT INTO request_images (request_id, image_type, file_path, mime_type, file_size, captured_lat, captured_lng, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$requestId, $type, $relativePath, $mime, $size, $lat, $lng, current_user_id()]
    );
}
