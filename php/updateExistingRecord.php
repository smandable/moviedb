<?php

include "db_connect.php";

  $copyResultRowValues = $_POST['copyResultRowValues'];

  $id = $copyResultRowValues[0];
  $dimensions = $copyResultRowValues[2];
  $size = $copyResultRowValues[3];
  $duration = $copyResultRowValues[4];

  $result = $db->query("UPDATE `".$table."` SET dimensions='$dimensions', filesize='$size', duration='$duration' WHERE id='$id'");

  if (!$result) {
      die($db->error);
  }

// echo "Successfully updated row: $id with values: $dimensions, $size, $duration \n";

  //$result->close();
  $db->close();
