<?php

// include('getPlayLength.php');
include('getDimensions.php');
//$dirName = "/Volumes/Recorded 2/recorded odd file types/";
$dirName = "/Volumes/Recorded 2/processing/";
echo "dirName: " . $dirName . "\n";

$files = array();
$files2 = array();
    $index = 0;

    $pattern1 = '/ - Scene.*/';
    $pattern2 = '/ - CD.*/';
    //$pattern3 = '/.m.*/';
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

    $fileName = $fileInfo->getBasename();
    $fileName = preg_replace(array($pattern1, $pattern2, $pattern3, $pattern4, $pattern5, $pattern6, $pattern7, $pattern8, $pattern9, $pattern10), '', $fileName);
    $fileNameAndPath = $fileInfo->getPathname();
    $fileSize = filesize($fileInfo->getPathname());

    $dimensions = getDimensions($fileNameAndPath);
    //$dimensions = "1280 x 1024";
    $files[] = array('Name' => $fileName, 'Dimensions' => $dimensions, 'Size' => $fileSize, 'Path' => $fileNameAndPath);
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
    $files2[$i] = array('Name' => $nm, 'Dimensions' => $dm, 'Size' => $sz);
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
    checkDatabaseForMovie($files2ReducedSizesSummed[$i]["Name"], $files2ReducedSizesSummed[$i]["Size"], $files2ReducedSizesSummed[$i]["Dimensions"]);
}

function checkDatabaseForMovie($title, $size, $dimensions)
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

    $numOne = " # 01";

    if (strpos($title, $numOne) !== false) {
        echo "\n\n$title contains $numOne " . "11111111111111111111111111111 \n\n";
        $result = $mysqli->query("SELECT * FROM movies WHERE title = '$title'");
        if ($result->num_rows > 0) {
            updateDB($title, $dimensions, $size, $id, $mysqli);
        } else {
            $tmpTitle = explode("$numOne", $title);
            echo "tmp title: " . $tmpTitle[0] . "\n";
            $result = $mysqli->query("SELECT * FROM movies WHERE title = '$tmpTitle[0]'");
            if ($result->num_rows > 0) {
                updateDB($title, $dimensions, $size, $id, $mysqli);
                echo "Title updated from " . $tmpTitle[0] . " to " . $title . "\n";
            }
        }
    } elseif (preg_match('/# [0-9]+$/', $title)) {
        echo "\n\n$title contains number other than 01 " . "2222222222222222222222222222 \n\n";
        $tmpTitle = preg_split('/# [0-9]+/', $title);
        //echo "tmp title other than one: " . $tmpTitle[0] . "\n";
        $result = $mysqli->query("SELECT * FROM movies WHERE title = '$tmpTitle[0]'");
        if ($result->num_rows > 0) {
            $newTitle = $tmpTitle[0] . $numOne;
            echo "newTitle: $newTitle \n";
            $result = $mysqli->query("UPDATE movies SET title='$newTitle' WHERE title='$tmpTitle[0]'");
            echo "Title updated from " . $tmpTitle[0] . " to " . $newTitle . "\n";
            //updateDB($newTitle, $dimensions, $size, $id, $mysqli);
        }
        $result = $mysqli->query("SELECT * FROM movies WHERE title = '$title'");
        if ($result->num_rows > 0) {
            updateDB($title, $dimensions, $size, $id, $mysqli);
        } else {
            addToDB($title, $dimensions, $size, $mysqli);
        }
    } else {
        echo "\n\n$title contains neither " . "---------------------------------n\n";
        $result = $mysqli->query("SELECT * FROM movies WHERE title = '$title'");
        if ($result->num_rows > 0) {
            updateDB($title, $dimensions, $size, $id, $mysqli);
        } else {
            $newTitle = $title . $numOne;
            echo "newTitle: $newTitle \n";
            $result = $mysqli->query("SELECT * FROM movies WHERE title = '$newTitle'");
            if ($result->num_rows > 0) {
                $title = $newTitle;
                updateDB($title, $dimensions, $size, $id, $mysqli);
            } else {
                addToDB($title, $dimensions, $size, $mysqli);
            }
        }
    }
    $mysqli->close();
}
function updateDB($title, $dimensions, $size, $id, $mysqli)
{
    echo "In database: " . stripslashes($title) . "\n";
    $result = $mysqli->query("UPDATE movies SET title='$title', dimensions='$dimensions', filesize='$size' WHERE id='$id'");
    updateFile("upd", $title, $dimensions, $size);
}

function addToDB($title, $dimensions, $size, $mysqli)
{
    echo "Not in database: " . stripslashes($title) . "\n";
    $result = $mysqli->query("INSERT IGNORE INTO movies (title, dimensions, filesize, date_created) VALUES ('$title', '$dimensions', '$size', NOW())");
    updateFile("nid", $title, $dimensions, $size);
}

function updateFile($status, $title, $dimensions, $size)
{
    if ($status == "upd") {
        $myfile = fopen("data/UpdatedInDB.txt", "a") or die("Unable to open file!");
        $txt = stripslashes($title) . " " . $size . " " . $dimensions;
        echo "Size of " . stripslashes($title) . " updated to " . $size . "\n";
        echo "Dimensions of " . stripslashes($title) . " updated to " . $dimensions . "\n";
    } elseif ($status == "nid") {
        $myfile = fopen("data/NotInDb.txt", "a") or die("Unable to open file!");
        $txt = stripslashes($title) . " " . $size . " " . $dimensions . "\n";
        echo stripslashes($title) . " added to database \n ";
    }

    fwrite($myfile, $txt . "\n");
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
