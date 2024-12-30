<?php

require 'safe_json_encode.php';
require 'db_connect.php';

try {
    // Query to fetch records ordered by the creation date
    $query = "SELECT id, title, dimensions, filesize, duration, filepath, date_created FROM `" . $table . "` ORDER BY date_created DESC";
    $result = $db->query($query);

    // Check for query success
    if (!$result) {
        throw new Exception("Database query failed: " . $db->error);
    }

    $data = [];

    // Process the query result
    while ($row = $result->fetch_assoc()) {
        $data[] = [
            'id' => $row['id'],
            'title' => $row['title'],
            'dimensions' => $row['dimensions'],
            'duration' => $row['duration'],
            'filesize' => $row['filesize'],
            'filepath' => $row['filepath'],
            'date_created' => $row['date_created'],
        ];
    }

    // Set appropriate headers for JSON response
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
    header('Content-Type: application/json');

    // Output the result as a flat array
    echo safe_json_encode($data);
} catch (Exception $e) {
    // Handle exceptions and return an error message
    http_response_code(500);
    echo safe_json_encode(['error' => true, 'message' => $e->getMessage()]);
} finally {
    // Ensure the database connection is closed
    $db->close();
}
