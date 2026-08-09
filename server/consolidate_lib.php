<?php

/**
 * Consolidation: shared logic for scripts/consolidate_movies.php (the
 * episodic-file consolidator CLI) and server/consolidateMovies.php (the
 * endpoint that spawns and monitors it), plus the "consolidate" settings
 * validation used by appSettings.php.
 *
 * Everything takes explicit args for testability (the harness drives fixture
 * trees under the system temp dir). NOTHING in this file ever unlinks library
 * data: collisions and suspect partials are shelved into the drive's
 * duplicates/ directory — never deleted.
 *
 * The data-safety core lives here so the harness can red-proof it directly:
 * moviedb_consolidate_move_into_place() implements the hardened destination-
 * collision rule (size+mtime match => source is a true duplicate and is
 * shelved; anything else at the destination is a suspected partial from an
 * interrupted cross-volume mv and is quarantined aside as SUSPECT_PARTIAL__*
 * before the good source is moved in) and the failed-mv partial quarantine.
 */

require_once __DIR__ . '/path_guard.php';
require_once __DIR__ . '/drive_index_lib.php';

const MOVIEDB_CONSOLIDATE_SUSPECT_PREFIX = 'SUSPECT_PARTIAL__';
const MOVIEDB_CONSOLIDATE_MTIME_TOLERANCE = 2; // seconds; FAT/exFAT-style coarse stamps

if (!function_exists('moviedb_consolidate_defaults')) {
    /** Hardcoded defaults = the original standalone script's config. */
    function moviedb_consolidate_defaults(): array
    {
        return [
            'drives' => [
                '/Volumes/Recorded 1/recorded',
                '/Volumes/Recorded 2/recorded',
                '/Volumes/Recorded 3/recorded',
                '/Volumes/Recorded 4/recorded',
            ],
            'balanceTo'    => '/Volumes/Recorded 4/recorded',
            'targetFreeGb' => 100,
            'reserveGb'    => 20,
            // Off by default: balancing only fires when a drive drops below
            // targetFreeGb, and an unasked-for cross-drive evacuation is a
            // surprise on a run you started to consolidate.
            'balance'      => false,
            'recursive'    => false,
        ];
    }
}

if (!function_exists('moviedb_consolidate_settings')) {
    /**
     * Effective settings: app_settings.json key "consolidate" overlaid onto
     * the defaults. Tolerant reader — a missing file/key or an invalid-typed
     * value falls back to the default for that key (strict validation happens
     * at save time in appSettings.php, not here).
     */
    function moviedb_consolidate_settings(?string $settingsPath = null): array
    {
        $settingsPath = $settingsPath ?? __DIR__ . '/app_settings.json';
        $s = moviedb_consolidate_defaults();
        if (!is_file($settingsPath)) {
            return $s;
        }
        $raw = @file_get_contents($settingsPath);
        $all = $raw === false ? null : json_decode($raw, true);
        $c = is_array($all) ? ($all['consolidate'] ?? null) : null;
        if (!is_array($c)) {
            return $s;
        }
        if (isset($c['drives']) && is_array($c['drives']) && $c['drives'] !== []) {
            $drives = [];
            foreach ($c['drives'] as $d) {
                if (!is_string($d) || trim($d) === '') {
                    $drives = null;
                    break;
                }
                $drives[] = rtrim(trim($d), '/');
            }
            if ($drives !== null) {
                $s['drives'] = array_values($drives);
            }
        }
        if (isset($c['balanceTo']) && is_string($c['balanceTo']) && trim($c['balanceTo']) !== '') {
            $s['balanceTo'] = rtrim(trim($c['balanceTo']), '/');
        }
        foreach (['targetFreeGb', 'reserveGb'] as $k) {
            if (isset($c[$k]) && is_int($c[$k]) && $c[$k] >= 0) {
                $s[$k] = $c[$k];
            }
        }
        foreach (['balance', 'recursive'] as $k) {
            if (isset($c[$k]) && is_bool($c[$k])) {
                $s[$k] = $c[$k];
            }
        }
        return $s;
    }
}

