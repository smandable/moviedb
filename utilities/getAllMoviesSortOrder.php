<?php
    include "db_connect.php";

    $sortOrder = $_POST['sortOrder'];
    $options = array();
    echo "sortOrder:  " . $sortOrder . "\n\n";
    if ($sortOrder == "IDDESC") {
        // echo "sortOrder:  " . $sortOrder . "\n\n";
        // $result = $db->query('SELECT id, title, notes, date_created FROM `".$table."` ORDER BY id DESC');
        $sortOrder = "id DESC";
    } else {
        // echo "sortOrder:  " . $sortOrder . "\n\n";
        // $result = $db->query('SELECT id, title, notes, date_created FROM `".$table."` ORDER BY title ASC');
        $sortOrder = "title ASC";
    }
    $result = $db->query("SELECT id, title, notes, date_created FROM `".$table."` ORDER BY '$sortOrder'");

    if ($result->num_rows > 0) {
        while ($row_id = $result->fetch_array()) {
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
