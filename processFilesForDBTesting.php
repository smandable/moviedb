<?php

// include('getPlayLength.php');
include('getDimensions.php');
include('formatSize.php');
//$dirName = "/Volumes/Recorded 2/recorded odd file types/";
 //$dirName = "/Volumes/Recorded 1/test/";
$dirName = "/Users/sean/Download/tmp/names fixed/";
$//dirName = "/Users/sean/Download/tmp/test/";
echo "dirName: " . $dirName . "\n";

$files = array();
$files2 = array();
    $index = 0;

    $pattern1 = '/ - Scene.*/';
    $pattern2 = '/ - CD.*/';
    //$pattern3 = '/.\.m[a-z].*/';
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
    if ($fileInfo->isDot() || $fileInfo->getBasename() === '.DS_Store') {
        continue;
    }
    $filetypes = array("txt", "log");
    $filetype = pathinfo($fileInfo, PATHINFO_EXTENSION);

    if (!in_array(strtolower($filetype), $filetypes)) {
        $fileName = $fileInfo->getBasename();
        $fileName = preg_replace(array($pattern1, $pattern2, $pattern3, $pattern4, $pattern5, $pattern6, $pattern7, $pattern8, $pattern9, $pattern10), '', $fileName);
        $baseName = $fileInfo->getBasename();
        $fileNameAndPath = $fileInfo->getPathname();
        $fileSize = filesize($fileInfo->getPathname());

        $dimensions = getDimensions($fileNameAndPath, $dirName);

        $files[] = array('Name' => $fileName, 'baseName' => $baseName, 'Dimensions' => $dimensions, 'Size' => $fileSize, 'Path' => $fileNameAndPath);
    }
}

$filesSorted = array();

foreach ($files as $key => $row) {
    $filesSorted[$key] = $row['Path'];
}

array_multisort($filesSorted, SORT_ASC, $files);

$keys = array_keys($files);

$lengthFiles = count($files);

for ($i=0;$i<$lengthFiles;$i++) {
    $nm = $files[$i]["Name"];
    $dm = $files[$i]["Dimensions"];
    $sz = $files[$i]["Size"];
    $ph = $files[$i]["Path"];
    $files2[$i] = array('Name' => $nm, 'Dimensions' => $dm, 'Size' => $sz, 'Path' => $ph);
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
    checkDatabaseForMovie($files2ReducedSizesSummed[$i]["Name"], $files2ReducedSizesSummed[$i]["Dimensions"], $files2ReducedSizesSummed[$i]["Size"], $dirName, $files);
}

function checkDatabaseForMovie($title, $dimensions, $size, $dirName, $files)
{
    $config = include('config/config.php');
    $mysqli = new mysqli($config->host, $config->username, $config->pass, $config->database);

    if (mysqli_connect_errno()) {
        printf("Connect failed: %s\n", mysqli_connect_error());
        exit();
    }
    if (!$mysqli->set_charset('utf8')) {
        printf("Error loading character set utf8: %s\n", $mysqli->error);
        exit();
    }

    $title = $mysqli->real_escape_string($title);

    $result = $mysqli->query("SELECT * FROM movies WHERE title = '$title'");
    $row = mysqli_fetch_assoc($result);
    $id = $row['id'];
    $titleInDB = $row['title'];
    $dimensionsInDB = $row['dimensions'];
    $sizeInDB = $row['filesize'];

    $numOne = " # 01";

    if (preg_match('/# [0-9]+$/', $title)) {
        echo "$title contains number" . "2222222222222222222222222222 \n";
        $tmpTitle = preg_split('/# [0-9]+/', $title);
        $result = $mysqli->query("SELECT * FROM movies WHERE title = '$tmpTitle[0]'");
        if ($result->num_rows > 0) {
            $newTitle = $tmpTitle[0] . $numOne;
            echo "$tmpTitle[0] is in database, to be updated to $newTitle \n";
            $result = $mysqli->query("UPDATE movies SET title='$newTitle' WHERE title='$tmpTitle[0]'");
            updateDB($title, $mysqli, $id);
            echo "Title updated in DB from " . $tmpTitle[0] . " to " . $newTitle . "\n";
            updateFile("upd", $dirName, $title, $dimensions, $size, $titleInDB, $dimensionsInDB, $sizeInDB);
        }
    } else {
        echo "$title contains no number ---------------------------------\n";
        $newTitle = $title . $numOne;
        echo "newTitle: $newTitle \n";
        $result = $mysqli->query("SELECT * FROM movies WHERE title = '$newTitle'");
        if ($result->num_rows > 0) {
            echo "$newTitle is in database\n";
            updateFile("fid", $dirName, $title, $dimensions, $size, $titleInDB, $dimensionsInDB, $sizeInDB);
            moveDuplicateFile($title, $dirName, $files);
        } else {
            $result = $mysqli->query("SELECT * FROM movies WHERE title = '$title'");
            if ($result->num_rows > 0) {
                echo "$title is in database \n";
                compareFileSizeToDB($title, $titleInDB, $size, $sizeInDB, $dimensions, $dimensionsInDB, $dirName);
                updateFile("fid", $dirName, $title, $dimensions, $size, $titleInDB, $dimensionsInDB, $sizeInDB);
                moveDuplicateFile($title, $dirName, $files);
            } else {
                echo "$title is not (yet) in database\n";
                addToDB($title, $dimensions, $size, $mysqli);
                updateFile("nid", $dirName, $title, $dimensions, $size, $titleInDB, $dimensionsInDB, $sizeInDB);
            }
        }
    }

    $mysqli->close();
}
function updateDB($title, $mysqli, $id)
{
    echo "In database: " . stripslashes($title) . "\n";
    $result = $mysqli->query("UPDATE movies SET title='$title' WHERE id='$id'");
    //updateFile("upd", $title);
}

