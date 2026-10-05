<?php

/**
 * Cast-name respelling — "use this spelling" on a cast-audit finding: every
 * indexed file whose cast tail carries one of the other spellings is renamed
 * to the chosen one, and those spellings leave the vocabulary
 * (castNamesAudit.php 'respellPreview' / 'respell').
 *
 *   "Sample Movie # 02 - Scene_1 - Abby Rain, Jane Doe.mp4"
 *     -> "Sample Movie # 02 - Scene_1 - Abby Rains, Jane Doe.mp4"
 *
 * Only the cast tail after Scene_N changes — a title that happens to contain
 * the name is left alone — and only files in the drive index, the same files
 * the audit's counts come from. The client previews the plan and sends back
 * the paths it showed: nothing outside that list is renamed, nothing is
 * overwritten, and each rename is appended to server/cast_respell_log.jsonl
 * (old and new path), enough to undo one by hand.
 */

require_once __DIR__ . '/cast_helpers.php';
require_once __DIR__ . '/drive_index_lib.php';
require_once __DIR__ . '/rename_helpers.php';

const MOVIEDB_CAST_RESPELL_LOG = __DIR__ . '/cast_respell_log.jsonl';

/** The filesystem's per-name cap (as renameTheFilesToNormalize.php). */
const MOVIEDB_CAST_RESPELL_MAX_FILENAME_BYTES = 255;

if (!function_exists('moviedb_cast_respell_filename')) {
    /**
     * $file with each cast-tail name matching one of $from (the store's way:
     * cleaned, case-insensitive) replaced by $to; null when its tail carries
     * none of them. Separators, a "With " prefix and the title are kept.
     */
    function moviedb_cast_respell_filename(string $file, array $from, string $to): ?string
    {
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        $base = $ext === '' ? $file : substr($file, 0, -strlen($ext) - 1);
        // The same two tail shapes the audit counts (moviedb_cast_audit_usage)
        if (!preg_match(MOVIEDB_SCENE_CAST_RE, $base, $m, PREG_OFFSET_CAPTURE)
            && !preg_match('/Scene_\d+\s+(\p{Lu}.+)$/u', $base, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        [$tail, $offset] = $m[1];
        $wanted = array_flip(array_map('mb_strtolower', $from));

        // Split as moviedb_split_cast_tail does, keeping the separators
        $parts = preg_split('/(\s*(?:,|&|\band\b|\+)\s*)/iu', $tail, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$tail];
        $changed = false;
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                continue; // a separator
            }
            $clean = moviedb_clean_cast_name($part);
            if ($clean === '' || !isset($wanted[mb_strtolower($clean)])) {
                continue;
            }
            $pos = mb_stripos($part, $clean);
            if ($pos !== false) {
                // Splice the name itself, so "With " and edge spacing survive
                $parts[$i] = mb_substr($part, 0, $pos) . $to . mb_substr($part, $pos + mb_strlen($clean));
            } else {
                // Cleaning changed the letters (lookalike folding): swap the
                // whole name, keeping its surrounding spaces
                preg_match('/^(\s*).*?(\s*)$/su', $part, $edges);
                $parts[$i] = ($edges[1] ?? '') . $to . ($edges[2] ?? '');
            }
            $changed = true;
        }
        if (!$changed) {
            return null;
        }
        $newBase = substr($base, 0, $offset) . implode('', $parts);
        $new = $ext === '' ? $newBase : $newBase . '.' . $ext;
        return $new === $file ? null : $new;
    }
}

if (!function_exists('moviedb_cast_respell_plan')) {
    /**
     * The renames respelling $from as $to would make across $entries (drive
     * index entries): [{path, dir, file, newFile, conflict}] where conflict
     * means another indexed file already has the new name.
     */
    function moviedb_cast_respell_plan(array $entries, array $from, string $to): array
    {
        $taken = [];
        foreach ($entries as $entry) {
            if (is_array($entry)) {
                $taken[mb_strtolower(($entry['dir'] ?? '') . '/' . ($entry['file'] ?? ''))] = true;
            }
        }
        $plan = [];
        foreach ($entries as $entry) {
            $file = is_array($entry) && is_string($entry['file'] ?? null) ? $entry['file'] : '';
            $dir = is_array($entry) && is_string($entry['dir'] ?? null) ? $entry['dir'] : '';
            $new = $file === '' ? null : moviedb_cast_respell_filename($file, $from, $to);
            if ($new === null) {
                continue;
            }
            $caseOnly = strcasecmp($new, $file) === 0;
            $plan[] = [
                'path' => $dir . '/' . $file,
                'dir' => $dir,
                'file' => $file,
                'newFile' => $new,
                'conflict' => !$caseOnly && isset($taken[mb_strtolower($dir . '/' . $new)]),
            ];
        }
        return $plan;
    }
}

