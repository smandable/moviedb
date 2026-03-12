<?php

// Load environment variables, then configuration
require_once 'env_loader.php';
$config = include 'config.php';

// Validate configuration
if (!$config || !isset($config->host, $config->username, $config->pass, $config->database, $config->table)) {
    die('Invalid configuration file.');
}

// Establish a database connection
$db = new mysqli($config->host, $config->username, $config->pass, $config->database);

// Check for connection errors
if ($db->connect_error) {
    die('Database connection failed: ' . $db->connect_error);
}

// Ensure the table name is available
$table = $config->table;

if (empty($table)) {
    die('Table name is not defined in the configuration.');
}
