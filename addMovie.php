<?php

    $config = include('config/config.php');
    $mysqli = new mysqli($config->host, $config->username, $config->pass, $config->database);

  if (mysqli_connect_errno()) {
      printf("Connect failed: %s\n", mysqli_connect_error());
      exit();
  }
  if (!$mysqli->set_charset('utf8')) {
      printf("Error loading character set utf8: %s\n", $mysqli->error);
      exit();
  }

  $title = $_POST['title'];
  $title = $mysqli->real_escape_string($title);
  // $errors = "";

  if (empty($_POST['title'])) {
      echo 'Title is required.';
      exit();
  }
  if (isset($_POST['dimensions'])) {
      $dimensions = $_POST['dimensions'];
      $dimensions = $mysqli->real_escape_string($dimensions);
  } else {
      $dimensions = "";
  }
  if (isset($_POST['filesize'])) {
      $filesize = $_POST['filesize'];
      $filesize = $mysqli->real_escape_string($filesize);
  } else {
      $filesize = "";
  }

  $result = $mysqli->query("SELECT * FROM movies WHERE title = '$title'");

  if (!$result) {
      die($mysqli->error);
  }

  if ($result->num_rows > 0) {
      echo "Duplicate: " . stripslashes($title);
  } else {
      $result = $mysqli->query("INSERT IGNORE INTO movies (title, dimensions, filesize, date_created) VALUES ('$title', '$dimensions', '$filesize', NOW())");
      echo "New record " . stripslashes($title) . " created successfully";

      if ($result === false) {
          echo "SQL error:".$mysqli->error;
      }
  }

  $mysqli->close();
