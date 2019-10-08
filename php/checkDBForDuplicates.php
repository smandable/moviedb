<?php

ini_set('max_execution_time', 0);

include('formatSize.php');

include "db_connect.php";

//$result = $db->query("SELECT a.id, a.title, a.dimensions, a.filesize, a.duration, a.date_created, a.filepath, b.id, b.title, b.dimensions, b.filesize, b.duration, b.date_created FROM movies_het a INNER JOIN movies_het b ON b.title = a.title AND b.id > a.id GROUP BY b.title ORDER BY b.title ASC;");
$result = $db->query("SELECT id, title, dimensions, filesize, duration, filepath, date_created FROM `".$table."` ORDER BY title ASC");

$options = array();

    while ($row = mysqli_fetch_assoc($result)) {
        $options[] = array(
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

include "safe_json_encode.php";
echo safe_json_encode($options);

$db->close();

//     $result = $db->query("SELECT id, title, dimensions, filesize, duration, filepath, date_created FROM `".$table."` ORDER BY title ASC");
//     //$result = $db->query("SELECT a.id, a.title, a.dimensions, a.filesize, a.duration, a.date_created, a.filepath, b.id, b.title, b.dimensions, b.filesize, b.duration, b.date_created, b.filepath FROM movies_het a INNER JOIN movies_het b ON b.title = a.title AND b.id > a.id ORDER BY b.title ASC;");
//     //$result = $db->query("SELECT b.id, b.title FROM `".$table."` a INNER JOIN `".$table."` b ON b.title = a.title AND b.id > a.id GROUP BY b.id");
//
// // $allRows = array();
// $duplicateRows = array();
// $row = mysqli_fetch_assoc($result)
//
//       //  while ($row = mysqli_fetch_assoc($result)) {
//             $allRows[] = array(
//               'id'      => $row['id'],
//               'title'    => $row['title'],
//               'dimensions'    => $row['dimensions'],
//               'duration'    => $row['duration'],
//               'filesize'    => $row['filesize'],
//               'filepath'    => $row['filepath'],
//               'date_created'    => $row['date_created']
//           );
//         //}
// print_r($result);
// var_dump($allRows);

        // $keys = array_keys($allRows);
        // $num_keys = count($keys);
        // $i = 1;
        // foreach ($allRows as $a) {
        //     //if ($i < $num_keys && $allRows[$keys][$i] == $a) {
        //     echo "$allRows[$keys]\n";
        //     //  }
        //     $i++;
        // }


//         echo "after while loop";
// $lengthAllRowsArray = count($allRows);
//var_dump($allRows);
//$allRows = array_values($allRows);
//
// foreach ($allRows as $key => $value) {
//     print_r($key) . "\n";
//     print_r($value) . "\n";
// }


// for ($i = 0; $i < $lengthAllRowsArray - 1; ++$i) {
//     $id = $allRows[$i]['id'];
//     $nextID = $allRows[$i+1]['id'];
//     $title = $allRows[$i]['title'];
//     echo "$title\n";
//
//     if (current($title) === next($title)) {
//         echo "$id\t$title\n";
//     }
// }
// for ($i=0;$i<$lengthAllRowsArray;$i++) {
//     //print_r($allRows);
//     $id = $allRows[$i]['id'];
//     $nextID = $allRows[$i+1]['id'];
//     $title = $allRows[$i]['title'];
//     $nextTitle = $allRows[$i+1]'title'];
//
//     //similar_text($title, $nextTitle, $percentage);
//
//     if ((strcasecmp($title, $nextTitle) == 0)) {
//         echo "$id\t$title\n";
//         //echo "$nextID:\t$nextTitle\n";
//     }
//     // checkArrayForDuplicates($allRows[$i]);
// }

// function checkArrayForDuplicates(&$allRows)
// {
//     $title = $allRows['title'];
//     $dimensions = $allRows['Dimensions'];
//     $size = $allRows['Size'];
//     $duration = $allRows['Duration'];
//
//     // print_r($allRows);
//     // $title = $allRows[$i]['title'];
//   // $nextTitle = $allRows[$i+1]'title'];
//   // similar_text($title, $nextTitle, $percentage);
//   // if ($percentage > 50%) {
//   //   echo "$title\n";
//   //   echo "$nextTitle\n";
//   // }
// }



// var_dump($allRows);
//returnHTML($duplicateRows);

// function returnHTML($duplicateRows)
// {
//     $lengthDuplicateRows = count($duplicateRows);
//     $returnedArray = array();
//
//     for ($i=0;$i<$lengthDuplicateRows;$i++) {
//         $returnedArray['data'][$i] = array(
//           'Title' => $duplicateRows[$i]["title"],
//           'Dimensions' => $duplicateRows[$i]["dimensions"],
//           'Size' => $duplicateRows[$i]["size"],
//           'Duration' => $duplicateRows[$i]["duration"],
//           'Path' => $duplicateRows[$i]["path"],
//           'DateCreatedInDB' => $duplicateRows[$i]["dateCreatedInDB"],
//           'ID' => $duplicateRows[$i]["id"]
//         );
//     }
//
//     echo json_encode($returnedArray);
// }
//
// header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
// header('Content-type: application/json');
// echo json_encode($allRows);
