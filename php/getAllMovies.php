<?php

include "safe_json_encode.php";
include "db_connect.php";

    $result = $db->query("SELECT id, title, dimensions, filesize, duration, filepath, date_created FROM `".$table."` ORDER BY title ASC");

    $options = array();

        while ($row = mysqli_fetch_assoc($result)) {
            $options['data'][] = array(
              'id'      => $row['id'],
              'title'    => $row['title'],
              'dimensions'    => $row['dimensions'],
              'duration'    => $row['duration'],
              'filesize'    => $row['filesize'],
              'filepath'    => $row['filepath'],
              'date_created'    => $row['date_created']
          );
        }

    //modify http header to json
     header('Cache-Control: no-cache, must-revalidate');
     header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
     header('Content-type: application/json');

     echo safe_json_encode($options);

    $db->close();
