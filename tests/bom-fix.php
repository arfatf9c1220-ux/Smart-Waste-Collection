<?php
/**
 * tests/bom-fix.php — strips UTF-8 BOMs and trailing closing tags.
 * Usage: php tests/bom-fix.php          (dry run: add --write to apply)
 */
$root = dirname(__DIR__);
$write = in_array('--write', $argv, true);

$rii  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$skip = ['vendor', 'node_modules', '.git', 'uploads'];

$changed = [];
foreach ($rii as $f) {
    if (!$f->isFile()) continue;
    $path = $f->getPathname();
    foreach ($skip as $s) {
        if (strpos(str_replace('\\', '/', $path), '/' . $s . '/') !== false) continue 2;
    }
    $rel = substr($path, strlen($root) + 1);
    $ext = strtolower($f->getExtension());
    if (!in_array($ext, ['php', 'js', 'css', 'json', 'sql', 'md'], true)) continue;

    $src = file_get_contents($path);
    $new = $src;

    if (strncmp($new, "\xEF\xBB\xBF", 3) === 0) {
        $new = substr($new, 3);
        $changed[] = "BOM stripped        $rel";
    }
    if ($ext === 'php') {
        // A closing tag at EOF emits a stray newline, so drop it and end in PHP.
        // (NB: never put a literal close tag inside a slash comment — PHP
        //  terminates PHP mode there too.)
        $trimmed = rtrim($new);
        if (substr($trimmed, -2) === '?>') {
            $new = rtrim(substr($trimmed, 0, -2)) . "\n";
            $changed[] = "closing tag removed $rel";
        }
        // normalise to LF and guarantee a single trailing newline
        $new = str_replace(["\r\n", "\r"], "\n", $new);
        if (!str_ends_with($new, "\n")) $new .= "\n";
    }

    if ($new !== $src) {
        if ($write) file_put_contents($path, $new);
        else $changed[] = "(dry run) would fix  $rel";
    }
}

foreach (array_unique($changed) as $c) echo $c . "\n";
echo ($write ? 'applied: ' : 'would fix: ') . count(array_unique($changed)) . " file(s)\n";
