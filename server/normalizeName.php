<?php

// Normalize a single file base name and return the result. This is the live
// preview source for the rename modal — the SAME normalizeFileBaseName()
// pipeline that checkFileNamesToNormalize.php uses, so the preview always
// matches what a rename will actually produce (single source of truth).

header('Content-Type: application/json');

require_once __DIR__ . '/normalize_helpers.php';

$input = json_decode(file_get_contents('php://input') ?: '', true);
$name = is_array($input) ? (string)($input['name'] ?? '') : '';

// When the user has hand-edited the name, preserve their casing choices.
$respectUserCasing = is_array($input) ? !empty($input['respectUserCasing']) : false;

// Defensive cap — base names are short; avoid pathological regex input.
if (function_exists('mb_substr') && mb_strlen($name) > 1000) {
    $name = mb_substr($name, 0, 1000);
}

echo json_encode(['normalized' => normalizeFileBaseName($name, $respectUserCasing)]);
