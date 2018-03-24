<?php

$newState = $_POST['newState'];

$str = implode("", file('config/config.php'));
$fp = fopen('config/config.php', 'w');
    if ($newState == "testDB") {
        // $str = str_replace('movieLibrary', 'movieLibraryTEST', $str);
        $str = preg_replace('/movieLibrary\b/i', 'movieLibraryTEST', $str);
    } else {
        // $str = str_replace('movieLibraryTEST', 'movieLibrary', $str);
        $str = preg_replace('/movieLibraryTEST\b/i', 'movieLibrary', $str);
    }

fwrite($fp, $str, strlen($str));
fclose($fp);

echo 'Updated';
