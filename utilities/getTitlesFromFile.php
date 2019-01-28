<?php

// $directory = "/Volumes/External WD 8TB/tmp/names fixed/";
//
// $titles = array();
//
// if ($handle = opendir($directory)) {
//     while (false !== ($entry = readdir($handle))) {
//         if ($entry != "." && $entry != "..") {
//
//             $pattern1 = '/ - Scene.*/';
//             $pattern2 = '/ - CD.*/';
//             $pattern3 = '/.mp4.*/';
//             $pattern4 = '/.mkv.*/';
//             $pattern5 = '/.wmv.*/';
//             $pattern6 = '/.avi.*/';
//             $pattern7 = '/.m4v.*/';
//
//             $entry = preg_replace( array($pattern1, $pattern2, $pattern3, $pattern4, $pattern5, $pattern6, $pattern7), '', $entry );
//
//             $titles[] = $entry;
//             $titlesNoDuplicates = array_unique($titles);
//
//         }
//     }
//     closedir($handle);
// }
//
// foreach ($titlesNoDuplicates as $title) {
//     echo "$title\n";
// }




$input = file_get_contents("/Users/sean/Desktop/names_fixed.txt");
$json_a = json_decode($input, true);

$theArray = object_to_array($json_a);

echo count($theArray['Title']) . "<br />";

$db = new mysqli("localhost", "root", "spm024", "movieLibrary");
  // check connection
  if ($db->connect_errno) {
      printf("Connect failed: %s\n", $db->connect_error);
      exit();
  }

for ($i = 0; $i < count($theArray['Title']); $i++) {
    $vals = array_values($theArray['Title'][$i]);
    $title = $vals[16];

    if (strtolower($title) != strtolower($blacklist1) || strtolower($name) != strtolower($blacklist2)) {
        $name = $db->real_escape_string($name);
        $sql = "REPLACE INTO `".$table."` (title) VALUES ('$name')";

        if ($db->query($sql) === true) {
            echo "New record " . $name . " created successfully<br>";
        } else {
            echo "Error: " . $sql . "<br>" . $db->error;
        }
    }
}

$results->close();
$db->close();
