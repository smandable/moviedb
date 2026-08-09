<?php

/**
 * Drive index: a JSON catalog (server/drive_index.json, gitignored) of every
 * video file under the configured library roots, so the app can search the
 * drives instantly without walking them per query.
 *
 * Built by scripts/build_drive_index.php (CLI) or the driveIndex.php endpoint
 * ("rebuild"). Each entry's "base" is the filename without extension run
 * through stripTitleVariantSuffixes(), so all scene/CD/bonus files of one
 * movie share a base and group together in search results.
 *
 * All functions take explicit args for testability and default to the prod
 * locations when called without them.
 */

require_once __DIR__ . '/normalize_helpers.php';

const MOVIEDB_DRIVE_INDEX_FILE = __DIR__ . '/drive_index.json';
const MOVIEDB_DRIVE_INDEX_SETTINGS_FILE = __DIR__ . '/app_settings.json';
const MOVIEDB_DRIVE_INDEX_MAX_DEPTH = 4;
const MOVIEDB_DRIVE_INDEX_SEARCH_CAP = 50;
const MOVIEDB_DRIVE_INDEX_VIDEO_EXTS = [
    'mp4', 'm4v', 'mkv', 'avi', 'wmv', 'mov', 'mpg', 'mpeg', 'ts', 'flv', 'webm',
];

if (!function_exists('moviedb_drive_index_default_roots')) {
    /** The library roots indexed when settings don't override them.
     *  SP, Misc, and Download are deliberately excluded. */
    function moviedb_drive_index_default_roots(): array
    {
        return [
            '/Volumes/Recorded 1/recorded',
            '/Volumes/Recorded 2/recorded',
            '/Volumes/Recorded 3/recorded',
            '/Volumes/Recorded 4/recorded',
            '/Volumes/Etc/Extra',
        ];
    }
}

if (!function_exists('moviedb_drive_index_roots')) {
    /** Roots to index: app_settings.json "driveIndexRoots" when it's a
     *  non-empty array of non-empty strings, else the defaults. */
    function moviedb_drive_index_roots(?string $settingsPath = null): array
    {
        $settingsPath = $settingsPath ?? MOVIEDB_DRIVE_INDEX_SETTINGS_FILE;
        if (is_file($settingsPath)) {
            $raw = @file_get_contents($settingsPath);
            $settings = $raw === false ? null : json_decode($raw, true);
            $roots = is_array($settings) ? ($settings['driveIndexRoots'] ?? null) : null;
            if (is_array($roots) && $roots !== []) {
                $clean = [];
                foreach ($roots as $root) {
                    if (!is_string($root) || trim($root) === '') {
                        $clean = null;
                        break;
                    }
                    $clean[] = rtrim(trim($root), '/');
                }
                if ($clean !== null) {
                    return array_values($clean);
                }
            }
        }
        return moviedb_drive_index_default_roots();
    }
}

