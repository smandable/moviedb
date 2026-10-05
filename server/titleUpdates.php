<?php

/**
 * Database titles after library renames. Logic in title_update_lib.php.
 *
 * POST { action: 'preview', renames: [{path, originalFileName, newFileName}] }
 *   -> { items: [...], indexMissing }  one per title change, each with a status (see
 *      moviedb_title_update_classify); 'merge' items carry `merged`, the
 *      values the kept row would take.
 * POST { action: 'apply', updates: [{table, id, from, to}] }
 *   -> { results: [{id, ok, error?, row?}] }
 * POST { action: 'merge', table, keep, drop, oldTitle, title, values: {filesize, dimensions, duration} }
 *   -> { ok, error?, row?, deleted? }
 *
 * apply/merge write the database, so they require the X-Requested-With
 * header (CSRF gate, as driveIndex.php). Every write is appended to
 * title_updates_log.jsonl with the rows as they were.
 */

require_once __DIR__ . '/path_guard.php';
require_once __DIR__ . '/title_update_lib.php';
require_once __DIR__ . '/ffprobe.php';

ini_set('display_errors', '0');
header('Content-Type: application/json');

function moviedb_title_updates_fail(int $code, string $message): never
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    moviedb_title_updates_fail(405, 'Only POST requests are allowed');
}

$data = json_decode(file_get_contents('php://input') ?: '', true);
$data = is_array($data) ? $data : [];
$action = is_string($data['action'] ?? null) ? $data['action'] : '';

if (in_array($action, ['apply', 'merge'], true) && empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    moviedb_title_updates_fail(403, 'Missing X-Requested-With header');
}

require __DIR__ . '/db_connect.php'; // $db
// By folder, not by the Settings table switch (see MOVIEDB_TITLE_UPDATE_TABLE_DIRS)
$table = MOVIEDB_TITLE_UPDATE_DEFAULT_TABLE;
$tables = moviedb_title_update_tables($table);

try {
    if ($action === 'preview') {
        // A sleeping drive takes ~20s to answer its first listing.
        set_time_limit(120);
        $renames = [];
        foreach (is_array($data['renames'] ?? null) ? $data['renames'] : [] as $r) {
            $path = rtrim((string) ($r['path'] ?? ''), '/');
            $from = (string) ($r['originalFileName'] ?? '');
            $to = (string) ($r['newFileName'] ?? '');
            // The folder exactly as on disk (no "..", no symlink hops): the
            // folder→table rule and the leftover checks compare paths as text
            $real = $path === '' ? false : realpath($path);
            if ($real === false || strcasecmp($real, $path) !== 0 || !moviedb_is_path_allowed($path)
                || $from === '' || $to === '' || str_contains($from . $to, '/')) {
                continue;
            }
            $renames[] = ['path' => $path, 'originalFileName' => $from, 'newFileName' => $to];
        }
        $index = moviedb_load_drive_index();
        $ffprobe = is_executable('/opt/homebrew/bin/ffprobe') ? '/opt/homebrew/bin/ffprobe' : 'ffprobe';
        $items = [];
        foreach (moviedb_title_update_pairs($renames, $table) as $pair) {
            // Subtitled title ("# 02 - Back in Rio") or cast tail: the rows decide
            $pair = moviedb_title_update_resolve($pair,
                fn(string $old, string $new) => moviedb_title_update_rows($db, $pair['table'], $old, $new));
            if ($pair === null) {
                continue; // only a cast changed
            }
            $form = $pair['form'];
            $leftovers = moviedb_title_update_leftovers($pair['dirs'], $pair['oldTitle'], $index, $pair['newTitle'], $pair['table'], $form);
            $item = moviedb_title_update_classify($pair, $pair['rows'], $leftovers);
            if ($item['status'] === 'merge') {
                // Measure the title's files when they're all here; with copies
                // elsewhere a folder total would understate the movie.
                $files = null;
                $elsewhere = moviedb_title_update_files_elsewhere($pair['dirs'], [$pair['oldTitle'], $pair['newTitle']], $index, $pair['table'], $form);
                if (!$elsewhere) {
                    $paths = [];
                    foreach ($pair['dirs'] as $dir) {
                        foreach (moviedb_title_update_dir_videos($dir) as $f) {
                            if (strcasecmp(moviedb_title_update_file_title(pathinfo($f, PATHINFO_FILENAME), $form), $pair['newTitle']) === 0
                                && is_file("$dir/$f")) {
                                $paths[] = "$dir/$f";
                            }
                        }
                    }
                    if ($paths) {
                        $probes = probeVideosParallel($paths, $ffprobe, 4);
                        $size = 0; $duration = 0; $largest = -1; $dims = null;
                        foreach ($paths as $i => $p) {
                            $s = (int) @filesize($p);
                            $size += $s;
                            $duration += (int) ($probes[$i]['duration'] ?? 0);
                            if ($s > $largest && !empty($probes[$i]['dimensions'])) {
                                $largest = $s;
                                $dims = $probes[$i]['dimensions'];
                            }
                        }
                        $files = ['filesize' => $size, 'dimensions' => $dims, 'duration' => $duration ?: null, 'count' => count($paths)];
                    }
                }
                $item['merged'] = moviedb_title_update_merge_values($item['keep'], $item['drop'], $files)
                    + ['fileCount' => $files['count'] ?? 0, 'elsewhere' => $elsewhere];
            }
            $items[] = $item;
        }
        // Without the index, copies on other drives can't be checked
        echo json_encode(['items' => $items, 'indexMissing' => $index === null], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit();
    }

    if ($action === 'apply') {
        $results = [];
        foreach (is_array($data['updates'] ?? null) ? $data['updates'] : [] as $u) {
            $t = (string) ($u['table'] ?? '');
            $id = (int) ($u['id'] ?? 0);
            $from = (string) ($u['from'] ?? '');
            $to = trim((string) ($u['to'] ?? ''));
            if (!in_array($t, $tables, true) || $id <= 0 || $from === '' || $to === '') {
                $results[] = ['id' => $id, 'ok' => false, 'error' => 'Invalid update.'];
                continue;
            }
            $results[] = ['id' => $id] + moviedb_title_update_apply($db, $t, $id, $from, $to);
        }
        echo json_encode(['results' => $results], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit();
    }

    if ($action === 'merge') {
        $t = (string) ($data['table'] ?? '');
        $title = trim((string) ($data['title'] ?? ''));
        $oldTitle = trim((string) ($data['oldTitle'] ?? ''));
        if (!in_array($t, $tables, true) || $title === '' || $oldTitle === '' || !is_array($data['keep'] ?? null)
            || !is_array($data['drop'] ?? null) || !is_array($data['values'] ?? null)) {
            moviedb_title_updates_fail(400, 'Invalid merge.');
        }
        echo json_encode(moviedb_title_update_merge($db, $t, $data['keep'], $data['drop'], $oldTitle, $title, $data['values']),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit();
    }

    moviedb_title_updates_fail(400, 'Unknown action');
} catch (Throwable $e) {
    moviedb_title_updates_fail(500, 'Title update error: ' . $e->getMessage());
}
