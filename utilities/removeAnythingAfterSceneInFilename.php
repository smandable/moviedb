<?php


$dirName = "/Volumes/Recorded 1/test/";
echo "dirName: " . $dirName . "\n";


    $pattern1 = '/ - Scene.\d*/';


foreach (new DirectoryIterator($dirName) as $fileInfo) {
    if ($fileInfo->isDot() || $fileInfo->getBasename() === '.DS_Store') {
        continue;
    }

    $fileName = $fileInfo->getBasename();
    $fileName = preg_replace(array($pattern1), '$pattern1', $fileName);
    $fileNameAndPath = $fileInfo->getPathname();
    echo "$fileName\n";


    $myfile = fopen("filesRenamed.txt", "a") or die("Unable to open file!");
    // $txt = stripslashes($title) . " " . $size . " " . $dimensions;
    fwrite($myfile, $txt . "\n");
    fclose($myfile);
}

// echo "files2ReducedSizesSummed: \n";
// print_r($files2ReducedSizesSummed);
// echo "\n\n";
