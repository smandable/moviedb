<?php

ini_set('max_execution_time', 0);

include('getDimensions.php');
include('getDuration.php');
include('formatSize.php');

$directory = $_POST['directory'];

if (empty($_POST['directory'])) {
    echo 'Directory is required.';
    exit();
}

$filesArray = array();

$pattern1 = '/\.[a-z1-9]{3,4}$/i';
$pattern2 = '/ - Scene.*/i';
$pattern3 = '/ - CD.*/i';
$pattern4 = '/ - Bonus.*| Bonus.*/i';

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($iterator as $fileInfo) {
    if ($fileInfo->getBasename() === '.DS_Store' || $fileInfo->getBasename() === 'Thumbs.db' || $fileInfo->getBasename() === '.AppleDouble'|| $fileInfo->getBasename() === 'updated.txt') {
        continue;
    }
    $baseName = $fileInfo->getBasename();
    $fileName = $baseName;
    $fileName = preg_replace(array($pattern1, $pattern2, $pattern3, $pattern4), '', $fileName);
    $fileNameAndPath = $fileInfo->getPathname();
    $fileSize = filesize($fileInfo->getPathname());
    $fileExtension =pathinfo($fileInfo->getBasename(), PATHINFO_EXTENSION);
    $dimensions = getDimensions($fileNameAndPath);
    $duration = getDuration($fileNameAndPath);

    $filesArray[] = array(
      'Title' => $fileName,
      'baseName' => $baseName,
      'fileExtension' => $fileExtension,
      'Dimensions' => $dimensions,
      'Duration' => $duration,
      'Size' => $fileSize,
      'Path' => $fileNameAndPath
    );
}

$filesSorted = array();

foreach ($filesArray as $key => $row) {
    $filesSorted[$key] = $row['Path'];
}

array_multisort($filesSorted, SORT_ASC, $filesArray);

$lengthFilesArray = count($filesArray);

for ($i=0;$i<$lengthFilesArray;$i++) {
    $filesArrayToReduce[$i] = array(
    'Title' => $filesArray[$i]["Title"],
    'Dimensions' => $filesArray[$i]["Dimensions"],
    'Duration' => $filesArray[$i]["Duration"],
    'DurationInDB' => '',
    'Size' => $filesArray[$i]["Size"],
    'SizeInDB' => '',
    'Path' => $filesArray[$i]["Path"],
    'PathInDB' => '',
    'Duplicate' => false,
    'isLarger' => false,
    'DateCreatedInDB' => '',
    'ID' => ''
  );
}

$filesArrayReducedSizesSummed = array_reduce($filesArrayToReduce, function ($a, $b) {
    if (isset($a[$b['Title']])) {
        $a[$b['Title']]['Size'] += $b['Size'];
        $a[$b['Title']]['Duration'] += $b['Duration'];
    } else {
        $a[$b['Title']] = $b;
    }
    return $a;
});

$filesArrayReducedSizesSummed = array_values($filesArrayReducedSizesSummed);

$lengthFilesArrayReducedSizesSummed = count($filesArrayReducedSizesSummed);

for ($i=0;$i<$lengthFilesArrayReducedSizesSummed;$i++) {
    checkDatabaseForMovie($filesArrayReducedSizesSummed[$i], $filesArray);
}

