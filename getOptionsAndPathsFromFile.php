<?php

$path = getcwd() . "/config";

$optionsAndPaths = include('config/optionsAndPaths.php');

$returnedArray = array('moveDuplicates' => $optionsAndPaths['moveDuplicates'], 'updateSizeInDB' => $optionsAndPaths['updateSizeInDB'], 'updateDimensionsInDB' => $optionsAndPaths['updateDimensionsInDB'], 'updateDurationInDB' => $optionsAndPaths['updateDurationInDB'], 'paths' => array());

foreach ($optionsAndPaths['paths'] as $path) {
    $returnedArray['paths'][] = $path;
}

echo json_encode($returnedArray);
