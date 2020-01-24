<?php

$directory = $_POST['directory'];

$fi = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);
$numFiles = iterator_count($fi);

include "safe_json_encode.php";
echo safe_json_encode($numFiles);
