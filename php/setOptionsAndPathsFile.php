<?php

//$path = getcwd() . "/config";

$optionsAndPaths = include('../config/optionsAndPaths.php');

require "safe_json_encode.php";
echo safe_json_encode($optionsAndPaths);
