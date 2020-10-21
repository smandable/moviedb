<?php

$originalFileName = $_POST['originalFileName'];

session_id("files");

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

foreach ($_SESSION['files'] as &$file) {

    if (($file['fileName']) == $originalFileName) {
        if (isset($file['dontRename'])) {
            $file['dontRename'] = false;
        } else {
            $file['dontRename'] = true;
        }
    }
}
