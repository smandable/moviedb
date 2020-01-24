<?php
require 'db_connect.php';

$id = $_POST['id'];
$id = $db->real_escape_string($id);

if (empty($_POST['id'])) {
    echo 'Id is required.';
    die();
}

  $result = $db->query("DELETE FROM `".$table."` WHERE id='$id'");

if (!$result) {
    die($db->error);
}
  echo "Successfully deleted record " . $id . "\n";
  
$db->close();
