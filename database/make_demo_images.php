<?php
/**
 * database/make_demo_images.php
 * ------------------------------------------------------------------
 * Generates the placeholder "waste / before / after" photos referenced by
 * database/seed.sql.
 *
 * It writes real PNG files WITHOUT needing the GD extension: the PNG
 * chunks (IHDR / IDAT / IEND) are built by hand and zlib compressed with
 * gzcompress().
 *
 * Usage (from the XAMPP shell or a browser):
 *     php database/make_demo_images.php
 */

declare(strict_types=1);

const IMG_W = 480;
const IMG_H = 360;

/**
 * Minimal true-colour PNG encoder.
 * @param callable $pixel fn(int $x, int $y): array{0:int,1:int,2:int}
 */
function make_png(callable $pixel): string
{
    // --- Build the raw scanlines (each row prefixed with filter byte 0) ---
    $raw = '';
    for ($y = 0; $y < IMG_H; $y++) {
        $raw .= chr(0);                     // filter type "None"
        for ($x = 0; $x < IMG_W; $x++) {
            [$r, $g, $b] = $pixel($x, $y);
            $raw .= chr($r) . chr($g) . chr($b);
        }
    }

    $ihdr = pack('NN', IMG_W, IMG_H)
        . chr(8)      // bit depth
        . chr(2)      // colour type 2 = truecolour RGB
        . chr(0)      // compression
        . chr(0)      // filter
        . chr(0);     // interlace

    $idat = gzcompress($raw, 6);

    return "\x89PNG\r\n\x1a\n"
        . png_chunk('IHDR', $ihdr)
        . png_chunk('IDAT', $idat)
        . png_chunk('IEND', '');
}

/** One PNG chunk: length + type + data + CRC. */
function png_chunk(string $type, string $data): string
{
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

/** Deterministic pseudo random generator so re-runs look identical. */
function seeded_rand(int $seed): int
{
    $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
    return $seed;
}

/**
 * Draws a photo-like placeholder.
 * @param array $top    top gradient colour
 * @param array $bottom bottom gradient colour
 * @param int   $seed   random seed
 * @param int   $blobs  how many "litter" shapes to scatter
 */
function photo_pixel(array $top, array $bottom, int $seed, int $blobs, bool $litter): callable
{
    // Pre-compute the litter shapes: [x, y, w, h, colour]
    $shapes = [];
    $s = $seed;
    for ($i = 0; $i < $blobs; $i++) {
        $s = seeded_rand($s);
        $sx = $s % IMG_W;
        $s = seeded_rand($s);
        $sy = (int) (IMG_H * 0.30) + ($s % (int) (IMG_H * 0.65));
        $s = seeded_rand($s);
        $sw = 18 + ($s % 70);
        $s = seeded_rand($s);
        $sh = 12 + ($s % 46);
        $s = seeded_rand($s);
        $shapes[] = [$sx, $sy, $sw, $sh, [
            40 + ($s % 200), 40 + (($s >> 8) % 200), 40 + (($s >> 16) % 200),
        ]];
    }

    return function (int $x, int $y) use ($top, $bottom, $shapes, $litter): array {
        // Vertical gradient background
        $t = $y / IMG_H;
        $r = (int) ($top[0] + ($bottom[0] - $top[0]) * $t);
        $g = (int) ($top[1] + ($bottom[1] - $top[1]) * $t);
        $b = (int) ($top[2] + ($bottom[2] - $top[2]) * $t);

        // Ground line (a subtle kerb)
        if ($y > IMG_H * 0.72) {
            $r = (int) ($r * 0.86);
            $g = (int) ($g * 0.86);
            $b = (int) ($b * 0.86);
        }

        // Litter
        if ($litter) {
            foreach ($shapes as [$sx, $sy, $sw, $sh, $c]) {
                if ($x >= $sx && $x < $sx + $sw && $y >= $sy && $y < $sy + $sh) {
                    // simple shading so the shapes look a bit 3D
                    $shade = ($y - $sy) / max(1, $sh);
                    return [
                        (int) ($c[0] * (1 - 0.25 * $shade)),
                        (int) ($c[1] * (1 - 0.25 * $shade)),
                        (int) ($c[2] * (1 - 0.25 * $shade)),
                    ];
                }
            }
        }

        return [$r, $g, $b];
    };
}

$root = dirname(__DIR__);

/** Visual recipe per image type. */
$recipes = [
    // citizen photo: what the waste looked like at report time
    'waste'  => ['top' => [150, 140, 128], 'bottom' => [92, 86, 78],  'blobs' => 34, 'litter' => true],
    // before photo: taken by the worker before cleaning (messy)
    'before' => ['top' => [138, 132, 124], 'bottom' => [78, 74, 68],  'blobs' => 30, 'litter' => true],
    // after photo: taken after collection (clean)
    'after'  => ['top' => [196, 214, 226], 'bottom' => [214, 226, 214], 'blobs' => 0,  'litter' => false],
];

$made = 0;

// 20 waste photos (one per seeded request) + before/after for the collected ones.
foreach (range(1, 20) as $n) {
    foreach (['waste'] as $type) {
        $dir  = $root . '/uploads/' . $type;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $r    = $recipes[$type];
        $png  = make_png(photo_pixel($r['top'], $r['bottom'], 1000 + $n * 7, $r['blobs'], $r['litter']));
        file_put_contents($dir . "/demo-$type-$n.png", $png);
        $made++;
    }
}

// 13 before/after pairs (requests 1..13 were collected).
foreach (range(1, 13) as $n) {
    foreach (['before', 'after'] as $type) {
        $dir  = $root . '/uploads/' . $type;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $r    = $recipes[$type];
        $png  = make_png(photo_pixel($r['top'], $r['bottom'], 5000 + $n * 13, $r['blobs'], $r['litter']));
        file_put_contents($dir . "/demo-$type-$n.png", $png);
        $made++;
    }
}

echo "Generated $made demo images.\n";
echo "  uploads/waste/demo-waste-1..20.png\n";
echo "  uploads/before/demo-before-1..13.png\n";
echo "  uploads/after/demo-after-1..13.png\n";
