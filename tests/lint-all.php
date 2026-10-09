<?php
declare(strict_types=1);
/**
 * tests/lint-all.php
 *
 * 1. php -l on every project PHP file (catches unbalanced braces, and also the
 *    subtle "close tag inside a // comment ends PHP mode" trap, which shows up
 *    as a parse error).
 * 2. token-aware scan with token_get_all() for the same trap, reporting the
 *    exact line so the cause is obvious.
 *
 * Usage: php tests/lint-all.php
 */
$root = dirname(__DIR__);
$skip = ['vendor', 'node_modules', '.git', 'uploads'];

$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$files = [];
foreach ($rii as $f) {
    if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') continue;
    $p = str_replace('\\', '/', $f->getPathname());
    foreach ($skip as $s) {
        if (strpos($p, '/' . $s . '/') !== false) continue 2;
    }
    $files[] = $p;
}
sort($files);

$rootNorm = str_replace('\\', '/', $root);
$syntax   = 0;
$tagTrap  = 0;

/**
 * PHP closes PHP mode on a close tag even inside a one-line comment, so any
 * "//" or "#" comment containing the literal two-character close sequence is a
 * bug: the remainder of the file is emitted to the browser as raw text.
 * A "/* *\/" block comment is harmless because it cannot span a close tag.
 */
function commentClosesPhp(string $text): bool
{
    $one = strncmp(ltrim($text), '//', 2) === 0 || strncmp(ltrim($text), '#', 1) === 0;
    return $one && strpos($text, '?' . '>') !== false;
}

foreach ($files as $file) {
    $rel = substr($file, strlen($rootNorm) + 1);

    // ---- php -l ----------------------------------------------------------
    $out = [];
    $rc  = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $out, $rc);
    if ($rc !== 0) {
        echo "SYNTAX  $rel\n";
        foreach ($out as $line) echo "        $line\n";
        $syntax++;
        continue;
    }

    // ---- token scan ------------------------------------------------------
    $src  = file_get_contents($file);
    $prev = null;
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if (in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true) && commentClosesPhp($tok[1])) {
                printf("TAG-TRAP  %s:%d  %s\n", $rel, $tok[2], trim($tok[1]));
                $tagTrap++;
            }
            $prev = $tok[0];
        }
    }
}

echo str_repeat('=', 64) . "\n";
printf("php files linted : %d\n", count($files));
printf("syntax errors    : %d\n", $syntax);
printf("close-tag-in-comment : %d\n", $tagTrap);
echo str_repeat('-', 64) . "\n";

// ---- delegate encoding / EOF checks to bom-scan ---------------------------
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tests/bom-scan.php'));
$bomRc = 0;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tests/bom-scan.php'), $o, $bomRc);

exit(($syntax || $tagTrap || $bomRc) ? 1 : 0);