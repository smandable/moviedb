<?php

$dirName = "/Volumes/Recorded 1/test2/";
//$dirName = "/Users/sean/Download/tmp/";
echo "dirName: " . $dirName . "\n";

foreach (new DirectoryIterator($dirName) as $fileInfo) {
    if ($fileInfo->isDot() || $fileInfo->getBasename() === '.DS_Store') {
        continue;
    }

    $fileName = $fileInfo->getBasename();

    $files[] = $fileName;
}
$filesSorted = array();

sort($files);

$lengthFiles = count($files);

for ($i=0;$i<$lengthFiles;$i++) {
    $str = $files[$i];
    if (strpos($str, '  ') !== false) {
        echo $str . "\n";
    }
}
