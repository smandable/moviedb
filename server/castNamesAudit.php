<?php

/**
 * Settings → Cast Name Vocabulary → "Check for junk & duplicates". Audits
 * server/cast_names.json (see cast_audit_lib.php) and remembers the findings
 * Sean has looked at and kept.
 *
 * POST { action: 'run' }          -> { findings: [...], hidden, total, index }
 * POST { action: 'dismiss', key } -> { success }  hide one finding from now on
 * POST { action: 'reset' }        -> { success }  show every finding again
 * POST { action: 'respellPreview', from: [...], to }
 *                                 -> { files: [{path, dir, file, newFile, conflict}] }
 * POST { action: 'respell', from: [...], to, files: [paths] }
 *                                 -> { results, renamed, failed, notPreviewed,
 *                                      indexUpdated, names, removed }
 *
 * 'respell' renames the previewed files whose cast tail carries a "from"
 * spelling to "to" (cast_respell_lib.php), then — only once no indexed file
 * still carries one — drops the "from" spellings from the vocabulary.
 *
 * Each finding's names carry how many indexed files use that spelling in a
 * cast tail (plus a few sample paths): the drive index only covers its roots,
 * so 0 means "not in the indexed folders", not "unused anywhere".
 *
 * 'run' and 'respellPreview' only read; every action but 'run' needs the
 * X-Requested-With header anyway.
 */

require_once __DIR__ . '/cast_audit_lib.php';
require_once __DIR__ . '/cast_respell_lib.php';
require_once __DIR__ . '/drive_index_lib.php';

/**
 * The respelling request's names: 'from' as a list of distinct non-empty
 * strings other than 'to', and 'to' cleaned the store's way. null when
 * unusable.
 */
function moviedb_cast_respell_request(array $data): ?array
{
    $to = moviedb_clean_cast_name(is_string($data['to'] ?? null) ? $data['to'] : '');
    $from = [];
    foreach (is_array($data['from'] ?? null) ? $data['from'] : [] as $name) {
        if (is_string($name) && trim($name) !== '' && $name !== $to) {
            $from[] = $name;
        }
    }
    $from = array_values(array_unique($from));
    if ($to === '' || $from === [] || count($from) > 20) {
        return null;
    }
    return ['from' => $from, 'to' => $to];
}

/** True while scripts/consolidate_movies.php holds its lock (it moves files). */
function moviedb_cast_respell_consolidating(): bool
{
    $fh = @fopen(__DIR__ . '/consolidate_progress.json.lock', 'c');
    if ($fh === false) {
        return false;
    }
    $free = flock($fh, LOCK_EX | LOCK_NB);
    if ($free) {
        flock($fh, LOCK_UN);
    }
    fclose($fh);
    return !$free;
}

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

    case 'respellPreview':
        $request = moviedb_cast_respell_request($data);
        if ($request === null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Need the spellings to replace and the one to use']);
            break;
        }
        $index = moviedb_load_drive_index();
        echo json_encode([
            'files' => moviedb_cast_respell_plan($index['entries'] ?? [], $request['from'], $request['to']),
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'respell':
        $request = moviedb_cast_respell_request($data);
        if ($request === null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Need the spellings to replace and the one to use']);
            break;
        }
        if (moviedb_cast_respell_consolidating()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'A consolidation is moving files — try again when it finishes']);
            break;
        }
        // Renames wait on sleeping drives to spin up — several can outlast the
        // 30 s default, which killed one respell after its first rename,
        // before the index and the list were saved. Finish even if the page
        // gives up waiting.
        set_time_limit(600);
        ignore_user_abort(true);
        $approved = array_values(array_filter(is_array($data['files'] ?? null) ? $data['files'] : [], 'is_string'));
        $outcome = moviedb_cast_respell_apply($request['from'], $request['to'], $approved);
        if (isset($outcome['error'])) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => $outcome['error']]);
            break;
        }
        // Drop the old spellings only when no indexed file still carries one;
        // otherwise Add Cast would just bring them back from those files.
        $names = moviedb_load_cast_store();
        $removed = false;
        if ($outcome['failed'] === 0 && $outcome['notPreviewed'] === 0 && $outcome['indexUpdated']) {
            foreach ($request['from'] as $old) {
                $names = moviedb_rename_cast_name($names, $old, $request['to']);
            }
            $names = moviedb_save_cast_store($names);
            $removed = true;
        }
        echo json_encode($outcome + ['names' => $names, 'removed' => $removed], JSON_UNESCAPED_UNICODE);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
}
