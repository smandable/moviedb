<?php

ini_set('max_execution_time', 0);

include('getDimensionsCMDln.php');
include('formatSize.php');

$dirName = $_POST['dirName'];

if (empty($_POST['dirName'])) {
    echo 'Directory is required.';
    exit();
}

$files = array();
$files2 = array();
// $pattern1 = '/\\.[^.\\s]{3,4}$/';
$pattern1 = '/\.[a-z1-9]{3,4}$/';
$pattern2 = '/ - Scene.*/i';
$pattern3 = '/ - CD.*/i';
$pattern4 = '/ - Bonus.*| Bonus.*/i';

$directory = new \RecursiveDirectoryIterator($dirName);
$iterator = new \RecursiveIteratorIterator($directory);

    foreach ($iterator as $fileInfo) {
        if ($fileInfo->getBasename() === '.' || $fileInfo->getBasename() === '..' || $fileInfo->getBasename() === '.DS_Store'
        || $fileInfo->getBasename() === 'Thumbs.db' || $fileInfo->getBasename() === '.AppleDouble'|| $fileInfo->getBasename() === 'updated.txt') {
            continue;
        }

        $baseName = $fileInfo->getBasename();
        $fileName = $baseName;
        $fileName = preg_replace(array($pattern1, $pattern2, $pattern3, $pattern4), '', $fileName);

        $fileNameAndPath = $fileInfo->getPathname();
        $fileSize = filesize($fileInfo->getPathname());
        $fileExtension =pathinfo($fileInfo->getBasename(), PATHINFO_EXTENSION);

        $dimensions = getDimensions($fileNameAndPath);

        $files[] = array('Name' => $fileName, 'baseName' => $baseName, 'fileExtension' => $fileExtension, 'Dimensions' => $dimensions, 'Size' => $fileSize, 'Path' => $fileNameAndPath);
    }

$filesSorted = array();

foreach ($files as $key => $row) {
    $filesSorted[$key] = $row['Path'];
}

array_multisort($filesSorted, SORT_ASC, $files);

$lengthFiles = count($files);

for ($i=0;$i<$lengthFiles;$i++) {
    $nm = $files[$i]["Name"];
    $dm = $files[$i]["Dimensions"];
    $sz = $files[$i]["Size"];
    $ph = $files[$i]["Path"];
    $isDupe = false;
    $isLarger = false;
    $sizeInDB = '';
    $dateCreatedInDB = '';
    $id = '';
    $files2[$i] = array('Name' => $nm, 'Dimensions' => $dm, 'Size' => $sz, 'Path' => $ph, 'Duplicate' => $isDupe, 'Larger' => $isLarger, 'Size in DB' => $sizeInDB,'Date Created' => $dateCreatedInDB, 'Id' => $id);
}

$files2ReducedSizesSummed = array_reduce($files2, function ($a, $b) {
    if (isset($a[$b['Name']])) {
        $a[$b['Name']]['Size'] += $b['Size'];
    } else {
        $a[$b['Name']] = $b;
    }
    return $a;
});

$files2ReducedSizesSummed = array_values($files2ReducedSizesSummed);
$lengthFiles2 = count($files2ReducedSizesSummed);

for ($i=0;$i<$lengthFiles2;$i++) {
    //checkDatabaseForMovie($files2ReducedSizesSummed[$i]["Name"], $files2ReducedSizesSummed[$i]["Dimensions"], $files2ReducedSizesSummed[$i]["Size"], $files2ReducedSizesSummed[$i]["Duplicate"], $files2ReducedSizesSummed[$i]["Larger"], $dirName, $files);
    checkDatabaseForMovie($files2ReducedSizesSummed[$i]["Name"], $files2ReducedSizesSummed[$i]["Dimensions"], $files2ReducedSizesSummed[$i]["Size"], $files2ReducedSizesSummed[$i]["Duplicate"], $files2ReducedSizesSummed[$i]["Larger"], $files2ReducedSizesSummed[$i]["Size in DB"], $files2ReducedSizesSummed[$i]["Date Created"], $files2ReducedSizesSummed[$i]["Id"], $dirName, $files);
}

