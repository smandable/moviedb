<?php

include "db_connect.php";

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
      $result = $db->query("UPDATE `".$table."` SET title='$valueToUpdate' WHERE id='$id'");
  }
  if ($columnToUpdate == 'Dimensions') {
      $result = $db->query("UPDATE `".$table."` SET dimensions='$valueToUpdate' WHERE id='$id'");
  }
  if ($columnToUpdate == 'Size') {
      $valueToUpdate = formatSize($valueToUpdate);
      $result = $db->query("UPDATE `".$table."` SET filesize='$valueToUpdate' WHERE id='$id'");
  }
  if ($columnToUpdate == 'Duration') {
      $result = $db->query("UPDATE `".$table."` SET duration='$valueToUpdate' WHERE id='$id'");
  } elseif ($result === false) {
      echo "SQL error:" . $db->error;
  }

  $db->close();

function formatSize($size)
{
    $size = preg_replace('/,/', '', $size);
    return $size;
}
