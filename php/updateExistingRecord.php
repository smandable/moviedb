<?php

require 'db_connect.php';

$copyResultRowValues = $_POST['copyResultRowValues'];

$id = $copyResultRowValues[0];
//echo "id: $id\n";

$dimensions = $copyResultRowValues[1];
//echo "dimensions: $dimensions\n";
$size = $copyResultRowValues[2];
//echo "size: $size\n";
$duration = $copyResultRowValues[3];
//echo "duration: $duration\n";

$result = $db->query("UPDATE `" . $table . "` SET dimensions='$dimensions', filesize='$size', duration='$duration' WHERE id='$id'");

if (!$result) {
    die($db->error);
}

echo "Successfully updated row: $id with values: $dimensions, $size, $duration \n";

//$result->close();
$db->close();
