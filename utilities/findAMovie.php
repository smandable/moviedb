<?php

function findMovie($title)
{
    $config = include('config/config.php');
    $mysqli = new mysqli($config->host, $config->username, $config->pass, $config->database);

    if (mysqli_connect_errno()) {
        printf("Connect failed: %s\n", mysqli_connect_error());
        exit();
    }
    if (!$mysqli->set_charset('utf8')) {
        printf("Error loading character set utf8: %s\n", $mysqli->error);
        exit();
    }

    $title = $mysqli->real_escape_string($title);

    $result = $mysqli->query("SELECT * FROM movies WHERE title = '$title'");

    if (!$result) {
        die($mysqli->error);
    }

    if ($result->num_rows > 0) {
        echo "In database: " . stripslashes($title);
    }

    $mysqli->close();
}
