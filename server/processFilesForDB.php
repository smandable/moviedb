<?php
// Increase the max execution time as needed
ini_set('max_execution_time', 0);

require 'formatSize.php';
require 'db_connect.php';
$config = require 'config.php'; // Load configuration

// Retrieve the flag from config
$updateMissingDataOnly = isset($config->updateMissingDataOnly) ? (bool)$config->updateMissingDataOnly : false;

// Validate Input
$input = json_decode(file_get_contents('php://input'), true);
$directory = isset($input['directory']) ? trim($input['directory']) : '';

if (empty($directory)) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Directory is required.']);
    exit();
}

// Check if the directory exists
if (!is_dir($directory)) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Invalid directory path.']);
    exit();
}

// Retrieve all files from the directory, excluding hidden files
$files = array_diff(scandir($directory), ['..', '.']);

$sessionFiles = [];

foreach ($files as $file) {
    // Skip hidden files
    if (substr($file, 0, 1) === '.') continue;

    $filePath = rtrim($directory, '/') . '/' . $file;

    if (is_file($filePath)) {
        $fileInfo = pathinfo($filePath);
        $fileNameNoExtension = $fileInfo['filename'];
        $fileExtension = isset($fileInfo['extension']) ? strtolower($fileInfo['extension']) : '';

        // Initialize metadata
        $fileSize = filesize($filePath);
        $fileDimensions = '';
        $fileDuration = 0; // Default to 0 seconds

        // Extract dimensions and duration based on file type
        if (in_array($fileExtension, ['mp4', 'mkv', 'avi', 'mov', 'wmv', 'mpg', 'm4v', 'divx'])) {
            // For videos, use FFmpeg to get dimensions and duration
            $ffmpegOutput = [];
            $ffprobeCmd = "ffprobe -v error -print_format json -show_entries format=duration -show_entries stream=width,height \"$filePath\"";
            $ffprobeOutput = shell_exec($ffprobeCmd);
            // error_log("FFprobe raw output for $filePath: " . print_r($ffprobeOutput, true));

            // Check if ffprobe executed successfully
            if ($ffprobeOutput === null) {
                error_log("FFprobe command failed for file: $filePath");
                continue; // Skip processing this file
            }

            // Decode JSON output
            $ffprobeData = json_decode($ffprobeOutput, true);

            // Check if JSON decoding was successful
            if ($ffprobeData === null) {
                error_log("Failed to decode FFprobe JSON output for file: $filePath");
                continue; // Skip processing this file
            }

            // Extract duration from format
            if (isset($ffprobeData['format']['duration'])) {
                $duration = floatval($ffprobeData['format']['duration']);
                if ($duration > 0) {
                    $fileDuration = (int)$duration; // Store as integer seconds
                } else {
                    error_log("FFprobe returned non-positive duration for file: $filePath");
                }
            } else {
                error_log("Duration not found in FFprobe output for file: $filePath");
            }

            // Extract dimensions from the first video stream
            if (isset($ffprobeData['streams']) && is_array($ffprobeData['streams'])) {
                foreach ($ffprobeData['streams'] as $streamIndex => $stream) {
                    // error_log("Processing stream $streamIndex: " . print_r($stream, true));

                    if (isset($stream['width'], $stream['height'])) {
                        $width = $stream['width'] ?? 0;
                        $height = $stream['height'] ?? 0;

                        if ($width > 0 && $height > 0) {
                            $fileDimensions = $width . ' x ' . $height;
                            // error_log("File dimensions set to: $fileDimensions");
                        } else {
                            error_log("Invalid dimensions found in stream $streamIndex");
                        }

                        break; // Exit after processing the first stream with dimensions
                    } else {
                        error_log("Stream $streamIndex is not a video stream in this file: $filePath");
                    }
                }
            }
        }

        // Add to sessionFiles array
        $sessionFiles[] = [
            'fileNameNoExtension' => $fileNameNoExtension,
            'fileSize' => $fileSize,
            'fileDimensions' => $fileDimensions,
            'fileDuration' => $fileDuration,
            'fileNameAndPath' => $filePath
        ];
    }
}

