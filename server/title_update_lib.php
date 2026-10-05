<?php

/**
 * Database title updates after library renames (Settings → Normalize Library
 * Filenames → "Update Database Titles"). A rename that changes a file's
 * catalogue title (moviedb_db_title_for_base) leaves its row on the old
 * spelling; this maps each landed rename to its row and, on request, renames
 * the row — or, when the new title already has a row of its own, merges the
 * two.
 *
 * Pure planning lives here (testable with fixtures: server/tests/
 * title_update_test.php); the guarded writes take a mysqli handle. Endpoint:
 * titleUpdates.php.
 */

require_once __DIR__ . '/normalize_helpers.php';
require_once __DIR__ . '/drive_index_lib.php'; // MOVIEDB_DRIVE_INDEX_VIDEO_EXTS

/**
 * Folders whose files are catalogued in movies_bi; every other folder's files
 * are in MOVIEDB_TITLE_UPDATE_DEFAULT_TABLE (Sean, 2026-10-04: "folders under
 * /Volumes/Etc/Bi use movies_bi"). Fixed by folder, not by Settings → Catalog
 * Table: a library rename belongs to its folder's catalog whichever table the
 * app happens to be showing.
 */
const MOVIEDB_TITLE_UPDATE_DEFAULT_TABLE = 'movies_het';

const MOVIEDB_TITLE_UPDATE_TABLE_DIRS = [
    '/Volumes/Etc/Bi' => 'movies_bi',
];

/** Append-only record of every row written, with before/after, for undo. */
const MOVIEDB_TITLE_UPDATE_LOG = __DIR__ . '/title_updates_log.jsonl';

const MOVIEDB_TITLE_UPDATE_COLUMNS = 'id, title, dimensions, filesize, duration, date_created';

if (!function_exists('moviedb_title_update_table_for_dir')) {
    function moviedb_title_update_table_for_dir(string $dir, string $defaultTable): string
    {
        // The library volumes are case-insensitive: "/Volumes/Etc/bi" is the Bi folder too
        $dir = rtrim($dir, '/');
        foreach (MOVIEDB_TITLE_UPDATE_TABLE_DIRS as $prefix => $table) {
            if (strcasecmp($dir, $prefix) === 0 || strncasecmp($dir, $prefix . '/', strlen($prefix) + 1) === 0) {
                return $table;
            }
        }
        return $defaultTable;
    }
}

if (!function_exists('moviedb_title_update_tables')) {
    /** The tables a request may name: the default one plus the mapped ones. */
    function moviedb_title_update_tables(string $defaultTable): array
    {
        return array_values(array_unique(array_merge([$defaultTable], array_values(MOVIEDB_TITLE_UPDATE_TABLE_DIRS))));
    }
}

if (!function_exists('moviedb_title_update_file_title')) {
    /**
     * The title a file base is catalogued under, in one of two forms. 'short'
     * is moviedb_db_title_for_base: a tail after "# NN - " is a cast and
     * drops ("Title # 03 - Jane Doe" → "Title # 03"). 'full' keeps it — the
     * library also has ~880 rows whose tail is a subtitle ("Buttman Goes to
     * Rio # 02 - Back in Rio", "A.N.A.L. # 03 - Bum Rush"), which the short
     * form can never find. The two differ only for such a tail; which one a
     * title uses is the database's call (moviedb_title_update_resolve).
     */
    function moviedb_title_update_file_title(string $base, string $form = 'short'): string
    {
        return $form === 'full' ? trim(stripTitleVariantSuffixes($base)) : moviedb_db_title_for_base($base);
    }
}

