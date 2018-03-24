<?php

  $config = include('config/config.php');
  $mysqli = new mysqli($config->host, $config->username, $config->pass, $config->database);

  if ($mysqli->connect_errno) {
      printf("Connect failed: %s\n", $mysqli->connect_error);
      exit();
  }

  $id = $_POST['id'];
  $id = $mysqli->real_escape_string($id);

  if (empty($_POST['id'])) {
      echo 'Id is required.';
      die();
  }
  $dataType = $_POST['dataType'];
  $dataType = $mysqli->real_escape_string($dataType);

  $dataToUpdate = $_POST['dataToUpdate'];
  $dataToUpdate = $mysqli->real_escape_string($dataToUpdate);

  if ($dataType == 'Title') {
      $title = $dataToUpdate;
      $result = $mysqli->query("UPDATE movies SET title='$title' WHERE id='$id'");
      echo "Record updated successfully. Title changed to " . $title;
      if ($result === false) {
          echo "SQL error:".$mysqli->error;
      }
  }
  if ($dataType == 'Dimensions') {
      $dimensions = $dataToUpdate;
      $result = $mysqli->query("UPDATE movies SET dimensions='$dimensions' WHERE id='$id'");
      echo "Record updated successfully. Dimensions changed to " . $dimensions;
      if ($result === false) {
          echo "SQL error:".$mysqli->error;
      }
  }
  if ($dataType == 'Size') {
      $size = $dataToUpdate;
      $result = $mysqli->query("UPDATE movies SET filesize='$size' WHERE id='$id'");
      echo "Record updated successfully. Size changed to " . $size;
      if ($result === false) {
          echo "SQL error:".$mysqli->error;
      }
  }
  if ($dataType == 'Date Added') {
      $date_created = $dataToUpdate;
      $result = $mysqli->query("UPDATE movies SET date_created='$dataToUpdate' WHERE id='$id'");
      echo "Record updated successfully. Date changed to " . $date_created;
      if ($result === false) {
          echo "SQL error:".$mysqli->error;
      }
  }


  $mysqli->close();
