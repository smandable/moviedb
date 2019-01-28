<?php

function getDimensions($fileNameAndPath)
{
    $path = $fileNameAndPath;
    $path = str_replace("'", "'\''", $path);

    exec("ffprobe -v error -show_entries stream=width,height -of default=noprint_wrappers=1 '$path'", $O, $S);

    if (!empty($O)) {

    print_r($S) . "\n";

        $dimensions = [
                "width"=>explode("=", $O[0])[1],
                "height"=>explode("=", $O[1])[1],
        ];

        $dimensionsString = $dimensions["width"] . " x " . $dimensions["height"];
    } else {
        $dimensionsString = "";
        echo "is empty\n";
    }
    return $dimensionsString;
}
