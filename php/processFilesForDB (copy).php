<?php

ini_set('max_execution_time', 0);

require 'formatSize.php';

$directory = $_POST['directory'];

if (empty($_POST['directory'])) {
    echo 'Directory is required.';
    exit();
}
//$options = $_POST['options'];

//$options vars are: moveDuplicateFile, updateSize, updatefileDimensions, updateDuration, updatePath, moveRecordedFile

//$options = array("false", "false", "false", "false", "true", "false");
//$options = array("true","false","false","false","false","false");
$options = array("false", "true", "true", "true", "true", "false");
$titlesArray = array();

session_id("files");
session_start();

createTitlesArray();
checkDatabaseForMovie($directory, $options, $titlesArray);

returnHTML();

function createTitlesArray()
{
    foreach ($_SESSION["files"] as $file) {
        $path = $file["path"];
        $fileName = $file["fileName"];
        //$fileExtension = $file["fileExtension"];
        $title = $file["fileNameNoExtension"];
        $fileSize = $file["fileSize"];
        $fileDimensions = $file["fileDimensions"];
        $fileDuration = $file["fileDuration"];

        //$pattern1 = '/\.[a-z1-9]{3,4}$/i';
        $pattern1 = '/ - Scene.*/i';
        $pattern2 = '/ - CD.*/i';
        $pattern3 = '/ - Bonus.*| Bonus.*/i';

        // $fileName = $fileInfo->getBasename();
        $title = preg_replace(array($pattern1, $pattern2, $pattern3), '', $title);

        // $fileNameAndPath = $fileInfo->getPathname();
        // $fileSize = filesize($fileInfo->getPathname());
        // $fileExtension = pathinfo($fileInfo->getBasename(), PATHINFO_EXTENSION);
        // $fileDimensions = getfileDimensions($fileNameAndPath);
        // $duration = getDuration($fileNameAndPath);
        // quickLogFile($fileNameAndPath, $fileDimensions, $duration);

        $titlesArray[] = array(
            'fileSize' => $fileSize,
            'fileDimensions' => $fileDimensions,
            'fileDuration' => $fileDuration,
            'title' => $title
        );

        // 'SizeInDB' => '',
        // 'Path' => $fileNameAndPath,
        // 'PathInDB' => '',
        // 'Duplicate' => false,
        // 'isLarger' => false,
        // 'DateCreatedInDB' => '',
        // 'ID' => ''
        // );

    }

    $titlesArray = array_reduce(
        $titlesArray,
        function ($a, $b) {
            if (isset($a[$b['title']])) {
                $a[$b['title']]['fileSize'] += $b['fileSize'];
                $a[$b['title']]['fileDuration'] += $b['fileDuration'];
            } else {
                $a[$b['title']] = $b;
            }
            return $a;
        }
    );

    var_dump($titlesArray);
}
//$filesArrayReducedSizesSummed = array_reduce(
//     $filesArray,
//     function ($a, $b) {
//         if (isset($a[$b['title']])) {
//             $a[$b['title']]['Size'] += $b['Size'];
//             $a[$b['title']]['Duration'] += $b['Duration'];
//         } else {
//             $a[$b['title']] = $b;
//         }
//         return $a;
//     }
// );

// $filesSorted = array();

// foreach ($filesArray as $key => $row) {
//     $filesSorted[$key] = $row['Path'];
// }

//array_multisort($filesSorted, SORT_ASC, $filesArray);

// $filesArrayReducedSizesSummed = array_reduce(
//     $filesArray,
//     function ($a, $b) {
//         if (isset($a[$b['title']])) {
//             $a[$b['title']]['Size'] += $b['Size'];
//             $a[$b['title']]['Duration'] += $b['Duration'];
//         } else {
//             $a[$b['title']] = $b;
//         }
//         return $a;
//     }
// );

// $filesArrayReducedSizesSummed = array_values($filesArrayReducedSizesSummed);

// $lengthFilesArrayReducedSizesSummed = count($filesArrayReducedSizesSummed);

