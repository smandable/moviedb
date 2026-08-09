<?php

declare(strict_types=1);

// CLI only: the whole repo sits under httpd's DocumentRoot, so without this
// guard a bare GET to this file would execute it via mod_php.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}


/**
 * consolidate_movies.php — hardened in-repo port of the standalone episodic
 * consolidator. Consolidates episodic video files spread across the
 * configured drive dirs, moving files group-by-group (base title: "# NN"
 * volumes of one series form ONE group), minimizing moved bytes, shelving
 * duplicates into each drive's duplicates/ dir (NEVER deleting anything),
 * evacuating space when needed, and optionally balancing free space by moving
 * whole groups to the balance-to drive.
 *
 * vs. the original standalone script:
 *   - Destination collisions no longer shelve the source blindly: only a
 *     size+mtime match (2s tolerance) is a true duplicate; anything else at
 *     the destination is quarantined aside as SUSPECT_PARTIAL__* and the good
 *     source is moved in. A failed mv that leaves a smaller partial at the
 *     destination gets the same quarantine so a retry is never poisoned.
 *     (Shared logic: server/consolidate_lib.php.)
 *   - Config comes from app_settings.json key "consolidate" (path via
 *     --settings, default ../server/app_settings.json); CLI flags override;
 *     hardcoded defaults match the original script.
 *   - --progress-file=PATH writes live progress JSON for the Settings page,
 *     plus PATH.last (final run summary), PATH.lock (single instance — exits
 *     3 when another run holds it) and PATH.pid (removed on exit).
 *
 * macOS-friendly (BSD tools). Uses /bin/mv for cross-volume moves.
 *
 * IMPORTANT: Run --dry-run first.
 */

date_default_timezone_set('America/New_York');

require_once __DIR__ . '/../server/consolidate_lib.php';
require_once __DIR__ . '/../server/drive_index_lib.php';

// Video extensions to include (case-insensitive)
$DEFAULT_EXTENSIONS = ['mp4', 'mkv', 'avi', 'mov', 'm4v', 'mpg', 'mpeg', 'ts', 'wmv', 'flv', 'webm', 'vob'];

// -----------------------
// CLI Options
// -----------------------
$opts = getopt('', [
    'dry-run',
    'execute',
    'recursive',
    'no-balance',
    'help',
    'target-free-gb:',
    'reserve-gb:',
    'only:',           // substring filter on base title (case-insensitive)
    'limit-groups:',   // stop after N groups (for testing)
    'log:',            // custom log filename
    'settings:',       // app_settings.json path (key "consolidate")
    'progress-file:',  // live progress JSON sidecar (endpoint mode)
    'rebuild-index',   // rebuild the drive index after a run that moved files
]);

function usageAndExit(): void
{
    $u = <<<TXT
Usage:
  php consolidate_movies.php --dry-run [options]
  php consolidate_movies.php --execute [options]

Options:
  --dry-run                  Plan only (default if --execute not provided)
  --execute                  Perform moves
  --recursive                Scan subdirectories too
  --settings=PATH            app_settings.json to read drives/balanceTo/targetFreeGb/
                             reserveGb/balance/recursive from (key "consolidate");
                             default: ../server/app_settings.json. CLI flags override.
  --progress-file=PATH       Write live progress JSON to PATH (plus PATH.last summary,
                             PATH.lock single-instance lock, PATH.pid); used by the
                             web endpoint. Exits 3 when another run holds the lock.
  --rebuild-index            After an --execute run that actually moved/renamed/shelved
                             something, rebuild the drive index so its paths aren't
                             stale. The index is written BESIDE the --settings file, and
                             roots come from that file's "driveIndexRoots" — a settings
                             file other than server/app_settings.json that omits them is
                             REFUSED (the roots would otherwise fall back to the real
                             /Volumes library). Off unless asked; the endpoint passes it.
  --target-free-gb=100       Final target free space for drives except balance-to
  --reserve-gb=20            Extra headroom required before pulling a group onto a
                             destination drive
  --no-balance               Skip final balancing pass to reach target-free-gb
  --only="On the Beach"      Only process groups whose base title contains this substring
  --limit-groups=50          Stop after processing N groups (testing)
  --log="mylog.tsv"          Custom log filename (TSV). Default location: with
                             --progress-file, "consolidate_last_run.tsv" beside the
                             progress file (overwritten each run); otherwise
                             "move_status_<timestamp>.tsv" in the current directory.

TXT;
    fwrite(STDERR, $u);
    exit(1);
}

if (isset($opts['help'])) {
    usageAndExit();
}

$DRY_RUN = isset($opts['dry-run']) || !isset($opts['execute']);

$PROGRESS_FILE = isset($opts['progress-file']) && is_string($opts['progress-file']) && trim($opts['progress-file']) !== ''
    ? trim($opts['progress-file'])
    : null;

// -----------------------
// Config: settings file (key "consolidate") overlaid on the original
// script's hardcoded defaults, then CLI flags on top of that.
// -----------------------
$settingsPath = isset($opts['settings']) && is_string($opts['settings']) && trim($opts['settings']) !== ''
    ? trim($opts['settings'])
    : __DIR__ . '/../server/app_settings.json';

$cfg = moviedb_consolidate_settings($settingsPath);

$RECURSIVE  = isset($opts['recursive']) ? true : (bool) $cfg['recursive'];
$DO_BALANCE = isset($opts['no-balance']) ? false : (bool) $cfg['balance'];
$REBUILD_INDEX = isset($opts['rebuild-index']);

$targetFreeGb = isset($opts['target-free-gb']) ? (int) $opts['target-free-gb'] : (int) $cfg['targetFreeGb'];
$reserveGb    = isset($opts['reserve-gb']) ? (int) $opts['reserve-gb'] : (int) $cfg['reserveGb'];

$onlyFilter = isset($opts['only']) && is_string($opts['only']) && trim($opts['only']) !== ''
    ? mb_strtolower(trim((string) $opts['only']))
    : null;

$limitGroups = isset($opts['limit-groups']) ? max(1, (int) $opts['limit-groups']) : null;

// driveKey == the configured path (unambiguous in logs)
$DRIVES = [];
foreach ($cfg['drives'] as $p) {
    $p = rtrim((string) $p, '/');
    if ($p !== '') {
        $DRIVES[$p] = $p;
    }
}
$balanceToDriveKey = rtrim((string) $cfg['balanceTo'], '/');

if (isset($opts['log']) && is_string($opts['log']) && trim($opts['log']) !== '') {
    $logFile = trim((string) $opts['log']);
} elseif ($PROGRESS_FILE !== null) {
    // Endpoint mode: one well-known log beside the progress file, overwritten
    // per run (the endpoint's logTail reads it).
    $logFile = dirname($PROGRESS_FILE) . '/consolidate_last_run.tsv';
} else {
    $logFile = 'move_status_' . date('Ymd_His') . '.tsv';
}

// -----------------------
// Single instance + pidfile. The lock must be taken BEFORE the log opens:
// the endpoint-mode log path is overwritten per run, and a losing second
// instance must not truncate the running one's log. Bare-CLI runs lock on
// the SAME file the endpoint uses, so a hand-run --execute and an endpoint
// run can never move files concurrently.
// -----------------------
$lockPath = ($PROGRESS_FILE !== null)
    ? $PROGRESS_FILE . '.lock'
    // Bare CLI shares the endpoint's lock so a hand-run and a web run can
    // never overlap. MOVIEDB_CONSOLIDATE_LOCK overrides for the test harness
    // (its bare-CLI cases must not contend with a real in-flight run).
    : (getenv('MOVIEDB_CONSOLIDATE_LOCK')
        ?: __DIR__ . '/../server/consolidate_progress.json.lock');
