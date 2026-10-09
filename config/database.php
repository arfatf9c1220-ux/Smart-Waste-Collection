<?php
/**
 * config/database.php
 * ------------------------------------------------------------------
 * Single PDO connection for the whole application (PDO singleton).
 *
 * Every query in this project uses PREPARED STATEMENTS, which is the
 * main defence against SQL injection. Never concatenate user input
 * into a SQL string.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Returns the shared PDO instance, creating it on first use.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_CHARSET
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // throw on SQL errors
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,         // rows as arrays
        PDO::ATTR_EMULATE_PREPARES   => false,                    // real prepared statements
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        // Friendly message for the browser + full detail in the PHP error log.
        http_response_code(500);
        if (APP_DEBUG) {
            exit('Database connection failed: ' . $e->getMessage());
        }
        error_log('DB connection failed: ' . $e->getMessage());
        exit('Database connection failed. Please check config/database.php.');
    }

    return $pdo;
}

/**
 * Convenience helper: run a prepared statement and return the statement.
 *
 * @param string $sql    SQL with ? placeholders
 * @param array  $params Bound values
 */
function db_query(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/** Fetch a single row (or null). */
function db_row(string $sql, array $params = []): ?array
{
    $row = db_query($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** Fetch all rows as an array. */
function db_all(string $sql, array $params = []): array
{
    return db_query($sql, $params)->fetchAll();
}

/** Fetch a single scalar value (first column of first row). */
function db_value(string $sql, array $params = [], $default = null)
{
    $val = db_query($sql, $params)->fetchColumn();
    return $val === false ? $default : $val;
}

/** INSERT helper that returns the new auto-increment id. */
function db_insert(string $sql, array $params = []): int
{
    db_query($sql, $params);
    return (int) db()->lastInsertId();
}

/**
 * Runs a .sql file that may contain many statements (schema / seed / migration).
 *
 * Emulated prepares are required for this because the file has no bound
 * parameters; the connection is otherwise configured with native prepares.
 * Statements are executed one at a time so a failure reports a useful message
 * and does not abort with an opaque driver error.
 *
 * @return array<int,string> error messages; empty means success
 */
function db_run_script(string $sql): array
{
    $errors = [];

    // strip a UTF-8 BOM: it would be parsed as part of the first statement
    if (strncmp($sql, "\xEF\xBB\xBF", 3) === 0) {
        $sql = substr($sql, 3);
    }

    $prevEmulate = null;
    try {
        $prevEmulate = db()->getAttribute(PDO::ATTR_EMULATE_PREPARES);
        db()->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
    } catch (Throwable $e) {
        $errors[] = 'Could not enable multi-statement mode: ' . $e->getMessage();
        return $errors;
    }

    try {
        foreach (db_split_statements($sql) as $i => $stmt) {
            try {
                db()->exec($stmt);
            } catch (PDOException $e) {
                $errors[] = sprintf('statement %d: %s -- near: %s',
                    $i + 1, $e->getMessage(), substr(preg_replace('/\s+/', ' ', $stmt), 0, 120));
            }
        }
    } finally {
        if ($prevEmulate !== null) {
            db()->setAttribute(PDO::ATTR_EMULATE_PREPARES, (bool) $prevEmulate);
        }
    }

    return $errors;
}

/**
 * Splits a SQL script on ";" while respecting quoted strings, comments and
 * BEGIN ... END blocks so a semicolon inside a trigger body is not a split.
 *
 * @return array<int,string>
 */
function db_split_statements(string $sql): array
{
    $out   = [];
    $buf   = '';
    $len   = strlen($sql);
    $inS   = false;  // '...'
    $inD   = false;  // "..."
    $inB   = false;  // `...`
    $depth = 0;      // BEGIN ... END nesting

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        $nx = $i + 1 < $len ? $sql[$i + 1] : '';

        // -- line comments
        if (!$inS && !$inD && !$inB) {
            if (($ch === '-' && $nx === '-') || $ch === '#') {
                $end = strpos($sql, "\n", $i);
                $end = $end === false ? $len : $end;
                $buf .= substr($sql, $i, $end - $i);
                $i = $end - 1;
                continue;
            }
            if ($ch === '/' && $nx === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $end = $end === false ? $len : $end + 2;
                $buf .= substr($sql, $i, $end - $i);
                $i = $end - 1;
                continue;
            }
        }

        if ($ch === "'" && !$inD && !$inB) {
            // backslash escape or doubled quote
            if ($nx === '\\') { $buf .= $ch . $nx; $i++; continue; }
            if ($inS && $nx === "'") { $buf .= $ch . $nx; $i++; continue; }
            $inS = !$inS;
            $buf .= $ch;
            continue;
        }
        if ($ch === '"' && !$inS && !$inB) {
            if ($nx === '\\') { $buf .= $ch . $nx; $i++; continue; }
            if ($inD && $nx === '"') { $buf .= $ch . $nx; $i++; continue; }
            $inD = !$inD;
            $buf .= $ch;
            continue;
        }
        if ($ch === '`' && !$inS && !$inD) {
            $inB = !$inB;
            $buf .= $ch;
            continue;
        }

        if (!$inS && !$inD && !$inB) {
            if (preg_match('/\bBEGIN\b/i', substr($buf . $ch, -8))) $depth++;
            if (preg_match('/\bEND\b/i', substr($buf . $ch, -6)) && $depth > 0) $depth--;

            if ($ch === ';' && $depth === 0) {
                $trimmed = trim($buf);
                if ($trimmed !== '') $out[] = $trimmed;
                $buf = '';
                continue;
            }
        }

        $buf .= $ch;
    }

    $trimmed = trim($buf);
    if ($trimmed !== '') $out[] = $trimmed;

    return $out;
}