if (!function_exists('moviedb_drive_index_scan')) {
    /**
     * Walk each existing root (depth cap MOVIEDB_DRIVE_INDEX_MAX_DEPTH levels
     * below the root; dot-entries and .Trashes/.moviedb_trash dirs skipped)
     * and collect video-file entries, sorted by base then file (natural,
     * case-insensitive). Returns ['entries' => [...], 'missingRoots' => [...]].
     * Shared by the real build and the CLI --dry-run.
     *
     * $onProgress, when given, is called with
     * ['root' => ..., 'rootsDone' => n, 'rootsTotal' => n, 'entries' => n]
     * at the start of each root and every ~1000 collected entries.
     */
    function moviedb_drive_index_scan(array $roots, ?callable $onProgress = null): array
    {
        $entries = [];
        $missingRoots = [];
        $unreadableRoots = [];

        // Drop duplicate and nested roots: a root inside another root would
        // index (and count) the same files twice, and make trash bookkeeping
        // remove only one of the twin entries.
        $kept = [];
        foreach ($roots as $root) {
            if (!is_string($root) || $root === '') {
                continue;
            }
            $norm = rtrim($root, '/');
            $real = realpath($norm) ?: $norm;
            $nested = false;
            foreach ($kept as $k) {
                if ($real === $k['real'] || strpos($real . '/', $k['real'] . '/') === 0) {
                    $nested = true;
                    break;
                }
            }
            if (!$nested) {
                $kept[] = ['root' => $norm, 'real' => $real];
            }
        }

        $progressState = [
            'root' => '',
            'rootsDone' => 0,
            'rootsTotal' => count($kept),
            'entries' => 0,
        ];
        $notify = function () use (&$progressState, $onProgress): void {
            if ($onProgress !== null) {
                $onProgress($progressState);
            }
        };

        $walk = function (string $dir, int $depth) use (&$walk, &$entries, &$progressState, $notify): void {
            $items = @scandir($dir);
            if ($items === false) {
                return;
            }
            foreach ($items as $item) {
                // "." / ".." plus every dot-entry (covers .Trashes and
                // .moviedb_trash too, but those are also excluded by name in
                // case a trash dir ever surfaces without its leading dot).
                if ($item === '' || $item[0] === '.') {
                    continue;
                }
                $path = $dir . '/' . $item;
                // Symlinks are never followed or indexed: an aliased path
                // would defeat the same-volume assumption trash relies on.
                if (is_link($path)) {
                    continue;
                }
                if (is_dir($path)) {
                    if (
                        $item !== '.Trashes'
                        && $item !== '.moviedb_trash'
                        // The consolidation shelf: files in duplicates/ are
                        // set-aside copies awaiting review — indexing them
                        // would misleadingly answer "you have this on drive X"
                        && strcasecmp($item, 'duplicates') !== 0
                        && $depth < MOVIEDB_DRIVE_INDEX_MAX_DEPTH
                    ) {
                        $walk($path, $depth + 1);
                    }
                    continue;
                }
                $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                if (!in_array($ext, MOVIEDB_DRIVE_INDEX_VIDEO_EXTS, true)) {
                    continue;
                }
                $entries[] = [
                    'base'  => stripTitleVariantSuffixes(pathinfo($item, PATHINFO_FILENAME)),
                    'file'  => $item,
                    'dir'   => $dir,
                    'size'  => (int) @filesize($path),
                    'mtime' => (int) @filemtime($path),
                ];
                $progressState['entries'] = count($entries);
                if (count($entries) % 1000 === 0) {
                    $notify();
                }
            }
        };

        foreach ($kept as $k) {
            $root = $k['root'];
            if (!is_dir($root)) {
                $missingRoots[] = $root;
                $progressState['rootsDone']++;
                continue;
            }
            // A root that exists but can't be listed (the lost-Full-Disk-
            // Access failure mode) must abort the build upstream — otherwise
            // a rebuild would overwrite a good index with a near-empty one.
            if (@scandir($root) === false) {
                $unreadableRoots[] = $root;
                $progressState['rootsDone']++;
                continue;
            }
            $progressState['root'] = $root;
            $notify();
            $walk($root, 0);
            $progressState['rootsDone']++;
            $notify();
        }

        usort($entries, function (array $a, array $b): int {
            return strnatcasecmp($a['base'], $b['base'])
                ?: strnatcasecmp($a['file'], $b['file']);
        });

        return [
            'entries' => $entries,
            'missingRoots' => $missingRoots,
            'unreadableRoots' => $unreadableRoots,
        ];
    }
}

if (!function_exists('moviedb_write_drive_index')) {
    /** Write-then-rename so a failed write can't truncate the index. The tmp
     *  name carries the pid so concurrent writers can't tear each other's
     *  half-written file (writers are also flock-serialized; this is belt). */
    function moviedb_write_drive_index(array $index, string $indexPath): bool
    {
        $tmp = $indexPath . '.tmp.' . getmypid();
        $json = json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || @file_put_contents($tmp, $json) === false) {
            return false;
        }
        if (!@rename($tmp, $indexPath)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }
}

if (!function_exists('moviedb_drive_index_lock')) {
    /**
     * Serialize every index writer (build, trash) on one advisory lock so a
     * trash can't load-modify-rewrite across a rebuild's write (lost update)
     * and two rebuilds can't interleave. Blocks until acquired — this is a
     * single-user tool; a rebuild holding it for a few seconds is fine.
     * Returns the lock handle, or null when the lock file can't be opened.
     */
    function moviedb_drive_index_lock(string $indexPath)
    {
        $fh = @fopen($indexPath . '.lock', 'c');
        if ($fh === false || !flock($fh, LOCK_EX)) {
            if ($fh !== false) {
                fclose($fh);
            }
            return null;
        }
        return $fh;
    }
}

