<?php
$query = $_GET['query'].'%'; // add % for LIKE query later

// do query
$stmt = $dbh->prepare('SELECT name FROM `".$table."`.movie WHERE name LIKE = :query');
$stmt->bindParam(':query', $query, PDO::PARAM_STR);
$stmt->execute();

// populate results
$result = array();
foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $row) {
    $result[] = $row;
}

// and return to typeahead

return json_encode($result);
?>