<?php

$dirName = "/Volumes/Recorded 1/test/";
echo "dirName: " . $dirName . "\n";

// $lines = file($dirName);

// $file = '/path/to/your/file';
// $filesize = filesize($file); // bytes
// $filesize = round($filesize / 1024 / 1024, 1); // megabytes with 1 digit

// echo "The size of your file is $filesize MB.\n";

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

    // $fileNameAndPath = $fileInfo->getPathname();
    // $fileName = $fileInfo->getBasename();
    // $totalSize = filesize($fileInfo->getPathname());
    //
    // $files[] = array($fileNameAndPath, $fileName, $totalSize);
    // echo $files[$index][$fileNameAndPath][$fileName][$totalSize];

    // $totalSize = filesize($files[$index]);
    // $files[$index][$fileName][$totalSize];
    // echo 'Current file: ' . $files[$index][][] . "\n";
    //echo "Current file size:  $totalSize MB.\n";

    $fileName = $fileInfo->getBasename();
    // $files[$index] = preg_replace(array($pattern1, $pattern2, $pattern3, $pattern4, $pattern5, $pattern6, $pattern7), '', $files[$index]);

    $fileName = preg_replace(array($pattern1, $pattern2, $pattern3, $pattern4, $pattern5, $pattern6, $pattern7), '', $fileName);

    //$nextNameOnly = preg_replace(array($pattern1, $pattern2, $pattern3, $pattern4, $pattern5, $pattern6, $pattern7), '', $files[++$index]);

    //echo '$nameOnly: ' . $nameOnly . "\n";

    $fileNameAndPath = $fileInfo->getPathname();
    //$fileName = $fileInfo->getBasename();
    $totalSize = filesize($fileInfo->getPathname());

    $files[] = array('Name' => $fileName, 'Size' => $totalSize, 'Path' => $fileNameAndPath);


    //echo '$nextNameOnly: ' . $nextNameOnly . "\n";
    //$title = $nameOnly;

    //findMovie($nameOnly);

    // $totalSize = filesize($files[$index]);
    // $totalSize = round($totalSize / 1024 / 1024, 1);
    // echo "The size of your file is $totalSize MB.\n";

    //if ($nameOnly == $nextNameOnly) {
    //echo '$files[$index]: ' . $files[$index] . ' == ' . '$files[$index++]: ' . $files[$index++] . "\n";
    //$files[++$index] = $fileInfo->getPathname();
    // $nextSize = ($files[$index] = $fileInfo->getPathname();
    //echo '$files[++$index] = $fileInfo->getPathname(); ' . $files[++$index] = $fileInfo->getPathname() . "\n";
    // $files[$index] = $fileInfo->getPathname();
    // $totalSize = filesize($files[$index]);



    // $nextSize = filesize($files[++$index]);
    // echo "The size of the next file is $nextSize MB.\n";
    // $totalSize += $nextSize;
    // $totalSize = round($totalSize / 1024 / 1024 / 1024, 1);
    // echo 'new $totalSize: ' . $totalSize . "\n";
    //}
    //$index++;
}
$filesSorted = array();
foreach ($files as $key => $row) {
    $filesSorted[$key] = $row['Path'];
}
array_multisort($filesSorted, SORT_ASC, $files);
// sort($files);
echo "filesSorted";
print_r($filesSorted);

echo "\n\n";
echo "files";
print_r($files);

$keys = array_keys($files);
// var_dump($keys);
$keysS = array_keys($filesSorted);
// var_dump($keysS);
// var_dump($files[$keys[0]]);
// var_dump($files[$keys[0]]['Name']);
// var_dump($files[$keys[1]]['Name']);
echo "\n\n";
$length = count($files);
// echo '$length: ' . $length . "\n";
$files2 = array();
//$files2[] = array('Name' => "", 'Size' => 0);
// $files2[] = array('Name' => $fileName, 'Size' => $totalSize, 'Path' => $fileNameAndPath);

for ($i=0;$i<$length;$i++) {
    $nm = $files[$i]["Name"];
    $sz = $files[$i]["Size"];
    $files2[$i] = array('Name' => $nm, 'Size' => $sz);
}
$files2 = super_unique($files2, 'Name');
    print_r($files2);


