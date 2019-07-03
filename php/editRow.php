<?php

include "db_connect.php";

  $id = $_POST['id'];
  $id = $db->real_escape_string($id);

  if (empty($_POST['id'])) {
      echo 'ID is required.';
      die();
  }
  $column = $_POST['column'];
  $column = $db->real_escape_string($column);

  $valueToUpdate = $_POST['valueToUpdate'];
  $valueToUpdate = $db->real_escape_string($valueToUpdate);

  if ($column == 'Title') {
      $result = $db->query("UPDATE `".$table."` SET title='$valueToUpdate' WHERE id='$id'");
  }
  if ($column == 'Dimensions') {
      $result = $db->query("UPDATE `".$table."` SET dimensions='$valueToUpdate' WHERE id='$id'");
  }
  if ($column == 'Size') {
      $valueToUpdate = formatSize($valueToUpdate);
      $result = $db->query("UPDATE `".$table."` SET filesize='$valueToUpdate' WHERE id='$id'");
  }
  if ($column == 'Duration') {
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
