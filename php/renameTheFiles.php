<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

foreach ($_SESSION['filesProcessed'] as &$item) {
    $path = $item['Path'] . "/";
    $pathAndOriginalFileName = $path.$item['originalFileName'];
    $newFileName = $item['newFileName'];
    $pathAndNewFileName = $path.$newFileName;
    if (file_exists($pathAndNewFileName)) {
        $item['fileWasRenamed'] = false;
    } else {
        rename($pathAndOriginalFileName, $pathAndNewFileName);
        $item['fileWasRenamed'] = true;
    }
}

echo json_encode($_SESSION['filesProcessed']);
