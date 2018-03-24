<?php


if (strpos(file_get_contents("config/config.php"), 'movieLibraryTEST') !== false) {
    $result = "movieLibraryTEST";
} elseif (strpos(file_get_contents("config/config.php"), 'movieLibrary') !== false) {
    $result = "movieLibrary";
}
// echo '$result: ' . $result . "\n";

// // modify http header to json
//  header('Cache-Control: no-cache, must-revalidate');
//  header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
//  header('Content-type: application/json');
//
// echo json_encode($result);

echo $result;
