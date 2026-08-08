<?php

/**
 * Cast-name vocabulary for the rename modal's Add Cast tab.
 *
 * Two sources, unioned:
 *   1. Names already used in the scanned directory's filenames — anything after
 *      "Scene_N - ", comma-split. Free and always current with the batch on disk.
 *   2. The persisted store (server/cast_names.json), seeded drive-wide by
 *      scripts/harvest_cast_names.php and grown here as renames land, so the
 *      vocabulary survives a batch moving off the staging SSD.
 *
 * POST { directory }   -> { names: [...] }   read the vocabulary
 * POST { add: [...] }  -> { names: [...] }   merge new names into the store
 * (both keys may be sent together; `add` is merged before the list is returned)
 */

require_once __DIR__ . '/path_guard.php';
require_once __DIR__ . '/cast_helpers.php';

ini_set('display_errors', '0');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only POST requests are allowed']);
    exit();
}

$data = json_decode(file_get_contents('php://input') ?: '', true);
$data = is_array($data) ? $data : [];

$names = moviedb_load_cast_store();

// 1. Merge anything the client explicitly reports as newly used.
if (isset($data['add']) && is_array($data['add'])) {
    foreach ($data['add'] as $candidate) {
        if (!is_string($candidate)) {
            continue;
        }
        foreach (moviedb_split_cast_tail($candidate) as $name) {
            $names[] = $name;
        }
    }
}

// 2. Mine the scanned directory's filenames.
if (isset($data['directory']) && is_string($data['directory']) && $data['directory'] !== '') {
    $directory = rtrim($data['directory'], '/');
    if (is_dir($directory) && moviedb_is_path_allowed($directory)) {
        $entries = @scandir($directory) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry[0] === '.') {
                continue;
            }
            $base = pathinfo($entry, PATHINFO_FILENAME);
            if (preg_match(MOVIEDB_SCENE_CAST_RE, $base, $m)) {
                foreach (moviedb_split_cast_tail($m[1]) as $name) {
                    $names[] = $name;
                }
            }
        }
    }
}

echo json_encode(['names' => moviedb_save_cast_store($names)]);
