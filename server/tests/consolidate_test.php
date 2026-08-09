<?php

// CLI only: the whole repo sits under httpd's DocumentRoot, so without this
// guard a bare GET to this file would execute it via mod_php.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}


/**
 * Verification harness for the consolidation feature:
 * scripts/consolidate_movies.php (run end-to-end via exec against fixture
 * "drives" under the system temp dir) plus server/consolidate_lib.php
 * (settings merge/validation, tail, pid-alive, and the hardened destination-
 * collision logic red-proofed directly on fixture files).
 *
 * Fixtures use FICTIONAL titles in a throwaway tree under the system temp
 * dir. The harness NEVER touches /Volumes and never runs the script against
 * real paths.
 *
 * End-to-end collision proof note: within a single run, a regular file at a
 * destination path is scanned into the same dedupe bucket as its source
 * (same basename => same parse => same group/item) and is shelved before the
 * move loop — so the collision branch only fires for dest entries the scan
 * can't see (non-regular files, or files appearing mid-run: the interrupted-
 * mv partial case). The suspect-dest path is therefore proven end-to-end with
 * a FIFO at the destination (is_file() false => skipped by the scan;
 * file_exists() true => collision branch fires), and both collision rules are
 * red-proofed directly against moviedb_consolidate_move_into_place() with
 * regular files.
 *
 *   Run:  php server/tests/consolidate_test.php
 *   Exit: 0 = all checks passed, 1 = at least one failed.
 */

require_once __DIR__ . '/../consolidate_lib.php';

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

/** Recursive dir snapshot: relpath => content hash + size + mtime (no FIFOs). */
function snapshotTree(string $dir): array
{
    $out = [];
    if (!is_dir($dir)) {
        return $out;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $fi) {
        $rel = substr($fi->getPathname(), strlen($dir) + 1);
        $out[$rel] = $fi->isDir()
            ? 'DIR'
            : md5_file($fi->getPathname()) . ':' . $fi->getSize() . ':' . $fi->getMTime();
    }
    ksort($out);
    return $out;
}

function runScript(array $args, ?string $cwd = null): array
{
    global $script;
    $cmd = $cwd !== null ? 'cd ' . escapeshellarg($cwd) . ' && ' : '';
    $cmd .= escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    return [$code, $out];
}

/**
 * Run the script while draining a FIFO progress file (and the child's own
 * stdout/stderr) concurrently — a plain exec would deadlock once the FIFO's
 * pipe buffer fills. Returns [exitCode, combinedOutput, rawFifoStream].
 */
function runScriptDrainingFifo(array $args, $fifoFh): array
{
    global $script;
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    $pipes = [];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $raw = '';
    $stdout = '';
    $code = -1;
    $deadline = microtime(true) + 60;
    while (true) {
        $raw .= (string) stream_get_contents($fifoFh);
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stdout .= (string) stream_get_contents($pipes[2]);
        $status = proc_get_status($proc);
        if (!$status['running']) {
            $code = $status['exitcode']; // only valid on the first non-running report
            break;
        }
        if (microtime(true) >= $deadline) {
            proc_terminate($proc, 9);
            break;
        }
        usleep(2000);
    }
    $raw .= (string) stream_get_contents($fifoFh);
    $stdout .= (string) stream_get_contents($pipes[1]);
    $stdout .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    return [$code, $stdout, $raw];
}

function cset(array $drives, string $balanceTo, array $over = []): array
{
    return $over + [
        'drives' => $drives, 'balanceTo' => $balanceTo,
        'targetFreeGb' => 0, 'reserveGb' => 0, 'balance' => false, 'recursive' => false,
    ];
}

function writeSettings(string $path, array $consolidate): void
{
    mkfile($path, json_encode(['consolidate' => $consolidate]));
}

function readLast(string $progress): ?array
{
    $raw = @file_get_contents($progress . '.last');
    $d = $raw === false ? null : json_decode($raw, true);
    return is_array($d) ? $d : null;
}

/** .last's indexRebuilt, distinguishing present-null from a missing key
 *  (?? would collapse both to the default). */
function indexRebuiltOf(string $progress)
{
    $last = readLast($progress);
    if (!is_array($last) || !array_key_exists('indexRebuilt', $last)) {
        return 'MISSING';
    }
    return $last['indexRebuilt'];
}

/** Count TSV rows whose action + status columns match. */
function tsvCount(string $tsvPath, string $action, string $status): int
{
    $n = 0;
    foreach (@file($tsvPath) ?: [] as $line) {
        $cols = explode("\t", rtrim($line, "\n"));
        if (($cols[3] ?? '') === $action && ($cols[7] ?? '') === $status) {
            $n++;
        }
    }
    return $n;
}

$script = realpath(__DIR__ . '/../../scripts/consolidate_movies.php');
check('script exists', is_string($script) && is_file($script), true);

$fx = rtrim(sys_get_temp_dir(), '/') . '/moviedb_consolidate_test_' . getmypid();
// Bare-CLI invocations must lock on a FIXTURE path, not the production lock —
// otherwise the harness fails whenever a real endpoint run is in flight
putenv('MOVIEDB_CONSOLIDATE_LOCK=' . $fx . '/bare_cli.lock');
rrmdir($fx); // stale tree from a crashed earlier run
mkdir($fx, 0777, true);

/** The S1/S2 fixture: split group, dup pair, unnumbered+numbered,
 *  parenthetical-tag group split across drives, recursive subdir source. */
function buildMainTree(string $d): array
{
    $A = "$d/A/recorded";
    $B = "$d/B/recorded";
    $C = "$d/C/recorded";
    mkdir($A, 0777, true);
    mkdir($B, 0777, true);
    mkdir($C, 0777, true);
    mkfile("$A/Copperfield Chronicles # 01.mp4", 'copper one');
    mkfile("$A/Copperfield Chronicles # 02.mp4", 'copper two!');
    mkfile("$B/sub/Copperfield Chronicles # 03.mp4", 'copper three!!');   // 14 bytes
    mkfile("$A/Marble Sky.mp4", 'MARBLE-SKY-PRIMARY-1');                  // 20 bytes, best copy
    mkfile("$B/Marble Sky.mp4", 'tiny.');                                 // 5 bytes, loser
    mkfile("$A/Foxglove Alley.mp4", 'fox base');                          // unnumbered, numbered exists
    mkfile("$A/Foxglove Alley # 01.mp4", 'fox ep one');
    mkfile("$A/Silverpine Saga # 01 (Kestrel) - Scene_1.mp4", 'kestrel scene one long'); // bigger => A wins tie
    mkfile("$B/Silverpine Saga # 02 (Kestrel).mp4", 'kest02');            // 6 bytes
    mkfile("$B/Silverpine Saga # 01.mp4", 'plain one');                   // separate (no-paren) group
    return [$A, $B, $C];
}

// ---------------------------------------------------------------------------
echo "S1: dry-run plans but changes nothing on disk:\n";
$d = "$fx/S1";
[$A, $B, $C] = buildMainTree($d);
$settings = "$d/settings.json";
writeSettings($settings, cset([$A, $B, $C], $C, ['recursive' => true]));
$progress = "$d/progress.json";

$snapDrives = static fn(string $d): array => [
    'A' => snapshotTree("$d/A"),
    'B' => snapshotTree("$d/B"),
    'C' => snapshotTree("$d/C"),
];
$before = $snapDrives($d);
[$code, $out] = runScript(["--settings=$settings", "--progress-file=$progress", '--dry-run']);
$after = $snapDrives($d);

