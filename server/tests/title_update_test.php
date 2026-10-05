<?php

// CLI only: the whole repo sits under httpd's DocumentRoot, so without this
// guard a bare GET to this file would execute it via mod_php.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

/**
 * Harness for server/title_update_lib.php — the planning half of "Update
 * Database Titles" (title mapping, table choice, pairing, leftovers,
 * classification, merge values). Run: php server/tests/title_update_test.php
 * Fixture-only: a temp directory and an in-memory index; no DB, no /Volumes.
 * (The guarded writes were exercised against a scratch database.)
 */

require_once __DIR__ . '/../title_update_lib.php';

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

// --- catalogue title of a file base name ---
check('plain title', moviedb_db_title_for_base('Sample Movie # 02'), 'Sample Movie # 02');
check('scene + cast dropped', moviedb_db_title_for_base('Sample Movie # 02 - Scene_3 - Jane Doe'), 'Sample Movie # 02');
check('volume cast tail dropped', moviedb_db_title_for_base('Sample Movie # 02 - Jane Doe'), 'Sample Movie # 02');
check('CD part dropped', moviedb_db_title_for_base('Sample Movie - CD2'), 'Sample Movie');
check('unnumbered subtitle kept', moviedb_db_title_for_base('Sample Movie - The Sequel'), 'Sample Movie - The Sequel');

// --- table per folder ---
check('Bi folder', moviedb_title_update_table_for_dir('/Volumes/Etc/Bi/recorded', 'movies_het'), 'movies_bi');
check('Bi root', moviedb_title_update_table_for_dir('/Volumes/Etc/Bi/', 'movies_het'), 'movies_bi');
check('lookalike prefix', moviedb_title_update_table_for_dir('/Volumes/Etc/Bimonthly', 'movies_het'), 'movies_het');
check('library folder', moviedb_title_update_table_for_dir('/Volumes/Recorded 1/recorded', 'movies_het'), 'movies_het');
check('Bi folder, other case (case-insensitive volume)', moviedb_title_update_table_for_dir('/Volumes/Etc/bi/recorded', 'movies_het'), 'movies_bi');
check('in_dirs ignores case and trailing slash', moviedb_title_update_in_dirs('/Volumes/x/Recorded/', ['/Volumes/X/recorded']), true);
check('in_dirs: other folder', moviedb_title_update_in_dirs('/Volumes/X/recorded2', ['/Volumes/X/recorded']), false);
check('allowed tables', moviedb_title_update_tables('movies_het'), ['movies_het', 'movies_bi']);

// --- pairing ---
$d1 = '/Volumes/Recorded 1/recorded';
$d2 = '/Volumes/Recorded 2/recorded';
$pairs = moviedb_title_update_pairs([
    ['path' => $d1, 'originalFileName' => 'Movie And Friends - Scene_1.mp4', 'newFileName' => 'Movie and Friends - Scene_1.mp4'],
    ['path' => $d1, 'originalFileName' => 'Movie And Friends - Scene_2.mp4', 'newFileName' => 'Movie and Friends - Scene_2.mp4'],
    ['path' => $d2, 'originalFileName' => 'Movie And Friends - Scene_3.mp4', 'newFileName' => 'Movie and Friends - Scene_3.mp4'],
    ['path' => $d1, 'originalFileName' => 'Cast Only - Scene_1.mp4', 'newFileName' => 'Cast Only - Scene_1 - Jane Doe.mp4'],
    ['path' => '/Volumes/Etc/Bi/recorded', 'originalFileName' => 'Bi Title Of Note.mp4', 'newFileName' => 'Bi Title of Note.mp4'],
], 'movies_het');
check('cast-only rename drops out; scenes collapse', count($pairs), 2);
check('scenes in two folders keep both', $pairs[0]['dirs'], [$d1, $d2]);
check('pair titles', [$pairs[0]['oldTitle'], $pairs[0]['newTitle']], ['Movie And Friends', 'Movie and Friends']);
check('pair files', count($pairs[0]['files']), 3);
check('Bi pair table', $pairs[1]['table'], 'movies_bi');
check('no conflict', $pairs[0]['conflict'], false);