// Process titles
$titlesArray = populateTitlesArray($sessionFiles);

// Arrays to hold different types of duplicates and missing entries
$duplicateTitlesArray = [];
$duplicateTitlesMissing01Array = [];
$titlesMissing01Array = [];

foreach ($titlesArray as &$titleItem) {
    // error_log("Main Script - Processing titleItem: " . print_r($titleItem, true));

    $titleItem = checkDatabaseForTitle(
        $titleItem,
        $duplicateTitlesArray,
        $duplicateTitlesMissing01Array,
        $titlesMissing01Array,
        $db,
        $config->table,
        $updateMissingDataOnly
    );
}
unset($titleItem); // break reference

// Perform rename operations after database checks
$pathMap = renameSessionFilesAddMissing01(
    $titlesMissing01Array,
    $duplicateTitlesMissing01Array,
    $sessionFiles
);

// Update the titlePath in titlesArray so the UI points at the renamed file
if (!empty($pathMap)) {
    foreach ($titlesArray as &$t) {
        if (!empty($t['titlePath']) && isset($pathMap[$t['titlePath']])) {
            $t['titlePath'] = $pathMap[$t['titlePath']];
        }
    }
    unset($t);
}

// Conditionally perform renaming or updating based on the flag
if (!$updateMissingDataOnly) {
    // Search session for duplicate files and move them accordingly only if not updating missing data
    searchSessionForDuplicateFiles($duplicateTitlesArray, $sessionFiles);
} else {
    // If updating missing data, skip moving duplicates
    // Optionally, log that duplicates are being handled via updates
    error_log("Flag 'updateMissingDataOnly' is set. Skipping moving duplicate files.");
}

// Return final results as JSON
returnHTML($titlesArray);

// -------------------- FUNCTION DEFINITIONS --------------------

function populateTitlesArray(array $sessionFiles)
{
    $titlesArray = [];

    foreach ($sessionFiles as $file) {
        // error_log("populateTitlesArray - Processing file: " . print_r($file, true));

        // Validate expected keys
        if (!isset($file['fileNameNoExtension'], $file['fileSize'], $file['fileDimensions'], $file['fileDuration'], $file['fileNameAndPath'])) {
            continue; // Skip invalid file entries
        }

        // Clean up the title
        $title = preg_replace(['/ - Scene.*/i', '/ - CD.*/i', '/ - Bonus.*| Bonus.*/i'], '', $file['fileNameNoExtension']);

        $titlesArray[] = [
            'title' => $title, // Correctly map the title key
            'titleSize' => $file['fileSize'],
            'fileDimensions' => $file['fileDimensions'] ?? '', // Ensure this key exists
            'titleDuration' => $file['fileDuration'] ?? 0, // Ensure this key exists
            'titlePath' => $file['fileNameAndPath']
        ];
    }

    // Combine files by title, summing sizes and durations
    return array_values(array_reduce(
        $titlesArray,
        function ($carry, $item) {
            $t = $item['title'];
            if (isset($carry[$t])) {
                $carry[$t]['titleSize'] += $item['titleSize'];
                $carry[$t]['titleDuration'] += $item['titleDuration'];
            } else {
                $carry[$t] = $item;
            }
            return $carry;
        },
        []
    ));
}


