<?php

// CLI only: the whole repo sits under httpd's DocumentRoot, so without this
// guard a bare GET to this file would execute it via mod_php.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}


/**
 * Verification harness for server/drive_index_lib.php — build (skips,
 * depth cap, base derivation, sorting), search (ci substring, grouping,
 * cap counts), trash (fallback .moviedb_trash move, index rewrite,
 * collision suffix, refusals), and reveal validation (dry-run only; the
 * harness never touches the GUI or /Volumes).
 *
 * Fixtures use FICTIONAL names in a throwaway tree under the system temp dir.
 *
 *   Run:  php server/tests/drive_index_test.php
 *   Exit: 0 = all checks passed, 1 = at least one failed.
 */

require_once __DIR__ . '/../drive_index_lib.php';

$failures = 0;
$checks = 0;

function check(string $label, $actual, $expected): void
{
    global $failures, $checks;
    $checks++;
    if ($actual === $expected) {
        echo "  ok: $label\n";
        return;
    }
    $failures++;
    fwrite(STDERR, sprintf(
        "FAIL: %s\n    expected: %s\n    actual:   %s\n",
        $label,
        var_export($expected, true),
        var_export($actual, true)
    ));
}

function mkfile(string $path, string $content = 'x'): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, $content);
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        is_dir($path) ? rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

$fx = rtrim(sys_get_temp_dir(), '/') . '/moviedb_drive_index_test_' . getmypid();
rrmdir($fx); // stale tree from a crashed earlier run
$rootA = $fx . '/rootA';
$missingRoot = $fx . '/missingRoot';
$indexPath = $fx . '/drive_index.json';

mkfile($rootA . '/Faux Manor - Scene_1 - Jane Fauxheart.mp4', 'scene one');
mkfile($rootA . '/Faux Manor - Scene_2.mp4', 'scene two');
mkfile($rootA . '/Solo Feature.mkv', 'a solo feature');
mkfile($rootA . '/Loud Extension.MKV');
mkfile($rootA . '/notes.txt');
mkfile($rootA . '/.hidden.mp4');
mkfile($rootA . '/Nested/Deep Cut - Bonus Footage.avi');
mkfile($rootA . '/d1/d2/d3/d4/Depth Four.mp4');
mkfile($rootA . '/d1/d2/d3/d4/d5/Too Deep.mp4');

echo "default roots:\n";
check('the five default roots', moviedb_drive_index_default_roots(), [
    '/Volumes/Recorded 1/recorded',
    '/Volumes/Recorded 2/recorded',
    '/Volumes/Recorded 3/recorded',
    '/Volumes/Recorded 4/recorded',
    '/Volumes/Etc/Extra',
]);

echo "settings-backed roots:\n";
$settingsFile = $fx . '/app_settings.json';
mkfile($settingsFile, json_encode(['driveIndexRoots' => ['/a/', '/b']]));
check('settings override wins, trailing slash trimmed',
    moviedb_drive_index_roots($settingsFile), ['/a', '/b']);
mkfile($settingsFile, json_encode(['driveIndexRoots' => []]));
check('empty override falls back to defaults',
    moviedb_drive_index_roots($settingsFile), moviedb_drive_index_default_roots());
mkfile($settingsFile, json_encode(['driveIndexRoots' => ['/a', 42]]));
check('non-string entry falls back to defaults',
    moviedb_drive_index_roots($settingsFile), moviedb_drive_index_default_roots());
check('missing settings file falls back to defaults',
    moviedb_drive_index_roots($fx . '/no_such_settings.json'),
    moviedb_drive_index_default_roots());

echo "build:\n";
$index = moviedb_build_drive_index([$rootA, $missingRoot], $indexPath);
check('build returns an array', is_array($index), true);
check('fileCount: video files only, no dotfiles, depth capped', $index['fileCount'], 6);
check('entries count matches fileCount', count($index['entries']), 6);
check('missing root is skipped but recorded', $index['missingRoots'], [$missingRoot]);
check('roots recorded as given', $index['roots'], [$rootA, $missingRoot]);
check('builtAt is ISO8601', (bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $index['builtAt']), true);
check('sorted by base then file (natural, ci)', array_column($index['entries'], 'file'), [
    'Deep Cut - Bonus Footage.avi',
    'Depth Four.mp4',
    'Faux Manor - Scene_1 - Jane Fauxheart.mp4',
    'Faux Manor - Scene_2.mp4',
    'Loud Extension.MKV',
    'Solo Feature.mkv',
]);
check('base strips the Bonus suffix', $index['entries'][0]['base'], 'Deep Cut');
check('base strips the Scene suffix', $index['entries'][2]['base'], 'Faux Manor');
check('scene siblings share a base', $index['entries'][3]['base'], 'Faux Manor');
check('nested file keeps its own dir', $index['entries'][0]['dir'], $rootA . '/Nested');
check('depth-4 file is indexed', $index['entries'][1]['dir'], $rootA . '/d1/d2/d3/d4');
$soloEntry = $index['entries'][5];
check('size recorded', $soloEntry['size'], strlen('a solo feature'));
check('mtime recorded', $soloEntry['mtime'] > 0, true);
check('index file written', is_file($indexPath), true);
$loaded = moviedb_load_drive_index($indexPath);
check('load returns what build wrote', $loaded, $index);

