<?php

return (object) [
    'host'     => getenv('DB_HOST') ?: 'localhost:3306',
    'username' => getenv('DB_USERNAME') ?: 'root',
    'pass'     => getenv('DB_PASSWORD') ?: '',
    'database' => getenv('DB_DATABASE') ?: 'movieLibrary',
    'table'    => getenv('DB_TABLE') ?: 'movies',
    'updateMissingDataOnly' => filter_var(getenv('UPDATE_MISSING_DATA_ONLY'), FILTER_VALIDATE_BOOLEAN),
];
