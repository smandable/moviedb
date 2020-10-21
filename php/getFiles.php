<?php

require 'getDimensions.php';
require 'getDuration.php';

$directory = $_POST['directory'];

if (empty($_POST['directory'])) {
    echo 'Directory is required.';
    exit();
}
getFiles($directory);

function getFiles($directory)
{
    $files = array();

    $directory = new \RecursiveDirectoryIterator($directory);
    $iterator = new \RecursiveIteratorIterator($directory);

    foreach ($iterator as $file) {
        if (
            $file->getBasename() === '.' || $file->getBasename() === '..' || $file->getBasename() === '.DS_Store'
            || $file->getBasename() === 'Thumbs.db' || $file->getBasename() === '.AppleDouble'
        ) {
            continue;
        }
        $path = $file->getPath();
        $fileName = $file->getBasename();
        $fileNameAndPath = $file->getPathname();
        $fileExtension = pathinfo($file->getBasename(), PATHINFO_EXTENSION);
        $fileExtension = "." . $fileExtension;
        $fileNameNoExtension = $file->getBasename($fileExtension);
        $fileSize = filesize($file->getPathname());
        $fileDimensions = getDimensions($fileNameAndPath);
        $fileDuration = getDuration($fileNameAndPath);

        $files[] = array('path' => $path, 'fileName' => $fileName, 'fileNameAndPath' => $fileNameAndPath, 'fileExtension' => $fileExtension, 'fileNameNoExtension' => $fileNameNoExtension, 'fileSize' => $fileSize, 'fileDimensions' => $fileDimensions, 'fileDuration' => $fileDuration);
    }

    array_multisort($files, SORT_ASC);

    session_id("files");
    session_start();
    $_SESSION["files"] = $files;
}
