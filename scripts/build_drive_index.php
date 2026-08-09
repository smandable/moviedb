<?php

// CLI only: the whole repo sits under httpd's DocumentRoot, so without this
// guard a bare GET to this file would execute it via mod_php.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}


/**
 * Build the drive index (server/drive_index.json): walk the configured
 * library roots (app_settings.json "driveIndexRoots", else the defaults) and
 * catalog every video file so the app can search the drives instantly.
 *
 * Usage:
 *   php scripts/build_drive_index.php            # scan + write the index
 *   php scripts/build_drive_index.php --dry-run  # report only, write nothing
 *
 * Re-run any time content moves; the whole index is rebuilt from disk each
 * run. The Settings page's "Rebuild Index" button does the same thing over HTTP.
 */

require_once __DIR__ . '/../server/drive_index_lib.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$roots = moviedb_drive_index_roots();

echo "Roots:\n";
foreach ($roots as $root) {
    echo '  ' . (is_dir($root) ? '  ' : '!! not mounted: ') . $root . "\n";
}

if ($dryRun) {
    $scan = moviedb_drive_index_scan($roots);
    echo 'Would index ' . count($scan['entries']) . " video file(s); writing nothing (--dry-run).\n";
    foreach (array_slice($scan['entries'], 0, 5) as $entry) {
        echo "  sample: {$entry['dir']}/{$entry['file']}\n";
        echo "          base: {$entry['base']}\n";
    }
    exit(0);
}

$index = moviedb_build_drive_index($roots);
if ($index === null) {
    fwrite(STDERR, "Failed to write " . MOVIEDB_DRIVE_INDEX_FILE . "\n");
    exit(1);
}
if (isset($index['error'])) {
    fwrite(STDERR, $index['error'] . "\n(previous index left untouched)\n");
    exit(1);
}

echo "Built:     {$index['builtAt']}\n";
echo "Files:     {$index['fileCount']}\n";
echo 'Missing:   ' . ($index['missingRoots'] ? implode(', ', $index['missingRoots']) : '(none)') . "\n";
echo 'Wrote:     ' . MOVIEDB_DRIVE_INDEX_FILE . "\n";
exit(0);