function checkDatabaseForTitle(
    array $titleItem,
    array &$duplicateTitlesArray,
    array &$duplicateTitlesMissing01Array,
    array &$titlesMissing01Array,
    $db,
    $table,
    $updateMissingDataOnly = false
) {
    // error_log("checkDatabaseForTitle - Initial titleItem: " . print_r($titleItem, true));

    // Ensure required keys exist
    $titleItem['fileDimensions'] = $titleItem['fileDimensions'] ?? '';
    $titleItem['titleDuration'] = $titleItem['titleDuration'] ?? 0;
    $titleItem['titleSize']      = $titleItem['titleSize'] ?? 0;
    $title = $titleItem['title'];

    // These are used later (isLarger + missing-meta updates)
    $titleSize      = (int)$titleItem['titleSize'];
    $fileDimensions = (string)$titleItem['fileDimensions'];
    $fileDuration   = (int)$titleItem['titleDuration'];

    // --- UI helper flags: show "external search" icon when DB numbering mismatches this file title ---
    // IMPORTANT: compute these based on the *incoming* file title, before any handleNumberedTitle()/handleMissingNumberedTitle()
    // logic mutates the title or updates DB rows.


    // --- UI helper flags (based on incoming filename/title) ---
    $sourceTitle = $titleItem['title'];
    $titleItem['sourceTitle'] = $sourceTitle;

    [$baseTitleStrict, $titleHasNumberStrict] = splitBaseTitleAndHasNumberStrict($sourceTitle);

    // NOTE: non-cached so later rows see DB changes made earlier in the same request
    $presence = getDbNumberingPresence($db, $table, $baseTitleStrict);

    $titleItem['baseTitle'] = $baseTitleStrict;
    $titleItem['titleHasNumber'] = $titleHasNumberStrict;
    $titleItem['dbHasUnnumberedVariant'] = $presence['dbHasUnnumberedVariant'];
    $titleItem['dbHasNumberedVariant'] = $presence['dbHasNumberedVariant'];

    $dbHasOtherNumberedVariant = false;

    if ($titleHasNumberStrict) {
        $likePrefix = $baseTitleStrict . ' # ';
        if ($stmt = $db->prepare("
        SELECT 1
        FROM `$table`
        WHERE title LIKE CONCAT(?, '%')
          AND title <> ?
        LIMIT 1
    ")) {
            // IMPORTANT: compare against the incoming title (sourceTitle), not $title which may later mutate
            $stmt->bind_param('ss', $likePrefix, $sourceTitle);
            $stmt->execute();
            $res = $stmt->get_result();
            $dbHasOtherNumberedVariant = ($res && $res->num_rows > 0);
            $stmt->close();
        }
    }

    $titleItem['dbHasOtherNumberedVariant'] = $dbHasOtherNumberedVariant;

    $titleItem['needsExternalSearch'] =
        (!$titleHasNumberStrict && $titleItem['dbHasNumberedVariant']) ||
        ($titleHasNumberStrict && $titleItem['dbHasUnnumberedVariant']) ||
        ($titleHasNumberStrict && $titleItem['dbHasOtherNumberedVariant']);

    // If you OR'd with duplicate in Angular, keep it there.
    // If you want it here instead, uncomment the next line:
    // $titleItem['needsExternalSearch'] = $titleItem['needsExternalSearch'] || !empty($titleItem['duplicate']);
    // -----------------------------------------------------------

    // --- Normalize title for DB operations ---
    $dbTitle = $sourceTitle;
    $dbTitle = handleNumberedTitle($dbTitle, $db, $table);
    $dbTitle = handleMissingNumberedTitle($dbTitle, $titleItem, $duplicateTitlesMissing01Array, $titlesMissing01Array, $db, $table);

    $titleItem['dbTitle'] = $dbTitle;

    // Preserve existing behavior (downstream code expects $title and titleItem['title'] to be the DB title)
    $title = $dbTitle;
    $titleItem['title'] = $title;


    // Check if title exists in DB
    if ($stmt = $db->prepare("SELECT id, date_created, dimensions, filesize, duration, filepath FROM `$table` WHERE title = ?")) {
        $stmt->bind_param('s', $title);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result && $result->num_rows > 0) {
                // Duplicate found in DB
                $row = $result->fetch_assoc();
                $titleItem['duplicate'] = true;
                $titleItem['id'] = $row['id'];
                $titleItem['dateCreatedInDB'] = $row['date_created'];
                $titleItem['dimensionsInDB'] = $row['dimensions'];
                $titleItem['sizeInDB'] = $row['filesize'];
                $titleItem['durationInDB'] = $row['duration'];

                $isLarger = $titleItem['isLarger'] = compareFileSizeToDB($titleSize, $row['filesize']);
                $duplicateTitlesArray[] = ['title' => $title, 'isLarger' => $isLarger];

                // If existing file in DB has missing metadata, set an additional flag:
                $hasMissingMeta = false;
                if ((empty($row['dimensions']) || strtolower($row['dimensions']) === '0 x 0')
                    || (empty($row['duration']) || $row['duration'] == 0)
                    || (empty($row['filesize']) || $row['filesize'] == 0)
                ) {
                    $hasMissingMeta = true;
                }

                // Pass that to the front-end:
                $titleItem['needsUpdateMissingMeta'] = $hasMissingMeta;

                // **New Logic: Update Missing Data if Flag is Set**
                if ($updateMissingDataOnly) {
                    $needsUpdate = false;
                    $newDimensions = $row['dimensions']; // Initialize with existing value
                    $newDuration = $row['duration']; // Initialize with existing value
                    $newFilesize = $row['filesize']; // Initialize with existing value

                    // Track which fields are updated
                    $updatedFields = [];

                    // Check if dimensions are blank or zero
                    if (empty($row['dimensions']) || strtolower($row['dimensions']) === '0 x 0') {
                        if (!empty($fileDimensions)) { // Ensure new dimensions are available
                            $needsUpdate = true;
                            $newDimensions = $fileDimensions;
                            $updatedFields[] = "dimensions to '{$newDimensions}'";
                        }
                    }

                    // Check if duration is zero
                    if (empty($row['duration']) || $row['duration'] == 0) {
                        if ($fileDuration > 0) { // Ensure new duration is valid
                            $needsUpdate = true;
                            $newDuration = $fileDuration;
                            $updatedFields[] = "duration to '{$newDuration}'";
                        }
                    }

                    // **Add Logic to Update filesize if it's zero**
                    if ($row['filesize'] == 0) {
                        if ($titleSize > 0) { // Ensure new filesize is valid
                            $needsUpdate = true;
                            $newFilesize = $titleSize;
                            $updatedFields[] = "filesize to '{$newFilesize}'";
                        }
                    }

                    if ($needsUpdate) {
                        // Prepare dynamic update query
                        $fieldsToUpdate = [];
                        $params = [];
                        $types = '';

                        if ($newDimensions !== $row['dimensions']) {
                            $fieldsToUpdate[] = 'dimensions = ?';
                            $params[] = $newDimensions;
                            $types .= 's';
                        }

                        if ($newDuration !== $row['duration']) {
                            $fieldsToUpdate[] = 'duration = ?';
                            $params[] = $newDuration;
                            $types .= 'i';
                        }

                        if ($newFilesize !== $row['filesize']) {
                            $fieldsToUpdate[] = 'filesize = ?';
                            $params[] = $newFilesize;
                            $types .= 'i';
                        }

                        // If there are fields to update, proceed
                        if (!empty($fieldsToUpdate)) {
                            $updateQuery = "UPDATE `$table` SET " . implode(', ', $fieldsToUpdate) . " WHERE id = ?";
                            $params[] = $row['id'];
                            $types .= 'i';

                            if ($stmtUpdate = $db->prepare($updateQuery)) {
                                $stmtUpdate->bind_param($types, ...$params);
                                if ($stmtUpdate->execute()) {
                                    // **Modified Log Messages to Include Title and Specific Fields Updated**
                                    if (!empty($updatedFields)) {
                                        $updatedFieldsStr = implode(' and ', $updatedFields);
                                        error_log("Updated '{$title}' with {$updatedFieldsStr}.");
                                    } else {
                                        error_log("No fields needed to be updated for '{$title}'.");
                                    }

                                    $titleItem['status'] = 'Updated missing data';
                                } else {
                                    error_log("Failed to update '{$title}': " . $stmtUpdate->error);
                                    $titleItem['status'] = 'Failed to update missing data';
                                }
                                $stmtUpdate->close();
                            } else {
                                error_log("Error preparing update statement for '{$title}': " . $db->error);
                                $titleItem['status'] = 'Failed to prepare update statement';
                            }
                        } else {
                            // No actual changes needed
                            error_log("No valid fields to update for '{$title}'.");
                            $titleItem['status'] = 'No updates required';
                        }
                    } else {
                        // No missing data to update
                        error_log("No missing data to update for '{$title}'.");
                        $titleItem['status'] = 'No updates required';
                    }
                }
            } else {
                // Insert new record
                $insertedId = addToDB($titleItem, $db, $table);
                $titleItem['id'] = $insertedId;
            }
        } else {
            error_log("Error executing query in checkDatabaseForTitle for '{$title}': " . $stmt->error);
        }
        $stmt->close();
    } else {
        error_log("Error preparing statement in checkDatabaseForTitle for '{$title}': " . $db->error);
    }

    return $titleItem;
}

