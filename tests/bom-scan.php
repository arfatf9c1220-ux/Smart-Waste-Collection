<?php
/**
 * tests/bom-scan.php — finds stray UTF-8 BOMs and CRLF/whitespace issues that
 * break PHP output. A BOM before "<?php" is a hard fatal in PHP 8.
 * Usage: php tests/bom-scan.php
 */
$root = dirname(__DIR__);
$rii  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$skip = ['vendor', 'node_modules', '.git', 'uploads'];

$bad = [];
foreach ($rii as $f) {
    if (!$f->isFile()) continue;
    $path = $f->getPathname();
    foreach ($skip as $s) {
        if (strpos(str_replace('\\', '/', $path), '/' . $s . '/') !== false) continue 2;
    }
    $ext = strtolower($f->getExtension());
    if (!in_array($ext, ['php', 'js', 'css', 'json', 'sql', 'md'], true)) continue;

    $head = file_get_contents($path, false, null, 0, 3);
    if (strncmp($head, "\xEF\xBB\xBF", 3) === 0) {
        $bad[] = ['BOM', substr($path, strlen($root) + 1)];
        continue;
    }
    if ($ext === 'php') {
        $first = file_get_contents($path, false, null, 0, 64);
        // leading blank lines before <?php emit output before headers
        if (strncmp($first, '<?php', 5) !== 0 && preg_match('/^\s+/', $first)) {
            $bad[] = ['LEADING-WS', substr($path, strlen($root) + 1)];
        }
        // close tag at EOF emits a trailing newline
        $all = file_get_contents($path);
        if (preg_match('/\?>\s*$/', $all)) {
            $bad[] = ['CLOSING-TAG', substr($path, strlen($root) + 1)];
        }
    }
}

echo str_repeat('-', 60) . "\n";
if (!$bad) {
    echo "OK: no BOM / whitespace / closing-tag problems\n";
    exit(0);
}
foreach ($bad as [$kind, $file]) printf("%-12s %s\n", $kind, $file);
echo 'issues: ' . count($bad) . "\n";
exit(1);