check('dry-run exits 0', $code, 0);
check('dry-run leaves the tree byte-identical', $after, $before);
check('dry-run pid file removed on exit', file_exists("$progress.pid"), false);
$last = readLast($progress);
check('dry-run wrote a .last summary', is_array($last), true);
check('.last says dryRun', $last['dryRun'] ?? null, true);
check('.last exitCode 0', $last['exitCode'] ?? null, 0);
check('.last planned moves', $last['moved'] ?? null, 2);
check('.last planned dupes', $last['duped'] ?? null, 2);
check('.last planned movedBytes (14 + 6)', $last['movedBytes'] ?? null, 20);
check('.last nothing failed or skipped', [($last['failed'] ?? -1), ($last['skipped'] ?? -1)], [0, 0]);
check('.last no renames planned (the real # 01 outweighs the unnumbered base)', $last['renamed'] ?? null, 0);
check('.last records a non-negative durationSeconds',
    is_int($last['durationSeconds'] ?? null) && $last['durationSeconds'] >= 0, true);
$p = json_decode((string) @file_get_contents($progress), true);
check('final progress record is phase done', $p['phase'] ?? null, 'done');
check('final progress groups 5/5', [$p['groupsDone'] ?? -1, $p['groupsTotal'] ?? -1], [5, 5]);
check('final progress carries dryRun', $p['dryRun'] ?? null, true);
check('final progress driveFree keys are the drives', array_keys($p['driveFree'] ?? []), [$A, $B, $C]);
$tsv = "$d/consolidate_last_run.tsv";
check('default log lands beside the progress file', is_file($tsv), true);
check('log first line is the TSV header', explode("\t", trim((string) (@file($tsv)[0] ?? '')))[0], 'ts');
check('dry-run log rows are DRYRUN', tsvCount($tsv, 'MOVE', 'DRYRUN'), 2);
check('dry-run log has no OK moves', tsvCount($tsv, 'MOVE', 'OK'), 0);

// ---------------------------------------------------------------------------
echo "S2: execute consolidates, dedupes, shelves the unnumbered base (progress phases via FIFO):\n";
$d = "$fx/S2";
[$A, $B, $C] = buildMainTree($d);
$settings = "$d/settings.json";
writeSettings($settings, cset([$A, $B, $C], $C, ['recursive' => true]));
$progress = "$d/progress.json";

$phases = [];
$fifoOk = function_exists('posix_mkfifo') && posix_mkfifo($progress, 0644);
check('progress FIFO created (phase capture)', $fifoOk, true);
$fifoFh = $fifoOk ? fopen($progress, 'r+') : null; // r+ never blocks on a FIFO
if ($fifoFh !== null) {
    stream_set_blocking($fifoFh, false);
}
[$code, $stdout, $raw] = runScriptDrainingFifo(["--settings=$settings", "--progress-file=$progress", '--execute'], $fifoFh);
fclose($fifoFh);
preg_match_all('/"phase":"([a-z]+)"/', $raw, $m);
$phases = $m[1];

check('execute exits 0', $code, 0);
check('progress written repeatedly (scan/groups/done)', count($phases) >= 7, true);
check('first progress phase is scan', $phases[0] ?? null, 'scan');
check('progress includes the groups phase', in_array('groups', $phases, true), true);
check('last progress phase is done', end($phases) ?: null, 'done');

check('split group consolidated onto the dominant drive',
    file_get_contents("$A/Copperfield Chronicles # 03.mp4"), 'copper three!!');
check('subdir source removed from the minority drive',
    file_exists("$B/sub/Copperfield Chronicles # 03.mp4"), false);
check('dedupe kept the biggest copy in place',
    file_get_contents("$A/Marble Sky.mp4"), 'MARBLE-SKY-PRIMARY-1');
check('dedupe shelved the loser under its drive\'s duplicates/',
    file_get_contents("$B/duplicates/Marble Sky.mp4"), 'tiny.');
check('unnumbered base shelved when numbered episodes exist',
    file_get_contents("$A/duplicates/Foxglove Alley.mp4"), 'fox base');
check('numbered episode stayed primary', file_get_contents("$A/Foxglove Alley # 01.mp4"), 'fox ep one');
check('parenthetical-tag episodes grouped together and consolidated',
    file_get_contents("$A/Silverpine Saga # 02 (Kestrel).mp4"), 'kest02');
check('paren group\'s scene file untouched on the winning drive',
    file_get_contents("$A/Silverpine Saga # 01 (Kestrel) - Scene_1.mp4"), 'kestrel scene one long');
check('no-paren sibling is its own group and stays put',
    file_get_contents("$B/Silverpine Saga # 01.mp4"), 'plain one');

$last = readLast($progress);
check('execute .last: moved 2, duped 2, none failed/skipped',
    [$last['moved'] ?? -1, $last['duped'] ?? -1, $last['failed'] ?? -1, $last['skipped'] ?? -1],
    [2, 2, 0, 0]);
check('execute .last: renamed 0 (the real # 01 outweighs the unnumbered base)', $last['renamed'] ?? null, 0);
check('execute .last: movedBytes 20', $last['movedBytes'] ?? null, 20);
check('execute .last: dryRun false', $last['dryRun'] ?? null, false);
$tsv = "$d/consolidate_last_run.tsv";
check('log: two OK moves', tsvCount($tsv, 'MOVE', 'OK'), 2);
check('log: two OK duplicate shelves', tsvCount($tsv, 'MOVE_DUP', 'OK'), 2);
check('log: five groups done', tsvCount($tsv, 'GROUP_DONE', 'OK'), 5);

// ---------------------------------------------------------------------------
echo "S3: COLLISION RED-PROOFS against moviedb_consolidate_move_into_place():\n";
$d = "$fx/S3";
$A = "$d/A/recorded";
$B = "$d/B/recorded";
mkdir($A, 0777, true);
mkdir($B, 0777, true);
$logLines = [];
$log = function (string $action, string $src, string $dest, int $bytes, string $status, string $message) use (&$logLines): void {
    $logLines[] = [$action, $src, $dest, $bytes, $status, $message];
};

echo "  (a) equal size+mtime dest => source shelved as a true duplicate:\n";
$srcPath = "$B/Glass Harbor # 01.mp4";
$destPath = "$A/Glass Harbor # 01.mp4";
mkfile($srcPath, 'IDENTICAL8');
mkfile($destPath, 'IDENTICAL8');
$t = time() - 100;
touch($srcPath, $t);
touch($destPath, $t);
$destMtimeBefore = filemtime($destPath);
$res = moviedb_consolidate_move_into_place($srcPath, $destPath, $B, $A, false, $log);
check('(a) outcome is duped', $res['outcome'], 'duped');
check('(a) nothing quarantined', $res['quarantined'], null);
check('(a) source left its old path', file_exists($srcPath), false);
check('(a) source shelved into ITS drive\'s duplicates/',
    file_get_contents("$B/duplicates/Glass Harbor # 01.mp4"), 'IDENTICAL8');
clearstatcache();
check('(a) destination untouched', file_get_contents($destPath), 'IDENTICAL8');
check('(a) destination mtime untouched', filemtime($destPath), $destMtimeBefore);