function handleNumberedTitle($title, $db, $table)
{
    if (preg_match('/# [0-9]+$/', $title)) {
        $titleN = preg_split('/ # [0-9]+/', $title)[0];
        if ($stmt = $db->prepare("SELECT id FROM `$table` WHERE title = ?")) {
            $stmt->bind_param('s', $titleN);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result && $result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    $title1 = $titleN . ' # 01';

                    // Update the title
                    if ($updateStmt = $db->prepare("UPDATE `$table` SET title=? WHERE title=?")) {
                        $updateStmt->bind_param('ss', $title1, $titleN);
                        $updateStmt->execute();
                        $updateStmt->close();
                    }
                }
            }
            $stmt->close();
        }
    }
    return $title;
}

function handleMissingNumberedTitle($title, array $titleItem, array &$duplicateTitlesMissing01Array, array &$titlesMissing01Array, $db, $table)
{
    if (!preg_match('/# [0-9]+$/', $title)) {
        $title01 = $title . ' # 01';

        // Check for exact # 01 title
        if ($stmt = $db->prepare("SELECT filesize FROM `$table` WHERE title = ?")) {
            $stmt->bind_param('s', $title01);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result && $result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    $isLarger = compareFileSizeToDB($titleItem['titleSize'], $row['filesize']);
                    $duplicateTitlesMissing01Array[] = ['title' => $title, 'isLarger' => $isLarger];
                    $title = $title01;
                }
            }
            $stmt->close();
        }

        // Check for any title with a number
        $likeTitle = $title . ' # ';
        if ($stmt = $db->prepare("SELECT id FROM `$table` WHERE title LIKE CONCAT(?, '%')")) {
            $stmt->bind_param('s', $likeTitle);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result && $result->num_rows > 0) {
                    $titlesMissing01Array[] = ['title' => $title];
                    $title = $title01;
                }
            }
            $stmt->close();
        }
    }

    return $title;
}


