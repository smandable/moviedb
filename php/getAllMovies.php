<?php

include "db_connect.php";

    $result = $db->query("SELECT id, title, dimensions, filesize, duration, filepath, date_created FROM `".$table."` ORDER BY title ASC");

    $options = array();

        while ($row_id = mysqli_fetch_assoc($result)) {
            $options['data'][] = array(
              'id'      => $row_id['id'],
              'title'    => $row_id['title'],
              'dimensions'    => $row_id['dimensions'],
              'duration'    => $row_id['duration'],
              'filesize'    => $row_id['filesize'],
              'filepath'    => $row_id['filepath'],
              'date_created'    => $row_id['date_created']
          );
        }

    //modify http header to json
     header('Cache-Control: no-cache, must-revalidate');
     header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
     header('Content-type: application/json');

    echo json_encode($options);

    $db->close();