// for ($i = 0; $i < $lengthFilesArrayReducedSizesSummed; $i++) {
//     checkDatabaseForMovie($directory, $options, $filesArrayReducedSizesSummed[$i], $filesArray);
// }

function checkDatabaseForMovie($directory, $options, &$filesArray)
{
    $title = $filesArrayReducedSizesSummed['title'];
    $fileDimensions = $filesArrayReducedSizesSummed['fileDimensions'];
    $size = $filesArrayReducedSizesSummed['Size'];
    $duration = $filesArrayReducedSizesSummed['Duration'];
    $path = $filesArrayReducedSizesSummed['Path'];

    include 'db_connect.php';

    $title = $db->real_escape_string($title);
    $path = $db->real_escape_string($path);

    $spacePoundSpace = " # ";
    $spacePoundSpace01 = " # 01";

    // If file being read from directory HAS a number in it, look for that title in the DB WITHOUT a number.
    // If found, add " # 01" to it. This is only to update that record in the db.

    if (preg_match('/# [0-9]+$/', $title)) {
        $tmptitle = preg_split('/ # [0-9]+/', $title);
        $resultT = $db->query("SELECT * FROM `" . $table . "` WHERE title = '$tmptitle[0]'");

        if ($resultT->num_rows > 0) {
            $titleSpacePoundSpace01 = $tmptitle[0] . $spacePoundSpace01;
            $db->query("UPDATE `" . $table . "` SET title='$titleSpacePoundSpace01' WHERE title='$tmptitle[0]'");
            $rowT = mysqli_fetch_assoc($resultT);
            $idT = $rowT['id'];
            $originaltitle = $rowT['title'];
            $pathT = $rowT['filepath'];

            if ($pathT != "") {
                $pathT = str_replace($originaltitle, $titleSpacePoundSpace01, $pathT);
                $db->query("UPDATE `" . $table . "` SET filepath='$pathT' WHERE id='$idT'");
            }
        }
    }

    // If file being read from directory DOES NOT have a number in it, look for that title in the DB WITH a number + " 01".
    // If found, add file to $filesMissingSpacePoundSpace01 array, and set $title to $title + # 01

    if (!preg_match('/# [0-9]+$/', $title)) {
        $titleSpacePoundSpace = $title . $spacePoundSpace;
        $titleSpacePoundSpace01 = $title . $spacePoundSpace01;

        $resultN = $db->query("SELECT * FROM `" . $table . "` WHERE title = '$titleSpacePoundSpace01'");
        $rowN = mysqli_fetch_assoc($resultN);
        if ($resultN->num_rows > 0) {
            $filesMissingSpacePoundSpace01[] = array('title' => $title);
            $title = $titleSpacePoundSpace01;
        }
        $resultW = $db->query("SELECT * FROM `" . $table . "` WHERE title LIKE '$titleSpacePoundSpace%'");
        $rowW = mysqli_fetch_assoc($resultW);
        if ($resultW->num_rows > 0) {
            $filesMissingSpacePoundSpace01[] = array('title' => $title);
            $title = $titleSpacePoundSpace01;
        }
    }

    $result = $db->query("SELECT * FROM `" . $table . "` WHERE title = '$title'");
    $row = mysqli_fetch_assoc($result);

    if ($result->num_rows > 0) {
        $filesArrayReducedSizesSummed['Duplicate'] = true;
        $filesArrayReducedSizesSummed['ID'] = $row['id'];
        $filesArrayReducedSizesSummed['DateCreatedInDB'] = $row['date_created'];
        $filesArrayReducedSizesSummed['SizeInDB'] = $row['filesize'];
        $filesArrayReducedSizesSummed['DurationInDB'] = $row['duration'];
        $filesArrayReducedSizesSummed['PathInDB'] = $row['filepath'];
        $filesArrayReducedSizesSummed['isLarger'] = compareFileSizeToDB($filesArrayReducedSizesSummed['Size'], $filesArrayReducedSizesSummed['SizeInDB']);

        if ($options[0] == "true") {
            moveDuplicateFile($title, $filesArray);
        }
        if ($options[1] == "true") {
            updateSize($title, $size, $db, $table);
        }
        if ($options[2] == "true") {
            updatefileDimensions($title, $fileDimensions, $db, $table);
        }
        if ($options[3] == "true") {
            updateDuration($title, $duration, $db, $table);
        }
        if ($options[4] == "true") {
            updatePath($title, $path, $db, $table);
        }
    } else {
        $filesArrayReducedSizesSummed['ID'] = addToDB($title, $fileDimensions, $size, $duration, $path, $db, $table);

        if ($options[5] == "true") {
            moveRecordedFile($directory, $title, $filesArray);
        }
    }

    $db->close();
}