/**
 * Strictly split a title into baseTitle + hasNumber where the numbered form must be " ... # 01"
 * (spaces required around '#').
 */
function splitBaseTitleAndHasNumberStrict(string $title): array
{
    $t = trim($title);

    if (preg_match('/^(.*)\s+#\s+(\d+)$/', $t, $m)) {
        return [trim($m[1]), true];
    }

    return [$t, false];
}

/**
 * Check whether DB contains:
 *  - the unnumbered variant: "Base Title"
 *  - any numbered variant:  "Base Title # NN"
 *
 *  * Non-cached: queries live DB state each time so results reflect changes within the same run.
 */
function getDbNumberingPresence($db, string $table, string $baseTitle): array
{
    $hasUnnumbered = false;
    $hasNumbered = false;

    // Exact unnumbered variant
    if ($stmt = $db->prepare("SELECT 1 FROM `$table` WHERE title = ? LIMIT 1")) {
        $stmt->bind_param('s', $baseTitle);
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            $hasUnnumbered = ($res && $res->num_rows > 0);
        }
        $stmt->close();
    }

    // Any numbered variant (strict: must contain " # " after the base title)
    $likePrefix = $baseTitle . ' # ';
    if ($stmt = $db->prepare("SELECT 1 FROM `$table` WHERE title LIKE CONCAT(?, '%') LIMIT 1")) {
        $stmt->bind_param('s', $likePrefix);
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            $hasNumbered = ($res && $res->num_rows > 0);
        }
        $stmt->close();
    }

    return [
        'dbHasUnnumberedVariant' => $hasUnnumbered,
        'dbHasNumberedVariant' => $hasNumbered,
    ];
}