echo "load edge cases:\n";
check('missing index loads as null', moviedb_load_drive_index($fx . '/nope.json'), null);
mkfile($fx . '/garbage.json', 'not json at all');
check('garbage index loads as null', moviedb_load_drive_index($fx . '/garbage.json'), null);

echo "search:\n";
$res = moviedb_search_drive_index('faux manor', $index);
check('one group for the shared base', count($res['groups']), 1);
check('group base casing preserved', $res['groups'][0]['base'], 'Faux Manor');
check('both scene files in the group', count($res['groups'][0]['files']), 2);
check('file path is dir + "/" + file',
    $res['groups'][0]['files'][0]['path'],
    $rootA . '/Faux Manor - Scene_1 - Jane Fauxheart.mp4');
check('totalGroups counted', $res['totalGroups'], 1);
check('totalFiles counted', $res['totalFiles'], 2);
$res = moviedb_search_drive_index('FAUX MANOR', $index);
check('search is case-insensitive', $res['totalFiles'], 2);
$res = moviedb_search_drive_index('fauxheart', $index);
check('filename-only hit still matches (base has no "fauxheart")', $res['totalFiles'], 1);
check('filename-only hit groups under its base', $res['groups'][0]['base'], 'Faux Manor');
$res = moviedb_search_drive_index('zzz-nothing', $index);
check('no hits: empty groups', $res['groups'], []);
check('no hits: zero totals', [$res['totalGroups'], $res['totalFiles']], [0, 0]);
check('empty query is an error', isset(moviedb_search_drive_index('', $index)['error']), true);
check('whitespace-only query is an error', isset(moviedb_search_drive_index('   ', $index)['error']), true);
check('121-char query is an error',
    isset(moviedb_search_drive_index(str_repeat('a', 121), $index)['error']), true);
check('validation against a null (unbuilt) index is refused',
    moviedb_drive_index_validate_path('/anywhere/file.mp4', null)['ok'], false);

echo "search cap:\n";
$bigEntries = [];
for ($i = 1; $i <= 60; $i++) {
    $n = str_pad((string) $i, 3, '0', STR_PAD_LEFT);
    foreach (['Scene_1', 'Scene_2'] as $scene) {
        $bigEntries[] = [
            'base'  => "Cap Title $n",
            'file'  => "Cap Title $n - $scene.mp4",
            'dir'   => '/fake/dir',
            'size'  => 1,
            'mtime' => 1,
        ];
    }
}
$big = ['roots' => ['/fake'], 'entries' => $bigEntries];
$res = moviedb_search_drive_index('cap title', $big);
check('groups capped at 50', count($res['groups']), 50);
check('totalGroups counted before the cap', $res['totalGroups'], 60);
check('totalFiles counted before the cap', $res['totalFiles'], 120);
check('capped groups keep alphabetical order', $res['groups'][0]['base'], 'Cap Title 001');

echo "trash (fixture root -> .moviedb_trash fallback beside the root):\n";
$soloPath = $rootA . '/Solo Feature.mkv';
$trash = moviedb_trash_drive_files([$soloPath], $indexPath);
check('index rewrite reported', $trash['indexUpdated'], true);
$results = $trash['results'];
check('reported trashed', $results[0]['trashed'], true);
check('moved into .moviedb_trash beside the root',
    $results[0]['to'], $rootA . '/.moviedb_trash/Solo Feature.mkv');
check('file exists at the trash target', is_file($rootA . '/.moviedb_trash/Solo Feature.mkv'), true);
check('original path is gone', file_exists($soloPath), false);
$after = moviedb_load_drive_index($indexPath);
check('index rewritten without the entry', $after['fileCount'], 5);
check('trashed file no longer in entries',
    in_array('Solo Feature.mkv', array_column($after['entries'], 'file'), true), false);

echo "trash collision suffix:\n";
$index = moviedb_build_drive_index([$rootA, $missingRoot], $indexPath);
check('rebuild skips the .moviedb_trash dir', $index['fileCount'], 5);
$scene2 = $rootA . '/Faux Manor - Scene_2.mp4';
$results = moviedb_trash_drive_files([$scene2], $indexPath)['results'];
check('first trash lands on the plain name',
    $results[0]['to'], $rootA . '/.moviedb_trash/Faux Manor - Scene_2.mp4');
