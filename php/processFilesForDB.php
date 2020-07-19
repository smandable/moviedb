<?php

ini_set('max_execution_time', 0);

require 'formatSize.php';
require 'renameFilesMissing01.php';
require 'renameDuplicateFilesMissing01.php';

$directory = $_POST['directory'];

if (empty($_POST['directory'])) {
    echo 'Directory is required.';
    exit();
}
//$options = $_POST['options'];

//$options are: moveDuplicateFile, updateSize, updatefileDimensions, updateDuration, updatePath, moveRecordedFile

//$options = array("false", "false", "false", "false", "true", "false");
//$options = array("true","false","false","false","false","false");
//$options = array("false", "true", "true", "true", "true", "false");

$titlesArray = array();
$duplicateTitlesArray = array();
$duplicateTitlesMissing01Array = array();
$titlesMissing01Array = array();

session_id("files");
session_start();

populateTitlesArray($titlesArray);

$titlesArray = array_values($titlesArray);

for ($i = 0; $i < count($titlesArray); $i++) {
    checkDatabaseForTitle($titlesArray[$i], $duplicateTitlesArray, $duplicateTitlesMissing01Array, $titlesMissing01Array);
}

$allFiles = $_SESSION["files"];

renameFilesMissing01($titlesMissing01Array);
renameDuplicateFilesMissing01($duplicateTitlesMissing01Array);
searchSessionForDuplicateFiles($duplicateTitlesArray, $duplicateTitlesMissing01Array);

returnHTML($titlesArray);

