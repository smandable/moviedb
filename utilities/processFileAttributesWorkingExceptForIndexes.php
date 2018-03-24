<?php

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
$totalSize = "";

foreach (new DirectoryIterator($dirName) as $fileInfo) {
    if ($fileInfo->isDot() || $fileInfo->getBasename() === '.DS_Store') {
        continue;
    }

    $fileName = $fileInfo->getBasename();
    $fileName = preg_replace(array($pattern1, $pattern2, $pattern3, $pattern4, $pattern5, $pattern6, $pattern7), '', $fileName);
    $fileNameAndPath = $fileInfo->getPathname();
    $totalSize = filesize($fileInfo->getPathname());
    $dimensions = getTheID3($fileNameAndPath);
    $files[] = array('Name' => $fileName, 'Size' => $totalSize, 'Dimensions' => $dimensions, 'Path' => $fileNameAndPath);
}
$filesSorted = array();
foreach ($files as $key => $row) {
    $filesSorted[$key] = $row['Path'];
}
array_multisort($filesSorted, SORT_ASC, $files);

echo "\n\n";
echo "files \n";
print_r($files);
echo "\n\n";

$keys = array_keys($files);

$lengthFiles = count($files);

for ($i=0;$i<$lengthFiles;$i++) {
    $nm = $files[$i]["Name"];
    $sz = $files[$i]["Size"];
    $files2[$i] = array('Name' => $nm, 'Size' => $sz);
}
print_r($files2);

$files2ReducedSizesSummed = array_reduce($files2, function ($a, $b) {
    isset($a[$b['Name']]) ? $a[$b['Name']]['Size'] += $b['Size'] : $a[$b['Name']] = $b;
    return $a;
});

$files2ReducedSizesSummed = array_values($files2ReducedSizesSummed);

print_r($files2ReducedSizesSummed);

$lengthFiles2 = count($files2ReducedSizesSummed);
for ($i=0;$i<$lengthFiles2;$i++) {
    findMovie($files2ReducedSizesSummed[$i]["Name"], $files2ReducedSizesSummed[$i]["Size"]);
}

function findMovie($title, $size)
{
    echo "in findMovie() \n";
    echo "$title \n";
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

    if (!$result) {
        die($mysqli->error);
    }

    if ($result->num_rows > 0) {
        echo "In database: " . stripslashes($title) . "\n";
        formatSize($size);
        //getTheID3($filename)
        $result = $mysqli->query("INSERT IGNORE INTO movies (size) VALUES ($size");
        echo "Size of " . stripslashes($title) . " updated to " . $size;
    }

    $mysqli->close();
}

function formatSize($size)
{
    if ($size >= 1073741824) {
        $size = number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($size >= 1048576) {
        $size = number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($size >= 1024) {
        $size = number_format($bytes / 1024, 2) . ' KB';
    }

    echo "$size \n";
    return $size;
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
