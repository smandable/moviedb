<?php

require 'db_connect.php';

$id = $_POST['id'];
$id = $db->real_escape_string($id);

if (empty($_POST['id'])) {
    echo 'ID is required.';
    die();
}
$columnToUpdate = $_POST['columnToUpdate'];
$columnToUpdate = $db->real_escape_string($columnToUpdate);

$valueToUpdate = $_POST['valueToUpdate'];
$valueToUpdate = $db->real_escape_string($valueToUpdate);

if ($columnToUpdate == 'Title') {
    $row = mysqli_fetch_assoc($db->query("SELECT * FROM `" . $table . "` WHERE id = '$id'"));

    $originalTitle = $row['title'];
    $path = $row['filepath'];
    $path = str_replace($originalTitle, $valueToUpdate, $path);

    $db->query("UPDATE `" . $table . "` SET title='$valueToUpdate' WHERE id='$id'");
    if ($path != "") {
        $db->query("UPDATE `" . $table . "` SET filepath='$path' WHERE id='$id'");
    }
}
if ($columnToUpdate == 'Scale') {
    $db->query("UPDATE `" . $table . "` SET dimensions='$valueToUpdate' WHERE id='$id'");
}
if ($columnToUpdate == 'Size') {
    $valueToUpdate = formatSize($valueToUpdate);
    $db->query("UPDATE `" . $table . "` SET filesize='$valueToUpdate' WHERE id='$id'");
}
if ($columnToUpdate == 'Time') {
    $db->query("UPDATE `" . $table . "` SET duration='$valueToUpdate' WHERE id='$id'");
}

$db->close();

function formatSize($size)
{
    $size = preg_replace('/,/', '', $size);
    return $size;
}
