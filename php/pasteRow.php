<?php

include "db_connect.php";

  $id = $_POST['id'];
  $id = $db->real_escape_string($id);

  if (empty($_POST['id'])) {
      echo 'Id is required.';
      die();
  }

  $copyResultRowValues = json_decode($_POST['copyResultRowValues'], true);

  $title = $copyResultRowValues['Title'];
  $title = $db->real_escape_string($title);

      if ($title == "") {
          echo 'Title is required.';
          exit();
      }

  $dimensions = $copyResultRowValues['Dimensions'];
  $dimensions = $db->real_escape_string($dimensions);

  $size = $copyResultRowValues['Size'];
  $size = $db->real_escape_string($size);

  $result = $db->query("UPDATE `".$table."` SET title='$title', dimensions='$dimensions', filesize='$size' WHERE id='$id'");

  if (!$result) {
      die($db->error);
  }

// echo "Successfully updated row: $id with values $title $dimensions $size\n";

  //$result->close();
  $db->close();
