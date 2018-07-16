<?php
include_once('libraries/getID3-master/getid3/getid3.php');

$path = $_POST['path'];
$dirName = $_POST['dirName'];
$isDuplicate = $_POST['isDuplicate'];

if ($isDuplicate == "true") {
    // echo "isDuplicate: $isDuplicate\n";
    // echo "path: $path\n";
    $x='duplicates/';
    $fos=pathinfo($path);

    $path=$fos['dirname'].DIRECTORY_SEPARATOR.$x.$fos['basename'];

    // echo "new path: $path\n";
    checkFile($path);
} elseif ($isDuplicate == "false") {
    // echo "isDuplicate: $isDuplicate\n";
    // echo "path: $path\n";
    $path = $_POST['path'];
    checkFile($path);
}

function checkFile($path)
{
    $getID3 = new getID3();

    $file = $getID3->analyze($path);
    $dimensions = $file['video']['resolution_x']. " x " . $file['video']['resolution_y'];
    echoResult($dimensions);
}

function echoResult($dimensions)
{
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
    header('Content-type: application/json');

    echo json_encode($dimensions);
}