if (!function_exists('moviedb_title_update_pairs')) {
    /**
     * Collapse landed renames ({path, originalFileName, newFileName}) into
     * distinct title changes, each in both forms (moviedb_title_update_file_title):
     * oldTitle/newTitle short, oldFull/newFull full. Renames that keep both
     * forms (a cast added to a scene) drop out; one that changes only a "# NN
     * - " tail stays, since that tail may be a subtitle — resolve decides.
     * Scenes of one movie collapse into one change; the same change made in
     * two folders keeps both folders. An old title heading to two different
     * new titles is flagged `conflict` (short form) / `conflictFull` on each.
     *
     * @return array<int, array{table:string, oldTitle:string, newTitle:string, oldFull:string, newFull:string, dirs:string[], files:string[], conflict:bool, conflictFull:bool}>
     */
    function moviedb_title_update_pairs(array $renames, string $defaultTable): array
    {
        $pairs = [];
        foreach ($renames as $r) {
            $dir = rtrim((string) ($r['path'] ?? ''), '/');
            $oldBase = pathinfo((string) ($r['originalFileName'] ?? ''), PATHINFO_FILENAME);
            $newBase = pathinfo((string) ($r['newFileName'] ?? ''), PATHINFO_FILENAME);
            $old = moviedb_title_update_file_title($oldBase);
            $new = moviedb_title_update_file_title($newBase);
            $oldFull = moviedb_title_update_file_title($oldBase, 'full');
            $newFull = moviedb_title_update_file_title($newBase, 'full');
            if ($dir === '' || $old === '' || $new === '' || ($old === $new && $oldFull === $newFull)) {
                continue;
            }
            $table = moviedb_title_update_table_for_dir($dir, $defaultTable);
            $key = implode("\0", [$table, $old, $new, $oldFull, $newFull]);
            $pairs[$key] ??= ['table' => $table, 'oldTitle' => $old, 'newTitle' => $new, 'oldFull' => $oldFull, 'newFull' => $newFull,
                'dirs' => [], 'files' => [], 'conflict' => false, 'conflictFull' => false];
            if (!in_array($dir, $pairs[$key]['dirs'], true)) {
                $pairs[$key]['dirs'][] = $dir;
            }
            $pairs[$key]['files'][] = $dir . '/' . $r['newFileName'];
        }
        $targets = [];
        $targetsFull = [];
        foreach ($pairs as $p) {
            $targets[$p['table'] . "\0" . $p['oldTitle']][$p['newTitle']] = true;
            $targetsFull[$p['table'] . "\0" . $p['oldFull']][$p['newFull']] = true;
        }
        foreach ($pairs as &$p) {
            $p['conflict'] = count($targets[$p['table'] . "\0" . $p['oldTitle']]) > 1;
            $p['conflictFull'] = count($targetsFull[$p['table'] . "\0" . $p['oldFull']]) > 1;
        }
        unset($p);
        return array_values($pairs);
    }
}

if (!function_exists('moviedb_title_update_resolve')) {
    /**
     * Which form a pair's title takes, decided by the database: the full
     * form (subtitle kept) when a row carries its old or new spelling, else
     * the short form (tail read as a cast). Returns the pair with
     * oldTitle/newTitle/conflict set to that form, `form` and its `rows` —
     * or null when only a cast changed (short form unchanged, no full-form
     * row).
     *
     * @param callable(string $old, string $new): array $rowsFor rows matching either title
     */
    function moviedb_title_update_resolve(array $pair, callable $rowsFor): ?array
    {
        $oldFull = $pair['oldFull'] ?? $pair['oldTitle'];
        $newFull = $pair['newFull'] ?? $pair['newTitle'];
        $hasTail = $oldFull !== $pair['oldTitle'] || $newFull !== $pair['newTitle'];
        if ($hasTail && $oldFull !== $newFull) {
            $rows = $rowsFor($oldFull, $newFull);
            if ($rows) {
                return ['oldTitle' => $oldFull, 'newTitle' => $newFull, 'conflict' => $pair['conflictFull'] ?? false,
                    'form' => 'full', 'rows' => $rows] + $pair;
            }
        }
        if ($pair['oldTitle'] === $pair['newTitle']) {
            return null;
        }
        return ['form' => 'short', 'rows' => $rowsFor($pair['oldTitle'], $pair['newTitle'])] + $pair;
    }
}

