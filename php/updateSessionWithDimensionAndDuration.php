<?php

require 'getDimensions.php';
require 'getDuration.php';

ini_set('max_execution_time', 0);


if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['i'])) {
    $_SESSION['i'] = 0;
}

//session_id("files");


$total = count($_SESSION['files']);

for ($i = $_SESSION['i']; $i < $total; $i++) {
    $_SESSION['i'] = $i;
    $percent = intval($i / $total * 100) . "%";

    $_SESSION['files'][$i]['fileDimensions'] = getDimensions($_SESSION['files'][$i]['fileNameAndPath']);
    $_SESSION['files'][$i]['fileDuration'] = getDuration($_SESSION['files'][$i]['fileNameAndPath']);

    echo '<script>
        parent.document.getElementById("progressbar").innerHTML="<div style=\"width:' . $percent . ';background:linear-gradient(to bottom, rgba(125,126,125,1) 0%,rgba(14,14,14,1) 100%); ;height:25px;\">&nbsp;</div>";
        parent.document.getElementById("information").innerHTML="<div style=\"text-align:center; font-weight:bold\">' . $percent . ' processed.</div>";</script>';

    ob_flush();
    flush();
}
echo '<script>
parent.document.getElementById("progressbar").innerHTML="<div style=\"width:100%;background:linear-gradient(to bottom, rgba(125,126,125,1) 0%,rgba(14,14,14,1) 100%); ;height:25px;\">&nbsp;</div>";
parent.document.getElementById("information").innerHTML="<div style=\"text-align:center; font-weight:bold\">Process completed</div>";

  setTimeout(function run() {
    parent.document.getElementById("information").innerHTML="<div style=\"text-align:center; font-weight:bold\">Please wait...</div>";
    }, 1000);

</script>';

//session_destroy();
unset($_SESSION["i"]);
