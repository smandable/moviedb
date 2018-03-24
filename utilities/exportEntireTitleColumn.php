<?php

  $fname = fopen("data/namesOnly.txt", "w") or die("Unable to open file!");
  $config = include('config/config.php');
  $mysqli = new mysqli($config->host, $config->username, $config->pass, $config->database);
  $num_rows = 0;

  $result = $mysqli->query("SELECT title FROM `movies`");

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
$mysqli->close();