$conflict = moviedb_title_update_pairs([
    ['path' => $d1, 'originalFileName' => 'Two Way.mp4', 'newFileName' => 'Two-Way.mp4'],
    ['path' => $d2, 'originalFileName' => 'Two Way - Scene_1.mp4', 'newFileName' => 'Twoway - Scene_1.mp4'],
], 'movies_het');
check('conflict flagged on both', [$conflict[0]['conflict'], $conflict[1]['conflict']], [true, true]);

// --- subtitled titles ("# NN - Subtitle"): the database picks the form ---
check('file title, short form drops the tail', moviedb_title_update_file_title('Sample Trip # 02 - Back Home'), 'Sample Trip # 02');
check('file title, full form keeps it', moviedb_title_update_file_title('Sample Trip # 02 - Back Home', 'full'), 'Sample Trip # 02 - Back Home');
check('file title, full form still drops scenes', moviedb_title_update_file_title('Sample Trip # 04 - Scene_2', 'full'), 'Sample Trip # 04');
$sub = moviedb_title_update_pairs([
    ['path' => $d1, 'originalFileName' => 'Sample trip # 02 - Back Home.mp4', 'newFileName' => 'Sample Trip # 02 - Back Home.mp4'],
    ['path' => $d1, 'originalFileName' => 'Sample Trip # 03 - the Return.mp4', 'newFileName' => 'Sample Trip # 03 - The Return.mp4'],
    ['path' => $d1, 'originalFileName' => 'Sample Trip # 05 - Jane Doe.mp4', 'newFileName' => 'Sample Trip # 05 - Jane Doe, Mary Roe.mp4'],
], 'movies_het');
check('a tail-only change stays a pair (it may be a subtitle)', count($sub), 3);
check('pairs carry both forms', [$sub[0]['oldTitle'], $sub[0]['oldFull'], $sub[0]['newFull']],
    ['Sample trip # 02', 'Sample trip # 02 - Back Home', 'Sample Trip # 02 - Back Home']);
$rowsBy = fn(array $byTitle) => function (string $old, string $new) use ($byTitle) {
    $out = [];
    foreach ($byTitle as $t => $r) {
        if (strcasecmp($t, $old) === 0 || strcasecmp($t, $new) === 0) {
            $out[] = $r;
        }
    }
    return $out;
};
$subRow = ['id' => '7', 'title' => 'Sample trip # 02 - Back Home', 'dimensions' => '', 'filesize' => '1', 'duration' => '1', 'date_created' => '2022-08-15'];
$r = moviedb_title_update_resolve($sub[0], $rowsBy(['Sample trip # 02 - Back Home' => $subRow]));
check('a row with the subtitle is found by the full form',
    [$r['form'], $r['oldTitle'], $r['newTitle'], count($r['rows'])],
    ['full', 'Sample trip # 02 - Back Home', 'Sample Trip # 02 - Back Home', 1]);
check('... and classifies as an update of that row', moviedb_title_update_classify($r, $r['rows'], [])['status'], 'update');
$shortRow = ['title' => 'Sample trip # 02'] + $subRow;
$r = moviedb_title_update_resolve($sub[0], $rowsBy(['Sample trip # 02' => $shortRow]));
check('no subtitled row: the short form, as before', [$r['form'], $r['oldTitle'], $r['newTitle']], ['short', 'Sample trip # 02', 'Sample Trip # 02']);
$r = moviedb_title_update_resolve($sub[1], $rowsBy(['Sample Trip # 03 - the Return' => ['title' => 'Sample Trip # 03 - the Return'] + $subRow]));
check('a subtitle-only change updates the subtitled row', [$r['form'], $r['newTitle']], ['full', 'Sample Trip # 03 - The Return']);
check('a cast-only change on a volume drops out', moviedb_title_update_resolve($sub[2], $rowsBy([])), null);
check('a cast-only change drops out even when the short title has a row',
    moviedb_title_update_resolve($sub[2], $rowsBy(['Sample Trip # 05' => ['title' => 'Sample Trip # 05'] + $subRow])), null);

