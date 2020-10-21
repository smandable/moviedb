<?php

ini_set('max_execution_time', 0);

$directory = $_POST['directory'];

if (empty($_POST['directory'])) {
    echo 'Directory is required.';
    exit();
}
$numFiles = $_POST['numFiles'];

session_start();

if (empty($_SESSION['i'])) {
    $_SESSION['i'] = 0;
}

getFileNames($numFiles, $directory);

function getFileNames($numFiles, $directory)
{
    $i = 0;
    $percent = intval($i / $numFiles * 100) . "%";

    $files = array();

    $directory = new \RecursiveDirectoryIterator($directory);
    $iterator = new \RecursiveIteratorIterator($directory);

    foreach ($iterator as $file) {
        $percent = intval($i / $numFiles * 100) . "%";

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

        $files[] = array('path' => $path, 'fileName' => $fileName, 'fileNameAndPath' => $fileNameAndPath, 'fileExtension' => $fileExtension, 'fileNameNoExtension' => $fileNameNoExtension, 'fileSize' => $fileSize);

        echo '<script>
        parent.document.getElementById("progressbar").innerHTML="<div style=\"width:' . $percent . ';background:linear-gradient(to bottom, rgba(125,126,125,1) 0%,rgba(14,14,14,1) 100%); ;height:25px;\">&nbsp;</div>";
        parent.document.getElementById("information").innerHTML="<div style=\"text-align:center; font-weight:bold\">' . $percent . ' is processed.</div>";</script>';

        ob_flush();
        flush();
        $i++;
    }

    echo '<script>
            parent.document.getElementById("progressbar").innerHTML="<div style=\"width:100%;background:linear-gradient(to bottom, rgba(125,126,125,1) 0%,rgba(14,14,14,1) 100%); ;height:25px;\">&nbsp;</div>";
            parent.document.getElementById("information").innerHTML="<div style=\"text-align:center; font-weight:bold\">Process completed</div>"
        </script>';

    unset($_SESSION["i"]);

    array_multisort($files, SORT_ASC);

    $_SESSION["files"] = $files;
}
