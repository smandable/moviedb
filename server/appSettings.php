<?php

/**
 * App settings persisted server-side (server/app_settings.json, gitignored)
 * so they survive browser-data clears and apply to any browser.
 *
 * GET                                  -> { settings: {...} }
 * POST { defaultDirectory }            -> { settings: {...}, directoryExists: bool }
 * POST { driveIndexRoots }             -> { settings: {...} }
 * POST { consolidate }                 -> { settings: {...} }
 * POST { moveRenamedUpFromNeedsCast }  -> { settings: {...} }
 *
 * Only whitelisted keys are stored. defaultDirectory, each driveIndexRoots
 * entry, and each consolidate drive must pass the ALLOWED_BASE_PATH guard
 * (whose realpath check means the path must exist while the guard is on — so
 * mount a volume before saving a root on it). directoryExists is
 * informational only. driveIndexRoots overrides the drive-index build roots
 * (see server/drive_index_lib.php); consolidate configures
 * scripts/consolidate_movies.php (see server/consolidate_lib.php);
 * moveRenamedUpFromNeedsCast is a plain boolean, the needs-cast move-up
 * toggle read by renameTheFilesToNormalize.php (absent means ON — see
 * server/rename_helpers.php).
 */

require_once __DIR__ . '/path_guard.php';
require_once __DIR__ . '/drive_index_lib.php';
require_once __DIR__ . '/consolidate_lib.php';

ini_set('display_errors', '0');
header('Content-Type: application/json');

const MOVIEDB_SETTINGS_FILE = __DIR__ . '/app_settings.json';

function moviedb_load_settings(): array
{
    if (!is_file(MOVIEDB_SETTINGS_FILE)) {
        return [];
    }
    $raw = @file_get_contents(MOVIEDB_SETTINGS_FILE);
    $data = $raw === false ? null : json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function moviedb_save_settings(array $settings): bool
{
    // Write-then-rename so a failed write can't truncate the settings file
    $tmp = MOVIEDB_SETTINGS_FILE . '.tmp';
    $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (@file_put_contents($tmp, $json) === false) {
        return false;
    }
    return @rename($tmp, MOVIEDB_SETTINGS_FILE);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // (object) so an empty settings map serializes as {} rather than []
    echo json_encode(['settings' => (object)moviedb_load_settings()]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only GET and POST requests are allowed']);
    exit();
}

// Custom-header gate: a blind cross-site POST can't set this without a CORS
// preflight the browser won't grant (see driveIndex.php for the rationale)
if (empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Missing X-Requested-With header']);
    exit();
}

$data = json_decode(file_get_contents('php://input') ?: '', true);
$data = is_array($data) ? $data : [];

$settings = moviedb_load_settings();
$directoryExists = null;

if (array_key_exists('defaultDirectory', $data)) {
    $dir = $data['defaultDirectory'];
    if (!is_string($dir) || trim($dir) === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'defaultDirectory must be a non-empty string']);
        exit();
    }
    $dir = rtrim(trim($dir), '/') . '/';
    if (!moviedb_is_path_allowed(rtrim($dir, '/'))) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'defaultDirectory is outside the allowed base path']);
        exit();
    }
    $settings['defaultDirectory'] = $dir;
    $directoryExists = is_dir($dir);
}

if (array_key_exists('driveIndexRoots', $data)) {
    $roots = $data['driveIndexRoots'];
    if (!is_array($roots) || count($roots) < 1 || count($roots) > 20) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'driveIndexRoots must be an array of 1 to 20 paths']);
        exit();
    }
    $cleanRoots = [];
    foreach ($roots as $root) {
        if (!is_string($root) || trim($root) === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'driveIndexRoots entries must be non-empty strings']);
            exit();
        }
        $root = rtrim(trim($root), '/');
        if ($root === '' || $root[0] !== '/' || !moviedb_is_path_allowed($root)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => "driveIndexRoots entry is not an allowed absolute path: $root",
            ]);
            exit();
        }
        // Deeper hardening (base itself, "/" via symlink, control chars):
        $rootError = moviedb_validate_drive_root($root);
        if ($rootError !== null) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => "driveIndexRoots entry rejected ($root): $rootError",
            ]);
            exit();
        }
        $cleanRoots[] = $root;
    }
    $settings['driveIndexRoots'] = $cleanRoots;
}

if (array_key_exists('consolidate', $data)) {
    // Exactly six keys; drives validated like driveIndexRoots; balanceTo must
    // be one of drives; GB values ints 0..20000; balance/recursive booleans.
    $check = moviedb_validate_consolidate_settings($data['consolidate']);
    if (!$check['ok']) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $check['error']]);
        exit();
    }
    $settings['consolidate'] = $check['clean'];
}

if (array_key_exists('moveRenamedUpFromNeedsCast', $data)) {
    if (!is_bool($data['moveRenamedUpFromNeedsCast'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'moveRenamedUpFromNeedsCast must be a boolean']);
        exit();
    }
    $settings['moveRenamedUpFromNeedsCast'] = $data['moveRenamedUpFromNeedsCast'];
}

if (!moviedb_save_settings($settings)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to write settings file']);
    exit();
}

echo json_encode([
    'success' => true,
    'settings' => (object)$settings,
    'directoryExists' => $directoryExists,
]);
