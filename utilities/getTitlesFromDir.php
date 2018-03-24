<?php

$directory = "/Volumes/External WD 8TB/tmp/names fixed/";

$titles = array();

if ($handle = opendir($directory)) {
    while (false !== ($entry = readdir($handle))) {
        if ($entry != "." && $entry != "..") {

            $pattern1 = '/ - Scene.*/';
            $pattern2 = '/ - CD.*/';
            $pattern3 = '/.mp4.*/';
            $pattern4 = '/.mkv.*/';
            $pattern5 = '/.wmv.*/';
            $pattern6 = '/.avi.*/';
            $pattern7 = '/.m4v.*/';

            $entry = preg_replace( array($pattern1, $pattern2, $pattern3, $pattern4, $pattern5, $pattern6, $pattern7), '', $entry );

            $titles[] = $entry;
            $titlesNoDuplicates = array_unique($titles);

        }
    }
    closedir($handle);
}

foreach ($titlesNoDuplicates as $title) {
    echo "$title\n";
}

?>