echo "  (a2) mtime tolerance boundary (2s in, 3s out):\n";
$srcPath = "$B/Glass Harbor # 05.mp4";
$destPath = "$A/Glass Harbor # 05.mp4";
mkfile($srcPath, 'SAME');
mkfile($destPath, 'SAME');
touch($srcPath, $t);
touch($destPath, $t + 2);
$res = moviedb_consolidate_move_into_place($srcPath, $destPath, $B, $A, false, $log);
check('(a2) 2s mtime skew still counts as a duplicate', $res['outcome'], 'duped');
$srcPath = "$B/Glass Harbor # 06.mp4";
$destPath = "$A/Glass Harbor # 06.mp4";
mkfile($srcPath, 'AAAA');
mkfile($destPath, 'BBBB'); // same size, mtime 3s apart => suspect
touch($srcPath, $t);
touch($destPath, $t + 3);
$res = moviedb_consolidate_move_into_place($srcPath, $destPath, $B, $A, false, $log);
check('(a2) 3s mtime skew is suspect: source moved in', $res['outcome'], 'moved');
check('(a2) suspect dest quarantined with the prefix',
    file_get_contents("$A/duplicates/SUSPECT_PARTIAL__Glass Harbor # 06.mp4"), 'BBBB');
check('(a2) good copy survives as primary', file_get_contents($destPath), 'AAAA');

echo "  (b) smaller/older dest => dest quarantined, source moved in intact:\n";
$srcPath = "$B/Glass Harbor # 02.mp4";
$destPath = "$A/Glass Harbor # 02.mp4";
mkfile($srcPath, 'THE_GOOD_FULL_COPY_0123456789');
mkfile($destPath, 'PART');
touch($destPath, $t - 5000); // an old partial from an interrupted mv
$res = moviedb_consolidate_move_into_place($srcPath, $destPath, $B, $A, false, $log);
check('(b) outcome is moved', $res['outcome'], 'moved');
check('(b) reports the quarantine target',
    $res['quarantined'], "$A/duplicates/SUSPECT_PARTIAL__Glass Harbor # 02.mp4");
check('(b) partial quarantined with content preserved',
    file_get_contents("$A/duplicates/SUSPECT_PARTIAL__Glass Harbor # 02.mp4"), 'PART');
check('(b) the good copy\'s content survives as primary',
    file_get_contents($destPath), 'THE_GOOD_FULL_COPY_0123456789');
check('(b) source gone from its old drive', file_exists($srcPath), false);
check('(b) source NOT shelved as a duplicate (old behavior would have)',
    file_exists("$B/duplicates/Glass Harbor # 02.mp4"), false);

echo "  dry-run collision plans only:\n";
$srcPath = "$B/Glass Harbor # 03.mp4";
$destPath = "$A/Glass Harbor # 03.mp4";
mkfile($srcPath, 'FULL_LENGTH_CONTENT');
mkfile($destPath, 'STUB');
$before = [file_get_contents($srcPath), file_get_contents($destPath)];
$res = moviedb_consolidate_move_into_place($srcPath, $destPath, $B, $A, true, $log);
check('dry-run collision outcome is (planned) moved', $res['outcome'], 'moved');
check('dry-run plans the quarantine target',
    str_contains((string) $res['quarantined'], 'SUSPECT_PARTIAL__Glass Harbor # 03.mp4'), true);
check('dry-run touched nothing',
    [file_get_contents($srcPath), file_get_contents($destPath)], $before);
check('dry-run created no duplicates entry',
    file_exists("$A/duplicates/SUSPECT_PARTIAL__Glass Harbor # 03.mp4"), false);

echo "  failed mv leaves the source untouched:\n";
$srcPath = "$B/Glass Harbor # 04.mp4";
mkfile($srcPath, 'STAYS');
$res = moviedb_consolidate_move_into_place($srcPath, "$A/no-such-dir/Glass Harbor # 04.mp4", $B, $A, false, $log);
check('unmovable dest: outcome failed', $res['outcome'], 'failed');
check('unmovable dest: source untouched', file_get_contents($srcPath), 'STAYS');

echo "  quarantine of a partial left by a failed mv (direct):\n";
$partial = "$A/Harborline # 01.mp4";
mkfile($partial, 'HALF');
$to = moviedb_consolidate_quarantine_partial($partial, 100, $A, $log);
check('smaller leftover is quarantined', $to, "$A/duplicates/SUSPECT_PARTIAL__Harborline # 01.mp4");
check('quarantined partial keeps its content', file_get_contents((string) $to), 'HALF');
check('quarantined partial left the dest path', file_exists($partial), false);
$full = "$A/Harborline # 02.mp4";
mkfile($full, str_repeat('F', 100));
check('same-size leftover is NOT touched', moviedb_consolidate_quarantine_partial($full, 100, $A, $log), null);
check('same-size leftover still in place', is_file($full), true);
check('missing dest is a no-op', moviedb_consolidate_quarantine_partial("$A/nope.mp4", 100, $A, $log), null);

echo "  duplicates name collisions never overwrite:\n";
$shelfSrc = "$B/Twinned Name.mp4";
$contents = ['first copy', 'second copy', 'third copy'];
foreach ($contents as $c) {
    mkfile($shelfSrc, $c);
    $r = moviedb_consolidate_shelve_file($shelfSrc, $B, 'test', false, $log);
    check("shelve of '$c' succeeded", $r['ok'], true);
}
$shelved = array_values(array_filter(scandir("$B/duplicates") ?: [], fn($e) => str_starts_with($e, 'Twinned Name')));
check('three shelved twins all present', count($shelved), 3);
$shelvedContents = array_map(fn($e) => file_get_contents("$B/duplicates/$e"), $shelved);
sort($shelvedContents);
sort($contents);
check('no shelved twin was overwritten', $shelvedContents, $contents);

// ---------------------------------------------------------------------------
echo "S4: end-to-end suspect-dest collision (FIFO at dest — invisible to the scan):\n";
$d = "$fx/S4";
$A = "$d/A/recorded";
$B = "$d/B/recorded";
$C = "$d/C/recorded";
mkdir($A, 0777, true);
mkdir($B, 0777, true);
mkdir($C, 0777, true);
mkfile("$A/Harbor Nights # 01.mp4", 'one');
mkfile("$A/Harbor Nights # 02.mp4", 'two');
mkfile("$B/Harbor Nights # 03.mp4", 'REAL THREE');
$fifo = "$A/Harbor Nights # 03.mp4";
check('fifo created at the dest path', posix_mkfifo($fifo, 0644), true);
check('precondition: scan cannot see the fifo (is_file false)', is_file($fifo), false);
check('precondition: collision branch will see it (file_exists true)', file_exists($fifo), true);
$settings = "$d/settings.json";
writeSettings($settings, cset([$A, $B, $C], $C));
$progress = "$d/progress.json";
[$code, $out] = runScript(["--settings=$settings", "--progress-file=$progress", '--execute']);
check('run exits 0', $code, 0);
clearstatcache();
check('good copy moved in as a regular file', is_file("$A/Harbor Nights # 03.mp4"), true);
// Guarded read: on a regression the dest is still the FIFO and a plain
// file_get_contents would block forever on a writer-less pipe.
check('good copy content survives as primary',
    is_file("$A/Harbor Nights # 03.mp4") ? file_get_contents("$A/Harbor Nights # 03.mp4") : null,
    'REAL THREE');
check('source gone from the minority drive', file_exists("$B/Harbor Nights # 03.mp4"), false);
check('suspect dest quarantined with the prefix',
    file_exists("$A/duplicates/SUSPECT_PARTIAL__Harbor Nights # 03.mp4"), true);
$last = readLast($progress);
check('counters: 1 moved, 1 quarantine counted as duped, none failed',
    [$last['moved'] ?? -1, $last['duped'] ?? -1, $last['failed'] ?? -1], [1, 1, 0]);
