<?php

// CLI only: the whole repo sits under httpd's DocumentRoot, so without this
// guard a bare GET to this file would execute it via mod_php.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

/**
 * Harness for server/cast_respell_lib.php — respelling a cast name across
 * indexed filenames. Run: php server/tests/cast_respell_test.php
 * Fixture-only: invented names, files in a temp directory, a temp index and
 * log. Never touches /Volumes.
 */

require_once __DIR__ . '/../cast_respell_lib.php';

$pass = 0;
$fail = 0;

function check(string $label, $actual, $expected): void
{
    global $pass, $fail;
    if ($actual === $expected) {
        $pass++;
    } else {
        $fail++;
        echo "FAIL {$label}\n  expected: " . var_export($expected, true)
            . "\n  actual:   " . var_export($actual, true) . "\n";
    }
}

$r = fn(string $file, array $from = ['Orlena Rain'], string $to = 'Orlena Rains')
    => moviedb_cast_respell_filename($file, $from, $to);

// --- one filename -------------------------------------------------------------
check('dash form', $r('Sample Movie # 02 - Scene_1 - Orlena Rain.mp4'), 'Sample Movie # 02 - Scene_1 - Orlena Rains.mp4');
check('space form', $r('Sample Movie # 02 - Scene_1 Orlena Rain.mp4'), 'Sample Movie # 02 - Scene_1 Orlena Rains.mp4');
check('among others, separators kept', $r('Sample # 01 - Scene_2 - Juna Quist, Orlena Rain & Marla Vex.mp4'),
    'Sample # 01 - Scene_2 - Juna Quist, Orlena Rains & Marla Vex.mp4');
check('"and" separator', $r('Sample # 01 - Scene_2 - Juna Quist and Orlena Rain.mp4'),
    'Sample # 01 - Scene_2 - Juna Quist and Orlena Rains.mp4');
check('case-insensitive match', $r('Sample # 01 - Scene_1 - orlena rain.mp4'), 'Sample # 01 - Scene_1 - Orlena Rains.mp4');
check('"With " prefix kept', $r('Sample # 01 - Scene_1 - With Orlena Rain.mp4'), 'Sample # 01 - Scene_1 - With Orlena Rains.mp4');
check('title untouched', $r('Orlena Rain Adventures # 01 - Scene_1 - Orlena Rain.mp4'),
    'Orlena Rain Adventures # 01 - Scene_1 - Orlena Rains.mp4');
check('longer name is a different name', $r('Sample # 01 - Scene_1 - Orlena Rainey.mp4'), null);
check('right spelling already: no change', $r('Sample # 01 - Scene_1 - Orlena Rains.mp4'), null);
check('no Scene_N: not a cast tail', $r('Sample # 03 - Orlena Rain.mp4'), null);
check('no extension', $r('Sample # 01 - Scene_1 - Orlena Rain'), 'Sample # 01 - Scene_1 - Orlena Rains');
check('several spellings at once', $r('Sample # 01 - Scene_1 - Orlenna Rains, Orlena Rain.mp4', ['Orlena Rain', 'Orlenna Rains']),
    'Sample # 01 - Scene_1 - Orlena Rains, Orlena Rains.mp4');
check('casing fix', $r('Sample # 01 - Scene_1 - orlena rains.mp4', ['orlena rains'], 'Orlena Rains'),
    'Sample # 01 - Scene_1 - Orlena Rains.mp4');
// "Оrlena" with U+041E CYRILLIC CAPITAL O cleans to the Latin spelling
check('lookalike letters replaced whole', $r("Sample # 01 - Scene_1 - Juna Quist, \u{041E}rlena Rain.mp4"),
    'Sample # 01 - Scene_1 - Juna Quist, Orlena Rains.mp4');

// --- plan ---------------------------------------------------------------------
$plan = moviedb_cast_respell_plan([
    ['dir' => '/X', 'file' => 'A # 01 - Scene_1 - Orlena Rain.mp4'],
    ['dir' => '/X', 'file' => 'A # 01 - Scene_1 - Orlena Rains.mp4'],   // the target exists
    ['dir' => '/X', 'file' => 'A # 01 - Scene_2 - Orlena Rain.mp4'],
    ['dir' => '/X', 'file' => 'B # 01 - Scene_1 - Marla Vex.mp4'],
], ['Orlena Rain'], 'Orlena Rains');
check('plan: only matching files', array_column($plan, 'file'),
    ['A # 01 - Scene_1 - Orlena Rain.mp4', 'A # 01 - Scene_2 - Orlena Rain.mp4']);
check('plan: conflict flagged', array_column($plan, 'conflict'), [true, false]);
check('plan: path', $plan[1]['path'], '/X/A # 01 - Scene_2 - Orlena Rain.mp4');

