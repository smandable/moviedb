<?php

// CLI only: the whole repo sits under httpd's DocumentRoot, so without this
// guard a bare GET to this file would execute it via mod_php.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

/**
 * Harness for server/db_tables.php — the Settings → Catalog Table override.
 * Run: php server/tests/db_tables_test.php
 * Fixture-only: temp settings files; never reads the real app_settings.json.
 */

require_once __DIR__ . '/../db_tables.php';

$pass = 0;
$fail = 0;
function check(string $label, $actual, $expected): void
{
    global $pass, $fail;
    if ($actual === $expected) {
        $pass++;
    } else {
        $fail++;
        echo "FAIL {$label}\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
    }
}

$f = tempnam(sys_get_temp_dir(), 'moviedb-db-tables-');
$with = function ($content) use ($f) { file_put_contents($f, $content); return $f; };

check('no settings file: DB_TABLE', moviedb_active_table('movies_het', $f . '.missing'), 'movies_het');
check('no dbTable key', moviedb_active_table('movies_het', $with('{"defaultDirectory":"/x/"}')), 'movies_het');
check('override to bi', moviedb_active_table('movies_het', $with('{"dbTable":"movies_bi"}')), 'movies_bi');
check('override to het', moviedb_active_table('movies_bi', $with('{"dbTable":"movies_het"}')), 'movies_het');
check('unknown table ignored', moviedb_active_table('movies_het', $with('{"dbTable":"mysql.user"}')), 'movies_het');
check('non-string ignored', moviedb_active_table('movies_het', $with('{"dbTable":["movies_bi"]}')), 'movies_het');
check('corrupt file ignored', moviedb_active_table('movies_het', $with('{not json')), 'movies_het');
unlink($f);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
