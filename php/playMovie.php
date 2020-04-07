<?php

$path = $_POST['path'];

// echo "$path\n";
//
// $path = escapeshellarg($path);
// $path = str_replace("'\''", "'\"'", $path);

// echo "$path\n";

exec('iina $path');
