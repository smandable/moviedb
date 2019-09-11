<?php
$directory = $_POST['directory'];
$fi = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);
$numFiles = iterator_count($fi);
echo $numFiles;