function checkDatabaseForMovie($title, $dimensions, $size, &$isDupe, &$isLarger, &$sizeInDB, &$dateCreatedInDB, &$id, $dirName, $files)
{
    include "db_connect.php";
    $titleUe = $title;
    $title = $db->real_escape_string($title);
    $result = $db->query("SELECT * FROM `".$table."` WHERE title = '$title'");
    $row = mysqli_fetch_assoc($result);
    $id = $row['id'];
    $titleInDB = $row['title'];
    $dimensionsInDB = $row['dimensions'];
    $sizeInDB = $row['filesize'];
    $dateCreatedInDB = $row['date_created'];
    $numOne = "# 01";

    //If title being read from directory HAS a number in it, look for that title in the DB WITHOUT a number. If found, add " # 01" to it.
    if (preg_match('/# [0-9]+$/', $title)) {
        $tmpTitle = preg_split('/ # [0-9]+/', $title);
        $result2 = $db->query("SELECT * FROM `".$table."` WHERE title = '$tmpTitle[0]'");
        if ($result2->num_rows > 0) {
            $newTitle = $tmpTitle[0]." ".$numOne;
            $result2 = $db->query("UPDATE `".$table."` SET title='$newTitle' WHERE title='$tmpTitle[0]'");
        }
    }

    //If title being read from directory DOES NOT have a number in it, look for that title in the DB WITH a number + " 01". If found, mark file as duplicate.
    $regex = "/# [0-9]/";
    if (!preg_match($regex, $title)) {
        $tmpTitle = $title." ".$numOne;
        $result2 = $db->query("SELECT * FROM `".$table."` WHERE title = '$tmpTitle'");
        $row2 = mysqli_fetch_assoc($result2);
        if ($result2->num_rows > 0) {
            $isDupe = true;
            $dateCreatedInDB = $row2['date_created'];

            $sizeInDB = $row2['filesize'];
            compareFileSizeToDB($size, $sizeInDB, $isLarger);

            //Caution here.. this is for normalization, really...
            //updateSizeAndDimensions($title, $dimensions, $dimensionsInDB, $size, $sizeInDB, $db, $table, $dirName);
            //End caution...

            //renameSingleFile($title, $dirName, $files);

            moveDuplicateFile($titleUe, $dirName, $files);
            return;
        }
    }

    if ($result->num_rows > 0) {
        $isDupe = true;
        compareFileSizeToDB($size, $sizeInDB, $isLarger);

        //Caution here.. this is for normalization, really...
        //updateSizeAndDimensions($title, $dimensions, $dimensionsInDB, $size, $sizeInDB, $db, $table, $dirName);
        //End caution...

        moveDuplicateFile($titleUe, $dirName, $files);
    } else {
        $id = addToDB($title, $dimensions, $size, $db, $table, $dirName);
    }

    $result->close();
    $db->close();
}

function addToDB($title, $dimensions, $size, $db, $table, $dirName)
{
    $result = $db->query("INSERT IGNORE INTO `".$table."` (title, dimensions, filesize, date_created) VALUES ('$title', '$dimensions', '$size', NOW())");
    $newResult = $db->query("SELECT * FROM `".$table."` WHERE title = '$title'");
    $newRow = mysqli_fetch_assoc($newResult);
    $newIdToReturn = $newRow['id'];
    // quickLogFile($title, $newIdToReturn, $dirName);
    return $newIdToReturn;
}
function updateSizeAndDimensions($title, $dimensions, $dimensionsInDB, $size, $sizeInDB, $db, $table, $dirName)
{
    if (($sizeInDB == null) || ($sizeInDB < $size)) {
        $result = $db->query("UPDATE `".$table."` SET dimensions='$dimensions' WHERE title='$title'");
        $result = $db->query("UPDATE `".$table."` SET filesize='$size' WHERE title='$title'");
        //quickLogFile($title, $size, $sizeInDB, $dimensions, $dimensionsInDB, $dirName);
    }
}

function updateDB($title, $db, $id, $table)
{
    $result = $db->query("UPDATE `".$table."` SET title='$title' WHERE id='$id'");
}

function compareFileSizeToDB($size, $sizeInDB, &$isLarger)
{
    if (($sizeInDB > 0) && ($sizeInDB < $size)) {
        $isLarger = true;
    }
}

returnHTML($files2ReducedSizesSummed);

