<?php

if (isset($_POST['newFileName'])) {
    $newFileName = $_POST['newFileName'];
} else {
    $newFileName = $_POST['value'];
}

$path = $_POST['path'];
$originalFileName = $_POST['originalFileName'];
$pathAndOriginalFileName = $path . $originalFileName;
$pathAndNewFileName = $path . $newFileName;

if (file_exists($pathAndNewFileName)) {
    echo "fail";
} else {
    rename($pathAndOriginalFileName, $pathAndNewFileName);
    // updateSession($originalFileName, $newFileName);
    echo $newFileName;
}



// function updateSession($originalFileName, $newFileName)
// {
//     if (session_status() !== PHP_SESSION_ACTIVE) {
//         session_start();
//     }

//     $filesProcessed = array();
//     $filesProcessed = $_SESSION['filesProcessed'];

//     foreach ($_SESSION['filesProcessed'] as $key => &$value) {
//         if ($value['originalFileName'] == $originalFileName) {
//             $_SESSION['filesProcessed'][$key]['originalFileName'] = $newFileName;
//         }
//     }
// }
