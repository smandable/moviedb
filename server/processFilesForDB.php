<?php
// Increase the max execution time as needed
ini_set('max_execution_time', 0);

require 'formatSize.php';
require 'renameFilesMissing01.php';
require 'renameDuplicateFilesMissing01.php';
require 'safe_json_encode.php';
require 'db_connect.php'; // Must define $db and $table or handle otherwise

session_id('files');
session_start();

// Ensure $table is defined here if not in db_connect.php
// $table = 'your_table_name'; 

// Validate Input
$directory = isset($_POST['directory']) ? trim($_POST['directory']) : '';
if (empty($directory)) {
    echo 'Directory is required.';
    exit();
}

// Retrieve files from session
if (!isset($_SESSION['files']) || !is_array($_SESSION['files'])) {
    echo "No files in session.";
    exit();
}

// Process titles
$titlesArray = populateTitlesArray($_SESSION['files']);

// Arrays to hold different types of duplicates and missing entries
$duplicateTitlesArray = [];
$duplicateTitlesMissing01Array = [];
$titlesMissing01Array = [];

foreach ($titlesArray as &$titleItem) {
    $titleItem = checkDatabaseForTitle(
        $titleItem, 
        $duplicateTitlesArray, 
        $duplicateTitlesMissing01Array, 
        $titlesMissing01Array, 
        $db, 
        $table
    );
}
unset($titleItem); // break reference

// Perform rename operations after database checks
renameFilesMissing01($titlesMissing01Array);
renameDuplicateFilesMissing01($duplicateTitlesMissing01Array);

// Search session for duplicate files and move them accordingly
searchSessionForDuplicateFiles($duplicateTitlesArray, $_SESSION['files']);

// Return final results as JSON
returnHTML($titlesArray);

// -------------------- FUNCTION DEFINITIONS --------------------

function populateTitlesArray(array $sessionFiles)
{
    $titlesArray = [];

    foreach ($sessionFiles as $file) {
        // Validate expected keys
        if (!isset($file['fileNameNoExtension'], $file['fileSize'], $file['fileDimensions'], $file['fileDuration'], $file['fileNameAndPath'])) {
            continue; // Skip invalid file entries
        }

        $title = $file['fileNameNoExtension'];
        $patterns = [
            '/ - Scene.*/i',
            '/ - CD.*/i',
            '/ - Bonus.*| Bonus.*/i'
        ];
        $title = preg_replace($patterns, '', $title);

        $titlesArray[] = [
            'title' => $title,
            'titleSize' => $file['fileSize'],
            'titleDimensions' => $file['fileDimensions'],
            'titleDuration' => $file['fileDuration'],
            'titlePath' => $file['fileNameAndPath']
        ];
    }

    // Combine duplicates by title, summing sizes and durations
    $reduced = array_reduce(
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
    );

    // logFile("populateTitlesArray");
    return $reduced;
}

function checkDatabaseForTitle(
    array $titleItem, 
    array &$duplicateTitlesArray,
    array &$duplicateTitlesMissing01Array,
    array &$titlesMissing01Array,
    $db,
    $table
) {
    $title = $titleItem['title'];
    $titleSize = $titleItem['titleSize'];

    // Process numbered or missing-numbered titles
    $title = handleNumberedTitle($title, $db, $table);
    $title = handleMissingNumberedTitle($title, $titleItem, $duplicateTitlesMissing01Array, $titlesMissing01Array, $db, $table);

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
                $titleItem['pathInDB'] = $row['filepath'];
                
                $isLarger = $titleItem['isLarger'] = compareFileSizeToDB($titleSize, $row['filesize']);
                $duplicateTitlesArray[] = ['title' => $title, 'isLarger' => $isLarger];
            } else {
                // Insert new record
                $insertedId = addToDB($titleItem, $db, $table);
                $titleItem['id'] = $insertedId;
            }
        } else {
            error_log("Error executing query in checkDatabaseForTitle: " . $stmt->error);
        }
        $stmt->close();
    } else {
        error_log("Error preparing statement in checkDatabaseForTitle: " . $db->error);
    }

    // logFile("checkDatabaseForTitle");
    return $titleItem;
}