$tsvRaw = (string) @file_get_contents("$d/consolidate_last_run.tsv");
check('log names the suspected partial', str_contains($tsvRaw, 'suspected partial'), true);
check('log shows the SUSPECT_PARTIAL__ target', str_contains($tsvRaw, 'SUSPECT_PARTIAL__Harbor Nights # 03.mp4'), true);

// ---------------------------------------------------------------------------
echo "S5: second instance exits 3 while the lock is held (and touches nothing):\n";
$d = "$fx/S5";
$A = "$d/A/recorded";
mkdir($A, 0777, true);
mkfile("$A/Lockout Lane # 01.mp4", 'll');
$settings = "$d/settings.json";
writeSettings($settings, cset([$A], $A));
$progress = "$d/progress.json";
mkfile("$progress.pid", '999999');            // the "running" instance's pidfile
mkfile("$d/consolidate_last_run.tsv", 'SENTINEL'); // ...and its log
$lockFh = fopen("$progress.lock", 'c');
check('harness acquired the lock', flock($lockFh, LOCK_EX | LOCK_NB), true);
[$code, $out] = runScript(["--settings=$settings", "--progress-file=$progress", '--dry-run']);
check('locked-out instance exits 3', $code, 3);
check('locked-out instance names the lock', str_contains(implode("\n", $out), 'lock'), true);
check('running instance\'s pidfile untouched', file_get_contents("$progress.pid"), '999999');
check('running instance\'s log NOT truncated', file_get_contents("$d/consolidate_last_run.tsv"), 'SENTINEL');
check('no .last written by the loser', file_exists("$progress.last"), false);
flock($lockFh, LOCK_UN);
fclose($lockFh);

// ---------------------------------------------------------------------------
echo "S6: missing drive dir => exit 1, nothing moved:\n";
$d = "$fx/S6";
$A = "$d/A/recorded";
$B = "$d/B/recorded";
mkdir($A, 0777, true);
mkdir($B, 0777, true);
mkfile("$A/Missing Drive Test # 01.mp4", 'm1');
mkfile("$B/Missing Drive Test # 02.mp4", 'm2');
$settings = "$d/settings.json";
writeSettings($settings, cset([$A, $B, "$d/Z/recorded"], $A)); // Z never created
$progress = "$d/progress.json";
$before = snapshotTree($d);
[$code, $out] = runScript(["--settings=$settings", "--progress-file=$progress", '--dry-run']);
check('missing drive exits 1', $code, 1);
check('error names the missing path', str_contains(implode("\n", $out), "$d/Z/recorded"), true);
$after = snapshotTree($d);
unset($after['progress.json.lock']); // the single-instance lock file is expected
check('nothing moved or created', $after, $before);
check('no .last for a run that never started', file_exists("$progress.last"), false);
check('endpoint-mode log not created on refusal', file_exists("$d/consolidate_last_run.tsv"), false);
check('pidfile cleaned up on the failure exit', file_exists("$progress.pid"), false);

echo "S6b: bare-CLI default log is timestamped in the cwd:\n";
$d = "$fx/S6b";
$A = "$d/A/recorded";
mkdir($A, 0777, true);
mkfile("$A/Bare Run # 01.mp4", 'br');
$settings = "$d/settings.json";
writeSettings($settings, cset([$A], $A));
[$code, $out] = runScript(["--settings=$settings", '--dry-run'], $d);
check('bare dry-run exits 0', $code, 0);
check('one timestamped move_status log in the cwd', count(glob("$d/move_status_*.tsv") ?: []), 1);

// ---------------------------------------------------------------------------
echo "S8: balance pass evacuates processed groups to the sink drive:\n";
$d = "$fx/S8";
$A = "$d/A/recorded";
$B = "$d/B/recorded";
$C = "$d/C/recorded";
mkdir($A, 0777, true);
mkdir($B, 0777, true);
mkdir($C, 0777, true);
mkfile("$A/Quill and Ember # 01.mp4", 'qe-one');
mkfile("$A/Quill and Ember # 02.mp4", 'qe-two!');
mkfile("$B/Rustling Vale # 01.mp4", 'rv-one');
mkfile("$B/Rustling Vale # 02.mp4", 'rv-two!');
if (disk_free_space($d) >= 20000 * 1024 ** 3) {
    echo "  SKIPPED: fixture volume has >= 20 000 GB free; the balance target can't be made unreachable\n";
} else {
    $settings = "$d/settings.json";
    writeSettings($settings, cset([$A, $B, $C], $C, ['balance' => true, 'targetFreeGb' => 20000]));
    $progress = "$d/progress.json";
    $phases = [];
    $fifoOk = posix_mkfifo($progress, 0644);
    $fifoFh = $fifoOk ? fopen($progress, 'r+') : null;
    if ($fifoFh !== null) {
        stream_set_blocking($fifoFh, false);
    }
    [$code, $stdout, $raw] = runScriptDrainingFifo(["--settings=$settings", "--progress-file=$progress", '--execute'], $fifoFh);
    fclose($fifoFh);
    preg_match_all('/"phase":"([a-z]+)"/', $raw, $m);
    $phases = $m[1];
    check('balance run exits 0', $code, 0);
    check('progress includes the balance phase', in_array('balance', $phases, true), true);
    check('balance run ends with phase done', end($phases) ?: null, 'done');
    foreach (['Quill and Ember # 01.mp4', 'Quill and Ember # 02.mp4',
              'Rustling Vale # 01.mp4', 'Rustling Vale # 02.mp4'] as $f) {
        check("evacuated to the sink: $f", is_file("$C/$f"), true);
    }
    check('non-sink drive A emptied', glob("$A/*.mp4"), []);
    check('non-sink drive B emptied', glob("$B/*.mp4"), []);
    check('log: four OK evacuation moves', tsvCount("$d/consolidate_last_run.tsv", 'EVAC_MOVE', 'OK'), 4);
    $last = readLast($progress);
    check('evacuations counted as moves', $last['moved'] ?? null, 4);
    check('evacuated bytes counted', $last['movedBytes'] ?? null, 6 + 7 + 6 + 7);
}

echo "S8b: an evacuation that shelves a true duplicate still COUNTS it:\n";
// The evacuation 'duped' branch fires only for a destination the scan can't
// see — a regular file there would be scanned into the same bucket and
// deduped during the group phase, long before evacuation (see the header
// note). So: a FIFO at the sink (file_exists true, is_file false => invisible
// to the scan) sized 0 like the source and given the same mtime satisfies
// move_into_place's size+mtime equality test, taking the true-duplicate path.
// That physically shelves the source, so it must increment 'duped' — it
// silently incremented nothing, which ALSO made the index-rebuild gate miss a
// real disk change.
$d = "$fx/S8b";
$A = "$d/A/recorded";
$C = "$d/C/recorded";
mkdir($A, 0777, true);
mkdir($C, 0777, true);
mkfile("$A/Cinder Hollow # 01.mp4", ''); // 0 bytes, to match the FIFO's size
$fifoAtSink = "$C/Cinder Hollow # 01.mp4";
$fifoMade = function_exists('posix_mkfifo') && posix_mkfifo($fifoAtSink, 0644);
check('S8b sink FIFO created', $fifoMade, true);
touch("$A/Cinder Hollow # 01.mp4", 1700000000);
@touch($fifoAtSink, 1700000000);
if (!$fifoMade || disk_free_space($d) >= 20000 * 1024 ** 3) {
    echo "  SKIPPED: no FIFO support, or the fixture volume has >= 20 000 GB free\n";
} else {
    $settings = "$d/settings.json";
    writeSettings($settings, cset([$A, $C], $C, ['balance' => true, 'targetFreeGb' => 20000]));
    [$code, ] = runScript(["--settings=$settings", "--progress-file=$d/progress.json", '--execute']);
    check('S8b exits 0', $code, 0);
    check('the source was shelved on its own drive',
        is_file("$A/duplicates/Cinder Hollow # 01.mp4"), true);
    check('the source no longer sits at its original path',
        is_file("$A/Cinder Hollow # 01.mp4"), false);
    check('the evacuation shelve was logged as EVAC_DUP',
        tsvCount("$d/consolidate_last_run.tsv", 'EVAC_DUP', 'OK'), 1);
    check('and it COUNTS as a duplicate (it counted 0 before this fix)',
        readLast("$d/progress.json")['duped'] ?? null, 1);
    unlink($fifoAtSink);
}

