<?php

/**
 * Drive-index endpoint: status/search/rebuild plus the two file actions
 * (reveal in Finder, move to volume trash). All logic lives in
 * drive_index_lib.php; this file is transport only.
 *
 * POST { action: 'status' }                  -> { exists, builtAt, fileCount, roots, missingRoots }
 * POST { action: 'search', query, offset? }  -> { groups, totalGroups, totalFiles, offset, pageSize }
 * POST { action: 'rebuild' }                 -> { success, builtAt, fileCount, roots, missingRoots }
 * POST { action: 'reveal', path }            -> { success, error? }
 * POST { action: 'trash', paths: string[] }  -> { success, results: [{path, trashed, to?, error?}] }
 *
 * Trash/reveal refuse any path that doesn't resolve inside the index's roots
 * or that isn't itself an index entry — the index is the allow-list.
 */

require_once __DIR__ . '/drive_index_lib.php';

ini_set('display_errors', '0');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only POST requests are allowed']);
    exit();
}

$data = json_decode(file_get_contents('php://input') ?: '', true);
$data = is_array($data) ? $data : [];
$action = isset($data['action']) && is_string($data['action']) ? $data['action'] : '';

// State-changing actions require a custom header. A hostile page can fire a
// blind cross-site "simple request" POST at 127.0.0.1:8888 (CORS gates reading
// the response, not sending the request); a custom header forces a CORS
// preflight the browser won't grant, killing that class outright.
$mutating = in_array($action, ['rebuild', 'reveal', 'trash'], true);
if ($mutating && empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Missing X-Requested-With header']);
    exit();
}

switch ($action) {
    case 'progress':
        echo json_encode(moviedb_drive_index_progress());
        break;

    case 'status':
        $index = moviedb_load_drive_index();
        echo json_encode([
            'exists'       => $index !== null,
            'builtAt'      => $index['builtAt'] ?? null,
            'fileCount'    => (int) ($index['fileCount'] ?? 0),
            'roots'        => $index['roots'] ?? moviedb_drive_index_roots(),
            'missingRoots' => $index['missingRoots'] ?? [],
        ]);
        break;

    case 'search':
        $query = is_string($data['query'] ?? null) ? $data['query'] : '';
        $offset = is_numeric($data['offset'] ?? null) ? (int) $data['offset'] : 0;
        $result = moviedb_search_drive_index($query, null, $offset);
        if (isset($result['error'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $result['error']]);
            break;
        }
        echo json_encode(['success' => true] + $result);
        break;

    case 'rebuild':
        set_time_limit(600); // four spinning disks take a while
        $index = moviedb_build_drive_index();
        if ($index === null) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to write the drive index']);
            break;
        }
        if (isset($index['error'])) {
            // Unreadable root (lost Full Disk Access): previous index kept
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $index['error']]);
            break;
        }
        echo json_encode([
            'success'      => true,
            'builtAt'      => $index['builtAt'],
            'fileCount'    => $index['fileCount'],
            'roots'        => $index['roots'],
            'missingRoots' => $index['missingRoots'],
        ]);
        break;

    case 'reveal':
        $path = $data['path'] ?? null;
        if (!is_string($path) || $path === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing path']);
            break;
        }
        $result = moviedb_reveal_drive_file($path);
        if (!$result['success'] && !empty($result['invalid'])) {
            http_response_code(400);
        }
        echo json_encode($result);
        break;

    case 'trash':
        $paths = $data['paths'] ?? null;
        if (!is_array($paths) || $paths === [] || count($paths) > 50) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'paths must be a non-empty array of at most 50 paths']);
            break;
        }
        $trash = moviedb_trash_drive_files(array_values($paths));
        echo json_encode([
            'success'      => true,
            'results'      => $trash['results'],
            'indexUpdated' => $trash['indexUpdated'],
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
}