function addToDB(array $titleItem, $db, $table)
{
    // error_log("addToDB - titleItem to be inserted: " . print_r($titleItem, true));

    if (!isset($titleItem['fileDimensions']) || !isset($titleItem['titleDuration'])) {
        error_log("Undefined index encountered - titleItem: " . print_r($titleItem, true));
    }

    $title = $titleItem['title'];
    $titleSize = (string) $titleItem['titleSize']; // Cast to string
    $titleDimensions = $titleItem['fileDimensions'] ?? ''; // Default to an empty string
    $titleDuration = (string) $titleItem['titleDuration']; // Cast to string for consistency

    // error_log("Preparing to insert: title=$title, dimensions=$titleDimensions, size=$titleSize, duration=$titleDuration");

    if ($stmt = $db->prepare("INSERT IGNORE INTO `$table` (title, dimensions, filesize, duration, date_created) VALUES (?, ?, ?, ?, NOW())")) {
        $stmt->bind_param('ssis', $title, $titleDimensions, $titleSize, $titleDuration);
        if (!$stmt->execute()) {
            error_log("Database insertion failed for '{$title}': " . $stmt->error);
        } else {
            $insertedId = $db->insert_id;
            // error_log("Inserted record ID: " . $insertedId);
            $stmt->close();
            return $insertedId;
        }
        $stmt->close();
    } else {
        error_log("Error preparing statement in addToDB for '{$title}': " . $db->error);
    }

    return null;
}


function compareFileSizeToDB($size, $sizeInDB)
{
    if ($sizeInDB > 0 && $sizeInDB < $size) {
        return 'isLarger';
    } elseif ($sizeInDB == 0 && $sizeInDB < $size) {
        return 'isLargerZeroDBSize';
    }
    return '';
}

function searchSessionForDuplicateFiles(array $duplicateTitlesArray, array $sessionFiles)
{
    $patterns = [
        '/ - Scene.*/i',
        '/ - CD.*/i',
        '/ - Bonus.*| Bonus.*/i'
    ];

    foreach ($duplicateTitlesArray as $dup) {
        $dupTitle = $dup['title'];
        foreach ($sessionFiles as $file) {
            if (!isset($file['fileNameAndPath'], $file['fileNameNoExtension'])) {
                continue;
            }

            $fileNameNoExtension = preg_replace($patterns, '', $file['fileNameNoExtension']);

            // Check if the duplicate title matches
            if (stripos($dupTitle, $fileNameNoExtension) === 0) {
                $fileName = basename($file['fileNameAndPath']);
                $destination = dirname($file['fileNameAndPath']) . '/duplicates/';
                // error_log("Matched duplicate: $fileName under $dupTitle");

                // Check if it's larger
                if ($dup['isLarger'] === 'isLarger') {
                    $destination .= 'larger/';
                }

                moveDuplicateFiles(dirname($file['fileNameAndPath']), $destination, $fileName);
            } else {
                // error_log("No match for dupTitle=$dupTitle and fileNameNoExtension=$fileNameNoExtension");
            }
        }
    }
}


function moveDuplicateFiles($path, $destination, $fileName)
{
    $path = rtrim($path, '/') . '/';

    if (!is_dir($destination)) {
        if (!mkdir($destination, 0777, true)) {
            error_log("Unable to create directory $destination");
            return;
        }
    }

    $source = $path . $fileName;
    $renameFile = $destination . $fileName;

    if (is_file($source)) {
        // error_log("Moving file from $source to $renameFile");
        if (!rename($source, $renameFile)) {
            error_log("Failed to rename $source to $renameFile");
        }
    }
}