$lockFp = @fopen($lockPath, 'c');
if ($lockFp === false || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "ERROR: another consolidation run already holds the lock ($lockPath); exiting.\n");
    exit(3);
}
if ($PROGRESS_FILE !== null) {
    $pidPath = $PROGRESS_FILE . '.pid';
    @file_put_contents($pidPath, (string) getmypid());
    register_shutdown_function(static function () use ($pidPath): void {
        @unlink($pidPath);
    });
}

// -----------------------
// Validate config (before the log opens — see lock comment above)
// -----------------------
if ($DRIVES === []) {
    fwrite(STDERR, "ERROR: no drive directories configured.\n");
    exit(1);
}
if (!array_key_exists($balanceToDriveKey, $DRIVES)) {
    fwrite(STDERR, "ERROR: balance-to drive '$balanceToDriveKey' is not one of the configured drives.\n");
    exit(1);
}
// Unmounted-drives guard: refuse to run when any configured dir is missing.
foreach ($DRIVES as $k => $p) {
    if (!is_dir($p)) {
        fwrite(STDERR, "ERROR: Drive path not found or not a directory: $p\n");
        exit(1);
    }
}

// -----------------------
// Logger
// -----------------------
$logFp = fopen($logFile, 'wb');
if (!$logFp) {
    fwrite(STDERR, "ERROR: Cannot open log file for writing: $logFile\n");
    exit(1);
}

$header = ['ts', 'mode', 'group', 'action', 'src', 'dest', 'bytes', 'status', 'message'];
fwrite($logFp, implode("\t", $header) . "\n");

function logLine($fp, array $cols): void
{
    fwrite($fp, implode("\t", array_map(static function ($v) {
        $s = (string) $v;
        // Keep TSV sane
        return str_replace(["\t", "\r", "\n"], ['\\t', '\\r', '\\n'], $s);
    }, $cols)) . "\n");
}

function out(string $msg): void
{
    fwrite(STDOUT, $msg . "\n");
}

function err(string $msg): void
{
    fwrite(STDERR, $msg . "\n");
}

/** TSV logger closure in the shape server/consolidate_lib.php expects. */
function tsvLoggerFor(string $groupDisplay): callable
{
    return static function (string $action, string $src, string $dest, int $bytes, string $status, string $message) use ($groupDisplay): void {
        global $logFp, $modeStr;
        logLine($logFp, [date('c'), $modeStr, $groupDisplay, $action, $src, $dest, $bytes, $status, $message]);
    };
}

function bytesToHuman(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $i = 0;
    $v = (float) $bytes;
    while ($v >= 1024 && $i < count($units) - 1) {
        $v /= 1024;
        $i++;
    }
    return sprintf('%.2f %s', $v, $units[$i]);
}

function gbToBytes(int $gb): int
{
    return $gb * 1024 * 1024 * 1024;
}

function diskFree(string $path): int
{
    $v = @disk_free_space($path);
    return $v === false ? 0 : (int) $v;
}

// -----------------------
// Dry-run space simulation: planned moves adjust these per-drive deltas so
// every space DECISION in a dry run sees the same numbers execute would —
// without it, evacuation math consulted real (unchanged) free space and the
// plan diverged from what execute does.
// -----------------------
$SIM_DELTA = []; // driveRoot => +freed / -consumed bytes (dry-run only)

function effectiveFree(string $root): int
{
    global $SIM_DELTA, $DRY_RUN;
    return diskFree($root) + ($DRY_RUN ? (int) ($SIM_DELTA[$root] ?? 0) : 0);
}

function simAdjust(string $root, int $delta): void
{
    global $SIM_DELTA, $DRY_RUN;
    if ($DRY_RUN) {
        $SIM_DELTA[$root] = (int) ($SIM_DELTA[$root] ?? 0) + $delta;
    }
}

function fileSizeSafe(string $path): int
{
    return moviedb_consolidate_size($path);
}

function fileMtimeSafe(string $path): int
{
    return moviedb_consolidate_mtime($path);
}

// -----------------------
// Progress sidecar + run summary (endpoint mode)
// -----------------------
$RUN_STARTED = time();
/** null = not attempted (dry run, nothing changed, or --rebuild-index absent). */
$INDEX_REBUILT = null;
/** Set the moment ANY rename/mv actually lands on disk — the drive index's
 *  paths are stale from that point on. Counters are the wrong gate: they
 *  under-count (evacuation shelving) and a FAILED move can still leave a
 *  quarantined leftover behind. */
$DISK_TOUCHED = false;
$PROGRESS = [
    'phase'      => 'scan',
    'group'      => '',
    'groupsDone' => 0,
    'groupsTotal' => 0,
    'currentSrc' => '',
    'currentDest' => '',
];
$COUNTERS = [
    'moved'      => 0, // files moved between drives (MOVE/EVAC_MOVE; planned in dry-run)
    'duped'      => 0, // files shelved into duplicates/ (incl. SUSPECT_PARTIAL__ quarantines)
    'skipped'    => 0, // groups skipped for space (GROUP_SKIP with status SKIP)
    'renamed'    => 0, // unnumbered base files renamed to their canonical "# 01" name
    'failed'     => 0, // failed moves/shelves
    'movedBytes' => 0,
];

function progressWrite(?string $phase = null): void
{
    global $PROGRESS_FILE, $PROGRESS, $COUNTERS, $DRY_RUN, $DRIVES;
    if ($PROGRESS_FILE === null) {
        return;
    }
    if ($phase !== null) {
        $PROGRESS['phase'] = $phase;
    }
    $driveFree = [];
    foreach ($DRIVES as $p) {
        $driveFree[$p] = effectiveFree($p); // projected during dry-run
    }
    @file_put_contents($PROGRESS_FILE, json_encode([
        'at'          => time(),
        'phase'       => $PROGRESS['phase'],
        'group'       => $PROGRESS['group'],
        'groupsDone'  => $PROGRESS['groupsDone'],
        'groupsTotal' => $PROGRESS['groupsTotal'],
        'currentSrc'  => $PROGRESS['currentSrc'],
        'currentDest' => $PROGRESS['currentDest'],
        'moved'       => $COUNTERS['moved'],
        'duped'       => $COUNTERS['duped'],
        'failed'      => $COUNTERS['failed'],
        'skipped'     => $COUNTERS['skipped'],
        'renamed'     => $COUNTERS['renamed'],
        'movedBytes'  => $COUNTERS['movedBytes'],
        'dryRun'      => $DRY_RUN,
        'driveFree'   => $driveFree,
    ]));
}

function writeLastRun(int $exitCode): void
{
    global $PROGRESS_FILE, $COUNTERS, $DRY_RUN, $RUN_STARTED, $INDEX_REBUILT;
    if ($PROGRESS_FILE === null) {
        return;
    }
    @file_put_contents($PROGRESS_FILE . '.last', json_encode([
        'finishedAt' => date('c'),
        'durationSeconds' => max(0, time() - $RUN_STARTED),
        'indexRebuilt' => $INDEX_REBUILT,
        'dryRun'     => $DRY_RUN,
        'exitCode'   => $exitCode,
        'moved'      => $COUNTERS['moved'],
        'duped'      => $COUNTERS['duped'],
        'skipped'    => $COUNTERS['skipped'],
        'renamed'    => $COUNTERS['renamed'],
        'failed'     => $COUNTERS['failed'],
        'movedBytes' => $COUNTERS['movedBytes'],
    ]));
}

