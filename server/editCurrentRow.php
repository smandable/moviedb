<?php
require 'db_connect.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? null;

    // Check for "field" and "value" (cell editing)
    if (isset($input['field']) && isset($input['value'])) {
        $field = $input['field'];
        $value = $input['value'];

        if (!$id || !$field || !isset($value)) {
            throw new Exception('Invalid input for cell editing.');
        }

        $query = "UPDATE `$table` SET `$field` = ? WHERE id = ?";
        $stmt = $db->prepare($query);

        if (!$stmt) {
            throw new Exception('Failed to prepare statement: ' . $db->error);
        }

        $stmt->bind_param('si', $value, $id);

        if (!$stmt->execute()) {
            throw new Exception('Failed to execute statement: ' . $stmt->error);
        }

        echo json_encode(['success' => true, 'message' => 'Cell updated successfully.']);
        exit();
    }

    // Check for "updateFields" (Update DB button)
    $updateFields = $input['updateFields'] ?? null;

    if ($id && $updateFields && is_array($updateFields)) {
        $updates = [];
        $values = [];
        foreach ($updateFields as $field => $value) {
            $updates[] = "`$field` = ?";
            $values[] = $value;
        }
        $values[] = $id; // Add ID for the WHERE clause

        $query = "UPDATE `$table` SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $db->prepare($query);

        if (!$stmt) {
            throw new Exception('Failed to prepare statement: ' . $db->error);
        }

        $types = str_repeat('s', count($updateFields)) . 'i'; // 's' for strings, 'i' for ID
        $stmt->bind_param($types, ...$values);

        if (!$stmt->execute()) {
            throw new Exception('Failed to execute statement: ' . $stmt->error);
        }

        echo json_encode(['success' => true, 'message' => 'Row updated successfully.']);
        exit();
    }

    throw new Exception('Invalid input format.');
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