if (!function_exists('moviedb_drive_index_unlock')) {
    function moviedb_drive_index_unlock($fh): void
    {
        if (is_resource($fh)) {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}

if (!function_exists('moviedb_validate_drive_root')) {
    /**
     * Guard for user-supplied index roots (Settings). Returns an error string
     * or null when acceptable. Beyond the ALLOWED_BASE_PATH check the caller
     * already does: the root may not BE the allowed base itself (a root of
     * "/Volumes" would sweep every mounted volume — including Macintosh HD's
     * "/Volumes/Macintosh HD" self-reference resolving to "/"), and its
     * realpath may not resolve to "/".
     */
    function moviedb_validate_drive_root(string $root): ?string
    {
        $root = rtrim(trim($root), '/');
        if ($root === '' || $root[0] !== '/') {
            return 'Root must be an absolute path';
        }
        if (preg_match('/[\x00-\x1F]/', $root)) {
            return 'Root contains control characters';
        }
        $base = rtrim((string) (function_exists('moviedb_allowed_base_path')
            ? moviedb_allowed_base_path()
            : (getenv('ALLOWED_BASE_PATH') ?: '/Volumes')), '/');
        $realRoot = realpath($root);
        $realBase = $base === '' ? '/' : (realpath($base) ?: $base);
        if ($root === $base || ($realRoot !== false && $realRoot === $realBase)) {
            return 'Root must be a directory inside the allowed base, not the base itself';
        }
        if ($realRoot === '/') {
            return 'Root resolves to the filesystem root';
        }
        return null;
    }
}

if (!function_exists('moviedb_build_drive_index')) {
    /**
     * Scan the roots and write the index atomically, serialized against every
     * other index writer. Returns the index array; ['error' => ...] when a
     * root exists but can't be listed (lost Full Disk Access — refusing to
     * write protects the previous good index); null when the write failed.
     */
    function moviedb_build_drive_index(?array $roots = null, ?string $indexPath = null)
    {
        $roots = $roots ?? moviedb_drive_index_roots();
        $indexPath = $indexPath ?? MOVIEDB_DRIVE_INDEX_FILE;

        // Live progress for the Settings page: the build holds the writer
        // lock, so a lock-free sidecar file is the reporting channel. Removed
        // in finally — its absence means "no rebuild running".
        $progressPath = $indexPath . '.progress';

        $lock = moviedb_drive_index_lock($indexPath);
        try {
            $scan = moviedb_drive_index_scan($roots, function (array $p) use ($progressPath): void {
                @file_put_contents($progressPath, json_encode($p + ['at' => time()]));
            });
            if ($scan['unreadableRoots'] !== []) {
                return [
                    'error' => 'Root(s) exist but cannot be read (Full Disk Access?): '
                        . implode(', ', $scan['unreadableRoots']),
                    'unreadableRoots' => $scan['unreadableRoots'],
                ];
            }

            // MERGE rebuild: the drives are usually unmounted and get mounted
            // only for a work session, so a rebuild must never forget an
            // offline drive — entries under a configured-but-missing root are
            // carried forward from the previous index instead of dropped.
            $entries = $scan['entries'];
            if ($scan['missingRoots'] !== []) {
                $old = moviedb_load_drive_index($indexPath);
                if ($old !== null) {
                    $carried = [];
                    foreach ($old['entries'] as $entry) {
                        if (!is_array($entry)) {
                            continue;
                        }
                        $dir = ($entry['dir'] ?? '') . '/';
                        foreach ($scan['missingRoots'] as $missing) {
                            if (strpos($dir, rtrim($missing, '/') . '/') === 0) {
                                $carried[] = $entry;
                                break;
                            }
                        }
                    }
                    if ($carried) {
                        $entries = array_merge($entries, $carried);
                        usort($entries, function (array $a, array $b): int {
                            return strnatcasecmp($a['base'], $b['base'])
                                ?: strnatcasecmp($a['file'], $b['file']);
                        });
                    }
                }
            }

            $index = [
                'builtAt'      => date('c'),
                'roots'        => array_values($roots),
                'missingRoots' => $scan['missingRoots'],
                'fileCount'    => count($entries),
                'entries'      => $entries,
            ];
            if (!moviedb_write_drive_index($index, $indexPath)) {
                return null;
            }
            return $index;
        } finally {
            @unlink($progressPath);
            moviedb_drive_index_unlock($lock);
        }
    }
}

if (!function_exists('moviedb_drive_index_progress')) {
    /**
     * The in-flight rebuild's progress, or ['active' => false]. Reads the
     * sidecar WITHOUT taking the index lock (the rebuild holds it for its
     * whole duration). A stale file (crashed build) reads as inactive.
     */
    function moviedb_drive_index_progress(?string $indexPath = null): array
    {
        $indexPath = $indexPath ?? MOVIEDB_DRIVE_INDEX_FILE;
        $raw = @file_get_contents($indexPath . '.progress');
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data) || (time() - (int) ($data['at'] ?? 0)) > 120) {
            return ['active' => false];
        }
        return [
            'active'     => true,
            'root'       => (string) ($data['root'] ?? ''),
            'rootsDone'  => (int) ($data['rootsDone'] ?? 0),
            'rootsTotal' => (int) ($data['rootsTotal'] ?? 0),
            'entries'    => (int) ($data['entries'] ?? 0),
        ];
    }
}

