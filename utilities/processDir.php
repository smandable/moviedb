<?php

$dirName = $_POST['dname'];

// $lines = file($dirName);


$files = array();
foreach (new DirectoryIterator($dirName) as $fileInfo) {
    if ($fileInfo->isDot() || !$fileInfo->isFile()) {
        continue;
    }
    $files[] = $fileInfo->getFilename();
}

// modify http header to json
 header('Cache-Control: no-cache, must-revalidate');
 header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
 header('Content-type: application/json');

echo json_encode($files);
