<?php

$config = include('../config/config.php');

$currentDB = $config['database'];
$currentTable = $config['table'];

echo json_encode($currentDB, $currentTable);
