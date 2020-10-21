<?php

function getDuration($file)
{
    $path = $file;
    $path = str_replace("'", "'\''", $path);
    //$path = escapeshellarg($path);
    $duration = exec("/usr/bin/ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 '$path'"); /* linux */
    // $cmd = "C:\\ffmpeg\\bin\\ffprobe.exe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 $path";
    // $duration = exec($cmd);
    return $duration;
}
