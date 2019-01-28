<?php

  $fname = fopen("data/namesOnly.txt", "w") or die("Unable to open file!");
  include "db_connect.php";
  $num_rows = 0;

  $result = $db->query("SELECT title FROM `".$table."`");

  while ($row = $result->fetch_assoc()) {
      echo $row['title']."\n";
      $title = $row['title'];
      fwrite($fname, $title . "\n");
      $num_rows++;
  }
echo "num rows: " . $num_rows;
 fclose($fname);
$fname = fopen("data/namesOnly.txt", "a") or die("Unable to open file!");
 fwrite($fname, "\n" . "num rows: " . $num_rows);
 $results->close();
 $db->close();
