<?php
/**
 * ---------------------------------------------------------------------------
 * FlatMate  |  Diagnostics
 * ---------------------------------------------------------------------------
 * A page that fails tells you nothing useful. A toast saying "Request failed
 * (500)" does not say whether the cause was a bad column, a rejected prepared
 * statement, a missing extension, a stale database or a typo in a route -- and
 * the difference between those five is the whole debugging session.
 *
 * So this class answers the question in one request:
 *
 *   Diag::report()  what does this installation actually look like right now?
 *                   Environment, database, and -- most usefully -- whether the
 *                   live schema still matches sql/schema.sql on disk.
 *
 *   Diag::record()  append a line to a small ring log so a failure that never
 *                   reached the browser (the reminder bell swallows its own
 *                   errors, for instance) is still recoverable afterwards.
 *
 * Every method is failure-tolerant by design. A diagnostic tool that throws is
 * worse than no diagnostic tool, so nothing here is allowed to raise.
 */

declare(strict_types=1);

final class Diag
{
    /** Ring log file. Kept small on purpose; it is a buffer, not an archive. */
    private const LOG_NAME       = 'diag.jsonl';
    private const LOG_MAX_BYTES  = 262144;   // 256 KB, then rotate to .1
    private const LOG_KEEP_LINES = 120;

    /* ======================================================================= */
    /*  Ring log                                                               */
    /* ======================================================================= */

    /**
     * Append one event to the ring log.
     *
     * Best-effort by contract: any failure here (read-only host, missing
     * directory, full disk) is swallowed. Losing a log line must never be the
     * reason a page breaks.
     */
    public static function record(string $level, string $message, array $context = []): void
    {
        try {
            $entry = [
                't'      => gmdate('c'),
                'level'  => $level,
                'msg'    => mb_substr($message, 0, 2000),
                'ctx'    => self::scrub($context),
                'action' => (string) ($_GET['action'] ?? $_POST['action'] ?? ''),
                'uri'    => mb_substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 300),
                'ua'     => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160),
            ];

            $file = self::logFile();
            if ($file === null) {
                error_log('[FlatMate][diag] ' . $entry['level'] . ' ' . $entry['msg']);
                return;
            }

            $line = json_encode(
                $entry,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            );

            // Rotate before appending so the file can never exceed the cap.
            if (is_file($file) && filesize($file) > self::LOG_MAX_BYTES) {
                @rename($file, $file . '.1');
            }

