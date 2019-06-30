<?php

$fileNameAndPath = $_POST['fileNameAndPath'];

if (empty($_POST['fileNameAndPath'])) {
    echo 'Filename and path are required.';
    exit();
} else {
    unlink($fileNameAndPath);
    echo "$fileNameAndPath deleted\n";
}
