<?php

include_once('libraries/getID3-master/getid3/getid3.php');
function getDimensions($filename)
{
    $getID3 = new getID3;
    $file = $getID3->analyze($filename);

    if (isset($file['video']['resolution_x']) && isset($file['video']['resolution_x'])) {
        $dimensions = $file['video']['resolution_x']. " x " . $file['video']['resolution_y'];
    // $myfile = fopen("dimensions.txt", "a") or die("Unable to open file!");
        // $txt = stripslashes($filename) . "    ". $dimensions . "\n";
        // fwrite($myfile, $txt . "\n");
        // fclose($myfile);
    } else {
        $myfile = fopen("dimensions-errors.txt", "a") or die("Unable to open file!");
        $txt = stripslashes($filename) . "\t\t\t error getting dimensions" . "\n";
        fwrite($myfile, $txt);
        fclose($myfile);
    }
    // $dimensions = $file['video']['resolution_x']. " x " . $file['video']['resolution_y'];

    // $myfile = fopen("dimensions.txt", "a") or die("Unable to open file!");
    // $txt = stripslashes($filename) . "    ". $dimensions . "\n";
    // fwrite($myfile, $txt . "\n");
    // fclose($myfile);

    return $dimensions;
}
