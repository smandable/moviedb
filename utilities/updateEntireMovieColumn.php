<?php

  $fname = 'data/' . 'namesOnly.txt';
  echo '$fname: ' . $fname;
  $id = 1;
  $config = include('config/config.php');
  $mysqli = new mysqli($config->host, $config->username, $config->pass, $config->database);
  foreach (file($fname) as $line) {
      $result = $mysqli->query("UPDATE movies SET title='$line' WHERE id='$id'");
      echo "Record updated successfully. Title changed to " . $line;
      $id = $id + 1;
  }
  echo $id;
  $mysqli->close();
