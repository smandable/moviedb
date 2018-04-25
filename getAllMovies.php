<?php
    $config = include('config/config.php');

    $mysqli = new mysqli($config->host, $config->username, $config->pass, $config->database);
    if ($mysqli->connect_errno) {
        printf('Connect failed: %s\n', $mysqli->connect_error);
        exit();
    }

    $options = array();

    $results = $mysqli->query('SELECT id, title, dimensions, filesize, date_created FROM movies ORDER BY title ASC');

    if ($results->num_rows > 0) {
        while ($row_id = $results->fetch_array()) {
            $options['data'][] = array(
              'id'      => $row_id['id'],
              'title'    => $row_id['title'],
              'dimensions'    => $row_id['dimensions'],
              'filesize'    => $row_id['filesize'],
              'date_created'    => $row_id['date_created']

          );
        }
    }

    // modify http header to json
     header('Cache-Control: no-cache, must-revalidate');
     header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
     header('Content-type: application/json');

    echo json_encode($options);
