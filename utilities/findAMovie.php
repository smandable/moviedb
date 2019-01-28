<?php

function findMovie($title)
{
    include "db_connect.php";

    $title = $db->real_escape_string($title);

    $result = $db->query("SELECT * FROM `".$table."` WHERE title = '$title'");

    if (!$result) {
        die($db->error);
    }

    if ($result->num_rows > 0) {
        echo "In database: " . stripslashes($title);
    }

    $results->close();
    $db->close();
}
