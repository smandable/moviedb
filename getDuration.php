<?php
function getDuration($file)
{
    $path = $file;
    $path = str_replace("'", "'\''", $path);
    $duration = exec("/usr/local/bin/ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 -sexagesimal '$path'");
    return $duration;
}
