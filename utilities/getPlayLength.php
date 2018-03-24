<?php

include_once('libraries/getID3-master/getid3/getid3.php');
function getPlayLength($filename)
{
    $getID3 = new getID3;
    $file = $getID3->analyze($filename);
    $playLength = $file['playtime_string'];

    return $playLength;
}
