<?php
/**
 * database/migrate.php  —  idempotent schema migrations
 * ---------------------------------------------------------------------------
 * Brings an existing database up to date with schema.sql WITHOUT dropping or
 * resetting anything. Safe to run repeatedly.
 *
 *   php database/migrate.php
 *
 * Each migration is guarded by an information_schema check, so a column that
 * already exists is simply skipped. Nothing here deletes data.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/** True when a column exists on a table in the current database. */
function column_exists(string $table, string $column): bool
{
    return (bool) db_value(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$table, $column],
        0
    );
}

/**
 * Migrations: [table, column, ALTER statement, human description].
 * The column is only added when missing.
 */
$migrations = [
    [
        'users', 'password_reset_token',
        'ALTER TABLE `users` ADD COLUMN `password_reset_token` VARCHAR(255) DEFAULT NULL AFTER `last_login_at`',
        'users.password_reset_token (sha256 of a one-time worker setup/reset token)',
    ],
    [
        'users', 'password_reset_expires',
        'ALTER TABLE `users` ADD COLUMN `password_reset_expires` DATETIME DEFAULT NULL AFTER `password_reset_token`',
        'users.password_reset_expires (expiry of the setup link)',
    ],
];

echo "Smart Waste Collection Optimizer — migrations\n";
echo str_repeat('=', 60) . "\n";

$applied = 0;
$skipped = 0;
$failed  = 0;

foreach ($migrations as [$table, $column, $sql, $desc]) {
    if (column_exists($table, $column)) {
        printf("  skip   %s (already present)\n", $desc);
        $skipped++;
        continue;
    }

    try {
        db()->exec($sql);
        printf("  add    %s\n", $desc);
        $applied++;
    } catch (Throwable $e) {
        printf("  FAIL   %s -> %s\n", $desc, $e->getMessage());
        $failed++;
    }
}

echo str_repeat('-', 60) . "\n";
printf("applied %d · skipped %d · failed %d\n", $applied, $skipped, $failed);
exit($failed > 0 ? 1 : 0);
