<?php

  $fname = 'data/' . 'namesOnly.txt';
  echo '$fname: ' . $fname;
  $id = 1;
  include "db_connect.php";
  foreach (file($fname) as $line) {
      $result = $db->query("UPDATE `".$table."` SET title='$line' WHERE id='$id'");
      echo "Record updated successfully. Title changed to " . $line;
      $id = $id + 1;
  }
  echo $id;
  $results->close();
 $db->close();