// --- leftovers (live folder + index elsewhere) ---
$tmp = rtrim(sys_get_temp_dir(), '/') . '/moviedb-title-update-test-' . getmypid();
@mkdir($tmp, 0777, true);
foreach (['Movie and Friends - Scene_1.mp4', 'Movie And Friends - Scene_2.mp4', 'Movie And Friends - notes.txt', 'Other.mp4'] as $f) {
    touch("$tmp/$f");
}
$index = ['entries' => [
    ['base' => 'Movie And Friends - Scene_9', 'file' => 'Movie And Friends - Scene_9.mp4', 'dir' => '/Volumes/X/recorded', 'size' => 1],
    ['base' => 'Movie And Friends - Scene_1', 'file' => 'Movie And Friends - Scene_1.mp4', 'dir' => $tmp, 'size' => 1], // stale: read live instead
]];
check('leftovers, case-only change: live folder + index elsewhere, videos only, exact old spelling',
    moviedb_title_update_leftovers([$tmp], 'Movie And Friends', $index, 'Movie and Friends'),
    ["$tmp/Movie And Friends - Scene_2.mp4", '/Volumes/X/recorded/Movie And Friends - Scene_9.mp4']);
check('leftovers, spelling change: any capitalization of the old title',
    moviedb_title_update_leftovers([$tmp], 'Movie And Friends', $index, 'Movie & Friends'),
    ["$tmp/Movie And Friends - Scene_2.mp4", "$tmp/Movie and Friends - Scene_1.mp4", '/Volumes/X/recorded/Movie And Friends - Scene_9.mp4']);
$biIndex = ['entries' => [
    ['base' => 'Movie And Friends', 'file' => 'Movie And Friends.mp4', 'dir' => '/Volumes/Recorded 2/recorded', 'size' => 1],
    ['base' => 'Movie And Friends', 'file' => 'Movie And Friends.mp4', 'dir' => '/Volumes/Etc/Bi/misc', 'size' => 1],
]];
check('leftovers only from the same catalog',
    moviedb_title_update_leftovers([], 'Movie And Friends', $biIndex, 'Movie & Friends', 'movies_bi'),
    ['/Volumes/Etc/Bi/misc/Movie And Friends.mp4']);
check('elsewhere only from the same catalog',
    moviedb_title_update_files_elsewhere([], ['movie and friends'], $biIndex, 'movies_het'),
    ['/Volumes/Recorded 2/recorded/Movie And Friends.mp4']);
check('files elsewhere match either title, any case',
    moviedb_title_update_files_elsewhere([$tmp], ['movie and friends'], $index),
    ['/Volumes/X/recorded/Movie And Friends - Scene_9.mp4']);
// A folder of its own: listings are cached per request, and $tmp was listed
// above. One file — the temp volume is case-insensitive, like the drives.
$tmpSub = "$tmp-sub";
@mkdir($tmpSub, 0777, true);
touch("$tmpSub/Sample trip # 02 - Back Home.mp4");
check('leftovers in the full form: a file still on the old subtitled spelling',
    moviedb_title_update_leftovers([$tmpSub], 'Sample trip # 02 - Back Home', null, 'Sample Trip # 02 - Back Home', 'movies_het', 'full'),
    ["$tmpSub/Sample trip # 02 - Back Home.mp4"]);
check('the short form never sees a subtitled leftover as the full title',
    moviedb_title_update_leftovers([$tmpSub], 'Sample trip # 02 - Back Home', null, 'Sample Trip # 02 - Back Home'),
    []);
unlink("$tmpSub/Sample trip # 02 - Back Home.mp4");
rmdir($tmpSub);
check('elsewhere in the full form',
    moviedb_title_update_files_elsewhere([], ['sample trip # 02 - back home'],
        ['entries' => [['base' => 'Sample Trip # 02 - Back Home', 'file' => 'Sample Trip # 02 - Back Home.mp4', 'dir' => '/Volumes/X/recorded', 'size' => 1]]],
        'movies_het', 'full'),
    ['/Volumes/X/recorded/Sample Trip # 02 - Back Home.mp4']);
array_map('unlink', glob("$tmp/*"));
rmdir($tmp);