function addToDB($title, $fileDimensions, $size, $duration, $path, $db, $table)
{
    $pattern1 = '/to move\//i';
    $pattern2 = '/names fixed\//i';
    $replaceWith = 'recorded/';

    $path = preg_replace(array($pattern1, $pattern2), $replaceWith, $path);

    if ($db->query(
        "INSERT IGNORE INTO `" . $table . "` (title, fileDimensions, filesize, duration, filepath, date_created) VALUES ('$title', '$fileDimensions', '$size', '$duration', '$path', NOW())"
    )) {
        $newRow = mysqli_fetch_assoc($db->query("SELECT * FROM `" . $table . "` WHERE title = '$title'"));

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

function moveDuplicateFiles($title, $filesArray)
{
    global $directory;
    $destination = $directory . 'duplicates/';
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

function findFilesToRename($directory, $filesMissingSpacePoundSpace01)
{
    $directory = $directory . 'duplicates/';
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
            $fileExtension = pathinfo($file->getBasename(), PATHINFO_EXTENSION);
            $fileExtension = "." . $fileExtension;
            $fileName = preg_replace($pattern1, '', $fileName);

            if (strcasecmp($titleMissingOne, $fileName) == 0) {
                $fileName = $fileName . $spacePoundSpace01 . $fileExtension;
                str_replace("'", "\'", $fileName);
                rename($directory . $originalFileName, $directory . $fileName);
            }

            if ($beginningOfFileName = stristr($fileName, ' - Scene_', true)) {
                if (strcasecmp($titleMissingOne, $beginningOfFileName) == 0) {
                    $tmpFileName = preg_split('/ - /', $fileName);
                    $fileName = $tmpFileName[0] . $spacePoundSpace01 . $spaceDashSpace . $tmpFileName[1] . $fileExtension;
                    str_replace("'", "\'", $fileName);
                    rename($directory . $originalFileName, $directory . $fileName);
                }
            }
            if ($beginningOfFileName = stristr($fileName, ' - CD', true)) {
                if (strcasecmp($titleMissingOne, $beginningOfFileName) == 0) {
                    $tmpFileName = preg_split('/ - /', $fileName);
                    $fileName = $tmpFileName[0] . $spacePoundSpace01 . $spaceDashSpace . $tmpFileName[1] . $fileExtension;
                    str_replace("'", "\'", $fileName);
                    rename($directory . $originalFileName, $directory . $fileName);
                }
            }
        }
    }
}

//returnHTML($filesArrayReducedSizesSummed);

function returnHTML($filesArrayReducedSizesSummed)
{
    $lengthFilesArrayReducedSizesSummed = count($filesArrayReducedSizesSummed);
    $returnedArray = array();

    for ($i = 0; $i < $lengthFilesArrayReducedSizesSummed; $i++) {
        $returnedArray['data'][$i] = array(
            'title' => $filesArrayReducedSizesSummed[$i]["title"],
            'fileDimensions' => $filesArrayReducedSizesSummed[$i]["fileDimensions"],
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
    require "safe_json_encode.php";
    echo safe_json_encode($returnedArray);
}

function quickLogFile($fileNameAndPath, $fileDimensions, $duration)
{
    global $directory;
    $myfile = fopen("$directory/fileDimensions_duration.txt", "a") or die("Unable to open file!");

    $txt = "$fileNameAndPath\t$fileDimensions\t$duration\n\n";

    fwrite($myfile, $txt);
    fclose($myfile);
}