mkfile($scene2, 'scene two, take two'); // same name reappears at the source
moviedb_build_drive_index([$rootA, $missingRoot], $indexPath);
$results = moviedb_trash_drive_files([$scene2], $indexPath)['results'];
check('collision still trashed', $results[0]['trashed'], true);
check('collision target gets the -YYYYmmdd-His suffix',
    (bool) preg_match('#/\.moviedb_trash/Faux Manor - Scene_2-\d{8}-\d{6}\.mp4$#', $results[0]['to']),
    true);
check('collision target exists', is_file($results[0]['to']), true);
check('first trashed copy untouched',
    is_file($rootA . '/.moviedb_trash/Faux Manor - Scene_2.mp4'), true);

echo "trash refusals:\n";
$outside = $fx . '/outside.mp4';
mkfile($outside);
$uncatalogued = $rootA . '/Uncatalogued.mp4';
mkfile($uncatalogued); // created AFTER the last build, so absent from the index
$before = moviedb_load_drive_index($indexPath);
$results = moviedb_trash_drive_files([$outside, $uncatalogued, 123], $indexPath)['results'];
check('outside the roots: refused', $results[0]['trashed'], false);
check('outside the roots: error names the cause',
    strpos($results[0]['error'], 'outside') !== false, true);
check('outside the roots: file untouched', is_file($outside), true);
check('in root but not in index: refused', $results[1]['trashed'], false);
check('in root but not in index: error names the cause',
    strpos($results[1]['error'], 'not in the drive index') !== false, true);
check('in root but not in index: file untouched', is_file($uncatalogued), true);
check('non-string path: refused', $results[2]['trashed'], false);
check('non-string path: has an error', $results[2]['error'] !== '', true);
$afterRefusals = moviedb_load_drive_index($indexPath);
check('all-refused call leaves the index unchanged', $afterRefusals, $before);

echo "concurrent-claim race (rename must never replace an existing trash target):\n";
// Simulate worker A having claimed the plain target a moment earlier: with
// the old file_exists probe, worker B's rename would silently REPLACE it.
$scene3 = $rootA . '/Faux Manor - Scene_3.mp4';
mkfile($scene3, 'scene three');
moviedb_build_drive_index([$rootA, $missingRoot], $indexPath);
$claimedTarget = $rootA . '/.moviedb_trash/Faux Manor - Scene_3.mp4';
mkfile($claimedTarget, 'WORKER A DATA — must survive');
$results = moviedb_trash_drive_files([$scene3], $indexPath)['results'];
check('claimed name skipped, trash still succeeds', $results[0]['trashed'], true);
check('lands on a suffixed name, not the claimed one',
    $results[0]['to'] !== $claimedTarget
    && (bool) preg_match('#Scene_3-\d{8}-\d{6}\.mp4$#', $results[0]['to']),
    true);
check('worker A\'s file survives with its content',
    @file_get_contents($claimedTarget), 'WORKER A DATA — must survive');

echo "unreadable root aborts the rebuild (lost-FDA protection):\n";
$goodIndex = moviedb_load_drive_index($indexPath);
$lockedRoot = $fx . '/locked_root';
@mkdir($lockedRoot);
mkfile($lockedRoot . '/Inside Locked.mp4');
@chmod($lockedRoot, 0000);
$attempt = moviedb_build_drive_index([$rootA, $lockedRoot], $indexPath);
@chmod($lockedRoot, 0755);
check('build refuses with an error', isset($attempt['error']), true);
check('error names the unreadable root',
    strpos($attempt['error'] ?? '', 'locked_root') !== false, true);
check('previous index left untouched',
    moviedb_load_drive_index($indexPath), $goodIndex);

echo "nested roots deduped:\n";
$nestedSub = $rootA . '/nested';
@mkdir($nestedSub);
mkfile($nestedSub . '/Nested Feature.mp4');
$dupIndex = moviedb_build_drive_index([$rootA, $nestedSub, $rootA], $indexPath);
$nestedCount = 0;
foreach ($dupIndex['entries'] as $e) {
    if ($e['file'] === 'Nested Feature.mp4') {
        $nestedCount++;
    }
}
check('file under a nested root indexed exactly once', $nestedCount, 1);

echo "duplicates shelf excluded from the index:\n";
@mkdir($rootA . '/duplicates');
mkfile($rootA . '/duplicates/Shelved Copy - Scene_1.mp4');
$withShelf = moviedb_build_drive_index([$rootA], $indexPath);
check('shelved duplicate not indexed',
    in_array('Shelved Copy - Scene_1.mp4', array_column($withShelf['entries'], 'file'), true), false);

