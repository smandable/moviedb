<?php

include('getPlayLength.php');
include('getDimensions.php');

// $dirName = $_POST['dirName'];
// $dirName = $mysqli->real_escape_string($dirName);\

$dirName = "/Volumes/Recorded 1/test/";
echo "dirName: " . $dirName . "\n";

$files = array();
    $index = 0;

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
    if ($fileInfo->isDot() || $fileInfo->getBasename() === '.DS_Store') {
        continue;
    }

    $fileName = $fileInfo->getBasename();
    $fileName = preg_replace(array($pattern1, $pattern2, $pattern3, $pattern4, $pattern5, $pattern6, $pattern7, $pattern8, $pattern9, $pattern10), '', $fileName);
    $fileNameAndPath = $fileInfo->getPathname();
    $fileSize = filesize($fileInfo->getPathname());
    // $dimensions = getDimensions($fileNameAndPath);
    $dimensions = "1280x1024";
    $files[] = array('Name' => $fileName, 'Size' => $fileSize, 'Dimensions' => $dimensions, 'Path' => $fileNameAndPath);
}
$filesSorted = array();
foreach ($files as $key => $row) {
    $filesSorted[$key] = $row['Path'];
}
array_multisort($filesSorted, SORT_ASC, $files);

echo "\n\n";
echo "files: \n";
print_r($files);
echo "\n\n";

$keys = array_keys($files);

$lengthFiles = count($files);

for ($i=0;$i<$lengthFiles;$i++) {
    $nm = $files[$i]["Name"];
    $sz = $files[$i]["Size"];
    $dm = $files[$i]["Dimensions"];
    $files2[$i] = array('Name' => $nm, 'Size' => $sz, 'Dimensions' => $dm);
}
print_r($files2);

$files2ReducedSizesSummed = array_reduce($files2, function ($a, $b) {
    if (isset($a[$b['Name']])) {
        $a[$b['Name']]['Size'] += $b['Size'];
    } else {
        $a[$b['Name']] = $b;
    }

    return $a;
});

$files2ReducedSizesSummed = array_values($files2ReducedSizesSummed);

echo "files2ReducedSizesSummed: \n";
print_r($files2ReducedSizesSummed);
echo "\n\n";

$lengthFiles2 = count($files2ReducedSizesSummed);
for ($i=0;$i<$lengthFiles2;$i++) {
    checkDatabaseForMovie($files2ReducedSizesSummed[$i]["Name"], $files2ReducedSizesSummed[$i]["Size"], $files2ReducedSizesSummed[$i]["Dimensions"]);
}

function checkDatabaseForMovie($title, $size, $dimensions)
{
    echo "in checkDatabaseForMovie() \n";
    echo "$title \n";
    echo "$size \n";
    echo "$dimensions \n";
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

    echo "row: \n"
    print_r($row) . "\n";

    if (!$result) {
        die($mysqli->error);
    }

    if ($result->num_rows > 0) {
        // echo "In database: " . stripslashes($title) . "\n";

        $result = $mysqli->query("UPDATE movies SET filesize='$size', dimensions='$dimensions' WHERE title='$title'");
        echo "Size of " . stripslashes($title) . " updated to " . $size . "\n";
        $myfile = fopen("UpdatedInDB.txt", "a") or die("Unable to open file!");
        $txt = stripslashes($title) . " " . $size . " " . $dimensions;
        fwrite($myfile, $txt . "\n");
        fclose($myfile);
    } else {
        $result = $mysqli->query("INSERT IGNORE INTO movies (title, dimensions, filesize, date_created) VALUES ('$title', '$dimensions', '$filesize', NOW())");

        echo "New record " . stripslashes($title) . " created successfully";

        $myfile = fopen("AddedToDB.txt", "a") or die("Unable to open file!");
        $txt = stripslashes($title) . " " . $size . " " . $dimensions;
        fwrite($myfile, $txt . "\n");
        fclose($myfile);
    }

    $mysqli->close();
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