/**
 * Splits filename (no extension) into [baseTitle, suffix] while preserving suffix exactly.
 * Examples:
 *  "Title - Scene_1" => ["Title", " - Scene_1"]
 *  "Title - CD1"     => ["Title", " - CD1"]
 *  "Title - Bonus X" => ["Title", " - Bonus X"]
 *  "Title"           => ["Title", ""]
 */
function splitBaseAndSuffixPreserve(string $nameNoExt): array
{
    $patterns = [
        '/( - Scene.*)$/i',
        '/( - CD.*)$/i',
        '/( - Bonus.*)$/i',
        '/( Bonus.*)$/i', // handles "Title Bonus..." if that ever exists
    ];

    foreach ($patterns as $p) {
        if (preg_match($p, $nameNoExt, $m, PREG_OFFSET_CAPTURE)) {
            $suffix = $m[1][0];
            $base = substr($nameNoExt, 0, $m[1][1]);
            return [trim($base), $suffix];
        }
    }

    return [trim($nameNoExt), ''];
}

/**
 * Rename files on disk by inserting " # 01" after the base title (before any suffix),
 * for any base title found in $titlesMissing01Array or $duplicateTitlesMissing01Array.
 *
 * Updates $sessionFiles in-place so later duplicate-moving still works.
 * Returns a map of oldPath => newPath so you can update titlesArray titlePath too.
 */
function renameSessionFilesAddMissing01(
    array $titlesMissing01Array,
    array $duplicateTitlesMissing01Array,
    array &$sessionFiles
): array {
    // Build a set of base titles that should get " # 01"
    $bases = [];

    foreach ($titlesMissing01Array as $x) {
        if (!empty($x['title'])) $bases[mb_strtolower(trim($x['title']))] = true;
    }
    foreach ($duplicateTitlesMissing01Array as $x) {
        if (!empty($x['title'])) $bases[mb_strtolower(trim($x['title']))] = true;
    }

    if (!$bases) return [];

    $pathMap = [];

    foreach ($sessionFiles as &$file) {
        if (empty($file['fileNameAndPath'])) continue;

        $oldPath = $file['fileNameAndPath'];
        $pi = pathinfo($oldPath);

        $dir = $pi['dirname'] ?? '';
        $ext = isset($pi['extension']) && $pi['extension'] !== '' ? ('.' . $pi['extension']) : '';
        $nameNoExt = $pi['filename'] ?? '';

        if ($dir === '' || $nameNoExt === '') continue;

        // Preserve suffix and find base
        [$base, $suffix] = splitBaseAndSuffixPreserve($nameNoExt);

        // Skip already-numbered (strict valid form)
        if (preg_match('/\s+#\s+\d+$/', $base)) continue;

        // Guard: if someone had invalid "#01" (no space), don’t make it worse—skip + log
        if (preg_match('/#\d+$/', $base)) {
            error_log("Skipping rename (invalid # format): {$oldPath}");
            continue;
        }

        // Only rename if this base is one of the "missing #01" bases
        if (!isset($bases[mb_strtolower($base)])) continue;

        $newNameNoExt = $base . ' # 01' . $suffix;
        $newPath = $dir . '/' . $newNameNoExt . $ext;

        if ($newPath === $oldPath) continue;

        if (file_exists($newPath)) {
            error_log("Rename skipped (target exists): {$newPath}");
            continue;
        }

        if (@rename($oldPath, $newPath)) {
            $pathMap[$oldPath] = $newPath;

            // Update sessionFiles so downstream duplicate moving works
            $file['fileNameNoExtension'] = $newNameNoExt;
            $file['fileNameAndPath'] = $newPath;
        } else {
            $err = error_get_last();
            error_log("Rename failed: {$oldPath} => {$newPath} | " . ($err['message'] ?? 'unknown error'));
        }
    }
    unset($file);

    return $pathMap;
}


function returnHTML($titlesArray)
{
    echo json_encode([
        'message' => "processFilesForDB is complete",
        'titles' => $titlesArray
    ]);
}
