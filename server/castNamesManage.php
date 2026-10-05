<?php

/**
 * CRUD for the cast-name vocabulary store (server/cast_names.json) — the
 * Settings page's editor. The autocomplete union endpoint is castNames.php;
 * this one manages the persisted store only.
 *
 * POST { action: 'list' }                      -> { names: [...], blocked: [...] }
 * POST { action: 'add', name }                 -> { names, blocked, added }
 * POST { action: 'rename', name, newName }     -> { names, blocked, renamed }
 * POST { action: 'delete', name }              -> { names, blocked, deleted }
 * POST { action: 'deleteMany', names: [...] }  -> { names, blocked, deleted: n }
 * POST { action: 'restore', name }             -> { names, blocked, restored }
 *
 * Deleting is permanent: the name joins the blocklist
 * (server/cast_names_blocked.json), which every store write honours, so
 * filenames that still use it can't bring it back. A rename blocks the old
 * spelling the same way. Adding a blocked name by hand, or 'restore', takes
 * it off the blocklist again.
 *
 * Names pass through moviedb_clean_cast_name (whitespace/punctuation cleanup +
 * homoglyph folding) so hand-typed entries obey the same hygiene as harvested
 * ones. Matching for rename/delete is case-insensitive, mirroring the store's
 * dedupe rule.
 *
 * 'rename' additionally OVERRIDES the casing of an existing match, so casing can
 * be fixed by renaming onto an entry — see moviedb_rename_cast_name(). The merge
 * path (moviedb_save_cast_store) stays first-wins on purpose; only this explicit
 * human edit is allowed to recase what is already stored. 'add' is first-wins
 * too (it's a blind insert — the typist may not know the name is stored), so it
 * reports back the spelling the store kept rather than the one typed.
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

// Only 'list' is read-only. A custom header forces a CORS preflight a hostile
// page won't be granted, killing blind cross-site POSTs (see driveIndex.php).
if ($action !== 'list' && empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Missing X-Requested-With header']);
    exit();
}

$names = moviedb_load_cast_store();

/** The response body every action shares: the store and the blocklist. */
function moviedb_cast_manage_body(array $names, array $extra = []): string
{
    return json_encode(['names' => $names, 'blocked' => moviedb_load_cast_blocklist()] + $extra);
}

switch ($action) {
    case 'list':
        echo moviedb_cast_manage_body($names);
        break;

    case 'add':
        $raw = is_string($data['name'] ?? null) ? $data['name'] : '';
        $clean = moviedb_clean_cast_name($raw);
        if ($clean === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Not a usable name']);
            break;
        }
        // Typing a blocked name back in is the way to un-delete it
        moviedb_unblock_cast_name($clean);
        $names = moviedb_save_cast_store(moviedb_add_cast_name($names, $raw));
        // Echo the spelling the STORE holds, not the one that was typed. Adding
        // a name that already exists in another casing keeps the stored spelling
        // (add isn't authoritative about casing — rename is), and the old code
        // echoed the typed casing anyway, so the Settings page said
        // 'Added "Marla Vex"' beside a list still reading "marla vex".
        echo moviedb_cast_manage_body($names, [
            'added' => moviedb_stored_cast_name($names, $clean) ?: $clean,
        ]);
        break;

    case 'rename':
        $old = is_string($data['name'] ?? null) ? $data['name'] : '';
        $newRaw = is_string($data['newName'] ?? null) ? $data['newName'] : '';
        $clean = moviedb_clean_cast_name($newRaw);
        if ($old === '' || $clean === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Not a usable name']);
            break;
        }
        // The list logic lives in cast_helpers.php so it is unit-testable; a
        // rename deliberately overrides the casing of an existing match. Pass
        // the RAW name so the stored name and the 'renamed' echoed below are
        // the same moviedb_clean_cast_name() call applied to the same input.
        $names = moviedb_rename_cast_name($names, $old, $newRaw);
        // The new spelling is wanted; the old one stays gone (a casing fix
        // renames onto the same name, which must not block itself)
        moviedb_unblock_cast_name($clean);
        if (mb_strtolower($old) !== mb_strtolower($clean)) {
            moviedb_block_cast_names([$old]);
        }
        echo moviedb_cast_manage_body(moviedb_save_cast_store($names), ['renamed' => $clean]);
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
        moviedb_block_cast_names([moviedb_stored_cast_name($names, $target) ?: $target]);
        echo moviedb_cast_manage_body(moviedb_save_cast_store($remaining), ['deleted' => $deleted]);
        break;

    case 'deleteMany':
        $targets = array_values(array_filter(
            is_array($data['names'] ?? null) ? $data['names'] : [],
            fn($n) => is_string($n) && trim($n) !== ''
        ));
        if ($targets === [] || count($targets) > 500) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing names']);
            break;
        }
        $remaining = $names;
        $blocked = [];
        foreach ($targets as $target) {
            $blocked[] = moviedb_stored_cast_name($names, $target) ?: $target;
            $remaining = moviedb_remove_name($remaining, $target);
        }
        moviedb_block_cast_names($blocked);
        echo moviedb_cast_manage_body(moviedb_save_cast_store($remaining), [
            'deleted' => count($names) - count($remaining),
        ]);
        break;

    case 'restore':
        $target = is_string($data['name'] ?? null) ? $data['name'] : '';
        $blockedName = moviedb_stored_cast_name(moviedb_load_cast_blocklist(), $target);
        if ($blockedName === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Not a blocked name']);
            break;
        }
        moviedb_unblock_cast_name($blockedName);
        $names = moviedb_save_cast_store(moviedb_add_cast_name($names, $blockedName));
        echo moviedb_cast_manage_body($names, ['restored' => $blockedName]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
}
