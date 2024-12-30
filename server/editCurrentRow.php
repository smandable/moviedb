<?php

require 'db_connect.php';

try {
    // 1. Validate basic inputs
    if (empty($_POST['id']) || empty(trim($_POST['id']))) {
        throw new Exception('ID is required.', 400);
    }

    if (empty($_POST['columnToUpdate']) || empty(trim($_POST['columnToUpdate']))) {
        throw new Exception('Column to update is required.', 400);
    }

    if (!isset($_POST['valueToUpdate'])) {
        throw new Exception('Value to update is required.', 400);
    }

    // 2. Extract and sanitize inputs
    $id = trim($_POST['id']);
    $columnToUpdate = trim($_POST['columnToUpdate']);
    // error_log("Received columnToUpdate: $columnToUpdate");
    $valueToUpdate = trim($_POST['valueToUpdate']);

    // 3. Map user-friendly column names to actual DB columns
    //    Adjust as needed if your DB column names differ.
    $columnMap = [
        'title'      => 'title',
        'dimensions' => 'dimensions',
        'filesize'  => 'filesize',
        'duration'   => 'duration',
    ];

    if (!array_key_exists($columnToUpdate, $columnMap)) {
        throw new Exception('Invalid column to update.', 400);
    }

    $dbColumn = $columnMap[$columnToUpdate];

    // Optional: If the column is "filesize", remove commas or apply custom formatting
    if ($dbColumn === 'filesize') {
        // Example: remove commas to keep numeric values consistent
        $valueToUpdate = preg_replace('/,/', '', $valueToUpdate);
    }

    // 4. Validate record existence
    $stmt = $db->prepare("SELECT id FROM `" . $table . "` WHERE id = ?");
    if (!$stmt) {
        throw new Exception('Failed to prepare statement: ' . $db->error, 500);
    }
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();

    if (!$result || $result->num_rows === 0) {
        throw new Exception('Record not found.', 404);
    }

    // 5. Prepare and execute the UPDATE query using a prepared statement
    $updateStmt = $db->prepare("UPDATE `" . $table . "` SET $dbColumn = ? WHERE id = ?");
    if (!$updateStmt) {
        throw new Exception('Failed to prepare update statement: ' . $db->error, 500);
    }
    $updateStmt->bind_param("si", $valueToUpdate, $id);
    $queryResult = $updateStmt->execute();

    if (!$queryResult) {
        throw new Exception('Failed to update record: ' . $db->error, 500);
    }

    // 6. Success response
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'Record updated successfully.'
    ]);

} catch (Exception $e) {
    // 7. Error handling with appropriate HTTP status codes
    $statusCode = $e->getCode() ?: 500;
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
} finally {
    // 8. Close the database connection
    $db->close();
}
