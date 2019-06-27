<?php

$dirName = "/Volumes/Recorded 1/recorded/";
echo "dirName: " . $dirName . "\n";

$numFilesNotInDB = 0;

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

    $files[] = array('Title' => $fileName, 'Size' => $fileSize, 'Path' => $fileNameAndPath);
}

$filesSorted = array();
foreach ($files as $key => $row) {
    $filesSorted[$key] = $row['Path'];
}
array_multisort($filesSorted, SORT_ASC, $files);

// echo "\n\n";
// echo "files: \n";
// print_r($files);
// echo "\n\n";

$keys = array_keys($files);

$lengthFiles = count($files);

for ($i=0;$i<$lengthFiles;$i++) {
    $nm = $files[$i]["Title"];
    $sz = $files[$i]["Size"];
    $files2[$i] = array('Title' => $nm, 'Size' => $sz);
}
// print_r($files2);

$files2ReducedSizesSummed = array_reduce($files2, function ($a, $b) {
    if (isset($a[$b['Title']])) {
        $a[$b['Title']]['Size'] += $b['Size'];
    } else {
        $a[$b['Title']] = $b;
    }

    return $a;
});

$files2ReducedSizesSummed = array_values($files2ReducedSizesSummed);

// echo "files2ReducedSizesSummed: \n";
// print_r($files2ReducedSizesSummed);
// echo "\n\n";

$lengthFiles2 = count($files2ReducedSizesSummed);
for ($i=0;$i<$lengthFiles2;$i++) {
    checkDatabaseForMovie($files2ReducedSizesSummed[$i]["Title"], $files2ReducedSizesSummed[$i]["Size"]);
}


function checkDatabaseForMovie($title, $size)
{
    include "db_connect.php";

    $title = $db->real_escape_string($title);

    $result = $db->query("SELECT * FROM `".$table."` WHERE title = '$title'");

    // $row = mysqli_fetch_assoc($result);
    //
    // print_r($row) . "\n";

    if (!$result) {
        die($db->error);
    }

    if ($result->num_rows > 0) {
    } else {
        echo "Not in database: " . stripslashes($title) . "\n";
        //$numFilesNotInDB++;
        $myfile = fopen("NotInDb.txt", "a") or die("Unable to open file!");
        $txt = stripslashes($title) . " " . $size;
        fwrite($myfile, $txt . "\n");
        fclose($myfile);
    }

    $results->close();
    $db->close();
}

echo "Not in DB: $numFilesNotInDB \n";

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
