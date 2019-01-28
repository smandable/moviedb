<?php

include "db_connect.php";

    $result = $db->query("SELECT id, title, dimensions, filesize, date_created FROM `".$table."` ORDER BY title ASC");

    $options = array();

        while ($row_id = mysqli_fetch_assoc($result)) {
            $options['data'][] = array(
              'id'      => $row_id['id'],
              'title'    => $row_id['title'],
              'dimensions'    => $row_id['dimensions'],
              'filesize'    => $row_id['filesize'],
              'date_created'    => $row_id['date_created']
          );
        }
     /*else {
        $options['data'][] = array('no data');
    }*/

    //modify http header to json
     header('Cache-Control: no-cache, must-revalidate');
     header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
     header('Content-type: application/json');

    echo json_encode($options);

    $result->close();
    $db->close();
