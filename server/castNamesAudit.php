<?php

/**
 * Settings → Cast Name Vocabulary → "Check for junk & duplicates". Audits
 * server/cast_names.json (see cast_audit_lib.php) and remembers the findings
 * Sean has looked at and kept.
 *
 * POST { action: 'run' }          -> { findings: [...], hidden, total, index }
 * POST { action: 'dismiss', key } -> { success }  hide one finding from now on
 * POST { action: 'reset' }        -> { success }  show every finding again
 *
 * Each finding's names carry how many indexed files use that spelling in a
 * cast tail (plus a few sample paths): the drive index only covers its roots,
 * so 0 means "not in the indexed folders", not "unused anywhere".
 *
 * 'run' is read-only. The writes go to server/cast_audit_dismissed.json only;
 * fixing a name goes through castNamesManage.php like any other edit.
 */

require_once __DIR__ . '/cast_audit_lib.php';
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

// Only 'run' is read-only. A custom header forces a CORS preflight a hostile
// page won't be granted, killing blind cross-site POSTs (see driveIndex.php).
if ($action !== 'run' && empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Missing X-Requested-With header']);
    exit();
}

switch ($action) {
    case 'run':
        $names = moviedb_load_cast_store();
        $audit = moviedb_cast_audit($names, moviedb_cast_audit_load_dismissed());
        $index = moviedb_load_drive_index();
        $usage = moviedb_cast_audit_usage($index['entries'] ?? []);
        $findings = array_map(function (array $finding) use ($usage): array {
            $finding['names'] = array_map(function (string $name) use ($usage): array {
                $use = $usage[mb_strtolower($name)] ?? [];
                return ['name' => $name, 'uses' => $use['count'] ?? 0, 'files' => $use['files'] ?? []];
            }, $finding['names']);
            return $finding;
        }, $audit['findings']);
        echo json_encode([
            'findings' => $findings,
            'hidden' => $audit['hidden'],
            'total' => count($names),
            'index' => $index === null ? null : [
                'builtAt' => $index['builtAt'] ?? null,
                'roots' => $index['roots'] ?? [],
                'fileCount' => count($index['entries']),
            ],
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'dismiss':
        $key = is_string($data['key'] ?? null) ? $data['key'] : '';
        if ($key === '' || mb_strlen($key) > 2000 || !preg_match('/^(duplicate|variant|junk|male)\|/', $key)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Not a finding key']);
            break;
        }
        $keys = moviedb_cast_audit_load_dismissed();
        $keys[] = $key;
        if (!moviedb_cast_audit_save_dismissed($keys)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Could not save the dismissal']);
            break;
        }
        echo json_encode(['success' => true]);
        break;

    case 'reset':
        if (!moviedb_cast_audit_save_dismissed([])) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Could not reset dismissals']);
            break;
        }
        echo json_encode(['success' => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
}