// -----------------------
// File scanning
// -----------------------
function scanDriveFiles(string $root, bool $recursive, array $extensions): array
{
    $files = [];

    $extSet = [];
    foreach ($extensions as $e) {
        $extSet[mb_strtolower($e)] = true;
    }

    if (!is_dir($root)) {
        return $files;
    }

    // An unreadable directory (the lost-Full-Disk-Access failure mode) must
    // abort the whole run: planning against a half-seen tree could shelve
    // files as "duplicates" of copies it failed to see.
    try {
        if ($recursive) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $fi) {
                /** @var SplFileInfo $fi */
                if (!$fi->isFile()) {
                    continue;
                }
                $path = $fi->getPathname();
                // Skip anything inside a duplicates folder
                if (preg_match('#/duplicates(/|$)#i', $path)) {
                    continue;
                }

                $ext = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if ($ext === '' || !isset($extSet[$ext])) {
                    continue;
                }

                $files[] = $path;
            }
        } else {
            $it = new DirectoryIterator($root);
            foreach ($it as $fi) {
                /** @var DirectoryIterator $fi */
                if ($fi->isDot() || !$fi->isFile()) {
                    continue;
                }
                $path = $fi->getPathname();
                if (preg_match('#/duplicates(/|$)#i', $path)) {
                    continue;
                }

                $ext = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if ($ext === '' || !isset($extSet[$ext])) {
                    continue;
                }

                $files[] = $path;
            }
        }
    } catch (UnexpectedValueException | RuntimeException $e) {
        fwrite(STDERR, "ERROR: cannot read '$root' or a directory inside it (Full Disk Access?): "
            . $e->getMessage() . "\n");
        exit(1);
    }

    return $files;
}

function parseBaseAndEpisode(string $nameNoExt): array
{
    $s = trim($nameNoExt);

    // 1) Episodic: "<base> # <digits> <variant tail>". The library's canonical
    // form is space-delimited (" # NN"), so require the spaces: a glued
    // "#3" mid-title ("Player #3 Returns") is NOT an episode marker — the old
    // loose match made such base-only movies bucket with a real "# NN" series
    // and get shelved as duplicates of it.
    if (preg_match('/^(.*?)(?:\s+#\s+(\d+))(?=\s|$)(.*)$/u', $s, $m)) {
        $base = trim($m[1]);
        $ep   = (int) $m[2];
        $tail = trim($m[3]); // could be "", or "(Studio) - Scene_2 ...", "- CD1", etc

        // If the tail begins with one or more "(...)" tags, treat them as part
        // of the base. Example: "Some Movie # 01 (Paramount) - Scene_1"
        //   => base: "Some Movie (Paramount)", tail: "- Scene_1"
        if (preg_match('/^\s*-?\s*((?:\([^)]*\)\s*)+)(.*)$/u', $tail, $mm)) {
            $parenBlock = trim($mm[1]);           // "(Paramount)" or "(Paramount) (Something)"
            $rest       = trim($mm[2]);           // "- Scene_1" or ""

            // Normalize whitespace inside the parenthetical block
            $parenBlock = preg_replace('/\s+/', ' ', $parenBlock) ?? $parenBlock;

            $base = trim($base . ' ' . $parenBlock);
            $tail = $rest;
        }

        // A tail that isn't a variant ("- Scene_1", "- CD2") or empty means
        // the " # N " was part of the movie's NAME ("Apartment # 3 Secrets"):
        // treat as base-only rather than bucketing it with a real series.
        if ($tail !== '' && $tail[0] !== '-') {
            return [$s, null, ''];
        }

        return [$base !== '' ? $base : $s, $ep, $tail];
    }

    // 2) Multi-part discs/parts without episode: "<base> - CD1 ..." / "Disc 2" / "Part 1" / "DVD 3"
    if (preg_match('/^(.*?)(?:\s*-\s*)((?:cd|disc|dvd|part)\s*\d+)\b(.*)$/iu', $s, $m)) {
        $base = trim($m[1]);
        $var  = trim('- ' . preg_replace('/\s+/', ' ', $m[2]) . $m[3]);
        return [$base !== '' ? $base : $s, null, $var];
    }

    // 3) Scene markers without episode: "<base> - Scene_2 ..." (and allow extra stuff after)
    if (preg_match('/^(.*?)(?:\s*-\s*)((?:scene)[_\s]*\d+)\b(.*)$/iu', $s, $m)) {
        $base = trim($m[1]);
        $var  = trim('- ' . preg_replace('/\s+/', ' ', $m[2]) . $m[3]);
        return [$base !== '' ? $base : $s, null, $var];
    }

    return [$s, null, ''];
}

/**
 * Sort paths in "episode / scene / disc" order based on filename parsing.
 *
 * Order:
 *   - Episode number (# 01, # 02...) ascending; no-episode treated as 0
 *   - Variant type order: (none) -> scene -> cd/disc/dvd/part -> other
 *   - Variant number ascending (scene_2 before scene_10)
 *   - Then variant text / basename / full path as tie-breakers
 */
function sortPathsByEpisodeScene(array &$paths): void
{
    usort($paths, static function (string $a, string $b): int {
        $ka = episodeSceneSortKeyForPath($a);
        $kb = episodeSceneSortKeyForPath($b);

        // Compare tuple elements in order
        $n = min(count($ka), count($kb));
        for ($i = 0; $i < $n; $i++) {
            if ($ka[$i] === $kb[$i]) {
                continue;
            }

            // ints vs strings: use appropriate compare
            if (is_int($ka[$i]) && is_int($kb[$i])) {
                return $ka[$i] <=> $kb[$i];
            }

            return strcmp((string) $ka[$i], (string) $kb[$i]);
        }

        // same key (rare) — stable fallback
        return strnatcasecmp($a, $b);
    });
}

function episodeSceneSortKeyForPath(string $path): array
{
    $nameNoExt = pathinfo($path, PATHINFO_FILENAME);

    [$base, $ep, $variant] = parseBaseAndEpisode($nameNoExt);

    $epNum = $ep ?? 0;

    $variant = trim((string) $variant);
    $variantLower = mb_strtolower($variant);

    // kindOrder: 0 none, 1 scene, 2 cd/disc/dvd/part, 3 other
    $kindOrder = 3;
    $kindNum = 0;

    if ($variantLower === '') {
        $kindOrder = 0;
    } elseif (preg_match('/scene[_\s]*(\d+)/i', $variant, $m)) {
        $kindOrder = 1;
        $kindNum = (int) $m[1];
    } elseif (preg_match('/\b(cd|disc|dvd|part)\s*(\d+)/i', $variant, $m)) {
        $kindOrder = 2;
        $kindNum = (int) $m[2];
    }

    $baseNameLower = mb_strtolower(basename($path));

    return [
        $epNum,           // 0,1,2,3...
        $kindOrder,       // 0..3
        $kindNum,         // 0..N
        $variantLower,    // tie-break
        $baseNameLower,   // tie-break
        $path,            // final stable tie-break
    ];
}

/**
 * The canonical "# 01" name for an unnumbered base file: "Base.mp4" →
 * "Base # 01.mp4". A trailing parenthetical block keeps the number before
 * it, matching the library's form: "Base (Studio).mp4" → "Base # 01 (Studio).mp4".
 */
function rename01Name(string $path): string
{
    $pi = pathinfo($path);
    $stem = (string) $pi['filename'];
    $ext = (isset($pi['extension']) && $pi['extension'] !== '') ? '.' . $pi['extension'] : '';
    if (preg_match('/^(.*?)(\s*(?:\([^)]*\)\s*)+)$/u', $stem, $m)) {
        return trim($m[1]) . ' # 01 ' . trim($m[2]) . $ext;
    }
    return $stem . ' # 01' . $ext;
}

function normKey(string $s): string
{
    $s = mb_strtolower(trim($s));
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    return $s;
}