if (!function_exists('moviedb_title_update_in_dirs')) {
    /** Whether $dir is one of $dirs, case-insensitively (the volumes are). */
    function moviedb_title_update_in_dirs(string $dir, array $dirs): bool
    {
        $dir = rtrim($dir, '/');
        foreach ($dirs as $d) {
            if (strcasecmp($dir, rtrim($d, '/')) === 0) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('moviedb_title_update_dir_videos')) {
    /**
     * Video file names in a folder, listed once per request: a library folder
     * holds thousands of files on a spinning disk, and a preview asks about
     * it once per title. Filtered by extension only (no per-file stat);
     * callers stat just the names they keep.
     */
    function moviedb_title_update_dir_videos(string $dir): array
    {
        static $cache = [];
        if (!isset($cache[$dir])) {
            $cache[$dir] = array_values(array_filter(@scandir($dir) ?: [], fn($f) => $f[0] !== '.'
                && in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), MOVIEDB_DRIVE_INDEX_VIDEO_EXTS, true)));
        }
        return $cache[$dir];
    }
}

if (!function_exists('moviedb_title_update_leftovers')) {
    /**
     * Video files still carrying the old title: live in the renamed folders,
     * and from the drive index everywhere else in the same catalog (a het
     * file never blocks a Bi title). A title that changed while copies keep
     * the old name would leave those copies matching nothing. For a case-only
     * change only the exact old spelling counts (the rest already match the
     * new title); otherwise any capitalization of the old title does.
     *
     * @param array|null $index A loaded drive index (moviedb_load_drive_index) or null.
     * @return string[] Paths.
     */
    function moviedb_title_update_leftovers(array $dirs, string $oldTitle, ?array $index, string $newTitle = '', string $table = MOVIEDB_TITLE_UPDATE_DEFAULT_TABLE, string $form = 'short'): array
    {
        $caseOnly = strcasecmp($oldTitle, $newTitle) === 0;
        $isOld = fn(string $t) => $caseOnly ? $t === $oldTitle : strcasecmp($t, $oldTitle) === 0;
        $found = [];
        foreach ($dirs as $dir) {
            foreach (moviedb_title_update_dir_videos($dir) as $f) {
                if ($isOld(moviedb_title_update_file_title(pathinfo($f, PATHINFO_FILENAME), $form)) && is_file("$dir/$f")) {
                    $found[] = "$dir/$f";
                }
            }
        }
        foreach ($index['entries'] ?? [] as $e) {
            if (moviedb_title_update_in_dirs($e['dir'], $dirs)
                || moviedb_title_update_table_for_dir($e['dir'], MOVIEDB_TITLE_UPDATE_DEFAULT_TABLE) !== $table) {
                continue; // read live above (the index may predate the rename) / another catalog
            }
            if ($isOld(moviedb_title_update_file_title($e['base'], $form))) {
                $found[] = $e['dir'] . '/' . $e['file'];
            }
        }
        return $found;
    }
}

if (!function_exists('moviedb_title_update_files_elsewhere')) {
    /** Index entries outside $dirs, in the same catalog, whose title (in $form) matches either title case-insensitively. */
    function moviedb_title_update_files_elsewhere(array $dirs, array $titles, ?array $index, string $table = MOVIEDB_TITLE_UPDATE_DEFAULT_TABLE, string $form = 'short'): array
    {
        $want = array_map('mb_strtolower', $titles);
        $found = [];
        foreach ($index['entries'] ?? [] as $e) {
            if (moviedb_title_update_in_dirs($e['dir'], $dirs)
                || moviedb_title_update_table_for_dir($e['dir'], MOVIEDB_TITLE_UPDATE_DEFAULT_TABLE) !== $table) {
                continue;
            }
            if (in_array(mb_strtolower(moviedb_title_update_file_title($e['base'], $form)), $want, true)) {
                $found[] = $e['dir'] . '/' . $e['file'];
            }
        }
        return $found;
    }
}

if (!function_exists('moviedb_title_update_keep_order')) {
    /** Earliest-catalogued first (date added, then id), so the kept row's date survives. */
    function moviedb_title_update_keep_order(array $a, array $b): int
    {
        $da = $a['date_created'] ?: '9999-99-99';
        $dbd = $b['date_created'] ?: '9999-99-99';
        return $da <=> $dbd ?: (int) $a['id'] <=> (int) $b['id'];
    }
}

