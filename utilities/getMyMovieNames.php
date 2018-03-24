<?php

  $config = include('config/config.php');
  $mysqli = new mysqli($config->host, $config->username, $config->pass, $config->database);

  // check connection
  if ($mysqli->connect_errno) {
      printf("Connect failed: %s\n", $mysqli->connect_error);
      exit();
  }

  $options = array();

  $results = $mysqli->query("SELECT movie_id, title, notes, date_created FROM movieLibrary.movies ORDER BY title DESC");

if ($results->num_rows > 0) {
    while ($row_id = $results->fetch_array()) {
        $options['myData'][] = array(
          'movie_id' => $row_id['id'],
          'title'    => $row_id['title'],
          'notes'    => $row_id['notes']
      );
    }
}

  // modify http header to json
  header('Cache-Control: no-cache, must-revalidate');
  header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
  header('Content-type: application/json');

 echo json_encode($options);