// ---------------------------------------------------------------------------
echo "S9: unreachable reserve => group skipped, nothing moved:\n";
$d = "$fx/S9";
$A = "$d/A/recorded";
$B = "$d/B/recorded";
$C = "$d/C/recorded";
mkdir($A, 0777, true);
mkdir($B, 0777, true);
mkdir($C, 0777, true);
mkfile("$A/Copperfield Chronicles # 01.mp4", 'copper one!!');
mkfile("$B/Copperfield Chronicles # 02.mp4", 'cc2');
if (disk_free_space($d) >= 20000 * 1024 ** 3) {
    echo "  SKIPPED: fixture volume has >= 20 000 GB free; the reserve can't be made unreachable\n";
} else {
    $settings = "$d/settings.json";
    writeSettings($settings, cset([$A, $B, $C], $C, ['reserveGb' => 20000]));
    $progress = "$d/progress.json";
    $before = snapshotTree($d);
    [$code, $out] = runScript(["--settings=$settings", "--progress-file=$progress", '--execute']);
    $after = snapshotTree($d);
    unset($after['progress.json'], $after['progress.json.last'], $after['consolidate_last_run.tsv'],
        $after['progress.json.lock'], $after['progress.json.pid']);
    unset($before['progress.json'], $before['progress.json.last'], $before['consolidate_last_run.tsv'],
        $before['progress.json.lock'], $before['progress.json.pid']);
    check('skip run exits 0', $code, 0);
    check('tree unchanged when every destination lacks reserve', $after, $before);
    $last = readLast($progress);
    check('group counted as skipped', $last['skipped'] ?? null, 1);
    check('nothing moved', $last['moved'] ?? null, 0);
    check('log records the GROUP_SKIP', tsvCount("$d/consolidate_last_run.tsv", 'GROUP_SKIP', 'SKIP'), 1);

    echo "S9b: CLI --reserve-gb=0 overrides the settings value:\n";
    [$code, $out] = runScript(["--settings=$settings", "--progress-file=$progress", '--execute', '--reserve-gb=0']);
    check('override run exits 0', $code, 0);
    check('group now consolidates onto the byte-dominant drive',
        file_get_contents("$A/Copperfield Chronicles # 02.mp4"), 'cc2');
    check('minority copy left its drive', file_exists("$B/Copperfield Chronicles # 02.mp4"), false);
}

// ---------------------------------------------------------------------------
echo "S10: settings merge (lib):\n";
check('missing settings file yields the original hardcoded defaults',
    moviedb_consolidate_settings("$fx/no_such_settings.json"),
    [
        'drives' => [
            '/Volumes/Recorded 1/recorded',
            '/Volumes/Recorded 2/recorded',
            '/Volumes/Recorded 3/recorded',
            '/Volumes/Recorded 4/recorded',
        ],
        'balanceTo'    => '/Volumes/Recorded 4/recorded',
        'targetFreeGb' => 100,
        'reserveGb'    => 20,
        'balance'      => false,
        'recursive'    => false,
    ]);
$sf = "$fx/settings_merge.json";
mkfile($sf, json_encode(['consolidate' => [
    'drives' => ['/tmp/x/', '/tmp/y'], 'balanceTo' => '/tmp/y/',
    'targetFreeGb' => 7, 'reserveGb' => 3, 'balance' => false, 'recursive' => true,
]]));
check('full override wins, trailing slashes trimmed',
    moviedb_consolidate_settings($sf),
    ['drives' => ['/tmp/x', '/tmp/y'], 'balanceTo' => '/tmp/y',
     'targetFreeGb' => 7, 'reserveGb' => 3, 'balance' => false, 'recursive' => true]);
mkfile($sf, json_encode(['consolidate' => ['targetFreeGb' => 7]]));
$merged = moviedb_consolidate_settings($sf);
check('partial override keeps default drives', count($merged['drives']), 4);
check('partial override applies the given key', $merged['targetFreeGb'], 7);
mkfile($sf, json_encode(['consolidate' => [
    'drives' => ['/tmp/x', 42], 'targetFreeGb' => -5, 'reserveGb' => 2.5, 'balance' => 'yes',
]]));
$merged = moviedb_consolidate_settings($sf);
check('non-string drive entry falls back to default drives', count($merged['drives']), 4);
check('negative int falls back to default', $merged['targetFreeGb'], 100);
check('float falls back to default', $merged['reserveGb'], 20);
check('non-bool falls back to default', $merged['balance'], false);
mkfile($sf, 'not json');
check('garbage settings file yields defaults', moviedb_consolidate_settings($sf)['targetFreeGb'], 100);

echo "S10: settings validation (lib, allowed base = fixture):\n";
putenv('ALLOWED_BASE_PATH=' . $fx);
$vA = "$fx/S1/A/recorded"; // exists from S1
$vB = "$fx/S1/B/recorded";
$valid = ['drives' => [$vA, "$vB/"], 'balanceTo' => "$vB/",
    'targetFreeGb' => 100, 'reserveGb' => 20, 'balance' => true, 'recursive' => false];
$r = moviedb_validate_consolidate_settings($valid);
check('valid settings accepted', $r['ok'], true);
check('clean drives normalized (trailing slash trimmed)', $r['clean']['drives'], [$vA, $vB]);
check('clean balanceTo normalized', $r['clean']['balanceTo'], $vB);
check('non-array refused', moviedb_validate_consolidate_settings('nope')['ok'], false);
$c = $valid;
unset($c['recursive']);
$r = moviedb_validate_consolidate_settings($c);
check('missing key refused', $r['ok'], false);
check('missing key named', str_contains($r['error'], 'recursive'), true);
$c = $valid + ['bonus' => 1];
$r = moviedb_validate_consolidate_settings($c);
check('unknown key refused', $r['ok'], false);
$c = $valid;
$c['balanceTo'] = "$fx/S1/C/recorded";
check('balanceTo outside drives refused', moviedb_validate_consolidate_settings($c)['ok'], false);
$c = $valid;
$c['drives'] = [];
check('zero drives refused', moviedb_validate_consolidate_settings($c)['ok'], false);
$c = $valid;
$c['drives'] = array_fill(0, 9, $vA);
check('nine drives refused', moviedb_validate_consolidate_settings($c)['ok'], false);
$c = $valid;
$c['drives'] = [$vA, $vA];
check('duplicate drives refused', moviedb_validate_consolidate_settings($c)['ok'], false);
$c = $valid;
$c['drives'] = [$vA, 42];
check('non-string drive refused', moviedb_validate_consolidate_settings($c)['ok'], false);
$c = $valid;
$c['drives'] = [$vA, '/etc'];
$c['balanceTo'] = $vA;
check('drive outside the allowed base refused', moviedb_validate_consolidate_settings($c)['ok'], false);
foreach ([['targetFreeGb', 20001], ['targetFreeGb', -1], ['reserveGb', '5'], ['balance', 1], ['recursive', 'yes']] as [$k, $v]) {
    $c = $valid;
    $c[$k] = $v;
    check("$k = " . var_export($v, true) . ' refused', moviedb_validate_consolidate_settings($c)['ok'], false);
}
putenv('ALLOWED_BASE_PATH');

