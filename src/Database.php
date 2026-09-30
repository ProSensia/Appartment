<?php
/**
 * ---------------------------------------------------------------------------
 * FlatMate  |  Database facade
 * ---------------------------------------------------------------------------
* Autoloaded from src/ like every other service. The single PDO handle lives
 * here; db.* credentials come from config/config.php. (config/database.php is
 * an optional shim that returns this same handle -- pages load Bootstrap.php.)
 *
 * Everything goes through named parameters -- there is no string interpolation
 * of user input into SQL anywhere in this project.
 */

declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;
    private static ?array $cfg = null;

    /** Build the DSN from config. */
    public static function dsn(): string
    {
        $c = self::config()['db'];
        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $c['host'],
            (int) $c['port'],
            $c['database'],
            $c['charset']
        );
    }

    /** Lazily open (and memoize) the PDO handle. */
    public static function conn(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $c = self::config()['db'];

        try {
            self::$pdo = new PDO(self::dsn(), $c['username'], $c['password'], $c['options']);
        } catch (PDOException $e) {
            self::fail(
                'Cannot connect to the database.',
                $e,
                (self::config()['app']['env'] ?? 'local') !== 'production'
            );
        }

        // Strict-ish SQL mode so silent truncation / zero dates are errors.
        self::$pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION,ERROR_FOR_DIVISION_BY_ZERO'");
        self::$pdo->exec("SET SESSION time_zone = '+00:00'");

        return self::$pdo;
    }

    /** SELECT helper. */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::conn()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** First row or null. */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** All rows. */
    public static function all(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /** Single scalar value (first column of first row). */
    public static function value(string $sql, array $params = [], mixed $default = null): mixed
    {
        $v = self::query($sql, $params)->fetchColumn();
        return $v === false ? $default : $v;
    }

    /** INSERT and return the new auto-increment id. */
    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql  = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            '`' . implode('`,`', $cols) . '`',
            ':' . implode(', :', $cols)
        );
        self::query($sql, $data);
        return (int) self::conn()->lastInsertId();
    }

    /**
     * INSERT IGNORE â€” returns the number of rows actually inserted
     * (0 when the row already existed and was skipped).
     */
    public static function insertOrIgnore(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql  = sprintf(
            'INSERT IGNORE INTO `%s` (%s) VALUES (%s)',
            $table,
            '`' . implode('`,`', $cols) . '`',
            ':' . implode(', :', $cols)
        );
        return self::query($sql, $data)->rowCount();
    }

    /** UPDATE ... WHERE $whereCol = $whereVal. Returns affected rows. */
    public static function update(string $table, array $data, string $whereCol, mixed $whereVal): int
    {
        $sets = [];
        foreach (array_keys($data) as $c) {
            $sets[] = "`$c` = :set_$c";
        }
        $params = [];
        foreach ($data as $c => $v) {
            $params["set_$c"] = $v;
        }
        $params['where_val'] = $whereVal;

        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE `%s` = :where_val',
            $table,
            implode(', ', $sets),
            $whereCol
        );
        return self::query($sql, $params)->rowCount();
    }

    /** DELETE ... WHERE $whereCol = $whereVal. */
    public static function delete(string $table, string $whereCol, mixed $whereVal): int
    {
        return self::query(
            sprintf('DELETE FROM `%s` WHERE `%s` = :v', $table, $whereCol),
            ['v' => $whereVal]
        )->rowCount();
    }

    /* ---------------------------------------------------------------- */
    /*  Transactions                                                     */
    /* ---------------------------------------------------------------- */

    public static function begin(): void
    {
        if (!self::conn()->inTransaction()) {
            self::conn()->beginTransaction();
        }
    }

    public static function commit(): void
    {
        if (self::conn()->inTransaction()) {
            self::conn()->commit();
        }
    }

    public static function rollback(): void
    {
        if (self::conn()->inTransaction()) {
            self::conn()->rollBack();
        }
    }

    /** Run $fn inside a transaction, rolling back on any throwable. */
    public static function transaction(callable $fn): mixed
    {
        self::begin();
        try {
            $result = $fn();
            self::commit();
            return $result;
        } catch (Throwable $e) {
            self::rollback();
            throw $e;
        }
    }

    /* ---------------------------------------------------------------- */
    /*  Introspection helpers                                            */
    /* ---------------------------------------------------------------- */

    public static function tableExists(string $table): bool
    {
        $t = self::value(
            'SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = :t',
            ['t' => $table]
        );
        return (int) $t > 0;
    }

    /** Health probe used by /api/index.php?action=health */
    public static function health(): array
    {
        $cfg = self::config()['db'];
        return [
            'connected' => self::tableExists('users'),
            'server'    => (string) self::value('SELECT VERSION()'),
            'database'  => $cfg['database'],
            'schema'    => self::tableExists('chore_tasks') ? 'installed' : 'missing',
            'time'      => gmdate('c'),
        ];
    }

    /* ---------------------------------------------------------------- */
    /*  Internals                                                        */
    /* ---------------------------------------------------------------- */

    private static function config(): array
    {
        if (self::$cfg === null) {
            self::$cfg = require dirname(__DIR__) . '/config/config.php';
        }
        return self::$cfg;
    }

    private static function fail(string $message, Throwable $e, bool $verbose): never
    {
        error_log('[FlatMate][DB] ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        $detail = $verbose
            ? '<pre style="white-space:pre-wrap;text-align:left;background:#1e1e2e;color:#cdd6f4;padding:16px;border-radius:10px">'
                . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>'
            : '';
        echo '<!doctype html><meta charset="utf-8"><title>Database error</title>'
            . '<div style="font-family:system-ui;max-width:760px;margin:12vh auto;padding:0 20px">'
            . '<h1 style="color:#c01c28">ðŸš« Database connection failed</h1>'
            . '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
            . $detail
            . '<p style="color:#666">Check <code>config/config.php</code> &rarr; <code>db</code>, then run '
            . '<code>sql/schema.sql</code> and <code>sql/seed.sql</code> in phpMyAdmin.</p></div>';
        exit;
    }
}