// --- classification ---
$pair = ['table' => 'movies_het', 'oldTitle' => 'Sample Title One', 'newTitle' => 'Sample Title 1', 'files' => [], 'conflict' => false];
$row = fn(int $id, string $t, ?string $date = '2020-01-01') => ['id' => (string) $id, 'title' => $t, 'dimensions' => '1920 x 1080', 'filesize' => '100', 'duration' => '60', 'date_created' => $date];
check('missing', moviedb_title_update_classify($pair, [], [])['status'], 'missing');
check('update', moviedb_title_update_classify($pair, [$row(5, 'Sample Title One')], [])['status'], 'update');
check('update row', moviedb_title_update_classify($pair, [$row(5, 'Sample Title One')], [])['row']['id'], '5');
check('current', moviedb_title_update_classify($pair, [$row(5, 'Sample Title 1')], [])['status'], 'current');
check('partial', moviedb_title_update_classify($pair, [$row(5, 'Sample Title One')], ['/x/Sample Title One.mp4'])['status'], 'partial');
check('conflict', moviedb_title_update_classify(['conflict' => true] + $pair, [$row(5, 'Sample Title One')], [])['status'], 'conflict');
check('ambiguous', moviedb_title_update_classify($pair, [$row(1, 'a'), $row(2, 'b'), $row(3, 'c')], [])['status'], 'ambiguous');
$m = moviedb_title_update_classify($pair, [$row(9, 'Sample Title One', '2019-05-01'), $row(4, 'Sample Title 1', '2021-01-01')], []);
check('merge keeps the earliest-catalogued row', [$m['status'], $m['keep']['id'], $m['drop']['id']], ['merge', '9', '4']);
$m2 = moviedb_title_update_classify($pair, [$row(9, 'Sample Title One', '2019-05-01'), $row(4, 'Sample Title 1', '2019-05-01')], []);
check('same date: lower id kept', $m2['keep']['id'], '4');
$m3 = moviedb_title_update_classify($pair, [$row(2, 'Sample Title One', null), $row(8, 'Sample Title 1', '2022-01-01')], []);
check('undated row sorts last', $m3['keep']['id'], '8');
check('merge reason: the new title has a row', $m['reason'], 'The new title already has a row of its own.');
$m4 = moviedb_title_update_classify($pair, [$row(2, 'Sample Title One'), $row(8, 'sample title one')], []);
check('merge reason: two rows of the old title', [$m4['status'], $m4['reason']], ['merge', 'The title has two rows.']);
$casePair = ['oldTitle' => 'Sample And Title', 'newTitle' => 'Sample and Title'] + $pair;
$c = moviedb_title_update_classify($casePair, [$row(5, 'Sample And Title')], ['/x/Sample And Title.mp4']);
check('case-only change with leftovers still updates, with a note', [$c['status'], $c['note'] !== null], ['update', true]);

// --- merge values ---
$keep = ['filesize' => '100', 'dimensions' => '', 'duration' => null];
$drop = ['filesize' => '999', 'dimensions' => '640 x 480', 'duration' => '3600'];
check('from rows: blanks filled from the deleted row',
    moviedb_title_update_merge_values($keep, $drop, null),
    ['source' => 'rows', 'filesize' => '100', 'dimensions' => '640 x 480', 'duration' => '3600']);
check('from files',
    moviedb_title_update_merge_values($keep, $drop, ['filesize' => 5000, 'dimensions' => '1920 x 1080', 'duration' => 7200]),
    ['source' => 'files', 'filesize' => '5000', 'dimensions' => '1920 x 1080', 'duration' => 7200]);
check('files without a probe fall back per column',
    moviedb_title_update_merge_values($keep, $drop, ['filesize' => 5000, 'dimensions' => null, 'duration' => null]),
    ['source' => 'files', 'filesize' => '5000', 'dimensions' => '640 x 480', 'duration' => '3600']);

// --- snapshot comparison ---
check('same row', moviedb_title_update_same_row($row(5, 'x'), $row(5, 'x')), true);
check('duration within a millisecond', moviedb_title_update_same_row(['duration' => '60.0004'] + $row(5, 'x'), $row(5, 'x')), true);
check('changed title', moviedb_title_update_same_row($row(5, 'y'), $row(5, 'x')), false);
check('gone row', moviedb_title_update_same_row(null, $row(5, 'x')), false);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
