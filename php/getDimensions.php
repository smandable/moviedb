<?php

function getDimensions($fileNameAndPath)
{
    $path = $fileNameAndPath;
    //$path = str_replace("'", "'\''", $path);
    $path = escapeshellarg($path);

    //exec("/usr/bin/ffprobe -v error -select_streams v:0 -show_entries stream=width,height -of default=noprint_wrappers=1 '$path'", $O, $S); /*- linux */

    exec("C:\\ffmpeg\\bin\\ffprobe.exe -v error -of flat=s=_ -select_streams v:0 -show_entries stream=width,height $path", $O, $S);

    $dimensions = [
        "width" => explode("=", $O[0])[1],
        "height" => explode("=", $O[1])[1],
    ];

    if (!empty($O)) {
        $dimensions = [
            "width" => explode("=", $O[0])[1],
            "height" => explode("=", $O[1])[1],
        ];

        $dimensionsString = $dimensions["width"] . " x " . $dimensions["height"];
    } else {
        $dimensionsString = "";
    }

    return $dimensionsString;
}
