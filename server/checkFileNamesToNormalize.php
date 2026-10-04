<?php
// Pull in shared normalization helpers
require_once __DIR__ . '/normalize_helpers.php';
require_once __DIR__ . '/path_guard.php';
require_once __DIR__ . '/drive_index_lib.php'; // MOVIEDB_DRIVE_INDEX_VIDEO_EXTS

// Keep PHP warnings/notices out of the JSON response body.
// They still go to the error log; they just don't get echoed to the client.
ini_set('display_errors', '0');

// Start output buffering to prevent premature output
ob_start();

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'message' => 'Only POST requests are allowed']);
    exit();
}

// Get the raw POST data
$data = json_decode(file_get_contents('php://input'), true);

// Validate the input
if (!isset($data['directory']) || empty($data['directory'])) {
    http_response_code(400); // Bad Request
    echo json_encode(['success' => false, 'message' => 'Directory path is required']);
    exit();
}

$directory = rtrim($data['directory'], '/');

// Check if the directory exists
if (!is_dir($directory)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid directory path']);
    exit();
}

// Reject any directory that resolves outside the allowed base path.
moviedb_reject_path($directory);

try {
    // videoOnly (the Settings page): list video files only, the same types
    // the drive index catalogs — library folders also hold scripts and notes.
    $onlyExtensions = !empty($data['videoOnly']) ? MOVIEDB_DRIVE_INDEX_VIDEO_EXTS : null;
    // recursive (the Settings page) walks subfolders too, skipping
    // duplicates/ and needs-cast/ (moviedb_scan_names_to_normalize)
    $normalizedFiles = moviedb_scan_names_to_normalize($directory, $onlyExtensions, !empty($data['recursive']));
    if ($normalizedFiles === null) {
        ob_clean();
        http_response_code(500);
        $err = error_get_last();
        $detail = $err['message'] ?? 'unknown error';
        // macOS TCC: httpd lacks Full Disk Access for /Volumes/*.
        // Grant it in System Settings → Privacy & Security → Full Disk Access
        // (add /opt/homebrew/opt/httpd/bin/httpd, then restart httpd).
        echo json_encode([
            'success' => false,
            'message' => "Cannot read directory \"$directory\": $detail",
        ]);
        exit();
    }

    // Set appropriate headers for JSON response
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
    header('Content-Type: application/json');

    // Output the result as a flat array
    echo json_encode(['files' => $normalizedFiles]);
} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'File processing error: ' . $e->getMessage()]);
}

// End output buffering and send output
ob_end_flush();
