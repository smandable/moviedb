<?php

  include "db_connect.php";
  $errors = array();

  $title = $_POST['title'];
  $title = $db->real_escape_string($title);

  echo "title: " . $title;
  if (empty($_POST['title'])) {
      $errors['title'] = 'Title is required.';
  }
  if (isset($_POST['notes'])) {
      $notes = $_POST['notes'];
      $notes = $db->real_escape_string($notes);
  } else {
      $notes = "";
  }

  $sql = "INSERT INTO movieLibrary.movies (title,notes) VALUES ('$title','$notes')";

  if ($db->query($sql) === true) {
      echo "New record created successfully";
  } else {
      echo "Error: " . $sql . "<br>" . $db->error;
  }

  $results->close();
 $db->close();
