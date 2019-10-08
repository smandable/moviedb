<?php

$config = include('../config/config.php');

$currentDB = $config['database'];
$currentTable = $config['table'];

include "safe_json_encode.php";
echo safe_json_encode($currentDB, $currentTable);
