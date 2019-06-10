<?php

$dirName = $_POST['dirName'];

$fi = new FilesystemIterator($dirName, FilesystemIterator::SKIP_DOTS);
$numFiles = iterator_count($fi);

echo $numFiles;
