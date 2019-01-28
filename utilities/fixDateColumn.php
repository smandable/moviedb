<?php

  include "db_connect.php";

     for ($i=23596;$i<=24045;$i++) {
         $result = $db->query("UPDATE `".$table."` SET date_created=NOW() WHERE id='$i'");
         echo "Record updated successfully";
     }

     $results->close();
   $db->close();
