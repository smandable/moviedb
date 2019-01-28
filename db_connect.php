<?php

$config = include('config/config.php');

$db = new mysqli($config->host, $config->username, $config->pass, $config->database);

$table = $config->table;

if (mysqli_connect_errno()) {
    echo mysqli_connect_error();
}
