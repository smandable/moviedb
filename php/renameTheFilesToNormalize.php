<?php

// if (isset($_POST['dontRenameThese'])) {
//     $dontRenameThese = $_POST['dontRenameThese'];
// } else {
//     $dontRenameThese = '';
// }

session_id("files");

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

foreach ($_SESSION['files'] as &$file) {

    $fileNameAndPath = $file['path'] . "/" . $file['fileName'];
    if (!isset($file['dontRename'])) {
        if (isset($file['newFileName'])) {
            // if (!in_array($file['fileName'], $dontRenameThese)) {

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
            //  }
        }
    }
}

include "safe_json_encode.php";
echo safe_json_encode($_SESSION["files"]);
