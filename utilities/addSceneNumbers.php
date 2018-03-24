<?php

// $dirName = "/Volumes/Recorded 3/names fixed/";
$dirName = "/Volumes/Recorded 1/test2/";
echo "dirName: " . $dirName . "\n";

$c = 1;
if ($handle = opendir($dirName)) {
    while (false !== ($fileName = readdir($handle))) {
        $newName = str_replace(" - ", " - Scene_$c ", $fileName);
        rename($fileName, $newName);
        echo "$newName \n";
        $c++;
    }
    closedir($handle);
}
