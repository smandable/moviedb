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

$pattern1 = '/\.[a-z1-9]{3,4}$/i';
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
    $pathInDB = '';
    $dateCreatedInDB = '';
    $id = '';
    $files2[$i] = array('Name' => $nm, 'Dimensions' => $dm, 'Size' => $sz, 'Size in DB' => $sizeInDB, 'Duration' => $du, 'DurationInDB' => $durationInDB, 'Path' => $ph, 'PathInDB' => $pathInDB, 'Duplicate' => $isDupe, 'Larger' => $isLarger, 'Date Created' => $dateCreatedInDB, 'ID' => $id);
}

$files2ReducedSizesSummed = array_reduce($files2, function ($a, $b) {
    if (isset($a[$b['Name']])) {
        $a[$b['Name']]['Size'] += $b['Size'];
        $a[$b['Name']]['Duration'] += $b['Duration'];
    // $a[$b['Name']]['Path'] = $a['Path'];
    } else {
        $a[$b['Name']] = $b;
    }
    return $a;
});
$files2ReducedSizesSummed = array_values($files2ReducedSizesSummed);
$lengthFiles2 = count($files2ReducedSizesSummed);

for ($i=0;$i<$lengthFiles2;$i++) {
    checkDatabaseForMovie($files2ReducedSizesSummed[$i]["Name"], $files2ReducedSizesSummed[$i]["Dimensions"], $files2ReducedSizesSummed[$i]["Size"], $files2ReducedSizesSummed[$i]["Size in DB"], $files2ReducedSizesSummed[$i]["Duration"], $files2ReducedSizesSummed[$i]["DurationInDB"], $files2ReducedSizesSummed[$i]["Path"], $files2ReducedSizesSummed[$i]["Path in DB"], $files2ReducedSizesSummed[$i]["Duplicate"], $files2ReducedSizesSummed[$i]["Larger"], $files2ReducedSizesSummed[$i]["Date Created"], $files2ReducedSizesSummed[$i]["ID"], $dirName, $files, $filesMissingNumOne);
}
function checkDatabaseForMovie(&$title, $dimensions, $size, &$sizeInDB, $duration, &$durationInDB, $path, &$pathInDB, &$isDupe, &$isLarger, &$dateCreatedInDB, &$id, $dirName, $files, &$filesMissingNumOne)
{
    include "db_connect.php";
    //$titleUe = $title;
    $title = $db->real_escape_string($title);
    $result = $db->query("SELECT * FROM `".$table."` WHERE title = '$title'");
    $row = mysqli_fetch_assoc($result);
    $id = $row['id'];
    $titleInDB = $row['title'];
    $dimensionsInDB = $row['dimensions'];
    $sizeInDB = $row['filesize'];
    $durationInDB = $row['duration'];
    $dateCreatedInDB = $row['date_created'];
    $pathInDB = $row['filepath'];
    $spacePoundSpace01 = " # 01";

    //If title being read from directory HAS a number in it, look for that title in the DB WITHOUT a number. If found, add " # 01" to it. This is only to update that record in the db. There's no comparison here.
    if (preg_match('/# [0-9]+$/', $title)) {
        $tmpTitle = preg_split('/ # [0-9]+/', $title);
        $result2 = $db->query("SELECT * FROM `".$table."` WHERE title = '$tmpTitle[0]'");
        if ($result2->num_rows > 0) {
            $newTitle = $tmpTitle[0].$spacePoundSpace01;
            $result2 = $db->query("UPDATE `".$table."` SET title='$newTitle' WHERE title='$tmpTitle[0]'");
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
            $pathInDB = $row2['filepath'];
            compareFileSizeToDB($size, $sizeInDB, $isLarger);
            moveDuplicateFile($title, $dirName, $files);

            $filesMissingNumOne[] = array('title' => $title);
            findFilesToRename($filesMissingNumOne, $dirName);

            $title = $tmpTitle;
            $title = stripslashes($title);
            return;
        }
    }

    if ($result->num_rows > 0) {
        $isDupe = true;
        compareFileSizeToDB($size, $sizeInDB, $isLarger);
        moveDuplicateFile($title, $dirName, $files);
    } else {
        $id = addToDB($title, $dimensions, $size, $duration, $path, $db, $table, $dirName);
        moveRecordedFile($title, $dirName, $files);
    }
    $title = stripslashes($title);
    $result->close();
    $db->close();
}
function addToDB($title, $dimensions, $size, $duration, $path, $db, $table, $dirName)
{
    $pattern1 = '/to move\//i';
    $pattern2 = '/names fixed\//i';
    $replaceWith = 'recorded/';

    $path = preg_replace(array($pattern1, $pattern2), $replaceWith, $path);

    $result = $db->query("INSERT IGNORE INTO `".$table."` (title, dimensions, filesize, duration, filepath, date_created) VALUES ('$title', '$dimensions', '$size', '$duration', '$path', NOW())");
    $newResult = $db->query("SELECT * FROM `".$table."` WHERE title = '$title'");
    $newRow = mysqli_fetch_assoc($newResult);
    $newIDToReturn = $newRow['id'];
    return $newIDToReturn;
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
function moveDuplicateFile($title, $dirName, $files)
{
    $destination = $dirName.'duplicates/';
    if (!is_dir($destination)) {
        mkdir($destination, 0777, true);
    }
    foreach ($files as $file) {
        if (!is_file($file['Path'])) {
            continue;
        }
        if ($file['Name'] == stripslashes($title)) {
            $rename_file = $destination.$file['baseName'];
            // quickLogFile($titleUe, $rename_file, $dirName);
            str_replace("'", "\'", $rename_file);
            //$rename_file = addslashes($rename_file);
            // quickLogFile($titleUe, $rename_file, $dirName);
            rename($file['Path'], $rename_file);
        }
    }
}
function moveRecordedFile($title, $dirName, $files)
{
    $pattern1 = '/to move\//i';
    $pattern2 = '/names fixed\//i';
    $replaceWith = 'recorded/';

    $dirName = preg_replace(array($pattern1, $pattern2), $replaceWith, $dirName);

    $destination = $dirName;

    if (!is_dir($destination)) {
        mkdir($destination, 0777, true);
    }
    foreach ($files as $file) {
        if (!is_file($file['Path'])) {
            continue;
        }
        if ($file['Name'] == stripslashes($title)) {
            $rename_file = $destination.$file['baseName'];
            // quickLogFile($titleUe, $rename_file, $dirName);
            str_replace("'", "\'", $rename_file);
            //$rename_file = addslashes($rename_file);
            // quickLogFile($titleUe, $rename_file, $dirName);
            rename($file['Path'], $rename_file);
        }
    }
}
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
        // echo "titleMissingOne: $titleMissingOne\n";

        foreach ($iterator as $file) {
            if ($file->getBasename() === '.' || $file->getBasename() === '..' || $file->getBasename() === '.DS_Store') {
                continue;
            }
            // echo "iterating\n";
            $fileName = $file->getBasename();
            $originalFileName = $fileName;
            $fileExtension =pathinfo($file->getBasename(), PATHINFO_EXTENSION);
            $fileExtension = "." . $fileExtension;
            $fileName = preg_replace($pattern1, '', $fileName);
            // echo "fileName: $fileName\n";

            if (strcasecmp($titleMissingOne, $fileName) == 0) {
                $fileName = $fileName . $spacePoundSpace01 . $fileExtension;
                str_replace("'", "\'", $fileName);
                // echo "fileName no patterns: $fileName\n";
                rename($dirName.$originalFileName, $dirName.$fileName);
            }

            if ($beginningOfFileName = stristr($fileName, ' - Scene_', true)) {
                if (strcasecmp($titleMissingOne, $beginningOfFileName) == 0) {
                    $tmpFileName = preg_split('/ - /', $fileName);
                    $fileName = $tmpFileName[0] . $spacePoundSpace01 . $spaceDashSpace . $tmpFileName[1] . $fileExtension;
                    str_replace("'", "\'", $fileName);
                    // echo "fileName after splitting and joining: $fileName\n";
                    rename($dirName.$originalFileName, $dirName.$fileName);
                }
            }
            if ($beginningOfFileName = stristr($fileName, ' - CD', true)) {
                if (strcasecmp($titleMissingOne, $beginningOfFileName) == 0) {
                    $tmpFileName = preg_split('/ - /', $fileName);
                    $fileName = $tmpFileName[0] . $spacePoundSpace01 . $spaceDashSpace . $tmpFileName[1] . $fileExtension;
                    str_replace("'", "\'", $fileName);
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
        $id = $files2ReducedSizesSummed[$i]["ID"];
        $returnedArray['data'][$i] = array('Name' => $nm, 'Dimensions' => $dm, 'Size' => $sz, 'Duration' => $du, 'DurationInDB' => $dudb, 'Path' => $ph, 'Duplicate' => $isd, 'Larger' => $isl, 'Size in DB' => $sdb, 'Date Created' => $dcd, 'ID' => $id);
    }
    echo json_encode($returnedArray);
}
function quickLogFile($title, $rename_file, $dirName)
{
    $myfile = fopen("$dirName/updated.txt", "a") or die("Unable to open file!");

    $txt = "$title\t\t$rename_file\t$rename_file\n\n";

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