if (!function_exists('moviedb_validate_consolidate_settings')) {
    /**
     * Strict validation for the "consolidate" settings object (save time and
     * pre-run). Exactly the six keys; 1..8 drive dirs each passing the same
     * allowed-base + drive-root validation as driveIndexRoots; balanceTo must
     * be one of drives; GB values ints 0..20000; balance/recursive booleans.
     *
     * Returns ['ok' => true, 'clean' => <normalized settings>] or
     * ['ok' => false, 'error' => '...'].
     */
    function moviedb_validate_consolidate_settings($c): array
    {
        if (!is_array($c)) {
            return ['ok' => false, 'error' => 'consolidate must be an object'];
        }
        $required = ['drives', 'balanceTo', 'targetFreeGb', 'reserveGb', 'balance', 'recursive'];
        foreach ($required as $k) {
            if (!array_key_exists($k, $c)) {
                return ['ok' => false, 'error' => "consolidate is missing key: $k"];
            }
        }
        $extra = array_diff(array_keys($c), $required);
        if ($extra !== []) {
            return ['ok' => false, 'error' => 'consolidate has unknown key(s): ' . implode(', ', $extra)];
        }

        if (!is_array($c['drives']) || count($c['drives']) < 1 || count($c['drives']) > 8) {
            return ['ok' => false, 'error' => 'consolidate.drives must be an array of 1 to 8 paths'];
        }
        $cleanDrives = [];
        foreach ($c['drives'] as $drive) {
            if (!is_string($drive) || trim($drive) === '') {
                return ['ok' => false, 'error' => 'consolidate.drives entries must be non-empty strings'];
            }
            $drive = rtrim(trim($drive), '/');
            if ($drive === '' || $drive[0] !== '/' || !moviedb_is_path_allowed($drive)) {
                return [
                    'ok' => false,
                    'error' => "consolidate.drives entry is not an allowed absolute path: $drive",
                ];
            }
            $rootError = moviedb_validate_drive_root($drive);
            if ($rootError !== null) {
                return ['ok' => false, 'error' => "consolidate.drives entry rejected ($drive): $rootError"];
            }
            $cleanDrives[] = $drive;
        }
        if (count(array_unique($cleanDrives)) !== count($cleanDrives)) {
            return ['ok' => false, 'error' => 'consolidate.drives contains duplicate paths'];
        }

        if (!is_string($c['balanceTo'])) {
            return ['ok' => false, 'error' => 'consolidate.balanceTo must be a string'];
        }
        $balanceTo = rtrim(trim($c['balanceTo']), '/');
        if (!in_array($balanceTo, $cleanDrives, true)) {
            return ['ok' => false, 'error' => 'consolidate.balanceTo must be one of consolidate.drives'];
        }

        foreach (['targetFreeGb', 'reserveGb'] as $k) {
            if (!is_int($c[$k]) || $c[$k] < 0 || $c[$k] > 20000) {
                return ['ok' => false, 'error' => "consolidate.$k must be an integer between 0 and 20000"];
            }
        }
        foreach (['balance', 'recursive'] as $k) {
            if (!is_bool($c[$k])) {
                return ['ok' => false, 'error' => "consolidate.$k must be a boolean"];
            }
        }

        return ['ok' => true, 'clean' => [
            'drives'       => $cleanDrives,
            'balanceTo'    => $balanceTo,
            'targetFreeGb' => $c['targetFreeGb'],
            'reserveGb'    => $c['reserveGb'],
            'balance'      => $c['balance'],
            'recursive'    => $c['recursive'],
        ]];
    }
}

if (!function_exists('moviedb_consolidate_pid_alive')) {
    function moviedb_consolidate_pid_alive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }
        $out = [];
        @exec('ps -p ' . (int) $pid . ' -o pid=', $out);
        return trim(implode('', $out)) !== '';
    }
}

