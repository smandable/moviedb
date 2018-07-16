<?php

$configUpdate = $_POST['configUpdate'];

$str = implode("", file('config/config.php'));
$fp = fopen('config/config.php', 'w');

    if ($configUpdate == "testDB") {
        $str = preg_replace('/movieLibrary\b/i', 'movieLibraryTEST', $str);
    } elseif ($configUpdate == "prodDB") {
        $str = preg_replace('/movieLibraryTEST\b/i', 'movieLibrary', $str);
    } elseif ($configUpdate == "movies_het") {
        $str = preg_replace('/movies_.*\w/i', 'movies_het', $str);
    } elseif ($configUpdate == "movies_bi") {
        $str = preg_replace('/movies_.*\w/i', 'movies_bi', $str);
    } elseif ($configUpdate == "movies_gay") {
        $str = preg_replace('/movies_.*\w/i', 'movies_gay', $str);
    } elseif ($configUpdate == "movies_misc") {
        $str = preg_replace('/movies_.*\w/i', 'movies_misc', $str);
    }

fwrite($fp, $str, strlen($str));
fclose($fp);

echo "Updated: $configUpdate\n";
