<?php

$dirName = "/Volumes/Recorded 2/bad files/";
echo "dirName: " . $dirName . "\n";

$files = array();
    $index = 0;

foreach (new DirectoryIterator($dirName) as $fileInfo) {
    if ($fileInfo->isDot() || $fileInfo->getBasename() === '.DS_Store') {
        continue;
    }

    $fileName = $fileInfo->getBasename();
    $fileNameAndPath = $fileInfo->getPathname();

    $myfile = fopen("data/testFiles.txt", "a") or die("Unable to open file!");

    if (fopen($fileNameAndPath, "r")) {
        echo "$fileName is readable\n";
    } else {
        echo "$fileName is not readable\n";
    }
    // $fileNameAndPath = $fileInfo->getPathname();
    // $fileSize = filesize($fileInfo->getPathname());
    // readFile($fileName);
    //print_r(sort($files));
}

// function readFile($fileName)
// {
//     if (is_readable($fileName)) {
//         echo 'The file is readable';
//     } else {
//         echo "$fileName is not readable\n";
//     }
//

    // $myfile = fopen("data/testFiles.txt", "a") or die("Unable to open file!");
    // $txt = $fileName . " " . $fileSize . "\n";
    // //echo "Size of " . $fileName . " is " . $fileSize . "\n";
    //
    // fwrite($myfile, $txt);
    // fclose($myfile);
//}
