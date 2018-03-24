<?php
    $config = include('config/config.php');
    $mysqli = new mysqli($config->host, $config->username, $config->pass, $config->database);
    if ($mysqli->connect_errno) {
        printf('Connect failed: %s\n', $mysqli->connect_error);
        exit();
    }

    $sortOrder = $_POST['sortOrder'];
    $options = array();
    echo "sortOrder:  " . $sortOrder . "\n\n";
    if ($sortOrder == "IDDESC") {
        // echo "sortOrder:  " . $sortOrder . "\n\n";
        // $results = $mysqli->query('SELECT id, title, notes, date_created FROM movies ORDER BY id DESC');
        $sortOrder = "id DESC";
    }
    else {
        // echo "sortOrder:  " . $sortOrder . "\n\n";
    // $results = $mysqli->query('SELECT id, title, notes, date_created FROM movies ORDER BY title ASC');
    $sortOrder = "title ASC";
    }
    $results = $mysqli->query("SELECT id, title, notes, date_created FROM movies ORDER BY '$sortOrder'");

    if ($results->num_rows > 0) {
        while ($row_id = $results->fetch_array()) {
            $options['myData'][] = array(
              'id'      => $row_id['id'],
              'title'    => $row_id['title'],
              'notes'    => $row_id['notes'],
              'date_created'    => $row_id['date_created']
          );
        }
    }

    // modify http header to json
     header('Cache-Control: no-cache, must-revalidate');
     header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
     header('Content-type: application/json');

    echo json_encode($options);
