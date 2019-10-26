<?php

require 'db_connect.php';

  $id = $_POST['pk'];
  $id = $db->real_escape_string($id);

  if (empty($_POST['pk'])) {
      echo 'ID is required.';
      die();
  }
  $columnToUpdate = $_POST['Title'];
  $columnToUpdate = $db->real_escape_string($columnToUpdate);

  $valueToUpdate = $_POST['value'];
  $valueToUpdate = $db->real_escape_string($valueToUpdate);

  if ($columnToUpdate == 'title') {
      $title = $valueToUpdate;
      $db->query("UPDATE `".$table."` SET title='$title' WHERE id='$id'");
  } else {
      echo "SQL error:" . $db->error;
  }

  // $result->close();
  $db->close();

// function formatSize($size)
// {
//     $size = preg_replace('/,/', '', $size);
//     return $size;
// }