function normVariantKey(string $s): string
{
    $s = mb_strtolower(trim($s));
    $s = preg_replace('/^[\s\-]+/', '', $s) ?? $s;     // strip leading "- "
    $s = preg_replace('/\s+/', '_', $s) ?? $s;         // spaces -> _
    $s = preg_replace('/_+/', '_', $s) ?? $s;          // collapse __
    $s = preg_replace('/[^a-z0-9_]+/i', '', $s) ?? $s; // keep it key-safe
    return trim($s, '_');
}

function sortFileRecordsNaturally(array &$files): void
{
    usort($files, static function (array $a, array $b): int {
        $aa = basename($a['path']);
        $bb = basename($b['path']);

        $c = strnatcasecmp($aa, $bb); // natural: 2 < 10
        if ($c !== 0) {
            return $c;
        }

        return strnatcasecmp($a['path'], $b['path']); // tie-breaker
    });
}

// -----------------------
// Move helpers
// -----------------------
function ensureDir(string $dir): bool
{
    if (is_dir($dir)) {
        return true;
    }
    return @mkdir($dir, 0775, true);
}

function shellMoveToPath(string $src, string $destPath, array &$cmdOut, int &$exitCode): void
{
    moviedb_consolidate_shell_move($src, $destPath, $cmdOut, $exitCode);
}

// -----------------------
// Duplicates shelving (delegates to consolidate_lib; keeps run counters)
// -----------------------
function moveToDuplicates(
    $logFp,
    bool $dryRun,
    string $modeStr,
    string $groupDisplay,
    string $srcPath,
    string $driveRoot,
    string $reason
): bool {
    global $COUNTERS, $DISK_TOUCHED;

    $r = moviedb_consolidate_shelve_file($srcPath, $driveRoot, $reason, $dryRun, tsvLoggerFor($groupDisplay));
    if ($r['to'] !== null) {
        out("  DUPLICATES: $srcPath  ->  {$r['to']}  ($reason)");
    }
    if ($r['ok']) {
        $COUNTERS['duped']++;
        if (!$dryRun) {
            $DISK_TOUCHED = true;
        }
    } else {
        $COUNTERS['failed']++;
        err("  FAIL moving to duplicates: $srcPath");
    }
    progressWrite();
    return $r['ok'];
}

function pickBestCopy(array $bucket): array
{
    usort($bucket, static function ($a, $b) {
        if ($a['size'] !== $b['size']) {
            return $b['size'] <=> $a['size'];
        }
        if ($a['mtime'] !== $b['mtime']) {
            return $b['mtime'] <=> $a['mtime'];
        }
        return strcmp($a['path'], $b['path']);
    });
    return $bucket[0];
}

