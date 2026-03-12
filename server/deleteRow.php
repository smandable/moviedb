<?php
require 'db_connect.php';

// Decode JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['id']) || empty(trim($input['id']))) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Id is required.']);
    exit;
}

$id = intval(trim($input['id']));

try {
    // Check if the ID exists in the database using prepared statement
    $idCheckStmt = $db->prepare("SELECT id FROM `$table` WHERE id = ?");
    $idCheckStmt->bind_param('i', $id);
    $idCheckStmt->execute();
    $idCheckResult = $idCheckStmt->get_result();

    if ($idCheckResult->num_rows === 0) {
        http_response_code(404); // Not Found
        echo json_encode(['error' => 'Record with the specified ID does not exist.']);
        $idCheckStmt->close();
        exit;
    }
    $idCheckStmt->close();

    // Attempt to delete the record using prepared statement
    $deleteStmt = $db->prepare("DELETE FROM `$table` WHERE id = ?");
    $deleteStmt->bind_param('i', $id);

    if ($deleteStmt->execute()) {
        echo json_encode(['success' => true, 'message' => "Successfully deleted record with ID: $id"]);
    } else {
        http_response_code(500); // Internal Server Error
        echo json_encode(['error' => 'Failed to delete record.', 'details' => $deleteStmt->error]);
    }
    $deleteStmt->close();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'An error occurred.', 'details' => $e->getMessage()]);
} finally {
    $db->close();
}