            @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // Intentionally empty: see the docblock.
        }
    }

    /** The most recent ring-log entries, newest last. */
    public static function recent(int $limit = 40): array
    {
        try {
            $file = self::logFile();
            if ($file === null || !is_readable($file)) {
                return [];
            }

            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                return [];
            }

            $lines = array_slice($lines, -$limit);
            $out   = [];
            foreach ($lines as $line) {
                $decoded = json_decode($line, true);
                $out[]   = is_array($decoded) ? $decoded : ['msg' => $line];
            }
            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /** Empty the ring log. Returns whether anything was actually there to clear,
     so the caller can tell "cleared" from "there was nothing" rather than
     reporting success for a log that never existed. */
    public static function clear(): bool
    {
        try {
            $file = self::logFile();
            if ($file === null || !is_file($file)) {
                return false;
            }
            return @unlink($file);
        } catch (Throwable) {
            return false;
        }
    }

    private static function logFile(): ?string
    {
        $dir = self::storageDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            return null;
        }
        return $dir . self::LOG_NAME;
    }

    private static function storageDir(): string
    {
        return dirname(__DIR__) . '/storage';
    }

    /**
     * Redact anything that looks like a secret before it reaches the log.
     *
     * The log is written to disk on a shared host and is echoed back in the
     * diagnostics report, so it must not become a place passwords accumulate.
     */
    private static function scrub(array $context): array
    {
        $secretish = '/(pass(word)?|secret|token|auth|cookie|csrf|credential)/i';

        array_walk_recursive($context, static function (&$value, $key) use ($secretish): void {
            if (is_string($key) && preg_match($secretish, (string) $key)) {
                $value = '[redacted]';
                return;
            }
            if (is_string($value) && preg_match($secretish, (string) $key ?? '')) {
                $value = '[redacted]';
            }
        });

        return $context;
    }

    /* ======================================================================= */
    /*  Report                                                                 */
    /* ======================================================================= */

    /**
     * Everything worth pasting into a bug report.
     *
     * @param bool $full Include data counts, the error-log tail and who is
     *                   signed in. Pass false when nobody is authenticated --
     *                   diag.php is reachable without a session precisely so
     *                   that a broken login can be diagnosed, and an anonymous
     *                   visitor must not be able to read the resident list.
     */
    public static function report(bool $full = true): array
    {
        $report = [
            'generated_at' => gmdate('c'),
            'app'          => self::appInfo(),
            'php'          => self::phpInfo(),
            'web'          => self::webInfo(),
            'db'           => self::dbInfo(),
            'schema'       => self::schemaInfo(),
            'checks'       => [],
            'storage'      => self::storageInfo(),
            'full'         => $full,
        ];

        if ($full) {
            $report['data']    = self::dataInfo();
            $report['session'] = self::sessionInfo();
            $report['error_log'] = self::errorLogInfo();
            $report['log']     = self::recent(40);
        }

        $report['checks'] = self::checks($report);
        $report['verdict'] = self::verdict($report);

        return $report;
    }

    /** One-line summary, handy when someone just wants to paste something. */
    public static function summary(array $report): string
    {
        $lines = [
            sprintf(
                'FlatMate %s | env=%s | PHP %s | %s',
                $report['app']['version'],
                $report['app']['env'],
                $report['php']['version'],
                PHP_SAPI
            ),
            sprintf(
                'DB %s | MySQL %s | schema %s',
                $report['db']['connected'] ? 'connected' : 'NOT CONNECTED',
                $report['db']['server_version'] ?: '?',
                $report['schema']['status']
            ),
            sprintf('verdict: %s', $report['verdict']['headline']),
        ];

        foreach ($report['verdict']['problems'] as $p) {
            $lines[] = '  - ' . $p;
        }
        foreach ($report['schema']['missing_tables'] as $t) {
            $lines[] = '  - missing table: ' . $t;
        }
        foreach ($report['schema']['missing_columns'] as $c) {
            $lines[] = '  - missing column: ' . $c;
        }
        foreach ($report['schema']['extra_columns'] as $c) {
            $lines[] = '  - unexpected column: ' . $c;
        }

        return implode("\n", $lines);
    }

    private static function verdict(array $report): array
    {
        $problems = [];

        if (!$report['db']['connected']) {
            $problems[] = 'The database is not reachable: ' . ($report['db']['error'] ?: 'unknown reason');
        }
        foreach ($report['schema']['missing_tables'] as $t) {
            $problems[] = "Table `$t` does not exist -- run sql/schema.sql";
        }
        foreach ($report['schema']['missing_columns'] as $c) {
            $problems[] = "Column `$c` is missing -- the database is behind sql/schema.sql";
        }
        foreach ($report['schema']['missing_views'] as $v) {
            $problems[] = "View `$v` does not exist -- run sql/patch.sql";
        }
        foreach ($report['checks'] as $check) {
            if ($check['ok'] === false && $check['critical']) {
                $problems[] = $check['name'] . ': ' . $check['detail'];
            }
        }
        if (!$report['storage']['writable']) {
            $problems[] = 'The storage/ directory is not writable, so server-side '
                        . 'error logging is falling back to error_log only.';
        }

        return [
            'ok'       => $problems === [],
            'headline' => $problems === [] ? 'no problems detected' : count($problems) . ' problem(s) found',
            'problems' => $problems,
        ];
    }

    /* ======================================================================= */
    /*  Sections                                                               */
    /* ======================================================================= */

    private static function appInfo(): array
    {
        return [
            'version'  => defined('FLATMATE_VERSION') ? FLATMATE_VERSION : '?',
            'name'     => (string) config('app.name', 'FlatMate'),
            'env'      => (string) config('app.env', 'local'),
            'base_url' => function_exists('base_url') ? (string) base_url() : '?',
            'timezone' => (string) config('app.timezone', '?'),
            'currency' => (string) config('app.currency', ''),
        ];
    }

    private static function phpInfo(): array
    {
        $extensions = [];
        foreach (['pdo_mysql', 'pdo', 'mbstring', 'json', 'openssl', 'session', 'fileinfo'] as $ext) {
            $extensions[$ext] = extension_loaded($ext);
        }

        return [
            'version'            => PHP_VERSION,
            'sapi'               => PHP_SAPI,
            'os'                 => PHP_OS_FAMILY,
            'extensions'         => $extensions,
            'missing_extensions' => array_keys(array_filter(
                $extensions,
                static fn(bool $on): bool => !$on
            )),
            'memory_limit'       => (string) ini_get('memory_limit'),
            'max_execution_time' => (string) ini_get('max_execution_time'),
            'max_input_vars'     => (string) ini_get('max_input_vars'),
            'post_max_size'      => (string) ini_get('post_max_size'),
            'upload_max_filesize'=> (string) ini_get('upload_max_filesize'),
            'upload_tmp_dir'     => (string) ini_get('upload_tmp_dir'),
            'upload_tmp_writable'=> is_writable((string) (ini_get('upload_tmp_dir') ?: sys_get_temp_dir())),
            'date_default_timezone_set' => date_default_timezone_get() !== 'UTC',
            'display_errors'     => (string) ini_get('display_errors'),
            'error_reporting'    => (string) error_reporting(),
        ];
    }

    private static function webInfo(): array
    {
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

        return [
            'https'        => $https,
            'request_uri'  => (string) ($_SERVER['REQUEST_URI'] ?? ''),
            'script_name'  => (string) ($_SERVER['SCRIPT_NAME'] ?? ''),
            'remote_addr'  => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'server_software' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? ''),
            'session_name' => (string) config('app.session_name', 'FLATMATE_SESSID'),
            'session_cookie_sent' => isset($_COOKIE[(string) config('app.session_name', 'FLATMATE_SESSID')]),
        ];
    }

    private static function dbInfo(): array
    {
        $info = [
            'connected'        => false,
            'server_version'   => '',
            'database'         => (string) config('db.database', ''),
            'charset'          => (string) config('db.charset', ''),
            'emulate_prepares' => false,
            'sql_mode'         => '',
            'time_zone'        => '',
            'error'            => '',
        ];

        try {
            $pdo     = Database::conn();
            $info['connected'] = true;
            $info['server_version'] = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            $info['sql_mode']      = (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
            $info['time_zone']     = (string) $pdo->query('SELECT @@SESSION.time_zone')->fetchColumn();
            $info['emulate_prepares'] =
                (bool) $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
            $info['strict_mode'] = str_contains(strtoupper($info['sql_mode']), 'STRICT');
        } catch (Throwable $e) {
            $info['error'] = $e->getMessage();
        }

        return $info;
    }

    /**
     * Does the live database still match sql/schema.sql on disk?
     *
     * This is the single most useful thing a bug report can carry. Every 1054
     * and 1136 error so far came from the server and the code disagreeing, and
     * "which one is stale" is otherwise invisible from the browser.
     */
    private static function schemaInfo(): array
    {
        $info = [
            'expected_tables'  => 0,
            'present_tables'   => 0,
            'missing_tables'   => [],
            'missing_columns'  => [],
            'extra_columns'    => [],
            'missing_views'    => [],
            'present_views'    => [],
            'schema_file'      => '',
            'status'           => 'unknown',
        ];

        $file = dirname(__DIR__) . '/sql/schema.sql';
        $info['schema_file'] = $file;
        if (!is_readable($file)) {
            $info['status'] = 'schema.sql not found at ' . $file;
            return $info;
        }

        $expected = self::parseSchemaTables((string) file_get_contents($file));
        $info['expected_tables'] = count($expected);
        if ($expected === []) {
            $info['status'] = 'schema.sql contained no CREATE TABLE statements';
            return $info;
        }

        try {
            $live = Database::conn()->query(
                'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()'
            )->fetchAll(PDO::FETCH_KEY_PAIR);

            $liveTables = [];
            foreach ($live as $table => $_) {
                $liveTables[strtolower((string) $table)] = true;
            }
            $info['present_tables'] = count($liveTables);

            foreach ($expected as $table => $cols) {
                if (!isset($liveTables[$table])) {
                    $info['missing_tables'][] = $table;
                    continue;
                }
                foreach ($cols as $col) {
                    if (!array_key_exists("$table.$col", $live)) {
                        $info['missing_columns'][] = "$table.$col";
                    }
                }
            }
            // Columns the code never asks for are harmless but worth knowing.
            foreach ($live as $tableCol => $_) {
                $table = strtolower(substr((string) $tableCol, 0, (int) strpos((string) $tableCol, '.')));
                if (isset($expected[$table]) && !in_array(substr((string) $tableCol, (int) strpos((string) $tableCol, '.') + 1), $expected[$table], true)) {
                    $info['extra_columns'][] = (string) $tableCol;
                }
            }

            $views = array_column(Database::conn()->query(
                'SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE()'
            )->fetchAll(PDO::FETCH_ASSOC), 'TABLE_NAME');
            $info['present_views'] = array_values($views);

            foreach (['vw_balance_sheet', 'vw_today_chores', 'vw_meal_coverage'] as $view) {
                if (!in_array($view, $views, true)) {
                    $info['missing_views'][] = $view;
                }
            }
        } catch (Throwable $e) {
            $info['status'] = 'could not read information_schema: ' . $e->getMessage();
            return $info;
        }

        $info['missing_tables']  = array_values($info['missing_tables']);
        $info['missing_columns'] = array_values($info['missing_columns']);
        $info['extra_columns']   = array_values($info['extra_columns']);

        $info['status'] = ($info['missing_tables'] === []
            && $info['missing_columns'] === []
            && $info['missing_views'] === [])
            ? 'in sync with sql/schema.sql'
            : 'OUT OF SYNC with sql/schema.sql';

        return $info;
    }

    /**
     * Column names per table, read from the CREATE TABLE bodies in schema.sql.
     *
     * Paren matching rather than a regex because ENUM('a','b') and
     * DECIMAL(12,2) contain parentheses of their own.
     *
     * @return array<string, list<string>>
     */
    private static function parseSchemaTables(string $sql): array
    {
        $tables = [];

        $pattern = '/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`?(\w+)`?\s*\(/i';
        if (preg_match_all($pattern, $sql, $hits, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        foreach ($hits[0] as $index => $full) {
            $table = strtolower($hits[1][$index][0]);

            /* The pattern ends with the opening paren, so the body starts right
               after the whole match and the nesting depth is already 1.
               Deriving the paren position from the table-name capture group
               instead starts the scan mid-name, and the first ')' encountered
               closes VARCHAR(191) -- which silently truncates the column list
               and then reports most of the table as missing. */
            $start = $full[1] + strlen($full[0]);

            $depth = 1;
            $quote = null;
            $i     = $start;
            $n     = strlen($sql);
            while ($i < $n) {
                $ch = $sql[$i];
                if ($quote !== null) {
                    if ($ch === '\\') {
                        $i += 2;
                        continue;
                    }
                    if ($ch === $quote) {
                        $quote = null;
                    }
                } elseif ($ch === "'" || $ch === '"' || $ch === '`') {
                    $quote = $ch;
                } elseif ($ch === '(') {
                    $depth++;
                } elseif ($ch === ')') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
                $i++;
            }
            $body = substr($sql, $start, max(0, $i - $start));

            $cols = [];
            foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
                $line = trim($line);
                if ($line === ''
                    || preg_match('/^(PRIMARY|UNIQUE|KEY|INDEX|CONSTRAINT|FOREIGN|CHECK|--|\/|\*)/i', $line)
                ) {
                    continue;
                }
                if (preg_match('/^`(\w+)`\s/', $line, $cm) === 1) {
                    $cols[] = strtolower($cm[1]);
                }
            }
            if ($cols !== []) {
                $tables[$table] = $cols;
            }
        }

        return $tables;
    }

    private static function dataInfo(): array
    {
        $counts = [];
        foreach ([
            'users', 'rooms', 'duty_groups', 'chore_areas', 'chore_tasks',
            'meal_plans', 'meals', 'meal_participants', 'expenses',
            'expense_splits', 'settlements', 'notices', 'invites',
            'activity_log', 'reminders',
        ] as $table) {
            try {
                $counts[$table] = (int) Database::value("SELECT COUNT(*) FROM `$table`");
            } catch (Throwable $e) {
                $counts[$table] = 'error: ' . $e->getMessage();
            }
        }
        return $counts;
    }

    private static function sessionInfo(): array
    {
        return [
            'signed_in' => Auth::check(),
            'user_id'   => Auth::check() ? (int) Auth::id() : null,
            'role'      => Auth::check() ? (string) (Auth::user()['role'] ?? '') : null,
            'csrf_ok'   => csrf_valid((string) ($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')),
        ];
    }

    private static function storageInfo(): array
    {
        $dir = self::storageDir();
        $file = $dir . '/' . self::LOG_NAME;

        return [
            'dir'          => $dir,
            'exists'       => is_dir($dir),
            'writable'     => is_dir($dir) && is_writable($dir),
            'log_file'     => $file,
            'log_bytes'    => is_file($file) ? (int) filesize($file) : 0,
            'log_lines'    => is_file($file) ? max(0, count(file($file) ?: [])) : 0,
        ];
    }

    private static function errorLogInfo(): array
    {
        $out = ['configured_type' => (string) ini_get('log_errors') ? 'on' : 'off', 'path' => '', 'recent' => []];

        try {
            $dest = (string) ini_get('error_log');
            if ($dest === '') {
                $dest = 'php://stderr (shared host: usually lands in the hosting control panel log)';
            }
            $out['path'] = $dest;

            // Only a real file can be tailed, and it is never this file.
            if (is_file($dest) && strlen($dest) < 4096) {
                $lines = file($dest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $out['recent'] = array_slice($lines ?: [], -30);
            }
        } catch (Throwable) {
            // Leave the defaults in place.
        }

        return $out;
    }

    /* ======================================================================= */
    /*  Self-checks                                                            */
    /* ======================================================================= */

    /**
     * Queries the app makes on every page load, run here so one broken statement
     * is reported by name instead of blanking a screen.
     */
    private static function checks(array $report): array
    {
        $checks = [];

        $add = static function (string $name, bool $ok, string $detail, bool $critical = true) use (&$checks): void {
            $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail, 'critical' => $critical];
        };

        if (!$report['db']['connected']) {
            $add('database', false, $report['db']['error'] ?: 'not connected');
            return $checks;
        }
        $add('database', true, 'connected to ' . $report['db']['database']);

        foreach ($report['php']['missing_extensions'] as $ext) {
            $add('extension:' . $ext, false, 'PHP extension ' . $ext . ' is not loaded');
        }

        foreach (['vw_balance_sheet', 'vw_today_chores', 'vw_meal_coverage'] as $view) {
            try {
                $n = (int) Database::value("SELECT COUNT(*) FROM `$view`");
                $add("view:$view", true, "$n row(s)");
            } catch (Throwable $e) {
                $add("view:$view", false, $e->getMessage());
            }
        }

        // The invariant behind the HTTP 400 the balance sheet used to throw.
        try {
            $sum = Database::value('SELECT COALESCE(SUM(net_balance), 0) FROM vw_balance_sheet');
            $add(
                'balances sum to zero',
                abs((float) $sum) < 0.005,
                'SUM(net_balance) = ' . $sum . ' (must be 0.00)',
                true
            );
        } catch (Throwable $e) {
            $add('balances sum to zero', false, $e->getMessage());
        }

        // One representative query per feature area, so a stale column in, say,
        // the chores feed is reported by name rather than as a blank board.
        foreach ([
            'dashboard users'    => 'SELECT COUNT(*) FROM vw_balance_sheet WHERE status IN ("active","invited")',
            'chore board'        => 'SELECT COUNT(*) FROM vw_today_chores',
            'meal coverage'      => 'SELECT COUNT(*) FROM vw_meal_coverage',
            'expense ledger'     => 'SELECT COUNT(*) FROM expenses WHERE is_deleted = 0',
            'settlements'        => 'SELECT COUNT(*) FROM settlements',
            'activity feed'      => 'SELECT COUNT(*) FROM activity_log',
            'reminders'          => 'SELECT COUNT(*) FROM reminders',
            'notices'            => 'SELECT COUNT(*) FROM notices',
            'invites'            => 'SELECT COUNT(*) FROM invites',
        ] as $label => $sql) {
            try {
                $add($label, true, (string) Database::value($sql) . ' row(s)', false);
            } catch (Throwable $e) {
                $add($label, false, $e->getMessage());
            }
        }

        return $checks;
    }
}