<?php

include "db_connect.php";

  $id = $_POST['id'];
  $id = $db->real_escape_string($id);

  if (empty($_POST['id'])) {
      echo 'ID is required.';
      die();
  }
  $dataType = $_POST['dataType'];
  $dataType = $db->real_escape_string($dataType);

  $dataToUpdate = $_POST['dataToUpdate'];
  $dataToUpdate = $db->real_escape_string($dataToUpdate);

  if ($dataType == 'Title') {
      $result = $db->query("UPDATE `".$table."` SET title='$dataToUpdate' WHERE id='$id'");
  }
  if ($dataType == 'Dimensions') {
      $result = $db->query("UPDATE `".$table."` SET dimensions='$dataToUpdate' WHERE id='$id'");
  }
  if ($dataType == 'Size') {
      $dataToUpdate = formatSize($dataToUpdate);
      $result = $db->query("UPDATE `".$table."` SET filesize='$dataToUpdate' WHERE id='$id'");
  }
  if ($dataType == 'Duration') {
      $result = $db->query("UPDATE `".$table."` SET duration='$dataToUpdate' WHERE id='$id'");
  } elseif ($result === false) {
      echo "SQL error:" . $db->error;
  }

  $db->close();

function formatSize($size)
{
    $size = preg_replace('/,/', '', $size);
    return $size;
}
