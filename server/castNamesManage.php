<?php

/**
 * CRUD for the cast-name vocabulary store (server/cast_names.json) — the
 * Settings page's editor. The autocomplete union endpoint is castNames.php;
 * this one manages the persisted store only.
 *
 * POST { action: 'list' }                      -> { names: [...] }
 * POST { action: 'add', name }                 -> { names: [...], added }
 * POST { action: 'rename', name, newName }     -> { names: [...], renamed }
 * POST { action: 'delete', name }              -> { names: [...], deleted }
 *
 * Names pass through moviedb_clean_cast_name (whitespace/punctuation cleanup +
 * homoglyph folding) so hand-typed entries obey the same hygiene as harvested
 * ones. Matching for rename/delete is case-insensitive, mirroring the store's
 * dedupe rule.
 */

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
$action = isset($data['action']) && is_string($data['action']) ? $data['action'] : '';

function moviedb_remove_name(array $names, string $target): array
{
    $key = mb_strtolower($target);
    return array_values(array_filter(
        $names,
        fn($n) => mb_strtolower($n) !== $key
    ));
}

$names = moviedb_load_cast_store();

switch ($action) {
    case 'list':
        echo json_encode(['names' => $names]);
        break;

    case 'add':
        $clean = moviedb_clean_cast_name(is_string($data['name'] ?? null) ? $data['name'] : '');
        if ($clean === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Not a usable name']);
            break;
        }
        $names[] = $clean;
        echo json_encode(['names' => moviedb_save_cast_store($names), 'added' => $clean]);
        break;

    case 'rename':
        $old = is_string($data['name'] ?? null) ? $data['name'] : '';
        $clean = moviedb_clean_cast_name(is_string($data['newName'] ?? null) ? $data['newName'] : '');
        if ($old === '' || $clean === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Not a usable name']);
            break;
        }
        $names = moviedb_remove_name($names, $old);
        $names[] = $clean;
        echo json_encode(['names' => moviedb_save_cast_store($names), 'renamed' => $clean]);
        break;

    case 'delete':
        $target = is_string($data['name'] ?? null) ? $data['name'] : '';
        if ($target === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing name']);
            break;
        }
        $remaining = moviedb_remove_name($names, $target);
        $deleted = count($remaining) < count($names);
        echo json_encode(['names' => moviedb_save_cast_store($remaining), 'deleted' => $deleted]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
}
