<?php

    include "db_connect.php";

  $title = $_POST['title'];
  $title = $db->real_escape_string($title);
  // $errors = "";

  if (empty($_POST['title'])) {
      echo 'Title is required.';
      exit();
  }
  if (isset($_POST['notes'])) {
      $notes = $_POST['notes'];
      $notes = $db->real_escape_string($notes);
  } else {
      $notes = "";
  }

  $result = $db->query("SELECT * FROM `".$table."` WHERE title = '$title'");

  if (!$result) {
      die($db->error);
  }

  if ($result->num_rows > 0) {
      echo "Duplicate: " . stripslashes($title);
  } else {
      $result = $db->query("INSERT IGNORE INTO `".$table."` (title, notes, date_created) VALUES ('$title', '$notes', NOW())");
      echo "New record " . stripslashes($title) . " created successfully";

      if ($result === false) {
          echo "SQL error:".$db->error;
      }
  }

  $results->close();
$db->close();