if (!function_exists('moviedb_consolidate_tail_lines')) {
    /**
     * Last $lines lines of a file, read backwards in chunks so a huge log is
     * never loaded whole. Missing/unreadable file reads as [].
     */
    function moviedb_consolidate_tail_lines(string $path, int $lines): array
    {
        $lines = max(1, $lines);
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return [];
        }
        $stat = fstat($fh);
        $pos = (int) ($stat['size'] ?? 0);
        $buf = '';
        while ($pos > 0 && substr_count($buf, "\n") <= $lines) {
            $read = min(8192, $pos);
            $pos -= $read;
            fseek($fh, $pos);
            $buf = fread($fh, $read) . $buf;
        }
        fclose($fh);
        $buf = rtrim($buf, "\n");
        if ($buf === '') {
            return [];
        }
        return array_slice(explode("\n", $buf), -$lines);
    }
}

if (!function_exists('moviedb_consolidate_action_tail')) {
    /**
     * The last $lines ACTION rows of the TSV log — moves, dupes, quarantines,
     * skips, failures — with the bookkeeping (GROUP_DONE, mkdir) filtered out
     * server-side. An 8k-group run's raw tail is ALL bookkeeping, which made
     * the plain tail useless for "what did it actually do".
     */
    function moviedb_consolidate_action_tail(string $path, int $lines): array
    {
        $lines = max(1, $lines);
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return [];
        }
        $noise = [
            'GROUP_DONE' => true,
            'EVAC_MKDIR' => true,
            'MKDIR_DUP' => true,
            'MKDIR_DEST' => true,
        ];
        $ring = [];
        while (($line = fgets($fh)) !== false) {
            $line = rtrim($line, "\n");
            if ($line === '') {
                continue;
            }
            $cols = explode("\t", $line);
            $action = $cols[3] ?? '';
            if ($action === '' || $action === 'action' || isset($noise[$action])) {
                continue;
            }
            $ring[] = $line;
            if (count($ring) > $lines) {
                array_shift($ring);
            }
        }
        fclose($fh);
        return $ring;
    }
}

if (!function_exists('moviedb_consolidate_size')) {
    function moviedb_consolidate_size(string $path): int
    {
        $s = @filesize($path);
        return $s === false ? 0 : (int) $s;
    }
}

if (!function_exists('moviedb_consolidate_mtime')) {
    function moviedb_consolidate_mtime(string $path): int
    {
        $t = @filemtime($path);
        return $t === false ? 0 : (int) $t;
    }
}

if (!function_exists('moviedb_consolidate_duplicates_dir')) {
    function moviedb_consolidate_duplicates_dir(string $driveRoot): string
    {
        return rtrim($driveRoot, '/') . '/duplicates';
    }
}

if (!function_exists('moviedb_consolidate_shell_move')) {
    /** /bin/mv so cross-volume moves work (rename() can't cross devices). */
    function moviedb_consolidate_shell_move(string $src, string $destPath, array &$cmdOut, int &$exitCode): void
    {
        $cmd = '/bin/mv ' . escapeshellarg($src) . ' ' . escapeshellarg($destPath);
        $cmdOut = [];
        $exitCode = 0;
        @exec($cmd . ' 2>&1', $cmdOut, $exitCode);
    }
}