if (!function_exists('moviedb_title_update_classify')) {
    /**
     * What to do with one title change, given the rows whose title matches
     * the old or new title case-insensitively and any leftover old-title
     * files. Statuses:
     *   update    one row, still on the old spelling — rename it
     *   merge     the new title already has a row (two rows in all) — offer a merge
     *   current   the one row already carries the new title
     *   missing   no row for either title
     *   partial   files with the old title remain (some renames failed or are elsewhere)
     *   conflict  the old title was renamed two different ways
     *   ambiguous three or more rows match
     * A case-only change with leftovers still updates (titles match
     * case-insensitively, so the leftovers keep matching) — noted instead.
     */
    function moviedb_title_update_classify(array $pair, array $rows, array $leftovers): array
    {
        $item = [
            'table' => $pair['table'],
            'oldTitle' => $pair['oldTitle'],
            'newTitle' => $pair['newTitle'],
            'files' => $pair['files'],
            'rows' => $rows,
            'leftovers' => $leftovers,
        ];
        $caseOnly = strcasecmp($pair['oldTitle'], $pair['newTitle']) === 0;
        if ($pair['conflict']) {
            return $item + ['status' => 'conflict', 'reason' => 'This title was renamed two different ways.'];
        }
        if ($leftovers && !$caseOnly) {
            return $item + ['status' => 'partial', 'reason' => count($leftovers) . ' file(s) still have the old title.'];
        }
        $n = count($rows);
        if ($n === 0) {
            return $item + ['status' => 'missing', 'reason' => 'No database row has the old or the new title.'];
        }
        if ($n === 1) {
            if ($rows[0]['title'] === $pair['newTitle']) {
                return $item + ['status' => 'current', 'reason' => 'The row already has the new title.'];
            }
            $note = $leftovers ? count($leftovers) . ' file(s) still use the old capitalization.' : null;
            return $item + ['status' => 'update', 'row' => $rows[0], 'note' => $note];
        }
        if ($n === 2) {
            $sorted = $rows;
            usort($sorted, 'moviedb_title_update_keep_order');
            $newHasRow = !$caseOnly && (strcasecmp($rows[0]['title'], $pair['newTitle']) === 0
                || strcasecmp($rows[1]['title'], $pair['newTitle']) === 0);
            return $item + ['status' => 'merge', 'keep' => $sorted[0], 'drop' => $sorted[1],
                'reason' => $newHasRow ? 'The new title already has a row of its own.' : 'The title has two rows.'];
        }
        return $item + ['status' => 'ambiguous', 'reason' => "$n rows match these titles."];
    }
}

if (!function_exists('moviedb_title_update_merge_values')) {
    /**
     * The merged row's size, resolution and length. From the files when they
     * were measured (all of the title's files are in the renamed folders);
     * otherwise the kept row's values, blanks filled from the deleted row.
     *
     * @param array|null $files ['filesize' => int, 'dimensions' => ?string, 'duration' => ?int] or null
     */
    function moviedb_title_update_merge_values(array $keep, array $drop, ?array $files): array
    {
        $pick = fn(string $c) => ($keep[$c] ?? null) !== null && $keep[$c] !== '' ? $keep[$c] : ($drop[$c] ?? null);
        if ($files !== null) {
            return [
                'source' => 'files',
                'filesize' => (string) $files['filesize'],
                'dimensions' => $files['dimensions'] ?: $pick('dimensions'),
                'duration' => $files['duration'] ?: $pick('duration'),
            ];
        }
        return ['source' => 'rows', 'filesize' => $pick('filesize'), 'dimensions' => $pick('dimensions'), 'duration' => $pick('duration')];
    }
}

