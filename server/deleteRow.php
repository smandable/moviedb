<?php
require 'db_connect.php';

if (!isset($_POST['id']) || empty(trim($_POST['id']))) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Id is required.']);
    exit;
}

$id = $db->real_escape_string(trim($_POST['id']));

// Check if the ID exists in the database
$idCheckQuery = "SELECT id FROM `" . $table . "` WHERE id='$id'";
$idCheckResult = $db->query($idCheckQuery);

if (!$idCheckResult || $idCheckResult->num_rows === 0) {
    http_response_code(404); // Not Found
    echo json_encode(['error' => 'Record with the specified ID does not exist.']);
    exit;
}

// Attempt to delete the record
$deleteQuery = "DELETE FROM `" . $table . "` WHERE id='$id'";
if ($db->query($deleteQuery)) {
    echo json_encode(['success' => true, 'message' => "Successfully deleted record with ID: $id"]);
} else {
    http_response_code(500); // Internal Server Error
    echo json_encode(['error' => 'Failed to delete record.', 'details' => $db->error]);
}

$db->close();