if (!function_exists('moviedb_consolidate_shelve_file')) {
    /**
     * Move a file into <driveRoot>/duplicates (same-drive rename, optionally
     * with a filename prefix such as SUSPECT_PARTIAL__). On a name collision
     * inside duplicates a timestamp (and then a counter) is appended — the
     * rename NEVER lands on an existing file, so nothing is ever replaced.
     *
     * $log is called as ($action, $src, $dest, $bytes, $status, $message).
     * Returns ['ok' => bool, 'to' => ?string] ('to' is the planned target in
     * dry-run mode).
     */
    function moviedb_consolidate_shelve_file(
        string $srcPath,
        string $driveRoot,
        string $reason,
        bool $dryRun,
        callable $log,
        string $prefix = ''
    ): array {
        $dupDir = moviedb_consolidate_duplicates_dir($driveRoot);

        // In dry-run, don't create anything — just log what would happen.
        if ($dryRun) {
            if (!is_dir($dupDir)) {
                $log('MKDIR_DUP', $srcPath, $dupDir, 0, 'DRYRUN', 'would create duplicates dir');
            }
        } elseif (!is_dir($dupDir) && !@mkdir($dupDir, 0775, true)) {
            $log('MKDIR_DUP', $srcPath, $dupDir, 0, 'FAIL', 'Could not create duplicates dir');
            return ['ok' => false, 'to' => null];
        }

        $bytes = moviedb_consolidate_size($srcPath);
        $destPath = $dupDir . '/' . $prefix . basename($srcPath);

        // Name collision inside duplicates: append a timestamp, then a
        // counter — never rename onto an existing file (rename replaces).
        if (file_exists($destPath)) {
            $pi = pathinfo($destPath);
            $ext = (isset($pi['extension']) && $pi['extension'] !== '') ? '.' . $pi['extension'] : '';
            $stamp = $pi['dirname'] . '/' . $pi['filename'] . '__' . date('Ymd_His');
            $destPath = $stamp . $ext;
            for ($n = 2; file_exists($destPath) && $n <= 50; $n++) {
                $destPath = $stamp . '_' . $n . $ext;
            }
            if (file_exists($destPath)) {
                $log('MOVE_DUP', $srcPath, $destPath, $bytes, 'FAIL', 'could not find a free duplicates name');
                return ['ok' => false, 'to' => null];
            }
        }

        if ($dryRun) {
            $log('MOVE_DUP', $srcPath, $destPath, $bytes, 'DRYRUN', $reason);
            return ['ok' => true, 'to' => $destPath];
        }

        if (@rename($srcPath, $destPath)) {
            $log('MOVE_DUP', $srcPath, $destPath, $bytes, 'OK', $reason);
            return ['ok' => true, 'to' => $destPath];
        }

        $log('MOVE_DUP', $srcPath, $destPath, $bytes, 'FAIL', 'rename() failed (' . $reason . ')');
        return ['ok' => false, 'to' => null];
    }
}

if (!function_exists('moviedb_consolidate_quarantine_partial')) {
    /**
     * After a FAILED mv: if a leftover file sits at the destination and is
     * smaller than the source, it's a partial from the interrupted copy —
     * quarantine it into the destination drive's duplicates dir with the
     * SUSPECT_PARTIAL__ prefix so a retry is never poisoned. A same-or-larger
     * leftover is NOT touched (not obviously a partial — leave it for a
     * human). Returns the quarantine target, or null when nothing was moved.
     */
    function moviedb_consolidate_quarantine_partial(
        string $destPath,
        int $expectedSrcSize,
        string $destDriveRoot,
        callable $log
    ): ?string {
        if (!file_exists($destPath)) {
            return null;
        }
        $destSize = moviedb_consolidate_size($destPath);
        if ($destSize >= $expectedSrcSize) {
            return null;
        }
        $r = moviedb_consolidate_shelve_file(
            $destPath,
            $destDriveRoot,
            "partial left by failed move ($destSize bytes vs expected $expectedSrcSize) quarantined",
            false,
            $log,
            MOVIEDB_CONSOLIDATE_SUSPECT_PREFIX
        );
        return $r['ok'] ? $r['to'] : null;
    }
}

