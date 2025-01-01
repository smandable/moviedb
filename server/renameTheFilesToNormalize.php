<?php

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['error' => 'Only POST requests are allowed']);
    exit();
}

// Get the raw POST data
$data = json_decode(file_get_contents('php://input'), true);

// Validate the input
if (!isset($data['files']) || !is_array($data['files'])) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Files data is required']);
    exit();
}

$files = $data['files'];
$results = [];

foreach ($files as $file) {
    if (!isset($file['path'], $file['originalFileName'], $file['newFileName'])) {
        $results[] = [
            'originalFileName' => $file['originalFileName'] ?? 'Unknown',
            'newFileName' => $file['newFileName'] ?? 'Unknown',
            'status' => 'Missing data',
        ];
        continue;
    }

    $path = rtrim($file['path'], '/');
    $originalPath = $path . "/" . $file['originalFileName'];
    $newPath = $path . "/" . $file['newFileName'];

    // Check if the original file exists
    if (!file_exists($originalPath)) {
        $results[] = [
            'originalFileName' => $file['originalFileName'],
            'newFileName' => $file['newFileName'],
            'status' => 'Original file not found',
        ];
        continue;
    }

    // Check if the new file name already exists
    if (file_exists($newPath)) {
        $results[] = [
            'originalFileName' => $file['originalFileName'],
            'newFileName' => $file['newFileName'],
            'status' => 'New file name already exists',
        ];
        continue;
    }

    // Attempt to rename the file
    if (rename($originalPath, $newPath)) {
        $results[] = [
            'originalFileName' => $file['originalFileName'],
            'newFileName' => $file['newFileName'],
            'status' => 'Renamed successfully',
        ];
    } else {
        $results[] = [
            'originalFileName' => $file['originalFileName'],
            'newFileName' => $file['newFileName'],
            'status' => 'Failed to rename',
        ];
    }
}

echo json_encode(['results' => $results]);
