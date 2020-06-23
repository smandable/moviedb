<?php

session_id("files");

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

foreach ($_SESSION['files'] as &$file) {

    $fileNameAndPath = $file['path'] . "/" . $file['fileName'];
    if (!isset($file['dontRename'])) {
        if (isset($file['newFileName'])) {

            $newFileNameAndPath = $file['path'] . "/" . $file['newFileName'];

            if (!file_exists($newFileNameAndPath)) {
                rename($fileNameAndPath, $newFileNameAndPath);
                $file['fileName'] = $file['newFileName'];
                $file['fileNameAndPath'] = $newFileNameAndPath;
                $file['newFileName'] = preg_replace('/\.[a-z1-9]{3,4}$/', '', $file['newFileName']);
                $file['fileNameNoExtension'] = $file['newFileName'];
                $file['fileWasRenamed'] = true;
            } else {
                $file['fileExists'] = true;
            }
        }
    }
}

require "safe_json_encode.php";
echo safe_json_encode($_SESSION["files"]);