echo "S10: log tail (lib):\n";
check('missing file tails to []', moviedb_consolidate_tail_lines("$fx/nope.tsv", 5), []);
$tailFile = "$fx/tail.txt";
mkfile($tailFile, '');
check('empty file tails to []', moviedb_consolidate_tail_lines($tailFile, 5), []);
mkfile($tailFile, "a\nb\nc\n");
check('tail smaller than the file', moviedb_consolidate_tail_lines($tailFile, 2), ['b', 'c']);
check('tail of exactly all lines', moviedb_consolidate_tail_lines($tailFile, 3), ['a', 'b', 'c']);
check('tail larger than the file', moviedb_consolidate_tail_lines($tailFile, 50), ['a', 'b', 'c']);
mkfile($tailFile, "one\ntwo (no trailing newline)");
check('no trailing newline: last line kept',
    moviedb_consolidate_tail_lines($tailFile, 1), ['two (no trailing newline)']);
$big = [];
for ($i = 1; $i <= 2000; $i++) {
    $big[] = "line $i " . str_repeat('x', 40);
}
mkfile($tailFile, implode("\n", $big) . "\n");
check('chunked backward read over a ~100KB file',
    moviedb_consolidate_tail_lines($tailFile, 3),
    [$big[1997], $big[1998], $big[1999]]);

echo "S10: pid liveness (lib):\n";
check('own pid is alive', moviedb_consolidate_pid_alive((int) getmypid()), true);
check('pid 0 is not alive', moviedb_consolidate_pid_alive(0), false);
check('negative pid is not alive', moviedb_consolidate_pid_alive(-5), false);
$deadOut = [];
exec('true & echo $!', $deadOut); // exec() already wraps in sh -c, so $! is the backgrounded true

$deadPid = (int) trim(implode('', $deadOut));
usleep(100000); // let the child get reaped
check('captured a child pid to test with', $deadPid > 0, true);
check('exited pid is not alive', moviedb_consolidate_pid_alive($deadPid), false);

// ---------------------------------------------------------------------------
echo "S11: the endpoint's detached-spawn recipe works (nohup ... & echo \$!):\n";
$d = "$fx/S11";
$A = "$d/A/recorded";
mkdir($A, 0777, true);
mkfile("$A/Spawned Story # 01.mp4", 'ss');
$settings = "$d/settings.json";
writeSettings($settings, cset([$A], $A));
$progress = "$d/progress.json";
$spawnLog = "$d/spawn.log";
$cmd = 'nohup /usr/bin/env php ' . escapeshellarg($script)
    . ' --settings=' . escapeshellarg($settings)
    . ' --progress-file=' . escapeshellarg($progress)
    . ' --dry-run >> ' . escapeshellarg($spawnLog) . ' 2>&1 & echo $!';
$out = [];
exec($cmd, $out);
$pid = (int) trim((string) end($out));
check('spawn captured a pid', $pid > 0, true);
$deadline = microtime(true) + 10;
while (moviedb_consolidate_pid_alive($pid) && microtime(true) < $deadline) {
    usleep(50000);
}
check('spawned run finished within 10s', moviedb_consolidate_pid_alive($pid), false);
$last = readLast($progress);
check('spawned run wrote its .last summary', $last['dryRun'] ?? null, true);
check('spawned run logged to the spawn log',
    str_contains((string) @file_get_contents($spawnLog), 'Done.'), true);

echo "S10: action tail (lib):\n";
$tailFx = "$fx/actiontail.tsv";
$rows = ["ts\tmode\tgroup\taction\tsrc\tdest\tbytes\tstatus\tmessage"];
for ($i = 1; $i <= 5; $i++) {
    $rows[] = "t\tDRYRUN\tG$i\tMOVE\t/s/f$i.mp4\t/d/f$i.mp4\t10\tDRYRUN\t";
    $rows[] = "t\tDRYRUN\tG$i\tGROUP_DONE\t\t/d\t10\tOK\t";
}
mkfile($tailFx, implode("\n", $rows) . "\n");
$actions = moviedb_consolidate_action_tail($tailFx, 3);
check('action tail keeps only action rows', count($actions), 3);
check('action tail keeps the LAST actions',
    array_map(fn($l) => explode("\t", $l)[2], $actions), ['G3', 'G4', 'G5']);
check('action tail drops bookkeeping + header',
    strpos(implode("\n", $actions), 'GROUP_DONE'), false);

echo "S12: '# N' inside a movie NAME is not an episode marker:\n";
$d = "$fx/S12";
$A = "$d/driveA";
$B = "$d/driveB";
mkfile("$A/Player # 01.mp4", 'series one');
mkfile("$A/Player # 02.mp4", 'series two');
mkfile("$B/Player #3 Returns.mp4", 'standalone glued');    // glued "#3": part of the name
mkfile("$B/Apartment # 3 Secrets.mp4", 'name with number'); // wordy tail: part of the name
$settings = "$d/settings.json";
writeSettings($settings, cset([$A, $B], $A));
[$code, ] = runScript(["--settings=$settings", "--progress-file=$d/progress.json", '--execute']);
check('S12 execute exits 0', $code, 0);
check('glued "#3" title survives untouched (old parser shelved it as a series dup)',
    @file_get_contents("$B/Player #3 Returns.mp4"), 'standalone glued');
check('wordy-tail "# 3" title survives untouched',
    @file_get_contents("$B/Apartment # 3 Secrets.mp4"), 'name with number');
check('no duplicates shelf appeared for the standalone titles', is_dir("$B/duplicates"), false);
check('the real series stayed consolidated on its drive',
    [@file_get_contents("$A/Player # 01.mp4"), @file_get_contents("$A/Player # 02.mp4")],
    ['series one', 'series two']);

echo "S13: balance pass never ping-pongs a freshly consolidated group:\n";
$d = "$fx/S13";
$A = "$d/driveA";
$SINK = "$d/sink";
mkfile("$A/Willow Creek # 01.mp4", 'w one');
mkfile("$A/Willow Creek # 02.mp4", 'w two');
mkfile("$SINK/Willow Creek # 03.mp4", 'w3');
$settings = "$d/settings.json";
// balance ON with an absurd target: without the ping-pong guard the balance
// pass would immediately evacuate the just-consolidated group off A to sink
writeSettings($settings, cset([$A, $SINK], $SINK, ['balance' => true]));
[$code, ] = runScript(["--settings=$settings", "--progress-file=$d/progress.json", '--execute', '--target-free-gb=999999']);
check('S13 execute exits 0', $code, 0);
check('group consolidated onto its dominant drive',
    @file_get_contents("$A/Willow Creek # 03.mp4"), 'w3');
check('balance did NOT bounce the fresh group to the sink',
    file_exists("$SINK/Willow Creek # 03.mp4") || file_exists("$SINK/Willow Creek # 01.mp4"), false);

