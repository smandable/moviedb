<?php

  $config = include('config/config.php');
  $mysqli = new mysqli($config->host, $config->username, $config->pass, $config->database);

  // check connection
  if ($mysqli->connect_errno){
    printf("Connect failed: %s\n", $mysqli->connect_error);
    exit();
  }
  if (!$mysqli->set_charset('utf8')) {
    printf("Error loading character set utf8: %s\n", $mysqli->error);
    exit;
  }
  $errors = array();

  $title = $_POST['title'];
  $title = $mysqli->real_escape_string($title);

  echo "title: " . $title;
  if (empty($_POST['title'])){
    $errors['title'] = 'Title is required.';
  }
  if(isset($_POST['notes'])) {
    $notes = $_POST['notes'];
    $notes = $mysqli->real_escape_string($notes);
  } else {
    $notes = "";
  }

  $sql = "INSERT INTO movieLibrary.movies (title,notes) VALUES ('$title','$notes')";

  if ($mysqli->query($sql) === TRUE) {
    echo "New record created successfully";
  } else {
      echo "Error: " . $sql . "<br>" . $mysqli->error;
  }

  $mysqli->close();

?>