function addToDB($title, $dimensions, $size, $mysqli)
{
    echo "Not in database: " . stripslashes($title) . "\n";
    $result = $mysqli->query("INSERT IGNORE INTO movies (title, dimensions, filesize, date_created) VALUES ('$title', '$dimensions', '$size', NOW())");
    // updateFile("nid", $dirName, $title, $dimensions, $size, $titleInDB, $dimensionsInDB, $sizeInDB);
}

function updateFile($status, $dirName, $title, $dimensions, $size, $titleInDB, $dimensionsInDB, $sizeInDB)
{
    $size = formatSize($size);
    $sizeInDB = formatSize($sizeInDB);

    if ($status == "fid") {
        $myfile = fopen("$dirName/Duplicates.txt", "a") or die("Unable to open file!");
        $txt = stripslashes($titleInDB) . " " . $dimensionsInDB . " " . $sizeInDB . "\n\n";
    //echo "Dimensions of " . stripslashes($title) . " updated to " . $dimensions . "\n";
    } elseif ($status == "nid") {
        $myfile = fopen("$dirName/NotInDb.txt", "a") or die("Unable to open file!");
        $txt = stripslashes($title) . " " . $dimensions . " " . $size . "\n\n";
        echo stripslashes($title) . " added to database \n ";
    } elseif ($status == "upd") {
        $myfile = fopen("$dirName/UpdatedInDb.txt", "a") or die("Unable to open file!");
        $txt = "Title updated in DB from " . $titleInDB . " to " . $title . "\n\n";
    }

    fwrite($myfile, $txt);
    fclose($myfile);
}

function compareFileSizeToDB($title, $titleInDB, $size, $sizeInDB, $dimensions, $dimensionsInDB, $dirName)
{
    echo "sizeInDB: " . $sizeInDB . "\n";
    echo "size: " . $size . "\n\n";
    //$myfile = fopen($dirName."sizeComparison.txt", "a") or die("Unable to open file!");
    $myfile = fopen("$dirName/sizeComparison.txt", "a") or die("Unable to open file!");
    if ($sizeInDB < $size) {
        //echo "Recorded $title is smaller than unrecorded file\n\n\n";
        $txt = "Smaller than unrecorded: " . stripslashes($title) . " " . $size . " " . $dimensions . "    vs.    " . $titleInDB . " " . $dimensionsInDB . " " . $sizeInDB . "\n\n";
    } elseif ($sizeInDB > $size) {
        //echo "Recorded $title is larger than unrecorded file\n\n\n";
        $txt = "Larger than unrecorded: " . stripslashes($title) . " " . $size . " " . $dimensions . "    vs.    " . $titleInDB . " " . $dimensionsInDB . " " . $sizeInDB . "\n\n";
    } else {
        //echo "Recorded $title is equal in size to unrecorded file\n\n\n";
        $txt = "Equal to unrecorded: " . stripslashes($title) . " " . $size . " " . $dimensions . "    vs.    " . $titleInDB . " " . $dimensionsInDB . " " . $sizeInDB . "\n\n";
    }

    fwrite($myfile, $txt);
    fclose($myfile);
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
        // $filetypes = array("txt", "log");
        // $filetype = pathinfo($fileInfo, PATHINFO_EXTENSION);

        // if (!in_array(strtolower($filetype), $filetypes)) {
        if ($file['Name'] == $title) {
            $newName = $file['baseName'];
            echo "newName: $newName\n";
            $rename_file = $destination.$file['baseName'];
            echo "rename_file: $rename_file\n";
            rename($file['Path'], $rename_file);
            echo "\n";
        }

    }
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