echo "S17: a SUFFIXED '# 01' blocks the rename — the unnumbered file is flagged, not renumbered:\n";
$d = "$fx/S17";
$A = "$d/driveA";
$B = "$d/driveB";
// "# 01 - Honeymoon" is its own work (Sean's rule), so it does NOT make the
// unnumbered file volume 1 — renaming would put two things in the # 01 slot.
mkfile("$A/Twin Booking.mp4", 'unnumbered original');
mkfile("$A/Twin Booking # 01 - Honeymoon.mp4", 'honeymoon cut');
mkfile("$B/Twin Booking # 02 - Ranch.mp4", 'ranch cut');
$settings = "$d/settings.json";
writeSettings($settings, cset([$A, $B], $A));
[$code, ] = runScript(["--settings=$settings", "--progress-file=$d/progress.json", '--execute']);
$tsv = "$d/consolidate_last_run.tsv";
check('S17 execute exits 0', $code, 0);
check('the unnumbered file keeps its name',
    @file_get_contents("$A/Twin Booking.mp4"), 'unnumbered original');
check('no bare "# 01" was created', file_exists("$A/Twin Booking # 01.mp4"), false);
check('the suffixed volume 1 is untouched',
    @file_get_contents("$A/Twin Booking # 01 - Honeymoon.mp4"), 'honeymoon cut');
check('nothing was shelved as a duplicate', is_dir("$A/duplicates"), false);
check('the skip is LOGGED for review', tsvCount($tsv, 'RENAME_01_SKIP', 'SKIP'), 1);
check('the log names the conflicting volume',
    (bool) preg_match('/RENAME_01_SKIP.*Twin Booking # 01 - Honeymoon/', (string) @file_get_contents($tsv)), true);
check('.last counts it as needing review', readLast("$d/progress.json")['flagged'] ?? null, 1);
check('.last records no rename', readLast("$d/progress.json")['renamed'] ?? null, 0);
check('the group still consolidated onto one drive',
    @file_get_contents("$A/Twin Booking # 02 - Ranch.mp4"), 'ranch cut');

// A BARE "# 01" is the opposite case: same item, best copy wins, still renamed
$d = "$fx/S17b";
$A = "$d/driveA";
mkfile("$A/Twin Booking.mp4", 'bigger unnumbered copy');
mkfile("$A/Twin Booking # 01.mp4", 'small');
mkfile("$A/Twin Booking # 02.mp4", 'second');
$settings = "$d/settings.json";
writeSettings($settings, cset([$A], $A));
[$code, ] = runScript(["--settings=$settings", "--progress-file=$d/progress.json", '--execute']);
check('S17b execute exits 0', $code, 0);
check('a BARE # 01 still dedupes and renames',
    @file_get_contents("$A/Twin Booking # 01.mp4"), 'bigger unnumbered copy');
check('S17b flagged nothing', readLast("$d/progress.json")['flagged'] ?? null, 0);
check('S17b counted the rename', readLast("$d/progress.json")['renamed'] ?? null, 1);

echo "S16: the balance pass is VISIBLE in the TSV log even when it moves nothing:\n";
$d = "$fx/S16";
$A = "$d/driveA";
$SINK = "$d/sink";
mkfile("$A/Larkspur Terrace # 01.mp4", 'lark one');
mkfile("$SINK/Larkspur Terrace # 02.mp4", 'lark two');
$settings = "$d/settings.json";
// balance ON, target 0 GB => every drive is already above target => no moves
writeSettings($settings, cset([$A, $SINK], $SINK, ['balance' => true]));
[$code, ] = runScript(["--settings=$settings", "--progress-file=$d/progress.json", '--execute', '--target-free-gb=0']);
$tsv = "$d/consolidate_last_run.tsv";
check('S16 execute exits 0', $code, 0);
check('a no-op balance pass still logs a BALANCE_CHECK row', tsvCount($tsv, 'BALANCE_CHECK', 'SKIP'), 1);
check('the no-op row explains itself (free vs target)',
    (bool) preg_match('/BALANCE_CHECK.*nothing to evacuate/', (string) @file_get_contents($tsv)), true);
check('a no-op balance pass moves nothing', tsvCount($tsv, 'EVAC_MOVE', 'OK'), 0);
check('BALANCE_CHECK survives the actions-only tail (it is not bookkeeping noise)',
    (bool) preg_grep('/\tBALANCE_CHECK\t/', moviedb_consolidate_action_tail($tsv, 50)), true);

// balancing switched OFF entirely => one row saying so
$d2 = "$fx/S16b";
$A2 = "$d2/driveA";
$SINK2 = "$d2/sink";
mkfile("$A2/Larkspur Terrace # 01.mp4", 'lark one');
mkfile("$SINK2/Larkspur Terrace # 02.mp4", 'lark two');
$settings2 = "$d2/settings.json";
writeSettings($settings2, cset([$A2, $SINK2], $SINK2, ['balance' => false]));
[$code, ] = runScript(["--settings=$settings2", "--progress-file=$d2/progress.json", '--execute']);
$tsv2 = "$d2/consolidate_last_run.tsv";
check('S16b execute exits 0', $code, 0);
check('balancing switched off writes NO balance rows at all',
    preg_grep('/\tBALANCE/', @file($tsv2) ?: []), []);

echo "S15: --rebuild-index refreshes the drive index beside the SETTINGS file (never the real one):\n";
$d = "$fx/S15";
$A = "$d/driveA";
$B = "$d/driveB";
mkfile("$A/Thistle Harbor # 01.mp4", 'thistle one');
mkfile("$B/Thistle Harbor # 02.mp4", 'thistle two');
$settings = "$d/settings.json";
// driveIndexRoots is read from this SAME file, and the index is written beside
// it — the mechanism that keeps fixture runs off server/drive_index.json
mkfile($settings, json_encode([
    'consolidate' => cset([$A, $B], $A),
    'driveIndexRoots' => [$A, $B],
]));
$fixtureIndex = "$d/drive_index.json";
$realIndex = __DIR__ . '/../drive_index.json';
$realBefore = is_file($realIndex) ? [filemtime($realIndex), filesize($realIndex)] : null;

// (a) no flag => no index written at all
[$code, ] = runScript(["--settings=$settings", "--progress-file=$d/progress.json", '--execute']);
check('S15 execute (no flag) exits 0', $code, 0);
check('without --rebuild-index no index is written', is_file($fixtureIndex), false);
check('without --rebuild-index .last reports indexRebuilt null',
    indexRebuiltOf("$d/progress.json"), null);

// (b) dry run with the flag => still nothing (nothing moved)
mkfile("$B/Thistle Harbor # 03.mp4", 'thistle three');
[$code, ] = runScript(["--settings=$settings", "--progress-file=$d/progress.json", '--dry-run', '--rebuild-index']);
check('S15 dry-run (with flag) exits 0', $code, 0);
check('a dry run never rebuilds the index', is_file($fixtureIndex), false);
check('dry run reports indexRebuilt null', indexRebuiltOf("$d/progress.json"), null);

// (c) execute with the flag and a real move => index rebuilt, beside settings
[$code, $out] = runScript(["--settings=$settings", "--progress-file=$d/progress.json", '--execute', '--rebuild-index']);
check('S15 execute (with flag) exits 0', $code, 0);
check('the moved file landed on the dominant drive',
    @file_get_contents("$A/Thistle Harbor # 03.mp4"), 'thistle three');
check('the index was written BESIDE the settings file', is_file($fixtureIndex), true);
$idx = json_decode((string) @file_get_contents($fixtureIndex), true);
check('the fixture index catalogued all three fixture files', count($idx['entries'] ?? []), 3);
check('every indexed path is inside the fixture tree',
    array_values(array_unique(array_map(
        static fn(array $e): bool => strpos((string) $e['dir'], $d) === 0,
        $idx['entries'] ?? []
    ))), [true]);
