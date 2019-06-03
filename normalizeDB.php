<?php

ini_set('max_execution_time', 0);

include('getDimensionsCMDln.php');
include('getDuration.php');
include('formatSize.php');

$dirName = $_POST['dirName'];

if (empty($_POST['dirName'])) {
    echo 'Directory is required.';
    exit();
}

$files = array();
$files2 = array();
$filesMissingNumOne = array();

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
    $duration = getDuration($fileNameAndPath);

    $files[] = array('Name' => $fileName, 'baseName' => $baseName, 'fileExtension' => $fileExtension, 'Dimensions' => $dimensions, 'Duration' => $duration, 'Size' => $fileSize, 'Path' => $fileNameAndPath);
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
    $du = $files[$i]["Duration"];
    $sz = $files[$i]["Size"];
    $ph = $files[$i]["Path"];
    $isDupe = false;
    $isLarger = false;
    $sizeInDB = '';
    $durationInDB = '';
    $dateCreatedInDB = '';
    $id = '';
    $files2[$i] = array('Name' => $nm, 'Dimensions' => $dm, 'Size' => $sz, 'Duration' => $du, 'DurationInDB' => $durationInDB, 'Path' => $ph, 'Duplicate' => $isDupe, 'Larger' => $isLarger, 'Size in DB' => $sizeInDB,'Date Created' => $dateCreatedInDB, 'Id' => $id);
}

$files2ReducedSizesSummed = array_reduce($files2, function ($a, $b) {
    if (isset($a[$b['Name']])) {
        $a[$b['Name']]['Size'] += $b['Size'];
        $a[$b['Name']]['Duration'] += $b['Duration'];
    } else {
        $a[$b['Name']] = $b;
    }
    return $a;
});
$files2ReducedSizesSummed = array_values($files2ReducedSizesSummed);
$lengthFiles2 = count($files2ReducedSizesSummed);