if (!function_exists('moviedb_load_drive_index')) {
    function moviedb_load_drive_index(?string $indexPath = null): ?array
    {
        $indexPath = $indexPath ?? MOVIEDB_DRIVE_INDEX_FILE;
        if (!is_file($indexPath)) {
            return null;
        }
        $raw = @file_get_contents($indexPath);
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data) || !isset($data['entries']) || !is_array($data['entries'])) {
            return null;
        }
        return $data;
    }
}

if (!function_exists('moviedb_search_drive_index')) {
    /**
     * Case-insensitive substring search against base AND file. Hits are
     * grouped by lower(base); groups sorted alphabetically and capped at
     * MOVIEDB_DRIVE_INDEX_SEARCH_CAP, with totalGroups/totalFiles counted
     * BEFORE the cap so the UI can say "showing 50 of N".
     *
     * Returns ['groups' => [...], 'totalGroups' => N, 'totalFiles' => N] or
     * ['error' => '...'] when the query is unusable / no index exists.
     */
    function moviedb_search_drive_index(string $q, ?array $index = null): array
    {
        $q = trim($q);
        if ($q === '' || mb_strlen($q) > 120) {
            return ['error' => 'Query must be 1-120 characters'];
        }
        $index = $index ?? moviedb_load_drive_index();
        if ($index === null) {
            return ['error' => 'No drive index has been built yet'];
        }

        $needle = mb_strtolower($q);
        $groups = [];
        foreach ($index['entries'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $base = (string) ($entry['base'] ?? '');
            $file = (string) ($entry['file'] ?? '');
            $dir  = (string) ($entry['dir'] ?? '');
            if (
                mb_strpos(mb_strtolower($base), $needle) === false
                && mb_strpos(mb_strtolower($file), $needle) === false
            ) {
                continue;
            }
            $key = mb_strtolower($base);
            if (!isset($groups[$key])) {
                $groups[$key] = ['base' => $base, 'files' => []];
            }
            $groups[$key]['files'][] = [
                'path'  => $dir . '/' . $file,
                'file'  => $file,
                'dir'   => $dir,
                'size'  => (int) ($entry['size'] ?? 0),
                'mtime' => (int) ($entry['mtime'] ?? 0),
            ];
        }

        $groups = array_values($groups);
        usort($groups, fn(array $a, array $b) => strnatcasecmp($a['base'], $b['base']));

        $totalGroups = count($groups);
        $totalFiles = 0;
        foreach ($groups as $group) {
            $totalFiles += count($group['files']);
        }

        return [
            'groups'      => array_slice($groups, 0, MOVIEDB_DRIVE_INDEX_SEARCH_CAP),
            'totalGroups' => $totalGroups,
            'totalFiles'  => $totalFiles,
        ];
    }
}

if (!function_exists('moviedb_drive_index_validate_path')) {
    /**
     * Shared trash/reveal validation. A client path is acted on only when it
     * is a string, resolves (realpath) inside one of the index's roots, AND
     * exactly equals dir."/".file of an index entry — the index is the
     * allow-list, so nothing the app didn't catalog can be touched.
     *
     * Returns ['ok' => true, 'root' => <root as configured>] or
     * ['ok' => false, 'error' => '...'].
     */
    function moviedb_drive_index_validate_path($path, ?array $index): array
    {
        if (!is_string($path) || $path === '') {
            return ['ok' => false, 'error' => 'Path must be a non-empty string'];
        }
        if ($index === null) {
            return ['ok' => false, 'error' => 'No drive index has been built yet'];
        }

        $real = realpath($path);
        if ($real === false) {
            return ['ok' => false, 'error' => 'File not found'];
        }
        $rootUsed = null;
        foreach ((array) ($index['roots'] ?? []) as $root) {
            if (!is_string($root)) {
                continue;
            }
            $realRoot = realpath($root);
            if ($realRoot !== false && strpos($real, rtrim($realRoot, '/') . '/') === 0) {
                $rootUsed = $root;
                break;
            }
        }
        if ($rootUsed === null) {
            return ['ok' => false, 'error' => 'Path is outside the indexed roots'];
        }

        foreach ($index['entries'] as $entry) {
            if (
                is_array($entry)
                && ($entry['dir'] ?? '') . '/' . ($entry['file'] ?? '') === $path
            ) {
                return ['ok' => true, 'root' => $rootUsed, 'real' => $real];
            }
        }
        return ['ok' => false, 'error' => 'Path is not in the drive index'];
    }
}

if (!function_exists('moviedb_drive_trash_dir')) {
    /**
     * Where a file at $path gets trashed to. On a real volume that's the
     * volume's own trash ("/Volumes/<name>/.Trashes/<uid>/") so the move is a
     * same-device rename; if that isn't creatable/writable, or the path isn't
     * under /Volumes (test fixtures), fall back to a ".moviedb_trash" dir —
     * beside the volume root, or beside the containing index root.
     */
    function moviedb_drive_trash_dir(string $path, string $rootUsed): ?string
    {
        if (preg_match('#^(/Volumes/[^/]+)/#', $path, $m)) {
            $uid = function_exists('posix_getuid') ? posix_getuid() : getmyuid();
            $trashes = $m[1] . '/.Trashes/' . $uid;
            if (!is_dir($trashes)) {
                @mkdir($trashes, 0700, true);
            }
            if (is_dir($trashes) && is_writable($trashes)) {
                return $trashes;
            }
            $fallback = $m[1] . '/.moviedb_trash';
        } else {
            $fallback = rtrim($rootUsed, '/') . '/.moviedb_trash';
        }
        if (!is_dir($fallback)) {
            @mkdir($fallback, 0700, true);
        }
        return (is_dir($fallback) && is_writable($fallback)) ? $fallback : null;
    }
}

if (!function_exists('moviedb_trash_drive_files')) {
    /**
     * Move (rename — NEVER unlink) each validated path into its volume's
     * trash. Successes are removed from the index, which is rewritten
     * atomically once at the end. Returns per-path results:
     * [{path, trashed: bool, to?: string, error?: string}, ...].
     */
    function moviedb_trash_drive_files(array $paths, ?string $indexPath = null): array
    {
        $indexPath = $indexPath ?? MOVIEDB_DRIVE_INDEX_FILE;

        // One writer at a time: without this, two concurrent trash requests
        // (or a trash during a rebuild) run load-modify-rewrite over the same
        // file and the last writer resurrects the other's removed entries.
        $lock = moviedb_drive_index_lock($indexPath);
        try {
            $index = moviedb_load_drive_index($indexPath);

            $results = [];
            $trashedPaths = [];
            foreach ($paths as $path) {
                $check = moviedb_drive_index_validate_path($path, $index);
                $pathStr = is_string($path) ? $path : '';
                if (!$check['ok']) {
                    $results[] = ['path' => $pathStr, 'trashed' => false, 'error' => $check['error']];
                    continue;
                }
                // Act on the resolved path: volume derivation and the rename
                // source must agree even if the client path rode a symlink.
                $source = $check['real'];

                $trashDir = moviedb_drive_trash_dir($source, $check['root']);
                if ($trashDir === null) {
                    $results[] = [
                        'path' => $path,
                        'trashed' => false,
                        'error' => 'Could not create a writable trash directory',
                    ];
                    continue;
                }

                // Claim the target name with O_CREAT|O_EXCL before renaming
                // onto it. A bare file_exists probe is check-then-act: two
                // same-basename trashes could both pass it and the second
                // rename would silently replace (unlink) the first trashed
                // file. Only ever rename onto our own claimed placeholder.
                $file = basename($source);
                $ext = pathinfo($file, PATHINFO_EXTENSION);
                $stem0 = pathinfo($file, PATHINFO_FILENAME);
                $suffix = $ext !== '' ? '.' . $ext : '';
                $target = null;
                $candidates = [$trashDir . '/' . $file];
                $stamp = $stem0 . '-' . date('Ymd-His');
                $candidates[] = $trashDir . '/' . $stamp . $suffix;
                for ($n = 2; $n <= 50; $n++) {
                    $candidates[] = $trashDir . '/' . $stamp . '-' . $n . $suffix;
                }
                foreach ($candidates as $candidate) {
                    $claim = @fopen($candidate, 'xb');
                    if ($claim !== false) {
                        fclose($claim);
                        $target = $candidate;
                        break;
                    }
                }
                if ($target === null) {
                    $results[] = ['path' => $path, 'trashed' => false, 'error' => 'Could not claim a trash target name'];
                    continue;
                }

                if (!@rename($source, $target)) {
                    @unlink($target); // our own zero-byte placeholder, never library data
                    $results[] = ['path' => $path, 'trashed' => false, 'error' => 'Move to trash failed'];
                    continue;
                }
                $trashedPaths[$path] = true;
                $results[] = ['path' => $path, 'trashed' => true, 'to' => $target];
            }

            $indexUpdated = true;
            if ($trashedPaths && $index !== null) {
                $index['entries'] = array_values(array_filter(
                    $index['entries'],
                    fn($e) => !is_array($e)
                        || !isset($trashedPaths[($e['dir'] ?? '') . '/' . ($e['file'] ?? '')])
                ));
                $index['fileCount'] = count($index['entries']);
                $indexUpdated = moviedb_write_drive_index($index, $indexPath);
            }

            return ['results' => $results, 'indexUpdated' => $indexUpdated];
        } finally {
            moviedb_drive_index_unlock($lock);
        }
    }
}

if (!function_exists('moviedb_reveal_drive_file')) {
    /**
     * Reveal a validated index file in Finder, in the console user's session
     * (same launchctl-asuser pattern as openExternalDriveSearch.php — the
     * httpd daemon isn't the GUI user). $dryRun runs the validation only, so
     * tests never poke the GUI. Returns ['success' => bool, 'error' => ...].
     */
    function moviedb_reveal_drive_file(string $path, ?string $indexPath = null, bool $dryRun = false): array
    {
        $indexPath = $indexPath ?? MOVIEDB_DRIVE_INDEX_FILE;
        $check = moviedb_drive_index_validate_path($path, moviedb_load_drive_index($indexPath));
        if (!$check['ok']) {
            return ['success' => false, 'invalid' => true, 'error' => $check['error']];
        }
        if ($dryRun) {
            return ['success' => true];
        }

        $uid = trim((string) @shell_exec('/usr/bin/stat -f%u /dev/console'));
        if (!preg_match('/^\d+$/', $uid)) {
            return ['success' => false, 'error' => 'Could not determine console user uid'];
        }
        $out = [];
        $code = 0;
        exec(
            '/bin/launchctl asuser ' . (int) $uid
                . ' /usr/bin/open -R ' . escapeshellarg($path) . ' 2>&1',
            $out,
            $code
        );
        if ($code !== 0) {
            return ['success' => false, 'error' => 'open -R failed: ' . implode("\n", $out)];
        }
        return ['success' => true];
    }
}