check('.last reports indexRebuilt true', indexRebuiltOf("$d/progress.json"), true);
check('the run logged an INDEX_REBUILD row',
    tsvCount("$d/consolidate_last_run.tsv", 'INDEX_REBUILD', 'OK'), 1);
check('the REAL server/drive_index.json was never touched',
    is_file($realIndex) ? [filemtime($realIndex), filesize($realIndex)] : null, $realBefore);
check('the index progress sidecar was cleaned up', is_file("$fixtureIndex.progress"), false);

// (c2) THE GUARD: a writeSettings()-shaped file (no driveIndexRoots) must be
// REFUSED, not silently walked against the real /Volumes library. Without this
// guard, adding --rebuild-index to any existing scenario would scan the real
// drives and dump a listing of the private library into the temp dir.
$dNo = "$fx/S15c";
$ANo = "$dNo/driveA";
$BNo = "$dNo/driveB";
mkfile("$ANo/Thistle Harbor # 01.mp4", 'thistle one');
mkfile("$BNo/Thistle Harbor # 02.mp4", 'thistle two');
$settingsNoRoots = "$dNo/settings.json";
writeSettings($settingsNoRoots, cset([$ANo, $BNo], $ANo)); // no driveIndexRoots
[$code, ] = runScript(["--settings=$settingsNoRoots", "--progress-file=$dNo/progress.json", '--execute', '--rebuild-index']);
check('S15c execute exits 0 (a refused rebuild is not fatal)', $code, 0);
check('the consolidation itself still happened',
    @file_get_contents("$ANo/Thistle Harbor # 02.mp4"), 'thistle two');
check('settings without driveIndexRoots => NO index written', is_file("$dNo/drive_index.json"), false);
check('the refusal is logged', tsvCount("$dNo/consolidate_last_run.tsv", 'INDEX_REBUILD', 'SKIP'), 1);
check('a refused rebuild reports indexRebuilt null', indexRebuiltOf("$dNo/progress.json"), null);
check('the REAL server/drive_index.json is STILL untouched',
    is_file($realIndex) ? [filemtime($realIndex), filesize($realIndex)] : null, $realBefore);

// (d) a second execute with nothing left to move => no rebuild, but SAY SO
$idxMtimeBefore = filemtime($fixtureIndex);
[$code, ] = runScript(["--settings=$settings", "--progress-file=$d/progress.json", '--execute', '--rebuild-index']);
check('S15 no-op execute exits 0', $code, 0);
check('a run that changed nothing skips the rebuild', indexRebuiltOf("$d/progress.json"), null);
check('the existing index file was left alone', filemtime($fixtureIndex), $idxMtimeBefore);
// The skip must be VISIBLE: with no row, the log can't answer "did it reindex?"
check('the skip is logged rather than silent',
    tsvCount("$d/consolidate_last_run.tsv", 'INDEX_REBUILD', 'SKIP'), 1);
check('the skipped-rebuild row survives the actions-only tail',
    (bool) preg_grep('/\tINDEX_REBUILD\t/', moviedb_consolidate_action_tail("$d/consolidate_last_run.tsv", 50)), true);

echo "S14: unnumbered base becomes '# 01' when numbered siblings exist (app convention):\n";
$d = "$fx/S14";
$A = "$d/driveA";
$B = "$d/driveB";
// (a) no "# 01" exists: base renamed in place; sibling consolidates onto A (more bytes)
mkfile("$A/Harbor Lights.mp4", 'harbor base!!');                 // 13 bytes
mkfile("$B/Harbor Lights # 12.mp4", 'h twelve');                 // 8 bytes
// (b) parenthetical tag: number is inserted BEFORE the tag
mkfile("$A/Quarry Lane (Kestrel).mp4", 'quarry base');
mkfile("$A/Quarry Lane # 02 (Kestrel).mp4", 'quarry two');
// (c) bigger unnumbered beats a smaller real "# 01": loser shelved, winner renamed onto the freed name
mkfile("$A/Neon Estuary.mp4", 'estuary big winner');             // 18 bytes
mkfile("$A/Neon Estuary # 01.mp4", 'small');                     // 5 bytes
mkfile("$A/Neon Estuary # 02.mp4", 'estuary two');
$settings = "$d/settings.json";
writeSettings($settings, cset([$A, $B], $A));

// Dry-run FIRST (against the untouched tree): plans the renames, reports no
// suspect-partial quarantine even though the shelved "# 01" loser still
// occupies the rename target on disk, and changes nothing.
$before = [snapshotTree($A), snapshotTree($B)];
[$code, ] = runScript(["--settings=$settings", "--progress-file=$d/progress.json", '--dry-run']);
check('S14 dry-run exits 0', $code, 0);
check('S14 dry-run leaves the drives byte-identical', [snapshotTree($A), snapshotTree($B)], $before);
$last = readLast("$d/progress.json");
check('S14 dry-run .last plans 3 renames', $last['renamed'] ?? null, 3);
$tsv = "$d/consolidate_last_run.tsv";
check('S14 dry-run logs 3 RENAME_01 rows', tsvCount($tsv, 'RENAME_01', 'DRYRUN'), 3);
check('S14 dry-run plans NO suspect-partial quarantine (planned-shelved target reads as free)',
    tsvCount($tsv, 'MOVE_DUP', 'DRYRUN')
        + (int) (strpos((string) @file_get_contents($tsv), 'SUSPECT_PARTIAL') !== false ? 99 : 0),
    1); // exactly the one true dup shelf (small "Neon Estuary # 01"), no SUSPECT rows

[$code, ] = runScript(["--settings=$settings", "--progress-file=$d/progress.json", '--execute']);
check('S14 execute exits 0', $code, 0);
check('(a) unnumbered base renamed to # 01 in place',
    @file_get_contents("$A/Harbor Lights # 01.mp4"), 'harbor base!!');
check('(a) original unnumbered name is gone', file_exists("$A/Harbor Lights.mp4"), false);
check('(a) numbered sibling consolidated alongside it',
    @file_get_contents("$A/Harbor Lights # 12.mp4"), 'h twelve');
check('(b) number inserted before the parenthetical tag',
    @file_get_contents("$A/Quarry Lane # 01 (Kestrel).mp4"), 'quarry base');
check('(b) original paren name is gone', file_exists("$A/Quarry Lane (Kestrel).mp4"), false);
check('(c) smaller real # 01 shelved as the duplicate',
    @file_get_contents("$A/duplicates/Neon Estuary # 01.mp4"), 'small');
check('(c) bigger unnumbered file now owns the # 01 name',
    @file_get_contents("$A/Neon Estuary # 01.mp4"), 'estuary big winner');
check('(c) original unnumbered name is gone', file_exists("$A/Neon Estuary.mp4"), false);
$last = readLast("$d/progress.json");
check('S14 .last: renamed 3, duped 1, failed 0',
    [$last['renamed'] ?? -1, $last['duped'] ?? -1, $last['failed'] ?? -1], [3, 1, 0]);
check('S14 execute logs 3 RENAME_01 rows', tsvCount($tsv, 'RENAME_01', 'OK'), 3);
check('S14 execute produced no quarantine',
    strpos((string) @file_get_contents($tsv), 'SUSPECT_PARTIAL'), false);

// ---------------------------------------------------------------------------
rrmdir($fx);
check('fixture tree cleaned up', is_dir($fx), false);

echo "\n$checks checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