if (!function_exists('moviedb_consolidate_move_into_place')) {
    /**
     * Move $src to $destPath with the hardened destination-collision rule:
     *
     *   - dest exists with the same size AND mtime (within 2s): the source is
     *     a true duplicate — shelve the SOURCE into its own drive's
     *     duplicates dir (the original script's behavior, now gated).
     *   - dest exists but size/mtime differ: the dest is suspect (likely a
     *     partial from an interrupted cross-volume mv) — quarantine the DEST
     *     into the destination drive's duplicates dir with the
     *     SUSPECT_PARTIAL__ prefix, then move the source in.
     *   - mv exits nonzero and left a dest smaller than the source: that
     *     partial is quarantined the same way (see
     *     moviedb_consolidate_quarantine_partial).
     *
     * Nothing is ever unlinked, and the mv never runs while a file still
     * exists at $destPath (a failed quarantine aborts the move).
     *
     * Returns ['outcome' => 'moved'|'duped'|'failed', 'finalPath' => ?string,
     * 'bytes' => int (source bytes when outcome is moved, else 0),
     * 'quarantined' => ?string (quarantine target when a dest was set aside)].
     */
    function moviedb_consolidate_move_into_place(
        string $src,
        string $destPath,
        string $srcDriveRoot,
        string $destDriveRoot,
        bool $dryRun,
        callable $log
    ): array {
        $bytes = moviedb_consolidate_size($src);
        $quarantined = null;

        if (file_exists($destPath)) {
            $destSize = moviedb_consolidate_size($destPath);
            $srcMtime = moviedb_consolidate_mtime($src);
            $destMtime = moviedb_consolidate_mtime($destPath);

            if ($destSize === $bytes && abs($destMtime - $srcMtime) <= MOVIEDB_CONSOLIDATE_MTIME_TOLERANCE) {
                // True duplicate: dest matches the source; shelve the source.
                $r = moviedb_consolidate_shelve_file(
                    $src,
                    $srcDriveRoot,
                    'destination collision: dest matches size+mtime; source is a true duplicate',
                    $dryRun,
                    $log
                );
                return [
                    'outcome'     => $r['ok'] ? 'duped' : 'failed',
                    'finalPath'   => null,
                    'bytes'       => 0,
                    'quarantined' => null,
                ];
            }

            // Suspect dest (size/mtime mismatch — likely an interrupted mv's
            // partial): set the DEST aside, then move the good source in.
            $r = moviedb_consolidate_shelve_file(
                $destPath,
                $destDriveRoot,
                "destination collision: suspected partial (dest $destSize bytes"
                    . ", mtime $destMtime vs src $bytes bytes, mtime $srcMtime) quarantined",
                $dryRun,
                $log,
                MOVIEDB_CONSOLIDATE_SUSPECT_PREFIX
            );
            if (!$r['ok']) {
                // Never mv onto an existing file — leave the source in place.
                $log('MOVE', $src, $destPath, $bytes, 'FAIL', 'destination collision could not be quarantined');
                return ['outcome' => 'failed', 'finalPath' => null, 'bytes' => 0, 'quarantined' => null];
            }
            $quarantined = $r['to'];
        }

        if ($dryRun) {
            $log('MOVE', $src, $destPath, $bytes, 'DRYRUN', '');
            return ['outcome' => 'moved', 'finalPath' => $destPath, 'bytes' => $bytes, 'quarantined' => $quarantined];
        }

        $cmdOut = [];
        $exit = 0;
        moviedb_consolidate_shell_move($src, $destPath, $cmdOut, $exit);

        if ($exit === 0 && file_exists($destPath) && !file_exists($src)) {
            $log('MOVE', $src, $destPath, $bytes, 'OK', '');
            return ['outcome' => 'moved', 'finalPath' => $destPath, 'bytes' => $bytes, 'quarantined' => $quarantined];
        }

        // Failed mv: quarantine a smaller leftover so a retry isn't poisoned.
        $expected = file_exists($src) ? moviedb_consolidate_size($src) : $bytes;
        $failQuarantine = moviedb_consolidate_quarantine_partial($destPath, $expected, $destDriveRoot, $log);
        if ($failQuarantine !== null) {
            $quarantined = $failQuarantine;
        }
        $log('MOVE', $src, $destPath, $bytes, 'FAIL', 'mv failed: exit=' . $exit . ' out=' . implode(' | ', $cmdOut));
        return ['outcome' => 'failed', 'finalPath' => null, 'bytes' => 0, 'quarantined' => $quarantined];
    }
}

if (!function_exists('moviedb_unmounted_paths')) {
    /**
     * Which of these configured paths are not directories RIGHT NOW.
     *
     * Sean keeps the external drives unmounted between sessions, so a saved
     * root routinely points at nothing. Both the consolidation and the drive
     * index need to say so up front rather than at the moment a run fails —
     * and the drive index's stored "missingRoots" can't answer it, since that
     * records what was missing when the index was last BUILT.
     *
     * @param string[] $paths
     * @return string[] the subset that isn't currently mounted/readable
     */
    function moviedb_unmounted_paths(array $paths): array
    {
        $missing = [];
        foreach ($paths as $p) {
            if (!is_string($p) || trim($p) === '') {
                continue;
            }
            $p = rtrim(trim($p), '/');
            if ($p !== '' && !is_dir($p)) {
                $missing[] = $p;
            }
        }
        return array_values(array_unique($missing));
    }
}
