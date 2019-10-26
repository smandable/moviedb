<?php

//$path = getcwd() . "/config";

$optionsAndPaths = include('../config/optionsAndPaths.php');

include "safe_json_encode.php";
echo safe_json_encode($optionsAndPaths);
