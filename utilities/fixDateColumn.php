<?php

  $config = include('config/config.php');
  $mysqli = new mysqli($config->host, $config->username, $config->pass, $config->database);

  if (mysqli_connect_errno()) {
      printf("Connect failed: %s\n", mysqli_connect_error());
      exit();
  }

     for ($i=23596;$i<=24045;$i++) {
         $result = $mysqli->query("UPDATE movies SET date_created=NOW() WHERE id='$i'");
         echo "Record updated successfully";
     }

  $mysqli->close();
