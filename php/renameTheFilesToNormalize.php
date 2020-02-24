<?php
$dontRenameThese = $_POST['dontRenameThese'];

session_id("files");

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

foreach ($_SESSION['files'] as &$file) {
    $path = $file['path'] . "/";
    $fileNameAndPath = $path . $file['fileName'];

    if (isset($file['newFileName'])) {
        if (!in_array($file['fileName'], $dontRenameThese)) {
            $newFileName = $file['newFileName'];
            $newFileNameAndPath = $path . $newFileName;

            if (!file_exists($newFileNameAndPath)) {
                rename($fileNameAndPath, $newFileNameAndPath);
                $file['fileName'] = $newFileName;
                $file['fileNameAndPath'] = $newFileNameAndPath;
                $newFileName = preg_replace('/\.[a-z1-9]{3,4}$/', '', $newFileName);
                $file['fileNameNoExtension'] = $newFileName;
                $file['fileWasRenamed'] = true;
            } else {
                $file['fileExists'] = true;
            }
        }
    }
}

include "safe_json_encode.php";
echo safe_json_encode($_SESSION["files"]);
