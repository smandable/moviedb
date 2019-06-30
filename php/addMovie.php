<?php

include "db_connect.php";

  $title = $_POST['title'];
  $title = $db->real_escape_string($title);

  if (empty($_POST['title'])) {
      echo 'Title is required.';
      exit();
  }
  if (isset($_POST['dimensions'])) {
      $dimensions = $_POST['dimensions'];
      $dimensions = $db->real_escape_string($dimensions);
  } else {
      $dimensions = "";
  }
  if (isset($_POST['filesize'])) {
      $filesize = $_POST['filesize'];
      $filesize = $db->real_escape_string($filesize);
  } else {
      $filesize = "";
  }

  $result = $db->query("SELECT * FROM `".$table."` WHERE title = '$title'");

  if (!$result) {
      die($db->error);
  }

  if ($result->num_rows > 0) {
      echo "Duplicate: " . stripslashes($title);
  } else {
      $result = $db->query("INSERT IGNORE INTO `".$table."` (title, dimensions, filesize, date_created) VALUES ('$title', '$dimensions', '$filesize', NOW())");
      echo "New record " . stripslashes($title) . " created successfully";

      if ($result === false) {
          echo "SQL error:".$db->error;
      }
  }

  $result->close();
  $db->close();
