<?php

  include "db_connect.php";

  $options = array();

  $result = $db->query("SELECT movie_id, title, notes, date_created FROM movieLibrary.movies ORDER BY title DESC");

if ($result->num_rows > 0) {
    while ($row_id = $result->fetch_array()) {
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
