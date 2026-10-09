<?php
/**
 * database/provision-worker-accounts.php  —  CLI
 * ---------------------------------------------------------------------------
 * Give EVERY eligible worker an individual login account (dynamic, no fixed
 * ids) and print a one-time password-setup link for each newly created one.
 *
 *   php database/provision-worker-accounts.php                # create + links
 *   php database/provision-worker-accounts.php --no-links     # create only
 *   php database/provision-worker-accounts.php --host=localhost:8899
 *   php database/provision-worker-accounts.php --json
 *
 * Nothing is deleted and existing passwords are never changed.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/worker_accounts.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$options  = getopt('', ['no-links', 'host::', 'json', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php database/provision-worker-accounts.php [--no-links] [--host=HOST] [--json]\n");
    exit(0);
}

$issueLinks = !isset($options['no-links']);
if (!empty($options['host'])) {
    $_SERVER['HTTP_HOST'] = (string) $options['host'];
}

$result = provision_worker_accounts(null, $issueLinks);
$c      = $result['counts'];

if (isset($options['json'])) {
    foreach ($result['items'] as &$item) {
        if (!empty($item['token'])) {
            $item['setup_url'] = worker_setup_url($item['token']);
            unset($item['token']);
        }
    }
    unset($item);
    echo json_encode(['counts' => $c, 'items' => $result['items']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit($c['needs_attention'] > 0 ? 1 : 0);
}

echo "Worker login accounts — provisioning\n";
echo str_repeat('=', 66), "\n";
printf(
    "eligible: %d   active: %d   awaiting setup: %d   inactive: %d   created: %d   needs attention: %d\n\n",
    $c['eligible'], $c['active'], $c['awaiting_setup'], $c['inactive'], $c['created'], $c['needs_attention']
);

foreach ($result['items'] as $item) {
    $tag = str_pad($item['state'], 15);
    $who = str_pad((string) $item['worker_code'], 10);
    $mail = $item['email'] ?: ($item['reason'] ?? '');

    if (!empty($item['token'])) {
        echo "  [created]        {$who} {$mail}\n";
        echo '                   ' . worker_setup_url($item['token']) . "\n";
    } else {
        echo "  [{$tag}] {$who} {$mail}\n";
    }
}

echo "\nDone. Share each setup link with the matching worker; they set their own password.\n";
exit($c['needs_attention'] > 0 ? 1 : 0);
