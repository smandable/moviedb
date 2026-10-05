<?php

// CLI only: the whole repo sits under httpd's DocumentRoot, so without this
// guard a bare GET to this file would execute it via mod_php.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

/**
 * Report junk and duplicates in the cast-name vocabulary (server/cast_names.json)
 * — the same audit as Settings → "Check for junk & duplicates". Read-only.
 *
 * Usage:
 *   php scripts/audit_cast_names.php          # findings not yet dismissed
 *   php scripts/audit_cast_names.php --all    # include dismissed findings
 *
 * The number after each name is how many indexed files use that spelling in a
 * cast tail (drive_index.json — its roots only).
 */

require_once __DIR__ . '/../server/cast_audit_lib.php';
require_once __DIR__ . '/../server/drive_index_lib.php';

$all = in_array('--all', $argv ?? [], true);

if (!is_file(MOVIEDB_CAST_STORE)) {
    fwrite(STDERR, 'No cast-name store at ' . MOVIEDB_CAST_STORE . "\n");
    exit(1);
}

$names = moviedb_load_cast_store();
$audit = moviedb_cast_audit($names, $all ? [] : moviedb_cast_audit_load_dismissed());
$index = moviedb_load_drive_index();
$usage = moviedb_cast_audit_usage($index['entries'] ?? []);

$labels = ['duplicate' => 'Duplicates', 'variant' => 'Likely misspellings', 'junk' => 'Not name-shaped'];
$byKind = [];
foreach ($audit['findings'] as $finding) {
    $byKind[$finding['kind']][] = $finding;
}

printf("%d names; drive index %s\n", count($names), $index === null
    ? 'missing (use counts unavailable)'
    : 'built ' . ($index['builtAt'] ?? '?') . ', ' . count($index['entries']) . ' files');
foreach ($labels as $kind => $label) {
    $list = $byKind[$kind] ?? [];
    printf("\n== %s (%d)\n", $label, count($list));
    foreach ($list as $finding) {
        $shown = array_map(
            fn($n) => $n . ' (' . ($usage[mb_strtolower($n)]['count'] ?? 0) . ')',
            $finding['names']
        );
        echo '  ', implode('  |  ', $shown);
        echo $kind === 'variant' ? "\n" : "   — {$finding['reason']}\n";
    }
}
if ($audit['hidden'] > 0) {
    printf("\n%d dismissed finding(s) hidden; --all shows them.\n", $audit['hidden']);
}
