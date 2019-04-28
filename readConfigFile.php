<?php

if (strpos(file_get_contents("config/config.php"), 'movieLibraryPROD') !== false) {
    $currentDB = "movieLibraryPROD";
} elseif (strpos(file_get_contents("config/config.php"), 'movieLibraryTEST') !== false) {
    $currentDB = "movieLibraryTEST";
}

if (strpos(file_get_contents("config/config.php"), 'movies_het') !== false) {
    $currentTable = "movies_het";
} elseif (strpos(file_get_contents("config/config.php"), 'movies_bi') !== false) {
    $currentTable = "movies_bi";
} elseif (strpos(file_get_contents("config/config.php"), 'movies_gay') !== false) {
    $currentTable = "movies_gay";
} elseif (strpos(file_get_contents("config/config.php"), 'movies_ts') !== false) {
    $currentTable = "movies_ts";
} elseif (strpos(file_get_contents("config/config.php"), 'movies_misc') !== false) {
    $currentTable = "movies_misc";
}
// echo $db_result;

echo json_encode(array('currentDB' => $currentDB, 'currentTable' => $currentTable));
