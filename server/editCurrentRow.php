<?php
// Include the database connection and configuration
require 'db_connect.php';
$config = require 'config.php'; // Load configuration

// Retrieve the table name from the configuration
$table = isset($config->table) ? $config->table : 'movies_het';

// Define allowed fields and their corresponding data types
$allowedFields = [
    'title'      => 's', // string
    'dimensions' => 's', // string
    'duration'   => 'i', // integer
    'filesize'   => 'i', // integer
    // Add other fields as necessary
];

try {
    // Decode the incoming JSON payload
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Retrieve and sanitize the ID
    $id = isset($input['id']) ? (int)$input['id'] : null;

    // Check for "field" and "value" (cell editing)
    if (isset($input['field']) && array_key_exists('value', $input)) {
        $field = $input['field'];
        $value = $input['value'];

        // Validate input: Ensure 'id', 'field' are present and 'field' is allowed
        if (is_null($id) || empty($field) || !array_key_exists($field, $allowedFields)) {
            throw new Exception('Invalid input for cell editing.');
        }

        // Handle null values based on field type
        if (is_null($value)) {
            if ($allowedFields[$field] === 'i') {
                $value = 0; // Default for integer fields
            } else {
                $value = ''; // Default for string fields
            }
        }

        // Determine the type for bind_param
        $type = $allowedFields[$field];

        // Prepare the SQL statement
        $query = "UPDATE `$table` SET `$field` = ? WHERE id = ?";
        $stmt = $db->prepare($query);

        if (!$stmt) {
            throw new Exception('Failed to prepare statement: ' . $db->error);
        }

        // Bind parameters based on the determined type
        if ($type === 'i') {
            $stmt->bind_param('ii', $value, $id);
        } else {
            $stmt->bind_param('si', $value, $id);
        }

        // Execute the statement
        if (!$stmt->execute()) {
            throw new Exception('Failed to execute statement: ' . $stmt->error);
        }

        // Close the statement
        $stmt->close();

        // Respond with success
        echo json_encode(['success' => true, 'message' => 'Cell updated successfully.']);
        exit();
    }

    // Check for "updateFields" (Update DB button)
    if (isset($input['updateFields']) && is_array($input['updateFields'])) {
        $updateFields = $input['updateFields'];

        if (is_null($id)) {
            throw new Exception('ID is required for updating fields.');
        }

        $updates = [];
        $values = [];
        $types = '';

        foreach ($updateFields as $field => $value) {
            // Validate each field
            if (!array_key_exists($field, $allowedFields)) {
                throw new Exception('Invalid field name: ' . htmlspecialchars($field));
            }

            // Handle null values based on field type
            if (is_null($value)) {
                if ($allowedFields[$field] === 'i') {
                    $value = 0; // Default for integer fields
                } else {
                    $value = ''; // Default for string fields
                }
            }

            // Append to updates and values arrays
            $updates[] = "`$field` = ?";
            $values[] = $value;
            $types .= $allowedFields[$field];
        }

        // Append the 'id' for the WHERE clause
        $values[] = $id;
        $types .= 'i';

        // Prepare the SQL statement
        $query = "UPDATE `$table` SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $db->prepare($query);

        if (!$stmt) {
            throw new Exception('Failed to prepare statement: ' . $db->error);
        }

        // Bind all parameters
        $stmt->bind_param($types, ...$values);

        // Execute the statement
        if (!$stmt->execute()) {
            throw new Exception('Failed to execute statement: ' . $stmt->error);
        }

        // Close the statement
        $stmt->close();

        // Respond with success
        echo json_encode(['success' => true, 'message' => 'Row updated successfully.']);
        exit();
    }

    // If neither condition is met, throw an exception
    throw new Exception('Invalid input format.');
} catch (Exception $e) {
    // Respond with an error message
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
