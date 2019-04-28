<?php

include "db_connect.php";

  $id = $_POST['pk'];
  $id = $db->real_escape_string($id);

  if (empty($_POST['pk'])) {
      echo 'ID is required.';
      die();
  }
  $dataType = $_POST['name'];
  $dataType = $db->real_escape_string($dataType);

  $dataToUpdate = $_POST['value'];
  $dataToUpdate = $db->real_escape_string($dataToUpdate);

  if ($dataType == 'title') {
      $title = $dataToUpdate;
      $result = $db->query("UPDATE `".$table."` SET title='$title' WHERE id='$id'");
  }
  // if ($dataType == 'Dimensions') {
  //     $dimensions = $dataToUpdate;
  //     $result = $db->query("UPDATE `".$table."` SET dimensions='$dimensions' WHERE id='$id'");
  // }
  // if ($dataType == 'Size') {
  //     $size = $dataToUpdate;
  //     $size = formatSize($size);
  //     $result = $db->query("UPDATE `".$table."` SET filesize='$size' WHERE id='$id'");
  // }
  elseif ($result === false) {
      echo "SQL error:" . $db->error;
  }

  // $result->close();
  $db->close();

// function formatSize($size)
// {
//     $size = preg_replace('/,/', '', $size);
//     return $size;
// }