// --- apply, against real files in a temp folder ---------------------------------
$root = sys_get_temp_dir() . '/cast_respell_' . getmypid();
@mkdir($root);
$dir = realpath($root);
$files = [
    'A # 01 - Scene_1 - Orlena Rain.mp4' => 'one',
    'A # 01 - Scene_2 - Juna Quist, orlena rain.mp4' => 'two',
    'A # 01 - Scene_3 - Orlena Rain.mp4' => 'three',        // not previewed
    'B # 01 - Scene_1 - Orlena Rain.mp4' => 'four',
    'B # 01 - Scene_1 - Orlena Rains.mp4' => 'occupant',    // blocks the one above
    'C # 01 - Scene_1 - Marla Vex.mp4' => 'five',
];
$entries = [];
foreach ($files as $name => $body) {
    file_put_contents("{$dir}/{$name}", $body);
    $entries[] = ['base' => stripTitleVariantSuffixes(pathinfo($name, PATHINFO_FILENAME)), 'file' => $name, 'dir' => $dir, 'size' => 1, 'mtime' => 1];
}
$indexPath = "{$dir}/index.json";
$logPath = "{$dir}/respell.jsonl";
file_put_contents($indexPath, json_encode(['roots' => [$dir], 'entries' => $entries]));

$approved = [
    "{$dir}/A # 01 - Scene_1 - Orlena Rain.mp4",
    "{$dir}/A # 01 - Scene_2 - Juna Quist, orlena rain.mp4",
    "{$dir}/B # 01 - Scene_1 - Orlena Rain.mp4",
    "{$dir}/C # 01 - Scene_1 - Marla Vex.mp4",   // approved but carries no spelling: ignored
];
$out = moviedb_cast_respell_apply(['Orlena Rain'], 'Orlena Rains', $approved, $indexPath, $logPath);

check('apply: counts', [$out['renamed'], $out['failed'], $out['notPreviewed'], $out['indexUpdated']], [2, 1, 1, true]);
check('apply: renamed on disk', file_get_contents("{$dir}/A # 01 - Scene_1 - Orlena Rains.mp4"), 'one');
check('apply: old name gone', file_exists("{$dir}/A # 01 - Scene_1 - Orlena Rain.mp4"), false);
check('apply: second cast member kept', file_get_contents("{$dir}/A # 01 - Scene_2 - Juna Quist, Orlena Rains.mp4"), 'two');
check('apply: not previewed, left alone', file_get_contents("{$dir}/A # 01 - Scene_3 - Orlena Rain.mp4"), 'three');
check('apply: never overwrites', file_get_contents("{$dir}/B # 01 - Scene_1 - Orlena Rains.mp4"), 'occupant');
check('apply: blocked file kept', file_get_contents("{$dir}/B # 01 - Scene_1 - Orlena Rain.mp4"), 'four');
check('apply: blocked reason', array_values(array_filter($out['results'], fn($x) => !$x['renamed']))[0]['error'],
    'A file with the new name already exists');
check('apply: unrelated file untouched', file_get_contents("{$dir}/C # 01 - Scene_1 - Marla Vex.mp4"), 'five');

$index = json_decode(file_get_contents($indexPath), true);
$indexed = array_column($index['entries'], 'file');
check('index: renamed entries updated', in_array('A # 01 - Scene_1 - Orlena Rains.mp4', $indexed, true)
    && in_array('A # 01 - Scene_2 - Juna Quist, Orlena Rains.mp4', $indexed, true), true);
check('index: old names gone', in_array('A # 01 - Scene_1 - Orlena Rain.mp4', $indexed, true), false);
check('index: same entry count', count($indexed), count($files));
check('index: base kept', $index['entries'][0]['base'], 'A # 01');

$log = array_map(fn($l) => json_decode($l, true), file($logPath, FILE_IGNORE_NEW_LINES));
check('log: one line per rename', count($log), 2);
check('log: paths', [$log[0]['from'], $log[0]['to']],
    ["{$dir}/A # 01 - Scene_1 - Orlena Rain.mp4", "{$dir}/A # 01 - Scene_1 - Orlena Rains.mp4"]);

// A casing fix renames through a temp name on a case-insensitive volume
file_put_contents("{$dir}/D # 01 - Scene_1 - juna quist.mp4", 'six');
$index['entries'][] = ['base' => 'D # 01', 'file' => 'D # 01 - Scene_1 - juna quist.mp4', 'dir' => $dir, 'size' => 1, 'mtime' => 1];
file_put_contents($indexPath, json_encode($index));
$out = moviedb_cast_respell_apply(['juna quist'], 'Juna Quist', ["{$dir}/D # 01 - Scene_1 - juna quist.mp4"], $indexPath, $logPath);
check('case-only: renamed', $out['renamed'], 1);
check('case-only: new casing on disk', in_array('D # 01 - Scene_1 - Juna Quist.mp4', scandir($dir), true), true);
check('case-only: no temp left', count(array_filter(scandir($dir), fn($f) => str_contains($f, '__tmp__'))), 0);

