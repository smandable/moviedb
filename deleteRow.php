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

  $result = $mysqli->query("DELETE FROM movies WHERE id='$id'");

  if (!$result) {
      die($mysqli->error);
  }
  echo "Successfully deleted record " . $id . "\n";
  $mysqli->close();