function checkDatabaseForMovie(&$filesArrayReducedSizesSummed, &$filesArray)
{
    global $directory;
    $title = $filesArrayReducedSizesSummed['Title'];
    $dimensions = $filesArrayReducedSizesSummed['Dimensions'];
    $size = $filesArrayReducedSizesSummed['Size'];
    $duration = $filesArrayReducedSizesSummed['Duration'];
    $path = $filesArrayReducedSizesSummed['Path'];

    include "db_connect.php";

    $title = $db->real_escape_string($title);
    $path = $db->real_escape_string($path);

    $spacePoundSpace01 = " # 01";

    // If file being read from directory HAS a number in it, look for that title in the DB WITHOUT a number.
    // If found, add " # 01" to it. This is only to update that record in the db.

    if (preg_match('/# [0-9]+$/', $title)) {
        $tmpTitle = preg_split('/ # [0-9]+/', $title);
        $resultT = $db->query("SELECT * FROM `".$table."` WHERE title = '$tmpTitle[0]'");

        if ($resultT->num_rows > 0) {
            $titleSpacePoundSpace01 = $tmpTitle[0].$spacePoundSpace01;
            $db->query("UPDATE `".$table."` SET title='$titleSpacePoundSpace01' WHERE title='$tmpTitle[0]'");
            $rowT = mysqli_fetch_assoc($resultT);
            $idT = $rowT['id'];
            $originalTitle = $rowT['title'];
            $pathT = $rowT['filepath'];

            if ($pathT != "") {
                $pathT = str_replace($originalTitle, $titleSpacePoundSpace01, $pathT);
                $db->query("UPDATE `".$table."` SET filepath='$pathT' WHERE id='$idT'");
            }
        }
    }

    // If file being read from directory DOES NOT have a number in it, look for that title in the DB WITH a number + " 01".
    // If found, add file to $filesMissingSpacePoundSpace01 array, and set $title to $title + # 01

    if (!preg_match('/# [0-9]+$/', $title)) {
        $titleSpacePoundSpace01 = $title.$spacePoundSpace01;

        $resultN = $db->query("SELECT * FROM `".$table."` WHERE title = '$titleSpacePoundSpace01'");
        $rowN = mysqli_fetch_assoc($resultN);
        if ($resultN->num_rows > 0) {
            $filesMissingSpacePoundSpace01[] = array('title' => $title);

            $title = $titleSpacePoundSpace01;
        }
    }

    $result = $db->query("SELECT * FROM `".$table."` WHERE title = '$title'");
    $row = mysqli_fetch_assoc($result);

    if ($result->num_rows > 0) {
        $filesArrayReducedSizesSummed['Duplicate'] = true;
        $filesArrayReducedSizesSummed['ID'] = $row['id'];
        $filesArrayReducedSizesSummed['DateCreatedInDB'] = $row['date_created'];
        $filesArrayReducedSizesSummed['SizeInDB'] = $row['filesize'];
        $filesArrayReducedSizesSummed['DurationInDB'] = $row['duration'];
        $filesArrayReducedSizesSummed['PathInDB'] = $row['filepath'];
        $filesArrayReducedSizesSummed['isLarger'] = compareFileSizeToDB($filesArrayReducedSizesSummed['Size'], $filesArrayReducedSizesSummed['SizeInDB']);

        moveDuplicateFile($title, $filesArray);
    } else {
        $filesArrayReducedSizesSummed['ID'] = addToDB($title, $dimensions, $size, $duration, $path, $db, $table);
        moveRecordedFile($title, $filesArray);
    }

    $db->close();
}

function addToDB($title, $dimensions, $size, $duration, $path, $db, $table)
{
    global $directory;
    $pattern1 = '/to move\//i';
    $pattern2 = '/names fixed\//i';
    $replaceWith = 'recorded/';

    $path = preg_replace(array($pattern1, $pattern2), $replaceWith, $path);

    if ($db->query(
        "INSERT IGNORE INTO `".$table."` (title, dimensions, filesize, duration, filepath, date_created) VALUES ('$title', '$dimensions', '$size', '$duration', '$path', NOW())"
    )) {
        $newRow = mysqli_fetch_assoc($db->query("SELECT * FROM `".$table."` WHERE title = '$title'"));
        // $newRow = mysqli_fetch_assoc($newResult);
        $newIDToReturn = $newRow['id'];
        return $newIDToReturn;
    } else {
        printf("Error in addToDB(): %s\n", $db->sqlstate);
        printf("Error in addToDB() message: %s\n", $db->error);
    }
}

function compareFileSizeToDB($size, $sizeInDB)
{
    $isLarger = false;
    if (($sizeInDB > 0) && ($sizeInDB < $size)) {
        $isLarger = true;
    }
    return $isLarger;
}

function moveDuplicateFile($title, $filesArray)
{
    global $directory;
    $destination = $directory.'duplicates/';
    if (!is_dir($destination)) {
        mkdir($destination, 0777, true);
    }
    foreach ($filesArray as $file) {
        if (!is_file($file['Path'])) {
            continue;
        }
        if ($file['Title'] == stripslashes($title)) {
            $rename_file = $destination.$file['baseName'];
            str_replace("'", "\'", $rename_file);
            rename($file['Path'], $rename_file);
        }
    }
}