for ($i=0;$i<$length;$i++) {
    // $c=0;
    // //foreach ($files[$i] as $key=>$value) {
    // $c++;
    // $nm = $files[$i]["Name"];
    // $sz = $files[$i]["Size"];
    // $files2[$i] = array('Name' => $nm, 'Size' => $sz);
    // //array_unique($files2);
    // print_r(super_unique($files2, 'Name'));

    // print_r(array_unique($files2));
    // if ($files2[$i]["Name"] != $files[$i]["Name"]) {
    //     echo "not equal";
    //     $files2[$i] = array('Name' => $nm, 'Size' => $sz);
    // }
    //if ($i<=$length) {
    $n = $i + 1;
    if ($n<$length) {
        // echo $key."=".$value;
        //echo $files[$i]["Name"];
        $totalSizeTmp = 0;
        if ($files[$i]["Name"] == $files[$n]["Name"]) {
            echo $files[$i]['Path'] . " is equal to " . $files[$n]['Path'] . "\n";
            //echo $files[$i]['Size'] . "\n";
            // if ($files[$i]["Name"] == $files2[$i]["Name"]) {
            $tmpN = $files[$i]['Name'];
            // $keys = array('$tmpN');
            //echo array_keys_exist($keys,$files2);
            if (multi_array_key_exists($tmpN, $files2)) {
                echo $files2[$i]["Name"] . ' exists';
                $files2[$i]["Size"] += $files[$n]["Size"];
                // echo "Size of " . $files2[$i]['Name'] . " is now: " . $files2[$i]['Size'] . "\n";
                // $totalSizeTmp = $files[$i]['Size'];
            // $totalSizeTmp += $files[$n]["Size"];
            // $files[$i]["Size"] = ($files[$i]["Size"] + $files[$n]["Size"]);
            // unset($files[$n]);
            // $files2 = array_values($files);
            }
            // echo "Size of " . $files2[$i]['Name'] . " is now: " . $files2[$i]['Size'] . "\n";
        }
        //}
    //}
    }
    // if ($c<count($files[$i])) {
    //     echo ",";
    // }
    //}
    echo "\n";
    // echo $key . ':' . $value . "\n";
    //echo $files[$keys[0]]['Name'] . "\n";

    // for ($j=1;$j<$length;$j++) {
    // $n1 = $files[$keys[$i]]['Name'];
    // echo '$n1: ' . $n1 . "\n";
    //
    // $n2 = next($files[$keys[$i]]['Name']);
    // echo  '$n2 ' . $n2 . "\n";
    //
    // echo strcasecmp($n1, $n2) . "\n";

    //if (strcasecmp($n1, $n2) == 0) {
        //echo PHP_EOL . "{$keys[0]} HAS VALUE {$files[$keys[0]]['Name']} THAT IS EQUAL TO THE VALUE OF {$files[$keys[1]]['Name']} IN {$keys[1]}";
    //}
    //}
}

// for ($i=0;$i<$files.length;$i++) {
//     echo toString($files[$i]);
//     // if ($files[$i]=>) {
//     //
//     // }
// }

echo "\n\n";

print_r($files2);

echo "\n\n";

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
function findMovie($title)
{
    echo "in findMovie() \n";
    echo "$title \n";
    include "db_connect.php";

    $title = $db->real_escape_string($title);

    $result = $db->query("SELECT * FROM `".$table."` WHERE title = '$title'");

    if (!$result) {
        die($db->error);
    }

    if ($result->num_rows > 0) {
        echo "In database: " . stripslashes($title) . "\n";
    }

    $results->close();
    $db->close();
}




// modify http header to json
 // header('Cache-Control: no-cache, must-revalidate');
 // header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
 // header('Content-type: application/json');

// echo json_encode($files);


//if next one until the last space matches, add filesize and check next one, next one... when there is no match, then use that size.


// $entry = preg_replace( array($pattern1, $pattern2, $pattern3, $pattern4, $pattern5, $pattern6, $pattern7), '', $entry );
