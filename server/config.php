<?php

return (object) [
    'host' => 'localhost:3306',
    'username' => 'root',
    'pass' => 'spm024',
    // 'database' => 'movieLibraryTEST',
    'database' => 'movieLibraryPROD',
    'table' => 'movies_het',
    'updateMissingDataOnly' => false, // Set to true to enable updating missing data (dimensions and duration), if false then script will behave normally and move to duplicates, etc
];