// A path that isn't in the index is refused, even if approved
$out = moviedb_cast_respell_apply(['Orlena Rain'], 'Orlena Rains', ['/etc/passwd'], $indexPath, $logPath);
check('unindexed path: nothing renamed', [$out['renamed'], $out['failed']], [0, 0]);
check('unindexed path: the real ones counted as not previewed', $out['notPreviewed'], 2);

// An entry outside the index's roots is refused before any rename
@mkdir("{$dir}/elsewhere");
file_put_contents("{$dir}/elsewhere/E # 01 - Scene_1 - Orlena Rain.mp4", 'seven');
$narrow = ['roots' => ["{$dir}/indexed-only"], 'entries' => [
    ['base' => 'E # 01', 'file' => 'E # 01 - Scene_1 - Orlena Rain.mp4', 'dir' => "{$dir}/elsewhere", 'size' => 1, 'mtime' => 1],
]];
@mkdir("{$dir}/indexed-only");
file_put_contents("{$dir}/narrow.json", json_encode($narrow));
$out = moviedb_cast_respell_apply(['Orlena Rain'], 'Orlena Rains',
    ["{$dir}/elsewhere/E # 01 - Scene_1 - Orlena Rain.mp4"], "{$dir}/narrow.json", $logPath);
check('outside roots: refused', [$out['renamed'], $out['failed'], $out['results'][0]['error'] ?? ''],
    [0, 1, 'Path is outside the indexed roots']);
check('outside roots: file untouched', file_get_contents("{$dir}/elsewhere/E # 01 - Scene_1 - Orlena Rain.mp4"), 'seven');

// A rename that landed before an interrupted run saved the index is
// finished on the next run: counted done, entry fixed, nothing re-logged
file_put_contents("{$dir}/F # 01 - Scene_1 - Orlena Rains.mp4", 'eight');   // renamed already
$stale = json_decode(file_get_contents($indexPath), true);
$stale['entries'][] = ['base' => 'F # 01', 'file' => 'F # 01 - Scene_1 - Orlena Rain.mp4', 'dir' => $dir, 'size' => 1, 'mtime' => 1];
file_put_contents($indexPath, json_encode($stale));
$logLines = count(file($logPath));
$out = moviedb_cast_respell_apply(['Orlena Rain'], 'Orlena Rains', ["{$dir}/F # 01 - Scene_1 - Orlena Rain.mp4"], $indexPath, $logPath);
check('interrupted: counted renamed', [$out['renamed'], $out['failed']], [1, 0]);
check('interrupted: marked already', $out['results'][0]['already'] ?? false, true);
$healed = array_column(json_decode(file_get_contents($indexPath), true)['entries'], 'file');
check('interrupted: index entry fixed', [in_array('F # 01 - Scene_1 - Orlena Rains.mp4', $healed, true),
    in_array('F # 01 - Scene_1 - Orlena Rain.mp4', $healed, true)], [true, false]);
check('interrupted: not logged twice', count(file($logPath)), $logLines);
// But a missing file with no renamed copy is still a failure
$stale = json_decode(file_get_contents($indexPath), true);
$stale['entries'][] = ['base' => 'G # 01', 'file' => 'G # 01 - Scene_1 - Orlena Rain.mp4', 'dir' => $dir, 'size' => 1, 'mtime' => 1];
file_put_contents($indexPath, json_encode($stale));
$out = moviedb_cast_respell_apply(['Orlena Rain'], 'Orlena Rains', ["{$dir}/G # 01 - Scene_1 - Orlena Rain.mp4"], $indexPath, $logPath);
check('vanished: a failure', [$out['renamed'], $out['failed'], $out['results'][0]['error'] ?? ''], [0, 1, 'File not found']);

// No index at all
$out = moviedb_cast_respell_apply(['Orlena Rain'], 'Orlena Rains', [], "{$dir}/missing.json", $logPath);
check('no index: error', $out['error'] ?? '', 'No drive index has been built yet');

// --- rename guard ---------------------------------------------------------------
check('rename: slash refused', moviedb_cast_respell_rename("{$dir}/C # 01 - Scene_1 - Marla Vex.mp4", '../x.mp4'), 'Invalid file name');
check('rename: too long refused', str_starts_with(
    moviedb_cast_respell_rename("{$dir}/C # 01 - Scene_1 - Marla Vex.mp4", str_repeat('a', 256) . '.mp4'), 'File name too long'), true);

foreach (new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
) as $f) {
    $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
}
rmdir($dir);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