echo "merge rebuild preserves entries of unmounted roots:\n";
$rootB = $fx . '/root_b';
@mkdir($rootB);
mkfile($rootB . '/Offline Feature - Scene_1.mp4');
$withB = moviedb_build_drive_index([$rootA, $rootB], $indexPath);
$countWithB = $withB['fileCount'];
check('root B file indexed while mounted',
    in_array('Offline Feature - Scene_1.mp4', array_column($withB['entries'], 'file'), true), true);
// "Unmount" root B (rename it away) and rebuild: its entries must carry over
rename($rootB, $rootB . '.away');
$rebuilt = moviedb_build_drive_index([$rootA, $rootB], $indexPath);
check('missing root reported', $rebuilt['missingRoots'], [$rootB]);
check('offline entries carried forward',
    in_array('Offline Feature - Scene_1.mp4', array_column($rebuilt['entries'], 'file'), true), true);
check('file count includes carried entries', $rebuilt['fileCount'], $countWithB);
check('carried entries stay sorted by base',
    $rebuilt['entries'] === (function ($e) {
        usort($e, fn($a, $b) => strnatcasecmp($a['base'], $b['base']) ?: strnatcasecmp($a['file'], $b['file']));
        return $e;
    })($rebuilt['entries']), true);
// Root removed from CONFIG entirely -> its entries genuinely drop
$dropped = moviedb_build_drive_index([$rootA], $indexPath);
check('unconfigured root\'s entries are dropped',
    in_array('Offline Feature - Scene_1.mp4', array_column($dropped['entries'], 'file'), true), false);
rename($rootB . '.away', $rootB);

echo "rebuild progress reporting:\n";
$progressCalls = [];
moviedb_drive_index_scan([$rootA], function (array $p) use (&$progressCalls): void {
    $progressCalls[] = $p;
});
check('scan reports progress at least at root boundaries', count($progressCalls) >= 2, true);
check('progress carries the root being scanned', $progressCalls[0]['root'], $rootA);
check('progress roots total', $progressCalls[0]['rootsTotal'], 1);
check('final progress marks the root done', end($progressCalls)['rootsDone'], 1);
check('final progress entry count matches the scan',
    end($progressCalls)['entries'] > 0, true);

$progressPath = $indexPath . '.progress';
check('no sidecar while idle', moviedb_drive_index_progress($indexPath), ['active' => false]);
file_put_contents($progressPath, json_encode([
    'root' => $rootA, 'rootsDone' => 1, 'rootsTotal' => 5, 'entries' => 1234, 'at' => time(),
]));
$live = moviedb_drive_index_progress($indexPath);
check('fresh sidecar reads active', $live['active'], true);
check('fresh sidecar carries counts', [$live['rootsDone'], $live['rootsTotal'], $live['entries']], [1, 5, 1234]);
file_put_contents($progressPath, json_encode([
    'root' => $rootA, 'rootsDone' => 1, 'rootsTotal' => 5, 'entries' => 1234, 'at' => time() - 500,
]));
check('stale sidecar (crashed build) reads inactive',
    moviedb_drive_index_progress($indexPath), ['active' => false]);
moviedb_build_drive_index([$rootA], $indexPath);
check('build removes the sidecar when done', file_exists($progressPath), false);

echo "drive-root validation:\n";
putenv('ALLOWED_BASE_PATH=/Volumes');
check('the base itself is rejected',
    moviedb_validate_drive_root('/Volumes') !== null, true);
check('a directory inside the base passes',
    moviedb_validate_drive_root('/Volumes/Fixture Volume/recorded'), null);
check('control characters rejected',
    moviedb_validate_drive_root("/Volumes/bad\nroot") !== null, true);
if (is_dir('/Volumes/Macintosh HD') && realpath('/Volumes/Macintosh HD') === '/') {
    check('root resolving to / rejected (Macintosh HD self-reference)',
        moviedb_validate_drive_root('/Volumes/Macintosh HD') !== null, true);
}
putenv('ALLOWED_BASE_PATH');

echo "reveal validation (dry run, no exec):\n";
$inIndex = $rootA . '/Faux Manor - Scene_1 - Jane Fauxheart.mp4';
check('in-index path passes validation',
    moviedb_reveal_drive_file($inIndex, $indexPath, true), ['success' => true]);
// Created after the last build above, so genuinely absent from the index
$uncatalogued = $rootA . '/Uncatalogued Two.mp4';
mkfile($uncatalogued);
$refused = moviedb_reveal_drive_file($uncatalogued, $indexPath, true);
check('out-of-index path is refused', $refused['success'], false);
check('refusal carries an error', isset($refused['error']), true);
$refusedOutside = moviedb_reveal_drive_file($outside, $indexPath, true);
check('outside-roots path is refused', $refusedOutside['success'], false);

rrmdir($fx);
check('fixture tree cleaned up', is_dir($fx), false);

echo "\n$checks checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