function returnHTML($files2ReducedSizesSummed)
{
    $lengthFiles2ReducedSizesSummed = count($files2ReducedSizesSummed);
    $returnedArray = array();

    for ($i=0;$i<$lengthFiles2ReducedSizesSummed;$i++) {
        $nm = $files2ReducedSizesSummed[$i]["Name"];
        $dm = $files2ReducedSizesSummed[$i]["Dimensions"];
        $sz = $files2ReducedSizesSummed[$i]["Size"];
        $ph = $files2ReducedSizesSummed[$i]["Path"];
        $isd = $files2ReducedSizesSummed[$i]["Duplicate"];
        $isl = $files2ReducedSizesSummed[$i]["Larger"];
        $sdb = $files2ReducedSizesSummed[$i]['Size in DB'];
        $dcd = $files2ReducedSizesSummed[$i]["Date Created"];
        $id = $files2ReducedSizesSummed[$i]["Id"];
        $returnedArray['data'][$i] = array('Name' => $nm, 'Dimensions' => $dm, 'Size' => $sz, 'Path' => $ph, 'Duplicate' => $isd, 'Larger' => $isl, 'Size in DB' => $sdb, 'Date Created' => $dcd, 'Id' => $id);
    }
    echo json_encode($returnedArray);
}

function moveDuplicateFile($titleUe, $dirName, $files)
{
    $destination = $dirName.'duplicates/';
    if (!is_dir($destination)) {
        mkdir($destination, 0777, true);
    }
    foreach ($files as $file) {
        if (!is_file($file['Path'])) {
            continue;
        }
        if ($file['Name'] == $titleUe) {
            $rename_file = $destination.$file['baseName'];
            str_replace("'", "\'", $rename_file);
            rename($file['Path'], $rename_file);
        }
    }
}

// function renameSingleFile($title, $newTitle, $dirName, $files)
// {
//     $destination = $dirName;
//
//     $pattern1 = '/\.[a-z1-9]{3,4}$/'
//     $pattern2 = '/ - Scene.*/i';
//     $pattern3 = '/ - CD.*/i';
//
// if ($newTitle = preg_match($pattern3)) {
//     $pattern = '/(\s)(- )(CD)/';
//     $rep = ' # 01 $2$3';
//     $newTitle = preg_replace($pattern, $rep, $newTitle);
//     rename($dirName.title, $dirName.$newTitle);
//   } else if ($newTitle = preg_match($pattern2)) {
//     $pattern = '/(\s)(- )(Scene)/';
//     $rep = ' # 01 $2$3';
//     $newTitle = preg_replace($pattern, $rep, $newTitle);
//         rename($dirName.title, $dirName.$newTitle);
//   } else {
//     $pattern = '/([\.])([a-z1-9]{3,4})$/';
//     $rep = ' # 01.$2';
//     $newTitle = preg_replace($pattern, $rep, $newTitle);
//         rename($dirName.title, $dirName.$newTitle);
//   }
//
//     // foreach ($files as $file) {
//     //     if (!is_file($file['Path'])) {
//     //         continue;
//     //     }
//
//
//         //$fileExtension =pathinfo($file['baseName'], PATHINFO_EXTENSION);
//         //$newTitle = $newTitle . "." . $fileExtension;
//
//         // if ($file['Name'] == $title) {
//         //     $rename_file = $destination.$newTitle;
//         //     rename($file['baseName'], $rename_file);
//         // }
//     //}
// }

function quickLogFile($title, $newIdToReturn, $dirName)
{
    $myfile = fopen("$dirName/updated.txt", "a") or die("Unable to open file!");
    // $txt = "$title\t\t  . 'size in db: ' . $sizeInDB . 'new size: ' . $size  . 'dimensions in db: ' . $dimensionsInDB  . 'new dimensions: ' . \t\t\n";

    $txt = "$newIdToReturn\t$title\n\n";

    fwrite($myfile, $txt);
    fclose($myfile);
}

function super_unique($array, $key)
{
    $temp_array = [];
    foreach ($array as &$v) {
        if (!isset($temp_array[$v[$key]])) {
            $temp_array[$v[$key]] =& $v;
        }
    }
    $array = array_values($temp_array);
    return $array;
}

function multi_array_key_exists($needle, $haystack)
{
    foreach ($haystack as $key => $value) :

        if ($needle == $key) {
            return true;
        }

    if (is_array($value)) :
             if (multi_array_key_exists($needle, $value) == true) {
                 return true;
             } else {
                 continue;
             }
    endif;
    endforeach;
    return false;
}
