<?php

/**
 * App settings persisted server-side (server/app_settings.json, gitignored)
 * so they survive browser-data clears and apply to any browser.
 *
 * GET                          -> { settings: {...} }
 * POST { defaultDirectory }    -> { settings: {...}, directoryExists: bool }
 *
 * Only whitelisted keys are stored. defaultDirectory is validated against the
 * ALLOWED_BASE_PATH guard; existence is reported but not required, because an
 * unmounted external volume is a normal state on this machine.
 */

require_once __DIR__ . '/path_guard.php';

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