for ($i=0;$i<$lengthFiles2;$i++) {
    checkDatabaseForMovie($files2ReducedSizesSummed[$i]["Name"], $files2ReducedSizesSummed[$i]["Dimensions"], $files2ReducedSizesSummed[$i]["Size"], $files2ReducedSizesSummed[$i]["Duration"], $files2ReducedSizesSummed[$i]["DurationInDB"], $files2ReducedSizesSummed[$i]["Duplicate"], $files2ReducedSizesSummed[$i]["Larger"], $files2ReducedSizesSummed[$i]["Size in DB"], $files2ReducedSizesSummed[$i]["Date Created"], $files2ReducedSizesSummed[$i]["Id"], $dirName, $files, $filesMissingNumOne);
}
function checkDatabaseForMovie(&$title, $dimensions, $size, $duration, &$durationInDB, &$isDupe, &$isLarger, &$sizeInDB, &$dateCreatedInDB, &$id, $dirName, $files, &$filesMissingNumOne)
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
    $durationInDB = $row['duration'];
    $dateCreatedInDB = $row['date_created'];
    $spacePoundSpace01 = " # 01";

    //If title being read from directory HAS a number in it, look for that title in the DB WITHOUT a number. If found, add " # 01" to it. This is only to update that record in the db. There's no comparison here.
    if (preg_match('/# [0-9]+$/', $title)) {
        $tmpTitle = preg_split('/ # [0-9]+/', $title);
        $result2 = $db->query("SELECT * FROM `".$table."` WHERE title = '$tmpTitle[0]'");
        if ($result2->num_rows > 0) {
            $newTitle = $tmpTitle[0].$spacePoundSpace01;
            $result2 = $db->query("UPDATE `".$table."` SET title='$newTitle' WHERE title='$tmpTitle[0]'");
            //$title = $tmpTitle;
        }
    }

    //If title being read from directory DOES NOT have a number in it, look for that title in the DB WITH a number + " 01". If found, mark file as duplicate, then rename the file to filename + " # 01"
    if (!preg_match('/# [0-9]+$/', $title)) {
        $tmpTitle = $title.$spacePoundSpace01;
        $result2 = $db->query("SELECT * FROM `".$table."` WHERE title = '$tmpTitle'");
        $row2 = mysqli_fetch_assoc($result2);
        if ($result2->num_rows > 0) {
            $isDupe = true;
            $id = $row2['id'];
            $dateCreatedInDB = $row2['date_created'];
            $sizeInDB = $row2['filesize'];
            compareFileSizeToDB($size, $sizeInDB, $isLarger);

            //Caution here.. this is for normalization, really...
            //updateSizeDimensionsAndDuration($title, $dimensions, $dimensionsInDB, $size, $sizeInDB, $db, $table, $dirName);
            //End caution...

            $filesMissingNumOne[] = array('title' => $title);
            //findFilesToRename($filesMissingNumOne);

            //moveDuplicateFile($titleUe, $dirName, $files);
            $title = $tmpTitle;
            return;
        }
    }

    if ($result->num_rows > 0) {
        $isDupe = true;
        compareFileSizeToDB($size, $sizeInDB, $isLarger);

        //Caution here.. this is for normalization, really...
        updateSizeDimensionsAndDuration($title, $dimensions, $dimensionsInDB, $size, $sizeInDB, $duration, $durationInDB, $db, $table, $dirName);
    //End caution...

        //moveDuplicateFile($titleUe, $dirName, $files);
    } else {
        $id = addToDB($title, $dimensions, $size, $duration, $db, $table, $dirName);
    }

    $result->close();
    $db->close();
}
function addToDB($title, $dimensions, $size, $duration, $db, $table, $dirName)
{
    $result = $db->query("INSERT IGNORE INTO `".$table."` (title, dimensions, filesize, duration, date_created) VALUES ('$title', '$dimensions', '$size', '$duration', NOW())");
    $newResult = $db->query("SELECT * FROM `".$table."` WHERE title = '$title'");
    $newRow = mysqli_fetch_assoc($newResult);
    $newIdToReturn = $newRow['id'];
    // quickLogFile($title, $newIdToReturn, $dirName);
    return $newIdToReturn;
}
function updateSizeDimensionsAndDuration($title, $dimensions, $dimensionsInDB, $size, $sizeInDB, $duration, $durationInDB, $db, $table, $dirName)
{
    if (($sizeInDB == null) || ($sizeInDB < $size)) {
        $result = $db->query("UPDATE `".$table."` SET filesize='$size' WHERE title='$title'");
    }
    if (($dimensionsInDB == null) || ($dimensionsInDB < $dimensions)) {
        $result = $db->query("UPDATE `".$table."` SET dimensions='$dimensions' WHERE title='$title'");
    }
    if (($durationInDB == null) || ($durationInDB < $duration)) {
        $result = $db->query("UPDATE `".$table."` SET duration='$duration' WHERE title='$title'");
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
//findFilesToRename($filesMissingNumOne, $dirName);
function findFilesToRename($filesMissingNumOne, $dirName)
{
    $dirName = $dirName.'duplicates/';
    $directory = new \RecursiveDirectoryIterator($dirName);
    $iterator = new \RecursiveIteratorIterator($directory);
    $spacePoundSpace01 = ' # 01';
    $spaceDashSpace = ' - ';
    $pattern1 = '/\.[a-z1-9]{3,4}$/';

    foreach ($filesMissingNumOne as $fileMissingOne) {
        $titleMissingOne = $fileMissingOne['title'];
        //echo "titleMissingOne: $titleMissingOne\n";

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
                // echo "fileName no patterns: $fileName\n";
                rename($dirName.$originalFileName, $dirName.$fileName);
            }

            if ($beginningOfFileName = stristr($fileName, ' - Scene_', true)) {
                if (strcasecmp($titleMissingOne, $beginningOfFileName) == 0) {
                    $tmpFileName = preg_split('/ - /', $fileName);
                    $fileName = $tmpFileName[0] . $spacePoundSpace01 . $spaceDashSpace . $tmpFileName[1] . $fileExtension;
                    // echo "fileName after splitting and joining: $fileName\n";
                    rename($dirName.$originalFileName, $dirName.$fileName);
                }
            }
            if ($beginningOfFileName = stristr($fileName, ' - CD', true)) {
                if (strcasecmp($titleMissingOne, $beginningOfFileName) == 0) {
                    $tmpFileName = preg_split('/ - /', $fileName);
                    $fileName = $tmpFileName[0] . $spacePoundSpace01 . $spaceDashSpace . $tmpFileName[1] . $fileExtension;
                    // echo "fileName after splitting and joining: $fileName\n";
                    rename($dirName.$originalFileName, $dirName.$fileName);
                }
            }
        }
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
        $du = $files2ReducedSizesSummed[$i]["Duration"];
        $dudb = $files2ReducedSizesSummed[$i]["DurationInDB"];
        $ph = $files2ReducedSizesSummed[$i]["Path"];
        $isd = $files2ReducedSizesSummed[$i]["Duplicate"];
        $isl = $files2ReducedSizesSummed[$i]["Larger"];
        $sdb = $files2ReducedSizesSummed[$i]['Size in DB'];
        $dcd = $files2ReducedSizesSummed[$i]["Date Created"];
        $id = $files2ReducedSizesSummed[$i]["Id"];
        $returnedArray['data'][$i] = array('Name' => $nm, 'Dimensions' => $dm, 'Size' => $sz, 'Duration' => $du, 'DurationInDB' => $dudb, 'Path' => $ph, 'Duplicate' => $isd, 'Larger' => $isl, 'Size in DB' => $sdb, 'Date Created' => $dcd, 'Id' => $id);
    }
    echo json_encode($returnedArray);
}
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
