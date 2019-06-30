<?php

function getDimensions($fileNameAndPath)
{
    $path = $fileNameAndPath;
    $path = str_replace("'", "'\''", $path);

    exec("/usr/local/bin/ffprobe -v error -select_streams v:0 -show_entries stream=width,height -of default=noprint_wrappers=1 '$path'", $O, $S);
    if (!empty($O)) {
        $dimensions = [
                "width"=>explode("=", $O[0])[1],
                "height"=>explode("=", $O[1])[1],
        ];

        $dimensionsString = $dimensions["width"] . " x " . $dimensions["height"];
    } else {
        $dimensionsString = "";
    }
    return $dimensionsString;
}
