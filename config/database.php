<?php
/**
 * ---------------------------------------------------------------------------
 * FlatMate  |  Database connection file
 * ---------------------------------------------------------------------------
 * The single PDO entry point for the whole application.
 *
 *   $db = require __DIR__ . '/../config/database.php';
 *   $rows = $db->query('SELECT * FROM rooms')->fetchAll();
 *
 * Internally everything goes through the autoloaded Database facade in
 * src/Database.php, so services all share one connection and one transaction
 * boundary per request. Credentials live in config/config.php.
 *
 * If the server cannot reach MySQL you get a readable page explaining exactly
 * what to fix, instead of a blank screen.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Bootstrap.php';

// Bail out early (and loudly) if the schema has not been imported yet.
if (!Database::tableExists('users')) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Schema missing</title>'
        . '<div style="font-family:system-ui;max-width:760px;margin:12vh auto;padding:0 20px">'
        . '<h1 style="color:#c01c28">&#9888; Tables not found</h1>'
        . '<p>The connection worked, but <code>' . htmlspecialchars((string) config('db.database'), ENT_QUOTES)
        . '</code> has no FlatMate tables yet.</p>'
        . '<p>Import these two files in phpMyAdmin (or the CLI), in order:</p>'
        . '<ol><li><code>sql/schema.sql</code></li><li><code>sql/seed.sql</code></li></ol>'
        . '</div>';
    exit;
}

return Database::conn();