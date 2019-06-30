<?php
function getDuration($file)
{
    $path = $file;
    $path = str_replace("'", "'\''", $path);
    // the -sexagesimal flag gives it in HOURS:MM:SS.MICROSECONDS
    $duration = exec("/usr/local/bin/ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 '$path'");
    return $duration;
}