function moveRecordedFile($title, $filesArray)
{
    global $directory;
    $pattern1 = '/to move\//i';
    $pattern2 = '/names fixed\//i';
    $replaceWith = 'recorded/';

    $destination = $directory;
    $destination = preg_replace(array($pattern1, $pattern2), $replaceWith, $destination);

    if (!is_dir($destination)) {
        mkdir($destination, 0777, true);
    }
    foreach ($filesArray as $file) {
        if (!is_file($file['Path'])) {
            continue;
        }
        if ($file['Title'] == stripslashes($title)) {
            $rename_file = $destination.$file['baseName'];
            str_replace("'", "\'", $rename_file);
            rename($file['Path'], $rename_file);
        }
    }
}

function findFilesToRename($filesMissingSpacePoundSpace01)
{
    global $directory;
    $directory = $directory.'duplicates/';
    $directory = new \RecursiveDirectoryIterator($directory);
    $iterator = new \RecursiveIteratorIterator($directory);
    $spacePoundSpace01 = ' # 01';
    $spaceDashSpace = ' - ';
    $pattern1 = '/\.[a-z1-9]{3,4}$/';

    foreach ($filesMissingSpacePoundSpace01 as $fileMissingOne) {
        $titleMissingOne = $fileMissingOne['title'];

        foreach ($iterator as $file) {
            if ($file->getBasename() === '.' || $file->getBasename() === '..' || $file->getBasename() === '.DS_Store') {
                continue;
            }
            $fileName = $file->getBasename();
            $originalFileName = $fileName;
            $fileExtension =pathinfo($file->getBasename(), PATHINFO_EXTENSION);
            $fileExtension = "." . $fileExtension;
            $fileName = preg_replace($pattern1, '', $fileName);

            if (strcasecmp($titleMissingOne, $fileName) == 0) {
                $fileName = $fileName . $spacePoundSpace01 . $fileExtension;
                str_replace("'", "\'", $fileName);
                rename($directory.$originalFileName, $directory.$fileName);
            }

            if ($beginningOfFileName = stristr($fileName, ' - Scene_', true)) {
                if (strcasecmp($titleMissingOne, $beginningOfFileName) == 0) {
                    $tmpFileName = preg_split('/ - /', $fileName);
                    $fileName = $tmpFileName[0] . $spacePoundSpace01 . $spaceDashSpace . $tmpFileName[1] . $fileExtension;
                    str_replace("'", "\'", $fileName);
                    rename($directory.$originalFileName, $directory.$fileName);
                }
            }
            if ($beginningOfFileName = stristr($fileName, ' - CD', true)) {
                if (strcasecmp($titleMissingOne, $beginningOfFileName) == 0) {
                    $tmpFileName = preg_split('/ - /', $fileName);
                    $fileName = $tmpFileName[0] . $spacePoundSpace01 . $spaceDashSpace . $tmpFileName[1] . $fileExtension;
                    str_replace("'", "\'", $fileName);
                    rename($directory.$originalFileName, $directory.$fileName);
                }
            }
        }
    }
}

returnHTML($filesArrayReducedSizesSummed);

function returnHTML($filesArrayReducedSizesSummed)
{
    $lengthFilesArrayReducedSizesSummed = count($filesArrayReducedSizesSummed);
    $returnedArray = array();

    for ($i=0;$i<$lengthFilesArrayReducedSizesSummed;$i++) {
        $returnedArray['data'][$i] = array(
          'Title' => $filesArrayReducedSizesSummed[$i]["Title"],
          'Dimensions' => $filesArrayReducedSizesSummed[$i]["Dimensions"],
          'Size' => $filesArrayReducedSizesSummed[$i]["Size"],
          'Duration' => $filesArrayReducedSizesSummed[$i]["Duration"],
          'DurationInDB' => $filesArrayReducedSizesSummed[$i]["DurationInDB"],
          'Path' => $filesArrayReducedSizesSummed[$i]["Path"],
          'Duplicate' => $filesArrayReducedSizesSummed[$i]["Duplicate"],
          'isLarger' => $filesArrayReducedSizesSummed[$i]["isLarger"],
          'SizeInDB' => $filesArrayReducedSizesSummed[$i]["SizeInDB"],
          'DateCreatedInDB' => $filesArrayReducedSizesSummed[$i]["DateCreatedInDB"],
          'ID' => $filesArrayReducedSizesSummed[$i]["ID"]
        );
    }

    echo json_encode($returnedArray);
}

// function quickLogFile($title)
// {
//     global $directory;
//     $myfile = fopen("$directory/updated.txt", "a") or die("Unable to open file!");
//
//     $txt = "$title\n\n";
//
//     fwrite($myfile, $txt);
//     fclose($myfile);
// }