if (!function_exists('moviedb_title_update_rows')) {
    /** Rows whose title matches either title case-insensitively. */
    function moviedb_title_update_rows(mysqli $db, string $table, string $old, string $new, bool $lock = false): array
    {
        $st = $db->prepare('SELECT ' . MOVIEDB_TITLE_UPDATE_COLUMNS . " FROM `$table` WHERE LOWER(title) IN (LOWER(?), LOWER(?)) ORDER BY id" . ($lock ? ' FOR UPDATE' : ''));
        $st->bind_param('ss', $old, $new);
        $st->execute();
        return $st->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

if (!function_exists('moviedb_title_update_row')) {
    function moviedb_title_update_row(mysqli $db, string $table, int $id, bool $lock = false): ?array
    {
        $st = $db->prepare('SELECT ' . MOVIEDB_TITLE_UPDATE_COLUMNS . " FROM `$table` WHERE id = ?" . ($lock ? ' FOR UPDATE' : ''));
        $st->bind_param('i', $id);
        $st->execute();
        return $st->get_result()->fetch_assoc() ?: null;
    }
}

if (!function_exists('moviedb_title_update_others_with_title')) {
    /** How many rows other than $exceptIds carry $title, case-insensitively. */
    function moviedb_title_update_others_with_title(mysqli $db, string $table, string $title, array $exceptIds): int
    {
        $ids = implode(',', array_map('intval', $exceptIds ?: [0]));
        $st = $db->prepare("SELECT COUNT(*) FROM `$table` WHERE LOWER(title) = LOWER(?) AND id NOT IN ($ids)");
        $st->bind_param('s', $title);
        $st->execute();
        return (int) $st->get_result()->fetch_row()[0];
    }
}

if (!function_exists('moviedb_title_update_same_row')) {
    /** A row as the client last saw it vs as it is now (durations to the millisecond). */
    function moviedb_title_update_same_row(?array $now, array $seen): bool
    {
        if ($now === null) {
            return false;
        }
        foreach (['id', 'title', 'dimensions', 'filesize', 'date_created'] as $c) {
            if ((string) ($now[$c] ?? '') !== (string) ($seen[$c] ?? '')) {
                return false;
            }
        }
        $a = $now['duration']; $b = $seen['duration'] ?? null;
        return ($a === null) === ($b === null) && ($a === null || abs((float) $a - (float) $b) < 0.001);
    }
}

if (!function_exists('moviedb_title_update_log')) {
    /** Append one write to the undo log; false when it couldn't be written. */
    function moviedb_title_update_log(array $entry, string $logPath = MOVIEDB_TITLE_UPDATE_LOG): bool
    {
        $entry = ['at' => date('c')] + $entry;
        return @file_put_contents($logPath, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX) !== false;
    }
}

if (!function_exists('moviedb_title_update_full_row')) {
    /** Every column (movies_bi's filepath too) — what the undo log keeps. */
    function moviedb_title_update_full_row(mysqli $db, string $table, int $id): ?array
    {
        $st = $db->prepare("SELECT * FROM `$table` WHERE id = ?");
        $st->bind_param('i', $id);
        $st->execute();
        return $st->get_result()->fetch_assoc() ?: null;
    }
}

if (!function_exists('moviedb_title_update_apply')) {
    /**
     * Rename one row's title. Guarded: the row must still read exactly
     * $from, and no other row may already carry $to (that's a merge).
     */
    function moviedb_title_update_apply(mysqli $db, string $table, int $id, string $from, string $to, string $logPath = MOVIEDB_TITLE_UPDATE_LOG): array
    {
        $db->begin_transaction();
        try {
            $before = moviedb_title_update_row($db, $table, $id, true);
            $beforeFull = moviedb_title_update_full_row($db, $table, $id);
            if ($before === null) {
                throw new RuntimeException('The row is gone.');
            }
            if ($before['title'] !== $from) {
                throw new RuntimeException("The row's title changed to \"{$before['title']}\".");
            }
            if (moviedb_title_update_others_with_title($db, $table, $to, [$id]) > 0) {
                throw new RuntimeException('Another row already has the new title.');
            }
            $st = $db->prepare("UPDATE `$table` SET title = ? WHERE id = ? AND BINARY title = ?");
            $st->bind_param('sis', $to, $id, $from);
            $st->execute();
            $after = moviedb_title_update_row($db, $table, $id);
            if ($st->affected_rows !== 1 || ($after['title'] ?? null) !== $to) {
                throw new RuntimeException('The update did not read back.');
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $logged = moviedb_title_update_log(['action' => 'update', 'table' => $table, 'before' => [$beforeFull], 'after' => $after], $logPath);
        return ['ok' => true, 'row' => $after, 'logged' => $logged];
    }
}

if (!function_exists('moviedb_title_update_merge')) {
    /**
     * Merge two rows into the kept one: it takes the new title and the given
     * size/resolution/length (its date added stays); the other row is
     * deleted. Guarded: both rows must be exactly as the preview showed them,
     * and no third row may carry the title.
     */
    function moviedb_title_update_merge(mysqli $db, string $table, array $keepSeen, array $dropSeen, string $oldTitle, string $title, array $values, string $logPath = MOVIEDB_TITLE_UPDATE_LOG): array
    {
        $keepId = (int) ($keepSeen['id'] ?? 0);
        $dropId = (int) ($dropSeen['id'] ?? 0);
        if ($keepId <= 0 || $dropId <= 0 || $keepId === $dropId) {
            return ['ok' => false, 'error' => 'Two different rows are needed.'];
        }
        $size = $values['filesize'] ?? null;
        $dims = $values['dimensions'] ?? null;
        $dur = $values['duration'] ?? null;
        // \z, not $: $ also matches before a trailing newline. Dimensions as
        // the DB already holds them ("720x480", "1280  x  720") are accepted.
        $durOk = $dur === null || (is_int($dur) || is_float($dur) ? $dur >= 0
            : is_string($dur) && preg_match('/^\d+(\.\d+)?\z/', $dur));
        if (($size !== null && !preg_match('/^\d{1,15}\z/', (string) $size))
            || ($dims !== null && $dims !== '' && !preg_match('/^\d{1,6}\s*x\s*\d{1,6}\z/', (string) $dims))
            || !$durOk) {
            return ['ok' => false, 'error' => 'Invalid size, resolution or length.'];
        }
        $size = $size === null ? null : (string) $size;
        $dims = $dims === null ? null : (string) $dims;
        $dur = $dur === null ? null : (float) $dur;
        $db->begin_transaction();
        try {
            $keep = moviedb_title_update_row($db, $table, $keepId, true);
            $drop = moviedb_title_update_row($db, $table, $dropId, true);
            if (!moviedb_title_update_same_row($keep, $keepSeen) || !moviedb_title_update_same_row($drop, $dropSeen)) {
                throw new RuntimeException('One of the rows changed since the preview — check again.');
            }
            // Only rows of this title change: each must carry the old or the new spelling
            foreach ([$keep, $drop] as $r) {
                if (strcasecmp($r['title'], $oldTitle) !== 0 && strcasecmp($r['title'], $title) !== 0) {
                    throw new RuntimeException("Row {$r['id']} (\"{$r['title']}\") isn't this title.");
                }
            }
            $keepFull = moviedb_title_update_full_row($db, $table, $keepId);
            $dropFull = moviedb_title_update_full_row($db, $table, $dropId);
            if (moviedb_title_update_others_with_title($db, $table, $title, [$keepId, $dropId]) > 0) {
                throw new RuntimeException('A third row already has the new title.');
            }
            $st = $db->prepare("UPDATE `$table` SET title = ?, filesize = ?, dimensions = ?, duration = ? WHERE id = ?");
            $st->bind_param('sssdi', $title, $size, $dims, $dur, $keepId);
            $st->execute();
            $del = $db->prepare("DELETE FROM `$table` WHERE id = ?");
            $del->bind_param('i', $dropId);
            $del->execute();
            $after = moviedb_title_update_row($db, $table, $keepId);
            if ($del->affected_rows !== 1 || moviedb_title_update_row($db, $table, $dropId) !== null
                || ($after['title'] ?? null) !== $title || (string) $after['filesize'] !== (string) $size) {
                throw new RuntimeException('The merge did not read back.');
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $logged = moviedb_title_update_log(['action' => 'merge', 'table' => $table, 'before' => [$keepFull, $dropFull], 'after' => $after, 'deleted' => $dropId], $logPath);
        return ['ok' => true, 'row' => $after, 'deleted' => $dropId, 'logged' => $logged];
    }
}
