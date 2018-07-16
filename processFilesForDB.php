<?php

include('getDimensionsCMDln.php');
include('formatSize.php');

$dirName = $_POST['dirName'];

if (empty($_POST['dirName'])) {
    echo 'Directory is required.';
    exit();
}

$files = array();
$files2 = array();

    $pattern1 = '/ - Scene.*/';
    $pattern2 = '/ - CD.*/';
    $pattern3 = '/.mp4.*/';
    $pattern4 = '/.mkv.*/';
    $pattern5 = '/.wmv.*/';
    $pattern6 = '/.avi.*/';
    $pattern7 = '/.m4v.*/';
    $pattern8 = '/.mov.*/';
    $pattern9 = '/.flv.*/';
    $pattern10 = '/ - Bonus.*| Bonus.*/';
$totalSize = "";

foreach (new DirectoryIterator($dirName) as $fileInfo) {
    if ($fileInfo->isDir() || $fileInfo->isDot() || $fileInfo->getBasename() === '.DS_Store'
    || $fileInfo->getBasename() === 'DimensionsErrors.txt'|| $fileInfo->getBasename() === 'Duplicates.txt'
    || $fileInfo->getBasename() === 'SizeComparison.txt' || $fileInfo->getBasename() === 'NotInDb.txt'
    || $fileInfo->getBasename() === 'UpdatedInDb.txt') {
        continue;
    }
    $fileName = $fileInfo->getBasename();
    $fileName = preg_replace(array($pattern1, $pattern2, $pattern3, $pattern4, $pattern5, $pattern6, $pattern7, $pattern8, $pattern9, $pattern10), '', $fileName);
    $baseName = $fileInfo->getBasename();
    $fileNameAndPath = $fileInfo->getPathname();
    $fileSize = filesize($fileInfo->getPathname());

    $dimensions = getDimensions($fileNameAndPath);
    $files[] = array('Name' => $fileName, 'baseName' => $baseName, 'Dimensions' => $dimensions, 'Size' => $fileSize, 'Path' => $fileNameAndPath);
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
    $files2[$i] = array('Name' => $nm, 'Dimensions' => $dm, 'Size' => $sz, 'Path' => $ph, 'Duplicate' => $isDupe, 'Larger' => $isLarger);
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
    checkDatabaseForMovie($files2ReducedSizesSummed[$i]["Name"], $files2ReducedSizesSummed[$i]["Dimensions"], $files2ReducedSizesSummed[$i]["Size"], $files2ReducedSizesSummed[$i]["Duplicate"], $files2ReducedSizesSummed[$i]["Larger"], $dirName, $files);
}

function checkDatabaseForMovie($title, $dimensions, $size, &$isDupe, &$isLarger, $dirName, $files)
{
    $config = include('config/config.php');
    $mysqli = new mysqli($config->host, $config->username, $config->pass, $config->database);

    if (mysqli_connect_errno()) {
        printf("Connect failed: %s\n", mysqli_connect_error());
        exit();
    }

    $title = $mysqli->real_escape_string($title);
    $result = $mysqli->query("SELECT * FROM movies WHERE title = '$title'");
    $row = mysqli_fetch_assoc($result);
    $id = $row['id'];
    $titleInDB = $row['title'];
    $dimensionsInDB = $row['dimensions'];
    $sizeInDB = $row['filesize'];

    $numOne = "# 01";

    if (preg_match('/# [0-9]+$/', $title)) {
        $tmpTitle = preg_split('/ # [0-9]+/', $title);

        $result = $mysqli->query("SELECT * FROM movies WHERE title = '$tmpTitle[0]'");
        if ($result->num_rows > 0) {
            $newTitle = $tmpTitle[0]." ".$numOne;
            $result = $mysqli->query("UPDATE movies SET title='$newTitle' WHERE title='$tmpTitle[0]'");
            //echo "Title updated in DB from " . $tmpTitle[0] . " to " . $newTitle . "\n";
            updateDB($title, $mysqli, $id);
        }
    } else {
        $newTitle = $title. " " .$numOne;
        $result = $mysqli->query("SELECT * FROM movies WHERE title = '$newTitle'");
        if ($result->num_rows > 0) {
            //echo "$newTitle is in database\n";
            moveDuplicateFile($title, $dirName, $files);
            $isDupe = true;
            return;
        }
    }

    $result = $mysqli->query("SELECT * FROM movies WHERE title = '$title'");

    if ($result->num_rows > 0) {
        //echo "$title is in database \n";
        compareFileSizeToDB($title, $titleInDB, $size, $sizeInDB, $dimensions, $dimensionsInDB, $isLarger, $dirName);
        moveDuplicateFile($title, $dirName, $files);
        $isDupe = true;
    } else {
        //echo "$title is not yet in database \n";
        addToDB($title, $dimensions, $size, $mysqli);
    }

    $mysqli->close();
}
function addToDB($title, $dimensions, $size, $mysqli)
{
    //echo "Not in database, so adding: " . stripslashes($title) . "\n";
    $result = $mysqli->query("INSERT IGNORE INTO movies (title, dimensions, filesize, date_created) VALUES ('$title', '$dimensions', '$size', NOW())");
}
function updateDB($title, $mysqli, $id)
{
    //echo "In database, so updating: " . stripslashes($title) . "\n";
    $result = $mysqli->query("UPDATE movies SET title='$title' WHERE id='$id'");
}
function compareFileSizeToDB($title, $titleInDB, $size, $sizeInDB, $dimensions, $dimensionsInDB, &$isLarger, $dirName)
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

        $returnedArray['data'][$i] = array('Name' => $nm, 'Dimensions' => $dm, 'Size' => $sz, 'Duplicate' => $isd, 'Larger' => $isl, 'Path' => $ph);
    }
    echo json_encode($returnedArray);
}

function renameFile($title, $newTitle, $dirName, $files)
{
    $destination = $dirName;

    foreach ($files as $file) {
        if (!is_file($file['Path'])) {
            continue;
        }

        //$fileExtension =pathinfo($file['baseName'], PATHINFO_EXTENSION);
        //$newTitle = $newTitle . "." . $fileExtension;

        if ($file['Name'] == $title) {
            $rename_file = $destination.$newTitle;
            rename($file['baseName'], $rename_file);
        }
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
        if ($file['Name'] == $title) {
            $rename_file = $destination.$file['baseName'];
            rename($file['Path'], $rename_file);
        }
    }
}

function updateFile($status, $dirName, $title, $dimensions, $size, $titleInDB, $dimensionsInDB, $sizeInDB)
{
    $size = formatSize($size);
    $sizeInDB = formatSize($sizeInDB);

    if ($status == "fid") {
        $myfile = fopen("$dirName/Duplicates.txt", "a") or die("Unable to open file!");
        $txt = stripslashes($titleInDB) . " " . $dimensionsInDB . " " . $sizeInDB . "\n\n";
    } elseif ($status == "nid") {
        $myfile = fopen("$dirName/NotInDb.txt", "a") or die("Unable to open file!");
        $txt = stripslashes($title) . " " . $dimensions . " " . $size . "\n\n";
    } elseif ($status == "upd") {
        $myfile = fopen("$dirName/UpdatedInDb.txt", "a") or die("Unable to open file!");
        $txt = "Title updated in DB from " . $titleInDB . " to " . $title . "\n\n";
    }

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
