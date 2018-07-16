<?php
    // $config = include('config/config.php');
    //
    // $mysqli = new mysqli($config->host, $config->username, $config->pass, $config->database);
    // if ($mysqli->connect_errno) {
    //     printf('Connect failed: %s\n', $mysqli->connect_error);
    //     exit();
    // }

// $table = $config->table;
// echo "$table\n";
//echo $mysqli;

include "db_connect.php";
//echo "$table\n";

    $results = $db->query("SELECT id, title, dimensions, filesize, date_created FROM `".$table."` ORDER BY title ASC");
    //$results = $db->query("SELECT id, title, dimensions, filesize, date_created FROM movies_het ORDER BY title ASC");

print_r($results);

$options = array();


        while ($row_id = mysqli_fetch_assoc($results)) {
            $options['data'][] = array(
              'id'      => $row_id['id'],
              'title'    => $row_id['title'],
              'dimensions'    => $row_id['dimensions'],
              'filesize'    => $row_id['filesize'],
              'date_created'    => $row_id['date_created']
          );
        }
        $results->close();
        $db->close();
     /*else {
        $options['data'][] = array('no data');
    }*/

    //modify http header to json
     header('Cache-Control: no-cache, must-revalidate');
     header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
     header('Content-type: application/json');

    echo json_encode($options);
