<?php

ini_set('max_execution_time', 0);

require 'formatSize.php';
require 'renameTheFilesMissing01.php';

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

session_id("files");
session_start();

populateTitlesArray($titlesArray);

$titlesArray = array_values($titlesArray);

for ($i = 0; $i < count($titlesArray); $i++) {
    checkDatabaseForTitle($directory, $titlesArray[$i], $duplicateTitlesArray);
}

//renameTheFilesMissing01($titlesArray);

$allFiles = $_SESSION["files"];

searchSessionForDuplicateFiles($duplicateTitlesArray, $allFiles);

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
function checkDatabaseForTitle($directory, &$titlesArray, &$duplicateTitlesArray)
{
    $title = $titlesArray["title"];
    $titleSize = $titlesArray["titleSize"];
    $titleDimensions = $titlesArray["titleDimensions"];
    $titleDuration = $titlesArray["titleDuration"];
    $titlePath = $titlesArray["titlePath"];

    include 'db_connect.php';

    $title = $db->real_escape_string($title);
    //$title = mysqli_real_escape_string($db, $title);

    $titlePath = $db->real_escape_string($titlePath);

    // If file being read from directory HAS a number in it, look for that title in the DB WITHOUT a number.
    // If found, add " # 01" to it. This is only to update that record in the db.

    if (preg_match('/# [0-9]+$/', $title)) {
        $titleN = preg_split('/ # [0-9]+/', $title);

        $result = $db->query("SELECT * FROM `" . $table . "` WHERE title = '$titleN[0]'");

        if ($result->num_rows > 0) {
            $title = $titleN[0] . " # 01";

            $db->query("UPDATE `" . $table . "` SET title='$title' WHERE title='$titleN[0]'");
            $row = mysqli_fetch_assoc($result);

            $id = $row['id'];
            $pathInDB = $row['filepath'];

            if ($pathInDB != "") {
                $pathInDB = str_replace($titleN[0], $title, $pathInDB);
                $db->query("UPDATE `" . $table . "` SET filepath='$pathInDB' WHERE id='$id'");
            }
        }
    }

    // If file being read from directory DOES NOT have a number in it, look for that title in the DB WITH a number + " 01".
    // If found, add file to $filesMissingSpacePoundSpace01 array, and set $title to $title + # 01. Then move into duplicates dir.

    if (!preg_match('/# [0-9]+$/', $title)) {
        $title01 = $title . " # 01";

        $result = $db->query("SELECT * FROM `" . $table . "` WHERE title = '$title01'");

        if ($result->num_rows > 0) {
            $fileMissing01 = true;

            $titlesArray['duplicate'] = true;
            $titlesArray["fileMissing01"] = $fileMissing01;

            $title = $title01;
            //    array_push($duplicateTitlesArray, $title);
        }
    }
    $result = null;
    $result = $db->query("SELECT * FROM `" . $table . "` WHERE title = '$title'");
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
    } else {
        $titlesArray['id'] = addToDB($title, $titleSize, $titleDimensions, $titleDuration, $titlePath, $db, $table);
    }

    $db->close();
}
function addToDB($title, $titleSize, $titleDimensions, $titleDuration, $titlePath, $db, $table)
{
    $pattern1 = '/to move\//i';
    $pattern2 = '/names fixed\//i';
    $replaceWith = 'recorded/';

    $titlePath = preg_replace(array($pattern1, $pattern2), $replaceWith, $titlePath);

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
function searchSessionForDuplicateFiles($duplicateTitlesArray, $allFiles)
{

    foreach ($allFiles as $file) {
        $path = $file["path"];
        $fileName = $file["fileName"];

        for ($i = 0; $i < count($duplicateTitlesArray); $i++) {
            if (strpos($file["fileNameNoExtension"], $duplicateTitlesArray[$i]['title']) > -1) {

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

    if (!is_file($path)) {
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

// function quickLogFile($directory, $duplicateTitle)
// {

//     $myfile = fopen("$directory/fileDimensions_duration.txt", "a") or die("Unable to open file!");

//     $txt = "$directory$destination\n\n";

//     fwrite($myfile, $txt);
//     fclose($myfile);
// }