// -----------------------
// Evacuation helpers
// -----------------------
function evacuateLargestGroupsFromDrive(
    $logFp,
    bool $dryRun,
    string $modeStr,
    string $fromDriveKey,
    string $fromRoot,
    string $toDriveKey,
    string $toRoot,
    int $needBytes,
    int $reserveBytes,
    array &$processedGroups,
    string $progressPhase,
    bool $skipFreshlyMoved = false
): int {
    global $COUNTERS, $PROGRESS, $DISK_TOUCHED;

    // Move already-processed groups off a drive, largest first, until we free at least needBytes.
    $candidates = [];
    foreach ($processedGroups as $baseKey => $pg) {
        if ($pg['homeDriveKey'] !== $fromDriveKey) {
            continue;
        }
        // Balance pass: don't ping-pong a group that was consolidated ONTO
        // this drive earlier in this very run straight back off it.
        if ($skipFreshlyMoved && !empty($pg['movedHere'])) {
            continue;
        }
        $candidates[$baseKey] = $pg;
    }

    uasort($candidates, static function ($a, $b) {
        return $b['bytes'] <=> $a['bytes'];
    });

    $freed = 0;

    foreach ($candidates as $baseKey => $pg) {
        if ($freed >= $needBytes) {
            break;
        }

        // The sink must actually have room for this group (plus reserve) —
        // without this check an out-of-space sink turns every evac move into
        // an ENOSPC failure/quarantine storm.
        if (effectiveFree($toRoot) < (int) $pg['bytes'] + $reserveBytes) {
            logLine($logFp, [date('c'), $modeStr, $pg['display'], 'EVAC_SKIP', $fromRoot, $toRoot,
                (int) $pg['bytes'], 'SKIP', 'sink lacks space for this group + reserve']);
            continue;
        }

        $groupDisplay = $pg['display'];
        out("EVACUATE group: [$groupDisplay] from $fromDriveKey -> $toDriveKey (to free space)");

        $movedPathsForThisGroup = [];

        $srcPaths = $pg['primaryFiles'];
        sortPathsByEpisodeScene($srcPaths);

        foreach ($srcPaths as $srcPath) {
            $bytes = fileSizeSafe($srcPath);

            // If executing and source is missing, it may already have been moved earlier.
            if (!$dryRun && !file_exists($srcPath)) {
                $expectedDest = rtrim($toRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($srcPath);
                if (file_exists($expectedDest)) {
                    logLine($logFp, [date('c'), $modeStr, $groupDisplay, 'EVAC_ALREADY', $srcPath, $expectedDest, fileSizeSafe($expectedDest), 'OK', 'source missing; destination exists']);
                    $movedPathsForThisGroup[] = $expectedDest;
                    continue;
                }

                logLine($logFp, [date('c'), $modeStr, $groupDisplay, 'EVAC_SKIP', $srcPath, $toRoot, $bytes, 'SKIP', 'source missing']);
                continue;
            }

            // In dry-run, don't create folders—just log what would happen, and continue planning.
            if ($dryRun) {
                if (!is_dir($toRoot)) {
                    logLine($logFp, [date('c'), $modeStr, $groupDisplay, 'EVAC_MKDIR', $srcPath, $toRoot, $bytes, 'DRYRUN', 'would ensure dest root']);
                }
            } else {
                if (!ensureDir($toRoot)) {
                    logLine($logFp, [date('c'), $modeStr, $groupDisplay, 'EVAC_MKDIR', $srcPath, $toRoot, $bytes, 'FAIL', 'Could not ensure dest root']);
                    continue;
                }
            }

            $destPath = rtrim($toRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($srcPath);

            out("  EVAC MOVE: $srcPath  ->  $destPath");
            // Heartbeat BEFORE the (possibly minutes-long) mv so the sidecar
            // shows the in-flight file while /bin/mv blocks
            $PROGRESS['currentSrc'] = $srcPath;
            $PROGRESS['currentDest'] = $destPath;
            progressWrite($progressPhase);

            // Same hardened collision rule as primary moves: an equal copy at
            // the sink shelves the source as a duplicate; a mismatched dest
            // is quarantined as SUSPECT_PARTIAL__* and the source moves in.
            // (The old path invented __EVAC_-suffixed names instead, which
            // bypassed the rule and could stack partials beside good copies.)
            $res = moviedb_consolidate_move_into_place(
                $srcPath,
                $destPath,
                $fromRoot,
                $toRoot,
                $dryRun,
                tsvLoggerFor($groupDisplay)
            );

            if ($res['quarantined'] !== null) {
                out("  QUARANTINE: $destPath  ->  {$res['quarantined']}");
                $COUNTERS['duped']++;
                $DISK_TOUCHED = $DISK_TOUCHED || !$dryRun;
            }
            if ($res['outcome'] !== 'failed' && !$dryRun) {
                $DISK_TOUCHED = true;
            }

            switch ($res['outcome']) {
                case 'moved':
                    logLine($logFp, [date('c'), $modeStr, $groupDisplay, 'EVAC_MOVE', $srcPath, $destPath, $bytes, $dryRun ? 'DRYRUN' : 'OK', '']);
                    $movedPathsForThisGroup[] = $destPath;
                    $freed += $bytes;
                    $COUNTERS['moved']++;
                    $COUNTERS['movedBytes'] += $bytes;
                    simAdjust($fromRoot, $bytes);
                    simAdjust($toRoot, -$bytes);
                    break;
                case 'duped':
                    // Source shelved on its own drive (equal copy already at
                    // the sink): the drive doesn't get its space back here.
                    // It DID move on disk, so it counts like any other shelve.
                    $COUNTERS['duped']++;
                    logLine($logFp, [date('c'), $modeStr, $groupDisplay, 'EVAC_DUP', $srcPath, $destPath, $bytes, $dryRun ? 'DRYRUN' : 'OK', 'equal copy already at sink; source shelved']);
                    break;
                default:
                    logLine($logFp, [date('c'), $modeStr, $groupDisplay, 'EVAC_MOVE', $srcPath, $destPath, $bytes, 'FAIL', 'see preceding log lines']);
                    $COUNTERS['failed']++;
                    err("  FAIL EVAC: $srcPath");
                    break;
            }
            progressWrite($progressPhase);
        }

        // Conservative: if any old path still exists under fromRoot, consider it still on the source drive.
        $stillOnFrom = false;
        if (!$dryRun) {
            foreach ($pg['primaryFiles'] as $old) {
                if (str_starts_with($old, $fromRoot) && file_exists($old)) {
                    $stillOnFrom = true;
                    break;
                }
            }
        }

        sortPathsByEpisodeScene($movedPathsForThisGroup);

        $allMoved = (count($movedPathsForThisGroup) === count($pg['primaryFiles']));

        if ($allMoved && ($dryRun || !$stillOnFrom)) {
            $processedGroups[$baseKey]['homeDriveKey'] = $toDriveKey;
            $processedGroups[$baseKey]['primaryFiles'] = $movedPathsForThisGroup;
        }
    }

    return $freed;
}

function ensureDestinationHasSpace(
    $logFp,
    bool $dryRun,
    string $modeStr,
    string $destDriveKey,
    string $destRoot,
    string $balanceToDriveKey,
    string $balanceToRoot,
    int $requiredBytes,
    int $reserveBytes,
    array &$processedGroups
): int {
    $free = effectiveFree($destRoot);
    if ($free >= $requiredBytes) {
        return 0;
    }

    $need = $requiredBytes - $free;
    out("Destination [$destDriveKey] needs to free at least " . bytesToHuman($need) . " (free=" . bytesToHuman($free) . ")");

    $freed = evacuateLargestGroupsFromDrive(
        $logFp,
        $dryRun,
        $modeStr,
        $destDriveKey,
        $destRoot,
        $balanceToDriveKey,
        $balanceToRoot,
        $need,
        $reserveBytes,
        $processedGroups,
        'groups'
    );

    // SAFETY re-check (sim deltas already reflect dry-run evacuations)
    $free2 = effectiveFree($destRoot);
    out("After evacuation: freed=" . bytesToHuman($freed) . " free=" . bytesToHuman($free2));

    return $freed;
}

// -----------------------
// Build groups
// -----------------------
out("Mode: " . ($DRY_RUN ? "DRY-RUN" : "EXECUTE"));
out("Recursive: " . ($RECURSIVE ? "yes" : "no"));
out("Target free (non-$balanceToDriveKey): {$targetFreeGb} GB");
out("Reserve headroom: {$reserveGb} GB");
out("Settings file: " . (is_file($settingsPath) ? $settingsPath : "(none — defaults)"));
out("Log file: $logFile");

$modeStr = $DRY_RUN ? 'DRYRUN' : 'EXEC';

progressWrite('scan');

$allGroups = []; // baseKey => ['display'=>..., 'files'=>[...]]

foreach ($DRIVES as $driveKey => $root) {
    out("Scanning: [$driveKey]");
    $paths = scanDriveFiles($root, $RECURSIVE, $DEFAULT_EXTENSIONS);

    foreach ($paths as $path) {
        $nameNoExt = pathinfo($path, PATHINFO_FILENAME);
        [$base, $ep, $variant] = parseBaseAndEpisode($nameNoExt);

        $baseKey = normKey($base);
        if (!isset($allGroups[$baseKey])) {
            $allGroups[$baseKey] = [
                'display' => $base,
                'files' => [],
            ];
        }

        $allGroups[$baseKey]['files'][] = [
            'path' => $path,
            'driveKey' => $driveKey,
            'base' => $base,
            'baseKey' => $baseKey,
            'ep' => $ep, // int|null
            'variant' => $variant,
            'size' => fileSizeSafe($path),
            'mtime' => fileMtimeSafe($path),
        ];
    }
    progressWrite('scan');
}

if ($onlyFilter !== null) {
    $allGroups = array_filter($allGroups, static function ($g) use ($onlyFilter) {
        return mb_strpos(mb_strtolower($g['display']), $onlyFilter) !== false;
    });
}

if (count($allGroups) === 0) {
    out("No groups found (check paths/extensions/filter).");
    progressWrite('done');
    writeLastRun(0);
    fclose($logFp);
    exit(0);
}

// Compute group total size for ordering
foreach ($allGroups as $k => &$g) {
    $sum = 0;
    foreach ($g['files'] as $f) {
        $sum += $f['size'];
    }
    $g['totalBytes'] = $sum;
}
unset($g);

// Sort: alphabetical by group display (natural sort: 2 < 10)
uasort($allGroups, static function ($a, $b) {
    $aa = mb_strtolower((string) $a['display']);
    $bb = mb_strtolower((string) $b['display']);

    $c = strnatcasecmp($aa, $bb);
    if ($c !== 0) {
        return $c;
    }

    // tie-breaker: bigger groups first if names identical
    return ($b['totalBytes'] ?? 0) <=> ($a['totalBytes'] ?? 0);
});

$PROGRESS['groupsTotal'] = count($allGroups);
progressWrite('groups');

// Track where processed groups "live" (after consolidation)
$processedGroups = []; // baseKey => ['homeDriveKey'=>..., 'primaryFiles'=>[paths], 'bytes'=>int]

// -----------------------
// Process groups
// -----------------------
$groupCount = 0;

foreach ($allGroups as $baseKey => $g) {
    $groupCount++;
    if ($limitGroups !== null && $groupCount > $limitGroups) {
        out("Stopping due to --limit-groups=$limitGroups");
        break;
    }

    $groupDisplay = $g['display'];
    $files = $g['files'];

    $PROGRESS['group'] = $groupDisplay;
    $PROGRESS['currentSrc'] = '';
    $PROGRESS['currentDest'] = '';

    out("");
    out("============================================================");
    out("GROUP: $groupDisplay  (" . count($files) . " files, total " . bytesToHuman((int) $g['totalBytes']) . ")");
    out("============================================================");

    // Build episode buckets and detect duplicates
    $hasNumberedEpisodes = false;
    foreach ($files as $f) {
        if ($f['ep'] !== null) {
            $hasNumberedEpisodes = true;
            break;
        }
    }

    $byItem = [];
    foreach ($files as $f) {
        $variantKey = normVariantKey((string) ($f['variant'] ?? ''));

        if ($f['ep'] === null && $variantKey === '' && $hasNumberedEpisodes) {
            // App convention (mirrors handleNumberedTitle /
            // renameSessionFilesAddMissing01): once numbered volumes of a
            // base exist, the unnumbered file IS volume # 01 — it joins the
            // EP 1 bucket (deduping against a real "# 01" if one exists) and
            // gets renamed to the canonical name, never shelved for merely
            // lacking its number.
            $f['ep'] = 1;
            $f['renameTo01'] = true;
            $itemKey = 'EP00001';
        } elseif ($f['ep'] === null) {
            $itemKey = ($variantKey === '') ? 'BASEONLY' : ('VAR__' . $variantKey);
        } else {
            $itemKey = sprintf('EP%05d', (int) $f['ep']) . ($variantKey === '' ? '' : ('__' . $variantKey));
        }

        $byItem[$itemKey][] = $f;
    }

    $primaryFiles = [];     // files we will consolidate
    $duplicateMoves = [];   // ['file' => <fileRecord>, 'reason' => string]

    foreach ($byItem as $itemKey => $bucket) {

        // BASEONLY bucket: only reachable when the group has NO numbered
        // episodes (unnumbered files otherwise join EP00001 above)
        if ($itemKey === 'BASEONLY') {
            // If multiple BASEONLY copies exist, keep best one and dupe the rest
            if (count($bucket) === 1) {
                $primaryFiles[] = $bucket[0];
            } else {
                $keep = pickBestCopy($bucket);
                $primaryFiles[] = $keep;

                foreach ($bucket as $f) {
                    if ($f['path'] === $keep['path']) {
                        continue;
                    }
                    $duplicateMoves[] = [
                        'file' => $f,
                        'reason' => "duplicate base-only title (kept best copy)",
                    ];
                }
            }

            continue;
        }

        // Any non-BASEONLY item (EPxxxxx__variant or VAR__variant):
        if (count($bucket) === 1) {
            $primaryFiles[] = $bucket[0];
        } else {
            $keep = pickBestCopy($bucket);
            $primaryFiles[] = $keep;

            foreach ($bucket as $f) {
                if ($f['path'] === $keep['path']) {
                    continue;
                }
                $duplicateMoves[] = [
                    'file' => $f,
                    'reason' => "duplicate item ($itemKey) (kept best copy)",
                ];
            }
        }
    }
    // Make ordering deterministic
    sortFileRecordsNaturally($primaryFiles);

    // Sort duplicate moves deterministically
    usort($duplicateMoves, static function (array $a, array $b): int {
        $aa = basename($a['file']['path']);
        $bb = basename($b['file']['path']);

        $c = strnatcasecmp($aa, $bb);
        if ($c !== 0) {
            return $c;
        }

        return strnatcasecmp($a['file']['path'], $b['file']['path']);
    });

    // Move duplicates aside first (within their current drive)
    $plannedShelvedPaths = []; // dry-run: shelved files still on disk — see rename01 below
    foreach ($duplicateMoves as $d) {
        $f = $d['file'];
        $src = $f['path'];
        if (!file_exists($src)) {
            continue;
        }

        moveToDuplicates(
            $logFp,
            $DRY_RUN,
            $modeStr,
            $groupDisplay,
            $src,
            $DRIVES[$f['driveKey']],
            $d['reason']
        );
        $plannedShelvedPaths[$src] = true;
    }

    if (count($primaryFiles) === 0) {
        out("No primary files remain after duplicate rules. Skipping consolidation.");
        logLine($logFp, [date('c'), $modeStr, $groupDisplay, 'GROUP_SKIP', '', '', (int) $g['totalBytes'], 'OK', 'no primary files; moved items to duplicates']);
        $PROGRESS['groupsDone']++;
        progressWrite('groups');
        continue;
    }

    // Determine destination drive with fallback:
    // 1) Rank drives by (primary count already on drive, then bytes already on drive, then free space)
    // 2) Try each drive in order
    //    - First pass: only accept drives that already have enough free (no evacuation)
    //    - Second pass: allow evacuation (except when dest is the balance-to drive)

    $counts = [];
    $bytesByDrive = [];
    foreach ($primaryFiles as $f) {
        $dk = $f['driveKey'];
        $counts[$dk] = ($counts[$dk] ?? 0) + 1;
        $bytesByDrive[$dk] = ($bytesByDrive[$dk] ?? 0) + (int) $f['size'];
    }

    $candidates = [];
    foreach ($DRIVES as $dk => $root) {
        $candidates[] = [
            'driveKey' => $dk,
            'root' => $root,
            'cnt' => (int) ($counts[$dk] ?? 0),
            'bytes' => (int) ($bytesByDrive[$dk] ?? 0),
            'free' => effectiveFree($root),
        ];
    }

    // Sort best-first
    usort($candidates, static function (array $a, array $b): int {
        // cnt desc, bytes desc, free desc
        if ($a['cnt'] !== $b['cnt']) {
            return $b['cnt'] <=> $a['cnt'];
        }
        if ($a['bytes'] !== $b['bytes']) {
            return $b['bytes'] <=> $a['bytes'];
        }
        return $b['free'] <=> $a['free'];
    });

    $chosen = null;

    // Two-phase try: first without evacuation, then with evacuation
    foreach ([false, true] as $allowEvac) {
        foreach ($candidates as $cand) {
            $tryDriveKey = $cand['driveKey'];
            $tryRoot = $cand['root'];

            // Compute incoming bytes if this drive is the destination
            $incomingBytesTry = 0;
            foreach ($primaryFiles as $pf) {
                if ($pf['driveKey'] !== $tryDriveKey) {
                    $incomingBytesTry += (int) $pf['size'];
                }
            }

            $requiredTry = $incomingBytesTry + gbToBytes($reserveGb);
            $freeNow = effectiveFree($tryRoot);

            // If it fits without evacuation, accept immediately
            if ($freeNow >= $requiredTry) {
                $chosen = [
                    'driveKey' => $tryDriveKey,
                    'root' => $tryRoot,
                    'incomingBytes' => $incomingBytesTry,
                    'required' => $requiredTry,
                    'freedBytes' => 0,
                ];
                break 2;
            }

            // Second phase: attempt evacuation (but never evacuate "to itself")
            if ($allowEvac && $tryDriveKey !== $balanceToDriveKey) {
                out("Destination candidate [$tryDriveKey] short by " . bytesToHuman($requiredTry - $freeNow) . " (free=" . bytesToHuman($freeNow) . "). Attempting evacuation...");
                $freedBytes = ensureDestinationHasSpace(
                    $logFp,
                    $DRY_RUN,
                    $modeStr,
                    $tryDriveKey,
                    $tryRoot,
                    $balanceToDriveKey,
                    $DRIVES[$balanceToDriveKey],
                    $requiredTry,
                    gbToBytes($reserveGb),
                    $processedGroups
                );

                $freeAfter = effectiveFree($tryRoot);

                if ($freeAfter >= $requiredTry) {
                    $chosen = [
                        'driveKey' => $tryDriveKey,
                        'root' => $tryRoot,
                        'incomingBytes' => $incomingBytesTry,
                        'required' => $requiredTry,
                        'freedBytes' => $freedBytes,
                    ];
                    break 2;
                }

                out("  Still insufficient on [$tryDriveKey] after evacuation (required=" . bytesToHuman($requiredTry) . ", free=" . bytesToHuman($freeAfter) . "). Trying next candidate...");
            }
        }
    }

    if ($chosen === null) {
        $primaryBytes = 0;
        foreach ($primaryFiles as $pf) {
            $primaryBytes += (int) $pf['size'];
        }

        $msg = 'no destination drive could satisfy space requirements'
            . '; incoming+reserve needed varies by destination'
            . '; reserve=' . $reserveGb . ' GB';

        err("  SKIP GROUP: $msg");
        logLine($logFp, [date('c'), $modeStr, $groupDisplay, 'GROUP_SKIP', '', '', $primaryBytes, 'SKIP', $msg]);
        $COUNTERS['skipped']++;
        $PROGRESS['groupsDone']++;
        progressWrite('groups');
        continue;
    }

    // Final chosen destination
    $destDriveKey = $chosen['driveKey'];
    $destRoot     = $chosen['root'];
    $incomingBytes = $chosen['incomingBytes'];
    $required      = $chosen['required'];

    out("Destination drive (with fallback): $destDriveKey");

    // Compute list of files that must be moved into destination (primary files not already there)
    $toMove = [];
    foreach ($primaryFiles as $f) {
        if ($f['driveKey'] !== $destDriveKey) {
            $toMove[] = $f;
        }
    }

    out("Primary files: " . count($primaryFiles) . " | Need to move into destination: " . count($toMove) . " (" . bytesToHuman($incomingBytes) . ")");

    // SAFETY: if we STILL don't have enough space (should be rare), skip this group
    // Sim deltas already account for dry-run evacuations/moves
    $effectiveFree = effectiveFree($destRoot);

    if ($effectiveFree < $required) {
        $primaryBytes = 0;
        foreach ($primaryFiles as $pf) {
            $primaryBytes += (int) $pf['size'];
        }

        $msg = 'insufficient space after destination selection/evacuation'
            . '; required=' . bytesToHuman($required)
            . '; free=' . bytesToHuman($effectiveFree)
            . '; incoming=' . bytesToHuman($incomingBytes)
            . '; reserve=' . $reserveGb . ' GB'
            . '; dest=' . $destDriveKey;

        err("  SKIP GROUP: $msg");
        logLine($logFp, [date('c'), $modeStr, $groupDisplay, 'GROUP_SKIP', '', $destRoot, $primaryBytes, 'SKIP', $msg]);
        $COUNTERS['skipped']++;
        $PROGRESS['groupsDone']++;
        progressWrite('groups');
        continue;
    }

    $primaryFilesSorted = $primaryFiles;
    usort($primaryFilesSorted, static function ($a, $b) {
        $c = strnatcasecmp(basename($a['path']), basename($b['path']));
        if ($c !== 0) {
            return $c;
        }
        return strnatcasecmp($a['path'], $b['path']); // tie-breaker
    });

    // Move each needed file into destination root
    $movedPrimaryPaths = [];
    $movedIntoDest = false; // did this group PHYSICALLY move onto the dest?
    foreach ($primaryFilesSorted as $f) {
        $src = $f['path'];
        $bytes = $f['size'];
        $rename01 = !empty($f['renameTo01']);
        $targetBase = $rename01 ? rename01Name($src) : basename($src);

        // If it already lives on destination, keep it (renaming in place when
        // the app convention says the unnumbered file IS "# 01")
        if ($f['driveKey'] === $destDriveKey) {
            if (!$rename01) {
                $movedPrimaryPaths[] = $src;
                continue;
            }
            $newPath = dirname($src) . DIRECTORY_SEPARATOR . $targetBase;
            out("  RENAME_01: $src  ->  $newPath");
            logLine($logFp, [date('c'), $modeStr, $groupDisplay, 'RENAME_01', $src, $newPath, $bytes, $DRY_RUN ? 'DRYRUN' : 'OK', 'unnumbered base becomes # 01']);
            if ($DRY_RUN && isset($plannedShelvedPaths[$newPath])) {
                // The losing "# 01" copy was just PLANNED into duplicates/ but
                // is still physically at the target — without this the dry run
                // would misreport the rename as a suspect-partial quarantine.
                logLine($logFp, [date('c'), $modeStr, $groupDisplay, 'MOVE', $src, $newPath, $bytes, 'DRYRUN', 'target freed by planned duplicate shelving']);
                $res01 = ['outcome' => 'moved', 'finalPath' => $newPath, 'bytes' => $bytes, 'quarantined' => null];
            } else {
                $res01 = moviedb_consolidate_move_into_place(
                    $src,
                    $newPath,
                    $DRIVES[$f['driveKey']],
                    $DRIVES[$f['driveKey']],
                    $DRY_RUN,
                    tsvLoggerFor($groupDisplay)
                );
            }
            if (!$DRY_RUN && $res01['outcome'] !== 'failed') {
                $DISK_TOUCHED = true;
            }
            if ($res01['outcome'] === 'moved') {
                $COUNTERS['renamed']++;
                $movedPrimaryPaths[] = $newPath;
            } elseif ($res01['outcome'] === 'duped') {
                // An identical "# 01" copy appeared at the target: source shelved
                $COUNTERS['duped']++;
            } else {
                $COUNTERS['failed']++;
                err("  FAIL: could not rename $src -> $newPath (see log)");
            }
            progressWrite();
            continue;
        }

        if (!file_exists($src)) {
            logLine($logFp, [date('c'), $modeStr, $groupDisplay, 'MOVE', $src, $destRoot, $bytes, 'FAIL', 'source missing']);
            err("  FAIL: source missing $src");
            $COUNTERS['failed']++;
            progressWrite();
            continue;
        }

        if (!ensureDir($destRoot)) {
            logLine($logFp, [date('c'), $modeStr, $groupDisplay, 'MKDIR_DEST', $src, $destRoot, $bytes, 'FAIL', 'could not ensure dest root']);
            err("  FAIL: could not ensure dest root: $destRoot");
            $COUNTERS['failed']++;
            progressWrite();
            continue;
        }

        $destPath = rtrim($destRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $targetBase;

        if ($rename01) {
            logLine($logFp, [date('c'), $modeStr, $groupDisplay, 'RENAME_01', $src, $destPath, $bytes, $DRY_RUN ? 'DRYRUN' : 'OK', 'unnumbered base becomes # 01 (moving)']);
        }
        out("  MOVE: $src  ->  $destPath");
        // Heartbeat BEFORE the (possibly minutes-long) mv so the sidecar
        // shows the in-flight file while /bin/mv blocks
        $PROGRESS['currentSrc'] = $src;
        $PROGRESS['currentDest'] = $destPath;
        progressWrite();

        // Hardened move: a dest collision is only a duplicate when size+mtime
        // match; otherwise the dest is quarantined as SUSPECT_PARTIAL__* and
        // the source moved in. See moviedb_consolidate_move_into_place().
        if ($DRY_RUN && $rename01 && isset($plannedShelvedPaths[$destPath])) {
            // Same dry-run edge as the in-place rename: the shelved "# 01"
            // loser still occupies the target on disk.
            logLine($logFp, [date('c'), $modeStr, $groupDisplay, 'MOVE', $src, $destPath, $bytes, 'DRYRUN', 'target freed by planned duplicate shelving']);
            $res = ['outcome' => 'moved', 'finalPath' => $destPath, 'bytes' => $bytes, 'quarantined' => null];
        } else {
            $res = moviedb_consolidate_move_into_place(
                $src,
                $destPath,
                $DRIVES[$f['driveKey']],
                $destRoot,
                $DRY_RUN,
                tsvLoggerFor($groupDisplay)
            );
        }

        if ($res['quarantined'] !== null) {
            out("  QUARANTINE: $destPath  ->  {$res['quarantined']}");
            $COUNTERS['duped']++;
            $DISK_TOUCHED = $DISK_TOUCHED || !$DRY_RUN;
        }
        if ($res['outcome'] !== 'failed' && !$DRY_RUN) {
            $DISK_TOUCHED = true;
        }

        switch ($res['outcome']) {
            case 'moved':
                $COUNTERS['moved']++;
                $COUNTERS['movedBytes'] += $res['bytes'];
                if ($rename01) {
                    $COUNTERS['renamed']++;
                }
                $movedPrimaryPaths[] = $destPath;
                $movedIntoDest = true;
                simAdjust($DRIVES[$f['driveKey']], $res['bytes']);
                simAdjust($destRoot, -$res['bytes']);
                break;
            case 'duped':
                out("  DUPLICATES: $src (destination already has this file)");
                $COUNTERS['duped']++;
                break;
            default:
                $COUNTERS['failed']++;
                err("  FAIL: could not move $src -> $destPath (see log)");
                break;
        }
        progressWrite();
    }

    // Record processed group "home"
    $groupBytes = 0;

    sortPathsByEpisodeScene($movedPrimaryPaths);

    foreach ($primaryFiles as $f) {
        $groupBytes += $f['size'];
    }

    $processedGroups[$baseKey] = [
        'display' => $groupDisplay,
        'homeDriveKey' => $destDriveKey,
        'primaryFiles' => $movedPrimaryPaths,
        'bytes' => $groupBytes,
        // Balance-pass ping-pong guard: a group moved onto this drive during
        // this run must not be evacuated straight back off it
        'movedHere' => $movedIntoDest,
    ];

    logLine($logFp, [date('c'), $modeStr, $groupDisplay, 'GROUP_DONE', '', $destRoot, $groupBytes, 'OK', '']);
    $PROGRESS['groupsDone']++;
    progressWrite('groups');
}

// -----------------------
// Final balancing pass
// -----------------------
if ($DO_BALANCE) {
    out("");
    out("============================================================");
    out("FINAL BALANCING PASS (target free per drive: {$targetFreeGb} GB)");
    out("============================================================");

    $PROGRESS['group'] = '';
    $PROGRESS['currentSrc'] = '';
    $PROGRESS['currentDest'] = '';
    progressWrite('balance');

    $targetBytes = gbToBytes($targetFreeGb);

    foreach ($DRIVES as $driveKey => $root) {
        if ($driveKey === $balanceToDriveKey) {
            continue;
        }

        $free = effectiveFree($root);
        out("Drive [$driveKey] free: " . bytesToHuman($free));

        if ($free >= $targetBytes) {
            // Log the no-op too: a balance pass that moves nothing otherwise
            // leaves NO trace in the TSV, so the log can't answer "did
            // balancing do anything?" — it only ever showed the moves.
            logLine($logFp, [date('c'), $modeStr, '', 'BALANCE_CHECK', $root, $DRIVES[$balanceToDriveKey],
                $free, 'SKIP', 'free ' . bytesToHuman($free) . ' >= target ' . $targetFreeGb
                    . ' GB — nothing to evacuate']);
            continue;
        }

        $need = $targetBytes - $free;
        out("  Needs to free: " . bytesToHuman($need));
        logLine($logFp, [date('c'), $modeStr, '', 'BALANCE_CHECK', $root, $DRIVES[$balanceToDriveKey],
            $free, 'OK', 'free ' . bytesToHuman($free) . ' < target ' . $targetFreeGb
                . ' GB — need to free ' . bytesToHuman($need)]);

        // Evacuate whole processed groups from this drive to balanceTo until free >= target
        $freed = evacuateLargestGroupsFromDrive(
            $logFp,
            $DRY_RUN,
            $modeStr,
            $driveKey,
            $root,
            $balanceToDriveKey,
            $DRIVES[$balanceToDriveKey],
            $need,
            gbToBytes($reserveGb),
            $processedGroups,
            'balance',
            true // never ping-pong groups consolidated onto this drive this run
        );

        $free2 = effectiveFree($root);
        out("  Freed: " . bytesToHuman($freed) . " | Now free: " . bytesToHuman($free2));
        logLine($logFp, [date('c'), $modeStr, '', 'BALANCE_DONE', $root, $DRIVES[$balanceToDriveKey],
            $freed, $free2 >= $targetBytes ? 'OK' : 'SKIP',
            'freed ' . bytesToHuman($freed) . '; now free ' . bytesToHuman($free2)
                . ' (target ' . $targetFreeGb . ' GB)']);
    }
} else {
    // Balancing switched off => the log stays clean of balance rows entirely
    // (Sean's call); stdout still notes it for a CLI run.
    out("Skipping final balancing pass (--no-balance).");
}

// -----------------------
// Drive index refresh. Consolidation moves files between drives, so every
// path the index holds for a moved file is now wrong — rebuild before
// reporting done (the run lock is still held, so nothing else can start
// mid-rebuild). Opt-in via --rebuild-index; skipped when nothing actually
// changed on disk. The index is written BESIDE this run's --settings file, so
// a fixture run can never clobber server/drive_index.json — and because the
// roots would otherwise silently fall back to the real /Volumes library, a
// non-production settings file must declare driveIndexRoots or we refuse.
// -----------------------
if ($REBUILD_INDEX && !$DRY_RUN && $DISK_TOUCHED) {
    $PROGRESS['group'] = '';
    $PROGRESS['currentSrc'] = '';
    $PROGRESS['currentDest'] = '';
    progressWrite('index');

    $indexPath = rtrim(dirname($settingsPath), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'drive_index.json';

    // moviedb_drive_index_roots() silently falls back to the hardcoded REAL
    // /Volumes roots when the settings file has no driveIndexRoots — so the
    // index path alone does NOT keep a fixture run off the real drives. Only
    // trust that fallback for the production settings file; any other
    // settings file must name its roots or the rebuild is refused.
    $rawSettings = is_file($settingsPath)
        ? json_decode((string) @file_get_contents($settingsPath), true)
        : null;
    $rootsAreExplicit = is_array($rawSettings)
        && isset($rawSettings['driveIndexRoots'])
        && is_array($rawSettings['driveIndexRoots'])
        && $rawSettings['driveIndexRoots'] !== [];
    $prodSettings = realpath(__DIR__ . '/../server/app_settings.json');
    $thisSettings = realpath($settingsPath);
    $isProdSettings = $prodSettings !== false && $thisSettings !== false && $prodSettings === $thisSettings;

    if (!$rootsAreExplicit && !$isProdSettings) {
        $why = 'refused: ' . $settingsPath . ' declares no driveIndexRoots, so the roots'
            . ' would fall back to the real /Volumes library';
        err("  Drive index rebuild $why");
        logLine($logFp, [date('c'), $modeStr, '', 'INDEX_REBUILD', '', $indexPath, 0, 'SKIP', $why]);
    } else {
        $indexRoots = moviedb_drive_index_roots($settingsPath);
        out("");
        out("Rebuilding the drive index ($indexPath)...");

        $index = moviedb_build_drive_index($indexRoots, $indexPath);
        if (is_array($index) && !isset($index['error'])) {
            $INDEX_REBUILT = true;
            out("  Drive index rebuilt: {$index['fileCount']} file(s).");
            logLine($logFp, [date('c'), $modeStr, '', 'INDEX_REBUILD', '', $indexPath,
                (int) $index['fileCount'], 'OK', 'drive index rebuilt after consolidation']);
        } else {
            // A failed rebuild leaves the PREVIOUS index untouched (the lib's
            // own guarantee) — the run still succeeded, so this is reported,
            // not fatal.
            $INDEX_REBUILT = false;
            $why = is_array($index) ? (string) ($index['error'] ?? 'unknown error') : 'could not write the index';
            err("  Drive index rebuild FAILED: $why");
            logLine($logFp, [date('c'), $modeStr, '', 'INDEX_REBUILD', '', $indexPath, 0, 'FAIL', $why]);
        }
    }
} elseif ($REBUILD_INDEX && !$DRY_RUN) {
    out("");
    out("Drive index rebuild skipped: nothing moved, renamed, or shelved.");
}

fclose($logFp);

$PROGRESS['group'] = '';
$PROGRESS['currentSrc'] = '';
$PROGRESS['currentDest'] = '';
progressWrite('done');
writeLastRun(0);

out("");
out("Done. Log written to: $logFile");
out("NOTE: Duplicates were placed under each drive's duplicates/ directory");
out("      (SUSPECT_PARTIAL__* entries are quarantined destination leftovers).");
out("IMPORTANT: Review duplicates before deleting anything.");