if (!function_exists('moviedb_cast_respell_rename')) {
    /**
     * Rename $source to $newName in its own folder, never over another file;
     * a case-only change goes through a temp name (macOS volumes are
     * case-insensitive). Returns '' on success, else why it didn't happen.
     */
    function moviedb_cast_respell_rename(string $source, string $newName): string
    {
        if (!moviedb_is_plain_filename($newName)) {
            return 'Invalid file name';
        }
        if (strlen($newName) > MOVIEDB_CAST_RESPELL_MAX_FILENAME_BYTES) {
            return 'File name too long (' . strlen($newName) . ' of '
                . MOVIEDB_CAST_RESPELL_MAX_FILENAME_BYTES . ' characters)';
        }
        $target = dirname($source) . '/' . $newName;
        $caseOnly = strcasecmp(basename($source), $newName) === 0;
        if (!$caseOnly && file_exists($target)) {
            return 'A file with the new name already exists';
        }
        if ($caseOnly) {
            $temp = $source . '.__tmp__' . uniqid('', true);
            if (!moviedb_rename_with_reason($source, $temp, $reason)) {
                return 'Rename failed' . ($reason === '' ? '' : ": {$reason}");
            }
            clearstatcache(true);
            if (!moviedb_rename_with_reason($temp, $target, $reason)) {
                @rename($temp, $source);
                return 'Rename failed' . ($reason === '' ? '' : ": {$reason}");
            }
            return '';
        }
        return moviedb_rename_with_reason($source, $target, $reason)
            ? ''
            : 'Rename failed' . ($reason === '' ? '' : ": {$reason}");
    }
}

if (!function_exists('moviedb_cast_respell_apply')) {
    /**
     * Respell $from as $to in the indexed files listed in $approved (paths
     * from the preview). Renames each, updates the index entries in one
     * atomic rewrite, and logs every rename. Returns
     * ['results' => [{path, newFile, renamed, error?}], 'renamed' => n,
     *  'failed' => n, 'notPreviewed' => n, 'indexUpdated' => bool]
     * — notPreviewed counts files that carry a spelling but weren't approved
     * (they appeared after the preview) and so were left alone.
     */
    function moviedb_cast_respell_apply(
        array $from,
        string $to,
        array $approved,
        ?string $indexPath = null,
        string $logPath = MOVIEDB_CAST_RESPELL_LOG
    ): array {
        $indexPath = $indexPath ?? MOVIEDB_DRIVE_INDEX_FILE;
        $approvedSet = array_flip(array_filter($approved, 'is_string'));

        // Same writer lock as a rebuild or a trash, so none of them can
        // rewrite the index over this one's changes
        $lock = moviedb_drive_index_lock($indexPath);
        try {
            $index = moviedb_load_drive_index($indexPath);
            if ($index === null) {
                return ['results' => [], 'renamed' => 0, 'failed' => 0, 'notPreviewed' => 0,
                    'indexUpdated' => false, 'error' => 'No drive index has been built yet'];
            }

            $results = [];
            $newNames = [];
            $notPreviewed = 0;
            foreach (moviedb_cast_respell_plan($index['entries'], $from, $to) as $item) {
                if (!isset($approvedSet[$item['path']])) {
                    $notPreviewed++;
                    continue;
                }
                $check = moviedb_drive_index_validate_path($item['path'], $index);
                if (!$check['ok'] && !$item['conflict'] && $check['error'] === 'File not found'
                    && is_file($item['dir'] . '/' . $item['newFile'])) {
                    // An earlier respell renamed it but was stopped before it
                    // saved the index (a sleeping drive outlasted the time
                    // limit): count it done and fix the entry. Logged then.
                    $newNames[$item['path']] = $item['newFile'];
                    $results[] = ['path' => $item['path'], 'newFile' => $item['newFile'], 'renamed' => true, 'already' => true];
                    continue;
                }
                $error = $check['ok']
                    ? moviedb_cast_respell_rename($check['real'], $item['newFile'])
                    : $check['error'];
                if ($error !== '') {
                    $results[] = ['path' => $item['path'], 'newFile' => $item['newFile'], 'renamed' => false, 'error' => $error];
                    continue;
                }
                $newNames[$item['path']] = $item['newFile'];
                $results[] = ['path' => $item['path'], 'newFile' => $item['newFile'], 'renamed' => true];
                @file_put_contents($logPath, json_encode([
                    'at' => date('c'),
                    'from' => $item['path'],
                    'to' => $item['dir'] . '/' . $item['newFile'],
                    'spellings' => array_values($from),
                    'as' => $to,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
            }

            $indexUpdated = true;
            if ($newNames) {
                foreach ($index['entries'] as &$entry) {
                    $path = is_array($entry) ? ($entry['dir'] ?? '') . '/' . ($entry['file'] ?? '') : '';
                    if (isset($newNames[$path])) {
                        $entry['file'] = $newNames[$path];
                        $entry['base'] = stripTitleVariantSuffixes(pathinfo($newNames[$path], PATHINFO_FILENAME));
                    }
                }
                unset($entry);
                $indexUpdated = moviedb_write_drive_index($index, $indexPath);
            }

            $renamed = count($newNames);
            return [
                'results' => $results,
                'renamed' => $renamed,
                'failed' => count($results) - $renamed,
                'notPreviewed' => $notPreviewed,
                'indexUpdated' => $indexUpdated,
            ];
        } finally {
            moviedb_drive_index_unlock($lock);
        }
    }
}