function handleNumberedTitle($title, $db, $table)
{
    if (preg_match('/# [0-9]+$/', $title)) {
        $titleN = preg_split('/ # [0-9]+/', $title)[0];
        if ($stmt = $db->prepare("SELECT id, filepath FROM `$table` WHERE title = ?")) {
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

                    // Update filepath
                    $pathInDB = $row['filepath'];
                    if (!empty($pathInDB)) {
                        $pathInDB = str_replace($titleN, $title1, $pathInDB);
                        if ($pathStmt = $db->prepare("UPDATE `$table` SET filepath=? WHERE id=?")) {
                            $pathStmt->bind_param('si', $pathInDB, $row['id']);
                            $pathStmt->execute();
                            $pathStmt->close();
                        }
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

function addToDB(array $titleItem, $db, $table)
{
    // logFile("addToDB");

    $title = $titleItem['title'];
    $titleSize = $titleItem['titleSize'];
    $titleDimensions = $titleItem['titleDimensions'];
    $titleDuration = $titleItem['titleDuration'];
    $titlePath = $titleItem['titlePath'];

    $patterns = ['/to move\//i', '/fixed\//i'];
    $replaceWith = 'recorded/';
    $titlePath = preg_replace($patterns, $replaceWith, $titlePath);

    if ($stmt = $db->prepare("INSERT IGNORE INTO `$table` (title, dimensions, filesize, duration, filepath, date_created) VALUES (?, ?, ?, ?, ?, NOW())")) {
        $stmt->bind_param('ssdds', $title, $titleDimensions, $titleSize, $titleDuration, $titlePath);
        if ($stmt->execute()) {
            $insertedId = $db->insert_id;
            $stmt->close();
            return $insertedId;
        } else {
            error_log("Error inserting record in addToDB: " . $stmt->error);
        }
        $stmt->close();
    } else {
        error_log("Error preparing statement in addToDB: " . $db->error);
    }

    return null;
}

function compareFileSizeToDB($size, $sizeInDB)
{
    // logFile("compareFileSizeToDB");
    if ($sizeInDB > 0 && $sizeInDB < $size) {
        return 'isLarger';
    } elseif ($sizeInDB == 0 && $sizeInDB < $size) {
        return 'isLargerZeroDBSize';
    }
    return '';
}

function searchSessionForDuplicateFiles(array $duplicateTitlesArray, array $sessionFiles)
{
    // logFile("searchSessionForDuplicateFiles");

    $patterns = [
        '/ - Scene.*/i',
        '/ - CD.*/i',
        '/ - Bonus.*| Bonus.*/i'
    ];

    foreach ($duplicateTitlesArray as $dup) {
        $dupTitle = $dup['title'];
        foreach ($sessionFiles as $file) {
            if (!isset($file['path'], $file['fileNameNoExtension'], $file['fileName'])) {
                continue;
            }

            $path = $file['path'];
            $fileNameNoExtension = preg_replace($patterns, '', $file['fileNameNoExtension']);

            if (stripos($dupTitle, $fileNameNoExtension) === 0) {
                $fileName = $file['fileName'];
                $destination = rtrim($path, '/') . '/duplicates/';
                if ($dup['isLarger'] === 'isLarger') {
                    $destination .= 'larger/';
                }
                moveDuplicateFiles($path, $destination, $fileName);
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
        if (!rename($source, $renameFile)) {
            error_log("Failed to rename $source to $renameFile");
        }
    }

    // logFile("moveDuplicateFiles");
}

function returnHTML($titlesArray)
{
    // logFile("returnHTML");
    $titlesArray = array_values($titlesArray);

    $directory = getcwd();
    // Save results for debugging or further use
    file_put_contents("$directory/tmp.txt", '<?php return ' . var_export($titlesArray, true) . ';');

    echo safe_json_encode("processFilesForDB is complete");
}

function logFile($currentFunction)
{
    $directory = getcwd();
    $logPath = "$directory/logFile.txt";

    if ($fh = fopen($logPath, "a")) {
        fwrite($fh, "$currentFunction\n");
        fclose($fh);
    } else {
        error_log("Unable to open log file: $logPath");
    }
}