function populateTitlesArray(&$titlesArray)
{
    foreach ($_SESSION["files"] as $file) {
        $title = $file["fileNameNoExtension"];
        $titleSize = $file["fileSize"];
        $titleDimensions = $file["fileDimensions"];
        $titleDuration = $file["fileDuration"];
        //This will only get the path of the first file, if it's broken into CDs. I don't care.
        $titlePath = $file["fileNameAndPath"];

        $pattern1 = '/ - Scene.*/i';
        $pattern2 = '/ - CD.*/i';
        $pattern3 = '/ - Bonus.*| Bonus.*/i';

        $title = preg_replace(array($pattern1, $pattern2, $pattern3), '', $title);

        $titlesArray[] = array(
            'title' => $title,
            'titleSize' => $titleSize,
            'titleDimensions' => $titleDimensions,
            'titleDuration' => $titleDuration,
            'titlePath' => $titlePath

        );
    }
    $titlesArray = array_reduce(
        $titlesArray,
        function ($a, $b) {
            if (isset($a[$b['title']])) {
                $a[$b['title']]['titleSize'] += $b['titleSize'];
                $a[$b['title']]['titleDuration'] += $b['titleDuration'];
            } else {
                $a[$b['title']] = $b;
            }
            return $a;
        }
    );
}
function checkDatabaseForTitle(&$titlesArray, &$duplicateTitlesArray, &$duplicateTitlesMissing01Array, &$titlesMissing01Array)
{
    $title = $titlesArray["title"];
    $titleSize = $titlesArray["titleSize"];
    $titleDimensions = $titlesArray["titleDimensions"];
    $titleDuration = $titlesArray["titleDuration"];
    $titlePath = $titlesArray["titlePath"];

    include 'db_connect.php';

    // If file being read from directory HAS a number in it, look for that title in the DB WITHOUT a number.
    // If found, add " # 01" to it. This is only to update that record in the db.

    if (preg_match('/# [0-9]+$/', $title)) {
        $titleN = preg_split('/ # [0-9]+/', $title);

        str_replace("'", "\'", $titleN[0]);

        $titleN = $titleN[0];
        $titleN = $db->real_escape_string($titleN);

        $result = $db->query("SELECT * FROM `" . $table . "` WHERE title = '$titleN'");

        if ($result->num_rows > 0) {

            $title1 = $titleN . " # 01";

            $db->query("UPDATE `" . $table . "` SET title='$title1' WHERE title='$titleN'");
            $row = mysqli_fetch_assoc($result);

            $id = $row['id'];
            $pathInDB = $row['filepath'];
            $pathInDB = $db->real_escape_string($pathInDB);
            if ($pathInDB != "") {
                $pathInDB = str_replace($titleN, $title1, $pathInDB);
                $db->query("UPDATE `" . $table . "` SET filepath='$pathInDB' WHERE id='$id'");
            }
            $result->free();
        }
    }

    // If file being read from directory DOES NOT have a number in it, look for that title in the DB WITH a number + " # 01".
    // If found, add file to $duplicateTitlesMissing01Array array, and set $title to $title + # 01.

    if (!preg_match('/# [0-9]+$/', $title)) {
        $title01 = $title . " # 01";
        $title01Escaped = $db->real_escape_string($title01);

        $result = $db->query("SELECT * FROM `" . $table . "` WHERE title = '$title01Escaped'");
        $row = mysqli_fetch_assoc($result);

        if ($result->num_rows > 0) {
            $sizeInDB = $row['filesize'];
            $isLarger = compareFileSizeToDB($titlesArray['titleSize'], $sizeInDB);
            $duplicateTitlesMissing01Array[] = array('title' => $title, 'isLarger' => $isLarger);
            $title = $title01;
            $result->free();
        }

        // Now look for the title, but with any number after it (but not # 01)

        $titleWithPound = $title . " # ";
        $titleWithPoundEscaped = $db->real_escape_string($titleWithPound);

        $result = $db->query("SELECT * FROM `" . $table . "` WHERE title LIKE '$titleWithPoundEscaped%' ");

        $row = mysqli_fetch_assoc($result);
        if ($result->num_rows > 0) {
            $titleMissing01 = $title;
            $titlesMissing01Array[] = array('title' => $titleMissing01);

            $title = $title01;
            $result->free();
        }
    }

    $result = null;
    $titleEscaped = $db->real_escape_string($title);
    $result = $db->query("SELECT * FROM `" . $table . "` WHERE title = '$titleEscaped'");
    $row = mysqli_fetch_assoc($result);

    if ($result->num_rows > 0) {
        $titlesArray['duplicate'] = true;
        $titlesArray['id'] = $row['id'];
        $titlesArray['dateCreatedInDB'] = $row['date_created'];
        $titlesArray['dimensionsInDB'] = $row['dimensions'];
        $titlesArray['sizeInDB'] = $row['filesize'];
        $titlesArray['durationInDB'] = $row['duration'];
        $titlesArray['pathInDB'] = $row['filepath'];
        $isLarger = $titlesArray['isLarger'] = compareFileSizeToDB($titlesArray['titleSize'], $titlesArray['sizeInDB']);

        $duplicateTitlesArray[] = array('title' => $title, 'isLarger' => $isLarger);
        $result->free();
    } else {
        $titlesArray['id'] = addToDB($titleEscaped, $titleSize, $titleDimensions, $titleDuration, $titlePath, $db, $table);
        $result->free();
    }

    $db->close();
}
function addToDB($title, $titleSize, $titleDimensions, $titleDuration, $titlePath, $db, $table)
{
    //$title = $db->real_escape_string($title);
    $pattern1 = '/to move\//i';
    $pattern2 = '/names fixed\//i';
    $replaceWith = 'recorded/';

    $titlePath = preg_replace(array($pattern1, $pattern2), $replaceWith, $titlePath);
    $titlePath = $db->real_escape_string($titlePath);

    if ($db->query(
        "INSERT IGNORE INTO `" . $table . "` (title, dimensions, filesize, duration, filepath, date_created) VALUES ('$title', '$titleDimensions', '$titleSize', '$titleDuration', '$titlePath', NOW())"
    )) {
        $row = mysqli_fetch_assoc($db->query("SELECT * FROM `" . $table . "` WHERE title = '$title'"));

        $returnedID = $row['id'];
        return $returnedID;
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

function searchSessionForDuplicateFiles($duplicateTitlesArray)
{

    $pattern1 = '/ - Scene.*/i';
    $pattern2 = '/ - CD.*/i';
    $pattern3 = '/ - Bonus.*| Bonus.*/i';

    for ($i = 0; $i < count($duplicateTitlesArray); $i++) {

        foreach ($_SESSION["files"] as $file) {
            $path = $file["path"];
            $fileNameNoExtension = $file["fileNameNoExtension"];

            $fileNameNoExtension = preg_replace(array($pattern1, $pattern2, $pattern3), '', $fileNameNoExtension);

            if ((stripos($duplicateTitlesArray[$i]['title'], $fileNameNoExtension) === 0)) {

                $fileName = $file["fileName"];

                $destination = $path . "\\duplicates\\";

                if ($duplicateTitlesArray[$i]["isLarger"] == true) {

                    $destination = $path . "\\duplicates\\larger\\";
                }
                moveDuplicateFiles($path, $destination, $fileName);
            }
        }
    }
}
function moveDuplicateFiles($path, $destination, $fileName)
{
    if (!is_dir($destination)) {
        mkdir($destination, 0777, true);
    }

    if (!is_file($$path . "\\" . $fileName)) {
        $file_to_rename = $path . "\\" . $fileName;
        $rename_file = $destination . $fileName;

        str_replace("'", "\'", $rename_file);
        rename($file_to_rename, $rename_file);
    }
}

function moveRecordedFile($directory, $title, $filesArray)
{
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
        if ($file['title'] == stripslashes($title)) {
            $rename_file = $destination . $file['baseName'];
            str_replace("'", "\'", $rename_file);
            rename($file['Path'], $rename_file);
        }
    }
}

function updateSize($title, $size, $db, $table)
{
    $db->query("UPDATE `" . $table . "` SET filesize='$size' WHERE title='$title'");
}

function updatefileDimensions($title, $fileDimensions, $db, $table)
{
    $db->query("UPDATE `" . $table . "` SET fileDimensions='$fileDimensions' WHERE title='$title'");
}

function updateDuration($title, $duration, $db, $table)
{
    $db->query("UPDATE `" . $table . "` SET duration='$duration' WHERE title='$title'");
}

function updatePath($title, $path, $db, $table)
{
    $db->query("UPDATE `" . $table . "` SET filepath='$path' WHERE title='$title'");
}

function updateDB($title, $id, $db, $table)
{
    $db->query("UPDATE `" . $table . "` SET title='$title' WHERE id='$id'");
}

function returnHTML($titlesArray)
{
    $titlesArray = array_values($titlesArray);

    include "safe_json_encode.php";
    echo safe_json_encode($titlesArray);
}
